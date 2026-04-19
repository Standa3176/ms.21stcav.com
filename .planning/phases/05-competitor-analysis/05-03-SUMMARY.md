---
phase: 05-competitor-analysis
plan: 03
subsystem: competitor
tags: [margin-analyser, suggestion-applier, observer, debounce, event-chain, comp-08, comp-09, d-05, d-06, d-07, p5-e, w1-semantics, a1-gate, a3-fallback]

requires:
  - phase: 01-foundation
    provides: "DomainEvent (ShouldDispatchAfterCommit, correlation_id auto-fill); SuggestionApplier contract + SuggestionApplierResolver singleton; Auditor::record meta-audit; BaseCommand correlation-id wrapper"
  - phase: 02-supplier-sync
    provides: "PHP 8.4 trait-collision avoidance pattern (constructor-based ->onQueue() instead of public string \\$queue) — applied to RecacheSalesCountsJob"
  - phase: 03-pricing-engine
    provides: "PriceCalculator::stripVat (COMP-06 reuse — MarginAnalyser delegates verbatim); PricingRule model + factory; RuleResolver::resolve(Product) returning PricingResolution DTO; sell_price/buy_price decimal:4 column convention"
  - phase: 04-bitrix24-crm-sync
    provides: "CrmPushRetryApplier registration pattern in AppServiceProvider — MarginChangeApplier registered with identical shape (afterResolving + ->register())"
  - plan: 05-01
    provides: "config/competitor.php (margin_delta_threshold_bps=800, min_margin_floor_bps=500, consecutive_scrapes_required=3, sales_threshold_90d=10, beat_by_pennies=1); products.last_sales_count_90d/last_sales_count_computed_at columns; Competitor + CompetitorPrice + CompetitorIngestRun factories with state helpers"
  - plan: 05-02
    provides: "CompetitorPriceRecorded event (5-field shape FROZEN); NewProductOpportunityApplier registration pattern in AppServiceProvider; deptrac.yaml Competitor layer allow-list (extended in this plan with Webhooks)"

provides:
  - "App\\Domain\\Pricing\\Events\\PricingRuleChanged (A1 gate ship — back-ports the event Phase 3 didn't surface): {ruleId, oldMarginBps, newMarginBps} + DomainEvent base"
  - "App\\Domain\\Pricing\\Observers\\PricingRuleObserver — fires PricingRuleChanged ONLY when margin_basis_points is dirty after save; double-guard via wasChanged + old!==new (cast normalisation safety)"
  - "PricingRule model: #[ObservedBy(PricingRuleObserver::class)] attribute (Laravel 11+ registration style)"
  - "App\\Domain\\Competitor\\Services\\SalesCounterService — getCount(sku) reads products.last_sales_count_90d (null-coalesced); meetsThreshold(sku) gates on config('competitor.sales_threshold_90d',10)"
  - "App\\Domain\\Competitor\\Listeners\\IncrementSkuSalesCount — queued listener on OrderReceived; loads WebhookReceipt; walks raw_body.line_items; W1 semantics (1 per line-item, NOT multiplied by quantity); skips null/empty SKU; silent no-op on missing receipt"
  - "App\\Domain\\Competitor\\Services\\MarginProposal — final readonly DTO {proposedMarginBasisPoints, competitorExVatPennies, supplierExVatPennies, beatByPennies}"
  - "App\\Domain\\Competitor\\Services\\MarginAnalyser::computeProposal — reverse-margin via PriceCalculator::stripVat (COMP-06); intdiv() integer math; null on supplier <=0; null + Log::warning('suggestion_suppressed_low_margin') below min_margin_floor_bps (Pitfall P5-E)"
  - "App\\Domain\\Competitor\\Listeners\\DispatchMarginAnalyserJob — debounced via Cache::add('competitor.analyser.debounce.{cid}.{sku}.{YYYY-MM-DD}', true, 24h); dispatches ComputeMarginSuggestionJob on default queue; silent no-op on already-debounced"
  - "App\\Domain\\Competitor\\Jobs\\ComputeMarginSuggestionJob — 6-gate pipeline (Product exists → ≥3 scrapes → sales threshold → direction consistency → MarginAnalyser non-null → delta threshold); D-07 evidence JSON shape; Suggestion(kind=margin_change) creator; fires MarginSuggestionCreated"
  - "App\\Domain\\Competitor\\Events\\MarginSuggestionCreated — final DomainEvent {suggestionId, competitorId, sku, proposedMarginBps}"
  - "App\\Domain\\Competitor\\Appliers\\MarginChangeApplier — supports=['margin_change']; apply() = PricingRule::findOrFail + update + Auditor::record + return result array; idempotent via observer wasChanged guard; THIRD real producer on the Suggestions seam"
  - "App\\Domain\\Competitor\\Jobs\\RecacheSalesCountsJob — A3 fallback STUB (WooClient lacks /orders); logs recache.wooclient_orders_missing + returns; constructor->onQueue('sync-bulk') (PHP 8.4 trait-collision pattern); TODO-A3-FOLLOWUP carries the real implementation contract"
  - "App\\Domain\\Competitor\\Console\\Commands\\CompetitorSalesRecacheCommand (signature 'competitor:sales-recache') — extends BaseCommand; chunks Product by 100 SKUs; dispatches one RecacheSalesCountsJob per chunk"
  - "EventServiceProvider \\$listen extended: OrderReceived gains IncrementSkuSalesCount; CompetitorPriceRecorded gains DispatchMarginAnalyserJob"
  - "AppServiceProvider extended: SuggestionApplierResolver registers margin_change kind against MarginChangeApplier (THIRD real producer); commands() includes CompetitorSalesRecacheCommand"
  - "routes/console.php: Schedule::command('competitor:sales-recache')->dailyAt('02:00')->withoutOverlapping(30)->onOneServer()->timezone('Europe/London')"
  - "deptrac.yaml + depfile.yaml: Competitor layer allow-list extended to [Foundation, Pricing, Products, Suggestions, Webhooks] — Webhooks added because IncrementSkuSalesCount imports OrderReceived event + WebhookReceipt model"
  - "10 Pest test files (49 new tests, 152 new assertions): PricingRuleChangedEventTest (5), SalesCounterServiceTest (6), IncrementSkuSalesCountListenerTest (8), MarginAnalyserTest (6), MinMarginFloorGuardTest (2), DebounceKeyTest (4), DispatchMarginAnalyserJobTest (2), ComputeMarginSuggestionJobTest (7), MarginChangeApplierTest (7), RecacheSalesCountsJobTest (3), CompetitorSalesRecacheCommandTest (5)"

