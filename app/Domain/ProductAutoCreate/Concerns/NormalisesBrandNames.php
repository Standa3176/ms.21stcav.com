<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Concerns;

/**
 * Quick task 260702-qd8 — shared brand-name normalisation + junk guard.
 *
 * Extracted VERBATIM from RefreshBrandsToAddCommand (260702-om7) so that the
 * command AND the new WooBrandCreator share ONE implementation. Behaviour is
 * byte-identical to the pre-extraction inline private methods — the
 * BrandsToAddIndexTest (260702-om7) continues to pass unchanged.
 */
trait NormalisesBrandNames
{
    /** HTML-decode + trim + collapse inner whitespace. 'VOGEL&#039;S' => "VOGEL'S". */
    public function normaliseBrandName(string $raw): string
    {
        $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', trim($s));
    }

    /** True when a (normalised) brand is on the config exclusion list (case-insensitive). */
    public function isJunkBrand(string $brand): bool
    {
        $ex = array_map('mb_strtolower', (array) config('product_auto_create.brands_to_add_exclude', []));

        return in_array(mb_strtolower(trim($brand)), $ex, true);
    }
}
