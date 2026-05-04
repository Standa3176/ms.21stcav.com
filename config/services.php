<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'woo' => [
        // Phase 2 populates url/consumer_key/consumer_secret; Phase 1 ships placeholders only
        'url' => env('WOO_URL'),
        'consumer_key' => env('WOO_CONSUMER_KEY'),
        'consumer_secret' => env('WOO_CONSUMER_SECRET'),

        // Phase 1 — webhook HMAC secret (Plan 04 middleware reads this)
        'webhook_secret' => env('WC_WEBHOOK_SECRET'),

        // Phase 1 — shadow-mode flag (D-08; MUST default to false)
        'write_enabled' => env('WOO_WRITE_ENABLED', false),
    ],

    'woocommerce' => [
        // Alias (some Filament plugins check services.woocommerce.*)
        'webhook_secret' => env('WC_WEBHOOK_SECRET'),
    ],

    // Phase 2 Plan 02 — 21stcav.com supplier API (SYNC-01 + SYNC-02 JWT auth).
    // Operator populates credentials before running the first live sync; JWT cache
    // key includes md5($username) so credential rotation naturally invalidates.
    'supplier' => [
        'url' => env('SUPPLIER_API_URL', 'https://21stcav.com'),
        'username' => env('SUPPLIER_API_USERNAME'),
        'password' => env('SUPPLIER_API_PASSWORD'),
    ],

    // Phase 4 Plan 01 — Bitrix24 one-way CRM sync.
    // BITRIX_WEBHOOK_URL: inbound-webhook URL from Bitrix admin → Developer resources → Other → Inbound webhook.
    //   Contains USER_ID + SECRET path segments — NEVER log the full URL (T-04-01-01 mitigation).
    // CRM_WRITE_ENABLED: Phase 1 D-08 shadow-mode parallel. MUST default to false; Phase 7 cutover flips.
    // BITRIX_SMOKE_TEST_ALLOWED: gate on `php artisan bitrix:smoke-test` — creates/reads real Bitrix records.
    //   MUST default to false so the command is never run accidentally against production.
    // Quick task 260503-rul — OpenAI / ChatGPT credential kind.
    // Optional env-fallback for IntegrationCredentialKind::OpenAiApi when no DB row exists.
    // Operator typically populates the credential via /admin → Integration Credentials,
    // but the env path is supported for parity with the other 5 kinds.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    // Quick task 260504-ld8 — remote supplier MySQL VPS (Phase 1: creds only).
    // Resolver env-fallback for IntegrationCredentialKind::SupplierDb. Operator
    // typically populates via /admin → Integration Credentials, but env path is
    // supported for parity with the other kinds. Phase 2 will use these to pull
    // SKU + buy_price + stock_quantity into local products.
    'supplier_db' => [
        'host' => env('SUPPLIER_DB_HOST'),
        'port' => env('SUPPLIER_DB_PORT', 3306),
        'database' => env('SUPPLIER_DB_DATABASE'),
        'username' => env('SUPPLIER_DB_USERNAME'),
        'password' => env('SUPPLIER_DB_PASSWORD'),
    ],

    'bitrix' => [
        'webhook_url' => env('BITRIX_WEBHOOK_URL'),
        'write_enabled' => env('CRM_WRITE_ENABLED', false),
        'smoke_test_allowed' => env('BITRIX_SMOKE_TEST_ALLOWED', false),
        'cache_ttl_hours' => (int) env('BITRIX_CACHE_TTL_HOURS', 24),
        'push_retry_attempts' => (int) env('BITRIX_PUSH_RETRY_ATTEMPTS', 3),
    ],

];
