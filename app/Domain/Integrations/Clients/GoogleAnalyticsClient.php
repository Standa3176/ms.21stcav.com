<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Clients;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use Carbon\CarbonInterface;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
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
     * Phase 15 Plan 15a-02 — READ-ONLY daily channel/campaign report pull.
     *
     * Grain per returned row = date × sessionDefaultChannelGroup ×
     * sessionSourceMedium × sessionCampaignName. The `date` dimension is
     * included so each row carries its own day (without it GA4 aggregates the
     * whole range into one row and the daily grain collapses).
     *
     * Returns [] when credentials are null (unconfigured) — this is the ONLY
     * silent no-op, which is what makes the scheduled `google:pull-ga4` safe to
     * ship before a GA4 service account exists. Any OTHER failure (e.g. a
     * property that rejects `keyEvents`) surfaces as the underlying SDK
     * exception so the calling command can log it — fetch never swallows those.
     *
     * Revenue is returned as a float in the property currency; conversion to
     * integer pennies happens in the command (the one money-mapping).
     *
     * @return list<array{
     *     date:string, channel_group:string, source_medium:string, campaign:string,
     *     sessions:int, key_events:int, transactions:int, purchase_revenue:float
     * }>
     */
    public function fetchChannelMetrics(CarbonInterface $from, CarbonInterface $to): array
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return [];
        }

        $request = (new RunReportRequest)
            ->setProperty('properties/'.$creds['property_id'])
            ->setDimensions([
                new Dimension(['name' => 'date']),
                new Dimension(['name' => 'sessionDefaultChannelGroup']),
                new Dimension(['name' => 'sessionSourceMedium']),
                new Dimension(['name' => 'sessionCampaignName']),
            ])
            ->setMetrics([
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'keyEvents']),
                new Metric(['name' => 'transactions']),
                new Metric(['name' => 'purchaseRevenue']),
            ])
            ->setDateRanges([new DateRange([
                'start_date' => $from->format('Y-m-d'),
                'end_date' => $to->format('Y-m-d'),
            ])]);

        $response = $this->runReport($request);

        $rows = [];
        foreach ($response->getRows() as $row) {
            $dims = iterator_to_array($row->getDimensionValues());
            $mets = iterator_to_array($row->getMetricValues());

            $rows[] = [
                'date' => $this->normalizeGaDate((string) $dims[0]->getValue()),
                'channel_group' => (string) $dims[1]->getValue(),
                'source_medium' => (string) $dims[2]->getValue(),
                'campaign' => (string) $dims[3]->getValue(),
                'sessions' => (int) $mets[0]->getValue(),
                'key_events' => (int) $mets[1]->getValue(),
                'transactions' => (int) $mets[2]->getValue(),
                'purchase_revenue' => (float) $mets[3]->getValue(),
            ];
        }

        return $rows;
    }

    /**
     * GA4's `date` dimension yields a compact YYYYMMDD string (e.g. "20260710").
     * Normalize to ISO Y-m-d so the caller can persist it into a date column
     * portably. Falls back to the raw value if it is not the expected shape.
     */
    protected function normalizeGaDate(string $raw): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Ymd', $raw);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('Y-m-d') : $raw;
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
