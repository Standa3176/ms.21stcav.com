<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Database\Factories\Domain\CRM\CrmPipelineSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 4 Plan 01 — Bitrix pipeline + landing stage (D-05, D-07, CRM-07).
 *
 * Singleton — migration seeds exactly one row so `current()` always resolves.
 * Plan 04-04 ships a Filament settings page to let admins set the values.
 *
 * Placement: under `App\Domain\CRM\Models` (singular) per Plan truth-list;
 * the migration uses plural table name `crm_pipeline_settings` (Laravel
 * convention) and Eloquent auto-derives the table from the class name
 * (CrmPipelineSetting → crm_pipeline_settings).
 */
final class CrmPipelineSetting extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'bitrix_pipeline_id',
        'landing_stage_id',
        'assigned_user_id',
        'deal_title_template',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bitrix_pipeline_id', 'landing_stage_id', 'assigned_user_id', 'deal_title_template'])
            ->logOnlyDirty();
    }

    /** Singleton accessor — migration guarantees one row; fail loud if missing. */
    public static function current(): self
    {
        return self::query()->firstOrFail();
    }

    protected static function newFactory(): CrmPipelineSettingFactory
    {
        return CrmPipelineSettingFactory::new();
    }
}
