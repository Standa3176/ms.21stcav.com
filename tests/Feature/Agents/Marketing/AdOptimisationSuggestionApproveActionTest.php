<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 6 — advice-only ad_optimisation approval
|--------------------------------------------------------------------------
|
| Approving an ad_optimisation Suggestion is acknowledgement ONLY: it flips
| status to approved but dispatches NO ApplySuggestionJob and triggers no
| external write (no applier is registered for the kind; closed-loop is 15c).
| The generic approve action (which DOES dispatch) is hidden for this kind.
*/

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function makeAdOptSuggestion(string $status = Suggestion::STATUS_PENDING): Suggestion
{
    return Suggestion::create([
        'kind' => 'ad_optimisation',
        'status' => $status,
        'correlation_id' => 'test-ad-opt-'.uniqid(),
        'payload' => [
            'proposals' => [[
                'action_type' => 'reduce_spend',
                'target' => 'Paid Search / Generic',
                'rationale' => 'No conversions in 30 days.',
                'supporting_metrics' => '{"sessions":900}',
                'confidence' => 'high',
            ]],
            'agent_run_id' => 'run-123',
        ],
        'evidence' => ['agent_kind' => 'ad_optimisation'],
        'proposed_at' => now(),
    ]);
}

it('ad_optimisation is a selectable kind filter option and is NOT hidden by default', function (): void {
    // Kind SelectFilter exposes the option.
    $reflection = new ReflectionClass(SuggestionResource::class);
    expect(file_get_contents($reflection->getFileName()))->toContain("'ad_optimisation' => 'ad_optimisation'");

    // getEloquentQuery only hides agent_guardrail_blocked — ad_optimisation shows.
    makeAdOptSuggestion();
    $visibleKinds = SuggestionResource::getEloquentQuery()->pluck('kind')->all();
    expect($visibleKinds)->toContain('ad_optimisation');
});

it('acknowledging an ad_optimisation Suggestion flips status to approved and dispatches NO apply job', function (): void {
    Bus::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $suggestion = makeAdOptSuggestion();

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->set('tableFilters.kind.value', 'ad_optimisation')
        ->set('tableFilters.status.value', null)
        ->callTableAction('acknowledge_ad_optimisation', $suggestion)
        ->assertHasNoTableActionErrors();

    // Advice-only: NO external job queued.
    Bus::assertNotDispatched(ApplySuggestionJob::class);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(Suggestion::STATUS_APPROVED);
    expect((string) $suggestion->resolved_by_user_id)->toBe((string) $admin->id);
});

it('the generic approve action (which dispatches ApplySuggestionJob) is hidden for ad_optimisation', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $suggestion = makeAdOptSuggestion();

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->set('tableFilters.kind.value', 'ad_optimisation')
        ->set('tableFilters.status.value', null)
        ->assertTableActionHidden('approve', $suggestion)
        ->assertTableActionVisible('acknowledge_ad_optimisation', $suggestion);
});
