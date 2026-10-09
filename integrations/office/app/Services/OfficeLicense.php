<?php

namespace App\Services;

use App\Exceptions\OfficeHelperRequestException;

final class OfficeLicense
{
    public function enabled(): bool
    {
        return is_file(base_path('office-managed')) || config('office-agent.enabled')
            || is_file(config('office-agent.state_dir').'/enabled');
    }

    public function decision(): array
    {
        if (! $this->enabled()) {
            return ['allowed' => true, 'message' => null];
        }
        $message = 'این سامانه غیرفعال شده است. برای فعال‌سازی با واحد فروش یا نماینده فنی خود در ارتباط باشید.';
        try {
            $dir = config('office-agent.state_dir');
            $trust = json_decode(file_get_contents($dir.'/trust.json'), true, 8, JSON_THROW_ON_ERROR);
            $envelope = json_decode(file_get_contents($dir.'/state.json'), true, 8, JSON_THROW_ON_ERROR);
            $raw = $this->decode($envelope['payload']);
            $key = $this->decode($trust['public_key']);
            $signature = $this->decode($envelope['signature']);
            if (($envelope['algorithm'] ?? '') !== 'Ed25519' || strlen($key) !== 32 || strlen($signature) !== 64
                || ! sodium_crypto_sign_verify_detached($signature, "office-agent/v2\n".$raw, $key)) {
                return ['allowed' => false, 'message' => $message];
            }
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $nonce = bin2hex(random_bytes(16));
            $proof = $this->control('/identity', ['Nonce' => $nonce], 5);
            $fingerprint = $proof['fingerprint'] ?? '';
            $deviceKey = $this->decode($state['device_public_key']);
            $proofSignature = $this->decode($proof['signature']);
            if (strlen($deviceKey) !== 32 || strlen($proofSignature) !== 64
                || ! sodium_crypto_sign_verify_detached($proofSignature, "office-hardware-proof/v2\n".$nonce."\n".$fingerprint, $deviceKey)) {
                return ['allowed' => false, 'message' => $message];
            }
            $bound = ($state['kind'] ?? '') === 'state' && ($state['protocol'] ?? 0) === 2
                && ($state['product'] ?? '') === 'office'
                && ($state['installation_id'] ?? '') === $trust['installation_id']
                && hash_equals($state['hardware_fingerprint'] ?? '', $fingerprint)
                && hash_equals($state['presented_fingerprint'] ?? '', $fingerprint);

            return ['allowed' => $bound && ($state['completed'] ?? false) && ($state['access'] ?? '') === 'allowed',
                'message' => $state['message'] ?? $message];
        } catch (\Throwable $error) {
            return ['allowed' => false, 'message' => $message];
        }
    }

    public function reactivate(string $code): void
    {
        $this->control('/reactivate', ['license_key' => $code], 60);
    }

    public function details(): array
    {
        try {
            $dir = config('office-agent.state_dir');
            $trust = json_decode(file_get_contents($dir.'/trust.json'), true, 8, JSON_THROW_ON_ERROR);
            $envelope = json_decode(file_get_contents($dir.'/state.json'), true, 8, JSON_THROW_ON_ERROR);
            $raw = $this->decode($envelope['payload']);
            if (($envelope['algorithm'] ?? '') !== 'Ed25519' || ! sodium_crypto_sign_verify_detached($this->decode($envelope['signature']), "office-agent/v2\n".$raw, $this->decode($trust['public_key']))) {
                return [];
            }
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (($state['installation_id'] ?? '') !== $trust['installation_id'] || ($state['kind'] ?? '') !== 'state' || ($state['protocol'] ?? 0) !== 2 || ($state['product'] ?? '') !== 'office') {
                return [];
            }

            return $state;
        } catch (\Throwable) {
            return [];
        }
    }

    public function updateStatus(): array
    {
        $path = config('office-agent.state_dir').'/update.json';
        if (! is_readable($path)) {
            return [];
        }
        try {
            return json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function maintenance(): bool
    {
        return (bool) ($this->updateStatus()['maintenance'] ?? false);
    }

    public function checkUpdates(): array
    {
        return $this->control('/check-update', [], 60);
    }

    public function startUpdate(array $confirmation): array
    {
        $checked = $this->checkUpdates();
        if (($checked['status'] ?? '') === 'running') {
            return $checked;
        }
        if (($checked['confirmation_supported'] ?? false) !== true) {
            throw new \RuntimeException('HELPER_UPGRADE_REQUIRED: سرویس بروزرسانی باید توسط مدیر سرور به‌روز شود. با نماینده فنی خود در ارتباط باشید.');
        }

        return $this->control('/update', $confirmation, 60);
    }

    private function control(string $path, array $data, int $timeout): array
    {
        $connection = @stream_socket_client('unix://'.config('office-agent.socket'), $errno, $error, 5);
        if (! $connection) {
            throw new \RuntimeException('سرویس فعال‌سازی در دسترس نیست.');
        }
        try {
            stream_set_timeout($connection, $timeout);
            $body = json_encode($data, JSON_THROW_ON_ERROR);
            $token = config('office-agent.control_token');
            $tokenFile = config('office-agent.control_token_file');
            if ($tokenFile && is_readable($tokenFile)) {
                $token = trim(file_get_contents($tokenFile));
            }
            if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
                throw new \RuntimeException('Invalid helper control identity.');
            }
            $request = 'POST '.$path." HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer ".$token
                ."\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
            $offset = 0;
            while ($offset < strlen($request)) {
                $written = fwrite($connection, substr($request, $offset));
                if (! $written) {
                    throw new \RuntimeException('Helper connection failed.');
                }
                $offset += $written;
            }
            $response = stream_get_contents($connection, 65536);
            preg_match('~^HTTP/1\.[01] (\d{3}) ~', $response, $status);
            $body = explode("\r\n\r\n", $response, 2)[1] ?? '';
            $data = json_decode($body, true);
            if (! in_array((int) ($status[1] ?? 0), [200, 202], true)) {
                if (($status[1] ?? '') === '429') {
                    $seconds = max(1, min(3600, (int) ($data['retry_after'] ?? 60)));
                    throw new OfficeHelperRequestException("بررسی موقتاً محدود شده است؛ {$seconds} ثانیه صبر کنید و دوباره تلاش کنید.", $seconds);
                }
                if (($status[1] ?? '') === '404') {
                    throw new \RuntimeException('HELPER_UPGRADE_REQUIRED: نسخهٔ helper قدیمی است؛ مدیر سرور فرمان نصب و اتصال helper را دوباره اجرا کند.');
                }
                throw new \RuntimeException($data['message'] ?? 'HELPER_REQUEST_FAILED: درخواست انجام نشد؛ اتصال helper به مرکز و لاگ سرویس را بررسی کنید.');
            }
            if (! is_array($data)) {
                throw new \RuntimeException('HELPER_RESPONSE_INVALID: پاسخ سرویس قابل خواندن نیست.');
            }

            return $data;
        } finally {
            fclose($connection);
        }
    }

    private function decode(string $value): string
    {
        return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
