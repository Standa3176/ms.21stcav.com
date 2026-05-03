<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 09.1 Plan 01 — integration_credentials table (D-01 + D-02).
 *
 * Polymorphic single-table credential storage for the 5 integration kinds
 * (supplier_api / woo_rest / bitrix_webhook / anthropic_api / langfuse_observability).
 *
 * UNIQUE(kind) for v1 — one row per integration kind. Multi-instance (e.g.
 * dev + prod Woo) deferred per CONTEXT.md Claude's Discretion.
 *
 * payload_encrypted holds the AES-256 encrypted JSON payload (Laravel native
 * 'encrypted:array' cast on the Eloquent model — D-03). Field shape per kind
 * documented in IntegrationCredentialKind::requiredFields().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id(); // auto-increment integer PK per D-02 (admin-managed; matches Phase 11.2 precedent)
            $table->string('kind', 64)->unique()->comment(
                'IntegrationCredentialKind enum value. UNIQUE per D-02 — one row per integration kind for v1.'
            );
            $table->string('name', 255);
            $table->text('payload_encrypted')->comment(
                'AES-256 (Laravel native) encrypted JSON. Shape per IntegrationCredentialKind::requiredFields().'
            );
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status', 16)->nullable()->comment('IntegrationTestStatus enum value');
            $table->text('last_test_error')->nullable();
            $table->unsignedInteger('last_test_latency_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
