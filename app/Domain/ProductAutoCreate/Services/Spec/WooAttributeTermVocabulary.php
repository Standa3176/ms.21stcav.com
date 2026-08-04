<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services\Spec;

use App\Domain\ProductAutoCreate\Models\WooAttributeTerm;

/**
 * 260728-fwx T2 — production {@see SpecTermVocabulary} backed by the local
 * `woo_attribute_terms` mirror (populated READ-ONLY from Woo by T1's nightly
 * `spec:sync-taxonomy-cache`).
 *
 * The whole table is loaded ONCE per instance and grouped by attribute_id, so
 * resolving a product's spec set is a handful of array lookups — no Woo call,
 * no per-row query. This class performs NO Woo I/O: it reads only the local
 * mirror T1 already synced.
 */
final class WooAttributeTermVocabulary implements SpecTermVocabulary
{
    /** @var array<int, array<int, array{term_id:int, term_name:string, term_slug:string|null}>>|null */
    private ?array $byAttribute = null;

    public function termsFor(int $attributeId): array
    {
        $this->byAttribute ??= $this->load();

        return $this->byAttribute[$attributeId] ?? [];
    }

    /**
     * @return array<int, array<int, array{term_id:int, term_name:string, term_slug:string|null}>>
     */
    private function load(): array
    {
        $out = [];
        WooAttributeTerm::query()
            ->select(['attribute_id', 'term_id', 'term_name', 'term_slug'])
            ->orderBy('attribute_id')
            ->orderBy('term_id')
            ->each(function (WooAttributeTerm $row) use (&$out): void {
                $out[(int) $row->attribute_id][] = [
                    'term_id' => (int) $row->term_id,
                    'term_name' => (string) $row->term_name,
                    'term_slug' => $row->term_slug !== null ? (string) $row->term_slug : null,
                ];
            });

        return $out;
    }
}
