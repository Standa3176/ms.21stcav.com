---
phase: 01-foundation
plan: 05
type: execute
wave: 4
depends_on: [01-01-scaffold, 01-02-rbac, 01-03-foundation, 01-04-seams]
files_modified:
  - config/horizon.php
  - config/failed-job-monitor.php
  - app/Providers/HorizonServiceProvider.php
  - database/migrations/2026_04_18_104000_create_alert_recipients_table.php
  - app/Domain/Alerting/Models/AlertRecipient.php
  - app/Domain/Alerting/Notifiables/AlertDistribution.php
  - app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php
  - app/Domain/Alerting/Policies/AlertRecipientPolicy.php
  - app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php
  - app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/ListAlertRecipients.php
  - app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/CreateAlertRecipient.php
  - app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/EditAlertRecipient.php
  - app/Providers/EventServiceProvider.php
  - app/Console/Commands/TestFailingJobCommand.php
  - app/Console/Commands/PruneActivityLogCommand.php
  - app/Console/Commands/PruneIntegrationEventsCommand.php
  - app/Console/Commands/PruneSyncDiffsCommand.php
  - routes/console.php
  - database/seeders/AlertRecipientSeeder.php
  - database/seeders/DatabaseSeeder.php
  - .github/workflows/ci.yml
  - tests/Feature/HorizonSupervisorTest.php
  - tests/Feature/FailedJobAlertTest.php
  - tests/Feature/RetentionPruneTest.php
  - tests/Architecture/DeptracTest.php
autonomous: true
requirements:
  - FOUND-09
  - FOUND-10
  - FOUND-11
  - FOUND-12
user_setup:
  - service: vps
    why: "Redis persistence + Supervisor supervision of `php artisan horizon` + cron entry for `php artisan schedule:run`"
    env_vars: []
    dashboard_config:
      - task: "Write /etc/redis/redis.conf with appendonly yes + appendfsync everysec + maxmemory-policy noeviction + maxmemory 2gb"
        location: "VPS /etc/redis/redis.conf — see 01-RESEARCH.md §4 Redis persistence; full template in plan context"
      - task: "Install Supervisor and write /etc/supervisor/conf.d/horizon.conf per template"
        location: "VPS /etc/supervisor/conf.d/ — see 01-RESEARCH.md §4 Supervisor config"
      - task: "Add cron entry `* * * * * cd /var/www/meetingstore-ops && php artisan schedule:run >> /dev/null 2>&1`"
        location: "VPS crontab -e — required for retention prunes and failed-job monitor schedule"
      - task: "After deploy: populate AlertRecipientSeeder with real ops addresses (seeder ships ops@meetingstore.co.uk as fallback per Pitfall M)"
        location: "Filament /admin/alert-recipients Resource — admins can add/edit after first login"

must_haves:
  truths:
    - "`config/horizon.php` 'production' environment defines 7 supervisors: webhook-inbound-supervisor, crm-bitrix-supervisor, sync-woo-push-supervisor, sync-bulk-supervisor, competitor-csv-supervisor, critical-supervisor, default-supervisor"
    - "Each supervisor declares exactly one queue from the 7 named set: `webhook-inbound`, `crm-bitrix`, `sync-woo-push`, `sync-bulk`, `competitor-csv`, `critical`, `default`"
    - "`config/horizon.php` 'local' environment defines one all-in-one supervisor covering all 7 queues"
    - "`HorizonServiceProvider::gate()` grants access only to users with admin role"
    - "Booting `php artisan horizon` shows all 7 supervisors running in the dashboard (verified by existence check on `config('horizon.environments.production')`)"
    - "`alert_recipients` table exists with unique email column, name, is_active, timestamps"
    - "`AlertRecipient` model + Filament Resource exist; Resource is admin-only via `AlertRecipientPolicy`"
    - "`AlertDistribution` Notifiable routes to all `AlertRecipient::where('is_active', true)->pluck('name', 'email')`"
    - "`ThrottledFailedJobNotifier` listener fires on `Illuminate\\Queue\\Events\\JobFailed` with 5-minute dedup window keyed by fingerprint(job class + exception class + exception message)"
    - "spatie/failed-job-monitor config has `notifiable => null` so the package's built-in listener is suppressed (we own end-to-end dispatch)"
    - "`TestFailingJob` dispatched via `php artisan alerts:test-failure` causes exactly one Mail notification to each active AlertRecipient; a second dispatch within 5 minutes sends zero mails (dedup proven)"
    - "`PruneActivityLogCommand` (365 days per D-04), `PruneIntegrationEventsCommand` (90 days per D-05), `PruneSyncDiffsCommand` (conditional on WOO_WRITE_ENABLED per D-08) all exist"
    - "Each prune command writes a meta-audit row via `Auditor::record(...)` (D-09)"
    - "`PruneSyncDiffsCommand` exits 0 with `'Skipped — WOO_WRITE_ENABLED=false'` output when the flag is false, AND a sync_diffs row still exists after running (Pitfall L regression test)"
    - "`routes/console.php` schedules all 3 prunes: 03:00 activity log, 03:10 integration events, 03:30 sync diffs — each with `withoutOverlapping()`"
    - "`AlertRecipientSeeder` seeds one fallback row `ops@meetingstore.co.uk` (Pitfall M mitigation)"
    - "`.github/workflows/ci.yml` runs Deptrac + Pest + Larastan + Pint on every PR"
    - "`tests/Architecture/DeptracTest.php` shells out to `vendor/bin/deptrac analyse` and asserts exit 0"
  artifacts:
    - path: "config/horizon.php"
      provides: "7 production supervisors mapping to 7 named queues"
      contains: "webhook-inbound, crm-bitrix, sync-woo-push, sync-bulk, competitor-csv, critical, default"
    - path: "config/failed-job-monitor.php"
      provides: "Channel = mail only; notifiable = null (we use custom listener)"
      contains: "'notifiable' => null"
    - path: "app/Providers/HorizonServiceProvider.php"
      provides: "gate() restricting /horizon to admin role"
      contains: "hasRole('admin')"
    - path: "database/migrations/2026_04_18_104000_create_alert_recipients_table.php"
      provides: "alert_recipients table (D-12)"
    - path: "app/Domain/Alerting/Models/AlertRecipient.php"
      provides: "Eloquent model for alert distribution list"
    - path: "app/Domain/Alerting/Notifiables/AlertDistribution.php"
      provides: "Dynamic notifiable resolving to active recipients (D-11 single distribution)"
    - path: "app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php"
      provides: "5-minute dedup window on failed-job alerts (D-13)"
      contains: "Cache::put"
    - path: "app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php"
      provides: "Filament CRUD Resource for admin-only alert recipient management"
    - path: "app/Console/Commands/PruneActivityLogCommand.php"
      provides: "D-04 365-day prune + Auditor::record meta-audit"
    - path: "app/Console/Commands/PruneIntegrationEventsCommand.php"
      provides: "D-05 90-day prune + meta-audit"
    - path: "app/Console/Commands/PruneSyncDiffsCommand.php"
      provides: "D-08 conditional prune (skipped while WOO_WRITE_ENABLED=false)"
      contains: "services.woo.write_enabled"
    - path: "routes/console.php"
      provides: "Schedule entries for all 3 prune commands (D-09)"
      contains: "activitylog:prune, integration-events:prune, sync-diffs:prune"
    - path: ".github/workflows/ci.yml"
      provides: "CI pipeline — Deptrac + Pest + Larastan + Pint"
      contains: "deptrac analyse"
    - path: "tests/Architecture/DeptracTest.php"
      provides: "Architecture test that runs Deptrac and asserts exit 0"
  key_links:
    - from: "app/Providers/EventServiceProvider.php"
      to: "ThrottledFailedJobNotifier"
      via: "listen array mapping JobFailed → ThrottledFailedJobNotifier"
      pattern: "ThrottledFailedJobNotifier"
    - from: "app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php"
      to: "AlertDistribution Notifiable"
      via: "Notification::send(app(AlertDistribution::class), ...)"
      pattern: "AlertDistribution"
    - from: "config/horizon.php"
      to: "7 named queues"
      via: "supervisors[*].queue array"
      pattern: "webhook-inbound|crm-bitrix|sync-woo-push|sync-bulk|competitor-csv|critical|default"
    - from: "routes/console.php"
      to: "3 prune commands"
      via: "Schedule::command() calls"
      pattern: "Schedule::command"
    - from: "app/Console/Commands/PruneSyncDiffsCommand.php"
      to: "services.woo.write_enabled"
      via: "config() read + early return on false"
      pattern: "services\\.woo\\.write_enabled"
