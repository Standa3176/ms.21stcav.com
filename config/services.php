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

];
