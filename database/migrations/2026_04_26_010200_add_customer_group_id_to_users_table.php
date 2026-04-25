<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Plan 04 Task 1 — denormalised users.customer_group_id (D-08).
 *
 * Adds a nullable BIGINT FK to customer_groups(id) ON DELETE SET NULL.
 * Soft-fail on group deletion is intentional for users (D-08): unlike
 * pricing_rules which uses restrictOnDelete (active rules block group
 * deletion), users null-back on group deletion so admin doesn't have
 * to reassign every user when retiring a group.
 *
 * Denormalised from Woo customer role (D-07): the
 * UpdateCustomerGroupOnUserRoleChange listener (Task 3) writes this column
 * on every customer.created / customer.updated webhook so order-time price
 * resolution doesn't need a join-on-every-call.
 *
 * B-02 hardening: this column is INTENTIONALLY OMITTED from User::$fillable.
 * Listener + backfill use forceFill([...]) so $fillable is unnecessary;
 * omitting it makes mass-assignment via Breeze ProfileController +
 * RegisteredUserController + future API forms structurally impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('customer_group_id')
                ->nullable()
                ->after('email')
                ->constrained('customer_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_group_id');
        });
    }
};
