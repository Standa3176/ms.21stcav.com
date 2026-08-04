<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Models\WooAttributeTerm;
use App\Domain\Sync\Services\WooClient;

/**
 * 260728-fwx T1 — spec:sync-taxonomy-cache (READ-ONLY vs Woo).
 *
 * Caches every global `pa_*` attribute's CURRENT term vocabulary into the local
 * `woo_attribute_terms` table so the upcoming SpecTaxonomyResolver (T2) can
 * resolve a raw spec value to an EXISTING term id WITHOUT hitting the flaky Woo
 * terms endpoint per product (and without risking Woo auto-creating a duplicate
 * term that would re-pollute the cleaned FacetWP facets).
 *
 * Read-from-Woo, write-to-local-table ONLY. This command issues nothing but GET
 * calls against Woo (products/attributes + products/attributes/{id}/terms) — it
 * is safe regardless of WOO_WRITE_ENABLED and never mutates the storefront.
 *
 * Flow:
 *   1. GET products/attributes?per_page=100 → keep every attribute whose slug
 *      starts with `pa_` (naturally the 44 spec taxonomies + harmless extras
 *      like pa_brand / pa_campaign-type* — we cache ALL pa_*, no special-casing).
 *   2. For each kept attribute: GET products/attributes/{id}/terms?per_page=100,
 *      paginated, with retry-with-backoff (the terms endpoint is flaky — a
 *      failed read RETRIES, then is REPORTED as a failed attribute; it is NEVER
 *      silently dropped, and the other attributes still cache).
 *   3. updateOrCreate on (attribute_id, term_id) — idempotent — then prune any
 *      cached term no longer present for that attribute (reporting the delta).
 *
 * Options:
 *   --only=<comma slugs>  Limit the sync to specific pa_* slugs (pa_ prefix
 *                         optional; it is prepended when omitted).
 *   --dry-run             Report counts without writing to woo_attribute_terms.
 *
 * Retry knobs (config-overridable; defaults are prod-safe, tests set backoff 0):
 *   services.woo.taxonomy_terms_max_attempts (default 4)
 *   services.woo.taxonomy_terms_backoff_ms   (default 500, linear per attempt)
 *
 * Scheduled nightly in routes/console.php (dailyAt 02:40 London,
 * withoutOverlapping) so the vocabulary stays fresh for the create hot-path.
 */
class SyncTaxonomyCacheCommand extends BaseCommand
{
    protected $signature = 'spec:sync-taxonomy-cache
        {--only= : Comma-separated pa_* slugs to limit the sync to (pa_ prefix optional)}
        {--dry-run : Report counts without writing to woo_attribute_terms}';

    protected $description = 'READ-ONLY: cache every global pa_* attribute term vocabulary into woo_attribute_terms (feeds SpecTaxonomyResolver). Safe regardless of WOO_WRITE_ENABLED.';

    /** Woo terms endpoint page size (Woo caps at 100). */
    private const PER_PAGE = 100;

    /** Hard cap on pages per attribute (5,000 terms) — defensive against a runaway paginate loop. */
    private const MAX_PAGES = 50;

    public function __construct(private readonly WooClient $woo)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->parseOnly((string) $this->option('only'));

        // 1) Fetch the attribute list (single GET). A failure here is fatal —
        //    there is nothing to iterate — so we report and bail non-zero.
        try {
            $attributes = $this->woo->get('products/attributes', ['per_page' => self::PER_PAGE]);
        } catch (\Throwable $e) {
            $this->error("Failed to fetch products/attributes: {$e->getMessage()}");

            return self::FAILURE;
        }

        $paAttributes = $this->filterPaAttributes($attributes, $only);

        if ($paAttributes === []) {
            $this->warn($only === []
                ? 'No pa_* attributes returned by Woo — nothing to cache.'
                : 'No pa_* attributes matched --only='.implode(',', $only).'.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — reporting counts only; woo_attribute_terms will NOT be written.');
        }

