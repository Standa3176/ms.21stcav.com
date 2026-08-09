# 260809-jie — Competitor pricing safety guards: margin ceiling + feed-jump quarantine

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Born from:** 2026-08-09 PRODUCTION incident — SKU `9C941AA` was repriced **£1297.30 → £4652.02**
(280% markup vs configured 22–35% margin bands). Root cause: `competitor_prices` for `competitor_id=3`
jumped **£1067.69 → £3876.69** ex-VAT overnight (2026-08-05), almost certainly a feed error, and
`pricing:undercut-competitors` faithfully undercut it by 1p and wrote `sell_price`. A catalogue-wide audit
found **14 products** with the same signature (large competitor-price move → resulting margin far outside
bands), worst being `9H.JN777.33E` at £18.72 cost → £450.79 (20.07×).

**PRODUCTION CONTEXT:** `WOO_WRITE_ENABLED=true` and a bulk price push is actively running. Both guards
must be purely additive and safe to deploy without disturbing in-flight work — no behaviour change to any
path other than the two guards below. This task does NOT retroactively fix the 14 already-mispriced live
SKUs (including `9C941AA`) — that is a separate operator remediation step after these guards are deployed
(e.g. dry-run `pricing:undercut-competitors` again post-deploy and manually correct anything still wrong).

## Goal
Two independent, config-driven guards so bad competitor feed data can never again reach a live sell price:

1. **Guard 1 — margin ceiling** in `CompetitorUndercutPricingCommand`: refuse to write a competitor-derived
   price whose resulting margin blows past a sane ceiling; flag it for human review instead.
