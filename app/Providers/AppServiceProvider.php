<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Policies\AlertRecipientPolicy;
use App\Domain\Suggestions\Appliers\StubApplier;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Policies\SuggestionPolicy;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Facades\LogBatch;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Plan 04: SuggestionApplierResolver must be a singleton so every producer,
        // job and admin action resolves the SAME registry instance (not a fresh, empty copy).
        $this->app->singleton(SuggestionApplierResolver::class);
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

        // ── Plan 04: Suggestions seam ────────────────────────────────────
        // Register the stub applier for kind='test' (Phase 1 acceptance fixture).
        // Phase 5+ producers extend this line:
        //   $resolver->register('margin_change', MarginChangeApplier::class);
        $this->app->afterResolving(
            SuggestionApplierResolver::class,
            function (SuggestionApplierResolver $resolver): void {
                $resolver->register('test', StubApplier::class);
            }
        );

        // Admin-only gate on Suggestion model — defence-in-depth on top of Shield
        // permission assignment (Pitfall K).
        Gate::policy(Suggestion::class, SuggestionPolicy::class);

        // Plan 05: admin-only gate on AlertRecipient (T-05-07; Pitfall K).
        // Leaking ops email addresses would expose staff to targeted phishing.
        Gate::policy(AlertRecipient::class, AlertRecipientPolicy::class);
    }
}
