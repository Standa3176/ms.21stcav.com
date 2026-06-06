---
phase: quick-260606-gnu
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php
  - tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php
  - routes/console.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
autonomous: true
requirements:
  - QUICK-260606-GNU-PRUNE
  - QUICK-260606-GNU-BADGE
  - QUICK-260606-GNU-SCHEDULE

must_haves:
  truths:
    - "Operator can run `php artisan suggestions:prune-orphans --dry-run` and see a count plus 20-row sample of stale competitor-only orphan suggestions without writing anything."
    - "Operator can run `php artisan suggestions:prune-orphans` and have rows older than 30 days that are BOTH off-supplier-DB AND have <2 competitors flipped to status='rejected' with rejection_reason='auto-rejected: stale competitor-only orphan (no supplier carries + <2 competitors)' and resolved_at set."
    - "Rows that fail any of the three gates (too fresh / on supplier DB / >=2 competitors) are left untouched."
    - "Mon 06:00 Europe/London cron runs the command BEFORE supplier:db-sync at 07:00 so we never prune anything that just arrived."
    - "The /admin/suggestions sidebar badge displays the 'high-confidence sourceable' count (pending + new_product_opportunity + on supplier DB + supporting_competitors >= 3), not the raw pending count."
    - "Hovering the badge shows a tooltip with the three-tier breakdown 'N high-confidence • N sourceable • N raw'."
    - "Badge color stays 'warning' and returns null when the high-confidence count is zero (no badge rendered)."
    - "The full Pest suite is green after the final commit."
  artifacts:
    - path: "app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php"
      provides: "suggestions:prune-orphans artisan command (extends BaseCommand)"
      contains: "class PruneOrphanSuggestionsCommand extends BaseCommand"
    - path: "tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php"
      provides: "2x2 matrix coverage + dry-run + --days override"
      contains: "PruneOrphanSuggestionsCommand"
    - path: "routes/console.php"
      provides: "Mon 06:00 London schedule entry placed above supplier:db-sync block"
      contains: "suggestions:prune-orphans"
    - path: "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php"
      provides: "Rewritten getNavigationBadge + new getNavigationBadgeTooltip"
      contains: "getNavigationBadgeTooltip"
  key_links:
    - from: "PruneOrphanSuggestionsCommand"
      to: "supplier_sku_cache"
      via: "NOT EXISTS subquery with LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence,'$.sku'))))"
      pattern: "NOT EXISTS.*supplier_sku_cache"
    - from: "SuggestionResource::getNavigationBadge"
      to: "supplier_sku_cache"
      via: "EXISTS subquery mirroring the on_supplier_db filter at SuggestionResource.php:247"
      pattern: "EXISTS.*supplier_sku_cache"
    - from: "routes/console.php"
      to: "suggestions:prune-orphans"
      via: "Schedule::command cron('0 6 * * 1')"
      pattern: "cron\\('0 6 \\* \\* 1'\\)"
---

<objective>
Triage the 62% orphan-rate noise out of /admin/suggestions so the operator's pending-inbox badge reflects real, actionable work.

Today the badge counts 14,263 pending rows but only 38% (~5,396) are on the supplier DB. Of those, only a much smaller slice have ≥3 competitors backing them — the "high-confidence sourceable" set the operator actually wants to see.

Two complementary changes:

1. **Auto-prune** the weakest, oldest rows (`suggestions:prune-orphans` artisan command + Mon 06:00 cron) — anything ≥30 days old, off-supplier, AND <2 competitors gets flipped to rejected. The "least valuable signal × longest wait" intersection.

2. **Rewrite the navigation badge** to count only "high-confidence sourceable" rows (pending + new_product_opportunity + on supplier DB + ≥3 competitors), with a tooltip exposing the underlying breakdown so the operator can drill into the wider pools when needed.

Purpose: Cut inbox noise without hiding signal. The pruner is deliberately conservative (only the off-supplier + low-competitor + stale intersection), and the badge change is read-only — neither closes off the operator's ability to find any row via existing filters.

Output: 4 atomic commits across 4 files. Full Pest suite green at the end.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Suggestions/Models/Suggestion.php
@app/Console/Commands/BaseCommand.php
@app/Domain/Suggestions/Console/Commands/AutoApplyMarginSuggestionsCommand.php
@app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
@routes/console.php
@database/migrations/2026_06_04_120000_create_supplier_sku_cache_table.php

