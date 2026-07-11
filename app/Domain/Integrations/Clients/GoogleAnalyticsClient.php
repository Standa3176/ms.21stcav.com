<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Clients;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use RuntimeException;
use Throwable;

/**
 * Phase 15 Plan 15a-01 — GA4 Data API read wrapper (READ-ONLY, shadow-safe).
 *
 * Mirrors the WooClient / EanSearchClient integration shape:
 *   - constructor-injected IntegrationCredentialResolver for credential lookup
 *     (DB row wins; services.google_analytics.* env fallback)
 *   - a public testConnection(): IntegrationTestResult wired into
 *     TestIntegrationAction so the operator gets an instant green/red answer
 *
 * The Data API is inherently read-only — runReport() cannot mutate anything.
 * This slice deliberately ships NO scheduled pull, NO snapshot table and NO
 * migration; those land in 15a-02. runReport() is a thin passthrough kept only
 * so 15a-02 has a seam to build the actual report ingestion on top of.
 *
 * REST transport: the inner client is always built with ['transport' => 'rest']
 * so the app never depends on the gRPC PECL extension.
 *
 * Testability: the inner SDK client (BetaAnalyticsDataClient) is declared
 * `final`, so it cannot be Mockery-mocked. The unit-test seam is therefore the
 * overridable public runReport() + protected credentials()/client() methods —
 * a Mockery partial mock stubs runReport() so tests never hit the network and
 * never construct a real client. A pre-built client MAY still be constructor-
 * injected (e.g. from a future service-provider binding) via the optional
 * second argument, mirroring WooClient's ?AutomatticClient seam.
 */
class GoogleAnalyticsClient
{
    private ?BetaAnalyticsDataClient $client;

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        ?BetaAnalyticsDataClient $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * Probe reachability + auth by running a minimal, read-only report
     * (metric `sessions`, yesterday→yesterday, limit 1). NEVER throws — SDK
     * exceptions (auth, permission, network) are caught and surfaced as a
     * failure IntegrationTestResult so the Filament "Test connection" button
     * always renders a clean red/green answer.
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        $creds = $this->credentials();
        if ($creds === null) {
            return IntegrationTestResult::failed('Google Analytics 4 credentials not configured.', 0);
        }

        try {
            $request = (new RunReportRequest)
                ->setProperty('properties/'.$creds['property_id'])
                ->setMetrics([new Metric(['name' => 'sessions'])])
                ->setDateRanges([new DateRange([
                    'start_date' => 'yesterday',
                    'end_date' => 'yesterday',
                ])])
                ->setLimit(1);

            $this->runReport($request);

            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::ok($latency);
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }

    /**
     * Thin READ-ONLY passthrough to the GA4 Data API runReport RPC. No pull /
     * persist logic lives here — 15a-02 builds the snapshot ingestion on top of
     * this seam. Public + non-final so the unit test can override it without
     * touching the `final` inner SDK client.
     */
    public function runReport(RunReportRequest $request): RunReportResponse
    {
        return $this->client()->runReport($request);
    }

    /**
     * `protected` so test doubles can stub it (avoids the DB in unit tests).
     * Returns null when either required field is absent/blank — testConnection()
     * degrades to a clean "not configured" failure instead of throwing.
     *
     * @return array{service_account_json:string, property_id:string}|null
     */
    protected function credentials(): ?array
    {
        try {
            $c = $this->resolver->for(IntegrationCredentialKind::GoogleAnalytics);
        } catch (IntegrationCredentialMissingException) {
            return null;
        }

        $json = trim((string) ($c['service_account_json'] ?? ''));
        $propertyId = trim((string) ($c['property_id'] ?? ''));
        if ($json === '' || $propertyId === '') {
            return null;
        }

        return ['service_account_json' => $json, 'property_id' => $propertyId];
    }

    /**
     * Lazily resolve the inner SDK client (constructor-injected instance wins;
     * otherwise built from credentials via makeClient()). `protected` for the
     * test seam.
     */
    protected function client(): BetaAnalyticsDataClient
    {
        if ($this->client instanceof BetaAnalyticsDataClient) {
            return $this->client;
        }

        $creds = $this->credentials();
        if ($creds === null) {
            throw new RuntimeException('Google Analytics 4 credentials not configured.');
        }

        return $this->client = $this->makeClient($creds);
    }

    /**
     * Build the GA4 Data API client from resolved credentials. REST transport
     * only — no gRPC PECL dependency. `protected` so tests can override the
     * construction of the `final` SDK client.
     *
     * @param  array{service_account_json:string, property_id:string}  $creds
     */
    protected function makeClient(array $creds): BetaAnalyticsDataClient
    {
        $decoded = json_decode($creds['service_account_json'], true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Google Analytics service_account_json is not valid JSON.');
        }

        return new BetaAnalyticsDataClient([
            'credentials' => $decoded,
            'transport' => 'rest',
        ]);
    }
}
