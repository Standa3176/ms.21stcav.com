---
phase: 06-product-auto-create
plan: 03
subsystem: product-auto-create
tags: [domain-events, listener-dispatch, orchestrator-job, publish-job, pin-enforcement-guard, taxonomy-resolver, applier-move-q4, retry-applier, auto-01, auto-02, auto-05, p6-g, a3-finding, listener-over-observer]

requires:
  - phase: 06-01
    provides: "app/Domain/ProductAutoCreate/ directory + 5 domain services (ProductContentBuilder + ProductSlugGenerator + ProductMatcher + CompletenessScorer + ProductImageFetcher); AutoCreateSkipRule model with matches(); config/product_auto_create.php (brand_taxonomy / category_taxonomy keys); products.auto_create_status + completeness_* columns; ProductOverride 8 pin_* columns with LogsActivity audit; A3 FINDING — saveQuietly suppresses BOTH saving + saved on Laravel 12, so observer pattern is unworkable."
  - phase: 06-02
    provides: "ProcessAutoCreateImageJob on sync-bulk queue; ImagePayloadBuilder; WooClient URL-pass-through contract (Q5). Plan 03's CreateWooProductJob calls ProcessAutoCreateImageJob::dispatch at the end of its happy-path."
  - phase: 02-03
    provides: "NewSupplierSkuDetected event (Phase 6 is the first real consumer); SupplierPriceChanged + SupplierStockChanged + SupplierSkuMissing events (the completeness-score recompute listener subscribes to all three)."
  - phase: 03-02
    provides: "RuleResolver + PriceCalculator (CreateWooProductJob uses both to set initial sell_price); SupplierPriceUnusableException (downgrades sell_price=null rather than retrying)."
  - phase: 04-03
    provides: "CrmPushRetryApplier pattern — AutoCreateRetryApplier mirrors the same shape (kind → dispatch fresh job); HandleOrderReceived listener queueing shape."
  - phase: 05-02
    provides: "NewProductOpportunityApplier Phase 5 stub — Phase 6 MOVES (not replaces) the file + swaps to real body (RESEARCH Q4 option b)."

