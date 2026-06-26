---
phase: 260626-oqr-add-supplier-exclusion-honor-suppliers-i
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Sync/Services/SupplierExclusionResolver.php
  - app/Providers/AppServiceProvider.php
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
  - app/Domain/Sync/Filament/Resources/SupplierResource.php
  - app/Domain/Sync/Filament/Resources/SupplierResource/Pages/ListSuppliers.php
  - app/Domain/Sync/Filament/Resources/SupplierResource/Pages/EditSupplier.php
  - tests/Unit/Domain/Sync/Services/SupplierExclusionResolverTest.php
  - tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php
  - tests/Feature/Filament/Resources/SupplierResourceTest.php
autonomous: true
requirements:
  - QUICK-260626-oqr
must_haves:
  truths:
    - "An operator can mark a supplier inactive (suppliers.is_active=false) and the stock/price sync then DROPS that supplier's offers entirely — its price AND stock are excluded from buildBestOfferMap, exactly as a stale supplier is."
    - "The exclusion is UNCONDITIONAL: it applies even when excludeStaleSuppliersFromBuyPrice=false. An explicit operator exclusion outranks the freshness policy and is not behind any flag."
    - "Exclusion is resolved via a new singleton SupplierExclusionResolver (per-request cached, mirrors SupplierFreshnessResolver) querying suppliers WHERE is_active=false; the filter runs in buildBestOfferMap BEFORE the stale filter and BEFORE the cheapest-in-stock reduction."
    - "If an excluded supplier was the ONLY source for a SKU, that SKU yields no offer (key absent from the map) — it then flows through the existing no-fresh-source handling (same as all-stale). No special-casing."
    - "A Filament 'Suppliers' admin page (SupplierResource, nav group 'Sync & CRM') lists every discovered supplier with an inline Active/Excluded toggle, freshness status (fresh/amber/stale), last-seen, stale_after_days override, and notes. Toggling is gated to admin + pricing_manager; sales/read_only cannot write."
    - "Suppliers are auto-discovered (suppliers:check-stale) — the resource has NO create action. The existing SupplierDbSyncCommand constructor stays backward-compatible (existing tests that construct it with 3 positional/named args keep working)."
  artifacts:
    - path: "app/Domain/Sync/Services/SupplierExclusionResolver.php"
      provides: "Per-request-cached resolver of excluded (is_active=false) supplier_ids"
      contains: "excludedSupplierIds"
    - path: "app/Domain/Sync/Commands/SupplierDbSyncCommand.php"
      provides: "Unconditional inactive-supplier offer drop in buildBestOfferMap, ahead of the stale filter"
      contains: "excludedSupplierIds"
    - path: "app/Domain/Sync/Filament/Resources/SupplierResource.php"
      provides: "Suppliers admin page with Active/Excluded toggle + freshness columns"
      contains: "ToggleColumn"
    - path: "tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php"
      provides: "buildBestOfferMap drops excluded supplier offers (price+stock), unconditional vs stale flag"
      contains: "is_active"
  key_links:
    - from: "SupplierDbSyncCommand::buildBestOfferMap()"
      to: "SupplierExclusionResolver::excludedSupplierIds()"
      via: "injected resolver, filter rows by supplierid before stale filter"
      pattern: "exclusion->excludedSupplierIds"
    - from: "SupplierResource ToggleColumn(is_active)"
      to: "suppliers.is_active"
      via: "inline Filament toggle gated to admin+pricing_manager"
      pattern: "ToggleColumn::make('is_active')"
---

<objective>
Let the operator EXCLUDE a supplier (e.g. Nuvias, currently sending stale data) from the
MeetingStore stock & price update pipeline — both via an admin UI toggle and enforced in the sync.

BACKGROUND: the `suppliers` table already has an `is_active` boolean (migration 260608-g8x,
docblock literally cites "Nuvias … uploads erratically" as the motivating case), but it is DEAD:
nothing reads it, and there is no UI to set it. Suppliers are auto-discovered into this table by
`suppliers:check-stale`.

