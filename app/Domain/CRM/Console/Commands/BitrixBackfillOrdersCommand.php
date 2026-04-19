<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Jobs\BackfillOrdersChunkJob;
use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Phase 4 Plan 05 Task 1 — `bitrix:backfill-orders` (CRM-10).
 *
 * Three modes (mutually exclusive via flag priority):
 *   - default dry-run    : chunks dispatch PushOrderToBitrixJob with
 *                          CRM_WRITE_ENABLED forced false — writes land in
 *                          sync_diffs.provider='bitrix', no real SDK calls.
 *   - --live             : opt-in to real Bitrix writes. BitrixEntityMap's
 *                          UNIQUE(entity_type, woo_id) makes re-runs safe.
 *   - --adopt-legacy-deal-ids : Pitfall 5 pre-cutover pass. Reads
 *                               _wc_bitrix24_deal_id Woo post_meta, writes
 *                               UF_CRM_WOO_ORDER_ID onto the legacy Deal,
 *                               records a BitrixEntityMap row with
 *                               created_via='adopted_legacy'.
 *
 * --since is REQUIRED (no default) — prevents backfilling to 2019 accidentally.
 *
 * 50 orders per chunk (--chunk=50), 600ms inter-page sleep (--sleep-ms=600)
 * to respect Bitrix's ~2 req/sec cloud ceiling.
 *
 * --only-order-id=42 (repeatable or CSV) skips date iteration and processes
 * exactly those IDs — surgical retry after a partial failure.
 *
 * Progress persists to bitrix_backfill_runs after each chunk (processed /
 * skipped / failed / adopted_legacy_count / last_cursor). finished_at set on
 * command completion. Auditor::record('bitrix.backfill.completed', …) at exit.
 */
final class BitrixBackfillOrdersCommand extends BaseCommand
{
    protected $signature = 'bitrix:backfill-orders
        {--since= : Required ISO date (YYYY-MM-DD) — oldest order date to process}
        {--live : Opt-in to actually push to Bitrix (default is dry-run)}
        {--adopt-legacy-deal-ids : Map legacy _wc_bitrix24_deal_id post_meta into BitrixEntityMap}
        {--chunk=50 : Orders per chunk}
        {--sleep-ms=600 : Milliseconds between page fetches (Bitrix 2 req/sec ceiling)}
        {--only-order-id=* : Limit to specific Woo order IDs (comma-separated or repeated flag)}
        {--dry-run : Explicit dry-run (== default; error if combined with --live)}';

    protected $description = 'Replay historical Woo orders into Bitrix (dry-run by default; --live opts in; --adopt-legacy-deal-ids for pre-cutover Pitfall 5)';

