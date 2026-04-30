<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 05 Task 2 — AgentRunRejectionInboxPage feature test
|--------------------------------------------------------------------------
|
| Locks the access matrix + query filter + bulk action behaviour for the
| /admin/agent-runs/rejection-inbox triage page (D-09 + CONTEXT Claude's
| Discretion §"Filament page route").
|
| Cases:
|   1. admin can access (Filament returns 200)
|   2. pricing_manager can access (Filament returns 200)
|   3. sales role gets 403 (read-side gate via canAccess)
|   4. read_only role gets 403 (same)
|   5. Query surfaces ONLY rejected margin_change rows with non-null
|      agent_rejection_feedback (excludes approved + non-margin_change +
|      NULL-feedback rows)
|   6. mark_triaged bulk action writes triaged_at + triage_note +
|      triaged_by_user_id onto the agent_rejection_feedback JSON column
|
| Uses Suggestion::create() directly (no factory exists) per
| MarginChangeEvidenceContractTest precedent (Plan 10-04). Roles are seeded
| via Role::firstOrCreate (matches Phase 8 AgentRunResourcePolicyTest pattern).
*/

use App\Domain\Suggestions\Models\Suggestion;
use App\Filament\Pages\AgentRunRejectionInboxPage;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function inboxRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

function makeRejectedAgentEnrichedSuggestion(string $sku = 'INBOX-MATCH', string $misleading = 'yes', ?string $notes = null): Suggestion
{
    return Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_REJECTED,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 1800],
        'evidence' => [
            'sku' => $sku,
            'agent_run_ids' => ['01HX'.strtoupper(Str::random(22))],
            'agent_confidence_0_to_100' => 62,
            'agent_proposed_band_min_bps' => 1750,
            'agent_proposed_band_max_bps' => 1900,
            'proposed_margin_bps' => 1800,
        ],
        'agent_rejection_feedback' => [
            'misleading' => $misleading,
            'notes' => $notes ?? 'Sample rejection feedback note for the inbox visibility test.',
            'rejected_by_user_id' => 1,
            'rejected_at' => now()->toIso8601String(),
        ],
        'rejection_reason' => $notes ?? 'Sample rejection feedback note for the inbox visibility test.',
        'proposed_at' => now()->subDay(),
        'resolved_at' => now(),
        'resolved_by_user_id' => 1,
    ]);
}

it('admin can access the AgentRunRejectionInboxPage', function (): void {
    $admin = inboxRoleUser('admin');
    $this->actingAs($admin);

    expect(AgentRunRejectionInboxPage::canAccess())->toBeTrue();

    Livewire::test(AgentRunRejectionInboxPage::class)->assertSuccessful();
});

it('pricing_manager can access the AgentRunRejectionInboxPage', function (): void {
    $pm = inboxRoleUser('pricing_manager');
    $this->actingAs($pm);

    expect(AgentRunRejectionInboxPage::canAccess())->toBeTrue();

    Livewire::test(AgentRunRejectionInboxPage::class)->assertSuccessful();
});

it('sales role is denied access (403)', function (): void {
    $sales = inboxRoleUser('sales');
    $this->actingAs($sales);

    expect(AgentRunRejectionInboxPage::canAccess())->toBeFalse();

    Livewire::test(AgentRunRejectionInboxPage::class)->assertForbidden();
});

it('read_only role is denied access (403)', function (): void {
    $ro = inboxRoleUser('read_only');
    $this->actingAs($ro);

    expect(AgentRunRejectionInboxPage::canAccess())->toBeFalse();

    Livewire::test(AgentRunRejectionInboxPage::class)->assertForbidden();
});

it('only rejected margin_change rows with non-null agent_rejection_feedback show up', function (): void {
    $admin = inboxRoleUser('admin');
    $this->actingAs($admin);

    // ── Match: rejected + margin_change + non-null feedback ──
    $matching = makeRejectedAgentEnrichedSuggestion('MATCH-SKU-INCLUDED', 'yes');

    // ── Exclude 1: APPROVED status (not rejected) ──
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [],
        'evidence' => ['sku' => 'EXCLUDE-APPROVED'],
        'agent_rejection_feedback' => [
            'misleading' => 'no',
            'notes' => 'should be excluded — wrong status',
            'rejected_by_user_id' => 1,
            'rejected_at' => now()->toIso8601String(),
        ],
        'proposed_at' => now(),
    ]);

    // ── Exclude 2: kind != margin_change ──
    Suggestion::create([
        'kind' => 'crm_push_failed',
        'status' => Suggestion::STATUS_REJECTED,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['woo_id' => 999],
        'evidence' => ['sku' => 'EXCLUDE-CRM'],
        'agent_rejection_feedback' => [
            'misleading' => 'yes',
            'notes' => 'should be excluded — wrong kind',
            'rejected_by_user_id' => 1,
            'rejected_at' => now()->toIso8601String(),
        ],
        'proposed_at' => now(),
        'resolved_at' => now(),
        'resolved_by_user_id' => 1,
    ]);

    // ── Exclude 3: feedback NULL (legacy / pre-D-09 rejection) ──
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_REJECTED,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [],
        'evidence' => ['sku' => 'EXCLUDE-NULL-FEEDBACK'],
        'agent_rejection_feedback' => null,
        'proposed_at' => now(),
        'resolved_at' => now(),
        'resolved_by_user_id' => 1,
    ]);

    Livewire::test(AgentRunRejectionInboxPage::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords(Suggestion::query()
            ->whereIn('evidence->sku', ['EXCLUDE-APPROVED', 'EXCLUDE-CRM', 'EXCLUDE-NULL-FEEDBACK'])
            ->get()
            ->all());
});

it('mark_triaged bulk action writes triaged_at + triage_note + triaged_by_user_id', function (): void {
    $admin = inboxRoleUser('admin');
    $this->actingAs($admin);

    $row = makeRejectedAgentEnrichedSuggestion('TRIAGE-TARGET', 'partial');

    Livewire::test(AgentRunRejectionInboxPage::class)
        ->callTableBulkAction('mark_triaged', [$row], data: [
            'triage_note' => 'Prompt edit shipped in 87fed12; fixture added.',
        ])
        ->assertHasNoTableBulkActionErrors();

    $fresh = $row->fresh();
    $feedback = $fresh->agent_rejection_feedback;

    expect($feedback)->toBeArray();
    expect($feedback)->toHaveKeys(['misleading', 'notes', 'rejected_by_user_id', 'rejected_at',
        'triaged_at', 'triage_note', 'triaged_by_user_id']);
    expect($feedback['triage_note'])->toBe('Prompt edit shipped in 87fed12; fixture added.');
    expect($feedback['triaged_by_user_id'])->toBe($admin->id);
    expect($feedback['triaged_at'])->toBeString();
    // Pre-existing keys preserved (not clobbered by the merge):
    expect($feedback['misleading'])->toBe('partial');
});
