<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Quick task 260504-e0q Tests.
 *
 * Verifies competitor:retry-quarantine lists / moves files between
 * storage/app/competitors/quarantine/ and incoming/.
 *
 * Uses real files under storage_path() because the command works directly
 * with the filesystem (Symfony Finder + rename), not the Storage facade.
 * beforeEach wipes the directories so each test starts clean.
 */

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());

    $base = storage_path('app/competitors');
    File::deleteDirectory($base.'/incoming');
    File::deleteDirectory($base.'/quarantine');
    File::ensureDirectoryExists($base.'/incoming');
    File::ensureDirectoryExists($base.'/quarantine/2026-05-03');
});

afterEach(function (): void {
    $base = storage_path('app/competitors');
    File::deleteDirectory($base.'/incoming');
    File::deleteDirectory($base.'/quarantine');
    File::ensureDirectoryExists($base.'/incoming');
});

it('lists quarantined files without moving them when no flags given', function (): void {
    $base = storage_path('app/competitors');
    File::put($base.'/quarantine/2026-05-03/foo.csv', "sku,price\nA,1\n");
    File::put($base.'/quarantine/2026-05-03/foo.csv.error.json', json_encode(['error' => 'database is locked']));

    $exit = Artisan::call('competitor:retry-quarantine');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('1 quarantined file');
    expect($output)->toContain('foo.csv');
    expect($output)->toContain('database is locked');
    expect(file_exists($base.'/quarantine/2026-05-03/foo.csv'))->toBeTrue();
    expect(file_exists($base.'/incoming/foo.csv'))->toBeFalse();
});

it('--all moves all .csv files quarantine → incoming and deletes .error.json sidecars', function (): void {
    $base = storage_path('app/competitors');
    File::put($base.'/quarantine/2026-05-03/a.csv', 'a');
    File::put($base.'/quarantine/2026-05-03/a.csv.error.json', '{}');
    File::put($base.'/quarantine/2026-05-03/b.csv', 'b');
    File::put($base.'/quarantine/2026-05-03/b.csv.error.json', '{}');

    $exit = Artisan::call('competitor:retry-quarantine', ['--all' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('moved=2');
    expect(file_exists($base.'/incoming/a.csv'))->toBeTrue();
    expect(file_exists($base.'/incoming/b.csv'))->toBeTrue();
    expect(file_exists($base.'/quarantine/2026-05-03/a.csv'))->toBeFalse();
    expect(file_exists($base.'/quarantine/2026-05-03/a.csv.error.json'))->toBeFalse();
    expect(file_exists($base.'/quarantine/2026-05-03/b.csv.error.json'))->toBeFalse();
});

it('--file=NAME moves only the matching file', function (): void {
    $base = storage_path('app/competitors');
    File::put($base.'/quarantine/2026-05-03/a.csv', 'a');
    File::put($base.'/quarantine/2026-05-03/b.csv', 'b');

    $exit = Artisan::call('competitor:retry-quarantine', ['--file' => 'b.csv']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('moved=1');
    expect(file_exists($base.'/incoming/a.csv'))->toBeFalse();
    expect(file_exists($base.'/incoming/b.csv'))->toBeTrue();
    expect(file_exists($base.'/quarantine/2026-05-03/a.csv'))->toBeTrue();
});

it('skips clobber when target file already exists in incoming', function (): void {
    $base = storage_path('app/competitors');
    File::put($base.'/incoming/a.csv', 'existing');
    File::put($base.'/quarantine/2026-05-03/a.csv', 'quarantined');

    $exit = Artisan::call('competitor:retry-quarantine', ['--all' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('moved=0');
    expect($output)->toContain('skipped=1');
    expect(file_get_contents($base.'/incoming/a.csv'))->toBe('existing');
    expect(file_exists($base.'/quarantine/2026-05-03/a.csv'))->toBeTrue();
});

it('errors if both --all and --file are given', function (): void {
    $exit = Artisan::call('competitor:retry-quarantine', ['--all' => true, '--file' => 'a.csv']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('mutually exclusive');
});
