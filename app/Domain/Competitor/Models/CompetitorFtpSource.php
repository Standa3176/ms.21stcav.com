<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorFtpSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 11.1 Plan 01 — CompetitorFtpSource (D-01..D-12).
 *
 * Admin-managed FTP/SFTP/FTPS source for competitor CSV pulls. Belongs to
 * a Competitor (cascade-deleted with parent). Credentials are stored
 * encrypted at rest via Laravel's native `'encrypted'` Eloquent cast (D-04
 * — AES-256). DB rows never see plaintext; only model accessors decrypt.
 *
 * D-09 — LogsActivity logs structural changes (host/port/username/protocol/
 * base_path/cron/is_active) but EXPLICITLY EXCLUDES the 3 encrypted credential
 * columns to prevent any ciphertext leak into the audit_log table.
 *
 * D-12 — `consecutive_failures` is the 3-strike circuit-breaker counter.
 * CompetitorFtpPullCommand atomically increments via `->increment(...)`;
 * after 3 failures the source is auto-disabled (is_active=false) and a
 * CompetitorFtpPullFailedNotification dispatches to AlertRecipient rows
 * with receives_competitor_ftp_alerts=true.
 *
 * ─── APP_KEY ROTATION RUNBOOK ─────────────────────────────────────────
 * Rotating APP_KEY invalidates ALL encrypted credential columns on this
 * table. Operator runbook:
 *   1. Decrypt all rows BEFORE rotating: SELECT id, password_encrypted,
 *      private_key_encrypted, passphrase_encrypted FROM competitor_ftp_sources
 *      via tinker → record plaintexts in 1Password (or equivalent).
 *   2. Rotate APP_KEY.
 *   3. Re-enter credentials via Filament CompetitorFtpSourceResource — the
 *      `'encrypted'` cast re-encrypts under the new key on save.
 * Document this in docs/ops/credential-rotation.md when that file lands.
 * ──────────────────────────────────────────────────────────────────────
 */
final class CompetitorFtpSource extends Model
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIAL = 'partial';

    public const PROTOCOL_FTP = 'ftp';

    public const PROTOCOL_SFTP = 'sftp';

    public const PROTOCOL_FTPS = 'ftps';

    /** HasUlids requirement — model PK is CHAR(26) string, not auto-incrementing. */
    protected $keyType = 'string';

    /** HasUlids requirement — disable auto-increment. */
    public $incrementing = false;

    protected $fillable = [
        'competitor_id',
        'name',
        'protocol',
        'host',
        'port',
        'username',
        'password_encrypted',
        'private_key_encrypted',
        'passphrase_encrypted',
        'base_path',
        'filename_pattern',
        'cron_expression',
        'verify_ssl',
        'is_active',
        'consecutive_failures',
        'last_pulled_at',
        'last_pull_status',
        'last_pull_files_fetched',
        'last_pull_error',
    ];

    protected $casts = [
        // D-04 — Laravel native AES-256 encryption (`encrypted` cast).
        'password_encrypted' => 'encrypted',
        'private_key_encrypted' => 'encrypted',
        'passphrase_encrypted' => 'encrypted',
        'verify_ssl' => 'boolean',
        'is_active' => 'boolean',
        'port' => 'integer',
        'consecutive_failures' => 'integer',
        'last_pull_files_fetched' => 'integer',
        'last_pulled_at' => 'datetime',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * D-09 — LogsActivity allow-list. Encrypted credential columns are
     * intentionally OMITTED so ciphertext never lands in activity_log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'protocol',
                'host',
                'port',
                'username',
                'base_path',
                'filename_pattern',
                'cron_expression',
                'verify_ssl',
                'is_active',
                'last_pull_status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CompetitorFtpSourceFactory
    {
        return CompetitorFtpSourceFactory::new();
    }
}
