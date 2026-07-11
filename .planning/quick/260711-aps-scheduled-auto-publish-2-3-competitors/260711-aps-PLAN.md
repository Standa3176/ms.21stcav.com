# 260711-aps — Twice-weekly auto-PUBLISH of pending, sourceable, 2-or-3-competitor SKUs (+ audit log)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Operator intent:** "auto create twice a week any pending suggested SKU where we have a supplier and
2 or 3 competitors show the product — **push straight to live**, and keep a record of what was pushed
and when." Competitor rule clarified: **exactly 2 OR exactly 3** (both qualify; track them distinctly).

## What already exists (build on it, don't duplicate)
- `products:draft-from-suggestions` (app/Console/Commands/DraftFromSuggestionsCommand.php) already walks
  `Suggestion` kind=new_product_opportunity + status=pending, filters to **sourceable** (supplier_sku_cache)
  + brand-on-Woo, runs the pipeline (generate-drafts → assign-taxonomy → optional source-images), and has
  `--auto-approve` which dispatches `PublishProductJob::dispatchSync` per draft to push LIVE (requires
  `WOO_WRITE_ENABLED=true`). `--create-missing-brands` avoids silently dropping brand-not-on-Woo SKUs.
- Competitor count = `evidence.supporting_competitors` (the inbox "Competitor count" filter uses it:
  `CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence,'$.supporting_competitors')) AS UNSIGNED)`).
- `PublishProductJob` marks `auto_create_status='published'` ONLY on a real Woo write; in shadow mode
  (`WOO_WRITE_ENABLED=false`) it does NOT falsely mark published (line ~41 contract) — so a scheduled
  auto-approve run is a **safe no-op until the flag is on**.

## Gaps to close
1. No competitor-count filter on the command.
2. No dedicated "what was pushed and when" record.
3. No schedule.

## Tasks

### Task 1 — `--min-competitors` / `--max-competitors` on the command (TDD)
Add two options to `products:draft-from-suggestions` (default null = no filter, backward-compatible).
When set, filter the Suggestion walk on `supporting_competitors` within `[min,max]` inclusive. **Driver-
portable** (mirror the readiness/brandJsonExpr pattern: SQLite `json_extract(evidence,'$.supporting_competitors')`
vs MariaDB `JSON_UNQUOTE(JSON_EXTRACT(...))`, cast to integer — memory: sqlite-mariadb-strict-trap; do
NOT use MySQL-only `CAST … AS UNSIGNED` in the shared path). Applies to the Suggestion-walk path (not the
explicit `--skus` path). Test: seed pending sourceable suggestions with supporting_competitors 1/2/3/4 →
`--min-competitors=2 --max-competitors=3` selects ONLY the 2 and 3; 1 and 4 excluded; combined with the
existing sourceable filter.

### Task 2 — Auto-publish audit log (TDD)
Migration `auto_publish_log` + model `App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry` (final,
fillable, casts): `id`, `sku` (str), `product_id` (nullable), `woo_product_id` (nullable int),
`competitor_count` (unsigned int — lets the operator see the 2-vs-3 split), `supplier_count` (nullable
int), `source` (str, default 'scheduled_auto_publish'), `batch_correlation_id` (nullable str),
`published_at` (timestamp). Index `published_at`, `competitor_count`.
Write ONE row per **successful** live publish, from the command's `--auto-approve` loop AFTER
`PublishProductJob::dispatchSync` returns and the product is confirmed published (re-read
`auto_create_status==='published'` AND `woo_product_id` present). Capture `competitor_count` from the
driving suggestion. **In shadow mode (no real publish) NO row is written.** Driver-portable.
Test: successful publish → exactly one log row w/ correct sku/competitor_count/woo_product_id/published_at;
shadow-mode publish (WOO_WRITE_ENABLED=false) → ZERO log rows; a 2-comp and a 3-comp publish → two rows
with competitor_count 2 and 3 respectively.

### Task 3 — Read-only "Auto-Publish Log" viewer (TDD)
A read-only Filament Resource on `AutoPublishLogEntry` (nav group **'Woo Maintenance'**, since it's the
auto-create/Woo surface; sensible sort + icon): columns published_at / sku / competitor_count /
woo_product_id (link to the live Woo product) / source, default sort published_at desc, filter by
competitor_count (2 / 3) + date. NO create/edit/delete. canAccess consistent with the other
auto-create/Woo-Maintenance admin resources. Test: renders for admin; seeded rows show; competitor_count
filter isolates 2-vs-3.

### Task 4 — Twice-weekly schedule + safeguards (TDD/verify)
In `routes/console.php` add (London TZ, mirror the file's `Schedule::command(...)` style):
```
Schedule::command('products:draft-from-suggestions', [
    '--min-competitors' => 2, '--max-competitors' => 3,
    '--auto-approve' => true, '--create-missing-brands' => true, '--source-images' => true,
    '--limit' => (int) config('product_auto_create.scheduled_publish_limit', 25),
    '--no-confirm' => true,
])->twiceWeekly(1, 4, '05:00')->withoutOverlapping();
```
Add the config key `product_auto_create.scheduled_publish_limit` (env `AUTO_PUBLISH_SCHEDULED_LIMIT`,
default 25) so the batch cap is tunable without a deploy. Add a comment: **live publishing requires
`WOO_WRITE_ENABLED=true`; until then this run is a safe shadow no-op.** Test: `schedule:list` shows the
entry without error; a focused test that the scheduled arg-set, run against seeded data, selects only the
2/3-competitor sourceable pending SKUs (dry-run / Bus or Queue fake — do NOT hit real Woo in tests).

## Verify
- `pest` on the command (competitor filter + audit-log write + shadow no-op), the model/migration, the
  viewer, and the schedule selection — GREEN. Wider ProductAutoCreate + Suggestions suites: no regression.
- `php artisan route:list --path=admin` exit 0 (Auto-Publish Log resolves); `php artisan schedule:list`
  shows the twice-weekly entry.
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations (model/resource in ProductAutoCreate; resource
  is presentation).

## Guardrails / out of scope
- The command's LIVE publish path is unchanged except for the competitor filter + audit-log write — do NOT
  alter PublishProductJob's write contract or the WOO_WRITE_ENABLED gate. Shadow-mode MUST stay a no-op
  (tested).
- Batch cap enforced via `--limit` (default 25). Audit log records only REAL publishes.
- Driver-portable everywhere (SQLite tests / MariaDB prod). Tests must NEVER hit real Woo (fake the job/
  client; assert on local state + the audit log).
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits per task.
  Write `260711-aps-SUMMARY.md` (commit SHAs, the competitor-filter test, the shadow-no-op test, verify
  results, and an explicit note that WOO_WRITE_ENABLED must be true in prod for live pushes).
