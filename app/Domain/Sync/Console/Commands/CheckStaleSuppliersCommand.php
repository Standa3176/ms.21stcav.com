<?php

declare(strict_types=1);

namespace App\Domain\Sync\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260608-g8x — snapshot every supplier's freshness state.
 *
 * Mirror shape of CompetitorCheckStaleCommand (Phase 5 Plan 04b) but at the
 * supplier-offer level. Runs Mon-Fri 07:45 London via routes/console.php —
 * 45 minutes after the 07:00 supplier:db-sync so today's sync had a chance
 * to write fresh recorded_at rows before classification fires.
 *
 * Discovery upsert: on every (non-dry-run) invocation, distinct supplier_ids
 * observed in supplier_offer_snapshots get an `updateOrCreate` row in the
 * `suppliers` table so the operator has a per-supplier row to edit
 * `stale_after_days` on.
 *
 * Snapshot semantics: TRUNCATE-and-replace (mirrors 260607-t6w
 * category_audit_findings). One ULID `run_id` per invocation; the latest
 * run_id = "current."
 */
final class CheckStaleSuppliersCommand extends BaseCommand
{
    protected $signature = 'suppliers:check-stale
        {--dry-run : Print snapshot table + counts; do NOT write to supplier_freshness_snapshots or upsert suppliers}';

    protected $description = 'Snapshot every supplier\'s fresh/amber/stale state from supplier_offer_snapshots into supplier_freshness_snapshots (Mon-Fri 07:45 London, post-supplier-sync).';

    public function __construct(
        private readonly SupplierFreshnessResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('suppliers:check-stale — '.($dryRun ? 'DRY-RUN' : 'LIVE'));

        try {
            // ─── 1. Discover distinct supplier_ids from supplier_offer_snapshots ───
            $discovered = DB::table('supplier_offer_snapshots')
                ->selectRaw('supplier_id, MAX(supplier_name) AS supplier_name')
                ->whereNotNull('supplier_id')
                ->where('supplier_id', '!=', '')
                ->groupBy('supplier_id')
                ->get();

            $this->info(sprintf(
                'Discovered %d distinct supplier_id(s) in supplier_offer_snapshots.',
                $discovered->count(),
            ));

            // ─── 2. Upsert `suppliers` rows so each observed supplier_id has a row ───
            $existingSuppliers = Supplier::query()->count();
            if (! $dryRun) {
                foreach ($discovered as $d) {
                    $sid = (string) $d->supplier_id;
                    if ($sid === '') {
                        continue;
                    }
                    Supplier::updateOrCreate(
                        ['supplier_id' => $sid],
                        ['name' => (string) ($d->supplier_name ?? '') ?: null],
                    );
                }
                // Force the resolver to re-read suppliers (override table) for this run.
                $this->resolver->forget();
            }
            $totalSuppliers = Supplier::query()->count();
            $this->line(sprintf(
                '  suppliers table: %d row(s)%s.',
                $totalSuppliers,
                $dryRun ? ' (dry-run — upsert skipped)' : '',
            ));

            // ─── 3. Resolve every known supplier (snapshots ∪ suppliers) ───
            $knownIds = $this->resolver->allKnownSupplierIds()->all();

            $runId = (string) Str::ulid();
            $createdAt = Carbon::now();

            $counts = ['fresh' => 0, 'amber' => 0, 'stale' => 0, 'unknown' => 0];
            $rows = [];
            $staleList = []; // for the bottom-of-terminal explicit list

            foreach ($knownIds as $sid) {
                $sid = (string) $sid;
                $status = $this->resolver->classify($sid);
                $threshold = $this->resolver->thresholdDaysFor($sid);
                $latest = $this->resolver->latestRecordedAtFor($sid);
                $days = $this->resolver->daysSinceFor($sid);
                $name = $this->resolver->nameFor($sid);

                $counts[$status] = ($counts[$status] ?? 0) + 1;

                $rows[] = [
                    'supplier_id' => $sid,
                    'supplier_name' => $name,
                    'latest_recorded_at' => $latest?->toDateString(),
                    'days_since' => $days,
                    'threshold_days' => $threshold,
                    'status' => $status,
                    'run_id' => $runId,
                    'created_at' => $createdAt,
                ];

                if ($status === 'stale') {
                    $staleList[] = [
                        'supplier_id' => $sid,
                        'name' => $name,
                        'days_since' => $days,
                        'threshold_days' => $threshold,
                    ];
                }
            }

            // ─── 4. Write snapshot (TRUNCATE-and-replace) OR dry-run print ───
            if ($dryRun) {
                $this->warn('[dry-run] — would TRUNCATE supplier_freshness_snapshots + INSERT '
                    .count($rows).' row(s).');
            } else {
                DB::transaction(function () use ($rows): void {
                    DB::table('supplier_freshness_snapshots')->truncate();
                    if ($rows !== []) {
                        // Realistic supplier count is well under 50; chunking is
                        // future-proofing against pathological growth.
                        foreach (array_chunk($rows, 500) as $chunk) {
                            DB::table('supplier_freshness_snapshots')->insert($chunk);
                        }
                    }
                });
            }

            // ─── 5. Output: status table + explicit stale list ───
            $this->info(str_repeat('-', 60));
            $this->info(sprintf(
                'fresh: %d | amber: %d | stale: %d | unknown: %d',
                $counts['fresh'],
                $counts['amber'],
                $counts['stale'],
                $counts['unknown'],
            ));

            if ($staleList !== []) {
                $this->warn('Stale suppliers (downstream stock-decayed):');
                foreach ($staleList as $s) {
                    $this->line(sprintf(
                        '  - supplier_id=%s name=%s days_since=%s threshold=%d',
                        $s['supplier_id'],
                        $s['name'] ?? '(unknown)',
                        $s['days_since'] === null ? 'n/a' : (string) $s['days_since'],
                        $s['threshold_days'],
                    ));
                }
            } else {
                $this->info('No stale suppliers detected.');
            }

            // Surface delta on first-run / discovery growth so operator sees
            // when a new supplier first appears in the feed.
            if (! $dryRun) {
                $delta = $totalSuppliers - $existingSuppliers;
                if ($delta > 0) {
                    $this->info(sprintf('  (%d new supplier(s) added to suppliers table this run.)', $delta));
                }
            }

            return SymfonyCommand::SUCCESS;
        } catch (QueryException $e) {
            Log::error('suppliers_check_stale.db_failure', [
                'error' => $e->getMessage(),
            ]);
            $this->error('DB error: '.$e->getMessage());

            return SymfonyCommand::FAILURE;
        } catch (\Throwable $e) {
            Log::error('suppliers_check_stale.unexpected_failure', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Unexpected error: '.$e->getMessage());

            return SymfonyCommand::FAILURE;
        }
    }
}
