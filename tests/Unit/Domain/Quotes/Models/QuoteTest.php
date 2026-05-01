<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 01 Task 1 — schema-presence tests for the 4 new migrations.
|--------------------------------------------------------------------------
|
| These tests assert the migrations applied cleanly + the columns/indexes
| exist with the expected types. Plan 11-01 Task 2 extends this file with
| Quote model behaviour tests once the model class exists.
|
| Why Schema::hasColumns + Schema::getColumnType: portable across MySQL +
| SQLite (CI uses MySQL via meetingstore_ops_testing; local dev runs the
| in-memory SQLite fast-path). Plan tolerates the MySQL-only ENUM modify
| via the migration's DB::getDriverName() === 'mysql' guard.
*/

it('migrates quotes table with expected columns', function (): void {
    expect(Schema::hasTable('quotes'))->toBeTrue();

    $expected = [
        'id',
        'user_id',
        'customer_group_id',
        'customer_group_name_at_quote',
        'customer_email',
        'customer_name',
        'billing_address',
        'status',
        'total_pence_at_quote',
        'expires_at',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'expired_at',
        'rejection_metadata',
        'correlation_id',
        'created_at',
        'updated_at',
    ];
    expect(Schema::hasColumns('quotes', $expected))->toBeTrue(
        'quotes table is missing expected columns: '.implode(', ', $expected)
    );
});

it('migrates quote_lines table with integer pence columns', function (): void {
    expect(Schema::hasTable('quote_lines'))->toBeTrue();

    $expected = [
        'id',
        'quote_id',
        'sku',
        'quantity_int',
        'unit_price_pence_at_quote',
        'line_total_pence_at_quote',
        'product_snapshot',
        'sort_order',
        'created_at',
        'updated_at',
    ];
    expect(Schema::hasColumns('quote_lines', $expected))->toBeTrue();

    // Phase 11 D-13 + Pitfall 1 — pence MUST be integer columns, never decimal/float.
    // Both MySQL bigint and SQLite integer surface as 'integer' / 'bigint' to Doctrine.
    $unitType = Schema::getColumnType('quote_lines', 'unit_price_pence_at_quote');
    $totalType = Schema::getColumnType('quote_lines', 'line_total_pence_at_quote');
    expect($unitType)->toBeIn(['integer', 'bigint']);
    expect($totalType)->toBeIn(['integer', 'bigint']);

    $qtyType = Schema::getColumnType('quote_lines', 'quantity_int');
    expect($qtyType)->toBeIn(['integer', 'bigint']);
});

it('extends bitrix_entity_map with quote_id column + composite UNIQUE index', function (): void {
    expect(Schema::hasColumn('bitrix_entity_map', 'quote_id'))->toBeTrue();

    // Composite UNIQUE(entity_type, quote_id) — name set explicitly in
    // migration so we can assert presence portably across MySQL + SQLite.
    $indexes = collect(Schema::getIndexes('bitrix_entity_map'));
    $hasQuoteUnique = $indexes->contains(
        fn ($idx) => $idx['name'] === 'bitrix_entity_map_entity_type_quote_id_unique'
    );
    expect($hasQuoteUnique)->toBeTrue(
        'Expected UNIQUE index bitrix_entity_map_entity_type_quote_id_unique on (entity_type, quote_id)'
    );

    // The original Phase 4 (entity_type, woo_id) UNIQUE MUST coexist —
    // we never drop it, just add the parallel quote_id index.
    $hasWooUnique = $indexes->contains(
        fn ($idx) => $idx['name'] === 'bitrix_entity_map_type_woo_id_unique'
    );
    expect($hasWooUnique)->toBeTrue(
        'Phase 4 UNIQUE(entity_type, woo_id) must still exist after Phase 11 extension'
    );
});

it('extends alert_recipients with receives_quote_alerts boolean default false', function (): void {
    expect(Schema::hasColumn('alert_recipients', 'receives_quote_alerts'))->toBeTrue();

    // Insert a fresh row (with all required NOT NULL defaults satisfied) and
    // confirm the column defaults to 0 / false. Tests the migration default,
    // not the seeded fallback row force-update (covered in Plan 11-04 tests).
    $id = DB::table('alert_recipients')->insertGetId([
        'email' => 'phase11test+'.uniqid().'@example.com',
        'name' => 'Phase 11 Plan 01 Test',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $row = DB::table('alert_recipients')->where('id', $id)->first();
    expect((bool) $row->receives_quote_alerts)->toBeFalse();

    // Cleanup — we're not in a transaction here at the test level.
    DB::table('alert_recipients')->where('id', $id)->delete();
});
