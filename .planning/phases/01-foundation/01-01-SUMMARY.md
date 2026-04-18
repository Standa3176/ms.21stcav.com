---
phase: 01-foundation
plan: 01-scaffold
subsystem: infra
tags: [laravel-12, filament-3, horizon, pulse, spatie-permission, filament-shield, activitylog, deptrac, pest, pint, phpstan, sentry]

requires:
  - phase: none
    provides: "Greenfield — pre-existing Laravel 12 skeleton + Docker infra (MySQL 8.4 + Redis 7.2) + .env"
provides:
  - "All 13 Phase-1 composer packages installed at caret-pinned versions"
  - "app/Domain/{Products,Pricing,Competitor,Sync,Webhooks,CRM,Suggestions,Alerting}/ module skeleton (.gitkeep)"
  - "app/Foundation/{Audit,Integration,Events}/ cross-cutting infra layer (.gitkeep)"
  - "app/Domain/Feeds/Contracts/FeedGenerator.php — FOUND-13 channel-feed contract stub"
  - "routes/webhooks.php registered under 'api' middleware group with CSRF exclusion for webhooks/*"
  - "config/database.php Redis block with 4 logical DBs (default=0, cache=1, horizon=2, pulse=3)"
  - "depfile.yaml + deptrac.yaml — 11-layer Deptrac ruleset, Foundation as sole shared dep"
  - "pint.json (Laravel preset + strict types), phpstan.neon (Larastan level 6), phpunit.xml (Architecture suite), tests/Pest.php"
  - ".env.example populated with WOO_WRITE_ENABLED=false, QUEUE_CONNECTION=redis, REDIS_CLIENT=phpredis, Horizon prefixes, Sentry keys"
affects:
  - "02-rbac (consumes spatie/permission + shield install)"
  - "03-foundation (consumes Foundation layer + FeedGenerator)"
  - "04-seams (consumes routes/webhooks.php + bootstrap/app.php CSRF exclusion)"
  - "05-horizon-alerting (consumes horizon config + failed-job-monitor + AlertRecipient slot)"
  - "all later phases (consume Deptrac rules + Tailwind 3 + Filament 3)"

tech-stack:
  added:
    - "filament/filament ^3.3 (3.3.50)"
    - "laravel/horizon ^5.45 (5.45.6)"
    - "laravel/pulse ^1.4 (1.7.3)"
    - "spatie/laravel-permission ^6.0 (6.25.0) — DOWN from plan ^7.2 (shield 3.x pins spatie ^6.0)"
    - "bezhansalleh/filament-shield ^3.3 (3.9.10)"
    - "spatie/laravel-activitylog ^4.12 (4.12.3)"
    - "rmsramos/activitylog ^2.0 (2.0.0) — UP from plan ^1.0 (v2.0.0 first Laravel 12 compatible)"
    - "spatie/laravel-failed-job-monitor ^4.4 (4.4.0) — tighter than plan ^4.0 (4.4+ needed for Laravel 12)"
    - "sentry/sentry-laravel ^4.17 (4.25.0)"
    - "qossmic/deptrac-shim ^1.0 (1.0.2)"
    - "pestphp/pest ^3.8.5 (3.8.6) — tighter than plan ^3.0 (3.8.5+ needed for phpunit 11.5.55)"
    - "pestphp/pest-plugin-laravel ^3.1 (3.1.0) — tighter than plan ^3.0 (3.1+ needed for Laravel 12)"
    - "nunomaduro/larastan ^3.9 (3.9.6) — tighter than plan ^3.0 (3.9+ needed for Laravel 12.4+)"
    - "laravel/pint ^1.24 (1.29.0) — upgraded from existing ^1.13"
    - "tailwindcss ^3.4.17 (npm) — DOWN from skeleton's ^4.0.0 per Pitfall D"
  patterns:
    - "Module-boundary enforcement: app/Domain/<Module>/ layers may only import from app/Foundation/ (Deptrac-enforced)"
    - "Webhook route seam: routes/webhooks.php registered under 'api' middleware with CSRF exclusion for webhooks/* (Plan 04 populates)"
    - "Redis logical DB segregation: queues (DB 0), cache (DB 1), horizon (DB 2), pulse (DB 3) prevents FLUSHDB collisions"
    - "Caret-pinned composer versions only; composer.lock committed (T-01-02 mitigation)"
    - ".env is git-ignored; .env.example is the canonical env template (T-01-01 mitigation)"

