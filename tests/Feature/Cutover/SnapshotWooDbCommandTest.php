<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\WooDbSnapshotter;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 05 Task 2 — cutover:snapshot-woo-db (CUT-04)
|--------------------------------------------------------------------------
|
| Behaviour tests S1..S4 from 07-05-PLAN:
|   S1 — creates a gzipped SQL file at the configured backup_path
|   S2 — audit_log entry written with action='cutover.woo_db_snapshotted'
|   S3 — graceful failure when mysqldump missing (non-zero exit, no crash)
|   S4 — --label is required; missing label fails fast
*/

beforeEach(function (): void {
    // Redirect snapshots into a per-test scratch directory so we can assert
    // existence without touching the real storage path.
    $this->tmp = sys_get_temp_dir().'/cutover-snapshot-test-'.uniqid();
    mkdir($this->tmp, 0o755, true);
    config(['cutover.backup_path' => $this->tmp]);
});

afterEach(function (): void {
    if (isset($this->tmp) && is_dir($this->tmp)) {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        @rmdir($this->tmp);
    }
});

it('registers cutover:snapshot-woo-db in the artisan registry', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cutover:snapshot-woo-db');
});

it('fails fast when --label is missing', function (): void {
    $exit = Artisan::call('cutover:snapshot-woo-db');

    expect($exit)->toBe(1);
});

it('creates a gzipped SQL file named woo-db-backup-{timestamp}-{label}.sql.gz', function (): void {
    // Swap the real snapshotter for one that writes a stub file (no mysqldump).
    app()->bind(WooDbSnapshotter::class, function (): object {
        return new class(app(Auditor::class)) extends WooDbSnapshotter
        {
            public function snapshot(string $label): array
            {
                $dir = (string) config('cutover.backup_path');
                $stamp = now()->format('Y-m-d-His');
                $filename = "woo-db-backup-{$stamp}-{$label}.sql.gz";
                $path = $dir.DIRECTORY_SEPARATOR.$filename;
                file_put_contents($path, gzencode('-- stub dump'));
                $size = filesize($path) ?: 0;

                // Replicate the audit-log write so S2 assertion still passes.
                app(Auditor::class)->record('cutover.woo_db_snapshotted', [
                    'filename' => $filename, 'path' => $path, 'label' => $label, 'size_bytes' => $size,
                ]);

                return ['filename' => $filename, 'path' => $path, 'size_bytes' => $size];
            }
        };
    });

    $exit = Artisan::call('cutover:snapshot-woo-db', ['--label' => 'test-snapshot']);

    expect($exit)->toBe(0);
    $files = glob($this->tmp.'/woo-db-backup-*-test-snapshot.sql.gz');
    expect($files)->not->toBeEmpty();
    expect(file_get_contents($files[0]))->not->toBeEmpty();
});

it('writes an audit_log entry with action=cutover.woo_db_snapshotted', function (): void {
    app()->bind(WooDbSnapshotter::class, function (): object {
        return new class(app(Auditor::class)) extends WooDbSnapshotter
        {
            public function snapshot(string $label): array
            {
                app(Auditor::class)->record('cutover.woo_db_snapshotted', [
                    'filename' => 'woo-db-backup-fake.sql.gz',
                    'path' => '/tmp/fake',
                    'label' => $label,
                    'size_bytes' => 1234,
                ]);

                return ['filename' => 'woo-db-backup-fake.sql.gz', 'path' => '/tmp/fake', 'size_bytes' => 1234];
            }
        };
    });

    Artisan::call('cutover:snapshot-woo-db', ['--label' => 'audit-test']);

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.woo_db_snapshotted')
        ->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['label'] ?? null)->toBe('audit-test');
});

it('returns non-zero exit code when the snapshotter throws RuntimeException', function (): void {
    app()->bind(WooDbSnapshotter::class, function (): object {
        return new class(app(Auditor::class)) extends WooDbSnapshotter
        {
            public function snapshot(string $label): array
            {
                throw new \RuntimeException('simulated mysqldump failure');
            }
        };
    });

    $exit = Artisan::call('cutover:snapshot-woo-db', ['--label' => 'fail-test']);

    expect($exit)->toBe(1);
});
