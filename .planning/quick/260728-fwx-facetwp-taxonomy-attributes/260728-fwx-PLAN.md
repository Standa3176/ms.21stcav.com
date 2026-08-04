# 260728-fwx — New products create FacetWP-compatible `pa_*` taxonomy attributes (without reverting the cleanup)

**Status:** PLAN for review — NOT yet approved, NOT built. No code until operator signs off.
**Type:** multi-task feature (execute task-by-task as GSD quick tasks). All Woo I/O via `WooClient` (REST only).

## Goal
The operator moved 44 product attributes from local custom → global `pa_*` taxonomies and cleaned
categories/descriptions so FacetWP (and Woo layered nav) can filter — these filter ONLY taxonomies, never
postmeta. Two outcomes required:
1. **New products** created by the app must attach their filterable specs as `pa_*` TAXONOMY attributes
   (term-linked), so they appear in the facets.
2. **Nothing the app does may revert** the existing attribute / category / description cleanup on the 6,201
   live products.

## Established facts (from 2026-07-26/28 investigation — do not re-derive)
- **REST partial-merge:** every app write goes through `WooClient` and PUT is tunnelled through POST; WC
  treats updates as partial merges — only sent keys change. No path does a read-whole-product-then-write-back.
- **Attributes are safe from all recurring sync** — no scheduled job sends `attributes`. Existing-product
  attribute cleanup cannot be reverted by the daily sync.
- **Descriptions safe** except operator-pinned overrides (intended).
- **CATEGORY REVERT RISK (real):** nightly 23:00 `cutover:auto-sync --field=stock_quantity,buy_price,category_id`
  (routes/console.php ~:530) pushes local `categories` over Woo when they diverge (app = category source of
  truth). Dormant now (WOO_WRITE_ENABLED=false); ACTIVATES at cutover.
- **New-product attributes are LOCAL-style (the bug):** `PublishProductJob::wooAttributes()`
  (app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php:458-488) emits `{name, options:[value], visible,
  variation}` with NO taxonomy `id` → postmeta, invisible to FacetWP. Same shape in
  `ResyncProductsToWooCommand::buildResyncPayload` (~:288-296). `CreateWooProductJob` (draft path) sends NO
  attributes at all.
- **All 44 target `pa_*` taxonomies exist on the live store** (IDs confirmed — see the map in Task 2). Woo
  REST **auto-creates an unknown term** if you send an option string it doesn't recognise → re-pollutes the
  facet. So term resolution MUST be resolve-existing-or-log, never invent.
- Attribute VALUES for new products currently come from Claude-generated `attributes_json` (`{name,value}`),
  not a supplier CSV — so labels are inconsistent and encoding risk is low TODAY (but must be handled if a
  supplier spec feed is wired in later).

## OPEN DECISIONS — need operator answer before/at build
- **D1 — Brightness exact vs band (brief is internally inconsistent).** The 44-list includes exact
  `pa_brightness-cdm2` (3531) as a taxonomy, but the banding section says the precise cd/m² figure "stays
  local, spec-table only." Recommend: **bands are the filters** (`pa_brightness-nits` 3518 = cd/m² band,
  `pa_brightness-lumens` 3554 = lumens band); **exact cd/m² / lumens stay LOCAL** spec rows (exact values =
  hundreds of terms = unusable facet). Confirm. Also enforce "never mix units" (a product carries lumens OR
  cd/m², never both).
- **D2 — Category-revert fix approach.** (a) Drop `category_id` from the nightly `cutover:auto-sync` --field
  list (app stops pushing categories entirely — simplest, safest for the cleanup), OR (b) reconcile app-local
  categories to the cleaned Woo state first (re-import Woo→local) so there's no divergence and the app can
  stay category source-of-truth. Recommend (a) now; (b) later if the app must own categories. Confirm.
- **D3 — FacetWP indexing.** Confirm whether FacetWP auto-indexes REST-created products (empirical test in
  Task 7). If it does NOT, a reindex trigger is needed and the REST-only app can't run `wp facetwp index` —
  would need a WP-side cron or an operator step. Decision deferred to the Task-7 result.

## Tasks (ordered; each an atomic executable unit)

