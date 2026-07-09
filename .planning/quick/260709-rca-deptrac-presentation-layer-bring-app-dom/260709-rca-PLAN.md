---
phase: 260709-rca-deptrac-presentation-layer-bring-app-dom
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - deptrac.yaml
  - depfile.yaml
must_haves:
  truths:
    - "`vendor/bin/deptrac analyse` reaches 0 violations and all 14 tests/Architecture/Deptrac*LayerTest + DeptracTest go GREEN — by correctly modelling Filament resources as PRESENTATION (they may read across domains, exactly as the existing Http layer already permits), NOT by baselining. Config-only: no application code changes, no behaviour change, no migration."
    - "Domain-embedded Filament (app/Domain/*/Filament/*) is moved into the Http presentation layer: the Http layer gains a second collector matching `app/Domain/.*/Filament/.*`, AND each of the 12 domain layers that HAVE a Filament dir (Agents, Alerting, CRM, Competitor, Integrations, Pricing, ProductAutoCreate, Products, Quotes, Suggestions, Sync, TradePricing) EXCLUDES its own Filament subdir via a `type: bool` collector (must app/Domain/<X>/.* / must_not app/Domain/<X>/Filament/.*). So each Filament file belongs to Http ONLY, not its domain layer — domain rules stop flagging presentation reads."
    - "The Http allow-list is extended ONLY as far as the moved Filament files legitimately require: after the move, re-run deptrac and add to Http any token the domain-Filament genuinely reads that Http lacks (expected: WpDirectDb — SuggestionResource uses DB::; and possibly Integrations). Each addition is a presentation read (display/config UI on app-own data), documented inline. Do NOT add tokens beyond what deptrac reports as needed."
    - "GUARD AGAINST OVER-EXCLUSION (false green): the exclusion must remove ONLY Filament files from domain layers. After the change the `Allowed` count must stay in a sane range (baseline 942; a large collapse — e.g. below ~850 — signals domain SERVICES got un-layered and must be fixed). Confirm a non-Filament domain→cross-domain edge is still enforced (the negatives in the Deptrac*LayerTest suites still pass — they plant real violators and assert deptrac catches them)."
    - "Both deptrac.yaml and depfile.yaml are edited IDENTICALLY (dual-config-sync — CLI reads deptrac.yaml, arch tests read depfile.yaml). This completes the Deptrac cleanup: 88→0 across the extends (a502860) + clean refactors (3e2943e) + this presentation-layer modelling."
  artifacts:
    - path: "deptrac.yaml"
      provides: "Http presentation layer covers domain Filament; domain layers exclude Filament"
      contains: "app/Domain/.*/Filament"
    - path: "depfile.yaml"
      provides: "identical presentation-layer modelling (dual-config-sync)"
      contains: "app/Domain/.*/Filament"
  key_links: []
---

<objective>
Finish the Deptrac cleanup (8→0, 14 arch tests green) by modelling domain-embedded Filament resources as the
PRESENTATION layer — they legitimately read across domains, which the codebase's Http layer already permits and
its comments explicitly anticipate for Filament. Operator-approved over risky UI refactors. Config-only.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260709-rca-deptrac-presentation-layer-bring-app-dom/
@CLAUDE.md
@deptrac.yaml
@depfile.yaml
---
Verified:
- Both YAMLs use ONLY `type: directory` collectors today (no bool yet — introducing bool is valid deptrac).
- Layers: domain layers each `- type: directory / regex: app/Domain/<X>/.*`. Http layer (line ~120):
  `regex: app/Http/.*` with a broad 16-domain allow-list [Foundation, Products, Pricing, Competitor, Sync,
  Webhooks, CRM, Suggestions, Alerting, Feeds, ProductAutoCreate, Dashboard, Cutover, Agents, TradePricing, Quotes]
  — comments say it exists so Filament (under app/Filament) can read domains.
- The 8 remaining violations are all domain-Filament presentation reads: PricingOperationsPage→CompetitorPrice,
  EditAutoCreateReview→SeoContentPatchApplier, SuggestionResource→Competitor + →RunAutoCreatePipelineJob.
- Domains WITH a Filament dir (need the exclude): Agents, Alerting, CRM, Competitor, Integrations, Pricing,
  ProductAutoCreate, Products, Quotes, Suggestions, Sync, TradePricing (12).
- Baseline: deptrac = 8 violations, 942 Allowed. SuggestionResource uses DB:: (so Http will likely need +WpDirectDb).
</context>

<interfaces>
=== Http layer — add the domain-Filament collector (both YAMLs) ===
```yaml
    - name: Http
      collectors:
        - type: directory
          regex: app/Http/.*
        - type: directory
          regex: app/Domain/.*/Filament/.*   # 260709: Filament resources are presentation — may read across domains (see allow-list)
```

