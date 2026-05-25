<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Core-loop step #3 — weekly auto-draft of competitor-only SKUs.
 *
 * Finds SKUs that appear in competitor pricing but are NOT on meetingstore yet,
 * prioritised by how many competitors stock them (more competitors = more
 * validated demand), then runs the review-first draft pipeline on them:
 *
 *   products:generate-drafts  → AI content from supplier_db (creates the local
 *                               draft; SKIPS any SKU our supplier doesn't carry,
 *                               since we can't sell what we can't buy)
 *   products:assign-taxonomy  → brand + multi-category from the live Woo taxonomy
 *   products:source-images    → Icecat/web images, Claude-vision validated
 *
 * The drafts land in the Auto-Create Review inbox for manual review + manual
 * publish. NOTHING posts to Woo. Already-drafted SKUs are naturally excluded
 * next run (once a SKU exists in `products` it's no longer "competitor-only").
 *
 * Scheduled Sunday 14:00 (routes/console.php). --limit bounds the weekly Claude
 * spend; raise it for a manual backfill.
 *
 *   php artisan products:draft-competitor-skus --dry-run   (list candidates only)
 *   php artisan products:draft-competitor-skus             (draft top --limit)
 */
final class DraftCompetitorSkusCommand extends BaseCommand
{
    protected $signature = 'products:draft-competitor-skus
        {--limit=25 : Max competitor-only SKUs to draft this run (cost bound)}
        {--min-competitors=2 : Only SKUs carried by at least this many competitors}
        {--max-age-days=30 : Only consider competitor prices within this window}
        {--candidates=15 : Image candidates to evaluate per product}
        {--skip-images : Create content + taxonomy only; skip image sourcing}
        {--dry-run : List the candidate SKUs only; do NOT create drafts}';

    protected $description = 'Weekly: draft products on competitors but not on meetingstore (supplier-carried only; review-first, no Woo writes)';

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $minCompetitors = max(1, (int) $this->option('min-competitors'));
        $maxAgeDays = max(1, (int) $this->option('max-age-days'));
        $candidates = max(1, (int) $this->option('candidates'));
        $skipImages = (bool) $this->option('skip-images');
        $cutoff = now()->subDays($maxAgeDays);

        $this->info(($dryRun ? 'DRY-RUN — ' : '')."Finding competitor-only SKUs (≥{$minCompetitors} competitors, prices ≤{$maxAgeDays}d, top {$limit}).");

        // Competitor SKUs not present in products, ranked by competitor count.
        // Match key mirrors the ingest orphan detector: LOWER(TRIM(sku)).
        $rows = DB::table('competitor_prices as cp')
            ->select('cp.sku', DB::raw('COUNT(DISTINCT cp.competitor_id) as comp_count'))
            ->where('cp.recorded_at', '>=', $cutoff)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('products as p')
                    ->whereRaw('LOWER(TRIM(p.sku)) = LOWER(TRIM(cp.sku))');
            })
            ->groupBy('cp.sku')
            ->havingRaw('COUNT(DISTINCT cp.competitor_id) >= ?', [$minCompetitors])
            ->orderByDesc('comp_count')
            ->orderBy('cp.sku')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No new competitor-only SKUs meet the threshold — nothing to draft.');

            return SymfonyCommand::SUCCESS;
        }

        $this->newLine();
        foreach ($rows as $row) {
            $this->line(sprintf('  %-20s  %d competitor(s)', $row->sku, (int) $row->comp_count));
        }

        $skus = $rows->pluck('sku')->map(static fn ($s): string => trim((string) $s))->all();
        $skuCsv = implode(',', $skus);

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN — '.count($skus).' candidate SKU(s). Re-run without --dry-run to draft them.');
            $this->line('(generate-drafts will skip any not carried by the supplier — we can only sell what we can buy.)');

            return SymfonyCommand::SUCCESS;
        }

        // ── Run the review-first pipeline (content → taxonomy → images) ──────
        $this->newLine();
        $this->info('1/3 — generating content (supplier-carried SKUs only)…');
        Artisan::call('products:generate-drafts', ['--skus' => $skuCsv], $this->getOutput());

        $this->info('2/3 — assigning brand + categories…');
        Artisan::call('products:assign-taxonomy', ['--skus' => $skuCsv], $this->getOutput());

        if (! $skipImages) {
            $this->info('3/3 — sourcing + validating images…');
            Artisan::call('products:source-images', [
                '--skus' => $skuCsv,
                '--candidates' => $candidates,
            ], $this->getOutput());
        } else {
            $this->line('3/3 — image sourcing skipped (--skip-images).');
        }

        $this->newLine();
        $this->info('Done — review the new drafts at /admin/auto-create-reviews (status: draft / needs brand+category). Nothing posted to Woo.');

        return SymfonyCommand::SUCCESS;
    }
}
