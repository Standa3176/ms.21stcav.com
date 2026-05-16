---
phase: 12-c3-seo-content-agent
plan: 02
subsystem: agents
tags: [agents, seo-agent, tool-bodies, brand-slug-resolver, prism-enum-param, p12-c-mitigation, p12-g-fallback, p12-h-defence, seoagt-02]

requires:
  - phase: 12-c3-seo-content-agent
    plan: 01
    provides: 4 SeoAgent tool stubs at Tools/Seo/, shared TruncatingTool relocation at Tools/TruncatingTool.php, brand-voice markdown scaffold (_global.md + logitech.md), AgentRegistry seo binding, agents.seo.temperature config slot
provides:
  - ReadProductDraftTool real body — Product::query lookup, per-field 4096-char mb_substr cap, brand_slug resolution via BrandSlugResolver, {error:'not_found'} sparse-data path
  - ReadBrandStyleGuideTool real body — file_get_contents per-brand fallback to _global.md, 3072-char content cap, raw-string JSON output (P12-H: zero template rendering)
  - ReadSimilarShippedProductsTool real body — Option B eligibility query (status='publish' AND (completeness_score>=85 OR NULL)), P12-G global fallback, TruncatingTool 3-KB cap with reduceLargestArray halving products array
  - ProposeContentPatchTool tightened schema — withEnumParameter for `field` arg (Open Question O-1 RESOLVED YES; Prism v0.100.1 supports it) pinning the 4 valid values; body still no-op writer
  - BrandSlugResolver helper at app/Domain/Agents/Support/BrandSlugResolver.php — single source of truth for Product.brand_id → slug mapping, used here and by Plan 12-04 RunSeoAgentJob
  - 31 Pest cases / 84 assertions covering schemas, caps, eligibility queries, fallback paths, slug derivation, P12-H defence
affects: [12-03-prompt-blade-guardrail-config, 12-04-run-job-mapper-filament-sidebar, 12-05-batch-command-shield-verification]

tech-stack:
  added: []  # zero composer changes — built on Phase 8 Prism primitives + Plan 12-01 stubs
  patterns:
    - "Prism v0.100.1 withEnumParameter — RESOLVED Open Question O-1 from Plan 12-01. Read vendor/prism-php/prism/src/Tool.php line 199 to verify; signature is withEnumParameter(string $name, string $description, array $options, bool $required=true). Tighter Anthropic schema (model cannot emit out-of-range field name). Mapper at Plan 12-04 still validates as defence in depth."
    - "BrandSlugResolver centralisation (P12-C mitigation) — single static helper reads brands.slug column (NOT brand.name), with defensive fallbacks: brand_id=null → 'global'; brands table missing OR slug null → (string) brand_id. Caches per brand_id via rememberForever. Plan 12-04 RunSeoAgentJob will delegate to this helper for the same slug resolution."
    - "Option B eligibility query — status='publish' AND (completeness_score>=85 OR completeness_score IS NULL). The NULL clause covers ~5000 Phase 2-synced manual products that pre-date AutoCreate scoring; those are canonical MeetingStore voice examples. RESEARCH §Tool 3 selected approach over Option A (auto_create_status='published' only, which would miss legacy rows)."
    - "P12-G global fallback in read_similar_shipped_products — when category filter returns zero rows, drops the filter and re-queries globally with _fallback:'global' hint in the response. Agent knows the voice anchor is cross-category and can reflect that in reasoning."
    - "P12-H file-content opacity defence — ReadBrandStyleGuideTool reads via file_get_contents and serialises via json_encode ONLY. Zero template-renderer usage in the code path; the literal forbidden FQCNs are absent from the file (grep-asserted at Plan 12-02 acceptance)."

key-files:
  created:
    - app/Domain/Agents/Support/BrandSlugResolver.php
    - tests/Unit/Agents/Tools/Seo/ReadProductDraftToolTest.php
    - tests/Unit/Agents/Tools/Seo/ReadBrandStyleGuideToolTest.php
    - tests/Unit/Agents/Tools/Seo/ReadSimilarShippedProductsToolTest.php
    - tests/Unit/Agents/Tools/Seo/ProposeContentPatchToolTest.php
    - tests/Feature/Agents/Seo/BrandSlugDerivationTest.php
    - tests/Feature/Agents/Seo/BrandVoiceGlobalFileExistsTest.php
  modified:
    - app/Domain/Agents/Tools/Seo/ReadProductDraftTool.php (stub body → real Product::query + per-field cap + BrandSlugResolver delegation)
    - app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php (stub body → file_get_contents per-brand/global fallback + 3072 cap)
    - app/Domain/Agents/Tools/Seo/ReadSimilarShippedProductsTool.php (stub body → Option B query + P12-G fallback + real reduceLargestArray)
    - app/Domain/Agents/Tools/Seo/ProposeContentPatchTool.php (withStringParameter('field') → withEnumParameter — Open Question O-1 resolved YES)
  deleted: []

