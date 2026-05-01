---
phase: 11-e2-quote-request-bitrix-deal-flow
plan: 01
subsystem: quotes-foundation
tags: [quotes, bitrix-dedup, ulid, schema, migrations, eloquent, enums, policies, deptrac, dual-yaml, vat-inclusive, separation-of-duties, shadow-mode]

requires:
  - phase: 01-foundation
    provides: HasUlids/LogsActivity convention, Auditor, BaseCommand, factory pattern, Gate::policy registration pattern
  - phase: 04-bitrix24-crm-sync
    provides: BitrixEntityMap dedup ledger (Pitfall 6) + UNIQUE(entity_type, woo_id) — Phase 11 EXTENDS with parallel UNIQUE(entity_type, quote_id)
  - phase: 05-competitor-analysis
    provides: Deptrac dual-YAML sync lesson (P05-05)
  - phase: 07-dashboard-cutover
    provides: Symfony Process exit-code architecture-test pattern (Windows-reliable; NOT stdout grep)
  - phase: 08-c4-agent-framework
    provides: HasUlids on AgentRun + status-constants-alongside-enum precedent
  - phase: 09-e1-trade-customer-pricing
    provides: customer_groups table + CustomerGroup model (Quote.customer_group_id FK target) + DeptracTradePricingLayerTest clone target

provides:
  - quotes table — ULID PK + 14 cols + status string column (sqlite-compat) + total_pence_at_quote cached + 4 status timestamps + rejection_metadata JSON + correlation_id
  - quote_lines table — ULID PK + foreignUlid CASCADE + integer pence cols VAT-INCLUSIVE per A1 (D-13 + Pitfall 1) + product_snapshot JSON + sort_order
  - bitrix_entity_map.quote_id CHAR(26) ULID + composite UNIQUE(entity_type, quote_id) coexists with existing UNIQUE(entity_type, woo_id) — RESEARCH OQ-2 Option a RESOLVED
  - alert_recipients.receives_quote_alerts BOOLEAN default false; seeded fallback force-updated TRUE per Pitfall M precedent
  - Quote Eloquent model — HasUlids + LogsActivity (logOnly status + 4 status timestamps + total — PII excluded per T-11-01-04) + 7 STATUS_* string constants + ulidShort + lines/customer/customerGroup relations
  - QuoteLine Eloquent model — HasUlids + integer pence casts (Pitfall 1) + product_snapshot array cast + BelongsTo Quote
  - QuoteStatus PHP 8.2 string-backed enum — 7 cases; D-05/D-06 reserved cases (PendingApproval, Approved) WITHOUT `withdrawn` (D-06 deferred); isReserved() helper
  - RejectionReason PHP 8.2 enum — 5 D-08 cases
  - QuotePolicy + QuoteLinePolicy — D-04 separation-of-duties (sales DENIED on approve; T-11-01-03) + D-05 admin-only revert window (5 min) + D-13 line immutability gates
  - QuoteFactory + QuoteLineFactory
  - config/quote.php — 9 keys including QUOTE_BITRIX_PUSH_ENABLED + QUOTE_BITRIX_TYPE_VERIFIED shadow-mode + cutover gates (cross-cutting invariant 4)
  - .env.example — 6 new QUOTE_* keys appended in v2 Phase 11 section
  - depfile.yaml + deptrac.yaml — Quotes layer registered + Quotes ruleset [Foundation, Products, Pricing, TradePricing, Suggestions, CRM, Webhooks] + CRM allow-list extended with Quotes (Plan 11-04 listener-based extension) + Http allow-list extended with Quotes (Plan 11-03 Filament Resource access)
  - DeptracQuotesLayerTest — 5 tests (dual-YAML structural + collector regex + CRM allow-list extension + 2× deptrac analyse exit-0); all PASS
  - PolicyTemplateIntegrityTest — floor bumped 27 → 29; Quotes/Policies path added; Quote/QuoteLine model→policy pairs registered
  - QuoteTest — 4 schema-presence + 9 model behaviour tests
  - QuoteLineTest — 6 unit tests
  - BitrixEntityMap.ENTITY_QUOTE_DEAL constant + scopeQuoteDeals + scopeForQuote(string)
  - AlertRecipient.scopeReceivingQuoteAlerts

