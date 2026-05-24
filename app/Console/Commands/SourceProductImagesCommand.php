<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\IcecatClient;
use App\Domain\ProductAutoCreate\Services\ProductImageFetcher;
use App\Domain\ProductAutoCreate\Services\ProductImageProcessor;
use App\Domain\ProductAutoCreate\Services\ProductImageVisionValidator;
use App\Domain\ProductAutoCreate\Services\WebImageSearchClient;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Source + validate product images for local draft Products (operator-driven).
 *
 * For each SKU (must already exist as a local Product — e.g. created by
 * products:generate-drafts):
 *   1. CANDIDATES — gather image URLs from Icecat (by EAN/GTIN, then
 *      Brand+ProductCode) and from any image column on the supplier_db
 *      supplier_products row (auto-detected).
 *   2. FETCH+PROCESS — download each candidate (ProductImageFetcher) and
 *      normalise to ≤1200px WebP (ProductImageProcessor) — same pipeline as
 *      the auto-create image job.
 *   3. VALIDATE — Claude-vision (ProductImageVisionValidator) confirms it is
 *      the correct product and rejects watermarks / overlaid promo text /
 *      competitor branding (physical product branding + on-screen UI are OK).
 *   4. STORE — keep up to --max accepted images on the public disk; set
 *      image_url (primary) + gallery_image_urls (all) + clear
 *      requires_manual_image_review when at least one passes.
 *
 * Review-first: NEVER posts to Woo. Images live locally for the Auto-Create
 * Review inbox until a later publish step.
 *
 *   php artisan products:source-images --skus=ABC,DEF --dry-run   (verdicts only)
 *   php artisan products:source-images --skus=ABC,DEF             (store images)
 *
 * NOTE: --dry-run still calls Icecat + Claude-vision (it shows real verdicts),
 * so it incurs a small Claude spend; it only skips storage + DB writes.
 * NOTE: Icecat image URLs may be IP-restricted — run on the server whose IP is
 * whitelisted in your Icecat account.
 */
final class SourceProductImagesCommand extends BaseCommand
{
    protected $signature = 'products:source-images
        {--skus= : Comma-separated SKUs of existing local Products (required)}
        {--max=3 : Max validated images to keep per product}
        {--candidates=8 : Max candidate images to evaluate per product (cost bound)}
        {--max-spend-pence=2000 : Abort once cumulative Claude spend exceeds this (safety)}
        {--dry-run : Source + validate + print verdicts only; do NOT store or write}';

    protected $description = 'Source product images (Icecat by EAN + supplier feed + web image search), Claude-vision validate (no watermarks/overlays), store up to N per draft Product';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly IcecatClient $icecat,
        private readonly WebImageSearchClient $webSearch,
        private readonly ProductImageFetcher $fetcher,
        private readonly ProductImageProcessor $processor,
        private readonly ProductImageVisionValidator $vision,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $max = max(1, (int) $this->option('max'));
        $candidateCap = max(1, (int) $this->option('candidates'));
        $maxSpendPence = max(0, (int) $this->option('max-spend-pence'));

        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        if ($skus === []) {
            $this->error('--skus is required (comma-separated SKUs).');

            return SymfonyCommand::FAILURE;
        }

        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Sourcing images for '.count($skus).' product(s); keep up to '.$max.' each.');

        $mysqli = $this->connectSupplierDb();
        if ($mysqli === null) {
            return SymfonyCommand::FAILURE;
        }
        $imageColumns = $this->detectSupplierImageColumns($mysqli);
        if ($imageColumns !== []) {
            $this->line('Supplier image column(s) detected: '.implode(', ', $imageColumns));
        } else {
            $this->line('No image-like column found on supplier_products — Icecat only.');
        }

        $totalPence = 0;
        $productsWithImages = 0;

        foreach ($skus as $sku) {
            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null) {
                $this->warn("  {$sku}: no local Product — run products:generate-drafts first; skipped");
                continue;
            }

            $facts = $this->supplierFacts($mysqli, $sku, $imageColumns);
            $brand = (string) ($facts['manufacturer'] ?? '');
            $mpn = (string) ($facts['mpn'] ?? '');
            $ean = (string) ($facts['ean'] ?? '');
            $title = (string) ($product->name ?: ($facts['title'] ?? $sku));

