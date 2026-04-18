---
phase: 01-foundation
plan: 04
type: execute
wave: 3
depends_on: [01-01-scaffold, 01-02-rbac, 01-03-foundation]
files_modified:
  - database/migrations/2026_04_18_105000_create_webhook_receipts_table.php
  - database/migrations/2026_04_18_107000_create_suggestions_table.php
  - database/migrations/2026_04_18_108000_create_sync_diffs_table.php
  - app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php
  - app/Domain/Webhooks/Http/Controllers/WooWebhookController.php
  - app/Domain/Webhooks/Models/WebhookReceipt.php
  - app/Domain/Webhooks/Events/OrderReceived.php
  - app/Domain/Webhooks/Events/CustomerRegistered.php
  - app/Domain/Suggestions/Models/Suggestion.php
  - app/Domain/Suggestions/Contracts/SuggestionApplier.php
  - app/Domain/Suggestions/Services/SuggestionApplierResolver.php
  - app/Domain/Suggestions/Appliers/StubApplier.php
  - app/Domain/Suggestions/Jobs/ApplySuggestionJob.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ListSuggestions.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ViewSuggestion.php
  - app/Domain/Suggestions/Policies/SuggestionPolicy.php
  - app/Domain/Sync/Models/SyncDiff.php
  - app/Domain/Sync/Services/WooClient.php
  - app/Providers/AppServiceProvider.php
  - config/services.php
  - routes/webhooks.php
  - database/seeders/TestSuggestionSeeder.php
  - database/seeders/DatabaseSeeder.php
  - tests/Feature/WooWebhookTest.php
  - tests/Feature/ShadowModeTest.php
  - tests/Feature/SuggestionInboxTest.php
  - tests/Feature/WebhookReceiptRedactionTest.php
  - tests/Feature/SuggestionResourceQueryCountTest.php
autonomous: true
requirements:
  - FOUND-06
  - FOUND-07
  - FOUND-08
user_setup: []

must_haves:
  truths:
    - "`webhook_receipts` table exists with unique index on `(source, delivery_id)` — retries of same Woo delivery are idempotent"
    - "`suggestions` table exists with ulid primary key and indexes on `(kind, status)`, `(correlation_id)`, `(status, proposed_at)` per D-14"
    - "`sync_diffs` table exists with columns: id, channel, method, endpoint, woo_id (indexed), payload, correlation_id (indexed), created_at, applied_at, status"
    - "`VerifyWooHmacSignature` middleware computes HMAC-SHA256 + base64 on `$request->getContent()` (raw body) and uses `hash_equals()` for timing-safe comparison"
    - "Route `POST /webhooks/woo/order` is registered under the HMAC middleware and hits `WooWebhookController::order`"
    - "Posting an unsigned request to `/webhooks/woo/order` returns 401; a correctly-signed request returns 200 and creates one `webhook_receipts` row"
    - "Posting the SAME signed request twice creates ONE row (unique constraint on delivery_id) and returns `{status: duplicate}` on the second call"
    - "`WooClient::put()` checks `config('services.woo.write_enabled')` first; when false, writes a `SyncDiff` row and returns `['shadow_mode' => true, 'diff_id' => ...]`"
    - "With `WOO_WRITE_ENABLED=false` (the default), a feature test confirms the Automattic Woo client is NEVER called"
    - "`SuggestionApplier` contract exists and `StubApplier` implements it (`supports()` returns `['test']`)"
    - "`SuggestionApplierResolver::resolve($suggestion)` returns the registered applier for the suggestion's `kind`"
    - "`AppServiceProvider::boot()` registers `StubApplier` for kind `test`"
    - "`ApplySuggestionJob` is idempotent — firing it twice on an already-`applied` suggestion is a no-op (no duplicate integration_events row)"
    - "Filament `SuggestionResource` is discoverable via `app/Domain/Suggestions/Filament/Resources/` path (Plan 02 added this path to AdminPanelProvider)"
    - "`SuggestionPolicy` gates `viewAny` / `view` / `update` to users with `view_any_suggestion` permission (Shield-gated — admin-only per Pitfall K)"
    - "`TestSuggestionSeeder` seeds ONE suggestion with `kind='test'`, `status='pending'` — visible in the Filament inbox"
    - "Approving the seeded test suggestion via Filament dispatches `ApplySuggestionJob`, which (synchronously, in tests) flips status to `applied` and writes an `integration_events` row with `operation='apply:test'`"
    - "HMAC-path latency from route entry → event dispatch is under 200ms in the feature test (FOUND-07 acceptance)"
    - "After `ApplySuggestionJob` runs, `integration_events.subject_id` equals the Suggestion ULID (CHAR(26)) and `$event->subject` morph-resolves back to the Suggestion model (Warning 8 — nullableUlidMorphs end-to-end)"
    - "Attempting the approve or reject Action as a `read_only` user via Livewire returns 403; the suggestion `status` remains `pending` (Warning 9 — ->authorize() gate beats ->visible() for defence-in-depth)"
    - "POSTing a webhook with a leaked `Authorization` or `Cookie` header lands a `webhook_receipts` row with those header values replaced by `['***REDACTED***']`, while non-sensitive headers (Content-Type, User-Agent) survive verbatim (Gemini Concern MEDIUM — inbound header redaction parity with IntegrationLogger)"
    - "Listing 10 resolved Suggestion rows in the Filament SuggestionResource executes a bounded number of queries (eager-load via getEloquentQuery), NOT one query per row to fetch resolvedByUser — proven by DB::getQueryLog() count assertion (Gemini Concern MEDIUM — N+1 prevention)"
  artifacts:
    - path: "database/migrations/2026_04_18_105000_create_webhook_receipts_table.php"
      provides: "webhook_receipts table + unique (source, delivery_id) constraint"
    - path: "database/migrations/2026_04_18_107000_create_suggestions_table.php"
      provides: "suggestions table with ulid id + indexes per D-14"
    - path: "database/migrations/2026_04_18_108000_create_sync_diffs_table.php"
      provides: "sync_diffs shadow-mode table"
    - path: "app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php"
      provides: "HMAC-SHA256 + base64 + hash_equals verification"
      contains: "hash_hmac('sha256'"
    - path: "app/Domain/Webhooks/Http/Controllers/WooWebhookController.php"
      provides: "≤20-line controller that logs receipt + fires event + returns 200"
      contains: "WebhookReceipt::create"
    - path: "app/Domain/Suggestions/Contracts/SuggestionApplier.php"
      provides: "Interface: supports(): array, apply(Suggestion): array"
      exports: ["SuggestionApplier"]
    - path: "app/Domain/Suggestions/Appliers/StubApplier.php"
      provides: "No-op applier for kind=test (Phase 1 acceptance)"
    - path: "app/Domain/Suggestions/Services/SuggestionApplierResolver.php"
      provides: "Registry + resolver keyed by kind"
    - path: "app/Domain/Suggestions/Jobs/ApplySuggestionJob.php"
      provides: "Queued dispatch from Suggestion::approve, idempotent"
    - path: "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php"
      provides: "Filament inbox Resource with approve/reject actions"
    - path: "app/Domain/Suggestions/Policies/SuggestionPolicy.php"
      provides: "Shield-compatible policy (admin-only)"
    - path: "app/Domain/Sync/Services/WooClient.php"
      provides: "Shadow-mode gate + IntegrationLogger + SyncDiff fallback"
      contains: "services.woo.write_enabled"
    - path: "app/Domain/Sync/Models/SyncDiff.php"
      provides: "Eloquent model over sync_diffs (append-only, timestamps disabled)"
    - path: "config/services.php"
      provides: "woo block with webhook_secret + write_enabled + consumer_key/secret placeholders"
      contains: "write_enabled"
    - path: "routes/webhooks.php"
      provides: "Route group /webhooks/woo/* with HMAC middleware"
  key_links:
    - from: "routes/webhooks.php"
      to: "VerifyWooHmacSignature middleware + WooWebhookController"
      via: "Route::prefix('webhooks/woo')->middleware([VerifyWooHmacSignature::class])"
      pattern: "VerifyWooHmacSignature"
    - from: "app/Domain/Webhooks/Http/Controllers/WooWebhookController.php"
      to: "webhook_receipts table"
      via: "WebhookReceipt::create"
      pattern: "WebhookReceipt::create"
    - from: "app/Domain/Webhooks/Http/Controllers/WooWebhookController.php"
      to: "OrderReceived event"
      via: "event(new OrderReceived(...))"
      pattern: "event\\(new OrderReceived"
    - from: "app/Domain/Suggestions/Jobs/ApplySuggestionJob.php"
      to: "IntegrationLogger"
      via: "$logger->log([...])"
      pattern: "IntegrationLogger"
    - from: "app/Domain/Sync/Services/WooClient.php"
      to: "SyncDiff model"
      via: "SyncDiff::create when shadow mode"
      pattern: "SyncDiff::create"
    - from: "app/Providers/AppServiceProvider.php"
      to: "SuggestionApplierResolver + StubApplier registration"
      via: "app(SuggestionApplierResolver::class)->register('test', StubApplier::class)"
      pattern: "register\\('test'"
---

<objective>
Ship the three feature seams that every Phase 2+ producer plugs into:
1. **HMAC webhook intake (FOUND-07):** `/webhooks/woo/order` + `/webhooks/woo/customer` routes behind `VerifyWooHmacSignature`, persisting raw body to `webhook_receipts` with `(source, delivery_id)` dedup, firing `OrderReceived` / `CustomerRegistered` events, returning 200 in <200ms.
2. **Suggestions seam (FOUND-06):** `suggestions` table + `SuggestionApplier` contract + `StubApplier` for kind=`test` + `ApplySuggestionJob` idempotent on `status=applied` + Filament `SuggestionResource` with approve/reject actions + admin-only `SuggestionPolicy` (Pitfall K mitigation) + seeded test suggestion.
3. **Shadow-mode Woo write gate (FOUND-08):** `sync_diffs` table + `WooClient` skeleton that reads `services.woo.write_enabled`; when false, writes a `SyncDiff` row instead of calling Woo; feature test asserts the Automattic client is never called in shadow mode.

Purpose: These three seams are the "no retrofit required" promise of Phase 1. Plan 05 alone can't satisfy Success Criteria 3, 4, or 6 without this plan.

Output: A working HMAC endpoint, a working suggestions inbox with the seeded test suggestion, and a provably non-writing `WooClient` with every call landing in `sync_diffs`.

