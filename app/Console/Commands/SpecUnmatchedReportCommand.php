<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Services\SpecTaxonomyResolver;
use App\Domain\Products\Models\Product;

/**
 * 260728-fwx T6 — spec:unmatched-report (READ-ONLY; no writes, no Woo calls).
 *
 * Quantifies, across the catalogue, HOW WELL products' curated `attributes_json`
 * specs resolve to the 44 global `pa_*` taxonomies — so the operator can see the
 * gap and decide where to extend the resolver's maps or improve upstream
 * (Claude) spec generation. It is the reporting counterpart to T2's
 * RESOLVE-DON'T-INVENT policy: every value that CANNOT be resolved to an existing
 * term is withheld from Woo (so the cleaned facets stay clean) — this report
 * surfaces exactly WHAT is being withheld and WHY, in aggregate.
 *
 * PURE READ: it runs {@see SpecTaxonomyResolver::resolve()} (which reads only the
 * locally-cached term vocabulary — the `woo_attribute_terms` mirror fed nightly
 * by T1) over each product's stored `attributes_json`. It makes ZERO Woo calls
 * and performs ZERO writes (the only optional output is a report CSV the operator
 * asks for via --csv). The resolver, its payload builder and the term cache are
 * reused AS-IS — this command adds no new resolution logic, it only aggregates.
 *
 * Three things reported (console summaries + top-N; full detail to --csv):
 *
 *  1. COVERAGE — products scanned, how many produced >=1 GLOBAL taxonomy
 *     attribute, the average number of global attrs per product, and a
 *     per-taxonomy FILL table (for each of the 44 pa_* attributes: how many
 *     scanned products produced a global attribute for it). Shows which facets
 *     are being populated vs starved.
 *
 *  2. UNMATCHED VALUES — rows whose LABEL mapped to a taxonomy but whose VALUE
 *     did not resolve to an existing term. Grouped attribute_slug → distinct raw
 *     value → occurrence count, with the resolver's reason
 *     (value_not_a_term / band_value_not_numeric / band_term_not_cached /
 *     mixed_units). These are the candidates for a per-attribute value-alias map.
 *
 *  3. UNMAPPED LABELS — labels present in attributes_json that fell through to
 *     LOCAL because they are neither in the 44-map / alias table NOR a known
 *     spec-only field. Distinct label → count. These are candidates for the
 *     alias table (e.g. "Connection" → Connectivity). Known spec-only labels
 *     (MPN / Model / Part Number / exact brightness) are counted SEPARATELY and
 *     excluded from the unmapped list so the operator isn't shown noise.
 *
 * Options:
 *   --limit=0      Cap the number of scanned products (0 = all).
 *   --status=      Product status filter (e.g. publish / pending). Empty or
 *                  "all" scans every status.
 *   --csv=<path>   Write the full detail (all sections) to a CSV.
 *   --top=30       How many rows to show in the console for the unmatched-value
 *                  and unmapped-label tables (the CSV always carries the full
 *                  long tail).
 *
 * Examples:
 *   php artisan spec:unmatched-report
 *   php artisan spec:unmatched-report --status=publish --top=50
 *   php artisan spec:unmatched-report --status=publish --csv=storage/app/reports/spec-unmatched.csv
 */
class SpecUnmatchedReportCommand extends BaseCommand
{
    protected $signature = 'spec:unmatched-report
        {--limit=0 : Cap the number of scanned products (0 = all)}
        {--status= : Product status filter (publish/pending/...); empty or "all" scans every status}
        {--csv= : Write the full detail (all sections) to this CSV path}
        {--top=30 : How many rows to show in the console for unmatched-values and unmapped-labels}';

    protected $description = 'READ-ONLY: report how well products\' attributes_json specs resolve to the 44 pa_* taxonomies (coverage + unmatched values + unmapped labels). No writes, no Woo calls.';

    /** Product scan chunk size. */
    private const CHUNK = 500;

