<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\ProductAutoCreate\Services\Spec\SpecTermVocabulary;
use Illuminate\Support\Facades\Log;

/**
 * 260728-fwx T2 — single source of truth turning a product's raw spec rows
 * (Claude-generated `attributes_json` = {name,value}[]) into a classified plan
 * ({@see ResolvedSpec}): which rows become global `pa_*` TAXONOMY attributes,
 * which stay LOCAL spec rows, and which are UNMATCHED (logged, never sent).
 *
 * WHY it exists: the operator moved 44 attributes to global `pa_*` taxonomies
 * so FacetWP can filter them. New products must attach filterable specs as
 * term-linked taxonomy attributes — but Woo REST AUTO-CREATES an unknown term
 * if you send an option string it doesn't recognise, which re-pollutes the
 * cleaned facets. So the hard rule is RESOLVE-DON'T-INVENT: a value is only
 * ever sent as a taxonomy term when it resolves to an EXISTING term in the
 * injected vocabulary (T1's cache). Anything else is UNMATCHED and withheld.
 *
 * PURE CLASSIFIER: this service performs NO Woo calls and NO writes. Its only
 * dependency is the injected {@see SpecTermVocabulary} (the live term list) —
 * making it fully unit-testable with a hand-built vocabulary.
 *
 * Operator decisions honoured:
 *  - D1: bands are the filters (pa_screen-size-band / pa_brightness-nits /
 *    pa_brightness-lumens / pa_room-size-band). The EXACT brightness figures
 *    stay LOCAL. A product carries lumens OR cd/m², never both — the
 *    never-mix-units guard drops+logs the second unit.
 */
class SpecTaxonomyResolver
{
    /**
     * The 44-map, keyed by NORMALISED Woo label → [slug, attribute_id].
     * (43 filterable taxonomies; the 44th — exact `pa_brightness-cdm2` — is
     * LOCAL per D1 and lives in {@see self::LOCAL_FORCED_LABELS}.)
     *
     * @var array<string, array{0:string, 1:int}>
     */
    private const LABEL_MAP = [
        'resolution' => ['pa_resolution', 3429],
        'display size band' => ['pa_screen-size-band', 3516],
        'mount type' => ['pa_mount-type', 3517],
        'connectivity' => ['pa_connectivity', 3273],
        'brightness band cd m2' => ['pa_brightness-nits', 3518],
        'brightness band lumens' => ['pa_brightness-lumens', 3554],
        'warranty' => ['pa_warranty', 3498],
        'hdr' => ['pa_hdr-support', 3519],
        'display technology' => ['pa_display-tech', 3520],
        'refresh rate' => ['pa_refresh-rate-hz', 3521],
        'viewing angle' => ['pa_viewing-angle-deg', 3524],
        'panel type' => ['pa_panel-type', 3543],
        'touchscreen' => ['pa_touchscreen-yn', 3550],
        'touchscreen size' => ['pa_touchscreen-size-in', 3551],
        'touch technology' => ['pa_touch-tech-2', 3540],
        'touch points' => ['pa_touch-points', 3541],
        'projection technology' => ['pa_projection-tech', 3529],
        'throw type' => ['pa_throw-type-2', 3544],
        'light source' => ['pa_light-source', 3542],
        'lens shift' => ['pa_lens-shift-2', 3530],
        'screen type' => ['pa_screen-type-2', 3526],
        'tensioning' => ['pa_tab-tensioned', 3539],
        'movement' => ['pa_movement', 3522],
        'vesa' => ['pa_vesa-standard', 3533],
        'max load' => ['pa_max-load-kg', 3547],
        'quick release' => ['pa_quick-release-2', 3532],
        'material' => ['pa_material', 3364],
        'colour' => ['pa_colour', 3268],
        'length' => ['pa_cable-length', 3534],
        'cable category' => ['pa_cable-category', 3538],
        'connector type' => ['pa_connector-type', 3535],
        'shielding' => ['pa_shielding-2', 3537],
        'fire rating' => ['pa_fire-rating', 3536],
        'impedance' => ['pa_impedance-ohms-2', 3523],
        'power output' => ['pa_power-output-w', 3549],
        'speaker type' => ['pa_speaker-type-2', 3545],
        'noise cancellation' => ['pa_noise-cancelling', 3527],
        'noise level' => ['pa_noise-level-db', 3528],
        'microphone' => ['pa_microphone-type-2', 3525],
        'ip rating' => ['pa_ip-rating', 3546],
        'field of view' => ['pa_field-of-view-deg', 3548],
        'platform certification' => ['pa_platform-certified', 3552],
        'room size' => ['pa_room-size-band', 3553],
    ];

