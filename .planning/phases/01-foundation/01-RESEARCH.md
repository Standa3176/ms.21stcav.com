# Phase 1: Foundation - Research

**Researched:** 2026-04-18
**Domain:** Laravel 12 + Filament 3.3 cross-cutting infrastructure (RBAC, module boundaries, event bus, audit log, integration log, suggestions seam, HMAC webhook intake, Horizon supervisors, Redis persistence, shadow-mode write gate, failed-job alerting, retention prunes)
**Confidence:** HIGH — stack is already pinned in `.planning/research/STACK.md` with recent publish dates; implementation patterns verified against official docs, package READMEs, and the project's own research corpus.

---

## Summary

Phase 1 is not about choosing a stack — STACK.md already pinned every package. This research is about **implementation specifics** for wiring 12 cross-cutting concerns together in the correct order, so no later phase needs to retrofit infrastructure. Every FOUND-01..FOUND-13 requirement has a concrete pattern here, and every Claude's-Discretion area from CONTEXT.md resolves to a default.

The three biggest risks this research resolves:

1. **Order of installation** — get Shield, spatie/permission, and Filament wired in the wrong order and you rediscover every permission on every deploy (or worse, orphan role rows). The migration ordering table below is deliberate.
2. **Raw body capture for HMAC** — Laravel 12's JSON middleware reads the body stream once. If it fires before the HMAC middleware, signature verification fails silently on every webhook. The middleware must be registered on a route group before any JSON-parsing middleware — concrete config in §3.
3. **Correlation ID propagation across the queue boundary** — Laravel 12's `Context` facade is purpose-built for this (dehydrate on dispatch, hydrate on execute). Using it is a 3-line boot-method change; not using it means reinventing the wheel per module. Documented in §10.

**Primary recommendation:** follow the migration ordering in §12, install packages in the sequence in §12's "Project Bootstrap Sequence", and treat the 5 Claude's-Discretion defaults (HMAC secret, correlation ID, Deptrac scope, Horizon worker counts, Redis persistence) as **the** implementation — they were chosen to match REQUIREMENTS.md and CONTEXT.md exactly.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**RBAC (FOUND-01):**
- **D-01:** Use `bezhansalleh/filament-shield` with auto-generated per-Resource permissions via `shield:generate`. One permission per (Resource × action) — `view_any`, `view`, `create`, `update`, `delete`, `restore`, `force_delete`. Coarse role-in-code checks are NOT sufficient.
- **D-02:** Four roles with **tight, not uniform** responsibility split:
  - `admin` — all Resources, all actions
  - `pricing_manager` — `Product`, `PricingRule`, `CompetitorPrice`, `SyncRun` (read-only for sync; CRUD on rules and products)
  - `sales` — `CrmPushLog` (read-only), own `ActivityLog` entries (read-only)
  - `read_only` — every page `view_any` / `view` only; no create/edit/delete anywhere
- **D-03:** Phase 1 must ship an **idempotent seeder** that (a) creates the 4 roles, (b) runs `shield:generate`, (c) assigns permissions per D-02. Runs on every deploy — role drift is a deploy-time correction, not a manual ops task.

**Retention SLAs (FOUND-12):**
- **D-04:** `audit_log` (spatie/activitylog) → 365 days
- **D-05:** `integration_events` → 90 days
- **D-06:** Competitor CSV source files + `csv_parse_errors` → 90 days
- **D-07:** `sync_errors` → 90 days
- **D-08:** `sync_diffs` → **never prune while `WOO_WRITE_ENABLED=false`**; post-cutover prune to 30 days
- **D-09:** All prunes are scheduled artisan commands (`routes/console.php`), not model observers. Prune commands log counts to `audit_log` (meta-audit).

**Alerting (FOUND-11):**
- **D-10:** Channel: **email only** (not Slack) via `spatie/laravel-failed-job-monitor`
- **D-11:** Severity routing: **single distribution** — all failures to the same list
- **D-12:** Recipient list is **database-backed** via a new `AlertRecipient` model + Filament Resource (admin-only, Shield-gated). **Adds scope to Phase 1.**
- **D-13:** Duplicate-alert suppression: same failure signature within a **rolling 5-minute window = one alert**. No quiet hours.

**Suggestions (FOUND-06):**
- **D-14:** `suggestions` table — ulid id, `kind` (string enum), `status` enum, `correlation_id` indexed, `payload` (JSON), `evidence` (JSON), `proposed_by` (nullable morph), `proposed_at`, `resolved_by_user_id`, `resolved_at`, `rejection_reason` (nullable text), `applied_at` (nullable). Indexes: `(kind, status)`, `(correlation_id)`, `(status, proposed_at)`.
- **D-15:** `approve` action **enqueues `ApplySuggestionJob`** on the `default` queue — never synchronous in Livewire. Job resolves a `SuggestionApplier` per `kind`, logs to `integration_events`, emits the appropriate domain event. Idempotent via `status=applied` guard.
- **D-16:** Every suggestion row carries an **indexed `correlation_id`** threading through `audit_log` → `integration_events` → `suggestions` → emitted events.
- **D-17:** Phase 1 ships: `suggestions` table, Filament inbox Resource, `SuggestionApplier` contract, seeded no-op test suggestion, stubbed `SuggestionPolicy` (Shield-wired). Phase 5 is the first real producer.

### Claude's Discretion (research must produce defaults)

| Area | Default chosen (see section) |
|------|------------------------------|
| HMAC secret management | Single env var per webhook source (`WC_WEBHOOK_SECRET`); rotation runbook stub in Phase 7; HMAC-SHA256 + base64 on raw body (§3) |
| Correlation ID generation | UUIDv4 at entry; honour inbound `X-Correlation-Id` / `X-Request-Id` if present; middleware injects to `Context`; propagates to queued jobs via Laravel 12's `Context::dehydrating` / `::hydrated` (§10) |
| Deptrac scope | Module boundaries only (`app/Domain/<Module>/`). No layer-enforcement ruleset in Phase 1 (§2) |
| Horizon supervisor per-queue worker counts | Derived from Woo 100-req/min and Bitrix 2-req/sec ceilings — concrete numbers in §4 |
| Redis persistence exact config | `appendonly yes`, `appendfsync everysec`, `maxmemory-policy noeviction` (required for queues — never evict jobs) (§4) |

### Deferred Ideas (OUT OF SCOPE for Phase 1)

- HMAC secret rotation operational runbook — Phase 7 cutover docs
- Severity-split alert routing — post-cutover
- Slack alerting channel — post-cutover if email proves too slow
- Shadow-mode `sync_diffs` UI — Phase 7
- Rollback SLA — Phase 7 cutover discussion

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FOUND-01 | Laravel 12 + Filament 3.3 boots with authenticated users and 4-role RBAC | §1 Filament/Shield wiring, §12 bootstrap sequence |
| FOUND-02 | `app/Domain/<Module>/` layout enforced by Deptrac CI | §2 Deptrac config + CI integration |
| FOUND-03 | Domain event bus with `correlation_id` threading | §10 Context facade + event base class |
| FOUND-04 | `audit_log` records every tracked-model change via spatie/activitylog | §5 LogsActivity + batch UUID for correlation_id |
| FOUND-05 | `integration_events` records every outbound API attempt | §6 custom table + Guzzle middleware pattern |
| FOUND-06 | `suggestions` table + Filament inbox with approve/reject | §7 schema, Resource, stub applier |
| FOUND-07 | HMAC-verified Woo webhooks, raw persisted, deduped, queued < 200ms | §3 middleware + webhook_receipts schema |
| FOUND-08 | `WOO_WRITE_ENABLED` gate routes writes to `sync_diffs` | §8 WooClient wrapper + feature test pattern |
| FOUND-09 | Horizon supervisors across 7 named queues | §4 supervisor config |
| FOUND-10 | Redis persistence (`appendonly`, `appendfsync everysec`) | §4 Redis config |
| FOUND-11 | Failed jobs trigger admin email alert | §9 spatie/failed-job-monitor + AlertRecipient |
| FOUND-12 | Retention prune commands enforced | §11 scheduled commands + conditional sync_diffs prune |
| FOUND-13 | `FeedGenerator` interface stub exists | §7 Suggestions-adjacent contract stub |

</phase_requirements>

## Project Constraints (from CLAUDE.md)

The project CLAUDE.md enforces these directives — research recommendations must align, not contradict:

- **Tech stack fixed:** Laravel 12, PHP 8.2+, Filament 3, Horizon + Redis, MySQL, Blade/Livewire via Filament, PHPUnit + Pest. Research MUST NOT recommend alternatives.
- **WooCommerce REST only** — never direct WP DB writes. The `sync_diffs` shadow-gate MUST sit in front of REST calls, never in front of DB operations.
- **Event-driven from day one** — domain events `ProductPriceChanged`, `StockWentToZero`, `OrderCreated` etc. fire on every mutation. Phase 1 ships the event *base class*, not feature events.
- **Audit everything** — every sync, push, rule application stored with correlation_id.
- **Suggestions pattern** — every data-changing feature writes to `suggestions` first when `auto_apply=false`.
- **Feed abstraction** — `FeedGenerator` interface stub required in v1 for Phase 8 channel feeds.
- **GSD workflow enforcement** — no file edits outside a GSD command. Planning must route through `/gsd-plan-phase`, execution through `/gsd-execute-phase`.

Research complies with all constraints above. Notable alignments:
- HMAC middleware pattern (§3) avoids `spatie/laravel-webhook-client` — CLAUDE.md stack does not list it, and STACK.md explicitly rejects it.
- `sync_diffs` shadow-mode gate (§8) sits at the REST call boundary, not the DB — preserves "REST only" discipline.
- Every outbound API helper in §6 writes `integration_events` — satisfies "audit everything".

---

## Standard Stack

**All packages below are already pinned in `.planning/research/STACK.md` — this table is a quick reference, not a re-derivation.** See STACK.md for the full compatibility matrix and "What NOT to Use" list.

### Core (Phase 1 uses every row)

| Library | Pinned Version | Purpose | Phase 1 Usage |
|---------|---------------|---------|---------------|
| `laravel/framework` | `^12.0` | Framework | Base |
| `filament/filament` | `^3.3` | Admin panel | Admin panel at `ops.meetingstore.co.uk` |
| `laravel/horizon` | `^5.45` | Queue supervisor + dashboard | 7 named queues (§4) |
| `laravel/pulse` | `^1.4` | Production metrics | Linked from Filament nav |
| `spatie/laravel-permission` | `^7.2` | RBAC DB + traits | 4 roles, integer IDs (§1) |
| `bezhansalleh/filament-shield` | `^3.3` | Filament × Spatie Permission glue | `shield:install` + `shield:generate` + seeder (§1) |
| `spatie/laravel-activitylog` | `^4.12` | Model-change audit log | `activity_log` table + `LogsActivity` trait (§5) |
| `rmsramos/activitylog` | `^1.0` | Filament viewer for activitylog | Drop-in Filament Resource (§5) |
| `spatie/laravel-failed-job-monitor` | `^4.x` | Failed-job alerting | Email-only channel (§9) |
| `sentry/sentry-laravel` | `^4.0` | Error tracking | Global exception handler |
| `qossmic/deptrac-shim` | `^1.0` (wraps deptrac `^2.x`) | Architecture fitness test | CI step (§2) |
| `nunomaduro/larastan` | `^3.0` | Static analysis | CI step |
| `laravel/pint` | `^1.24` | PSR-12 formatter | CI + pre-commit |
| `pestphp/pest` + `pest-plugin-laravel` | `^3.0` | Testing | Feature tests for each FOUND-* |

