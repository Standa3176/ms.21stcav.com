<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260628-b9t — DraftFromSuggestionsCommand::resolveBrandKey()
|--------------------------------------------------------------------------
|
| products:draft-from-suggestions used to require the supplier feed's
| manufacturer to EXACTLY match (case-insensitive, trimmed) a Woo brand
| term. Feed manufacturers are frequently "Brand - Category" shaped
| (e.g. "Yealink - Headset"), which never equals the clean "Yealink"
| brand term — so those SKUs were silently dropped ("No matching SKUs to
| draft", nothing created, no error).
|
| resolveBrandKey() adds a normalisation fallback: exact match first
| (preserves all current behaviour), then strip a trailing " - <suffix>"
| segment and retry. It is PURE — touches no database — so we construct
| the command through the container (it has constructor deps
| IntegrationCredentialResolver + TaxonomyResolver) and call the method
| directly with a fixed Woo brand map.
*/

/** Lowercased-name => canonical-name, mirroring the chunk-processor's $wooBrandsByLower. */
function wooBrandMap(): array
{
    return ['yealink' => 'Yealink', 'lenovo' => 'Lenovo', 'lindy' => 'Lindy'];
}

beforeEach(function (): void {
    $this->command = app(DraftFromSuggestionsCommand::class);
});

it('resolves an exact lowercase match to the same key (current behaviour)', function (): void {
    expect($this->command->resolveBrandKey('yealink', wooBrandMap()))->toBe('yealink');
});

it('resolves lenovo and lindy exact matches', function (): void {
    expect($this->command->resolveBrandKey('lenovo', wooBrandMap()))->toBe('lenovo');
    expect($this->command->resolveBrandKey('lindy', wooBrandMap()))->toBe('lindy');
});

it('strips a trailing " - <suffix>" and resolves the base brand (THE bug case)', function (): void {
    expect($this->command->resolveBrandKey('yealink - headset', wooBrandMap()))->toBe('yealink');
});

it('strips at the FIRST " - " when multiple suffix segments exist', function (): void {
    expect($this->command->resolveBrandKey('yealink - headset - uk', wooBrandMap()))->toBe('yealink');
});

it('defensively trims an untrimmed input before matching', function (): void {
    expect($this->command->resolveBrandKey('  lenovo  ', wooBrandMap()))->toBe('lenovo');
});

it('returns null when the stripped lead is still not a known Woo brand', function (): void {
    expect($this->command->resolveBrandKey('acme - widgets', wooBrandMap()))->toBeNull();
});

it('returns null for a hyphen with no surrounding spaces (only " - " is split)', function (): void {
    expect($this->command->resolveBrandKey('totally-unknown', wooBrandMap()))->toBeNull();
});

it('returns null for an empty string', function (): void {
    expect($this->command->resolveBrandKey('', wooBrandMap()))->toBeNull();
});
