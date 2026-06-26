---
phase: 260626-oqr-add-supplier-exclusion-honor-suppliers-i
plan: 01
subsystem: sync
tags: [supplier-sync, filament, rbac, dead-flag]
requires:
  - suppliers.is_active (migration 260608-g8x — previously dead)
  - SupplierFreshnessResolver (260608-g8x singleton pattern + UI freshness API)
  - SupplierDbSyncCommand::buildBestOfferMap (260504-m5w + 260608-g8x stale filter)
provides:
  - SupplierExclusionResolver singleton (excludedSupplierIds / isExcluded / forget)
  - Unconditional operator-exclusion drop in buildBestOfferMap (ahead of stale filter)
  - Filament Suppliers admin page (/admin/suppliers) with Active/Excluded toggle
affects:
  - supplier:db-sync buy_price + stock selection (drops excluded suppliers)
tech-stack:
  added: []
  patterns:
    - per-request-cached resolver singleton (mirrors SupplierFreshnessResolver)
    - row-shape PHP filter on $rows ahead of the cheapest-in-stock reduction
    - Filament ToggleColumn inline write gated via ->disabled + EditRecord 403 guard
key-files:
  created:
    - app/Domain/Sync/Services/SupplierExclusionResolver.php
    - app/Domain/Sync/Filament/Resources/SupplierResource.php
    - app/Domain/Sync/Filament/Resources/SupplierResource/Pages/ListSuppliers.php
    - app/Domain/Sync/Filament/Resources/SupplierResource/Pages/EditSupplier.php
    - tests/Unit/Domain/Sync/Services/SupplierExclusionResolverTest.php
    - tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php
    - tests/Feature/Filament/Resources/SupplierResourceTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
decisions:
  - Excluded supplier offers are DROPPED ENTIRELY (price + stock), as if the supplier doesn't exist — no freeze/special-case.
  - Exclusion is UNCONDITIONAL — not behind excludeStaleSuppliersFromBuyPrice; an operator exclusion outranks the freshness policy.
  - Constructor change is backward-compatible — resolver is a nullable param AFTER the bool, resolved from the container when null.
metrics:
  duration: ~30m
  completed: 2026-06-26
  tasks: 3
  files: 9
  commits: 3
---

# Phase 260626-oqr Plan 01: Supplier Exclusion (honor suppliers.is_active) Summary

Wires the previously-dead `suppliers.is_active` flag into both the stock/price sync and a new
Filament admin page, so an operator can pause a supplier (e.g. Nuvias) and have its price + stock
dropped from the next `supplier:db-sync` run — unconditionally, ahead of the freshness filter.

## The dead-flag finding

`suppliers.is_active` has existed since quick task **260608-g8x** (migration
`2026_06_08_120000_create_suppliers_table.php`), and its model docblock literally cites
*"Nuvias … uploads erratically"* as the motivating case. But the flag was **dead**: nothing read it,
and there was no UI to set it. Suppliers are auto-discovered into the `suppliers` table by
`suppliers:check-stale`, so the rows existed — they just had no effect. This plan closes that gap.

## What shipped — two layers

### 1. `SupplierExclusionResolver` singleton (Task 1)

`app/Domain/Sync/Services/SupplierExclusionResolver.php` — mirrors `SupplierFreshnessResolver`:

- `excludedSupplierIds(): Collection<int,string>` — supplier_ids `WHERE is_active=false`, cast to
  string (supplier_id is VARCHAR(16)), per-request cached (lazy `??=`).
- `isExcluded(string $supplierId): bool`
- `forget(): void` — clears the cache.

Registered as a singleton in `AppServiceProvider::register()` next to `SupplierFreshnessResolver`,
so the sync command shares one cache and triggers at most one query per command run.

### 2. Unconditional drop in `buildBestOfferMap` (Task 2)

`SupplierDbSyncCommand::buildBestOfferMap()` now drops operator-excluded suppliers' offers
(**price AND stock**) as the FIRST `$rows` transformation — **ahead of** the 260608-g8x stale
filter and the cheapest-in-stock reduction. The filter is the same row-shape `array_filter` the
stale block uses, sourced from the resolver:

```php
$excludedIds = $this->exclusion->excludedSupplierIds()->all();
// drop rows whose supplierid is in the excluded set
```

**Unconditional:** unlike the stale filter, this drop is **not** gated by
`excludeStaleSuppliersFromBuyPrice`. An explicit operator exclusion outranks the freshness policy
and has no OFF switch (pinned by Test B: NUVIAS is still dropped when the stale flag is `false`).

