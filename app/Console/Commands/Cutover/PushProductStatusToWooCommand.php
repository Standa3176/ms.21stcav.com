<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Cutover step C-NEW — push the LOCAL product status onto Woo so products that
 * `supplier:db-sync --flag-obsolete` demoted to `pending` (no supplier offer)
 * actually leave the live storefront at flip time. The supplier_api path's
 * MarkMissingSkusJob auto-pushes status; the supplier_db path in use does not,
 * so without this command the local status flip stays local-only.
 *
 * Scope: products with a Woo id whose local status matches `--statuses`
 * (default `pending` — the cohort `supplier:db-sync --flag-obsolete` and
 * `products:flag-missing-buy-price` produce; the cohort that's still publish
 * on Woo because no one has pushed status yet). The carve-outs (is_custom_ms,
 * exclude_from_auto_update) mirror what those producers already respect.
 *
 * `--statuses=pending,draft,private` widens the scope if you ever need it.
 * Drafts in particular are usually already drafts on Woo (they were imported
 * that way by woo:import-products) so re-pushing them is a no-op — left out
 * of the default to keep the SyncDiff stream signal-rich.
 *
 * Shadow-safe by design: every write goes through WooClient::put() →
 * writeOrShadow(). Pre-cutover (WOO_WRITE_ENABLED=false) each PUT records a
 * SyncDiff (`shadow_mode=true`); the same run post-cutover does the real PUT.
 * So you can `--live` it during the parity window to eyeball every diff that
 * will fire on flip day, then re-run it AT flip to actually push.
 *
 * Defaults dry-run (no Woo call at all, just lists the targeted rows).
 * `--live` opts in to calling WooClient (which still respects the env flag).
 *
 *   php artisan products:push-status-to-woo                              # dry-run summary
 *   php artisan products:push-status-to-woo --live                       # PUT every row (shadowed pre-flip)
 *   php artisan products:push-status-to-woo --live --limit=10            # cap (smoke test)
 *   php artisan products:push-status-to-woo --live --skus=A,B,C          # scope to specific SKUs
 */
class PushProductStatusToWooCommand extends BaseCommand
{
    protected $signature = 'products:push-status-to-woo
        {--live : Call WooClient::put() (still gated by WOO_WRITE_ENABLED → shadow SyncDiff when false)}
        {--statuses=pending : CSV of local statuses to push (default: pending — the --flag-obsolete + flag-missing-buy-price cohort)}
        {--skus= : CSV scope filter (LOWER(TRIM) match against products.sku)}
        {--limit= : Cap the number of products processed (smoke-test friendly)}';

    protected $description = 'Reconcile local product status onto Woo (cutover step C-NEW; defaults to pending).';

    public function __construct(private readonly WooClient $woo)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $skuFilter = $this->parseSkus((string) ($this->option('skus') ?? ''));
        $statuses = $this->parseSkus((string) ($this->option('statuses') ?? 'pending'));
        if ($statuses === []) {
            $statuses = ['pending'];
        }
        // Never let an operator accidentally push publish via --statuses=publish
        // (would no-op on already-published products + risk un-suppressing pending
        // ones that ARE on Woo as publish — defeats the whole purpose).
        $statuses = array_values(array_filter($statuses, static fn (string $s): bool => $s !== 'publish'));
        if ($statuses === []) {
            $this->error('--statuses cannot be empty (or only "publish", which is rejected).');

            return SymfonyCommand::FAILURE;
        }

        $query = Product::query()
            ->whereNotNull('woo_product_id')
            ->whereIn('status', $statuses)
            ->where(function ($q): void {
                // Mirror supplier:db-sync --flag-obsolete carve-outs — never push
                // status for products the obsolescence flow itself skips.
                $q->where('is_custom_ms', false)->orWhereNull('is_custom_ms');
            })
            ->where(function ($q): void {
                $q->where('exclude_from_auto_update', false)->orWhereNull('exclude_from_auto_update');
            });

        if ($skuFilter !== []) {
            $query->whereIn('sku', $skuFilter);
        }

        $query->orderBy('id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get(['id', 'sku', 'name', 'status', 'woo_product_id']);
        $count = $products->count();
        $mode = $live ? 'LIVE' : 'DRY-RUN';

        $statusList = implode(',', $statuses);
        $this->info("[{$mode}] {$count} product(s) with woo_product_id + local status in [{$statusList}], targeted for reconciliation.");
        if ($count === 0) {
            $this->info('Nothing to push. Done.');

            return SymfonyCommand::SUCCESS;
        }

        if (! $live) {
            $shown = 0;
            foreach ($products as $product) {
                $this->line(sprintf(
                    '  would PUT products/%d {"status":"%s"}  (sku=%s)',
                    (int) $product->woo_product_id,
                    (string) $product->status,
                    (string) $product->sku,
                ));
                $shown++;
                if ($shown >= 20 && $count > 20) {
                    $this->line(sprintf('  …and %d more (use --limit=N to bound).', $count - 20));
                    break;
                }
            }
            $this->info('Dry-run only. Re-run with --live to push (still gated by WOO_WRITE_ENABLED).');

            return SymfonyCommand::SUCCESS;
        }

        $pushedLive = 0;
        $shadowed = 0;
        $errors = 0;

        foreach ($products as $product) {
            $wooId = (int) $product->woo_product_id;
            $localStatus = (string) $product->status;

            try {
                $result = $this->woo->put("products/{$wooId}", ['status' => $localStatus]);
                if (($result['shadow_mode'] ?? false) === true) {
                    $shadowed++;
                } else {
                    $pushedLive++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->warn(sprintf(
                    '  ✗ products/%d (sku=%s, status=%s): %s',
                    $wooId,
                    (string) $product->sku,
                    $localStatus,
                    $e->getMessage(),
                ));
            }
        }

        $this->info(sprintf(
            'Done. live_pushed=%d shadowed=%d errors=%d',
            $pushedLive,
            $shadowed,
            $errors,
        ));
        if ($shadowed > 0) {
            $this->line('Shadowed rows = WOO_WRITE_ENABLED=false (review the sync_diffs table). '
                .'Re-run AT flip with WOO_WRITE_ENABLED=true to push for real.');
        }
        if ($errors === 0 && $shadowed === 0 && $pushedLive > 0) {
            $this->line('Mark the checklist gate: cutover:checklist --update-status=obsolete-statuses-pushed:pass');
        }

        return $errors > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /** @return array<int, string> */
    private function parseSkus(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        ));
    }
}
