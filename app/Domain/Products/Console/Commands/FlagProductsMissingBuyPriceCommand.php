<?php

declare(strict_types=1);

namespace App\Domain\Products\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Flip published Products with missing buy_price to status='pending' so they
 * fall out of the public catalogue until a real cost lands.
 *
 * Replaces the legacy WP plugin's logProductChanges() / handle_pending_product()
 * pair: any product that the supplier feed couldn't price is hidden from sale
 * rather than silently displayed at a stale or zero retail.
 *
 * Skipped:
 *   - tags contains 'custom-ms' — bespoke MeetingStore items are priced by hand
 *   - sku is in product_exceptions (active, NOT paused) — operator-managed
 *     allowlist for in-house assembly / non-integrated vendors / strategic
 *     loss-leaders. Managed via Filament at /admin/product-exceptions.
 *     Added 2026-06-02 as the structured successor to the custom-ms tag
 *     (both paths still honored — operator can migrate at their pace).
 *   - status already != 'publish' — we only demote publish→pending; never touch
 *     draft/private/trash
 *
 * Schedule: routes/console.php registers this Mon-Fri at 07:15 Europe/London,
 * 15 minutes after supplier:db-sync so today's buy_price is current.
 */
final class FlagProductsMissingBuyPriceCommand extends BaseCommand
{
    protected $signature = 'products:flag-missing-buy-price
        {--dry-run : Report what would flip without writing}';

    protected $description = 'Flip published Products with NULL/zero buy_price to status=pending (skips custom-ms tagged).';

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Active exception SKUs (operator-managed allowlist). Looked up once
        // up-front into a hashmap so the per-product check is O(1). Paused
        // rows are EXCLUDED here on purpose — pausing intentionally lets the
        // sync demote the SKU again (non-destructive "temporarily disable"
        // toggle).
        $exceptionSkus = ProductException::query()
            ->active()
            ->pluck('sku')
            ->mapWithKeys(fn (string $sku) => [trim($sku) => true])
            ->all();

        $candidates = Product::query()
            ->where('status', 'publish')
            ->where(function ($q) {
                $q->whereNull('buy_price')->orWhere('buy_price', '<=', 0);
            })
            ->get(['id', 'sku', 'name', 'tags', 'buy_price']);

        $flipped = 0;
        $skippedCustom = 0;
        $skippedException = 0;

        foreach ($candidates as $product) {
            $tags = (array) ($product->tags ?? []);
            if (in_array('custom-ms', $tags, true)) {
                $skippedCustom++;

                continue;
            }

            if (isset($exceptionSkus[trim((string) $product->sku)])) {
                $skippedException++;

                continue;
            }

            if (! $dryRun) {
                Product::where('id', $product->id)->update(['status' => 'pending']);
            }
            $flipped++;
        }

        $this->info(sprintf(
            'products:flag-missing-buy-price — %s candidates=%d %s=%d skipped_custom_ms=%d skipped_exception=%d',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $candidates->count(),
            $dryRun ? 'would_flip' : 'flipped',
            $flipped,
            $skippedCustom,
            $skippedException,
        ));

        return SymfonyCommand::SUCCESS;
    }
}
