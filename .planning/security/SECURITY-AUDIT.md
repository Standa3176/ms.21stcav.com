---
audit_date: 2026-05-17
auditor: gsd-code-reviewer
scope: full project (17 domain modules + foundation + Filament admin panel + webhook + agent framework)
files_scanned: ~70 production source files spot-read; full-tree pattern scans across app/, config/, routes/, bootstrap/
status: findings_present
critical_count: 0
high_count: 1
medium_count: 4
low_count: 6
informational_count: 5
---

# MeetingStore Ops — Full Security Audit

## Executive Summary

Overall posture is **strong for an internal ops tool** approaching v1 cutover. The codebase shows defence-in-depth design choices that are unusually disciplined for a Laravel app of this size: webhook HMAC uses `hash_equals` against raw body, every Filament Action calls `->authorize()` as a third layer behind Shield permissions + hand-written `hasRole()` policies, every external integration's credentials are AES-256-encrypted via Laravel's native `encrypted:array` cast and excluded from `spatie/activitylog` allow-lists, and outbound HTTP log redaction is symmetric with inbound webhook header redaction. The Phase 8 agent framework includes pre-flight prompt-injection XML fencing for non-Trusted tiers, per-tool sensitive-field stripping, post-flight outbound regex filter, and atomic Redis-backed budget guards with a kill-switch. `composer audit --no-dev` returns **zero advisories** against `composer.lock` as of audit date.

The three real concerns before flipping `WOO_WRITE_ENABLED=true`:

1. **`User::canAccessPanel()` returns `true` unconditionally** (HIGH) — every authenticated user can enter `/admin`. Today, Filament Resource policies enforce per-resource access (so a `read_only` user lands on a blank-ish dashboard), but any custom Filament Page that ships without a `canAccess()` guard is exposed. Two such pages already exist (`AgentRunRejectionInboxPage`, `CsvIngestIssuesPage`) and they DO implement `canAccess()`, but the panel-level guard is the load-bearing belt-and-braces line.
2. **Trusted-proxy + secure-cookie posture missing** (MEDIUM, deployment-only) — no `trustProxies`/`trustHosts` configuration is present and `SESSION_SECURE_COOKIE` is unset in `.env.example`. Hosting on the shared `ms.21stcav.com` VPS implies nginx TLS termination upstream of Laravel; without explicit trust, cookies won't be marked Secure, audit-log IPs will be the proxy's IP, and signed-URL generation can mis-detect HTTPS. Easy to fix at deploy.
3. **SFTP `hostFingerprint: null` + FTPS `verify_ssl=false` opt-out** (MEDIUM) — `FtpSourceConnector` accepts SFTP feeds without pinning a host key and allows operators to disable TLS verification per FTPS credential row (logged with a warning, but not blocked). For competitor-CSV ingest this is a deliberate "trust on first use" choice but lays the foundation for an MITM on supplier-of-supplier traffic during the trade-pricing roll-out.

Phase 12 Trusted-tier SEO agent intentionally skips prompt-injection guards (per the documented Phase 8 threat model — internal-only input). Phase 14 WhatsApp + Chatbot Untrusted-tier surfaces are NOT yet implemented; a re-audit is required at the end of Phase 14 because the prompt-injection guard, sensitive-field strip and outbound regex filter chain has not been load-tested against adversarial customer input yet.

## Findings by Severity

### CRITICAL (0 findings) — none found

No injection, auth-bypass, hardcoded-secrets, or data-exfil vulnerabilities surfaced during this audit pass.

### HIGH (1 finding)

#### HIGH-01: `User::canAccessPanel()` unconditionally returns `true`
- **File:** `app/Models/User.php:79-82`
- **Domain:** Authorization
- **Description:** The Filament panel guard hard-codes `return true;`:
  ```php
  public function canAccessPanel(Panel $panel): bool
  {
      return true;
  }
  ```
  The class docblock says "Phase 1 policy: any authenticated user may enter /admin; Resource-level permissions are enforced by Shield-generated policies. Tightened in later phases if needed."
