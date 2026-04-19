# Phase 4: Bitrix24 CRM Sync - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-19
**Phase:** 04-bitrix24-crm-sync
**Areas discussed:** UTM / GA CID capture, Pipeline routing & B2B definition, Webhook scope & order-update behaviour
**Areas deferred to Claude's Discretion:** GDPR erasure workflow (user opted not to discuss interactively; default captured in CONTEXT.md)

---

## UTM / GA CID capture

### Q1 — Transport mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| JS snippet + hidden fields (Recommended) | Custom JS on meetingstore.co.uk reads cookies/query, writes hidden checkout fields, Woo persists to order meta, arrives via order webhook | ✓ |
| Dedicated WP plugin | Install a WP plugin that stores UTMs to order meta | |
| Session cookie → Laravel endpoint | JS POSTs to Laravel, joined by email at webhook time | |

**User's choice:** JS snippet + hidden fields
**Notes:** Lowest-risk mechanism; reuses existing webhook payload flow; no extra WP plugin surface.

### Q2 — Attribution model

| Option | Description | Selected |
|--------|-------------|----------|
| First-touch, 30-day window (Recommended) | Cookie set on first landing, reused on return visits, overwritten only when fresh UTMs arrive | ✓ |
| Last-touch | Cookie overwritten on every UTM arrival | |
| Both, separate Bitrix fields | Capture first and last touch separately | |

**User's choice:** First-touch, 30-day window
**Notes:** Matches B2B considered-purchase buying journey.

### Q3 — Which fields to capture

| Option | Description | Selected |
|--------|-------------|----------|
| Standard 5 + GA CID (Recommended) | utm_source/medium/campaign/term/content + _ga Client ID = 6 Bitrix Deal custom fields | ✓ |
| Standard 5 only | Skip GA CID | |
| Standard 5 + GA CID + gclid/fbclid | Also capture ad-platform click IDs for Phase 8+ offline-conversion upload | |

**User's choice:** Standard 5 + GA CID
**Notes:** gclid/fbclid deferred to Phase 8+ ads-sync.

### Q4 — Capture on customer.created without an order

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — same cookie values, Contact-only fields (Recommended) | Contact-level fields on customer.created; Deal-level copy added when order fires | ✓ |
| No — UTMs only on Deal creation | Contact gets basic fields only | |

**User's choice:** Yes, Contact-only fields
**Notes:** Extension over legacy plugin; lets sales see attribution on registered-but-not-yet-purchased leads.

---

## Pipeline routing & B2B definition

### Q1 — Initial framing (reframed after inspecting legacy plugin)

**User referenced the extracted itgalaxy plugin at `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/woo project/bitrix24-extracted`.** Inspection revealed the plugin does NOT dynamically route orders by attribute — it uses ONE admin-configured pipeline+stage. CRM-07's "Multiple deal pipelines supported" means the admin can *choose* the pipeline, not that routing happens automatically. Initial framing of the question (B2B signal options) was discarded in favour of the reframed version below.

### Q1 (reframed) — How much routing do we actually need in v1?

| Option | Description | Selected |
|--------|-------------|----------|
| Match legacy — single pipeline+stage (Recommended) | Admin picks ONE pipeline_id + landing_stage_id in Filament settings; every Deal lands there | ✓ |
| Legacy + billing-company override | Default pipeline + secondary B2B pipeline when billing.company is present | |
| Full rule-driven routing | `crm_pipeline_routes` table with priorities and match criteria | |

**User's choice:** Match legacy — single pipeline+stage
**Notes:** Zero routing logic; matches current operational behaviour; rule-driven routing deferred as post-cutover enhancement only if ops requests.

### Q2 — Woo order status → Bitrix Deal stage map

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — configurable map (Recommended) | Filament UI, `crm_status_mappings` table, order.updated triggers mapped stage transitions | ✓ |
| Yes — sensible defaults, no UI | Hardcoded map (processing→In Work, completed→Won, etc.); change = deploy | |
| No — creation-only, no transitions | Only order.created pushes; order.updated is no-op | |

**User's choice:** Yes — configurable map
**Notes:** Full parity with legacy `functions/itglx-wcbx24-update-deal-stage.php` behaviour.

---

## Webhook scope & order-update behaviour

### Q1 — Which Woo events drive the sync

| Option | Description | Selected |
|--------|-------------|----------|
| order.created + order.updated + customer.created + customer.updated (Recommended) | Full parity with legacy plugin; 4 idempotent handlers | ✓ |
| order.created + customer.created only | One-shot push on creation; no updates | |
| All four + order.deleted | Also handle hard-deletes | |

