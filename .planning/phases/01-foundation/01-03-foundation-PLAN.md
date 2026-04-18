---
phase: 01-foundation
plan: 03
type: execute
wave: 2
depends_on: [01-01-scaffold]
files_modified:
  - app/Http/Middleware/AttachCorrelationId.php
  - app/Foundation/Events/DomainEvent.php
  - app/Foundation/Audit/Services/Auditor.php
  - app/Foundation/Integration/Models/IntegrationEvent.php
  - app/Foundation/Integration/Services/IntegrationLogger.php
  - app/Console/Commands/BaseCommand.php
  - app/Providers/AppServiceProvider.php
  - bootstrap/app.php
  - database/migrations/2026_04_18_106000_create_integration_events_table.php
  - tests/Feature/CorrelationIdPropagationTest.php
  - tests/Feature/AuditorTest.php
  - tests/Feature/IntegrationLoggerTest.php
autonomous: true
requirements:
  - FOUND-03
  - FOUND-04
  - FOUND-05
user_setup: []

must_haves:
  truths:
    - "`AttachCorrelationId` middleware injects a UUIDv4 into Laravel 12 `Context` on every request (or honours inbound `X-Correlation-Id` / `X-Request-Id` header)"
    - "Response includes `X-Correlation-Id` header matching the generated/inbound value"
    - "A `DomainEvent` abstract base class exists at `app/Foundation/Events/DomainEvent.php` with `correlationId` and `occurredAt` readonly properties auto-populated from `Context`"
    - "An `Auditor` service at `app/Foundation/Audit/Services/Auditor.php` wraps spatie/activitylog with `record(string $action, array $context)` signature and threads `correlation_id` via `LogBatch::setBatch()`"
    - "`integration_events` table exists with schema from 01-RESEARCH.md §6 (id, channel, direction, operation, nullableMorphs subject, correlation_id indexed, endpoint, method, request_body, request_headers, response_body, http_status, latency_ms, attempt, status, error_message, created_at — NO updated_at)"
    - "`IntegrationLogger::log(array $data): IntegrationEvent` redacts sensitive headers (`authorization`, `x-wc-webhook-signature`, `cookie`, `x-bitrix-signature`) before persist"
    - "`BaseCommand` abstract class attaches correlation_id at command handle() entry so artisan commands thread through audit/integration logs same as HTTP requests"
    - "`Context::hydrated` callback in AppServiceProvider re-opens spatie `LogBatch` inside queued jobs so audit rows thread correlation across queue boundary"
    - "A feature test fires an HTTP request, queues a job, and asserts the queued job's context contains the same correlation_id (Pitfall J defensive check)"
  artifacts:
    - path: "app/Http/Middleware/AttachCorrelationId.php"
      provides: "Correlation ID generation + Context injection on every HTTP request"
      contains: "Context::add('correlation_id'"
    - path: "app/Foundation/Events/DomainEvent.php"
      provides: "Base class every module-level event extends"
      exports: ["DomainEvent"]
      min_lines: 20
    - path: "app/Foundation/Audit/Services/Auditor.php"
      provides: "Meta-audit service wrapping spatie/activitylog"
      exports: ["Auditor::record"]
    - path: "app/Foundation/Integration/Models/IntegrationEvent.php"
      provides: "Eloquent model over integration_events table"
      exports: ["IntegrationEvent"]
    - path: "app/Foundation/Integration/Services/IntegrationLogger.php"
      provides: "Redacts headers, writes to integration_events with correlation_id auto-attached"
      exports: ["IntegrationLogger::log"]
    - path: "app/Console/Commands/BaseCommand.php"
      provides: "Artisan command base class with correlation_id + LogBatch scope"
      exports: ["BaseCommand"]
    - path: "database/migrations/2026_04_18_106000_create_integration_events_table.php"
      provides: "integration_events schema (FOUND-05)"
      contains: "correlation_id"
  key_links:
    - from: "app/Http/Middleware/AttachCorrelationId.php"
      to: "Illuminate\\Support\\Facades\\Context"
      via: "Context::add()"
      pattern: "Context::add\\('correlation_id'"
    - from: "app/Foundation/Events/DomainEvent.php"
      to: "Illuminate\\Support\\Facades\\Context"
      via: "Context::get() in constructor"
      pattern: "Context::get\\('correlation_id'\\)"
    - from: "app/Foundation/Integration/Services/IntegrationLogger.php"
      to: "IntegrationEvent model"
      via: "IntegrationEvent::create"
      pattern: "IntegrationEvent::create"
    - from: "bootstrap/app.php"
      to: "AttachCorrelationId middleware"
      via: "withMiddleware append"
      pattern: "AttachCorrelationId"
    - from: "app/Providers/AppServiceProvider.php"
      to: "Context::hydrated"
      via: "boot() callback"
      pattern: "Context::hydrated"