### T0 — Category-revert guard (protective, do first) [pending D2]
Per D2(a): remove `category_id` from the scheduled `cutover:auto-sync` field set so the app never pushes
`categories` on the nightly self-heal. Add a comment referencing this plan. (If D2(b) chosen instead, T0 is
a Woo→local category reconcile command run once before cutover.) Verify via `schedule:list` + a test that the
scheduled command's field set excludes `category_id`. Behaviourally inert until cutover regardless.

### T1 — `spec:sync-taxonomy-cache` (prerequisite infra)
The resolver needs each `pa_*` attribute's CURRENT term vocabulary. Build a READ-ONLY command that pulls all
44 attributes' terms (`GET products/attributes/{id}/terms?per_page=100`, paginated, retry-with-backoff for
the flaky endpoint) into a local cache (a `woo_attribute_terms` table or a cached structure): attribute_id,
slug, term_id, term_name, term_slug. Schedule it (e.g. nightly) so the vocabulary stays fresh. This isolates
term lookups from the create hot-path and avoids hammering Woo per product. READ-ONLY; no writes to Woo.

### T2 — `SpecTaxonomyResolver` service (the core)
Single source of truth mapping a raw `{label, value}` spec row to one of: GLOBAL taxonomy attribute /
LOCAL spec attribute / UNMATCHED (logged). Holds:
- **The 44 label→slug→id map** (confirmed live IDs):
  Resolution=pa_resolution/3429 · Display Size Band=pa_screen-size-band/3516 · Mount Type=pa_mount-type/3517 ·
  Connectivity=pa_connectivity/3273 · Brightness Band (cd/m²)=pa_brightness-nits/3518 · Brightness Band
  (lumens)=pa_brightness-lumens/3554 · Warranty=pa_warranty/3498 · HDR=pa_hdr-support/3519 · Display
  Technology=pa_display-tech/3520 · Refresh Rate=pa_refresh-rate-hz/3521 · Viewing Angle=pa_viewing-angle-deg/3524 ·
  Panel Type=pa_panel-type/3543 · Touchscreen=pa_touchscreen-yn/3550 · Touchscreen Size=pa_touchscreen-size-in/3551 ·
  Touch Technology=pa_touch-tech-2/3540 · Touch Points=pa_touch-points/3541 · Projection Technology=pa_projection-tech/3529 ·
  Throw Type=pa_throw-type-2/3544 · Light Source=pa_light-source/3542 · Lens Shift=pa_lens-shift-2/3530 ·
  Screen Type=pa_screen-type-2/3526 · Tensioning=pa_tab-tensioned/3539 · Movement=pa_movement/3522 ·
  VESA=pa_vesa-standard/3533 · Max Load=pa_max-load-kg/3547 · Quick Release=pa_quick-release-2/3532 ·
  Material=pa_material/3364 · Colour=pa_colour/3268 · Length=pa_cable-length/3534 · Cable Category=pa_cable-category/3538 ·
  Connector Type=pa_connector-type/3535 · Shielding=pa_shielding-2/3537 · Fire Rating=pa_fire-rating/3536 ·
  Impedance=pa_impedance-ohms-2/3523 · Power Output=pa_power-output-w/3549 · Speaker Type=pa_speaker-type-2/3545 ·
  Noise Cancellation=pa_noise-cancelling/3527 · Noise Level=pa_noise-level-db/3528 · Microphone=pa_microphone-type-2/3525 ·
  IP Rating=pa_ip-rating/3546 · Field of View=pa_field-of-view-deg/3548 · Platform Certification=pa_platform-certified/3552 ·
  Room Size=pa_room-size-band/3553. (Keys matched on normalised Woo name + a tolerant ALIAS table for common
  Claude label variants, e.g. "Screen Resolution"→Resolution, "Display Size"→Display Size Band.)
- **LOCAL/spec-only labels** (never taxonomies): MPN, Model, Part Number, exact Brightness (cd/m²), exact
  Brightness (lumens), plus any label not in the map → passed through as a local spec row.
