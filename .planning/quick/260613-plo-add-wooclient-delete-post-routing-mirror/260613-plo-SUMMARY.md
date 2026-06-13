---
phase: quick-260613-plo
plan: 01
type: execute
wave: 1
files_modified:
  - config/services.php
  - app/Domain/Sync/Services/WooClient.php
  - tests/Feature/WooClientDeleteTest.php
commits:
  - c6708d6 feat(260613-plo): WooClient::delete() POST-tunneled via ?_method=DELETE for WAF compatibility
  - f6943b8 test(260613-plo): WooClientDeleteTest cases A-G + DRIFT guard
verify_results:
  WooClientDeleteTest: "8/8 PASS (27 assertions, 16.43s)"
  DedupeBrandsCommandTest: "10/10 PASS (62 assertions, 8.68s) — PRIMARY CONSUMER"
  RetagProductsOnWooCommandTest: "12/12 PASS (62 assertions, 73.38s)"
  BrandDuplicateFinderTest: "7/7 PASS (36 assertions, 4.81s)"
out_of_scope:
  - "4 pre-existing WooClientGetTest.php failures (last touched in fb7ac18 260530-clv) — NOT addressed in 260613-plo per brief spec point 6"
duration_minutes: 8
completed_at: "2026-06-13T15:30:00Z"
---

# Quick 260613-plo: WooClient::delete() POST-routing mirror — SUMMARY

**One-liner:** `WooClient::delete()` now tunnels through HTTP POST with `?_method=DELETE` under default config so `brands:dedupe --delete-empty-woo-terms` stops getting 403'd by the CWP/Imunify360 WAF default block on HTTP DELETE to `/wp-json/*`.

## What shipped

1. **New config key `services.woo.use_post_for_deletes`** (default `true`, env `WOO_USE_POST_FOR_DELETES`) — adjacent to existing `use_post_for_updates` in `config/services.php`. Grep-discoverability is load-bearing: operators searching for `use_post_for_updates` find the delete twin in the same screen.

2. **`WooClient::delete()` rewritten** (`app/Domain/Sync/Services/WooClient.php` line 168 area). Signature byte-identical (`public function delete(string $endpoint, array $payload = []): array`). Branches:
   - **Default (`use_post_for_deletes=true`):** compute separator (`&` if endpoint already has a `?`, else `?`) → `writeOrShadow('POST', $endpoint.$separator.'_method=DELETE', $payload)`. The on-the-wire method is POST; the endpoint string carries `?_method=DELETE` (or `&_method=DELETE`) so operators can grep audit logs for tunnelled deletes.
   - **Opt-out (`use_post_for_deletes=false`):** original behaviour byte-for-byte — `writeOrShadow('DELETE', $endpoint, $payload)`.
   - **`dispatchWrite()` untouched** — the existing `'POST'` arm already maps to `$sdk->post($endpoint, $payload)` and the SDK forwards `?_method=DELETE` as part of the URL via Guzzle's UriResolver naturally. No new `match()` arm needed.
   - **`put()`, `post()`, `patch()` untouched** — PUT path (260530-clv) is byte-identical (proved by Case G).

3. **`tests/Feature/WooClientDeleteTest.php`** (267 lines, NEW) — 7 routing cases A-G + 1 architecture DRIFT guard:
   - **A** Default config → POST + `?_method=DELETE` appended; `delete()` NOT called.
   - **B** Opt-out → strict DELETE; `post()` NOT called.
   - **C** Endpoint with existing query string uses `&` separator.
   - **D** Empty payload default does not crash, payload stays `[]`.
   - **E** Audit log row in `integration_events`: `channel='woo', method='POST', endpoint='products/brands/42?_method=DELETE', status='success', http_status=200`.
   - **F** Shadow-mode gate (`write_enabled=false`): `SyncDiff` row created with `method='POST', endpoint='products/brands/42?_method=DELETE'`; `AutomatticClient` NEVER called.
   - **G** Backward-compat regression: `put()` still routes through POST WITHOUT a `_method` query suffix (PUT precedent 260530-clv — WP-REST treats POST and PUT identically for EDITABLE endpoints; only DELETE needs the explicit tunnel).
   - **DRIFT** Symfony Finder scans `app_path('Domain')` for `*.php` files containing the literal `_method=DELETE`. Asserts count is EXACTLY 1 and that file ends with `WooClient.php`. Failure message instructs future devs to consume the tunnel rather than fork it.

