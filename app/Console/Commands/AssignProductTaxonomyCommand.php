<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agents\Clients\ClaudeClient;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Auto-assign brand + category term IDs to draft Products (operator-driven).
 *
 * For each SKU:
 *   - BRAND  — fuzzy-match the supplier_db manufacturer (fallback: the first
 *     token of the product name) against the live Woo brand terms.
 *   - CATEGORY — ask Claude to pick the single best-fit category from the
 *     ACTUAL live Woo category list (handles use-case taxonomies like
 *     "Small Rooms (4-6)" that blind string-matching can't), then map the
 *     chosen name → term id.
 *
 * When BOTH resolve, auto_create_status flips draft ← needs_brand_or_category_assignment.
 * Writes via forceFill+saveQuietly so it NEVER disturbs content or images.
 * NEVER posts to Woo (read-only taxonomy lookups).
 *
 *   php artisan products:assign-taxonomy --skus=ABC,DEF --dry-run   (preview)
 *   php artisan products:assign-taxonomy --skus=ABC,DEF             (assign)
 */
final class AssignProductTaxonomyCommand extends BaseCommand
{
    protected $signature = 'products:assign-taxonomy
        {--skus= : Comma-separated SKUs of existing local Products (required)}
        {--dry-run : Resolve + print choices only; do NOT write}';

    protected $description = 'Auto-assign Woo brand + category term IDs to draft Products (brand=supplier manufacturer fuzzy-match; category=Claude pick from live Woo list)';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
        private readonly ClaudeClient $claude,
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
            $this->error('--skus is required (comma-separated SKUs).');

            return SymfonyCommand::FAILURE;
        }

        $categories = $this->taxonomy->allCategories();
        $brands = $this->taxonomy->allBrands();
        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Assigning taxonomy for '.count($skus).' product(s).');
        $this->line('Live Woo taxonomy: '.count($categories).' categories, '.count($brands).' brand terms.');

        if ($categories === []) {
            $this->warn('No Woo categories returned — check the WooCommerce REST credential. Aborting.');

            return SymfonyCommand::FAILURE;
        }
        $categoryNames = array_map(static fn (array $c): string => $c['name'], $categories);

        $manufacturers = $this->supplierManufacturers($skus);
        $totalPence = 0;
        $assigned = 0;

        foreach ($skus as $sku) {
            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null) {
                $this->warn("  {$sku}: no local Product — skipped");
                continue;
            }

            $manufacturer = $manufacturers[$sku] ?? $this->brandFromName((string) $product->name);
            $brandId = $this->taxonomy->resolveBrand($manufacturer !== '' ? $manufacturer : null);

            [$categoryNamesChosen, $costPence] = $this->pickCategories($product, $categoryNames);
            $totalPence += $costPence;

            // Map each chosen name → Woo term id (dedup, preserve order). The
            // FIRST is the primary (drives single-valued pricing rules); the full
            // set goes to category_ids for the Woo categories[] payload.
            $categoryIds = [];
            $resolvedNames = [];
            foreach ($categoryNamesChosen as $cn) {
                $cid = $this->taxonomy->categoryIdByName($cn);
                if ($cid !== null && ! in_array($cid, $categoryIds, true)) {
                    $categoryIds[] = $cid;
                    $resolvedNames[] = $cn;
                }
            }
            $primaryCategoryId = $categoryIds[0] ?? null;

            $this->newLine();
            $this->line("→ <info>{$sku}</info>  ".Str::limit((string) $product->name, 60));
            $this->line('  brand:    '.($manufacturer !== '' ? $manufacturer : '(unknown)').'  → '.($brandId !== null ? "term #{$brandId}" : '— no match'));
            if ($categoryIds === []) {
                $this->line('  categories: (none chosen) — no match');
            } else {
                $pairs = [];
                foreach ($resolvedNames as $i => $rn) {
                    $pairs[] = $rn.' #'.$categoryIds[$i].($i === 0 ? ' [primary]' : '');
                }
                $this->line('  categories: '.implode(', ', $pairs));
            }

            $bothResolved = $brandId !== null && $primaryCategoryId !== null;
            $newStatus = $bothResolved ? 'draft' : 'needs_brand_or_category_assignment';

            if ($dryRun) {
                $this->line('  would set status='.$newStatus.' ['.count($categoryIds).' categor'.(count($categoryIds) === 1 ? 'y' : 'ies').'] [dry-run]');
                if ($bothResolved) {
                    $assigned++;
                }
                continue;
            }

            $product->forceFill([
                'brand_id' => $brandId,
                'category_id' => $primaryCategoryId,
                'category_ids' => $categoryIds !== [] ? $categoryIds : null,
                'auto_create_status' => $newStatus,
            ])->saveQuietly();

            if ($bothResolved) {
                $assigned++;
            }
            $this->info('  ✓ saved [status='.$newStatus.']');
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — %d/%d fully assigned (brand+category), Claude spend %dp (~£%s).',
            $dryRun ? 'DRY-RUN complete' : 'Done',
            $assigned,
            count($skus),
            $totalPence,
            number_format($totalPence / 100, 2),
        ));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Ask Claude for ALL suitable category NAMES (verbatim) from the live Woo
     * list, most-specific first. Returns [names[], costPence].
     *
     * @param  array<int, string>  $categoryNames
     * @return array{0: array<int, string>, 1: int}
     */
    private function pickCategories(Product $product, array $categoryNames): array
    {
        $list = '';
        foreach ($categoryNames as $name) {
            $list .= "- {$name}\n";
        }

        $system = <<<'PROMPT'
        You map a product to its categories for a UK audio-visual / video-
        conferencing store. WooCommerce products belong to MULTIPLE categories.
        You are given the product and a fixed LIST of allowed category names.

        Select EVERY category from the list that genuinely fits this product — the
        specific product-type category PLUS any clearly-relevant broader or
        use-case categories (e.g. a USB conference camera → "USB Cameras",
        "Conference Cameras", "Video Conferencing"). Typically 1-4. Do NOT force
        weak matches; only include categories a shopper would expect to find it under.

        Reply with ONLY a JSON array of the chosen category names, copied VERBATIM
        from the list (exact spelling/punctuation), MOST SPECIFIC FIRST. Example:
        ["USB Cameras","Conference Cameras","Video Conferencing"]
        If none fit, reply exactly: []
        No other text, no markdown fences.
        PROMPT;

        $user = "Product: {$product->name}\n"
            .'Short description: '.strip_tags((string) $product->short_description)."\n\n"
            ."Allowed categories:\n{$list}";

        try {
            $resp = $this->claude->generate(
                systemPrompt: $system,
                messages: [new UserMessage($user)],
                maxTokens: 200,
                temperature: 0.0,
            );
        } catch (\Throwable $e) {
            $this->error('  Claude error picking categories: '.$e->getMessage());

            return [[], 0];
        }

        return [$this->parseNameList($resp->text), $resp->costPence];
    }

    /**
     * Parse a JSON array of category names from model output (tolerant of fences).
     *
     * @return array<int, string>
     */
    private function parseNameList(string $text): array
    {
        $text = trim($text);
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);

        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded) && preg_match('/\[.*\]/s', $text, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }

        return $out;
    }

    /**
     * Look up the supplier_db manufacturer for each SKU. Returns sku => manufacturer.
     *
     * @param  array<int, string>  $skus
     * @return array<string, string>
     */
    private function supplierManufacturers(array $skus): array
    {
        $out = [];
        try {
            $c = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
        } catch (\Throwable) {
            return $out;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new \mysqli(
            (string) $c['host'], (string) $c['username'], (string) $c['password'],
            (string) $c['database'], (int) ($c['port'] ?? 3306),
        );
        if ($m->connect_errno !== 0) {
            $this->warn('supplier_db connect failed ('.$m->connect_error.') — using product-name brand fallback.');

            return $out;
        }
        $stmt = $m->prepare(
            'SELECT manufacturer FROM supplier_products
             WHERE (suppliersku = ? OR mpn = ?) AND product_excluded = 0
             ORDER BY updated_at DESC LIMIT 1',
        );
        if ($stmt === false) {
            $m->close();

            return $out;
        }
        foreach ($skus as $sku) {
            $stmt->bind_param('ss', $sku, $sku);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (is_array($row) && trim((string) ($row['manufacturer'] ?? '')) !== '') {
                $out[$sku] = trim((string) $row['manufacturer']);
            }
        }
        $stmt->close();
        $m->close();

        return $out;
    }

    /** Crude brand fallback: the first word of the product name. */
    private function brandFromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = explode(' ', $name);

        return $parts[0] ?? '';
    }
}
