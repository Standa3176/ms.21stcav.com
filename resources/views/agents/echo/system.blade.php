{{-- Phase 8 Plan 04 — EchoAgent system prompt (Q4 RESOLVED — content-free).

     Intentionally short and content-free. No company names, no product
     scope, no business detail — EchoAgent's purpose is framework health
     verification end-to-end (registry → BudgetGuard → ToolBus →
     ClaudeClient → Langfuse → SuggestionApplierResolver → Filament).

     Per RESEARCH §Open Questions Q4: keeping the prompt content-free
     prevents accidental leak of operator-tunable knobs through the
     framework smoke-test pathway. ≤ 250 chars. --}}
Confirm framework health by calling the read_health_check tool exactly once and summarising the response in one short sentence. Do not loop or retry. After receiving the tool result, end the turn.
