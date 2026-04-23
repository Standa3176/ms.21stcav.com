<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 04 — SuggestionResource kind-specific actions
|--------------------------------------------------------------------------
| approve_new_product_opportunity (visible only for kind=new_product_opportunity)
| replay_auto_create (visible only for kind=auto_create_failed)
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('approve_new_product_opportunity action dispatches ApplySuggestionJob', function (): void {
    Queue::fake();

    $s = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'proposed_at' => now(),
        'evidence' => ['sku' => 'ACME-123', 'supporting_competitors' => 2],
    ]);

    $this->actingAs($this->admin);

    livewire(ListSuggestions::class)
        ->callTableAction('approve_new_product_opportunity', $s)
        ->assertHasNoTableActionErrors();

    expect($s->fresh()->status)->toBe(Suggestion::STATUS_APPROVED);
    Queue::assertPushed(ApplySuggestionJob::class);
});

it('replay_auto_create action dispatches ApplySuggestionJob for failed rows', function (): void {
    Queue::fake();

    $s = Suggestion::create([
        'kind' => 'auto_create_failed',
        'status' => Suggestion::STATUS_PENDING,
        'proposed_at' => now(),
        'evidence' => ['sku' => 'FAIL-456', 'source' => 'CreateWooProductJob'],
    ]);

    $this->actingAs($this->admin);

    livewire(ListSuggestions::class)
        ->callTableAction('replay_auto_create', $s)
        ->assertHasNoTableActionErrors();

    expect($s->fresh()->status)->toBe(Suggestion::STATUS_APPROVED);
    Queue::assertPushed(ApplySuggestionJob::class);
});

it('replay_auto_create action NOT visible for other kinds', function (): void {
    $s = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'proposed_at' => now(),
        'evidence' => ['sku' => 'MARGIN-1'],
    ]);

    $this->actingAs($this->admin);

    livewire(ListSuggestions::class)
        ->assertTableActionHidden('replay_auto_create', $s);
});

it('approve_new_product_opportunity NOT visible for other kinds', function (): void {
    $s = Suggestion::create([
        'kind' => 'auto_create_failed',
        'status' => Suggestion::STATUS_PENDING,
        'proposed_at' => now(),
        'evidence' => ['sku' => 'FAIL-1'],
    ]);

    $this->actingAs($this->admin);

    livewire(ListSuggestions::class)
        ->assertTableActionHidden('approve_new_product_opportunity', $s);
});
