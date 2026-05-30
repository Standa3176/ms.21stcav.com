# Cutover — Flip-Day Script

**One-page, copy-paste sequence for the morning you flip `WOO_WRITE_ENABLED=true`.**

Companion to [`cutover-runbook.md`](./cutover-runbook.md) — the runbook is the *why*, this is the *typing*. Whole sequence takes ~30 minutes once you start. Every artisan command runs as `stcav`, from `/home/stcav/ms.21stcav.com`.

**Kill switch at any moment:**
```bash
sed -i 's/^WOO_WRITE_ENABLED=true/WOO_WRITE_ENABLED=false/' /home/stcav/ms.21stcav.com/.env
sudo -u stcav php artisan config:clear
```

---

## Before you start (do these the day before, NOT on flip morning)

- [ ] **VAT basis confirmed.** Pick any product in WP admin → compare its `Regular price` to the storefront display. Inc-VAT → `WOO_PUSH_PRICES_EX_VAT=false` (current default). Ex-VAT → set `WOO_PUSH_PRICES_EX_VAT=true`.
- [ ] **Hygiene done.** GitHub PAT revoked, admin password rotated, 2 WP DB passwords rotated.
- [ ] **Bitrix decision made.** Either `bitrix:quotes-bootstrap` + mark the gate, or accept skipping quote-push at go-live.
- [ ] **2–3 canary SKUs chosen.** Real SKUs you actively sell, on stock, with recent buy_price. Suggested: `MUYHSMFFADW` (the one we fixed earlier) plus two of your own picking.
- [ ] **Flip window booked.** Quiet weekday 09:00–11:00 London. Block out the next 2 hours and the next 24h for monitoring availability.

---

## Step 1 — T-0:10 final sanity check

```bash
cd /home/stcav/ms.21stcav.com

# Confirm only the inherently-flip-or-later gates remain PENDING
sudo -u stcav php artisan cutover:checklist

# Fresh snapshot (cheap insurance on top of the pre-cutover one)
sudo -u stcav bash -c 'WOO_DB_HOST=localhost WOO_DB_DATABASE=meetingstoreco_wp28 WOO_DB_USERNAME=meetingstoreco_wp28 WOO_DB_PASSWORD=<wp-config-password> php artisan cutover:snapshot-woo-db --label=flip-morning'

# Glance at /admin → SyncDiffsParityWidget should be green
```

---

## Step 2 — Disable the legacy WP plugins (ends parallel run)

```bash
sudo -u stcav CUTOVER_DISABLE_LIVE_ALLOWED=true php artisan cutover:disable-legacy-plugins --live
sudo -u stcav php artisan cutover:checklist --update-status=legacy-plugins-disabled:pass
```

The old Stock Updater + itgalaxy Bitrix crons are now deregistered. From here, nothing competes with the Laravel app.

---

## Step 3 — Flip the switch (go-live)

```bash
sed -i 's/^WOO_WRITE_ENABLED=false/WOO_WRITE_ENABLED=true/' /home/stcav/ms.21stcav.com/.env
grep '^WOO_WRITE_ENABLED' /home/stcav/ms.21stcav.com/.env     # must echo: WOO_WRITE_ENABLED=true
sudo -u stcav php artisan config:clear
sudo -u stcav php artisan horizon:terminate                   # workers reload the env
sudo -u stcav php artisan cutover:checklist --update-status=flag-flip:pass
```

The next Woo write will be a real PUT, not a shadow SyncDiff.

---

## Step 4 — CANARY (push 2–3 SKUs ONE AT A TIME and verify each on the storefront)

```bash
sudo -u stcav php artisan pricing:undercut-competitors --skus=MUYHSMFFADW --live
```
- **Wait ~30 seconds for the queue.**
- **Open `https://meetingstore.co.uk/?p=<woo_product_id>` in a browser** (look up the woo_product_id locally if needed: `sudo -u stcav php artisan tinker --execute='echo \App\Domain\Products\Models\Product::where("sku","MUYHSMFFADW")->value("woo_product_id");'`).
- **Confirm the new price is showing.**

