# Phase 3: Pricing Engine - Context

**Gathered:** 2026-04-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 3 delivers the rule-driven pricing engine that transforms supplier prices (landed by Phase 2 in `products.buy_price` / `product_variants.buy_price`) into final VAT-inclusive retail prices written back through the existing Phase 2 Woo-push path. Scope: `PricingRule` model + migration (brand / category / brand+category scope, explicit priority integer), `ProductOverride` model + migration (per-product margin % override matching legacy `buy_price_percentage_to_add`), `RuleResolver` (most-specific-wins, no stacking, deterministic tiebreak), `PriceCalculator` (integer-pennies math, BCMath where PHP native is insufficient, single `round()` at the boundary, `HALF_UP` mode), `SupplierPriceChanged` listener (recompute → emit `ProductPriceChanged` on any integer-penny diff), Filament `PricingRuleResource` with effective-price preview + simulated-impact view, `php artisan pricing:recompute --all` queued batch (dry-run default, `--live` opt-in per Phase 2 D-04 pattern), and the 50-triple golden-fixture parity test sourced from live Woo DB that IS the Phase 3 ship gate.

Scope is fixed by ROADMAP.md Phase 3 and REQUIREMENTS.md PRCE-01..PRCE-10. Discussion resolved the implementation choices research flagged as "needs ops conversation" (rounding), plus three gaps the brief omitted (tie-breaker, override semantics, zero-price handling). Min-margin floor and rule validity windows were raised by research but deferred to a later phase to keep the parity ship gate tight.

</domain>

<decisions>
## Implementation Decisions

### Rounding convention (CRITICAL — ship gate depends on it)

- **D-01:** **Plain 2dp rounding** — the final price is `round(supplier × (1 + margin/100) × 1.2, 2)` per REQUIREMENTS.md PRCE-05. No psychological endings (`.99`, `.95`), no tier-dependent rounding. This matches what ops report the legacy Stock Updater plugin currently produces. Golden-fixture triples will confirm this penny-exactly before the phase ships.
- **D-02:** **`HALF_UP` rounding mode** (PHP `round()` default). Lock it explicitly in `config/pricing.php => 'rounding_mode' => PHP_ROUND_HALF_UP` per Pitfall 5's advice — document that the legacy plugin calls bare `round()` and we match that. If the live-Woo fixture export reveals a discrepancy, planner flips to `HALF_EVEN` and re-baselines fixtures as a single deliberate change.
- **D-03:** **Integer-pennies + BCMath math throughout** (Pitfall 5 mandate). `PriceCalculator::compute(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints = 2000): int` — single pure function, rounding applied once at the return boundary, no intermediate `round()` calls, no floats. Inputs converted to pennies at the DB/eloquent cast layer; outputs converted back to `decimal(12,4)` on write.
- **D-04:** **Golden fixtures sourced from live Woo DB.** A one-off read-only SQL script against a snapshot of `meetingstore.co.uk`'s Woo DB selects 50 products spanning the three default tiers (`<£100`, `£100–499`, `£500+`) plus edge cases (tier boundaries: `£99.99`/`£499.99`/`£500.01`, override-equipped SKUs, variable products with variation-level prices), recording `(supplier_price, margin_percent, legacy_final_price)` triples. The fixture is version-controlled as a JSON file under `tests/Fixtures/Pricing/golden-fixtures.json`. Re-sourcing is a deliberate act (commit message must reference the re-baseline reason).
- **D-05:** **VAT-removal helper ships in Phase 3**, not deferred to Phase 5. `PriceCalculator::stripVat(int $grossPennies, int $vatBasisPoints = 2000): int` — integer math (`$exVat = intdiv($gross * 10000, 12000)` with explicit rounding). Phase 5's competitor-CSV ingest imports and uses it unchanged, preventing parallel rounding logic from drifting. Covered by its own unit tests in Phase 3.

### Rule richness — v1 scope

- **D-06:** **Parity-only feature set for v1 `PricingRule`.** Ships exactly what REQUIREMENTS.md names: `scope` (enum: `brand` | `category` | `brand_category`), `brand_id` (nullable), `category_id` (nullable), `margin_basis_points` (int, e.g. 2200 = 22.00%), `priority` (unsigned smallint, see D-07), `is_default_tier` (bool), `tier_min_pennies` + `tier_max_pennies` (nullable, used only when `is_default_tier=true`). Explicitly **NOT in Phase 3**:
  - Minimum-margin floor per rule (research B.2 differentiator) — deferred
  - Validity windows (`valid_from` / `valid_until`) — deferred
  - Per-customer or customer-group scoping — already Out of Scope until v2 B2B
  This keeps the golden-fixture surface tight and the ship gate achievable. Floor + validity are tagged as "resurface for a dedicated Pricing Engine v2 phase" in this CONTEXT's Deferred section.
