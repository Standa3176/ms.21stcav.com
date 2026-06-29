<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260629-rct — DraftFromSuggestionsCommand::firstResolvableBrandKey()
|--------------------------------------------------------------------------
|
| A SKU can match MULTIPLE supplier feed rows under the same MPN with
| different manufacturers — e.g. HD226:
|   - BSHD226   | mfr=BrightSign   | stk=118  (the real product; BrightSign IS a Woo brand)
|   - MSBSHD226 | mfr=Protect Plus | stk=0    (a warranty/protection plan; not a brand)
|
| The old $supMap kept the LAST-fetched manufacturer per key, so HD226 ended
| up mapped to "Protect Plus" (not a Woo brand) and got wrongly skipped as
| "brand not on Woo", even though it's a creatable, in-stock BrightSign product.
|
| firstResolvableBrandKey() takes the SKU's full manufacturer list and returns
| the FIRST one that resolves to a Woo brand (via resolveBrandKey from
| 260628-b9t), as [brandKey, matchedManufacturer]; [null, null] if none resolve.
|
| It is PURE — touches no database — so we construct the command through the
| container (constructor deps IntegrationCredentialResolver + TaxonomyResolver)
| and call the method directly with a fixed Woo brand map.
*/

/** Lowercased-name => canonical-name, mirroring the chunk-processor's $wooBrandsByLower. */
function wooBrandMapMultiMfr(): array
{
    return ['brightsign' => 'BrightSign', 'lindy' => 'Lindy'];
}

beforeEach(function (): void {
    $this->command = app(DraftFromSuggestionsCommand::class);
});

it('resolves a single resolvable manufacturer', function (): void {
    expect($this->command->firstResolvableBrandKey(['BrightSign'], wooBrandMapMultiMfr()))
        ->toBe(['brightsign', 'BrightSign']);
});

it('picks the brand-resolving manufacturer over a non-brand add-on (THE HD226 case)', function (): void {
    expect($this->command->firstResolvableBrandKey(['Protect Plus', 'BrightSign'], wooBrandMapMultiMfr()))
        ->toBe(['brightsign', 'BrightSign']);
});

it('is order-independent: first resolvable wins', function (): void {
    expect($this->command->firstResolvableBrandKey(['BrightSign', 'Protect Plus'], wooBrandMapMultiMfr()))
        ->toBe(['brightsign', 'BrightSign']);
});

it('returns [null, null] when no manufacturer resolves', function (): void {
    expect($this->command->firstResolvableBrandKey(['Protect Plus'], wooBrandMapMultiMfr()))
        ->toBe([null, null]);
});

it('still applies the resolveBrandKey suffix-strip (Brand - Category)', function (): void {
    expect($this->command->firstResolvableBrandKey(['Lindy - Cable'], wooBrandMapMultiMfr()))
        ->toBe(['lindy', 'Lindy - Cable']);
});

it('returns [null, null] for an empty manufacturer list', function (): void {
    expect($this->command->firstResolvableBrandKey([], wooBrandMapMultiMfr()))
        ->toBe([null, null]);
});
