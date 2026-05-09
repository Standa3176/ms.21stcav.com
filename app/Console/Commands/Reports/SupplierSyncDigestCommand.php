<?php

declare(strict_types=1);

namespace App\Console\Commands\Reports;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Sync\Services\SupplierSyncDigestComposer;
use App\Mail\SupplierSyncDigestMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily post-supplier-sync digest sender — replaces the legacy plugin's
 * send_results_and_cleanup() 4-CSV email.
 *
 * Schedule: routes/console.php registers this Mon-Fri at 08:00 Europe/London
 * (30 min after supplier:db-sync 07:00). Recipients = AlertRecipient where
 * receives_sync_reports=true AND is_active=true (existing flag from Phase 2).
 *
 * Empty-recipient guard mirrors WeeklyDigestCommand: log + return SUCCESS so
 * Horizon doesn't alert on what is a configuration state.
 */
final class SupplierSyncDigestCommand extends BaseCommand
{
    protected $signature = 'reports:supplier-sync-digest';

    protected $description = 'Compose + send the post-supplier-sync digest to opted-in AlertRecipients.';

    protected function perform(): int
    {
        /** @var SupplierSyncDigestComposer $composer */
        $composer = app(SupplierSyncDigestComposer::class);

        $recipients = AlertRecipient::query()
            ->where('receives_sync_reports', true)
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            Log::warning('SupplierSyncDigestCommand: no AlertRecipient has receives_sync_reports=true; skipping send.');
            $this->warn('No recipients opted in to receives_sync_reports — digest not sent.');

            return self::SUCCESS;
        }

        $payload = $composer->compose();

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email, $recipient->name)
                ->send(new SupplierSyncDigestMail($payload));
        }

        $this->info(sprintf(
            'Supplier sync digest sent to %d recipient(s).',
            $recipients->count(),
        ));

        return self::SUCCESS;
    }
}
