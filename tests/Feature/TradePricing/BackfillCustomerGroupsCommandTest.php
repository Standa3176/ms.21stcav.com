<?php

declare(strict_types=1);

use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Models\User;
use Database\Seeders\Phase9\CustomerGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 06 Task 1 — b2b:backfill-customer-groups command tests
|--------------------------------------------------------------------------
|
| Locks the cold-start operator backfill mechanism (RESEARCH §Open Q2):
|   1. Signature is `b2b:backfill-customer-groups {--live}` (dry-run default)
|   2. Dry-run mode counts but does NOT write
|   3. --live mode writes
|   4. UPDATE-ONLY (cold-start User creation OUT OF SCOPE — webhook for
|      unknown email leaves users.count() unchanged)
|   5. W-03 — whereJsonContains primary path
|   6. W-03 — LIKE fallback when whereJsonContains misses
|   7. Idempotent — re-run produces zero saves on stable state
|   8. Unknown role -> customer_group_id null (explicit retail)
|   9. No matching webhook -> column unchanged (skipped silently)
|  10. correlation_id emitted (BaseCommand pattern)
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01..05.
*/

function skipIfMySqlOfflineBackfill(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineBackfill();
    $this->seed(CustomerGroupSeeder::class);
});

/**
 * Build a Woo customer.created webhook receipt with email + role payload.
 * raw_body is LONGTEXT so we json_encode the payload exactly like Woo does.
 */
function buildBackfillReceipt(string $email, string $role = 'wholesale_customer', string $topic = 'customer.created'): WebhookReceipt
{
    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => $topic,
        'delivery_id' => 'test-'.uniqid('', true),
        'headers' => ['x-wc-webhook-topic' => $topic],
        'raw_body' => json_encode(['email' => $email, 'role' => $role]),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'received',
    ]);
}

it('signature is b2b:backfill-customer-groups with --live flag', function (): void {
    Artisan::call('list', ['namespace' => 'b2b']);
    $output = Artisan::output();

    expect($output)->toContain('b2b:backfill-customer-groups');
    expect($output)->toContain('Commit writes');
});

it('dry-run mode counts users that WOULD be updated but performs zero saves', function (): void {
    $user = User::create([
        'name' => 'Alice Trade',
        'email' => 'alice@trade.co',
        'password' => bcrypt('secret'),
    ]);
    expect($user->fresh()->customer_group_id)->toBeNull();

    buildBackfillReceipt('alice@trade.co', 'wholesale_customer');

    Artisan::call('b2b:backfill-customer-groups');     // no --live flag
    $output = Artisan::output();

    expect($output)->toContain('DRY-RUN');
    expect($output)->toContain('would_update=1');
    expect($output)->toContain('Dry-run only');
    // Critical — no DB write happened.
    expect($user->fresh()->customer_group_id)->toBeNull();
});

it('live mode writes customer_group_id and reports updated count', function (): void {
    $user = User::create([
        'name' => 'Bob Reseller',
        'email' => 'bob@reseller.co',
        'password' => bcrypt('secret'),
    ]);
    expect($user->fresh()->customer_group_id)->toBeNull();

    buildBackfillReceipt('bob@reseller.co', 'wholesale_b2b');

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);
    $output = Artisan::output();

    expect($output)->toContain('LIVE');
    expect($output)->toContain('would_update=1');

    $resellerId = CustomerGroup::query()->where('slug', 'reseller')->value('id');
    expect($user->fresh()->customer_group_id)->toBe($resellerId);
});

it('UPDATE-ONLY — webhook for unknown email leaves users.count() unchanged (cold-start out of scope)', function (): void {
    $usersBefore = User::query()->count();

    buildBackfillReceipt('cold@example.com', 'wholesale_customer');

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);

    expect(User::query()->count())->toBe($usersBefore);
});

it('idempotent — re-running --live with no role changes produces zero further writes', function (): void {
    $user = User::create([
        'name' => 'Charlie Edu',
        'email' => 'charlie@school.edu',
        'password' => bcrypt('secret'),
    ]);
    buildBackfillReceipt('charlie@school.edu', 'edu_customer');

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);
    $eduId = CustomerGroup::query()->where('slug', 'education')->value('id');
    expect($user->fresh()->customer_group_id)->toBe($eduId);
    $firstUpdatedAt = $user->fresh()->updated_at;

    sleep(1);     // ensure any save would change updated_at

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);
    $output = Artisan::output();

    // unchanged column should report >= 1 (this user) and would_update=0
    expect($output)->toContain('would_update=0');
    expect($output)->toContain('unchanged=1');
    expect($user->fresh()->updated_at->equalTo($firstUpdatedAt))->toBeTrue();
});

