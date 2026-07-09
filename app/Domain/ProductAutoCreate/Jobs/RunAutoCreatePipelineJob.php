<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Models\User;
use App\Notifications\OperatorJobCompletedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued wrapper around `products:draft-from-suggestions` for the Filament
 * bulk-action path. Runs the full chain (generate-drafts → assign-taxonomy →
 * source-images → auto-publish) on the operator-selected SKU list.
 *
 * Runs on the `sync-bulk` Horizon queue (512MB, 1800s worker timeout, single
 * worker). It was previously on `default` (256MB, 120s worker timeout), whose
 * supervisor silently SIGKILLed any batch running past 2 minutes — an hour-scale
 * Claude+Woo run needs sync-bulk's memory + time, and serializing it (one worker)
 * stops it competing with other bulk work. tries=1: per-SKU idempotency lives
 * inside the chained commands themselves; a job-level retry would re-spend
 * Claude money on already-drafted SKUs.
 *
 * ShouldBeUnique + uniqueFor=1800: the unique lock prevents a concurrent
 * duplicate run of the same SKU set (protects Claude/Woo spend), and the
 * bounded uniqueFor (matching the sync-bulk worker timeout = the real max
 * runtime) means a crashed/OOM'd/SIGKILL'd worker's lock auto-expires instead
 * of blocking re-dispatch of the identical SKUs forever. Mirrors the codebase's
 * own RecomputePriceJob (ShouldBeUnique + uniqueFor + onQueue('sync-bulk')).
 */
final class RunAutoCreatePipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    /**
     * @param  array<int,string>  $skus
     */
    public function __construct(
        private readonly array $skus,
        private readonly bool $sourceImages,
        private readonly bool $autoPublish,
        private readonly int $triggeredByUserId,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function uniqueId(): string
    {
        return 'auto-create:'.md5(implode(',', $this->skus));
    }

    public function handle(): int
    {
        Context::add('correlation_id', (string) Str::uuid());
        Context::add('triggered_by_user_id', $this->triggeredByUserId);

        // 260630-ry6 — hand the command a unique cache key it writes its run
        // summary to; we pull it back after the run for a per-SKU notification.
        $resultKey = 'autocreate:result:'.(string) Str::uuid();

        $args = [
            '--skus' => implode(',', $this->skus),
            '--no-confirm' => true,
            '--result-cache-key' => $resultKey,
        ];
        if ($this->sourceImages) {
            $args['--source-images'] = true;
        }
        if ($this->autoPublish) {
            $args['--auto-approve'] = true;
        }
        // 260702-qd8 — turn on auto-brand-creation for the Filament
        // explicit-selection path when the config switch is on (the operator
        // can disable it without a deploy). The CLI still opts in per-run.
        if ((bool) config('product_auto_create.auto_create_missing_brands', true)) {
            $args['--create-missing-brands'] = true;
        }

        Log::info('auto_create_pipeline.dispatched', [
            'sku_count' => count($this->skus),
            'source_images' => $this->sourceImages,
            'auto_publish' => $this->autoPublish,
            'triggered_by_user_id' => $this->triggeredByUserId,
        ]);

        $exitCode = Artisan::call('products:draft-from-suggestions', $args);

        // 260630-ry6 — pull the structured run summary the command just wrote
        // (pull = get + forget). Null on a cache miss / older command — the
        // formatter then falls back to the generic count-based body.
        $summary = Cache::pull($resultKey);

        // Quick task 260606-p4q → 260630-ry6 — bell-icon completion notification
        // for the triggering operator. Now reports the PER-SKU outcome (created
        // with brands + published-to-Woo + skipped-with-reason) via the pure
        // formatAutoCreateResultBody() helper instead of the old "N/M processed".
        [$body, $level] = self::formatAutoCreateResultBody($summary, count($this->skus), $this->autoPublish);

        try {
            if ($this->triggeredByUserId > 0 && ($user = User::find($this->triggeredByUserId))) {
                $user->notify(new OperatorJobCompletedNotification(
                    title: 'Auto-create pipeline complete',
                    body: $body,
                    level: $level,
                    url: $this->autoPublish ? '/admin/products' : '/admin/auto-create-reviews',
                ));
            }
        } catch (Throwable $e) {
            // Notifications table missing (prod not yet migrated) or any other
            // dispatch failure MUST NOT propagate — pipeline already finished.
            Log::warning('auto_create_pipeline.notify_failed', ['error' => $e->getMessage()]);
        }

        return $exitCode;
    }

    /**
     * Build [body, level] for the completion notification from the command's run summary.
     * Falls back to a generic line when $summary is null (older command / cache miss).
     *
     * @param  array<string,mixed>|null  $summary
     * @return array{0:string,1:string}
     */
    public static function formatAutoCreateResultBody(?array $summary, int $selectedCount, bool $autoPublish): array
    {
        if (! is_array($summary)) {
            return ["Pipeline finished for {$selectedCount} selected SKU(s). See /admin/products or /admin/auto-create-reviews.", 'info'];
        }
        $created = (int) ($summary['created'] ?? 0);
        $skipped = $summary['skipped'] ?? [];
        $skipCount = array_sum(array_map(static fn ($a): int => is_array($a) ? count($a) : 0, $skipped));

        $lines = [];
        $brands = $summary['by_brand'] ?? [];
        $brandStr = $brands !== [] ? ' ('.implode(', ', array_keys($brands)).')' : '';
        $lines[] = "Created/updated {$created}{$brandStr}.";
        if ($autoPublish && isset($summary['auto_publish']) && is_array($summary['auto_publish'])) {
            $ap = $summary['auto_publish'];
            $lines[] = 'Published to Woo: '.(int) ($ap['published'] ?? 0)
                .((int) ($ap['failed'] ?? 0) > 0 ? ', failed: '.(int) $ap['failed'] : '')
                .((int) ($ap['shadowed'] ?? 0) > 0 ? ', shadowed: '.(int) $ap['shadowed'] : '');
        }
        $labels = [
            'not_sourceable' => 'not sourceable (no supplier carries it)',
            'no_manufacturer' => 'no manufacturer in feed',
            'brand_not_on_woo' => 'brand not on Woo (add under Products → Brands)',
        ];
        foreach ($labels as $bucket => $label) {
            $skus = $skipped[$bucket] ?? [];
            if (is_array($skus) && $skus !== []) {
                $shown = array_slice($skus, 0, 10);
                $more = count($skus) - count($shown);
                $lines[] = 'Skipped — '.$label.': '.implode(', ', $shown).($more > 0 ? " (+{$more} more)" : '');
            }
        }
        $level = $created === 0 ? 'danger' : ($skipCount > 0 ? 'warning' : 'success');

        return [implode("\n", $lines), $level];
    }

    /**
     * Horizon-invoked failure callback. Mirrors handle() try/catch shape
     * so a notification-dispatch failure does not re-fail the job.
     */
    public function failed(Throwable $e): void
    {
        try {
            if ($this->triggeredByUserId > 0 && ($user = User::find($this->triggeredByUserId))) {
                $user->notify(new OperatorJobCompletedNotification(
                    title: 'Auto-create pipeline FAILED',
                    body: 'Pipeline threw '.class_basename($e).': '.Str::limit($e->getMessage(), 200),
                    level: 'danger',
                    url: '/admin/auto-create-reviews',
                ));
            }
        } catch (Throwable $ne) {
            Log::warning('auto_create_pipeline.notify_failed_on_failure', ['error' => $ne->getMessage()]);
        }
    }
}
