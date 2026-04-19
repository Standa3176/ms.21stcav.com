<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 02 Task 2 — bitrix:schema:refresh CLI
|--------------------------------------------------------------------------
|
| Command invalidates + refetches all 3 entity schemas and writes an audit row
| on success. Returns non-zero exit code on any fetch failure (partial success
| is treated as failure from ops' perspective).
*/

beforeEach(function (): void {
    \Illuminate\Support\Facades\Cache::flush();
});

it('invalidates and refetches all three schemas', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn(['D1' => [], 'D2' => []]);
    $client->shouldReceive('contactFieldsGet')->once()->andReturn(['C1' => []]);
    $client->shouldReceive('companyFieldsGet')->once()->andReturn(['CO1' => [], 'CO2' => [], 'CO3' => []]);

    $this->app->instance(BitrixClient::class, $client);
    $this->app->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);

    $this->artisan('bitrix:schema:refresh')
        ->expectsOutputToContain('cache invalidated')
        ->expectsOutputToContain('deal -> 2 fields')
        ->expectsOutputToContain('contact -> 1 fields')
        ->expectsOutputToContain('company -> 3 fields')
        ->assertExitCode(0);

    $activity = Activity::where('description', 'bitrix.schema.refreshed')->latest('id')->first();
    expect($activity)->not->toBeNull();
    $entities = $activity->properties['entities'];
    expect($entities)->toContain('deal');
    expect($entities)->toContain('contact');
    expect($entities)->toContain('company');
    $counts = $activity->properties['counts'];
    expect($counts['deal'])->toBe(2);
    expect($counts['contact'])->toBe(1);
    expect($counts['company'])->toBe(3);
});

it('returns non-zero on partial fetch failure and writes no audit row', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn(['D1' => []]);
    $client->shouldReceive('contactFieldsGet')
        ->once()
        ->andThrow(new \App\Domain\CRM\Exceptions\BitrixPermanentException('auth broken'));
    // Company fetch should NOT be attempted after contact fails
    $client->shouldNotReceive('companyFieldsGet');

    $this->app->instance(BitrixClient::class, $client);
    $this->app->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);

    $this->artisan('bitrix:schema:refresh')->assertExitCode(1);

    // No audit row should be written on partial failure
    $activity = Activity::where('description', 'bitrix.schema.refreshed')->latest('id')->first();
    expect($activity)->toBeNull();
});
