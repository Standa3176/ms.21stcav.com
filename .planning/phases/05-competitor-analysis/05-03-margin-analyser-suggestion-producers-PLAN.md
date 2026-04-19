---
phase: 05-competitor-analysis
plan: 03
type: execute
wave: 3
depends_on:
  - "05-02"
files_modified:
  - app/Domain/Pricing/Events/PricingRuleChanged.php
  - app/Domain/Pricing/Observers/PricingRuleObserver.php
  - app/Domain/Pricing/Models/PricingRule.php
  - app/Domain/Competitor/Services/SalesCounterService.php
  - app/Domain/Competitor/Services/MarginAnalyser.php
  - app/Domain/Competitor/Listeners/DispatchMarginAnalyserJob.php
  - app/Domain/Competitor/Listeners/IncrementSkuSalesCount.php
  - app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php
  - app/Domain/Competitor/Jobs/RecacheSalesCountsJob.php
  - app/Domain/Competitor/Console/Commands/CompetitorSalesRecacheCommand.php
  - app/Domain/Competitor/Events/MarginSuggestionCreated.php
  - app/Domain/Competitor/Appliers/MarginChangeApplier.php
  - app/Providers/EventServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - routes/console.php
  - tests/Feature/Pricing/PricingRuleChangedEventTest.php
  - tests/Feature/Competitor/SalesCounterServiceTest.php
  - tests/Feature/Competitor/MarginAnalyserTest.php
  - tests/Feature/Competitor/DispatchMarginAnalyserJobTest.php
  - tests/Feature/Competitor/ComputeMarginSuggestionJobTest.php
  - tests/Feature/Competitor/IncrementSkuSalesCountListenerTest.php
  - tests/Feature/Competitor/MarginChangeApplierTest.php
  - tests/Feature/Competitor/MinMarginFloorGuardTest.php
  - tests/Feature/Competitor/DebounceKeyTest.php
autonomous: true
requirements:
  - COMP-08
  - COMP-09

must_haves:
  truths:
    - "Task 1 opens with `grep -r 'class PricingRuleChanged' app/Domain/Pricing/Events/`; if zero hits (expected per Phase 3 SUMMARY audit), the event class + PricingRule observer + EventServiceProvider wire-up are shipped THIS plan (Assumption A1 gate)"
    - "`App\\Domain\\Pricing\\Events\\PricingRuleChanged` exists, extends DomainEvent, carries {ruleId, oldMarginBps, newMarginBps, correlationId}"
    - "`PricingRuleObserver::updated` fires `PricingRuleChanged` ONLY when `$rule->isDirty('margin_basis_points')` returns true"
    - "PricingRule model registers the observer via `Model::observe(PricingRuleObserver::class)` in `AppServiceProvider::boot` OR via `#[ObservedBy]` attribute on the model"
    - "`SalesCounterService::getCount(string $sku): int` returns `Product::where('sku', $sku)->value('last_sales_count_90d') ?? 0` — NO live Woo REST per call"
    - "`IncrementSkuSalesCount` listener subscribes to Phase 1 `OrderReceived` event; for each `line_items[].sku` in the payload, calls `Product::where('sku', $sku)->increment('last_sales_count_90d')`; runs on `default` queue"
    - "`CompetitorSalesRecacheCommand` (signature `competitor:sales-recache`, BaseCommand) is scheduled daily at 02:00 in routes/console.php; iterates products in chunks of 100, dispatches `RecacheSalesCountsJob` on `sync-bulk` queue; each job authoritatively recomputes `last_sales_count_90d` via Woo REST `GET /orders?after={90d_ago}&per_page=100` with pagination"
    - "`MarginAnalyser::computeProposal(int $competitorGrossPennies, int $supplierExVatPennies): ?MarginProposal` uses `PriceCalculator::stripVat` for VAT removal; returns null when supplierExVatPennies <= 0; applies min-margin-floor guard (Pitfall P5-E) — proposed margin < config('competitor.min_margin_floor_bps', 500) → null + Log::warning('suggestion_suppressed_low_margin')"
    - "`DispatchMarginAnalyserJob` listener subscribes to `CompetitorPriceRecorded`; constructs cache key `competitor.analyser.debounce.{competitorId}.{sku}.{YYYY-MM-DD}`; uses `Cache::add($key, true, now()->addHours(24))` atomic — if returns false, exits silently (debounced); else dispatches `ComputeMarginSuggestionJob` on `default` queue"
    - "`ComputeMarginSuggestionJob::handle`: loads last 3 `CompetitorPrice` rows for (competitor_id, sku); applies 3 threshold gates: delta >= config('competitor.margin_delta_threshold_bps'), 3 consecutive scrapes same direction (all above or all below our sell), sales_count >= config('competitor.sales_threshold_90d'); resolves applicable PricingRule via RuleResolver for the product; computes MarginProposal; if all gates pass AND proposal non-null → creates Suggestion(kind='margin_change') with D-07 evidence JSON shape + payload={pricing_rule_id, new_margin_basis_points} + fires MarginSuggestionCreated event"
    - "`MarginChangeApplier::supports()` returns `['margin_change']`; `apply($suggestion)` loads PricingRule by payload.pricing_rule_id, updates `margin_basis_points` to payload.new_margin_basis_points via $rule->update() which triggers PricingRuleObserver → PricingRuleChanged event (Phase 3 listener chain then recomputes), records Auditor::record('competitor.margin_change_applied') with before/after, returns result array"
    - "`MarginChangeApplier` registered in AppServiceProvider::boot via `app(SuggestionApplierResolver::class)->register('margin_change', MarginChangeApplier::class)`"
    - "Both listeners (DispatchMarginAnalyserJob, IncrementSkuSalesCount) registered in EventServiceProvider::$listen array"
    - "MarginAnalyser respects beatByPennies config: targetSellExVat = competitorExVat - beatByPennies (default 1p)"
  artifacts:
    - path: "app/Domain/Pricing/Events/PricingRuleChanged.php"
      provides: "Event fired when PricingRule.margin_basis_points changes — consumed by MarginChangeApplier + future Phase 3 RecomputePriceListener extension"
      exports: ["ruleId","oldMarginBps","newMarginBps"]
    - path: "app/Domain/Pricing/Observers/PricingRuleObserver.php"
      provides: "Fires PricingRuleChanged on isDirty('margin_basis_points') after save"
    - path: "app/Domain/Competitor/Services/MarginAnalyser.php"
      provides: "Reverse-margin calculation with min-margin-floor guard; returns ?MarginProposal"
      min_lines: 50
    - path: "app/Domain/Competitor/Services/SalesCounterService.php"
      provides: "Denormalised sales count lookup + threshold helper"
    - path: "app/Domain/Competitor/Listeners/DispatchMarginAnalyserJob.php"
      provides: "Debounced listener via Cache::add(key, true, 24h) atomic lock"
    - path: "app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php"
      provides: "Threshold-checking + evidence-JSON-building + Suggestion-creating job"
      min_lines: 80
    - path: "app/Domain/Competitor/Appliers/MarginChangeApplier.php"
      provides: "Second real SuggestionApplier producer (after Phase 4's CrmPushRetryApplier)"
    - path: "app/Domain/Competitor/Jobs/RecacheSalesCountsJob.php"
      provides: "Nightly authoritative recache via Woo REST (on sync-bulk queue)"
    - path: "app/Domain/Competitor/Console/Commands/CompetitorSalesRecacheCommand.php"
      provides: "Daily 02:00 schedule entry; iterates products + dispatches RecacheSalesCountsJob per chunk"
  key_links:
    - from: "app/Providers/EventServiceProvider.php"
      to: "app/Domain/Competitor/Listeners/DispatchMarginAnalyserJob.php"
      via: "$listen array"
      pattern: "CompetitorPriceRecorded::class.*DispatchMarginAnalyserJob"
    - from: "app/Providers/EventServiceProvider.php"
      to: "app/Domain/Competitor/Listeners/IncrementSkuSalesCount.php"
      via: "$listen array"
      pattern: "OrderReceived::class.*IncrementSkuSalesCount"
    - from: "app/Domain/Competitor/Appliers/MarginChangeApplier.php"
      to: "app/Domain/Pricing/Models/PricingRule.php"
      via: "update + implicit event fire"
      pattern: "PricingRule::findOrFail"
    - from: "app/Providers/AppServiceProvider.php"
      to: "app/Domain/Competitor/Appliers/MarginChangeApplier.php"
      via: "SuggestionApplierResolver::register"
      pattern: "register\\('margin_change'"
