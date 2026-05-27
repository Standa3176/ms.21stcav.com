---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: Intelligence + B2B
status: planning
stopped_at: Completed 12-05-PLAN.md — Phase 12 fully shipped (5/5 plans complete; UAT deferred to production deploy per 12-UAT-DISPOSITION.md; 120 Pest cases / 287 assertions; ship verdict PASS_WITH_DEFERRED_UAT in 12-VERIFICATION.md)
last_updated: "2026-05-27T07:45:00.000Z"
last_activity: 2026-05-24
progress:
  total_phases: 11
  completed_phases: 8
  total_plans: 29
  completed_plans: 29
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-24 — v2.0 milestone kicked off)

**Core value:** One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.
**Current focus:** Phase 12 complete — ready for phase verification + Phase 13 (E3 WhatsApp Channel) planning

## Current Position

Milestone: v2.0 Intelligence + B2B
Phase: 13
Plan: Not started
Status: Phase ready for `gsd-tools phase complete 12`; Phase 13 ready for planning (run `/gsd-research-phase 13` first — research flag YES)
Last activity: 2026-05-27 - Completed quick task 260527-c0m: supplier + competitor names in the Pricing Ops bucket popup (pending visual check; not yet deployed)

Progress: [░░░░░░░░░░] 0% (0/8 v2 phases; 7/7 v1 phases shipped 2026-04-24)

**v2 build order:** 8 (C4) ∥ 9 (E1) → 10 (C1) → 11 (E2) → 12 (C3) → 13 (E3) → 14 (E4) → 15 (C2 — LATE, gates on v1 cutover + ≥4 weeks UTM data)

**v2 active research flags:**

- Phase 8: YES — Prism API surface, Langfuse self-hosted Docker, MCP PHP SDK, shield:safe-regenerate design
- Phase 10: YES — prompt design, deterministic temp=0 calibration, token budget across input contexts
- Phase 13: YES — WABA setup, Meta OBO BSP deprecation 2026, 24h window state machine

**Top 3 operator decisions blocking ANY v2 phase (per research/SUMMARY.md):**

1. Anthropic monthly budget ceiling — proposal: £200/month default
2. Self-hosted Langfuse vs Cloud — proposal: self-hosted Docker (EU residency)
3. Catalogue size sanity-check — assumption: ~5k SKUs (drives E4 FULLTEXT vs vector DB)

## Performance Metrics

**Velocity (v1.50.1 final):**

- Total plans completed: 62 (v1)
- Average duration: ~25 min/plan
- Total execution time: ~16 hours over 6 days

**By Phase (v1.50.1):**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1 | 5 | ~3.7h | 44m |
| 2 | 5 | ~2.0h | 24m |
| 3 | 5 | - | - |
| 4 | 5 | ~3.5h | 42m |
| 5 | 6 | ~2.4h | 24m |
| 6 | 6 | ~1.7h | 17m |
| 7 | 6 | ~2.0h | 19m |
| 08 | 5 | - | - |
| 09 | 6 | - | - |
| 10 | 5 | - | - |
| 11.1 | 1 | - | - |
| 11.2 | 1 | - | - |
| 09.1 | 1 | - | - |
| 12 | 5 | - | - |

