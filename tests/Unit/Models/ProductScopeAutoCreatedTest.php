<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260606-o63 — Product::scopeAutoCreated unit test
|--------------------------------------------------------------------------
|
| Pins the `!= 'manual'` semantic of the canonical "auto-created products
| only" predicate. The column `auto_create_status` is NOT NULL DEFAULT
| 'manual' per migration 2026_04_22_100300_add_auto_create_columns_to_
| products_table, so a `whereNotNull` filter is vacuous — see quick
| 260606-mx9 for the bug uncovering and this quick (260606-o63) for the
| fix.
|
| Three assertions:
|   1. Three-product happy path: A=manual EXCLUDED, B=draft + C=published
|      INCLUDED.
|   2. Chainability: `->autoCreated()->where('id', $B->id)` still returns
|      a Builder, proving the scope is NOT a terminal method.
|   3. All-enum coverage: one product per enum value (8 total); the scope
|      returns exactly 7 (every status EXCEPT 'manual'). Locks the
|      semantic against accidental inclusion / exclusion drift.
|
| Driver-agnostic — pure column-equality predicate; runs identically on
| SQLite in-memory (Pest default) and production MySQL.
*/

use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function skipIfMySqlOfflineO63(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

it('autoCreated() returns rows with auto_create_status != manual (excludes legacy WC products)', function (): void {
    skipIfMySqlOfflineO63();

    $a = Product::factory()->create([
        'sku' => 'O63-A-MANUAL',
        'auto_create_status' => 'manual',
    ]);
    $b = Product::factory()->create([
        'sku' => 'O63-B-DRAFT',
        'auto_create_status' => 'draft',
    ]);
    $c = Product::factory()->create([
        'sku' => 'O63-C-PUBLISHED',
        'auto_create_status' => 'published',
    ]);

    $skus = Product::query()
        ->autoCreated()
        ->orderBy('id')
        ->pluck('sku')
        ->all();

    expect($skus)->toBe([$b->sku, $c->sku]);
    expect($skus)->not->toContain($a->sku);
});

it('autoCreated() is chainable — returns a Builder, not a Collection', function (): void {
    skipIfMySqlOfflineO63();

    Product::factory()->create([
        'sku' => 'O63-CHAIN-A-MANUAL',
        'auto_create_status' => 'manual',
    ]);
    $b = Product::factory()->create([
        'sku' => 'O63-CHAIN-B-DRAFT',
        'auto_create_status' => 'draft',
    ]);
    Product::factory()->create([
        'sku' => 'O63-CHAIN-C-PUBLISHED',
        'auto_create_status' => 'published',
    ]);

    // Chained where after autoCreated proves the scope returns a Builder
    // (a terminal method like get() would throw "Call to undefined method
    // ::where()" here).
    $skus = Product::query()
        ->autoCreated()
        ->where('id', $b->id)
        ->pluck('sku')
        ->all();

    expect($skus)->toBe([$b->sku]);
});

it('autoCreated() correctly classifies all 8 enum values (only manual excluded)', function (): void {
    skipIfMySqlOfflineO63();

    $statuses = [
        'manual',
        'draft',
        'pending_review',
        'approved',
        'published',
        'rejected',
        'needs_brand_or_category_assignment',
        'variations_not_supported_v1',
    ];

    foreach ($statuses as $status) {
        Product::factory()->create([
            'sku' => 'O63-ENUM-'.$status,
            'auto_create_status' => $status,
        ]);
    }

    $count = Product::query()->autoCreated()->count();

    // 8 statuses seeded, 'manual' excluded → 7 included.
    expect($count)->toBe(7);

    // Defensive: 'manual' MUST NOT appear in the included set.
    $includedStatuses = Product::query()
        ->autoCreated()
        ->pluck('auto_create_status')
        ->map(fn ($s) => (string) $s)
        ->all();
    expect($includedStatuses)->not->toContain('manual');
    // Every non-manual status MUST appear exactly once.
    foreach ($statuses as $status) {
        if ($status === 'manual') {
            continue;
        }
        expect($includedStatuses)->toContain($status);
    }
});
