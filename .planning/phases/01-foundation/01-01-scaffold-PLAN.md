---
phase: 01-foundation
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - composer.lock
  - package.json
  - .env.example
  - .env
  - bootstrap/app.php
  - config/database.php
  - config/queue.php
  - config/cache.php
  - config/session.php
  - config/logging.php
  - routes/webhooks.php
  - depfile.yaml
  - phpstan.neon
  - pint.json
  - phpunit.xml
  - tests/Pest.php
  - app/Domain/Products/.gitkeep
  - app/Domain/Pricing/.gitkeep
  - app/Domain/Competitor/.gitkeep
  - app/Domain/Sync/.gitkeep
  - app/Domain/Webhooks/.gitkeep
  - app/Domain/CRM/.gitkeep
  - app/Domain/Suggestions/.gitkeep
  - app/Domain/Alerting/.gitkeep
  - app/Domain/Feeds/Contracts/FeedGenerator.php
  - app/Foundation/Audit/.gitkeep
  - app/Foundation/Integration/.gitkeep
  - app/Foundation/Events/.gitkeep
  - app/Http/Middleware/.gitkeep
autonomous: true
requirements:
  - FOUND-02
  - FOUND-13
user_setup:
  - service: vps
    why: "Production host for Laravel 12 + Redis + Supervisor + Cron"
    env_vars:
      - name: DB_DATABASE
        source: "MySQL on VPS — create database `meetingstore_ops` before running migrations"
      - name: DB_USERNAME
        source: "MySQL user with CREATE/ALTER on `meetingstore_ops`"
      - name: DB_PASSWORD
        source: "Matching password for DB_USERNAME"
      - name: WC_WEBHOOK_SECRET
        source: "WooCommerce admin → Settings → Advanced → Webhooks (set later, wave 3 activates the middleware)"
    dashboard_config:
      - task: "Install PHP extensions: ext-redis (phpredis via PECL), ext-bcmath, ext-intl"
        location: "VPS shell — see 01-RESEARCH.md Environment Availability table"
      - task: "Install Redis 7.x with appendonly yes + appendfsync everysec + maxmemory-policy noeviction"
        location: "/etc/redis/redis.conf — see 01-RESEARCH.md §4 Redis persistence"
      - task: "Install Supervisor for Horizon auto-restart"
        location: "/etc/supervisor/conf.d/horizon.conf — Plan 05 ships template"
  - service: dev-shell
    why: "Plans use Unix-shell idioms (mkdir -p, touch, rm -rf, heredoc); native Windows cmd.exe / PowerShell will fail on these"
    env_vars: []
    dashboard_config:
      - task: "Windows users: execute every plan command in git-bash (Git for Windows ships it) or WSL2 — NOT cmd.exe or PowerShell. The bootstrap, mkdir/touch, deptrac violator setup, and Pest test commands all assume bash."
        location: "Local dev machine — install Git for Windows from https://git-scm.com/download/win, then use 'Git Bash' from the Start menu"

must_haves:
  truths:
    - "`laravel new meetingstore-ops` skeleton exists and serves `/` (200) via `php artisan serve`"
    - "All Phase 1 composer packages installed at the exact versions in 01-RESEARCH.md §12 and STACK.md"
    - "`app/Domain/<Module>/` directories exist for all 9 modules (Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds)"
    - "`app/Foundation/{Audit,Integration,Events}/` directories exist"
    - "`depfile.yaml` at repo root defines all 9 Domain modules + Foundation + Http layers"
    - "`vendor/bin/deptrac analyse` exits 0 on the empty scaffold"
    - "A deliberate cross-domain import (e.g., `use App\\Domain\\CRM\\...` inside `app/Domain/Products/`) makes `vendor/bin/deptrac analyse` exit non-zero"
    - "`.env.example` contains `WOO_WRITE_ENABLED=false` and `WC_WEBHOOK_SECRET=` placeholder keys"
    - "`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis` set in `.env.example`"
    - "`config/database.php` redis block has 4 separate DBs (default=0, cache=1, horizon=2, pulse=3)"
    - "`app/Domain/Feeds/Contracts/FeedGenerator.php` interface stub exists with `channel()`, `generate()`, `lastGeneratedAt()` methods (FOUND-13)"
    - "`routes/webhooks.php` exists (empty placeholder group) — Wave 3 populates"
    - "`bootstrap/app.php` registers `routes/webhooks.php` under `api` middleware group with CSRF exclusion for `webhooks/*`"
  artifacts:
    - path: "composer.json"
      provides: "All Phase 1 dependencies at pinned versions"
      contains: "filament/filament, laravel/horizon, laravel/pulse, spatie/laravel-permission, bezhansalleh/filament-shield, spatie/laravel-activitylog, rmsramos/activitylog, spatie/laravel-failed-job-monitor, sentry/sentry-laravel, qossmic/deptrac-shim, nunomaduro/larastan, pestphp/pest, laravel/pint"
    - path: "depfile.yaml"
      provides: "Deptrac module-boundary ruleset"
      contains: "layers: Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds, Foundation, Http"
    - path: ".env.example"
      provides: "All Phase 1 env keys documented"
      contains: "WOO_WRITE_ENABLED=false"
    - path: "app/Domain/Feeds/Contracts/FeedGenerator.php"
      provides: "Phase 8 feed-generator contract stub (FOUND-13)"
      exports: ["FeedGenerator"]
    - path: "routes/webhooks.php"
      provides: "Empty webhook route file registered in bootstrap/app.php"
    - path: "bootstrap/app.php"
      provides: "Webhook route group registration + CSRF exclusion"
      pattern: "webhooks\\.php"
  key_links:
    - from: "bootstrap/app.php"
      to: "routes/webhooks.php"
      via: "withRouting.then"
      pattern: "base_path\\('routes/webhooks.php'\\)"
    - from: "depfile.yaml"
      to: "app/Domain/*"
      via: "directory collectors"
      pattern: "app/Domain/[A-Za-z]+/\\.\\*"
