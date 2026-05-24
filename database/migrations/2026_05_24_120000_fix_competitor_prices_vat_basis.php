<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct the VAT basis of historical competitor_prices rows.
 *
 * Competitor CSV feeds are EX-VAT (net/trade) prices (operator-confirmed
 * 2026-05-24), but the ingest treated the raw value as VAT-INCLUSIVE: it stored
 * raw → price_pennies_gross and stripVat(raw) → price_pennies_ex_vat. That made
 * every competitor look ~17% cheaper than reality and broke the undercut pricer
 * (it floored everything). The ingest is now fixed (raw = ex-VAT; gross = +VAT);
 * this migration re-derives the existing rows the same way:
 *
 *   new ex_vat = old gross         (the raw net value)
 *   new gross  = old gross + VAT
 *
 * Done in a chunked PHP loop (not a single SQL UPDATE) so it is unambiguous and
 * identical on MySQL + SQLite. Runs exactly once (migration tracking) so it is
 * not at risk of double-applying.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vatBps = (int) config('pricing.vat_basis_points', 2000);

        DB::table('competitor_prices')->orderBy('id')->chunkById(2000, function ($rows) use ($vatBps): void {
            DB::transaction(function () use ($rows, $vatBps): void {
                foreach ($rows as $row) {
                    $rawExVat = (int) $row->price_pennies_gross; // old "gross" held the raw net value
                    DB::table('competitor_prices')->where('id', $row->id)->update([
                        'price_pennies_ex_vat' => $rawExVat,
                        'price_pennies_gross' => (int) round($rawExVat * (10000 + $vatBps) / 10000),
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        // Restore the pre-fix (buggy) shape: gross = raw net value; ex_vat = stripVat(raw).
        $vatBps = (int) config('pricing.vat_basis_points', 2000);

        DB::table('competitor_prices')->orderBy('id')->chunkById(2000, function ($rows) use ($vatBps): void {
            DB::transaction(function () use ($rows, $vatBps): void {
                foreach ($rows as $row) {
                    $rawExVat = (int) $row->price_pennies_ex_vat; // current ex_vat holds the raw net value
                    DB::table('competitor_prices')->where('id', $row->id)->update([
                        'price_pennies_gross' => $rawExVat,
                        'price_pennies_ex_vat' => (int) round($rawExVat * 10000 / (10000 + $vatBps)),
                    ]);
                }
            });
        });
    }
};
