<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;
use App\Domain\Suggestions\Models\Suggestion;

/*
|--------------------------------------------------------------------------
| Quick task 260711-aps Task 1 — --min-competitors / --max-competitors
|--------------------------------------------------------------------------
| The Suggestion-walk path gains an inclusive competitor-count band filter on
| evidence.supporting_competitors. Default (null/null) = no filter (backward
| compatible). Driver-portable (SQLite json_extract vs MariaDB
| JSON_UNQUOTE(JSON_EXTRACT), cast to integer — memory sqlite-mariadb-strict-trap).
|
| The command's supplier walk uses a live mysqli connection that cannot be faked
| in-process, so the competitor filter is covered via the pure, testable
| pendingOpportunitySuggestionsQuery() seam (the exact query the walk drives),
| seeded against the local SQLite suggestions table.
*/

function seedOpportunity(int $competitors, string $sku): Suggestion
{
    return Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => 'pending',
        'evidence' => ['sku' => $sku, 'supporting_competitors' => $competitors],
        'proposed_at' => now(),
    ]);
}

/** Pull the evidence.sku values the query would drive into the walk. */
function walkedSkus(?int $min, ?int $max): array
{
    $command = app(DraftFromSuggestionsCommand::class);

    return $command->pendingOpportunitySuggestionsQuery($min, $max)
        ->get()
        ->map(fn ($row) => (string) (json_decode((string) $row->evidence, true)['sku'] ?? ''))
        ->sort()
        ->values()
        ->all();
}

it('selects ONLY the 2- and 3-competitor SKUs with --min-competitors=2 --max-competitors=3', function (): void {
    seedOpportunity(1, 'ONE');
    seedOpportunity(2, 'TWO');
    seedOpportunity(3, 'THREE');
    seedOpportunity(4, 'FOUR');

    expect(walkedSkus(2, 3))->toBe(['THREE', 'TWO']);
});

it('excludes the 1- and 4-competitor SKUs from the 2..3 band', function (): void {
    seedOpportunity(1, 'ONE');
    seedOpportunity(2, 'TWO');
    seedOpportunity(3, 'THREE');
    seedOpportunity(4, 'FOUR');

    expect(walkedSkus(2, 3))
        ->not->toContain('ONE')
        ->not->toContain('FOUR');
});

it('applies no filter (backward compatible) when both bounds are null', function (): void {
    seedOpportunity(1, 'ONE');
    seedOpportunity(2, 'TWO');
    seedOpportunity(3, 'THREE');
    seedOpportunity(4, 'FOUR');

    expect(walkedSkus(null, null))->toBe(['FOUR', 'ONE', 'THREE', 'TWO']);
});

it('honours a lower bound alone (min only, no upper cap)', function (): void {
    seedOpportunity(1, 'ONE');
    seedOpportunity(2, 'TWO');
    seedOpportunity(3, 'THREE');
    seedOpportunity(4, 'FOUR');

    expect(walkedSkus(3, null))->toBe(['FOUR', 'THREE']);
});

it('only walks pending new_product_opportunity rows', function (): void {
    seedOpportunity(2, 'PENDING');
    Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => 'applied',
        'evidence' => ['sku' => 'APPLIED', 'supporting_competitors' => 2],
        'proposed_at' => now(),
    ]);
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => 'pending',
        'evidence' => ['sku' => 'MARGIN', 'supporting_competitors' => 2],
        'proposed_at' => now(),
    ]);

    expect(walkedSkus(2, 3))->toBe(['PENDING']);
});
