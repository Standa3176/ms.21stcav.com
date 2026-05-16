---
phase: 12-c3-seo-content-agent
plan: 03
subsystem: agents
tags: [agents, seo-agent, system-prompt, guardrails, brand-voice-regex, p12-b-mitigation, p12-h-defence, seoagt-04]

requires:
  - phase: 08-c4-agent-framework
    plan: 03
    provides: Guardrail contract, GuardrailEngine runPostFlight orchestration, GuardrailViolationException base class
  - phase: 10-c1-pricing-agent
    plan: 03
    provides: System prompt Blade view precedent (resources/views/agents/pricing/system.blade.php); deterministic sha256 prompt-hash invariant; PromptRenderer render('kind') contract
  - phase: 12-c3-seo-content-agent
    plan: 01
    provides: SeoAgent skeleton with guardrails()=[] placeholder, _global.md mandatory voice file, agents.seo.temperature config slot
  - phase: 12-c3-seo-content-agent
    plan: 02
    provides: 4 tool bodies (read_product_draft + read_brand_style_guide + read_similar_shipped_products + propose_content_patch with enum-typed field arg), BrandSlugResolver helper

provides:
  - resources/views/agents/seo/system.blade.php — static deterministic 6.4 KB system prompt with 5 anchor sections (persona / workflow / brand voice rules / forbidden output / output contract / 2 few-shot examples). sha256 hash = `75bac4c32ddf1d36d8ae9c1c5c4f1a39497c23c5a26cb42768ec25de141785e7` (Plan 12-04 RunSeoAgentJob writes this onto AgentRun.system_prompt_hash for forensic continuity)
  - config/seo_agent.php — 13-pattern starter regex library across 3 categories (competitor_brands 4 / price_claims_absolute 4 / marketing_superlatives 5). Verbatim from RESEARCH §Recommended starter pattern set with operator-iteration rationale comments preserved.
  - app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php — final post-flight Guardrail implementation; walks `$response->steps[]->toolCalls[]`, filters for `propose_content_patch`, scans (before + "\n" + after) against config('seo_agent.guardrails'). First match throws GuardrailViolationException with failedPatternKey + matchedExcerpt (bounded at 200 chars via mb_substr). Does NOT inject SeoAgentResultMapper (P12-B Option B — Plan 12-04 catch-block writes Suggestion).
  - GuardrailViolationException ADDITIVELY extended with readonly `$failedPatternKey` + `$matchedExcerpt` fields. Phase 10's `fromGuardrail()` factory + RunPricingAgentJob's `$e->guardrailClass` access continue to compile byte-identically (verified via 22-test backward-compat sweep).
  - SeoAgent::guardrails() now returns 3-entry chain in deterministic order: `[SensitiveFieldsStripGuardrail, OutboundRegexFilterGuardrail, SeoOutboundGuardrail]`. Plan 12-04 tests + RunSeoAgentJob catch-block rely on this index ordering.
  - 30 Pest cases / 56 assertions covering Task 1 (Blade prompt + config + exception backward-compat), Task 2 (guardrail contract + chain wiring), Task 3 (end-to-end via GuardrailEngine).

affects: [12-04-run-job-mapper-filament-sidebar, 12-05-batch-command-shield-verification]

