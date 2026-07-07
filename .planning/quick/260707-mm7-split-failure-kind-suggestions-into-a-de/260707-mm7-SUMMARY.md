---
phase: 260707-mm7-split-failure-kind-suggestions-into-a-de
plan: 01
subsystem: Suggestions (Filament admin)
tags: [filament, suggestions, failures, triage, page, nav-badge, additive]
requires:
  - App\Domain\Suggestions\Models\Suggestion (STATUS_* consts, evidence/payload/rejection_reason)
  - App\Domain\Suggestions\Jobs\ApplySuggestionJob (replay dispatch)
  - AutoCreateHealthPage (structural precedent — HasTable page + nav badge)
provides:
  - app/Filament/Pages/SuggestionFailuresPage.php (dedicated failure-kind triage view)
  - SuggestionFailuresPage::FAILURE_KINDS const (crm_push_failed / auto_create_failed / agent_guardrail_blocked)
  - pending-failure nav badge under Operations
affects:
  - Filament admin sidebar (new 'Suggestion Failures' item under Operations, sort 120)
tech-stack:
  added: []
  patterns:
    - HasTable custom Filament page scoped by whereIn('kind', FAILURE_KINDS)
    - defensive try/catch nav badge (never 500 the sidebar)
    - replay/reject actions mirrored from SuggestionResource (not extracted — additive)
key-files:
  created:
    - app/Filament/Pages/SuggestionFailuresPage.php
    - resources/views/filament/pages/suggestion-failures.blade.php
    - tests/Feature/Suggestions/SuggestionFailuresPageTest.php
  modified: []
decisions:
  - PURELY ADDITIVE — SuggestionResource is untouched, so the large existing test blast radius (CrmPushFailedSuggestionTest, SuggestionResourceGuardrailBlockedFilterTest, agent/guardrail suites) stays green.
  - Replay/reject action logic was COPIED from SuggestionResource rather than extracted to a shared trait, keeping the change strictly additive (extraction would modify the resource).
  - Reject writes the same `rejection_reason` column SuggestionResource writes (confirmed against reject action ~line 736).
metrics:
  duration: ~30m
  completed: 2026-07-07
---

# Phase 260707-mm7 Plan 01: Suggestion Failures Triage Page Summary

Added a dedicated admin-only Filament page **Suggestion Failures** that lists only the three failure kinds — `crm_push_failed`, `auto_create_failed`, `agent_guardrail_blocked` — giving failures their own triage home separate from the opportunities list, carrying the Replay auto-create / Replay CRM push / Reject actions plus a pending-failure nav badge. Purely additive: `SuggestionResource` is not modified.

## What shipped

- **`app/Filament/Pages/SuggestionFailuresPage.php`** — a `Page implements HasTable` (auto-discovered, same dir as `AutoCreateHealthPage`):
  - `FAILURE_KINDS` const drives the table query (`Suggestion::query()->whereIn('kind', FAILURE_KINDS)`) so `new_product_opportunity` and every other kind are excluded.
  - Columns: kind (badge), context (`evidence.sku` or `'order #'.payload.woo_id`), error/reason (`evidence.error ?? reason ?? message ?? guardrail_reason`), status (badge), proposed_at, correlation_id (mono, copyable, hidden by default).
  - Filters: kind SelectFilter + status SelectFilter defaulting to `pending`.
  - Actions (admin-gated via `->authorize()` + `->visible()`): **Replay auto-create** (auto_create_failed + pending), **Replay CRM push** (crm_push_failed + pending) — both set status→approved + resolved fields and `ApplySuggestionJob::dispatch($record->id)`; **Reject** (pending) — writes status→rejected + `rejection_reason` + resolved fields.
  - `canAccess()` admin-only; `getNavigationBadge()` counts pending failure-kind rows in a try/catch (null on error, mirroring `AutoCreateHealthPage`); danger badge colour; Operations group, sort 120 (after Auto-create Health at 110).
- **`resources/views/filament/pages/suggestion-failures.blade.php`** — minimal `<x-filament-panels::page>{{ $this->table }}</x-filament-panels::page>`.
- **`tests/Feature/Suggestions/SuggestionFailuresPageTest.php`** — 6 cases (see Verification).

## Why additive (avoided the SuggestionResource test blast radius)

The failure kinds are already hidden from the main opportunities list by `SuggestionResource`'s Tier-1 default kind filter (`new_product_opportunity`). Rather than removing them from the resource's kind filter — which ripples into `CrmPushFailedSuggestionTest`, `SuggestionResourceGuardrailBlockedFilterTest`, and the agent/guardrail suites that all assert current `SuggestionResource` behaviour — this task only adds a new page. The replay/reject action logic was copied verbatim from `SuggestionResource` (approve/replay ~line 692, CRM replay ~line 814, reject ~line 736) rather than extracted, so no existing file changed.

## Verification (Herd php)

- `pest tests/Feature/Suggestions/SuggestionFailuresPageTest.php` → **6 passed (33 assertions)**:
  - lists only the 3 failure kinds and hides the opportunity
  - replay auto-create dispatches `ApplySuggestionJob` + status→approved
  - replay CRM push dispatches `ApplySuggestionJob` + status→approved
  - reject → status rejected + `rejection_reason` stored
  - nav badge counts pending failure-kind rows (`'3'`; 0 → null; non-pending + non-failure excluded)
  - admin-only `canAccess()` (admin true, sales false)
- Regression `pest tests/Feature/Suggestions tests/Unit/Suggestions` → **43 passed (188 assertions)**, no regressions (`SuggestionResource` untouched).
- `pint app/Filament/Pages/SuggestionFailuresPage.php tests/...` → fixed imports (`ordered_imports`, `fully_qualified_strict_types`), then clean.

TDD gates: `test(260707-mm7)` RED commit `d010aa0` (6 failed — class not found) → `feat(260707-mm7)` GREEN commit `d599725`.

## No functional deviations

Plan executed as written — the `<interfaces>` code was used near-verbatim (pint reordered imports and added the `User` import for the `canAccess` docblock type).

## Deferred follow-up

The higher-cost step — REMOVING the failure kinds from `SuggestionResource`'s kind filter entirely so they live ONLY on this page — is deferred. It touches several existing tests (`CrmPushFailedSuggestionTest`, `SuggestionResourceGuardrailBlockedFilterTest`, agent/guardrail suites) and would need each re-baselined. The 5 pre-existing Suggestions test failures (`correlation_id NOT NULL` + `BindingResolutionException`) noted in prior tasks remain owed and are unrelated to this change.

## Operator notes / post-deploy

- **NOT pushed / NOT deployed** — local commits only.
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- New sidebar item under **Operations → Suggestion Failures** (danger badge = pending failures). Triage `crm_push_failed` / `auto_create_failed` / `agent_guardrail_blocked` here with Replay + Reject; the main Suggestions list stays focused on opportunities.

## Self-Check: PASSED

- `app/Filament/Pages/SuggestionFailuresPage.php` — FOUND
- `resources/views/filament/pages/suggestion-failures.blade.php` — FOUND
- `tests/Feature/Suggestions/SuggestionFailuresPageTest.php` — FOUND
- Commit `d010aa0` (test/RED) — FOUND
- Commit `d599725` (feat/GREEN) — FOUND
