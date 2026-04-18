---
phase: 02-supplier-sync
plan: 03
type: execute
wave: 3
depends_on:
  - 02-01
  - 02-02
files_modified:
  - app/Foundation/Events/DomainEvent.php
  - app/Domain/Sync/Events/SupplierPriceChanged.php
  - app/Domain/Sync/Events/SupplierStockChanged.php
  - app/Domain/Sync/Events/SupplierSkuMissing.php
  - app/Domain/Sync/Events/NewSupplierSkuDetected.php
  - app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php
  - app/Domain/Sync/Services/WooProductIterator.php
  - app/Domain/Sync/Services/SkuMatcher.php
  - app/Domain/Sync/Services/AbortGuard.php
  - app/Domain/Sync/Services/SyncDiffEngine.php
  - app/Domain/Sync/Exceptions/SyncAbortException.php
  - app/Domain/Sync/Jobs/SyncChunkJob.php
  - app/Domain/Sync/Jobs/MarkMissingSkusJob.php
  - app/Domain/Sync/Commands/SyncSupplierCommand.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/EventServiceProvider.php
  - routes/console.php
  - tests/Feature/DomainEventAfterCommitTest.php
  - tests/Feature/WooProductIteratorTest.php
  - tests/Feature/SkuMatcherTest.php
  - tests/Feature/AbortGuardTest.php
  - tests/Feature/SyncDiffEngineTest.php
  - tests/Feature/SyncChunkJobTest.php
  - tests/Feature/SyncChunkFailureTest.php
  - tests/Feature/MissingSkuHandlingTest.php
  - tests/Feature/ExcludeFromAutoUpdateTest.php
  - tests/Feature/DryRunModeTest.php
  - tests/Feature/SyncResumeTest.php
  - tests/Feature/SyncSupplierCommandFlagsTest.php
  - tests/Feature/SupplierEventDispatchTest.php
autonomous: true
requirements:
  - SYNC-01
  - SYNC-03
  - SYNC-05
  - SYNC-06
  - SYNC-07
  - SYNC-09
  - SYNC-10
  - SYNC-13

must_haves:
  truths:
    - "`php artisan sync:supplier` with no flags runs in DRY-RUN mode and writes zero real Woo calls (D-04)"
    - "`php artisan sync:supplier --live --dry-run` exits with a non-zero code and a clear error message (D-04 mutually exclusive)"
    - "`php artisan sync:supplier --resume={run_id}` picks up from cursor_page + cursor_sku and does not double-push already-synced SKUs (SYNC-03)"
    - "A SyncChunkJob that encounters a per-SKU failure records it in sync_errors and continues processing the remainder of the page (SYNC-05)"
    - "D-06 aborts fire: >20% error rate after 500 SKUs OR 50 consecutive failures OR JWT refresh failure → run.status='aborted', cursor persisted, ThrottledFailedJobNotifier alert fired once"
    - "Missing-at-supplier SKUs flip to Woo status=pending UNLESS is_custom_ms=true (D-03/SYNC-06); missing variations flip to status=private (D-03 granular)"
    - "`_exclude_from_auto_update` products are skipped but counted (SYNC-07)"
    - "After a successful write, `SupplierPriceChanged` / `SupplierStockChanged` / `SupplierSkuMissing` / `NewSupplierSkuDetected` events dispatch via DomainEvent+ShouldDispatchAfterCommit; listeners only fire after the enclosing transaction commits (Pitfall P2-I retrofit)"
    - "Phase 1's full 92-test suite still passes AFTER the DomainEvent retrofit to ShouldDispatchAfterCommit"
  artifacts:
    - path: "app/Foundation/Events/DomainEvent.php"
      provides: "base class now implements ShouldDispatchAfterCommit (Phase 1 retrofit — Pitfall P2-I)"
    - path: "app/Domain/Sync/Events/SupplierPriceChanged.php"
      provides: "DomainEvent with {sku, wooProductId, wooVariationId, oldPrice, newPrice, reason}"
    - path: "app/Domain/Sync/Events/SupplierStockChanged.php"
      provides: "DomainEvent with {sku, wooProductId, wooVariationId, oldStock, newStock, reason}"
    - path: "app/Domain/Sync/Events/SupplierSkuMissing.php"
      provides: "DomainEvent with {sku, wooProductId, wooVariationId, hadCustomMsTag, newStatus}"
    - path: "app/Domain/Sync/Events/NewSupplierSkuDetected.php"
      provides: "DomainEvent (D-09) with {sku, supplierPrice, supplierStock} — Phase 6 AUTO-01 producer"
    - path: "app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php"
      provides: "No-op listener so the event doesn't pile up in failed_jobs pre-Phase 6"
    - path: "app/Domain/Sync/Services/WooProductIterator.php"
      provides: "Generator yielding [page, skus[]] — simples + variations flattened (D-02)"
    - path: "app/Domain/Sync/Services/SkuMatcher.php"
      provides: "build(array) + match(string): ?array in-memory supplier-SKU hashmap"
    - path: "app/Domain/Sync/Services/AbortGuard.php"
      provides: "throwIfTriggered/recordSuccess/recordFailure — D-06 tiered aborts (a/b/c)"
    - path: "app/Domain/Sync/Services/SyncDiffEngine.php"
      provides: "diff(skuRow, supplierRow): ?array — returns {endpoint, payload, action, reason, old_*, new_*}"
    - path: "app/Domain/Sync/Jobs/SyncChunkJob.php"
      provides: "ShouldQueue on sync-woo-push; handles ≤100 SKUs; per-SKU idempotency via last_synced_at > run.started_at"
    - path: "app/Domain/Sync/Jobs/MarkMissingSkusJob.php"
      provides: "Post-sync pass — flips missing-SKU Woo products/variations per D-03"
    - path: "app/Domain/Sync/Commands/SyncSupplierCommand.php"
      provides: "extends BaseCommand; signature `sync:supplier {--live} {--dry-run} {--resume=}`; flag-exclusivity guard"
    - path: "routes/console.php"
      provides: "Commented-out daily cron entry for `sync:supplier --live` — kill-switch per D-05"
  key_links:
    - from: "app/Foundation/Events/DomainEvent.php"
      to: "Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit"
      via: "interface implemented on the base class (Pitfall P2-I)"
      pattern: "ShouldDispatchAfterCommit"
    - from: "app/Domain/Sync/Commands/SyncSupplierCommand.php"
      to: "app/Domain/Sync/Services/SupplierClient.php"
      via: "$supplier->fetchAllProducts() builds in-memory hashmap"
      pattern: "fetchAllProducts"
    - from: "app/Domain/Sync/Commands/SyncSupplierCommand.php"
      to: "app/Domain/Sync/Services/WooProductIterator.php"
      via: "foreach ($iterator->pages(fromPage: $run->cursor_page) as $page)"
      pattern: "->pages\\(fromPage:"
    - from: "app/Domain/Sync/Jobs/SyncChunkJob.php"
      to: "app/Domain/Sync/Models/SyncRun.php"
      via: "SyncRun::findOrFail + ->incrementCounter"
      pattern: "SyncRun::findOrFail"
    - from: "app/Domain/Sync/Jobs/SyncChunkJob.php"
      to: "app/Domain/Sync/Events/SupplierPriceChanged.php"
      via: "event(new SupplierPriceChanged(...)) after successful write"
      pattern: "new Supplier(Price|Stock)Changed"
    - from: "app/Domain/Sync/Commands/SyncSupplierCommand.php"
      to: "app/Console/Commands/BaseCommand.php"
      via: "extends BaseCommand; implements perform()"
      pattern: "extends BaseCommand"
---

<objective>
Build the Phase 2 orchestrator: the SyncSupplierCommand that fetches the supplier catalogue, paginates Woo, dispatches SyncChunkJob per page, tracks aborts via AbortGuard, emits 4 domain events, and writes CSV report rows via SyncRunItem. Plus the retrofit: add `ShouldDispatchAfterCommit` to Phase 1's `DomainEvent` base class so events don't fire on rolled-back transactions (Pitfall P2-I) — verified by re-running Phase 1's 92-test suite.

This is the heaviest plan of Phase 2. It splits logically into: (T1) DomainEvent retrofit + 4 new events + stub listener; (T2) support services (WooProductIterator, SkuMatcher, AbortGuard, SyncDiffEngine); (T3) SyncChunkJob + MarkMissingSkusJob + SyncSupplierCommand + routes/console cron skeleton.

Purpose: This is the whole sync pipeline. All success criteria 1-4 and 6 from ROADMAP.md Phase 2 run through this plan. Plan 04 (reporting + Filament) and Plan 05 (guardrails) are non-functional without this.

Output: 4 events + 1 listener + 4 services + 2 jobs + 1 command + DomainEvent retrofit + routes/console.php update + 13 new test files.