**Sole-source SKUs:** if an excluded supplier was the only source for a SKU, the key is simply
absent from the map (no fallback invented) — it then flows through the existing no-fresh-source
handling, exactly the same as an all-stale SKU (pinned by Test C).

**Backward-compatible constructor:** the resolver is added as a NULLABLE param **after** the bool,
resolved from the container when null:

```php
public function __construct(
    private readonly IntegrationCredentialResolver $resolver,
    private readonly SupplierFreshnessResolver $freshness,
    private readonly bool $excludeStaleSuppliersFromBuyPrice = true,
    ?SupplierExclusionResolver $exclusion = null,
) { parent::__construct(); $this->exclusion = $exclusion ?? app(SupplierExclusionResolver::class); }
```

Existing `new SupplierDbSyncCommand(app(...), app(...), excludeStaleSuppliersFromBuyPrice: $bool)`
constructions stay green — confirmed by the stale suite + `tests/Feature/Sync/` (38/38).

### 3. Filament Suppliers admin page (Task 3)

`SupplierResource` (nav group **Sync & CRM**, truck icon, sort 25, after Import Issues) lists every
auto-discovered supplier with:

- inline **Active/Excluded `ToggleColumn`** (the control surface),
- freshness badge (fresh/amber/stale via `SupplierFreshnessResolver::classify`),
- last-seen (`latestRecordedAtFor(...)?->diffForHumans()`),
- `stale_after_days` override, notes, updated_at,
- an **Excluded-only** TernaryFilter,
- a **danger** nav badge counting excluded suppliers (visible at a glance),
- an edit form (supplier_id + name are read-only auto-discovered natural keys; is_active toggle,
  stale_after_days, notes are editable).

**RBAC:** writes gated to **admin + pricing_manager** on the `ToggleColumn` `->disabled`, every edit
form field `->disabled`, AND `EditSupplier::mutateFormDataBeforeSave` (403 at POST, defence-in-depth).
sales/read_only are view-only. `canCreate() => false` — suppliers are auto-discovered by
`suppliers:check-stale`, not created in the UI. Auto-discovered by AdminPanelProvider's
`Domain/Sync/Filament/Resources` glob; routes `admin/suppliers` (index + edit) confirmed.

## Verification

| Suite | Result |
| --- | --- |
| `SupplierExclusionResolverTest` (unit) | 3 passed |
| `SupplierDbSyncExclusionTest` (A/B/C) | 3 passed |
| `SupplierDbSyncStaleSupplierTest` + `tests/Feature/Sync/` (regression) | 38 passed |
| `SupplierResourceTest` (Filament, RBAC + toggle + filter) | 7 passed |
| **Full relevant run** | **51 passed (191 assertions)** |
| pint on all new/changed files | PASS |
| `route:list | grep suppliers` | `admin/suppliers` index + edit present |

## Operator runbook — exclude / re-include Nuvias

1. Open **/admin/suppliers**, find Nuvias, flip the **Active** toggle **OFF**.
2. The next `supplier:db-sync` run (Mon–Fri 07:00 London) ignores its price + stock.
3. To apply immediately, preview then apply:
   ```
   sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan supplier:db-sync --dry-run'
   # then without --dry-run
   ```
4. If Nuvias is not listed, it has not been discovered yet — run `suppliers:check-stale` once.
5. **Re-activating later:** flip the toggle back ON; the next sync re-includes it.

**Deploy:** push main, then on the VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`
(no migration — `is_active` already exists from 260608-g8x).

## Notes on correctness (per plan)

- Exclusion is **unconditional** — not behind `excludeStaleSuppliersFromBuyPrice`.
- Excluded suppliers' offers are dropped **entirely** (price + stock), ahead of the stale filter.
- Sole-source excluded SKUs get **no offer** (key absent) and fall through to the existing
  no-source handling — no special-casing.

## Deviations from Plan

None — plan executed exactly as written. (The Filament feature test used the component method
`updateTableColumnState` via Livewire's `->call(...)` to exercise the `ToggleColumn` write path,
which respects the column's `->disabled` gate internally — this is the standard Filament 3 path for
asserting an inline-editable column's RBAC, equivalent to the plan's intent.)

## Commits

- `45c69bd` feat(260626-oqr): add SupplierExclusionResolver singleton
- `358127d` feat(260626-oqr): drop operator-excluded suppliers in buildBestOfferMap
- `abdc7bb` feat(260626-oqr): add Suppliers Filament admin page with Active toggle

## Self-Check: PASSED

- All 7 created files present on disk.
- All 3 task commits present in `git log`.
