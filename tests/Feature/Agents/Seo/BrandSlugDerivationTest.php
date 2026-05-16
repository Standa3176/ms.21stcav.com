<?php

declare(strict_types=1);

use App\Domain\Agents\Support\BrandSlugResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 02 Task 3 — BrandSlugResolver P12-C anti-regression
|--------------------------------------------------------------------------
|
| Per RESEARCH §P12-C: the wrong slug derivation algorithm (e.g. kebab-case
| of `Brand.name`) routes brand 'Logitech, Inc.' to `logitech,-inc..md`
| instead of `logitech.md` — agent silently degrades to global voice.
|
| BrandSlugResolver is the single source of truth — reads `brands.slug`
| column (NOT brands.name). Both ReadProductDraftTool (Plan 12-02) and
| RunSeoAgentJob (Plan 12-04) delegate here.
|
| Test cases (RESEARCH-pinned):
|   1. brand_id=5 with brands.slug='logitech', name='Logitech, Inc.' → 'logitech'
|      (NOT 'logitech-inc' or 'Logitech, Inc.')
|   2. brand_id=6 with brands.slug=null (no slug populated) → '6' (numeric fallback)
|   3. brand_id=null → 'global' (defensive — Plan 12-05 eligibility query
|      filters these out but if one slips through we don't crash)
|   4. Cache::flush() between cases — rememberForever caches per brand_id
*/

beforeEach(function () {
    Cache::flush();
    if (! Schema::hasTable('brands')) {
        Schema::create('brands', function ($t): void {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
        });
    }
    DB::table('brands')->truncate();
});

it('returns the brands.slug column value (NOT name) for a brand with a slug populated', function () {
    DB::table('brands')->insert(['id' => 5, 'slug' => 'logitech', 'name' => 'Logitech, Inc.']);

    expect(BrandSlugResolver::forBrandId(5))->toBe('logitech');
});

it('falls back to (string) brand_id when brands row has null slug', function () {
    DB::table('brands')->insert(['id' => 6, 'slug' => null, 'name' => 'NoSlugBrand']);

    expect(BrandSlugResolver::forBrandId(6))->toBe('6');
});

it('falls back to (string) brand_id when brand row does not exist in brands table', function () {
    // No row for id=99
    expect(BrandSlugResolver::forBrandId(99))->toBe('99');
});

it('returns "global" when brand_id is null (defensive — Plan 12-05 should filter these out)', function () {
    expect(BrandSlugResolver::forBrandId(null))->toBe('global');
});

it('caches per brand_id — second call hits the cache', function () {
    DB::table('brands')->insert(['id' => 7, 'slug' => 'poly', 'name' => 'Polycom']);

    expect(BrandSlugResolver::forBrandId(7))->toBe('poly');

    // Mutate the underlying row — cached value should still win
    DB::table('brands')->where('id', 7)->update(['slug' => 'mutated']);

    expect(BrandSlugResolver::forBrandId(7))->toBe('poly');
});
