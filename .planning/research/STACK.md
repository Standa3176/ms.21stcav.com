# Stack Research

**Domain:** Laravel 12 + Filament 3 ops / middleware application — supplier sync, e-commerce pricing, competitor intelligence, CRM push, scheduled & queued work
**Researched:** 2026-04-18
**Overall confidence:** HIGH (core stack verified via Packagist + official docs; Bitrix24 SDK choice is the only MEDIUM-confidence item)

---

## Executive Opinion (TL;DR)

| Concern | Pick | One-line rationale |
|---|---|---|
| Framework | `laravel/framework` ^12.0 | Already decided. Horizon, Pulse, Telescope, Filament 3 all compatible. |
| Admin UI | `filament/filament` ^3.3 | Decided. Stay on v3; do **not** upgrade to v4 mid-build — see Filament v4 note below. |
| Queues | `laravel/horizon` ^5.45 | Decided. Use **phpredis** (not Predis) on same-VPS install. |
| Woo REST | `automattic/woocommerce` ^3.1 | Official Automattic client. Thin Guzzle wrapper; all we need. No Laravel-specific wrapper. |
| Woo webhooks | Custom middleware — no package | HMAC-SHA256 + base64 on raw body; too simple to pull in a dependency. |
| Bitrix24 CRM | `bitrix24/b24phpsdk` ^1.x (official) | Only officially-supported, inbound-webhook-auth PHP SDK. Vendor-origin caveat flagged below. |
| Roles / perms | `spatie/laravel-permission` ^6.0 + `bezhansalleh/filament-shield` ^3.3 | The Filament-world standard for RBAC. *(April 2026 verified: v7.x not yet Filament 3.3-compatible; resolver picks v6 floor.)* |
| Audit log | `spatie/laravel-activitylog` ^4.12 + `rmsramos/activitylog` ^2.0 (Filament viewer) | Purpose-built for "audit everything" constraint. **Not** event-sourcing. *(rmsramos v2 required for Filament 3.3 compat.)* |
| CSV ingest | `spatie/simple-excel` ^3.9 | Generator-based, constant memory; right fit for n8n drops. |
| Charts | Filament built-in (Chart.js) — fall back to `leandrocfe/filament-apex-charts` if we hit limits | Keep vanilla; only add ApexCharts if dashboards demand it. |
| Testing | Pest 3 + PHPUnit 11 (both) | Pest as primary; PHPUnit still runs since Pest sits on top. |
| Errors | `sentry/sentry-laravel` | Laravel's official preferred; already familiar to the ecosystem. |
| Production monitoring | `laravel/pulse` (prod) + `laravel/telescope` (local only) | Pulse for prod health, Telescope never in prod. |
| HTTP out | Built-in `Http::` facade (Guzzle under the hood) | No Saloon needed — scope is tiny (3 external APIs). |

---

## Recommended Stack

### Core Technologies

| Technology | Version (April 2026) | Purpose | Why Recommended |
|---|---|---|---|
| PHP | ^8.2 (tested on 8.3) | Runtime | Matches Laravel 12 minimum; 8.2 is the floor for every package below. 8.3 has faster readonly classes and typed const support. |
| Laravel | ^12.0 | Framework | User decision. Feb 2025 LTS-adjacent release, maintenance-only changes from 11, and every package we need supports `^12.0`. |
| Filament | ^3.3 (latest 3.x) | Admin panel | User decision. v3.3 shipped Laravel 12 support; plugin ecosystem (Shield, activitylog viewers, ApexCharts) is mature on 3.x. See the v4 caveat in "What NOT to Use". |
| Livewire | ^3.5 (pulled in by Filament 3) | Reactive UI | Do not install separately; Filament 3 pins the version it needs. |
| Tailwind CSS | ^3.4 | Styling | **Filament 3 requires Tailwind v3**; v4 has breaking changes and is only supported by Filament v4. |
| MySQL | 8.0+ | Primary DB | Matches existing 21CAV infra; separate DB from Woo per PROJECT.md. |
| Redis | 7.x (or Valkey 7.x drop-in) | Queues + Pulse + cache | Required by Horizon. Same VPS; no cross-network latency. |
| Laravel Horizon | ^5.45 | Queue dashboard + supervision | User decision. Supervises the supplier sync, Bitrix push, CSV import, and product-create queues. |
| Laravel Pulse | ^1.4 | Prod monitoring | Dashboard for slow queries, queue throughput, exception rate. Redis-backed so no DB bloat. |

