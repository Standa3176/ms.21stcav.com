<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5 Plan 01 — Competitor price history (COMP-07).
 *
 * NEVER TRUNCATED. UNIQUE(competitor_id, sku, recorded_at) enforces
 * idempotent re-ingest of the same CSV.
 *
 * NO LogsActivity trait — high-volume writes would flood activity_log
 * (~3.6M rows/year at 5 competitors × 2000 SKUs × 365 days). Phase 2
 * ProductVariant established this precedent for write-heavy tables.
 *
 * Price columns:
 * - price_pennies_ex_vat: analyser input (MarginAnalyser reads this).
 * - price_pennies_gross:  raw CSV value preserved for audit / legal.
 */
final class CompetitorPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'competitor_id',
        'sku',
        'mpn',
        'price_pennies_ex_vat',
        'price_pennies_gross',
        'recorded_at',
        'ingest_run_id',
    ];

    protected $casts = [
        'price_pennies_ex_vat' => 'int',
        'price_pennies_gross' => 'int',
        'recorded_at' => 'datetime',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(CompetitorIngestRun::class, 'ingest_run_id');
    }

    protected static function newFactory(): CompetitorPriceFactory
    {
        return CompetitorPriceFactory::new();
    }
}
