<?php

declare(strict_types=1);

use App\Domain\Competitor\Jobs\CompetitorCsvChunkJob;
use App\Domain\Competitor\Jobs\IngestCompetitorCsvJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Bus;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 2 — IngestCompetitorCsvJob entry point
|--------------------------------------------------------------------------
|
| Happy path: processing/foo.csv → first-ingest mapping write + Bus::batch
| of chunk jobs. Quarantine path: ambiguous header → csv_parse_errors + no
| chunks dispatched.
*/

function seedProcessingCsv(string $fixtureName, string $destName): string
{
    $dir = storage_path('app/competitors/processing');
    if (! is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
    $dest = $dir.DIRECTORY_SEPARATOR.$destName;
    copy(base_path('tests/Fixtures/competitors/'.$fixtureName), $dest);

    return $dest;
}

afterEach(function (): void {
    // Clean up processing, archive, quarantine dirs to keep tests isolated.
    foreach (['processing', 'archive', 'quarantine'] as $bucket) {
        $path = storage_path('app/competitors/'.$bucket);
        if (is_dir($path)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
        }
    }
});

it('dispatches CompetitorCsvChunkJob(s) via Bus::batch for a valid CSV with auto-detected mapping', function (): void {
    Bus::fake();

    $c = Competitor::factory()->create(['slug' => 'acme']);
    Product::factory()->create(['sku' => 'ABC-1']);
    Product::factory()->create(['sku' => 'ABC-2']);
    Product::factory()->create(['sku' => 'ABC-3']);

    $path = seedProcessingCsv('utf8_bom.csv', 'acme_2026-04-21.csv');

    (new IngestCompetitorCsvJob($path, $c->id))->handle(
        app(\App\Domain\Competitor\Services\ColumnHeuristicDetector::class),
        app(\App\Domain\Competitor\Services\DecimalFormatDetector::class),
        app(\App\Domain\Competitor\Services\EncodingDetector::class),
    );

    // Mapping was persisted (first-time ingest)
    expect(CompetitorCsvMapping::where('competitor_id', $c->id)->count())->toBe(1);

    // Bus::batch dispatched at least one CompetitorCsvChunkJob
    Bus::assertBatched(function ($batch) {
        return $batch->jobs->contains(function ($job) {
            return $job instanceof CompetitorCsvChunkJob;
        });
    });
});

it('moves file to quarantine and writes ambiguous_mapping CsvParseError when headers have zero candidates', function (): void {
    $c = Competitor::factory()->create(['slug' => 'weird']);
    $path = seedProcessingCsv('ambiguous_headers.csv', 'weird_2026-04-21.csv');

    (new IngestCompetitorCsvJob($path, $c->id))->handle(
        app(\App\Domain\Competitor\Services\ColumnHeuristicDetector::class),
        app(\App\Domain\Competitor\Services\DecimalFormatDetector::class),
        app(\App\Domain\Competitor\Services\EncodingDetector::class),
    );

    expect(file_exists($path))->toBeFalse();
    expect(CsvParseError::where('issue_type', CsvParseError::TYPE_AMBIGUOUS_MAPPING)->count())->toBe(1);
    expect(CompetitorCsvMapping::where('competitor_id', $c->id)->count())->toBe(0);

    // Quarantine: find the moved file
    $found = false;
    $qdir = storage_path('app/competitors/quarantine');
    if (is_dir($qdir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($qdir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getFilename() === 'weird_2026-04-21.csv') {
                $found = true;
            }
        }
    }
    expect($found)->toBeTrue('File was not moved to quarantine/');

    $run = CompetitorIngestRun::where('competitor_id', $c->id)->first();
    expect($run)->not->toBeNull();
    expect($run->status)->toBe(CompetitorIngestRun::STATUS_FAILED);
});

it('uses an existing competitor_csv_mappings row on subsequent ingests (fast-path)', function (): void {
    Bus::fake();

    $c = Competitor::factory()->create(['slug' => 'acme']);
    // Pre-existing mapping: fast-path (no header-detection re-run)
    CompetitorCsvMapping::factory()->create([
        'competitor_id' => $c->id,
        'sku_column_index' => 0,
        'price_column_index' => 1,
        'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
    ]);
    Product::factory()->create(['sku' => 'ABC-1']);

    $path = seedProcessingCsv('utf8_bom.csv', 'acme_2026-04-21.csv');

    (new IngestCompetitorCsvJob($path, $c->id))->handle(
        app(\App\Domain\Competitor\Services\ColumnHeuristicDetector::class),
        app(\App\Domain\Competitor\Services\DecimalFormatDetector::class),
        app(\App\Domain\Competitor\Services\EncodingDetector::class),
    );

    // Still exactly one mapping (no duplicate row created)
    expect(CompetitorCsvMapping::where('competitor_id', $c->id)->count())->toBe(1);
    Bus::assertBatched(fn ($b) => $b->jobs->count() >= 1);
});
