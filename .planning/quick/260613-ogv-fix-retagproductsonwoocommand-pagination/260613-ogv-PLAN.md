---
phase: quick-260613-ogv
plan: 01
type: execute
wave: 1
depends_on: []
quick_id: 260613-ogv
files_modified:
  - app/Console/Commands/RetagProductsOnWooCommand.php
  - tests/Feature/Console/RetagProductsOnWooCommandTest.php
autonomous: true
requirements:
  - 260613-ogv-pagination-status-fix

must_haves:
  truths:
    - "The per-source products list GET passes status=any so pending/draft products are processed (not silently skipped by Woo's default status=publish)"
    - "The per-source pagination loop ALWAYS queries page=1 in a while-true loop (since the ?brand=N filter set shrinks as products are retagged off the source brand)"
    - "A safety-break at 50 iterations stops the loop with a warn + audit, so a stuck or echoing Woo cannot loop forever"
    - "All 10 existing Pest cases A-J remain GREEN — public command signature, counters, audit namespaces, dry-run, source-ids intersect guard, 200ms throttle, per-product PUT semantics all preserved byte-identically"
    - "Two new Pest cases K (pending product captured under status=any) and L (200-product drain over multiple page=1 calls) GREEN"
    - "DedupeBrandsCommandTest + BrandDuplicateFinderTest (sibling suites) remain GREEN — no service-level surface touched"
  artifacts:
    - path: "app/Console/Commands/RetagProductsOnWooCommand.php"
      provides: "Pagination-correct + status=any-aware retag command"
      contains: "'status' => 'any'"
    - path: "app/Console/Commands/RetagProductsOnWooCommand.php"
      provides: "Always-page-1 while-true loop with $maxPageGuard safety break"
      contains: "while (true)"
    - path: "tests/Feature/Console/RetagProductsOnWooCommandTest.php"
      provides: "12 Pest cases A-L covering the new contract"
      contains: "Case K"
  key_links:
    - from: "RetagProductsOnWooCommand::perform"
      to: "WooClient::get('products', [...'status' => 'any', 'page' => 1])"
      via: "per-source while-true loop"
      pattern: "'status' => 'any'"
    - from: "RetagProductsOnWooCommand::perform"
      to: "brands.retag_safety_break audit row"
      via: "$maxPageGuard counter exceeding 50"
      pattern: "brands\\.retag_safety_break"
---

<objective>
Fix two prod-incident-driven bugs in `app/Console/Commands/RetagProductsOnWooCommand.php` that today required manual tinker rescue passes during the brand-cleanup arc:

1. **status=publish silent skip** — the per-source products list GET defaults to `status=publish`, silently missing pending/draft products (hit today for Barco R9861522EU, Crestron UC-B30-Z-WM, ~50 LG pending, ~10 Neat pending). Add `'status' => 'any'`.

2. **page-increment loses the tail** — the loop incremented `$page` after each retag pass, but the `?brand=N` filter set shrinks under the loop as products are retagged OFF the source brand. Today LG had 148 products on `-brand` canonical; first pass moved 100 (page 1), second pass would have hit page 2 of a now-48-item set and returned empty. Required a manual `while(true) { query page=1 }` rescue script. Replace the loop with always-page-1 + 50-iteration safety break.

Purpose: close two prod-observed silent-loss bugs in the brands-cleanup operator workflow without disturbing the rest of the command's contract (counters, audit namespaces, dry-run, --source-ids intersect guard, 200ms throttle, per-product PUT semantics).

Output: 1 modified command file + 1 modified test file (Case I fixture updated + 2 new cases K, L). Full Pest delta vs 260613-o33 baseline: **+2 pass / 0 new fails**.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md

# Current implementation — the file being modified
@app/Console/Commands/RetagProductsOnWooCommand.php

# Existing tests — Cases A-J must stay GREEN; Case I fixture must drain [source][1]
@tests/Feature/Console/RetagProductsOnWooCommandTest.php

# Sibling command (pattern reference — do NOT modify)
@app/Console/Commands/DedupeBrandsCommand.php

# WooClient — for test stub patterns
@app/Domain/Sync/Services/WooClient.php

# Consumed via DI — for drift-prevention sanity, NOT modified
@app/Domain/Sync/Services/BrandDuplicateFinder.php

<interfaces>
<!-- Key shape contracts the executor needs. -->

Public command signature (DO NOT CHANGE):
  brands:retag-products-on-woo
    {--dry-run}
    {--source-ids=}
    {--limit=0}