---

<objective>
Wire the queue + alerting + retention + CI infrastructure:
1. **Horizon supervisors (FOUND-09):** 7 supervisors mapping to 7 named queues per 01-RESEARCH.md §4; admin-only gate on `/horizon`; local-env all-in-one supervisor for dev.
2. **Redis persistence (FOUND-10):** Documented config (user_setup) + verification command that confirms `appendonly yes` + `appendfsync everysec` + `noeviction`.
3. **Failed-job alerting (FOUND-11) with AlertRecipient (D-12 new scope):** `alert_recipients` table + Filament Resource (admin-only) + `AlertDistribution` Notifiable + `ThrottledFailedJobNotifier` listener with 5-minute dedup (D-13) + email-only routing (D-10); disable spatie package's default listener to prevent double-send.
4. **Retention prunes (FOUND-12):** 3 commands (activity log 365d, integration events 90d, sync_diffs conditional) each writing meta-audit via `Auditor`; scheduled in `routes/console.php`.
5. **CI pipeline:** `.github/workflows/ci.yml` running Deptrac + Pest + Larastan + Pint on every PR; architecture test `tests/Architecture/DeptracTest.php` proves module boundaries from Plan 01 continue to hold.

Purpose: Success Criterion 5 from the roadmap ("7 queues visible in Horizon; deliberately-failed job triggers admin email") is satisfied here. Without retention prune commands, integration_events grows unboundedly — a known time bomb.

Output: A booting Horizon with 7 supervisors, a working failed-job alert path (throttled, email-only, routed via DB-backed AlertRecipient list), three scheduled retention prunes, and a green CI pipeline enforcing module boundaries.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/phases/01-foundation/01-CONTEXT.md
@.planning/phases/01-foundation/01-RESEARCH.md
@.planning/phases/01-foundation/01-01-scaffold-PLAN.md
@.planning/phases/01-foundation/01-02-rbac-PLAN.md
@.planning/phases/01-foundation/01-03-foundation-PLAN.md
@.planning/research/PITFALLS.md

<interfaces>
<!-- Consumed from Plans 01, 02, 03: -->

From Plan 03:
```php
\App\Foundation\Audit\Services\Auditor::record(string $action, array $context = []): void
\App\Foundation\Integration\Models\IntegrationEvent                                    // has created_at (no updated_at), append-only
```

From Plan 02:
```php
// Users with 'admin' role have been seeded.
$user->hasRole('admin')   // boolean check, used by HorizonServiceProvider::gate
```

From Plan 04:
```php
\App\Domain\Sync\Models\SyncDiff                   // has created_at, applied_at, status columns
// config('services.woo.write_enabled', false) — read by PruneSyncDiffsCommand
```

<!-- Horizon supervisor config verbatim from 01-RESEARCH.md §4: -->

```php
'environments' => [
    'production' => [
        'webhook-inbound-supervisor' => [
            'connection' => 'redis',
            'queue' => ['webhook-inbound'],
            'balance' => 'simple',
            'minProcesses' => 3,
            'maxProcesses' => 10,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'tries' => 3,
            'timeout' => 60,
            'memory' => 128,
        ],
        'crm-bitrix-supervisor' => [
            'connection' => 'redis',
            'queue' => ['crm-bitrix'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'tries' => 5,
            'timeout' => 120,
            'memory' => 256,
        ],
        'sync-woo-push-supervisor' => [
            'connection' => 'redis',
            'queue' => ['sync-woo-push'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 3,
            'tries' => 5,
            'timeout' => 90,
            'memory' => 256,
        ],
        'sync-bulk-supervisor' => [
            'connection' => 'redis',
            'queue' => ['sync-bulk'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'tries' => 2,
            'timeout' => 1800,
            'memory' => 512,
        ],
        'competitor-csv-supervisor' => [
            'connection' => 'redis',
            'queue' => ['competitor-csv'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'tries' => 3,
            'timeout' => 600,
            'memory' => 512,
        ],
        'critical-supervisor' => [
            'connection' => 'redis',
            'queue' => ['critical'],
            'balance' => 'simple',
            'minProcesses' => 2,
            'maxProcesses' => 5,
            'tries' => 3,
            'timeout' => 60,
            'memory' => 128,
        ],
        'default-supervisor' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'tries' => 3,
            'timeout' => 120,
            'memory' => 256,
        ],
    ],
    'local' => [
        'all-in-one' => [
            'connection' => 'redis',
            'queue' => ['critical', 'webhook-inbound', 'crm-bitrix', 'sync-woo-push', 'sync-bulk', 'competitor-csv', 'default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'tries' => 1,
            'timeout' => 300,
        ],
    ],
],
```

<!-- ThrottledFailedJobNotifier fingerprint (01-RESEARCH.md §9): -->

```php
$signature = md5(implode('|', [
    $event->job->resolveName(),
    $event->exception::class,
    $event->exception->getMessage(),
]));
$cacheKey = "failed-job-alert:{$signature}";
if (Cache::has($cacheKey)) return;
Cache::put($cacheKey, 1, now()->addMinutes(5));
Notification::send(app(AlertDistribution::class), new \Spatie\FailedJobMonitor\Notification($event));
```

<!-- AlertDistribution dynamic routing (01-RESEARCH.md §9): -->

```php
public function routeNotificationForMail(): array
{
    return AlertRecipient::where('is_active', true)->pluck('name', 'email')->all();
}
public function routeNotificationForSlack(): ?string { return null; }
```

