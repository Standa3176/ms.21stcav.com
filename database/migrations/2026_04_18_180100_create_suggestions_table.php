<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('kind', 64);                  // 'margin_change' | 'crm_push_failed' | 'new_product' | 'test'
            $t->string('status', 16)->default('pending'); // 'pending'|'approved'|'rejected'|'applied'|'failed'
            $t->string('correlation_id', 36)->index();
            $t->json('payload');
            $t->json('evidence')->nullable();
            $t->nullableMorphs('proposed_by');
            $t->timestamp('proposed_at');
            $t->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();

            $t->index(['kind', 'status']);
            $t->index(['status', 'proposed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