key-files:
  created:
    - "app/Domain/Feeds/Contracts/FeedGenerator.php"
    - "routes/webhooks.php"
    - "depfile.yaml"
    - "deptrac.yaml (default-lookup mirror of depfile.yaml)"
    - "pint.json"
    - "phpstan.neon"
    - "tests/Pest.php"
    - "tests/Architecture/.gitkeep"
    - "tailwind.config.js"
    - "postcss.config.js"
    - "app/Domain/{Products,Pricing,Competitor,Sync,Webhooks,CRM,Suggestions,Alerting}/.gitkeep × 8"
    - "app/Foundation/{Audit,Integration,Events}/.gitkeep × 3"
    - "config/activitylog.php, config/failed-job-monitor.php, config/horizon.php, config/permission.php, config/pulse.php (published)"
    - "database/migrations/2026_04_18_160132_create_pulse_tables.php (+ permission + activity_log × 3)"
  modified:
    - "composer.json (13 new deps; caret-pinned)"
    - "composer.lock"
    - "package.json (Tailwind 4 → Tailwind 3)"
    - "vite.config.js (removed @tailwindcss/vite plugin)"
    - "resources/css/app.css (Tailwind 4 @import → Tailwind 3 @tailwind directives)"
    - "bootstrap/app.php (register routes/webhooks.php + CSRF exclusion)"
    - "config/database.php (add horizon + pulse Redis logical DBs)"
    - ".env.example (full MeetingStoreOps env template)"
    - ".env (merge WOO_WRITE_ENABLED + Horizon + Sentry keys)"
    - "phpunit.xml (add Architecture testsuite)"
    - ".gitignore (add .deptrac.cache)"

key-decisions:
  - "spatie/laravel-permission downgraded to ^6.0 — Shield 3.x line requires spatie ^6.0; upgrading to Shield 4.x would force Filament 4 (forbidden by STACK.md)"
  - "rmsramos/activitylog upgraded to ^2.0 — v1.x incompatible with Laravel 12; v2.0.0 is first v12-compatible release"
  - "Tailwind downgraded from v4 to v3.4.17 per Pitfall D — Filament 3 is hard-incompatible with Tailwind 4"
  - "Laravel skeleton was pre-existing (ca736c6) from parallel Codex session — composer create-project SKIPPED per pre-condition directive"
  - "Horizon install uses --ignore-platform-req=ext-pcntl/posix on Windows dev; production VPS (Linux) has both extensions"
  - "deptrac-shim config uses depfile.yaml (plan canonical) + deptrac.yaml (default CLI lookup mirror) so both the plan's CI invocation and vendor/bin/deptrac default work"

patterns-established:
  - "Pattern 1 (FOUND-02): Domain modules live in app/Domain/<Module>/. Cross-domain imports are forbidden. Foundation (app/Foundation/) is the only cross-cutting layer. Http may import from any layer. Enforced by Deptrac in CI."
  - "Pattern 2 (FOUND-13): FeedGenerator contract — future Phase 8 channel feeds implement channel(), generate(), lastGeneratedAt(). Contract frozen in Phase 1."
  - "Pattern 3: Webhook registration seam — routes/webhooks.php loads under 'api' middleware with CSRF exclusion for webhooks/*. Plan 04 adds HMAC-protected routes without touching bootstrap/app.php."
  - "Pattern 4: Composer caret pins + committed lockfile + security advisory checking — T-01-02 mitigation."

