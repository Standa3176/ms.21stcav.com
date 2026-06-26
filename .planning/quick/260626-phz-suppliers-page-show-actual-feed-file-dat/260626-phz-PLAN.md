---
phase: 260626-phz-suppliers-page-show-actual-feed-file-dat
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Sync/Filament/Resources/SupplierResource.php
  - tests/Unit/Domain/Sync/SupplierFeedAgeTest.php
  - tests/Feature/Filament/Resources/SupplierResourceTest.php
autonomous: true
requirements:
  - QUICK-260626-phz
must_haves:
  truths:
    - "The Suppliers admin page shows each supplier's feed date as an ACTUAL DATE (e.g. 'Fri 20 Jun 2026'), sourced from SupplierFreshnessResolver::latestRecordedAtFor() (MAX recorded_at = last date MS received matching feed data), not a relative 'N days ago' phrase."
    - "That date cell is colour-coded by WORKING-DAY age (weekends excluded): RED (danger) when older than 5 working days, AMBER (warning) at 4-5 working days, GREEN (success) when 3 or fewer; GRAY ('never') when the supplier has no recorded feed data."
    - "Working-day age is computed by a pure, deterministic helper SupplierResource::workingDaysSince(?Carbon): ?int using Carbon weekday diff, version-robust (absolute value), comparing start-of-day to start-of-day. Colour mapping is a pure helper SupplierResource::feedAgeColor(?int): string."
    - "The existing per-supplier freshness badge (fresh/amber/stale on stale_after_days) REMAINS — the new feed-date column is added alongside it (it replaces the old relative 'Last seen' column, which conveyed the same data less precisely)."
    - "A tooltip on the date cell states the working-day age in words (e.g. '6 working days ago' / 'Today' / 'No feed data recorded yet')."
  artifacts:
    - path: "app/Domain/Sync/Filament/Resources/SupplierResource.php"
      provides: "Feed-date badge column (actual date + 5-working-day colour) + pure helpers workingDaysSince/feedAgeColor/feedAgeTooltip"
      contains: "feedAgeColor"
    - path: "tests/Unit/Domain/Sync/SupplierFeedAgeTest.php"
      provides: "Deterministic boundary tests (Carbon::setTestNow) for workingDaysSince + feedAgeColor"
      contains: "setTestNow"
  key_links:
    - from: "SupplierResource feed_date column ->color()"
      to: "SupplierResource::feedAgeColor(workingDaysSince(latestRecordedAtFor))"
      via: "pure helpers over the resolver's MAX(recorded_at)"
      pattern: "feedAgeColor"
---

<objective>
On the Suppliers admin page (/admin/suppliers, built in 260626-oqr), show each supplier's FEED DATE
as a real date and colour it RED when the feed data is older than 5 WORKING days — so an operator can
glance the list and spot suppliers (e.g. Nuvias) that have gone quiet.

DECISIONS (confirmed with operator 2026-06-26):
  - Date source = "last data we received": SupplierFreshnessResolver::latestRecordedAtFor() =
    MAX(supplier_offer_snapshots.recorded_at). recorded_at is stamped today() by supplier:db-sync on
    each pull, so it tracks "last date MS got matching feed data from this supplier" — the same signal
    the existing stale detection uses. (No remote-schema work; we are NOT pulling the supplier's own
    upstream file timestamp.)
  - Colour rule = ADD a date+age column with the 5-working-day rule; KEEP the existing fresh/amber/
    stale freshness badge alongside.

The current resource has a relative "Last seen" column (diffForHumans). Replace it with a precise,
colour-coded "Feed date" column. Working-day age excludes weekends (bank holidays not considered —
acceptable; note in SUMMARY).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260626-phz-suppliers-page-show-actual-feed-file-dat/
@CLAUDE.md
@app/Domain/Sync/Filament/Resources/SupplierResource.php
@app/Domain/Sync/Services/SupplierFreshnessResolver.php
@tests/Feature/Filament/Resources/SupplierResourceTest.php

<interfaces>
<!-- Extracted from the codebase — use directly. -->