Scope additions beyond REQUIREMENTS.md (explicit per CONTEXT.md):
- D-04 — `--live` flag + flag-conflict validation on sync:supplier command
- D-05 — commented-out daily cron entry in routes/console.php (kill-switch)
- D-06 — AbortGuard service (tiered abort policy)
- D-07 — --resume cursor-resumable semantics on sync:supplier
- D-09 — NewSupplierSkuDetected event + no-op stub listener
- Pitfall P2-I — DomainEvent base class retrofit to ShouldDispatchAfterCommit (Phase 1 behaviour change, full suite regression required)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/phases/02-supplier-sync/02-RESEARCH.md
@.planning/phases/02-supplier-sync/02-01-SUMMARY.md
@.planning/phases/02-supplier-sync/02-02-SUMMARY.md
@.planning/phases/01-foundation/01-03-SUMMARY.md
@.planning/phases/01-foundation/01-04-SUMMARY.md
@.planning/phases/01-foundation/01-05-SUMMARY.md
@.planning/research/PITFALLS.md
@app/Foundation/Events/DomainEvent.php
@app/Console/Commands/BaseCommand.php
@app/Domain/Sync/Services/WooClient.php
@app/Domain/Sync/Services/SupplierClient.php

<interfaces>
<!-- Contracts this plan consumes from earlier plans. Do not re-explore. -->

From P01 (02-01-data-model) — SyncRun model API:
```php
SyncRun::STATUS_QUEUED|RUNNING|COMPLETED|ABORTED|FAILED;
SyncRun::ABORT_ERROR_RATE|ABORT_CONSECUTIVE|ABORT_JWT_REFRESH|ABORT_MANUAL;
$run->markRunning();
$run->abort(string $reason, ?string $message = null);
$run->finalise();
$run->incrementCounter(string $action);  // 'updated'|'skipped'|'failed'|'missing'|'unknown_sku'
SyncRun::findResumable(int $id): self;
$run->errors();  // HasMany
$run->items();   // HasMany (SyncRunItem)
$run->cursor_page; $run->cursor_sku; $run->dry_run; $run->correlation_id;

// Write-only: SyncError::create([sync_run_id, sku, woo_product_id?, woo_variation_id?, error_class, error_message, correlation_id, created_at])
// Write-only: SyncRunItem::create([sync_run_id, sku, woo_*, action, reason, old_price, new_price, old_stock, new_stock, error_message, correlation_id, created_at])
// Write-only: ImportIssue::create([sku, woo_*, issue_type, detected_at, last_seen_at, notes, correlation_id])
```

From P02 (02-02-external-clients):
```php
WooClient::get(string $endpoint, array $query = []): array;  // NEW — throws HttpClientException on 4xx/5xx
WooClient::put/post/patch/delete(...): array;  // Phase 2 — real writes when WOO_WRITE_ENABLED=true

SupplierClient::fetchAllProducts(): array;  // ['SKU' => ['price' => '99.00', 'stock' => 5], ...]
// Throws JwtRefreshFailedException on persistent 401 (D-06c)
```

From Phase 1 P03 — BaseCommand + Context:
```php
abstract class BaseCommand extends Command
{
    final public function handle(): int;  // seeds correlation_id + LogBatch, calls perform()
    abstract protected function perform(): int;
}
// Context::get('correlation_id') always populated inside perform()
```

From Phase 1 P04 — SuggestionApplier seam (not used here but Pitfall K pattern reminder):
- ApplySuggestionJob uses `onQueue('default')` in constructor (PHP 8.4 trait conflict workaround)
- All queueable jobs: use `$this->onQueue(...)` in __construct, not class-level `$queue` property

From Phase 1 P05 — Horizon supervisors:
- `sync-bulk` queue → sync-bulk-supervisor (1 proc, 1800s timeout) — for SyncSupplierCommand orchestrator
- `sync-woo-push` queue → sync-woo-push-supervisor (2-3 procs, 90s timeout) — for SyncChunkJob. **Research Pitfall P2-E recommends bumping to 120s**; research says this can be done as part of Plan 03.

From Phase 1 P05 — ThrottledFailedJobNotifier:
- Subscribed to JobFailed event; 5-min atomic dedup via Cache::add
- Phase 2 aborts trigger it automatically if the orchestrator job exhausts retries
</interfaces>

</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Retrofit DomainEvent with ShouldDispatchAfterCommit + 4 Phase 2 events + stub listener + re-run Phase 1 regression suite</name>
  <files>
    app/Foundation/Events/DomainEvent.php,
    app/Domain/Sync/Events/SupplierPriceChanged.php,
    app/Domain/Sync/Events/SupplierStockChanged.php,
    app/Domain/Sync/Events/SupplierSkuMissing.php,
    app/Domain/Sync/Events/NewSupplierSkuDetected.php,
    app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php,
    app/Providers/EventServiceProvider.php,
    tests/Feature/DomainEventAfterCommitTest.php,
    tests/Feature/SupplierEventDispatchTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §Pattern 3 (lines 370-391), Pitfall P2-I (lines 1218-1227 — ShouldDispatchAfterCommit retrofit), §6 Variable Product Sync Logic (lines 836-854)
    - 02-CONTEXT.md — Pitfall P2-I as Phase 2 Plan 03 responsibility (implicit from <canonical_refs> line 105 "extend for ... ShouldDispatchAfterCommit"), D-09 NewSupplierSkuDetected (lines 46-50)
    - 01-03-SUMMARY.md (DomainEvent current state — Dispatchable + SerializesModels only; subclass convention)
    - 01-04-SUMMARY.md (OrderReceived, CustomerRegistered, SuggestionProposed, SuggestionApproved subclasses — all 4 need to be re-tested after retrofit)
    - 01-05-SUMMARY.md (EventServiceProvider creation pattern in bootstrap/providers.php; how to register a listener)
    - app/Foundation/Events/DomainEvent.php (current state — loaded in interfaces)
    - app/Providers/EventServiceProvider.php (current state — already maps JobFailed → ThrottledFailedJobNotifier per P05)
  </read_first>
  <behavior>
    Tests in tests/Feature/DomainEventAfterCommitTest.php (proves Pitfall P2-I fix):
    - Test A1: Dispatching a DomainEvent subclass OUTSIDE a transaction fires listeners immediately (unchanged semantics for existing callers).
    - Test A2: Dispatching a DomainEvent subclass INSIDE `DB::transaction()` that commits → listener fires AFTER commit.
    - Test A3: Dispatching a DomainEvent subclass INSIDE `DB::transaction()` that rolls back (via exception or manual rollback) → listener DOES NOT fire. This is the Pitfall P2-I fix — proven by `Event::fake() + DB::transaction fn() => { event(new SupplierPriceChanged(...)); throw new Exception(); } catch (...) + Event::assertNotDispatched(SupplierPriceChanged::class)`.
    - Test A4 (regression): Phase 1's existing 4 DomainEvent subclasses (OrderReceived, CustomerRegistered, SuggestionProposed, SuggestionApproved) also respect after-commit when dispatched in transactions — sample 2 of the 4 via identical transaction-rollback tests.
    - Test A5: The DomainEvent base class implements `Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit` — reflection assertion.

    Tests in tests/Feature/SupplierEventDispatchTest.php (SYNC-13):
    - Test E1: Creating a SupplierPriceChanged event populates correlationId (from Context) + occurredAt + payload fields {sku, wooProductId, wooVariationId, oldPrice, newPrice, reason}.
    - Test E2: SupplierStockChanged payload shape {sku, wooProductId, wooVariationId, oldStock, newStock, reason}.
    - Test E3: SupplierSkuMissing payload shape {sku, wooProductId, wooVariationId, hadCustomMsTag, newStatus}.
    - Test E4: NewSupplierSkuDetected payload {sku, supplierPrice, supplierStock}.
    - Test E5: All 4 events extend DomainEvent AND implement ShouldDispatchAfterCommit via inheritance.
    - Test E6: Dispatching `NewSupplierSkuDetected` invokes `StubNewSupplierSkuListener::handle()` exactly once (registered in EventServiceProvider; no-op body but must be wired).

    Phase 1 regression (no new test file — existing suite):
    - Re-run `vendor/bin/pest` — all 92 pre-existing tests still pass. If any fail, the retrofit needs a targeted fix (most likely: any test that used `Event::fake()` inside DB::transaction and then rolled back expecting the event to fire will need updating).
  </behavior>
  <action>
**1. Retrofit `app/Foundation/Events/DomainEvent.php`** — single edit, additive interface:
```php
namespace App\Foundation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * [existing docblock — preserve]
 *
 * Pitfall P2-I (Phase 2 retrofit): implements ShouldDispatchAfterCommit so events
 * dispatched inside DB::transaction() that rolls back do NOT fire listeners.
 * Critical for SyncChunkJob which wraps per-SKU writes in transactions — a rolled-back
 * Woo write MUST NOT trigger Phase 3's price-recompute listener.
 */
abstract class DomainEvent implements ShouldDispatchAfterCommit
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

**2. Create the 4 Phase 2 domain events** — all extend DomainEvent, primitive fields only (T-03-05 convention):

`app/Domain/Sync/Events/SupplierPriceChanged.php`:
```php
namespace App\Domain\Sync\Events;

