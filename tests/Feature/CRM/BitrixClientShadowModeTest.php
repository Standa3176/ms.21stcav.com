<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 02 Task 1 — BitrixClient shadow-mode gate
|--------------------------------------------------------------------------
|
| CRM_WRITE_ENABLED=false MUST route every write-path call (dealAdd, dealUpdate,
| contactAdd, contactUpdate, companyAdd, companyUpdate) into a sync_diffs row
| with provider='bitrix'. The SDK is never called. Read-path methods remain
| live so Filament schema discovery works in shadow mode.
*/

beforeEach(function (): void {
    config([
        'services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/',
        'services.bitrix.write_enabled' => false,
    ]);
});

/** A test-only BitrixClient that hard-fails if sdk() is invoked in shadow mode. */
function noSdkBitrixClient(): BitrixClient
{
    return new class(app(IntegrationLogger::class)) extends BitrixClient
    {
        private function sdk(): never
        {
            throw new RuntimeException('BitrixClient::sdk() must not be called in shadow mode');
        }
    };
}

it('writes sync_diffs row with provider=bitrix when CRM_WRITE_ENABLED=false on dealAdd', function (): void {
    $client = noSdkBitrixClient();

    $shadowId = $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 42, 'TITLE' => 'Order #42']);

    expect($shadowId)->toStartWith('SHADOW-');
    expect(SyncDiff::where('provider', 'bitrix')->where('woo_id', '42')->exists())->toBeTrue();
    expect(IntegrationEvent::where('endpoint', 'crm.deal.add')->where('channel', 'bitrix')->count())->toBe(1);
});

it('endpoint field never contains the webhook URL in shadow mode', function (): void {
    $client = noSdkBitrixClient();

    $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 99, 'TITLE' => 'Order #99']);

    $event = IntegrationEvent::latest('id')->first();
    expect($event->endpoint)->toBe('crm.deal.add');
    expect($event->endpoint)->not->toContain('https://');
    expect($event->endpoint)->not->toContain('example.bitrix24.com');
});

it('all 6 write methods honour shadow-mode', function (): void {
    $client = noSdkBitrixClient();

    $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 1, 'TITLE' => 't']);
    $client->dealUpdate('123', ['STAGE_ID' => 'NEW']);
    $client->contactAdd(['UF_CRM_WOO_CUSTOMER_ID' => 2, 'NAME' => 'Jane']);
    $client->contactUpdate('456', ['EMAIL' => [['VALUE' => 'j@example.com']]]);
    $client->companyAdd(['TITLE' => 'Acme Ltd']);
    $client->companyUpdate('789', ['TITLE' => 'Acme Ltd Renamed']);

    $endpoints = SyncDiff::where('provider', 'bitrix')->pluck('endpoint')->all();
    expect($endpoints)->toContain('crm.deal.add');
    expect($endpoints)->toContain('crm.deal.update');
    expect($endpoints)->toContain('crm.contact.add');
    expect($endpoints)->toContain('crm.contact.update');
    expect($endpoints)->toContain('crm.company.add');
    expect($endpoints)->toContain('crm.company.update');
    expect(count($endpoints))->toBe(6);
});

it('shadow-mode payload includes the shadow_id for later reconciliation', function (): void {
    $client = noSdkBitrixClient();

    $shadowId = $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 77, 'TITLE' => 'Order #77']);
    $row = SyncDiff::where('provider', 'bitrix')->latest('id')->first();

    expect($row->payload['__shadow_id'] ?? null)->toBe($shadowId);
});

it('returns distinct shadow IDs across consecutive calls', function (): void {
    $client = noSdkBitrixClient();

    $a = $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 1, 'TITLE' => 'a']);
    $b = $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 2, 'TITLE' => 'b']);

    expect($a)->not->toBe($b);
});