Constructor (DO NOT CHANGE):
  __construct(WooClient $woo, Auditor $auditor, BrandDuplicateFinder $finder)

Existing 7 counters (DO NOT CHANGE):
  groups_processed / products_scanned / products_retagged / already_canonical
  / errors / no_products_on_woo / source_not_a_duplicate

Existing 5 audit namespaces (DO NOT CHANGE):
  brands.product_retagged
  brands.retag_failed
  brands.retag_pagination_failed
  brands.retag_no_products_on_woo
  brands.retag_discovery_failed

NEW 6th audit namespace (added by this plan):
  brands.retag_safety_break — payload {source_brand_id, pages}
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Patch RetagProductsOnWooCommand per-source loop (status=any + always-page-1 while-true + safety break)</name>
  <files>app/Console/Commands/RetagProductsOnWooCommand.php</files>
  <behavior>
    Per-source loop now ALWAYS queries page=1 in a while-true loop with status=any:
    - Every iteration calls `$this->woo->get('products', ['brand' => $sourceId, 'per_page' => 100, 'page' => 1, 'status' => 'any'])`
    - Loop exits when response is empty array (filter set drained)
    - Loop also exits when `$pageGuard >= 50` (defensive backstop), emitting `$this->warn` + `brands.retag_safety_break` audit with `{source_brand_id, pages}` payload
    - The previous `for ($page = 1; ...)` page-increment guard and `$page++` semantics are removed
    - Existing 404 detection (`(int) $e->getCode() === 404 || str_contains rest_term_invalid|term does not exist`), errors counter, no_products_on_woo counter, brands.retag_no_products_on_woo + brands.retag_pagination_failed audit rows, per-product PUT logic, already_canonical short-circuit, --dry-run sample buffer, --limit global cap, 200ms `usleep(WOO_PUT_THROTTLE_USEC)` throttle on successful PUTs, --source-ids intersect guard, source_not_a_duplicate counter, per-source breakdown table, final counter table — ALL preserved byte-identically
    - The `if (count($response) < self::PRODUCTS_PER_PAGE) break;` per-page-short heuristic at the bottom of the previous loop is REMOVED — the new contract drains via "response empty" alone, because the filter set shrinks per-iteration
  </behavior>
  <action>
    Modify `app/Console/Commands/RetagProductsOnWooCommand.php` in three localised edits scoped to the per-source loop (lines ~168-363 of the current file). Do NOT touch the constructor, option parsing, BrandDuplicateFinder consumption, --source-ids intersect guard, counter init, final reporting tables, or any code outside the per-source `foreach ($sourceToCanonical as $sourceId => $canonicalId)` body.

    Edit A — replace the `$page = 1; while (true) { ... $page++; }` block (currently roughly lines 184-362 of the file, identified by the `$page = 1;` initialiser through the matching closing `}` of the inner while loop) with a comment-block-prefixed while-true loop that:
      (1) inserts a comment block above the loop body (verbatim):
          // 2026-06-13 INCIDENT (260613-ogv) — pagination must ALWAYS query page=1 because the filter set
          // (`?brand=N`) shrinks under us as products are retagged OFF the source brand. The previous
          // `for ($page = 1; ...)` pattern incremented page-after-retag and silently lost the tail
          // (LG: 148 products → page 1 returned 100, page 2 of the now-48-item set returned empty).
          // Safety break at 50 iterations is a defensive backstop, NOT an expected exit path.
      (2) initialises `$pageGuard = 0;` and `$maxPageGuard = 50;` immediately before `while (true) {`
      (3) inside the loop body, the products GET call becomes:
          $response = $this->woo->get('products', [
              'brand' => $sourceId,
              'per_page' => self::PRODUCTS_PER_PAGE,
              'page' => 1,             // ALWAYS page 1 — the filter set shrinks as we retag products off this brand
              'status' => 'any',       // 2026-06-13 (260613-ogv) — without this WC defaults to status=publish; pending/draft products are silently skipped
          ]);
      (4) keep the existing 404 / non-404 try/catch handling, the existing `if (! is_array($response) || $response === []) break;` empty-response guard, and the entire per-product `foreach ($response as $row)` retag block UNCHANGED (current brand extraction, MINUS/UNION computation, already_canonical short-circuit, --dry-run path, live PUT path with 200ms throttle, --limit global cap, audit rows) — copy these blocks across verbatim
      (5) REMOVE the trailing `if (count($response) < self::PRODUCTS_PER_PAGE) break; $page++;` lines — they are obsolete under the new always-page-1 contract
      (6) at the bottom of the while-true body (after the inner foreach completes for an iteration), add:
          $pageGuard++;
          if ($pageGuard >= $maxPageGuard) {
              $this->warn("  ! safety_break source={$sourceId}: ran {$maxPageGuard} iterations of page=1, aborting source loop");
              $this->auditor->record('brands.retag_safety_break', [
                  'source_brand_id' => $sourceId,
                  'pages' => $pageGuard,
              ]);
              break;
          }

    Edit B — leave the `if ($hitLimit) { break; }` between source iterations untouched (it lives outside the while-true loop body).

    Edit C — no signature, no docblock surgery, no const additions (PRODUCTS_PER_PAGE + WOO_PUT_THROTTLE_USEC unchanged). The `$maxPageGuard = 50` literal lives in the function body as a documented defensive constant — promoting to a class const is OUT OF SCOPE for this quick task.

    Do NOT add a 6th counter to the final summary table. The `brands.retag_safety_break` audit row is the surfaced signal; the counter table preserves its existing 7-row shape for downstream operator-eye continuity.

    Drift-prevention sanity after edit:
      - `grep -c "'page' => 1" app/Console/Commands/RetagProductsOnWooCommand.php` returns ≥ 1
      - `grep -c "'status' => 'any'" app/Console/Commands/RetagProductsOnWooCommand.php` returns ≥ 1
      - `grep -c "while (true)" app/Console/Commands/RetagProductsOnWooCommand.php` returns ≥ 1
      - `grep -c "for (\$page" app/Console/Commands/RetagProductsOnWooCommand.php` returns 0
      - `grep -c "\$page++" app/Console/Commands/RetagProductsOnWooCommand.php` returns 0
      - `grep -c "brands.retag_safety_break" app/Console/Commands/RetagProductsOnWooCommand.php` returns ≥ 1
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php --filter='Case I' 2>&1 | tail -30</automated>
    <automated>php -l app/Console/Commands/RetagProductsOnWooCommand.php</automated>
  </verify>
  <done>
    Command file passes `php -l`. Case I (the only existing test that exercises multi-iteration pagination) still passes — though its fixture is updated in Task 2 to reflect always-page-1 draining semantics, the assertions remain: both pages worth of products retagged, `putCalls` count = 101. All other 9 existing cases unaffected by this task's surface area.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Update Case I fixture + add Pest cases K and L</name>
  <files>tests/Feature/Console/RetagProductsOnWooCommandTest.php</files>
  <behavior>
    Three test-rig changes inside `tests/Feature/Console/RetagProductsOnWooCommandTest.php`:

    (1) **Update Case I fixture** to model always-page-1 draining: the WooClient stub returns the first 100 products on the first `[source=11][page=1]` read, the next 1 product on the second `[source=11][page=1]` read, and `[]` on the third — mirroring how real WC returns a SHRINKING filter set as products lose the source brand. Assertion intent unchanged: 101 PUTs, both batches confirmed hit. The existing `pageNums` assertion (page=1 AND page=2 both present in getCalls) is REPLACED by a stronger assertion: getCalls for `products` with `brand=11` shows all queries used `page=1` AND `status='any'`, and the total count of such GETs is exactly 3 (drain → 100, drain → 1, drain → empty).

    (2) **Add Case K** — pending product captured under status=any: stub returns ALL 3 products (1 publish + 2 pending) when query carries `status=any`; returns just the 1 publish product when query is publish-only. Assert all 3 products receive PUT calls. Confirms the `status=any` change is wired.

    (3) **Add Case L** — pagination tail capture: 200 products across "multiple drains". First page=1 read returns first 100, second page=1 read returns next 100, third returns empty. Assert 200 PUT calls, safety break NOT triggered (no `brands.retag_safety_break` audit row), final counter `products_retagged=200`. Confirms the always-page-1 contract correctly drains a 200-product source without losing the tail.
  </behavior>
  <action>
    Modify `tests/Feature/Console/RetagProductsOnWooCommandTest.php` in three localised edits. Do NOT modify the `bindRetagWooStub` helper signature, the anonymous-subclass WooClient stub shape, or any test Cases A-H or J.

    Edit A — Case I fixture rewrite:
      - Replace the current `productsByBrandByPage[11] = [1 => $page1Products, 2 => [['id' => 60001, ...]]]` fixture with a DYNAMIC stub that drains. The cleanest path is to use a `drainQueue` pattern instead of a static map. Modify ONLY the `bindRetagWooStub(...)` call inside Case I — keep the helper function untouched — by passing a drain array via a NEW stub property. Concretely, the simplest implementation that keeps the helper signature stable: post-bind, mutate `$stub->productsByBrandByPage[11]` via a setter or direct property assignment between iterations is impossible (we can't intercept). Instead, override the stub's `get()` behaviour for this test by adding a small `productsByBrandPageQueue` queue mechanism — see fallback approach below.

      **Use this approach (no helper-function touch):** replace the current static fixture with a queue-aware stub override INSIDE the test. The test re-binds a one-off anonymous subclass extending WooClient (mirrors the helper pattern but inlines the queue behaviour for Case I). Pattern:

        $page1Drain = [];
        $page1Drain[] = [/* 100 products */];
        $page1Drain[] = [/* 1 product */];
        $page1Drain[] = []; // final drain signals empty

        $stub = new class([
            'brandsByPage' => [
                1 => [
                    ['id' => 10, 'name' => 'Poly', 'count' => 500],
                    ['id' => 11, 'name' => 'poly', 'count' => 101],
                ],
            ],
            'productsBrand11Queue' => $page1Drain,
        ]) extends WooClient {
            public array $getCalls = [];
            public array $putCalls = [];
            public array $brandsByPage;
            public array $productsBrand11Queue;
            public function __construct(array $config) {
                $this->brandsByPage = $config['brandsByPage'];
                $this->productsBrand11Queue = $config['productsBrand11Queue'];
            }
            public function get(string $endpoint, array $query = []): array {
                $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];
                if ($endpoint === 'products/brands') {
                    return $this->brandsByPage[(int) ($query['page'] ?? 1)] ?? [];
                }
                if ($endpoint === 'products' && (int) ($query['brand'] ?? 0) === 11) {
                    return array_shift($this->productsBrand11Queue) ?? [];
                }
                return [];
            }
            public function put(string $endpoint, array $payload): array {
                $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];
                return ['id' => 0];
            }
        };
        app()->instance(WooClient::class, $stub);

      Assertions for Case I after the rewrite:
        expect($stub->putCalls)->toHaveCount(101);
        $brand11Gets = array_filter($stub->getCalls, fn($c) => $c['endpoint'] === 'products' && ($c['query']['brand'] ?? null) === 11);
        expect(count($brand11Gets))->toBe(3); // drain → 100, drain → 1, drain → empty
        foreach ($brand11Gets as $call) {
            expect($call['query']['page'] ?? null)->toBe(1);    // ALWAYS page 1
            expect($call['query']['status'] ?? null)->toBe('any');
        }

    Edit B — append Case K (status=any captures pending products) at the bottom of the test file, before the closing `bindRetagWooStub` helper function:

      it('Case K: status=any captures pending+draft products that publish-only would skip', function (): void {
          // Stub returns all 3 products under status=any; returns just the 1 publish row under any other status.
          $stub = new class([
              'brandsByPage' => [
                  1 => [
                      ['id' => 20, 'name' => 'Crestron', 'count' => 80],
                      ['id' => 21, 'name' => 'crestron', 'count' => 3],
                  ],
              ],
              'allProducts' => [
                  ['id' => 7001, 'sku' => 'K-publish',  'status' => 'publish', 'brands' => [['id' => 21]]],
                  ['id' => 7002, 'sku' => 'K-pending1', 'status' => 'pending', 'brands' => [['id' => 21]]],
                  ['id' => 7003, 'sku' => 'K-pending2', 'status' => 'pending', 'brands' => [['id' => 21]]],
              ],
          ]) extends WooClient {
              public array $getCalls = [];
              public array $putCalls = [];
              public array $brandsByPage;
              public array $allProducts;
              public int $drainCalls = 0;
              public function __construct(array $config) {
                  $this->brandsByPage = $config['brandsByPage'];
                  $this->allProducts = $config['allProducts'];
              }
              public function get(string $endpoint, array $query = []): array {
                  $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];
                  if ($endpoint === 'products/brands') {
                      return $this->brandsByPage[(int) ($query['page'] ?? 1)] ?? [];
                  }
                  if ($endpoint === 'products' && (int) ($query['brand'] ?? 0) === 21) {
                      $this->drainCalls++;
                      if ($this->drainCalls > 1) return []; // drain after first call
                      // Under status=any → all 3; otherwise → just the publish row.
                      if (($query['status'] ?? null) === 'any') return $this->allProducts;
                      return array_filter($this->allProducts, fn($p) => ($p['status'] ?? null) === 'publish');
                  }
                  return [];
              }
              public function put(string $endpoint, array $payload): array {
                  $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];
                  return ['id' => 0];
              }
          };
          app()->instance(WooClient::class, $stub);

          $exit = Artisan::call('brands:retag-products-on-woo');

          expect($exit)->toBe(0);
          expect($stub->putCalls)->toHaveCount(3); // all 3 — including the 2 pending — retagged
          expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(3);

          // Sanity: confirm at least one GET against brand=21 carried status=any
          $brand21Gets = array_filter($stub->getCalls, fn($c) => $c['endpoint'] === 'products' && ($c['query']['brand'] ?? null) === 21);
          $statuses = array_map(fn($c) => $c['query']['status'] ?? null, $brand21Gets);
          expect($statuses)->toContain('any');
      });

    Edit C — append Case L (drain tail capture across 2 batches of 100) immediately after Case K:

      it('Case L: 200-product source drains across multiple page=1 reads, safety break NOT hit', function (): void {
          // First page=1 read → 100 products, second → next 100, third → empty.
          $batch1 = [];
          for ($i = 1; $i <= 100; $i++) $batch1[] = ['id' => 80000 + $i, 'sku' => "L-{$i}", 'brands' => [['id' => 31]]];
          $batch2 = [];
          for ($i = 101; $i <= 200; $i++) $batch2[] = ['id' => 80000 + $i, 'sku' => "L-{$i}", 'brands' => [['id' => 31]]];

          $stub = new class([
              'brandsByPage' => [
                  1 => [
                      ['id' => 30, 'name' => 'LG', 'count' => 800],
                      ['id' => 31, 'name' => 'lg', 'count' => 200],
                  ],
              ],
              'drainQueue' => [$batch1, $batch2, []],
          ]) extends WooClient {
              public array $getCalls = [];
              public array $putCalls = [];
              public array $brandsByPage;
              public array $drainQueue;
              public function __construct(array $config) {
                  $this->brandsByPage = $config['brandsByPage'];
                  $this->drainQueue = $config['drainQueue'];
              }
              public function get(string $endpoint, array $query = []): array {
                  $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];
                  if ($endpoint === 'products/brands') {
                      return $this->brandsByPage[(int) ($query['page'] ?? 1)] ?? [];
                  }
                  if ($endpoint === 'products' && (int) ($query['brand'] ?? 0) === 31) {
                      return array_shift($this->drainQueue) ?? [];
                  }
                  return [];
              }
              public function put(string $endpoint, array $payload): array {
                  $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];
                  return ['id' => 0];
              }
          };
          app()->instance(WooClient::class, $stub);

          $exit = Artisan::call('brands:retag-products-on-woo');

          expect($exit)->toBe(0);
          expect($stub->putCalls)->toHaveCount(200);

          // No safety break fired — 200 / 100 = 2 drain iterations, well under the 50-iteration backstop.
          expect(Activity::query()->where('description', 'brands.retag_safety_break')->count())->toBe(0);
          expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(200);
      });

    Drift-prevention sanity after edit:
      - `grep -c "it('Case K:" tests/Feature/Console/RetagProductsOnWooCommandTest.php` returns 1
      - `grep -c "it('Case L:" tests/Feature/Console/RetagProductsOnWooCommandTest.php` returns 1
      - The original `bindRetagWooStub` helper at the bottom of the file is unchanged (sanity check that A, B, C, D, E, F, G, H, J test bodies were not disturbed).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php 2>&1 | tail -30</automated>
  </verify>
  <done>
    All 12 Pest cases (A-L) GREEN. Case I uses the new drain-queue stub. Cases K and L are appended at the bottom of the file. The `bindRetagWooStub` helper function and Cases A, B, C, D, E, F, G, H, J test bodies are untouched.
  </done>
