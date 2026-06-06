# Findings — exit-on-first-non-empty resolver audit (260606-q7h)

## Scope

9 scouted files in `app/Domain/` + targeted grep sweep across the whole
`app/Domain/` tree for four shape patterns (terms-non-empty-return,
non-null short-circuit, null-coalesce between method calls, try/catch
reaching different data sources, "fallback" comment hits).

The 2026-05-31 production miss was `TaxonomyResolver::allBrands()` returning
a 1-term `pa_brand` taxonomy while ignoring the 176-term native
`/products/brands` taxonomy — silently fuzzy-matching Sony, Barco,
ViewSonic, Huddly all to "Huddlecamhd" for months. Fix `73ac682` inverted
the priority. This audit sweeps the other 8 scouted resolvers for the same
shape and scores severity against the rubric.

## Severity rubric (carried from PLAN)

- **HIGH** — two sources can BOTH legitimately hold data AND downstream
  consumers trust the result silently (Huddlecamhd shape). FIX in Task 3.
- **MEDIUM** — order-dependent but documented intent (e.g. "DB wins over
  env" in a docblock). DOCUMENT only.
- **LOW** — clean fallback where one source is intentionally authoritative
  AND the other is failover only. DOCUMENT only.
- **N/A** — single source, no multi-source resolution shape at all.
- **SANITY** — known fixed site; verify the fix is intact.

## Findings Table

| # | File:line | Severity | One-sentence explanation | Proposed fix |
|---|-----------|----------|--------------------------|--------------|
| 1 | app/Domain/Agents/Services/AgentRegistry.php:32 | N/A — single source | In-memory `$registry` array of kind→class; throws on miss; not multi-source. | none |
| 2 | app/Domain/Agents/Support/BrandSlugResolver.php:56 | LOW | Single authoritative source (`brands.slug` column); the "fallbacks" are degraded paths (`(string) $brandId`) when the table is missing or the DB throws — not a competing data source. Per-`brandId` `rememberForever` cache; tests `Cache::flush()` in setUp. | none |
| 3 | app/Domain/Integrations/Services/IntegrationCredentialResolver.php:51,60 | MEDIUM | DB row primary, env config fallback. Docblock explicitly declares the lookup order (D-06) and that env fallback is PERMANENT (D-08) for CI/test/initial-deploy ergonomics. Required-fields check on BOTH paths means a partial DB row correctly falls through to env — documented and tested. Order-dependent but intent is explicit. | document only — already canonical |
| 4 | app/Domain/Pricing/Services/RuleResolver.php:41,73,93,114,141 | LOW | NOT a multi-source resolver — a most-specific-wins chain (override → brand_category → category → brand → default_tier). Each layer is a distinct *specificity* level, not a competing data source. Throws `NoPricingRuleMatchedException` when nothing matches (fail-loud). Purity test enforces no clock/cache/config reads. | none |
| 5 | app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php:68,74 | N/A — single source | Single taxonomy (`wp/v2/product_brand`). The slug-collision retry (`{slug}` → `{slug}-brand`) is a retry on the SAME endpoint, not a second data source. Term list cached 1h. | none |
| 6 | app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php:256 | SANITY | 73ac682 fix intact: `/products/brands` (native) is primary at line 261, `pa_brand` global attribute fallback at line 273. Docblock dated 2026-05-31 explains the inversion and references the Huddlecamhd miss. `if ($terms !== []) { return $terms; }` shape at lines 262 and 274 is the canonical "exit on first non-empty source" — but ordered correctly. | none |
| 7 | app/Domain/Suggestions/Services/SuggestionApplierResolver.php:27 | N/A — single source | In-memory kind→class map; throws on miss. Same shape as AgentRegistry. | none |
| 8 | app/Domain/Sync/Services/SupplierSkuRegistry.php:34 | N/A — single source | NOT a resolver. Materialises remote `feeds_products` rows into local `supplier_sku_cache` (single column PK). Single upstream source. No "competing data source" semantic at all. | none |
| 9 | app/Domain/TradePricing/Services/TradeRuleResolver.php:55,72,90,112,134,164,175 | LOW | Decorator over `RuleResolver` with a 5-layer most-specific-wins chain (override → group+brand+category → group+category → group+brand → group default_tier → delegate to base). Same specificity-chain semantic as `RuleResolver` — not competing sources. Retail fast-path (`$customerGroupId === null \|\| 0`) delegates byte-identical to v1. Byte-identity test pins `resolve()` body sha256. | none |

## Grep-sweep extras (de-duped against the 9 scouted files)

Pattern A (`if ($terms !== []) { return $terms; }`) — 2 hits, both inside
`TaxonomyResolver.php` already audited (row 6 above).

Pattern B (non-null short-circuit return) — top hits already covered:
- `IntegrationCredentialResolver.php:51,60` (row 3)
- `CRM/BitrixClient.php:88-90,103-105` — `$shadow !== null` short-circuit
  for `WOO_WRITE_ENABLED=false` shadow-mode (NOT multi-source resolution;
  this is a write-side gate that returns a synthetic id or `void` when
  writes are disabled — single authoritative path, dry-run-style early
  return). → N/A — single source, dry-run gate.

Pattern C (`?? $this->method()`) — 8 hits in
`CutoverChecklistReporter.php:69,84,92,98,105,161,168,183`. Shape:
`$state[$key] ?? $this->checkX()`. The `$state` array is the manually
operator-overridden disposition map; the `checkX()` method is the
automated probe. → LOW — the explicit operator override (left side) is
intentionally authoritative; the automated probe is the fallback when no
override has been recorded. Single intent, well-documented; not the
Huddlecamhd shape (no second data source competing — it's
human-override-vs-automated-probe).

Pattern D ("fallback" case-insensitive) — 43 files. Spot-checked the
non-scouted hits:
- `Sync/WooClient.php` — Woo SDK request-shape fallback when the
  Automattic client returns scalar vs. array; same single endpoint.
- `Agents/Clients/ClaudeClient.php` — Prism finish-reason fallback to
  local `Error` enum case; single source, defensive mapping.
- `ProductImageFetcher.php`, `IcecatClient.php`,
  `WebImageSearchClient.php` — image-source fallback chain DOES exist
  here (Icecat → web search → Claude vision validation). This is a
  proper multi-source resolution but it's **chained pipeline**, not
  "exit on first non-empty" — each step is a different concern
  (structured product data vs. web hit vs. validation), and the
  validator (Claude vision) explicitly evaluates each candidate before
  picking. Out of audit scope (not a same-data-different-source race),
  but flagged here for future awareness.
- `IntegrationCredentialResolver.php` — already audited (row 3).
- `Pricing/Filament/Resources/PricingRuleResource.php` — UI strings
  only.

Pattern E (`try { ... } catch { ... return X; }`) — 40 hits. Spot-checked
the multi-source candidates:
- `TaxonomyResolver.php:318` and `:356` — already audited (row 6); the
  catch in `allBrands()` lets it fall through to `pa_brand`, which is the
  canonical fix shape from 73ac682.
- `BitrixClient.php:74-77` — credential-resolution exception → returns
  empty webhook URL; single source.
- `WebImageSearchClient.php:194-197` — missing-credential exception →
  returns null (no image source); single source.
- `BitrixSchemaCache.php:85-90` — schema-cache lookup; cache miss →
  invalidate + return false; single source.

No additional HIGH findings discovered by the grep sweep.

## Summary

- HIGH:   0
- MEDIUM: 1 (IntegrationCredentialResolver — documented DB-wins-then-env)
- LOW:    4 (BrandSlugResolver, RuleResolver, TradeRuleResolver, CutoverChecklistReporter)
- N/A:    4 (AgentRegistry, ProductBrandTermResolver, SuggestionApplierResolver, SupplierSkuRegistry)
- SANITY: 1 (TaxonomyResolver::allBrands — 73ac682 intact)

## Why zero HIGH findings

The Huddlecamhd shape requires THREE conditions simultaneously:

1. Two sources can BOTH legitimately hold data for the same identity.
2. The resolver exits on first non-empty without sanity-checking the
   loser's content.
3. Downstream consumers silently trust the result (no volume check, no
   "this looks too small" sanity gate).

Across the 9 scouted resolvers + grep-discovered extras, every
multi-source path is either:

- **Authoritative-vs-degraded** (BrandSlugResolver, BitrixClient
  credential lookup) — the fallback is a degradation path, not a
  competing data source.
- **Most-specific-wins specificity chain** (RuleResolver,
  TradeRuleResolver) — each layer is a distinct hierarchical level.
- **Documented-intent priority** (IntegrationCredentialResolver — D-06
  DB-wins-then-env; CutoverChecklistReporter — operator override beats
  automated probe; TaxonomyResolver — native taxonomy beats legacy
  attribute, fixed in 73ac682).
- **Pipeline chain with validation** (image-source resolution in
  ProductImageFetcher → IcecatClient → WebImageSearchClient → Claude
  vision validation) — multi-source but each step does its own
  validation, not "exit on first non-empty".
- **Single-source** (AgentRegistry, SuggestionApplierResolver,
  ProductBrandTermResolver, SupplierSkuRegistry).

The 73ac682 fix on `TaxonomyResolver::allBrands()` was the only
multi-source resolver in `app/Domain/` where both sources legitimately
held data AND the consumer (`bestMatchId` fuzzy match) trusted the
result silently. After the inversion, the primary-volume source is
checked first and the secondary is degradation-only.

## Recommended next step

**HIGH == 0 → CASE A: skip Task 3.** Audit doc is the deliverable. Append
the "Decision" section below to record the call, then proceed to Task 4
(full Pest suite + SUMMARY.md).

## Decision (Task 2 — appended)

**CASE A — Clean bill: 0 HIGH findings. Task 3 SKIPPED.**

Rationale: across the 9 scouted files + grep-discovered extras, no
resolver matches the full Huddlecamhd shape (two sources legitimately
holding data + silent consumer trust). The 73ac682 fix already addressed
the only known instance, and the sanity row confirms it is intact.

Next: Task 4 runs the full Pest suite to confirm zero new failures vs the
260606-p4q baseline (1,826 / 219 / 3) and writes SUMMARY.md.
