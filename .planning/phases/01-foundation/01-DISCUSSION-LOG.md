# Phase 1: Foundation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-18
**Phase:** 01-foundation
**Areas discussed:** Role permission scope, Retention SLAs, Admin alerting, Suggestions schema shape

---

## Role permission scope

### Q1: How deep should role-based permissions go in Phase 1?

| Option | Description | Selected |
|--------|-------------|----------|
| Full Shield auto-gen (Recommended) | Shield generates a permission per Resource + action. Seeders assign per-role. Most granular; matches Filament conventions. | ✓ |
| Coarse role-check only | Just the 4 roles; gate Resources with `canViewAny()` role-in check. Simple. | |
| Hybrid — Shield for admin resources, coarse for the rest | Shield for AuditLog/Suggestions/Users; coarse for Product/Sync/Competitor. | |

**User's choice:** Full Shield auto-gen (Recommended)

### Q2: What does each role get access to in v1?

| Option | Description | Selected |
|--------|-------------|----------|
| Tight split by responsibility (Recommended) | admin=everything; pricing_manager=Products+Rules+Competitor+Sync read; sales=CRM log read-only+own audit; read_only=view-only. | ✓ |
| Admin + everyone-else-read-only | Only admin edits; others view-only. Simpler. | |
| Admin only, others stubbed for Phase 7 | Phase 1 ships admin + Shield wiring; other roles finalised at cutover. | |

**User's choice:** Tight split by responsibility (Recommended)

---

## Retention SLAs

### Q1: audit_log retention

| Option | Description | Selected |
|--------|-------------|----------|
| 365 days (Recommended) | Full year; covers compliance audits and annual reviews. | ✓ |
| 90 days | Quarterly horizon. Smaller DB. | |
| Forever | No data loss ever; DB grows unboundedly. | |

**User's choice:** 365 days (Recommended)

### Q2: integration_events retention

| Option | Description | Selected |
|--------|-------------|----------|
| 90 days (Recommended) | 3-month forensics window; highest-volume log; Sentry captures failure signals independently. | ✓ |
| 30 days | Tight rolling window. | |
| 365 days | Match audit_log; plan for 10–50GB. | |

**User's choice:** 90 days (Recommended)

### Q3: Competitor CSV + csv_parse_errors retention

| Option | Description | Selected |
|--------|-------------|----------|
| 90 days (matches REQUIREMENTS.md) | Matches COMP-12; persisted competitor_prices rows never pruned per COMP-07. | ✓ |
| 30 days | Smaller disk usage. | |
| 180 days | Longer replay window for seasonal investigations. | |

**User's choice:** 90 days (matches REQUIREMENTS.md)

### Q4: sync_errors / sync_diffs retention

| Option | Description | Selected |
|--------|-------------|----------|
| sync_errors 90d / sync_diffs 30d post-cutover (Recommended) | Errors: 90d. Diffs: 30d after WOO_WRITE_ENABLED=true; before cutover, never prune. | ✓ |
| Both 90 days | Uniform rule. | |
| Both forever | Never lose a sync trace; large sync_diffs table before cutover. | |

**User's choice:** sync_errors 90d / sync_diffs 30d post-cutover (Recommended)

---

## Admin alerting

### Q1: Alert channels

| Option | Description | Selected |
|--------|-------------|----------|
| Email + Slack (Recommended) | Slack real-time + email fallback. | |
| Email only | Simplest; Laravel mail config. | ✓ |
| Slack only | Real-time focus; risk if webhook rotates or Slack is down. | |

**User's choice:** Email only

### Q2: Severity routing

| Option | Description | Selected |
|--------|-------------|----------|
| Single distribution (Recommended) | One channel + one email list. Dedup handles noise. | ✓ |
| Split critical vs non-critical | Different destinations per queue severity. | |
| Defer routing to v1.1 | Ship single distribution now; split after real traffic. | |

**User's choice:** Single distribution — all failures to same destination (Recommended)

### Q3: Admin email distribution list

| Option | Description | Selected |
|--------|-------------|----------|
| Single alias (ops@…) | Platform-managed list; no code deploys to change membership. | |
| Explicit addresses in config | Hardcoded in .env. | |
| Database-backed list managed via Filament | Admins add/remove via UI; needs Resource + table in Phase 1. | ✓ |

**User's choice:** Database-backed list managed via Filament

### Q4: Quiet hours / rate limiting

| Option | Description | Selected |
|--------|-------------|----------|
| No quiet hours, but rate-limit duplicates (Recommended) | Same signature within 5 min → one alert. No time-of-day suppression. | ✓ |
| Critical always; non-critical deferred 22:00–08:00 UK | Digest at 08:00 for non-critical; critical always fires. | |
| No quiet hours, no rate limiting | Every failure alerts every time. | |

**User's choice:** No quiet hours, but rate-limit duplicates (Recommended)

---

## Suggestions schema shape

### Q1: Suggestions table shape

| Option | Description | Selected |
|--------|-------------|----------|
| Generic JSON payload (Recommended) | kind enum + payload/evidence JSON; indexed correlation_id; extensible without migrations. | ✓ |
| Typed columns per kind | Separate columns per suggestion kind; type-safe but migration-heavy. | |
| Polymorphic suggestions + suggestable_type/id | Links to concrete model; flexible but complex. | |

**User's choice:** Generic JSON payload (Recommended)

### Q2: Apply path

| Option | Description | Selected |
|--------|-------------|----------|
| Enqueue a job (Recommended) | Approve → ApplySuggestionJob on default queue. Idempotent via status guard. | ✓ |
| Synchronous apply in Livewire action | Faster UX feedback; risk of long-running applies blocking UI. | |

**User's choice:** Enqueue a job (Recommended)

### Q3: correlation_id column

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — correlation_id column, indexed (Recommended) | Threads through audit_log → integration_events → suggestions → events. Single-query traceability. | ✓ |
| No — evidence JSON contains source refs | Keep source pointers in JSON; simpler schema; no single-query trace. | |

**User's choice:** Yes — correlation_id column, indexed (Recommended)

---

## Claude's Discretion

Areas left to research/planner defaults:

- HMAC secret management strategy (FOUND-07)
- Correlation ID generation source (FOUND-03)
- Deptrac layer enforcement scope (FOUND-02)
- Horizon supervisor per-queue worker counts + timeouts (FOUND-09)
- Redis persistence eviction policy (FOUND-10)

## Deferred Ideas

None — discussion stayed within Phase 1 scope.
