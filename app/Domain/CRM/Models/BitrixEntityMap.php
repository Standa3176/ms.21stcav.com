<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Database\Factories\Domain\CRM\BitrixEntityMapFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 4 Plan 01 — Pitfall 6 CRITICAL dedup ledger.
 *
 * Every CRM write path MUST consult this ledger before calling crm.*.add.
 * UNIQUE(entity_type, woo_id) enforces the guarantee at the DB layer.
 *
 * No LogsActivity trait — this is an operational dedup table, not
 * user-facing configuration data. Audit noise would drown the real
 * signal from crm_field_mappings / crm_pipeline_settings edits.
 *
 * @property int $id
 * @property string $entity_type      'deal' | 'contact' | 'company' | 'quote_deal'
 * @property int $woo_id              0 sentinel for companies + quote_deal rows
 * @property string|null $quote_id    ULID CHAR(26) — Phase 11 quote_deal entity_type;
 *                                    composite UNIQUE (entity_type, quote_id)
 * @property string $bitrix_id        VARCHAR(64) — never cast to int (Pitfall 3)
 * @property string|null $email_hash  sha256(mb_strtolower(email))
 * @property string|null $last_payload_hash
 * @property string|null $last_status_snapshot
 * @property \Illuminate\Support\Carbon $last_pushed_at
 * @property string|null $last_correlation_id
 * @property string $created_via      'push'|'backfill'|'adopted_legacy'|'manual'
 */
final class BitrixEntityMap extends Model
{
    use HasFactory;

    public const ENTITY_DEAL = 'deal';

    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_COMPANY = 'company';

    /** Phase 11 Plan 01 — Quote → Bitrix Deal mapping (QUOT-07 dedup). */
    public const ENTITY_QUOTE_DEAL = 'quote_deal';

    public const VIA_PUSH = 'push';

    public const VIA_BACKFILL = 'backfill';

    public const VIA_ADOPTED_LEGACY = 'adopted_legacy';

    public const VIA_MANUAL = 'manual';

    protected $table = 'bitrix_entity_map';

    protected $fillable = [
        'entity_type',
        'woo_id',
        'quote_id',          // Phase 11 Plan 01 — ULID CHAR(26) for quote_deal rows
        'bitrix_id',
        'email_hash',
        'last_payload_hash',
        'last_status_snapshot',
        'notes_hash_set',
        'last_pushed_at',
        'last_correlation_id',
        'created_via',
    ];

    protected $casts = [
        'woo_id' => 'integer',
        'last_pushed_at' => 'datetime',
        'notes_hash_set' => 'array',
    ];

    public function scopeDeals(Builder $q): Builder
    {
        return $q->where('entity_type', self::ENTITY_DEAL);
    }

    public function scopeContacts(Builder $q): Builder
    {
        return $q->where('entity_type', self::ENTITY_CONTACT);
    }

    public function scopeCompanies(Builder $q): Builder
    {
        return $q->where('entity_type', self::ENTITY_COMPANY);
    }

    public function scopeForWooOrder(Builder $q, int $wooOrderId): Builder
    {
        return $q->where('entity_type', self::ENTITY_DEAL)->where('woo_id', $wooOrderId);
    }

    public function scopeForWooCustomer(Builder $q, int $wooCustomerId): Builder
    {
        return $q->where('entity_type', self::ENTITY_CONTACT)->where('woo_id', $wooCustomerId);
    }

    /** Phase 11 Plan 01 — narrow to quote_deal rows (parallels scopeDeals). */
    public function scopeQuoteDeals(Builder $q): Builder
    {
        return $q->where('entity_type', self::ENTITY_QUOTE_DEAL);
    }

    /**
     * Phase 11 Plan 01 — Plan 11-04 EntityDeduper.findDealByQuoteId entry point.
     *
     * Keys off the new composite UNIQUE(entity_type, quote_id) index. ULID
     * is a 26-char string — never cast to int (mirror Pitfall 3 for bitrix_id).
     */
    public function scopeForQuote(Builder $q, string $quoteId): Builder
    {
        return $q->where('entity_type', self::ENTITY_QUOTE_DEAL)->where('quote_id', $quoteId);
    }

    protected static function newFactory(): BitrixEntityMapFactory
    {
        return BitrixEntityMapFactory::new();
    }
}