    /**
     * Tolerant alias table for common Claude label variants. NORMALISED alias
     * → NORMALISED canonical key in {@see self::LABEL_MAP}.
     *
     * @var array<string, string>
     */
    private const LABEL_ALIASES = [
        'screen resolution' => 'resolution',
        'display resolution' => 'resolution',
        'native resolution' => 'resolution',
        'display size' => 'display size band',
        'screen size' => 'display size band',
        'diagonal size' => 'display size band',
        'connectivity options' => 'connectivity',
        'connections' => 'connectivity',
        'ports' => 'connectivity',
        'mounting type' => 'mount type',
        'warranty period' => 'warranty',
        'hdr support' => 'hdr',
        'display tech' => 'display technology',
        'refresh rate hz' => 'refresh rate',
        'viewing angle deg' => 'viewing angle',
        'touch technology type' => 'touch technology',
        'projection tech' => 'projection technology',
        'colour finish' => 'colour',
        'color' => 'colour',
        'color finish' => 'colour',
        'cable length' => 'length',
        'max load kg' => 'max load',
        'maximum load' => 'max load',
        'weight capacity' => 'max load',
        'power output w' => 'power output',
        'field of view deg' => 'field of view',
        'fov' => 'field of view',
        'platform certified' => 'platform certification',
        'platform certifications' => 'platform certification',
        'room size band' => 'room size',
    ];

    /**
     * Spec-only labels that must NEVER become a taxonomy — forced LOCAL even
     * when numeric (D1 + brief). Keys are NORMALISED labels.
     *
     * @var list<string>
     */
    private const LOCAL_FORCED_LABELS = [
        'mpn',
        'model',
        'part number',
        'brightness cd m2',   // exact cd/m² figure (pa_brightness-cdm2 3531) — LOCAL per D1
        'brightness lumens',  // exact lumens figure — LOCAL per D1
    ];

    /** @var list<string> */
    private const BAND_SLUGS = [
        'pa_screen-size-band',
        'pa_brightness-nits',
        'pa_brightness-lumens',
        'pa_room-size-band',
    ];

    /**
     * Band boundary tables — ascending [upperInclusive|null, canonicalLabel].
     * The last bucket (null) is the open-ended catch-all. Boundaries are
     * inclusive and non-overlapping; the canonical label is then RESOLVED
     * against the cache (belt-and-braces — an uncached band term is UNMATCHED).
     *
     * Labels are the EXACT live Woo term names (validated against prod
     * `woo_attribute_terms`) so the derived band links to a real cached term.
     * Boundary integers are unchanged — only the label strings carry the unit
     * suffix the live store uses (`inch` / `lumens` / `people`). The final
     * derived→cached match is ALSO unit-suffix/whitespace/case tolerant (see
     * {@see self::bandNormalise()}) so a minor drift can't silently break it.
     *
     * @var array<string, list<array{0:int|null, 1:string}>>
     */
    private const BAND_TABLES = [
        // Screen size (inches): live pa_screen-size-band (3516) term names.
        'pa_screen-size-band' => [
            [22, 'Up to 22 inch'],
            [27, '23-27 inch'],
            [34, '28-34 inch'],
            [43, '35-43 inch'],
            [55, '44-55 inch'],
            [65, '56-65 inch'],
            [75, '66-75 inch'],
            [85, '76-85 inch'],
            [null, '86 inch and above'],
        ],
        // Brightness cd/m²: live pa_brightness-nits (3518) term names (already
        // matched the store — left unchanged). Boundary: 2500 → High bright,
        // 2501 → Window facing.
        'pa_brightness-nits' => [
            [350, 'Standard (up to 350)'],
            [700, 'Semi-bright (351-700)'],
            [2500, 'High bright (701-2500)'],
            [null, 'Window facing (2500+)'],
        ],
        // Brightness lumens: live pa_brightness-lumens (3554) term names.
        'pa_brightness-lumens' => [
            [2999, 'Under 3000 lumens'],
            [4999, '3000-4999 lumens'],
            [9999, '5000-9999 lumens'],
            [null, '10000+ lumens'],
        ],
        // Room size (people): live pa_room-size-band (3553) term names. Brief
        // boundaries overlap at 4/6/10 — tie-break: the LOWER band wins at a
        // shared boundary (ascending upper-inclusive).
        'pa_room-size-band' => [
            [4, 'Huddle (2-4 people)'],
            [6, 'Small (4-6 people)'],
            [10, 'Medium (6-10 people)'],
            [null, 'Large (10+ people)'],
        ],
    ];

