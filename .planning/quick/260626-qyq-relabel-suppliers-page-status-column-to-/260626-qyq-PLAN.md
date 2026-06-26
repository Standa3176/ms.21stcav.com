---
phase: 260626-qyq-relabel-suppliers-page-status-column-to-
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Sync/Filament/Resources/SupplierResource.php
  - tests/Feature/Filament/Resources/SupplierResourceTest.php
autonomous: true
requirements:
  - QUICK-260626-qyq
must_haves:
  truths:
    - "The Suppliers page status column is labelled 'Feed status' and shows Active (feed_status=1) / Inactive (feed_status=0) / Unknown (null) — mirroring the legacy dashboard's Status column (feeds.status), NOT a Fresh/Stale/Feed-off blend."
    - "Staleness is conveyed solely by the Feed date column's red colour (>5 working days), exactly like the legacy dash — so an Active-but-ancient feed (e.g. Unicol, feed_status=1, date 2023) shows 'Active' status with a RED date."
    - "The 'Feed status' column does NOT collide with the operator 'Active' toggle (is_active, the MS-side pricing-exclusion control) — they are clearly distinct columns/labels."
    - "Active → green, Inactive → red, Unknown → gray."
  artifacts:
    - path: "app/Domain/Sync/Filament/Resources/SupplierResource.php"
      provides: "Feed status column = Active/Inactive/Unknown from feed_status"
      contains: "'Feed status'"
  key_links:
    - from: "SupplierResource feed_status_label column"
      to: "suppliers.feed_status"
      via: "match(1=>Active,0=>Inactive,default=>Unknown)"
      pattern: "Inactive"
---

<objective>
Align the Suppliers page status column with the legacy dashboard the operator uses (screenshot
2026-06-26: a 'Status' column showing Active/Inactive straight from feeds.status). Currently MS shows
a blended 'Feed off / Stale / Fresh' badge that (a) duplicates the staleness already shown by the
red Feed date colour and (b) is confusable with the operator 'Active' toggle (is_active = MS-side
pricing exclusion, a different concept).

Change the status column to a 'Feed status' column = Active (feed_status=1) / Inactive (feed_status=0)
/ Unknown (null). Leave the Feed date column (red >5 working days) as the staleness signal — same
split the legacy dash uses.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260626-qyq-relabel-suppliers-page-status-column-to-/
@CLAUDE.md
@app/Domain/Sync/Filament/Resources/SupplierResource.php
@tests/Feature/Filament/Resources/SupplierResourceTest.php

<interfaces>
CURRENT status column in SupplierResource::table() — REPLACE its body:
```php
TextColumn::make('feed_status_label')
    ->label('Status')
    ->badge()
    ->getStateUsing(function (Supplier $record): string {
        if ($record->feed_status === 0) { return 'Feed off'; }
        if ($record->feed_remote_date === null) { return 'No data'; }
        return self::workingDaysSince($record->feed_remote_date) > 5 ? 'Stale' : 'Fresh';
    })
    ->color(fn (string $state): string => match ($state) {
        'Fresh' => 'success', 'Stale' => 'danger', 'Feed off' => 'danger', default => 'gray',
    }),
```
TARGET:
```php
TextColumn::make('feed_status_label')
    ->label('Feed status')
    ->badge()
    ->getStateUsing(fn (Supplier $record): string => match ($record->feed_status) {
        1 => 'Active',
        0 => 'Inactive',
        default => 'Unknown',
    })
    ->color(fn (string $state): string => match ($state) {
        'Active' => 'success',
        'Inactive' => 'danger',
        default => 'gray',
    }),
```
Update the column's comment block to reflect Active/Inactive-from-feeds.status (drop the Fresh/Stale
wording). The Feed date column and all other columns stay unchanged.

feed_status is cast 'integer' on the Supplier model (1/0/null).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Relabel status column to Feed status (Active/Inactive)</name>
  <files>
    app/Domain/Sync/Filament/Resources/SupplierResource.php,
    tests/Feature/Filament/Resources/SupplierResourceTest.php
  </files>
  <behavior>
    The status column shows 'Feed status' = Active/Inactive/Unknown per <interfaces>. Update any
    feature-test assertion that expected 'Feed off'/'Stale'/'Fresh' to expect 'Inactive'/'Active'
    instead (Nuvias feed_status=0 → 'Inactive'; a feed_status=1 supplier → 'Active'; the red Feed date
    still asserts staleness separately if covered).
  </behavior>
  <action>
    Replace the status column body with the TARGET in <interfaces> and refresh its comment. Update the
    SupplierResourceTest assertions accordingly (e.g. the Nuvias case now asserts 'Inactive' rather than
    'Feed off'; keep the 'Thu 14 May 2026' Feed-date assertion). Run the test + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Filament/Resources/SupplierResourceTest.php 2>&1 | tail -20</automated>
    Expected: GREEN.

    <automated>grep -n "Feed off\|'Stale'\|'Fresh'" app/Domain/Sync/Filament/Resources/SupplierResource.php; echo "exit:$?"</automated>
    Expected: no match (old wording gone). grep exit 1 = good.

    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Sync/Filament/Resources/SupplierResource.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Status column = 'Feed status' Active/Inactive/Unknown from feed_status; old blend wording gone.
    - Feature test updated + GREEN; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Filament/Resources/SupplierResourceTest.php` → GREEN
2. `grep "Feed off|'Stale'|'Fresh'" SupplierResource.php` → no match
3. `pint --test SupplierResource.php` → PASS
</verification>

<success_criteria>
- Suppliers page shows 'Feed status' = Active/Inactive/Unknown (from feeds.status), matching the legacy dash.
- Staleness still shown by the red Feed date colour; no collision with the operator 'Active' toggle.
- Test GREEN; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260626-qyq-relabel-suppliers-page-status-column-to-/260626-qyq-SUMMARY.md` documenting the relabel (Active/Inactive from feeds.status, matching the legacy dash) and that staleness remains on the Feed date colour.
</output>
