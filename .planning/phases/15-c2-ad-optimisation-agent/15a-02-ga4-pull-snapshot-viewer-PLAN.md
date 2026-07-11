# 15a-02 — GA4 daily channel/campaign pull → snapshot → Marketing viewer

**Type:** GSD phase-plan slice (TDD, atomic commits). Executor does NOT push/deploy.
**Parent:** Phase 15 (expanded) — Marketing Intelligence. Builds on 15a-01's `GoogleAnalyticsClient`.
**Decisions:** D15-1/2/3 (STATE.md) — GA4-first, READ-ONLY, writes deferred. Research: `15-RESEARCH.md`.

## Goal
Pull daily GA4 channel/campaign performance into a local snapshot table on a schedule and show it
in a read-only admin viewer under a new **Marketing** nav group. Built and fully tested against
STUBS so it's ready the instant the operator provisions a GA4 service account — and it must **no-op
gracefully when GA4 is not yet configured** (so it's safe to schedule in prod today). READ-ONLY: no
external writes, no mutation of GA4.

## Scope — ONE report this slice
Channel/campaign daily performance only. Grain = `date × sessionDefaultChannelGroup ×
sessionSourceMedium × sessionCampaignName`. Metrics: `sessions`, `keyEvents`, `transactions`,
`purchaseRevenue`. (Landing-page and product-level reports are a later slice — do NOT add them now.)
No new Deptrac layer — the snapshot model + pull live in the existing **Integrations** domain; the
dedicated `Marketing` Deptrac layer is deferred to 15b (analyser services). The Filament resource is
presentation (already allowed to read across domains per the e301158 presentation-layer model).

## Context / patterns to mirror (verified)
- **Client seam (15a-01):** `App\Domain\Integrations\Clients\GoogleAnalyticsClient` exposes
  `runReport(RunReportRequest $request): RunReportResponse` (thin SDK passthrough) + protected
  `credentials()` (returns `['service_account_json','property_id']` or null) + `client()`. The
  property id is encapsulated in credentials — do NOT leak it to callers.
- **Snapshot model shape:** `app/Domain/Products/Models/ProductPriceSnapshot.php` — `final class …
  extends Model`, `$fillable`, `$casts`, `unique(entity, recorded_at)` + `index(recorded_at)`.
- **Migration shape:** `database/migrations/2026_05_04_163008_create_history_snapshot_tables.php`
  (`Schema::create` + `->unique([...], 'name')` + `->index`). Keep driver-portable (SQLite tests /
  MariaDB prod — memory: sqlite-mariadb-strict-trap; no MySQL-only DDL).
- **Command base:** `app/Console/Commands/BaseCommand.php` (+ `perform()`); find the existing
  `supplier:db-sync` command and mirror its structure/location.
- **Scheduler:** `routes/console.php` uses `Schedule::command('…')->dailyAt('…')` (London TZ).
- **Nav groups (post-pdw):** `AdminPanelProvider::navigationGroups()` = Operations, Catalogue, Woo
  Maintenance, Review, Competitors, Sync & CRM, Settings.

## Tasks

### Task 1 — Migration + model: `ga_channel_metrics_daily` (TDD)
Migration creating `ga_channel_metrics_daily`:
- `id`; `date` (date, indexed); `channel_group` (string 128); `source_medium` (string 191);
  `campaign` (string 191, nullable); `sessions` (unsigned int); `key_events` (unsigned int);
  `transactions` (unsigned int); `purchase_revenue_pennies` (unsigned bigint — store money as
  integer pennies, app convention); `pulled_at` (timestamp).
- `unique(['date','channel_group','source_medium','campaign'], 'gcm_grain_unique')` + `index('date')`.
- Model `App\Domain\Integrations\Models\GaChannelMetric` (`final`, `$fillable`, `$casts`:
  `date`=>date, counts=>integer, `purchase_revenue_pennies`=>integer, `pulled_at`=>datetime).
Test: migration runs on SQLite; model casts round-trip; the unique key enforces the grain.

### Task 2 — Client: `fetchChannelMetrics()` (TDD)
Add a READ-ONLY method to `GoogleAnalyticsClient`:
`fetchChannelMetrics(CarbonInterface $from, CarbonInterface $to): array` that
- returns `[]` when `credentials()` is null (unconfigured — no throw),
- otherwise builds a `RunReportRequest` (property `properties/{property_id}` from credentials, the 3
  dimensions + 4 metrics above, date range from→to), calls `$this->runReport($request)`, and maps
  the protobuf `RunReportResponse` rows → normalized assoc arrays:
  `['date','channel_group','source_medium','campaign','sessions','key_events','transactions','purchase_revenue']`
  (revenue as float in property currency — conversion to pennies happens in the command).
- Prefer `keyEvents`; if the property errors on `keyEvents`, that surfaces as an exception the caller
  logs (do not silently swallow inside fetch — only the null-credentials path is a silent no-op).
Test: partial-mock `runReport()` (15a-01's established seam — the SDK client is `final`) to return a
hand-built `RunReportResponse` with 1–2 rows; assert the protobuf→array mapping. Also assert the
null-credentials path returns `[]`.

### Task 3 — Pull command `google:pull-ga4` (TDD)
`google:pull-ga4` (mirror supplier:db-sync location/BaseCommand):
- Options: `--from=` / `--to=` (default: last 7 days through today, to refresh partial current day);
  `--dry-run`.
- Calls `GoogleAnalyticsClient::fetchChannelMetrics($from,$to)`. **If it returns `[]` (unconfigured
  OR genuinely no rows), log an info line and exit 0 — NEVER error.** This is what makes it safe to
  schedule before credentials exist.
- Maps each row → `GaChannelMetric` via `updateOrCreate` on the grain key (idempotent — re-pulling a
  day overwrites). **Money: `purchase_revenue_pennies = (int) round(purchase_revenue * 100)`** — this
  is the one money-mapping; test it explicitly. Sets `pulled_at = now()`.
- Prints a summary (`upserted N rows across D days`); `--dry-run` prints without writing.
Test: mock `GoogleAnalyticsClient::fetchChannelMetrics` to return canned rows (incl. a float revenue
like `1234.56` → assert `123456` pennies); assert rows persisted, idempotent re-run (no dupes,
overwrite), `--dry-run` writes nothing, and the unconfigured path (`[]`) exits 0 with no rows + no error.

### Task 4 — Read-only Filament viewer under a new **Marketing** nav group
- Add `'Marketing'` to `AdminPanelProvider::navigationGroups()` — place it AFTER `'Competitors'`,
  before `'Sync & CRM'`.
- A read-only Filament **Resource** on `GaChannelMetric` (mirror an existing read-only resource such
  as Price History / CRM Push Log): table columns date / channel_group / source_medium / campaign /
  sessions / key_events / transactions / revenue (format pennies→£, `->money('gbp', divideBy: 100)`
  or equivalent), default sort date desc, filters by date + channel_group. NO create/edit/delete.
  `navigationGroup = 'Marketing'`, sensible label ("GA4 Channels") + icon. Register a policy or gate
  consistent with other read-only resources (viewAny = authed workspace; no writes).
Test: a Filament/Livewire smoke test that the list page renders for an admin and shows a seeded row
(mirror an existing resource test). Keep it light.

### Task 5 — Schedule (safe no-op until configured)
In `routes/console.php`, `Schedule::command('google:pull-ga4')->twiceDaily(6, 14)` (London TZ, matching
the file's convention) with `->withoutOverlapping()`. Because Task 3 no-ops when unconfigured, this is
safe to ship now. Add a brief comment noting it stays a no-op until a GA4 credential is saved.

## Verify
- `pest` on all touched areas (migration/model, client fetch, command incl. money + idempotent +
  unconfigured, resource smoke) — GREEN.
- `php artisan route:list --path=admin` exit 0 (Marketing resource resolves).
- `php artisan schedule:list` shows `google:pull-ga4` without error.
- `pint` pass on touched files.
- `vendor/bin/deptrac analyse` (or the project's deptrac test) → **0 violations** (no new layer; model
  in Integrations, resource is presentation). If it flags, fix placement — do NOT add allow-list
  entries without noting why.

## Guardrails / out of scope
- READ-ONLY. No GA4 writes, no Google Ads, no closed-loop/GCLID, no advisory agent (that's 15b), no
  landing-page/product reports (later slice), NO new Deptrac layer.
- The scheduled command MUST be a safe no-op when GA4 is unconfigured — this is a hard requirement.
- Money stored as integer pennies; the ×100 rounding is the one money-mapping and must be tested.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- No push, no deploy. Atomic commits per task. Write `15a-02-SUMMARY.md` on completion (commit SHAs,
  table/grain, the money-mapping test, verify results, and confirm the unconfigured no-op path).
