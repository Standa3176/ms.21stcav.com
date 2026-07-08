---
phase: 260708-jou-automate-catalogue-gaps-bulk-fixes-dispa
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Products/Jobs/RunCatalogueGapFixJob.php
  - app/Filament/Pages/CatalogueGapsPage.php
  - config/services.php
  - tests/Feature/Products/CatalogueGapsPageTest.php
  - tests/Feature/Products/RunCatalogueGapFixJobTest.php
must_haves:
  truths:
    - "The Catalogue Gaps BULK fix actions (Source images / Backfill EAN / Resync) no longer run synchronously in the web request and no longer stop at 25. ONE click now queues the ENTIRE ticked selection (up to a high safety ceiling) as background jobs — the operator never has to run the next batch manually."
    - "Work is dispatched as chunks of config('services.woo.maintenance_fix_batch_limit', 25) SKUs, one RunCatalogueGapFixJob per chunk, onto the Horizon 'sync-bulk' queue (maxProcesses=1 → processed ONE batch at a time), so a big run cannot saturate prod CPU or burst the per-SKU fix APIs (Icecat/Serper/EAN-search). No Horizon supervisor/deploy change needed — sync-bulk already exists."
    - "A safety ceiling config('services.woo.maintenance_fix_max_per_run', 1000) caps how many products a single click can queue; if the selection exceeds it, the first N are queued and the notification says '(capped at N of M — run again for the rest)'. The confirmation modal explains it queues in the background, one batch at a time."
    - "RunCatalogueGapFixJob runs its command via Artisan::call(['--skus'=>csv]) but ONLY for an allow-list of the three fix commands (defence-in-depth — never executes an arbitrary command name); tries=1 (never auto-retry a money-costing batch); it sets its own queue to 'sync-bulk'. Per-row (single-SKU) fixAction() stays synchronous + unchanged."
  artifacts:
    - path: "app/Domain/Products/Jobs/RunCatalogueGapFixJob.php"
      provides: "queued, allow-listed, chunk fix-runner on sync-bulk"
      contains: "ALLOWED_COMMANDS"
    - path: "app/Filament/Pages/CatalogueGapsPage.php"
      provides: "bulk actions dispatch chunked jobs instead of synchronous Artisan::call"
      contains: "RunCatalogueGapFixJob::dispatch"
    - path: "config/services.php"
      provides: "woo.maintenance_fix_max_per_run ceiling"
      contains: "maintenance_fix_max_per_run"
  key_links:
    - from: "CatalogueGapsPage::bulkFixAction()"
      to: "RunCatalogueGapFixJob on the sync-bulk queue, chunked"
      via: "skus->take(maxPerRun)->chunk(batchLimit) → dispatch per chunk"
      pattern: "RunCatalogueGapFixJob"
    - from: "RunCatalogueGapFixJob::handle()"
      to: "Artisan::call(command, --skus)"
      via: "ALLOWED_COMMANDS allow-list guard"
      pattern: "ALLOWED_COMMANDS"
---

<objective>
Remove the manual "run the next 25" toil: make one click on a Catalogue Gaps bulk fix queue the whole ticked
selection to the background, processed in throttled chunks so it stays safe on prod + API spend. Replaces the
260708-gab synchronous 25-cap with chunked dispatch to the Horizon sync-bulk queue (single worker = natural
throttle), keeping a high safety ceiling + a clear confirmation.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-jou-automate-catalogue-gaps-bulk-fixes-dispa/
@CLAUDE.md
@app/Filament/Pages/CatalogueGapsPage.php
@config/services.php
@app/Domain/Sync/Jobs/SyncChunkJob.php
@tests/Feature/Products/CatalogueGapsPageTest.php
---
SyncChunkJob is the queue-job convention to mirror: `final class … implements ShouldQueue; use Dispatchable,
InteractsWithQueue, Queueable, SerializesModels; public int $tries; public int $timeout; $this->onQueue('…')
in the constructor`. Horizon supervisors (config/horizon.php): sync-bulk = balance simple, maxProcesses=1 →
ONE job at a time (the throttle). The three fix commands already accept `--skus=<csv>`.
Current bulkFixAction (260708-gab) builds a capped CSV and calls Artisan::call synchronously — REPLACE that
body with chunked dispatch. Per-row fixAction() (single SKU, synchronous) must stay unchanged.
config/services.php has a 'woo' => [...] array already holding maintenance_fix_batch_limit.
</context>

