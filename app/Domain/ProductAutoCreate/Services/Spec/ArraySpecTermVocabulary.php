<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services\Spec;

/**
 * 260728-fwx T2 — in-memory {@see SpecTermVocabulary} backed by a plain array.
 *
 * The default seam for unit tests (no Woo, no DB) and for any caller that has
 * already loaded a term list and wants to hand it to the resolver directly.
 */
final class ArraySpecTermVocabulary implements SpecTermVocabulary
{
    /** @var array<int, array<int, array{term_id:int, term_name:string, term_slug:string|null}>> */
    private array $byAttribute;

    /**
     * @param  array<int, array<int, array{term_id:int, term_name:string, term_slug?:string|null}>>  $byAttribute
     *                                                                                                             attribute_id => list of {term_id, term_name, term_slug?}
     */
    public function __construct(array $byAttribute = [])
    {
        $normalised = [];
        foreach ($byAttribute as $attributeId => $terms) {
            foreach ($terms as $term) {
                $normalised[(int) $attributeId][] = [
                    'term_id' => (int) $term['term_id'],
                    'term_name' => (string) $term['term_name'],
                    'term_slug' => isset($term['term_slug']) ? (string) $term['term_slug'] : null,
                ];
            }
        }
        $this->byAttribute = $normalised;
    }

    public function termsFor(int $attributeId): array
    {
        return $this->byAttribute[$attributeId] ?? [];
    }
}
