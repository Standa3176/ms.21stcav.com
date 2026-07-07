<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260707-mm7 — SuggestionFailuresPage dedicated triage view
|--------------------------------------------------------------------------
|
| A new admin-only Filament page 'Suggestion Failures' scoped to the three
| failure kinds (crm_push_failed / auto_create_failed / agent_guardrail_blocked)
| — split out of the opportunities list so failures have their own triage home.
|
| PURELY ADDITIVE: SuggestionResource is NOT modified, so no existing test
| breaks. This test proves:
|   - the page lists ONLY the 3 failure kinds (new_product_opportunity hidden)
|   - Replay auto-create dispatches ApplySuggestionJob + flips status→approved
|   - Replay CRM push dispatches ApplySuggestionJob + flips status→approved
|   - Reject flips status→rejected
|   - getNavigationBadge() counts pending failure-kind rows ('3'; 0 → null)
|   - canAccess() is admin-only
|
| correlation_id is set explicitly on every seed (memory: the column is
| NOT NULL — the deferred pre-existing Suggestions failures stem from omitting
| it; we set it here so this suite is green on its own terms).
*/

use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Filament\Pages\SuggestionFailuresPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function suggestionFailuresUser(string $role = 'admin'): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function makeFailureSuggestion(string $kind, string $status = Suggestion::STATUS_PENDING, array $evidence = [], array $payload = []): Suggestion
{
    return Suggestion::create([
        'kind' => $kind,
        'status' => $status,
        'correlation_id' => (string) Str::uuid(),
        'payload' => $payload,
        'evidence' => $evidence,
        'proposed_at' => now(),
    ]);
}

it('lists only the 3 failure kinds and hides the opportunity', function (): void {
    $this->actingAs(suggestionFailuresUser());

    $crm = makeFailureSuggestion('crm_push_failed', evidence: ['error' => 'timeout'], payload: ['woo_id' => 4242]);
    $autoCreate = makeFailureSuggestion('auto_create_failed', evidence: ['sku' => 'SKU-AC', 'error' => 'no brand']);
    $guardrail = makeFailureSuggestion('agent_guardrail_blocked', evidence: ['guardrail_reason' => 'over budget', 'sku' => 'SKU-GR']);
    $opportunity = makeFailureSuggestion('new_product_opportunity', evidence: ['sku' => 'SKU-OP']);

    Livewire::test(SuggestionFailuresPage::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$crm, $autoCreate, $guardrail])
        ->assertCanNotSeeTableRecords([$opportunity]);
});

it('replay auto-create dispatches ApplySuggestionJob and flips status to approved', function (): void {
    Bus::fake();
    $this->actingAs(suggestionFailuresUser());

    $record = makeFailureSuggestion('auto_create_failed', evidence: ['sku' => 'SKU-AC', 'error' => 'boom']);

    Livewire::test(SuggestionFailuresPage::class)
        ->callTableAction('replay_auto_create', $record->id);

    Bus::assertDispatched(
        ApplySuggestionJob::class,
        fn (ApplySuggestionJob $j): bool => $j->suggestionId === $record->id,
    );

    expect($record->fresh()->status)->toBe(Suggestion::STATUS_APPROVED);
});

it('replay CRM push dispatches ApplySuggestionJob and flips status to approved', function (): void {
    Bus::fake();
    $this->actingAs(suggestionFailuresUser());

    $record = makeFailureSuggestion('crm_push_failed', evidence: ['error' => 'timeout'], payload: ['woo_id' => 55]);

    Livewire::test(SuggestionFailuresPage::class)
        ->callTableAction('replay_crm', $record->id);

    Bus::assertDispatched(
        ApplySuggestionJob::class,
        fn (ApplySuggestionJob $j): bool => $j->suggestionId === $record->id,
    );

    expect($record->fresh()->status)->toBe(Suggestion::STATUS_APPROVED);
});

it('reject flips status to rejected and stores the rejection reason', function (): void {
    $this->actingAs(suggestionFailuresUser());

    $record = makeFailureSuggestion('agent_guardrail_blocked', evidence: ['guardrail_reason' => 'over budget']);

    Livewire::test(SuggestionFailuresPage::class)
        ->callTableAction('reject', $record->id, data: ['rejection_reason' => 'not worth replaying']);

    $fresh = $record->fresh();
    expect($fresh->status)->toBe(Suggestion::STATUS_REJECTED)
        ->and($fresh->rejection_reason)->toBe('not worth replaying');
});

it('navigation badge counts pending failure-kind suggestions', function (): void {
    makeFailureSuggestion('crm_push_failed');
    makeFailureSuggestion('auto_create_failed');
    makeFailureSuggestion('agent_guardrail_blocked');
    // Non-failure and non-pending rows must NOT be counted.
    makeFailureSuggestion('new_product_opportunity');
    makeFailureSuggestion('crm_push_failed', Suggestion::STATUS_APPLIED);

    expect(SuggestionFailuresPage::getNavigationBadge())->toBe('3');

    Suggestion::query()->delete();
    expect(SuggestionFailuresPage::getNavigationBadge())->toBeNull();
});

it('is admin-only', function (): void {
    $this->actingAs(suggestionFailuresUser('admin'));
    expect(SuggestionFailuresPage::canAccess())->toBeTrue();

    $this->actingAs(suggestionFailuresUser('sales'));
    expect(SuggestionFailuresPage::canAccess())->toBeFalse();
});
