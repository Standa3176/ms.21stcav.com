---
phase: 260707-fkx-feeds-count-column-still-blank-on-mariad
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
  - tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php
must_haves:
  truths:
    - "The Feeds column renders the ftpFeeds count on PROD (MariaDB) — proven blank there even after the 260707-f06 key rename (HEAD b872801 deployed, still blank). Root cause: Filament ->counts('ftpFeeds') applies a withCount aggregate-subquery that, combined with the table's ->modifyQueryUsing(withMax('ftpFeeds','remote_file_date')), yields the ftp_feeds_count attribute on SQLite (tests green) but NOT on MariaDB (prod) → column read null → blank. This is the project's known SQLite↔MariaDB divergence class."
    - "Fix: the Feeds column resolves its value with an engine-independent per-row relation count — ->state(fn (Competitor $record): int => $record->ftpFeeds()->count()) — instead of the ->counts() aggregate. This runs a plain COUNT per row (identical on SQLite + MariaDB) and cannot be affected by aggregate-select/subquery behaviour. The Competitors list is a tiny (~5 row) settings table so per-row counts are negligible."
    - "The ->counts('ftpFeeds') call is REMOVED from the column (it was the fragile part). ->label('Feeds') + ->tooltip(...) stay. ->sortable() is dropped (a computed ->state can't DB-sort; keeping it would sort by a column that doesn't exist). The withMax modifier + Feed file date column + Last Ingest colouring are UNCHANGED."
    - "The guard test asserts the column state equals the ftpFeeds count (2 and 0) — and is meaningful because ->state() runs the same on SQLite; the point is it no longer depends on aggregate-select behaviour."
  artifacts:
    - path: "app/Domain/Competitor/Filament/Resources/CompetitorResource.php"
      provides: "Feeds column via engine-independent ->state() relation count"
      contains: "ftpFeeds()->count()"
    - path: "tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php"
      provides: "state = ftpFeeds count (2 / 0) via the closure"
      contains: "ftp_feeds_count"
  key_links:
    - from: "Feeds TextColumn value"
      to: "$record->ftpFeeds()->count()"
      via: "replace ->counts() aggregate with ->state() direct count"
      pattern: "ftpFeeds()->count()"
---

<objective>
The Feeds column on Settings > Competitor Feeds is STILL blank on prod after the 260707-f06 key rename
(b872801 confirmed deployed). Cause: Filament's ->counts('ftpFeeds') aggregate-subquery + the table's
->modifyQueryUsing(withMax('ftpFeeds', ...)) together populate ftp_feeds_count on SQLite (tests pass) but
not on MariaDB (prod) — the recurring SQLite↔MariaDB trap. Make the count engine-independent: resolve it
with a direct per-row relation count via ->state(), removing reliance on the aggregate entirely.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260707-fkx-feeds-count-column-still-blank-on-mariad/
@CLAUDE.md
@app/Domain/Competitor/Filament/Resources/CompetitorResource.php
@app/Domain/Competitor/Models/Competitor.php
@tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php
</context>

<interfaces>
CompetitorResource — current Feeds column (~lines 229-233):
```php
TextColumn::make('ftp_feeds_count')
    ->label('Feeds')
    ->counts('ftpFeeds')                 // <- aggregate: works on SQLite, NULL on MariaDB w/ the withMax modifier
    ->tooltip('Number of feed files configured for this competitor')
    ->sortable(),
```
REPLACE with an engine-independent per-row count:
```php
TextColumn::make('ftp_feeds_count')
    ->label('Feeds')
    // 260707-fkx — resolve the count directly per row instead of Filament's
    // ->counts() aggregate: the withCount subquery + the withMax modifier
    // populated this on SQLite (tests) but NOT on MariaDB (prod) → blank.
    // Direct COUNT is identical on both engines; ~5-row settings table so N+1
    // is negligible.
    ->state(fn (Competitor $record): int => $record->ftpFeeds()->count())
    ->tooltip('Number of feed files configured for this competitor'),
```
Remove `->counts('ftpFeeds')` and `->sortable()` from this column. Do NOT touch the
`->modifyQueryUsing(withMax('ftpFeeds','remote_file_date'))` line (the Feed file date column needs it), the
Feed file date column, the Last Ingest colouring, or any other column/action.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Feeds count via engine-independent ->state() relation count</name>
  <files>
    app/Domain/Competitor/Filament/Resources/CompetitorResource.php,
    tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php
  </files>
  <behavior>
    Replace the Feeds column's ->counts()/sortable() with ->state(fn (Competitor $record): int =>
    $record->ftpFeeds()->count()) per <interfaces>. Update/keep the existing
    CompetitorFeedsCountColumnTest so it still asserts the column state equals the ftpFeeds count:
      - competitor with 2 ftpFeeds → assertTableColumnStateSet('ftp_feeds_count', 2, record: $withFeeds)
      - competitor with 0 → assertTableColumnStateSet('ftp_feeds_count', 0, record: $noFeeds)
    (Reuse the test's existing auth/seed setup; only the column mechanism changed, so the assertions hold.)
    Keep pfy + pw3 competitor tests green.
  </behavior>
  <action>
    Edit the Feeds column (drop ->counts + ->sortable, add ->state closure). Adjust the test if the previous
    version relied on the aggregate attribute being on the query (the ->state closure makes it independent).
    Run the test + pfy/pw3 regression + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php 2>&1 | tail -15</automated>
    Expected: GREEN — column state = 2 and 0 via the ->state closure.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php 2>&1 | tail -8</automated>
    Expected: pfy + pw3 still GREEN.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Competitor/Filament/Resources/CompetitorResource.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Feeds column resolves via a direct ftpFeeds()->count() (engine-independent), no ->counts() aggregate; test asserts 2/0; pfy+pw3 green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php` → GREEN
2. pfy + pw3 competitor tests → GREEN
3. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- The Feeds column now shows the count on prod (MariaDB) — the ->counts() aggregate wasn't populating the
  attribute on MariaDB alongside the withMax modifier (worked on SQLite → tests green → the divergence
  slipped through, classic SQLite↔MariaDB trap). Direct per-row count is engine-independent.
- Trade-off: the Feeds column is no longer DB-sortable (it's a computed value). Acceptable for a ~5-row list;
  say so if you want sorting back and I'll add a withCount + orderBy that's MariaDB-verified.
- If, after deploy, it's STILL blank, that would point to something outside the query (unlikely with ->state)
  — screenshot it and I'll probe the live render directly.
</verification>

<success_criteria>
- Feeds column renders the ftpFeeds count on MariaDB via an engine-independent ->state() relation count (no aggregate dependency); tooltip kept; withMax/Feed-file-date/Last-Ingest untouched; test asserts 2/0; pfy+pw3 green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260707-fkx-feeds-count-column-still-blank-on-mariad/260707-fkx-SUMMARY.md` documenting
the SQLite-green/MariaDB-blank aggregate divergence (why the f06 key rename wasn't enough), the ->state()
direct-count fix, the sortable trade-off, and the deploy note.
</output>
