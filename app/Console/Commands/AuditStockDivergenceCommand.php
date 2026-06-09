<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260609-nku — products:audit-stock-divergence.
 *
 * Detect "phantom stock" SKUs where:
 *   - MS local stock_quantity = 0
 *   - every fresh supplier reports stock = 0 (NOT EXISTS subquery on
 *     supplier_offer_snapshots gated on SupplierFreshnessResolver::freshSupplierIds())
 *   - Woo claims stock_quantity > 0
 *
 * Today's live observation `45-243-224` (Ergotron arm): MS=0, suppliers
 * fresh-and-empty, Woo=7 — customer can order, hits backorder limbo. We
 * currently have zero visibility into the scale. This command surfaces it.
 *
 * Mirrors the 260607-t6w category-audit shape (predicate → snapshot → page
 * → widget → bulk action) and the 260608-g8x SupplierFreshnessResolver
 * single-source-of-truth principle: NO duplicated DATEDIFF SQL — the
 * resolver owns the fresh predicate, this command consumes its output.
 *
 * Snapshot semantics: TRUNCATE-and-replace inside DB::transaction(). The
 * operator wants today's actionable list, not a longitudinal trend.
 *
 *   php artisan products:audit-stock-divergence --dry-run
 *   php artisan products:audit-stock-divergence --limit=100
 *   php artisan products:audit-stock-divergence --chunk=50
 *
 * Run guidance: Weekly Mon 09:15 London cron (routes/console.php) — sits
 * AFTER the woo:import-products (09:00) and supplier:db-sync (09:05) safety-
 * net retries so the products.stock_quantity column reflects today's
 * freshest Woo pull before the NOT EXISTS predicate runs.
 */
class AuditStockDivergenceCommand extends BaseCommand
{
    protected $signature = 'products:audit-stock-divergence
        {--limit=0 : Cap candidate set (0=unbounded)}
        {--chunk=50 : Woo IDs per batch (Woo per_page cap 100)}
        {--dry-run : Print outcomes without writing snapshot}';

    protected $description = 'Detect phantom-stock SKUs (MS=0 + every fresh supplier=0 + Woo>0). TRUNCATE-and-replaces stock_divergence_findings every run; weekly Mon 09:15 London cron.';

    /** Bulk INSERT chunk size — keeps any single round-trip well under packet limits. */
    private const INSERT_CHUNK_SIZE = 500;

    /** 200ms between Woo batches — matches today's manual probe + protects CWP IO concurrency. */
    private const WOO_BATCH_SLEEP_MICROSECONDS = 200_000;

    /** Empty-set guard mirroring 260608-g8x — whereIn on this sentinel never matches. */
    private const EMPTY_SUPPLIER_SENTINEL = '__NONE__';

