---
phase: quick-260809-uza
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Sync/Commands/WooImportProductsCommand.php
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
  - app/Domain/Sync/Models/ImportIssue.php
  - app/Domain/Sync/Filament/Resources/ImportIssueResource.php
  - database/migrations/2026_08_09_120000_add_stale_cost_no_supplier_to_import_issues_issue_type.php
  - tests/Feature/Sync/WooImportProductsCommandCostAuthorityTest.php
  - tests/Feature/Domain/Sync/SupplierDbSyncStaleCostTest.php
autonomous: true
requirements: ["260809-uza"]

must_haves:
  truths:
    - "woo:import-products NEVER overwrites an existing product's non-null local buy_price on update"
    - "woo:import-products still SEEDS buy_price from Woo COG meta on create, and on existing rows where buy_price IS NULL"
    - "woo:import-products --with-supplier still authoritatively sets buy_price (that path IS the supplier feed)"
    - "supplier:db-sync writes a STALE_COST_NO_SUPPLIER ImportIssue for a previously-costed product that matches no valid supplier offer"
    - "carve-outs (is_custom_ms, exclude_from_auto_update, custom-ms tag) are respected — no stale-cost issue is written for them, and the cost is never nulled/changed"
    - "the new issue_type value is accepted by BOTH MariaDB (prod ENUM) and SQLite (test CHECK constraint)"
  artifacts:
    - path: "app/Domain/Sync/Commands/WooImportProductsCommand.php"
      provides: "Conditional buy_price authority — seed-on-create/null-only, preserve-on-update"
      contains: "buy_price"
    - path: "app/Domain/Sync/Commands/SupplierDbSyncCommand.php"
      provides: "Stale-cost surfacing in the unmatched branch"
      contains: "STALE_COST_NO_SUPPLIER"
    - path: "app/Domain/Sync/Models/ImportIssue.php"
      provides: "New STALE_COST_NO_SUPPLIER issue-type constant"
      contains: "STALE_COST_NO_SUPPLIER"
    - path: "database/migrations/2026_08_09_120000_add_stale_cost_no_supplier_to_import_issues_issue_type.php"
      provides: "Driver-guarded ENUM extension"
      contains: "stale_cost_no_supplier"
  key_links:
    - from: "app/Domain/Sync/Commands/WooImportProductsCommand.php"
      to: "products.buy_price"
      via: "conditional payload key (omitted on preserve path)"
      pattern: "buy_price"
    - from: "app/Domain/Sync/Commands/SupplierDbSyncCommand.php"
      to: "ImportIssue (stale_cost_no_supplier)"
      via: "updateOrCreate in the !isset(map[key]) branch"
      pattern: "ImportIssue::updateOrCreate"
---

