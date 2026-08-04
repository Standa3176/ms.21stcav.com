# 260728-fwx T3 — Route publish + resync attributes through `SpecTaxonomyResolver` — SUMMARY

**One-liner:** New products (and manual resyncs) now attach filterable specs as
GLOBAL `pa_*` TAXONOMY attributes (term-linked, FacetWP-visible) by routing
`attributes_json` through T2's `SpecTaxonomyResolver` via a new shared
`WooAttributePayloadBuilder`; spec-only rows stay local, unmatched values are
never sent, and the draft path stays intentionally attribute-less.

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `5e7baa0` | test | Failing specs — global `pa_*` shape on publish + resync (T3 RED) |
| `8a4a5d5` | feat | `WooAttributePayloadBuilder` + rewire `PublishProductJob` & `ResyncProductsToWooCommand` (T3 GREEN) |

## What changed

1. **New `app/Domain/ProductAutoCreate/Services/WooAttributePayloadBuilder.php`** —
   the single shared builder. `build(array $rows): array` calls
   `SpecTaxonomyResolver::resolve()` and maps the `ResolvedSpec` buckets to the
   WC-REST `attributes[]` payload:
   - **GLOBAL** → `{id: <attribute_id>, options: [<resolved term_name>], visible: true, variation: false, position: <n>}`
   - **LOCAL** → `{name: <label>, options: [<value>], visible: true, variation: false, position: <n>}`
   - **UNMATCHED** → dropped (resolver already logged them for T6)
   - Deterministic ordering: GLOBAL rows first (resolver row order), then LOCAL,
     numbered `0..n` across the combined list. Returns `[]` when nothing resolves.

2. **`PublishProductJob::wooAttributes()`** (app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php)
   — body replaced with `app(WooAttributePayloadBuilder::class)->build($raw)`.
   Obtained via the container (not constructor/handle injection) because the job
   is queued (constructor carries only the two serialisable ids) and
   `wooAttributes()` sits below `buildCreatePayload()` off the method-injection
   seam. Empty/all-unmatched → `[]` → `buildCreatePayload()` omits the
   `attributes` key exactly as before.

