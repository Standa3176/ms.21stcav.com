---
phase: quick-260613-plo
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - config/services.php
  - app/Domain/Sync/Services/WooClient.php
  - tests/Feature/WooClientDeleteTest.php
autonomous: true
requirements:
  - "260613-plo-A: tunneled POST routing on default config"
  - "260613-plo-B: explicit opt-out preserves strict DELETE"
  - "260613-plo-C: query-string-aware separator (& vs ?)"
  - "260613-plo-D: empty payload default does not crash"
  - "260613-plo-E: audit log records method=POST + endpoint?_method=DELETE"
  - "260613-plo-F: shadow-mode gate writes sync_diff with POST+_method=DELETE"
  - "260613-plo-G: PUT + POST paths untouched (260530-clv regression guard)"
  - "260613-plo-DRIFT: _method=DELETE literal appears exactly once in app/Domain/"

must_haves:
  truths:
    - "WooClient::delete() under default config issues HTTP POST with ?_method=DELETE appended"
    - "WooClient::delete() under use_post_for_deletes=false issues strict HTTP DELETE (no _method suffix)"
    - "Endpoint with existing query string uses & separator (not ?) when appending _method=DELETE"
    - "DedupeBrandsCommand --delete-empty-woo-terms path runs end-to-end without hitting the WAF DELETE block"
    - "PUT path (260530-clv) and POST path are unchanged after this edit"
    - "Audit logs (integration_events) record method=POST + endpoint including ?_method=DELETE so operators can grep for tunneled deletes"
    - "Shadow-mode (write_enabled=false) records sync_diffs with method=POST + endpoint including ?_method=DELETE — same path as live"
  artifacts:
    - path: "config/services.php"
      provides: "services.woo.use_post_for_deletes config key, default true, adjacent to use_post_for_updates"
      contains: "use_post_for_deletes"
    - path: "app/Domain/Sync/Services/WooClient.php"
      provides: "delete() rewritten to mirror put() POST-routing pattern, with WAF + 2026-06-13 incident comment block referencing 260530-clv precedent"
      contains: "_method=DELETE"
    - path: "tests/Feature/WooClientDeleteTest.php"
      provides: "Pest cases A-G plus DRIFT grep guard"
      min_lines: 200
  key_links:
    - from: "WooClient::delete()"
      to: "writeOrShadow('POST', endpoint . '?_method=DELETE', payload)"
      via: "config('services.woo.use_post_for_deletes', true) branch"
      pattern: "writeOrShadow\\('POST'.*_method=DELETE"
    - from: "DedupeBrandsCommand::handle (Phase B)"
      to: "WooClient::delete('products/brands/{id}', ['force' => true])"
      via: "unchanged caller — signature preserved"
      pattern: "\\$this->woo->delete"
    - from: "tests/Feature/WooClientDeleteTest.php DRIFT case"
      to: "app/Domain/ grep for '_method=DELETE'"
      via: "Finder / glob across app/Domain/**/*.php counted via str_contains"
      pattern: "_method=DELETE"
---

<objective>
Mirror the 260530-clv `use_post_for_updates` PUT→POST WAF tunnel onto `WooClient::delete()` so `brands:dedupe --delete-empty-woo-terms` (and every future delete command) stops getting 403'd by the CWP/Imunify/mod_security default block on HTTP DELETE to `/wp-json/*`.

Purpose: Today's incident — 11 brand DELETEs returned "JSON ERROR: Syntax error" because nginx returned HTML 403 (WAF rule). Operator hand-deleted via wp-admin (~5 min lost). The PUT precedent already exists in this same file (line 146); this task copies the pattern symmetrically to delete().

Output:
- New config key `services.woo.use_post_for_deletes` (default true)
- `WooClient::delete()` rewritten to POST-route via `?_method=DELETE` when flag is on
- 7 Pest cases (A-G) + 1 drift-prevention grep test, all GREEN
- Regression-clean PUT path, POST path, DedupeBrandsCommandTest, RetagProductsOnWooCommandTest, BrandDuplicateFinderTest
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

# THE file being modified — note the PUT precedent at line 146 (mirror this exactly)
@app/Domain/Sync/Services/WooClient.php

# Where the new config key goes — adjacent to existing use_post_for_updates at line 67
@config/services.php

# Pest test rig pattern (Mockery + AutomatticClient stub + WooResponse for status codes)
@tests/Feature/WooClientGetTest.php

# Primary consumer — preserves signature so this caller is unchanged
@app/Console/Commands/DedupeBrandsCommand.php