affects: [11-02-priceSnapshotter, 11-03-filament-quote-resource, 11-04-bitrix-push-pipeline, 11-05-quotes-expire, phase-13-whatsapp-quote-handoff, phase-14-chatbot-propose-quote]

tech-stack:
  added: []  # Zero composer changes — Phase 11 Plan 01 is pure schema + model-layer + Deptrac additions
  patterns:
    - "ULID PK on Quote + QuoteLine via Illuminate\\Database\\Eloquent\\Concerns\\HasUlids (matches Phase 1 D-16, Phase 8 AgentRun precedent)"
    - "VAT-INCLUSIVE pence storage convention locked at column-comment + model-docblock level (D-13 + Pitfall 1) — PDF strips VAT at render time in Plan 11-04"
    - "Composite UNIQUE indexes coexist on bitrix_entity_map: existing (entity_type, woo_id) for orders + new (entity_type, quote_id) for quote-deals (Plan 11-04 EntityDeduper queries via two parallel scope methods)"
    - "Status-constants-alongside-enum pattern (mirror of Phase 8 AgentRun) — both Quote::STATUS_DRAFT and QuoteStatus::Draft->value are valid where('status', ...) inputs"
    - "Reserved-but-unused enum cases (D-05/D-06 PendingApproval + Approved) ship in v1.0 enum so v1.x extension is non-breaking; isReserved() helper guards against accidental v1.0 use"
    - "Shadow-mode env gate (cross-cutting invariant 4) — QUOTE_BITRIX_PUSH_ENABLED default false; Plan 11-04 routes to sync_diffs (provider='bitrix-quote') instead of BitrixClient when off"
    - "Cutover gate (QUOTE_BITRIX_TYPE_VERIFIED) — Plan 11-04 pre-flight refuses to push when false; flipped TRUE by ops after Bitrix admin verifies TYPE_ID=QUOTE"
    - "D-04 separation-of-duties at policy layer — sales role explicitly DENIED on QuotePolicy::approve (T-11-01-03 mitigation); 4-eyes pattern enforced even before Filament UI lands"
    - "Deptrac Quotes layer mirrored byte-equivalent across both yaml configs (Phase 5 P05-05 lesson)"

key-files:
  created:
    - database/migrations/2026_05_01_010000_create_quotes_table.php
    - database/migrations/2026_05_01_010100_create_quote_lines_table.php
    - database/migrations/2026_05_01_010200_add_quote_id_to_bitrix_entity_map_table.php
    - database/migrations/2026_05_01_010300_add_receives_quote_alerts_to_alert_recipients_table.php
    - app/Domain/Quotes/Models/Quote.php
    - app/Domain/Quotes/Models/QuoteLine.php
    - app/Domain/Quotes/Enums/QuoteStatus.php
    - app/Domain/Quotes/Enums/RejectionReason.php
    - app/Domain/Quotes/Policies/QuotePolicy.php
    - app/Domain/Quotes/Policies/QuoteLinePolicy.php
    - database/factories/Domain/Quotes/QuoteFactory.php
    - database/factories/Domain/Quotes/QuoteLineFactory.php
    - config/quote.php
    - tests/Architecture/DeptracQuotesLayerTest.php
    - tests/Unit/Domain/Quotes/Models/QuoteTest.php
    - tests/Unit/Domain/Quotes/Models/QuoteLineTest.php
  modified:
    - app/Domain/CRM/Models/BitrixEntityMap.php  # ENTITY_QUOTE_DEAL constant + quote_id fillable + scopeQuoteDeals + scopeForQuote
    - app/Domain/Alerting/Models/AlertRecipient.php  # receives_quote_alerts fillable + cast + scopeReceivingQuoteAlerts
    - app/Providers/AppServiceProvider.php  # Gate::policy bindings for Quote + QuoteLine
    - depfile.yaml  # Quotes layer + ruleset + CRM/Http allow-list extensions
    - deptrac.yaml  # mirrored — dual-config-sync
    - tests/Architecture/PolicyTemplateIntegrityTest.php  # floor 27→29 + Quote/QuoteLine policy pairs
    - .env.example  # 6 new QUOTE_* keys

