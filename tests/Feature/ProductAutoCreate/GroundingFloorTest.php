<?php

declare(strict_types=1);

use App\Console\Commands\GenerateProductDraftsCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260913-trd — the grounding floor
|--------------------------------------------------------------------------
|
| A 25-SKU B-Tech pilot on 2026-09-13 ran with no supplier detail column, so
| the model was grounded on "title + identifiers only". Where the title carried
| real words it wrote good copy. Where the title was just a part number it did
| not decline — it filled the shape with plausible nothing, and invented the
| product TYPE to do it:
|
|   BT8421-PRO/B   -> "Pro Monitor Arm Accessory ... designed to complement
|                      compatible B-Tech mounting solutions"
|   BT5442         -> the identical sentence
|   BT735 - Black  -> "Universal Flat-to-Wall TV Mount"    <- invented outright
|
| That is worse than no listing: it cannot convert, the category is a guess,
| and thin duplicated copy drags site-wide quality down.
|
| Every case below is a REAL title from that pilot run.
*/

/** The helper is private; exercise it the way the command does. */
function isDescriptive(string $title, string $sku = '', string $mpn = ''): bool
{
    $method = new ReflectionMethod(GenerateProductDraftsCommand::class, 'titleIsDescriptive');

    return (bool) $method->invoke(app(GenerateProductDraftsCommand::class), $title, $sku, $mpn);
}

it('passes titles that actually describe the product', function (string $title, string $sku): void {
    expect(isDescriptive($title, $sku))->toBeTrue();
})->with([
    ['BT4002/B V2 Large Floor Base (890 x 610mm) for BT8381 Columns', 'BT4002/B V2'],
    ['BT4118/B 50mm Pole for Floor Stands - 1.8m', 'BT4118/B'],
    ['BT7056/C Floor Fixing Kit for 50mm Poles', 'BT7056/C'],
    ['BT5431/B Elements Tilt 400 Flat Screen Wall Mount (VESA 400)', 'BT5431/B'],
    ['BT8708/B Premium Bolt-Down Single Screen Twin Column UC Stand', 'BT8708/B'],
]);

it('rejects titles that are only a part number', function (string $title, string $sku): void {
    expect(isDescriptive($title, $sku))->toBeFalse();
})->with([
    ['BT8421-PRO/B', 'BT8421/B'],
    ['BT5442', 'BT5442/B'],
    ['BTEBT7353 - Black', 'BT7353/B'],
    ['BT735 - Black', 'BT7351/B'],
    ['', 'BT9999/B'],
]);

it('treats a colour alone as not descriptive', function (): void {
    // "Black" tells a buyer nothing about what the thing IS.
    expect(isDescriptive('BT1234 - Black', 'BT1234'))->toBeFalse()
        ->and(isDescriptive('BT1234 Silver', 'BT1234'))->toBeFalse();
});

it('strips the MPN as well as the SKU', function (): void {
    // The supplier title often repeats the manufacturer code in a different
    // shape; both must be removed before judging what is left.
    expect(isDescriptive('BTEBT7353', 'BT7353/B', 'BTEBT7353'))->toBeFalse();
});

it('exposes an --allow-thin escape hatch', function (): void {
    // The floor is a default, not a prohibition — an operator who knows the
    // product can still force generation.
    $source = file_get_contents(base_path('app/Console/Commands/GenerateProductDraftsCommand.php'));

    expect($source)->toContain('--allow-thin')
        ->and($source)->toContain('$allowThin = (bool) $this->option(\'allow-thin\')')
        // And the skip must be reported, not silent.
        ->and($source)->toContain('skipped by the grounding floor');
});

it('only applies the floor when there is NOTHING to ground on', function (): void {
    // Real grounding makes the title irrelevant. There are now two sources:
    //
    //   $details   — a supplier description column. supplier_products has none
    //                and never will (14 columns, all identifiers), so this is
    //                always [] in practice — but the check stays for any future
    //                supplier that does carry prose.
    //   $grounded  — 260914-j0x: Icecat returned a description or spec table.
    //                Measured 70% coverage, so this is the one that actually
    //                fires. A bare part number is fine to generate from when
    //                Icecat can describe the product.
    $source = file_get_contents(base_path('app/Console/Commands/GenerateProductDraftsCommand.php'));

    expect($source)->toContain('! $allowThin && ! $grounded && $details === [] && ! $this->titleIsDescriptive');
});
