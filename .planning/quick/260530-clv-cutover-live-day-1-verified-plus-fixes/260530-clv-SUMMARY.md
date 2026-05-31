---
phase: quick-260530-clv
plan: 01
subsystem: cutover + pricing + auto-create + competitor
tags: [cutover, woo-write-live, schedule-bug, flatsome-parity, ean-gtin, csv-errors-ux]
requires:
  - All v1 cutover gates pre-cleared (12/14 going into 2026-05-30)
  - WooCommerce REST API key with Read/Write scope
  - meetingstoreco_wp28 + admin password rotated pre-flip
provides:
  - v1 cutover LIVE on prod (WOO_WRITE_ENABLED=true, daily reprice scheduled)
  - 5 follow-on features + fixes shipped in the same session
  - Captured env-vs-config lesson in STATE for future ops
affects:
  - Whole pricing flow now writes live to meetingstore.co.uk
  - Stock Updater plugin deactivated (manually, in WP admin)
  - itgalaxy Bitrix plugin intentionally LEFT ACTIVE (CRM half deferred)
tech-stack:
  added: []
  patterns:
    - "WAF compatibility: route PUT through POST for WP-REST resource updates (use_post_for_updates flag)"
    - "Auto-create attribute generation via Claude alongside descriptions → WC attributes[] → Flatsome theme renders the spec table"
    - "EAN persistence with chained fallback: normaliseEan(supplier_db) ?? normaliseEan(sku) — handles manufacturers that put the EAN in the SKU column"
    - "Schedule env-toggles MUST be read via config() not env() in cached-config mode (deploy.sh runs config:cache)"
key-files:
  created:
    - app/Console/Commands/Cutover/PushProductStatusToWooCommand.php
    - tests/Feature/Cutover/PushProductStatusToWooCommandTest.php
    - docs/ops/cutover-flip-day-script.md
    - database/migrations/2026_05_30_200000_add_attributes_json_to_products_table.php
    - database/migrations/2026_05_30_210000_add_ean_to_products_table.php
    - app/Domain/Competitor/Filament/Widgets/CsvParseErrorsByCompetitorWidget.php
  modified:
    - app/Domain/Sync/Services/WooClient.php (use_post_for_updates flag)
    - app/Console/Commands/GenerateProductDraftsCommand.php (attributes[] schema + ean fallback)
    - app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php (attributes + global_unique_id payload)
    - app/Domain/Products/Models/Product.php (ean + attributes_json fillable + cast)
    - app/Domain/Cutover/Services/CutoverChecklistReporter.php (obsolete-statuses-pushed gate)
    - app/Domain/Competitor/Filament/Resources/CompetitorFtpFeedResource.php (4-day stale threshold + inline N-days-old)
    - app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/ListCsvParseErrors.php (header widget + XLSX export action)
    - config/services.php (woo.use_post_for_updates)
    - config/competitor.php (ftp.stale_days 30→4)
    - config/pricing.php (undercut_schedule_enabled — env→config fix)
    - config/cutover.php (divergence_scan_schedule_enabled — env→config fix)
    - config/agents.php (seo_batch_schedule_enabled — env→config fix)
    - routes/console.php (3 env() calls replaced with config())
    - docs/ops/cutover-runbook.md (C-NEW step now built, not just promised)
    - .planning/STATE.md (3 updates over the session)
decisions:
  - "Bitrix CRM cutover DEFERRED — leave itgalaxy plugin running until new app's CRM_WRITE_ENABLED is verified + crm_push_logs migration runs (separate future quick task)"
  - "WAF whitelist for PUT method NOT requested from hosting — code-side POST workaround is durable and avoids ops dependency"
  - "products:push-status-to-woo default = --statuses=pending (not !=publish) after dry-run found 2,121 candidates including 204 already-in-sync drafts"
  - "EAN fallback uses chained normaliseEan(supplier) ?? normaliseEan(sku) so blank/placeholder ean values still trigger SKU fallback (8-14 digit barcode test)"
  - "Stale-feed threshold lowered 30→4 days per operator: competitor feeds refresh daily or every-other-day"
  - "Schedule toggles MUST use config() in cached-config mode — captured as recurring lesson in STATE.md"
metrics:
  duration: ~6 hours over 2026-05-30 evening + 2026-05-31 morning
  completed: 2026-05-31
  tasks: ~12 distinct ship/verify cycles
  files: 23 modified/created (excluding STATE.md/SUMMARY.md)
  commits: 14 (a9d90b3..a14545c)
