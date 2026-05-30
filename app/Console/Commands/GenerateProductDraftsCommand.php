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
        // Auto-detect the supplier's own description/spec columns so the model
        // grounds in the supplier's actual product data (the nearest thing to a
        // datasheet we have) rather than just the title.
        $detailColumns = $this->detectDetailColumns($m);
        if ($detailColumns !== []) {
            $this->line('Supplier detail column(s) used as datasheet basis: '.implode(', ', $detailColumns));
        } else {
            $this->line('No supplier description/spec column found — grounding on title + identifiers only.');
        }
        $selectCols = array_merge(['title', 'manufacturer', 'mpn', 'suppliersku', 'ean', 'price', 'rrp'], $detailColumns);
        $selectList = implode(', ', array_map(static fn (string $col): string => "`{$col}`", $selectCols));
        $stmt = $m->prepare(
            "SELECT {$selectList}
             FROM supplier_products
             WHERE (suppliersku = ? OR mpn = ?) AND product_excluded = 0
             ORDER BY updated_at DESC LIMIT 1",
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

            $details = [];
            foreach ($detailColumns as $col) {
                $v = trim(strip_tags((string) ($row[$col] ?? '')));
                if ($v !== '') {
                    $details[$col] = Str::limit($v, 2500, '');
                }
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
            if ($details !== []) {
                $facts['supplier_details'] = $details;
            }

            $this->newLine();
            $this->line("→ <info>{$sku}</info>  {$facts['brand']} — ".Str::limit($facts['supplier_title'], 60));

            try {
                $resp = $this->claude->generate(
                    systemPrompt: $system,
                    messages: [new UserMessage(json_encode($facts, JSON_THROW_ON_ERROR))],
                    maxTokens: 2500,
                    temperature: 0.0,
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

            $contentValues = [
                'name' => (string) ($content['title'] ?? $facts['supplier_title']),
                'slug' => Str::slug($facts['brand'].' '.($content['title'] ?? $sku)),
                'short_description' => $this->normaliseHtml($content['short_description'] ?? null),
                'long_description' => $this->normaliseHtml($content['long_description'] ?? null),
                'meta_description' => isset($content['meta_description'])
                    ? Str::limit((string) $content['meta_description'], 255, '')
                    : null,
                // Curated WC "Additional Information" tab rows (Brand, Resolution, etc.)
                // — drives Flatsome storefront spec table parity with existing products.
                // Null when Claude returns no usable rows; PublishProductJob skips the
                // payload key when null/empty.
                'attributes_json' => $this->normaliseAttributes($content['attributes'] ?? null),
                // GTIN/EAN/UPC barcode from supplier_db — persisted so
                // PublishProductJob can push it onto Woo as `global_unique_id`
                // (WC 9.x structured slot used by Google Merchant Center /
                // schema.org product markup). Null when the supplier feed has
                // no value or it's a placeholder.
                'ean' => $this->normaliseEan($facts['ean'] ?? null),
            ];

            $existing = Product::query()->where('sku', $sku)->first();
            if ($existing !== null) {
                // Regenerate CONTENT only — preserve taxonomy (brand/category/
                // category_ids/auto_create_status) and image state (image_url/
                // gallery_image_urls/requires_manual_image_review) set by the later
                // assign-taxonomy + source-images steps. Re-running is now safe.
                $existing->forceFill($contentValues)->save();
                $made++;
                $this->info("  ✓ updated content for Product #{$existing->id} (taxonomy + images preserved)");

                continue;
            }

            // New product — full first-time setup (primary taxonomy + review flag).
            $brandId = $this->taxonomy->resolveBrand($facts['brand'] !== '' ? $facts['brand'] : null);
            $categoryId = $this->taxonomy->resolveCategory(isset($content['category']) ? (string) $content['category'] : null);
            $product = Product::create($contentValues + [
                'sku' => $sku,
                'type' => 'simple',
                'status' => 'draft',
                'auto_create_status' => ($brandId !== null && $categoryId !== null)
                    ? 'draft'
                    : 'needs_brand_or_category_assignment',
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'buy_price' => is_numeric($facts['supplier_cost']) ? $facts['supplier_cost'] : null,
                'requires_manual_image_review' => true,
            ]);
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
        supplier_cost, rrp, and possibly supplier_details with the supplier's own product
        text). TREAT THESE FACTS AS THE PRODUCT'S DATASHEET. State ONLY what the facts
        contain.

        HARD RULES (datasheet-only — the operator requires this):
        - Do NOT state any specification, port or connector (HDMI, USB, USB-C, VGA, RJ45,
          DisplayPort, SD/TF card slot, OPS slot, etc.), interface, resolution, refresh rate,
          brightness, capacity (RAM/storage), dimension, weight, operating system,
          certification, or model variant that is not EXPLICITLY present in the supplied facts.
        - Do NOT invent in-the-box contents. List only items explicitly named in the facts;
          if none are given, write exactly one line: "Supplied as standard — refer to the
          manufacturer's specification for full box contents."
        - If the facts don't support a section's specifics, keep that section brief and general
          (describe the product type's typical role) WITHOUT asserting any unverified detail.
        - Benefit-led phrasing is fine; inventing facts is not. When unsure, leave it out.
        UK English throughout.

        Return ONLY a single valid JSON object — no prose, no markdown code fences — with EXACTLY these keys:

          "title": clean customer-facing product title in the form "Brand Model Descriptor" that
            INCLUDES the manufacturer model/part number (e.g. "Sony FW-50EZ20L 50\" Commercial
            Display"). Do NOT put a bare EAN/barcode number in the title.

          "category": one natural retail category name (e.g. "Video Conferencing Cameras",
            "Professional Displays", "Interactive Flat Panel Displays", "ClickShare & Collaboration").

          "short_description": an HTML "<ul>" containing 4 "<li>" benefit bullets (minimum 3,
            maximum 5), each grounded in the facts — no invented specs.

          "long_description": HTML that follows THIS EXACT section structure and ORDER so every
            product on the site is consistent. Use only "<h3>" for headings:
              <h3>Product Overview</h3><p>2-3 sentence overview of what the product is and who it suits, using only given facts.</p>
              <h3>Key Features</h3><ul> 4 to 5 <li> features, each supported by the facts </ul>
              <h3>Use Cases</h3><ul> 3 to 4 <li> realistic environments/scenarios (these are usage contexts, not specs) </ul>
              <h3>Compatibility</h3><p>Only compatibility the facts support; if the facts name no ports/platforms, keep to ONE general sentence and name NO specific ports/standards.</p>
              <h3>What's in the Box</h3><ul> only items explicitly in the facts; otherwise the single "Supplied as standard…" line above </ul>
              <h3>Why Buy from MeetingStore?</h3><ul> exactly 4 <li>: UK audio-visual specialists; expert pre-sales advice; fast UK delivery; competitive trade pricing </ul>

          "meta_description": a single line, 155 characters or fewer, for SEO — facts only.

          "attributes": an array of 5-8 key/value spec rows for the WooCommerce "Additional
            Information" tab (the storefront's spec table). Each row is an object
            {"name": "...", "value": "..."} — name is a short spec label (≤ 22 chars,
            Title Case, no trailing colon), value is the concrete spec (≤ 60 chars,
            single line, no HTML). Pick attributes that READ LIKE A SPEC SHEET FOR
            THIS PRODUCT TYPE — for a camera: Brand, Resolution, Field of View, Frame
            Rate, Connection, Microphone, Mount, Warranty. For a display: Brand, Screen
            Size, Resolution, Brightness, Refresh Rate, Inputs, Speakers, Warranty.
            For a cable/adapter: Brand, Connector A, Connector B, Length, Colour,
            Material, Compatibility. Use ONLY facts given; if a value isn't supported,
            OMIT the row (fewer accurate rows beats more guessed ones). Always include
            Brand as the first row. Do not repeat the title; do not invent spec numbers.

        Be accurate and concise. A thinner, fully-accurate description is REQUIRED over a richer one that guesses.
        PROMPT;
    }

    /**
     * Auto-detect supplier_products columns that hold descriptive/spec text, so
     * the model can ground in the supplier's own product data (the nearest thing
     * to a datasheet) — not just the title. Matches desc/spec/feature/detail/
     * overview/highlight/bullet/body names; excludes image columns. Identifier
     * names are validated before use in the SELECT.
     *
     * @return array<int, string>
     */
    private function detectDetailColumns(\mysqli $m): array
    {
        $res = $m->query('SHOW COLUMNS FROM supplier_products');
        if ($res === false) {
            return [];
        }
        $cols = [];
        while ($row = $res->fetch_assoc()) {
            $name = (string) ($row['Field'] ?? '');
            if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                continue;
            }
            if (preg_match('/desc|spec|feature|detail|overview|highlight|bullet|body|long_?text/i', $name) === 1
                && preg_match('/image|img|picture|photo|thumb/i', $name) !== 1) {
                $cols[] = $name;
            }
        }

        return $cols;
    }

    /**
     * Repair the one malformed-tag class a model occasionally emits: a closing
     * tag whose ">" is missing before the next tag, e.g. "</li<li>" → "</li><li>".
     *
     * Deliberately surgical — the pattern cannot match well-formed HTML
     * ("</li><li>" already has its ">") and never touches text content, so £,
     * ™, curly quotes and valid markup are left exactly as written. Returns null
     * for empty/non-string input (keeps the column nullable).
     */
    private function normaliseHtml(mixed $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        return (string) preg_replace('#</([a-zA-Z][a-zA-Z0-9]*)\s*<#', '</$1><', $html);
    }

    /**
     * Clean Claude's attributes[] into the storage shape — array of {name, value}
     * with trimmed, length-capped, deduped (by lowercased name) entries. Returns
     * null when no usable rows (cast as JSON null on the model; PublishProductJob
     * then omits the payload key).
     *
     * @return array<int, array{name:string, value:string}>|null
     */
    private function normaliseAttributes(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $byKey = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $name = mb_substr($name, 0, 22);
            $value = mb_substr($value, 0, 60);
            $byKey[mb_strtolower($name)] = ['name' => $name, 'value' => $value];
        }

        return $byKey === [] ? null : array_values($byKey);
    }

    /**
     * Normalise an EAN/GTIN from the supplier feed: trim, strip spaces/hyphens,
     * keep digits only; require a plausible length (8-14, covering GTIN-8/UPC-12/
     * EAN-13/GTIN-14). Returns null for blanks, placeholders (all-zero, all-nine),
     * and anything that doesn't look like a real barcode.
     */
    private function normaliseEan(mixed $raw): ?string
    {
        $s = preg_replace('/\D+/', '', (string) ($raw ?? '')) ?? '';
        $len = strlen($s);
        if ($len < 8 || $len > 14) {
            return null;
        }
        // Reject all-zero / all-nine placeholders (common feed sentinels).
        if (preg_match('/^(0+|9+)$/', $s) === 1) {
            return null;
        }

        return $s;
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