<interfaces>
<!-- Canonical patterns the executor needs. Extracted from the codebase — do NOT re-explore. -->

# BaseCommand contract (app/Console/Commands/BaseCommand.php)
- `final handle(): int` is sealed — sets up `Context::add('correlation_id', $uuid)` + LogBatch wrap.
- Subclasses implement `protected function perform(): int`.
- Symfony exit codes via `Symfony\Component\Console\Command\Command as SymfonyCommand` → `return SymfonyCommand::SUCCESS;` (= 0).

# Canonical perform() shape — see AutoApplyMarginSuggestionsCommand (the closest sibling in the same domain)
- final class with `protected $signature = '<verb>:<noun> {--flag : desc} {--option= : desc}'`
- `protected $description = '...';`
- Dry-run flag is `--dry-run` (not `--live`) — read via `(bool) $this->option('dry-run')`.
- Uses `->cursor()->each(...)` for streaming counts; we use `->chunkById(500, ...)` for the bulk update path here.

# Suggestion model (app/Domain/Suggestions/Models/Suggestion.php)
- ULID PK via HasUlids — id is CHAR(26).
- Status constants: STATUS_PENDING='pending', STATUS_REJECTED='rejected', STATUS_APPLIED='applied'.
- Casts: `evidence => 'array'`, `proposed_at => 'datetime'`, `resolved_at => 'datetime'`.
- ⚠ Suggestion does NOT use the spatie/laravel-activitylog `LogsActivity` trait (verified via grep). Mass `->update()` would skip events anyway. Audit trail for the prune is captured via STDOUT counter summary + BaseCommand's correlation_id LogBatch wrapper. Do not wire LogsActivity in this plan — out of scope.

# supplier_sku_cache table (database/migrations/2026_06_04_120000_create_supplier_sku_cache_table.php)
- Single column: `sku VARCHAR(191) PRIMARY KEY`.
- All values are lower-cased + trimmed at write time (see SupplierSkuRegistry::refresh).
- Match expression used everywhere in the codebase:
  `c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku'))))`
- Existing precedent: SuggestionResource.php:247 (on_supplier_db SelectFilter).

# Filament navigation badge (SuggestionResource.php:54-64) — current
```
public static function getNavigationBadge(): ?string
{
    try {
        $count = Suggestion::query()->where('status', Suggestion::STATUS_PENDING)->count();
    } catch (\Throwable) {
        return null;
    }
    return $count > 0 ? (string) $count : null;
}

public static function getNavigationBadgeColor(): ?string
{
    return 'warning';
}
```
Add `getNavigationBadgeTooltip(): ?string` next to these. Filament 3 picks it up automatically.

