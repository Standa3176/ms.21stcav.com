---
task: 260712-adl
title: Make the AdOptimisation GA4 read window tunable (one lookback knob)
type: gsd-quick
subsystem: agents/marketing
tags: [ad-optimisation, ga4, config, agents, tdd]
key-files:
  modified:
    - config/agents.php
    - app/Domain/Agents/Tools/Marketing/ReadGa4ChannelPerformanceTool.php
  created:
    - tests/Feature/Agents/Marketing/AdOptimisationLookbackConfigTest.php
  modified-tests:
    - tests/Feature/Agents/Marketing/ReadMarketingToolsTest.php
commits:
  - 01fac0d  # feat: unify default at 30 (config bump + config test)
  - 8a96ba1  # feat: tool reads configured lookback window
completed: 2026-07-12
---

# 260712-adl: AdOptimisation lookback config unification — Summary

One env var (`AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS` → `config('agents.ad_optimisation.data_lookback_days')`) now drives BOTH the "is there GA4 data to review" guard (command + dashboard) AND `ReadGa4ChannelPerformanceTool`'s read window, so the guard and the agent's read window can never disagree again.

## What changed

1. **`ReadGa4ChannelPerformanceTool`** — replaced the hardcoded `WINDOW_DAYS = 30` const with a `windowDays()` helper resolving `(int) config('agents.ad_optimisation.data_lookback_days', 30)`. Drives the `since` calc, the `window_days` field in the returned JSON, and the `description()` text (no longer hardcodes "30 days" — it reflects the configured window at runtime). `DEFAULT_WINDOW_DAYS = 30` remains only as the config-absent fallback.
2. **`config/agents.php`** — bumped the `data_lookback_days` env DEFAULT from `14` → `30`. Behaviour when unset is now: guard widened from 14d → 30d (harmless), tool default preserved at 30d. Docblock updated to describe the single unified knob.

No change to advice logic, the propose tool, the mapper, or write-gating. Still advice-only, still shadow-gated by `AGENT_WRITE_ENABLED`. The command (`RunAdOptimisationCommand`), dashboard (`MarketingDashboardPage::dispatchReview`) and job (`RunAdOptimisationJob`) already read the same config key — no code change needed there; they now simply agree with the tool.

## Tasks (TDD)

- **Task 2 — Config default bump** (committed first for green-commit discipline; see Deviations). `config/agents.php` default 14 → 30, plus `AdOptimisationLookbackConfigTest` asserting the default resolves to 30 when the env is unset and that the command + dashboard read the same key. Commit `01fac0d`.
- **Task 1 — Tool reads the config window.** Tool refactor + tests in `ReadMarketingToolsTest`. RED confirmed (tool returned hardcoded 30, "Failed asserting that 30 is identical to 120"), then GREEN. Commit `8a96ba1`.

## Test result (the load-bearing assertion)

Seeded a ~40-day-old row and a ~100-day-old row:
- With `data_lookback_days=120`: `window_days` output = **120** and the ~100-day-old ("Historic") row is **included** (2 channels).
- With the default (**30**): the ~100-day-old row is **excluded** (empty channels), `window_days` output = 30.

## Verify results

- `pest` (marketing agents dir): **38 passed** (113 assertions). Dashboard page: **8 passed**. Targeted window/config: **5 passed**. No regression — the `agents:run-ad-optimisation` no-data no-op guard and the dashboard "Review with Claude" guard still behave correctly, now keyed off the same config.
- `php artisan route:list --path=admin`: **exit 0**.
- `pint` (touched files): **pass**.
- `vendor/bin/deptrac analyse`: **0 violations** (the `AbstractString::toByteString` deprecation notice is pre-existing PHP 8.4 vendor-phar noise, not a violation).

## Operator note (prod)

To actually widen the reviewed window and get persisted advice in production the operator must set, in prod `.env`:
- `AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS=<N>` (e.g. 120 to cover the May–Jul historical, ads-on data)
- `AGENT_WRITE_ENABLED=true` (otherwise runs stay shadow-mode: AgentRun persists, zero Suggestions)

Redeploy = `config:cache` rebuild only. **No composer install, no migrations.**

## Deviations from Plan

**1. [Rule 3 — commit ordering] Committed Task 2 (config) before Task 1 (tool) for green-commit discipline.**
- **Reason:** The existing `ReadMarketingToolsTest` asserts `window_days === 30`. Once the tool reads config (Task 1), that assertion resolves to the config default — which is 14 until Task 2 bumps it to 30. Landing Task 1 first would leave the pre-existing test red between commits. Committing the config bump (Task 2) first keeps every commit's tree green. Both changes are otherwise exactly as specified; only the commit sequence was swapped.
- **Impact:** None on behaviour. Commit `01fac0d` (config) precedes `8a96ba1` (tool).

## Guardrails honoured

- Did NOT stage the pre-existing working-tree noise: `storage/app/research/supplier-probe.json` (deletion), `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified), untracked `.claude/`. All left untouched in the working tree.
- No push, no deploy. PHP via Herd (`~/.config/herd/bin/php84/php.exe`).

## Self-Check: PASSED

- `app/Domain/Agents/Tools/Marketing/ReadGa4ChannelPerformanceTool.php` — modified, committed in `8a96ba1`.
- `config/agents.php` — modified, committed in `01fac0d`.
- `tests/Feature/Agents/Marketing/AdOptimisationLookbackConfigTest.php` — created, committed in `01fac0d`.
- `tests/Feature/Agents/Marketing/ReadMarketingToolsTest.php` — modified, committed in `8a96ba1`.
- Commits `01fac0d` and `8a96ba1` present in `git log`.
