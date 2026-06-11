<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260611-f1y — products:push-visibility-to-woo.
 *
 * Pushes catalog_visibility=hidden to Woo for every Product flagged
 * is_internal_only=true. Idempotent (pre-GET checks current state,
 * skips PUT if already hidden). Operator-triggered — NOT scheduled.
 *
 * Seeded internals (woo_product_id):
 *   167493 — Credit
 *   167492 — Offer
 *   165038 — Quote Payment (No Vat)
 *
 * Future internals: operator flips the Filament toggle on the Product
 * Edit page; the toggle's afterSave calls this command with
 * --skus={sku|woo_id} synchronously.
 *
 * Single-field PUT: {'catalog_visibility': 'hidden'}. The split-PUT
 * WAF workaround in ResyncProductsToWooCommand (260530-clv) is specific
 * to the Cost-of-Goods plugin's regular_price recompute hook and does
 * NOT apply here (catalog_visibility doesn't trigger that hook).
 *
 * Drift-prevention contract: `app/Domain/Cutover/Services/WooFieldComparator.php`
 * does NOT compare catalog_visibility — out of scope for the 260610-qc4
 * 13-field parity check. The 3 seeded SKUs will not surface as sync_diffs
 * after the push. If a future quick task ever adds catalog_visibility to
 * the comparator, it MUST teach the comparator to treat
 * `is_internal_only=true` rows as expected-hidden (otherwise the 3 SKUs
 * will flood the divergence scan).
 *
 *   php artisan products:push-visibility-to-woo --dry-run
 *   php artisan products:push-visibility-to-woo --skus=Credit,Offer
 *   php artisan products:push-visibility-to-woo --skus=167493
 *   php artisan products:push-visibility-to-woo
 */
// Not `final` so the Pest feature test can swap WooClient through the
// container without subclassing the command itself.
class PushVisibilityToWooCommand extends BaseCommand
{
    protected $signature = 'products:push-visibility-to-woo
        {--skus= : Comma-separated SKU OR woo_product_id list; default = all is_internal_only=true products with woo_product_id. Numeric tokens match woo_product_id; non-numeric match sku.}
        {--limit=0 : Max products this run (0=unbounded)}
        {--dry-run : Print plan without Woo writes (and without Woo GETs)}';

    protected $description = 'Push catalog_visibility=hidden to Woo for is_internal_only products (260611-f1y).';

    public function __construct(private readonly WooClient $woo)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // Parse options.
        $skusFilter = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('skus'))),
            static fn (string $s): bool => $s !== '',
        ));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // Build candidate query.
        //
        // Default: only is_internal_only=true products with a woo link.
        //
        // With --skus: drop the is_internal_only filter so the operator can
        // force-push ad-hoc SKUs (or woo_ids). Numeric tokens match
        // woo_product_id (covers the empty-SKU internals like Credit/Offer);
        // non-numeric tokens match sku.
        if ($skusFilter !== []) {
            $numeric = array_values(array_filter($skusFilter, 'ctype_digit'));
            $alpha = array_values(array_diff($skusFilter, $numeric));

            $query = Product::query()
                ->where(function ($q) use ($numeric, $alpha): void {
                    if ($alpha !== []) {
                        $q->orWhereIn('sku', $alpha);
                    }
                    if ($numeric !== []) {
                        $q->orWhereIn('woo_product_id', array_map('intval', $numeric));
                    }
                });
        } else {
            $query = Product::query()
                ->where('is_internal_only', true)
                ->whereNotNull('woo_product_id');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        // Counters.
        $scanned = 0;
        $hidden = 0;
        $alreadyHidden = 0;
        $errors = 0;
        $noWooId = 0;
        $wouldHide = 0;

        $this->info(($dryRun ? '[dry-run] ' : '[LIVE] ').'products:push-visibility-to-woo — starting');

        foreach ($query->cursor() as $product) {
            $scanned++;
            $sku = (string) ($product->sku ?? '');
            $wooId = (int) ($product->woo_product_id ?? 0);

            if ($wooId <= 0) {
                $noWooId++;
                $this->warn("  no_woo_product_id sku={$sku} — operator data issue, skipping");

                continue;
            }

            if ($dryRun) {
                $wouldHide++;
                $this->line("  would_hide woo={$wooId} sku={$sku}");

                continue;
            }

            // Pre-GET — idempotency check.
            try {
                $current = $this->woo->get("products/{$wooId}");
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  error (GET) woo={$wooId} sku={$sku}: {$e->getMessage()}");

                continue;
            }

            $visibility = (string) ($current['catalog_visibility'] ?? 'visible');

            if ($visibility === 'hidden') {
                $alreadyHidden++;
                $this->line("  already_hidden woo={$wooId} sku={$sku}");

                continue;
            }

            // Single-field PUT — catalog_visibility doesn't trigger the
            // Cost-of-Goods recompute hook so no split-PUT WAF workaround.
            try {
                $this->woo->put("products/{$wooId}", ['catalog_visibility' => 'hidden']);
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  error (PUT) woo={$wooId} sku={$sku}: {$e->getMessage()}");

                continue;
            }

            $hidden++;
            $this->line("  hidden woo={$wooId} sku={$sku}");

            // Match BackfillCategoryFromWooCommand pacing — 200ms between
            // live Woo calls. Skipped on errors/already_hidden so a no-op
            // run is fast.
            usleep(200_000);
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['scanned', $scanned],
                [$dryRun ? 'would_hide' : 'hidden', $dryRun ? $wouldHide : $hidden],
                ['already_hidden', $alreadyHidden],
                ['no_woo_product_id', $noWooId],
                ['errors', $errors],
            ],
        );

        // Per-candidate failures are reported, not fatal — caller drives
        // next-action from the summary.
        return SymfonyCommand::SUCCESS;
    }
}