- **Impact:** Today, mitigated by per-Resource policies and per-Page `canAccess()` overrides (verified — `AgentRunRejectionInboxPage::canAccess()`, `CsvIngestIssuesPage::canAccess()`, `IntegrationCredentialPolicy::viewAny()` all gate correctly). The exposure is **future-state**: any Filament Page that ships without a `canAccess()` returns true by default, meaning a `read_only` user could reach a developer-forgotten internal page. Worse, the HomeDashboardPage and 9 widgets must individually enforce policies — widget data leakage is a real risk.

  This is also the gate that determines whether a non-admin user sees the panel chrome at all (sidebar, header, login). A misregistered Page is silently exposed.
- **Fix:**
  ```php
  public function canAccessPanel(Panel $panel): bool
  {
      // Any role assigned by RolePermissionSeeder grants panel entry.
      // The Resource/Page-level policies remain the load-bearing per-feature gate.
      return $this->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
  }
  ```
  Bonus: add a `tests/Feature/AdminPanelAccessTest.php` that asserts unauthenticated users → 302 to /login and users with no role → 403. Filament's `Authenticate` middleware handles auth, but role-less authenticated users bypass it today.
- **References:** OWASP A01:2021 Broken Access Control; Filament panel-gate docs https://filamentphp.com/docs/3.x/panels/users#authorizing-access-to-the-panel

### MEDIUM (4 findings)

#### MED-01: Trusted-proxy + secure-cookie not configured
- **File:** `bootstrap/app.php:19-27`, `.env.example:39-41`
- **Domain:** Configuration hardening
- **Description:** `bootstrap/app.php` does not call `->trustProxies(...)`. `.env.example` ships `SESSION_ENCRYPT=false` and omits `SESSION_SECURE_COOKIE`. Laravel 12 defaults `secure` to `null` which means the framework auto-detects from `$request->isSecure()` — but with no trusted proxy, `isSecure()` returns false behind a TLS-terminating reverse proxy. PROJECT.md confirms hosting on `ms.21stcav.com` shared VPS subdomain, which strongly implies nginx upfront.
- **Impact:**
  - Session cookies may be transmitted without the `Secure` attribute on production, enabling session theft if HTTP→HTTPS redirect is misconfigured.
  - `$request->ip()` returns the proxy IP (likely `127.0.0.1`) and persists that into `audit_log` + `integration_events` + `webhook_receipts.headers` — making forensic IP review useless.
  - `URL::temporarySignedRoute()` (used by `QueuedCsvExportJob` → `ExportDownloadController`) may generate `http://` URLs if `app()->isProduction()` and `forceScheme` aren't set, breaking the signature on the consumer side.
- **Fix:**
  ```php
  // bootstrap/app.php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->validateCsrfTokens(except: ['webhooks/*']);
      $middleware->append(\App\Http\Middleware\AttachCorrelationId::class);

      // Trust the local reverse proxy chain (nginx/Apache on the shared VPS).
      // 127.0.0.1 = local nginx; private ranges allow for future Docker/CloudFlare setups.
      $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
  })
  ```
  And in `.env` (production):
  ```
  SESSION_SECURE_COOKIE=true
  SESSION_ENCRYPT=true
  ```
  Plus an `AppServiceProvider::boot()` line for HTTPS URL generation in production:
  ```php
  if ($this->app->isProduction()) {
      URL::forceScheme('https');
  }
  ```
- **References:** Laravel docs https://laravel.com/docs/12.x/requests#configuring-trusted-proxies; OWASP A05:2021 Security Misconfiguration

#### MED-02: SFTP host fingerprint not pinned (TOFU)
- **File:** `app/Domain/Competitor/Ftp/Services/FtpSourceConnector.php:99-114`
- **Domain:** External API security (file ingest)
- **Description:**
  ```php
  $provider = new SftpConnectionProvider(
      host: $credential->host,
      ...
      hostFingerprint: null,    // ← always null
      connectivityChecker: null,
  );
  ```
  Every SFTP competitor feed connects with no host-key verification. The credential model has no `host_fingerprint` column.
