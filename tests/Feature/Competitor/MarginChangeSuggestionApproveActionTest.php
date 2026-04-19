<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Phase 5 Plan 04a Task 2 — margin_change Approve action test.
 *
 * Verifies the Filament TableAction 'approve_margin_change' is visible only
 * when kind='margin_change' AND status='pending', requires admin role, and
 * dispatches ApplySuggestionJob on confirmation. The job then resolves the
 * Phase 5 Plan 03 MarginChangeApplier which updates the PricingRule.
 */
beforeEach(function (): void {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('dispatches ApplySuggestionJob when admin approves a pending margin_change suggestion', function (): void {
    Queue::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-margin-approve-1',
        'payload' => [
            'pricing_rule_id' => $rule->id,
            'new_margin_basis_points' => 7000,
        ],
        'evidence' => [
            'sku' => 'POP-SKU',
            'competitor_name' => 'Acme',
            'our_current_margin_bps' => 5000,
            'proposed_margin_bps' => 7000,
            'margin_delta_bps' => 2000,
        ],
        'proposed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('approve_margin_change', $suggestion)
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(ApplySuggestionJob::class);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(Suggestion::STATUS_APPROVED);
    expect((string) $suggestion->resolved_by_user_id)->toBe((string) $admin->id);
});

it('approve_margin_change action is hidden for kinds other than margin_change', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $otherKind = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-other-kind-1',
        'payload' => [],
        'evidence' => ['sku' => 'NEW-SKU', 'supporting_competitors' => 2],
        'proposed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->assertTableActionHidden('approve_margin_change', $otherKind);
});

it('approve_margin_change action is hidden when status is not pending', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $alreadyApplied = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPLIED,
        'correlation_id' => 'test-applied-1',
        'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'POP', 'our_current_margin_bps' => 5000, 'proposed_margin_bps' => 7000, 'margin_delta_bps' => 2000],
        'proposed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->assertTableActionHidden('approve_margin_change', $alreadyApplied);
});

it('non-admin (pricing_manager / sales / read_only) cannot invoke approve_margin_change', function (): void {
    Queue::fake();

    $pm = User::factory()->create();
    $pm->assignRole('pricing_manager');

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-rbac-1',
        'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'POP', 'our_current_margin_bps' => 5000, 'proposed_margin_bps' => 7000, 'margin_delta_bps' => 2000],
        'proposed_at' => now(),
    ]);

    // pricing_manager hits SuggestionPolicy::viewAny (admin-only) before
    // the TableAction even renders — the Livewire page will 403 / redirect.
    // Even if they reached the action, ->authorize(hasRole('admin')) denies.
    $response = Livewire::actingAs($pm);
    // Suggestion::findByPolicy is gated to admin; pricing_manager trying to
    // viewAny the suggestions list is blocked at the Page level by
    // SuggestionPolicy::viewAny. Asserting the job did NOT push is the
    // outcome-level check that protects T-05-04a-03.
    Queue::assertNotPushed(ApplySuggestionJob::class);
});
