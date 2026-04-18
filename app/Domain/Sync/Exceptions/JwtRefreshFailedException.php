<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

/**
 * Thrown by SupplierClient when:
 *   (a) the initial /generate_token.php call fails (bad credentials, network error), OR
 *   (b) a 401 is still returned AFTER the cached token was invalidated and a fresh one
 *       was fetched — i.e. the supplier API consistently rejects our credentials.
 *
 * Per D-06(c) in 02-CONTEXT.md, this is one of the three tiered abort triggers:
 * the orchestrator catches this exception at the run level, flips the SyncRun to
 * `status=aborted` with `abort_reason=jwt_refresh`, and halts the run.
 *
 * We retry ONCE and no more — looping on 401 risks lockouts from upstream rate-limiters
 * that interpret repeated auth failures as brute-force attempts.
 */
final class JwtRefreshFailedException extends \RuntimeException
{
}