<objective>
Break the circular cost-authority loop and surface stale costs, for the pricing bug traced on SKU 9C941AA (local buy_price frozen at July's £1019.89 while the cheapest fresh in-stock supplier offer has been £3759.59 since Aug 3).

Two confirmed defects:

- PART 1 (circular cost authority): `woo:import-products` ALWAYS sets `buy_price` from Woo's `_alg_wc_cog_cost` meta on every update. Combined with the nightly `cutover:auto-sync --field=...,buy_price` that PUSHES local buy_price back to Woo COG, this cements any wrong cost forever — the supplier feed can never win durably. Fix: Woo COG may only SEED buy_price on create or when local buy_price IS NULL; the `--with-supplier` authoritative path is preserved.
- PART 2 (silent stale cost): in `supplier:db-sync`, a previously-costed product whose only suppliers are now excluded/stale/OOS matches no key in `buildBestOfferMap` and its stale buy_price is silently left driving the sell-price recompute. Neither `--flag-obsolete` (zero-offer only) nor `products:flag-missing-buy-price` (NULL only) catches it. Fix: write a new `STALE_COST_NO_SUPPLIER` ImportIssue (never change the cost) so it surfaces for review.

Purpose: Make the supplier feed the durable authority for cost, and give operators an audit trail when a fresh cost cannot be sourced.
Output: Two command fixes, a new ImportIssue type + driver-guarded migration, a small triage-UI touch, and two Pest tests.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md

<interfaces>
<!-- Extracted from the codebase — executor should use these directly, no exploration needed. -->

WooImportProductsCommand::perform() (app/Domain/Sync/Commands/WooImportProductsCommand.php):
- Builds $payload (lines ~138-151) with 'buy_price' => $this->parseDecimal($cogCost) ALWAYS present.
- $cogCost extracted via $this->extractMetaValue($p['meta_data'] ?? [], '_alg_wc_cog_cost').
- --with-supplier override (lines ~156-161) sets $payload['buy_price'] = $supplierBuy when supplier feed has the SKU.
- Write happens via Product::withoutEvents(fn () => Product::updateOrCreate(['woo_product_id' => $wooProductId], $payload)) (lines ~179-184). withoutEvents MUST be preserved (260611-s2d — prevents ProductObserver echo-loop PUTs back to Woo).
- $product->wasRecentlyCreated distinguishes create vs update for counters.
- Dry-run branch (lines ~163-170) counts created/updated purely by Product::where('woo_product_id', $wooProductId)->exists().
- private parseDecimal(mixed): ?string — returns null for empty/non-numeric.

SupplierDbSyncCommand::perform() main loop (app/Domain/Sync/Commands/SupplierDbSyncCommand.php):
- Chunks full Product models: Product::whereNotNull('sku')->orderBy('id')->chunk(500, fn ($batch) => ...).
- Unmatched branch (lines ~221-238): `if ($key === '' || ! isset($map[$key])) { $unmatched++; if ($flagObsolete && ... isObsoleteCandidate($local)) {...} continue; }`.
- isObsoleteCandidate(Product $product): bool (lines ~365-378) — returns false unless status==='publish', is_custom_ms===false, exclude_from_auto_update===false, and NOT in tags 'custom-ms'.
- $dryRun (bool) is in scope; existing counters use the $wouldFlagObsolete / $flaggedObsolete pattern.
- $local is a full Product model → $local->id, $local->sku, $local->woo_product_id, $local->buy_price all available.
- DO NOT touch buildBestOfferMap (lines ~537-634).

ImportIssue (app/Domain/Sync/Models/ImportIssue.php):
- Constants: TYPE_MISSING_AT_SUPPLIER, TYPE_UNKNOWN_SKU, TYPE_MISSING_COST_PRICE, TYPE_EXCLUDE_FLAG_NO_METADATA.
- $fillable includes sku, woo_product_id, woo_variation_id, issue_type, detected_at, last_seen_at, resolved_at, notes, correlation_id.
- Migration column: issue_type is a MySQL native ENUM (see below). detected_at NOT NULL + indexed. correlation_id uuid NOT NULL + indexed. last_seen_at + resolved_at nullable.

PriceRecomputer::logImportIssue() (app/Domain/Pricing/Services/PriceRecomputer.php lines ~239-261) — the idempotent pattern to mirror:
  ImportIssue::updateOrCreate(
    ['sku' => $sku, 'woo_product_id' => $wooProductId, 'woo_variation_id' => $wooVariationId, 'issue_type' => ImportIssue::TYPE_MISSING_COST_PRICE, 'resolved_at' => null],
    ['detected_at' => now(), 'last_seen_at' => now(), 'notes' => "...", 'correlation_id' => $correlationId],
  );

ENUM-extension precedent (EXACT template to copy): database/migrations/2026_05_02_010100_add_ftp_pull_failed_to_csv_parse_errors_issue_type.php
  - mysql: DB::statement("ALTER TABLE ... MODIFY COLUMN issue_type ENUM(...) NOT NULL")
  - sqlite: dropIndex → dropColumn → re-add enum with new value → re-add index (RefreshDatabase DB is empty at migrate time, no row migration).
  - other drivers: no-op.
</interfaces>

@app/Domain/Sync/Commands/WooImportProductsCommand.php
@app/Domain/Sync/Commands/SupplierDbSyncCommand.php
@app/Domain/Sync/Models/ImportIssue.php
@app/Domain/Sync/Filament/Resources/ImportIssueResource.php
@database/migrations/2026_04_18_200400_create_import_issues_table.php
@tests/Feature/Sync/WooImportProductsCommandTest.php
@tests/Feature/Domain/Sync/SupplierDbSyncStaleSupplierTest.php
</context>

<out_of_scope>
- DO NOT modify `buildBestOfferMap`'s cheapest-in-stock / stale / excluded selection logic (lines ~537-634). A separate runtime check — "does the sync actually select an active + fresh + in-stock supplier?" — is still pending operator confirmation. If that check turns out buggy it is a FOLLOW-UP task, not this one.
- DO NOT change the nightly `cutover:auto-sync --field=stock_quantity,buy_price` push (routes/console.php ~548). Part 1 breaks the loop at the import side; the push side stays as-is (it now pushes the supplier-authoritative cost, which is correct).
- Executor must NOT push or deploy. Commit locally only.
</out_of_scope>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: woo:import-products — Woo COG seeds cost only on create / when null; supplier feed stays authoritative</name>
  <files>app/Domain/Sync/Commands/WooImportProductsCommand.php, tests/Feature/Sync/WooImportProductsCommandCostAuthorityTest.php</files>
  <behavior>
    - Existing product, non-null local buy_price, Woo COG present & different, NO --with-supplier: buy_price is UNCHANGED after import.
    - New product (no existing row), Woo COG present: buy_price is SEEDED from COG.
    - Existing product, local buy_price IS NULL, Woo COG present: buy_price is SEEDED from COG.
    - --with-supplier with a supplier price for the SKU, existing non-null buy_price: supplier price WINS (authoritative override preserved).
    - All other fields (name, status, stock_status, stock_quantity, sell_price, last_synced_at) still update on every existing row exactly as before.
    - --dry-run still writes nothing and still counts created vs updated by row existence.
  </behavior>
  <action>
Restructure the buy_price handling in `perform()` so the Woo COG value only ever SEEDS cost, and the supplier feed (via --with-supplier) remains the sole authoritative overwrite. Per the PART 1 diagnosis for 260809-uza.

Because `updateOrCreate` applies the ENTIRE values array on update, buy_price must be conditionally included, not always present. Implement:

1. Remove the unconditional `'buy_price' => $this->parseDecimal($cogCost)` from the base `$payload` array. Keep every other payload key unchanged.
2. Before writing, resolve the existing row once: fetch the current product (id + buy_price) by woo_product_id. Reuse this for BOTH the dry-run existence check and the live path (do not add a second query in dry-run — replace the existing `Product::where(...)->exists()` check with a null-check on the fetched row).
3. Decide buy_price with this precedence:
   - If `--with-supplier` AND sku !== '' AND supplierFeed has the SKU AND parseDecimal(supplier price) !== null → include `$payload['buy_price'] = $supplierBuy` (authoritative; wins on new AND existing). This preserves the current --with-supplier override semantics.
   - Else if the row is NEW (no existing) OR existing buy_price IS NULL → set `$cog = $this->parseDecimal($cogCost)`; include `$payload['buy_price'] = $cog` ONLY when $cog !== null (never seed a null over nothing — a genuinely null COG just leaves buy_price null on create, matching today's behaviour).
   - Else (existing row with a non-null buy_price and no supplier override) → DO NOT add the buy_price key at all. updateOrCreate then leaves products.buy_price untouched on update.
4. Keep the write wrapped in `Product::withoutEvents(fn () => Product::updateOrCreate(['woo_product_id' => $wooProductId], $payload))` — the 260611-s2d echo-loop guard is non-negotiable.
5. Keep created/updated counters driven by `$product->wasRecentlyCreated` (live) and by row existence (dry-run). Do NOT count a preserved buy_price as anything special — an existing row is still "updated" because other fields refresh. Do not introduce a phantom counter.

This is directive prose only — do NOT inline code blocks. Follow resolve-don't-invent: reuse the existing parseDecimal, extractMetaValue, and withoutEvents patterns already in the file.

Then write tests/Feature/Sync/WooImportProductsCommandCostAuthorityTest.php. Make it self-contained: define local Mockery fakes for WooClient::get and SupplierClient::fetchAllProducts (mirror the fakeWooPage / fakeSupplier helpers in the sibling WooImportProductsCommandTest.php, but define them INSIDE this file or inline to avoid cross-file helper coupling). Use `uses(RefreshDatabase::class)` and seed Context correlation_id in beforeEach as the sibling test does. Cover every case in the <behavior> block. Keep SQL driver-portable (SQLite :memory:).
  </action>
  <verify>
    <automated>php artisan test tests/Feature/Sync/WooImportProductsCommandCostAuthorityTest.php tests/Feature/Sync/WooImportProductsCommandTest.php</automated>
  </verify>
  <done>New cost-authority test passes AND the pre-existing WooImportProductsCommandTest.php still passes (regression — the extract-COG and --with-supplier tests must stay green). On an existing product with a non-null buy_price, woo:import leaves buy_price untouched; on create / null it seeds from COG; --with-supplier still overrides.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: supplier:db-sync — surface a STALE_COST_NO_SUPPLIER ImportIssue when a costed product has no valid supplier offer</name>
  <files>app/Domain/Sync/Models/ImportIssue.php, database/migrations/2026_08_09_120000_add_stale_cost_no_supplier_to_import_issues_issue_type.php, app/Domain/Sync/Commands/SupplierDbSyncCommand.php, app/Domain/Sync/Filament/Resources/ImportIssueResource.php, tests/Feature/Domain/Sync/SupplierDbSyncStaleCostTest.php</files>
  <behavior>
    - Product with a non-null buy_price whose SKU is absent from buildBestOfferMap (all suppliers excluded/stale/OOS) → an unresolved ImportIssue with issue_type=stale_cost_no_supplier is written; buy_price is NOT changed.
    - Carve-out respected: is_custom_ms=true, OR exclude_from_auto_update=true, OR a 'custom-ms' tag → NO issue written.
    - Product with a NULL buy_price and no supplier offer → NO stale_cost_no_supplier issue (that is the existing missing_cost_price / flag-missing-buy-price path, not ours).
    - Re-running the sync the same day does NOT create a duplicate row (idempotent updateOrCreate on the unresolved tuple).
    - --dry-run writes NO issue row (counts a would-flag instead), matching the command's existing dry-run discipline.
    - The new type is insertable on SQLite (test) — proving the migration's CHECK-constraint rebuild worked — and on MariaDB via the MODIFY path.
  </behavior>
  <action>
Add the new issue type and surface it for operator triage. Per the PART 2 diagnosis for 260809-uza. It must run BY DEFAULT in the scheduled bare `supplier:db-sync` path (no new flag — the 07:00 cron invokes it with no options), independent of `--flag-obsolete`.

1. ImportIssue model: add `public const STALE_COST_NO_SUPPLIER = 'stale_cost_no_supplier';` alongside the existing type constants. Update the model's class docblock enum list to mention it.

2. Migration database/migrations/2026_08_09_120000_add_stale_cost_no_supplier_to_import_issues_issue_type.php — COPY the structure of database/migrations/2026_05_02_010100_add_ftp_pull_failed_to_csv_parse_errors_issue_type.php exactly, retargeted to the import_issues table:
   - mysql up(): `ALTER TABLE import_issues MODIFY COLUMN issue_type ENUM('missing_at_supplier','unknown_sku','missing_cost_price','exclude_flag_no_metadata','stale_cost_no_supplier') NOT NULL`.
   - sqlite up(): drop the single-column issue_type index (confirm its real name first — Laravel default is `import_issues_issue_type_index`; verify against the create migration / schema before hardcoding), dropColumn('issue_type'), re-add `->enum('issue_type', [<all five>])->after('woo_variation_id')`, then re-add `->index()` on issue_type with the same name. Note the create migration used a bare `->enum(...)->index()` (single-column index, NOT composite) — so unlike the csv_parse_errors precedent there is only ONE index to drop/restore.
   - down(): reverse to the original four values (mysql MODIFY back; sqlite drop+re-add+reindex).
   - other drivers: no-op.
   - Driver-portable is mandatory (SQLite tests vs MariaDB prod) — this is the exact SQLite/MariaDB strict trap that bit prod twice; the ENUM CHECK constraint on SQLite WILL reject 'stale_cost_no_supplier' without this migration, so the Part-2 test depends on it.

3. SupplierDbSyncCommand::perform():
   - Generate one correlation_id per run near the top: `$correlationId = (string) \Illuminate\Support\Str::uuid();` (add `use Illuminate\Support\Str;`). import_issues.correlation_id is NOT NULL, and this command has none today.
   - Extract the three auto-update carve-outs (is_custom_ms, exclude_from_auto_update, 'custom-ms' tag) currently inline in isObsoleteCandidate() into a private helper `hasAutoUpdateCarveOut(Product $product): bool`, and refactor isObsoleteCandidate() to call it (preserving isObsoleteCandidate's additional publish-status gate unchanged). Do not alter isObsoleteCandidate's external behaviour.
   - In the unmatched branch (`$key === '' || ! isset($map[$key])`), AFTER `$unmatched++` and INDEPENDENT of the `--flag-obsolete` block: when `$key !== ''` AND `$local->buy_price !== null` AND NOT `hasAutoUpdateCarveOut($local)` → this is a previously-costed product with no fresh in-stock source. Deliberately do NOT gate on publish status here (a stale cost is a data-quality fact regardless of storefront visibility — note this divergence from isObsoleteCandidate in a comment). If `$dryRun` → increment a new `$wouldFlagStaleCost` counter; else write the ImportIssue via the idempotent updateOrCreate pattern mirroring PriceRecomputer::logImportIssue: match on [sku=$local->sku, woo_product_id=$local->woo_product_id, woo_variation_id=null, issue_type=ImportIssue::STALE_COST_NO_SUPPLIER, resolved_at=null], values [detected_at=now(), last_seen_at=now(), notes="Product has a non-null buy_price but no fresh in-stock supplier offer — cost may be stale (260809-uza); cost left unchanged.", correlation_id=$correlationId], and increment a `$flaggedStaleCost` counter. NEVER null or change buy_price.
   - Add the new counters to the command's summary line (e.g. `stale_cost=%d` live, `would_flag_stale_cost=%d` on dry-run) so ops sees it.

4. ImportIssueResource (triage UI — completes "surface for review"): add ImportIssue::STALE_COST_NO_SUPPLIER => 'Stale cost / no supplier' to (a) the form Select options, (b) the table badge color match arm (use 'danger' — same severity as missing cost), and (c) the SelectFilter options. The unresolved nav badge already counts all types, so no change there.

5. Test tests/Feature/Domain/Sync/SupplierDbSyncStaleCostTest.php — follow the SupplierDbSyncStaleSupplierTest.php pattern (construct the command directly via `new SupplierDbSyncCommand(app(IntegrationCredentialResolver::class), app(SupplierFreshnessResolver::class))`, `uses(RefreshDatabase::class)`). Because the stale-cost logic lives in perform()'s Product-iteration loop (not in the directly-callable buildBestOfferMap), drive it through the command: seed Product rows, and mock the remote pull so buildBestOfferMap yields a map WITHOUT the target SKU. Prefer running the artisan command with a fake supplier connection if that is the established pattern; otherwise assert against a small extracted seam. Confirm the exact seeding/mocking approach against the sibling SupplierDbSyncCommandTest.php (tests/Feature/Sync/SupplierDbSyncCommandTest.php) before writing — reuse whatever mysqli/credential-faking pattern it already uses; resolve-don't-invent. Cover every case in the <behavior> block, including the SQLite insertability of the new enum value and the carve-out exclusions. Keep SQL driver-portable.

Directive prose only — do NOT inline code blocks in this action.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/Domain/Sync/SupplierDbSyncStaleCostTest.php tests/Feature/Domain/Sync/SupplierDbSyncStaleSupplierTest.php tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php tests/Feature/Sync/SupplierDbSyncCommandTest.php</automated>
  </verify>
  <done>New stale-cost test passes and all three pre-existing supplier-sync tests stay green (regression). A costed product with no valid supplier offer produces exactly one unresolved stale_cost_no_supplier ImportIssue (idempotent on re-run), carve-out products produce none, NULL-cost products produce none, buy_price is never modified, --dry-run writes nothing, and the new enum value inserts cleanly on SQLite (proving the migration).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

No new trust boundaries. Both commands already read the remote supplier MySQL / Woo REST and write only the local DB; this change narrows writes (Part 1 writes buy_price less often) and adds a read-surfacing row (Part 2).

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-uza-01 | Tampering (data integrity) | woo:import buy_price overwrite | mitigate | Conditional payload key stops Woo COG re-cementing a wrong cost; supplier feed stays authoritative. Covered by Task 1 tests. |
| T-uza-02 | Information disclosure / silent failure | supplier:db-sync stale cost | mitigate | Emit STALE_COST_NO_SUPPLIER ImportIssue (audit trail) instead of silently leaving a stale cost driving prices. Cost never mutated. Covered by Task 2 tests. |
| T-uza-03 | Tampering (schema parity) | import_issues.issue_type ENUM | mitigate | Driver-guarded migration (MariaDB MODIFY / SQLite CHECK rebuild) — the known SQLite↔MariaDB strict trap. Test inserts the new value on SQLite; prod path mirrors the ftp_pull_failed precedent. |
| T-uza-SC | Tampering | npm/composer installs | accept | No new packages installed. |
</threat_model>

<verification>
- `php artisan test tests/Feature/Sync tests/Feature/Domain/Sync` — full sync suites green (both new tests + all pre-existing regression tests).
- `vendor/bin/pint --dirty` — style clean on touched files.
- Manual sanity (optional, no deploy): `php artisan migrate --pretend` shows the import_issues ENUM ALTER on a mysql connection; on the SQLite test DB the migration runs inside RefreshDatabase.
</verification>

<success_criteria>
- woo:import-products preserves an existing non-null local buy_price on update, seeds it on create / when null, and --with-supplier still overrides. (T-uza-01)
- supplier:db-sync emits an idempotent, unresolved STALE_COST_NO_SUPPLIER ImportIssue for a costed product with no valid supplier offer, respecting carve-outs, never changing the cost, running by default in the scheduled path. (T-uza-02)
- The new issue_type is accepted by MariaDB (prod) and SQLite (tests) via the driver-guarded migration, and is triageable in the Filament ImportIssueResource. (T-uza-03)
- All pre-existing sync tests remain green. Committed locally only — no push, no deploy.
- buildBestOfferMap selection logic is untouched (out-of-scope-pending-operator-confirmation).
</success_criteria>

<output>
Create `.planning/quick/260809-uza-supplier-cost-feedback-loop/260809-uza-SUMMARY.md` when done.
</output>