use App\Foundation\Events\DomainEvent;

final class SupplierPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly string $oldPrice,
        public readonly string $newPrice,
        public readonly string $reason = 'supplier_sync',
    ) {
        parent::__construct();
    }
}
```

`SupplierStockChanged.php`:
```php
final class SupplierStockChanged extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly int $oldStock,
        public readonly int $newStock,
        public readonly string $reason = 'supplier_sync',
    ) { parent::__construct(); }
}
```

`SupplierSkuMissing.php`:
```php
final class SupplierSkuMissing extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly bool $hadCustomMsTag,
        public readonly string $newStatus,  // 'pending' | 'private' | 'publish' (unchanged)
    ) { parent::__construct(); }
}
```

`NewSupplierSkuDetected.php` (D-09 — Phase 6 AUTO-01 producer):
```php
final class NewSupplierSkuDetected extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly string $supplierPrice,
        public readonly int $supplierStock,
    ) { parent::__construct(); }
}
```

**3. Create `app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php`** (D-09 no-op):
```php
namespace App\Domain\Sync\Listeners;

use App\Domain\Sync\Events\NewSupplierSkuDetected;
use Illuminate\Support\Facades\Log;

/**
 * D-09 stub listener — Phase 2 establishes the event producer; Phase 6 wires the
 * real CreateWooProductJob listener. Without this stub the event would accumulate
 * in failed_jobs waiting for a handler.
 */
final class StubNewSupplierSkuListener
{
    public function handle(NewSupplierSkuDetected $event): void
    {
        Log::info('NewSupplierSkuDetected (stub — Phase 6 wires the real handler)', [
            'sku' => $event->sku,
            'correlation_id' => $event->correlationId,
        ]);
    }
}
```

**4. Register the listener in `app/Providers/EventServiceProvider.php`** (additive — keep existing JobFailed → ThrottledFailedJobNotifier map):
```php
protected $listen = [
    // Phase 1 Plan 05:
    \Illuminate\Queue\Events\JobFailed::class => [\App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier::class],

    // Phase 2 Plan 03 (D-09 stub):
    \App\Domain\Sync\Events\NewSupplierSkuDetected::class => [\App\Domain\Sync\Listeners\StubNewSupplierSkuListener::class],
];
```

**5. Write `tests/Feature/DomainEventAfterCommitTest.php`** — all 5 tests A1-A5. Use `Event::fake([SupplierPriceChanged::class])` + `DB::transaction()` + `DB::rollBack()` to prove the after-commit behaviour.

**6. Write `tests/Feature/SupplierEventDispatchTest.php`** — all 6 tests E1-E6.

**7. Run Phase 1 regression:**
```bash
vendor/bin/pest --filter=CorrelationIdPropagation --filter=SuggestionInbox --filter=WooWebhook --filter=FailedJobAlert
vendor/bin/pest  # full suite — MUST remain ≥ 92 passing on Phase 1 tests
```

**If any Phase 1 test fails:**
- The most likely regression is a test that fakes an event inside a transaction then expects it to fire regardless. Update those tests to either (a) not use a transaction, or (b) assert the event AFTER `DB::commit()`.
- Document in the deviations section of the eventual 02-03-SUMMARY.md.

**Self-check:**
```bash
vendor/bin/pest --filter=DomainEventAfterCommit --filter=SupplierEventDispatch
vendor/bin/pest  # full suite
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=DomainEventAfterCommit &amp;&amp; vendor/bin/pest --filter=SupplierEventDispatch &amp;&amp; vendor/bin/pest</automated>
  </verify>
  <done>
    - `grep "ShouldDispatchAfterCommit" app/Foundation/Events/DomainEvent.php` matches
    - `grep -r "implements ShouldDispatchAfterCommit" app/Domain/Sync/Events/` returns 0 (subclasses inherit)
    - 4 event classes + 1 listener exist under `app/Domain/Sync/{Events,Listeners}/`
    - EventServiceProvider maps `NewSupplierSkuDetected::class` to `StubNewSupplierSkuListener::class`
    - DomainEventAfterCommitTest — 5 tests green; SupplierEventDispatchTest — 6 tests green
    - Full Pest suite ≥ 137 passing (Phase 1: 92 + P01: 18 + P02: 16 + P03 T1: 11)
    - ZERO regressions in Phase 1 test files (CorrelationIdPropagation, SuggestionInbox, WooWebhook, FailedJobAlert, etc.)
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Ship WooProductIterator, SkuMatcher, AbortGuard, SyncDiffEngine — the 4 stateless services the orchestrator composes</name>
  <files>
    app/Domain/Sync/Services/WooProductIterator.php,
    app/Domain/Sync/Services/SkuMatcher.php,
    app/Domain/Sync/Services/AbortGuard.php,
    app/Domain/Sync/Services/SyncDiffEngine.php,
    app/Domain/Sync/Exceptions/SyncAbortException.php,
    tests/Feature/WooProductIteratorTest.php,
    tests/Feature/SkuMatcherTest.php,
    tests/Feature/AbortGuardTest.php,
    tests/Feature/SyncDiffEngineTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §3 WooProductIterator (lines 632-723 — exact generator code + variable-product variations loop), §4 SyncRun state machine (already loaded), §Pattern 4 AbortGuard (lines 392-429 — exact counter logic), §6 Variable Product Sync Logic (lines 836-854 — diff engine write-path match), §8 custom-ms + exclude meta detection (lines 873-895), §Open Questions resolved (lines 1418-1425 — price 2dp string match + stock int exact)
    - 02-CONTEXT.md — D-02 (variable/simple branching), D-06 (tiered abort triggers), D-07 (resume persistence)
    - PITFALLS.md Pitfall 17 (variable products), Pitfall 18 (stock race — not mitigated in v1 per research; documented)
    - app/Domain/Sync/Services/WooClient.php (get method signature)
    - app/Domain/Products/Models/Product.php + ProductVariant.php (from P01 — relationship + casts)
  </read_first>
  <behavior>
    Tests in tests/Feature/WooProductIteratorTest.php:
    - Test I1: Paginates Woo /products via WooClient::get; stops when a page returns < 100 rows.
    - Test I2: `simple` product yields a row with {type: 'simple', sku, woo_product_id, woo_variation_id: null, price, stock_quantity, manage_stock, is_custom_ms, exclude_from_auto_update}.
    - Test I3: `variable` product triggers a SECOND Woo call to `products/{id}/variations` and yields one row per variation with type='variation' inheriting parent's custom-ms / exclude flag.
    - Test I4: Variable product with > 100 variations — inner pagination follows through all pages (A9 mitigation).
    - Test I5: `custom-ms` tag detection is case-insensitive on slug AND matches regardless of id/name; Test I6: `_exclude_from_auto_update` meta detection matches value='yes' AND value='1' AND value=true.
    - Test I7: `fromPage: 7` starts pagination at page 7 (resume semantics).
    - Test I8: Grouped/external products are skipped (v1 scope) — no row yielded.

    Tests in tests/Feature/SkuMatcherTest.php:
    - Test M1: `build($supplierFeed)->match('SKU-123')` returns supplier row; match on unknown SKU returns null.
    - Test M2: Matcher is case-sensitive on SKUs (Woo convention per AUTO-08 preview; ops can decide Phase 6).
    - Test M3: Stores supplierFeed by reference efficiently — a 10,000-SKU feed built + 10k matches runs in < 100ms (perf sanity).

    Tests in tests/Feature/AbortGuardTest.php:
    - Test B1: Error rate ≤ 20% after 500 samples → throwIfTriggered does NOT throw.
    - Test B2: Error rate > 20% after 500 samples → throws SyncAbortException with reason=error_rate (D-06a).
    - Test B3: Error rate > 20% with ONLY 400 samples → does NOT throw (below min-samples threshold).
    - Test B4: 50 consecutive failures (recordFailure → recordFailure × 50, no recordSuccess between) → throws with reason=consecutive_failures (D-06b).
    - Test B5: recordSuccess resets the consecutive counter to 0.
    - Test B6: Marking jwt_refresh_failed = true → throwIfTriggered throws with reason=jwt_refresh (D-06c).
    - Test B7: AbortGuard counters are per-run — creating a new SyncRun resets all counters (test by dispatching two independent runs).

    Tests in tests/Feature/SyncDiffEngineTest.php:
    - Test D1: Supplier row matches Woo row exactly (same price to 2dp, same stock int) → diff returns null (no-op).
    - Test D2: Supplier price differs → diff returns {action: 'updated', endpoint: 'products/1234', payload: {regular_price: '199.00'}, old_price: '180.00', new_price: '199.00', reason: null}.
    - Test D3: Supplier stock differs → payload includes stock_quantity.
    - Test D4: Both differ → payload includes both; action='updated'; old_*/new_* cover both.
    - Test D5: skuRow.type='variation' → endpoint='products/{parent}/variations/{vid}'; skuRow.type='simple' → endpoint='products/{id}'.
    - Test D6: exclude_from_auto_update=true on skuRow → diff returns {action: 'skipped', reason: 'exclude_from_auto_update', payload: []} regardless of price/stock difference (SYNC-07).
    - Test D7: Supplier row missing (null) → returns null here; missing handling is in MarkMissingSkusJob (separate pass).
    - Test D8: Price 2dp comparison is exact-string after trimming trailing zeros — '199.00' === '199.0' === '199' per D-discretion point 6.
  </behavior>
  <action>
