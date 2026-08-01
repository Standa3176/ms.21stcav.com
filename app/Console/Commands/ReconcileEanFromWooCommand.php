<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\NormalisesEan;
use App\Console\Support\Sleeper;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260726-egr — products:reconcile-ean-from-woo (READ Woo, WRITE local only).
 *
 * Local `products.ean` drifted from Woo's real GTIN (`global_unique_id`).
 * Proven on prod 2026-07-26: A30-020 local ean `6938820000000` (13 digits but
 * FAILS the EAN-13 check digit) vs Woo `0841885115294` (valid). Root cause: the
 * shared NormalisesEan trait length-checked but did NO check-digit validation,
 * so precision-mangled values passed as "valid". The Merchant feed reads local
 * `products.ean`, so bad local values would get products disapproved by Google
 * even though Woo holds the correct GTIN.
 *
 * This command is the REVERSE of WooGtinPublisher: it READS Woo's GTIN and pulls
 * it back into the LOCAL columns (`products.ean` + `products.woo_gtin`),
 * checksum-gated. It is READ-ONLY against Woo — it NEVER calls WooClient
 * put/post/patch/delete, never touches the storefront, and does NOT depend on
 * WOO_WRITE_ENABLED (that flag gates Woo WRITES, not local ones).
 *
 * ── Verdicts (per product) ──
 *   FIX               local empty/invalid + Woo valid  → set local ean + woo_gtin
 *   CONFLICT          both valid but differ            → report only, NEVER change
 *   in_sync           both valid and match             → skip
 *   no_valid_woo_gtin Woo GTIN empty/invalid           → can't fix from Woo
 *   read_failed       Woo GET failed all retries       → leave local untouched
 *
 * ── Scope ──
 *   default   products with a woo_product_id whose local ean is EMPTY or fails
 *             the checksum (the clearly-broken set), restricted to simple+publish.
 *   --skus    exactly the named SKUs (with a woo_product_id), any validity.
 *   --all     every simple+publish product with a woo_product_id (MANY Woo reads
 *             on a flaky endpoint — uses the retry+pacing below).
 *
 * ── Dry-run by default ── only --apply writes, and only LOCAL columns.
 *
 * ── Read resilience (flaky endpoint, 260726-slw lesson) ── each Woo GET is
 * wrapped in retry-with-backoff (--read-retries / --read-backoff-ms, exponential)
 * with gentle pacing between reads. A read that fails every attempt is counted as
 * read_failed and the local row is left untouched — never "fixed" from a failed read.
 *
 * ── Example (prod) ──
 *   php artisan products:reconcile-ean-from-woo --csv=storage/app/ean-reconcile.csv   # dry-run
 *   # review conflicts, then:
 *   php artisan products:reconcile-ean-from-woo --apply
 *
 * Not `final` so the Pest feature test can swap the bound WooClient stub without
 * subclassing the command (mirrors BackfillCategoryFromWooCommand).
 */
class ReconcileEanFromWooCommand extends BaseCommand
{
    use NormalisesEan;

    /** Gentle pacing between consecutive Woo reads (ms) — self-DoS guard on the shared box. */
    private const READ_PACE_MS = 200;

    /** CSV header — mirrored by the command test. */
    private const CSV_HEADER = [
        'sku', 'woo_id', 'local_ean', 'woo_gtin', 'local_valid', 'woo_valid', 'verdict',
    ];

    protected $signature = 'products:reconcile-ean-from-woo
        {--skus= : Comma-separated SKU list to target specifically (any validity)}
        {--all : Scan every simple+publish product with a woo_product_id (many Woo reads on a flaky endpoint)}
        {--apply : Write local columns (ean + woo_gtin). Default is dry-run.}
        {--csv= : Write per-product verdict rows to this CSV path}
        {--read-retries=4 : Woo GET retry attempts after the first, on transient failure}
        {--read-backoff-ms=3000 : Base backoff between read retries (exponential: base, 2x, 4x…)}';

    protected $description = 'Reconcile local products.ean from Woo global_unique_id (checksum-gated). READS Woo, WRITES local only — never touches the storefront.';

