<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Enums\IntegrationTestStatus;
use Database\Factories\Domain\Integrations\IntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 09.1 Plan 01 — IntegrationCredential (D-01 + D-03 + D-14).
 *
 * Polymorphic single-table credential storage for the 5 integration kinds
 * (supplier_api / woo_rest / bitrix_webhook / anthropic_api / langfuse_observability).
 *
 * `payload_encrypted` uses Laravel 12 native AES-256 'encrypted:array' cast —
 * the array is JSON-serialised before encryption. Field shape per kind documented
 * in `IntegrationCredentialKind::requiredFields()`.
 *
 * D-14: LogsActivity captures structural changes (name, is_active, last_test_*)
 * but EXPLICITLY OMITS payload_encrypted — spatie/activitylog reads raw attributes,
 * which would otherwise store ciphertext in activity_log. The allow-list below
 * excludes payload_encrypted entirely so neither plaintext nor ciphertext leaks.
 *
 * ─── APP_KEY ROTATION RUNBOOK ─────────────────────────────────────────
 * Rotating APP_KEY invalidates ALL encrypted payloads. Operator must
 * re-enter every credential via Filament after rotation. Document in
 * docs/ops/integration-credentials.md (handover artefact).
 * ──────────────────────────────────────────────────────────────────────
 */
class IntegrationCredential extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'integration_credentials';

    protected $fillable = [
        'kind',
        'name',
        'payload_encrypted',
        'is_active',
        'last_test_at',
        'last_test_status',
        'last_test_error',
        'last_test_latency_ms',
    ];

    protected $casts = [
        'kind' => IntegrationCredentialKind::class,
        'payload_encrypted' => 'encrypted:array',  // D-03 Laravel 12 native AES-256, JSON shape
        'is_active' => 'boolean',
        'last_test_at' => 'datetime',
        'last_test_status' => IntegrationTestStatus::class,
        'last_test_latency_ms' => 'integer',
    ];

    /**
     * D-14 — LogsActivity allow-list. payload_encrypted INTENTIONALLY OMITTED.
     * spatie/activitylog reads raw attributes; including payload_encrypted would
     * persist ciphertext to activity_log on every save. The structural columns
     * below are sufficient for audit (who changed name / is_active / test state).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'kind',
                'name',
                'is_active',
                'last_test_at',
                'last_test_status',
                'last_test_error',
                'last_test_latency_ms',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): IntegrationCredentialFactory
    {
        return IntegrationCredentialFactory::new();
    }
}
