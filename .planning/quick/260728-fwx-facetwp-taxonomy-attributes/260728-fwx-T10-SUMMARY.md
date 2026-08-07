# 260728-fwx T10 — comprehensive value-normalisation + label-alias coverage — SUMMARY

**One-liner:** Extended the T2/T9 `SpecTaxonomyResolver` + `config/spec_taxonomy.php`
with the operator-supplied normalisation ruleset — VESA range ENUMERATION, Room
Size multi-value text-mapping, Mount→Movement value RE-ROUTING, straight value
maps for Shielding/Colour/Display Tech/Material/Length/Light Source, Connectivity
token-expansions + bearer→mode, a batch of label aliases, and the EAN label-drop —
all still RESOLVE-DON'T-INVENT (every candidate must match an EXISTING cached
`woo_attribute_terms` term, else `unmatched`/logged; tests seed from
`canonical-terms.json`).

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `6b56574` | feat | config + resolver: VESA enumeration, Room Size multi-value, Mount→Movement re-route, value maps, label aliases, EAN drop |
| `9fe0f6d` | test | T10 coverage cases + bearer→mode fixture updates + extended seed vocabulary |

(SUMMARY + this doc committed separately.)

## Rules added (every one)

### §1 VESA range-enumeration (`pa_vesa-standard`, `vesa` strategy)
- `standard_patterns` moved to config (the 13-entry VESA_STANDARD list).
- Normalise: `×/✕`→`x`, en/em-dash + minus variants→`-`, strip `mm`, tidy `x`-spacing.
- **Range** `AxB to|and|- CxD` → every standard pattern `P` with `P.w ∈ [minW,maxW]`
  and `P.h ∈ [minH,maxH]`, PLUS the stated endpoints, deduped, sorted ascending
  (w then h), `' / '`-joined → resolved as ONE cached compound term.
  - `200×200 mm to 600×400 mm` → `200x200 / 300x300 / 400x200 / 400x400 / 600x400`.
  - `75×75 mm and 100×100 mm` → `75x75 / 100x100`.
- **Comma / VESA-N list** `VESA 75, VESA 100` → `75x75 / 100x100`.
- **Single** `AxB`/`200 x 200` → `200x200`; single `VESA N` → `NxN`.
- **Pre-enumerated slash-list** preserved (spacing-normalised) — back-compat.
- A produced string that is NOT a cached term → `unmatched` (proven by test).

### §2 Room Size (`pa_room-size-band`, `room_size` strategy — dedicated dispatch)
- Removed from generic `BAND_SLUGS`; own `resolveRoomSize()` handler.
- `text_map` (ordered contains): Focus Room/Phone Booth/Huddle→Huddle; Small→Small;
  Medium→Medium (ordered BEFORE Large so `Medium/Large`→Medium); Extra-Large/Large→Large.
- Numeric derivation retained (via the unchanged `BAND_TABLES` room-size row).
- **MULTI-VALUE**: split on `,`/`&`/`and` (NOT `/`, NOT `to`), resolve each token
  (exact → text-map → numeric), dedupe, sort small→large by `band_rank`; ONE global
  row + raw value as LOCAL companion.

### §3 Movement + Mount→Movement re-routing
- New `pa_movement` `keywords` normaliser (Full Motion / Tilt & Swivel / Swivel /
  Tilt / Fixed — most-specific first, so `Full Motion – Tilt, Swivel, 3 Pivots`→
  Full Motion and `Tilt & Swivel` is never clipped).
- New `value_reroutes` config + `rerouteTarget()`: a value under a Mount label that
  is NOT a genuine mount (no mount keyword) AND IS a movement value → emitted under
  `pa_movement`. Guard means real mounts stay Mount (`Full-Motion Wall Mount`→Wall).
- `Motion Type` label → Movement via label alias.

### §3 Straight value maps
- **Shielding** (`pa_shielding-2`): `unshielded`/`u-utp`/`utp`→U/UTP first; SFTP/STP/
  double/fully/shielded→S/FTP; Yes/No/Braided→unmatched (dropped).