---

# Phase quick-260530-clv Plan 01: v1 Cutover live + 4 follow-on features + 1 critical schedule bug fix — Summary

The single biggest milestone in this project's life: **meetingstore.co.uk's pricing/stock/auto-create now run through the Laravel app, not the legacy Stock Updater WordPress plugin.** The cutover flipped at 2026-05-30 19:07 UTC. Day 1 (2026-05-31) was verified clean with 3,909 successful Woo POST writes and zero failures.

## What was built / shipped

### The cutover itself

- **WOO_WRITE_ENABLED=true** flipped in prod `.env` at 19:07 UTC 2026-05-30.
- **Stock Updater plugin manually deactivated** in WP admin (WP-CLI not installed on the CWP server, so `cutover:disable-legacy-plugins` returned `manual_required` and the operator clicked Deactivate in WP).
- **itgalaxy Bitrix plugin intentionally left ACTIVE** — the new app's CRM (Phase 4) has `CRM_WRITE_ENABLED=false` + `crm_push_logs` table never migrated, so disabling itgalaxy would have broken lead flow. CRM cutover deferred to a future quick task.
- **3 canary writes verified end-to-end on the storefront**: £0.75 MUYHSMFFADW (Startech Headset Adapter), £351.96 952-000038 (Logitech Rally Mic Pod — floored at 6%), £31.45 5G4AB-USB-C-HUB.
- **1,939 status reconciliations pushed** via the new `products:push-status-to-woo --live` command (live_pushed=1939, shadowed=0, errors=0).
- **PRICING_UNDERCUT_SCHEDULE_ENABLED=true** added to `.env` to enable the daily 08:00 BST auto-reprice cron.

### Emergency mid-cutover fix — WAF blocks PUT method (`fb7ac18`)

First canary push failed with HTTP 403 + a plain Apache page. Diagnosis: the host's Apache/mod_security blocks HTTP PUT to `/wp-json/*` at the WAF layer (POST is allowed through). WordPress REST treats POST and PUT identically for resource-update endpoints (`WP_REST_Server::EDITABLE = POST | PUT | PATCH`), so we route PUTs through POST. New config flag `services.woo.use_post_for_updates` (env `WOO_USE_POST_FOR_UPDATES`, default `true`). `WooClient::put()` now calls `writeOrShadow('POST', ...)` when the flag is on. Zero behavioral change for WC, full bypass of the WAF block. Long-term: ops can whitelist PUT in CWP/Imunify and flip the flag to `false`, but it's not necessary.

### Follow-on 1 — Flatsome layout parity (`8a31731`)

Auto-created products didn't carry `_product_attributes`, so Flatsome's theme rendered them visibly thinner than existing products (the "Additional Information" tab was empty). Fix:
- New `products.attributes_json` JSON column.
- `GenerateProductDraftsCommand` Claude schema extended to return 5-8 spec rows (`name`, `value`), sized for the product type (camera/display/cable/adapter examples baked into the prompt). Brand always first. Trim, length-cap, dedupe-by-name on save.
- `PublishProductJob::buildCreatePayload` maps `attributes_json` to WC REST's `attributes[]` (name + options:[value] + visible:true + variation:false + position). Omitted entirely when empty (Woo would otherwise create empty global attribute rows).

