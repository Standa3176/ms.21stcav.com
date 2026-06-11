---
quick_id: 260611-g4q
mode: quick
title: "products:push-divergence-to-woo — close 3,078 of 4,235 cutover parity gaps (stock_quantity + buy_price + category_id)"
created: 2026-06-11
completed: 2026-06-11
status: shipped
commits:
  - 7f07659  # feat(cutover): products:push-divergence-to-woo command + AppServiceProvider registration
  - 6d712d5  # test(cutover): push-divergence-to-woo Pest cases A-J
  - 1b6cc1c  # docs(state): cross-reference 260611-g4q from 260611-f1y row
test_delta:
  baseline: "1,995 / 222 / 3 (260611-f1y)"
  shipped:  "2,005 / 222 / 3"
  delta:    "+10 pass / 0 new fails / 0 skips"
files_created:
  - app/Console/Commands/PushDivergenceToWooCommand.php
  - tests/Feature/Console/PushDivergenceToWooCommandTest.php
files_modified:
  - app/Providers/AppServiceProvider.php
  - .planning/STATE.md
---

# 260611-g4q — MS→Woo Divergence Pusher — SHIPPED

## What landed

A new artisan command `products:push-divergence-to-woo` that consumes
`sync_diffs` from the 260610-qc4 13-field divergence scan and pushes MS-side
truth back to Woo for the 3 deterministically-pushable fields: **stock_quantity
+ buy_price + category_id** — collectively **3,078 of 4,235 (73%)** of the
cutover-parity gap surfaced on prod's first live scan (2026-06-11).

The remaining 1,157 sync_diffs (image_url 382 + exists 219 + meta_description 1
+ tail) need separate triage and are out of scope for this task.

## Why this exists (the gap nobody else closed)

- 260610-qc4 grew `WooFieldComparator` from 7 → 13 comparable fields. First
  live run hit prod yesterday and emitted **4,235 sync_diffs across 3,348
  products** — true cutover parity is **45%**, not the 100% green the original
  7-field scan claimed.
- Field distribution: stock_quantity=**1,598** + buy_price=**1,480** +
  category_id=**555** + image_url=382 + exists=219 + meta_description=1.
- Neither of the existing MS→Woo writers closed this gap:
  - `OverridePopulator` writes `pin_*` flags only, never to Woo, and
    explicitly skips diffs where `pin_column=null` (which IS our entire
    3-field set — none of stock/buy_price/category have pin columns).
  - `ResyncProductsToWooCommand` pushes regular_price + brand tag +
    attributes — not stock, not buy_price meta, not category.
- This command fills exactly that gap.

## The 6 outcomes (sync_diff lifecycle)

