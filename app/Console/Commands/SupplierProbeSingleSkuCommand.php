<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Services\SupplierClient;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Phase 6 Plan 01 Task 1 — Q1 supplier-API probe (RESEARCH §Open Questions Q1).
 *
 * Calls SupplierClient::fetchSingleProduct($sku) + dumps the FULL decoded
 * supplier row to storage/app/research/supplier-probe.json. The probe output
 * is the gating dependency for Plan 06-02's ProductImageFetcher — it reveals
 * the actual image_url / image_fallback_urls / brand / category / description
 * field shape the supplier returns (which fetchAllProducts() discards).
 *
 * Extends BaseCommand (Phase 1 Plan 03) so correlation_id threads through
 * Context + LogBatch the same way sync:supplier does — supplier.GET call
 * ends up in integration_events with the same correlation_id as the stdout
 * log line.
 *
 * Usage:
 *   php artisan supplier:probe-single-sku ABC-123
 *
 * Output:
 *   - stdout: "Probe response written to: storage/app/research/supplier-probe.json"
 *   - file: storage/app/research/supplier-probe.json (pretty-printed JSON)
 */
final class SupplierProbeSingleSkuCommand extends BaseCommand
{
    protected $signature = 'supplier:probe-single-sku {sku : Supplier SKU to probe}';

    protected $description = 'Phase 6 Q1 probe — fetch full supplier record for a single SKU and dump to storage/app/research/supplier-probe.json';

    public function __construct(
        private readonly SupplierClient $client,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $sku = (string) $this->argument('sku');

        if ($sku === '') {
            $this->error('SKU argument is required.');

            return SymfonyCommand::FAILURE;
        }

        $this->info("Probing supplier for SKU: {$sku}");

        $row = $this->client->fetchSingleProduct($sku);

        if ($row === []) {
            $this->warn("Supplier returned no data for SKU: {$sku}");
        }

        $json = json_encode(
            $row,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $this->error('Failed to JSON-encode supplier response.');

            return SymfonyCommand::FAILURE;
        }

        // Write to storage/app/research/ (NOT storage/app/private/research — the
        // `local` disk's root is storage/app/private, but the research probe
        // lives a level up at storage/app/research so ops can grep + commit
        // reference outputs alongside .gitkeep without wading into the
        // auto-generated private/ tree).
        $dir = storage_path('app/research');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }
        $relative = 'storage/app/research/supplier-probe.json';
        $absolute = $dir.DIRECTORY_SEPARATOR.'supplier-probe.json';
        if (file_put_contents($absolute, $json) === false) {
            $this->error("Failed to write probe output to {$absolute}");

            return SymfonyCommand::FAILURE;
        }

        $this->info("Probe response written to: {$relative}");
        $this->line("(absolute: {$absolute})");

        return SymfonyCommand::SUCCESS;
    }
}
