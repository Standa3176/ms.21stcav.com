# Phase 11: E2 Quote Request → Bitrix Deal Flow - Discussion Log

> **Audit trail only.** Decisions are captured in CONTEXT.md.

**Date:** 2026-04-30
**Phase:** 11-e2-quote-request-bitrix-deal-flow
**Mode:** interactive (user invoked without --auto)
**Areas discussed:** Customer model + quote ownership; Approval + send workflow; Quote acceptance mechanism; Line-add UX + VAT display

---

## Customer model + quote ownership

| Option | Description | Selected |
|--------|-------------|----------|
| Both — nullable user_id FK + denormalised fields (Recommended) | Quote.user_id NULLABLE FK + customer_email/customer_name/billing_address fallback for anonymous leads | ✓ |
| FK only — require registered user | Blocks lead-quoting flow | |
| Free-text only — no FK | QUOT-01 verbatim; loses customer-history queries | |

**User's choice:** Both — dual-mode.
**Notes:** Unblocks Phase 13 WhatsApp + Phase 14 chatbot anonymous lead flows.

---

## Approval + send workflow

| Option | Description | Selected |
|--------|-------------|----------|
| Single Approve action transitions draft → sent (Recommended) | One button, atomic: status flip + QuoteApproved event + PDF email + audit_log | ✓ |
| Two-step: Approve (pricing_manager) → Send (sales) | More clicks; small-team overhead | |
| Three-step: Draft → Approve → Send → Accept | Heavyweight v1 | |

**User's choice:** Single Approve.
**Notes:** Intermediate enum values (pending_approval / approved) reserved unused for v1.x extension without migration. Sales cannot self-approve (separation of duties).

---

## Quote acceptance mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Manual sales update — v1 simplest (Recommended) | Customer replies via email/phone/Bitrix; sales clicks Mark-as-accepted | ✓ |
| Accept-link in PDF + public token route | Token security + anti-replay overhead | |
| Bitrix Deal stage mirror-back | Out of scope (Phase 4 v1 = one-way Woo→Bitrix) | |

**User's choice:** Manual sales update.
**Notes:** Reject action prompts structured reason (price_too_high / wrong_specifications / competitor_won / delayed_decision / other) + free-text. Persists to Quote.rejection_metadata JSON.

---

## Line-add UX + VAT display on PDF

| Option | Description | Selected |
|--------|-------------|----------|
| Search-and-add picker + ex-VAT itemised PDF (Recommended) | Reuse Phase 6 ProductResource search; UK B2B convention | ✓ |
| Manual SKU entry only + inc-VAT itemised PDF | Friendlier for retail; unusual for B2B | |
| Search picker + bulk paste + ex-VAT itemised | Bulk paste deferred to v1.1 | |

**User's choice:** Search-and-add picker + ex-VAT itemised PDF.
**Notes:** Bulk paste deferred. Quantity is integer (no fractional units in B2B AV).

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- DOMPDF driver for spatie/laravel-pdf (per ROADMAP success criterion 3)
- PDF generated on-demand; storage/app/quotes/ retention deferred
- Branded header logo at public/images/meetingstore-logo.png
- Bitrix Deal TYPE_ID=QUOTE pre-flight check via crm.dealtype.list
- Bitrix line-item modelling: lean toward crm.deal.productrows.set; RESEARCH validates
- Quote.id 26-char ULID; PDF reference shows 8-char short form
- crm-bitrix queue for PushQuoteToBitrixDealJob
- Phase 4 D-11 retry pattern for quote pushes (3 attempts, 30s/5m/30m, 4xx fail-fast → quote_push_failed Suggestion)
- expires_at default 14 days, configurable
- Customer expiry email default OFF
- Filament navigation group "Sales"
- Deptrac Quotes layer one-way arrow (CRM → Quotes allowed; Quotes → CRM denied)
- PushQuoteToBitrix listener placement: app/Domain/CRM/Listeners/ (CRM owns Bitrix)
- AlertRecipient.receives_quote_alerts toggle extension
- B-03 byte-identity sha256 baselines for TradeRuleResolver + PriceCalculator + BitrixClient

## Deferred Ideas

- Public accept-link in PDF (token-signed URL → status flip)
- Bitrix Deal stage mirror-back (inbound webhook → Quote.status)
- e-Signature integration (DocuSign / Adobe Sign)
- Bulk paste line-add UX
- Per-brand quote PDF templates
- Quote_history relation (full edit history)
- Quote PDF storage / archival
- Quote analytics dashboard (Phase 7 territory)
- Multi-currency quotes (GBP only in v1)
- Quote line discounts (per-line override)
- Reject reason analytics dashboard
- QuoteAccepted/Rejected Bitrix Deal stage transitions
- Customer self-service "my quotes" page
- Quote → Order conversion
- Per-customer quote rate limits