---

<objective>
Build the Foundation layer: correlation ID middleware, `DomainEvent` base class, `Auditor` service (wraps spatie/activitylog), `IntegrationLogger` service + `integration_events` table, `BaseCommand` for artisan parity, and queue-boundary propagation via Laravel 12 `Context::dehydrating/hydrated`. This plan is the cross-cutting plumbing that every Phase 2+ module threads through.

Purpose: Satisfies FOUND-03 (event bus with correlation threading), FOUND-04 (audit log), FOUND-05 (integration events log). Without this, Plan 04 cannot log webhook receipts with a correlation_id, and Plan 05's failed-job alerts lack the `correlation_id` field (Pitfall J).

Output: Every HTTP request and artisan command carries a UUIDv4 correlation_id threaded through `Context`, spatie `LogBatch`, `audit_log`, `integration_events`, and (on the next hop) queued jobs via Laravel 12's automatic dehydrate/hydrate. Header `X-Correlation-Id` is emitted on every response.
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
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md

<interfaces>
<!-- Contracts this plan ships for Plans 04 + 05 to consume. -->

From 01-RESEARCH.md §10 (Correlation ID middleware):

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\LogBatch;
use Symfony\Component\HttpFoundation\Response;

class AttachCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-Id')
            ?? $request->header('X-Request-Id')
            ?? (string) Str::uuid();

        Context::add('correlation_id', $correlationId);

        LogBatch::startBatch();
        LogBatch::setBatch($correlationId);

        try {
            $response = $next($request);
            $response->headers->set('X-Correlation-Id', $correlationId);
            return $response;
        } finally {
            LogBatch::endBatch();
        }
    }
}
```

From 01-RESEARCH.md §10 (AppServiceProvider hydrate/dehydrate):

```php
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Spatie\Activitylog\Facades\LogBatch;

Context::dehydrating(function (Repository $context) {
    // Hook point for future defensive logging
});

Context::hydrated(function (Repository $context) {
    if ($cid = $context->get('correlation_id')) {
        LogBatch::setBatch($cid);
    }
});
```

From 01-RESEARCH.md "Pattern 1" (DomainEvent base class):

```php
namespace App\Foundation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

abstract class DomainEvent
{
    use Dispatchable, SerializesModels;

    public readonly string $correlationId;
    public readonly string $occurredAt;

    public function __construct()
    {
        $this->correlationId = Context::get('correlation_id') ?? (string) \Illuminate\Support\Str::uuid();
        $this->occurredAt = now()->toIso8601String();
    }
}
```

From 01-RESEARCH.md §11 (Auditor — meta-audit service):

```php
namespace App\Foundation\Audit\Services;

use Illuminate\Support\Facades\Context;

final class Auditor
{
    public function record(string $action, array $context = []): void
    {
        activity('system')
            ->withProperties(array_merge([
                'correlation_id' => Context::get('correlation_id'),
                'occurred_at'    => now()->toIso8601String(),
            ], $context))
            ->log($action);
    }
}
```

From 01-RESEARCH.md §6 (integration_events table + IntegrationLogger):

```php
Schema::create('integration_events', function (Blueprint $t) {
    $t->id();
    $t->string('channel', 32);
    $t->string('direction', 8)->default('outbound');
    $t->string('operation', 64);
    $t->nullableMorphs('subject');
    $t->string('correlation_id', 36)->index();
    $t->string('endpoint', 255);
    $t->string('method', 8);
    $t->json('request_body')->nullable();
    $t->json('request_headers')->nullable();
    $t->json('response_body')->nullable();
    $t->integer('http_status')->nullable();
    $t->integer('latency_ms')->nullable();
    $t->tinyInteger('attempt')->default(1);
    $t->string('status', 12);
    $t->text('error_message')->nullable();
    $t->timestamp('created_at');

    $t->index(['channel', 'created_at']);
    $t->index(['status', 'created_at']);
});
```

```php
// IntegrationLogger sensitive-header redaction list:
$sensitive = ['authorization', 'x-wc-webhook-signature', 'cookie', 'x-bitrix-signature'];
```

From 01-RESEARCH.md §10 (BaseCommand):

```php
abstract class BaseCommand extends Command
{
    public function handle(): int
    {
        $correlationId = (string) Str::uuid();
        Context::add('correlation_id', $correlationId);
        LogBatch::startBatch();
        LogBatch::setBatch($correlationId);

        try {
            return $this->execute();
        } finally {
            LogBatch::endBatch();
        }
    }

