<?php

declare(strict_types=1);

use App\Domain\TradePricing\Listeners\UpdateCustomerGroupOnUserRoleChange;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Models\User;
use Database\Seeders\Phase9\CustomerGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 04 Task 3 — UpdateCustomerGroupOnUserRoleChange listener
|--------------------------------------------------------------------------
|
| Locks listener semantics:
|   1. Happy path — existing User + matching role -> customer_group_id
|      forceFilled with the right group.
|   2. B-04 invariant — no local user => listener skips silently. NO User
|      rows created from webhook payloads. Cold-start is the explicit job
|      of `b2b:backfill-customer-groups` (Plan 09-06).
|   3. Compare-and-swap idempotency (Pitfall 4) — re-firing same payload
|      produces no updated_at drift.
|   4. Role change clears group — wholesale_customer -> customer flips the
|      column back to null (retail).
|   5. Missing email -> early return + warning log.
|   6. Listener registered in EventServiceProvider $listen.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01..03.
*/

function skipIfMySqlOfflineListener(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineListener();
    $this->seed(CustomerGroupSeeder::class);
});

/**
 * Build a webhook receipt with a wholesale_customer payload for the given email.
 */
function buildCustomerRegisteredReceipt(string $email, string $role = 'wholesale_customer'): WebhookReceipt
{
    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'customer.created',
        'delivery_id' => 'test-'.uniqid(),
        'headers' => ['x-wc-webhook-topic' => 'customer.created'],
        'raw_body' => json_encode(['email' => $email, 'role' => $role]),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'received',
    ]);
}

it('updates customer_group_id on existing user when wholesale_customer role arrives', function (): void {
    $user = User::create([
        'name' => 'Alice Trade',
        'email' => 'alice@trade.co',
        'password' => bcrypt('secret'),
    ]);
    expect($user->fresh()->customer_group_id)->toBeNull();

    $receipt = buildCustomerRegisteredReceipt('alice@trade.co', 'wholesale_customer');
    app(UpdateCustomerGroupOnUserRoleChange::class)
        ->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    $expectedId = CustomerGroup::query()->where('slug', 'trade')->value('id');
    expect($user->fresh()->customer_group_id)->toBe($expectedId);
});

it('skips silently when no local user matches the webhook email (B-04)', function (): void {
    $receipt = buildCustomerRegisteredReceipt('never-existed@example.com', 'wholesale_customer');
    $beforeCount = User::query()->count();

    $listener = app(UpdateCustomerGroupOnUserRoleChange::class);
    $listener->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    // NO user created from forged webhook payload.
    expect(User::query()->count())->toBe($beforeCount);
});

it('compare-and-swap: re-firing same payload produces no updated_at drift', function (): void {
    $user = User::create([
        'name' => 'Carol',
        'email' => 'carol@trade.co',
        'password' => bcrypt('secret'),
    ]);

    // First fire — sets the group.
    $receipt1 = buildCustomerRegisteredReceipt('carol@trade.co', 'wholesale_customer');
    app(UpdateCustomerGroupOnUserRoleChange::class)
        ->handle(new CustomerRegistered($receipt1->id, $receipt1->delivery_id));

    $user->refresh();
    $firstUpdatedAt = $user->updated_at;

    // Sleep 1s so any rewrite would change updated_at.
    sleep(1);

    // Second fire — same payload, same target state.
    $receipt2 = buildCustomerRegisteredReceipt('carol@trade.co', 'wholesale_customer');
    app(UpdateCustomerGroupOnUserRoleChange::class)
        ->handle(new CustomerRegistered($receipt2->id, $receipt2->delivery_id));

    $user->refresh();
    expect($user->updated_at->toIso8601String())->toBe($firstUpdatedAt->toIso8601String());
});

it('clears customer_group_id when role changes to unmapped customer (default Woo role)', function (): void {
    $tradeId = CustomerGroup::query()->where('slug', 'trade')->value('id');
    $user = User::create([
        'name' => 'Dave',
        'email' => 'dave@trade.co',
        'password' => bcrypt('secret'),
    ]);
    $user->forceFill(['customer_group_id' => $tradeId])->save();
    expect($user->fresh()->customer_group_id)->toBe($tradeId);

    $receipt = buildCustomerRegisteredReceipt('dave@trade.co', 'customer');
    app(UpdateCustomerGroupOnUserRoleChange::class)
        ->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    expect($user->fresh()->customer_group_id)->toBeNull();
});

it('skips with warning when payload has empty email', function (): void {
    $user = User::create([
        'name' => 'Eve',
        'email' => 'eve@trade.co',
        'password' => bcrypt('secret'),
    ]);

    $receipt = WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'customer.created',
        'delivery_id' => 'test-empty-'.uniqid(),
        'headers' => ['x-wc-webhook-topic' => 'customer.created'],
        'raw_body' => json_encode(['role' => 'wholesale_customer']),    // no email key
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'received',
    ]);

    app(UpdateCustomerGroupOnUserRoleChange::class)
        ->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    expect($user->fresh()->customer_group_id)->toBeNull();
});

it('listener is registered in EventServiceProvider $listen', function (): void {
    $contents = file_get_contents(base_path('app/Providers/EventServiceProvider.php'));
    expect($contents)->toContain('UpdateCustomerGroupOnUserRoleChange');
    expect($contents)->toContain('CustomerRegistered::class');
});
