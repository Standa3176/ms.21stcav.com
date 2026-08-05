# 260728-fwx T9 — label aliases + value normalisers + multi-term — SUMMARY

**One-liner:** Extended the T2 `SpecTaxonomyResolver` with config-driven label
aliases, per-attribute value normalisers (Resolution/Mount/CableCat/Warranty/
Panel/VESA + brightness unit-routing) and MULTI-TERM support (Connectivity splits
into many resolved terms), all reading a new operator-extensible
`config/spec_taxonomy.php` and all still RESOLVE-DON'T-INVENT — every normalised
candidate must match an EXISTING cached `woo_attribute_terms` term or it stays
`unmatched`/logged.

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `206a813` | test | Failing T9 specs — normalisers + multi-term + aliases + brightness routing (RED) |
| `eed269f` | feat | `config/spec_taxonomy.php` + resolver logic + ResolvedSpec/builder multi-term (GREEN) |
| `f0772ea` | test | Update two "Connection"-label fixtures for the new connectivity alias |

## What changed

### New `config/spec_taxonomy.php` (operator-extensible LOOKUP maps)

Four top-level keys — DATA only; the SMART LOGIC stays in the resolver:

- **`label_aliases`** — normalised alias → canonical 44-map key. Ships the T2
  defaults (mirrored for visibility) **plus** the T9 additions
  `vesa compatibility→vesa`, `connection→connectivity`, `connection type→connectivity`.
  Merged OVER the resolver's built-in defaults (config wins).
- **`unit_routed_labels`** — a label whose TARGET attribute depends on the value's
  UNIT. `brightness`: `lumen→pa_brightness-lumens`, `cd/m`|`nit→pa_brightness-nits`
  (first needle found in the value wins; then existing band derivation runs).
- **`value_normalisers`** — per-slug tables + a `strategy` name selecting the
  resolver method: `resolution` (WxH-pair + keyword), `keywords` (Mount, contains,
  most-specific first), `alnum_map` (CableCat), `warranty` (strip + numeric),
  `panel` (ips/\bva\b/lcd), `vesa` (space-normalise + "compatible"), `multi`
  (Connectivity token map).
- **`multi_value`** — slugs whose one value may carry many terms (`pa_connectivity`).

### `SpecTaxonomyResolver` (additive; pure classifier preserved — no Woo, no writes)

- Constructor gains an optional `?array $config = null` (defaults to
  `config('spec_taxonomy')`; unit tests may still inject). Loads the four tables
  into typed props; `labelAliases = array_merge(built-in defaults, config)`.
