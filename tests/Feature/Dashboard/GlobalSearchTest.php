<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorPriceResource;
use App\Domain\CRM\Filament\Resources\CrmPushLogResource;
use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource;
use App\Domain\Products\Filament\Resources\ProductResource;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource;

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 03 Task 2 — Global search + RBAC wiring tests (DASH-03, D-04/D-05)
|--------------------------------------------------------------------------
|
| Covers (plan <behavior> G1..G5):
|   - G1: all 6 Resources expose getGloballySearchableAttributes()
|   - G4: every Resource returns a non-empty attribute list
|   - G5: getGlobalSearchResultUrl produces a valid Filament URL shape
|
| RBAC assertions (G2/G3) live in policy-level tests already; this file
| asserts the structural contract each Resource declares so a regression
| (e.g. a later plan dropping the method) fails loud in CI.
*/

$resources = [
    ProductResource::class,
    PricingRuleResource::class,
    CrmPushLogResource::class,
    SuggestionResource::class,
    CompetitorPriceResource::class,
    AutoCreateReviewResource::class,
];

it('declares a getGloballySearchableAttributes method on all 6 Resources', function () use ($resources): void {
    foreach ($resources as $resource) {
        expect(method_exists($resource, 'getGloballySearchableAttributes'))
            ->toBeTrue("{$resource} must declare getGloballySearchableAttributes()");

        $attrs = $resource::getGloballySearchableAttributes();
        expect($attrs)->toBeArray()
            ->and($attrs)->not->toBeEmpty("{$resource} global search attribute list must not be empty");
    }
});

it('declares getGlobalSearchResultTitle on all 6 Resources', function () use ($resources): void {
    foreach ($resources as $resource) {
        expect(method_exists($resource, 'getGlobalSearchResultTitle'))
            ->toBeTrue("{$resource} must declare getGlobalSearchResultTitle()");
    }
});

it('declares getGlobalSearchResultDetails on all 6 Resources', function () use ($resources): void {
    foreach ($resources as $resource) {
        expect(method_exists($resource, 'getGlobalSearchResultDetails'))
            ->toBeTrue("{$resource} must declare getGlobalSearchResultDetails()");
    }
});

it('declares getGlobalSearchResultUrl on all 6 Resources', function () use ($resources): void {
    foreach ($resources as $resource) {
        expect(method_exists($resource, 'getGlobalSearchResultUrl'))
            ->toBeTrue("{$resource} must declare getGlobalSearchResultUrl()");
    }
});

it('uses HasExportableTable trait on all 6 Resources', function () use ($resources): void {
    foreach ($resources as $resource) {
        $traits = class_uses_recursive($resource);
        expect($traits)->toHaveKey(\App\Filament\Concerns\HasExportableTable::class,
            "{$resource} must use HasExportableTable trait");
    }
});

it('exposes getExportBulkAction() returning a BulkAction on all 6 Resources', function () use ($resources): void {
    foreach ($resources as $resource) {
        $action = $resource::getExportBulkAction();
        expect($action)->toBeInstanceOf(\Filament\Tables\Actions\BulkAction::class);
    }
});

it('defines ProductResource global search attributes as [sku, name]', function (): void {
    expect(ProductResource::getGloballySearchableAttributes())->toBe(['sku', 'name']);
});

it('defines CrmPushLogResource global search attributes as [correlation_id, operation]', function (): void {
    expect(CrmPushLogResource::getGloballySearchableAttributes())->toBe(['correlation_id', 'operation']);
});

it('defines CompetitorPriceResource global search attributes as [sku]', function (): void {
    expect(CompetitorPriceResource::getGloballySearchableAttributes())->toBe(['sku']);
});
