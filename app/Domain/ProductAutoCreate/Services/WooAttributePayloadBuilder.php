<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Console\Commands\ResyncProductsToWooCommand;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;

/**
 * 260728-fwx T3 — turns a product's raw `attributes_json` ({name,value}[]) into
 * the WC-REST `attributes[]` payload, routing every row through the
 * {@see SpecTaxonomyResolver} so filterable specs attach as GLOBAL `pa_*`
 * TAXONOMY attributes (FacetWP-visible) instead of local postmeta.
 *
 * Shared by BOTH write paths — {@see PublishProductJob}
 * (new-product publish) and {@see ResyncProductsToWooCommand}
 * (manual re-sync) — so a resync RE-GLOBALISES rather than re-localising, and the
 * two paths can never drift apart.
 *
 * Output shape (per {@see ResolvedSpec} bucket):
 *  - GLOBAL    → {id:<attribute_id>, options:[<resolved term_name>], visible:true,
 *                 variation:false, position:<n>}. Sending the resolved term NAME
 *                 with the attribute `id` set makes WC LINK the existing term
 *                 (it never auto-creates because the term already exists).
 *  - LOCAL     → {name:<label>, options:[<raw value>], visible:true,
 *                 variation:false, position:<n>} — the legacy spec-table shape,
 *                 for labels that are not filterable taxonomies.
 *  - UNMATCHED → NOT emitted (the resolver already logged them for the T6 report;
 *                sending an unknown option would auto-create a dup term and
 *                re-pollute the cleaned facet).
 *
 * Positions are deterministic and stable: GLOBAL rows first (in resolver row
 * order), then LOCAL rows, numbered 0..n across the combined list. Returns [] when
 * nothing resolves — callers then omit the `attributes` key entirely (preserving
 * the "no empty global attributes" behaviour).
 */
final class WooAttributePayloadBuilder
{
    public function __construct(private readonly SpecTaxonomyResolver $resolver) {}

    /**
     * @param  array<int, array{name?:mixed, value?:mixed}>  $rows  attributes_json entries
     * @return array<int, array<string, mixed>>
     */
    public function build(array $rows): array
    {
        $resolved = $this->resolver->resolve($rows);

        $out = [];
        $position = 0;

        // GLOBAL first — term-linked pa_* taxonomy rows (id + resolved term
        // names). `term_names` carries ALL resolved terms (multi-value support,
        // 260728-fwx T9); single-term rows yield a one-element `options` array,
        // byte-identical to the pre-T9 output.
        foreach ($resolved->global() as $g) {
            $out[] = [
                'id' => $g['attribute_id'],
                'options' => $g['term_names'],
                'position' => $position++,
                'visible' => true,
                'variation' => false,
            ];
        }

        // LOCAL next — spec-only rows kept as local WC attributes (label + value).
        foreach ($resolved->local() as $l) {
            $out[] = [
                'name' => $l['name'],
                'options' => [$l['value']],
                'position' => $position++,
                'visible' => true,
                'variation' => false,
            ];
        }

        // UNMATCHED rows are intentionally dropped (resolve-don't-invent).

        return $out;
    }
}
