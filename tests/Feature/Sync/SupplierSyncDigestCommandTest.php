<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Mail\SupplierSyncDigestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Stock-updater parity glue — reports:supplier-sync-digest
|--------------------------------------------------------------------------
|
| Daily digest replacement for the legacy plugin's send_results_and_cleanup()
| 4-CSV email. Sends to AlertRecipients where receives_sync_reports=true
| AND is_active=true. Empty-recipient state exits 0 + logs warning so
| Horizon doesn't alert on what is a configuration choice.
*/

it('registers reports:supplier-sync-digest as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('reports:supplier-sync-digest');
});

it('sends only to opted-in active AlertRecipients', function (): void {
    Mail::fake();

    AlertRecipient::create([
        'email' => 'sync-in-1@ops.test',
        'name' => 'Sync In 1',
        'is_active' => true,
        'receives_sync_reports' => true,
    ]);
    AlertRecipient::create([
        'email' => 'sync-in-2@ops.test',
        'name' => 'Sync In 2',
        'is_active' => true,
        'receives_sync_reports' => true,
    ]);
    AlertRecipient::create([
        'email' => 'sync-out@ops.test',
        'name' => 'Sync Out',
        'is_active' => true,
        'receives_sync_reports' => false,
    ]);
    AlertRecipient::create([
        'email' => 'sync-inactive@ops.test',
        'name' => 'Sync Inactive',
        'is_active' => false,
        'receives_sync_reports' => true,
    ]);

    Artisan::call('reports:supplier-sync-digest');

    Mail::assertSent(SupplierSyncDigestMail::class, 2);
    Mail::assertSent(SupplierSyncDigestMail::class, fn ($m) => $m->hasTo('sync-in-1@ops.test'));
    Mail::assertSent(SupplierSyncDigestMail::class, fn ($m) => $m->hasTo('sync-in-2@ops.test'));
    Mail::assertNotSent(SupplierSyncDigestMail::class, fn ($m) => $m->hasTo('sync-out@ops.test'));
    Mail::assertNotSent(SupplierSyncDigestMail::class, fn ($m) => $m->hasTo('sync-inactive@ops.test'));
});

it('exits 0 + sends nothing when no recipients are opted in', function (): void {
    Mail::fake();

    $exit = Artisan::call('reports:supplier-sync-digest');

    expect($exit)->toBe(0);
    Mail::assertNothingSent();
});
