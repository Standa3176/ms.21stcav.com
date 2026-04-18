---
gsd_state_version: 1.0
milestone: v1.50.1
milestone_name: milestone
status: verifying
stopped_at: Completed Phase 02 Plan 02 (external clients) — SupplierClient + WooClient.get() + live-write with 429 backoff; 16 new tests green, 126 total
last_updated: "2026-04-18T21:37:18.543Z"
last_activity: 2026-04-18
progress:
  total_phases: 7
  completed_phases: 1
  total_plans: 10
  completed_plans: 7
  percent: 70
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-18)

**Core value:** One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.
**Current focus:** Phase 1 — Foundation

## Current Position

Phase: 1 of 7 (Foundation)
Plan: 5 of 5 in current phase (01-02-rbac complete)
Status: Phase complete — ready for verification
Last activity: 2026-04-18

Progress: [████░░░░░░] 40%

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| — | — | — | — |

**Recent Trend:**

- Last 5 plans: —
- Trend: — (no data yet)

*Updated after each plan completion*
| Phase 01 P01-scaffold | 50m | 3 tasks | 25 files |
| Phase 01 P02-rbac | 35m | 2 tasks | 9 files |
| Phase 01 P03 | 40m | 2 tasks | 13 files |
| Phase 01 P04 | ~19 min | 3 tasks | 25 files |
| Phase 01 P05 | 85 min | 4 tasks | 21 files |
| Phase 02 P01 | 10m | 2 tasks | 24 files |
| Phase 02 P02 | 45m | 2 tasks | 14 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Roadmap: CRM sync moved to Phase 4 (ahead of Competitor and Auto-Create) because sanctions-compliance risk on itgalaxy v1.50.1 is the original "why now" — every week deferred accumulates legal/security exposure
- Roadmap: 7-phase structure locked as dependency-forced (cannot compress to coarse 3-5 without losing coherent delivery boundaries)
- Stack: Laravel 12 + Filament 3.3 + Horizon + phpredis + `automattic/woocommerce` + `bitrix24/b24phpsdk` (official) with `mesilov/bitrix24-php-sdk` as documented fallback
- [Phase 01]: Phase 1 scaffold: spatie/laravel-permission ^6.0 (not ^7.2 as STACK.md says; Shield 3.x requires ^6.0); rmsramos/activitylog ^2.0 (not ^1.0; first Laravel-12 compatible); Tailwind downgraded v4→v3.4.17 per Pitfall D (Filament 3 incompat with Tailwind 4); Horizon pcntl/posix bypassed on Windows dev
- [Phase 01 P02]: Shield permission format is `{action}_{resource_snake_singular}` underscore (NOT `::`) — verified via `permission:show`; RolePermissionSeeder uses LIKE patterns (`%_product`, `%_pricing_rule`, etc.) so later-phase Resources auto-attach after `shield:generate` re-run
- [Phase 01 P02]: Shield's auto-created `super_admin` + `panel_user` roles disabled in `config/filament-shield.php` — D-02 locks role set to exactly 4 (admin/pricing_manager/sales/read_only)
- [Phase 01 P02]: Shield 3.9.10's RolePolicy stub leaks 4 unrendered `{{ Placeholder }}` literal strings — MUST audit all Shield-generated Policies going forward (deferred-items pitfall)
- [Phase 01 P02]: Admin user seeded via DatabaseSeeder (admin@meetingstore.co.uk / password) — operator must rotate password before production
- [Phase 01]: Phase 01 P03: AttachCorrelationId registered GLOBALLY (not per-group) — Laravel 12 health:/up route bypasses web+api groups; global registration threads correlation_id through all HTTP entries including health checks
- [Phase 01]: Phase 01 P03: BaseCommand abstract method named perform() — execute() and run() both collide with Laravel/Symfony Command base class concrete methods
- [Phase 01]: Phase 01 P03: testing DB isolation via phpunit.xml DB_DATABASE=meetingstore_ops_testing — MySQL (not sqlite :memory) preserves JSON column + nullableUlidMorphs semantics parity with prod; resolves Plan 02 RefreshDatabase-truncation handoff
- [Phase 01]: Phase 01 P03: Context::hydrated callback calls BOTH startBatch() AND setBatch() — setBatch alone is a spatie no-op when no batch is open; discovered during Pitfall J queue-boundary test authoring
- [Phase 01]: Suggestion ULID primary key retained end-to-end — nullableUlidMorphs subject on integration_events resolves back via $event->subject (Warning 8)
- [Phase 01]: SuggestionPolicy hardcoded to hasRole('admin') — overrides shield:generate's permission-based stub (Pitfall K belt-and-braces)
- [Phase 01]: ->authorize() on Filament approve/reject Actions is mandatory defence-in-depth; ->visible() alone is insufficient (Warning 9)
- [Phase 01]: ApplySuggestionJob queue routing via $this->onQueue('default') in constructor — PHP 8.4 rejects $queue trait property redeclare
- [Phase 01]: Migration timestamp 190000 (not plan's 104000) — orchestrator permission-to-deviate for logical ordering
- [Phase 01]: Horizon Pitfall E boot assertion guarded by app()->environment('testing') — phpunit.xml forces QUEUE_CONNECTION=sync
- [Phase 01]: ThrottledFailedJobNotifier uses Cache::add atomic lock (race-safe) over has+put pattern
- [Phase 01]: shield:generate deliberately NOT re-run in Plan 05 — would regress SuggestionPolicy + RolePolicy (Plan 04 pattern); AlertRecipientPolicy uses hasRole('admin') directly
- [Phase 02]: Plan 02-01: observer uses forceFill + saveQuietly (not touch) to avoid activity_log bloat from routine variation saves
- [Phase 02]: Plan 02-01: 6 models shipped in Task 1 (not Task 2) so factory smoke tests resolve — splitting models across tasks would fail TDD RED-phase class resolution
- [Phase 02]: Plan 02-01: sync_runs.consecutive_failures shipped as unsignedInt default 0 (D-06(b) Checker blocker) — enables multi-worker AbortGuard via atomic SyncRun::increment across supervisor processes
- [Phase 02]: Plan 02-02: automattic/woocommerce resolved to 3.1.0 not 3.1.1 — Packagist only advertises 3.1.0 as of 2026-04-18 install; caret pin ^3.1 matches both; functional parity (3.1.1 adds PHP 8.5 support, irrelevant on PHP 8.4 dev)
- [Phase 02]: Plan 02-02: Automattic\WooCommerce\Client 3.1.0 does NOT expose patch() method — WooClient::patch() routes through $this->inner->http->request(endpoint, 'PATCH', payload) which is the same underlying generic method other verbs use
- [Phase 02]: Plan 02-02: WooClient is no longer final — WooRateLimitTest subclasses via anonymous class to override protected sleepMicros(int) test seam for deterministic timing assertions without real usleep delays
- [Phase 02]: Plan 02-02: SupplierClient bind() not singleton() — token state lives in Cache (external), instance has no sockets/cURL handles, tests get fresh instance per resolve; WooClient remains singleton because Automattic cURL handle is worth reusing
- [Phase 02]: Plan 02-02: correlation_id column is VARCHAR(36) across integration_events + 3 other Phase 1 tables — tests MUST use plain Str::uuid() not prefixed IDs (e.g. test-{uuid} = 41 chars, SQLSTATE[22001] truncation)

### Pending Todos

None yet.

### Blockers/Concerns

None yet. Open items flagged for per-phase planning (from research/SUMMARY.md "Gaps to Address"):

- Phase 1: retention policies, user roles, rollback SLA (ops/compliance sign-off)
- Phase 2: variable-product count, admin email distribution list (ops check)
- Phase 3: rounding convention (5-min ops conversation)
- Phase 4: UTM capture mechanism, GDPR workflow, webhook-delivery SLA
- Phase 5: MAP-policy brand coverage
- Phase 6: supplier image-DB availability, draft-vs-immediate-publish

## Session Continuity

Last session: 2026-04-18T21:37:18.531Z
Stopped at: Completed Phase 02 Plan 02 (external clients) — SupplierClient + WooClient.get() + live-write with 429 backoff; 16 new tests green, 126 total
Resume file: None