requirements-completed:
  - FOUND-02
  - FOUND-13

duration: ~50 min
completed: 2026-04-18
---

# Phase 01 Plan 01: Scaffold Summary

**Laravel 12 + Filament 3.3 + Horizon + Pulse + Spatie auth stack installed at caret-pinned versions; app/Domain/ module skeleton created and Deptrac-enforced; FeedGenerator contract (FOUND-13) and webhook route seam shipped; Tailwind downgraded v4→v3 per Filament 3 hard-incompat.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-04-18T16:03Z
- **Completed:** 2026-04-18T17:28Z
- **Tasks:** 3
- **Files created:** 25+ (skeleton dirs, contract, config, depfile, migrations)
- **Files modified:** 11 (composer.json, composer.lock, package.json, vite.config.js, resources/css/app.css, bootstrap/app.php, config/database.php, .env.example, .env, phpunit.xml, .gitignore)

## Accomplishments

- Installed all 13 Phase-1 composer packages with proper caret pins (3 pin adjustments documented below)
- Created the 9 Domain + 3 Foundation skeleton directories with .gitkeep sentinels
- Shipped FeedGenerator contract (FOUND-13) with PSR-4 autoload-verified interface
- Registered routes/webhooks.php route group under 'api' middleware with CSRF exclusion for webhooks/*
- Configured Deptrac ruleset (11 layers, Foundation as the only shared dep); verified positive (empty scaffold passes) AND negative (deliberate cross-domain import fails with exit 1, 2 violations detected)
- Downgraded Tailwind v4 → v3.4.17 per Pitfall D (Filament 3 is hard-incompatible with Tailwind 4)
- Added 4 logical Redis DBs (default/cache/horizon/pulse) to config/database.php
- Ran package-scaffold migrations (pulse_tables, permission_tables, activity_log_table × 3)

## Task Commits

1. **Task 1: Install Phase 1 packages + configure env/redis/tailwind** — `213acaf` (feat)
2. **Task 2: Scaffold Domain/Foundation skeleton + FeedGenerator contract (FOUND-13)** — `8d7af2c` (feat)
3. **Task 3: Configure Deptrac, Pint, PHPStan, Pest for module-boundary CI (FOUND-02)** — `4a18ea9` (chore)

## Files Created/Modified

### Created

- `app/Domain/Feeds/Contracts/FeedGenerator.php` — FOUND-13 channel-feed contract (channel(), generate(), lastGeneratedAt())
- `routes/webhooks.php` — empty placeholder; Plan 04 will add HMAC-protected Woo routes
- `depfile.yaml` + `deptrac.yaml` — 11-layer ruleset (9 Domain + Foundation + Http)
- `pint.json` — Laravel preset + strict types/params/comparison + alpha-sorted imports
- `phpstan.neon` — Larastan level 6, ignoring Filament navigation noise
- `tailwind.config.js`, `postcss.config.js` — Tailwind 3 build pipeline
- `tests/Pest.php` — standard TestCase + RefreshDatabase for Feature suite
- `tests/Architecture/.gitkeep` — placeholder for Plan 05 deptrac assertions
- `app/Domain/{Products,Pricing,Competitor,Sync,Webhooks,CRM,Suggestions,Alerting}/.gitkeep` (×8)
- `app/Foundation/{Audit,Integration,Events}/.gitkeep` (×3)
- `config/activitylog.php`, `config/failed-job-monitor.php`, `config/horizon.php`, `config/permission.php`, `config/pulse.php` — published package configs
- 5 new migrations: pulse tables, permission tables, activity_log table + event column + batch UUID column

### Modified

- `composer.json` — added 13 Phase-1 deps (caret-pinned); upgraded laravel/pint ^1.13 → ^1.24
- `composer.lock` — locked 113 total packages
- `package.json` — tailwindcss ^4.0.0 → ^3.4.17; added autoprefixer + postcss; removed @tailwindcss/vite
- `vite.config.js` — removed @tailwindcss/vite plugin import
- `resources/css/app.css` — replaced Tailwind 4 `@import 'tailwindcss'` with Tailwind 3 `@tailwind base/components/utilities`
- `bootstrap/app.php` — registered routes/webhooks.php under 'api' middleware; added CSRF exclusion for webhooks/*
- `config/database.php` — added horizon (db=2) and pulse (db=3) Redis logical DBs
- `.env.example` — full MeetingStoreOps template: APP_ENV=production, DB=mysql, Redis=phpredis, QUEUE=redis, CACHE=redis, WOO_WRITE_ENABLED=false, WC_WEBHOOK_SECRET=, Horizon prefixes, Sentry keys, commented Woo/Bitrix/Supplier placeholders for Phases 2/4
- `.env` — merged Phase 1 keys without overwriting existing DB/Redis creds
- `phpunit.xml` — added Architecture testsuite
- `.gitignore` — added .deptrac.cache

## Decisions Made

See frontmatter `key-decisions` for full list. Headline items:

1. **spatie/laravel-permission ^6.0 (not ^7.2):** Shield 3.x line requires spatie ^6.0. The plan's `^7.2` pin in STACK.md is inaccurate against April 2026 package reality — upgrading spatie to 7.x would force Shield 4.x, which forces Filament 4.x, which is explicitly forbidden by STACK.md and Pitfall D.

2. **rmsramos/activitylog ^2.0 (not ^1.0):** v1.x line hard-requires Laravel 10 or 11; v2.0.0 is the first Laravel 12 compatible release. Plan's `^1.0` pin predates this package's v2 release.

3. **Tailwind v3.4.17 (not v4):** Laravel 12 skeleton now ships Tailwind 4 by default. Filament 3 is hard-incompatible with Tailwind 4 (Pitfall D). Downgraded via package.json + vite.config.js + resources/css/app.css.

4. **Horizon pcntl/posix on Windows dev:** Horizon's `ext-pcntl` + `ext-posix` requirements are Unix-only. Local Windows/Herd install uses `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`; production VPS (Linux) has both extensions (documented in 01-RESEARCH.md §Environment Availability).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `composer create-project` step skipped**
- **Found during:** Task 1 bootstrap
- **Issue:** Plan Task 1 starts with `composer create-project laravel/laravel:^12.0 . --prefer-dist`, but Laravel 12 skeleton was pre-existing (installed by parallel Codex session, commit `ca736c6` on master). Re-running create-project would destroy the app.
- **Fix:** Skipped create-project; started installation at `composer require filament/filament:"^3.3"` per plan sequence.
- **Verification:** `php artisan --version` confirms Laravel Framework 12.56.0 before and after task.
- **Documented per pre-condition directive #2.**

**2. [Rule 3 — Blocking] spatie/laravel-permission downgraded ^7.2 → ^6.0**
- **Found during:** Task 1 (Shield install)
- **Issue:** `composer require bezhansalleh/filament-shield:^3.3` failed with: "filament-shield 3.x requires spatie/laravel-permission ^6.0 -> conflicts with root composer.json require (^7.2)". The entire Shield 3.x line (3.0.0 → 3.9.10) pins spatie ^6.0.
- **Fix:** Changed composer.json `spatie/laravel-permission: "^7.2"` → `"^6.0"`; ran composer update -W; resumed Shield install.
- **Verification:** `composer show spatie/laravel-permission` returns `6.25.0`; `composer show bezhansalleh/filament-shield` returns `3.9.10`; both resolve without conflict.
- **Committed in:** `213acaf` (Task 1)

**3. [Rule 3 — Blocking] rmsramos/activitylog upgraded ^1.0 → ^2.0**
- **Found during:** Task 1 (activitylog viewer install)
- **Issue:** `composer require rmsramos/activitylog:^1.0` failed: "v1.0.0 requires illuminate/contracts ^10.0||^11.0" — no Laravel 12 support in 1.x line. Publisher released v2.0.0 adding Laravel 12 support.
- **Fix:** Changed to `^2.0`.
- **Verification:** `composer show rmsramos/activitylog` returns `v2.0.0`.
- **Committed in:** `213acaf` (Task 1)

**4. [Rule 3 — Blocking] spatie/laravel-failed-job-monitor pin tightened ^4.0 → ^4.4**
- **Found during:** Task 1
- **Issue:** `^4.0` resolves to 4.0.0 which requires laravel/framework ^7.0|^8.0 — not Laravel 12. Needed minimum v4.4.0 for Laravel 12 support.
- **Fix:** Tightened to `^4.4`.
- **Verification:** `composer show spatie/laravel-failed-job-monitor` returns `4.4.0`.

**5. [Rule 3 — Blocking] Pest/Larastan/Pest-Plugin-Laravel pins tightened**
- **Found during:** Task 1 (dev deps)
- **Issue:** Plan's `^3.0` pins for each resolved to earliest 3.0.0 which don't support Laravel 12 / PHPUnit 11.5.55 (active version in this skeleton).
- **Fix:**
  - `pestphp/pest`: `^3.0` → `^3.8.5` (3.8.5+ drops upper-bound phpunit conflict)
  - `pestphp/pest-plugin-laravel`: `^3.0` → `^3.1` (3.1.0+ supports Laravel 12)
  - `nunomaduro/larastan`: `^3.0` → `^3.9` (3.9.x supports Laravel 12.4+)
- **Verification:** `composer show` returns pest 3.8.6, pest-plugin-laravel 3.1.0, larastan 3.9.6.

**6. [Rule 2 — Missing Critical] Tailwind 4 → Tailwind 3 downgrade**
- **Found during:** Task 1 post-install check
- **Issue:** Laravel 12 skeleton ships `tailwindcss ^4.0.0` + `@tailwindcss/vite ^4.0.0` + Tailwind 4 `@import 'tailwindcss'` CSS syntax by default. Filament 3 is hard-incompatible with Tailwind 4 (Pitfall D), and plan's done-criteria asserts `package.json` contains `"tailwindcss": "^3"`.
- **Fix:**
  - `package.json` — replaced `@tailwindcss/vite ^4`/`tailwindcss ^4` with `tailwindcss ^3.4.17` + `autoprefixer ^10.4.20` + `postcss ^8.4.47`
  - `vite.config.js` — removed `import tailwindcss from '@tailwindcss/vite'` + plugin call
  - `resources/css/app.css` — replaced Tailwind 4 `@import 'tailwindcss'` syntax with standard Tailwind 3 `@tailwind base/components/utilities`
  - Created `tailwind.config.js` (content paths include Filament vendor views) + `postcss.config.js`
- **Verification:** `grep "tailwindcss" package.json` returns `"tailwindcss": "^3.4.17"`.
- **Committed in:** `213acaf` (Task 1)

**7. [Rule 3 — Blocking] Horizon pcntl/posix extensions missing on Windows dev**
- **Found during:** Task 1 (Horizon install)
- **Issue:** `laravel/horizon ^5.45` requires `ext-pcntl` + `ext-posix` — Unix-only, not available on Windows/Herd.
- **Fix:** Used `composer require --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` for Horizon + all subsequent composer commands on this Windows dev environment. Production VPS (Linux) has both extensions per 01-RESEARCH.md Environment Availability section.
- **Caveat:** All future composer operations on Windows dev need the same flags. Ops deploy runbook must not assume Windows workflow.

**8. [Rule 2 — Missing Critical] deptrac.yaml mirror of depfile.yaml**
- **Found during:** Task 3 (Deptrac verification)
- **Issue:** `qossmic/deptrac-shim` defaults to loading `deptrac.yaml`, not `depfile.yaml`. Plan's done-criteria specifies `depfile.yaml` at repo root (CI uses explicit `--config-file=depfile.yaml`), but developers running `vendor/bin/deptrac analyse` with no args will hit "The file 'deptrac.yaml' does not exist".
- **Fix:** Copied `depfile.yaml` → `deptrac.yaml` so both the plan's canonical CI path (`depfile.yaml`) AND the default CLI invocation (`deptrac.yaml`) work.
- **Verification:** `vendor/bin/deptrac analyse --no-progress` (no args) exits 0 on empty scaffold.
- **Committed in:** `4a18ea9` (Task 3)

**9. [Rule 3 — Blocking] phpstan.neon includes path corrected**
- **Found during:** Task 3
- **Issue:** Plan's phpstan.neon references `vendor/nunomaduro/larastan/extension.neon`. Correct — confirmed by `find vendor -name extension.neon`. (Earlier draft mistakenly used `vendor/larastan/larastan` path.)
- **Fix:** Uses `vendor/nunomaduro/larastan/extension.neon` in final phpstan.neon.
- **Verification:** File exists at `vendor/nunomaduro/larastan/extension.neon`.

---

**Total deviations:** 9 auto-fixed (5 blocking version pins, 2 missing-critical config, 1 skipped pre-existing step, 1 pcntl platform workaround)

**Impact on plan:** All 9 deviations are correctness-driven (Rule 2/3). No scope creep; no additional features added. The Phase-1 contract (FOUND-02 + FOUND-13 requirements, 4 Redis DBs, WOO_WRITE_ENABLED=false, empty webhook seam, Deptrac passes-empty/fails-violator) is fully delivered. The STACK.md pin adjustments should be propagated back to `.planning/research/STACK.md` before Plan 02 starts — those entries are now inaccurate for the April 2026 package ecosystem.

## Issues Encountered

1. **Deptrac cache not gitignored by default** — `.deptrac.cache` file was created during verification; added to `.gitignore` before final commit.
2. **Composer caret stripping via bash shell** — `composer require pkg:"^X.Y"` reaches composer as `pkg:X.Y` (exact pin) because bash strips carets even inside double quotes on Windows. Worked around by editing `composer.json` manually to restore carets, then running `composer update`. Final `composer.json` has proper caret pins on all 13 Phase-1 packages (verified in final-commit diff).
3. **Pest v3.8.0-3.8.4 conflicts with installed phpunit 11.5.55** — Pest's composer.json in 3.8.0-3.8.4 declares `conflict: {"phpunit/phpunit": ">11.5.15"}`. Pest 3.8.5+ removes this upper bound. Pinned pest to `^3.8.5`.

## User Setup Required

From plan's `user_setup` block — carried forward to Plan 02 consumption:

### VPS (production host for Laravel 12 + Redis + Supervisor + Cron)

- `DB_DATABASE=meetingstore_ops` — already created and migrated on dev (local Docker MySQL)
- `DB_USERNAME` / `DB_PASSWORD` — already set in dev `.env` (local creds `meetingstore`/`meetingstore`); **VPS values still needed**
- `WC_WEBHOOK_SECRET=` — **deferred** (populated in Plan 04 activation, per plan's wave-3 scheduling)

### VPS install prerequisites (for Plan 02/05)

- Install PHP extensions: `ext-redis` (phpredis via PECL), `ext-bcmath`, `ext-intl`, `ext-pcntl`, `ext-posix`
- Install Redis 7.x with `appendonly yes` + `appendfsync everysec` + `maxmemory-policy noeviction`
  - **Local dev note:** already configured in `docker-compose.yml` per commit `99eef87`
- Install Supervisor for Horizon auto-restart (Plan 05 ships template config)

### Dev-shell

- Windows users must use git-bash or WSL2 (plans use Unix idioms). Cmd/PowerShell will fail on mkdir -p, touch, heredoc.

## Next Phase Readiness

### Plan 02 (RBAC) can assume

- spatie/laravel-permission 6.25.0 installed, migration published (`2026_04_18_160211_create_permission_tables`) AND already run
- bezhansalleh/filament-shield 3.9.10 installed; `shield:install admin` NOT yet run (Plan 02 task)
- Activity log migrations published + run; activity_log table exists in DB
- `app/Domain/Alerting/` directory exists (empty, ready for AlertRecipient model per D-12)
- Filament panel provider auto-generated at `app/Providers/Filament/AdminPanelProvider.php` (plan-level `Plugin(FilamentShieldPlugin::make())` registration is Plan 02's job)
- 4 Filament panels/pages/resources directories exist under `app/Filament/`

### Plan 03 (Foundation) can assume

- `app/Foundation/{Audit,Integration,Events}/` directories exist
- `App\Domain\Feeds\Contracts\FeedGenerator` interface loaded
- `config/activitylog.php` published; Plan 03 can extend it per CONTEXT.md D-04 (365-day retention)

### Plan 04 (Seams) can assume

- `routes/webhooks.php` registered; CSRF excluded for `webhooks/*`
- Plan 04 adds `VerifyWooHmacSignature` middleware + `WooWebhookController` without touching `bootstrap/app.php`

### Plan 05 (Horizon + Alerting) can assume

- `spatie/laravel-failed-job-monitor` installed; `config/failed-job-monitor.php` published
- Horizon installed; `config/horizon.php` published; Horizon service provider registered
- Horizon and Pulse have dedicated Redis logical DBs (2, 3)

### Known concerns for later phases

1. **STACK.md version entries stale** — `spatie/laravel-permission` should be documented as `^6.0` not `^7.2`; `rmsramos/activitylog` as `^2.0` not `^1.0`. Planner agents reading STACK.md in later phases will hit the same conflicts. Recommend an out-of-band edit to STACK.md.
2. **Horizon on Windows** — Development Horizon dashboard will show workers as down because pcntl isn't available. This is expected — Horizon is intended for Linux VPS production. Windows dev uses `queue:listen` for live queue testing (already in `composer.json` `dev` script).
3. **Laravel welcome page** — `resources/views/welcome.blade.php` still references Instrument Sans + Tailwind 4 utilities via `@vite`. Will break on `npm run build` until `npm install` happens. Out of Plan 01 scope; Plan 02 or 03 will handle frontend build.
4. **filament:upgrade in post-autoload-dump** — composer.json adds `@php artisan filament:upgrade` to `post-autoload-dump` scripts. This runs on every `composer dump-autoload` / `composer update`, and may produce noisy output but is idempotent per Filament docs.

## Self-Check: PASSED

- Created files verified:
  - `app/Domain/Feeds/Contracts/FeedGenerator.php` FOUND
  - `routes/webhooks.php` FOUND
  - `depfile.yaml` FOUND
  - `deptrac.yaml` FOUND
  - `pint.json` FOUND
  - `phpstan.neon` FOUND
  - `tests/Pest.php` FOUND
  - `tailwind.config.js` FOUND
  - `postcss.config.js` FOUND
  - All 9 Domain + 3 Foundation `.gitkeep` files FOUND
- Commits verified via `git log --oneline`:
  - `213acaf` FOUND (Task 1 commit)
  - `8d7af2c` FOUND (Task 2 commit)
  - `4a18ea9` FOUND (Task 3 commit)
- Deptrac positive+negative tests executed and reverted (positive exit 0, negative exit 1 with 2 violations detected).
- `php artisan --version` returns `Laravel Framework 12.56.0`.
- All 13 packages installed; package versions logged in frontmatter `tech-stack.added`.

---

*Phase: 01-foundation*
*Plan: 01-scaffold*
*Completed: 2026-04-18*