---

<objective>
Bootstrap the greenfield Laravel 12 project, install every Phase 1 dependency at pinned versions from 01-RESEARCH.md §12, create the `app/Domain/<Module>/` + `app/Foundation/` skeleton, wire the Deptrac module-boundary ruleset, ship the `FeedGenerator` contract stub (FOUND-13), and register the webhook route file + CSRF exclusion so later plans can plug into it.

Purpose: Every downstream plan depends on the package manifest, folder layout, and Deptrac config existing. Getting the installation order wrong here (e.g., Shield before spatie/permission) cascades into hours of rework. This plan is the "foundation of the foundation."

Output: A running Laravel 12 skeleton on the dev machine with all packages installed, module folders created, Deptrac passing on the empty scaffold, `.env.example` fully populated, and no business logic yet.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/01-foundation/01-CONTEXT.md
@.planning/phases/01-foundation/01-RESEARCH.md
@.planning/research/STACK.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md

<interfaces>
<!-- This plan creates the skeleton; no downstream interfaces yet. -->
<!-- Contracts shipped: FeedGenerator (FOUND-13) — canonical signature below. -->

From 01-RESEARCH.md §7 (FeedGenerator contract stub):

```php
namespace App\Domain\Feeds\Contracts;

interface FeedGenerator
{
    public function channel(): string;
    public function generate(): string;    // returns generated feed path or URL
    public function lastGeneratedAt(): ?\DateTimeImmutable;
}
```

From 01-RESEARCH.md §12 (project bootstrap sequence):

```bash
# Exact order — do not re-order
composer create-project laravel/laravel:^12.0 meetingstore-ops .
composer require filament/filament:"^3.3"
php artisan filament:install --panels
composer require laravel/horizon:"^5.45"
php artisan horizon:install
composer require laravel/pulse:"^1.4"
php artisan pulse:install
composer require spatie/laravel-permission:"^7.2"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
composer require bezhansalleh/filament-shield:"^3.3"
# NB: shield:install runs in Plan 02, NOT here — requires migrations run first
composer require spatie/laravel-activitylog:"^4.12"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-config"
composer require rmsramos/activitylog:"^1.0"
composer require spatie/laravel-failed-job-monitor:"^4.0"
php artisan vendor:publish --provider="Spatie\FailedJobMonitor\FailedJobMonitorServiceProvider"
composer require sentry/sentry-laravel:"^4.0"
composer require --dev pestphp/pest:"^3.0" pestphp/pest-plugin-laravel:"^3.0"
composer require --dev nunomaduro/larastan:"^3.0"
composer require --dev laravel/pint:"^1.24"
composer require --dev qossmic/deptrac-shim
```

From 01-RESEARCH.md §2 (Deptrac depfile.yaml):

