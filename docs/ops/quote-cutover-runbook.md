# Phase 11 Quote → Bitrix Cutover Runbook

**Audience:** ops + admin operators
**Owner:** RAMS Platform team
**Last reviewed:** 2026-05-01 (Plan 11-04 ship)

This runbook covers flipping Phase 11's quote-flow from shadow mode (default
post-deploy) to live writes against the Bitrix tenant.

---

## TL;DR

1. Deploy Phase 11 with `QUOTE_BITRIX_PUSH_ENABLED=false` (default).
2. Operator runs `php artisan bitrix:quotes-bootstrap` → must PASS.
3. Operator runs sandbox test sequence (below).
4. Edit production `.env`: `QUOTE_BITRIX_PUSH_ENABLED=true`.
5. `php artisan config:clear && php artisan horizon:terminate`.
6. Monitor first 5 quote approvals.

Rollback: flip env back to `false`, repeat steps 5.

---

## Pre-flight (REQUIRED before flipping QUOTE_BITRIX_PUSH_ENABLED=true)

### Step 1 — Verify deal type + custom field

```bash
php artisan bitrix:quotes-bootstrap
```

Expected output:

```
Correlation: <uuid>
bitrix:quotes-bootstrap — deal category PASS: ID=5 NAME=QUOTE matches QUOTE_BITRIX_DEAL_TYPE_ID=QUOTE.
bitrix:quotes-bootstrap — UF_CRM_WOO_QUOTE_ID already exists, skipping.
                       (or: bitrix:quotes-bootstrap — created UF_CRM_WOO_QUOTE_ID.)

bitrix:quotes-bootstrap PASS — UF_CRM_WOO_QUOTE_ID present, dealtype QUOTE verified.
Operator may now flip QUOTE_BITRIX_PUSH_ENABLED=true.
```

Exit code `0` = ready. Exit code `1` = dealtype missing — follow operator
runbook printed by the command. Exit code `2` = transport / auth error —
check `BITRIX_WEBHOOK_URL` permissions.

### Step 2 — Sandbox probe (RESEARCH §Pre-Cutover Bitrix Sandbox Probe)

Against a **non-production** Bitrix tenant with `QUOTE_BITRIX_PUSH_ENABLED=true`:

1. Create a test Quote in Filament admin → add 2 lines → Approve.
2. In Bitrix CRM → Deals: confirm a new Deal appears with
   `TYPE_ID=QUOTE`, `UF_CRM_WOO_QUOTE_ID` matches the Quote ULID, and the
   Products tab shows the 2 line items.
3. Re-Approve the same Quote (admin → Quote → Approve again, or use the
   Revert + Approve admin-only path within 5 min).
4. Confirm NO duplicate Bitrix Deal appears — the existing Deal is updated
   (BitrixEntityMap dedup on `entity_type=quote_deal, quote_id=...`).
5. Verify `crm.deal.productrows.set` rendered the line items correctly in the
   Bitrix UI (PRODUCT_NAME, PRICE, QUANTITY visible).

If all 5 pass on sandbox, proceed to production cutover.

---

## Flip live (production)

```bash
# Edit production .env
sed -i 's/^QUOTE_BITRIX_PUSH_ENABLED=false$/QUOTE_BITRIX_PUSH_ENABLED=true/' .env

# Reload config in long-running workers
php artisan config:clear
php artisan horizon:terminate
```

Horizon respawns with the new config picked up. The next QuoteApproved event
will dispatch `PushQuoteToBitrixDealJob` to the `crm-bitrix` Horizon queue
and call BitrixClient live (instead of writing to sync_diffs).

### Monitor first 5 approvals

- Horizon dashboard `/admin/horizon/dashboard` → `crm-bitrix` queue: zero
  failed jobs after the first 5 quote approvals.
- `storage/logs/laravel.log` → search for `PushQuoteToBitrixDealJob: pushed quote`
  log lines (one per successful push).
- Filament Suggestions inbox: zero `quote_push_failed` rows.

If any push fails:

- Suggestion appears with `kind=quote_push_failed` — review evidence payload.
- AlertRecipient mail dispatched to `receives_quote_alerts=true` recipients
  on retry exhaustion (5-min dedup per Quote ID).

---

## Rollback

If quote pushes fail repeatedly after cutover:

```bash
sed -i 's/^QUOTE_BITRIX_PUSH_ENABLED=true$/QUOTE_BITRIX_PUSH_ENABLED=false/' .env
php artisan config:clear
php artisan horizon:terminate
```

Subsequent quote approvals serialise to `sync_diffs` with
`provider='bitrix-quote'`. To replay accumulated diffs after fixing the
underlying issue:

```bash
# Plan 11-05 ships sync-diffs:replay — operator awaits ship before manual replay.
php artisan sync-diffs:replay --provider=bitrix-quote --since=YYYY-MM-DD
```

(Until Plan 11-05 ships the replay command, manual SQL replay is the
fallback — see `App\Domain\Sync\Models\SyncDiff` schema.)

---

## Failure modes

| Symptom | Likely cause | Action |
|---------|--------------|--------|
| `quote_push_failed` Suggestion `sub_kind=permanent_validation` | 4xx Bitrix error (bad TYPE_ID, missing custom field) | Re-run `bitrix:quotes-bootstrap` |
| `quote_push_failed` Suggestion `sub_kind=push_exhausted` | 3 retries hit 5xx / network | Check Bitrix tenant health; retry via Suggestion approval |
| AlertRecipient mail received | DLQ alert (post-retry-exhaustion) | Review Suggestion evidence; replay after fix |
| Sync_diffs accumulating with `provider=bitrix-quote` | `QUOTE_BITRIX_PUSH_ENABLED=false` | Either flip env true OR replay diffs after fix |

---

## Cross-references

- `.planning/phases/11-e2-quote-request-bitrix-deal-flow/11-CONTEXT.md` — D-04 + D-09
- `.planning/phases/11-e2-quote-request-bitrix-deal-flow/11-RESEARCH.md` — A6, A7, A8, OQ-3, OQ-4
- `app/Domain/CRM/Jobs/PushQuoteToBitrixDealJob.php` — push pipeline body
- `app/Domain/CRM/Console/Commands/BitrixQuotesBootstrapCommand.php` — pre-flight command
- `config/quote.php` — `bitrix_push_enabled`, `bitrix_quote_type_verified` cutover gates
