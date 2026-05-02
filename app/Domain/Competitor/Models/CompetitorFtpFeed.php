<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorFtpFeedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpFeed (D-01 + D-02).
 *
 * One row per remote file inside the shared FTP folder.
 *
 * Auto-increment integer PK (D-02) so admin-managed rows have stable per-row
 * identity in the Filament table (matches screenshot's 1, 10, 12, 13, 16, 18, 27...).
 *
 * SoftDeletes for audit history (CONTEXT.md Claude's Discretion).
 *
 * UNIQUE local_filename — collision check at create time prevents two feeds
 * overwriting the same file in storage/app/competitors/incoming/.
 *
 * D-13 pull algorithm fields:
 *   - last_pulled_at — when Laravel last fetched (column shown as "Last Updated"
 *     in the Filament table, NOT updated_at)
 *   - remote_file_date — remote mtime; drives stale-feed alert when older than
 *     config('competitor.ftp.stale_days', 30)
 *   - last_pull_status — success | failed | skipped | no_change
 *   - consecutive_failures — 3-strike auto-disable counter (D-13 step 7)
 */
class CompetitorFtpFeed extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const FORMAT_CSV = 'csv';

    public const FORMAT_TSV = 'tsv';

    public const FORMAT_ZIP = 'zip';

    public const FORMAT_TXT = 'txt';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_NO_CHANGE = 'no_change';

    protected $table = 'competitor_ftp_feeds';

    // Auto-increment integer PK — explicit defaults (Eloquent default but defensive).
    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'competitor_id',
        'credential_id',
        'remote_filename',
        'local_filename',
        'format',
        'is_active',
        'last_pulled_at',
        'remote_file_date',
        'last_pull_status',
        'last_pull_error',
        'consecutive_failures',
    ];

    protected $casts = [
        'competitor_id' => 'integer',
        'is_active' => 'boolean',
        'last_pulled_at' => 'datetime',
        'remote_file_date' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    protected $attributes = [
        'consecutive_failures' => 0,
        'is_active' => true,
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(CompetitorFtpCredential::class, 'credential_id');
    }

    /**
     * D-09 parity — LogsActivity allow-list. No encrypted columns on this
     * table; allow-list still explicit so future column additions are
     * a deliberate audit-log decision.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'competitor_id',
                'credential_id',
                'remote_filename',
                'local_filename',
                'format',
                'is_active',
                'last_pull_status',
                'consecutive_failures',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CompetitorFtpFeedFactory
    {
        return CompetitorFtpFeedFactory::new();
    }
}
