# 260712-gaj — HOTFIX: GA4 service_account_json credential field (Textarea + size)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Blocker:** operator cannot save GA4 credentials — Test connection returns "service_account_json is
not valid JSON."

## Root cause (confirmed)
`IntegrationCredentialResource` renders EVERY `requiredFields()` entry as a single-line `TextInput`
with `->maxLength(2048)` (loop ~L134-167). A GA4 service-account key is a **multi-line JSON ~2.3-2.6 KB**
(the `private_key` PEM alone is ~1.7 KB). Two failures compound:
1. Multi-line paste into a single-line `<input>` keeps only the first line (`{`) → invalid JSON.
2. Even single-line, `maxLength(2048)` truncates a ~2.5 KB key → invalid JSON.
So the `service_account_json` field must render as a **Textarea** with a large maxLength. No other
credential kind has this problem (they're all short secrets), so keep the change surgical.

## Tasks

### Task 1 — Declare large/JSON fields per kind (TDD)
Add `textareaFields(): array` to `IntegrationCredentialKind` (mirror `optionalFields()` / `urlFields()`
shape): `self::GoogleAnalytics => ['service_account_json']`, `default => []`. Test: GoogleAnalytics
returns `['service_account_json']`; other kinds return `[]`.

### Task 2 — Render those as Textarea in the credential form (TDD)
In `IntegrationCredentialResource`'s field loop, when `in_array($field, $kind->textareaFields(), true)`,
build a `Filament\Forms\Components\Textarea` instead of `TextInput`:
- `->rows(12)`, `->maxLength(16384)` (comfortably > a service-account key), `->required(create)`,
  `->dehydrated(fn ($state) => filled($state))` (so "leave blank to keep" still works on edit),
  helper text noting it's stored encrypted / leave blank to keep on edit, and a placeholder on edit
  like `'•••••••• (saved — leave blank to keep)'`. NOT `->password()` (Textarea can't mask; it's behind
  admin auth + encrypted at rest — acceptable and standard for a service-account key).
- Textarea fields are NEITHER url fields NOR password fields — branch BEFORE the url/password logic so
  they don't get `->url()`/`->password()`.
Keep all OTHER fields exactly as today (single-line TextInput, password/url as before). Test: build the
form schema for a `GoogleAnalytics` credential and assert the `payload_encrypted.service_account_json`
component is a `Textarea` with maxLength >= 8192; assert a different kind's fields are still `TextInput`.

### Task 3 — Round-trip proof (TDD) — the test that guarantees a REAL key works
A test that a realistic **multi-line ~2.5 KB** service-account JSON (a fixture with a fake but
structurally-valid `private_key` PEM block spanning newlines, total > 2048 chars) saved through the
credential path resolves back **byte-intact** via `IntegrationCredentialResolver` and `json_decode()`
succeeds (assert `type/project_id/client_email/private_key` survive). This catches BOTH the truncation
and the multi-line issues. Confirm the `payload_encrypted` storage column can hold the encrypted blob —
if it is `varchar`/limited, add a migration widening it to `text`/`longtext` (encryption + base64
inflates size ~1.4x); if already `text`+, no migration needed (note which in the SUMMARY).

## Verify
- `pest` on the enum, the resource form, and the round-trip — GREEN. Wider Integrations suite: no regression.
- `php artisan route:list --path=admin` exit 0.
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / out of scope
- Surgical: only the `service_account_json` (textareaFields) rendering + size. Do NOT change other
  kinds' fields, the encryption, or the resolver logic (beyond confirming round-trip).
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260712-gaj-SUMMARY.md` (root cause, the round-trip test, whether a column-widen migration was needed,
  verify results, and the redeploy note — cache rebuild via deploy.sh; migration only if the column was
  widened).