affects:
  - "05-04a-filament-resources-and-rbac (SuggestionResource needs to render margin_change kind with D-07 evidence; the FROZEN evidence JSON shape below is the contract; Approve action triggers ApplySuggestionJob which resolves MarginChangeApplier)"
  - "05-04b-filament-pages-stale-feed (CompetitorAnalysisPage 'biggest deltas' tab can read margin_change suggestions ordered by abs(margin_delta_bps) from evidence JSON)"
  - "05-05-retention-guardrails-verification (RecacheSalesCountsJob's TODO-A3-FOLLOWUP becomes Phase 7+ polish item; the 02:00 schedule entry is already in place so post-WooClient extension activates real recache with zero plumbing change)"
  - "Phase 3 Plan 02 RecomputePriceListener (currently subscribes to SupplierPriceChanged only): could be EXTENDED post-Phase-5 to also subscribe to PricingRuleChanged so margin updates trigger catalogue-wide recompute. NOT in scope for Phase 5 — flagged as Phase 7 polish concern."
  - "Phase 6 PriceRecomputerJob bulk path: when admin approves a margin_change Suggestion, the current applier ONLY updates the rule and emits the event. Catalogue-wide recompute against the new margin requires either (a) extending RecomputePriceListener to subscribe to PricingRuleChanged, or (b) the admin manually running pricing:recompute. Phase 6 should make this automatic."

tech-stack:
  added:
    - "None — 100% reuse. PHP 8.4 #[ObservedBy] attribute (existing Laravel 11+ feature, first use in this codebase). Cache::add atomic debounce (existing Phase 1 D-13 ThrottledFailedJobNotifier precedent extended). Spatie/activitylog assertion against system log (existing Phase 1 Auditor pattern)."
  patterns:
    - "Observer-fires-event chain: MarginChangeApplier deliberately does NOT dispatch PricingRuleChanged itself. It updates PricingRule via Eloquent → PricingRuleObserver fires the event. Single source of truth for 'margin_basis_points changed' semantics; future listeners on PricingRuleChanged don't need to know about this applier."
    - "PHP 8.4 trait-collision avoidance (Plan 05-02 Deviation #1 precedent): RecacheSalesCountsJob declares NO public string \\$queue property; constructor calls \\$this->onQueue('sync-bulk') instead. Test asserts \\$job->queue === 'sync-bulk' which works because Queueable trait exposes the property at runtime."
    - "Direct ->handle() invocation in tests instead of dispatchSync(): when a test job handle throws internally, dispatchSync() routes through the queue worker → spatie/laravel-failed-job-monitor's notify() (which uses an undefined Application::notify macro) — masks the real error behind BadMethodCallException. Direct handle() with manually-resolved dependencies surfaces the real failure cleanly."
    - "Audit-via-activitylog assertion (final-class workaround): Auditor is final → Mockery cannot mock it → tests assert against \\Spatie\\Activitylog\\Models\\Activity rows in the 'system' log. Stronger integration-level coverage than mocking the wrapper."
    - "RuleResolver returns DTO not model: PricingResolution carries matchedRuleId (nullable when source='override'). MarginChangeApplier consumes payload.pricing_rule_id directly (since the Suggestion was already created with that field); ComputeMarginSuggestionJob walks resolution.matchedRuleId then PricingRule::find() to read current margin_basis_points."