---

<objective>
The analytical core: reverse-engineer proposed margins from competitor prices, noise-suppress via three thresholds, create margin_change suggestions with rich evidence JSON, ship the applier that mutates PricingRule (triggering Phase 3's recompute chain via the newly-shipped PricingRuleChanged event).

**Assumption A1 verification — Task 1 opens with** `grep -r "class PricingRuleChanged" app/Domain/Pricing/Events/`. Confirmed absent in planner's dev-env check (2026-04-19: only ProductPriceChanged.php exists). This plan ships the event class, observer, and wire-up as Task 1. If a later agent finds the event already exists (e.g. Phase 3 back-ports it first), skip class creation and just re-verify the observer wiring.

Purpose: When shipped, a `competitor_prices` row that meets all 3 thresholds (8% delta, 3 consecutive scrapes, ≥10 sales/90d) produces a `suggestions('margin_change')` row whose approval cascades through PricingRule update → Phase 3 RecomputePriceListener → ProductPriceChanged → Phase 2 Woo push — fully wired.

Output: 1 new Pricing event + 1 observer + 6 Competitor services/jobs/listeners + 1 applier + 1 command + 1 MarginSuggestionCreated event + EventServiceProvider + AppServiceProvider updates + 9 Pest tests.
</objective>

<execution_context>
@C:/Users/sonny.tanda/.claude/get-shit-done/workflows/execute-plan.md
@C:/Users/sonny.tanda/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05-competitor-analysis/05-CONTEXT.md
@.planning/phases/05-competitor-analysis/05-RESEARCH.md
@.planning/phases/05-competitor-analysis/05-01-SUMMARY.md
@.planning/phases/05-competitor-analysis/05-02-SUMMARY.md

# Phase 3 contracts Phase 5 depends on (MUST NOT duplicate)
@.planning/phases/03-pricing-engine/03-02-SUMMARY.md
@app/Domain/Pricing/Services/PriceCalculator.php
@app/Domain/Pricing/Services/RuleResolver.php
@app/Domain/Pricing/Models/PricingRule.php
@app/Domain/Pricing/Events/ProductPriceChanged.php

# Phase 1 contracts for applier + event handling
@app/Domain/Suggestions/Contracts/SuggestionApplier.php
@app/Domain/Suggestions/Services/SuggestionApplierResolver.php
@app/Domain/Suggestions/Models/Suggestion.php
@app/Domain/Foundation/Events/DomainEvent.php
@app/Domain/Foundation/Audit/Services/Auditor.php
@app/Providers/EventServiceProvider.php
@app/Providers/AppServiceProvider.php

# Phase 4 Applier pattern reference (CrmPushRetryApplier)
@.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md
@app/Domain/CRM/Appliers/CrmPushRetryApplier.php

# Phase 1 OrderReceived event (this plan's IncrementSkuSalesCount subscribes to it)
@app/Domain/Webhooks/Events/OrderReceived.php

<interfaces>
<!-- Existing contracts Phase 5 Plan 3 consumes verbatim -->

From app/Domain/Pricing/Services/PriceCalculator.php:
```php
public function stripVat(int $grossPennies, int $vatBasisPoints = 2000): int;
```

From app/Domain/Pricing/Services/RuleResolver.php (Phase 3 Plan 02):
```php
// Most-specific-wins resolution — returns the PricingRule (or default-tier) for a product
public function resolve(Product $product): PricingRule;
```

From app/Domain/Suggestions/Models/Suggestion.php (Phase 1 Plan 04):
```php
// Columns: id (ULID), kind, status (pending|approved|rejected|applied|failed),
//          payload (json), evidence (json), correlation_id, applier_result (json nullable), ...
```

From app/Domain/Suggestions/Contracts/SuggestionApplier.php:
```php
interface SuggestionApplier
{
    public function supports(): array;            // return ['kind_a', 'kind_b']
    public function apply(Suggestion $s): array;  // throws on failure; returns result array
}
```

From app/Domain/Webhooks/Events/OrderReceived.php (Phase 1 Plan 04):
```php
class OrderReceived extends DomainEvent
{
    public function __construct(public readonly int $webhookReceiptId, public readonly array $payload) {}
    // $payload shape includes 'line_items' => [['sku' => '...', 'quantity' => N, ...], ...]
}
```

From app/Domain/CRM/Appliers/CrmPushRetryApplier.php (Phase 4 Plan 03 — the pattern to mirror):
```php
class CrmPushRetryApplier implements SuggestionApplier
{
    public function supports(): array { return ['crm_push_failed']; }
    public function apply(Suggestion $s): array { /* re-dispatches original job */ }
}
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Assumption A1 gate + PricingRuleChanged event + PricingRule observer + SalesCounterService + IncrementSkuSalesCount listener</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §9 (PricingRuleChanged wiring) §3 (SalesCounterService)
    - @app/Domain/Pricing/Events/ProductPriceChanged.php (shape to mirror)
    - @app/Domain/Pricing/Models/PricingRule.php (to add observer)
    - @app/Domain/Pricing/Listeners/RecomputePriceListener.php (Phase 3 listener; confirm it subscribes to SupplierPriceChanged — will NOT be modified in this plan)
    - @app/Domain/Webhooks/Events/OrderReceived.php (Phase 1; confirm payload.line_items shape)
    - @app/Providers/EventServiceProvider.php (current $listen array)
    - @.planning/phases/03-pricing-engine/03-02-SUMMARY.md (confirm PricingRuleChanged absent from original Phase 3 ship)
  </read_first>
  <context_annotation>
    **W1 — Sales counter semantics (FROZEN for 05-03):** `last_sales_count_90d` = "1 increment per line item per order". Concretely: an order with `quantity=3` of SKU-1 counts as 1 (NOT 3); an order with SKU-1 appearing in TWO separate line items counts as 2 (degenerate Woo shape but semantically correct). `RecacheSalesCountsJob` (Task 3) MUST use the identical aggregation to prevent drift with the real-time listener: `COUNT(DISTINCT order_line_item_id) WHERE sku = X AND created_at >= NOW() - INTERVAL 90 DAY`. If Woo REST returns orders rather than line items directly, aggregate line items in PHP using the same rule — one count per line item occurrence, not multiplied by quantity. This semantic MUST be documented verbatim in 05-03-SUMMARY.md post-execution.
  </context_annotation>
  <behavior>
    - ASSUMPTION GATE Test: `grep -rn "class PricingRuleChanged" app/Domain/Pricing/Events/` runs; test passes when the file exists after this plan executes. If executor reads pre-existing content (unlikely), executor skips creating the class but still runs wire-up tests.
    - Test: `new PricingRuleChanged(ruleId: 1, oldMarginBps: 5000, newMarginBps: 6000)` carries the 3 fields + inherits correlation_id from DomainEvent
    - Test: `PricingRuleObserver::updated` fires PricingRuleChanged when `margin_basis_points` is dirty after save
    - Test: `PricingRuleObserver::updated` does NOT fire PricingRuleChanged when only `name` column is dirty (no margin change)
    - Test: updating a PricingRule via `$rule->update(['margin_basis_points' => 6000])` dispatches `PricingRuleChanged` (verified via `Event::fake(PricingRuleChanged::class)` + `Event::assertDispatched`)
    - Test: `SalesCounterService::getCount('SKU-1')` returns `Product::where('sku', 'SKU-1')->value('last_sales_count_90d')` when product exists
    - Test: `SalesCounterService::getCount('NON-EXISTENT')` returns 0 (null-coalesced)
    - Test: `SalesCounterService::meetsThreshold('SKU-1')` returns true when count >= config('competitor.sales_threshold_90d', 10)
    - Test: IncrementSkuSalesCount listener: given OrderReceived with payload.line_items=[['sku' => 'SKU-1', 'quantity' => 3], ['sku' => 'SKU-2', 'quantity' => 1]] → Product SKU-1's last_sales_count_90d increments by 1, SKU-2 increments by 1 (1 per line item, NOT multiplied by quantity — unless we choose quantity-aware; spec is "count = orders" per DocString, so 1-per-order-line is correct)
    - Test: IncrementSkuSalesCount listener ignores line items with null/missing sku
    - Test: EventServiceProvider $listen array contains `OrderReceived::class => [IncrementSkuSalesCount::class, ...]`
  </behavior>
  <action>
**STEP 1 — Assumption A1 verification:**
```bash
grep -r "class PricingRuleChanged" app/Domain/Pricing/Events/ || echo "MISSING — ship in this task"
```
Confirmed missing. Proceed to create.

**Create `app/Domain/Pricing/Events/PricingRuleChanged.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Events;

use App\Domain\Foundation\Events\DomainEvent;

final class PricingRuleChanged extends DomainEvent
{
    public function __construct(
        public readonly int $ruleId,
        public readonly int $oldMarginBps,
        public readonly int $newMarginBps,
    ) {
        parent::__construct();
    }
}
```

**Create `app/Domain/Pricing/Observers/PricingRuleObserver.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Observers;

use App\Domain\Pricing\Events\PricingRuleChanged;
use App\Domain\Pricing\Models\PricingRule;

class PricingRuleObserver
{
    public function updated(PricingRule $rule): void
    {
        if (! $rule->wasChanged('margin_basis_points')) {
            return;
        }

        $old = $rule->getOriginal('margin_basis_points');
        $new = $rule->margin_basis_points;

        if ($old === $new) {
            return; // defensive: cast-normalisation guard
        }

        event(new PricingRuleChanged(
            ruleId: $rule->id,
            oldMarginBps: (int) $old,
            newMarginBps: (int) $new,
        ));
    }
}
```

**Wire observer in `PricingRule` model** — add class attribute:
```php
use App\Domain\Pricing\Observers\PricingRuleObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(PricingRuleObserver::class)]
class PricingRule extends Model { /* existing body */ }
```

(Laravel 11+ supports `ObservedBy` attribute; if Phase 1–3 used `->observe()` in ServiceProvider boot, follow that instead — read `AppServiceProvider::boot()` first.)

**Create `app/Domain/Competitor/Services/SalesCounterService.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Products\Models\Product;