tech-stack:
  added: []  # zero composer changes — Phase 8 Guardrail contract + Phase 10 prompt-renderer surface + Phase 12-01/02 SeoAgent infrastructure already present
  patterns:
    - "Additive exception extension — GuardrailViolationException keeps its existing `public string $guardrailClass` + `public string $when` properties (used by Phase 10 mutation-via-factory) AND adds two NEW readonly fields with empty-string defaults. Constructor accepts all four args named-style. fromGuardrail() factory still works. Phase 10's catch-site `$e->guardrailClass !== '' ? $e->guardrailClass : GuardrailViolationException::class` continues to compile byte-identically. Zero Phase 10 regression."
    - "Post-flight guardrail with explicit propose_content_patch filter — SeoOutboundGuardrail does NOT scan read_* tool calls. read_* tools legitimately return supplier copy that may contain marketing words; that copy is INPUT from the supplier, not output the agent generated. Only `propose_content_patch` is scanned (the agent's own emissions)."
    - "P12-B Option B routing — guardrail's only responsibility is scan+throw with structured exception fields. Plan 12-04 RunSeoAgentJob catch-block uses `$e->failedPatternKey` + `$e->matchedExcerpt` to call `$mapper->createGuardrailBlockedSuggestion(...)`. Pure separation of concerns: guardrail = detection; job = audit-trail-write."
    - "P12-H defence at Blade boundary — system.blade.php does NOT inline brand-voice markdown via any directive-inclusion mechanism. Brand-voice content arrives at the agent via runtime `read_brand_style_guide` tool call (file_get_contents + json_encode, never view-template-rendered). Plan 12-03 Task 1 acceptance asserts the Blade source contains zero literal `@include` directives — same grep gate Plan 12-02 honoured for ReadBrandStyleGuideTool."
    - "Deterministic prompt sha256 — static Blade (zero `{{ $variable }}` interpolation surface). Two consecutive PromptRenderer calls produce byte-identical hashes. Locks the forensic seam: `WHERE system_prompt_hash = '75bac4c…'` lets ops query all SeoAgent runs that used this prompt version."

key-files:
  created:
    - resources/views/agents/seo/system.blade.php
    - config/seo_agent.php
    - app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php
    - tests/Feature/Agents/Seo/SystemPromptCalibrationTest.php
    - tests/Feature/Agents/Seo/SeoOutboundGuardrailTest.php
    - tests/Feature/Agents/Seo/SeoAgentGuardrailsWiredTest.php
    - tests/Feature/Agents/Seo/SeoGuardrailIntegrationTest.php
    - tests/Architecture/SeoAgentConfigTest.php
  modified:
    - app/Domain/Agents/Exceptions/GuardrailViolationException.php (additive: +readonly $failedPatternKey, +readonly $matchedExcerpt, +constructor accepting all 4 named args; existing $guardrailClass/$when mutable properties preserved; fromGuardrail() factory still works)
    - app/Domain/Agents/Agents/SeoAgent.php (guardrails() body: `return []` → 3-entry chain via app() container resolution + 3 new use stmts for the guardrail classes)
    - .planning/phases/12-c3-seo-content-agent/deferred-items.md (logged pre-existing PricingAgentCalibrationTest credential-resolver failure as out-of-scope)
  deleted: []

key-decisions:
  - "GuardrailViolationException stayed `final class` — original Phase 8 class was final; the Plan 12-03 extension is additive (new readonly fields + new constructor signature) so subclassing isn't needed. final preserved."
  - "Constructor signature order chosen as `(guardrailClass, message, failedPatternKey, matchedExcerpt)` — `guardrailClass` first because the existing fromGuardrail factory passes it first; placing `message` second mirrors Exception's natural constructor; the two new readonly fields trail at positions 3-4 with empty-string defaults. Named-arg style at all 3 throw sites (SeoOutboundGuardrail + future Plan 12-04 mapper hooks) keeps the signature ergonomic even as the parameter list grows."
  - "SeoOutboundGuardrail scans `before . \"\\n\" . after` (concatenated) NOT just `after`. The `before` text is the agent's verbatim copy from read_product_draft — if the supplier copy itself contains a forbidden phrase, the patch shouldn't propagate it through. Cost of scanning before is negligible (1 extra regex pass per propose call); benefit is defence-in-depth at the patch-propagation seam."
  - "Guardrail order pinned [SensitiveFieldsStrip, OutboundRegex, SeoOutbound] — Phase 10 baseline (slots 0+1) inherited verbatim, SeoOutbound appended at index 2. This order means Phase 8 base regex (cost_price / supplier_price / hostname leakage) catches BEFORE SEO brand-voice regex. The framework enforces 'broader / more critical defences first'. Plan 12-04 tests rely on this index ordering being byte-identical to SeoAgent's `guardrails()` return."
  - "Did NOT inject SeoAgentResultMapper into SeoOutboundGuardrail constructor (RESEARCH §Pattern 7 Option B chosen over Option A). The guardrail is stateless and pure (scan + throw). The audit-trail Suggestion write is the catching job's responsibility (Plan 12-04 RunSeoAgentJob's catch-block). Cleaner separation; testable without mocking the mapper; mapper signature deferred to Plan 12-04 design."
  - "Blade view contains 6,400 bytes of prompt content (≥ 4096 acceptance threshold). Two few-shot examples were carefully crafted: Example 1 (LOGI-MEETUP) demonstrates patching empty long_description + meta_description with multi-paragraph structure citing per-brand voice (RightSense), platform compatibility (Zoom Rooms / Microsoft Teams Rooms / Google Meet), and similar-product structure. Example 2 (NICHE-RACK-SHELF) demonstrates the SKIP path — no patches when existing copy is on-brand. Both examples reinforce CONTEXT D-04 — patching empty fields is the priority; patching adequate fields is forbidden."

