# 260712-adl — Make the AdOptimisation GA4 read window tunable (one lookback knob)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** operator has GA4 data only older than the fixed windows (ads-off + a recent tracking gap; data
= May–Jul 10). "Review with Claude" declines (14-day guard) and, even if dispatched, the agent's read
tool is hardcoded to 30 days → would see nothing. Make ONE knob widen both so the agent can review the
historical (ads-on) data to inform the ads-restart decision.

## Change (surgical, config-driven)
Unify both windows on the EXISTING config key `agents.ad_optimisation.data_lookback_days` (env
`AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS`):
1. **`ReadGa4ChannelPerformanceTool`** — replace the hardcoded `WINDOW_DAYS = 30` with
   `(int) config('agents.ad_optimisation.data_lookback_days', 30)`. Update the `since` calc, the
   `window_days` field in the returned JSON, AND the tool `description()` text (currently says
   "last 30 days" — make it reflect the configured window, e.g. "the last N days" resolved at runtime).
2. **`config/agents.php`** — bump the `data_lookback_days` env DEFAULT from 14 → **30** so behaviour is
   unchanged when unset (button guard was 14, tool was 30; unify at 30 — a harmless widening of the
   guard, and the tool default is preserved). The button guard (`MarketingDashboardPage::dispatchReview`
   / `RunAdOptimisationCommand`) already reads this same key — no change needed there; it now simply
   agrees with the tool.
Result: one env var `AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS` controls BOTH the "is there data to review"
guard AND the agent's read window, so they can never disagree again.

## Tasks
### Task 1 — Tool reads the config window (TDD)
Refactor `ReadGa4ChannelPerformanceTool` to resolve its window from
`config('agents.ad_optimisation.data_lookback_days', 30)`. Test: with the config set to 120, the tool's
query window is 120 days and its `window_days` output = 120; with default, 30. Seed rows at ~40 and ~100
days old and assert the 120-day window includes the 100-day row while the 30-day default excludes it.

### Task 2 — Config default bump (TDD)
`config/agents.php` `data_lookback_days` env default 14 → 30. Test: `config('agents.ad_optimisation.data_lookback_days')`
=== 30 when the env var is unset. Confirm the button guard + command still read the same key (no code
change there — a test asserting the command/guard use the config value is enough).

## Verify
- `pest`: the tool window test + config default + the existing AdOptimisation agent/command/dashboard
  tests — GREEN, no regression. (The `agents:run-ad-optimisation` no-data no-op + the dashboard
  "Review with Claude" no-data guard must still behave correctly, now keyed off the same config.)
- `php artisan route:list --path=admin` exit 0.
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / out of scope
- Purely widening/config — NO change to advice logic, the propose tool, the mapper, or write gating.
  Still advice-only, still shadow-gated by `AGENT_WRITE_ENABLED`.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd. No push, no deploy. Atomic commits. Write `260712-adl-SUMMARY.md` (the unified
  knob, tests, verify; note operator must set `AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS` + `AGENT_WRITE_ENABLED`
  in prod to actually get persisted advice, and redeploy = config:cache rebuild, no composer/migrate).
