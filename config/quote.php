<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 11 — E2 Quote Request → Bitrix Deal Flow (CONTEXT.md §Decisions)
|--------------------------------------------------------------------------
|
| Company-identity fields are HARD-CODED (no env var) per CONTEXT.md
| Claude's Discretion — they change so rarely that an env override would
| be more error-prone than direct edits to this file. The two override-able
| company fields (vat_number + registration_number) are env-backed for ops
| convenience when scaling across multiple legal entities.
|
| Quote workflow gates:
|   - bitrix_push_enabled — cross-cutting invariant 4 shadow-mode gate.
|     Default FALSE. When false, Plan 11-04 PushQuoteToBitrixDealJob writes
|     payload to sync_diffs (provider='bitrix-quote') instead of calling
|     BitrixClient::dealAdd. Operator flips manually after the
|     `bitrix:quotes-bootstrap` runbook PASSES.
|   - bitrix_quote_type_verified — Plan 11-05 cutover gate. Default FALSE.
|     Flipped TRUE by ops after Bitrix admin confirms TYPE_ID=QUOTE deal
|     type exists. Plan 11-04 pre-flight check refuses to push when this
|     is false.
*/

return [
    // ── Company identity (PDF header + Bitrix Deal seed payload) ─────────
    'company_name' => 'MeetingStore Limited',
    'company_address' => "Suite 8, 110 Bishopsgate\nLondon EC2N 4AD",
    'company_vat_number' => env('COMPANY_VAT_NUMBER', 'GB123456789'),
    'company_registration_number' => env('COMPANY_REGISTRATION_NUMBER', '12345678'),

    // ── Quote workflow ────────────────────────────────────────────────────

    // expires_at = created_at + this many days (D-XX 14-day default).
    'default_expiry_days' => env('QUOTE_DEFAULT_EXPIRY_DAYS', 14),

    // Customer-facing expiry email — opt-in via ops decision after observing
    // v1 expiry volume (CONTEXT.md Claude's Discretion).
    'email_on_expiry' => env('QUOTE_EMAIL_ON_EXPIRY', false),

    // PDF reserves a printed signature block at the bottom — e-Signature
    // integration deferred to v2.x per ROADMAP success criterion 3.
    'pdf_signature_block' => env('QUOTE_PDF_SIGNATURE_BLOCK', false),

    // ── Bitrix push pipeline (Plan 11-04 + Plan 11-05) ───────────────────

    // The Bitrix Deal TYPE_ID for quote-deals — operator creates this via
    // Bitrix admin per the runbook. Plan 11-04 pre-flight check verifies.
    'bitrix_deal_type_id' => env('QUOTE_BITRIX_DEAL_TYPE_ID', 'QUOTE'),

    // Cross-cutting invariant 4 — shadow-mode gate. DEFAULT FALSE.
    // When false, PushQuoteToBitrixDealJob writes to sync_diffs instead of
    // calling BitrixClient. Operator flips TRUE manually after
    // bitrix:quotes-bootstrap runbook PASSES (Plan 11-05 ships the runbook).
    'bitrix_push_enabled' => env('QUOTE_BITRIX_PUSH_ENABLED', false),

    // Plan 11-05 cutover gate — flipped TRUE by ops after Bitrix admin
    // confirms TYPE_ID=QUOTE deal type exists in the live tenant.
    // Plan 11-04 pre-flight refuses to push when this is false.
    'bitrix_quote_type_verified' => env('QUOTE_BITRIX_TYPE_VERIFIED', false),
];
