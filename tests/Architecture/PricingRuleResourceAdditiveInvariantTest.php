<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 05 — D-09 PricingRuleResource additive invariant (DB-free)
|--------------------------------------------------------------------------
|
| Mirrors PricingRuleResourceCustomerGroupFieldTest Tests 3 & 4 but lives in
| Architecture/ so it runs even when MySQL is offline. Source-grep
| assertions; no DB needed.
|
| The D-09 invariant: PricingRuleResource gains ONE Select (customer_group_id)
| + ONE SelectFilter (customer_group_id) — and NOTHING ELSE removed or
| renamed. Phase 3 form fields and table filters must remain. Future PRs
| that try to refactor PricingRuleResource will fail this test loudly.
|
| T-09-05-04 mitigation: locked on CI by these greps.
*/

it('PricingRuleResource preserves Phase 3 form fields (D-09 additive invariant)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/PricingRuleResource.php'));
    expect($source)->not->toBeFalse('PricingRuleResource source must be readable');

    // Phase 3 form fields — must stay.
    expect($source)->toContain("Select::make('scope')");
    expect($source)->toContain("TextInput::make('brand_id')");
    expect($source)->toContain("TextInput::make('category_id')");
    expect($source)->toContain("TextInput::make('margin_basis_points')");
    expect($source)->toContain("TextInput::make('priority')");
    expect($source)->toContain("Toggle::make('is_default_tier')");
    expect($source)->toContain("Toggle::make('active')");

    // Reactive scope behaviour preserved (drives brand/category visibility).
    expect($source)->toContain('->reactive()');

    // New additive Select (D-09).
    expect($source)->toContain("Select::make('customer_group_id')");
    expect($source)->toContain("relationship('customerGroup', 'name')");
});

it('PricingRuleResource retains existing filters AND adds customer_group_id (D-09 additive invariant)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/PricingRuleResource.php'));

    // Phase 3 filters — must stay.
    expect($source)->toContain("TernaryFilter::make('active')");
    expect($source)->toContain("SelectFilter::make('scope')");

    // New filter (D-09 additive).
    expect($source)->toContain("SelectFilter::make('customer_group_id')");

    // grep-counted: at least 5 Select/Filter make() calls survive.
    $count = substr_count($source, 'TernaryFilter::make')
        + substr_count($source, 'SelectFilter::make')
        + substr_count($source, 'Select::make');
    expect($count)->toBeGreaterThanOrEqual(5, "Expected ≥5 Select/Filter make() calls; got {$count}");
});

it('PricingRuleResource customer_group_id Select is positioned BEFORE the scope Select (D-09 first-in-form)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/PricingRuleResource.php'));

    $cgPos = strpos($source, "Select::make('customer_group_id')");
    $scopePos = strpos($source, "Select::make('scope')");

    expect($cgPos)->not->toBeFalse('customer_group_id Select must exist');
    expect($scopePos)->not->toBeFalse('scope Select must exist');
    expect($cgPos)->toBeLessThan($scopePos, 'customer_group_id Select must be FIRST in form (before scope) — D-09');
});

it('RolePermissionSeeder contains the 5 customer_group permission strings (W-05 v1-parity)', function (): void {
    $source = file_get_contents(base_path('database/seeders/RolePermissionSeeder.php'));

    expect($source)->toContain("'view_any_customer_group'");
    expect($source)->toContain("'view_customer_group'");
    expect($source)->toContain("'create_customer_group'");
    expect($source)->toContain("'update_customer_group'");
    expect($source)->toContain("'delete_customer_group'");

    // W-05 documentation: brittleness of findByName accepted as v1-parity.
    expect($source)->toContain('W-05')
        ->and($source)->toContain('findByName');
});
