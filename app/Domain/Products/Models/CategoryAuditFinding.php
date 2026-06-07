<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quick task 260607-t6w — Eloquent model for the category_audit_findings
 * snapshot table.
 *
 * Schema lives in 2026_06_07_201255_create_category_audit_findings_table.
 * Snapshot semantics: TRUNCATE + re-INSERT every Fri 22:00 London run by
 * AuditProductCategoriesCommand. There is no history — the table reflects
 * "current misclassified live products" only.
 *
 * issue_type is intentionally kept as a plain string (column-level
 * varchar(32) constraint via the migration) rather than an Eloquent enum
 * cast — operators tune the bucket set in the command file (where the
 * BRAND_NATURAL_HOMES const lives) and a PHP enum would require a separate
 * migration + class round-trip every time we add a new shape.
 *
 * severity is an int 1..4 where 1 is most severe (missing — Google
 * Shopping disapprovals) and 4 is least severe (suspicious brand-category
 * mismatch — operator review judgement call).
 *
 * $guarded = [] is safe here because the only write path is the
 * AuditProductCategoriesCommand's bulk insert via DB::table (which
 * bypasses Eloquent mass-assignment entirely) and the per-row Filament
 * Page reads — no operator-shaped form ever hydrates this model.
 */
final class CategoryAuditFinding extends Model
{
    public const ISSUE_MISSING = 'missing';

    public const ISSUE_ORPHANED = 'orphaned';

    public const ISSUE_UNCATEGORIZED = 'uncategorized';

    public const ISSUE_SUSPICIOUS = 'suspicious';

    protected $table = 'category_audit_findings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => 'int',
            'audited_at' => 'datetime',
            'brand_id' => 'int',
            'category_id' => 'int',
            'product_id' => 'int',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