- **D-07:** **Deterministic tie-breaker: explicit priority integer.** Resolver sort order: `specificity DESC` (brand+category > category > brand > default tier, tracked as int specificity_rank), `priority DESC` (user-set; higher = earlier), `id ASC` (final fallback — never truly hit unless two identical rules exist). Default `priority = 100` so a rule created without thought still sorts predictably. When two rules tie on specificity AND priority AND id, the resolver's internal invariant holds by database primary key uniqueness.
- **D-08:** **Per-product override = margin % override** (legacy `buy_price_percentage_to_add` semantics). `ProductOverride` table: `product_id` (FK, unique), `margin_basis_points` (int), `created_by_user_id`, `reason` (nullable text for audit). `PriceCalculator::compute()` takes the override margin instead of the rule's when one exists; everything else in the formula stays identical. Golden fixtures cover ≥5 override-equipped SKUs.
- **D-09:** **Override granularity: parent product only; variations inherit.** `ProductOverride` has `product_id` (not `variant_id`). For a variable product with 5 colour variations, setting a 25% margin override on the parent applies uniformly to all 5 variations. Matches the legacy plugin (meta lived on the parent post) and the `ProductResource` UX already built in Phase 2. Per-variation overrides resurface as a v2 candidate if ops asks for it; schema is forward-compatible (add nullable `variant_id` column later without breaking v1 rows).

### Zero/null supplier price + Woo-write safety

- **D-10:** **Zero/null supplier price → skip + ImportIssue.** `PriceCalculator::compute()` has a guard at entry: if `$supplierPennies <= 0` or null, throw `SupplierPriceUnusableException`. The `SupplierPriceChanged` listener catches this and writes an `ImportIssue` row (`issue_type: 'missing_cost_price'` — already exists from Phase 2 D-09) with the product_id, supplier_price, and correlation_id. Existing `products.sell_price` stays untouched (no £0 leak to Woo). Pricing manager triages in the Filament Import Issues page already built in Phase 2.
- **D-11:** **Bulk recompute is idempotent on ImportIssue rows.** `pricing:recompute --all` hits the same guard; already-logged issues are found-or-updated (`last_seen_at` bumped, no new row inserted). The batch summary reports `skipped_zero_price: N` per run. Issue rows are cleared only when a subsequent sync lands a valid supplier price.
- **D-12:** **`pricing:recompute --all` defaults to dry-run**, `--live` opt-in (follows Phase 2 D-04). Dry-run: calculator runs, diffs are printed + counted in the report, **no writes to `products.sell_price`** and **no `ProductPriceChanged` emission**. `--live`: writes the recomputed prices and emits `ProductPriceChanged` per row that changed. The existing `WOO_WRITE_ENABLED` env flag remains the second gate governing the Phase 2 listener's push to Woo. Belt-and-braces against a typo'd production recompute.
- **D-13:** **`ProductPriceChanged` emits on any integer-penny diff.** Listener compares new vs stored final price as integers (`$newPennies !== $oldPennies`); if they differ, emit. No percent-floor filter (integer-pennies comparison is already the floor — identical inputs yield identical outputs). Event carries `correlation_id` from Context per Phase 1 D-16, so the downstream Woo push, audit log, and integration_events row are all joinable.

### Claude's Discretion

The following areas were not discussed — planner/researcher may pick the default best-practice approach:

