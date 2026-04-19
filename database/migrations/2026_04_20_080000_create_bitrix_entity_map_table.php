<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 01 — bitrix_entity_map (Pitfall 6 CRITICAL).
 *
 * Dedup ledger for Woo entity → Bitrix entity mapping. Without this table,
 * the first `order.updated` replay will silently create a duplicate Deal.
 *
 * UNIQUE(entity_type, woo_id) is load-bearing — every push path MUST
 * consult this index before calling crm.*.add.
 *
 * Column notes:
 *   - bitrix_id VARCHAR(64): Bitrix returns IDs as strings (Pitfall 3);
 *     never cast to int. Some configurations use prefixed string IDs.
 *   - woo_id BIGINT UNSIGNED: 0 sentinel for companies (no Woo primary key).
 *   - email_hash: sha256(mb_strtolower(email)) — indexed for GDPR lookup.
 *   - last_status_snapshot: Woo order status for deals (D-09 stage-change guard).
 *   - last_payload_hash: sha256(json_encode(payload)) — drift detection.
 *   - created_via: 'push' | 'backfill' | 'adopted_legacy' | 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix_entity_map', function (Blueprint $t) {
            $t->id();
            $t->enum('entity_type', ['deal', 'contact', 'company']);
            $t->unsignedBigInteger('woo_id');                        // 0 sentinel for companies
            $t->string('bitrix_id', 64);                             // Pitfall 3 — VARCHAR not BIGINT
            $t->string('email_hash', 64)->nullable();                // sha256(mb_strtolower(email))
            $t->string('last_payload_hash', 64)->nullable();         // drift detection
            $t->string('last_status_snapshot', 30)->nullable();      // D-09 Woo status for deals
            $t->timestamp('last_pushed_at')->useCurrent();
            $t->string('last_correlation_id', 36)->nullable();
            $t->string('created_via', 30)->default('push');          // 'push'|'backfill'|'adopted_legacy'|'manual'
            $t->timestamps();

            // Pitfall 6 — non-negotiable dedup guarantee
            $t->unique(['entity_type', 'woo_id'], 'bitrix_entity_map_type_woo_id_unique');

            $t->index(['entity_type', 'bitrix_id'], 'bitrix_entity_map_type_bitrix_idx');
            $t->index('last_pushed_at');
            $t->index('email_hash');
            $t->index('last_correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix_entity_map');
    }
};