**1. `app/Domain/Sync/Exceptions/SyncAbortException.php`:**
```php
namespace App\Domain\Sync\Exceptions;

final class SyncAbortException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Sync aborted: {$reason}");
    }
}
```

**2. `app/Domain/Sync/Services/WooProductIterator.php`** — RESEARCH §3 verbatim with inner pagination for > 100 variations:
```php
namespace App\Domain\Sync\Services;

/**
 * Paginates Woo /products + /products/{id}/variations at per_page=100 (Woo hard cap).
 * Yields a flat stream of sync units — simples + variations — for the SyncChunkJob.
 *
 * Gotcha (A9): Variable parents with > 100 variations need inner pagination too.
 */
final class WooProductIterator
{
    public function __construct(private WooClient $woo) {}

    /** @return \Generator<int, array{page: int, skus: array<int, array>}> */
    public function pages(int $fromPage = 1): \Generator
    {
        $page = max(1, $fromPage);
        do {
            $products = $this->woo->get('products', ['per_page' => 100, 'page' => $page]);
            if (empty($products)) break;

            $skus = [];
            foreach ($products as $p) {
                $isCustomMs = $this->hasSlug($p['tags'] ?? [], 'custom-ms');
                $excludeFlag = $this->hasMeta($p['meta_data'] ?? [], '_exclude_from_auto_update');

                if (($p['type'] ?? 'simple') === 'simple') {
                    $skus[] = [
                        'type' => 'simple',
                        'sku' => $p['sku'] ?? '',
                        'woo_product_id' => (int) $p['id'],
                        'woo_variation_id' => null,
                        'price' => (string) ($p['regular_price'] ?? $p['price'] ?? ''),
                        'stock_quantity' => (int) ($p['stock_quantity'] ?? 0),
                        'manage_stock' => (bool) ($p['manage_stock'] ?? false),
                        'is_custom_ms' => $isCustomMs,
                        'exclude_from_auto_update' => $excludeFlag,
                    ];
                } elseif (($p['type'] ?? '') === 'variable') {
                    $variationPage = 1;
                    do {
                        $variations = $this->woo->get("products/{$p['id']}/variations", [
                            'per_page' => 100, 'page' => $variationPage,
                        ]);
                        foreach ($variations as $v) {
                            $skus[] = [
                                'type' => 'variation',
                                'sku' => $v['sku'] ?? '',
                                'woo_product_id' => (int) $p['id'],
                                'woo_variation_id' => (int) $v['id'],
                                'price' => (string) ($v['regular_price'] ?? $v['price'] ?? ''),
                                'stock_quantity' => (int) ($v['stock_quantity'] ?? 0),
                                'manage_stock' => is_bool($v['manage_stock'] ?? null) ? (bool) $v['manage_stock'] : ($v['manage_stock'] === 'parent'),
                                'is_custom_ms' => $isCustomMs,
                                'exclude_from_auto_update' => $excludeFlag,
                                'attributes' => $v['attributes'] ?? [],
                            ];
                        }
                        $variationPage++;
                    } while (count($variations) === 100);
                }
                // grouped/external: skipped (v1 scope)
            }

            yield ['page' => $page, 'skus' => $skus];
            $page++;
        } while (count($products) === 100);
    }

    private function hasSlug(array $tags, string $slug): bool
    {
        foreach ($tags as $tag) {
            if (strtolower((string) ($tag['slug'] ?? '')) === strtolower($slug)) return true;
        }
        return false;
    }

    private function hasMeta(array $meta, string $key): bool
    {
        foreach ($meta as $m) {
            if (($m['key'] ?? '') === $key) {
                $value = $m['value'] ?? null;
                return $value === 'yes' || $value === '1' || $value === true || $value === 1;
            }
        }
        return false;
    }
}
```

**3. `app/Domain/Sync/Services/SkuMatcher.php`** — in-memory hashmap:
```php
namespace App\Domain\Sync\Services;

/**
 * In-memory supplier-SKU → row hashmap. ~15k SKUs × ~120 bytes = 1.8MB (A4).
 * Built once per SyncRun at orchestrator start; shared across chunks via the serialised
 * SyncChunkJob payload (Pitfall P2-D). Re-built every run — no cache across runs.
 */
final class SkuMatcher
{
    /** @var array<string, array{price: string, stock: int}> */
    private array $map = [];

    public function build(array $supplierFeed): self
    {
        $this->map = $supplierFeed;
        return $this;
    }

    public function match(string $sku): ?array
    {
        return $this->map[$sku] ?? null;
    }

    public function supplierSkus(): array
    {
        return array_keys($this->map);
    }

    public function count(): int
    {
        return count($this->map);
    }
}
```

**4. `app/Domain/Sync/Services/AbortGuard.php`** — RESEARCH Pattern 4 verbatim, keyed by run id in-memory:
```php
namespace App\Domain\Sync\Services;

use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Models\SyncRun;

final class AbortGuard
{
    private const ERROR_RATE_THRESHOLD = 0.20;      // D-06(a)
    private const ERROR_RATE_MIN_SAMPLES = 500;
    private const CONSECUTIVE_FAILURE_THRESHOLD = 50; // D-06(b)

    /** @var array<int, array{processed:int, failed:int, consecutive:int, jwt_broken:bool}> */
    private array $counters = [];

    private function counters(int $runId): array
    {
        return $this->counters[$runId] ??= ['processed' => 0, 'failed' => 0, 'consecutive' => 0, 'jwt_broken' => false];
    }

    public function recordSuccess(int $runId): void
    {
        $c = &$this->counters[$runId];
        $c = ['processed' => $c['processed'] + 1, 'failed' => $c['failed'], 'consecutive' => 0, 'jwt_broken' => $c['jwt_broken']];
    }

    public function recordFailure(int $runId): void
    {
        $c = &$this->counters[$runId];
        $c = ['processed' => $c['processed'] + 1, 'failed' => $c['failed'] + 1, 'consecutive' => $c['consecutive'] + 1, 'jwt_broken' => $c['jwt_broken']];
    }

    public function markJwtRefreshFailed(int $runId): void
    {
        $this->counters($runId);
        $this->counters[$runId]['jwt_broken'] = true;
    }

    public function throwIfTriggered(int $runId): void
    {
        $c = $this->counters($runId);

        if ($c['jwt_broken']) {
            throw new SyncAbortException(SyncRun::ABORT_JWT_REFRESH, 'JWT refresh failed (D-06c).');
        }
        if ($c['consecutive'] >= self::CONSECUTIVE_FAILURE_THRESHOLD) {
            throw new SyncAbortException(SyncRun::ABORT_CONSECUTIVE, "{$c['consecutive']} consecutive failures (D-06b).");
        }
        if ($c['processed'] >= self::ERROR_RATE_MIN_SAMPLES
            && ($c['failed'] / max(1, $c['processed'])) > self::ERROR_RATE_THRESHOLD) {
            $rate = number_format(($c['failed'] / $c['processed']) * 100, 1);
            throw new SyncAbortException(SyncRun::ABORT_ERROR_RATE, "Error rate {$rate}% exceeded 20% after {$c['processed']} SKUs (D-06a).");
        }
    }

    public function resetRun(int $runId): void
    {
        unset($this->counters[$runId]);
    }
}
```

Bind AbortGuard as a singleton in AppServiceProvider::register() so counters persist across chunk jobs in the same run within the same worker process:
```php
$this->app->singleton(\App\Domain\Sync\Services\AbortGuard::class);
```

