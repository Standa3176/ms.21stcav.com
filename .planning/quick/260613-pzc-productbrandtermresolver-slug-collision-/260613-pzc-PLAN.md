---
phase: 260613-pzc-productbrandtermresolver-slug-collision-
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - config/services.php
  - app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php
  - tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php
autonomous: true
requirements:
  - QUICK-260613-pzc
must_haves:
  truths:
    - "When auto-creating a new product_brand term, the resolver checks WP's product_tag taxonomy for a slug collision BEFORE falling back to the `-brand` suffix."
    - "Default strategy `skip-creation` logs a warning and returns null on collision (NEVER creates the suffixed duplicate that caused the 11 prod pairs)."
    - "Opt-in strategy `auto-delete-empty-colliding-tag` deletes the empty colliding tag via the 260613-plo POST-tunnelled DELETE and retries the clean slug."
    - "Emergency escape hatch `force-suffix` preserves the OLD behaviour (logged as a warning) for rare cases where the operator explicitly wants the suffix."
    - "Defensive: a WP-REST error during the pre-flight check returns null (NOT an exception) so brand creation gracefully falls back to legacy 2-attempt behaviour rather than blocking forever."
    - "Public API (getTermIdForName, assignToProduct, flushCache, cache semantics) is byte-identical to today — PublishProductJob and other callers see no behavioural drift on the happy path."
  artifacts:
    - path: "config/services.php"
      provides: "New `services.woo.brand_slug_collision_strategy` config key (default `skip-creation`)"
      contains: "brand_slug_collision_strategy"
    - path: "app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php"
      provides: "Pre-flight collision check + strategy-driven createTerm() orchestration"
      contains: "checkProductTagCollision"
    - path: "tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php"
      provides: "10 Pest cases A-J covering every documented strategy branch + sanity guards"
      min_lines: 300
  key_links:
    - from: "ProductBrandTermResolver::createTerm()"
      to: "config('services.woo.brand_slug_collision_strategy')"
      via: "config() call inside createTerm()"
      pattern: "brand_slug_collision_strategy"
    - from: "ProductBrandTermResolver::checkProductTagCollision()"
      to: "WpRestClient::get('wp/v2/product_tag', ['slug' => ...])"
      via: "GET probe before -brand suffix fallback"
      pattern: "wp/v2/product_tag"
    - from: "auto-delete-empty-colliding-tag branch"
      to: "WpRestClient::delete('wp/v2/product_tag/{id}?force=true')"
      via: "the WAF-tunnelled DELETE path from 260613-plo"
      pattern: "wp/v2/product_tag/"
---

<objective>
Fix the root cause of the 11 duplicate `{brand}` + `{brand}-brand` product_brand pairs discovered on 2026-06-13.

ProductBrandTermResolver::createTerm() currently does a blind 2-attempt fallback: try primary slug → on ANY failure, try `{slug}-brand`. That fallback silently produced 11 duplicates over time whenever a `product_tag` with the same slug already existed AND someone (operator or another tool) later created the clean-slug brand.

Replace the blind fallback with a config-driven, pre-flight-checked strategy:

