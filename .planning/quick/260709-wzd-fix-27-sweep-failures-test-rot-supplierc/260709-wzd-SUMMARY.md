---
phase: 260709-wzd-fix-27-sweep-failures-test-rot-supplierc
plan: 01
subsystem: [Sync, TradePricing, RBAC, Integrations]
tags: [test-rot, rbac, seeder, woo-client, supplier-client, mockery]
requires: []
provides:
  - "customer_group + quote perms survive syncPermissions() (D-10/D-04 RBAC matrix enforced after seeding)"
  - "23 test-rot fixes restore the full-suite sweep to green (SupplierClient Mockery stubs, Facades\\Str typo, WooClient 3-arg ctor + reflection, list→help assertion, put→post routing)"
affects:
  - database/seeders/RolePermissionSeeder.php
tech-stack:
  added: []
  patterns:
    - "Container-bound test doubles for typed ctor deps MUST be real subtypes — use Mockery::mock(Class::class), not bare `new class {}`"
    - "syncPermissions() REPLACES a role's whole set — any givePermissionTo() grant must ALSO appear in the sync source list to survive"
key-files:
  created:
    - .planning/quick/260709-wzd-fix-27-sweep-failures-test-rot-supplierc/260709-wzd-SUMMARY.md
  modified:
    - tests/Feature/SyncResumeTest.php
    - tests/Feature/SyncReportMailTest.php
    - tests/Feature/SyncSupplierCommandFlagsTest.php
    - tests/Feature/SupplierEventDispatchTest.php
    - tests/Feature/WooClientGetTest.php
    - tests/Feature/WooRateLimitTest.php
    - tests/Feature/TradePricing/BackfillCustomerGroupsCommandTest.php
    - database/seeders/RolePermissionSeeder.php
decisions:
  - "WooRateLimitTest required a SECOND test-rot fix beyond the 3-arg ctor: production put() routes through POST when services.woo.use_post_for_updates=true (default), so the `shouldReceive('put')` mocks no longer matched. Pinned the flag off in beforeEach (Rule 1 deviation) — minimal, preserves the 429-backoff test intent."
  - "Seeder fix ADDS the clobbered perms to the syncPermissions source lists rather than removing the (now-redundant but harmless, idempotent) givePermissionTo blocks."
metrics:
  duration: ~15m
  completed: 2026-07-09
---

# Phase 260709-wzd Plan 01: Fix 27 Sweep Failures (test-rot + RBAC seeder clobber) Summary

Took 27 of the 30 full-suite sweep failures to green: 23 test-rot fixes across 7 test files
plus 1 REAL RBAC bug (the RolePermissionSeeder `syncPermissions()` clobber that silently wiped
`pricing_manager`/`sales` customer_group + quote grants). The remaining 3 (AnonymousDisplayPostureTest —
a real trade-pricing-leak bug entangled with the v1 byte-identity lock) were left entirely untouched
as a separate task.

## What Changed (per bucket)

### Bucket 1 — SupplierClient stubs (8 cases, test-rot)
`SyncResumeTest`, `SyncReportMailTest`, `SyncSupplierCommandFlagsTest` bound a bare `new class {}`
anonymous stub into the container as `SupplierClient`. `WooImportProductsCommand`'s ctor typehints
`SupplierClient $supplier`, and Artisan command discovery eagerly resolves it — the anon class is not
a `SupplierClient` subtype, so injection threw a `TypeError`. Replaced each with
`Mockery::mock(SupplierClient::class)->shouldReceive('fetchAllProducts')->andReturn(...)` (mirroring the
passing `WooImportProductsCommandTest` `fakeSupplier`), preserving each stub's original return shape:
- `SyncResumeTest::resumeStub()` → `andReturn($feed)`
- `SyncReportMailTest::stubSupplierClientEmpty()` → `andReturn([])`
- `SyncReportMailTest::stubSupplierClientThrowingJwt()` → `andThrow(new JwtRefreshFailedException('bad creds'))`
- `SyncSupplierCommandFlagsTest` beforeEach → `andReturn([])`

