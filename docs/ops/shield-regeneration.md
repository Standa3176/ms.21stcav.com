# Shield Safe Regeneration Runbook (AGNT-11)

**Phase 8 Plan 05 Task 1** — Operator runbook for `shield:safe-regenerate`.

This document explains when and how to use the `shield:safe-regenerate` artisan
command, which wraps Filament Shield's `shield:generate` with automatic P5-F
restoration (the "hand-written policy body survives Shield regeneration"
invariant established by Phase 6 commit `dba497c`).

---

## When to run

Run `shield:safe-regenerate` whenever a Filament Resource is added, renamed, or
removed in the application. Filament Shield re-scans the app's Filament tree on
every `shield:generate` call and writes Permission rows + a *fresh* Policy stub
for every Resource it discovers — overwriting any hand-written body. This
command automates the "regenerate, then restore" cycle.

**Trigger events:**

- New Filament Resource created (e.g. Phase 11 `QuoteResource`,
  Phase 13 `WhatsAppConversationResource`, Phase 14 `ChatbotSessionResource`,
  Phase 15 `AdBudgetOverrideResource`)
- Resource renamed / moved between domains
- Resource deleted (cleanup orphaned permissions + policy)
- After running `composer update bezhansalleh/filament-shield` (in case
  template skeleton has changed)

**DO NOT run during normal feature development on existing Resources** — the
hand-written policy bodies are stable and don't need re-scanning.

---

## Standard workflow

```bash
# 1. Confirm working tree is clean (no uncommitted edits).
git status

# 2. Regenerate Shield + restore hand-written policies.
php artisan shield:safe-regenerate

# 3. Belt-and-braces: verify PolicyTemplateIntegrityTest still passes.
php artisan test --filter=PolicyTemplateIntegrityTest

# 4. Confirm only expected new files (the freshly-generated permissions seed)
#    appear in `git status`. Hand-written policies should be unchanged.
git status

# 5. Commit any new permission rows + intentional policy additions.
git add database/seeders/RolePermissionSeeder.php app/Domain/{...}/Policies/{...}Policy.php
git commit -m "shield: regenerate after adding {ResourceName}"
```

---

## First-time policy bootstrap

When a *new* policy file is added (e.g. Phase 8's `AgentRunPolicy` was the
first Agents-domain policy), the regeneration flow has a special edge case:

- On the very first commit, the policy file IS in the working tree but not yet
  in git. `git ls-files` returns it once committed. After the first commit
  lands, subsequent `shield:safe-regenerate` runs will capture the policy
  via `git ls-files` → restoration overwrites Shield's freshly-generated
  stub with the committed hand-written body.

- If you want Shield's freshly-generated body to be the canonical version
  (e.g. for a brand-new Resource where there are no special rules to enforce),
  pass `--allow-new=PolicyClassName`:

  ```bash
  php artisan shield:safe-regenerate --allow-new=AgentRunPolicy
  ```

  The captured policy file will be skipped during restoration; Shield's
  output stands. Operator inspects the file post-run; if needed, edits it
  to add hand-written `hasRole('admin')` checks and recommits.

- After the hand-written body is committed, future `shield:safe-regenerate`
  runs do NOT need `--allow-new` — the file is now tracked + the canonical
  body lives in git, ready to be checked out.

---

## Force mode (rare)

`--force` overrides the dirty-tree refusal. Use ONLY when intentionally
combining Shield regeneration with other working-tree changes:

```bash
php artisan shield:safe-regenerate --force
```

Common case: composer-updating Shield itself, where the vendor/ tree is dirty.

**WARNING:** the command runs `git checkout --` on captured policies. Any
unstaged edits to a captured policy file WILL be lost. Stash first if in doubt.

---

## Restoration disable (escape hatch)

`--restore=false` runs Shield without the post-step git checkout:

```bash
php artisan shield:safe-regenerate --restore=false
```

Use when you want Shield's freshly-generated stubs to be the canonical version
for ALL policies in this run (rare; usually you want the opposite). Operator
inspects every generated policy file post-run.

---

## Failure modes

| Symptom | Cause | Fix |
|---|---|---|
| `git working tree is dirty` | Uncommitted edits in `git status` | `git stash` or commit first; or pass `--force` |
| `git ls-files` returns empty | Running outside a git repo | Ensure CWD is a git working tree |
| `shield:generate failed with exit code N` | Filament Shield internal error | Check `storage/logs/laravel.log`; usually a Resource class fails to autoload |
| `PolicyTemplateIntegrityTest FAILED — Shield {{ Placeholder }} leak detected` | Some policy still contains the literal `{{ Placeholder }}` post-restore | Inspect failing policy; restore by hand if `git checkout --` couldn't reach it |
| `Could not restore {ClassName}` warning | Policy file wasn't tracked at expected path; `git checkout` failed | Inspect file by hand; manually restore by re-typing the body or pulling from a known-good commit |

---

## Adoption checklist for upcoming v2 phases

| Phase | Adds | First-run command |
|---|---|---|
| Phase 10 (PricingAgent) | None — reuses AgentRunPolicy + SuggestionPolicy | `php artisan shield:safe-regenerate` |
| Phase 11 (Quote) | `QuotePolicy` | `php artisan shield:safe-regenerate --allow-new=QuotePolicy` (first commit) |
| Phase 12 (SeoAgent) | None — reuses AgentRunPolicy | `php artisan shield:safe-regenerate` |
| Phase 13 (WhatsApp) | `WhatsAppConversationPolicy` | `php artisan shield:safe-regenerate --allow-new=WhatsAppConversationPolicy` |
| Phase 14 (Chatbot) | `ChatbotSessionPolicy` | `php artisan shield:safe-regenerate --allow-new=ChatbotSessionPolicy` |
| Phase 15 (Marketing) | `AdBudgetOverridePolicy` | `php artisan shield:safe-regenerate --allow-new=AdBudgetOverridePolicy` |

After the first commit lands the policy with its hand-written body, drop the
`--allow-new` flag from subsequent runs — capture-and-restore takes over.

---

## Implementation reference

- Source: `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php`
- Tests: `tests/Feature/Agents/ShieldSafeRegenerateCommandTest.php`
- Architecture invariant: `tests/Architecture/PolicyTemplateIntegrityTest.php`
  (floor pinned at 27 in Phase 8 Plan 01; bumps as later phases add policies)
- Phase 6 precedent: commit `dba497c` (manual restoration script for
  `AutoCreateReviewResource`) — `shield:safe-regenerate` generalises this.
- Phase 8 README integration: add to `.planning/phases/08-c4-agent-framework/`
  README on first cutover deploy.
