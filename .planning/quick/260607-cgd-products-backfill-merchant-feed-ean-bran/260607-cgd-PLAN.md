---
quick_id: 260607-cgd
type: quick
mode: quick
title: products:backfill-merchant-feed — backfill EAN/brand/category from supplier_db to lift 89% Google Merchant Center disapproval rate
files_modified:
  - app/Console/Concerns/NormalisesEan.php
  - app/Console/Commands/GenerateProductDraftsCommand.php
  - app/Console/Commands/BackfillMerchantFeedCommand.php
  - app/Providers/AppServiceProvider.php
  - tests/Unit/Console/Concerns/NormalisesEanTest.php
  - tests/Feature/Console/BackfillMerchantFeedCommandTest.php
autonomous: true

must_haves:
  truths:
    - "`App\\Console\\Concerns\\NormalisesEan` trait exists; `GenerateProductDraftsCommand` consumes it (no duplicate normaliser logic)."
    - "`php artisan products:backfill-merchant-feed --field=ean --dry-run` reports per-field counts + 20-row sample + writes ZERO rows."
    - "Live `--field=ean` writes only validated EANs to `products.ean` for `status='publish'` rows; idempotent re-run reports 0 candidates."
    - "Live `--field=brand` writes `products.brand_id` only when supplier manufacturer fuzzy-matches a Woo brand term at threshold ≥ 0.85."
    - "Live `--field=category` chains `products:assign-taxonomy` in 50-SKU batches and shows the Claude cost estimate banner (~1p/SKU) before spending."
    - "`--resync` chains `products:resync-to-woo --skus=<only-updated-SKUs>` (NEVER the whole candidate set)."
    - "Command auto-discovered + registered via `AppServiceProvider::$commands` (visible in `php artisan list`)."
    - "EnvUsageTest + AutoCreatedPredicateTest stay GREEN; no `env()` in the new command or trait."
  artifacts:
    - path: app/Console/Concerns/NormalisesEan.php
      provides: Shared EAN normaliser trait (byte-identical to GenerateProductDraftsCommand:479-491)
      contains: "public function normaliseEan"
    - path: app/Console/Commands/BackfillMerchantFeedCommand.php
      provides: "products:backfill-merchant-feed" artisan
      contains: "class BackfillMerchantFeedCommand"
    - path: tests/Unit/Console/Concerns/NormalisesEanTest.php
      provides: Trait coverage — real EAN, N/A, all-0/9 sentinels, dashed-13, too-short/too-long, empty/null
    - path: tests/Feature/Console/BackfillMerchantFeedCommandTest.php
      provides: Dry-run prints + zero writes; live updates only validated rows; fuzzy-below-threshold skipped
  key_links:
    - from: app/Console/Commands/BackfillMerchantFeedCommand.php
      to: app/Console/Concerns/NormalisesEan.php
      via: "use NormalisesEan;"
      pattern: "use NormalisesEan"
    - from: app/Console/Commands/GenerateProductDraftsCommand.php
      to: app/Console/Concerns/NormalisesEan.php
      via: "use NormalisesEan; (private normaliseEan method removed)"
      pattern: "use NormalisesEan"
    - from: app/Console/Commands/BackfillMerchantFeedCommand.php
      to: app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php
      via: "constructor-injected TaxonomyResolver → resolveBrand() + allBrands()"
      pattern: "TaxonomyResolver"
    - from: app/Console/Commands/BackfillMerchantFeedCommand.php
      to: app/Domain/Integrations/Services/IntegrationCredentialResolver.php
      via: "resolver->for(IntegrationCredentialKind::SupplierDb) → mysqli to feeds_products"
      pattern: "IntegrationCredentialKind::SupplierDb"
    - from: app/Console/Commands/BackfillMerchantFeedCommand.php
      to: app/Console/Commands/AssignProductTaxonomyCommand.php
      via: "Artisan::call('products:assign-taxonomy', ['--skus' => ...]) in 50-SKU batches"
      pattern: "products:assign-taxonomy"
    - from: app/Console/Commands/BackfillMerchantFeedCommand.php
      to: app/Console/Commands/ResyncProductsToWooCommand.php
      via: "Artisan::call('products:resync-to-woo', ['--skus' => $updatedCsv])"
      pattern: "products:resync-to-woo"
    - from: app/Providers/AppServiceProvider.php
      to: app/Console/Commands/BackfillMerchantFeedCommand.php
      via: "->commands([... BackfillMerchantFeedCommand::class ...])"
      pattern: "BackfillMerchantFeedCommand::class"
---

