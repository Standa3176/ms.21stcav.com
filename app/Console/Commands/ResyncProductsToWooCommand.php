<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Backfill brand-as-tag + regular_price + attributes onto already-published
 * Woo products that were created before the fixes shipped on 2026-05-31.
 *
 * Why this exists: tonight's 26-product batch published with:
 *   - regular_price empty   (sell_price was never set on auto-drafts; fixed
 *                            forward in commit d2cebc5)
 *   - tags missing          (tags column not populated by generate-drafts
 *                            until commit 26e7e01; the batch ran before that)
 *   - brand missing         (we pushed dead `brands: [{id}]` which the WC
 *                            native taxonomy silently dropped — see commit
 *                            884bfdd. Brand surfaces via the TAGS row + the
 *                            attributes "Brand" entry, both of which we can
 *                            re-push without recreating the product)
 *
 * The fix-forward commits prevent the bug for FUTURE batches; this command
 * fixes the existing damaged rows by PUTting a minimal, targeted payload
 * (tags + regular_price + attributes) to each product's Woo id. Status,
 * title, descriptions, images, slug — left untouched (PUT merges, so
 * un-sent keys are preserved).
 *
 * Brand-as-first-tag is enforced even when the local `tags` column is null
 * — we look up the brand name from the cached Woo brand term list and
 * prepend it. If tags ARE populated locally (Claude wrote them post commit
 * 26e7e01), they're used as-is.
 *
 *   php artisan products:resync-to-woo --skus=ABC,DEF              (write)
 *   php artisan products:resync-to-woo --skus=ABC,DEF --dry-run    (preview)
 */
final class ResyncProductsToWooCommand extends BaseCommand
{
    protected $signature = 'products:resync-to-woo
        {--skus= : Comma-separated SKUs of local Products to resync (required)}
        {--dry-run : Print the PUT payload; do NOT write}';

    protected $description = 'Backfill brand-as-tag + regular_price + attributes onto already-published Woo products (fix for pre-2026-05-31 auto-create batches)';

    public function __construct(
        private readonly WooClient $woo,
        private readonly TaxonomyResolver $taxonomy,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        if ($skus === []) {
            $this->error('--skus is required.');

            return SymfonyCommand::FAILURE;
        }

        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Resyncing '.count($skus).' product(s) to Woo.');

        // Cache brand-id → brand-name from the live Woo brand list once.
        // We use this to inject the brand name as the FIRST tag when the
        // local `tags` column is empty (pre commit 26e7e01 drafts).
        $brandNameById = [];
        foreach ($this->taxonomy->allBrands() as $b) {
            $brandNameById[(int) $b['id']] = (string) $b['name'];
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($skus as $sku) {
            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null) {
                $this->warn("  ✗ {$sku}: no local Product — skipped");
                $skipped++;

                continue;
            }
            if ($product->woo_product_id === null) {
                $this->warn("  ✗ {$sku}: never published to Woo (no woo_product_id) — skipped");
                $skipped++;

                continue;
            }

            $payload = $this->buildResyncPayload($product, $brandNameById);
            if ($payload === []) {
                $this->line("  ⊘ {$sku}: nothing to resync (all fields already populated)");
                $skipped++;

                continue;
            }

            $changes = array_keys($payload);
            $this->line("→ {$sku} (Woo #{$product->woo_product_id})  patching: ".implode(', ', $changes));

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $this->woo->put("products/{$product->woo_product_id}", $payload);
                $this->info('  ✓ patched');
                $ok++;
            } catch (\Throwable $e) {
                $this->error('  ✗ Woo error: '.$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — %d patched, %d skipped, %d failed.',
            $dryRun ? 'DRY-RUN complete' : 'Done',
            $ok,
            $skipped,
            $failed,
        ));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Build a minimal PUT payload for a single Product. Only includes fields
     * we want to UPDATE; PUT merges so omitted keys are preserved on Woo.
     *
     * Backfills:
     *   - tags: brand name as first tag (looked up from cached Woo brand
     *     list) + any local tags
     *   - regular_price: from sell_price; if sell_price is null, backfill
     *     with buy_price × 1.4 as a fallback
     *   - attributes: re-push attributes_json (in case it was missed in the
     *     original create)
     *
     * @param  array<int, string>  $brandNameById
     * @return array<string, mixed>
     */
    private function buildResyncPayload(Product $product, array $brandNameById): array
    {
        $payload = [];

        // ── tags: ensure brand is the FIRST tag ──
        $brandName = $product->brand_id !== null
            ? ($brandNameById[(int) $product->brand_id] ?? null)
            : null;
        $localTags = is_array($product->tags) ? $product->tags : [];
        $tags = [];
        $seen = [];
        if ($brandName !== null && $brandName !== '') {
            $tags[] = $brandName;
            $seen[mb_strtolower($brandName)] = true;
        }
        foreach ($localTags as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $k = mb_strtolower($t);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $tags[] = $t;
        }
        if ($tags !== []) {
            $payload['tags'] = array_map(
                static fn (string $name): array => ['name' => $name],
                $tags,
            );
        }

        // ── regular_price: backfill with sell_price, or buy_price × 1.4 ──
        $price = null;
        if ($product->sell_price !== null && (float) $product->sell_price > 0) {
            $price = (float) $product->sell_price;
        } elseif ($product->buy_price !== null && (float) $product->buy_price > 0) {
            $price = round((float) $product->buy_price * 1.4, 2);
            // Also write back to local row so future operations use this anchor.
            $product->forceFill(['sell_price' => $price])->saveQuietly();
        }
        if ($price !== null && $price > 0) {
            $payload['regular_price'] = number_format($price, 2, '.', '');
        }

        // ── attributes: re-push attributes_json (idempotent) ──
        $raw = is_array($product->attributes_json) ? $product->attributes_json : [];
        $byKey = [];
        foreach ($raw as $a) {
            if (! is_array($a)) {
                continue;
            }
            $name = trim((string) ($a['name'] ?? ''));
            $value = trim((string) ($a['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $byKey[mb_strtolower($name)] = ['name' => $name, 'value' => $value];
        }
        $i = 0;
        $attrs = [];
        foreach ($byKey as $entry) {
            $attrs[] = [
                'name' => $entry['name'],
                'options' => [$entry['value']],
                'position' => $i++,
                'visible' => true,
                'variation' => false,
            ];
        }
        if ($attrs !== []) {
            $payload['attributes'] = $attrs;
        }

        return $payload;
    }
}
