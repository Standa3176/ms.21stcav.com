<?php

declare(strict_types=1);

use App\Console\Commands\RefreshBrandsToAddCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260702-h50 — RefreshBrandsToAddCommand::buildBrandsToAddIndex()
|--------------------------------------------------------------------------
|
| The brand of a new_product_opportunity is NOT stored on the suggestion — it
| comes from the supplier feed manufacturer. buildBrandsToAddIndex() is the
| PURE core of products:refresh-brands-to-add: given lower(sku) => [mfrs] and
| the Woo brand map, it classifies each SKU and aggregates a "brands to add"
| summary. No DB / mysqli — the remote walk in perform() is the thin, untested
| shell (it mirrors DraftFromSuggestionsCommand's mysqli walk).
|
| Rules per SKU:
|   - manufacturers empty / none in map => sourceable=false, brand=null,
|     on_woo=false, NOT in to_add.
|   - a manufacturer resolves to a Woo brand => on_woo=true, brand=canonical
|     Woo name, sourceable=true (NOT in to_add — already on Woo).
|   - has manufacturer(s) but none resolve => on_woo=false, sourceable=true,
|     brand=FIRST manufacturer (the thing to add) => in to_add (count++, push sku).
*/

beforeEach(function (): void {
    $this->command = app(RefreshBrandsToAddCommand::class);
});

it('classifies on-woo / to-add / not-sourceable SKUs and aggregates the to-add summary', function (): void {
    $woo = ['yealink' => 'Yealink', 'lindy' => 'Lindy'];
    $skuToMfr = [
        'bh71' => ['Yealink'],                 // on woo
        'ab12' => ['Trantec'],                 // to add
        'cd34' => ['Trantec'],                 // to add (same brand -> count 2)
        'ef56' => ['Protect Plus', 'Yealink'], // multi -> resolves Yealink (on woo)
        'gh78' => [],                          // not sourceable
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, $woo);

    // ── per_sku ──────────────────────────────────────────────────────────
    expect($index['per_sku']['bh71'])->toBe(['brand' => 'Yealink', 'on_woo' => true, 'sourceable' => true]);
    expect($index['per_sku']['ab12'])->toBe(['brand' => 'Trantec', 'on_woo' => false, 'sourceable' => true]);
    expect($index['per_sku']['cd34'])->toBe(['brand' => 'Trantec', 'on_woo' => false, 'sourceable' => true]);
    expect($index['per_sku']['ef56'])->toBe(['brand' => 'Yealink', 'on_woo' => true, 'sourceable' => true]);
    expect($index['per_sku']['gh78'])->toBe(['brand' => null, 'on_woo' => false, 'sourceable' => false]);

    // ── to_add ───────────────────────────────────────────────────────────
    // Only the unresolved-but-sourceable bucket: Trantec (2 SKUs). No Yealink
    // (already on Woo), no not-sourceable entry.
    expect($index['to_add'])->toHaveKey('Trantec');
    expect($index['to_add']['Trantec']['count'])->toBe(2);
    expect($index['to_add']['Trantec']['skus'])->toBe(['ab12', 'cd34']);
    expect($index['to_add'])->not->toHaveKey('Yealink');
    expect(array_keys($index['to_add']))->toBe(['Trantec']);
});

/*
|--------------------------------------------------------------------------
| Quick task 260702-om7 — normalise + case-collapse + junk-exclude
|--------------------------------------------------------------------------
| The raw h50/hg1 to-add list re-pollutes the taxonomy on one-click-create:
| case-variant duplicates ('BROTHER'+'Brother'), HTML entities ('VOGEL&#039;S'),
| junk ('SPECIALS'). buildBrandsToAddIndex now HTML-decodes + trims + collapses
| whitespace on manufacturer names, groups the to-add bucket case-insensitively
| into ONE canonical (mixed-case preferred, acronyms preserved, never
| title-cased), and drops junk (config brands_to_add_exclude).
*/

it('collapses case-variant to-add brands into one mixed-case canonical with summed count', function (): void {
    $skuToMfr = [
        'a' => ['BROTHER'],
        'b' => ['Brother'],
        'c' => ['Brother'],
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, []);

    // One row, mixed-case 'Brother' preferred over all-caps 'BROTHER', count 3.
    expect(array_keys($index['to_add']))->toBe(['Brother']);
    expect($index['to_add']['Brother']['count'])->toBe(3);
    expect($index['to_add']['Brother']['skus'])->toBe(['a', 'b', 'c']);

    // per_sku brand === the SAME canonical for every case variant.
    expect($index['per_sku']['a']['brand'])->toBe('Brother');
    expect($index['per_sku']['b']['brand'])->toBe('Brother');
    expect($index['per_sku']['c']['brand'])->toBe('Brother');
});

it('preserves all-caps acronym brands (never title-cases APC)', function (): void {
    $skuToMfr = [
        'd' => ['APC'],
        'e' => ['APC'],
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, []);

    expect(array_keys($index['to_add']))->toBe(['APC']);
    expect($index['to_add']['APC']['count'])->toBe(2);
    expect($index['per_sku']['d']['brand'])->toBe('APC');
});

it('html-decodes a manufacturer so it matches an existing Woo brand', function (): void {
    $woo = ['yealink' => 'Yealink', "vogel's" => "Vogel's"];
    $skuToMfr = [
        'f' => ['VOGEL&#039;S'], // decodes to VOGEL'S -> matches Woo "Vogel's"
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, $woo);

    expect($index['per_sku']['f'])->toBe(['brand' => "Vogel's", 'on_woo' => true, 'sourceable' => true]);
    expect($index['to_add'])->toBe([]);
});

it('html-decodes a manufacturer that is not on Woo into the decoded to-add name', function (): void {
    $woo = ['yealink' => 'Yealink']; // no Vogel's on Woo
    $skuToMfr = [
        'g' => ['VOGEL&#039;S'],
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, $woo);

    expect(array_keys($index['to_add']))->toBe(["VOGEL'S"]);
    expect($index['to_add']["VOGEL'S"]['count'])->toBe(1);
    expect($index['per_sku']['g']['brand'])->toBe("VOGEL'S");
});

it('excludes junk brands from the to-add summary and from per_sku brand', function (): void {
    $skuToMfr = [
        'h' => ['SPECIALS'],
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, []);

    // Junk => sourceable but not a creatable brand; NOT in to_add.
    expect($index['per_sku']['h'])->toBe(['brand' => null, 'on_woo' => false, 'sourceable' => true]);
    expect($index['to_add'])->toBe([]);
});

it('leaves on-woo / not-sourceable / multi-mfr classification unchanged after normalisation', function (): void {
    $woo = ['yealink' => 'Yealink'];
    $skuToMfr = [
        'i' => ['Yealink'],                 // on woo (unchanged)
        'j' => [],                          // not sourceable (unchanged)
        'k' => ['Protect Plus', 'Yealink'], // multi-mfr preference (unchanged)
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, $woo);

    expect($index['per_sku']['i'])->toBe(['brand' => 'Yealink', 'on_woo' => true, 'sourceable' => true]);
    expect($index['per_sku']['j'])->toBe(['brand' => null, 'on_woo' => false, 'sourceable' => false]);
    expect($index['per_sku']['k'])->toBe(['brand' => 'Yealink', 'on_woo' => true, 'sourceable' => true]);
    expect($index['to_add'])->toBe([]);
});

it('caps the sample skus per to-add brand at 25', function (): void {
    $skuToMfr = [];
    for ($i = 0; $i < 40; $i++) {
        $skuToMfr['sku'.$i] = ['Trantec'];
    }

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, []);

    expect($index['to_add']['Trantec']['count'])->toBe(40);
    expect($index['to_add']['Trantec']['skus'])->toHaveCount(25);
});

it('returns empty to_add when every SKU is already on Woo or not sourceable', function (): void {
    $woo = ['yealink' => 'Yealink'];
    $skuToMfr = [
        'a1' => ['Yealink'],
        'b2' => [],
    ];

    $index = $this->command->buildBrandsToAddIndex($skuToMfr, $woo);

    expect($index['to_add'])->toBe([]);
    expect($index['per_sku']['a1'])->toBe(['brand' => 'Yealink', 'on_woo' => true, 'sourceable' => true]);
    expect($index['per_sku']['b2'])->toBe(['brand' => null, 'on_woo' => false, 'sourceable' => false]);
});
