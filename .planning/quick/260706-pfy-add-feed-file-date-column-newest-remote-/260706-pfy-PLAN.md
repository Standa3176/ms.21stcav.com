---
phase: 260706-pfy-add-feed-file-date-column-newest-remote-
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
  - tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php
must_haves:
  truths:
    - "The Competitor Feeds list (CompetitorResource) shows a NEW 'Feed file date' column = the NEWEST remote_file_date across that competitor's FTP feeds (the actual feed-file creation/mtime — distinct from 'Last Ingest' which is when the app processed it). Placeholder '— none' when the competitor has no feed with a date."
    - "That column is colour-coded with the SAME behind-the-latest-run rule as Last Ingest: RED when null or > config('competitor.last_run_lag_hours',24)h older than the newest remote_file_date across ACTIVE competitors' feeds; GREEN when with the latest run; GRAY when no reference. Reuses the existing pure freshnessColorFor(); the newest-feed-file-date reference is computed ONCE (memoised)."
    - "The per-row newest remote_file_date is loaded via withMax on the table query (no N+1) — attribute ftp_feeds_max_remote_file_date."
    - "The 'Feeds' count column gets a tooltip clarifying it's the number of configured feed files for the competitor."
    - "Additive/display-only: existing columns (incl. the pw3 Last Ingest colouring), the resource query semantics, actions, and badges are unchanged."
  artifacts:
    - path: "app/Domain/Competitor/Filament/Resources/CompetitorResource.php"
      provides: "Feed file date column (withMax remote_file_date) + behind-latest-run colour + Feeds tooltip"
      contains: "Feed file date"
    - path: "tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php"
      provides: "latestActiveFeedFileDate reference test (max over active, ignores inactive/no-feed)"
      contains: "latestActiveFeedFileDate"
  key_links:
    - from: "Feed file date column ->color()"
      to: "freshnessColorFor(record newest remote_file_date, latestActiveFeedFileDate(), config lag)"
      via: "reuse the pure pw3 helper + a memoised feed-file reference + withMax"
      pattern: "latestActiveFeedFileDate"
---

<objective>
The operator asked for the competitor FEED FILE creation date on Settings > Competitor Feeds. Prod data
confirms it's genuinely absent there: the page shows only 'Last Ingest' (last_ingest_at, when the app
processed the feed, ~05:05); the actual feed-file date (remote_file_date, ~00:00–04:00) lives only on the
FTP Feeds page. Add a 'Feed file date' column here = newest remote_file_date across the competitor's feeds,
with the same behind-the-latest-run red rule (260705-pw3). Also tooltip the 'Feeds' count column. Additive.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260706-pfy-add-feed-file-date-column-newest-remote-/
@CLAUDE.md
@app/Domain/Competitor/Filament/Resources/CompetitorResource.php
@app/Domain/Competitor/Models/Competitor.php
@app/Domain/Competitor/Models/CompetitorFtpFeed.php
@config/competitor.php
</context>

<interfaces>
CompetitorResource already has (from 260705-pw3): pure `freshnessColorFor(?Carbon,?Carbon,int): string`,
memoised `latestActiveIngestAt()`, and the coloured `last_ingest_at` column. REUSE freshnessColorFor.

1. Imports: add `use App\Domain\Competitor\Models\CompetitorFtpFeed;` and (Carbon already imported by pw3).

2. Load the per-row newest feed-file date without N+1 — add a table query modifier. In `table(Table $table)`:
```php
->modifyQueryUsing(fn (Builder $query): Builder => $query->withMax('ftpFeeds', 'remote_file_date'))
```
   (Builder is `Illuminate\Database\Eloquent\Builder` — already imported or add it.) This exposes
   `$record->ftp_feeds_max_remote_file_date` (string|null).

3. Memoised reference — newest feed-file date across ACTIVE competitors' feeds:
```php
protected static ?Carbon $latestActiveFeedFileDateMemo = null;
protected static bool $latestActiveFeedFileDateLoaded = false;

public static function latestActiveFeedFileDate(): ?Carbon
{
    if (! self::$latestActiveFeedFileDateLoaded) {
        self::$latestActiveFeedFileDateLoaded = true;
        $max = CompetitorFtpFeed::query()
            ->whereHas('competitor', fn (Builder $q) => $q->active())
            ->max('remote_file_date');
        self::$latestActiveFeedFileDateMemo = $max !== null ? Carbon::parse($max) : null;
    }
    return self::$latestActiveFeedFileDateMemo;
}
```

