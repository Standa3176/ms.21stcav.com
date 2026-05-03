---
quick_id: 260503-rul
description: Add OpenAI/ChatGPT credential kind + move FTP credentials to Admin nav group
date: 2026-05-03
must_haves:
  truths:
    - kind column is string(64) not DB enum — no migration needed (verified in 2026_05_02_160000_create_integration_credentials_table.php)
    - IntegrationCredentialResolver::resolveFromEnv() match is exhaustive — adding a case requires a branch
    - TestIntegrationAction::dispatch() match has a default fallback — adding a case is safe (test-connection returns "Unknown kind" until OpenAiClient is built)
  artifacts:
    - app/Domain/Integrations/Enums/IntegrationCredentialKind.php (extend with OpenAiApi)
    - app/Domain/Integrations/Services/IntegrationCredentialResolver.php (add env-fallback branch)
    - config/services.php (add services.openai stub)
    - tests/Feature/Integrations/IntegrationCredentialKindEnumTest.php (5 → 6 cases)
    - tests/Feature/Integrations/IntegrationCredentialResolverTest.php (wipe new env keys in beforeEach)
    - app/Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php (Catalogue → Admin, sort 30)
  key_links:
    - 09.1-CONTEXT.md D-04 (per-kind required-field shapes)
---

# Quick Task 260503-rul: OpenAI/ChatGPT credential kind + FTP credentials → Admin

## Goal

Two atomic changes to the Phase 09.1 Integration Credentials admin area:

1. **Add OpenAI/ChatGPT** as the 6th IntegrationCredentialKind so admins can store OpenAI API keys alongside Anthropic keys.
2. **Consolidate FTP credentials into Admin** so all secrets live in one nav group. FTP feeds (operational config, not secrets) stay in Catalogue.

## Tasks

### Task 1 — Extend `IntegrationCredentialKind` enum

File: `app/Domain/Integrations/Enums/IntegrationCredentialKind.php`

- Add case `OpenAiApi = 'openai_api'`
- `requiredFields()`: `['api_key']` (parity with Anthropic)
- `label()`: `'OpenAI / ChatGPT API'`
- `color()`: `'danger'` (expensive — visually distinct, parity with AnthropicApi)

### Task 2 — Add `services.openai` config stub

File: `config/services.php`

```php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
],
```

### Task 3 — Add OpenAi branch to resolver env-fallback

File: `app/Domain/Integrations/Services/IntegrationCredentialResolver.php`

Add to the `match ($kind)` in `resolveFromEnv()`:

```php
IntegrationCredentialKind::OpenAiApi => [
    'api_key' => (string) config('services.openai.api_key', ''),
],
```

### Task 4 — Update enum + resolver tests

File: `tests/Feature/Integrations/IntegrationCredentialKindEnumTest.php`
- `toHaveCount(5)` → `toHaveCount(6)`
- Add `->toContain('openai_api')`
- Add `expect(IntegrationCredentialKind::OpenAiApi->requiredFields())->toBe(['api_key'])`

File: `tests/Feature/Integrations/IntegrationCredentialResolverTest.php`
- Add `config()->set('services.openai.api_key', null);` to `beforeEach()` env-wipe block

### Task 5 — Move FTP Credentials Catalogue → Admin

File: `app/Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php`

- `$navigationGroup = 'Catalogue'` → `'Admin'`
- `$navigationSort = 50` → `30` (sits below Integration Credentials at 20, above Roles & Permissions at 60)
- Add docblock note: "Moved from Catalogue → Admin in quick task 260503-rul: FTP credentials are secrets, sit alongside other integration credentials in Admin. Feeds remain in Catalogue (operational config edited by ops, not secrets)."

CompetitorFtpFeedResource stays in Catalogue — feeds are operational config, not secrets.

### Task 6 — Verify

- `vendor/bin/pest tests/Feature/Integrations/IntegrationCredentialKindEnumTest.php` passes
- `vendor/bin/pest tests/Feature/Integrations/IntegrationCredentialResolverTest.php` passes
- `vendor/bin/deptrac analyse` — 0 violations

## Out of scope

- OpenAiClient (no test-connection support yet — falls through to default "Unknown kind"; user can save credential, run "Test connection" returns gentle failure until client is wired)
- DB enum migration (kind column is string(64), no constraint to extend)
- Moving CompetitorFtpFeedResource (deliberately stays in Catalogue per group semantics)
