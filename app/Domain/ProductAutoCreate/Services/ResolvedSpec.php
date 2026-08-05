<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

/**
 * 260728-fwx T2 — the classified plan {@see SpecTaxonomyResolver::resolve()}
 * returns for a product's whole raw spec set. Three buckets, no overlap:
 *
 *  - global    — rows that map to a `pa_*` taxonomy AND resolve to one or MORE
 *                EXISTING terms. Each entry:
 *                {attribute_id:int, attribute_slug:string, term_id:int,
 *                 term_name:string, term_ids:list<int>, term_names:list<string>,
 *                 raw_label:string, raw_value:string}
 *                These are the ONLY rows T3 sends to Woo as taxonomy (`id`)
 *                attributes.
 *                MULTI-TERM (260728-fwx T9): `term_ids`/`term_names` carry ALL
 *                resolved terms (e.g. a "Bluetooth, 2.4 GHz Wireless"
 *                Connectivity value → two terms). The scalar `term_id`/
 *                `term_name` keys are kept for backward compatibility and always
 *                mirror the FIRST resolved term; single-term rows have one-element
 *                arrays. The builder emits `options` from `term_names`.
 *  - local     — spec-only rows kept as local WC attributes (label passthrough):
 *                {name:string, value:string}. Includes MPN/Model/Part Number,
 *                the EXACT brightness figures (D1), band companion figures, and
 *                any label not in the 44-map.
 *  - unmatched — rows whose label mapped to a taxonomy but whose value did NOT
 *                resolve to an existing term (or a band term absent from the
 *                cache, or a dropped mixed-unit brightness):
 *                {raw_label:string, raw_value:string, attribute_slug:string,
 *                 reason:string}. NEVER sent to Woo (Woo auto-creates unknown
 *                terms → re-pollutes the facet). Surfaced for the T6 report.
 */
final class ResolvedSpec
{
    /**
     * @param  array<int, array{attribute_id:int, attribute_slug:string, term_id:int, term_name:string, term_ids:list<int>, term_names:list<string>, raw_label:string, raw_value:string}>  $global
     * @param  array<int, array{name:string, value:string}>  $local
     * @param  array<int, array{raw_label:string, raw_value:string, attribute_slug:string, reason:string}>  $unmatched
     */
    public function __construct(
        private array $global,
        private array $local,
        private array $unmatched,
    ) {}

    /**
     * @return array<int, array{attribute_id:int, attribute_slug:string, term_id:int, term_name:string, term_ids:list<int>, term_names:list<string>, raw_label:string, raw_value:string}>
     */
    public function global(): array
    {
        return $this->global;
    }

    /**
     * @return array<int, array{name:string, value:string}>
     */
    public function local(): array
    {
        return $this->local;
    }

    /**
     * @return array<int, array{raw_label:string, raw_value:string, attribute_slug:string, reason:string}>
     */
    public function unmatched(): array
    {
        return $this->unmatched;
    }

    /**
     * @return array{global: array<int, array<string, mixed>>, local: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'global' => $this->global,
            'local' => $this->local,
            'unmatched' => $this->unmatched,
        ];
    }
}