key-decisions:
  - "Open Question O-1 RESOLVED YES — Prism v0.100.1 ships withEnumParameter at vendor/prism-php/prism/src/Tool.php:199. Upgraded ProposeContentPatchTool's `field` arg from withStringParameter to withEnumParameter pinning the 4 valid SEO fields. The Anthropic-side schema now constrains the model's emission space; mapper at Plan 12-04 still validates for defence in depth (cheap belt-and-braces)."
  - "BrandSlugResolver centralised (NOT inlined per-caller) — both ReadProductDraftTool AND Plan 12-04 RunSeoAgentJob will resolve brand_id → slug. One implementation prevents the P12-C silent-degradation pitfall where two callers derive slugs differently."
  - "BrandSlugResolver tolerates a missing brands table — the current v2.0 schema has no brands table yet (brand_id on products is a stub identifier); Schema::hasTable('brands') guard returns (string) brand_id which then falls through to _global.md at the read tool. This means existing brand_id=5 products WITHOUT a brands row will route to global voice — graceful degradation, not an error. Plan 12-04 / Plan 12-05 will revisit when brands table lands."
  - "Per-field 4096-char cap in read_product_draft applied at tool level, NOT via TruncatingTool — single Product row payload is bounded by schema so 4 fields × 4096 chars ≈ 16 KB worst case (well over the 3 KB soft cap, but agent doesn't actually USE all 4 caps simultaneously — most fields are <500 chars in practice). Plan 12-02 explicitly chose tool-level caps over the more aggressive cap helper to preserve full-field visibility for the agent's current-state baseline."
  - "ReadSimilarShippedProductsTool reduceLargestArray strategy — halves the products array on cap pressure (preserves first N entries in DB order, which for 'shipped' rows means recent + lexicographic). Single-product last-resort trims long_description_first_500_chars to 200 chars rather than dropping the row. Mirrors Phase 10 ReadMarginHistoryTool's halve-then-trim pattern."

metrics:
  duration_minutes: 38
  tasks_completed: 3
  files_created: 7
  files_modified: 4
  files_deleted: 0
  tests_added: 31
  test_assertions_added: 84
  composer_changes: 0
  migrations: 0
completed-date: 2026-05-16

commits:
  - hash: 53ad8c8
    message: "feat(12-02): implement read_product_draft + read_brand_style_guide bodies"
  - hash: 713813a
    message: "feat(12-02): implement read_similar_shipped_products with Option B + P12-G fallback"
  - hash: 36e2f59
    message: "feat(12-02): tighten propose_content_patch field arg + BrandSlugResolver test"
  - hash: 9aa1646
    message: "docs(12-02): reword PHPDoc to satisfy P12-H grep acceptance criterion"
---

# Phase 12 Plan 02: SEO Tool Bodies + BrandSlugResolver + Enum-Typed Field Param Summary

Filling the 4 SeoAgent tool stubs from Plan 12-01 with real implementations — Product::query lookup with per-field caps, file-system reads with brand-slug fallback, Option B eligibility query with P12-G global fallback, and a no-op writer with the `field` arg upgraded to enum-typed (Open Question O-1 resolved YES against Prism v0.100.1). Also introduces BrandSlugResolver as the centralised single-source-of-truth for brand_id → slug routing (P12-C mitigation), which Plan 12-04's RunSeoAgentJob will delegate to.

## What Shipped

**Tier 1 — Tool bodies (SEOAGT-02):**

- `app/Domain/Agents/Tools/Seo/ReadProductDraftTool.php` — real `Product::query()->where('sku', $sku)->first()`. Returns documented schema with `sku, name, short_description, long_description, meta_description, brand_id, brand_slug, category_id, completeness_score, completeness_missing_fields`. Each string field capped at 4096 chars via `mb_substr` (T-12-02-03 DoS mitigation). Unknown SKU returns `{error: 'not_found', sku: $sku}` without throwing. `brand_slug` resolved via `BrandSlugResolver::forBrandId($product->brand_id)`.

  Sample response (existing Product):
  ```json
  {
    "sku": "LOGI-MEETUP",
    "name": "Logitech MeetUp Conference Camera",
    "short_description": "All-in-one ConferenceCam for small huddle rooms.",
    "long_description": "Long supplier description goes here.",
    "meta_description": "Logitech MeetUp — huddle-room camera.",
    "brand_id": 5,
    "brand_slug": "logitech",
    "category_id": 12,
    "completeness_score": 64,
    "completeness_missing_fields": ["long_description", "meta_description"]
  }
  ```

