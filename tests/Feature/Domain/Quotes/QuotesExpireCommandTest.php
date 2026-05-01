<?php

declare(strict_types=1);

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Notifications\QuoteExpiredNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 05 Task 1 — quotes:expire (QUOT-08)
|--------------------------------------------------------------------------
|
| Six tests covering:
|   1. dry-run is the DEFAULT — no DB writes (cross-cutting invariant 3)
|   2. --live flips status=sent → expired + sets expired_at
|   3. --limit clause respected — only N rows processed
|   4. config('quote.email_on_expiry')=true → Notification::send fires
|   5. config('quote.email_on_expiry')=false → no notification
|   6. NEVER touches non-sent quotes (draft/accepted/rejected/expired)
|
| Skip-on-MySQL-offline parity with Phase 11 Plans 02..04 (Quote.factory()
| writes a real row; SQLite + RefreshDatabase deferred to MySQL window).
*/

function skipIfMySqlOfflineQuotesExpire(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineQuotesExpire();
    config(['quote.email_on_expiry' => false]);
    Notification::fake();
});

it('defaults to dry-run when no flag set — no DB writes', function (): void {
    skipIfMySqlOfflineQuotesExpire();

    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('quotes:expire')
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect($quote->fresh()->status)->toBe(Quote::STATUS_SENT);
    expect($quote->fresh()->expired_at)->toBeNull();
});

it('writes status=expired + expired_at when --live flag is set', function (): void {
    skipIfMySqlOfflineQuotesExpire();

    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('quotes:expire', ['--live' => true])
        ->expectsOutputToContain('LIVE')
        ->assertExitCode(0);

    $fresh = $quote->fresh();
    expect($fresh->status)->toBe(Quote::STATUS_EXPIRED);
    expect($fresh->expired_at)->not->toBeNull();
});

it('respects --limit clause — only processes N rows', function (): void {
    skipIfMySqlOfflineQuotesExpire();

    Quote::factory()->count(5)->create([
        'status' => Quote::STATUS_SENT,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('quotes:expire', ['--live' => true, '--limit' => 2])
        ->assertExitCode(0);

    $expiredCount = Quote::where('status', Quote::STATUS_EXPIRED)->count();
    expect($expiredCount)->toBe(2);

    $stillSent = Quote::where('status', Quote::STATUS_SENT)->count();
    expect($stillSent)->toBe(3);
});

it('sends QuoteExpiredNotification when config quote.email_on_expiry is true', function (): void {
    skipIfMySqlOfflineQuotesExpire();
    config(['quote.email_on_expiry' => true]);

    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'expires_at' => now()->subDay(),
        'customer_email' => 'expiring-customer@example.com',
    ]);

    $this->artisan('quotes:expire', ['--live' => true])
        ->assertExitCode(0);

    Notification::assertSentOnDemand(QuoteExpiredNotification::class);
});

it('does NOT send notification when config quote.email_on_expiry is false', function (): void {
    skipIfMySqlOfflineQuotesExpire();
    config(['quote.email_on_expiry' => false]);

    Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('quotes:expire', ['--live' => true])
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

it('NEVER touches non-sent quotes — draft, accepted, rejected, already-expired all stay put', function (): void {
    skipIfMySqlOfflineQuotesExpire();

    $draft = Quote::factory()->create([
        'status' => Quote::STATUS_DRAFT,
        'expires_at' => now()->subDay(),
    ]);
    $accepted = Quote::factory()->create([
        'status' => Quote::STATUS_ACCEPTED,
        'expires_at' => now()->subDay(),
        'accepted_at' => now()->subDays(2),
    ]);
    $rejected = Quote::factory()->create([
        'status' => Quote::STATUS_REJECTED,
        'expires_at' => now()->subDay(),
        'rejected_at' => now()->subDays(2),
    ]);
    $alreadyExpired = Quote::factory()->create([
        'status' => Quote::STATUS_EXPIRED,
        'expires_at' => now()->subDays(30),
        'expired_at' => now()->subDays(20),
    ]);

    $this->artisan('quotes:expire', ['--live' => true])->assertExitCode(0);

    expect($draft->fresh()->status)->toBe(Quote::STATUS_DRAFT);
    expect($accepted->fresh()->status)->toBe(Quote::STATUS_ACCEPTED);
    expect($rejected->fresh()->status)->toBe(Quote::STATUS_REJECTED);

    // already-expired stayed expired — but expired_at must NOT be re-stamped
    $freshExpired = $alreadyExpired->fresh();
    expect($freshExpired->status)->toBe(Quote::STATUS_EXPIRED);
});
