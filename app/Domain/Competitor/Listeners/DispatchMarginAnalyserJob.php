<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Listeners;

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Jobs\ComputeMarginSuggestionJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 5 Plan 03 Task 2 — debounced listener on CompetitorPriceRecorded.
 *
 * Prevents N-per-CSV margin analysis on repeated scrapes of the same
 * (competitor, sku) on the same day. Cache::add is atomic — if the key
 * already exists returns false, and we exit silently.
 *
 * Key format (FROZEN for Plan 05-04 Filament debug tooling):
 *   competitor.analyser.debounce.{competitor_id}.{sku}.{YYYY-MM-DD}
 *
 * TTL: 24 hours (auto-expires so next day's first CSV kicks off analysis).
 *
 * Queue: default — listener work is cheap (Cache::add + Queue::push); the
 * actual threshold-checking + Suggestion creation happens in the dispatched
 * ComputeMarginSuggestionJob on the same queue.
 */
class DispatchMarginAnalyserJob implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(CompetitorPriceRecorded $event): void
    {
        $today = now()->format('Y-m-d');
        $key = sprintf(
            'competitor.analyser.debounce.%d.%s.%s',
            $event->competitorId,
            $event->sku,
            $today,
        );

        if (! Cache::add($key, true, now()->addHours(24))) {
            return; // already dispatched for this (competitor, sku, day) — silent no-op
        }

        ComputeMarginSuggestionJob::dispatch($event->competitorId, $event->sku)
            ->onQueue('default');
    }
}