- `app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php` — real `file_get_contents` with per-brand → global fallback per RESEARCH §Tool 2 verbatim. `$slug = strtolower(trim($brand))`, then check `is_file(resource_path("agents/brand-voice/{$slug}.md"))`. Content capped at 3072 chars via `mb_substr`; `_bytes` reports total bytes on disk pre-cap. Empty/literal "global" arg routes directly to `_global.md`. P12-H defence: file content is treated as opaque string serialised via `json_encode` only — no template rendering anywhere in the code path (acceptance enforced by grep gate on the file).

  Sample response (per-brand happy path):
  ```json
  {
    "brand": "logitech",
    "source": "per-brand",
    "content": "# Logitech voice supplement\n\n> Extends `_global.md`. Read both...",
    "_bytes": 1024
  }
  ```

  Sample response (global fallback for unknown brand):
  ```json
  {
    "brand": "unknown-brand-xyz",
    "source": "global",
    "content": "# MeetingStore brand voice — global\n\n## Tone & voice...",
    "_bytes": 2099
  }
  ```

- `app/Domain/Agents/Tools/Seo/ReadSimilarShippedProductsTool.php` — real Option B eligibility query: `status='publish' AND (completeness_score>=85 OR completeness_score IS NULL)` (covers Phase 2-synced manual rows with null score AND AutoCreate-published rows with score ≥ 85). When `where('category_id', $cat)` returns zero rows, drops the filter and re-queries globally, marking the response with `_fallback: 'global'` (P12-G mitigation). Each product mapped to `{sku, name, short_description (200 chars), long_description_first_500_chars (500 chars), meta_description}`. Overall response capped at 3072 bytes via `TruncatingTool::capJson`; `reduceLargestArray` halves the products array on cap pressure (single-product last-resort trims `long_description_first_500_chars` to 200 chars).

  Sample response (category=12 with eligible rows):
  ```json
  {
    "category_id": 12,
    "limit": 5,
    "products": [
      {
        "sku": "LOGI-RALLY-BAR",
        "name": "Logitech Rally Bar All-in-One Video Bar",
        "short_description": "Premium 4K bar for medium rooms...",
        "long_description_first_500_chars": "<500 char snippet>",
        "meta_description": "Logitech Rally Bar — medium-room video bar."
      }
    ]
  }
  ```

  Sample response (category=99 with zero rows, P12-G fallback):
  ```json
  {
    "category_id": 99,
    "limit": 5,
    "products": [/* ...5 from any category... */],
    "_fallback": "global"
  }
  ```

- `app/Domain/Agents/Tools/Seo/ProposeContentPatchTool.php` — body still no-op (returns `{"acknowledged":true}` verbatim), but the `field` parameter upgraded from `withStringParameter` to `withEnumParameter('field', 'Which field to patch — one of: title, short_description, long_description, meta_description', ['title', 'short_description', 'long_description', 'meta_description'])`. Sample no-op response:
  ```json
  {"acknowledged":true}
  ```

**Tier 2 — BrandSlugResolver helper (P12-C mitigation):**

- `app/Domain/Agents/Support/BrandSlugResolver.php` — `final class` with one public method `forBrandId(?int $brandId): string`:
  - `$brandId === null` → returns `'global'` (defensive — Plan 12-05 eligibility filters these out, but if one slips through we route to global voice rather than crashing).
  - `Schema::hasTable('brands') === false` → returns `(string) $brandId` (current v2.0 schema has no brands table; this graceful path lets the helper compile against future schemas without blocking the agent).
  - `brands` row missing for `$brandId` OR slug null → `(string) $brandId`.
  - `brands` row with non-empty slug → that slug verbatim.
  - Caches per `brand_id` via `cache()->rememberForever("brand_slug.{$brandId}", ...)`.
  - Catches any `QueryException` / `Throwable` from the DB lookup and degrades to the numeric fallback rather than blocking an agent run.

**Tier 3 — Test coverage (31 cases / 84 assertions):**

