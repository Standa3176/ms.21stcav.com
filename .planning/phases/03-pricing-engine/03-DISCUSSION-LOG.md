# Phase 3: Pricing Engine - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-19
**Phase:** 03-pricing-engine
**Areas discussed:** Rounding convention, Rule richness + tie-breaker, Zero/null supplier price

---

## Rounding convention

### Q1 — Legacy plugin rounding convention?

| Option | Description | Selected |
|--------|-------------|----------|
| Plain 2dp | Matches REQUIREMENTS.md formula `round(x, 2)`. Final prices like £847.33, £1199.25. Predictable, easy to golden-test. | ✓ |
| Ends in .99 | Psychological pricing — £849.99, £1199.99. Common in AV retail. Requires ceiling step. | |
| Ends in .95 or .49 | Alternative psychological convention. | |
| Hybrid (tier-dependent) | Different rounding per tier (e.g. .99 for <£100, plain elsewhere). | |

**User's choice:** Plain 2dp (Recommended)
**Notes:** Matches the REQUIREMENTS.md formula exactly. Golden fixtures sourced from live Woo DB will confirm parity.

### Q2 — PHP rounding mode?

| Option | Description | Selected |
|--------|-------------|----------|
| HALF_UP | PHP `round()` default; 0.5 always rounds up. Most likely matches legacy plugin (bare `round()` call). | ✓ |
| HALF_EVEN (banker's) | HMRC-permitted; 0.5 rounds to nearest even. Reduces statistical bias but differs from PHP default. | |
| Let planner decide after legacy review | Planner inspects legacy plugin source during research and locks mode. | |

**User's choice:** HALF_UP (Recommended)
**Notes:** Lock explicitly in `config/pricing.php => 'rounding_mode' => PHP_ROUND_HALF_UP`. Re-baseline fixtures only if live-Woo export reveals a mismatch.

### Q3 — 50 golden-fixture source?

| Option | Description | Selected |
|--------|-------------|----------|
| Export from live Woo DB | One-off read-only SQL against a snapshot; records `(supplier_price, margin_percent, legacy_final)` triples. Ground-truth parity. | ✓ |
| Hand-craft from legacy formula | Planner computes expected values using the documented formula. Blind to hidden edge cases. | |
| Hybrid | 30 exported + 20 synthesized edge cases (tier boundaries, zero supplier price, 0% margin, ceiling cases). | |

**User's choice:** Export from live Woo DB (Recommended)
**Notes:** Fixture is version-controlled as JSON under `tests/Fixtures/Pricing/golden-fixtures.json`. Re-sourcing requires a deliberate commit referencing the re-baseline reason.

### Q4 — VAT-removal helper in Phase 3?

| Option | Description | Selected |
|--------|-------------|----------|
| Expose now | `PriceCalculator::stripVat()` ships in Phase 3 with its own tests; Phase 5 reuses unchanged. | ✓ |
| Defer to Phase 5 | Phase 3 ships forward calculator only; Phase 5 adds stripping when competitor ingest needs it. Drift risk. | |
| Ship but defer use | Ship + test helper in Phase 3 but leave it unused until Phase 5. | |

**User's choice:** Expose now (Recommended)
**Notes:** Prevents the "float / 1.2" landmine Pitfall 5 warns about and keeps rounding logic in one place.

---

## Rule richness + tie-breaker

### Q1 — v1 rule feature scope?

| Option | Description | Selected |
|--------|-------------|----------|
| Parity-only | Ship only what REQUIREMENTS.md names: scope, margin %, default tiers, per-product override. | ✓ |
| Parity + min-margin floor | Add nullable `min_margin_pct` column; log 'floor breached' suggestion when supplier price rises. | |
| Parity + floor + validity windows | Adds `valid_from` / `valid_until` columns for promos. | |
| Parity + everything | Full featureset — floor + windows + rich audit trail. | |

**User's choice:** Parity-only (Recommended)
**Notes:** Keeps the golden-fixture surface tight and the ship gate achievable. Floor + validity deferred to a dedicated Pricing v2 phase post-cutover.

### Q2 — Tie-breaker for equally-specific rules?

| Option | Description | Selected |
|--------|-------------|----------|
| Explicit priority integer | Add `priority` column (unsigned smallint, default 100). Resolver sorts specificity DESC, priority DESC, id ASC. | ✓ |
| Most-recently-updated wins | Sort by `updated_at` DESC after specificity. Innocuous edits can silently flip which rule fires. | |
| Rule ID ascending | First-created wins. Predictable but makes later rules feel invisible. | |
| Fail loudly on ambiguity | Resolver throws when overlap is unresolved. Safer but noisy during setup. | |

**User's choice:** Explicit priority integer (Recommended)
**Notes:** Pricing manager can force deterministic ordering when category overlap is real.

### Q3 — Per-product override semantics?

| Option | Description | Selected |
|--------|-------------|----------|
| Margin % override | Stored as int basis points. PriceCalculator runs the same formula with this margin. Matches legacy meta name. | ✓ |
| Direct final-price override | Stored as int pennies. Bypasses the formula entirely. Makes margin/VAT reporting lie. | |
| Both (admin chooses per override) | Override row has type enum. Flexible but adds UI branching. | |

**User's choice:** Margin % override (Recommended)
**Notes:** Matches legacy `buy_price_percentage_to_add` semantics literally.

### Q4 — Override granularity for variable products?

| Option | Description | Selected |
|--------|-------------|----------|
| Parent only, variations inherit | `ProductOverride.product_id` only. Matches legacy (meta lived on parent post). | ✓ |
| Parent OR variation | `ProductOverride` has both `product_id` + nullable `variant_id`. Variant-specific wins. | |
| Variation only | For variable products, overrides must be per-variation. 5× the data entry. | |

**User's choice:** Parent only (Recommended)
**Notes:** Schema forward-compatible — nullable `variant_id` can be added later without breaking v1 rows.

---

## Zero/null supplier price

### Q1 — Behaviour when SupplierPriceChanged fires with supplier_price = 0 or null?

| Option | Description | Selected |
|--------|-------------|----------|
| Skip + log to import_issues | Reuse Phase 2 D-09 `missing_cost_price` issue type. No Woo push, no £0 leak. | ✓ |
| Skip + emit suggestion | Writes a suggestion for admin review. Heavier than needed for 'data glitch'. | |
| Flip product to pending in Woo | Pricing engine reaches into Woo. Violates separation — Phase 2 owns status flips. | |
| Fail the recompute job loudly | Listener throws → failed_jobs. Noisy, doesn't distinguish data vs. code problem. | |

**User's choice:** Skip + log to import_issues (Recommended)
**Notes:** `PriceCalculator::compute()` guards at entry, throws `SupplierPriceUnusableException`. Listener catches and writes ImportIssue.

### Q2 — Bulk recompute behaviour for already-flagged import_issues?

| Option | Description | Selected |
|--------|-------------|----------|
| Skip + count in report | Find-or-update existing ImportIssue (`last_seen_at` bumped). Clean idempotent bulk. | ✓ |
| Re-log every time | New ImportIssue row per recompute run. Floods the table. | |
| Treat as hard failure | Whole chunk fails. Safer first-time, terrible for routine recomputes. | |

**User's choice:** Skip + count in report (Recommended)
**Notes:** Idempotent; issue rows clear only when a subsequent sync lands a valid supplier price.

### Q3 — `pricing:recompute --all` default mode?

| Option | Description | Selected |
|--------|-------------|----------|
| Dry-run default, --live opt-in | Matches Phase 2 D-04. No writes to sell_price, no ProductPriceChanged emission unless --live. | ✓ |
| Live default for Laravel writes, Woo gate stays | Always writes Laravel; WOO_WRITE_ENABLED still gates downstream push. | |
| Live default, no Laravel writes until --commit | Stages diffs to scratch table, admin commits via Filament. Heaviest UX. | |

**User's choice:** Dry-run default, --live opt-in (Recommended)
**Notes:** Belt-and-braces. `WOO_WRITE_ENABLED` remains the second gate governing the Phase 2 listener's push.

### Q4 — ProductPriceChanged emission threshold?

| Option | Description | Selected |
|--------|-------------|----------|
| Any integer-penny diff | Emit when `new_pennies !== old_pennies`. Integer compare, no float slop. | ✓ |
| Diff ≥ 1p AND ≥ 0.01% of price | Percent-floor filter. Adds no value with integer-pennies comparison. | |
| Only on rule/override change | Won't satisfy PRCE-07 (supplier-price change is the primary trigger). | |

**User's choice:** Any integer-penny diff (Recommended)
**Notes:** Event carries correlation_id from Context per Phase 1 D-16 — downstream Woo push + audit log + integration_events all joinable on it.

---

## Claude's Discretion

Deferred to planner/researcher with stated defaults:

- Default tier seed values (margin % per tier) — read from the same Woo DB snapshot that sources the fixtures
- Filament rule-explorer UX specifics (layout, InfoList shape)
- Simulated-impact view depth (count always; on-demand table with CSV export; no charts)
- PricingRule audit trail (spatie/activitylog `LogsActivity` trait + `logOnlyDirty()`)
- Listener queue (`default`, not `sync-woo-push`)
- PriceCalculator test scope (50 golden fixtures + guard-path unit tests + property-based rounding tests)

## Deferred Ideas

Scoped out of Phase 3 to protect the golden-fixture ship gate. Candidates for a dedicated Pricing Engine v2 phase post-cutover:

- Minimum-margin floor per rule (research B.2 differentiator)
- Rule validity windows (`valid_from` / `valid_until`)
- Per-variation pricing overrides
- Psychological rounding (`.99` / `.95` / tier-dependent)
- Direct final-price override (alternative to margin %)
- Rule change-history UI (beyond spatie/activitylog storage)
- Variable-product rule-level scoping
