<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 260709-hl0 — thin Eloquent model for `supplier_sku_cache`.
 *
 * Backs the "On supplier DB" membership filter on /admin/suggestions. The
 * table is a single-column (sku PRIMARY KEY) materialised key set, rebuilt
 * by SupplierSkuRegistry::refresh() via truncate + chunked insertOrIgnore.
 *
 * Introduced to route SupplierSkuRegistry's writes through Eloquent instead
 * of the DB facade (SYNC-04 — the Sync layer must not import
 * Illuminate\Support\Facades\DB). The query builder obtained via
 * SupplierSkuCache::query() delegates to the same base builder DB::table()
 * returned, so truncate()/insertOrIgnore() produce byte-identical SQL.
 *
 * No timestamps (the migration creates only the `sku` column) and
 * $guarded=[] so the chunked bulk insertOrIgnore mass-assigns freely.
 */
final class SupplierSkuCache extends Model
{
    protected $table = 'supplier_sku_cache';

    public $timestamps = false;

    protected $guarded = [];
}
