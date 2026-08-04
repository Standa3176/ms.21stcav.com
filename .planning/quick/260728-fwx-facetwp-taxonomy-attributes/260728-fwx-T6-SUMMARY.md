# 260728-fwx T6 — `spec:unmatched-report` — SUMMARY

**One-liner:** READ-ONLY artisan command that scans every product's curated
`attributes_json` through T2's `SpecTaxonomyResolver` (reading ONLY the local
cached term vocabulary — zero Woo calls, zero writes) and aggregates a
catalogue-wide picture of how well specs resolve to the 44 `pa_*` taxonomies:
coverage + per-facet fill, unmatched values (candidates for value-alias maps),
and unmapped labels (candidates for the label-alias table) — so the operator can
extend the maps deliberately instead of terms being silently invented.

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `e6a9bfb` | feat | `spec:unmatched-report` command + Pest coverage (T6) |

## What was built

**`app/Console/Commands/SpecUnmatchedReportCommand.php`** (`spec:unmatched-report`)

Signature: `{--limit=0} {--status=} {--csv=} {--top=30}`.

- Iterates products with a non-empty `attributes_json`, chunked (`chunkById(500)`,
  `orderBy id`), optional `--status` filter (`publish`/`pending`/…; empty or
  `all` = every status), `--limit` cap (0 = all). Empty `[]` JSON is skipped
  without being counted (portable — no JSON-column string comparison in SQL).
- For each product runs `SpecTaxonomyResolver::resolve($attributes_json)` and
  folds the result into three aggregates:
  1. **Coverage** — products scanned, how many produced ≥1 GLOBAL taxonomy
     attribute (+ %), total global attrs, average global attrs/product, and a
     **per-taxonomy FILL table** for all **44** `pa_*` facets (products producing
     a global for each) so starved 0-fill facets are visible. The 44th
     (`pa_brightness-cdm2` 3531) is flagged `is_local` (LOCAL per D1 — always 0).
  2. **Unmatched values** — resolver `unmatched()` rows grouped
     `attribute_slug → distinct raw value → count`, with the primary `reason`
     (`value_not_a_term` / `band_value_not_numeric` / `band_term_not_cached` /
     `mixed_units`). Top-`--top` on console; full tail in CSV.
  3. **Unmapped labels** — labels present in `attributes_json` that fell through
     to LOCAL (not in the 44-map/alias, not spec-only). Known spec-only labels
     (MPN/Model/Part Number/exact brightness) are counted **separately** and
     **excluded** from the unmapped list so the operator isn't shown noise.
- `--csv=<path>` writes the full detail in four clearly-separated sections
  (`# SECTION: COVERAGE` / `TAXONOMY_FILL` / `UNMATCHED_VALUES` /
  `UNMAPPED_LABELS`), each with its own header. Directory auto-created. This is
  the command's ONLY output write — a report artifact at an operator-chosen path,
  never a catalogue/Woo write.

### Reuse-not-modify discipline

- `SpecTaxonomyResolver`, `WooAttributePayloadBuilder` and the `woo_attribute_terms`
  cache are used **as-is** — no changes. The command adds NO resolution logic; it
  only aggregates the resolver's existing output.
- Label classification for the "unmapped" analysis is driven by the resolver's
  OWN output (labels that produced a `global` or `unmatched` row ARE mapped
  taxonomies) — so the 44-map is **not** duplicated for that purpose. Only two
  small report-side artifacts are mirrored, each with a sync note: the 44-slug
  fill list (`FACET_ATTRIBUTES`, needed to show starved facets) and the 5
  spec-only labels (`SPEC_ONLY_LABELS`). `normaliseLabel()` matches the resolver's
  exactly.

## Verification results

| Check | Result |
|-------|--------|
| Pest `SpecUnmatchedReportCommandTest` (5 cases, 32 assertions) | **PASS** |
| `pint` (both touched files) | **pass** (`{"result":"pass"}`) |
| `deptrac analyse` | **0 violations** |
| `artisan route:list --path=admin` | **exit 0** |
| `artisan list` / `help spec:unmatched-report` | command + 4 options registered |

Test coverage (deterministic — seeds `woo_attribute_terms` so the injected
resolver resolves against a known vocabulary, no Woo/network):
- coverage counts (scanned=2, produced-global=1, total=2, avg=1) + per-taxonomy
  fill (`pa_resolution`/`pa_colour`=1, `pa_screen-size-band`=0,
  `pa_brightness-cdm2` `is_local`=1);
- unmatched-value grouping + reasons (`pa_resolution,8K,1,value_not_a_term`;
  `pa_colour,Rainbow,1,value_not_a_term`);
- unmapped labels (`Connection,2,unmapped`) with spec-only excluded
  (`MPN,1,spec_only`);
- band derivation counts as a filled global (55″ → `44-55 inch` term);
- `--limit=1` → scanned=1; `--status` omitted scans all statuses (pending
  included); `--status=publish` narrows;
- `--csv` writes the expected sections/headers/rows;
- **NO Woo call** (spy `WooClient` bound; `calls` empty) and **NO write**
  (`woo_attribute_terms`/`products` counts + product `attributes_json` unchanged).

## Prod command to run the report with a CSV

```bash
# On the prod VPS (Herd/native PHP). Read-only — safe regardless of WOO_WRITE_ENABLED.
php artisan spec:unmatched-report --status=publish --top=50 \
  --csv=storage/app/reports/spec-unmatched-$(date +%Y%m%d).csv
```

Console shows the coverage summary, the 44-row fill table and the top-N unmatched
values + unmapped labels; the CSV carries the full long tail (all four sections)
for working the map extensions. Run `--status=publish` first (the live facets),
or omit `--status` to include drafts/pending.

## Deviations from plan

None — plan executed as written. One implementation note: PHP 8.4's `fputcsv`
quotes fields containing spaces, so the test parses the CSV with `str_getcsv`
(row-array assertions) rather than raw-string matching — this makes assertions
robust to enclosure/quoting differences and is not a behaviour change.

## Known stubs

None. The command is fully wired to the real `SpecTaxonomyResolver` (via the
container-bound `WooAttributeTermVocabulary` → `woo_attribute_terms`) and produces
real aggregates; no placeholder/mock data paths.

## Constraints honoured

- **READ-ONLY:** no DB writes, no Woo calls (verified by test spy), no migration,
  no `WOO_WRITE_ENABLED` change. The only filesystem write is the operator-
  requested `--csv` report.
- Reused `SpecTaxonomyResolver` + payload builder **unchanged**. All within
  `ProductAutoCreate`/Console (command in `app/Console/Commands`, the
  deptrac-uncovered layer, same as T1's `spec:sync-taxonomy-cache`).
- No push/deploy. Atomic commit on `main`.
- Pre-existing working-tree noise NOT staged: deleted
  `storage/app/research/supplier-probe.json`, modified
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked
  `.claude/`.
- PHP via Herd php84 for every check.

## Self-Check: PASSED

- Files exist: `app/Console/Commands/SpecUnmatchedReportCommand.php`,
  `tests/Feature/Console/SpecUnmatchedReportCommandTest.php` — both present.
- Commit `e6a9bfb` present in `git log`.
- 5 tests green; pint pass; deptrac 0; route:list exit 0; command registered.