SupplierResource (app/Domain/Sync/Filament/Resources/SupplierResource.php) currently has, in
table()->columns(), a relative "Last seen" column at lines 133-137:
```php
TextColumn::make('last_seen')
    ->label('Last seen')
    ->getStateUsing(fn (Supplier $record): ?string => app(SupplierFreshnessResolver::class)
        ->latestRecordedAtFor((string) $record->supplier_id)?->diffForHumans())
    ->placeholder('never'),
```
REPLACE this column with the new feed_date badge column (below). The 'freshness' badge column
immediately above it (lines 122-132) STAYS unchanged.

SupplierFreshnessResolver::latestRecordedAtFor(string $supplierId): ?\Carbon\Carbon — MAX(recorded_at)
for the supplier, or null when no snapshots. It is a singleton (per-request cached), so calling it
2-3× per row in the column closures is cheap.

Carbon weekday diff: `$from->diffInWeekdays($to)` counts weekdays between two dates. Carbon's sign/
absolute behaviour differs across v2/v3 — wrap in abs() and round() for version-robustness, and
normalise both ends to start-of-day so partial-day time components never shift the count.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Pure working-day-age helpers + deterministic unit test (RED→GREEN)</name>
  <files>
    app/Domain/Sync/Filament/Resources/SupplierResource.php,
    tests/Unit/Domain/Sync/SupplierFeedAgeTest.php
  </files>
  <behavior>
    Three pure public static helpers on SupplierResource:

    ```php
    public static function workingDaysSince(?\Carbon\Carbon $date): ?int
    {
        if ($date === null) {
            return null;
        }
        // Weekdays between the feed date and now, weekends excluded. abs()+round()
        // for Carbon v2/v3 sign robustness; start-of-day both ends so a partial
        // day never shifts the count.
        return (int) round(abs(
            $date->copy()->startOfDay()->diffInWeekdays(now()->startOfDay())
        ));
    }

    public static function feedAgeColor(?int $workingDays): string
    {
        return match (true) {
            $workingDays === null => 'gray',
            $workingDays > 5      => 'danger',
            $workingDays >= 4     => 'warning',
            default               => 'success',
        };
    }

    public static function feedAgeTooltip(?int $workingDays): ?string
    {
        if ($workingDays === null) {
            return 'No feed data recorded yet';
        }
        if ($workingDays === 0) {
            return 'Today';
        }
        return $workingDays.' working day'.($workingDays === 1 ? '' : 's').' ago';
    }
    ```

    Unit test tests/Unit/Domain/Sync/SupplierFeedAgeTest.php — pin "now" with
    Carbon::setTestNow('2026-06-26') (a FRIDAY) and assert workingDaysSince + feedAgeColor at the
    boundaries (computed by counting weekdays from each date to Fri 2026-06-26):
      - 2026-06-26 (Fri, today)      → 0 working days → 'success'
      - 2026-06-25 (Thu)             → 1             → 'success'
      - 2026-06-23 (Tue)             → 3             → 'success'
      - 2026-06-22 (Mon)             → 4             → 'warning'
      - 2026-06-19 (prev Fri)        → 5             → 'warning'
      - 2026-06-18 (prev Thu)        → 6             → 'danger'   (older than 5 working days)
      - 2026-06-12 (Fri, 2 wks back) → 10            → 'danger'
      - null                         → null          → 'gray'
    Also assert feedAgeTooltip: null → 'No feed data recorded yet'; 0 → 'Today'; 1 → '1 working day
    ago'; 6 → '6 working days ago'. Call Carbon::setTestNow() (no arg) in an afterEach/cleanup to
    reset.

    NOTE: if the exact weekday counts differ by ±1 against the installed Carbon version, adjust the
    EXPECTED numbers to match Carbon's actual output for these fixed dates — but the COLOUR boundaries
    (>5 danger, 4-5 warning, ≤3 success) are the contract and must hold. Verify the 2026-06-18 case
    lands in 'danger' and 2026-06-22 in 'warning'.
  </behavior>
  <action>
    Add the three helpers to SupplierResource (near the existing private canWrite() helper; these are
    public for unit testing). Add `use Carbon\Carbon;` if not present. Write the unit test. Run it.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Domain/Sync/SupplierFeedAgeTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (after aligning any ±1 weekday-count expectations to the installed Carbon; colour boundaries hold).
  </verify>
  <done>
    - Three pure helpers exist on SupplierResource.
    - Unit test GREEN; colour boundaries (>5 danger, 4-5 warning, ≤3 success, null gray) verified at fixed dates.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Feed-date badge column on SupplierResource</name>
  <files>
    app/Domain/Sync/Filament/Resources/SupplierResource.php,
    tests/Feature/Filament/Resources/SupplierResourceTest.php
  </files>
  <behavior>
    The relative "Last seen" column is replaced by a "Feed date" badge column:
    ```php
    TextColumn::make('feed_date')
        ->label('Feed date')
        ->badge()
        ->getStateUsing(fn (Supplier $record): ?string => app(SupplierFreshnessResolver::class)
            ->latestRecordedAtFor((string) $record->supplier_id)?->format('D j M Y'))
        ->placeholder('never')
        ->color(fn (Supplier $record): string => self::feedAgeColor(self::workingDaysSince(
            app(SupplierFreshnessResolver::class)->latestRecordedAtFor((string) $record->supplier_id)
        )))
        ->tooltip(fn (Supplier $record): ?string => self::feedAgeTooltip(self::workingDaysSince(
            app(SupplierFreshnessResolver::class)->latestRecordedAtFor((string) $record->supplier_id)
        ))),
    ```
    Placed where 'last_seen' was (between the 'freshness' badge and 'stale_after_days'). The
    'freshness' badge column stays. Update the class docblock to mention the feed-date column +
    5-working-day colour rule (260626-phz).

    Feature test additions (tests/Feature/Filament/Resources/SupplierResourceTest.php): seed a supplier
    with a SupplierOfferSnapshot at a known recorded_at and assert the List page renders the formatted
    date string (e.g. assertSee on the 'D j M Y' value). Pin Carbon::setTestNow to a fixed date so the
    seeded recorded_at and the rendered output are deterministic; reset after. (Colour is unit-tested
    in Task 1 — the feature test asserts the DATE renders and the page stays successful.)
  </behavior>
  <action>
    Replace the last_seen column block with the feed_date column above. Keep everything else. Run pint.
    Extend the existing SupplierResource feature test with a feed-date render assertion (reuse its role
    helper + supplier/snapshot seeding; mirror the existing freshness-column seeding so
    latestRecordedAtFor resolves).
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Filament/Resources/SupplierResourceTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (existing cases + the new feed-date render assertion).

    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Sync/Filament/Resources/SupplierResource.php 2>&1 | tail -5</automated>
    Expected: PASS.

    <automated>grep -n "last_seen" app/Domain/Sync/Filament/Resources/SupplierResource.php; echo "exit:$?"</automated>
    Expected: no match (relative column removed). grep exit 1 = good.
  </verify>
  <done>
    - Feed-date badge column renders an actual date, coloured by the 5-working-day rule, with a working-day tooltip; 'never' + gray when no data.
    - Freshness badge retained; relative last_seen column gone.
    - Feature test GREEN; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Domain/Sync/SupplierFeedAgeTest.php` → GREEN (boundary + colour)
2. `pest tests/Feature/Filament/Resources/SupplierResourceTest.php` → GREEN
3. `pint --test app/Domain/Sync/Filament/Resources/SupplierResource.php` → PASS
4. `grep last_seen SupplierResource.php` → no match

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- On /admin/suppliers the 'Feed date' column now shows the actual date of the last feed data we
  received per supplier, RED when older than 5 working days, AMBER at 4-5, GREEN otherwise. Nuvias
  (or any quiet supplier) will surface RED.
- Caveat: the date is "last date MeetingStore received matching feed data" (recorded_at), not the
  supplier's own upstream file timestamp; working-day age excludes weekends but not bank holidays.
</verification>

<success_criteria>
- Suppliers page shows an actual feed date per supplier, colour-coded RED when older than 5 working days (weekends excluded), AMBER at 4-5, GREEN ≤3, GRAY when never.
- Existing freshness badge retained; relative last_seen column removed.
- Colour/age logic is pure + deterministically unit-tested at boundaries; feature test green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260626-phz-suppliers-page-show-actual-feed-file-dat/260626-phz-SUMMARY.md` documenting:
- The feed-date column + 5-working-day colour rule, and that it reuses recorded_at (last-received date) per the operator decision.
- The pure helpers + deterministic Carbon::setTestNow boundary tests.
- The caveat (recorded_at vs true upstream file date; weekends excluded, bank holidays not).
- Operator note: quiet suppliers now surface RED at a glance.
</output>
