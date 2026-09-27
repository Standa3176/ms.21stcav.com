<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorResource;
use App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages\ListCompetitors;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260927-oje — the Competitor Feeds "SKUs" column
|--------------------------------------------------------------------------
|
| 'Feeds' counts feed files CONFIGURED. Nothing on the page counted SKUs that
| actually arrived, which is how screenmoove went absent for the whole of
| August with a green-looking row (onedirect -44%, avitdirect -43% in the same
| reading on 2026-09-26). A configured feed delivering zero SKUs must be
| visible as a failure, not as a quiet blank.
|
| Two things make this number honest, and both are pinned below:
|
|   DISTINCT — competitor_prices is append-only with UNIQUE(competitor_id, sku,
|     recorded_at), so one SKU seen on 20 scrapes is 20 rows. A row count would
|     read as coverage 20× too high, and would RISE as a feed stalled and got
|     re-ingested.
|   WINDOWED — rows are NEVER pruned (COMP-07), so an all-time count answers
|     "did this feed ever work". Only the window says whether it works now.
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    // The counts are cached for 10 minutes across the whole table, so a stale
    // entry from a previous test would silently satisfy every assertion here.
    Cache::flush();
});

it('counts DISTINCT SKUs, not price rows', function (): void {
    $competitor = Competitor::factory()->create();

    // Three rows, two SKUs — the same SKU priced on two different days.
    CompetitorPrice::factory()->for($competitor)->forSku('SKU-A')->recordedAt(now()->subDay())->create();
    CompetitorPrice::factory()->for($competitor)->forSku('SKU-A')->recordedAt(now()->subDays(2))->create();
    CompetitorPrice::factory()->for($competitor)->forSku('SKU-B')->recordedAt(now()->subDay())->create();

    expect(CompetitorResource::skuCountsByCompetitor()[$competitor->id] ?? null)->toBe(2);
});

it('ignores rows older than the window', function (): void {
    config()->set('competitor.sku_count_window_days', 30);
    $competitor = Competitor::factory()->create();

    CompetitorPrice::factory()->for($competitor)->forSku('IN-WINDOW')->recordedAt(now()->subDays(3))->create();
    // The screenmoove case: still in the table, months old, must not count.
    CompetitorPrice::factory()->for($competitor)->forSku('LONG-DEAD')->recordedAt(now()->subDays(60))->create();

    expect(CompetitorResource::skuCountsByCompetitor()[$competitor->id] ?? null)->toBe(1);
});

it('keeps each competitor\'s SKUs to itself', function (): void {
    $a = Competitor::factory()->create();
    $b = Competitor::factory()->create();

    CompetitorPrice::factory()->for($a)->forSku('SHARED')->create();
    CompetitorPrice::factory()->for($a)->forSku('ONLY-A')->create();
    CompetitorPrice::factory()->for($b)->forSku('SHARED')->create();

    $counts = CompetitorResource::skuCountsByCompetitor();

    expect($counts[$a->id])->toBe(2)
        ->and($counts[$b->id])->toBe(1);
});

it('renders the count in the table, and 0 rather than blank for a silent feed', function (): void {
    $live = Competitor::factory()->create();
    CompetitorPrice::factory()->for($live)->forSku('LIVE-1')->create();
    CompetitorPrice::factory()->for($live)->forSku('LIVE-2')->create();

    // Configured but delivering nothing — the failure this column exists for.
    $silent = Competitor::factory()->create();

    Livewire::test(ListCompetitors::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('sku_count', 2, record: $live->getKey())
        ->assertTableColumnStateSet('sku_count', 0, record: $silent->getKey());
});

it('colours a zero-SKU feed red and a live one neutral', function (): void {
    // Colour is the whole point: a 0 that looks like every other cell is the
    // state we were already in.
    $live = Competitor::factory()->create();
    CompetitorPrice::factory()->for($live)->forSku('LIVE-1')->create();
    $silent = Competitor::factory()->create();

    // Reach the column through a MOUNTED page — Filament builds the Table in
    // the Livewire boot cycle, so app(ListCompetitors::class)->getTable() hits
    // an uninitialised typed property.
    $table = Livewire::test(ListCompetitors::class)->instance()->getTable();

    $column = collect($table->getColumns())->first(fn ($c): bool => $c->getName() === 'sku_count');

    expect($column)->not->toBeNull()
        ->and($column->getLabel())->toBe('SKUs');

    // Evaluate the colour closure the way Filament does — bound to the record,
    // handed that cell's resolved state.
    $colourFor = function (Competitor $record) use ($column): string {
        $bound = $column->record($record);

        return $bound->getColor($bound->getState());
    };

    expect($colourFor($silent))->toBe('danger')
        ->and($colourFor($live))->toBe('gray');
});

it('resolves the whole table in ONE query, not one per row', function (): void {
    // Per-row COUNT(DISTINCT sku) is the ftp_feeds_count pattern, and it is
    // fine there because competitor_ftp_feeds has ~5 rows. Here it is hundreds
    // of thousands per competitor (screenmoove alone: 182,868 rows in 30 days),
    // so the grouped-and-cached shape is load-bearing.
    Competitor::factory()->count(4)->create()->each(function (Competitor $c): void {
        CompetitorPrice::factory()->for($c)->forSku('S-'.$c->id)->create();
    });

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = CompetitorResource::skuCountsByCompetitor();
    $countAfterFirst = $queries;
    // Second call must be served from cache.
    $second = CompetitorResource::skuCountsByCompetitor();

    expect($countAfterFirst)->toBe(1)
        ->and($queries)->toBe(1)
        ->and($second)->toBe($first);
});

it('reads the window from config so it can be widened without a deploy', function (): void {
    expect(config('competitor.sku_count_window_days'))->toBe(30);

    $source = file_get_contents(base_path('app/Domain/Competitor/Filament/Resources/CompetitorResource.php'));
    expect($source)->toContain("config('competitor.sku_count_window_days', 30)")
        // The cache key must carry the window, or changing it would serve the
        // old window's numbers for 10 minutes.
        ->and($source)->toContain('"competitor:sku_counts:{$days}d"');
});
