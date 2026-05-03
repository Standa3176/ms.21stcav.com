<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Enums\IntegrationTestStatus;
use App\Domain\Integrations\Models\IntegrationCredential;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Phase 09.1 Plan 01 Task 1 Tests 1.1, 1.2, 1.3.
 *
 * Verifies the IntegrationCredential model contract:
 *   - 'encrypted:array' cast round-trips array payloads (Test 1.1)
 *   - LogsActivity allow-list excludes payload_encrypted (Test 1.2)
 *   - UNIQUE(kind) constraint enforced at the DB level (Test 1.3)
 */

it('encrypts payload_encrypted via the encrypted:array cast and round-trips on reload', function (): void {
    $secret = 'sk-test-secret-' . bin2hex(random_bytes(8));
    $row = IntegrationCredential::factory()->kind(IntegrationCredentialKind::AnthropicApi)
        ->create(['payload_encrypted' => ['api_key' => $secret]]);

    $reloaded = $row->fresh();

    expect($reloaded->payload_encrypted)
        ->toBeArray()
        ->toMatchArray(['api_key' => $secret]);

    $rawCipher = (string) DB::table('integration_credentials')
        ->where('id', $row->id)
        ->value('payload_encrypted');

    expect($rawCipher)
        ->not->toBe('')
        ->not->toContain($secret, 'Raw column value must be ciphertext, not plaintext');
});

it('LogsActivity records structural changes but never the payload_encrypted ciphertext (D-14)', function (): void {
    $secret = 'sk-secret-do-not-leak-' . bin2hex(random_bytes(8));
    $row = IntegrationCredential::factory()
        ->kind(IntegrationCredentialKind::AnthropicApi)
        ->create([
            'name' => 'Initial name',
            'payload_encrypted' => ['api_key' => $secret],
        ]);

    $row->update(['name' => 'Updated name']);

    $activity = Activity::query()
        ->where('subject_id', $row->id)
        ->where('subject_type', IntegrationCredential::class)
        ->orderByDesc('id')
        ->first();

    expect($activity)->not->toBeNull('Activity log row missing for IntegrationCredential update');

    $serialised = json_encode($activity->properties->toArray());

    expect($serialised)->not->toContain($secret);
    expect($serialised)->toContain('Updated name');
});

it('enforces UNIQUE(kind) so the second supplier_api row throws QueryException', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create();

    expect(fn () => IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create())
        ->toThrow(QueryException::class);
});

it('casts last_test_status to IntegrationTestStatus enum on reload', function (): void {
    $row = IntegrationCredential::factory()
        ->kind(IntegrationCredentialKind::WooRest)
        ->create([
            'last_test_status' => IntegrationTestStatus::Ok,
            'last_test_at' => now(),
            'last_test_latency_ms' => 42,
        ])
        ->fresh();

    expect($row->last_test_status)->toBe(IntegrationTestStatus::Ok)
        ->and($row->last_test_latency_ms)->toBe(42)
        ->and($row->last_test_at)->not->toBeNull();
});
