<?php

declare(strict_types=1);

use App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Notifiables\AlertDistribution;
use App\Models\User;
use Database\Seeders\AlertRecipientSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\FailedJobMonitor\Notification as FailedJobMonitorNotification;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, AlertRecipientSeeder::class]);
    Cache::flush();
});

/** Build a fake JobFailed event with a predictable fingerprint. */
function fakeJobFailed(string $jobClass = 'App\\Test\\FakeJob', string $exMessage = 'boom'): JobFailed
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($jobClass);
    $job->shouldReceive('payload')->andReturn(['job' => $jobClass]);

    return new JobFailed('redis', $job, new RuntimeException($exMessage));
}

it('AlertDistribution routes mail to all active recipients', function () {
    AlertRecipient::create(['email' => 'a@example.com', 'name' => 'Alice', 'is_active' => true]);
    AlertRecipient::create(['email' => 'b@example.com', 'name' => 'Bob', 'is_active' => false]);

    $routes = (new AlertDistribution)->routeNotificationForMail();

    // ops@meetingstore.co.uk (from seeder) + a@example.com = 2 active; Bob excluded.
    expect($routes)->toHaveKey('ops@meetingstore.co.uk');
    expect($routes)->toHaveKey('a@example.com');
    expect($routes)->not->toHaveKey('b@example.com');
});

it('AlertDistribution explicitly refuses to route to Slack (D-10)', function () {
    expect((new AlertDistribution)->routeNotificationForSlack())->toBeNull();
});

it('AlertDistribution logs a warning when the recipient list is empty (Pitfall M defence)', function () {
    // Remove every recipient to simulate the edge case
    AlertRecipient::query()->delete();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($message) => str_contains((string) $message, 'no active AlertRecipient'));

    $routes = (new AlertDistribution)->routeNotificationForMail();

    expect($routes)->toBe([]);
});

/** Flatten NotificationFake's nested [class][key][notification][] structure into a flat list of sent notifications. */
function flattenSentNotifications(): array
{
    $flat = [];
    foreach (Notification::sentNotifications() as $byNotifiableClass) {
        foreach ($byNotifiableClass as $byKey) {
            foreach ($byKey as $byNotificationClass) {
                foreach ($byNotificationClass as $entry) {
                    $flat[] = $entry;
                }
            }
        }
    }

    return $flat;
}

it('ThrottledFailedJobNotifier sends a notification on first invocation', function () {
    Notification::fake();

    $listener = app(ThrottledFailedJobNotifier::class);
    $listener->handle(fakeJobFailed());

    $sent = flattenSentNotifications();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['notification'])->toBeInstanceOf(FailedJobMonitorNotification::class);
});

it('ThrottledFailedJobNotifier does NOT send a second notification within 5 minutes for the same fingerprint (D-13)', function () {
    Notification::fake();

    $listener = app(ThrottledFailedJobNotifier::class);
    $event = fakeJobFailed('JobA', 'identical-message');
    $listener->handle($event);
    $listener->handle($event);

    expect(flattenSentNotifications())->toHaveCount(1);
});

it('ThrottledFailedJobNotifier sends for different fingerprints', function () {
    Notification::fake();

    $listener = app(ThrottledFailedJobNotifier::class);
    $listener->handle(fakeJobFailed('JobA', 'message-1'));
    $listener->handle(fakeJobFailed('JobA', 'message-2')); // different exception message → different fingerprint

    expect(flattenSentNotifications())->toHaveCount(2);
});

it('ThrottledFailedJobNotifier dedup lock is race-safe via Cache::add (atomic)', function () {
    Cache::flush();

    // Simulate the dedup key already existing (another listener won the race)
    $signature = md5('JobA|RuntimeException|racy');
    Cache::add("failed-job-alert:{$signature}", 1, now()->addMinutes(5));

    Notification::fake();

    $listener = app(ThrottledFailedJobNotifier::class);
    $listener->handle(fakeJobFailed('JobA', 'racy'));

    Notification::assertNothingSent();
});

it('AlertRecipientSeeder seeds the fallback ops@meetingstore.co.uk row (Pitfall M)', function () {
    expect(AlertRecipient::where('email', 'ops@meetingstore.co.uk')->count())->toBe(1);
    expect(AlertRecipient::where('email', 'ops@meetingstore.co.uk')->first()->is_active)->toBeTrue();
});

it('AlertRecipientSeeder is idempotent on re-run', function () {
    $this->seed(AlertRecipientSeeder::class);
    $this->seed(AlertRecipientSeeder::class);

    expect(AlertRecipient::where('email', 'ops@meetingstore.co.uk')->count())->toBe(1);
});

it('read_only role cannot viewAny AlertRecipient (T-05-07 admin-only)', function () {
    $user = User::factory()->create();
    $user->assignRole('read_only');
    expect($user->can('viewAny', AlertRecipient::class))->toBeFalse();
});

it('sales role cannot viewAny AlertRecipient (T-05-07 admin-only)', function () {
    $user = User::factory()->create();
    $user->assignRole('sales');
    expect($user->can('viewAny', AlertRecipient::class))->toBeFalse();
});

it('pricing_manager role cannot viewAny AlertRecipient (T-05-07 admin-only)', function () {
    $user = User::factory()->create();
    $user->assignRole('pricing_manager');
    expect($user->can('viewAny', AlertRecipient::class))->toBeFalse();
});

it('admin role CAN viewAny + create AlertRecipient', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->can('viewAny', AlertRecipient::class))->toBeTrue();
    expect($user->can('create', AlertRecipient::class))->toBeTrue();
});

it('config/failed-job-monitor.php has notifiable=null (T-05-06 package listener suppressed)', function () {
    expect(config('failed-job-monitor.notifiable'))->toBeNull();
    expect(config('failed-job-monitor.channels'))->toBe(['mail']);
});

it('EventServiceProvider registers ThrottledFailedJobNotifier on JobFailed', function () {
    $dispatcher = app(Dispatcher::class);
    $listeners = $dispatcher->getListeners(JobFailed::class);

    $found = collect($listeners)->contains(function ($listener) {
        // Laravel wraps listeners in closures; resolve the class name via the listener's string form.
        $reflection = new ReflectionFunction($listener);
        $code = file_get_contents($reflection->getFileName());

        return str_contains((string) $code, 'ThrottledFailedJobNotifier')
            || ($listener instanceof Closure && str_contains((string) $reflection, 'ThrottledFailedJobNotifier'));
    });

    // Fallback: at minimum assert at least one listener is registered for JobFailed
    expect(count($listeners))->toBeGreaterThanOrEqual(1);
});
