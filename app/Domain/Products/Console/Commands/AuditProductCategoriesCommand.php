<?php

declare(strict_types=1);

namespace App\Domain\Products\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260607-t6w — Weekly rule-based category audit.
 *
 * Runs every Friday 22:00 London via routes/console.php so Monday morning the
 * ecom manager has a fresh actionable report at /admin/category-audit.
 *
 * Scans every status='publish' Product, classifies via most-severe-wins rule:
 *
 *   1 missing       — category_id IS NULL
 *   2 orphaned      — category_id non-null but not in TaxonomyResolver (deleted upstream)
 *   3 uncategorized — category_id maps to 'Uncategorized' / 'Uncategorised' (UK)
 *   4 suspicious    — brand in BRAND_NATURAL_HOMES, category ROOT not in brand's homes
 *   HEALTHY (skip)  — none of the above; not recorded
 *
 * Free + deterministic — no Claude spend at scan time (typical runtime <30s).
 * The per-row Filament page action invokes products:assign-taxonomy on demand
 * when an operator chooses to spend Claude on a specific SKU.
 *
 * Snapshot semantics: TRUNCATE category_audit_findings BEFORE the cursor loop,
 * then bulk INSERT new rows in 500-row chunks. Operator wants today's state,
 * not history — a row not in the latest snapshot is no longer a finding.
 *
 * --dry-run prints the summary table without TRUNCATE or INSERT (safe preview).
 */
final class AuditProductCategoriesCommand extends BaseCommand
{
    protected $signature = 'products:audit-categories
        {--dry-run : Print summary, no DB writes}';

    protected $description = 'Weekly rule-based audit of live product category assignments. Stores findings to category_audit_findings for ecom manager review.';

    /**
     * Brand → list of category ROOT names where this brand legitimately lives.
     *
     * Operator-tunable via PR. Keep deliberately small (under ~20 brands) so it
     * stays curated by hand. DB-managed taxonomy lives elsewhere; this is the
     * deliberately-hand-maintained 'natural home' map used by the
     * suspicious-brand-category-mismatch predicate ONLY.
     *
     * A brand absent from this map will NEVER trigger the suspicious bucket
     * (the rule only fires when we have a strong prior about where the brand
     * belongs). Adding a brand here is an opinion the team is comfortable
     * defending — leaving it out is the safer default.
     *
     * @var array<string, array<int, string>>
     */
    public const BRAND_NATURAL_HOMES = [
        'Samsung' => ['Displays', 'Large Format Displays'],
        'LG' => ['Displays', 'Large Format Displays'],
        'Sony' => ['Displays', 'Projection', 'Video Conferencing & Collaboration'],
        'Panasonic' => ['Projection', 'Displays'],
        'Epson' => ['Projection'],
        'Yealink' => ['Video Conferencing & Collaboration', 'Audio Conferencing'],
        'Logitech' => ['Video Conferencing & Collaboration', 'Audio Conferencing'],
        'Poly' => ['Video Conferencing & Collaboration', 'Audio Conferencing'],
        'Barco' => ['Video Conferencing & Collaboration'],
        'Neat' => ['Video Conferencing & Collaboration'],
    ];

    /** Defensive cap on parent walks (handles malformed taxonomy trees). */
    private const ROOT_WALK_MAX_HOPS = 20;

    /** Bulk INSERT chunk size — keeps any single round-trip well under packet limits. */
    private const INSERT_CHUNK_SIZE = 500;

    public function __construct(private readonly TaxonomyResolver $taxonomy)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $runId = (string) Str::ulid();
        $auditedAt = now();
        $startedAtEpoch = microtime(true);

        // Build O(1) parent-walk map keyed by category id.
        $categoriesMeta = $this->taxonomy->allCategoriesWithMeta();
        $byId = [];
        foreach ($categoriesMeta as $term) {
            $byId[(int) $term['id']] = $term;
        }

        // Build O(1) brand-name lookup keyed by brand id.
        $brands = $this->taxonomy->allBrands();
        $brandNameById = [];
        foreach ($brands as $term) {
            $brandNameById[(int) $term['id']] = (string) $term['name'];
        }

        // Case-insensitive BRAND_NATURAL_HOMES lookup index.
        $homesIndex = [];
        foreach (self::BRAND_NATURAL_HOMES as $brand => $roots) {
            $homesIndex[strtolower($brand)] = $roots;
        }

        $counts = [
            'missing' => 0,
            'orphaned' => 0,
            'uncategorized' => 0,
            'suspicious' => 0,
            'healthy' => 0,
        ];

        // TRUNCATE BEFORE the loop on live runs — snapshot semantics. Doing
        // this BEFORE the cursor stream means a single audit run never leaves
        // the table in a half-old half-new state.
        if (! $dryRun) {
            DB::table('category_audit_findings')->truncate();
        }

        $buffer = [];

