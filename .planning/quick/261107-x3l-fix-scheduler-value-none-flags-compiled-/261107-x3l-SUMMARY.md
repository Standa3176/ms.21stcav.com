---
phase: quick-261107-x3l
plan: 01
status: complete
completed: 2026-11-07
commits: [47be4df, 9a6f817]
---

# Quick 261107-x3l: Fix scheduler VALUE_NONE flags

`products:draft-from-suggestions` and `products:restore-sourceable-pending` now pass their value-less flags as bare list items. A new architecture test binds every scheduled artisan event against its command's InputDefinition.

## Commits
- 47be4df test: tests/Architecture/ScheduledCommandOptionsValidTest.php (RED)
- 9a6f817 fix: routes/console.php, two arrays plus a comment line above each

## RED result (before the fix)
exit=1, 3 failed. The failure text:
- `products:restore-sourceable-pending: The "--live" option does not accept a value. (compiled: products:restore-sourceable-pending --live="1" --push-to-woo="1" --include-listed-out-of-stock="1")`
- `products:draft-from-suggestions: The "--auto-approve" option does not accept a value. (compiled: ... --auto-approve="1" --create-missing-brands="1" --source-images="1" --limit=25 --no-confirm="1")`

## GREEN result (after the fix)
- ScheduledCommandOptionsValidTest, ScheduleTimezoneTest and TradeSyncScheduleTest: 9 passed.
- StockUpdaterParityScheduleTest, SyncTaxonomyCacheScheduleTest and DeptracTest: 13 passed.
- `pint --test` is clean on both touched files.
- All exit codes were checked directly.

## Deviations
None. `--auto-approve` is kept as the user chose.

## Found but not fixed
None. The all-events test, with the feature flags forced on, found only these two entries failing.

## Notes
- The all-events test takes about 78s, because `Artisan::all()` boots every command.
- The test forces on the config flags that gate scheduling: the pricing undercut, b2b storefront sync, agents SEO batch and ad optimisation, and the cutover divergence scan and sell-price reconcile. The guard therefore covers the gated events too.
- Both jobs will now actually run on their next tick. They remain gated by WOO_WRITE_ENABLED, and the draft job is capped at limit=25.