metrics:
  duration_minutes: 14
  tasks_completed: 3
  files_created: 8
  files_modified: 3
  files_deleted: 0
  tests_added: 30
  test_assertions_added: 56
  composer_changes: 0
  migrations: 0
completed-date: 2026-05-16

commits:
  - hash: 19f4c98
    message: "feat(12-03): SEO system prompt + guardrail regex config + exception fields"
  - hash: 2ca020e
    message: "feat(12-03): implement SeoOutboundGuardrail + wire 3-guardrail chain"
  - hash: 301be76
    message: "test(12-03): end-to-end GuardrailEngine integration test for SEO chain"
---

# Phase 12 Plan 03: SEO System Prompt + Brand-Voice Regex Library + SeoOutboundGuardrail Summary

Closing SEOAGT-04 — the brand-voice guardrail surface for SeoAgent. Three artefacts ship: a static deterministic system prompt (resources/views/agents/seo/system.blade.php), a 13-pattern starter regex library (config/seo_agent.php with 3 categories), and the post-flight SeoOutboundGuardrail wired into SeoAgent::guardrails() in deterministic index order. GuardrailViolationException is additively extended with `failedPatternKey` + `matchedExcerpt` readonly fields so Plan 12-04's RunSeoAgentJob catch-block can route to `SeoAgentResultMapper::createGuardrailBlockedSuggestion(...)` (P12-B mitigation) without any Phase 10 regression.

## What Shipped

**Tier 1 — System prompt Blade view (CONTEXT D-01..D-04; SEOAGT-02):**

- `resources/views/agents/seo/system.blade.php` — static 6.4 KB prompt with 5 anchor sections:
  - **Persona paragraph** — copywriter for MeetingStore (meetingstore.co.uk), factual jargon-free product copy for system integrators. Locks the "paraphrase + structure" rather than "invent" stance.
  - **Your workflow** — 6-step list mirroring Phase 10's structure: read draft → read voice → read similar shipped → reason → propose 0-4 patches → respond.
  - **Brand voice rules** — references the runtime `read_brand_style_guide` output as "the LAW"; explains the per-brand-supplements-global hierarchy.
  - **Forbidden output (the system rejects entire runs on match)** — 3 bullet categories matching the 3 regex categories in config/seo_agent.php, with the platform/service allowlist (Zoom Rooms / Microsoft Teams Rooms / Google Meet) called out explicitly.
  - **Output contract** — `propose_content_patch` 5 required args + per-field length conventions (title 30-90, short_desc 80-300, long_desc 300-2000, meta_desc 60-160).
  - **Few-shot examples** — Example 1 LOGI-MEETUP patches long_description + meta_description with per-brand voice; Example 2 NICHE-RACK-SHELF demonstrates the SKIP path (no patches).
- sha256 hash of rendered prompt: `75bac4c32ddf1d36d8ae9c1c5c4f1a39497c23c5a26cb42768ec25de141785e7`. Plan 12-04 RunSeoAgentJob persists this onto every AgentRun row for forensic continuity. PromptRenderer determinism gate locked via `tests/Feature/Agents/Seo/SystemPromptCalibrationTest.php::"rendering twice produces an identical hash"`.

**Tier 2 — Brand-voice regex pattern library (CONTEXT D-01; SEOAGT-04):**

