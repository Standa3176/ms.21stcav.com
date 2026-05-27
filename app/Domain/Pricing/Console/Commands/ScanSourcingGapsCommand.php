<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Pricing\Services\PricingOpsReport;
use App\Domain\Pricing\Services\SourcingGapScanner;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * pricing:scan-sourcing-gaps — find parts a competitor lists that NO supplier
 * carries and we don't sell (likely obsolete, or we need to find a supplier),
 * and cache the result for the Pricing Operations dashboard "Sourcing gaps" tile.
 *
 * Heavy remote scan over the full supplier feed (SourcingGapScanner delegates the
 * feed read to the Sync SupplierFeedSourceabilityChecker), so it runs on a
 * schedule (weekly) + caches; the dashboard reads the cache (never queries the
 * feed on page load). Cache key = PricingOpsReport::SOURCING_GAPS_CACHE_KEY.
 *
 *   php artisan pricing:scan-sourcing-gaps               (30-day competitor window)
 *   php artisan pricing:scan-sourcing-gaps --days=14     (tighter window)
 */
final class ScanSourcingGapsCommand extends BaseCommand
{
    protected $signature = 'pricing:scan-sourcing-gaps
        {--days=30 : How recent a competitor price must be to count (max age in days)}';

    protected $description = 'Cache competitor-listed parts no supplier carries (sourcing gaps) for the dashboard.';

    public function __construct(private readonly SourcingGapScanner $scanner)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $days = max(1, (int) $this->option('days'));
        $this->info("Scanning for sourcing gaps (competitor-listed, no supplier, ≤{$days}d competitor prices)…");

        try {
            $result = $this->scanner->compute($days);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        Cache::forever(PricingOpsReport::SOURCING_GAPS_CACHE_KEY, $result);

        $this->info(sprintf(
            'Done. %d sourcing gap(s) cached (showing up to %d). Top: %s',
            $result['count'],
            count($result['gaps']),
            $result['gaps'][0]['part'] ?? '—',
        ));

        return SymfonyCommand::SUCCESS;
    }
}
