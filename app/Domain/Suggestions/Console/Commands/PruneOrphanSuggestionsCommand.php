<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260606-gnu — auto-reject stale competitor-only orphan
 * new_product_opportunity Suggestions.
 *
 * 62% of pending /admin/suggestions rows are off-supplier-DB orphan SKUs
 * (competitor lists it, no supplier carries it) with only 1 competitor
 * tracking them. They are not actionable — at best they sit in the inbox
 * forever and at worst they bury the genuinely-actionable high-confidence
 * sourceable rows under noise.
 *
 * This command flips them to status='rejected' in conservative batches.
 * The 3-way conjunction gate is the "least valuable signal × longest wait"
 * intersection: a row must be ALL of
 *   - kind = 'new_product_opportunity'
 *   - status = 'pending'
 *   - older than --days (default 14 — empirical: 2026-06-06 prod run found 0 candidates at >=30d because the entire orphan set was <30d old; 14d catches 97% of the orphan tail while still preserving the next supplier-sync window)
 *   - evidence.supporting_competitors < 2
 *   - sku NOT in supplier_sku_cache (off-supplier-DB)
 *
 * Worst-case misclassification preserves the row for one extra week
 * (next Mon 06:00 cron fire) — schedule sits BEFORE supplier:db-sync at
 * 07:00 so a row whose sourceability status is about to flip stays safe.
 *
 * Suggestion does NOT use spatie/laravel-activitylog LogsActivity (verified
 * via grep). Mass ->update() bypasses model events anyway. Audit trail is
 * captured via the STDOUT counter summary + BaseCommand's correlation_id
 * LogBatch wrapper (started in BaseCommand::handle).
 */
final class PruneOrphanSuggestionsCommand extends BaseCommand
{
    protected $signature = 'suggestions:prune-orphans
        {--days=14 : Min age in days; rows newer than this stay pending}
        {--dry-run : Print count + sample 20 SKUs, do not write}';

    protected $description = 'Auto-reject stale competitor-only orphan new_product_opportunity suggestions (off-supplier-DB + <2 competitors + older than N days).';

    protected function perform(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'suggestions:prune-orphans — %s (gate: off-supplier-DB + <2 competitors + >=%d days old)',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $days,
        ));

        $candidates = $this->candidateQuery($days);
        $count = (clone $candidates)->count();

        if ($dryRun) {
            $sample = (clone $candidates)
                ->limit(20)
                ->get(['id', 'evidence', 'proposed_at']);

            $rows = $sample->map(function (Suggestion $row): array {
                return [
                    (string) data_get($row->evidence, 'sku', '—'),
                    (string) (int) data_get($row->evidence, 'supporting_competitors', 0),
                    (string) (int) $row->proposed_at->diffInDays(now()),
                ];
            })->all();

            $this->table(['SKU', 'Competitors', 'Age (days)'], $rows);
            $this->info(sprintf('Found %d candidate(s) — dry-run, no writes.', $count));

            return SymfonyCommand::SUCCESS;
        }

        $rejectionReason = 'auto-rejected: stale competitor-only orphan (no supplier carries + <2 competitors)';
        $now = now();
        $total = 0;

        // chunkById paginates by primary key, which is stable across the
        // pending → rejected status flip we're applying inside the loop.
        // Plain chunk() would skip rows because the WHERE on status changes.
        (clone $candidates)
            ->select(['id'])
            ->chunkById(500, function ($chunk) use ($rejectionReason, $now, &$total): void {
                $ids = $chunk->pluck('id')->all();
                if ($ids === []) {
                    return;
                }
                Suggestion::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'status' => Suggestion::STATUS_REJECTED,
                        'rejection_reason' => $rejectionReason,
                        'resolved_at' => $now,
                    ]);
                $total += count($ids);
            });

        $this->info(sprintf('Rejected %d orphan suggestion(s).', $total));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Build the candidate Eloquent query. Driver-aware JSON expression so
     * the command works on both MySQL (production) and SQLite (test DB).
     *
     * MySQL needs JSON_UNQUOTE() to strip the quotes JSON_EXTRACT adds for
     * string values; SQLite's json_extract() returns the unquoted value
     * directly. CAST(... AS UNSIGNED) is MySQL syntax; SQLite uses INTEGER.
     */
    private function candidateQuery(int $days): Builder
    {
        $skuExpr = $this->jsonSkuExpression('suggestions.evidence');
        $competitorsExpr = $this->jsonIntCastExpression('evidence', 'supporting_competitors');

        return Suggestion::query()
            ->where('kind', 'new_product_opportunity')
            ->where('status', Suggestion::STATUS_PENDING)
            ->where('proposed_at', '<', now()->subDays($days))
            ->whereRaw("{$competitorsExpr} < 2")
            ->whereRaw("NOT EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM({$skuExpr})))");
    }

    private function jsonSkuExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract({$column}, '$.sku')"
            : "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.sku'))";
    }

    private function jsonIntCastExpression(string $column, string $path): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(json_extract({$column}, '$.{$path}') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$path}')) AS UNSIGNED)";
    }
}