key-files:
  created:
    - "app/Domain/Pricing/Events/PricingRuleChanged.php"
    - "app/Domain/Pricing/Observers/PricingRuleObserver.php"
    - "app/Domain/Competitor/Events/MarginSuggestionCreated.php"
    - "app/Domain/Competitor/Services/SalesCounterService.php"
    - "app/Domain/Competitor/Services/MarginAnalyser.php"
    - "app/Domain/Competitor/Services/MarginProposal.php"
    - "app/Domain/Competitor/Listeners/IncrementSkuSalesCount.php"
    - "app/Domain/Competitor/Listeners/DispatchMarginAnalyserJob.php"
    - "app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php"
    - "app/Domain/Competitor/Jobs/RecacheSalesCountsJob.php"
    - "app/Domain/Competitor/Appliers/MarginChangeApplier.php"
    - "app/Domain/Competitor/Console/Commands/CompetitorSalesRecacheCommand.php"
    - "tests/Feature/Pricing/PricingRuleChangedEventTest.php"
    - "tests/Feature/Competitor/SalesCounterServiceTest.php"
    - "tests/Feature/Competitor/IncrementSkuSalesCountListenerTest.php"
    - "tests/Feature/Competitor/MarginAnalyserTest.php"
    - "tests/Feature/Competitor/MinMarginFloorGuardTest.php"
    - "tests/Feature/Competitor/DebounceKeyTest.php"
    - "tests/Feature/Competitor/DispatchMarginAnalyserJobTest.php"
    - "tests/Feature/Competitor/ComputeMarginSuggestionJobTest.php"
    - "tests/Feature/Competitor/MarginChangeApplierTest.php"
    - "tests/Feature/Competitor/RecacheSalesCountsJobTest.php"
    - "tests/Feature/Competitor/CompetitorSalesRecacheCommandTest.php"
  modified:
    - "app/Domain/Pricing/Models/PricingRule.php — added #[ObservedBy(PricingRuleObserver::class)] attribute"
    - "app/Providers/EventServiceProvider.php — extended OrderReceived listener array with IncrementSkuSalesCount; added CompetitorPriceRecorded => [DispatchMarginAnalyserJob]"
    - "app/Providers/AppServiceProvider.php — registered margin_change kind against MarginChangeApplier (3rd real producer); appended CompetitorSalesRecacheCommand to commands()"
    - "routes/console.php — Schedule::command('competitor:sales-recache')->dailyAt('02:00')"
    - "deptrac.yaml — Competitor layer allow-list gained Webhooks ([Foundation, Pricing, Products, Suggestions, Webhooks])"
    - "depfile.yaml — kept in sync with deptrac.yaml (legacy file)"

key-decisions:
  - "A1 gate confirmed missing → shipped this plan. PricingRuleChanged event + PricingRuleObserver + #[ObservedBy] wire-up are the FIRST-class mechanisms by which margin_change suggestion approval triggers downstream re-recompute. If Phase 7 wants a full recompute on margin change, RecomputePriceListener (currently SupplierPriceChanged-only) can be extended to subscribe to PricingRuleChanged in a single line — flagged as Phase 7 polish."
  - "A3 gate confirmed WooClient lacks /orders → RecacheSalesCountsJob ships as A3 FALLBACK STUB. The job logs recache.wooclient_orders_missing and exits; the schedule entry + chunked dispatch infrastructure ship anyway so post-WooClient-extension work is a 1-class body change. The W1 semantics contract (1 per line-item, NOT multiplied by quantity) is documented in BOTH the listener (Task 1) and the stub job docblocks so the future implementation cannot drift."
  - "Observer registration via #[ObservedBy(PricingRuleObserver::class)] attribute (Laravel 11+) — chosen over Model::observe() in AppServiceProvider::boot to keep cross-cutting concerns at the model definition site. First use of #[ObservedBy] in this codebase; future Phase 6+ planners can follow the same pattern."
  - "MarginChangeApplier fires PricingRuleChanged via observer chain (NOT direct event() call). Single source of truth for 'margin_basis_points changed' semantics. If a future planner adds a different code path that mutates margin_basis_points, the observer ensures the event STILL fires."
  - "Auditor assertion via spatie\\Activitylog\\Models\\Activity row inspection rather than Mockery mock — Auditor::class is final; mocking final classes triggers Mockery exception. Asserting on the activity_log row is a stronger integration-level test anyway."
  - "ComputeMarginSuggestionJob test uses direct ->handle() invocation instead of ::dispatchSync — dispatchSync routes throws through the failed-job monitor whose notify() macro is undefined in this app, masking real errors behind BadMethodCallException. Direct invocation surfaces real failures cleanly. Same pattern recommended for any future Job test that exercises failure paths."
  - "RuleResolver returns PricingResolution DTO (not Model) — ComputeMarginSuggestionJob calls resolver->resolve($product), then if matchedRuleId is set does PricingRule::find($matchedRuleId) to read current margin_basis_points + scope. matchedRuleId is null when source='override' — that case is intentionally skipped in this plan (D-08 scope, deferred to a future plan that adds margin_change semantics for ProductOverride)."
  - "Direction-consistency gate uses unique()->count() === 1 on the boolean comparison row->price < ourSell. Compact + correct: all-true and all-false both pass; mixed array unique() returns 2 distinct values which fails the gate. Avoids fragile loop-with-flag bookkeeping."
  - "Cache::add atomic semantics chosen over DB unique-index for debounce (research §8 alternative considered) — Cache::add is faster, aligned with Phase 1 D-13 ThrottledFailedJobNotifier pattern; DB-unique path would fight QueryException 1062 noise on every CSV row drop."
  - "Deptrac Competitor layer extended to include Webhooks (was [Foundation, Pricing, Products, Suggestions]; now [...+Webhooks]). IncrementSkuSalesCount imports OrderReceived event class + WebhookReceipt model — both live in app/Domain/Webhooks. Plan 05-05 will add Alerting (3rd extension)."

