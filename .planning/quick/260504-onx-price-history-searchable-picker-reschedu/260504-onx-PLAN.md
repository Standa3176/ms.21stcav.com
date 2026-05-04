---
quick_id: 260504-onx
description: Price History searchable picker + reschedule (supplier weekdays 07, competitor 02)
date: 2026-05-04
must_haves:
  truths:
    - brands table does NOT exist (Schema::hasTable('brands') = false). Skip brand search.
    - Existing executor pass used plain HTML select capped at 500 rows
    - Filament 3.3 Page can adopt HasForms + InteractsWithForms for inline search forms
  artifacts:
    - routes/console.php (2 cron changes)
    - app/Domain/Products/Filament/Pages/PriceHistoryPage.php (HasForms + Select)
    - resources/views/filament/pages/price-history.blade.php (render $this->form)
---

# Plan 260504-onx

1. supplier:db-sync → cron `0 7 * * 1-5` (Mon-Fri 07:00 London)
2. competitor:ftp-pull --live → cron `0 2 * * 0,3` (Sun+Wed 02:00 London)
3. PriceHistoryPage: implements HasForms, uses Select with searchable + getSearchResultsUsing across sku / name / short_description, no row limit
4. Blade replaces plain `<select>` with `{{ $this->form }}`
5. Verify: schedule:list, curl probe, deptrac
