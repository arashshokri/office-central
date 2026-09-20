<?php

return [
    'version' => env('CENTRAL_VERSION', trim((string) file_get_contents(base_path('VERSION')))),
    'public_url' => env('CENTRAL_PUBLIC_URL', env('APP_URL')),
    'require_https' => env('CENTRAL_REQUIRE_HTTPS', true),
    'agent_poll_seconds' => (int) env('AGENT_STATE_POLL_SECONDS', 5),
    'health_interval_seconds' => (int) env('LICENSE_HEALTH_INTERVAL_SECONDS', 43200),
    'download_token_lifetime_seconds' => (int) env('DOWNLOAD_TOKEN_LIFETIME_SECONDS', 600),
    'offline_after_seconds' => (int) env('INSTALLATION_OFFLINE_AFTER_SECONDS', 90000),
    'nonce_lifetime_seconds' => (int) env('NONCE_LIFETIME_SECONDS', 900),
    'package_disk' => env('PACKAGE_STORAGE_DRIVER', 'packages'),
    'signing_private_key' => env('CENTRAL_SIGNING_PRIVATE_KEY'),
    'signing_public_key' => env('CENTRAL_SIGNING_PUBLIC_KEY'),
    'default_lock_message' => env('DEFAULT_LOCK_MESSAGE', 'دسترسی این سامانه موقتاً محدود شده است. لطفاً با پشتیبانی در ارتباط باشید.'),
];
