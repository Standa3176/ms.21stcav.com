# 260728-fwx T2 — `SpecTaxonomyResolver` service — SUMMARY

**One-liner:** Pure, unit-testable classifier that turns a product's raw
`{name,value}[]` spec set into a `ResolvedSpec` of three buckets — GLOBAL `pa_*`
taxonomy rows (resolved to EXISTING terms only), LOCAL spec rows, and UNMATCHED
rows (logged, withheld) — enforcing resolve-don't-invent, D1 band-vs-exact, and
never-mix-units, with NO Woo I/O and NO writes.

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `13a6393` | feat | SpecTaxonomyResolver + ResolvedSpec + SpecTermVocabulary seam (+ Array/Woo impls) + container binding |
| `5a19482` | test | Unit (pure) + integration (seeded `woo_attribute_terms`) coverage — 40 tests / 130 assertions |

## What was built

New files under `app/Domain/ProductAutoCreate/Services/`:

1. **`SpecTaxonomyResolver.php`** — the core. `resolve(array $rows): ResolvedSpec`.
2. **`ResolvedSpec.php`** — value object with `global()`, `local()`, `unmatched()`, `toArray()`.
3. **`Spec/SpecTermVocabulary.php`** — injected term-lookup contract (`termsFor(int $attributeId)`).
4. **`Spec/ArraySpecTermVocabulary.php`** — in-memory impl for unit tests (no Woo/DB).
5. **`Spec/WooAttributeTermVocabulary.php`** — prod impl reading T1's local `woo_attribute_terms` mirror (loaded once, grouped by attribute_id; NO Woo call).

Wiring: `AppServiceProvider::register()` binds `SpecTermVocabulary → WooAttributeTermVocabulary`, so `app(SpecTaxonomyResolver::class)` works end-to-end in prod while tests inject an array vocabulary.

## Final public API shape

```php
// Injectable term vocabulary (the only dependency — no Woo, no writes)
interface SpecTermVocabulary {
    /** @return array<int,array{term_id:int,term_name:string,term_slug:string|null}> */
    public function termsFor(int $attributeId): array;
}

class SpecTaxonomyResolver {
    public function __construct(private SpecTermVocabulary $vocabulary) {}

    /** @param array<int,array{name?:mixed,value?:mixed}> $rows (attributes_json) */
    public function resolve(array $rows): ResolvedSpec;
}

final class ResolvedSpec {
    /** @return array<int,array{attribute_id:int,attribute_slug:string,term_id:int,term_name:string,raw_label:string,raw_value:string}> */
    public function global(): array;
    /** @return array<int,array{name:string,value:string}> */
    public function local(): array;
    /** @return array<int,array{raw_label:string,raw_value:string,attribute_slug:string,reason:string}> */
    public function unmatched(): array;
    /** @return array{global:array,local:array,unmatched:array} */
    public function toArray(): array;
}
```

**Unmatched `reason` values:** `value_not_a_term`, `band_value_not_numeric`,
`band_term_not_cached`, `mixed_units`. Every unmatched row is also logged
structured via `Log::warning('spec_taxonomy.unmatched', $row)` for the T6 report.

## Classification rules (as implemented)

