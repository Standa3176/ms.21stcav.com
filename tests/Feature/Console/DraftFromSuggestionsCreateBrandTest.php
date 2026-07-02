<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;
use App\Domain\ProductAutoCreate\Jobs\RunAutoCreatePipelineJob;
use App\Domain\ProductAutoCreate\Services\WooBrandCreator;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260702-qd8 — draft-from-suggestions --create-missing-brands
|--------------------------------------------------------------------------
| products:draft-from-suggestions used to SKIP brand_not_on_woo SKUs (no
| product). With --create-missing-brands the command find-or-creates the Woo
| brand term (normalised + junk-guarded via WooBrandCreator) and PROMOTES the
| SKU to a candidate; without the flag it still skips (byte-identical).
|
| The command's supplier walk uses a live mysqli connection that cannot be
| faked in-process, so the promotion decision is covered via the pure,
| injectable promoteMissingBrand() helper (mockable WooBrandCreator), plus the
| RunAutoCreatePipelineJob arg wiring (config on → flag passed; off → omitted).
*/

/** Build the command through the container with a bound fake WooBrandCreator. */
function draftCommandWithCreator(WooBrandCreator $creator): DraftFromSuggestionsCommand
{
    app()->instance(WooBrandCreator::class, $creator);

    return app(DraftFromSuggestionsCommand::class);
}

it('promotes a brand_not_on_woo SKU to a candidate when the flag is set and the brand is real', function (): void {
    $creator = Mockery::mock(WooBrandCreator::class);
    $creator->shouldReceive('ensureBrandTermId')->once()->with('Trantec')->andReturn(555);
    $creator->shouldReceive('normaliseBrandName')->with('Trantec')->andReturn('Trantec');

    $command = draftCommandWithCreator($creator);

    expect($command->promoteMissingBrand(['Trantec'], true))->toBe('Trantec');
});

it('does NOT promote a junk brand (creator returns null) — SKU stays skipped', function (): void {
    $creator = Mockery::mock(WooBrandCreator::class);
    $creator->shouldReceive('ensureBrandTermId')->once()->with('Specials')->andReturn(null);
    $creator->shouldNotReceive('normaliseBrandName');

    $command = draftCommandWithCreator($creator);

    expect($command->promoteMissingBrand(['Specials'], true))->toBeNull();
});

it('does NOT promote (and never consults the creator) when the flag is off', function (): void {
    $creator = Mockery::mock(WooBrandCreator::class);
    $creator->shouldNotReceive('ensureBrandTermId');

    $command = draftCommandWithCreator($creator);

    expect($command->promoteMissingBrand(['Trantec'], false))->toBeNull();
});

it('RunAutoCreatePipelineJob passes --create-missing-brands when the config switch is ON', function (): void {
    config(['product_auto_create.auto_create_missing_brands' => true]);

    $captured = null;
    Artisan::shouldReceive('call')->once()
        ->withArgs(function (string $cmd, array $args) use (&$captured): bool {
            $captured = $args;

            return $cmd === 'products:draft-from-suggestions';
        })
        ->andReturn(0);

    (new RunAutoCreatePipelineJob(['SKU-1'], sourceImages: false, autoPublish: false, triggeredByUserId: 0))->handle();

    expect($captured)->toHaveKey('--create-missing-brands');
    expect($captured['--create-missing-brands'])->toBeTrue();
});

it('RunAutoCreatePipelineJob OMITS --create-missing-brands when the config switch is OFF', function (): void {
    config(['product_auto_create.auto_create_missing_brands' => false]);

    $captured = null;
    Artisan::shouldReceive('call')->once()
        ->withArgs(function (string $cmd, array $args) use (&$captured): bool {
            $captured = $args;

            return $cmd === 'products:draft-from-suggestions';
        })
        ->andReturn(0);

    (new RunAutoCreatePipelineJob(['SKU-1'], sourceImages: false, autoPublish: false, triggeredByUserId: 0))->handle();

    expect($captured)->not->toHaveKey('--create-missing-brands');
});