class SalesCounterService
{
    public function __construct(private int $thresholdDefault = 10) {}

    public function getCount(string $sku): int
    {
        $count = Product::where('sku', $sku)->value('last_sales_count_90d');
        return (int) ($count ?? 0);
    }

    public function meetsThreshold(string $sku): bool
    {
        $threshold = (int) config('competitor.sales_threshold_90d', $this->thresholdDefault);
        return $this->getCount($sku) >= $threshold;
    }
}
```

**Create `app/Domain/Competitor/Listeners/IncrementSkuSalesCount.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Listeners;

use App\Domain\Products\Models\Product;
use App\Domain\Webhooks\Events\OrderReceived;
use Illuminate\Contracts\Queue\ShouldQueue;

class IncrementSkuSalesCount implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(OrderReceived $event): void
    {
        $lineItems = data_get($event->payload, 'line_items', []);

        foreach ($lineItems as $item) {
            $sku = data_get($item, 'sku');
            if (! is_string($sku) || $sku === '') {
                continue;
            }

            Product::where('sku', $sku)->increment('last_sales_count_90d');
        }
    }
}
```

**Wire listener in `app/Providers/EventServiceProvider.php` `$listen` array** — APPEND (do NOT replace existing entries):
```php
\App\Domain\Webhooks\Events\OrderReceived::class => [
    // ... existing listeners ...
    \App\Domain\Competitor\Listeners\IncrementSkuSalesCount::class,
],
```

**Tests (`tests/Feature/Pricing/` + `tests/Feature/Competitor/`):**
- `PricingRuleChangedEventTest` — event class carries correct fields; observer fires on margin dirty; does NOT fire on other field dirty
- `SalesCounterServiceTest` — 3 cases (product exists, product missing, threshold check)
- `IncrementSkuSalesCountListenerTest` — dispatches listener via Event::fake or direct invocation; asserts DB increments; handles missing/null SKUs
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Pricing/PricingRuleChangedEventTest.php tests/Feature/Competitor/SalesCounterServiceTest.php tests/Feature/Competitor/IncrementSkuSalesCountListenerTest.php --stop-on-failure && grep -q "class PricingRuleChanged" app/Domain/Pricing/Events/PricingRuleChanged.php && grep -q "IncrementSkuSalesCount" app/Providers/EventServiceProvider.php</automated>
  </verify>
  <done>PricingRuleChanged event exists; PricingRuleObserver fires it on margin_basis_points dirty saves only; SalesCounterService provides getCount + meetsThreshold; IncrementSkuSalesCount listener wired in EventServiceProvider; all 3 test files green; zero Phase 3 regression (existing Phase 3 tests still pass — verify via `php vendor/bin/pest tests/Feature/Pricing/ --stop-on-failure`).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: MarginAnalyser + MarginProposal DTO + ComputeMarginSuggestionJob + DispatchMarginAnalyserJob listener + debounce</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §7 (full MarginAnalyser implementation) §8 (debounce strategy) Example 1 Example 2
    - @app/Domain/Pricing/Services/RuleResolver.php (resolve(Product): PricingRule)
    - @app/Domain/Products/Models/Product.php (supplier_price_pennies, sell_price_pennies columns)
    - @app/Domain/Competitor/Events/CompetitorPriceRecorded.php (from 05-02 — confirm event shape)
    - @app/Domain/Competitor/Models/CompetitorPrice.php (to query last-3 scrapes)
    - @app/Domain/Suggestions/Models/Suggestion.php (ULID, kind, evidence, payload)
    - @config/competitor.php (all 9 thresholds — margin_delta_threshold_bps, consecutive_scrapes_required, sales_threshold_90d, min_margin_floor_bps, beat_by_pennies)
  </read_first>
  <behavior>
    - Test: `MarginAnalyser::computeProposal(competitorGross=8999, supplierExVat=4000)`
      - stripVat(8999, 2000) = 7499
      - targetSellExVat = 7499 - 1 (beat by 1p) = 7498
      - marginBps = intdiv((7498 - 4000) * 10000, 4000) = intdiv(34980000, 4000) = 8745
      - Asserts returned MarginProposal has proposedMarginBasisPoints=8745, competitorExVatPennies=7499, supplierExVatPennies=4000, beatByPennies=1
    - Test: `MarginAnalyser::computeProposal(competitorGross=5000, supplierExVat=4500)` where proposed margin < 500 bps (floor) → returns null + logs 'suggestion_suppressed_low_margin' warning (Pitfall P5-E)
    - Test: `MarginAnalyser::computeProposal(competitorGross=8999, supplierExVat=0)` → returns null (invalid supplier price guard)
    - Test: `MarginAnalyser::computeProposal(competitorGross=8999, supplierExVat=-100)` → returns null
    - Test: `DispatchMarginAnalyserJob` first invocation for (competitor_id=1, sku='SKU-1', date='2026-04-21') → Cache::add succeeds → dispatches ComputeMarginSuggestionJob
    - Test: second invocation same key (still same day) → Cache::add returns false → listener exits silently, NO second dispatch
    - Test: DebounceKeyTest asserts exact cache key format: `competitor.analyser.debounce.1.SKU-1.2026-04-21`
    - Test: `ComputeMarginSuggestionJob::handle` — full happy path:
      - Seed: Competitor, Product(sku=SKU-1, supplier_price=4000, sell_price=8500, brand + category), 3 CompetitorPrice rows (all < our sell, consistent direction), SalesCounter returns 15
      - Expected: Suggestion(kind=margin_change) created with payload={pricing_rule_id: X, new_margin_basis_points: Y}, evidence carrying last_3_competitor_prices + our_* fields + pricing_rule sub-object + beat_by_pennies per D-07 shape; MarginSuggestionCreated event fires
    - Test: same fixture but sales_count = 5 (below threshold) → NO suggestion created
    - Test: same fixture but delta_bps < 800 (below threshold) → NO suggestion created
    - Test: same fixture but only 2 CompetitorPrice rows (below 3-scrape threshold) → NO suggestion created
    - Test: 3 CompetitorPrice rows where directions flip (row 1 below our sell, row 2 above our sell, row 3 below) → NOT all-consecutive-same-direction → NO suggestion
    - Test: ComputeMarginSuggestionJob respects min-margin-floor (delegates to MarginAnalyser null return)
  </behavior>
  <action>
**`app/Domain/Competitor/Services/MarginProposal.php`** (readonly DTO):
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

final readonly class MarginProposal
{
    public function __construct(
        public int $proposedMarginBasisPoints,
        public int $competitorExVatPennies,
        public int $supplierExVatPennies,
        public int $beatByPennies,
    ) {}
}
```

