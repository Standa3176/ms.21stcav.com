<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 260728-fwx T1 — one cached row per (global `pa_*` attribute, term).
 *
 * Written READ-ONLY-from-Woo by `spec:sync-taxonomy-cache`; read by the
 * upcoming SpecTaxonomyResolver (T2) to resolve a spec value to an EXISTING
 * term id without hitting the flaky Woo terms endpoint per product.
 *
 * This model NEVER touches Woo — it is a pure local mirror. The composite
 * unique(attribute_id, term_id) is what makes the sync idempotent.
 *
 * @property int $id
 * @property int $attribute_id
 * @property string $attribute_slug
 * @property string|null $attribute_name
 * @property int $term_id
 * @property string $term_name
 * @property string|null $term_slug
 */
class WooAttributeTerm extends Model
{
    protected $table = 'woo_attribute_terms';

    protected $fillable = [
        'attribute_id',
        'attribute_slug',
        'attribute_name',
        'term_id',
        'term_name',
        'term_slug',
    ];

    protected $casts = [
        'attribute_id' => 'integer',
        'term_id' => 'integer',
    ];
}
