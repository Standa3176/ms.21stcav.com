<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductSupplierSku;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260825-z8q — where did this product's cost come from?
 *
 * READ-ONLY. Prints evidence; changes nothing, and deliberately does not
 * recommend a fix, because the two candidate causes need opposite responses and
 * only the operator can see the physical part.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE QUESTION IT ANSWERS
 *
 * CP4 on prod 2026-08-25: cost GBP 24.96 against a GBP 1,517.99 selling price —
 * 4,968% before any competitor row is involved, and our price AGREES with the
 * competitor's GBP 1,748.39. So the COST is the outlier, and there are two ways
 * a wrong one arrives:
 *
 *   ALIAS MISMATCH   An alternative supplier code (260823-clp) matched this
 *                    product to a cheaper part's feed row. SupplierOfferSnapshot
 *                    records the code an offer was FOUND UNDER, so a found-under
 *                    value that is not the product's own SKU is the fingerprint.
 *                    Fix: delete the offending ProductSupplierSku row. Aliases
 *                    are additive, so removal is clean and the next
 *                    supplier:db-sync re-derives cost from the product's own SKU.
 *
 *   FEED ERROR       The supplier genuinely lists that code at that price. Fix
 *                    is a conversation with the supplier, not a data change.
 *
 * resolveMatchKey() (SupplierDbSyncCommand) guarantees an alias can never
 * REPLACE an existing cost — it is consulted only when the product is otherwise
 * unmatched. It can still ESTABLISH one where there was none, which is the gap
 * this command exists to expose.
 *
 *   php artisan pricing:cost-fault-evidence --skus=CP4
 *   php artisan pricing:cost-fault-evidence --skus=CP4,83Z50AA#ABB,772C8AA
 */
final class CostFaultEvidenceCommand extends BaseCommand
{
    private const OFFER_ROWS = 6;

    protected $signature = 'pricing:cost-fault-evidence
        {--skus= : Comma-separated SKUs to investigate (required)}
        {--offers=6 : Recent supplier offer snapshots to show per product}';

    protected $description = 'READ-ONLY cost provenance for a suspected cost fault: aliases + which code each supplier offer was found under (260825-z8q).';

    protected function perform(): int
    {
        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        if ($skus === []) {
            $this->error('--skus is required.');

            return SymfonyCommand::FAILURE;
        }

        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $offerRows = max(1, (int) $this->option('offers'));

        foreach ($skus as $sku) {
            $this->investigate($sku, $vatBps, $offerRows);
        }

        $this->newLine();
        $this->line('An offer FOUND UNDER a code that is not the product\'s own SKU means the');
        $this->line('cost arrived through an alias — check that row\'s source and confidence.');
        $this->line('Found under the product\'s own SKU means the supplier feed itself carries');
        $this->line('that price, and the fix is with the supplier, not in this database.');

        return SymfonyCommand::SUCCESS;
    }

    private function investigate(string $sku, int $vatBps, int $offerRows): void
    {
        $this->newLine();
        $product = Product::where('sku', $sku)->first();

        if ($product === null) {
            $this->warn("── {$sku} — no local product with that SKU.");

            return;
        }

        $buy = (int) round(((float) $product->buy_price) * 100);
        $sell = (int) round(((float) $product->sell_price) * 100);
        $currentBps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);

        $this->info(sprintf('── %s  (product #%d, %s)', $sku, (int) $product->id, (string) $product->status));
        $this->line(sprintf(
            '   cost £%s   price £%s   margin our price already earns: %s',
            number_format($buy / 100, 2),
            number_format($sell / 100, 2),
            $currentBps === null ? 'n/a' : number_format($currentBps / 100, 1).'%',
        ));

        $this->renderAliases((int) $product->id);
        $this->renderOffers($product, $sku, $offerRows);
    }

    private function renderAliases(int $productId): void
    {
        $aliases = ProductSupplierSku::where('product_id', $productId)->get();

        if ($aliases->isEmpty()) {
            $this->line('   aliases: none recorded — an alias cannot be the source here.');

            return;
        }

        $this->line('   aliases:');
        $this->table(
            ['Alternative SKU', 'Normalised', 'Supplier', 'Source', 'Confidence', 'Notes'],
            $aliases->map(static fn (ProductSupplierSku $a): array => [
                (string) $a->supplier_sku,
                (string) $a->normalised_sku,
                (string) ($a->supplier_id ?? 'any'),
                (string) $a->source,
                (string) $a->confidence,
                substr((string) $a->notes, 0, 44),
            ])->all(),
        );
    }

    private function renderOffers(Product $product, string $sku, int $offerRows): void
    {
        $offers = SupplierOfferSnapshot::where('product_id', $product->id)
            ->orderByDesc('recorded_at')
            ->limit($offerRows)
            ->get();

        if ($offers->isEmpty()) {
            $this->line('   offers: none recorded.');

            return;
        }

        $own = strtolower(trim($sku));

        $this->line('   recent supplier offers:');
        $this->table(
            ['Recorded', 'Found under', 'Supplier', 'Price', 'Stock', 'Matches own SKU?'],
            $offers->map(static function (SupplierOfferSnapshot $o) use ($own): array {
                $found = strtolower(trim((string) $o->sku));

                return [
                    $o->recorded_at?->format('Y-m-d') ?? '-',
                    (string) $o->sku,
                    (string) $o->supplier_name,
                    number_format((float) $o->price, 2),
                    (string) $o->stock,
                    // The fingerprint: "no" means the cost came in through an
                    // alternative code, not the product's own identity.
                    $found === $own ? 'yes' : 'NO — via alias',
                ];
            })->all(),
        );
    }
}