requirements-completed:
  - COMP-08
  - COMP-09

duration: ~41 min
completed: 2026-04-19
---

# Phase 05 Plan 03: Margin Analyser + Suggestion Producers Summary

**The analytical core: PricingRuleChanged event back-ported (A1 gate); MarginAnalyser reverse-margin calculator with COMP-06 stripVat reuse + Pitfall P5-E min-margin-floor guard; debounced (Cache::add atomic) DispatchMarginAnalyserJob → ComputeMarginSuggestionJob 6-gate pipeline producing margin_change Suggestions with D-07 evidence JSON; MarginChangeApplier as the THIRD real producer on the Suggestions seam (after CrmPushRetryApplier + NewProductOpportunityApplier stub) — fires PricingRuleChanged via the observer chain, not direct dispatch; SalesCounterService + IncrementSkuSalesCount listener (W1 semantics: 1 increment per line-item, NOT multiplied by quantity) + CompetitorSalesRecacheCommand on 02:00 schedule. A3 gate confirmed WooClient lacks /orders; RecacheSalesCountsJob ships as fallback stub with TODO-A3-FOLLOWUP marker. 49 new Pest tests green; full project suite 726/0/2-skipped (5765 assertions); 0 Deptrac violations.**

## Performance

- **Duration:** ~41 min (3 tasks, all tdd="true")
- **Started:** 2026-04-19T20:34:42Z
- **Completed:** 2026-04-19T21:15:25Z
- **Tasks:** 3
- **Commits:** 6 (3× RED, 3× GREEN) + 1 final metadata commit
- **Files created:** 22 (12 production + 10 tests)
- **Files modified:** 6 (PricingRule + EventServiceProvider + AppServiceProvider + routes/console + deptrac.yaml + depfile.yaml)

## A1 Gate Outcome (PricingRuleChanged event)

**Verification command:**
```bash
grep -r "class PricingRuleChanged" app/Domain/Pricing/Events/
```
**Result at plan start:** EMPTY — only `ProductPriceChanged.php` existed in `app/Domain/Pricing/Events/`. Confirmed Phase 3 did NOT back-port PricingRuleChanged.

**Action taken:** Shipped the event class + observer + #[ObservedBy] wire-up as Task 1 per plan instruction. Post-task verification:
```bash
$ grep -r "class PricingRuleChanged" app/Domain/Pricing/Events/
app/Domain/Pricing/Events/PricingRuleChanged.php:final class PricingRuleChanged extends DomainEvent
```

**Future-phase note:** If a future plan extends Phase 3's RecomputePriceListener to subscribe to PricingRuleChanged (so margin updates trigger catalogue-wide recompute automatically), the event class is now in place — the listener subscription is a single line in EventServiceProvider's $listen array. Currently the only consumer is the implicit chain triggered by MarginChangeApplier's update.

## A3 Gate Outcome (WooClient /orders endpoint)

**Verification command:**
```bash
grep -rn "getOrders\|/orders" app/Domain/Sync/Services/WooClient.php
```
**Result at plan start:** WOOCLIENT_ORDERS_MISSING (zero hits).

**Action taken:** Plan-prescribed fallback path activated:
- `RecacheSalesCountsJob` ships as a NO-OP stub that logs `recache.wooclient_orders_missing` and returns (does NOT mutate `products.last_sales_count_90d`).
- `CompetitorSalesRecacheCommand` + 02:00 schedule entry STILL ship — so future WooClient extension activates real recache with zero plumbing change.
- The real-time `IncrementSkuSalesCount` listener (Task 1) remains the SOLE source of truth for `last_sales_count_90d` until WooClient gains a `getOrders()` method.

**TODO-A3-FOLLOWUP (post-Phase-5):** Extend `WooClient` with a `getOrders(array $params): array` method, then replace `RecacheSalesCountsJob::handle()` body with the aggregation plan documented in the job's docblock (paginate /orders since 90d ago + aggregate SKU counts using identical W1 semantics + UPDATE products.last_sales_count_90d). The `W1 semantics` contract (1 increment per line-item; NOT multiplied by quantity) is documented in BOTH the real-time listener AND the stub so the future implementation cannot drift.

## Observer Registration Style

Chose `#[ObservedBy(PricingRuleObserver::class)]` PHP 8 attribute on PricingRule directly, NOT the legacy `Model::observe(PricingRuleObserver::class)` call in `AppServiceProvider::boot`. Rationale:

1. Cross-cutting concern lives at the model definition site (no need to scan the provider to see what observers are wired).
2. Laravel 11+ idiomatic — first use of `#[ObservedBy]` in this codebase establishes the pattern for Phase 6+ planners.
3. AppServiceProvider::boot() is already heavy with policy + applier registrations — keeping observers off it preserves readability.