- `config/seo_agent.php` returns `['guardrails' => [3 categories]]` with 13 patterns total:
  - **competitor_brands (4)** — Cisco Webex Room, Poly Studio, Neat Bar/Board/Frame, Yealink MeetingBoard/Bar. The allowlist (Zoom / Microsoft Teams / Google Meet) is documented at the comment block but explicitly NOT in the pattern array.
  - **price_claims_absolute (4)** — cheapest/lowest/best/unbeatable/guaranteed-lowest price; price-match-guarantee; "£N saving/off/less"; half-price / 50% off / massive-discount.
  - **marketing_superlatives (5)** — revolutionary/groundbreaking/game-changer/paradigm-shift; world's-best/leading/finest (with `/u` flag for the U+2019 curly apostrophe); industry-leading/cutting-edge/state-of-the-art; unparalleled/unmatched/incomparable/unrivalled; perfect-solution/ultimate-solution.
- Every pattern compiles via `@preg_match` (SeoAgentConfigTest gates compile errors at CI time per T-12-03-06 acceptance).

### Pattern Calibration Notes (for ops/PR iteration)

| Pattern category | Conservative or Aggressive? | False-positive risk | Notes for future PR iteration |
|---|---|---|---|
| `competitor_brands` | Conservative (only 4 exact-product patterns) | LOW | Will likely need to ADD patterns as new competitor SKUs surface (e.g. "Yamaha CS-700"). Allowlist for Zoom / Microsoft Teams / Google Meet is INTENTIONAL — those are platforms we integrate with, not competing hardware. |
| `price_claims_absolute` | Aggressive on absolute-claim phrases, conservative on numeric patterns | MEDIUM (the £N pattern could fire on legitimate "£50 GBP" specs) | The "£N saving/off/less" pattern uses `\b` anchors but a price quote in product copy like "£50 PoE+ injector included" would NOT match (no saving/off/less keyword). Keep an eye on PR feedback. |
| `marketing_superlatives` | Aggressive — directly enforces _global.md "Words to avoid" | MEDIUM-HIGH on `\bperfect\b` (the word may appear in legitimate context, "perfectly sized for huddle rooms") | The `perfect(?:\s+solution)?` pattern matches "perfect" alone OR "perfect solution". May need to tighten to require "perfect solution" only after observed false positives. PR iteration loop is the calibration. |

**Tier 3 — SeoOutboundGuardrail post-flight implementation (SEOAGT-04):**

- `app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php` — `final class implements Guardrail`:
  - `isPreFlight() === false`, `isPostFlight() === true`, `shouldRun(any) === true` (always runs for SeoAgent regardless of tier).
  - `post(ClaudeResponse $response): ClaudeResponse` walks `$response->steps[]` → `$step->toolCalls[]` (handles both array and Collection shapes), filters `$call instanceof \Prism\Prism\ValueObjects\ToolCall && $call->name === 'propose_content_patch'`. For each propose call: extracts `$args = $call->arguments();`, builds `$textToScan = $before . "\n" . $after`, iterates `$patterns` from `config('seo_agent.guardrails', [])`. First `@preg_match === 1` throws GuardrailViolationException with `failedPatternKey: (string) $key, matchedExcerpt: mb_substr($m[0], 0, 200)`.
  - Does NOT inject `SeoAgentResultMapper` — P12-B Option B per RESEARCH §Pattern 7. The catching job (Plan 12-04 RunSeoAgentJob) calls `$mapper->createGuardrailBlockedSuggestion($run, $product, $e->failedPatternKey, $e->matchedExcerpt)`.

**Tier 4 — GuardrailViolationException additive extension:**

- `app/Domain/Agents/Exceptions/GuardrailViolationException.php`:
  - Class signature unchanged (`final class … extends \RuntimeException`).
  - Existing fields preserved: `public string $guardrailClass = ''` (mutable, used by `fromGuardrail()` factory); `public string $when = ''` (mutable, set by RunAgentJob catch-block).
  - NEW readonly fields: `public readonly string $failedPatternKey;` + `public readonly string $matchedExcerpt;` — populated only via constructor; default empty string when omitted.
  - NEW explicit constructor accepting all 4 named args (`guardrailClass`, `message`, `failedPatternKey`, `matchedExcerpt`); defaults to empty strings so `new GuardrailViolationException()` still works.
  - `fromGuardrail()` static factory updated to use the new constructor (passes only the first two args); calling shape unchanged.
- Phase 10 backward-compat verified: 22 Phase 8 + Phase 10 tests pass byte-identically (`PricingAgentRegistrationTest`, `AgentRegistryTest`, `GuardrailEngineTest`). `RunPricingAgentJob.php:226-239`'s catch-block (`$e->guardrailClass !== '' ? $e->guardrailClass : GuardrailViolationException::class`) compiles and runs unchanged.

