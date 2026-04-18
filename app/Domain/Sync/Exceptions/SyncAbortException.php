<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

/**
 * D-06 tiered abort — thrown by AbortGuard when any of three triggers fire:
 *
 *   (a) error_rate         — >20% failures after 500+ SKUs (feed degraded)
 *   (b) consecutive_failures — 50+ in a row (supplier/auth likely down)
 *   (c) jwt_refresh        — SupplierClient reports token cannot be refreshed (D-06c)
 *
 * $reason corresponds to SyncRun::ABORT_* constants so the orchestrator can
 * pass it directly into $run->abort($e->reason, $e->getMessage()).
 */
final class SyncAbortException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Sync aborted: {$reason}");
    }
}