```yaml
parameters:
  paths: [./app]
  exclude_files: ['#.*test.*#']
  layers:
    - name: Products
      collectors: [{ type: directory, regex: app/Domain/Products/.* }]
    - name: Pricing
      collectors: [{ type: directory, regex: app/Domain/Pricing/.* }]
    - name: Competitor
      collectors: [{ type: directory, regex: app/Domain/Competitor/.* }]
    - name: Sync
      collectors: [{ type: directory, regex: app/Domain/Sync/.* }]
    - name: Webhooks
      collectors: [{ type: directory, regex: app/Domain/Webhooks/.* }]
    - name: CRM
      collectors: [{ type: directory, regex: app/Domain/CRM/.* }]
    - name: Suggestions
      collectors: [{ type: directory, regex: app/Domain/Suggestions/.* }]
    - name: Alerting
      collectors: [{ type: directory, regex: app/Domain/Alerting/.* }]
    - name: Feeds
      collectors: [{ type: directory, regex: app/Domain/Feeds/.* }]
    - name: Foundation
      collectors: [{ type: directory, regex: app/Foundation/.* }]
    - name: Http
      collectors: [{ type: directory, regex: app/Http/.* }]
  ruleset:
    Products:     [Foundation]
    Pricing:      [Foundation]
    Competitor:   [Foundation]
    Sync:         [Foundation]
    Webhooks:     [Foundation]
    CRM:          [Foundation]
    Suggestions:  [Foundation]
    Alerting:     [Foundation]
    Feeds:        [Foundation]
    Foundation:   []
    Http:         [Foundation, Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds]
```

