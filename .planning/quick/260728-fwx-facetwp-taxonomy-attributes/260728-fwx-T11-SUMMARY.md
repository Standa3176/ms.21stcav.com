# 260728-fwx T11 — operator-answer normalisation (display-tech, Max Load, lumens, touchscreen, Movement, normalised-key) — SUMMARY

**One-liner:** Extended `SpecTaxonomyResolver` + `config/spec_taxonomy.php` with the T11
operator answers — a Display-Technology drop-list + Direct-lit/Direct/D-LED→LCD maps,
a dual-emit Max Load (exact `pa_max-load-kg` "{n} kg" AND derived `pa_max-load-band`),
the six-band lumens scheme with an ANSI-lumens LOCAL companion, a boolean Touchscreen
with an optional 3-way split (Touch Points / Touch Technology), a Full-Motion hyphen
keyword, and a GENERAL normalised-key resolution tier across all attributes — all still
RESOLVE-DON'T-INVENT (every candidate must match a live `woo_attribute_terms` term).
Projector Type inference is DEFERRED per the reference.

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `638d58d` | feat | config + resolver: display-tech drops/maps, dual Max Load, 6 lumens bands, touchscreen split, Full-Motion keyword, general normalised-key tier |
| `fa48362` | test | T11 coverage cases + extended seed vocabulary + two deliberately-changed cases |

(SUMMARY committed separately.)

## What changed (per task item)

### 1. Display Technology (`pa_display-tech`, 3520)
- **drop-list** (config `drop_values`, force-unmatch even when cached): Commercial
  Display, Interactive Flat Panel Display, Interactive Flat Panel, Video Wall Display,
  Commercial Signage Display, Interactive Touch Display, Stretch Display, Non-Interactive,
  Commercial TV, Digital, Flat Panel, Interactive Display, Interactive E-Board, Large
  Format Commercial Display — plus the other leaked cache terms with no real mapping
  (Indoor LED, LCD / Flat Panel, Flip-Chip CoB) so the verbatim + normalised-key tiers
  cannot resurrect them.
- **maps** (`keywords`): `Direct-lit LED` / `Direct LED` / `D-LED` → LCD; `Direct View
  LED, Flip-Chip CoB` → Direct View LED (the `direct view led` keyword wins first).
- **overrides**: the backlit-LCD product-type terms (`LED-backlit LCD`, `Direct LED-backlit
  LCD`, `Direct-lit LED-backlit LCD`) resolve to the real **LCD** term ahead of any
  verbatim self-match. Only the 11 real terms are ever emitted; never a leaked term.

### 2. Max Load — dual emit (new `max_load` config + `resolveMaxLoad()`)
- One Max Load value (labels Max Load / Max Load Capacity / Max Weight Capacity / Load
  Rating / Weight Capacity) emits BOTH global rows:
  - **exact** → `pa_max-load-kg` normalised to canonical `"{n} kg"` (space; `70kg`→`70 kg`),
    resolved against cache.
  - **band** → `pa_max-load-band` via `kg<=10 Up to 10 kg / <=25 11-25 kg / <=50 26-50 kg
    / <=100 51-100 kg / else Over 100 kg`, resolved (tolerantly) against cache.
- Each row is independently resolve-don't-invent (an uncached candidate is logged
  unmatched, never blocks the other row).

### 3. Lumens band — 6-band scheme (`BAND_TABLES['pa_brightness-lumens']`)
- New bands: `Under 3000 / 3000-3999 / 4000-4999 / 5000-6999 / 7000-9999 / 10000+ lumens`.
  The two OLD bands `3000-4999` and `5000-9999` are RETIRED and can never be derived.
- Exact figure → LOCAL companion `Brightness (lumens)` reformatted to `"{n} ANSI lumens"`.

### 4. Touchscreen — boolean + 3-way split (new `touchscreen` config + `resolveTouchscreen()`)
- Any value that DESCRIBES a touchscreen (`touch` keyword, a point count, or a touch
  tech) → **Yes**. Explicit `Yes`/`No` kept verbatim; a negative descriptor (`Non-touch`,
  `No touch`) → No.
