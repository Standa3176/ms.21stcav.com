<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages\ListCompetitors;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Quick task 260707-fkx — the Feeds column was STILL blank on prod (MariaDB)
 * after the 260707-f06 key rename (b872801 deployed). Root cause: the column's
 * ->counts('ftpFeeds') aggregate adds a withCount subquery which — combined
 * with the table's ->modifyQueryUsing(withMax('ftpFeeds','remote_file_date'))
 * modifier — populated ftp_feeds_count on SQLite (tests green) but NOT on
 * MariaDB (prod) → column read null → blank. The recurring SQLite↔MariaDB
 * divergence, invisible to a SQLite test suite.
 *
 * Fix = resolve the count with an engine-independent per-row relation count:
 * ->state(fn (Competitor $record) => $record->ftpFeeds()->count()). A plain
 * COUNT per row behaves identically on SQLite + MariaDB and cannot be affected
 * by aggregate-select/subquery behaviour. This guard asserts the column state
 * equals the ftpFeeds count (2 and 0) via the ->state closure.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('Feeds column state equals the ftpFeeds count (2 / 0), not blank', function (): void {
    $withFeeds = Competitor::factory()->create();
    CompetitorFtpFeed::factory()->count(2)->for($withFeeds)->create();

    $noFeeds = Competitor::factory()->create();

    // Pass the record KEY (not the model instance): Filament resolves the record
    // through the table query pipeline, then the column's ->state closure runs
    // $record->ftpFeeds()->count() — an engine-independent per-row COUNT that no
    // longer depends on any aggregate-select attribute being present on the query.
    Livewire::test(ListCompetitors::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('ftp_feeds_count', 2, record: $withFeeds->getKey())
        ->assertTableColumnStateSet('ftp_feeds_count', 0, record: $noFeeds->getKey());
});
