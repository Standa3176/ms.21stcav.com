<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Jobs;

use App\Domain\Dashboard\Services\CsvExportWriter;
use App\Mail\QueuedCsvExportMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Phase 7 Plan 03 — queued CSV export job (D-06, 10k-100k row path).
 *
 * Runs on the `sync-bulk` Horizon queue (Phase 1 FOUND-09 supervisor). The
 * browser path (<10k rows) streams inline; this job handles the slow path
 * where the UI has already shown the user a "queue this export to email?"
 * confirmation.
 *
 * Pipeline:
 *   1. Rehydrate the Resource's base query via its static getEloquentQuery().
 *   2. Apply simple scalar filter payload keys as `->where()` conditions.
 *      Complex Filament filter shapes (arrays, nested) are pre-flattened by
 *      QueueCsvExportAction before dispatch (Plan 07-03 Task 2 concern).
 *   3. Stream ->cursor() rows into CsvExportWriter::writeToFile() at
 *      storage/app/exports/{filename}.
 *   4. Build a temporarySignedRoute to 'exports.download' valid for 7 days
 *      (Threat T-07-03-05 mitigation — signed URL expiry + opaque filename).
 *   5. Mail::to the queuing user with the signed URL + approximate row count.
 *
 * PHP 8.4 trait-collision guard (Phase 5/6 lesson):
 *   NEVER declare `public string $queue = 'sync-bulk'` — the trait
 *   composition of Dispatchable + Queueable + SerializesModels
 *   InteractsWithQueue + the $queue property creates a collision on PHP 8.4.
 *   Use $this->onQueue('sync-bulk') inside the constructor instead.
 */
final class QueuedCsvExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800; // 30 minutes — matches Horizon sync-bulk supervisor.

    /**
     * @param  class-string  $resourceClass  Filament Resource FQCN (e.g. ProductResource::class)
     * @param  array<string, mixed>  $filterPayload  Flat key=>scalar map for ->where()
     */
    public function __construct(
        public readonly string $resourceClass,
        public readonly array $filterPayload,
        public readonly int $userId,
        public readonly string $correlationId,
    ) {
        // PHP 8.4 trait-collision guard — NEVER public string $queue.
        $this->onQueue('sync-bulk');
    }

    public function handle(CsvExportWriter $writer): void
    {
        $resourceClass = $this->resourceClass;

        // Filament Resources expose getEloquentQuery() as a static; we call it
        // reflectively rather than via an instance to match Filament's own
        // invocation path.
        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $resourceClass::getEloquentQuery();

        foreach ($this->filterPayload as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $query->where($key, $value);
            }
        }

        $filename = $writer->filename($resourceClass::getSlug(), $this->correlationId);
        $path = storage_path('app/exports/'.$filename);
        File::ensureDirectoryExists(dirname($path));

        // Count BEFORE cursor iteration so we can include the total in the mail;
        // cursor() is lazy so no duplicated work.
        $approxRowCount = (int) (clone $query)->count();

        $writer->writeToFile(
            $query->cursor()->map(fn ($model) => $model->toArray()),
            $path,
        );

        $user = User::find($this->userId);
        if ($user === null) {
            Log::warning('QueuedCsvExportJob: user missing at completion', [
                'user_id' => $this->userId,
                'filename' => $filename,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        $signedUrl = URL::temporarySignedRoute(
            'exports.download',
            now()->addDays(7),
            ['file' => $filename],
        );

        Mail::to($user->email)->send(new QueuedCsvExportMail(
            filename: $filename,
            signedUrl: $signedUrl,
            rowCountApprox: $approxRowCount,
        ));

        Log::info('QueuedCsvExportJob: export delivered', [
            'user_id' => $this->userId,
            'filename' => $filename,
            'approx_rows' => $approxRowCount,
            'correlation_id' => $this->correlationId,
        ]);
    }
}
