# MeetingStore cutover plan — flip WOO_WRITE_ENABLED=true (app becomes live source of truth)

**Status:** PLAN for operator review. No writes until each phase is explicitly approved + run together.
**Grounding:** cutover-readiness assessment 2026-08-09 (all file:line verified). The one gate is
`services.woo.write_enabled` read only in `WooClient::writeOrShadow`. Throttle (260719-wth) + its cache-key
fix (260726-wtc) are present and proven (brand-retag canary). Rollback = set flag false + `config:clear`.

## What the flip turns live (first night, no extra flags)
- **23:00 `cutover:auto-sync --field=stock_quantity,buy_price`** — live PUT of stock/buy-price divergences, ≤500/night. (category_id already dropped — T0.)
- **07:25 `products:restore-sourceable-pending --push-to-woo`** — status only, ~7 products.
- **Mon+Thu 05:00 `draft-from-suggestions --auto-approve`** — straight-to-live publish, ≤25 SKUs.
- Event-driven push (`event_driven_push_enabled`), `pricing:undercut-competitors`, and `sync:supplier`
  all stay OFF (separate flags / commented). Leave them off at flip.

## Phase 0 — Readiness (READ-ONLY, do first; this is the real go/no-go)
1. **Divergence scan → parity number.** `php artisan cutover:divergence-scan --live` then read
   `cutover:checklist`. This computes how far local stock/price diverges from live Woo and the parity %.
   **This is the gate that decides everything** — if parity is low, flipping would push a lot of local
   values over live; we review the magnitude BEFORE any flip. Target: ≥99% sustained 7 days (code checks
   the latest snapshot; the 7-day part is operator discipline — watch the widget daily).
2. **Confirm VAT basis** — `WOO_PUSH_PRICES_EX_VAT` (default false = inc-VAT) vs how the storefront stores
   prices. A wrong basis = 20% error on every price push. Verify against a known product.
3. **Snapshot + rollback drill** — `cutover:snapshot-woo-db`; rehearse the flag-false rollback.
4. **Confirm** Horizon `woo-writes` supervisor is running and healthy.

## Phase 1 — Housekeeping (before flip)
1. **Clear stale failed jobs** (July-19 `sync-woo-push` + July-01 `RunAutoCreatePipelineJob`). They do NOT
   auto-retry — but a careless `queue:retry all` post-flip would replay them as live writes. `queue:forget`
   / `queue:flush` them (after a quick look) so they can't.
2. Work the `cutover:checklist` gates (woo-db-snapshot, obsolete-statuses-pushed, etc.) to green.

## Phase 2 — Curate the 388 backlog (READ-ONLY)
1. `php artisan products:publish-drafts --dry-run` — shows which drafts are publishable (brand + category +
   images present) vs skipped, without writing.
2. Review/cull: some drafts are 2 months old (prices/stock/competitors moved). Decide the wanted set
   (`--skus=` list or accept the filtered set). Don't blanket-publish all 388.

## Phase 3 — The flip (quiet hour, supervised, watch load)
1. `WOO_WRITE_ENABLED=true` in `.env` → **`php artisan config:clear`** (NOT config:cache — env/config trap).
2. Confirm `config('services.woo.write_enabled') === true`.
3. **Canary:** `products:publish-drafts --limit=3` (or a specific `--skus`). Watch `uptime`/Horizon.
   Verify on Woo: products live, price correct (VAT), `pa_*` attributes taxonomy-linked, images sideloaded.
4. If clean, let it sit; watch the first **23:00 auto-sync** (or run manually with
   `--field=stock_quantity,buy_price` — NEVER default `--field`, that re-adds category_id).

## Phase 4 — Publish the backlog (batched, off-peak)
`products:publish-drafts --limit=N` in batches over the curated set, watching load between batches. 388
drafts ≈ 800-1,200 serialized writes (Path B is POST + price-PUT + brand-tax); ~15-20+ min single-file even
throttled. Pace it; Ctrl-C is safe (idempotent — published rows get a woo_product_id and are skipped).

## Phase 5 — FacetWP retroactive apply (T12) — inside the write window
1. Deploy T11 (pushed, not deployed).
2. Build + run **T12 = add-only retroactive attribute apply**: for each existing product, read current Woo
   attributes, write ONLY absent `pa_*` taxonomies (NEVER overwrite the hand-populated live values), with a
   dry-run add-vs-overwrite report first. Throttled, batched, off-peak.

## Phase 6 — Stabilise + finalise
1. Monitor parity + load for the agreed window (7 days).
2. Only then canary + enable `pricing:undercut-competitors` (`PRICING_UNDERCUT_SCHEDULE_ENABLED`).
3. Disable the legacy WP plugins (Stock Updater / Bitrix) — the parallel-run→disable final step.

## Footguns (brief every operator)
- Manual `cutover:auto-sync` / `products:push-divergence-to-woo` with DEFAULT `--field` pushes `category_id`
  over the hand-cleaned Woo categories. ALWAYS pass `--field=stock_quantity,buy_price`.
- After setting the flag, `config:clear` — otherwise cached config hides the flip.
- Don't enable undercut/event-driven at the same moment as the flip.
- All live-write ops as the `stcav` user (root git/artisan aborts or leaves root-owned files).

## Rollback (any time)
`WOO_WRITE_ENABLED=false` → `php artisan config:clear`. Instantly back to shadow (no-op) mode. In-flight
throttled jobs finish or requeue harmlessly.
