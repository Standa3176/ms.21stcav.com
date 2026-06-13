---
quick_id: 260613-dir
mode: quick
type: execute
status: ready
created: 2026-06-13
description: New `brands:dedupe` artisan that finds case-insensitive duplicate Woo product_brand terms, reassigns MS products.brand_id from non-canonical → canonical, and optionally deletes the Woo-side duplicate terms.
files_modified:
  - app/Console/Commands/DedupeBrandsCommand.php
  - app/Providers/AppServiceProvider.php
  - tests/Feature/Console/DedupeBrandsCommandTest.php
  - .planning/STATE.md
autonomous: true
must_haves:
  truths:
    - "`php artisan brands:dedupe --dry-run` prints 3-section plan (groups / reassignments / Woo deletes if --delete-empty-woo-terms) and writes nothing."
    - "`php artisan brands:dedupe` reassigns MS products.brand_id from every non-canonical duplicate to the canonical id."
    - "Canonical selection is deterministic: highest Woo `count` DESC, tie-break by lowest term id ASC."
    - "`--delete-empty-woo-terms` runs AFTER all reassignments and DELETEs the now-empty source terms from Woo."
    - "Re-running the command after a successful dedupe is a no-op (groups_found=0)."
    - "Re-running --delete on already-deleted terms increments `already_deleted` (not `errors`) — idempotent."
    - "Every reassignment and every Woo deletion (incl. errors and 404s) writes an audit_log row with from_id / to_id / from_name / to_name / products_affected."
    - "All Woo writes route through WooClient (no direct Http:: / Guzzle / SDK)."
  artifacts:
    - path: "app/Console/Commands/DedupeBrandsCommand.php"
      provides: "brands:dedupe artisan + per-source DB::transaction reassignment + optional gated Woo DELETE"
      min_lines: 240
    - path: "tests/Feature/Console/DedupeBrandsCommandTest.php"
      provides: "10 Pest cases A-J covering happy path, canonical selection, idempotence, --delete-empty-woo-terms gating + 404/5xx handling"
      min_lines: 320
  key_links:
    - from: "DedupeBrandsCommand::perform"
      to: "WooClient::get('products/brands', ...) + WooClient::delete('products/brands/{id}', ['force'=>true])"
      via: "constructor DI"
      pattern: "WooClient.*\\$woo"
    - from: "DedupeBrandsCommand reassign branch"
      to: "DB::table('products')->where('brand_id', $sourceId)->update(['brand_id' => $canonicalId])"
      via: "DB::transaction per source"
      pattern: "DB::transaction"
    - from: "DedupeBrandsCommand audit calls"
      to: "Auditor::record('brands.dedupe_reassigned' | 'brands.dedupe_woo_term_deleted' | 'brands.dedupe_woo_term_already_deleted' | 'brands.dedupe_woo_term_error', ...)"
      via: "constructor DI"
      pattern: "Auditor.*record"
    - from: "AppServiceProvider::$commands"
      to: "DedupeBrandsCommand::class"
      via: "Console kernel registration"
      pattern: "DedupeBrandsCommand::class"
---

<objective>
Quick task **260613-dir**. New `brands:dedupe` artisan that finds case-insensitive duplicate brand terms in Woo's `product_brand` taxonomy and merges MS `products.brand_id` from non-canonical → canonical. Optionally deletes the Woo-side duplicate terms via gated `--delete-empty-woo-terms` flag.

**Why now:** 260611-sr7 backfilled 3,106 brand_ids via name-first-word heuristics. Years of legacy WC imports left the Woo `product_brand` taxonomy with case-mismatch dupes ("Poly" vs "poly"), trailing-whitespace dupes (" Logitech " vs "Logitech"), and other exact-name dupes. After the backfill, MS products are now pointing at a mix of canonical AND duplicate Woo term ids — every duplicate that owns a product is a fragmented brand surface on the storefront (separate `/product-brand/{slug}/` landing pages, split product counts, split SEO juice).

**Purpose:** One-shot operator-triggered command that:
1. Pages through Woo `/products/brands?per_page=100` collecting every term.
2. Groups by `strtolower(trim($name))`.
3. For each group with size > 1: picks canonical by highest `count` DESC, tie-break lowest id ASC.
4. Reassigns MS `products.brand_id` from each non-canonical source to the canonical via `DB::table('products')->update`.
5. **Only if `--delete-empty-woo-terms`**: AFTER all reassignments, DELETEs the Woo source terms via `WooClient::delete("products/brands/{id}", ['force' => true])`.

**Out of scope (explicitly):**
- Fuzzy / alias matching (e.g. "HP" vs "Hewlett-Packard"). Aliases require operator judgement — handled via the existing Filament brand-mapping UI, NOT this command.
- Scheduled cron. Brand dupes don't accumulate fast enough to need automation; operator-triggered keeps this safe.
- Backfilling new brand_ids. 260611-sr7 owns that surface; this command only moves products BETWEEN existing brand ids.
- Touching `BackfillProductBrandFromNameCommand`, `BackfillCategoryFromWooCommand`, `BackfillMerchantFeedCommand`, `TaxonomyResolver`. UNTOUCHED.

