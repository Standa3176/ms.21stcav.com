<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\DivergenceScanner;
use App\Domain\Cutover\Services\OverridePopulator;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Models\SyncDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 05 Task 1 — cutover:populate-overrides (CUT-02)
|--------------------------------------------------------------------------
|
| Covers behaviour tests P1..P6 from 07-05-PLAN:
|   P1 — dry-run does NOT create overrides
|   P2 — --live creates override rows with pins set
|   P3 — merge-never-clear-pins (D-15) — existing pin_title=true stays true
|   P4 — merge adds NEW pin to existing override without clearing old pins
|   P5 — writes audit_log entry for each merge
|   P6 — idempotent (running --live twice doesn't double-create)
*/

/** Seed sync_diffs rows for a single divergence-scan run, sharing one correlation_id. */
function seedDivergenceRows(int $productId, string $sku, array $fieldToPin): string
{
    $correlation = (string) Str::uuid();
    foreach ($fieldToPin as $field => $pinColumn) {
        SyncDiff::create([
            'provider' => DivergenceScanner::PROVIDER,
            'channel' => 'woo',
            'method' => 'GET',
            'endpoint' => 'products?sku='.$sku,
            'woo_id' => null,
            'payload' => [
                'product_id' => $productId,
                'sku' => $sku,
                'field' => $field,
                'laravel' => 'laravel-value',
                'live' => 'live-value',
                'pin_column' => $pinColumn,
            ],
            'correlation_id' => $correlation,
            'created_at' => now(),
            'status' => 'pending',
        ]);
    }

    return $correlation;
}

it('dry-run does NOT create ProductOverride rows', function (): void {
    $p = Product::factory()->create(['sku' => 'DRY-1']);
    seedDivergenceRows($p->id, 'DRY-1', ['name' => 'pin_title']);

    $exit = Artisan::call('cutover:populate-overrides'); // no --live

    expect($exit)->toBe(0);
    expect(ProductOverride::count())->toBe(0);
});

it('--live creates override rows with pin_* flags set', function (): void {
    $p = Product::factory()->create(['sku' => 'LIVE-1']);
    seedDivergenceRows($p->id, 'LIVE-1', [
        'name' => 'pin_title',
        'long_description' => 'pin_long_description',
        'image_url' => 'pin_image',
    ]);

    Artisan::call('cutover:populate-overrides', ['--live' => true]);

    $override = ProductOverride::where('product_id', $p->id)->first();
    expect($override)->not->toBeNull();
    expect((bool) $override->pin_title)->toBeTrue();
    expect((bool) $override->pin_long_description)->toBeTrue();
    expect((bool) $override->pin_image)->toBeTrue();
    // Untouched pins stay false.
    expect((bool) $override->pin_slug)->toBeFalse();
    expect((bool) $override->pin_short_description)->toBeFalse();
});

it('merge semantics NEVER clear an existing pin (D-15)', function (): void {
    // CRITICAL REGRESSION TEST P3: existing pin_title=true must survive a
    // scan that finds NO title divergence.
    $p = Product::factory()->create(['sku' => 'KEEP-1']);
    ProductOverride::create([
        'product_id' => $p->id,
        'margin_basis_points' => 0, // NOT NULL, no DB default — pins-only convention
        'pin_title' => true, // pre-existing ops-set pin
    ]);

    // Scan only finds description divergence — NO divergence on title.
    seedDivergenceRows($p->id, 'KEEP-1', ['long_description' => 'pin_long_description']);

    Artisan::call('cutover:populate-overrides', ['--live' => true]);

    $override = ProductOverride::where('product_id', $p->id)->first();
    expect((bool) $override->pin_title)->toBeTrue(); // ← NEVER cleared
    expect((bool) $override->pin_long_description)->toBeTrue(); // ← newly added
});

it('merge adds new pins to an existing override without clearing old ones', function (): void {
    $p = Product::factory()->create(['sku' => 'ADD-1']);
    ProductOverride::create([
        'product_id' => $p->id,
        'margin_basis_points' => 0, // NOT NULL, no DB default — pins-only convention
        'pin_title' => true,
        'pin_slug' => true,
    ]);

    seedDivergenceRows($p->id, 'ADD-1', [
        'long_description' => 'pin_long_description',
        'image_url' => 'pin_image',
    ]);

    Artisan::call('cutover:populate-overrides', ['--live' => true]);

    $override = ProductOverride::where('product_id', $p->id)->first();
    expect((bool) $override->pin_title)->toBeTrue();
    expect((bool) $override->pin_slug)->toBeTrue();
    expect((bool) $override->pin_long_description)->toBeTrue();
    expect((bool) $override->pin_image)->toBeTrue();
});

it('writes audit_log entries under the cutover-populate-overrides-command actor', function (): void {
    $p = Product::factory()->create(['sku' => 'AUD-1']);
    seedDivergenceRows($p->id, 'AUD-1', ['name' => 'pin_title']);

    Artisan::call('cutover:populate-overrides', ['--live' => true]);

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.override_created')
        ->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['actor'] ?? null)->toBe(OverridePopulator::ACTOR);
    expect($activity->properties['product_id'] ?? null)->toBe($p->id);
});

it('is idempotent when the same scan is replayed through --live twice', function (): void {
    $p = Product::factory()->create(['sku' => 'IDEM-1']);
    seedDivergenceRows($p->id, 'IDEM-1', ['name' => 'pin_title']);

    Artisan::call('cutover:populate-overrides', ['--live' => true]);
    Artisan::call('cutover:populate-overrides', ['--live' => true]);

    // Second run should not create a duplicate override and should not flip
    // any additional pins (nothing new to flip — pin_title already true).
    expect(ProductOverride::count())->toBe(1);

    $mergeActivity = \Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.override_merged')
        ->count();
    // No merge audit entries expected — the only row was created on run 1;
    // run 2 saw pin_title already true and made NO changes (never-clear).
    expect($mergeActivity)->toBe(0);
});

it('registers cutover:populate-overrides in the artisan registry', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cutover:populate-overrides');
});
