<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Spec Taxonomy resolution — operator-extensible LOOKUP maps (260728-fwx T9/T10)
|--------------------------------------------------------------------------
|
| These tables feed App\Domain\ProductAutoCreate\Services\SpecTaxonomyResolver.
| The resolver holds the SMART LOGIC (regex / keyword-contains / split /
| numeric banding / VESA enumeration / re-routing); this file holds only the
| DATA it consumes, so an operator can extend coverage WITHOUT a code change or
| a redeploy of the resolver.
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
| T10 added: VESA range-enumeration, Room Size text-map + multi-value, Movement
| (+ Mount→Movement value re-routing), and straight value maps for Shielding /
| Colour / Display Technology / Material / Length / Light Source, plus a batch of
| label aliases and the EAN label-drop.
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

        // (T9) — driven by the prod gap report. High-confidence only.
        'vesa compatibility' => 'vesa',
        'connection' => 'connectivity',
        'connection type' => 'connectivity',

        // (T10 §4) — operator-supplied label aliases to lift facet coverage.
        'max load capacity' => 'max load',
        'max weight capacity' => 'max load',
        'load rating' => 'max load',
        'backlight technology' => 'display technology',
        'backlight type' => 'display technology',
        'panel technology' => 'display technology',
        // NOTE: 'display type'→'display technology' is a DELIBERATE T10 reversal
        // of the T9 "keep Display Type local" stance (operator reference §4).
        'display type' => 'display technology',
        'motion type' => 'movement',
        'touch' => 'touchscreen',
    ],

    /*
    |----------------------------------------------------------------------
    | drop_labels — labels dropped ENTIRELY (never global, local, OR unmatched)
    |----------------------------------------------------------------------
    | NORMALISED labels. EAN is a native WooCommerce GTIN field, not an
    | attribute (a barcode = one term per product) — it must not surface as a
    | spec row at all (T10 §4b).
    */
    'drop_labels' => [
        'ean',
    ],

    /*
    |----------------------------------------------------------------------
    | unit_routed_labels — a label whose TARGET attribute depends on the
    | UNIT found in the VALUE (the resolver reads the value, not the label).
    |----------------------------------------------------------------------
    | Keyed by NORMALISED label → ordered [ unit-needle (ci, contains) =>
    | canonical 44-map key ]. First needle found in the value wins; if none
    | match, the row falls through to LOCAL (never guessed).
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
    | value_conditional_labels — a label routed ONLY when the VALUE matches a
    | pattern; otherwise the row stays LOCAL (never guessed).
    |----------------------------------------------------------------------
    | Keyed by NORMALISED label → ordered [ PCRE pattern (tested against the
    | RAW value) => canonical 44-map key ]. First matching pattern wins.
    |
    | `Cable Type` is ambiguous — it is only a Cable Category when the value is
    | an actual CatN grade; any other value (USB, HDMI, …) stays LOCAL (T10 §4).
    */
    'value_conditional_labels' => [
        'cable type' => [
            '/\bcat\.?\s?(?:5e|6a|6|7|8)\b/i' => 'cable category',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | value_reroutes — a VALUE under one attribute's label that actually
    | belongs to a DIFFERENT attribute (the value, not the label, decides).
    |----------------------------------------------------------------------
    | Keyed by SOURCE slug → ['target' => canonical 44-map key]. The resolver
    | re-routes ONLY when the value is NOT a genuine source value (does not hit
    | the source's own normaliser) AND DOES hit the target's normaliser — so a
    | "Full-Motion Wall Mount" (a Wall mount) stays Mount, while a bare "Fixed"
    | / "Tilt" / "Full Motion – Tilt, Swivel, 3 Pivots" routes to Movement
    | (T10 §3). `Motion Type` reaches Movement via the label alias above.
    */
    'value_reroutes' => [
        'pa_mount-type' => [
            'target' => 'movement',
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
    | Two OPTIONAL keys are honoured on ANY normaliser entry (checked BEFORE the
    | verbatim cache match, so they can override an otherwise-cached value):
    |  - `drop_values` : list<string> of normalised values FORCED to unmatched
    |                    even when they exist as a cached term (junk facets).
    |  - `overrides`   : map<normalised-value,term> forcing a specific cached
    |                    term (e.g. US→UK spelling) ahead of the verbatim match.
    |
    | Strategies:
    |  - resolution : WxH digit-pair map + keyword (contains) map.
    |  - keywords   : ordered contains-keyword → term. First/most-specific wins.
    |  - alnum_map  : strip non-alphanumeric + lowercase → exact key → term.
    |  - warranty   : strip trailing "warranty" + keyword map + numeric logic.
    |  - panel      : contains ips→IPS, word-boundary va→VA, else lcd→LCD.
    |  - vesa       : compatible-catch + range ENUMERATION over standard_patterns.
    |  - length     : "<n> m|metre(s)|meter(s)" → "<n>m".
    |  - room_size  : text_map + numeric derivation, MULTI-VALUE (see resolver).
    |  - multi      : split into tokens; token_map/token_expansions + bearer_modes.
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
        // T10 §3 rows: Corner / Mullion / In-Window / Swivel Mount / Universal
        // Projector → Wall; Fixed Height Mobile Stand → Floor Standing.
        'pa_mount-type' => [
            'strategy' => 'keywords',
            'keywords' => [
                'din rail' => 'DIN Rail',
                'trolley' => 'Trolley / Cart',
                'cart' => 'Trolley / Cart',
                'table top' => 'Tabletop',
                'tabletop' => 'Tabletop',
                'mobile stand' => 'Floor Standing',
                'universal projector' => 'Wall',
                'swivel mount' => 'Wall',
                'corner' => 'Wall',
                'mullion' => 'Wall',
                'in-window' => 'Wall',
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

        // Movement (3522): T10 §3. Ordered most-specific first so a compound
        // "Full Motion – Tilt, Swivel, 3 Pivots" resolves to Full Motion and a
        // "Tilt & Swivel" is never clipped to Tilt/Swivel. Used BOTH for the
        // Movement/Motion-Type label AND the Mount→Movement value re-route.
        'pa_movement' => [
            'strategy' => 'keywords',
            'keywords' => [
                'full motion' => 'Full Motion',
                'tilt & swivel' => 'Tilt & Swivel',
                'tilt and swivel' => 'Tilt & Swivel',
                'swivel' => 'Swivel',
                'tilt' => 'Tilt',
                'fixed' => 'Fixed',
            ],
        ],

        // Shielding (3537): T10 §3. 'unshielded'/'u/utp'/'utp' → U/UTP FIRST so
        // "unshielded" is not caught by the generic "shielded"→S/FTP row.
        // Yes / No / Braided carry no keyword → unmatched (dropped, not sent).
        'pa_shielding-2' => [
            'strategy' => 'keywords',
            'keywords' => [
                'unshielded' => 'U/UTP',
                'u/utp' => 'U/UTP',
                'utp' => 'U/UTP',
                's/ftp' => 'S/FTP',
                'sftp' => 'S/FTP',
                'stp' => 'S/FTP',
                'double shielded' => 'S/FTP',
                'fully shielded' => 'S/FTP',
                'shielded' => 'S/FTP',
            ],
        ],

        // Colour (3268): T10 §3. Two-tone → dominant colour. Order encodes the
        // operator's dominance rules (Graphite/Silver/Grey before Black/White).
        'pa_colour' => [
            'strategy' => 'keywords',
            'keywords' => [
                'graphite' => 'Graphite',
                'charcoal' => 'Charcoal',
                'chrome' => 'Chrome',
                'silver' => 'Silver',
                'grey' => 'Grey',
                'gray' => 'Grey',
                'black' => 'Black',
                'white' => 'White',
                'blue' => 'Blue',
                'red' => 'Red',
                'green' => 'Green',
                'yellow' => 'Yellow',
                'transparent' => 'Transparent',
            ],
        ],

        // Display Technology (3520): T10 §3. "LED-backlit LCD" → LCD; "Direct
        // View LED, …" → Direct View LED. drop_values force-unmatch the junk
        // marketing terms that the live cache still carries (they are NOT facets).
        'pa_display-tech' => [
            'strategy' => 'keywords',
            'keywords' => [
                'direct view led' => 'Direct View LED',
                'nanocell' => 'NanoCell',
                'mini-led' => 'Mini-LED',
                'microled' => 'MicroLED',
                'qled' => 'QLED',
                'oled' => 'OLED',
                'lcd' => 'LCD',
                'ips' => 'IPS',
            ],
            'drop_values' => [
                'interactive display',
                'commercial tv',
                'large format commercial display',
                'digital',
            ],
        ],

        // Material (3364): T10 §3. Keywords for the non-cached descriptors;
        // overrides force the US→UK spelling / dominant-material resolution for
        // values that are THEMSELVES cached terms (verbatim match would keep them).
        'pa_material' => [
            'strategy' => 'keywords',
            'keywords' => [
                'polycarbonate' => 'Polycarbonate',
                'stainless steel' => 'Steel',
                'cold-rolled steel' => 'Steel',
                'powder-coated steel' => 'Steel',
                'steel' => 'Steel',
                'aluminium' => 'Aluminium',
                'aluminum' => 'Aluminium',
                'abs plastic' => 'Plastic',
                'plastic' => 'Plastic',
                'glass' => 'Glass',
            ],
            'overrides' => [
                'aluminum' => 'Aluminium',
                'aluminum alloy' => 'Aluminium',
                'aluminium and steel' => 'Aluminium',
                'steel/aluminum' => 'Steel',
            ],
        ],

        // Light Source (3542): T10 §3 (SIMPLE maps ONLY). Model-prefix inference
        // (Acer PL → Laser, Epson EB-W → Lamp, …) is DEFERRED — it needs product
        // model context the resolver does not have. NEVER infer Lamp from the
        // word "lamp" in copy (so no 'lamp' keyword here).
        'pa_light-source' => [
            'strategy' => 'keywords',
            'keywords' => [
                'laser' => 'Laser',
                'phosphor' => 'Laser',
                'duracore' => 'Laser',
                'solid shine' => 'Laser',
                'rgb led' => 'LED',
                '4led' => 'LED',
                'led' => 'LED',
                'uhp' => 'Lamp',
                'uhe' => 'Lamp',
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

        // Length (3534): T10 §3. "0.6 m"/"2 m"/"3 metres" → "0.6m"/"2m"/"3m".
        // (A cached "N metres" whole-matches first; the normaliser only fires
        //  for non-cached space/unit variants — see resolver note.)
        'pa_cable-length' => [
            'strategy' => 'length',
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

        // VESA (3533): T10 §1 range ENUMERATION. compatible-catch, else emit
        // every standard pattern within the stated range (+ endpoints), sorted
        // ascending and joined by ' / '. The produced compound string is then
        // resolved as a SINGLE cached term (resolve-don't-invent).
        'pa_vesa-standard' => [
            'strategy' => 'vesa',
            'compatible_term' => 'VESA compatible',
            'standard_patterns' => [
                [50, 50], [75, 75], [100, 100], [200, 100], [200, 200], [300, 300],
                [400, 200], [400, 400], [600, 400], [800, 400], [800, 600], [900, 600], [1000, 600],
            ],
        ],

        // Room Size (3553): T10 §2 text-map + numeric derivation, MULTI-VALUE.
        // text_map is ordered contains-keyword (Medium before Large so
        // "Medium/Large" → Medium). band_rank sorts emitted bands small→large.
        'pa_room-size-band' => [
            'strategy' => 'room_size',
            'text_map' => [
                'focus room' => 'Huddle (2-4 people)',
                'phone booth' => 'Huddle (2-4 people)',
                'huddle' => 'Huddle (2-4 people)',
                'small' => 'Small (4-6 people)',
                'medium' => 'Medium (6-10 people)',
                'extra-large' => 'Large (10+ people)',
                'extra large' => 'Large (10+ people)',
                'large' => 'Large (10+ people)',
            ],
            'band_rank' => [
                'Huddle (2-4 people)' => 1,
                'Small (4-6 people)' => 2,
                'Medium (6-10 people)' => 3,
                'Large (10+ people)' => 4,
            ],
        ],

        // Connectivity (3273): MULTI — split + per-token normalise + resolve all.
        // token_expansions: ONE token → MANY terms (Network → Ethernet + IP /
        // Network). bearer_modes: a resolved bearer implies a connection-MODE
        // facet (HDMI/USB/Ethernet → Wired; Wi-Fi/Bluetooth/DECT → Wireless) —
        // applied to DIRECTLY-resolved tokens only (see resolver note).
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
            // Contains-keyword → LIST of terms. Expansion-derived terms do NOT
            // trigger bearer_modes (so "Network (LAN)" stays [Ethernet, IP /
            // Network] and never adds Wired).
            'token_expansions' => [
                'network' => ['Ethernet', 'IP / Network'],
                'ip with integrated radio' => ['Ethernet', 'IP / Network'],
                'lan' => ['Ethernet', 'IP / Network'],
                'cat5e' => ['Ethernet'],
                'quick disconnect' => ['Ethernet'],
                '3.5mm stereo jack' => ['3.5mm Audio'],
            ],
            // Mode term → the bearer terms that imply it (resolve-don't-invent:
            // the mode term is only added if it is itself a cached term).
            'bearer_modes' => [
                'Wired' => ['HDMI', 'USB', 'USB-C', 'USB-A', 'USB 2.0', 'USB 3.0', 'Ethernet', 'DisplayPort', 'DVI', 'HDBaseT', 'SDI', 'VGA', 'HDMI/USB3.0/IP'],
                'Wireless' => ['Wi-Fi', 'WiFi', 'Bluetooth', 'DECT', '2.4GHz Wireless'],
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
    |
    | NOTE: Room Size is ALSO multi-value but has its own `room_size` strategy
    | dispatch (text-map + numeric), so it is NOT listed here.
    */
    'multi_value' => [
        'pa_connectivity',
    ],

];