**`app/Domain/Competitor/Services/MarginAnalyser.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Pricing\Services\PriceCalculator;
use Illuminate\Support\Facades\Log;

class MarginAnalyser
{
    public function __construct(private PriceCalculator $calculator) {}

    public function computeProposal(int $competitorGrossPennies, int $supplierExVatPennies): ?MarginProposal
    {
        if ($supplierExVatPennies <= 0) {
            return null; // Phase 3 invalid-supplier guard analogue
        }

        $beatByPennies = (int) config('competitor.beat_by_pennies', 1);
        $minFloorBps = (int) config('competitor.min_margin_floor_bps', 500);

        $competitorExVat = $this->calculator->stripVat($competitorGrossPennies, 2000); // COMP-06 — NEVER reimplement
        $targetSellExVat = $competitorExVat - $beatByPennies;

        // margin_bps = ((target_sell - supplier) / supplier) * 10000
        $marginBps = intdiv(($targetSellExVat - $supplierExVatPennies) * 10000, $supplierExVatPennies);

        if ($marginBps < $minFloorBps) {
            Log::warning('suggestion_suppressed_low_margin', [
                'supplier_ex_vat_pennies' => $supplierExVatPennies,
                'competitor_ex_vat_pennies' => $competitorExVat,
                'proposed_margin_bps' => $marginBps,
                'floor_bps' => $minFloorBps,
            ]);
            return null; // Pitfall P5-E guard
        }

        return new MarginProposal(
            proposedMarginBasisPoints: $marginBps,
            competitorExVatPennies: $competitorExVat,
            supplierExVatPennies: $supplierExVatPennies,
            beatByPennies: $beatByPennies,
        );
    }
}
```