    public function __construct(
        private readonly WooClient $woo,
        private readonly Sleeper $sleeper,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $skusFilter = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('skus'))),
            static fn (string $s): bool => $s !== '',
        ));
        $all = (bool) $this->option('all');
        $apply = (bool) $this->option('apply');
        $csvPath = $this->option('csv') !== null ? trim((string) $this->option('csv')) : '';
        $readRetries = max(0, (int) $this->option('read-retries'));
        $readBackoffMs = max(0, (int) $this->option('read-backoff-ms'));

        $this->newLine();
        $this->line('── products:reconcile-ean-from-woo — pull Woo GTIN → local (checksum-gated) ──');
        $this->line('  READS Woo global_unique_id · WRITES local products.ean + woo_gtin only · never touches the storefront.');
        $this->line('  mode: '.($apply ? 'APPLY (writes local columns)' : 'DRY-RUN (no writes)'));

        // Build the candidate query.
        if ($skusFilter !== []) {
            $query = Product::query()
                ->whereNotNull('woo_product_id')
                ->whereIn('sku', $skusFilter);
        } else {
            // Default + --all both scope to simple+publish with a Woo id.
            $query = Product::query()
                ->where('type', 'simple')
                ->where('status', 'publish')
                ->whereNotNull('woo_product_id');

            if ($all) {
                $this->warn('  --all: scanning EVERY simple+publish product with a woo_product_id — this is many reads on a flaky endpoint.');
            }
        }

        // Whether we PHP-filter to the broken set. Only the default scope (no
        // --skus, no --all) narrows to empty/invalid-local rows; --skus and
        // --all read every candidate so their verdicts (in_sync/conflict/…) show.
        $brokenSetOnly = ($skusFilter === [] && ! $all);

        $counts = [
            'scanned' => 0,
            'fixed' => 0,
            'conflicts' => 0,
            'no_valid_woo_gtin' => 0,
            'in_sync' => 0,
            'read_failed' => 0,
        ];

        /** @var array<int, array<int, string>> $csvRows */
        $csvRows = [];
        /** @var array<int, array<int, string>> $fixTable */
        $fixTable = [];
        /** @var array<int, array<int, string>> $conflictTable */
        $conflictTable = [];

        $firstRead = true;

        foreach ($query->cursor() as $product) {
            $sku = (string) $product->sku;
            $wooId = (int) ($product->woo_product_id ?? 0);
            if ($sku === '' || $wooId <= 0) {
                continue;
            }

            $localEan = trim((string) ($product->ean ?? ''));
            $localValid = $localEan !== '' && $this->isValidGtinChecksum($localEan);

            // Default scope: skip rows whose local ean is already valid.
            if ($brokenSetOnly && $localValid) {
                continue;
            }

            // Gentle pacing between reads (skip before the very first).
            if (! $firstRead && self::READ_PACE_MS > 0) {
                $this->sleeper->micros(self::READ_PACE_MS * 1000);
            }
            $firstRead = false;

            $counts['scanned']++;

            $read = $this->readWooGtin($wooId, $readRetries, $readBackoffMs);
            if (! $read['ok']) {
                $counts['read_failed']++;
                $csvRows[] = [$sku, (string) $wooId, $localEan, '', $localValid ? 'yes' : 'no', 'no', 'read_failed'];

                continue;
            }

            $wooGtin = $read['gtin'];
            $wooValid = $wooGtin !== '' && $this->isValidGtinChecksum($wooGtin);

            $verdict = $this->verdict($localEan, $localValid, $wooGtin, $wooValid);

            $csvRows[] = [
                $sku, (string) $wooId, $localEan, $wooGtin,
                $localValid ? 'yes' : 'no', $wooValid ? 'yes' : 'no', $verdict,
            ];

            switch ($verdict) {
                case 'fix':
                    $counts['fixed']++;
                    $fixTable[] = [$sku, (string) $wooId, $localEan === '' ? '(empty)' : $localEan, $wooGtin, $apply ? 'written' : 'would-fix'];
                    if ($apply) {
                        // LOCAL columns ONLY — never a Woo write, no WOO_WRITE_ENABLED dependency.
                        $product->forceFill(['ean' => $wooGtin, 'woo_gtin' => $wooGtin])->saveQuietly();
                    }
                    break;

                case 'conflict':
                    $counts['conflicts']++;
                    $conflictTable[] = [$sku, (string) $wooId, $localEan, $wooGtin, 'left unchanged'];
                    break;

                case 'in_sync':
                    $counts['in_sync']++;
                    break;

                case 'no_valid_woo_gtin':
                    $counts['no_valid_woo_gtin']++;
                    break;
            }
        }

        $this->renderSummary($counts, $apply);

        if ($fixTable !== []) {
            $this->newLine();
            $this->line('── Fixes'.($apply ? '' : ' (would apply — dry-run)').' ──');
            $this->table(['SKU', 'Woo ID', 'Local ean', 'Woo GTIN', 'Status'], array_slice($fixTable, 0, 50));
            if (count($fixTable) > 50) {
                $this->line(sprintf('  … and %d more (see --csv for the full list).', count($fixTable) - 50));
            }
        }

        if ($conflictTable !== []) {
            $this->newLine();
            $this->line('── Conflicts (both valid, differ — NEED HUMAN JUDGEMENT, never auto-changed) ──');
            $this->table(['SKU', 'Woo ID', 'Local ean', 'Woo GTIN', 'Action'], array_slice($conflictTable, 0, 50));
            if (count($conflictTable) > 50) {
                $this->line(sprintf('  … and %d more (see --csv for the full list).', count($conflictTable) - 50));
            }
        }

        if ($csvPath !== '') {
            $written = $this->writeCsv($csvPath, $csvRows);
            $this->newLine();
            $this->info("CSV written: {$written}  ({$this->plural(count($csvRows), 'row')})");
        }

        if (! $apply && $counts['fixed'] > 0) {
            $this->newLine();
            $this->line('  Dry-run — re-run with --apply to write the '.$counts['fixed'].' fix(es) to LOCAL columns (safe: no storefront impact).');
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Decide the verdict for one product from the local + Woo GTIN state.
     *
     * @return 'fix'|'conflict'|'in_sync'|'no_valid_woo_gtin'
     */
    private function verdict(string $localEan, bool $localValid, string $wooGtin, bool $wooValid): string
    {
        if (! $wooValid) {
            // Can't fix from Woo — Woo has nothing valid to copy back.
            return 'no_valid_woo_gtin';
        }

        if (! $localValid) {
            // Local empty/invalid + Woo valid → the clearly-fixable case.
            return 'fix';
        }

        // Both valid.
        return $localEan === $wooGtin ? 'in_sync' : 'conflict';
    }

    /**
     * Read Woo's global_unique_id for one product with retry-with-backoff.
     *
     * A transient non-JSON/timeout read on the flaky endpoint must RETRY, not
     * silently skip (260726-slw lesson). All attempts exhausted → ['ok'=>false]
     * and the caller leaves the local row untouched (read_failed).
     *
     * @return array{ok:bool, gtin:string, error:?string}
     */
    private function readWooGtin(int $wooId, int $retries, int $backoffMs): array
    {
        $attempts = $retries + 1; // retries are IN ADDITION to the first attempt
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->woo->get("products/{$wooId}");
                $gtin = trim((string) ($response['global_unique_id'] ?? ''));

                return ['ok' => true, 'gtin' => $gtin, 'error' => null];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $attempts && $backoffMs > 0) {
                    // Exponential: base, 2x, 4x, 8x…
                    $delayMs = $backoffMs * (2 ** ($attempt - 1));
                    $this->sleeper->micros($delayMs * 1000);
                }
            }
        }

        return ['ok' => false, 'gtin' => '', 'error' => $lastError];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function renderSummary(array $counts, bool $apply): void
    {
        $this->newLine();
        $this->line('── Summary ──');
        $this->table(
            ['Metric', 'Count'],
            [
                ['scanned', $counts['scanned']],
                [$apply ? 'fixed' : 'would_fix', $counts['fixed']],
                ['conflicts', $counts['conflicts']],
                ['no_valid_woo_gtin', $counts['no_valid_woo_gtin']],
                ['in_sync', $counts['in_sync']],
                ['read_failed', $counts['read_failed']],
            ],
        );

        // Emit the raw verdict tokens on their own lines so operators (and the
        // feature test) can grep every bucket unambiguously.
        $this->line('  verdicts: FIX='.$counts['fixed']
            .' · CONFLICT='.$counts['conflicts']
            .' · in_sync='.$counts['in_sync']
            .' · no_valid_woo_gtin='.$counts['no_valid_woo_gtin']
            .' · read_failed='.$counts['read_failed']);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeCsv(string $path, array $rows): string
    {
        $resolved = $this->resolvePath($path);
        File::ensureDirectoryExists(dirname($resolved));

        $handle = fopen($resolved, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV for writing: {$resolved}");
        }

        fputcsv($handle, self::CSV_HEADER);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $resolved;
    }

    private function resolvePath(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($normalised, '/')
            || preg_match('/^[A-Za-z]:\//', $normalised) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    private function plural(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }
}
