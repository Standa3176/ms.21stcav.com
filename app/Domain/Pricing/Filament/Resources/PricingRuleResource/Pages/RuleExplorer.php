<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Resources\Pages\Page;

/**
 * Phase 3 Plan 03 Task 2 — Rule Explorer (PRCE-08).
 *
 * Pricing manager types a SKU → page shows:
 *   - Effective retail price (pennies → £XX.XX)
 *   - Margin applied (basis points → %)
 *   - Resolution chain (override / brand_category / category / brand / default_tier)
 *   - Link to the winning rule or override for drill-down edit
 *
 * READ-ONLY: does NOT dispatch ProductPriceChanged, does NOT write anything.
 * Gate: can('viewAny', PricingRule) (admin + pricing_manager + sales + read_only).
 *
 * Variant SKU lookup falls back to the parent Product for brand/category
 * resolution; variant.buy_price wins over parent.buy_price when set.
 */
class RuleExplorer extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = PricingRuleResource::class;

    protected static string $view = 'filament.pages.rule-explorer';

    protected static ?string $title = 'Rule Explorer';

    protected static ?string $navigationLabel = 'Rule Explorer';

    protected static ?int $navigationSort = 15;

    public ?array $data = [];

    public ?array $resolution = null;

    public ?string $lastError = null;

    public function mount(): void
    {
        $this->form->fill([]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('sku')
                ->label('SKU')
                ->required()
                ->placeholder('e.g. LOG-C930E')
                ->helperText('Product or variant SKU. Lookup resolves product → variant fallback.'),
        ])->statePath('data');
    }

    public function lookup(): void
    {
        $this->resolution = null;
        $this->lastError = null;

        $sku = trim((string) ($this->data['sku'] ?? ''));
        if ($sku === '') {
            $this->lastError = 'Enter a SKU to look up.';

            return;
        }

        // Resolve product by SKU: product.sku first, then variant.sku → parent.
        $product = Product::where('sku', $sku)->first();
        $variant = null;

        if ($product === null) {
            $variant = ProductVariant::where('sku', $sku)->first();
            $product = $variant?->product;
        }

        if ($product === null) {
            $this->lastError = "No product found for SKU {$sku}.";

            return;
        }

        try {
            $resolution = app(RuleResolver::class)->resolve($product);
        } catch (NoPricingRuleMatchedException $e) {
            $this->lastError = $e->getMessage();

            return;
        }

        // Buy price: variant overrides parent when present.
        $buyPrice = $variant?->buy_price ?? $product->buy_price;
        $buyPennies = $buyPrice === null ? 0 : (int) round(((float) $buyPrice) * 100);

        // Guard BEFORE calling calculator — calculator throws on <=0 and the
        // stack trace would be confusing for the pricing manager.
        if ($buyPennies <= 0) {
            $this->lastError = 'Product has zero / null buy_price — no retail price computable. Check the Import Issues page for missing cost-price entries.';

            return;
        }

        $sellPennies = app(PriceCalculator::class)->compute($buyPennies, $resolution->marginBasisPoints);

        $this->resolution = [
            'sku' => $sku,
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'variant_id' => $variant?->id,
            'buy_pennies' => $buyPennies,
            'sell_pennies' => $sellPennies,
            'margin_basis_points' => $resolution->marginBasisPoints,
            'source' => $resolution->source,
            'matched_rule_id' => $resolution->matchedRuleId,
            'override_id' => $resolution->overrideId,
            'chain' => $resolution->chain,
        ];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('viewAny', PricingRule::class) ?? false;
    }
}