From 01-RESEARCH.md §3 (bootstrap/app.php webhook group):

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->create();
```

From 01-RESEARCH.md §4 (config/database.php redis block):

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 0],
    'cache'   => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 1],
    'horizon' => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 2],
    'pulse'   => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 3],
],
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Dev machine → Git repo | `.env` MUST NOT be committed; `.env.example` is the only env-template artifact in git |
| Composer registry → project | Pinned versions (`^3.3`, `^7.2`, etc.) prevent transitive upgrade drift |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-01-01 | I | `.env` file containing real secrets | mitigate | `.gitignore` must include `.env` (Laravel default); verify after `composer create-project`. Only `.env.example` is committed. |
| T-01-02 | T | `composer.json` version pins | mitigate | Use `^3.3`-style caret pins (minor+patch float, major locked). Commit `composer.lock`. Never use `*` or `dev-main`. |
| T-01-03 | S | Webhook route file existing without HMAC middleware yet | accept | Plan 04 installs HMAC middleware before any real webhook routes are added. This plan ships an empty `routes/webhooks.php` with no routes — no attack surface yet. |
| T-01-04 | E | Default Laravel installation leaving Breeze or debug routes exposed | mitigate | Do NOT install `laravel/breeze` (Pitfall D). Filament 3 ships its own auth in Plan 02. Verify `app/Http/Controllers/Auth` does NOT exist post-install. |
</threat_model>

<tasks>

<task type="auto">
  <name>Task 1: Bootstrap Laravel 12, install all Phase 1 composer packages, configure .env.example with Redis + queue + WOO_WRITE_ENABLED</name>
  <files>composer.json, composer.lock, package.json, .env.example, .env, config/database.php, config/queue.php, config/cache.php, config/session.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §12 (Project bootstrap sequence — exact install order)
    - .planning/phases/01-foundation/01-RESEARCH.md §4 (Redis persistence config + 4 logical DBs)
    - .planning/phases/01-foundation/01-RESEARCH.md §8 (WOO_WRITE_ENABLED default + services.woo config block)
    - .planning/research/STACK.md (pinned versions, "What NOT to Use" table — no Breeze, no Tailwind 4, no Telescope in prod)
    - .planning/research/PITFALLS.md Pitfall D (Tailwind 4 trap), Pitfall E (QUEUE_CONNECTION must be redis), Pitfall F (appendfsync everysec), Pitfall G (maxmemory-policy noeviction)
    - .planning/phases/01-foundation/01-CONTEXT.md D-08 (WOO_WRITE_ENABLED default false, non-negotiable)
  </read_first>
  <action>
    Run the bootstrap sequence from 01-RESEARCH.md §12 verbatim in the working directory `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops`. The directory is empty except for `.planning/`.

    **Installation order (non-negotiable — Pitfall I resolved by following this):**

    ```bash
    # A. Laravel skeleton — install INTO the existing directory (don't overwrite .planning/)
    composer create-project laravel/laravel:^12.0 . --prefer-dist
    # If composer complains about non-empty dir, use: composer create-project laravel/laravel:^12.0 tmp-install && cp -r tmp-install/. . && rm -rf tmp-install

    # B. Core panels (Filament MUST come before Shield per §1 install-order note)
    composer require filament/filament:"^3.3"
    php artisan filament:install --panels

    # C. Queue + monitoring
    composer require laravel/horizon:"^5.45"
    php artisan horizon:install
    composer require laravel/pulse:"^1.4"
    php artisan pulse:install

    # D. RBAC (spatie first, Shield second — do NOT run shield:install yet; Plan 02 does that)
    composer require spatie/laravel-permission:"^7.2"
    php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
    composer require bezhansalleh/filament-shield:"^3.3"

    # E. Audit stack
    composer require spatie/laravel-activitylog:"^4.12"
    php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
    php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-config"
    composer require rmsramos/activitylog:"^1.0"

    # F. Alerting + Sentry
    composer require spatie/laravel-failed-job-monitor:"^4.0"
    php artisan vendor:publish --provider="Spatie\FailedJobMonitor\FailedJobMonitorServiceProvider"
    composer require sentry/sentry-laravel:"^4.0"

    # G. Dev tooling
    composer require --dev pestphp/pest:"^3.0" pestphp/pest-plugin-laravel:"^3.0"
    composer require --dev nunomaduro/larastan:"^3.0"
    composer require --dev laravel/pint:"^1.24"
    composer require --dev qossmic/deptrac-shim
    ```

    **Explicitly DO NOT install:**
    - `laravel/breeze` (Pitfall D — Filament 3 ships its own auth, adding Breeze produces two login routes)
    - `laravel/telescope` (STACK.md "What NOT to Use" — never in prod; may add `--dev` later, not in Phase 1 scope)
    - `tailwindcss@^4` — verify `package.json` shows `"tailwindcss": "^3.4"` after `filament:install --panels` (Pitfall D)

    **Configure `.env.example` (write this full content, no partial edit):**

    ```
    APP_NAME=MeetingStoreOps
    APP_ENV=production
    APP_KEY=
    APP_DEBUG=false
    APP_URL=https://ops.meetingstore.co.uk

    LOG_CHANNEL=stack
    LOG_LEVEL=info

    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=meetingstore_ops
    DB_USERNAME=
    DB_PASSWORD=

    REDIS_CLIENT=phpredis
    REDIS_HOST=127.0.0.1
    REDIS_PASSWORD=null
    REDIS_PORT=6379

    QUEUE_CONNECTION=redis
    CACHE_STORE=redis
    SESSION_DRIVER=database
    SESSION_LIFETIME=120

    HORIZON_PREFIX=ops-horizon
    HORIZON_DOMAIN=ops.meetingstore.co.uk

    MAIL_MAILER=smtp
    MAIL_HOST=
    MAIL_PORT=587
    MAIL_USERNAME=
    MAIL_PASSWORD=
    MAIL_ENCRYPTION=tls
    MAIL_FROM_ADDRESS=no-reply@ops.meetingstore.co.uk
    MAIL_FROM_NAME="MeetingStore Ops"

    # Phase 1 — Woo write gate (MUST default to false; do NOT flip to true until Phase 7 cutover)
    # Valid values: 'false' (shadow mode, default) | 'true' (real Woo writes, post-Phase-7 only)
    WOO_WRITE_ENABLED=false

    # Phase 1 — Webhook HMAC secret (populated by ops at deploy; see Plan 04)
    # Format: 32+ char random alphanumeric string — generate with `openssl rand -base64 32`
    # Must EXACTLY match the secret entered in WooCommerce > Settings > Advanced > Webhooks > Secret
    WC_WEBHOOK_SECRET=

    # Phase 2 adds:
    # WOO_URL=                      # Format: https://<store>.com (no trailing slash; consumer key auth)
    # WOO_CONSUMER_KEY=             # Format: ck_<64-hex>  — Woo > Settings > Advanced > REST API
    # WOO_CONSUMER_SECRET=          # Format: cs_<64-hex>
    # SUPPLIER_API_URL=             # Format: https://api.21stcav.com (central supplier endpoint, no trailing slash)
    # SUPPLIER_API_USER=
    # SUPPLIER_API_PASS=

    # Phase 4 adds:
    # BITRIX_INBOUND_WEBHOOK=       # Format: https://<portal>.bitrix24.com/rest/<user_id>/<token>/  (TRAILING SLASH REQUIRED)

    SENTRY_LARAVEL_DSN=             # Format: https://<key>@<host>.ingest.sentry.io/<project_id>
    SENTRY_TRACES_SAMPLE_RATE=0.1   # Float 0.0-1.0 — sample rate for performance traces
    ```

    **Copy `.env.example` → `.env` and run `php artisan key:generate`** so the local dev env is functional.

    **Update `config/database.php` `redis` block** to match 01-RESEARCH.md §4 (4 separate logical DBs — default/cache/horizon/pulse). Replace the default `redis` block:

    ```php
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
        'horizon' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_HORIZON_DB', '2'),
        ],
        'pulse' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_PULSE_DB', '3'),
        ],
    ],
    ```

    **Create the database and run migrations:**

    ```bash
    # User creates DB manually per user_setup; then:
    php artisan migrate
    ```

    **Verify post-install:**
    - `php artisan --version` shows `Laravel Framework 12.*`
    - `composer show filament/filament` shows `3.3.*`
    - `composer show laravel/horizon` shows `5.45.*` or newer within `^5.45`
    - `cat package.json | grep tailwindcss` shows `^3.4` (Pitfall D)
    - `ls app/Http/Controllers/Auth` returns "no such file" (Breeze not installed)
  </action>
  <verify>
    <automated>php artisan --version &amp;&amp; composer show filament/filament | head -3 &amp;&amp; composer show laravel/horizon | head -3 &amp;&amp; composer show spatie/laravel-permission | head -3 &amp;&amp; composer show bezhansalleh/filament-shield | head -3 &amp;&amp; composer show spatie/laravel-activitylog | head -3 &amp;&amp; composer show rmsramos/activitylog | head -3 &amp;&amp; composer show spatie/laravel-failed-job-monitor | head -3 &amp;&amp; composer show sentry/sentry-laravel | head -3 &amp;&amp; composer show qossmic/deptrac-shim | head -3 &amp;&amp; grep -q "^WOO_WRITE_ENABLED=false$" .env.example &amp;&amp; grep -q "^QUEUE_CONNECTION=redis$" .env.example &amp;&amp; grep -q "^REDIS_CLIENT=phpredis$" .env.example &amp;&amp; test ! -d app/Http/Controllers/Auth &amp;&amp; grep -q "\"tailwindcss\": \"\\^3" package.json</automated>
  </verify>
  <done>
    All 11 packages installed at pinned versions from STACK.md; `.env.example` contains `WOO_WRITE_ENABLED=false`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`; `config/database.php` has 4 logical Redis DBs; `package.json` pins `tailwindcss ^3`; Breeze NOT installed; `php artisan --version` returns Laravel 12.x; local DB migrated (default Laravel tables created).
  </done>