# Schedule precedent — supplier:db-sync (routes/console.php:65-75)
Already uses cron + withoutOverlapping + onOneServer + timezone. Mirror this shape exactly:
```
Schedule::command('supplier:db-sync')
    ->cron('0 7 * * 1-5')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('...');
```
The prune entry goes IMMEDIATELY ABOVE this block (per task brief) so the file order matches runtime order Mon 06:00 → Mon-Fri 07:00 → Mon-Fri 07:05.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write Pest test (RED) — tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php</name>
  <files>tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php</files>
  <behavior>
    Run the test FIRST (TDD-ish) so the executor in Task 2 has a target. RefreshDatabase migrates supplier_sku_cache + suggestions tables.

    Seed the 2x2 matrix (helper `makeOrphanSuggestion(string $sku, int $competitors, int $ageDays)` that forceCreate's a `kind='new_product_opportunity'`, `status='pending'` row with `evidence` JSON containing `sku` + `supporting_competitors` + `competitor_sightings` array, and `proposed_at = now()->subDays($ageDays)`):

      - Row A: sku 'ORPH-OLD-1C', off-supplier, 1 competitor, 60 days old   → MUST be rejected
      - Row B: sku 'ORPH-FRESH-1C', off-supplier, 1 competitor, 5 days old  → MUST stay pending (too fresh)
      - Row C: sku 'SOURCEABLE-1C', insert into supplier_sku_cache, 1 competitor, 60 days old → MUST stay pending (sourceable)
      - Row D: sku 'ORPH-OLD-3C', off-supplier, 3 competitors, 60 days old  → MUST stay pending (high-signal)

    Note: insert into supplier_sku_cache via `DB::table('supplier_sku_cache')->insert(['sku' => 'sourceable-1c'])` — LOWERCASED + trimmed to mirror SupplierSkuRegistry write convention. The match expression LOWER(TRIM(...)) handles the lookup direction.

    Tests (it() blocks):

    - it('registers suggestions:prune-orphans as an artisan command') — `expect(array_keys(Artisan::all()))->toContain('suggestions:prune-orphans')`.

    - it('rejects only the stale off-supplier <2-competitor row in the 2x2 matrix') — seed all 4, `Artisan::call('suggestions:prune-orphans')`, then assertDatabaseHas for Row A: status='rejected', rejection_reason starts with 'auto-rejected: stale', resolved_at not null. For Rows B / C / D: status='pending', rejection_reason null.

    - it('--dry-run does not modify any rows') — seed Row A (the only one that would be rejected), run `Artisan::call('suggestions:prune-orphans', ['--dry-run' => true])`, assertDatabaseMissing rejected row, assertDatabaseHas the original pending row. Also assert the command output contains the candidate count (use `Artisan::output()` + `str_contains(...)`).

    - it('--days=90 leaves 60-day-old rows alone') — seed Row A (60d), `Artisan::call('suggestions:prune-orphans', ['--days' => 90])`, assertDatabaseHas pending (the 60-day row is younger than the 90-day cutoff, so not pruned).

    - it('returns 0 on success') — `expect(Artisan::call('suggestions:prune-orphans'))->toBe(0)`.

    Use `uses(RefreshDatabase::class)`. Pattern + helper shape mirrors AutoApplyMarginSuggestionsCommandTest.php (same domain, same conventions). Run the test now and confirm it FAILS with "command not found" / class not found — that is the RED step.
  </behavior>
  <action>
    Create `tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php` per the behavior block above. Mirror the structural shape of `AutoApplyMarginSuggestionsCommandTest.php` (same directory, same domain). Use `forceCreate` not factories (Suggestion has no factory for new_product_opportunity rows by default). Run `vendor/bin/pest tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php` and confirm it fails with the expected "command not found" / class-not-found error (RED). Do NOT proceed to Task 2 until the test file is committed in a `test(suggestions): add failing tests for prune-orphans command` commit.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php 2>&amp;1 | grep -E "FAILED|ERROR|command.+not.+(defined|found)"</automated>
  </verify>
  <done>
    File exists at tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php. Running pest on it FAILS (no command yet — expected). Commit message: `test(suggestions): add failing tests for prune-orphans command`.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Implement PruneOrphanSuggestionsCommand (GREEN)</name>
  <files>app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php</files>
  <behavior>
    The command from Task 1's test suite must now pass. Specifically:

    - Signature: `suggestions:prune-orphans {--days=30 : Min age in days} {--dry-run : Print count + sample 20 SKUs, do not write}`.
    - Description: 'Auto-reject stale competitor-only orphan new_product_opportunity suggestions (off-supplier-DB + &lt;2 competitors + older than N days).'
    - extends App\Console\Commands\BaseCommand, implements `protected function perform(): int`.
    - `use Symfony\Component\Console\Command\Command as SymfonyCommand;` then `return SymfonyCommand::SUCCESS;`.

    Candidate query (single Eloquent builder, reused for dry-run + live):

      Suggestion::query()
        ->where('kind', 'new_product_opportunity')
        ->where('status', Suggestion::STATUS_PENDING)
        ->where('proposed_at', '&lt;', now()->subDays($days))
        ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) &lt; 2")
        ->whereRaw("NOT EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku')))))")

    Dry-run path: `$count = (clone $candidates)->count();` + `$sample = (clone $candidates)->limit(20)->get(['id', 'evidence', 'proposed_at']);` — render via `$this->table(['SKU', 'Competitors', 'Age (days)'], ...)` mapping `data_get($row->evidence, 'sku')`, `(int) data_get($row->evidence, 'supporting_competitors', 0)`, `$row->proposed_at->diffInDays(now())`. Print `Found {count} candidate(s) — dry-run, no writes.` and return SUCCESS.

    Live path: `chunkById(500, function ($chunk) use (&amp;$total) { ... })` — for each chunk pluck ids, then run `Suggestion::query()->whereIn('id', $ids)->update(['status' => Suggestion::STATUS_REJECTED, 'rejection_reason' => 'auto-rejected: stale competitor-only orphan (no supplier carries + <2 competitors)', 'resolved_at' => now()]);` and bump `$total`. Print `Rejected {total} orphan suggestion(s).` and return SUCCESS.

    Important: use `chunkById` (not `chunk`) — because the update mutates the WHERE clause (status changes from pending→rejected), a regular cursor-based `chunk` would skip rows or paginate incorrectly. `chunkById` paginates by primary key which is stable across the mutation.

    Reasoning notes for the executor (Suggestion has ULID PK):
    - `chunkById` works fine on ULID PKs — it just uses `id` as the order column.
    - If the test exposes a chunkById-on-ULID edge case (some Laravel versions had nuance here), fall back to: collect candidate ids first into an array (`(clone $candidates)->pluck('id')->all()`), then `array_chunk($ids, 500)` + per-chunk `Suggestion::query()->whereIn('id', $chunk)->update([...])`. Either path satisfies the test as long as Row A flips and B/C/D do not.

    No LogsActivity wiring — Suggestion model does not use the trait (see &lt;interfaces&gt;). BaseCommand's correlation_id + LogBatch::startBatch covers the trace context.
  </behavior>
  <action>
    Create `app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php` per the behavior block. Register is automatic via Laravel's command auto-discovery (App\Domain is already covered by AppServiceProvider's namespace registration — verify by grepping `App\\Domain\\Suggestions\\Console` in AppServiceProvider or by confirming AutoApplyMarginSuggestionsCommand resolves without manual registration; it does, per the existing test). Run `vendor/bin/pest tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php` and iterate until all assertions pass (GREEN). Commit as `feat(suggestions): add suggestions:prune-orphans command`.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php</automated>
  </verify>
  <done>
    All it() blocks from Task 1's test file pass. `php artisan list suggestions` shows `suggestions:prune-orphans`. `php artisan suggestions:prune-orphans --dry-run` on the dev DB runs without errors. Commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 3: Add Mon 06:00 schedule entry in routes/console.php</name>
  <files>routes/console.php</files>
  <action>
    Insert a new Schedule::command block in `routes/console.php` IMMEDIATELY ABOVE the existing `supplier:db-sync` block (currently at lines 65-75 — the comment block that says "Quick task 260504-m5w + 260504-onx — Mon-Fri supplier DB pull at 07:00 London."). The new entry:

      // Quick task 260606-gnu — Mon 06:00 London prune of stale competitor-only orphan suggestions.
      // Runs BEFORE supplier:db-sync (Mon-Fri 07:00) so the prune never touches rows whose
      // sourceability status is about to change. Conservative gates (off-supplier + <2 competitors
      // + >=30 days old) mean a misclassified row is at worst preserved one extra week.
      Schedule::command('suggestions:prune-orphans')
          -&gt;cron('0 6 * * 1')
          -&gt;withoutOverlapping(60)
          -&gt;onOneServer()
          -&gt;timezone('Europe/London')
          -&gt;description('Prune stale orphan suggestions (Mon 06:00, before supplier sync)');

    Do NOT use `--live` — this command's default mode IS live; `--dry-run` is the opt-out. (This is the opposite convention from `competitor:ftp-pull --live` and `pricing:undercut-competitors --live`, but matches `suggestions:auto-apply`, `supplier:db-sync`, `dashboard:refresh`, etc. — most commands in this codebase. The executor should preserve this — do not retrofit a --live flag on the prune command.)

    Verify the env() guardrail (tests/Architecture/EnvUsageTest.php, commit 2336e30 from earlier today) still passes — this entry uses no env() calls so it's automatic, but run the architecture test to be sure.

    Commit as `chore(schedule): cron suggestions:prune-orphans Mon 06:00 London`.
  </action>
  <verify>
    <automated>php artisan schedule:list 2&gt;&amp;1 | grep "suggestions:prune-orphans"</automated>
    <automated>vendor/bin/pest tests/Architecture/EnvUsageTest.php</automated>
  </verify>
  <done>
    `php artisan schedule:list` shows the entry with cron `0 6 * * 1` and timezone Europe/London. Architecture test still green. The new block is positioned just above (file-order) the `supplier:db-sync` block so reading routes/console.php top-to-bottom matches the Mon 06:00 → Mon-Fri 07:00 runtime order. Commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 4: Rewrite getNavigationBadge + add getNavigationBadgeTooltip on SuggestionResource</name>
  <files>app/Domain/Suggestions/Filament/Resources/SuggestionResource.php</files>
  <action>
    In `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`:

    (a) Add `use Illuminate\Support\Facades\Cache;` to the imports if not already present (it is not — confirmed via current Read).

    (b) REPLACE the body of `getNavigationBadge()` (lines 54-64) so it now returns the high-confidence sourceable count. Keep the existing try/catch + null-when-zero contract:

        public static function getNavigationBadge(): ?string
        {
            try {
                $count = Suggestion::query()
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->where('kind', 'new_product_opportunity')
                    ->whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku')))))")
                    -&gt;whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) &gt;= 3")
                    -&gt;count();
            } catch (\Throwable) {
                return null;
            }

            return $count &gt; 0 ? (string) $count : null;
        }

    Update the docblock above to reflect the new semantics (replace the existing "Quick task 260504-ev5" doc with a Quick task 260606-gnu note explaining: high-confidence = pending + new_product_opportunity + on supplier DB + &gt;=3 competitors; rationale: 62% of pending rows are competitor-only orphans, so the raw count was noise). Keep the defensive try/catch comment.

    (c) ADD a NEW public static method `getNavigationBadgeTooltip(): ?string` directly below `getNavigationBadgeColor()`. It uses Cache::remember to compute three counters once per minute and formats the tooltip string:

        public static function getNavigationBadgeTooltip(): ?string
        {
            try {
                return Cache::remember('suggestions.nav_breakdown', 60, function (): string {
                    $base = Suggestion::query()
                        -&gt;where('status', Suggestion::STATUS_PENDING)
                        -&gt;where('kind', 'new_product_opportunity');

                    $rawPending = (clone $base)-&gt;count();
                    $sourceable = (clone $base)
                        -&gt;whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku')))))")
                        -&gt;count();
                    $highConfidence = (clone $base)
                        -&gt;whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku')))))")
                        -&gt;whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) &gt;= 3")
                        -&gt;count();

                    return sprintf(
                        '%s high-confidence • %s sourceable • %s raw',
                        number_format($highConfidence),
                        number_format($sourceable),
                        number_format($rawPending),
                    );
                });
            } catch (\Throwable) {
                return null;
            }
        }

    `getNavigationBadgeColor()` stays untouched (still returns 'warning').

    Filament 3 automatically picks up `getNavigationBadgeTooltip()` as the resource sidebar tooltip — no further wiring needed (it's a documented Filament Resource extension point on the same family as getNavigationBadge / getNavigationBadgeColor).

    Run the FULL Pest suite afterwards. Any existing tests that hit `getNavigationBadge()` (notably tests/Feature/SuggestionResourceQueryCountTest.php — referenced in the existing docblock — would need to seed `kind=new_product_opportunity` + supplier_sku_cache + supporting_competitors&gt;=3 to get a non-null result). If a pre-existing test asserts a specific badge count under the old semantics and now fails, update that test's seed data to match the new contract (the test purpose — N+1 query bound — remains; only the seed shape changes). Do NOT loosen the test's query-count assertion.

    Commit as `feat(suggestions): badge counts high-confidence sourceable + breakdown tooltip`.
  </action>
  <verify>
    <automated>vendor/bin/pest</automated>
    <automated>grep -n "getNavigationBadgeTooltip" app/Domain/Suggestions/Filament/Resources/SuggestionResource.php</automated>
  </verify>
  <done>
    `getNavigationBadge()` body now contains the EXISTS supplier_sku_cache subquery + the &gt;=3 competitor whereRaw. `getNavigationBadgeTooltip()` exists, uses Cache::remember('suggestions.nav_breakdown', 60, ...), and returns the 3-tier breakdown string. `getNavigationBadgeColor()` unchanged. FULL Pest suite is green. Commit landed.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| operator → artisan CLI | Operator (or cron) invokes `suggestions:prune-orphans`. Input surface: `--days` int, `--dry-run` bool. No untrusted external input. |
| sidebar render → DB | Filament renders the badge on every authed admin page load; query failure must NOT 500 the entire admin (existing try/catch contract preserved). |
| scheduler → cron string | `cron('0 6 * * 1')` is operator-authored; no injection surface. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-260606-gnu-01 | Tampering | `suggestions:prune-orphans` mass UPDATE | mitigate | 3-way conjunction gate (age &gt;= --days AND off-supplier AND &lt;2 competitors). Worst-case misclassification preserves the row another week. Default 30-day floor + `--dry-run` for ops verification before first live run. |
| T-260606-gnu-02 | Repudiation | Bulk update bypasses LogsActivity | accept | Suggestion model does not use LogsActivity (verified). BaseCommand's correlation_id + LogBatch wrapper provides command-level trace context. STDOUT counter is captured by cron mail. No per-row audit is in scope for this quick task. |
| T-260606-gnu-03 | Denial of Service | Badge tooltip runs 3 EXISTS queries on every admin page load | mitigate | 60-second Cache::remember('suggestions.nav_breakdown', 60, ...). Three queries scoped to small indexes; supplier_sku_cache PK lookup is O(1) on the ~900k row table. |
| T-260606-gnu-04 | Denial of Service | Mass UPDATE on suggestions table | mitigate | Chunked at 500 rows via chunkById (or id-array array_chunk fallback). withoutOverlapping(60) prevents schedule double-fire. |
| T-260606-gnu-05 | Information Disclosure | Tooltip exposes inbox volumes | accept | Counts are admin-only data already visible via the filter UI. No PII. |
| T-260606-gnu-06 | Tampering | Schedule fires BEFORE supplier:db-sync — could prune a row whose supplier-DB status is about to flip | accept | Conservative gates make this near-impossible: a row needs to be both off-supplier AND have &lt;2 competitors AND have aged 30+ days. The 1-hour gap between prune (06:00) and sync (07:00) means a newly-arrived supplier row only avoids one weekly prune cycle. |
| T-260606-gnu-SC | Tampering | npm/pip/cargo installs | n/a | No package installs in this plan. |
</threat_model>

<verification>
After all four tasks land:

1. **Pest suite green:** `vendor/bin/pest` — all green.
2. **Architecture guardrail green:** `vendor/bin/pest tests/Architecture/EnvUsageTest.php` — no env() leaks (the new schedule entry uses no env() calls).
3. **Schedule visible:** `php artisan schedule:list` shows `suggestions:prune-orphans` with cron `0 6 * * 1` (timezone Europe/London).
4. **Command dry-run on dev DB:** `php artisan suggestions:prune-orphans --dry-run` produces a count + 20-row table without writing.
5. **Badge sanity check:** open `/admin/suggestions` in local dev; sidebar badge reflects the high-confidence count (or no badge if zero). Hover shows the 3-tier breakdown tooltip. (Manual — operator verification on prod after deploy.)
6. **Filename precedent:** the PLAN file is named `260606-gnu-PLAN.md` per existing quick-task convention (e.g. `260606-c4o-add-architectural-guardrail-env-must-onl/` uses the same slug-as-quick-id pattern).
</verification>

<success_criteria>
- `app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php` exists, extends BaseCommand, registers as `suggestions:prune-orphans`.
- `tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php` exists with the 2x2 matrix + dry-run + --days override coverage; all assertions pass.
- `routes/console.php` has the Mon 06:00 London schedule entry placed above the supplier:db-sync block.
- `SuggestionResource::getNavigationBadge()` body uses the high-confidence sourceable count; `getNavigationBadgeTooltip()` exists with the 60s cache + 3-tier breakdown.
- 4 atomic commits land in order: test (RED) → command (GREEN) → schedule → badge+tooltip.
- Full Pest suite green at the end (including the architecture guardrail).
</success_criteria>

<output>
After all four tasks complete, write `.planning/quick/260606-gnu-inbox-triage-suggestions-prune-orphans-h/260606-gnu-SUMMARY.md` summarising:
- Final commit shas in order
- Live prod expected behaviour (badge will drop from `14,263` to the high-confidence count once deployed)
- First-run-on-prod recommendation: run `php artisan suggestions:prune-orphans --dry-run` BEFORE the first Mon 06:00 cron fire to inspect the candidate set, then confirm comfort with the prune count.
</output>