- **Default tier seed values** (PRCE-03) — the tier boundaries `<£100`, `£100–499`, `£500+` are locked; the margin percentages for each are a "5-min ops conversation" item (research SUMMARY.md) that the planner should resolve before writing seeder. Until then, use the legacy plugin's live production values read from the same Woo DB snapshot that sources the fixtures.
- **Filament rule explorer UX** (PRCE-08) — layout, which fields show on the table vs. form, how the resolution chain ("brand+cat → brand → cat → default") renders. Planner picks a clean Filament 3 pattern (`InfoList` entries with coloured badges per rule-type was the Phase 2 pattern; reuse).
- **Simulated-impact view depth** (PRCE-09) — preview the "N SKUs would change" count always; a table with `sku | current_price | proposed_price | delta` on demand. Paginate at 50, allow CSV export via Filament's bulk export action. Defer any charting (trend lines, histograms) to Phase 7 dashboard polish.
- **PricingRule audit trail** — `spatie/laravel-activitylog` is already installed (Phase 1). Add `LogsActivity` trait to `PricingRule` and `ProductOverride` with `->logOnlyDirty()` on the pricing-affecting columns. No separate audit table.
- **Listener queue** — `SupplierPriceChanged` listener runs on the `default` queue (not `sync-woo-push` — that queue is for the downstream Woo PUT, not the recompute). Keeps pricing math off the rate-limited queue.
- **PriceCalculator test scope** — golden fixtures (50) + unit tests for each guard path (zero, null, negative, over-boundary tier) + property-based tests for rounding stability (PHP property test library already available or use a hand-rolled loop over 1000 random inputs).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 Foundation (authoritative contracts Phase 3 consumes)

- `.planning/phases/01-foundation/01-CONTEXT.md` — 17 locked decisions (D-01..D-17) from Phase 1 (RBAC, correlation_id threading, suggestions seam, shadow-mode gate, AlertRecipient)
- `.planning/phases/01-foundation/01-03-SUMMARY.md` — `DomainEvent` base class, `Auditor` service, `IntegrationLogger`, `BaseCommand`, `Context::hydrated` queue bridge — all Phase 3 listener/command code MUST use these
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — `SuggestionApplier` contract (Phase 3 ships nothing new here; Phase 5 is the first real producer)
- `.planning/phases/01-foundation/01-05-SUMMARY.md` — 7 Horizon supervisors (`default` queue for the pricing listener, `sync-bulk` for the bulk recompute batch)

### Phase 2 Supplier Sync (Phase 3 runs as a downstream listener)

- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — 10 locked decisions (D-01..D-10), notably D-01 (variable products first-class), D-04 (dry-run-default CLI pattern), D-09 (`NewSupplierSkuDetected` + `import_issues` table), D-10 (CSV report column set)
- `.planning/phases/02-supplier-sync/02-VERIFICATION.md` — Phase 2 ship verdict (Phase 3 assumes Phase 2 is green before starting)
- `app/Domain/Sync/Events/SupplierPriceChanged.php` — Phase 3's primary trigger event (payload: product_id, variant_id nullable, old_buy_price, new_buy_price, correlation_id)
- `app/Domain/Products/Models/Product.php` — `buy_price` (supplier ex-VAT) + `sell_price` (Phase 3 writes this) + `cost_price` (historical, untouched by Phase 3)
- `app/Domain/Products/Models/ProductVariant.php` — same fields at variation level; `old_sell_price` gives Phase 3 the 1-sync lookback for the diff check
- `app/Domain/Sync/Models/ImportIssue.php` — D-10 zero-price handling writes here with `issue_type: 'missing_cost_price'`

### Project foundations

- `.planning/PROJECT.md` — Core Value (Laravel owns pricing), Constraints (audit everything, suggestions pattern), Key Decisions (most-specific-wins pricing)
- `.planning/REQUIREMENTS.md` — PRCE-01 through PRCE-10 acceptance criteria
- `.planning/ROADMAP.md` — Phase 3 goal, depends-on (Phase 2), 5 success criteria (golden fixture is criterion 1 and is the ship gate)
- `.planning/STATE.md` — Session continuity and open-items flagged during earlier phases

### Research artefacts

- `.planning/research/STACK.md` — BCMath availability / PHP 8.4 `BcMath\Number` class status; no new packages for Phase 3
- `.planning/research/FEATURES.md` §B — Pricing engine categorised features (B.1 brief items, B.2 differentiators, B.3 gaps, B.4 anti-features — this CONTEXT resolves B.3 and defers two of B.2)
- `.planning/research/PITFALLS.md` — Pitfall 5 (VAT rounding drift, CRITICAL) is the MUST-READ for the planner; Pitfall 7 (nullable columns without backfill pathway) also applies to the new `ProductOverride` + `PricingRule` migrations
- `.planning/research/SUMMARY.md` — "Research flags" table marks Phase 3 as `light` research; the rounding-convention question is resolved here (D-01..D-04) — no deeper research needed pre-planning

### No external specs

No ADRs, RFCs, or external spec documents for Phase 3. REQUIREMENTS + ROADMAP + the two earlier CONTEXT files + research files constitute the full contract.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1 + Phase 2 delivered)

