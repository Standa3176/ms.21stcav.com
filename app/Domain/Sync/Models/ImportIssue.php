<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Database\Factories\Domain\Sync\ImportIssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 2 Plan 01 — Catalogue-health triage row (SYNC-12 + D-09).
 *
 * Four issue types (enum constants):
 *   - missing_at_supplier       (Woo product, no supplier match → SYNC-06 pending)
 *   - unknown_sku               (Supplier SKU Woo doesn't know; D-09)
 *   - missing_cost_price        (Product in DB, buy_price NULL)
 *   - exclude_flag_no_metadata  (Has _exclude meta but no notes rationale)
 *
 * resolved_at nullable — scopeUnresolved filters on IS NULL.
 * scopeOfType filters on issue_type for Filament ImportIssueResource tabs.
 */
final class ImportIssue extends Model
{
    use HasFactory;

    public const TYPE_MISSING_AT_SUPPLIER = 'missing_at_supplier';
    public const TYPE_UNKNOWN_SKU = 'unknown_sku';
    public const TYPE_MISSING_COST_PRICE = 'missing_cost_price';
    public const TYPE_EXCLUDE_FLAG_NO_METADATA = 'exclude_flag_no_metadata';

    protected $fillable = [
        'sku', 'woo_product_id', 'woo_variation_id',
        'issue_type', 'detected_at', 'last_seen_at', 'resolved_at',
        'notes', 'correlation_id',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('issue_type', $type);
    }

    protected static function newFactory(): ImportIssueFactory
    {
        return ImportIssueFactory::new();
    }
}
