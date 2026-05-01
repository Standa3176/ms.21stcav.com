<?php

declare(strict_types=1);

use App\Domain\CRM\Console\Commands\BitrixQuotesBootstrapCommand;
use App\Domain\CRM\Services\BitrixClient;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 04 — bitrix:quotes-bootstrap pre-flight check (Pitfall 2)
|--------------------------------------------------------------------------
|
| Covers QUOT-08 cutover gate prerequisites:
|   - SUCCESS: dealtype QUOTE exists in mocked dealcategory.list response
|     → idempotent UF_CRM_WOO_QUOTE_ID creation → cache marker set → exit 0
|   - WARNING/FAIL: dealtype missing → operator runbook printed → exit 1
|     (cache marker NOT set; QUOTE_BITRIX_PUSH_ENABLED stays at-rest false)
|   - IDEMPOTENT: existing UF_CRM_WOO_QUOTE_ID userfield → skip (re-run safe)
|   - PROBE MODE: --probe never calls userfield.add even when missing
|
| Skip-on-MySQL-offline parity with Phase 4 BitrixBootstrapCommandTest (the
| Activity::where lookup hits the activity_log table at the end of every run).
*/

function skipIfMySqlOfflineQuotesBootstrap(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineQuotesBootstrap();
    config(['services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/']);
    config(['quote.bitrix_deal_type_id' => 'QUOTE']);
    Cache::forget(BitrixQuotesBootstrapCommand::CACHE_KEY_VERIFIED);
});

it('fails fast when BITRIX_WEBHOOK_URL is empty', function (): void {
    skipIfMySqlOfflineQuotesBootstrap();
    config(['services.bitrix.webhook_url' => null]);

    $this->artisan('bitrix:quotes-bootstrap')
        ->expectsOutputToContain('BITRIX_WEBHOOK_URL is empty')
        ->assertExitCode(1);
});

it('exits 0 with success message when dealtype QUOTE exists in mocked dealcategory.list response', function (): void {
    skipIfMySqlOfflineQuotesBootstrap();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealCategoryList')->once()->andReturn([
        ['ID' => '0', 'NAME' => 'General', 'SORT' => 0, 'IS_LOCKED' => false],
        ['ID' => '5', 'NAME' => 'QUOTE', 'SORT' => 10, 'IS_LOCKED' => false],
    ]);
    $client->shouldReceive('dealUserfieldList')->once()->andReturn([]);
    $client->shouldReceive('dealUserfieldAdd')->once()->andReturn('UF_ID_123');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:quotes-bootstrap')
        ->expectsOutputToContain('PASS')
        ->assertExitCode(0);

    expect(Cache::get(BitrixQuotesBootstrapCommand::CACHE_KEY_VERIFIED))->toBeTrue();

    $activity = Activity::where('description', 'bitrix.quotes_bootstrap')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['userfield_created'])->toBe(true);
});

it('exits 1 with operator runbook when dealtype QUOTE missing from dealcategory list', function (): void {
    skipIfMySqlOfflineQuotesBootstrap();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealCategoryList')->once()->andReturn([
        ['ID' => '0', 'NAME' => 'General', 'SORT' => 0, 'IS_LOCKED' => false],
        ['ID' => '1', 'NAME' => 'Sales Pipeline', 'SORT' => 10, 'IS_LOCKED' => false],
    ]);
    // userfield.add MUST NOT be called when dealtype is missing.
    $client->shouldNotReceive('dealUserfieldAdd');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:quotes-bootstrap')
        ->expectsOutputToContain('Operator runbook')
        ->assertExitCode(1);

    // Cache marker must NOT be set on failure.
    expect(Cache::has(BitrixQuotesBootstrapCommand::CACHE_KEY_VERIFIED))->toBeFalse();

    $activity = Activity::where('description', 'bitrix.quotes_bootstrap.dealtype_missing')->latest('id')->first();
    expect($activity)->not->toBeNull();
});

it('idempotently skips UF_CRM_WOO_QUOTE_ID creation when field already exists', function (): void {
    skipIfMySqlOfflineQuotesBootstrap();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealCategoryList')->once()->andReturn([
        ['ID' => '5', 'NAME' => 'QUOTE', 'SORT' => 10, 'IS_LOCKED' => false],
    ]);
    $client->shouldReceive('dealUserfieldList')->once()->andReturn([
        ['FIELD_NAME' => 'UF_CRM_WOO_ORDER_ID', 'ID' => '100'],
        ['FIELD_NAME' => 'UF_CRM_WOO_QUOTE_ID', 'ID' => '101'],
    ]);
    // userfield.add MUST NOT be called when the field already exists.
    $client->shouldNotReceive('dealUserfieldAdd');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:quotes-bootstrap')
        ->expectsOutputToContain('already exists')
        ->expectsOutputToContain('PASS')
        ->assertExitCode(0);

    expect(Cache::get(BitrixQuotesBootstrapCommand::CACHE_KEY_VERIFIED))->toBeTrue();

    $activity = Activity::where('description', 'bitrix.quotes_bootstrap')->latest('id')->first();
    expect($activity->properties['userfield_created'])->toBe(false);
});

it('probe mode never calls userfield.add even when field is missing', function (): void {
    skipIfMySqlOfflineQuotesBootstrap();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealCategoryList')->once()->andReturn([
        ['ID' => '5', 'NAME' => 'QUOTE', 'SORT' => 10, 'IS_LOCKED' => false],
    ]);
    $client->shouldReceive('dealUserfieldList')->once()->andReturn([]);
    $client->shouldNotReceive('dealUserfieldAdd');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:quotes-bootstrap', ['--probe' => true])
        ->expectsOutputToContain('would create')
        ->assertExitCode(0);

    // Probe mode does NOT set the verified cache marker — operator must run
    // a real (non-probe) bootstrap to flip the gate.
    expect(Cache::has(BitrixQuotesBootstrapCommand::CACHE_KEY_VERIFIED))->toBeFalse();
});
