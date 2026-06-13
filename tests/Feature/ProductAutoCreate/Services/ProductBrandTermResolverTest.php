<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\Sync\Services\WpRestClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Quick task 260613-pzc — ProductBrandTermResolver slug-collision pre-flight
|--------------------------------------------------------------------------
|
| Covers the three config-driven strategies for handling cross-taxonomy
| slug collisions when WP refuses to create a `product_brand` term whose
| slug already exists in `product_tag`:
|
|   - skip-creation                  (default, safe)        Cases B, C
|   - auto-delete-empty-colliding-tag (aggressive, opt-in)  Cases C, D
|   - force-suffix                   (DEPRECATED escape)    Case E
|
| Plus sanity guards covering happy-path (A), defensive fall-through on
| probe failure (F), cache semantics (G), null/empty inputs (H),
| case-insensitive cache (I), and assignToProduct regression guard (J).
|
| BOUNDARY STRATEGY — WpRestClient is declared `final`, so we stub at the
| HTTP layer using Laravel's `Http::fake()` (matches EanSearchClientTest
| pattern in this codebase). The real WpRestClient is constructed with
| baseUrl=`https://meetingstore.test/wp-json`; assertions ride on
| `Http::assertSent` / `Http::assertNotSent` checking the actual URL +
| method the resolver issues. This avoids final-class mocking pain
| entirely.
|
| The 11 duplicate product_brand pairs cleaned manually on 2026-06-13
| (memory note `meetingstore-brand-cleanup-followups`) are the root-cause
| trigger for this whole task — every assertion below maps directly to
| "would this resolver state ship that bug again?".
*/

const WP_BASE = 'https://meetingstore.test/wp-json';

beforeEach(function (): void {
    // Reset config + cache + log spy for every case. Individual cases
    // override strategy as needed via config()->set().
    config()->set('services.woo.brand_slug_collision_strategy', 'skip-creation');
    Cache::flush();
    Log::spy();
});

/**
 * Build a real WpRestClient against our fake base URL. The HTTP layer
 * is intercepted by Http::fake() in each test.
 */
function makeResolver(): ProductBrandTermResolver
{
    return new ProductBrandTermResolver(new WpRestClient(WP_BASE, null, null));
}

/**
 * Build the JSON wp/v2/product_brand list response so the resolver's
 * cached-map fetch (`getCachedMap`) returns an empty list — that forces
 * the createTerm() code path for every test that doesn't pre-seed.
 */
function emptyBrandListResponse(): array
{
    return [];
}

/* ───────────────────────────────────────────────────────────────────────
 | Case A — No collision, primary slug succeeds. Happy path baseline.
 ──────────────────────────────────────────────────────────────────────── */
it('Case A: no collision, primary slug succeeds — single POST, returns new id', function (): void {
    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::response([
            'id' => 4242,
            'slug' => 'yealink',
            'name' => 'Yealink',
            'taxonomy' => 'product_brand',
        ], 201),
    ]);

    $id = makeResolver()->getTermIdForName('Yealink');

    expect($id)->toBe(4242);

    // ZERO -brand suffix POST attempts.
    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains((string) $request->body(), '"slug":"yealink-brand"');
    });
});

/* ───────────────────────────────────────────────────────────────────────
 | Case B — Collision, default strategy skip-creation.
 ──────────────────────────────────────────────────────────────────────── */
it('Case B: collision + skip-creation strategy — pre-flight GET fires, returns null, ZERO -brand POST', function (): void {
    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        // Primary POST refused by WP because slug collides cross-taxonomy.
        WP_BASE.'/wp/v2/product_brand' => Http::sequence()
            ->push(['code' => 'term_exists', 'message' => 'A term with the name provided already exists with this slug.'], 400),
        // Pre-flight probe finds the colliding product_tag.
        WP_BASE.'/wp/v2/product_tag?slug=yealink' => Http::response([
            ['id' => 9001, 'name' => 'Yealink', 'slug' => 'yealink', 'count' => 5, 'taxonomy' => 'product_tag'],
        ], 200),
    ]);

    $id = makeResolver()->getTermIdForName('Yealink');

    expect($id)->toBeNull();

    // The warning channel must surface the colliding tag id + brand name.
    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $channel, array $ctx = []): bool {
            return $channel === 'product_brand.tag_slug_collision'
                && ($ctx['brand'] ?? null) === 'Yealink'
                && (int) ($ctx['colliding_tag_id'] ?? 0) === 9001;
        })
        ->atLeast()->once();

    // Pre-flight GET fired.
    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/wp/v2/product_tag') && str_contains($r->url(), 'slug=yealink'));

    // ZERO -brand suffix POST.
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains((string) $r->body(), '"slug":"yealink-brand"'));

    // ZERO DELETE call.
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
});