Repeat with two more SKUs. If anything looks wrong → **kill switch above**, then debug.

---

## Step 5 — Reconcile obsolete statuses (the real C-NEW step)

```bash
# Re-run flag-obsolete to catch any demotions since last run
sudo -u stcav php artisan supplier:db-sync --flag-obsolete

# Now push status to Woo — these are REAL PUTs (no longer shadow)
sudo -u stcav php artisan products:push-status-to-woo --live

# Spot-check one on storefront — it should now show "not available" / 404
sudo -u stcav php artisan tinker --execute='$p=\App\Domain\Products\Models\Product::whereNotNull("woo_product_id")->where("status","pending")->orderByDesc("updated_at")->first(); echo "spot-check: https://meetingstore.co.uk/?p=".$p->woo_product_id."\n";'

sudo -u stcav php artisan cutover:checklist --update-status=obsolete-statuses-pushed:pass
```

---

## Step 6 — Enable the daily pricing schedule

ONLY after the canary passed. Set in `.env`:
```bash
echo 'PRICING_UNDERCUT_SCHEDULE_ENABLED=true' >> /home/stcav/ms.21stcav.com/.env
sudo -u stcav php artisan config:clear
```

Daily `pricing:undercut-competitors --live` at 08:00 London will now run. Tomorrow 08:00 is the first real test of the schedule.

---

## Step 7 — First-hour watch

Keep these in front of you for the first 60 minutes:

```bash
# AbortGuard status (should stay green; trips at 20% errors or 50 consecutive failures)
sudo -u stcav php artisan tinker --execute='echo (new \App\Domain\Sync\Services\AbortGuard)->status();' 2>/dev/null || true

# Failed jobs (should stay empty)
watch -n 30 'sudo -u stcav php artisan queue:failed | head -20'

# Horizon dashboard (in a browser)
# https://ms.21stcav.com/horizon

# Live tail of the Laravel log
sudo -u stcav tail -f /home/stcav/ms.21stcav.com/storage/logs/laravel.log
```

If error count climbs OR AbortGuard trips → **kill switch**, investigate, decide whether to re-flip.

---

## Step 8 — Day 1 to Day 7 (monitoring window)

Daily checks (5 minutes each morning):
```bash
sudo -u stcav php artisan cutover:checklist                  # confirm nothing regressed
sudo -u stcav php artisan queue:failed | head -20            # confirm no DLQ buildup
```

Glance at `/admin` Home Dashboard — Integration Health tiles all green, SyncDiff counts as expected (post-flip these are now successful writes, not shadowed).

After 7 clean days:
```bash
sudo -u stcav php artisan cutover:checklist --update-status=monitoring-7-days:pass
```

Next Monday 07:00 London the weekly digest lands. After confirming:
```bash
sudo -u stcav php artisan cutover:checklist --update-status=weekly-digest-landed:pass
```

`cutover:checklist` now exits 0. Cutover complete.

---

## Rollback decision tree

| Symptom | Action |
|---|---|
| Canary price wrong on storefront | Kill switch → check VAT basis, re-flip after fix |
| AbortGuard tripped | Kill switch → check Horizon failed jobs, investigate, re-flip after fix |
| Mass divergence (Home Dashboard parity drops sharply) | Kill switch → run `cutover:divergence-scan --live` to identify, fix, re-flip |
| Storefront totally broken | Kill switch + restore the morning snapshot (`storage/app/cutover/*.sql.gz`) into `meetingstoreco_wp28` |
| Bitrix push failures only | Set `QUOTE_BITRIX_PUSH_ENABLED=false`; price + sync remain live |

The flag is the rollback. Restoring the DB snapshot is only needed if the live store itself gets corrupted (`AbortGuard` should prevent that).