</task>

<task type="auto">
  <name>Task 3: Regression sweep — sibling suites + full Pest delta</name>
  <files>(no files modified — verification only)</files>
  <action>
    Run sibling and overlapping suites to confirm no behavioural drift outside the targeted surface. Treat any new failure as a Rule-1 fold-back into Tasks 1-2 — do NOT mask it.

    Required green:
      1. `vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php` — must show 12 passed (10 original A-J + 2 new K, L). Case I now uses the drain-queue stub.
      2. `vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php` — must show 10 passed (sibling command, untouched by this plan; sanity check that BrandDuplicateFinder DI surface still resolves cleanly).
      3. `vendor/bin/pest tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php` — must show 7 passed (260613-o33 baseline). If this file does not exist, skip this assertion with a note in the SUMMARY.

    Then a focused Pest sweep across the brand-cleanup arc to confirm +2/0 delta:

      `vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php tests/Feature/Console/DedupeBrandsCommandTest.php tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php`

    Expected delta vs 260613-o33 baseline: **+2 pass / 0 new fails** (the two new K, L cases; nothing else moves).

    Full-suite Pest (`vendor/bin/pest`) is OOM-deferred per the recurring infrastructure note in STATE.md (Windows herd PHP plateaus at 512MB) — DO NOT force it. The focused sweep above is the in-scope proof.

    If `vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php` fails on a case OTHER than K or L:
      - Read the failure output, identify the regressed case
      - Fix in Task 1 or Task 2 (whichever surface the regression points to)
      - Re-run; do not proceed until all 12 are GREEN

    Operator dry-run after deploy (NOT executed by this task — documented for SUMMARY):
      `php artisan brands:retag-products-on-woo --dry-run --source-ids=2904`
      Expected: a plan listing BOTH publish + pending LG products under the new status=any contract.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php tests/Feature/Console/DedupeBrandsCommandTest.php 2>&1 | tail -15</automated>
  </verify>
  <done>
    Focused sweep is GREEN (12 + 10 = 22 cases passing). BrandDuplicateFinderTest is GREEN (7 cases) if the file exists. Full Pest delta vs 260613-o33 baseline confirmed at +2 pass / 0 new fails on the focused/touched-area sweep. Full-suite OOM caveat carried forward to the SUMMARY.
  </done>
