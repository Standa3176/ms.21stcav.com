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
 * MVP product-content generator (operator-driven, 2026-05-23).
 *
 * For each supplied SKU: read facts from the supplier MySQL (supplier_db —
 * title/manufacturer/mpn/ean/price/rrp), ask Claude (via the sanctioned
 * ClaudeClient → budget logging + Langfuse for free) to write a clean title,
 * 3-5 benefit bullets, a formatted long description, a category suggestion and
 * an SEO meta description GROUNDED in those facts, then upsert a LOCAL draft
 * Product (status=draft, auto_create_status=draft|needs_brand_or_category_assignment).
 *
 * Review-first: writes ONLY local Product rows that surface in the Auto-Create
 * Review inbox. It NEVER posts to Woo (no WooClient::post here) — the only Woo
 * traffic is TaxonomyResolver's read to map brand/category names to term IDs.
 * Images are deliberately out of scope (step 2 — Icecat); requires_manual_image_review
 * is set so the review UI flags it.
 *
 *   php artisan products:generate-drafts --skus=ABC,DEF --dry-run   (preview only)
 *   php artisan products:generate-drafts --skus=ABC,DEF             (write drafts)
 */
final class GenerateProductDraftsCommand extends BaseCommand
{
    protected $signature = 'products:generate-drafts
        {--skus= : Comma-separated supplier SKUs or MPNs (required)}
        {--dry-run : Generate + print content only; do NOT write Product drafts}';

    protected $description = 'AI-generate product content from supplier_db facts into local draft Products (review-first, no Woo writes)';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly ClaudeClient $claude,
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
            $this->error('--skus is required (comma-separated SKUs/MPNs).');

