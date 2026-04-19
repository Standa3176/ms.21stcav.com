<?php

declare(strict_types=1);

use App\Domain\CRM\Events\BitrixDealPushed;
use App\Domain\CRM\Jobs\UpdateDealStageJob;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Services\BitrixClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 2 — UpdateDealStageJob (D-09 narrow stage/opportunity patch)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config(['services.bitrix.write_enabled' => true]);
    $this->seed(\Database\Seeders\Phase4\CrmStatusMappingSeeder::class);
});

it('looks up bitrix_entity_map, calls dealUpdate with mapped STAGE_ID + OPPORTUNITY, updates map', function (): void {
    Event::fake([BitrixDealPushed::class]);

    CrmStatusMapping::where('woo_status', 'processing')->update(['bitrix_stage_id' => 'C5:PREP']);
    BitrixEntityMap::factory()->dealFor(101, 'D101')->create(['last_status_snapshot' => 'pending']);

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealUpdate')
        ->once()
        ->with('D101', ['STAGE_ID' => 'C5:PREP', 'OPPORTUNITY' => 250.5], Mockery::any());

    (new UpdateDealStageJob(101, 'processing', 'pending', 250.5, 'cid-abc'))->handle($client);

    $map = BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 101)->first();
    expect($map->last_status_snapshot)->toBe('processing');
    expect($map->last_correlation_id)->toBe('cid-abc');

    Event::assertDispatched(BitrixDealPushed::class, fn ($e) => $e->wooOrderId === 101 && $e->mode === 'stage_changed');
});

it('logs warning and skips dealUpdate when no CrmStatusMapping matches the Woo status', function (): void {
    Log::spy();

    BitrixEntityMap::factory()->dealFor(102, 'D102')->create(['last_status_snapshot' => 'pending']);
    // Deliberately do NOT set bitrix_stage_id on any row.

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldNotReceive('dealUpdate');

    (new UpdateDealStageJob(102, 'pending', 'pending', 100.0, 'cid-x'))->handle($client);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('logs warning and short-circuits when bitrix_entity_map row is missing', function (): void {
    Log::spy();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldNotReceive('dealUpdate');

    (new UpdateDealStageJob(999, 'processing', 'pending', 100.0, null))->handle($client);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});