### Supporting Libraries — Integration Concerns

| Library | Version | Purpose | When / Why |
|---|---|---|---|
| `automattic/woocommerce` | ^3.1 (3.1.1 added PHP 8.5 support Jan 2026) | Woo REST client | Official Automattic library. Thin wrapper over Guzzle with WP HMAC signing for HTTP (non-HTTPS) endpoints. No stale dependencies. **Do not** wrap it in a Laravel "helper package" — write our own `WooClient` service. |
| `bitrix24/b24phpsdk` | ^1.x (PHP 8.2–8.4 production line) | Bitrix24 REST | **Official** Bitrix-org SDK, supports inbound-webhook auth (our auth mode), has typed responses and generator-based bulk ops. MEDIUM confidence only because we haven't shipped against it; `mesilov/bitrix24-php-sdk` is the veteran community fallback if we hit sharp edges. |
| `spatie/laravel-permission` | ^6.0 | RBAC database + traits | Industry standard. Open Items flagged "admin only, or separate sales/ops roles?" — this package makes either trivial. *(April 2026 verified — v7.x not yet resolvable alongside Filament 3.3; v6 is the resolver floor. Tracked in 01-01-SUMMARY deviations.)* |
| `bezhansalleh/filament-shield` | ^3.3 (keep on 3.x while Filament is 3.x) | Filament × Spatie Permission glue | Generates per-resource/page/widget permissions automatically; saves weeks of boilerplate. **Must match Filament major version** — do not install v4.x on Filament 3. |
| `spatie/laravel-activitylog` | ^4.12 | Model-change audit log | Project constraint: "Audit everything." Drops `activity_log` table, auto-logs model events via `LogsActivity` trait. |
| `rmsramos/activitylog` | ^2.0 | Filament viewer for Spatie activitylog | Read-only Filament Resource showing activity log entries with relationship managers. Actively maintained; chosen over `Z3d0X/filament-logger` (unmaintained) and `pxlrbt/filament-activity-log` (thinner UI). *(v2.x required for Filament 3.3 compat — April 2026 verified.)* |
| `spatie/simple-excel` | ^3.9 | CSV read/write with generators | Handles the n8n competitor CSV drops with constant memory. Chosen over `maatwebsite/laravel-excel` (heavy, import/export abstractions we don't need) and `league/csv` (lower-level; we'd re-implement the generator layer ourselves). |
| `sentry/sentry-laravel` | ^4.x | Error tracking | Laravel's officially-endorsed error tracker. Captures queue-job failures, HTTP-client errors to Woo/Bitrix/21stcav. |
| `laravel/pulse` | ^1.4 | Lightweight prod metrics | Redis storage = minimal overhead. Queue throughput, slow queries, exceptions — perfect for a sync-heavy app. |
| `laravel/telescope` | ^5.x | Deep local/staging debugging | **Local and staging only.** Explicitly disabled in production (see "What NOT to Use"). |
| `laravel/tinker` | (bundled with 12) | REPL | Included. |
| `pestphp/pest` + `pestphp/pest-plugin-laravel` | ^3.x | Testing DSL | User decision. Built on PHPUnit 11 — no rewrite needed for any PHPUnit tests. |
| `mockery/mockery` | ^1.6 | Test doubles | Standard. |
| `fakerphp/faker` | ^1.23 | Test data | Standard. |
| `laravel/pint` | ^1.24 | PSR-12 formatter | Matches existing 21CAV convention. |
| `nunomaduro/larastan` | ^3.x (phpstan level 6+) | Static analysis | Strongly recommended for this app — three external APIs with strong contracts, static types catch issues before prod. |
| `barryvdh/laravel-debugbar` | ^3.x (dev only) | Dev feedback loop | Optional; handy when building Filament pages. |

### Development Tools

| Tool | Purpose | Notes |
|---|---|---|
| Laravel Sail (optional) | Docker dev env | Matches existing 21CAV Rams2 setup. Not needed if developing natively on Windows with Herd/Valet. |
| Laravel Pail | Real-time log tail | Matches 21CAV convention (`composer dev` script). |
| Concurrently | Parallel dev processes | Used by the standard `composer dev` script to run serve + queue + vite + pail together. |
| Vite + Tailwind 3 | Asset pipeline | Already defined by Filament 3 installer. |
| Supervisor (prod) | Horizon supervision on VPS | Non-negotiable for queue reliability on a persistent VPS. Config goes in `/etc/supervisor/conf.d/horizon.conf`. |
| Cron (prod) | Laravel scheduler entry | Single `* * * * * php artisan schedule:run` line on the VPS. Per-job schedule lives in `routes/console.php`. |