- **Value→term resolution:** look up the attribute's cached terms (T1); match case-insensitively; apply the
  value-maps (e.g. RESOLUTION_MAP `4K`/`4K UHD`/`3840x2160`→`4K UHD (3840x2160)`). Exact existing term only —
  **unmatched values are LOGGED (structured), never sent** (prevents Woo auto-creating a dup term).
- **Band derivation:** for `pa_screen-size-band`, `pa_brightness-nits`, `pa_brightness-lumens`,
  `pa_room-size-band` — derive the band from the raw numeric value (inclusive, non-overlapping boundaries per
  brief); the raw figure goes to the companion LOCAL spec row. Enforce D1 "never mix units".
- **Output** per row: `{kind: global|local|unmatched, attribute_id?, term_id?/term_name?, local_name?, raw}`.
- Pure/unit-testable (cache injected); no Woo calls in the resolver itself (T1 owns the fetch).

### T3 — Rewrite `PublishProductJob::wooAttributes()` to build the payload via the resolver
Feed `attributes_json` through `SpecTaxonomyResolver`, then build the WC-REST `attributes` array:
- GLOBAL rows → `{id:<pa_ id>, options:[<term name/slug>], visible:true, variation:false, position}`.
- LOCAL rows → `{name:<label>, options:[<value>], visible:true, variation:false, position}` (unchanged shape,
  spec-table only).
- UNMATCHED → NOT sent; recorded to the unmatched log (T6).
Keep the same mechanism in `ResyncProductsToWooCommand::buildResyncPayload` (so a resync doesn't relocalise).
Decide (D-in-build) whether `CreateWooProductJob` (draft path) should also carry attributes, or whether specs
are only attached at publish — document the chosen single create path so every new product gets them.

### T4 — Category-path band subcategory on create
Confirm `PublishProductJob::categoryIds()` includes the size/lumens BAND child category (per brief: a 55"
display → `…Flat Panel Displays > 50-55 inch`). If band derivation (T2) is available, map the derived band to
its category child id and include it in the `categories` payload. Read-only category resolution (reuse the
existing category taxonomy resolver).

### T5 — Encoding gate for supplier-sourced spec values
Where spec values originate from a supplier CSV/feed (future path), convert Windows-1252→UTF-8 with
multi-encoding detection (`mb_convert_encoding($v,'UTF-8','Windows-1252,ISO-8859-1,UTF-8')`) and a validation
gate rejecting rows matching `/\d\s*\?|\?\s*\d|cd\/m\?/`. For the current Claude-JSON path this is a guard
(assert no mojibake before a value becomes a term). Reuse `NormalisesEan`-style single-source concern.

### T6 — Unmatched-values report (keeps the vocabulary controlled)
Structured log + a small report (command or Filament panel) of every UNMATCHED label and value from creates,
so the operator can extend the maps deliberately instead of terms being silently invented. This is the
brief's "weekly report of unmatched values" requirement.

### T7 — Post-create verification guard + FacetWP-index empirical test
After a create POST, GET the product back and assert each intended GLOBAL attribute returned with a taxonomy
`id > 0` (proves it linked, not localised); on failure, log/alert loudly (a new product invisible to facets
is a silent revenue leak). Use the FIRST correctly-built product to answer D3: does it appear in a facet
WITHOUT a manual `wp facetwp index`? Record the answer; if no, add a reindex-trigger step (WP-side cron /
operator) — out of the REST app's reach, so flag as an ops dependency.

## Verify (per task, TDD)
Unit: resolver mapping (label alias resolves; spec-only stays local; unmatched logged not sent; band
derivation boundaries; never-mix-units). Feature: `PublishProductJob` payload contains global-taxonomy
attributes with the right IDs + resolved terms and no invented terms (stubbed WooClient + injected term
cache); resync keeps global shape; T0 schedule excludes category_id. `pint`; `deptrac 0`; `route:list` exit 0.
Driver-portable. No push/deploy by executors; operator deploys.

## Guardrails
- All Woo I/O via WooClient. RESOLVE-don't-invent on terms (hard rule — protects the cleaned facets).
- Do NOT change the true supplier price/stock sync payloads. Do NOT flip WOO_WRITE_ENABLED.
- Do NOT stage pre-existing working-tree noise. Atomic commits. Herd php84.
