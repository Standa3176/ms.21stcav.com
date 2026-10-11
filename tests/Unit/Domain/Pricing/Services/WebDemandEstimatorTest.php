<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\WebDemandEstimator;

/*
|--------------------------------------------------------------------------
| Quick task 260927-p41 — the web-demand model
|--------------------------------------------------------------------------
|
| The shop has taken no orders for about a year, so units-sold does not exist as
| a ranking input. This model estimates weekly UK web demand from breadth and
| persistence (both MEASURED from competitor feeds), price (measured), and a
| per-product-class base rate (ASSUMED — config/ad_demand.php).
|
| These tests pin the SHAPE and the DIRECTION of the model, never a magic output
| number. The magnitudes are priors that an AV trader is expected to correct, so
| asserting "a 3-competitor £400 webcam is 14.6 units/week" would only pin my
| guess in place and fail the moment someone tunes the config — which is the
| thing the config exists to allow.
|
| What is worth pinning is that the model cannot be fooled in the ways that
| would matter: that demand falls as price rises, that an unclassifiable title
| lands mid-table rather than at either extreme, and that a title reading
| "wall mount for 55in display" is an accessory rather than a display.
*/

function estimator(): WebDemandEstimator
{
    return app(WebDemandEstimator::class);
}

it('falls as price rises, all else equal', function (): void {
    // The single most important property. Cash margin RISES with price, so
    // without this the shortlist is just "most expensive first" — which is the
    // ranking the breadth-only score already produced.
    $cheap = estimator()->estimate('Yealink UVC30 webcam', 15000, 3, 20, 84);
    $mid = estimator()->estimate('Yealink UVC30 webcam', 100000, 3, 20, 84);
    $dear = estimator()->estimate('Yealink UVC30 webcam', 900000, 3, 20, 84);

    expect($cheap['units_per_week'])->toBeGreaterThan($mid['units_per_week'])
        ->and($mid['units_per_week'])->toBeGreaterThan($dear['units_per_week']);
});

it('rises with competitor breadth, all else equal', function (): void {
    $narrow = estimator()->estimate('Some product', 30000, 1, 20, 84);
    $wide = estimator()->estimate('Some product', 30000, 5, 20, 84);

    expect($wide['units_per_week'])->toBeGreaterThan($narrow['units_per_week']);
});

it('rises with persistence, all else equal', function (): void {
    // Seen once in twelve weeks is usually clearance; seen on every feed day is
    // a stocked line.
    $once = estimator()->estimate('Some product', 30000, 3, 1, 84);
    $always = estimator()->estimate('Some product', 30000, 3, 24, 84);

    expect($always['units_per_week'])->toBeGreaterThan($once['units_per_week']);
});

it('never lets persistence reach zero — one sighting is weak evidence, not none', function (): void {
    $floor = round((float) config('ad_demand.persistence_floor'), 2);

    // Never seen at all sits exactly on the floor...
    $never = estimator()->estimate('Some product', 30000, 3, 0, 84);
    expect($never['persistence_factor'])->toBe($floor)
        ->and($never['units_per_week'])->toBeGreaterThan(0.0);

    // ...and a single sighting is a little ABOVE it, not equal to it. One feed
    // row is weak evidence, not absent evidence.
    $once = estimator()->estimate('Some product', 30000, 3, 1, 84);
    expect($once['persistence_factor'])->toBeGreaterThan($floor)
        ->and($once['persistence_factor'])->toBeLessThan(0.5);
});

it('caps persistence at 1.0 so a daily-publishing competitor cannot inflate a SKU', function (): void {
    // 84 sightings in an 84-day window is every single day — far more than the
    // ~24 expected. It must not scale past a fully-stocked line.
    $expected = estimator()->estimate('Some product', 30000, 3, 24, 84);
    $daily = estimator()->estimate('Some product', 30000, 3, 84, 84);

    expect($daily['units_per_week'])->toBe($expected['units_per_week'])
        ->and($daily['persistence_factor'])->toBe(1.0);
});

