<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Pricing\Services\PricingOpsReport;
use App\Domain\Sync\Services\SupplierAddCandidateScanner;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * supplier:scan-add-candidates — find parts our suppliers carry that we don't
 * sell yet (≥N distinct suppliers, not in local products) and cache the result
 * for the Pricing Operations dashboard "Products to add" tile.
 *
 * Heavy remote GROUP BY over the full supplier feed, so it runs on a schedule
 * (weekly) + caches; the dashboard reads the cache (never queries the feed on
 * page load). Cache key = PricingOpsReport::ADD_CANDIDATES_CACHE_KEY.
 *
 *   php artisan supplier:scan-add-candidates              (≥2 suppliers)
 *   php artisan supplier:scan-add-candidates --min=3      (stricter)
 */
final class ScanSupplierAddCandidatesCommand extends BaseCommand
{
    protected $signature = 'supplier:scan-add-candidates
        {--min=2 : Minimum distinct suppliers a part must have to qualify}';

    protected $description = 'Cache supplier-carried parts not on meetingstore (≥N suppliers) for the dashboard.';

    public function __construct(private readonly SupplierAddCandidateScanner $scanner)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $min = max(2, (int) $this->option('min'));
        $this->info("Scanning supplier feed for add-candidates (≥{$min} suppliers, not on MS)…");

        try {
            $result = $this->scanner->scan($min);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        Cache::forever(PricingOpsReport::ADD_CANDIDATES_CACHE_KEY, $result);

        $this->info(sprintf(
            'Done. %d add-candidate(s) cached (showing up to %d). Top: %s',
            $result['count'],
            count($result['candidates']),
            $result['candidates'][0]['part'] ?? '—',
        ));

        return SymfonyCommand::SUCCESS;
    }
}
