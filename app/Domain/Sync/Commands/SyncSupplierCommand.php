<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Exceptions\JwtRefreshFailedException;
use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Jobs\MarkMissingSkusJob;
use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SkuMatcher;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooProductIterator;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Orchestrator for the daily supplier sync (SYNC-01..SYNC-13).
 *
 * Default DRY-RUN (D-04). `--live` enables writes (gated additionally by WOO_WRITE_ENABLED).
 * `--resume={run_id}` resumes an aborted/crashed run from its cursor (SYNC-03 + D-07).
 * `--live --dry-run` → error (D-04 mutually exclusive).
 */
final class SyncSupplierCommand extends BaseCommand
{
    protected $signature = 'sync:supplier
        {--live : Enable real Woo writes (default is dry-run per D-04)}
        {--dry-run : Explicit dry-run mode (default; error if combined with --live)}
        {--resume= : Resume an aborted/crashed run by its id}';

    protected $description = 'Pull supplier catalogue and sync to Woo (dry-run by default, --live for real writes)';

    protected function perform(): int
    {
        if ($this->option('live') && $this->option('dry-run')) {
            $this->error('Error: --live and --dry-run are mutually exclusive (D-04).');

            return SymfonyCommand::FAILURE;
        }

        $isLive = (bool) $this->option('live');
        $resumeId = $this->option('resume');

        $run = null;

        try {
            $run = $resumeId !== null
                ? $this->resumeRun((int) $resumeId, $isLive)
                : $this->newRun($isLive);

            $this->info(sprintf(
                'Sync run id=%d (dry_run=%s, resume=%s) — correlation_id=%s',
                $run->id,
                $run->dry_run ? 'true' : 'false',
                $resumeId !== null ? 'yes' : 'no',
                $run->correlation_id,
            ));

            /** @var SupplierClient $supplier */
            $supplier = app(SupplierClient::class);
            $supplierFeed = $supplier->fetchAllProducts();
            $this->info('Supplier feed: ' . count($supplierFeed) . ' SKUs fetched.');

            /** @var SkuMatcher $matcher */
            $matcher = app(SkuMatcher::class)->build($supplierFeed);
            unset($matcher);  // orchestrator doesn't use matcher directly post-build; chunk workers rebuild per job.

            /** @var WooProductIterator $iterator */
            $iterator = app(WooProductIterator::class);

            $wooSkusSeen = [];
            $startPage = $run->cursor_page > 0 ? $run->cursor_page : 1;

            foreach ($iterator->pages(fromPage: $startPage) as $pageData) {
                foreach ($pageData['skus'] as $row) {
                    $wooSkusSeen[(string) $row['sku']] = $row;
                }

                SyncChunkJob::dispatch(
                    runId: $run->id,
                    page: $pageData['page'],
                    skus: $pageData['skus'],
                    supplierFeed: $supplierFeed,
                );

                $this->info(sprintf(
                    '  Page %d: %d SKUs dispatched.',
                    $pageData['page'],
                    count($pageData['skus']),
                ));
            }

            // D-09 — supplier-only SKUs become ImportIssue + NewSupplierSkuDetected.
            foreach ($supplierFeed as $sku => $supplierRow) {
                if (! isset($wooSkusSeen[$sku])) {
                    ImportIssue::create([
                        'sku' => $sku,
                        'issue_type' => ImportIssue::TYPE_UNKNOWN_SKU,
                        'detected_at' => now(),
                        'last_seen_at' => now(),
                        'notes' => 'SKU present in supplier feed but no matching Woo product',
                        'correlation_id' => $run->correlation_id,
                    ]);

                    event(new NewSupplierSkuDetected(
                        sku: $sku,
                        supplierPrice: (string) $supplierRow['price'],
                        supplierStock: (int) $supplierRow['stock'],
                    ));

                    $run->incrementCounter('unknown_sku');
                }
            }

            // SYNC-06 + D-03 — Woo-only SKUs go to MarkMissingSkusJob with Woo state
            // (so SyncRunItem.old_price / old_stock populate — D-10 CSV contract).
            $missingRows = [];
            foreach ($wooSkusSeen as $sku => $row) {
                if (! isset($supplierFeed[$sku])) {
                    $missingRows[] = [
                        'sku' => $sku,
                        'type' => (string) ($row['type'] ?? 'simple'),
                        'woo_product_id' => (int) ($row['woo_product_id'] ?? 0),
                        'woo_variation_id' => isset($row['woo_variation_id']) ? (int) $row['woo_variation_id'] : null,
                        'is_custom_ms' => (bool) ($row['is_custom_ms'] ?? false),
                        'woo_price' => (string) ($row['price'] ?? ''),
                        'woo_stock' => (int) ($row['stock_quantity'] ?? 0),
                    ];
                }
            }

            if ($missingRows !== []) {
                MarkMissingSkusJob::dispatch($run->id, $missingRows);
                $this->info('Missing-SKU pass dispatched: ' . count($missingRows) . ' SKUs.');
            }

            $run->refresh()->finalise();

            $this->info(sprintf(
                'Run %d completed. updated=%d, skipped=%d, failed=%d, missing=%d, unknown_sku=%d.',
                $run->id,
                $run->updated_count,
                $run->skipped_count,
                $run->failed_count,
                $run->missing_count,
                $run->unknown_sku_count,
            ));

            return SymfonyCommand::SUCCESS;
        } catch (JwtRefreshFailedException $e) {
            // D-06(c) — SupplierClient reports token can't be refreshed. Flag + abort.
            if ($run !== null) {
                app(AbortGuard::class)->triggerJwtFailure($run->id);
                $run->abort(SyncRun::ABORT_JWT_REFRESH, $e->getMessage());
            }
            $this->error('Sync ABORTED: reason=jwt_refresh, message=' . $e->getMessage());

            return SymfonyCommand::FAILURE;
        } catch (SyncAbortException $e) {
            $run?->abort($e->reason, $e->getMessage());
            $this->error('Sync ABORTED: reason=' . $e->reason . ', message=' . $e->getMessage());

            return SymfonyCommand::FAILURE;
        }
    }

    private function newRun(bool $isLive): SyncRun
    {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();

        $run = SyncRun::create([
            'started_at' => now(),
            'status' => SyncRun::STATUS_RUNNING,
            'dry_run' => ! $isLive,
            'correlation_id' => $correlationId,
            'cursor_page' => 0,
        ]);

        app(Auditor::class)->record('sync.run.started', [
            'run_id' => $run->id,
            'dry_run' => $run->dry_run,
        ]);

        return $run;
    }

    private function resumeRun(int $id, bool $isLive): SyncRun
    {
        $run = SyncRun::findResumable($id);

        if ($isLive && $run->dry_run) {
            // Flipping dry → live on resume is allowed — operator explicitly opted in.
            $run->update(['dry_run' => false]);
        }

        app(Auditor::class)->record('sync.run.resumed', [
            'run_id' => $run->id,
            'from_page' => $run->cursor_page,
        ]);

        return $run;
    }
}