        /** @var array<string, int> $summary slug => term count */
        $summary = [];
        /** @var array<string, string> $failed slug => error message */
        $failed = [];
        /** @var array<string, int> $pruned slug => pruned count */
        $pruned = [];
        $totalTerms = 0;

        foreach ($paAttributes as $attr) {
            $attrId = $attr['id'];
            $slug = $attr['slug'];
            $name = $attr['name'];

            try {
                $terms = $this->fetchTermsWithRetry($attrId);
            } catch (\Throwable $e) {
                // Reported, NOT silently dropped — other attributes continue.
                $failed[$slug] = $e->getMessage();
                $this->error("  x {$slug} (#{$attrId}) — term fetch FAILED after retries: {$e->getMessage()}");

                continue;
            }

            $count = count($terms);
            $summary[$slug] = $count;
            $totalTerms += $count;

            if ($dryRun) {
                $this->line("  - {$slug} (#{$attrId}): {$count} terms");

                continue;
            }

            $prunedCount = $this->persistTerms($attrId, $slug, $name, $terms);
            if ($prunedCount > 0) {
                $pruned[$slug] = $prunedCount;
            }
            $this->line("  - {$slug} (#{$attrId}): {$count} terms cached"
                .($prunedCount > 0 ? ", {$prunedCount} stale pruned" : ''));
        }

        $this->renderSummary($summary, $pruned, $failed, $totalTerms, $dryRun);

