<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260607-9c6 — H-1 remediation (SECURITY-REVIEW.md 260606-q7h).
 *
 * webhook_receipts.raw_body holds verbatim Woo webhook payloads:
 *   - topic='customer' carries email + billing/shipping/phone PII
 *   - topic='order'    carries the same plus line items + tokenised payment metadata
 *
 * Stored indefinitely with no prune, this is a GDPR Article 5(1)(e) storage-
 * limitation violation. This command auto-deletes rows older than per-topic
 * retention windows nightly at 03:25 London (see routes/console.php).
 *
 * Per-topic defaults (CLI-overridable so a future GDPR DSR can tighten on-demand):
 *   - order    = 30 days  (Woo order topic — tokenised payment metadata + line items)
 *   - customer =  7 days  (TIGHTEST WINDOW — email + billing/shipping/phone PII)
 *   - other    = 90 days  (catch-all for future topic strings; safe-by-default
 *                          because we don't know what future webhook payloads carry)
 *
 * Topic string values match the controller-derived short string
 * (WooWebhookController::order/customer hardcodes 'order' / 'customer').
 * The 'other' bucket is whereNotIn(['order', 'customer']) so unknown future
 * topics inherit the conservative 90d ceiling.
 *
 * Mass ->delete() is intentional — WebhookReceipt does NOT use spatie
 * LogsActivity (verified — model declares no traits beyond Eloquent's defaults).
 * BaseCommand's correlation_id LogBatch wrapper provides the audit thread for
 * the command run itself.
 *
 * Driver-agnostic by construction (no JSON expressions, no LIKE patterns) —
 * same code path runs on prod MySQL and SQLite test DB.
 *
 * Queries are index-covered via the (source, topic, received_at) composite
 * index from the 2026_04_18 migration, so the daily nightly run is cheap
 * even on a large webhook_receipts table.
 */
final class PruneWebhookReceiptsCommand extends BaseCommand
{
    protected $signature = 'webhooks:prune-receipts
        {--order-days=30 : Retention in days for topic=\'order\' (default 30)}
        {--customer-days=7 : Retention in days for topic=\'customer\' (tightest GDPR window)}
        {--other-days=90 : Retention for all other topics}
        {--dry-run : Print per-topic candidate counts, perform no deletes}';

    protected $description = "Prune webhook_receipts rows older than per-topic GDPR retention (H-1 remediation, 260607-9c6).";

    protected function perform(): int
    {
        $orderDays = (int) $this->option('order-days');
        $customerDays = (int) $this->option('customer-days');
        $otherDays = (int) $this->option('other-days');
        $dryRun = (bool) $this->option('dry-run');

        $orderCutoff = now()->subDays($orderDays);
        $customerCutoff = now()->subDays($customerDays);
        $otherCutoff = now()->subDays($otherDays);

        $orderQuery = WebhookReceipt::query()
            ->where('topic', 'order')
            ->where('received_at', '<', $orderCutoff);

        $customerQuery = WebhookReceipt::query()
            ->where('topic', 'customer')
            ->where('received_at', '<', $customerCutoff);

        $otherQuery = WebhookReceipt::query()
            ->whereNotIn('topic', ['order', 'customer'])
            ->where('received_at', '<', $otherCutoff);

        if ($dryRun) {
            $orderCount = (clone $orderQuery)->count();
            $customerCount = (clone $customerQuery)->count();
            $otherCount = (clone $otherQuery)->count();

            $this->info(sprintf(
                'webhooks:prune-receipts — DRY-RUN — would delete: order=%d (cutoff %s), customer=%d (cutoff %s), other=%d (cutoff %s)',
                $orderCount, $orderCutoff->toIso8601String(),
                $customerCount, $customerCutoff->toIso8601String(),
                $otherCount, $otherCutoff->toIso8601String(),
            ));

            return SymfonyCommand::SUCCESS;
        }

        $orderDeleted = $orderQuery->delete();
        $customerDeleted = $customerQuery->delete();
        $otherDeleted = $otherQuery->delete();

        $this->info(sprintf(
            'webhooks:prune-receipts — LIVE — deleted: order=%d, customer=%d, other=%d',
            $orderDeleted, $customerDeleted, $otherDeleted,
        ));

        return SymfonyCommand::SUCCESS;
    }
}