The `WooProductIterator` anon stubs were left as-is (resolved lazily via `app()`, duck-typed — not a
typed ctor dependency, so no `TypeError`).

### Bucket 2 — Str import typo (6 cases, test-rot)
`SupplierEventDispatchTest.php`: `use Illuminate\Support\Facades\Str;` → `use Illuminate\Support\Str;`
(the `Facades\Str` class does not exist). Confirmed TEST-ONLY — no app/ occurrence.

### Bucket 3 — WooClient 3-arg ctor (8 cases, test-rot)
`WooClient::__construct` is now `(IntegrationLogger $logger, IntegrationCredentialResolver $resolver, ?AutomatticClient $inner = null)`.
- `WooClientGetTest.php`: added `use App\Domain\Integrations\Services\IntegrationCredentialResolver;`
  and inserted `app(IntegrationCredentialResolver::class)` as arg 2 in all 3 constructions (G1/G2/G3),
  keeping the inner mock as arg 3.
- `WooClientGetTest.php` G4 reflection: `toHaveCount(2)` → `toHaveCount(3)`; asserted index 1 is
  `IntegrationCredentialResolver`; the `?AutomatticClient` param is now index 2. G3's TypeError → the
  expected `HttpClientException` once construction succeeds.
- `WooRateLimitTest.php`: added the import; inserted `app(IntegrationCredentialResolver::class)` as the
  middle ctor arg in the `rateLimitTestClient` anon subclass (3 args total).

**Second-layer test-rot in WooRateLimitTest (Rule 1 deviation — see below):** the ctor fix alone left
all 4 R-tests failing. Production `put()` routes through **POST** when
`services.woo.use_post_for_updates` is true (the default — WP-REST treats POST/PUT identically for
updates), so the tests' `shouldReceive('put')` mocks no longer matched (`$sdk->post()` was called →
Mockery `BadMethodCallException`). Fixed by pinning `config(['services.woo.use_post_for_updates' => false])`
in the test's `beforeEach` so `put()` dispatches to `$sdk->put()` and the existing put() mocks match.
Minimal fix; preserves the 429-backoff/jitter/Retry-After test intent.

### Bucket 4c — backfill help assertion (1 case, test-rot)
`BackfillCustomerGroupsCommandTest.php` asserted `list --namespace=b2b` output contains the option
description `'Commit writes'` — but `list` never prints per-option help. Switched to
`Artisan::call('help', ['command_name' => 'b2b:backfill-customer-groups'])` then asserted the output
contains `--live` and `Commit writes`. The command's signature
(`{--live : Commit writes (default is dry-run)}`) is correct and unchanged.

### Bucket 4b — RolePermissionSeeder RBAC clobber (4 cases, REAL BUG)
**Root cause:** the seeder grants `pricing_manager` + `sales` their customer_group perms (givePermissionTo,
~L231-235) and quote perms (~L247-263) additively, then LATER calls
`$pricingManager->syncPermissions($pricingManagerPermissions)` (~L491) and
`$sales->syncPermissions($salesPermissions)` (~L551). `syncPermissions()` REPLACES each role's ENTIRE
permission set with only what's in the source list — and neither source list contained the customer_group
or quote perms. Result: those grants were silently wiped in prod, so `CustomerGroupPolicy` (which gates
on `$user->can('*_customer_group')`) never actually enforced the D-10 matrix. Identical clobber hit the
quote grants (D-04). `admin` was unaffected (syncs `Permission::all()`); `read_only` unaffected (uses the
`view_%` LIKE pattern + explicit revokes).

**Fix:** added the clobbered perms to the `syncPermissions()` SOURCE lists so they SURVIVE the sync.
The redundant-but-harmless (idempotent) givePermissionTo blocks were left in place as intent documentation.

**Exact perm slugs added** (confirmed against the givePermissionTo blocks + `CustomerGroupPolicy`):

- `pricing_manager` `whereIn([...])` source list (customer_group — all 5; quote — all except delete/revert per D-04):
  - `view_any_customer_group`, `view_customer_group`, `create_customer_group`, `update_customer_group`, `delete_customer_group`
  - `view_any_quote`, `view_quote`, `create_quote`, `update_quote`, `approve_quote`, `mark_accepted_quote`, `mark_rejected_quote`