---

## Stack by Problem (Requirement Mapping)

### 1. Sync to/from WooCommerce REST API reliably and at scale

- **Primary:** `automattic/woocommerce` ^3.1 (official WP/Automattic client)
- **Wrapping pattern:** Build our own `App\Services\Woo\WooClient` singleton that takes the Automattic client as a constructor dep. Reasons: testability (mock the inner client in tests), easy retry/backoff layer, logging interception for the audit requirement, and single place to handle `_exclude_from_auto_update` meta.
- **Retry:** Use Laravel's built-in `Http::retry()` pattern in the wrapper, OR rely on `automattic/woocommerce`'s Guzzle stack with exponential-backoff middleware. Do not pull in Saloon for a single API.
- **Scale trick:** Woo REST has a `?per_page=100` cap. Build a `WooProductIterator` that paginates and yields. Every chunk dispatches a `SyncProductChunkJob` to Horizon so a single VPS restart doesn't lose state — this directly satisfies "chunked/resumable sync with last-processed-ID tracking."

**NOT recommended:**
- `guidemaster/laravel-woocommerce` / `neimarperez/laravel-woocommerce` — thin "Facade" wrappers that add almost no value and couple us to a low-usage maintainer.
- Direct `wp-cli` or WP DB writes — explicitly forbidden by the project constraint "Woo API REST only, never direct WP DB writes."

Sources: [automattic/woocommerce on Packagist](https://packagist.org/packages/automattic/woocommerce), [woocommerce/wc-api-php GitHub](https://github.com/woocommerce/wc-api-php).

### 2. Call Bitrix24 CRM REST (no official-SDK assumption has changed)

- **Primary:** `bitrix24/b24phpsdk` ^1.x — **Bitrix itself now ships an official PHP SDK**. This contradicts the project brief's assumption of "no official SDK." Released on the `bitrix24` org on GitHub; line v1 is stable for PHP 8.2–8.4. Supports inbound-webhook auth (the project's auth mode), batch calls, generator-based iteration, and full CRM fields API coverage — which we need for the dynamic field-mapping requirement (E.).
- **Fallback:** `mesilov/bitrix24-php-sdk` ^2.x — the long-standing community SDK, battle-tested since 2013, also supports webhook auth. Use this if the official SDK proves buggy or stale on our version pin.
- **Vendor-origin note:** Bitrix24 is a Russian-origin platform, but **using the CRM via its own SDK is out of scope of the sanctions issue** flagged in the brief — that issue concerns the `itgalaxycompany` WordPress plugin (a third-party integrator, not Bitrix itself) which the business cannot purchase updates for. The CRM connection itself is the existing, lawful business relationship we're preserving.
- **Wrapping pattern:** Same as Woo — `App\Services\Bitrix\BitrixClient` with inbound-webhook URL from config. Typed methods: `createDeal()`, `createContact()`, `createCompany()`, `getDealFields()`, etc. Every call wraps in a `CrmPushLog` model write (request + response + retry attempts) to satisfy "Audit log of every CRM push attempt."

**NOT recommended:**
- Any `itgalaxycompany/*`, `integrationhub*`, or "bitrix-connector" WordPress plugin repackaged as Composer — avoid for the same compliance reason that motivated this rebuild.
- `citrus-soft/bitrix24-php-sdk`, `chicuza/bitrix24-php-sdk`, `dezio/bitrix24-php-sdk` — low-activity forks. Stick with the two mainstream options above.
- Rolling our own raw-HTTP client — Bitrix's field-discovery methods are complex enough that the SDK saves real time.

