# Phase 12: C3 SEO / Content Agent - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-07
**Phase:** 12-c3-seo-content-agent
**Areas discussed:** Brand-voice sources, Patch granularity, Approval write-through
**Areas skipped by user:** Confidence + rejection inbox (user opted out — covered as Claude's Discretion in CONTEXT.md)

---

## Brand-voice sources

### Q1 — Where should brand-voice content + guardrail patterns live?

| Option | Description | Selected |
|--------|-------------|----------|
| Markdown files (Recommended) | resources/agents/brand-voice/{brand}.md for content + config/seo_agent_guardrails.php for regex. Version-controlled, edited via PR. | ✓ |
| DB table + Filament Resource | brand_style_guides + seo_guardrail_patterns tables, managed by admin via Filament. Allows non-dev edits but adds 2 migrations + 2 Resources. | |
| PHP config files only | Everything in config/seo_agent.php as nested arrays. Simplest, but tied to deploys for any tweak. | |

**User's choice:** Markdown files (Recommended)
**Notes:** Accepted recommended option without modification. Brand voice rarely changes in v2.0; PR-based editing is sufficient.

### Q2 — Single global voice or per-brand?

| Option | Description | Selected |
|--------|-------------|----------|
| Hybrid (Recommended) | Global default + optional per-brand override files. Falls back to global when no per-brand file exists. | ✓ |
| Single global voice | One MeetingStore voice doc applies to all products. Brand parameter ignored. | |
| Per-brand only | Every brand needs its own voice file; agent fails if missing. Most rigorous but high maintenance. | |

**User's choice:** Hybrid (Recommended)
**Notes:** Accepted recommended option. Enables per-brand variation without blocking the framework for new brands.

---

## Patch granularity

### Q3 — How does an agent run produce Suggestions per product?

| Option | Description | Selected |
|--------|-------------|----------|
| One bundled Suggestion (Recommended) | Single Suggestion of kind=seo_content_patch with payload listing all 4 field patches. UI shows all four diffs with per-field approve checkboxes. | ✓ |
| Up to 4 separate Suggestions | Each patched field becomes its own Suggestion. Granular but spawns 4x inbox rows per product. | |
| One per touched field, same kind | kind=seo_content_patch but one row per field, with payload.field set. | |

**User's choice:** One bundled Suggestion (Recommended)
**Notes:** Accepted recommended option. Cleaner mapper, less inbox noise, per-field approve handled via UI checkboxes within the bundled Suggestion.

---

## Approval write-through

### Q4 — When admin approves a patch, what writes where?

| Option | Description | Selected |
|--------|-------------|----------|
| Product canonical + pin flag (Recommended) | Write to Product.{field} (canonical) AND set ProductOverride.pin_{field}=true. Matches Phase 6 pin semantics; no new columns. | ✓ |
| New pinned_{field} columns on ProductOverride | Add migration for pinned_title/short_description/etc. Approved patch writes there; Product.{field} stays as supplier-sync target. Adds 4 migration columns. | |
| Both — canonical AND pinned copy | Write Product.{field} live AND ProductOverride.pinned_{field} for rollback. Adds migration but enables recoverability. | |

**User's choice:** Product canonical + pin flag (Recommended)
**Notes:** Accepted recommended option. Matches existing Phase 6 ProductOverride pin semantics; zero migrations needed for v2.0 ship.

---

## Claude's Discretion

User opted not to discuss confidence band + rejection inbox parity with Phase 10. CONTEXT.md captures the decision to SKIP both for v2.0 (defer to v2.1 if calibration data justifies the addition). Other discretion items resolved in CONTEXT.md:

- Temperature = 0.4 (REQUIREMENTS.md line 124 allows higher-than-zero for SEO)
- Scheduled time = 04:30 Europe/London (between competitor FTP pull and supplier sync)
- Batch eligibility query skips products with existing pending or applied SEO suggestion
- Tool location: `app/Domain/Agents/Tools/Seo/`
- Agent class: `app/Domain/Agents/Agents/SeoAgent.php` (mirrors PricingAgent)
- Zero migrations (ProductOverride pin columns already exist)
- Permission `run_seo_agent` for admin + pricing_manager

## Deferred Ideas

- Per-suggestion confidence band — defer to v2.1
- Dedicated rejection inbox page — defer to v2.1
- Auto-apply for SEO patches — explicitly never planned
- DB-managed brand voice + guardrails via Filament — revisit if ops needs frequent edits
