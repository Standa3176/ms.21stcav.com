<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorFtpCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpCredential (D-01 + D-03).
 *
 * Shared FTP/SFTP/FTPS connection pointing at one supplier-aggregated folder.
 * Many competitor_ftp_feeds belong to one credential.
 *
 * Encrypted columns (Laravel native AES-256 'encrypted' cast — D-03):
 *   - password_encrypted, private_key_encrypted, passphrase_encrypted
 *
 * D-09 — LogsActivity logs structural changes but EXPLICITLY EXCLUDES the 3
 * encrypted credential columns to prevent any ciphertext leak into the
 * audit_log table (Phase 11.1 D-09 pattern preserved).
 *
 * ─── APP_KEY ROTATION RUNBOOK ─────────────────────────────────────────
 * Rotating APP_KEY invalidates ALL encrypted credential columns on this
 * table. Operator must re-enter all credentials via the Filament UI after
 * rotation (CompetitorFtpCredentialResource). Same constraint as
 * Phase 11.1's CompetitorFtpSource.
 * ──────────────────────────────────────────────────────────────────────
 */
class CompetitorFtpCredential extends Model
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    public const PROTOCOL_FTP = 'ftp';

    public const PROTOCOL_SFTP = 'sftp';

    public const PROTOCOL_FTPS = 'ftps';

    public const TEST_STATUS_OK = 'ok';

    public const TEST_STATUS_FAILED = 'failed';

    /** HasUlids requirement — model PK is CHAR(26) string, not auto-incrementing. */
    protected $keyType = 'string';

    /** HasUlids requirement — disable auto-increment. */
    public $incrementing = false;

    protected $table = 'competitor_ftp_credentials';

    protected $fillable = [
        'name',
        'protocol',
        'host',
        'port',
        'username',
        'password_encrypted',
        'private_key_encrypted',
        'passphrase_encrypted',
        'base_path',
        'verify_ssl',
        'is_active',
        'last_test_at',
        'last_test_status',
        'last_test_error',
    ];

    protected $casts = [
        'port' => 'integer',
        // D-03 — Laravel native AES-256 encryption.
        'password_encrypted' => 'encrypted',
        'private_key_encrypted' => 'encrypted',
        'passphrase_encrypted' => 'encrypted',
        'verify_ssl' => 'boolean',
        'is_active' => 'boolean',
        'last_test_at' => 'datetime',
    ];

    public function feeds(): HasMany
    {
        return $this->hasMany(CompetitorFtpFeed::class, 'credential_id');
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
                'verify_ssl',
                'is_active',
                'last_test_status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CompetitorFtpCredentialFactory
    {
        return CompetitorFtpCredentialFactory::new();
    }
}
