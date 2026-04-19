<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Foundation\Integration\Services\IntegrationLogger;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Phase 4 Plan 01 Task 1 — sandbox-validate the Bitrix SDK's API surface.
 *
 * Runs 7 probes against the configured tenant so Plan 04-02 can lock the
 * BitrixClient wrapper interface with evidence rather than hope. Probe list:
 *   1. crm.deal.userfield.list — can we enumerate Deal custom fields?
 *   2. crm.deal.fields — does dealFieldsGet return the expected shape?
 *   3. crm.contact.fields — ditto for Contact
 *   4. crm.company.fields — ditto for Company
 *   5. crm.deal.list with UF_CRM_WOO_ORDER_ID filter — Pitfall 6 dedup path
 *   6. crm.duplicate.findbycomm EMAIL — Pitfall 2 (multi-value field filter)
 *   7. crm.duplicate.findbycomm PHONE — ditto with E.164 number
 *
 * Two-layer gate protects production:
 *   a. BITRIX_SMOKE_TEST_ALLOWED=false default — must be opted-in via env
 *   b. BITRIX_WEBHOOK_URL must be configured (command refuses to SDK-init otherwise)
 *
 * T-04-01-01 mitigation: the webhook URL is NEVER echoed to stdout — only the
 * SDK endpoint method (`crm.deal.userfield.list`) is logged.
 *
 * Probe indirection: the concrete SDK call is resolved via a container binding
 * under PROBE_RUNNER_KEY so tests can swap in a fake runner without hitting
 * Bitrix. Without an override, the command builds a ServiceBuilder on demand
 * and dispatches the probe against it.
 */
final class BitrixSmokeTestCommand extends BaseCommand
{
    /** Container binding key for a probe runner override (test seam). */
    public const PROBE_RUNNER_KEY = 'crm.bitrix.smoke_probe_runner';

    protected $signature = 'bitrix:smoke-test {--skip-write : Skip probes that create records}';

    protected $description = 'Probe the configured Bitrix tenant via the official SDK to confirm method signatures. Requires BITRIX_SMOKE_TEST_ALLOWED=true.';

    public function __construct(private readonly IntegrationLogger $logger)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        if (! config('services.bitrix.smoke_test_allowed')) {
            $this->error('BitrixSmokeTestCommand: blocked — set BITRIX_SMOKE_TEST_ALLOWED=true to run. This command creates/reads real Bitrix records.');

            return self::FAILURE;
        }

        if (empty(config('services.bitrix.webhook_url'))) {
            $this->error('BitrixSmokeTestCommand: BITRIX_WEBHOOK_URL is empty. Configure .env before running probes.');

            return self::FAILURE;
        }

        $correlationId = (string) Context::get('correlation_id');

        $probes = [
            ['dealUserfieldList probe',         'crm.deal.userfield.list',   ['filter' => ['FIELD_NAME' => 'UF_CRM_WOO_ORDER_ID']]],
            ['dealFieldsGet probe',             'crm.deal.fields',           []],
            ['contactFieldsGet probe',          'crm.contact.fields',        []],
            ['companyFieldsGet probe',          'crm.company.fields',        []],
            ['dealList UF filter probe',        'crm.deal.list',             ['filter' => ['UF_CRM_WOO_ORDER_ID' => 999999999], 'select' => ['ID']]],
            ['duplicateFindByComm EMAIL probe', 'crm.duplicate.findbycomm',  ['type' => 'EMAIL', 'entity_type' => 'CONTACT', 'values' => ['smoke-test-noop@example.invalid']]],
            ['duplicateFindByComm PHONE probe', 'crm.duplicate.findbycomm',  ['type' => 'PHONE', 'entity_type' => 'CONTACT', 'values' => ['+000000000000']]],
        ];

        $rows = [];
        $anyFailure = false;

        foreach ($probes as [$label, $sdkMethod, $args]) {
            $start = microtime(true);
            try {
                $result = $this->probe($sdkMethod, $args, $correlationId);
                $latencyMs = (int) ((microtime(true) - $start) * 1000);
                $rows[] = [
                    'probe' => $label,
                    'method' => $sdkMethod,
                    'status' => 'ok',
                    'latency_ms' => $latencyMs,
                    'notes' => isset($result['count']) ? 'count='.$result['count'] : 'ok',
                ];
            } catch (Throwable $e) {
                $latencyMs = (int) ((microtime(true) - $start) * 1000);
                $anyFailure = true;
                $rows[] = [
                    'probe' => $label,
                    'method' => $sdkMethod,
                    'status' => 'error',
                    'latency_ms' => $latencyMs,
                    'notes' => $e::class.': '.$e->getMessage(),
                ];

                $this->logger->log([
                    'channel' => 'bitrix',
                    'endpoint' => $sdkMethod,
                    'operation' => 'smoke-test:'.$sdkMethod,
                    'direction' => 'outbound',
                    'correlation_id' => $correlationId,
                    'method' => 'POST',
                    'http_status' => 0,
                    'error_message' => $e->getMessage(),
                    'status' => 'failed',
                ]);
            }
        }

        $this->table(['Probe', 'Method', 'Status', 'Latency ms', 'Notes'], $rows);

        return $anyFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Dispatch a single probe. When a test has registered a fake runner under
     * PROBE_RUNNER_KEY we delegate there so CI never hits Bitrix. Otherwise
     * we build a live ServiceBuilder against the configured webhook URL.
     *
     * @return array{ok?: bool, count?: int}
     */
    private function probe(string $sdkMethod, array $args, string $correlationId): array
    {
        if ($this->laravel->bound(self::PROBE_RUNNER_KEY)) {
            /** @var callable $runner */
            $runner = $this->laravel->make(self::PROBE_RUNNER_KEY);

            return $runner($sdkMethod, $args, $correlationId);
        }

        // Real path — Plan 04-02 expands this into BitrixClient's permanent home.
        $serviceBuilder = ServiceBuilderFactory::createServiceBuilderFromWebhook(
            (string) config('services.bitrix.webhook_url')
        );

        $result = match ($sdkMethod) {
            'crm.deal.userfield.list' => $serviceBuilder->getCRMScope()->dealUserfield()->list($args['filter'] ?? []),
            'crm.deal.fields'          => $serviceBuilder->getCRMScope()->deal()->fields(),
            'crm.contact.fields'       => $serviceBuilder->getCRMScope()->contact()->fields(),
            'crm.company.fields'       => $serviceBuilder->getCRMScope()->company()->fields(),
            'crm.deal.list'            => $serviceBuilder->getCRMScope()->deal()->list([], $args['filter'] ?? [], $args['select'] ?? ['*']),
            'crm.duplicate.findbycomm' => $serviceBuilder->getCRMScope()->duplicate()->findByComm($args['type'], $args['entity_type'], $args['values']),
            default                    => throw new \LogicException("Unknown smoke-test probe: {$sdkMethod}"),
        };

        // Log the successful probe so ops can audit what ran.
        $this->logger->log([
            'channel' => 'bitrix',
            'endpoint' => $sdkMethod,
            'operation' => 'smoke-test:'.$sdkMethod,
            'direction' => 'outbound',
            'correlation_id' => $correlationId,
            'method' => 'POST',
            'http_status' => 200,
            'status' => 'success',
        ]);

        return ['ok' => true, 'count' => is_countable($result) ? count((array) $result) : 0];
    }
}
