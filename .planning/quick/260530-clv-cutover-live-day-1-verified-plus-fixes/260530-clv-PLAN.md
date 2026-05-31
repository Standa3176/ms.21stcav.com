---
phase: quick-260530-clv
plan: 01
title: v1 cutover go-live + same-session follow-ons
recorded: retroactive (operator-driven incident response + ad-hoc feature shipping)
started: 2026-05-30 (late afternoon UK)
completed: 2026-05-31 (early morning UK)
---

# Quick Task 260530-clv — Plan

## Request

> ok give me the steps to flip

→ Operator initiated the v1→Laravel cutover after weeks of preparation (12 of 14 checklist gates already PASS). The single canonical flip is `WOO_WRITE_ENABLED=true`. Plan: walk the operator through the gates that remained, the flip itself, the canary, the obsolete-statuses push, the schedule enable, and the 7-day monitoring window.

## Scope as it actually played out

Originally a single "flip the cutover" task; expanded over the session to include 5 follow-on features + 1 critical scheduler bug fix as issues surfaced during go-live.

### Phase A — Pre-flip paperwork (gates clearing)
- supplier-probe → PASS (N/A for supplier_db setup)
- woo-sandbox → PASS (#3b shadow proof accepted as equivalent)
- drill-rollback-staging → PASS (no staging env; snapshot-restore rehearsal documented as waiver)
- VAT basis re-verified inc-VAT → `WOO_PUSH_PRICES_EX_VAT=false` stays
- Hygiene: GitHub PAT revoked, admin password rotated, 2 WP DB passwords rotated

### Phase B — The flip (~10 min)
- `cutover:disable-legacy-plugins --live` → all `manual_required` (WP-CLI not on the CWP server) → operator deactivated Stock Updater in WP admin manually; left itgalaxy Bitrix plugin ACTIVE
- `WOO_WRITE_ENABLED=false → true` in prod `.env` + `config:clear` + `horizon:terminate`
- Canary push of MUYHSMFFADW failed with HTTP 403 → diagnosed as hosting WAF blocking PUT method → shipped PUT→POST WooClient patch
- After patch: 3 canary writes verified live on storefront

### Phase C — Same-session follow-ons (5 features + 1 critical bug fix)
- C-NEW step: `products:push-status-to-woo` command built + ran cleanly (1,939 status reconciliations, errors=0)
- Auto-create attributes_json → Flatsome layout parity (verified live on Huddly)
- EAN/global_unique_id end-to-end (live on Huddly)
- CSV parse errors widget + XLSX export for Claude Code triage
- Competitor FTP stale threshold 30→4 days + inline "N days old"
- Schedule env→config bug discovered + fixed (the bug that caused Day 1's first auto-reprice to silently miss)

### Phase D — Day 1 verification
- Manual recovery of missed 08:00 BST reprice: 5,279 changes, 3,909 successful Woo POSTs, zero errors
- `schedule:test` confirms scheduler pipeline works
- `crontab -l` + `dashboard_snapshots` 5-min freshness confirms OS cron is alive
- `schedule:list` shows the auto-reprice entry with Next Due 23h
- STATE.md + cross-session memory updated to lock in Day 1 LIVE state + the env-vs-config lesson

## Out of scope

- **CRM cutover** — itgalaxy plugin remains active. Separate future quick task once new app's CRM is wired + verified.
- **WAF whitelist for PUT method on the hosting side** — code-side POST workaround is durable; ops dependency avoided.
- **Approving the other 5 strong-six auto-create drafts** — they're backfilled with attributes_json + ean and ready; operator clicks Approve in `/admin/auto-create-reviews` when ready.
- **7-day monitoring window completion** (2026-06-06) and weekly digest gate (next Monday) — calendar events, not work.

## Why retroactive

This wasn't a pre-planned quick task — it was 6 hours of operator-driven incident response + opportunistic feature shipping during go-live. Recording retroactively for project continuity + the env-vs-config lesson capture.