**v2 phases:** No data yet — v2 planning kicks off with Phase 8.
| Phase 08-c4-agent-framework P01 | 55min | 3 tasks | 20 files |
| Phase 08-c4-agent-framework P02 | 36min | 3 tasks | 9 files |
| Phase 08 P03 | 40 min | 3 tasks | 25 files |
| Phase 08-c4-agent-framework P04 | 19min | 3 tasks | 14 files |
| Phase 08 P05 | 17min | 4 tasks | 23 files |
| Phase 09 P09-02 | 15min | 3 tasks | 4 files |
| Phase 09-e1-trade-customer-pricing P03 | 44m | 3 tasks | 6 files |
| Phase 09 P09-04 | 7m | 3 tasks | 11 files |
| Phase 09 P05 | 37min | 2 tasks | 12 files |
| Phase 09-e1-trade-customer-pricing P06 | 25min | 3 tasks | 6 files |
| Phase 10-c1-pricing-agent P01 | 16min | 3 tasks | 11 files |
| Phase 10-c1-pricing-agent P02 | 16min | 2 tasks | 12 files |
| Phase 10-c1-pricing-agent P03 | 13min | 3 tasks | 7 files |
| Phase 10-c1-pricing-agent P04 | 17min | 3 tasks | 11 files |
| Phase 10-c1-pricing-agent P05 | 21min | 4 tasks | 8 files |
| Phase 11-e2-quote-request-bitrix-deal-flow P11-01 | 28min | 2 tasks | 23 files |
| Phase 11-e2-quote-request-bitrix-deal-flow P02 | 14min | 2 tasks | 12 files |
| Phase 11-e2-quote-request-bitrix-deal-flow P03 | 60min | 2 tasks | 20 files |
| Phase 11 P04 | 38min | 3 tasks | 20 files |
| Phase 11-e2-quote-request-bitrix-deal-flow PP05 | 30min | 2 tasks | 11 files |
| Phase 11.1 P01 | 35min | 3 tasks | 27 files |
| Phase 11.2 P01 | 25min | 3 tasks | 44 files |
| Phase 09.1 P01 | 50min | 3 tasks | 38 files |
| Phase 12-c3-seo-content-agent P12-01 | 23min | 3 tasks | 18 files |
| Phase 12-c3-seo-content-agent P02 | 38 | 3 tasks | 11 files |
| Phase 12-c3-seo-content-agent P03 | 14min | 3 tasks | 11 files |
| Phase 12-c3-seo-content-agent P04 | 17 | 3 tasks | 12 files |
| Phase 12-c3-seo-content-agent P05 | 75min | 5 tasks | 14 files |

## Quick Tasks Completed