key-decisions:
  - "A1 RESOLVED — VAT-INCLUSIVE storage convention LOCKED: unit_price_pence_at_quote + line_total_pence_at_quote are stored VAT-INCLUSIVE in pence integers (matches PriceCalculator::compute output). PDF strips VAT at render time via PriceCalculator::stripVat helper (Phase 3 D-05). NEVER store as decimal/float — Pitfall 1. Encoded in column comment + model docblock + integer cast Pest test."
  - "A2 RESOLVED (Rule 1 deviation) — bitrix_entity_map.entity_type was ENUM('deal','contact','company') in Phase 4 (plan A2 assumed VARCHAR). Migration 2026_05_01_010200 now MODIFIES the MySQL ENUM allow-list to include 'quote_deal' (preserves DB-level enum guarantee). SQLite no-op via DB::getDriverName() guard (test DB stores ENUMs as TEXT)."
  - "A9 RESOLVED — customer_group_name_at_quote denormalised VARCHAR(255) included on quotes table (CONTEXT.md Claude's Discretion). Snapshotted at quote creation; survives subsequent CustomerGroup rename via FK ON DELETE RESTRICT + denormalised string."
  - "OQ-1 RESOLVED — quotes.total_pence_at_quote cached UNSIGNED BIGINT column added. Plan 11-02 ships the recompute-on-save observer (draft only); after status=sent the column is locked alongside the lines."
  - "OQ-2 RESOLVED — Option (a) chosen: nullable quote_id CHAR(26) ULID column + composite UNIQUE(entity_type, quote_id) named bitrix_entity_map_entity_type_quote_id_unique. Coexists with the existing UNIQUE(entity_type, woo_id) for orders. Plan 11-04 EntityDeduper queries via two parallel scope methods (scopeForWooOrder + scopeForQuote)."
  - "D-06 reserved enum cases — QuoteStatus enum ships with PendingApproval + Approved cases for v1.x non-breaking extension; v1.0 transitions never branch on them. NO `withdrawn` case shipped (D-06 deferred — sales overwrites by editing draft instead). isReserved() helper exposes the reserved set for defensive guards in Plans 11-03/11-04."
  - "D-04 separation-of-duties locked at policy layer — QuotePolicy::approve explicitly DENIES users with sales role only (without admin/pricing_manager). T-11-01-03 mitigation. Plan 11-03 Filament Resource visualises as disabled button with tooltip 'ask pricing_manager or admin to approve'."
  - "PolicyTemplateIntegrityTest floor bumped 27 → 29. Adds app/Domain/Quotes/Policies path + QuotePolicy + QuoteLinePolicy → model bindings. Catches Shield {{ Placeholder }} regression on Quote policies on every CI run."

patterns-established:
  - "Phase 11 migration timestamps: 2026_05_01_010000 + 010100 + 010200 + 010300 — Phase 10 ended at 2026_04_28_*; Phase 11 starts at 2026_05_*"
  - "Quote ULID short form: ulidShort() method returns substr($this->id, 0, 8) — UI/PDF reference shows 'Quote #{ulid_short_8}' for human-friendly identifiers"
  - "Dual mode customer (D-01): nullable user_id FK + denormalised customer_email/name/billing_address — anonymous-lead path supports Phase 13 WhatsApp + Phase 14 chatbot + cold sales"
  - "Quotes Deptrac one-way arrow: Quotes domain emits QuoteApproved event; CRM domain consumes via PushQuoteToBitrix listener. Quotes ruleset has CRM (read-only model reference), CRM ruleset has Quotes (listener consumes Quote model). BitrixClient + BitrixEntityMap + push wiring stays inside CRM domain."

