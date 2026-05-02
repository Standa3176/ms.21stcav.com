<?php

declare(strict_types=1);

use App\Domain\Competitor\Ftp\Exceptions\FormatNormalisationException;
use App\Domain\Competitor\Ftp\Services\FeedFormatNormaliser;
use App\Domain\Competitor\Services\EncodingDetector;

/*
|--------------------------------------------------------------------------
| Phase 11.2 Plan 01 Task 2 — FeedFormatNormaliser tests (D-05).
|--------------------------------------------------------------------------
|
| Covers all 4 format paths: csv passthrough / tsv → csv / zip extract first
| *.csv / txt delimiter sniff. Real temp files (no mocking) — service touches
| the filesystem.
*/

beforeEach(function (): void {
    $this->normaliser = new FeedFormatNormaliser(new EncodingDetector());
    $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fnt_'.uniqid('', true);
    @mkdir($this->workDir, 0o775, true);
});

afterEach(function (): void {
    if (isset($this->workDir) && is_dir($this->workDir)) {
        // Recursive cleanup of created temp files.
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->workDir);
    }
});

it('csv format → passes through unchanged', function (): void {
    $path = $this->workDir.'/in.csv';
    file_put_contents($path, "a,b,c\n1,2,3\n");

    $result = $this->normaliser->normalise($path, 'csv', 'feed.csv');

    expect($result)->toBe($path);
    expect(file_get_contents($result))->toBe("a,b,c\n1,2,3\n");
});

it('tsv format → converts to comma-separated CSV', function (): void {
    $path = $this->workDir.'/in.tsv';
    file_put_contents($path, "a\tb\tc\n1\t2\t3\n");

    $result = $this->normaliser->normalise($path, 'tsv', 'feed.csv');

    expect($result)->not->toBe($path);
    expect(file_get_contents($result))->toContain('a,b,c');
    expect(file_get_contents($result))->toContain('1,2,3');
    expect(file_get_contents($result))->not->toContain("\t");
});

it('tsv with UTF-8 BOM → strips BOM in output', function (): void {
    $path = $this->workDir.'/bom.tsv';
    file_put_contents($path, "\xEF\xBB\xBFa\tb\n1\t2\n");

    $result = $this->normaliser->normalise($path, 'tsv', 'feed.csv');

    $contents = file_get_contents($result);
    expect(substr($contents, 0, 3))->not->toBe("\xEF\xBB\xBF",
        'UTF-8 BOM leaked into normalised CSV output.');
    expect($contents)->toContain('a,b');
});

it('tsv with Windows-1252 → converts to UTF-8 via EncodingDetector', function (): void {
    $path = $this->workDir.'/win1252.tsv';
    // Windows-1252 encoded "£" is byte 0xA3
    file_put_contents($path, "price\theader\n\xA3100\tfoo\n");

    $result = $this->normaliser->normalise($path, 'tsv', 'feed.csv');

    $contents = file_get_contents($result);
    // UTF-8 multi-byte £ = 0xC2 0xA3
    expect($contents)->toContain("\xC2\xA3");
    expect($contents)->not->toContain("\t");
});

it('zip format → extracts first matching *.csv (case-insensitive)', function (): void {
    $zipPath = $this->workDir.'/bundle.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('PRICE.CSV', "sku,price\nABC,9.99\n");
    $zip->addFromString('readme.txt', 'unrelated');
    $zip->close();

    $result = $this->normaliser->normalise($zipPath, 'zip', 'feed.csv');

    expect(file_get_contents($result))->toContain('sku,price');
    expect(file_get_contents($result))->toContain('ABC,9.99');
});

it('zip with multiple CSVs → returns first by archive order (deterministic)', function (): void {
    $zipPath = $this->workDir.'/multi.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('first.csv', "marker,1\n");
    $zip->addFromString('second.csv', "marker,2\n");
    $zip->close();

    $result = $this->normaliser->normalise($zipPath, 'zip', 'feed.csv');

    expect(file_get_contents($result))->toContain('marker,1');
    expect(file_get_contents($result))->not->toContain('marker,2');
});

it('zip without any CSV → throws FormatNormalisationException', function (): void {
    $zipPath = $this->workDir.'/nocsv.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'no csv here');
    $zip->close();

    expect(fn () => $this->normaliser->normalise($zipPath, 'zip', 'feed.csv'))
        ->toThrow(FormatNormalisationException::class, 'no CSV found');
});

it('txt with tab-heavy content → converts to comma-separated CSV', function (): void {
    $path = $this->workDir.'/tab.txt';
    file_put_contents($path, "a\tb\tc\n1\t2\t3\n");

    $result = $this->normaliser->normalise($path, 'txt', 'feed.csv');

    $contents = file_get_contents($result);
    expect($contents)->toContain('a,b,c');
    expect($contents)->not->toContain("\t");
});

it('txt with no clear delimiter pattern → throws delimiterUncertain', function (): void {
    $path = $this->workDir.'/prose.txt';
    file_put_contents($path, "This is unstructured prose with no delimiter signal.\n");

    expect(fn () => $this->normaliser->normalise($path, 'txt', 'feed.csv'))
        ->toThrow(FormatNormalisationException::class, 'could not detect delimiter');
});

it('unknown format → throws unsupportedFormat', function (): void {
    $path = $this->workDir.'/x.xlsx';
    file_put_contents($path, 'whatever');

    expect(fn () => $this->normaliser->normalise($path, 'xlsx', 'feed.csv'))
        ->toThrow(FormatNormalisationException::class, "unsupported format 'xlsx'");
});