1. **Label → attribute** — raw label normalised (`²/³`→`2/3`, lowercase, punctuation→space, collapse ws) then matched against the **44-map** (43 filterable `pa_*` taxonomies, verbatim ids from the plan) plus a tolerant **ALIAS** table (e.g. Screen/Display Resolution→Resolution, Display/Screen Size→Display Size Band, Connectivity Options→Connectivity, Color→Colour, Cable Length→Length, …). Unknown label → **local passthrough**.
2. **LOCAL-forced (never taxonomy, even if numeric)** — `MPN`, `Model`, `Part Number`, exact `Brightness (cd/m²)`, exact `Brightness (lumens)` (D1; the 44th attribute `pa_brightness-cdm2` 3531 stays local).
3. **Value → term (resolve-don't-invent)** — exact term_name → case-insensitive/whitespace-normalised → per-attribute VALUE-ALIAS (RESOLUTION: `4K`/`4K UHD`/`3840x2160`/`4K@60Hz`→`4K UHD (3840x2160)`; `1080p`/`FHD`/`1920x1080`→`Full HD (1920x1080)`). No hit → **unmatched** (`value_not_a_term`), never sent.
4. **Band derivation** for `pa_screen-size-band`, `pa_brightness-nits`, `pa_brightness-lumens`, `pa_room-size-band` — parse leading numeric (commas stripped), map via inclusive non-overlapping boundary tables, then **resolve the derived band label against the cache** (belt-and-braces: uncached band → `band_term_not_cached`). Non-numeric → `band_value_not_numeric`. The exact raw figure is ALSO emitted as a **local companion** row (`Brightness (cd/m²)` / `Brightness (lumens)` / `Display Size` / `Room Size`).
   - screen-size: `≤22 Up to 22 / 23-27 / 28-34 / 35-43 / 44-55 / 56-65 / 66-75 / 76-85 / >85 86 inch and above`
   - cd/m²: `≤350 Standard / 351-700 Semi-bright / 701-2500 High bright / >2500 Window facing`
   - lumens: `≤2999 Under 3000 / 3000-4999 / 5000-9999 / ≥10000 10000+ lumens`
   - room-size: `≤4 Huddle / ≤6 Small / ≤10 Medium / >10 Large` (brief's 4/6/10 overlaps resolved **lower-band-wins**, documented)
5. **Never-mix-units (D1)** — if BOTH a cd/m² and a lumens brightness signal are present (band or exact), the **first-seen unit wins**; every row of the opposite unit → **unmatched (`mixed_units`)** and logged. Tie-break is first-seen because the resolver has no category context (documented in-code).
6. **No Woo, no writes** — asserted by a source-scan test (`WooClient`/`Http::`/`Guzzle`/`curl` absent).

## Verification results

| Check | Result |
|-------|--------|
| Pest — `SpecTaxonomyResolverTest` (Unit) + `SpecTaxonomyResolverIntegrationTest` (Feature) | **40 passed / 130 assertions** |
| Band boundary cases (55→44-55, 43→35-43, 350→Standard, 351→Semi-bright, 2500→High bright, 2501→Window facing, 3000→3000-4999, 2999→Under 3000) | **PASS** |
| never-mix-units drops+logs second brightness | **PASS** |
| unresolvable value → unmatched AND not in global | **PASS** |
| NO WooClient/HTTP call anywhere (source scan) | **PASS** |
| `pint --test` (8 touched files) | **pass** |
| `deptrac analyse` | **0 violations** |

## Decisions

- **Vocabulary as an injected seam** (`SpecTermVocabulary`) rather than querying inside the resolver — keeps the resolver a pure classifier (unit tests need no Woo/DB and fully control the cache). Prod binding reads T1's `woo_attribute_terms`.
- **Band labels are the brief's strings verbatim** (e.g. `Standard (up to 350)`, `44-55`, `10000+ lumens`) — the only available source of truth for the live term names; belt-and-braces cache resolution means a mismatch surfaces as `band_term_not_cached` (unmatched) rather than inventing a term.
- **Exact-brightness labels are LOCAL only** (do NOT auto-derive a band from them) — matches D1 + the plan's spec-only list; bands come exclusively from the `Brightness Band (…)` / `Display Size` / `Room Size` label rows.
- **Room-size overlap tie-break = lower band wins** (brief boundaries 4/6/10 overlap); not covered by explicit brief tests, so a consistent documented rule was chosen.
- **No `depfile.yaml` / `deptrac.yaml` change** — every new class lives in the `ProductAutoCreate` domain and depends only on the in-domain `WooAttributeTerm` model + Laravel `Log` facade; the existing ProductAutoCreate layer already covers it (0 violations).

## Deviations from plan

None — plan executed as written. The task's suggested API (injectable term vocabulary) was adopted via the `SpecTermVocabulary` seam.

## Known stubs

None. The resolver is fully wired; `WooAttributeTermVocabulary` reads real cached rows; unmatched values are surfaced structurally (`ResolvedSpec::unmatched()`) + logged for T6.

## Constraints honoured

- No Woo I/O and no writes in the resolver (source-scan test enforces).
- No migration (T1 owns `woo_attribute_terms`); no `WOO_WRITE_ENABLED` change; `PublishProductJob` untouched (that's T3).
- No push / deploy. Atomic commits on `main`.
- Pre-existing working-tree noise NOT staged: deleted `storage/app/research/supplier-probe.json`, modified `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`.

## Self-Check: PASSED

- Files exist: `SpecTaxonomyResolver.php`, `ResolvedSpec.php`, `Spec/SpecTermVocabulary.php`, `Spec/ArraySpecTermVocabulary.php`, `Spec/WooAttributeTermVocabulary.php`, both test files — all present.
- Commits `13a6393` (feat) + `5a19482` (test) present in `git log`.
- 40/40 tests green; pint pass on touched files; deptrac 0 violations.
