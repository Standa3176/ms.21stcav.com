---
quick_id: 260504-e0q
description: SQLite WAL default + competitor:retry-quarantine command
date: 2026-05-04
commit: 5762ff7
status: completed
---

# Quick Task 260504-e0q — Summary

## Two persistent fixes from the screenmoove incident

### 1. SQLite WAL journal_mode default

`config/database.php` — SQLite connection now sets `'journal_mode' => 'WAL'`. Reduces writer-vs-writer lock contention from `job_batches.pending_jobs` updates during parallel CompetitorCsvChunkJob batches. Production (MySQL) unaffected. Survives `migrate:fresh`.

The earlier session's `PRAGMA journal_mode=WAL` runtime fix was a one-shot — this config change makes it permanent.

### 2. `php artisan competitor:retry-quarantine`

| Invocation | Behaviour |
|---|---|
| `competitor:retry-quarantine` | List quarantined files + last-error from sidecar JSON (no mutation) |
| `competitor:retry-quarantine --all` | Move every .csv quarantine→incoming, delete .error.json sidecars |
| `competitor:retry-quarantine --file=NAME` | Move only the matching file across all date subdirs |
| `competitor:retry-quarantine --all --file=X` | Errors with "mutually exclusive" |

Idempotent: skips clobber when target already exists in incoming/. Best-effort sidecar cleanup. Always prints "Now run: php artisan competitor:watch (after 30s mtime gate)" so operator knows next step.

Replaces the manual `cp storage/app/competitors/quarantine/<date>/*.csv storage/app/competitors/incoming/` ceremony.

## Files (4 — +245 / -1)

- `config/database.php` (+7 / -1)
- `app/Domain/Competitor/Console/Commands/CompetitorRetryQuarantineCommand.php` (NEW, 121 lines)
- `app/Providers/AppServiceProvider.php` (+2 — registered after CompetitorWatchCommand per Phase 5 precedent)
- `tests/Feature/Competitor/CompetitorRetryQuarantineCommandTest.php` (NEW, 116 lines, 5 tests)

## Tests

5/5 passing, 25 assertions, 5.4s:
- lists files without moving them when no flags given
- --all moves all .csv quarantine → incoming + deletes .error.json sidecars
- --file=NAME moves only the matching file
- skips clobber when target already exists in incoming
- errors if both --all and --file are given

Deptrac: 0 violations.

## Subtle bugs caught during build

1. **Symfony Finder `name()` is cumulative** — calling `->name('*.csv')->name('b.csv')` matches files matching EITHER pattern (OR semantics), not the AND I expected. Fix: only call once with the appropriate pattern.

2. **`SplFileInfo::getRealPath()` returns false after rename** — initial implementation tried to derive the sidecar path AFTER moving the .csv, which broke. Fix: snapshot `$sourcePath` and `$sidecarPath` before the rename.

## Commit

`5762ff7` — feat(competitor-csv): SQLite WAL journal_mode default + competitor:retry-quarantine command

## Operator usage

After any failed ingest cycle:

```bash
# See what's stuck
php artisan competitor:retry-quarantine

# Replay everything
php artisan competitor:retry-quarantine --all

# Wait for 30s mtime gate, then re-watch
sleep 35 && php artisan competitor:watch
php artisan queue:work --queue=competitor-csv --stop-when-empty
```