requirements-completed: [QUOT-01, QUOT-02]

# Metrics
duration: 28min
started: 2026-05-01T12:48:01Z
completed: 2026-05-01T13:16:06Z
tasks: 2
files_created: 16
files_modified: 7
---

# Phase 11 Plan 01: Foundation Schema + Models + Deptrac Layer Summary

**ULID-keyed Quote + QuoteLine schema with VAT-inclusive pence snapshots, parallel BitrixEntityMap quote_deal dedup ledger (composite UNIQUE alongside the existing order UNIQUE), 2 D-04 separation-of-duties policies, and a fresh Quotes Deptrac layer mirrored byte-identical across depfile.yaml + deptrac.yaml.**

## Performance

- **Duration:** ~28 min
- **Started:** 2026-05-01T12:48:01Z
- **Completed:** 2026-05-01T13:16:06Z
- **Tasks:** 2 (atomic commits)
- **Files created:** 16
- **Files modified:** 7

## Accomplishments

- 4 migrations applied cleanly against local SQLite (smoke-tested in tinker — ULID 26 chars, integer pence cast preserves 1999, JSON snapshot array cast works, ON DELETE CASCADE works)
- Quote + QuoteLine ULID Eloquent models with HasUlids + LogsActivity (Quote only — PII excluded from audit trail per T-11-01-04) + relations + ulidShort helper
- QuoteStatus 7-case enum with D-05/D-06 reserved cases (PendingApproval, Approved) WITHOUT `withdrawn` (D-06 deferred); isReserved() helper guards against accidental v1.0 use
- RejectionReason 5-case enum matching D-08
- QuotePolicy + QuoteLinePolicy with D-04 separation-of-duties enforced at the gate layer (sales role explicitly DENIED on approve), D-05 admin-only revert (5 min window), and D-13 line immutability (line edits forbidden after parent quote leaves draft)
- BitrixEntityMap extended with ENTITY_QUOTE_DEAL constant + quote_id fillable + scopeQuoteDeals + scopeForQuote — preserves Phase 4 dedup guarantees while opening the parallel quote-deal lookup path
- AlertRecipient extended with receives_quote_alerts (Plan 11-04/11-05 forward-compat); seeded fallback force-updated TRUE so Pitfall M can't strand quote alerts
- config/quote.php with 9 keys + .env.example with 6 new QUOTE_* keys (QUOTE_BITRIX_PUSH_ENABLED + QUOTE_BITRIX_TYPE_VERIFIED shadow + cutover gates)
- Quotes Deptrac layer registered in BOTH depfile.yaml AND deptrac.yaml (dual-YAML lockstep per Phase 5 P05-05); CRM allow-list extended with Quotes (PushQuoteToBitrix listener authorisation); Http allow-list extended with Quotes (Plan 11-03 Filament Resource access). Both deptrac analyse runs exit 0.
- DeptracQuotesLayerTest 5/5 PASS — dual-YAML structural + collector regex + CRM allow-list extension + 2× deptrac analyse exit-0
- PolicyTemplateIntegrityTest floor bumped 27 → 29; all 3 architecture-suite tests PASS
- DeptracTradePricingLayerTest + DeptracCrmLayerTest still PASS (no regressions from Quotes layer registration)

## Task Commits

Each task was committed atomically:

1. **Task 1: Migrations + BitrixEntityMap + AlertRecipient extensions** — `bc30c99` (feat)
2. **Task 2: Models + Enums + Policies + Factories + Config + Quotes Deptrac layer (dual-YAML) + DeptracQuotesLayerTest** — `99999bc` (feat)

_Note: Task 1 + Task 2 are non-TDD `auto` tasks per the plan tags; no separate test-first commits._

## Files Created