## D-07 Evidence JSON Shape (FROZEN — Plan 05-04a will consume verbatim)

Every `Suggestion(kind='margin_change')` row carries this evidence shape:

```json
{
  "competitor_id": 5,
  "competitor_name": "Rice and Sons",
  "sku": "POP-SKU",
  "last_3_competitor_prices": [
    {"price_ex_vat_pennies": 7499, "recorded_at": "2026-04-18T20:50:50+00:00"},
    {"price_ex_vat_pennies": 7499, "recorded_at": "2026-04-17T20:50:50+00:00"},
    {"price_ex_vat_pennies": 7499, "recorded_at": "2026-04-16T20:50:50+00:00"}
  ],
  "our_sell_price_pennies": 10000,
  "our_supplier_price_pennies": 4000,
  "our_current_margin_bps": 5000,
  "proposed_margin_bps": 8745,
  "margin_delta_bps": 3745,
  "sales_count_90d": 15,
  "pricing_rule": {
    "id": 5,
    "scope": "default_tier",
    "current_margin_bps": 5000,
    "resolution_source": "default_tier"
  },
  "beat_by_pennies": 1
}
```

Payload is the minimum required for the applier:
```json
{"pricing_rule_id": 5, "new_margin_basis_points": 8745}
```

## Sales Counter Semantics (W1 FROZEN)

`products.last_sales_count_90d` is incremented by the `IncrementSkuSalesCount` listener using **W1 semantics**: ONE increment per line-item, NOT multiplied by quantity.

| Scenario | Increment |
|----------|-----------|
| `{sku: A, quantity: 3}` (one line) | A += 1 |
| `{sku: A, quantity: 1}, {sku: A, quantity: 7}` (two lines) | A += 2 |
| `{sku: null}` or missing sku | skip |
| Order arrives without raw_body.line_items | no-op |
| WebhookReceipt missing | silent no-op (no throw) |

**Drift prevention:** When `RecacheSalesCountsJob` is activated post-A3-followup, it MUST use the identical aggregation semantics so the nightly authoritative recompute matches the real-time listener exactly. The contract is documented verbatim in BOTH file headers.

## MarginAnalyser Algorithm

```
beat_by_pennies   = config('competitor.beat_by_pennies', 1)
min_floor_bps     = config('competitor.min_margin_floor_bps', 500)

competitor_ex_vat = PriceCalculator::stripVat(gross, 2000)        ← COMP-06
target_sell_ex_vat = competitor_ex_vat - beat_by_pennies
margin_bps         = intdiv((target_sell_ex_vat - supplier) * 10000, supplier)

Guards:
  supplier <= 0       → null
  margin_bps < floor  → null + Log::warning('suggestion_suppressed_low_margin')   ← Pitfall P5-E

Worked example (gross=8999, supplier=4000, beat=1):
  stripVat(8999, 2000) = round(8999 * 10000 / 12000) = round(7499.166...) = 7499 (HALF_UP)
  target_sell = 7499 - 1 = 7498
  margin_bps  = intdiv((7498 - 4000) * 10000, 4000) = intdiv(34_980_000, 4000) = 8745
```

All arithmetic is integer pennies → `intdiv` for the basis-points conversion. No floats. PriceCalculator::stripVat is the single source of VAT math (COMP-06 enforced by StripVatReuseTest grep harness).

## Threshold Pipeline (ComputeMarginSuggestionJob)

```
Gate 1 — Product exists (case-sensitive SKU lookup)
       ↓
Gate 2 — ≥ config('competitor.consecutive_scrapes_required', 3) CompetitorPrice rows
       ↓
Gate 3 — SalesCounterService::meetsThreshold (>= config sales_threshold_90d)
       ↓
Gate 4 — Direction consistency (all N rows above OR all below our sell — no flips)
       ↓
Gate 5 — MarginAnalyser::computeProposal returns non-null (min-floor + zero-supplier guards)
       ↓
Gate 6 — abs(current_margin_bps - proposed_margin_bps) >= config margin_delta_threshold_bps
       ↓
Suggestion::create(kind='margin_change', payload, evidence) + event(MarginSuggestionCreated)
```

Each gate short-circuits on miss — no Suggestion created, no event fired.

## Debounce Strategy

```
key = sprintf('competitor.analyser.debounce.%d.%s.%s', competitor_id, sku, today)
ttl = 24 hours

if (! Cache::add(key, true, ttl)) → return silently  (already debounced today)
else                              → ComputeMarginSuggestionJob::dispatch()->onQueue('default')
```

Result: at most ONE `ComputeMarginSuggestionJob` per `(competitor, sku, day)` regardless of how many CSVs n8n drops. T-05-03-05 mitigation: 5 competitors × 2000 SKUs × 1/day = 10k jobs/day max — well within Horizon `default` capacity.

## End-to-End Chain (FROZEN for Plan 05-04+ consumption)

