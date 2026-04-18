<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOUND-05 — integration_events table.
 *
 * Append-only log of every outbound/inbound integration call (Woo, Bitrix, supplier,
 * merchant_center, suggestions apply). Written exclusively by IntegrationLogger::log().
 *
 * D-05 retention: 90 days (prune command ships in Plan 05).
 * T-03-01 mitigation: request_headers values pre-redacted by IntegrationLogger.
 *
 * Uses nullableUlidMorphs for subject — CHAR(26) subject_id supports ULID-keyed
 * models such as Suggestion (landing in Plan 04). Phase 1 iter fix — do not change
 * to nullableMorphs without also migrating Suggestion's primary-key type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 32);                         // 'woo' | 'bitrix' | 'supplier' | 'merchant_center' | 'suggestions'
            $t->string('direction', 8)->default('outbound');   // 'outbound' | 'inbound'
            $t->string('operation', 64);                       // 'product.update' | 'deal.create' | 'apply:test'
            $t->nullableUlidMorphs('subject');                 // CHAR(26) subject_id for ULID-keyed subjects
            $t->string('correlation_id', 36)->index();
            $t->string('endpoint', 255);
            $t->string('method', 8);                           // GET | POST | PUT | DELETE | PATCH | APPLY (internal)
            $t->json('request_body')->nullable();
            $t->json('request_headers')->nullable();           // secrets REDACTED before write (T-03-01)
            $t->json('response_body')->nullable();
            $t->integer('http_status')->nullable();
            $t->integer('latency_ms')->nullable();
            $t->unsignedTinyInteger('attempt')->default(1);
            $t->string('status', 12);                          // 'success' | 'failed' | 'retrying'
            $t->text('error_message')->nullable();
            $t->timestamp('created_at')->nullable();
            // No updated_at — append-only.

            $t->index(['channel', 'created_at']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
