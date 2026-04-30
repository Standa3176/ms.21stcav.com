# PricingAgent Prompt Iteration Runbook

**Audience:** Pricing engineers + ops devs who own the PricingAgent system prompt
**Scope:** Editing `resources/views/agents/pricing/system.blade.php` safely between deployments
**Related:** Phase 10 Plan 03 (this prompt), Phase 8 Plan 03 (PromptRenderer + sha256), CONTEXT D-07 (confidence rubric), CONTEXT D-09 (rejection feedback inbox)

---

## 1. Why prompt iteration matters

The PricingAgent's system prompt at `resources/views/agents/pricing/system.blade.php` is the
single load-bearing surface that controls how Claude interprets margin_change Suggestions.
A change to the rubric anchors (e.g. shifting LOW from "≤5 sales/90d" to "≤7 sales/90d")
shifts the **entire** distribution of agent confidence outputs — every downstream
out-of-band chip (CONTEXT D-08), Filament confidence badge (CONTEXT D-10), and prompt-iteration
triage decision (CONTEXT D-09) depends on the rubric staying calibrated to the data.

**v2.0 design choice (CONTEXT D-09):** Rejection feedback feeds prompt tuning **manually** —
operators read the `/admin/agent-runs/rejection-inbox` filtered for `misleading=yes`/`partial`,
read the notes, then edit the Blade view + ship a new commit. Auto-prompt-feedback (the model
self-rewriting based on rejection text) is deferred to v2.1+ because of compounding-drift risk.

**There is no UI for editing this prompt. There is no DB-stored prompt. Git history IS the
version history.** The `agent_runs.system_prompt_hash` column captures the sha256 of every
rendered prompt so ops can join "all runs that used rubric vN" to git blame on the Blade file.

---

## 2. Workflow

The minimal safe loop for any prompt edit:

1. **Edit** `resources/views/agents/pricing/system.blade.php` directly. Stay within the existing
   structure: persona / workflow / confidence rubric / output contract / few-shot examples.
   **Do not introduce `{{ $variable }}` interpolation** — the deterministic hash invariant
   (Plan 10-03 PricingAgentPromptHashTest) breaks if the rendered text varies between calls.
   Per-brand variants are deferred to v2.1.

2. **Run the calibration + determinism tests locally:**
   ```bash
   php artisan test --filter='PricingAgentCalibrationTest|PricingAgentPromptHashTest'
   ```
   - `PricingAgentPromptHashTest` should always pass (4 assertions on hash determinism).
   - `PricingAgentCalibrationTest` may fail if your rubric change shifts the band-membership
     of one of the 4 scripted fixtures (HIGH-confidence / LOW-confidence / max-steps-exhausted /
     malformed-args). This is the design intent — band drift is a regression signal.

3. **If calibration fails after your edit, you have three options:**
   - Tune the rubric anchors so the existing fixtures still land in their band, OR
   - Update the fixture's expected `confidence_0_to_100` value to match the new intended
     behaviour (then commit both the prompt + the fixture together for a clean atomic change), OR
   - Revert the prompt change — the rubric drift you introduced wasn't the calibration you wanted.

4. **Commit + push:**
   ```bash
   git commit resources/views/agents/pricing/system.blade.php \
       -m "feat(10-prompt): tighten LOW band anchor — sales threshold now <=7 not <=5"
   ```
   Use a `feat(10-prompt):` prefix so future ops can `git log --grep='10-prompt'` to see every
   prompt iteration cleanly.

5. **Deploy.** Horizon picks up the new code on the next worker boot. The next admin-triggered
   `RunPricingAgentJob` (Plan 10-04) renders the new prompt; `AgentRun.system_prompt_hash`
   captures the new sha256 hex automatically.

---

## 3. Querying agent runs by prompt version

The forensic question after any prompt change: "Show me every PricingAgent run that used the
rubric I shipped on Apr 30 — and how did the confidence distribution shift compared to the
previous rubric?"

### Step 1 — Find the hash for a given commit

```bash
# Check out the commit you care about and re-render the prompt
git checkout <commit-sha>
php artisan tinker --execute='echo app(App\Domain\Agents\Services\PromptRenderer::class)->render("pricing")["hash"];'
git checkout -
```

The output is a 64-char hex sha256. Copy it.

### Step 2 — Query agent_runs

```sql
-- "Show me every PricingAgent run that used the rubric I shipped on Apr 30"
SELECT id, started_at, status, cost_pence, langfuse_trace_id, triggering_suggestion_id
FROM agent_runs
WHERE kind = 'pricing'
  AND system_prompt_hash = 'a256f55290684d4b2e8a88f4897d600a87f92624095c304bdf03ac7ae9e3a3f3'
ORDER BY started_at DESC;
```

Cross-reference any specific run's `langfuse_trace_id` with the self-hosted Langfuse UI for
the full conversation trace (system prompt + tool calls + final propose_margin_band).

### Step 3 — Compare confidence distribution between two prompt versions