```
n8n drops CSV → CompetitorWatchCommand (Plan 05-02) → IngestCompetitorCsvJob (Bus::batch chunks)
              → CompetitorCsvRowWriter persists CompetitorPrice + fires CompetitorPriceRecorded
              → DispatchMarginAnalyserJob (THIS PLAN, debounced via Cache::add)
              → ComputeMarginSuggestionJob (THIS PLAN, 6-gate pipeline)
              → Suggestion(kind='margin_change') created with D-07 evidence
              → MarginSuggestionCreated event (Phase 7 dashboard hook)
              → Admin reviews in Filament inbox (Plan 05-04a)
              → Admin Approves → ApplySuggestionJob (Phase 1 D-17)
              → SuggestionApplierResolver → MarginChangeApplier (THIS PLAN)
              → PricingRule::update(['margin_basis_points' => N])
              → PricingRuleObserver fires PricingRuleChanged (THIS PLAN, A1 ship)
              → Auditor::record('competitor.margin_change_applied') in audit_log
              → (FUTURE) RecomputePriceListener extension subscribes to PricingRuleChanged
                 → catalogue-wide recompute (currently a Phase 7 polish item)
```

## Task Commits

1. **Task 1 RED:** `766e95d test(05-03): add failing tests for PricingRuleChanged event + SalesCounterService + IncrementSkuSalesCount listener (RED)` — 3 test files, 19 tests
2. **Task 1 GREEN:** `0c12377 feat(05-03): PricingRuleChanged event + observer + SalesCounterService + IncrementSkuSalesCount listener (GREEN)` — event + observer + service + listener + EventServiceProvider wiring + Deptrac extension (Webhooks)
3. **Task 2 RED:** `4060079 test(05-03): add failing tests for MarginAnalyser + debounce + ComputeMarginSuggestionJob (RED)` — 5 test files, 21 tests
4. **Task 2 GREEN:** `b53cdec feat(05-03): MarginAnalyser + ComputeMarginSuggestionJob + DispatchMarginAnalyserJob listener + debounce (GREEN)` — MarginProposal + MarginAnalyser + MarginSuggestionCreated + DispatchMarginAnalyserJob + ComputeMarginSuggestionJob + EventServiceProvider wiring
5. **Task 3 RED:** `f76bd46 test(05-03): add failing tests for MarginChangeApplier + RecacheSalesCountsJob stub + CompetitorSalesRecacheCommand (RED)` — 3 test files, 15 tests
6. **Task 3 GREEN:** `b4a73d3 feat(05-03): MarginChangeApplier + RecacheSalesCountsJob (A3 stub) + CompetitorSalesRecacheCommand + 02:00 schedule (GREEN)` — applier + stub job + command + AppServiceProvider registration + schedule entry

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Deptrac Competitor layer missing Webhooks dependency**