        // A per-attribute term-fetch failure is REPORTED but does not fail the
        // whole run — the cache is a best-effort refresh and the partial result
        // is still useful. The top-level attributes fetch is the only fatal path.
        return self::SUCCESS;
    }

    /**
     * Parse `--only` into a list of pa_* slugs (prepending pa_ when omitted).
     *
     * @return array<int, string>
     */
    protected function parseOnly(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $slugs = [];
        foreach (explode(',', $raw) as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            if (! str_starts_with($s, 'pa_')) {
                $s = 'pa_'.$s;
            }
            $slugs[] = $s;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Keep only attributes whose slug starts with `pa_`, applying the --only filter.
     *
     * @param  array<int, string>  $only
     * @return array<int, array{id:int, slug:string, name:string}>
     */
    protected function filterPaAttributes(mixed $attributes, array $only): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $out = [];
        foreach ($attributes as $attr) {
            if (is_object($attr)) {
                $attr = (array) $attr;
            }
            if (! is_array($attr)) {
                continue;
            }

            $id = $attr['id'] ?? null;
            $slug = (string) ($attr['slug'] ?? '');

            if (! is_numeric($id) || ! str_starts_with($slug, 'pa_')) {
                continue;
            }
            if ($only !== [] && ! in_array($slug, $only, true)) {
                continue;
            }

            $out[] = [
                'id' => (int) $id,
                'slug' => $slug,
                'name' => (string) ($attr['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Fetch an attribute's full (paginated) term list, retrying the WHOLE fetch
     * with linear backoff on any transient failure (non-JSON / timeout / 5xx —
     * the terms endpoint is flaky, see 260726-slw/egr). Retrying the whole fetch
     * (rather than a single page) keeps the buffer consistent — a half-read
     * vocabulary must never reach the prune step.
     *
     * @return array<int, array{term_id:int, term_name:string, term_slug:string}>
     *
     * @throws \Throwable When every attempt fails (caller reports it as a failed attribute).
     */
    protected function fetchTermsWithRetry(int $attributeId): array
    {
        $maxAttempts = max(1, (int) config('services.woo.taxonomy_terms_max_attempts', 4));
        $backoffMs = max(0, (int) config('services.woo.taxonomy_terms_backoff_ms', 500));

        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->fetchAllTermPages($attributeId);
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    // Linear backoff: base, 2x, 3x ... — polite to the flaky endpoint.
                    $this->sleepMs($backoffMs * $attempt);
                }
            }
        }

        throw $lastError ?? new \RuntimeException("Unknown term-fetch failure for attribute {$attributeId}.");
    }

    /**
     * Test seam — real sleep between retries. Tests set backoff to 0 (via
     * services.woo.taxonomy_terms_backoff_ms) so this is never invoked with a
     * positive value under test.
     */
    protected function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * Page through products/attributes/{id}/terms (per_page=100), stopping on the
     * first short/empty page. Throws on any GET failure so the retry wrapper can
     * re-attempt the whole fetch.
     *
     * @return array<int, array{term_id:int, term_name:string, term_slug:string}>
     */
    protected function fetchAllTermPages(int $attributeId): array
    {
        /** @var array<int, array{term_id:int, term_name:string, term_slug:string}> $out keyed by term_id */
        $out = [];
        $page = 1;

        do {
            $batch = $this->woo->get("products/attributes/{$attributeId}/terms", [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            if (! is_array($batch)) {
                throw new \RuntimeException("Non-array terms response for attribute {$attributeId} (page {$page}).");
            }

            $n = 0;
            foreach ($batch as $term) {
                $n++;
                if (is_object($term)) {
                    $term = (array) $term;
                }
                if (! is_array($term)) {
                    continue;
                }
                $id = $term['id'] ?? null;
                $termName = (string) ($term['name'] ?? '');
                if (! is_numeric($id) || $termName === '') {
                    continue;
                }
                // Woo returns names HTML-entity-encoded ("A &amp; B") — decode so
                // the cached term matches what the resolver compares against.
                $out[(int) $id] = [
                    'term_id' => (int) $id,
                    'term_name' => html_entity_decode($termName, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'term_slug' => (string) ($term['slug'] ?? ''),
                ];
            }

            $page++;
        } while ($n === self::PER_PAGE && $page <= self::MAX_PAGES);

        return array_values($out);
    }

    /**
     * Upsert the fetched terms for one attribute and prune any cached term no
     * longer present. Returns the number of pruned (stale) rows.
     *
     * @param  array<int, array{term_id:int, term_name:string, term_slug:string}>  $terms
     */
    protected function persistTerms(int $attributeId, string $slug, string $name, array $terms): int
    {
        $seenTermIds = [];
        foreach ($terms as $term) {
            WooAttributeTerm::updateOrCreate(
                ['attribute_id' => $attributeId, 'term_id' => $term['term_id']],
                [
                    'attribute_slug' => $slug,
                    'attribute_name' => $name,
                    'term_name' => $term['term_name'],
                    'term_slug' => $term['term_slug'],
                ],
            );
            $seenTermIds[] = $term['term_id'];
        }

        // Prune terms no longer present on Woo for this attribute. Reached only
        // when the fetch SUCCEEDED (a failed fetch throws before here), so an
        // empty $seenTermIds legitimately means the attribute has zero terms now.
        $pruneQuery = WooAttributeTerm::query()->where('attribute_id', $attributeId);
        if ($seenTermIds !== []) {
            $pruneQuery->whereNotIn('term_id', $seenTermIds);
        }

        $stale = $pruneQuery->count();
        if ($stale > 0) {
            $pruneQuery->delete();
        }

        return $stale;
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<string, int>  $pruned
     * @param  array<string, string>  $failed
     */
    protected function renderSummary(array $summary, array $pruned, array $failed, int $totalTerms, bool $dryRun): void
    {
        $this->newLine();
        $this->info(($dryRun ? '[DRY-RUN] ' : '').'spec:sync-taxonomy-cache summary');
        $this->line('  attributes cached: '.count($summary));
        $this->line('  total terms:       '.$totalTerms);

        if ($pruned !== []) {
            $this->line('  pruned (stale):    '.array_sum($pruned).' across '.count($pruned).' attribute(s)');
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('  attributes FAILED (reported, not cached): '.count($failed));
            foreach ($failed as $slug => $msg) {
                $this->error("    - {$slug}: {$msg}");
            }
        }
    }
}