**`app/Domain/Competitor/Listeners/DispatchMarginAnalyserJob.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Listeners;

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Jobs\ComputeMarginSuggestionJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

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
            return; // another listener already dispatched today (Pattern 3)
        }

        ComputeMarginSuggestionJob::dispatch($event->competitorId, $event->sku)
            ->onQueue('default');
    }
}
```

**`app/Domain/Competitor/Events/MarginSuggestionCreated.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Events;

use App\Domain\Foundation\Events\DomainEvent;

final class MarginSuggestionCreated extends DomainEvent
{
    public function __construct(
        public readonly string $suggestionId,      // ULID string
        public readonly int $competitorId,
        public readonly string $sku,
        public readonly int $proposedMarginBps,
    ) { parent::__construct(); }
}
```

**`app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php`:**
- Implements `ShouldQueue`; `$queue = 'default'`; `$tries = 2`; `$timeout = 60`
- Constructor: `public function __construct(public int $competitorId, public string $sku) {}`
- `handle(MarginAnalyser $analyser, SalesCounterService $salesCounter, RuleResolver $resolver): void`:
  1. Load Product by SKU (case-insensitive). If missing → return (orphan; handled upstream).
  2. Load last 3 CompetitorPrice rows for (competitor_id, sku) ordered by recorded_at DESC. If count < `config('competitor.consecutive_scrapes_required', 3)` → return.
  3. Sales threshold: if `! $salesCounter->meetsThreshold($this->sku)` → return.
  4. Consecutive-direction check: For all 3 rows, check if `$row->price_pennies_ex_vat < $product->sell_price_pennies` is consistent across all 3 (all true OR all false). If mixed → return.
  5. Compute proposal: `$proposal = $analyser->computeProposal($latestRow->price_pennies_gross, $product->supplier_price_pennies);` If null → return.
  6. Resolve applicable PricingRule: `$rule = $resolver->resolve($product);`
  7. Check delta: `$deltaBps = abs($rule->margin_basis_points - $proposal->proposedMarginBasisPoints);` If `$deltaBps < config('competitor.margin_delta_threshold_bps', 800)` → return.
  8. Build evidence JSON per D-07 shape (see RESEARCH §7):
     ```php
     $evidence = [
         'competitor_id' => $this->competitorId,
         'competitor_name' => $competitor->name,
         'sku' => $this->sku,
         'last_3_competitor_prices' => $last3->map(fn ($r) => ['price_ex_vat_pennies' => $r->price_pennies_ex_vat, 'recorded_at' => $r->recorded_at->toIso8601String()])->values()->all(),
         'our_sell_price_pennies' => $product->sell_price_pennies,
         'our_supplier_price_pennies' => $product->supplier_price_pennies,
         'our_current_margin_bps' => $rule->margin_basis_points,
         'proposed_margin_bps' => $proposal->proposedMarginBasisPoints,
         'margin_delta_bps' => $deltaBps,
         'sales_count_90d' => $salesCounter->getCount($this->sku),
         'pricing_rule' => [
             'id' => $rule->id,
             'name' => $rule->name ?? sprintf('Rule #%d', $rule->id),
             'scope' => $rule->scope ?? 'default_tier',
             'current_margin_bps' => $rule->margin_basis_points,
         ],
         'beat_by_pennies' => $proposal->beatByPennies,
     ];
     ```
  9. Create Suggestion:
     ```php
     $suggestion = Suggestion::create([
         'kind' => 'margin_change',
         'status' => 'pending',
         'subject_type' => PricingRule::class,
         'subject_id' => $rule->id,
         'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => $proposal->proposedMarginBasisPoints],
         'evidence' => $evidence,
         'correlation_id' => Context::get('correlation_id'),
     ]);
     ```
  10. `event(new MarginSuggestionCreated($suggestion->id, $this->competitorId, $this->sku, $proposal->proposedMarginBasisPoints));`