Sources: [bitrix24/b24phpsdk GitHub](https://github.com/bitrix24/b24phpsdk), [mesilov/bitrix24-php-sdk](https://github.com/mesilov/bitrix24-php-sdk), [Bitrix24 SDK docs](https://apidocs.bitrix24.com/sdk/index.html).

### 3. Scheduled + queued jobs (Horizon)

- **Primary:** `laravel/horizon` ^5.45 on Redis 7.x via the **phpredis** PHP extension (not Predis).
  - phpredis is a compiled extension, meaningfully faster for queue workers processing thousands of jobs/day; install via `pecl install redis`.
  - We control the VPS — there's no shared-hosting excuse for Predis here.
- **Supervision:** Supervisor on the VPS runs `php artisan horizon` with `autorestart=true`. Horizon internally spawns/retires workers based on `config/horizon.php` balancing strategy — use `auto` balance for this app since the four queue shapes (sync, crm-push, csv-ingest, product-create) have very different burst profiles.
- **Scheduling:** Native Laravel scheduler (`routes/console.php` in Laravel 12). Single cron entry `* * * * * php artisan schedule:run`. Daily supplier sync is a scheduled command that dispatches to Horizon.
- **Queue separation:** Define 4 named Redis queues: `sync`, `crm`, `csv`, `woo-writes`. Horizon's `balanceMaxShift` ensures one slow Bitrix push doesn't starve the supplier sync.

**NOT recommended:**
- Database queue driver — would work but lose Horizon's monitoring value.
- SQS / Amazon — unnecessary complexity for a single-VPS deployment; changes only our "phpredis" dependency to "SQS client" with no upside.
- Laravel 12's new `queue:work --queue=default --max-jobs=500` solo-mode pattern — fine for tiny apps but Horizon is what we want for observability.

Sources: [Laravel Horizon docs 12.x](https://laravel.com/docs/12.x/horizon), [laravel/horizon Packagist](https://packagist.org/packages/laravel/horizon), [Laravel 12 Redis docs](https://laravel.com/docs/12.x/redis).

### 4. Inbound HMAC-signed webhooks from Woo

- **Primary:** **Write our own middleware — no package needed.** The signature algorithm is simple and the surface area is ~30 lines.
  - WooCommerce sends `X-WC-Webhook-Signature` header: `base64_encode(hash_hmac('sha256', $rawBody, $secret, true))`.
  - Middleware reads raw request body (Laravel 12: `$request->getContent()`), computes expected signature, uses `hash_equals()` for timing-safe comparison.
  - Also validate `X-WC-Webhook-Topic` and `X-WC-Webhook-Source` to short-circuit events we don't care about.
  - Store the HMAC secret in `.env` as `WC_WEBHOOK_SECRET` — Open Item in PROJECT.md ("HMAC secret management") resolves here.
- **Route pattern:** `POST /webhooks/woocommerce/{topic}` hitting a single `WooWebhookController` that dispatches a typed job per topic (e.g., `HandleOrderCreatedJob`).

**NOT recommended:**
- `spatie/laravel-webhook-client` — overkill and opinionated about model structure; you spend as much time fighting its assumptions as writing the 30-line middleware.
- `cline/webhook` — new and low-adoption; no sense taking on a dependency for code we can audit in 5 minutes.
- Inline HMAC in controllers — breaks the "thin controller" pattern and makes testing harder.

Sources: [Laravel webhook implementation guide 2026](https://oneuptime.com/blog/post/2026-02-02-laravel-webhooks-implementation/view), [WooCommerce webhook signature details](https://hookdeck.com/webhooks/platforms/guide-to-woocommerce-webhooks-features-and-best-practices).

### 5. Filament admin dashboard — tables, charts, audit logs, custom pages

- **Core:** Filament 3.3 panel builder — covers resources, pages, widgets, notifications, form/table builders.
- **Charts:**
  - **Primary:** Filament's built-in Chart widget (Chart.js under the hood). Good enough for the dashboard specs in Req F — last-run duration, updated/failed counts, margin history, top deltas.
  - **Escape hatch:** `leandrocfe/filament-apex-charts` ^3.x (for Filament 3) if we hit Chart.js limits on the competitor-price trend views. ApexCharts is more interactive (zoom, hover tooltips) — but only add it when we need it; it's a heavier client bundle.
  - **⚠️ Version gotcha:** `leandrocfe/filament-apex-charts` ^5.0 is Filament 4 only. On Filament 3 pin **^3.x** explicitly.
- **Audit log viewer:** `rmsramos/activitylog` Filament plugin — drops in a "Activity Log" resource with filters by causer/subject/date. Not strictly needed if we build our own CrmPushLog resource, but saves time on the generic model-change audit.
- **Custom pages:** Filament's `Pages` API — all six dashboard sections from Req F are either Resources (Pricing rules, CRM push log) or custom Pages (Supplier sync status, Import issues, Competitor analysis) with widgets.
- **Horizon link:** Filament 3 has an official `filament/notifications` system; no Horizon plugin needed — just link to `/horizon` from the navigation with `NavigationItem::make()->url('/horizon')`.

**NOT recommended:**
- `filament/filament` ^4.0 — see "What NOT to Use" below. **Stay on 3.x for v1.**
- `Z3d0X/filament-logger` — **unmaintained** since mid-2024. Use the `jacobtims/filament-logger` fork if we want file-log tailing, or `rmsramos/activitylog` for DB-backed.
- Tailwind 4 — breaks Filament 3 completely.

Sources: [Filament docs 3.x](https://filamentphp.com/docs/3.x/panels/installation), [leandrocfe/filament-apex-charts](https://github.com/leandrocfe/filament-apex-charts), [Filament chart widgets docs](https://filamentphp.com/docs/3.x/widgets/charts).

### 6. Ingest CSVs from disk (n8n drops)

- **Primary:** `spatie/simple-excel` ^3.9.
  - Generator-based reader — processes CSVs of arbitrary size with constant memory (critical for "full history" competitor retention over months/years).
  - Works directly with Laravel's `LazyCollection` API.
  - Auto-detects delimiters; handles BOMs.
- **File discovery pattern:** Scheduled command `php artisan competitors:ingest` that runs every 5 minutes, scans `storage/app/competitors/inbox/`, dispatches one `IngestCompetitorCsvJob` per file, moves the file to `storage/app/competitors/processed/{date}/` on success or `storage/app/competitors/failed/` on exception.
- **Header detection:** Competitor CSVs have inconsistent headers — write a small `ColumnMapper` service that matches `sku|mpn|part_number` and `price|cost|gbp|£` case-insensitively. Exactly mirrors the old plugin's logic (tracked in the business memory `stock_updater_plugin_architecture`).

**NOT recommended:**
- `maatwebsite/laravel-excel` — popular but heavyweight (PhpSpreadsheet under the hood, loads whole workbooks into memory). Meant for Excel, not CSV streams; memory footprint becomes real pain above ~50k rows.
- `league/csv` — lower-level, well-made, but we'd end up writing the Laravel integration layer that `spatie/simple-excel` already provides.
- Native PHP `fgetcsv()` — works, but no BOM handling, no delimiter detection, no generator API. Don't reinvent for a ~50 line save.

Sources: [spatie/simple-excel GitHub](https://github.com/spatie/simple-excel), [spatie/simple-excel Packagist](https://packagist.org/packages/spatie/simple-excel).

---

## Installation (composer-only — no npm needed beyond Filament's default)

```bash
# Core framework + admin
composer require laravel/framework:^12.0
composer require filament/filament:"^3.3"

# Queues + monitoring
composer require laravel/horizon:"^5.45"
composer require laravel/pulse:"^1.4"
composer require laravel/telescope:"^5.0" --dev

# External integrations
composer require automattic/woocommerce:"^3.1"
composer require bitrix24/b24phpsdk:"^1.0"

# RBAC
composer require spatie/laravel-permission:"^6.0"
composer require bezhansalleh/filament-shield:"^3.3"

# Audit log
composer require spatie/laravel-activitylog:"^4.12"
composer require rmsramos/activitylog:"^2.0"

# CSV ingest
composer require spatie/simple-excel:"^3.9"

# Error tracking
composer require sentry/sentry-laravel:"^4.0"

# Dev / testing / quality
composer require --dev pestphp/pest:"^3.0"
composer require --dev pestphp/pest-plugin-laravel:"^3.0"
composer require --dev nunomaduro/larastan:"^3.0"
composer require --dev laravel/pint:"^1.24"
composer require --dev barryvdh/laravel-debugbar:"^3.0"

# PHP extensions (install via pecl / apt on the VPS, not composer)
# - pecl install redis            (phpredis)
# - apt install php8.2-intl       (required by Bitrix SDK + several Spatie packages)
# - apt install php8.2-bcmath     (precise price math for pricing engine)
```

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|---|---|---|
| `bitrix24/b24phpsdk` (official) | `mesilov/bitrix24-php-sdk` | If we hit version-pin pain or missing endpoints on the official SDK — mesilov has deeper tenure and the same webhook-auth support. |
| `spatie/simple-excel` | `league/csv` | If competitor CSVs start needing advanced transforms (joins, windowed aggregates) — but unlikely given the spec. |
| `spatie/simple-excel` | `maatwebsite/laravel-excel` | Only if stakeholders later ask for **Excel export** of admin reports; for ingestion stay with simple-excel. |
| Built-in `Http::` | Saloon | If we add a 4th/5th external API (Google Merchant, Meta Catalog — Phase 8) with shared auth/caching/rate-limit needs, Saloon's connector model pays off. For v1's 3 APIs, don't pull it in. |
| Filament built-in Chart widget | `leandrocfe/filament-apex-charts` | When Chart.js's interactivity becomes a blocker on the competitor-price drill-downs. |
| `rmsramos/activitylog` | `pxlrbt/filament-activity-log` | If we want a simpler, single-page activity view — rmsramos is richer but heavier. |
| Pest + PHPUnit | PHPUnit only | If someone on the team strongly dislikes Pest's function syntax — both still work and tests don't need migration. |
| Sentry | Flare | Flare has slick Laravel DX and is from Spatie; pick it if we also want Spatie's Ignition premium features. Sentry wins on "official Laravel partnership" and broader tool ecosystem (alerts, releases). |
| Laravel Pulse | New Relic / Datadog | Only if Meeting Store business is already paying for APM on other infra. For standalone, Pulse + Sentry covers 95%. |
| phpredis extension | Predis | Only if the VPS refuses pecl installs (shouldn't). |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|---|---|---|
| **Filament 4.x for v1** | Released Aug 2025 but still maturing; requires Tailwind 4; some third-party plugins (apex-charts, shield, activitylog-pro) have diverging version matrices per major. Upgrading mid-build is a tax we don't need. Plan the v3→v4 upgrade as a post-cutover Phase 8+ ticket. | Filament `^3.3` pinned, stay there for v1. |
| **Tailwind CSS 4.x** | Hard-incompatible with Filament 3. | Tailwind `^3.4`. |
| **Direct WP database writes** (e.g., `$wpdb`-style queries over a MySQL bridge) | Explicit project constraint. Breaks future multi-channel expansion. | `automattic/woocommerce` REST client only. |
| **`itgalaxycompany/*` or any Russian WordPress-plugin repackaging** | The entire reason for this rebuild — procurement/sanctions compliance. | `bitrix24/b24phpsdk` or `mesilov/bitrix24-php-sdk` for CRM; roll our own for anything else. |
| **Roistat / Yandex Metrika SDKs** | Explicitly Out of Scope in PROJECT.md (Russian analytics stack, unused). | UTM + GA Client ID capture only, pushed as Bitrix custom fields. |
| **Laravel Telescope in production** | Stores every request, query, and log to DB with high overhead. Can leak secrets in prod logs. | Telescope `--dev` only; Pulse in production for aggregate metrics. |
| **`Z3d0X/filament-logger`** | Unmaintained since mid-2024; broken against Filament 3.3 on some configs. | `jacobtims/filament-logger` (active fork) **or** `rmsramos/activitylog` (purpose-built viewer). |
| **`maatwebsite/laravel-excel` for CSV ingest** | Loads whole workbooks into memory; meant for Excel; overkill for n8n CSV drops. | `spatie/simple-excel` (generator-based). |
| **Saloon for Woo/Bitrix/21stcav** | Excellent package but over-abstraction for 3 stable APIs with dedicated SDKs. | Wrap each API in a thin `App\Services\{Vendor}\{Vendor}Client` service on top of its native client / `Http::` facade. |
| **Predis** (the pure-PHP Redis client) | phpredis is ~2× faster and we control the VPS. | `pecl install redis` → phpredis extension. |
| **Laravel WebSockets / Reverb for Filament** | Not needed for this ops app — dashboard refreshes are fine via Filament's Livewire polling. | Skip until a Phase 9 "live notifications" requirement emerges. |
| **Any package requiring Laravel 10 or lower in `composer.json` constraints** | Will either force us down-rev or create dependency-resolver pain. | Verify every `^8.0\|^9.0\|^10.0` pin includes `^12.0` before adopting. |

---

## Known Friction Points (flag for roadmap)

| Area | Friction | Mitigation |
|---|---|---|
| **Filament 3 plugin availability** | Some third-party plugins have not updated to Filament 3.3 + Laravel 12 combo. | For every plugin above we've verified compatibility as of April 2026. Before adding any new plugin not in this list, verify its `composer.json` supports both `"filament/filament": "^3.0"` and `"illuminate/contracts": "^12.0"`. |
| **Horizon + phpredis on same-VPS deployment** | Horizon's auto-balance strategy can starve queues if Redis saturates on the shared VPS with Woo itself. | Separate Redis DB (`database.redis.horizon.database` = different index from cache). Monitor via Pulse. Consider Valkey as a drop-in Redis replacement if memory pressure appears. |
| **Filament 3 → 4 upgrade** | Eventual; requires Tailwind 3 → 4 migration, plugin version bumps, and `app/Filament/*` API adjustments. | Treat as a dedicated post-v1 phase. Filament ships an automated upgrade script, but third-party plugin bumps are manual. |
| **Bitrix SDK newness** | Official SDK is young; API-surface changes possible on the 1.x → 3.x path. | Pin to `^1.0` exact line; keep the community SDK (mesilov) as a documented fallback. |
| **WooCommerce webhook delivery reliability** | Woo retries webhook failures 5 times over ~24 hours then gives up. | Always return 200 immediately after signature verification, queue the actual work. Any error in the queue job does NOT cause Woo to retry — we reconcile via the daily sync. |
| **Automattic client HTTP defaults** | Default timeout is 10s, no retry. For our daily 1000+ product sync this matters. | Configure Guzzle options in WooClient constructor: `timeout: 30`, Guzzle retry middleware on 429/5xx with exponential backoff. |
| **`spatie/simple-excel` large-file behaviour** | Generators hold file handles open; long jobs eventually hit Horizon's `retry_after`. | Break CSV ingest into chunk-jobs of N rows (e.g., 500) via `LazyCollection::chunk(500)`. |
| **Activity log table growth** | `activity_log` fills fast if every model write is logged. | Limit `LogsActivity` to specific fields on each model. Schedule monthly prune (`activitylog:clean --days=180`) — resolves PROJECT.md Open Item "Retention policy for audit logs." |

---

## Version Compatibility Matrix

| Package | Pin | Laravel 12 | Filament 3.3 | PHP 8.2 | Notes |
|---|---|---|---|---|---|
| `laravel/framework` | ^12.0 | — | ✅ | ✅ | Min PHP 8.2. |
| `filament/filament` | ^3.3 | ✅ | — | ✅ | Requires Tailwind 3. |
| `laravel/horizon` | ^5.45 | ✅ | n/a | ✅ | |
| `laravel/pulse` | ^1.4 | ✅ | n/a | ✅ | Redis-only storage in prod. |
| `laravel/telescope` | ^5.0 | ✅ | n/a | ✅ | `--dev` only. |
| `automattic/woocommerce` | ^3.1 | ✅ | n/a | ✅ | 3.1.1 also supports PHP 8.5. |
| `bitrix24/b24phpsdk` | ^1.0 | ✅ | n/a | ✅ (8.2–8.4) | v3.x line is PHP 8.4+. |
| `spatie/laravel-permission` | ^6.0 | ✅ | n/a | ✅ | **April 2026:** v7.x not yet Filament 3.3-compatible; resolver picks v6. |
| `bezhansalleh/filament-shield` | ^3.3 | ✅ | ✅ (3.x line) | ✅ | Shield 4.x is Filament 4 only — do not cross streams. |
| `spatie/laravel-activitylog` | ^4.12 | ✅ | n/a | ✅ | Supports Laravel 8–13. |
| `rmsramos/activitylog` | ^2.0 | ✅ | ✅ | ✅ | **April 2026:** v2.x required for Filament 3.3 compat. |
| `spatie/simple-excel` | ^3.9 | ✅ | n/a | ✅ | |
| `sentry/sentry-laravel` | ^4.0 | ✅ | n/a | ✅ | |
| `pestphp/pest` | ^3.0 | ✅ | n/a | ✅ | Runs on PHPUnit 11. |

---

## Stack Patterns by Variant (edge cases to flag at phase planning)

**If VPS cannot install phpredis** (unlikely — we own the box):
- Fall back to `predis/predis` ^2.x
- Accept ~1.5–2× queue throughput drop

**If Bitrix official SDK proves unstable:**
- Swap constructor dep in `BitrixClient` for `mesilov/bitrix24-php-sdk` ^2.x
- Our wrapper pattern means this is a 1-hour swap, not a refactor

**If competitor CSV volume explodes beyond simple-excel's comfort zone** (>1M rows/day):
- Add a pre-processing step with `ext-intl` + native `fgetcsv` to split into <100k chunks
- Still feed `spatie/simple-excel` per chunk

**If we later need Shopify/Amazon channels (Phase 8):**
- Introduce Saloon at that point — the connector pattern pays off when there are 4+ external APIs with shared concerns (retry, rate-limit, caching)
- Existing `WooClient`/`BitrixClient` stay as-is

---

## Confidence Assessment

| Library / Choice | Confidence | Basis |
|---|---|---|
| Laravel 12 + Filament 3.3 + Horizon + Pulse | HIGH | Official docs + Packagist versions as of April 2026 + community real-world usage |
| `automattic/woocommerce` ^3.1 | HIGH | Official Automattic SDK, verified 3.1.1 released Jan 30 2026 with PHP 8.5 support |
| `spatie/*` packages (permission/activitylog/simple-excel) | HIGH | All three released updates in Feb-Mar 2026 with `^12.0` Laravel constraint |
| `bezhansalleh/filament-shield` 3.x pin | HIGH | Shield 4.x released for Filament 4; 3.x line actively backported |
| `bitrix24/b24phpsdk` official SDK | MEDIUM | Exists, is maintained by Bitrix org, but newer than `mesilov/bitrix24-php-sdk`. Fallback plan documented. |
| phpredis over Predis | HIGH | Well-established performance gap on dedicated VPS |
| Pest 3 + PHPUnit 11 | HIGH | Laravel 12 ships with Pest test scaffolding by default |
| Sentry over Flare | MEDIUM | Both valid; Sentry wins on "official Laravel partner" status — soft preference not strict |
| Rolling our own HMAC middleware (no package) | HIGH | 30-line implementation with `hash_hmac` + `hash_equals`; well-documented Woo webhook spec |
| Not using Saloon | MEDIUM | Correct for v1 scope but reassessable at Phase 8 |
| Avoiding Filament 4 for v1 | HIGH | Upgrade mid-build would burn weeks; v3 will be supported throughout v1 timeline |

---

## Sources

**Official documentation / Packagist (HIGH confidence):**
- [Laravel 12 — Horizon](https://laravel.com/docs/12.x/horizon)
- [Laravel 12 — Redis](https://laravel.com/docs/12.x/redis)
- [Laravel 12 — Testing](https://laravel.com/docs/12.x/testing)
- [Laravel 12 — HTTP Client](https://laravel.com/docs/12.x/http-client)
- [Filament 3.x — Installation](https://filamentphp.com/docs/3.x/panels/installation)
- [Filament 3.x — Chart widgets](https://filamentphp.com/docs/3.x/widgets/charts)
- [Filament v4 stable announcement](https://filamentphp.com/content/alexandersix-filament-v4-is-stable) — context for "why stay on v3"
- [Filament releases on GitHub](https://github.com/filamentphp/filament/releases)
- [Packagist — automattic/woocommerce](https://packagist.org/packages/automattic/woocommerce)
- [Packagist — laravel/horizon](https://packagist.org/packages/laravel/horizon)
- [Packagist — spatie/laravel-permission](https://packagist.org/packages/spatie/laravel-permission)
- [Packagist — spatie/laravel-activitylog](https://packagist.org/packages/spatie/laravel-activitylog)
- [Packagist — spatie/simple-excel](https://packagist.org/packages/spatie/simple-excel)
- [GitHub — bitrix24/b24phpsdk](https://github.com/bitrix24/b24phpsdk) (official)
- [GitHub — mesilov/bitrix24-php-sdk](https://github.com/mesilov/bitrix24-php-sdk) (community fallback)
- [GitHub — bezhanSalleh/filament-shield](https://github.com/bezhanSalleh/filament-shield)
- [Bitrix24 SDK index](https://apidocs.bitrix24.com/sdk/index.html)

**Articles / third-party (MEDIUM confidence, cross-referenced):**
- [Laravel Pulse vs Telescope — production strategy](https://yassineaitsidibrahim.medium.com/why-laravel-pulse-is-better-than-telescope-for-monitoring-in-production-184c16fa1326)
- [WooCommerce webhook signature behaviour](https://hookdeck.com/webhooks/platforms/guide-to-woocommerce-webhooks-features-and-best-practices)
- [How to Implement Webhooks in Laravel (Jan 2026)](https://oneuptime.com/blog/post/2026-02-02-laravel-webhooks-implementation/view)
- [Filament Activity Log packages — comparison](https://laraveldaily.com/post/filament-activity-logs-three-packages-comparison-review)
- [Pest vs PHPUnit decision guide 2026](https://itmarkerz.co.in/blog/laravel-testing-strategy-2026)
- [Laravel WooCommerce integration patterns 2026](https://attowp.com/backend-server/laravel-for-wordpress-users/laravel-wordpress-integration/)

---

*Stack research for: MeetingStore Ops (Laravel 12 + Filament 3 source-of-truth layer over WooCommerce + Bitrix24 CRM sync)*
*Researched: 2026-04-18*
