<?php

namespace App\Services;

use Illuminate\Http\Request;

final class AgentProtocol
{
    public const CONTEXT = "office-agent/v2\n";

    public function envelope(array $payload): array
    {
        $message = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $private = sodium_base642bin((string) config('office.signing_private_key'), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return [
            'payload' => sodium_bin2base64($message, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'signature' => sodium_bin2base64(sodium_crypto_sign_detached(self::CONTEXT.$message, $private), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'algorithm' => 'Ed25519',
        ];
    }

    public function verifyRequest(Request $request, string $publicKey): bool
    {
        try {
            $timestamp = $request->header('X-Request-Timestamp', '');
            $nonce = $request->header('X-Request-Nonce', '');
            if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300
                || ! preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
                return false;
            }
            $key = sodium_base642bin($publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $signature = sodium_base642bin($request->header('X-Device-Signature', ''), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $message = implode("\n", [$request->method(), '/'.$request->path(), $timestamp, $nonce, hash('sha256', $request->getContent())]);

            return strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, $message, $key);
        } catch (\Throwable) {
            return false;
        }
    }

    public function credential(string $installationId, string $publicKey): string
    {
        // Deterministic only for this signed device/installation pair, permitting
        // recovery after a lost activation response without storing plaintext.
        return 'oia_'.hash_hmac('sha256', $installationId."\n".$publicKey, (string) config('office.signing_private_key'));
    }
}
