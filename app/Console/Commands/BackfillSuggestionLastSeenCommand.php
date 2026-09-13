<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\DB;

/**
 * Quick task 260913-qoi — suggestions:backfill-last-seen.
 *
 * One-time (re-runnable) backfill of `evidence->last_seen_at` on existing
 * new_product_opportunity suggestions, taken from the newest competitor_prices
 * row for the same SKU.
 *
 * WHY THESE ROWS HAVE NO USABLE DATE
 *
 * OrphanDetector::record() used to `return $existing` untouched whenever the
 * sighting came from a competitor already counted for that SKU (D-09 idempotent
 * no-op). Correct for the counter — but it meant `updated_at` only moved when a
 * NEW competitor appeared. A SKU listed on every scrape since May still carried
 * its May timestamp.
 *
 * Measured 2026-09-13: of 6,965 pending suggestions untouched for 30+ days,
 * **1,631 were in that morning's scrape**. The Suggestions inbox showed live,
 * currently-sourceable products as long dead, and there was no way to tell them
 * apart.
 *
 * The detector now refreshes `last_seen_at` on every sighting, so the list
 * self-heals from the next scrape onward — but only for SKUs that still appear.
 * This command fills in the history for everything else, so a row reads
 * "last seen 3 months ago" rather than blank.
 *
 * Reads competitor_prices, writes only suggestion evidence. Touches no product,
 * price, or Woo record.
 *
 * Operator entry points:
 *   php artisan suggestions:backfill-last-seen              (DRY-RUN, default)
 *   php artisan suggestions:backfill-last-seen --apply
 */
final class BackfillSuggestionLastSeenCommand extends BaseCommand
{
    protected $signature = 'suggestions:backfill-last-seen
        {--apply : Write the dates. WITHOUT THIS NOTHING IS WRITTEN.}';

    protected $description = 'Backfill evidence->last_seen_at on new_product_opportunity suggestions from competitor price history. Dry-run by default.';

    protected function perform(): int
    {
        $apply = (bool) $this->option('apply');

        // Newest sighting per SKU across every competitor, in one pass.
        $newest = DB::table('competitor_prices')
            ->select('sku')
            ->selectRaw('max(recorded_at) as seen')
            ->groupBy('sku')
            ->pluck('seen', 'sku');

        $this->info(sprintf(
            '%s — %d SKU(s) with competitor price history.',
            $apply ? 'APPLY' : 'DRY-RUN',
            $newest->count(),
        ));

        $set = 0;
        $alreadyFresh = 0;
        $noHistory = 0;
        $buckets = ['today' => 0, '7d' => 0, '30d' => 0, 'older' => 0];

        Suggestion::query()
            ->where('kind', 'new_product_opportunity')
            ->chunkById(500, function ($rows) use (&$set, &$alreadyFresh, &$noHistory, &$buckets, $newest, $apply): void {
                foreach ($rows as $suggestion) {
                    $evidence = (array) $suggestion->evidence;
                    $sku = (string) ($evidence['sku'] ?? '');
                    if ($sku === '') {
                        $noHistory++;

                        continue;
                    }

                    $seen = $newest[$sku] ?? null;
                    if ($seen === null) {
                        $noHistory++;

                        continue;
                    }

                    // Never move a date BACKWARDS — the detector may already
                    // have written a fresher one since this run started.
                    $existing = $evidence['last_seen_at'] ?? null;
                    if ($existing !== null && strtotime((string) $existing) >= strtotime((string) $seen)) {
                        $alreadyFresh++;

                        continue;
                    }

                    $ts = strtotime((string) $seen);
                    if ($ts >= strtotime('today')) {
                        $buckets['today']++;
                    } elseif ($ts >= strtotime('-7 days')) {
                        $buckets['7d']++;
                    } elseif ($ts >= strtotime('-30 days')) {
                        $buckets['30d']++;
                    } else {
                        $buckets['older']++;
                    }

                    $set++;

                    if (! $apply) {
                        continue;
                    }

                    $evidence['last_seen_at'] = (string) $seen;
                    $suggestion->evidence = $evidence;
                    // saveQuietly: this is a data repair, not an operator
                    // decision — it must not fire suggestion observers or
                    // re-notify anyone about a months-old opportunity.
                    $suggestion->saveQuietly();
                }
            });

        $this->line('');
        $this->info(sprintf(
            '%d %s, %d already fresh, %d have no price history.',
            $set,
            $apply ? 'written' : 'would gain a date',
            $alreadyFresh,
            $noHistory,
        ));
        $this->line('  of those dated — last seen:');
        foreach ($buckets as $label => $n) {
            $this->line(sprintf('    %-7s %6d', $label, $n));
        }

        if (! $apply && $set > 0) {
            $this->comment('Nothing was written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
