<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\SimulatedImpactCalculator;
use Filament\Resources\Pages\Page;

/**
 * Phase 3 Plan 03 Task 3 — Simulated Impact (PRCE-09).
 *
 * Pricing manager opens a rule → clicks "Simulate" → sees which SKUs would
 * change if the rule were saved as-is. DB transaction rolls back — no writes
 * persist, no ProductPriceChanged events fire.
 *
 * Gate: can('update', PricingRule::class) — admin + pricing_manager only.
 */
class SimulatedImpact extends Page
{
    protected static string $resource = PricingRuleResource::class;

    protected static string $view = 'filament.pages.simulated-impact';

    protected static ?string $title = 'Simulated Impact';

    /**
     * The PricingRule under test. Named `$rule` (not `$record`) because
     * Livewire's nested-component parameter reconciliation re-assigns
     * any public property that matches a mount param name on re-render —
     * a typed PricingRule property named `$record` would receive the
     * scalar route binding and throw a type error on hydration.
     */
    public ?PricingRule $rule = null;

    public ?array $result = null;

    public function mount(int|string $record): void
    {
        $this->rule = PricingRule::findOrFail($record);
        $this->result = null;
    }

    public function simulate(): void
    {
        $raw = app(SimulatedImpactCalculator::class)->simulate($this->rule, limit: 50);

        // Convert SimulatedImpactRow DTOs → plain arrays because Livewire's
        // property hydrator does not know how to marshal readonly classes
        // across the wire. Blade template expects array keys anyway.
        $this->result = [
            'count' => $raw['count'],
            'rows' => array_map(
                static fn ($row): array => [
                    'productId' => $row->productId,
                    'variantId' => $row->variantId,
                    'sku' => $row->sku,
                    'currentPennies' => $row->currentPennies,
                    'proposedPennies' => $row->proposedPennies,
                    'deltaPennies' => $row->deltaPennies,
                    'resolutionSource' => $row->resolutionSource,
                ],
                $raw['rows']
            ),
        ];
    }

    /**
     * Gate: admin + pricing_manager may simulate (same as policy update matrix).
     * Uses hasAnyRole directly because can('update', PricingRule::class) would
     * invoke the policy with a class string whereas the hand-written method
     * expects a PricingRule instance. Matches the role gate in
     * PricingRulePolicy::update() precisely.
     */
    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }
}