- **`DomainEvent` base class** (Phase 1) — `SupplierPriceChanged` already extends it; Phase 3 adds `ProductPriceChanged` the same way. Auto-fills correlation_id, implements `ShouldDispatchAfterCommit` (events don't fire in rolled-back transactions).
- **`BaseCommand`** (Phase 1) — `PricingRecomputeCommand` extends this for automatic correlation_id threading and console CID visibility.
- **`Auditor`** (Phase 1) — logs `PricingRule` and `ProductOverride` model changes into the existing `audit_log` table; `LogsActivity` trait on the new models hooks in automatically.
- **`IntegrationLogger`** (Phase 1) — Phase 3 doesn't make outbound API calls directly; the downstream Phase 2 Woo push on `ProductPriceChanged` continues to log. No new outbound paths in Phase 3.
- **`ImportIssue` model + table** (Phase 2 D-09) — Phase 3 reuses `issue_type: 'missing_cost_price'` for zero/null supplier prices. No schema change needed.
- **`Product` + `ProductVariant` models** (Phase 2) — Phase 3 writes to `sell_price` on both; reads `buy_price` + tier bracket for rule resolution. Observer patterns + `forceFill + saveQuietly` already established (Phase 2 Plan 01) to avoid activity-log bloat from routine variation saves.
- **Horizon supervisors** (Phase 1 Plan 05) — `default` for the listener, `sync-bulk` for the bulk recompute batch. Both already booted; Phase 3 just dispatches onto them.
- **`ThrottledFailedJobNotifier`** (Phase 1) — catches any listener/batch failures with 5-min dedup; no action needed in Phase 3.
- **Shield RBAC pattern** (Phase 1 D-01..D-03) — `shield:generate` auto-creates per-Resource permissions for `PricingRule` and `ProductOverride`. Seeder LIKE patterns (`%_pricing_rule`, `%_product_override`) already match `pricing_manager` role in `RolePermissionSeeder` from Phase 1; re-running the seeder on deploy is the existing pattern.

### Established Patterns (from Phase 1 + 2 SUMMARY files)

- **Migration timestamps** — Phase 2 migrations start at `2026_04_18_190000`; Phase 3 starts at `2026_04_19_XXXXXX` (planner picks exact values).
- **Filament Resource pattern** — Shield auto-creates permissions; Resources register in `app/Domain/<Module>/Filament/Resources/`. Phase 3 Pricing Resources live in `app/Domain/Pricing/Filament/Resources/`.
- **Policy template `{{ Placeholder }}` literal strings** — Phase 1 discovered Shield 3.9.10 leaks these; Phase 2 promoted `PolicyTemplateIntegrityTest` to `tests/Architecture/` as a permanent grep guard. Phase 3's new `PricingRulePolicy` and `ProductOverridePolicy` get auto-checked by that test.
- **Testing DB isolation** — `meetingstore_ops_testing` MySQL DB (not in-memory SQLite) per Phase 1 P03. Phase 3 tests follow the same pattern; no changes needed.
- **`->authorize()` mandatory on Filament Actions** — applies to any pricing bulk action (e.g. "Recompute selected" or "Delete rule").
- **Migration + nullable column pattern** (Pitfall 7) — any nullable column on `pricing_rules` or `product_overrides` must have a backfill path + null-safe code access. Applies to `tier_min_pennies`/`tier_max_pennies` and any later-added optional columns.

### Integration Points

- **Inbound:** `SupplierPriceChanged` event (Phase 2) → `RecomputePriceListener` → `RuleResolver` → `PriceCalculator::compute()` → write `products.sell_price` OR log to `import_issues` → emit `ProductPriceChanged`
- **Outbound:** `ProductPriceChanged` event → Phase 2's existing Woo-push listener (shadow-gated by `WOO_WRITE_ENABLED`) → `WooClient::put()` → Woo REST
- **New migrations needed:** `pricing_rules` (D-06 columns + priority), `product_overrides` (D-08 columns + unique product_id), fixture JSON under `tests/Fixtures/Pricing/`
- **New Filament Resources:** `PricingRuleResource` (CRUD + rule explorer preview page + simulated-impact view), `ProductOverrideResource` (read-write for `pricing_manager`; usually accessed via `ProductResource` relation manager)
- **New domain:** `app/Domain/Pricing/` (currently `.gitkeep` placeholder) gets populated: `Models/`, `Services/` (`RuleResolver`, `PriceCalculator`, `BulkRecomputer`), `Events/` (`ProductPriceChanged`), `Listeners/` (`RecomputePriceListener`), `Filament/Resources/`, `Console/Commands/` (`PricingRecomputeCommand`), `Policies/`
- **Deptrac layer** — `Pricing` layer depends on `Products` (read), `Sync` (read events), `Foundation` (Auditor, IntegrationLogger, DomainEvent). NO dependency on `CRM`, `Competitor`, `Webhooks`. Extend depfile.yaml with the explicit allow-list.

</code_context>

<specifics>
## Specific Ideas

- **Golden fixture is the ship gate, full stop** — PRCE-06 / ROADMAP.md Phase 3 success criterion 1. If any of the 50 triples drift by a single penny, the phase does not ship. Planner must wire this as a CI-blocking test, not a soft warning.
- **`PriceCalculator` is a pure static-like service** — no Eloquent access, no event dispatch, no logging. Takes primitives, returns primitives. This is deliberate — it's the unit that golden fixtures pin. Everything *around* it (the listener, the bulk command, the Filament explorer) stays testable without touching the calculator's internals.
- **`stripVat()` ships with its own tests in Phase 3** (D-05) — Phase 5's competitor-CSV ingest imports it unchanged. Prevents the "parallel rounding logic" drift Pitfall 5 warns about.
- **`product_overrides` migration carries backfill step** (Pitfall 7) — if legacy Woo meta has `buy_price_percentage_to_add` values, a one-off backfill command (`php artisan pricing:backfill-overrides`) reads them during Phase 3 deploy. Planner decides whether to ship the backfill in this phase or defer to Phase 7 cutover prep.
- **Tier seeder reads live values** — D-06 locks the tier boundaries, but the margin percentages per tier come from the same Woo DB snapshot that sources the golden fixtures. Avoids the "seeder has different tier values from fixtures" drift.
- **`ImportIssue` deduplication is by `(product_id, issue_type, resolved_at IS NULL)`** — Phase 2 already persists this shape. Phase 3's zero-price handler uses find-or-update (`updateOrCreate` with `last_seen_at` bump), NOT create-every-time.
- **`PricingRule.is_default_tier` column is exclusive-set** — either a rule is a default-tier fallback (tier bounds set, brand/category NULL) or it's a specific rule (brand and/or category set, tier bounds NULL). Architectural test or DB-level CHECK constraint enforces this.
- **Deferred floor + validity windows are NOT "out of scope forever"** — they are scoped out of Phase 3 to protect the ship gate. Surface them as a candidate Phase 7.5 or Phase 8 pricing-v2 discussion item after the parity cutover is proven.

</specifics>

<deferred>
## Deferred Ideas

These surfaced during discussion but are explicitly scoped out of Phase 3 to keep the golden-fixture ship gate achievable. They are candidates for a dedicated Pricing Engine v2 phase post-cutover:

- **Minimum-margin floor per rule** (research B.2) — guards against supplier price rising while margin% makes retail drop below break-even. Standard in competitor repricers. Defer until post-cutover when v1 parity is proven.
- **Rule validity windows** (`valid_from` / `valid_until`) — enables promotional pricing (Black Friday, supplier-rebate windows). Resolver would need to filter by active-window before specificity sort.
- **Per-variation pricing overrides** — `ProductOverride.variant_id` nullable column. Schema is forward-compatible per D-09. Resurface if ops asks for it after v1.
- **Psychological rounding** (`.99` / `.95` / tier-dependent) — rejected in D-01; if ops later decides this is needed, it's a single-place change in `PriceCalculator` + a re-baseline of golden fixtures.
- **Direct final-price override** (alternative to margin-% override) — rejected in D-08; schema could be extended with an enum later if ops needs one-off pricing that bypasses the formula.
- **Rule audit dashboard / change-history UI** — Phase 3 ships `LogsActivity` on the models (free via spatie/activitylog). A dedicated Filament view surfacing rule-change history belongs in Phase 7 dashboard polish.
- **Variable-product rule-level scoping** — rules currently apply to all variations of a matched parent. Per-variation rule-level scope is even heavier than per-variation overrides; unlikely to be asked for.

None of these are required for v1 functionality — they are enhancements that compound on a proven v1 parity baseline.

</deferred>

---

*Phase: 03-pricing-engine*
*Context gathered: 2026-04-19*
