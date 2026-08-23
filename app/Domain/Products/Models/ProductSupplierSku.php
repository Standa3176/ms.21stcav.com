<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quick task 260823-clp — an alternative supplier code for a product.
 *
 * The successor to the legacy Stock Updater plugin's "alternative SKU" field:
 * when a second supplier lists the same physical part under a different code,
 * recording it here stops the app treating that row as an unknown part and
 * auto-creating a duplicate product on Woo.
 *
 * `normalised_sku` is maintained by the model, never by callers — every write
 * path (Filament, console, tests) goes through the same normalisation, so the
 * unique index cannot be defeated by casing or padding.
 */
final class ProductSupplierSku extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_DERIVED_MPN = 'derived_mpn';

    public const SOURCE_DERIVED_EAN = 'derived_ean';

    protected $fillable = [
        'product_id', 'supplier_id', 'supplier_sku', 'normalised_sku',
        'source', 'confidence', 'notes',
    ];

    protected $casts = [
        'supplier_id' => 'integer',
        'confidence' => 'integer',
    ];

    protected static function booted(): void
    {
        // Single normalisation choke point. Aligns with SupplierOfferSnapshot's
        // lowercase-trimmed matchKey convention (and against SkuMatcher's old
        // case-sensitive one — the inconsistency the 2026-08-09 TODO called out).
        static::saving(function (self $alias): void {
            $alias->supplier_sku = trim((string) $alias->supplier_sku);
            $alias->normalised_sku = self::normalise($alias->supplier_sku);
        });
    }

    public static function normalise(string $sku): string
    {
        return strtolower(trim($sku));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Every alias code in the system, normalised → product_id.
     *
     * Read by the add-candidate scanner and the auto-create duplicate gate.
     * Small by nature (operator-curated plus derived proposals), so a single
     * pluck beats per-row lookups in either hot path.
     *
     * @return array<string, int>
     */
    public static function normalisedMap(): array
    {
        return self::query()
            ->pluck('product_id', 'normalised_sku')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