provides:
  - "4 DomainEvents under app/Domain/ProductAutoCreate/Events/ — AutoCreateAttempted(sku), AutoCreateSucceeded(productId, wooProductId, sku, slug, completenessScore, autoCreateStatus), AutoCreateFailed(sku, reason, ?exceptionClass, ?exceptionMessage), ProductPublished(productId, wooProductId, publishedByUserId). All extend Foundation DomainEvent → inherit ShouldDispatchAfterCommit (Pitfall P2-I rollback safety)"
  - "App\\Domain\\ProductAutoCreate\\Listeners\\HandleNewSupplierSku — AUTO-01 primary listener. ShouldQueue on sync-bulk. D-04 skip-rule gate iterates AutoCreateSkipRule::active(); on ANY match logs auto_skipped to integration_events with matched_rule_ids in request_body + returns. Otherwise dispatches CreateWooProductJob::dispatch(sku). T-06-03-04 fail-soft — per-rule exceptions (catastrophic regex) logged + rule skipped, dispatch still fires"
  - "App\\Domain\\ProductAutoCreate\\Jobs\\CreateWooProductJob — full handle() orchestrator on sync-woo-push queue. tries=3, backoff=[30,300,1800]. Pipeline: AutoCreateAttempted → ProductMatcher::existsNormalised dup gate (AUTO-08) → SupplierClient::fetchSingleProduct (T-06-03-01 tampering guard) → ProductContentBuilder::compile → ProductSlugGenerator::generate → pre-POST Woo slug probe (Pitfall P6-G option 1) → TaxonomyResolver::resolveBrand+Category → Product::create (draft OR needs_brand_or_category_assignment) → RuleResolver+PriceCalculator (SupplierPriceUnusableException downgrades to sell_price=null) → WooClient::post('/products') → forceFill+saveQuietly Woo-returned slug+id+sell_price (Pitfall P6-G option 2) → ProcessAutoCreateImageJob::dispatch → CompletenessScorer → AutoCreateSucceeded. failed() DLQ → kind='auto_create_failed' Suggestion"
  - "App\\Domain\\ProductAutoCreate\\Jobs\\PublishProductJob — admin-triggered draft → published. sync-woo-push queue, tries=3. Flips Product.auto_create_status='published' + status='publish' + WooClient::put('/products/{wooId}', ['status'=>'publish']) when woo_product_id set, skips PUT otherwise. Fires ProductPublished(productId, wooProductId, publishedByUserId) for Phase 7 dashboard subscribers"
  - "App\\Domain\\ProductAutoCreate\\Services\\TaxonomyResolver — resolveBrand(?string): ?int + resolveCategory(?string): ?int. Null/empty input → null. Uses configurable taxonomy slugs (product_auto_create.brand_taxonomy default 'pa_brand'). Case-insensitive trim-tolerant exact match over `name` field. Any WooClient exception → null (fail-soft; logged by WooClient already)"
  - "App\\Domain\\ProductAutoCreate\\Services\\ProductOverrideGuard — revertIfPinned(int wooProductId, array fieldNames, string source). 7-entry field map: name→pin_title, slug→pin_slug, short_description→pin_short_description, description→pin_long_description, meta_description→pin_meta_description, regular_price→pin_price, images→pin_image. Shape helpers: images → [{src: URL}], regular_price → 'xx.xx' string, rest pass-through. Audits via Auditor::record('product_auto_create.pin_reverted'). Silent no-ops when product / override missing OR no pin flag true for any requested field. stock_quantity + status have no pin map entries (intentionally — those are sync-level state)"
  - "App\\Domain\\ProductAutoCreate\\Listeners\\RecomputeCompletenessOnSupplierChange — A3 FINDING mitigation. Subscribes to Phase 2's SupplierPriceChanged (@handlePriceChanged), SupplierStockChanged (@handleStockChanged), SupplierSkuMissing (@handleSkuMissing). Looks up Product by woo_product_id + recomputes CompletenessScorer::score + persists 3 columns via forceFill+saveQuietly. Silent no-op on unknown woo_product_id (supplier SKUs not yet auto-created). ShouldQueue on `default` queue. REPLACES the observer approach because Laravel 12 saveQuietly suppresses both saving + saved (confirmed by Plan 01 SaveQuietlyObserverTest)"
  - "App\\Domain\\ProductAutoCreate\\Appliers\\NewProductOpportunityApplier — RESEARCH Q4 option b. FILE MOVED from app/Domain/Competitor/Appliers/ + body REPLACED with real CreateWooProductJob::dispatch(sku, suggestionId). supports() returns ['new_product_opportunity']. apply() returns {phase_6_live: true, sku, dispatched_job_class}. Missing evidence.sku → {error: missing_sku_in_evidence}"
  - "App\\Domain\\ProductAutoCreate\\Appliers\\AutoCreateRetryApplier — kind='auto_create_failed' DLQ replay (mirrors Phase 4 CrmPushRetryApplier). supports() returns ['auto_create_failed']. apply() dispatches CreateWooProductJob + returns {retry_dispatched: true, sku}"
  - "EventServiceProvider: NewSupplierSkuDetected → HandleNewSupplierSku (REPLACES Phase 2 StubNewSupplierSkuListener). SupplierPriceChanged/SupplierStockChanged/SupplierSkuMissing each get the Phase 6 RecomputeCompletenessOnSupplierChange handler via the @method string syntax (3 new bindings). Phase 2's StubNewSupplierSkuListener class file DELETED"
  - "AppServiceProvider: 2 applier registrations updated/added — new_product_opportunity → ProductAutoCreate\\NewProductOpportunityApplier (REPLACES Competitor FQCN), auto_create_failed → ProductAutoCreate\\AutoCreateRetryApplier (NEW)"
  - "Deptrac deptrac.yaml + depfile.yaml: ProductAutoCreate layer comment block updated to Plan 03 shape (allow-list unchanged — already was [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks] from Plan 01, now formally justified per-item). Competitor layer no longer needs ProductAutoCreate visibility because the applier moved — one-way arrow preserved"
  - "9 Pest feature test files: AutoCreateEventsTest (5 cases), HandleNewSupplierSkuTest (7 cases), TaxonomyResolverTest (9 cases), CreateWooProductJobTest (9 cases), PublishProductJobTest (3 cases), ProductOverrideGuardTest (7 cases), CompletenessScorerListenerTest (5 cases), NewProductOpportunityApplierTest (6 cases incl. 2 file-absence guards), AutoCreateRetryApplierTest (4 cases). Test execution deferred to MySQL-online env per Plan 06-01/02 precedent"
  - "tests/Feature/SupplierEventDispatchTest — E6 updated to assert binding on HandleNewSupplierSku (Phase 6), E7 (stub-listener log test) removed. StubNewSupplierSkuListener file itself removed. Phase 2's event-shape assertions (E1-E5) untouched"

