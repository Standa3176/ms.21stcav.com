<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Policies\AlertRecipientPolicy;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Products\Policies\ProductPolicy;
use App\Domain\Products\Policies\ProductVariantPolicy;
use App\Domain\Suggestions\Appliers\StubApplier;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Policies\SuggestionPolicy;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Policies\ImportIssuePolicy;
use App\Domain\Sync\Policies\SyncRunPolicy;
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

        // ── Phase 2 Plan 02: Woo REST + Supplier API clients ─────────────
        // Automattic's WooCommerce SDK binding — single shared instance per request
        // (cURL handle + consumer key/secret are stable across calls).
        $this->app->singleton(\Automattic\WooCommerce\Client::class, function ($app) {
            return new \Automattic\WooCommerce\Client(
                (string) config('services.woo.url', 'https://meetingstore.co.uk'),
                (string) config('services.woo.consumer_key', ''),
                (string) config('services.woo.consumer_secret', ''),
                [
                    'version' => 'wc/v3',
                    'timeout' => 30,          // default 10s is too short for bulk writes
                    'verify_ssl' => $app->isProduction(),  // local dev may use self-signed
                ]
            );
        });

        // WooClient wraps the Automattic client with shadow-mode gate + 429 backoff
        // + IntegrationLogger threading. Singleton so per-request correlation_id flows
        // naturally through the same logger instance.
        $this->app->singleton(\App\Domain\Sync\Services\WooClient::class, function ($app) {
            return new \App\Domain\Sync\Services\WooClient(
                $app->make(\App\Foundation\Integration\Services\IntegrationLogger::class),
                $app->make(\Automattic\WooCommerce\Client::class),
            );
        });

        // SupplierClient — non-singleton is fine: JWT state lives in Cache, not on
        // the instance, so re-instantiation is cheap and tests benefit from fresh
        // instances per resolve.
        $this->app->bind(\App\Domain\Sync\Services\SupplierClient::class, function ($app) {
            return new \App\Domain\Sync\Services\SupplierClient(
                $app->make(\App\Foundation\Integration\Services\IntegrationLogger::class),
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });
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

        // ── Phase 2 Plan 01: Products + Sync domain policies ─────────────
        // Per Phase 1 D-02 role split (admin + pricing_manager edit; sales +
        // read_only view-only). Policies hardcode hasRole to survive drift
        // in RolePermissionSeeder LIKE-pattern queries (Pitfall K + P2-H).
        // DO NOT regenerate via shield:generate — see per-policy docblocks.
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductVariant::class, ProductVariantPolicy::class);
        Gate::policy(SyncRun::class, SyncRunPolicy::class);
        Gate::policy(ImportIssue::class, ImportIssuePolicy::class);
    }
}
