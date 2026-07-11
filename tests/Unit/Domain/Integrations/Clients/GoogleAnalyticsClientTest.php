<?php

declare(strict_types=1);

use App\Domain\Integrations\Clients\GoogleAnalyticsClient;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Google\ApiCore\ApiException;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15a-01 — GoogleAnalyticsClient (READ-ONLY GA4 Data API)
|--------------------------------------------------------------------------
|
| Unit-tested WITHOUT network and WITHOUT the DB (mirrors WooClient/BitrixClient
| test discipline). The inner google/analytics-data SDK client
| (Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient) is declared
| `final`, so it cannot be Mockery-mocked directly. The clean seam is therefore
| the overridable public runReport() method + protected credentials() — both
| stubbed here via a Mockery partial mock. No BetaAnalyticsDataClient is ever
| constructed, so no service-account JSON is parsed and no HTTP is issued.
|
| testConnection() MUST NEVER throw: SDK exceptions are caught and returned as a
| failure IntegrationTestResult.
*/

/**
 * Partial mock of GoogleAnalyticsClient with credentials() + runReport() stubbed.
 * Passing a fake resolver keeps the DB completely out of the unit test.
 */
function makeGaClient(): GoogleAnalyticsClient
{
    $resolver = Mockery::mock(IntegrationCredentialResolver::class);

    /** @var GoogleAnalyticsClient $mock */
    $mock = Mockery::mock(GoogleAnalyticsClient::class, [$resolver])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    return $mock;
}

it('testConnection() returns ok when the GA4 runReport succeeds', function (): void {
    $client = makeGaClient();
    $client->shouldReceive('credentials')->andReturn([
        'service_account_json' => '{"type":"service_account"}',
        'property_id' => '123456789',
    ]);
    $client->shouldReceive('runReport')->once()->andReturn(new RunReportResponse);

    $result = $client->testConnection();

    expect($result)->toBeInstanceOf(IntegrationTestResult::class)
        ->and($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('testConnection() targets the correct property and a minimal sessions report', function (): void {
    $client = makeGaClient();
    $client->shouldReceive('credentials')->andReturn([
        'service_account_json' => '{"type":"service_account"}',
        'property_id' => '987654321',
    ]);

    $captured = null;
    $client->shouldReceive('runReport')
        ->once()
        ->with(Mockery::on(function ($request) use (&$captured) {
            $captured = $request;

            return $request instanceof RunReportRequest;
        }))
        ->andReturn(new RunReportResponse);

    $client->testConnection();

    expect($captured)->toBeInstanceOf(RunReportRequest::class)
        ->and($captured->getProperty())->toBe('properties/987654321')
        ->and($captured->getLimit())->toBe(1)
        ->and(iterator_to_array($captured->getMetrics()))->toHaveCount(1)
        ->and($captured->getMetrics()[0]->getName())->toBe('sessions')
        ->and(iterator_to_array($captured->getDateRanges()))->toHaveCount(1);
});

it('testConnection() returns a failure result (never throws) on an SDK ApiException', function (): void {
    $client = makeGaClient();
    $client->shouldReceive('credentials')->andReturn([
        'service_account_json' => '{"type":"service_account"}',
        'property_id' => '123456789',
    ]);
    $client->shouldReceive('runReport')
        ->once()
        ->andThrow(new ApiException('PERMISSION_DENIED: caller has no access', 7, 'PERMISSION_DENIED'));

    $result = $client->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('PERMISSION_DENIED')
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('testConnection() returns a failure result when credentials are not configured', function (): void {
    $client = makeGaClient();
    $client->shouldReceive('credentials')->andReturnNull();
    $client->shouldNotReceive('runReport');

    $result = $client->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('not configured');
});

it('constructor types $resolver as IntegrationCredentialResolver and $client as ?BetaAnalyticsDataClient', function (): void {
    $refl = new ReflectionMethod(GoogleAnalyticsClient::class, '__construct');
    $params = $refl->getParameters();

    expect($params)->toHaveCount(2);

    expect($params[0]->getType())->not->toBeNull()
        ->and($params[0]->getType()->getName())->toBe(IntegrationCredentialResolver::class);

    $innerType = $params[1]->getType();
    expect($innerType)->not->toBeNull()
        ->and($innerType->getName())->toBe(BetaAnalyticsDataClient::class)
        ->and($innerType->allowsNull())->toBeTrue()
        ->and($params[1]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[1]->getDefaultValue())->toBeNull();
});
