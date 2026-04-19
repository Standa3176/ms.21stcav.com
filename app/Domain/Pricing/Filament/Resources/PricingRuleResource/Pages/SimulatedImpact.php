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

    public PricingRule $record;

    public ?array $result = null;

    public function mount(int|string $record): void
    {
        $this->record = PricingRule::findOrFail($record);
        $this->result = null;
    }

    public function simulate(): void
    {
        $this->result = app(SimulatedImpactCalculator::class)->simulate($this->record, limit: 50);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('update', PricingRule::class) ?? false;
    }
}