    /**
     * Companion LOCAL spec label for a band attribute — the exact raw figure is
     * ALSO emitted as a local row under this label (brief: exact
     * "Brightness (cd/m²)").
     *
     * @var array<string, string>
     */
    private const BAND_COMPANION_LABEL = [
        'pa_screen-size-band' => 'Display Size',
        'pa_brightness-nits' => 'Brightness (cd/m²)',
        'pa_brightness-lumens' => 'Brightness (lumens)',
        'pa_room-size-band' => 'Room Size',
    ];

    /**
     * Per-attribute VALUE-ALIAS maps: NORMALISED raw value → canonical term
     * NAME (which must itself exist in the cache). Keyed by attribute slug.
     *
     * @var array<string, array<string, string>>
     */
    private const VALUE_ALIASES = [
        'pa_resolution' => [
            '4k' => '4K UHD (3840x2160)',
            '4k uhd' => '4K UHD (3840x2160)',
            'uhd' => '4K UHD (3840x2160)',
            '3840x2160' => '4K UHD (3840x2160)',
            '4k@60hz' => '4K UHD (3840x2160)',
            '4k 60hz' => '4K UHD (3840x2160)',
            '1080p' => 'Full HD (1920x1080)',
            'fhd' => 'Full HD (1920x1080)',
            'full hd' => 'Full HD (1920x1080)',
            '1920x1080' => 'Full HD (1920x1080)',
        ],
    ];

    public function __construct(private SpecTermVocabulary $vocabulary) {}

    /**
     * Classify a product's WHOLE raw spec set into global / local / unmatched.
     * Operates on the whole set so cross-row rules (never-mix-units) can fire.
     *
     * @param  array<int, array{name?:mixed, value?:mixed}>  $rows  attributes_json entries
     */
    public function resolve(array $rows): ResolvedSpec
    {
        $entries = $this->prepare($rows);
        $droppedUnit = $this->droppedBrightnessUnit($entries);

        $global = [];
        $local = [];
        $unmatched = [];

        foreach ($entries as $entry) {
            // Cross-row guard (D1): the second brightness unit is dropped+logged.
            if ($droppedUnit !== null && $entry['brightness_unit'] === $droppedUnit) {
                $unmatched[] = $this->unmatched(
                    $entry['raw_label'],
                    $entry['raw_value'],
                    $this->brightnessSlug($droppedUnit),
                    'mixed_units',
                );

                continue;
            }

            // Spec-only labels (MPN / Model / Part Number / exact brightness) —
            // LOCAL even if numeric (D1).
            if ($entry['local_forced']) {
                $local[] = ['name' => $entry['raw_label'], 'value' => $entry['raw_value']];

                continue;
            }

            // Label not in the 44-map (nor an alias) → LOCAL spec passthrough.
            if ($entry['slug'] === null) {
                $local[] = ['name' => $entry['raw_label'], 'value' => $entry['raw_value']];

                continue;
            }

            // Band attributes: derive the band from the leading numeric, resolve
            // the band label to an EXISTING term, and emit the exact figure as a
            // LOCAL companion row.
            if (in_array($entry['slug'], self::BAND_SLUGS, true)) {
                $this->resolveBand($entry, $global, $local, $unmatched);

                continue;
            }

            // Regular taxonomy: resolve value → existing term (exact / ci / alias).
            $term = $this->resolveTermName(
                $entry['attribute_id'],
                $entry['raw_value'],
                self::VALUE_ALIASES[$entry['slug']] ?? [],
            );

            if ($term === null) {
                $unmatched[] = $this->unmatched(
                    $entry['raw_label'],
                    $entry['raw_value'],
                    $entry['slug'],
                    'value_not_a_term',
                );

                continue;
            }

            $global[] = $this->global($entry['attribute_id'], $entry['slug'], $term, $entry['raw_label'], $entry['raw_value']);
        }

        return new ResolvedSpec($global, $local, $unmatched);
    }

