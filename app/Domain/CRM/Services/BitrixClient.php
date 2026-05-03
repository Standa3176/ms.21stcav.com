<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Exceptions\BitrixTransientException;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Integration\Services\IntegrationLogger;
use Bitrix24\SDK\Core\Exceptions\BaseException;
use Bitrix24\SDK\Core\Exceptions\TransportException;
use Bitrix24\SDK\Services\CRM\Duplicates\Service\EntityType as DuplicateEntityType;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * SDK-agnostic wrapper around bitrix24/b24phpsdk ^1.10.
 *
 * Plan 04-02 responsibilities (on top of Plan 04-01 skeleton):
 *   - Bodies for the 14 remaining methods (deal/contact/company Add/Update/Get/List/FieldsGet + duplicateFindByComm).
 *   - Shadow-mode gate: every *Add / *Update checks `services.bitrix.write_enabled`
 *     FIRST; when false, writes a row to `sync_diffs` (provider='bitrix') and
 *     returns a sentinel `SHADOW-{uuid}`. Read paths always hit the SDK.
 *   - Exception classification (D-11 retry semantics): SDK TransportException
 *     (network + 5xx + 429) → BitrixTransientException; any other SDK throwable
 *     (4xx validation, auth, not-found) → BitrixPermanentException.
 *   - ~2 req/sec throttle inside withSdk() — the SDK 1.10.x ServiceBuilderFactory
 *     does NOT accept a custom HTTP client, so we enforce the ceiling at the
 *     wrapper layer via usleep. BitrixRateLimitMiddleware stays shipped + tested
 *     for a future SDK version that exposes HTTP-client injection.
 *   - T-04-02-01 mitigation: webhook URL is NEVER logged. The SDK's exception
 *     message can echo the URL; we redact before persisting + rethrowing.
 *
 * Not marked final — Phase 2 WooClient precedent: open for Mockery mocks in
 * tests. Real subclasses never ship outside the test suite.
 */
class BitrixClient
{
    /**
     * Minimum gap (microseconds) between consecutive SDK calls within the SAME
     * PHP process — 500ms guarantees the 2 req/sec ceiling. Aggregate across
     * multiple Horizon workers is accepted per T-04-02-04 (documented).
     */
    private const SDK_THROTTLE_USEC = 500_000;

    /** microtime(true) of the last SDK call this instance made. */
    private float $lastCallAt = 0.0;

    /** Lazy-built SDK service builder — constructed on first write/read. */
    private ?ServiceBuilder $sdk = null;

    public function __construct(
        private readonly IntegrationLogger $logger,
        private readonly IntegrationCredentialResolver $resolver,
    ) {
    }

