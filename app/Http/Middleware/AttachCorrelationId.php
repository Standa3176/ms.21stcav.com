<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\LogBatch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach a correlation_id to every HTTP request + thread it through Context + spatie LogBatch.
 *
 * Honours inbound X-Correlation-Id or X-Request-Id when well-formed (8-64 chars, safe alphabet).
 * Otherwise generates a UUIDv4. Emits X-Correlation-Id on the response for downstream correlation.
 *
 * Registered in bootstrap/app.php under withMiddleware web + api group append.
 *
 * FOUND-03 — correlation_id propagation. T-03-02 mitigation — format validation prevents
 * log-injection attacks via malformed inbound header values.
 */
class AttachCorrelationId
{
    /** Valid correlation_id format: 8-64 chars, alphanumeric + dashes only (UUIDv4-compatible, no log injection). */
    private const VALID_FORMAT = '/^[A-Za-z0-9\-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->header('X-Correlation-Id') ?? $request->header('X-Request-Id');

        $correlationId = ($inbound !== null && preg_match(self::VALID_FORMAT, $inbound))
            ? $inbound
            : (string) Str::uuid();

        // Laravel 12 Context — automatically propagates to queued jobs via dehydrate/hydrate.
        Context::add('correlation_id', $correlationId);

        // Thread spatie/activitylog rows in this request scope with the same UUID.
        LogBatch::startBatch();
        LogBatch::setBatch($correlationId);

        try {
            /** @var Response $response */
            $response = $next($request);
            $response->headers->set('X-Correlation-Id', $correlationId);

            return $response;
        } finally {
            LogBatch::endBatch();
        }
    }
}
