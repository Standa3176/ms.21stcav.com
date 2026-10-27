<?php

declare(strict_types=1);

namespace App\Domain\CRM\Support;

/**
 * Phase 12.1 Plan 02 — Bitrix deal-field write guards.
 *
 * Per D-07 there are TWO deal fields both labelled "Actual Margin". The
 * dashboard (another system) reads UF_CRM_1610623973323, so this app must
 * never write it — not from static fields, not from CrmFieldMapping, not
 * from the seeder.
 */
final class BitrixFieldGuards
{
    public const ACTUAL_MARGIN_FIELD = 'UF_CRM_1610623973323';

    public static function isForbiddenTarget(string $code): bool
    {
        return strtoupper(trim($code)) === self::ACTUAL_MARGIN_FIELD;
    }
}