**User's choice:** All four core events (not order.deleted)
**Notes:** order.deleted rare in Woo; cancellations flow via order.updated status-map.

### Q2 — What to push on order.updated

| Option | Description | Selected |
|--------|-------------|----------|
| Stage + totals + notes only (Recommended) | Patch STAGE_ID, OPPORTUNITY, append new notes; preserve admin edits in Bitrix | ✓ |
| Full re-push every time | Re-send every configured field; perfect mirror but overwrites manual edits | |
| Stage only | Minimal; order total drift not tracked | |

**User's choice:** Stage + totals + notes only
**Notes:** Narrow patch scope preserves salesperson edits (phone corrections, responsible-user reassignments).

### Q3 — order.updated before order.created race condition

| Option | Description | Selected |
|--------|-------------|----------|
| Delayed re-queue if Deal missing (Recommended) | Up to 5 re-queues with 30s delay; surfaces as `crm_push_failed` suggestion after exhaustion | ✓ |
| Create-on-update fallback | Run full create if Deal missing; masks bugs | |
| Strict ordering via job chaining | Fragile under retry | |

**User's choice:** Delayed re-queue
**Notes:** Uses Laravel's `release($delay)` — no extra scheduler surface.

### Q4 — Retry policy for Bitrix push failures

| Option | Description | Selected |
|--------|-------------|----------|
| 5 attempts, exponential backoff, 4xx fail-fast (Recommended) | 30s, 2m, 10m, 30m, 2h; retries 5xx/network/429 only | |
| 3 attempts, shorter backoff | 30s, 5m, 30m; more ops noise, faster DLQ surfacing | ✓ |
| 10 attempts, longer backoff | Up to 24h of retries; low ops noise | |

**User's choice:** 3 attempts, shorter backoff
**Notes:** MeetingStore is low-volume; fast ops-visibility beats long back-off cushions; stale Deal tomorrow worse than pinged inbox today.

---

## Claude's Discretion

Areas the user did not discuss interactively; sensible defaults captured in CONTEXT.md `<decisions>` section:

- GDPR right-to-erasure workflow (CRM-13) — dual entry-point (CLI + Filament action), PII scrub-in-place on Contact, Deal financial fields preserved, Company not erased, dedicated audit-log entry per erasure
- Contact / Company dedup keys — Contact email (case-insensitive) + phone fallback; Company VAT → name+postcode; Deal UF_CRM_WOO_ORDER_ID exact; `BitrixEntityMap` unique on `(entity_type, woo_id)`
- Field mapping storage + UX — `crm_field_mappings` table seeded from legacy plugin defaults; Filament Resources per entity; "Refresh from Bitrix" button; save-time validation against fresh schema
- `php artisan bitrix:bootstrap` — idempotent custom-field creator, Phase 4's first deliverable
- `php artisan bitrix:backfill-orders --since={date}` — dry-run default, `--since` required, chunk 50, 600ms sleep between chunks
- `CRM_WRITE_ENABLED` shadow-mode gate — parallel to `WOO_WRITE_ENABLED`; planner decides whether to reuse `sync_diffs` or add `crm_push_shadow`
- Queue routing — webhook handlers on `crm-bitrix`, backfill on `sync-bulk` (Pitfall 7 separation)
- Bitrix SDK — `bitrix24/b24phpsdk` ^1.x with `mesilov/bitrix24-php-sdk` ^2.x fallback if sandbox validation during research flags blockers

## Deferred Ideas

- B2B-vs-retail dynamic routing — defer; legacy doesn't do it, ops haven't asked
- Full rule-driven pipeline routing table — defer post-cutover
- gclid/fbclid offline-conversion upload — Phase 8+ ads sync
- Two-way status sync (Bitrix → Woo) — explicit Out of Scope
- Inbound Bitrix webhook — explicit Out of Scope
- Coupon list sync — explicit Out of Scope
- Read-only Bitrix Deal status mirror in Laravel dashboard — reconsider in Phase 7 polish
- order.deleted handling — add only if ops reports dropped deletions
- Bitrix Lead entity mode — fixed to Deal+Contact+Company
- Custom Woo order statuses auto-discovery — Phase 7 dashboard polish candidate
- Full-field re-push on order.updated — reconsider if Bitrix drifts in practice
- Pipeline settings in env vars — rejected in favour of Filament UI (CRM-06)