- **Impact:** An attacker who can DNS-poison or BGP-hijack the competitor feed host can present a forged SSH host key and the Flysystem `SftpAdapter` will accept it silently. The intercepted CSVs feed `competitor_prices` and ultimately `margin_change` Suggestions — so a subtle pricing-manipulation attack lands in the pricing engine.

  Impact bounded today by:
  - Competitor feeds are not customer-facing
  - All margin moves > 8% (config `pricing.auto_apply_threshold_bps`) currently auto-apply; smaller moves require manual review
  - No private/secret data is sent outbound to the SFTP server (read-only pulls)
- **Fix:** Add a nullable `host_fingerprint_pinned` text column to `competitor_ftp_credentials`. When present, pass it to `SftpConnectionProvider::hostFingerprint`. Add an admin UI on `CompetitorFtpCredentialResource` that captures the fingerprint on first successful test-connection and stores it. Refuse subsequent connects when the live fingerprint diverges (raise `IntegrationEvent` `failed` + notify alert recipients).
- **References:** CWE-322 Key Exchange without Entity Authentication

#### MED-03: FTPS `verify_ssl=false` opt-out is logged but not gated
- **File:** `app/Domain/Competitor/Ftp/Services/FtpSourceConnector.php:60-68, 90`
- **Domain:** External API security
- **Description:** When `$credential->verify_ssl === false` and `protocol = ftps`:
  ```php
  Log::warning('competitor.ftp.ssl_verification_disabled', [...]);
  ...
  'ignorePassiveAddress' => $isFtps && $credential->verify_ssl === false,
  ```
  The credential row alone controls TLS verification — any admin who toggles `verify_ssl=false` in the Filament form opens the connection up to a passive MITM.
- **Impact:** Same blast radius as MED-02; this is the FTPS-specific defence-in-depth side. The log warning is operator-readable but operators have already opted in by checking the box, so it's an audit trail, not a control.
- **Fix:** Require ops sign-off via a second env flag (e.g. `COMPETITOR_FTP_INSECURE_ALLOWED=false` default) before a `verify_ssl=false` credential is allowed to execute live pulls. Block at `CompetitorFtpPullCommand::handle()` start; surface the rejection as an `import_issue` row so it appears in the home dashboard.
- **References:** CWE-295 Improper Certificate Validation

#### MED-04: `webhook_receipts.raw_body` stores customer PII without retention prune
- **File:** `app/Domain/Webhooks/Http/Controllers/WooWebhookController.php:55-65`, `routes/console.php` (no `webhook-receipts:prune` schedule)
- **Domain:** Logging & PII
- **Description:** Every inbound Woo order/customer webhook persists `$request->getContent()` verbatim into `webhook_receipts.raw_body`, which contains the customer's name, email, billing address, phone number, and order line-items. Headers are correctly redacted by `WebhookReceipt::redactHeaders()` but the body is intact by design.

  `routes/console.php` defines 11 retention prune schedules (audit_log 365d, integration_events 90d, sync_errors 90d, sync_diffs conditional, csv-prune 90d, dashboard_snapshots 30d, history 90d, agent-runs 5y) but **no schedule prunes `webhook_receipts`**.
- **Impact:** UK GDPR Article 5(1)(e) storage-limitation principle. After 2-3 years of trading volume this table will hold tens of thousands of rows of unredacted customer data forever. The `gdpr:erase-bitrix-customer` flow (Phase 4 D-13) scrubs Bitrix + AgentRun rows but does NOT touch webhook_receipts.
- **Fix:**
  1. Add a `webhook-receipts:prune --days=N` artisan command. Default `N=90` matches integration_events retention.
  2. Schedule it in `routes/console.php` in the 03:00-04:00 cascade.
  3. Extend `GdprEraseBitrixCustomerCommand` to also scrub `webhook_receipts.raw_body` rows referencing the erased customer's email (JSON_SEARCH the body for the lowercase email, replace match with `REDACTED-{sha256-prefix}` — mirrors `AgentRunGdprScrubber` pattern).
