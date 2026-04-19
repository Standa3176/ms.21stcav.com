<?php

declare(strict_types=1);

use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\OrderNoteSynchroniser;
use App\Foundation\Integration\Services\IntegrationLogger;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 2 — OrderNoteSynchroniser
|--------------------------------------------------------------------------
|
| D-09 narrow note append: only new notes (hash-dedup by note id + body)
| are appended to Deal COMMENTS. Existing comments preserved (legacy parity).
*/

it('appends only new notes and preserves existing COMMENTS', function (): void {
    $map = BitrixEntityMap::factory()->dealFor(200, 'D200')->create();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealGet')
        ->once()
        ->with('D200', Mockery::any())
        ->andReturn(['COMMENTS' => 'existing note from sales']);
    $client->shouldReceive('dealUpdate')
        ->once()
        ->with('D200', Mockery::on(function ($fields) {
            return isset($fields['COMMENTS'])
                && str_contains($fields['COMMENTS'], 'existing note from sales')
                && str_contains($fields['COMMENTS'], 'Shipped via DHL tomorrow');
        }), Mockery::any());

    (new OrderNoteSynchroniser($client, app(IntegrationLogger::class)))->appendNewNotes(
        'D200',
        ['notes' => [['id' => 99, 'note' => 'Shipped via DHL tomorrow']]],
        $map->fresh(),
        'cid-1',
    );

    $fresh = $map->fresh();
    expect($fresh->notes_hash_set)->toBeArray();
    expect(count($fresh->notes_hash_set))->toBe(1);
});

it('is idempotent — second call with same note IDs does NOT dealUpdate again', function (): void {
    $map = BitrixEntityMap::factory()->dealFor(201, 'D201')->create();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealGet')->once()->andReturn(['COMMENTS' => '']);
    $client->shouldReceive('dealUpdate')->once();

    $sync = new OrderNoteSynchroniser($client, app(IntegrationLogger::class));

    $order = ['notes' => [['id' => 1, 'note' => 'First note']]];

    $sync->appendNewNotes('D201', $order, $map->fresh(), 'cid-1');
    // Second call reads the now-updated map — no new hashes → dealUpdate NOT fired.
    $sync->appendNewNotes('D201', $order, $map->fresh(), 'cid-2');

    // dealGet was expected once, dealUpdate once — if the second sync had called
    // them again Mockery would fail the test at verifyAllExpectations().
    expect(true)->toBeTrue();
});

it('short-circuits when order has no notes', function (): void {
    $map = BitrixEntityMap::factory()->dealFor(202, 'D202')->create();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldNotReceive('dealGet');
    $client->shouldNotReceive('dealUpdate');

    (new OrderNoteSynchroniser($client, app(IntegrationLogger::class)))->appendNewNotes(
        'D202',
        ['notes' => []],
        $map->fresh(),
        null,
    );

    expect(true)->toBeTrue();
});