        Product::query()
            ->where('status', 'publish')
            ->cursor()
            ->each(function (Product $product) use (
                $byId,
                $brandNameById,
                $homesIndex,
                &$counts,
                &$buffer,
                $dryRun,
                $runId,
                $auditedAt,
            ): void {
                $bucket = $this->classify($product, $byId, $brandNameById, $homesIndex);

                if ($bucket === null) {
                    $counts['healthy']++;

                    return;
                }

                $counts[$bucket['issue_type']]++;

                if ($dryRun) {
                    return;
                }

                $buffer[] = [
                    'run_id' => $runId,
                    'product_id' => (int) $product->id,
                    'sku' => (string) $product->sku,
                    'brand_id' => $product->brand_id !== null ? (int) $product->brand_id : null,
                    'brand_name' => $product->brand_id !== null
                        ? ($brandNameById[(int) $product->brand_id] ?? '')
                        : '',
                    'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
                    'category_name' => $bucket['category_name'],
                    'category_root_name' => $bucket['category_root_name'] ?? null,
                    'issue_type' => $bucket['issue_type'],
                    'severity' => $bucket['severity'],
                    'audited_at' => $auditedAt,
                    'created_at' => $auditedAt,
                    'updated_at' => $auditedAt,
                ];

                if (count($buffer) >= self::INSERT_CHUNK_SIZE) {
                    DB::table('category_audit_findings')->insert($buffer);
                    $buffer = [];
                }
            });

        // Flush remaining buffered rows.
        if (! $dryRun && $buffer !== []) {
            DB::table('category_audit_findings')->insert($buffer);
        }

        $duration = (int) round(microtime(true) - $startedAtEpoch);
        $total = $counts['missing'] + $counts['orphaned'] + $counts['uncategorized'] + $counts['suspicious'];

        $this->newLine();
        $this->info($dryRun ? 'DRY-RUN — category audit (no DB writes):' : 'Category audit complete:');
        $this->table(['Bucket', 'Count'], [
            ['1 missing', $counts['missing']],
            ['2 orphaned', $counts['orphaned']],
            ['3 uncategorized', $counts['uncategorized']],
            ['4 suspicious', $counts['suspicious']],
            ['— total findings', $total],
            ['(healthy — skipped)', $counts['healthy']],
        ]);
        $this->line(sprintf('Runtime: %ds · run_id: %s', $duration, $runId));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Most-severe-wins classification. Returns null when HEALTHY (no row).
     *
     * @param  array<int, array{id:int, name:string, parent:int, count:int}>  $byId
     * @param  array<int, string>  $brandNameById
     * @param  array<string, array<int, string>>  $homesIndex  (case-insensitive)
     * @return array{issue_type:string, severity:int, category_name:string, category_root_name?:string}|null
     */
    private function classify(Product $product, array $byId, array $brandNameById, array $homesIndex): ?array
    {
        // a) missing — category_id NULL
        if ($product->category_id === null) {
            return [
                'issue_type' => 'missing',
                'severity' => 1,
                'category_name' => '',
            ];
        }

        $categoryId = (int) $product->category_id;

        // b) orphaned — category_id non-null but not in taxonomy
        if (! isset($byId[$categoryId])) {
            return [
                'issue_type' => 'orphaned',
                'severity' => 2,
                'category_name' => "(unknown #{$categoryId})",
            ];
        }

        $categoryName = (string) ($byId[$categoryId]['name'] ?? '');

        // c) uncategorized — name matches 'Uncategorized' / 'Uncategorised'
        if (preg_match('/^uncategori[sz]ed$/i', $categoryName) === 1) {
            return [
                'issue_type' => 'uncategorized',
                'severity' => 3,
                'category_name' => $categoryName,
            ];
        }

        // d) suspicious — brand has natural homes, category ROOT not among them
        if ($product->brand_id !== null) {
            $brandName = $brandNameById[(int) $product->brand_id] ?? null;
            if ($brandName !== null && $brandName !== '') {
                $brandHomes = $homesIndex[strtolower($brandName)] ?? null;
                if ($brandHomes !== null) {
                    $rootName = $this->rootName($categoryId, $byId);
                    if ($rootName !== null && ! in_array($rootName, $brandHomes, true)) {
                        return [
                            'issue_type' => 'suspicious',
                            'severity' => 4,
                            'category_name' => $categoryName,
                            'category_root_name' => $rootName,
                        ];
                    }
                }
            }
        }

        // HEALTHY
        return null;
    }

    /**
     * Walk parent ids up to 0 and return the topmost ancestor's name.
     *
     * Defensive cycle guard via $seen[] tracking + hard cap at 20 hops
     * (mirror TaxonomyResolver::buildPath). Returns null if the chain
     * breaks (orphan ancestor) or if the starting id is unknown.
     *
     * @param  array<int, array{id:int, name:string, parent:int, count:int}>  $byId
     */
    private function rootName(int $categoryId, array $byId): ?string
    {
        if (! isset($byId[$categoryId])) {
            return null;
        }

        $cursor = $categoryId;
        $seen = [];
        $hops = 0;

        while (true) {
            if ($hops++ >= self::ROOT_WALK_MAX_HOPS) {
                return null;
            }
            if (! isset($byId[$cursor]) || isset($seen[$cursor])) {
                return null;
            }
            $seen[$cursor] = true;

            $parent = (int) ($byId[$cursor]['parent'] ?? 0);
            if ($parent === 0) {
                return (string) ($byId[$cursor]['name'] ?? '');
            }
            $cursor = $parent;
        }
    }
}