**Output:**
- New `app/Console/Commands/DedupeBrandsCommand.php` (~240 lines).
- Registered in `app/Providers/AppServiceProvider::$commands`.
- New `tests/Feature/Console/DedupeBrandsCommandTest.php` with 10 Pest cases A-J.
- STATE.md row + full Pest regression baseline confirmed.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Console/Commands/BackfillProductBrandFromNameCommand.php
@app/Console/Commands/PushVisibilityToWooCommand.php
@app/Domain/Sync/Services/WooClient.php
@app/Foundation/Audit/Services/Auditor.php
@app/Providers/AppServiceProvider.php
@tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php
@tests/Feature/Console/PushVisibilityToWooCommandTest.php

<interfaces>
Pre-verified during planning (no executor re-discovery needed):

**WooClient::delete() — EXISTS** (app/Domain/Sync/Services/WooClient.php line 168):
```php
public function delete(string $endpoint, array $payload = []): array
{
    return $this->writeOrShadow('DELETE', $endpoint, $payload);
}
```
Full IntegrationLogger audit trail + correlation_id wiring already inherited via `writeOrShadow → writeLive`. Use directly. The `$payload` arg accepts `['force' => true]` for WP REST hard-delete semantics — the Automattic SDK's `$sdk->delete($endpoint, $payload)` forwards it as a query-string param. NO new WooClient method needed.

**WooClient::get('products/brands', $query) — proven shape** (used by `BackfillCategoryFromWooCommand` for `/products/categories` parity; Woo REST is symmetrical between categories + brands). Expected response per page: list of `{ id: int, name: string, slug: string, count: int, ... }`.

**Auditor::record signature** (app/Foundation/Audit/Services/Auditor.php line 22):
```php
public function record(string $action, array $context = []): void
```
Returns void. Threads correlation_id from `Context::get('correlation_id')` automatically.

**Product brand_id column**: nullable INT FK to Woo term id. BackfillProductBrandFromNameCommand's candidate query uses `whereNull('brand_id')->orWhere('brand_id', 0)` — both nullable AND 0-sentinel forms exist in the wild. **For dedupe**: query `where('brand_id', $sourceId)` returns ONLY rows that were explicitly pointing at the source term (won't accidentally sweep NULLs into the canonical bucket — that would corrupt the 260611-sr7 backfill outcome).

**BaseCommand pattern**: every recent command extends `App\Console\Commands\BaseCommand` and implements `protected function perform(): int`. Constructor DI is standard.

**Test rig pattern** (mirrors `BackfillProductBrandFromNameCommandTest`):
- `RefreshDatabase` + Pest functional style.
- Stub WooClient via container `instance(WooClient::class, $stub)` with an anonymous subclass overriding `get()` + `delete()` to return canned payloads / throw canned exceptions / record invocations.
- Assertions: `$this->artisan('brands:dedupe', [...])->assertExitCode(0)`; DB state via `DB::table('products')->where('brand_id', $canonicalId)->count()`; audit log via `Spatie\Activitylog\Models\Activity::query()->where('description', 'brands.dedupe_*')`.

**Symfony Command exit codes**: `SymfonyCommand::SUCCESS = 0`, `FAILURE = 1`. Per-source errors are reported via counter table, NOT a non-zero exit (matches PushVisibilityToWooCommand precedent).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Pre-flight probe + 5-line scope confirmation (no commit)</name>
  <files>(read-only — no writes)</files>
  <action>
Confirm 4 facts before writing code. NO commit. Output a 5-line scope block to the chat:

1. `WooClient::delete(string $endpoint, array $payload = []): array` EXISTS at app/Domain/Sync/Services/WooClient.php line 168 (pre-verified in plan interfaces; re-grep with `Grep "public function delete" app/Domain/Sync/Services/WooClient.php` to confirm before coding). NO WooClient method addition needed — strikes the conditional "maybe add delete()" branch from this plan.

2. Confirm Woo `/products/brands` REST response shape returns `id`, `name`, `slug`, `count` as top-level array keys. Use `Grep "products/brands" app/` to check whether any existing code already touches this endpoint; if `BackfillProductBrandFromNameCommand`'s TaxonomyResolver path or any other class already paginates `/products/brands`, mirror its loop variable naming for code-review consistency.

3. Confirm `products.brand_id` column is nullable INT via `Grep "brand_id" database/migrations/` — should turn up at least one ALTER/CREATE that documents the type. If nullable AND 0-sentinel both exist (260611-sr7 candidate query confirms), the dedupe `where('brand_id', $sourceId)` slice safely excludes NULLs/zeros.

4. Confirm `Auditor::record(string $action, array $context = []): void` signature (pre-verified line 22). Confirm Auditor is `final` (line 20) → cannot be Mockery-mocked → Spatie `Activity::query()` post-call assertion is the test pattern (matches 260611-sr7's BackfillProductBrandFromNameCommandTest + 260611-rl4's AutoSyncDivergenceCommandTest).