- **References:** UK GDPR Art. 5(1)(e); OWASP A09:2021 Security Logging and Monitoring Failures (over-logging side)

### LOW (6 findings)

#### LOW-01: `ReadBrandStyleGuideTool` accepts free-form `brand` without path-sanitisation
- **File:** `app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php:73-87`
- **Domain:** File security
- **Description:** The Phase 12 SEO agent's `read_brand_style_guide` tool takes a `brand` string from the LLM and constructs a path:
  ```php
  $slug = strtolower(trim($brand));
  $perBrandPath = resource_path("agents/brand-voice/{$slug}.md");
  ```
  No filter for `..`, `/`, `\` or null bytes. If the LLM emits `"../config/database"` the resolved path becomes `resources/config/database.md`. In practice the `.md` suffix and `is_file()` check prevent reading anything outside `resources/agents/brand-voice/`, but the defence depends on no `.md` file existing elsewhere under `resources/`.
- **Impact:** Today: bounded to nil — the SeoAgent is `TrustTier::Trusted` (internal data only), `brand` is generated by the LLM at tool-call time but seeded by `RunSeoAgentJob::brandSlug($product)` which goes through `BrandSlugResolver` reading the authoritative `brands.slug` column. Future Untrusted-tier agents that import this tool will inherit the gap.
- **Fix:**
  ```php
  $slug = strtolower(trim($brand));
  if (! preg_match('/^[a-z0-9_\-]+$/', $slug)) {
      return json_encode(['brand' => $brand, 'source' => 'global', 'content' => '', '_bytes' => 0], JSON_THROW_ON_ERROR);
  }
  ```
- **References:** CWE-22 Path Traversal (defence-in-depth)

#### LOW-02: BCRYPT rounds at default 12 (good) but no explicit password policy
- **File:** `.env.example:16`, no `App\Rules\PasswordPolicy` class
- **Domain:** Authentication & Session
- **Description:** Breeze's default password validator is `min:8` only. No requirement for digits, symbols, or password-history; no breach-corpus check. Operator passwords gate the entire ops platform.
- **Impact:** A weak ops password unlocks the entire `/admin` panel including IntegrationCredential reads (encrypted at rest but visible to the admin who can also revealable() inspect them via the Filament form).
- **Fix:** Use Laravel 12's `Password` validator with `min(12)->mixedCase()->numbers()->symbols()->uncompromised()` in the Breeze registration/profile controllers. Document in `docs/ops/admin-onboarding.md`.
- **References:** OWASP ASVS V2.1; NIST SP 800-63B

#### LOW-03: No MFA on admin login
- **File:** `app/Providers/Filament/AdminPanelProvider.php:171-173`
- **Domain:** Authentication & Session
- **Description:** Filament login uses single-factor email + password. `BITRIX_WEBHOOK_URL` (full secret in plaintext on the IntegrationCredential ciphertext column) and the ability to flip `WOO_WRITE_ENABLED=true` via Filament settings are protected by that single factor.
- **Impact:** Phishing of an admin account leaks the full ops platform. Bounded by Filament's built-in rate-limiter on login attempts.
- **Fix:** Adopt `filament/breezy` or `stechstudio/filament-impersonate` for TOTP MFA at minimum for the `admin` role. Roadmap candidate, not a cutover blocker.
- **References:** OWASP A07:2021 Identification and Authentication Failures

#### LOW-04: Filament `revealable()` password fields on IntegrationCredentialResource
- **File:** `app/Domain/Integrations/Filament/Resources/IntegrationCredentialResource.php:158-163`
- **Domain:** Secrets management
- **Description:** Edit form for IntegrationCredential password fields uses `->password()->revealable()`. While the policy restricts the page to admin, `revealable()` lets the admin re-display the secret in the browser — a shoulder-surf / over-the-shoulder Slack-screenshot risk.
- **Impact:** Low. Secret is already in admin's hands by virtue of being able to write it; the additional risk is unintended display during screen-share or screenshot.
- **Fix:** Remove `->revealable()` — admins who genuinely need to recover a credential should rotate it. The current edit-form pattern of "leave blank to keep, type to replace" already supports this without ever displaying the stored secret.
- **References:** Defence-in-depth; OWASP ASVS V8.1

#### LOW-05: `agents:gdpr-purge-langfuse` is a STUB
- **File:** Referenced in `bootstrap/app.php:606-609` and `AppServiceProvider`; command class `AgentsGdprPurgeLangfuseCommand`
- **Domain:** Logging & PII
- **Description:** Operator-callable command that's documented as a stub: "STUB per Open Question Q1 RESOLVED ... v2.1 swaps to live API once Langfuse retention API stabilises." Langfuse traces persist customer-bearing tool_call inputs from Phase 14 Untrusted-tier agents.
- **Impact:** GDPR erasure requests cannot reach Langfuse-stored traces today. Bounded by Phase 14 not yet shipping; once chatbot lands, this becomes a true gap.
- **Fix:** Track in Phase 14 acceptance criteria. Operator-facing release notes should call out that Langfuse retention is manually managed until v2.1.
- **References:** UK GDPR Art. 17

#### LOW-06: BudgetGuard reserve-vs-spend race window
- **File:** `app/Domain/Agents/Services/BudgetGuard.php:38-46`
- **Domain:** Race conditions
- **Description:** Documented in the class header — two concurrent agent runs can both pass `assertHasBudget()` and both spend, exceeding the kind's daily cap by up to one extra run. The author cites `agents-supervisor maxProcesses=2` (Plan 01) and `withMaxSteps(8)` per-run loop ceiling as the bounding mechanism.
- **Impact:** £0.50 max over-budget per kind per day. Acceptable per the documented CONTEXT D-01 trade-off.
- **Fix:** None required pre-cutover. Re-evaluate at v2.1 if `maxProcesses` is raised above 2 OR average call cost crosses £0.50. A `Cache::lock()`-wrapped read-modify-write would close the window at a cost of one Redis round-trip per pre-flight.
- **References:** Plan 8 P12-E pitfall (already documented in `08-CONTEXT.md`)

### INFORMATIONAL (5 hardening recommendations)

#### INFO-01: Add Content-Security-Policy + HSTS headers
- **Domain:** Configuration hardening
- **Description:** No Laravel middleware emits `Content-Security-Policy`, `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, or `Referrer-Policy: strict-origin-when-cross-origin`. Filament renders a strict-content profile by default but the headers anchor defence at the browser.
- **Fix:** Either register a `SecurityHeaders` middleware in `bootstrap/app.php` `append()` or configure nginx to inject. CSP needs Filament-specific allowlist (Livewire, Alpine, htmx) — start with `Content-Security-Policy-Report-Only` for a week.

