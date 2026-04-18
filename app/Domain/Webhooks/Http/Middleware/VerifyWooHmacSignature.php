<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the X-WC-Webhook-Signature header against HMAC-SHA256 of the raw body.
 *
 * CRITICAL ORDERING (Pitfall A):
 *   - This middleware MUST run BEFORE any middleware that parses JSON input.
 *   - $request->getContent() reads the raw stream; once read by input parsing, the
 *     subsequent HMAC comparison silently fails.
 *   - Registered as the FIRST middleware on the /webhooks/woo/* route group in routes/webhooks.php.
 *
 * Algorithm per WooCommerce WC_Webhook::generate_signature:
 *   $signature = base64_encode(hash_hmac('sha256', $body, $secret, true))
 *
 * Comparison uses hash_equals() for timing-safe comparison (T-04-02).
 */
class VerifyWooHmacSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-WC-Webhook-Signature');
        $secret = config('services.woo.webhook_secret');

        abort_unless(
            is_string($signature) && is_string($secret) && $secret !== '',
            401,
            'Missing HMAC signature or server secret not configured'
        );

        // CRITICAL: raw body. Do NOT call ->json() / ->all() / ->input() anywhere
        // in the middleware stack before this point (Pitfall A).
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        abort_unless(hash_equals($expected, $signature), 401, 'Invalid HMAC signature');

        return $next($request);
    }
}
