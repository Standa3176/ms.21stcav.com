# margin_change Suggestion.evidence Schema

**Audience:** v2 maintainers touching Phase 5 (`Competitor`) or Phase 10
(`Agents`/Pricing) code that reads or writes `Suggestion.evidence` for
`kind='margin_change'` rows.

**Status:** Locked at Plan 10-04 ship. Phase 5 keys are the contract Phase 10
depends on; Phase 10 keys overlay onto the same JSON column. **Both phases
must respect the rules below or the agent enrichment + Filament UI silently
break.**

**Tests that lock this contract:**

- `tests/Feature/Suggestions/MarginChangeEvidenceContractTest.php` — Phase 5 key
  presence + types (Plan 10-04 P10-G defence)
- `tests/Architecture/MarginChangeApplierUnchangedTest.php` — Phase 5 applier
  byte-identity sha256 (Phase 9 B-03 precedent)
- `tests/Unit/Domain/Agents/Services/PricingAgentResultMapperTest.php` — Phase
  10 mapper extraction + 10-cap + terminal states

---

## 1. Phase 5 producer — `ComputeMarginSuggestionJob`

Located at `app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php` lines
156-180. Verbatim copy of the produced shape:

```php
$evidence = [
    'competitor_id'             => int,    // FK to competitors.id
    'competitor_name'           => string, // 'Acme AV Distributor Ltd'
    'sku'                       => string, // 'LOGI-MEETUP'  ← READ BY MAPPER + UI
    'last_3_competitor_prices'  => array,  // last 3 CompetitorPrice rows for the (sku, competitor) pair
    'our_sell_price_pennies'    => int,    // products.sell_price * 100
    'our_supplier_price_pennies'=> int,    // products.buy_price * 100
    'our_current_margin_bps'    => int,    // pricing_rules.margin_basis_points  ← READ BY UI
    'proposed_margin_bps'       => int,    // MarginAnalyser->computeProposal()  ← READ BY OUT-OF-BAND CHIP
    'margin_delta_bps'          => int,    // abs(current - proposed)
    'sales_count_90d'           => int,    // products.last_sales_count_90d
    'pricing_rule'              => [
        'id'                  => int,    // pricing_rules.id
        'scope'               => string, // 'global' | 'brand' | 'category' | 'brand_category'  ← READ BY UI
        'current_margin_bps'  => int,    // mirror of pricing_rules.margin_basis_points
        'resolution_source'   => string, // 'rule' | 'override' (RuleResolver output)
    ],
    'beat_by_pennies'           => int,    // proposed_pennies vs latest_competitor_price_pennies
];
```

**Phase 5 invariant — these keys MUST exist for every margin_change row:**

| Key | Type | Read by Phase 10 |
|---|---|---|
| `sku` | string | PricingAgentResultMapper (user message) + tools |
| `proposed_margin_bps` | int | OUT-OF-BAND chip (v1 deterministic value) |
| `our_current_margin_bps` | int | Filament v1 deterministic card |
| `pricing_rule.scope` | string | Filament v1 deterministic card |

If a future Phase 5 refactor renames any of these keys, Phase 10 silently breaks.
`MarginChangeEvidenceContractTest` catches the regression at the test layer.

---

## 2. Phase 10 enrichment overlay — `PricingAgentResultMapper`

Located at `app/Domain/Agents/Services/PricingAgentResultMapper.php`. Adds keys
to the SAME `evidence` JSON; never removes or renames Phase 5 keys.

```php
$evidence = [
    // ... Phase 5 keys above stay untouched ...

    // Added on every PricingAgent run (regardless of terminal state):
    'agent_run_ids'              => array,  // ULIDs, capped at 10 latest (P10-E)
    'agent_run_status'           => string, // 'completed' | 'no_proposal' | 'malformed_proposal'
    'agent_run_completed_at'     => string, // ISO-8601

    // Added on `completed` terminal state ONLY (overwritten by latest run):
    'agent_reasoning'            => string, // ≤4096 chars, markdown-rendered in UI
    'agent_confidence_0_to_100'  => int,    // 0-100, badge-coloured by D-07 bands
    'agent_proposed_band_min_bps'=> int,    // ≥0, ≤ band_max_bps
    'agent_proposed_band_max_bps'=> int,    // ≥ band_min_bps
    'agent_proposed_bps'         => int,    // model's central proposal (mid of band)

    // Added on out-of-band approve via SuggestionResource action (D-08):
    'out_of_band_approval'       => [
        'deterministic_bps'      => int,
        'band_min_bps'           => int,
        'band_max_bps'           => int,
        'reason'                 => string, // ≥10 chars, ≤2000 (modal validation)
        'approved_by_user_id'    => int,
        'approved_at'            => string, // ISO-8601
        'latest_agent_run_id'    => string, // ULID
    ],
];
```