/* ───────────────────────────────────────────────────────────────────────
 | Case C — Collision, auto-delete strategy, but tag has products attached.
 ──────────────────────────────────────────────────────────────────────── */
it('Case C: collision + auto-delete + tag NOT empty (count=5) → falls back to skip-creation; ZERO DELETE, ZERO -brand POST', function (): void {
    config()->set('services.woo.brand_slug_collision_strategy', 'auto-delete-empty-colliding-tag');

    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::sequence()
            ->push(['code' => 'term_exists'], 400),
        WP_BASE.'/wp/v2/product_tag?slug=yealink' => Http::response([
            ['id' => 9001, 'name' => 'Yealink', 'slug' => 'yealink', 'count' => 5, 'taxonomy' => 'product_tag'],
        ], 200),
    ]);

    $id = makeResolver()->getTermIdForName('Yealink');

    expect($id)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $channel, array $ctx = []): bool {
            return $channel === 'product_brand.tag_slug_collision'
                && ($ctx['reason'] ?? null) === 'tag not empty';
        })
        ->atLeast()->once();

    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains((string) $r->body(), '"slug":"yealink-brand"'));
});

/* ───────────────────────────────────────────────────────────────────────
 | Case D — Collision, auto-delete, empty tag → DELETE + retry primary.
 ──────────────────────────────────────────────────────────────────────── */
it('Case D: collision + auto-delete + tag IS empty (count=0) → DELETE fires once, primary slug retried, returns new id', function (): void {
    config()->set('services.woo.brand_slug_collision_strategy', 'auto-delete-empty-colliding-tag');

    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::sequence()
            ->push(['code' => 'term_exists'], 400)  // first attempt fails
            ->push(['id' => 4242, 'slug' => 'yealink', 'name' => 'Yealink', 'taxonomy' => 'product_brand'], 201),  // retry succeeds
        WP_BASE.'/wp/v2/product_tag?slug=yealink' => Http::response([
            ['id' => 9001, 'name' => 'Yealink', 'slug' => 'yealink', 'count' => 0, 'taxonomy' => 'product_tag'],
        ], 200),
        WP_BASE.'/wp/v2/product_tag/9001*' => Http::response([], 200),
    ]);

    $id = makeResolver()->getTermIdForName('Yealink');

    expect($id)->toBe(4242);

    // DELETE fired exactly once on /wp/v2/product_tag/9001.
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/wp/v2/product_tag/9001'));

    // Two POSTs to product_brand (initial fail + retry).
    $brandPosts = 0;
    Http::recorded(function ($request) use (&$brandPosts) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/wp/v2/product_brand')) {
            $brandPosts++;
        }
    });
    expect($brandPosts)->toBe(2);

    // No -brand suffix attempted.
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains((string) $r->body(), '"slug":"yealink-brand"'));
});

/* ───────────────────────────────────────────────────────────────────────
 | Case E — Strategy force-suffix → bypass pre-flight, -brand POST runs.
 ──────────────────────────────────────────────────────────────────────── */
it('Case E: strategy force-suffix → ZERO pre-flight GET, -brand suffix POST succeeds, warning logged', function (): void {
    config()->set('services.woo.brand_slug_collision_strategy', 'force-suffix');

    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::sequence()
            ->push(['code' => 'term_exists'], 400)
            ->push(['id' => 5151, 'slug' => 'yealink-brand', 'name' => 'Yealink', 'taxonomy' => 'product_brand'], 201),
    ]);

    $id = makeResolver()->getTermIdForName('Yealink');

    expect($id)->toBe(5151);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $channel, array $ctx = []): bool {
            return $channel === 'product_brand.force_suffix_strategy_in_use'
                && ($ctx['brand'] ?? null) === 'Yealink';
        })
        ->atLeast()->once();

    // Pre-flight GET MUST be skipped by force-suffix branch.
    Http::assertNotSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/wp/v2/product_tag') && str_contains($r->url(), 'slug=yealink'));
});

/* ───────────────────────────────────────────────────────────────────────
 | Case F — Pre-flight WP-REST throws → resolver swallows, returns null.
 ──────────────────────────────────────────────────────────────────────── */
