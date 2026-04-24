# Stack Research — v2.0 Intelligence + B2B (additions only)

**Domain:** Subsequent milestone on top of shipped v1.50.1 — adding Claude-driven AI agents (pricing / ads / SEO suggestions), trade-customer pricing, quote-request flow with PDF output, WhatsApp Business chat surface, and an AI product-finder chatbot
**Researched:** 2026-04-24
**Overall confidence:** HIGH on agent/observability core (Prism + Langfuse are clear ecosystem leaders); MEDIUM on WhatsApp client (multiple credible options, all community-maintained); HIGH on PDF + chatbot UI choices.

---

## Scope of this document

This is an **addendum** to the v1 STACK.md (which remains canonical for everything already shipped — Laravel 12, Filament 3.3, Horizon, the Woo+Bitrix+supplier+CSV stack, Spatie/Shield/activitylog, Pest/PHPUnit/Deptrac).

**Re-research is explicitly out of scope.** The v1 stack is fixed by `Constraints` in `.planning/PROJECT.md` ("no breaking changes mid-v2"). This document only proposes **net-new dependencies** and any **version bumps** required to land the seven v2 features (C4 + C1/C2/C3 + E1/E2/E3/E4).

---

## Executive Picks (TL;DR)

| Concern | Pick | One-line rationale |
|---|---|---|
| **(a) Claude SDK / LLM client** | `prism-php/prism` ^0.100.1 | De-facto Laravel-native LLM abstraction with built-in Anthropic provider, native tool-use loop, structured-output schemas, streaming, ~3.1M installs. PHP 8.2 floor matches v1. |
| **(b) Agent tool-use orchestration** | Prism's `Tool::as()` + `withTools()` + `withMaxSteps()` (no separate framework) | Prism implements the multi-turn tool-execution loop natively — no Neuron-AI / LarAgent / Atlas needed. Wrap each agent (PricingAgent, AdsAgent, SeoAgent) in an `App\Domain\Agents\*Agent` class behind a `RunsAsAgent` contract that produces `Suggestion` rows via the existing `SuggestionApplier` seam. |
| **(c) LLM observability** | `mliviu79/laravel-langfuse-prism` ^0.1.x + self-hosted Langfuse (Docker) | Auto-traces every Prism call (token counts, costs, latency, tool spans). OTel-based, queue-non-blocking. Self-host alongside ops VPS to keep customer + margin data in-EU. |
| **(d) WhatsApp Business client** | `netflie/whatsapp-cloud-api` ^3.1 (stable line) — wrapped in `App\Domain\Chat\WhatsAppClient` | Most-installed (459k), Meta Cloud API direct (no Twilio/Gupshup middleman), no vendor lock-in. **Pin to 3.1 stable, not 4.x beta.** Bitrix Open Channel route rejected — see "What NOT to Use". |
| **(e) Chatbot web UI** | Native Livewire 3 `wire:stream` + Filament/Blade for E4 product-finder; Filament `Page` + Livewire for internal agent-suggestion review screens | Livewire 3.5 (already pinned by Filament 3) ships token-streaming. No new UI framework. **Don't add `adultdate/filament-wirechat` (Filament 4 only) or `ferarandrei1/filament-ai-chat-widget` (OpenAI-only, Laravel 11 cap).** |
| **(f) PDF output for E2 quotes** | `spatie/laravel-pdf` ^2.7 with the **DOMPDF driver** | Spatie's v2 driver-based architecture lets us start with pure-PHP DOMPDF (no Node/Chromium) and switch to Browsershot if the quote layout demands Tailwind/Flexbox later — single API, zero refactor. |
| **(g) NLP preprocessing for product-finder** | None — let Claude do it | Product-finder runs through the Claude agent loop with a `searchProducts(query, filters)` tool that hits MySQL FULLTEXT on `products.name + description`. Don't add Meilisearch / Typesense / vector DB in v2 — premature. Re-evaluate at v2.1 if recall is poor. |
| **Cost guardrails** | Custom `App\Domain\Agents\BudgetGuard` middleware (Cache::add atomic counter) + Sentry alerts | Reuse the v1 dedup + alerting machinery (P1 03 `IntegrationLogger` + Cache-based rate limiter). Don't pull in `laravel-fuse` or third-party AI-cost packages — pattern is ~80 lines, integrates with existing `integration_events` rows. |

---

## Recommended additions (composer-only)

### Core LLM + agent layer