**5. `app/Domain/Sync/Services/SyncDiffEngine.php`** — compares supplier ↔ Woo:
```php
namespace App\Domain\Sync\Services;

final class SyncDiffEngine
{
    /**
     * Returns null if no diff; else array{action, endpoint, payload, reason?, old_price?, new_price?, old_stock?, new_stock?}
     *
     * SYNC-07: exclude_from_auto_update → skipped regardless of diff.
     */
    public function diff(array $skuRow, ?array $supplierRow): ?array
    {
        if ($skuRow['exclude_from_auto_update'] ?? false) {
            return [
                'action' => 'skipped',
                'endpoint' => $this->endpoint($skuRow),
                'payload' => [],
                'reason' => 'exclude_from_auto_update',
                'old_price' => (string) ($skuRow['price'] ?? ''),
                'new_price' => null,
                'old_stock' => (int) ($skuRow['stock_quantity'] ?? 0),
                'new_stock' => null,
            ];
        }

        if ($supplierRow === null) {
            return null;  // Missing-at-supplier — handled by MarkMissingSkusJob pass
        }

        $oldPriceNorm = $this->normalisePrice((string) ($skuRow['price'] ?? ''));
        $newPriceNorm = $this->normalisePrice((string) ($supplierRow['price'] ?? ''));
        $oldStock = (int) ($skuRow['stock_quantity'] ?? 0);
        $newStock = (int) ($supplierRow['stock'] ?? 0);

        $priceChanged = ($oldPriceNorm !== $newPriceNorm) && $newPriceNorm !== '';
        $stockChanged = ($oldStock !== $newStock);

        if (! $priceChanged && ! $stockChanged) {
            return null;  // no-op
        }

        $payload = [];
        if ($priceChanged) $payload['regular_price'] = (string) $supplierRow['price'];
        if ($stockChanged) $payload['stock_quantity'] = $newStock;

        return [
            'action' => 'updated',
            'endpoint' => $this->endpoint($skuRow),
            'payload' => $payload,
            'old_price' => (string) $skuRow['price'],
            'new_price' => (string) $supplierRow['price'],
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
        ];
    }

    private function endpoint(array $skuRow): string
    {
        return match ($skuRow['type'] ?? 'simple') {
            'simple' => "products/{$skuRow['woo_product_id']}",
            'variation' => "products/{$skuRow['woo_product_id']}/variations/{$skuRow['woo_variation_id']}",
            default => "products/{$skuRow['woo_product_id']}",
        };
    }

    /** Exact 2dp string match — per D-discretion point 6. Strip trailing zeros and trailing dot. */
    private function normalisePrice(string $price): string
    {
        if ($price === '') return '';
        $formatted = number_format((float) $price, 2, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');
        return $trimmed === '' ? '0' : $trimmed;
    }
}
```

**6. Write all 4 test files** per <behavior> (I1-I8, M1-M3, B1-B7, D1-D8). Use WooClient mocks for iterator tests. Use in-memory Product/ProductVariant factories from P01 for diff engine tests.

