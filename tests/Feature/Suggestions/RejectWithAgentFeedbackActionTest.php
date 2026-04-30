<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 05 Task 1 — D-09 structured rejection feedback action test
|--------------------------------------------------------------------------
|
| Locks the conditional ->form() + ->action() behaviour on SuggestionResource's
| reject TABLE action when the Suggestion is a margin_change row enriched by
| the PricingAgent (i.e. evidence.agent_run_ids[] is non-empty).
|
| 6 cases per the plan acceptance criteria:
|
|   1. Persistence — successful structured submission flips status=rejected
|      AND writes agent_rejection_feedback JSON column with all 4 fields
|      (misleading, notes, rejected_by_user_id, rejected_at) when margin_change
|      + agent_run_ids non-empty
|   2. Backward compat — non-margin_change reject leaves agent_rejection_feedback
|      NULL (column-canonical resolution per Plan 10-05 Step B); rejection_reason
|      lands in the legacy column
|   3. Backward compat — margin_change with empty agent_run_ids[] runs the
|      standard reject path (rejection_reason filled; agent_rejection_feedback NULL)
|   4. Validation — submission with notes < 10 chars fails validation; status
|      stays pending; agent_rejection_feedback stays NULL
|   5. Validation — submission missing the misleading radio fails validation
|   6. Persistence — `partial` and `no` misleading values both round-trip onto
|      the column (rubric coverage)
|
| Storage strategy invariant (Plan 10-05 Step B): structured feedback writes to
| the top-level suggestions.agent_rejection_feedback JSON column, NOT to
| evidence.agent_rejection_feedback (the rejection inbox query needs the
| column for an indexable whereNotNull scan).
|
| Suggestion has no factory — created directly via Suggestion::create() per
| MarginChangeEvidenceContractTest precedent (Plan 10-04). The reject action
| lives in ->actions([...]) on the table, so tests use ListSuggestions +
| callTableAction('reject', $suggestion, data: [...]).
*/

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function rejectFeedbackAdminUser(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user->fresh();
}

function makeMarginChangeSuggestion(array $evidenceOverrides = [], string $status = Suggestion::STATUS_PENDING): Suggestion
{
    $evidence = array_merge([
        'sku' => 'TEST-MC-'.strtoupper(Str::random(6)),
        'competitor_id' => 5,
        'competitor_name' => 'AV Distributor Ltd',
        'our_current_margin_bps' => 2000,
        'proposed_margin_bps' => 1800,
        'margin_delta_bps' => 200,
        'sales_count_90d' => 27,
        'pricing_rule' => ['id' => 1, 'scope' => 'global'],
        // D-02 — non-empty agent_run_ids[] flips the reject action into D-09 mode
        'agent_run_ids' => ['01HX'.strtoupper(Str::random(22))],
        'agent_reasoning' => 'Sample agent reasoning at least forty characters long for realism.',
        'agent_confidence_0_to_100' => 62,
        'agent_proposed_band_min_bps' => 1750,
        'agent_proposed_band_max_bps' => 1900,
    ], $evidenceOverrides);

    return Suggestion::create([
        'kind' => 'margin_change',
        'status' => $status,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 1800],
        'evidence' => $evidence,
        'proposed_at' => now(),
    ]);
}

function makeNonMarginChangeSuggestion(string $kind = 'crm_push_failed', string $status = Suggestion::STATUS_PENDING): Suggestion
{
    return Suggestion::create([
        'kind' => $kind,
        'status' => $status,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['woo_id' => 999],
        'evidence' => ['detail' => 'a Bitrix push failure'],
        'proposed_at' => now(),
    ]);
}

