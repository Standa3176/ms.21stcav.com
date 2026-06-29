<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260629-pqh — DraftFromSuggestionsCommand::classifySkip()
|--------------------------------------------------------------------------
|
| products:draft-from-suggestions used to drop every non-candidate SKU with
| no explanation, ending the run with just "No matching SKUs to draft" — so
| operators saw "N SKU(s) queued" then nothing, with no idea why
| (not-sourceable SKUs like A75DM66D, sourceable-but-brand-not-on-Woo SKUs
| like S4.04-B-EB-GD5 / Trantec).
|
| classifySkip() is the pure decision helper behind the new per-SKU skip
| report. It is PURE — touches no database — so we construct the command
| through the container (constructor deps IntegrationCredentialResolver +
| TaxonomyResolver) and call the method directly.
|
| Buckets:
|   not_sourceable   — no supplier feed row at all (inFeed false)
|   no_manufacturer  — feed row exists but manufacturer blank
|   brand_not_on_woo — manufacturer present but not a Woo brand
|   null             — it's a valid candidate (not skipped)
*/

beforeEach(function (): void {
    $this->command = app(DraftFromSuggestionsCommand::class);
});

it('classifies not_sourceable when the SKU is not in the feed at all', function (): void {
    expect($this->command->classifySkip(false, false, false))->toBe('not_sourceable');
});

it('treats inFeed=false as dominant even if manufacturer/brand flags are true', function (): void {
    expect($this->command->classifySkip(false, true, true))->toBe('not_sourceable');
});

it('classifies no_manufacturer when in feed but manufacturer is blank', function (): void {
    expect($this->command->classifySkip(true, false, false))->toBe('no_manufacturer');
});

it('classifies brand_not_on_woo when manufacturer present but not a Woo brand', function (): void {
    expect($this->command->classifySkip(true, true, false))->toBe('brand_not_on_woo');
});

it('returns null (a valid candidate) when in feed, has manufacturer and brand resolved', function (): void {
    expect($this->command->classifySkip(true, true, true))->toBeNull();
});