#### INFO-02: Verify `failed_jobs` table retention
- **Domain:** Logging & PII
- **Description:** `routes/console.php` does not prune `failed_jobs`. Spatie/laravel-failed-job-monitor sends alerts but the table grows unbounded. Payloads can hold customer order data passed into `PushOrderToBitrixJob` etc.
- **Fix:** Add a `queue:prune-failed` schedule, e.g. `Schedule::command('queue:prune-failed --hours=8640')` for 12-month retention.

#### INFO-03: APP_KEY rotation runbook lives in code comments only
- **Domain:** Secrets management
- **Description:** Two model docblocks reference an APP_KEY rotation runbook (`IntegrationCredential.php:30-34`, `CompetitorFtpCredential.php:28-33`) pointing to `docs/ops/integration-credentials.md`. The doc file's presence/content is out of scope for this audit pass; confirm it exists and is up-to-date before cutover.

#### INFO-04: No `health:check` beyond `/up`
- **Domain:** Configuration hardening
- **Description:** Laravel's `/up` route returns a literal 200. There's no deeper liveness/readiness check that verifies DB + Redis + Horizon supervisor health. For a cutover-monitoring window this is the operator's primary "is it alive" surface.
- **Fix:** Custom `/health` route gated on a header secret, returns JSON with DB ping, Redis ping, Horizon master process alive, latest scheduled command success timestamps. Out of scope for cutover but useful for the 7-day parity window.