- **Found during:** Task 1 implementation — `IncrementSkuSalesCount` listener imports `App\Domain\Webhooks\Events\OrderReceived` + `App\Domain\Webhooks\Models\WebhookReceipt`. Existing `deptrac.yaml` Competitor layer allow-list = `[Foundation, Pricing, Products, Suggestions]` — would have produced 2 violations.
- **Fix:** Extended Competitor layer to `[Foundation, Pricing, Products, Suggestions, Webhooks]` in BOTH deptrac.yaml AND depfile.yaml (legacy mirror per Plan 05-02 Deviation #2). Updated comment block to document Plan 05-03 extension.
- **Verification:** `php vendor/bin/deptrac analyse --no-progress` → 0 violations / 0 warnings / 0 errors / 200 allowed dependencies.
- **Committed in:** `0c12377` (Task 1 GREEN)

**2. [Rule 1 — Bug] PHP 8.4 trait-collision on RecacheSalesCountsJob's `public string $queue = 'sync-bulk'`**

- **Found during:** Task 3 first Pest run — `RecacheSalesCountsJob` declared `public string $queue = 'sync-bulk'`; `Illuminate\Bus\Queueable` declares `public ?string $queue` with null default. PHP 8.4 trait-composition check rejects mismatched property types/defaults.
- **Fix:** Removed the property declaration; route the queue name through `$this->onQueue('sync-bulk')` in the constructor. Test still asserts `$job->queue === 'sync-bulk'` because the trait exposes `$queue` at runtime after the call.
- **Files modified:** `app/Domain/Competitor/Jobs/RecacheSalesCountsJob.php`, `tests/Feature/Competitor/RecacheSalesCountsJobTest.php` (comment-only refresh)
- **Verification:** All 3 RecacheSalesCountsJob tests + all 5 CompetitorSalesRecacheCommand tests pass.
- **Plan precedent:** Documented in Plan 05-02 SUMMARY Deviation #1; same fix applied here.
- **Committed in:** `b4a73d3` (Task 3 GREEN)

**3. [Rule 1 — Bug] StripVatReuseTest false positive: docblock literal `/ 1.2` in MarginAnalyser**

- **Found during:** Task 2 final regression — `StripVatReuseTest::contains zero occurrences of "/ 1.2" or "/ 1.20"` flagged my MarginAnalyser docblock that QUOTED the anti-pattern as part of the explanatory comment ("would fail the build if a `/ 1.2` or local stripVat function appears").
- **Fix:** Rephrased the docblock to "would fail the build if any VAT-divide short-hand or local stripVat function appears" — same semantic meaning, no literal `/ 1.2` substring. Plan 05-02 caught the analogous `{{ Placeholder }}` literal in CompetitorPolicy docblock (Deviation #2) — same family of guardrail-self-trip.
- **Verification:** All 3 StripVatReuseTest cases pass; full Competitor suite still 100% green.
- **Committed in:** `b53cdec` (Task 2 GREEN — fixed inline before commit)
- **Lesson for future planners:** When documenting a code anti-pattern in PHP docblocks under `app/Domain/Competitor/`, describe it in prose (e.g. "VAT-divide short-hand") — never quote the literal substring the integrity-test grep is hunting for.

**4. [Rule 3 — Blocking] suggestions.correlation_id is non-nullable; Context::get('correlation_id') returns null in test context**

- **Found during:** Task 2 ComputeMarginSuggestionJob first run — `QueryException: Column 'correlation_id' cannot be null` on the Suggestion::create call. Test invokes job directly without a parent HTTP/CLI request having attached correlation_id to Context.
- **Fix:** Added a UUID fallback: `'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid()`. Production paths (always inside a BaseCommand or HTTP request) always have a CID in Context; the fallback is defensive for orphan invocations.
- **Files modified:** `app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php`
- **Committed in:** `b53cdec` (Task 2 GREEN — fixed inline before commit)

**5. [Rule 3 — Blocking] Mockery cannot mock final Auditor class**

- **Found during:** Task 3 first MarginChangeApplierTest run — `Mockery::spy(\App\Foundation\Audit\Services\Auditor::class)` threw "The class is marked final and its methods cannot be replaced".
- **Fix:** Switched the audit assertion strategy from "mock the wrapper" to "assert against the spatie\Activitylog\Models\Activity row written by the real Auditor". Stronger integration-level test; matches how Phase 1/4 audit assertions are structured.
- **Files modified:** `tests/Feature/Competitor/MarginChangeApplierTest.php`
- **Verification:** All 7 MarginChangeApplierTest cases pass; activity_log row carries suggestion_id + pricing_rule_id + old_margin_bps + new_margin_bps as expected.
- **Committed in:** `b4a73d3` (Task 3 GREEN — fixed inline before commit)

**6. [Rule 3 — Blocking] dispatchSync() in tests masks job-internal errors behind BadMethodCallException**

- **Found during:** Task 2 first ComputeMarginSuggestionJob run — failing test reported `BadMethodCallException: Method Illuminate\Foundation\Application::notify does not exist` instead of the actual underlying error (the correlation_id null insert from Deviation #4). The route: dispatchSync → job throws → spatie/laravel-failed-job-monitor → tries to call `app->notify()` (macro not registered in this app).
- **Fix:** Switched ComputeMarginSuggestionJobTest to a `runComputeMarginSuggestionJob($competitorId, $sku)` test helper that calls `(new ComputeMarginSuggestionJob(...))->handle(app(MarginAnalyser), app(SalesCounterService), app(RuleResolver))` directly. Real failures surface cleanly.
- **Files modified:** `tests/Feature/Competitor/ComputeMarginSuggestionJobTest.php`
- **Lesson for future planners:** When a Job test exercises a failure path, prefer direct `->handle()` invocation over `::dispatchSync()`. dispatchSync's queue worker path triggers the failed-job monitor which has its own dependencies.
- **Committed in:** `b53cdec` (Task 2 GREEN — fixed inline before commit)

---

**Total deviations:** 6 auto-fixed (4× Rule 3 blocking, 2× Rule 1 bug). All required for correctness or test-harness hygiene. No Rule 4 architectural asks. Plan contract (event back-port + analyser + applier + listener + debounce + schedule + A3 fallback) shipped in full. COMP-08 + COMP-09 requirements complete.

## Authentication Gates

None — this plan is pure DB + event/listener wiring. No external API calls, no new secrets, no CLI logins.

## Performance Characteristics

- **Memory:** Bounded — ComputeMarginSuggestionJob reads max 3 CompetitorPrice rows per dispatch; CompetitorSalesRecacheCommand chunks Product by 100. No unbounded queries.
- **Throughput:** Cache::add debounce caps margin analysis at 1 job per (competitor, sku, day) = 10k jobs/day max for 5 competitors × 2000 SKUs. Default queue handles trivially.
- **DB writes per CSV ingest:** unchanged from Plan 05-02 (1 competitor_prices row per scrape) + 0..1 suggestions row when all 6 gates pass + 0..1 activity_log row when MarginChangeApplier runs.

## Next Phase Readiness

### Plan 05-04a (Filament resources + RBAC) can assume

- `Suggestion(kind='margin_change')` rows are being produced with the FROZEN D-07 evidence JSON shape documented above. SuggestionResource can render `evidence.last_3_competitor_prices` as a sparkline, `evidence.proposed_margin_bps` as a delta badge against `evidence.our_current_margin_bps`, etc.
- Approving a margin_change suggestion in the Filament inbox triggers `ApplySuggestionJob` → resolver → `MarginChangeApplier::apply()` — the full chain is wired and tested.
- `MarginSuggestionCreated` event is the hook for a "new margin suggestions today" badge on the dashboard.
- `SuggestionApplierResolver` now recognises THREE kinds: `crm_push_failed` (Phase 4), `new_product_opportunity` (Phase 5 Plan 02 stub), `margin_change` (this plan).

### Plan 05-04b (Filament pages + stale-feed) can assume

- `competitor_prices` ingest still works; the new debounced listener is silent until thresholds are met.
- `CompetitorAnalysisPage` "biggest deltas" tab can read `Suggestion::where('kind', 'margin_change')->orderBy('evidence->margin_delta_bps', 'desc')` (note: JSON column ordering syntax varies — may need a generated column or PHP-side sort).

### Plan 05-05 (retention + verification)

- The 02:00 schedule slot is already in use — pick 02:10/02:20/etc for `competitor-csv:prune` to avoid overlap.
- `RecacheSalesCountsJob`'s TODO-A3-FOLLOWUP is a Phase 7 polish item — Plan 05-05's verification step can assert the stub still ships if WooClient still lacks /orders.

### Future-phase polish flagged

1. **RecomputePriceListener could subscribe to PricingRuleChanged** so margin updates trigger catalogue-wide recompute automatically. Currently the admin would need to run `pricing:recompute` manually after approving a margin_change suggestion. ONE-line addition to EventServiceProvider's $listen.
2. **WooClient.getOrders() extension** — activates real RecacheSalesCountsJob (TODO-A3-FOLLOWUP). W1 semantics contract documented inline.
3. **JSON column ordering for evidence->margin_delta_bps** — Filament `CompetitorAnalysisPage` "biggest deltas" tab will need either a generated MySQL column on suggestions or PHP-side sort over a paginated query.
4. **Override-scope margin suggestions** — current ComputeMarginSuggestionJob skips when RuleResolver returns `source='override'`. A future plan could extend MarginChangeApplier with a second supports() kind like `margin_override_change` for products with ProductOverride rows.

## Self-Check: PASSED

- **Created files verified:**
  - `app/Domain/Pricing/Events/PricingRuleChanged.php` FOUND
  - `app/Domain/Pricing/Observers/PricingRuleObserver.php` FOUND
  - `app/Domain/Competitor/Events/MarginSuggestionCreated.php` FOUND
  - `app/Domain/Competitor/Services/SalesCounterService.php` FOUND
  - `app/Domain/Competitor/Services/MarginAnalyser.php` FOUND
  - `app/Domain/Competitor/Services/MarginProposal.php` FOUND
  - `app/Domain/Competitor/Listeners/IncrementSkuSalesCount.php` FOUND
  - `app/Domain/Competitor/Listeners/DispatchMarginAnalyserJob.php` FOUND
  - `app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php` FOUND
  - `app/Domain/Competitor/Jobs/RecacheSalesCountsJob.php` FOUND
  - `app/Domain/Competitor/Appliers/MarginChangeApplier.php` FOUND
  - `app/Domain/Competitor/Console/Commands/CompetitorSalesRecacheCommand.php` FOUND
  - 10 test files under `tests/Feature/Pricing/` and `tests/Feature/Competitor/` FOUND

- **Commits verified via `git log --oneline`:**
  - `766e95d test(05-03): ... (RED)` FOUND
  - `0c12377 feat(05-03): PricingRuleChanged event + observer + SalesCounterService ... (GREEN)` FOUND
  - `4060079 test(05-03): ... MarginAnalyser ... (RED)` FOUND
  - `b53cdec feat(05-03): MarginAnalyser + ComputeMarginSuggestionJob ... (GREEN)` FOUND
  - `f76bd46 test(05-03): MarginChangeApplier + RecacheSalesCountsJob ... (RED)` FOUND
  - `b4a73d3 feat(05-03): MarginChangeApplier + RecacheSalesCountsJob ... + schedule (GREEN)` FOUND

- **Runtime verification:**
  - `grep -r "class PricingRuleChanged" app/Domain/Pricing/Events/` → matched `PricingRuleChanged.php` ✓
  - `php artisan event:list | grep -i competitor` → both `CompetitorPriceRecorded → DispatchMarginAnalyserJob` AND `OrderReceived → IncrementSkuSalesCount` listed ✓
  - `php artisan schedule:list | grep competitor:sales-recache` → `0 1 * * * php artisan competitor:sales-recache (Next Due: 3 hours from now)` ✓
  - `php vendor/bin/pest tests/Feature/Competitor/ tests/Feature/Pricing/ tests/Architecture/` → 250/250 passed (764 assertions) ✓
  - `php vendor/bin/pest` (full suite) → 726 passed / 2 skipped (pre-existing) / 0 failed (5765 assertions) ✓
  - `php vendor/bin/deptrac analyse --no-progress` → 0 violations / 0 warnings / 0 errors / 200 allowed dependencies ✓
  - `grep -rn "/ 1\\.2" app/Domain/Competitor/` → 0 matches ✓

---

*Phase: 05-competitor-analysis*
*Plan: 03-margin-analyser-suggestion-producers*
*Completed: 2026-04-19*
