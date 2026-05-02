<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Phase 11.2 D-07 — Phase 5 ingest pipeline UNTOUCHED.
|--------------------------------------------------------------------------
|
| Architectural test: CompetitorWatchCommand + IngestCompetitorCsvJob have
| ZERO modifications since Phase 5's ship date. Format normalisation in
| Phase 11.2 happens BEFORE the file lands in storage/app/competitors/incoming/,
| so Phase 5 only ever sees clean .csv files.
|
| Baseline strategy: count git commits since 2026-04-25 (day before Phase 11.1
| shipped) that touched these files. Expect 0. Phase 5 shipped in commit
| cc921a2 well before this date — any commit since 2026-04-25 to either file
| violates D-07.
|
| If the dev branch picks up an unrelated rename, anchor on the Phase 5 SHA
| documented in .planning/phases/05-competitor-analysis/05-01-SUMMARY.md.
*/

it('Phase 5 CompetitorWatchCommand has zero modifications since Phase 11.x ship window (D-07)', function (): void {
    $result = Process::run([
        'git', 'log', '--since=2026-04-25', '--oneline', '--',
        'app/Domain/Competitor/Console/Commands/CompetitorWatchCommand.php',
    ]);

    $output = trim($result->output());

    expect($output)->toBe(
        '',
        'D-07 violation: Phase 5 CompetitorWatchCommand was modified after 2026-04-25. '
        .'Format normalisation must happen BEFORE the file lands in incoming/. Found commits: '.$output
    );
});

it('Phase 5 IngestCompetitorCsvJob has zero modifications since Phase 11.x ship window (D-07)', function (): void {
    $result = Process::run([
        'git', 'log', '--since=2026-04-25', '--oneline', '--',
        'app/Domain/Competitor/Jobs/IngestCompetitorCsvJob.php',
    ]);

    $output = trim($result->output());

    expect($output)->toBe(
        '',
        'D-07 violation: Phase 5 IngestCompetitorCsvJob was modified after 2026-04-25. '
        .'Phase 5 ingest pipeline must be byte-identical pre/post Phase 11.x. Found commits: '.$output
    );
});
