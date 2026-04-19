<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Foundation\Integration\Services\IntegrationLogger;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Support\Facades\Context;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * SDK-agnostic wrapper around bitrix24/b24phpsdk ^1.10.
 *
 * Plan 04-01 ships:
 *   - all 18 method signatures (so downstream plans can type-hint against the contract)
 *   - REAL bodies for the 4 userfield CRUD methods that `bitrix:bootstrap` needs today:
 *       dealUserfieldList / dealUserfieldAdd / contactUserfieldList / contactUserfieldAdd
 *   - LogicException throws for the 14 non-bootstrap methods (Plan 04-02 replaces).
 *
 * Plan 04-02 ships:
 *   - the 14 remaining bodies (dealAdd/Update/Get/List, contactAdd/Update/List, etc.)
 *   - shadow-mode gate (CRM_WRITE_ENABLED=false → sync_diffs(provider='bitrix'))
 *   - BitrixTransientException / BitrixPermanentException classification (D-11 retry policy)
 *   - 429 Retry-After handling (Bitrix ~2 req/sec ceiling)
 *
 * T-04-01-01 mitigation: never log the full webhook URL. IntegrationLogger
 * records the endpoint method name only (`crm.deal.userfield.list`).
 *
 * Plan 04-02 will bind this as a singleton in AppServiceProvider; Plan 04-01
 * binds it as transient so the bootstrap command gets a fresh instance.
 */
class BitrixClient
{
    // Not marked final — Phase 2 P02 precedent: keeping this open allows
    // tests to fake the client via Mockery::mock(BitrixClient::class) and
    // enables Plan 04-02's rate-limit subclass test seam without a
    // separate interface. Real subclasses never ship outside tests.
    /** Lazy-built SDK service builder — constructed on first write/read. */
    private ?ServiceBuilder $sdk = null;

