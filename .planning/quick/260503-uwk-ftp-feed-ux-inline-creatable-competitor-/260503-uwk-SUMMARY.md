---
quick_id: 260503-uwk
description: FTP Feed UX — inline-creatable competitor + auto local_filename + Competitors admin page
date: 2026-05-03
commit: 538b0ee
status: completed
---

# Quick Task 260503-uwk — Summary

## What changed

Two operator UX problems fixed in one atomic commit on top of Phase 11.2 + Phase 09.1:

### 1. Catalogue → Competitors Filament Resource (NEW)

- 4 files created: `CompetitorResource.php` + 3 Pages (List, Create, Edit)
- Auto-discovered via the existing `discoverResources(Domain/Competitor/...)` in AdminPanelProvider — no provider edits needed
- Catalogue group, sort 10 (top), heroicon-o-building-storefront
- Form: name (auto-derives slug live on blur), slug (regex-validated), status (Pending/Active/Inactive), is_active toggle, optional website_url + map_policy_notes
- Table: name, slug (mono + copyable), status badge, feeds count, last ingest at, is_active toggle column
- Routes registered: `admin/competitors`, `/create`, `/{record}/edit` (verified via `route:list`)

### 2. CompetitorFtpFeedResource form changes

- **Inline-creatable competitor select.** `createOptionForm()` opens a tiny modal with name + optional slug → creates Competitor with status=pending and auto-slug if slug blank. No need to leave the FTP Feed form to pre-seed a competitor.
- **`local_filename` is now Hidden.** Operator never touches it. Server-side `mutateFormDataBeforeCreate()` on CreateCompetitorFtpFeed derives `{slug}_2026-01-01.csv` from the competitor's slug. The fixed date is intentional — the watcher's regex requires `<slug>_YYYY-MM-DD.csv` for slug parsing only; freshness comes from file mtime.
- **Tightened form-level regex** on the (now hidden) local_filename to match the watcher's pattern: `regex:/^[a-z0-9_-]+_\d{4}-\d{2}-\d{2}\.csv$/`. Closes the previous "passes form, fails watcher" footgun where operators could save a value that the watcher would later quarantine.

### 3. Competitor model — ftpFeeds() HasMany (NEW)

Added the inverse relationship so `CompetitorResource` table can show `feeds_count` via `->counts('ftpFeeds')`. CompetitorFtpFeed already had the `competitor()` BelongsTo side; this completes the bidirectional pattern.

## Tests + verification

- `tests/Feature/Competitor/Ftp/CompetitorFtpFeedResourceTest.php` — all passing (incl. RBAC, default sort, stale_days config, UNIQUE local_filename guard)
- `tests/Unit/Domain/Competitor/Models/CompetitorFtpFeedTest.php` — all passing (relationships, casts, defaults)
- 15 tests / 24 assertions / 33s — green
- `vendor/bin/deptrac analyse` — 0 violations
- `php artisan route:list` — 3 new admin/competitors routes registered

DB-driven tests required SQLite override (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`) per the standing MySQL gap noted in prior quick tasks.

## Out of scope (deliberately deferred)

- **Editing Competitor inline from FTP Feed form.** `createOptionForm` only handles create. Editing still requires the Competitors admin page — by design (avoids accidentally editing an active competitor's slug while configuring a new feed).
- **Migrating existing feeds' local_filename** to the new pattern. Existing rows stay as-is.
- **Renaming "Supplier" label on the competitor select.** User uses both "supplier" and "competitor" interchangeably; kept the label as-is.
- **Resource-level policy for CompetitorResource.** Phase 09.1 nav-restructure pattern is permissive for Catalogue resources (RBAC enforced at action layer). Adding a CompetitorPolicy is a separate task if/when needed.

## Files changed (7 total, +273 / -7)

**Created (4):**
- `app/Domain/Competitor/Filament/Resources/CompetitorResource.php`
- `app/Domain/Competitor/Filament/Resources/CompetitorResource/Pages/ListCompetitors.php`
- `app/Domain/Competitor/Filament/Resources/CompetitorResource/Pages/CreateCompetitor.php`
- `app/Domain/Competitor/Filament/Resources/CompetitorResource/Pages/EditCompetitor.php`

**Modified (3):**
- `app/Domain/Competitor/Models/Competitor.php` (+5 / 0)
- `app/Domain/Competitor/Filament/Resources/CompetitorFtpFeedResource.php` (+44 / -7)
- `app/Domain/Competitor/Filament/Resources/CompetitorFtpFeedResource/Pages/CreateCompetitorFtpFeed.php` (+22 / 0)

## Commit

`538b0ee` — feat(competitor-feeds): inline-creatable competitor + auto-derive local_filename + dedicated Competitors admin page

## Verification (operator)

1. Reload `/admin`
2. Catalogue nav now shows **Competitors** at the top of the group
3. Click "Add Competitor" → fill name → slug auto-derives on blur → save
4. Catalogue → FTP Feeds → "Add New Feed":
   - Supplier dropdown shows existing competitors + a "+ Create" option
   - Click "+ Create" → tiny modal with name field → creates the competitor and selects it
   - **No `Local filename` field** in the form anymore
5. Save the feed → should create with local_filename = `<competitor-slug>_2026-01-01.csv` (verify in the row's edit view, where it'll still display via the Hidden field's round-trip)
6. Run `php artisan competitor:ftp-pull --live` → should now process the feed (assuming the FTP credentials still authenticate)