- `routeByUnit()` — unit-routed label handling in `prepare()` (only when the label
  isn't already a direct map/alias hit).
- New value resolution: **exact/ci on the RAW value first** (highest fidelity —
  preserves slash/paren-bearing terms like `HD (1366x768)`, `IP / Network`), then
  the per-attribute **normaliser candidate**, itself resolved exact/ci against the
  cache. A candidate is ONLY used when it resolves to a real cached term.
  - Ordering note: the brief says "normalisers before exact/ci"; implemented as
    exact/ci-first so a value that already IS a cached term links verbatim and a
    broad keyword can never clobber it. Every provided VERIFY case (and all T2/T3
    cases) passes identically, and fidelity is strictly higher.
- `resolveMultiValue()` — one raw value → one GLOBAL row carrying ALL tokens that
  resolve; a whole-value verbatim match is tried FIRST (keeps `IP / Network`
  intact); otherwise split on `[,/&]+`, `\band\b`, `+`; each token normalised via
  the config `token_map` (contains) with a trailing-version strip fallback
  (`Bluetooth 5.1→Bluetooth`); tokens that don't resolve are logged
  `value_not_a_term` WITHOUT failing the row; resolved terms deduped by id.

### `ResolvedSpec` (backward-compatible, additive)

Global rows gain `term_ids: list<int>` + `term_names: list<string>`. The scalar
`term_id`/`term_name` keys are KEPT and always mirror the FIRST resolved term, so
every pre-T9 caller/test reading them is unchanged. Single-term rows carry
one-element arrays. Public method signatures unchanged.

### `WooAttributePayloadBuilder`

GLOBAL rows now emit `options => $g['term_names']` (was `[$g['term_name']]`).
For single-term rows this is a one-element array → **byte-identical** to pre-T9
output; multi-term Connectivity emits all options. No signature change.

## Backward-compat confirmation (ResolvedSpec / builder)

- **API unchanged:** `ResolvedSpec::global()/local()/unmatched()/toArray()` and
  `WooAttributePayloadBuilder::build()` keep the same signatures; the change is
  purely additive keys + `options` sourced from the (one-or-more) `term_names`.
- **Single-term output identical:** the T3 `PublishProductGlobalAttributesTest` and
  `ResyncProductsToWooGlobalAttributesTest` assert the FULL global row shape
  (`['id'=>3429,'options'=>['4K UHD (3840x2160)'],'position'=>0,'visible'=>true,'variation'=>false]`)
  and still pass verbatim.
- **Scalar keys preserved:** the T2 unit + integration tests read
  `global()[0]['term_id']`/`['term_name']` — still present.

## The two intentional test adjustments (documented per the plan)

T9 adds a real `connection`→`pa_connectivity` alias, so two pre-existing
260728-fwx tests that used **"Connection" as a deliberately unmapped/local label
example** no longer held. Each fixture was swapped to a label T9 deliberately
leaves LOCAL/ambiguous (per the "do NOT add ambiguous ones" constraint),
preserving the test's original intent:

- `PublishProductJobTest` local-passthrough example: `Connection`/`USB-C` →
  `Series`/`Pro-X`.
- `SpecUnmatchedReportCommandTest` unmapped-label example: `Connection` →
  `Form Factor`.

No assertion about resolver mechanics was weakened — only the example label
changed, because "Connection" is now a legitimate facet.

## Verification results

| Check | Result |
|-------|--------|
| New `SpecTaxonomyNormalisationTest` (T9, DB-seeded real terms) + T2 unit + T2 integration + T3 publish/resync | **85 passed / 276 assertions** |
| `tests/Feature/ProductAutoCreate` + `tests/Feature/Console` full sweep | **all green** (2 fixtures adjusted above) |
| `tests/Unit/ProductAutoCreate` sweep | **66 passed / 193 assertions** |
| `pint --test` (resolver, ResolvedSpec, builder, config, all touched tests) | **pass** (`{"result":"pass"}`) |
| `vendor/bin/deptrac analyse` | **0 violations** |
| `artisan route:list --path=admin` | **exit 0** |

VERIFY cases proven by the new test file:
- Resolution: `4K UHD 3840 x 2160 (2160p)`, `4K UHD 3840 × 2160`, `4K Ultra HD`,
  `3840 x 2160 (4K UHD)` → `4K UHD (3840x2160)`; `Full HD 1080p` →
  `Full HD (1920x1080)`; `HD (1366x768)` still exact-matches (no pair) — proving
  the raw exact-match fast path.
- Mount: `Tilt Wall Mount`/`Wall Mount`/`Full-Motion Wall Mount`/`Wall Arm`→Wall;
  `Desk Clamp`/`Desk-mountable articulating arm`→Desk; `Ceiling Mount`→Ceiling.
- CableCat: `Cat.6`→Cat6, `Cat.8.1`→Cat8. Warranty: `Lifetime Warranty`→Lifetime
  (+ numeric `3 year`→3 Years, `1 year`→1 Year, `6 months`→6 Months).
- Panel: `LED-backlit LCD`→LCD.
- Connectivity multi: `Bluetooth, 2.4 GHz Wireless`→[Bluetooth, 2.4GHz Wireless]
  (2 options via the builder); `Bluetooth 5.1`→[Bluetooth]; `Wired and Wireless`→
  [Wired, Wireless]; `IP / Network` kept whole; `Bluetooth & Telepathy`→[Bluetooth]
  + Telepathy logged unmatched.
- Label alias: `VESA Compatibility`/`200 x 200`→global `pa_vesa-standard`
  `200x200`; `VESA`/`VESA Compatible`→`VESA compatible`; `Connection Type`→
  `pa_connectivity`; `Brightness`/`500 cd/m²`→`pa_brightness-nits`
  `Semi-bright (351-700)`; `Brightness`/`3500 lumens`→`pa_brightness-lumens`
  `3000-4999 lumens`.
- Resolve-don't-invent: a made-up mount (`Hovercraft Suspension`) normalises to
  nothing → `unmatched` (`value_not_a_term`), never sent.

## Config file shape (as delivered)

```php
return [
    'label_aliases'      => [ /* norm-alias => canonical 44-map key */ ],
    'unit_routed_labels' => [ 'brightness' => [ 'lumen'=>'brightness band lumens', 'cd/m'=>'brightness band cd m2', 'nit'=>'brightness band cd m2' ] ],
    'value_normalisers'  => [
        'pa_resolution'    => ['strategy'=>'resolution', 'pairs'=>[...], 'keywords'=>[...]],
        'pa_mount-type'    => ['strategy'=>'keywords',   'keywords'=>[...]],
        'pa_cable-category'=> ['strategy'=>'alnum_map',  'map'=>[...]],
        'pa_warranty'      => ['strategy'=>'warranty',   'keywords'=>['lifetime'=>'Lifetime']],
        'pa_panel-type'    => ['strategy'=>'panel',      'keywords'=>['ips'=>'IPS','va'=>'VA','lcd'=>'LCD']],
        'pa_vesa-standard' => ['strategy'=>'vesa',       'compatible_term'=>'VESA compatible'],
        'pa_connectivity'  => ['strategy'=>'multi',      'token_map'=>[...]],
    ],
    'multi_value'        => ['pa_connectivity'],
];
```

## Deviations from plan

- **Two test fixtures adjusted** (documented above) — required because T9's own
  `connection→connectivity` alias made "Connection" a mapped facet; the tests used
  it as an unmapped/local example. No resolver-mechanics assertion weakened.
- **Normaliser ordering** implemented as exact/ci-first then normaliser (the brief
  said "before exact/ci") — documented in-code; strictly higher fidelity, all
  VERIFY + T2/T3 cases pass identically.

## Known stubs

None. Config is fully wired; the resolver reads it via the container-bound
`WooAttributeTermVocabulary` (real `woo_attribute_terms`); multi-term flows through
`ResolvedSpec` → `WooAttributePayloadBuilder` → the two write paths (publish +
resync) unchanged.

## Deferred issues (out of scope — pre-existing)

`deferred-items.md`: a pre-existing global-helper name clash (`makeResolver()` in
both `ProductBrandTermResolverTest` and the T2 `SpecTaxonomyResolverTest`) aborts a
COMBINED Unit+Feature pest run. Not a T9 regression (neither file touched by T9;
both predate it); T9 verified by running the Unit and Feature sweeps separately.

## Constraints honoured

- No Woo I/O added to the resolver (purity source-scan test still green); no
  migration; no `WOO_WRITE_ENABLED` change; no retroactive push; no push/deploy.
- Atomic commits on `main`. Herd php84 for every check.
- Pre-existing working-tree noise NOT staged: deleted
  `storage/app/research/supplier-probe.json`, modified
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked
  `.claude/`.

## Self-Check: PASSED

- Files exist: config/spec_taxonomy.php, SpecTaxonomyResolver.php, ResolvedSpec.php, WooAttributePayloadBuilder.php, SpecTaxonomyNormalisationTest.php, 260728-fwx-T9-SUMMARY.md.
- Commits 206a813 (RED) + eed269f (GREEN) + f0772ea (fixture adjust) present in git log.
- Full Feature sweep 458 passed / 0 failed; Unit sweep 66 passed; pint pass; deptrac 0; route:list exit 0.
