---
phase: quick-260607-hxa
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Integrations/Enums/IntegrationCredentialKind.php
  - app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php
  - app/Domain/Integrations/Services/IntegrationCredentialResolver.php
  - app/Domain/ProductAutoCreate/Services/EanSearchClient.php
  - config/integrations.php
  - config/services.php
  - app/Console/Commands/BackfillMerchantFeedCommand.php
  - tests/Feature/Console/BackfillMerchantFeedCommandTest.php
  - tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php
  - CLAUDE.md
autonomous: true
requirements:
  - QUICK-260607-hxa
must_haves:
  truths:
    - "IntegrationCredentialKind::EanSearch enum case exists with requiredFields() === ['token']"
    - "EanSearchClient::lookupGtinByMpn(brand, mpn) returns the matched EAN string when EAN-search.org responds with a non-empty array; null otherwise"
    - "BackfillMerchantFeedCommand defaults to EAN-search.org as the fallback provider; setting EAN_FALLBACK_PROVIDER=icecat in .env switches back to IcecatClient without code change"
    - "Outcome bucket labels in command output now read recovered_from_ean_lookup / ean_lookup_no_match / ean_lookup_invalid_ean / ean_lookup_budget_exhausted (was: recovered_from_icecat etc.)"
    - "Pest test suite green — Icecat-bucket assertions updated to ean_lookup_*; new EanSearchClientTest passes; cases A-F still meaningful under EanSearch provider"
    - "IcecatClient stays in the codebase (still used by SourceProductImagesCommand for product image lookup)"
    - "CLAUDE.md Tech Stack documents EAN-search.org as the default GTIN backfill provider; Icecat row clarifies it is now image-only"
  artifacts:
    - path: "app/Domain/Integrations/Enums/IntegrationCredentialKind.php"
      provides: "EanSearch enum case + requiredFields/label/color/urlFields entries"
      contains: "case EanSearch = 'ean_search'"
    - path: "app/Domain/ProductAutoCreate/Services/EanSearchClient.php"
      provides: "MPN→GTIN reverse lookup against api.ean-search.org"
      exports: ["lookupGtinByMpn", "testConnection"]
    - path: "config/integrations.php"
      provides: "ean_fallback_provider config key (env-driven default 'ean_search')"
      contains: "ean_fallback_provider"
    - path: "tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php"
      provides: "6 unit cases covering brand match, brand-fallback, empty, invalid digits, HTTP error, null brand"
      min_lines: 80
  key_links:
    - from: "app/Console/Commands/BackfillMerchantFeedCommand.php"
      to: "config('integrations.ean_fallback_provider')"
      via: "constructor + per-call provider switch in backfillEan()"
      pattern: "config\\('integrations\\.ean_fallback_provider'\\)"
    - from: "app/Console/Commands/BackfillMerchantFeedCommand.php"
      to: "EanSearchClient::lookupGtinByMpn"
      via: "constructor injection (alongside IcecatClient)"
      pattern: "EanSearchClient"
    - from: "app/Domain/ProductAutoCreate/Services/EanSearchClient.php"
      to: "IntegrationCredentialResolver::for(IntegrationCredentialKind::EanSearch)"
      via: "credentials() helper (mirror IcecatClient)"
      pattern: "IntegrationCredentialKind::EanSearch"
    - from: "app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php"
      to: "EanSearchClient::testConnection"
      via: "match arm in dispatch()"
      pattern: "EanSearch"
---

