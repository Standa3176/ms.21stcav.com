---
quick_id: 260504-onx
description: Price History searchable picker + reschedule
date: 2026-05-04
commit: 4afd884
status: completed
---

# Quick Task 260504-onx — Summary

## Three changes shipped

### 1. supplier:db-sync — Mon-Fri 07:00 London (was daily 03:30)

`routes/console.php`. Cron now `0 7 * * 1-5`. Schedule:list confirms `0 6 * * 1-5` UTC = 07:00 BST Mon-Fri.

### 2. competitor:ftp-pull --live — Sun+Wed 02:00 London (was Sun+Wed 06:00)

`routes/console.php`. Cron now `0 2 * * 0,3`. Schedule:list confirms `0 1 * * 0,3` UTC = 02:00 BST.

### 3. Price History picker — searchable + uncapped

`PriceHistoryPage` now implements `HasForms` + uses `InteractsWithForms` trait. The `Select::make('productId')` field has:
- `->searchable()` — type-to-search inside the dropdown
- `->getSearchResultsUsing()` — server-side LIKE %search% across sku, name, short_description. Returns top 50 per query.
- `->getOptionLabelUsing()` — preserves the SKU/name display when a value is hydrated.
- All 5,633 products reachable. Brands skipped (no `brands` table in this codebase).

Blade now renders `{{ $this->form }}` instead of the plain HTML `<select>` capped at 500.

## Files changed (3, +70 / -37)

- `routes/console.php` — two schedule cron changes + descriptions/comments updated
- `app/Domain/Products/Filament/Pages/PriceHistoryPage.php` — HasForms + form() method
- `resources/views/filament/pages/price-history.blade.php` — render `$this->form` instead of HTML select

## Verification

- `php artisan schedule:list` — all 4 entries confirmed (woo 03:00 / supplier 07:00 Mon-Fri / competitor 02:00 Sun+Wed / history:prune 04:00 — all London-time)
- `vendor/bin/deptrac analyse` — 0 violations
- `curl /admin/price-history-page` → 302 (login redirect, no 500)

## Commit

`4afd884` — feat(price-history): searchable product picker + reschedule supplier weekdays-07 + competitor 02