**Self-check:**
```bash
vendor/bin/pest --filter=WooProductIterator --filter=SkuMatcher --filter=AbortGuard --filter=SyncDiffEngine
vendor/bin/pest
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=WooProductIterator &amp;&amp; vendor/bin/pest --filter=SkuMatcher &amp;&amp; vendor/bin/pest --filter=AbortGuard &amp;&amp; vendor/bin/pest --filter=SyncDiffEngine</automated>
  </verify>
  <done>
    - 4 service classes + 1 exception exist in app/Domain/Sync/{Services,Exceptions}/
    - WooProductIteratorTest — 8 tests green (simple, variable, inner-page pagination, custom-ms slug/case, exclude meta, fromPage resume, grouped-skipped)
    - SkuMatcherTest — 3 tests green (build/match, case-sensitivity, perf < 100ms on 10k)
    - AbortGuardTest — 7 tests green (all D-06 a/b/c triggers, reset, per-run isolation)
    - SyncDiffEngineTest — 8 tests green (no-op, price-only, stock-only, both, variation endpoint, exclude skipped, missing null, 2dp normalisation)
    - Full Pest suite ≥ 163 passing (previous 137 + this task's 26)
    - AbortGuard bound as singleton in AppServiceProvider::register()
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Ship SyncChunkJob + MarkMissingSkusJob + SyncSupplierCommand + routes/console.php cron + 8 feature tests covering SYNC-01/03/05/06/07/09/10/13</name>
  <files>
    app/Domain/Sync/Jobs/SyncChunkJob.php,
    app/Domain/Sync/Jobs/MarkMissingSkusJob.php,
    app/Domain/Sync/Commands/SyncSupplierCommand.php,
    routes/console.php,
    tests/Feature/SyncChunkJobTest.php,
    tests/Feature/SyncChunkFailureTest.php,
    tests/Feature/MissingSkuHandlingTest.php,
    tests/Feature/ExcludeFromAutoUpdateTest.php,
    tests/Feature/DryRunModeTest.php,
    tests/Feature/SyncResumeTest.php,
    tests/Feature/SyncSupplierCommandFlagsTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §Pattern 1 (SyncSupplierCommand verbatim lines 229-295), §Pattern 2 SyncChunkJob (lines 302-367), §5 End-to-End Flow (lines 815-834 — resume correctness + chunk size), §7 Missing Variant Handling (lines 858-872 — MarkMissingSkusJob logic + truth table), Pitfall P2-E (timeout bump 90→120s), Pitfall P2-F (idempotent skip by last_synced_at)
    - 02-CONTEXT.md — D-04 flags, D-05 cron kill-switch, D-06 abort, D-07 resume, D-03 missing variant granular, D-09 unknown SKU → event + ImportIssue
    - 01-03-SUMMARY.md (BaseCommand::perform signature)
    - 01-05-SUMMARY.md (Horizon sync-bulk + sync-woo-push supervisor config, routes/console.php existing TODOs)
    - PITFALLS.md Pitfalls 1, 3, 8, 13, 18
    - app/Domain/Sync/Services/WooClient.php + SupplierClient.php (already loaded)
    - app/Console/Commands/BaseCommand.php (already referenced)
    - routes/console.php (current — has 3 prune schedules; must ADD the sync:supplier commented-out entry without disturbing existing)
  </read_first>
  <behavior>
    Tests in tests/Feature/SyncChunkJobTest.php:
    - Test C1: Job on 'sync-woo-push' queue (assert via `SyncChunkJob::$queue` or constructor's onQueue).
    - Test C2: Processes 50 SKUs in one page, writes 50 sync_run_items, increments updated_count by N (where N = diffs found).
    - Test C3: In dry_run mode — does NOT call WooClient->put() (assert Mockery::never()); writes SyncDiff rows via shadow path; still creates sync_run_items.
    - Test C4: Per-SKU idempotency (Pitfall P2-F): if product.last_synced_at > run.started_at, the SKU is skipped (the chunk was retried after a partial success).
    - Test C5: After successful write, dispatches SupplierPriceChanged if price diffed, SupplierStockChanged if stock diffed. Both events or either or neither (no-op case).
    - Test C6: Unknown SKU path — a SKU in the chunk that appears in supplier_feed but has no matching Woo match → creates an ImportIssue row (issue_type=unknown_sku) AND dispatches NewSupplierSkuDetected.

    Tests in tests/Feature/SyncChunkFailureTest.php (SYNC-05):
    - Test F1: A WooClient::put() throws HttpClientException → sync_errors row created with error_class, error_message, correlation_id; chunk CONTINUES processing remaining SKUs.
    - Test F2: AbortGuard.recordFailure called on each SKU failure; after 50 consecutive the next throwIfTriggered throws SyncAbortException, which causes the orchestrator (NOT chunk job) to flip run.status='aborted'. (Test this via a small chunk of 51 SKUs all failing — assert SyncAbortException surfaces from SyncChunkJob's handle.)
    - Test F3: Run.failed_count incremented correctly after a chunk of mixed success/failure.

    Tests in tests/Feature/MissingSkuHandlingTest.php (SYNC-06 + D-03):
    - Test Ms1: simple product in Woo, SKU absent in supplier feed, no custom-ms tag → WooClient->put("products/{$id}", ['status' => 'pending']) called; SupplierSkuMissing dispatched with newStatus='pending'.
    - Test Ms2: simple product with custom-ms tag → Woo is NOT touched (skipped); SupplierSkuMissing dispatched with newStatus='publish' (unchanged) + hadCustomMsTag=true.
    - Test Ms3: variation absent in supplier → WooClient->put("products/{$parent}/variations/{$vid}", ['status' => 'private']); parent stays publish; SupplierSkuMissing hasCustomMsTag=false regardless of parent's tag (D-03 explicit carve-out for variations).
    - Test Ms4: ImportIssue row created for every missing SKU (issue_type='missing_at_supplier').

    Tests in tests/Feature/ExcludeFromAutoUpdateTest.php (SYNC-07):
    - Test X1: Woo product with _exclude_from_auto_update=yes meta → diff returns 'skipped' action; NO WooClient write; run.skipped_count incremented; sync_run_item row action='skipped' reason='exclude_from_auto_update'.
    - Test X2: Confirmation: NO SupplierPriceChanged / SupplierStockChanged dispatched for skipped SKUs.

    Tests in tests/Feature/DryRunModeTest.php (SYNC-09 + D-04):
    - Test Y1: `sync:supplier` with no flags → run.dry_run=true; zero real WooClient puts; all writes landed in sync_diffs (shadow mode).
    - Test Y2: `sync:supplier --dry-run` (explicit) → same behaviour as Y1.
    - Test Y3: `sync:supplier --live` with WOO_WRITE_ENABLED=true → run.dry_run=false; real Woo calls (or mocked Automattic client calls — not shadow mode).
    - Test Y4: `sync:supplier --live` with WOO_WRITE_ENABLED=false → still shadow mode at WooClient level (env flag is an extra safety belt); assertion: sync_diffs rows created even though command said --live.

    Tests in tests/Feature/SyncResumeTest.php (SYNC-03 + D-07):
    - Test Z1: `sync:supplier --resume={run_id}` finds the SyncRun in status=aborted, flips back to running, starts iteration at cursor_page (not page 1).
    - Test Z2: Within a chunk, SKUs whose Product.last_synced_at > run.started_at are skipped (Pitfall P2-F idempotent).
    - Test Z3: Double-run idempotency: run twice to the same SyncRun (simulate worker crash mid-chunk, retry) — no duplicate SupplierPriceChanged events for the same SKU; each SKU writes exactly one sync_run_item.
    - Test Z4: Non-resumable run (status=completed) → `--resume={id}` exits with non-zero code.

    Tests in tests/Feature/SyncSupplierCommandFlagsTest.php (D-04):
    - Test FL1: `--live --dry-run` together → command exits non-zero, error message mentions "mutually exclusive".
    - Test FL2: `--resume=999999` (non-existent id) → exits non-zero.
    - Test FL3: No flags → exits 0, dry_run=true, sync_diffs rows created.
  </behavior>
  <action>
**1. `app/Domain/Sync/Jobs/SyncChunkJob.php`** — per-page work unit. Follows RESEARCH §Pattern 2:
```php
namespace App\Domain\Sync\Jobs;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class SyncChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;  // Pitfall P2-E: bump from 90s to 120s to fit 50 SKUs with backoff
    public array $backoff = [10, 30, 90];

    public function __construct(
        public readonly int $runId,
        public readonly int $page,
        /** @var array<int, array> */
        public readonly array $skus,
        /** @var array<string, array{price: string, stock: int}> */
        public readonly array $supplierFeed,
    ) {
        $this->onQueue('sync-woo-push');
    }

    public function handle(WooClient $woo, SyncDiffEngine $diffEngine, AbortGuard $abortGuard): void
    {
        $run = SyncRun::findOrFail($this->runId);
        Context::add('correlation_id', $run->correlation_id);

        foreach ($this->skus as $skuRow) {
            $abortGuard->throwIfTriggered($run->id);

            // Pitfall P2-F: skip SKUs already synced in THIS run (worker retry idempotency)
            if ($this->alreadySyncedInThisRun($skuRow, $run)) {
                continue;
            }

            $sku = (string) ($skuRow['sku'] ?? '');
            $supplierRow = $this->supplierFeed[$sku] ?? null;

            try {
                $diff = $diffEngine->diff($skuRow, $supplierRow);

                if ($diff === null) {
                    // no-op
                    $abortGuard->recordSuccess($run->id);
                    continue;
                }

                if ($diff['action'] === 'skipped') {
                    $this->writeRunItem($run, $skuRow, 'skipped', $diff);
                    $run->incrementCounter('skipped');
                    $abortGuard->recordSuccess($run->id);
                    continue;
                }

                // action === 'updated' → write to Woo
                DB::transaction(function () use ($run, $woo, $skuRow, $diff, $supplierRow) {
                    $woo->put($diff['endpoint'], $diff['payload']);
                    $this->writeRunItem($run, $skuRow, 'updated', $diff);
                    $this->upsertLocalMirror($skuRow, $supplierRow, $run);
                });

                $this->dispatchDomainEvents($skuRow, $diff);
                $run->incrementCounter('updated');
                $abortGuard->recordSuccess($run->id);
            } catch (SyncAbortException $e) {
                throw $e;  // bubble to the orchestrator
            } catch (\Throwable $e) {
                SyncError::create([
                    'sync_run_id' => $run->id,
                    'sku' => $sku,
                    'woo_product_id' => $skuRow['woo_product_id'] ?? null,
                    'woo_variation_id' => $skuRow['woo_variation_id'] ?? null,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);
                $this->writeRunItem($run, $skuRow, 'failed', null, $e->getMessage());
                $run->incrementCounter('failed');
                $abortGuard->recordFailure($run->id);
            }

            $run->update(['cursor_page' => $this->page, 'cursor_sku' => $sku]);
        }

        // Unknown-SKU detection for this page (SKUs in supplier feed but no Woo match)
        $this->detectUnknownSkus($run);
    }

    private function alreadySyncedInThisRun(array $skuRow, SyncRun $run): bool
    {
        $wooId = $skuRow['woo_product_id'] ?? null;
        if ($wooId === null) return false;

        if (($skuRow['type'] ?? 'simple') === 'variation') {
            $vid = $skuRow['woo_variation_id'] ?? null;
            $v = ProductVariant::where('woo_variation_id', $vid)->first();
            return $v?->last_synced_at?->greaterThan($run->started_at) ?? false;
        }
        $p = Product::where('woo_product_id', $wooId)->first();
        return $p?->last_synced_at?->greaterThan($run->started_at) ?? false;
    }

    private function upsertLocalMirror(array $skuRow, array $supplierRow, SyncRun $run): void
    {
        // Mirror supplier values into local Product / ProductVariant so downstream phases have them
        $now = now();
        if (($skuRow['type'] ?? 'simple') === 'variation') {
            ProductVariant::where('woo_variation_id', $skuRow['woo_variation_id'])->update([
                'buy_price' => $supplierRow['price'],
                'stock_quantity' => $supplierRow['stock'],
                'last_synced_at' => $now,
            ]);
        } else {
            Product::where('woo_product_id', $skuRow['woo_product_id'])->update([
                'buy_price' => $supplierRow['price'],
                'last_synced_at' => $now,
                'last_sync_run_id' => $run->id,
            ]);
        }
    }

    private function writeRunItem(SyncRun $run, array $skuRow, string $action, ?array $diff, ?string $errorMessage = null): void
    {
        SyncRunItem::create([
            'sync_run_id' => $run->id,
            'sku' => (string) ($skuRow['sku'] ?? ''),
            'woo_product_id' => $skuRow['woo_product_id'] ?? null,
            'woo_variation_id' => $skuRow['woo_variation_id'] ?? null,
            'action' => $action,
            'reason' => $diff['reason'] ?? null,
            'old_price' => $diff['old_price'] ?? null,
            'new_price' => $diff['new_price'] ?? null,
            'old_stock' => $diff['old_stock'] ?? null,
            'new_stock' => $diff['new_stock'] ?? null,
            'error_message' => $errorMessage,
            'correlation_id' => $run->correlation_id,
            'created_at' => now(),
        ]);
    }

    private function dispatchDomainEvents(array $skuRow, array $diff): void
    {
        $sku = (string) $skuRow['sku'];
        $pid = (int) $skuRow['woo_product_id'];
        $vid = $skuRow['woo_variation_id'] ?? null;

        if (isset($diff['payload']['regular_price'])) {
            event(new SupplierPriceChanged($sku, $pid, $vid, $diff['old_price'] ?? '', $diff['new_price'] ?? ''));
        }
        if (isset($diff['payload']['stock_quantity'])) {
            event(new SupplierStockChanged($sku, $pid, $vid, (int) ($diff['old_stock'] ?? 0), (int) ($diff['new_stock'] ?? 0)));
        }
    }

    private function detectUnknownSkus(SyncRun $run): void
    {
        $wooSkusInChunk = array_filter(array_column($this->skus, 'sku'));
        $supplierSkusInFeed = array_keys($this->supplierFeed);
        // Unknown = in supplier, not mapped to any SKU in this chunk's Woo pass
        // Global unknown detection is cheaper as a set-difference at the orchestrator level — see SyncSupplierCommand step.
        // This method is a no-op in v1; orchestrator handles the global pass.
    }
}
```

**2. `app/Domain/Sync/Jobs/MarkMissingSkusJob.php`** — runs after all chunks complete:
```php
namespace App\Domain\Sync\Jobs;

use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * SYNC-06 + D-03: flip Woo status for SKUs absent from the supplier feed.
 * Runs after all SyncChunkJobs complete; receives the (inWoo) and (inSupplier) sets
 * from the orchestrator. Missing = inWoo − inSupplier.
 */