**Phase 10 invariants:**

- Mapper appends `agent_run_ids[]` and trims to the latest 10 entries
  (`PricingAgentResultMapper::RUN_IDS_CAP`). Older entries are dropped
  permanently — only the 5-year `agent_runs` retention preserves the full
  history.
- `agent_run_status='no_proposal'` and `'malformed_proposal'` PRESERVE the
  prior `agent_*` enrichment fields. Admin sees the last successful proposal
  + a chip flagging the latest run's terminal state.
- The mapper sets `Suggestion.proposed_by_type = AgentRun::class` and
  `proposed_by_id = $latestRun->id` on every successful merge (Phase 1 D-14
  morph activation). Filament displays "Proposed by: Agent pricing run
  {ulid-prefix}".

---

## 3. OUT-OF-BAND detection logic

Located at `SuggestionResource::computeOutOfBand()`. Pure function:

```php
$deterministic = (int) data_get($r->evidence, 'proposed_margin_bps', 0);
$bandMin       = (int) data_get($r->evidence, 'agent_proposed_band_min_bps', 0);
$bandMax       = (int) data_get($r->evidence, 'agent_proposed_band_max_bps', 0);

if ($bandMin === 0 && $bandMax === 0) {
    return '';  // no agent enrichment yet — chip hidden
}

return ($deterministic < $bandMin || $deterministic > $bandMax)
    ? 'OUT-OF-BAND'
    : 'IN-BAND';
```

**Three states surfaced in the Filament UI badge:**

| State | Color | Meaning |
|---|---|---|
| `''` (empty placeholder `—`) | `gray` | No agent run yet — admin should run the agent before approving |
| `'IN-BAND'` | `success` (green) | v1's deterministic margin sits inside the agent's confidence band — no special approval flow |
| `'OUT-OF-BAND'` | `danger` (red) | v1's deterministic margin is outside the agent's band — approve action gains a required `out_of_band_reason` form field (D-08) |

---

## 4. `out_of_band_approval` JSON shape

Written by `SuggestionResource::approve_margin_change` action when (and only
when) `computeOutOfBand($record) === 'OUT-OF-BAND'`. Captured BEFORE the
status flip so a subsequent failure doesn't orphan the audit trail (Phase 1
FOUND-04 pattern).

```php
$evidence['out_of_band_approval'] = [
    'deterministic_bps'   => 2200,                    // v1's proposed_margin_bps
    'band_min_bps'        => 1800,                    // agent's lower bound
    'band_max_bps'        => 2050,                    // agent's upper bound
    'reason'              => 'agent reasoning missed Q1 tariff impact on supply costs',
    'approved_by_user_id' => 7,                       // auth()->id()
    'approved_at'         => '2026-04-30T14:23:11+01:00',
    'latest_agent_run_id' => '01HX...',               // tail of agent_run_ids[]
];
```

In the same transaction, `Auditor::record('approved_margin_change_out_of_band',
[...])` writes to `audit_log` with the same fields plus `correlation_id`. Two
sources of truth so a dashboard query for "out-of-band approvals last 30d"
can use whichever is more performant.

---

## Architectural rules

1. **Phase 5 keys are the contract.** Phase 5 may add new keys to its own
   evidence shape (with deferred-items.md note); Phase 5 may NEVER rename
   or remove a key Phase 10 reads.
2. **Phase 10 keys overlay; never replace.** The mapper merges into the
   existing `evidence` array; Phase 5 keys flow through untouched.
3. **`out_of_band_approval` is write-only from the approve action.** No
   read-back is required for v2.0 — Filament queries the `audit_log` table
   directly when the dashboard needs an out-of-band approval list.
4. **`agent_run_ids[]` is the only growing array.** Capped at 10 entries
   (`PricingAgentResultMapper::RUN_IDS_CAP`). Every other agent_* key is a
   scalar overwritten by the latest run.

---

*Phase: 10-c1-pricing-agent / Plan 04*
*Last updated: 2026-04-30*