### Not Installed Until Later Phases

- `automattic/woocommerce` — Phase 2 adds real calls; Phase 1 only ships the `WooClient` skeleton + shadow-mode gate
- `bitrix24/b24phpsdk` — Phase 4 (CRM)
- `spatie/simple-excel` — Phase 5 (competitor CSV ingest)

### Version verification (April 2026)

STACK.md confirms package versions via publish dates:
- `spatie/laravel-activitylog ^4.12` — released Feb-Mar 2026 with `^12.0` Laravel constraint [CITED: packagist.org/packages/spatie/laravel-activitylog]
- `bezhansalleh/filament-shield ^3.3` — 3.x line actively backported; 4.x is Filament 4 only [CITED: github.com/bezhanSalleh/filament-shield]
- `spatie/laravel-failed-job-monitor ^4.x` — v4 on main branch, compatible with Laravel 10/11/12 [CITED: github.com/spatie/laravel-failed-job-monitor]
- `qossmic/deptrac` — v2.x is the current stable line [CITED: github.com/qossmic/deptrac]

Before `composer require`, the planner SHOULD run `composer show <package>` or `npm view`-equivalent (`composer global show qossmic/deptrac`) to confirm no dot-release has happened between 2026-04-18 and execution.

---

## Architecture Patterns

The architecture is locked by `.planning/research/ARCHITECTURE.md` §2 (project structure) and §3 (five patterns). This section documents **Phase 1-specific wiring** — how to bring each pattern from "design" to "running code."

### Recommended Project Structure

Per ARCHITECTURE.md §2 — this is the folder layout Phase 1 must establish:

```
app/
├── Console/Commands/
│   └── {Prune}{Table}Command.php    # Phase 1 ships 4 of these (§11)
├── Domain/                          # Deptrac enforces boundaries (§2)
│   ├── Products/                    # EMPTY in Phase 1 — folder reserved
│   ├── Pricing/                     # EMPTY
│   ├── Competitor/                  # EMPTY
│   ├── Sync/                        # SKELETON — WooClient + sync_diffs only
│   ├── Webhooks/                    # PHASE 1 FULL — HMAC middleware + controller + receipts
│   ├── CRM/                         # EMPTY — Phase 4 fills
│   ├── Suggestions/                 # PHASE 1 FULL — model, Resource, contract, stub applier
│   ├── Alerting/                    # PHASE 1 NEW (D-12) — AlertRecipient model + Resource
│   └── Feeds/Contracts/             # PHASE 1 — FeedGenerator.php interface stub (FOUND-13)
├── Foundation/                      # Cross-cutting infra (NOT a domain)
│   ├── Audit/                       # PHASE 1 — Auditor service wrapping spatie/activitylog
│   ├── Integration/                 # PHASE 1 — IntegrationLogger + IntegrationEvent model
│   └── Events/                      # PHASE 1 — DomainEvent base class with correlation_id
├── Http/
│   ├── Controllers/
│   └── Middleware/
│       └── AttachCorrelationId.php  # PHASE 1 — UUIDv4 + Context injection
├── Models/User.php                  # Only truly cross-domain model
├── Policies/
└── Providers/
    ├── AppServiceProvider.php
    ├── EventServiceProvider.php
    ├── AuthServiceProvider.php
    ├── HorizonServiceProvider.php   # PHASE 1 — gate via `admin` role
    └── Filament/AdminPanelProvider.php  # PHASE 1 — panel config
```

### Pattern 1: Event Bus with Correlation ID (FOUND-03)

**What:** A `DomainEvent` base class that every module-level event extends. Auto-populates `correlation_id` from Laravel 12's `Context` facade on construction.

**Base class (ships in Phase 1):**

