<?php

declare(strict_types=1);

$directory = '/run/office-secrets';

if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create secret directory.\n");
    exit(1);
}

function env_value(string $name): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? null : $value;
}

function random_urlsafe(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function atomic_write(string $path, string $value): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $value, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write {$path}.");
    }
    chmod($temporary, 0444);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to finalize {$path}.");
    }
}

function ensure_secret(string $name, ?string $provided, callable $generator): string
{
    global $directory;
    $path = $directory . '/' . $name;

    if (is_file($path)) {
        $existing = trim((string) file_get_contents($path));
        if ($existing !== '') {
            return $existing;
        }
    }

    $value = $provided ?? $generator();
    if ($value === '') {
        throw new RuntimeException("Secret {$name} is empty.");
    }

    atomic_write($path, $value . "\n");
    return $value;
}

try {
    $appKey = ensure_secret(
        'app_key',
        env_value('APP_KEY'),
        static fn (): string => 'base64:' . base64_encode(random_bytes(32))
    );

    $dbPassword = ensure_secret(
        'db_password',
        env_value('DB_PASSWORD'),
        static fn (): string => random_urlsafe(36)
    );

    $redisPassword = ensure_secret(
        'redis_password',
        env_value('REDIS_PASSWORD'),
        static fn (): string => random_urlsafe(36)
    );

    $privateKey = env_value('CENTRAL_SIGNING_PRIVATE_KEY');
    $publicKey = env_value('CENTRAL_SIGNING_PUBLIC_KEY');

    if ($privateKey === null && is_file($directory . '/signing_private_key')) {
        $privateKey = trim((string) file_get_contents($directory . '/signing_private_key'));
    }

    if ($publicKey === null && is_file($directory . '/signing_public_key')) {
        $publicKey = trim((string) file_get_contents($directory . '/signing_public_key'));
    }

    if ($privateKey === null) {
        $pair = sodium_crypto_sign_keypair();
        $privateKey = sodium_bin2base64(
            sodium_crypto_sign_secretkey($pair),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
        $publicKey = sodium_bin2base64(
            sodium_crypto_sign_publickey($pair),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
    } elseif ($publicKey === null) {
        $secret = sodium_base642bin($privateKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $publicKey = sodium_bin2base64(
            sodium_crypto_sign_publickey_from_secretkey($secret),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
    }

    ensure_secret('signing_private_key', $privateKey, static fn (): string => $privateKey);
    ensure_secret('signing_public_key', $publicKey, static fn (): string => $publicKey);

    $adminEmail = env_value('OFFICE_ADMIN_EMAIL') ?? 'admin@localhost';
    $adminPasswordProvided = env_value('OFFICE_ADMIN_PASSWORD');
    $adminPassword = ensure_secret(
        'admin_password',
        $adminPasswordProvided,
        static fn (): string => random_urlsafe(24)
    );
    ensure_secret('admin_email', $adminEmail, static fn (): string => $adminEmail);

    if (!is_file($directory . '/admin_credentials.txt')) {
        atomic_write(
            $directory . '/admin_credentials.txt',
            "Office Central initial administrator\n" .
            "Email: {$adminEmail}\n" .
            "Password: {$adminPassword}\n"
        );
    }

    if (!is_file($directory . '/initialized')) {
        atomic_write($directory . '/initialized', gmdate('c') . "\n");
    }

    echo "Office Central runtime secrets are ready.\n";
    echo "Initial admin credentials are stored at /run/office-secrets/admin_credentials.txt inside the app container.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Secret bootstrap failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