- `sales` — VIEW perms into the `view_%`-gated `orWhereIn` branch:
  - `view_any_customer_group`, `view_customer_group`, `view_any_quote`, `view_quote`
- `sales` — WRITE quote perms into the OUTER `orWhereIn` branch (bypasses the `view_%` gate so they land):
  - `create_quote`, `update_quote`, `mark_accepted_quote`, `mark_rejected_quote`

Confirmed green: `CustomerGroupResourceTest` + `PricingRuleResourceCustomerGroupFieldTest` (both re-seed
via `RolePermissionSeeder` in setup), including the `RolePermissionSeeder grants customer_group perms per
D-10 matrix (Test 5)` case.

## Verification

- **9 files** (all 10 sweep files EXCEPT `AnonymousDisplayPostureTest`):
  `Tests: 50 passed (236 assertions)` — ALL GREEN.
  (`SyncResumeTest`, `SyncReportMailTest`, `SyncSupplierCommandFlagsTest`, `SupplierEventDispatchTest`,
  `WooClientGetTest`, `WooRateLimitTest`, `BackfillCustomerGroupsCommandTest`, `CustomerGroupResourceTest`,
  `PricingRuleResourceCustomerGroupFieldTest`.)
- **Pint** `database/seeders/RolePermissionSeeder.php`: `{"result":"pass"}` (after a `method_chaining_indentation`
  auto-fix on the sales `orWhereIn` block I edited — cosmetic, no logic change).
- **Driver-portable:** all changes are test-double wiring, an import fix, a config pin, a help-command
  assertion, and additive perm slugs in query-builder `whereIn`/`orWhereIn` lists — no raw SQL, no
  driver-specific typing. Safe on SQLite (tests) and MariaDB (prod).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] WooRateLimitTest put→post routing (second-layer test-rot)**
- **Found during:** Task 1 verification (all 4 R-tests still red after the plan's 3-arg ctor fix).
- **Issue:** production `WooClient::put()` routes writes through POST when
  `services.woo.use_post_for_updates` is true (the default), so the tests' `shouldReceive('put')` mocks
  never matched — `$sdk->post()` was invoked, raising Mockery `BadMethodCallException`. The plan's
  bucket-3 triage covered only the ctor signature; this routing default is a separate rot layer.
- **Fix:** added `config(['services.woo.use_post_for_updates' => false])` to the test's `beforeEach` so
  `put()` dispatches to `$sdk->put()`, matching the existing mocks. Preserves the backoff/jitter/Retry-After
  intent; no production change.
- **Files modified:** `tests/Feature/WooRateLimitTest.php`
- **Commit:** (this plan's atomic commit)

## Operator / Deploy Notes

- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- **RE-RUN the RolePermissionSeeder on deploy** — `php artisan db:seed --class=RolePermissionSeeder` — so
  `pricing_manager`/`sales` regain their customer_group + quote permissions (the D-10/D-04 RBAC matrix that
  was silently wiped by the `syncPermissions()` clobber). This is the load-bearing prod correction; the
  other 23 fixes are pure test-rot with no prod impact.

## Still Owed (SEPARATE task — NOT in scope here)

- **AnonymousDisplayPostureTest (3 sweep failures)** — a real trade-pricing-LEAK bug where the resolver
  returns trade margin to anonymous/retail. The fix is entangled with the v1 byte-identity lock on
  `RuleResolver.php` / `TradeRuleResolver.php` / `PriceCalculator.php`, so it needs an approach decision
  and is handled as its own task. **These files + that test were left entirely untouched by this plan.**

## Self-Check: PASSED
- `database/seeders/RolePermissionSeeder.php` — FOUND (modified, pint pass)
- 7 test files — FOUND (modified)
- 9-file pest run — 50 passed
- RuleResolver / TradeRuleResolver / PriceCalculator / AnonymousDisplayPostureTest — NOT modified (git status confirmed)
