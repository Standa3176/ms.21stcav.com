<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Models\Supplier;
use Carbon\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260626-q2b — suppliers:sync-feed-dates.
 *
 * Mirrors what MeetingStore was MISSING: the supplier's REAL file date. The
 * Suppliers admin page used to derive "Feed date" from
 * supplier_offer_snapshots.recorded_at — the date MS last PULLED, stamped
 * today() on every supplier:db-sync run regardless of whether the supplier
 * actually refreshed. So Nuvias showed fresh/today even though its real file
 * date is 2026-05-14 00:20:24 (probed live 2026-06-26: feeds.id=1,
 * remote_date='2026-05-14 00:20:24', cron_run='2026-05-20 12:53:12', status=0).
 *
 * The supplier's TRUE file date lives on the REMOTE feeds table as
 * feeds.remote_date. This command connects to the same remote SupplierDb MySQL
 * VPS that supplier:db-sync uses (via the SupplierDb integration credential +
 * mysqli), runs `SELECT id, name, remote_date, cron_run, status FROM feeds`, and
 * upserts those feed-metadata fields onto the local suppliers table.
 *
 * METADATA-ONLY: it writes NO product prices/stock. The upsert is restricted to
 * name + the three feed_* fields, so it NEVER clobbers operator-owned columns
 * (is_active, stale_after_days, notes) — same discipline as
 * CheckStaleSuppliersCommand's discovery upsert.
 *
 * Join key: feeds.id (== suppliers.supplier_id, as string). Nuvias is feeds.id=1.
 *
 * The DB-write logic is the PURE, testable upsertFeedRows(array, bool $dryRun)
 * method — array-in, no mysqli — so the mapping + operator-field preservation +
 * zero-date guard + dry-run contract are all unit-tested without a remote DB.
 *
 * Schedule: routes/console.php registers a Mon-Fri 06:55 London run, just before
 * the 07:00 supplier:db-sync price sync, so the page shows today's feed dates.
 *
 * Operator entry points:
 *   php artisan suppliers:sync-feed-dates --dry-run   (count what would change)
 *   php artisan suppliers:sync-feed-dates             (LIVE — upsert metadata)
 */
final class SyncSupplierFeedDatesCommand extends BaseCommand
{
    protected $signature = 'suppliers:sync-feed-dates
        {--dry-run : Report what would change without writing}';

    protected $description = 'Pull the REAL feed date (feeds.remote_date) + cron_run + status from the remote supplier MySQL and upsert them onto suppliers (metadata-only — no price/stock writes).';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('suppliers:sync-feed-dates — '.($dryRun ? 'DRY-RUN' : 'LIVE'));

        // ── Resolve credentials (same SupplierDb credential as supplier:db-sync) ──
        $creds = $this->resolver->for(IntegrationCredentialKind::SupplierDb);

        // Suppress mysqli's default warning-on-failure so we return a clean
        // FAILURE instead of leaking PHP warnings (mirrors SupplierDbSyncCommand).
        mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = @new \mysqli(
            (string) $creds['host'],
            (string) $creds['username'],
            (string) $creds['password'],
            (string) $creds['database'],
            (int) ($creds['port'] ?? 3306),
        );

        if ($mysqli->connect_errno !== 0) {
            $this->error("MySQL connect failed (errno={$mysqli->connect_errno}): {$mysqli->connect_error}");

            return SymfonyCommand::FAILURE;
        }

        // ── Pull every feed's metadata ──
        $rows = [];
        $result = $mysqli->query('SELECT id, name, remote_date, cron_run, status FROM feeds');
        if ($result === false) {
            $this->error("Query failed: {$mysqli->error}");
            $mysqli->close();

            return SymfonyCommand::FAILURE;
        }

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $mysqli->close();

        $this->info('Fetched '.count($rows).' feed row(s) from the remote feeds table.');

        // ── Upsert feed metadata (pure, no I/O) ──
        $counts = $this->upsertFeedRows($rows, $dryRun);

        $this->info(str_repeat('-', 60));
        $this->info(sprintf(
            'Done%s. created=%d updated=%d skipped=%d',
            $dryRun ? ' (dry-run — no writes)' : '',
            $counts['created'],
            $counts['updated'],
            $counts['skipped'],
        ));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Upsert feed-metadata rows onto the suppliers table.
     *
     * PURE (no mysqli/I/O) so the mapping, operator-field preservation, zero-date
     * guard and dry-run contract are all unit-testable with array input. For each
     * row with a non-empty id, updateOrCreate by supplier_id writing ONLY name +
     * the three feed_* fields — NEVER is_active / stale_after_days / notes.
     *
     * @param  array<int, array<string, mixed>>  $rows  feeds rows (id, name, remote_date, cron_run, status)
     * @return array{created:int, updated:int, skipped:int}
     */
    public function upsertFeedRows(array $rows, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $rawId = $row['id'] ?? null;
            $sid = $rawId === null ? '' : (string) $rawId;
            if ($sid === '') {
                $skipped++;

                continue;
            }

            // ONLY these four fields — operator-owned columns are never in the
            // update payload, so an upsert preserves is_active/stale_after_days/notes.
            $payload = [
                'name' => ($row['name'] ?? null) ?: null,
                'feed_remote_date' => $this->parseFeedDate(isset($row['remote_date']) ? (string) $row['remote_date'] : null),
                'feed_cron_run' => $this->parseFeedDate(isset($row['cron_run']) ? (string) $row['cron_run'] : null),
                'feed_status' => is_numeric($row['status'] ?? null) ? (int) $row['status'] : null,
            ];

            if ($dryRun) {
                // Count what WOULD change without writing.
                Supplier::where('supplier_id', $sid)->exists() ? $updated++ : $created++;

                continue;
            }

            $existed = Supplier::where('supplier_id', $sid)->exists();
            Supplier::updateOrCreate(['supplier_id' => $sid], $payload);
            $existed ? $updated++ : $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Parse a remote feed datetime string. NULL/empty/'0000-...' zero-dates
     * (which MySQL emits for unset datetimes) → null so Carbon never throws.
     * Otherwise Carbon::parse the value. Public for unit tests.
     */
    public function parseFeedDate(?string $raw): ?Carbon
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return null;
        }

        return Carbon::parse($raw);
    }
}