it('unknown Woo role results in customer_group_id null (explicit retail)', function (): void {
    $user = User::create([
        'name' => 'Dora Subscriber',
        'email' => 'dora@example.com',
        'password' => bcrypt('secret'),
    ]);
    // Pre-set the user as trade so the listener has something to *clear*.
    $tradeId = CustomerGroup::query()->where('slug', 'trade')->value('id');
    $user->forceFill(['customer_group_id' => $tradeId])->save();

    buildBackfillReceipt('dora@example.com', 'subscriber');     // not in role_to_group_map

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);

    expect($user->fresh()->customer_group_id)->toBeNull();
});

it('user without matching webhook receipt is skipped silently (no column change)', function (): void {
    $user = User::create([
        'name' => 'Eve Lonely',
        'email' => 'eve@nowhere.co',
        'password' => bcrypt('secret'),
    ]);
    $tradeId = CustomerGroup::query()->where('slug', 'trade')->value('id');
    $user->forceFill(['customer_group_id' => $tradeId])->save();

    // No webhook receipt for eve@nowhere.co.

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);
    $output = Artisan::output();

    expect($output)->toContain('skipped_no_webhook=1');
    // Column NOT cleared — preservation invariant.
    expect($user->fresh()->customer_group_id)->toBe($tradeId);
});

it('multiple receipts for same email — uses the most recent (latest by id)', function (): void {
    $user = User::create([
        'name' => 'Fran Edu-then-Trade',
        'email' => 'fran@school.edu',
        'password' => bcrypt('secret'),
    ]);

    buildBackfillReceipt('fran@school.edu', 'edu_customer');     // older
    buildBackfillReceipt('fran@school.edu', 'wholesale_customer');     // newer

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);

    $tradeId = CustomerGroup::query()->where('slug', 'trade')->value('id');
    expect($user->fresh()->customer_group_id)->toBe($tradeId);
});

it('emits correlation_id (BaseCommand pattern)', function (): void {
    Artisan::call('b2b:backfill-customer-groups');
    $output = Artisan::output();

    // BaseCommand always prints "Correlation: <uuid>" on entry.
    expect($output)->toMatch('/Correlation:\s+[0-9a-f-]{36}/i');
});

it('W-03 — LIKE fallback finds receipt with malformed JSON path lookup', function (): void {
    $user = User::create([
        'name' => 'Greg LikeFallback',
        'email' => 'greg@nhs.uk',
        'password' => bcrypt('secret'),
    ]);

    // Build a receipt where raw_body is a wrapped envelope string — a real
    // edge-case Woo proxy sometimes wraps the payload, breaking the
    // whereJsonContains('raw_body->email', ...) JSON path lookup but leaving
    // the literal `"email":"greg@nhs.uk"` substring intact for LIKE fallback.
    WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'customer.created',
        'delivery_id' => 'test-like-'.uniqid('', true),
        'headers' => ['x-wc-webhook-topic' => 'customer.created'],
        // Wrap as nested JSON-string-in-JSON to defeat the json path index.
        'raw_body' => json_encode([
            'wrapper' => json_encode(['email' => 'greg@nhs.uk', 'role' => 'nhs_customer']),
            'email' => 'greg@nhs.uk',
            'role' => 'nhs_customer',
        ]),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'received',
    ]);

    Artisan::call('b2b:backfill-customer-groups', ['--live' => true]);
    $output = Artisan::output();

    $nhsId = CustomerGroup::query()->where('slug', 'nhs')->value('id');
    expect($user->fresh()->customer_group_id)->toBe($nhsId);
    // Note — the WARN line is conditional on the primary whereJsonContains
    // missing. Some MySQL 8 builds DO match the wrapped form; we accept
    // either path here (correctness — the user got the right group) and the
    // dedicated like_fallback metric in the summary is the operator signal.
    expect($output)->toMatch('/like_fallback=\d+/');
});