Write a 5-line scope confirmation block in chat (no file write):
- ✅ WooClient::delete exists, no addition needed
- ✅ /products/brands response keys: id, name, slug, count
- ✅ products.brand_id: nullable INT (NULLs/0 excluded by source-equality slice)
- ✅ Auditor signature confirmed, test uses Activity::query()
- → proceed with Task 2 single-commit path (no WooClient.php in files_modified)
  </action>
  <verify>
    <automated>grep -c "public function delete" app/Domain/Sync/Services/WooClient.php | grep -v '^#' | grep -qE '^[1-9]'</automated>
  </verify>
  <done>5-line scope block written to chat; no files modified; no commit.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: DedupeBrandsCommand + AppServiceProvider registration (atomic commit)</name>
  <files>app/Console/Commands/DedupeBrandsCommand.php, app/Providers/AppServiceProvider.php</files>
  <action>
Create `app/Console/Commands/DedupeBrandsCommand.php` extending `BaseCommand`. Implement `protected function perform(): int`. Constructor DI: `WooClient $woo`, `Auditor $auditor`. Class is NOT `final` (mirrors PushVisibilityToWooCommand + BackfillProductBrandFromNameCommand — test rigs use container `instance()` swaps, no subclassing).

**Signature:**
```
brands:dedupe
  {--dry-run : Print plan without writes}
  {--delete-empty-woo-terms : After reassignment, DELETE the duplicate Woo terms via WooClient::delete (default off — gated)}
```

**Private consts (grep-discoverable per drift-prevention contract):**
- `BRANDS_PER_PAGE = 100` — Woo REST per-page cap.
- `WOO_DELETE_THROTTLE_USEC = 200_000` — 200ms pacing between live Woo DELETEs (mirrors PushVisibilityToWooCommand line 167 + BackfillCategoryFromWooCommand cadence).

**Docblock contract (top of class):** Document:
- Scope: ONLY case-insensitive trimmed name matches. Fuzzy aliases out of scope.
- Canonical selection: highest `count` DESC, tie-break lowest `id` ASC.
- Two-phase safety: reassignment SAFE (products still have a valid brand); Woo DELETE RISKY (other plugins might reference) — gated default-off.
- Idempotence: re-running on already-deduped state is a no-op (`groups_found=0`); re-running --delete on already-deleted terms increments `already_deleted` not `errors`.
- Drift-prevention: ALL Woo writes via `WooClient` — no direct HTTP. If a future quick task adds variation handling or a 4th brand surface, it MUST extend this command, NOT re-implement the pagination + grouping elsewhere.

**`perform()` logic — implement in this order:**

1. **Parse options** — `$dryRun = (bool) $this->option('dry-run')`, `$deleteEmpty = (bool) $this->option('delete-empty-woo-terms')`.

2. **Page through Woo brands** — loop `$page = 1` calling `$this->woo->get('products/brands', ['per_page' => self::BRANDS_PER_PAGE, 'page' => $page])` until the response is `[]` (empty array). For each row, push `['id' => (int)$row['id'], 'name' => (string)$row['name'], 'count' => (int)($row['count'] ?? 0)]` into `$brands`. Defensive: skip rows where `id <= 0` or `name === ''`. Wrap the per-page GET in try/catch — on throw, log warning + abort with FAILURE exit (operator re-runs after fixing connectivity; partial pagination would give a misleading dedupe plan).

3. **Group by lowercased + trimmed name** — `$groups[strtolower(trim($name))][] = $brand`. Filter to groups with `count($group) > 1`. Counter `groups_found = count($filtered)`.

4. **Determine canonical + merge sources per group** — for each group: sort by `count DESC, id ASC` (write a `usort` closure inline, no helper extraction — visible at the call site). First element = canonical, rest = merge sources.

5. **Pre-count affected products per source** — for each merge source, query `DB::table('products')->where('brand_id', $sourceId)->count()`. Store as `$plannedAffected[$sourceId]`. Used for both dry-run display and live counter.

6. **Dry-run branch** — print 3 sections:
   - **Section 1 — Duplicate groups**: table `[Group key, Canonical id, Canonical name, Canonical count, Source ids]`.
   - **Section 2 — Reassignment plan**: table `[Source id, Source name, Source count, Canonical id, Products affected]`.
   - **Section 3 (only if --delete-empty-woo-terms)** — table `[Source id, Source name, Will delete via Woo? force=true]`.
   - Print counter summary: `groups_found / would_merge / would_reassign_products / would_delete_woo_terms`. Return `SUCCESS`. NO writes.

7. **Live branch — phase A: reassignments first** — for each group, for each merge source:
   - `DB::transaction(function () use (...): int { return DB::table('products')->where('brand_id', $sourceId)->update(['brand_id' => $canonicalId, 'updated_at' => now()]); });`
   - Capture `$affected = (int) (closure return)`.
   - Counter `products_reassigned += $affected`, `sources_merged++`.
   - Audit log call: `$this->auditor->record('brands.dedupe_reassigned', ['from_id' => $sourceId, 'to_id' => $canonicalId, 'from_name' => $sourceName, 'to_name' => $canonicalName, 'products_affected' => $affected]);`.
   - On any throw (DB level) the transaction rolls back; bump `errors++`, audit `brands.dedupe_reassign_failed` with the throw message, continue with next source (do NOT abort the whole run — partial dedupe is still a net win; batch continues).