<interfaces>
=== NEW app/Domain/Products/Jobs/RunCatalogueGapFixJob.php ===
```php
<?php

declare(strict_types=1);

namespace App\Domain\Products\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * 260708-jou — runs ONE chunk of a Catalogue Gaps bulk fix in the background.
 * Dispatched (one per ~25-SKU chunk) by CatalogueGapsPage bulk actions onto the
 * sync-bulk queue (Horizon maxProcesses=1 → batches run one-at-a-time, so a big
 * run can't saturate prod CPU or burst the per-SKU fix APIs).
 */
final class RunCatalogueGapFixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Defence-in-depth: this job runs an artisan command by NAME — only ever these three. */
    public const ALLOWED_COMMANDS = [
        'products:source-images',
        'products:backfill-merchant-feed',
        'products:resync-to-woo',
    ];

    public int $tries = 1;      // per-SKU API calls cost money — never auto-retry a whole batch.

    public int $timeout = 900;  // 25 image-sources can be slow; sync-bulk is single-worker so this is safe.

    /** @param array<int, string> $skus */
    public function __construct(
        public readonly string $command,
        public readonly array $skus,
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function handle(): void
    {
        if (! in_array($this->command, self::ALLOWED_COMMANDS, true)) {
            Log::warning('RunCatalogueGapFixJob: refused disallowed command', [
                'command' => $this->command, 'actor_id' => $this->actorId,
            ]);

            return;
        }

        $csv = implode(',', array_values(array_filter(
            $this->skus,
            fn ($s): bool => is_string($s) && $s !== '',
        )));

        if ($csv === '') {
            return;
        }

        Log::info('RunCatalogueGapFixJob: running fix batch', [
            'command' => $this->command, 'count' => count($this->skus), 'actor_id' => $this->actorId,
        ]);

        Artisan::call($this->command, ['--skus' => $csv]);
    }
}
```

=== config/services.php (inside 'woo' => [...]) ===
Add below maintenance_fix_batch_limit:
```php
// 260708-jou — hard ceiling on how many products a single Catalogue Gaps bulk-fix CLICK may queue
// (the work runs in the background now; this stops one click queueing the entire catalogue).
'maintenance_fix_max_per_run' => (int) env('WOO_MAINTENANCE_FIX_MAX_PER_RUN', 1000),
```
Update the maintenance_fix_batch_limit comment to note it is now the CHUNK size for background bulk fixes
(SKUs per RunCatalogueGapFixJob), not a hard per-click cap.

