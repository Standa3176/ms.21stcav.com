<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\BackfillMerchantFeedCommand;
use App\Console\Commands\Cutover\CutoverChecklistCommand;
use App\Console\Commands\Cutover\DisableLegacyPluginsCommand;
use App\Console\Commands\Cutover\DivergenceScanCommand;
use App\Console\Commands\Cutover\DrillRollbackCommand;
use App\Console\Commands\Cutover\PopulateOverridesCommand;
use App\Console\Commands\Cutover\PushProductStatusToWooCommand;
use App\Console\Commands\Cutover\SnapshotWooDbCommand;
use App\Console\Commands\Dashboard\DashboardRefreshCommand;
use App\Console\Commands\Dashboard\PruneDashboardSnapshotsCommand;
use App\Console\Commands\Reports\SupplierSyncDigestCommand;
use App\Console\Commands\Reports\WeeklyDigestCommand;
use App\Console\Commands\SupplierProbeSingleSkuCommand;
use App\Domain\Agents\Agents\PricingAgent;
use App\Domain\Agents\Agents\SeoAgent;
use App\Domain\Agents\Appliers\SeoContentPatchApplier;
use App\Domain\Agents\Console\Commands\AgentRunCommand;
use App\Domain\Agents\Console\Commands\AgentsGdprPurgeLangfuseCommand;
use App\Domain\Agents\Console\Commands\AgentsPruneArchiveCommand;
use App\Domain\Agents\Console\Commands\RunSeoAgentBatchCommand;
use App\Domain\Agents\Console\Commands\ShieldSafeRegenerateCommand;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Policies\AgentRunPolicy;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Services\BudgetGuard;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Policies\AlertRecipientPolicy;
use App\Domain\Competitor\Appliers\MarginChangeApplier;
use App\Domain\Competitor\Console\Commands\CompetitorCheckStaleCommand;
use App\Domain\Competitor\Console\Commands\CompetitorCsvPruneCommand;
use App\Domain\Competitor\Console\Commands\CompetitorRetryQuarantineCommand;
use App\Domain\Competitor\Console\Commands\CompetitorSalesRecacheCommand;
use App\Domain\Competitor\Console\Commands\CompetitorWatchCommand;
use App\Domain\Competitor\Ftp\Console\Commands\CompetitorFtpPullCommand;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Competitor\Policies\CompetitorCsvMappingPolicy;
use App\Domain\Competitor\Policies\CompetitorFtpCredentialPolicy;
use App\Domain\Competitor\Policies\CompetitorFtpFeedPolicy;
use App\Domain\Competitor\Policies\CompetitorIngestRunPolicy;
use App\Domain\Competitor\Policies\CompetitorPolicy;
use App\Domain\Competitor\Policies\CompetitorPricePolicy;
use App\Domain\Competitor\Policies\CsvParseErrorPolicy;
use App\Domain\CRM\Appliers\CrmPushRetryApplier;
use App\Domain\CRM\Appliers\QuotePushRetryApplier;
use App\Domain\CRM\Console\Commands\BitrixBackfillOrdersCommand;
use App\Domain\CRM\Console\Commands\BitrixBootstrapCommand;
use App\Domain\CRM\Console\Commands\BitrixQuotesBootstrapCommand;
use App\Domain\CRM\Console\Commands\BitrixSchemaRefreshCommand;
use App\Domain\CRM\Console\Commands\BitrixSmokeTestCommand;
use App\Domain\CRM\Console\Commands\GdprEraseBitrixCustomerCommand;
use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Models\GdprErasureLogEntry;
use App\Domain\CRM\Policies\BitrixBackfillRunPolicy;
use App\Domain\CRM\Policies\BitrixEntityMapPolicy;
use App\Domain\CRM\Policies\CrmFieldMappingPolicy;
use App\Domain\CRM\Policies\CrmPipelineSettingPolicy;
use App\Domain\CRM\Policies\CrmPushLogPolicy;
use App\Domain\CRM\Policies\CrmStatusMappingPolicy;
use App\Domain\CRM\Policies\GdprErasureLogEntryPolicy;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\BitrixSchemaCache;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Dashboard\Models\UserSavedFilter;
use App\Domain\Dashboard\Policies\DashboardSnapshotPolicy;
use App\Domain\Dashboard\Policies\UserSavedFilterPolicy;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Observers\IntegrationCredentialObserver;
use App\Domain\Integrations\Policies\IntegrationCredentialPolicy;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Pricing\Console\Commands\PricingRecomputeCommand;
use App\Domain\Pricing\Console\Commands\ScanSourcingGapsCommand;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Policies\PricingRulePolicy;
use App\Domain\Pricing\Policies\ProductOverridePolicy;
use App\Domain\Pricing\Services\PriceRecomputer;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Appliers\AutoCreateRetryApplier;
use App\Domain\ProductAutoCreate\Appliers\NewProductOpportunityApplier;
use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\ProductAutoCreate\Models\AutoCreateSetting;
use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Domain\ProductAutoCreate\Policies\AutoCreateRejectionPolicy;
use App\Domain\ProductAutoCreate\Policies\AutoCreateSettingsPolicy;
use App\Domain\ProductAutoCreate\Policies\AutoCreateSkipRulePolicy;
use App\Domain\Products\Console\Commands\FlagProductsMissingBuyPriceCommand;
use App\Domain\Products\Console\Commands\SnapshotsPruneCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductException;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Products\Policies\ProductExceptionPolicy;
use App\Domain\Products\Policies\ProductPolicy;
use App\Domain\Products\Policies\ProductVariantPolicy;
use App\Domain\Quotes\Console\Commands\QuotesExpireCommand;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Domain\Quotes\Observers\QuoteLineImmutabilityObserver;
use App\Domain\Quotes\Observers\QuoteTotalRecomputeObserver;
use App\Domain\Quotes\Policies\QuoteLinePolicy;
use App\Domain\Quotes\Policies\QuotePolicy;
use App\Domain\Suggestions\Appliers\StubApplier;
use App\Domain\Suggestions\Console\Commands\AutoApplyMarginSuggestionsCommand;
use App\Domain\Suggestions\Console\Commands\PruneOrphanSuggestionsCommand;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Policies\SuggestionPolicy;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use App\Domain\Sync\Commands\ExplainSupplierCostCommand;
use App\Domain\Sync\Commands\ScanSupplierAddCandidatesCommand;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Commands\SyncSupplierCommand;
use App\Domain\Sync\Commands\WooImportProductsCommand;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Policies\ImportIssuePolicy;
use App\Domain\Sync\Policies\SyncRunPolicy;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WpRestClient;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\TradePricing\Policies\CustomerGroupPolicy;
use App\Domain\TradePricing\Services\RoleToGroupMapper;
use App\Domain\TradePricing\Services\TradeRuleResolver;
use App\Domain\Webhooks\Console\Commands\PruneWebhookReceiptsCommand;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;
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
        $this->app->singleton(PriceRecomputer::class);

        // ── Phase 9 Plan 02: TradeRuleResolver decorator (TRDE-02) ───────
        // Decorator wraps v1's RuleResolver via constructor injection.
        // When $customerGroupId is null|0 it delegates verbatim to v1 (retail
        // byte-identical fast-path); when set, it walks the 5-tier specificity
        // sort then falls through to v1 on miss. v1 retail callers
        // (PriceRecomputer, SimulatedImpactCalculator, RuleExplorer,
        // ComputeMarginSuggestionJob, CreateWooProductJob) keep resolving via
        // RuleResolver directly — they don't know customer groups exist.
        // Singleton so $app->make(TradeRuleResolver::class) returns the same
        // instance per request (matches v1 PriceRecomputer pattern).
        $this->app->singleton(TradeRuleResolver::class, function ($app) {
            return new TradeRuleResolver(
                $app->make(RuleResolver::class),
            );
        });

        // ── Phase 9 Plan 04: RoleToGroupMapper (TRDE-04 D-07) ─────────────
        // Reads config('b2b.role_to_group_map') on every resolve() so operator
        // can hot-swap mappings via .env / config:clear without restarting
        // workers. Singleton because the service is stateless and the
        // listener (UpdateCustomerGroupOnUserRoleChange) + future backfill
        // command (Plan 09-06) both consume the same instance per request.
        $this->app->singleton(RoleToGroupMapper::class);

        // ── Phase 2 Plan 02: Woo REST + Supplier API clients ─────────────
        // Automattic's WooCommerce SDK binding — single shared instance per request
        // (cURL handle + consumer key/secret are stable across calls).
        $this->app->singleton(Client::class, function ($app) {
            return new Client(
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
        //
        // Phase 09.1 Plan 01 — credentials sourced via IntegrationCredentialResolver
        // (D-07). The standalone Automattic\WooCommerce\Client binding above is
        // retained for tests that pre-stage a stubbed SDK via Mockery; production
        // code-paths build the SDK inside WooClient::sdk() from resolver creds.
        $this->app->singleton(WooClient::class, function ($app) {
            return new WooClient(
                $app->make(IntegrationLogger::class),
                $app->make(IntegrationCredentialResolver::class),
            );
        });

        // WpRestClient — Basic Auth wrapper for WordPress REST API endpoints
        // (`/wp/v2/...`). Distinct from WooClient because the WC consumer
        // key/secret only auths `/wc/v3/*`. Used for `product_brand`
        // taxonomy writes (the storefront's clickable Brand: <link> on
        // meetingstore.co.uk reads from this taxonomy — see memory
        // meetingstore-brand-display). Singleton to share one Http client
        // per request.
        $this->app->singleton(WpRestClient::class, function ($app) {
            return new WpRestClient(
                baseUrl: (string) config('services.wp_rest.base_url'),
                username: config('services.wp_rest.username'),
                appPassword: config('services.wp_rest.app_password'),
            );
        });

        // SupplierClient — non-singleton is fine: JWT state lives in Cache, not on
        // the instance, so re-instantiation is cheap and tests benefit from fresh
        // instances per resolve.
        //
        // Phase 09.1 Plan 01 — credentials sourced via IntegrationCredentialResolver
        // (D-07). Replaces direct config('services.supplier.*') reads.
        $this->app->bind(SupplierClient::class, function ($app) {
            return new SupplierClient(
                $app->make(IntegrationLogger::class),
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
                $app->make(IntegrationCredentialResolver::class),
            );
        });

        // ── Phase 4 Plan 02: Bitrix CRM client + schema cache ─────────────
        // BitrixClient wraps the official b24phpsdk with shadow-mode gate,
        // 2 req/sec throttle, and D-11 exception classification. Singleton so
        // per-request correlation_id flows naturally through one logger instance
        // and the per-instance throttle timestamp enforces the rate limit.
        $this->app->singleton(BitrixClient::class, function ($app) {
            // Phase 09.1 Plan 01 — webhook URL sourced via IntegrationCredentialResolver
            // (D-07). Replaces direct config('services.bitrix.webhook_url') reads.
            return new BitrixClient(
                $app->make(IntegrationLogger::class),
                $app->make(IntegrationCredentialResolver::class),
            );
        });

        // BitrixSchemaCache — singleton so the Laravel cache is consulted through
        // one resolver per request and warm-up results short-circuit after the
        // first fieldsFor() call in a chain.
        $this->app->singleton(BitrixSchemaCache::class);

        // ── Phase 6 Plan 02: Intervention ImageManager DI binding ────────
        // intervention/image-laravel's ServiceProvider binds the manager to the
        // string key 'image' (Facades\Image::BINDING) — NOT to the class name.
        // Our ProductImageProcessor takes ImageManager via constructor typehint,
        // so the container can't auto-resolve the required $driver primitive.
        // Alias ImageManager::class to the pre-built facade binding so DI works.
        $this->app->bind(ImageManager::class, fn ($app) => $app->make('image'));

        // ── Phase 8 Plan 03: C4 Agent Framework runtime services ────────
        // All four are singletons — the registry is in-memory state and the
        // budget/tool/guardrail services hold per-request configuration that
        // benefits from a single shared instance (matches v1
        // SuggestionApplierResolver pattern). Plan 04 RunAgentJob resolves
        // them via constructor injection.
        $this->app->singleton(AgentRegistry::class);
        $this->app->singleton(BudgetGuard::class);
        $this->app->singleton(ToolBus::class);
        $this->app->singleton(GuardrailEngine::class);
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
                $resolver->register('crm_push_failed', CrmPushRetryApplier::class);
                // Phase 6 Plan 03 — REAL applier (RESEARCH Q4 resolution): file
                // moved from app/Domain/Competitor/Appliers/ into
                // app/Domain/ProductAutoCreate/Appliers/. Body replaced with
                // CreateWooProductJob::dispatch(). Old FQCN deleted.
                $resolver->register(
                    'new_product_opportunity',
                    NewProductOpportunityApplier::class,
                );
                // Phase 6 Plan 03 — DLQ replay applier for kind='auto_create_failed'.
                // CreateWooProductJob::failed() writes the Suggestion row; the
                // Plan 04 Filament Replay action dispatches ApplySuggestionJob →
                // this applier → fresh CreateWooProductJob (mirrors Phase 4
                // CrmPushRetryApplier precedent).
                $resolver->register(
                    'auto_create_failed',
                    AutoCreateRetryApplier::class,
                );
                // Phase 5 Plan 03 Task 3 — THIRD real producer (and the first
                // PRODUCTIVE one beyond the CRM retry seam). Approving a
                // margin_change Suggestion updates PricingRule via Eloquent →
                // PricingRuleObserver fires PricingRuleChanged → Phase 3's
                // recompute chain picks up the new margin.
                $resolver->register('margin_change', MarginChangeApplier::class);

                // Phase 11 Plan 05 Task 1 — DLQ recovery applier for kind='quote_push_failed'.
                // PushQuoteToBitrixDealJob (Plan 11-04) writes the failed Suggestion in
                // two paths: (a) handle()-catch on BitrixPermanentException 4xx fail-fast,
                // (b) failed() hook after retries exhausted. Admin clicks Replay in the
                // Filament Suggestions inbox → ApplySuggestionJob → THIS applier →
                // fresh PushQuoteToBitrixDealJob with original quote_id + correlation_id.
                // Operator-driven recovery loop per RESEARCH OQ-5 (no auto-retry).
                $resolver->register(
                    'quote_push_failed',
                    QuotePushRetryApplier::class,
                );

                // Phase 12 Plan 04 — SeoContentPatchApplier writes through to
                // Product.{name|*_description} + ProductOverride.pin_{field}=true
                // when an admin approves a bundled seo_content_patch Suggestion.
                // Title→name column mapping is fenced by
                // SeoContentPatchApplierTitleToNameTest (P12 critical gotcha).
                // NOTE: kind='agent_guardrail_blocked' is NOT registered here —
                // those Suggestions are audit-only forensic rows from
                // RunSeoAgentJob's catch(GuardrailViolationException) catch block;
                // admin cannot approve them (Plan 12-05 filters them from the
                // default Suggestion list).
                $resolver->register(
                    'seo_content_patch',
                    SeoContentPatchApplier::class,
                );

                // ── Phase 10 Plan 01: EchoApplier deleted (P10-H sweep) ──────
                // EchoApplier (kind='echo_health') was the Phase 8 framework
                // smoke-test fixture. Phase 10 deletes it because PricingAgent
                // is the first REAL framework consumer; the Phase 8 framework
                // smoke contract migrates to tests/Feature/Agents/FrameworkSmokeTest.php
                // (inline fixture stub — no production applier needed).
                // Phase 10 Plan 04 ships PricingAgentResultMapper as the real
                // mapper for kind='margin_change' enrichment (no new applier
                // — MarginChangeApplier above stays the approve seam).
            }
        );

        // ── Phase 10 Plan 01: AgentRegistry — register PricingAgent ──────
        // EchoAgent (kind='echo') was deleted in the Plan 10-01 P10-H sweep
        // (it was Phase 8 framework smoke-test scaffolding, not a business
        // consumer). PricingAgent is the first REAL RunsAsAgent
        // implementation (PRCAGT-01 / PRCAGT-02 / PRCAGT-05). Phase 12/14/15
        // will add 'seo' / 'chatbot' / 'ad_optimisation' through the same
        // afterResolving hook. Block stays adjacent to the
        // SuggestionApplierResolver block above so registrations cluster
        // by phase rather than by Service Provider call order.
        //
        // The framework-integrity contract that EchoAgent's smoke test used
        // to assert is now preserved by tests/Feature/Agents/FrameworkSmokeTest.php
        // via an inline fixture stub agent class (no production registration
        // needed for the smoke test).
        $this->app->afterResolving(
            AgentRegistry::class,
            function (AgentRegistry $registry): void {
                $registry->register('pricing', PricingAgent::class);
                // ── Phase 12 Plan 01: AgentRegistry — register SeoAgent ──
                // Second REAL RunsAsAgent consumer of the Phase 8 framework.
                // Plan 12-05 ships RunSeoAgentBatchCommand (nightly 04:30 London).
                // Plan 12-04 ships RunSeoAgentJob + Filament sidebar.
                // Plan 12-01 — this line — is the AgentRegistry binding so
                // downstream plans wire against a stable interface.
                $registry->register('seo', SeoAgent::class);
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
        // Operator-managed allowlist preserving publish status against the
        // FlagProductsMissingBuyPriceCommand demotion. Filament Resource at
        // /admin/product-exceptions. admin+pricing_manager CRUD, sales+
        // read_only view-only, admin-only delete.
        Gate::policy(ProductException::class, ProductExceptionPolicy::class);
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
        Gate::policy(BitrixEntityMap::class, BitrixEntityMapPolicy::class);
        Gate::policy(CrmFieldMapping::class, CrmFieldMappingPolicy::class);
        Gate::policy(CrmStatusMapping::class, CrmStatusMappingPolicy::class);
        Gate::policy(CrmPipelineSetting::class, CrmPipelineSettingPolicy::class);
        Gate::policy(BitrixBackfillRun::class, BitrixBackfillRunPolicy::class);

        // Phase 4 Plan 05 — GDPR erasure audit (CRM-13). Admin read-only;
        // create/update/delete denied (append-only from GdprEraser service).
        Gate::policy(GdprErasureLogEntry::class, GdprErasureLogEntryPolicy::class);

        // ── Phase 5 Plan 01: Competitor domain policies ─────────────────
        // D-02 + D-04 role split: admin has full CRUD on competitors;
        // pricing_manager can resolve quarantined CSV mappings + parse errors;
        // sales can view competitor prices + ingest runs for quote context.
        // Hand-written hasRole() checks per Pitfall K + P2-H + P5-F — do NOT
        // regenerate via shield:generate. PolicyTemplateIntegrityTest
        // (tests/Architecture) catches any Shield `{{ Placeholder }}` leaks.
        Gate::policy(Competitor::class, CompetitorPolicy::class);
        Gate::policy(CompetitorPrice::class, CompetitorPricePolicy::class);
        Gate::policy(CompetitorCsvMapping::class, CompetitorCsvMappingPolicy::class);
        Gate::policy(CompetitorIngestRun::class, CompetitorIngestRunPolicy::class);
        Gate::policy(CsvParseError::class, CsvParseErrorPolicy::class);

        // Phase 11.2 Plan 01 — multi-feed FTP refactor (D-09 + D-11).
        // Replaces Phase 11.1's per-source CompetitorFtpSourcePolicy (deleted).
        //
        // CompetitorFtpCredentialPolicy — admin-only on every method (D-09).
        //   STRICTER than the other Competitor policies because credentials hold
        //   encrypted FTP secrets. pricing_manager / sales / read_only all 403.
        //
        // CompetitorFtpFeedPolicy — admin write + pricing_manager view-only (D-11).
        //   Sales + read_only have NO access (feeds are credentials-adjacent).
        //
        // Pitfall P5-F — hand-written hasRole / hasAnyRole checks; DO NOT
        // regenerate via shield:generate. Use shield:safe-regenerate instead.
        Gate::policy(CompetitorFtpCredential::class, CompetitorFtpCredentialPolicy::class);
        Gate::policy(CompetitorFtpFeed::class, CompetitorFtpFeedPolicy::class);

        // ── Phase 09.1 Plan 01 — Integration Connections Admin (D-01 + D-12 + D-14) ──
        // IntegrationCredentialPolicy — admin-only on every method (D-12).
        // STRICTER than the rest of the app: credentials hold encrypted secrets
        // for the 5 integrations (Supplier API JWT / Woo REST / Bitrix webhook /
        // Anthropic API key / Langfuse keys). pricing_manager / sales / read_only
        // all 403 (no view perms in RolePermissionSeeder either).
        //
        // Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
        // shield:generate. Use shield:safe-regenerate (Phase 8).
        //
        // IntegrationCredentialObserver — invalidates IntegrationCredentialResolver's
        // 60s per-kind cache key on every save/delete/forceDelete so operator
        // credential rotation takes effect within ≤60s (D-06).
        Gate::policy(
            IntegrationCredential::class,
            IntegrationCredentialPolicy::class,
        );
        IntegrationCredential::observe(
            IntegrationCredentialObserver::class,
        );

        // ── Phase 6 Plan 01: ProductAutoCreate domain policies ──────────
        // D-04 + T-06-01-04 role split: admin governs skip-rule CRUD (cost +
        // brand-reputation impact). pricing_manager has view-only on rules +
        // create/view on rejections (review-inbox triage). sales + read_only
        // denied entirely.
        // Pitfall P5-F — hand-written hasRole checks; do NOT shield:generate.
        Gate::policy(AutoCreateSkipRule::class, AutoCreateSkipRulePolicy::class);
        Gate::policy(AutoCreateRejection::class, AutoCreateRejectionPolicy::class);

        // ── Phase 6 Plan 04: AutoCreateSetting policy ──────────────────
        // Admin-only gate on the singleton settings model (governs draft-vs-
        // immediate-publish — load-bearing AUTO-07 decision). Both the Page
        // canAccess() gate + the save() abort_unless consult this binding.
        Gate::policy(AutoCreateSetting::class, AutoCreateSettingsPolicy::class);

        // ── Phase 7 Plan 01: Dashboard domain policies ─────────────────
        // D-02 + D-07: dashboard snapshots are admin/pricing/sales/read_only
        // viewable (ambient ops intel) but create/update DENY for all (the
        // scheduled dashboard:refresh command is the only writer). User
        // saved filters are owner-scoped with an admin override on delete.
        // Pitfall P5-F — hand-written hasRole checks; DO NOT shield:generate.
        Gate::policy(DashboardSnapshot::class, DashboardSnapshotPolicy::class);
        Gate::policy(UserSavedFilter::class, UserSavedFilterPolicy::class);

        // ── Phase 4 Plan 04: CRM Push Log (read-only view over integration_events) ──
        // CrmPushLogResource binds to IntegrationEvent but scopes the query to
        // channel='bitrix'. Policy grants viewAny/view to admin + sales (D-02);
        // all mutations denied. This registration only affects CRM — the Resource
        // is the only Filament surface that renders IntegrationEvent rows.
        Gate::policy(IntegrationEvent::class, CrmPushLogPolicy::class);

        // ── Phase 9 Plan 05: TradePricing — CustomerGroup policy ─────────
        // CRUD gates for the new CustomerGroupResource (TRDE-04 D-10).
        // Permission strings (`*_customer_group`) are seeded by
        // RolePermissionSeeder per Plan 05 Task 2; policy methods consult
        // them via `$user->can()`. Sales = view-only; read_only = locked
        // out entirely. Pitfall P5-F — DO NOT regenerate via shield:generate
        // (use shield:safe-regenerate --allow-new=CustomerGroupPolicy on
        // first scaffold; subsequent runs drop the --allow-new flag).
        Gate::policy(
            CustomerGroup::class,
            CustomerGroupPolicy::class,
        );

        // ── Phase 8 Plan 01: C4 Agent Framework — AgentRun policy ────────
        // Admin-only viewAny/view; create/update/delete return false
        // unconditionally because AgentRuns are produced by Plan 04's
        // RunAgentJob and never edited via Filament. Hand-written hasRole
        // check per Pitfall K + P5-F — DO NOT regenerate via shield:generate
        // without porting back the hasRole layer. PolicyTemplateIntegrityTest
        // floor bumps 26 → 27 with this policy (caught by the architecture
        // suite on every CI run).
        //
        // Filament Resource for AgentRun arrives in Plan 08-04; this policy
        // ships in Plan 08-01 so the architecture-test floor is in place
        // before the Filament surface lands.
        Gate::policy(AgentRun::class, AgentRunPolicy::class);

        // ── Phase 11 Plan 01: E2 Quote Request → Bitrix Deal Flow policies ──
        // QuotePolicy enforces D-04 separation-of-duties: sales role cannot
        // approve own quote (T-11-01-03 mitigation). QuoteLinePolicy enforces
        // D-13 line snapshot immutability: line edits forbidden after parent
        // Quote.status leaves draft. Both policies are hand-written per
        // Pitfall K + P5-F — DO NOT regenerate via shield:generate.
        // PolicyTemplateIntegrityTest floor bumps 27 → 29 to cover the two
        // new policies (caught by the architecture suite on every CI run).
        // Filament QuoteResource arrives in Plan 11-03; these policies ship
        // in Plan 11-01 so the gate is in place before the UI lands.
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(QuoteLine::class, QuoteLinePolicy::class);

        // ── Phase 11 Plan 02: QuoteLine observer chain (D-13 + OQ-1) ─────
        // ORDER MATTERS — the array-form ::observe() preserves registration
        // order across Eloquent's saving / saved hooks:
        //   1. QuoteLineImmutabilityObserver (saving) — gate-keeper. Throws
        //      QuoteLineImmutableException when status != draft AND any
        //      forbidden column is dirty (T-11-02-01 mitigation).
        //   2. QuoteTotalRecomputeObserver (saved + deleted) — runs only
        //      when the immutability gate passed. Recomputes parent
        //      Quote.total_pence_at_quote = SUM(quote_lines.line_total)
        //      while status == draft; locked alongside lines after sent.
        //
        // PriceSnapshotter + QuoteLineWriter (Plan 11-02 Task 1) are the
        // sole legitimate creation path; the immutability observer no-ops
        // on creation (! $line->exists) so initial snapshot writes succeed.
        QuoteLine::observe([
            QuoteLineImmutabilityObserver::class,
            QuoteTotalRecomputeObserver::class,
        ]);

        // ── Phase 2 Plan 03: register SyncSupplierCommand ────────────────
        // Laravel 12 auto-discovers artisan commands from app/Console/Commands/.
        // Our command lives under app/Domain/Sync/Commands/, so we register it
        // explicitly via ServiceProvider::commands(). Keeping this in
        // AppServiceProvider::boot avoids touching bootstrap/app.php (Warning 2 —
        // iter-1 fix) and only runs the registration when artisan is bootstrapping
        // (runningInConsole guard).
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncSupplierCommand::class,
                // Quick task 260504-d7v — bulk Woo→products import.
                // Closes the bootstrap gap where products table couldn't be
                // populated from an existing Woo catalogue (sync:supplier
                // updates existing rows but never creates).
                WooImportProductsCommand::class,
                // Quick task 260504-m5w — daily supplier MySQL VPS sync.
                // Pulls price + stock from supplier_products on stcav_dash and
                // updates local products.buy_price + stock_quantity. Match key:
                // LOWER(TRIM(mpn)) preferred, LOWER(TRIM(suppliersku)) fallback.
                SupplierDbSyncCommand::class,
                // 2026-05-25 — cost-traceability diagnostic: show every supplier
                // offer for a SKU + which one sets buy_price (cheapest in-stock).
                ExplainSupplierCostCommand::class,
                // 2026-05-25 — catalogue-expansion scan: parts on ≥2 suppliers
                // not on MS, cached for the dashboard "Products to add" tile.
                ScanSupplierAddCandidatesCommand::class,
                // 2026-05-27 — sourcing-gap scan: parts a competitor lists that
                // NO supplier carries + we don't sell (likely obsolete), cached
                // for the dashboard "Sourcing gaps" tile.
                ScanSourcingGapsCommand::class,
                // Phase 3 Plan 04 Task 2 — operator CLI for catalogue-wide
                // recompute. Default dry-run, --live opt-in (D-12). Lives
                // under app/Domain/Pricing/Console/Commands/ so explicit
                // registration is required (same pattern as Phase 2).
                PricingRecomputeCommand::class,
                // Phase 4 Plan 01 — Bitrix CRM Sync commands.
                // bitrix:bootstrap creates UF_CRM_WOO_ORDER_ID + 13 UTM/customer
                // custom fields in Bitrix (idempotent, safe on every deploy).
                // bitrix:smoke-test probes the SDK's API surface before Plan 04-02
                // locks the BitrixClient wrapper interface (two-layer gate —
                // BITRIX_SMOKE_TEST_ALLOWED + BITRIX_WEBHOOK_URL).
                BitrixBootstrapCommand::class,
                BitrixSmokeTestCommand::class,
                // Phase 4 Plan 02 — CRM-02 field-schema cache refresh.
                // Invalidates the 24h cache + refetches deal/contact/company
                // schemas. Admin-triggered after Bitrix UF_CRM_* edits.
                BitrixSchemaRefreshCommand::class,
                // Phase 4 Plan 05 — CRM-10 backfill + CRM-13 GDPR erasure.
                // Backfill has 3 modes (dry-run / live / adopt-legacy-deal-ids)
                // and --since is REQUIRED (no default). GDPR erasure requires
                // typed ERASE confirmation + dispatches EraseBitrixContactJob.
                BitrixBackfillOrdersCommand::class,
                GdprEraseBitrixCustomerCommand::class,
                // Phase 11 Plan 04 — pre-flight check for Phase 11 quote-flow:
                // verify TYPE_ID=QUOTE deal category exists + idempotently create
                // UF_CRM_WOO_QUOTE_ID. Operator runs this BEFORE flipping
                // QUOTE_BITRIX_PUSH_ENABLED=true. Standalone command (NOT an
                // extension of BitrixBootstrapCommand per B-03 byte-identity).
                BitrixQuotesBootstrapCommand::class,
                // Phase 11 Plan 05 (QUOT-08) — quotes:expire scheduled command.
                // Dry-run-default per cross-cutting invariant 3; --live opt-in.
                // Scheduled at 00:30 daily Europe/London via routes/console.php;
                // ad-hoc operator runs default to dry-run for safety. Lives
                // under app/Domain/Quotes/Console/Commands/ so explicit
                // registration is required.
                QuotesExpireCommand::class,
                // Phase 5 Plan 02 Task 2 — scheduled 5-minute CSV watcher (COMP-01+04).
                CompetitorWatchCommand::class,
                // Quick task 260504-e0q — operator command to replay quarantined CSVs.
                CompetitorRetryQuarantineCommand::class,
                // Phase 11.1 Plan 01 — every-15-min FTP/SFTP/FTPS pull (COMP-FTP-01).
                // Lives outside app/Console/Commands/ so explicit registration
                // mirrors the watcher pattern above. Dry-run by default per D-06;
                // schedule entry in routes/console.php passes --live opt-in flag.
                CompetitorFtpPullCommand::class,
                // Phase 5 Plan 03 Task 3 — nightly 02:00 sales-counter recache.
                // A3 fallback: dispatched job is currently a stub (WooClient lacks
                // /orders); command + schedule ship so future WooClient extension
                // activates real recache with zero plumbing changes.
                CompetitorSalesRecacheCommand::class,
                // Phase 5 Plan 04b Task 2 — hourly stale-feed detector (COMP-11).
                // 48h threshold + 24h per-competitor dedup; routes via
                // AlertRecipient.receives_competitor_alerts (Plan 05-01/05-04a).
                CompetitorCheckStaleCommand::class,
                // Phase 5 Plan 05 Task 1 — daily 03:40 CSV archive prune (COMP-12).
                // --days=0 is a no-op safety guard (explicit 0); otherwise falls back
                // to config('competitor.csv_retention_days', 90). NEVER touches
                // competitor_prices rows (COMP-07 mandate, permanent regression test).
                CompetitorCsvPruneCommand::class,
                // Phase 6 Plan 01 Task 1 — Q1 supplier-API probe (RESEARCH.md Open Question Q1).
                // Dumps full supplier row for a single SKU to storage/app/research/supplier-probe.json
                // so Plan 06-02 ProductImageFetcher / ProductContentBuilder can see the real
                // image_url / brand / category / description field shape. Manual-run only.
                SupplierProbeSingleSkuCommand::class,
                // Phase 7 Plan 02 — dashboard:refresh + snapshots:prune.
                // dashboard:refresh (D-02) every 5 min via routes/console.php aggregates
                // the 9 home-dashboard metrics into dashboard_snapshots so every widget
                // read is a single indexed lookup. snapshots:prune (daily 03:50) keeps
                // the snapshot table small + forward-compatible with the deferred
                // sparkline history split.
                DashboardRefreshCommand::class,
                PruneDashboardSnapshotsCommand::class,
                // Phase 7 Plan 04 — reports:weekly-digest (DASH-05 / D-08).
                // Scheduled Monday 07:00 Europe/London (routes/console.php). Composes
                // the 5-section digest and sends to AlertRecipient where
                // receives_weekly_digest=true. Writes dashboard_snapshots.weekly_report_status
                // so the Plan 07-02 WeeklyReportStatusWidget picks up last_sent_at +
                // recipient_count (Plan 07-02 computeWeeklyReportStatus preserves these).
                WeeklyDigestCommand::class,
                // Stock-updater parity glue — daily post-supplier-sync digest
                // (replaces the legacy plugin's send_results_and_cleanup() email).
                SupplierSyncDigestCommand::class,
                // Stock-updater parity glue — auto-apply margin_change Suggestions
                // whose delta crosses pricing.auto_apply_threshold_bps (legacy
                // plugin's setPer() ≥ 8% rule).
                AutoApplyMarginSuggestionsCommand::class,
                // Quick task 260606-gnu — auto-reject stale competitor-only
                // orphan new_product_opportunity Suggestions (off-supplier-DB +
                // <2 competitors + >=30 days old). Mon 06:00 London cron.
                PruneOrphanSuggestionsCommand::class,
                // Quick task 260607-cgd — products:backfill-merchant-feed. Backfills
                // EAN/brand/category from supplier_db onto live products to lift
                // Google Merchant Center disapproval rate (89% → <10% target).
                // Default --dry-run; --resync chains products:resync-to-woo on the
                // SUCCESSFULLY UPDATED SKUs only. Reuses NormalisesEan trait so the
                // EAN validator stays byte-identical to GenerateProductDraftsCommand.
                BackfillMerchantFeedCommand::class,
                // Quick task 260607-9c6 (SECURITY-REVIEW.md H-1) — daily 03:25
                // London prune of webhook_receipts.raw_body. Per-topic GDPR
                // retention (order=30d, customer=7d, other=90d). Closes the
                // GDPR Art. 5(1)(e) storage-limitation gap on Woo PII webhooks.
                PruneWebhookReceiptsCommand::class,
                // Stock-updater parity glue — flip published Products with
                // NULL/zero buy_price to status=pending (legacy plugin's
                // logProductChanges() / handle_pending_product() behaviour).
                FlagProductsMissingBuyPriceCommand::class,
                // ── Phase 7 Plan 05 — Cutover commands (CUT-01..07, D-12..D-21) ────
                // Six artisan commands orchestrating the legacy-plugin → Laravel
                // cutover. All extend BaseCommand (correlation_id threading) and
                // route through IntegrationLogger + Auditor. Lives under
                // app/Console/Commands/Cutover/ so explicit registration required.
                //
                // Per D-19 sequence:
                //   1. cutover:snapshot-woo-db      (CUT-04) pre-cutover safety net
                //   2. cutover:divergence-scan      (CUT-01) parity baseline
                //   3. cutover:populate-overrides   (CUT-02) preserve human edits
                //   4. cutover:drill-rollback       (CUT-05) rollback verification
                //   5. cutover:disable-legacy-plugins (CUT-03 + CUT-07)
                //   6. cutover:checklist            (D-21) PASS/PENDING/FAIL report
                //
                // --live gates on CUTOVER_DRILL_ALLOWED / CUTOVER_DISABLE_LIVE_ALLOWED
                // env vars (config keys store NAMES, not values — two-step safety).
                DivergenceScanCommand::class,
                PopulateOverridesCommand::class,
                SnapshotWooDbCommand::class,
                DrillRollbackCommand::class,
                DisableLegacyPluginsCommand::class,
                CutoverChecklistCommand::class,
                // Cutover step C-NEW (2026-05-27) — reconcile non-publish LOCAL
                // status onto Woo so --flag-obsolete demotions actually leave
                // the storefront on flip day. Shadow-safe; defaults dry-run.
                PushProductStatusToWooCommand::class,
                // Phase 8 Plan 04 — agent:run {kind} [--dry-run] CLI entry
                // point for the C4 framework. Extends BaseCommand so the
                // correlation_id threads through the entire CLI → Job →
                // AgentRun → Suggestion pipeline (AGNT-12 acceptance).
                AgentRunCommand::class,
                // ── Phase 8 Plan 05 — operational hygiene commands ────────
                // shield:safe-regenerate (AGNT-11) wraps shield:generate with
                // automatic P5-F restoration. Phase 10/11/13/14/15 will use
                // it on every new Resource. Documented in
                // docs/ops/shield-regeneration.md.
                ShieldSafeRegenerateCommand::class,
                // agents:gdpr-purge-langfuse (D-09 sibling) — STUB per Open
                // Question Q1 RESOLVED (RESEARCH §Open Questions). v2.1 swaps
                // to live API once Langfuse retention API stabilises.
                AgentsGdprPurgeLangfuseCommand::class,
                // agents:prune-archive (D-07) — annual 5-year retention prune.
                // Schedule registered in routes/console.php (1 Jan 02:00
                // Europe/London). Default --days=1825 covers the full D-07
                // horizon; --dry-run for safe ops verification.
                AgentsPruneArchiveCommand::class,
                // Phase 12 Plan 05 (SEOAGT-05) — agents:run-seo-batch.
                // Nightly batch dispatches up to 20 RunSeoAgentJob instances
                // over Phase 6 AutoCreate drafts. Between-dispatch monthly
                // budget recheck (P12-E mitigation) prevents overshoot when
                // the £200 ceiling is already near. Schedule in
                // routes/console.php at 04:30 Europe/London, env-flag gated
                // (AGENT_SEO_BATCH_SCHEDULE_ENABLED, default true).
                RunSeoAgentBatchCommand::class,
                // Quick task 260504-muq — history:prune (90-day price + stock
                // history retention). Deletes product_price_snapshots +
                // supplier_offer_snapshots older than
                // config('history.retention_days', 90). Scheduled at 04:00
                // Europe/London via routes/console.php (continues the
                // 03:00..03:50 retention cascade).
                SnapshotsPruneCommand::class,
            ]);
        }
    }
}
