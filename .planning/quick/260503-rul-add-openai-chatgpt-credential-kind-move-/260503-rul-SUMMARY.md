---
quick_id: 260503-rul
description: Add OpenAI/ChatGPT credential kind + move FTP credentials to Admin nav group
date: 2026-05-03
commit: ecb376a
status: completed
---

# Quick Task 260503-rul — Summary

## What changed

Two atomic, independent admin-area refinements on top of Phase 09.1:

### 1. OpenAI/ChatGPT credential kind

- `IntegrationCredentialKind` enum extended from 5 → 6 cases (added `OpenAiApi = 'openai_api'`)
- `requiredFields()` / `label()` / `color()` entries added (parity with `AnthropicApi` — single `api_key` field, `danger` color for visual "expensive" parity)
- `IntegrationCredentialResolver::resolveFromEnv()` match extended with OpenAi branch reading `services.openai.api_key`
- `config/services.php` adds `services.openai.api_key => env('OPENAI_API_KEY')` stub
- No DB migration needed — `kind` column is `string(64)`, not a DB-level enum

### 2. FTP Credentials Catalogue → Admin

- `CompetitorFtpCredentialResource::$navigationGroup` Catalogue → Admin
- `$navigationSort` 50 → 30 (sits between Integration Credentials at 20 and Roles & Permissions at 60)
- Docblock note added explaining the move
- `CompetitorFtpFeedResource` deliberately left in Catalogue — feeds are operational config edited by ops, not secrets

## Tests

- `IntegrationCredentialKindEnumTest` — 3/3 passing (cases count 5→6, OpenAi requiredFields assertion added)
- `IntegrationCredentialResolverTest` — 6/6 passing (Test 2.5 loops every kind, validates the new resolver branch)
- Deptrac — 0 violations

DB-driven tests required SQLite override (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`) because `phpunit.xml` hardcodes MySQL — same MySQL gap noted in prior `260503-rgd` quick task. No test failure introduced by this change.

## Out of scope (deliberately deferred)

- **OpenAiClient test-connection support.** No `OpenAiClient` exists yet; `TestIntegrationAction::dispatch()` has a `default => IntegrationTestResult::failed('Unknown kind: ...')` branch so operators saving an `openai_api` row and clicking "Test connection" will see a gentle failure rather than a crash. When OpenAI integration is needed in code, add `IntegrationCredentialKind::OpenAiApi => app(OpenAiClient::class)->testConnection()` to the dispatch match.
- **DB enum migration.** Not needed — `kind` is `string(64)` per the original Phase 09.1 schema (no constraint to extend).
- **CompetitorFtpFeedResource move.** Stays in Catalogue by design — feeds are operational config, not secrets.

## Files changed

- `app/Domain/Integrations/Enums/IntegrationCredentialKind.php` (+8 / -3)
- `app/Domain/Integrations/Services/IntegrationCredentialResolver.php` (+3 / 0)
- `config/services.php` (+7 / 0)
- `tests/Feature/Integrations/IntegrationCredentialKindEnumTest.php` (+5 / -2)
- `tests/Feature/Integrations/IntegrationCredentialResolverTest.php` (+1 / 0)
- `app/Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php` (+8 / -3)

Total: 6 files, +34 / -6 lines.

## Commit

`ecb376a` — feat(admin): add OpenAI/ChatGPT credential kind + consolidate FTP credentials into Admin nav group

## Verification (operator)

1. Reload `/admin`
2. Admin nav group should now show: Integration Credentials, FTP Credentials, Roles & Permissions, Alert Recipients, plus the auto-create settings pages
3. Catalogue nav group should still show FTP Feeds + the catalogue resources, just without FTP Credentials
4. Click "New Integration Credential" — Kind dropdown shows 6 options including "OpenAI / ChatGPT API"
5. Save a row with `kind=openai_api, api_key=sk-test` — should encrypt + persist
6. (FTP test) Click "Test connection" on an FTP credential row — earlier `ftp_connect()` undefined error fixed by enabling Herd's `extension=ftp` in `php84/php.ini`
