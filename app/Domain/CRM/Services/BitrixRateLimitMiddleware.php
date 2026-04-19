<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware enforcing Bitrix24's ~2 req/sec inbound-webhook ceiling
 * (see SDK Issue #76 — no built-in limiter).
 *
 * Token-bucket: 2 tokens/sec (one-second sliding window of request timestamps).
 * When the window already holds 2 entries, we usleep until the oldest ages out.
 *
 * On 429 response, we honour Retry-After (or default to 2s), sleep, and retry
 * ONCE from inside the middleware. Further 429s bubble up as the SDK's
 * TransportException; BitrixClient::withSdk classifies that as transient.
 *
 * Shipped standalone in Plan 04-02: the SDK 1.10.x ServiceBuilderFactory does
 * not accept a custom Guzzle HandlerStack (see vendor/bitrix24/b24phpsdk/src/
 * Services/ServiceBuilderFactory.php lines 160-172 — only EventDispatcher +
 * LoggerInterface are injectable). To satisfy the ≤2 req/sec guarantee today,
 * BitrixClient applies an equivalent usleep-based throttle inside withSdk().
 * This middleware stays shipped (and tested) because:
 *   1. It is drop-in ready for a future SDK version that exposes HTTP-client
 *      injection (tracked for Phase 7 polish).
 *   2. It documents the exact throttle contract with testable behaviour.
 *   3. A BitrixClient subclass/test seam can opt into the middleware via
 *      its own Guzzle client when exercising the 429 Retry-After path.
 */
final class BitrixRateLimitMiddleware
{
    /** @var array<float> timestamps (microseconds) of recent requests */
    private static array $window = [];

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $this->throttle();

            /** @var PromiseInterface $promise */
            $promise = $handler($request, $options);

            return $promise->then(function (ResponseInterface $response) use ($request, $handler, $options) {
                if ($response->getStatusCode() !== 429) {
                    return $response;
                }

                $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: 2);
                usleep(max(1, $retryAfter) * 1_000_000);

                // Retry ONCE — further 429 bubbles up as-is (SDK turns it into TransportException).
                return $handler($request, $options)->wait();
            });
        };
    }

    private function throttle(): void
    {
        $now = microtime(true);
        self::$window = array_values(array_filter(self::$window, static fn ($t) => $t > $now - 1.0));

        if (count(self::$window) >= 2) {
            $oldest = self::$window[0];
            $sleepFor = max(0.0, 1.0 - ($now - $oldest));
            if ($sleepFor > 0) {
                usleep((int) ($sleepFor * 1_000_000));
            }
        }

        self::$window[] = microtime(true);
    }

    /** Test seam — reset the static window between tests. */
    public static function reset(): void
    {
        self::$window = [];
    }
}
