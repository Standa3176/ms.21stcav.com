<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('source', 32);              // 'woo' | 'bitrix' (future)
            $t->string('topic', 64);                // 'order' | 'customer' etc
            $t->string('delivery_id', 128);         // X-WC-Webhook-Delivery-ID
            $t->json('headers');                    // full header dump (sensitive redacted by caller)
            $t->longText('raw_body');               // raw payload bytes as received
            $t->string('correlation_id', 36)->index();
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->string('status', 16)->default('received'); // 'received' | 'processed' | 'failed'
            $t->text('error_message')->nullable();
            $t->timestamps();

            $t->unique(['source', 'delivery_id']);
            $t->index(['source', 'topic', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
