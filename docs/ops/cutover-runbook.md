# MeetingStore Ops — Woo Write Cutover Runbook

**Purpose:** the ordered, copy-pasteable sequence to safely flip `WOO_WRITE_ENABLED=false → true` so the app starts writing to the live WooCommerce store (prices, products, sync). Pairs with `docs/ops/cutover-handover.md` (ops handover) and the live gate tracker:

```bash
php artisan cutover:checklist          # exit 0 = every gate PASS = GO
php artisan cutover:checklist --update-status=<gate-id>:pass   # mark a manual gate done
```

> Status at time of writing: `WOO_WRITE_ENABLED=false` (shadow mode). Every Woo write is captured as a `SyncDiff` instead of being sent. Nothing below touches the live store until **C2 (the flip)**.

---

## The one switch + safety net
- **Switch:** `WOO_WRITE_ENABLED` in the production `.env`. `false` → shadow (SyncDiff). `true` → live writes via `WooClient::writeOrShadow`.
- **Instant kill:** set it back to `false` + `php artisan config:clear`. Stops all Woo writes immediately.
- **Auto kill:** `AbortGuard` trips on >20% error rate or 50 consecutive failures in a sync window.

## What actually goes live on the flip
1. **Price pushes** — `PushPriceChangeToWoo` starts PUTting `regular_price` to Woo on every `ProductPriceChanged` (from `pricing:undercut-competitors --live` and supplier-driven recompute).
2. **Supplier sync writes** — Phase 2 `SyncChunkJob` price/stock updates.
3. **Draft publishes** — `PublishProductJob` (review-inbox "Approve") for products **that already have a `woo_product_id`**.
4. ⚠️ **NOT** the auto-drafted products from `generate-drafts` / `draft-competitor-skus` — they have no `woo_product_id` yet. See **Known Gap** below.

---

## Phase A — Pre-flight (do anytime; parallel-safe, no live writes)
| Gate | Command |
|---|---|
| `woo-db-snapshot` | `php artisan cutover:snapshot-woo-db --label=pre-cutover` |
| `feature-suite` | `vendor/bin/pest --compact` then `cutover:checklist --update-status=feature-suite:pass` |
| `woo-sandbox` | Manual: POST a product with `images[]` to a Woo sandbox; confirm `images[0].src` resolves under `/wp-content/uploads/`; then `--update-status=woo-sandbox:pass` |
| `supplier-probe` | **Likely N/A for your setup** — this gate covers the `supplier_api` auto-create path (`CreateWooProductJob`). You use `supplier_db` + the new draft pipeline, so mark it `--update-status=supplier-probe:pass` only after deciding whether the supplier-sync auto-create path is in scope. |

**A-NEW — confirm the Woo price VAT basis.** Pick a known product, compare its `regular_price` in Woo against its storefront display:
- Woo enters prices **inc-VAT** → leave `WOO_PUSH_PRICES_EX_VAT=false` (default).
- Woo enters prices **ex-VAT** → set `WOO_PUSH_PRICES_EX_VAT=true`.

Getting this wrong is a 20% price error on every push. (Competitor feeds are ex-VAT and you quote trade, so ex-VAT is plausible — verify, don't assume.)

## Phase B — Parity / parallel-run (~7+ days, still no live writes)
1. Enable the parallel-run scan: `CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED=true` (runs `cutover:divergence-scan --live` daily 01:00).
2. `divergence-scan` + `parity-threshold` gates: watch the **SyncDiffsParity** widget on `/admin` → green when parity ≥ `config('cutover.parity_threshold_percent')` (99%) sustained over the 7-day window.
3. `populate-overrides`: `php artisan cutover:populate-overrides --live` (pins fields that legitimately diverge so they aren't overwritten).
4. `drill-rollback-staging`: on **staging only** — `CUTOVER_DRILL_ALLOWED=true php artisan cutover:drill-rollback --live` then `--update-status=drill-rollback-staging:pass`.

**B-NEW — preview the pricing before it can write.**
- `php artisan pricing:floor-report` — confirm the floor (6%) + below-cost exceptions.
- `php artisan pricing:undercut-competitors` (dry-run) — eyeball the price changes.
- *Optional:* set `PRICING_UNDERCUT_SCHEDULE_ENABLED=true` now to stage `sell_price` daily and review the **shadow** `SyncDiff` rows (the exact `regular_price` PUTs that will fire) before flip.

## Phase C — The flip
1. `legacy-plugins-disabled`: `CUTOVER_DISABLE_LIVE_ALLOWED=true php artisan cutover:disable-legacy-plugins --live` — deregisters the legacy Stock Updater + itgalaxy Bitrix crons and deactivates the plugins (parallel-run ends here). Then `--update-status=legacy-plugins-disabled:pass`.
2. **`flag-flip`** — in production `.env`: `WOO_WRITE_ENABLED=true`, then `php artisan config:clear`. Then `--update-status=flag-flip:pass`.
3. **CANARY before mass pricing** — do NOT enable the daily reprice in the same breath as the flip. First push a handful:
   ```bash
   php artisan pricing:undercut-competitors --skus=<2-3 known SKUs> --live
   ```
   Verify those 2-3 prices on the live storefront. Only then enable the daily schedule: `PRICING_UNDERCUT_SCHEDULE_ENABLED=true`.
4. *(If using Bitrix quote push)* `php artisan bitrix:quotes-bootstrap` then `--update-status=bitrix_quote_type_id_verified:pass`, and flip `QUOTE_BITRIX_PUSH_ENABLED=true`.

## Phase D — Post-flip monitoring
1. `monitoring-7-days`: watch the Home Dashboard + AbortGuard daily for 7 clean days; then `--update-status=monitoring-7-days:pass`.
2. `weekly-digest-landed`: confirm the Monday 07:00 digest sent.
3. The Sunday 14:00 `products:draft-competitor-skus` runs automatically — review its drafts in the Auto-Create inbox.

---

## ⚠️ Known Gap — publishing auto-drafted products to Woo
`products:generate-drafts` and `products:draft-competitor-skus` create **local** draft Products with **no `woo_product_id`**. The review-inbox "Approve" action (`PublishProductJob`) only flips `status=publish` on a product that **already exists on Woo** — it does not CREATE the product on Woo. So "manual movement to live" for an auto-drafted product currently has no path to actually create it on the store.

**To close the loop**, a small build is needed (call it #3b): a "create on Woo" step that POSTs the reviewed draft to `/products` (status=draft or publish), back-fills `woo_product_id` + `slug` + the images/categories, and is wired into the review Approve action — OR route reviewed drafts through the existing `CreateWooProductJob` (note: it currently requires `supplier_api` data and a different trigger). Build this before relying on the Sunday auto-draft → publish flow end-to-end.

The **pricing** half of the loop (#1 undercut + #2 push) is unaffected by this gap — it operates on existing Woo products that already have a `woo_product_id`.

---

## Go / No-Go
```bash
php artisan cutover:checklist        # all PASS + exit 0 = GO
```
Rollback at any point: `WOO_WRITE_ENABLED=false` + `php artisan config:clear`.
