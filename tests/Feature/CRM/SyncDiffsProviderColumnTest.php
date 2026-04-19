<?php

declare(strict_types=1);

use App\Domain\Sync\Models\SyncDiff;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 01 Task 2 — sync_diffs.provider column
|--------------------------------------------------------------------------
|
| RESEARCH §Shadow-Mode Gate Option A reuses the Phase 1 sync_diffs table as
| the destination for Bitrix shadow writes — `provider='bitrix'` rows become
| the Phase 7 divergence-scan input. These tests lock down:
|   1. The column defaults to 'woo' (so existing Phase 1/2 rows still work).
|   2. 'bitrix' is a valid value (shadow-mode writes insert successfully).
|   3. An index exists on `provider` (Phase 7 scan filters will be fast).
*/

it('defaults provider to woo for existing-shape inserts', function (): void {
    $id = DB::table('sync_diffs')->insertGetId([
        'channel' => 'woo',
        'method' => 'PUT',
        'endpoint' => '/wp-json/wc/v3/products/42',
        'woo_id' => '42',
        'payload' => json_encode(['price' => 10_00]),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'created_at' => now(),
        'status' => 'pending',
    ]);

    $row = SyncDiff::findOrFail($id);
    expect($row->provider)->toBe('woo');
});

it('accepts provider=bitrix for shadow-mode writes', function (): void {
    $id = DB::table('sync_diffs')->insertGetId([
        'provider' => 'bitrix',
        'channel' => 'bitrix',
        'method' => 'POST',
        'endpoint' => 'crm.deal.add',
        'woo_id' => '77',
        'payload' => json_encode(['TITLE' => 'Woo Order #77']),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'created_at' => now(),
        'status' => 'pending',
    ]);

    $row = SyncDiff::findOrFail($id);
    expect($row->provider)->toBe('bitrix');
});

it('indexes the provider column for Phase 7 divergence scans', function (): void {
    $indexes = DB::select('SHOW INDEX FROM sync_diffs WHERE Column_name = ?', ['provider']);
    expect(count($indexes))->toBeGreaterThanOrEqual(1);
});
