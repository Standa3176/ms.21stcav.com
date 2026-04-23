<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 6 Plan 04 — AutoCreateSetting (AUTO-07, D-09).
 *
 * Singleton — migration seeds exactly one row so `current()` always resolves.
 * Admin-editable via AutoCreateSettingsPage (Filament Page, not Resource).
 *
 * Pattern: matches Phase 4's CrmPipelineSetting singleton exactly
 * (firstOrFail + LogsActivity audit trail + hand-written policy).
 *
 * Columns:
 *   - mode: 'draft' (AUTO-07 default) | 'immediate_publish' (admin opt-in)
 *   - cta: meta_description trailer copy
 *   - optimize_images: spatie/image-optimizer invocation flag
 *   - completeness_threshold: integer 0-100 (D-09 publish gate)
 */
final class AutoCreateSetting extends Model
{
    use LogsActivity;

    protected $table = 'auto_create_settings';

    protected $fillable = [
        'mode',
        'cta',
        'optimize_images',
        'completeness_threshold',
    ];

    protected $casts = [
        'optimize_images' => 'bool',
        'completeness_threshold' => 'int',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['mode', 'cta', 'optimize_images', 'completeness_threshold'])
            ->logOnlyDirty();
    }

    /** Singleton accessor — migration guarantees one row; fail loud if missing. */
    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
