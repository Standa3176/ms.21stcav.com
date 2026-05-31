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

        // Use the CURATED picking list (paths built, attribute-filter facets
        // stripped, empties dropped) — not the raw flat list. Without this
        // Claude (correctly) returns [] for niche products because the raw
        // 447-term list is mostly storefront filter values like "10000+
        // lumens" / "56-65 inch" that no shopper would browse BY.
        $categoriesForPicking = $this->taxonomy->allCategoriesForPicking();
        $brands = $this->taxonomy->allBrands();
        $rawCount = count($this->taxonomy->allCategoriesWithMeta());
        $pickCount = count($categoriesForPicking);
        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Assigning taxonomy for '.count($skus).' product(s).');
        $this->line(sprintf(
            'Live Woo taxonomy: %d categories (%d after pruning empty + filter facets), %d brand terms.',
            $rawCount, $pickCount, count($brands),
        ));

        if ($categoriesForPicking === []) {
            $this->warn('No usable Woo categories after pruning — check the WooCommerce REST credential. Aborting.');

            return SymfonyCommand::FAILURE;
        }
        // Build the path-labelled list once and pass to Claude (paths
        // disambiguate name-duplicates like the six "56-65 inch" terms).
        $categoryLabels = array_map(
            static fn (array $c): string => $c['path'],
            $categoriesForPicking,
        );

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

            [$categoryLabelsChosen, $costPence] = $this->pickCategories($product, $categoryLabels);
            $totalPence += $costPence;

            // Fallback pass: if the strict picker returned [], ask Claude to
            // pick the SINGLE best general category, even if not a perfect
            // fit, so the product can publish under SOMETHING rather than
            // stay stuck forever. Most "no match" failures on the live store
            // are loose-fit retail products (accessories, service plans) that
            // the strict prompt is correctly conservative about — but in
            // practice we need a home for them.
            if ($categoryLabelsChosen === []) {
                [$fallback, $fallbackCost] = $this->pickFallbackCategory($product, $categoryLabels);
                $totalPence += $fallbackCost;
                if ($fallback !== null) {
                    $categoryLabelsChosen = [$fallback];
                }
            }

            // Map each chosen label → Woo term id (dedup, preserve order). The
            // FIRST is the primary (drives single-valued pricing rules); the full
            // set goes to category_ids for the Woo categories[] payload.
            $categoryIds = [];
            $resolvedNames = [];
            foreach ($categoryLabelsChosen as $cn) {
                $cid = $this->taxonomy->categoryIdByLabel($cn);
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
     * Ask Claude for ALL suitable category PATHS (verbatim) from the live Woo
     * list, most-specific first. Returns [paths[], costPence].
     *
     * Labels are full paths like "Cameras > USB Cameras" — paths
     * disambiguate the many name-duplicate terms that share a name but
     * differ by parent (e.g. six different "56-65 inch" categories).
     *
     * @param  array<int, string>  $categoryLabels
     * @return array{0: array<int, string>, 1: int}
     */
    private function pickCategories(Product $product, array $categoryLabels): array
    {
        $list = '';
        foreach ($categoryLabels as $label) {
            $list .= "- {$label}\n";
        }

        $system = <<<'PROMPT'
        You map a product to its categories for a UK audio-visual / video-
        conferencing store. WooCommerce products belong to MULTIPLE categories.
        You are given the product and a fixed LIST of allowed category PATHS
        (each is "Parent > Child > ..." — the full tree path, so name-duplicates
        are distinguishable).

        Select EVERY category from the list that genuinely fits this product — the
        specific product-type category PLUS any clearly-relevant broader or
        use-case categories (e.g. a USB conference camera → "Cameras > USB Cameras",
        "Cameras > Conference Cameras", "Video Conferencing"). Typically 1-4. Do NOT
        force weak matches; only include categories a shopper would expect to find
        it under.

        Reply with ONLY a JSON array of the chosen category PATHS, copied VERBATIM
        from the list (exact spelling/punctuation/spacing/separator), MOST SPECIFIC
        FIRST. Example:
        ["Cameras > USB Cameras","Cameras > Conference Cameras","Video Conferencing"]
        If none fit, reply exactly: []
        No other text, no markdown fences.
        PROMPT;

        $user = "Product: {$product->name}\n"
            .'Short description: '.strip_tags((string) $product->short_description)."\n\n"
            ."Allowed category paths:\n{$list}";

        try {
            $resp = $this->claude->generate(
                systemPrompt: $system,
                messages: [new UserMessage($user)],
                maxTokens: 300,
                temperature: 0.0,
            );
        } catch (\Throwable $e) {
            $this->error('  Claude error picking categories: '.$e->getMessage());

            return [[], 0];
        }

        return [$this->parseNameList($resp->text), $resp->costPence];
    }

    /**
     * Fallback when pickCategories returns [] — ask Claude to pick the
     * SINGLE best general category, even if not a perfect fit. Keeps the
     * product from getting stuck in needs_brand_or_category_assignment
     * forever just because the storefront lacks a perfect niche category.
     * Returns [path|null, costPence].
     *
     * @param  array<int, string>  $categoryLabels
     * @return array{0: string|null, 1: int}
     */
    private function pickFallbackCategory(Product $product, array $categoryLabels): array
    {
        $list = '';
        foreach ($categoryLabels as $label) {
            $list .= "- {$label}\n";
        }

        $system = <<<'PROMPT'
        You are placing a UK audio-visual / video-conferencing product into ONE
        WooCommerce category — the previous strict pass found no perfect fit, so
        now pick the SINGLE CLOSEST category from the list, even if not exact.
        Any home is better than no home — the product must publish somewhere.

        Prefer the BROAD parent category over a niche child (e.g. a USB extension
        cable with no "Cables" category → pick "Accessories" or the broadest
        relevant parent). Avoid product-type categories the product clearly
        ISN'T (don't put a camera under "Displays").

        Reply with ONLY the single chosen category path, copied VERBATIM from
        the list (no JSON, no quotes, no markdown). If the list contains
        absolutely NOTHING that could plausibly hold this product (very rare),
        reply with the literal word: NONE
        PROMPT;

        $user = "Product: {$product->name}\n"
            .'Short description: '.strip_tags((string) $product->short_description)."\n\n"
            ."Allowed category paths:\n{$list}";

        try {
            $resp = $this->claude->generate(
                systemPrompt: $system,
                messages: [new UserMessage($user)],
                maxTokens: 80,
                temperature: 0.0,
            );
        } catch (\Throwable) {
            return [null, 0];
        }

        $picked = trim($resp->text);
        $picked = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $picked);
        $picked = trim($picked, " \t\n\r\0\x0B\"'");

        if ($picked === '' || strcasecmp($picked, 'NONE') === 0) {
            return [null, $resp->costPence];
        }

        return [$picked, $resp->costPence];
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