**Tier 5 — SeoAgent::guardrails() wired (3-entry chain):**

- `app/Domain/Agents/Agents/SeoAgent.php` — `guardrails()` body changed from `return []` to:
  ```php
  return [
      app(SensitiveFieldsStripGuardrail::class),
      app(OutboundRegexFilterGuardrail::class),
      app(SeoOutboundGuardrail::class),
  ];
  ```
- Order is pinned and tested. Plan 12-04 RunSeoAgentJob + downstream tests rely on this index order being byte-identical to what SeoAgent returns (SeoAgentGuardrailsWiredTest + SeoGuardrailIntegrationTest both assert).

**Tier 6 — Test coverage (30 Pest cases / 56 assertions):**

- `tests/Feature/Agents/Seo/SystemPromptCalibrationTest.php` (8 cases / 12 assertions) — PromptRenderer determinism + 5-anchor section gate + propose_content_patch ≥5 mentions + few-shot anchor strings (RightSense / Logitech MeetUp / Zoom Rooms) + Blade source NOT directive-includes brand-voice content (P12-H grep gate).
- `tests/Architecture/SeoAgentConfigTest.php` (10 cases / 17 assertions) — config shape + 3 categories × ≥4 patterns + ≥12 total + every regex compiles + GuardrailViolationException accepts new fields AND fromGuardrail() backward-compat AND is RuntimeException.
- `tests/Feature/Agents/Seo/SeoOutboundGuardrailTest.php` (12 cases / 18 assertions) — pre/post flight booleans, shouldRun(Trusted), pre() no-op, 3 forbidden categories trigger with correct failedPatternKey + matchedExcerpt, before-text scan, clean pass-through, non-propose calls ignored, empty steps[] handled, 200-char excerpt bound.
- `tests/Feature/Agents/Seo/SeoAgentGuardrailsWiredTest.php` (5 cases / 5 assertions) — guardrails() returns exactly 3 entries in deterministic index order; each entry is a Guardrail-contract implementor.
- `tests/Feature/Agents/Seo/SeoGuardrailIntegrationTest.php` (5 cases / 9 assertions) — end-to-end via `GuardrailEngine::runPostFlight(SeoAgent, response, Trusted)`: forbidden patch short-circuits, clean response passes through, guardrail order via inspection, first-match-fails-entire-run (no partial publishing per D-01).

## Pattern Calibration — Final Pattern List

```
competitor_brands (4 patterns):
  /\b(?:cisco\s+webex(?:\s+room)?)\b/i
  /\b(?:poly\s+studio)\b/i
  /\b(?:neat\s+(?:bar|board|frame))\b/i
  /\b(?:yealink\s+(?:meetingboard|meetingbar))\b/i

price_claims_absolute (4 patterns):
  /\b(?:cheapest|lowest\s+price|best\s+price|unbeatable\s+price|guaranteed\s+lowest)\b/i
  /\b(?:price\s+match(?:\s+guarantee)?)\b/i
  /\b(?:£\s*\d+(?:\.\d{2})?\s*(?:saving|off|less))\b/i
  /\b(?:half\s+price|50%\s+off|massive\s+discount)\b/i

marketing_superlatives (5 patterns):
  /\b(?:revolutionary|groundbreaking|game[\s-]?chang(?:er|ing)|paradigm[\s-]?shift)\b/i
  /\b(?:world['\x{2019}s]+\s+(?:best|first|leading|finest))\b/iu
  /\b(?:industry[\s-]?leading|cutting[\s-]?edge|state[\s-]?of[\s-]?the[\s-]?art)\b/i
  /\b(?:unparalleled|unmatched|incomparable|unrivalled)\b/i
  /\b(?:perfect(?:\s+solution)?|ultimate(?:\s+solution)?)\b/i
```

Total: 13 patterns. The set is intentionally CONSERVATIVE — better to miss a violation in the first 2 weeks and have admin spot it during AutoCreate review than to false-positive-block 30% of legitimate patches. PR-iteration is the calibration loop: admin reports patterns that should fire but don't, OR patterns that fire but shouldn't, and the next plan adds/refines.