```php
// app/Foundation/Events/DomainEvent.php
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

**Source:** Laravel 12 Context facade docs — `Context` survives the HTTP → queue boundary via automatic dehydrate/hydrate [CITED: laravel.com/docs/12.x/context].

### Pattern 2: Suggestions seam (D-14 through D-17, FOUND-06)

Fully detailed in §7. The Phase 1 deliverable is: table + model + Filament Resource + `SuggestionApplier` contract + one seeded test suggestion + `StubApplier` that just flips status to `applied` (so the Phase 1 acceptance test passes).

### Pattern 3: Outbound integration log (FOUND-05)

Fully detailed in §6. Pattern: every HTTP client (WooClient skeleton in Phase 1, BitrixClient/SupplierClient in later phases) writes to `integration_events` on every call — success and failure.

### Pattern 4: HMAC Webhook Middleware (FOUND-07)

Fully detailed in §3.

### Pattern 5: Queue Segregation (FOUND-09)

Fully detailed in §4.

### Anti-Patterns to Avoid (from ARCHITECTURE.md §9)

- **Eloquent relationships across modules** → use SKU strings + `ProductLookupService::findBySku()` not `Order::belongsTo(Product)`
- **Controller with business logic for webhooks** → controller is ≤20 lines (verify HMAC → persist raw → fire event → 200)
- **Direct Woo DB writes** → CLAUDE.md-level forbidden
- **Sharing one queue for all work** → Phase 1 ships 7 named queues
- **Audit log used as event bus** → audit_log is read-only from the feature code side; writes come from `LogsActivity` trait and `Auditor::record()` only
- **Writing to Woo from multiple places** → only `Sync` module calls WooClient::put/post; other modules fire events
- **Skipping suggestions table "just for now"** → CONTEXT.md D-17 mandates it ship in Phase 1

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| RBAC schema + Filament wiring | Custom policies + nav gates | `spatie/laravel-permission` + `bezhansalleh/filament-shield` | Shield's `shield:generate` produces per-Resource permissions automatically; DIY takes weeks and never stays in sync with new Resources |
| Model-change audit log | Custom `ModelObserver::updated()` → `audit_log` writes | `spatie/laravel-activitylog` + `LogsActivity` trait | Spatie handles morph relations, batch logging, causer resolution, soft-delete semantics. DIY misses edge cases (mass assignment, `update([])` no-op detection, restore events) |
| Activitylog Filament UI | Custom Resource | `rmsramos/activitylog` | Ships a Filament Resource with causer/subject/date filters; free |
| Failed-job alerting | Custom `Horizon::afterFailed()` hook | `spatie/laravel-failed-job-monitor` | Built-in dedup hooks + channel abstraction + Laravel notification integration |
| WooCommerce HMAC verification | — (no package worth using) | Custom middleware, ~30 lines | STACK.md explicitly rejects `spatie/laravel-webhook-client` — too opinionated, couples model structure. HMAC spec is trivial (§3) |
| Correlation ID propagation | `session()->put('corr_id')` or job payload field | Laravel 12 `Context` facade | Official Laravel 12 feature; dehydrate/hydrate across queue boundary is automatic [CITED: laravel.com/docs/12.x/context] |
| Deptrac equivalent | Custom architecture test in PHPUnit | `qossmic/deptrac` | Purpose-built, config-driven, CI-friendly report output. DIY architecture tests grow into a second codebase |
| Float math for money | `round($price * 1.2, 2)` | BCMath / integer pennies | Phase 3 decision, but the `ext-bcmath` install (STACK.md line 218) happens in Phase 1 VPS setup |
| Raw CSV parsing | `fgetcsv()` | `spatie/simple-excel` (Phase 5) | BOM, encoding, generators — STACK.md §6 details |

**Key insight:** Every item above was either (a) already resolved by STACK.md with evidence of why the alternative is wrong, or (b) so well-specified in Laravel 12 docs (Context, Shield, activitylog) that reinventing is pure cost.

---

## Runtime State Inventory

**N/A — Phase 1 is greenfield.** This project has no existing runtime, no prior deployments, no stored data, no OS-registered state, no secrets to rotate, no build artifacts.

The only artifacts are `.planning/*` docs and no application code. All 5 categories of Runtime State Inventory return "None — greenfield project":

| Category | Items Found |
|----------|-------------|
| Stored data | None — no DB yet, no Redis yet, no storage |
| Live service config | None — VPS not provisioned |
| OS-registered state | None — no cron, no systemd, no Supervisor |
| Secrets/env vars | None — `.env` file will be created in Phase 1 task list |
| Build artifacts | None — `composer install` hasn't happened |

This section is included for completeness (the agent instructions require explicit statements, not omissions). The planner can skip state-migration tasks entirely for Phase 1.

---

## Environment Availability

**Per CLAUDE.md, the development/target environment is the existing VPS hosting `meetingstore.co.uk`.** The planner MUST include an "environment bootstrap" plan as Plan 0 that verifies these on the target machine:

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All | TBD (VPS provision step) | ^8.2 | — (hard block) |
| `ext-redis` (phpredis) | Horizon, cache | TBD | PECL current | `predis/predis` (STACK.md confirms 1.5-2× throughput drop) |
| `ext-bcmath` | Phase 3 pricing math | TBD | — | None — install is `apt install php8.2-bcmath` (STACK.md line 218) |
| `ext-intl` | Bitrix SDK (Phase 4), several Spatie packages | TBD | — | None — `apt install php8.2-intl` |
| MySQL | Primary DB | TBD | 8.0+ | None |
| Redis | Queues, cache, Pulse | TBD | 7.x (or Valkey 7.x) | None — required by Horizon |
| Composer 2 | Install | TBD | Current | None |
| Node.js + npm | Vite build | TBD | LTS | None |
| Supervisor | Horizon supervision | TBD | Any recent | None — "non-negotiable for queue reliability on persistent VPS" (STACK.md) |
| Cron | Scheduler entry | TBD | — | None |

**Missing dependencies with no fallback:** PHP extensions (`bcmath`, `intl`), MySQL, Redis, Supervisor, Cron. Planner must add a "Plan 0: VPS provision + dependency install" before any Laravel-side work.

**Missing dependencies with fallback:** phpredis — Predis is acceptable but documented throughput penalty.

Windows-side note (the repo lives on a Windows dev machine per the env info): the planner should document Herd/Laragon/Valet as the acceptable dev equivalent; production is Linux VPS.

---

## Section 1 — Filament 3.3 admin panel boot + Shield wiring (FOUND-01)

### Install order (must follow exactly)

```bash
# 1. Filament panel (creates AdminPanelProvider)
composer require filament/filament:"^3.3"
php artisan filament:install --panels

# 2. Spatie permission (publishes migration + config)
composer require spatie/laravel-permission:"^7.2"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate  # creates roles, permissions, model_has_*, role_has_permissions

# 3. Shield (requires spatie/permission installed first)
composer require bezhansalleh/filament-shield:"^3.3"
php artisan shield:install admin  # registers Shield plugin on the `admin` panel
                                   # publishes config/filament-shield.php
                                   # creates app/Filament/Resources/RoleResource/

# 4. Generate permissions — RE-RUN ON EVERY DEPLOY (D-03)
php artisan shield:generate --all --panel=admin
```

**Why this order matters:** Shield's `install` command depends on `spatie/permission` migrations existing. Running Shield first throws "table permissions not found" and corrupts the published config.

### Permission ID type decision — integer (not UUID)

`spatie/laravel-permission` defaults to integer auto-increment IDs. Shield expects integer IDs. Switching to UUIDs requires publishing the migration and editing BOTH the `permissions.id` and `roles.id` columns PLUS every `model_has_permissions`/`model_has_roles` pivot. **Recommendation: stay on integer IDs unless there's a hard external requirement** (there isn't — the `User` model is the only morphable, and it can use either).

**Source:** [CITED: github.com/bezhansalleh/filament-shield — README.md 3.x branch]. [ASSUMED: no integer-ID-specific trap surfaces in the Phase 1 seeder — verify by running the seeder twice on a fresh DB during plan-check.]

### Idempotent seeder pattern (D-03)

```php
// database/seeders/RolePermissionSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clear cache so newly-created permissions are found
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2. Create (or fetch) the 4 roles (D-02)
        $admin          = Role::firstOrCreate(['name' => 'admin',           'guard_name' => 'web']);
        $pricingManager = Role::firstOrCreate(['name' => 'pricing_manager', 'guard_name' => 'web']);
        $sales          = Role::firstOrCreate(['name' => 'sales',           'guard_name' => 'web']);
        $readOnly       = Role::firstOrCreate(['name' => 'read_only',       'guard_name' => 'web']);

        // 3. shield:generate has already produced per-Resource permissions.
        //    Admin: give them ALL permissions (idempotent sync).
        $admin->syncPermissions(Permission::all());

        // 4. read_only: only view_any_* and view_* permissions
        $readOnly->syncPermissions(
            Permission::where('name', 'like', 'view_any_%')
                ->orWhere('name', 'like', 'view_%')
                ->get()
        );

        // 5. pricing_manager: Product/PricingRule/CompetitorPrice/SyncRun
        //    CRUD on Product + PricingRule; view-only on CompetitorPrice + SyncRun
        $pricingManager->syncPermissions(array_merge(
            Permission::where('name', 'like', '%_product')->orWhere('name', 'like', '%_pricing::rule')->pluck('name')->all(),
            Permission::whereIn('name', ['view_any_competitor::price', 'view_competitor::price', 'view_any_sync::run', 'view_sync::run'])->pluck('name')->all(),
        ));

        // 6. sales: view CrmPushLog + own activity log
        $sales->syncPermissions([
            'view_any_crm::push::log', 'view_crm::push::log',
            // activity log viewing gated separately via policy
        ]);

        // 7. Forget cache again so role changes take effect
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

**Deploy-time execution (D-03):** add to `composer dump-autoload && php artisan migrate --force && php artisan shield:generate --all && php artisan db:seed --class=RolePermissionSeeder --force` in the deploy runbook.

**Warning (Shield permission names):** `shield:generate` produces permission names in the shape `{action}_{resource::name}` where the resource name is derived from the Filament Resource's `getRelationshipName()`. The exact pattern can be configured in `config/filament-shield.php` under `permission_prefixes`. The seeder must match whatever format is generated — verify by running `php artisan permission:show` after `shield:generate` on a throwaway run and copying the actual names.

**Source:** [CITED: filamentphp.com/plugins/bezhansalleh-shield], [CITED: github.com/bezhansalleh/filament-shield — 3.x README], [CITED: laraveldaily.com/lesson/filament-3/shield-plugin-roles-permissions].

### Filament admin panel provider minimal config

```php
// app/Providers/Filament/AdminPanelProvider.php — auto-generated by filament:install --panels
public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('admin')
        ->path('admin')
        ->login()  // Filament ships its own auth; do NOT use Breeze
        ->plugin(FilamentShieldPlugin::make())  // added by shield:install admin
        ->colors(['primary' => Color::Blue])
        ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
        ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
        ->pages([Pages\Dashboard::class])
        ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
        ->middleware([...Filament\Http\Middleware defaults...])
        ->authMiddleware([Authenticate::class]);
}
```

**Breeze vs Filament auth:** Filament 3 ships its own login page via `->login()`. Installing Breeze alongside produces two login routes and confuses users. **Do not install Breeze.** Phase 1 auth = Filament-native only.

### Domain-module Filament Resources — discovery pattern

Per ARCHITECTURE.md §2, each `app/Domain/<Module>/Filament/` folder should host that module's Resources. The default `discoverResources` call above only scans `app/Filament/Resources`. **Add per-module discovery calls in AdminPanelProvider:**

```php
->discoverResources(in: app_path('Domain/Suggestions/Filament/Resources'), for: 'App\\Domain\\Suggestions\\Filament\\Resources')
->discoverResources(in: app_path('Domain/Alerting/Filament/Resources'),    for: 'App\\Domain\\Alerting\\Filament\\Resources')
// ... one per module that exposes Resources in Phase 1
```

Only `Suggestions` and `Alerting` expose Resources in Phase 1. Add more entries in later phases as modules populate.

---

## Section 2 — Deptrac module-boundary enforcement (FOUND-02)

### Install

```bash
composer require --dev qossmic/deptrac-shim
```

`deptrac-shim` is a no-dependencies wrapper that installs the Deptrac PHAR. Avoids composer resolver conflicts with symfony/console versions. [CITED: github.com/qossmic/deptrac — README].

### `depfile.yaml` — module-boundary ruleset (Claude's Discretion resolved)

```yaml
# depfile.yaml — committed at repo root
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
    # Every Domain module can depend on Foundation but NOT on other Domain modules
    # Cross-domain communication is via events + Suggestions + Foundation services ONLY
    Products:     [Foundation]
    Pricing:      [Foundation]
    Competitor:   [Foundation]
    Sync:         [Foundation]
    Webhooks:     [Foundation]
    CRM:          [Foundation]
    Suggestions:  [Foundation]
    Alerting:     [Foundation]
    Feeds:        [Foundation]
    Foundation:   []         # Foundation depends on nothing from app/
    Http:         [Foundation, Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds]
    # Http layer can depend on any domain — controllers route to domains
```

**Scope decision (Claude's Discretion):** module boundaries ONLY — no layer-enforcement (Controller→Service→Domain) ruleset in Phase 1. Rationale: Laravel's folder convention handles this implicitly; adding a second ruleset doubles the false-positive surface without changing what the CI catches. Defer to post-cutover.

### CI integration

```yaml
# .github/workflows/ci.yml (excerpt) — or equivalent GitLab / Bitbucket
- name: Deptrac (module boundaries)
  run: vendor/bin/deptrac analyse --report-skipped --fail-on-uncovered
```

Exit code non-zero when a cross-domain import is introduced.

### Acceptance test (Success Criterion 2)

A Phase 1 task must create a deliberately-violating PR (e.g., a commit that adds `use App\Domain\CRM\Services\BitrixClient;` inside `app/Domain/Products/Services/`) and confirm the CI step fails. Revert the commit before merge.

### Known Deptrac false-positive patterns

- **Service container bindings:** `app(SomeService::class)` or facade calls pass through a string, not a `use` statement. Deptrac doesn't see it — this is a design choice that favours explicit DI. Accept the tradeoff: constructor injection produces the boundary error, `app()` calls do not. Phase 1 convention: always use constructor DI in cross-module consumption (e.g., inside a listener).
- **Eloquent relations in model files:** `belongsTo(\App\Domain\Products\Models\Product::class)` counts as a use. Anti-Pattern 1 forbids this already, so no workaround needed.
- **Filament Resource auto-discovery:** `discoverResources()` in AdminPanelProvider passes strings, not classes. No Deptrac hit.

**Source:** [CITED: github.com/qossmic/deptrac], [CITED: github.com/avosalmon/modular-monolith-laravel/blob/main/deptrac.yaml — example from Laracon Online 2022].

---

## Section 3 — HMAC webhook middleware for WooCommerce (FOUND-07)

### WooCommerce HMAC scheme (verified)

- Header: `X-WC-Webhook-Signature`
- Value: `base64_encode(hash_hmac('sha256', $rawBody, $secret, true))`
- **Critical: `$rawBody` is the raw request body bytes — NOT a re-encoded JSON**
- Timing-safe comparison: `hash_equals($expected, $received)`
- Gotcha: WooCommerce calls `wp_specialchars_decode()` on the secret before signing — avoid `&<>` characters in the secret (use alphanumeric-only)

**Sources:** [CITED: woocommerce.github.io/code-reference/classes/WC-Webhook.html], [CITED: hookdeck.com — Guide to WooCommerce Webhooks], ARCHITECTURE.md §3 Pattern 4 has the canonical middleware snippet.

### HMAC secret management (Claude's Discretion resolved)

- **One env var per webhook source:** `WC_WEBHOOK_SECRET` for WooCommerce. If a second Woo store is ever added, a second env var — do not share a secret across stores.
- Stored in `.env` only (never committed, never in DB).
- Rotation runbook: updated secret in Woo admin → updated secret in `.env` → `php artisan config:clear` → deploy. Rotation must happen within a maintenance window (any webhook fired during the swap fails HMAC).
- Phase 7 cutover docs contain the full rotation runbook. Phase 1 ships: the env key, the middleware, and a comment in `.env.example` pointing at Phase 7 docs.

### Middleware — full implementation

```php
// app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php
namespace App\Domain\Webhooks\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWooHmacSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-WC-Webhook-Signature');
        $secret    = config('services.woocommerce.webhook_secret');

        abort_unless($signature && $secret, 401, 'Missing signature or secret');

        // CRITICAL: getContent() returns the RAW body as sent by Woo.
        // Do NOT call ->json() or anything that triggers input parsing before this point.
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        abort_unless(hash_equals($expected, $signature), 401, 'Invalid signature');

        return $next($request);
    }
}
```

### Route registration — `routes/webhooks.php` (separate file)

```php
// routes/webhooks.php
use App\Domain\Webhooks\Http\Controllers\WooWebhookController;
use App\Domain\Webhooks\Http\Middleware\VerifyWooHmacSignature;

Route::prefix('webhooks/woo')
    ->middleware([VerifyWooHmacSignature::class])
    ->group(function () {
        Route::post('order',    [WooWebhookController::class, 'order'])->name('webhooks.woo.order');
        Route::post('customer', [WooWebhookController::class, 'customer'])->name('webhooks.woo.customer');
        // Phase 4 adds more topics
    });
```

### Bootstrap/app.php registration (Laravel 12)

```php
// bootstrap/app.php (excerpt)
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:     __DIR__ . '/../routes/web.php',
        commands:__DIR__ . '/../routes/console.php',
        health:  '/up',
        then: function () {
            Route::middleware('api')  // api middleware = no CSRF, no session
                ->prefix('')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Exclude webhook routes from CSRF
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Register AttachCorrelationId as a global middleware (§10)
        $middleware->web(append: [\App\Http\Middleware\AttachCorrelationId::class]);
    })
    ->create();
```

**Why `api` middleware group (not `web`):** the `web` group includes `VerifyCsrfToken`, `StartSession`, `ShareErrorsFromSession`, `SubstituteBindings`. Webhooks don't need sessions or CSRF — `api` middleware is the minimal pipeline for them. Note: the `api` group in Laravel 12 DOES still call the `ValidateJsonWebTokens`-style input-reading steps; the raw body is preserved because `$request->getContent()` always reads from the raw stream, not the parsed input.

### Webhook receipts table (FOUND-07 acceptance: row created + dedup by delivery ID)

```php
// database/migrations/XXXX_XX_XX_create_webhook_receipts_table.php
Schema::create('webhook_receipts', function (Blueprint $t) {
    $t->id();
    $t->string('source', 32);                 // 'woo' | 'bitrix' (future)
    $t->string('topic', 64);                   // 'order.created', 'customer.updated', etc.
    $t->string('delivery_id', 128);            // X-WC-Webhook-Delivery-ID
    $t->json('headers');                       // full header dump
    $t->longText('raw_body');                  // raw payload as received
    $t->string('correlation_id', 36)->index(); // UUIDv4 — see §10
    $t->timestamp('received_at');
    $t->timestamp('processed_at')->nullable();
    $t->string('status', 16)->default('received'); // 'received' | 'processed' | 'failed'
    $t->text('error_message')->nullable();
    $t->timestamps();

    // Dedup key — same delivery_id twice = same event retried by Woo
    $t->unique(['source', 'delivery_id']);
    $t->index(['source', 'topic', 'received_at']);
});
```

### Controller — ≤20 lines

```php
// app/Domain/Webhooks/Http/Controllers/WooWebhookController.php
final class WooWebhookController
{
    public function order(Request $request): JsonResponse
    {
        $deliveryId = $request->header('X-WC-Webhook-Delivery-ID')
            ?? (string) Str::uuid();  // defensive — should always be present

        // Idempotent insert — unique index throws on duplicate delivery_id
        try {
            $receipt = WebhookReceipt::create([
                'source'         => 'woo',
                'topic'          => 'order',
                'delivery_id'    => $deliveryId,
                'headers'        => $request->headers->all(),
                'raw_body'       => $request->getContent(),
                'correlation_id' => Context::get('correlation_id'),
                'received_at'    => now(),
            ]);
        } catch (QueryException $e) {
            // Duplicate — already received. Return 200 so Woo stops retrying.
            if ($e->getCode() === '23000') {
                return response()->json(['status' => 'duplicate']);
            }
            throw $e;
        }

        // Fire domain event — listener enqueues the real processing on webhook-inbound queue
        event(new OrderReceived($receipt->id, $receipt->correlation_id));

        return response()->json(['status' => 'accepted']);
    }
}
```

### <200ms dispatch budget (acceptance criterion 3)

The measured path is: middleware HMAC → 1 DB insert → 1 event dispatch → return. On local dev (SQLite in-memory) this is ~15ms; on production MySQL ~40-60ms. Event listener enqueues the real processing job on the `webhook-inbound` supervisor — that handoff is the 200ms clock-stop. Feature test asserts `$response->headers->get('X-Response-Time') < 200` (add a timing middleware in the test suite).

---

## Section 4 — Horizon supervisor config + Redis persistence (FOUND-09, FOUND-10)

### Seven queues (locked by phase success criterion 5)

| Queue | Purpose | Phase 1 Populator |
|-------|---------|-------------------|
| `critical` | User-facing fast work (rare — reserved for Phase 7 notification centre) | Empty in Phase 1 |
| `sync-woo-push` | Per-SKU Woo REST push jobs | Empty (Phase 2) |
| `sync-bulk` | Daily supplier sync, batch operations | Empty (Phase 2) |
| `crm-bitrix` | Deal/Contact/Company push | Empty (Phase 4) |
| `competitor-csv` | CSV ingest | Empty (Phase 5) |
| `webhook-inbound` | Post-HMAC webhook processing | Phase 1 — HandleWooOrderJob listens here |
| `default` | Everything else, including `ApplySuggestionJob` | Phase 1 — suggestion apply + retention prunes |

### `config/horizon.php` supervisor shape

```php
// config/horizon.php (excerpt — production environment block)
'environments' => [
    'production' => [
        'webhook-inbound-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['webhook-inbound'],
            'balance'             => 'simple',   // webhook work is uniform; simple split is fine
            'minProcesses'        => 3,
            'maxProcesses'        => 10,
            'balanceMaxShift'     => 1,
            'balanceCooldown'     => 3,
            'tries'               => 3,
            'timeout'             => 60,         // webhooks should be fast
            'memory'              => 128,
        ],
        'crm-bitrix-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['crm-bitrix'],
            'balance'             => 'simple',
            'minProcesses'        => 1,
            'maxProcesses'        => 2,          // Bitrix 2-req/sec ceiling — 2 workers is the hard cap
            'tries'               => 5,
            'timeout'             => 120,
            'memory'              => 256,
        ],
        'sync-woo-push-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['sync-woo-push'],
            'balance'             => 'auto',
            'minProcesses'        => 2,
            'maxProcesses'        => 3,          // Woo 100-req/min — ~1.6/sec, 3 workers is safe
            'tries'               => 5,
            'timeout'             => 90,
            'memory'              => 256,
        ],
        'sync-bulk-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['sync-bulk'],
            'balance'             => 'simple',
            'minProcesses'        => 1,
            'maxProcesses'        => 1,          // long-running; one chunk at a time
            'tries'               => 2,
            'timeout'             => 1800,        // 30 minutes per chunk
            'memory'              => 512,
        ],
        'competitor-csv-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['competitor-csv'],
            'balance'             => 'simple',
            'minProcesses'        => 1,
            'maxProcesses'        => 2,
            'tries'               => 3,
            'timeout'             => 600,
            'memory'              => 512,         // CSV parsing can spike
        ],
        'critical-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['critical'],
            'balance'             => 'simple',
            'minProcesses'        => 2,
            'maxProcesses'        => 5,
            'tries'               => 3,
            'timeout'             => 60,
            'memory'              => 128,
        ],
        'default-supervisor' => [
            'connection'          => 'redis',
            'queue'               => ['default'],
            'balance'             => 'auto',
            'minProcesses'        => 1,
            'maxProcesses'        => 3,
            'tries'               => 3,
            'timeout'             => 120,
            'memory'              => 256,
        ],
    ],
    'local' => [
        'all-in-one' => [
            'connection' => 'redis',
            'queue'      => ['critical', 'webhook-inbound', 'crm-bitrix', 'sync-woo-push', 'sync-bulk', 'competitor-csv', 'default'],
            'balance'    => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'tries'      => 1,
            'timeout'    => 300,
        ],
    ],
],
```

**Worker count rationale (Claude's Discretion resolved):**
- Woo: 100 req/min = 1.67 req/sec. Each push takes ~300-800ms. 3 workers × 2 req/sec ceiling per worker ≈ 6 req/sec theoretical; real throughput is ~2-3 req/sec accounting for round-trip. Well under the 100/min ceiling but enough to keep up with normal priceChange volume.
- Bitrix: 2 req/sec ceiling = HARD CAP. 2 workers running in tight loop would exceed it. Use 2 workers max with the SDK's built-in rate-limit awareness OR a `Sleep::until()` throttle in the job — BitrixClient adds this in Phase 4. For Phase 1 skeleton, 2 workers is the config; throughput doesn't matter because no real calls happen.
- webhook-inbound: 3-10 workers means a burst of webhook fan-out (e.g., Woo sending 20 events in 5 seconds during a promo) doesn't queue up. 10 is the ceiling to protect against runaway.

**`auto` vs `simple` decision:**
- `auto` for queues with variable workload (sync-woo-push, default) — Horizon scales between min/max based on pending depth
- `simple` for everything else — even process distribution across queues within the supervisor; appropriate for steady workloads or queues where rate limits are binding

**Source:** [CITED: laravel.com/docs/12.x/horizon] — balancing strategies documented on the Laravel 12.x Horizon page.

### Supervisor config on Linux VPS

```ini
; /etc/supervisor/conf.d/horizon.conf
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

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start horizon
```

On code deploy: `php artisan horizon:terminate` — Supervisor auto-restarts with the new code.

### Redis persistence (FOUND-10, Claude's Discretion resolved)

```
# /etc/redis/redis.conf (excerpt — production VPS)
appendonly yes
appendfsync everysec       # at-most 1s of lost writes on crash; ~10× throughput vs appendfsync always
appendfilename "appendonly.aof"
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb

# Critical for queues — NEVER evict job payloads
maxmemory 2gb              # size based on VPS; 2GB reserves for queue bursts
maxmemory-policy noeviction
```

**`noeviction` is not optional for queues.** [CITED: laravel-enlightn.com/docs/reliability/redis-eviction-policy-analyzer.html] — "if you are using Redis for queues or sessions, your Redis database needs to be persistent, which means that you should set your eviction policy to noeviction". Any other policy silently drops jobs when memory pressure hits.

**Separate Redis logical DBs** (STACK.md Known Friction Points):

```php
// config/database.php - redis block
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 0],
    'cache'   => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 1],
    'horizon' => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 2],
    'pulse'   => ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => 6379, 'database' => 3],
],
```

Separate DBs prevent `FLUSHDB` accidents from nuking queue state while clearing cache.

### HorizonServiceProvider — admin-role gate

```php
// app/Providers/HorizonServiceProvider.php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return $user->hasRole('admin');
    });
}
```

Enforces D-02 role scope: only `admin` can reach `/horizon`. The "Horizon link" in Filament nav (STACK.md §5) should check this gate.

### Acceptance test (Success Criterion 5)

- Start Horizon: `php artisan horizon`
- Open `/horizon` — all 7 supervisors show "running"
- Dispatch a deliberately-failing test job: `TestFailingJob::dispatch()->onQueue('default');`
- After `tries=3` failures, spatie/failed-job-monitor fires one email (§9)

---

## Section 5 — Audit log (spatie/activitylog) + Filament viewer (FOUND-04)

### Install + publish

```bash
composer require spatie/laravel-activitylog:"^4.12"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-config"
php artisan migrate

composer require rmsramos/activitylog:"^1.0"
# rmsramos auto-registers a Filament Resource — confirm in nav after panel cache clear
php artisan filament:cache-components
```

### `LogsActivity` trait pattern (Phase 1 template)

Phase 1 ships NO business models with `LogsActivity` (they arrive in Phase 2+), but it ships the **convention** that all future models MUST follow:

```php
// Example template — used as a reference, not applied to any Phase 1 model
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PricingRule extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['brand', 'category', 'margin_percent', 'is_active'])  // NEVER logAll() — table growth
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Pricing rule {$eventName}");
    }
}
```

### Correlation-ID threading via `LogBatch`

Spatie's batch-logging feature is purpose-built for correlation_id propagation:

```php
use Spatie\Activitylog\Facades\LogBatch;

// In a middleware or job handler
LogBatch::startBatch();
LogBatch::setBatch(Context::get('correlation_id'));

// ... any LogsActivity model writes within this scope share the batch UUID

LogBatch::endBatch();
```

**Source:** [CITED: spatie.be/docs/laravel-activitylog/v4/api/log-batch] — "setBatch() method sets a UUID for the current open batch, which can be used to keep the batch open throughout multiple requests or in a batch queue job."

**Phase 1 implementation:** wrap the HTTP request lifecycle in a LogBatch scope via a middleware. Also wrap every queued job handler. This ensures every activity_log row produced inside that boundary shares the correlation_id.

```php
// app/Http/Middleware/AttachCorrelationId.php — excerpt (full version in §10)
public function handle(Request $request, Closure $next): Response
{
    $correlationId = // ... (see §10)
    Context::add('correlation_id', $correlationId);

    LogBatch::startBatch();
    LogBatch::setBatch($correlationId);

    try {
        return $next($request);
    } finally {
        LogBatch::endBatch();
    }
}
```

### Retention prune (D-04, 365 days)

```php
// app/Console/Commands/PruneActivityLogCommand.php
class PruneActivityLogCommand extends Command
{
    protected $signature = 'activitylog:prune {--days=365}';

    public function handle(Auditor $auditor): int
    {
        $cutoff  = now()->subDays($this->option('days'));
        $deleted = Activity::where('created_at', '<', $cutoff)->delete();

        // Meta-audit: log the prune itself (D-09)
        $auditor->record('activitylog.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date'   => $cutoff->toIso8601String(),
        ]);

        $this->info("Pruned {$deleted} activity log rows older than {$this->option('days')} days.");
        return 0;
    }
}
```

Schedule in `routes/console.php`:

```php
Schedule::command('activitylog:prune --days=365')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onQueue('default');
```

**NOTE:** Spatie ships `activitylog:clean` out of the box. We can use that directly — but the D-09 requirement is "prune commands log counts to audit_log", which the built-in command does NOT do. The custom command above wraps the built-in logic AND adds the meta-audit write. Recommended: ship the custom command.

### rmsramos/activitylog Filament Resource

`rmsramos/activitylog` auto-discovers itself on install. The generated Resource sits at `/admin/activity-log` by default. Filter by: causer, subject type, subject ID, log name, date range. [CITED: laraveldaily.com/post/filament-activity-logs-three-packages-comparison-review].

**Phase 1 task:** after install, add a policy that gates visibility to `admin` role only (it would be a leak for `sales` to see all pricing-rule edits). Shield's `shield:generate` will produce the policy scaffold automatically — the Phase 1 seeder grants `view_any_activity` + `view_activity` to `admin` only.

---

## Section 6 — Integration events log (FOUND-05)

### Schema (not a package — custom table)

```php
// database/migrations/XXXX_XX_XX_create_integration_events_table.php
Schema::create('integration_events', function (Blueprint $t) {
    $t->id();
    $t->string('channel', 32);                        // 'woo' | 'bitrix' | 'supplier' | 'merchant_center'
    $t->string('direction', 8)->default('outbound');  // 'outbound' | 'inbound'
    $t->string('operation', 64);                      // 'product.update' | 'deal.create' | 'token.generate'
    $t->nullableMorphs('subject');                    // optional link to domain model
    $t->string('correlation_id', 36)->index();
    $t->string('endpoint', 255);
    $t->string('method', 8);                          // GET | POST | PUT | DELETE | PATCH
    $t->json('request_body')->nullable();
    $t->json('request_headers')->nullable();           // secrets REDACTED before write
    $t->json('response_body')->nullable();
    $t->integer('http_status')->nullable();
    $t->integer('latency_ms')->nullable();
    $t->tinyInteger('attempt')->default(1);
    $t->string('status', 12);                         // 'success' | 'failed' | 'retrying'
    $t->text('error_message')->nullable();
    $t->timestamp('created_at');

    $t->index(['channel', 'created_at']);
    $t->index(['status', 'created_at']);
});
```

Note: no `updated_at` — integration events are append-only.

### `IntegrationLogger` service (Phase 1 Foundation)

```php
// app/Foundation/Integration/Services/IntegrationLogger.php
final class IntegrationLogger
{
    public function log(array $data): IntegrationEvent
    {
        $defaults = [
            'correlation_id' => Context::get('correlation_id'),
            'direction'      => 'outbound',
            'attempt'        => 1,
            'created_at'     => now(),
        ];

        // Redact sensitive headers before persistence
        if (isset($data['request_headers'])) {
            $data['request_headers'] = $this->redactHeaders($data['request_headers']);
        }

        return IntegrationEvent::create(array_merge($defaults, $data));
    }

    private function redactHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'x-wc-webhook-signature', 'cookie', 'x-bitrix-signature'];
        return collect($headers)
            ->map(fn ($v, $k) => in_array(strtolower($k), $sensitive, true) ? ['***REDACTED***'] : $v)
            ->all();
    }
}
```

### `WooClient` write-through pattern (Phase 1 skeleton)

```php
// app/Domain/Sync/Services/WooClient.php — Phase 1 SKELETON
final class WooClient
{
    public function __construct(
        private IntegrationLogger $logger,
        private \Automattic\WooCommerce\Client $inner,  // lazy-bound — Phase 2 adds real config
    ) {}

    public function put(string $endpoint, array $payload): array
    {
        if (! config('services.woo.write_enabled', false)) {
            // Shadow mode — see §8
            return $this->recordDiff('PUT', $endpoint, $payload);
        }

        $start = microtime(true);
        try {
            $response = $this->inner->put($endpoint, $payload);
            $status   = 200;
            $body     = (array) $response;
            $error    = null;
        } catch (\Throwable $e) {
            $status = method_exists($e, 'getResponseCode') ? $e->getResponseCode() : 500;
            $body   = ['error' => $e->getMessage()];
            $error  = $e->getMessage();
        }
        $latency = (int) ((microtime(true) - $start) * 1000);

        $this->logger->log([
            'channel'        => 'woo',
            'operation'      => "PUT {$endpoint}",
            'endpoint'       => $endpoint,
            'method'         => 'PUT',
            'request_body'   => $payload,
            'response_body'  => $body,
            'http_status'    => $status,
            'latency_ms'     => $latency,
            'status'         => $error ? 'failed' : 'success',
            'error_message'  => $error,
        ]);

        if ($error) {
            throw new \RuntimeException("Woo PUT {$endpoint} failed: {$error}");
        }
        return $body;
    }

    // recordDiff() — see §8
}
```

**Guzzle middleware alternative:** Wrapping Guzzle at the middleware level is cleaner (capture ALL traffic, not just what client methods call). The `automattic/woocommerce` client accepts a Guzzle client via its `options` array. Phase 2 may switch to middleware if the explicit-wrap pattern feels repetitive; Phase 1 ships the explicit wrap because it's simpler to reason about for the acceptance test.

### Retention prune (D-05, 90 days)

Mirror pattern of §5. Scheduled daily at 03:10 (staggered off activitylog prune at 03:00 to spread load).

---

## Section 7 — Suggestions table + Filament inbox + stub applier (FOUND-06, D-14 to D-17)

### Migration (verbatim from D-14)

```php
// database/migrations/XXXX_XX_XX_create_suggestions_table.php
Schema::create('suggestions', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->string('kind', 64);                           // 'margin_change' | 'crm_push_failed' | 'new_product' | 'test'
    $t->string('status', 16)->default('pending');     // 'pending'|'approved'|'rejected'|'applied'|'failed'
    $t->string('correlation_id', 36)->index();
    $t->json('payload');                              // proposed change, kind-specific shape
    $t->json('evidence')->nullable();                 // source data that produced the suggestion
    $t->nullableMorphs('proposed_by');                // user_id OR producer class (agent, service)
    $t->timestamp('proposed_at');
    $t->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $t->timestamp('resolved_at')->nullable();
    $t->text('rejection_reason')->nullable();
    $t->timestamp('applied_at')->nullable();
    $t->timestamps();

    $t->index(['kind', 'status']);
    $t->index(['status', 'proposed_at']);
});
```

### `SuggestionApplier` contract

```php
// app/Domain/Suggestions/Contracts/SuggestionApplier.php
namespace App\Domain\Suggestions\Contracts;

use App\Domain\Suggestions\Models\Suggestion;

interface SuggestionApplier
{
    /** Which `kind` values this applier handles. */
    public function supports(): array;

    /**
     * Execute the change described by the suggestion.
     * Must be idempotent. Returns an array written to `applied_result` (future column; Phase 1 discards).
     *
     * @throws \Throwable on failure — job should catch, flip status to 'failed', surface in inbox
     */
    public function apply(Suggestion $suggestion): array;
}
```

### Registration pattern (container-resolved per `kind`)

```php
// app/Domain/Suggestions/Services/SuggestionApplierResolver.php
final class SuggestionApplierResolver
{
    /** @var array<string, string>  map of kind => applier class */
    private array $registry = [];