    /**
     * The 44 target pa_* attributes: slug => [attribute_id, human label, is_local].
     *
     * Mirrors {@see SpecTaxonomyResolver}'s 44-map for REPORTING purposes only
     * (the resolver stays the single source of truth for RESOLUTION — this list
     * only drives the fill table so the operator sees every facet, including the
     * starved 0-fill ones, in one place). The 44th — exact `pa_brightness-cdm2`
     * (3531) — is intentionally LOCAL per D1 (exact cd/m² is a spec row, the
     * band is the facet), so it can never produce a global and is flagged
     * `is_local` = true.
     *
     * @var array<string, array{0:int, 1:string, 2:bool}>
     */
    private const FACET_ATTRIBUTES = [
        'pa_resolution' => [3429, 'Resolution', false],
        'pa_screen-size-band' => [3516, 'Display Size Band', false],
        'pa_mount-type' => [3517, 'Mount Type', false],
        'pa_connectivity' => [3273, 'Connectivity', false],
        'pa_brightness-nits' => [3518, 'Brightness Band (cd/m²)', false],
        'pa_brightness-lumens' => [3554, 'Brightness Band (lumens)', false],
        'pa_warranty' => [3498, 'Warranty', false],
        'pa_hdr-support' => [3519, 'HDR', false],
        'pa_display-tech' => [3520, 'Display Technology', false],
        'pa_refresh-rate-hz' => [3521, 'Refresh Rate', false],
        'pa_viewing-angle-deg' => [3524, 'Viewing Angle', false],
        'pa_panel-type' => [3543, 'Panel Type', false],
        'pa_touchscreen-yn' => [3550, 'Touchscreen', false],
        'pa_touchscreen-size-in' => [3551, 'Touchscreen Size', false],
        'pa_touch-tech-2' => [3540, 'Touch Technology', false],
        'pa_touch-points' => [3541, 'Touch Points', false],
        'pa_projection-tech' => [3529, 'Projection Technology', false],
        'pa_throw-type-2' => [3544, 'Throw Type', false],
        'pa_light-source' => [3542, 'Light Source', false],
        'pa_lens-shift-2' => [3530, 'Lens Shift', false],
        'pa_screen-type-2' => [3526, 'Screen Type', false],
        'pa_tab-tensioned' => [3539, 'Tensioning', false],
        'pa_movement' => [3522, 'Movement', false],
        'pa_vesa-standard' => [3533, 'VESA', false],
        'pa_max-load-kg' => [3547, 'Max Load', false],
        'pa_quick-release-2' => [3532, 'Quick Release', false],
        'pa_material' => [3364, 'Material', false],
        'pa_colour' => [3268, 'Colour', false],
        'pa_cable-length' => [3534, 'Length', false],
        'pa_cable-category' => [3538, 'Cable Category', false],
        'pa_connector-type' => [3535, 'Connector Type', false],
        'pa_shielding-2' => [3537, 'Shielding', false],
        'pa_fire-rating' => [3536, 'Fire Rating', false],
        'pa_impedance-ohms-2' => [3523, 'Impedance', false],
        'pa_power-output-w' => [3549, 'Power Output', false],
        'pa_speaker-type-2' => [3545, 'Speaker Type', false],
        'pa_noise-cancelling' => [3527, 'Noise Cancellation', false],
        'pa_noise-level-db' => [3528, 'Noise Level', false],
        'pa_microphone-type-2' => [3525, 'Microphone', false],
        'pa_ip-rating' => [3546, 'IP Rating', false],
        'pa_field-of-view-deg' => [3548, 'Field of View', false],
        'pa_platform-certified' => [3552, 'Platform Certification', false],
        'pa_room-size-band' => [3553, 'Room Size', false],
        // 44th — exact cd/m² figure. LOCAL per D1 (band is the facet). Always 0-fill.
        'pa_brightness-cdm2' => [3531, 'Brightness exact (cd/m²)', true],
    ];

    /**
     * Known spec-only labels (NORMALISED) — mirrors the resolver's
     * LOCAL_FORCED_LABELS. Report-side recogniser only: these are counted
     * separately and EXCLUDED from the "unmapped labels" candidate list so the
     * operator isn't shown expected local fields as if they were map gaps.
     *
     * @var list<string>
     */
    private const SPEC_ONLY_LABELS = [
        'mpn',
        'model',
        'part number',
        'brightness cd m2',
        'brightness lumens',
    ];

    public function __construct(private readonly SpecTaxonomyResolver $resolver)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $status = trim((string) $this->option('status'));
        $top = max(1, (int) $this->option('top'));
        $csvPath = trim((string) $this->option('csv'));

        // --- Aggregation accumulators -------------------------------------
        $scanned = 0;
        $productsWithGlobal = 0;
        $totalGlobalAttrs = 0;

        /** @var array<string, int> $fill  slug => number of products producing >=1 global for it */
        $fill = array_fill_keys(array_keys(self::FACET_ATTRIBUTES), 0);

        /** @var array<string, array{slug:string, value:string, count:int, reasons:array<string,int>}> $unmatched */
        $unmatched = [];

        /** @var array<string, array{label:string, count:int}> $unmapped  norm label => {display label, count} */
        $unmapped = [];

        /** @var array<string, array{label:string, count:int}> $specOnly */
        $specOnly = [];

