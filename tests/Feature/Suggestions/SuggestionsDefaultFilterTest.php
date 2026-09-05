<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260905-po7 — Suggestions opens on what Auto-create can act on
|--------------------------------------------------------------------------
|
| The list used to open on every pending row, ~96% of which are competitor
| -only SKUs no supplier carries. The bulk "Auto-create selected" action
| filters those out silently, so selecting a screenful and dispatching
| produced nothing at all — an operator lost two days (2026-08-30/31)
| believing the button was broken.
|
| Two things are pinned here:
|   1. on_supplier_db DEFAULTS to 'yes' — the list opens on sourceable rows.
|   2. The sidebar badge counts THAT SAME SET, so the number and the screen
|      behind it agree. It previously counted the narrower high-confidence
|      (>= 3 competitors) set.
|
| Fixture: 3 pending new_product_opportunity rows.
|   S1 sourceable, 4 competitors  → in the default list AND high-confidence
|   S2 sourceable, 1 competitor   → in the default list, NOT high-confidence
|   N1 not sourceable, 5 competitors → in neither
*/

function po7Admin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user->fresh();
}

function po7Suggestion(string $sku, int $competitors, bool $sourceable): Suggestion
{
    if ($sourceable) {
        DB::table('supplier_sku_cache')->insert(['sku' => strtolower(trim($sku))]);
    }

    return Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'po7-'.$sku,
        'payload' => [],
        'evidence' => [
            'sku' => $sku,
            'brand' => 'Barco',
            'supporting_competitors' => $competitors,
            'competitor_sightings' => [
                ['name' => 'Ballicom', 'price_gross_pennies' => 1000],
            ],
        ],
        'proposed_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->s1 = po7Suggestion('PO7-HIGH', 4, true);
    $this->s2 = po7Suggestion('PO7-LOW', 1, true);
    $this->n1 = po7Suggestion('PO7-ORPHAN', 5, false);
});

it('opens on sourceable rows only — the competitor-only orphan is hidden', function (): void {
    $this->actingAs(po7Admin());

    Livewire::test(ListSuggestions::class)
        ->assertCanSeeTableRecords([$this->s1, $this->s2])
        ->assertCanNotSeeTableRecords([$this->n1]);
});

it('still reaches the competitor-only rows when the operator clears the default', function (): void {
    $this->actingAs(po7Admin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('on_supplier_db', null)
        ->assertCanSeeTableRecords([$this->s1, $this->s2, $this->n1]);
});

it('counts the sidebar badge over the same set the list opens on', function (): void {
    // 2 sourceable pending — NOT 1 (high-confidence) and NOT 3 (raw pending).
    expect(SuggestionResource::getNavigationBadge())->toBe('2')
        ->and(Suggestion::query()->sourceablePending()->count())->toBe(2)
        ->and(Suggestion::query()->highConfidenceSourceable()->count())->toBe(1);
});

it('keeps the high-confidence tier visible in the badge tooltip', function (): void {
    expect(SuggestionResource::getNavigationBadgeTooltip())
        ->toBe('1 high-confidence • 2 sourceable • 3 raw');
});

it('still composes the competitor gate onto the sourceable predicate', function (): void {
    // scopeHighConfidenceSourceable now delegates status/kind/feed-membership
    // to scopeSourceablePending — prove the >= 3 gate survived the refactor.
    expect(Suggestion::query()->highConfidenceSourceable()->pluck('correlation_id')->all())
        ->toBe(['po7-PO7-HIGH']);
});

it('does not hide other suggestion kinds behind the sourceable default', function (): void {
    // "On supplier DB" is a new_product_opportunity concept. A margin_change
    // row is not "unsourceable" — the question does not apply — so the default
    // must not delete it when the operator clears the Kind filter to look.
    $margin = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'po7-margin',
        'payload' => [],
        'evidence' => ['sku' => 'PO7-NOT-IN-FEED'],
        'proposed_at' => now(),
    ]);

    $this->actingAs(po7Admin());

    Livewire::test(ListSuggestions::class)
        ->set('tableFilters.kind.value', null)
        ->assertCanSeeTableRecords([$margin, $this->s1])
        ->assertCanNotSeeTableRecords([$this->n1]);
});