#### INFO-05: Sentry DSN configured but no PII scrubbing rule documented
- **Domain:** Logging & PII
- **Description:** `sentry/sentry-laravel` is in `composer.json`. `.env.example` has `SENTRY_LARAVEL_DSN=` and `SENTRY_TRACES_SAMPLE_RATE=0.1` but no documented `send_default_pii` / `before_send` callback to strip customer emails/phones from exception breadcrumbs.
- **Fix:** Publish `config/sentry.php` with explicit `send_default_pii: false` and a `before_send` callback that scrubs the same PII keys as `AgentRunGdprScrubber::PII_KEYS`.

## Domain Coverage Matrix

| Domain | Status | Notes |
|---|---|---|
| Authentication & Session | 2 LOW, 1 HIGH | LOW-02 password policy, LOW-03 MFA, HIGH-01 canAccessPanel |
| Authorization | 1 HIGH | HIGH-01 canAccessPanel; every Policy + Filament Action ->authorize() otherwise correct |
| Input validation | clean | Form requests + Filament forms use Rules; webhook body HMAC-bound; CSV ingest column-mapping admin-gated |
| SQL injection | clean | All `whereRaw`/`selectRaw` are parameterised with `?` placeholders or static SQL. `SupplierDbSyncCommand` mysqli paths use prepared statements with `bind_param` |
| XSS / output encoding | clean | No `Blade::render`, no `{!! !!}` on user input (the one occurrence wraps `e()` first), no `HtmlString`/`->html()`, no Filament `formatStateUsing` returning HTML |
| CSRF | clean | `webhooks/*` correctly excluded; Filament panel uses `VerifyCsrfToken` in its middleware stack; webhook routes have HMAC instead |
| Secrets management | 1 LOW | LOW-04 revealable() on creds; `.env` in .gitignore; `.env.example` ships placeholder-only; `IntegrationCredential.payload_encrypted` uses Laravel 12 AES-256 native; LogsActivity allow-lists exclude ciphertext columns |
| External API security | 2 MEDIUM | MED-02 SFTP host fingerprint, MED-03 FTPS verify_ssl opt-out. WooClient / BitrixClient / SupplierClient all use IntegrationLogger redaction, `verify_ssl=true` in production, timeouts set |
| File security | 1 LOW | LOW-01 ReadBrandStyleGuideTool brand-slug. ExportDownloadController uses signed URL + basename. CsvIngestIssuesPage::locateQuarantinedFile uses basename and path-scoped scandir |
| AI/Agent-specific | 1 LOW | LOW-06 BudgetGuard race (documented + bounded). Trusted-tier guardrails per design; sensitive-field strip + outbound regex filter both present; XML-fence pre-flight for non-Trusted; mb_substr caps on all tool I/O |
| Webhook security | clean | HMAC-SHA256 via `hash_equals`, raw body preserved by middleware ordering, unique (source, delivery_id) dedup, Sensitive headers redacted by `WebhookReceipt::redactHeaders` |
| Configuration hardening | 1 MEDIUM, 1 INFO | MED-01 trustProxies/secure cookies, INFO-01 security headers. APP_DEBUG/APP_ENV default to production in .env.example |
| Logging & PII | 1 MEDIUM, 1 LOW, 1 INFO | MED-04 webhook_receipts retention; LOW-05 Langfuse purge stub; INFO-02 failed_jobs prune. `AgentRunGdprScrubber` + `gdpr:erase-bitrix-customer` cover Bitrix + AgentRuns |
| Race conditions | 1 LOW | LOW-06 BudgetGuard. `webhook_receipts` unique index for dedup correct; `sync_run_items` ledger uses transactions |
| Dependency CVEs | clean | `composer audit --no-dev` returned "No security vulnerability advisories found" as of 2026-05-17 |

## Deployment Readiness Verdict

