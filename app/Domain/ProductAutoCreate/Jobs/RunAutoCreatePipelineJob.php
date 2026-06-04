<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Queued wrapper around `products:draft-from-suggestions` for the Filament
 * bulk-action path. Runs the full chain (generate-drafts → assign-taxonomy →
 * source-images → auto-publish) on the operator-selected SKU list.
 *
 * Uses the default queue so it's picked up by the existing Horizon supervisor
 * (no new supervisor config required). tries=1: per-SKU idempotency lives
 * inside the chained commands themselves; a job-level retry would re-spend
 * Claude money on already-drafted SKUs.
 */
final class RunAutoCreatePipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  array<int,string>  $skus
     */
    public function __construct(
        private readonly array $skus,
        private readonly bool $sourceImages,
        private readonly bool $autoPublish,
        private readonly int $triggeredByUserId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'auto-create:'.md5(implode(',', $this->skus));
    }

    public function handle(): int
    {
        Context::add('correlation_id', (string) \Illuminate\Support\Str::uuid());
        Context::add('triggered_by_user_id', $this->triggeredByUserId);

        $args = [
            '--skus' => implode(',', $this->skus),
            '--no-confirm' => true,
        ];
        if ($this->sourceImages) {
            $args['--source-images'] = true;
        }
        if ($this->autoPublish) {
            $args['--auto-approve'] = true;
        }

        Log::info('auto_create_pipeline.dispatched', [
            'sku_count' => count($this->skus),
            'source_images' => $this->sourceImages,
            'auto_publish' => $this->autoPublish,
            'triggered_by_user_id' => $this->triggeredByUserId,
        ]);

        return Artisan::call('products:draft-from-suggestions', $args);
    }
}