- `database/migrations/2026_05_01_010000_create_quotes_table.php` — ULID PK + 18 cols + status string col (sqlite-compat) + total_pence_at_quote cached + 4 status timestamps
- `database/migrations/2026_05_01_010100_create_quote_lines_table.php` — ULID PK + foreignUlid CASCADE + integer pence cols VAT-INCLUSIVE + product_snapshot JSON
- `database/migrations/2026_05_01_010200_add_quote_id_to_bitrix_entity_map_table.php` — composite UNIQUE(entity_type, quote_id); MODIFIES MySQL ENUM allow-list to include 'quote_deal' (Rule 1 deviation; SQLite no-op)
- `database/migrations/2026_05_01_010300_add_receives_quote_alerts_to_alert_recipients_table.php` — boolean column + force-update seeded fallback to TRUE
- `app/Domain/Quotes/Models/Quote.php` — HasUlids + LogsActivity + 7 STATUS_* constants + ulidShort + 3 relations
- `app/Domain/Quotes/Models/QuoteLine.php` — HasUlids + integer pence casts + product_snapshot array cast + BelongsTo Quote
- `app/Domain/Quotes/Enums/QuoteStatus.php` — 7-case string-backed enum + isReserved helper (NO `withdrawn` case)
- `app/Domain/Quotes/Enums/RejectionReason.php` — 5 D-08 cases
- `app/Domain/Quotes/Policies/QuotePolicy.php` — D-04 separation-of-duties + D-05 revert window + markAccepted/markRejected
- `app/Domain/Quotes/Policies/QuoteLinePolicy.php` — D-13 line immutability gate
- `database/factories/Domain/Quotes/QuoteFactory.php` — anonymous-lead draft default
- `database/factories/Domain/Quotes/QuoteLineFactory.php` — Pitfall 1 sentinel (1999 pence)
- `config/quote.php` — 9 keys (company identity + workflow + Bitrix push gates)
- `tests/Architecture/DeptracQuotesLayerTest.php` — 5 tests
- `tests/Unit/Domain/Quotes/Models/QuoteTest.php` — 4 schema-presence + 9 model behaviour tests
- `tests/Unit/Domain/Quotes/Models/QuoteLineTest.php` — 6 unit tests

## Files Modified

- `app/Domain/CRM/Models/BitrixEntityMap.php` — ENTITY_QUOTE_DEAL constant + 'quote_id' fillable + scopeQuoteDeals + scopeForQuote
- `app/Domain/Alerting/Models/AlertRecipient.php` — receives_quote_alerts fillable + boolean cast + scopeReceivingQuoteAlerts
- `app/Providers/AppServiceProvider.php` — Gate::policy bindings for Quote + QuoteLine
- `depfile.yaml` — Quotes layer (lines ~99-114) + Quotes ruleset (line ~265) + CRM extension (line ~169) + Http extension (line ~262)
- `deptrac.yaml` — mirrored Quotes layer (lines ~94-109) + Quotes ruleset (line ~284) + CRM extension (line ~177) + Http extension (line ~282)
- `tests/Architecture/PolicyTemplateIntegrityTest.php` — floor 27 → 29 + Quotes/Policies path + Quote/QuoteLine model→policy pairs
- `.env.example` — 6 new QUOTE_* keys appended in v2 Phase 11 section

## Decisions Made

