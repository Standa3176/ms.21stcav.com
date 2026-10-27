<?php

declare(strict_types=1);

namespace App\Domain\CRM\Support;

/**
 * Pure predicate over deal-creating Woo order statuses (config-driven, 12.1 D-01/D-01a).
 *
 * The caller passes config('services.bitrix.order_paid_statuses') so this class
 * stays free of config()/env() access.
 */
final class OrderPaidGate
{
    /**
     * @param  array<int, string>  $paidStatuses
     */
    public static function isPaid(string $status, array $paidStatuses): bool
    {
        $normalised = self::normalise($status);
        if ($normalised === '') {
            return false;
        }

        $list = array_map(static fn ($s): string => self::normalise((string) $s), $paidStatuses);

        return in_array($normalised, $list, true);
    }

    /** Trim, lower-case and strip a leading "wc-" prefix. */
    public static function normalise(string $status): string
    {
        $s = mb_strtolower(trim($status));

        return str_starts_with($s, 'wc-') ? substr($s, 3) : $s;
    }
}