```sql
-- Confidence histogram for prompt version A
SELECT
  s.evidence->>'$.agent_confidence_0_to_100' AS confidence,
  COUNT(*) AS run_count
FROM agent_runs r
JOIN suggestions s ON s.id = r.triggering_suggestion_id
WHERE r.kind = 'pricing'
  AND r.system_prompt_hash = '<HASH-A>'
  AND r.status = 'completed'
GROUP BY confidence
ORDER BY confidence;

-- Repeat with <HASH-B>; eyeball the histogram shift to assess the rubric change's impact
```

If you see the LOW-band run count double after a rubric tightening, the change probably went
too far — the agent is being too conservative across the board. Iterate again.

---

## 4. Comparing two prompt versions

Once you have two hashes that bookend an interesting iteration window, the textual diff tells
you what changed:

```bash
git log --oneline -- resources/views/agents/pricing/system.blade.php
# pick the two commits you care about
git diff <commit-A>..<commit-B> -- resources/views/agents/pricing/system.blade.php
```

Pair this with the Filament `AgentRunResource` confidence histogram (Plan 10-04) to assess
whether the new rubric narrowed or widened the typical band distribution as intended.

For a quick "does the new prompt produce a different sha256?" smoke check:

```bash
git stash  # if you have uncommitted changes
git checkout <commit-A>
HASH_A=$(php artisan tinker --execute='echo app(App\Domain\Agents\Services\PromptRenderer::class)->render("pricing")["hash"];')

git checkout <commit-B>
HASH_B=$(php artisan tinker --execute='echo app(App\Domain\Agents\Services\PromptRenderer::class)->render("pricing")["hash"];')

git checkout -
git stash pop

[ "$HASH_A" != "$HASH_B" ] && echo "DIFFERENT — new hash will be captured on next agent run"
```

---

## 5. When NOT to edit the prompt

The prompt is load-bearing. A reckless edit can degrade enrichment quality across hundreds of
admin-pull-driven runs before anyone notices. Hold off when:

- **You haven't captured a baseline.** Before any rubric change, query the rejection inbox for
  the LAST 5+ misleading-flagged runs against the current prompt. Read the notes. If you can't
  point at a specific failure pattern the new prompt is supposed to fix, the change is
  speculative — capture the baseline first, then iterate.

- **The change introduces dynamic Blade interpolation.** Adding `{{ $context['recent_rejection_notes'] }}`
  or `{{ now()->format('Y-m-d') }}` defeats the deterministic hash invariant — `PricingAgentPromptHashTest`
  fails immediately. Per-context variants (e.g. brand-specific personas) are deferred to v2.1
  (CONTEXT Deferred Ideas).

- **You're mid-iteration on another prompt change.** Don't stack two rubric edits in a single
  commit cycle. Iterate one anchor at a time, ship, capture 5+ data points, then iterate the
  next anchor. Stacked changes make it impossible to attribute behaviour shifts to specific edits.

- **An admin is actively running pricing-agent batches.** A re-deploy triggers a Horizon worker
  restart; in-flight `RunPricingAgentJob` instances retry against the new prompt mid-loop. For
  large batches (>10 suggestions in flight), wait for Horizon to drain before deploying a prompt
  change. Use `php artisan horizon:status` + Pulse to check the agents queue depth.

- **The calibration test fails and you don't understand why.** Don't update the fixture's
  expected confidence band to "make the test pass" without first understanding what the new
  prompt is doing differently. The test is the regression guardrail — recalibrating it without
  understanding the band shift hides the very signal it exists to surface.

---

## 6. Reference — current prompt sections

For navigation when editing `resources/views/agents/pricing/system.blade.php`:

| Section | Purpose | Edit risk |
|---------|---------|-----------|
| Persona (top 2 paragraphs) | Sets pricing-analyst voice + UK B2B AV reseller framing | LOW — tone tweaks rarely shift outputs |
| `# Your workflow` | 7-step tool sequence; ack-and-stop after `propose_margin_band` | HIGH — re-ordering tool calls or removing the stop instruction can cause `withMaxSteps(8)` exhaustion |
| `# Confidence rubric` | LOW/MODERATE/HIGH bands + anchor examples (CONTEXT D-07) | HIGH — the calibration test locks band-membership; any threshold edit shifts the distribution |
| `# Output contract` | 6-arg `propose_margin_band` schema + truncation rule | HIGH — args here MUST match `ProposeMarginBandTool` Prism schema or the agent's call gets rejected |
| `# Few-shot examples` | 2 worked cases (LOGI-MEETUP HIGH + NICHE-RACK-SHELF LOW) | MEDIUM — adding a 3rd example anchors a new band but increases prompt tokens (CONTEXT D-05 budget concern) |

When editing the rubric anchors specifically: keep the **monotonic shape** (LOW thresholds <
MODERATE thresholds < HIGH thresholds) so the calibration test's band-membership assertions
stay coherent.