=== Each of the 12 domain layers WITH Filament — exclude the Filament subdir (both YAMLs) ===
Convert `collectors: [ - type: directory / regex: app/Domain/<X>/.* ]` to a bool collector:
```yaml
    - name: Suggestions
      collectors:
        - type: bool
          must:
            - type: directory
              regex: app/Domain/Suggestions/.*
          must_not:
            - type: directory
              regex: app/Domain/Suggestions/Filament/.*
```
Do this for: Agents, Alerting, CRM, Competitor, Integrations, Pricing, ProductAutoCreate, Products, Quotes,
Suggestions, Sync, TradePricing. Leave domains WITHOUT a Filament dir unchanged. If deptrac's bool syntax differs
in this version, use the equivalent it accepts (verify by a clean `deptrac analyse` parse); a negative-lookahead
`regex: app/Domain/<X>/(?!Filament/).*` is an acceptable alternative if bool is unsupported.

=== Http allow-list — extend ONLY as deptrac requires after the move ===
Re-run `deptrac analyse`; for any NEW violation now attributed to the Http layer (a Filament file reading a token
Http lacks), add that token to Http's allow-list with an inline comment (expected: `WpDirectDb`; possibly
`Integrations`). Add NOTHING speculative — only tokens deptrac reports as needed to reach 0.

Keep deptrac.yaml and depfile.yaml byte-identical in every edited section.
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: model domain-Filament as the presentation layer</name>
  <files>
    deptrac.yaml,
    depfile.yaml
  </files>
  <behavior>
    Apply the <interfaces> changes to BOTH YAMLs identically, then iterate the Http allow-list until deptrac = 0.
    tdd=false — deptrac + the arch tests are the verifier.
  </behavior>
  <action>
    Add the Filament collector to Http; convert the 12 domain layers to exclude their Filament subdir; run deptrac;
    extend Http's allow-list only for reported Filament reads; iterate to 0. Diff the two YAMLs. Run the arch tests.
    GUARD: confirm the Allowed count didn't collapse (still ≳850) — if it did, the exclusion is too broad; fix it.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/deptrac analyse --no-progress 2>&1 | grep -iE "Violations|Allowed|Warnings" | tail -4</automated>
    Expected: Violations 0; Allowed still ≳850 (baseline 942 — only Filament files re-layered, domain services intact); Warnings 0. If Allowed collapsed, STOP — the exclusion un-layered non-Filament files; fix before proceeding.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Architecture 2>&1 | tail -6</automated>
    Expected: ALL Deptrac*LayerTest + DeptracTest GREEN (the whole-project positives now pass at 0; the planted-violator NEGATIVES still pass — proving domain rules still catch real violations for non-Filament code). tests/Architecture fully green (the 4 test-rot files were fixed earlier).
    <automated>diff <(grep -A40 'name: Http' deptrac.yaml) <(grep -A40 'name: Http' depfile.yaml) && echo "HTTP BLOCK IDENTICAL" || echo "DIFFERS — fix"</automated>
    Expected: "HTTP BLOCK IDENTICAL" (and confirm the 12 domain-layer edits match across both files too).
  </verify>
  <done>
    - Domain-Filament in the Http presentation layer; 12 domain layers exclude their Filament subdir; Http allow-list extended only as needed; deptrac 0; Allowed count sane (no over-exclusion); all 14 Deptrac arch tests green; both YAMLs identical.
  </done>
</task>

</tasks>

<verification>
1. `deptrac analyse` → 0 violations, Allowed ≳850 (no over-exclusion)
2. `pest tests/Architecture` → all GREEN (incl. all Deptrac layer tests + the planted-violator negatives)
3. deptrac.yaml / depfile.yaml identical in every edited section

Operator notes (for SUMMARY.md):
- Deploy: no runtime effect (arch-lint config only).
- What changed + WHY: Filament resources are presentation and legitimately read across domains — the Http layer
  already models this and its comments anticipated Filament doing so. Domain-embedded Filament (app/Domain/*/Filament)
  now belongs to the presentation layer instead of its domain layer, so it is no longer flagged for cross-domain
  display reads. This is correct layering, not a baseline. Domain SERVICES remain strictly layered (the exclusion
  removed only Filament files — verified by the unchanged Allowed count + the still-passing negative tests).
- Completes Deptrac 88→0: extends (a502860, 64) + clean refactors (3e2943e, 16) + presentation modelling (8).
</verification>

<success_criteria>
- Deptrac at 0 with all 14 arch tests green, via correct presentation-layer modelling of domain Filament (not baselining); domain services remain strictly layered (Allowed count sane, negatives still pass); both YAMLs identical; config-only.
</success_criteria>

<output>
Create `.planning/quick/260709-rca-deptrac-presentation-layer-bring-app-dom/260709-rca-SUMMARY.md` documenting the Http-layer Filament collector, the 12 domain-layer Filament exclusions, any Http allow-list tokens added (with why), the deptrac 8→0 + Allowed-count guard result, and that the full 88→0 cleanup is complete.
</output>