<objective>
Ship `products:backfill-merchant-feed` to recover the 89% of live products currently auto-disapproved by Google Merchant Center (3,493 TRIPLE FAIL rows: no EAN + no brand_id + no category_id). The command pulls EAN + manufacturer from `supplier_db.feeds_products` (94% recoverable per today's diagnostic), fuzzy-matches manufacturer against the Woo brand taxonomy via `TaxonomyResolver`, and chains Claude category assignment via the existing `products:assign-taxonomy` command. Default behaviour is `--dry-run` (counts + 20-row sample). Live runs are idempotent (the WHERE clause excludes already-populated rows) and optionally chain `products:resync-to-woo` on the SUCCESSFULLY UPDATED SKUs only — never the candidate set.

Purpose: turn 89% Merchant Center disapproval rate into < 10% by populating GTIN/MPN/Brand/Category on the existing 3,922 live SKUs. The Woo plugin Google Listings & Ads re-feeds Merchant Center daily once the data lands.

Output:
- New shared trait `App\Console\Concerns\NormalisesEan` (single source of truth for EAN validation — `GenerateProductDraftsCommand` retrofitted to use it; same drift-prevention pattern as 260606-o63 `Product::scopeAutoCreated`)
- New `App\Console\Commands\BackfillMerchantFeedCommand` registered in `AppServiceProvider::$commands`
- Pest coverage for the trait + the command's three field paths
- Zero new failures in the full Pest suite vs the 260607-9c6 baseline (1845 / 219 / 3)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

# Existing helper to extract (byte-identical) into the trait
@app/Console/Commands/GenerateProductDraftsCommand.php

# Pattern references — DO NOT modify, only consume
@app/Console/Commands/BaseCommand.php
@app/Console/Commands/RetryMissingImagesCommand.php
@app/Console/Commands/AssignProductTaxonomyCommand.php
@app/Console/Commands/ResyncProductsToWooCommand.php
@app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php
@app/Domain/Integrations/Services/IntegrationCredentialResolver.php
@app/Domain/Sync/Services/SupplierSkuRegistry.php
@app/Providers/AppServiceProvider.php

<interfaces>
<!-- Contracts the executor consumes — extracted from the codebase so no exploration needed -->

From `app/Console/Commands/GenerateProductDraftsCommand.php` lines 479-491 — the canonical EAN normaliser, currently `private`. The trait must contain BYTE-IDENTICAL logic (digits-only, length 8-14, reject all-0/all-9 placeholders) and return `?string`:

    private function normaliseEan(mixed $raw): ?string
    {
        $s = preg_replace('/\D+/', '', (string) ($raw ?? '')) ?? '';
        $len = strlen($s);
        if ($len < 8 || $len > 14) {
            return null;
        }
        if (preg_match('/^(0+|9+)$/', $s) === 1) {
            return null;
        }
        return $s;
    }

From `app/Console/Commands/BaseCommand.php`:
    abstract class BaseCommand extends \Illuminate\Console\Command
    final public function handle(): int   // wires correlation_id + LogBatch, calls perform()
    abstract protected function perform(): int

From `app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php`:
    public function resolveBrand(?string $brandName): ?int       // returns Woo brand TERM ID (or null) — fuzzy threshold 0.85
    public function allBrands(): array                            // array<int, array{id:int,name:string}>, 1h cache
    private const FUZZY_THRESHOLD = 0.85

From `app/Domain/Integrations/Services/IntegrationCredentialResolver.php`:
    public function for(IntegrationCredentialKind $kind): array  // throws IntegrationCredentialMissingException
    // SupplierDb keys: host, port, database, username, password

From `app/Domain/Sync/Services/SupplierSkuRegistry.php` — REFERENCE mysqli streaming pattern (DO NOT EDIT):
    $creds = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new \mysqli($creds['host'], $creds['username'], $creds['password'], $creds['database'], (int)($creds['port'] ?? 3306));
    if ($db->connect_errno !== 0) { throw new \RuntimeException(...); }
    $result = $db->query("SELECT ... FROM feeds_products WHERE ...", MYSQLI_USE_RESULT);
    while ($row = $result->fetch_assoc()) { ... }
    $result->free(); $db->close();

From `app/Console/Commands/RetryMissingImagesCommand.php` — `--field` / `--skus` / `--limit` / `--dry-run` / `--resync` parsing pattern, `auth()->user()` capture at perform() start, Artisan::call chain + Artisan::output() pipe to the console.

From `app/Console/Commands/AssignProductTaxonomyCommand.php`:
    Signature: `products:assign-taxonomy {--skus= : Comma-separated SKUs of existing local Products (required)} {--dry-run}`
    Chained via: Artisan::call('products:assign-taxonomy', ['--skus' => $csv])

From `app/Console/Commands/ResyncProductsToWooCommand.php`:
    Signature: `products:resync-to-woo {--skus=} {--dry-run}`
    Chained via: Artisan::call('products:resync-to-woo', ['--skus' => $csv])

From `app/Providers/AppServiceProvider.php` boot() / `if ($this->app->runningInConsole()) { $this->commands([...]); }` block, ~line 607-790 — add `BackfillMerchantFeedCommand::class` to the array with a quick-task comment header.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Extract normaliseEan() to App\Console\Concerns\NormalisesEan trait + retrofit GenerateProductDraftsCommand</name>
  <files>
    app/Console/Concerns/NormalisesEan.php,
    app/Console/Commands/GenerateProductDraftsCommand.php,
    tests/Unit/Console/Concerns/NormalisesEanTest.php
  </files>
  <behavior>
    - Real EAN-13 `"5033588057222"` → returns `"5033588057222"` (passes through unchanged).
    - Dashed `"123-456-7890123"` → returns `"1234567890123"` (dashes stripped → 13 digits).
    - 8-digit `"12345678"` → returns `"12345678"` (lower bound accepted).
    - 14-digit `"12345678901234"` → returns `"12345678901234"` (upper bound accepted).
    - `"N/A"` → returns `null` (stripped to empty, fails length gate).
    - `""` → returns `null`.
    - `null` → returns `null`.
    - `"—"` (em-dash) → returns `null` (no digits).
    - 7-digit `"1234567"` → returns `null` (too short).
    - 15-digit `"123456789012345"` → returns `null` (too long).
    - `"0"` → returns `null` (placeholder).
    - `"00000000000000"` → returns `null` (all-zero placeholder).
    - `"99999999999999"` → returns `null` (all-nine placeholder).
    - `"9999999999999"` (13 nines) → returns `null`.
    - `12345678` (int, not string) → returns `"12345678"` (mixed input accepted via (string) cast).
  </behavior>
  <action>
    Step 1 — Create `app/Console/Concerns/NormalisesEan.php`. Namespace `App\Console\Concerns`. Trait `NormalisesEan` with ONE `public function normaliseEan(mixed $raw): ?string` whose body is BYTE-IDENTICAL to `GenerateProductDraftsCommand.php` lines 479-491 (digits-only via `preg_replace('/\D+/', '', (string)($raw ?? ''))`, length gate 8 ≤ len ≤ 14, reject `/^(0+|9+)$/`). Method MUST be `public` so the new command can call it on `$this`. Include the docblock from the original (trim/strip/digits-only/length/placeholder explanation) — single source of truth must carry the same warning.

    Step 2 — Edit `app/Console/Commands/GenerateProductDraftsCommand.php`: add `use App\Console\Concerns\NormalisesEan;` near the other `use` statements, add `use NormalisesEan;` inside the class body (near the top of the class, before any constants/properties). DELETE the `private function normaliseEan(...)` method at lines 473-492 entirely (the trait now provides it, public-scoped — broader-than-private widens visibility, which is legal in PHP trait composition). No other behaviour change in this file. All other private methods stay.

    Step 3 — Create `tests/Unit/Console/Concerns/NormalisesEanTest.php` as a Pest test consuming the trait via an anonymous class wrapper (avoid Symfony Command bootstrap):

      $sut = new class { use \App\Console\Concerns\NormalisesEan; };

    Cover ALL behaviour cases listed in `<behavior>` above. Use Pest's `it(...)` blocks with `expect($sut->normaliseEan(...))->toBe(...)` and `->toBeNull()` matchers. One assertion per case.

    Step 4 — DO NOT touch any other file in this task. No changes to AppServiceProvider yet. No EnvUsageTest changes (trait + tests have no env() reads).

    Atomic commit at end of task: `refactor(commands): extract normaliseEan() to NormalisesEan trait (260607-cgd)`. Use `git add` on only the three files listed in `<files>`.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Unit/Console/Concerns/NormalisesEanTest.php --stop-on-failure</automated>
    <automated>vendor/bin/pest tests/Architecture/EnvUsageTest.php --stop-on-failure</automated>
    <!-- Drift gate: the old private method must be gone; trait must be used -->
    <automated>grep -n "private function normaliseEan" app/Console/Commands/GenerateProductDraftsCommand.php | wc -l   # expect 0</automated>
    <automated>grep -n "use NormalisesEan" app/Console/Commands/GenerateProductDraftsCommand.php | wc -l   # expect 1</automated>
  </verify>
  <done>
    Trait file exists at `app/Console/Concerns/NormalisesEan.php` with byte-identical logic to the original; `GenerateProductDraftsCommand` no longer declares a `normaliseEan` method but `use`s the trait; Pest unit test green covering all 15 behaviour cases; EnvUsageTest still green; atomic commit `refactor(commands): extract normaliseEan() to NormalisesEan trait (260607-cgd)` landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: products:backfill-merchant-feed command — EAN field path</name>
  <files>
    app/Console/Commands/BackfillMerchantFeedCommand.php,
    tests/Feature/Console/BackfillMerchantFeedCommandTest.php
  </files>
  <behavior>
    - Default `--dry-run` (no flag) runs in dry-run mode (per the rule: live requires explicit absence of `--dry-run`); the operator MUST pass live by OMITTING `--dry-run`. (Signature follows the existing repo pattern: `--dry-run` is opt-in. The README banner the command prints CALLS OUT that production runs require omitting `--dry-run`.)
    - `--field=ean --dry-run` prints 4-quadrant counts: `would_update`, `skipped_invalid_ean`, `skipped_no_supplier_match`, `already_populated_excluded`. Writes ZERO rows.
    - `--field=ean --dry-run` prints a 20-row sample (`SKU → candidate EAN → outcome`).
    - Live `--field=ean` writes only validated EANs to `products.ean`. Re-run after success → reports 0 candidates (idempotent — WHERE excludes non-null/non-empty `ean`).
    - Supplier row with `ean='N/A'` is reported as `skipped_invalid_ean` (the trait returns null).
    - Local SKU with no matching supplier row is reported as `skipped_no_supplier_match`.
    - Supplier row with valid 13-digit EAN → `products.ean` updated to that string, byte-identical.
    - `--skus=ABC,DEF` scopes to those SKUs only.
    - `--limit=10` caps the candidate set at 10.
    - Without --resync, the command does NOT call `products:resync-to-woo`.
  </behavior>
  <action>
    Step 1 — Create `app/Console/Commands/BackfillMerchantFeedCommand.php`:

    - Namespace `App\Console\Commands`. Class `final class BackfillMerchantFeedCommand extends BaseCommand`. Declare `use \App\Console\Concerns\NormalisesEan;` after `use App\Console\Commands\BaseCommand` (already in same namespace — adjust accordingly). Inside the class body: `use NormalisesEan;`.

    - Constructor: `public function __construct(private readonly \App\Domain\Integrations\Services\IntegrationCredentialResolver $resolver, private readonly \App\Domain\ProductAutoCreate\Services\TaxonomyResolver $taxonomy) { parent::__construct(); }` — matches RetryMissingImagesCommand pattern. (Task 2 only uses `$resolver`; `$taxonomy` is declared now so Task 3 + Task 4 don't have to edit the constructor signature.)

    - Signature (verbatim, including line breaks):

        protected $signature = 'products:backfill-merchant-feed
            {--field=ean : Comma-separated fields to backfill: ean, brand, category}
            {--skus= : Comma-separated SKU list; default = all live products missing the requested field(s)}
            {--limit=0 : Max products this run; 0 = unbounded}
            {--dry-run : Print counts + 20-row sample; do NOT write}
            {--resync : After backfill, run products:resync-to-woo on the SUCCESSFULLY UPDATED SKUs only (re-feeds Merchant Center)}
            {--no-confirm : Skip interactive y/N confirmations (use for cron / non-interactive runs)}';

    - `protected $description = 'Backfill EAN/brand/category from supplier_db onto live products to lift Google Merchant Center disapproval rate.';`

    - `perform()` body (Task 2 scope — EAN only; Tasks 3+4 extend):

      1. Parse `--field` into `array $fields = array_values(array_filter(array_map('trim', explode(',', strtolower((string)$this->option('field'))))))`. Validate each token is one of `['ean','brand','category']`; unknown token → error + return FAILURE.

      2. Parse `--skus`, `--limit`, `--dry-run`, `--resync`. Capture `$this->triggeringUser = auth()->user();` at perform() start (same pattern as RetryMissingImagesCommand — preserves auth context across nested Artisan::call chains).

      3. Initialise `$updatedSkus = [];` (the global "successfully updated" set fed to --resync).

      4. If `in_array('ean', $fields, true)` call `$this->backfillEan($dryRun, $skusFilter, $limit, $updatedSkus);` — a new private method described below. (Brand/category methods land in Tasks 3+4 — Task 2 stubs them as `// TODO: Task 3`/`// TODO: Task 4` no-ops if-and-only-if they're listed in --field, with a `$this->warn("brand/category backfill not yet implemented in this task")` and continue.)

      5. After all field passes: if `--resync` and `$updatedSkus !== []`, print a banner showing the SKU count + (if interactive AND `!$noConfirm`) prompt y/N via `$this->confirm("Push {N} updated SKUs to Woo via products:resync-to-woo?", true)`; on yes, `Artisan::call('products:resync-to-woo', ['--skus' => implode(',', array_values(array_unique($updatedSkus)))])` then pipe `$this->line(Artisan::output())`. (Task 2 only ever populates $updatedSkus from EAN; Tasks 3+4 add to the same set.)

      6. Return SymfonyCommand::SUCCESS.

    - Private method `backfillEan(bool $dryRun, array $skusFilter, int $limit, array &$updatedSkus): void`:

      a. Build candidate query — `Product::query()->where('status', 'publish')->where(function ($q) { $q->whereNull('ean')->orWhere('ean', ''); })`. Apply `$skusFilter` via `->whereIn('sku', $skusFilter)` if non-empty. Apply `->limit($limit)` if `$limit > 0`. Project `id, sku` only via `->pluck('sku', 'id')` — but keep the array small (SKU list).

      b. If candidate set empty → `$this->info('EAN backfill: 0 candidate products.')` and return.

      c. Lowercase + trim each SKU for the supplier lookup key. Collect into `$candidateSkus` (array of strings).

      d. Open supplier_db via mysqli (mirror SupplierSkuRegistry pattern):

         $creds = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
         mysqli_report(MYSQLI_REPORT_OFF);
         $db = @new \mysqli($creds['host'], $creds['username'], $creds['password'], $creds['database'], (int)($creds['port'] ?? 3306));
         if ($db->connect_errno !== 0) { throw new \RuntimeException("Supplier DB connect failed: errno={$db->connect_errno} {$db->connect_error}"); }

         Build a single quoted IN-list for the candidate SKUs (use `mysqli_real_escape_string` per token). For deterministic behaviour query BOTH `suppliersku` AND `mpn` columns with one UNION-like approach: simpler is two queries, one keyed by suppliersku and one by mpn — first one wins per SKU (suppliersku preferred because that's the supplier's catalogue key).

         SELECT statement (suppliersku pass):
           SELECT LOWER(TRIM(suppliersku)) AS sku_key, ean
           FROM feeds_products
           WHERE product_excluded = 0 AND LOWER(TRIM(suppliersku)) IN ('a','b',...)

         Then a second SELECT keyed on mpn for any SKU still unresolved.

         Stream via `MYSQLI_USE_RESULT` + `while ($row = $result->fetch_assoc())`. Build `$supplierEanBySku[$sku_key] = $row['ean']`.

      e. For each candidate SKU: classify into one of four outcomes:
         - `already_populated_excluded` — should be 0 by construction (WHERE clause excludes them) but keep the bucket for the dry-run readout so the operator sees it's empty.
         - `skipped_no_supplier_match` — no `$supplierEanBySku[$key]`.
         - `skipped_invalid_ean` — supplier row found but `$this->normaliseEan($supplierEanBySku[$key])` returns null.
         - `would_update` / `updated` — supplier row found AND normalised EAN is non-null.

      f. Print 4-quadrant counts as an `$this->table([...], [...])`. Print 20-row sample (`$sku → $candidate → $outcome`).

      g. If `$dryRun`: print "Dry-run — exiting without writes." and return.

      h. Live path: chunk the update map into 500-row batches; per batch use `DB::table('products')->where('sku', $sku)->update(['ean' => $validated, 'updated_at' => now()])`. Push each successfully-updated SKU into `$updatedSkus` (passed by reference). Print final "Updated N products." with the count.

    Step 2 — Create `tests/Feature/Console/BackfillMerchantFeedCommandTest.php`. Use `RefreshDatabase`. The mysqli call is the test boundary — DO NOT spin up a real remote DB. Two options the executor may choose between (executor's call — document the choice in the test header comment):

      OPTION A (preferred — minimal surface): Extract the supplier_db lookup into a small protected `lookupSupplierEans(array $candidateSkus): array` method on the command; the Pest test instantiates the command via an anonymous subclass that overrides ONLY that method to return a stub map. (Matches the 260607-9c6 H-2 `runDumpCommand` test pattern.)

      OPTION B: Bind a small `SupplierFeedReader` interface in the constructor and use Laravel's container to swap a fake in the test.

      Choose Option A — fewer files, single-task scope.

    Pest cases:
      - `it('dry-run reports 4 quadrants and writes zero rows'):` seed 3 Products (status='publish', ean=null) with SKUs ABC/DEF/GHI; override `lookupSupplierEans` to return `['abc' => '5033588057222', 'def' => 'N/A', /* ghi missing */]`. Run `artisan('products:backfill-merchant-feed', ['--field' => 'ean', '--dry-run' => true])`. Assert exit code 0, products.ean is unchanged (`Product::where('sku','ABC')->value('ean')` is null), and the output `->expectsOutputToContain('would_update')` etc.
      - `it('live updates only validated rows'):` same seed; override lookup; run WITHOUT --dry-run. Assert `Product::where('sku','ABC')->value('ean') === '5033588057222'`, DEF stays null, GHI stays null. Exit code 0.
      - `it('is idempotent on re-run'):` after the live pass, re-run live; assert the second invocation reports "0 candidate products" (the WHERE clause excludes ABC because ean is now populated).
      - `it('--limit caps the candidate set'):` seed 5 SKUs all matching; run with --dry-run --limit=2; assert "candidate" count in output is 2.

    Step 3 — DO NOT register the command in AppServiceProvider yet. Laravel 12 auto-discovers `app/Console/Commands/*`, so `php artisan list` will find it even before Task 5 — but the explicit registration in Task 5 is the established repo convention (see GenerateProductDraftsCommand / AssignProductTaxonomyCommand precedent flagged in 260606-c4o).

    Atomic commit: `feat(products): backfill-merchant-feed command (EAN path) (260607-cgd)`. Stage ONLY the two files in `<files>` plus the trait import line in the command. No other files.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php --stop-on-failure</automated>
    <automated>vendor/bin/pest tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php --stop-on-failure</automated>
    <!-- env() guardrail: command must not call env() outside config -->
    <automated>grep -n "env(" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect 0</automated>
    <!-- Trait wired -->
    <automated>grep -n "use NormalisesEan" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect 1</automated>
  </verify>
  <done>
    Command file ships with EAN field path; dry-run prints 4-quadrant counts + 20-row sample; live writes only validated EANs; idempotent re-run reports 0 candidates; Pest feature tests green; env() guardrail clean; atomic commit `feat(products): backfill-merchant-feed command (EAN path) (260607-cgd)` landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Brand field path — supplier manufacturer → TaxonomyResolver fuzzy → brand_id</name>
  <files>
    app/Console/Commands/BackfillMerchantFeedCommand.php,
    tests/Feature/Console/BackfillMerchantFeedCommandTest.php
  </files>
  <behavior>
    - Live `--field=brand` writes `products.brand_id` only for products whose supplier_db `manufacturer` string resolves via `TaxonomyResolver::resolveBrand()` (Woo brand term id, fuzzy threshold ≥ 0.85, per 73ac682 fix).
    - Supplier row with empty manufacturer → reported as `skipped_no_supplier_manufacturer`. No write.
    - Supplier row with manufacturer that fuzzy-scores BELOW 0.85 against every Woo brand term → reported as `skipped_fuzzy_below_threshold`. No write. (Mocked Woo via a TaxonomyResolver test double — see Step 2 below.)
    - Supplier row with manufacturer that resolves to a brand term → `products.brand_id` updated to that term id.
    - `--field=ean,brand` runs BOTH passes; SKUs successfully updated in EITHER pass are added to `$updatedSkus`.
    - Dry-run prints the 4-quadrant brand counts alongside the EAN counts (one table per field).
  </behavior>
  <action>
    Step 1 — Add private method `backfillBrand(bool $dryRun, array $skusFilter, int $limit, array &$updatedSkus): void` to `BackfillMerchantFeedCommand`:

    a. Candidate query — `Product::query()->where('status', 'publish')->where(function ($q) { $q->whereNull('brand_id')->orWhere('brand_id', 0); })`. Same `$skusFilter` + `$limit` rules as backfillEan.

    b. If candidate set empty → `$this->info('Brand backfill: 0 candidate products.')` and return.

    c. Extract supplier manufacturer for each candidate via a NEW protected method `lookupSupplierManufacturers(array $candidateSkus): array<string,string>` (mirrors `lookupSupplierEans` shape — test double can override it). SELECT: `LOWER(TRIM(suppliersku)) AS sku_key, manufacturer FROM feeds_products WHERE product_excluded=0 AND LOWER(TRIM(suppliersku)) IN (...)`. Same suppliersku-first / mpn-fallback structure as Task 2.

    d. For each candidate SKU classify into:
       - `skipped_no_supplier_manufacturer` — no manufacturer string (null/empty after trim).
       - `skipped_fuzzy_below_threshold` — manufacturer non-empty but `$this->taxonomy->resolveBrand($mfr)` returns null.
       - `would_update` / `updated` — resolveBrand returned a term id.

    e. Print 4-quadrant counts + 20-row sample (sku → manufacturer → resolved brand name + id → outcome). Resolved brand name is looked up by id over `$this->taxonomy->allBrands()` array for sample display only.

    f. Dry-run: return after printing.

    g. Live path: 500-row chunk update via `DB::table('products')->where('sku', $sku)->update(['brand_id' => $brandId, 'updated_at' => now()])`. Push each successfully-updated SKU into `$updatedSkus`.

    Step 2 — Wire into `perform()`: after the EAN pass, `if (in_array('brand', $fields, true)) $this->backfillBrand(...);`.

    Step 3 — Extend `tests/Feature/Console/BackfillMerchantFeedCommandTest.php`:

    Override BOTH `lookupSupplierManufacturers` (returning a stub map) AND swap the `TaxonomyResolver` binding via `$this->instance(TaxonomyResolver::class, $stub)` with a stub double whose `resolveBrand('Sony')` returns 42 and `resolveBrand('Linsx')` returns null (below threshold). Also stub `allBrands()` returning `[['id'=>42,'name'=>'Sony']]` for the display sample.

    Pest cases (additive — keep existing EAN cases green):
      - `it('--field=brand live updates only resolved brands'):` seed 3 Products (brand_id=null) with SKUs SONY/LINSX/NONE; manufacturer map `['sony'=>'Sony','linsx'=>'Linsx', /* none missing */]`. Run without --dry-run. Assert Product::sku=SONY brand_id=42; LINSX brand_id stays null; NONE stays null. Exit 0.
      - `it('--field=brand fuzzy below threshold does not write'):` direct assertion that LINSX kept brand_id=null after the live run.
      - `it('--field=brand dry-run writes nothing'):` same seed; with --dry-run; assert all brand_ids still null after run; output contains all three outcome buckets.

    Atomic commit: `feat(products): backfill-merchant-feed brand path via supplier_db.manufacturer + TaxonomyResolver fuzzy (260607-cgd)`.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php --stop-on-failure</automated>
    <automated>vendor/bin/pest tests/Architecture/EnvUsageTest.php --stop-on-failure</automated>
    <automated>grep -n "FUZZY_THRESHOLD" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect 0 — threshold lives in TaxonomyResolver, not duplicated here</automated>
  </verify>
  <done>
    `backfillBrand()` method ships; live path writes only fuzzy-resolved brand_ids; below-threshold + missing-manufacturer rows are reported but NOT written; Pest feature tests green for both EAN + brand paths; threshold NOT duplicated in the command (single source of truth stays in TaxonomyResolver per 73ac682); atomic commit landed.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 4: Category field path (Claude via products:assign-taxonomy) + --resync chain finalisation</name>
  <files>
    app/Console/Commands/BackfillMerchantFeedCommand.php
  </files>
  <action>
    Step 1 — Add private method `backfillCategory(bool $dryRun, array $skusFilter, int $limit, bool $noConfirm, array &$updatedSkus): void`:

    a. Candidate query — `Product::query()->where('status', 'publish')->where(function ($q) { $q->whereNull('category_id')->orWhere('category_id', 0); })`. Same skus+limit filters.

    b. If empty → info and return.

    c. Pluck SKU list (`$candidateSkus`). `$count = count($candidateSkus)`.

    d. Cost banner BEFORE any spend: estimate `$costPence = $count * 1;` (~1p/SKU per task background — be conservative; AssignProductTaxonomyCommand uses Claude per SKU). Print a clear info line: "Category backfill candidates: {N}. Estimated Claude spend: ~{N}p (~£{N/100}). Field: category."

    e. Dry-run path: also print first 20 SKUs as a sample, then return.

    f. Live path interactive guard: if `posix_isatty(STDIN)` is true (stdin is a TTY) AND `!$noConfirm`, call `if (!$this->confirm("About to spend ~£{N/100} on Claude for category backfill. Proceed?", false)) { $this->warn('Aborted by operator.'); return; }`. If stdin is not a TTY (cron/queue) and `--no-confirm` is absent, ABORT with an error (operator must explicitly opt in for non-interactive runs via `--no-confirm`).

    g. Chunk `$candidateSkus` into 50-SKU batches via `array_chunk`. For each batch:
       - Capture `Product::whereIn('sku', $batch)->whereNull('category_id')->pluck('sku')->all()` as `$beforeMissing` (idempotency baseline within the batch).
       - `$exit = Artisan::call('products:assign-taxonomy', ['--skus' => implode(',', $batch)]);`
       - Pipe output: `$this->line(Artisan::output());`
       - Re-query `Product::whereIn('sku', $batch)->whereNotNull('category_id')->pluck('sku')->all()` as `$afterAssigned`; the intersection of (`$beforeMissing` ∩ `$afterAssigned`) is the SET successfully updated in this batch. Push each into `$updatedSkus`.
       - On non-zero exit code, `$this->warn("Batch exited {$exit} — continuing")` but continue the loop (don't abort whole backfill on one batch failure).

    h. After loop, print "Category backfill: {N} products updated."

    Step 2 — Wire into `perform()` after the brand pass: `if (in_array('category', $fields, true)) $this->backfillCategory($dryRun, $skusFilter, $limit, $noConfirm, $updatedSkus);`.

    Step 3 — Finalise `--resync` chain end-of-perform() (already drafted in Task 2 — verify the path now feeds the union of $updatedSkus from all three field passes). Pre-resync banner: `$this->info("Resync candidates: ".count($updatedSkus)." SKU(s).")`; if non-empty and interactive+not-no-confirm, `$this->confirm("Push these to Woo via products:resync-to-woo?", true)`. Dedup via `array_values(array_unique($updatedSkus))` before passing as `--skus` CSV. Pipe Artisan::output().

    Step 4 — Verify NO env() reads anywhere in the command. Verify the command file body doesn't duplicate FUZZY_THRESHOLD, doesn't re-define normaliseEan, doesn't bypass TaxonomyResolver.

    Step 5 — Manual smoke (not a Pest test — Claude/Woo integrations are external). Run `php artisan products:backfill-merchant-feed --field=category --dry-run --limit=5` on dev DB; output should print "Category backfill candidates: 5" and the cost banner; exit 0 without touching supplier_db (category path doesn't need supplier_db at all — it just hands SKUs to assign-taxonomy).

    Atomic commit: `feat(products): backfill-merchant-feed category (Claude via assign-taxonomy) + --resync chain (260607-cgd)`. Stage ONLY the command file.

    NOTE on testing: category-path Pest coverage is intentionally NOT added in this task. AssignProductTaxonomyCommand is itself external (Claude call) and re-mocking it inside this command's tests would be high-effort low-value. The integration is verified by Task 5 manual smoke + the existing AssignProductTaxonomyCommand test suite (separate file, already green).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php --stop-on-failure</automated>
    <automated>vendor/bin/pest tests/Architecture/EnvUsageTest.php --stop-on-failure</automated>
    <automated>grep -n "env(" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect 0</automated>
    <automated>grep -nE "(private|protected) function normaliseEan" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect 0 — must use trait</automated>
    <automated>grep -n "products:assign-taxonomy" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect ≥ 1</automated>
    <automated>grep -n "products:resync-to-woo" app/Console/Commands/BackfillMerchantFeedCommand.php | wc -l   # expect ≥ 1</automated>
  </verify>
  <done>
    `backfillCategory()` method ships; cost-estimate banner shows BEFORE Claude spend; interactive confirmation gates the live path unless --no-confirm; `Artisan::call('products:assign-taxonomy', ['--skus' => ...])` runs in 50-SKU batches; updated-SKU set is the intersection of "was missing before / present after" (correct attribution to this run, not legacy assignments); `--resync` chain at end-of-perform passes the union of all three passes' updates and only the SKUs we changed; atomic commit landed.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 5: Register command in AppServiceProvider + verification (no commit)</name>
  <files>
    app/Providers/AppServiceProvider.php
  </files>
  <action>
    Step 1 — Edit `app/Providers/AppServiceProvider.php`. Add `use App\Console\Commands\BackfillMerchantFeedCommand;` to the alphabetised `use` block (near the other `App\Console\Commands\*` imports). Then inside the `$this->commands([...])` array (the `if ($this->app->runningInConsole())` block ~line 607-790), append a new entry near the other product/merchant commands (e.g. after `PruneOrphanSuggestionsCommand::class,` ~line 719). Use a quick-task comment header matching the established pattern:

        // Quick task 260607-cgd — products:backfill-merchant-feed. Backfills
        // EAN/brand/category from supplier_db onto live products to lift
        // Google Merchant Center disapproval rate (89% → <10% target).
        // Default --dry-run; --resync chains products:resync-to-woo on the
        // SUCCESSFULLY UPDATED SKUs only. Reuses NormalisesEan trait so the
        // EAN validator stays byte-identical to GenerateProductDraftsCommand.
        BackfillMerchantFeedCommand::class,

    Step 2 — Run focused tests:

      vendor/bin/pest tests/Unit/Console/Concerns/NormalisesEanTest.php tests/Feature/Console/BackfillMerchantFeedCommandTest.php tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php --stop-on-failure

    All MUST be GREEN.

    Step 3 — Verify the command is discoverable: `php artisan list | grep backfill-merchant-feed` — expect 1 line `products:backfill-merchant-feed  Backfill EAN/brand/category from supplier_db onto live products...`.

    Step 4 — Dry-run smoke against dev DB (mysqli will likely fail in dev because supplier_db creds aren't present locally — that's expected; the test is "does the command boot, parse args, and error cleanly?"). Run:

      php artisan products:backfill-merchant-feed --field=ean --dry-run --limit=10

    Expected outcome A (supplier_db creds present): per-quadrant table + 20-row sample.
    Expected outcome B (no creds in dev): `Supplier DB connect failed` exception printed; exit code non-zero. THIS IS ACCEPTABLE — capture in the SUMMARY notes. The failure mode is identical to how SupplierSkuRegistry behaves in dev.

    Step 5 — Run the full Pest suite baseline against 260607-9c6 (1845 / 219 / 3):

      vendor/bin/pest 2>&1 | tail -20

    Expect: pass count ≥ 1845 + (NormalisesEanTest cases) + (BackfillMerchantFeedCommandTest cases). Failed count MUST stay ≤ 219 (zero new failures). Skipped MUST stay at 3.

    Step 6 — Verify `composer pint` and `vendor/bin/phpstan analyse --memory-limit=2G` clean (PHPStan level matches the project default — see composer.json). Fix any errors before commit.

    Step 7 — Single atomic commit covering ONLY this task's AppServiceProvider edit:

      chore(commands): register products:backfill-merchant-feed (260607-cgd)

    Stage ONLY `app/Providers/AppServiceProvider.php`. Do NOT amend the four prior commits (Tasks 1-4) — each lands as its own atomic commit per repo convention.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Unit/Console/Concerns/NormalisesEanTest.php tests/Feature/Console/BackfillMerchantFeedCommandTest.php tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php --stop-on-failure</automated>
    <automated>php artisan list 2>&1 | grep -c "products:backfill-merchant-feed"   # expect 1</automated>
    <automated>vendor/bin/pest 2>&1 | tail -5   # full-suite baseline check: pass ≥ 1845, failed ≤ 219, skipped == 3</automated>
    <automated>grep -n "BackfillMerchantFeedCommand::class" app/Providers/AppServiceProvider.php | wc -l   # expect 1</automated>
    <human-check>SUMMARY captures: total commits (4 — one per task 1-4 + this Task 5 commit = 5 total), full Pest suite delta vs 260607-9c6 baseline (1845/219/3), and the supplier_db smoke outcome (A creds-present / B no-creds — record which one happened).</human-check>
  </verify>
  <done>
    Command appears in `php artisan list`; focused Pest tests green (Trait + Feature + 2 architecture guardrails); full Pest suite shows zero new failures vs 260607-9c6 baseline; PHPStan + Pint clean; atomic commit `chore(commands): register products:backfill-merchant-feed (260607-cgd)` landed (separate from the four feature commits in Tasks 1-4); SUMMARY.md documents the 5 commits + delta + smoke outcome.
  </done>
</task>

</tasks>

<verification>
End-to-end gate (run before writing SUMMARY):

1. `php artisan list | grep products:backfill-merchant-feed` → exactly 1 line.
2. `vendor/bin/pest tests/Unit/Console/Concerns/ tests/Feature/Console/BackfillMerchantFeedCommandTest.php tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php` → all green.
3. `vendor/bin/pest` full-suite — pass ≥ 1845, failed ≤ 219, skipped == 3 (no new failures vs 260607-9c6 baseline).
4. `grep -c "private function normaliseEan" app/Console/Commands/GenerateProductDraftsCommand.php` → 0 (trait is sole owner).
5. `grep -c "env(" app/Console/Commands/BackfillMerchantFeedCommand.php app/Console/Concerns/NormalisesEan.php` → 0 (env() guardrail intact).
6. `git log --oneline -6` → 5 atomic commits referencing 260607-cgd in this order:
   - refactor(commands): extract normaliseEan() to NormalisesEan trait (260607-cgd)
   - feat(products): backfill-merchant-feed command (EAN path) (260607-cgd)
   - feat(products): backfill-merchant-feed brand path via supplier_db.manufacturer + TaxonomyResolver fuzzy (260607-cgd)
   - feat(products): backfill-merchant-feed category (Claude via assign-taxonomy) + --resync chain (260607-cgd)
   - chore(commands): register products:backfill-merchant-feed (260607-cgd)
7. Manual smoke (dev): `php artisan products:backfill-merchant-feed --field=ean --dry-run --limit=10` — either prints 4-quadrant table (creds present) or raises Supplier DB connect failed (creds absent). Either is acceptable; record which in SUMMARY.
</verification>

<success_criteria>
- `App\Console\Concerns\NormalisesEan` trait exists as the single source of truth for EAN validation; `GenerateProductDraftsCommand` no longer carries a private copy.
- `products:backfill-merchant-feed` registered in AppServiceProvider and discoverable via `php artisan list`.
- Three field paths work end-to-end on dry-run: `ean` (supplier_db.ean → normaliseEan), `brand` (supplier_db.manufacturer → TaxonomyResolver fuzzy), `category` (chain `products:assign-taxonomy`).
- Live runs are idempotent: WHERE clauses exclude already-populated rows, so re-running after a successful pass reports 0 candidates.
- `--resync` chains `products:resync-to-woo` ONLY for SKUs successfully updated in this run (never the candidate set, never legacy SKUs).
- Cost estimate banner (~£N at ~1p/SKU) shows BEFORE the category-path Claude spend; interactive confirm gates live runs unless `--no-confirm`.
- Pest suite: NormalisesEanTest + BackfillMerchantFeedCommandTest green; EnvUsageTest + AutoCreatedPredicateTest still green; zero new failures vs 260607-9c6 baseline (1845 / 219 / 3).
- All five atomic commits land on this branch, each referencing the quick id `260607-cgd`.
</success_criteria>

<output>
After verification passes, create `.planning/quick/260607-cgd-products-backfill-merchant-feed-ean-bran/260607-cgd-SUMMARY.md` capturing:
- The 5 atomic commit SHAs in order.
- Full Pest suite delta vs the 260607-9c6 baseline (pass / fail / skipped — must show zero new failures).
- The dev smoke outcome for `--field=ean --dry-run --limit=10` (creds-present-table OR creds-absent-error — both are acceptable; record which one happened).
- Whether prod backfill has been kicked off yet (likely NO — prod run is a follow-up operator step once command is shipped).
- Any deviations from the plan + rationale.
</output>