8. **Live branch — phase B: Woo deletes (only if --delete-empty-woo-terms AND not dry-run)** — AFTER all reassignments complete, for each merge source:
   - `try { $this->woo->delete("products/brands/{$sourceId}", ['force' => true]); $wooTermsDeleted++; $this->auditor->record('brands.dedupe_woo_term_deleted', ['source_id' => $sourceId, 'source_name' => $sourceName, 'canonical_id' => $canonicalId]); }`
   - `catch (\Throwable $e)`: detect 404 vs other. Look for HTTP 404 by sniffing `$e->getCode() === 404` OR by string-matching `'term does not exist'` / `'rest_term_invalid'` in `$e->getMessage()` (defensive — WP REST returns codes through HttpClientException + message). On 404: `$alreadyDeleted++`; audit `brands.dedupe_woo_term_already_deleted` with source_id (info-level — idempotent re-run signal, NOT an error). On any other throw: `$errors++`; audit `brands.dedupe_woo_term_error` with source_id + error message (warning-level); batch continues.
   - `usleep(self::WOO_DELETE_THROTTLE_USEC);` after every Woo call (success OR throw — pacing applies whether or not the call succeeded).

9. **Per-group summary table — always (live AND dry-run)** — at the end, before final counter table, print a per-group breakdown so operator sees what happened: `[Group key, Canonical, Sources merged (ids), Products reassigned, Woo terms deleted (if --delete-empty-woo-terms)]`.

10. **Final counter table** — `groups_found / sources_merged / products_reassigned / woo_terms_deleted / already_deleted / errors`. Return `SymfonyCommand::SUCCESS` even if `errors > 0` (per-source failures reported, not fatal — operator decides next action; matches PushVisibilityToWooCommand precedent). FAILURE exit reserved for: brand-pagination GET throw in step 2.

**Run banner** at start: `$this->info(($dryRun ? '[dry-run] ' : '[LIVE] ') . 'brands:dedupe — delete_empty_woo_terms=' . ($deleteEmpty ? 'true' : 'false'));`

**`AppServiceProvider` registration** — open `app/Providers/AppServiceProvider.php`, find the `$commands` registration block (mirrors the 260611-sr7 BackfillProductBrandFromNameCommand registration site). Add `DedupeBrandsCommand::class` to the array. Keep alphabetical ordering if the existing block is sorted; otherwise append.

**Inline scope-guardrail comment** above the `perform()` method (mirrors the docblock contract — duplicated intentionally so future grep on `brands.dedupe_reassigned` or `BRANDS_PER_PAGE` lands on the rationale):
```
// Drift-prevention: ALL Woo writes via $this->woo (WooClient). Direct Http:: /
// Guzzle / new AutomatticClient() in this command would bypass IntegrationLogger
// audit trail + correlation_id threading. If a future quick task adds variation
// brand dedup or a 4th brand surface, EXTEND this command — do not re-implement
// the pagination + grouping + canonical-selection elsewhere.
```

**Atomic commit (one of two committing tasks):**
`feat(brands): brands:dedupe command + AppServiceProvider registration (260613-dir)`

Files committed: `app/Console/Commands/DedupeBrandsCommand.php` + `app/Providers/AppServiceProvider.php`.
  </action>
  <verify>
    <automated>php artisan list 2>&1 | grep -E 'brands:dedupe\s'</automated>
  </verify>
  <done>
`php artisan brands:dedupe` resolves via `artisan list`. Class extends BaseCommand. Both private consts present (`BRANDS_PER_PAGE`, `WOO_DELETE_THROTTLE_USEC`). Both Woo write paths go through `$this->woo` — no direct HTTP. AppServiceProvider registers the command class. Atomic commit landed.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Pest cases A-J for DedupeBrandsCommand (atomic commit)</name>
  <files>tests/Feature/Console/DedupeBrandsCommandTest.php</files>
  <action>
Create `tests/Feature/Console/DedupeBrandsCommandTest.php` mirroring the structure of `tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php` (functional Pest style + `RefreshDatabase` + container `instance()` stub for WooClient + Spatie Activity post-call assertion for audit log).

**Test helper / fixture** (top of file):
- Anonymous subclass of `WooClient` overriding `get()` + `delete()` only. The subclass takes:
  - `$brandsByPage`: `array<int, array<int, array{id:int,name:string,count:int}>>` keyed by `page` number — the test stages exact pagination behaviour.
  - `$deleteBehaviour`: `array<int, 'ok'|'404'|'5xx'>` keyed by source id — controls per-call outcome.
  - Internal `$invocations` array recording every `get()` + `delete()` call for assertions.
- Inject via `$this->app->instance(WooClient::class, $stub);` in each test's setup or inline before `artisan(...)`.

**Helper to seed brand-grouping products**: small closure that creates N Products with `brand_id => X` so the DB-side reassignment assertions read cleanly.

**Cases A-J** — each test name starts with "it_" per Pest convention:

**A — No duplicates** — Woo returns a single page of unique brands (e.g. 3 distinct names). After `artisan('brands:dedupe')`: `groups_found=0`, no DB writes, no Woo deletes attempted, no `brands.dedupe_*` audit rows.