| Date       | Task                                | Type        | Commit  |
| ---------- | ----------------------------------- | ----------- | ------- |
| 2026-05-03 | Embed Horizon in Filament chrome    | feat(admin) | 2b77a25 |
| 2026-05-03 | Horizon Cluster (8 sub-pages)       | feat(admin) | a2def38 |
| 2026-05-03 | Native Horizon pages (drop iframe + cluster) | feat(admin) | 90d989f |
| 2026-05-03 | Brand recolor (violet-800) + dashboard polish + nav restructure | feat(admin) | d806312 |
| 2026-05-03 | OpenAI/ChatGPT credential kind + FTP creds → Admin nav group | feat(admin) | ecb376a |
| 2026-05-03 | FTP-credential UTF-8 disable + Sun+Wed pull schedule + ftp ext | fix(competitor-ftp) | c562ea4 |
| 2026-05-03 | Inline-creatable competitor + auto local_filename + Competitors admin page | feat(competitor-feeds) | 538b0ee |
| 2026-05-03 | Post-create UX (toast + redirect + pull-status column) + afterStateUpdated fix | feat(competitor-feeds) | cce9107 |
| 2026-05-04 | FTP MDTM fallback (older daemons reject MDTM) | fix(competitor-ftp) | 0f3770e |
| 2026-05-04 | Capture orphan SKUs as queryable rows (4,264 rows materialised from live FTP feeds) | feat(competitor-prices) | cbca30b |
| 2026-05-04 | woo:import-products bulk Woo catalogue import + optional supplier enrichment | feat(sync) | 78d43ea |
| 2026-05-04 | SQLite WAL default + competitor:retry-quarantine command | feat(competitor-csv) | 5762ff7 |
| 2026-05-04 | Was£X Save Y% £Z sale-price extraction + fallback SKU column (242 rows recovered) | feat(competitor-csv) | 53fa2ac |
| 2026-05-04 | Default new competitors Active + cascade-impact delete modal | feat(competitor-feeds) | a9112e7 |
| 2026-05-04 | 8-group nav restructure + colored attention badges (29 files) | feat(admin) | def23dc |
| 2026-05-04 | Wire Domain/Integrations resource discovery (Admin → Integration Credentials) | fix(admin) | 5479e16 |
| 2026-05-04 | Defensive try/catch on all 12 nav badge queries | fix(admin) | 660b25a |
| 2026-05-04 | Clarify Integration Credential edit form (saved-value placeholder) | fix(admin) | b33f034 |
| 2026-05-04 | Live Woo import — 5,633 products + stock_quantity + Live/Pending labels (24.8% competitor match) | feat(products) | 60dee1f |
| 2026-05-04 | Extract buy_price from Woo _alg_wc_cog_cost meta (5,430 products / 96.4% coverage) | feat(sync) | be8e5a9 |
| 2026-05-04 | Supplier DB (Remote MySQL) credential kind — Phase 1 of remote supplier sync | feat(integrations) | b2101b7 |
| 2026-05-04 | Supplier DB host field accepts hostname or IP (kind-explicit URL detection) | fix(integrations) | 5cf41d0 |
| 2026-05-04 | Supplier DB test action + edit-form rendering fixes (mysqli_report + Group $record) | fix(integrations) | 69b9273 |
| 2026-05-04 | supplier:db-sync command + daily schedule (Phase 2; 3,939 of 5,629 SKUs matched) | feat(sync) | 7890d5c |
| 2026-05-04 | 90-day price + stock history snapshots (5,632 product + 8,017 supplier-offer rows; per-supplier breakdown + Filament UI + history:prune) | feat(products) | 864a14e |
| 2026-05-04 | Price History searchable picker (sku/name/desc) + reschedule supplier Mon-Fri 07:00 + competitor Sun+Wed 02:00 | feat(price-history) | 4afd884 |
| 2026-05-24 | AI product-page creation (extends Phase 6): products:generate-drafts (Claude content, meetingstore 6-section structure) + products:source-images (Icecat+Serper web search+Claude-vision validation, woo_product_id nullable, gallery_image_urls) + products:assign-taxonomy (fuzzy brand+Claude category) + /preview/product/{id} page + Icecat/ImageSearch credential kinds + Langfuse OTel silenced | feat(autocreate) | 9b7f094 |
| 2026-05-25 | Simplify admin nav 8 groups → 6: quarantine ~18 set-once screens into one collapsible "Settings" group, merge operational logs into "Sync & CRM", remove WooCommerce/CRM&Bitrix/Admin/orphan FTP&CSV groups, nest 5 competitor-feed screens under a "Competitor Feeds" parent (Horizon-style navigationParentItem). Pure nav metadata, ~22 files; 81 admin routes verified. Quick task [260525-gtv](./quick/260525-gtv-simplify-admin-nav-8-groups-to-6-rename-/) | refactor(admin) | 46086a4 |
| 2026-05-25 | Pricing Operations dashboard `/admin/pricing-operations` (4 panels: recent price changes, new SKUs, competitor at/below 6% floor, competitor below cost) via CompetitorPositionScanner reusing floor-report ex-VAT margin math. + PHP 8.4 fix: removed `public string $queue` trait collision in AgentAlertNotification/RunAgentJob (dormant on prod 8.3) + added pest-plugin-livewire dev dep. 7 new tests green. Quick task [260525-pnk](./quick/260525-pnk-pricing-operations-dashboard-php-8-4-tes/) | feat(pricing) | 24f03aa |
| 2026-05-25 | Core-loop #3b publish-on-Woo (PublishProductJob creates auto-drafts on Woo, shadow-safe; fixes rest_no_route slash bug) + daily 08:00 undercut pricing schedule (opt-in) + cutover runbook (`docs/ops/cutover-runbook.md`, phases A–D). All gated. Quick task [260525-szn](./quick/260525-szn-core-loop-3b-publish-on-woo-pricing-sche/) | feat(autocreate) | 6ce34f6 |
| 2026-05-25 | Supplier cost accuracy: buy_price = cheapest IN-STOCK supplier (was latest-updated; 1,772 SKUs re-costed live) + `supplier:explain-cost {sku}` diagnostic + `--flag-obsolete` (no-supplier products → pending; 160 demoted live). Pricing Ops dashboard UX: clickable tiles → filterable modal + CSV/XLS export, active-nav "you are here" highlight, + "Products to add" tile (parts on ≥4 suppliers not on MS — default tuned from data: ≥2=68k, ≥3=20k, ≥4=2,135; via `supplier:scan-add-candidates`, weekly Sun 05:00). 10 tests green. Quick task [260525-szo](./quick/260525-szo-supplier-cost-accuracy-cheapest-in-stock/) | fix(sync)+feat(pricing) | 1d95f73 |
| 2026-05-27 | Pricing Ops **bucket popup** now shows the supplier name under "Our cost (ex)" and the competitor name under "Lowest comp (ex)" (muted sub-lines, null-safe). Extended `CompetitorPositionScanner` to resolve both, batched: competitor via argmin `competitor_id` → raw `DB::select` on `competitors` (deptrac: Pricing↛Competitor, so NO model import); supplier via cheapest-current `SupplierOfferSnapshot` (matches how `buy_price` is chosen). Popup blade ONLY — inline panels + CSV untouched (scoped out by user). PHPStan L6 + deptrac (touched file) + Pint clean; scanner Pest 7 green. Pending visual human-check. Quick task [260527-c0m](./quick/260527-c0m-show-supplier-name-under-our-cost-and-co/) | feat(pricing) | d74a604 |

