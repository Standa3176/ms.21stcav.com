<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.2 Plan 01 Task 1 — CompetitorFtpCredential model tests.
|--------------------------------------------------------------------------
|
| Asserts: (a) ULID PK semantics, (b) 'encrypted' Eloquent casts on the 3
| credential columns, (c) HasMany relationship to CompetitorFtpFeed,
| (d) LogsActivity allow-list excludes the 3 encrypted columns (D-09 parity
| with Phase 11.1 CompetitorFtpSource).
*/

it('migrates competitor_ftp_credentials table with expected columns', function (): void {
    expect(Schema::hasTable('competitor_ftp_credentials'))->toBeTrue();

    expect(Schema::hasColumns('competitor_ftp_credentials', [
        'id', 'name', 'protocol', 'host', 'port', 'username',
        'password_encrypted', 'private_key_encrypted', 'passphrase_encrypted',
        'base_path', 'verify_ssl', 'is_active',
        'last_test_at', 'last_test_status', 'last_test_error',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('has a ULID primary key (string + non-incrementing)', function (): void {
    $credential = new CompetitorFtpCredential();

    expect($credential->getKeyType())->toBe('string')
        ->and($credential->getIncrementing())->toBeFalse();

    $created = CompetitorFtpCredential::factory()->create();
    expect(strlen($created->id))->toBe(26);
});

it('encrypts password_encrypted at rest via the encrypted Eloquent cast (D-03)', function (): void {
    $credential = CompetitorFtpCredential::factory()->create([
        'password_encrypted' => 'hunter2-plaintext',
    ]);

    $rawRow = DB::table('competitor_ftp_credentials')->where('id', $credential->id)->first();

    expect($rawRow->password_encrypted)
        ->not->toBe('hunter2-plaintext', 'Raw DB column leaked plaintext password — encrypted cast misconfigured.');

    $reloaded = CompetitorFtpCredential::find($credential->id);
    expect($reloaded->password_encrypted)->toBe('hunter2-plaintext');
});

it('encrypts private_key_encrypted at rest', function (): void {
    $credential = CompetitorFtpCredential::factory()->create([
        'private_key_encrypted' => "-----BEGIN PRIVATE KEY-----\nfoo\n-----END PRIVATE KEY-----\n",
    ]);

    $rawRow = DB::table('competitor_ftp_credentials')->where('id', $credential->id)->first();
    expect($rawRow->private_key_encrypted)->not->toContain('BEGIN PRIVATE KEY');

    $reloaded = CompetitorFtpCredential::find($credential->id);
    expect($reloaded->private_key_encrypted)->toContain('BEGIN PRIVATE KEY');
});

it('encrypts passphrase_encrypted at rest', function (): void {
    $credential = CompetitorFtpCredential::factory()->create([
        'passphrase_encrypted' => 'secret-key-passphrase',
    ]);

    $rawRow = DB::table('competitor_ftp_credentials')->where('id', $credential->id)->first();
    expect($rawRow->passphrase_encrypted)->not->toBe('secret-key-passphrase');

    $reloaded = CompetitorFtpCredential::find($credential->id);
    expect($reloaded->passphrase_encrypted)->toBe('secret-key-passphrase');
});

it('has many feeds (HasMany relationship)', function (): void {
    $credential = CompetitorFtpCredential::factory()->create();
    CompetitorFtpFeed::factory()->count(3)->create(['credential_id' => $credential->id]);

    expect($credential->feeds)->toHaveCount(3)
        ->and($credential->feeds->first())->toBeInstanceOf(CompetitorFtpFeed::class);
});

it('does NOT log encrypted credential columns in activity_log (D-09 parity)', function (): void {
    $credential = CompetitorFtpCredential::factory()->create([
        'password_encrypted' => 'initial-secret',
    ]);

    $credential->update([
        'name' => 'rotated-name',
        'password_encrypted' => 'rotated-secret',
    ]);

    $activity = Activity::where('subject_type', CompetitorFtpCredential::class)
        ->where('subject_id', $credential->id)
        ->latest()
        ->first();

    $properties = $activity?->properties->toArray() ?? [];
    $attributes = $properties['attributes'] ?? [];
    $oldAttrs = $properties['old'] ?? [];

    expect(array_key_exists('name', $attributes))->toBeTrue();
    expect(array_key_exists('password_encrypted', $attributes))->toBeFalse(
        'Encrypted password column leaked into activity_log properties — D-09 violation.'
    );
    expect(array_key_exists('private_key_encrypted', $attributes))->toBeFalse();
    expect(array_key_exists('passphrase_encrypted', $attributes))->toBeFalse();
    expect(array_key_exists('password_encrypted', $oldAttrs))->toBeFalse();
});
