<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Database\Factories\Domain\CRM\CrmFieldMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 4 Plan 01 — Woo ↔ Bitrix field map (CRM-06).
 *
 * Admin-editable through Filament (Plan 04-04). Seeded from legacy plugin
 * defaults in Plan 04-03. Changes are audit-worthy — misconfigured mappings
 * silently drop data, so LogsActivity is essential for regression analysis.
 */
final class CrmFieldMapping extends Model
{
    use HasFactory;
    use LogsActivity;

    public const ENTITY_DEAL = 'deal';

    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_COMPANY = 'company';

    protected $fillable = [
        'entity_type',
        'woo_field',
        'bitrix_field',
        'is_custom',
        'transformer',
        'sort_order',
    ];

    protected $casts = [
        'is_custom' => 'bool',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['entity_type', 'woo_field', 'bitrix_field', 'is_custom', 'transformer', 'sort_order'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): CrmFieldMappingFactory
    {
        return CrmFieldMappingFactory::new();
    }
}
