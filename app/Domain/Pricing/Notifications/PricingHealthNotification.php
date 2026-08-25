<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Quick task 260825-n5v — a live product is priced below cost or below the
 * agreed minimum margin.
 *
 * Fires only on HARD faults. Suspect costs are counted in the body but never
 * trigger the mail on their own: they are a known data-quality backlog, and an
 * alarm that fires daily for a backlog is one people mute — after which the
 * real one is missed too.
 *
 * Every pricing fault found in August 2026 was found by someone deciding to go
 * looking. This is the first thing that tells us without being asked.
 */
final class PricingHealthNotification extends Notification
{
    /**
     * @param  array<int, array<string, mixed>>  $belowCost
     * @param  array<int, array<string, mixed>>  $belowFloor
     */
    public function __construct(
        public readonly array $belowCost,
        public readonly array $belowFloor,
        public readonly int $costFaultCount,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lossCount = count($this->belowCost);
        $floorCount = count($this->belowFloor);

        $mail = (new MailMessage)
            ->subject(sprintf(
                '[MS Ops] Pricing: %s',
                $lossCount > 0
                    ? sprintf('%d product(s) SELLING BELOW COST', $lossCount)
                    : sprintf('%d product(s) below the margin floor', $floorCount),
            ));

        if ($lossCount > 0) {
            $mail->line(sprintf(
                '%d live product(s) are priced below what we pay for them. Every sale loses money.',
                $lossCount,
            ));
            $mail->line($this->summarise($this->belowCost));
        }

        if ($floorCount > 0) {
            $mail->line(sprintf(
                '%d live product(s) are above cost but under the agreed minimum margin.',
                $floorCount,
            ));
            $mail->line($this->summarise($this->belowFloor));
        }

        if ($this->costFaultCount > 0) {
            $mail->line(sprintf(
                'Also %d product(s) whose margin looks implausible against their pricing rule — usually a wrong COST, which then distorts the floor and the rules themselves. Not alarming on its own; run `pricing:health-check` to review.',
                $this->costFaultCount,
            ));
        }

        return $mail
            ->line('A wrong price can also be a wrong cost or a SKU collision — check the product before changing the price.')
            ->line('Full detail: php artisan pricing:health-check');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function summarise(array $rows): string
    {
        $shown = array_slice($rows, 0, 5);

        $parts = array_map(static fn (array $r): string => sprintf(
            '%s (cost £%s, price £%s)',
            (string) $r['sku'],
            number_format(((int) $r['buy']) / 100, 2),
            number_format(((int) $r['sell']) / 100, 2),
        ), $shown);

        $more = count($rows) - count($shown);

        return implode(', ', $parts).($more > 0 ? sprintf(' … and %d more.', $more) : '');
    }
}