**Operator-verified live on Huddly S1** (Product #5633 → Woo #179682): "THE HUDDLY IS NOW USING THE CORRECT FLATSOME PAGE".

### Follow-on 2 — EAN/GTIN end-to-end (`99d34bd → 812fb29 → c51f674`)

Existing meetingstore.co.uk products carry GTIN as `wp_postmeta._global_unique_id` (used by Google Merchant Center + schema.org product markup). Auto-create wasn't sending it.
- New `products.ean` column (varchar, nullable, indexed).
- `GenerateProductDraftsCommand` persists `ean` from supplier_db facts via chained fallback: `normaliseEan(supplier_ean) ?? normaliseEan(sku)`. The fallback is critical because many manufacturers (Huddly) leave the EAN column blank and use the EAN AS the SKU (e.g. `sku=7090043790993`). `normaliseEan()` strips non-digits + validates 8-14 length + rejects all-zero/all-nine placeholders, so SKUs that aren't barcode-shaped (Sony "FW-50EZ20L") still return null cleanly.
- `PublishProductJob::buildCreatePayload` adds `global_unique_id` to the POST payload when set.

**Strong-six backfill outcome**: 4 of 6 got EANs (Huddly S1 7090043790993, Huddly IQ 7090043790573, Barco 986160001, ViewSonic 4589468732839). The two Sony commercial displays correctly returned null (SKU not barcode-shaped, supplier_db has no real EAN — there's literally no source).

### Follow-on 3 — CSV parse errors triage UX (`cf3fb1b`)

Operator asked: "add a header that shows issues by competitor and allow export to XLS so I can use Claude Code to fix".
- New `CsvParseErrorsByCompetitorWidget` (StatsOverviewWidget) — one tile per competitor with unresolved count + dominant `issue_type`, colour-graded by count (gray ≤10, warning 11-50, danger >50). Orphan rows (null competitor_id) bucket under "(orphan)". Capped at 8 tiles; remainder folds into "+N more competitors" combined tile.
- New "Export unresolved (XLSX)" header action on `/admin/csv-parse-errors`. Dumps every unresolved row to `.xlsx` with the fields needed to round-trip into Claude Code: competitor, filename, issue_type, line_number, raw_line, context (JSON), created_at, ingest_run_id. Spatie SimpleExcelWriter to a temp file → browser download → auto-delete after send.

### Follow-on 4 — Competitor FTP stale threshold 30→4 days (`8aafc3e`)

Operator: "make it more than 4 days old" (the highlighting trigger). `config('competitor.ftp.stale_days')` default lowered 30→4. `CompetitorFtpFeedResource` "Remote File Date" column now renders `YYYY-MM-DD (N days old)` inline so staleness is readable at a glance, plus a hover tooltip explaining the red colouring when it fires.

### 🚨 Critical fix — env() vs config() in cached-config mode (`d7d0e39`)

**The bug that broke Day 1's first 08:00 BST auto-reprice fire.**

`routes/console.php` wrapped 3 schedule registrations in `if ((bool) env('PRICING_UNDERCUT_SCHEDULE_ENABLED', false))` etc. When `deploy.sh` runs `php artisan config:cache`, `env()` calls **outside `config/*.php`** return the DEFAULT value, so the `if` short-circuited and the schedule never registered. No log entry, no error — silently invisible.

`schedule:list` on prod 2026-05-31 morning confirmed: `pricing:undercut-competitors --live` was MISSING from the registered schedules despite `PRICING_UNDERCUT_SCHEDULE_ENABLED=true` in `.env`.

Fix (applied to all 3 env-gated schedules):

```php
// config/pricing.php
'undercut_schedule_enabled' => (bool) env('PRICING_UNDERCUT_SCHEDULE_ENABLED', false),

// routes/console.php
if ((bool) config('pricing.undercut_schedule_enabled')) { Schedule::command(...); }
```

Same pattern applied to `agents.seo_batch_schedule_enabled` and `cutover.divergence_scan_schedule_enabled` (which were latent-broken in the same way).

**Verified working:** Post-deploy `schedule:list | grep undercut` now shows `0 7 * * * php artisan pricing:undercut-competitors --live ... Next Due: 23 hours from now`. `schedule:test` ran the command through the scheduler pipeline (1m 25s clean). `crontab -l -u stcav` confirms `* * * * * /bin/php artisan schedule:run` is registered. `dashboard_snapshots.computed_at` showed the every-5-min `dashboard:refresh` cron firing during the same window, proving the OS cron is alive.

**Day 1 manual recovery (because schedule missed)**: ran `pricing:undercut-competitors --live` by hand. Evaluated 5,292 products, changed 5,279 (3,345 undercut, 1,015 floored, 928 cost-plus margin). Resulted in 3,909 real Woo POST writes (avg 484ms latency, zero failures, zero failed jobs).

### Cutover-runbook & checklist artefacts

- New `docs/ops/cutover-flip-day-script.md` (`c3b8848`) — one-page copy-paste sequence for go-live morning. Eight numbered steps with the exact commands + kill-switch documented top + rollback decision tree bottom.
- `cutover-runbook.md` C-NEW step updated to describe the now-built `products:push-status-to-woo` command instead of "not yet implemented".
- New checklist gate `obsolete-statuses-pushed` (between `flag-flip` and `monitoring-7-days`). Marked PASS after the 1,939 status reconciliations.

## Verification results

| Gate | Command | Result |
|------|---------|--------|
| Cutover checklist after flip | `cutover:checklist` | **12 PASS / 3 PENDING** (post-flip-only or optional) |
| Total Woo writes Day 1 (status + canary + auto-reprice + Huddly tests) | `integration_events` query | **~5,855 successful, zero failed** |
| Auto-reprice queue drain | `queue:failed` | **0** all day |
| AbortGuard trips | Home Dashboard | **0** |
| Tomorrow's auto-reprice fire confirmed | `schedule:test --name=...` | **1m 25s clean** + `schedule:list` shows Next Due 23h |
| OS cron alive | `crontab -l -u stcav` + `dashboard_snapshots` 5-min freshness | **both confirmed** |
| Operator visual on Huddly storefront | manual browser check | **price + Flatsome layout + attributes tab all correct** |

## Deviations from Plan

- **No formal SPEC/PLAN/CONTEXT** — this was operator-driven incident response + ad-hoc feature shipping, recorded retroactively here. Each commit is self-contained.
- **Bitrix CRM half deferred** — not a deviation, an explicit choice. Documented in STATE + memory as a future migration.
- **WAF whitelist for PUT method NOT requested from hosting** — chose code-side POST workaround instead. The `services.woo.use_post_for_updates` flag means we can flip back to strict PUT if/when ops whitelists PUT later.

## Known Stubs / Follow-ups for next session

- **CRM cutover** — wire new app's Bitrix domain (`CRM_WRITE_ENABLED`, run `crm_push_logs` migration if any, verify push, then disable itgalaxy plugin).
- **Approve the other 5 strong-six** (5634/5635/5636/5637/5638) via `/admin/auto-create-reviews` — all backfilled with attributes_json + ean and ready.
- **Minor data-quality fixes flagged but not blocking:**
  - `supplier_products.title` has double-encoded HTML entities (`&amp;quot;` for `"`) — Claude handles in-band but our `supplier_title` fallback would carry the ugly form. Defensive `html_entity_decode(html_entity_decode(...))` is the fix.
  - `normaliseEan` accepts 8-14 digits inclusive (non-standard GTIN sizes like 9 digits pass through). Tighten to `in [8, 12, 13, 14]` if Google Merchant Center rejects.

## Threat Flags

None new. The PUT→POST workaround uses the same auth scope as the original PUTs (Read/Write WC REST key), same Woo endpoint, same payload shape. No new external services. No new schema secrets.

## Commits

```
a14545c  docs(state): lock in Day 1 LIVE 2026-05-31 + capture env() vs config() lesson
d7d0e39  fix(scheduler): use config() not env() for 3 schedule toggles
cf3fb1b  feat(competitor): CSV parse errors — per-competitor widget + XLSX export
8aafc3e  feat(competitor): lower FTP feed stale threshold 30→4 days + show age inline
69d0092  docs(state): record full 2026-05-30 cutover + auto-create follow-ons
c51f674  fix(auto-create): EAN fallback triggers when supplier value invalid
812fb29  fix(auto-create): fall back to SKU as EAN when supplier_db blank
99d34bd  feat(auto-create): persist EAN + push to Woo as global_unique_id
8a31731  feat(auto-create): generate + push product attributes for Flatsome parity
ce7bad5  docs(state): 🎉 cutover live 2026-05-30 19:07 UTC
fb7ac18  fix(woo): route WooClient::put() through POST for WAF compatibility
c3b8848  docs(cutover): flip-day script — single copy-paste sequence for go-live
28420aa  fix(cutover): tighten products:push-status-to-woo default to --statuses=pending
a9d90b3  feat(cutover): products:push-status-to-woo (C-NEW) + obsolete-statuses-pushed gate
```

## Self-Check: PASSED

- `app/Console/Commands/Cutover/PushProductStatusToWooCommand.php` — FOUND
- `docs/ops/cutover-flip-day-script.md` — FOUND
- `database/migrations/2026_05_30_200000_add_attributes_json_to_products_table.php` — FOUND
- `database/migrations/2026_05_30_210000_add_ean_to_products_table.php` — FOUND
- `app/Domain/Competitor/Filament/Widgets/CsvParseErrorsByCompetitorWidget.php` — FOUND
- Cutover checklist on prod: **12 PASS / 3 PENDING** (verified post-deploy)
- Auto-reprice schedule on prod: **registered, Next Due ~23h** (verified `schedule:list`)
- Storefront on prod: live + Huddly renders Flatsome layout correctly (operator-verified)
