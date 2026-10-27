<?php

declare(strict_types=1);

namespace App\Domain\CRM\Support;

/**
 * Detects the sentinel id BitrixClient returns for writes in shadow mode
 * (CRM_WRITE_ENABLED=false). Producer: BitrixClient::shadowIfDisabled.
 */
final class ShadowId
{
    public const PREFIX = 'SHADOW-';

    public static function is(?string $id): bool
    {
        return $id !== null && str_starts_with($id, self::PREFIX);
    }
}
