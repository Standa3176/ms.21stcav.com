<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 04 Task 1 — SeoContentPatchApplier (per-field write-through)
|--------------------------------------------------------------------------
|
| Five fixtures pin the contract:
|
|   1. Applies a single 'title' patch → Product.name updated (NOT title);
|      ProductOverride.pin_title=true; audit row written with before_hash +
|      after_hash (sha256, NOT verbatim text).
|   2. SUBSET approval — 2 of 4 patches with applied_at → Suggestion.status
|      stays STATUS_PENDING; the 2 unapplied patches still visible in payload.
|   3. FULL approval — all 4 patches applied_at → Suggestion.status flips to
|      STATUS_APPLIED.
|   4. ProductOverride upsert preserves other pin flags (pin_image=true set
|      before applier runs; applier only touches pin_title, leaves pin_image=true).
|   5. Source contains DB::transaction (transactional integrity).
*/

use App\Domain\Agents\Appliers\SeoContentPatchApplier;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function makeSeoPatchSuggestion(int $productId, array $patches): Suggestion
{
    return Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [
            'product_id' => $productId,
            'sku' => 'LOGI-MEETUP',
            'patches' => $patches,
            'agent_run_id' => (string) Str::ulid(),
        ],
        'evidence' => ['agent_kind' => 'seo'],
        'proposed_at' => now(),
    ]);
}

it('applies a single approved title patch → Product.name updated + pin_title=true + audit hashes', function () {
    $product = Product::factory()->create([
        'sku' => 'LOGI-' . Str::random(4),
        'name' => 'Old name',
        'short_description' => 'unchanged',
        'long_description' => 'unchanged',
        'meta_description' => 'unchanged',
    ]);

    $suggestion = makeSeoPatchSuggestion($product->id, [
        [
            'field' => 'title',
            'before' => 'Old name',
            'after' => 'New, better name',
            'reasoning' => 'short reasoning',
            'applied_at' => now()->toIso8601String(),
        ],
    ]);

    $result = app(SeoContentPatchApplier::class)->apply($suggestion);

    $product->refresh();
    expect($product->name)->toBe('New, better name');

    $override = ProductOverride::where('product_id', $product->id)->first();
    expect($override)->not->toBeNull();
    expect((bool) $override->pin_title)->toBeTrue();

    // Audit row exists with sha256 hashes (NOT verbatim values)
    $audit = DB::table('activity_log')
        ->where('description', 'seo.content_patch_applied')
        ->orderByDesc('id')
        ->first();
    expect($audit)->not->toBeNull();
    $props = (array) json_decode((string) $audit->properties, true);
    expect((string) data_get($props, 'field'))->toBe('title');
    expect((string) data_get($props, 'before_hash'))->toBe(hash('sha256', 'Old name'));
    expect((string) data_get($props, 'after_hash'))->toBe(hash('sha256', 'New, better name'));

    // Result array shape (SuggestionApplier contract returns array)
    expect($result)->toBeArray();
    expect((bool) ($result['applied'] ?? false))->toBeTrue();

    // Status flips to APPLIED (all patches were approved)
    $suggestion->refresh();
    expect($suggestion->status)->toBe(Suggestion::STATUS_APPLIED);
});

it('SUBSET approval — Suggestion.status stays PENDING when only some patches applied', function () {
    $product = Product::factory()->create([
        'sku' => 'LOGI-' . Str::random(4),
        'name' => 'Old name',
        'short_description' => 'Old short',
        'long_description' => 'Old long',
        'meta_description' => 'Old meta',
    ]);

    $now = now()->toIso8601String();
    $suggestion = makeSeoPatchSuggestion($product->id, [
        ['field' => 'title', 'before' => 'Old name', 'after' => 'New name', 'reasoning' => 'r', 'applied_at' => $now],
        ['field' => 'short_description', 'before' => 'Old short', 'after' => 'New short', 'reasoning' => 'r', 'applied_at' => $now],
        ['field' => 'long_description', 'before' => 'Old long', 'after' => 'New long', 'reasoning' => 'r', 'applied_at' => null],
        ['field' => 'meta_description', 'before' => 'Old meta', 'after' => 'New meta', 'reasoning' => 'r', 'applied_at' => null],
    ]);

    app(SeoContentPatchApplier::class)->apply($suggestion);

    $product->refresh();
    expect($product->name)->toBe('New name');
    expect($product->short_description)->toBe('New short');
    expect($product->long_description)->toBe('Old long'); // not approved
    expect($product->meta_description)->toBe('Old meta'); // not approved

    $suggestion->refresh();
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);

    // Override pin reflects partial approval
    $override = ProductOverride::where('product_id', $product->id)->first();
    expect((bool) $override->pin_title)->toBeTrue();
    expect((bool) $override->pin_short_description)->toBeTrue();
    expect((bool) $override->pin_long_description)->toBeFalse();
    expect((bool) $override->pin_meta_description)->toBeFalse();
});

it('FULL approval — Suggestion.status flips to APPLIED when all patches approved', function () {
    $product = Product::factory()->create([
        'sku' => 'LOGI-' . Str::random(4),
        'name' => 'Old name',
        'short_description' => 'Old short',
        'long_description' => 'Old long',
        'meta_description' => 'Old meta',
    ]);

    $now = now()->toIso8601String();
    $suggestion = makeSeoPatchSuggestion($product->id, [
        ['field' => 'title', 'before' => 'Old name', 'after' => 'New name', 'reasoning' => 'r', 'applied_at' => $now],
        ['field' => 'short_description', 'before' => 'Old short', 'after' => 'New short', 'reasoning' => 'r', 'applied_at' => $now],
        ['field' => 'long_description', 'before' => 'Old long', 'after' => 'New long', 'reasoning' => 'r', 'applied_at' => $now],
        ['field' => 'meta_description', 'before' => 'Old meta', 'after' => 'New meta', 'reasoning' => 'r', 'applied_at' => $now],
    ]);

    app(SeoContentPatchApplier::class)->apply($suggestion);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(Suggestion::STATUS_APPLIED);
});

it('ProductOverride upsert preserves OTHER pin flags', function () {
    $product = Product::factory()->create([
        'sku' => 'LOGI-' . Str::random(4),
        'name' => 'Old name',
    ]);
    // Pre-existing override row with pin_image=true (unrelated to SEO)
    ProductOverride::create([
        'product_id' => $product->id,
        'margin_basis_points' => 2500,
        'reason' => 'manual override',
        'pin_image' => true,
    ]);

    $suggestion = makeSeoPatchSuggestion($product->id, [
        ['field' => 'title', 'before' => 'Old name', 'after' => 'New name', 'reasoning' => 'r', 'applied_at' => now()->toIso8601String()],
    ]);

    app(SeoContentPatchApplier::class)->apply($suggestion);

    $override = ProductOverride::where('product_id', $product->id)->first();
    expect((bool) $override->pin_title)->toBeTrue();
    expect((bool) $override->pin_image)->toBeTrue(); // preserved
    expect((int) $override->margin_basis_points)->toBe(2500); // preserved
});

it('source file contains DB::transaction wrapper (transactional integrity guard)', function () {
    $path = base_path('app/Domain/Agents/Appliers/SeoContentPatchApplier.php');
    expect(is_file($path))->toBeTrue();
    expect(file_get_contents($path))->toContain('DB::transaction');
});