1. Default `skip-creation` — pre-flight finds the colliding product_tag, logs warning, returns null (no -brand suffix term created → no duplicate pair possible).
2. Opt-in `auto-delete-empty-colliding-tag` — if the colliding tag has zero products attached, delete it (via 260613-plo's WAF-tunnelled DELETE), then retry the clean slug.
3. Emergency `force-suffix` — preserves the OLD behaviour as an explicit escape hatch (with a warning that surfaces the risk).

Purpose: structurally prevent the brand-duplicate-pair problem we just hand-cleaned. Sibling of 260613-plo (which fixed the DELETE 403 that blocked the cleanup script).

Output: config key, refactored resolver, full Pest coverage. Operator post-deploy step: flush the term-map cache; monitor logs for `product_brand.tag_slug_collision` warnings on first auto-create batch.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260613-pzc-productbrandtermresolver-slug-collision-/
@CLAUDE.md
@app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php
@app/Domain/Sync/Services/WpRestClient.php
@config/services.php
@tests/Feature/WooClientDeleteTest.php

<interfaces>
<!-- Key contracts the executor needs. Extracted from the codebase. -->
<!-- Executor should use these directly — no exploration needed. -->

From app/Domain/Sync/Services/WpRestClient.php:
```php
final class WpRestClient
{
    public function get(string $path, array $query = []): array;
    public function post(string $path, array $body): array;
    public function put(string $path, array $body): array;
    public function delete(string $path): bool;  // returns true on 2xx, throws \RuntimeException on failure
}
```

Failure semantics: every non-2xx response throws `\RuntimeException` with message `"WP REST {method} {path} failed (HTTP {status}): {body_preview}"`. The body preview includes WordPress's JSON error code (e.g. `term_exists`) so callers can string-match if needed, but the canonical signal is the exception itself.

From app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php (current shape — PRESERVE this public surface):
```php
class ProductBrandTermResolver
{
    public function __construct(private readonly WpRestClient $wp) {}
    public function getTermIdForName(?string $brandName): ?int;
    public function assignToProduct(int $wooProductId, array $termIds): bool;
    public function flushCache(): void;

    private function getCachedMap(): array;  // unchanged
    private function createTerm(string $brandName): ?int;  // ORCHESTRATION CHANGES INSIDE; signature unchanged
    private function tryCreate(string $name, string $slug): ?int;  // unchanged
    private function normaliseName(string $name): string;  // unchanged
}
```

The CACHE_KEY `product_brand.term_map` and CACHE_TTL_SECONDS `3600` constants are unchanged.

From config/services.php — sibling pattern for the new key:
```php
'woo' => [
    // 260613-plo
    'use_post_for_updates' => env('WOO_USE_POST_FOR_UPDATES', true),
    'use_post_for_deletes' => env('WOO_USE_POST_FOR_DELETES', true),
    // 260613-pzc — NEW (adjacent to the above):
    'brand_slug_collision_strategy' => env('WOO_BRAND_SLUG_COLLISION_STRATEGY', 'skip-creation'),
],
```

Allowed values for `brand_slug_collision_strategy`: `'skip-creation'` (default, safe), `'auto-delete-empty-colliding-tag'` (aggressive, opt-in), `'force-suffix'` (deprecated escape hatch).

From tests/Feature/WooClientDeleteTest.php — STUB PATTERN to mirror (Mockery on the inner SDK was correct there; for THIS task we want a different stub style: anonymous-subclass over WpRestClient with public capture vars, because WpRestClient is `final` so Mockery cannot mock it. Reference the WooClientDeleteTest only for the Pest case-naming convention and the config()->set() pattern at the top of each test.):
```php
beforeEach(function (): void {
    // Reset to default strategy for each test; individual cases override.
    config()->set('services.woo.brand_slug_collision_strategy', 'skip-creation');
    Log::spy();  // for assertions on warning channel
});
```

Note on WpRestClient stubbing: WpRestClient is declared `final` (line 29). Use an anonymous CLASS that EXTENDS a non-final test seam, OR (preferred) bind a hand-rolled stub via `app()->instance(WpRestClient::class, $stub)`. Since WpRestClient is final, the cleanest path is to create the stub via Mockery's `Mockery::mock(WpRestClient::class)->makePartial()` — Mockery handles finals via runtime extension when `mockery/mockery` is in dev deps (it is, per CLAUDE.md stack). If a Mockery final-class issue surfaces, fall back to building a small test-only subclass by removing `final` from WpRestClient (LAST RESORT — prefer Mockery).

Cache reset between tests: call `Cache::flush()` in `beforeEach()` OR call `$resolver->flushCache()` after construction. The cache key `product_brand.term_map` persists across the test runner otherwise.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add config key + write the 10 Pest cases (failing)</name>
  <files>
    config/services.php,
    tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php
  </files>
  <behavior>
    Pest cases A-J (every case asserts on observable WP-REST calls + log warnings + return values):

    - (A) No collision, primary slug succeeds → ONE post to `wp/v2/product_brand` with slug `yealink`; returns the new term id (e.g. 4242); cache contains `['yealink' => 4242]`.
    - (B) Collision detected, default strategy `skip-creation` → ONE failed POST attempt at primary; pre-flight GET `wp/v2/product_tag?slug=yealink` returns `[{id:9001,count:5}]`; warning logged with channel `product_brand.tag_slug_collision` AND payload containing brand+tag_id; returns null; assert ZERO `-brand`-suffix POSTs.
    - (C) Collision, strategy `auto-delete-empty-colliding-tag`, tag has products attached (count=5) → falls back to skip-creation behaviour; warning logged with reason `"tag not empty"`; returns null; assert ZERO delete calls AND ZERO -brand-suffix POSTs.
    - (D) Collision, strategy `auto-delete-empty-colliding-tag`, tag IS empty (count=0) → DELETE `wp/v2/product_tag/9001?force=true` called exactly once; THEN retry primary slug POST succeeds; returns the new term id; assert exactly ONE delete + TWO POSTs (initial fail + retry-after-delete).
    - (E) Collision, strategy `force-suffix` → primary fails → pre-flight is SKIPPED (strategy bypasses it) → `-brand` suffix POST called and succeeds; returns the suffixed term id; warning logged `"force-suffix strategy in use — risk of duplicate brand pair"`.
    - (F) Pre-flight WP-REST throws → resolver swallows exception, returns null from checkProductTagCollision, then falls back to OLD 2-attempt behaviour (primary then `-brand`). Sanity guard: transient WP error during pre-flight MUST NOT block all brand creation forever.
    - (G) Cache write on success — after Case A succeeds, calling `getTermIdForName('Yealink')` again returns 4242 from cache (zero additional WP-REST calls).
    - (H) Empty/null/whitespace brand names — `getTermIdForName(null)`, `getTermIdForName('')`, `getTermIdForName('   ')` all return null and make ZERO WP-REST calls.
    - (I) Case-insensitivity preserved — `getTermIdForName('YEALINK')` and `getTermIdForName('yealink')` resolve to the same cached id (one POST total).
    - (J) Integration sanity for assignToProduct — `assignToProduct(123, [42])` POSTs to `wp/v2/product/123` with body `['product_brand' => [42]]`. Baseline regression guard — not modified by this task.
  </behavior>
  <action>
    Step 1 — Add the new config key to `config/services.php`. Insert it INSIDE the existing `'woo' => [...]` block, adjacent to `'use_post_for_deletes'` (260613-plo's last key). Use this exact shape:

    ```
    'brand_slug_collision_strategy' => env('WOO_BRAND_SLUG_COLLISION_STRATEGY', 'skip-creation'),
    ```

    Above it, add a comment block (matching the existing 260613-plo voice — incident-anchored, multi-line, references the memory note) explaining: the 11-duplicate incident, the three allowed values (skip-creation / auto-delete-empty-colliding-tag / force-suffix), the cross-reference to 260613-plo's DELETE-tunnel (which enables the auto-delete branch), and an explicit DEPRECATION note on `force-suffix` ("retained as emergency escape hatch ONLY — risk of duplicate brand pair").

    Step 2 — CREATE the test file at `tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php`. Mirror the file-header banner style from WooClientDeleteTest (260613-plo) — a top-level comment block enumerating cases A-J with one-line summaries, and a "Boundary strategy" note documenting the stub approach.

    Stub strategy: WpRestClient is `final` — use `Mockery::mock(WpRestClient::class)` (Mockery handles finals via its mockery overload mechanism; if that proves flaky in this codebase's PHPUnit config, fall back to wrapping the real client in a test-only anonymous class that records calls into public capture arrays and is bound via `app()->instance(WpRestClient::class, $stub)`). Reference WooClientDeleteTest's Mockery-on-AutomatticClient pattern for case structure.

    In `beforeEach`:
    - `Cache::flush()` (the term map cache persists otherwise);
    - `Log::spy()` (for warning assertions);
    - `config()->set('services.woo.brand_slug_collision_strategy', 'skip-creation')` (explicit default reset).

    For each case A-J, build the stub mock with the expected sequence of WP-REST calls (POST/GET/DELETE), construct the resolver via `new ProductBrandTermResolver($stub)`, invoke `getTermIdForName('Yealink')` (or the appropriate variant), and assert on (a) return value, (b) WP-REST call sequence, (c) log warnings via `Log::shouldHaveReceived('warning')->with(...)`. For Case D, the second POST attempt must be a fresh expectation (Mockery `->ordered()` chain, or two separate `shouldReceive('post')->once()` calls with different `->with()` matchers).

    Run the test file — expect 10 cases to FAIL (resolver doesn't have the new behaviour yet). Commit this state. This is the RED step.

    Do NOT touch the resolver in this task. The intentional RED state proves the tests actually exercise the new behaviour rather than passing by accident.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php 2>&1 | tail -30</automated>
    Expected: tests file is parsed (no PHP fatal); 10 cases collected; cases A, G, H, I, J may already pass (current resolver already handles them correctly); cases B, C, D, E, F MUST fail with assertion errors (the new branches don't exist yet). If ALL 10 pass, the stubs aren't tight enough — Case B/C/D/E expectations on the GET pre-flight call should fail against the unmodified resolver.

    Also verify the config key:
    <automated>php artisan config:clear && php artisan tinker --execute="echo config('services.woo.brand_slug_collision_strategy');"</automated>
    Expected output: `skip-creation`
  </verify>
  <done>
    - `config/services.php` has the new `brand_slug_collision_strategy` key with a 260613-pzc-attributed comment block referencing 260613-plo and the duplicate-pair incident.
    - `tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php` exists with 10 cases A-J in the documented order.
    - Cases B, C, D, E (and F's specific log-channel assertion) FAIL against the unmodified resolver.
    - `php artisan tinker` confirms the default config value is `'skip-creation'`.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Refactor ProductBrandTermResolver — pre-flight + strategy branching</name>
  <files>
    app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php
  </files>
  <behavior>
    All 10 Pest cases from Task 1 PASS. The public API (getTermIdForName, assignToProduct, flushCache, cache semantics, normaliseName) is byte-identical. The only INTERNAL changes:

    - New private method `checkProductTagCollision(string $slug): ?array` — GET `wp/v2/product_tag?slug={slug}`, return `['id' => N, 'count' => M]` on collision OR `null` (no collision OR transient WP-REST error during probe).
    - `createTerm(string $brandName): ?int` rewired to: (1) try primary slug; (2) on failure, branch by `config('services.woo.brand_slug_collision_strategy')`; (3) execute the appropriate branch (skip-creation / auto-delete-empty-colliding-tag / force-suffix); (4) final fallback returns null with a warning.
    - `tryCreate()` is unchanged.

    Public observable behaviour matches Task 1's case definitions exactly.
  </behavior>
  <action>
    Step 1 — Open `app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php`. Replace the body of `createTerm()` (lines 173-201) with the strategy-driven orchestration described in the spec. KEEP `tryCreate()` (lines 203-232) unchanged. KEEP `normaliseName()` unchanged. KEEP `getCachedMap()` unchanged. KEEP all public methods unchanged.

    New `createTerm()` structure (incident-anchored comment block + orchestration):

    ```
    /**
     * Create a product_brand term with pre-flight slug-collision detection.
     *
     * 2026-06-13 INCIDENT — the old -brand suffix fallback (without pre-flight)
     * silently created 11 duplicate brand pairs on prod ({brand} + {brand}-brand).
     * Root cause: tryCreate(primary) fails because WP refuses cross-taxonomy slug
     * collisions; the unconditional retry with `{slug}-brand` succeeded → operator
     * (or later code path) created the clean-slug brand → duplicate pair. See
     * memory `meetingstore-brand-cleanup-followups` for the cleanup arc.
     *
     * Strategy (config: services.woo.brand_slug_collision_strategy):
     *   - 'skip-creation'                  (default, safe)  — log + return null
     *   - 'auto-delete-empty-colliding-tag' (aggressive)    — delete empty tag, retry
     *   - 'force-suffix'                   (DEPRECATED)     — old behaviour, last resort
     */
    private function createTerm(string $brandName): ?int
    {
        $primarySlug = Str::slug($brandName);

        // Attempt 1: clean slug (always tried first regardless of strategy)
        $id = $this->tryCreate($brandName, $primarySlug);
        if ($id !== null) {
            return $id;
        }

        $strategy = (string) config('services.woo.brand_slug_collision_strategy', 'skip-creation');

        // force-suffix branch: bypass pre-flight, preserve OLD behaviour with warning.
        if ($strategy === 'force-suffix') {
            Log::warning('product_brand.force_suffix_strategy_in_use', [
                'brand' => $brandName,
                'risk' => 'duplicate brand pair if clean-slug term created later',
            ]);
            $id = $this->tryCreate($brandName, $primarySlug.'-brand');
            if ($id !== null) {
                return $id;
            }
            Log::warning('product_brand.create_failed_force_suffix', [
                'brand' => $brandName,
                'tried_slugs' => [$primarySlug, $primarySlug.'-brand'],
            ]);
            return null;
        }

        // skip-creation + auto-delete-empty-colliding-tag share the pre-flight.
        $collision = $this->checkProductTagCollision($primarySlug);

        if ($collision === null) {
            // No collision detected (OR pre-flight errored — defensive). Fall back
            // to the OLD 2-attempt pattern: something OTHER than slug collision
            // caused the primary failure (5xx, auth blip). The -brand suffix is
            // a reasonable last resort here because there's no colliding tag
            // identified, so we can't create the duplicate-pair pathology.
            $id = $this->tryCreate($brandName, $primarySlug.'-brand');
            if ($id !== null) {
                return $id;
            }
            Log::warning('product_brand.create_failed_all_slugs', [
                'brand' => $brandName,
                'tried_slugs' => [$primarySlug, $primarySlug.'-brand'],
            ]);
            return null;
        }

        // Collision detected.
        if ($strategy === 'auto-delete-empty-colliding-tag' && ($collision['count'] ?? 1) === 0) {
            try {
                $this->wp->delete('wp/v2/product_tag/'.$collision['id'].'?force=true');
                Log::info('product_brand.auto_deleted_empty_colliding_tag', [
                    'brand' => $brandName,
                    'tag_id' => $collision['id'],
                ]);

                // Retry primary slug — now unblocked.
                $id = $this->tryCreate($brandName, $primarySlug);
                if ($id !== null) {
                    return $id;
                }
            } catch (\Throwable $e) {
                Log::warning('product_brand.auto_delete_failed', [
                    'brand' => $brandName,
                    'tag_id' => $collision['id'],
                    'error' => $e->getMessage(),
                ]);
                // fall through to skip-creation log + null return.
            }
        }

        // skip-creation (default) OR auto-delete-empty-colliding-tag with non-empty tag
        // OR auto-delete failure path.
        Log::warning('product_brand.tag_slug_collision', [
            'brand' => $brandName,
            'colliding_tag_id' => $collision['id'],
            'colliding_tag_count' => $collision['count'] ?? null,
            'strategy' => $strategy,
            'reason' => ($strategy === 'auto-delete-empty-colliding-tag' && ($collision['count'] ?? 1) > 0)
                ? 'tag not empty'
                : 'strategy=skip-creation',
            'operator_action' => 'Delete the empty colliding product_tag in wp-admin, OR set strategy=auto-delete-empty-colliding-tag',
        ]);

        return null;
    }
    ```

    Then add the new private method below `createTerm()`:

    ```
    /**
     * Pre-flight check: does a product_tag with this slug already exist?
     * Returns ['id' => N, 'count' => M] on collision, null otherwise (no collision
     * OR transient WP-REST error — defensive null so brand creation doesn't block
     * forever on a probe blip).
     *
     * @return array{id:int,count:int}|null
     */
    private function checkProductTagCollision(string $slug): ?array
    {
        try {
            $result = $this->wp->get('wp/v2/product_tag', ['slug' => $slug]);
            if (! is_array($result) || $result === []) {
                return null;
            }
            $first = $result[0] ?? null;
            if (! is_array($first)) {
                return null;
            }
            $id = $first['id'] ?? null;
            $count = $first['count'] ?? 0;
            if (! is_numeric($id) || (int) $id <= 0) {
                return null;
            }
            return [
                'id' => (int) $id,
                'count' => (int) $count,
            ];
        } catch (\Throwable $e) {
            Log::warning('product_brand.tag_collision_probe_failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    ```

    Step 2 — Update the class-level docblock (lines 12-37). The current docblock describes the OLD `-brand` suffix fallback as the canonical behaviour. Replace with a description of the strategy-driven pre-flight pattern, referencing the 2026-06-13 incident, and reframe the `-brand` suffix as a NON-DEFAULT escape hatch.

    Step 3 — Run the test file. All 10 cases MUST pass.

    Step 4 — Run regression checks per the spec.

    DRIFT check: ensure NO additional `-brand` suffix string appears in this file outside of `createTerm()`'s force-suffix branch + the no-collision-detected fall-through. The string `.'-brand'` should appear in exactly TWO places (the two intentional fallbacks). The docblock can mention `-brand` in prose freely.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php 2>&1 | tail -15</automated>
    Expected: 10/10 GREEN.

    <automated>vendor/bin/pest tests/Feature/WooClientDeleteTest.php 2>&1 | tail -10</automated>
    Expected: 8/8 GREEN (sibling from 260613-plo, must be unchanged).

    <automated>vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php tests/Feature/Console/RetagProductsOnWooCommandTest.php tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php 2>&1 | tail -15</automated>
    Expected: 29/29 GREEN (10 + 12 + 7 = the brand-cleanup regression suite).

    <automated>vendor/bin/pest tests/Feature/ProductAutoCreate/ 2>&1 | tail -10</automated>
    Expected: full ProductAutoCreate suite GREEN (sanity for PublishProductJob + other consumers of the resolver).

    DRIFT guard — count the `.'-brand'` literal in the resolver file (should be exactly 2 — force-suffix branch + no-collision-fallback branch):
    <automated>grep -c "\.'-brand'" app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php</automated>
    Expected: 2
  </verify>
  <done>
    - All 10 Pest cases (A-J) GREEN.
    - WooClientDeleteTest.php still 8/8 GREEN (no regression to 260613-plo).
    - DedupeBrandsCommandTest, RetagProductsOnWooCommandTest, BrandDuplicateFinderTest unchanged.
    - Full ProductAutoCreate Feature suite GREEN (no regression to PublishProductJob or sibling consumers).
    - `.'-brand'` literal appears in the resolver in exactly 2 places (both intentional, both inside `createTerm()`).
    - Class docblock updated to describe the new strategy-driven pre-flight pattern with 2026-06-13 incident reference.
  </done>
</task>

</tasks>

<verification>
End-to-end after both tasks:

1. Focused Pest: `vendor/bin/pest tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php` → 10/10 GREEN
2. Sibling regression (260613-plo): `vendor/bin/pest tests/Feature/WooClientDeleteTest.php` → 8/8 GREEN
3. Brand cleanup regression: `vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php tests/Feature/Console/RetagProductsOnWooCommandTest.php tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php` → 29/29 GREEN
4. Full ProductAutoCreate suite: `vendor/bin/pest tests/Feature/ProductAutoCreate/` → GREEN (consumer-side sanity)
5. Full Pest delta vs 260613-plo baseline: +10 pass / 0 new fails
6. Config sanity: `php artisan tinker --execute="echo config('services.woo.brand_slug_collision_strategy');"` → `skip-creation`
7. DRIFT guard: `grep -c "\.'-brand'" app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php` → 2

Operator notes (for SUMMARY.md, NOT executed by Claude):
- After deploy: flush cache via `php artisan tinker --execute="app(\App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver::class)->flushCache();"`
- Monitor `storage/logs/laravel.log` for `product_brand.tag_slug_collision` warnings on the first batch — these surface tag/brand pairs requiring operator attention.
- Optional: once 260613-plo's DELETE tunnel is confirmed stable in prod, set `WOO_BRAND_SLUG_COLLISION_STRATEGY=auto-delete-empty-colliding-tag` in `.env` for hands-off cleanup of empty colliding tags.
</verification>

<success_criteria>
- 10/10 new Pest cases GREEN
- Zero regressions in 260613-plo sibling tests, brand-cleanup test suite, or wider ProductAutoCreate suite
- Config key default is `skip-creation` (safe — NEVER creates `-brand` suffix duplicates by default)
- Resolver class docblock + createTerm() docblock both reference the 2026-06-13 incident
- `force-suffix` strategy retained but marked DEPRECATED in code comments
- Auto-delete branch uses 260613-plo's WAF-tunnelled DELETE (via WpRestClient::delete which delegates appropriately)
- Public API (getTermIdForName, assignToProduct, flushCache) byte-identical → PublishProductJob and other call sites need no changes
</success_criteria>

<output>
Create `.planning/quick/260613-pzc-productbrandtermresolver-slug-collision-/260613-pzc-SUMMARY.md` when done, documenting:
- Before/after of createTerm() orchestration
- The three strategies + when each fires
- Why pre-flight on `wp/v2/product_tag?slug=` was chosen over parsing the `term_exists` error code from the failed POST (the error-parsing path is fragile across WP versions; an explicit probe is observable + testable)
- The 11 duplicate pairs cleaned by the brand-cleanup arc (link to the memory note `meetingstore-brand-cleanup-followups`)
- Operator post-deploy steps (cache flush + log monitoring + optional strategy flip)
- Sibling reference to 260613-plo whose DELETE tunnel enables the auto-delete branch
</output>