- `tests/Unit/Agents/Tools/Seo/ReadProductDraftToolTest.php` — 5 cases / 19 assertions. Schema for existing Product, error response for missing SKU, 4096 cap on 10k-char fields, brand_slug numeric fallback when brands row absent (P12-C edge), brand_slug=null when product.brand_id=null.
- `tests/Unit/Agents/Tools/Seo/ReadBrandStyleGuideToolTest.php` — 6 cases / 18 assertions. Logitech per-brand happy path, unknown-brand global fallback, empty-string global, literal "global" arg, case normalisation (LOGITECH → logitech), 3072-char content cap with `_bytes` reporting pre-cap size.
- `tests/Unit/Agents/Tools/Seo/ReadSimilarShippedProductsToolTest.php` — 7 cases / 24 assertions. Schema with 5 category-matching products, Option B includes NULL completeness rows AND excludes low-score / draft rows, P12-G fallback on empty-category, exact 500-char `long_description_first_500_chars` slice, natural-availability when fewer than limit, 3-KB cap with `_truncated` hint, zero-global non-existent category returns empty array.
- `tests/Unit/Agents/Tools/Seo/ProposeContentPatchToolTest.php` — 5 cases / 13 assertions. name() returns 'propose_content_patch', asPrismTool() returns Prism\Prism\Tool with exactly 5 params (sku, field, before, after, reasoning), field is enum-typed with 4 valid values, no-op body always returns `{"acknowledged":true}`, description contains the 4 field names verbatim.
- `tests/Feature/Agents/Seo/BrandSlugDerivationTest.php` — 5 cases / 5 assertions. Returns brands.slug column value (NOT name), falls back to (string) brand_id when slug null, falls back when brands row missing, returns 'global' for brand_id=null, caches per brand_id.
- `tests/Feature/Agents/Seo/BrandVoiceGlobalFileExistsTest.php` — 1 case / 1 assertion. Mandatory `_global.md` file existence at resource_path.

## Sample JSON responses from tests (per <output> spec)

`read_product_draft` happy path: see Tier 1 above (`"sku":"LOGI-MEETUP"` ... `"completeness_missing_fields":["long_description","meta_description"]`).

`read_brand_style_guide` per-brand: `{"brand":"logitech","source":"per-brand","content":"# Logitech voice supplement...","_bytes":1024}`.

`read_brand_style_guide` global fallback: `{"brand":"unknown-brand-xyz","source":"global","content":"# MeetingStore brand voice...","_bytes":2099}`.

`read_similar_shipped_products` category match: `{"category_id":12,"limit":5,"products":[{"sku":"BIG-0",...}],"_truncated":true,"_total_available":10}` (when 10 products of 1000-char descriptions are seeded and the cap triggers).

`read_similar_shipped_products` P12-G fallback: `{"category_id":99,"limit":5,"products":[/* 5 cross-category */],"_fallback":"global"}`.

`propose_content_patch` no-op: `{"acknowledged":true}`.

## Resolution of Open Question O-1 (carried forward from Plan 12-01)

**Question:** Does Prism v0.100.1 support `withEnumParameter` for the `field` arg in ProposeContentPatchTool?

**Investigation:** Read `vendor/prism-php/prism/src/Tool.php`. Confirmed at line 199:

```php
public function withEnumParameter(
    string $name,
    string $description,
    array $options,
    bool $required = true,
): self {
    $this->withParameter(new EnumSchema($name, $description, $options), $required);
    return $this;
}
```

**Resolution: YES.** `field` arg upgraded from `withStringParameter` to `withEnumParameter` with the 4 valid values pinned. The Anthropic-side schema now constrains the model's emission space — the LLM cannot emit `'description'` or `'price'` (out-of-range field names that the mapper at Plan 12-04 would silently drop). Defence in depth is preserved: Plan 12-04 SeoAgentResultMapper still validates field against the same 4-value allow-list before bundling into the Suggestion payload.

**Validation:** `tests/Unit/Agents/Tools/Seo/ProposeContentPatchToolTest.php::"field parameter is enum-typed with the 4 valid SEO fields"` asserts `$tool->parametersAsArray()['field']['enum'] === ['title', 'short_description', 'long_description', 'meta_description']`.

## Deviations from Plan

### Rule 3 (auto-fixed blocking issue) — Missing brands table in current schema