    public function __construct(
        private readonly WooClient $woo,
        private readonly SupplierFreshnessResolver $freshness,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $runId = (string) Str::ulid();
        $auditedAt = now();
        $chunkSize = max(1, min(100, (int) $this->option('chunk')));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // Fresh supplier ids — single source of truth (260608-g8x). DO NOT
        // duplicate the DATEDIFF SQL here; the resolver owns the policy.
        $freshIds = $this->freshness->freshSupplierIds()->all();
        $sentinelFreshIds = $freshIds === [] ? [self::EMPTY_SUPPLIER_SENTINEL] : $freshIds;

        // Candidate query — MS=0, has woo_product_id, published, AND no fresh
        // supplier reports stock>0 in the last 7 days. The `__NONE__` sentinel
        // mirrors 260608-g8x's empty-set guard so whereIn never collapses to
        // a true match.
        $query = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id')
            ->where('stock_quantity', 0)
            ->whereNotExists(function ($q) use ($sentinelFreshIds): void {
                $q->select(DB::raw(1))
                    ->from('supplier_offer_snapshots as s')
                    ->whereColumn('s.sku', DB::raw('LOWER(TRIM(products.sku))'))
                    ->whereIn('s.supplier_id', $sentinelFreshIds)
                    ->where('s.stock', '>', 0)
                    ->where('s.recorded_at', '>=', now()->subDays(7));
            });

        if ($limit > 0) {
            $query->limit($limit);
        }

        // Counters.
        $candidatesScanned = 0;
        $wooResponsesReceived = 0;
        $matched = 0;
        $divergentFound = 0;
        $wooNotFound = 0;
        $error = 0;
        $totalPhantomUnits = 0;

        // Accumulate divergent rows in memory — bounded by the candidate set
        // (typical: hundreds, worst case low thousands; sample row ~150 bytes).
        $findings = collect();

        // Stream candidates via cursor() + chunk in PHP — keeps memory flat for
        // the 3,000+ candidate worst case while staying compatible with the
        // Eloquent query builder shape above.
        $buffer = [];
        foreach ($query->cursor() as $product) {
            $buffer[] = $product;
            if (count($buffer) < $chunkSize) {
                continue;
            }
            $this->processChunk(
                collect($buffer),
                $runId,
                $auditedAt,
                $findings,
                $candidatesScanned,
                $wooResponsesReceived,
                $matched,
                $divergentFound,
                $wooNotFound,
                $error,
                $totalPhantomUnits,
            );
            $buffer = [];
            usleep(self::WOO_BATCH_SLEEP_MICROSECONDS);
        }
        if ($buffer !== []) {
            $this->processChunk(
                collect($buffer),
                $runId,
                $auditedAt,
                $findings,
                $candidatesScanned,
                $wooResponsesReceived,
                $matched,
                $divergentFound,
                $wooNotFound,
                $error,
                $totalPhantomUnits,
            );
        }

        // Outcome table — render before write so dry-run + live both show it.
        $this->renderCounters(
            $dryRun,
            $candidatesScanned,
            $wooResponsesReceived,
            $matched,
            $divergentFound,
            $wooNotFound,
            $error,
            $totalPhantomUnits,
        );

        // Dry-run sample — top 20 by phantom_units DESC so the operator gets
        // an actionable preview without touching the snapshot table.
        if ($dryRun) {
            $sample = $findings
                ->sortByDesc('phantom_units')
                ->take(20)
                ->map(static fn (array $r): array => [
                    $r['sku'],
                    $r['woo_product_id'],
                    $r['ms_stock_quantity'],
                    $r['woo_stock_quantity'],
                    $r['phantom_units'],
                ])
                ->values()
                ->all();
            if ($sample !== []) {
                $this->newLine();
                $this->info('Top 20 divergent SKUs (dry-run sample):');
                $this->table(
                    ['SKU', 'Woo #', 'MS qty', 'Woo qty', 'Phantom diff'],
                    $sample,
                );
            }
            $this->newLine();
            $this->info("Dry-run — exiting without writes. run_id: {$runId}");

            return SymfonyCommand::SUCCESS;
        }

        // Live path: TRUNCATE-and-replace inside a transaction so the table
        // never sits in a half-old half-new state. Mirrors 260607-t6w semantics.
        DB::transaction(function () use ($findings): void {
            DB::table('stock_divergence_findings')->truncate();
            foreach ($findings->chunk(self::INSERT_CHUNK_SIZE) as $batch) {
                DB::table('stock_divergence_findings')->insert($batch->all());
            }
        });

        $this->newLine();
        $this->info("Stock divergence audit complete. run_id: {$runId}");

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  Collection<int, Product>  $chunk
     * @param  Collection<int, array<string, mixed>>  $findings
     */
    private function processChunk(
        Collection $chunk,
        string $runId,
        Carbon $auditedAt,
        Collection $findings,
        int &$candidatesScanned,
        int &$wooResponsesReceived,
        int &$matched,
        int &$divergentFound,
        int &$wooNotFound,
        int &$error,
        int &$totalPhantomUnits,
    ): void {
        $wooIds = $chunk->pluck('woo_product_id')->map(static fn ($v): int => (int) $v)->all();

        try {
            $response = $this->woo->get('products', [
                'include' => implode(',', $wooIds),
                'per_page' => count($wooIds),
                'orderby' => 'include',
            ]);
        } catch (\Throwable $e) {
            $error += count($chunk);
            $this->error('Woo batch failed: '.$e->getMessage());

            return;
        }

        $wooResponsesReceived += count($response);

        // Build lookup map keyed by Woo's `id`. Woo SDK returns stdClass for
        // list endpoints (single-product GET decodes assoc); normalise both
        // shapes via json round-trip so downstream array-access is uniform.
        $byId = collect($response)
            ->map(static fn ($row) => is_array($row) ? $row : json_decode(json_encode($row), true))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['id']))
            ->keyBy(static fn (array $row): int => (int) $row['id']);

        foreach ($chunk as $product) {
            $candidatesScanned++;
            $wooId = (int) $product->woo_product_id;
            $wooRow = $byId->get($wooId);

            if ($wooRow === null) {
                $wooNotFound++;

                continue;
            }

            $wooQty = (int) ($wooRow['stock_quantity'] ?? 0);
            if ($wooQty <= 0) {
                $matched++;

                continue;
            }

            // Divergent: Woo claims qty>0 but MS+fresh suppliers say 0.
            $divergentFound++;
            $phantom = $wooQty;            // ms_stock_quantity is 0 by query construction
            $totalPhantomUnits += $phantom;

            $wooModified = isset($wooRow['date_modified']) && $wooRow['date_modified'] !== ''
                ? Carbon::parse((string) $wooRow['date_modified'])
                : null;

            $findings->push([
                'sku' => (string) $product->sku,
                'name' => $product->name !== null ? (string) $product->name : null,
                'woo_product_id' => $wooId,
                'ms_stock_quantity' => 0,
                'woo_stock_quantity' => $wooQty,
                'phantom_units' => $phantom,
                'woo_last_modified' => $wooModified,
                'ms_last_synced_at' => $product->last_synced_at,
                'status' => 'woo_overcount',
                'run_id' => $runId,
                'audited_at' => $auditedAt,
                'created_at' => $auditedAt,
                'updated_at' => $auditedAt,
            ]);
        }
    }

    private function renderCounters(
        bool $dryRun,
        int $candidatesScanned,
        int $wooResponsesReceived,
        int $matched,
        int $divergentFound,
        int $wooNotFound,
        int $error,
        int $totalPhantomUnits,
    ): void {
        $this->newLine();
        $this->info($dryRun ? 'DRY-RUN — stock divergence audit (no DB writes):' : 'Stock divergence audit:');
        $this->table(
            ['Counter', 'Value'],
            [
                ['candidates_scanned', $candidatesScanned],
                ['woo_responses_received', $wooResponsesReceived],
                ['matched (Woo agrees MS=0)', $matched],
                ['divergent_found', $divergentFound],
                ['woo_not_found', $wooNotFound],
                ['error', $error],
                ['total_phantom_units', $totalPhantomUnits],
            ],
        );
    }
}
