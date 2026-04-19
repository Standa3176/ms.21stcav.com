<?php

declare(strict_types=1);

namespace App\Domain\CRM\Exceptions;

use RuntimeException;

/**
 * Raised when a Bitrix call fails with a permanent error:
 *   - 4xx validation errors (unknown field, malformed value, bad enum)
 *   - 401 Unauthorized (webhook URL rotated; credential broken)
 *   - 403 Forbidden (API method disabled for the webhook's permission set)
 *   - 404 Not Found (Contact/Deal ID no longer exists)
 *
 * Caller policy (Plan 04-02+): fail fast — do NOT retry. Surface the error as
 * a `suggestions('crm_push_failed')` row so ops can inspect + replay after fixing
 * the underlying issue (D-11 + D-12).
 *
 * Classification logic is introduced in Plan 04-02 (BitrixClient body).
 */
final class BitrixPermanentException extends RuntimeException
{
}
