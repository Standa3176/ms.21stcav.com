<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Services\SupplierSkuRegistry;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Refreshes the cached set of sourceable supplier SKUs used by the
 * "On supplier DB" Filament filter on the Suggestions table.
 *
 * Scheduled post-supplier:db-sync (Mon-Fri ~07:05 London) so the filter
 * reflects today's catalogue. Idempotent — safe to run by hand any time.
 */
final class RefreshSupplierSkuCacheCommand extends BaseCommand
{
    protected $signature = 'supplier:refresh-sku-cache';

    protected $description = 'Refresh the cached set of sourceable supplier SKUs (used by Suggestions filter).';

    public function __construct(private readonly SupplierSkuRegistry $registry)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $count = $this->registry->refresh();
        $this->info("Cached {$count} sourceable supplier SKU keys.");

        return SymfonyCommand::SUCCESS;
    }
}
