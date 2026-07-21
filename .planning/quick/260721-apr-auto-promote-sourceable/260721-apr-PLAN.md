# 260721-apr — Auto-promote products that become sourceable again (close the one-way door)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Operator business rule (confirmed):** *"A product with no current supplier listing should MOVE TO PENDING."*
`supplier:db-sync --flag-obsolete` implements that correctly. **But it's a one-way door** — nothing
promotes a product back when a supplier lists it again, so the catalogue slowly under-sells (7 such
products found on prod today). This task closes the loop.

## Key correction driving the design
`products:restore-sourceable-pending` (260713-rsp) restores the LOCAL status only — built on the
assumption that pending products were still `publish` on Woo. **That assumption was wrong:** Woo mirrors
the local status (Woo admin: All 6,201 / Published 4,633 / **Pending 1,568** ≈ local publish 4,640 /
pending 1,561). So a local-only restore leaves the product still hidden on the storefront. To actually
make it sellable again the status must be **pushed to Woo**.

## Tasks

### Task 1 — `--push-to-woo` on `products:restore-sourceable-pending` (TDD)
Mirror the app's established flag pattern (`products:source-images --push-to-woo`,
`backfill-merchant-feed --push-to-woo`). When set, after restoring a product's local status to the
published value, push the status to Woo:
- Only for products with a `woo_product_id` (skip + count others).
- Push **via `WooClient`** — never a raw HTTP call — so it automatically inherits the 260719-wth
  throttle (serialisation + rate limit + pacing), the shadow gate (`WOO_WRITE_ENABLED=false` ⇒ records a
  SyncDiff, no live write), the AbortGuard and the audit trail.
- Default OFF (backward compatible: no flag ⇒ zero Woo calls, exactly as today).
- Report counts: restored-local / pushed-to-Woo / shadowed / skipped-no-woo-id.

### Task 2 — Schedule it (TDD/verify)
Add to `routes/console.php` a daily run AFTER the supplier sync (which is Mon–Fri 07:00), e.g.
`Schedule::command('products:restore-sourceable-pending', ['--live' => true, '--push-to-woo' => true])
->dailyAt('07:30')->withoutOverlapping()` (London TZ, matching the file's convention). Safe to schedule
now: with `WOO_WRITE_ENABLED=false` the push is a shadow no-op, and it becomes effective the moment
writes are re-enabled. Add a comment saying exactly that.

## Verify
- `pest`: `--push-to-woo` pushes the published status via WooClient for a restored product **with** a
  woo_product_id; **no flag ⇒ no Woo call at all** (backward compat); a product without `woo_product_id`
  is skipped and counted; shadow mode performs no live write (assert via the WooClient seam / no real
  HTTP); the existing 260713-rsp behaviour (in-stock-only selection, carve-outs, dry-run default) stays
  green.
- `php artisan schedule:list` shows the new daily entry; `route:list --path=admin` exit 0.
- `pint`; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / out of scope
- **Do NOT change `WOO_WRITE_ENABLED`** and do NOT alter the demotion logic — `--flag-obsolete` is
  CORRECT per the operator's rule; this task only adds the missing inverse.
- All Woo interaction MUST go through `WooClient` (throttle + shadow gate + audit). No raw HTTP, no
  bypassing the gate. No migration.
- Selection signal unchanged from 260713-rsp: only products with a CURRENT fresh in-stock supplier offer
  are promoted (churn-safe — they won't be re-demoted by the next sync).
- Driver-portable (SQLite tests / MariaDB prod). Do NOT stage the pre-existing working-tree noise
  (`storage/app/research/supplier-probe.json`, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`,
  untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260721-apr-SUMMARY.md` noting: deploy is behaviourally inert while shadow; the promote only becomes
  effective once `WOO_WRITE_ENABLED=true`; and that the first live run doubles as a small, ideal canary
  for the new throttle (~7 products).
