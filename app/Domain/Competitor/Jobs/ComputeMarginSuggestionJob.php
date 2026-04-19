<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Jobs;

use App\Domain\Competitor\Events\MarginSuggestionCreated;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Services\MarginAnalyser;
use App\Domain\Competitor\Services\SalesCounterService;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 5 Plan 03 Task 2 — the three-threshold gate + Suggestion producer.
 *
 * Dispatched by DispatchMarginAnalyserJob listener (debounced via Cache::add
 * per (competitor, sku, day)). Runs on the `default` queue — cheap work,
 * no external IO besides DB reads.
 *
 * Gate sequence (short-circuits on first miss):
 *   1. Product exists for SKU (case-sensitive match; orphan detection is
 *      Plan 05-02's OrphanDetector concern).
 *   2. last N CompetitorPrice rows (N = config consecutive_scrapes_required,
 *      default 3) exist.
 *   3. Direction consistency — all N rows consistently above OR below our
 *      sell price; a flip resets the signal.
 *   4. Sales threshold — products.last_sales_count_90d >= config threshold.
 *   5. MarginAnalyser returns non-null proposal (min-margin-floor guard
 *      built in).
 *   6. abs(current_margin_bps - proposed_margin_bps) >= config delta
 *      threshold.
 *
 * On success: creates Suggestion(kind='margin_change') with D-07 evidence +
 * fires MarginSuggestionCreated event.
 *
 * T-05-03-05 mitigation: Cache::add debounce at the listener layer caps
 * dispatches to 1 per (competitor, sku, day) so the queue can't be flooded
 * by n8n CSV re-drops.
 */
class ComputeMarginSuggestionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $competitorId,
        public readonly string $sku,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        MarginAnalyser $analyser,
        SalesCounterService $salesCounter,
        RuleResolver $resolver,
    ): void {
        // ── Gate 1 — Product must exist for the SKU ──────────────────────────
        $product = Product::where('sku', $this->sku)->first();
        if ($product === null) {
            return; // orphan path handled by OrphanDetector upstream
        }

        // ── Gate 2 — ≥N consecutive scrapes required ──────────────────────────
        $requiredScrapes = (int) config('competitor.consecutive_scrapes_required', 3);
        $lastRows = CompetitorPrice::query()
            ->where('competitor_id', $this->competitorId)
            ->where('sku', $this->sku)
            ->orderByDesc('recorded_at')
            ->limit($requiredScrapes)
            ->get();

        if ($lastRows->count() < $requiredScrapes) {
            return;
        }

        // ── Gate 3 — sales threshold ──────────────────────────────────────────
        if (! $salesCounter->meetsThreshold($this->sku)) {
            return;
        }

        // ── Gate 4 — direction consistency ────────────────────────────────────
        $ourSellPennies = (int) round(((float) $product->sell_price) * 100);
        $directions = $lastRows->map(
            fn (CompetitorPrice $row): bool => (int) $row->price_pennies_ex_vat < $ourSellPennies
        );
        if ($directions->unique()->count() !== 1) {
            // mixed above/below — not a persistent signal
            return;
        }

        // ── Gate 5 — MarginAnalyser proposal (min-margin-floor built in) ──────
        /** @var CompetitorPrice $latestRow */
        $latestRow = $lastRows->first();
        $supplierPennies = (int) round(((float) $product->buy_price) * 100);

        $proposal = $analyser->computeProposal(
            competitorGrossPennies: (int) $latestRow->price_pennies_gross,
            supplierExVatPennies: $supplierPennies,
        );
        if ($proposal === null) {
            return;
        }

        // ── Resolve applicable PricingRule (RuleResolver returns a DTO) ───────
        try {
            $resolution = $resolver->resolve($product);
        } catch (\Throwable $e) {
            Log::warning('ComputeMarginSuggestionJob.rule_resolution_failed', [
                'competitor_id' => $this->competitorId,
                'sku' => $this->sku,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // margin_change suggestions only make sense against a real PricingRule
        // (override resolution is D-08 scope — skip until later).
        if ($resolution->matchedRuleId === null) {
            return;
        }

        $rule = PricingRule::find($resolution->matchedRuleId);
        if ($rule === null) {
            return;
        }

        // ── Gate 6 — margin delta threshold ───────────────────────────────────
        $currentMarginBps = (int) $rule->margin_basis_points;
        $proposedMarginBps = (int) $proposal->proposedMarginBasisPoints;
        $deltaBps = abs($currentMarginBps - $proposedMarginBps);

        $deltaThreshold = (int) config('competitor.margin_delta_threshold_bps', 800);
        if ($deltaBps < $deltaThreshold) {
            return;
        }

        // ── Build D-07 evidence JSON ──────────────────────────────────────────
        $competitor = Competitor::find($this->competitorId);

        $evidence = [
            'competitor_id' => $this->competitorId,
            'competitor_name' => $competitor?->name ?? sprintf('Competitor #%d', $this->competitorId),
            'sku' => $this->sku,
            'last_3_competitor_prices' => $lastRows->map(fn (CompetitorPrice $r) => [
                'price_ex_vat_pennies' => (int) $r->price_pennies_ex_vat,
                'recorded_at' => $r->recorded_at?->toIso8601String(),
            ])->values()->all(),
            'our_sell_price_pennies' => $ourSellPennies,
            'our_supplier_price_pennies' => $supplierPennies,
            'our_current_margin_bps' => $currentMarginBps,
            'proposed_margin_bps' => $proposedMarginBps,
            'margin_delta_bps' => $deltaBps,
            'sales_count_90d' => $salesCounter->getCount($this->sku),
            'pricing_rule' => [
                'id' => (int) $rule->id,
                'scope' => (string) $rule->scope,
                'current_margin_bps' => $currentMarginBps,
                'resolution_source' => $resolution->source,
            ],
            'beat_by_pennies' => $proposal->beatByPennies,
        ];

        $suggestion = Suggestion::create([
            'kind' => 'margin_change',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
            'payload' => [
                'pricing_rule_id' => (int) $rule->id,
                'new_margin_basis_points' => $proposedMarginBps,
            ],
            'evidence' => $evidence,
            'proposed_at' => now(),
        ]);

        event(new MarginSuggestionCreated(
            suggestionId: (string) $suggestion->id,
            competitorId: $this->competitorId,
            sku: $this->sku,
            proposedMarginBps: $proposedMarginBps,
        ));
    }
}