</task>

<task type="auto">
  <name>Task 2: Create app/Domain/ + app/Foundation/ skeleton, ship FeedGenerator contract (FOUND-13), register routes/webhooks.php in bootstrap/app.php</name>
  <files>app/Domain/Products/.gitkeep, app/Domain/Pricing/.gitkeep, app/Domain/Competitor/.gitkeep, app/Domain/Sync/.gitkeep, app/Domain/Webhooks/.gitkeep, app/Domain/CRM/.gitkeep, app/Domain/Suggestions/.gitkeep, app/Domain/Alerting/.gitkeep, app/Domain/Feeds/Contracts/FeedGenerator.php, app/Foundation/Audit/.gitkeep, app/Foundation/Integration/.gitkeep, app/Foundation/Events/.gitkeep, routes/webhooks.php, bootstrap/app.php</files>
  <read_first>
    - .planning/research/ARCHITECTURE.md §2 (Recommended Project Structure — exact folder layout)
    - .planning/phases/01-foundation/01-RESEARCH.md §7 (FeedGenerator contract stub verbatim)
    - .planning/phases/01-foundation/01-RESEARCH.md §3 (bootstrap/app.php webhook group registration + CSRF exclusion)
    - bootstrap/app.php (current state — post laravel/laravel install)
    - routes/web.php (current state — ensure routes/webhooks.php doesn't collide)
  </read_first>
  <action>
    **Create directory skeleton with `.gitkeep` files** (git doesn't track empty dirs):

    ```bash
    mkdir -p app/Domain/Products
    mkdir -p app/Domain/Pricing
    mkdir -p app/Domain/Competitor
    mkdir -p app/Domain/Sync
    mkdir -p app/Domain/Webhooks
    mkdir -p app/Domain/CRM
    mkdir -p app/Domain/Suggestions
    mkdir -p app/Domain/Alerting
    mkdir -p app/Domain/Feeds/Contracts
    mkdir -p app/Foundation/Audit
    mkdir -p app/Foundation/Integration
    mkdir -p app/Foundation/Events

    touch app/Domain/Products/.gitkeep
    touch app/Domain/Pricing/.gitkeep
    touch app/Domain/Competitor/.gitkeep
    touch app/Domain/Sync/.gitkeep
    touch app/Domain/Webhooks/.gitkeep
    touch app/Domain/CRM/.gitkeep
    touch app/Domain/Suggestions/.gitkeep
    touch app/Domain/Alerting/.gitkeep
    touch app/Foundation/Audit/.gitkeep
    touch app/Foundation/Integration/.gitkeep
    touch app/Foundation/Events/.gitkeep
    ```

    **Write `app/Domain/Feeds/Contracts/FeedGenerator.php` (FOUND-13 — verbatim from §7):**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Feeds\Contracts;

    /**
     * Phase 8 channel feeds implement this (Google Merchant, Meta Catalog, Amazon, etc.).
     * Phase 1 ships the empty contract so later phases slot in without refactor.
     *
     * Downstream phases MUST extend this interface to add per-channel specifics; they
     * MUST NOT alter the three methods defined here (doing so breaks FOUND-13 contract).
     */
    interface FeedGenerator
    {
        /** Unique channel identifier — e.g. 'google_merchant', 'meta_catalog', 'amazon_atom'. */
        public function channel(): string;

        /** Generate the feed artifact. Returns absolute filesystem path or fully-qualified URL. */
        public function generate(): string;

        /** When this channel last produced a feed, or null if never generated. */
        public function lastGeneratedAt(): ?\DateTimeImmutable;
    }
    ```

    **Write `routes/webhooks.php` as an empty placeholder** (Plan 04 populates with Woo HMAC-protected routes):

    ```php
    <?php

    /*
    |--------------------------------------------------------------------------
    | Webhook Routes
    |--------------------------------------------------------------------------
    |
    | Inbound webhook endpoints (Woo, Bitrix future). Registered in
    | bootstrap/app.php under the 'api' middleware group with CSRF excluded
    | for the 'webhooks/*' prefix.
    |
    | HMAC verification middleware is added per-route in Plan 04.
    |
    | Phase 1 Plan 01 ships this file empty to establish the registration seam.
    */

    // Routes added in Plan 04 (VerifyWooHmacSignature + WooWebhookController)
    ```

    **Update `bootstrap/app.php`** to register `routes/webhooks.php` under the `api` middleware group and exclude `webhooks/*` from CSRF (verbatim from 01-RESEARCH.md §3):

    ```php
    <?php

    use Illuminate\Foundation\Application;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Illuminate\Foundation\Configuration\Middleware;
    use Illuminate\Support\Facades\Route;

    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
            then: function () {
                Route::middleware('api')
                    ->prefix('')
                    ->group(base_path('routes/webhooks.php'));
            },
        )
        ->withMiddleware(function (Middleware $middleware) {
            // Webhook endpoints don't have sessions / CSRF tokens
            $middleware->validateCsrfTokens(except: ['webhooks/*']);

            // Plan 03 appends AttachCorrelationId middleware here
        })
        ->withExceptions(function (Exceptions $exceptions) {
            //
        })
        ->create();
    ```

    **Verify PSR-4 autoload picks up new namespaces:**

    ```bash
    composer dump-autoload
    php -r "require 'vendor/autoload.php'; var_dump(interface_exists('App\\Domain\\Feeds\\Contracts\\FeedGenerator'));"
    # Must print: bool(true)
    ```
  </action>
  <verify>
    <automated>test -f app/Domain/Feeds/Contracts/FeedGenerator.php &amp;&amp; test -f routes/webhooks.php &amp;&amp; test -d app/Domain/Products &amp;&amp; test -d app/Domain/Pricing &amp;&amp; test -d app/Domain/Competitor &amp;&amp; test -d app/Domain/Sync &amp;&amp; test -d app/Domain/Webhooks &amp;&amp; test -d app/Domain/CRM &amp;&amp; test -d app/Domain/Suggestions &amp;&amp; test -d app/Domain/Alerting &amp;&amp; test -d app/Foundation/Audit &amp;&amp; test -d app/Foundation/Integration &amp;&amp; test -d app/Foundation/Events &amp;&amp; grep -q "routes/webhooks.php" bootstrap/app.php &amp;&amp; grep -q "webhooks/\\*" bootstrap/app.php &amp;&amp; php -r "require 'vendor/autoload.php'; exit(interface_exists('App\\\\Domain\\\\Feeds\\\\Contracts\\\\FeedGenerator') ? 0 : 1);"</automated>
  </verify>
  <done>
    All 9 Domain module directories + 3 Foundation subdirectories exist (with `.gitkeep`); `FeedGenerator` interface loads via PSR-4 autoload; `routes/webhooks.php` exists (empty placeholder); `bootstrap/app.php` registers the webhook route group and excludes `webhooks/*` from CSRF; `composer dump-autoload` produces no warnings.
  </done>
</task>

<task type="auto">
  <name>Task 3: Configure Deptrac module-boundary ruleset, create pint.json + phpstan.neon + phpunit.xml + tests/Pest.php, verify Deptrac passes on empty scaffold and fails on a deliberate cross-domain import</name>
  <files>depfile.yaml, pint.json, phpstan.neon, phpunit.xml, tests/Pest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §2 (Deptrac depfile.yaml — full ruleset) + Pitfall I (container bindings bypass Deptrac)
    - .planning/phases/01-foundation/01-RESEARCH.md §12 (install-order confirms deptrac-shim is in --dev)
    - .planning/research/ARCHITECTURE.md §2 (module boundaries — Foundation is the only shared layer)
    - depfile.yaml (if exists — should not, we're creating it)
    - tests/Pest.php (if exists — may have Pest default scaffold from pest:install)
  </read_first>
  <action>
    **Write `depfile.yaml` at repo root** verbatim from 01-RESEARCH.md §2:

    ```yaml
    parameters:
      paths:
        - ./app
      exclude_files:
        - '#.*test.*#'
      layers:
        - name: Products
          collectors:
            - type: directory
              regex: app/Domain/Products/.*
        - name: Pricing
          collectors:
            - type: directory
              regex: app/Domain/Pricing/.*
        - name: Competitor
          collectors:
            - type: directory
              regex: app/Domain/Competitor/.*
        - name: Sync
          collectors:
            - type: directory
              regex: app/Domain/Sync/.*
        - name: Webhooks
          collectors:
            - type: directory
              regex: app/Domain/Webhooks/.*
        - name: CRM
          collectors:
            - type: directory
              regex: app/Domain/CRM/.*
        - name: Suggestions
          collectors:
            - type: directory
              regex: app/Domain/Suggestions/.*
        - name: Alerting
          collectors:
            - type: directory
              regex: app/Domain/Alerting/.*
        - name: Feeds
          collectors:
            - type: directory
              regex: app/Domain/Feeds/.*
        - name: Foundation
          collectors:
            - type: directory
              regex: app/Foundation/.*
        - name: Http
          collectors:
            - type: directory
              regex: app/Http/.*
      ruleset:
        Products:     [Foundation]
        Pricing:      [Foundation]
        Competitor:   [Foundation]
        Sync:         [Foundation]
        Webhooks:     [Foundation]
        CRM:          [Foundation]
        Suggestions:  [Foundation]
        Alerting:     [Foundation]
        Feeds:        [Foundation]
        Foundation:   []
        Http:         [Foundation, Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds]
    ```

    **Write `pint.json`** (Laravel Pint config, PSR-12 base):

    ```json
    {
        "preset": "laravel",
        "rules": {
            "declare_strict_types": true,
            "strict_comparison": true,
            "strict_param": true,
            "no_unused_imports": true,
            "ordered_imports": { "sort_algorithm": "alpha" }
        }
    }
    ```

    **Write `phpstan.neon`** (Larastan level 6 — STACK.md recommendation):

    ```neon
    includes:
        - vendor/nunomaduro/larastan/extension.neon

    parameters:
        paths:
            - app/
        level: 6
        ignoreErrors:
            # Filament Resource protected statics — ignore "never read" noise
            - '#Property App\\\\Filament.*::\$navigation[A-Za-z]+ is never read#'
        checkMissingIterableValueType: false
        checkGenericClassInNonGenericObjectType: false
    ```

    **Write `phpunit.xml`** (or verify default from Laravel install includes Feature + Unit + Architecture test suites). Ensure this block exists — edit if missing:

    ```xml
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Architecture">
            <directory>tests/Architecture</directory>
        </testsuite>
    </testsuites>
    ```

    **Write `tests/Pest.php`** (standard Pest scaffold, extend with module test helpers):

    ```php
    <?php

    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Tests\TestCase;

    /*
    |--------------------------------------------------------------------------
    | Test Case
    |--------------------------------------------------------------------------
    */

    uses(TestCase::class)->in('Feature', 'Unit');
    uses(TestCase::class, RefreshDatabase::class)->in('Feature');

    /*
    |--------------------------------------------------------------------------
    | Expectations
    |--------------------------------------------------------------------------
    */

    expect()->extend('toBeOne', fn () => $this->toBe(1));

    /*
    |--------------------------------------------------------------------------
    | Functions
    |--------------------------------------------------------------------------
    */

    function something() { /* reserved for global helpers */ }
    ```

    **Make `tests/Architecture` directory** (Plan 05 populates with deptrac wrapper test):

    ```bash
    mkdir -p tests/Architecture
    touch tests/Architecture/.gitkeep
    ```

    **Verify Deptrac passes on empty scaffold:**

    ```bash
    vendor/bin/deptrac analyse --no-progress
    # Expected exit: 0 — no violations (no code in Domain/*  yet)
    ```

    **Verify Deptrac fails on deliberate cross-domain import (this is the Phase 1 Success Criterion 2 proof):**

    ```bash
    # Create a throwaway violator file
    mkdir -p app/Domain/Products/Services
    cat > app/Domain/Products/Services/ViolatorService.php <<'EOF'
    <?php
    namespace App\Domain\Products\Services;

    // DELIBERATE VIOLATION — Products must not depend on CRM
    use App\Domain\CRM\Services\BitrixClient;

    class ViolatorService
    {
        public function __construct(private BitrixClient $client) {}
    }
    EOF

    # Deptrac must exit non-zero
    vendor/bin/deptrac analyse --no-progress
    echo "Exit: $?"
    # Expected: Exit: 1 (or any non-zero)

    # Clean up the violator so the rest of the pipeline is green
    rm -rf app/Domain/Products/Services
    ```

    Document this verification step in the task's final output — the plan's `<done>` requires the positive (pass on empty) AND negative (fail on violator) both proven.
  </action>
  <verify>
    <automated>test -f depfile.yaml &amp;&amp; test -f pint.json &amp;&amp; test -f phpstan.neon &amp;&amp; test -f phpunit.xml &amp;&amp; test -f tests/Pest.php &amp;&amp; grep -q "Products:\\s*\\[Foundation\\]" depfile.yaml &amp;&amp; grep -q "Http:\\s*\\[Foundation" depfile.yaml &amp;&amp; vendor/bin/deptrac analyse --no-progress</automated>
  </verify>
  <done>
    `depfile.yaml` defines 9 Domain + Foundation + Http layers with Foundation as the only cross-cutting allowed dep; `pint.json` + `phpstan.neon` + `phpunit.xml` + `tests/Pest.php` all exist; `vendor/bin/deptrac analyse` exits 0 on the empty scaffold; the deliberate-violator verification from the action (Products → CRM import) was run locally and exited non-zero before being reverted.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Dev machine → Git repo | `.env` never committed; only `.env.example` carries declared keys |
| Composer registry → project | Caret-pinned versions + `composer.lock` prevent drift |
| Laravel bootstrap → webhook routes | Webhook routes registered under `api` middleware (no session, no CSRF) — attack surface is ZERO in Plan 01 because no routes are populated yet |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-01-01 | I | Committing `.env` with secrets | mitigate | Rely on Laravel's default `.gitignore` which excludes `.env`; only `.env.example` is committed (has `DB_PASSWORD=` empty placeholder) |
| T-01-02 | T | Unpinned package versions | mitigate | All `composer require` calls use `^X.Y` carets; `composer.lock` committed |
| T-01-03 | S | Empty webhook route file reachable | accept | `routes/webhooks.php` defines NO routes in this plan; even if reached, Laravel returns 404 |
| T-01-04 | E | Default Filament admin user creation | accept | No admin user created yet — Plan 02 owns `make:filament-user` as part of RBAC wiring |
| T-01-05 | I | Tailwind 4 accidentally installed | mitigate | Post-install verification grep asserts `tailwindcss ^3` in package.json (Pitfall D) |
| T-01-06 | D | Deptrac shim mis-installed breaking CI later | mitigate | `qossmic/deptrac-shim` chosen over direct Deptrac to avoid symfony/console resolver conflicts (01-RESEARCH.md §2) |
</threat_model>

<verification>
- `php artisan --version` → Laravel 12.x
- `composer show filament/filament` → 3.3.x
- `composer show laravel/horizon` → 5.45.x or newer within `^5.45`
- `composer show spatie/laravel-permission` → 7.2.x
- `composer show bezhansalleh/filament-shield` → 3.3.x (NOT 4.x)
- `composer show spatie/laravel-activitylog` → 4.12.x
- `composer show rmsramos/activitylog` → 1.x
- `composer show spatie/laravel-failed-job-monitor` → 4.x
- `composer show sentry/sentry-laravel` → 4.x
- `composer show qossmic/deptrac-shim` → 1.x
- `grep tailwindcss package.json` → `^3.4` (not 4.x — Pitfall D)
- `test ! -d app/Http/Controllers/Auth` (Breeze NOT installed)
- `grep -q "^WOO_WRITE_ENABLED=false$" .env.example` exits 0
- `grep -q "^QUEUE_CONNECTION=redis$" .env.example` exits 0
- `grep -q "^REDIS_CLIENT=phpredis$" .env.example` exits 0
- `php -r "require 'vendor/autoload.php'; exit(interface_exists('App\\\\Domain\\\\Feeds\\\\Contracts\\\\FeedGenerator') ? 0 : 1);"` exits 0
- `vendor/bin/deptrac analyse --no-progress` exits 0 on empty scaffold
- Deliberate violator (`App\\Domain\\Products\\Services\\ViolatorService` importing from `App\\Domain\\CRM`) makes deptrac exit non-zero — verified and reverted
</verification>

<success_criteria>
- **FOUND-02 (module boundaries):** `app/Domain/<Module>/` layout created and Deptrac ruleset committed; passes on empty; fails on cross-domain import (proven in Task 3 action)
- **FOUND-13 (FeedGenerator stub):** interface file present, PSR-4 autoloadable, three methods as specified
- All Phase 1 packages pinned and installed; no forbidden packages (Breeze, Telescope, Tailwind 4)
- `.env.example` has `WOO_WRITE_ENABLED=false` as the default (D-08)
- Redis uses 4 logical DBs (prevents FLUSHDB from nuking queue state)
- Wave 2 plans (02-RBAC, 03-Foundation) can start without any additional install steps
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation/01-01-SUMMARY.md` documenting: installed package versions (from `composer show` output), the Deptrac positive + negative test outcome, and any deviations from 01-RESEARCH.md §12 install sequence (expected: none).
</output>