- **Found during:** Task 1, writing BrandSlugResolver.
- **Issue:** The PLAN.md's BrandSlugResolver helper queries `DB::table('brands')->where('id', $brandId)->value('slug')`, but the current meetingstore-ops-app v2.0 catalogue has NO `brands` table — `brand_id` on products is currently a stub integer identifier with no FK destination. A direct DB query would throw `SQLSTATE 1 no such table: brands` on first call.
- **Fix:** Wrapped the lookup in `Schema::hasTable('brands')` guard PLUS a `try/catch` for any `QueryException | Throwable` from the underlying DB call. Both paths degrade to `(string) $brandId` numeric fallback. The per-brand file lookup in ReadBrandStyleGuideTool then falls through to `_global.md` because no `5.md` file exists — graceful degradation, not an error.
- **Files modified:** `app/Domain/Agents/Support/BrandSlugResolver.php` (added Schema::hasTable guard + try/catch).
- **Test added:** `BrandSlugDerivationTest::"falls back to (string) brand_id when brand row does not exist in brands table"` exercises this path explicitly.
- **Commit:** rolled into `53ad8c8` (Task 1).

### Rule 3 (auto-fixed blocking issue) — P12-H grep gate failed due to PHPDoc literal mentions

- **Found during:** Self-check after Task 3.
- **Issue:** Plan 12-02 Task 1 acceptance criteria included `grep -L "Blade::render\|@include" app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php` (expects the file to NOT contain those literal strings). My initial PHPDoc documented the security defence using those FQCNs verbatim ("NEVER passes the content through Blade::render or @include — the LLM sees the markdown as data, never as a template..."), tripping the grep.
- **Fix:** Reworded the PHPDoc to describe the defence without mentioning the forbidden literals — "The markdown is NEVER passed through any view-template renderer or directive-inclusion mechanism". Behaviourally identical; semantic intent preserved.
- **Files modified:** `app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php` (PHPDoc only).
- **Commit:** `9aa1646` (separate atomic docs commit).

No other deviations — plan executed as written. Rule 4 (architectural change) not invoked; no auth gates encountered.

## Authentication Gates

None encountered. All work is local DB / filesystem / Prism schema introspection — no external API calls, no auth boundaries.

## Known Stubs

None. Plan 12-02's primary purpose was to ELIMINATE the `{stub:true}` placeholder payloads from Plan 12-01's 3 read tools (ReadProductDraftTool, ReadBrandStyleGuideTool, ReadSimilarShippedProductsTool). Verified post-implementation: zero remaining `'stub' => true` references in `app/Domain/Agents/Tools/Seo/*.php`.

ProposeContentPatchTool remains intentionally a no-op writer per CONTEXT D-03 — this is the DESIGN, not a stub. The Plan 12-04 SeoAgentResultMapper will extract calls from `agent_run.tool_calls[]` post-loop; the tool body is the structured-contract output sink, not the writer.

## Known Edge Case for Plan 12-04

Per the <output> spec: **Product without `brand_id`**. Plan 12-05's eligibility query is documented to filter out drafts with `auto_create_status='needs_brand_or_category_assignment'`, so a Product with `brand_id=null` shouldn't reach Plan 12-04 in practice. But Plan 12-02's `BrandSlugResolver::forBrandId(null)` defensively returns `'global'` rather than crashing — that path is tested. If Plan 12-04 RunSeoAgentJob hits a Product with `brand_id=null`, the agent will simply read global brand voice (which is the correct degraded behaviour).

## Pinned Decisions for Plan 12-03 and Plan 12-04

- **BrandSlugResolver is the single delegation point.** Plan 12-04 RunSeoAgentJob's `brandSlug()` helper MUST call `BrandSlugResolver::forBrandId($product->brand_id)` — do not introduce a second slug-derivation path.
- **field parameter is enum-typed.** Plan 12-03's system prompt SHOULD reference the 4 valid values but does NOT need to add additional in-prompt validation language — the schema constraint is now enforced by Prism / Anthropic. Plan 12-04 mapper still validates as defence in depth.
- **Option B eligibility query is shared mental model.** Plan 12-05's batch command will filter `Product::where('auto_create_status', 'pending_review')` — that's a DIFFERENT predicate from the read_similar_shipped_products' status='publish' AND completeness>=85 query. Don't confuse the two: batch eligibility = "WHICH drafts to run the agent on"; shipped eligibility = "WHICH historical products to show the agent as voice examples".
- **brands table will eventually land.** When it does, no code change is needed in BrandSlugResolver — `Schema::hasTable('brands')` returns true, the DB lookup happens, and resolution works as designed. The defensive fallback paths simply stop being hit. Tests that seed `DB::table('brands')->insert([...])` already cover the future-state behaviour.

