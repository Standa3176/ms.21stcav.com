---
phase: 260626-phz-suppliers-page-show-actual-feed-file-dat
plan: 01
subsystem: ui
tags: [filament, suppliers, freshness, carbon, working-days, badge-column]

requires:
  - phase: 260626-oqr
    provides: SupplierResource (Suppliers admin page) + freshness badge column
  - phase: 260608-g8x
    provides: SupplierFreshnessResolver::latestRecordedAtFor (MAX recorded_at)
provides:
  - Feed-date badge column on /admin/suppliers showing the actual last-received feed date per supplier
  - 5-working-day colour rule (RED > 5, AMBER 4-5, GREEN <= 3, GRAY never)
  - Pure static helpers SupplierResource::workingDaysSince / feedAgeColor / feedAgeTooltip
affects: [suppliers admin, supplier freshness UX]

tech-stack:
  added: []
  patterns:
    - "Pure public static helpers on a Filament Resource so colour/age logic is unit-testable without mounting a panel"
    - "Carbon::setTestNow deterministic boundary table for weekday-diff logic"

key-files:
  created:
    - tests/Unit/Domain/Sync/SupplierFeedAgeTest.php
  modified:
    - app/Domain/Sync/Filament/Resources/SupplierResource.php
    - tests/Feature/Filament/Resources/SupplierResourceTest.php

key-decisions:
  - "Feed date = MAX(recorded_at) (last date MS received matching feed data), per operator decision 2026-06-26 — NOT the supplier's own upstream file timestamp."
  - "ADD a coloured Feed-date column on the 5-working-day rule; KEEP the existing fresh/amber/stale freshness badge alongside it."
  - "Replace (not duplicate) the old relative 'Last seen' (diffForHumans) column — same signal, less precise."
  - "Working-day age excludes weekends but NOT bank holidays (acceptable for an at-a-glance operator cue)."

patterns-established:
  - "workingDaysSince uses abs()+round() over diffInWeekdays with start-of-day on both ends for Carbon v2/v3 sign + partial-day robustness."

requirements-completed: [QUICK-260626-phz]

duration: ~15min
completed: 2026-06-26
---

# Quick Task 260626-phz: Suppliers page shows actual feed date Summary

**The /admin/suppliers Feed-date column now renders the real date of the last feed data received per supplier (e.g. "Fri 26 Jun 2026"), badge-coloured RED when older than 5 working days — quiet suppliers like Nuvias surface at a glance — replacing the old relative "Last seen" phrase.**

## Performance

- **Duration:** ~15 min
- **Tasks:** 2 of 2
- **Files modified:** 3 (1 created, 2 modified)
- **Commits:** 4 (test RED, feat GREEN helpers, feat column swap, docs)

## Accomplishments

### Task 1 — Pure working-day-age helpers (TDD RED → GREEN)
Added three pure `public static` helpers to `SupplierResource`:

- `workingDaysSince(?Carbon): ?int` — Carbon `diffInWeekdays` (weekends excluded), wrapped in `abs()` + `round()` for v2/v3 sign robustness, start-of-day on both ends so a partial-day time component never shifts the count. NULL feed date → NULL age.
- `feedAgeColor(?int): string` — the colour contract: `> 5 danger`, `4–5 warning`, `≤ 3 success`, `null gray`.
- `feedAgeTooltip(?int): ?string` — working-day age in words (`No feed data recorded yet` / `Today` / `N working day(s) ago`).

Deterministic unit test `tests/Unit/Domain/Sync/SupplierFeedAgeTest.php` pins `Carbon::setTestNow('2026-06-26')` (a Friday) and asserts the boundary table + colour mapping + tooltip wording, resetting `setTestNow()` in `afterEach`.

### Task 2 — Feed-date badge column
Replaced the relative `last_seen` (`diffForHumans`) column with a `feed_date` badge column that:
- shows `latestRecordedAtFor(...)?->format('D j M Y')` (placeholder `never`),
- colours via `feedAgeColor(workingDaysSince(latestRecordedAtFor(...)))`,
- tooltips via `feedAgeTooltip(...)`.

The existing fresh/amber/stale freshness badge is untouched. Updated the class docblock to document the feed-date column + 5-working-day rule (260626-phz). Extended the feature test with a deterministic feed-date render assertion (`Carbon::setTestNow('2026-06-26')` → `assertSee('Fri 26 Jun 2026')`).

## Boundary verification (settled weekday counts)

Verified against the installed Carbon under Herd PHP 8.4 — counts matched the plan's table exactly, **no ±1 adjustment needed**. Counted from each date to the pinned Fri 2026-06-26:

| Feed date | Working days | Colour |
|---|---|---|
| 2026-06-26 (Fri, today) | 0 | success |
| 2026-06-25 (Thu) | 1 | success |
| 2026-06-23 (Tue) | 3 | success |
| 2026-06-22 (Mon) | 4 | warning |
| 2026-06-19 (prev Fri) | 5 | warning |
| 2026-06-18 (prev Thu) | 6 | danger |
| 2026-06-12 (Fri, 2 wks back) | 10 | danger |
| null | null | gray |

Contract boundaries confirmed: **2026-06-18 → danger**, **2026-06-22 → warning**.

## Tests

- Unit: `SupplierFeedAgeTest` — 20 passed (21 assertions).
- Feature: `SupplierResourceTest` — 8 passed (16 assertions), including the new feed-date render.
- `pint --test` on `SupplierResource.php` — PASS (also `pint` applied to both test files; all `pass`).
- `grep last_seen SupplierResource.php` — no match (relative column removed).

## Caveats / operator notes

- The date is **"last date MeetingStore received matching feed data"** (`recorded_at`, stamped `today()` by `supplier:db-sync`), **not** the supplier's own upstream file timestamp. No remote-schema work.
- Working-day age excludes **weekends** but **not bank holidays** — acceptable for an at-a-glance cue.
- On `/admin/suppliers` the Feed-date column shows the actual date per supplier: RED when older than 5 working days, AMBER at 4–5, GREEN ≤ 3, GRAY ("never") with no data. Quiet suppliers (e.g. Nuvias) now surface RED at a glance.
- **Deploy (operator):** push `main`, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`. (Not done here — local commits only.)

## Deviations from Plan

None — plan executed exactly as written. Carbon's weekday counts matched the plan's expected table, so no expected-count realignment was required; the colour contract holds at every boundary.

## Self-Check: PASSED

- FOUND: app/Domain/Sync/Filament/Resources/SupplierResource.php (helpers + feed_date column)
- FOUND: tests/Unit/Domain/Sync/SupplierFeedAgeTest.php
- FOUND: tests/Feature/Filament/Resources/SupplierResourceTest.php (feed-date assertion)
- Commits f7a3c8a, b1a59ce, 0fd7664 present in git log.
