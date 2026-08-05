<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Spec Taxonomy resolution — operator-extensible LOOKUP maps (260728-fwx T9)
|--------------------------------------------------------------------------
|
| These tables feed App\Domain\ProductAutoCreate\Services\SpecTaxonomyResolver.
| The resolver holds the SMART LOGIC (regex / keyword-contains / split /
| numeric banding); this file holds only the DATA it consumes, so an operator
| can extend coverage WITHOUT a code change or a redeploy of the resolver.
|
| HARD RULE — RESOLVE-DON'T-INVENT: every mapping here is only a CANDIDATE.
| The resolver still requires the candidate to match an EXISTING cached term
| (woo_attribute_terms, synced by `spec:sync-taxonomy-cache`). A candidate that
| does not resolve to a live term is dropped as `unmatched` and logged — it is
| NEVER sent to Woo (Woo would auto-create a dup term and re-pollute the facet).
|
| So the correct extension workflow is: run `spec:unmatched-report`, find a
| recurring raw label/value, add an alias/normaliser row here whose TARGET is a
| string that ALREADY exists as a live term, and the resolver picks it up.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | label_aliases — tolerant label variants → canonical 44-map key
    |----------------------------------------------------------------------
    | Keys + values are NORMALISED labels (lowercase, punctuation→space,
    | '²'→'2'). Merged OVER the resolver's built-in alias defaults, so this
    | file wins on conflicts and only needs to carry additions.
    |
    | CONSERVATIVE — only high-confidence, unambiguous aliases. Do NOT add
    | ambiguous labels (Series, Product Type, Display Type, Compatibility,
    | Form Factor) — they must stay LOCAL, not be forced onto a taxonomy.
    */
    'label_aliases' => [
        // Existing (T2) defaults mirrored here for operator visibility.
        'screen resolution' => 'resolution',
        'display resolution' => 'resolution',
        'native resolution' => 'resolution',
        'display size' => 'display size band',
        'screen size' => 'display size band',
        'diagonal size' => 'display size band',
        'connectivity options' => 'connectivity',
        'connections' => 'connectivity',
        'ports' => 'connectivity',
        'mounting type' => 'mount type',
        'warranty period' => 'warranty',
        'hdr support' => 'hdr',
        'display tech' => 'display technology',
        'refresh rate hz' => 'refresh rate',
        'viewing angle deg' => 'viewing angle',
        'touch technology type' => 'touch technology',
        'projection tech' => 'projection technology',
        'colour finish' => 'colour',
        'color' => 'colour',
        'color finish' => 'colour',
        'cable length' => 'length',
        'max load kg' => 'max load',
        'maximum load' => 'max load',
        'weight capacity' => 'max load',
        'power output w' => 'power output',
        'field of view deg' => 'field of view',
        'fov' => 'field of view',
        'platform certified' => 'platform certification',
        'platform certifications' => 'platform certification',
        'room size band' => 'room size',

        // NEW (T9) — driven by the prod gap report. High-confidence only.
        'vesa compatibility' => 'vesa',
        'connection' => 'connectivity',
        'connection type' => 'connectivity',
    ],

    /*
    |----------------------------------------------------------------------
    | unit_routed_labels — a label whose TARGET attribute depends on the
    | UNIT found in the VALUE (the resolver reads the value, not the label).
    |----------------------------------------------------------------------
    | Keyed by NORMALISED label → ordered [ unit-needle (ci, contains) =>
    | canonical 44-map key ]. First needle found in the value wins; if none
    | match, the row falls through to LOCAL (never guessed).
    |
    | `brightness` is inherently ambiguous — lumens (projectors) vs cd/m²
    | (panels) route to DIFFERENT taxonomies. After routing, the resolver's
    | existing band derivation + never-mix-units guard apply unchanged.
    */
    'unit_routed_labels' => [
        'brightness' => [
            'lumen' => 'brightness band lumens', // → pa_brightness-lumens (3554)
            'cd/m' => 'brightness band cd m2',   // → pa_brightness-nits   (3518)
            'nit' => 'brightness band cd m2',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | value_normalisers — per-attribute value LOOKUP tables (keyed by slug)
    |----------------------------------------------------------------------
    | Applied when a raw value does not match a cached term verbatim (exact /
    | case-insensitive). Each entry names a `strategy` (the resolver method
    | that interprets the data) plus that strategy's data. The candidate the
    | strategy produces is STILL resolved against the live cache before use.
    |
    | Strategies:
    |  - resolution : WxH digit-pair map + keyword (contains) map.
    |  - keywords   : ordered contains-keyword → term. First/most-specific wins.
    |  - alnum_map  : strip non-alphanumeric + lowercase → exact key → term.
    |  - warranty   : strip trailing "warranty" + keyword map + numeric
    |                 year/month formatting (logic in resolver).
    |  - panel      : contains ips→IPS, word-boundary va→VA, else lcd→LCD.
    |  - vesa       : space-normalise around x and /, plus a "compatible" catch.
    |  - multi      : split into tokens; each token normalised via token_map
    |                 (contains) then resolved; ALL that resolve are emitted.
    */
    'value_normalisers' => [

        // Resolution (3429): × → x, strip spaces, map by WxH pair, else keyword.
        'pa_resolution' => [
            'strategy' => 'resolution',
            'pairs' => [
                '3840x2160' => '4K UHD (3840x2160)',
                '1920x1080' => 'Full HD (1920x1080)',
                '2560x1440' => 'QHD (2560x1440)',
                '1280x720' => 'HD (1280x720)',
                '7680x4320' => '8K UHD (7680x4320)',
            ],
            // Ordered: most-specific keyword first (contains match).
            'keywords' => [
                '4k ultra hd' => '4K UHD (3840x2160)',
                'uhd' => '4K UHD (3840x2160)',
                '4k' => '4K UHD (3840x2160)',
                'full hd' => 'Full HD (1920x1080)',
                'fhd' => 'Full HD (1920x1080)',
                '1080p' => 'Full HD (1920x1080)',
            ],
        ],

        // Mount type (3517): contains-keyword, most-specific first.
        'pa_mount-type' => [
            'strategy' => 'keywords',
            'keywords' => [
                'din rail' => 'DIN Rail',
                'trolley' => 'Trolley / Cart',
                'cart' => 'Trolley / Cart',
                'table top' => 'Tabletop',
                'tabletop' => 'Tabletop',
                'ceiling' => 'Ceiling',
                'wall' => 'Wall',
                'clamp' => 'Desk',
                'desk' => 'Desk',
                'floor' => 'Floor Standing',
                'pole' => 'Pole',
                'tripod' => 'Tripod',
                'rack' => 'Rack',
            ],
        ],

        // Cable category (3538): strip non-alnum + lowercase → exact key.
        'pa_cable-category' => [
            'strategy' => 'alnum_map',
            'map' => [
                'cat5e' => 'Cat5e',
                'cat6a' => 'Cat6a',
                'cat6' => 'Cat6',
                'cat7' => 'Cat7',
                'cat81' => 'Cat8',
                'cat8' => 'Cat8',
            ],
        ],

        // Warranty (3498): strip trailing "warranty"; keyword + numeric logic.
        'pa_warranty' => [
            'strategy' => 'warranty',
            'keywords' => [
                'lifetime' => 'Lifetime',
            ],
        ],

        // Panel type (3543): ips→IPS, \bva\b→VA, else lcd→LCD.
        'pa_panel-type' => [
            'strategy' => 'panel',
            'keywords' => [
                'ips' => 'IPS',
                'va' => 'VA',
                'lcd' => 'LCD',
            ],
        ],

        // VESA (3533): normalise spaces around x and /, "compatible" catch.
        'pa_vesa-standard' => [
            'strategy' => 'vesa',
            'compatible_term' => 'VESA compatible',
        ],

        // Connectivity (3273): MULTI — split + per-token normalise + resolve all.
        'pa_connectivity' => [
            'strategy' => 'multi',
            // Ordered contains-keyword → canonical term. Most-specific first so
            // "2.4 GHz Wireless" wins over the generic "wireless".
            'token_map' => [
                '2.4 ghz' => '2.4GHz Wireless',
                '2.4ghz' => '2.4GHz Wireless',
                'wi-fi' => 'Wi-Fi',
                'wifi' => 'Wi-Fi',
                'bluetooth' => 'Bluetooth',
                'ethernet' => 'Ethernet',
                'hdbaset' => 'HDBaseT',
                'usb-c' => 'USB-C',
                'usb-a' => 'USB-A',
                'displayport' => 'DisplayPort',
                'thunderbolt' => 'Thunderbolt',
                'hdmi' => 'HDMI',
                'poe' => 'PoE',
                'dect' => 'DECT',
                'xlr' => 'XLR',
                'wireless' => 'Wireless',
                'wired' => 'Wired',
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | multi_value — slugs whose ONE raw value may carry MANY terms
    |----------------------------------------------------------------------
    | The resolver splits the value on [,/&]+, " and ", "+" and emits one
    | GLOBAL row carrying ALL tokens that resolve (term_ids/term_names arrays);
    | tokens that don't resolve are logged unmatched WITHOUT failing the row.
    | A value that matches a single cached term verbatim (e.g. "IP / Network")
    | is kept whole (not split), preserving slash-bearing term names.
    */
    'multi_value' => [
        'pa_connectivity',
    ],

];