**B — Canonical = highest count** — group: `{id:10, name:'Poly', count:50}` + `{id:11, name:'poly', count:3}`. Seed 3 MS products with `brand_id=11`. After live run (no `--delete-empty-woo-terms`): all 3 products now have `brand_id=10`. Counters: `groups_found=1, sources_merged=1, products_reassigned=3, woo_terms_deleted=0`. Audit row `brands.dedupe_reassigned` exists with `from_id=11, to_id=10, products_affected=3`.

**C — Tie-break by lowest id** — group: `{id:5, name:'Bose', count:8}` + `{id:8, name:'bose', count:8}`. Counts tied. Canonical MUST be id=5 (lowest). Seed 2 MS products with `brand_id=8`. After run: both products on `brand_id=5`. Assertion specifically targets the tie-break determinism.

**D — Case mismatch (mixed case)** — `{id:10, name:'Poly', count:50}` + `{id:11, name:'POLY', count:30}` + `{id:12, name:'poly', count:5}`. Grouped under key `'poly'`. Canonical = id=10. Seed 4 products on id=11 + 1 on id=12. After run: all 5 sitting on id=10. `sources_merged=2`.

**E — Whitespace trim** — `{id:20, name:'Logitech', count:100}` + `{id:21, name:' Logitech ', count:2}`. Grouped under `'logitech'`. Canonical = id=20. Seed 1 product on id=21. After run: product on id=20.

**F — --dry-run** — Same fixture as Case B (3 products on id=11). Run with `--dry-run`. After run: products STILL on `brand_id=11` (no writes). Output contains the 3 plan sections (assert via `expectsOutputToContain(...)` or capture stdout). No `brands.dedupe_*` audit rows. WooClient stub records ZERO `delete()` invocations (and zero PUT/POST).

**G — --delete-empty-woo-terms happy path** — Fixture: 2 duplicate groups, 4 total merge sources. WooClient stub returns `ok` for every `delete()` call. Run with `--delete-empty-woo-terms` (no `--dry-run`). Assertions:
- All MS products reassigned to canonicals (DB check).
- Stub's `$invocations` records exactly 4 `delete("products/brands/{$sourceId}", ['force' => true])` calls — assert the endpoints AND the `force => true` payload.
- Audit rows: 4× `brands.dedupe_reassigned` + 4× `brands.dedupe_woo_term_deleted`.
- Counter `woo_terms_deleted=4`, `errors=0`, `already_deleted=0`.
- **Critical ordering assertion**: all reassignments precede all Woo deletes. The stub records timestamps OR an autoincrementing call-order counter; assert that every `update.brand_id` audit row was created before every `dedupe_woo_term_deleted` row. (This catches the "phase A before phase B" invariant — the whole reason --delete is gated.)

**H — --delete-empty-woo-terms with Woo 5xx** — Fixture: 1 group with 1 merge source. WooClient stub throws a synthetic `\RuntimeException` (or `HttpClientException` if available) with code 500 from `delete()`. Assertions:
- Reassignment STILL happened (DB check — products on canonical).
- `errors=1`, `woo_terms_deleted=0`, `already_deleted=0`.
- Audit row `brands.dedupe_woo_term_error` exists with the source id and the error message.
- Audit row `brands.dedupe_reassigned` ALSO exists (Phase A independent of Phase B failure).
- Command exit code = `SUCCESS` (per-source Woo failures are not fatal).

**I — --delete-empty-woo-terms with Woo 404 (idempotent re-run signal)** — Fixture: 1 group with 1 merge source. Stub throws `\RuntimeException("rest_term_invalid: Term does not exist", 404)` from `delete()`. Assertions:
- Reassignment STILL happened.
- `already_deleted=1`, `errors=0` (404 is NOT an error — operator re-ran after a prior --delete already succeeded; the term is gone, which is the desired end-state).
- Audit row `brands.dedupe_woo_term_already_deleted` exists; NO `brands.dedupe_woo_term_error` row.

**J — DB::transaction rollback on per-source update failure** — Simulate a DB throw INSIDE the per-source transaction. Approach: register a `DB::beforeExecuting` callback before invoking artisan that throws on the specific UPDATE statement targeting `brand_id = $sourceId`. Other sources in the same run proceed normally.
- Setup: 2 duplicate groups. Source A's update will be poisoned via `DB::beforeExecuting`; source B's update runs cleanly.
- Assertions:
  - Source A's products STILL have `brand_id = $sourceA_id` (transaction rolled back — partial state never persisted).
  - Source B's products successfully migrated to canonical (batch continues).
  - `errors=1, sources_merged=1, products_reassigned=N_for_source_B_only`.
  - Audit row `brands.dedupe_reassign_failed` exists for source A; `brands.dedupe_reassigned` exists for source B.
  - Command exit code = `SUCCESS`.

