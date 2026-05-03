---
quick_id: 260503-uwk
description: FTP Feed UX — inline-creatable competitor + auto local_filename + Competitors admin page
date: 2026-05-03
must_haves:
  truths:
    - Competitor resources auto-discover via AdminPanelProvider:111 — CompetitorResource only needs to live under Domain/Competitor/Filament/Resources
    - Competitor model fields (verified from Models/Competitor.php) — slug, name, website_url, map_policy_notes, status, is_active, last_ingest_at
    - Watcher regex (CompetitorWatchCommand:76) — ^([a-z0-9_-]+?)_(\d{4}-\d{2}-\d{2})\.csv$
    - local_filename has unique:competitor_ftp_feeds — auto-derived value must be unique per feed (collision-safe via competitor_id appended if needed)
  artifacts:
    - app/Domain/Competitor/Filament/Resources/CompetitorResource.php (NEW)
    - app/Domain/Competitor/Filament/Resources/CompetitorResource/Pages/ListCompetitors.php (NEW)
    - app/Domain/Competitor/Filament/Resources/CompetitorResource/Pages/CreateCompetitor.php (NEW)
    - app/Domain/Competitor/Filament/Resources/CompetitorResource/Pages/EditCompetitor.php (NEW)
    - app/Domain/Competitor/Filament/Resources/CompetitorFtpFeedResource.php (MODIFY)
    - app/Domain/Competitor/Filament/Resources/CompetitorFtpFeedResource/Pages/CreateCompetitorFtpFeed.php (MODIFY)
---

# Quick Task 260503-uwk

## Goal

Two coupled UX fixes for the Competitor FTP Feeds setup flow.

## Tasks

### Task 1 — CompetitorResource (Catalogue → Competitors admin page)

New Filament Resource auto-discovered via `discoverResources(Domain/Competitor/...)`.

- `$navigationGroup = 'Catalogue'`, `$navigationSort = 10`, `$navigationIcon = 'heroicon-o-building-storefront'`, `$navigationLabel = 'Competitors'`
- Form: name (required, live, derives slug), slug (required, unique, helper text), status (Select from STATUS_* constants), is_active (Toggle), website_url (TextInput, optional), map_policy_notes (Textarea, optional)
- Table columns: id, name, slug, status (badge), feeds_count, last_ingest_at, is_active toggle
- Default sort: name asc
- 3 Pages: List, Create, Edit (boilerplate)

### Task 2 — CompetitorFtpFeedResource form changes

**A. Inline-creatable competitor select:**

Add `->createOptionForm([...])` and `->createOptionUsing(fn (array $data) => Competitor::create([...]))` to the existing competitor_id Select. Inline form has just name + auto-derived slug + default status=pending. Keeps the dropdown for existing competitors AND lets operator add a new one without leaving the page.

**B. Auto-derive local_filename:**

Replace TextInput with `Hidden::make('local_filename')` and compute it server-side in `CreateCompetitorFtpFeed::mutateFormDataBeforeCreate()`:

```php
$competitor = Competitor::find($data['competitor_id']);
$data['local_filename'] = sprintf('%s_2026-01-01.csv', $competitor->slug);
```

Inline comment explains why date is fixed (watcher regex requires it but uses mtime for freshness).

Edit form: leave local_filename alone — keep the existing Hidden so it round-trips on save without UI exposure (and no risk of orphaning files in incoming/).

**C. Tighten regex:**

Form-level rule on local_filename → `regex:/^[a-z0-9_-]+_\d{4}-\d{2}-\d{2}\.csv$/` matching watcher's pattern. Belt-and-braces in case Hidden ever leaks editable.

### Task 3 — Verify

- `vendor/bin/pest tests/Feature/Competitor` (SQLite override)
- `vendor/bin/deptrac analyse` → 0 violations
- Manual: Catalogue → Competitors page renders, FTP Feed New form has createOptionForm

### Task 4 — Commit

`feat(competitor-feeds): inline-creatable competitor + auto-derive local_filename + dedicated Competitors admin page`

## Out of scope

- Editing Competitor from inside the FTP Feed form (createOptionForm only does create)
- Migrating existing competitors' local_filename to the new pattern (existing rows untouched)
- Renaming "Supplier" label on the select (kept as-is — user uses both terms)
