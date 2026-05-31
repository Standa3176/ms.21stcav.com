<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 01 — Agent budget + shadow-mode + observability config
|--------------------------------------------------------------------------
|
| Single source of truth for every operator-tunable agent knob. Plan 03's
| BudgetGuard reads `monthly_ceiling_pence` + `daily_caps` + `default_daily_cap_pence`
| + `day_boundary_timezone` (D-01..D-05). Plan 04's RunAgentJob reads
| `write_enabled` + `auto_apply_enabled` to gate Suggestions seam writes
| (AGNT-12 shadow-mode). Plan 02's ClaudeClient reads `default_model`
| + `pricing` (cost calculation post-flight, D-08).
|
| Every key is env-overridable so production can scale without redeploys —
| BudgetGuard reads the config (which reads env) on each call so no warm
| cache stale-pence drift. CRITICAL — values cast to int in BudgetGuard
| (Plan 03) so a NEGATIVE env var defaults to 0 (fail-closed; T-08-01-01).
|
| Threat model anchor: T-08-01-01 (Tampering — cap values).
*/
return [

    /*
    |--------------------------------------------------------------------------
    | Budget enforcement (D-01..D-05)
    |--------------------------------------------------------------------------
    |
    | monthly_ceiling_pence  — Layer 2 hard kill-switch (D-01). 100% of monthly
    |                          spend rejects ALL new dispatches with
    |                          MonthlyBudgetExceededException (D-02). In-flight
    |                          runs complete normally.
    | daily_caps             — Layer 1 per-kind soft caps (AGNT-04).
    | default_daily_cap_pence — D-05 fail-safe for unknown kinds.
    | day_boundary_timezone  — D-04 — Europe/London midnight rollover.
    */
    'monthly_ceiling_pence' => (int) env('AGENTS_MONTHLY_CEILING_PENCE', 20000),  // £200 default

    'daily_caps' => [
        'pricing' => (int) env('AGENTS_DAILY_CAP_PRICING', 500),
        'seo' => (int) env('AGENTS_DAILY_CAP_SEO', 300),
        // Chatbot cap is per-session in v2.0 — applied per ChatbotSession.id via BudgetGuard's
        // session-scoped cache key (Plan 14). Plan 03 BudgetGuard treats this
        // as a per-session ceiling rather than a daily total.
        'chatbot' => (int) env('AGENTS_DAILY_CAP_CHATBOT_PER_SESSION', 200),
        'ad_optimisation' => (int) env('AGENTS_DAILY_CAP_AD', 300),
        // Echo is the framework smoke-test agent — minimal cap is sufficient and
        // also acts as a guard against accidental loops during Plan 04 development.
        'echo' => 50,
    ],

    'default_daily_cap_pence' => (int) env('AGENTS_DEFAULT_DAILY_CAP_PENCE', 100),  // D-05

    'day_boundary_timezone' => 'Europe/London',  // D-04

    /*
    |--------------------------------------------------------------------------
    | Shadow-mode gates (AGNT-12)
    |--------------------------------------------------------------------------
    |
    | Both default false. write_enabled gates the AgentSuggestionWriter
    | (Plan 03 — when false, runs complete and persist AgentRun rows but
    | DO NOT write any Suggestion). auto_apply_enabled is reserved for v2.1
    | per-suggestion-kind override; stays false permanently in v2.0 per
    | CONTEXT Claude's Discretion.
    */
    'write_enabled' => (bool) env('AGENT_WRITE_ENABLED', false),
    'auto_apply_enabled' => (bool) env('AGENT_AUTO_APPLY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Anthropic + Prism (Plan 02 wires)
    |--------------------------------------------------------------------------
    |
    | default_model — claude-sonnet-4-6 (Feb 2026 release, $3/$15 per million
    |                  tokens, 1M context beta). Prism passes verbatim to the
    |                  Anthropic provider via ->using(Provider::Anthropic, ...).
    |
    | pricing       — Per-1K-token pricing in pence for cost calculation
    |                  (Plan 02's ClaudeClient reads this post-flight to compute
    |                  cost_pence on AgentRun). Assume £/$ ≈ 1.0 — operator
    |                  recalibrates after Phase 8 ships and 2 weeks of real
    |                  spend data accumulates.
    |
    |                  claude-sonnet-4-6 raw rate:
    |                    input  $3 / 1M tokens = 0.0003 cents/token = 0.00024 pence/token
    |                    output $15 / 1M tokens = 0.0015 cents/token = 0.0012 pence/token
    */
    'default_model' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-6'),

    'pricing' => [
        'claude-sonnet-4-6' => [
            'input_pence_per_token' => (float) env('AGENTS_PRICE_INPUT_PENCE_PER_TOKEN', 0.00024),
            'output_pence_per_token' => (float) env('AGENTS_PRICE_OUTPUT_PENCE_PER_TOKEN', 0.0012),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool-loop ceiling (Pitfall A1 — token runaway)
    |--------------------------------------------------------------------------
    |
    | max_steps  — Prism's withMaxSteps() cap on tool-call iterations. 8 is
    |              comfortably above any real agent's expected step count
    |              while bounding worst-case token burn from prompt-injection
    |              tool loops.
    | max_tokens — Per-call output ceiling. Output tokens dominate cost.
    */
    'max_steps' => (int) env('AGENTS_MAX_STEPS', 8),
    'max_tokens' => (int) env('AGENTS_MAX_TOKENS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Observability (Plan 02 ships Langfuse Docker; custom-OTel as fallback)
    |--------------------------------------------------------------------------
    |
    | driver       — 'langfuse-prism' uses the mliviu79/laravel-langfuse-prism
    |                 shim (Plan 02 install). 'custom-otel' switches to the
    |                 ~150-LOC custom exporter shipped commented-out in Plan 02
    |                 per STACK.md bus-factor mitigation. Operator swaps by
    |                 editing this env var + restarting Horizon.
    | langfuse.*   — Self-hosted Langfuse on lf.ops.meetingstore.co.uk
    |                 (Claude's Discretion). Plan 02's docker-compose.langfuse.yml
    |                 brings the stack up.
    */
    'observability' => [
        'driver' => env('AGENTS_OBSERVABILITY_DRIVER', 'langfuse-prism'),
        'langfuse' => [
            'host' => env('LANGFUSE_HOST'),
            'public_key' => env('LANGFUSE_PUBLIC_KEY'),
            'secret_key' => env('LANGFUSE_SECRET_KEY'),
        ],
        // 'custom_otel' => [
        //     // Shipped in Plan 02; uncomment + flip 'driver' above + restart Horizon to swap.
        //     'fallback_log_channel' => 'langfuse-otel-fallback',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (D-07)
    |--------------------------------------------------------------------------
    |
    | retention_days — 5y rolling horizon. Plan 05's agents:prune-archive
    |                   command exports rows past this threshold to gzipped
    |                   JSON archives in storage/app/agent-archives/ then
    |                   DELETEs them. Disk projection: ~5MB/month at 100
    |                   runs/day; ~3GB after 5y; archives stay <100MB.
    */
    'retention_days' => (int) env('AGENTS_RETENTION_DAYS', 1825),

    /*
    |--------------------------------------------------------------------------
    | Phase 12 — SeoAgent per-kind overrides (CONTEXT Claude's Discretion)
    |--------------------------------------------------------------------------
    |
    | 0.4 balances creativity (genuine paraphrasing) with reproducibility.
    | Set higher than pricing (0.0 deterministic) because REQUIREMENTS line
    | 124 explicitly allows temp>0 for SEO/chatbot with guardrails. Plan
    | 12-04 RunSeoAgentJob passes this to ClaudeClient::generate(temperature:).
    |
    | Threat anchor: T-12-01-04 — Denial of Service. Operator can hot-tune
    | via env without redeploy if creative-output calibration goes sideways
    | (high rejection rate during launch week).
    */
    'seo' => [
        'temperature' => (float) env('AGENTS_SEO_TEMPERATURE', 0.4),
    ],

    // Nightly `agents:run-seo-batch` 04:30 schedule toggle. Same
    // env()-broken-in-cached-config issue as pricing.undercut_schedule_enabled
    // (see that comment) — read via config() from routes/console.php.
    'seo_batch_schedule_enabled' => (bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true),

];