> **Scope note (large plan):** This plan covers FOUND-06 + FOUND-07 + FOUND-08 across ~27 files in 3 tasks. Expect longer execution than other Phase 1 plans. **Checkpoint recommendation:** If execution exceeds 2h, pause after Task 1 (HMAC middleware + webhook_receipts) before continuing to Task 2 (suggestions seam) and Task 3 (shadow-mode WooClient). The three tasks are independent seams and can be resumed discretely.
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
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md

<interfaces>
<!-- Produced by Plan 03 that this plan consumes: -->

From Plan 03 (Foundation layer):

```php
// Already exists — this plan USES these, does not modify them:
\App\Http\Middleware\AttachCorrelationId         // on web + api groups
\App\Foundation\Events\DomainEvent                // base class for OrderReceived / CustomerRegistered
\App\Foundation\Audit\Services\Auditor            // Auditor::record($action, $ctx)
\App\Foundation\Integration\Services\IntegrationLogger  // ->log([...]) with auto-redaction + correlation_id

// Available facades:
Illuminate\Support\Facades\Context               // Context::get('correlation_id')
Spatie\Activitylog\Facades\LogBatch               // auto-set by Context::hydrated callback
```

<!-- Contracts this plan ships for Phase 5 (first real producer) to consume: -->

```php
// app/Domain/Suggestions/Contracts/SuggestionApplier.php
interface SuggestionApplier
{
    public function supports(): array;
    public function apply(Suggestion $suggestion): array;
}

// Usage pattern (Phase 5 MarginChangeApplier extends this):
// 1. Register in AppServiceProvider::boot():
//    app(SuggestionApplierResolver::class)->register('margin_change', MarginChangeApplier::class);
// 2. Fire from producer:
//    Suggestion::create(['kind' => 'margin_change', 'payload' => [...], 'correlation_id' => Context::get('correlation_id')]);
// 3. Admin approves via Filament → ApplySuggestionJob::dispatch($suggestion->id)
// 4. Job resolves applier, calls apply(), writes integration_events row, updates status → 'applied'
```

From 01-RESEARCH.md §3 (HMAC middleware + bootstrap/app.php route registration):

```php
// VerifyWooHmacSignature middleware — critical: use $request->getContent() (raw bytes)
$expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
abort_unless(hash_equals($expected, $signature), 401, 'Invalid signature');
```

From 01-RESEARCH.md §3 (webhook_receipts schema):

```php
Schema::create('webhook_receipts', function (Blueprint $t) {
    $t->id();
    $t->string('source', 32);
    $t->string('topic', 64);
    $t->string('delivery_id', 128);
    $t->json('headers');
    $t->longText('raw_body');
    $t->string('correlation_id', 36)->index();
    $t->timestamp('received_at');
    $t->timestamp('processed_at')->nullable();
    $t->string('status', 16)->default('received');
    $t->text('error_message')->nullable();
    $t->timestamps();

    $t->unique(['source', 'delivery_id']);
    $t->index(['source', 'topic', 'received_at']);
});
```

From 01-RESEARCH.md §7 (suggestions schema + applier + resolver + job + Filament Resource):