**Wire DispatchMarginAnalyserJob in `app/Providers/EventServiceProvider.php`:**
```php
\App\Domain\Competitor\Events\CompetitorPriceRecorded::class => [
    \App\Domain\Competitor\Listeners\DispatchMarginAnalyserJob::class,
],
```

**Tests:**
- `MarginAnalyserTest` — 4 scenarios: happy, below floor, zero supplier, negative supplier
- `MinMarginFloorGuardTest` — Log::warning assertion on P5-E path
- `DebounceKeyTest` — exact cache key format; second invocation same day returns null
- `DispatchMarginAnalyserJobTest` — Cache::add + Queue::fake → first fires, second doesn't
- `ComputeMarginSuggestionJobTest` — full happy path + 4 threshold-miss scenarios (sales below 10, delta below 800, scrape count below 3, direction flip)
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/MarginAnalyserTest.php tests/Feature/Competitor/MinMarginFloorGuardTest.php tests/Feature/Competitor/DebounceKeyTest.php tests/Feature/Competitor/DispatchMarginAnalyserJobTest.php tests/Feature/Competitor/ComputeMarginSuggestionJobTest.php --stop-on-failure</automated>
  </verify>
  <done>MarginAnalyser ships with 4 guards (zero/neg supplier, min-margin-floor, stripVat delegation); DispatchMarginAnalyserJob debounces via Cache::add atomic; ComputeMarginSuggestionJob enforces all 3 thresholds + consecutive-direction gate; evidence JSON matches D-07 shape exactly; MarginSuggestionCreated event fires on Suggestion creation; all 5 test files green.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: MarginChangeApplier + RecacheSalesCountsJob + CompetitorSalesRecacheCommand + schedule entry</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §3 (SalesCounter nightly recache) §7 (applier pattern) §Code Example 4 (MarginChangeApplier shape)
    - @app/Domain/CRM/Appliers/CrmPushRetryApplier.php (Phase 4 applier structure to mirror exactly)
    - @app/Domain/Sync/Services/WooClient.php (Phase 2 — for GET /orders call in RecacheSalesCountsJob)
    - @app/Domain/Pricing/Models/PricingRule.php (update → observer → PricingRuleChanged — verified in Task 1)
    - @app/Domain/Foundation/Audit/Services/Auditor.php (Phase 1 — record() signature)
    - @app/Console/Commands/BaseCommand.php (perform() pattern)
    - @routes/console.php (current schedule entries; append 02:00 recache)
  </read_first>
  <behavior>
    - Test: `MarginChangeApplier::supports()` returns `['margin_change']`
    - Test: `MarginChangeApplier::apply($suggestion)` where payload={pricing_rule_id: 5, new_margin_basis_points: 7000}:
      - Loads PricingRule id=5
      - Updates margin_basis_points from oldMargin (say 5000) → 7000
      - PricingRuleObserver fires PricingRuleChanged(ruleId=5, oldMarginBps=5000, newMarginBps=7000) (verified via Event::fake)
      - Auditor::record called with 'competitor.margin_change_applied' + {suggestion_id, pricing_rule_id, old_margin_bps, new_margin_bps}
      - Returns result array with {applied: true, pricing_rule_id: 5, old_margin_bps: 5000, new_margin_bps: 7000}
    - Test: `MarginChangeApplier::apply` when payload.pricing_rule_id references a non-existent rule → throws ModelNotFoundException (ApplySuggestionJob catches + flips status=failed per Phase 1 D-17 contract)
    - Test: `MarginChangeApplier::apply` is idempotent — second call with same suggestion (already-applied margin) does NOT re-fire PricingRuleChanged (observer's wasChanged check prevents no-op fires)
    - Test: MarginChangeApplier is registered in AppServiceProvider — `app(SuggestionApplierResolver::class)->resolve('margin_change')` returns the applier instance
    - Test: `CompetitorSalesRecacheCommand` signature = 'competitor:sales-recache'; when run with 0 products → no jobs dispatched; return 0
    - Test: `CompetitorSalesRecacheCommand` with 150 products → dispatches 2 RecacheSalesCountsJob instances (100 + 50) on 'sync-bulk' queue
    - Test: `php artisan schedule:list | grep competitor:sales-recache` shows the schedule entry
    - Test: RecacheSalesCountsJob(['SKU-1', 'SKU-2']) — Http::fake Woo response with mocked orders containing both SKUs → updates `products.last_sales_count_90d` AND `last_sales_count_computed_at` for both
  </behavior>
  <action>
**STEP 1 — Verify WooClient orders endpoint support (W2 guard):**

```bash
grep -rn "getOrders\|/orders" app/Domain/Sync/Clients/WooClient.php 2>/dev/null || echo "WOOCLIENT_ORDERS_MISSING"
```

**Context:** Phase 2 WooClient may not expose a `GET /orders` method. If the grep returns zero hits (i.e. `WOOCLIENT_ORDERS_MISSING` printed):
- `RecacheSalesCountsJob` (Task 3 below) falls back to **event-driven-only** semantics — the nightly recache path becomes a TODO-A3-FOLLOWUP scheduled for a post-Phase-5 WooClient extension.
- In this fallback: `CompetitorSalesRecacheCommand` still dispatches `RecacheSalesCountsJob` onto `sync-bulk`, but the job body reduces to a self-documenting `Log::warning('recache.wooclient_orders_missing', [...]); return;` + Auditor note. `last_sales_count_90d` still updates via the real-time `IncrementSkuSalesCount` listener (Task 1); drift correction becomes a Phase 7 polish item.
- If the grep returns ≥1 hit, proceed with the full Woo REST aggregation described in Task 3 action below.

Document the outcome of this grep in 05-03-SUMMARY.md under "A3 Outcome".

**STEP 2 — MarginChangeApplier:**

**`app/Domain/Competitor/Appliers/MarginChangeApplier.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Appliers;

use App\Domain\Foundation\Audit\Services\Auditor;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;

class MarginChangeApplier implements SuggestionApplier
{
    public function __construct(private Auditor $auditor) {}

    public function supports(): array
    {
        return ['margin_change'];
    }

    public function apply(Suggestion $suggestion): array
    {
        $payload = $suggestion->payload;
        $rule = PricingRule::findOrFail(data_get($payload, 'pricing_rule_id'));

        $oldMargin = (int) $rule->margin_basis_points;
        $newMargin = (int) data_get($payload, 'new_margin_basis_points');

        // Update — PricingRuleObserver fires PricingRuleChanged IF dirty
        $rule->update(['margin_basis_points' => $newMargin]);

        $this->auditor->record('competitor.margin_change_applied', [
            'suggestion_id' => $suggestion->id,
            'pricing_rule_id' => $rule->id,
            'old_margin_bps' => $oldMargin,
            'new_margin_bps' => $rule->fresh()->margin_basis_points,
        ]);

        return [
            'applied' => true,
            'pricing_rule_id' => $rule->id,
            'old_margin_bps' => $oldMargin,
            'new_margin_bps' => $rule->fresh()->margin_basis_points,
        ];
    }
}
```

**Register in `AppServiceProvider::boot()`** — APPEND (do NOT overwrite Phase 4's existing `crm_push_failed` registration or Phase 5 Plan 02's `new_product_opportunity` registration):
```php
app(\App\Domain\Suggestions\Services\SuggestionApplierResolver::class)
    ->register('margin_change', \App\Domain\Competitor\Appliers\MarginChangeApplier::class);
```

**`app/Domain/Competitor/Jobs/RecacheSalesCountsJob.php`:**
- Implements `ShouldQueue`; `$queue = 'sync-bulk'`; `$tries = 3`; `$timeout = 300`
- Constructor: `public function __construct(public array $skus) {}`
- `handle(WooClient $woo): void`:
  1. Build params: `$after = now()->subDays(90)->toIso8601String();`
  2. Paginate Woo `GET /orders?after={after}&status=any&per_page=100` (follow Phase 2 WooClient rate-limit middleware chain)
  3. Aggregate SKU → count from `line_items[].sku` where SKU in `$this->skus`
  4. For each SKU: `Product::where('sku', $sku)->update(['last_sales_count_90d' => $count, 'last_sales_count_computed_at' => now()])`
  5. SKUs with zero orders: set count=0 + computed_at=now (authoritative overwrite)

**`app/Domain/Competitor/Console/Commands/CompetitorSalesRecacheCommand.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Competitor\Jobs\RecacheSalesCountsJob;
use App\Domain\Products\Models\Product;

class CompetitorSalesRecacheCommand extends BaseCommand
{
    protected $signature = 'competitor:sales-recache';
    protected $description = 'Recompute last_sales_count_90d for every product (nightly job).';

    protected function perform(): int
    {
        Product::query()
            ->select('sku')
            ->chunk(100, function ($products) {
                RecacheSalesCountsJob::dispatch($products->pluck('sku')->all())->onQueue('sync-bulk');
            });

        $this->info('Sales recache jobs dispatched onto sync-bulk queue.');
        return 0;
    }
}
```

**Register in `routes/console.php`** — APPEND:
```php
Schedule::command('competitor:sales-recache')
    ->dailyAt('02:00')
    ->onOneServer();
```

**Tests:**
- `MarginChangeApplierTest` — happy path (rule updates, event fires, auditor records, return array correct) + missing rule throws + idempotency check
- Include assertion in `MarginChangeApplierTest` that `Event::assertDispatched(PricingRuleChanged::class)` — this closes the cross-domain chain
- Supplement test: registration test — `app(SuggestionApplierResolver::class)->resolve('margin_change') instanceof MarginChangeApplier`

Optional command test (if Pest fixture shape permits): shell out `php artisan competitor:sales-recache --env=testing` against a 150-product seed; assert jobs dispatched onto sync-bulk queue.
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/MarginChangeApplierTest.php --stop-on-failure && php artisan schedule:list 2>/dev/null | grep -q "competitor:sales-recache" && php artisan tinker --execute="echo app(\\App\\Domain\\Suggestions\\Services\\SuggestionApplierResolver::class)->resolve('margin_change')::class;" | grep -q MarginChangeApplier</automated>
  </verify>
  <done>MarginChangeApplier registered + tested + fires PricingRuleChanged via Eloquent update + observer chain; CompetitorSalesRecacheCommand scheduled daily 02:00; RecacheSalesCountsJob dispatches to sync-bulk with 100-SKU chunks; `php artisan schedule:list` shows the new entry; the full chain (CompetitorPriceRecorded → debounced listener → ComputeMarginSuggestionJob → margin_change Suggestion → admin approval → ApplySuggestionJob → MarginChangeApplier → PricingRule update → PricingRuleChanged event) is wired end-to-end.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Suggestion.payload → PricingRule update | Admin-approved payload updates a real pricing rule. Tampered payload = unauthorised margin change. |
| Woo REST → RecacheSalesCountsJob | External untrusted source → overwrites internal product counter. |
| OrderReceived payload → IncrementSkuSalesCount | Already HMAC-verified at webhook ingress (Phase 1 FOUND-07). |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-03-01 | Tampering | Suggestion.payload.new_margin_basis_points | mitigate | MarginChangeApplier enforces `Auditor::record` BEFORE return; min_margin_floor_bps enforced BOTH at creation (MarginAnalyser) AND implicitly at approval — admin is trusted but the suggestion row is append-only. No rewrites. |
| T-05-03-02 | Elevation of Privilege | Non-admin approving margin_change suggestion | mitigate | SuggestionResource + SuggestionPolicy enforce admin-only; `->authorize()` on Approve Filament Action; Shield permission `approve_suggestion` gated to admin role only. (Phase 1 D-15 pattern preserved.) |
| T-05-03-03 | Tampering | MarginAnalyser integer-math overflow | accept | PHP integer overflow at 64-bit is ~9.2 quintillion pennies; price × 10000 reaches overflow only at ~922 quadrillion pennies — impossible for consumer AV products. |
| T-05-03-04 | Information Disclosure | Suggestion.evidence contains supplier price | accept | Admin-only; no external exposure. Supplier prices are commercial data but not PII. |
| T-05-03-05 | Denial of Service | CompetitorPriceRecorded listener flooding queue | mitigate | Cache::add debounce per (competitor, sku, day) bounds to 1 ComputeMarginSuggestionJob per SKU per competitor per day. 5 competitors × 2000 SKUs = 10k dispatches/day max — well within queue capacity. |
| T-05-03-06 | Tampering | Woo REST response in RecacheSalesCountsJob | mitigate | Phase 2 WooClient enforces 429 backoff + HMAC on inbound webhooks; outbound GET /orders uses API-key auth; response is numeric counts, no code execution path. |
| T-05-03-07 | Information Disclosure | Auditor records sell/supplier price in audit_log | accept | Admin-only read via Filament; retention pruning at 365 days per Phase 1 D-05. |
</threat_model>

<verification>
- Full Pest suite green: `php vendor/bin/pest --stop-on-failure`
- `php artisan schedule:list` shows: `competitor:watch` (every 5 min) + `competitor:sales-recache` (daily 02:00)
- `app(SuggestionApplierResolver::class)->resolve('margin_change')` returns `MarginChangeApplier`
- `grep -r "stripVat" app/Domain/Competitor/ | wc -l` >= 1 (MarginAnalyser calls it)
- `grep -r "/ 1\\.2\\b\\|/ 1\\.20\\b" app/Domain/Competitor/` returns zero matches
- A smoke-test integration: seed Product+Competitor+3 CompetitorPrice rows + set last_sales_count_90d=20 → fire CompetitorPriceRecorded manually → assert Suggestion(kind=margin_change) created with D-07 evidence
- Phase 3 test suite (`tests/Feature/Pricing/`) still green (PricingRuleChanged event should not break existing tests; observer only fires on margin dirty so non-margin edits unaffected)
</verification>

<success_criteria>
- PricingRuleChanged event ships + observer fires on margin changes
- MarginAnalyser returns null below floor (P5-E) + null on zero/neg supplier
- ComputeMarginSuggestionJob enforces 3 thresholds + direction consistency
- MarginChangeApplier registered + fires PricingRuleChanged through the observer chain (no direct event dispatch)
- SalesCounterService + IncrementSkuSalesCount + CompetitorSalesRecacheCommand form the hybrid real-time + nightly reconciliation strategy
- All 9 new Pest tests pass
- Zero Phase 1–4 test regressions
</success_criteria>

<output>
Create `.planning/phases/05-competitor-analysis/05-03-SUMMARY.md` documenting:
- A1 verification outcome (event was missing → shipped here; note for future phases)
- Which observer registration style was chosen (#[ObservedBy] attribute vs AppServiceProvider::boot(Model::observe))
- Integer arithmetic choices in MarginAnalyser (intdiv used — penny-exact, no float)
- Exact evidence JSON shape for D-07 — FROZEN for Filament UI (Plan 05-04 consumes)
- If RecacheSalesCountsJob had to fall back from Woo REST /orders SKU-filter to PHP-side aggregation
- Any discovered conflicts with existing Phase 3 RecomputePriceListener (e.g., if listener needs to subscribe to PricingRuleChanged too — if yes, note for Phase 7 polish)
</output>