| Outcome              | Meaning                                                            | sync_diff status after run                            |
| -------------------- | ------------------------------------------------------------------ | ----------------------------------------------------- |
| `pushed`             | All eligible fields landed on Woo via single PUT                   | applied + applied_at=now                              |
| `errors`             | GET or PUT failed → product skipped, run continues                 | unchanged (pending) — retries on next run             |
| `no_woo_product_id`  | Product row has `woo_product_id=NULL` (shouldn't happen post-cutover) | applied + applied_at=now + payload `applied_with` annotation (no retry) |
| `woo_not_found`      | Pre-GET returned 404 (product deleted on Woo)                       | status=`woo_not_found` (NOT applied — data wasn't pushed; row retired to stop surfacing) |
| `already_applied`    | sync_diff row already had status='applied'                          | informational counter — query filters them out, stays at 0 |
| `partial_success`    | Reserved forward-compat — single-PUT design keeps this at 0         | n/a                                                   |

## Non-negotiable constraints (the contracts that survive)

1. **Pre-GET on EVERY product**. The Algoritmika WC Cost-of-Goods plugin
   stores `buy_price` inside `meta_data[]` alongside Yoast SEO entries,
   EAN keys, brand_id meta, etc. A blind PUT with
   `meta_data=[{key:_alg_wc_cog_cost, value:X}]` WIPES every other meta
   entry. Mandatory read-modify-write: GET → splice existing meta_data →
   PUT the merged result. (Case B test pins this — asserts Yoast +
   brand entries survive untouched + old cost entry is dropped + new
   cost entry appears exactly once.)
2. **Single PUT per product carries all 3 fields.** NOT 3 separate PUTs.
   Saves 2× round-trips per product × 1,000+ products ≈ 22 wall-time
   minutes per run.
3. **NO split-PUT WAF workaround needed.** 260530-clv's
   ResyncProductsToWooCommand splits because `regular_price` +
   everything-else triggers the COG recompute hook. Our payload
   (stock + meta + categories) has NO `regular_price` → no split needed.
4. **`manage_stock=true` ALWAYS co-emitted with `stock_quantity`.** Without
   it Woo treats the value as a manual override, not a storefront
   source-of-truth.
5. **`category_ids` JSON preferred** for the categories payload; single
   `category_id` fallback when category_ids is empty/null.
6. **200ms usleep between successful PUTs** matches 260607-v5g + 260609-nku
   + 260611-f1y pacing.
7. **Live-confirmation prompt by default**; `--no-confirm` exists for
   cron-style invocation (operator opt-in).
8. **`env()` guardrail respected** — no new .env switches; nothing to
   add to `config/`.
9. **DivergenceScanner, OverridePopulator, ResyncProductsToWooCommand,
   PushVisibilityToWooCommand UNTOUCHED.**

## Drift-prevention contract (the next dev's safety net)

- Private const `SUPPORTED_FIELDS = ['stock_quantity', 'buy_price',
  'category_id']` — grep-discoverable. Unknown `--field=X` values bail with
  a clear "Unsupported field: X. Supported: …" error (no silent skip).
- Imports `WooFieldComparator::BUY_PRICE_META_KEY` — **never duplicates** the
  literal `'_alg_wc_cog_cost'`. Grep guard: `grep -v '^\s*\*' source | grep
  -c "_alg_wc_cog_cost"` → 0.
- Class docblock explicitly instructs: "if a future quick task adds a 14th
  comparable field, the dev MUST either extend SUPPORTED_FIELDS + the
  payload builder here, OR explicitly out-scope the new field with a
  comment." `DivergenceComparatorCoverageTest` (260610-qc4) fails when the
  comparator changes; this command bails on unknown `--field=` until
  extended.

## What we verified

- `php artisan list | grep push-divergence-to-woo` → 1 hit. ✓
- `--help` exposes 6 flags (--field, --limit, --chunk, --dry-run,
  --correlation-id, --no-confirm). ✓
- 10 Pest cases A-J GREEN (87 assertions, 12.67s).
- Regression: WooFieldComparator + DivergenceScanCommand +
  DivergenceComparatorCoverage + ResyncProductsToWoo + PushVisibilityToWoo +
  OverridePopulator — 26 passed.
- Full Pest suite: **2,005 / 222 / 3** vs baseline 1,995 / 222 / 3 →
  **+10 pass / 0 new fails / 0 new skips**. Exact planned delta.
- Smoke check on dev DB: `--dry-run --limit=2 --no-confirm` exits clean
  ("No divergence-scan rows found") — no stack trace.

## Test cases (A-J)

| Case | What it pins                                                            |
| ---- | ----------------------------------------------------------------------- |
| A    | Stock-only happy path — 1 GET, 1 PUT with `stock_quantity` + `manage_stock=true`, sync_diff flipped to applied + applied_at non-null |
| B    | buy_price-only — meta_data MERGE preserves Yoast + brand entries, drops old `_alg_wc_cog_cost`, appends fresh entry with `number_format(.., 4)` value |
| C    | category_id-only — `categories` built from `category_ids` JSON multi-cat; NO stock/manage_stock/meta_data keys present |
| D    | 3-field combo — EXACTLY 1 GET + EXACTLY 1 PUT carrying all 3 fields; all 3 sync_diff rows applied |
| E    | `--field=stock_quantity` partial-scope — only the stock_quantity sync_diff applied; buy_price stays pending for next pass |
| F    | Woo PUT 5xx — sync_diff stays pending, errors counter ticks, exit 0 (per-candidate failures non-fatal) |
| G    | Woo GET 404 — sync_diff flipped to status=`woo_not_found` (NOT applied), woo_not_found counter ticks |
| H    | `--dry-run` with 5 products — 5 GETs (pre-GET runs for plan accuracy) + 0 PUTs + 0 sync_diff updates; output contains `would_push` |
| I    | Pre-applied sync_diff row filtered by query — 0 GETs, 0 PUTs |
| J    | `--correlation-id=cid-OLD` override defeats latest() — only cid-OLD product touched; cid-NEW row stays pending |

## Files

**Created:**
- `app/Console/Commands/PushDivergenceToWooCommand.php` (404 LOC)
- `tests/Feature/Console/PushDivergenceToWooCommandTest.php` (404 LOC)

**Modified:**
- `app/Providers/AppServiceProvider.php` (+9 lines: use import + $commands entry + explanatory docblock)
- `.planning/STATE.md` (+1 row in Quick Tasks Completed table, last_updated bumped)

## Commits (3 atomic)

1. `7f07659` — feat(cutover): products:push-divergence-to-woo command +
   AppServiceProvider registration (260611-g4q)
2. `6d712d5` — test(cutover): push-divergence-to-woo Pest cases A-J
   (260611-g4q)
3. `1b6cc1c` — docs(state): cross-reference 260611-g4q from 260611-f1y row
   (260611-g4q)

Task 1 (probe) + Task 5 (verify) intentionally no-commit per plan spec.

## Post-deploy operator sequence (PROD)

1. Deploy commit `1b6cc1c` (HEAD).
2. `php artisan cutover:divergence-scan --live` — refresh sync_diffs +
   re-baseline parity widget on the 13-field comparator.
3. `php artisan products:push-divergence-to-woo --dry-run` — confirms the
   per-field plan size. Expect ~1,598 stock_quantity / ~1,480 buy_price /
   ~555 category_id eligible. Read the per-field tally rows in the
   outcome table.
4. `php artisan products:push-divergence-to-woo` — live run with
   confirmation prompt. ~3,078 PUT round-trips × ~0.4s wall time ≈
   20-30 min total (single PUT per product carries all 3 fields).
5. `php artisan cutover:divergence-scan --live` — re-scan. Expect parity
   to climb from 45% toward 70%+. Remaining sync_diffs will dominate on
   image_url + exists which need separate triage (out of scope here).
6. If any rows landed status=`woo_not_found`, those Woo products were
   already deleted — review whether MS should soft-delete the mirror.

## Deviations from plan

None. Plan executed exactly as written. Frontmatter scope_probe_output
filled at end of Task 1 confirming all 4 plan assumptions were correct
(SyncDiff payload shape includes product_id, Product.category_ids is
array-of-int, WooFieldComparator::BUY_PRICE_META_KEY is importable,
AppServiceProvider insertion point is adjacent to PushVisibilityToWooCommand).

## Known stubs

None. The command writes directly to Woo via the production `WooClient`
service; no placeholder data sources. The Pest tests use a
`bindDivergenceStub` anonymous-subclass — that's a TEST FIXTURE, not a
production stub (the production code path is fully wired).

## Self-Check: PASSED

- File `app/Console/Commands/PushDivergenceToWooCommand.php` — FOUND.
- File `tests/Feature/Console/PushDivergenceToWooCommandTest.php` — FOUND.
- Commit `7f07659` — FOUND in git log.
- Commit `6d712d5` — FOUND in git log.
- Commit `1b6cc1c` — FOUND in git log.
- STATE.md row for 260611-g4q — FOUND (grep -c → 1).
- `php artisan list` resolves `push-divergence-to-woo` — CONFIRMED.
- Focused tests: 10 passed (87 assertions).
- Full suite: 2,005 passed / 222 failed / 3 skipped (+10 pass / 0 new
  fails vs baseline 1,995 / 222 / 3).
