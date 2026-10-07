<?php

namespace App\Services;

final class OfficeLicense
{
    public function enabled(): bool
    {
        return is_file(base_path('office-managed')) || config('office-agent.enabled');
    }

    public function decision(): array
    {
        if (! $this->enabled()) { return ['allowed' => true, 'message' => null]; }
        $message = 'این سامانه غیرفعال شده است. برای فعال‌سازی و تهیه لایسنس جدید با واحد فروش یا نماینده فروش تماس بگیرید.';
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

    private function control(string $path, array $data, int $timeout): array
    {
        $connection = @stream_socket_client('unix://'.config('office-agent.socket'), $errno, $error, 5);
        if (! $connection) { throw new \RuntimeException('سرویس فعال‌سازی در دسترس نیست.'); }
        try {
            stream_set_timeout($connection, $timeout);
            $body = json_encode($data, JSON_THROW_ON_ERROR);
            $token = config('office-agent.control_token');
            if (! preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \RuntimeException('Invalid helper control identity.'); }
            $request = "POST ".$path." HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer ".$token
                ."\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
            $offset = 0;
            while ($offset < strlen($request)) {
                $written = fwrite($connection, substr($request, $offset));
                if (! $written) { throw new \RuntimeException('Helper connection failed.'); }
                $offset += $written;
            }
            $response = stream_get_contents($connection, 65536);
            if (! preg_match('~^HTTP/1\.[01] 200 ~', $response)) {
                throw new \RuntimeException('فعال‌سازی انجام نشد. اعتبار لایسنس و اتصال سرویس به مرکز را بررسی کنید.');
            }
            return json_decode(explode("\r\n\r\n", $response, 2)[1], true, 8, JSON_THROW_ON_ERROR);
        } finally { fclose($connection); }
    }

    private function decode(string $value): string
    {
        return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
