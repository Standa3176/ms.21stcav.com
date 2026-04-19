<?php

declare(strict_types=1);

use App\Domain\CRM\Models\BitrixEntityMap;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 01 Task 2 — Pitfall 6 dedup ledger schema guarantees
|--------------------------------------------------------------------------
|
| These tests enforce the invariants that make bitrix_entity_map safe as
| the source of truth for "has this Woo entity already been pushed?".
| Breaking any of them is a CRITICAL regression — duplicate Deals on live
| Bitrix is the #1 legacy-plugin complaint.
*/

it('enforces UNIQUE(entity_type, woo_id)', function (): void {
    BitrixEntityMap::factory()->dealFor(100, 'B1')->create();

    $this->expectException(QueryException::class);

    BitrixEntityMap::factory()->dealFor(100, 'B2')->create();
});

it('stores bitrix_id as a string (VARCHAR 64), never as integer', function (): void {
    $row = BitrixEntityMap::factory()->dealFor(101, 'CMP_9999999999999999')->create();

    $fresh = BitrixEntityMap::findOrFail($row->id);
    expect($fresh->bitrix_id)->toBe('CMP_9999999999999999');
    expect(is_string($fresh->bitrix_id))->toBeTrue();
});

it('defaults created_via to push', function (): void {
    $row = BitrixEntityMap::factory()->create();

    expect($row->fresh()->created_via)->toBe('push');
});

it('indexes email_hash for GDPR lookup', function (): void {
    BitrixEntityMap::factory()->contactFor(1, 'B10', 'foo@bar.com')->create();
    BitrixEntityMap::factory()->contactFor(2, 'B11', 'foo@bar.com')->create();
    BitrixEntityMap::factory()->contactFor(3, 'B12', 'foo@bar.com')->create();

    $hash = hash('sha256', mb_strtolower('foo@bar.com'));

    expect(BitrixEntityMap::where('email_hash', $hash)->count())->toBe(3);
});

it('exposes entity-type scopes for push-path queries', function (): void {
    BitrixEntityMap::factory()->dealFor(200, 'BD1')->create();
    BitrixEntityMap::factory()->contactFor(200, 'BC1', 'a@example.com')->create();
    BitrixEntityMap::factory()->companyFor('BX1', 'Acme', 'SW1A 1AA')->create();

    expect(BitrixEntityMap::deals()->count())->toBe(1);
    expect(BitrixEntityMap::contacts()->count())->toBe(1);
    expect(BitrixEntityMap::companies()->count())->toBe(1);
    expect(BitrixEntityMap::forWooOrder(200)->first()->bitrix_id)->toBe('BD1');
    expect(BitrixEntityMap::forWooCustomer(200)->first()->bitrix_id)->toBe('BC1');
});