            $this->newLine();
            $this->line("→ <info>{$sku}</info>  {$brand} — ".Str::limit($title, 60));

            // ── Candidate URLs: Icecat (licensed) → supplier feed → web search ──
            $icecatUrls = $this->icecat->fetchImageUrls(
                $ean !== '' ? $ean : null,
                $brand !== '' ? $brand : null,
                $mpn !== '' ? $mpn : null,
                $candidateCap,
            );
            $supplierUrls = $this->supplierImageUrls($facts, $imageColumns);
            $webUrls = $this->webSearch->searchImageUrls(
                $this->searchQuery($brand, $mpn, $title),
                $candidateCap,
                $brand !== '' ? $brand : null,
            );

            $candidates = [];
            foreach ($icecatUrls as $u) {
                $candidates[] = ['url' => $u, 'source' => 'icecat'];
            }
            foreach ($supplierUrls as $u) {
                $candidates[] = ['url' => $u, 'source' => 'supplier'];
            }
            foreach ($webUrls as $u) {
                $candidates[] = ['url' => $u, 'source' => 'web'];
            }
            $candidates = $this->dedupeByUrl($candidates);

            $this->line('  candidates: '.count($icecatUrls).' icecat + '.count($supplierUrls).' supplier + '.count($webUrls).' web ('.count($candidates).' unique)'
                .($ean === '' ? '  [no EAN]' : "  [EAN {$ean}]"));

            if ($candidates === []) {
                $this->warn('  no candidate images — left for manual review');
                continue;
            }

            $kept = [];          // list of ['bytes'=>..., 'source'=>..., 'url'=>...]
            $evaluated = 0;

            foreach ($candidates as $cand) {
                if (count($kept) >= $max || $evaluated >= $candidateCap) {
                    break;
                }
                if ($maxSpendPence > 0 && $totalPence >= $maxSpendPence) {
                    $this->warn("  spend guard hit ({$totalPence}p ≥ {$maxSpendPence}p) — stopping");
                    break;
                }
                $evaluated++;

                $webp = $this->fetchAndProcess($cand['url']);
                if ($webp === null) {
                    $this->line('    ✗ '.$cand['source'].': fetch/decode failed  '.Str::limit($cand['url'], 70));
                    continue;
                }

                $result = $this->vision->validate($webp, [
                    'brand' => $brand,
                    'mpn' => $mpn,
                    'title' => $title,
                ]);
                $totalPence += (int) $result['cost_pence'];

                if ($result['accept'] === true) {
                    $kept[] = ['bytes' => $webp, 'source' => $cand['source'], 'url' => $cand['url']];
                    $this->info('    ✓ '.$cand['source'].': accepted — '.Str::limit((string) $result['reason'], 70));
                } else {
                    $this->line('    ✗ '.$cand['source'].': rejected — '.Str::limit((string) $result['reason'], 70));
                }
            }

            if ($kept === []) {
                $this->warn('  0 images passed validation — left for manual review');
                continue;
            }

            if ($dryRun) {
                $this->info('  would keep '.count($kept).' image(s) [dry-run — not stored]');
                $productsWithImages++;
                continue;
            }

            $urls = $this->storeImages($product, $kept);
            $product->forceFill([
                'image_url' => $urls[0],
                'gallery_image_urls' => $urls,
                'requires_manual_image_review' => false,
            ])->saveQuietly();