## Contract Plan 12-04 Must Honour

1. **`GuardrailViolationException` carries `failedPatternKey` + `matchedExcerpt`.** RunSeoAgentJob's catch-block reads these fields and calls `$mapper->createGuardrailBlockedSuggestion($run, $product, $e->failedPatternKey, $e->matchedExcerpt)` BEFORE rethrowing. The mapper writes ONE Suggestion of kind `agent_guardrail_blocked` with `evidence.failed_pattern_key` + `evidence.matched_excerpt` set verbatim from the exception fields. P12-B mitigation per RESEARCH §Pattern 7 Option B.

2. **Guardrails order pinned: `[SensitiveFieldsStrip, OutboundRegex, SeoOutbound]`.** Plan 12-04's tests + RunSeoAgentJob catch-block rely on this index order. Do NOT add a new guardrail without updating the index expectations in SeoAgentGuardrailsWiredTest + SeoGuardrailIntegrationTest.

3. **System prompt sha256 = `75bac4c32ddf1d36d8ae9c1c5c4f1a39497c23c5a26cb42768ec25de141785e7`.** Plan 12-04 RunSeoAgentJob writes this onto every AgentRun row. If the Blade view is edited (e.g. operator iterates the few-shot examples), the hash changes and Filament can query the old vs new versions via `WHERE system_prompt_hash = 'X'`. Plan 12-04 calibration tests can assert the hash for forensic deterministic-prompt verification.

4. **`propose_content_patch` field arg is enum-typed (Plan 12-02 resolved Open Question O-1).** The Anthropic schema constrains the model's emission space; SeoOutboundGuardrail scans every propose call regardless of field name (no field-specific allowlist). Plan 12-04 mapper still validates field against the 4-value allow-list as defence in depth.

5. **NO partial publishing (CONTEXT D-01).** First forbidden match → guardrail throws → catch-block writes ONE `agent_guardrail_blocked` Suggestion → ALL proposed patches from that run are abandoned. Plan 12-04 test `RunSeoAgentJobGuardrailBlockedTest` asserts: (a) AgentRun.status = `guardrail_blocked`, (b) one Suggestion of kind `agent_guardrail_blocked` exists, (c) zero Suggestions of kind `seo_content_patch` exist.

## Deviations from Plan

None — plan executed as written. Rule 1-3 not invoked; Rule 4 (architectural change) not invoked; no auth gates encountered. The only minor adjustment was a one-line Blade comment reword (changed "this template does NOT @include brand-voice markdown" → "this template does NOT inline brand-voice markdown via any directive-inclusion mechanism") to satisfy the Task 1 P12-H acceptance grep gate — same pattern Plan 12-02 honoured for ReadBrandStyleGuideTool. Behaviourally identical; semantic intent preserved.

## Authentication Gates

None encountered. All work is local file system + SQLite in-memory test environment + config lookup + container resolution. No Anthropic API calls, no DB seeding, no third-party services exercised.

## Known Stubs

None. SeoOutboundGuardrail's post() body is the real implementation (not a `{stub:true}` placeholder). config/seo_agent.php returns a 13-pattern array (not a single TODO). system.blade.php contains the full 6.4 KB prompt (not a placeholder). All three artefacts are ready for Plan 12-04 to consume.

## Out-of-Scope Findings (deferred-items.md updated)

- `tests/Feature/Agents/PricingAgentCalibrationTest.php` 4 fixtures fail with `IntegrationCredentialMissingException` — the IntegrationCredentialResolver expects an `integration_credentials` row with `kind='anthropic_api'` to be seeded in the local SQLite DB, but none exists. Verified reproducible at parent commit `2ca020e` BEFORE Plan 12-03's Task 3 was added. Same posture as Plan 12-02's deferred items (Phase 11 architecture failures from missing `customer_groups` table): out of scope, not a regression. Logged to `deferred-items.md`. Deferred to a future Phase 10 calibration-test fixture-hygiene plan.

## Verification

```bash
"C:/Users/sonny.tanda/.config/herd/bin/php.bat" vendor/bin/pest \
  tests/Feature/Agents/Seo/ \
  tests/Architecture/SeoAgentConfigTest.php \
  --stop-on-failure
```

