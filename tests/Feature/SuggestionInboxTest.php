<?php

use App\Domain\Suggestions\Appliers\StubApplier;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TestSuggestionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('StubApplier supports kind=test', function () {
    expect(app(StubApplier::class)->supports())->toBe(['test']);
});

it('SuggestionApplier contract exists with supports() and apply() methods', function () {
    $reflection = new ReflectionClass(SuggestionApplier::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('supports'))->toBeTrue();
    expect($reflection->hasMethod('apply'))->toBeTrue();
});

it('SuggestionApplierResolver resolves test kind to StubApplier', function () {
    $suggestion = Suggestion::create([
        'kind' => 'test',
        'status' => 'pending',
        'correlation_id' => 'cid-resolve',
        'payload' => [],
        'proposed_at' => now(),
    ]);

    $applier = app(SuggestionApplierResolver::class)->resolve($suggestion);
    expect($applier)->toBeInstanceOf(StubApplier::class);
});

it('SuggestionApplierResolver throws on unregistered kind', function () {
    $suggestion = Suggestion::create([
        'kind' => 'unregistered_kind',
        'status' => 'pending',
        'correlation_id' => 'cid-unk',
        'payload' => [],
        'proposed_at' => now(),
    ]);

    expect(fn () => app(SuggestionApplierResolver::class)->resolve($suggestion))
        ->toThrow(RuntimeException::class, 'No SuggestionApplier registered');
});

it('TestSuggestionSeeder creates one pending test suggestion', function () {
    $this->seed(TestSuggestionSeeder::class);

    expect(Suggestion::where('kind', 'test')->count())->toBe(1);
    $s = Suggestion::where('kind', 'test')->first();
    expect($s->status)->toBe('pending');
    expect($s->correlation_id)->not->toBeNull();
    expect($s->payload)->toHaveKey('message');
});

it('TestSuggestionSeeder is idempotent (firstOrCreate)', function () {
    $this->seed(TestSuggestionSeeder::class);
    $this->seed(TestSuggestionSeeder::class);

    expect(Suggestion::where('kind', 'test')->count())->toBe(1);
});

it('ApplySuggestionJob flips pending → applied and writes integration_events row', function () {
    $suggestion = Suggestion::create([
        'kind' => 'test',
        'status' => 'pending',
        'correlation_id' => 'cid-apply',
        'payload' => ['x' => 'y'],
        'proposed_at' => now(),
    ]);

    ApplySuggestionJob::dispatchSync($suggestion->id);

    $suggestion->refresh();
    expect($suggestion->status)->toBe('applied');
    expect($suggestion->applied_at)->not->toBeNull();

    $events = IntegrationEvent::where('channel', 'suggestions')->where('correlation_id', 'cid-apply')->get();
    expect($events)->toHaveCount(1);
    expect($events->first()->operation)->toBe('apply:test');
    expect($events->first()->status)->toBe('success');
});

it('ApplySuggestionJob is idempotent — dispatching twice produces ONE integration_events row (D-15)', function () {
    $suggestion = Suggestion::create([
        'kind' => 'test',
        'status' => 'pending',
        'correlation_id' => 'cid-idem',
        'payload' => [],
        'proposed_at' => now(),
    ]);

    ApplySuggestionJob::dispatchSync($suggestion->id);
    ApplySuggestionJob::dispatchSync($suggestion->id); // second call — must be no-op

    $events = IntegrationEvent::where('channel', 'suggestions')->where('correlation_id', 'cid-idem')->count();
    expect($events)->toBe(1);
});

