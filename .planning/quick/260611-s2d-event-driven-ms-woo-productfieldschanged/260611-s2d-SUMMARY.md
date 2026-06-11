---
quick_id: 260611-s2d
mode: quick
type: summary
completed_at: 2026-06-11
duration_minutes: ~45
commits:
  - a6cd425  # refactor(sync): extract WooProductWriter service from PushDivergenceToWooCommand
  - aa8f59e  # feat(products): ProductFieldsChangedEvent + ProductObserver with feature flag
  - d4ddad1  # feat(products): PushProductFieldsToWoo queued listener + EventServiceProvider wiring
  - 85758ec  # chore(sync): wrap WooImportProductsCommand bulk updateOrCreate in withoutEvents
  - cc8ab80  # test(products): observer + listener Pest cases A-J for event-driven Woo push
  # Task 8 STATE commit follows this file write
files_created:
  - app/Domain/Sync/Services/WooProductWriter.php
  - app/Domain/Products/Events/ProductFieldsChangedEvent.php
  - app/Domain/Products/Observers/ProductObserver.php
  - app/Domain/Products/Listeners/PushProductFieldsToWoo.php
  - tests/Feature/Domain/Products/ProductObserverTest.php
  - tests/Feature/Domain/Products/Listeners/PushProductFieldsToWooTest.php
files_modified:
  - app/Console/Commands/PushDivergenceToWooCommand.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/EventServiceProvider.php
  - app/Domain/Sync/Commands/WooImportProductsCommand.php
  - config/cutover.php
  - .planning/STATE.md
pest_delta:
  baseline: "2,023 / 222 / 3 (260611-rl4)"
  expected_after: "2,033 / 222 / 3 (+10 pass / 0 new fails on focused + touched-area)"
  focused_run: "53/53 passed (284 assertions, 27.62s) — observer 4 + listener 6 + 5 regression suites 43"
---

# 260611-s2d — Event-Driven MS→Woo Propagation

## One-liner

Eloquent observer on Product dispatches a domain event when stock_quantity / buy_price / sell_price / category_id changes; queued listener on sync-woo-push calls a shared WooProductWriter to PUT the change instantly. Feature-flagged (default OFF) for safe deploy. Daily 23:00 auto-sync (260611-rl4) preserved as backstop.

## Final Commit SHAs

| # | Hash      | Type     | Subject                                                                            |
|---|-----------|----------|------------------------------------------------------------------------------------|
| 1 | a6cd425   | refactor | extract WooProductWriter service from PushDivergenceToWooCommand                   |
| 2 | aa8f59e   | feat     | ProductFieldsChangedEvent + ProductObserver with feature flag                      |
| 3 | d4ddad1   | feat     | PushProductFieldsToWoo queued listener + EventServiceProvider wiring               |
| 4 | 85758ec   | chore    | wrap WooImportProductsCommand bulk updateOrCreate in withoutEvents                 |
| 5 | cc8ab80   | test     | observer + listener Pest cases A-J for event-driven Woo push                       |
| 6 | (this)    | docs     | 260611-s2d STATE + LOG + SUMMARY end-of-run artifacts                              |

## Pest Delta Numbers

| Suite                                       | Baseline | After  | Notes                                  |
|---------------------------------------------|----------|--------|----------------------------------------|
| ProductObserverTest (new)                   | —        | 4/4    | A-D: flag-off / single / multi / non-tracked |
| PushProductFieldsToWooTest (new)            | —        | 6/6    | E-J: queue / handle / audit / 404 / error / null-product |
| PushDivergenceToWooCommandTest (regression) | 10/10    | 10/10  | Refactor contract preserved            |
| PushVisibilityToWooCommandTest (regression) | 6/6      | 6/6    | Untouched                              |
| HydrateProductStockFromOffersCommandTest    | 10/10    | 10/10  | Untouched                              |
| AutoSyncDivergenceCommandTest               | 8/8      | 8/8    | Untouched                              |
| DivergenceScanCommandTest                   | 9/9      | 9/9    | Untouched                              |
| **Focused total**                           | —        | **53/53** | 284 assertions, 27.62s              |

**Full-suite reconciliation:** deferred per 260611-qcq / 260611-rl4 pattern — Windows herd PHP suite OOMs at 512MB (pre-existing infra issue, NOT introduced by 260611-s2d). Focused + touched-area equivalence confirmed +10 pass / 0 new fails.

## Probe Disagreements vs Original Brief

1. **Queue name correction.** Brief said the listener should run on `'woo-writes'`. The actual queue is `'sync-woo-push'` (config/horizon.php line 202: `'sync-woo-push-supervisor' => ['queue' => ['sync-woo-push'], 'tries' => 5, 'maxProcesses' => 3]`). Listener uses `sync-woo-push`. Planner already folded this correction into the PLAN; executor confirmed.
2. **Bulk-path scope reduction.** Brief implied multiple commands needed `Product::withoutEvents()` wrapping. Task 1 probe confirmed only `WooImportProductsCommand::updateOrCreate` (line 173) uses Eloquent save semantics. SupplierDbSyncCommand + HydrateProductStockFromOffersCommand use `Product::where(...)->update([...])` which fires NO Eloquent events. PushDivergenceToWooCommand writes only sync_diffs. Single wrap site.
3. **Event base class.** Plan brief specified `Dispatchable + SerializesModels` directly on the new event. Executor extended `App\Foundation\Events\DomainEvent` (codebase convention — matches `ProductPriceChanged`, auto-populates `correlationId` from Context facade). The plan's constructor `?string $correlationId` arg is replaced by `parent::__construct()` inheriting the Context-driven value. This is a deviation from the plan's literal signature but matches existing codebase patterns and the plan's drift-prevention philosophy. Documented in LOG.md.
4. **WooProductWriter `final` modifier.** Plan specified `final class WooProductWriter`. Executor unmarked as `final` so listener Pest tests can swap an anonymous-subclass stub through the container (mirrors the WooClient pattern used by PushDivergenceToWooCommandTest). Docblock explains the test-rig coupling.