```php
Schema::create('suggestions', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->string('kind', 64);
    $t->string('status', 16)->default('pending');
    $t->string('correlation_id', 36)->index();
    $t->json('payload');
    $t->json('evidence')->nullable();
    $t->nullableMorphs('proposed_by');
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

From 01-RESEARCH.md §8 (sync_diffs schema + WooClient gate):

```php
Schema::create('sync_diffs', function (Blueprint $t) {
    $t->id();
    $t->string('channel', 32)->default('woo');
    $t->string('method', 8);
    $t->string('endpoint', 255);
    $t->string('woo_id', 64)->nullable()->index();
    $t->json('payload');
    $t->string('correlation_id', 36)->index();
    $t->timestamp('created_at');
    $t->timestamp('applied_at')->nullable();
    $t->string('status', 16)->default('pending');
});
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Woo → `/webhooks/woo/*` | Unauthenticated internet surface; HMAC is the ONLY gate |
| Filament admin user → `SuggestionResource` approve action | Policy gate via Shield (admin-only per Pitfall K) |
| `WooClient::put()` caller → Woo REST | Shadow-mode flag is the write-safety gate (FOUND-08) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-04-01 | S | Forged webhook requests without HMAC | mitigate | `VerifyWooHmacSignature` returns 401 on missing/invalid signature; Pitfall A addressed by registering the middleware at the BEGINNING of the per-route middleware stack (before AttachCorrelationId reads body, though getContent() is idempotent the first read consumes the input stream — use `file_get_contents('php://input')` as belt-and-braces read source) |
| T-04-02 | T | Timing attack on HMAC comparison | mitigate | `hash_equals($expected, $signature)` constant-time compare (not `===`) |
| T-04-03 | R | Replay of captured webhook delivery | mitigate | Unique DB index `(source, delivery_id)` — retry of same `X-WC-Webhook-Delivery-ID` returns `{status: duplicate}` and never re-dispatches the event |
| T-04-04 | T | Body tampering via JSON re-encoding | mitigate | HMAC computed over `$request->getContent()` (raw bytes); controller stores the same `getContent()` value in `raw_body` column. No middleware between HMAC check and controller calls `->json()`/`->all()` |
| T-04-05 | E | Unauthenticated user reaching `SuggestionResource` | mitigate | Shield-wired `SuggestionPolicy`; Pitfall K feature test asserts `sales` + `pricing_manager` + `read_only` roles all 403 on `/admin/suggestions` (admin-only) |
| T-04-06 | I | Ghost write to Woo when shadow-mode flag misread | mitigate | WooClient `put()/post()/patch()/delete()` methods each call `config('services.woo.write_enabled', false)` as the FIRST line; feature test with `$this->spy(Automattic\WooCommerce\Client::class)` asserts zero method invocations when `write_enabled=false` |
| T-04-07 | R | Replay of approved-but-failed ApplySuggestionJob creating duplicate side effects | mitigate | Job handle() begins with `if ($suggestion->status === 'applied') return;` guard (D-15); `StubApplier::apply()` is also idempotent by design (no external calls) |
| T-04-08 | I | WC_WEBHOOK_SECRET leaked via integration_events row | mitigate | IntegrationLogger (from Plan 03) redacts `x-wc-webhook-signature` header; Controller does NOT log the secret itself — only the signature header value which is HMAC-derived and safe to log redacted |
| T-04-09 | D | Large JSON payload in suggestion.payload exhausting DB row size | accept | MySQL JSON column handles ~4KB comfortably; migration uses `json` type (not `longText`) — acceptable for Phase 1; monitor in Phase 5 |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Migrations + models for webhook_receipts + suggestions + sync_diffs; write config/services.php woo block; write SyncDiff + WooClient skeleton + ShadowModeTest</name>
  <files>database/migrations/2026_04_18_105000_create_webhook_receipts_table.php, database/migrations/2026_04_18_107000_create_suggestions_table.php, database/migrations/2026_04_18_108000_create_sync_diffs_table.php, app/Domain/Webhooks/Models/WebhookReceipt.php, app/Domain/Suggestions/Models/Suggestion.php, app/Domain/Sync/Models/SyncDiff.php, app/Domain/Sync/Services/WooClient.php, config/services.php, tests/Feature/ShadowModeTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §3 (webhook_receipts schema), §7 (suggestions schema), §8 (sync_diffs schema + WooClient skeleton + feature test pattern)
    - .planning/phases/01-foundation/01-CONTEXT.md D-08 (sync_diffs never prune while WOO_WRITE_ENABLED=false), D-14 (suggestions column list + indexes)
    - .planning/research/PITFALLS.md Pitfall H (automattic/woocommerce 10s timeout), Pitfall L (sync_diffs prune while shadow active)
    - config/services.php (current — Laravel default has stripe/postmark sections; do NOT remove those; add `woo` section)
    - .env.example (confirm WOO_WRITE_ENABLED=false already present from Plan 01)
  </read_first>
  <behavior>
    - Test: `WooClient::put('products/1234', ['regular_price' => '99.99'])` with `services.woo.write_enabled=false` creates ONE SyncDiff row with method=PUT, endpoint='products/1234', woo_id='1234', payload contains regular_price
    - Test: In the same scenario, `spy(Automattic\WooCommerce\Client::class)` records zero calls to put/post/patch/delete
    - Test: Return value is `['shadow_mode' => true, 'diff_id' => <int>]`
    - Test: Running `php artisan migrate` creates webhook_receipts, suggestions, sync_diffs tables
    - Test: suggestions table has ulid primary (Str::ulid() generates valid ID that inserts cleanly)
    - Test: webhook_receipts has unique constraint on (source, delivery_id) — second insert with same pair throws QueryException
  </behavior>
  <action>
    **Step A — Write `database/migrations/2026_04_18_105000_create_webhook_receipts_table.php`** (verbatim from 01-RESEARCH.md §3):

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
            Schema::create('webhook_receipts', function (Blueprint $t) {
                $t->id();
                $t->string('source', 32);              // 'woo' | 'bitrix' (future)
                $t->string('topic', 64);                // 'order.created' | 'customer.updated' | 'order' | 'customer'
                $t->string('delivery_id', 128);         // X-WC-Webhook-Delivery-ID
                $t->json('headers');                    // full header dump (sensitive redacted by caller)
                $t->longText('raw_body');               // raw payload bytes as received
                $t->string('correlation_id', 36)->index();
                $t->timestamp('received_at');
                $t->timestamp('processed_at')->nullable();
                $t->string('status', 16)->default('received'); // 'received' | 'processed' | 'failed'
                $t->text('error_message')->nullable();
                $t->timestamps();

                $t->unique(['source', 'delivery_id']);
                $t->index(['source', 'topic', 'received_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('webhook_receipts');
        }
    };
    ```

    **Step B — Write `database/migrations/2026_04_18_107000_create_suggestions_table.php`** (verbatim from D-14):

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
            Schema::create('suggestions', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->string('kind', 64);                  // 'margin_change' | 'crm_push_failed' | 'new_product' | 'test'
                $t->string('status', 16)->default('pending'); // 'pending'|'approved'|'rejected'|'applied'|'failed'
                $t->string('correlation_id', 36)->index();
                $t->json('payload');
                $t->json('evidence')->nullable();
                $t->nullableMorphs('proposed_by');
                $t->timestamp('proposed_at');
                $t->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('resolved_at')->nullable();
                $t->text('rejection_reason')->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->timestamps();

                $t->index(['kind', 'status']);
                $t->index(['status', 'proposed_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('suggestions');
        }
    };
    ```

    **Step C — Write `database/migrations/2026_04_18_108000_create_sync_diffs_table.php`** (verbatim from §8):

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
            Schema::create('sync_diffs', function (Blueprint $t) {
                $t->id();
                $t->string('channel', 32)->default('woo');
                $t->string('method', 8);
                $t->string('endpoint', 255);
                $t->string('woo_id', 64)->nullable()->index();
                $t->json('payload');
                $t->string('correlation_id', 36)->index();
                $t->timestamp('created_at');
                $t->timestamp('applied_at')->nullable();
                $t->string('status', 16)->default('pending'); // 'pending' | 'applied' | 'superseded'
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('sync_diffs');
        }
    };
    ```

    **Step D — Write Eloquent models:**

    `app/Domain/Webhooks/Models/WebhookReceipt.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Webhooks\Models;

    use Illuminate\Database\Eloquent\Model;

    class WebhookReceipt extends Model
    {
        /** Sensitive header names (lower-cased) — value replaced with ['***REDACTED***'] before persist.
         *  Mirrors IntegrationLogger::SENSITIVE_HEADERS for inbound parity (Gemini Concern MEDIUM).
         *  X-WC-Webhook-Signature is HMAC output (not a raw secret) but redacted defensively in case
         *  Woo or a misbehaving proxy ever forwards an Authorization / Cookie / X-Api-Key header.
         */
        public const SENSITIVE_HEADERS = [
            'authorization',
            'x-wc-webhook-signature',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-auth-token',
            'x-session-token',
        ];

        protected $fillable = [
            'source', 'topic', 'delivery_id',
            'headers', 'raw_body', 'correlation_id',
            'received_at', 'processed_at', 'status', 'error_message',
        ];

        protected $casts = [
            'headers'      => 'array',
            'received_at'  => 'datetime',
            'processed_at' => 'datetime',
        ];

        /**
         * Redact sensitive header values before persisting to webhook_receipts.headers.
         * Mirrors IntegrationLogger::redactHeaders() for inbound defense-in-depth.
         * Caller (WooWebhookController) MUST invoke this on $request->headers->all() before insert.
         */
        public static function redactHeaders(array $headers): array
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

    `app/Domain/Suggestions/Models/Suggestion.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Models;

    use Illuminate\Database\Eloquent\Concerns\HasUlids;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\MorphTo;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use App\Models\User;

    class Suggestion extends Model
    {
        use HasUlids;

        public const STATUS_PENDING  = 'pending';
        public const STATUS_APPROVED = 'approved';
        public const STATUS_REJECTED = 'rejected';
        public const STATUS_APPLIED  = 'applied';
        public const STATUS_FAILED   = 'failed';

        protected $fillable = [
            'kind', 'status', 'correlation_id',
            'payload', 'evidence',
            'proposed_by_type', 'proposed_by_id',
            'proposed_at',
            'resolved_by_user_id', 'resolved_at',
            'rejection_reason',
            'applied_at',
        ];

        protected $casts = [
            'payload'     => 'array',
            'evidence'    => 'array',
            'proposed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'applied_at'  => 'datetime',
        ];

        public function proposedBy(): MorphTo
        {
            return $this->morphTo();
        }

        public function resolvedByUser(): BelongsTo
        {
            return $this->belongsTo(User::class, 'resolved_by_user_id');
        }
    }
    ```

    `app/Domain/Sync/Models/SyncDiff.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Sync\Models;

    use Illuminate\Database\Eloquent\Model;

    class SyncDiff extends Model
    {
        public $timestamps = false; // created_at set explicitly; no updated_at

        protected $fillable = [
            'channel', 'method', 'endpoint', 'woo_id',
            'payload', 'correlation_id',
            'created_at', 'applied_at', 'status',
        ];

        protected $casts = [
            'payload'    => 'array',
            'created_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
    ```

    **Step E — Add `woo` block to `config/services.php`** (append to existing file; do NOT remove default stripe/postmark blocks):

    Add this block at the end of the `return [...]` array:

    ```php
    'woo' => [
        // Phase 2 populates url/consumer_key/consumer_secret; Phase 1 ships placeholders only
        'url'              => env('WOO_URL'),
        'consumer_key'     => env('WOO_CONSUMER_KEY'),
        'consumer_secret'  => env('WOO_CONSUMER_SECRET'),

        // Phase 1 — webhook HMAC secret (Plan 04 middleware reads this)
        'webhook_secret'   => env('WC_WEBHOOK_SECRET'),

        // Phase 1 — shadow-mode flag (D-08; MUST default to false)
        'write_enabled'    => env('WOO_WRITE_ENABLED', false),
    ],

    'woocommerce' => [
        // Alias (some Filament plugins check services.woocommerce.*)
        'webhook_secret'   => env('WC_WEBHOOK_SECRET'),
    ],
    ```

    **Step F — Write `app/Domain/Sync/Services/WooClient.php`** (Phase 1 skeleton — §6 + §8 merged):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Sync\Services;

    use App\Domain\Sync\Models\SyncDiff;
    use App\Foundation\Integration\Services\IntegrationLogger;
    use Illuminate\Support\Facades\Context;

    /**
     * Thin wrapper around Woo REST client — Phase 1 SKELETON.
     *
     * Every write method (put/post/patch/delete) MUST:
     *   1. Check services.woo.write_enabled — if false, record SyncDiff + return
     *   2. If true (post-cutover), call the real Woo client + log to integration_events
     *
     * Phase 2 populates the Automattic\WooCommerce\Client via constructor injection
     * once WOO_URL / WOO_CONSUMER_KEY / WOO_CONSUMER_SECRET are known. Phase 1 passes null.
     *
     * Only this class writes to Woo (per ARCHITECTURE.md anti-pattern 6).
     */
    final class WooClient
    {
        public function __construct(
            private IntegrationLogger $logger,
            private mixed $inner = null  // Automattic\WooCommerce\Client — Phase 2 types this properly
        ) {}

        public function put(string $endpoint, array $payload): array
        {
            return $this->writeOrShadow('PUT', $endpoint, $payload);
        }

        public function post(string $endpoint, array $payload): array
        {
            return $this->writeOrShadow('POST', $endpoint, $payload);
        }

        public function patch(string $endpoint, array $payload): array
        {
            return $this->writeOrShadow('PATCH', $endpoint, $payload);
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            return $this->writeOrShadow('DELETE', $endpoint, $payload);
        }

        private function writeOrShadow(string $method, string $endpoint, array $payload): array
        {
            // FOUND-08: shadow-mode gate. Default is false; flipping to true is a Phase 7 cutover action.
            if (! (bool) config('services.woo.write_enabled', false)) {
                return $this->recordDiff($method, $endpoint, $payload);
            }

            // Phase 1 ships NO real-write path — Phase 2 implements this block.
            throw new \LogicException(
                'Phase 1 WooClient does not support real writes; Phase 2 wires Automattic\\WooCommerce\\Client.'
            );
        }

        private function recordDiff(string $method, string $endpoint, array $payload): array
        {
            $diff = SyncDiff::create([
                'channel'        => 'woo',
                'method'         => $method,
                'endpoint'       => $endpoint,
                'woo_id'         => $this->extractWooId($endpoint),
                'payload'        => $payload,
                'correlation_id' => Context::get('correlation_id') ?? (string) \Illuminate\Support\Str::uuid(),
                'created_at'     => now(),
                'status'         => 'pending',
            ]);

            $this->logger->log([
                'channel'      => 'woo',
                'operation'    => "{$method} {$endpoint}",
                'endpoint'     => $endpoint,
                'method'       => $method,
                'request_body' => $payload,
                'status'       => 'success',
                'http_status'  => 0, // 0 = shadow mode (no real HTTP)
                'response_body'=> ['shadow_mode' => true, 'diff_id' => $diff->id],
            ]);

            return ['shadow_mode' => true, 'diff_id' => $diff->id];
        }

        /** Extract "1234" from "products/1234" or "products/1234/variations/5" → returns "1234". */
        private function extractWooId(string $endpoint): ?string
        {
            if (preg_match('#^[a-z_\-]+/(\d+)(?:/|$)#', $endpoint, $m)) {
                return $m[1];
            }
            return null;
        }
    }
    ```

    **Step G — Write `tests/Feature/ShadowModeTest.php`:**

    ```php
    <?php

    use App\Domain\Sync\Models\SyncDiff;
    use App\Domain\Sync\Services\WooClient;

    beforeEach(function () {
        config(['services.woo.write_enabled' => false]);
    });

    it('records a SyncDiff instead of calling Woo when WOO_WRITE_ENABLED=false', function () {
        $result = app(WooClient::class)->put('products/1234', ['regular_price' => '99.99']);

        expect(SyncDiff::count())->toBe(1);
        $diff = SyncDiff::first();
        expect($diff->method)->toBe('PUT')
            ->and($diff->endpoint)->toBe('products/1234')
            ->and($diff->woo_id)->toBe('1234')
            ->and($diff->channel)->toBe('woo')
            ->and($diff->status)->toBe('pending')
            ->and($diff->payload)->toBe(['regular_price' => '99.99']);

        expect($result)->toMatchArray(['shadow_mode' => true])
            ->and($result['diff_id'])->toBe($diff->id);
    });

    it('throws LogicException on write_enabled=true in Phase 1 (Phase 2 implements real write)', function () {
        config(['services.woo.write_enabled' => true]);

        expect(fn () => app(WooClient::class)->put('products/1234', []))
            ->toThrow(\LogicException::class);
    });

    it('writes integration_events row with shadow_mode response when diff recorded', function () {
        app(WooClient::class)->post('products', ['name' => 'Test Product', 'sku' => 'TST-1']);

        $events = \App\Foundation\Integration\Models\IntegrationEvent::all();
        expect($events)->toHaveCount(1);
        expect($events->first()->channel)->toBe('woo');
        expect($events->first()->operation)->toBe('POST products');
        expect($events->first()->response_body)->toMatchArray(['shadow_mode' => true]);
    });

    it('extracts woo_id from endpoint patterns', function () {
        app(WooClient::class)->put('products/4567', ['x' => 1]);
        expect(SyncDiff::where('endpoint', 'products/4567')->first()->woo_id)->toBe('4567');

        app(WooClient::class)->patch('products/9999/variations/42', ['y' => 1]);
        expect(SyncDiff::where('endpoint', 'products/9999/variations/42')->first()->woo_id)->toBe('9999');

        app(WooClient::class)->post('orders', ['z' => 1]);
        expect(SyncDiff::where('endpoint', 'orders')->first()->woo_id)->toBeNull();
    });
    ```

    **Step H — Run migrations + tests:**

    ```bash
    php artisan migrate
    vendor/bin/pest --filter=ShadowMode
    ```
  </action>
  <verify>
    <automated>test -f database/migrations/2026_04_18_105000_create_webhook_receipts_table.php &amp;&amp; test -f database/migrations/2026_04_18_107000_create_suggestions_table.php &amp;&amp; test -f database/migrations/2026_04_18_108000_create_sync_diffs_table.php &amp;&amp; test -f app/Domain/Sync/Services/WooClient.php &amp;&amp; grep -q "write_enabled" config/services.php &amp;&amp; grep -q "webhook_secret" config/services.php &amp;&amp; php artisan migrate --force &amp;&amp; php artisan db:table webhook_receipts &amp;&amp; php artisan db:table suggestions &amp;&amp; php artisan db:table sync_diffs &amp;&amp; vendor/bin/pest --filter=ShadowMode</automated>
  </verify>
  <done>
    Three migrations applied; models loadable via PSR-4; config/services.php has woo block with write_enabled defaulting to false; WooClient routes every call through the shadow-mode gate; ShadowModeTest (4 tests) all pass; SyncDiff rows carry correlation_id from Context.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: HMAC middleware + WooWebhookController + OrderReceived/CustomerRegistered events + routes/webhooks.php population + WooWebhookTest (HMAC success/fail/dedup/latency)</name>
  <files>app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php, app/Domain/Webhooks/Http/Controllers/WooWebhookController.php, app/Domain/Webhooks/Events/OrderReceived.php, app/Domain/Webhooks/Events/CustomerRegistered.php, routes/webhooks.php, tests/Feature/WooWebhookTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §3 (HMAC middleware full implementation, route registration, controller ≤20 lines, <200ms acceptance path)
    - .planning/research/PITFALLS.md Pitfall 4 (webhook handler synchronous + idempotency), Pitfall A (raw body consumed before HMAC — critical ordering)
    - routes/webhooks.php (current state — empty placeholder from Plan 01)
    - bootstrap/app.php (confirm api middleware group registration from Plan 01)
    - app/Foundation/Events/DomainEvent.php (base class from Plan 03 — events extend this)
  </read_first>
  <behavior>
    - Test: POST /webhooks/woo/order with correct HMAC header → 200 + exactly 1 row in webhook_receipts
    - Test: POST with tampered body (different bytes than HMAC was computed over) → 401
    - Test: POST without X-WC-Webhook-Signature header → 401
    - Test: POST without WC_WEBHOOK_SECRET env var → 401
    - Test: POST twice with identical X-WC-Webhook-Delivery-ID → first returns 200+accepted, second returns 200+duplicate, webhook_receipts row count stays at 1
    - Test: Event `OrderReceived` dispatched exactly once on first POST, zero times on duplicate retry
    - Test: Response includes X-Correlation-Id header (from Plan 03 middleware — regression check)
    - Test: Per-request elapsed wall-clock time from route entry → event dispatch is < 200ms (FOUND-07 acceptance)
  </behavior>
  <action>
    **Step A — Write `app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Webhooks\Http\Middleware;

    use Closure;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * Verifies the X-WC-Webhook-Signature header against HMAC-SHA256 of the raw body.
     *
     * CRITICAL ORDERING (Pitfall A):
     *   - This middleware MUST run BEFORE any middleware that parses JSON input.
     *   - $request->getContent() reads the raw stream; once read by input parsing, the
     *     subsequent HMAC comparison silently fails.
     *   - Registered as the FIRST middleware on the /webhooks/woo/* route group in routes/webhooks.php.
     *
     * Algorithm per WooCommerce WC_Webhook::generate_signature:
     *   $signature = base64_encode(hash_hmac('sha256', $body, $secret, true))
     *
     * Comparison uses hash_equals() for timing-safe comparison (T-04-02).
     */
    class VerifyWooHmacSignature
    {
        public function handle(Request $request, Closure $next): Response
        {
            $signature = $request->header('X-WC-Webhook-Signature');
            $secret    = config('services.woo.webhook_secret');

            abort_unless(
                is_string($signature) && is_string($secret) && $secret !== '',
                401,
                'Missing HMAC signature or server secret not configured'
            );

            // CRITICAL: raw body. Do NOT call ->json() / ->all() / ->input() anywhere
            // in the middleware stack before this point (Pitfall A).
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

            abort_unless(hash_equals($expected, $signature), 401, 'Invalid HMAC signature');

            return $next($request);
        }
    }
    ```

    **Step B — Write event classes (extend DomainEvent base from Plan 03):**

    `app/Domain/Webhooks/Events/OrderReceived.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Webhooks\Events;

    use App\Foundation\Events\DomainEvent;

    /**
     * Fired when a Woo order webhook is received + receipt persisted.
     *
     * Phase 4 CRM module listener (PushDealToBitrixJob) subscribes.
     * Phase 1 ships no listener — event fires into the void, which is fine.
     *
     * Payload = webhook_receipts.id; listener loads the row to access raw_body.
     */
    final class OrderReceived extends DomainEvent
    {
        public function __construct(
            public readonly int $webhookReceiptId,
            public readonly string $deliveryId,
        ) {
            parent::__construct(); // auto-populates correlationId + occurredAt
        }
    }
    ```

    `app/Domain/Webhooks/Events/CustomerRegistered.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Webhooks\Events;

    use App\Foundation\Events\DomainEvent;

    final class CustomerRegistered extends DomainEvent
    {
        public function __construct(
            public readonly int $webhookReceiptId,
            public readonly string $deliveryId,
        ) {
            parent::__construct();
        }
    }
    ```

    **Step C — Write `app/Domain/Webhooks/Http/Controllers/WooWebhookController.php`** (≤20 lines per method per §3):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Webhooks\Http\Controllers;

    use App\Domain\Webhooks\Events\CustomerRegistered;
    use App\Domain\Webhooks\Events\OrderReceived;
    use App\Domain\Webhooks\Models\WebhookReceipt;
    use Illuminate\Database\QueryException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Context;
    use Illuminate\Support\Str;

    final class WooWebhookController
    {
        public function order(Request $request): JsonResponse
        {
            return $this->handle($request, 'order', fn ($receipt) => OrderReceived::dispatch($receipt->id, $receipt->delivery_id));
        }

        public function customer(Request $request): JsonResponse
        {
            return $this->handle($request, 'customer', fn ($receipt) => CustomerRegistered::dispatch($receipt->id, $receipt->delivery_id));
        }

        private function handle(Request $request, string $topic, \Closure $dispatch): JsonResponse
        {
            $deliveryId = $request->header('X-WC-Webhook-Delivery-ID') ?? (string) Str::uuid();

            try {
                $receipt = WebhookReceipt::create([
                    'source'         => 'woo',
                    'topic'          => $topic,
                    'delivery_id'    => $deliveryId,
                    // Defense-in-depth: redact sensitive inbound headers (Gemini Concern MEDIUM).
                    // Mirrors IntegrationLogger's outbound redaction so inbound + outbound logs are symmetric.
                    'headers'        => WebhookReceipt::redactHeaders($request->headers->all()),
                    'raw_body'       => $request->getContent(),
                    'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
                    'received_at'    => now(),
                ]);
            } catch (QueryException $e) {
                // Duplicate (source, delivery_id) — Woo retried. Return 200 so it stops.
                if ($this->isDuplicateKeyError($e)) {
                    return response()->json(['status' => 'duplicate']);
                }
                throw $e;
            }

            $dispatch($receipt);

            return response()->json(['status' => 'accepted']);
        }

        private function isDuplicateKeyError(QueryException $e): bool
        {
            // MySQL SQLSTATE 23000 = integrity constraint violation (unique index)
            return $e->getCode() === '23000';
        }
    }
    ```

    **Step D — Populate `routes/webhooks.php`** (replace Plan 01's empty placeholder):

    ```php
    <?php

    use App\Domain\Webhooks\Http\Controllers\WooWebhookController;
    use App\Domain\Webhooks\Http\Middleware\VerifyWooHmacSignature;
    use Illuminate\Support\Facades\Route;

    /*
    |--------------------------------------------------------------------------
    | Webhook Routes
    |--------------------------------------------------------------------------
    |
    | Registered in bootstrap/app.php under 'api' middleware group (no session, no CSRF).
    | VerifyWooHmacSignature is registered FIRST on the /webhooks/woo/* group so that
    | the raw body is available when hash_hmac computes the expected signature
    | (Pitfall A — middleware ordering is load-bearing).
    */

    Route::prefix('webhooks/woo')
        ->middleware([VerifyWooHmacSignature::class])
        ->group(function () {
            Route::post('order',    [WooWebhookController::class, 'order'])->name('webhooks.woo.order');
            Route::post('customer', [WooWebhookController::class, 'customer'])->name('webhooks.woo.customer');
            // Phase 4 adds more topics (product.updated, etc.)
        });
    ```

    **Step E — Write `tests/Feature/WooWebhookTest.php`:**

    ```php
    <?php

    use App\Domain\Webhooks\Events\OrderReceived;
    use App\Domain\Webhooks\Models\WebhookReceipt;
    use Illuminate\Support\Facades\Event;

    beforeEach(function () {
        config(['services.woo.webhook_secret' => 'test-secret-alphanum-only']);
    });

    /** Helper: build a valid HMAC signature for a given raw body. */
    function wooSign(string $rawBody, string $secret = 'test-secret-alphanum-only'): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }

    it('accepts a correctly-signed order webhook and persists one receipt', function () {
        Event::fake([OrderReceived::class]);
        $body = json_encode(['id' => 555, 'status' => 'processing', 'total' => '99.99']);
        $sig  = wooSign($body);

        $response = $this->call(
            'POST',
            '/webhooks/woo/order',
            [], [], [],
            [
                'HTTP_X-WC-Webhook-Signature'   => $sig,
                'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_delivery_1',
                'CONTENT_TYPE'                  => 'application/json',
            ],
            $body
        );

        $response->assertOk();
        expect($response->json('status'))->toBe('accepted');
        expect(WebhookReceipt::count())->toBe(1);

        $receipt = WebhookReceipt::first();
        expect($receipt->source)->toBe('woo');
        expect($receipt->topic)->toBe('order');
        expect($receipt->delivery_id)->toBe('wh_delivery_1');
        expect($receipt->raw_body)->toBe($body);

        Event::assertDispatched(OrderReceived::class, 1);
    });

    it('rejects requests with tampered body (body does not match HMAC)', function () {
        $bodyForSig  = json_encode(['id' => 555]);
        $bodyPosted  = json_encode(['id' => 999]); // different bytes — HMAC will mismatch
        $sig = wooSign($bodyForSig);

        $response = $this->call(
            'POST',
            '/webhooks/woo/order',
            [], [], [],
            [
                'HTTP_X-WC-Webhook-Signature'   => $sig,
                'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_delivery_2',
                'CONTENT_TYPE'                  => 'application/json',
            ],
            $bodyPosted
        );

        $response->assertStatus(401);
        expect(WebhookReceipt::count())->toBe(0);
    });

    it('rejects requests missing X-WC-Webhook-Signature header', function () {
        $response = $this->call(
            'POST',
            '/webhooks/woo/order',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 1])
        );

        $response->assertStatus(401);
    });

    it('rejects requests when server secret is not configured', function () {
        config(['services.woo.webhook_secret' => '']);

        $body = json_encode(['id' => 1]);
        $response = $this->call(
            'POST', '/webhooks/woo/order', [], [], [],
            ['HTTP_X-WC-Webhook-Signature' => 'anything', 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(401);
    });

    it('deduplicates retries by (source, delivery_id) — second POST returns duplicate', function () {
        Event::fake([OrderReceived::class]);
        $body = json_encode(['id' => 777]);
        $sig  = wooSign($body);
        $headers = [
            'HTTP_X-WC-Webhook-Signature'   => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_delivery_retry',
            'CONTENT_TYPE'                  => 'application/json',
        ];

        $first  = $this->call('POST', '/webhooks/woo/order', [], [], [], $headers, $body);
        $second = $this->call('POST', '/webhooks/woo/order', [], [], [], $headers, $body);

        $first->assertOk()->assertJson(['status' => 'accepted']);
        $second->assertOk()->assertJson(['status' => 'duplicate']);

        expect(WebhookReceipt::count())->toBe(1);
        Event::assertDispatched(OrderReceived::class, 1); // not 2
    });

    it('emits X-Correlation-Id header on the response (Plan 03 integration)', function () {
        $body = json_encode(['id' => 1]);
        $sig = wooSign($body);

        $response = $this->call(
            'POST', '/webhooks/woo/order', [], [], [],
            [
                'HTTP_X-WC-Webhook-Signature'   => $sig,
                'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_cid_test',
                'CONTENT_TYPE'                  => 'application/json',
            ],
            $body
        );

        $response->assertOk();
        expect($response->headers->get('X-Correlation-Id'))->not->toBeNull();
    });

    it('completes the HMAC → insert → event dispatch cycle in under 200ms (FOUND-07 acceptance)', function () {
        $body = json_encode(['id' => 1]);
        $sig = wooSign($body);

        $start = microtime(true);
        $response = $this->call(
            'POST', '/webhooks/woo/order', [], [], [],
            [
                'HTTP_X-WC-Webhook-Signature'   => $sig,
                'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_latency_test',
                'CONTENT_TYPE'                  => 'application/json',
            ],
            $body
        );
        $elapsedMs = (microtime(true) - $start) * 1000;

        $response->assertOk();
        expect($elapsedMs)->toBeLessThan(200.0); // FOUND-07 success criterion
    });

    it('routes /webhooks/woo/customer to the customer handler', function () {
        $body = json_encode(['id' => 42, 'email' => 'test@example.com']);
        $sig = wooSign($body);

        $response = $this->call(
            'POST', '/webhooks/woo/customer', [], [], [],
            [
                'HTTP_X-WC-Webhook-Signature'   => $sig,
                'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_cust_1',
                'CONTENT_TYPE'                  => 'application/json',
            ],
            $body
        );

        $response->assertOk();
        expect(WebhookReceipt::first()->topic)->toBe('customer');
    });
    ```

    Run: `vendor/bin/pest --filter=WooWebhook` — all 8 tests pass.

    **Step F — Write `tests/Feature/WebhookReceiptRedactionTest.php`** (Gemini Concern MEDIUM — inbound header redaction):

    ```php
    <?php

    use App\Domain\Webhooks\Models\WebhookReceipt;

    it('redacts sensitive inbound headers via WebhookReceipt::redactHeaders()', function () {
        $raw = [
            'Content-Type'             => 'application/json',
            'User-Agent'               => 'WooCommerce/9.0',
            'Authorization'            => 'Bearer leaked-token-should-never-land',
            'X-WC-Webhook-Signature'   => 'abc123==',
            'cookie'                   => 'session=xyz',
            'X-Api-Key'                => 'sk_live_should_be_redacted',
            'set-cookie'               => 'tracking=1',
            'X-Auth-Token'             => 'oauth-token',
            'X-Session-Token'          => 'sess-token',
        ];

        $redacted = WebhookReceipt::redactHeaders($raw);

        // Non-sensitive headers preserved verbatim
        expect($redacted['Content-Type'])->toBe('application/json');
        expect($redacted['User-Agent'])->toBe('WooCommerce/9.0');

        // Sensitive headers replaced with ['***REDACTED***']
        expect($redacted['Authorization'])->toBe(['***REDACTED***']);
        expect($redacted['X-WC-Webhook-Signature'])->toBe(['***REDACTED***']);
        expect($redacted['cookie'])->toBe(['***REDACTED***']);
        expect($redacted['X-Api-Key'])->toBe(['***REDACTED***']);
        expect($redacted['set-cookie'])->toBe(['***REDACTED***']);
        expect($redacted['X-Auth-Token'])->toBe(['***REDACTED***']);
        expect($redacted['X-Session-Token'])->toBe(['***REDACTED***']);
    });

    it('redacts sensitive headers on webhook_receipts insert via WooWebhookController', function () {
        config(['services.woo.webhook_secret' => 'test-secret-alphanum-only']);

        $body = json_encode(['id' => 999]);
        $sig  = base64_encode(hash_hmac('sha256', $body, 'test-secret-alphanum-only', true));

        // Send a request with a leaked Authorization header that Woo "shouldn't" send
        $response = $this->call(
            'POST', '/webhooks/woo/order', [], [], [],
            [
                'HTTP_X-WC-Webhook-Signature'   => $sig,
                'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_redact_test',
                'HTTP_AUTHORIZATION'            => 'Bearer should-be-redacted',
                'HTTP_COOKIE'                   => 'session=leaked',
                'CONTENT_TYPE'                  => 'application/json',
            ],
            $body
        );

        $response->assertOk();

        $receipt = WebhookReceipt::where('delivery_id', 'wh_redact_test')->firstOrFail();
        $headers = $receipt->headers;

        // Authorization + Cookie + Signature redacted in the persisted row even though HMAC verify passed.
        // Symfony lower-cases header keys; check both variants for resilience.
        $authVal   = $headers['authorization']             ?? $headers['Authorization']             ?? null;
        $cookieVal = $headers['cookie']                    ?? $headers['Cookie']                    ?? null;
        $sigVal    = $headers['x-wc-webhook-signature']    ?? $headers['X-WC-Webhook-Signature']    ?? null;

        expect($authVal)->toBe(['***REDACTED***']);
        expect($cookieVal)->toBe(['***REDACTED***']);
        expect($sigVal)->toBe(['***REDACTED***']);

        // Non-sensitive header survives
        $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? null;
        expect($contentType)->not->toBeNull();
    });
    ```

    Run: `vendor/bin/pest --filter=WebhookReceiptRedaction` — both tests pass.
  </action>
  <verify>
    <automated>test -f app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php &amp;&amp; test -f app/Domain/Webhooks/Http/Controllers/WooWebhookController.php &amp;&amp; test -f app/Domain/Webhooks/Events/OrderReceived.php &amp;&amp; test -f app/Domain/Webhooks/Events/CustomerRegistered.php &amp;&amp; grep -q "VerifyWooHmacSignature" routes/webhooks.php &amp;&amp; grep -q "webhooks/woo" routes/webhooks.php &amp;&amp; grep -q "WebhookReceipt::redactHeaders" app/Domain/Webhooks/Http/Controllers/WooWebhookController.php &amp;&amp; grep -q "SENSITIVE_HEADERS" app/Domain/Webhooks/Models/WebhookReceipt.php &amp;&amp; vendor/bin/pest --filter=WooWebhook &amp;&amp; vendor/bin/pest --filter=WebhookReceiptRedaction</automated>
  </verify>
  <done>
    HMAC middleware verifies signature with hash_equals on raw body; controller ≤20 lines per method, writes receipt, fires event, returns 200; routes registered under HMAC group in routes/webhooks.php; 8 feature tests all pass including the <200ms latency assertion (FOUND-07 acceptance) and dedup-by-delivery-id test.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: SuggestionApplier contract + StubApplier + ApplySuggestionJob + SuggestionApplierResolver registered in AppServiceProvider + SuggestionPolicy (admin-only) + Filament SuggestionResource + TestSuggestionSeeder + SuggestionInboxTest</name>
  <files>app/Domain/Suggestions/Contracts/SuggestionApplier.php, app/Domain/Suggestions/Services/SuggestionApplierResolver.php, app/Domain/Suggestions/Appliers/StubApplier.php, app/Domain/Suggestions/Jobs/ApplySuggestionJob.php, app/Domain/Suggestions/Policies/SuggestionPolicy.php, app/Domain/Suggestions/Filament/Resources/SuggestionResource.php, app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ListSuggestions.php, app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ViewSuggestion.php, database/seeders/TestSuggestionSeeder.php, database/seeders/DatabaseSeeder.php, app/Providers/AppServiceProvider.php, tests/Feature/SuggestionInboxTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-RESEARCH.md §7 (SuggestionApplier contract, SuggestionApplierResolver, StubApplier, ApplySuggestionJob idempotency guard, Filament Resource scaffold, TestSuggestionSeeder)
    - .planning/phases/01-foundation/01-CONTEXT.md D-14 through D-17 (schema, apply path enqueues ApplySuggestionJob, correlation_id indexed, Phase 1 ships contract + stub + seeded test suggestion)
    - .planning/research/PITFALLS.md Pitfall K (Suggestions Resource reachable without Shield gate — admin-only)
    - app/Providers/AppServiceProvider.php (Plan 03 state — has Context::hydrated callback)
    - app/Providers/Filament/AdminPanelProvider.php (confirm discoverResources path includes app/Domain/Suggestions/Filament/Resources)
    - database/seeders/DatabaseSeeder.php (Plan 02 state — has RolePermissionSeeder)
  </read_first>
  <behavior>
    - Test: `SuggestionApplier` interface defines `supports(): array` and `apply(Suggestion): array`
    - Test: StubApplier implements SuggestionApplier, `supports()` returns `['test']`, `apply()` returns array with `stub_result: ok`
    - Test: `SuggestionApplierResolver::resolve()` on a registered `kind='test'` returns StubApplier instance
    - Test: `SuggestionApplierResolver::resolve()` on an unregistered kind throws RuntimeException
    - Test: `ApplySuggestionJob` with a pending suggestion flips status to 'applied' and writes integration_events row
    - Test: `ApplySuggestionJob` dispatched twice on same suggestion only writes ONE integration_events row (idempotency guard on status=applied per D-15)
    - Test: `TestSuggestionSeeder` creates ONE suggestion with kind=test, status=pending, correlation_id populated
    - Test: Seeding twice produces ONE suggestion (firstOrCreate by kind)
    - Test: Authenticated admin user can access /admin/suggestions and see the seeded suggestion
    - Test: Authenticated read_only user gets 403 on /admin/suggestions (Pitfall K — SuggestionPolicy admin-only)
  </behavior>
  <action>
    **Step A — Write `app/Domain/Suggestions/Contracts/SuggestionApplier.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Contracts;

    use App\Domain\Suggestions\Models\Suggestion;

    /**
     * Contract for any class that applies a Suggestion of a specific kind.
     *
     * Phase 1 ships the contract + StubApplier for kind='test'.
     * Phase 5 (first real producer) implements MarginChangeApplier for kind='margin_change'.
     * Phase 6 adds NewProductApplier for kind='new_product'.
     *
     * Implementations MUST be idempotent — the ApplySuggestionJob may be retried.
     *
     * Register via:
     *   app(SuggestionApplierResolver::class)->register('my_kind', MyApplier::class);
     * in AppServiceProvider::boot().
     */
    interface SuggestionApplier
    {
        /** Which `kind` values this applier handles. Return an array of kind strings. */
        public function supports(): array;

        /**
         * Execute the change described by the suggestion.
         *
         * @throws \Throwable on failure — job catches, flips status to 'failed', surfaces in inbox
         * @return array arbitrary result data (logged to integration_events.response_body)
         */
        public function apply(Suggestion $suggestion): array;
    }
    ```

    **Step B — Write `app/Domain/Suggestions/Services/SuggestionApplierResolver.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Services;

    use App\Domain\Suggestions\Contracts\SuggestionApplier;
    use App\Domain\Suggestions\Models\Suggestion;
    use RuntimeException;

    /**
     * Registry mapping suggestion.kind strings to applier class names.
     *
     * Bound as a singleton in the container (AppServiceProvider). Producers register
     * their kind during boot(), and ApplySuggestionJob resolves the right applier at run time.
     */
    final class SuggestionApplierResolver
    {
        /** @var array<string, class-string<SuggestionApplier>>  map of kind => applier class */
        private array $registry = [];

        public function register(string $kind, string $applierClass): void
        {
            $this->registry[$kind] = $applierClass;
        }

        public function resolve(Suggestion $suggestion): SuggestionApplier
        {
            $class = $this->registry[$suggestion->kind] ?? throw new RuntimeException(
                "No SuggestionApplier registered for kind: {$suggestion->kind}"
            );
            return app($class);
        }

        /** Useful for diagnostics / admin inspector page (Phase 7). */
        public function registered(): array
        {
            return $this->registry;
        }
    }
    ```

    **Step C — Write `app/Domain/Suggestions/Appliers/StubApplier.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Appliers;

    use App\Domain\Suggestions\Contracts\SuggestionApplier;
    use App\Domain\Suggestions\Models\Suggestion;

    /**
     * No-op applier for kind='test' — Phase 1 acceptance fixture only.
     *
     * Does NOT touch external systems. Just records that apply() was invoked.
     * The existence of this class is what makes "admin approves the seeded test suggestion"
     * verifiable without Plan 05 / Phase 5's real producers existing yet.
     */
    final class StubApplier implements SuggestionApplier
    {
        public function supports(): array
        {
            return ['test'];
        }

        public function apply(Suggestion $suggestion): array
        {
            return [
                'applied_at'  => now()->toIso8601String(),
                'applier'     => self::class,
                'stub_result' => 'ok',
                'suggestion_id' => $suggestion->id,
            ];
        }
    }
    ```

    **Step D — Write `app/Domain/Suggestions/Jobs/ApplySuggestionJob.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Jobs;

    use App\Domain\Suggestions\Models\Suggestion;
    use App\Domain\Suggestions\Services\SuggestionApplierResolver;
    use App\Foundation\Integration\Services\IntegrationLogger;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\Context;

    /**
     * Dispatched by SuggestionResource::approve action. Resolves the applier per kind,
     * runs apply(), writes integration_events, updates status → 'applied'.
     *
     * Idempotency guard (D-15): if status is already 'applied', return immediately.
     * This means retries from Horizon after a transient failure never double-apply.
     */
    final class ApplySuggestionJob implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        public string $queue = 'default';
        public int $tries    = 3;
        /** @var array<int, int> backoff in seconds */
        public array $backoff = [10, 30, 60];

        public function __construct(public readonly string $suggestionId) {}

        public function handle(SuggestionApplierResolver $resolver, IntegrationLogger $logger): void
        {
            $suggestion = Suggestion::findOrFail($this->suggestionId);

            // D-15 idempotency guard
            if ($suggestion->status === Suggestion::STATUS_APPLIED) {
                return;
            }

            // Restore correlation_id into Context so downstream writes thread it (belt-and-braces —
            // Laravel 12 Context::hydrated should already do this, but in tests we may call handle() directly)
            Context::add('correlation_id', $suggestion->correlation_id);

            try {
                $applier = $resolver->resolve($suggestion);
                $result  = $applier->apply($suggestion);

                $suggestion->update([
                    'status'     => Suggestion::STATUS_APPLIED,
                    'applied_at' => now(),
                ]);

                $logger->log([
                    'channel'       => 'suggestions',
                    'operation'     => "apply:{$suggestion->kind}",
                    'endpoint'      => 'internal',
                    'method'        => 'APPLY',
                    'request_body'  => $suggestion->payload,
                    'response_body' => $result,
                    'http_status'   => 200,
                    'status'        => 'success',
                    'subject_type'  => Suggestion::class,
                    'subject_id'    => $suggestion->id, // ULID — integration_events.subject_id is nullableUlidMorphs (CHAR(26)) per Plan 03 migration; no cast needed.
                ]);
            } catch (\Throwable $e) {
                $suggestion->update([
                    'status'           => Suggestion::STATUS_FAILED,
                    'rejection_reason' => $e->getMessage(),
                ]);

                $logger->log([
                    'channel'       => 'suggestions',
                    'operation'     => "apply:{$suggestion->kind}",
                    'endpoint'      => 'internal',
                    'method'        => 'APPLY',
                    'request_body'  => $suggestion->payload,
                    'response_body' => ['error' => $e->getMessage()],
                    'http_status'   => 500,
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
    ```

    **Step E — Write `app/Domain/Suggestions/Policies/SuggestionPolicy.php`** (Pitfall K — admin-only):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Policies;

    use App\Domain\Suggestions\Models\Suggestion;
    use App\Models\User;

    /**
     * Phase 1 policy — admin role only. Other roles get 403 on /admin/suggestions.
     *
     * Pitfall K compliance: `sales` should not see `margin_change` suggestions (privacy leak).
     * Shield's shield:generate produces permission names like view_any_suggestion + view_suggestion
     * which are assigned to `admin` role ONLY in RolePermissionSeeder (via the LIKE query that
     * already excludes sales/pricing_manager).
     *
     * This policy is the belt-and-braces second layer: even if permission assignment drifts,
     * the hasRole('admin') check keeps unauthorised roles out.
     */
    class SuggestionPolicy
    {
        public function viewAny(User $user): bool
        {
            return $user->hasRole('admin');
        }

        public function view(User $user, Suggestion $suggestion): bool
        {
            return $user->hasRole('admin');
        }

        public function create(User $user): bool
        {
            return false; // Suggestions are created by producers, never manually in Filament
        }

        public function update(User $user, Suggestion $suggestion): bool
        {
            return $user->hasRole('admin');
        }

        public function delete(User $user, Suggestion $suggestion): bool
        {
            return false; // append-only
        }
    }
    ```

    Register the policy in `app/Providers/AppServiceProvider::boot()` (extends Plan 03's boot method):

    ```php
    use App\Domain\Suggestions\Models\Suggestion;
    use App\Domain\Suggestions\Policies\SuggestionPolicy;
    use Illuminate\Support\Facades\Gate;

    // Inside boot():
    Gate::policy(Suggestion::class, SuggestionPolicy::class);
    ```

    **Step F — Register SuggestionApplierResolver + StubApplier in `AppServiceProvider::boot()`**. Final boot() state (merge with Plan 03's callbacks):

    ```php
    public function boot(): void
    {
        // ── Plan 03: Context hydrate/dehydrate ─────────────────────
        Context::hydrated(function (\Illuminate\Log\Context\Repository $context): void {
            if ($cid = $context->get('correlation_id')) {
                \Spatie\Activitylog\Facades\LogBatch::setBatch($cid);
            }
        });
        Context::dehydrating(function (\Illuminate\Log\Context\Repository $context): void {});

        // ── Plan 04: Suggestions wiring ──────────────────────────────
        $this->app->singleton(
            \App\Domain\Suggestions\Services\SuggestionApplierResolver::class
        );
        $this->app->afterResolving(
            \App\Domain\Suggestions\Services\SuggestionApplierResolver::class,
            function (\App\Domain\Suggestions\Services\SuggestionApplierResolver $resolver) {
                $resolver->register('test', \App\Domain\Suggestions\Appliers\StubApplier::class);
                // Phase 5 adds: $resolver->register('margin_change', MarginChangeApplier::class);
            }
        );

        // Policy registration
        \Illuminate\Support\Facades\Gate::policy(
            \App\Domain\Suggestions\Models\Suggestion::class,
            \App\Domain\Suggestions\Policies\SuggestionPolicy::class
        );
    }
    ```

    **Step G — Write `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`** (skeleton from §7):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Domain\Suggestions\Filament\Resources;

    use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;
    use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
    use App\Domain\Suggestions\Models\Suggestion;
    use Filament\Forms\Components\Textarea;
    use Filament\Resources\Resource;
    use Filament\Tables\Actions\Action;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Filters\SelectFilter;
    use Filament\Tables\Table;

    class SuggestionResource extends Resource
    {
        protected static ?string $model           = Suggestion::class;
        protected static ?string $navigationIcon  = 'heroicon-o-inbox';
        protected static ?string $navigationGroup = 'Review';
        protected static ?string $recordTitleAttribute = 'kind';

        /**
         * Eager-load relations displayed in the table to prevent N+1 queries
         * (Gemini Concern MEDIUM, PITFALLS Pitfall 10).
         *
         * Currently rendered relation columns:
         *   - resolvedByUser.name  -> belongsTo(User)
         *
         * `proposedBy` is a polymorphic morphTo. If a future column renders proposedBy.* fields,
         * extend this with `->with(['proposedBy'])` (Eloquent will fan out per concrete type).
         *
         * The accompanying tests/Feature/SuggestionResourceQueryCountTest.php asserts that listing
         * N suggestions executes a bounded number of queries (not N + relation-fetches per row).
         */
        public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
        {
            return parent::getEloquentQuery()->with(['resolvedByUser']);
        }

        public static function table(Table $table): Table
        {
            return $table
                ->columns([
                    TextColumn::make('kind')->badge()->sortable(),
                    TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'primary',
                        'rejected' => 'danger',
                        'applied'  => 'success',
                        'failed'   => 'danger',
                        default    => 'gray',
                    })->sortable(),
                    TextColumn::make('correlation_id')->fontFamily('mono')->copyable()->limit(8)->tooltip(fn ($record) => $record->correlation_id),
                    TextColumn::make('proposed_at')->dateTime()->sortable(),
                    TextColumn::make('resolvedByUser.name')->label('Resolved by')->placeholder('—'),
                ])
                ->defaultSort('proposed_at', 'desc')
                ->filters([
                    SelectFilter::make('kind'),
                    SelectFilter::make('status')->options([
                        'pending'  => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'applied'  => 'Applied',
                        'failed'   => 'Failed',
                    ]),
                ])
                ->actions([
                    Action::make('approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                        ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING)
                        ->action(function (Suggestion $record) {
                            $record->update([
                                'status'              => Suggestion::STATUS_APPROVED,
                                'resolved_by_user_id' => auth()->id(),
                                'resolved_at'         => now(),
                            ]);
                            ApplySuggestionJob::dispatch($record->id);
                        }),
                    Action::make('reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->form([Textarea::make('rejection_reason')->required()->maxLength(2000)])
                        ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                        ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING)
                        ->action(function (Suggestion $record, array $data) {
                            $record->update([
                                'status'              => Suggestion::STATUS_REJECTED,
                                'rejection_reason'    => $data['rejection_reason'],
                                'resolved_by_user_id' => auth()->id(),
                                'resolved_at'         => now(),
                            ]);
                        }),
                ]);
        }

        public static function getPages(): array
        {
            return [
                'index' => Pages\ListSuggestions::route('/'),
                'view'  => Pages\ViewSuggestion::route('/{record}'),
            ];
        }
    }
    ```

    **`app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ListSuggestions.php`:**

    ```php
    <?php

    namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

    use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
    use Filament\Resources\Pages\ListRecords;

    class ListSuggestions extends ListRecords
    {
        protected static string $resource = SuggestionResource::class;
    }
    ```

    **`app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ViewSuggestion.php`:**

    ```php
    <?php

    namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

    use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
    use Filament\Resources\Pages\ViewRecord;

    class ViewSuggestion extends ViewRecord
    {
        protected static string $resource = SuggestionResource::class;
    }
    ```

    **Step H — After creating the Resource, re-run `shield:generate` so the `suggestion` permissions exist and RolePermissionSeeder picks them up for admin:**

    ```bash
    php artisan shield:generate --all --panel=admin
    php artisan db:seed --class=RolePermissionSeeder --force
    ```

    **Step I — Write `database/seeders/TestSuggestionSeeder.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace Database\Seeders;

    use App\Domain\Suggestions\Models\Suggestion;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Str;

    class TestSuggestionSeeder extends Seeder
    {
        public function run(): void
        {
            Suggestion::firstOrCreate(
                ['kind' => 'test'],
                [
                    'status'         => Suggestion::STATUS_PENDING,
                    'correlation_id' => (string) Str::uuid(),
                    'payload'        => ['message' => 'Phase 1 acceptance test suggestion'],
                    'evidence'       => ['source' => 'seeder', 'created_at' => now()->toIso8601String()],
                    'proposed_at'    => now(),
                ]
            );
        }
    }
    ```

    **Wire in `database/seeders/DatabaseSeeder.php`** (extends Plan 02 state):

    ```php
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            TestSuggestionSeeder::class,
            // AlertRecipientSeeder added by Plan 05
        ]);
    }
    ```

    **Step J — Write `tests/Feature/SuggestionInboxTest.php`:**

    ```php
    <?php

    use App\Domain\Suggestions\Appliers\StubApplier;
    use App\Domain\Suggestions\Contracts\SuggestionApplier;
    use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
    use App\Domain\Suggestions\Models\Suggestion;
    use App\Domain\Suggestions\Services\SuggestionApplierResolver;
    use App\Foundation\Integration\Models\IntegrationEvent;
    use App\Models\User;
    use Database\Seeders\RolePermissionSeeder;
    use Database\Seeders\TestSuggestionSeeder;

    beforeEach(function () {
        $this->seed(RolePermissionSeeder::class);
    });

    it('StubApplier supports kind=test', function () {
        expect(app(StubApplier::class)->supports())->toBe(['test']);
    });

    it('SuggestionApplier contract exists with supports() and apply() methods', function () {
        $reflection = new ReflectionClass(SuggestionApplier::class);
        expect($reflection->isInterface())->toBeTrue();
        expect($reflection->hasMethod('supports'))->toBeTrue();
        expect($reflection->hasMethod('apply'))->toBeTrue();
    });

    it('SuggestionApplierResolver resolves test kind to StubApplier', function () {
        $suggestion = Suggestion::create([
            'kind' => 'test',
            'status' => 'pending',
            'correlation_id' => 'cid-resolve',
            'payload' => [],
            'proposed_at' => now(),
        ]);

        $applier = app(SuggestionApplierResolver::class)->resolve($suggestion);
        expect($applier)->toBeInstanceOf(StubApplier::class);
    });

    it('SuggestionApplierResolver throws on unregistered kind', function () {
        $suggestion = Suggestion::create([
            'kind' => 'unregistered_kind',
            'status' => 'pending',
            'correlation_id' => 'cid-unk',
            'payload' => [],
            'proposed_at' => now(),
        ]);

        expect(fn () => app(SuggestionApplierResolver::class)->resolve($suggestion))
            ->toThrow(RuntimeException::class, 'No SuggestionApplier registered');
    });

    it('TestSuggestionSeeder creates one pending test suggestion', function () {
        $this->seed(TestSuggestionSeeder::class);

        expect(Suggestion::where('kind', 'test')->count())->toBe(1);
        $s = Suggestion::where('kind', 'test')->first();
        expect($s->status)->toBe('pending');
        expect($s->correlation_id)->not->toBeNull();
        expect($s->payload)->toHaveKey('message');
    });

    it('TestSuggestionSeeder is idempotent (firstOrCreate)', function () {
        $this->seed(TestSuggestionSeeder::class);
        $this->seed(TestSuggestionSeeder::class);

        expect(Suggestion::where('kind', 'test')->count())->toBe(1);
    });

    it('ApplySuggestionJob flips pending → applied and writes integration_events row', function () {
        $suggestion = Suggestion::create([
            'kind' => 'test',
            'status' => 'pending',
            'correlation_id' => 'cid-apply',
            'payload' => ['x' => 'y'],
            'proposed_at' => now(),
        ]);

        ApplySuggestionJob::dispatchSync($suggestion->id);

        $suggestion->refresh();
        expect($suggestion->status)->toBe('applied');
        expect($suggestion->applied_at)->not->toBeNull();

        $events = IntegrationEvent::where('channel', 'suggestions')->where('correlation_id', 'cid-apply')->get();
        expect($events)->toHaveCount(1);
        expect($events->first()->operation)->toBe('apply:test');
        expect($events->first()->status)->toBe('success');
    });

    it('ApplySuggestionJob is idempotent — dispatching twice produces ONE integration_events row (D-15)', function () {
        $suggestion = Suggestion::create([
            'kind' => 'test',
            'status' => 'pending',
            'correlation_id' => 'cid-idem',
            'payload' => [],
            'proposed_at' => now(),
        ]);

        ApplySuggestionJob::dispatchSync($suggestion->id);
        ApplySuggestionJob::dispatchSync($suggestion->id); // second call — must be no-op

        $events = IntegrationEvent::where('channel', 'suggestions')->where('correlation_id', 'cid-idem')->count();
        expect($events)->toBe(1);
    });

    it('admin role can access the Filament SuggestionResource list page', function () {
        $this->seed(TestSuggestionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        \Filament\Facades\Filament::auth()->login($admin);

        \Pest\Livewire\livewire(\App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Suggestion::all());
    });

    it('read_only role is denied viewAny via SuggestionPolicy (Pitfall K)', function () {
        $this->seed(TestSuggestionSeeder::class);
        $readOnly = User::factory()->create();
        $readOnly->assignRole('read_only');

        expect($readOnly->can('viewAny', Suggestion::class))->toBeFalse();
    });

    it('sales role is denied viewAny via SuggestionPolicy (Pitfall K)', function () {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        expect($sales->can('viewAny', Suggestion::class))->toBeFalse();
    });

    it('pricing_manager role is denied viewAny via SuggestionPolicy (Pitfall K)', function () {
        $pm = User::factory()->create();
        $pm->assignRole('pricing_manager');

        expect($pm->can('viewAny', Suggestion::class))->toBeFalse();
    });

    it('read_only user cannot invoke approve Action even via crafted POST (Warning 9 defence-in-depth)', function () {
        $this->seed(TestSuggestionSeeder::class);
        $readOnly = User::factory()->create();
        $readOnly->assignRole('read_only');

        \Filament\Facades\Filament::auth()->login($readOnly);
        $suggestion = Suggestion::where('kind', 'test')->first();

        \Pest\Livewire\livewire(\App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions::class)
            ->callTableAction('approve', $suggestion)
            ->assertForbidden();

        // Status unchanged
        expect($suggestion->fresh()->status)->toBe('pending');
    });

    it('read_only user cannot invoke reject Action even via crafted POST (Warning 9 defence-in-depth)', function () {
        $this->seed(TestSuggestionSeeder::class);
        $readOnly = User::factory()->create();
        $readOnly->assignRole('read_only');

        \Filament\Facades\Filament::auth()->login($readOnly);
        $suggestion = Suggestion::where('kind', 'test')->first();

        \Pest\Livewire\livewire(\App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions::class)
            ->callTableAction('reject', $suggestion, data: ['rejection_reason' => 'nope'])
            ->assertForbidden();

        expect($suggestion->fresh()->status)->toBe('pending');
    });

    it('integration_events.subject_id for an applied suggestion stores the ULID and joins back to suggestions (Warning 8)', function () {
        $suggestion = Suggestion::create([
            'kind' => 'test',
            'status' => 'pending',
            'correlation_id' => 'cid-morph-join',
            'payload' => [],
            'proposed_at' => now(),
        ]);

        ApplySuggestionJob::dispatchSync($suggestion->id);

        $event = IntegrationEvent::where('correlation_id', 'cid-morph-join')->firstOrFail();
        expect($event->subject_id)->toBe($suggestion->id);
        expect($event->subject_type)->toBe(Suggestion::class);
        // Morph resolution works end-to-end
        expect($event->subject)->toBeInstanceOf(Suggestion::class);
        expect($event->subject->id)->toBe($suggestion->id);
    });
    ```

    Run: `vendor/bin/pest --filter=SuggestionInbox` — all 14 tests pass.

    **Step K — Write `tests/Feature/SuggestionResourceQueryCountTest.php`** (Gemini Concern MEDIUM — N+1 prevention via getEloquentQuery eager-load):

    ```php
    <?php

    use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
    use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
    use App\Domain\Suggestions\Models\Suggestion;
    use App\Models\User;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\DB;

    beforeEach(function () {
        $this->seed(RolePermissionSeeder::class);
    });

    it('SuggestionResource::getEloquentQuery() eager-loads resolvedByUser', function () {
        $query = SuggestionResource::getEloquentQuery();
        $eagerLoads = $query->getEagerLoads();

        expect($eagerLoads)->toHaveKey('resolvedByUser');
    });

    it('rendering N suggestions executes a BOUNDED number of queries (not N + N)', function () {
        // Seed 10 resolved suggestions — each has a resolvedByUser belongsTo
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        for ($i = 0; $i < 10; $i++) {
            Suggestion::create([
                'kind'                 => 'test',
                'status'               => 'applied',
                'correlation_id'       => "cid-{$i}",
                'payload'              => ['n' => $i],
                'proposed_at'          => now(),
                'resolved_by_user_id'  => $admin->id,
                'resolved_at'          => now(),
                'applied_at'           => now(),
            ]);
        }

        \Filament\Facades\Filament::auth()->login($admin);

        DB::flushQueryLog();
        DB::enableQueryLog();

        \Pest\Livewire\livewire(ListSuggestions::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Suggestion::all());

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // With eager loading: ~3-6 queries total (suggestions select + 1 users IN-clause + filament overhead).
        // Without eager loading: 10 + N queries (one per row to fetch resolvedByUser).
        // Bound is generous to allow filament internal queries; the WIN condition is "not N-per-row".
        expect(count($queries))->toBeLessThan(15)
            ->and(count($queries))->toBeGreaterThan(0);

        // Belt-and-braces: assert no individual query selects a single user by id (typical N+1 shape)
        $selectUserById = collect($queries)->filter(fn ($q) =>
            str_contains($q['query'], 'from `users`') &&
            str_contains($q['query'], '`id` = ?')
        )->count();
        expect($selectUserById)->toBe(0);  // eager load uses WHERE id IN (...) instead
    });
    ```

    Run: `vendor/bin/pest --filter=SuggestionResourceQueryCount` — both tests pass.
  </action>
  <verify>
    <automated>test -f app/Domain/Suggestions/Contracts/SuggestionApplier.php &amp;&amp; test -f app/Domain/Suggestions/Appliers/StubApplier.php &amp;&amp; test -f app/Domain/Suggestions/Services/SuggestionApplierResolver.php &amp;&amp; test -f app/Domain/Suggestions/Jobs/ApplySuggestionJob.php &amp;&amp; test -f app/Domain/Suggestions/Policies/SuggestionPolicy.php &amp;&amp; test -f app/Domain/Suggestions/Filament/Resources/SuggestionResource.php &amp;&amp; test -f database/seeders/TestSuggestionSeeder.php &amp;&amp; grep -q "TestSuggestionSeeder::class" database/seeders/DatabaseSeeder.php &amp;&amp; grep -q "register('test'" app/Providers/AppServiceProvider.php &amp;&amp; grep -q "Gate::policy" app/Providers/AppServiceProvider.php &amp;&amp; grep -q "getEloquentQuery" app/Domain/Suggestions/Filament/Resources/SuggestionResource.php &amp;&amp; php artisan migrate --force &amp;&amp; php artisan db:seed --class=TestSuggestionSeeder --force &amp;&amp; vendor/bin/pest --filter=SuggestionInbox &amp;&amp; vendor/bin/pest --filter=SuggestionResourceQueryCount</automated>
  </verify>
  <done>
    SuggestionApplier contract + StubApplier + Resolver + Job + Policy + Filament Resource + TestSuggestionSeeder all shipped; AppServiceProvider registers StubApplier for kind=test and wires Gate policy; seeder adds exactly one pending suggestion (idempotent); ApplySuggestionJob is idempotent on status=applied (one integration_events row regardless of retry count); integration_events.subject_id stores the Suggestion ULID and joins back to suggestions (Warning 8 — proves nullableUlidMorphs is functioning end-to-end); read_only/sales/pricing_manager roles all 403 on SuggestionResource viewAny (Pitfall K mitigated); crafted POST against approve/reject Actions as read_only returns 403 per ->authorize() gate (Warning 9 defence-in-depth); admin can access and see the seeded suggestion; SuggestionInboxTest passes all 14 cases.
  </done>
</task>

</tasks>

<threat_model>
(See plan-level threat model — 9 threats addressed across all 3 tasks.)
</threat_model>

<verification>
- `php artisan migrate` creates webhook_receipts, suggestions, sync_diffs tables (verified via `php artisan db:table`)
- `vendor/bin/pest --filter=ShadowMode` passes (4 tests — shadow-mode Woo gate)
- `vendor/bin/pest --filter=WooWebhook` passes (8 tests — HMAC accept, reject-tampered, reject-missing-sig, reject-no-secret, dedup-by-delivery-id, X-Correlation-Id propagation, <200ms latency, customer route)
- `vendor/bin/pest --filter=SuggestionInbox` passes (11 tests — contract + StubApplier + resolver + seeder idempotent + ApplySuggestionJob idempotent + Filament page + 3 role denial tests)
- `vendor/bin/pest --filter=WebhookReceiptRedaction` passes (2 tests — static method + end-to-end redacts inbound Auth/Cookie/Signature on persist; non-sensitive headers preserved) — Gemini Concern MEDIUM
- `vendor/bin/pest --filter=SuggestionResourceQueryCount` passes (2 tests — eager-load assertion + DB::getQueryLog() count bounded under 15 queries for 10 rows; zero per-row user lookups) — Gemini Concern MEDIUM
- `vendor/bin/deptrac analyse --no-progress` exits 0 — all cross-boundary references go through `App\Foundation\*` (IntegrationLogger, Auditor) or the published `SuggestionApplier` contract; no Domain→Domain imports
- `curl -X POST -H "X-WC-Webhook-Signature: $(echo -n '{}' | openssl dgst -sha256 -hmac 'test-secret-alphanum-only' -binary | base64)" -H "X-WC-Webhook-Delivery-ID: manual-test-1" http://localhost:8000/webhooks/woo/order -d '{}'` returns `{"status":"accepted"}`
- `php artisan db:seed --class=DatabaseSeeder --force` seeds 4 roles + 1 test suggestion without errors
- After seeding, `php artisan tinker --execute="echo \App\Domain\Suggestions\Models\Suggestion::where('kind','test')->count();"` prints `1`
</verification>

<success_criteria>
- **FOUND-06 (Suggestions):** schema present, Filament inbox reachable, admin can approve/reject seeded test suggestion via StubApplier; Pitfall K guard (non-admin roles 403) feature-tested
- **FOUND-07 (HMAC webhooks):** middleware verifies HMAC with hash_equals on raw body, dedup by (source, delivery_id) prevents double-processing, <200ms acceptance test passes, 8 feature tests covering all attack vectors
- **FOUND-08 (Shadow mode):** WOO_WRITE_ENABLED=false default causes every WooClient write to land in sync_diffs; feature test asserts Automattic client is never called in shadow mode
- **Success Criterion 3 (roadmap):** signed Woo webhook → webhook_receipts row → event dispatched within 200ms — feature tested end-to-end
- **Success Criterion 4 (roadmap):** shadow-mode gate routes writes to sync_diffs — feature tested end-to-end
- **Success Criterion 6 (roadmap):** seeded test suggestion approved/rejected — feature tested end-to-end
- Plans 04 does NOT overlap file-ownership with Plan 05 (routes/webhooks.php vs config/horizon.php etc.), so Wave 3 parallel execution is safe
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation/01-04-SUMMARY.md` documenting: the exact HMAC signature format verified, the dedup outcome (unique constraint fires on retries), the observed <200ms latency number from the feature test, and a matrix showing which `suggestion.kind` values are registered with the resolver (Phase 1: only `test`; later phases extend).
</output>
