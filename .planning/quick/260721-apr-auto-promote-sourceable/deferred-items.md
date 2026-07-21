# 260721-apr — deferred / out-of-scope discoveries

Logged, NOT fixed (outside this task's change surface).

## 1. A whole-repo `vendor/bin/pest` run fatals before finishing (pre-existing)

```
PHP Fatal error: Cannot redeclare function seedGaRow()
  (previously declared in tests/Feature/Agents/Marketing/ReadMarketingToolsTest.php:23)
  in tests/Feature/Integrations/MarketingOverviewStatsTest.php on line 27
```

Two Pest files declare the same global helper `seedGaRow()`. Pre-existing — both files
predate this task (last touched by `8a96ba1 feat(260712-adl)`), neither is touched here.
Effect: the full suite cannot be run in one process; suites must be run per-directory.
Fix would be to rename one helper or move it into a shared `tests/Pest.php` helper.

## 2. Repo-wide `vendor/bin/pint --test` reports many pre-existing violations

~100+ untouched files (app/Domain/Agents/*, app/Console/Commands/B2b/*, …) fail
`pint --test`. The repo convention is to run `pint` on touched files only
(cf. `a51fd93 style(260719-wth): pint formatting on WooClient throttle additions`).
All four files touched by this task pass `pint --test` cleanly.

## 3. Pre-existing working-tree noise left untouched (per plan guardrail)

- `storage/app/research/supplier-probe.json` (staged-for-deletion in the working tree)
- `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified)
- untracked `.claude/`

None of these were staged or committed by this task.