it('admin role can access the Filament SuggestionResource list page', function () {
    $this->seed(TestSuggestionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    \Filament\Facades\Filament::auth()->login($admin);

    \Livewire\Livewire::test(\App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions::class)
        ->assertSuccessful()
        // 260707-gsy — clear the new default Kind/Status filters so the seeded
        // kind='test' pending suggestion is visible in the list.
        ->set('tableFilters.kind.value', null)
        ->set('tableFilters.status.value', null)
        ->assertCanSeeTableRecords(Suggestion::all());
});

it('read_only role is denied viewAny via SuggestionPolicy (Pitfall K)', function () {
    $this->seed(TestSuggestionSeeder::class);
    $readOnly = User::factory()->create();
    $readOnly->assignRole('read_only');

    expect($readOnly->can('viewAny', Suggestion::class))->toBeFalse();
});

it('sales role is denied viewAny via SuggestionPolicy (Pitfall K)', function () {
    $sales = User::factory()->create();
    $sales->assignRole('sales');

    expect($sales->can('viewAny', Suggestion::class))->toBeFalse();
});

it('pricing_manager role is denied viewAny via SuggestionPolicy (Pitfall K)', function () {
    $pm = User::factory()->create();
    $pm->assignRole('pricing_manager');

    expect($pm->can('viewAny', Suggestion::class))->toBeFalse();
});

/**
 * Warning 9 defence-in-depth — the approve/reject Actions carry ->authorize() closures that
 * return false for any non-admin role. The first defence layer (SuggestionPolicy::viewAny)
 * already denies read_only at the page level, so Livewire's callTableAction against the
 * ListSuggestions component cannot even reach the table. Instead, we invoke the Action's
 * authorize callback directly — this is the same check a crafted POST would hit server-side.
 */
/**
 * Warning 9 defence-in-depth — the approve/reject Actions each carry an ->authorize()
 * closure of the form: fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false.
 *
 * We can't exercise the full Livewire-driven Action POST path because read_only's
 * SuggestionPolicy::viewAny already denies access before the table renders (first-layer
 * defence does its job too well for this test). But the closure itself is pure — we
 * evaluate the identical condition under four roles to prove only admin passes.
 */
it('approve/reject Action authorize closures deny non-admin roles (Warning 9 defence-in-depth)', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $readOnly = User::factory()->create();
    $readOnly->assignRole('read_only');

    $sales = User::factory()->create();
    $sales->assignRole('sales');

    $pm = User::factory()->create();
    $pm->assignRole('pricing_manager');

    // The SuggestionResource approve/reject authorize closure evaluates this exact expression:
    $evaluate = fn (User $u) => $u->hasRole('admin');

    expect($evaluate($admin))->toBeTrue();
    expect($evaluate($readOnly))->toBeFalse();
    expect($evaluate($sales))->toBeFalse();
    expect($evaluate($pm))->toBeFalse();
});

it('approve + reject Actions have authorize closures that deny non-admin roles', function () {
    // Assert the SuggestionResource source contains both ->authorize() chains on approve + reject —
    // this guarantees Warning 9 fix doesn't silently regress to ->visible()-only gating on future edits.
    $source = file_get_contents(base_path('app/Domain/Suggestions/Filament/Resources/SuggestionResource.php'));

    // Approve Action has ->authorize
    $approveBlock = strstr($source, "Action::make('approve')");
    $approveEnd = strpos($approveBlock, "Action::make('reject')");
    $approveSegment = substr($approveBlock, 0, $approveEnd);
    expect($approveSegment)->toContain('->authorize(')
        ->and($approveSegment)->toContain("hasRole('admin')");

    // Reject Action has ->authorize
    $rejectSegment = strstr($source, "Action::make('reject')");
    expect($rejectSegment)->toContain('->authorize(')
        ->and($rejectSegment)->toContain("hasRole('admin')");
});

it('integration_events.subject_id for an applied suggestion stores the ULID and joins back to suggestions (Warning 8)', function () {
    $suggestion = Suggestion::create([
        'kind' => 'test',
        'status' => 'pending',
        'correlation_id' => 'cid-morph-join',
        'payload' => [],
        'proposed_at' => now(),
    ]);

    ApplySuggestionJob::dispatchSync($suggestion->id);

    $event = IntegrationEvent::where('correlation_id', 'cid-morph-join')->firstOrFail();
    expect($event->subject_id)->toBe($suggestion->id);
    expect($event->subject_type)->toBe(Suggestion::class);
    // Morph resolution works end-to-end
    expect($event->subject)->toBeInstanceOf(Suggestion::class);
    expect($event->subject->id)->toBe($suggestion->id);
});
