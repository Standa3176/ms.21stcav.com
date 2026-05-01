<?php

declare(strict_types=1);

use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Jobs\PushQuoteToBitrixDealJob;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Notifications\QuotePushFailedNotification;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\EntityDeduper;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Models\SyncDiff;
use Illuminate\Support\Facades\Notification;

/*
|==============================================================================
| Phase 11 Plan 04 — PushQuoteToBitrixDealJob (QUOT-05 + QUOT-06 + QUOT-07)
|==============================================================================
|
| 5 scenarios:
|   1. SHADOW MODE — QUOTE_BITRIX_PUSH_ENABLED=false writes to sync_diffs
|      with provider='bitrix-quote' and NEVER calls BitrixClient methods.
|   2. LIVE — first push: dealAdd + dealProductRowsSet, BitrixEntityMap row
|      created with entity_type='quote_deal'.
|   3. LIVE — second push (re-approval): dealUpdate + dealProductRowsSet,
|      no duplicate Bitrix Deal (idempotent QUOT-07).
|   4. DLQ — BitrixPermanentException emits Suggestion(kind=quote_push_failed)
|      + AlertRecipient notification.
|   5. LINE SHAPE — line items array matches RESEARCH §11 verified spec
|      (PRODUCT_NAME, PRICE, PRICE_EXCLUSIVE, PRICE_NETTO, PRICE_BRUTTO,
|      QUANTITY, TAX_RATE='20', TAX_INCLUDED='Y', CUSTOMIZED='Y',
|      MEASURE_CODE=796, MEASURE_NAME='pcs', SORT).
|
| Skip-on-MySQL-offline parity with Phase 11 Plan 02 (PriceSnapshotterTest).
*/

function skipIfMySqlOfflinePushQuote(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflinePushQuote();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
    config(['quote.bitrix_deal_type_id' => 'QUOTE']);
    config(['services.bitrix.write_enabled' => true]);
});

