# 260728-fwx T1 — `spec:sync-taxonomy-cache` + `woo_attribute_terms` — SUMMARY

**One-liner:** READ-ONLY (GET-only) artisan command that caches every global `pa_*`
attribute's current term vocabulary into a local `woo_attribute_terms` table, with
retry-with-backoff for the flaky Woo terms endpoint and a nightly schedule — the
prerequisite cache the upcoming `SpecTaxonomyResolver` (T2) reads to resolve spec
values to EXISTING terms without hammering Woo per product.

## Commits (on `main`)

| Hash | Type | Description |
|------|------|-------------|
| `81a368d` | feat | Migration + `WooAttributeTerm` model + `spec:sync-taxonomy-cache` command + nightly schedule |
| `dff7007` | test | Feature + Architecture coverage |

## What was built

1. **Migration** `database/migrations/2026_07_28_000000_create_woo_attribute_terms_table.php`
   — table `woo_attribute_terms`, driver-portable (SQLite tests / MariaDB prod).
2. **Model** `app/Domain/ProductAutoCreate/Models/WooAttributeTerm.php` — pure local
   mirror (never touches Woo); lives in the ProductAutoCreate domain alongside the
   T2 resolver that will consume it.
3. **Command** `app/Console/Commands/SyncTaxonomyCacheCommand.php` (`spec:sync-taxonomy-cache`):
   - `GET products/attributes?per_page=100` via `WooClient`; keeps every attribute
     whose slug starts with `pa_` (no special-case exclusion of pa_brand / pa_campaign-type*).
   - Per attribute: `GET products/attributes/{id}/terms?per_page=100`, paginated
     (stops on the first short/empty page, hard cap 50 pages).
   - **Retry-with-backoff** (linear, config-tunable — default 4 attempts / 500 ms base):
     a transient failure retries the WHOLE fetch, then — if still failing — is
     **reported** as a failed attribute (never silently dropped); other attributes
     still cache.
   - `updateOrCreate` on `(attribute_id, term_id)` (idempotent) + prunes stale terms
     no longer present, reporting the delta.
   - `--only=<comma slugs>` (pa_ prefix optional) and `--dry-run` (reports counts,
     writes nothing).
   - Prints a summary: attributes cached / total terms / per-attribute counts /
     pruned counts / any FAILED attribute.
4. **Schedule** `routes/console.php` — `spec:sync-taxonomy-cache` nightly at
   **02:40 Europe/London**, `withoutOverlapping(30)`, `onOneServer()`.

## Table schema (`woo_attribute_terms`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK auto-inc | |
| `attribute_id` | integer | **indexed** |
| `attribute_slug` | string(191) | e.g. `pa_material` |
| `attribute_name` | string(191) nullable | e.g. `Material` |
| `term_id` | integer | Woo term id |
| `term_name` | string(191) | HTML-entity-decoded |
| `term_slug` | string(191) nullable | |
| `created_at` / `updated_at` | timestamps | |

Constraints: `unique(attribute_id, term_id)` (`woo_attribute_terms_attr_term_unique`)
+ index on `attribute_id`.

## Verification results

| Check | Result |
|-------|--------|
| Pest (10 tests, 43 assertions) | **PASS** |
| `php artisan migrate` on SQLite (`--env=testing`) | **clean** (`woo_attribute_terms ... DONE`) |
| `pint --test` (6 touched files) | **pass** |
| `deptrac analyse` | **0 violations** |
| `route:list --path=admin` | **exit 0** |
| `schedule:list` | shows `spec:sync-taxonomy-cache` (`40 1 * * *` UTC = 02:40 BST) |

Test coverage: pa_* cached + non-pa_ excluded; pagination followed (short page
stops, page 3 never requested); transient failure retries then caches; permanent
failure reported but others cached; `--dry-run` writes nothing; `--only` filters
(with/without pa_ prefix); idempotent re-run (no dupes); stale prune; in-place
metadata update; **asserts NO Woo write call (GET only)** across every case.

## Decisions

- **Model placement:** `App\Domain\ProductAutoCreate\Models\WooAttributeTerm` — the
  T2 `SpecTaxonomyResolver` (already-existing `TaxonomyResolver` sibling) lives in
  this domain and is the sole consumer. Command sits in `app/Console/Commands`
  (deptrac-uncovered layer, same as every other WooClient-consuming command).
- **Exit code:** a per-attribute term-fetch failure is REPORTED loudly but the run
  still exits 0 (best-effort cache refresh; partial result is useful and the nightly
  job shouldn't flap on one flaky attribute). Only a failure of the top-level
  `products/attributes` fetch is fatal (exit 1).
- **Retry granularity:** retries the whole paginated fetch (not a single page) so a
  half-read vocabulary can never reach the prune step and delete real terms.

## Deviations from plan

None — plan executed as written. Retry-with-backoff reuses the 260726-slw/egr flaky-
endpoint lesson (retry then report). Backoff is config-tunable
(`services.woo.taxonomy_terms_backoff_ms` / `_max_attempts`) so tests set it to 0.

## Known stubs

None. The command is fully wired to `WooClient` (GET) and the local table; no
placeholder data paths.

## Constraints honoured

- READ-ONLY vs Woo (only `GET`); all Woo I/O via `WooClient`; no `WOO_WRITE_ENABLED`
  change; no push/deploy.
- Pre-existing working-tree noise NOT staged: deleted `storage/app/research/supplier-probe.json`,
  modified `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`.

## Self-Check: PASSED

- Files exist: migration, model, command, both tests, schedule edit — all present.
- Commits `81a368d` + `dff7007` present in `git log`.
