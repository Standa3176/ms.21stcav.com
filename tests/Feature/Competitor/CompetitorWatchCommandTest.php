<?php

declare(strict_types=1);

use App\Domain\Competitor\Jobs\IngestCompetitorCsvJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CsvParseError;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 2 — CompetitorWatchCommand
|--------------------------------------------------------------------------
|
| Scheduled every 5 minutes. File-age gate, filename regex, atomic rename
| to processing/, dispatch per file. Unknown slugs auto-create Competitor
| as status=pending (D-01).
*/

function writeIncomingCsv(string $name, int $ageSeconds = 60): string
{
    $dir = storage_path('app/competitors/incoming');
    if (! is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
    $path = $dir.DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, "sku,price\nABC-1,89.99\n");
    // Age the file so the 30s mtime gate passes.
    $oldTime = time() - $ageSeconds;
    touch($path, $oldTime, $oldTime);

    return $path;
}

afterEach(function (): void {
    foreach (['incoming', 'processing', 'archive', 'quarantine'] as $bucket) {
        $path = storage_path('app/competitors/'.$bucket);
        if (is_dir($path)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } elseif ($file->getFilename() !== '.gitkeep') {
                    @unlink($file->getPathname());
                }
            }
        }
    }
});

it('dispatches IngestCompetitorCsvJob on the competitor-csv queue for a valid, aged CSV', function (): void {
    Queue::fake();

    Competitor::factory()->create(['slug' => 'acme']);
    $path = writeIncomingCsv('acme_2026-04-21.csv', ageSeconds: 60);

    $this->artisan('competitor:watch')->assertSuccessful();

    Queue::assertPushedOn('competitor-csv', IngestCompetitorCsvJob::class);
    // File moved to processing/
    expect(file_exists($path))->toBeFalse();
    expect(file_exists(storage_path('app/competitors/processing/acme_2026-04-21.csv')))->toBeTrue();
});

it('does NOT dispatch when the file is younger than 30 seconds (mtime gate)', function (): void {
    Queue::fake();

    Competitor::factory()->create(['slug' => 'acme']);
    writeIncomingCsv('acme_2026-04-21.csv', ageSeconds: 5);

    $this->artisan('competitor:watch')->assertSuccessful();

    Queue::assertNothingPushed();
    // File stays in incoming/
    expect(file_exists(storage_path('app/competitors/incoming/acme_2026-04-21.csv')))->toBeTrue();
});

it('auto-creates a pending Competitor for an unknown slug (D-01 first-sighting)', function (): void {
    Queue::fake();

    expect(Competitor::where('slug', 'brandnew')->count())->toBe(0);
    writeIncomingCsv('brandnew_2026-04-21.csv', ageSeconds: 60);

    $this->artisan('competitor:watch')->assertSuccessful();

    $c = Competitor::where('slug', 'brandnew')->first();
    expect($c)->not->toBeNull();
    expect($c->status)->toBe(Competitor::STATUS_PENDING);
});

it('quarantines a file whose name fails the filename_regex (traversal guard)', function (): void {
    Queue::fake();

    // A filename that fails the regex — unusual characters + traversal component.
    // We write under a *sanitised* filename, then rename in place to simulate the attack.
    // The watcher only sees files via glob() — so use a filename without path separators but still invalid.
    $dir = storage_path('app/competitors/incoming');
    if (! is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
    $path = $dir.DIRECTORY_SEPARATOR.'HAS UPPERCASE_2026-04-21.csv';
    file_put_contents($path, "sku,price\n");
    $oldTime = time() - 60;
    touch($path, $oldTime, $oldTime);

    $this->artisan('competitor:watch')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(CsvParseError::where('issue_type', CsvParseError::TYPE_INVALID_FILENAME)->count())->toBe(1);
    // File moved out of incoming/
    expect(file_exists($path))->toBeFalse();
});