        // --- Scan (chunked; no N+1, no Woo) -------------------------------
        $query = Product::query()
            ->whereNotNull('attributes_json')
            ->select(['id', 'status', 'attributes_json'])
            ->orderBy('id');

        if ($status !== '' && strtolower($status) !== 'all') {
            $query->where('status', $status);
        }

        $stop = false;
        $query->chunkById(self::CHUNK, function ($products) use (
            &$scanned, &$productsWithGlobal, &$totalGlobalAttrs, &$fill,
            &$unmatched, &$unmapped, &$specOnly, &$stop, $limit
        ): bool {
            foreach ($products as $product) {
                $rows = is_array($product->attributes_json) ? $product->attributes_json : [];
                // Empty attributes_json (stored '[]') is nothing to classify — skip
                // WITHOUT counting it as scanned (portable across SQLite/MariaDB;
                // avoids a JSON-column string comparison in the WHERE clause).
                if ($rows === []) {
                    continue;
                }

                if ($limit > 0 && $scanned >= $limit) {
                    $stop = true;

                    return false;
                }

                $this->accumulate($rows, $scanned, $productsWithGlobal, $totalGlobalAttrs, $fill, $unmatched, $unmapped, $specOnly);
                $scanned++;

                if ($limit > 0 && $scanned >= $limit) {
                    $stop = true;

                    return false;
                }
            }

            return true;
        }, 'id', 'id');

        // --- Report -------------------------------------------------------
        $this->renderCoverage($scanned, $productsWithGlobal, $totalGlobalAttrs, $fill);
        $this->renderUnmatchedValues($unmatched, $top);
        $this->renderUnmappedLabels($unmapped, $specOnly, $top);

        if ($csvPath !== '') {
            $this->writeCsv($csvPath, $scanned, $productsWithGlobal, $totalGlobalAttrs, $fill, $unmatched, $unmapped, $specOnly);
        }