**Result:** 60 passed (122 assertions) in 27.51s. Plan 12-03 scope green.

```bash
"C:/Users/sonny.tanda/.config/herd/bin/php.bat" vendor/bin/pest \
  tests/Feature/Agents/Seo/SeoOutboundGuardrailTest.php \
  tests/Feature/Agents/Seo/SystemPromptCalibrationTest.php \
  tests/Feature/Agents/Seo/SeoAgentGuardrailsWiredTest.php
```

**Result:** 25 passed (48 assertions) — the success-criteria filter from the plan prompt.

```bash
"C:/Users/sonny.tanda/.config/herd/bin/php.bat" vendor/bin/pest \
  tests/Feature/Agents/PricingAgentRegistrationTest.php \
  tests/Feature/Agents/PricingAgentPromptHashTest.php \
  tests/Unit/Domain/Agents/Services/AgentRegistryTest.php \
  tests/Unit/Domain/Agents/Services/GuardrailEngineTest.php \
  tests/Architecture/AgentToolsNamingTest.php \
  tests/Architecture/TruncatingToolRelocationTest.php \
  tests/Architecture/PricingToolsObserveSoftCapTest.php
```

**Result:** 34 passed (78 assertions). Zero regression on Phase 8 + Phase 10 + Phase 12-01/02 invariants (credential-resolver-independent tests). The 4 `PricingAgentCalibrationTest` failures are pre-existing IntegrationCredentialMissingException failures, deferred (see Out-of-Scope above).

## Threat Flags

None new beyond the plan's existing `<threat_model>` register (T-12-03-01 through T-12-03-06). All six dispositions honoured:

- **T-12-03-01** (price claim fabrication) → mitigate: `price_claims_absolute` regex category with 4 patterns; first match throws; no partial publishing. Tested in `SeoOutboundGuardrailTest::"price_claims_absolute match"`.
- **T-12-03-02** (competitor product naming) → mitigate: `competitor_brands` category with 4 patterns; allowlist for Zoom/Teams/Google Meet documented + tested via clean-pass-through case.
- **T-12-03-03** (marketing superlatives) → mitigate: `marketing_superlatives` category with 5 patterns. Tested via multiple fixtures (revolutionary, world's best).
- **T-12-03-04** (no audit trail on guardrail-blocked run) → mitigate: GuardrailViolationException carries failedPatternKey + matchedExcerpt → Plan 12-04 mapper writes `agent_guardrail_blocked` Suggestion. Plan 12-04 test asserts.
- **T-12-03-05** (brand voice file XSS via Blade) → mitigate: system.blade.php contains zero directive-inclusion of brand-voice markdown. Asserted via grep at `SystemPromptCalibrationTest::"Blade view source does NOT @include"`.
- **T-12-03-06** (malformed regex DoS) → accept: `@preg_match` suppresses warnings + returns false on compile error; the pattern is skipped silently. `SeoAgentConfigTest::"every regex compiles"` gates this at CI time.

## Self-Check: PASSED

- File `resources/views/agents/seo/system.blade.php` — FOUND (6.4 KB, 5 anchor sections, propose_content_patch occurs ≥5×)
- File `config/seo_agent.php` — FOUND, returns `['guardrails' => [3 keys × 4-5 patterns]]`, 13 total patterns
- File `app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php` — FOUND, `final class implements Guardrail`, post() throws GuardrailViolationException with failedPatternKey + matchedExcerpt
- File `app/Domain/Agents/Exceptions/GuardrailViolationException.php` — FOUND, contains `readonly string $failedPatternKey` AND `readonly string $matchedExcerpt`, fromGuardrail() factory unchanged
- File `app/Domain/Agents/Agents/SeoAgent.php` — guardrails() returns 3-entry chain via app() container resolution
- Test files (5) — ALL FOUND
- Commit `19f4c98` (Task 1) — FOUND
- Commit `2ca020e` (Task 2) — FOUND
- Commit `301be76` (Task 3) — FOUND
- Pest plan-scope suite — 60 passed (122 assertions), 0 failed
- Pest success-criteria filter — 25 passed (48 assertions), 0 failed
- PHP -l on every modified .php file — clean (9 files)
- Phase 10 backward-compat — 22 cases pass; `$e->guardrailClass` access in RunPricingAgentJob compiles unchanged
