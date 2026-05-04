---
quick_id: 260504-ld8
description: Supplier DB (Remote MySQL) credential kind — Phase 1 of remote supplier sync
date: 2026-05-04
commit: b2101b7
status: completed
---

# Quick Task 260504-ld8 — Summary

## Goal

Phase 1 of "remote supplier MySQL VPS → local products mirror → Woo push." This phase ships **only** the credential infrastructure + test-connection action.

## Files (6, +96 / -3)

1. `app/Domain/Integrations/Enums/IntegrationCredentialKind.php` — added 7th case `SupplierDb` with host/port/database/username/password requiredFields, "Supplier DB (Remote MySQL)" label, success (green) color
2. `app/Domain/Integrations/Services/IntegrationCredentialResolver.php` — env-fallback branch reading `services.supplier_db.*`
3. `config/services.php` — `supplier_db` stub sourcing from `SUPPLIER_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` env vars
4. `app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php` — `testSupplierDb()` helper that opens a one-shot mysqli connection (not a registered Laravel connection), runs `SELECT 1`, returns `IntegrationTestResult::ok($latency)` or `::failed($message, $latency)`. Suppresses `mysqli_report` warning-on-failure for clean error reporting.
5. `tests/Feature/Integrations/IntegrationCredentialKindEnumTest.php` — count 6→7, added `supplier_db` containment + requiredFields assertion
6. `tests/Feature/Integrations/IntegrationCredentialResolverTest.php` — `beforeEach()` env-wipe extended to clear `services.supplier_db.*`

## Tests + verification

- 14/14 integration tests passing (1 pre-existing skip unrelated)
- Deptrac: 0 violations
- Lint clean on all 6 files
- 2 admin URLs probed via curl → 302 (login redirect, no 500)

## Operator next step

Refresh `/admin → Admin → Integration Credentials → New`. The Kind dropdown now has **7 options** including **"Supplier DB (Remote MySQL)"**. Pick it and the form fields update live to:

- **host** — your VPS hostname or IP
- **port** — typically `3306`
- **database** — schema name on the VPS MySQL
- **username** — MySQL user (read-only recommended for safety)
- **password** — MySQL password

Save → click "Test connection" on the row → should return success + latency_ms (e.g. "Connected — 47ms"). If it fails, the error message returns the actual mysqli `connect_errno` so you can diagnose (auth fail, host unreachable, port blocked by firewall).

## What's deferred to Phase 2

- The `supplier:db-sync` artisan command (needs your remote-table schema info before I can write the SELECT)
- Local products mirror update logic
- Phase 3: Woo push (dry-run by default per Phase 2 D-04 precedent)

## Commit

`b2101b7` — feat(integrations): add Supplier DB (Remote MySQL) credential kind — Phase 1 of remote supplier sync