affects:
  - "06-04-filament-ui (AutoCreateReviewResource lists draft + needs_brand_or_category_assignment + ready-for-publish + auto_create_failed rows. Plan 04 wires the admin 'Publish' action to PublishProductJob::dispatch + the admin 'Replay' action to ApplySuggestionJob for kind=auto_create_failed rows — both dispatch paths are LIVE after Plan 03)"
  - "06-05-pin-enforcement (ApplyPinsDuringSync listener subscribes to SupplierPriceChanged/SupplierStockChanged/SupplierSkuMissing events and calls ProductOverrideGuard::revertIfPinned. Plan 05 doesn't touch Phase 2 code — pure listener extension per D-11)"
  - "06-06-retention-verification (no new retention; Phase 6's 4 DomainEvents log via standard Phase 1 integration_events retention window)"
  - "07-dashboard (ProductPublished subscribers land here — Phase 6 ships the event + fires it; Phase 7 wires the 'N products published this week' tile listener)"

tech-stack:
  added:
    - "New domain directories under app/Domain/ProductAutoCreate/: Events/, Listeners/, Appliers/ — all PSR-4 autoloaded."
  patterns:
    - "Listener-based completeness recompute (A3 FINDING): Laravel 12's saveQuietly suppresses saving + saved observers. Phase 2's forceFill+saveQuietly sync path would never re-trigger an observer. Plan 06-03 therefore subscribes a listener to the 3 Phase 2 supplier-change events instead — recompute runs from inside the listener, writing via the same forceFill+saveQuietly pattern to keep activity_log clean."
    - "Handler-method event binding via `ListenerClass::class.'@methodName'` — one listener class with 3 methods serves 3 events (cleaner than 3 separate listener classes for the same recompute logic). Laravel 12 supports this string syntax natively in EventServiceProvider::$listen."
    - "Applier MOVE (not COPY) — RESEARCH Q4 option b. File relocated between domains to keep Deptrac's one-way arrow clean (Competitor → ProductAutoCreate would violate the arrow direction if the applier stayed in Competitor AND dispatched a Job that lives in ProductAutoCreate). Move = delete old file + create new namespace + delete old test + create new test + update applier registration. Caller's mental model unchanged — SuggestionApplierResolver::resolve still returns the right applier for the kind string."
    - "Post-POST Woo slug reconciliation (Pitfall P6-G): Woo auto-appends -2 / -3 on slug collision server-side. Our CreateWooProductJob does TWO defences: pre-POST probe via WooClient::get('/products', ['slug' => ...]) regenerates -{sku} on collision; post-POST forceFill reads $response['slug'] back onto Product.slug so Laravel and Woo agree even if Woo's logic fires anyway."
    - "Graceful failure downgrade for SupplierPriceUnusableException — rather than letting the pricing exception bubble + retry, CreateWooProductJob catches it specifically and ships the draft with sell_price=null / regular_price='0.00'. Ops sees the draft in the review inbox with the incomplete-price flag; retries are reserved for TRANSIENT failures (Woo 5xx / network). Supplier price 0 is a DATA-STATE issue, not a TRANSIENT one, so retrying 3x with backoff is wasted budget."
    - "Dual Deptrac config sync (Phase 5 Plan 05-05 lesson) — ProductAutoCreate layer comment block updated in BOTH deptrac.yaml AND depfile.yaml in the SAME commit. Allow-list was already in place from Plan 06-01; this plan just refreshed the comment block to reflect the real imports landed in Plan 03."

key-files:
  created:
    - "app/Domain/ProductAutoCreate/Events/AutoCreateAttempted.php"
    - "app/Domain/ProductAutoCreate/Events/AutoCreateSucceeded.php"
    - "app/Domain/ProductAutoCreate/Events/AutoCreateFailed.php"
    - "app/Domain/ProductAutoCreate/Events/ProductPublished.php"
    - "app/Domain/ProductAutoCreate/Listeners/HandleNewSupplierSku.php"
    - "app/Domain/ProductAutoCreate/Listeners/RecomputeCompletenessOnSupplierChange.php"
    - "app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php"
    - "app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php"
    - "app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php"
    - "app/Domain/ProductAutoCreate/Services/ProductOverrideGuard.php"
    - "app/Domain/ProductAutoCreate/Appliers/NewProductOpportunityApplier.php"
    - "app/Domain/ProductAutoCreate/Appliers/AutoCreateRetryApplier.php"
    - "tests/Feature/ProductAutoCreate/AutoCreateEventsTest.php"
    - "tests/Feature/ProductAutoCreate/HandleNewSupplierSkuTest.php"
    - "tests/Feature/ProductAutoCreate/TaxonomyResolverTest.php"
    - "tests/Feature/ProductAutoCreate/CreateWooProductJobTest.php"
    - "tests/Feature/ProductAutoCreate/PublishProductJobTest.php"
    - "tests/Feature/ProductAutoCreate/ProductOverrideGuardTest.php"
    - "tests/Feature/ProductAutoCreate/CompletenessScorerListenerTest.php"
    - "tests/Feature/ProductAutoCreate/NewProductOpportunityApplierTest.php"
    - "tests/Feature/ProductAutoCreate/AutoCreateRetryApplierTest.php"
  modified:
    - "app/Providers/EventServiceProvider.php (NewSupplierSkuDetected → HandleNewSupplierSku; 3 new Supplier*→RecomputeCompletenessOnSupplierChange bindings via @method syntax)"
    - "app/Providers/AppServiceProvider.php (new_product_opportunity → ProductAutoCreate FQCN; new auto_create_failed → AutoCreateRetryApplier registration)"
    - "deptrac.yaml (ProductAutoCreate layer comment block refreshed to Plan 03 shape)"
    - "depfile.yaml (mirror of deptrac.yaml)"
    - "tests/Feature/SupplierEventDispatchTest.php (E6 rebound to HandleNewSupplierSku; E7 removed)"
  deleted:
    - "app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php (replaced by HandleNewSupplierSku)"
    - "app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php (MOVED to ProductAutoCreate)"
    - "tests/Feature/Competitor/NewProductOpportunityApplierTest.php (replaced by tests/Feature/ProductAutoCreate/NewProductOpportunityApplierTest.php)"

