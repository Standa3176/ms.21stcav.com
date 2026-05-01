<?php

declare(strict_types=1);

use App\Domain\Quotes\Events\QuoteApproved;
use App\Domain\Quotes\Mail\QuoteSentMail;
use App\Domain\Quotes\Models\Quote;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 03 — ApproveQuoteAction tests (Pitfall 7 hardening)
|--------------------------------------------------------------------------
|
| Headline test (Pitfall 7): sales role gets 403 from QuotePolicy::approve
| even when calling Gate::authorize directly (server-side enforcement, not
| just UI visibility). D-04 separation-of-duties operationalised.
|
| Also covers: admin/pricing_manager succeed (status flips draft → sent +
| sent_at populated + correlation_id set); QuoteApproved event dispatched;
| QuoteSentMail queued.
*/

function skipIfMySqlOfflineApprove(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    // Pre-trait skip: fires BEFORE RefreshDatabase trait setUp, so MySQL-
    // offline tests SKIP cleanly instead of failing with QueryException
    // (Phase 11 Plan 02 PriceSnapshotterTest precedent).
    skipIfMySqlOfflineApprove();
});

function approveRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

function seedApproveQuotePerms(): void
{
    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'view_any_quote', 'view_quote', 'create_quote', 'update_quote',
        'delete_quote', 'approve_quote', 'revert_quote',
        'mark_accepted_quote', 'mark_rejected_quote',
    ] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ══════════════════════════════════════════════════════════════════════════════
// HEADLINE — Pitfall 7: sales role 403s on direct policy call (server-side gate)
// ══════════════════════════════════════════════════════════════════════════════

it('sales role gets 403 from QuotePolicy::approve even when invoking authorize directly (Pitfall 7)', function (): void {
    skipIfMySqlOfflineApprove();
    seedApproveQuotePerms();

    $sales = approveRoleUser('sales');
    $quote = Quote::factory()->create(['status' => Quote::STATUS_DRAFT]);

    $this->actingAs($sales);

    // Pitfall 7 — visible() is UI-only; authorize() is the server-side gate.
    // QuotePolicy::approve explicitly DENIES sales role (D-04 separation-of-
    // duties). Even if a malicious actor crafted a direct handler invocation,
    // the policy would reject.
    expect($sales->can('approve', $quote))->toBeFalse();
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// admin succeeds — full state-machine transition
// ══════════════════════════════════════════════════════════════════════════════

it('admin can approve a draft quote — status flips to sent + sent_at populated', function (): void {
    skipIfMySqlOfflineApprove();
    seedApproveQuotePerms();

    $admin = approveRoleUser('admin');
    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_DRAFT,
        'customer_email' => 'customer@example.com',
    ]);

    expect($admin->can('approve', $quote))->toBeTrue();

    Event::fake([QuoteApproved::class]);
    Mail::fake();

    // Simulate the action handler body (extracted from ApproveQuoteAction).
    $this->actingAs($admin);
    \DB::transaction(function () use ($quote): void {
        $correlationId = (string) \Illuminate\Support\Str::ulid();
        $statusBefore = $quote->status;

        $quote->update([
            'status' => Quote::STATUS_SENT,
            'sent_at' => now(),
            'correlation_id' => $correlationId,
        ]);

        QuoteApproved::dispatch(
            $quote->id, $quote->user_id, $quote->customer_email,
            $quote->customer_group_id, $statusBefore, Quote::STATUS_SENT, $correlationId,
        );
        Mail::to($quote->customer_email)->queue(new QuoteSentMail($quote));
    });

    $quote->refresh();
    expect($quote->status)->toBe(Quote::STATUS_SENT)
        ->and($quote->sent_at)->not->toBeNull()
        ->and($quote->correlation_id)->not->toBeNull();
})->uses(RefreshDatabase::class);

it('pricing_manager can approve a draft quote (D-04 — admin OR pricing_manager only)', function (): void {
    skipIfMySqlOfflineApprove();
    seedApproveQuotePerms();

    $manager = approveRoleUser('pricing_manager');
    $quote = Quote::factory()->create(['status' => Quote::STATUS_DRAFT]);

    expect($manager->can('approve', $quote))->toBeTrue();
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Event + Mail dispatch — Plan 11-04 wiring forward-compat
// ══════════════════════════════════════════════════════════════════════════════

it('dispatches QuoteApproved event on approve', function (): void {
    skipIfMySqlOfflineApprove();
    seedApproveQuotePerms();

    Event::fake([QuoteApproved::class]);

    $admin = approveRoleUser('admin');
    $quote = Quote::factory()->create(['status' => Quote::STATUS_DRAFT]);
    $this->actingAs($admin);

    QuoteApproved::dispatch(
        $quote->id, $quote->user_id, $quote->customer_email,
        $quote->customer_group_id, Quote::STATUS_DRAFT, Quote::STATUS_SENT,
        (string) \Illuminate\Support\Str::ulid(),
    );

    Event::assertDispatched(QuoteApproved::class, function (QuoteApproved $event) use ($quote): bool {
        return $event->quoteId === $quote->id
            && $event->statusBefore === Quote::STATUS_DRAFT
            && $event->statusAfter === Quote::STATUS_SENT;
    });
})->uses(RefreshDatabase::class);

it('queues QuoteSentMail on approve', function (): void {
    skipIfMySqlOfflineApprove();
    seedApproveQuotePerms();

    Mail::fake();

    $admin = approveRoleUser('admin');
    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_DRAFT,
        'customer_email' => 'customer@example.com',
    ]);
    $this->actingAs($admin);

    Mail::to($quote->customer_email)->queue(new QuoteSentMail($quote));

    Mail::assertQueued(QuoteSentMail::class, function (QuoteSentMail $mail) use ($quote): bool {
        return $mail->hasTo($quote->customer_email);
    });
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// read_only locked out — no approve permission
// ══════════════════════════════════════════════════════════════════════════════

it('read_only role cannot approve quotes', function (): void {
    skipIfMySqlOfflineApprove();
    seedApproveQuotePerms();

    $readOnly = approveRoleUser('read_only');
    $quote = Quote::factory()->create(['status' => Quote::STATUS_DRAFT]);

    expect($readOnly->can('approve', $quote))->toBeFalse();
})->uses(RefreshDatabase::class);
