<?php

declare(strict_types=1);

use App\Console\Commands\RefreshBrandsToAddCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260702-kn5 — RefreshBrandsToAddCommand::indexSuggestionSkus()
|--------------------------------------------------------------------------
|
| Suggestion has a ULID string primary key (HasUlids). The shipped collect step
| did `$suggestionSku[(int) $sug->id] = $sku` — `(int) '01ksace…'` = 1, so every
| pending suggestion overwrote array key 1 (last-write-wins → count 1). The walk
| therefore processed only 1 of 8,826 pending new_product_opportunity
| suggestions and tagging tagged 0.
|
| indexSuggestionSkus() is the pure, string-keyed replacement: it maps rows to
| [ (string) ULID id => sku ], keying by the FULL ULID string (NEVER (int)),
| skipping rows with a blank evidence.sku, and handling evidence supplied as a
| JSON string OR an already-decoded array.
|
| The distinct-ULID → distinct-entries assertion is the bug guard: casting these
| ids to int would collide them all onto 1; string keying keeps them separate.
*/

beforeEach(function (): void {
    $this->command = app(RefreshBrandsToAddCommand::class);
});

it('keys distinct ULID ids as distinct entries (never collapses to 1)', function (): void {
    $rows = [
        (object) ['id' => '01ksaceqj4vqc15t6gnr51yd54', 'evidence' => '{"sku":"A"}'],
        (object) ['id' => '01ksacfrq4vqc15t6gnr51yd55', 'evidence' => '{"sku":"B"}'],
        (object) ['id' => '01ksacgbq4vqc15t6gnr51yd56', 'evidence' => '{"sku":"C"}'],
    ];

    $out = $this->command->indexSuggestionSkus($rows);

    // Bug guard: 3 distinct ULIDs → 3 entries, NOT 1.
    expect($out)->toHaveCount(3);
    expect($out)->toBe([
        '01ksaceqj4vqc15t6gnr51yd54' => 'A',
        '01ksacfrq4vqc15t6gnr51yd55' => 'B',
        '01ksacgbq4vqc15t6gnr51yd56' => 'C',
    ]);

    // Sanity: (int) casting those ids would collide them all onto key 1.
    expect((int) '01ksaceqj4vqc15t6gnr51yd54')->toBe(1);
    expect((int) '01ksacfrq4vqc15t6gnr51yd55')->toBe(1);
    expect((int) '01ksacgbq4vqc15t6gnr51yd56')->toBe(1);
});

it('skips rows with a blank evidence.sku', function (): void {
    $rows = [
        (object) ['id' => '01ksaceqj4vqc15t6gnr51yd54', 'evidence' => '{"sku":"A"}'],
        (object) ['id' => '01ksacfrq4vqc15t6gnr51yd55', 'evidence' => '{"sku":""}'],
    ];

    $out = $this->command->indexSuggestionSkus($rows);

    expect($out)->toHaveCount(1);
    expect($out)->toBe(['01ksaceqj4vqc15t6gnr51yd54' => 'A']);
});

it('handles evidence supplied as an already-decoded array', function (): void {
    $rows = [
        (object) ['id' => '01ksaceqj4vqc15t6gnr51yd54', 'evidence' => ['sku' => 'A']],
        (object) ['id' => '01ksacfrq4vqc15t6gnr51yd55', 'evidence' => '{"sku":"B"}'],
    ];

    $out = $this->command->indexSuggestionSkus($rows);

    expect($out)->toBe([
        '01ksaceqj4vqc15t6gnr51yd54' => 'A',
        '01ksacfrq4vqc15t6gnr51yd55' => 'B',
    ]);
});
