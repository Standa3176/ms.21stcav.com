<?php

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('SuggestionResource::getEloquentQuery() eager-loads resolvedByUser', function () {
    $query = SuggestionResource::getEloquentQuery();
    $eagerLoads = $query->getEagerLoads();

    expect($eagerLoads)->toHaveKey('resolvedByUser');
});

it('rendering N suggestions executes a BOUNDED number of queries (not N + N)', function () {
    // Seed 10 resolved suggestions — each has a resolvedByUser belongsTo
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    for ($i = 0; $i < 10; $i++) {
        Suggestion::create([
            'kind' => 'test',
            'status' => 'applied',
            'correlation_id' => "cid-{$i}",
            'payload' => ['n' => $i],
            'proposed_at' => now(),
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => now(),
            'applied_at' => now(),
        ]);
    }

    \Filament\Facades\Filament::auth()->login($admin);

    DB::flushQueryLog();
    DB::enableQueryLog();

    \Livewire\Livewire::test(ListSuggestions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Suggestion::all());

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // With eager loading: ~3-6 queries total (suggestions select + 1 users IN-clause + filament overhead).
    // Without eager loading: 10 + N queries (one per row to fetch resolvedByUser).
    // Bound is generous to allow filament internal queries; the WIN condition is "not N-per-row".
    expect(count($queries))->toBeLessThan(15)
        ->and(count($queries))->toBeGreaterThan(0);

    // Belt-and-braces: assert no individual query selects a single user by id (typical N+1 shape)
    $selectUserById = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'from `users`') &&
        str_contains($q['query'], '`id` = ?')
    )->count();
    expect($selectUserById)->toBe(0);  // eager load uses WHERE id IN (...) instead
});