| ID | Decision | Source |
|----|----------|--------|
| A1 RESOLVED | VAT-INCLUSIVE pence storage locked via column comment + model docblock + integer-cast Pest test (Pitfall 1) | Plan §A1 + RESEARCH §VAT math |
| A2 RESOLVED | Migration MODIFIES MySQL ENUM allow-list to include 'quote_deal' (preserves DB-level enum guarantee); SQLite no-op via driver guard. **Rule 1 deviation — A2 originally assumed VARCHAR but found ENUM in Phase 4** | Phase 4 migration inspection |
| A9 RESOLVED | customer_group_name_at_quote denormalised VARCHAR(255) included on quotes table | CONTEXT.md Claude's Discretion |
| OQ-1 RESOLVED | quotes.total_pence_at_quote cached UNSIGNED BIGINT column; recompute observer ships in Plan 11-02 | RESEARCH §Open Q1 |
| OQ-2 RESOLVED | Option (a): nullable quote_id ULID + composite UNIQUE(entity_type, quote_id) coexists with existing UNIQUE(entity_type, woo_id) | RESEARCH §Open Q2 |
| D-06 deferred | NO `withdrawn` case in QuoteStatus enum — sales overwrites by editing draft. PendingApproval + Approved RESERVED but unused in v1.0 | CONTEXT.md D-06 |
| D-04 enforced at policy | QuotePolicy::approve explicitly DENIES sales-only users (T-11-01-03 mitigation); 4-eyes pattern in place before Filament UI | CONTEXT.md D-04 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] bitrix_entity_map.entity_type is ENUM, not VARCHAR (plan A2 mismatch)**
- **Found during:** Task 1 (planning the BitrixEntityMap migration)
- **Issue:** Plan A2 stated "BitrixEntityMap.entity_type column type verified VARCHAR (no migration needed); new value 'quote_deal' accepted" but inspection of Phase 4 migration `2026_04_20_080000_create_bitrix_entity_map_table.php` showed it's actually a MySQL `ENUM('deal', 'contact', 'company')`. Inserting `'quote_deal'` would trigger SQL error 1265 (data truncated) on MySQL.
- **Fix:** Migration `2026_05_01_010200` now wraps an `ALTER TABLE ... MODIFY COLUMN ... ENUM('deal', 'contact', 'company', 'quote_deal')` statement in a `DB::getDriverName() === 'mysql'` guard. SQLite stores ENUMs as TEXT and accepts any value, so the statement is a MySQL-only no-op on the test DB. The down() method reverts to the original 3-value ENUM.
- **Files modified:** `database/migrations/2026_05_01_010200_add_quote_id_to_bitrix_entity_map_table.php`
- **Verification:** Migration applies cleanly against SQLite (`php artisan migrate` passed); on MySQL the `MODIFY COLUMN` is the standard idempotent ENUM-extension pattern. The choice preserves Phase 4's DB-level enum-validation guarantee instead of relaxing to VARCHAR.
- **Committed in:** `bc30c99` (Task 1 commit)

**2. [Rule 2 - Missing critical] Force-update seeded fallback for receives_quote_alerts**
- **Found during:** Task 1 (writing the alert_recipients migration)
- **Issue:** Plan instructed `default(false)` on `receives_quote_alerts` but didn't specify the seeded fallback row update. Without the force-update, the Pitfall M "no active recipient" outage discovered in Phase 4 / Phase 5 / Phase 8 alert extensions would strand quote alerts in production until ops manually flips the toggle.
- **Fix:** Migration appends `DB::table('alert_recipients')->where('email', 'ops@meetingstore.co.uk')->update(['receives_quote_alerts' => true])` — same pattern as Phase 4 plan 03 + Phase 5 plan 01 + Phase 8 plan 01.
- **Files modified:** `database/migrations/2026_05_01_010300_add_receives_quote_alerts_to_alert_recipients_table.php`
- **Verification:** Migration applies cleanly; future Plan 11-04 alert dispatch can rely on at least one active recipient existing.
- **Committed in:** `bc30c99` (Task 1 commit)