=== CatalogueGapsPage::bulkFixAction() — replace the whole method body ===
```php
private function bulkFixAction(string $name, string $label, string $icon, string $command): BulkAction
{
    $chunkSize = max(1, (int) config('services.woo.maintenance_fix_batch_limit', 25));
    $maxPerRun = max($chunkSize, (int) config('services.woo.maintenance_fix_max_per_run', 1000));

    return BulkAction::make($name)
        ->label($label)
        ->icon($icon)
        ->requiresConfirmation()
        ->modalDescription("Queues {$command} for every selected product (up to {$maxPerRun}) as background batches of {$chunkSize}, processed one batch at a time on the sync-bulk queue so it can't overload the shop or the fix APIs. Watch progress in Horizon — you don't need to re-run.")
        ->visible(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
        ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
        ->action(function (Collection $records) use ($command, $label, $chunkSize, $maxPerRun): void {
            $skus = $records
                ->pluck('sku')
                ->filter(fn ($sku): bool => $sku !== null && $sku !== '')
                ->values();
            $selected = $skus->count();

            if ($selected === 0) {
                Notification::make()->warning()->title('No SKUs in the selection')->send();

                return;
            }

            $skus = $skus->take($maxPerRun);
            $queued = $skus->count();

            $batches = 0;
            foreach ($skus->chunk($chunkSize) as $chunk) {
                RunCatalogueGapFixJob::dispatch($command, $chunk->values()->all(), auth()->id());
                $batches++;
            }

            Log::info('CatalogueGapsPage: bulk fix queued', [
                'command' => $command, 'selected' => $selected, 'queued' => $queued,
                'batches' => $batches, 'chunk_size' => $chunkSize, 'actor_id' => auth()->id(),
            ]);

            $title = "{$label}: queued {$queued} product(s) in {$batches} background batch(es)";
            if ($selected > $maxPerRun) {
                $title .= " (capped at {$maxPerRun} of {$selected} — run again for the rest)";
            }

            Notification::make()
                ->success()
                ->title($title)
                ->body('Processing in the background on the sync-bulk queue — watch Horizon; one batch at a time.')
                ->send();
        });
}
```
Add `use App\Domain\Products\Jobs\RunCatalogueGapFixJob;`. Keep the Artisan import (per-row fixAction still
uses Artisan::call synchronously — leave that method exactly as-is).
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: queued chunked fix job + rewire bulk actions</name>
  <files>
    app/Domain/Products/Jobs/RunCatalogueGapFixJob.php,
    app/Filament/Pages/CatalogueGapsPage.php,
    config/services.php,
    tests/Feature/Products/CatalogueGapsPageTest.php,
    tests/Feature/Products/RunCatalogueGapFixJobTest.php
  </files>
  <behavior>
    Create the job + config key + rewire bulkFixAction per <interfaces>.
    RunCatalogueGapFixJobTest (new): Artisan::fake(); an allowed command with SKUs → Artisan::call invoked once
    with the joined --skus CSV; a DISALLOWED command → Artisan::call NOT invoked (assertNotRun); empty/blank SKUs
    → not invoked. Also Queue::fake() + RunCatalogueGapFixJob::dispatch(...) then Queue::assertPushedOn('sync-bulk', RunCatalogueGapFixJob).
    CatalogueGapsPageTest (rewrite the 260708-gab cap cases to the queued model): Queue::fake(); seed products
    matching the default gap filter; select N and invoke a bulk fix action → assert the RIGHT number of
    RunCatalogueGapFixJob were pushed (ceil(min(N,maxPerRun)/chunkSize)), that the UNION of their ->skus equals
    the queued SKUs (chunked, none lost/duplicated), and that each job's ->command is the expected fix command.
    With config('services.woo.maintenance_fix_max_per_run') set low (e.g. 2) + chunkSize 1, selecting 4 → only 2
    jobs pushed (ceiling enforced). Keep the existing gap-filter / per-row fixAction / missing_brand cases green
    (per-row still calls Artisan synchronously — do NOT convert it).
  </behavior>
  <action>
    Add the job, config key, rewire bulkFixAction, write both tests. Run them + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/RunCatalogueGapFixJobTest.php tests/Feature/Products/CatalogueGapsPageTest.php 2>&1 | tail -20</automated>
    Expected: GREEN — bulk actions push chunked RunCatalogueGapFixJob on sync-bulk (no synchronous Artisan in bulk); ceiling enforced; allow-list guarded; per-row + filter cases still pass.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint app/Domain/Products/Jobs/RunCatalogueGapFixJob.php app/Filament/Pages/CatalogueGapsPage.php config/services.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - RunCatalogueGapFixJob (allow-listed, sync-bulk, tries=1) created; bulk actions dispatch chunked jobs for the whole selection up to the ceiling with a background notice; per-row unchanged; both tests green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/RunCatalogueGapFixJobTest.php tests/Feature/Products/CatalogueGapsPageTest.php` → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). Horizon must be
  running (it already is) — the sync-bulk supervisor (1 worker) processes the batches. No horizon.php change.
- Behaviour: on Catalogue Gaps, tick as many products as you like and click a bulk fix (Source images / Backfill
  EAN / Resync). It now QUEUES the whole selection in background batches of 25 and works through them one batch at
  a time — no more "run the next 25". A single click queues at most WOO_MAINTENANCE_FIX_MAX_PER_RUN (default 1000)
  products; above that it does the first 1000 and asks you to run again. Watch progress at /horizon.
- Chunk size = WOO_MAINTENANCE_FIX_BATCH_LIMIT (still 25). Because sync-bulk is single-worker, batches never run
  concurrently — safe for prod CPU + the per-SKU fix APIs. Per-row (single-product) fixes still run immediately.
</verification>

<success_criteria>
- One click queues the entire ticked selection (up to the ceiling) as chunked RunCatalogueGapFixJob on sync-bulk, processed one batch at a time; no manual re-runs; allow-list + tries=1 + ceiling guardrails intact; per-row unchanged; tests green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260708-jou-automate-catalogue-gaps-bulk-fixes-dispa/260708-jou-SUMMARY.md` documenting the
new queued/chunked model, the sync-bulk throttle, the allow-list + ceiling guardrails, the config keys, the tests,
and the deploy note (no migration; Horizon already running).
</output>
