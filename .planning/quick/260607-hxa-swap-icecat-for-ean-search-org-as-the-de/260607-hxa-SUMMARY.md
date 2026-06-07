---
phase: quick-260607-hxa
plan: 01
subsystem: products / integrations
tags: [products, integrations, ean-search, backfill, merchant-feed]
status: complete
requires:
  - "260607-g25 (Icecat fallback path that this plan replaces as default)"
  - "260607-cgd (products:backfill-merchant-feed base command)"
provides:
  - "App\\Domain\\Integrations\\Enums\\IntegrationCredentialKind::EanSearch"
  - "App\\Domain\\ProductAutoCreate\\Services\\EanSearchClient"
  - "config('integrations.ean_fallback_provider') — env-driven provider switch"
  - "Renamed outcome buckets: ean_lookup_no_match / ean_lookup_invalid_ean / ean_lookup_budget_exhausted / recovered_from_ean_lookup"
affects:
  - "App\\Console\\Commands\\BackfillMerchantFeedCommand (4-arg constructor, provider-aware cost arithmetic in hundredths-of-pence)"
  - "TestIntegrationAction dispatch (new EanSearch arm)"
  - "IntegrationCredentialResolver::resolveFromEnv (new EanSearch match arm)"
  - "config/services.php (new 'ean_search' block)"
  - "CLAUDE.md tech stack (new Product Enrichment Providers sub-section + Stack-by-Problem section 7)"
tech-stack:
  added:
    - "EAN-search.org (api.ean-search.org/api) — default GTIN reverse-lookup provider"
  patterns:
    - "Provider seam — provider resolved via config; per-SKU branch in BackfillMerchantFeedCommand selects EanSearchClient or IcecatClient"
    - "Silent-degrade-to-null (mirrors IcecatClient): every failure returns null, never throws"
    - "Token redaction in IntegrationLogger calls — T-260607hxa-01 mitigation"
key-files:
  created:
    - "app/Domain/ProductAutoCreate/Services/EanSearchClient.php (243 lines)"
    - "config/integrations.php (30 lines)"
    - "tests/Unit/Domain/Integrations/Enums/IntegrationCredentialKindEanSearchTest.php (66 lines)"
    - "tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php (162 lines)"
  modified:
    - "app/Domain/Integrations/Enums/IntegrationCredentialKind.php (+5 lines — new case + 4 match arms)"
    - "app/Domain/Integrations/Services/IntegrationCredentialResolver.php (+3 lines — new resolveFromEnv arm)"
    - "app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php (+1 line — new dispatch arm)"
    - "config/services.php (+13 lines — new 'ean_search' block)"
    - "app/Console/Commands/BackfillMerchantFeedCommand.php (provider-aware backfillEan + bucket rename + 4-arg constructor)"
    - "tests/Feature/Console/BackfillMerchantFeedCommandTest.php (+188/−81 — 4-arg stubs + ean_lookup_* assertions + Case G provider-switch)"
    - "CLAUDE.md (+11 lines — Product Enrichment Providers sub-section + Stack-by-Problem section 7)"
decisions:
  - "Provider switch is config-driven (`integrations.ean_fallback_provider`), not code-driven — A/B comparison is a single env-var flip, no rebuild needed"
  - "Cost arithmetic moved from tenths-of-pence (Icecat-only) to hundredths-of-pence so 0.03p/query is integer-representable without floats"
  - "`--max-icecat-spend-pence` flag name kept for operator muscle memory; it now caps whichever provider is active (ean_search at 3 hundredths/query, icecat at 20 hundredths/query)"
  - "IcecatClient + IntegrationCredentialKind::Icecat retained unchanged — Icecat stays the image-lookup source (SourceProductImagesCommand) AND the opt-in EAN-backfill fallback"
  - "TestIntegrationAction match arm landed in Task 1 (alongside the enum) rather than Task 2 — PHP lazy-resolves the class at runtime so the forward reference is safe; verified by Case G integration test"
metrics:
  duration: "44min"
  completed_date: "2026-06-07"
  tasks_completed: 6
  files_created: 4
  files_modified: 7
  pest_focused: "29 new tests across 3 files (6 enum + 8 EanSearchClient + 14 BackfillMerchantFeed + Case G replaces one prior G-25 case)"
  pest_full_suite_delta: "+15 passed / 0 new failures vs 260607-g25 baseline"
---

# Phase quick-260607-hxa Plan 01: Swap Icecat for EAN-search.org as default EAN-fallback provider — Summary

EAN-search.org is now the **default** GTIN reverse-lookup provider for
`products:backfill-merchant-feed`, replacing Icecat in that role. Icecat stays in
the codebase, owns image lookup (`SourceProductImagesCommand`), and remains
available as a forensic-A/B fallback (`EAN_FALLBACK_PROVIDER=icecat`).

## Why