**Atomic commit (second of two committing tasks):**
`test(brands): brands:dedupe Pest cases A-J (260613-dir)`
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php --stop-on-failure 2>&1 | tail -20</automated>
  </verify>
  <done>10/10 Pest cases GREEN. Stub's invocation-recording proves Woo `delete("products/brands/{$id}", ['force'=>true])` is called only when `--delete-empty-woo-terms` is on, only AFTER reassignment, exactly once per source. Phase A → Phase B ordering asserted in Case G. Audit log namespaces covered: `brands.dedupe_reassigned`, `brands.dedupe_reassign_failed`, `brands.dedupe_woo_term_deleted`, `brands.dedupe_woo_term_already_deleted`, `brands.dedupe_woo_term_error`. Atomic commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 4: Verification — artisan resolves + focused suite + touched-area regression (no commit)</name>
  <files>(verification only — no writes)</files>
  <action>
NO commit on this task. Run these in order; capture exit codes + counters to chat:

1. **Artisan registration check**:
   `php artisan list | grep brands:dedupe`
   Must resolve to exactly one row: `brands:dedupe   Find...merge duplicate Woo brand terms...`

2. **Focused suite**:
   `vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php`
   Expect: 10/10 GREEN.

3. **Touched-area regression — close kin** (commands that share BaseCommand + Auditor + WooClient + AppServiceProvider plumbing):
   ```
   vendor/bin/pest \
     tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php \
     tests/Feature/Console/BackfillCategoryFromWooCommandTest.php \
     tests/Feature/Console/PushVisibilityToWooCommandTest.php
   ```
   Expect: all GREEN (baselines: 9 + n + 6 from 260611-sr7 / 260607-v5g / 260611-f1y respectively — confirm against historical counts in STATE.md if exact numbers shift). Any new failure here = the AppServiceProvider edit broke command registration ordering or the new command's docblock literally clashes with a scanned arch test — investigate before continuing.