it('writes agent_rejection_feedback JSON column on successful structured submission (margin_change + agent_run_ids non-empty)', function (): void {
    $admin = rejectFeedbackAdminUser();
    $suggestion = makeMarginChangeSuggestion();
    $longNotes = 'Agent confidence overshot — only two competitors in the corridor and supplier moved 15% last week.';

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('reject', $suggestion, data: [
            'misleading' => 'yes',
            'notes' => $longNotes,
        ])
        ->assertHasNoTableActionErrors();

    $fresh = $suggestion->fresh();

    expect($fresh->status)->toBe(Suggestion::STATUS_REJECTED);
    expect($fresh->resolved_by_user_id)->toBe($admin->id);
    expect($fresh->resolved_at)->not->toBeNull();
    expect($fresh->rejection_reason)->toBe($longNotes);

    // D-09 column-canonical resolution per Plan 10-05 Step B — written to the
    // dedicated agent_rejection_feedback column, NOT to evidence.agent_rejection_feedback.
    $feedback = $fresh->agent_rejection_feedback;
    expect($feedback)->toBeArray();
    expect($feedback)->toHaveKeys(['misleading', 'notes', 'rejected_by_user_id', 'rejected_at']);
    expect($feedback['misleading'])->toBe('yes');
    expect($feedback['notes'])->toBe($longNotes);
    expect($feedback['rejected_by_user_id'])->toBe($admin->id);
    expect($feedback['rejected_at'])->toBeString();

    // Defensive: the JSON sub-key path stays empty (column is canonical).
    expect(data_get($fresh->evidence, 'agent_rejection_feedback'))->toBeNull();
});

it('leaves agent_rejection_feedback NULL on successful non-margin_change rejection (standard path unchanged)', function (): void {
    $admin = rejectFeedbackAdminUser();
    $suggestion = makeNonMarginChangeSuggestion('crm_push_failed');

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('reject', $suggestion, data: [
            'rejection_reason' => 'Bitrix endpoint moved; not retrying.',
        ])
        ->assertHasNoTableActionErrors();

    $fresh = $suggestion->fresh();
    expect($fresh->status)->toBe(Suggestion::STATUS_REJECTED);
    expect($fresh->rejection_reason)->toBe('Bitrix endpoint moved; not retrying.');
    expect($fresh->agent_rejection_feedback)->toBeNull();
});

it('leaves agent_rejection_feedback NULL when margin_change has empty agent_run_ids (standard path)', function (): void {
    $admin = rejectFeedbackAdminUser();
    $suggestion = makeMarginChangeSuggestion(['agent_run_ids' => []]);

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('reject', $suggestion, data: [
            'rejection_reason' => 'Manual override — different reason.',
        ])
        ->assertHasNoTableActionErrors();

    $fresh = $suggestion->fresh();
    expect($fresh->status)->toBe(Suggestion::STATUS_REJECTED);
    expect($fresh->rejection_reason)->toBe('Manual override — different reason.');
    expect($fresh->agent_rejection_feedback)->toBeNull();
});

it('rejects submission when notes < 10 chars (validation fails; suggestion stays pending)', function (): void {
    $admin = rejectFeedbackAdminUser();
    $suggestion = makeMarginChangeSuggestion();

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('reject', $suggestion, data: [
            'misleading' => 'yes',
            'notes' => 'short',
        ])
        ->assertHasTableActionErrors(['notes']);

    // Suggestion stays pending — no partial write.
    expect($suggestion->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($suggestion->fresh()->agent_rejection_feedback)->toBeNull();
});

it('rejects submission when misleading radio is missing (validation fails)', function (): void {
    $admin = rejectFeedbackAdminUser();
    $suggestion = makeMarginChangeSuggestion();

    Livewire::actingAs($admin)
        ->test(ListSuggestions::class)
        ->callTableAction('reject', $suggestion, data: [
            // misleading omitted on purpose
            'notes' => 'Plenty of substance in this rejection note — over ten characters easy.',
        ])
        ->assertHasTableActionErrors(['misleading']);

    expect($suggestion->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($suggestion->fresh()->agent_rejection_feedback)->toBeNull();
});

it('round-trips partial and no values for misleading onto agent_rejection_feedback', function (): void {
    $admin = rejectFeedbackAdminUser();

    foreach (['partial', 'no'] as $value) {
        $suggestion = makeMarginChangeSuggestion();
        $notes = "Recording {$value} — rationale here is sufficiently descriptive.";

        Livewire::actingAs($admin)
            ->test(ListSuggestions::class)
            ->callTableAction('reject', $suggestion, data: [
                'misleading' => $value,
                'notes' => $notes,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $suggestion->fresh();
        expect($fresh->status)->toBe(Suggestion::STATUS_REJECTED);
        expect($fresh->agent_rejection_feedback['misleading'])->toBe($value);
        expect($fresh->agent_rejection_feedback['notes'])->toBe($notes);
    }
});