<interfaces>
<!-- Key contracts the executor needs. Extracted from codebase. -->
<!-- Use these directly — no exploration needed. -->

From app/Domain/Sync/Services/WooClient.php (the PRECEDENT to mirror):

```php
// Line 146 — the pattern to copy
public function put(string $endpoint, array $payload): array
{
    // WAF compatibility: many WP hosts block HTTP PUT to /wp-json/* at the
    // Apache layer (CWP/Imunify/mod_security defaults) while letting POST
    // through. WP-REST treats POST and PUT identically for resource-update
    // endpoints (WP_REST_Server::EDITABLE), so we route PUT through POST
    // when services.woo.use_post_for_updates is true (default).
    $method = config('services.woo.use_post_for_updates', true) ? 'POST' : 'PUT';

    return $this->writeOrShadow($method, $endpoint, $payload);
}

// Line 168 — the trivial pass-through being replaced
public function delete(string $endpoint, array $payload = []): array
{
    return $this->writeOrShadow('DELETE', $endpoint, $payload);
}

// Line 281 — dispatchWrite() — POST arm already routes via $sdk->post().
// The _method=DELETE query string flows through the endpoint argument
// into the SDK's URL builder transparently — no dispatchWrite changes.
match ($method) {
    'POST' => $sdk->post($endpoint, $payload),
    'PUT' => $sdk->put($endpoint, $payload),
    'DELETE' => $sdk->delete($endpoint, $payload),
    'PATCH' => $sdk->http->request($endpoint, 'PATCH', $payload),
    ...
};
```

From config/services.php (line 60-67) — where the new key goes:

```php
'woo' => [
    ...
    // WAF compatibility — many WP hosts (CWP, Imunify360, generic mod_security
    // configs) block HTTP PUT to /wp-json/* at the Apache layer while letting
    // POST through. ...
    'use_post_for_updates' => env('WOO_USE_POST_FOR_UPDATES', true),

    // ← NEW KEY GOES IMMEDIATELY HERE for grep-discoverability
    ...
],
```

From tests/Feature/WooClientGetTest.php — test rig pattern:

```php
// Mock pattern — anonymous-class subclass not needed; Mockery on AutomatticClient works
$mockInner = Mockery::mock(AutomatticClient::class);
$mockHttp = Mockery::mock();
$mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
$mockInner->http = $mockHttp;
$mockInner->shouldReceive('post')->once()->with($expectedEndpoint, $expectedPayload)->andReturn([]);

$client = new WooClient(app(IntegrationLogger::class), $mockInner);
$client->delete('products/brands/42', ['force' => true]);
```