| Package | Pin | Purpose | Why this exact choice |
|---|---|---|---|
| `prism-php/prism` | `^0.100.1` (Mar 20 2026) | Provider-agnostic LLM client + tool-use loop + structured output | Single most-installed Laravel-native LLM package (3.1M+ installs, 62 dependents). Has first-class Anthropic provider with **`Provider::Anthropic` enum**, fluent `Prism::text()->using(...)->withTools([...])->withMaxSteps(N)->asText()` syntax, parallel tool execution for I/O-bound tools, structured output via PHP class schemas, SSE streaming via Livewire `wire:stream`. Pre-1.0 but the API has stabilised; v1.0 timeline is "in the coming weeks" per official Laravel blog. **Composer constraint `^12.0\|^13.0` matches our Laravel 12 pin.** PHP `^8.2` floor matches v1. |
| `prism-php/relay` | `^1.7` | MCP client tool for Prism (lets Claude invoke MCP servers as tools) | Optional — add only if we want to plug `meetingstore-ops` into Claude Code's MCP ecosystem. **Not required for v2 scope** — pricing/ads/SEO agents call native PHP tools, not MCP servers. Listed here so future planners don't reinvent. |
| `mliviu79/laravel-langfuse-prism` | `^0.1.0` (Jan 13 2026) | Auto-traces every Prism call to a Langfuse instance | Drop-in observability via OTel. Auto-instruments `Prism::text()`, `embeddings()`, `image()`, `audio()`. Async/queued exports — never blocks the agent's HTTP response. Token counting + cost calculation per provider. Constraint `^9.0\|^10.0\|^11.0\|^12.0` works. **Caveat: 115 installs, single-maintainer "primarily for our own production apps" — flag as MEDIUM-confidence dep; mitigation in "Friction" section below.** |

**Anti-recommendation: NOT installing `anthropic-ai/sdk` directly.** The official Anthropic PHP SDK is at v0.17.0 (Apr 23 2026), still in `0.x` "API may change at any time" beta, and exposes only raw Messages/streaming primitives — no tool-loop helper, no Laravel service-provider, no Eloquent integration. Prism wraps the Anthropic API with a higher-level interface; pulling both in would duplicate transport logic and confuse the dev surface. If Prism ever proves limiting we swap *under* our `App\Domain\Agents\Llm` contract, not alongside.

**Anti-recommendation: NOT installing `laravel/ai` (the official Laravel AI SDK).** Released Feb 2026 at v0.6.3 (Apr 22) with **PHP `^8.3` floor** — incompatible with our v1 PHP 8.2 floor. The package itself depends on `prism-php/prism`, so we get the same engine with one fewer indirection by using Prism directly. Re-evaluate when `laravel/ai` hits 1.0 AND we're ready to bump PHP minimum to 8.3.

**Anti-recommendation: NOT installing `inspector-apm/neuron-ai` / `neuron-core/neuron-ai`.** Capable framework, but introduces its own agent/memory/RAG abstractions that overlap with what we get from Prism + our existing `Suggestion`/`integration_events` infrastructure. Bringing in Neuron would mean either running two parallel agent abstractions or rewriting v1's audit seam. Stick with Prism + thin wrappers.

### Quote-flow PDF (E2)

| Package | Pin | Purpose | Why |
|---|---|---|---|
| `spatie/laravel-pdf` | `^2.7` (Apr 23 2026) | Generate quote PDF from Blade template | v2 has a **driver-based architecture** — start with the bundled **DOMPDF driver** (`composer require dompdf/dompdf:^3.0`) for a zero-Node-dependency setup that fits the existing VPS. If sales later demand Tailwind utilities or complex layout, swap to the Browsershot driver (Node + headless Chromium) **without touching call sites**. Constraint `^11.0\|^12.0\|^13.0` works. |

**Why not `barryvdh/laravel-dompdf`?** It's the older popular pick (~1.5M installs/month) but only supports CSS 2.1 — no Flexbox/Grid/Tailwind. Spatie's package wraps DOMPDF with the same constraint *but* lets us escape to Browsershot later via a one-line driver switch, so we don't lose optionality.

**Why not `barryvdh/laravel-snappy` (wkhtmltopdf)?** Unmaintained on Wkhtmltopdf side since 2023; deprecated upstream.

### WhatsApp Business (E3)

| Package | Pin | Purpose | Why |
|---|---|---|---|
| `netflie/whatsapp-cloud-api` | `^3.1` (stable line — **NOT 4.0.0-beta1**) | PHP client for Meta WhatsApp Business Cloud API | 459k installs, 641 stars, 6 dependents, 0 security advisories. Direct Meta Cloud API (no Twilio/Gupshup/ChatApp middleman = no per-message vendor margin). Supports text/media/template/button messages, webhook handling, business profile management. PHP `^8.1` floor — fits v1. **Pin to 3.x stable; the 4.0.0-beta1 (Apr 14 2026) is recent and still beta — do not adopt mid-build.** |
| `netflie/laravel-notification-whatsapp` | `^1.4` (optional) | Laravel Notification channel built on the SDK above | Add only if we want template-message notifications to flow through Laravel's `Notifiable` interface (e.g. for one-off transactional alerts). For the v2 chat surface — bidirectional conversation routed to Bitrix24 + AI agent — we use the SDK directly inside `App\Domain\Chat\WhatsAppClient`. |

