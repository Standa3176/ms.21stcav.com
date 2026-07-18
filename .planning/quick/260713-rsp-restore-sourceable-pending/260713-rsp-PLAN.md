# 260713-rsp — Restore wrongly-flagged sourceable `pending` products (local-only)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Context:** cutover audit found 1,568 products `status=pending` + `woo_product_id` NOT NULL (a third of
the live catalogue). Of these, **162 are sourceable** (present in `supplier_sku_cache`) yet flagged
pending — a demotion that looks wrong. These products are still `publish` on the live Woo store (the
`--flag-obsolete` demotion is LOCAL-only, queues no Woo write). This task restores the wrongly-demoted
ones locally so the local DB realigns with the live store BEFORE cutover (so the cutover status-push
doesn't sweep sellable products off the shop).

## CRITICAL — investigate before building (Task 1)
Do NOT assume "in `supplier_sku_cache` ⇒ should be published." First understand the demotion logic so the
restore is CONSISTENT and won't just churn:
- Find how `supplier:db-sync --flag-obsolete` (and `products:flag-missing-buy-price`) decide to set
  `status='pending'` — what "no supplier offer" means (live in-stock offer via feeds_products/
  stockseparate / LiveSupplierStockResolver? vs merely SKU-listed in `supplier_sku_cache`?).
- Determine the real reason the 162 are both "in supplier_sku_cache" AND flagged pending. Likely one of:
  (a) listed by a supplier but currently OUT OF STOCK (no live offer) → demoted correctly-ish;
  (b) a matching/timing gap → demoted wrongly.
- Report the **in-stock breakdown** of the 162: how many have a CURRENT in-stock supplier offer vs are
  listed-but-out-of-stock. And assess **churn risk**: would the next `supplier:db-sync --flag-obsolete`
  re-demote whatever this command restores? (If yes, the restore alone is a band-aid and the root cause
  needs a separate fix — say so clearly in the SUMMARY; do NOT silently ship a command that fights the sync.)

Write the findings into the SUMMARY. If the investigation shows most of the 162 are out-of-stock (so
demotion was arguably correct), STOP and report back rather than mass-restoring — flag for the operator.

## Task 2 — `products:restore-sourceable-pending` command (TDD)
Build an artisan command that restores the correctly-identified wrongly-flagged cohort to the live/active
status LOCALLY:
- **Scope (default):** `status='pending'` AND `woo_product_id IS NOT NULL` AND has a **current supplier
  offer** (use the same in-stock/offer signal `flag-obsolete` uses to KEEP a product published — the
  consistent inverse, so restored rows survive the next sync). Respect the producer carve-outs the
  runbook honours (`is_custom_ms` / `exclude_from_auto_update`) — do not touch those.
- Sets `status` back to the active/published value (whatever the pre-demotion live status is — confirm
  the Product status vocabulary; on-Woo products are `publish`). LOCAL-ONLY — this command MUST NOT call
  WooClient / push to Woo (the products are already `publish` on Woo; realigning local status needs no
  write). Assert this in a test (no Woo HTTP, WOO_WRITE_ENABLED irrelevant).
- **`--dry-run` is the DEFAULT** (report the cohort + counts, change nothing); `--live` applies. Also
  support `--include-listed-out-of-stock` (opt-in) if the operator later wants to restore the broader
  supplier_sku_cache set — but default to the strict in-stock signal.
- Prints a summary: how many restored, how many skipped (carve-outs / out-of-stock), and a sample.
- Idempotent; re-runnable.

## Verify
- `pest`: the command restores an in-stock pending+on-Woo product to publish; SKIPS a
  listed-but-out-of-stock one (unless the opt-in flag); SKIPS `is_custom_ms`/`exclude_from_auto_update`;
  `--dry-run` (default) changes nothing; makes NO Woo/WooClient call (assert). Driver-portable.
- `php artisan route:list --path=admin` exit 0 (no admin change, but confirms boot); `pint`; `deptrac 0`.

## Guardrails / out of scope
- LOCAL status realignment only. No Woo writes, no cutover flag change, no status-push to Woo, no
  migration. This does NOT flip `WOO_WRITE_ENABLED`.
- Does NOT decide the fate of the ~1,400 no-supplier products — that's a separate operator decision.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260713-rsp-SUMMARY.md` — MUST include the Task-1 findings (why the 162 were demoted, in-stock
  breakdown, churn-risk verdict) and the command usage. If the investigation says most are legitimately
  out-of-stock, report that instead of mass-restoring.