final class MarkMissingSkusJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;  // may touch many SKUs sequentially

    public function __construct(
        public readonly int $runId,
        /** @var array<int, array{sku:string, type:string, woo_product_id:int, woo_variation_id:?int, is_custom_ms:bool}> */
        public readonly array $missingRows,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function handle(WooClient $woo): void
    {
        $run = SyncRun::findOrFail($this->runId);
        Context::add('correlation_id', $run->correlation_id);

        foreach ($this->missingRows as $row) {
            $newStatus = 'publish';  // default for custom-ms simples (stays published)
            $shouldWrite = false;
            $endpoint = '';

            if ($row['type'] === 'simple' && ! $row['is_custom_ms']) {
                $newStatus = 'pending';
                $shouldWrite = true;
                $endpoint = "products/{$row['woo_product_id']}";
            } elseif ($row['type'] === 'simple' && $row['is_custom_ms']) {
                // D-03: stays publish, no Woo write
                $newStatus = 'publish';
                $shouldWrite = false;
            } elseif ($row['type'] === 'variation') {
                // D-03 explicit: variations flip to private regardless of parent's custom-ms
                $newStatus = 'private';
                $shouldWrite = true;
                $endpoint = "products/{$row['woo_product_id']}/variations/{$row['woo_variation_id']}";
            }

            try {
                if ($shouldWrite && ! $run->dry_run) {
                    $woo->put($endpoint, ['status' => $newStatus]);
                } elseif ($shouldWrite && $run->dry_run) {
                    // In dry-run, WooClient's shadow gate catches this — writes sync_diff instead
                    $woo->put($endpoint, ['status' => $newStatus]);
                }

                ImportIssue::create([
                    'sku' => $row['sku'],
                    'woo_product_id' => $row['woo_product_id'],
                    'woo_variation_id' => $row['woo_variation_id'],
                    'issue_type' => ImportIssue::TYPE_MISSING_AT_SUPPLIER,
                    'detected_at' => now(),
                    'last_seen_at' => now(),
                    'notes' => "Missing from supplier feed; newStatus={$newStatus}",
                    'correlation_id' => $run->correlation_id,
                ]);

                SyncRunItem::create([
                    'sync_run_id' => $run->id,
                    'sku' => $row['sku'],
                    'woo_product_id' => $row['woo_product_id'],
                    'woo_variation_id' => $row['woo_variation_id'],
                    'action' => 'missing',
                    'reason' => $row['is_custom_ms'] ? 'missing_at_supplier_custom_ms_preserved' : 'missing_at_supplier',
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);

                $run->incrementCounter('missing');

                event(new SupplierSkuMissing(
                    sku: $row['sku'],
                    wooProductId: $row['woo_product_id'],
                    wooVariationId: $row['woo_variation_id'],
                    hadCustomMsTag: $row['is_custom_ms'],
                    newStatus: $newStatus,
                ));
            } catch (\Throwable $e) {
                // Log to sync_errors and continue — a single missing SKU failure should not abort the pass
                SyncError::create([
                    'sync_run_id' => $run->id,
                    'sku' => $row['sku'],
                    'woo_product_id' => $row['woo_product_id'],
                    'woo_variation_id' => $row['woo_variation_id'],
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
```

**3. `app/Domain/Sync/Commands/SyncSupplierCommand.php`** — orchestrator, follows RESEARCH §Pattern 1:
```php
namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Jobs\MarkMissingSkusJob;
use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\SkuMatcher;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooProductIterator;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Orchestrator for the daily supplier sync (SYNC-01..SYNC-13).
 *
 * Default DRY-RUN (D-04). `--live` enables writes (still gated by WOO_WRITE_ENABLED).
 * `--resume={run_id}` continues an aborted/crashed run from its cursor (SYNC-03 + D-07).
 * Flag combinations validated per D-04 (`--live --dry-run` → error).
 */
final class SyncSupplierCommand extends BaseCommand
{
    protected $signature = 'sync:supplier
        {--live : Enable real Woo writes (default is dry-run per D-04)}
        {--dry-run : Explicit dry-run mode (default; error if combined with --live)}
        {--resume= : Resume an aborted/crashed run by its id}';

    protected $description = 'Pull supplier catalogue and sync to Woo (dry-run by default, --live for real writes)';

    protected function perform(): int
    {
        if ($this->option('live') && $this->option('dry-run')) {
            $this->error('Error: --live and --dry-run are mutually exclusive (D-04).');
            return SymfonyCommand::FAILURE;
        }

        $isLive = (bool) $this->option('live');
        $resumeId = $this->option('resume');

        try {
            $run = $resumeId !== null
                ? $this->resumeRun((int) $resumeId, $isLive)
                : $this->newRun($isLive);

            $this->info("Sync run id={$run->id} (dry_run=" . ($run->dry_run ? 'true' : 'false') . ", resume=" . ($resumeId !== null ? 'yes' : 'no') . ") — correlation_id={$run->correlation_id}");

            /** @var SupplierClient $supplier */
            $supplier = app(SupplierClient::class);
            $supplierFeed = $supplier->fetchAllProducts();
            $this->info('Supplier feed: ' . count($supplierFeed) . ' SKUs fetched.');

            /** @var SkuMatcher $matcher */
            $matcher = app(SkuMatcher::class)->build($supplierFeed);

            /** @var WooProductIterator $iterator */
            $iterator = app(WooProductIterator::class);

            $wooSkusSeen = [];
            $missingRows = [];

            foreach ($iterator->pages(fromPage: $run->cursor_page > 0 ? $run->cursor_page : 1) as $pageData) {
                foreach ($pageData['skus'] as $row) {
                    $wooSkusSeen[(string) $row['sku']] = $row;
                }

                // Dispatch per-page chunk — synchronous when in test mode via QUEUE_CONNECTION=sync
                SyncChunkJob::dispatch(
                    runId: $run->id,
                    page: $pageData['page'],
                    skus: $pageData['skus'],
                    supplierFeed: $supplierFeed,
                );

                $run->update(['total_skus' => $run->total_skus + count($pageData['skus'])]);
                $this->info("  Page {$pageData['page']}: " . count($pageData['skus']) . ' SKUs dispatched.');
            }

            // Unknown SKUs: in supplier feed but not seen in Woo iteration (D-09 + Phase 6 producer)
            foreach ($supplierFeed as $sku => $row) {
                if (! isset($wooSkusSeen[$sku])) {
                    ImportIssue::create([
                        'sku' => $sku,
                        'issue_type' => ImportIssue::TYPE_UNKNOWN_SKU,
                        'detected_at' => now(),
                        'last_seen_at' => now(),
                        'notes' => 'SKU present in supplier feed but no matching Woo product',
                        'correlation_id' => $run->correlation_id,
                    ]);
                    event(new NewSupplierSkuDetected($sku, (string) $row['price'], (int) $row['stock']));
                    $run->incrementCounter('unknown_sku');
                }
            }

            // Missing SKUs: in Woo but not in supplier feed (SYNC-06 + D-03)
            foreach ($wooSkusSeen as $sku => $row) {
                if (! isset($supplierFeed[$sku])) {
                    $missingRows[] = [
                        'sku' => $sku,
                        'type' => (string) $row['type'],
                        'woo_product_id' => (int) $row['woo_product_id'],
                        'woo_variation_id' => $row['woo_variation_id'] ?? null,
                        'is_custom_ms' => (bool) $row['is_custom_ms'],
                    ];
                }
            }

            if ($missingRows !== []) {
                MarkMissingSkusJob::dispatch($run->id, $missingRows);
                $this->info('Missing-SKU pass dispatched: ' . count($missingRows) . ' SKUs.');
            }

            $run->refresh()->finalise();
            $this->info("Run {$run->id} completed. updated={$run->updated_count}, skipped={$run->skipped_count}, failed={$run->failed_count}, missing={$run->missing_count}, unknown_sku={$run->unknown_sku_count}.");

            return SymfonyCommand::SUCCESS;
        } catch (SyncAbortException $e) {
            $run->abort($e->reason, $e->getMessage());
            $this->error("Sync ABORTED: reason={$e->reason}, message={$e->getMessage()}");
            return SymfonyCommand::FAILURE;
        }
    }

    private function newRun(bool $isLive): SyncRun
    {
        $run = SyncRun::create([
            'started_at' => now(),
            'status' => SyncRun::STATUS_RUNNING,
            'dry_run' => ! $isLive,
            'correlation_id' => Context::get('correlation_id') ?? (string) \Illuminate\Support\Str::uuid(),
            'cursor_page' => 0,
        ]);
        app(\App\Foundation\Audit\Services\Auditor::class)->record('sync.run.started', ['run_id' => $run->id, 'dry_run' => $run->dry_run]);
        return $run;
    }

    private function resumeRun(int $id, bool $isLive): SyncRun
    {
        $run = SyncRun::findResumable($id);
        if ($isLive && $run->dry_run) {
            // Flipping from dry to live mid-resume is allowed — operator has explicitly opted in
            $run->update(['dry_run' => false]);
        }
        app(\App\Foundation\Audit\Services\Auditor::class)->record('sync.run.resumed', ['run_id' => $run->id, 'from_page' => $run->cursor_page]);
        return $run;
    }
}
```

**4. Register SyncSupplierCommand in `app/Console/Kernel.php`** (or `bootstrap/app.php` withCommands if Laravel 12 pattern) — actually Laravel 12 auto-discovers commands in `app/Console/Commands/` BUT our SyncSupplierCommand lives in `app/Domain/Sync/Commands/` so add it to the `withCommands` array in `bootstrap/app.php`:

```php
// bootstrap/app.php — in the ->withCommands(...) chain, add:
->withCommands([
    // ... existing ...
    \App\Domain\Sync\Commands\SyncSupplierCommand::class,
])
```

**5. Update `routes/console.php`** — add the commented-out cron entry (D-05 kill-switch). DO NOT uncomment; Phase 7 runbook enables it:
```php
// Phase 2 (D-05) — Daily supplier sync. Commented out; enable post-cutover.
// Schedule::command('sync:supplier --live')
//     ->dailyAt('02:00')
//     ->onOneServer()
//     ->withoutOverlapping(60)
//     ->onQueue('sync-bulk')
//     ->timezone('Europe/London')
//     ->description('Daily 21stcav.com supplier sync (D-05 — enable post-Phase-7-cutover)');
```

**6. Write 7 feature test files** per <behavior>. Use `Queue::fake()` + `Event::fake()` + `Http::fake()` where needed. For SyncChunkJob tests, call the job synchronously via `dispatch_sync` or invoke `->handle(...)` directly with resolved services.

**Self-check:**
```bash
vendor/bin/pest --filter=SyncChunkJob --filter=SyncChunkFailure --filter=MissingSkuHandling --filter=ExcludeFromAutoUpdate --filter=DryRunMode --filter=SyncResume --filter=SyncSupplierCommandFlags
vendor/bin/pest  # FULL suite — Phase 1 regression must stay clean
```
  </action>
  <verify>
    <automated>vendor/bin/pest &amp;&amp; vendor/bin/deptrac analyse --no-progress</automated>
  </verify>
  <done>
    - 2 Job classes + 1 Command class exist and are registered (`php artisan list | grep sync:supplier` returns the row)
    - `php artisan sync:supplier --help` shows `--live`, `--dry-run`, `--resume=` options
    - routes/console.php contains a COMMENTED sync:supplier --live entry (grep for "// Schedule::command('sync:supplier" matches)
    - 7 new feature test files, totalling ≥ 28 tests, all green
    - Full Pest suite ≥ 191 passing (previous 163 + this task's 28)
    - ZERO Phase 1 test regressions
    - Deptrac 0 violations
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CLI → SyncSupplierCommand | Operator invokes `sync:supplier --live --resume={id}`; integer coercion + resumable status check guards cursor tampering |
| SyncChunkJob payload (serialised to Redis) | ~2MB supplier feed serialised per dispatch; attacker with Redis access can read/modify |
| DomainEvent → queued listener | ShouldDispatchAfterCommit now ensures rolled-back transactions don't fire listeners |
| Unknown SKU → NewSupplierSkuDetected event | Malicious supplier feed could inject SKUs to pollute import_issues + trigger Phase 6's eventual CreateWooProductJob |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-03-01 | Tampering | `--resume={run_id}` cursor re-entry | mitigate | SyncRun::findResumable scopes to status IN (aborted, failed, running) — completed runs cannot be re-entered. SyncRun.correlation_id is immutable after create; tampering with cursor_page via DB manipulation is captured by spatie/activitylog on SyncRun changes. |
| T-02-03-02 | Denial of Service | Malicious supplier feed with 1M SKUs → Redis memory exhaustion | mitigate | A4 documents ≤20k SKUs current-state; Phase 2 in-memory matcher. Post-v1 scale flagged (Pitfall P2-D). AbortGuard's error-rate trigger naturally aborts runs with runaway feeds. |
| T-02-03-03 | Elevation of Privilege | Events fired inside rolled-back transaction leaking bad data downstream | mitigate | DomainEvent now implements ShouldDispatchAfterCommit (this plan's retrofit). Test A3 proves. |
| T-02-03-04 | Spoofing | Malicious feed injecting SKUs pretending to be "unknown" to pollute ImportIssue | accept | ImportIssue table is append-only + admin-review-only; Phase 6 wires human approval before any product-creation action. Accepted for v1; document as "ops manual triage required". |
| T-02-03-05 | Tampering | `_exclude_from_auto_update` meta tampering (operator races with sync) | mitigate | Woo meta reads happen once per iterator pass — snapshot semantics prevent mid-sync flip. Every skip writes a sync_run_item with reason='exclude_from_auto_update' — auditable. Plan 04 SyncRunResource surfaces in drill-down. |
| T-02-03-06 | Repudiation | Abort reason / resume events | mitigate | Auditor::record writes log_name='system' rows for sync.run.started/resumed/aborted/completed. spatie activity_log includes batch_uuid = correlation_id. |
| T-02-03-07 | Information Disclosure | Supplier feed serialised in SyncChunkJob payload visible in Horizon UI | accept | Horizon UI is admin-gated (Phase 1 Pitfall K). Feed contains SKU + price + stock — business-confidential but not PII. Admin-only access is sufficient. |
</threat_model>

<verification>
1. **Phase 1 regression (critical — DomainEvent retrofit):**
   ```bash
   vendor/bin/pest
   ```
   ≥ 191 passing, 2 skipped (Phase 1's pre-existing), 0 failures. If any Phase 1 test turns red after the retrofit → fix the test (not the retrofit) since ShouldDispatchAfterCommit is correct behaviour.

2. **End-to-end dry-run smoke:**
   ```bash
   php artisan sync:supplier
   # → "Sync run id=N (dry_run=true, resume=no) — correlation_id=..."
   # → "Supplier feed: X SKUs fetched." (may fail if creds not in .env — acceptable in dev)
   ```
   If supplier creds unavailable: fallback is to mock the SupplierClient binding via `$this->app->instance(SupplierClient::class, $mock)` in a feature test.

3. **Flag-conflict exit code:**
   ```bash
   php artisan sync:supplier --live --dry-run
   # → exits non-zero; prints "Error: --live and --dry-run are mutually exclusive (D-04)."
   ```

4. **Horizon queue bindings visible:**
   ```bash
   php artisan tinker --execute='dd(config("horizon.environments.production"))'
   # → sync-bulk, sync-woo-push supervisors still present from Phase 1 Plan 05
   ```

5. **Deptrac clean:** `vendor/bin/deptrac analyse --no-progress` exits 0.

6. **routes/console.php syntax:** `php artisan schedule:list` does NOT show sync:supplier in the active schedule (it's commented out per D-05).
</verification>

<success_criteria>
- DomainEvent base class retrofitted to implement ShouldDispatchAfterCommit (Pitfall P2-I)
- 4 domain events + 1 stub listener wired in EventServiceProvider
- 4 services (WooProductIterator, SkuMatcher, AbortGuard, SyncDiffEngine) + 2 exceptions built and covered with 26 tests
- 2 jobs (SyncChunkJob on sync-woo-push, MarkMissingSkusJob on sync-bulk) + 1 command (SyncSupplierCommand on sync-bulk queue implicitly)
- `sync:supplier` default = dry-run (D-04); `--live` required for real writes; flag-conflict exits non-zero
- `--resume={run_id}` resumes from cursor_page + cursor_sku; worker-crash idempotency via last_synced_at check
- D-06 aborts (a/b/c) fire correctly; abort writes abort_reason + abort_message; ThrottledFailedJobNotifier catches the SyncAbortException propagation
- D-05 daily cron entry COMMENTED in routes/console.php (kill-switch, Phase 7 enables)
- D-09 unknown SKU → NewSupplierSkuDetected event + import_issues row + no-op listener log
- ALL Phase 1 tests still green (ShouldDispatchAfterCommit retrofit regression-safe)
- Full Pest suite ≥ 191 passing
- Deptrac 0 violations
</success_criteria>

<output>
Create `.planning/phases/02-supplier-sync/02-03-SUMMARY.md` after completion with:
- DomainEvent retrofit: diff + regression outcome
- 4 events shipped + listener registration
- Service composition decisions (AbortGuard singleton, matcher rebuild-per-run)
- Chunk timeout bump 90s → 120s (Pitfall P2-E) — note whether config/horizon.php was modified
- Any Phase 1 test regressions encountered + their fixes
- sync:supplier CLI surface (signature, flag handling, resume semantics)
- routes/console.php commented cron entry verbatim
</output>
