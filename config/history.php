<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| History (price + stock snapshot) configuration
|--------------------------------------------------------------------------
|
| Quick task 260504-muq — 90-day rolling history retention. Drives
| App\Domain\Products\Console\Commands\SnapshotsPruneCommand (history:prune)
| which runs daily at 04:00 Europe/London via routes/console.php.
|
| retention_days — number of days to keep product_price_snapshots +
|   supplier_offer_snapshots rows. Defaults to 90 (90 days × 5,633 products
|   = ~507k product snapshots; 90 × ~12k offers = ~1.1M offer snapshots —
|   well within MySQL/SQLite capability). Operators can shrink via .env
|   override during disk-pressure windows; lengthen for ad-hoc trend studies.
*/

return [
    'retention_days' => (int) env('HISTORY_RETENTION_DAYS', 90),
];