it('classifies a mount as an accessory even when the title mentions a display', function (): void {
    // The ordering trap in CLASS_KEYWORDS. "Wall mount for 55in display" is a
    // £40 bracket, not a £900 screen, and mis-classing it would put a display's
    // low base rate on a high-volume accessory.
    expect(estimator()->classify('B-Tech BT8221 Wall Mount for 55in Display'))->toBe('accessory');
});

it('classifies a video bar as a uc_bar, not an accessory or a display', function (): void {
    expect(estimator()->classify('Logitech Rally Bar Mini Video Bar'))->toBe('uc_bar')
        ->and(estimator()->classify('Poly Studio X50 Collaboration Bar'))->toBe('uc_bar');
});

it('separates installed AV from web-bought kit', function (): void {
    expect(estimator()->classify('Biamp TesiraFORTE DSP Processor'))->toBe('installed_av')
        ->and(estimator()->classify('Shure MXA910 Ceiling Array Microphone'))->toBe('installed_av');
});

it('returns unknown for a bare part number rather than guessing a class', function (): void {
    // Same discipline as the 260913-trd grounding floor: an unclassifiable title
    // must not be dressed up as something specific. 'unknown' carries a
    // mid-table base rate so the row is neither promoted nor silently dropped.
    expect(estimator()->classify('BT8421-PRO/B'))->toBe('unknown')
        ->and(estimator()->classify(''))->toBe('unknown');
});

it('puts unknown between the busiest and quietest classes', function (): void {
    $bases = (array) config('ad_demand.class_base_units_per_week');

    expect($bases['unknown'])->toBeLessThan($bases['accessory'])
        ->and($bases['unknown'])->toBeGreaterThan($bases['installed_av']);
});

it('reports a band, because the model cannot justify decimal precision', function (): void {
    // ASCII hyphens since 261011-lpg — see the terminal-mangling test below.
    expect(estimator()->band(0.1))->toBe('under 1/wk')
        ->and(estimator()->band(1.5))->toBe('1-2/wk')
        ->and(estimator()->band(4.0))->toBe('2-5/wk')
        ->and(estimator()->band(9.0))->toBe('5-15/wk')
        ->and(estimator()->band(40.0))->toBe('15+/wk');
});

it('multiplies units by TRUE margin for the weekly-profit ranking', function (): void {
    // The ranking number. 2.5 units/wk x £199 true cash margin = £497.50/wk.
    expect(estimator()->weeklyProfitPence(2.5, 19900))->toBe(49750);
});

it('ranks a mid-price high-volume line above a dear niche one on profit', function (): void {
    // The whole point of the change. Under the old breadth x margin score the
    // £9,000 item wins on margin alone; on expected weekly profit it should not.
    $bar = estimator()->estimate('Logitech Rally Bar Video Bar', 250000, 4, 20, 84);
    $wall = estimator()->estimate('Absen LED Video Wall Panel', 900000, 4, 20, 84);

    $barProfit = estimator()->weeklyProfitPence($bar['units_per_week'], 40000);
    $wallProfit = estimator()->weeklyProfitPence($wall['units_per_week'], 150000);

    expect($barProfit)->toBeGreaterThan($wallProfit);
});

it('exposes every factor so a row can be argued with', function (): void {
    // If an operator cannot see WHY a SKU ranked where it did, the estimate is
    // an oracle and they will either over-trust it or ignore it.
    $result = estimator()->estimate('Jabra Evolve2 65 Headset', 15000, 3, 20, 84);

    expect($result)->toHaveKeys([
        'units_per_week', 'band', 'class', 'class_base',
        'price_factor', 'price_band', 'breadth_factor', 'persistence_factor',
    ])->and($result['class'])->toBe('headset');
});

it('respects a retuned config without a code change', function (): void {
    $before = estimator()->estimate('Some cable', 5000, 3, 20, 84);

    config()->set('ad_demand.class_base_units_per_week.accessory', 1.0);

    $after = estimator()->estimate('Some cable', 5000, 3, 20, 84);

    expect($after['units_per_week'])->toBeLessThan($before['units_per_week']);
});

