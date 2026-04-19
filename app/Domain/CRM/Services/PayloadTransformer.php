<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

/**
 * Phase 4 Plan 03 — tiny named-transformer registry for CrmFieldMapping.transformer.
 *
 * Legacy plugin exposed the transformer idea implicitly (field-specific
 * conversion inside OrderToBitrix24). Our port externalises it so admins
 * can pick a transformer in the Filament UI (Plan 04-04) without code edits.
 *
 * Supported names (null / 'none' = identity):
 *   - 'uppercase'        → mb_strtoupper
 *   - 'phone_e164'       → strips non-digits (except +), validates E.164, returns +XX... or null
 *   - 'join_line_items'  → accepts array<line_item>, emits "SKU × qty; ..." string
 */
final class PayloadTransformer
{
    public static function apply(mixed $value, ?string $transformer): mixed
    {
        $transformer = $transformer === null || $transformer === '' ? 'none' : $transformer;

        return match ($transformer) {
            'none' => $value,
            'uppercase' => is_string($value) ? mb_strtoupper($value) : $value,
            'phone_e164' => self::normalisePhone(is_string($value) ? $value : null),
            'join_line_items' => self::joinLineItems($value),
            default => $value,
        };
    }

    public static function normalisePhone(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // '00' international dialing prefix → '+' (UK convention; common across EU):
        // '00447700900111' and '+447700900111' both mean the same number.
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (! preg_match('/^\+?[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        return str_starts_with($digits, '+') ? $digits : '+'.$digits;
    }

    /**
     * @param  mixed  $lineItems  Woo `line_items` array
     */
    private static function joinLineItems(mixed $lineItems): string
    {
        if (! is_array($lineItems)) {
            return '';
        }

        $parts = [];
        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            $qty = (int) ($item['quantity'] ?? 1);
            if ($name === '') {
                continue;
            }
            $parts[] = $qty > 1 ? "{$name} × {$qty}" : $name;
        }

        return implode('; ', $parts);
    }
}
