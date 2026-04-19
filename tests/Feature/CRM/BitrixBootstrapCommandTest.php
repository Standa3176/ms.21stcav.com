<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 01 Task 3 — bitrix:bootstrap idempotent custom-field creator
|--------------------------------------------------------------------------
|
| Covers CRM-01 acceptance: after a first run we must have 14 UF_CRM_WOO_*
| fields in Bitrix; a second run must report "already exists, skipping" for
| every one; missing auth (empty BITRIX_WEBHOOK_URL) must fail fast without
| touching the SDK; a userfield.list throw must abort BEFORE any .add call
| (Pitfall 6 mandate — never create without existence check).
*/

beforeEach(function (): void {
    config(['services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/']);
});

it('fails fast when BITRIX_WEBHOOK_URL is empty', function (): void {
    config(['services.bitrix.webhook_url' => null]);

    $this->artisan('bitrix:bootstrap')
        ->expectsOutputToContain('BITRIX_WEBHOOK_URL is empty')
        ->assertExitCode(1);
});

it('creates 14 fields on first run when Bitrix returns empty userfield lists', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealUserfieldList')->once()->andReturn([]);
    $client->shouldReceive('contactUserfieldList')->once()->andReturn([]);
    $client->shouldReceive('dealUserfieldAdd')->times(7)->andReturn('1');
    $client->shouldReceive('contactUserfieldAdd')->times(7)->andReturn('1');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:bootstrap')->assertExitCode(0);

    $activity = Activity::where('description', 'bitrix.bootstrap')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['created'])->toBe(14);
    expect($activity->properties['skipped'])->toBe(0);
    expect($activity->properties['dry_run'])->toBe(false);
});

it('is idempotent — skips every field that already exists', function (): void {
    $allDealFields = [
        ['FIELD_NAME' => 'UF_CRM_WOO_ORDER_ID'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_SOURCE'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_MEDIUM'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CAMPAIGN'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_TERM'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CONTENT'],
        ['FIELD_NAME' => 'UF_CRM_WOO_GA_CID'],
    ];
    $allContactFields = [
        ['FIELD_NAME' => 'UF_CRM_WOO_CUSTOMER_ID'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_SOURCE'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_MEDIUM'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CAMPAIGN'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_TERM'],
        ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CONTENT'],
        ['FIELD_NAME' => 'UF_CRM_WOO_GA_CID'],
    ];

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealUserfieldList')->once()->andReturn($allDealFields);
    $client->shouldReceive('contactUserfieldList')->once()->andReturn($allContactFields);
    $client->shouldNotReceive('dealUserfieldAdd');
    $client->shouldNotReceive('contactUserfieldAdd');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:bootstrap')->assertExitCode(0);

    $activity = Activity::where('description', 'bitrix.bootstrap')->latest('id')->first();
    expect($activity->properties['created'])->toBe(0);
    expect($activity->properties['skipped'])->toBe(14);
});

it('dry-run never calls add methods even when nothing exists', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealUserfieldList')->once()->andReturn([]);
    $client->shouldReceive('contactUserfieldList')->once()->andReturn([]);
    $client->shouldNotReceive('dealUserfieldAdd');
    $client->shouldNotReceive('contactUserfieldAdd');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:bootstrap --dry-run')
        ->expectsOutputToContain('would create UF_CRM_WOO_ORDER_ID')
        ->assertExitCode(0);

    $activity = Activity::where('description', 'bitrix.bootstrap')->latest('id')->first();
    expect($activity->properties['dry_run'])->toBe(true);
    expect($activity->properties['created'])->toBe(14); // dry-run counts what WOULD be created
});

it('fails hard when userfield.list throws (auth broken) — never calls add', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealUserfieldList')->once()->andThrow(new RuntimeException('Bitrix auth broken'));
    $client->shouldNotReceive('dealUserfieldAdd');
    $client->shouldNotReceive('contactUserfieldAdd');

    $this->app->instance(BitrixClient::class, $client);

    $this->artisan('bitrix:bootstrap')->assertExitCode(1);

    $activity = Activity::where('description', 'bitrix.bootstrap.failed')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['error_class'])->toBe('RuntimeException');
});
