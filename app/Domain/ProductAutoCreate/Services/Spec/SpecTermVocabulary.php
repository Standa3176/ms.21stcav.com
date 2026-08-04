<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services\Spec;

/**
 * 260728-fwx T2 — the term-vocabulary seam the SpecTaxonomyResolver reads to
 * RESOLVE (never invent) a raw spec value against the CURRENT live term list
 * of a global `pa_*` attribute.
 *
 * Injecting this contract (rather than querying Woo or the DB inside the
 * resolver) keeps the resolver a pure classifier: unit tests supply an
 * {@see ArraySpecTermVocabulary} with a hand-built vocabulary — no Woo, no DB,
 * full control over the cache. Production wires the Eloquent-backed
 * {@see WooAttributeTermVocabulary} (fed nightly by T1's spec:sync-taxonomy-cache).
 */
interface SpecTermVocabulary
{
    /**
     * Every cached term for a global attribute id, or [] when the attribute is
     * uncached / unknown.
     *
     * @return array<int, array{term_id:int, term_name:string, term_slug:string|null}>
     */
    public function termsFor(int $attributeId): array;
}
