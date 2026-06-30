---
phase: 260630-ry6-surface-auto-create-pipeline-per-sku-res
plan: 01
subsystem: ProductAutoCreate / Suggestions
tags: [notifications, auto-create, operator-ux, cache-handoff]
requires:
  - "products:draft-from-suggestions skip-reason buckets (260629-pqh)"
  - "OperatorJobCompletedNotification (260606-p4q)"
provides:
  - "Per-SKU auto-create outcome surfaced in the operator notification bell"
  - "RunAutoCreatePipelineJob::formatAutoCreateResultBody() pure helper"
  - "DraftFromSuggestionsCommand --result-cache-key + writeRunSummary()"
affects:
  - app/Console/Commands/DraftFromSuggestionsCommand.php
  - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
tech-stack:
  added: []
  patterns:
    - "Cache-key handoff: command writes a structured run summary to a job-supplied cache key (TTL 600s); job reads via Cache::pull (get+forget)."
    - "Pure static formatter for notification body/level — unit-testable without DB or Filament."
key-files:
  created:
    - tests/Unit/Domain/ProductAutoCreate/AutoCreateResultBodyTest.php
  modified:
    - app/Console/Commands/DraftFromSuggestionsCommand.php
    - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
decisions:
  - "writeRunSummary() is a no-op when no --result-cache-key is passed — the interactive CLI path is byte-identical (no Cache writes)."
  - "Job reads the summary via Cache::pull (get+forget) so a unique per-run key never leaks; null on cache miss falls back to the generic count-based info body."
  - "Notification level is outcome-driven: danger (0 created), warning (partial: some created + some skipped), success (all created), info (summary absent)."
metrics:
  duration: ~25m
  completed: 2026-06-30
---

# Quick Task 260630-ry6: Surface auto-create pipeline per-SKU result Summary

The app's "Auto-create selected (full pipeline)" bulk action now reports the **per-SKU outcome** in the operator's notification bell — created (with brands) + published-to-Woo + skipped-with-reason — instead of the old black-box "N/M SKUs processed".

## The black-box problem

The Filament bulk action dispatches `RunAutoCreatePipelineJob`, which wraps `products:draft-from-suggestions`. When the job finished it sent only `"{successCount}/{total} SKUs processed"` — never WHY a SKU didn't create. The CLI already prints rich skip reasons (260629-pqh: not_sourceable / no_manufacturer / brand_not_on_woo), but that detail died in the queued path, so operators were dropping to the VPS to learn why a part (e.g. HD226) didn't create.

## The cache-key handoff

1. **`RunAutoCreatePipelineJob::handle()`** mints a unique key `autocreate:result:{uuid}`, passes it as `--result-cache-key`, runs the command via `Artisan::call`, then `Cache::pull($resultKey)` reads-and-forgets the summary.
2. **`DraftFromSuggestionsCommand`** has a new `--result-cache-key=` option and a private `writeRunSummary(array)` helper that does `Cache::put($key, $summary, 600)` **only when the key is non-empty**. The structured summary is written at BOTH return points:
   - the `$count === 0` zero-candidate early return (created=0, populated skip buckets, auto_publish=null) — so a 0-created run still reports why;
   - the final return (created count + created SKUs, `by_brand` counts, skip buckets, and the auto-publish published/shadowed/failed counts when `--auto-approve`).
3. **`RunAutoCreatePipelineJob::formatAutoCreateResultBody(?array $summary, int $selectedCount, bool $autoPublish): array{0:string,1:string}`** is a pure public static helper that turns the summary into `[body, level]`. Null summary → generic fallback body + `info`. Otherwise it builds "Created/updated N (brands)", an optional "Published to Woo: …" line, and one "Skipped — <reason>: <skus>" line per non-empty bucket (first 10 SKUs + "(+N more)"). Level: `danger` (0 created) / `warning` (partial) / `success` (all created).
4. **`SuggestionResource`** auto_create_full toast reworded: "Dispatched. The created / skipped (with reasons) result will appear in your notifications bell when the pipeline finishes (usually under a minute)."

The existing try/catch around `$user->notify(...)` is preserved (notifications table may be missing on un-migrated prod).

## CRITICAL — CLI path unaffected

`writeRunSummary()` reads `--result-cache-key`; when absent (`''`) it returns without touching the cache. Interactive `php artisan products:draft-from-suggestions` therefore behaves byte-identically — no `Cache::put`, no behaviour change. The job is the only caller that passes a key.

## Verification

- `pest tests/Unit/Domain/ProductAutoCreate/AutoCreateResultBodyTest.php` → **5 passed** (17 assertions): null-fallback→info, all-created+auto-publish→success, partial→warning, zero-created→danger, >10-skipped→"+N more".
- `pest tests/Feature/Console/ tests/Unit/Console/ tests/Unit/Domain/ProductAutoCreate/` → **170 passed** (675 assertions) — no regression on the existing draft-from-suggestions + pipeline tests.
- `pint --test` on the 3 changed files + the new test → **PASS**.
- grep confirms `writeRunSummary` at both command return points + `Cache::pull` / `formatAutoCreateResultBody` in the job.

## Operator notes (NOT executed here — local-only commit)

- **Deploy:** push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- After deploy, trigger 'Auto-create selected (full pipeline)' on a pending SKU; the bell notification now states created (with brands) + published-to-Woo + skipped-with-reason per SKU. Makes the app self-explanatory — no more dropping to the VPS to learn why a part didn't create.

## Self-Check: PASSED

- Files created/modified all present on disk.
- Commits `40f8bf0` (Task 1) and `f61591e` (Task 2) present in `git log`.