<objective>
Swap Icecat for EAN-search.org as the DEFAULT EAN reverse-lookup provider in
`products:backfill-merchant-feed`. The 260607-g25 Icecat fallback ran against
116 stuck premium B2B SKUs and matched ZERO of them — Sony FW-Bravia, Panasonic
PT- projectors, PTZOptics, Roland VR, BirdDog and Vivitek aren't in Icecat's
index but ARE in EAN-search.org's. EAN-search is also ~7× cheaper per query
(€0.003 vs Icecat's ~0.2p ≈ £0.0024) and free for the first 100/day.

Purpose: lift the EAN backfill hit-rate on the high-disapproval AV/B2B segment
that today's Icecat path can't reach, while preserving operator muscle memory
around the existing CLI flags and the £2 budget cap.

Output:
  - New `IntegrationCredentialKind::EanSearch` ('ean_search', requires `token`)
  - New `EanSearchClient` service mirroring IcecatClient's lookupGtinByMpn shape
  - Config-switchable provider (`integrations.ean_fallback_provider`, default
    `'ean_search'`, flip to `'icecat'` via `EAN_FALLBACK_PROVIDER=icecat`)
  - Renamed outcome buckets in command output + updated Pest cases A-F
  - CLAUDE.md tech-stack row noting EAN-search.org is the new default GTIN
    backfill provider; Icecat row clarified as image-only
  - IcecatClient + Icecat enum case + SourceProductImagesCommand untouched

Out of scope: SourceProductImagesCommand, Icecat image-lookup code, deleting
IcecatClient, switching `--icecat-fallback` CLI flag name (alias added, original
stays for backwards compat).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@app/Domain/Integrations/Enums/IntegrationCredentialKind.php
@app/Domain/Integrations/Services/IntegrationCredentialResolver.php
@app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php
@app/Domain/ProductAutoCreate/Services/IcecatClient.php
@app/Console/Commands/BackfillMerchantFeedCommand.php
@app/Console/Concerns/NormalisesEan.php
@app/Filament/Widgets/IntegrationHealthWidget.php
@tests/Feature/Console/BackfillMerchantFeedCommandTest.php
@config/services.php
@.planning/STATE.md

<interfaces>
Key contracts the executor needs (already scouted — do NOT re-explore):

IntegrationCredentialKind enum (current shape — add EanSearch in parallel to each match):
  - cases: SupplierApi, WooRest, BitrixWebhook, AnthropicApi, OpenAiApi,
    LangfuseObservability, SupplierDb, Icecat, ImageSearch
  - requiredFields(): array  (Icecat returns ['username']; add EanSearch ['token'])
  - optionalFields(): array  (defaults to [] via `default => []` — no edit needed
    for EanSearch unless an optional field is added)
  - urlFields(): array  (add EanSearch to the empty-list arm; the API URL is
    hard-coded in the client, not a credential field)
  - label(): string  (add EanSearch => 'EAN-search.org (GTIN lookup)')
  - color(): string  (add EanSearch => 'info' — content-enrichment source, same
    palette as Icecat)

IntegrationCredentialResolver::for(IntegrationCredentialKind $kind): array
  - throws IntegrationCredentialMissingException on miss; DB row wins, then env.
  - resolveFromEnv() is a match() over kind; add EanSearch =>
      ['token' => (string) config('services.ean_search.token', '')]

IcecatClient (shape to mirror; do NOT modify):
  - public lookupGtinByMpn(?string $brand, ?string $mpn): ?string
  - public testConnection(): IntegrationTestResult
  - protected credentials(): ?array  (returns null when not configured — silent
    degrade pattern; mirror this)
  - private log(...) via IntegrationLogger with channel='icecat' — for the new
    client use channel='ean_search'
  - All failure modes return null (no throws) — caller degrades to next source

NormalisesEan trait (used by BackfillMerchantFeedCommand):
  - public normaliseEan(?string $raw): ?string  — strips whitespace, validates
    digits-only + length 8/12/13/14, returns null on garbage like 'N/A', '0',
    '—'. The command pipes both supplier_db AND fallback-client output through
    this BEFORE writing. EanSearchClient must NOT re-implement; let the command
    keep normalising at the call site.

BackfillMerchantFeedCommand current constructor:
  public function __construct(
      private readonly IntegrationCredentialResolver $resolver,
      private readonly TaxonomyResolver $taxonomy,
      private readonly IcecatClient $icecat,
  )
  → new shape adds `EanSearchClient $eanSearch` as a 4th constructor arg.

EAN-search.org API (confirmed in task_background — do NOT fetch live during dev):
  - Base URL: https://api.ean-search.org/api
  - Auth: ?token=<token> query param
  - Reverse search (MPN → GTIN):
      ?token=X&op=barcode-search&format=json&search=<urlencoded_query>&language=en
  - Response: JSON array of objects [{"ean":"5033588057222","name":"...","category":"..."}]
    OR an empty array [] on no match.
  - Errors: HTTP 4xx/5xx OR a JSON object like {"error": "..."} when the token
    is invalid — treat both as "no match" (return null, no throw).
  - Brand-match logic: API has no brand filter param. Apply client-side: pick
    the first row whose `name` field contains the brand string
    (case-insensitive). If no row matches the brand OR brand is null/empty,
    fall back to the FIRST row in the response.
</interfaces>

<stub_writes>
After enum + client land, smoke-check in tinker:

  php artisan tinker --execute "
    dump(\\App\\Domain\\Integrations\\Enums\\IntegrationCredentialKind::EanSearch->requiredFields());
    dump(\\App\\Domain\\Integrations\\Enums\\IntegrationCredentialKind::EanSearch->label());
    dump(config('integrations.ean_fallback_provider'));
  "

Expected output:
  array(1) { [0]=> string(5) "token" }
  string(28) "EAN-search.org (GTIN lookup)"
  string(10) "ean_search"
</stub_writes>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add EanSearch IntegrationCredentialKind enum case + admin wiring</name>
  <files>
    app/Domain/Integrations/Enums/IntegrationCredentialKind.php,
    app/Domain/Integrations/Services/IntegrationCredentialResolver.php,
    app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php,
    config/services.php,
    tests/Unit/Domain/Integrations/Enums/IntegrationCredentialKindEanSearchTest.php
  </files>
  <behavior>
    - Test 1: IntegrationCredentialKind::EanSearch is a valid enum case with value 'ean_search'.
    - Test 2: IntegrationCredentialKind::EanSearch->requiredFields() returns exactly ['token'].
    - Test 3: IntegrationCredentialKind::EanSearch->label() returns a non-empty string containing 'EAN-search'.
    - Test 4: IntegrationCredentialKind::EanSearch->color() returns a non-empty string (any Filament palette colour).
    - Test 5: IntegrationCredentialKind::EanSearch->urlFields() returns [] (the API base URL is hard-coded in the client, not a credential field).
    - Test 6 (resolver env fallback): With config('services.ean_search.token') set, IntegrationCredentialResolver::for(IntegrationCredentialKind::EanSearch) returns ['token' => '...'] without touching the DB.
  </behavior>
  <action>
    Add EAN-search.org as a first-class IntegrationCredentialKind so the existing
    admin Filament Credentials Resource picks it up via auto-discovery (the form
    builder reads `requiredFields()`), and so IntegrationHealthWidget surfaces a
    6th traffic-light tile automatically (it iterates `cases()` already — confirmed
    in app/Filament/Widgets/IntegrationHealthWidget.php — no widget edit needed).

    Edits:

    1. `app/Domain/Integrations/Enums/IntegrationCredentialKind.php`:
       - Add `case EanSearch = 'ean_search';` after `case Icecat = 'icecat';`
         (keep ImageSearch as the last case for diff hygiene OR insert before it;
         either way is fine — the enum cases() order has no functional dependency).
       - Add to `requiredFields()` match: `self::EanSearch => ['token'],`
       - Add to `label()` match: `self::EanSearch => 'EAN-search.org (GTIN lookup)',`
       - Add to `color()` match: `self::EanSearch => 'info',` (content-enrichment palette parity with Icecat).
       - Add EanSearch to `urlFields()` empty-list arm — append to the existing
         line: `self::AnthropicApi, self::OpenAiApi, self::SupplierDb, self::Icecat, self::ImageSearch, self::EanSearch => [],`
       - `optionalFields()` falls through to `default => []` — no edit needed.

    2. `app/Domain/Integrations/Services/IntegrationCredentialResolver.php`:
       - Add to the `resolveFromEnv()` match:
         `IntegrationCredentialKind::EanSearch => ['token' => (string) config('services.ean_search.token', '')],`

    3. `config/services.php`:
       - Add a new top-level array key `'ean_search' => ['token' => env('EAN_SEARCH_TOKEN', '')],`
         placed next to the existing `'icecat'` entry. This is the env() seam
         (env() is only ever called inside config/* per Laravel guardrails).

    4. `app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php`:
       - Add a new arm to the `dispatch()` match BEFORE the `default`:
         `IntegrationCredentialKind::EanSearch => app(\App\Domain\ProductAutoCreate\Services\EanSearchClient::class)->testConnection(),`
       - Order: insert immediately after the Icecat arm so reviewers see the
         pair side-by-side.

    5. New test file `tests/Unit/Domain/Integrations/Enums/IntegrationCredentialKindEanSearchTest.php`
       (Pest unit test, NO RefreshDatabase). Cover the six behaviour cases above.
       For Test 6, use `config(['services.ean_search.token' => 'demo-token-123'])`
       to inject env-state before calling `app(IntegrationCredentialResolver::class)->for(...)`.

    Do NOT touch IntegrationHealthWidget — it iterates `cases()` and picks up the
    new kind automatically. Do NOT touch the Filament IntegrationCredentialResource
    form builder for the same reason (it reads `requiredFields()` at render).

    NOTE: `EanSearchClient` doesn't exist yet at the moment TestIntegrationAction
    references it — that's fine, PHP only resolves the class at runtime (when
    `app()` is called inside the match arm), and Task 2 lands it before Task 3
    runs any code that hits this match arm. If running the new test in isolation
    fails because of the class reference, mark the test `skip()` until Task 2,
    OR (preferred) order the commit so TestIntegrationAction edit is part of the
    Task 2 commit instead. Acceptable to defer the TestIntegrationAction edit to
    Task 2 if it's cleaner — call it out in the SUMMARY.
  </action>
  <verify>
    <automated>./vendor/bin/pest --filter "IntegrationCredentialKindEanSearch"</automated>
  </verify>
  <done>
    - New unit test passes (6 cases green).
    - Existing Pest suite has no NEW failures (baseline per STATE.md 260607-g25 row).
    - `php artisan tinker --execute "dump(\\App\\Domain\\Integrations\\Enums\\IntegrationCredentialKind::EanSearch->requiredFields());"`
      prints `['token']`.
    - Atomic commit: `feat(integrations): add EanSearch credential kind (260607-hxa)`
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: EanSearchClient service — MPN → GTIN reverse lookup</name>
  <files>
    app/Domain/ProductAutoCreate/Services/EanSearchClient.php,
    tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php
  </files>
  <behavior>
    - Case 1 (brand match, single row): response `[{"ean":"5033588057222","name":"Panasonic PT-REZ80BEJ Projector"}]` + brand="Panasonic" → returns "5033588057222".
    - Case 2 (brand match, multi-row prefers brand-matching row): response `[{"ean":"123","name":"Sony FW-98BZ30L"},{"ean":"4711","name":"Panasonic PT-X"}]` + brand="Panasonic" → returns "4711" (the Panasonic row), NOT "123".
    - Case 3 (empty response): API returns `[]` → returns null.
    - Case 4 (digits validation rejects placeholder): response `[{"ean":"N/A","name":"foo"}]` → returns null.
    - Case 5 (HTTP error): Http::fake returns 401/403/404/500 → returns null, no throw.
    - Case 6 (null brand falls back to first row): response `[{"ean":"5033588057222","name":"foo"}]` + brand=null → returns "5033588057222".
    - Case 7 (token not configured): `IntegrationCredentialMissingException` is caught silently → returns null without issuing any HTTP call (Http::fake assert no request was sent).
    - Case 8 (testConnection ok): with valid token, Http::fake returns a non-empty JSON array → testConnection() returns IntegrationTestResult::ok(...).
  </behavior>
  <action>
    Create `app/Domain/ProductAutoCreate/Services/EanSearchClient.php`. Mirror
    IcecatClient's shape and silent-degrade-to-null pattern. Do NOT inherit
    from IcecatClient — the two clients share an interface SHAPE not state.

    Constructor: same DI as IcecatClient —
      `public function __construct(private IntegrationCredentialResolver $resolver, private IntegrationLogger $logger) {}`

    Public methods:

    1. `public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string`
       - Trim brand + mpn. If mpn is empty after trim → return null (no HTTP
         call). The EAN-search API requires the `search` param to be non-empty.
         (Note: divergence from IcecatClient which allows brand-only lookups —
         EAN-search's reverse-search needs the actual product identifier.)
       - Resolve creds via `$this->credentials()` helper (private, mirrors
         IcecatClient::credentials()). On null/missing → return null silently.
       - Build URL: `https://api.ean-search.org/api` with query params:
           token, op=barcode-search, format=json, search=$mpn, language=en
         Use `Http::timeout(10)->retry(2, 250)->get($baseUrl, $query)`. Catch
         `\Throwable` around the whole call and return null on exception (log
         via IntegrationLogger with channel='ean_search', status='failed',
         message=$e->getMessage()).
       - On non-2xx → log failure, return null.
       - Decode `$resp->json()`. If it's not an array OR it's an associative
         array with an `error` key OR the array is empty → log no_match,
         return null.
       - Brand-pick logic:
         - If $brand !== '': iterate rows; pick the first row where
           `mb_stripos((string) ($row['name'] ?? ''), $brand) !== false`.
           If none match, fall through to first-row fallback below.
         - Otherwise (or fallthrough): take the first row.
       - Extract `$picked['ean']`. Coerce to string, trim. If empty → return null.
       - Return the raw EAN string. **Do NOT call normaliseEan here** — the
         BackfillMerchantFeedCommand call site already pipes through the trait
         (mirrors how it handles IcecatClient output). Keep the client a
         pure-transport-and-decode layer.

    2. `public function testConnection(): IntegrationTestResult`
       - Probe with a known real EAN search. Suggested: search="Sony FW-50EZ20L"
         (Sony 50" Bravia — task background lists it as a stuck SKU; the test is
         only proving the token is accepted, not that this exact product is
         indexed).
       - Latency tracking via microtime, same pattern as IcecatClient::testConnection.
       - 401/403 OR a response body containing `"error"` → failed("EAN-search.org auth: ...").
       - 200 + valid JSON (array or object) → ok($latency).
       - Other → failed("EAN-search.org HTTP {status}: ...").
       - Catch `\Throwable` → failed($e->getMessage(), $latency).

    Private helpers (mirror IcecatClient):

    3. `private function credentials(): ?array`
       - `try { $c = $this->resolver->for(IntegrationCredentialKind::EanSearch); } catch (IntegrationCredentialMissingException) { return null; }`
       - `$token = trim((string) ($c['token'] ?? '')); if ($token === '') return null;`
       - Return `['token' => $token]`.

    4. `private function log(array $request, int $status, string $outcome, array $response, int $latencyMs): void`
       - Uses `$this->logger->log([...])` with channel='ean_search', operation='barcode_search.lookup',
         method='GET', endpoint=$baseUrl, request_body=$request (token redacted
         to '***'), response_body=$response, http_status=$status, latency_ms=$latencyMs,
         status=$outcome, correlation_id=Context::get('correlation_id') ?? (string) Str::uuid().
       - **Redact the token** in request_body before logging — do NOT write the
         live token to integration_events.

    5. `private function baseUrl(): string`
       - `return (string) config('services.ean_search.base_url', 'https://api.ean-search.org/api');`
       - Add a default `'base_url' => env('EAN_SEARCH_BASE_URL', 'https://api.ean-search.org/api'),`
         to the config/services.php `ean_search` block from Task 1 so it's
         override-able in tests if needed (not strictly required — tests use
         Http::fake matched by URL pattern).

    Test file `tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php`:
    - Use `Http::fake(['api.ean-search.org/*' => Http::sequence()->push(...)->push(...)])`
      OR per-test Http::fake(['url-glob' => Http::response(json, status)]). The
      assertSent assertion verifies the URL contains `op=barcode-search` and the
      search param is URL-encoded correctly.
    - Bind a stub IntegrationCredentialResolver via `app()->instance(IntegrationCredentialResolver::class, ...)`
      that returns `['token' => 'fake-token']` from `for(IntegrationCredentialKind::EanSearch)`.
    - For Case 7 (token not configured), bind a resolver stub that throws
      `IntegrationCredentialMissingException::for(...)`.
    - For Case 8 (testConnection), use Http::fake with a 200 + non-empty array
      and assert the returned IntegrationTestResult has `ok === true`.

    Do NOT add EanSearchClient as a singleton in any provider — Laravel's
    auto-resolution handles construction (both deps are container-resolved).
  </action>
  <verify>
    <automated>./vendor/bin/pest --filter EanSearchClient</automated>
  </verify>
  <done>
    - 8 behaviour cases all green.
    - `EnvUsageTest` still green (env() calls confined to config/*).
    - No new failures in the full Pest suite (baseline per STATE.md 260607-g25 row).
    - Atomic commit: `feat(ean-search): EanSearchClient with MPN→GTIN lookup (260607-hxa)`
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Swap provider in BackfillMerchantFeedCommand (default = EAN-search)</name>
  <files>
    config/integrations.php,
    app/Console/Commands/BackfillMerchantFeedCommand.php
  </files>
  <behavior>
    - With EAN_FALLBACK_PROVIDER unset, config('integrations.ean_fallback_provider') === 'ean_search'.
    - With EAN_FALLBACK_PROVIDER=icecat, config returns 'icecat'.
    - When `--icecat-fallback` is passed (default) AND provider='ean_search', the command calls EanSearchClient::lookupGtinByMpn and does NOT call IcecatClient::lookupGtinByMpn for any SKU.
    - When provider='icecat', it calls IcecatClient and does NOT call EanSearchClient.
    - Outcome bucket labels in the printed table read `recovered_from_ean_lookup`, `ean_lookup_no_match`, `ean_lookup_invalid_ean`, `ean_lookup_budget_exhausted` (not `*_icecat_*`).
    - Per-query cost moves to 0.03p (3 hundredths-of-pence) when provider is ean_search; stays at 0.2p (2 tenths-of-pence) when provider is icecat. Budget cap arithmetic remains integer.
    - Pre-flight banner reads "EAN lookup fallback ENABLED via {provider} (~{cost}/query, budget cap £{X.XX})" or "EAN lookup fallback DISABLED — supplier_db only".
    - `--ean-fallback` is accepted as an alias of `--icecat-fallback` (both no-op when fallback is default ON); `--no-icecat-fallback` continues to opt out.
  </behavior>
  <action>
    1. Create `config/integrations.php`:

       ```
       <?php
       return [
           // Quick task 260607-hxa — default GTIN-backfill provider for
           // products:backfill-merchant-feed. 'ean_search' (default) uses
           // EanSearchClient (api.ean-search.org); 'icecat' falls back to
           // IcecatClient for forensic A/B comparison.
           'ean_fallback_provider' => env('EAN_FALLBACK_PROVIDER', 'ean_search'),
       ];
       ```

    2. Edit `app/Console/Commands/BackfillMerchantFeedCommand.php`:

       a) Add EanSearchClient import:
          `use App\Domain\ProductAutoCreate\Services\EanSearchClient;`

       b) Extend constructor — add `EanSearchClient $eanSearch` as the 4th arg:
          ```
          public function __construct(
              private readonly IntegrationCredentialResolver $resolver,
              private readonly TaxonomyResolver $taxonomy,
              private readonly IcecatClient $icecat,
              private readonly EanSearchClient $eanSearch,
          ) {
              parent::__construct();
          }
          ```
          **NOTE:** this is a breaking constructor change — the Pest test file's
          anonymous subclasses (bindEanStub + bindBrandStub) will break until
          Task 4 updates them. Run Task 3 + Task 4 as a paired commit if needed,
          OR commit Task 3 with the test file still pointing at the old
          constructor and accept the failures only until Task 4 lands. Preferred:
          land Task 3 + Task 4 in the same working tree but separate commits in
          quick succession so the broken-test window is narrow.

       c) Resolve the active provider at the top of `perform()` (or where the
          per-call branch lives — choose to keep the resolution local to
          `backfillEan()` so the brand path stays untouched). Add to backfillEan
          parameters or read at the call site:
          ```
          $provider = (string) config('integrations.ean_fallback_provider', 'ean_search');
          if (! in_array($provider, ['ean_search', 'icecat'], true)) {
              $provider = 'ean_search';  // safe default on misconfig
          }
          ```

       d) Provider-aware cost arithmetic. The existing implementation uses
          tenths-of-pence (each Icecat query = 2 tenths = 0.2p) with
          `$maxIcecatSpendTenthPence = $maxIcecatSpendPence * 10;`. Replace with
          a HUNDREDTHS-of-pence scale so the 0.03p EAN-search rate is integer-
          representable:
          - Per-query cost: ean_search = 3 hundredths-of-pence (= 0.03p);
            icecat = 20 hundredths-of-pence (= 0.2p).
          - `$maxFallbackSpendHundredthPence = $maxIcecatSpendPence * 100;`
            (`--max-icecat-spend-pence=200` becomes 20000 hundredths-of-pence).
          - Gate: `if ($maxFallbackSpendHundredthPence > 0 && ($fallbackSpendHundredthPence + $perQueryCost) > $maxFallbackSpendHundredthPence) { … }`
          - Display: `$pence = number_format($fallbackSpendHundredthPence / 100, 2);`
            and `$queries = (int) ($fallbackSpendHundredthPence / $perQueryCost);`.

       e) Branch the per-SKU call:
          ```
          $candidate = $provider === 'icecat'
              ? $this->icecat->lookupGtinByMpn($brand, $mpn)
              : $this->eanSearch->lookupGtinByMpn($brand, $mpn);
          ```

       f) Rename bucket variables AND printed labels:
          - `$recoveredFromIcecat` → `$recoveredFromEanLookup`
          - `$icecatNoMatch` → `$eanLookupNoMatch`
          - `$icecatInvalidEan` → `$eanLookupInvalidEan`
          - `$icecatBudgetExhausted` → `$eanLookupBudgetExhausted`
          - Table cells: `'recovered_from_icecat'` → `'recovered_from_ean_lookup'`,
            `'icecat_no_match'` → `'ean_lookup_no_match'`,
            `'icecat_invalid_ean'` → `'ean_lookup_invalid_ean'`,
            `'icecat_budget_exhausted'` → `'ean_lookup_budget_exhausted'`.
          - Sample-row "Source" column: `'icecat'` → the active provider string
            (so the operator can see at a glance which provider matched). E.g.
            `'Source' => $provider`. For the supplier-row entries, keep
            `'supplier'`.
          - Outcome tokens in sample rows: `'would_update_from_icecat'` →
            `'would_update_from_ean_lookup'`, `'updated_from_icecat'` →
            `'updated_from_ean_lookup'`, `'icecat_no_match'` →
            `'ean_lookup_no_match'`, etc.
          - Internal `$sourceBySku[$sku] = 'icecat'` → `$sourceBySku[$sku] = 'ean_lookup'`.
            Update the live-write counters block accordingly:
            `if (($sourceBySku[$sku] ?? 'supplier') === 'ean_lookup') { $eanLookupUpdated++; } else { $supplierUpdated++; }`
          - Final info line: `"Updated {$total} product(s) with EAN: {$supplierUpdated} from supplier_db, {$eanLookupUpdated} recovered from EAN-lookup ({$provider})."`

       g) Pre-flight banner:
          ```
          if ($icecatFallback) {
              $capGbp = number_format($maxIcecatSpendPence / 100, 2);
              $perQueryGbp = $provider === 'icecat' ? '0.2p' : '0.03p';
              $this->info("EAN lookup fallback ENABLED via {$provider} (~{$perQueryGbp}/query, budget cap £{$capGbp}).");
          } else {
              $this->info('EAN lookup fallback DISABLED — supplier_db only (260607-cgd parity).');
          }
          ```

       h) Signature additions:
          - Add a new option `{--ean-fallback : Alias of --icecat-fallback. DEFAULT ON.}`
            immediately after the existing `--icecat-fallback` line. The flag is
            documentation/affordance only — the code does NOT need to read it
            because the default-on semantics rely on `--no-icecat-fallback` for
            opt-out.
          - Update the existing `--icecat-fallback` description to read
            `"DEPRECATED — alias of --ean-fallback for backwards compatibility. DEFAULT ON."`
          - Update `--max-icecat-spend-pence` description to read
            `"Cap cumulative EAN-lookup spend per run (~0.03p/query for ean_search, ~0.2p/query for icecat; default 200p = £2)."`

       i) Update class-level docblock — keep the 260607-cgd / 260607-g25
          provenance lines, add a 260607-hxa line:
          `Extended 260607-hxa — EAN-search.org replaces Icecat as the default EAN-fallback provider (config-switchable; Icecat retained for image lookup only).`

       j) **Cost-counter variable rename**: `$icecatSpendTenthPence` →
          `$fallbackSpendHundredthPence`. `$maxIcecatSpendTenthPence` →
          `$maxFallbackSpendHundredthPence`. The constructor parameter
          `$maxIcecatSpendPence` stays as the operator-facing CLI input
          (pence-level granularity matches the flag name).

    DO NOT delete IcecatClient or its constructor injection — both clients live
    side-by-side and the provider switch is config-driven, not code-driven.
  </action>
  <verify>
    <automated>./vendor/bin/pest --filter BackfillMerchantFeedCommand</automated>
  </verify>
  <done>
    - Pest tests for the command compile (run after Task 4 lands — Task 4 has
      the matching test-side rename). If the test failures from this task are
      ONLY the bucket-label asserts (`expect()->toContain('recovered_from_icecat')`)
      and the constructor-arity errors in the anonymous subclasses, that's
      expected and Task 4 closes them.
    - `php artisan tinker --execute "dump(config('integrations.ean_fallback_provider'));"`
      prints `string(10) "ean_search"`.
    - Atomic commit: `feat(products): swap Icecat for EAN-search.org as default backfill provider (260607-hxa)`
  </done>
</task>

<task type="auto">
  <name>Task 4: Update BackfillMerchantFeedCommandTest for ean_lookup rename + new constructor</name>
  <files>tests/Feature/Console/BackfillMerchantFeedCommandTest.php</files>
  <action>
    Update the existing Pest feature test to match the renamed buckets and the
    new 4-arg constructor. The test currently mocks IcecatClient and asserts on
    `recovered_from_icecat` / `icecat_no_match` etc. — migrate every assertion
    to the new provider-agnostic `ean_lookup_*` vocabulary and bind an
    EanSearchClient fake as the PRIMARY fallback mock (Icecat fake stays bound
    as a throw-on-call sentinel to prove the default-provider path doesn't
    call it).

    Concrete edits:

    1. Top-of-file imports:
       - Add `use App\Domain\ProductAutoCreate\Services\EanSearchClient;`
       - Keep `use App\Domain\ProductAutoCreate\Services\IcecatClient;` —
         needed for the throw-on-call sentinel.

    2. Header comment block: add a 260607-hxa line explaining that the test
       now binds EanSearchClient as the primary fallback fake and IcecatClient
       as the throw-on-call sentinel by default.

    3. `bindEanStub(array $eanMap, ?array $icecatGtinMap = null)` helper:
       - Rename parameter `$icecatGtinMap` → `$eanLookupGtinMap` (keep the
         same semantics: null = throw-on-call, [] = always-null, populated = lookup).
       - Replace the IcecatClient anonymous subclass with an EanSearchClient
         anonymous subclass mirroring the same shape (callCount, throw-on-call
         when null, return map[$mpn] otherwise).
       - Bind that fake to the container at `app()->instance(EanSearchClient::class, ...)`.
       - **Also** bind a throw-on-call IcecatClient fake at
         `app()->instance(IcecatClient::class, ...)` so that any accidental
         Icecat call during the default-provider tests trips a `RuntimeException`.
       - The backfill-command anonymous subclass now extends with the 4-arg
         constructor: `parent::__construct($resolver, $taxonomy, $icecat, $eanSearch);`
         — pull both fakes from the container via `app(IcecatClient::class)` and
         `app(EanSearchClient::class)` so the same fakes are reused.

    4. `bindBrandStub(...)` helper: extend the backfill-command anonymous
       subclass constructor signature with the new 4th arg. Bind an
       additional throw-on-call EanSearchClient fake (the brand path doesn't
       use it, but the constructor needs a non-null value). Mirror the existing
       throw-on-call IcecatClient sentinel.

    5. Cases A-F renames:
       - Case A label/body: `recovered_from_icecat` → `recovered_from_ean_lookup`;
         `Icecat fallback ENABLED` → `EAN lookup fallback ENABLED via ean_search`.
       - Case B: `icecat_no_match` → `ean_lookup_no_match`.
       - Case C: `icecat_invalid_ean` → `ean_lookup_invalid_ean`.
       - Case D ("supplier-first wins"): assert `app(EanSearchClient::class)->callCount === 0`
         (not Icecat). Also assert `app(IcecatClient::class)->callCount === 0`
         to prove Icecat is never called under the default provider.
       - Case E (--no-icecat-fallback / opt-out): assert
         `app(EanSearchClient::class)->callCount === 0` AND
         `app(IcecatClient::class)->callCount === 0`. Update the output assertion
         `Icecat fallback DISABLED` → `EAN lookup fallback DISABLED`.
       - Case F (budget cap): rename `icecat_budget_exhausted` → `ean_lookup_budget_exhausted`.
         The cost gate now runs at 0.03p/query for ean_search — re-tune the
         test: with `--max-icecat-spend-pence=1`, the cap is 100 hundredths-of-pence
         and each query costs 3 hundredths-of-pence; you can fit 33 queries
         (33 × 3 = 99 ≤ 100; 34th would be 102 > 100). So 6 products is too
         few to hit the cap at 1p. CHOICE: either (a) tighten the cap to
         a value that catches at boundary (e.g., test passes
         `--max-icecat-spend-pence=0` is NOT useful since 0 means "no cap"),
         OR (b) bump the product count and tune the cap. Recommend bumping
         the product count to a value where boundary tolerance is testable:
         pass `--max-icecat-spend-pence=1` (= 100 hundredths) AND create 40
         products. Expected writes ≈ 33 (boundary 31-34 acceptable), exhausted
         ≥ 6. Assert `>= 30` writes and `>= 1` exhausted. Tune to taste —
         the SPECIFIC assertion ranges are not load-bearing as long as both
         buckets are exercised.

    6. Update the four `--no-icecat-fallback` / non-fallback "4 quadrants" tests
       — they don't go through the EanSearchClient path, so no behavioural
       change needed beyond removing any stale `recovered_from_icecat` text
       assertions (there should be none in those tests, but double-check).

    7. Add ONE new test case asserting the provider-switch behaviour:

       ```
       it('Case G: EAN_FALLBACK_PROVIDER=icecat routes to IcecatClient instead', function (): void {
           config(['integrations.ean_fallback_provider' => 'icecat']);
           Product::factory()->create([
               'sku' => 'ROUTE-1',
               'status' => 'publish',
               'ean' => null,
               'brand_id' => 42,
           ]);

           bindBrandTermsForIcecat([['id' => 42, 'name' => 'Sony']]);
           // Bind an Icecat fake that DOES return a match; EanSearch fake set
           // to throw-on-call so any leak fails loudly.
           bindEanLookupStubForProvider(
               eanMap: ['route-1' => 'N/A'],
               eanSearchGtinMap: null,             // throw on call
               icecatGtinMap: ['ROUTE-1' => '4548736142680'],
           );

           Artisan::call('products:backfill-merchant-feed', [
               '--field' => 'ean',
               '--no-confirm' => true,
           ]);

           expect(Product::where('sku', 'ROUTE-1')->value('ean'))->toBe('4548736142680');
           expect(app(EanSearchClient::class)->callCount)->toBe(0);
           expect(app(IcecatClient::class)->callCount)->toBeGreaterThanOrEqual(1);
       });
       ```

       This requires a new helper `bindEanLookupStubForProvider(...)` (or
       extend `bindEanStub` with a 3rd parameter `?array $icecatGtinMap = null`
       — symmetric with the existing eanSearch one). Either pattern is fine;
       the existing `bindEanStub` rename in step 3 already accepts the EanSearch
       map, just add a 3rd parameter for the Icecat map (defaulting to null =
       throw-on-call). Reuse for Case G.

    8. Run the full test file after edits — all 7 cases (A-G) plus the original
       4 quadrant/limit tests + the 3 brand-path tests must pass.

    9. Notes for the executor:
       - The `bindEanStub` signature changes are breaking — every call site in
         the test file must be updated. Roughly 8 call sites; grep for
         `bindEanStub(` first.
       - `IntegrationLogger` is a service the IcecatClient + EanSearchClient
         constructors require — `app(IntegrationLogger::class)` works in the
         anonymous subclasses.
       - Pest factory `Product::factory()` may not exist if there's no
         `Database\Factories\ProductFactory` — but the existing tests already
         use it, so it does exist. No new factory work needed.
  </action>
  <verify>
    <automated>./vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php</automated>
  </verify>
  <done>
    - All test cases (A-G, dry-run 4 quadrants, --limit, brand path) green.
    - `./vendor/bin/pest --filter EanSearchClient` still green (Task 2 regression check).
    - No new failures in unrelated suites (baseline per STATE.md).
    - Atomic commit: `test(products): update backfill-merchant-feed test cases for ean-lookup rename (260607-hxa)`
  </done>
</task>

<task type="auto">
  <name>Task 5: CLAUDE.md tech-stack docs — EAN-search.org row + Icecat clarification</name>
  <files>CLAUDE.md</files>
  <action>
    Update CLAUDE.md to document EAN-search.org as the default GTIN backfill
    provider and clarify Icecat's role is now image-only.

    Concrete edits:

    1. The CLAUDE.md "Tech Stack" section does NOT currently have an explicit
       Icecat row — Icecat lives only in code comments and the integration
       credentials table. ADD a new sub-section after the "Supporting Libraries —
       Integration Concerns" table titled `### Product Enrichment Providers`.
       (If a similar sub-section already exists with a different name, append
       to it instead of creating a parallel section.)

       Table content:

       | Service | Purpose | Cost | Where used |
       |---|---|---|---|
       | EAN-search.org | Reverse MPN → GTIN lookup. DEFAULT EAN backfill provider for `products:backfill-merchant-feed` (config: `integrations.ean_fallback_provider='ean_search'`). | ~€0.003/query, free tier 100/day | `EanSearchClient` (260607-hxa) |
       | Icecat | Product image URLs (high-res Image + Gallery). Also available as a FALLBACK EAN provider (`integrations.ean_fallback_provider='icecat'`) for forensic A/B comparison. | ~0.2p/query | `IcecatClient` (image: `SourceProductImagesCommand`; opt-in EAN backfill: 260607-g25) |
       | Serper (Web Image Search) | Fallback image source when Icecat doesn't index the SKU. | Per-search subscription | `WebImageSearchClient` |

    2. If the file has a "Stack by Problem (Requirement Mapping)" section, add
       a new sub-bullet under the "Product enrichment" requirement (or create
       it if it doesn't exist):

       ### N. EAN reverse lookup for Google Merchant Center backfill
       - **Primary:** EAN-search.org via `EanSearchClient` (default). Free
         tier 100/day, paid €30/10k queries (~€0.003/query). Coverage skews
         strongly toward industrial / AV B2B SKUs (Sony FW-Bravia, Panasonic
         PT-, PTZOptics, Roland, BirdDog, Vivitek) where Icecat returns zero
         hits.
       - **Fallback:** Icecat via `IcecatClient` — kept for A/B comparison and
         in case EAN-search downtime. Set `EAN_FALLBACK_PROVIDER=icecat`.
       - **Cost cap:** `products:backfill-merchant-feed --max-icecat-spend-pence`
         (still named with `-icecat-` for operator muscle memory; actually caps
         whichever provider is active).

    3. Do NOT edit any existing rows beyond what's listed above. The Filament /
       Horizon / Bitrix etc. stack stays exactly as-is.

    4. If unsure about exact heading levels (### vs ##), match the surrounding
       structure of the file you're editing. The point is that an LLM reading
       CLAUDE.md for tech-stack context sees both rows side-by-side.
  </action>
  <verify>
    <automated>node -e "const c = require('fs').readFileSync('CLAUDE.md','utf8'); if (!c.includes('EAN-search.org')) { process.exit(1) }"</automated>
  </verify>
  <done>
    - CLAUDE.md contains an EAN-search.org row in the tech-stack tables.
    - Icecat row clarifies image-primary, EAN-fallback-opt-in.
    - Atomic commit: `docs(claude-md): add EAN-search.org as default GTIN backfill provider (260607-hxa)`
  </done>
</task>

<task type="auto">
  <name>Task 6: Verify — focused + full Pest, tinker smoke, post-deploy notes</name>
  <files></files>
  <action>
    No code changes. Verification only.

    Run the verification matrix:

    1. Focused green:
       - `./vendor/bin/pest --filter "IntegrationCredentialKindEanSearch"`
       - `./vendor/bin/pest --filter EanSearchClient`
       - `./vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php`
       - `./vendor/bin/pest --filter EnvUsageTest`
       - `./vendor/bin/pest --filter AutoCreatedPredicateTest` (regression — confirms
         no spillover into the product-creation invariants from the 260607-g25 line).

    2. Full Pest suite: `./vendor/bin/pest`
       Baseline (per STATE.md 260607-g25 row): ~1,881 passed / 219 skipped / 3 failed
       pre-existing.
       Acceptance: 0 NEW failures vs baseline. If a previously-passing test now
       fails because of a Task 1-5 change, fix it before closing the SUMMARY.

    3. Tinker smoke:
       ```
       php artisan tinker --execute "
         echo PHP_EOL;
         echo 'Enum case: ' . \App\Domain\Integrations\Enums\IntegrationCredentialKind::EanSearch->value . PHP_EOL;
         echo 'Required: ' . implode(',', \App\Domain\Integrations\Enums\IntegrationCredentialKind::EanSearch->requiredFields()) . PHP_EOL;
         echo 'Label: ' . \App\Domain\Integrations\Enums\IntegrationCredentialKind::EanSearch->label() . PHP_EOL;
         echo 'Color: ' . \App\Domain\Integrations\Enums\IntegrationCredentialKind::EanSearch->color() . PHP_EOL;
         echo 'Provider: ' . config('integrations.ean_fallback_provider') . PHP_EOL;
       "
       ```
       Expected:
         Enum case: ean_search
         Required: token
         Label: EAN-search.org (GTIN lookup)
         Color: info
         Provider: ean_search

    4. Operator post-deploy notes (record in the SUMMARY, do NOT do them as part
       of this task — these are for the human after deploy):
       - Sign up at https://www.ean-search.org (free tier 100/day or paid €30/10k).
       - Get API token from the EAN-search dashboard.
       - Open Filament: `/admin/integration-credentials` → New.
       - Pick kind = `EAN-search.org (GTIN lookup)`.
       - Paste token into the `token` field.
       - Save → click "Test connection" → expect green "Connection OK".
       - Without the token configured, EAN-search calls return null gracefully
         and the backfill command continues to write only supplier-derived EANs
         (no crash, no incorrect data).
       - Optional A/B: to compare providers on the same SKU set, run with
         `EAN_FALLBACK_PROVIDER=icecat php artisan products:backfill-merchant-feed --field=ean --dry-run --limit=20`
         then re-run with `EAN_FALLBACK_PROVIDER=ean_search` and diff the
         outcome tables.

    5. STATE.md update (in the SUMMARY workflow, not here): add a 260607-hxa
       row noting "EAN-search.org swap shipped; Icecat retained for image
       lookup; CLI flag `--icecat-fallback` kept as alias."

    NO COMMIT for this task.
  </action>
  <verify>
    <automated>./vendor/bin/pest --filter "EanSearch|BackfillMerchantFeed|EnvUsage|AutoCreatedPredicate"</automated>
  </verify>
  <done>
    - Focused tests all green.
    - Full Pest baseline holds (no new failures).
    - Tinker smoke confirms enum + config wiring.
    - Operator post-deploy steps documented in the SUMMARY for the human.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| BackfillMerchantFeedCommand → api.ean-search.org | Outbound HTTP — token in query string, supplier SKU strings in `search` param. Untrusted bytes in the JSON response cross BACK into the app and are written to `products.ean` after passing through `NormalisesEan`. |
| EanSearchClient → IntegrationCredentialResolver → DB / .env | Token resolution boundary. DB-stored payloads are encrypted via `payload_encrypted` cast; env fallback reads from `.env`. |
| Filament admin form → integration_credentials table | Operator pastes the EAN-search.org token in the UI; written through the `encrypted:array` payload cast. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-260607hxa-01 | Information Disclosure | EanSearchClient::log() request body | mitigate | Redact `token` field to `'***'` before passing the request payload to IntegrationLogger so live tokens never land in `integration_events`. Codified in Task 2 action (private log() helper). |
| T-260607hxa-02 | Tampering | api.ean-search.org response → `products.ean` | mitigate | Every EAN-search-returned string is piped through `NormalisesEan::normaliseEan` at the BackfillMerchantFeedCommand call site BEFORE the DB write — same gate that protects supplier_db and Icecat output today. Placeholder values ('N/A', '0', '—', mixed-letter junk) classify as `ean_lookup_invalid_ean` and are NOT written. |
| T-260607hxa-03 | Denial of Service | Unbounded paid-API spend | mitigate | Per-run cost cap honoured via `$maxFallbackSpendHundredthPence`. Default cap £2 (200p). EAN-search rate is 0.03p/query so even an exhausted cap = ~6,666 queries — well below the free-tier 100/day if operator runs once per day. |
| T-260607hxa-04 | Tampering | Provider-switch config | accept | `EAN_FALLBACK_PROVIDER` env var is operator-facing; misconfiguration falls back to `'ean_search'` (safe default coded in Task 3 step 2c). No attack surface beyond the existing `.env` trust boundary. |
| T-260607hxa-05 | Spoofing | Forged EAN-search.org response | accept | HTTPS enforced by `Http::` defaults; certificate validation on. The api.ean-search.org domain is well-known and signed. Risk is low for a read-only enrichment call where the worst outcome is a wrong EAN (caught at the next Google Merchant Center sync feedback loop). |
| T-260607hxa-SC | Tampering | Package installs | mitigate | No new Composer packages added — EanSearchClient uses the built-in `Http::` facade + Laravel HTTP client. No package-legitimacy gate needed. |
</threat_model>

<verification>
**Behavioural verification:**

- `IntegrationCredentialKind::EanSearch` enum case is reachable, has
  `requiredFields() === ['token']`, label contains 'EAN-search', color is a
  valid Filament palette colour.
- `EanSearchClient::lookupGtinByMpn`:
  - Brand-match wins over first-row when brand provided + multiple rows.
  - Null brand falls back to first row.
  - Empty `[]` → null.
  - Invalid digits / placeholder → null (validated downstream in normaliseEan
    OR — for the unit test — invalid digits in the EAN field of a returned
    row are still returned as a raw string; the command validates).
  - HTTP 4xx/5xx → null, no throw.
  - Missing token → null silently, no HTTP call issued.
- `BackfillMerchantFeedCommand`:
  - Default run (no env override) calls EanSearchClient, not IcecatClient.
  - `EAN_FALLBACK_PROVIDER=icecat` calls IcecatClient, not EanSearchClient.
  - Outcome table labels read `ean_lookup_*` (not `icecat_*`).
  - Pre-flight banner says "EAN lookup fallback ENABLED via ean_search".
  - `--no-icecat-fallback` still opts out (backwards compat).
  - Cost cap arithmetic correct at 0.03p/query (verify by inspecting the
    printed "Icecat spend this run: Xp (Y queries)" line — note: rename to
    "EAN lookup spend this run: Xp (Y queries)" in Task 3 step g).
- `TestIntegrationAction::dispatch` routes EanSearch kind to EanSearchClient.
- `IntegrationHealthWidget` shows a 6th tile for EanSearch (visual — operator
  confirms post-deploy at `/admin`).

**Test verification:**

- `./vendor/bin/pest --filter "IntegrationCredentialKindEanSearch|EanSearchClient|BackfillMerchantFeedCommand|EnvUsageTest|AutoCreatedPredicateTest"` → all green.
- `./vendor/bin/pest` full suite → no NEW failures vs baseline (1,881 / 219 / 3).

**Doc verification:**

- CLAUDE.md grep for "EAN-search.org" → at least one match.
- CLAUDE.md Icecat row clarifies image-primary / EAN-fallback-opt-in.
</verification>

<success_criteria>
- [ ] `IntegrationCredentialKind::EanSearch` enum case lands with all four
      methods (requiredFields/label/color/urlFields) handling the new case.
- [ ] `IntegrationCredentialResolver::resolveFromEnv()` maps EanSearch to
      `['token' => config('services.ean_search.token', '')]`.
- [ ] `TestIntegrationAction::dispatch()` routes EanSearch to
      `EanSearchClient::testConnection()`.
- [ ] `EanSearchClient` exists with `lookupGtinByMpn` + `testConnection`;
      mirrors IcecatClient's silent-degrade-to-null pattern; brand-match logic
      implemented; token redaction in logs.
- [ ] `config/integrations.php` exists with `ean_fallback_provider` default
      `'ean_search'`, env-overridable via `EAN_FALLBACK_PROVIDER`.
- [ ] `BackfillMerchantFeedCommand` accepts both clients via DI, switches on
      `config('integrations.ean_fallback_provider')` at the per-SKU call site.
- [ ] Outcome bucket labels renamed to `ean_lookup_*` in both the printed
      tables and the sample rows.
- [ ] Cost arithmetic uses hundredths-of-pence scale; 0.03p/query for
      ean_search, 0.2p/query for icecat; `--max-icecat-spend-pence` semantics
      preserved (caps whichever provider is active).
- [ ] `--ean-fallback` alias added; `--no-icecat-fallback` opt-out preserved.
- [ ] Existing Pest cases A-F migrate to `ean_lookup_*` and bind
      EanSearchClient as the primary fake + IcecatClient as throw-on-call sentinel.
- [ ] New Case G exercises the `EAN_FALLBACK_PROVIDER=icecat` provider switch.
- [ ] CLAUDE.md documents EAN-search.org as the default GTIN backfill provider;
      Icecat row clarified as image-primary.
- [ ] IcecatClient + `IntegrationCredentialKind::Icecat` + SourceProductImagesCommand
      UNTOUCHED.
- [ ] 5 atomic commits land (Tasks 1, 2, 3, 4, 5); Task 6 is verification only.
- [ ] No NEW test failures vs the STATE.md 260607-g25 baseline.
</success_criteria>

<output>
Create `.planning/quick/260607-hxa-swap-icecat-for-ean-search-org-as-the-de/260607-hxa-SUMMARY.md` when done.

The SUMMARY must record:
1. Files actually edited (paths + line counts).
2. Final test status: focused green, full Pest pass/fail/skip counts vs baseline.
3. The 5 atomic commit SHAs.
4. **Operator post-deploy checklist** (sign up at ean-search.org, paste token in
   `/admin/integration-credentials`, click Test connection).
5. **A/B comparison runbook** (the `EAN_FALLBACK_PROVIDER=icecat` flip for
   forensic comparison on the 116 stuck SKUs from 260607-g25).
6. The STATE.md `260607-hxa` row append text.
</output>