it('Case F: pre-flight WP-REST 500 → checkProductTagCollision returns null, defensive fallback to -brand suffix retry', function (): void {
    // Strategy stays at skip-creation (default).
    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::sequence()
            ->push(['code' => 'term_exists'], 400)  // primary clean fails
            ->push(['id' => 5252, 'slug' => 'yealink-brand', 'name' => 'Yealink', 'taxonomy' => 'product_brand'], 201),  // -brand fallback succeeds
        WP_BASE.'/wp/v2/product_tag?slug=yealink' => Http::response(['error' => 'wp upstream blip'], 500),
    ]);

    $id = makeResolver()->getTermIdForName('Yealink');

    // Defensive: when probe fails, we cannot identify the colliding tag — the
    // duplicate-pair pathology requires KNOWING about the colliding tag, so
    // a -brand-suffix retry here is safer than blocking brand creation forever.
    expect($id)->toBe(5252);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $channel, array $ctx = []): bool {
            return $channel === 'product_brand.tag_collision_probe_failed'
                && ($ctx['slug'] ?? null) === 'yealink';
        })
        ->atLeast()->once();
});

/* ───────────────────────────────────────────────────────────────────────
 | Case G — Cache write on success. Second lookup hits cache, zero HTTP.
 ──────────────────────────────────────────────────────────────────────── */
it('Case G: cache write on success — second lookup hits cache, ZERO additional WP-REST calls', function (): void {
    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::response([
            'id' => 4242,
            'slug' => 'yealink',
            'name' => 'Yealink',
            'taxonomy' => 'product_brand',
        ], 201),
    ]);

    $resolver = makeResolver();
    $first = $resolver->getTermIdForName('Yealink');
    expect($first)->toBe(4242);

    // Count requests so we can assert the second lookup adds none.
    $countBefore = 0;
    Http::recorded(function () use (&$countBefore) {
        $countBefore++;
    });

    $second = $resolver->getTermIdForName('Yealink');
    expect($second)->toBe(4242);

    $countAfter = 0;
    Http::recorded(function () use (&$countAfter) {
        $countAfter++;
    });

    expect($countAfter)->toBe($countBefore);
});

/* ───────────────────────────────────────────────────────────────────────
 | Case H — null / empty / whitespace → null, zero HTTP.
 ──────────────────────────────────────────────────────────────────────── */
it('Case H: null/empty/whitespace brand name → null, ZERO WP-REST calls', function (): void {
    Http::fake();  // any HTTP would assertSent-fail.

    $resolver = makeResolver();
    expect($resolver->getTermIdForName(null))->toBeNull();
    expect($resolver->getTermIdForName(''))->toBeNull();
    expect($resolver->getTermIdForName('   '))->toBeNull();

    Http::assertNothingSent();
});

/* ───────────────────────────────────────────────────────────────────────
 | Case I — Case-insensitive cache: 'YEALINK' and 'yealink' share id.
 ──────────────────────────────────────────────────────────────────────── */
it('Case I: case-insensitive cache — YEALINK and yealink resolve to same id, ONE POST total', function (): void {
    Http::fake([
        WP_BASE.'/wp/v2/product_brand?per_page=100&page=1' => Http::response(emptyBrandListResponse(), 200),
        WP_BASE.'/wp/v2/product_brand' => Http::response([
            'id' => 4242,
            'slug' => 'yealink',
            'name' => 'Yealink',
            'taxonomy' => 'product_brand',
        ], 201),
    ]);

    $resolver = makeResolver();
    expect($resolver->getTermIdForName('YEALINK'))->toBe(4242);
    expect($resolver->getTermIdForName('yealink'))->toBe(4242);
    expect($resolver->getTermIdForName('  Yealink  '))->toBe(4242);

    // Exactly ONE POST to wp/v2/product_brand (the create — subsequent
    // lookups hit the cache).
    $brandPosts = 0;
    Http::recorded(function ($r) use (&$brandPosts) {
        if ($r->method() === 'POST' && str_ends_with($r->url(), '/wp/v2/product_brand')) {
            $brandPosts++;
        }
    });
    expect($brandPosts)->toBe(1);
});

/* ───────────────────────────────────────────────────────────────────────
 | Case J — Integration regression guard for assignToProduct.
 ──────────────────────────────────────────────────────────────────────── */
it('Case J: assignToProduct posts product_brand:[termId] to wp/v2/product/{id}', function (): void {
    Http::fake([
        WP_BASE.'/wp/v2/product/123' => Http::response(['id' => 123], 200),
    ]);

    $ok = makeResolver()->assignToProduct(123, [42]);

    expect($ok)->toBeTrue();

    Http::assertSent(function ($r): bool {
        return $r->method() === 'POST'
            && str_ends_with($r->url(), '/wp/v2/product/123')
            && str_contains((string) $r->body(), '"product_brand"')
            && str_contains((string) $r->body(), '42');
    });
});
