<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Policies\AlertRecipientPolicy;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Policies\PricingRulePolicy;
use App\Domain\Pricing\Policies\ProductOverridePolicy;
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

        // Phase 3 Plan 04 Task 1 — PriceRecomputer is the shared "recompute a
        // SKU's price" core used by BOTH the event-driven RecomputePriceListener
        // AND the bulk RecomputePriceJob. Stateless but singleton-bound to avoid
        // repeat DI resolution cost during a 15k-SKU bulk batch.
        $this->app->singleton(\App\Domain\Pricing\Services\PriceRecomputer::class);

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

        // ── Phase 4 Plan 02: Bitrix CRM client + schema cache ─────────────
        // BitrixClient wraps the official b24phpsdk with shadow-mode gate,
        // 2 req/sec throttle, and D-11 exception classification. Singleton so
        // per-request correlation_id flows naturally through one logger instance
        // and the per-instance throttle timestamp enforces the rate limit.
        $this->app->singleton(\App\Domain\CRM\Services\BitrixClient::class, function ($app) {
            return new \App\Domain\CRM\Services\BitrixClient(
                $app->make(\App\Foundation\Integration\Services\IntegrationLogger::class),
            );
        });

        // BitrixSchemaCache — singleton so the Laravel cache is consulted through
        // one resolver per request and warm-up results short-circuit after the
        // first fieldsFor() call in a chain.
        $this->app->singleton(\App\Domain\CRM\Services\BitrixSchemaCache::class);

        // ── Phase 6 Plan 02: Intervention ImageManager DI binding ────────
        // intervention/image-laravel's ServiceProvider binds the manager to the
        // string key 'image' (Facades\Image::BINDING) — NOT to the class name.
        // Our ProductImageProcessor takes ImageManager via constructor typehint,
        // so the container can't auto-resolve the required $driver primitive.
        // Alias ImageManager::class to the pre-built facade binding so DI works.
        $this->app->bind(\Intervention\Image\ImageManager::class, fn ($app) => $app->make('image'));
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
        //
        // Phase 4 Plan 03 D-12: CrmPushRetryApplier is the FIRST real producer
        // on this seam — registers against kind='crm_push_failed' so
        // ApplySuggestionJob can re-dispatch PushOrderToBitrixJob /
        // PushCustomerToBitrixJob when an admin clicks Replay on a failed
        // suggestion in the Filament inbox.
        $this->app->afterResolving(
            SuggestionApplierResolver::class,
            function (SuggestionApplierResolver $resolver): void {
                $resolver->register('test', StubApplier::class);
                $resolver->register('crm_push_failed', \App\Domain\CRM\Appliers\CrmPushRetryApplier::class);
                // Phase 6 Plan 03 — REAL applier (RESEARCH Q4 resolution): file
                // moved from app/Domain/Competitor/Appliers/ into
                // app/Domain/ProductAutoCreate/Appliers/. Body replaced with
                // CreateWooProductJob::dispatch(). Old FQCN deleted.
                $resolver->register(
                    'new_product_opportunity',
                    \App\Domain\ProductAutoCreate\Appliers\NewProductOpportunityApplier::class,
                );
                // Phase 6 Plan 03 — DLQ replay applier for kind='auto_create_failed'.
                // CreateWooProductJob::failed() writes the Suggestion row; the
                // Plan 04 Filament Replay action dispatches ApplySuggestionJob →
                // this applier → fresh CreateWooProductJob (mirrors Phase 4
                // CrmPushRetryApplier precedent).
                $resolver->register(
                    'auto_create_failed',
                    \App\Domain\ProductAutoCreate\Appliers\AutoCreateRetryApplier::class,
                );
                // Phase 5 Plan 03 Task 3 — THIRD real producer (and the first
                // PRODUCTIVE one beyond the CRM retry seam). Approving a
                // margin_change Suggestion updates PricingRule via Eloquent →
                // PricingRuleObserver fires PricingRuleChanged → Phase 3's
                // recompute chain picks up the new margin.
                $resolver->register('margin_change', \App\Domain\Competitor\Appliers\MarginChangeApplier::class);
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

        // ── Phase 3 Plan 01: Pricing domain policies ─────────────────────
        // Gates PricingRule + ProductOverride writes to admin + pricing_manager.
        // Hand-written hasRole() checks per Pitfall K + P2-H; DO NOT regenerate
        // via shield:generate (Plan 02-05 PolicyTemplateIntegrityTest catches).
        Gate::policy(PricingRule::class, PricingRulePolicy::class);
        Gate::policy(ProductOverride::class, ProductOverridePolicy::class);

        // ── Phase 4 Plan 01: CRM domain policies ─────────────────────────
        // Admin-only gates on all 5 CRM models. Hardcoded hasRole('admin')
        // per Pitfall K + P2-H — do NOT regenerate via shield:generate.
        // PolicyTemplateIntegrityTest (tests/Architecture) catches Shield
        // {{ Placeholder }} leaks on every CI run.
        Gate::policy(\App\Domain\CRM\Models\BitrixEntityMap::class,    \App\Domain\CRM\Policies\BitrixEntityMapPolicy::class);
        Gate::policy(\App\Domain\CRM\Models\CrmFieldMapping::class,    \App\Domain\CRM\Policies\CrmFieldMappingPolicy::class);
        Gate::policy(\App\Domain\CRM\Models\CrmStatusMapping::class,   \App\Domain\CRM\Policies\CrmStatusMappingPolicy::class);
        Gate::policy(\App\Domain\CRM\Models\CrmPipelineSetting::class, \App\Domain\CRM\Policies\CrmPipelineSettingPolicy::class);
        Gate::policy(\App\Domain\CRM\Models\BitrixBackfillRun::class,  \App\Domain\CRM\Policies\BitrixBackfillRunPolicy::class);

        // Phase 4 Plan 05 — GDPR erasure audit (CRM-13). Admin read-only;
        // create/update/delete denied (append-only from GdprEraser service).
        Gate::policy(\App\Domain\CRM\Models\GdprErasureLogEntry::class, \App\Domain\CRM\Policies\GdprErasureLogEntryPolicy::class);

        // ── Phase 5 Plan 01: Competitor domain policies ─────────────────
        // D-02 + D-04 role split: admin has full CRUD on competitors;
        // pricing_manager can resolve quarantined CSV mappings + parse errors;
        // sales can view competitor prices + ingest runs for quote context.
        // Hand-written hasRole() checks per Pitfall K + P2-H + P5-F — do NOT
        // regenerate via shield:generate. PolicyTemplateIntegrityTest
        // (tests/Architecture) catches any Shield `{{ Placeholder }}` leaks.
        Gate::policy(\App\Domain\Competitor\Models\Competitor::class,           \App\Domain\Competitor\Policies\CompetitorPolicy::class);
        Gate::policy(\App\Domain\Competitor\Models\CompetitorPrice::class,      \App\Domain\Competitor\Policies\CompetitorPricePolicy::class);
        Gate::policy(\App\Domain\Competitor\Models\CompetitorCsvMapping::class, \App\Domain\Competitor\Policies\CompetitorCsvMappingPolicy::class);
        Gate::policy(\App\Domain\Competitor\Models\CompetitorIngestRun::class,  \App\Domain\Competitor\Policies\CompetitorIngestRunPolicy::class);
        Gate::policy(\App\Domain\Competitor\Models\CsvParseError::class,        \App\Domain\Competitor\Policies\CsvParseErrorPolicy::class);

        // ── Phase 6 Plan 01: ProductAutoCreate domain policies ──────────
        // D-04 + T-06-01-04 role split: admin governs skip-rule CRUD (cost +
        // brand-reputation impact). pricing_manager has view-only on rules +
        // create/view on rejections (review-inbox triage). sales + read_only
        // denied entirely.
        // Pitfall P5-F — hand-written hasRole checks; do NOT shield:generate.
        Gate::policy(\App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule::class,  \App\Domain\ProductAutoCreate\Policies\AutoCreateSkipRulePolicy::class);
        Gate::policy(\App\Domain\ProductAutoCreate\Models\AutoCreateRejection::class, \App\Domain\ProductAutoCreate\Policies\AutoCreateRejectionPolicy::class);

        // ── Phase 4 Plan 04: CRM Push Log (read-only view over integration_events) ──
        // CrmPushLogResource binds to IntegrationEvent but scopes the query to
        // channel='bitrix'. Policy grants viewAny/view to admin + sales (D-02);
        // all mutations denied. This registration only affects CRM — the Resource
        // is the only Filament surface that renders IntegrationEvent rows.
        Gate::policy(\App\Foundation\Integration\Models\IntegrationEvent::class, \App\Domain\CRM\Policies\CrmPushLogPolicy::class);

        // ── Phase 2 Plan 03: register SyncSupplierCommand ────────────────
        // Laravel 12 auto-discovers artisan commands from app/Console/Commands/.
        // Our command lives under app/Domain/Sync/Commands/, so we register it
        // explicitly via ServiceProvider::commands(). Keeping this in
        // AppServiceProvider::boot avoids touching bootstrap/app.php (Warning 2 —
        // iter-1 fix) and only runs the registration when artisan is bootstrapping
        // (runningInConsole guard).
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Domain\Sync\Commands\SyncSupplierCommand::class,
                // Phase 3 Plan 04 Task 2 — operator CLI for catalogue-wide
                // recompute. Default dry-run, --live opt-in (D-12). Lives
                // under app/Domain/Pricing/Console/Commands/ so explicit
                // registration is required (same pattern as Phase 2).
                \App\Domain\Pricing\Console\Commands\PricingRecomputeCommand::class,
                // Phase 4 Plan 01 — Bitrix CRM Sync commands.
                // bitrix:bootstrap creates UF_CRM_WOO_ORDER_ID + 13 UTM/customer
                // custom fields in Bitrix (idempotent, safe on every deploy).
                // bitrix:smoke-test probes the SDK's API surface before Plan 04-02
                // locks the BitrixClient wrapper interface (two-layer gate —
                // BITRIX_SMOKE_TEST_ALLOWED + BITRIX_WEBHOOK_URL).
                \App\Domain\CRM\Console\Commands\BitrixBootstrapCommand::class,
                \App\Domain\CRM\Console\Commands\BitrixSmokeTestCommand::class,
                // Phase 4 Plan 02 — CRM-02 field-schema cache refresh.
                // Invalidates the 24h cache + refetches deal/contact/company
                // schemas. Admin-triggered after Bitrix UF_CRM_* edits.
                \App\Domain\CRM\Console\Commands\BitrixSchemaRefreshCommand::class,
                // Phase 4 Plan 05 — CRM-10 backfill + CRM-13 GDPR erasure.
                // Backfill has 3 modes (dry-run / live / adopt-legacy-deal-ids)
                // and --since is REQUIRED (no default). GDPR erasure requires
                // typed ERASE confirmation + dispatches EraseBitrixContactJob.
                \App\Domain\CRM\Console\Commands\BitrixBackfillOrdersCommand::class,
                \App\Domain\CRM\Console\Commands\GdprEraseBitrixCustomerCommand::class,
                // Phase 5 Plan 02 Task 2 — scheduled 5-minute CSV watcher (COMP-01+04).
                \App\Domain\Competitor\Console\Commands\CompetitorWatchCommand::class,
                // Phase 5 Plan 03 Task 3 — nightly 02:00 sales-counter recache.
                // A3 fallback: dispatched job is currently a stub (WooClient lacks
                // /orders); command + schedule ship so future WooClient extension
                // activates real recache with zero plumbing changes.
                \App\Domain\Competitor\Console\Commands\CompetitorSalesRecacheCommand::class,
                // Phase 5 Plan 04b Task 2 — hourly stale-feed detector (COMP-11).
                // 48h threshold + 24h per-competitor dedup; routes via
                // AlertRecipient.receives_competitor_alerts (Plan 05-01/05-04a).
                \App\Domain\Competitor\Console\Commands\CompetitorCheckStaleCommand::class,
                // Phase 5 Plan 05 Task 1 — daily 03:40 CSV archive prune (COMP-12).
                // --days=0 is a no-op safety guard (explicit 0); otherwise falls back
                // to config('competitor.csv_retention_days', 90). NEVER touches
                // competitor_prices rows (COMP-07 mandate, permanent regression test).
                \App\Domain\Competitor\Console\Commands\CompetitorCsvPruneCommand::class,
                // Phase 6 Plan 01 Task 1 — Q1 supplier-API probe (RESEARCH.md Open Question Q1).
                // Dumps full supplier row for a single SKU to storage/app/research/supplier-probe.json
                // so Plan 06-02 ProductImageFetcher / ProductContentBuilder can see the real
                // image_url / brand / category / description field shape. Manual-run only.
                \App\Console\Commands\SupplierProbeSingleSkuCommand::class,
            ]);
        }
    }
}