    /**
     * Phase 09.1 — webhook URL sourced from IntegrationCredentialResolver
     * (DB row wins; .env fallback). Replaces direct config('services.bitrix.webhook_url')
     * reads. Resolver is internally cached for 60s per kind.
     */
    private function webhookUrl(): string
    {
        try {
            return (string) $this->resolver->for(IntegrationCredentialKind::BitrixWebhook)['webhook_url'];
        } catch (\Throwable) {
            return '';
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Deal methods
    // ══════════════════════════════════════════════════════════════════════

    public function dealAdd(array $fields, ?string $correlationId = null): string
    {
        $shadow = $this->shadowIfDisabled('crm.deal.add', $fields['UF_CRM_WOO_ORDER_ID'] ?? null, $fields, $correlationId);
        if ($shadow !== null) {
            return $shadow;
        }

        return (string) $this->withSdk(
            'crm.deal.add',
            ['fields' => $fields],
            fn () => $this->sdk()->getCRMScope()->deal()->add($fields)->getId(),
            $correlationId,
        );
    }

    public function dealUpdate(string $bitrixId, array $fields, ?string $correlationId = null): void
    {
        $shadow = $this->shadowIfDisabled('crm.deal.update', null, ['id' => $bitrixId, 'fields' => $fields], $correlationId);
        if ($shadow !== null) {
            return;
        }

        $this->withSdk(
            'crm.deal.update',
            ['id' => $bitrixId, 'fields' => $fields],
            fn () => $this->sdk()->getCRMScope()->deal()->update((int) $bitrixId, $fields)->isSuccess(),
            $correlationId,
        );
    }

    public function dealGet(string $bitrixId, ?string $correlationId = null): ?array
    {
        return $this->withSdk(
            'crm.deal.get',
            ['id' => $bitrixId],
            function () use ($bitrixId): ?array {
                $result = $this->sdk()->getCRMScope()->deal()->get((int) $bitrixId);

                return $this->dealItemToArray($result);
            },
            $correlationId,
        );
    }

    public function dealList(array $filter = [], array $select = ['*'], int $start = 0, ?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.deal.list',
            ['filter' => $filter, 'select' => $select, 'start' => $start],
            function () use ($filter, $select, $start): array {
                $result = $this->sdk()->getCRMScope()->deal()->list([], $filter, $select, $start);

                return $this->dealsToArray($result);
            },
            $correlationId,
        );
    }

    public function dealFieldsGet(?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.deal.fields',
            [],
            fn () => $this->sdk()->getCRMScope()->deal()->fields()->getFieldsDescription(),
            $correlationId,
        );
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
                $result = $sdk->getCRMScope()->dealUserfield()->add($fields);

                return $this->extractIdFromUserfieldResult($result);
            }
        );
    }

    /**
     * List Deal custom fields. Real body — bitrix:bootstrap consumes this.
     *
     * @return array<int, array<string, mixed>>
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

    // ── Phase 11 Plan 04 — Deal product-rows + deal-category methods ──────
    // Additive only — byte-identical to existing methods above (B-03 invariant).

    /**
     * Phase 11 Plan 04 — replace deal product rows.
     *
     * Bitrix `crm.deal.productrows.set` is idempotent — passing the same row
     * payload twice produces the same Deal state (rows are replaced, not
     * appended). Plan 11-04 PushQuoteToBitrixDealJob calls this on every
     * push (initial + re-approval) to satisfy QUOT-07 idempotent re-approval.
     *
     * Row shape (RESEARCH §11 verified against vendor SDK):
     *   ['PRODUCT_ID'=>0,'PRODUCT_NAME'=>'...', 'PRICE'=>'...','QUANTITY'=>'...',
     *    'TAX_RATE'=>'20','TAX_INCLUDED'=>'Y','CUSTOMIZED'=>'Y',
     *    'MEASURE_CODE'=>796,'MEASURE_NAME'=>'pcs','SORT'=>10]
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function dealProductRowsSet(int $dealId, array $rows, ?string $correlationId = null): void
    {
        $shadow = $this->shadowIfDisabled('crm.deal.productrows.set', null, ['id' => $dealId, 'rows' => $rows], $correlationId);
        if ($shadow !== null) {
            return;
        }

        $this->withSdk(
            'crm.deal.productrows.set',
            ['id' => $dealId, 'rows' => $rows],
            fn () => $this->sdk()->getCRMScope()->dealProductRows()->set($dealId, $rows)->isSuccess(),
            $correlationId,
        );
    }

    /**
     * Phase 11 Plan 04 — list Bitrix deal categories.
     *
     * Plan 11-04 BitrixQuotesBootstrapCommand consumes this for the
     * pre-flight TYPE_ID=QUOTE verification (Pitfall 2 — operator must
     * create the deal type in Bitrix admin before flipping
     * QUOTE_BITRIX_PUSH_ENABLED=true).
     *
     * Read-only path — never goes through shadowIfDisabled (matches
     * dealList / dealUserfieldList convention).
     *
     * @return array<int, array<string, mixed>>  list of {ID, NAME, SORT, IS_LOCKED}
     */
    public function dealCategoryList(?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.dealcategory.list',
            [],
            function (): array {
                $result = $this->sdk()->getCRMScope()->dealCategory()->list([], [], ['ID', 'NAME', 'SORT', 'IS_LOCKED'], 0);

                $rows = [];
                foreach ($result->getDealCategories() as $cat) {
                    $rows[] = [
                        'ID' => isset($cat->ID) ? (string) $cat->ID : '',
                        'NAME' => isset($cat->NAME) ? (string) $cat->NAME : '',
                        'SORT' => isset($cat->SORT) ? (int) $cat->SORT : 0,
                        'IS_LOCKED' => isset($cat->IS_LOCKED) ? (bool) $cat->IS_LOCKED : false,
                    ];
                }

                return $rows;
            },
            $correlationId,
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Contact methods
    // ══════════════════════════════════════════════════════════════════════

    public function contactAdd(array $fields, ?string $correlationId = null): string
    {
        $shadow = $this->shadowIfDisabled('crm.contact.add', $fields['UF_CRM_WOO_CUSTOMER_ID'] ?? null, $fields, $correlationId);
        if ($shadow !== null) {
            return $shadow;
        }

        return (string) $this->withSdk(
            'crm.contact.add',
            ['fields' => $fields],
            fn () => $this->sdk()->getCRMScope()->contact()->add($fields)->getId(),
            $correlationId,
        );
    }

    public function contactUpdate(string $bitrixId, array $fields, ?string $correlationId = null): void
    {
        $shadow = $this->shadowIfDisabled('crm.contact.update', null, ['id' => $bitrixId, 'fields' => $fields], $correlationId);
        if ($shadow !== null) {
            return;
        }

        $this->withSdk(
            'crm.contact.update',
            ['id' => $bitrixId, 'fields' => $fields],
            fn () => $this->sdk()->getCRMScope()->contact()->update((int) $bitrixId, $fields)->isSuccess(),
            $correlationId,
        );
    }

    public function contactList(array $filter = [], array $select = ['ID', 'EMAIL'], int $start = 0, ?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.contact.list',
            ['filter' => $filter, 'select' => $select, 'start' => $start],
            function () use ($filter, $select, $start): array {
                $result = $this->sdk()->getCRMScope()->contact()->list([], $filter, $select, $start);

                return $this->contactsToArray($result);
            },
            $correlationId,
        );
    }

    public function contactFieldsGet(?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.contact.fields',
            [],
            fn () => $this->sdk()->getCRMScope()->contact()->fields()->getFieldsDescription(),
            $correlationId,
        );
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
        $shadow = $this->shadowIfDisabled('crm.company.add', null, $fields, $correlationId);
        if ($shadow !== null) {
            return $shadow;
        }

        return (string) $this->withSdk(
            'crm.company.add',
            ['fields' => $fields],
            fn () => $this->sdk()->getCRMScope()->company()->add($fields)->getId(),
            $correlationId,
        );
    }

    public function companyUpdate(string $bitrixId, array $fields, ?string $correlationId = null): void
    {
        $shadow = $this->shadowIfDisabled('crm.company.update', null, ['id' => $bitrixId, 'fields' => $fields], $correlationId);
        if ($shadow !== null) {
            return;
        }

        $this->withSdk(
            'crm.company.update',
            ['id' => $bitrixId, 'fields' => $fields],
            fn () => $this->sdk()->getCRMScope()->company()->update((int) $bitrixId, $fields)->isSuccess(),
            $correlationId,
        );
    }

    public function companyList(array $filter = [], array $select = ['ID', 'TITLE'], int $start = 0, ?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.company.list',
            ['filter' => $filter, 'select' => $select, 'start' => $start],
            function () use ($filter, $select, $start): array {
                $result = $this->sdk()->getCRMScope()->company()->list([], $filter, $select, $start);

                return $this->companiesToArray($result);
            },
            $correlationId,
        );
    }

    public function companyFieldsGet(?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.company.fields',
            [],
            fn () => $this->sdk()->getCRMScope()->company()->fields()->getFieldsDescription(),
            $correlationId,
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Dedup primitive
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param  string  $type  'EMAIL' | 'PHONE' | 'IM'
     * @param  string  $entityType  'CONTACT' | 'COMPANY'
     * @param  array<int,string>  $values
     * @return array{CONTACT?: array<int,string>, COMPANY?: array<int,string>, LEAD?: array<int,string>}
     */
    public function duplicateFindByComm(string $type, string $entityType, array $values, ?string $correlationId = null): array
    {
        return (array) $this->withSdk(
            'crm.duplicate.findbycomm',
            ['type' => $type, 'entity_type' => $entityType, 'values' => $values],
            function () use ($type, $entityType, $values): array {
                $sdk = $this->sdk();
                $duplicateService = $sdk->getCRMScope()->duplicate();
                $entityEnum = $this->resolveDuplicateEntityType($entityType);

                // SDK splits by comm-type: findByEmail / findByPhone with a shared underlying
                // crm.duplicate.findbycomm REST call. For anything else (IM) we fall through
                // to findByPhone-style — Bitrix accepts any comm type via the 'type' param.
                $result = strtoupper($type) === 'EMAIL'
                    ? $duplicateService->findByEmail($values, $entityEnum)
                    : $duplicateService->findByPhone($values, $entityEnum);

                return $this->duplicateResultToArray($result);
            },
            $correlationId,
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Shadow-mode + withSdk plumbing
    // ══════════════════════════════════════════════════════════════════════

    /**
     * When CRM_WRITE_ENABLED=false, diverts write-path calls to sync_diffs +
     * returns a shadow sentinel. Returns null when live writes should proceed.
     */
    private function shadowIfDisabled(string $method, ?int $wooId, array $payload, ?string $correlationId): ?string
    {
        if ((bool) config('services.bitrix.write_enabled', false)) {
            return null;
        }

        $correlationId ??= (string) Context::get('correlation_id');
        $shadowId = 'SHADOW-'.Str::uuid()->toString();

        SyncDiff::create([
            'provider' => 'bitrix',
            'channel' => 'bitrix',
            'method' => 'POST',
            'endpoint' => $method,
            'woo_id' => $wooId === null ? null : (string) $wooId,
            'payload' => array_merge($payload, ['__shadow_id' => $shadowId]),
            'correlation_id' => $correlationId,
            'created_at' => now(),
            'status' => 'pending',
        ]);

        $this->logger->log([
            'channel' => 'bitrix',
            'direction' => 'outbound',
            'method' => 'POST',
            'operation' => $method,
            'endpoint' => $method,
            'request_body' => $payload,
            'response_body' => ['shadow_id' => $shadowId, 'reason' => 'CRM_WRITE_ENABLED=false'],
            'http_status' => 0,
            'correlation_id' => $correlationId,
            'status' => 'success',
        ]);

        return $shadowId;
    }

    /**
     * Core SDK-call wrapper: applies the 2 req/sec throttle, logs the call,
     * classifies thrown exceptions into transient vs permanent.
     */
    private function withSdk(string $method, array $requestPayload, Closure $callable, ?string $correlationId): mixed
    {
        $correlationId ??= (string) Context::get('correlation_id');
        $this->applyThrottle();
        $start = microtime(true);

        try {
            $result = $callable();
            $this->lastCallAt = microtime(true);

            $this->logger->log([
                'channel' => 'bitrix',
                'direction' => 'outbound',
                'method' => 'POST',
                'operation' => $method,
                'endpoint' => $method,
                'request_body' => $requestPayload,
                'response_body' => $this->summariseResult($result),
                'http_status' => 200,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'correlation_id' => $correlationId,
                'status' => 'success',
            ]);

            return $result;
        } catch (TransportException $e) {
            $this->lastCallAt = microtime(true);
            $sanitisedMessage = $this->sanitiseErrorMessage($e->getMessage());

            $this->logger->log([
                'channel' => 'bitrix',
                'direction' => 'outbound',
                'method' => 'POST',
                'operation' => $method,
                'endpoint' => $method,
                'request_body' => $requestPayload,
                'response_body' => ['error' => $sanitisedMessage],
                'http_status' => 503,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'correlation_id' => $correlationId,
                'status' => 'failed',
                'error_message' => $sanitisedMessage,
            ]);

            throw new BitrixTransientException(
                sprintf('BitrixClient: transport failure on %s — %s', $method, $sanitisedMessage),
                0,
                $e,
            );
        } catch (Throwable $e) {
            $this->lastCallAt = microtime(true);
            $sanitisedMessage = $this->sanitiseErrorMessage($e->getMessage());
            $status = $this->extractHttpStatus($e);

            $this->logger->log([
                'channel' => 'bitrix',
                'direction' => 'outbound',
                'method' => 'POST',
                'operation' => $method,
                'endpoint' => $method,
                'request_body' => $requestPayload,
                'response_body' => ['error' => $sanitisedMessage],
                'http_status' => $status,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'correlation_id' => $correlationId,
                'status' => 'failed',
                'error_message' => $sanitisedMessage,
            ]);

            throw new BitrixPermanentException(
                sprintf('BitrixClient: validation/auth failure on %s — %s', $method, $sanitisedMessage),
                0,
                $e,
            );
        }
    }

    /** Ensures ≥500ms between SDK calls within the same PHP process. */
    private function applyThrottle(): void
    {
        if ($this->lastCallAt === 0.0) {
            return;
        }
        $elapsedUsec = (int) ((microtime(true) - $this->lastCallAt) * 1_000_000);
        $needed = self::SDK_THROTTLE_USEC - $elapsedUsec;
        if ($needed > 0) {
            usleep($needed);
        }
    }

    /** Replace the webhook URL in error messages with a redaction marker (T-04-02-01). */
    private function sanitiseErrorMessage(string $message): string
    {
        $webhookUrl = $this->webhookUrl();
        if ($webhookUrl !== '') {
            $message = str_replace($webhookUrl, '***REDACTED_URL***', $message);
            // Redact even partial leaks (trailing slash, trimmed).
            $message = str_replace(rtrim($webhookUrl, '/'), '***REDACTED_URL***', $message);
        }

        return $message;
    }

    /**
     * Phase 09.1 Plan 01 (D-11) — Test connection for the Bitrix24 webhook.
     *
     * Calls dealCategoryList() — the lightest-weight read against the SDK
     * that confirms the webhook URL + auth + Bitrix server reachability.
     * Empty result array IS still success (Bitrix may have no categories yet).
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        try {
            $this->dealCategoryList();
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::ok($latency);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($this->sanitiseErrorMessage($e->getMessage()), $latency);
        }
    }

    /** Best-effort HTTP status extraction for permanent-error logging. */
    private function extractHttpStatus(Throwable $e): int
    {
        $code = $e->getCode();
        if (is_int($code) && $code >= 400 && $code < 600) {
            return $code;
        }

        return 400;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SDK result normalisation
    // ══════════════════════════════════════════════════════════════════════

    /** Summary row written into integration_events.response_body (keeps payloads short). */
    private function summariseResult(mixed $result): array
    {
        if (is_array($result)) {
            return ['count' => count($result)];
        }
        if (is_scalar($result)) {
            return ['id' => (string) $result];
        }
        if ($result === null) {
            return ['result' => null];
        }
        if (is_object($result)) {
            return ['class' => $result::class];
        }

        return [];
    }

    private function dealItemToArray(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }
        if (is_array($result)) {
            return $result;
        }
        if (is_object($result) && method_exists($result, 'deal')) {
            $deal = $result->deal();
            if (is_object($deal) && method_exists($deal, 'getResult')) {
                $data = $deal->getResult();

                return is_array($data) ? $data : null;
            }
        }
        if (is_object($result) && method_exists($result, 'getCoreResponse')) {
            $core = $result->getCoreResponse();
            if (is_object($core) && method_exists($core, 'getResponseData')) {
                $data = $core->getResponseData()->getResult();

                return is_array($data) ? $data : null;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function dealsToArray(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'getDeals')) {
            $rows = [];
            foreach ($result->getDeals() as $item) {
                $rows[] = is_object($item) && method_exists($item, 'getResult')
                    ? (array) $item->getResult()
                    : (array) $item;
            }

            return $rows;
        }

        return $this->extractResultArray($result);
    }

    /** @return array<int, array<string, mixed>> */
    private function contactsToArray(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'getContacts')) {
            $rows = [];
            foreach ($result->getContacts() as $item) {
                $rows[] = is_object($item) && method_exists($item, 'getResult')
                    ? (array) $item->getResult()
                    : (array) $item;
            }

            return $rows;
        }

        return $this->extractResultArray($result);
    }

    /** @return array<int, array<string, mixed>> */
    private function companiesToArray(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'getCompanies')) {
            $rows = [];
            foreach ($result->getCompanies() as $item) {
                $rows[] = is_object($item) && method_exists($item, 'getResult')
                    ? (array) $item->getResult()
                    : (array) $item;
            }

            return $rows;
        }

        return $this->extractResultArray($result);
    }

    /** @return array{CONTACT?: array<int,string>, COMPANY?: array<int,string>, LEAD?: array<int,string>} */
    private function duplicateResultToArray(mixed $result): array
    {
        if (is_array($result)) {
            // Already shaped as the Bitrix REST response.
            return $result;
        }

        if (is_object($result) && method_exists($result, 'getCoreResponse')) {
            try {
                $data = $result->getCoreResponse()->getResponseData()->getResult();
                if (is_array($data)) {
                    // The REST shape is ['CONTACT' => ['123', '456'], 'COMPANY' => []].
                    return $data;
                }
            } catch (Throwable) {
                // Fall through to empty.
            }
        }

        // SDK DuplicateResult only exposes getContactsId() — wrap for caller parity.
        if (is_object($result) && method_exists($result, 'getContactsId')) {
            try {
                $ids = $result->getContactsId();

                return ['CONTACT' => array_map(static fn ($id) => (string) $id, $ids)];
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * Fallback for any AbstractResult-shape SDK object: pull the raw result array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractResultArray(mixed $result): array
    {
        if (! is_object($result)) {
            return [];
        }
        if (! method_exists($result, 'getCoreResponse')) {
            return [];
        }
        try {
            $data = $result->getCoreResponse()->getResponseData()->getResult();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        // Normalise to list-of-rows.
        if (array_is_list($data)) {
            return $data;
        }

        return [$data];
    }

    private function resolveDuplicateEntityType(string $entityType): ?DuplicateEntityType
    {
        return match (strtoupper($entityType)) {
            'CONTACT' => DuplicateEntityType::Contact,
            'COMPANY' => DuplicateEntityType::Company,
            'LEAD' => DuplicateEntityType::Lead,
            default => null,
        };
    }

    // ══════════════════════════════════════════════════════════════════════
    // Legacy read/write helpers (used by Plan 04-01 userfield methods only)
    // ══════════════════════════════════════════════════════════════════════

    /** Lazy-builds the SDK service builder. Fails hard if webhook URL missing. */
    private function sdk(): ServiceBuilder
    {
        if ($this->sdk === null) {
            $webhookUrl = $this->webhookUrl();
            if ($webhookUrl === '') {
                throw new RuntimeException('BitrixClient: BITRIX_WEBHOOK_URL is empty. Configure via admin/integration-credentials or .env before use.');
            }
            $this->sdk = ServiceBuilderFactory::createServiceBuilderFromWebhook($webhookUrl);
        }

        return $this->sdk;
    }

    /**
     * Plan 04-01 userfield path — retained alongside withSdk because the
     * bootstrap command + tests already depend on its exact log shape.
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
                'error_message' => $this->sanitiseErrorMessage($e->getMessage()),
            ]);
            throw $e;
        }
    }

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
                'error_message' => $this->sanitiseErrorMessage($e->getMessage()),
            ]);
            throw $e;
        }
    }

    /** @see Plan 04-01 — supports scalar / ->getId() / ['ID' => ...] shapes. */
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

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normaliseUserfieldList(mixed $result): array
    {
        if (is_array($result)) {
            if ($result === [] || array_is_list($result)) {
                return array_map(static fn ($r) => is_array($r) ? $r : ['value' => $r], $result);
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