- **Colour** (`pa_colour`): two-tone→dominant via ordered keywords (Graphite/Silver/
  Grey before Black/White); `Graphite Grey`→Graphite.
- **Display Technology** (`pa_display-tech`): backlit-LCD variants→LCD, Direct View
  LED etc.; `drop_values` force-unmatch cached junk (Interactive Display / Commercial
  TV / Large Format Commercial Display / Digital).
- **Material** (`pa_material`): keywords (Steel/Aluminium/Plastic/Polycarbonate…);
  `overrides` force cached US→UK spelling (`Aluminum`→Aluminium) + dominant compounds
  (`Aluminium and steel`→Aluminium, `Steel/aluminum`→Steel).
- **Length** (`pa_cable-length`, `length` strategy): `0.6 m`/`2 m`/`3 metres`→`0.6m`/
  `2m`/`3m`.
- **Mount Type** rows: Corner/Mullion/In-Window/Swivel Mount/Universal Projector→
  Wall; Fixed Height Mobile Stand→Floor Standing.
- **Connectivity** (`pa_connectivity`): `token_expansions` (Network/Network (LAN)/IP
  with integrated radio/LAN→Ethernet + IP / Network; Cat5e/Quick Disconnect→Ethernet;
  3.5mm Stereo Jack→3.5mm Audio) + `bearer_modes` (HDMI/USB/Ethernet…→Wired; Wi-Fi/
  Bluetooth/DECT/2.4GHz→Wireless).
- **Light Source** (`pa_light-source`): SIMPLE maps only — Laser/Phosphor/RGB True
  Laser/DuraCore/SOLID SHINE→Laser; RGB LED/4LED→LED; UHP/UHE→Lamp. (No `lamp`
  keyword — never infer Lamp from copy.)

### §4 Label aliases added
`max load capacity`/`max weight capacity`/`load rating`→max load;
`backlight technology`/`backlight type`/`display type`/`panel technology`→display
technology; `motion type`→movement; `touch`→touchscreen; `cable type`→cable category
ONLY when the value is a CatN (via `value_conditional_labels` + `routeByValuePattern`).

### §4b Do-not-alias / drop
`Connector A`/`Connector B`, `Screen Size Range`/`Compatible Screen Size`, `Brand`,
`MPN`/`Model`/`Part Number`/`RRP` all stay LOCAL (no aliases added). **EAN dropped
entirely** via new `drop_labels` — never global, local, or unmatched.

## New resolver mechanisms (reusable)
- `drop_labels` — labels skipped entirely in `prepare()`.
- `value_conditional_labels` + `routeByValuePattern()` — value-pattern label routing.
- `value_reroutes` + `rerouteTarget()` — value-based attribute re-routing.
- Per-normaliser `drop_values` (force-unmatch cached junk) + `overrides` (force a
  cached term ahead of the verbatim match) in `resolveValue()`.
- `length` + `room_size` strategies; VESA `standard_patterns` enumeration;
  connectivity `token_expansions` + `bearer_modes`.

## Deviations from plan (deliberate behaviour changes — documented)

1. **bearer→mode adds Wired/Wireless** to existing Connectivity fixtures. Five prior
   T9 assertions were updated (each a deliberate coverage-lift per §3):
   - `Bluetooth, 2.4 GHz Wireless` → `[Bluetooth, 2.4GHz Wireless, Wireless]` (unit + builder).
   - `Bluetooth 5.1` → `[Bluetooth, Wireless]`.
   - `Bluetooth & Telepathy` → `[Bluetooth, Wireless]` (+ Telepathy unmatched).
   - `Connection Type`/`HDMI` → `[HDMI, Wired]`.
   `Wired and Wireless` and `IP / Network` (whole-match) unchanged; the `Thunderbolt 5`
   integration case unchanged (still unmatched against an HDMI-only cache).
2. **Mount `Fixed`/`Tilt`/`Swivel` now route to Movement** (previously would have been
   unmatched mounts) — the §3 re-route rule. Covered by new tests; real mounts (with a
   location keyword) unchanged.
