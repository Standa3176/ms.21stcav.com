<?php

declare(strict_types=1);

use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Exceptions\BitrixTransientException;
use App\Domain\CRM\Services\BitrixClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Bitrix24\SDK\Core\Exceptions\BaseException;
use Bitrix24\SDK\Core\Exceptions\TransportException;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 02 Task 1 — D-11 exception classification
|--------------------------------------------------------------------------
|
| 5xx / network / 429 raised by the SDK arrives as TransportException; the
| wrapper MUST re-throw BitrixTransientException.
| Any other SDK throwable (4xx, auth, not-found) MUST re-throw
| BitrixPermanentException so the job-layer retry policy fails fast.
*/

beforeEach(function (): void {
    config([
        'services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/',
        'services.bitrix.write_enabled' => true,
    ]);
});

/**
 * Test-only BitrixClient seam — lets us throw controlled exceptions from the
 * SDK call without wiring a fake ServiceBuilder.
 */
function throwingBitrixClient(\Throwable $error): BitrixClient
{
    return new class($error, app(IntegrationLogger::class)) extends BitrixClient
    {
        public function __construct(private readonly \Throwable $error, IntegrationLogger $logger)
        {
            parent::__construct($logger);
        }

        public function dealAdd(array $fields, ?string $correlationId = null): string
        {
            $reflection = new ReflectionMethod(parent::class, 'withSdk');
            $reflection->setAccessible(true);

            return (string) $reflection->invoke(
                $this,
                'crm.deal.add',
                ['fields' => $fields],
                fn () => throw $this->error,
                $correlationId,
            );
        }

        public function dealList(array $filter = [], array $select = ['*'], int $start = 0, ?string $correlationId = null): array
        {
            $reflection = new ReflectionMethod(parent::class, 'withSdk');
            $reflection->setAccessible(true);

            return (array) $reflection->invoke(
                $this,
                'crm.deal.list',
                ['filter' => $filter],
                fn () => throw $this->error,
                $correlationId,
            );
        }
    };
}

it('throws BitrixTransientException on TransportException', function (): void {
    $client = throwingBitrixClient(new TransportException('503 Gateway Timeout'));

    expect(fn () => $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 1]))
        ->toThrow(BitrixTransientException::class);
});

it('throws BitrixPermanentException on generic SDK BaseException', function (): void {
    $client = throwingBitrixClient(new BaseException('Invalid UF field', 400));

    expect(fn () => $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 2]))
        ->toThrow(BitrixPermanentException::class);
});

it('logs integration_events row with response_status=503 on transient', function (): void {
    $client = throwingBitrixClient(new TransportException('503 Service Unavailable'));

    try {
        $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 3]);
    } catch (BitrixTransientException) {
        // expected
    }

    $event = IntegrationEvent::where('channel', 'bitrix')->latest('id')->first();
    expect($event)->not->toBeNull();
    expect($event->http_status)->toBe(503);
    expect($event->status)->toBe('failed');
});

it('logs integration_events row with response_status in 400-499 on permanent', function (): void {
    $client = throwingBitrixClient(new BaseException('Bad request', 400));

    try {
        $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 4]);
    } catch (BitrixPermanentException) {
        // expected
    }

    $event = IntegrationEvent::where('channel', 'bitrix')->latest('id')->first();
    expect($event->http_status)->toBeGreaterThanOrEqual(400);
    expect($event->http_status)->toBeLessThan(500);
    expect($event->status)->toBe('failed');
});

it('sanitises webhook URL from exception messages (T-04-02-01)', function (): void {
    $leakyMessage = 'API error at https://example.bitrix24.com/rest/1/fake-token/ — bad request';
    $client = throwingBitrixClient(new BaseException($leakyMessage, 400));

    try {
        $client->dealAdd(['UF_CRM_WOO_ORDER_ID' => 5]);
    } catch (BitrixPermanentException $e) {
        expect($e->getMessage())->not->toContain('fake-token');
        expect($e->getMessage())->toContain('***REDACTED_URL***');
    }

    $event = IntegrationEvent::where('channel', 'bitrix')->latest('id')->first();
    $error = (string) $event->error_message;
    expect($error)->not->toContain('fake-token');
    expect($error)->toContain('***REDACTED_URL***');
});

it('classifies TransportException as transient even when caused by read methods', function (): void {
    $client = throwingBitrixClient(new TransportException('timeout'));

    expect(fn () => $client->dealList())->toThrow(BitrixTransientException::class);
});
