<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.2 Plan 01 Task 1 — CompetitorFtpFeed model tests.
|--------------------------------------------------------------------------
|
| Asserts: (a) auto-increment integer PK semantics (D-02 — matches screenshot
| IDs 1, 10, 12, ...), (b) SoftDeletes trait active, (c) UNIQUE local_filename
| constraint (D-01), (d) BelongsTo competitor + credential, (e) Carbon casts
| on date columns, (f) consecutive_failures defaults to 0.
*/

it('migrates competitor_ftp_feeds table with expected columns', function (): void {
    expect(Schema::hasTable('competitor_ftp_feeds'))->toBeTrue();

    expect(Schema::hasColumns('competitor_ftp_feeds', [
        'id', 'competitor_id', 'credential_id',
        'remote_filename', 'local_filename', 'format',
        'is_active',
        'last_pulled_at', 'remote_file_date', 'last_pull_status',
        'last_pull_error', 'consecutive_failures',
        'deleted_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('has an auto-increment integer primary key (D-02)', function (): void {
    $feed = new CompetitorFtpFeed();

    expect($feed->getKeyType())->toBe('int')
        ->and($feed->getIncrementing())->toBeTrue();
});

it('uses SoftDeletes — delete sets deleted_at; forceDelete removes the row', function (): void {
    expect(class_uses_recursive(CompetitorFtpFeed::class))
        ->toContain(\Illuminate\Database\Eloquent\SoftDeletes::class);

    $feed = CompetitorFtpFeed::factory()->create();
    $id = $feed->id;

    $feed->delete();
    expect(CompetitorFtpFeed::withTrashed()->find($id)->deleted_at)->not->toBeNull();
    expect(CompetitorFtpFeed::find($id))->toBeNull(); // default scope hides

    CompetitorFtpFeed::withTrashed()->find($id)->forceDelete();
    expect(CompetitorFtpFeed::withTrashed()->find($id))->toBeNull();
});

it('enforces UNIQUE local_filename (D-01)', function (): void {
    CompetitorFtpFeed::factory()->create(['local_filename' => 'nuvias.csv']);

    expect(fn () => CompetitorFtpFeed::factory()->create(['local_filename' => 'nuvias.csv']))
        ->toThrow(QueryException::class);
});

it('belongs to a competitor', function (): void {
    $competitor = Competitor::factory()->create();
    $feed = CompetitorFtpFeed::factory()->create(['competitor_id' => $competitor->id]);

    expect($feed->competitor)->toBeInstanceOf(Competitor::class)
        ->and($feed->competitor->id)->toBe($competitor->id);
});

it('belongs to a credential', function (): void {
    $credential = CompetitorFtpCredential::factory()->create();
    $feed = CompetitorFtpFeed::factory()->create(['credential_id' => $credential->id]);

    expect($feed->credential)->toBeInstanceOf(CompetitorFtpCredential::class)
        ->and($feed->credential->id)->toBe($credential->id);
});

it('casts last_pulled_at and remote_file_date as Carbon instances', function (): void {
    $feed = CompetitorFtpFeed::factory()->create([
        'last_pulled_at' => now(),
        'remote_file_date' => now()->subDays(10),
    ]);

    $reloaded = CompetitorFtpFeed::find($feed->id);
    expect($reloaded->last_pulled_at)->toBeInstanceOf(Carbon::class);
    expect($reloaded->remote_file_date)->toBeInstanceOf(Carbon::class);
});

it('defaults consecutive_failures to 0', function (): void {
    $feed = CompetitorFtpFeed::factory()->create();

    expect($feed->consecutive_failures)->toBe(0);
});
