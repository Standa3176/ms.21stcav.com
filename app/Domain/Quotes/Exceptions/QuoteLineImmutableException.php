<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use RuntimeException;

/**
 * Phase 11 Plan 02 — D-13 line snapshot immutability tripwire.
 *
 * Thrown by QuoteLineImmutabilityObserver::saving when:
 *   (a) parent Quote.status != draft AND any forbidden column is dirty, OR
 *   (b) parent Quote.status == draft AND price/snapshot is being mutated
 *       (price + snapshot are set ONCE on creation only).
 *
 * The exception is the LAST LINE OF DEFENCE per Plan 11-02 threat model T-11-02-01:
 * even if a future bulk-import path or a mass-assignment-friendly Eloquent call
 * tries to mutate `unit_price_pence_at_quote` after status=sent, the observer
 * fires before the row is saved and this exception bubbles up.
 *
 * The error message format MUST be stable — Plan 11-04's PDF preview UX
 * surfaces it verbatim to the operator when a stale Filament form re-tries
 * a save against a now-sent quote.
 */
final class QuoteLineImmutableException extends RuntimeException
{
    /**
     * Build the canonical exception with the dirty-column diff inline.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $dirty
     */
    public static function forDirtyColumns(string $lineUlid, string $quoteUlid, string $status, array $dirty): self
    {
        $cols = implode(',', array_keys($dirty));
        $diff = json_encode($dirty, JSON_UNESCAPED_SLASHES);

        return new self(sprintf(
            'QuoteLine %s columns [%s] are immutable when Quote %s.status=%s (allowed: status=draft). Diff: %s',
            $lineUlid,
            $cols,
            $quoteUlid,
            $status,
            $diff === false ? '<unencodable>' : $diff,
        ));
    }
}
