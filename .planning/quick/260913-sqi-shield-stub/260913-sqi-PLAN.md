# 260913-sqi — Remove the Shield IntegrationEventPolicy stub

**Branch:** `quick/260913-shield-stub`
**Trigger:** `ShieldRestorationProtocolTest` was failing. Investigated on the
operator's request; it turned out to be a genuine finding, not a stale guard.

## What it was

`app/Foundation/Integration/Policies/IntegrationEventPolicy.php` — a
Shield-generated stub, not hand-written. Every marker says so: no
`declare(strict_types=1)`, not `final`, `HandlesAuthorization`, unsorted
imports, and Shield's `crm::push::log` permission naming. All against this
codebase's conventions.

## How long, and how it arrived

    2026-04-19  guard written (dba497c), Phase 5 Plan 04a
    2026-05-09  stub arrives in 408ab94 "deploy: add deployment bundle"

It was never deleted-then-restored — it came in WITH a deployment bundle
commit, almost certainly a working tree where `shield:generate` had been run.
So the guardrail had been **red for four months**. That is the more
uncomfortable finding: a permanently-failing check trains everyone to skim past
failures, which is how the next real one gets missed.

## Was it doing harm? No — but only by one line

`AppServiceProvider:643` binds `Gate::policy(IntegrationEvent::class,
CrmPushLogPolicy::class)`, and an explicit binding beats Laravel's
auto-discovery. So `CrmPushLogPolicy` was enforcing and the stub was inert.

But the two disagree on something that counts:

| | CrmPushLogPolicy (active) | Shield stub (inert) |
|---|---|---|
| viewAny / view | admin or sales | permission-gated |
| create / update / delete | **hard `false`** | permission-gated |
| forceDelete / restore | **hard `false`** | permission-gated |
| replay | admin only | **absent** |
| deleteAny / forceDeleteAny / replicate / reorder | absent (deny) | permission-gated |

`integration_events` is an **audit trail**, deliberately immutable — nobody
creates, edits or deletes a row. The stub converts that into "whoever holds the
Shield permission". Remove or reorder one `Gate::policy` line, or let Shield
regenerate, and auto-discovery silently swaps immutable audit history for
permission-gated deletion. It also drops `replay()`, the admin-only action the
CRM push log actually uses.

Dead today; a landmine tomorrow.

## Change

- Deleted the file (the directory held nothing else). Nothing referenced it —
  verified by grep across `app`, `tests`, `config`, `database`; the only hits
  were the guard test's own assertion strings.
- Fixed a cosmetic bug of mine from 260913-qoi: `suggestions:backfill-last-seen`
  printed "would gain a date" even in `--apply` mode. Now prints "written".

## Verification

    ShieldRestorationProtocolTest                     2/2 green (was 1 red since May)
    PolicyTemplateIntegrityTest                       3/3 green
      - incl. "Gate::policy bindings resolve to Domain / root Policies
        (not Shield stubs)" — independently confirms the binding is right

Suites `tests/Feature/CRM tests/Architecture tests/Feature/Suggestions`:
**354 passed, 1,493 assertions**. Pint clean, deptrac 0 violations.

## Note for whoever deploys

Removing it on the SERVER is not enough — `deploy.sh` hard-resets
(`HEAD is now at …`), so the next deploy restores any tracked file deleted by
hand. This had to be the repo.

## Follow-up worth considering

Whatever produced 408ab94 can do it again. The durable fix is either never
running `shield:generate` against this tree, or adding the restoration protocol
to the deploy checklist. The guard now works, but a guard nobody watches is
what got us four months of red.
