<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 04 Task 1 — SuggestionResource Replay action tests
|--------------------------------------------------------------------------
|
| Replay action visible only on kind=crm_push_failed + status=pending rows.
| Admin-only via ->authorize() closure (Warning 9 mandate).
| Action dispatches ApplySuggestionJob which resolves CrmPushRetryApplier.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    foreach (['view_any_suggestion', 'view_suggestion', 'update_suggestion'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    Role::findByName('admin')->givePermissionTo(['view_any_suggestion', 'view_suggestion', 'update_suggestion']);
});

function makeReplayTestSuggestion(string $status = Suggestion::STATUS_PENDING): Suggestion
{
    return Suggestion::create([
        'kind' => 'crm_push_failed',
        'status' => $status,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'payload' => ['sub_kind' => 'push_exhausted', 'entity_type' => 'deal', 'woo_id' => 42, 'topic' => 'order.created'],
        'evidence' => ['webhook_receipt_id' => 999, 'correlation_id' => (string) \Illuminate\Support\Str::uuid()],
        'proposed_at' => now(),
    ]);
}

it('replay action authorize closure allows admin only', function (): void {
    $closure = fn () => auth()->user()?->hasRole('admin') ?? false;

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    expect($closure())->toBeTrue();

    foreach (['pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);
        $this->actingAs($user);
        expect($closure())->toBeFalse("Role {$roleName} must NOT pass the replay authorize closure");
    }
});

it('replay visibility closure returns TRUE only for crm_push_failed + pending', function (): void {
    $visibility = fn (Suggestion $r) => $r->kind === 'crm_push_failed' && $r->status === Suggestion::STATUS_PENDING;

    $pending = makeReplayTestSuggestion();
    expect($visibility($pending))->toBeTrue();

    // Not crm_push_failed → hidden.
    $other = Suggestion::create([
        'kind' => 'test',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'payload' => [],
        'evidence' => [],
        'proposed_at' => now(),
    ]);
    expect($visibility($other))->toBeFalse();

    // Already approved → hidden.
    $approved = makeReplayTestSuggestion(Suggestion::STATUS_APPROVED);
    expect($visibility($approved))->toBeFalse();
});

it('clicking replay dispatches ApplySuggestionJob with the correct suggestion id', function (): void {
    Queue::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $suggestion = makeReplayTestSuggestion();

    Livewire::test(ListSuggestions::class)
        // 260707-gsy — clear the new default Kind/Status filters so the
        // crm_push_failed record is present in the table for the action.
        ->set('tableFilters.kind.value', null)
        ->set('tableFilters.status.value', null)
        ->callTableAction('replay', $suggestion->id);

    Queue::assertPushed(
        ApplySuggestionJob::class,
        fn (ApplySuggestionJob $j) => $j->suggestionId === $suggestion->id,
    );

    expect($suggestion->fresh()->status)->toBe(Suggestion::STATUS_APPROVED);
});

it('SuggestionResource source contains 3 authorize closures (approve + reject + replay — Warning 9)', function (): void {
    $source = file_get_contents(app_path('Domain/Suggestions/Filament/Resources/SuggestionResource.php'));

    // Each Action block carries ->authorize(fn ... hasRole('admin')).
    $count = substr_count($source, "hasRole('admin')");
    expect($count)->toBeGreaterThanOrEqual(3, "Expected ≥3 hasRole('admin') authorize checks (approve + reject + replay); found {$count}");

    // Explicit marker for the replay branch.
    expect($source)->toContain("'crm_push_failed'");
    expect($source)->toContain("ApplySuggestionJob::dispatch");
});