The 260607-g25 Icecat fallback ran against 116 stuck premium B2B SKUs (Sony
FW-Bravia, Panasonic PT- projectors, PTZOptics, Roland VR-, BirdDog, Vivitek)
and matched **zero** — Icecat's index doesn't cover that channel-only AV
segment. EAN-search.org does cover it, and is **~7× cheaper** per query
(€0.003 vs ~£0.0024) with a free tier of 100 queries/day.

## Files Edited

### Created (4)

| Path | Lines | Purpose |
|------|-------|---------|
| `app/Domain/ProductAutoCreate/Services/EanSearchClient.php` | 243 | MPN→GTIN reverse-lookup client. Mirrors IcecatClient's silent-degrade-to-null pattern. Token redaction in logs (T-260607hxa-01). |
| `config/integrations.php` | 30 | New `ean_fallback_provider` env-bound config key. |
| `tests/Unit/Domain/Integrations/Enums/IntegrationCredentialKindEanSearchTest.php` | 66 | 6 Pest cases pinning enum shape + resolver env-fallback. |
| `tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php` | 162 | 8 Pest cases covering brand match, multi-row brand priority, empty, placeholder pass-through, HTTP errors, null brand, token-missing short-circuit, testConnection ok. |

### Modified (7)

| Path | Δ | Purpose |
|------|---|---------|
| `app/Domain/Integrations/Enums/IntegrationCredentialKind.php` | +5 | New `EanSearch` case + 4 match arms (requiredFields/label/color/urlFields). |
| `app/Domain/Integrations/Services/IntegrationCredentialResolver.php` | +3 | New `resolveFromEnv` arm — `['token' => config('services.ean_search.token', '')]`. |
| `app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php` | +1 | New dispatch arm → `EanSearchClient::testConnection`. |
| `config/services.php` | +13 | New `'ean_search'` block (token + base_url env-seam — env() lives inside config/ per guardrail). |
| `app/Console/Commands/BackfillMerchantFeedCommand.php` | +/− major | 4-arg constructor (EanSearchClient added); per-SKU branch on `config('integrations.ean_fallback_provider')`; bucket renames; hundredths-of-pence cost arithmetic; pre-flight banner with provider name. |
| `tests/Feature/Console/BackfillMerchantFeedCommandTest.php` | +188/−81 | `bindEanStub` rebuilt with 4-arg parent; EanSearchClient primary fake + IcecatClient throw-on-call sentinel; cases A-F migrated to `ean_lookup_*` assertions; new Case G covers `EAN_FALLBACK_PROVIDER=icecat`. |
| `CLAUDE.md` | +11 | New `Product Enrichment Providers` sub-section + section 7 in `Stack by Problem`. |

## Commits (5 atomic)

| # | Commit | Subject |
|---|--------|---------|
| 1 | `ddb2311` | feat(integrations): add EanSearch credential kind (260607-hxa) |
| 2 | `96cded7` | feat(ean-search): EanSearchClient with MPN→GTIN lookup (260607-hxa) |
| 3 | `f656102` | feat(products): swap Icecat for EAN-search.org as default backfill provider (260607-hxa) |
| 4 | `52285f5` | test(products): update backfill-merchant-feed test cases for ean-lookup rename (260607-hxa) |
| 5 | `c9704e7` | docs(claude-md): add EAN-search.org as default GTIN backfill provider (260607-hxa) |

Task 6 is verification-only (no commit per plan).

## Test Status

### Focused (all green)

| Filter | Result |
|--------|--------|
| `--filter IntegrationCredentialKindEanSearch` | **6 passed** / 11 assertions / 8.49s |
| `--filter EanSearchClient` | **8 passed** / 12 assertions / 10.21s |
| `tests/Feature/Console/BackfillMerchantFeedCommandTest.php` | **14 passed** / 57 assertions / 9.07s (cases A-G + dry-run/live/idempotent/--limit + 3 brand-path) |
| `--filter EnvUsageTest` | **3 passed** / 6 assertions / 12.96s |
| `--filter AutoCreatedPredicateTest` | **2 passed** / 16 assertions / 6.24s |

### Full Pest suite

| Metric | Baseline (260607-g25) | This run | Delta |
|--------|------------------------|----------|-------|
| Passed | 1,881 | **1,896** | **+15** |
| Failed (pre-existing) | 219 | 219 | 0 |
| Skipped | 3 | 3 | 0 |
| Duration | — | 1,278.63s | — |

**Zero new failures vs baseline.** The +15 new passing cases breakdown as 6 enum +
8 EanSearchClient + 1 new Case G + cases A-F kept their pass count (renamed
assertions, same intent).

## Tinker Smoke Probe

```
$ php artisan config:clear && php artisan tinker (final probe)
Enum case: ean_search
Required: token
Label: EAN-search.org (GTIN lookup)
Color: info
Provider: ean_search
```

All five values match the plan's expected output.

## Operator Post-Deploy Checklist

After this code is deployed to `ms.21stcav.com`:

1. **Sign up at https://www.ean-search.org** (free tier 100 queries/day, or
   paid €30/10k ≈ €0.003/query). Get the API token from the EAN-search
   dashboard.
2. **Open Filament:** `/admin/integration-credentials` → **New** button.
3. **Pick kind:** `EAN-search.org (GTIN lookup)`.
4. **Paste token** into the `token` field.
5. **Save** → click **Test connection** → expect green `Connection OK`.

Without the token configured, EAN-search calls return null gracefully and
the backfill command continues to write only supplier-derived EANs (no
crash, no incorrect data — silent-degrade pattern matches IcecatClient).

## Forensic A/B Comparison Runbook

To compare EAN-search.org vs Icecat on the same SKU set (e.g., the 116
stuck SKUs from 260607-g25):

```bash
# Run A: EAN-search.org (default)
php artisan products:backfill-merchant-feed --field=ean --dry-run --limit=20

# Run B: Icecat (one-shot env override, no code change)
EAN_FALLBACK_PROVIDER=icecat php artisan products:backfill-merchant-feed --field=ean --dry-run --limit=20
```

Diff the printed outcome tables. Sample-row "Source" column shows the
active provider per row (`ean_search` or `icecat`) — useful when both
providers are mixed in a multi-run forensic comparison.

To flip the default permanently (e.g., if EAN-search.org has an outage):

```bash
# In .env on prod:
EAN_FALLBACK_PROVIDER=icecat
php artisan config:clear   # deploy.sh runs config:cache so this is needed
```

## STATE.md row append text

```
| 2026-06-07 | **EAN-search.org swap — replace Icecat as default GTIN backfill provider.** Today's 260607-g25 Icecat fallback matched 0/116 premium B2B SKUs (Sony FW-Bravia, Panasonic PT-, PTZOptics, Roland, BirdDog, Vivitek) because Icecat doesn't index that channel-only AV segment. EAN-search.org DOES cover it and is ~7× cheaper (€0.003/query vs Icecat's ~0.2p ≈ £0.0024; free tier 100/day). New `IntegrationCredentialKind::EanSearch` (token-only) + `EanSearchClient` (mirrors IcecatClient's silent-degrade-to-null pattern; brand-match logic picks first row whose `name` contains the brand string; token redacted in IntegrationLogger calls). BackfillMerchantFeedCommand 4-arg constructor + per-SKU branch via `config('integrations.ean_fallback_provider')` (default `ean_search`, flip to `icecat` via `EAN_FALLBACK_PROVIDER=icecat`). Bucket renames: `recovered_from_icecat → recovered_from_ean_lookup`, `icecat_no_match → ean_lookup_no_match`, etc. Cost arithmetic switched to hundredths-of-pence so 0.03p/query is integer-representable. `--icecat-fallback` flag kept as alias (DEPRECATED label); `--no-icecat-fallback` opt-out preserved. IcecatClient + Icecat enum case + SourceProductImagesCommand UNTOUCHED — Icecat retains image-lookup duty. Pest +15 GREEN (6 enum + 8 EanSearchClient + new Case G provider-switch); full suite delta vs 260607-g25 baseline: 1,896 / 219 / 3 (+15 pass / 0 new fails). **Post-deploy operator action:** sign up at ean-search.org, paste token via /admin/integration-credentials → New → kind=EAN-search. Quick task [260607-hxa](./quick/260607-hxa-swap-icecat-for-ean-search-org-as-the-de/) | feat(integrations) | ddb2311 + 96cded7 + f656102 + 52285f5 + c9704e7 |
```

## Deviations

**None — plan executed exactly as written**, including the Task 3 → Task 4
intentional intermediate breakage (acknowledged in plan Task 3 "done"
criteria: constructor-arity errors in the test file are expected until Task 4
lands the matching test-side rename). Five atomic commits landed in plan
order; no Rule 1-3 auto-fixes triggered; no Rule 4 architectural decisions
needed.

One minor build-order observation worth flagging for future plans:

- **TestIntegrationAction match arm landed in Task 1** (not Task 2). The plan
  flagged this as acceptable ("Acceptable to defer the TestIntegrationAction
  edit to Task 2 if it's cleaner — call it out in the SUMMARY."). I kept it
  in Task 1 because PHP lazy-resolves `app(EanSearchClient::class)` at
  runtime — the forward reference is safe and reviewers see the
  enum+resolver+admin-wiring as a single coherent change.

## Self-Check: PASSED

- `app/Domain/ProductAutoCreate/Services/EanSearchClient.php` — FOUND
- `config/integrations.php` — FOUND
- `tests/Unit/Domain/Integrations/Enums/IntegrationCredentialKindEanSearchTest.php` — FOUND
- `tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php` — FOUND
- Commit `ddb2311` — FOUND
- Commit `96cded7` — FOUND
- Commit `f656102` — FOUND
- Commit `52285f5` — FOUND
- Commit `c9704e7` — FOUND