4. New column — place right after the existing `last_ingest_at` column:
```php
TextColumn::make('feed_file_date')
    ->label('Feed file date')
    ->state(fn (Competitor $record): ?string => $record->ftp_feeds_max_remote_file_date)
    ->dateTime('Y-m-d H:i')
    ->placeholder('— none')
    ->color(fn (Competitor $record): string => self::freshnessColorFor(
        $record->ftp_feeds_max_remote_file_date !== null ? Carbon::parse($record->ftp_feeds_max_remote_file_date) : null,
        self::latestActiveFeedFileDate(),
        (int) config('competitor.last_run_lag_hours', 24),
    ))
    ->tooltip(function (Competitor $record): ?string {
        $latest = self::latestActiveFeedFileDate();
        $raw = $record->ftp_feeds_max_remote_file_date;
        if ($latest === null) {
            return null;
        }
        if ($raw === null) {
            return 'No feed file date — not from the last feed run';
        }
        $date = Carbon::parse($raw);
        $lag = (int) config('competitor.last_run_lag_hours', 24);
        return $date->lt($latest->copy()->subHours(max(0, $lag)))
            ? 'Feed file behind the latest run ('.$date->diffForHumans($latest, ['parts' => 1]).' before the newest feed file)'
            : 'Feed file from the latest run';
    }),
```

5. Tooltip the existing Feeds count column (the `->counts('ftpFeeds')` TextColumn ~line 197): add
   `->tooltip('Number of feed files configured for this competitor')`.

Do NOT change last_ingest_at, other columns, actions, badges, or the delete-modal logic.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Feed file date column + behind-latest-run colour + Feeds tooltip</name>
  <files>
    app/Domain/Competitor/Filament/Resources/CompetitorResource.php,
    tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php
  </files>
  <behavior>
    Add the withMax query modifier, latestActiveFeedFileDate() (memoised), the 'Feed file date' column
    (reusing freshnessColorFor), and the Feeds tooltip per <interfaces>. Feature test (DB, RefreshDatabase):
      - Seed 3 ACTIVE competitors each with a CompetitorFtpFeed: newest remote_file_date = today 04:00
        (A), today 03:00 (B, within 24h → success), yesterday-minus (C, > 24h behind → danger); + 1 INACTIVE
        competitor with a very recent feed (must NOT raise the reference). Assert
        CompetitorResource::latestActiveFeedFileDate() equals A's 04:00 (ignores the inactive competitor).
      - Assert freshnessColorFor(C's date, latestActiveFeedFileDate(), 24) === 'danger' and
        freshnessColorFor(B's date, ..., 24) === 'success' and a competitor with no feed → null → 'danger'.
      (The pure colour rule itself is already unit-tested in 260705-pw3 — this test guards the NEW reference
       query + active-scope filtering + the null-feed path. Reset the memo between assertions if needed via
       a fresh value / new request, or assert in one pass.)
    Keep the existing CompetitorResource + pw3 tests green.
  </behavior>
  <action>
    Edit CompetitorResource (imports, withMax modifier, latestActiveFeedFileDate, the column, Feeds tooltip).
    Write the feature test. Run it + the pw3 unit test + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php 2>&1 | tail -18</automated>
    Expected: GREEN (reference = newest active feed-file date ignoring inactive; danger/success/null cases; pw3 unit still green).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Competitor/Filament/Resources/CompetitorResource.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Competitor Feeds shows a 'Feed file date' column (newest remote_file_date, no N+1) coloured red when behind the latest run; Feeds count tooltipped; reference ignores inactive competitors; tests + pint green.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` → GREEN
2. `pint --test` on the resource → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Settings > Competitor Feeds now shows TWO dates: **Feed file date** (when the competitor's CSV was
  produced — remote_file_date, the "creation date" you wanted) and **Last Ingest** (when the app processed
  it). Both go RED when behind the latest run (>24h older than the newest, tunable via
  COMPETITOR_LAST_RUN_LAG_HOURS). **Feeds** column = number of configured feed files (now tooltipped).
- With current prod data all 5 feeds are same-day (00:00–04:00) so all show GREEN; a competitor whose file
  stops updating will go red once it's a day behind the newest.
</verification>

<success_criteria>
- Competitor Feeds page shows the feed-file creation date (newest remote_file_date per competitor) with the behind-latest-run red rule; Feeds count tooltipped; no N+1; reference ignores inactive competitors; feature + pw3 tests + pint green; additive only.
</success_criteria>

<output>
Create `.planning/quick/260706-pfy-add-feed-file-date-column-newest-remote-/260706-pfy-SUMMARY.md` documenting
that the feed-file date (remote_file_date) was absent from the Competitor Feeds page (only Last Ingest showed),
the new withMax-backed 'Feed file date' column + reused behind-latest-run colour + Feeds tooltip, the test, and
the deploy note.
</output>
