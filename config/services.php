<?php

declare(strict_types=1);

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

        // VAT basis for prices PUSHED to Woo's regular_price (PushPriceChangeToWoo).
        // sell_price is stored VAT-INCLUSIVE; default false = push inc-VAT (matches
        // the existing auto-create convention). Set WOO_PUSH_PRICES_EX_VAT=true if
        // the Woo store enters prices EX-VAT (confirm against the storefront before
        // cutover — getting this wrong is a 20% price error). Competitor feeds are
        // ex-VAT, so trade stores often display ex-VAT.
        'push_prices_ex_vat' => env('WOO_PUSH_PRICES_EX_VAT', false),

        // WAF compatibility — many WP hosts (CWP, Imunify360, generic mod_security
        // configs) block HTTP PUT to /wp-json/* at the Apache layer while letting
        // POST through. WP-REST routes `/products/{id}` to the same update handler
        // for both POST and PUT (WP_REST_Server::EDITABLE), so we can route our
        // PUT calls through POST without changing semantics. Default true (safer
        // across hosting environments). Set WOO_USE_POST_FOR_UPDATES=false to
        // restore strict PUT.
        'use_post_for_updates' => env('WOO_USE_POST_FOR_UPDATES', true),

        // 260613-plo — sibling of use_post_for_updates above for HTTP DELETE.
        // Same WAF families (CWP / Imunify360 / generic mod_security) that block
        // PUT to /wp-json/* also block DELETE — and they return HTML 403 pages
        // that the Automattic SDK then mis-parses as "JSON ERROR: Syntax error".
        // Operator-visible incident 2026-06-13: `brands:dedupe --delete-empty-woo-terms`
        // returned 11 phantom failures because every Woo DELETE was 403'd at
        // nginx before reaching PHP. WP-REST treats POST with `?_method=DELETE`
        // as a destructive operation identically to a true DELETE (it's the
        // method-override convention WP itself documents for client-tunnelled
        // verbs), so we route DELETE through POST + `?_method=DELETE` query
        // tunnel when this flag is true (default). Set WOO_USE_POST_FOR_DELETES=false
        // to restore strict DELETE for hosts that don't block the verb.
        'use_post_for_deletes' => env('WOO_USE_POST_FOR_DELETES', true),

        // 260613-pzc — ProductBrandTermResolver slug-collision strategy.
        //
        // 2026-06-13 INCIDENT — the old `-brand` suffix fallback in createTerm()
        // silently produced 11 duplicate product_brand pairs on prod
        // ({brand} + {brand}-brand) over time. Root cause: every time WP refused
        // a clean-slug product_brand create because a `product_tag` already owned
        // that slug, the resolver blindly retried with `{slug}-brand` — which
        // succeeded — and a later code path (operator or another tool) eventually
        // also created the clean-slug brand. Two brand terms, same display name,
        // forever-after divergent product attachments. Cleanup arc tracked in
        // memory `meetingstore-brand-cleanup-followups`.
        //
        // This config drives the strategy createTerm() applies on slug collision:
        //
        //   - 'suffix-on-tag-collision'        (default, 260703-p8m — SAFE)
        //         Pre-flight checks `wp/v2/product_tag?slug={primary}`; on a
        //         CONFIRMED collision it creates the `{slug}-brand` product_brand
        //         term (brand NAME stays clean, e.g. "Yealink"; only the slug
        //         carries the -brand suffix → /brand/yealink-brand/). Safe by
        //         construction: the clean slug is provably held by an existing
        //         product_tag, so a clean-slug product_brand can NEVER be created
        //         for that name → the 2026-06-13 duplicate-PAIR pathology cannot
        //         recur (that came from force-suffix creating a suffix term
        //         WITHOUT a confirmed collision). Brands whose clean slug is free
        //         still create cleanly via Attempt 1.
        //
        //   - 'skip-creation'                  (SAFE — old default)
        //         Pre-flight checks `wp/v2/product_tag?slug={primary}` and, on
        //         collision, logs a warning + returns null. NEVER creates the
        //         `-brand` suffixed term. Operator must clean the colliding tag
        //         (or flip strategy to auto-delete-empty-colliding-tag) before
        //         the brand auto-creates on the next batch.
        //
        //   - 'auto-delete-empty-colliding-tag' (AGGRESSIVE, opt-in)
        //         Pre-flight as above; if the colliding tag has `count=0` (no
        //         products attached) it is deleted via the WAF-tunnelled DELETE
        //         from 260613-plo (WpRestClient::delete → POST ?_method=DELETE)
        //         and the clean-slug primary is retried. Tags with attached
        //         products fall through to skip-creation behaviour.
        //
        //   - 'force-suffix'                   (DEPRECATED escape hatch)
        //         Retained as emergency escape hatch ONLY — replicates the OLD
        //         pre-260613-pzc behaviour (no pre-flight, blind `-brand`
        //         suffix retry) and emits a warning surfacing the duplicate-
        //         pair risk on every invocation. Do not set this in prod
        //         unless you've already validated there's no colliding tag.
        'brand_slug_collision_strategy' => env('WOO_BRAND_SLUG_COLLISION_STRATEGY', 'suffix-on-tag-collision'),

        // 260607-pys — Storefront base URL for the "View on storefront"
        // per-row action on /admin/ad-candidates. Defaults to the live
        // meetingstore.co.uk storefront; overridable via WOO_STOREFRONT_URL.
        // env() inside config is the only legal place per 260606-c4o
        // EnvUsageTest guardrail — never read env() from the page directly.
        'storefront_url' => env('WOO_STOREFRONT_URL', 'https://meetingstore.co.uk'),

        // 260708-gab / 260708-jou — CHUNK size for background Catalogue Gaps bulk fixes: how many SKUs go into
        // each RunCatalogueGapFixJob dispatched onto the sync-bulk queue. No longer a hard per-click cap — the
        // whole ticked selection is queued (up to maintenance_fix_max_per_run below) as chunks of this size,
        // processed one batch at a time by the single sync-bulk worker.
        'maintenance_fix_batch_limit' => (int) env('WOO_MAINTENANCE_FIX_BATCH_LIMIT', 25),

        // 260708-jou — hard ceiling on how many products a single Catalogue Gaps bulk-fix CLICK may queue
        // (the work runs in the background now; this stops one click queueing the entire catalogue).
        'maintenance_fix_max_per_run' => (int) env('WOO_MAINTENANCE_FIX_MAX_PER_RUN', 1000),
    ],

    'woocommerce' => [
        // Alias (some Filament plugins check services.woocommerce.*)
        'webhook_secret' => env('WC_WEBHOOK_SECRET'),
    ],

    // WP REST API (separate from WC REST). Used for taxonomies + post-type
    // operations that WC's REST schema doesn't expose — primarily writes to
    // the `product_brand` taxonomy that drives meetingstore.co.uk's clickable
    // `Brand: <link>` storefront display (the WC native /products/brands
    // endpoint is dormant on this storefront — see memory
    // meetingstore-brand-display). The WC consumer key/secret only auths
    // /wc/v3/* — for /wp/v2/* we need a WordPress Application Password
    // (operator generates per-user in WP Admin → Profile → Application
    // Passwords). Password value contains spaces — MUST be double-quoted
    // in .env or dotenv parser errors.
    'wp_rest' => [
        'base_url' => env('WP_REST_BASE_URL', rtrim((string) env('WOO_URL'), '/').'/wp-json'),
        'username' => env('WP_REST_USERNAME'),
        'app_password' => env('WP_REST_APP_PASSWORD'),
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

    // Icecat product-content syndication (product images by GTIN/EAN).
    // Resolver env-fallback for IntegrationCredentialKind::Icecat. Operator
    // typically stores the username via /admin → Integration Credentials.
    // ICECAT_USERNAME — Icecat account name (Open Icecat works username-only).
    // ICECAT_API_TOKEN / ICECAT_CONTENT_TOKEN — Full Icecat only (non-sponsored
    //   brands + asset/image access); passed as request headers, optional.
    // NOTE: Icecat image URLs may be IP-restricted to your account's whitelisted
    //   IP — the source-images command downloads server-side, so whitelist the
    //   production server IP (or use Access Tokens) in your Icecat account.
    'icecat' => [
        'username' => env('ICECAT_USERNAME'),
        // Full Icecat: app_key (QUERY param, from the Icecat "My Profile" page)
        // unlocks Full content; api/content tokens are optional UUID headers.
        'app_key' => env('ICECAT_APP_KEY'),
        'api_token' => env('ICECAT_API_TOKEN'),
        'content_token' => env('ICECAT_CONTENT_TOKEN'),
        'base_url' => env('ICECAT_BASE_URL', 'https://live.icecat.biz/api'),
        'language' => env('ICECAT_LANGUAGE', 'EN'),
    ],

    // Quick task 260607-hxa — EAN-search.org (api.ean-search.org).
    // DEFAULT GTIN reverse-lookup provider for products:backfill-merchant-feed
    // (config-switchable via integrations.ean_fallback_provider). Resolver
    // env-fallback for IntegrationCredentialKind::EanSearch. Operator typically
    // stores the token via /admin → Integration Credentials.
    // EAN_SEARCH_TOKEN — bearer token from https://www.ean-search.org/ dashboard
    //   (free tier 100 queries/day; paid €30/10k ≈ €0.003/query).
    // EAN_SEARCH_BASE_URL — defaults to the public endpoint; only set for
    //   testing / alternative deployments.
    'ean_search' => [
        'token' => env('EAN_SEARCH_TOKEN', ''),
        'base_url' => env('EAN_SEARCH_BASE_URL', 'https://api.ean-search.org/api'),
    ],

    // Web image search (manufacturer product images by "{brand} {mpn}" query).
    // Resolver env-fallback for IntegrationCredentialKind::ImageSearch; operator
    // typically stores the api_key via /admin → Integration Credentials.
    // provider: 'serper' (default, serper.dev). country biases results (gl=uk).
    // blocked_domains: domains to drop from results (e.g. your competitors) so we
    // never pull a rival's product photography — the Claude-vision validator also
    // rejects competitor-branded images as a second line of defence.
    'image_search' => [
        'provider' => env('IMAGE_SEARCH_PROVIDER', 'serper'),
        'api_key' => env('IMAGE_SEARCH_API_KEY'),
        'base_url' => env('IMAGE_SEARCH_BASE_URL', 'https://google.serper.dev'),
        'country' => env('IMAGE_SEARCH_COUNTRY', 'uk'),
        'blocked_domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IMAGE_SEARCH_BLOCKED_DOMAINS', '')),
        ))),
    ],

];
