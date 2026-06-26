<?php

declare(strict_types=1);

use App\Domain\Sync\Commands\SyncSupplierFeedDatesCommand;
use App\Domain\Sync\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260626-q2b — suppliers:sync-feed-dates upsert logic
|--------------------------------------------------------------------------
|
| Drives the PURE upsertFeedRows(array $rows, bool $dryRun): array method
| directly with array input — no mysqli, no remote DB. The command's I/O
| (mysqli connect + SELECT id, name, remote_date, cron_run, status FROM feeds)
| is a thin shell around this method, so the data-mapping + operator-field
| preservation + zero-date guard + dry-run contract all live here.
|
| feed_remote_date is the supplier's REAL file date (feeds.remote_date), NOT
| the recorded_at MS-pull date. Nuvias (feeds.id=1) probed 2026-06-26:
| remote_date='2026-05-14 00:20:24', cron_run='2026-05-20 12:53:12', status=0.
*/

function syncFeedCommand(): SyncSupplierFeedDatesCommand
{
    return app(SyncSupplierFeedDatesCommand::class);
}

it('upserts the real feed_remote_date, cron_run and status from feed rows', function (): void {
    $rows = [
        ['id' => 1, 'name' => 'Nuvias', 'remote_date' => '2026-05-14 00:20:24', 'cron_run' => '2026-05-20 12:53:12', 'status' => 0],
        ['id' => 2, 'name' => 'Ingram', 'remote_date' => '2026-06-25 06:00:00', 'cron_run' => '2026-06-25 06:30:00', 'status' => 1],
    ];

    $result = syncFeedCommand()->upsertFeedRows($rows, false);

    expect($result['created'])->toBe(2);

    $nuvias = Supplier::where('supplier_id', '1')->firstOrFail();
    expect($nuvias->name)->toBe('Nuvias');
    expect($nuvias->feed_remote_date->format('Y-m-d H:i:s'))->toBe('2026-05-14 00:20:24');
    expect($nuvias->feed_cron_run->format('Y-m-d H:i:s'))->toBe('2026-05-20 12:53:12');
    expect($nuvias->feed_status)->toBe(0);

    $ingram = Supplier::where('supplier_id', '2')->firstOrFail();
    expect($ingram->feed_remote_date->format('Y-m-d'))->toBe('2026-06-25');
    expect($ingram->feed_status)->toBe(1);
});

it('parses zero-dates and nulls to null without throwing', function (): void {
    $rows = [
        ['id' => 3, 'name' => 'Zerodate', 'remote_date' => '0000-00-00 00:00:00', 'cron_run' => null, 'status' => 1],
        ['id' => 4, 'name' => 'Nulldate', 'remote_date' => null, 'cron_run' => '', 'status' => null],
    ];

    $result = syncFeedCommand()->upsertFeedRows($rows, false);

    expect($result['created'])->toBe(2);

    $zero = Supplier::where('supplier_id', '3')->firstOrFail();
    expect($zero->feed_remote_date)->toBeNull();
    expect($zero->feed_cron_run)->toBeNull();
    expect($zero->feed_status)->toBe(1);

    $null = Supplier::where('supplier_id', '4')->firstOrFail();
    expect($null->feed_remote_date)->toBeNull();
    expect($null->feed_cron_run)->toBeNull();
    expect($null->feed_status)->toBeNull();
});

it('preserves operator-owned fields across an upsert (only writes feed metadata + name)', function (): void {
    Supplier::create([
        'supplier_id' => '1',
        'name' => 'Old Name',
        'is_active' => false,
        'stale_after_days' => 21,
        'notes' => 'paused',
    ]);

    $rows = [
        ['id' => 1, 'name' => 'Nuvias', 'remote_date' => '2026-05-14 00:20:24', 'cron_run' => '2026-05-20 12:53:12', 'status' => 0],
    ];

    $result = syncFeedCommand()->upsertFeedRows($rows, false);

    expect($result['updated'])->toBe(1);

    $nuvias = Supplier::where('supplier_id', '1')->firstOrFail();
    // Operator fields untouched.
    expect($nuvias->is_active)->toBeFalse();
    expect($nuvias->stale_after_days)->toBe(21);
    expect($nuvias->notes)->toBe('paused');
    // Feed metadata + name written.
    expect($nuvias->name)->toBe('Nuvias');
    expect($nuvias->feed_remote_date->format('Y-m-d H:i:s'))->toBe('2026-05-14 00:20:24');
    expect($nuvias->feed_status)->toBe(0);
});

it('writes nothing on dry-run', function (): void {
    $rows = [
        ['id' => 1, 'name' => 'Nuvias', 'remote_date' => '2026-05-14 00:20:24', 'cron_run' => '2026-05-20 12:53:12', 'status' => 0],
    ];

    $result = syncFeedCommand()->upsertFeedRows($rows, true);

    expect(Supplier::count())->toBe(0);
    // Dry-run still reports what WOULD happen.
    expect($result['created'])->toBe(1);
});

it('skips rows with an empty id', function (): void {
    $rows = [
        ['id' => '', 'name' => 'NoId', 'remote_date' => '2026-05-14 00:20:24', 'cron_run' => null, 'status' => 1],
        ['id' => null, 'name' => 'AlsoNoId', 'remote_date' => '2026-05-14 00:20:24', 'cron_run' => null, 'status' => 1],
    ];

    $result = syncFeedCommand()->upsertFeedRows($rows, false);

    expect($result['skipped'])->toBe(2);
    expect(Supplier::count())->toBe(0);
});

it('parseFeedDate maps zero/empty/null to null and valid dates to Carbon', function (): void {
    $cmd = syncFeedCommand();

    expect($cmd->parseFeedDate(null))->toBeNull();
    expect($cmd->parseFeedDate(''))->toBeNull();
    expect($cmd->parseFeedDate('0000-00-00 00:00:00'))->toBeNull();
    expect($cmd->parseFeedDate('2026-05-14 00:20:24')?->format('Y-m-d H:i:s'))->toBe('2026-05-14 00:20:24');
});
