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

    /** Defence-in-depth: this job runs an artisan command by NAME — only ever these. */
    public const ALLOWED_COMMANDS = [
        'products:source-images',
        'products:backfill-merchant-feed',
        'products:resync-to-woo',
        'products:publish-sourced-brands',
    ];

    public int $tries = 1;      // per-SKU API calls cost money — never auto-retry a whole batch.

    public int $timeout = 900;  // 25 image-sources can be slow; sync-bulk is single-worker so this is safe.

    /**
     * @param  array<int, string>  $skus
     * @param  array<string, mixed>  $options  extra artisan options merged into the call
     *                                         (e.g. ['--push-to-woo' => true] for the
     *                                         Source-images fix). Trusted admin-only input.
     */
    public function __construct(
        public readonly string $command,
        public readonly array $skus,
        public readonly ?int $actorId = null,
        public readonly array $options = [],
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

        Artisan::call($this->command, array_merge(['--skus' => $csv], $this->options));
    }
}