From app/Console/Commands/DedupeBrandsCommand.php — caller signature (DO NOT BREAK):
```php
$this->woo->delete("products/brands/{$id}", ['force' => true])
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add use_post_for_deletes config key + rewrite WooClient::delete() with POST tunnel + 8 Pest cases</name>
  <files>
    config/services.php,
    app/Domain/Sync/Services/WooClient.php,
    tests/Feature/WooClientDeleteTest.php
  </files>
  <behavior>
    Pest cases (all 8 must be GREEN in `tests/Feature/WooClientDeleteTest.php`):

    - (A) Default config (use_post_for_deletes=true, write_enabled=true): `$client->delete('products/brands/42', ['force' => true])`
          → AutomatticClient::post() receives endpoint='products/brands/42?_method=DELETE', payload=['force' => true]
          → AutomatticClient::delete() is NOT called.
    - (B) Opt-out (config(['services.woo.use_post_for_deletes' => false]), write_enabled=true): same call
          → AutomatticClient::delete() receives endpoint='products/brands/42' (NO ?_method suffix), payload=['force' => true]
          → AutomatticClient::post() is NOT called.
    - (C) Endpoint with existing query string: `$client->delete('products/123?force=true')`
          → AutomatticClient::post() receives endpoint='products/123?force=true&_method=DELETE' (correct `&` separator).
    - (D) Empty payload default: `$client->delete('products/brands/42')` (no second arg)
          → AutomatticClient::post() receives endpoint='products/brands/42?_method=DELETE', payload=[] (no crash, no spurious `force`).
    - (E) Audit log content (use_post_for_deletes=true, write_enabled=true): one successful delete
          → integration_events row has channel='woo', method='POST', endpoint='products/brands/42?_method=DELETE', status='success'.
    - (F) Shadow-mode gate (write_enabled=false, use_post_for_deletes=true): one delete
          → AutomatticClient is NEVER called (no Mockery expectation on post/delete)
          → sync_diffs row created with method='POST', endpoint='products/brands/42?_method=DELETE'.
    - (G) Backward-compat regression guard (use_post_for_updates=true default, write_enabled=true): one put() call to 'products/42' with ['regular_price' => '99.00']
          → AutomatticClient::post() receives endpoint='products/42' (NO ?_method suffix — PUT precedent does NOT use the query-string tunnel; it relies on POST routing through writeOrShadow + dispatchWrite, exactly as today).
          → Confirms today's edit did not regress 260530-clv. (Reads the file as the source of truth — PUT precedent does not put `?_method=PUT` on the URL because WP-REST treats POST and PUT as the same handler for EDITABLE endpoints. Only DELETE needs the explicit _method tunnel.)
    - (DRIFT) Architecture grep: scan all `app/Domain/**/*.php` files, count files containing the literal string `_method=DELETE`. Assert count is exactly 1 and that the matching file is `app/Domain/Sync/Services/WooClient.php`. Any second site means someone forked the WAF workaround — fail loud.
  </behavior>
  <action>
    Step 1 — config/services.php: locate the `'woo' => [...]` array and the existing `'use_post_for_updates' => env('WOO_USE_POST_FOR_UPDATES', true),` line (currently at line 67). Insert a new key DIRECTLY BELOW it (still inside the `'woo'` array, before the `'storefront_url'` entry) with the same shape, named `use_post_for_deletes`, default `env('WOO_USE_POST_FOR_DELETES', true)`. Adjacent placement is load-bearing for grep-discoverability (operators searching for `use_post_for_updates` must find the delete twin in the same screen). Add a brief comment block above the new key that (a) references the WAF block on HTTP DELETE, (b) cross-references the 2026-06-13 incident, (c) mirrors the tone of the existing use_post_for_updates comment. Set `WOO_USE_POST_FOR_DELETES=false` as the documented opt-out env var.

    Step 2 — app/Domain/Sync/Services/WooClient.php: REPLACE the existing `public function delete(string $endpoint, array $payload = []): array` at line 168-171 with the POST-routing variant. Important constraints:
      • Keep the signature byte-identical: `public function delete(string $endpoint, array $payload = []): array` — DedupeBrandsCommand calls `$this->woo->delete("products/brands/{$id}", ['force' => true])` and must continue working without code changes.
      • Comment block ABOVE the method body MUST reference both anchors (per spec point 3): "Mirrors the 260530-clv `use_post_for_updates` PUT precedent (see put() above)." AND "2026-06-13 incident — without this, brands:dedupe --delete-empty-woo-terms returns 403 from nginx for every term and operator must hand-delete via wp-admin."
      • When `config('services.woo.use_post_for_deletes', true)` is truthy: compute `$separator = str_contains($endpoint, '?') ? '&' : '?';` then call `$this->writeOrShadow('POST', $endpoint . $separator . '_method=DELETE', $payload)`. The on-the-wire method becomes POST; the endpoint string carries `?_method=DELETE` (or `&_method=DELETE` when a query string already exists) so audit-log grep works.
      • When falsy: `$this->writeOrShadow('DELETE', $endpoint, $payload)` — original behaviour byte-for-byte.
      • Do NOT touch `dispatchWrite()` — the existing 'POST' arm already maps to `$sdk->post($endpoint, $payload)` and the SDK forwards `?_method=DELETE` as part of the URL via Guzzle's UriResolver naturally. Adding a 'POST_DELETE_TUNNEL' arm or similar is forbidden — keeps the match() expression unchanged so the existing 12 PUT-tunnel tests stay green.
      • Do NOT touch `put()` or `post()` or any other write method.

    Step 3 — tests/Feature/WooClientDeleteTest.php (NEW file): mirror the setup pattern from `tests/Feature/WooClientGetTest.php` (Mockery on AutomatticClient, WooResponse for status codes, Context::add('correlation_id', Str::uuid()) in beforeEach). Implement all 8 cases above (A-G + DRIFT). Notes:
      • Use Pest's `it(...)` syntax (the existing file uses this idiom).
      • For cases A, C, D, E, G: set `config(['services.woo.write_enabled' => true])` in beforeEach OR per-test so writeOrShadow takes the live path.
      • For case B: set `config(['services.woo.use_post_for_deletes' => false, 'services.woo.write_enabled' => true])`.
      • For case F: set `config(['services.woo.write_enabled' => false])` ONLY (use_post_for_deletes defaults to true).
      • For case G: set `config(['services.woo.use_post_for_updates' => true, 'services.woo.write_enabled' => true])` and stub `$mockInner->shouldReceive('post')->once()->with('products/42', ['regular_price' => '99.00'])` — assert PUT continues to route through POST without any `_method` query parameter.
      • For case E: query the `integration_events` table after the call and assert method+endpoint+status as described in <behavior>.
      • For case F: query the `sync_diffs` table after the call. The SyncDiff model lives at `App\Domain\Sync\Models\SyncDiff` — see writeOrShadow() recordDiff() for fields.
      • For case DRIFT (top-level expect() or its own `it(...)`): use Symfony Finder OR `glob_recursive`-like pattern over `app_path('Domain')` to enumerate `*.php` files, filter to ones whose contents contain `_method=DELETE`, assert `count === 1` and that the matching file path ends with `Domain/Sync/Services/WooClient.php`. If the test infrastructure does not have RefreshDatabase or its equivalent, add `uses(RefreshDatabase::class)->in(__FILE__);` at the top — match WooClientGetTest's posture (look at the existing file's top-of-file setup).
      • Use the same `use` imports as WooClientGetTest.php (WooClient, IntegrationEvent, IntegrationLogger, AutomatticClient, Context, Str, plus `App\Domain\Sync\Models\SyncDiff` for case F).
      • Do NOT modify WooClientGetTest.php (the 4 pre-existing failures from 260530-clv `fb7ac18` are explicitly out of scope per spec point 6 — flag in SUMMARY).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/WooClientDeleteTest.php tests/Feature/Console/DedupeBrandsCommandTest.php tests/Feature/Console/RetagProductsOnWooCommandTest.php tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php</automated>
  </verify>
  <done>
    • config/services.php has `use_post_for_deletes` adjacent to `use_post_for_updates`, default true.
    • WooClient::delete() routes POST + `?_method=DELETE` under default config; routes strict DELETE when flag is false.
    • Comment block above delete() references both 260530-clv PUT precedent AND 2026-06-13 incident.
    • `vendor/bin/pest tests/Feature/WooClientDeleteTest.php` → 8/8 GREEN.
    • `vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php` → 10/10 GREEN (PRIMARY CONSUMER acceptance gate).
    • `vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php` → 12/12 GREEN (sibling, unchanged from 260613-ogv).
    • `vendor/bin/pest tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php` → 7/7 GREEN (sibling, unchanged from 260613-o33).
    • Pre-existing 4 failures in WooClientGetTest.php are noted in SUMMARY as OUT OF SCOPE.
  </done>
</task>

</tasks>

<verification>
- Focused: `vendor/bin/pest tests/Feature/WooClientDeleteTest.php` → 8/8 GREEN.
- Regression (PRIMARY): `vendor/bin/pest tests/Feature/Console/DedupeBrandsCommandTest.php` → 10/10 GREEN.
- Regression (sibling): `vendor/bin/pest tests/Feature/Console/RetagProductsOnWooCommandTest.php` → 12/12 GREEN.
- Regression (sibling): `vendor/bin/pest tests/Unit/Domain/Sync/Services/BrandDuplicateFinderTest.php` → 7/7 GREEN.
- Drift guard inside WooClientDeleteTest: the literal `_method=DELETE` appears in EXACTLY one file under `app/Domain/`.
- Pre-existing 4 WooClientGetTest.php failures (260530-clv `fb7ac18`) are flagged in SUMMARY but NOT fixed in this task.
</verification>

<success_criteria>
- `WooClient::delete()` tunnels through POST with `?_method=DELETE` under default config — WAF block resolved.
- `WooClient::delete()` signature unchanged; DedupeBrandsCommand caller works without edits.
- PUT path (260530-clv) and POST path are byte-identical to pre-change behaviour (case G proves it).
- All 4 verify gates GREEN as listed above.
- Operator can now run `php artisan brands:dedupe --delete-empty-woo-terms` end-to-end without WAF 403 / hand-cleanup.
</success_criteria>

<output>
Create `.planning/quick/260613-plo-add-wooclient-delete-post-routing-mirror/260613-plo-SUMMARY.md` when done.

SUMMARY must include:
- What shipped (config key + delete() rewrite + 8 Pest cases).
- Cross-references: 260530-clv PUT precedent (commit fb7ac18), 2026-06-13 brands:dedupe incident.
- Out-of-scope flag: 4 pre-existing WooClientGetTest.php failures (260530-clv `fb7ac18`) are NOT addressed in this task.
- Operator action: `php artisan brands:dedupe --delete-empty-woo-terms` now works end-to-end.
</output>
