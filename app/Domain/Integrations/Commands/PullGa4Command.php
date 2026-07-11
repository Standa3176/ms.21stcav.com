<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Integrations\Clients\GoogleAnalyticsClient;
use App\Domain\Integrations\Models\GaChannelMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Phase 15 Plan 15a-02 — google:pull-ga4.
 *
 * READ-ONLY daily pull of GA4 channel/campaign performance into the local
 * ga_channel_metrics_daily snapshot table. Mirrors supplier:db-sync's
 * BaseCommand + --dry-run shape.
 *
 * SAFE NO-OP CONTRACT (hard requirement): GoogleAnalyticsClient::fetch-
 * ChannelMetrics() returns [] both when GA4 is unconfigured AND when a
 * configured property genuinely returns no rows. In EITHER case this command
 * logs an info line and exits 0 — it NEVER errors. That is what makes it safe
 * to schedule in prod BEFORE a GA4 service account exists (routes/console.php
 * registers it today; it stays a no-op until credentials are saved).
 *
 * Idempotency: each row upserts via updateOrCreate on the grain unique key
 * (date × channel_group × source_medium × campaign), so re-pulling a day (the
 * default 7-day window deliberately re-covers the partial current day)
 * overwrites in place with no duplicates.
 *
 * Money: purchase_revenue arrives as a float in the property currency; this
 * command performs the ONE money-mapping —
 *   purchase_revenue_pennies = (int) round($row['purchase_revenue'] * 100).
 *
 * Operator entry points:
 *   php artisan google:pull-ga4                       (LIVE upsert, last 7 days→today)
 *   php artisan google:pull-ga4 --dry-run             (count only, no writes)
 *   php artisan google:pull-ga4 --from=2026-07-01 --to=2026-07-07
 */
final class PullGa4Command extends BaseCommand
{
    protected $signature = 'google:pull-ga4
        {--from= : Start date (Y-m-d). Default: 7 days before --to}
        {--to= : End date (Y-m-d). Default: today (refreshes the partial current day)}
        {--dry-run : Report what would be upserted without writing}';

    protected $description = 'Pull daily GA4 channel/campaign metrics into the local snapshot table (READ-ONLY).';

    public function __construct(private readonly GoogleAnalyticsClient $client)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'))->startOfDay()
            : CarbonImmutable::today();
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay()
            : $to->subDays(7);

        $this->info(sprintf(
            'google:pull-ga4 — %s (%s → %s)',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $from->toDateString(),
            $to->toDateString(),
        ));

        $rows = $this->client->fetchChannelMetrics($from, $to);

        // Safe no-op: unconfigured OR genuinely no rows. Log + exit 0.
        if ($rows === []) {
            $this->info('No GA4 rows returned (integration unconfigured or no data in range) — nothing to upsert.');
            Log::info('google.pull_ga4.noop', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'dry_run' => $dryRun,
            ]);

            return SymfonyCommand::SUCCESS;
        }

        $upserted = 0;
        $days = [];

        foreach ($rows as $row) {
            $days[$row['date']] = true;

            if ($dryRun) {
                $upserted++;

                continue;
            }

            GaChannelMetric::updateOrCreate(
                [
                    'date' => $row['date'],
                    'channel_group' => $row['channel_group'],
                    'source_medium' => $row['source_medium'],
                    'campaign' => $row['campaign'],
                ],
                [
                    'sessions' => (int) $row['sessions'],
                    'key_events' => (int) $row['key_events'],
                    'transactions' => (int) $row['transactions'],
                    // The one money-mapping — GA4 float currency → integer pennies.
                    'purchase_revenue_pennies' => (int) round(((float) $row['purchase_revenue']) * 100),
                    'pulled_at' => now(),
                ],
            );
            $upserted++;
        }

        $this->info(sprintf(
            '%s %d rows across %d day(s).',
            $dryRun ? 'Would upsert' : 'Upserted',
            $upserted,
            count($days),
        ));

        return SymfonyCommand::SUCCESS;
    }
}