        return self::SUCCESS;
    }

    /**
     * Classify one product's raw spec set and fold the result into the
     * accumulators. Pure over the injected resolver + already-loaded cache.
     *
     * @param  array<int, mixed>  $rows
     * @param  array<string, int>  $fill
     * @param  array<string, array{slug:string, value:string, count:int, reasons:array<string,int>}>  $unmatched
     * @param  array<string, array{label:string, count:int}>  $unmapped
     * @param  array<string, array{label:string, count:int}>  $specOnly
     */
    private function accumulate(
        array $rows,
        int &$scanned,
        int &$productsWithGlobal,
        int &$totalGlobalAttrs,
        array &$fill,
        array &$unmatched,
        array &$unmapped,
        array &$specOnly,
    ): void {
        $resolved = $this->resolver->resolve($rows);
        $global = $resolved->global();

        // Coverage.
        if ($global !== []) {
            $productsWithGlobal++;
            $totalGlobalAttrs += count($global);
        }

        // Per-taxonomy fill — count each product AT MOST ONCE per slug.
        $slugsSeen = [];
        foreach ($global as $g) {
            $slug = (string) $g['attribute_slug'];
            if (isset($slugsSeen[$slug])) {
                continue;
            }
            $slugsSeen[$slug] = true;
            if (array_key_exists($slug, $fill)) {
                $fill[$slug]++;
            }
        }

        // Unmatched values (label mapped, value didn't resolve).
        foreach ($resolved->unmatched() as $u) {
            $slug = (string) $u['attribute_slug'];
            $value = (string) $u['raw_value'];
            $reason = (string) $u['reason'];
            $key = $slug."\x1f".$value;
            if (! isset($unmatched[$key])) {
                $unmatched[$key] = ['slug' => $slug, 'value' => $value, 'count' => 0, 'reasons' => []];
            }
            $unmatched[$key]['count']++;
            $unmatched[$key]['reasons'][$reason] = ($unmatched[$key]['reasons'][$reason] ?? 0) + 1;
        }

        // Unmapped labels vs known spec-only. Classify each RAW input label
        // against the resolver's OWN output (labels that produced a global or an
        // unmatched row ARE mapped taxonomies) — so we never duplicate the
        // 44-map here. Only the small spec-only recogniser is mirrored.
        $mappedLabels = [];
        foreach ($global as $g) {
            $mappedLabels[$this->normaliseLabel((string) $g['raw_label'])] = true;
        }
        foreach ($resolved->unmatched() as $u) {
            $mappedLabels[$this->normaliseLabel((string) $u['raw_label'])] = true;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawLabel = trim((string) ($row['name'] ?? ''));
            $rawValue = trim((string) ($row['value'] ?? ''));
            if ($rawLabel === '' || $rawValue === '') {
                continue; // resolver skips blank rows — mirror that here.
            }
            $norm = $this->normaliseLabel($rawLabel);

            if (isset($mappedLabels[$norm])) {
                continue; // mapped to a taxonomy — not an unmapped label.
            }

            if (in_array($norm, self::SPEC_ONLY_LABELS, true)) {
                $this->tallyLabel($specOnly, $norm, $rawLabel);

                continue;
            }

            $this->tallyLabel($unmapped, $norm, $rawLabel);
        }
    }

    /**
     * @param  array<string, array{label:string, count:int}>  $bucket
     */
    private function tallyLabel(array &$bucket, string $normKey, string $displayLabel): void
    {
        if (! isset($bucket[$normKey])) {
            $bucket[$normKey] = ['label' => $displayLabel, 'count' => 0];
        }
        $bucket[$normKey]['count']++;
    }

    /**
     * @param  array<string, int>  $fill
     */
    private function renderCoverage(int $scanned, int $productsWithGlobal, int $totalGlobalAttrs, array $fill): void
    {
        $avg = $scanned > 0 ? round($totalGlobalAttrs / $scanned, 2) : 0.0;
        $pct = $scanned > 0 ? round($productsWithGlobal / $scanned * 100, 1) : 0.0;

        $this->newLine();
        $this->info('=== COVERAGE SUMMARY ===');
        $this->line("  products scanned:            {$scanned}");
        $this->line("  produced >=1 global attr:    {$productsWithGlobal} ({$pct}%)");
        $this->line("  total global attrs:          {$totalGlobalAttrs}");
        $this->line("  avg global attrs / product:  {$avg}");

        $this->newLine();
        $this->info('=== PER-TAXONOMY FILL (products producing a global attr for each pa_* facet) ===');

        $rows = [];
        foreach (self::FACET_ATTRIBUTES as $slug => [$id, $label, $isLocal]) {
            $count = $fill[$slug] ?? 0;
            $rows[] = [
                'slug' => $slug,
                'id' => $id,
                'label' => $label.($isLocal ? ' (LOCAL per D1)' : ''),
                'filled' => $count,
            ];
        }

        // Sort by fill desc, then slug — starved (0-fill) facets sink to the bottom.
        usort($rows, fn ($a, $b) => $b['filled'] <=> $a['filled'] ?: strcmp($a['slug'], $b['slug']));

        $this->table(
            ['Attribute slug', 'ID', 'Label', 'Products filled'],
            array_map(fn ($r) => [$r['slug'], $r['id'], $r['label'], $r['filled']], $rows),
        );

        $starved = count(array_filter($rows, fn ($r) => $r['filled'] === 0));
        $this->line("  facets with ZERO fill: {$starved} of ".count($rows));
    }

    /**
     * @param  array<string, array{slug:string, value:string, count:int, reasons:array<string,int>}>  $unmatched
     */
    private function renderUnmatchedValues(array $unmatched, int $top): void
    {
        $this->newLine();
        $this->info('=== UNMATCHED VALUES (label mapped, value did not resolve to a term) ===');

        if ($unmatched === []) {
            $this->line('  (none — every mapped label resolved to an existing term)');

            return;
        }

        $rows = array_values($unmatched);
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['slug'].$a['value'], $b['slug'].$b['value']));

        $total = count($rows);
        $shown = array_slice($rows, 0, $top);

        $this->table(
            ['Attribute slug', 'Raw value', 'Count', 'Reason'],
            array_map(fn ($r) => [$r['slug'], $r['value'], $r['count'], $this->primaryReason($r['reasons'])], $shown),
        );
        $this->line("  distinct unmatched (slug,value) pairs: {$total}".($total > $top ? " (showing top {$top} — full list in --csv)" : ''));
    }

    /**
     * @param  array<string, array{label:string, count:int}>  $unmapped
     * @param  array<string, array{label:string, count:int}>  $specOnly
     */
    private function renderUnmappedLabels(array $unmapped, array $specOnly, int $top): void
    {
        $this->newLine();
        $this->info('=== UNMAPPED LABELS (present in attributes_json, fell through to LOCAL — candidates for the alias table) ===');

        if ($unmapped === []) {
            $this->line('  (none — every non-spec-only label mapped to a taxonomy)');
        } else {
            $rows = array_values($unmapped);
            usort($rows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
            $total = count($rows);
            $shown = array_slice($rows, 0, $top);

            $this->table(
                ['Label', 'Count'],
                array_map(fn ($r) => [$r['label'], $r['count']], $shown),
            );
            $this->line("  distinct unmapped labels: {$total}".($total > $top ? " (showing top {$top} — full list in --csv)" : ''));
        }

        $specOnlyDistinct = count($specOnly);
        $specOnlyTotal = array_sum(array_map(fn ($r) => $r['count'], $specOnly));
        $this->line("  (excluded {$specOnlyDistinct} known spec-only label(s), {$specOnlyTotal} occurrence(s) — MPN/Model/Part Number/exact brightness)");
    }

    /**
     * @param  array<string, int>  $reasons
     */
    private function primaryReason(array $reasons): string
    {
        if ($reasons === []) {
            return '';
        }
        arsort($reasons);

        return (string) array_key_first($reasons);
    }

    /**
     * Write the full detail (all sections) to a CSV. Clearly separated sections
     * (each prefixed by a `# SECTION:` comment row + its own header). This is the
     * command's ONLY output write — it is a report artifact at an operator-chosen
     * path, never a catalogue/Woo write.
     *
     * @param  array<string, int>  $fill
     * @param  array<string, array{slug:string, value:string, count:int, reasons:array<string,int>}>  $unmatched
     * @param  array<string, array{label:string, count:int}>  $unmapped
     * @param  array<string, array{label:string, count:int}>  $specOnly
     */
    private function writeCsv(
        string $path,
        int $scanned,
        int $productsWithGlobal,
        int $totalGlobalAttrs,
        array $fill,
        array $unmatched,
        array $unmapped,
        array $specOnly,
    ): void {
        $dir = dirname($path);
        if ($dir !== '' && ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error("Could not open CSV for writing: {$path}");

            return;
        }

        $avg = $scanned > 0 ? round($totalGlobalAttrs / $scanned, 2) : 0.0;

        // Section 1 — coverage headline.
        fputcsv($handle, ['# SECTION: COVERAGE']);
        fputcsv($handle, ['metric', 'value']);
        fputcsv($handle, ['products_scanned', $scanned]);
        fputcsv($handle, ['products_with_global', $productsWithGlobal]);
        fputcsv($handle, ['total_global_attrs', $totalGlobalAttrs]);
        fputcsv($handle, ['avg_global_attrs_per_product', $avg]);
        fputcsv($handle, []);

        // Section 2 — per-taxonomy fill (all 44).
        fputcsv($handle, ['# SECTION: TAXONOMY_FILL']);
        fputcsv($handle, ['attribute_slug', 'attribute_id', 'attribute_label', 'products_filled', 'is_local']);
        foreach (self::FACET_ATTRIBUTES as $slug => [$id, $label, $isLocal]) {
            fputcsv($handle, [$slug, $id, $label, $fill[$slug] ?? 0, $isLocal ? 1 : 0]);
        }
        fputcsv($handle, []);

        // Section 3 — unmatched values (full long tail, sorted desc).
        fputcsv($handle, ['# SECTION: UNMATCHED_VALUES']);
        fputcsv($handle, ['attribute_slug', 'raw_value', 'count', 'reason']);
        $uRows = array_values($unmatched);
        usort($uRows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['slug'].$a['value'], $b['slug'].$b['value']));
        foreach ($uRows as $r) {
            fputcsv($handle, [$r['slug'], $r['value'], $r['count'], $this->primaryReason($r['reasons'])]);
        }
        fputcsv($handle, []);

        // Section 4 — labels (unmapped + spec-only), classified.
        fputcsv($handle, ['# SECTION: UNMAPPED_LABELS']);
        fputcsv($handle, ['label', 'count', 'classification']);
        $lRows = [];
        foreach ($unmapped as $r) {
            $lRows[] = ['label' => $r['label'], 'count' => $r['count'], 'class' => 'unmapped'];
        }
        foreach ($specOnly as $r) {
            $lRows[] = ['label' => $r['label'], 'count' => $r['count'], 'class' => 'spec_only'];
        }
        usort($lRows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        foreach ($lRows as $r) {
            fputcsv($handle, [$r['label'], $r['count'], $r['class']]);
        }

        fclose($handle);

        $this->newLine();
        $this->info("Full detail written to: {$path}");
    }

    /**
     * Label normalisation — IDENTICAL to {@see SpecTaxonomyResolver::normaliseLabel}
     * so the report classifies labels exactly as the resolver does: '²'→'2',
     * lowercase, punctuation to single spaces, collapse whitespace.
     */
    private function normaliseLabel(string $label): string
    {
        $label = str_replace(['²', '³'], ['2', '3'], $label);
        $label = mb_strtolower(trim($label));
        $label = (string) preg_replace('/[^a-z0-9]+/', ' ', $label);

        return trim((string) preg_replace('/\s+/', ' ', $label));
    }
}