3. **`ResyncProductsToWooCommand`** — now injects `WooAttributePayloadBuilder`
   (constructor DI, the command's existing convention) and its
   `buildResyncPayload()` calls `->build($raw)` instead of the old inline
   local-only loop. A manual resync therefore RE-GLOBALISES rather than
   re-localising. Duplication removed cleanly via the shared builder (no mirrored
   logic).

4. **`CreateWooProductJob` (draft path) — intentionally NOT touched.**

## CREATE-PATH DECISION (documented)

Attributes attach at the **PUBLISH** path (`PublishProductJob`), which is where a
product goes live and becomes filterable. `CreateWooProductJob` (the draft path)
sends **no attributes** and stays that way — drafts are not live/FacetWP-indexed,
so attaching taxonomy terms there is wasted work and a revert risk. Drafts gain
their attributes when they are published (`PublishProductJob` Path A flips the
draft to `publish`; a draft created without a `woo_product_id` goes through Path B
which now carries the global attributes). Verified: `CreateWooProductJob` contains
zero attribute/`SpecTaxonomy`/builder references.

## Before / after — attributes payload shape

Example row `attributes_json: [{name:'Resolution', value:'4K'}]` with `pa_resolution`
(3429) cached term `4K UHD (3840x2160)`:

**Before (local-only postmeta — invisible to FacetWP):**
```json
{ "name": "Resolution", "options": ["4K"], "position": 0, "visible": true, "variation": false }
```

**After (global term-linked taxonomy — FacetWP-visible):**
```json
{ "id": 3429, "options": ["4K UHD (3840x2160)"], "position": 0, "visible": true, "variation": false }
```

Spec-only row `{name:'MPN', value:'ABC-123'}` → stays local
`{ "name": "MPN", "options": ["ABC-123"], "position": 1, "visible": true, "variation": false }`.
Unmatched row (e.g. mappable label, value not a cached term) → **absent from the
payload entirely**.

## Verification results

| Check | Result |
|-------|--------|
| New `PublishProductGlobalAttributesTest` (3 cases: global / local / unmatched / empty) | **PASS** |
| New `ResyncProductsToWooGlobalAttributesTest` (resync produces global shape) | **PASS** |
| Existing `PublishProductJobTest` (14 cases; 1 adjusted — see below) | **PASS** |
| Existing `ResyncProductsToWooCommandTest` (3 cases, unchanged) | **PASS** |
| Full sweep `tests/Feature/ProductAutoCreate` + `tests/Feature/Console` | **420 passed / 1767 assertions** |
| `pint --test` (touched files) | **pass** |
| `deptrac analyse` | **0 violations** |
| `artisan route:list --path=admin` | **exit 0** |

Test proofs (from the specs):
- `{name:'Resolution', value:'4K'}` + cached term → POST `attributes` contains
  `{id:3429, options:['4K UHD (3840x2160)'], ...}`, and **no** local
  `{name:'Resolution', options:['4K']}`.
- `{name:'MPN', value:'ABC-123'}` → local `{name:'MPN', options:['ABC-123']}`.
- Unmatched value (`Colour`/`Rainbow`, no cached term) → absent from payload.
- Empty / all-unmatched `attributes_json` → no `attributes` key sent.
- Resync path produces the identical global-taxonomy shape.

## Deviations from plan

### Adjusted test (allowed by the task — hard-coded old local shape)

**1. [Test-shape] `PublishProductJobTest` "includes attributes[] … (Flatsome layout parity)"**
- **Found during:** GREEN run — the case hard-coded the pre-T3 local-only shape
  (name-keyed, deduped-by-lowercase-name, `position 0 = "brand"` case-dup, count 3).
- **Change:** rewritten to inject an `ArraySpecTermVocabulary` (so `Resolution`
  resolves) and assert the new shape — GLOBAL `{id:3429, options:['4K UHD (3840x2160)']}`
  first, `Brand` + `Connection` as LOCAL rows. The removed `attributes_json`
  `brand`/`Acme Duplicate` case-dup row was dropped from the fixture: the resolver
  classifies the whole set and does **not** dedupe by name (dedup was a property of
  the deleted local-only builder, not a stated requirement), so keeping it would
  assert behaviour T3 does not own.
- **Files modified:** `tests/Feature/ProductAutoCreate/PublishProductJobTest.php`
- **Commit:** `8a4a5d5`

No other deviations. Resolver (T2) and term cache (T1) untouched. Brand handling,
categories, description, price and every other payload key unchanged.

## Notable design note

`WooAttributePayloadBuilder`'s docblock `@see` references caused Pint's
`fully_qualified_strict_types` fixer to add `use` imports for `PublishProductJob`
and `ResyncProductsToWooCommand` (doc-only references). `deptrac analyse` reports
**0 violations** — the builder lives in the `ProductAutoCreate` domain layer and
the references are documentation-only, so the architecture boundary is intact.

## Known stubs

None. Both write paths are fully wired to the resolver; unmatched values are
withheld and already logged (T2) for the T6 report.

## Constraints honoured

- All Woo I/O still via `WooClient` (builder does no I/O — it only classifies via
  the resolver, which itself does no Woo/DB call).
- No migration. No `WOO_WRITE_ENABLED` change. No push / deploy.
- Atomic commits on `main` (RED test → GREEN feat).
- Pre-existing working-tree noise NOT staged: deleted
  `storage/app/research/supplier-probe.json`, modified
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`.
- PHP via Herd php84 for all checks.

## Self-Check: PASSED

- Files exist: `WooAttributePayloadBuilder.php`, both new test files present.
- Commits `5e7baa0` (test) + `8a4a5d5` (feat) present in `git log`.
- New + adjusted + full ProductAutoCreate/Console sweeps green (420 passed);
  pint pass; deptrac 0; route:list exit 0.