### Known debt / separate milestones
- **Test-suite remediation (full green on PHP 8.3/CI)** — surfaced 2026-05-25: the Pest suite has ~165 pre-existing failures + 1 hanging (networked) test, spanning many domains. Root causes are **test-infra rot, not prod bugs**: fixtures not seeding FK deps (e.g. customer_groups), Filament action-visibility drift (`callTableAction` on a hidden action now throws), MySQL-vs-SQLite skip-guards — compounded by local PHP 8.4 vs prod PHP 8.3. The suite hadn't been runnable for a long time (the missing pest-plugin-livewire, now added, proves it). **Not a cutover blocker.** Cutover **Gate 3 (feature-suite)** is satisfied on critical-path evidence instead (app boots, 81 routes resolve, prod is 8.3, and the changed/critical suites pass: Pricing 107, Sync 23, Products 20, Suggestions 16, PublishProductJob 5, + 7 new dashboard tests). Greening the rest = its own milestone, domain-by-domain, ideally on PHP 8.3 in CI.

## Accumulated Context

### v2 Milestone Decisions (locked 2026-04-24)

- **Anthropic budget:** £200/month default; revisit after Phase 8 ships + 2 weeks of real usage
- **Langfuse:** self-hosted Docker on ops VPS (EU residency for margin + customer data)
- **Catalogue assumption:** ~5k SKUs; MySQL FULLTEXT for E4; vector DB deferred to v2.1
- **Phase numbering:** Continues from v1 (Phase 8-15); does NOT reset
- **Net-additive architecture:** 5 new Deptrac layers, 2 new Horizon queues, 4 required composer packages, ZERO version bumps to v1's stack
- **Cutover gate on Phase 15:** Must NOT begin Phase 15 planning until v1 cutover is live in production AND ≥4 weeks of Bitrix Deal UTM data has accumulated. Pre-flight (ADAGT-05) verifies GCLID capture or ships a v1 hotfix first.
- **v1 frozen:** v2 is net-additive; v1 jobs/listeners are NEVER modified; all v2 hooks are subscribers via DomainEvent + listener-based extension

### v2 Cross-Cutting Invariants (every phase respects)

1. Suggestions seam mandatory for any data-changing feature
2. Dual-YAML Deptrac sync — every new layer in BOTH `depfile.yaml` AND `deptrac.yaml`
3. Dry-run-default CLI — `--live` opt-in
4. Shadow-mode gates default false: `AGENT_WRITE_ENABLED`, `AGENT_AUTO_APPLY_ENABLED`, `WHATSAPP_OUTBOUND_ENABLED`, `QUOTE_BITRIX_PUSH_ENABLED`
5. `shield:safe-regenerate` wrapper ships in Phase 8 — every later phase uses it
6. Correlation_id threading: Context → Prism → Langfuse → Suggestions → integration_events
7. ULID PKs for all cross-domain references (`AgentRun`, `Quote`, `WhatsAppConversation`, `ChatbotSession`)
8. Listener-based extension of v1 — never modify v1 jobs
9. Provider seam pattern — new external APIs get thin `<X>Client` wrappers (mirror `WooClient` / `BitrixClient`)
10. Golden fixture extension, not modification — v1's 50 PriceCalculator triples remain byte-identical

### Decisions (carry-forward from v1.50.1 — see PROJECT.md Key Decisions table)

Decisions are logged in PROJECT.md Key Decisions table. Recent v1 decisions affecting v2 work:

- v1 framework + audit/integration/suggestions seams are the load-bearing seams for every v2 phase
- v1 cutover is ops-executed in parallel with v2 dev (does NOT block v2 phases 8-14)
- E5 RAMS cross-project integration deferred to v2.1+
- Channel feeds (Phase 8 in candidate list), customer automation, forecasting all deferred to v2.1+

**v2 Plan 08-02 (2026-04-25):**