**PASS_WITH_FIXES**

The codebase is fundamentally well-built; no Critical findings would block cutover. However, before flipping `WOO_WRITE_ENABLED=true` ops should land the following fixes in priority order:

1. **(HIGH-01) Tighten `User::canAccessPanel()`** — 5 lines, no tests need changing because per-Resource policies are the load-bearing gate. Belt-and-braces enforcement at the panel level. **Cutover blocker.**
2. **(MED-01) Configure trustProxies + SESSION_SECURE_COOKIE=true + URL::forceScheme('https') in production** — one-line trust call, two env-flag flips, three-line provider boot. **Cutover blocker if `ms.21stcav.com` sits behind nginx (it does).**
3. **(MED-04) Schedule `webhook-receipts:prune` + extend GDPR erasure to cover webhook_receipts** — UK GDPR storage-limitation principle. Not a cutover blocker (no live writes yet) but should ship within the 7-day parity window.

Defer to v2.1+ backlog:
- (MED-02) SFTP host fingerprint pinning — operator process workaround until then: manually verify on first connect
- (MED-03) FTPS verify_ssl opt-out env-gate — operator process workaround: track which credentials have it disabled and audit weekly
- (LOW-01, LOW-02, LOW-03, LOW-04, LOW-05, LOW-06) — backlog candidates for security hardening sprint
- (INFO-01 through INFO-05) — incremental hardening, not gating

## Out-of-Scope / Future Considerations

- **Phase 14 chatbot will introduce TrustTier::Untrusted with customer input.** The Phase 8 guardrail chain (PromptInjectionXmlFenceGuardrail + SensitiveFieldsStripGuardrail + OutboundRegexFilterGuardrail) has not been load-tested against adversarial customer prompts. Re-audit the agent framework at the end of Phase 14, with specific focus on:
  - Prompt-injection bypasses (Anthropic's evolving threat-model docs)
  - Tool-output sanitisation when the LLM transcribes user-supplied free-form text
  - LangFuse retention + GDPR purge (LOW-05 must be live, not stub)
- **Phase 11 quote PDF** uses spatie/laravel-pdf with DOMPDF — DOMPDF has its own historical CVE surface. Watch for `dompdf/dompdf` advisories during the cutover window.
- **`b24phpsdk` v1.10 is pinned** because v3.x requires PHP 8.4. If a security advisory lands on v1.10, the PHP 8.4 floor upgrade becomes a forced migration.
- **WhatsApp Business + AI product-finder chatbot (Phase 13, 14)** introduce customer-facing endpoints — a full re-audit covering CSRF, rate-limiting, and per-customer authorization is required when those phases hit code review.
- **RAMS cross-project FK (E5)** if/when it ships will introduce a new trust boundary between this app and `rams.21stcav.com`. Audit the shared-customer surface (read-only FK, signed-URL handshake, or full-API auth) at that time.

---
_Audit completed 2026-05-17 by gsd-code-reviewer._
_Methodology: read-only static analysis. Files spot-read: ~70 production sources covering bootstrap, routes, middleware, all 13 Policy classes, the 4 most-sensitive Filament Resources, all 4 agent Guardrails, BudgetGuard, IntegrationCredentialResolver, IntegrationLogger, WooClient, BitrixClient, SupplierDbSyncCommand, FtpSourceConnector, CsvIngestIssuesPage, WooWebhookController, VerifyWooHmacSignature, AttachCorrelationId, BrandSlugResolver, AgentRunGdprScrubber, and all 4 Tool implementations. Full-tree pattern scans for: eval/assert/unserialize/extract on user input, whereRaw/selectRaw/DB::raw, Blade `{!! !!}` raw output, shell exec, hardcoded secrets, raw XML parsers, raw mail()._
_Composer audit: `composer audit --no-dev` returned zero advisories against `composer.lock` (Laravel 12.x + Filament 3.3 + Prism 0.100.1 + b24phpsdk 1.10.0 + spatie/laravel-permission ^6 + spatie/laravel-activitylog ^4.12)._
