<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 6 Plan 04 — ValidPregPattern (T-06-04-01 ReDoS mitigation).
 *
 * Validates that a user-provided regex pattern:
 *   1. Is ≤ 256 characters (belt-and-braces with the model-level cap)
 *   2. Compiles cleanly (@preg_match returns !== false)
 *   3. Does not obviously trigger catastrophic backtracking — we run it
 *      against a representative 128-char test string with a 50ms wall-clock
 *      budget. Patterns that exceed the budget fail validation.
 *
 * PHP's preg_match doesn't have a hard timeout, but we can set
 * pcre.backtrack_limit via ini_set — a pattern that hits the backtrack limit
 * fires PREG_BACKTRACK_LIMIT_ERROR which we treat as a fail. 50ms is a
 * pragmatic upper bound for admin-authored skip rules.
 */
final class ValidPregPattern implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a non-empty regex pattern.');

            return;
        }

        if (strlen($value) > 256) {
            $fail('The :attribute must be at most 256 characters.');

            return;
        }

        $delimited = '/'.$value.'/';

        // Set a conservative backtrack limit — catastrophic-backtracking
        // patterns (e.g. `(a+)+$`) will trip this before blowing the request.
        $originalBacktrack = (int) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        $start = microtime(true);
        $result = @preg_match($delimited, str_repeat('a', 128).'X');
        $elapsedMs = (microtime(true) - $start) * 1000;

        ini_set('pcre.backtrack_limit', (string) $originalBacktrack);

        if ($result === false) {
            $lastError = preg_last_error();
            $reason = match ($lastError) {
                PREG_BACKTRACK_LIMIT_ERROR => 'catastrophic backtracking detected (simplify the pattern)',
                PREG_RECURSION_LIMIT_ERROR => 'recursion limit hit',
                PREG_BAD_UTF8_ERROR => 'invalid UTF-8',
                PREG_INTERNAL_ERROR => 'internal PCRE error (pattern is malformed)',
                default => 'does not compile as a valid regex',
            };
            $fail("The :attribute {$reason}.");

            return;
        }

        if ($elapsedMs > 50) {
            $fail('The :attribute is too slow (>50ms on a 128-char test string) — likely catastrophic backtracking. Simplify the pattern.');
        }
    }
}
