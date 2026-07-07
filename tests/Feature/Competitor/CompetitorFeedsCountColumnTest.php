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
 * Quick task 260707-f06 — the Feeds column on the Competitor Feeds list was
 * ALWAYS blank. Root cause: TextColumn::make('feeds_count')->counts('ftpFeeds')
 * — ->counts('ftpFeeds') adds withCount('ftpFeeds') which yields the attribute
 * ftp_feeds_count (Laravel snake-cases the ftpFeeds relation), but the column
 * was keyed 'feeds_count', so it read a non-existent attribute → blank.
 *
 * Fix = rename the column KEY to ftp_feeds_count so it matches the withCount
 * alias. This guard asserts the column state now equals the ftpFeeds count
 * (2 and 0) rather than null/blank.
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

    Livewire::test(ListCompetitors::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('ftp_feeds_count', 2, record: $withFeeds)
        ->assertTableColumnStateSet('ftp_feeds_count', 0, record: $noFeeds);
});
