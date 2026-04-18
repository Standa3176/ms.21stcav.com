<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_diffs', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 32)->default('woo');
            $t->string('method', 8);
            $t->string('endpoint', 255);
            $t->string('woo_id', 64)->nullable()->index();
            $t->json('payload');
            $t->string('correlation_id', 36)->index();
            $t->timestamp('created_at');
            $t->timestamp('applied_at')->nullable();
            $t->string('status', 16)->default('pending'); // 'pending' | 'applied' | 'superseded'
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_diffs');
    }
};