- When a point count / tech is present, splits 3-way: Touchscreen=Yes + Touch Points
  (`{n}-point` → `pa_touch-points`) + Touch Technology (PCAP / IR / InGlass / Optical /
  IR Touch → `pa_touch-tech-2`). The extra rows emit ONLY when they resolve to a cached
  term (resolve-don't-invent).

### 5. Movement — Full-Motion hyphen
- Added `full-motion` keyword to `pa_movement` (`Full-Motion Articulating Arm` → Full
  Motion). Bare `Full-Motion` also resolves via the general normalised-key tier (§6).

### 6. General normalised-key tier (`matchNormalisedKey()` / `normaliseKey()`)
- New FINAL tier in `resolveValue()` after exact→alias: compares
  `strtolower(preg_replace('/[^a-z0-9]/i','',$raw))` of the value against the same-
  normalised cached term names, so case / hyphen / spacing variants resolve generically
  across ALL single-value attributes (subsumes `Full-Motion`, `Cat.6`, `USB C`, etc.).
  Ordering preserved: exact → alias/normaliser → normalised-key. Still resolve-don't-invent.

### 7. Projector Type (#3555) — DEFERRED (as instructed)
- The multi-signal inference engine (lumens / throw / lens / product-name) was **NOT**
  built in T11 — it needs product context the pure spec-row classifier does not receive,
  and must NOT infer "Installation" from description text (155/280 false positives).
- **No `pa_projector-type` label map was added.** A `Projector Type` row therefore
  continues to fall through to LOCAL (unchanged behaviour). Deliberately NOT turned into
  a taxonomy passthrough, because that would convert currently-LOCAL projector-type rows
  into unmatched-dropped rows — a regression, and it would strand the value for the future
  T8-class enrichment. If the operator later wants literal-value matches mapped, that is a
  one-line label-map add once the inference engine (or a literal-only passthrough) is
  designed. Left for a future T8-class task.

## Deviations from plan (deliberate behaviour changes — documented)

1. **Max Load now dual-emits** (was a single `pa_max-load-kg` row). Updated the existing
   "aliases Max Load Capacity" test (now asserts BOTH rows, exact `50 kg` + band `26-50 kg`).
2. **Lumens band boundaries changed** to the 6-band scheme. Updated:
   - Feature test `3500 lumens` → `3000-3999 lumens` (was `3000-4999 lumens`, now retired).
   - Unit test boundary provider rewritten to the 6-band terms.
3. **Max Load exact canonical form is now `"{n} kg"` with a space** (was `50kg`). Seed
   vocabulary updated to the space form to match the operator's canonical.
4. All other T11 additions are new behaviour with new tests; no other prior assertions changed.

## Known Stubs / provisional data

- **`max_load.band_attribute_id = 3556` is PROVISIONAL.** The real `pa_max-load-band` Woo
  attribute id was not known at T11 time (it exists nowhere in the codebase/reference).
  It is loudly flagged in `config/spec_taxonomy.php`. Because of resolve-don't-invent the
  band simply stays UNMATCHED in prod until the real id is set — no term is ever
  auto-created, so there is no pollution risk. **T12 MUST** confirm the id via
  `spec:sync-taxonomy-cache` and set it before the retroactive push.

## Constraints honoured

- No Woo I/O in the resolver (purity source-scan test still green); no migration; no
  `WOO_WRITE_ENABLED` change; no retroactive push; no push/deploy.
- Config-driven maps (display-tech, max_load bands, touchscreen keywords all in config).
- Atomic commits on `main`. Herd php84 (8.4.22) for every check.
- Pre-existing working-tree noise NOT staged: deleted
  `storage/app/research/supplier-probe.json`, modified
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`.

## Verification results

| Check | Result |
|-------|--------|
| `SpecTaxonomyNormalisationTest` (T9+T10+T11, DB-seeded from canonical terms) | **123 passed / 367 assertions** |
| `tests/Unit/ProductAutoCreate` sweep | **70 passed / 201 assertions** |
| `tests/Feature/ProductAutoCreate` + `tests/Feature/Console` sweep | **548 passed / 2166 assertions** |
| `pint` (resolver, config, both tests) | **pass** (`{"result":"pass"}`) |
| `vendor/bin/deptrac analyse` | **0 violations** |
| `artisan route:list --path=admin` | **exit 0** |

Unit + Feature sweeps run in SEPARATE invocations — the pre-existing `makeResolver()`
helper name-clash (documented in `deferred-items.md` / T10) makes a single combined run
fatal. Not a T11 regression.

## Self-Check: PASSED

- Files exist: `config/spec_taxonomy.php`, `SpecTaxonomyResolver.php`,
  `SpecTaxonomyNormalisationTest.php`, `SpecTaxonomyResolverTest.php`, this summary.
- Commits `638d58d` (feat) + `fa48362` (test) present in `git log`.
- 123 + 70 + 548 tests green; pint pass; deptrac 0 violations; route:list exit 0.
</content>
</invoke>
