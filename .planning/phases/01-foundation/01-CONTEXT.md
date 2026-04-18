# Phase 1: Foundation - Context

**Gathered:** 2026-04-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 1 delivers the cross-cutting infrastructure the other six phases depend on: a Laravel 12 + Filament 3.3 admin app with role-based access, a modular `app/Domain/<Module>/` layout enforced by Deptrac, a domain event bus with correlation-ID threading, audit/integration/suggestions persistence and Filament viewers, HMAC-verified Woo webhook intake, Horizon supervisors across 7 named queues, Redis persistence, failed-job alerting, scheduled retention prunes, and the `WOO_WRITE_ENABLED` shadow-mode write gate. No feature work (supplier sync, pricing, CRM) in this phase — only the seams those later phases plug into.

Scope is fixed by ROADMAP.md Phase 1 and REQUIREMENTS.md FOUND-01 through FOUND-13. Discussion clarified implementation of the cross-cutting concerns; it did not add new capabilities.

</domain>

<decisions>
## Implementation Decisions

### RBAC — depth and scope (FOUND-01)

- **D-01:** Use `bezhansalleh/filament-shield` with **auto-generated per-Resource permissions**. Shield's `shield:generate` command produces one permission per (Resource × action) pair — `view_any`, `view`, `create`, `update`, `delete`, `restore`, `force_delete`. Seeders assign these to the 4 roles. Coarse role-in-code checks are NOT sufficient — Filament convention and future-proofing both favour full Shield adoption.
- **D-02:** Role access split by responsibility (tight, not uniform):
  - `admin` — all Resources, all actions
  - `pricing_manager` — `Product`, `PricingRule`, `CompetitorPrice`, `SyncRun` (read only for sync; CRUD on rules and products)
  - `sales` — `CrmPushLog` (read only), own `ActivityLog` entries (read only)
  - `read_only` — every page `view_any` / `view` only; no create/edit/delete anywhere
- **D-03:** Phase 1 must ship a seeder that idempotently (a) creates the 4 roles, (b) runs `shield:generate`, (c) assigns the permission set defined in D-02. The seeder runs on every deploy — role drift in prod is a deploy-time correction, not a manual ops task.

### Retention SLAs (FOUND-12)

- **D-04:** `audit_log` (spatie/activitylog) → **365 days**. Covers annual compliance audits, "who changed this price last year?" forensics. Expect ~1M rows/year at current catalogue size.
- **D-05:** `integration_events` (every outbound API call) → **90 days**. Rolling 3-month forensics window. Highest-volume log — Sentry captures failure signals independently.
- **D-06:** Competitor CSV source files + `csv_parse_errors` → **90 days** (matches COMP-12 default in REQUIREMENTS.md). Trend analysis beyond 90 days reads from persisted `competitor_prices` (never pruned per COMP-07).
- **D-07:** `sync_errors` → **90 days**. Long enough to investigate recurring failures.
- **D-08:** `sync_diffs` (shadow-mode diffs) → **never prune while `WOO_WRITE_ENABLED=false`** (they are the parity evidence for the Phase 7 cutover gate). Post-cutover (when the flag flips to `true`), prune to **30 days**.
- **D-09:** All retention prunes run as scheduled artisan commands dispatched via `routes/console.php`, not via model observers or cron directly. Prune commands log counts to `audit_log` so retention enforcement is itself auditable.

### Admin alerting (FOUND-11)

- **D-10:** Channel: **email only** (not Slack). Uses Laravel's configured mail driver via `spatie/laravel-failed-job-monitor`. Slack can be added post-cutover if email proves too slow.
- **D-11:** Severity routing: **single distribution — all failures to the same destination list**. No split between critical / non-critical queues in v1. Revisit after a month of real traffic volume.
- **D-12:** Recipient list is **database-backed and managed via Filament** (not `.env`, not hardcoded). **Adds a new model + Resource to Phase 1 scope:** `AlertRecipient` (email, name, is_active, created_at). Admin-only Resource, uses Shield permissions from D-01.
- **D-13:** Duplicate-alert suppression: **same failure signature within a rolling 5-minute window produces one alert**, not N. Use the monitor package's built-in dedup or a custom throttle layer around the notifier. No quiet hours — outages never get suppressed by time-of-day.

### Suggestions schema and apply path (FOUND-06)

- **D-14:** Suggestions table uses a **generic JSON payload** shape:
  - `id` (ulid)
  - `kind` (string enum — `margin_change`, `crm_push_failed`, `new_product`, etc; producers register their kinds)
  - `status` (enum — `pending`, `approved`, `rejected`, `applied`, `failed`)
  - `correlation_id` (string, indexed — see D-16)
  - `payload` (JSON — the proposed change)
  - `evidence` (JSON — source data that produced the suggestion)
  - `proposed_by` (nullable morph — user or agent/producer class name)
  - `proposed_at`, `resolved_by_user_id`, `resolved_at`
  - `rejection_reason` (nullable text)
  - `applied_at` (nullable timestamp)
  - Indexes: `(kind, status)`, `(correlation_id)`, `(status, proposed_at)`
- **D-15:** `approve` action **enqueues an `ApplySuggestionJob`** on the `default` queue — never synchronous in the Livewire request. The job resolves a `SuggestionApplier` per `kind` and executes the change, logs to `integration_events`, and emits the appropriate domain event. Idempotent via a guard on `status=applied`. Failures flip status to `failed` and surface in the notification centre (Phase 7).
- **D-16:** Every suggestion row carries an **indexed `correlation_id` column** that threads through `audit_log` → `integration_events` → `suggestions` → emitted domain events. Satisfies FOUND-03 traceability end-to-end. "Why does this suggestion exist?" is a single SQL join on `correlation_id`.
- **D-17:** Phase 1 ships the `suggestions` table, the Filament inbox Resource, the `SuggestionApplier` contract, a seeded no-op test suggestion, and a stubbed `SuggestionPolicy` (Shield-wired). Phase 5 is the first real producer; Phase 6 is the second. The stub applier path exists so Phase 1's "seeded test suggestion approve/reject works" success criterion is verifiable.