**3. [Rule 1 - Bug] Pest expect()->toContain() does not accept a custom message argument**
- **Found during:** Task 2 (running DeptracQuotesLayerTest)
- **Issue:** Wrote `expect($crmAllowed)->toContain('Quotes', "CRM ruleset in {$yamlPath} is missing Quotes...")` — pest interprets the second arg as an additional value to check (so the assertion looks for both `'Quotes'` AND the message string in the array, both of which must be present). The test failed because the message string isn't in the CRM allow-list.
- **Fix:** Removed the second-argument message; the test name itself communicates the assertion failure context. Single-arg `toContain('Quotes')` is the correct pest signature.
- **Files modified:** `tests/Architecture/DeptracQuotesLayerTest.php`
- **Verification:** All 5 DeptracQuotesLayerTest tests now PASS (verified via `php artisan test tests/Architecture/DeptracQuotesLayerTest.php`).
- **Committed in:** `99999bc` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (1 schema bug, 1 missing-critical force-update, 1 test syntax bug)
**Impact on plan:** All deviations were correctness fixes; no scope creep. Schema deviation #1 actually STRENGTHENS the implementation by preserving the Phase 4 DB-level enum guarantee instead of relaxing to VARCHAR.

## Issues Encountered

- **MySQL `meetingstore_ops_testing` DB offline locally.** Same constraint as Phase 6/7/8/9/10 — phpunit.xml configures the test DB as MySQL `meetingstore_ops_testing` per Phase 1 P03 lesson, but the local dev box runs SQLite for day-to-day work. Result: all 13 schema-presence + model behaviour Pest tests in `tests/Unit/Domain/Quotes/Models/*` are deferred until CI runs (or until the local MySQL service is started). This matches the deferred-tests block in every prior phase summary. Mitigations:
  - Migration smoke-tested via `php artisan migrate` against local SQLite (PASSED — all 4 migrations applied cleanly).
  - Quote/QuoteLine + factory + cascade smoke-tested via `php artisan tinker` (PASSED — ULID 26 chars, integer cast preserves 1999, snapshot array cast works, ON DELETE CASCADE removes lines on Quote delete).
  - Gate::policy bindings smoke-tested via `Gate::getPolicyFor()` (PASSED for both Quote and QuoteLine).
  - Architecture suite ran end-to-end: 61 passed / 10 deferred-DB failures. **All Phase 11 Plan 01 architecture-test additions PASS** (DeptracQuotesLayerTest 5/5; PolicyTemplateIntegrityTest 3/3; pre-existing DeptracTradePricingLayerTest + DeptracCrmLayerTest unaffected).

- **Pre-existing untracked files** (`.planning/phases/09.1-integration-connections-admin/`, `.planning/phases/11-e2-quote-request-bitrix-deal-flow/11-02-PLAN.md`, `11-05-PLAN.md`, `app/Foundation/Integration/Policies/`) were left UNCOMMITTED — out of scope for Plan 11-01. They will be committed by their respective plans (11-02, 11-05, and the 09.1 Integration Connections phase).

## Self-Check: PASSED

Verified after writing SUMMARY.md:

| Item | Status |
|------|--------|
| `database/migrations/2026_05_01_010000_create_quotes_table.php` | FOUND |
| `database/migrations/2026_05_01_010100_create_quote_lines_table.php` | FOUND |
| `database/migrations/2026_05_01_010200_add_quote_id_to_bitrix_entity_map_table.php` | FOUND |
| `database/migrations/2026_05_01_010300_add_receives_quote_alerts_to_alert_recipients_table.php` | FOUND |
| `app/Domain/Quotes/Models/Quote.php` | FOUND |
| `app/Domain/Quotes/Models/QuoteLine.php` | FOUND |
| `app/Domain/Quotes/Enums/QuoteStatus.php` | FOUND |
| `app/Domain/Quotes/Enums/RejectionReason.php` | FOUND |
| `app/Domain/Quotes/Policies/QuotePolicy.php` | FOUND |
| `app/Domain/Quotes/Policies/QuoteLinePolicy.php` | FOUND |
| `database/factories/Domain/Quotes/QuoteFactory.php` | FOUND |
| `database/factories/Domain/Quotes/QuoteLineFactory.php` | FOUND |
| `config/quote.php` | FOUND |
| `tests/Architecture/DeptracQuotesLayerTest.php` | FOUND |
| `tests/Unit/Domain/Quotes/Models/QuoteTest.php` | FOUND |
| `tests/Unit/Domain/Quotes/Models/QuoteLineTest.php` | FOUND |
| Commit `bc30c99` (Task 1) | FOUND in `git log` |
| Commit `99999bc` (Task 2) | FOUND in `git log` |
| Migration `2026_05_01_010000_create_quotes_table` applied | FOUND in `migrate:status` output |
| Migration `2026_05_01_010100_create_quote_lines_table` applied | FOUND |
| Migration `2026_05_01_010200_add_quote_id_to_bitrix_entity_map_table` applied | FOUND |
| Migration `2026_05_01_010300_add_receives_quote_alerts_to_alert_recipients_table` applied | FOUND |
| Deptrac depfile.yaml: 0 violations | VERIFIED |
| Deptrac deptrac.yaml: 0 violations | VERIFIED |
| DeptracQuotesLayerTest: 5/5 PASS | VERIFIED |
| PolicyTemplateIntegrityTest: 3/3 PASS (floor 27→29) | VERIFIED |
| DeptracTradePricingLayerTest: 4/4 PASS (no regression) | VERIFIED |
| DeptracCrmLayerTest: 2/2 PASS (no regression) | VERIFIED |