3. **bearer→mode fires only for DIRECTLY-resolved bearers, not expansion-derived ones**
   — interpretation required to satisfy the explicit VERIFY `Network (LAN)→[Ethernet,
   IP / Network]` (no Wired). A directly-typed `Ethernet` still adds Wired.
4. **Exact-match-first retained** (T9 architectural decision): `drop_values`/`overrides`
   are the sanctioned way to override an otherwise-cached value; used for Display Tech
   junk + Material spelling.

## Doc rules I could NOT (fully) implement — and why

- **Light Source model-prefix inference** (Acer PL→Laser, Epson EB-W→Lamp, …) —
  **DEFERRED**. Needs the product MODEL string, which the resolver (a pure spec-row
  classifier) does not receive. Future enrichment: pass model context or run a
  pre-classifier that injects a Light Source spec row.
- **Lumens 6-band boundary change** (doc: Under 3000 / 3000-3999 / 4000-4999 /
  5000-6999 / 7000-9999 / 10000+) — **NOT changed** per task instruction. Kept the
  current 4-band derivation (prod cache showed 4). **Operator action:** re-sync the
  cache and reconcile before widening to 6 bands.
- **VESA term-set size** (doc ~63 combos vs cache ~32) — relied on RESOLVE-DON'T-INVENT:
  an enumerated string only emits if it is a cached term, else `unmatched` (safe).
  **Operator action:** re-sync the VESA cache to lift match rate.
- **Room Size APPLICABILITY** ("only room-serving products; unset on touch monitors,
  accessories, …") — **NOT enforced in the resolver** (no product-category context).
  The resolver maps whatever Room Size row it is given; suppressing the row on
  non-room-serving products is the extractor's responsibility.
- **`TosLink (Optical)`→Optical Audio** — left as `unmatched` (per doc: "not a current
  term unless operator adds it"). No fabricated term.
- **Length `N metres`→`Nm` for values ALREADY cached as `N metres`** — exact-match-first
  keeps the cached `N metres` term; the `length` normaliser only rewrites non-cached
  space/unit variants (e.g. `0.6 m`→`0.6m`). Minor dedup nuance; both spellings are
  valid cached terms. Could be forced via `overrides` if the operator wants strict `m`.
- **Impedance mojibake** — untouched (WP-side encoding cleanup, out of resolver scope).

## Verification results

| Check | Result |
|-------|--------|
| `SpecTaxonomyNormalisationTest` (T9+T10, DB-seeded from canonical terms) | **75 passed / 217 assertions** |
| `tests/Feature/ProductAutoCreate` + `tests/Feature/Console` full sweep | **500 passed / 2016 assertions** |
| `tests/Unit/ProductAutoCreate` sweep | **66 passed / 193 assertions** |
| `pint --test` (resolver, config, T10 test) | **pass** (`{"result":"pass"}`) |
| `vendor/bin/deptrac analyse` | **0 violations** |
| `artisan route:list --path=admin` | **exit 0** |

PHP via Herd `~/.config/herd/bin/php84/php.exe` (PHP 8.4.22). Unit + Feature sweeps
run separately (the pre-existing `makeResolver()` helper clash — see deferred-items.md).

## Known stubs

None. Config is fully wired; the resolver reads it via the container-bound
`WooAttributeTermVocabulary` (real `woo_attribute_terms`). No stub values, no
placeholder data.

## Constraints honoured

- No Woo I/O added to the resolver (purity source-scan test still green); no migration;
  no `WOO_WRITE_ENABLED` change; no retroactive push; no push/deploy.
- Atomic commits on `main`. Herd php84 for every check.
- Pre-existing working-tree noise NOT staged: deleted
  `storage/app/research/supplier-probe.json`, modified
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`.

## Self-Check: PASSED

- Files exist: `config/spec_taxonomy.php`, `SpecTaxonomyResolver.php`,
  `SpecTaxonomyNormalisationTest.php`, `260728-fwx-T10-SUMMARY.md`.
- Commits `6b56574` (feat) + `9fe0f6d` (test) present in `git log`.
- 75 + 500 + 66 tests green; pint pass; deptrac 0; route:list exit 0.
