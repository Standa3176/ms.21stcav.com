<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Database\Factories\Domain\CRM\CrmStatusMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 4 Plan 01 — Woo order status → Bitrix Deal STAGE_ID (D-06, CRM-07).
 *
 * Seeded on migration by CrmStatusMappingSeeder with the 7 standard Woo
 * statuses; admin assigns real STAGE_IDs via Filament (Plan 04-04).
 *
 * stageIdForStatus() is the single entry point Plan 04-03's
 * UpdateDealStageJob consults on `order.updated`.
 */
final class CrmStatusMapping extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'woo_status',
        'bitrix_stage_id',
        'bitrix_stage_label',
        'is_terminal',
    ];

    protected $casts = [
        'is_terminal' => 'bool',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['woo_status', 'bitrix_stage_id', 'bitrix_stage_label', 'is_terminal'])
            ->logOnlyDirty();
    }

    /** Convenience lookup — returns null if no mapping exists yet. */
    public static function stageIdForStatus(string $wooStatus): ?string
    {
        /** @var string|null $stageId */
        $stageId = self::query()->where('woo_status', $wooStatus)->value('bitrix_stage_id');

        return $stageId;
    }

    protected static function newFactory(): CrmStatusMappingFactory
    {
        return CrmStatusMappingFactory::new();
    }
}