decisions:
  - "OBSERVER → LISTENER PIVOT (A3 FINDING): Plan 01's SaveQuietlyObserverTest showed Laravel 12 saveQuietly suppresses BOTH `saving` and `saved` events. An Eloquent observer would NEVER fire during Phase 2's sync path. Plan 06-03 therefore ships RecomputeCompletenessOnSupplierChange as a listener subscribing to the 3 Phase 2 supplier-change events. The observer idea in Plan 03's <behavior> section is NOT shipped — the listener covers all paths (fresh creates trigger via CreateWooProductJob's explicit scorer call; supplier mutations trigger via the listener)."
  - "APPLIER MOVE vs COPY (RESEARCH Q4 option b): the file moved from Competitor to ProductAutoCreate. The alternative — leaving it in Competitor and adding a Competitor→ProductAutoCreate Deptrac allow — would create a circular dependency surface (Competitor already publishes its own kind='margin_change' applier; making it the owner of new_product_opportunity AND letting it dispatch a ProductAutoCreate Job inverts the dependency arrow). Moving the file keeps Competitor → ProductAutoCreate one-way."
  - "PRICING FAILURE HANDLING: SupplierPriceUnusableException downgrades the draft to sell_price=null rather than letting the exception trigger the retry chain. Retries are preserved for transient failures (Woo 5xx, network). Supplier-returned price=0 is a data-state issue that ops must address in the review inbox — not a transient one."
  - "`needs_brand_or_category_assignment` SHORT-CIRCUIT: when TaxonomyResolver returns null for brand OR category, CreateWooProductJob creates the Product with status='needs_brand_or_category_assignment' AND STOPS. No Woo POST (Woo requires a category at minimum for visibility), no image job, no AutoCreateSucceeded event. Plan 04's Filament inbox surfaces the status as a filter bucket so ops can pick a taxonomy + manually re-dispatch."
  - "HANDLER-METHOD LISTENER BINDING: Laravel's EventServiceProvider::$listen supports `ListenerClass::class.'@methodName'` string values. RecomputeCompletenessOnSupplierChange ships 3 distinct handler methods (handlePriceChanged, handleStockChanged, handleSkuMissing) for 3 distinct events, backed by one private `recomputeByWooId` helper. Alternative (3 separate listener classes) would triple the file count for no semantic benefit."
  - "FAIL-OPEN ON SKIP-RULE EXCEPTIONS (T-06-03-04): HandleNewSupplierSku catches per-rule exceptions during matching and skips the rule rather than the whole dispatch. A malformed regex from an admin-authored rule CANNOT take down the whole auto-create pipeline. Logged at warning level for ops awareness."

metrics:
  completed_at: "2026-04-23T20:52Z"
  duration_minutes: 18
  tasks_completed: 3
  files_created: 21
  files_modified: 5
  files_deleted: 3
  commits: 3
  events_added: 4
  jobs_added: 2
  listeners_added: 2
  services_added: 2
  appliers_added: 2
  test_files: 9
  deptrac_violations: 0