    protected function perform(): int
    {
        // D-04/D-12 parity with Phase 2/3: --live and --dry-run are mutually exclusive.
        if ($this->option('live') && $this->option('dry-run')) {
            $this->error('Error: --live and --dry-run are mutually exclusive.');

            return SymfonyCommand::INVALID;
        }

        $sinceRaw = $this->option('since');
        $onlyIds = $this->normaliseOnlyIds((array) $this->option('only-order-id'));

        // --since is REQUIRED unless --only-order-id fully specifies the scope
        // (operator surgical retry path). Everywhere else, missing --since exits 1.
        if (($sinceRaw === null || $sinceRaw === '') && $onlyIds === []) {
            $this->error('Error: --since is required (YYYY-MM-DD) — no default to prevent "backfill everything since 2019" accidents.');

            return SymfonyCommand::FAILURE;
        }

        $since = null;
        if ($sinceRaw !== null && $sinceRaw !== '') {
            try {
                $since = CarbonImmutable::parse((string) $sinceRaw)->startOfDay();
            } catch (Throwable $e) {
                $this->error('Error: --since must be a valid ISO date (YYYY-MM-DD). Got: '.$sinceRaw);

                return SymfonyCommand::FAILURE;
            }
        }

        // Derive mode — adopt-legacy > live > dry-run (highest-priority flag wins).
        $mode = BitrixBackfillRun::MODE_DRY_RUN;
        if ((bool) $this->option('adopt-legacy-deal-ids')) {
            $mode = BitrixBackfillRun::MODE_ADOPT_LEGACY;
        } elseif ((bool) $this->option('live')) {
            $mode = BitrixBackfillRun::MODE_LIVE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $correlationId = (string) (Context::get('correlation_id') ?? Str::uuid());

        // T-04-05-02 — concurrent-run guard. An in-progress run of the same
        // mode within the last hour blocks a fresh command from starting.
        $inFlight = BitrixBackfillRun::query()
            ->where('mode', $mode)
            ->whereNull('finished_at')
            ->where('started_at', '>=', now()->subHour())
            ->latest('id')
            ->first();
        if ($inFlight !== null) {
            $this->error(sprintf(
                'Error: a %s backfill run (id=%d) is already in progress (started_at=%s). Wait for it to finish or clear it manually.',
                $mode,
                $inFlight->id,
                $inFlight->started_at?->toIso8601String() ?? 'unknown',
            ));

            return SymfonyCommand::FAILURE;
        }

        $run = BitrixBackfillRun::create([
            'since_date' => $since?->toDateString() ?? now()->toDateString(),
            'mode' => $mode,
            'started_at' => now(),
            'correlation_id' => $correlationId,
        ]);

        $this->renderBanner($mode, $since, $chunk, $sleepMs, $onlyIds, $run->id);

        try {
            if ($onlyIds !== []) {
                $this->dispatchOnlyIds($onlyIds, $run->id, $mode, $correlationId, $chunk);
            } else {
                /** @var WooClient $woo */
                $woo = app(WooClient::class);
                $this->iteratePages($woo, $since, $chunk, $sleepMs, $run->id, $mode, $correlationId);
            }
        } catch (Throwable $e) {
            $this->error('Backfill aborted: '.$e->getMessage());
            $run->update(['finished_at' => now(), 'notes' => 'aborted: '.$e->getMessage()]);

            return SymfonyCommand::FAILURE;
        }

        $run->refresh();
        $run->update(['finished_at' => now()]);
        $run->refresh();

        $this->renderSummary($run);

        app(Auditor::class)->record('bitrix.backfill.completed', [
            'run_id' => $run->id,
            'mode' => $run->mode,
            'processed' => $run->processed_orders,
            'skipped' => $run->skipped_orders,
            'failed' => $run->failed_orders,
            'adopted_legacy' => $run->adopted_legacy_count,
            'total' => $run->total_orders,
        ]);

        // >10% failure rate ⇒ exit 1 so CI/cron operators see it.
        if ($run->total_orders > 0 && $run->failed_orders / $run->total_orders > 0.1) {
            $this->warn('Backfill completed with >10% failure rate — exit code 1.');

            return SymfonyCommand::FAILURE;
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array<int, string>  $raw
     * @return array<int, int>
     */
    private function normaliseOnlyIds(array $raw): array
    {
        $flat = [];
        foreach ($raw as $entry) {
            foreach (explode(',', (string) $entry) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                $flat[] = (int) $piece;
            }
        }

        return array_values(array_unique(array_filter($flat, static fn (int $n) => $n > 0)));
    }

    /**
     * @param  array<int, int>  $onlyIds
     */
    private function dispatchOnlyIds(array $onlyIds, int $runId, string $mode, string $correlationId, int $chunk): void
    {
        // Bump total_orders counter up front so the tracker's processed/skipped
        // arithmetic makes sense at the summary step.
        BitrixBackfillRun::where('id', $runId)->increment('total_orders', count($onlyIds));

        foreach (array_chunk($onlyIds, $chunk) as $slice) {
            BackfillOrdersChunkJob::dispatch(
                orderIds: $slice,
                backfillRunId: $runId,
                mode: $mode,
                correlationId: $correlationId,
            );
            $this->info('  Dispatched surgical chunk: '.count($slice).' order(s).');
        }
    }

    private function iteratePages(
        WooClient $woo,
        CarbonImmutable $since,
        int $chunk,
        int $sleepMs,
        int $runId,
        string $mode,
        string $correlationId,
    ): void {
        $page = 1;

        while (true) {
            $response = $woo->get('orders', [
                'after' => $since->toIso8601String(),
                'per_page' => $chunk,
                'page' => $page,
                'orderby' => 'id',
                'order' => 'asc',
                'status' => 'any',
            ]);

            if (! is_array($response) || $response === []) {
                break;
            }

            $ids = [];
            foreach ($response as $order) {
                if (isset($order['id'])) {
                    $ids[] = (int) $order['id'];
                }
            }

            if ($ids === []) {
                break;
            }

            BitrixBackfillRun::where('id', $runId)->increment('total_orders', count($ids));

            BackfillOrdersChunkJob::dispatch(
                orderIds: $ids,
                backfillRunId: $runId,
                mode: $mode,
                correlationId: $correlationId,
            );
            $this->info(sprintf('  Page %d: %d order(s) dispatched.', $page, count($ids)));

            // Short page ⇒ we've walked off the end of the Woo result set.
            if (count($ids) < $chunk) {
                break;
            }

            // Respect Bitrix's ~2 req/sec ceiling by pacing the Woo-side
            // iteration. The chunk workers themselves pace per-call via the
            // BitrixClient's in-wrapper throttle (Plan 04-02).
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $page++;
        }
    }

    /**
     * @param  array<int, int>  $onlyIds
     */
    private function renderBanner(
        string $mode,
        ?CarbonImmutable $since,
        int $chunk,
        int $sleepMs,
        array $onlyIds,
        int $runId,
    ): void {
        $this->info("Bitrix backfill — run id={$runId}, mode={$mode}");
        $this->line('  since     : '.($since?->toDateString() ?? '(not used — --only-order-id supplied)'));
        $this->line('  chunk     : '.$chunk);
        $this->line('  sleep-ms  : '.$sleepMs);
        if ($onlyIds !== []) {
            $this->line('  only-ids  : '.implode(', ', $onlyIds));
        }

        if ($mode === BitrixBackfillRun::MODE_DRY_RUN) {
            $this->line('DRY-RUN: no real Bitrix writes. Shadow rows will land in sync_diffs.provider=bitrix.');
        } elseif ($mode === BitrixBackfillRun::MODE_LIVE) {
            $this->warn('LIVE mode: real Bitrix pushes. Re-runs are idempotent via BitrixEntityMap.');
        } else {
            $this->warn('ADOPT-LEGACY mode: reads _wc_bitrix24_deal_id post_meta, sets UF_CRM_WOO_ORDER_ID on legacy Deals, writes BitrixEntityMap rows.');
        }
    }

    private function renderSummary(BitrixBackfillRun $run): void
    {
        $this->info('');
        $this->info(sprintf(
            'Run %d completed in mode=%s — processed=%d, skipped=%d, failed=%d, adopted_legacy=%d, total=%d',
            $run->id,
            $run->mode,
            $run->processed_orders,
            $run->skipped_orders,
            $run->failed_orders,
            $run->adopted_legacy_count,
            $run->total_orders,
        ));
        $this->line('  correlation_id: '.(string) $run->correlation_id);
        $this->line('  last_cursor   : '.(string) ($run->last_cursor ?? '(none)'));
    }
}
