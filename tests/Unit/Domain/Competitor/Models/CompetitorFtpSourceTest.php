<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.1 Plan 01 Task 1 — CompetitorFtpSource model + schema tests.
|--------------------------------------------------------------------------
|
| Asserts: (a) migration applied with all 19 expected columns, (b) ULID PK
| is CHAR(26), (c) `'encrypted'` Eloquent cast actually encrypts at the DB
| layer (raw SELECT does NOT see plaintext), (d) LogsActivity excludes the
| 3 encrypted credential columns from activity_log, (e) cascade FK deletes
| children when parent Competitor is deleted, (f) composite UNIQUE
| (competitor_id, name) enforces one source name per competitor.
|
| All 6 tests run against `meetingstore_ops_testing` MySQL.
*/

it('migrates competitor_ftp_sources table with expected columns', function (): void {
    expect(Schema::hasTable('competitor_ftp_sources'))->toBeTrue();

    $expected = [
        'id',
        'competitor_id',
        'name',
        'protocol',
        'host',
        'port',
        'username',
        'password_encrypted',
        'private_key_encrypted',
        'passphrase_encrypted',
        'base_path',
        'filename_pattern',
        'cron_expression',
        'verify_ssl',
        'is_active',
        'consecutive_failures',
        'last_pulled_at',
        'last_pull_status',
        'last_pull_files_fetched',
        'last_pull_error',
        'created_at',
        'updated_at',
    ];

    expect(Schema::hasColumns('competitor_ftp_sources', $expected))->toBeTrue(
        'competitor_ftp_sources is missing expected columns: '.implode(', ', $expected)
    );
});

it('creates a competitor_ftp_sources row with a 26-char ULID id', function (): void {
    $source = CompetitorFtpSource::factory()->create();

    expect(strlen($source->id))->toBe(26)
        ->and($source->getKeyType())->toBe('string')
        ->and($source->incrementing)->toBeFalse();
});

it('encrypts password at rest via the encrypted Eloquent cast (D-04)', function (): void {
    $source = CompetitorFtpSource::factory()->create([
        'password_encrypted' => 'hunter2-plaintext',
    ]);

    // Raw DB read — encrypted cast is bypassed, ciphertext only.
    $rawRow = DB::table('competitor_ftp_sources')->where('id', $source->id)->first();

    expect($rawRow->password_encrypted)
        ->not->toBe('hunter2-plaintext', 'Raw DB column leaked plaintext password — encrypted cast misconfigured.');

    // Reload via model — cast decrypts.
    $reloaded = CompetitorFtpSource::find($source->id);
    expect($reloaded->password_encrypted)->toBe('hunter2-plaintext');
});

it('does NOT log encrypted credential columns in activity_log (D-09)', function (): void {
    $source = CompetitorFtpSource::factory()->create([
        'password_encrypted' => 'initial-secret',
    ]);

    // Mutate a logged column + an encrypted column.
    $source->update([
        'host' => 'new-host.example.com',
        'password_encrypted' => 'rotated-secret',
    ]);

    $activity = Activity::where('subject_type', CompetitorFtpSource::class)
        ->where('subject_id', $source->id)
        ->latest()
        ->first();

    expect($activity)->not->toBeNull('Expected an activity_log entry for the update.');

    $properties = $activity->properties->toArray();
    $changedFields = array_keys(($properties['attributes'] ?? []) + ($properties['old'] ?? []));

    expect($changedFields)->toContain('host')
        ->and($changedFields)->not->toContain('password_encrypted')
        ->and($changedFields)->not->toContain('private_key_encrypted')
        ->and($changedFields)->not->toContain('passphrase_encrypted');
});

it('cascades on competitor delete', function (): void {
    $competitor = Competitor::factory()->create();
    CompetitorFtpSource::factory()->count(2)->create(['competitor_id' => $competitor->id]);

    expect(CompetitorFtpSource::where('competitor_id', $competitor->id)->count())->toBe(2);

    $competitor->delete();

    expect(CompetitorFtpSource::where('competitor_id', $competitor->id)->count())->toBe(0);
});

it('enforces composite UNIQUE (competitor_id, name)', function (): void {
    $competitor = Competitor::factory()->create();
    CompetitorFtpSource::factory()->create([
        'competitor_id' => $competitor->id,
        'name' => 'weekly_csv',
    ]);

    expect(fn () => CompetitorFtpSource::factory()->create([
        'competitor_id' => $competitor->id,
        'name' => 'weekly_csv',
    ]))->toThrow(QueryException::class);
});