4. **WooClient-touching arch tests** (defensive — confirms Task 1's "no WooClient.php addition" decision held; if Task 2 accidentally edited WooClient.php, this would catch it):
   `vendor/bin/pest --filter='WooClient' 2>&1 | tail -20`
   Expect: pre-existing pass count unchanged; 0 new failures.

5. **Full Pest baseline reconciliation** (subject to the same Windows-Herd 512MB OOM caveat carried through every recent quick task starting at 260611-qcq):
   Try `vendor/bin/pest 2>&1 | tail -5`.
   Target delta vs 260611-sr7 baseline: **+10 pass / 0 new fails / 0 new risky**. Expected new total around 2,052 pass.
   If full-suite OOMs at the chunk boundary (pre-existing infrastructure issue, NOT introduced by 260613-dir): document the OOM in STATE.md exactly as 260611-sr7 did, then assert touched-area equivalence as the proxy signal (steps 2 + 3 + 4 all GREEN). DO NOT mark Task 4 failed for the OOM alone — that's environmental.

Write a verification block to chat with:
- ✅/❌ for each of the 5 steps
- Focused suite test count + duration
- Full-suite delta if obtainable; OOM-deferred note if not
- Any new failure → STOP. Do not proceed to Task 5.
  </action>
  <verify>
    <automated>php artisan list 2>&1 | grep -c "brands:dedupe" | grep -qE '^1$' && vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php --stop-on-failure 2>&1 | tail -3 | grep -qE 'Tests:.*10 passed'</automated>
  </verify>
  <done>Steps 1-4 all GREEN. Step 5 either GREEN with +10/0/0 delta OR OOM-deferred with explicit note in chat. No new failures in touched-area suites. Verification block written to chat; no files modified; no commit.</done>
</task>

<task type="auto">
  <name>Task 5: STATE.md row + frontmatter rotate (atomic commit)</name>
  <files>.planning/STATE.md</files>
  <action>
Open `.planning/STATE.md`. Rotate the existing `stopped_at:` value down to a new `old_stopped_at:` row (preserving the chain of every recent quick task — currently `stopped_at: 260611-sr7`, will become `old_stopped_at: 260611-sr7`). Write a new top-level `stopped_at:` row for 260613-dir capturing in the same dense one-paragraph style as 260611-sr7 / 260611-s2d / 260611-rl4:

**Contents the row MUST capture (mirror predecessor rigor):**
- One-sentence "Completed quick task 260613-dir — `brands:dedupe` ..." summary.
- The scope: case-insensitive trimmed-name matching ONLY; fuzzy/alias matching out of scope.
- Canonical-selection rule (highest count DESC, tie-break lowest id ASC).
- Two-phase safety: reassignment SAFE, Woo DELETE RISKY + gated.
- The 6 counters (`groups_found / sources_merged / products_reassigned / woo_terms_deleted / already_deleted / errors`).
- The 5 audit log namespaces (`brands.dedupe_reassigned`, `brands.dedupe_reassign_failed`, `brands.dedupe_woo_term_deleted`, `brands.dedupe_woo_term_already_deleted`, `brands.dedupe_woo_term_error`).
- All Pest cases A-J + their assertions in compressed form.
- Full Pest delta vs 260611-sr7 baseline (or OOM-deferred caveat carried forward).
- Files touched: `DedupeBrandsCommand.php` + `AppServiceProvider.php` + `DedupeBrandsCommandTest.php` + this STATE.md row.
- Atomic commits: 3 total (Task 2 feat + Task 3 test + Task 5 docs).
- **POST-DEPLOY OPERATOR ACTION** (numbered, in the style of every recent stopped_at):
  1. Deploy all 3 commits.
  2. `php artisan brands:dedupe --dry-run` confirms the plan size + which group keys are dupes. Operator eyeballs the canonical-vs-source choices for sanity (the resolver picked highest-count, but a human gut-check matters — e.g. if "Poly" 50 vs "poly" 3 the operator may notice the brand actually ships with capital "Poly" today, which matches).
  3. Live run `php artisan brands:dedupe`. MS products reassigned; Woo terms untouched.
  4. Storefront spot-check `/product-brand/poly/` (or whichever canonical you picked) — should now show MORE products than before. The duplicate `/product-brand/poly-1/` (Woo's auto-slug-collision fallback) is still live BUT now empty.
  5. **Risky step — only if comfortable**: `php artisan brands:dedupe --delete-empty-woo-terms`. Woo term DELETE on the now-empty source ids. Re-running this is safe (already_deleted++ on 404). After this, the empty duplicate landing pages 404 — fine, those URLs had no inbound traffic worth preserving (auto-slug-collision dupes never ranked).
  6. Spot-check `/wp-json/wc/v3/products/brands?per_page=100` — duplicate names should be gone.
- **What this does NOT do**: no fuzzy aliasing (HP vs Hewlett-Packard) — operator handles via Filament brand-mapping UI; no scheduled cron; no Woo PUT against products with `brand_id=$sourceId` (we update MS-side only; Woo's `_product_brand_id` meta is silent-skipped by WooFieldComparator per 260610-qc4, so no flood of sync_diffs); no rollback (if operator picks wrong canonical, a follow-up `--skus=` reassignment via 260611-sr7's `products:backfill-brand-from-name` is the manual unwind path); BackfillProductBrandFromNameCommand / BackfillMerchantFeedCommand / BackfillCategoryFromWooCommand / TaxonomyResolver UNTOUCHED.

Update the frontmatter:
- `last_updated: "2026-06-13T..."` (ISO timestamp from the actual run).
- `last_activity: 2026-06-13`.
- Progress counters unchanged (this is a quick task — doesn't move phase progress).

**Atomic commit:**
`docs(state): 260613-dir brands:dedupe shipped (260613-dir)`
  </action>
  <verify>
    <automated>grep -c "260613-dir" .planning/STATE.md | grep -v '^#' | grep -qE '^[1-9]'</automated>
  </verify>
  <done>STATE.md has a new top-level `stopped_at:` paragraph for 260613-dir covering all 6 counters, all 5 audit namespaces, all 10 Pest cases, the 6-step post-deploy operator sequence, and the "what this does NOT do" boundaries. The previous 260611-sr7 row rotated to `old_stopped_at`. Frontmatter timestamps updated. Atomic commit landed. Total commits across this quick task: 3 (Task 2 + Task 3 + Task 5).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| operator CLI → MS DB | Operator triggers `brands:dedupe`; command mutates `products.brand_id` for many rows in one shot |
| MS app → Woo REST | Command POSTs (via PUT-as-POST) and DELETEs against `/wp-json/wc/v3/products/brands/{id}?force=true` — affects live storefront term taxonomy |
| Woo REST → other plugins | Yoast SEO schema, Google Listings & Ads feed, custom Flatsome theme code may reference the deleted term ids |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-260613-dir-01 | Tampering | `DB::table('products')->update(['brand_id' => $canonicalId])` mass UPDATE | mitigate | Wrap each per-source update in `DB::transaction` (Pest Case J asserts rollback); `where('brand_id', $sourceId)` slice EXCLUDES NULL/0 brand_id rows so the 260611-sr7 backfill outcome is preserved; canonical selection is deterministic (highest count DESC, tie-break lowest id ASC) — re-running produces the same canonical, not a flip-flop |
| T-260613-dir-02 | Repudiation | "Who reassigned 3,000 products at 02:14 UTC?" | mitigate | Every reassignment writes `brands.dedupe_reassigned` audit row via Auditor (`from_id, to_id, from_name, to_name, products_affected`); correlation_id auto-threaded; per-source rollback writes `brands.dedupe_reassign_failed` |
| T-260613-dir-03 | Denial of Service | Woo REST rate-limit on bulk DELETE | mitigate | `WOO_DELETE_THROTTLE_USEC = 200_000` (200ms) sleep between every Woo DELETE call (success OR throw); WooClient's built-in 429 backoff already handles bursty pacing as a backstop |
| T-260613-dir-04 | Tampering | `--delete-empty-woo-terms` removes Woo terms other plugins may reference (Yoast schema, GLA feed, Flatsome theme overrides) | mitigate | Flag is DEFAULT OFF; gated language in docblock + run banner ("delete_empty_woo_terms=true/false"); operator runs reassignment WITHOUT --delete first, spot-checks storefront, then opts in to the destructive step. Reassignment phase has zero blast radius on third-party plugins (those plugins reference TERM ids, not the products → brand mapping) |
| T-260613-dir-05 | Information Disclosure | Brand name corpus may include internal-only brand names (e.g. private-label test brands) | accept | brands:dedupe outputs go to stdout + audit log only — neither leaves the operator's shell. No PII; brand names are public storefront data. |
| T-260613-dir-06 | Elevation of Privilege | Command discovers and acts on duplicate brand ids without confirmation prompt | accept | Default mode (no --delete-empty-woo-terms) only touches MS DB — fully reversible via 260611-sr7's `products:backfill-brand-from-name --skus=...` if operator picked the wrong canonical. Default-OFF Woo DELETE flag is the actual destructive boundary; the operator opt-in IS the elevation gate. No interactive `confirm()` prompt because the dry-run output IS the confirmation surface. |
| T-260613-dir-07 | Tampering | Idempotence regression: re-running --delete on already-deleted terms could increment `errors` instead of `already_deleted`, falsely alarming operator | mitigate | Case I asserts the 404 → `already_deleted++` semantics; without this, every cron-style re-run would surface false errors and noise the operator into deploy paralysis |
| T-260613-dir-SC | Tampering | npm/composer install / package legitimacy | n/a | No new package installs; only uses already-installed `automattic/woocommerce` via existing WooClient. Package legitimacy gate not applicable. |
</threat_model>

<verification>

**Phase-level checks** (mapped to must_haves.truths):

1. ✅ `php artisan brands:dedupe --dry-run` prints 3-section plan and writes nothing.
   - Verify: Pest Case F (dry-run) — DB unchanged + plan output captured.
2. ✅ Live run reassigns MS products.brand_id from non-canonical → canonical.
   - Verify: Pest Cases B / C / D / E — DB-level `DB::table('products')->where('brand_id', $canonicalId)->count()` assertion.
3. ✅ Canonical = highest count DESC, tie-break lowest id ASC.
   - Verify: Pest Case B (count rule) + Case C (tie-break rule) — both assert specific canonical ids.
4. ✅ `--delete-empty-woo-terms` runs AFTER all reassignments and DELETEs source terms.
   - Verify: Pest Case G — stub records call ordering; assertion proves all reassign-audit rows precede all delete-audit rows.
5. ✅ Re-run after successful dedupe = no-op.
   - Verify: Pest Case A — groups_found=0 fast path; no writes / no audit rows.
6. ✅ Re-run --delete on already-deleted terms increments `already_deleted` not `errors`.
   - Verify: Pest Case I — 404 → `already_deleted++`, NOT `errors++`.
7. ✅ Every reassignment + deletion writes audit log.
   - Verify: All cases B-J — Spatie `Activity::query()->where('description', 'brands.dedupe_*')` assertion.
8. ✅ All Woo writes via WooClient.
   - Verify: Source-level — only `$this->woo->get()` and `$this->woo->delete()` are called; no `Http::`, `Guzzle`, or `new AutomatticClient()` in command source. Confirm with `grep -E "Http::|Guzzle|AutomatticClient" app/Console/Commands/DedupeBrandsCommand.php` returning zero hits.

**Touched-area regression equivalence** (Task 4 step 3): BackfillProductBrandFromNameCommandTest + BackfillCategoryFromWooCommandTest + PushVisibilityToWooCommandTest all GREEN at their existing baselines.

**Full Pest baseline target**: +10 pass / 0 new fails / 0 new risky vs 260611-sr7's ~2,042 pass / 222 skipped / 3 risky. OOM-deferred fallback acceptable per the Windows Herd 512MB infrastructure caveat documented since 260611-qcq.
</verification>

<success_criteria>

- ☑ `app/Console/Commands/DedupeBrandsCommand.php` exists, extends BaseCommand, has constructor DI on `WooClient` + `Auditor`, both private consts present, full docblock contract documented.
- ☑ `app/Providers/AppServiceProvider.php` registers `DedupeBrandsCommand::class` in the `$commands` array.
- ☑ `tests/Feature/Console/DedupeBrandsCommandTest.php` exists with 10 cases A-J all GREEN.
- ☑ Phase A (reassign) → Phase B (Woo DELETE) ordering asserted (Case G).
- ☑ Idempotent re-run semantics asserted (Case A no-op + Case I 404→already_deleted).
- ☑ DB::transaction rollback asserted (Case J).
- ☑ `--delete-empty-woo-terms` is default OFF; flag gated.
- ☑ All Woo writes via WooClient — grep on command source returns zero direct-HTTP usage.
- ☑ Audit log namespaces visible: 5 distinct `brands.dedupe_*` actions.
- ☑ STATE.md row rotated; 260613-dir entry captures all counters + 6 operator steps + boundaries.
- ☑ Exactly 3 atomic commits: feat (Task 2) + test (Task 3) + docs (Task 5).
- ☑ Touched-area regression suites GREEN.
- ☑ Full Pest delta confirmed +10/0/0 OR OOM-deferred with explicit note.
</success_criteria>

<output>

Create `.planning/quick/260613-dir-brands-dedupe-find-merge-duplicate-woo-b/260613-dir-SUMMARY.md` when done, capturing:

- One-paragraph dense summary mirroring the STATE.md row style.
- Files modified list (3 code files + STATE.md row).
- Counters + 5 audit namespaces.
- Pest cases A-J + delta vs 260611-sr7 baseline.
- 6-step post-deploy operator sequence (extracted verbatim from STATE.md).
- "What this does NOT do" boundaries.
- 3 atomic commit SHAs.
</output>
