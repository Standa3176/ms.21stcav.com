# 260712-gaj — HOTFIX: GA4 service_account_json credential field (Textarea + size) — SUMMARY

**Status:** COMPLETE. TDD, 3 atomic commits. No push, no deploy (per plan).

## Root cause (confirmed)
`IntegrationCredentialResource` rendered EVERY `requiredFields()` entry as a single-line
`TextInput->maxLength(2048)`. A GA4 service-account key is **multi-line JSON ~2.3–2.6 KB** (the
`private_key` PEM block alone is ~1.7 KB). Two compounding failures:
1. Multi-line paste into a single-line `<input>` keeps only the first line (`{`) → invalid JSON.
2. Even single-line, `maxLength(2048)` truncates a ~2.5 KB key → invalid JSON.

Either produced the operator error *"service_account_json is not valid JSON."* The model cast
(`encrypted:array`), the `text` storage column, and the resolver were all fine — the bug was purely
in the **form field type/size**.

## What changed (surgical)
- **Task 1** — `IntegrationCredentialKind::textareaFields()`: `GoogleAnalytics => ['service_account_json']`,
  `default => []`. Mirrors the `optionalFields()`/`urlFields()` shape.
- **Task 2** — `IntegrationCredentialResource`: extracted the field loop into a testable static
  `payloadFieldComponents(IntegrationCredentialKind): array`. `textareaFields()` entries now render as
  `Filament\Forms\Components\Textarea` (`rows(12)`, `maxLength(16384)`, `required` on create,
  `dehydrated(filled)`, helper/placeholder text) — **branched BEFORE** the url/password logic so the
  Textarea never gets `->url()`/`->password()`. All OTHER fields render exactly as before (single-line
  `TextInput`, password/url unchanged).
- **Task 3** — round-trip proof test (see below).

## Column-widen migration needed?
**No.** `integration_credentials.payload_encrypted` is already a `text` column
(`2026_05_02_160000_create_integration_credentials_table.php`). MySQL `TEXT` holds 64 KB; SQLite `TEXT`
is unbounded. The encrypted + base64 blob of a ~2.5 KB key (~1.4× inflation ≈ 3.5 KB) fits comfortably.
A test asserts `Schema::getColumnType(...)==='text'` and that the raw stored ciphertext is longer than
the >2 KB plaintext. **No migration created.**

## Round-trip test result
`tests/Feature/Integrations/GoogleAnalyticsCredentialRoundTripTest.php` — a realistic **multi-line
~2.5 KB** fake-but-structurally-valid service-account JSON (PEM `private_key` spanning 26 newlines,
`strlen > 2048`, `\n` count > 10) saved through `IntegrationCredential::create()` resolves back
**byte-intact** via `IntegrationCredentialResolver::for(GoogleAnalytics)`, and `json_decode()` succeeds
(`json_last_error === JSON_ERROR_NONE`) with `type` / `project_id` / `client_email` / multi-line
`private_key` all surviving. **PASS (3 tests, 14 assertions).** This proves a real >2 KB multi-line key
now survives end-to-end.

## Verify results
- `pest` (enum + resource form + round-trip): **GREEN** — 10 new tests.
- Wider Integrations suite (`tests/Feature/Integrations` + `tests/Unit/Domain/Integrations`):
  **105 passed, 1 skipped (pre-existing Livewire-boot skip), 414 assertions — no regression.**
- `php artisan route:list --path=admin`: **exit 0.**
- `pint` on all 5 touched files: **pass.**
- `vendor/bin/deptrac analyse`: **0 violations.**

## Commits
- `3b70f24` — feat(260712-gaj): add textareaFields() to IntegrationCredentialKind
- `fe62b40` — fix(260712-gaj): render service_account_json as a Textarea in the credential form
- `db34140` — test(260712-gaj): prove >2KB multi-line GA4 key round-trips byte-intact

## Redeploy note
Code-only change (no migration). On prod: `git pull` then rebuild caches via `deploy.sh`
(config/route/view cache) so the updated Filament resource is picked up. No `migrate` needed (column
was already `text`). No queue/Horizon restart required for the form change.

## Deviations from Plan
- **[Rule 3 — testability refactor]** Extracted the inline field loop into a public static
  `payloadFieldComponents()` so the plan's required component-level assertion ("assert the
  `payload_encrypted.service_account_json` component is a `Textarea`") is testable without a full
  Filament panel boot (the existing resource test skips Livewire-boot cases). Behavior for every field
  is byte-identical to before; no field rendering changed except the intended `service_account_json`
  Textarea. Covered by `tests/Feature/Integrations/IntegrationCredentialResourceTextareaFieldTest.php`.

## Out of scope (NOT touched, per plan guardrails)
- `storage/app/research/supplier-probe.json` (pre-existing deletion), left unstaged.
- `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (pre-existing modification), left unstaged.
- Untracked `.claude/`, left alone.
- `pint --test` reports pre-existing style drift in ~7 other Integrations files (Resolver, several
  older tests) NOT touched by this task — out of scope, not fixed.

## Known Stubs
None.

## Self-Check
- Files exist: enum, resource, 3 test files — all present (edits/writes succeeded).
- Commits `3b70f24`, `fe62b40`, `db34140` — all in `git log`.
- **Self-Check: PASSED**