2. **Guard 2 — feed-jump quarantine** in `CompetitorCsvRowWriter`: a single competitor-price row that moves
   too far vs. its own prior value never reaches the pricer's "lowest current competitor" query, even though
   it is still persisted for audit/history (this codebase's established "persist everything, gate on
   consumption" pattern — see the existing orphan-row precedent in the same file).

**Do NOT touch** `App\Domain\Pricing\Services\PriceCalculator` or `RuleResolver` — both are byte-locked by
`TradePricingNoV1ModificationTest`, `PriceCalculatorPurityTest`, and a sha256 guard. Touching either fails
the build. `CompetitorUndercutPricer::decide()` already computes and returns `effective_margin_bps` — Guard
1 reads that value, it does not recompute margin math.

---

## Task 1 — Guard 1: margin ceiling block + review Suggestion (TDD)

**Files:** `config/competitor.php`, `app/Console/Commands/CompetitorUndercutPricingCommand.php`,
`tests/Feature/Pricing/CompetitorUndercutPricingCommandMarginCeilingTest.php` (new — this command currently
has ZERO test coverage; this file becomes its first).

**Config (add to `config/competitor.php`, alongside the existing `min_margin_floor_bps` — this is its
upside counterpart):**
```
'max_margin_ceiling_bps' => (int) env('COMPETITOR_MAX_MARGIN_CEILING_BPS', 5000), // 50% ceiling
```
Add a doc-comment block matching the file's existing style explaining the incident + relationship to
`min_margin_floor_bps`.

**Behavior (write the Pest test FIRST, watch it fail, then implement):**
- Test 1: a product whose lowest competitor price is a feed-error-magnitude jump (buy £100 ex-VAT cost;
  competitor gross price high enough that undercutting it 1p breaches `max_margin_ceiling_bps`) — running
  `pricing:undercut-competitors --skus=<sku> --live` must: leave `products.sell_price` UNCHANGED, NOT
  dispatch `ProductPriceChanged` (`Event::fake` + `Event::assertNotDispatched`), increment a new
  `blocked_ceiling` stat (assert via `expectsOutputToContain`), print a blocked line naming the SKU, buy
  price, proposed price, effective margin, and the driving competitor price, and create exactly one
  `App\Domain\Suggestions\Models\Suggestion` row.
- Test 2: same scenario WITHOUT `--live` (dry-run, the default) — the block must still appear in output and
  the Suggestion must still be written (dry-run has zero pricing side effects, but this is a review flag,
  not a price write, so it fires either way — this is the detection mechanism operators rely on since the
  live schedule only runs once daily).
- Test 3 (no-regression case): a product whose competitor-driven margin is well inside the ceiling (e.g.
  ~30%) is undercut and written normally with `--live`; `blocked_ceiling` stays 0, no Suggestion of this kind
  is created.
- Test 4: re-running the command a second time for the same still-anomalous SKU does NOT create a second
  duplicate Suggestion row — `updateOrCreate`-style idempotency keyed on (kind, evidence→sku, pending
  status), refreshing `evidence` + `proposed_at` on repeat.

**Implementation (`CompetitorUndercutPricingCommand`):**
- Read `$ceilingBps = (int) config('competitor.max_margin_ceiling_bps', 5000);` in `perform()`, thread it
  into the `chunkById` closure and into `priceOne()` as a new parameter (mirrors how `$minFloorBps` /
  `$vatBps` are already threaded).
- Add `'blocked_ceiling' => 0` to the `$stats` array initializer.
- In `priceOne()`, immediately after `$decision = $this->pricer->decide(...)` (line ~163) and BEFORE the
  existing `$this->stats[$source] = ...` increment: if `$decision['source']` is `competitor_undercut` OR
  `competitor_floor` AND `(int) $decision['effective_margin_bps'] > $ceilingBps`, then:
  - `$this->stats['blocked_ceiling']++;`
  - print a blocked line (`$this->line(...)`) with SKU, buy price, proposed sell price
    (`$decision['final_pennies']`), effective margin %, and the driving competitor gross price (`$lowest`,
    may be null only in the `competitor_floor` case where a competitor still drove the floor calc — in
    practice `$lowest` is always set for both sources here since neither source fires without a competitor).
  - call a new private `recordCeilingBlockedSuggestion(Product $product, string $sku, int $buyPennies, int
    $proposedPennies, int $marginBps, ?int $competitorGrossPennies): void` (kind
    `'competitor_price_ceiling_blocked'`, `status = Suggestion::STATUS_PENDING`, `evidence` = sku,
    buy_price_pennies, proposed_sell_price_pennies, effective_margin_bps, competitor_price_pennies,
    ceiling_bps, blocked_at ISO8601; `payload` = product_id + sku). Look up an existing PENDING suggestion of
    this kind for this SKU via `Suggestion::where('kind', ...)->where('status', Suggestion::STATUS_PENDING)
    ->whereJsonContains('evidence->sku', $sku)->first()` (same pattern `OrphanDetector::record()` already
    uses in this codebase) — if found, `update()` its `evidence` + `proposed_at`; else `create()`. No new
    `SuggestionApplier` needed — this kind is informational-only for human review, exactly like the existing
    `auto_create_failed` / `crm_push_failed` / `quote_push_failed` kinds in this codebase which also have no
    registered applier.
  - `return;` immediately — skip the `unchanged`/`changed`/write/dispatch path entirely. This is what
    guarantees no write and no dispatch regardless of `--live`.
- Add `use App\Domain\Suggestions\Models\Suggestion;` import.
- Update the final summary `sprintf` in `perform()` to add the blocked count, e.g.: `'%s — %d changed (%d
  undercut, %d floored, %d margin), %d unchanged, %d skipped, %d blocked (margin ceiling) of %d
  processed.'` with `$this->stats['blocked_ceiling']` appended as an argument.

**Verify:**
```
<automated>pest --filter=CompetitorUndercutPricingCommandMarginCeilingTest</automated>
```
**Done:** All 4 tests above pass; a competitor-driven price whose margin exceeds
`competitor.max_margin_ceiling_bps` never reaches `products.sell_price` or `ProductPriceChanged`, in both
dry-run and `--live`, and is captured as a deduplicated review Suggestion.

---

## Task 2 — Guard 2a: quarantine flag on `competitor_prices` ingest (TDD)

**Files:** new migration `database/migrations/2026_08_09_000000_add_price_anomaly_flag_to_competitor_prices_table.php`,
`app/Domain/Competitor/Models/CompetitorPrice.php`, `app/Domain/Competitor/Services/CompetitorCsvRowWriter.php`,
`config/competitor.php`, `tests/Feature/Competitor/CompetitorPriceAnomalyQuarantineTest.php` (new).

**Config (add alongside the other competitor thresholds):**
```
'max_row_move_pct' => (int) env('COMPETITOR_MAX_ROW_MOVE_PCT', 50),
```

**Migration (additive-only — safe on the live prod DB, mirrors the existing
`2026_07_08_020000_add_pin_price_to_product_overrides_table.php` pattern in this repo):**
- `Schema::table('competitor_prices', ...)` adds:
  - `boolean('is_price_anomaly')->default(false)->after('price_pennies_gross')`
  - `string('price_anomaly_reason', 255)->nullable()->after('is_price_anomaly')`
- Backfill existing rows explicitly: `DB::table('competitor_prices')->update(['is_price_anomaly' => false]);`
  (belt-and-braces, mirrors the pin_price migration precedent — nullable/`default(false)` already covers
  this on MySQL/MariaDB but keep it explicit for driver-portability).
- No new index needed: the existing `UNIQUE(competitor_id, sku, recorded_at)` index already services the
  "most recent prior row for (competitor_id, sku) ordered by recorded_at desc" lookup the writer needs.
- `down()` drops both columns.

**`CompetitorPrice` model:** add `is_price_anomaly` and `price_anomaly_reason` to `$fillable`; cast
`is_price_anomaly` as `'bool'`.

**Behavior (write the Pest test FIRST):**
- Test 1: first-ever row for a (competitor, SKU) pair — no prior row exists — is NEVER flagged regardless of
  price, because there is nothing to compare against.
- Test 2: a second row for the same (competitor, SKU) that moves ≤ `max_row_move_pct` (e.g. +20% when
  ceiling is 50%) is NOT flagged (`is_price_anomaly` false, `price_anomaly_reason` null).
- Test 3 (the incident, reproduced): baseline row £1067.69 ex-VAT, then a second ingest at £3876.69 ex-VAT
  for the same (competitor_id, sku) — a 263% move, ceiling 50% — the SECOND row is written with
  `is_price_anomaly = true` and a non-null `price_anomaly_reason` describing the move; `CompetitorPrice::
  count()` is still 2 (row persists — this codebase's "persist all data, gate on consumption" convention,
  same as the existing orphan-row behavior in this same writer); `rows_written` on the `CompetitorIngestRun`
  still increments normally (writing is not the same as "reaching the pricer" — that gate lives in Task 3).
- Test 4: a prior row with `price_pennies_ex_vat = 0` never divides by zero and is never flagged from that
  comparison (defensive edge case — a zero baseline can't meaningfully express "% move").
- Drive all 4 through `CompetitorCsvChunkJob::handle()` with the real `CompetitorCsvRowWriter`, matching the
  existing test style in `tests/Feature/Competitor/CompetitorCsvChunkJobTest.php` (same file's `Event::fake`
  + factory setup pattern) — two sequential `CompetitorCsvChunkJob` runs for the baseline-then-jump case,
  asserting `recorded_at` ordering via explicit timestamps if needed to keep the "most recent prior" lookup
  deterministic.

**Implementation (`CompetitorCsvRowWriter::write()`):**
- Immediately before the `CompetitorPrice::create([...])` call (after `$exVatPennies` /
  `$grossInclPennies` are computed), look up the prior row: `CompetitorPrice::query()->where('competitor_id',
  $run->competitor_id)->where('sku', $sku)->orderByDesc('recorded_at')->first(['price_pennies_ex_vat']);`
  (single query, uses the existing unique index — NOT a correlated subquery, driver-portable on SQLite test /
  MariaDB prod per this project's known trap).
- If a prior row exists AND its `price_pennies_ex_vat > 0`: compute `$movePct = abs($exVatPennies -
  $priorPennies) / $priorPennies * 100`. If `$movePct > (float) config('competitor.max_row_move_pct', 50)`,
  set `$isAnomaly = true` and build `$anomalyReason` (prior price, new price, move %, threshold — human-
  readable, ≤ 255 chars).
- Pass `is_price_anomaly` / `price_anomaly_reason` into the existing `CompetitorPrice::create([...])` call.
- When flagged, `Log::warning('competitor.price_move_flagged', [...competitor_id, sku, prior/new ex-vat
  pennies, move_pct, ingest_run_id])` — mirrors the existing `Log::info('competitor.duplicate_row_skipped',
  ...)` pattern already in this file.
- **Deliberately do NOT change** anything else in this method: `OrphanDetector`, the `CompetitorPriceRecorded`
  event dispatch, `rows_written`/`rows_orphaned` counters, and the COMP-06 `stripVat`/`addVat` reuse all stay
  byte-identical. The flagged row still persists and still participates in orphan detection / margin-
  analyser events exactly as an unflagged row would — the ONLY consumer being gated is the undercut pricer's
  "lowest current competitor" query, wired in Task 3. This is a deliberate minimal-blast-radius choice per
  the task brief's "least invasive mechanism."

**Verify:**
```
<automated>pest --filter=CompetitorPriceAnomalyQuarantineTest</automated>
```
**Done:** All 4 tests pass; anomalous rows persist (audit trail intact) but carry `is_price_anomaly = true` +
a human-readable reason; no other writer behavior changes (existing `CompetitorCsvChunkJobTest.php` suite
stays green).

---

## Task 3 — Guard 2b: wire the pricer to exclude quarantined rows + incident-reproduction regression test (TDD)

**Files:** `app/Console/Commands/CompetitorUndercutPricingCommand.php` (same file as Task 1 — sequential
edit, no conflict since Task 1 already committed), `tests/Feature/Pricing/CompetitorUndercutPricingCommandQuarantineExclusionTest.php` (new).

**Behavior (write the Pest test FIRST):**
- Test 1 (the incident, end-to-end): a product with buy price + TWO competitor rows for the same competitor
  — an old good price and a newer row flagged `is_price_anomaly = true` (same 263%-jump shape as Task 2's
  Test 3, built directly via `CompetitorPrice::factory()` with `is_price_anomaly: true` rather than through
  the CSV pipeline, since this test's concern is the pricer's query, not ingestion) — running
  `pricing:undercut-competitors --skus=<sku> --live` prices off the OLDER unflagged price, NEVER the flagged
  one. Assert the resulting `sell_price` reflects an undercut of the good price, not the anomalous one.
- Test 2: a product whose ONLY competitor row is flagged `is_price_anomaly = true` (no unflagged fallback
  exists) is treated exactly as "on no competitor" — falls through to the cost-plus rule-margin case
  (`source = 'margin'`), matching the existing no-competitor code path in `priceOne()`. If no pricing rule
  matches, it is `skipped` exactly as today.
- Test 3 (no-regression): a normal unflagged competitor row still prices exactly as before — reuse a
  minimal version of Task 1's Test 3 shape to confirm the new `where` clause doesn't change existing
  behavior for the common case.

**Implementation:** in `lowestCurrentCompetitorGross()`, add `->where('is_price_anomaly', false)` to the
existing `CompetitorPrice::query()` chain (alongside the existing `sku`/`mpn`/`recorded_at` filters) — one
line, no other change to the method's latest-per-competitor / minimum-across-competitors logic.

**Verify:**
```
<automated>pest --filter=CompetitorUndercutPricingCommandQuarantineExclusionTest</automated>
```
**Done:** A quarantined competitor-price row can never be selected as the "lowest current competitor" for
pricing purposes — the exact incident (competitor 3's 3.63× overnight jump) is proven unreachable by the
pricer via an automated regression test.

---

## Full-suite verify (run once, after all 3 tasks)
- `pest --filter=Competitor` and `pest --filter=Pricing` — full sibling suites green, no regressions.
- `php artisan migrate` (fresh/test DB) — new migration applies cleanly; `php artisan migrate:rollback
  --step=1` then re-migrate — additive migration reverses cleanly.
- `php artisan route:list --path=admin` exits 0.
- `./vendor/bin/pint` — no style violations introduced.
- `./vendor/bin/deptrac analyse` — 0 new violations (this repo has `deptrac.yaml`; both touched
  files/classes stay within their existing architecture layers — no new cross-layer imports beyond
  `App\Domain\Suggestions\Models\Suggestion`, which `OrphanDetector` in the same `Competitor` domain already
  imports today, so it's an established-legal edge).

## Guardrails / SUMMARY
- Do NOT modify `PriceCalculator` or `RuleResolver` (byte-locked, sha256-guarded).
- Do NOT flip `WOO_WRITE_ENABLED`, change the Woo-write throttle, or touch anything outside the two guards
  described here.
- Do NOT retroactively correct the 14 already-mispriced live SKUs — flag this explicitly in the SUMMARY as a
  follow-up for the operator (re-run `pricing:undercut-competitors` dry-run after deploy to see what Guard 1
  now blocks vs. what's already live-wrong; those need manual/scripted correction as a separate step).
- Migration is additive-only (nullable-safe boolean + string, explicit backfill) — safe to run against the
  live prod DB with `WOO_WRITE_ENABLED=true` and a bulk push in flight; it does not touch any row currently
  being written by that push.
- Keep both new config keys env-overridable with the documented defaults (`COMPETITOR_MAX_MARGIN_CEILING_BPS`
  default 5000 = 50%; `COMPETITOR_MAX_ROW_MOVE_PCT` default 50).
- All new SQL must be driver-portable (SQLite test / MariaDB prod) — no MySQL-only functions, no correlated
  subqueries with `LIMIT`. The prior-row lookup in Task 2 is a plain single query against the existing unique
  index, not a subquery.
- Do NOT stage pre-existing working-tree noise unrelated to this task (check `git status` before committing;
  only stage the files listed in each task).
- Atomic commit per task (3 commits total). Write `260809-jie-SUMMARY.md` covering: both guards' final
  config defaults, the exact incident-reproduction test names proving each guard closes the gap, the 14-SKU
  remediation follow-up note above, and confirmation that the full sibling `Competitor`/`Pricing` test suites
  stayed green.
