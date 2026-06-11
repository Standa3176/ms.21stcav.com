<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260611-sr7 — products:backfill-brand-from-name.
 *
 * Closes the M-1 Phase 7 gap row: 3,231 of 3,922 live products (82.4%) carry
 * NULL brand_id because the legacy WC import never carried brand assignment.
 *
 * Heuristic: auto-create products (260606-q3o) follow the
 *   `{Brand} {category words} {SKU}`
 * naming convention, so the FIRST WORD of `Product.name` is reliably the
 * brand candidate. Edge case: a small subset of products lead with the SKU
 * itself ("AV1E3AA#AC3 Poly collaboration device") — detect a SKU-shaped
 * first token via SKU_LIKE_PATTERN and try the SECOND word instead.
 *
 * Drift-prevention contract:
 *   - TaxonomyResolver is the SINGLE source of brand fuzzy matching (also
 *     consumed by BackfillMerchantFeedCommand::backfillBrand). This command
 *     IMPORTS TaxonomyResolver via constructor DI; under NO circumstances
 *     does it re-implement name -> brand_id resolution.
 *   - The FUZZY_THRESHOLD const (0.85) is owned by TaxonomyResolver, NOT
 *     plumbed through here. The --min-confidence CLI option is INFORMATIONAL
 *     ONLY — surfaced in the run banner so the operator UX matches the
 *     written brief, but the value never reaches the resolver.
 *
 * Scope guardrails:
 *   - NO Woo writes — WooFieldComparator silent-skips brand_id meta (both
 *     sides null, no parity diff). Pure MS-side data quality lift.
 *   - NO scheduled cron — one-shot operator-triggered.
 *   - Products with brand_id already set are EXCLUDED by the candidate
 *     query — the run is a pure backfill, not a re-resolver. A re-run is
 *     idempotent against the same candidate slice.
 *   - Per-product DB::table('products')->update on a single column; no
 *     transactional wrapping (matches BackfillMerchantFeedCommand::backfillBrand
 *     parity, which is the precedent for single-column brand_id writes).
 *
 * Activity log caveat: this command uses DB::table()->update (not Eloquent
 * save), so Product's LogsActivity trait does NOT fire. Operator inspection
 * of /admin/products?brand_id=X after the run is the agreed accountability
 * surface for the M-1 closure event.
 *
 *   php artisan products:backfill-brand-from-name --dry-run
 *   php artisan products:backfill-brand-from-name --skus=960-001503,AV1E3AA#AC3
 *   php artisan products:backfill-brand-from-name --limit=50
 *   php artisan products:backfill-brand-from-name --min-confidence=0.95   # informational banner echo
 */
// Not `final` so the Pest feature test can substitute the TaxonomyResolver via
// container instance() with an anonymous-subclass stub (matches the
// HydrateProductStockFromOffersCommand + BackfillMerchantFeedCommand pattern).
class BackfillProductBrandFromNameCommand extends BaseCommand
{
    /**
     * Grep-discoverability anchor for the SKU-shaped first-token detector.
     *
     * Prod sample that motivated this: "AV1E3AA#AC3 Poly collaboration device"
     * (HP Poly device leading with the part-number SKU). When the first word
     * matches this pattern AND its length >= MIN_SKU_LIKE_LENGTH (>5 per
     * brief), the SECOND word is used as the brand candidate instead.
     *
     * Pattern intentionally case-insensitive; restricted to A-Z/0-9 plus
     * hyphen and '#' (the two non-alphanumeric chars HP SKUs use). Tighten
     * here — never duplicate the regex elsewhere — if future false-positives
     * surface (e.g. legitimate brand names that happen to be 6+ chars of
     * uppercase + digits).
     */
    private const SKU_LIKE_PATTERN = '/^[A-Z0-9][A-Z0-9\-#]+$/i';

    /**
     * Minimum length for the SKU_LIKE_PATTERN trigger. Brief specified
     * `strlen > 5`, so 6 is the inclusive floor here.
     */
    private const MIN_SKU_LIKE_LENGTH = 6;