## Next Phase Readiness

**Plan 11-02 (PriceSnapshotter + immutability observer) is unblocked:**
- Quote + QuoteLine models exist with HasUlids + factories
- TradeRuleResolver::resolveForQuote(sku, customer_group_id) thin delegate is the next addition (Phase 9 service, Plan 11-02 task)
- D-13 invariant target: QuoteLineImmutabilityObserver throws on `saving` when status != draft AND price/snapshot is dirty
- quotes.total_pence_at_quote recompute-on-save observer (OQ-1 follow-up) ships in Plan 11-02
- QuotePdfPriceImmunityTest regression test (ROADMAP success criterion 1) ships in Plan 11-02

**Plan 11-03 (Filament QuoteResource) is unblocked:**
- QuotePolicy + QuoteLinePolicy registered (Gate::policy bindings) — Filament Action `->authorize('approve')` will resolve correctly
- Sales nav group + Filament Resource scaffolding ready
- D-04 separation-of-duties already enforced at policy layer; Filament action can rely on it

**Plan 11-04 (Bitrix push pipeline) is unblocked:**
- BitrixEntityMap.ENTITY_QUOTE_DEAL constant + scopeForQuote(string) ready for EntityDeduper.findDealByQuoteId
- bitrix_entity_map.quote_id column + composite UNIQUE index in place — DB-level race-condition guard for parallel push jobs
- QUOTE_BITRIX_PUSH_ENABLED env gate + sync_diffs (provider='bitrix-quote') routing ready
- AlertRecipient.receives_quote_alerts ready for push-failed DLQ alert routing

**Plan 11-05 (quotes:expire + verification) is unblocked:**
- (status, expires_at) composite index in place — quotes:expire query is index-covered
- QUOTE_EMAIL_ON_EXPIRY config flag in place — opt-in customer email path ready
- QUOTE_BITRIX_TYPE_VERIFIED cutover gate ready for ops flip-on after pre-flight check

**Open items deferred to Plan 11-02:**
- PriceSnapshotter service implementation
- QuoteLineImmutabilityObserver model observer
- Quote::saving observer that recomputes total_pence_at_quote on draft saves
- QuotePdfPriceImmunityTest regression Pest test (creates Quote, edits underlying PricingRule, asserts QuoteLine snapshot unchanged)

**Open items deferred to MySQL `meetingstore_ops_testing` provisioning** (Phase 1 P03 follow-up):
- 4 QuoteTest schema-presence Pest tests
- 9 QuoteTest model behaviour Pest tests
- 6 QuoteLineTest unit tests

---

*Phase: 11-e2-quote-request-bitrix-deal-flow*
*Plan: 01 — Foundation: schema + models + enums + policies + factories + config + Quotes Deptrac layer (dual-YAML)*
*Completed: 2026-05-01*
