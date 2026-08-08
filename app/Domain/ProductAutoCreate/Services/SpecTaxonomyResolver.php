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

    /**
     * Numeric BAND attributes handled by {@see self::resolveBand()}. NOTE: room
     * size is band-derivable too, but it is MULTI-VALUE + text-mapped (T10 §2)
     * so it has its own {@see self::resolveRoomSize()} dispatch and is NOT here.
     *
     * @var list<string>
     */
    private const BAND_SLUGS = [
        'pa_screen-size-band',
        'pa_brightness-nits',
        'pa_brightness-lumens',
    ];

    /** Room-size slug — dedicated multi-value + text-map dispatch (T10 §2). */
    private const ROOM_SIZE_SLUG = 'pa_room-size-band';

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
        // T11 §3 — six bands. The two OLD bands "3000-4999 lumens" and
        // "5000-9999 lumens" are RETIRED and must never be derived into.
        'pa_brightness-lumens' => [
            [2999, 'Under 3000 lumens'],
            [3999, '3000-3999 lumens'],
            [4999, '4000-4999 lumens'],
            [6999, '5000-6999 lumens'],
            [9999, '7000-9999 lumens'],
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
     * Tolerant label aliases (config `label_aliases`), merged OVER the built-in
     * {@see self::LABEL_ALIASES} defaults so an operator can extend coverage in
     * config/spec_taxonomy.php without a code change.
     *
     * @var array<string, string>
     */
    private array $labelAliases;

    /**
     * Labels whose TARGET attribute depends on the UNIT in the value
     * (config `unit_routed_labels`). E.g. `brightness` → lumens vs cd/m².
     *
     * @var array<string, array<string, string>>
     */
    private array $unitRoutedLabels;

    /**
     * Per-attribute value-normaliser tables (config `value_normalisers`),
     * keyed by slug. The SMART LOGIC lives in this class; these are the
     * operator-editable lookup tables it consumes.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $valueNormalisers;

    /**
     * Slugs whose single raw value may carry MANY terms (config `multi_value`).
     *
     * @var list<string>
     */
    private array $multiValueSlugs;

    /**
     * NORMALISED labels dropped ENTIRELY — never global/local/unmatched
     * (config `drop_labels`, e.g. EAN → native WooCommerce GTIN field).
     *
     * @var list<string>
     */
    private array $dropLabels;

    /**
     * Labels routed to an attribute ONLY when the VALUE matches a pattern
     * (config `value_conditional_labels`); else LOCAL. E.g. `Cable Type` →
     * Cable Category only for a CatN value.
     *
     * @var array<string, array<string, string>>
     */
    private array $valueConditionalLabels;

    /**
     * A VALUE under one attribute's label that actually belongs to a DIFFERENT
     * attribute (config `value_reroutes`), keyed by SOURCE slug → target spec.
     * E.g. a bare `Fixed`/`Tilt` under a Mount label → the Movement attribute.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $valueReroutes;

    /**
     * Max Load dual-emit config (config `max_load`): one value → exact
     * pa_max-load-kg + derived pa_max-load-band (T11 §2).
     *
     * @var array<string, mixed>
     */
    private array $maxLoad;

    /**
     * Touchscreen boolean + 3-way-split config (config `touchscreen`, T11 §4).
     *
     * @var array<string, mixed>
     */
    private array $touchscreen;

    /**
     * @param  array<string, mixed>|null  $config  overrides config('spec_taxonomy') (tests)
     */
    public function __construct(private SpecTermVocabulary $vocabulary, ?array $config = null)
    {
        $config ??= (array) (function_exists('config') ? config('spec_taxonomy', []) : []);

        /** @var array<string, string> $aliases */
        $aliases = $config['label_aliases'] ?? [];
        $this->labelAliases = array_merge(self::LABEL_ALIASES, $aliases);

        /** @var array<string, array<string, string>> $routed */
        $routed = $config['unit_routed_labels'] ?? [];
        $this->unitRoutedLabels = $routed;

        /** @var array<string, array<string, mixed>> $normalisers */
        $normalisers = $config['value_normalisers'] ?? [];
        $this->valueNormalisers = $normalisers;

        /** @var list<string> $multi */
        $multi = $config['multi_value'] ?? [];
        $this->multiValueSlugs = $multi;

        /** @var list<string> $drop */
        $drop = $config['drop_labels'] ?? [];
        $this->dropLabels = array_map(fn (string $l): string => $this->normaliseLabel($l), $drop);

        /** @var array<string, array<string, string>> $conditional */
        $conditional = $config['value_conditional_labels'] ?? [];
        $this->valueConditionalLabels = $conditional;

        /** @var array<string, array<string, mixed>> $reroutes */
        $reroutes = $config['value_reroutes'] ?? [];
        $this->valueReroutes = $reroutes;

        /** @var array<string, mixed> $maxLoad */
        $maxLoad = $config['max_load'] ?? [];
        $this->maxLoad = $maxLoad;

        /** @var array<string, mixed> $touchscreen */
        $touchscreen = $config['touchscreen'] ?? [];
        $this->touchscreen = $touchscreen;
    }

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

            // Room size (T10 §2): MULTI-VALUE, text-mapped OR numeric-derived,
            // emitting all applicable bands (sorted small→large) plus the raw
            // figure as a LOCAL companion.
            if ($entry['slug'] === self::ROOM_SIZE_SLUG) {
                $this->resolveRoomSize($entry, $global, $local, $unmatched);

                continue;
            }

            // Band attributes: derive the band from the leading numeric, resolve
            // the band label to an EXISTING term, and emit the exact figure as a
            // LOCAL companion row.
            if (in_array($entry['slug'], self::BAND_SLUGS, true)) {
                $this->resolveBand($entry, $global, $local, $unmatched);

                continue;
            }

            // Max Load (T11 §2): ONE value → TWO global rows (exact kg + band).
            if (($this->maxLoad['exact_slug'] ?? null) === $entry['slug']) {
                $this->resolveMaxLoad($entry, $global, $unmatched);

                continue;
            }

            // Touchscreen (T11 §4): boolean Yes/No + optional 3-way split
            // (Touch Points / Touch Technology) when the value carries them.
            if (($this->touchscreen['slug'] ?? null) === $entry['slug']) {
                $this->resolveTouchscreen($entry, $global, $unmatched);

                continue;
            }

            // Value re-routing (T10 §3): a value under this label may belong to a
            // DIFFERENT attribute (e.g. a bare "Fixed"/"Tilt" under a Mount label
            // → Movement). Only fires when the value is NOT a genuine source
            // value AND IS a target value — so real mounts stay mounts.
            if (isset($this->valueReroutes[$entry['slug']])) {
                $reroute = $this->rerouteTarget($entry['slug'], $entry['raw_value']);
                if ($reroute !== null) {
                    [$targetSlug, $targetAttributeId] = $reroute;
                    $term = $this->resolveValue($targetSlug, $targetAttributeId, $entry['raw_value']);
                    if ($term === null) {
                        $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $targetSlug, 'value_not_a_term');
                    } else {
                        $global[] = $this->globalRow($targetAttributeId, $targetSlug, [$term], $entry['raw_label'], $entry['raw_value']);
                    }

                    continue;
                }
            }

            // Multi-value taxonomy (e.g. Connectivity): one raw value → many
            // terms. Handled separately so a partly-resolvable value still emits
            // the tokens that DO resolve (the rest are logged unmatched).
            if (in_array($entry['slug'], $this->multiValueSlugs, true)) {
                $this->resolveMultiValue($entry, $global, $unmatched);

                continue;
            }

            // Regular taxonomy: resolve value → existing term (exact / ci /
            // per-attribute normaliser candidate → existing term).
            $term = $this->resolveValue($entry['slug'], $entry['attribute_id'], $entry['raw_value']);

            if ($term === null) {
                $unmatched[] = $this->unmatched(
                    $entry['raw_label'],
                    $entry['raw_value'],
                    $entry['slug'],
                    'value_not_a_term',
                );

                continue;
            }

            $global[] = $this->globalRow($entry['attribute_id'], $entry['slug'], [$term], $entry['raw_label'], $entry['raw_value']);
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

            // Dropped labels (T10 §4b): EAN etc. never surface anywhere.
            if (in_array($normLabel, $this->dropLabels, true)) {
                continue;
            }

            $localForced = in_array($normLabel, self::LOCAL_FORCED_LABELS, true);

            $slug = null;
            $attributeId = null;
            if (! $localForced) {
                [$slug, $attributeId] = $this->lookupAttribute($normLabel);

                // Unit-routed labels (e.g. `brightness`): the target attribute is
                // decided by the UNIT in the value, not the label. Only applies
                // when the label isn't already a direct map/alias hit.
                if ($slug === null) {
                    [$slug, $attributeId] = $this->routeByUnit($normLabel, $rawValue);
                }

                // Value-conditional labels (T10 §4): e.g. `Cable Type` is only a
                // Cable Category when the VALUE is an actual CatN grade; else LOCAL.
                if ($slug === null) {
                    [$slug, $attributeId] = $this->routeByValuePattern($normLabel, $rawValue);
                }
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
        if (isset($this->labelAliases[$normLabel]) && isset(self::LABEL_MAP[$this->labelAliases[$normLabel]])) {
            return self::LABEL_MAP[$this->labelAliases[$normLabel]];
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

        $global[] = $this->globalRow($attributeId, $slug, [$term], $entry['raw_label'], $entry['raw_value']);

        // The exact raw figure ALSO stays as a LOCAL companion spec row. For
        // lumens the companion is canonicalised to "{n} ANSI lumens" (T11 §3);
        // every other band keeps the raw figure verbatim.
        $local[] = [
            'name' => self::BAND_COMPANION_LABEL[$slug] ?? $entry['raw_label'],
            'value' => $this->bandCompanionValue($slug, $number, $entry['raw_value']),
        ];
    }

    /**
     * The LOCAL companion value for a band row. Lumens (T11 §3) canonicalises to
     * "{n} ANSI lumens"; all other bands keep the exact raw figure verbatim.
     */
    private function bandCompanionValue(string $slug, float $number, string $rawValue): string
    {
        if ($slug === 'pa_brightness-lumens') {
            return $this->formatNumber($number).' ANSI lumens';
        }

        return $rawValue;
    }

    /**
     * Format a numeric for a canonical value string: drop a ".0" on whole
     * numbers ("70.0" → "70") but keep genuine decimals ("12.5" → "12.5").
     */
    private function formatNumber(float $number): string
    {
        if ($number === floor($number) && is_finite($number)) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(sprintf('%.4f', $number), '0'), '.');
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
     * Max Load (T11 §2): one value → TWO global rows. Parse the leading numeric
     * kg, then emit (a) the EXACT figure canonicalised to "{n} kg" under
     * pa_max-load-kg and (b) the DERIVED band under pa_max-load-band. Each is
     * independently resolve-don't-invent: an uncached candidate is logged
     * unmatched (never sent) without blocking the other row.
     *
     * @param  array{raw_label:string, raw_value:string, slug:string|null, attribute_id:int|null}  $entry
     * @param  array<int, array<string, mixed>>  $global
     * @param  array<int, array<string, mixed>>  $unmatched
     */
    private function resolveMaxLoad(array $entry, array &$global, array &$unmatched): void
    {
        $exactSlug = (string) ($this->maxLoad['exact_slug'] ?? 'pa_max-load-kg');
        $exactId = (int) ($this->maxLoad['exact_attribute_id'] ?? ($entry['attribute_id'] ?? 0));
        $bandSlug = (string) ($this->maxLoad['band_slug'] ?? 'pa_max-load-band');
        $bandId = (int) ($this->maxLoad['band_attribute_id'] ?? 0);
        /** @var list<array{0:int|null, 1:string}> $bands */
        $bands = $this->maxLoad['bands'] ?? [];

        $kg = $this->leadingNumeric($entry['raw_value']);
        if ($kg === null) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $exactSlug, 'band_value_not_numeric');

            return;
        }

        // (a) EXACT → canonical "{n} kg" (space before unit), resolved.
        $exactCandidate = $this->formatNumber($kg).' kg';
        $exactTerm = $this->resolveValue($exactSlug, $exactId, $exactCandidate);
        if ($exactTerm === null) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $exactSlug, 'value_not_a_term');
        } else {
            $global[] = $this->globalRow($exactId, $exactSlug, [$exactTerm], $entry['raw_label'], $entry['raw_value']);
        }

        // (b) DERIVED band → resolved (tolerant, resolve-don't-invent).
        $bandLabel = null;
        foreach ($bands as [$upper, $label]) {
            if ($upper === null || $kg <= $upper) {
                $bandLabel = $label;

                break;
            }
        }
        if ($bandLabel === null || $bandId <= 0) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $bandSlug, 'band_term_not_cached');

            return;
        }

        $bandTerm = $this->resolveBandTerm($bandId, $bandLabel);
        if ($bandTerm === null) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $entry['raw_value'], $bandSlug, 'band_term_not_cached');

            return;
        }

        $global[] = $this->globalRow($bandId, $bandSlug, [$bandTerm], $entry['raw_label'], $entry['raw_value']);
    }

    /**
     * Touchscreen (T11 §4): BOOLEAN facet with an optional 3-way split. An
     * explicit Yes/No (or a negative descriptor → No) is honoured; any other
     * value that DESCRIBES a touchscreen resolves to Yes. When the value also
     * carries a point count and/or a touch technology, additional
     * pa_touch-points / pa_touch-tech-2 rows are emitted — ONLY when they
     * resolve to a cached term (resolve-don't-invent).
     *
     * @param  array{raw_label:string, raw_value:string, slug:string|null, attribute_id:int|null}  $entry
     * @param  array<int, array<string, mixed>>  $global
     * @param  array<int, array<string, mixed>>  $unmatched
     */
    private function resolveTouchscreen(array $entry, array &$global, array &$unmatched): void
    {
        /** @var int $attributeId */
        $attributeId = $entry['attribute_id'];
        $rawValue = $entry['raw_value'];
        $lower = mb_strtolower($rawValue);

        $yesTerm = (string) ($this->touchscreen['yes_term'] ?? 'Yes');
        $noTerm = (string) ($this->touchscreen['no_term'] ?? 'No');
        /** @var list<string> $negatives */
        $negatives = $this->touchscreen['negative_keywords'] ?? [];
        /** @var list<string> $touchKeywords */
        $touchKeywords = $this->touchscreen['touch_keywords'] ?? [];

        $terms = $this->vocabulary->termsFor($attributeId);

        // Explicit verbatim Yes/No wins (keeps a plain "Yes"/"No" as-is).
        $direct = $terms === [] ? null : $this->matchTerm($terms, $rawValue);

        // Negative descriptor ("Non-touch"/"No touch") → No.
        $isNegative = false;
        foreach ($negatives as $needle) {
            if (mb_strpos($lower, mb_strtolower((string) $needle)) !== false) {
                $isNegative = true;

                break;
            }
        }

        if ($direct !== null) {
            $global[] = $this->globalRow($attributeId, $entry['slug'] ?? 'pa_touchscreen-yn', [$direct], $entry['raw_label'], $rawValue);
            // A plain Yes/No carries no extra facets — but a value like
            // "Yes, 20-point PCAP" still splits below.
            if ($this->ciValue($rawValue) === $this->ciValue($yesTerm) || $this->ciValue($rawValue) === $this->ciValue($noTerm)) {
                return;
            }
        } elseif ($isNegative) {
            $noHit = $terms === [] ? null : $this->matchTerm($terms, $noTerm);
            if ($noHit === null) {
                $unmatched[] = $this->unmatched($entry['raw_label'], $rawValue, $entry['slug'] ?? 'pa_touchscreen-yn', 'value_not_a_term');

                return;
            }
            $global[] = $this->globalRow($attributeId, $entry['slug'] ?? 'pa_touchscreen-yn', [$noHit], $entry['raw_label'], $rawValue);

            return;
        }

        // Extract the optional Touch Points / Touch Technology candidates.
        $pointsCandidate = $this->extractTouchPoints($rawValue);
        $techCandidate = $this->firstKeywordHit($lower, $this->touchscreen['tech_keywords'] ?? []);

        // Detect a touchscreen: a "touch" keyword, a point count, or a touch tech.
        $describesTouch = $pointsCandidate !== null || $techCandidate !== null;
        if (! $describesTouch) {
            foreach ($touchKeywords as $needle) {
                if (mb_strpos($lower, mb_strtolower((string) $needle)) !== false) {
                    $describesTouch = true;

                    break;
                }
            }
        }

        // Emit Touchscreen=Yes when the value describes a touchscreen (unless the
        // verbatim Yes/No already emitted above).
        if ($direct === null) {
            if (! $describesTouch) {
                $unmatched[] = $this->unmatched($entry['raw_label'], $rawValue, $entry['slug'] ?? 'pa_touchscreen-yn', 'value_not_a_term');

                return;
            }
            $yesHit = $terms === [] ? null : $this->matchTerm($terms, $yesTerm);
            if ($yesHit === null) {
                $unmatched[] = $this->unmatched($entry['raw_label'], $rawValue, $entry['slug'] ?? 'pa_touchscreen-yn', 'value_not_a_term');

                return;
            }
            $global[] = $this->globalRow($attributeId, $entry['slug'] ?? 'pa_touchscreen-yn', [$yesHit], $entry['raw_label'], $rawValue);
        }

        // Touch Points row (only if it resolves to a cached term).
        if ($pointsCandidate !== null) {
            $pointsSlug = (string) ($this->touchscreen['touch_points_slug'] ?? 'pa_touch-points');
            $pointsId = (int) ($this->touchscreen['touch_points_attribute_id'] ?? 0);
            $pointsTerm = $pointsId > 0 ? $this->resolveValue($pointsSlug, $pointsId, $pointsCandidate) : null;
            if ($pointsTerm !== null) {
                $global[] = $this->globalRow($pointsId, $pointsSlug, [$pointsTerm], $entry['raw_label'], $rawValue);
            }
        }

        // Touch Technology row (only if it resolves to a cached term).
        if ($techCandidate !== null) {
            $techSlug = (string) ($this->touchscreen['touch_tech_slug'] ?? 'pa_touch-tech-2');
            $techId = (int) ($this->touchscreen['touch_tech_attribute_id'] ?? 0);
            $techTerm = $techId > 0 ? $this->resolveValue($techSlug, $techId, $techCandidate) : null;
            if ($techTerm !== null) {
                $global[] = $this->globalRow($techId, $techSlug, [$techTerm], $entry['raw_label'], $rawValue);
            }
        }
    }

    /**
     * Extract a Touch Points candidate ("{n}-point") from a touchscreen value.
     * "20-point"/"20 point"/"20 points"/"20pt" → "20-point"; else null.
     */
    private function extractTouchPoints(string $value): ?string
    {
        if (preg_match('/(\d+)\s*[- ]?(?:point|points|pt)\b/i', $value, $m) === 1) {
            return $m[1].'-point';
        }

        return null;
    }

    /**
     * Room size (T10 §2): MULTI-VALUE. Split the raw value into tokens (comma /
     * ampersand / "and" — NOT slash, so "Medium/Large" stays one descriptor),
     * resolve each token via exact/ci → text-map (contains) → numeric band
     * derivation, dedupe, sort small→large by `band_rank`, and emit ONE global
     * row carrying all bands + the raw value as a LOCAL companion.
     *
     * @param  array{raw_label:string, raw_value:string, slug:string|null, attribute_id:int|null}  $entry
     * @param  array<int, array<string, mixed>>  $global
     * @param  array<int, array<string, mixed>>  $local
     * @param  array<int, array<string, mixed>>  $unmatched
     */
    private function resolveRoomSize(array $entry, array &$global, array &$local, array &$unmatched): void
    {
        $slug = self::ROOM_SIZE_SLUG;
        /** @var int $attributeId */
        $attributeId = $entry['attribute_id'];
        $rawValue = $entry['raw_value'];

        /** @var array<string, mixed> $cfg */
        $cfg = $this->valueNormalisers[$slug] ?? [];
        /** @var array<string, string> $textMap */
        $textMap = $cfg['text_map'] ?? [];
        /** @var array<string, int> $bandRank */
        $bandRank = $cfg['band_rank'] ?? [];

        $terms = $this->vocabulary->termsFor($attributeId);

        /** @var array<string, array{term_id:int, term_name:string, term_slug:string|null}> $resolved */
        $resolved = [];
        foreach ($this->splitRoomSize($rawValue) as $token) {
            $term = $this->resolveRoomToken($attributeId, $terms, $token, $textMap);
            if ($term !== null) {
                $resolved[$term['term_name']] = $term;
            }
        }

        if ($resolved === []) {
            $unmatched[] = $this->unmatched($entry['raw_label'], $rawValue, $slug, 'value_not_a_term');

            return;
        }

        $list = array_values($resolved);
        usort($list, static function (array $a, array $b) use ($bandRank): int {
            $ra = $bandRank[$a['term_name']] ?? PHP_INT_MAX;
            $rb = $bandRank[$b['term_name']] ?? PHP_INT_MAX;

            return $ra <=> $rb;
        });

        $global[] = $this->globalRow($attributeId, $slug, $list, $entry['raw_label'], $rawValue);

        // The exact raw figure/text ALSO stays as a LOCAL companion spec row.
        $local[] = [
            'name' => self::BAND_COMPANION_LABEL[$slug] ?? $entry['raw_label'],
            'value' => $rawValue,
        ];
    }

    /**
     * Split a room-size value into tokens on comma / ampersand / "and" — NOT on
     * slash ("Medium/Large" is a single dominant descriptor, not two bands) and
     * NOT on "to" (handled by numeric/text mapping of the whole token).
     *
     * @return list<string>
     */
    private function splitRoomSize(string $value): array
    {
        $parts = preg_split('/\s*(?:,|&|\band\b)\s*/i', $value) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out === [] ? [$value] : $out;
    }

    /**
     * Resolve ONE room-size token → cached band term. Order: exact/ci → text-map
     * (contains) → numeric band derivation (tolerant band-term match).
     *
     * @param  array<int, array{term_id:int, term_name:string, term_slug:string|null}>  $terms
     * @param  array<string, string>  $textMap
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function resolveRoomToken(int $attributeId, array $terms, string $token, array $textMap): ?array
    {
        if ($terms === []) {
            return null;
        }

        $direct = $this->matchTerm($terms, $token);
        if ($direct !== null) {
            return $direct;
        }

        $label = $this->firstKeywordHit(mb_strtolower($token), $textMap);
        if ($label !== null) {
            $hit = $this->matchTerm($terms, $label);
            if ($hit !== null) {
                return $hit;
            }
        }

        $number = $this->leadingNumeric($token);
        if ($number !== null) {
            $bandLabel = $this->deriveBandLabel(self::ROOM_SIZE_SLUG, $number);

            return $this->resolveBandTerm($attributeId, $bandLabel);
        }

        return null;
    }

    /**
     * Resolve a normalised label to a unit-routed target [slug, attribute_id]
     * (config `unit_routed_labels`) based on the FIRST unit-needle found in the
     * value, or [null, null] when the label isn't unit-routed / no unit matches.
     *
     * @return array{0:string|null, 1:int|null}
     */
    private function routeByUnit(string $normLabel, string $rawValue): array
    {
        $map = $this->unitRoutedLabels[$normLabel] ?? null;
        if ($map === null) {
            return [null, null];
        }

        $haystack = mb_strtolower($rawValue);
        foreach ($map as $needle => $canonicalKey) {
            if (mb_strpos($haystack, mb_strtolower((string) $needle)) !== false && isset(self::LABEL_MAP[$canonicalKey])) {
                return self::LABEL_MAP[$canonicalKey];
            }
        }

        return [null, null];
    }

    /**
     * Resolve a value-conditional label (config `value_conditional_labels`) to
     * [slug, attribute_id] — routed ONLY when the RAW value matches one of the
     * label's PCRE patterns (first match wins), else [null, null] → LOCAL.
     *
     * @return array{0:string|null, 1:int|null}
     */
    private function routeByValuePattern(string $normLabel, string $rawValue): array
    {
        $map = $this->valueConditionalLabels[$normLabel] ?? null;
        if ($map === null) {
            return [null, null];
        }

        foreach ($map as $pattern => $canonicalKey) {
            if (preg_match((string) $pattern, $rawValue) === 1 && isset(self::LABEL_MAP[$canonicalKey])) {
                return self::LABEL_MAP[$canonicalKey];
            }
        }

        return [null, null];
    }

    /**
     * Decide whether a value under $slug's label should RE-ROUTE to a different
     * attribute (config `value_reroutes`). Re-routes ONLY when the value is NOT
     * a genuine source value (does not hit the source normaliser) AND DOES hit
     * the target normaliser — so a "Full-Motion Wall Mount" (a Wall mount) stays
     * Mount while a bare "Fixed"/"Tilt"/"Full Motion – …" routes to Movement.
     *
     * @return array{0:string, 1:int}|null [targetSlug, targetAttributeId]
     */
    private function rerouteTarget(string $slug, string $rawValue): ?array
    {
        $cfg = $this->valueReroutes[$slug] ?? null;
        if ($cfg === null) {
            return null;
        }

        $targetKey = (string) ($cfg['target'] ?? '');
        if (! isset(self::LABEL_MAP[$targetKey])) {
            return null;
        }

        // Genuine source value (e.g. a real mount keyword) → keep with source.
        if ($this->normaliseValue($slug, $rawValue) !== null) {
            return null;
        }

        [$targetSlug, $targetAttributeId] = self::LABEL_MAP[$targetKey];

        // Only re-route when the value actually reads as a TARGET value.
        if ($this->normaliseValue($targetSlug, $rawValue) === null) {
            return null;
        }

        return [$targetSlug, $targetAttributeId];
    }

    /**
     * RESOLVE-DON'T-INVENT (single-term): return the cached term matching
     * $rawValue, or null. Order: exact/ci on the RAW value (highest fidelity —
     * preserves slash/paren-bearing exact terms) → per-attribute NORMALISER
     * candidate, itself resolved exact/ci against the cache. null → UNMATCHED.
     *
     * The normaliser runs AFTER the raw exact/ci pass so a value that already
     * IS a cached term links verbatim (a broad keyword can never clobber it),
     * while non-exact inputs ("Tilt Wall Mount", "Cat.6", "500 cd/m²") still map
     * — the candidate is ONLY ever used when it resolves to a real cached term.
     *
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function resolveValue(string $slug, int $attributeId, string $rawValue): ?array
    {
        $terms = $this->vocabulary->termsFor($attributeId);
        if ($terms === []) {
            return null;
        }

        /** @var array<string, mixed> $cfg */
        $cfg = $this->valueNormalisers[$slug] ?? [];
        $norm = $this->ciValue($rawValue);

        // Force-drop (T10 §3): junk values that are STILL cached terms (e.g.
        // Display Technology "Interactive Display") — unmatched, never sent.
        /** @var list<string> $dropValues */
        $dropValues = $cfg['drop_values'] ?? [];
        foreach ($dropValues as $dropValue) {
            if ($this->ciValue((string) $dropValue) === $norm) {
                return null;
            }
        }

        // Overrides (T10 §3): force a specific cached term AHEAD of the verbatim
        // match, so a value that is itself a (different) cached term can be
        // remapped — e.g. US "Aluminum" → UK "Aluminium".
        /** @var array<string, string> $overrides */
        $overrides = $cfg['overrides'] ?? [];
        foreach ($overrides as $from => $to) {
            if ($this->ciValue((string) $from) === $norm) {
                return $this->matchTerm($terms, (string) $to);
            }
        }

        $direct = $this->matchTerm($terms, $rawValue);
        if ($direct !== null) {
            return $direct;
        }

        $candidate = $this->normaliseValue($slug, $rawValue);
        if ($candidate !== null) {
            $hit = $this->matchTerm($terms, $candidate);
            if ($hit !== null) {
                return $hit;
            }
        }

        // General normalised-key tier (T11 §6): compare the alnum-lowercased raw
        // value against the same-normalised cached term names so case / hyphen /
        // spacing variants resolve generically across ALL attributes (subsumes
        // many one-off variants like "Full-Motion", "Cat.6", "USB C"). Still
        // RESOLVE-DON'T-INVENT — it can only ever return a real cached term.
        return $this->matchNormalisedKey($terms, $rawValue);
    }

    /**
     * General normalised-key match (T11 §6): strip every non-alphanumeric char
     * and lowercase, then compare against the same-normalised cached term names.
     * Returns the real cached term, or null (never fabricates).
     *
     * @param  array<int, array{term_id:int, term_name:string, term_slug:string|null}>  $terms
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function matchNormalisedKey(array $terms, string $value): ?array
    {
        $key = $this->normaliseKey($value);
        if ($key === '') {
            return null;
        }

        foreach ($terms as $term) {
            if ($this->normaliseKey($term['term_name']) === $key) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Normalised key for the general resolution tier: lowercase + strip every
     * non-alphanumeric character ("Full-Motion" → "fullmotion", "Cat.6" → "cat6").
     */
    private function normaliseKey(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /**
     * Exact term_name → case-insensitive/whitespace-normalised match, or null.
     *
     * @param  array<int, array{term_id:int, term_name:string, term_slug:string|null}>  $terms
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function matchTerm(array $terms, string $value): ?array
    {
        foreach ($terms as $term) {
            if ($term['term_name'] === $value) {
                return $term;
            }
        }

        $needle = $this->ciValue($value);
        foreach ($terms as $term) {
            if ($this->ciValue($term['term_name']) === $needle) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Per-attribute value NORMALISER: turn a raw value into a canonical CANDIDATE
     * term name (still resolved against the cache by the caller), or null when no
     * rule fires. The DATA lives in config `value_normalisers`; the strategy
     * name selects the LOGIC here.
     */
    private function normaliseValue(string $slug, string $rawValue): ?string
    {
        $cfg = $this->valueNormalisers[$slug] ?? null;
        if ($cfg === null) {
            return null;
        }

        return match ($cfg['strategy'] ?? '') {
            'resolution' => $this->normaliseResolution($rawValue, $cfg),
            'keywords' => $this->normaliseKeywords($rawValue, $cfg),
            'alnum_map' => $this->normaliseAlnumMap($rawValue, $cfg),
            'warranty' => $this->normaliseWarranty($rawValue, $cfg),
            'panel' => $this->normalisePanel($rawValue, $cfg),
            'vesa' => $this->normaliseVesa($rawValue, $cfg),
            'length' => $this->normaliseLength($rawValue),
            // 'room_size' has a dedicated dispatch ({@see self::resolveRoomSize})
            // and never reaches here.
            default => null,
        };
    }

    /**
     * Length (T10 §3): "0.6 m" / "2 m" / "3 metres" / "3 meters" → "0.6m" / "2m"
     * / "3m" — strip the space and canonicalise the unit to a bare `m`. Other
     * units (cm/km) are left to the verbatim cache match. The produced candidate
     * is still resolved against the cache by the caller.
     */
    private function normaliseLength(string $rawValue): ?string
    {
        $v = mb_strtolower(trim($rawValue));
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(?:m|metre|metres|meter|meters)$/', $v, $m) === 1) {
            return $m[1].'m';
        }

        return null;
    }

    /**
     * Resolution: normalise × → x + strip whitespace; map by WxH digit pair, else
     * by contains-keyword.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function normaliseResolution(string $rawValue, array $cfg): ?string
    {
        $lower = mb_strtolower($rawValue);
        $xnorm = str_replace(['×', '✕'], 'x', $lower);
        $stripped = (string) preg_replace('/\s+/', '', $xnorm);

        /** @var array<string, string> $pairs */
        $pairs = $cfg['pairs'] ?? [];
        if (preg_match('/(\d{3,5})x(\d{3,5})/', $stripped, $m) === 1) {
            $pair = $m[1].'x'.$m[2];
            if (isset($pairs[$pair])) {
                return $pairs[$pair];
            }
        }

        return $this->firstKeywordHit($lower, $cfg['keywords'] ?? []);
    }

    /**
     * Contains-keyword strategy (Mount): first/most-specific keyword in the
     * config order that the value contains wins.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function normaliseKeywords(string $rawValue, array $cfg): ?string
    {
        return $this->firstKeywordHit(mb_strtolower($rawValue), $cfg['keywords'] ?? []);
    }

    /**
     * Alphanumeric-key strategy (Cable Category): strip non-alphanumerics +
     * lowercase, then EXACT-match the config key ("Cat.8.1" → "cat81" → Cat8).
     *
     * @param  array<string, mixed>  $cfg
     */
    private function normaliseAlnumMap(string $rawValue, array $cfg): ?string
    {
        /** @var array<string, string> $map */
        $map = $cfg['map'] ?? [];
        $key = mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $rawValue));

        return $map[$key] ?? null;
    }

    /**
     * Warranty: strip a trailing "warranty" word, then keyword (lifetime) or
     * numeric year/month formatting ("3 year"→"3 Years", "1 year"→"1 Year",
     * "6 months"→"6 Months").
     *
     * @param  array<string, mixed>  $cfg
     */
    private function normaliseWarranty(string $rawValue, array $cfg): ?string
    {
        $v = mb_strtolower(trim($rawValue));
        $v = trim((string) preg_replace('/\s+warranty$/', '', $v));

        $keyword = $this->firstKeywordHit($v, $cfg['keywords'] ?? []);
        if ($keyword !== null) {
            return $keyword;
        }

        if (preg_match('/^(\d+)\s*year/', $v, $m) === 1) {
            $n = (int) $m[1];

            return $n === 1 ? '1 Year' : $n.' Years';
        }

        if (preg_match('/^(\d+)\s*month/', $v, $m) === 1) {
            $n = (int) $m[1];

            return $n === 1 ? '1 Month' : $n.' Months';
        }

        return null;
    }

    /**
     * Panel: contains ips → IPS; word-boundary "va" → VA; else contains lcd →
     * LCD. Keywords length ≤ 2 match on a word boundary (so "va" doesn't fire
     * inside another word); longer keywords match on contains.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function normalisePanel(string $rawValue, array $cfg): ?string
    {
        $lower = mb_strtolower($rawValue);
        /** @var array<string, string> $keywords */
        $keywords = $cfg['keywords'] ?? [];
        foreach ($keywords as $needle => $term) {
            $needle = (string) $needle;
            if (mb_strlen($needle) <= 2) {
                if (preg_match('/\b'.preg_quote($needle, '/').'\b/', $lower) === 1) {
                    return $term;
                }
            } elseif (mb_strpos($lower, $needle) !== false) {
                return $term;
            }
        }

        return null;
    }

    /**
     * VESA (T10 §1): a "compatible" value → the compatible term; a RANGE
     * ("AxB to|and|- CxD") → every standard pattern within the range (+ the
     * stated endpoints), sorted ascending and joined by ' / '; a "VESA N, VESA
     * M" / comma list → each NxN/NxM joined; a single "AxB" → "AxB". The
     * produced compound string is resolved as ONE cached term by the caller
     * (resolve-don't-invent: a produced string that isn't cached is unmatched).
     *
     * @param  array<string, mixed>  $cfg
     */
    private function normaliseVesa(string $rawValue, array $cfg): ?string
    {
        if (mb_stripos($rawValue, 'compatible') !== false) {
            return isset($cfg['compatible_term']) ? (string) $cfg['compatible_term'] : null;
        }

        // Normalise: lowercase, × → x, dash variants → '-', strip 'mm', tidy the
        // spacing around x so "200 x 200" and "200×200 mm" both become "200x200".
        $v = mb_strtolower(trim($rawValue));
        $v = str_replace(['×', '✕'], 'x', $v);
        $v = (string) preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $v);
        $v = (string) preg_replace('/\bmm\b/', ' ', $v);
        $v = (string) preg_replace('/\s*x\s*/', 'x', $v);
        $v = trim((string) preg_replace('/\s+/', ' ', $v));

        // Already a compound slash-list (pre-enumerated) → normalise spacing only.
        if (str_contains($v, '/')) {
            $v = (string) preg_replace('/\s*\/\s*/', ' / ', $v);

            return trim((string) preg_replace('/\s+/', ' ', $v));
        }

        /** @var list<array{0:int, 1:int}> $patterns */
        $patterns = $cfg['standard_patterns'] ?? [];

        // Range: "AxB to CxD" | "AxB and CxD" | "AxB - CxD".
        if (preg_match('/^(\d+)x(\d+)\s*(?:to|and|-)\s*(\d+)x(\d+)$/', $v, $m) === 1) {
            return $this->enumerateVesaRange((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], $patterns);
        }

        // Comma / "vesa N" list.
        if (str_contains($v, ',') || str_contains($v, 'vesa')) {
            $pairs = $this->parseVesaPairs($v);
            if ($pairs !== []) {
                return $this->joinVesaPairs($pairs);
            }
        }

        // Single "AxB".
        if (preg_match('/^(\d+)x(\d+)$/', $v, $m) === 1) {
            return $m[1].'x'.$m[2];
        }

        // Single "vesa N" / bare "N" → NxN.
        if (preg_match('/^(?:vesa\s*)?(\d+)$/', $v, $m) === 1) {
            return $m[1].'x'.$m[1];
        }

        return $v === '' ? null : $v;
    }

    /**
     * Enumerate the VESA standard patterns within a stated range, always
     * including the stated endpoints, sorted ascending and joined by ' / '.
     *
     * @param  list<array{0:int, 1:int}>  $patterns
     */
    private function enumerateVesaRange(int $a, int $b, int $c, int $d, array $patterns): string
    {
        $minW = min($a, $c);
        $maxW = max($a, $c);
        $minH = min($b, $d);
        $maxH = max($b, $d);

        $pairs = [];
        foreach ($patterns as $pattern) {
            $w = (int) $pattern[0];
            $h = (int) $pattern[1];
            if ($w >= $minW && $w <= $maxW && $h >= $minH && $h <= $maxH) {
                $pairs[] = [$w, $h];
            }
        }

        // Always include the stated endpoints (even if not standard patterns).
        $pairs[] = [$a, $b];
        $pairs[] = [$c, $d];

        return $this->joinVesaPairs($pairs);
    }

    /**
     * Parse a comma / "vesa N" list into WxH pairs. "vesa 75"/"75" → [75,75];
     * "200x200" → [200,200].
     *
     * @return list<array{0:int, 1:int}>
     */
    private function parseVesaPairs(string $v): array
    {
        $pairs = [];
        foreach (preg_split('/\s*,\s*/', $v) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (preg_match('/(\d+)x(\d+)/', $token, $m) === 1) {
                $pairs[] = [(int) $m[1], (int) $m[2]];
            } elseif (preg_match('/(\d+)/', $token, $m) === 1) {
                $pairs[] = [(int) $m[1], (int) $m[1]];
            }
        }

        return $pairs;
    }

    /**
     * Dedupe, sort ascending (by width then height) and ' / '-join WxH pairs.
     *
     * @param  list<array{0:int, 1:int}>  $pairs
     */
    private function joinVesaPairs(array $pairs): string
    {
        $seen = [];
        $unique = [];
        foreach ($pairs as $pair) {
            $key = $pair[0].'x'.$pair[1];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $pair;
            }
        }

        usort($unique, static fn (array $x, array $y): int => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);

        return implode(' / ', array_map(static fn (array $p): string => $p[0].'x'.$p[1], $unique));
    }

    /**
     * First config keyword (in declared order) that $haystack CONTAINS → its
     * mapped term, or null. Needles are compared lowercase.
     *
     * @param  array<string, string>  $keywords
     */
    private function firstKeywordHit(string $haystack, array $keywords): ?string
    {
        foreach ($keywords as $needle => $term) {
            if (mb_strpos($haystack, mb_strtolower((string) $needle)) !== false) {
                return $term;
            }
        }

        return null;
    }

    /**
     * MULTI-VALUE resolution (Connectivity): one raw value → one GLOBAL row
     * carrying ALL tokens that resolve to a cached term; tokens that don't
     * resolve are logged unmatched WITHOUT failing the row.
     *
     * A value that matches a single cached term verbatim (e.g. "IP / Network")
     * is kept WHOLE — never split — so slash-bearing term names survive.
     *
     * @param  array{raw_label:string, raw_value:string, slug:string|null, attribute_id:int|null}  $entry
     * @param  array<int, array<string, mixed>>  $global
     * @param  array<int, array<string, mixed>>  $unmatched
     */
    private function resolveMultiValue(array $entry, array &$global, array &$unmatched): void
    {
        /** @var string $slug */
        $slug = $entry['slug'];
        /** @var int $attributeId */
        $attributeId = $entry['attribute_id'];
        $rawValue = $entry['raw_value'];

        $terms = $this->vocabulary->termsFor($attributeId);

        /** @var array<string, mixed> $cfg */
        $cfg = $this->valueNormalisers[$slug] ?? [];
        /** @var array<string, list<string>> $expansions */
        $expansions = $cfg['token_expansions'] ?? [];
        /** @var array<string, list<string>> $bearerModes */
        $bearerModes = $cfg['bearer_modes'] ?? [];

        $resolved = [];
        $seenTermIds = [];
        $directTermNames = [];

        $addTerm = function (array $term) use (&$resolved, &$seenTermIds): void {
            if (! isset($seenTermIds[$term['term_id']])) {
                $seenTermIds[$term['term_id']] = true;
                $resolved[] = $term;
            }
        };

        // Whole-value verbatim match first — keeps "IP / Network" etc. intact
        // (never split on its slash). A single-bearer whole match (e.g. "HDMI")
        // is still a DIRECT term, so bearer→mode below can add its mode facet.
        $whole = $terms === [] ? null : $this->matchTerm($terms, $rawValue);
        if ($whole !== null) {
            $addTerm($whole);
            $directTermNames[] = $whole['term_name'];
        } else {
            foreach ($this->splitMultiValue($rawValue) as $token) {
                if ($terms === []) {
                    $unmatched[] = $this->unmatched($entry['raw_label'], $token, $slug, 'value_not_a_term');

                    continue;
                }

                // Token EXPANSION (T10 §3): one token → possibly MANY terms
                // (e.g. "Network (LAN)" → Ethernet + IP / Network). Expansion-derived
                // terms do NOT feed the bearer→mode inference below.
                $expandNames = $this->matchExpansion($token, $expansions);
                if ($expandNames !== null) {
                    $any = false;
                    foreach ($expandNames as $name) {
                        $hit = $this->matchTerm($terms, $name);
                        if ($hit !== null) {
                            $addTerm($hit);
                            $any = true;
                        }
                    }
                    if (! $any) {
                        $unmatched[] = $this->unmatched($entry['raw_label'], $token, $slug, 'value_not_a_term');
                    }

                    continue;
                }

                $term = $this->resolveToken($slug, $terms, $token);
                if ($term === null) {
                    $unmatched[] = $this->unmatched($entry['raw_label'], $token, $slug, 'value_not_a_term');

                    continue;
                }
                $addTerm($term);
                $directTermNames[] = $term['term_name'];
            }
        }

        // Bearer → mode (T10 §3): a directly-resolved bearer implies a
        // connection-MODE facet (HDMI/USB/Ethernet → Wired; Wi-Fi/Bluetooth/DECT
        // → Wireless). resolve-don't-invent: the mode term is added only if it is
        // itself a cached term.
        if ($terms !== []) {
            foreach ($bearerModes as $modeTerm => $bearers) {
                foreach ($directTermNames as $name) {
                    if (in_array($name, $bearers, true)) {
                        $hit = $this->matchTerm($terms, (string) $modeTerm);
                        if ($hit !== null) {
                            $addTerm($hit);
                        }

                        break;
                    }
                }
            }
        }

        if ($resolved !== []) {
            $global[] = $this->globalRow($attributeId, $slug, $resolved, $entry['raw_label'], $rawValue);
        }
    }

    /**
     * Match a token against the config `token_expansions` (contains-keyword →
     * LIST of candidate term names), or null when no expansion fires.
     *
     * @param  array<string, list<string>>  $expansions
     * @return list<string>|null
     */
    private function matchExpansion(string $token, array $expansions): ?array
    {
        $haystack = mb_strtolower($token);
        foreach ($expansions as $needle => $termNames) {
            if (mb_strpos($haystack, mb_strtolower((string) $needle)) !== false) {
                return array_values($termNames);
            }
        }

        return null;
    }

    /**
     * Split a multi-value string on [,/&]+, the word "and", and "+".
     *
     * @return list<string>
     */
    private function splitMultiValue(string $value): array
    {
        $parts = preg_split('/\s*(?:[,\/&]+|\band\b|\+)\s*/i', $value) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * Resolve ONE multi-value token → cached term. Order: exact/ci on the token
     * → config `token_map` contains-keyword → strip a trailing version number
     * ("Bluetooth 5.1"→"Bluetooth") then retry. null → the token is unmatched.
     *
     * @param  array<int, array{term_id:int, term_name:string, term_slug:string|null}>  $terms
     * @return array{term_id:int, term_name:string, term_slug:string|null}|null
     */
    private function resolveToken(string $slug, array $terms, string $token): ?array
    {
        $direct = $this->matchTerm($terms, $token);
        if ($direct !== null) {
            return $direct;
        }

        /** @var array<string, string> $tokenMap */
        $tokenMap = $this->valueNormalisers[$slug]['token_map'] ?? [];

        $candidate = $this->firstKeywordHit(mb_strtolower($token), $tokenMap);
        if ($candidate !== null) {
            $hit = $this->matchTerm($terms, $candidate);
            if ($hit !== null) {
                return $hit;
            }
        }

        // Strip a trailing version number ("v2", "5.1", "3.0") and retry.
        $stripped = trim((string) preg_replace('/\s+v?\d+(?:\.\d+)*\s*$/i', '', $token));
        if ($stripped !== '' && $stripped !== $token) {
            $retry = $this->matchTerm($terms, $stripped);
            if ($retry !== null) {
                return $retry;
            }
            $candidate = $this->firstKeywordHit(mb_strtolower($stripped), $tokenMap);
            if ($candidate !== null) {
                return $this->matchTerm($terms, $candidate);
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
     * Build a global bucket entry from one OR MORE resolved terms.
     *
     * `term_ids`/`term_names` carry ALL resolved terms (multi-value support,
     * 260728-fwx T9); the scalar `term_id`/`term_name` keys mirror the FIRST
     * term for backward compatibility with pre-T9 single-term callers/tests.
     *
     * @param  non-empty-list<array{term_id:int, term_name:string, term_slug:string|null}>  $terms
     * @return array{attribute_id:int, attribute_slug:string, term_id:int, term_name:string, term_ids:list<int>, term_names:list<string>, raw_label:string, raw_value:string}
     */
    private function globalRow(int $attributeId, string $slug, array $terms, string $rawLabel, string $rawValue): array
    {
        $termIds = array_map(static fn (array $t): int => $t['term_id'], $terms);
        $termNames = array_map(static fn (array $t): string => $t['term_name'], $terms);

        return [
            'attribute_id' => $attributeId,
            'attribute_slug' => $slug,
            'term_id' => $termIds[0],
            'term_name' => $termNames[0],
            'term_ids' => $termIds,
            'term_names' => $termNames,
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
