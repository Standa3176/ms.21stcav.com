<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web-demand model for ad shortlisting (quick task 260927-p41)
|--------------------------------------------------------------------------
|
| READ THIS BEFORE TRUSTING A NUMBER THAT COMES OUT OF IT.
|
| meetingstore.co.uk has not taken orders for about a year, so there is no
| own-site demand signal at all: `last_sales_count_90d` is a year stale at best
| and zero at worst, and GA4 has nothing to say about a dark shop. The usual
| ranking input — units sold — does not exist.
|
| So this model estimates weekly UK web demand from what we DO hold, and it is
| important to be exact about which parts are measured and which are assumed:
|
|   MEASURED (from competitor_prices, our own feeds)
|     · breadth      — how many of the tracked resellers currently list the SKU
|     · persistence   — on how many distinct days it appeared in the window
|     · price          — our own net sell price
|
|   ASSUMED (the tables below — MY priors, not observations)
|     · class_base_units_per_week — how many units a product of this KIND sells
|       per week across the whole UK web market
|     · price_band_factors — how much web demand falls away as price rises
|     · breadth_factors / persistence floor — how to weight the measured signals
|
| The class base rates are the single largest source of error here and they are
| the one thing an AV trader knows better than this codebase does. They are
| config precisely so they can be corrected without a deploy. Change them and
| the shortlist re-ranks.
|
| WHAT WOULD MAKE THIS REAL: Google Keyword Planner search volume (free, UK
| location) against the exported SKU/name list. That is an actual demand
| measurement for the top of the funnel. Until it is loaded, treat every
| `est_units_per_week` below as an ORDER OF MAGNITUDE for ranking, not a
| forecast — which is why the command reports a band alongside the number.
*/

