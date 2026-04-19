<?php

declare(strict_types=1);

use Spatie\Activitylog\Models\Activity;

/**
 * Phase 5 Plan 05 Task 1 — competitor:csv-prune command (COMP-12).
 *
 * Scope: prunes ONLY files under storage/app/competitors/archive/** older
 * than --days (default 0 → config('competitor.csv_retention_days', 90)).
 * NEVER touches:
 *   - competitor_prices rows (COMP-07 mandate)
 *   - competitor_ingest_runs rows
 *   - csv_parse_errors rows
 *   - files under competitors/{incoming,processing,quarantine}
 *
 * Audits every run via Auditor → `competitor.csv_pruned` activity entry.
 */
beforeEach(function (): void {
    $this->archivePath = storage_path('app/competitors/archive');
    $this->incomingPath = storage_path('app/competitors/incoming');
    $this->quarantinePath = storage_path('app/competitors/quarantine');
    $this->processingPath = storage_path('app/competitors/processing');

    foreach ([$this->archivePath, $this->incomingPath, $this->quarantinePath, $this->processingPath] as $dir) {
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        // Scrub any leftover files from prior tests (except .gitkeep).
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_file($full)) {
                @unlink($full);
            } elseif (is_dir($full)) {
                // Recursively clear subdirectories we created
                foreach (scandir($full) ?: [] as $inner) {
                    if ($inner === '.' || $inner === '..') {
                        continue;
                    }
                    @unlink($full.DIRECTORY_SEPARATOR.$inner);
                }
                @rmdir($full);
            }
        }
    }
});

function writeCsvAtAge(string $path, int $daysOld, string $body = "sku,price\nA1,9.99\n"): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
    file_put_contents($path, $body);
    $timestamp = now()->subDays($daysOld)->timestamp;
    touch($path, $timestamp);
}

it('--days=0 is a no-op safety guard — prints warning, deletes nothing, returns 0', function (): void {
    $victim = $this->archivePath.'/2025-01-15/old.csv';
    writeCsvAtAge($victim, 200);

    expect(is_file($victim))->toBeTrue();

    $this->artisan('competitor:csv-prune --days=0')
        ->expectsOutputToContain('--days=0 is a no-op safety guard')
        ->assertSuccessful();

    expect(is_file($victim))->toBeTrue('--days=0 must not delete any files');
});

it('--days=90 deletes files older than 90 days and preserves newer files', function (): void {
    $old = $this->archivePath.'/2025-01-15/old.csv';
    $fresh = $this->archivePath.'/2026-04-15/fresh.csv';

    writeCsvAtAge($old, 100);
    writeCsvAtAge($fresh, 4);

    $this->artisan('competitor:csv-prune --days=90')->assertSuccessful();

    expect(is_file($old))->toBeFalse('file older than 90 days must be deleted');
    expect(is_file($fresh))->toBeTrue('file younger than 90 days must be preserved');
});

it('default (no --days flag) uses config(competitor.csv_retention_days) = 90', function (): void {
    config()->set('competitor.csv_retention_days', 90);

    $old = $this->archivePath.'/2025-01-15/old.csv';
    $fresh = $this->archivePath.'/2026-04-15/fresh.csv';

    writeCsvAtAge($old, 91);
    writeCsvAtAge($fresh, 2);

    $this->artisan('competitor:csv-prune')->assertSuccessful();

    expect(is_file($old))->toBeFalse();
    expect(is_file($fresh))->toBeTrue();
});

it('NEVER touches files under incoming/, processing/, or quarantine/ even if old', function (): void {
    $safeIncoming = $this->incomingPath.'/safe_incoming.csv';
    $safeProcessing = $this->processingPath.'/safe_processing.csv';
    $safeQuarantine = $this->quarantinePath.'/safe_quarantine.csv';

    writeCsvAtAge($safeIncoming, 365);
    writeCsvAtAge($safeProcessing, 365);
    writeCsvAtAge($safeQuarantine, 365);

    $this->artisan('competitor:csv-prune --days=1')->assertSuccessful();

    expect(is_file($safeIncoming))->toBeTrue('incoming/ must never be pruned');
    expect(is_file($safeProcessing))->toBeTrue('processing/ must never be pruned');
    expect(is_file($safeQuarantine))->toBeTrue('quarantine/ must never be pruned');
});

it('skips .gitkeep files even if aged', function (): void {
    $gitkeep = $this->archivePath.'/.gitkeep';
    writeCsvAtAge($gitkeep, 365, '');

    $this->artisan('competitor:csv-prune --days=1')->assertSuccessful();

    expect(is_file($gitkeep))->toBeTrue('.gitkeep is a sentinel, must never be deleted');
});

it('writes competitor.csv_pruned activity log with deleted_count + cutoff_date + days + archive_path', function (): void {
    $old1 = $this->archivePath.'/2025-01-15/a.csv';
    $old2 = $this->archivePath.'/2025-01-16/b.csv';
    writeCsvAtAge($old1, 120);
    writeCsvAtAge($old2, 100);

    Activity::query()->delete();

    $this->artisan('competitor:csv-prune --days=90')->assertSuccessful();

    $entry = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'competitor.csv_pruned')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull();

    $props = $entry->properties->toArray();
    expect($props)
        ->toHaveKey('deleted_count')
        ->toHaveKey('cutoff_date')
        ->toHaveKey('days')
        ->toHaveKey('archive_path');
    expect($props['deleted_count'])->toBe(2);
    expect($props['days'])->toBe(90);
    expect($props['archive_path'])->toContain('competitors');
    expect($props['archive_path'])->toContain('archive');
});

it('handles missing archive directory gracefully (exit 0, no exception)', function (): void {
    // Wipe archive dir entirely
    foreach (scandir($this->archivePath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $this->archivePath.DIRECTORY_SEPARATOR.$entry;
        if (is_file($full)) {
            @unlink($full);
        }
    }
    @rmdir($this->archivePath);

    $this->artisan('competitor:csv-prune --days=30')->assertSuccessful();

    // Restore archive/.gitkeep sentinel for repo hygiene — this test
    // intentionally wipes the tree to exercise the missing-dir branch.
    if (! is_dir($this->archivePath)) {
        mkdir($this->archivePath, 0o775, true);
    }
    file_put_contents($this->archivePath.DIRECTORY_SEPARATOR.'.gitkeep', '');
});

it('is registered as an artisan command', function (): void {
    $commands = \Illuminate\Support\Facades\Artisan::all();
    expect(array_key_exists('competitor:csv-prune', $commands))->toBeTrue();
});