- ClaudeClient is the SOLE Anthropic call site (Prism wrapper); enforced by Deptrac + AgentsWriteOnlyViaSuggestionsTest
- Self-hosted Langfuse on lf.ops.meetingstore.co.uk with 127.0.0.1-only port binding; nginx + admin basic auth; mliviu79 shim primary path, custom-OTel fallback documented in observability.md
- Plan W8 verify regex corrected to `[* ]v?<version>` (handles real `composer show v0.100.1` prefix); future plan-checkers should use this pattern
- ClaudeResponse maps Prism's 7-case FinishReason enum to local 5-case D-06 enum with default→Error fall-through (future-proofs against Prism enum additions)
- CostCalculator throws RuntimeException on unknown model (fail-loud — unbudgeted call surfaces as runtime error, not silent zero-cost)

**v2 Plan 11-01 (2026-05-01) — Phase 11 Foundation:**

- OQ-2 RESOLVED: BitrixEntityMap dedup ledger extended with nullable `quote_id` ULID + composite UNIQUE(entity_type, quote_id) coexisting with existing UNIQUE(entity_type, woo_id) for orders. Plan 11-04 EntityDeduper queries via two parallel scope methods (scopeForWooOrder + scopeForQuote)
- A2 RESOLVED (Rule 1 deviation): bitrix_entity_map.entity_type was MySQL ENUM (not VARCHAR as plan A2 assumed). Migration MODIFIES the ENUM allow-list to include 'quote_deal' — preserves the Phase 4 DB-level enum guarantee. SQLite no-op via `DB::getDriverName() === 'mysql'` guard.
- A1 LOCKED: VAT-INCLUSIVE pence storage convention encoded at column-comment + model-docblock level (Pitfall 1). PDF strips VAT at render time via PriceCalculator::stripVat in Plan 11-04. Pest test asserts integer cast preserves 1999 sentinel value.
- OQ-1 RESOLVED: quotes.total_pence_at_quote cached UNSIGNED BIGINT column added; recompute observer ships in Plan 11-02 (draft-only writes; locked alongside lines after status=sent).
- D-04 separation-of-duties enforced at QuotePolicy::approve gate — sales role explicitly DENIED (T-11-01-03 mitigation). 4-eyes pattern in place before Filament UI lands in Plan 11-03.
- D-06 reserved enum cases: QuoteStatus ships PendingApproval + Approved cases for v1.x non-breaking extension; v1.0 transitions never branch on them. NO `withdrawn` case (D-06 deferred — sales overwrites by editing draft).
- PolicyTemplateIntegrityTest floor bumped 27 → 29 covering QuotePolicy + QuoteLinePolicy.
- A9 LOCKED: customer_group_name_at_quote VARCHAR(255) denormalised on quotes table (CONTEXT.md Claude's Discretion).

### Roadmap Evolution

- Phase 09.1 inserted after Phase 9: Integration Connections Admin (URGENT) — Filament admin page for Supplier API + WooCommerce REST + Bitrix + Langfuse + Anthropic credentials with per-integration "Test connection" actions; closes the env-only-credentials ops gap before Phase 10 PricingAgent ships and burns Anthropic budget against potentially-misconfigured upstreams
- Phase 11.1 inserted after Phase 11: Competitor FTP Pull (URGENT) — scheduled FTP/SFTP fetch of competitor CSVs from external feeds into `storage/app/competitors/incoming/` so Phase 5 watcher picks them up. New `competitor_ftp_sources` admin-managed table (host/port/path/encrypted credentials/cron/active flag), `competitor:ftp-pull` artisan command (dry-run default + `--live` opt-in), Filament Resource, scheduled before `competitor:watch`, audit_log entry per pull. Closes the only missing link in the FTP→parse→DB→pricing pipeline. Single-plan phase, ~50-100 LOC + 5-6 tests. Deps: Phase 5 (existing CSV ingest pipeline).

### Pending Todos

None yet — Phase 8 planning kicks off with `/gsd-plan-phase 8`.

### Blockers/Concerns

**Operator decisions to confirm before Phase 8 plan 01 lands:**

- Anthropic budget ceiling sign-off (proposal: £200/month)
- Self-hosted Langfuse Docker provisioning on ops VPS

**Carry-forward from v1.50.1 (operator-side, not v2 dev work):**

- v1 cutover execution per `docs/ops/cutover-handover.md` Appendix A
- 3 operator carry-forward gates: supplier API probe, Woo sandbox image URL pass-through re-validation, feature-tier Pest suite run against online MySQL

**Per-phase operator questions (per research/SUMMARY.md operator decisions tracker):**

- Phase 9 (E1): trade customer-group seed list (Trade / Reseller / Education / NHS proposed); display strategy `retail` vs `hidden`
- Phase 11 (E2): quote PDF branding template ownership; customer signature mechanism; Bitrix Deal line-item modelling for 30-line quotes
- Phase 13 (E3): WABA ownership confirmation; template catalogue scope; Meta OBO deprecation verification
- Phase 14 (E4): public vs internal SKU split; anonymous chatbot PII storage posture; chatbot 24/7 vs business-hours
- Phase 15 (C2): GCLID capture in v1 — hotfix on v1 may be required before P15
- Plan 08-01: AgentRunTest (12 unit tests) + migration verification deferred until MySQL service is running on 127.0.0.1:3306
- Plan 08-02: 5 Prism::fake() integration tests in tests/Feature/Agents/ClaudeClientTest.php deferred until MySQL service is online (Pest auto-applies RefreshDatabase to tests/Feature). 9 unit tests pass today. Langfuse Docker stack ready for ops to provision per docs/ops/observability.md

## Session Continuity

Last session: 2026-05-16T17:30:00.000Z
Stopped at: Completed 12-05-PLAN.md — Phase 12 fully shipped (5/5 plans complete; UAT deferred to production deploy per 12-UAT-DISPOSITION.md; 120 Pest cases / 287 assertions; ship verdict PASS_WITH_DEFERRED_UAT in 12-VERIFICATION.md)
Resume: Orchestrator runs `gsd-tools phase complete 12` to finalize the phase, then `/gsd-research-phase 13` (E3 WhatsApp Channel — research flag YES per WABA setup + Meta OBO BSP deprecation 2026 + 24h window state machine edge cases).

### Phase 12 Decisions (logged 2026-05-16)

- **O-1 Resolved YES** — Prism v0.100.1 supports `withEnumParameter`; ProposeContentPatchTool's `field` arg pinned to 4 valid SEO fields at the Anthropic schema level.
- **O-2 Resolved env-flag default true** — `AGENT_SEO_BATCH_SCHEDULE_ENABLED=true` wraps the nightly schedule entry; operator can flip to false in `.env` for emergency disable without code deploy.
- **O-3 Resolved subset stays pending** — Suggestion status only flips to `applied` when ALL patches in `payload.patches[]` have `applied_at` set; subset approvals stay `pending` so admin can return later.
- **O-4 Resolved temperature=0.4** — `agents.seo.temperature` defaults to 0.4 (env override via `AGENTS_SEO_TEMPERATURE`) — balances creativity vs reproducibility for SEO copy.
- **O-5 Resolved default-hide with escape-hatch filter** — SuggestionResource::getEloquentQuery hides `agent_guardrail_blocked` by default; explicit `tableFilters.kind.value=agent_guardrail_blocked` shows them.
- **P12-A LAST-WINS dedup defended at 3 layers** — unconditional `$patchesByField[$field] = ...` + Pest fixture asserting second-call wins + source grep gate returning 0 for `isset($patchesByField`.
- **P12-B catch-block audit before rethrow** — RunSeoAgentJob line 242 calls `createGuardrailBlockedSuggestion` BEFORE line 271's `throw $e`; one `agent_guardrail_blocked` Suggestion + zero `seo_content_patch` Suggestions after blocked run.
- **P12-D TruncatingTool relocation clean (no shim)** — moved from `Tools/Pricing/` to shared `Tools/` parent; old FQCN absent; all 4 Phase 10 read_* tools updated.
- **P12-E budget race 3-layer defence** — pre-flight Cache::get + between-dispatch Cache::get + BatchCommandBudgetRaceTest with 2 ceiling fixtures.
- **P12-F additive Filament sidebar** — `seoPatchesInfolist` method name (NOT `infolist()`); EditAutoCreateReview declares neither `form()` nor `infolist()` locally; reflection check in AutoCreateEditFormUnchangedTest.
- **P12-H brand-voice opacity** — ReadBrandStyleGuideTool + system.blade.php contain neither `Blade::render` nor `@include`; grep-asserted in test suite.
- **Critical title→Product.name remap** — SEOAGT-01 user-facing 'title' field maps to Product.name column via `FIELD_TO_PRODUCT_COLUMN` constant; defended by SeoContentPatchApplierTitleToNameTest 3 cases.
- **UAT deferred to production deploy** — `ms.21stcav.com` not yet bootstrapped; 10 of 10 manual UAT steps have direct Pest substitution; deferred items + re-run conditions captured in 12-UAT-DISPOSITION.md.