**Wrapping pattern (mirrors v1 `WooClient`/`BitrixClient`):**
- `App\Domain\Chat\WhatsAppClient` thin wrapper around `Netflie\WhatsAppCloudApi\WhatsAppCloudApi`
- HMAC webhook verification middleware (Meta uses `X-Hub-Signature-256` SHA-256 HMAC over raw body — same pattern as v1's WooHmacMiddleware, ~30 LOC)
- Outbound calls log to `integration_events` via the v1 `IntegrationLogger`
- Inbound messages dispatch a `WhatsappMessageReceived` DomainEvent → handlers route to either Bitrix24 (existing flow) or the AI product-finder agent (new flow)

**Anti-recommendation: NOT routing WhatsApp through Bitrix24's Open Channel connector.** Two reasons:
1. **Compliance posture.** v1 deliberately avoided Russian-origin third-party integrations (the entire reason for replacing `itgalaxycompany`). Bitrix's first-party Open Channel WhatsApp connectors are routed via Gupshup or ChatApp — both add a paid intermediary and put another vendor between Meta and our store.
2. **Architecture.** v1 owns the customer + ops data; Bitrix is a CRM destination, not a routing layer. Pushing WhatsApp through Bitrix would mean Laravel reads chat events back from Bitrix via polling — inverts the established Laravel-as-source-of-truth pattern.

**Anti-recommendation: NOT adopting `MissaelAnda/laravel-whatsapp` or `crenspire/laravel-whatsapp` or `blissjaspis/laravel-whatsapp-cloud-api`.** All credible, but all <50k installs and single-maintainer. `netflie/whatsapp-cloud-api` is the install-volume leader (10×–100× the others) and has the longest commit history.

### Chat / chatbot UI (E4 + agent-review surfaces)

**No new packages.** Stack is:
- **Livewire 3.5+** (already pinned by Filament 3.3) → use `wire:stream` directive for token-by-token streaming of Claude responses to the product-finder UI
- **Filament 3.3 Page + custom Livewire component** for internal agent-suggestion review screens (existing `SuggestionResource` is rich enough for most cases — only build a custom Page if a specific agent needs a richer side-by-side diff UX)
- **Public-facing E4 product-finder** = a single Blade view + Livewire 3 `Stream::send()` calls inside a Prism agent loop; no auth required (or Filament guest panel if we want lightweight session tracking)

**Anti-recommendation: NOT installing `adultdate/filament-wirechat`.** It's Filament 4 only — would force the Filament 4 upgrade we explicitly deferred at v1 cutover.

**Anti-recommendation: NOT installing `ferarandrei1/filament-ai-chat-widget`.** OpenAI-only (no Anthropic provider), Laravel 11 cap (no `^12.0` constraint), and pulls in OpenAI's own SDK rather than going through Prism — kills our provider portability.

**Anti-recommendation: NOT installing `icetalker/filament-chatgpt-bot` or `ercogx/openai-assistant`.** Same reason — OpenAI-locked.

### Cost / safety / observability glue

| Concern | Approach | Files |
|---|---|---|
| **Per-agent token budget** | `App\Domain\Agents\BudgetGuard` invokable wrapper. Reads daily token counter from Redis (`Cache::add` atomic increment, mirrors v1's failed-job dedup pattern from Plan 01-05). Throws `BudgetExceededException` *before* the Prism call dispatches. | `app/Domain/Agents/BudgetGuard.php`, config `config/agents.php` |
| **Per-agent rate limit** | Laravel's built-in `RateLimiter::for('agent.pricing', fn () => Limit::perMinute(10))` invoked by each agent class | `app/Providers/RateLimiterServiceProvider.php` (extend the one v1 already has if present) |
| **Tool-loop safety** | Prism's native `withMaxSteps(N)` (default to 10 in `config/agents.php`); plus a `BudgetGuard` total-token cap per request | Per-agent class const `MAX_STEPS` |
| **Trace + cost logging** | Langfuse (via `mliviu79/laravel-langfuse-prism`) — auto-instruments Prism calls, exports asynchronously | Self-hosted Langfuse Docker container on the same VPS as ops; admin UI behind `admin` Shield permission (mirror Horizon pattern) |
| **Failure alerting** | Reuse v1's `spatie/laravel-failed-job-monitor` + `AlertRecipient` table — agent jobs failing inherit the same alerting | No new code |
| **Audit trail** | Every agent run produces a `Suggestion` row (existing v1 table, ULID-keyed). `Auditor::logIntegration(...)` hits `integration_events`. `correlation_id` threads through `Context` → Prism → Langfuse via custom Prism middleware (~30 LOC) | `app/Domain/Agents/CorrelationIdMiddleware.php` |

**Anti-recommendation: NOT installing `harris21/laravel-fuse`.** Genuinely useful circuit-breaker library, but the v1 pattern (DB-backed `consecutive_failures` counter on `sync_runs` + `Cache::add` atomic dedup) is already the team's idiom. Adding a new abstraction for one new feature class is unjustified — re-implement the proven pattern in `BudgetGuard`.

**Anti-recommendation: NOT installing `sadiqueali/laravel-ai-guard` or similar AI-cost packages.** Either OpenAI-SDK-locked, Laravel-AI-SDK-locked (PHP 8.3 floor), or extremely new with <100 installs. The cost-control needs are simple enough that custom code (~80 LOC) integrates cleanly with the existing audit + alerting fabric.

---

## Installation (composer commands)

```bash
# === REQUIRED for v2.0 ===

# Core agent layer (C4 infrastructure + C1/C2/C3 agents + E4 chatbot)
composer require prism-php/prism:"^0.100"

# LLM observability (auto-traces every Prism call to self-hosted Langfuse)
composer require mliviu79/laravel-langfuse-prism:"^0.1"

# Quote PDF output (E2)
composer require spatie/laravel-pdf:"^2.7"
composer require dompdf/dompdf:"^3.0"

# WhatsApp Business Cloud API client (E3) — pin to 3.x stable, NOT 4.x beta
composer require netflie/whatsapp-cloud-api:"^3.1"

# === OPTIONAL ===

# Notification channel for transactional WhatsApp template messages (E3 only if we want
# Laravel Notifiable integration; SDK call sites suffice for the chat agent itself)
composer require netflie/laravel-notification-whatsapp:"^1.4"

# MCP client tool — only if we expose meetingstore-ops as MCP server / consume external MCP
composer require prism-php/relay:"^1.7"

# === ENVIRONMENT (NOT composer) ===
# Self-hosted Langfuse runs in Docker:
#   docker compose up -d langfuse-server langfuse-postgres langfuse-clickhouse
# Add LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY, LANGFUSE_HOST to .env
#
# Anthropic credentials:
#   ANTHROPIC_API_KEY=sk-ant-...
# (Prism auto-discovers via config/prism.php publish)
#
# WhatsApp Business Cloud API credentials (Meta Developer Portal):
#   WHATSAPP_FROM_PHONE_NUMBER_ID=...
#   WHATSAPP_ACCESS_TOKEN=...
#   WHATSAPP_WEBHOOK_VERIFY_TOKEN=...
#   WHATSAPP_APP_SECRET=...   # for X-Hub-Signature-256 HMAC verification
```

**No new dev dependencies.** Pest 3 + Larastan + Deptrac + Pint cover the new code. Add a Deptrac layer rule for `Agents` (Prism allowed; direct Anthropic SDK forbidden) at Phase planning time.

**No npm changes.** No JS framework migration. Livewire 3 streaming uses Alpine (already pinned by Filament 3).

---

## Integration with v1 stack — explicit contracts

| v1 seam | v2 use | New code |
|---|---|---|
| `App\Foundation\Suggestions\SuggestionApplier` contract | Each agent (`PricingAgent`, `AdsAgent`, `SeoAgent`) produces `Suggestion` rows of the appropriate `kind`. Existing `MarginChangeApplier` etc. handle the apply-side untouched. | New: `App\Domain\Agents\Producers\PricingAgentProducer` (and 2 siblings). Zero changes to v1 appliers. |
| `App\Domain\Sync\Woo\WooClient` | E4 chatbot's `searchProducts` tool reads from local DB (NOT Woo). Tool wrapped to never call WooClient — reads are MySQL + cached aggregates only. | New: `App\Domain\Agents\Tools\SearchProductsTool` |
| `App\Domain\Crm\Bitrix\BitrixClient` | E2 quote flow → `BitrixClient->createDeal(category: 'quote_request', ...)` reuses v1 push path. E3 WhatsApp inbound messages → optional Deal creation via existing seam. | Zero changes to BitrixClient. New: `App\Domain\QuoteFlow\QuoteToDealHandler` |
| `App\Foundation\Auditing\IntegrationLogger` | Every Prism HTTP call to Anthropic logs to `integration_events` via Prism middleware | New: `App\Domain\Agents\PrismIntegrationLogger` (~40 LOC middleware) |
| `App\Foundation\DomainEvent` + correlation_id Context | Every agent run dispatches `AgentRunStarted` / `AgentRunCompleted` / `AgentRunFailed`. Correlation_id threads through to Langfuse trace metadata. | New: 3 events; existing `Context` retains state across queue dehydrate/hydrate (already proven in v1) |
| Horizon supervisors (7 named queues) | Add an 8th supervisor `agents` for agent jobs; budget enforcement at the queue level via Horizon's `maxProcesses` | Update `config/horizon.php` and `supervisor.conf` |
| `spatie/laravel-permission` + Shield | Add 4 new resources × ~5 perms = ~20 perms (`view_agent_run`, `replay_agent_run`, etc.). Run `shield:generate` post-Resource creation, **then restore hand-written policies per Pitfall P5-F**. | Standard v1 protocol |
| `bezhansalleh/filament-shield` | New Filament Resources: `AgentRunResource` (view-only, agent suggestions browsable), `WhatsappConversationResource` (view + replay), `QuoteRequestResource` (full CRUD for sales) | All existing patterns |
| `spatie/laravel-activitylog` | Agent runs + applied suggestions flow through `LogsActivity` already on `Suggestion` model — no schema changes needed | Zero |

**Critical: no new queue runner / DI container / event bus.** Everything routes through Laravel 12's native facilities + Horizon as in v1. Prism plugs in via service-provider auto-discovery; Langfuse plugs in via its own service-provider. Both are zero-config beyond `.env`.

---

## Version compatibility matrix

| Package | Pin | PHP req | Laravel req | Filament 3.3 | Notes |
|---|---|---|---|---|---|
| `prism-php/prism` | `^0.100.1` | `^8.2` ✅ | `^11.0\|^12.0\|^13.0` ✅ | n/a (no Filament dep) | v1.0 GA imminent per laravel.com blog (April 2026) |
| `prism-php/relay` | `^1.7` | `^8.2` ✅ | `^12.0\|^13.0` ✅ | n/a | Optional MCP client |
| `mliviu79/laravel-langfuse-prism` | `^0.1.0` | `^8.2\|^8.3\|^8.4` ✅ | `^9.0\|^10.0\|^11.0\|^12.0` ✅ | n/a | Single-maintainer; mitigation below |
| `spatie/laravel-pdf` | `^2.7` | `^8.2` ✅ | `^11.0\|^12.0\|^13.0` ✅ | n/a | Optional Browsershot driver (Node+Chromium) |
| `dompdf/dompdf` | `^3.0` | `^8.0` ✅ | n/a | n/a | Pure-PHP, no Node |
| `netflie/whatsapp-cloud-api` | `^3.1` | `>=8.1` ✅ | n/a (framework-agnostic) | n/a | Pin **stable**, NOT 4.0.0-beta1 |
| `netflie/laravel-notification-whatsapp` | `^1.4` | `>=7.4` ✅ | `^8.0\|^9.0\|^10.0\|^11.0\|^12.0` ✅ | n/a | Optional |

All v1 packages remain at their existing pins — **zero version bumps required** to land v2.0. The stack-fixed constraint in `.planning/PROJECT.md` is honoured.

---

## Alternatives considered

| Recommended | Alternative | When to switch |
|---|---|---|
| `prism-php/prism` | `laravel/ai` (official Laravel SDK) | When `laravel/ai` hits 1.0 stable AND we bump PHP minimum to 8.3 (post-cutover, post-v2). It depends on Prism anyway, so the swap is a thin contract refactor. |
| `prism-php/prism` | `inspector-apm/neuron-ai` | If we hit Prism limitations on multi-agent orchestration or persistent chat memory (Neuron ships memory primitives Prism doesn't). For v2's three independent agents (no cross-agent state), Prism wins on simplicity. |
| `prism-php/prism` | Direct `anthropic-ai/sdk` ^0.17 | Never for v2 — direct SDK loses tool-loop helpers and provider portability. Maybe re-evaluate for highly-custom streaming protocols if Prism abstractions ever obstruct. |
| `mliviu79/laravel-langfuse-prism` + self-hosted Langfuse | Helicone (proxy-based) | If we want zero-instrumentation observability and accept proxying every Anthropic call through a third party. Rejected for v2: data residency concerns (margin/customer data via proxy) + extra latency on the chat-bot user-facing path. |
| Self-hosted Langfuse | Langfuse Cloud (managed) | If ops team doesn't want to run another Docker stack. Trade-off: customer/margin data leaves the EU. Decide post-v2 cutover; both modes use same SDK. |
| `spatie/laravel-pdf` (DOMPDF driver) | `barryvdh/laravel-dompdf` | If we want a more popular package. Rejected because Spatie's driver-based architecture preserves our future Browsershot escape hatch with no API rewrite. |
| `spatie/laravel-pdf` (DOMPDF driver) | `spatie/laravel-pdf` (Browsershot driver) | When sales push back on the DOMPDF look-and-feel (no Tailwind, no Flexbox, weak font support). Switching is a 1-line driver config + adding Node + Puppeteer to the VPS. |
| `netflie/whatsapp-cloud-api` | Twilio for WhatsApp | If Meta's Cloud API direct integration proves limiting (e.g. need conversational AI features Twilio bundles). Rejected for v2: extra per-message cost; Twilio doesn't serve all geographies; we don't need Twilio's bundled AI since Prism owns that layer. |
| `netflie/whatsapp-cloud-api` | Bitrix24 Open Channel WhatsApp | Never — see compliance + architecture rationale above. |
| Native Livewire `wire:stream` | Custom JS frontend (React/Vue + WebSocket) | If we ever need to embed the chatbot on a non-Laravel page. For v2 (Filament-internal + a single Blade product-finder), Livewire is sufficient and avoids a JS toolchain rebuild. |

---

## What NOT to use

| Avoid | Why | Use instead |
|---|---|---|
| **`anthropic-ai/sdk` directly** | 0.x beta, "API may change at any time", no Laravel integration, no tool-loop helper. Pulling it in alongside Prism duplicates HTTP transport. | `prism-php/prism` — wraps Anthropic provider, gives tool-loop + structured output + streaming. |
| **`laravel/ai` (official Laravel SDK)** | PHP `^8.3` floor — incompatible with v1 PHP 8.2 floor. Currently at v0.6.3. Internally depends on Prism anyway. | Use Prism directly until Laravel AI hits 1.0 + we bump PHP min. |
| **`inspector-apm/neuron-ai` / `neuron-core/neuron-ai`** | Adds a parallel agent abstraction. Memory/RAG primitives we don't yet need. Would either run alongside Prism (split brain) or replace v1's Suggestion seam. | Prism + thin `App\Domain\Agents\*Agent` wrappers + existing `Suggestion` seam. |
| **`adultdate/filament-wirechat`** | Filament 4 only — forces the upgrade we deferred at v1 cutover. | Native Livewire 3 `wire:stream` + Filament 3 `Page`. |
| **`ferarandrei1/filament-ai-chat-widget`** | OpenAI-only, Laravel 11 cap. | Native Livewire 3 + Prism with provider-agnostic config. |
| **`icetalker/filament-chatgpt-bot`, `ercogx/openai-assistant`** | OpenAI-locked; no Anthropic. | Same — Prism behind the scenes. |
| **`netflie/whatsapp-cloud-api` v4.0.0-beta1** | Beta released Apr 14 2026 — too fresh for v2 production. | Pin `^3.1` stable line. |
| **`MissaelAnda/laravel-whatsapp`, `crenspire/laravel-whatsapp`, `blissjaspis/laravel-whatsapp-cloud-api`** | All <50k installs vs netflie's 459k; single-maintainer with limited issue volume. | `netflie/whatsapp-cloud-api`. |
| **Bitrix24 Open Channel WhatsApp connectors (Gupshup, ChatApp WACA, WATI, Umnico)** | (1) Compliance posture — adds Russian-origin / paid-intermediary tier we deliberately removed at v1. (2) Architecture — inverts Laravel-as-source-of-truth. | Direct Meta Cloud API via netflie SDK; route to Bitrix as one downstream of inbound events. |
| **`barryvdh/laravel-dompdf`** | Older, locks us to DOMPDF. | `spatie/laravel-pdf` with DOMPDF driver — same engine, escape hatch to Browsershot. |
| **`barryvdh/laravel-snappy`** | Wkhtmltopdf upstream deprecated 2023. | `spatie/laravel-pdf`. |
| **Helicone proxy** | Routes every Anthropic call through a third party — data residency + extra latency on the user-facing chat path. | Self-hosted Langfuse (SDK-based, in-VPS). |
| **`harris21/laravel-fuse`** | Genuinely good circuit-breaker, but team's existing pattern (Cache::add atomic + DB consecutive_failures) is already idiomatic across v1. | Custom `BudgetGuard` (~80 LOC) reusing v1 patterns. |
| **`sadiqueali/laravel-ai-guard`** | Coupled to `laravel/ai` SDK (PHP 8.3 floor); brand new. | Custom `BudgetGuard`. |
| **Meilisearch / Typesense / pgvector / Pinecone for E4 product-finder** | Premature. Catalogue is small (~5k SKUs); MySQL FULLTEXT is enough. Adds infra burden and a second source of truth. | MySQL FULLTEXT + Claude as the natural-language layer. Reassess at v2.1 if recall is poor. |
| **LangChain / LangChain-PHP forks** | Heavy abstraction, JS/Python-first ecosystem; PHP forks are unmaintained. Prism is the Laravel-native equivalent. | Prism. |
| **`maatwebsite/laravel-excel` for any new v2 work** | Already rejected at v1; reasoning unchanged. | `spatie/simple-excel` (already pinned). |

---

## Friction & risk register

| Risk | Severity | Mitigation |
|---|---|---|
| **Prism is pre-1.0** (`^0.100`) — minor releases could break API | MEDIUM | Pin tight (`^0.100`, not `^0.100\|^1.0`). Encapsulate every Prism call inside `App\Domain\Agents\Llm\PrismDriver implements LlmDriver` — swap is a 1-class change if Prism 1.0 breaks the surface. Watch the v1.0 release notes (laravel.com promised "in coming weeks" as of Mar 2026). |
| **`mliviu79/laravel-langfuse-prism` is a single-maintainer package** with 115 installs — bus factor 1 | MEDIUM | The package is a thin OTel exporter shim around Prism middleware. If it becomes abandoned, fall back to either (a) writing our own ~150-LOC Prism middleware that posts to Langfuse REST, or (b) Langfuse's own self-hosted OTel collector. Document this fallback in `Phase X-PLAN`. |
| **Langfuse self-host** adds a Docker stack (Postgres + ClickHouse + server) on the ops VPS | LOW | Disk + RAM cost: ~2 GB/month at our expected token volume. ClickHouse can be retention-pruned to 90 days mirroring v1's `integration_events` retention. Doc in `docs/ops/observability.md` (new). |
| **Prism `withMaxSteps()` defaults to a low number** (~5 historically) — agents could halt mid-task | LOW | Set per-agent: `PricingAgent::MAX_STEPS = 8`, `SeoAgent::MAX_STEPS = 6`, `ChatbotAgent::MAX_STEPS = 12`. Surface in `config/agents.php`. |
| **WhatsApp 24-hour customer-care window** — Meta blocks freeform messages outside it; only template messages allowed for outbound | MEDIUM | Pre-register transactional templates ("quote-ready", "order-update", "stock-alert") via Meta Business Manager *before* feature ship. Document Meta-side approval lead-time (24-72h) in Phase E3 PLAN. The chat agent itself only operates in-window. |
| **Meta WhatsApp webhook signature verification** — easy to get HMAC algorithm wrong | LOW | Mirror v1's WooHmacMiddleware exactly: `hash_hmac('sha256', $rawBody, $appSecret, true)` then base64; compare with `hash_equals` against `X-Hub-Signature-256` (strip `sha256=` prefix). Pest test against fixture payloads from Meta's docs. |
| **Anthropic API outages** — chat-bot UX depends on Claude availability | MEDIUM | Per-tool timeout (default 30s in Prism). User-facing fallback message ("our assistant is briefly unavailable — please email sales@meetingstore.co.uk"). Internal agents (pricing/ads/SEO) can simply skip the run and re-attempt next schedule cycle. Sentry alert on >5 consecutive Anthropic 5xx within 10 min. |
| **DOMPDF font rendering quality** for quote PDFs | LOW | Stick to web-safe fonts (Helvetica, Times) initially. If rejected by sales, swap driver to Browsershot — single-line change. |
| **Cost runaway** if an agent gets stuck in a tool loop | HIGH (cost), LOW (likelihood) | Three-layer defence: Prism `withMaxSteps` (loop ceiling) + `BudgetGuard` (per-agent daily $ ceiling) + Sentry alert on token-spike anomaly. Hard cap defaults: PricingAgent $5/day, SeoAgent $3/day, ChatbotAgent $2/day per session, configurable in `config/agents.php`. |

---

## Stack additions vs v2 features (cross-check)

| v2 Feature | Required new packages | Reuses from v1 |
|---|---|---|
| **C4** Agent framework infrastructure | `prism-php/prism`, `mliviu79/laravel-langfuse-prism` | Suggestions seam; SuggestionApplier contract; IntegrationLogger; DomainEvent; Auditor; Horizon; Shield |
| **C1** Pricing agent | (covered by C4) | competitor_prices, pricing_rules, MarginAnalyser, MarginChangeApplier |
| **C2** Ad optimisation agent | (covered by C4) | Bitrix UTM/GA fields on Deal (UF_CRM_WOO_UTM_*) shipped in v1 Phase 4 |
| **C3** SEO/content agent | (covered by C4) | products.completeness_score field, ProductOpportunityApplier |
| **E1** Trade customer pricing | none (schema-only) | PricingRule resolver, RuleResolver, customer-group field added via migration |
| **E2** Quote request → Bitrix Deal | `spatie/laravel-pdf`, `dompdf/dompdf` | BitrixClient, BitrixSchemaCache, EntityDeduper, CrmPushLog |
| **E3** WhatsApp Business | `netflie/whatsapp-cloud-api` (+ optional `netflie/laravel-notification-whatsapp`) | WooHmacMiddleware pattern, IntegrationLogger, BitrixClient |
| **E4** AI product-finder chatbot | (covered by C4) + zero UI deps (native Livewire 3) | products + categories + brands; MySQL FULLTEXT |

**Conclusion: 4 required new composer packages + 1 PDF engine (DOMPDF) + 0 new JS/CSS dependencies.** The smallest possible stack delta to land all 7 v2 features.

---

## Confidence assessment

| Choice | Confidence | Basis |
|---|---|---|
| `prism-php/prism` as the LLM client | HIGH | Verified Mar 2026 v0.100.1 release on Packagist (3.1M installs); Laravel team acknowledges Prism as the canonical pre-`laravel/ai` solution; documented Anthropic provider + tool-loop semantics. |
| `mliviu79/laravel-langfuse-prism` as observability | MEDIUM-HIGH | Verified Jan 2026 v0.1.0 release; Laravel `^12.0` constraint; explicit Prism integration. **Bus-factor risk acknowledged** — fallback documented. |
| Self-hosted Langfuse over cloud | HIGH | Standard ops trade-off (data residency); Langfuse self-host is documented and stable. |
| `spatie/laravel-pdf` v2.7 with DOMPDF driver | HIGH | Verified Apr 23 2026 v2.7.0 release on Packagist; Spatie maintainer reputation; driver swap proven in v2 docs. |
| `netflie/whatsapp-cloud-api` v3.1 stable | HIGH | 459k installs verified; Meta Cloud API endpoint stability; explicit v3.1 stable vs v4.0.0-beta1 distinction. |
| Native Livewire 3 over Filament chat plugins | HIGH | Filament 3.3 ships Livewire 3.5 already pinned; `wire:stream` documented in Livewire 4.x docs (also present in 3.5+). |
| Custom `BudgetGuard` over off-the-shelf | HIGH | v1 demonstrated the Cache::add + DB-counter idiom across 5 phases — pattern proven. |
| Avoiding `laravel/ai` SDK | HIGH | PHP 8.3 floor incompatibility verified directly on Packagist. |
| MySQL FULLTEXT over vector DB for E4 | MEDIUM | Catalogue size (~5k SKUs) is the assumption; if v2.0 is wrong about that, vector adds ~$50/month + a Phase. |
| Meta Cloud API direct vs Bitrix Open Channel | HIGH | Compliance + architecture rationale aligns with v1's foundational decision (sanctions-driven SDK selection). |

---

## Sources

**Official documentation / Packagist (HIGH confidence):**
- [Prism — Tools & Function Calling docs](https://prismphp.com/core-concepts/tools-function-calling/) — verified `withTools()`, `withMaxSteps()`, parallel tool execution
- [Packagist — prism-php/prism](https://packagist.org/packages/prism-php/prism) — v0.100.1 / Mar 20 2026 / Laravel `^11.0|^12.0|^13.0` / PHP `^8.2`
- [Packagist — prism-php/relay](https://packagist.org/packages/prism-php/relay) — v1.7.0 / Mar 1 2026 / MCP client tool
- [Packagist — laravel/ai](https://packagist.org/packages/laravel/ai) — v0.6.3 / Apr 22 2026 / **PHP `^8.3`** (incompatible)
- [Packagist — anthropic-ai/sdk](https://packagist.org/packages/anthropic-ai/sdk) — v0.17.0 / Apr 23 2026 / beta status
- [Packagist — mliviu79/laravel-langfuse-prism](https://packagist.org/packages/mliviu79/laravel-langfuse-prism) — v0.1.0 / Jan 13 2026 / single-maintainer
- [Packagist — spatie/laravel-pdf](https://packagist.org/packages/spatie/laravel-pdf) — v2.7.0 / Apr 23 2026 / driver-based architecture
- [Packagist — netflie/whatsapp-cloud-api](https://packagist.org/packages/netflie/whatsapp-cloud-api) — 3.1 stable / 4.0.0-beta1 / 459k installs / Meta Cloud API
- [Packagist — netflie/laravel-notification-whatsapp](https://packagist.org/packages/netflie/laravel-notification-whatsapp) — v1.4.0 / Apr 2 2025
- [GitHub — anthropics/anthropic-sdk-php](https://github.com/anthropics/anthropic-sdk-php) — beta status confirmed
- [GitHub — prism-php/prism](https://github.com/prism-php/prism) — 2.4k stars, 563 commits
- [Driver-Based Architecture in Spatie's Laravel PDF v2 — Laravel News](https://laravel-news.com/driver-based-architecture-in-spaties-laravel-pdf-v2)
- [Livewire 4.x — wire:stream](https://livewire.laravel.com/docs/4.x/wire-stream) — token streaming for chat-bot UI

**Cross-reference articles (MEDIUM confidence — used to verify ecosystem patterns, not as sole source):**
- [Prism Makes AI Feel Laravel Native — Laravel.com blog](https://laravel.com/blog/prism-makes-ai-feel-laravel-native-the-artisan-of-the-day-is-tj-miller)
- [Building Reliable Agentic Loops with Laravel Workflow and PrismPHP — Medium](https://medium.com/@rlmc/building-reliable-agentic-loops-with-laravel-workflow-and-prismphp-85f5738f71f6)
- [Observability for Claude Agent SDK with Langfuse — Langfuse docs](https://langfuse.com/integrations/frameworks/claude-agent-sdk)
- [Spatie's Laravel PDF vs Laravel DomPDF — Medium 2026 comparison](https://medium.com/@developerawam/spaties-laravel-pdf-vs-laravel-dompdf-which-one-should-you-actually-use-8c1d2ca104f7)
- [PHP CMS Framework — Laravel and Prism PHP — April 2026](http://www.phpcmsframework.com/2026/04/laravel-prism-php-ai-models.html)

**MEDIUM confidence — ecosystem context:**
- [Laravel AI Guard — Sadique Ali, Feb 2026](https://sadiqueali.medium.com/laravel-ai-guard-control-and-optimize-ai-costs-in-laravel-ai-sdk-applications-176705358d1b) — agent cost-control patterns (informs custom BudgetGuard design)
- [Token-Based Rate Limiting for AI Agents — Zuplo 2026](https://zuplo.com/learning-center/token-based-rate-limiting-ai-agents) — modern AI rate-limit posture
- [LLM Observability Comparison — Athenic Blog 2026](https://getathenic.com/blog/langsmith-vs-helicone-vs-langfuse-comparison) — Helicone vs Langfuse trade-offs

---

*Stack research for: MeetingStore Ops v2.0 Intelligence + B2B (additions only)*
*Researched: 2026-04-24*
*Net new composer packages: 4 required + 2 optional*
*Net new dev/JS dependencies: 0*
*Version bumps to v1 packages: 0*
