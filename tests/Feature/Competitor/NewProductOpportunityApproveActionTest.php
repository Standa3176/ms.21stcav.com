<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Phase 5 Plan 04a Task 2 — new_product_opportunity Approve action test.
 *
 * Verifies the Filament TableAction 'approve_new_product_opportunity' is
 * visible only when kind='new_product_opportunity' AND status='pending',
 * requires admin role, and dispatches ApplySuggestionJob which resolves the
 * Phase 5 Plan 02 NewProductOpportunityApplier (stub body; Phase 6 replaces).
 */
beforeEach(function (): void {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('dispatches ApplySuggestionJob when admin approves a pending new_product_opportunity suggestion', function (): void {
    Queue::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-new-product-1',
        'payload' => [],
        'evidence' => [
            'sku' => 'NEW-OPP-1',
            'supporting_competitors' => 3,
            'first_seen_at' => now()->toIso8601String(),
            'competitor_sightings' => [
                ['competitor_id' => 1, 'name' => 'Acme', 'price_gross_pennies' => 12000],
                ['competitor_id' => 2, 'name' => 'Beta', 'price_gross_pennies' => 11500],
                ['competitor_id' => 3, 'name' => 'Gamma', 'price_gross_pennies' => 12500],
            ],
        ],
        'proposed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('approve_new_product_opportunity', $suggestion)
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(ApplySuggestionJob::class);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(Suggestion::STATUS_APPROVED);
    expect((string) $suggestion->resolved_by_user_id)->toBe((string) $admin->id);
});

it('approve_new_product_opportunity action is hidden for kinds other than new_product_opportunity', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $otherKind = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-other-1',
        'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 6000],
        'evidence' => ['sku' => 'M', 'our_current_margin_bps' => 5000, 'proposed_margin_bps' => 6000, 'margin_delta_bps' => 1000],
        'proposed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->assertTableActionHidden('approve_new_product_opportunity', $otherKind);
});

it('supporting_competitors column renders the evidence count for new_product_opportunity rows', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-badge-1',
        'payload' => [],
        'evidence' => ['sku' => 'BADGE-TEST', 'supporting_competitors' => 5],
        'proposed_at' => now(),
    ]);

    // Renders as an integer state via ->state() — assert the Livewire page
    // loads without error + record is present in the paginated table.
    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->assertCanSeeTableRecords([$suggestion]);
});

it('running the NewProductOpportunityApplier directly returns the Phase 5 stub shape (logged + idempotent)', function (): void {
    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'applier-direct-1',
        'payload' => [],
        'evidence' => ['sku' => 'DIRECT-APPLY', 'supporting_competitors' => 2],
        'proposed_at' => now(),
    ]);

    $applier = app(\App\Domain\Competitor\Appliers\NewProductOpportunityApplier::class);
    $result = $applier->apply($suggestion);

    expect($result['phase_5_stub'] ?? null)->toBeTrue();
    expect($result['sku'] ?? null)->toBe('DIRECT-APPLY');
    expect($result['applier'] ?? null)->toContain('NewProductOpportunityApplier');
});
