# Quick Task 260525-szn — Summary

**Description:** Core-loop #3b (publish auto-drafts on Woo) + pricing schedule + cutover runbook.
**Date:** 2026-05-25 · **Status:** ✅ Shipped + pushed (retroactive GSD record).

## Commits
- `8756ffa` — feat(pricing): schedule daily undercut repricing (08:00, opt-in `PRICING_UNDERCUT_SCHEDULE_ENABLED`)
- `0b181a4` — docs(ops): map the Woo write-cutover runbook (`docs/ops/cutover-runbook.md`, phases A–D)
- `6ce34f6` — feat(autocreate): #3b publish auto-drafts by creating them on Woo
- `6b04ca7` — docs(ops): runbook — obsolete pending statuses need a flip-time Woo push (C-NEW)

## What shipped
- **Pricing schedule:** daily 08:00 `pricing:undercut-competitors --live` in `routes/console.php`, env-gated (`PRICING_UNDERCUT_SCHEDULE_ENABLED`, default false). Pre-cutover only stages sell_price + shadow SyncDiffs.
- **Cutover runbook:** sequences the 12 `cutover:checklist` gates into Phases A (pre-flight) → B (7-day parity) → C (flip + canary) → D (monitoring); folds in #1/#2/ex-VAT; documents the one-switch + AbortGuard; flags VAT-basis check.
- **#3b publish-on-Woo:** `PublishProductJob` rewritten — products with `woo_product_id` → PUT `status=publish`; auto-drafts with no id → POST `/products` (name/slug/sku/price/descriptions/categories/images), back-fill id + Woo-reconciled slug. **Shadow-safe**: pre-cutover records a SyncDiff and the row STAYS in review (not falsely published). Fixed a latent leading-slash `rest_no_route` bug. 5 tests; shadow-verified on prod (Huddly S1 → POST products SyncDiff, stayed draft).
- **Runbook C-NEW:** flagged that local `pending` statuses (from `--flag-obsolete`) need a flip-time Woo push step.

## Notes
Everything gated behind `WOO_WRITE_ENABLED=false`; nothing reaches the live store until cutover.