### Claude's Discretion

The following areas were not discussed — planner/researcher may pick the default best-practice approach:

- **HMAC secret management** (FOUND-07) — single env var per webhook source, rotatable without code deploy; document rotation runbook in Phase 7 cutover docs. Research phase should confirm WooCommerce's recommended HMAC scheme (SHA256 + base64 on raw body).
- **Correlation ID generation** (FOUND-03) — UUIDv4 generated at entry (webhook handler, scheduled job boot, artisan command boot); middleware injects into request context; logger + event bus read from context; downstream HTTP calls propagate as `X-Correlation-Id` header. If an inbound request already carries `X-Request-Id` or `X-Correlation-Id`, honour it; otherwise generate.
- **Deptrac layer enforcement scope** (FOUND-02) — enforce `app/Domain/<Module>/` cross-domain imports only. Do NOT also enforce Controller→Service→Domain layering in Phase 1 (Laravel's folder convention handles this implicitly; adding a second ruleset is more friction than value at this stage).
- **Horizon supervisor config exact shape** (FOUND-09) — researcher to derive per-queue worker counts and timeout values based on Woo's 100-req/min rate limit and Bitrix's 2-req/sec ceiling.
- **Redis persistence exact config values** (FOUND-10) — `appendonly yes` + `appendfsync everysec` is the baseline per REQUIREMENTS.md; researcher to confirm memory/eviction policy for same-VPS deployment.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Research artifacts (authored 2026-04-18)

- `.planning/research/STACK.md` — Exact package choices with pinned versions, Supervisor + cron config templates, Filament 3 vs 4 caveats, Tailwind 3 pinning requirement
- `.planning/research/ARCHITECTURE.md` — Module boundary enforcement details, event bus design, Horizon queue segregation rationale, shadow-mode write gate pattern
- `.planning/research/FEATURES.md` — Per-FOUND-* implementation notes where research already drilled deeper than REQUIREMENTS.md
- `.planning/research/PITFALLS.md` — Known traps (Filament v4 breaking changes, Bitrix auth caveats, Redis persistence gotchas on Windows, Deptrac false-positives)
- `.planning/research/SUMMARY.md` — Research overview and "Gaps to Address" list (the Phase 1 items: retention, roles, rollback SLA — this CONTEXT.md resolves the first two)

### Project-level foundations

- `.planning/PROJECT.md` — Core Value, Constraints (event-driven, audit everything, suggestions pattern, feed abstraction), Key Decisions table
- `.planning/REQUIREMENTS.md` — FOUND-01 through FOUND-13 acceptance criteria
- `.planning/ROADMAP.md` — Phase 1 goal, dependencies, success criteria, requirement coverage
- `.planning/STATE.md` — Open items flagged for per-phase planning

### No external specs

No ADRs, RFCs, or external spec documents are in play for Phase 1 — the research + REQUIREMENTS + PROJECT files constitute the full contract.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

Greenfield project. No existing application code; only `.planning/` artifacts and research files.

### Established Patterns

None yet. Phase 1 establishes the patterns (module layout, audit log, integration log, suggestions seam, event bus, shadow-mode write gate) that every subsequent phase inherits.

### Integration Points

Phase 1 is not integrating with existing code — it is building the integration points themselves. Downstream phases (2–7) plug into:
- `App\Domain\<Module>\` for each feature domain
- `App\Contracts\FeedGenerator` (empty contract stub for Phase 8)
- `App\Contracts\SuggestionApplier` per-kind resolver
- `WOO_WRITE_ENABLED` gate + `sync_diffs` table for shadow writes
- `integration_events` for every outbound HTTP call
- Domain event bus for cross-module signals

</code_context>

<specifics>
## Specific Ideas

- **AlertRecipient adds Phase 1 scope** — the DB-backed alert distribution (D-12) was not in the original REQUIREMENTS.md task list. Planner must add: migration, model, Resource, policy, notifier integration. Roughly one additional plan or a sub-task of the failed-job-monitor plan.
- **Shield seeder on every deploy** — D-03 commits to running the Shield permission sync on every deploy, not just initial setup. Document this in the deploy runbook.
- **`sync_diffs` retention is conditional on `WOO_WRITE_ENABLED`** — D-08 requires the retention prune job to read the current flag state and skip pruning when writes are disabled (pre-cutover). Plan accordingly.
- **Suggestions `correlation_id` must be backfillable** — D-16 indexes the column from day one so that Phase 5's first real producer can rely on it without a later migration.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within Phase 1 scope. Topics explicitly NOT discussed (deferred to Claude's Discretion per the decisions section above):

- HMAC secret rotation operational runbook — document in Phase 7 cutover docs, not Phase 1
- Severity-split alert routing — revisit post-cutover once alert volume is observable
- Slack alerting channel — add post-cutover if email proves too slow
- Shadow-mode sync_diffs UI — implied by Phase 7 shadow-mode dashboard, not Phase 1
- Rollback SLA (from STATE.md open items) — deferred to Phase 7 cutover discussion; Phase 1 just needs the flag + diff table to exist

</deferred>

---

*Phase: 01-foundation*
*Context gathered: 2026-04-18*
