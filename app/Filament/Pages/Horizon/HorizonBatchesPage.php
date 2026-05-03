<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon;

use App\Filament\Pages\Horizon\Concerns\HasHorizonRedisStatus;
use Filament\Pages\Page;
use Illuminate\Bus\BatchRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * /admin/horizon/batches — lists the 50 most-recent job batches via
 * Illuminate\Bus\BatchRepository (Horizon's own BatchesController uses
 * the same source). Each row shows id / name / total / pending /
 * processed / failed / created_at.
 *
 * BatchRepository is bound by Laravel core — works whether or not Redis
 * is reachable (the table lives in MySQL by default per
 * config('queue.batching.database')). The Redis banner still surfaces
 * via HasHorizonRedisStatus because the rest of the Horizon dashboard
 * cluster expects Redis; without Redis no batches will be processed
 * even if the historical rows render.
 *
 * Admin-only.
 */
class HorizonBatchesPage extends Page
{
    use HasHorizonRedisStatus;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Batches';

    protected static ?string $navigationParentItem = 'Horizon';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'horizon/batches';

    protected static ?string $title = 'Batches';

    protected static string $view = 'filament.pages.horizon.batches';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * Fetch the 50 most-recent batches. Returns an empty collection if the
     * batches table is missing (QueryException) — mirrors Horizon's own
     * BatchesController::index() defensive guard.
     *
     * @return Collection<int, \Illuminate\Bus\Batch>
     */
    public function getBatches(): Collection
    {
        try {
            $batches = app(BatchRepository::class)->get(50, null);
        } catch (QueryException $e) {
            return collect();
        }

        return collect($batches);
    }
}