## Cross-references

| Anchor | What it provides | Touched in this task? |
| --- | --- | --- |
| 260530-clv (commit `fb7ac18`) | PUT precedent — `use_post_for_updates` flag in `config/services.php` + `WooClient::put()` POST-routing | No code change; only mirrored symmetrically |
| 2026-06-13 incident | `brands:dedupe --delete-empty-woo-terms` returned 11 phantom failures because every Woo DELETE was 403'd at nginx; operator hand-deleted via wp-admin (~5 min lost) | Resolved by this task |
| 260613-dir | `DedupeBrandsCommand` (the PRIMARY consumer of `WooClient::delete()`) | Unchanged — caller signature preserved; test 10/10 GREEN |
| 260613-f2r | `RetagProductsOnWooCommand` + `BrandDuplicateFinder` (sibling work that landed yesterday) | Unchanged — tests 12/12 + 7/7 GREEN |

## Verification

| Suite | Result |
| --- | --- |
| **Focused**: `tests/Feature/WooClientDeleteTest.php` | **8/8 PASS** (27 assertions, 16.43s) |
| **Regression (PRIMARY consumer)**: `tests/Feature/Console/DedupeBrandsCommandTest.php` | **10/10 PASS** (62 assertions, 8.68s) |
| **Regression (sibling)**: `tests/Feature/Console/RetagProductsOnWooCommandTest.php` | **12/12 PASS** (62 assertions, 73.38s) |
| **Regression (sibling)**: `tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php` | **7/7 PASS** (36 assertions, 4.81s) |
| **Total** | **37/37 PASS (187 assertions)** |

## Out of scope (flagged, NOT addressed)

- **4 pre-existing `WooClientGetTest.php` failures** last touched in commit `fb7ac18` (260530-clv `fix(woo): route WooClient::put() through POST for WAF compatibility`). Root cause: those tests use the older 2-arg `WooClient` constructor (`new WooClient($logger, $inner)`) but the constructor was extended to 3 args (`$logger, $resolver, $inner`) when Phase 09.1 introduced `IntegrationCredentialResolver`. The new `WooClientDeleteTest.php` written today uses the correct 3-arg form (mirroring `WooClientResolverIntegrationTest.php`), so this task does NOT touch the failing file. Per spec point 6, fixing those is queued as a separate quick task.
- **No echo-loop guard for inbound webhooks** — out of scope; no Woo write paths to webhooks today.
- **No automatic shadow-mode replay refactor** — `SyncDiff` rows for DELETE now carry the POST tunnel in their `endpoint` column; the Phase 7 replay command (if/when built) will need to know that the column may end with `?_method=DELETE`. This is documented here so the replay author cross-references it.

## Deviations from plan

**None.** Plan executed exactly as written. Pre-implementation deviation: worktree HEAD's merge-base was the previous task's tip (`054a585`) rather than the plan-doc commit (`aa7413c`); the worktree-setup `git reset --hard aa7413c` brought the plan doc into the worktree (no code or test changes lost — the worktree branch is private to this agent and the previous task's commits live on `main` already).

## Operator action

After deploy, `php artisan brands:dedupe --delete-empty-woo-terms` works end-to-end without WAF 403 or hand-cleanup:

1. Pull this branch / cherry-pick `c6708d6` + `f6943b8` onto the deploy ref.
2. `php artisan config:clear` (the new `services.woo.use_post_for_deletes` config key needs the cache flushed).
3. Re-run the Phase B step from yesterday's brand-dedupe sequence: `php artisan brands:dedupe --delete-empty-woo-terms`.
4. Audit log (`/admin/integration-events?search=_method%3DDELETE`) should now show one `method=POST` row per duplicate brand term deleted, with the endpoint column carrying the literal `products/brands/{id}?_method=DELETE` suffix — grep-friendly for incident response.
5. Spot-check Woo storefront: the duplicate brand landing pages (`/product-brand/poly-1/` etc.) should now 404 (terms genuinely deleted), confirming the WAF was the only blocker.

## Self-Check: PASSED

- `config/services.php` — `use_post_for_deletes` key present (line 69 area); FOUND
- `app/Domain/Sync/Services/WooClient.php` — `_method=DELETE` literal present in `delete()`; FOUND
- `tests/Feature/WooClientDeleteTest.php` — file present (267 lines); FOUND
- Commit `c6708d6` — FOUND in `git log --all`
- Commit `f6943b8` — FOUND in `git log --all`