    protected $signature = 'products:backfill-brand-from-name
        {--skus= : Comma-separated SKU list; default = all NULL-brand live products}
        {--limit=0 : Cap product count (0=unbounded)}
        {--min-confidence=0.85 : Informational — TaxonomyResolver owns FUZZY_THRESHOLD const}
        {--dry-run : Print plan without writing}';

    protected $description = 'Backfill products.brand_id from name-first-word via TaxonomyResolver (260611-sr7 — closes M-1 Phase 7 gap). MS-side only — no Woo writes.';

    public function __construct(private readonly TaxonomyResolver $taxonomy)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Parse options ─────────────────────────────────────────────────
        $skusRaw = (string) ($this->option('skus') ?? '');
        $skusFilter = array_values(array_filter(
            array_map('trim', explode(',', $skusRaw)),
            static fn (string $s): bool => $s !== '',
        ));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $minConfidence = (float) $this->option('min-confidence');

        if ($minConfidence < 0 || $minConfidence > 1) {
            $this->error("Invalid --min-confidence value '{$minConfidence}' — must be between 0 and 1 (informational only; TaxonomyResolver owns the threshold).");

            return SymfonyCommand::FAILURE;
        }

        // ── 2. Build candidate query ─────────────────────────────────────────
        // Mirrors BackfillMerchantFeedCommand::backfillBrand candidate shape:
        // status='publish' + (brand_id IS NULL OR brand_id = 0). --skus
        // override replaces the NULL filter with whereIn but keeps the publish
        // constraint for parity.
        $query = Product::query()->where('status', 'publish');
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
        } else {
            $query->where(function ($q): void {
                $q->whereNull('brand_id')->orWhere('brand_id', 0);
            });
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        // ── 3. Count candidates (cursor() doesn't expose total mid-stream) ───
        $candidateCount = (clone $query)->count();

        // ── 4. Pre-load brand-name map ONCE for sample-table display ─────────
        $brandNameById = [];
        foreach ($this->taxonomy->allBrands() as $term) {
            $id = (int) ($term['id'] ?? 0);
            $name = (string) ($term['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $brandNameById[$id] = $name;
            }
        }

        // ── 5. Run banner — surface operator's --min-confidence verbatim ─────
        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'products:backfill-brand-from-name — candidates='.$candidateCount
            .' min-confidence='.number_format($minConfidence, 2)
            .($limit > 0 ? ' limit='.$limit : '')
            .($skusFilter !== [] ? ' --skus='.implode(',', $skusFilter) : '')
        );

        // ── 6. Counters + buffers ────────────────────────────────────────────
        $scanned = 0;
        $resolved = 0;
        $unresolved = 0;
        $skippedSkuPrefix = 0;
        $errors = 0;

        /** @var array<string, int> $unresolvedHistogram  candidate => hits */
        $unresolvedHistogram = [];
        /** @var array<int, array<int, string>> $sample */
        $sample = [];
        $sampleCap = 20;

        // ── 7. Per-product loop ──────────────────────────────────────────────
        foreach ($query->cursor() as $product) {
            $scanned++;

            $name = trim((string) ($product->name ?? ''));
            if ($name === '') {
                $unresolved++;
                $unresolvedHistogram[''] = ($unresolvedHistogram[''] ?? 0) + 1;
                if (count($sample) < $sampleCap) {
                    $sample[] = [(string) $product->sku, '(empty name)', '', '', '', 'unresolved_empty_name'];
                }

                continue;
            }

            $words = preg_split('/\s+/', $name) ?: [];
            $word0 = $words[0] ?? '';
            if ($word0 === '') {
                $unresolved++;
                $unresolvedHistogram[''] = ($unresolvedHistogram[''] ?? 0) + 1;
                if (count($sample) < $sampleCap) {
                    $sample[] = [(string) $product->sku, '(empty first word)', '', '', '', 'unresolved_empty_first_word'];
                }

                continue;
            }

            // SKU-shaped first-token detector. When matched AND length >=
            // MIN_SKU_LIKE_LENGTH, skip the SKU and try the second word.
            $candidate = $word0;
            $resolvedWord = $word0;
            if (
                preg_match(self::SKU_LIKE_PATTERN, $word0) === 1
                && mb_strlen($word0) >= self::MIN_SKU_LIKE_LENGTH
            ) {
                $skippedSkuPrefix++;
                $word1 = $words[1] ?? null;
                if ($word1 === null || $word1 === '') {
                    $unresolved++;
                    $unresolvedHistogram[$word0] = ($unresolvedHistogram[$word0] ?? 0) + 1;
                    if (count($sample) < $sampleCap) {
                        $sample[] = [(string) $product->sku, $word0, '', '', '', 'unresolved_sku_only_name'];
                    }

                    continue;
                }
                $candidate = $word1;
                $resolvedWord = $word1;
            }

            // Resolution call — TaxonomyResolver is the SINGLE source for
            // brand fuzzy matching. Exceptions are swallowed per-product and
            // bumped to the errors counter; the batch continues.
            try {
                $brandId = $this->taxonomy->resolveBrand($candidate);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('backfill_brand_from_name.row_failed', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'candidate' => $candidate,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($brandId === null) {
                $unresolved++;
                $unresolvedHistogram[$candidate] = ($unresolvedHistogram[$candidate] ?? 0) + 1;
                if (count($sample) < $sampleCap) {
                    $sample[] = [
                        (string) $product->sku,
                        $word0,
                        $resolvedWord,
                        '',
                        '',
                        'unresolved_below_threshold',
                    ];
                }

                continue;
            }

            $brandName = $brandNameById[$brandId] ?? '(unknown)';

            if ($dryRun) {
                $resolved++;
                if (count($sample) < $sampleCap) {
                    $sample[] = [
                        (string) $product->sku,
                        $word0,
                        $resolvedWord,
                        $brandName,
                        (string) $brandId,
                        'would_update',
                    ];
                }

                continue;
            }

            // Live path. Single-column update mirrors BackfillMerchantFeedCommand
            // lines 691-697 exactly. No transactional wrapping (single column).
            $affected = DB::table('products')
                ->where('id', $product->id)
                ->update(['brand_id' => $brandId, 'updated_at' => now()]);

            if ($affected > 0) {
                $resolved++;
                if (count($sample) < $sampleCap) {
                    $sample[] = [
                        (string) $product->sku,
                        $word0,
                        $resolvedWord,
                        $brandName,
                        (string) $brandId,
                        'updated',
                    ];
                }
            }
        }

        // ── 8. Output: counter table ─────────────────────────────────────────
        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['scanned', $scanned],
                ['resolved', $resolved],
                ['unresolved', $unresolved],
                ['skipped_sku_prefix', $skippedSkuPrefix],
                ['errors', $errors],
            ],
        );

        // ── 9. Top-30 unresolved candidates table (when histogram non-empty) ─
        if ($unresolvedHistogram !== []) {
            arsort($unresolvedHistogram);
            // Stable secondary sort by candidate ASC for ties.
            $rows = [];
            foreach ($unresolvedHistogram as $cand => $hits) {
                $rows[] = ['candidate' => $cand, 'hits' => $hits];
            }
            usort($rows, static function (array $a, array $b): int {
                if ($a['hits'] !== $b['hits']) {
                    return $b['hits'] <=> $a['hits'];
                }

                return strcmp((string) $a['candidate'], (string) $b['candidate']);
            });
            $top30Rows = array_map(
                static fn (array $r): array => [(string) $r['candidate'], (int) $r['hits']],
                array_slice($rows, 0, 30),
            );

            $this->newLine();
            $this->info('Top-30 unresolved candidates:');
            $this->table(['Candidate', 'Hits'], $top30Rows);
        }

        // ── 10. Sample-row table (top 20) when buffer non-empty ──────────────
        if ($sample !== []) {
            $this->newLine();
            $this->info('Sample (first '.$sampleCap.'):');
            $this->table(
                ['SKU', 'First word', 'Resolved word', 'Brand name', 'brand_id', 'Outcome'],
                $sample,
            );
        }

        return SymfonyCommand::SUCCESS;
    }
}