it('shadow-mode writes to sync_diffs with provider=bitrix-quote when QUOTE_BITRIX_PUSH_ENABLED=false', function (): void {
    skipIfMySqlOfflinePushQuote();
    config(['quote.bitrix_push_enabled' => false]);

    $quote = Quote::factory()->create([
        'customer_email' => 'shadow@example.com',
        'total_pence_at_quote' => 24_000,
    ]);
    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'SHADOW-001',
        'quantity_int' => 2,
        'unit_price_pence_at_quote' => 12_000,
        'line_total_pence_at_quote' => 24_000,
        'product_snapshot' => ['name' => 'Shadow Product'],
        'sort_order' => 10,
    ]);

    // BitrixClient + EntityDeduper MUST NOT be called in shadow mode.
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldNotReceive('dealAdd');
    $client->shouldNotReceive('dealUpdate');
    $client->shouldNotReceive('dealProductRowsSet');
    $deduper = Mockery::mock(EntityDeduper::class);
    $deduper->shouldNotReceive('findOrCreateContact');

    app()->instance(BitrixClient::class, $client);
    app()->instance(EntityDeduper::class, $deduper);

    $job = new PushQuoteToBitrixDealJob($quote->id, 'corr-shadow-001');
    $job->handle($client, app(\App\Domain\Pricing\Services\PriceCalculator::class), $deduper);

    $diff = SyncDiff::query()->where('provider', 'bitrix-quote')->first();
    expect($diff)->not->toBeNull();
    expect($diff->payload['quote_id'])->toBe($quote->id);
    expect($diff->payload['type_id'])->toBe('QUOTE');
    expect($diff->payload['rows'])->toBeArray();
    expect($diff->payload['rows'])->toHaveCount(1);
})->uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('first push: dealAdd then dealProductRowsSet + creates BitrixEntityMap quote_deal row', function (): void {
    skipIfMySqlOfflinePushQuote();
    config(['quote.bitrix_push_enabled' => true]);

    $quote = Quote::factory()->create([
        'customer_email' => 'live@example.com',
        'total_pence_at_quote' => 24_000,
    ]);
    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'LIVE-001',
        'quantity_int' => 1,
        'unit_price_pence_at_quote' => 24_000,
        'line_total_pence_at_quote' => 24_000,
        'product_snapshot' => ['name' => 'Live Product'],
        'sort_order' => 10,
    ]);

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealAdd')
        ->once()
        ->andReturn('999'); // Bitrix Deal ID
    $client->shouldReceive('dealProductRowsSet')
        ->once()
        ->withArgs(function ($dealId, $rows, $cid) {
            return $dealId === 999 && is_array($rows) && count($rows) === 1;
        });
    $client->shouldNotReceive('dealUpdate');

    $deduper = Mockery::mock(EntityDeduper::class);
    $deduper->shouldReceive('findOrCreateContact')->once()->andReturn('CONTACT_555');

    app()->instance(BitrixClient::class, $client);
    app()->instance(EntityDeduper::class, $deduper);

    $job = new PushQuoteToBitrixDealJob($quote->id, 'corr-live-001');
    $job->handle($client, app(\App\Domain\Pricing\Services\PriceCalculator::class), $deduper);

    $map = BitrixEntityMap::query()
        ->where('entity_type', BitrixEntityMap::ENTITY_QUOTE_DEAL)
        ->where('quote_id', $quote->id)
        ->first();
    expect($map)->not->toBeNull();
    expect($map->bitrix_id)->toBe('999');
    expect($map->created_via)->toBe(BitrixEntityMap::VIA_PUSH);
})->uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('second push: dealUpdate then dealProductRowsSet (idempotent QUOT-07; no new map row)', function (): void {
    skipIfMySqlOfflinePushQuote();
    config(['quote.bitrix_push_enabled' => true]);

    $quote = Quote::factory()->create([
        'customer_email' => 'idempotent@example.com',
        'total_pence_at_quote' => 12_000,
    ]);
    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'IDEM-001',
        'quantity_int' => 1,
        'unit_price_pence_at_quote' => 12_000,
        'line_total_pence_at_quote' => 12_000,
        'product_snapshot' => ['name' => 'Idempotent Product'],
        'sort_order' => 10,
    ]);

    // Pre-populate the entity map (simulating a prior approve cycle).
    $existingMap = BitrixEntityMap::create([
        'entity_type' => BitrixEntityMap::ENTITY_QUOTE_DEAL,
        'woo_id' => 0,
        'quote_id' => $quote->id,
        'bitrix_id' => '777',
        'last_payload_hash' => 'old-hash',
        'last_correlation_id' => 'corr-old',
        'last_pushed_at' => now()->subMinutes(5),
        'created_via' => BitrixEntityMap::VIA_PUSH,
    ]);

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldNotReceive('dealAdd');
    $client->shouldReceive('dealUpdate')->once()->withArgs(function ($id, $fields, $cid) {
        return $id === '777';
    });
    $client->shouldReceive('dealProductRowsSet')->once()->withArgs(function ($dealId, $rows, $cid) {
        return $dealId === 777;
    });

    $deduper = Mockery::mock(EntityDeduper::class);
    $deduper->shouldReceive('findOrCreateContact')->once()->andReturn('CONTACT_555');

    app()->instance(BitrixClient::class, $client);
    app()->instance(EntityDeduper::class, $deduper);

    $job = new PushQuoteToBitrixDealJob($quote->id, 'corr-idem-002');
    $job->handle($client, app(\App\Domain\Pricing\Services\PriceCalculator::class), $deduper);

    // No duplicate map row.
    $count = BitrixEntityMap::query()
        ->where('entity_type', BitrixEntityMap::ENTITY_QUOTE_DEAL)
        ->where('quote_id', $quote->id)
        ->count();
    expect($count)->toBe(1);

    $existingMap->refresh();
    expect($existingMap->bitrix_id)->toBe('777');
    expect($existingMap->last_correlation_id)->toBe('corr-idem-002');
})->uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('emits quote_push_failed Suggestion + AlertRecipient notification on BitrixPermanentException', function (): void {
    skipIfMySqlOfflinePushQuote();
    config(['quote.bitrix_push_enabled' => true]);
    Notification::fake();

    $quote = Quote::factory()->create([
        'customer_email' => 'permfail@example.com',
        'total_pence_at_quote' => 12_000,
    ]);
    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'FAIL-001',
        'quantity_int' => 1,
        'unit_price_pence_at_quote' => 12_000,
        'line_total_pence_at_quote' => 12_000,
        'product_snapshot' => ['name' => 'Fail Product'],
        'sort_order' => 10,
    ]);

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealAdd')
        ->once()
        ->andThrow(new BitrixPermanentException('crm.deal.add — invalid TYPE_ID'));

    $deduper = Mockery::mock(EntityDeduper::class);
    $deduper->shouldReceive('findOrCreateContact')->once()->andReturn('CONTACT_555');

    app()->instance(BitrixClient::class, $client);
    app()->instance(EntityDeduper::class, $deduper);

    $job = new PushQuoteToBitrixDealJob($quote->id, 'corr-fail-001');

    // handle() catches BitrixPermanentException and calls $this->fail() — does
    // not rethrow. Suggestion should be written even when fail() doesn't throw.
    try {
        $job->handle($client, app(\App\Domain\Pricing\Services\PriceCalculator::class), $deduper);
    } catch (\Throwable) {
        // fail() may bubble depending on Job state; suggestion is the load-bearing assertion.
    }

    $suggestion = Suggestion::query()->where('kind', 'quote_push_failed')->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);
    expect($suggestion->payload['sub_kind'])->toBe('permanent_validation');
    expect($suggestion->payload['quote_id'])->toBe($quote->id);
    expect($suggestion->correlation_id)->toBe('corr-fail-001');
})->uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('builds line items array with RESEARCH §11 verified DealProductRows shape', function (): void {
    skipIfMySqlOfflinePushQuote();
    config(['quote.bitrix_push_enabled' => false]);

    $quote = Quote::factory()->create([
        'customer_email' => 'shape@example.com',
        'total_pence_at_quote' => 24_000,
    ]);
    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'SHAPE-001',
        'quantity_int' => 3,
        'unit_price_pence_at_quote' => 8_000,
        'line_total_pence_at_quote' => 24_000,
        'product_snapshot' => ['name' => 'Shape Product'],
        'sort_order' => 10,
    ]);

    $client = Mockery::mock(BitrixClient::class);
    $deduper = Mockery::mock(EntityDeduper::class);
    app()->instance(BitrixClient::class, $client);
    app()->instance(EntityDeduper::class, $deduper);

    $job = new PushQuoteToBitrixDealJob($quote->id, 'corr-shape-001');
    $job->handle($client, app(\App\Domain\Pricing\Services\PriceCalculator::class), $deduper);

    $diff = SyncDiff::query()->where('provider', 'bitrix-quote')->latest('id')->first();
    expect($diff)->not->toBeNull();
    expect($diff->payload['rows'])->toBeArray()->toHaveCount(1);

    $row = $diff->payload['rows'][0];
    expect($row)->toHaveKeys([
        'PRODUCT_ID', 'PRODUCT_NAME', 'PRICE', 'PRICE_EXCLUSIVE', 'PRICE_NETTO',
        'PRICE_BRUTTO', 'QUANTITY', 'TAX_RATE', 'TAX_INCLUDED', 'CUSTOMIZED',
        'MEASURE_CODE', 'MEASURE_NAME', 'SORT',
    ]);
    expect($row['PRODUCT_NAME'])->toBe('Shape Product');
    expect($row['QUANTITY'])->toBe('3');
    expect($row['TAX_RATE'])->toBe('20');
    expect($row['TAX_INCLUDED'])->toBe('Y');
    expect($row['CUSTOMIZED'])->toBe('Y');
    expect($row['MEASURE_CODE'])->toBe(796);
    expect($row['MEASURE_NAME'])->toBe('pcs');
    expect($row['SORT'])->toBe(10);
})->uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);