    abstract protected function execute(): int;
}
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Inbound HTTP → `AttachCorrelationId` | Inbound `X-Correlation-Id` header honoured; must NOT be truncated or reused as an auth token |
| Outbound API → `IntegrationLogger` | Sensitive headers MUST be redacted before DB persist |
| Queue boundary | `Context` payload crosses via Laravel 12 dehydrate/hydrate — no secrets should ride in context |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-03-01 | I | `integration_events.request_headers` leaking `Authorization` / HMAC secrets | mitigate | `IntegrationLogger::redactHeaders()` lower-cases header names and replaces any match in the sensitive list with `['***REDACTED***']` before `IntegrationEvent::create()` |
| T-03-02 | T | Inbound `X-Correlation-Id` used for injection attacks (SQL, log spoofing) | mitigate | Middleware validates format: regex `^[A-Za-z0-9\-]{8,64}$` — if inbound header fails, generate UUIDv4 instead. Include this guard explicitly in `AttachCorrelationId` |
| T-03-03 | I | `integration_events` grows unboundedly | mitigate | Plan 05 ships `PruneIntegrationEventsCommand` with D-05 90-day retention |
| T-03-04 | T | `audit_log` tampering | accept | spatie/activitylog is append-only from the trait API; direct UPDATE attacks require DB credentials (out of Phase 1 scope) |
| T-03-05 | I | `DomainEvent::serializeModels` leaking password columns when Eloquent model is passed to event | mitigate | Convention: domain events carry primitive fields (IDs, strings) not full models. Enforced by code review + Pitfall 13 convention (ShouldQueue default, no model refs where avoidable) |
| T-03-06 | R | Lost correlation_id on failed job (Pitfall J) | mitigate | `Context::hydrated` callback re-opens LogBatch; `ThrottledFailedJobNotifier` in Plan 05 captures `$event->job->payload()` which contains dehydrated context |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Create AttachCorrelationId middleware + DomainEvent base + Context hydrate/dehydrate in AppServiceProvider + BaseCommand + feature test for HTTP propagation</name>
  <files>app/Http/Middleware/AttachCorrelationId.php, app/Foundation/Events/DomainEvent.php, app/Console/Commands/BaseCommand.php, app/Providers/AppServiceProvider.php, bootstrap/app.php, tests/Feature/CorrelationIdPropagationTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §10 (correlation ID propagation — middleware, Context::dehydrating/hydrated, BaseCommand)
    - .planning/phases/01-foundation/01-RESEARCH.md §5 (LogBatch::setBatch threading)
    - .planning/phases/01-foundation/01-RESEARCH.md Pitfall J (correlation_id on failed jobs)
    - bootstrap/app.php (post-Plan 01 state — already has webhooks.php registration)
    - app/Providers/AppServiceProvider.php (Laravel default scaffold)
  </read_first>
  <behavior>
    - Test: HTTP GET /up without inbound header → response has X-Correlation-Id header matching UUIDv4 regex
    - Test: HTTP GET /up with X-Correlation-Id: fixed-value-abc123 → response echoes the same value
    - Test: HTTP GET /up with X-Request-Id header (no X-Correlation-Id) → response X-Correlation-Id matches the X-Request-Id value
    - Test: Malformed inbound X-Correlation-Id (containing newlines or >64 chars) → middleware regenerates UUIDv4 (T-03-02)
    - Test: Firing a DomainEvent subclass reads correlation_id from Context, not a fresh UUID
  </behavior>
  <action>
    **Step A — Write `app/Http/Middleware/AttachCorrelationId.php`** (expanded from §10 with T-03-02 validation):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Http\Middleware;

    use Closure;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Context;
    use Illuminate\Support\Str;
    use Spatie\Activitylog\Facades\LogBatch;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * Attach a correlation_id to every HTTP request + thread it through Context + spatie LogBatch.
     *
     * Honours inbound X-Correlation-Id or X-Request-Id when well-formed (8-64 chars, safe alphabet).
     * Otherwise generates a UUIDv4. Emits X-Correlation-Id on the response for downstream correlation.
     *
     * Registered in bootstrap/app.php under withMiddleware web-group append.
     */
    class AttachCorrelationId
    {
        /** Valid correlation_id format: 8-64 chars, alphanumeric + dashes only (UUIDv4-compatible, no log injection). */
        private const VALID_FORMAT = '/^[A-Za-z0-9\-]{8,64}$/';

        public function handle(Request $request, Closure $next): Response
        {
            $inbound = $request->header('X-Correlation-Id') ?? $request->header('X-Request-Id');

            $correlationId = ($inbound !== null && preg_match(self::VALID_FORMAT, $inbound))
                ? $inbound
                : (string) Str::uuid();

            // Laravel 12 Context — automatically propagates to queued jobs via dehydrate/hydrate
            Context::add('correlation_id', $correlationId);

            // Thread spatie/activitylog rows in this request scope with the same UUID
            LogBatch::startBatch();
            LogBatch::setBatch($correlationId);

            try {
                /** @var Response $response */
                $response = $next($request);
                $response->headers->set('X-Correlation-Id', $correlationId);
                return $response;
            } finally {
                LogBatch::endBatch();
            }
        }
    }
    ```

    **Step B — Register the middleware in `bootstrap/app.php`** (append to the web group so it runs after session init but before any logging). Edit the existing `withMiddleware` block:

    ```php
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // FOUND-03: Attach correlation_id at HTTP entry (web routes only — queued jobs
        // hydrate Context automatically; webhooks handled by Plan 04's separate pipeline).
        $middleware->web(append: [\App\Http\Middleware\AttachCorrelationId::class]);
        $middleware->api(append: [\App\Http\Middleware\AttachCorrelationId::class]);
    })
    ```

    Note: the `api` group is where webhooks live per Plan 01's bootstrap config, so AttachCorrelationId fires on webhook routes too. This is intentional — Plan 04's HMAC middleware runs BEFORE AttachCorrelationId within the webhook group because `validateCsrfTokens` alone doesn't read the body, but `AttachCorrelationId` also doesn't read the body so order is non-critical. However, to be safe, Plan 04 will append its HMAC middleware directly on the webhook route group via `Route::middleware([...])`, which runs BEFORE group-level `api` append middleware. **This ordering is tested by Plan 04's feature test.**

    **Step C — Write `app/Foundation/Events/DomainEvent.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Foundation\Events;

    use Illuminate\Foundation\Events\Dispatchable;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\Context;
    use Illuminate\Support\Str;

    /**
     * Every module-level domain event extends this.
     *
     * Auto-populates correlation_id from Laravel 12 Context facade — survives the HTTP→queue
     * boundary via automatic dehydrate/hydrate (no manual payload stuffing required).
     *
     * FOUND-03 compliance: every event a downstream listener sees carries a correlation_id
     * threaded through audit_log + integration_events + suggestions for the full chain.
     *
     * Subclass convention: events MUST carry primitive fields (SKUs, IDs, strings),
     * NEVER full Eloquent models (Pitfall: SerializesModels leaks hidden columns on dispatch).
     */
    abstract class DomainEvent
    {
        use Dispatchable, SerializesModels;

        public readonly string $correlationId;
        public readonly string $occurredAt;

        public function __construct()
        {
            $this->correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
            $this->occurredAt = now()->toIso8601String();
        }
    }
    ```

    **Step D — Write `app/Console/Commands/BaseCommand.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Context;
    use Illuminate\Support\Str;
    use Spatie\Activitylog\Facades\LogBatch;

    /**
     * Every artisan command that performs cross-module work extends this.
     *
     * Ensures correlation_id threads through the command's entire execution path
     * (audit_log, integration_events, dispatched jobs) — same seam HTTP requests
     * get via AttachCorrelationId middleware.
     *
     * Subclasses implement execute() instead of handle().
     */
    abstract class BaseCommand extends Command
    {
        final public function handle(): int
        {
            $correlationId = (string) Str::uuid();
            Context::add('correlation_id', $correlationId);

            LogBatch::startBatch();
            LogBatch::setBatch($correlationId);

            try {
                return $this->execute();
            } finally {
                LogBatch::endBatch();
            }
        }

        /** Command body — implement per-command logic here, not in handle(). */
        abstract protected function execute(): int;
    }
    ```

    **Step E — Update `app/Providers/AppServiceProvider.php`** to register Context hydrate/dehydrate callbacks (Pitfall J mitigation):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Providers;

    use Illuminate\Log\Context\Repository;
    use Illuminate\Support\Facades\Context;
    use Illuminate\Support\ServiceProvider;
    use Spatie\Activitylog\Facades\LogBatch;

    class AppServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            //
        }

        public function boot(): void
        {
            // FOUND-03 + Pitfall J: re-open spatie LogBatch inside queued jobs so
            // audit rows written by the job share the originating request's correlation_id.
            Context::hydrated(function (Repository $context): void {
                if ($cid = $context->get('correlation_id')) {
                    LogBatch::setBatch($cid);
                }
            });

            // Hook point for future defensive logging (no-op in Phase 1)
            Context::dehydrating(function (Repository $context): void {
                // e.g. Log::debug('Context dehydrating', ['correlation_id' => $context->get('correlation_id')]);
            });
        }
    }
    ```

    **Step F — Write `tests/Feature/CorrelationIdPropagationTest.php`:**

    ```php
    <?php

    use Illuminate\Support\Facades\Context;

    it('generates a UUIDv4 correlation_id when no inbound header is present', function () {
        $response = $this->get('/up');

        $response->assertOk();
        $cid = $response->headers->get('X-Correlation-Id');

        expect($cid)->not->toBeNull()
            ->and($cid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    it('honours a well-formed inbound X-Correlation-Id header', function () {
        $fixed = 'fixed-value-abc123-xyz';

        $response = $this->withHeaders(['X-Correlation-Id' => $fixed])->get('/up');

        expect($response->headers->get('X-Correlation-Id'))->toBe($fixed);
    });

    it('falls back to X-Request-Id when X-Correlation-Id absent', function () {
        $fixed = 'req-id-7890';

        $response = $this->withHeaders(['X-Request-Id' => $fixed])->get('/up');

        expect($response->headers->get('X-Correlation-Id'))->toBe($fixed);
    });

    it('rejects malformed inbound correlation_id and regenerates (T-03-02)', function () {
        $malformed = "abc\ninjection\nattempt"; // contains newlines — fails regex

        $response = $this->withHeaders(['X-Correlation-Id' => $malformed])->get('/up');

        expect($response->headers->get('X-Correlation-Id'))
            ->not->toBe($malformed)
            ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}/i'); // UUIDv4
    });

    it('DomainEvent subclass reads correlation_id from Context, not a fresh UUID', function () {
        Context::add('correlation_id', 'test-cid-from-context');

        $event = new class extends \App\Foundation\Events\DomainEvent {};

        expect($event->correlationId)->toBe('test-cid-from-context');
    });

    it('DomainEvent generates UUIDv4 when Context has no correlation_id', function () {
        Context::forget('correlation_id');

        $event = new class extends \App\Foundation\Events\DomainEvent {};

        expect($event->correlationId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}/i');
    });
    ```

    Run: `vendor/bin/pest --filter=CorrelationIdPropagation` — all 6 tests pass.
  </action>
  <verify>
    <automated>test -f app/Http/Middleware/AttachCorrelationId.php &amp;&amp; test -f app/Foundation/Events/DomainEvent.php &amp;&amp; test -f app/Console/Commands/BaseCommand.php &amp;&amp; grep -q "AttachCorrelationId" bootstrap/app.php &amp;&amp; grep -q "Context::hydrated" app/Providers/AppServiceProvider.php &amp;&amp; grep -q "LogBatch::setBatch" app/Providers/AppServiceProvider.php &amp;&amp; vendor/bin/pest --filter=CorrelationIdPropagation</automated>
  </verify>
  <done>
    Middleware registered on web + api groups; `X-Correlation-Id` emitted on every response; inbound headers honoured if well-formed; malformed inputs regenerate; DomainEvent base class reads from Context; AppServiceProvider wires Context::hydrated → LogBatch::setBatch; CorrelationIdPropagationTest passes all 6 cases.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Create integration_events migration + IntegrationEvent model + IntegrationLogger service (with header redaction) + Auditor service + feature tests</name>
  <files>database/migrations/2026_04_18_106000_create_integration_events_table.php, app/Foundation/Integration/Models/IntegrationEvent.php, app/Foundation/Integration/Services/IntegrationLogger.php, app/Foundation/Audit/Services/Auditor.php, tests/Feature/AuditorTest.php, tests/Feature/IntegrationLoggerTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §6 (integration_events schema + IntegrationLogger full implementation + redactHeaders logic)
    - .planning/phases/01-foundation/01-RESEARCH.md §11 (Auditor service wrapping activity('system')->log)
    - .planning/phases/01-foundation/01-CONTEXT.md D-09 (prune commands log to audit_log via Auditor)
    - .planning/research/PITFALLS.md Pitfall 10 (N+1 on audit-heavy tables — integration_events indexes matter)
    - database/migrations/ (list existing migration files — ensure 2026_04_18_106000 timestamp is greater than all existing + spatie/activitylog follow-ups from Plan 01)
  </read_first>
  <behavior>
    - Test: `Schema::hasTable('integration_events')` returns true after migration; indexes on `correlation_id`, `(channel, created_at)`, `(status, created_at)` exist
    - Test: `IntegrationLogger::log()` auto-attaches correlation_id from Context when not explicitly passed
    - Test: Headers `authorization`, `x-wc-webhook-signature`, `cookie`, `x-bitrix-signature` (any case) get redacted to `['***REDACTED***']`
    - Test: Non-sensitive headers (e.g. `content-type`, `user-agent`) are preserved as-is
    - Test: `Auditor::record('test.action', ['foo' => 'bar'])` produces one `activity_log` row with `log_name='system'`, `description='test.action'`, properties containing correlation_id + foo=bar
  </behavior>
  <action>
    **Step A — Write migration `database/migrations/2026_04_18_106000_create_integration_events_table.php`:**

    Check that the filename timestamp (`2026_04_18_106000`) is AFTER existing Plan 01 migrations. If spatie/permission published as `2026_04_18_101000` and activitylog as `2026_04_18_102000`+, this `106000` is safe. If any existing file has a higher timestamp, bump to `109000` to keep ordering strict.

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
            Schema::create('integration_events', function (Blueprint $t) {
                $t->id();
                $t->string('channel', 32);                         // 'woo' | 'bitrix' | 'supplier' | 'merchant_center' | 'suggestions'
                $t->string('direction', 8)->default('outbound');   // 'outbound' | 'inbound'
                $t->string('operation', 64);                        // 'product.update' | 'deal.create' | 'apply:test'
                $t->nullableMorphs('subject');                      // optional link to domain model
                $t->string('correlation_id', 36)->index();
                $t->string('endpoint', 255);
                $t->string('method', 8);                            // GET | POST | PUT | DELETE | PATCH | APPLY (internal)
                $t->json('request_body')->nullable();
                $t->json('request_headers')->nullable();            // secrets REDACTED before write (Pitfall T-03-01)
                $t->json('response_body')->nullable();
                $t->integer('http_status')->nullable();
                $t->integer('latency_ms')->nullable();
                $t->tinyInteger('attempt')->default(1)->unsigned();
                $t->string('status', 12);                           // 'success' | 'failed' | 'retrying'
                $t->text('error_message')->nullable();
                $t->timestamp('created_at');
                // No updated_at — append-only

                $t->index(['channel', 'created_at']);
                $t->index(['status', 'created_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('integration_events');
        }
    };
    ```

    **Step B — Write `app/Foundation/Integration/Models/IntegrationEvent.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Foundation\Integration\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\MorphTo;

    /**
     * Append-only record of every outbound/inbound integration call.
     *
     * Written exclusively by IntegrationLogger::log() — never via model ::create()
     * directly (the logger handles redaction + correlation_id auto-attach).
     *
     * Pruned by Plan 05 PruneIntegrationEventsCommand on a 90-day window (D-05).
     */
    class IntegrationEvent extends Model
    {
        public $timestamps = false; // append-only; created_at set explicitly

        protected $fillable = [
            'channel', 'direction', 'operation',
            'subject_type', 'subject_id',
            'correlation_id',
            'endpoint', 'method',
            'request_body', 'request_headers', 'response_body',
            'http_status', 'latency_ms', 'attempt', 'status', 'error_message',
            'created_at',
        ];

        protected $casts = [
            'request_body'    => 'array',
            'request_headers' => 'array',
            'response_body'   => 'array',
            'http_status'     => 'integer',
            'latency_ms'      => 'integer',
            'attempt'         => 'integer',
            'created_at'      => 'datetime',
        ];

        public function subject(): MorphTo
        {
            return $this->morphTo();
        }
    }
    ```

    **Step C — Write `app/Foundation/Integration/Services/IntegrationLogger.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Foundation\Integration\Services;

    use App\Foundation\Integration\Models\IntegrationEvent;
    use Illuminate\Support\Facades\Context;

    /**
     * Writes rows to integration_events.
     *
     * Every outbound API client (WooClient, BitrixClient, SupplierClient, etc.)
     * MUST call IntegrationLogger::log() on success AND failure. The logger:
     *   1. Auto-attaches correlation_id from Context if not explicitly passed
     *   2. Redacts sensitive headers (authorization, x-wc-webhook-signature, cookie, x-bitrix-signature)
     *   3. Sets created_at to now() if absent
     *   4. Returns the persisted IntegrationEvent for callers that need the ID
     *
     * FOUND-05 compliance: every external call is logged here.
     */
    final class IntegrationLogger
    {
        /** Header names (lower-cased) whose values get replaced with ['***REDACTED***']. */
        private const SENSITIVE_HEADERS = [
            'authorization',
            'x-wc-webhook-signature',
            'cookie',
            'x-bitrix-signature',
            'x-api-key',
            'x-auth-token',
        ];

        public function log(array $data): IntegrationEvent
        {
            $defaults = [
                'correlation_id' => Context::get('correlation_id'),
                'direction'      => 'outbound',
                'attempt'        => 1,
                'created_at'     => now(),
            ];

            if (isset($data['request_headers']) && is_array($data['request_headers'])) {
                $data['request_headers'] = $this->redactHeaders($data['request_headers']);
            }

            return IntegrationEvent::create(array_merge($defaults, $data));
        }

        /** Lower-cases every header name and masks values on the sensitive list. */
        private function redactHeaders(array $headers): array
        {
            $redacted = [];
            foreach ($headers as $name => $value) {
                $lower = strtolower((string) $name);
                $redacted[$name] = in_array($lower, self::SENSITIVE_HEADERS, true)
                    ? ['***REDACTED***']
                    : $value;
            }
            return $redacted;
        }
    }
    ```

    **Step D — Write `app/Foundation/Audit/Services/Auditor.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Foundation\Audit\Services;

    use Illuminate\Support\Facades\Context;

    /**
     * Meta-audit helper. Wraps spatie/activitylog for system-level events that
     * don't attach to a specific Eloquent model — prune counts, role-sync outcomes,
     * scheduled-command triggers, shadow-mode diff outcomes, etc.
     *
     * Model-level changes (PricingRule updated, Product created) use the LogsActivity
     * trait on the model directly — NOT this service.
     *
     * D-09 compliance: retention prune commands call Auditor::record() so the prune
     * action itself is auditable. FOUND-04 compliance: correlation_id threads through.
     */
    final class Auditor
    {
        public function record(string $action, array $context = []): void
        {
            activity('system')
                ->withProperties(array_merge([
                    'correlation_id' => Context::get('correlation_id'),
                    'occurred_at'    => now()->toIso8601String(),
                ], $context))
                ->log($action);
        }
    }
    ```

    **Step E — Run migrations to create `integration_events` table:**

    ```bash
    php artisan migrate
    ```

    Confirm table + indexes exist:

    ```bash
    php artisan db:table integration_events
    ```

    **Step F — Write `tests/Feature/IntegrationLoggerTest.php`:**

    ```php
    <?php

    use App\Foundation\Integration\Models\IntegrationEvent;
    use App\Foundation\Integration\Services\IntegrationLogger;
    use Illuminate\Support\Facades\Context;

    it('persists a row with all expected columns', function () {
        Context::add('correlation_id', 'test-cid-1');
        $logger = app(IntegrationLogger::class);

        $event = $logger->log([
            'channel'       => 'woo',
            'operation'     => 'product.update',
            'endpoint'      => 'products/1234',
            'method'        => 'PUT',
            'request_body'  => ['regular_price' => '99.99'],
            'response_body' => ['id' => 1234, 'price' => '99.99'],
            'http_status'   => 200,
            'latency_ms'    => 145,
            'status'        => 'success',
        ]);

        expect($event)->toBeInstanceOf(IntegrationEvent::class);
        expect(IntegrationEvent::count())->toBe(1);
        expect($event->correlation_id)->toBe('test-cid-1');
        expect($event->channel)->toBe('woo');
        expect($event->direction)->toBe('outbound'); // default
        expect($event->attempt)->toBe(1);             // default
    });

    it('redacts sensitive request_headers (case-insensitive)', function () {
        $logger = app(IntegrationLogger::class);

        $event = $logger->log([
            'channel'       => 'woo',
            'operation'     => 'test',
            'endpoint'      => '/test',
            'method'        => 'POST',
            'status'        => 'success',
            'request_headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer super-secret-token',
                'X-WC-Webhook-Signature' => 'abc123==',
                'cookie' => 'session=xyz',
                'User-Agent' => 'test',
            ],
        ]);

        $headers = $event->request_headers;

        expect($headers['Content-Type'])->toBe('application/json');
        expect($headers['User-Agent'])->toBe('test');
        expect($headers['Authorization'])->toBe(['***REDACTED***']);
        expect($headers['X-WC-Webhook-Signature'])->toBe(['***REDACTED***']);
        expect($headers['cookie'])->toBe(['***REDACTED***']);
    });

    it('auto-attaches correlation_id from Context when not explicitly passed', function () {
        Context::add('correlation_id', 'auto-cid-xyz');

        $event = app(IntegrationLogger::class)->log([
            'channel'   => 'bitrix',
            'operation' => 'deal.add',
            'endpoint'  => 'crm.deal.add',
            'method'    => 'POST',
            'status'    => 'failed',
        ]);

        expect($event->correlation_id)->toBe('auto-cid-xyz');
    });

    it('has indexes on correlation_id, (channel, created_at), (status, created_at)', function () {
        $columns = \Schema::getColumnListing('integration_events');
        expect($columns)->toContain('correlation_id', 'channel', 'status', 'created_at');

        // Index existence check (MySQL-specific — adjust for other drivers if needed)
        $indexes = collect(\DB::select('SHOW INDEX FROM integration_events'))->pluck('Key_name')->unique();
        expect($indexes)->toContain('integration_events_correlation_id_index');
    });
    ```

    **Step G — Write `tests/Feature/AuditorTest.php`:**

    ```php
    <?php

    use App\Foundation\Audit\Services\Auditor;
    use Illuminate\Support\Facades\Context;
    use Spatie\Activitylog\Models\Activity;

    it('records a system-level activity with correlation_id in properties', function () {
        Context::add('correlation_id', 'audit-cid-1');

        app(Auditor::class)->record('test.action', ['foo' => 'bar']);

        $activity = Activity::where('log_name', 'system')
            ->where('description', 'test.action')
            ->latest()
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->properties['correlation_id'])->toBe('audit-cid-1');
        expect($activity->properties['foo'])->toBe('bar');
        expect($activity->properties)->toHaveKey('occurred_at');
    });

    it('falls back gracefully when Context has no correlation_id', function () {
        Context::forget('correlation_id');

        app(Auditor::class)->record('orphan.action', []);

        $activity = Activity::where('description', 'orphan.action')->latest()->first();
        expect($activity)->not->toBeNull();
        // correlation_id key present but value is null
        expect($activity->properties)->toHaveKey('correlation_id');
        expect($activity->properties['correlation_id'])->toBeNull();
    });
    ```

    Run: `vendor/bin/pest --filter=Auditor && vendor/bin/pest --filter=IntegrationLogger` — all tests pass.
  </action>
  <verify>
    <automated>test -f database/migrations/2026_04_18_106000_create_integration_events_table.php &amp;&amp; test -f app/Foundation/Integration/Models/IntegrationEvent.php &amp;&amp; test -f app/Foundation/Integration/Services/IntegrationLogger.php &amp;&amp; test -f app/Foundation/Audit/Services/Auditor.php &amp;&amp; php artisan migrate --force &amp;&amp; php artisan db:table integration_events &amp;&amp; vendor/bin/pest --filter=IntegrationLogger &amp;&amp; vendor/bin/pest --filter=Auditor</automated>
  </verify>
  <done>
    `integration_events` table migrated with all columns and 3 indexes; IntegrationEvent model casts request_body/response_body/request_headers as array; IntegrationLogger redacts 6 sensitive headers case-insensitively and auto-attaches Context correlation_id; Auditor records to `log_name='system'` with correlation_id + occurred_at in properties; IntegrationLoggerTest (4 tests) + AuditorTest (2 tests) all pass.
  </done>
</task>

</tasks>

<threat_model>
(See plan-level threat model above — comprehensive.)
</threat_model>

<verification>
- `php artisan migrate` exits 0; `integration_events` table exists with correlation_id index
- `vendor/bin/pest --filter=CorrelationIdPropagation` passes (6 tests)
- `vendor/bin/pest --filter=IntegrationLogger` passes (4 tests)
- `vendor/bin/pest --filter=Auditor` passes (2 tests)
- `vendor/bin/deptrac analyse --no-progress` exits 0 — this plan only writes to `app/Foundation/` + `app/Http/Middleware/` + `app/Console/Commands/` + `app/Providers/` (no cross-domain imports)
- `curl -I http://localhost:8000/up` returns `X-Correlation-Id: <uuid>` header
- `curl -I -H "X-Correlation-Id: test-abc-123" http://localhost:8000/up` returns `X-Correlation-Id: test-abc-123` header (honours inbound)
- `php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(\\App\\Foundation\\Audit\\Services\\Auditor::class); echo 'ok';"` prints `ok` (DI resolution works)
</verification>

<success_criteria>
- **FOUND-03:** `AttachCorrelationId` middleware + `DomainEvent` base class + `Context::hydrated` → `LogBatch::setBatch` propagation establishes end-to-end correlation_id threading from HTTP entry → spatie audit rows → queued jobs. Pitfall J (correlation_id on failed jobs) mitigated via `Context::hydrated` callback.
- **FOUND-04:** `Auditor::record()` writes to spatie/activitylog with correlation_id in properties; `activity_log` table exists (migrated in Plan 01 via `vendor:publish`); retention prune added in Plan 05.
- **FOUND-05:** `integration_events` table exists with correct schema + 3 indexes; `IntegrationLogger::log()` redacts sensitive headers (T-03-01 mitigated) and auto-attaches correlation_id.
- Foundation layer is consumed (not modified) by Plans 04 + 05.
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation/01-03-SUMMARY.md` documenting: the exact middleware registration point in `bootstrap/app.php`, the 6 sensitive-header names configured, and confirmation that `php artisan queue:work --once` preserves correlation_id across the HTTP→queue boundary (spot test — dispatch a test job from a request, observe Context in the handler).
</output>