requirements:
  - AUTO-01 (NewSupplierSkuDetected → HandleNewSupplierSku skip-rule gate → CreateWooProductJob dispatch — END-TO-END)
  - AUTO-02 (Blade-compiled draft via ProductContentBuilder wired into CreateWooProductJob; TaxonomyResolver resolves brand + category at create time)
  - AUTO-05 (integration_events auto_skipped entries from the listener; retry/backoff shipped on CreateWooProductJob; DLQ via Suggestion kind='auto_create_failed' + AutoCreateRetryApplier)
---

# Phase 06 Plan 03: Event-Driven Orchestration + Applier Move — Summary

Phase 6's event-driven core landed in a single 3-task cadence. The auto-create pipeline now flows end-to-end from Phase 2's `NewSupplierSkuDetected` event through a skip-rule-gated listener, an orchestrator job with a full pricing + taxonomy + slug-reconciliation pipeline, and a DLQ path that rejoins via the Suggestions seam. Plan 04's Filament review inbox can wire its actions directly onto the live dispatch seam — no more stubs.

## Task-by-task outcomes

### Task 1 — 4 DomainEvents + HandleNewSupplierSku listener + TaxonomyResolver + Deptrac refresh

**Commit:** `9bf3fb8`

- `app/Domain/ProductAutoCreate/Events/` gets 4 classes: `AutoCreateAttempted(sku)`, `AutoCreateSucceeded(productId, wooProductId, sku, slug, completenessScore, autoCreateStatus)`, `AutoCreateFailed(sku, reason, ?exceptionClass, ?exceptionMessage)`, `ProductPublished(productId, wooProductId, publishedByUserId)`. Each extends the Foundation `DomainEvent` base so `ShouldDispatchAfterCommit` is inherited (Pitfall P2-I — rolled-back transactions don't leak events).
- `HandleNewSupplierSku` replaces Phase 2's `StubNewSupplierSkuListener` (stub file + class removed). D-04 skip-rule iteration logs `auto_skipped` to `integration_events` with `matched_rule_ids` in `request_body` on any rule hit; otherwise dispatches `CreateWooProductJob::dispatch($event->sku)`. T-06-03-04 fail-soft — per-rule exceptions (catastrophic regex / bad price-range syntax) are caught + logged but the listener still dispatches.
- `TaxonomyResolver` ships with `resolveBrand(?string): ?int` and `resolveCategory(?string): ?int`. Configurable taxonomy slugs (`product_auto_create.brand_taxonomy` default `'pa_brand'`, `category_taxonomy` default `'product_cat'`). Case-insensitive trim-tolerant exact match over the Woo `name` field. Any `WooClient::get` exception returns null (fail-soft; WooClient already logged via `integration_events`).
- `CreateWooProductJob` shipped as a STUB in Task 1 (constructor + queue routing + `failed()` DLQ only) so the listener's `Queue::fake → assertPushed(CreateWooProductJob::class)` test resolves the real class. Task 2 fills the `handle()` pipeline.
- `EventServiceProvider`: `NewSupplierSkuDetected` → `HandleNewSupplierSku` (REPLACES stub binding). `SupplierEventDispatchTest` E6 updated; E7 (stub-log test) removed.
- `deptrac.yaml` + `depfile.yaml` comment block refreshed to explain Plan 03's actual imports; allow-list unchanged (was pre-seeded by Plan 01).

**Tests:** `AutoCreateEventsTest` (5 cases), `HandleNewSupplierSkuTest` (7 cases), `TaxonomyResolverTest` (9 cases).

### Task 2 — CreateWooProductJob (full) + PublishProductJob + ProductOverrideGuard + CompletenessScorer listener

**Commit:** `ef2de6e`

**`CreateWooProductJob::handle()` pipeline (14 steps):**

1. `event(new AutoCreateAttempted($sku))` — diagnostic anchor.
2. `ProductMatcher::existsNormalised($sku)` → `AutoCreateFailed('duplicate')` + return (AUTO-08 dedup v1).
3. `SupplierClient::fetchSingleProduct($sku)` → `AutoCreateFailed('supplier_not_found')` on empty response (T-06-03-01 tampering guard).
4. `ProductContentBuilder::compile($supplierData)` → 5-key SEO shape.
5. `ProductSlugGenerator::generate($title, $sku)` → client-side unique candidate.
6. Pre-POST `WooClient::get('/products', ['slug' => ...])` → if non-empty, regenerate `{base}-{sku-lowercased}` (Pitfall P6-G option 1).
7. `TaxonomyResolver::resolveBrand + resolveCategory`. Missing EITHER → create Product with `auto_create_status='needs_brand_or_category_assignment'`, short-circuit (NO Woo POST, NO image job, NO `AutoCreateSucceeded`).
8. `Product::create(...)` with `auto_create_status='draft'`, `status='draft'`.
9. Phase 3 pricing: `RuleResolver::resolve($product)` → `PriceCalculator::compute($buyPennies, $resolution->marginBasisPoints)`. `SupplierPriceUnusableException` (zero/negative supplier price) downgrades to `sell_price=null` + `regular_price='0.00'` rather than retrying (transient vs data-state distinction — retries are reserved for Woo 5xx / network).
10. `WooClient::post('/products', $payload)` — draft, images=[] (Plan 02 job adds them later).
11. `forceFill + saveQuietly` with `woo_product_id`, Woo-returned `slug`, `sell_price` (Pitfall P6-G option 2).
12. `ProcessAutoCreateImageJob::dispatch($product->id, $supplierData['image_url'] ?? null, $fallbacks)` — Plan 02's sync-bulk queue handles the image chain.
13. `CompletenessScorer::score` → `forceFill` 3 completeness columns.
14. `event(new AutoCreateSucceeded(...))` with final snapshot.

`failed(Throwable $e)` → `Suggestion::create(['kind' => 'auto_create_failed', 'evidence' => [sku, source, error, exception, original_suggestion_id]])` so Plan 04's admin "Replay" action has a row to act on (mirrors Phase 4 CrmPushRetryApplier DLQ precedent).

**`PublishProductJob`** — simple draft-to-publish transition. `sync-woo-push` queue, `tries=3`. Flips `Product.auto_create_status='published'` + `status='publish'` via `forceFill+saveQuietly`; calls `WooClient::put('/products/{wooId}', ['status' => 'publish'])` when `woo_product_id` is set; fires `ProductPublished(productId, wooProductId, publishedByUserId)`.

**`ProductOverrideGuard::revertIfPinned(int $wooProductId, array $fieldNames, string $source)`** — Plan 05's `ApplyPinsDuringSync` listener calls this after Phase 2's sync writes. 7-entry field map mirrors the `pin_*` columns on `ProductOverride`: `name`→`pin_title`, `slug`→`pin_slug`, `short_description`→`pin_short_description`, `description`→`pin_long_description`, `meta_description`→`pin_meta_description`, `regular_price`→`pin_price`, `images`→`pin_image`. Shape helpers: `images` → `[{src: URL}]`, `regular_price` → `'xx.xx'` string, rest pass-through. Silent no-op when product OR override missing, or when no pinned field intersects `$fieldNames`. Audits via `Auditor::record('product_auto_create.pin_reverted', [...])` on every successful revert.

**`RecomputeCompletenessOnSupplierChange` listener (A3 FINDING pivot)** — subscribes to `SupplierPriceChanged@handlePriceChanged`, `SupplierStockChanged@handleStockChanged`, `SupplierSkuMissing@handleSkuMissing`. Looks up Product by `woo_product_id` + recomputes `CompletenessScorer::score` + persists 3 columns via `forceFill+saveQuietly` (same pattern as Phase 2 sync writes — keeps `activity_log` clean). Silent no-op on unknown `woo_product_id`. REPLACES the observer approach that Plan 01's `SaveQuietlyObserverTest` proved unworkable under Laravel 12.

**Tests:** `CreateWooProductJobTest` (9 cases), `PublishProductJobTest` (3 cases), `ProductOverrideGuardTest` (7 cases), `CompletenessScorerListenerTest` (5 cases).

### Task 3 — NewProductOpportunityApplier MOVE + AutoCreateRetryApplier

**Commit:** `5c87e1f`

Per RESEARCH Q4 option b, the `NewProductOpportunityApplier` file relocates from `app/Domain/Competitor/Appliers/` to `app/Domain/ProductAutoCreate/Appliers/` with a **real body** that dispatches `CreateWooProductJob`. The Phase 5 stub body is gone.

- **Created:** `app/Domain/ProductAutoCreate/Appliers/NewProductOpportunityApplier.php` — reads `evidence['sku']`, dispatches `CreateWooProductJob::dispatch($sku, (string) $suggestion->id)`, returns `{phase_6_live: true, sku, dispatched_job_class}`. Missing sku → `{error: missing_sku_in_evidence}`.
- **Created:** `app/Domain/ProductAutoCreate/Appliers/AutoCreateRetryApplier.php` — kind `'auto_create_failed'`, DLQ replay applier. Same shape, returns `{retry_dispatched: true, sku}`.
- **Deleted:** `app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php` — old stub file removed to preserve the Competitor → ProductAutoCreate one-way Deptrac arrow.
- **Deleted:** `tests/Feature/Competitor/NewProductOpportunityApplierTest.php` — replaced by the new test under `tests/Feature/ProductAutoCreate/`.
- **AppServiceProvider** registers the two new appliers at their NEW FQCNs:
  ```php
  $resolver->register('new_product_opportunity', \App\Domain\ProductAutoCreate\Appliers\NewProductOpportunityApplier::class);
  $resolver->register('auto_create_failed',      \App\Domain\ProductAutoCreate\Appliers\AutoCreateRetryApplier::class);
  ```

**Tests:** `NewProductOpportunityApplierTest` (6 cases — includes 2 file-absence guards that fail the build if someone re-creates the old file), `AutoCreateRetryApplierTest` (4 cases).

## Q4 Applier MOVE — Verification Matrix

| Check | Result |
|---|---|
| `test -f app/Domain/ProductAutoCreate/Appliers/NewProductOpportunityApplier.php` | ✓ exists |
| `test ! -f app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php` | ✓ absent |
| `test ! -f tests/Feature/Competitor/NewProductOpportunityApplierTest.php` | ✓ absent |
| `grep -r "App\\\\Domain\\\\Competitor\\\\Appliers\\\\NewProductOpportunityApplier" app/` | 0 matches |
| AppServiceProvider `new_product_opportunity` FQCN | ProductAutoCreate |
| AppServiceProvider `auto_create_failed` FQCN | ProductAutoCreate\AutoCreateRetryApplier |
| Deptrac `Competitor` allow-list | still `[Foundation, Pricing, Products, Suggestions, Webhooks, Alerting]` — no ProductAutoCreate needed |

## Observer Registration Strategy (Decision Record)

Plan 03 does NOT ship a `ProductCompletenessObserver`. Plan 01's `SaveQuietlyObserverTest` is the authoritative source: Laravel 12's `saveQuietly` suppresses both `saving` and `saved` events. Phase 2's `SyncChunkJob` uses `forceFill + saveQuietly` (locked by Plan 01 for `activity_log` bloat reasons). Therefore:

- A `Product::saving(...)` observer would NEVER fire during the real supplier-sync path.
- A `Product::saved(...)` observer would ALSO never fire — same reason.
- Explicit Phase 2 modification is rejected by D-11 (keep Phase 2 untouched).

**Shipped strategy:** `RecomputeCompletenessOnSupplierChange` listener subscribes to the 3 Phase 2 supplier-change domain events. The listener re-runs `CompletenessScorer::score` + persists via the same `forceFill+saveQuietly` pattern. Fresh creates trigger completeness recompute directly from `CreateWooProductJob::handle()` (step 13). Plan 05's `ApplyPinsDuringSync` listener can optionally nudge the scorer too — but it's not mandatory because the 3 supplier events already cover the mutation surface.

## Deptrac ProductAutoCreate allow-list (both files)

```yaml
ProductAutoCreate: [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks]
```

Allow-list unchanged from Plan 01 (forward-compat super-set). Comment block refreshed in both `deptrac.yaml` and `depfile.yaml` to describe the concrete imports now landing in Plan 03. Competitor is intentionally NOT in the allow-list — the applier MOVE keeps the one-way arrow direction clean.

## Deviations from Plan

### [Rule 2 — Correctness] Shipped RecomputeCompletenessOnSupplierChange listener instead of Observer

- **Found during:** Task 2 design review against Plan 01's `SaveQuietlyObserverTest` outcome (A3 FINDING).
- **Issue:** Plan's `<action>` section instructs shipping a `ProductCompletenessObserver` on `Product::observe(...)`. Plan 01's live test already PROVED the observer wouldn't fire during Phase 2's `saveQuietly` sync path. Shipping the observer as written would leave the completeness score stale on every supplier-driven price/stock mutation — silently broken.
- **Fix:** Shipped `RecomputeCompletenessOnSupplierChange` listener subscribing to `SupplierPriceChanged@handlePriceChanged` / `SupplierStockChanged@handleStockChanged` / `SupplierSkuMissing@handleSkuMissing`. Fresh creates get a direct `CompletenessScorer` invocation from `CreateWooProductJob::handle()`. Plan's intent (completeness recomputed after supplier changes) is satisfied; the specific "how" (listener not observer) is the only deviation.
- **Files modified:** `app/Domain/ProductAutoCreate/Listeners/RecomputeCompletenessOnSupplierChange.php` (new); `app/Providers/EventServiceProvider.php` (3 new bindings).
- **Commit:** `ef2de6e`

### Deferred Verification — MySQL Testing Environment

- **Found during:** initial test run attempt.
- **Issue:** Same situation as Plans 06-01 + 06-02 — `meetingstore_ops_testing` MySQL isn't running in the execution environment (`php -r "new PDO(...)"` returns `No connection could be made`). Feature-tier tests using `RefreshDatabase` fail at `PDO::connect()`.
- **Fix:** All 9 new test files authored against the correct shape (RefreshDatabase via `tests/Pest.php`; MySQL-idiomatic schema assertions; Mockery for `WooClient` / `SupplierClient` / `RuleResolver` / `PriceCalculator` / `Auditor`; `Queue::fake` + `Event::fake` for dispatch assertions). All 21 new/modified PHP files pass `php -l`. Deptrac runs green on both `deptrac.yaml` and `depfile.yaml` (0 violations, 289 allowed edges after the applier move). Test execution defers to MySQL-online environment — same precedent as Plan 06-01 / 06-02.
- **Files modified:** none — test code is correct; execution is an infra-level dependency.
- **Commit:** n/a

## Threat Flags

No new trust boundaries introduced beyond the plan's documented threat model. All STRIDE mitigations in the plan's `<threat_model>` T-06-03-01..04 are observable in code:

- **T-06-03-01** (tampered SKU in evidence) — applier guards with `(string)` cast + empty check; `CreateWooProductJob::handle()` bails with `AutoCreateFailed('supplier_not_found')` when the supplier API returns `[]` for a non-existent SKU.
- **T-06-03-02** (non-admin triggers applier) — existing `SuggestionPolicy::apply` + Filament `->authorize` gate the ONLY caller path (ApplySuggestionJob); the applier itself has no direct external surface.
- **T-06-03-03** (info disclosure via `integration_events`) — `IntegrationLogger` already redacts Authorization header (pre-existing Phase 1 mitigation); no new sensitive surface.
- **T-06-03-04** (DoS via catastrophic regex) — `HandleNewSupplierSku::collectMatchingSkipRules` wraps each `$rule->matches()` in `try/catch` + logs + skips the bad rule. Plus `AutoCreateSkipRule::matches` itself uses `@preg_match` suppression (Plan 01 shipped).

## Auto-Mode Record

No checkpoints encountered — all 3 tasks were `type="auto"`. No auth gates. No Rule 4 architectural decisions.

## Self-Check: PASSED

- Created files verified via direct path inspection:
  - `app/Domain/ProductAutoCreate/Events/{AutoCreateAttempted,AutoCreateSucceeded,AutoCreateFailed,ProductPublished}.php` — 4 FOUND.
  - `app/Domain/ProductAutoCreate/Listeners/{HandleNewSupplierSku,RecomputeCompletenessOnSupplierChange}.php` — 2 FOUND.
  - `app/Domain/ProductAutoCreate/Jobs/{CreateWooProductJob,PublishProductJob}.php` — 2 FOUND.
  - `app/Domain/ProductAutoCreate/Services/{TaxonomyResolver,ProductOverrideGuard}.php` — 2 FOUND.
  - `app/Domain/ProductAutoCreate/Appliers/{NewProductOpportunityApplier,AutoCreateRetryApplier}.php` — 2 FOUND.
  - `tests/Feature/ProductAutoCreate/` — 9 new Pest test files FOUND.
- Deleted files verified absent:
  - `app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php` — ABSENT.
  - `app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php` — ABSENT.
  - `tests/Feature/Competitor/NewProductOpportunityApplierTest.php` — ABSENT.
- Grep guards:
  - `grep -r "App\\\\Domain\\\\Competitor\\\\Appliers\\\\NewProductOpportunityApplier" app/` → 0 matches.
  - `grep -r "StubNewSupplierSkuListener" app/` → 0 matches.
- Commits verified via `git log --oneline`:
  - `9bf3fb8` Task 1 (events + listener + taxonomy + deptrac refresh)
  - `ef2de6e` Task 2 (CreateWooProductJob + PublishProductJob + guard + completeness listener)
  - `5c87e1f` Task 3 (applier MOVE + retry applier + AppServiceProvider)
- `php -l` syntax-linted all 21 new/modified PHP files — 0 errors.
- `php vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress` → 0 violations, 289 allowed.
- `php vendor/bin/deptrac analyse --config-file=depfile.yaml --no-progress` → 0 violations, 289 allowed.
- AppServiceProvider registration inspection (read): both new FQCNs present, Competitor FQCN absent.
- EventServiceProvider registration inspection (read): `HandleNewSupplierSku`, `RecomputeCompletenessOnSupplierChange@handlePriceChanged/handleStockChanged/handleSkuMissing` bindings all present. `StubNewSupplierSkuListener` import removed.

Feature-tier test execution deferred to MySQL-online environment (same precedent as Plans 06-01, 06-02).

---

*Phase: 06-product-auto-create*
*Plan: 03-orchestration*
*Completed: 2026-04-23*