            $productsWithImages++;
            $this->info('  ✓ stored '.count($urls).' image(s); primary set, manual-review cleared');
        }

        $mysqli->close();
        $this->newLine();
        $this->info(sprintf(
            '%s — %d/%d product(s) got images, total Claude vision spend %dp (~£%s).',
            $dryRun ? 'DRY-RUN complete' : 'Done',
            $productsWithImages,
            count($skus),
            $totalPence,
            number_format($totalPence / 100, 2),
        ));
        if (! $dryRun && $productsWithImages > 0) {
            $this->line('Review at /admin/auto-create-reviews. Images are local only (no Woo write).');
        }

        return SymfonyCommand::SUCCESS;
    }

    private function connectSupplierDb(): ?\mysqli
    {
        $c = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new \mysqli(
            (string) $c['host'], (string) $c['username'], (string) $c['password'],
            (string) $c['database'], (int) ($c['port'] ?? 3306),
        );
        if ($m->connect_errno !== 0) {
            $this->error('supplier_db connect failed: '.$m->connect_error);

            return null;
        }

        return $m;
    }

    /**
     * Auto-detect image URL columns on supplier_products (image/img/picture/photo).
     *
     * @return array<int, string>
     */
    private function detectSupplierImageColumns(\mysqli $m): array
    {
        $cols = [];
        $res = $m->query('SHOW COLUMNS FROM supplier_products');
        if ($res === false) {
            return [];
        }
        while ($row = $res->fetch_assoc()) {
            $name = (string) ($row['Field'] ?? '');
            if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                continue; // safety: only plain identifiers go into the SELECT
            }
            if (preg_match('/image|img|picture|photo/i', $name) === 1) {
                $cols[] = $name;
            }
        }

        return $cols;
    }

    /**
     * Fetch the supplier_products row (facts + any detected image columns).
     *
     * @param  array<int, string>  $imageColumns
     * @return array<string, mixed>
     */
    private function supplierFacts(\mysqli $m, string $sku, array $imageColumns): array
    {
        $base = ['title', 'manufacturer', 'mpn', 'suppliersku', 'ean'];
        $select = array_merge($base, $imageColumns);
        $list = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $select));

        $stmt = $m->prepare(
            "SELECT {$list}
             FROM supplier_products
             WHERE (suppliersku = ? OR mpn = ?) AND product_excluded = 0
             ORDER BY updated_at DESC LIMIT 1",
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ss', $sku, $sku);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : [];
    }

    /**
     * Pull http(s) image URLs out of the detected supplier image columns
     * (values may be single URLs or delimited lists).
     *
     * @param  array<string, mixed>  $facts
     * @param  array<int, string>  $imageColumns
     * @return array<int, string>
     */
    private function supplierImageUrls(array $facts, array $imageColumns): array
    {
        $out = [];
        foreach ($imageColumns as $col) {
            $raw = trim((string) ($facts[$col] ?? ''));
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/[,|;\s]+/', $raw) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part !== '' && preg_match('#^https?://#i', $part) === 1) {
                    $out[] = $part;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Build the web image-search query. "{brand} {mpn}" is the most precise
     * (e.g. "Sony FW-50EZ20L"); fall back to the product title when MPN is
     * missing.
     */
    private function searchQuery(string $brand, string $mpn, string $title): string
    {
        $brand = trim($brand);
        $mpn = trim($mpn);
        if ($mpn !== '') {
            return trim($brand.' '.$mpn);
        }

        return trim($title) !== '' ? trim($title) : $brand;
    }

    /**
     * @param  array<int, array{url:string, source:string}>  $candidates
     * @return array<int, array{url:string, source:string}>
     */
    private function dedupeByUrl(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $c) {
            $u = $c['url'];
            if (isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $out[] = $c;
        }

        return $out;
    }

    /** Download + normalise one URL to WebP bytes; null on any failure. */
    private function fetchAndProcess(string $url): ?string
    {
        $tmp = $this->fetcher->fetch($url, []);
        if ($tmp === null) {
            return null;
        }
        try {
            return $this->processor->process($tmp);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Store kept WebP images to the public disk; return their public URLs
     * (primary first). Primary keeps the existing `{slug}-main.webp` name so
     * it overwrites any earlier placeholder.
     *
     * @param  array<int, array{bytes:string, source:string, url:string}>  $kept
     * @return array<int, string>
     */
    private function storeImages(Product $product, array $kept): array
    {
        $slug = (string) ($product->slug ?: ('product-'.$product->id));
        $urls = [];
        foreach (array_values($kept) as $i => $img) {
            $name = $i === 0 ? "{$slug}-main.webp" : "{$slug}-".($i + 1).'.webp';
            $path = "auto-create-images/{$name}";
            Storage::disk('public')->put($path, $img['bytes']);
            $urls[] = (string) Storage::disk('public')->url($path);
        }

        return $urls;
    }
}
