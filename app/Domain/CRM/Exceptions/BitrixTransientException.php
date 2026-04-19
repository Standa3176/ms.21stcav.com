<?php

declare(strict_types=1);

namespace App\Domain\CRM\Exceptions;

use RuntimeException;

/**
 * Raised when a Bitrix call fails with a transient error:
 *   - 5xx responses (Bitrix outage, temporary DB lock)
 *   - Network errors (connection refused, DNS, timeout)
 *   - 429 Too Many Requests (honour Retry-After per D-11 + STACK.md §2)
 *
 * Caller policy (Plan 04-02+): retry 3× with 30s → 5m → 30m backoff (D-11).
 *
 * Classification logic is introduced in Plan 04-02 (BitrixClient body).
 * This class is shipped in Plan 04-01 so downstream plans can type-hint
 * against it without circular declarations.
 */
final class BitrixTransientException extends RuntimeException
{
}
