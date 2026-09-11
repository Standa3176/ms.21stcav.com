<?php

declare(strict_types=1);

use App\Domain\Alerting\Contracts\TlsInspector;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Notifications\TlsExpiryNotification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260911-t80 — tls:check-expiry
|--------------------------------------------------------------------------
|
| meetingstore.co.uk expired at 09:34 UTC on 2026-09-11 and was found seven
| hours later by a failing trade:sync. What must hold:
|
|   - an expired cert FAILS the run (the exit code is the durable signal)
|   - so does one inside the warning window, and an unreachable host
|   - a healthy sweep passes
|   - no subscriber must not turn a real expiry into a silent pass
|
| The inspector is stubbed: a test that reaches the real network would pass or
| fail on someone else's infrastructure, and would go green the day the thing
| it is meant to catch gets fixed.
*/

/** @param array<string, int|null> $daysByHost  null = unreachable */
function fakeInspector(array $daysByHost): void
{
    $stub = new class($daysByHost) implements TlsInspector
    {
        /** @param array<string, int|null> $days */
        public function __construct(private array $days) {}

        public function inspect(string $host, int $port = 443, int $timeoutSeconds = 10): array
        {
            $d = $this->days[$host] ?? null;

            if ($d === null) {
                return ['host' => $host, 'ok' => false, 'expires_at' => null, 'days_left' => null,
                    'subject' => null, 'issuer' => null, 'error' => 'connection refused'];
            }

            return ['host' => $host, 'ok' => true,
                'expires_at' => (new DateTimeImmutable)->modify($d.' days'),
                'days_left' => $d, 'subject' => 'CN = '.$host, 'issuer' => "C = US, O = Let's Encrypt",
                'error' => null];
        }
    };

    app()->instance(TlsInspector::class, $stub);
}

beforeEach(function (): void {
    config()->set('alerting.tls.hosts', ['meetingstore.co.uk', 'ms.21stcav.com']);
    config()->set('alerting.tls.warn_days', 14);
});

it('registers the command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('tls:check-expiry');
});

it('PASSES when every certificate is comfortably valid', function (): void {
    fakeInspector(['meetingstore.co.uk' => 62, 'ms.21stcav.com' => 38]);

    $this->artisan('tls:check-expiry')
        ->assertExitCode(0)
        ->expectsOutputToContain('PASS');
});

it('FAILS on an expired certificate — the real 2026-09-11 case', function (): void {
    fakeInspector(['meetingstore.co.uk' => -1, 'ms.21stcav.com' => 38]);

    $this->artisan('tls:check-expiry')
        ->assertExitCode(1)
        ->expectsOutputToContain('EXPIRED');
});

it('FAILS inside the warning window, which is the whole point', function (): void {
    // 13 days out: still serving fine, but this is the warning that was never
    // sent. Let's Encrypt renews inside 30 days, so 13 is actionable today.
    fakeInspector(['meetingstore.co.uk' => 13, 'ms.21stcav.com' => 38]);

    $this->artisan('tls:check-expiry')
        ->assertExitCode(1)
        ->expectsOutputToContain('expires in 13 day(s)');
});

it('FAILS when a host cannot be reached rather than reporting it healthy', function (): void {
    fakeInspector(['meetingstore.co.uk' => null, 'ms.21stcav.com' => 38]);

    $this->artisan('tls:check-expiry')
        ->assertExitCode(1)
        ->expectsOutputToContain('UNREACHABLE');
});

it('honours --days so the threshold can be tightened without a deploy', function (): void {
    fakeInspector(['meetingstore.co.uk' => 20, 'ms.21stcav.com' => 38]);

    $this->artisan('tls:check-expiry')->assertExitCode(0);
    $this->artisan('tls:check-expiry --days=30')->assertExitCode(1);
});

it('emails subscribed recipients when --notify is passed', function (): void {
    Notification::fake();
    AlertRecipient::factory()->create(['is_active' => true, 'receives_pricing_alerts' => true]);
    fakeInspector(['meetingstore.co.uk' => -1, 'ms.21stcav.com' => 38]);

    $this->artisan('tls:check-expiry --notify')->assertExitCode(1);

    Notification::assertSentTimes(TlsExpiryNotification::class, 1);
});

it('still FAILS when nobody is subscribed — silence must not read as a pass', function (): void {
    Notification::fake();
    fakeInspector(['meetingstore.co.uk' => -1]);
    config()->set('alerting.tls.hosts', ['meetingstore.co.uk']);

    $this->artisan('tls:check-expiry --notify')
        ->assertExitCode(1)
        ->expectsOutputToContain('No active recipient');

    Notification::assertNothingSent();
});

it('checks only the hosts given by --hosts', function (): void {
    fakeInspector(['other.example.com' => -5]);

    $this->artisan('tls:check-expiry --hosts=other.example.com')
        ->assertExitCode(1)
        ->expectsOutputToContain('other.example.com');
});
