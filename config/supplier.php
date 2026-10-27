<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Supplier freshness configuration (Quick task 260608-g8x)
|--------------------------------------------------------------------------
|
| Per-supplier stale-feed handling. Three states per supplier:
|
|   fresh  — MAX(recorded_at) >= NOW() - threshold_days (default 7)
|   amber  — within the last `amber_warning_ratio` of the window
|            (default 0.7 → for a 7d window, days_since ∈ {4,5,6})
|   stale  — past the threshold → downstream scanners exclude this supplier
|
| Per-supplier override lives on the `suppliers.stale_after_days` column.
| NULL falls back to `default_stale_after_days` here.
|
| Plain literal values (NOT env()) — the env() guardrail (260606-c4o
| EnvUsageTest) forbids env() outside config/, but operator preference here
| is "edit config + redeploy" not "flip an env var." Both keys are slow-
| changing policy tunables.
*/

return [
    // Days of supplier silence before classification flips to 'stale'.
    'default_stale_after_days' => 7,

    // Fraction of the window after which classification flips to 'amber'.
    // Example: ratio=0.7 + threshold=7 → amber boundary = floor(7 * 0.7) = 4.
    // days_since ∈ {0..3} → fresh; {4..6} → amber; >=7 → stale.
    'amber_warning_ratio' => 0.7,

    /*
    | Quick task 261027-duz — supplier-cost outlier guard.
    |
    | supplier:db-sync and products:hydrate-stock-from-offers refuse to adopt a
    | cheapest offer that is < 1/ratio of the LOWER median of the other
    | suppliers' prices for the same key: the existing buy_price is held, the
    | outlier supplier's stock is not counted, and supplier:db-sync raises a
    | supplier_cost_outlier ImportIssue. Incident 2026-10-27: Midwich's code
    | 1301222 (an unrelated £7.20 item) costed the £88.20 Yealink T74U.
    |
    | Plain literals (no env()) — slow-changing policy tunables, same as above.
    | enabled=false restores the pre-261027 cheapest-wins behaviour exactly.
    */
    'cost_outlier_guard' => [
        'enabled' => true,
        'ratio' => 3,
    ],
];
