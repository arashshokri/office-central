<?php

return [
    'version' => env('CENTRAL_VERSION', '1.0.0'),
    'public_url' => env('CENTRAL_PUBLIC_URL', env('APP_URL')),
    'require_https' => env('CENTRAL_REQUIRE_HTTPS', true),
    'lease_refresh_seconds' => (int) env('LICENSE_LEASE_REFRESH_SECONDS', 300),
    'health_interval_seconds' => (int) env('LICENSE_HEALTH_INTERVAL_SECONDS', 43200),
    'offline_grace_seconds' => (int) env('LICENSE_OFFLINE_GRACE_PERIOD_SECONDS', 604800),
    'download_token_lifetime_seconds' => (int) env('DOWNLOAD_TOKEN_LIFETIME_SECONDS', 600),
    'offline_after_seconds' => (int) env('INSTALLATION_OFFLINE_AFTER_SECONDS', 90000),
    'nonce_lifetime_seconds' => (int) env('NONCE_LIFETIME_SECONDS', 900),
    'package_disk' => env('PACKAGE_STORAGE_DRIVER', 'packages'),
    'signing_private_key' => env('CENTRAL_SIGNING_PRIVATE_KEY'),
    'signing_public_key' => env('CENTRAL_SIGNING_PUBLIC_KEY'),
];
