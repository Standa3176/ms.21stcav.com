<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Contracts\TlsInspector;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Alerting\Notifications\TlsExpiryNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Quick task 260911-t80 — daily TLS expiry check.
 *
 * Born from the 2026-09-11 outage: meetingstore.co.uk's certificate expired at
 * 09:34 UTC and the first anyone knew was a catalogue-wide `trade:sync` failing
 * 4,333 times seven hours later. Customers had been meeting a browser security
 * warning the whole time.
 *
 * Root cause was a gap between two certificate systems on one box — certbot
 * renews what certbot issued (systemd timer, healthy) while the CWP AutoSSL
 * domains had no renewal scheduled at all. `staging.meetingstore.co.uk` had
 * been expired since 1 May with nothing reporting it.
 *
 * This command is deliberately agnostic about renewal. It asks each host what
 * it is SERVING, so it keeps working if a domain moves between certbot and
 * CWP, and it catches the case both tools miss — a renewal that succeeded on
 * disk but whose web server was never reloaded.
 *
 * Exit code is the durable signal, same posture as pricing:health-check: the
 * email is a courtesy and a missing subscriber must not make a real expiry
 * look like a pass.
 *
 *   0  every host comfortably valid
 *   1  at least one host expired, expiring inside the threshold, or unreachable
 */
final class TlsCheckExpiryCommand extends BaseCommand
{
    protected $signature = 'tls:check-expiry
        {--days= : Warn when fewer than this many days remain. Default: config alerting.tls.warn_days.}
        {--hosts= : Comma-separated hosts to check instead of the configured list.}
        {--notify : Email subscribed recipients when something needs attention.}';

    protected $description = 'Check TLS certificate expiry for the storefront and ops domains (read-only).';

    public function __construct(private readonly TlsInspector $inspector)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $hosts = $this->hosts();
        if ($hosts === []) {
            $this->error('No hosts configured — set alerting.tls.hosts or pass --hosts.');

            return self::FAILURE;
        }

        $warnDays = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('alerting.tls.warn_days', 14);

        $this->info(sprintf('TLS expiry check — %d host(s), warning under %d days.', count($hosts), $warnDays));

        $expired = [];
        $expiring = [];
        $unreachable = [];

        foreach ($hosts as $host) {
            $r = $this->inspector->inspect($host);

            if (! $r['ok']) {
                $unreachable[] = ['host' => $host, 'error' => (string) $r['error']];
                $this->line(sprintf('  %-34s UNREACHABLE — %s', $host, (string) $r['error']));

                continue;
            }

            $days = (int) $r['days_left'];
            $when = $r['expires_at']?->format('Y-m-d H:i') ?? '?';

            if ($days < 0) {
                $expired[] = ['host' => $host, 'days' => $days, 'expires_at' => $when];
                $this->line(sprintf('  %-34s EXPIRED %d day(s) ago (%s)', $host, abs($days), $when));

                continue;
            }

            if ($days < $warnDays) {
                $expiring[] = ['host' => $host, 'days' => $days, 'expires_at' => $when];
                $this->line(sprintf('  %-34s expires in %d day(s) (%s)', $host, $days, $when));

                continue;
            }

            $this->line(sprintf('  %-34s ok — %d day(s) (%s)', $host, $days, $when));
        }

        $problems = count($expired) + count($expiring) + count($unreachable);

        if ($problems === 0) {
            $this->info('PASS — every certificate is valid and outside the warning window.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'ATTENTION — %d expired, %d expiring, %d unreachable.',
            count($expired),
            count($expiring),
            count($unreachable),
        ));

        if ($this->option('notify')) {
            $this->notify($expired, $expiring, $unreachable, $warnDays);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<int, array{host:string, days:int, expires_at:string}>  $expired
     * @param  array<int, array{host:string, days:int, expires_at:string}>  $expiring
     * @param  array<int, array{host:string, error:string}>  $unreachable
     */
    private function notify(array $expired, array $expiring, array $unreachable, int $warnDays): void
    {
        try {
            $recipients = AlertRecipient::query()
                ->where('is_active', true)
                ->where('receives_pricing_alerts', true)
                ->get();

            if ($recipients->isEmpty()) {
                // The non-zero exit is the durable signal; the email is a courtesy.
                $this->warn('No active recipient subscribed; nothing emailed.');

                return;
            }

            Notification::send($recipients, new TlsExpiryNotification($expired, $expiring, $unreachable, $warnDays));
            $this->line(sprintf('  Alerted %d recipient(s).', $recipients->count()));
        } catch (Throwable $e) {
            // A notification failure must never mask the finding itself.
            Log::warning('tls.expiry_alert_failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<int, string> */
    private function hosts(): array
    {
        $raw = trim((string) ($this->option('hosts') ?? ''));

        $hosts = $raw !== ''
            ? explode(',', $raw)
            : (array) config('alerting.tls.hosts', []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            $hosts,
        ))));
    }
}