DECISIONS (confirmed with operator 2026-06-26):
  1. Excluded supplier offers are DROPPED ENTIRELY (price + stock ignored, as if the supplier
     doesn't exist). SKUs only that supplier carried fall through to the existing no-fresh-source
     handling — same behaviour as a stale supplier. NO freeze/special-case.
  2. Control surface = a new Filament "Suppliers" admin page with an Active/Excluded toggle.

This mirrors the EXISTING stale-supplier mechanism in SupplierDbSyncCommand::buildBestOfferMap()
(lines 519-536), which already drops stale suppliers' offers before the cheapest-in-stock reduction.
We add an analogous, UNCONDITIONAL drop for operator-excluded suppliers, sourced from a new
SupplierExclusionResolver singleton (modelled on SupplierFreshnessResolver).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260626-oqr-add-supplier-exclusion-honor-suppliers-i/
@CLAUDE.md
@app/Domain/Sync/Commands/SupplierDbSyncCommand.php
@app/Domain/Sync/Models/Supplier.php
@app/Domain/Sync/Services/SupplierFreshnessResolver.php
@app/Domain/Sync/Filament/Resources/ImportIssueResource.php
@tests/Feature/Domain/Sync/SupplierDbSyncStaleSupplierTest.php

<interfaces>
<!-- Extracted from the codebase — use directly, no exploration needed. -->

Supplier model (app/Domain/Sync/Models/Supplier.php): fillable [supplier_id, name, stale_after_days,
is_active, notes]; casts is_active=>bool, stale_after_days=>int. Table `suppliers`: supplier_id
VARCHAR(16) unique, name VARCHAR(100) nullable, stale_after_days unsigned smallint nullable,
is_active bool default true, notes text nullable, indexed on is_active.

SupplierDbSyncCommand constructor (CURRENT — keep backward-compatible):
```php
public function __construct(
    private readonly IntegrationCredentialResolver $resolver,
    private readonly SupplierFreshnessResolver $freshness,
    private readonly bool $excludeStaleSuppliersFromBuyPrice = true,
) { parent::__construct(); }
```
Tests construct it as `new SupplierDbSyncCommand(app(...), app(...), excludeStaleSuppliersFromBuyPrice: $bool)`
(named arg for the bool). To stay non-breaking, ADD the exclusion resolver as a NULLABLE param AFTER
the bool, resolved from the container when null:
```php
private readonly SupplierExclusionResolver $exclusion;
public function __construct(
    private readonly IntegrationCredentialResolver $resolver,
    private readonly SupplierFreshnessResolver $freshness,
    private readonly bool $excludeStaleSuppliersFromBuyPrice = true,
    ?SupplierExclusionResolver $exclusion = null,
) {
    parent::__construct();
    $this->exclusion = $exclusion ?? app(SupplierExclusionResolver::class);
}
```

The stale filter to MIRROR (SupplierDbSyncCommand::buildBestOfferMap, lines 519-536):
```php
if ($this->excludeStaleSuppliersFromBuyPrice) {
    $staleIds = $this->freshness->staleSupplierIds()->all();
    if ($staleIds !== []) {
        $staleSet = array_flip(array_map('strval', $staleIds));
        $rows = array_values(array_filter($rows, static function (array $row) use ($staleSet): bool {
            $sid = isset($row['supplierid']) ? (string) $row['supplierid'] : '';
            return $sid === '' || ! isset($staleSet[$sid]);
        }));
    }
}
```

SupplierFreshnessResolver public API (for the UI freshness columns):
classify(string $supplierId): string  // 'fresh' | 'amber' | 'stale'
latestRecordedAtFor(string $supplierId): ?Carbon
daysSinceFor(string $supplierId): ?int
forget(): void   // resets per-request cache; SINGLETON-bound

SupplierFreshnessResolver is registered as a singleton (see AppServiceProvider; grep `singleton`)
— register SupplierExclusionResolver the same way so its per-request cache is shared.

ImportIssueResource (app/Domain/Sync/Filament/Resources/ImportIssueResource.php) is the resource
PATTERN to mirror: nav group 'Sync & CRM', getNavigationBadge() with defensive try/catch, write
actions gated via `auth()->user()?->hasAnyRole(['admin','pricing_manager'])` on BOTH ->visible and
->authorize, canCreate() => false, getPages() index+edit. Filament 3 + Tailwind 3.

Filament ToggleColumn (inline boolean toggle) — gate writes with
`->disabled(fn (): bool => ! (auth()->user()?->hasAnyRole(['admin','pricing_manager']) ?? false))`.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: SupplierExclusionResolver service + singleton + unit test</name>
  <files>
    app/Domain/Sync/Services/SupplierExclusionResolver.php,
    app/Providers/AppServiceProvider.php,
    tests/Unit/Domain/Sync/Services/SupplierExclusionResolverTest.php
  </files>
  <behavior>
    SupplierExclusionResolver (final) exposes:
      - excludedSupplierIds(): Illuminate\Support\Collection<int,string> — supplier_ids WHERE
        is_active=false, cast to string, per-request cached (lazy ??=).
      - isExcluded(string $supplierId): bool
      - forget(): void — clears the cache.
    Registered as a singleton in AppServiceProvider (next to / mirroring SupplierFreshnessResolver).

    Unit test (RefreshDatabase): seed 3 Supplier rows — two is_active=true, one is_active=false
    ('NUVIAS'). Assert excludedSupplierIds() === ['NUVIAS'] (values only); isExcluded('NUVIAS') true,
    isExcluded(active) false; after creating another inactive supplier WITHOUT forget(), the cached
    result is unchanged (proves caching); after forget(), it reflects both. Assert returned ids are
    strings.
  </behavior>
  <action>
    Create the resolver:
    ```php
    namespace App\Domain\Sync\Services;
    use App\Domain\Sync\Models\Supplier;
    use Illuminate\Support\Collection;

    final class SupplierExclusionResolver
    {
        /** @var Collection<int,string>|null */
        private ?Collection $cache = null;

        /** @return Collection<int,string> supplier_ids with is_active=false */
        public function excludedSupplierIds(): Collection
        {
            return $this->cache ??= Supplier::query()
                ->where('is_active', false)
                ->pluck('supplier_id')
                ->map(static fn ($id): string => (string) $id)
                ->values();
        }

        public function isExcluded(string $supplierId): bool
        {
            return $this->excludedSupplierIds()->contains($supplierId);
        }

        public function forget(): void
        {
            $this->cache = null;
        }
    }
    ```
    Register the singleton in AppServiceProvider::register() alongside the other domain singletons
    (search for `SupplierFreshnessResolver` registration; if it is auto-resolved rather than
    explicitly bound, add `$this->app->singleton(SupplierExclusionResolver::class);` near the other
    `$this->app->singleton(...)` calls). Write the unit test described above.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Domain/Sync/Services/SupplierExclusionResolverTest.php 2>&1 | tail -15</automated>
    Expected: GREEN.
  </verify>
  <done>
    - Resolver exists with the 3 methods + per-request cache.
    - Singleton-registered.
    - Unit test GREEN (filter, caching, forget, string-typing).
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Enforce exclusion in SupplierDbSyncCommand::buildBestOfferMap</name>
  <files>
    app/Domain/Sync/Commands/SupplierDbSyncCommand.php,
    tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php
  </files>
  <behavior>
    buildBestOfferMap drops offers from excluded suppliers UNCONDITIONALLY, BEFORE the existing stale
    filter. Constructor gains the nullable SupplierExclusionResolver param (resolved from container
    when null) per <interfaces> — existing constructions stay green.

    Feature test (mirror SupplierDbSyncStaleSupplierTest; call buildBestOfferMap directly, no mysqli):
      - Test A: seed Supplier('NUVIAS', is_active=false) + Supplier('WCOAST', is_active=true). Rows:
        NUVIAS price 1.00 stock 5, WCOAST price 9.00 stock 5, same mpn 'PART-X'. With exclude-stale
        ON: map['part-x']['buy'] === '9.00', supplier WCOAST, in_stock true — NUVIAS dropped despite
        being cheapest. Assert total stock reflects only WCOAST (5), not 10.
      - Test B: UNCONDITIONAL — same data but construct the command with
        excludeStaleSuppliersFromBuyPrice:false. NUVIAS is STILL dropped (exclusion is not gated by
        the stale flag) → buy 9.00 / WCOAST. (Contrast: the stale flag OFF would have kept a stale
        supplier; the exclusion flag has no OFF.)
      - Test C: excluded supplier is the ONLY source — seed Supplier('NUVIAS', is_active=false), rows
        only from NUVIAS for 'PART-Z'. Map has NO 'part-z' key (offer dropped, no fallback invented).
      - Between buildBestOfferMap calls in the same test, call
        `app(SupplierExclusionResolver::class)->forget()` and
        `app(SupplierFreshnessResolver::class)->forget()` to clear singleton caches (mirror the stale
        test's forget() discipline).
      Provide a local `makeExclusionSyncCommand(bool $excludeStale = true)` helper mirroring
      makeStaleSyncCommand but letting the container inject the exclusion resolver.
  </behavior>
  <action>
    Step 1 — Update the constructor to the backward-compatible shape in <interfaces> (add nullable
    `?SupplierExclusionResolver $exclusion = null`, assign `$this->exclusion = $exclusion ?? app(...)`).
    Add the import for SupplierExclusionResolver.

    Step 2 — In buildBestOfferMap, insert the exclusion filter as the FIRST transformation of $rows,
    BEFORE the `if ($this->excludeStaleSuppliersFromBuyPrice)` stale block:
    ```php
    // 260626-oqr — operator-excluded suppliers (suppliers.is_active=false) are
    // dropped UNCONDITIONALLY, ahead of the freshness filter. An explicit
    // operator exclusion outranks freshness policy and is not behind any flag.
    // (e.g. Nuvias paused while it ships stale data.) Same row-shape filter as
    // the stale block below; sourced from the SupplierExclusionResolver singleton.
    $excludedIds = $this->exclusion->excludedSupplierIds()->all();
    if ($excludedIds !== []) {
        $excludedSet = array_flip(array_map('strval', $excludedIds));
        $rows = array_values(array_filter($rows, static function (array $row) use ($excludedSet): bool {
            $sid = isset($row['supplierid']) ? (string) $row['supplierid'] : '';
            return $sid === '' || ! isset($excludedSet[$sid]);
        }));
    }
    ```
    Update the class docblock's 260608-g8x note region to mention the new unconditional exclusion
    layer (brief; reference 260626-oqr).

    Step 3 — Write tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php per <behavior>.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (A, B, C).

    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Domain/Sync/SupplierDbSyncStaleSupplierTest.php tests/Feature/Sync/ 2>&1 | tail -15</automated>
    Expected: existing sync suites STILL GREEN (constructor change is backward-compatible).

    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Sync/Commands/SupplierDbSyncCommand.php app/Domain/Sync/Services/SupplierExclusionResolver.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - buildBestOfferMap drops excluded-supplier offers (price + stock) unconditionally, ahead of the stale filter.
    - Constructor backward-compatible; existing sync tests green.
    - New exclusion feature test GREEN (A/B/C). pint clean.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Filament SupplierResource (Suppliers admin page with Active toggle)</name>
  <files>
    app/Domain/Sync/Filament/Resources/SupplierResource.php,
    app/Domain/Sync/Filament/Resources/SupplierResource/Pages/ListSuppliers.php,
    app/Domain/Sync/Filament/Resources/SupplierResource/Pages/EditSupplier.php,
    tests/Feature/Filament/Resources/SupplierResourceTest.php
  </files>
  <behavior>
    A Filament resource for App\Domain\Sync\Models\Supplier, mirroring ImportIssueResource:
      - nav group 'Sync & CRM', icon 'heroicon-o-truck', pluralModelLabel 'Suppliers',
        navigationSort 25 (after Import Issues=20), recordTitleAttribute 'supplier_id'.
      - getNavigationBadge(): count of is_active=false suppliers (defensive try/catch), badge color
        'danger' (so excluded suppliers are visible at a glance); null when zero.
      - Table columns:
          * supplier_id (mono, searchable, sortable)
          * name (searchable, placeholder '—')
          * is_active → ToggleColumn::make('is_active')->label('Active')
              ->disabled(fn (): bool => ! (auth()->user()?->hasAnyRole(['admin','pricing_manager']) ?? false))
          * freshness → TextColumn::make('freshness')->badge()
              ->getStateUsing(fn (Supplier $r): string => app(SupplierFreshnessResolver::class)->classify((string) $r->supplier_id))
              ->color(fn (string $s): string => match ($s) { 'fresh' => 'success', 'amber' => 'warning', 'stale' => 'danger', default => 'gray' })
          * last_seen → TextColumn::make('last_seen')
              ->getStateUsing(fn (Supplier $r): ?string => app(SupplierFreshnessResolver::class)->latestRecordedAtFor((string) $r->supplier_id)?->diffForHumans())
              ->placeholder('never')
          * stale_after_days (sortable, placeholder 'default')
          * notes (limit 40, placeholder '—')
          * updated_at (dateTime, sortable, toggleable)
      - Filter: TernaryFilter::make('is_active')->label('Status')->trueLabel('Active only')
          ->falseLabel('Excluded only')->placeholder('All').
      - Edit form (writes gated to admin+pricing_manager via ->disabled on each field for others):
          supplier_id (disabled — natural key, auto-discovered), name (disabled — denormalised),
          is_active (Toggle), stale_after_days (TextInput numeric nullable, helperText
          'Blank = default 7 days'), notes (Textarea rows 3, maxLength 2000, columnSpanFull).
      - canCreate(): false (auto-discovered by suppliers:check-stale).
      - getPages(): index => ListSuppliers, edit => EditSupplier.

    Test (tests/Feature/Filament/Resources/SupplierResourceTest.php, Livewire):
      - admin can mount the List page (assertSuccessful) and SEE a seeded supplier.
      - admin can toggle is_active via the ToggleColumn (assert DB flips) — use Livewire table action
        or directly assert ToggleColumn is enabled for admin and disabled for read_only.
      - pricing_manager can mount + toggle.
      - read_only (or sales) mounts read-only: assert the ToggleColumn is disabled for them
        (or that an attempted write is rejected). Use the role-helper pattern from
        CategoryAuditPageTest/ImportIssueResource tests.
      - TernaryFilter 'Excluded only' shows an is_active=false supplier and hides an active one.
      Reuse a role+user helper (mirror categoryAuditUser) and seed Supplier + a SupplierOfferSnapshot
      so the freshness column resolves without error.
  </behavior>
  <action>
    Create SupplierResource.php + the two Pages (ListSuppliers extends ListRecords, EditSupplier
    extends EditRecord) under app/Domain/Sync/Filament/Resources/SupplierResource/Pages/, mirroring
    the ImportIssueResource pages layout. Resources are auto-discovered by AdminPanelProvider
    (app/Providers/Filament/AdminPanelProvider.php) from app/Domain/Sync/Filament/Resources — no
    manual registration needed; verify the discovery glob covers this path.

    Gate all write surfaces (ToggleColumn, edit form fields, any save) to admin+pricing_manager with
    the hasAnyRole pattern; leave read access open (read-only for sales/read_only), matching
    ImportIssueResource's convention.

    Write the feature test. Run pint on all new files.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Filament/Resources/SupplierResourceTest.php 2>&1 | tail -25</automated>
    Expected: GREEN.

    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Sync/Filament/Resources/SupplierResource.php app/Domain/Sync/Filament/Resources/SupplierResource/Pages/ListSuppliers.php app/Domain/Sync/Filament/Resources/SupplierResource/Pages/EditSupplier.php 2>&1 | tail -5</automated>
    Expected: PASS.

    <automated>~/.config/herd/bin/php84/php.exe artisan route:list 2>&1 | grep -i suppliers | head</automated>
    Expected: the Filament suppliers resource routes are registered (admin/suppliers...).
  </verify>
  <done>
    - SupplierResource + 2 pages exist; Suppliers page lists suppliers with inline Active toggle, freshness, last-seen, stale_after_days, notes; Excluded-only filter works.
    - Writes gated to admin+pricing_manager; read-only for others; no create action.
    - Feature test GREEN; pint clean; routes registered.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Domain/Sync/Services/SupplierExclusionResolverTest.php` → GREEN
2. `pest tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php` → GREEN (A/B/C)
3. `pest tests/Feature/Domain/Sync/SupplierDbSyncStaleSupplierTest.php tests/Feature/Sync/` → still GREEN (no regression)
4. `pest tests/Feature/Filament/Resources/SupplierResourceTest.php` → GREEN
5. `pint --test` on all new/changed files → PASS
6. `artisan route:list | grep suppliers` → resource routes present

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- To exclude Nuvias: open /admin/suppliers, find Nuvias, flip the Active toggle OFF. The next
  `supplier:db-sync` run (Mon-Fri 07:00 London) will ignore its price + stock. To apply immediately:
  `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan supplier:db-sync --dry-run'`
  to preview, then without --dry-run.
- If Nuvias is not yet listed, it has not been discovered — run `suppliers:check-stale` once.
- Re-activating later: flip the toggle back ON; next sync re-includes it.
</verification>

<success_criteria>
- Operator can toggle a supplier Active/Excluded in /admin/suppliers (admin + pricing_manager only).
- supplier:db-sync drops excluded suppliers' price + stock from buildBestOfferMap, unconditionally.
- Excluded-only suppliers' sole-source SKUs get no offer (flow through existing no-source handling).
- Backward-compatible: existing sync + Filament tests stay green.
- All new tests green; pint clean; routes registered.
</success_criteria>

<output>
Create `.planning/quick/260626-oqr-add-supplier-exclusion-honor-suppliers-i/260626-oqr-SUMMARY.md` documenting:
- The dead-flag finding (is_active existed since 260608-g8x but was never wired) and how this closes it.
- The two layers: SupplierExclusionResolver singleton + the unconditional buildBestOfferMap drop (ahead of the stale filter), and the Suppliers admin UI.
- Operator runbook to exclude/re-include Nuvias (toggle + when it takes effect).
- Note that exclusion is unconditional (not behind excludeStaleSuppliersFromBuyPrice) and sole-source SKUs fall through to existing no-source handling.
</output>
