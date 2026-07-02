<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Product Auto-Create Configuration (Phase 6 Plan 01)
|--------------------------------------------------------------------------
|
| Tunable knobs for the auto-create pipeline. Every downstream job /
| service reads through config('product_auto_create.*') — never hard-codes
| a value inline.
|
| mode — v1 ships as 'draft' (AUTO-07). Immediate-publish is the other
|   valid value and requires an explicit admin toggle via Plan 06-04's
|   AutoCreateSettingsPage + a written ops-cutover runbook entry.
|
| cta — meta_description trailer ("Shop now at meetingstore.co.uk" etc).
|   Configurable so ops can A/B-test SEO copy without a code change.
|
| optimize_images — spatie/image-optimizer binary invocation. Default
|   disabled on Windows dev (binaries absent); enabled on Linux VPS.
|   Wrapped in try/catch in Plan 06-02 regardless (Pitfall P6-C).
|
| placeholder_image_url — served via public/images/ (NOT storage:link per
|   Pitfall P6-F). Ships as a committed binary asset.
|
| completeness_publish_threshold — score gate for publish (D-07). 85 = bar.
|
| image_max_dimension / image_webp_quality / image_fetch_timeout_seconds —
|   bounds for Plan 06-02's intervention/image pipeline.
|
| max_image_bytes / min_image_bytes — Pitfall P6-A supply-chain guards.
*/

return [

    // D-07 + AUTO-07 — draft-first is the v1 lock.
    'mode' => env('PRODUCT_AUTO_CREATE_MODE', 'draft'),

    // D-01 — meta_description trailer + long-description footer.
    'cta' => env(
        'PRODUCT_AUTO_CREATE_CTA',
        'Shop now at meetingstore.co.uk'
    ),

    // AUTO-04 — intervention/image + spatie/image-optimizer pipeline.
    'optimize_images' => env(
        'PRODUCT_AUTO_CREATE_OPTIMIZE_IMAGES',
        PHP_OS_FAMILY !== 'Windows'
    ),

    // Pitfall P6-F — hosted at public/images/ so no storage:link dependency.
    'placeholder_image_url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/images/av-product-placeholder.webp',

    // D-07 publish gate.
    'completeness_publish_threshold' => 85,

    // Plan 06-02 image pipeline bounds (Pitfall P6-A + AUTO-04).
    'image_max_dimension' => 1200,
    'image_webp_quality' => 85,
    'image_fetch_timeout_seconds' => 10,

    // Pitfall P6-A — supply-chain size sanity checks.
    'max_image_bytes' => 10 * 1024 * 1024,  // 10 MB ceiling (DoS guard)
    'min_image_bytes' => 5 * 1024,          // 5 KB floor (HTML error-page guard)

    // Taxonomy resolution (Plan 06-03 TaxonomyResolver).
    'brand_taxonomy' => env('PRODUCT_AUTO_CREATE_BRAND_TAXONOMY', 'pa_brand'),
    'category_taxonomy' => env('PRODUCT_AUTO_CREATE_CATEGORY_TAXONOMY', 'product_cat'),

    // 260702-om7 — manufacturer names never offered as creatable Woo brands in the
    // brands-to-add list (case-insensitive). Not real brands / consumables buckets.
    'brands_to_add_exclude' => ['specials', 'un-branded', 'unbranded'],

    // 260702-qd8 — when creating a product from Suggestions whose manufacturer is
    // not yet a Woo brand, auto-create the brand term (normalised + junk-guarded)
    // instead of skipping/parking the product. Single on/off switch for both the
    // per-row approve (CreateWooProductJob) and the bulk pipeline
    // (draft-from-suggestions via RunAutoCreatePipelineJob).
    'auto_create_missing_brands' => true,

];
