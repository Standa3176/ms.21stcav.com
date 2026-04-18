<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Facades\LogBatch;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // FOUND-03 + Pitfall J: re-open spatie LogBatch inside queued jobs so
        // audit rows written by the job share the originating request's correlation_id.
        // Laravel 12's Context::hydrated fires AFTER a queued job rehydrates its payload —
        // by that point the correlation_id is already in Context, we just need LogBatch to pick it up.
        Context::hydrated(function (Repository $context): void {
            if ($cid = $context->get('correlation_id')) {
                LogBatch::startBatch();
                LogBatch::setBatch($cid);
            }
        });

        // Hook point for future defensive logging (no-op in Phase 1).
        Context::dehydrating(function (Repository $context): void {
            // e.g. Log::debug('Context dehydrating', ['correlation_id' => $context->get('correlation_id')]);
        });
    }
}