    public function register(string $kind, string $applierClass): void
    {
        $this->registry[$kind] = $applierClass;
    }

    public function resolve(Suggestion $suggestion): SuggestionApplier
    {
        $class = $this->registry[$suggestion->kind] ?? throw new \RuntimeException(
            "No SuggestionApplier registered for kind: {$suggestion->kind}"
        );
        return app($class);
    }
}
```

Registered in a service provider:

```php
// app/Providers/AppServiceProvider.php — boot()
$resolver = app(SuggestionApplierResolver::class);
$resolver->register('test', \App\Domain\Suggestions\Appliers\StubApplier::class);
// Phase 5 adds:
// $resolver->register('margin_change', \App\Domain\Competitor\Appliers\MarginChangeApplier::class);
```

### `StubApplier` (Phase 1 — required for success criterion 6)

```php
// app/Domain/Suggestions/Appliers/StubApplier.php
final class StubApplier implements SuggestionApplier
{
    public function supports(): array { return ['test']; }

    public function apply(Suggestion $suggestion): array
    {
        // No-op applier for Phase 1 test suggestion.
        // Does NOT touch external systems — just records that apply() was called.
        return [
            'applied_at'  => now()->toIso8601String(),
            'applier'     => self::class,
            'stub_result' => 'ok',
        ];
    }
}
```

### `ApplySuggestionJob` (D-15)

```php
// app/Domain/Suggestions/Jobs/ApplySuggestionJob.php
final class ApplySuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'default';
    public int $tries    = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $suggestionId) {}

    public function handle(SuggestionApplierResolver $resolver, IntegrationLogger $logger): void
    {
        $suggestion = Suggestion::findOrFail($this->suggestionId);

        // Idempotency guard (D-15)
        if ($suggestion->status === 'applied') {
            return;
        }

        // Restore correlation_id into Context so downstream writes thread it
        Context::add('correlation_id', $suggestion->correlation_id);

        try {
            $applier = $resolver->resolve($suggestion);
            $result  = $applier->apply($suggestion);

            $suggestion->update([
                'status'     => 'applied',
                'applied_at' => now(),
            ]);

            $logger->log([
                'channel'    => 'suggestions',
                'operation'  => "apply:{$suggestion->kind}",
                'endpoint'   => 'internal',
                'method'     => 'APPLY',
                'request_body'  => $suggestion->payload,
                'response_body' => $result,
                'http_status'   => 200,
                'status'        => 'success',
            ]);
        } catch (\Throwable $e) {
            $suggestion->update([
                'status'           => 'failed',
                'rejection_reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### Filament Resource scaffold

```php
// app/Domain/Suggestions/Filament/Resources/SuggestionResource.php — skeleton
class SuggestionResource extends Resource
{
    protected static ?string $model           = Suggestion::class;
    protected static ?string $navigationIcon  = 'heroicon-o-inbox';
    protected static ?string $navigationGroup = 'Review';
    protected static ?string $recordTitleAttribute = 'kind';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'pending'  => 'warning',
                    'approved' => 'primary',
                    'rejected' => 'danger',
                    'applied'  => 'success',
                    'failed'   => 'danger',
                }),
                TextColumn::make('correlation_id')->fontFamily('mono')->copyable()->limit(8),
                TextColumn::make('proposed_at')->dateTime()->sortable(),
            ])
            ->defaultSort('proposed_at', 'desc')
            ->filters([
                SelectFilter::make('kind'),
                SelectFilter::make('status'),
            ])
            ->actions([
                Action::make('approve')
                    ->requiresConfirmation()
                    ->visible(fn (Suggestion $r) => $r->status === 'pending')
                    ->action(function (Suggestion $record) {
                        $record->update([
                            'status'              => 'approved',
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at'         => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),
                Action::make('reject')
                    ->form([Textarea::make('rejection_reason')->required()])
                    ->visible(fn (Suggestion $r) => $r->status === 'pending')
                    ->action(function (Suggestion $record, array $data) {
                        $record->update([
                            'status'              => 'rejected',
                            'rejection_reason'    => $data['rejection_reason'],
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at'         => now(),
                        ]);
                    }),
            ]);
    }
    // Form + pages definitions follow standard Filament scaffold
}
```

### Seeded test suggestion (for success criterion 6)

```php
// database/seeders/TestSuggestionSeeder.php
class TestSuggestionSeeder extends Seeder
{
    public function run(): void
    {
        Suggestion::firstOrCreate(
            ['kind' => 'test'],
            [
                'status'         => 'pending',
                'correlation_id' => (string) Str::uuid(),
                'payload'        => ['message' => 'Phase 1 acceptance test suggestion'],
                'evidence'       => ['source' => 'seeder', 'created_at' => now()],
                'proposed_at'    => now(),
            ]
        );
    }
}
```

### `FeedGenerator` contract stub (FOUND-13)

```php
// app/Domain/Feeds/Contracts/FeedGenerator.php
namespace App\Domain\Feeds\Contracts;

/**
 * Phase 8 channel feeds implement this (Google Merchant, Meta Catalog, Amazon, etc.).
 * Phase 1 ships the empty contract so later phases slot in without refactor.
 */
interface FeedGenerator
{
    public function channel(): string;
    public function generate(): string;    // returns generated feed path or URL
    public function lastGeneratedAt(): ?\DateTimeImmutable;
}
```

---

## Section 8 — `WOO_WRITE_ENABLED` shadow-mode gate (FOUND-08)

### `sync_diffs` table schema

```php
// database/migrations/XXXX_XX_XX_create_sync_diffs_table.php
Schema::create('sync_diffs', function (Blueprint $t) {
    $t->id();
    $t->string('channel', 32)->default('woo');         // future-proof for other channels
    $t->string('method', 8);                           // PUT | POST | PATCH | DELETE
    $t->string('endpoint', 255);                       // e.g., 'products/1234'
    $t->string('woo_id', 64)->nullable()->index();     // extracted from endpoint when applicable
    $t->json('payload');                               // what WOULD have been sent
    $t->string('correlation_id', 36)->index();
    $t->timestamp('created_at');
    $t->timestamp('applied_at')->nullable();           // set when Phase 7 cutover replays
    $t->string('status', 16)->default('pending');     // 'pending' | 'applied' | 'superseded'
});
```

### Gate location — `WooClient::put/post/patch/delete`

Referring back to §6's `WooClient` sketch — the first line of every write method checks the flag:

```php
// app/Domain/Sync/Services/WooClient.php
public function put(string $endpoint, array $payload): array
{
    if (! config('services.woo.write_enabled', false)) {
        return $this->recordDiff('PUT', $endpoint, $payload);
    }
    // ... real call
}

private function recordDiff(string $method, string $endpoint, array $payload): array
{
    $diff = SyncDiff::create([
        'method'         => $method,
        'endpoint'       => $endpoint,
        'woo_id'         => $this->extractWooId($endpoint),  // e.g., "products/1234" -> "1234"
        'payload'        => $payload,
        'correlation_id' => Context::get('correlation_id'),
    ]);
    return ['shadow_mode' => true, 'diff_id' => $diff->id];
}
```

### `.env` config

```
# .env
WOO_WRITE_ENABLED=false  # DEFAULT — never set true until Phase 7 cutover
```

```php
// config/services.php
'woo' => [
    'url'              => env('WOO_URL'),
    'consumer_key'     => env('WOO_CONSUMER_KEY'),
    'consumer_secret'  => env('WOO_CONSUMER_SECRET'),
    'webhook_secret'   => env('WC_WEBHOOK_SECRET'),
    'write_enabled'    => env('WOO_WRITE_ENABLED', false),
],
```

### Feature test (Success Criterion 4)

```php
// tests/Feature/ShadowModeTest.php
it('records a sync_diff instead of calling Woo when WOO_WRITE_ENABLED=false', function () {
    config(['services.woo.write_enabled' => false]);

    $mockHttp = $this->spy(\Automattic\WooCommerce\Client::class);

    app(WooClient::class)->put('products/1234', ['regular_price' => '99.99']);

    expect(SyncDiff::count())->toBe(1);
    expect(SyncDiff::first())
        ->endpoint->toBe('products/1234')
        ->method->toBe('PUT')
        ->woo_id->toBe('1234');

    $mockHttp->shouldNotHaveReceived('put');
});

it('calls Woo when WOO_WRITE_ENABLED=true', function () {
    config(['services.woo.write_enabled' => true]);
    // ... assert call happened, no diff recorded
});
```

---

## Section 9 — Failed-job alerting (email only) (FOUND-11, D-10 to D-13)

### Install

```bash
composer require spatie/laravel-failed-job-monitor:"^4.0"
php artisan vendor:publish --provider="Spatie\FailedJobMonitor\FailedJobMonitorServiceProvider"
```

### Config — email only (D-10), disable Slack

```php
// config/failed-job-monitor.php
return [
    'notifiable' => \App\Domain\Alerting\Notifiables\AlertDistribution::class,
    'notification' => \Spatie\FailedJobMonitor\Notification::class,
    'channels' => ['mail'],  // ← removed 'slack'
    'mail' => [
        // 'to' is resolved dynamically via the AlertDistribution notifiable — leave empty
        'to' => [],
    ],
    'slack' => [
        'webhook_url' => null,  // explicit — never used
    ],
];
```

### `AlertRecipient` model + migration (D-12, ADDS PHASE 1 SCOPE)

```php
// database/migrations/XXXX_XX_XX_create_alert_recipients_table.php
Schema::create('alert_recipients', function (Blueprint $t) {
    $t->id();
    $t->string('email')->unique();
    $t->string('name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});
```

```php
// app/Domain/Alerting/Models/AlertRecipient.php
class AlertRecipient extends Model
{
    protected $fillable = ['email', 'name', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
```

### Dynamic notifiable — routes to all active AlertRecipients (D-11 single distribution)

```php
// app/Domain/Alerting/Notifiables/AlertDistribution.php
final class AlertDistribution
{
    use Illuminate\Notifications\Notifiable;

    public function routeNotificationForMail(): array
    {
        return AlertRecipient::where('is_active', true)
            ->pluck('name', 'email')  // returns ['email' => 'name']
            ->all();
    }

    // Explicitly prevent slack routing even if config drifts
    public function routeNotificationForSlack(): ?string
    {
        return null;
    }
}
```

### 5-minute dedup window (D-13)

spatie/laravel-failed-job-monitor does NOT provide built-in rolling-window dedup. Implementation: wrap the notification dispatch in a cache-key throttle.

```php
// app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php
final class ThrottledFailedJobNotifier
{
    public function handle(JobFailed $event): void
    {
        $signature = $this->fingerprint($event);
        $cacheKey  = "failed-job-alert:{$signature}";

        // If a matching alert went out in the last 5 minutes, skip
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, 1, now()->addMinutes(5));

        // Delegate to the package's default handler
        Notification::send(
            app(config('failed-job-monitor.notifiable')),
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

Register in EventServiceProvider:

```php
protected $listen = [
    \Illuminate\Queue\Events\JobFailed::class => [
        \App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier::class,
    ],
];
```

**Then disable spatie/failed-job-monitor's auto-listener** so we don't double-send: in `config/failed-job-monitor.php`, leave the config but the package's service provider auto-binds a listener to `JobFailed`. The cleanest way to suppress it is to override the notifiable resolution — but actually, a simpler approach:

**Alternative (simpler):** disable spatie's listener entirely and do everything in our custom listener:

```php
// config/failed-job-monitor.php
'notifiable' => null,  // suppress package's own dispatch
```

Then our listener in EventServiceProvider handles end-to-end. This is the recommended path for Phase 1.

### Filament `AlertRecipient` Resource

Standard Filament Resource — form has email (unique, validated), name, is_active toggle. Admin-only per Shield permissions set in the seeder (D-02: admin role gets all Resources).

### Acceptance test

Dispatch a deliberately-failing test job:

```php
// app/Console/Commands/TestFailingJobCommand.php
class TestFailingJobCommand extends Command
{
    protected $signature = 'alerts:test-failure';

    public function handle(): int
    {
        TestFailingJob::dispatch();
        $this->info('Test job dispatched. Check mail log + alert_recipients inboxes.');
        return 0;
    }
}

class TestFailingJob implements ShouldQueue
{
    public int $tries = 1;
    public function handle(): void { throw new \RuntimeException('Deliberate failure'); }
}
```

Run `php artisan alerts:test-failure` — after the job fails, a single email lands at each active recipient. Run it again within 5 minutes — no email (dedup).

---

## Section 10 — Correlation ID propagation (FOUND-03, Claude's Discretion)

### Middleware — entry point

```php
// app/Http/Middleware/AttachCorrelationId.php
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
        // Honour inbound header if present (Claude's Discretion decision)
        $correlationId = $request->header('X-Correlation-Id')
            ?? $request->header('X-Request-Id')
            ?? (string) Str::uuid();

        // Laravel 12 Context — automatically propagates to queued jobs
        Context::add('correlation_id', $correlationId);

        // Start spatie/activitylog batch with this UUID so audit rows thread
        LogBatch::startBatch();
        LogBatch::setBatch($correlationId);

        try {
            /** @var Response $response */
            $response = $next($request);

            // Emit header for downstream correlation
            $response->headers->set('X-Correlation-Id', $correlationId);

            return $response;
        } finally {
            LogBatch::endBatch();
        }
    }
}
```

Registered in `bootstrap/app.php` (see §3's `withMiddleware` snippet).

### Cross-queue propagation via `Context::dehydrating` / `::hydrated`

Laravel 12 automatically carries the `Context` payload across the queue boundary [CITED: laravel.com/docs/12.x/context]. No additional config needed for basic use. If we want to add defensive logging:

```php
// app/Providers/AppServiceProvider.php — boot()
use Illuminate\Log\Context\Repository;

Context::dehydrating(function (Repository $context) {
    Log::debug('Context dehydrating', ['correlation_id' => $context->get('correlation_id')]);
});

Context::hydrated(function (Repository $context) {
    Log::debug('Context hydrated', ['correlation_id' => $context->get('correlation_id')]);

    // Re-open LogBatch inside the queued job so audit rows threads the same correlation_id
    if ($cid = $context->get('correlation_id')) {
        LogBatch::setBatch($cid);
    }
});
```

### Artisan command + scheduled job entry point

Commands and scheduled jobs don't go through HTTP middleware. Wrap them in a base command:

```php
// app/Console/Commands/BaseCommand.php
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

### Downstream HTTP — propagate as `X-Correlation-Id`

Every outbound HTTP call includes the header. In the `WooClient` sketch (§6), before calling `$this->inner->put(...)`, add a Guzzle middleware that injects the header:

```php
// Guzzle middleware snippet (lives in WooClient constructor)
$handlerStack = HandlerStack::create();
$handlerStack->push(Middleware::mapRequest(function (RequestInterface $request) {
    $cid = Context::get('correlation_id');
    return $cid ? $request->withHeader('X-Correlation-Id', $cid) : $request;
}));
```

---

## Section 11 — Retention prune commands (FOUND-12, D-04 to D-09)

### Schedule registration — `routes/console.php`

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

// D-04: audit_log 365 days
Schedule::command('activitylog:prune --days=365')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onQueue('default');

// D-05: integration_events 90 days
Schedule::command('integration-events:prune --days=90')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onQueue('default');

// D-07: sync_errors 90 days
Schedule::command('sync-errors:prune --days=90')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->onQueue('default');

// D-08: sync_diffs — conditional
Schedule::command('sync-diffs:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onQueue('default');

// D-06: competitor files + csv_parse_errors — added in Phase 5
// (Phase 1 ships the schedule entry commented out with a TODO pointer)
```

### Conditional `sync_diffs` prune (D-08 special case)

```php
// app/Console/Commands/PruneSyncDiffsCommand.php
class PruneSyncDiffsCommand extends Command
{
    protected $signature = 'sync-diffs:prune';

    public function handle(Auditor $auditor): int
    {
        // D-08: NEVER prune while shadow mode is active
        if (! config('services.woo.write_enabled', false)) {
            $auditor->record('sync-diffs.prune.skipped', [
                'reason' => 'WOO_WRITE_ENABLED is false; diffs are parity evidence.',
            ]);
            $this->info('Skipped — WOO_WRITE_ENABLED=false.');
            return 0;
        }

        // Post-cutover: 30-day retention
        $cutoff  = now()->subDays(30);
        $deleted = SyncDiff::where('created_at', '<', $cutoff)
            ->whereNotNull('applied_at')  // keep un-applied diffs for investigation
            ->delete();

        $auditor->record('sync-diffs.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date'   => $cutoff->toIso8601String(),
        ]);

        $this->info("Pruned {$deleted} sync_diffs rows older than 30 days.");
        return 0;
    }
}
```

### Meta-audit via `Auditor` facade/service

All prune commands write to `audit_log` (D-09). `Auditor` is a thin Foundation service:

```php
// app/Foundation/Audit/Services/Auditor.php
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

### `withoutOverlapping` — mutex critical

`withoutOverlapping(30)` sets a 30-minute mutex so a long-running prune doesn't kick off a second instance. Without this, two daily runs overlapping on a slow DB can cause DELETE-on-DELETE lock contention.

---

## Section 12 — Project bootstrap sequence

### Order of operations (do not re-order)

```bash
# ── A. Laravel skeleton ──────────────────────────────────────────
composer create-project laravel/laravel:^12.0 meetingstore-ops
cd meetingstore-ops

# ── B. Core panels + RBAC (must be this order) ──────────────────
composer require filament/filament:"^3.3"
php artisan filament:install --panels

composer require spatie/laravel-permission:"^7.2"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

composer require bezhansalleh/filament-shield:"^3.3"
php artisan shield:install admin

# ── C. Audit + integration log tables ────────────────────────────
composer require spatie/laravel-activitylog:"^4.12"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-config"

composer require rmsramos/activitylog:"^1.0"

# ── D. Queue stack ──────────────────────────────────────────────
composer require laravel/horizon:"^5.45"
php artisan horizon:install
composer require spatie/laravel-failed-job-monitor:"^4.0"
php artisan vendor:publish --provider="Spatie\FailedJobMonitor\FailedJobMonitorServiceProvider"

# ── E. Monitoring ───────────────────────────────────────────────
composer require laravel/pulse:"^1.4"
php artisan pulse:install

composer require sentry/sentry-laravel:"^4.0"
php artisan sentry:publish

# ── F. Dev tooling ──────────────────────────────────────────────
composer require --dev pestphp/pest:"^3.0" pestphp/pest-plugin-laravel:"^3.0" \
    nunomaduro/larastan:"^3.0" laravel/pint:"^1.24" qossmic/deptrac-shim

# ── G. Custom migrations (order matters) ─────────────────────────
php artisan make:migration create_webhook_receipts_table
php artisan make:migration create_integration_events_table
php artisan make:migration create_suggestions_table
php artisan make:migration create_sync_diffs_table
php artisan make:migration create_alert_recipients_table

# ── H. Migrate ──────────────────────────────────────────────────
php artisan migrate

# ── I. Seeders ──────────────────────────────────────────────────
php artisan shield:generate --all --panel=admin
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=TestSuggestionSeeder
# Admin user created via php artisan make:filament-user
```

### Migration ordering (literal file-date order)

Because Laravel runs migrations in filename order, name them with sequential timestamps. Recommended order:

```
2026_04_18_100000_create_users_table.php                     (Laravel default)
2026_04_18_100001_create_password_reset_tokens_table.php     (default)
2026_04_18_100002_create_sessions_table.php                  (default)
2026_04_18_100003_create_cache_table.php                     (default)
2026_04_18_100004_create_jobs_table.php                      (default)
2026_04_18_101000_create_permission_tables.php               (spatie/permission vendor:publish)
2026_04_18_102000_create_activity_log_table.php              (spatie/activitylog vendor:publish)
2026_04_18_102001_add_event_column_to_activity_log.php       (spatie/activitylog follow-up)
2026_04_18_102002_add_batch_uuid_column_to_activity_log.php  (spatie/activitylog follow-up)
2026_04_18_103000_create_pulse_tables.php                    (laravel/pulse)
2026_04_18_104000_create_alert_recipients_table.php          (D-12 new)
2026_04_18_105000_create_webhook_receipts_table.php          (FOUND-07)
2026_04_18_106000_create_integration_events_table.php        (FOUND-05)
2026_04_18_107000_create_suggestions_table.php               (FOUND-06)
2026_04_18_108000_create_sync_diffs_table.php                (FOUND-08)
```

**Why this order:**
- Users before permission tables (pivot table FKs point at users)
- Permission tables before Shield seed (Shield queries them)
- Activity log before suggestions/alert_recipients (those tables trigger activity log writes on create)
- Alert recipients before webhook_receipts (no strict FK but alphabetised in admin UI)
- Sync_diffs last — only table that depends on `WOO_WRITE_ENABLED` config

### `.env.example` — required keys

```
APP_NAME=MeetingStoreOps
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://ops.meetingstore.co.uk

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

HORIZON_PREFIX=ops-horizon
HORIZON_DOMAIN=ops.meetingstore.co.uk

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@ops.meetingstore.co.uk
MAIL_FROM_NAME="MeetingStore Ops"

# Phase 1 — Woo write gate (MUST default to false)
WOO_WRITE_ENABLED=false

# Phase 1 — Webhook HMAC secret (populated at deploy)
WC_WEBHOOK_SECRET=

# Phase 2 adds these:
# WOO_URL=
# WOO_CONSUMER_KEY=
# WOO_CONSUMER_SECRET=
# SUPPLIER_API_URL=
# SUPPLIER_API_USER=
# SUPPLIER_API_PASS=

# Phase 4 adds these:
# BITRIX_WEBHOOK_URL=

SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
```

---

## Common Pitfalls

Tied to specific FOUND-* requirements per PITFALLS.md.

### Pitfall A — Raw body consumed before HMAC middleware (FOUND-07)
**What goes wrong:** Any middleware that calls `$request->all()`, `$request->json()`, or `$request->input()` BEFORE VerifyWooHmacSignature will cause `$request->getContent()` to return empty or re-encoded bytes. HMAC check fails on every webhook. Silent.
**Avoid:** Register VerifyWooHmacSignature as the FIRST middleware on the webhook route group. Never put a `web` middleware (StartSession, etc.) in front of webhook routes. Feature test asserts HMAC passes against a known-good fixture.

### Pitfall B — Shield permission names don't match seeder (FOUND-01, D-03)
**What goes wrong:** `shield:generate` produces names like `view_any_pricing::rule` (with `::`), but the seeder uses `view_any_pricing_rule` (with `_`). `syncPermissions()` silently ignores the mismatch. Permissions land on role, UI still gate-locks.
**Avoid:** Run `shield:generate` first on a throwaway DB, `php artisan permission:show` to copy the exact names, paste into the seeder. Integration test: after seeding, assert `$role->hasPermissionTo('view_any_pricing::rule')`.

### Pitfall C — `$fillable` missing on pivot tables (FOUND-01)
**What goes wrong:** `spatie/laravel-permission` pivot tables (`model_has_roles`, etc.) use morph columns. If `User` model has `$guarded = []` but model_type is not cast correctly, role assignments fail silently.
**Avoid:** Follow spatie docs — add `HasRoles` trait to User model and leave config defaults.

### Pitfall D — Tailwind 4 installed by default (FOUND-01)
**What goes wrong:** Laravel 12's `laravel/installer` may default to Tailwind 4 in its Breeze path. Filament 3 breaks with Tailwind 4.
**Avoid:** Do NOT install Breeze. Filament ships its own asset pipeline with Tailwind 3 pinned. Verify `package.json` shows `"tailwindcss": "^3.4"` after `filament:install --panels`.

### Pitfall E — Horizon queues populated on non-redis driver (FOUND-09)
**What goes wrong:** `.env` defaults `QUEUE_CONNECTION=sync` or `database`. Horizon doesn't manage those — failed jobs go somewhere else, supervisor config is silently ignored.
**Avoid:** Hard-code `QUEUE_CONNECTION=redis` in `.env.example` and add a boot-time assertion in `HorizonServiceProvider::boot()`: `throw_unless(config('queue.default') === 'redis', ...)`.

### Pitfall F — Redis `appendfsync always` kills throughput (FOUND-10)
**What goes wrong:** Copy-pasting "maximum durability" config from a Redis tutorial puts `appendfsync always` in production. Each write becomes a synchronous fsync — ~10× slower. Webhook processing SLAs fail on any burst.
**Avoid:** Use `appendfsync everysec` (the Redis-recommended default). Verify with `redis-cli CONFIG GET appendfsync` after install.

### Pitfall G — Redis eviction policy = `allkeys-lru` (FOUND-09, FOUND-10)
**What goes wrong:** Default Redis install ships with `maxmemory-policy noeviction` on 7.x but some hosted Redis (ElastiCache, DO managed) default to `allkeys-lru`. Queue payloads evicted under memory pressure — jobs vanish.
**Avoid:** Explicit `maxmemory-policy noeviction` in production Redis config. Per Laravel Enlightn: "NEVER use any policy other than noeviction for queues."

### Pitfall H — `automattic/woocommerce` default 10s timeout (FOUND-08)
**What goes wrong:** The Automattic client defaults to 10s HTTP timeout, no retry. A slow Woo response times out, the shadow-mode gate branches to the error handler instead of `recordDiff()`. Sync_diffs stay empty despite the flag being off.
**Avoid:** Construct Automattic client with `options: ['timeout' => 30]` in the WooClient constructor. Configure Guzzle retry middleware (3x with exponential backoff on 429/5xx). Phase 1 skeleton ships the timeout config even though no real calls happen.

### Pitfall I — Deptrac false-positives on container bindings (FOUND-02)
**What goes wrong:** Using `app(ClassFromOtherModule::class)` bypasses Deptrac — string-based resolution isn't tracked. Teams write tests that fail on constructor injection (caught) but pass on `app()` calls (not caught). Boundary quietly violated.
**Avoid:** Convention: always constructor-inject across module boundaries. Enforced by code review; Phase 1 task includes a CONVENTIONS.md entry.

### Pitfall J — Correlation ID doesn't survive failed jobs (FOUND-03)
**What goes wrong:** Laravel 12's Context dehydrate/hydrate works for successful job runs, but for a `JobFailed` listener the hydration may have completed before the listener fires (or the Context may be cleared after exception). Failed-job alerts may lack correlation_id.
**Avoid:** Capture `$event->job->payload()` inside the ThrottledFailedJobNotifier listener — the payload contains the dehydrated context. Extract `correlation_id` from there explicitly. Write a feature test: fail a job, assert `Mail::fake()->assertSent(...)` captures the correlation_id in the rendered email body.

### Pitfall K — Suggestions Resource reachable without Shield gate (FOUND-06, D-17)
**What goes wrong:** Stub Policy without proper Shield wiring = every authenticated user sees the inbox. `sales` role should not see `margin_change` suggestions (privacy/scope leak).
**Avoid:** After `shield:generate`, the seeder explicitly assigns `view_any_suggestion` + `view_suggestion` to `admin` only (not to `sales` or `pricing_manager`). Phase 1 acceptance: log in as `sales` role user, confirm `/admin/suggestions` returns 403.

### Pitfall L — `sync_diffs` prune runs while shadow mode active (FOUND-12, D-08)
**What goes wrong:** Forgotten or reversed conditional check in `PruneSyncDiffsCommand`. The parity evidence for Phase 7 cutover vanishes.
**Avoid:** Feature test: `config(['services.woo.write_enabled' => false])`, seed 100-day-old SyncDiff, run command, assert row still exists + assert audit_log has "prune.skipped" entry.

### Pitfall M — AlertRecipient empty on first deploy (FOUND-11, D-12)
**What goes wrong:** First production failure occurs before an admin has opened the Filament AlertRecipient Resource and added anyone. Notification dispatch loops over an empty array → no email sent → silent outage.
**Avoid:** Seed one fallback recipient (ops@company.com) in production seeder. Add a Pulse card or home-dashboard widget showing "0 active alert recipients" as a warning state.

---

## Code Examples

All code blocks above are the canonical patterns. Re-listing the small-but-critical ones:

### Context-aware domain event (FOUND-03)
```php
// app/Foundation/Events/DomainEvent.php
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

### HMAC verify (FOUND-07)
```php
$expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
abort_unless(hash_equals($expected, $signature), 401);
```

### Shadow-mode gate (FOUND-08)
```php
if (! config('services.woo.write_enabled', false)) {
    return $this->recordDiff($method, $endpoint, $payload);
}
```

### Spatie activitylog batch for correlation threading (FOUND-04)
```php
LogBatch::startBatch();
LogBatch::setBatch(Context::get('correlation_id'));
// ... writes
LogBatch::endBatch();
```

---

## State of the Art

| Old Approach | Current (2026) Approach | When Changed | Impact |
|--------------|-------------------------|--------------|--------|
| Laravel 10/11 session-based request ID | Laravel 11/12 `Context` facade | Laravel 11.x (2024) | `Context` crosses queue boundary automatically via dehydrate/hydrate — no more payload field stuffing |
| `Z3d0X/filament-logger` for audit UI | `rmsramos/activitylog` (Filament 3 viewer over spatie/activitylog) | 2024-2025 | Z3d0X unmaintained since mid-2024; rmsramos is the active replacement |
| Saloon for any external API wrap | Thin service class over native SDK (WooCommerce, Bitrix) | 2024-onward for small-scope apps | Saloon pays off at 4+ APIs; v1 has 3 — skip the abstraction |
| Filament 2 with manual policies | Filament 3 + Shield auto-generate | Filament 3.0 (2023) | `shield:generate` produces per-Resource permissions; eliminates policy boilerplate |
| `maatwebsite/laravel-excel` for CSV | `spatie/simple-excel` | 2023-onward | Generator-based streams; constant memory for large files |
| Predis for Redis client | phpredis (PECL extension) | Ongoing | ~2× queue throughput on dedicated VPS |

**Deprecated/outdated:**
- Filament v4 — released Aug 2025 but plugin matrix still diverging from v3; STACK.md commits to v3.3 for v1
- Tailwind CSS v4 — incompatible with Filament 3 completely
- Laravel `queue:listen` — replaced by Horizon in production; only acceptable locally

---

## Validation Architecture

`workflow.nyquist_validation` is explicitly `false` in `.planning/config.json`. **Per agent instructions, this section can be skipped.** Phase 1 verification relies on the 6 Success Criteria from ROADMAP.md, each backed by a single feature test (listed below) — Pest 3 on PHPUnit 11.

### Phase 1 Success Criterion → Test Map

| Criterion | Test | Automated Command |
|-----------|------|-------------------|
| 1. Admin logs into panel, sees role-gated nav | `tests/Feature/RoleGatedNavigationTest.php` | `vendor/bin/pest --filter=RoleGatedNavigation` |
| 2. Deptrac CI fails on cross-domain import | `tests/Architecture/DeptracTest.php` (or CI step) | `vendor/bin/deptrac analyse --fail-on-uncovered` |
| 3. Signed Woo webhook → row in webhook_receipts + queued handler < 200ms | `tests/Feature/WooWebhookTest.php` | `vendor/bin/pest --filter=WooWebhook` |
| 4. WOO_WRITE_ENABLED=false routes WooClient::put to sync_diffs | `tests/Feature/ShadowModeTest.php` | `vendor/bin/pest --filter=ShadowMode` |
| 5. 7 Horizon queues visible; failed job triggers email | `tests/Feature/HorizonSupervisorTest.php` + `tests/Feature/FailedJobAlertTest.php` | `vendor/bin/pest --filter=Horizon\\|FailedJobAlert` |
| 6. Suggestions inbox reachable + seeded suggestion approve/reject works | `tests/Feature/SuggestionInboxTest.php` | `vendor/bin/pest --filter=SuggestionInbox` |

### Test infrastructure (Phase 1 Wave 0 gaps)

- `phpunit.xml` — auto-generated by Laravel installer; ensure `<testsuite name="Feature">` includes `tests/Feature/*`
- `tests/Pest.php` — auto-generated; extend with `uses(TestCase::class, RefreshDatabase::class)->in('Feature')`
- `tests/Architecture/DeptracTest.php` — new; shells out to `vendor/bin/deptrac` and asserts exit code 0

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `spatie/permission` integer IDs work unchanged with Shield 3.3 on Laravel 12 | §1 | LOW — fallback to UUID IDs is documented in spatie docs; one-migration swap |
| A2 | spatie/laravel-failed-job-monitor v4 doesn't have a built-in 5-min dedup window | §9 | LOW — confirmed via package README and v4 config file; custom throttle is the standard Laravel pattern |
| A3 | `automattic/woocommerce` client accepts custom Guzzle client for middleware injection | §10 | MEDIUM — README shows `options` array support; if the client hard-builds Guzzle internally, Phase 2 may need to move correlation-id injection to an explicit request wrapper. Verify during Phase 2 planning. |
| A4 | Woo's `X-WC-Webhook-Delivery-ID` header is always present and stable across retries | §3 | LOW — confirmed by Hookdeck guide and Woo source; header has been stable since Woo 2.1 |
| A5 | The chosen worker counts (2 for Bitrix, 3 for Woo) are correct given the documented rate limits | §4 | LOW — math is conservative; Phase 2/4 real-traffic testing can tune upward |
| A6 | Laravel 12's Context facade dehydrate/hydrate works across Horizon's Redis job boundary without additional config | §10 | LOW — official docs state it works; Horizon uses the standard queue boundary |
| A7 | The exact Shield permission naming format (`view_any_pricing::rule` vs `view_any_pricing_rule`) is predictable enough to hard-code in the seeder | §1, Pitfall B | MEDIUM — documented risk; mitigation is to run `shield:generate` first and copy real names |

**A3 and A7 should be validated early in execution** — A3 can be checked in 10 minutes by skimming the Automattic client source; A7 requires a one-command dry run. Both cheap to de-risk.

---

## Open Questions (RESOLVED)

None for Phase 1 planner. All Claude's-Discretion items from CONTEXT.md are resolved above. The 7 open questions from FEATURES.md (rounding rule, supplier image availability, MAP brands, UTM capture mechanism, retention windows, user roles, webhook-delivery SLA) are either:

- Resolved by CONTEXT.md (retention windows → D-04..D-08; user roles → D-02)
- Deferred to Phase 2-6 discuss-phase sessions (rounding, supplier images, MAP brands, UTM capture)
- Not applicable to Phase 1 (webhook-delivery SLA — Phase 7 concern; Phase 1 just ships the plumbing)

**If the planner hits a decision not covered here, it is (a) outside Phase 1 scope per CONTEXT.md `<deferred>`, or (b) a net-new question that should pause planning and return to the user.**

---

## Sources

### Primary (HIGH confidence)

- `.planning/research/STACK.md` — all package pinning, versions, compatibility matrix
- `.planning/research/ARCHITECTURE.md` — module boundary design, 5 patterns, domain event catalogue
- `.planning/research/PITFALLS.md` — 23 pitfalls with phase mapping
- `.planning/research/FEATURES.md` — per-module feature research
- `.planning/research/SUMMARY.md` — gaps-to-address list
- `.planning/phases/01-foundation/01-CONTEXT.md` — user decisions D-01 through D-17
- [Laravel 12 docs — Context](https://laravel.com/docs/12.x/context) — dehydrate/hydrate across queue boundary
- [Laravel 12 docs — Horizon](https://laravel.com/docs/12.x/horizon) — supervisor config, balance strategies
- [Filament 3.x docs](https://filamentphp.com/docs/3.x/panels/installation) — panel installation, Resources, navigation
- [bezhansalleh/filament-shield README (3.x)](https://github.com/bezhanSalleh/filament-shield/blob/3.x/README.md) — shield:install, shield:generate, permission naming
- [spatie/laravel-activitylog — Log Batch docs](https://spatie.be/docs/laravel-activitylog/v4/api/log-batch) — setBatch() for correlation_id threading
- [qossmic/deptrac](https://github.com/qossmic/deptrac) — module boundary enforcement tool
- [WooCommerce WC_Webhook code reference](https://woocommerce.github.io/code-reference/classes/WC-Webhook.html) — HMAC signing implementation

### Secondary (MEDIUM confidence — verified with official)

- [Hookdeck — WooCommerce Webhooks Guide](https://hookdeck.com/webhooks/platforms/guide-to-woocommerce-webhooks-features-and-best-practices) — delivery-id retry behaviour, HMAC format
- [avosalmon/modular-monolith-laravel — deptrac.yaml](https://github.com/avosalmon/modular-monolith-laravel/blob/main/deptrac.yaml) — canonical ruleset example from Laracon Online 2022
- [Laravel Enlightn — Redis Eviction Policy](https://www.laravel-enlightn.com/docs/reliability/redis-eviction-policy-analyzer.html) — noeviction requirement for queues
- [LaravelDaily — Filament Activity Logs packages](https://laraveldaily.com/post/filament-activity-logs-three-packages-comparison-review) — rmsramos vs pxlrbt vs Z3d0X comparison

### Tertiary (LOW confidence — flagged for validation during execution)

- None — all critical claims verified above or resolved via project-level research artifacts.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — already pinned by STACK.md with recent publish dates
- Architecture: HIGH — ARCHITECTURE.md is the canonical source; this research specifies Phase 1 wiring only
- Pitfalls: HIGH — 13 pitfalls mapped to FOUND-* requirements, grounded in PITFALLS.md's 23-item catalogue plus Phase-1-specific additions (A, D, F, G, M)
- Implementation specifics (§1-§12): HIGH for 10 sections; MEDIUM on §9 (5-minute dedup window — custom pattern, not package-provided) and §1 Shield permission naming (Pitfall B mitigation is "run generator first")

**Research date:** 2026-04-18
**Valid until:** 2026-05-18 (30-day horizon — Laravel/Filament/Horizon release cadence is slow enough; re-validate if Phase 1 execution slips past that)