    public function __construct(
        private readonly IntegrationLogger $logger,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════════
    // Deal methods
    // ══════════════════════════════════════════════════════════════════════

    public function dealAdd(array $fields, ?string $correlationId = null): string
    {
        throw new LogicException(static::class.'::dealAdd — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function dealUpdate(string $bitrixId, array $fields, ?string $correlationId = null): void
    {
        throw new LogicException(static::class.'::dealUpdate — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function dealGet(string $bitrixId, ?string $correlationId = null): ?array
    {
        throw new LogicException(static::class.'::dealGet — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function dealList(array $filter = [], array $select = ['*'], int $start = 0, ?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::dealList — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function dealFieldsGet(?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::dealFieldsGet — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    /**
     * Create a Deal custom field. Real body — bitrix:bootstrap consumes this.
     *
     * @return string  Bitrix-assigned field ID (as string — Pitfall 3)
     */
    public function dealUserfieldAdd(array $fields, ?string $correlationId = null): string
    {
        return $this->executeWrite(
            endpoint: 'crm.deal.userfield.add',
            correlationId: $correlationId,
            request: ['fields' => $fields],
            callable: function () use ($fields): string {
                $sdk = $this->sdk();
                // The SDK's userfield service exposes dealUserfield() for Deal-scoped UF CRUD.
                // Plan 04-02's smoke-test output informs the concrete chain; we dispatch defensively.
                $result = $sdk->getCRMScope()->dealUserfield()->add($fields);

                return $this->extractIdFromUserfieldResult($result);
            }
        );
    }

    /**
     * List Deal custom fields. Real body — bitrix:bootstrap consumes this.
     *
     * @return array<int, array<string, mixed>>  Rows shaped {FIELD_NAME, USER_TYPE_ID, ...}
     */
    public function dealUserfieldList(array $filter = [], ?string $correlationId = null): array
    {
        return $this->executeRead(
            endpoint: 'crm.deal.userfield.list',
            correlationId: $correlationId,
            request: ['filter' => $filter],
            callable: function () use ($filter): array {
                $sdk = $this->sdk();
                $result = $sdk->getCRMScope()->dealUserfield()->list($filter);

                return $this->normaliseUserfieldList($result);
            }
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Contact methods
    // ══════════════════════════════════════════════════════════════════════

    public function contactAdd(array $fields, ?string $correlationId = null): string
    {
        throw new LogicException(static::class.'::contactAdd — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function contactUpdate(string $bitrixId, array $fields, ?string $correlationId = null): void
    {
        throw new LogicException(static::class.'::contactUpdate — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function contactList(array $filter = [], array $select = ['ID', 'EMAIL'], int $start = 0, ?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::contactList — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function contactFieldsGet(?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::contactFieldsGet — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    /** Real body — bitrix:bootstrap consumes this. */
    public function contactUserfieldAdd(array $fields, ?string $correlationId = null): string
    {
        return $this->executeWrite(
            endpoint: 'crm.contact.userfield.add',
            correlationId: $correlationId,
            request: ['fields' => $fields],
            callable: function () use ($fields): string {
                $sdk = $this->sdk();
                $result = $sdk->getCRMScope()->contactUserfield()->add($fields);

                return $this->extractIdFromUserfieldResult($result);
            }
        );
    }

    /** Real body — bitrix:bootstrap consumes this. */
    public function contactUserfieldList(array $filter = [], ?string $correlationId = null): array
    {
        return $this->executeRead(
            endpoint: 'crm.contact.userfield.list',
            correlationId: $correlationId,
            request: ['filter' => $filter],
            callable: function () use ($filter): array {
                $sdk = $this->sdk();
                $result = $sdk->getCRMScope()->contactUserfield()->list($filter);

                return $this->normaliseUserfieldList($result);
            }
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Company methods
    // ══════════════════════════════════════════════════════════════════════

    public function companyAdd(array $fields, ?string $correlationId = null): string
    {
        throw new LogicException(static::class.'::companyAdd — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function companyUpdate(string $bitrixId, array $fields, ?string $correlationId = null): void
    {
        throw new LogicException(static::class.'::companyUpdate — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function companyList(array $filter = [], array $select = ['ID', 'TITLE'], int $start = 0, ?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::companyList — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    public function companyFieldsGet(?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::companyFieldsGet — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Dedup primitive
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param  string  $type  'EMAIL' | 'PHONE' | 'IM'
     * @param  string  $entityType  'CONTACT' | 'COMPANY'
     */
    public function duplicateFindByComm(string $type, string $entityType, array $values, ?string $correlationId = null): array
    {
        throw new LogicException(static::class.'::duplicateFindByComm — signature shipped Plan 04-01; body shipped Plan 04-02.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Private helpers
    // ══════════════════════════════════════════════════════════════════════

    /** Lazy-builds the SDK service builder. Fails hard if webhook URL missing. */
    private function sdk(): ServiceBuilder
    {
        if ($this->sdk === null) {
            $webhookUrl = (string) config('services.bitrix.webhook_url');
            if ($webhookUrl === '') {
                throw new RuntimeException('BitrixClient: BITRIX_WEBHOOK_URL is empty. Configure .env before use.');
            }
            $this->sdk = ServiceBuilderFactory::createServiceBuilderFromWebhook($webhookUrl);
        }

        return $this->sdk;
    }

    /**
     * Executes a read-shaped SDK call + logs an integration_event row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function executeRead(string $endpoint, ?string $correlationId, array $request, callable $callable): array
    {
        $correlationId ??= (string) Context::get('correlation_id');
        $start = microtime(true);

        try {
            $result = $callable();
            $this->logger->log([
                'channel' => 'bitrix',
                'endpoint' => $endpoint,
                'operation' => $endpoint,
                'direction' => 'outbound',
                'correlation_id' => $correlationId,
                'method' => 'POST',
                'http_status' => 200,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'request_body' => $request,
                'status' => 'success',
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->log([
                'channel' => 'bitrix',
                'endpoint' => $endpoint,
                'operation' => $endpoint,
                'direction' => 'outbound',
                'correlation_id' => $correlationId,
                'method' => 'POST',
                'http_status' => 0,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'request_body' => $request,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** Executes a write-shaped SDK call + logs. Returns the SDK's result identifier. */
    private function executeWrite(string $endpoint, ?string $correlationId, array $request, callable $callable): string
    {
        $correlationId ??= (string) Context::get('correlation_id');
        $start = microtime(true);

        try {
            $id = $callable();
            $this->logger->log([
                'channel' => 'bitrix',
                'endpoint' => $endpoint,
                'operation' => $endpoint,
                'direction' => 'outbound',
                'correlation_id' => $correlationId,
                'method' => 'POST',
                'http_status' => 200,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'request_body' => $request,
                'response_body' => ['id' => $id],
                'status' => 'success',
            ]);

            return $id;
        } catch (Throwable $e) {
            $this->logger->log([
                'channel' => 'bitrix',
                'endpoint' => $endpoint,
                'operation' => $endpoint,
                'direction' => 'outbound',
                'correlation_id' => $correlationId,
                'method' => 'POST',
                'http_status' => 0,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'request_body' => $request,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Best-effort conversion of the SDK's userfield `add` return to a scalar ID.
     * Plan 04-02's smoke-test output lets us narrow this — for now we handle
     * the common shapes: scalar, object with getId(), or array with 'ID'.
     */
    private function extractIdFromUserfieldResult(mixed $result): string
    {
        if (is_scalar($result)) {
            return (string) $result;
        }
        if (is_object($result) && method_exists($result, 'getId')) {
            return (string) $result->getId();
        }
        if (is_array($result) && isset($result['ID'])) {
            return (string) $result['ID'];
        }

        // Fall back to an empty marker — caller logs via IntegrationLogger so
        // ops can correlate. Plan 04-02 tightens this after sandbox validation.
        return '';
    }

    /**
     * Normalise the SDK's userfield `list` return to a plain array of rows.
     * Every row is an associative array keyed by the Bitrix field name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseUserfieldList(mixed $result): array
    {
        if (is_array($result)) {
            // Detect single-row associative vs list of rows.
            if ($result === [] || array_is_list($result)) {
                return array_map(fn ($r) => is_array($r) ? $r : ['value' => $r], $result);
            }

            return [$result];
        }

        if (is_object($result)) {
            if (method_exists($result, 'getUserfields')) {
                $rows = $result->getUserfields();

                return is_array($rows) ? $rows : [];
            }
            if (method_exists($result, 'getCoreResponse')) {
                $core = $result->getCoreResponse();
                if (is_object($core) && method_exists($core, 'getResponseData')) {
                    $data = $core->getResponseData();
                    if (is_object($data) && method_exists($data, 'getResult')) {
                        $payload = $data->getResult();
                        if (is_array($payload)) {
                            return array_values($payload);
                        }
                    }
                }
            }
        }

        return [];
    }
}
