<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pricing Engine Configuration (Phase 3 Plan 01)
|--------------------------------------------------------------------------
|
| D-02 + Pitfall 5: locks the rounding mode in ONE place so drift between
| the calculator, the competitor-CSV VAT-strip helper (Phase 5) and any
| future re-baseline is a single-file change.
|
| D-05: vat_basis_points is the default VAT for compute() + stripVat(). UK
| standard rate is 20% = 2000 basis points. Changing it to e.g. 5% (reduced
| rate) would be a single env override, but retail VAT on AV equipment is
| always 20% in scope for v1.
|
| fixture_path: golden-fixtures.json is the Phase 3 ship gate (PRCE-06).
| Exposed via config so a future re-baseline can point at a dated snapshot
| without touching the test file.
*/

return [

    // D-02 — match the legacy Stock Updater plugin's bare round() behaviour.
    // Change this AND re-baseline tests/Fixtures/Pricing/golden-fixtures.json
    // in the SAME commit if ops signs off a rounding-mode flip.
    'rounding_mode' => PHP_ROUND_HALF_UP,

    // D-05 — default UK standard VAT. Override via PRICING_VAT_BASIS_POINTS
    // env for regional edge cases. 2000 = 20.00%.
    'vat_basis_points' => (int) env('PRICING_VAT_BASIS_POINTS', 2000),

    // Phase 3 ship gate fixture path. Re-baseline per D-04.
    'fixture_path' => base_path('tests/Fixtures/Pricing/golden-fixtures.json'),

];
