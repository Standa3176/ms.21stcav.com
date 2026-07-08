<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Database\Factories\Domain\Products\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 2 Plan 01 — Product mirror of WooCommerce's product identity.
 *
 * woo_product_id is the canonical cross-system ID. Laravel's auto-increment id
 * is LOCAL-ONLY — never pass it to Woo writes (Pitfall P2-D once catalogued).
 *
 * For simple products, $sku is populated; for variable products, $sku is null
 * and the children carry unique per-variation SKUs (see ProductVariant).
 *
 * Soft-deletes on the parent preserve sync history through retention-prune
 * cycles. Hard-delete cascades to ProductVariant children via FK.
 */
final class Product extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'woo_product_id', 'sku', 'ean', 'name', 'type', 'status', 'stock_status', 'stock_quantity',
        'brand_id', 'category_id', 'category_ids',
        'buy_price', 'sell_price', 'cost_price',
        'is_custom_ms', 'exclude_from_auto_update', 'is_internal_only', 'tags',
        'last_synced_at', 'last_sync_run_id',
        'last_sales_count_90d', 'last_sales_count_computed_at',
        // Phase 6 Plan 01 — auto-create + SEO + completeness columns.
        'slug',
        'short_description',
        'long_description',
        'meta_description',
        'image_url',
        'gallery_image_urls',
        'requires_manual_image_review',
        'auto_create_status',
        'completeness_score',
        'completeness_computed_at',
        'completeness_missing_fields',
        // 2026-05-30 — curated key/value attributes for WC "Additional Information"
        // tab + Flatsome theme spec table (drives storefront layout parity).
        'attributes_json',
        // Quick task 260708-b4f — Woo maintenance reconciliation (Pass 1): each
        // live product's REAL Woo state, mirrored nightly by
        // products:reconcile-woo-maintenance for the Maintenance dashboard.
        'woo_image_count',
        'woo_gtin',
        'woo_category_count',
        'woo_stock_status',
        'woo_reconciled_at',
        // Quick task 260708-dyy — brand reconciliation Pass A: real product_brand
        // term count per live product (the storefront Brand link), captured by the
        // WP-REST brand pass in products:reconcile-woo-maintenance.
        'woo_brand_count',
    ];

    protected $casts = [
        'is_custom_ms' => 'bool',
        'exclude_from_auto_update' => 'bool',
        // 260611-f1y — flags products curated as "internal-use" (Credit / Offer /
        // Quote Payment style). Drives products:push-visibility-to-woo +
        // Filament toggle. Intentionally NOT in getActivitylogOptions()->logOnly()
        // — operator UX flag, not auditable product data per planner decision.
        'is_internal_only' => 'bool',
        'tags' => 'array',
        'last_synced_at' => 'datetime',
        'buy_price' => 'decimal:4',
        'sell_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'last_sales_count_90d' => 'int',
        'stock_quantity' => 'integer',
        'last_sales_count_computed_at' => 'datetime',
        // Phase 6 Plan 01 — auto-create casts.
        'category_ids' => 'array',
        'gallery_image_urls' => 'array',
        'requires_manual_image_review' => 'bool',
        'completeness_score' => 'int',
        'completeness_computed_at' => 'datetime',
        'completeness_missing_fields' => 'array',
        'attributes_json' => 'array',
        // Quick task 260708-b4f — Woo maintenance reconciliation casts.
        'woo_image_count' => 'integer',
        'woo_category_count' => 'integer',
        'woo_reconciled_at' => 'datetime',
        // Quick task 260708-dyy — brand reconciliation Pass A.
        'woo_brand_count' => 'integer',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'sku', 'name', 'type', 'status',
                'brand_id', 'category_id',
                'buy_price', 'sell_price',
                'is_custom_ms', 'exclude_from_auto_update',
            ])
            ->logOnlyDirty();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 3 Plan 02 — pricing-key accessors consumed by RuleResolver
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Return the brand_id used by the RuleResolver's brand / brand_category
     * filter paths. Null when the product has no brand mapped (falls through
     * to category or default_tier).
     */
    public function getPricingBrandId(): ?int
    {
        return $this->brand_id === null ? null : (int) $this->brand_id;
    }

    /**
     * Return the category_id used by the RuleResolver's category /
     * brand_category filter paths. Null when the product has no category
     * mapped (falls through to brand or default_tier).
     */
    public function getPricingCategoryId(): ?int
    {
        return $this->category_id === null ? null : (int) $this->category_id;
    }

    /**
     * Canonical "auto-created products only" predicate.
     *
     * WHY THIS EXISTS — 2026-06-06 quick task 260606-mx9 uncovered a latent
     * bug: callers were using `whereNotNull('auto_create_status')` as the
     * "exclude legacy WC-migrated products" filter. But the column is
     * `NOT NULL DEFAULT 'manual'` per migration
     * `2026_04_22_100300_add_auto_create_columns_to_products_table.php`
     * (with a belt-and-braces backfill of every pre-existing row to 'manual'
     * in the same migration's up() body). So `whereNotNull` is vacuous —
     * it never excludes anything. The 2026-06-06 Manhattan retry dry-run
     * silently surfaced 5,668 candidates instead of the expected ~36
     * because of this bug.
     *
     * The correct predicate is `!= 'manual'` — 'manual' is the migration's
     * explicit "legacy / pre-auto-create" marker (per its docblock).
     *
     * This scope is the single source of truth so RetryMissingImagesCommand,
     * AutoCreateHealthPage, and any future caller cannot drift. Quick task
     * 260606-o63 swapped the two known call sites onto this scope and
     * installed `tests/Architecture/AutoCreatedPredicateTest.php` to fail
     * CI if the vacuous predicate is ever re-introduced.
     *
     * Eloquent strips the `scope` prefix and lowercases the next char on
     * call, so callers invoke `Product::query()->autoCreated()`.
     */
    public function scopeAutoCreated(Builder $query): Builder
    {
        return $query->where('auto_create_status', '!=', 'manual');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