            return SymfonyCommand::FAILURE;
        }

        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Generating content for '.count($skus).' SKU(s).');

        // ── Connect supplier_db (mysqli — same path as supplier:db-sync) ──
        $c = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new \mysqli(
            (string) $c['host'], (string) $c['username'], (string) $c['password'],
            (string) $c['database'], (int) ($c['port'] ?? 3306),
        );
        if ($m->connect_errno !== 0) {
            $this->error('supplier_db connect failed: '.$m->connect_error);

            return SymfonyCommand::FAILURE;
        }
        $stmt = $m->prepare(
            'SELECT title, manufacturer, mpn, suppliersku, ean, price, rrp
             FROM supplier_products
             WHERE (suppliersku = ? OR mpn = ?) AND product_excluded = 0
             ORDER BY updated_at DESC LIMIT 1',
        );

        $system = $this->systemPrompt();
        $totalPence = 0;
        $made = 0;

        foreach ($skus as $sku) {
            $stmt->bind_param('ss', $sku, $sku);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (! $row) {
                $this->warn("  {$sku}: not found in supplier DB — skipped");
                continue;
            }

            $facts = [
                'sku' => $sku,
                'brand' => (string) ($row['manufacturer'] ?? ''),
                'supplier_title' => (string) ($row['title'] ?? ''),
                'mpn' => (string) ($row['mpn'] ?? ''),
                'ean' => (string) ($row['ean'] ?? ''),
                'supplier_cost' => $row['price'] ?? null,
                'rrp' => $row['rrp'] ?? null,
            ];

            $this->newLine();
            $this->line("→ <info>{$sku}</info>  {$facts['brand']} — ".Str::limit($facts['supplier_title'], 60));

            try {
                $resp = $this->claude->generate(
                    systemPrompt: $system,
                    messages: [new UserMessage(json_encode($facts, JSON_THROW_ON_ERROR))],
                    maxTokens: 1600,
                    temperature: 0.3,
                );
            } catch (\Throwable $e) {
                $this->error('  Claude error: '.$e->getMessage());
                continue;
            }
            $totalPence += $resp->costPence;

            $content = $this->parseJson($resp->text);
            if ($content === null) {
                $this->error('  Could not parse JSON from model output:');
                $this->line('  '.Str::limit($resp->text, 240));
                continue;
            }

            $this->line('  title:    '.($content['title'] ?? '(none)'));
            $this->line('  category: '.($content['category'] ?? '(none)'));
            $this->line('  short:    '.Str::limit(strip_tags((string) ($content['short_description'] ?? '')), 90));
            $this->line('  meta:     '.Str::limit((string) ($content['meta_description'] ?? ''), 90));
            $this->line('  Claude cost: '.$resp->costPence.'p');

            if ($dryRun) {
                continue;
            }

            $brandId = $this->taxonomy->resolveBrand($facts['brand'] !== '' ? $facts['brand'] : null);
            $categoryId = $this->taxonomy->resolveCategory(isset($content['category']) ? (string) $content['category'] : null);

            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'name' => (string) ($content['title'] ?? $facts['supplier_title']),
                    'slug' => Str::slug($facts['brand'].' '.($content['title'] ?? $sku)),
                    'type' => 'simple',
                    'status' => 'draft',
                    'auto_create_status' => ($brandId !== null && $categoryId !== null)
                        ? 'draft'
                        : 'needs_brand_or_category_assignment',
                    'short_description' => $content['short_description'] ?? null,
                    'long_description' => $content['long_description'] ?? null,
                    'meta_description' => isset($content['meta_description'])
                        ? Str::limit((string) $content['meta_description'], 255, '')
                        : null,
                    'brand_id' => $brandId,
                    'category_id' => $categoryId,
                    'buy_price' => is_numeric($facts['supplier_cost']) ? $facts['supplier_cost'] : null,
                    'requires_manual_image_review' => true,
                ],
            );
            $made++;
            $this->info("  ✓ draft Product #{$product->id} [{$product->auto_create_status}]  brand_id="
                .($brandId ?? '—').' category_id='.($categoryId ?? '—'));
        }

        $m->close();
        $this->newLine();
        $this->info(sprintf(
            '%s — %d draft(s) written, total Claude spend %dp (~£%s).',
            $dryRun ? 'DRY-RUN complete' : 'Done',
            $made,
            $totalPence,
            number_format($totalPence / 100, 2),
        ));
        if (! $dryRun && $made > 0) {
            $this->line('Review them at /admin/auto-create-reviews (status: draft / needs brand+category).');
        }

        return SymfonyCommand::SUCCESS;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a senior e-commerce copywriter for Meeting Store (meetingstore.co.uk), a UK
        audio-visual and video-conferencing retailer selling to IT/AV professionals.

        You receive supplier facts as a JSON object (brand, supplier_title, mpn, ean,
        supplier_cost, rrp). Write clean, benefit-led product content GROUNDED STRICTLY in
        those facts. NEVER invent specifications, dimensions, ports, resolutions, or features
        that are not clearly stated or implied by the brand/title/model. If a detail is not
        given, leave it out rather than guess. UK English throughout.

        Return ONLY a single valid JSON object — no prose, no markdown code fences — with EXACTLY these keys:
          "title": clean customer-facing product title (Brand + model + short descriptor; no raw SKUs/codes unless part of the model name).
          "category": one natural retail category name for this product (e.g. "Video Conferencing Cameras", "Professional Displays", "ClickShare & Collaboration").
          "short_description": an HTML "<ul>" with 3-5 "<li>" benefit bullets, each grounded in the facts.
          "long_description": HTML — one 2-3 sentence overview paragraph ("<p>...</p>"), then "<h3>Key features</h3>" and a "<ul>" of feature bullets. No fabricated specs.
          "meta_description": a single line, 155 characters or fewer, for SEO.

        Be accurate and concise. When unsure, stay general rather than fabricate.
        PROMPT;
    }

    /**
     * Extract a JSON object from the model's text (tolerates stray prose / code fences).
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        // Strip ```json … ``` fences if present.
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Fallback: grab the first {...} block.
        if (preg_match('/\{.*\}/s', $text, $mm) === 1) {
            $decoded = json_decode($mm[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