/*
|--------------------------------------------------------------------------
| 260927-p41 follow-up — classification measured against REAL SKUs
|--------------------------------------------------------------------------
|
| The generic noun list was written against invented examples ("Yealink UVC30
| webcam") and looked fine. Run against 13 live catalogue rows it returned
| `unknown` THIRTEEN TIMES — because real AV titles are brand + model code and
| contain none of the nouns it matches on. The model could not tell a £1,600
| wireless presentation dongle from a £5,900 interactive board, which is exactly
| the distinction it exists to make.
|
| Two fixes, both pinned below: a config-driven FAMILY map checked before the
| nouns, and the SKU added to the haystack (model codes live there — "LG 86in
| commercial" is unclassifiable, its SKU 86PK640S is not).
*/

it('classifies the real catalogue SKUs that all returned unknown', function (string $name, string $sku, string $expected): void {
    expect(estimator()->classify($name, $sku))->toBe($expected);
})->with([
    ['Yealink MeetingBoard Pro 86', 'MB86Pro-A02', 'display_large'],
    ['Yealink MeetingBoard Pro 65', 'MB65PRO-A02', 'display_large'],
    ['Yealink MVC S90', 'YEAMVCS90C5U', 'room_system'],
    ['Yealink MVC S40', 'MVC S40-C5U-000', 'room_system'],
    ['Yealink MCore kit', 'MCOREKIT-C5U-MS', 'room_system'],
    ['Barco ClickShare CX-50 Gen 2', 'R9861622EUB2', 'wireless_presentation'],
    ['Barco ClickShare CX-20 Gen 2', 'R9861612EUB1', 'wireless_presentation'],
    ['Barco ClickShare Bar Pro', 'R9861633EUB2', 'wireless_presentation'],
    ['Promethean AP10 86in bundle', 'AP10-A86-EU', 'display_large'],
    // The SKU-only case: nothing in the title is classifiable.
    ['LG 86in commercial', '86PK640S', 'display_large'],
]);

it('reads families from config so a new range needs no deploy', function (): void {
    expect(estimator()->classify('Acme Foo 9000', 'FOO-1'))->toBe('unknown');

    config()->set('ad_demand.family_keywords.uc_bar', ['foo 9000']);

    expect(estimator()->classify('Acme Foo 9000', 'FOO-1'))->toBe('uc_bar');
});

it('prefers a family over a generic noun', function (): void {
    // A ClickShare Bar Pro contains 'bar'. Without family-first it would take the
    // uc_bar rate; it is a wireless presentation device and sells like one.
    expect(estimator()->classify('Barco ClickShare Bar Pro', 'R9861633EUB2'))
        ->toBe('wireless_presentation');
});

it('still falls through to unknown for a bare part number', function (): void {
    // The families must not become a catch-all. An unmatched title is still
    // reported as unknown, which is the signal that the map needs extending.
    expect(estimator()->classify('BT8421-PRO/B', 'BT8421/B'))->toBe('unknown');
});

it('rates a self-serve device above a project bundle at the same price', function (): void {
    // A ClickShare is unboxed and plugged in; an MVC bundle needs a room. Same
    // price band, different web demand — the thing 13-of-13 'unknown' erased.
    $clickshare = estimator()->estimate('Barco ClickShare CX-50 Gen 2', 283750, 3, 20, 84, 'R9861622EUB2');
    $roomSystem = estimator()->estimate('Yealink MVC S50', 366667, 3, 20, 84, 'MVC S50-C5U-000');

    expect($clickshare['units_per_week'])->toBeGreaterThan($roomSystem['units_per_week']);
});

/*
|--------------------------------------------------------------------------
| 261011-lpg — three defects the FIRST LIVE SHORTLIST exposed
|--------------------------------------------------------------------------
|
| 193 rows came back and the top of the ranking was wrong:
|
|   #1  Lenovo ThinkSmart Hub, est £3,150/wk — because 'hub' was an accessory
|       keyword, so a ~£1,900 room console took the 35/week base rate meant for
|       USB hubs. One over-broad token decided the top of the list.
|   #38 Samsung 85in and #43 LG 98in came back as `display_small`, because
|       display_large matched only words like "interactive" and "signage". A
|       98in panel took the 10/week desktop-monitor rate.
|   #56 "Neat Bar Generation 2" was `unknown`.
|
| The size rule is the structural one: the diagonal is in the title, so large
| vs small is now MEASURED rather than keyword-guessed.
*/