return [
    // ── Measured-signal windows ──────────────────────────────────────────
    // 84 days = 12 weeks. Long enough that a fortnightly supplier feed still
    // registers persistence, short enough to describe the CURRENT market.
    'window_days' => (int) env('AD_DEMAND_WINDOW_DAYS', 84),

    // Feeds land roughly twice a week (the FTP pull runs Sun + Wed), so a SKU
    // continuously stocked shows up on about this many distinct days in the
    // window. Used as the denominator for persistence — NOT as a hard gate,
    // because a competitor who publishes daily would otherwise dominate.
    'expected_sightings_per_week' => (float) env('AD_DEMAND_EXPECTED_SIGHTINGS_PER_WEEK', 2.0),

    // ── PRODUCT FAMILIES — checked BEFORE the generic nouns ──────────────
    //
    // WHY THIS EXISTS: the generic keyword list below matches nouns ("webcam",
    // "headset", "cable"). Real AV catalogue titles are brand + model code, and
    // carry no such noun. Measured against 13 live SKUs on 2026-09-27:
    //
    //   Yealink MeetingBoard Pro 86 -> unknown
    //   Yealink MVC S90             -> unknown
    //   Barco ClickShare CX-50      -> unknown
    //   Promethean AP10 86in        -> unknown
    //   ... 13 of 13 unknown
    //
    // So every one of them collapsed onto the `unknown` base rate and the model
    // could not tell a wireless presentation dongle from an 86in board. Family
    // tokens fix that, and they live in config because new ranges appear
    // constantly and a trader should not need a deploy to add one.
    //
    // THIS LIST IS INCOMPLETE BY CONSTRUCTION. It covers the families seen so
    // far. Anything unmatched still falls through to the nouns and then to
    // `unknown` — which is why the command reports the class per row: a
    // shortlist full of `unknown` means this map needs extending, not that the
    // products are unclassifiable.
    'family_keywords' => [
        // 261011 — FIRST, deliberately. Families are tested before the generic
        // nouns, so "Yealink Floorstand for 75in MeetingBoard" matched the
        // 'meetingboard' family and became a display_large — and the size rule
        // then read the 75in of the screen it HOLDS. A stand is an accessory
        // whatever it is bolted to, and the price rule reclassifies the dear
        // ones as accessory_major from there.
        'accessory' => ['floorstand', 'floor stand', 'wall stand', 'mb-fs'],
        // Self-contained IT purchases: unboxed, plugged in, no installer. These
        // genuinely do sell through a web basket.
        'wireless_presentation' => ['clickshare', 'wireless presentation', 'airtame', 'via connect', 'via go'],
        // Teams/Zoom Rooms bundles — a compute module, a bar and a panel sold as
        // a project. Researched online, bought through a quote.
        // 261011-lpg — 'mtr ' removed: it matched "Yealink mtr interactive
        // display" (an MB75 MeetingBoard) and split one product family across two
        // classes, because our catalogue names the 65 and the 75 differently.
        // 'thinksmart' added for the Lenovo room consoles.
        'room_system' => ['mvc', 'mcore', 'teams rooms', 'zoom rooms', 'rooms kit', 'room bundle', 'thinksmart'],
        // Interactive boards and panels — specified, delivered, wall-mounted.
        'display_large' => [
            'meetingboard', 'activpanel', 'ap10', 'ap7', 'ap6',
            'interactive flat panel', 'ifp', 'commercial display', 'pk640',
        ],
    ],

    // ── ASSUMED: units/week across the UK web market, by product kind ─────
    // Order-of-magnitude priors. A cable sells in tens per week nationally; an
    // installed DSP sells in ones, and mostly through integrators rather than a
    // web basket. `unknown` sits deliberately mid-table so an unclassifiable
    // product is neither promoted nor silently dropped.
    'class_base_units_per_week' => [
        'accessory' => (float) env('AD_DEMAND_BASE_ACCESSORY', 35),
        // Stands, trolleys and mounts above the threshold above. Specified
        // alongside a room, not dropped in a basket.
        'accessory_major' => (float) env('AD_DEMAND_BASE_ACCESSORY_MAJOR', 4),
        'headset' => (float) env('AD_DEMAND_BASE_HEADSET', 30),
        'webcam' => (float) env('AD_DEMAND_BASE_WEBCAM', 22),
        'speakerphone' => (float) env('AD_DEMAND_BASE_SPEAKERPHONE', 16),
        'uc_bar' => (float) env('AD_DEMAND_BASE_UC_BAR', 12),
        // A ClickShare is bought the way a webcam is — no installer, no survey —
        // so it earns a real web rate despite a four-figure price. The price band
        // still discounts it heavily; this stops it being discounted twice.
        'wireless_presentation' => (float) env('AD_DEMAND_BASE_WIRELESS_PRESENTATION', 9),
        // A Rooms bundle is researched online and bought through a quote. Some
        // web demand, well below a single device.
        'room_system' => (float) env('AD_DEMAND_BASE_ROOM_SYSTEM', 4),
        'display_small' => (float) env('AD_DEMAND_BASE_DISPLAY_SMALL', 10),
        'networking' => (float) env('AD_DEMAND_BASE_NETWORKING', 8),
        'unknown' => (float) env('AD_DEMAND_BASE_UNKNOWN', 6),
        'display_large' => (float) env('AD_DEMAND_BASE_DISPLAY_LARGE', 4),
        'projector' => (float) env('AD_DEMAND_BASE_PROJECTOR', 4),
        'licence' => (float) env('AD_DEMAND_BASE_LICENCE', 3),
        'installed_av' => (float) env('AD_DEMAND_BASE_INSTALLED_AV', 2),
    ],

    // ── ASSUMED: how self-serve web demand decays with price ──────────────
    // Thresholds are NET (ex-VAT) pence, to match the margin basis. They
    // correspond to roughly £150 / £500 / £1,500 / £4,000 / £10,000 gross.
    // A £60 webcam is an impulse basket; an £8,000 LED wall is a quotation with
    // a site survey, and no amount of ad spend turns it into a checkout.
    'price_band_factors' => [
        ['max_net_pence' => 12500, 'factor' => 1.5, 'label' => 'under £150'],
        ['max_net_pence' => 41500, 'factor' => 1.0, 'label' => '£150–£500'],
        ['max_net_pence' => 125000, 'factor' => 0.5, 'label' => '£500–£1.5k'],
        ['max_net_pence' => 333000, 'factor' => 0.18, 'label' => '£1.5k–£4k'],
        ['max_net_pence' => 833000, 'factor' => 0.06, 'label' => '£4k–£10k'],
        ['max_net_pence' => PHP_INT_MAX, 'factor' => 0.02, 'label' => 'over £10k'],
    ],

    // ── ASSUMED: weighting of the MEASURED breadth signal ────────────────
    // Resellers stock what sells, so the number of them carrying a SKU is the
    // best proven-demand proxy we hold. It is a weighting, not a base: one
    // competitor halves the estimate rather than zeroing it.
    'breadth_factors' => [
        1 => 0.5,
        2 => 0.8,
        3 => 1.0,
        4 => 1.25,
        5 => 1.5,
    ],

    // Persistence scales between this floor (seen once) and 1.0 (seen on every
    // expected feed day). A SKU in one feed and never again is usually
    // clearance, not a stocked line — worth less, not worthless.
    'persistence_floor' => (float) env('AD_DEMAND_PERSISTENCE_FLOOR', 0.4),

    // ── Display size decides large vs small ──────────────────────────────
    //
    // 261011-lpg — keywords alone could not tell an 85in Samsung from a 24in
    // monitor: `display_large` matched only words like "interactive" and
    // "signage", so "Samsung 85in QM85C Display" and "LG 98in Display" both
    // fell through to `display_small` and took its 10/week base rate. A 98in
    // commercial panel is specified, delivered and wall-mounted; it does not
    // sell like a desktop monitor.
    //
    // The diagonal is in the title, so it is MEASURED rather than guessed. At or
    // above this many inches a display is treated as large. Applied only once a
    // title is already recognised as a display, so a 27in monitor ARM stays an
    // accessory.
    'display_large_min_inches' => (int) env('AD_DEMAND_DISPLAY_LARGE_MIN_INCHES', 55),

    // ── A £2,000 stand is not a £15 cable ────────────────────────────────
    //
    // 261011-lpg — `accessory` carries a 35/week base rate calibrated for
    // cables, brackets and adapters. It also matches "stand", "trolley" and
    // "mount", which in an AV catalogue includes £700 floor trolleys and £2,000
    // Neat Board stands. On the second live run those took the top three places:
    //
    //   #1 B-Tech BT8558/B     ~£730 net   est 5-15/wk  £1,840/wk
    //   #2 ViewSonic EP5542T   ~£2,240 net est 2-5/wk   £1,399/wk
    //   #3 Neat adaptive stand ~£2,018 net est 5-15/wk  £581/wk
    //
    // The price-band decay alone could not fix it: 35 x 0.18 is still ~6/week
    // for a £2,000 stand. The base rate was the wrong starting point, not the
    // multiplier.
    //
    // Above this NET price an accessory is a considered purchase rather than an
    // impulse one, and gets its own base rate. Same shape as the display rule:
    // a MEASURED price refining a keyword-guessed class.
    'accessory_major_min_net_pence' => (int) env('AD_DEMAND_ACCESSORY_MAJOR_MIN_NET_PENCE', 40000),

    // ── Reporting bands ──────────────────────────────────────────────────
    // The model cannot justify decimal precision, so the command prints a band
    // next to the number and the band is what should be read.
    'bands' => [
        // 261011-lpg — ASCII hyphens, deliberately. These labels were written
        // with en dashes and the operator's terminal DROPS them, so the first
        // live run printed "2-5/wk" as "25/wk" and "5-15/wk" as "515/wk" — a
        // demand band rendered as a number an order of magnitude out, in the
        // column the whole ranking is read from. The PDF and CSV were fine; the
        // console is the primary read and it was not.
        ['max_units' => 0.5, 'label' => 'under 1/wk'],
        ['max_units' => 2.0, 'label' => '1-2/wk'],
        ['max_units' => 5.0, 'label' => '2-5/wk'],
        ['max_units' => 15.0, 'label' => '5-15/wk'],
        ['max_units' => INF, 'label' => '15+/wk'],
    ],

    // ── Ad-viability gates ───────────────────────────────────────────────
    // A Shopping click in UK AV runs roughly £0.40–£1.20 and converts at 1–2%,
    // so acquiring one order costs on the order of £30–£100. Below that the
    // margin cannot pay for the click, whatever the volume.
    //
    // 261011-lpg — this was written as "the backstop for when an operator lowers
    // the margin floor" and then never wired to anything. The first live run used
    // --min-margin-pence=4000 and duly shortlisted products carrying £42-£46 of
    // cash margin: real money, but less than one Shopping acquisition costs, so
    // every sale would have been bought at a loss. Dead config is worse than no
    // config, because the comment claims a protection that does not exist. Now
    // enforced by the command.
    'min_margin_pence' => (int) env('AD_DEMAND_MIN_MARGIN_PENCE', 6000),

    // Under about one sale a month there is never enough conversion data for
    // Google to optimise, so the campaign cannot learn regardless of margin.
    'min_units_per_week' => (float) env('AD_DEMAND_MIN_UNITS_PER_WEEK', 0.25),
];