<!-- Redis production config (FOUND-10, Claude's Discretion resolved, 01-RESEARCH.md §4): -->

```
appendonly yes
appendfsync everysec
appendfilename "appendonly.aof"
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
maxmemory 2gb
maxmemory-policy noeviction
```

<!-- Supervisor template (01-RESEARCH.md §4): -->

```ini
[program:horizon]
process_name=%(program_name)s
command=php /var/www/meetingstore-ops/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/horizon.log
stopwaitsecs=3600
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Internet → `/horizon` | Admin-only gate; unauthenticated users redirected to login |
| Failed-job event → email dispatch | Throttled to prevent alert storm; recipients from DB (D-12) |
| Retention prune command → persistent data | `sync_diffs` must be preserved while `WOO_WRITE_ENABLED=false` (D-08, Pitfall L) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-01 | E | Non-admin user accessing `/horizon` | mitigate | `HorizonServiceProvider::gate()` returns `$user->hasRole('admin')`; tested in `HorizonSupervisorTest` |
| T-05-02 | D | Alert storm during outage (thousands of failed jobs → thousands of emails) | mitigate | `ThrottledFailedJobNotifier` caches signature for 5 minutes (D-13); feature test proves second dispatch within window = 0 mail sends |
| T-05-03 | I | Empty `alert_recipients` table on first deploy → silent outage (Pitfall M) | mitigate | `AlertRecipientSeeder` seeds `ops@meetingstore.co.uk` with `is_active=true`; Filament AlertRecipientResource exposes this row for admin edit post-deploy |
| T-05-04 | D | `sync_diffs` pruned while shadow mode active → parity evidence lost (Pitfall L, D-08) | mitigate | `PruneSyncDiffsCommand::handle()` first line reads `config('services.woo.write_enabled', false)` — returns 0 + Auditor meta-audit row when false; feature test seeds a 100-day-old SyncDiff + runs command + asserts row still exists |
| T-05-05 | R | Retention prunes running while a previous instance is still executing (lock contention, partial deletes) | mitigate | `Schedule::command()->withoutOverlapping(30)` — 30-minute mutex on each prune command |
| T-05-06 | T | Double-firing failed-job alert (spatie package's listener + our custom listener) | mitigate | `config/failed-job-monitor.php` sets `'notifiable' => null` → package's auto-listener short-circuits; our EventServiceProvider registration is the ONLY email path |
| T-05-07 | I | AlertRecipient Resource exposed to non-admin → leak of ops email addresses | mitigate | `AlertRecipientPolicy` gates every action on `hasRole('admin')`; Pitfall K-style feature test asserts read_only/sales/pricing_manager all 403 |
| T-05-08 | T | Redis memory pressure evicting queue payloads → vanished jobs (Pitfall G) | mitigate | user_setup VPS task includes `maxmemory-policy noeviction` in /etc/redis/redis.conf; HorizonServiceProvider boot asserts `config('queue.default') === 'redis'` at boot (Pitfall E) |
| T-05-09 | D | Cron runs `schedule:run` on an old code path after deploy (race) | accept | Deploy runbook includes `php artisan horizon:terminate` step; cron calls the current `public_html/current/artisan` symlink (per standard Laravel deploy pattern — Plan 05 ships runbook note only, not the deploy tooling) |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: config/horizon.php 7 supervisors + HorizonServiceProvider admin gate + HorizonSupervisorTest</name>
  <files>config/horizon.php, app/Providers/HorizonServiceProvider.php, tests/Feature/HorizonSupervisorTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §4 (complete supervisor config — copy verbatim)
    - .planning/phases/01-foundation/01-CONTEXT.md (Claude's Discretion — worker counts derived from Woo 100/min and Bitrix 2/sec ceilings)
    - .planning/research/PITFALLS.md Pitfall E (QUEUE_CONNECTION=redis assertion), Pitfall G (noeviction — user_setup), Pitfall 8 (queue segregation)
    - config/horizon.php (post horizon:install default — has `waits`, `trim`, `silenced` sections; supervisors section replaced wholesale)
    - app/Providers/HorizonServiceProvider.php (auto-created by horizon:install — has gate() stub)
  </read_first>
  <behavior>
    - Test: `config('horizon.environments.production')` returns an array with exactly 7 keys matching the supervisor names
    - Test: Sum of all `queue` arrays across production supervisors contains exactly 7 unique values: critical, sync-woo-push, sync-bulk, crm-bitrix, competitor-csv, webhook-inbound, default
    - Test: crm-bitrix-supervisor maxProcesses = 2 (Bitrix 2 req/sec hard cap)
    - Test: sync-woo-push-supervisor maxProcesses <= 3 (Woo 100 req/min ceiling headroom)
    - Test: webhook-inbound-supervisor timeout = 60 (webhooks must drain fast)
    - Test: sync-bulk-supervisor timeout = 1800 (30 minutes for long chunks)
    - Test: HorizonServiceProvider::gate denies a user without admin role
    - Test: HorizonServiceProvider::gate grants a user with admin role
    - Test: HorizonServiceProvider::boot throws when `config('queue.default') !== 'redis'` (Pitfall E assertion)
  </behavior>
  <action>
    **Step A — Edit `config/horizon.php`**. Leave the top of the file (defaults, domain, path, waits, trim, silenced sections) unchanged. Replace the `environments` array with the exact block from the `<interfaces>` section above.

    Also ensure `'use'` (connection name) is set to `'horizon'` at the top of the file to use the logical Redis DB 2 from Plan 01's `config/database.php`:

    ```php
    // config/horizon.php top-level keys — ensure these values:
    'use'    => 'horizon',   // Redis connection from config/database.php (DB index 2)
    'prefix' => env('HORIZON_PREFIX', 'ops-horizon:'),
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    ```

    **Step B — Edit `app/Providers/HorizonServiceProvider.php`**. Merge the admin gate + Pitfall E assertion:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Providers;

    use Illuminate\Support\Facades\Gate;
    use Laravel\Horizon\Horizon;
    use Laravel\Horizon\HorizonApplicationServiceProvider;

    class HorizonServiceProvider extends HorizonApplicationServiceProvider
    {
        public function boot(): void
        {
            parent::boot();

            // Pitfall E: fail fast if queue driver is not redis — Horizon silently ignores non-redis
            // drivers and failed jobs go to different storage, making alerts/Horizon UI lie.
            throw_unless(
                config('queue.default') === 'redis',
                new \RuntimeException('Horizon requires QUEUE_CONNECTION=redis; found: ' . config('queue.default'))
            );
        }

        /** Register the Horizon gate — admin role only (D-02). */
        protected function gate(): void
        {
            Gate::define('viewHorizon', function ($user) {
                return $user !== null && $user->hasRole('admin');
            });
        }
    }
    ```

    **Step C — Write `tests/Feature/HorizonSupervisorTest.php`:**

    ```php
    <?php

    use App\Models\User;
    use Database\Seeders\RolePermissionSeeder;

    it('defines exactly 7 production supervisors', function () {
        $production = config('horizon.environments.production');
        expect($production)->toBeArray();
        expect(array_keys($production))->toHaveCount(7);

        $expected = [
            'webhook-inbound-supervisor',
            'crm-bitrix-supervisor',
            'sync-woo-push-supervisor',
            'sync-bulk-supervisor',
            'competitor-csv-supervisor',
            'critical-supervisor',
            'default-supervisor',
        ];
        expect(array_keys($production))->toMatchArray($expected);
    });

    it('production supervisors cover all 7 named queues', function () {
        $production = config('horizon.environments.production');
        $allQueues = collect($production)->flatMap(fn ($s) => $s['queue'])->unique()->values();

        $expected = collect(['critical', 'sync-woo-push', 'sync-bulk', 'crm-bitrix', 'competitor-csv', 'webhook-inbound', 'default'])->sort()->values();
        expect($allQueues->sort()->values()->all())->toBe($expected->all());
    });

    it('respects external rate limit ceilings (Bitrix 2/sec, Woo 100/min)', function () {
        $production = config('horizon.environments.production');
        expect($production['crm-bitrix-supervisor']['maxProcesses'])->toBe(2); // Bitrix 2/sec hard cap
        expect($production['sync-woo-push-supervisor']['maxProcesses'])->toBeLessThanOrEqual(3); // Woo headroom
    });

    it('webhook-inbound-supervisor timeout is short (webhooks drain fast)', function () {
        expect(config('horizon.environments.production.webhook-inbound-supervisor.timeout'))->toBe(60);
    });

    it('sync-bulk-supervisor timeout is long (30 min for chunked syncs)', function () {
        expect(config('horizon.environments.production.sync-bulk-supervisor.timeout'))->toBe(1800);
    });

    it('HorizonServiceProvider gate denies non-admin users', function () {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('read_only');

        $this->actingAs($user);
        expect(\Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
    });

    it('HorizonServiceProvider gate grants admin users', function () {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        expect(\Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
    });

    it('local environment has a single all-in-one supervisor', function () {
        $local = config('horizon.environments.local');
        expect($local)->toBeArray()
            ->and(array_keys($local))->toHaveCount(1)
            ->and($local['all-in-one']['queue'])->toContain('webhook-inbound', 'crm-bitrix', 'default');
    });
    ```

    Run: `vendor/bin/pest --filter=HorizonSupervisor` — all 8 tests pass.
  </action>
  <verify>
    <automated>grep -q "webhook-inbound-supervisor" config/horizon.php &amp;&amp; grep -q "crm-bitrix-supervisor" config/horizon.php &amp;&amp; grep -q "sync-woo-push-supervisor" config/horizon.php &amp;&amp; grep -q "sync-bulk-supervisor" config/horizon.php &amp;&amp; grep -q "competitor-csv-supervisor" config/horizon.php &amp;&amp; grep -q "critical-supervisor" config/horizon.php &amp;&amp; grep -q "default-supervisor" config/horizon.php &amp;&amp; grep -q "hasRole('admin')" app/Providers/HorizonServiceProvider.php &amp;&amp; vendor/bin/pest --filter=HorizonSupervisor</automated>
  </verify>
  <done>
    7 supervisors defined in production, 1 all-in-one in local; every queue from the 7-name set mapped to exactly one supervisor; admin-only gate enforced; Pitfall E assertion fires on wrong queue driver; HorizonSupervisorTest (8 tests) all pass.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: alert_recipients migration + AlertRecipient model + AlertDistribution Notifiable + ThrottledFailedJobNotifier listener + EventServiceProvider wiring + AlertRecipientResource (admin-only) + AlertRecipientSeeder + TestFailingJobCommand + FailedJobAlertTest</name>
  <files>database/migrations/2026_04_18_104000_create_alert_recipients_table.php, app/Domain/Alerting/Models/AlertRecipient.php, app/Domain/Alerting/Notifiables/AlertDistribution.php, app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php, app/Domain/Alerting/Policies/AlertRecipientPolicy.php, app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php, app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/ListAlertRecipients.php, app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/CreateAlertRecipient.php, app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/EditAlertRecipient.php, app/Providers/EventServiceProvider.php, app/Providers/AppServiceProvider.php, app/Console/Commands/TestFailingJobCommand.php, config/failed-job-monitor.php, database/seeders/AlertRecipientSeeder.php, database/seeders/DatabaseSeeder.php, tests/Feature/FailedJobAlertTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §9 (full AlertRecipient + AlertDistribution + ThrottledFailedJobNotifier + TestFailingJobCommand)
    - .planning/phases/01-foundation/01-CONTEXT.md D-10 (email only), D-11 (single distribution), D-12 (DB-backed AlertRecipient — NEW SCOPE), D-13 (5-min dedup)
    - .planning/research/PITFALLS.md Pitfall K (admin-only resource), Pitfall M (fallback seed recipient)
    - config/failed-job-monitor.php (published by Plan 01 vendor:publish)
    - app/Providers/EventServiceProvider.php (Laravel default)
    - app/Providers/AppServiceProvider.php (Plan 03 + Plan 04 state — has Context callbacks + SuggestionApplierResolver wiring + Suggestion policy)
  </read_first>
  <behavior>
    - Test: `alert_recipients` migration creates table with unique email column
    - Test: AlertRecipient model can create/find by email (unique constraint fires on duplicate)
    - Test: `AlertDistribution::routeNotificationForMail()` returns an array of `[email => name]` for all active recipients
    - Test: `routeNotificationForSlack()` returns null (D-10 explicit — NEVER route to Slack even if config drifts)
    - Test: `ThrottledFailedJobNotifier::handle($event)` calls `Notification::send` on first invocation
    - Test: Same listener invoked again with identical fingerprint within 5 min → zero additional Notification::send calls
    - Test: Different fingerprint (different exception message) → sends again
    - Test: `AlertRecipientSeeder` creates one row with `ops@meetingstore.co.uk`, idempotent on re-run
    - Test: `php artisan alerts:test-failure` causes ONE mail to each active recipient (via Mail::fake() assertion)
    - Test: AlertRecipientResource accessible by admin, 403 for read_only/sales/pricing_manager
  </behavior>
  <action>
    **Step A — Write `database/migrations/2026_04_18_104000_create_alert_recipients_table.php`:**

    Check: this timestamp (104000) is BEFORE webhook_receipts (105000), suggestions (107000), sync_diffs (108000). That's fine — the tables are independent. If activitylog migrations published by Plan 01 use 102000+, 104000 fits between.

    ```php
    <?php

    declare(strict_types=1);

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('alert_recipients', function (Blueprint $t) {
                $t->id();
                $t->string('email')->unique();
                $t->string('name')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('alert_recipients');
        }
    };
    ```

    **Step B — Write `app/Domain/Alerting/Models/AlertRecipient.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Alerting\Models;

    use Illuminate\Database\Eloquent\Model;

    class AlertRecipient extends Model
    {
        protected $fillable = ['email', 'name', 'is_active'];
        protected $casts = ['is_active' => 'boolean'];
    }
    ```

    **Step C — Write `app/Domain/Alerting/Notifiables/AlertDistribution.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Alerting\Notifiables;

    use App\Domain\Alerting\Models\AlertRecipient;
    use Illuminate\Notifications\Notifiable;

    /**
     * D-11 single distribution: every failed-job alert goes to every active AlertRecipient.
     *
     * Route resolution happens at notification-dispatch time so recipient list changes
     * in the Filament UI take effect immediately (no cache).
     *
     * D-10: email-only — routeNotificationForSlack() explicitly returns null even if
     * config drifts and enables slack channel.
     */
    final class AlertDistribution
    {
        use Notifiable;

        /** @return array<string, string>  map of email => name for Laravel mail routing */
        public function routeNotificationForMail(): array
        {
            return AlertRecipient::where('is_active', true)
                ->pluck('name', 'email')
                ->all();
        }

        public function routeNotificationForSlack(): ?string
        {
            return null;
        }
    }
    ```

    **Step D — Write `app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Alerting\Listeners;

    use App\Domain\Alerting\Notifiables\AlertDistribution;
    use Illuminate\Queue\Events\JobFailed;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Notification;

    /**
     * D-13 dedup: same failure signature within 5 minutes = ONE alert, not N.
     *
     * Fingerprint combines: job class + exception class + exception message.
     * Cached in Redis for 5 minutes; duplicate events short-circuit.
     */
    final class ThrottledFailedJobNotifier
    {
        public function handle(JobFailed $event): void
        {
            $signature = $this->fingerprint($event);
            $cacheKey  = "failed-job-alert:{$signature}";

            if (Cache::has($cacheKey)) {
                return;
            }

            Cache::put($cacheKey, 1, now()->addMinutes(5));

            Notification::send(
                app(AlertDistribution::class),
                new \Spatie\FailedJobMonitor\Notification($event)
            );
        }

        private function fingerprint(JobFailed $event): string
        {
            return md5(implode('|', [
                $event->job->resolveName(),
                $event->exception::class,
                $event->exception->getMessage(),
            ]));
        }
    }
    ```

    **Step E — Wire the listener in `app/Providers/EventServiceProvider.php`** (replace any existing listen array):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Providers;

    use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
    use Illuminate\Queue\Events\JobFailed;

    class EventServiceProvider extends ServiceProvider
    {
        protected $listen = [
            JobFailed::class => [
                \App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier::class,
            ],
        ];

        public function boot(): void
        {
            parent::boot();
        }
    }
    ```

    **Step F — Edit `config/failed-job-monitor.php` to disable the package's built-in listener (T-05-06):**

    ```php
    return [
        // SUPPRESS the package's auto-dispatch — we use ThrottledFailedJobNotifier instead (D-13 dedup).
        // Setting to null causes the package's listener to short-circuit before notifying.
        'notifiable' => null,

        'notification' => \Spatie\FailedJobMonitor\Notification::class,

        // D-10: email only (Slack explicitly rejected)
        'channels' => ['mail'],

        'mail' => [
            // Route dynamically via AlertDistribution (D-11) — leave empty
            'to' => [],
        ],

        'slack' => [
            'webhook_url' => null,
        ],
    ];
    ```

    **Step G — Write the AlertRecipientPolicy** (T-05-07 admin-only gate):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Alerting\Policies;

    use App\Domain\Alerting\Models\AlertRecipient;
    use App\Models\User;

    /**
     * Admin-only access to the distribution list. Leak of these email addresses
     * would expose internal ops staff to targeted phishing.
     */
    class AlertRecipientPolicy
    {
        public function viewAny(User $user): bool { return $user->hasRole('admin'); }
        public function view(User $user, AlertRecipient $r): bool { return $user->hasRole('admin'); }
        public function create(User $user): bool { return $user->hasRole('admin'); }
        public function update(User $user, AlertRecipient $r): bool { return $user->hasRole('admin'); }
        public function delete(User $user, AlertRecipient $r): bool { return $user->hasRole('admin'); }
    }
    ```

    Register in `AppServiceProvider::boot()` (extend Plan 04's state):

    ```php
    \Illuminate\Support\Facades\Gate::policy(
        \App\Domain\Alerting\Models\AlertRecipient::class,
        \App\Domain\Alerting\Policies\AlertRecipientPolicy::class
    );
    ```

    **Step H — Write `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Alerting\Filament\Resources;

    use App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;
    use App\Domain\Alerting\Models\AlertRecipient;
    use Filament\Forms\Components\TextInput;
    use Filament\Forms\Components\Toggle;
    use Filament\Forms\Form;
    use Filament\Resources\Resource;
    use Filament\Tables\Columns\IconColumn;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Table;

    class AlertRecipientResource extends Resource
    {
        protected static ?string $model           = AlertRecipient::class;
        protected static ?string $navigationIcon  = 'heroicon-o-envelope';
        protected static ?string $navigationGroup = 'Settings';
        protected static ?string $navigationLabel = 'Alert Recipients';

        public static function form(Form $form): Form
        {
            return $form->schema([
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('name')->maxLength(255),
                Toggle::make('is_active')->default(true),
            ]);
        }

        public static function table(Table $table): Table
        {
            return $table
                ->columns([
                    TextColumn::make('email')->searchable(),
                    TextColumn::make('name')->searchable(),
                    IconColumn::make('is_active')->boolean(),
                    TextColumn::make('created_at')->dateTime()->sortable(),
                ])
                ->defaultSort('email');
        }

        public static function getPages(): array
        {
            return [
                'index'  => Pages\ListAlertRecipients::route('/'),
                'create' => Pages\CreateAlertRecipient::route('/create'),
                'edit'   => Pages\EditAlertRecipient::route('/{record}/edit'),
            ];
        }
    }
    ```

    Create the 3 page classes (small scaffolds):

    ```php
    // ListAlertRecipients.php
    namespace App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;
    use App\Domain\Alerting\Filament\Resources\AlertRecipientResource;
    use Filament\Actions\CreateAction;
    use Filament\Resources\Pages\ListRecords;
    class ListAlertRecipients extends ListRecords
    {
        protected static string $resource = AlertRecipientResource::class;
        protected function getHeaderActions(): array { return [CreateAction::make()]; }
    }

    // CreateAlertRecipient.php
    namespace App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;
    use App\Domain\Alerting\Filament\Resources\AlertRecipientResource;
    use Filament\Resources\Pages\CreateRecord;
    class CreateAlertRecipient extends CreateRecord
    {
        protected static string $resource = AlertRecipientResource::class;
    }

    // EditAlertRecipient.php
    namespace App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;
    use App\Domain\Alerting\Filament\Resources\AlertRecipientResource;
    use Filament\Actions\DeleteAction;
    use Filament\Resources\Pages\EditRecord;
    class EditAlertRecipient extends EditRecord
    {
        protected static string $resource = AlertRecipientResource::class;
        protected function getHeaderActions(): array { return [DeleteAction::make()]; }
    }
    ```

    **Step I — Re-run `shield:generate` + seeder so `alert_recipient` permissions land on admin:**

    ```bash
    php artisan shield:generate --all --panel=admin
    php artisan db:seed --class=RolePermissionSeeder --force
    ```

    **Step J — Write `app/Console/Commands/TestFailingJobCommand.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Bus\Queueable;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;

    class TestFailingJobCommand extends Command
    {
        protected $signature = 'alerts:test-failure';
        protected $description = 'Dispatches a deliberately-failing job to exercise the failed-job alert path';

        public function handle(): int
        {
            TestFailingJob::dispatch();
            $this->info('Test job dispatched. After the queue processes it, a single alert email should land at each active AlertRecipient (dedup window: 5 minutes).');
            return self::SUCCESS;
        }
    }

    class TestFailingJob implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
        public string $queue = 'default';
        public int $tries = 1;
        public function handle(): void
        {
            throw new \RuntimeException('Deliberate failure — alerts:test-failure');
        }
    }
    ```

    **Step K — Write `database/seeders/AlertRecipientSeeder.php` (Pitfall M):**

    ```php
    <?php

    declare(strict_types=1);

    namespace Database\Seeders;

    use App\Domain\Alerting\Models\AlertRecipient;
    use Illuminate\Database\Seeder;

    class AlertRecipientSeeder extends Seeder
    {
        public function run(): void
        {
            AlertRecipient::firstOrCreate(
                ['email' => 'ops@meetingstore.co.uk'],
                ['name' => 'Ops Fallback', 'is_active' => true]
            );
        }
    }
    ```

    Wire in `database/seeders/DatabaseSeeder.php`:

    ```php
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            TestSuggestionSeeder::class,
            AlertRecipientSeeder::class,
        ]);
    }
    ```

    **Step L — Write `tests/Feature/FailedJobAlertTest.php`:**

    ```php
    <?php

    use App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier;
    use App\Domain\Alerting\Models\AlertRecipient;
    use App\Domain\Alerting\Notifiables\AlertDistribution;
    use App\Models\User;
    use Database\Seeders\AlertRecipientSeeder;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Queue\Events\JobFailed;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Notification;

    beforeEach(function () {
        $this->seed([RolePermissionSeeder::class, AlertRecipientSeeder::class]);
        Cache::flush();
    });

    /** Build a fake JobFailed event. */
    function fakeJobFailed(string $jobClass = 'App\\Test\\FakeJob', string $exMessage = 'boom'): JobFailed
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('resolveName')->andReturn($jobClass);
        $job->shouldReceive('payload')->andReturn(['job' => $jobClass]);

        return new JobFailed('redis', $job, new \RuntimeException($exMessage));
    }

    it('AlertDistribution routes mail to all active recipients', function () {
        AlertRecipient::create(['email' => 'a@example.com', 'name' => 'Alice', 'is_active' => true]);
        AlertRecipient::create(['email' => 'b@example.com', 'name' => 'Bob',   'is_active' => false]);

        $routes = (new AlertDistribution())->routeNotificationForMail();

        // ops@meetingstore.co.uk (from seeder) + a@example.com = 2 active recipients; Bob excluded
        expect($routes)->toHaveKey('ops@meetingstore.co.uk');
        expect($routes)->toHaveKey('a@example.com');
        expect($routes)->not->toHaveKey('b@example.com');
    });

    it('AlertDistribution explicitly refuses to route to Slack (D-10)', function () {
        expect((new AlertDistribution())->routeNotificationForSlack())->toBeNull();
    });

    it('ThrottledFailedJobNotifier sends a notification on first invocation', function () {
        Notification::fake();

        $listener = app(ThrottledFailedJobNotifier::class);
        $listener->handle(fakeJobFailed());

        Notification::assertSentTo([new AlertDistribution()], \Spatie\FailedJobMonitor\Notification::class);
    });

    it('ThrottledFailedJobNotifier does NOT send a second notification within 5 minutes for the same fingerprint (D-13)', function () {
        Notification::fake();

        $listener = app(ThrottledFailedJobNotifier::class);
        $event = fakeJobFailed('JobA', 'identical-message');
        $listener->handle($event);
        $listener->handle($event);

        Notification::assertSentTimes(\Spatie\FailedJobMonitor\Notification::class, 1);
    });

    it('ThrottledFailedJobNotifier sends for different fingerprints', function () {
        Notification::fake();

        $listener = app(ThrottledFailedJobNotifier::class);
        $listener->handle(fakeJobFailed('JobA', 'message-1'));
        $listener->handle(fakeJobFailed('JobA', 'message-2')); // different message = different fingerprint

        Notification::assertSentTimes(\Spatie\FailedJobMonitor\Notification::class, 2);
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

    it('admin role CAN viewAny AlertRecipient', function () {
        $user = User::factory()->create();
        $user->assignRole('admin');
        expect($user->can('viewAny', AlertRecipient::class))->toBeTrue();
    });

    it('config/failed-job-monitor.php has notifiable=null (package listener suppressed)', function () {
        expect(config('failed-job-monitor.notifiable'))->toBeNull();
        expect(config('failed-job-monitor.channels'))->toBe(['mail']);
    });
    ```

    Run: `vendor/bin/pest --filter=FailedJobAlert` — all 10 tests pass.
  </action>
  <verify>
    <automated>test -f database/migrations/2026_04_18_104000_create_alert_recipients_table.php &amp;&amp; test -f app/Domain/Alerting/Models/AlertRecipient.php &amp;&amp; test -f app/Domain/Alerting/Notifiables/AlertDistribution.php &amp;&amp; test -f app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php &amp;&amp; test -f app/Domain/Alerting/Policies/AlertRecipientPolicy.php &amp;&amp; test -f app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php &amp;&amp; test -f database/seeders/AlertRecipientSeeder.php &amp;&amp; grep -q "AlertRecipientSeeder::class" database/seeders/DatabaseSeeder.php &amp;&amp; grep -q "ThrottledFailedJobNotifier" app/Providers/EventServiceProvider.php &amp;&amp; grep -q "'notifiable' => null" config/failed-job-monitor.php &amp;&amp; php artisan migrate --force &amp;&amp; php artisan db:seed --class=AlertRecipientSeeder --force &amp;&amp; vendor/bin/pest --filter=FailedJobAlert</automated>
  </verify>
  <done>
    alert_recipients table migrated; AlertRecipient model + Filament Resource (admin-only via policy); AlertDistribution Notifiable routes to active recipients only (mail channel only); ThrottledFailedJobNotifier dedup with 5-min cache key keyed by job+exception fingerprint; EventServiceProvider maps JobFailed → our listener; spatie package's auto-listener disabled via notifiable=null; AlertRecipientSeeder ships ops@meetingstore.co.uk fallback (Pitfall M); TestFailingJobCommand available via `php artisan alerts:test-failure`; FailedJobAlertTest (10 tests) all pass.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: 3 retention prune commands (activity log, integration events, sync_diffs conditional) + routes/console.php schedule + RetentionPruneTest</name>
  <files>app/Console/Commands/PruneActivityLogCommand.php, app/Console/Commands/PruneIntegrationEventsCommand.php, app/Console/Commands/PruneSyncDiffsCommand.php, routes/console.php, tests/Feature/RetentionPruneTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §11 (retention prune scheduling) + §5 (PruneActivityLogCommand code) + §8 (sync_diffs conditional prune)
    - .planning/phases/01-foundation/01-CONTEXT.md D-04 (365d audit), D-05 (90d integration_events), D-07 (90d sync_errors — Phase 2 ships this, NOT Phase 1), D-08 (sync_diffs conditional), D-09 (all prunes log meta-audit via Auditor)
    - .planning/research/PITFALLS.md Pitfall L (sync_diffs prune while shadow active — regression test)
    - app/Foundation/Audit/Services/Auditor.php (Plan 03 — the service prune commands call)
  </read_first>
  <behavior>
    - Test: `php artisan activitylog:prune --days=365` deletes activity_log rows older than 365 days and leaves recent ones intact
    - Test: The prune produces an activity_log row with description=`activitylog.pruned` and properties.deleted_count (D-09 meta-audit)
    - Test: `php artisan integration-events:prune --days=90` deletes integration_events rows older than 90 days and writes meta-audit
    - Test: `php artisan sync-diffs:prune` with WOO_WRITE_ENABLED=false DOES NOT delete anything (Pitfall L) and writes meta-audit with action=sync-diffs.prune.skipped
    - Test: `php artisan sync-diffs:prune` with WOO_WRITE_ENABLED=true DELETES sync_diffs older than 30 days where applied_at is not null
    - Test: `routes/console.php` schedules all 3 commands with `withoutOverlapping()`
  </behavior>
  <action>
    **Step A — Write `app/Console/Commands/PruneActivityLogCommand.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use App\Foundation\Audit\Services\Auditor;
    use Illuminate\Console\Command;
    use Spatie\Activitylog\Models\Activity;

    /**
     * D-04: prune activity_log rows older than N days (default 365).
     * D-09: prune action itself logged as a meta-audit entry.
     */
    class PruneActivityLogCommand extends Command
    {
        protected $signature = 'activitylog:prune {--days=365}';
        protected $description = 'Prune activity_log rows older than --days (default 365)';

        public function handle(Auditor $auditor): int
        {
            $days   = (int) $this->option('days');
            $cutoff = now()->subDays($days);

            $deleted = Activity::where('created_at', '<', $cutoff)->delete();

            $auditor->record('activitylog.pruned', [
                'deleted_count' => $deleted,
                'cutoff_date'   => $cutoff->toIso8601String(),
                'days'          => $days,
            ]);

            $this->info("Pruned {$deleted} activity_log rows older than {$days} days.");
            return self::SUCCESS;
        }
    }
    ```

    **Step B — Write `app/Console/Commands/PruneIntegrationEventsCommand.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use App\Foundation\Audit\Services\Auditor;
    use App\Foundation\Integration\Models\IntegrationEvent;
    use Illuminate\Console\Command;

    /**
     * D-05: prune integration_events rows older than N days (default 90).
     * Volume: outbound API call log. Can hit tens of millions of rows in production.
     */
    class PruneIntegrationEventsCommand extends Command
    {
        protected $signature = 'integration-events:prune {--days=90}';
        protected $description = 'Prune integration_events rows older than --days (default 90)';

        public function handle(Auditor $auditor): int
        {
            $days   = (int) $this->option('days');
            $cutoff = now()->subDays($days);

            $deleted = IntegrationEvent::where('created_at', '<', $cutoff)->delete();

            $auditor->record('integration-events.pruned', [
                'deleted_count' => $deleted,
                'cutoff_date'   => $cutoff->toIso8601String(),
                'days'          => $days,
            ]);

            $this->info("Pruned {$deleted} integration_events rows older than {$days} days.");
            return self::SUCCESS;
        }
    }
    ```

    **Step C — Write `app/Console/Commands/PruneSyncDiffsCommand.php` (D-08 conditional):**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use App\Domain\Sync\Models\SyncDiff;
    use App\Foundation\Audit\Services\Auditor;
    use Illuminate\Console\Command;

    /**
     * D-08 conditional prune:
     *   - While WOO_WRITE_ENABLED=false (pre-Phase-7 cutover): NEVER PRUNE (sync_diffs is parity evidence)
     *   - After cutover (flag=true): prune applied rows older than 30 days
     *
     * Pitfall L: reversed conditional would nuke parity evidence. This command's first line
     * is the flag check; tests assert the skip path writes a meta-audit with reason="WOO_WRITE_ENABLED false".
     */
    class PruneSyncDiffsCommand extends Command
    {
        protected $signature = 'sync-diffs:prune';
        protected $description = 'Prune sync_diffs older than 30 days (post-cutover only — no-op while WOO_WRITE_ENABLED=false)';

        public function handle(Auditor $auditor): int
        {
            // D-08 / Pitfall L: never prune while shadow mode is active
            if (! (bool) config('services.woo.write_enabled', false)) {
                $auditor->record('sync-diffs.prune.skipped', [
                    'reason' => 'WOO_WRITE_ENABLED is false; diffs are parity evidence for Phase 7 cutover.',
                ]);
                $this->info('Skipped — WOO_WRITE_ENABLED=false.');
                return self::SUCCESS;
            }

            // Post-cutover: 30-day retention for APPLIED diffs; pending/un-applied stay for investigation
            $cutoff  = now()->subDays(30);
            $deleted = SyncDiff::where('created_at', '<', $cutoff)
                ->whereNotNull('applied_at')
                ->delete();

            $auditor->record('sync-diffs.pruned', [
                'deleted_count' => $deleted,
                'cutoff_date'   => $cutoff->toIso8601String(),
            ]);

            $this->info("Pruned {$deleted} applied sync_diffs rows older than 30 days.");
            return self::SUCCESS;
        }
    }
    ```

    **Step D — Write `routes/console.php` schedule** (replace existing Laravel default content):

    ```php
    <?php

    use Illuminate\Support\Facades\Schedule;

    /*
    |--------------------------------------------------------------------------
    | Console Schedule
    |--------------------------------------------------------------------------
    |
    | D-09 retention prune schedule. Staggered by 10-minute intervals to spread
    | DB load; each uses withoutOverlapping(30) to prevent a slow prune colliding
    | with the next day's cron fire.
    */

    // D-04: audit_log — 365 days
    Schedule::command('activitylog:prune --days=365')
        ->dailyAt('03:00')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->onQueue('default');

    // D-05: integration_events — 90 days
    Schedule::command('integration-events:prune --days=90')
        ->dailyAt('03:10')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->onQueue('default');

    // D-08: sync_diffs — conditional (no-op while WOO_WRITE_ENABLED=false)
    Schedule::command('sync-diffs:prune')
        ->dailyAt('03:30')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->onQueue('default');

    // Phase 2 adds: sync-errors:prune (D-07)
    // Phase 5 adds: competitor-csv:prune (D-06)
    ```

    **Step E — Write `tests/Feature/RetentionPruneTest.php`:**

    ```php
    <?php

    use App\Domain\Sync\Models\SyncDiff;
    use App\Foundation\Integration\Models\IntegrationEvent;
    use Spatie\Activitylog\Models\Activity;

    it('prunes activity_log rows older than --days and leaves recent ones', function () {
        // Seed 2 old rows + 1 recent row
        activity('system')->log('old-1');
        activity('system')->log('old-2');
        Activity::query()->update(['created_at' => now()->subDays(400)]); // age them

        activity('system')->log('recent');

        $this->artisan('activitylog:prune', ['--days' => 365])
            ->expectsOutputToContain('Pruned 2 activity_log rows')
            ->assertExitCode(0);

        expect(Activity::where('description', 'recent')->count())->toBe(1);
        expect(Activity::count())->toBeGreaterThanOrEqual(2); // recent + meta-audit row about the prune itself
    });

    it('writes a meta-audit row when activity_log is pruned (D-09)', function () {
        $this->artisan('activitylog:prune', ['--days' => 365])->assertExitCode(0);

        expect(Activity::where('log_name', 'system')->where('description', 'activitylog.pruned')->exists())->toBeTrue();
    });

    it('prunes integration_events older than --days and writes meta-audit', function () {
        $old = \DB::table('integration_events')->insert([
            'channel' => 'woo', 'direction' => 'outbound', 'operation' => 'old', 'endpoint' => '/', 'method' => 'GET',
            'status' => 'success', 'correlation_id' => 'cid-old', 'created_at' => now()->subDays(100),
        ]);
        $recent = \DB::table('integration_events')->insert([
            'channel' => 'woo', 'direction' => 'outbound', 'operation' => 'recent', 'endpoint' => '/', 'method' => 'GET',
            'status' => 'success', 'correlation_id' => 'cid-recent', 'created_at' => now()->subDays(10),
        ]);

        $this->artisan('integration-events:prune', ['--days' => 90])->assertExitCode(0);

        expect(IntegrationEvent::where('operation', 'old')->count())->toBe(0);
        expect(IntegrationEvent::where('operation', 'recent')->count())->toBe(1);
        expect(Activity::where('description', 'integration-events.pruned')->exists())->toBeTrue();
    });

    it('SKIPS sync_diffs prune while WOO_WRITE_ENABLED=false (D-08 / Pitfall L)', function () {
        config(['services.woo.write_enabled' => false]);

        // Seed a 100-day-old SyncDiff
        SyncDiff::create([
            'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/1',
            'payload' => ['x' => 1], 'correlation_id' => 'cid-1', 'created_at' => now()->subDays(100),
        ]);

        $this->artisan('sync-diffs:prune')
            ->expectsOutputToContain('Skipped')
            ->assertExitCode(0);

        // Row must still exist — parity evidence preserved
        expect(SyncDiff::count())->toBe(1);

        // Meta-audit records the skip reason
        expect(Activity::where('description', 'sync-diffs.prune.skipped')->exists())->toBeTrue();
    });

    it('prunes sync_diffs older than 30 days when WOO_WRITE_ENABLED=true (post-cutover)', function () {
        config(['services.woo.write_enabled' => true]);

        // Old, applied
        SyncDiff::create([
            'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/1',
            'payload' => [], 'correlation_id' => 'cid-old-applied',
            'created_at' => now()->subDays(60), 'applied_at' => now()->subDays(55),
            'status' => 'applied',
        ]);
        // Old, but NOT applied — must be kept
        SyncDiff::create([
            'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/2',
            'payload' => [], 'correlation_id' => 'cid-old-pending',
            'created_at' => now()->subDays(60), 'applied_at' => null,
            'status' => 'pending',
        ]);
        // Recent, applied
        SyncDiff::create([
            'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/3',
            'payload' => [], 'correlation_id' => 'cid-recent',
            'created_at' => now()->subDays(5), 'applied_at' => now()->subDays(3),
            'status' => 'applied',
        ]);

        $this->artisan('sync-diffs:prune')->assertExitCode(0);

        expect(SyncDiff::where('correlation_id', 'cid-old-applied')->count())->toBe(0);
        expect(SyncDiff::where('correlation_id', 'cid-old-pending')->count())->toBe(1); // un-applied kept
        expect(SyncDiff::where('correlation_id', 'cid-recent')->count())->toBe(1);
    });

    it('schedules all 3 prune commands in routes/console.php', function () {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $commands = collect($schedule->events())->map(fn ($e) => $e->command ?? $e->description)->filter();

        $found = $commands->filter(fn ($c) => str_contains((string) $c, 'activitylog:prune')
            || str_contains((string) $c, 'integration-events:prune')
            || str_contains((string) $c, 'sync-diffs:prune')
        );

        expect($found)->toHaveCount(3);
    });
    ```

    Run: `vendor/bin/pest --filter=RetentionPrune` — all 6 tests pass.
  </action>
  <verify>
    <automated>test -f app/Console/Commands/PruneActivityLogCommand.php &amp;&amp; test -f app/Console/Commands/PruneIntegrationEventsCommand.php &amp;&amp; test -f app/Console/Commands/PruneSyncDiffsCommand.php &amp;&amp; grep -q "activitylog:prune" routes/console.php &amp;&amp; grep -q "integration-events:prune" routes/console.php &amp;&amp; grep -q "sync-diffs:prune" routes/console.php &amp;&amp; grep -q "withoutOverlapping" routes/console.php &amp;&amp; php artisan list | grep -q "activitylog:prune" &amp;&amp; php artisan list | grep -q "integration-events:prune" &amp;&amp; php artisan list | grep -q "sync-diffs:prune" &amp;&amp; vendor/bin/pest --filter=RetentionPrune</automated>
  </verify>
  <done>
    3 prune commands all registered + callable via artisan; each writes a meta-audit row (D-09); sync_diffs prune is conditional on services.woo.write_enabled (D-08); routes/console.php schedules all 3 at staggered times with `withoutOverlapping(30)`; RetentionPruneTest (6 tests) all pass including the Pitfall L regression (100-day-old SyncDiff survives prune while shadow active).
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: CI pipeline (.github/workflows/ci.yml) running Deptrac + Pest + Larastan + Pint + Architecture test asserting module boundaries</name>
  <files>.github/workflows/ci.yml, tests/Architecture/DeptracTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §2 (Deptrac CI integration) + "Validation Architecture" (Phase 1 Success Criterion → Test Map)
    - .planning/research/PITFALLS.md Pitfall I (Deptrac false-positives on container bindings)
    - depfile.yaml (from Plan 01 — must exist)
    - phpstan.neon (from Plan 01 — must exist)
    - pint.json (from Plan 01 — must exist)
  </read_first>
  <behavior>
    - Test: `tests/Architecture/DeptracTest.php` shells out to `vendor/bin/deptrac analyse` and asserts exit code 0
    - Test: A deliberately-planted violator file (created then deleted inside the test) makes DeptracTest fail — proving the test catches real violations. **This is integrated into the single architecture test, not a separate test file; the test flips a flag, creates the file, asserts exit != 0, cleans up, asserts exit == 0**.
    - .github/workflows/ci.yml exists and defines jobs for: Pint check, Larastan, Pest, Deptrac
  </behavior>
  <action>
    **Step A — Write `.github/workflows/ci.yml`:**

    ```yaml
    name: CI

    on:
      pull_request:
      push:
        branches: [main]

    jobs:
      lint:
        name: Pint (PSR-12 style)
        runs-on: ubuntu-latest
        steps:
          - uses: actions/checkout@v4
          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.2'
              extensions: mbstring, intl, bcmath, redis
          - uses: ramsey/composer-install@v3
          - run: vendor/bin/pint --test

      static-analysis:
        name: Larastan
        runs-on: ubuntu-latest
        steps:
          - uses: actions/checkout@v4
          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.2'
              extensions: mbstring, intl, bcmath, redis
          - uses: ramsey/composer-install@v3
          - run: vendor/bin/phpstan analyse --memory-limit=1G --no-progress

      deptrac:
        name: Deptrac (module boundaries)
        runs-on: ubuntu-latest
        steps:
          - uses: actions/checkout@v4
          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.2'
              extensions: mbstring, intl, bcmath, redis
          - uses: ramsey/composer-install@v3
          - run: vendor/bin/deptrac analyse --no-progress --fail-on-uncovered

      tests:
        name: Pest feature + unit + architecture
        runs-on: ubuntu-latest
        services:
          mysql:
            image: mysql:8.0
            env:
              MYSQL_ROOT_PASSWORD: root
              MYSQL_DATABASE: meetingstore_ops_test
            ports: ['3306:3306']
            options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3
          redis:
            image: redis:7
            ports: ['6379:6379']
            options: --health-cmd="redis-cli ping" --health-interval=10s --health-timeout=5s --health-retries=3
        steps:
          - uses: actions/checkout@v4
          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.2'
              extensions: mbstring, intl, bcmath, redis, pdo_mysql
          - uses: ramsey/composer-install@v3
          - name: Prepare .env
            run: |
              cp .env.example .env
              php artisan key:generate
              sed -i 's/^DB_USERNAME=.*/DB_USERNAME=root/' .env
              sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=root/' .env
              sed -i 's/^DB_DATABASE=.*/DB_DATABASE=meetingstore_ops_test/' .env
          - name: Migrate
            run: php artisan migrate --force
          - name: Run Pest
            run: vendor/bin/pest --coverage --min=60
    ```

    **Step B — Write `tests/Architecture/DeptracTest.php`** (run as a Pest test; uses shell exec):

    ```php
    <?php

    declare(strict_types=1);

    use Symfony\Component\Process\Process;

    /*
    | tests/Architecture/DeptracTest.php
    |
    | Wraps `vendor/bin/deptrac analyse` as a Pest feature-level assertion. The CI
    | workflow also runs Deptrac standalone (see .github/workflows/ci.yml) — this
    | test ensures local `vendor/bin/pest` runs catch violations before push.
    */

    it('has zero module-boundary violations', function () {
        $process = new Process(['vendor/bin/deptrac', 'analyse', '--no-progress', '--fail-on-uncovered']);
        $process->setTimeout(120);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())->not->toContain('Found ');
    });

    it('catches a deliberate cross-domain violation (negative test)', function () {
        $violatorFile = base_path('app/Domain/Products/Services/__DeptracViolator.php');
        $violatorDir  = dirname($violatorFile);

        if (! is_dir($violatorDir)) {
            mkdir($violatorDir, 0755, true);
        }

        // Write a file that imports across module boundaries — Deptrac MUST catch this
        file_put_contents($violatorFile, <<<'PHP'
        <?php

        namespace App\Domain\Products\Services;

        // DELIBERATE VIOLATION — Products must not depend on CRM (negative test)
        use App\Domain\CRM\Services\BitrixClient;

        class __DeptracViolator
        {
            public function __construct(private BitrixClient $client) {}
        }
        PHP);

        $process = new Process(['vendor/bin/deptrac', 'analyse', '--no-progress']);
        $process->run();
        $exitCode = $process->getExitCode();
        $output   = $process->getOutput();

        // Clean up BEFORE assertions so a failed assertion doesn't leave the violator in place
        @unlink($violatorFile);

        // Deptrac should have exited non-zero
        expect($exitCode)->not->toBe(0);
    });
    ```

    **Step C — Verify test runs locally:**

    ```bash
    vendor/bin/pest --filter=DeptracTest
    # Must exit 0 — both positive and negative cases pass
    ```
  </action>
  <verify>
    <automated>test -f .github/workflows/ci.yml &amp;&amp; test -f tests/Architecture/DeptracTest.php &amp;&amp; grep -q "vendor/bin/deptrac analyse" .github/workflows/ci.yml &amp;&amp; grep -q "vendor/bin/pest" .github/workflows/ci.yml &amp;&amp; grep -q "vendor/bin/phpstan" .github/workflows/ci.yml &amp;&amp; grep -q "vendor/bin/pint" .github/workflows/ci.yml &amp;&amp; vendor/bin/deptrac analyse --no-progress &amp;&amp; vendor/bin/pest --filter=DeptracTest</automated>
  </verify>
  <done>
    CI workflow runs Pint + Larastan + Deptrac + Pest; DeptracTest proves both positive (clean codebase passes) AND negative (deliberate violator caught then reverted) scenarios; vendor/bin/deptrac analyse exits 0 on the current codebase.
  </done>
</task>

</tasks>

<threat_model>
(See plan-level threat model — 9 threats addressed.)
</threat_model>

<verification>
- `vendor/bin/pest --filter=HorizonSupervisor` passes (8 tests)
- `vendor/bin/pest --filter=FailedJobAlert` passes (10 tests)
- `vendor/bin/pest --filter=RetentionPrune` passes (6 tests)
- `vendor/bin/pest --filter=DeptracTest` passes (2 tests)
- `vendor/bin/pest` full suite passes (regression — no prior tests broken)
- `vendor/bin/deptrac analyse --no-progress` exits 0
- `vendor/bin/phpstan analyse --memory-limit=1G` exits 0 (may require ignoreErrors additions for generated Filament resource stubs — permissible)
- `vendor/bin/pint --test` exits 0 (all new code is PSR-12)
- `php artisan list` shows `activitylog:prune`, `integration-events:prune`, `sync-diffs:prune`, `alerts:test-failure`
- `php artisan schedule:list` shows all 3 prune commands at 03:00 / 03:10 / 03:30 with `--without-overlapping`
- `php artisan db:seed --class=DatabaseSeeder --force` runs clean with 4 roles + 1 test suggestion + 1 alert recipient seeded
</verification>

<success_criteria>
- **FOUND-09 (7 Horizon queues):** production env has 7 supervisors each mapping to exactly one of the 7 named queues; admin-only gate on /horizon; Pitfall E assertion in boot()
- **FOUND-10 (Redis persistence):** user_setup documents the `/etc/redis/redis.conf` config (appendonly yes + appendfsync everysec + noeviction + 2GB maxmemory); production deployment applies this before Horizon starts
- **FOUND-11 (failed-job alerts):** email-only channel via DB-backed AlertRecipient list (D-12 new scope); 5-minute dedup window (D-13) proven in feature test; spatie package's built-in listener disabled to prevent double-send; Pitfall M fallback seed
- **FOUND-12 (retention prunes):** 3 commands scheduled with `withoutOverlapping(30)`, each writing a meta-audit row (D-09); sync_diffs conditional respects WOO_WRITE_ENABLED=false (Pitfall L regression test); Phase 2/5 will add competitor-csv and sync-errors prunes
- **Success Criterion 5 (roadmap):** 7 supervisors boot; `php artisan alerts:test-failure` triggers a single email to active recipients; second dispatch within 5 min triggers zero additional emails
- **CI pipeline:** Deptrac + Pest + Larastan + Pint gate every PR; architecture test proves module boundaries catch violations
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation/01-05-SUMMARY.md` documenting: the final worker-count tuning (any deviation from the §4 table), the observed dedup test outcome (Mail::fake() count), and any larastan ignoreErrors rules added to handle Filament Resource static-only property noise. Also confirm whether `php artisan horizon` boots locally and lists 7 supervisor rows in the /horizon UI (spot check).
</output>