it('does not let a USB-hub keyword swallow a room console', function (): void {
    expect(estimator()->classify('Lenovo ThinkSmart Hub Intel Core', '11H1000KUK'))->toBe('room_system')
        ->and(estimator()->classify('Yealink conference camera AVHub', 'AVHub'))->toBe('uc_bar')
        // and a real USB hub is still an accessory
        ->and(estimator()->classify('StarTech USB-C Hub 4 port', 'HUB-4P'))->toBe('accessory');
});

it('classifies a display by its DIAGONAL, not by whether the title says "signage"', function (string $name, string $expected): void {
    expect(estimator()->classify($name, 'SKU-X'))->toBe($expected);
})->with([
    ['Samsung 85" QM85C Display', 'display_large'],
    ['LG 98" 98UH5J-H Display', 'display_large'],
    ['iiyama LH7575UHS-B2AG 75" ProLite Display', 'display_large'],
    ['Sony FW-65EZ20L 65" 4K Ultra HD Commercial Display', 'display_large'],
    // Below the threshold these stay small — the rule has to cut both ways.
    ['Sony FW-32BZ30J1 32" BRAVIA 4K Display', 'display_small'],
    ['ViewSonic CDE4330 43" Display', 'display_small'],
    ['Samsung LH46OHDEBGBXEN OH46DX 46" Display', 'display_small'],
]);

it('reads the diagonal from the title only, never the SKU', function (): void {
    // "98UH5J-H" and "LH46OHDEBGBXEN" are full of digits that are not inches.
    // A title with no stated size keeps whatever the keywords decided.
    // Deliberately NOT "commercial display" — that is a family keyword meaning
    // large in its own right, which would mask what this test is checking.
    expect(estimator()->classify('LG Display', '98UH5J-H'))->toBe('display_small');
});

it('leaves a monitor ARM an accessory however many inches it mentions', function (): void {
    // The size rule must only apply once a title is already a display. A 27in
    // mount is a £40 bracket.
    expect(estimator()->classify('Ergotron 27" CareFit Combo System Stand', '45-618-251'))->toBe('accessory');
});

it('takes the largest stated diagonal when a title mentions several', function (): void {
    expect(estimator()->classify('Yealink 75" display with 10" touch panel', 'X'))->toBe('display_large');
});

it('honours a retuned size threshold from config', function (): void {
    config()->set('ad_demand.display_large_min_inches', 90);

    expect(estimator()->classify('Samsung 85" QM85C Display', 'X'))->toBe('display_small')
        ->and(estimator()->classify('LG 98" Display', 'X'))->toBe('display_large');
});

it('prints demand bands in ASCII so a terminal cannot mangle them', function (): void {
    // The first live run rendered "2-5/wk" as "25/wk" and "5-15/wk" as "515/wk"
    // because the labels used en dashes and the operator terminal dropped them.
    // A demand band displayed an order of magnitude out, in the column the whole
    // ranking is read from.
    foreach ((array) config('ad_demand.bands') as $band) {
        expect($band['label'])->toMatch('/^[\x20-\x7E]+$/');
    }
});

it('enforces the ad-viability cash floor instead of only documenting it', function (): void {
    // config/ad_demand.php described this as the backstop for a lowered margin
    // floor and nothing read it, so --min-margin-pence=4000 shortlisted £42-£46
    // rows: less cash than one Shopping acquisition costs.
    $source = file_get_contents(base_path('app/Console/Commands/ShoppingCandidatesCommand.php'));

    expect(config('ad_demand.min_margin_pence'))->toBeGreaterThan(0)
        ->and($source)->toContain("config('ad_demand.min_margin_pence'")
        // whichever bar is higher wins — an operator may raise it, not lower it
        ->and($source)->toContain('if ($viabilityFloor > $minMarginPence)');
});