</task>

</tasks>

<verification>
  Phase-level checks:
    - 12 Pest cases A-L all GREEN in tests/Feature/Console/RetagProductsOnWooCommandTest.php (10 originals A-J retained, K + L added).
    - DedupeBrandsCommandTest 10/10 GREEN (sibling untouched).
    - BrandDuplicateFinderTest 7/7 GREEN if the file exists (sibling untouched).
    - `php -l app/Console/Commands/RetagProductsOnWooCommand.php` clean.
    - grep guards on the command file:
      - `'page' => 1` present (≥1 occurrence)
      - `'status' => 'any'` present (≥1 occurrence)
      - `while (true)` present (≥1 occurrence)
      - `for ($page` absent (0 occurrences)
      - `$page++` absent (0 occurrences)
      - `brands.retag_safety_break` present (≥1 occurrence)
</verification>

<success_criteria>
  - All 6 must_haves.truths observable from the test suite output + command source diff
  - Per-source loop ALWAYS queries page=1 with status=any (provable by Case I + Case K + Case L assertions)
  - Safety break audit row exists and is wired (provable by Case L NOT firing it; future operator runs will surface it if a Woo regression introduces a stuck loop)
  - Zero regressions in DedupeBrandsCommandTest or BrandDuplicateFinderTest (proves the BrandDuplicateFinder DI surface + sibling command contract untouched)
  - Full Pest delta confirmed at +2 pass / 0 new fails on focused/touched-area sweep
  - Operator dry-run `php artisan brands:retag-products-on-woo --dry-run --source-ids=2904` after deploy returns a plan that includes BOTH publish + pending products (documented in SUMMARY for post-deploy operator action)
</success_criteria>

<output>
  Create `.planning/quick/260613-ogv-fix-retagproductsonwoocommand-pagination/260613-ogv-SUMMARY.md` when done, covering:
    - Files modified (the 2 listed)
    - Pest delta (+2/0)
    - Operator action: post-deploy dry-run + live retag-products-on-woo run to drain any current Barco / Crestron / LG / Neat pending stragglers
    - Drift-prevention sanity: grep confirmations on the 6 patterns listed under verification
    - Carry-forward: the `$maxPageGuard = 50` literal is a defensive backstop, NOT an expected exit path; if it ever fires in prod, the audit row surfaces it as `brands.retag_safety_break` and the operator should investigate (most likely Woo cache echo or a `brand=N` filter that isn't actually narrowing — neither of which is a normal state).
</output>