    /**
     * Normalise + pre-classify each row into a working entry. Blank name/value
     * rows are skipped entirely (nothing to classify).
     *
     * @param  array<int, array{name?:mixed, value?:mixed}>  $rows
     * @return list<array{raw_label:string, raw_value:string, norm_label:string, slug:string|null, attribute_id:int|null, local_forced:bool, brightness_unit:string|null}>
     */
    private function prepare(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawLabel = trim((string) ($row['name'] ?? ''));
            $rawValue = trim((string) ($row['value'] ?? ''));
            if ($rawLabel === '' || $rawValue === '') {
                continue;
            }

            $normLabel = $this->normaliseLabel($rawLabel);
            $localForced = in_array($normLabel, self::LOCAL_FORCED_LABELS, true);

            $slug = null;
            $attributeId = null;
            if (! $localForced) {
                [$slug, $attributeId] = $this->lookupAttribute($normLabel);
            }

            $out[] = [
                'raw_label' => $rawLabel,
                'raw_value' => $rawValue,
                'norm_label' => $normLabel,
                'slug' => $slug,
                'attribute_id' => $attributeId,
                'local_forced' => $localForced,
                'brightness_unit' => $this->brightnessUnit($normLabel, $slug),
            ];
        }

        return $out;
    }

    /**
     * Resolve a normalised label to [slug, attribute_id] via the 44-map + alias
     * table, or [null, null] when it isn't a known taxonomy.
     *
     * @return array{0:string|null, 1:int|null}
     */
    private function lookupAttribute(string $normLabel): array
    {
        if (isset(self::LABEL_MAP[$normLabel])) {
            return self::LABEL_MAP[$normLabel];
        }
        if (isset(self::LABEL_ALIASES[$normLabel])) {
            return self::LABEL_MAP[self::LABEL_ALIASES[$normLabel]];
        }

        return [null, null];
    }

    /**
     * Which brightness unit ('nits' | 'lumens') a row carries, or null. Covers
     * both the band labels and the exact spec-only labels.
     */
    private function brightnessUnit(string $normLabel, ?string $slug): ?string
    {
        if ($slug === 'pa_brightness-nits' || $normLabel === 'brightness cd m2') {
            return 'nits';
        }
        if ($slug === 'pa_brightness-lumens' || $normLabel === 'brightness lumens') {
            return 'lumens';
        }

        return null;
    }

    /**
     * D1 never-mix-units: if the set carries BOTH units, the FIRST brightness
     * row (row order) wins and the OTHER unit is dropped. Tie-break documented:
     * the resolver has no category context, so first-seen wins.
     *
     * @param  list<array{brightness_unit:string|null}>  $entries
     */
    private function droppedBrightnessUnit(array $entries): ?string
    {
        $seen = [];
        $firstUnit = null;
        foreach ($entries as $entry) {
            $unit = $entry['brightness_unit'];
            if ($unit === null) {
                continue;
            }
            $seen[$unit] = true;
            $firstUnit ??= $unit;
        }

        // Only a genuine conflict (both units present) triggers a drop.
        if ($firstUnit === null || count($seen) < 2) {
            return null;
        }

        return $firstUnit === 'nits' ? 'lumens' : 'nits';
    }

    private function brightnessSlug(string $unit): string
    {
        return $unit === 'nits' ? 'pa_brightness-nits' : 'pa_brightness-lumens';
    }

    /**
     * Derive → resolve → emit for a band attribute. Appends to the buckets by
     * reference so the mixed-units + companion logic stays in resolve().
     *
     * @param  array{raw_label:string, raw_value:string, slug:string|null, attribute_id:int|null}  $entry
     * @param  array<int, array<string, mixed>>  $global
     * @param  array<int, array<string, mixed>>  $local
     * @param  array<int, array<string, mixed>>  $unmatched
     */
    private function resolveBand(array $entry, array &$global, array &$local, array &$unmatched): void
    {
        /** @var string $slug */
        $slug = $entry['slug'];
        /** @var int $attributeId */
        $attributeId = $entry['attribute_id'];

        $number = $this->leadingNumeric($entry['raw_value']);
        if ($number === null) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $slug, 'band_value_not_numeric');

            return;
        }

        $bandLabel = $this->deriveBandLabel($slug, $number);

        // Belt-and-braces: the derived band label must exist as a cached term.
        // Tolerant match (exact → ci → unit/whitespace/case-normalised) so a
        // minor unit-suffix drift can't silently break the link — but still
        // RESOLVE-DON'T-INVENT (null → unmatched, never fabricated).
        $term = $this->resolveBandTerm($attributeId, $bandLabel);
        if ($term === null) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $slug, 'band_term_not_cached');

            return;
        }

        $global[] = $this->global($attributeId, $slug, $term, $entry['raw_label'], $entry['raw_value']);

        // The exact raw figure ALSO stays as a LOCAL companion spec row.
        $local[] = [
            'name' => self::BAND_COMPANION_LABEL[$slug] ?? $entry['raw_label'],
            'value' => $entry['raw_value'],
        ];
    }

    /**
     * Map a numeric to its band label via the ascending upper-inclusive table.
     */
    private function deriveBandLabel(string $slug, float $number): string
    {
        $table = self::BAND_TABLES[$slug];
        foreach ($table as [$upper, $label]) {
            if ($upper === null || $number <= $upper) {
                return $label;
            }
        }

        // Unreachable (last bucket is always the null catch-all) — fall back to
        // the final label defensively.
        return $table[count($table) - 1][1];
    }

    /**
     * RESOLVE-DON'T-INVENT: return the cached term matching $rawValue, or null.
     * Order: exact term_name → case-insensitive/whitespace-normalised → value
     * alias → canonical term name (exact / ci). null means UNMATCHED.
     *
     * @param  array<string, string>  $valueAliases  normalised raw value → canonical term name
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function resolveTermName(int $attributeId, string $rawValue, array $valueAliases): ?array
    {
        $terms = $this->vocabulary->termsFor($attributeId);
        if ($terms === []) {
            return null;
        }

        // 1) exact
        foreach ($terms as $term) {
            if ($term['term_name'] === $rawValue) {
                return $term;
            }
        }

        // 2) case-insensitive / whitespace-normalised
        $needle = $this->ciValue($rawValue);
        foreach ($terms as $term) {
            if ($this->ciValue($term['term_name']) === $needle) {
                return $term;
            }
        }

        // 3) per-attribute value alias → canonical term name, resolved (exact / ci)
        if (isset($valueAliases[$needle])) {
            $canonical = $valueAliases[$needle];
            $canonicalCi = $this->ciValue($canonical);
            foreach ($terms as $term) {
                if ($term['term_name'] === $canonical || $this->ciValue($term['term_name']) === $canonicalCi) {
                    return $term;
                }
            }
        }

        return null;
    }

    /**
     * RESOLVE-DON'T-INVENT for a DERIVED BAND label. The band tables carry the
     * exact live term names, but a term could still drift (unit suffix, casing,
     * whitespace) between the code and the store cache — so match tolerantly:
     * exact → case-insensitive → band-normalised (trailing unit token stripped).
     * The CACHE is the source of truth for what's sent — we return the real
     * cached term (term_name/term_id), never the internal label. null → the
     * band term genuinely isn't cached → UNMATCHED (never fabricated).
     *
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function resolveBandTerm(int $attributeId, string $bandLabel): ?array
    {
        $terms = $this->vocabulary->termsFor($attributeId);
        if ($terms === []) {
            return null;
        }

        // 1) exact
        foreach ($terms as $term) {
            if ($term['term_name'] === $bandLabel) {
                return $term;
            }
        }

        // 2) case-insensitive / whitespace-normalised
        $ci = $this->ciValue($bandLabel);
        foreach ($terms as $term) {
            if ($this->ciValue($term['term_name']) === $ci) {
                return $term;
            }
        }

        // 3) band-normalised: tolerate a differing/absent trailing unit token
        $bn = $this->bandNormalise($bandLabel);
        foreach ($terms as $term) {
            if ($this->bandNormalise($term['term_name']) === $bn) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Band-term normalisation for tolerant matching: lowercase, collapse
     * internal whitespace, then strip a SINGLE trailing unit token
     * (inch|inches|lumens|people). Deliberately narrow — it only forgives the
     * unit suffix, casing and whitespace; it never rewrites the band range, so
     * two distinct bands can't collapse onto one another.
     */
    private function bandNormalise(string $value): string
    {
        $value = $this->ciValue($value);

        return trim((string) preg_replace('/\s*(?:inch|inches|lumens|people)$/', '', $value));
    }

    /**
     * Build a global bucket entry.
     *
     * @param  array{term_id:int, term_name:string, term_slug:string|null}  $term
     * @return array{attribute_id:int, attribute_slug:string, term_id:int, term_name:string, raw_label:string, raw_value:string}
     */
    private function global(int $attributeId, string $slug, array $term, string $rawLabel, string $rawValue): array
    {
        return [
            'attribute_id' => $attributeId,
            'attribute_slug' => $slug,
            'term_id' => $term['term_id'],
            'term_name' => $term['term_name'],
            'raw_label' => $rawLabel,
            'raw_value' => $rawValue,
        ];
    }

    /**
     * Build an unmatched bucket entry AND log it (structured) — these are
     * withheld from Woo and surfaced for the T6 report.
     *
     * @return array{raw_label:string, raw_value:string, attribute_slug:string, reason:string}
     */
    private function unmatched(string $rawLabel, string $rawValue, string $slug, string $reason): array
    {
        $row = [
            'raw_label' => $rawLabel,
            'raw_value' => $rawValue,
            'attribute_slug' => $slug,
            'reason' => $reason,
        ];

        Log::warning('spec_taxonomy.unmatched', $row);

        return $row;
    }

    /**
     * Leading numeric of a raw value ("55 inch" → 55.0, "2,500 cd/m²" → 2500.0),
     * or null when the value doesn't start with a number.
     */
    private function leadingNumeric(string $value): ?float
    {
        $clean = str_replace(',', '', trim($value));
        if (preg_match('/^-?\d+(?:\.\d+)?/', $clean, $m) === 1) {
            return (float) $m[0];
        }

        return null;
    }

    /**
     * Label normalisation for MAP + ALIAS matching: '²'→'2', lowercase, strip
     * punctuation to single spaces, collapse whitespace.
     */
    private function normaliseLabel(string $label): string
    {
        $label = str_replace(['²', '³'], ['2', '3'], $label);
        $label = mb_strtolower(trim($label));
        $label = (string) preg_replace('/[^a-z0-9]+/', ' ', $label);

        return trim((string) preg_replace('/\s+/', ' ', $label));
    }

    /**
     * Value normalisation for term matching: lowercase + collapse internal
     * whitespace. Punctuation is PRESERVED (term names carry meaningful
     * parens/×, e.g. "4K UHD (3840x2160)").
     */
    private function ciValue(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(trim($value))));
    }
}
