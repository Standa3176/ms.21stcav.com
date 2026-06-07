<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | EAN reverse-lookup provider (260607-hxa)
    |--------------------------------------------------------------------------
    |
    | Default GTIN-backfill provider used by `products:backfill-merchant-feed`
    | when supplier_db.feeds_products.ean is missing / invalid for a candidate
    | SKU.
    |
    |   'ean_search' (default) → App\Domain\ProductAutoCreate\Services\EanSearchClient
    |                            against api.ean-search.org. ~€0.003/query, free
    |                            tier 100/day. Strong on AV/B2B SKUs (Sony FW-,
    |                            Panasonic PT-, PTZOptics, Roland, BirdDog).
    |   'icecat'               → App\Domain\ProductAutoCreate\Services\IcecatClient
    |                            against live.icecat.biz. ~0.2p/query. Kept as
    |                            forensic A/B comparison + downtime fallback for
    |                            EAN-search.
    |
    | Flip via .env: `EAN_FALLBACK_PROVIDER=icecat`. Misconfigured values fall
    | back to 'ean_search' at the call site in BackfillMerchantFeedCommand.
    | Icecat remains the primary image-lookup source (SourceProductImagesCommand)
    | regardless of this setting — this key only governs the GTIN backfill path.
    |
    */

    'ean_fallback_provider' => env('EAN_FALLBACK_PROVIDER', 'ean_search'),

];