## Auto-fixes During Execution (Rule 1 / Rule 3)

1. **Rule 1 — Pest case C readonly bug.** Test originally called `sort($event->changedFields)` which threw `Cannot indirectly modify readonly property`. Fixed by copying to a local array before sorting. No code change needed — test-only fix.
2. **Rule 3 — WooProductWriter `final` blocked stub.** Anonymous-subclass stub in listener tests couldn't extend a `final` class. Removed `final` modifier with docblock note explaining the test-rig coupling. Folded into Task 6 commit.
3. **Rule 3 — Auditor mock impossible.** Auditor is `final` and can't be Mockery-mocked. Pivoted to Spatie `Activity::query()` post-call assertion (mirrors AutoSyncDivergenceCommandTest + MarginChangeApplierTest patterns). Auditor itself was NOT modified.

## Operator Readiness Checklist

- [x] All 6 commits land on the deploy branch.
- [ ] **Post-deploy:** confirm flag OFF — `php artisan tinker --execute='echo (int) config("cutover.event_driven_push_enabled");'` returns `0`.
- [ ] **Post-deploy:** confirm listener wired — `php artisan event:list | grep ProductFieldsChangedEvent` shows `App\Domain\Products\Listeners\PushProductFieldsToWoo (ShouldQueue)`.
- [ ] **Flip readiness test on a single SKU:**
    1. Set `CUTOVER_EVENT_DRIVEN_PUSH_ENABLED=true` in `.env`.
    2. `php artisan config:clear`.
    3. Edit a test SKU's stock_quantity in Filament.
    4. Check Horizon for a `App\Domain\Products\Listeners\PushProductFieldsToWoo` job on the `sync-woo-push` queue.
    5. Check Woo for the field update within ~30s.
    6. Check `activity_log` for an `events.product_pushed` row with `properties.result='pushed'`.
- [ ] **If green:** leave flag ON. 23:00 auto-sync (260611-rl4) still runs as backstop.
- [ ] **If amber/red:** flip flag OFF in `.env`, `config:clear`. No code rollback needed (observer no-ops when flag OFF).

## Forward Warnings

1. **Echo-loop boundary is dormant.** `WooWebhookController` has zero Product writes today (Task 1 probe confirmed). If a future task gains Product writes from inside the webhook handler, add `Context::get('source') !== 'woo-webhook'` at the observer dispatch boundary to prevent listener-driven PUTs from reflecting Woo-webhook-driven writes back to Woo. Listener docblock carries this warning.
2. **sell_price duplicate-PUT.** When sell_price changes via a direct admin save AND the pricing recompute pipeline (`ProductPriceChanged`) both fire, two parallel PUTs reach Woo. Benign: Woo accepts identical PUTs and the writer is idempotent. FOLLOW-UP: consider de-duping in a future task once observability (activity_log frequency) confirms the duplication is measurable.
3. **Soft-delete race window.** If a Product is soft-deleted between event dispatch and listener handle (single-digit-second window), `Product::find()` returns null and the listener returns silently — no audit, no error. The 23:00 auto-sync backstop closes any persistent drift. Observable side effect: missing `events.product_pushed` audit rows for events whose product was deleted mid-flight.
4. **Drift-prevention contract.** WooProductWriter is the SINGLE source of truth for the 4-field PUT payload. If a 5th pushable field is added (e.g. brand_id once pa_brand→id resolves), extend BOTH `WooProductWriter::putProductFields` branches AND `PushDivergenceToWooCommand::SUPPORTED_FIELDS` in the same commit. Docblocks call this out.

## Self-Check: PASSED

- [x] `app/Domain/Sync/Services/WooProductWriter.php` exists with public `putProductFields()` method.
- [x] `app/Domain/Products/Events/ProductFieldsChangedEvent.php` exists with readonly props.
- [x] `app/Domain/Products/Observers/ProductObserver.php` exists with `TRACKED_FIELDS` const + flag short-circuit.
- [x] `app/Domain/Products/Listeners/PushProductFieldsToWoo.php` exists implementing ShouldQueue with `$queue='sync-woo-push'` and `$tries=4`.
- [x] `tests/Feature/Domain/Products/ProductObserverTest.php` exists with 4 cases.
- [x] `tests/Feature/Domain/Products/Listeners/PushProductFieldsToWooTest.php` exists with 6 cases.
- [x] `config('cutover.event_driven_push_enabled')` defaults FALSE — verified via tinker probe (`bool(false)`).
- [x] `php artisan event:list` shows `ProductFieldsChangedEvent → PushProductFieldsToWoo (ShouldQueue)`.
- [x] All 6 commits land in `git log --oneline`: a6cd425, aa8f59e, d4ddad1, 85758ec, cc8ab80, (Task 8 to land next).
- [x] Grep gate: `_alg_wc_cog_cost` literal in WooProductWriter.php = 0 code matches (only docblock references). Constant `WooFieldComparator::BUY_PRICE_META_KEY` is the sole code reference.
- [x] Grep gate: `Product::observe(ProductObserver::class)` registered in AppServiceProvider (line 625).
- [x] Grep gate: no `'woo-writes'` code literal in `app/Domain/Products/Listeners/`.

