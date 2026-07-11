<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Widgets\LatestMarketingAdviceWidget;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-02 Task 4 — LatestMarketingAdviceWidget
|--------------------------------------------------------------------------
|
| READ-ONLY table of the most-recent PENDING ad_optimisation Suggestions
| (15b-01 output). Other kinds excluded; hard empty-state (zero rows → no error).
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function actingMarketingAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);

    return $admin;
}

function seedAdvice(string $target = 'Brand UK', string $confidence = 'high'): Suggestion
{
    return Suggestion::create([
        'kind' => 'ad_optimisation',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [
            'proposals' => [[
                'action_type' => 'shift_budget',
                'target' => $target,
                'rationale' => 'High ROAS channel is under-funded.',
                'supporting_metrics' => 'sessions=300 revenue=£1,500',
                'confidence' => $confidence,
            ]],
            'agent_run_id' => (string) Str::uuid(),
        ],
        'proposed_at' => now(),
    ]);
}

it('shows pending ad_optimisation suggestions and excludes other kinds', function (): void {
    actingMarketingAdmin();

    $advice = seedAdvice();

    $otherKind = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['foo' => 'bar'],
        'proposed_at' => now(),
    ]);

    $nonPending = Suggestion::create([
        'kind' => 'ad_optimisation',
        'status' => Suggestion::STATUS_APPLIED,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['proposals' => []],
        'proposed_at' => now(),
    ]);

    Livewire::test(LatestMarketingAdviceWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$advice])
        ->assertCanNotSeeTableRecords([$otherKind, $nonPending]);
});

it('renders a friendly empty state with zero advice rows (no error)', function (): void {
    actingMarketingAdmin();

    Livewire::test(LatestMarketingAdviceWidget::class)
        ->assertSuccessful()
        ->assertSee('No marketing advice yet');
});

it('is visible only to admins (Suggestion viewAny gate)', function (): void {
    $nonAdmin = User::factory()->create();
    test()->actingAs($nonAdmin);
    expect(LatestMarketingAdviceWidget::canView())->toBeFalse();

    actingMarketingAdmin();
    expect(LatestMarketingAdviceWidget::canView())->toBeTrue();
});