## Verification

```bash
php vendor/bin/pest \
  tests/Unit/Agents/Tools/Seo/ \
  tests/Feature/Agents/Seo/ \
  --stop-on-failure
```

**Result:** 43 passed (103 assertions) in 21.05s. Zero regression on Plan 12-01 FrameworkSmokeTest + TruncatingToolRelocationTest + PricingToolsObserveSoftCapTest (rerun separately: 47 passed / 153 assertions).

```bash
php vendor/bin/pest --filter="ReadProductDraftTool|ReadBrandStyleGuideTool|ReadSimilarShippedProductsTool|ProposeContentPatchTool|BrandSlugDerivation|BrandVoiceGlobalFileExists"
```

**Result:** 31 passed (84 assertions) — matches the success-criteria filter from the plan prompt.

## Threat Flags

None new beyond the plan's existing `<threat_model>` register (T-12-02-01 through T-12-02-04). All four mitigations are honoured by code + tests:

- **T-12-02-01** (Information Disclosure of supplier-only fields) — `ReadProductDraftTool` queries ONLY the columns enumerated in RESEARCH §Tool 1 schema; never selects `cost_pence`, `supplier_price_pence`, or `*_margin*`. Plan 12-03 SensitiveFieldsStrip guardrail is the second line.
- **T-12-02-02** (Brand voice markdown XSS via downstream Blade rendering / P12-H) — `ReadBrandStyleGuideTool` reads via `file_get_contents` + `json_encode` only; literal forbidden FQCNs (`Blade::render`, `@include`) grep-asserted absent in the file (commit `9aa1646`).
- **T-12-02-03** (DoS via massive long_description blowing AgentRun.tool_calls JSON column) — Per-field `mb_substr(..., 4096)` caps in `ReadProductDraftTool`; `TruncatingTool::capJson` 3-KB cap in `ReadSimilarShippedProductsTool`; both tested.
- **T-12-02-04** (Brand slug derivation mismatch — P12-C) — `BrandSlugResolver` helper uses `brands.slug` (NOT `brands.name`); `BrandSlugDerivationTest` enforces.

## Out-of-Scope Findings (deferred-items.md)

Pre-existing Architecture suite failures (18 cases including `PinnedQuotePricesSurviveRuleEditTest`) fail with `SQLSTATE 1 no such table: customer_groups`. These tests were authored in Phase 11 (commit `d2b9bb1`) and depend on tables/migrations that aren't present in the local SQLite schema. **Verified reproducible on the parent commit `ace2f96`** before any Plan 12-02 changes — these are pre-existing failures unrelated to Plan 12-02 scope.

Logged to `.planning/phases/12-c3-seo-content-agent/deferred-items.md` per the execution-flow scope boundary rules. Plan 12-02 does NOT attempt to fix these; out-of-scope per Rule 3 guidance.

## Self-Check: PASSED

- File `app/Domain/Agents/Tools/Seo/ReadProductDraftTool.php` — FOUND, contains `Product::query()`, `mb_substr`, `completeness_missing_fields`
- File `app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php` — FOUND, contains `_global.md`, `mb_substr($content, 0, self::CONTENT_CAP_CHARS)` (3072), `JSON_THROW_ON_ERROR`; does NOT contain `Blade::render` or `@include`
- File `app/Domain/Agents/Tools/Seo/ReadSimilarShippedProductsTool.php` — FOUND, contains `status`, `completeness_score`, `long_description_first_500_chars`, `_fallback`; extends `TruncatingTool`
- File `app/Domain/Agents/Tools/Seo/ProposeContentPatchTool.php` — FOUND, contains 4× `withStringParameter` + 1× `withEnumParameter` for the `field` arg, `'acknowledged' => true`, `'title, short_description, long_description, meta_description'`
- File `app/Domain/Agents/Support/BrandSlugResolver.php` — FOUND, exposes `forBrandId(?int $brandId): string`
- Test files (6) — ALL FOUND
- Commit `53ad8c8` — FOUND (Task 1)
- Commit `713813a` — FOUND (Task 2)
- Commit `36e2f59` — FOUND (Task 3)
- Commit `9aa1646` — FOUND (P12-H docs fix)
- Pest plan-scope suite — 43 passed (103 assertions), 0 failed
- Pest success-criteria filter — 31 passed (84 assertions), 0 failed
- PHP -l on every modified .php file — clean (11 files)
