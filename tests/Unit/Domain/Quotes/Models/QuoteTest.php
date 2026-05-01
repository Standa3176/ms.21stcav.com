<?php

declare(strict_types=1);

use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

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

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 01 Task 2 — Quote model behaviour tests.
|--------------------------------------------------------------------------
|
| HasUlids trait, LogsActivity (logOnly status + status timestamps + total),
| relations (lines/customer/customerGroup), ulidShort helper.
*/

it('Quote PK is a 26-character ULID via HasUlids trait', function (): void {
    $quote = Quote::factory()->create();
    expect($quote->id)->toBeString()->toHaveLength(26);
});

it('Quote status defaults to draft and round-trips as a string', function (): void {
    $quote = Quote::factory()->create();
    expect($quote->status)->toBe(Quote::STATUS_DRAFT);
    expect($quote->status)->toBe(QuoteStatus::Draft->value);
});

it('Quote LogsActivity captures only status + status timestamps + total (not customer_email)', function (): void {
    $quote = Quote::factory()->create();

    // Mutate customer_email — MUST NOT log (PII excluded per T-11-01-04).
    $quote->customer_email = 'updated+'.uniqid().'@example.com';
    $quote->save();

    $emailActivity = Activity::where('subject_id', $quote->id)
        ->where('subject_type', Quote::class)
        ->latest()
        ->first();

    if ($emailActivity !== null) {
        $attrs = $emailActivity->properties['attributes'] ?? [];
        expect($attrs)->not->toHaveKey('customer_email');
    }

    // Mutate status — MUST log per logOnly contract.
    $quote->status = Quote::STATUS_SENT;
    $quote->sent_at = now();
    $quote->save();

    $statusActivity = Activity::where('subject_id', $quote->id)
        ->where('subject_type', Quote::class)
        ->latest()
        ->first();

    expect($statusActivity)->not->toBeNull();
    $attrs = $statusActivity->properties['attributes'] ?? [];
    expect($attrs)->toHaveKey('status');
});

it('Quote->lines() returns HasMany ordered by sort_order', function (): void {
    $quote = Quote::factory()->create();
    QuoteLine::factory()->create(['quote_id' => $quote->id, 'sort_order' => 2]);
    QuoteLine::factory()->create(['quote_id' => $quote->id, 'sort_order' => 0]);
    QuoteLine::factory()->create(['quote_id' => $quote->id, 'sort_order' => 1]);

    $orders = $quote->lines()->pluck('sort_order')->all();
    expect($orders)->toBe([0, 1, 2]);
});

it('Quote->customer() returns nullable BelongsTo User', function (): void {
    $quote = Quote::factory()->create(['user_id' => null]);
    expect($quote->customer)->toBeNull();
    expect($quote->customer())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

it('Quote->ulidShort returns first 8 chars of the ULID', function (): void {
    $quote = Quote::factory()->create();
    expect($quote->ulidShort())->toHaveLength(8);
    expect($quote->ulidShort())->toBe(substr($quote->id, 0, 8));
});

it('QuoteStatus reserves PendingApproval + Approved cases without exposing them as v1.0 transitions', function (): void {
    expect(QuoteStatus::isReserved(QuoteStatus::PendingApproval))->toBeTrue();
    expect(QuoteStatus::isReserved(QuoteStatus::Approved))->toBeTrue();
    expect(QuoteStatus::isReserved(QuoteStatus::Draft))->toBeFalse();
    expect(QuoteStatus::isReserved(QuoteStatus::Sent))->toBeFalse();
    expect(QuoteStatus::isReserved(QuoteStatus::Accepted))->toBeFalse();
    expect(QuoteStatus::isReserved(QuoteStatus::Rejected))->toBeFalse();
    expect(QuoteStatus::isReserved(QuoteStatus::Expired))->toBeFalse();
});

it('QuoteStatus does NOT include a withdrawn case (D-06 deferred)', function (): void {
    $values = array_map(fn (QuoteStatus $c) => $c->value, QuoteStatus::cases());
    expect($values)->not->toContain('withdrawn');
});

it('Quote billing_address + rejection_metadata cast as array', function (): void {
    $quote = Quote::factory()->create([
        'billing_address' => ['line1' => '110 Bishopsgate'],
        'rejection_metadata' => ['reason' => 'price_too_high', 'note' => 'too steep'],
    ]);
    expect($quote->billing_address)->toBeArray();
    expect($quote->billing_address['line1'])->toBe('110 Bishopsgate');
    expect($quote->rejection_metadata)->toBeArray();
    expect($quote->rejection_metadata['reason'])->toBe('price_too_high');
});
