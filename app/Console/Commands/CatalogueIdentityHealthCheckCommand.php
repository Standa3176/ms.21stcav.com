<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Quick task 260828-plk — does the live catalogue describe REAL products?
 *
 * READ-ONLY. No writes, no events, no Woo calls.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 *
 * pricing:health-check (260825-n5v) asks whether the catalogue is priced
 * correctly, and it runs every morning. Nothing asks whether the catalogue is
 * DESCRIBED correctly. That asymmetry is why roughly 2,242 fabricated barcodes
 * sat on the storefront for months and surfaced only because someone went
 * looking on 2026-08-27.
 *
 * The faults this exists to catch, all found by hand:
 *
 *   61U3010000AC held "613010000"      the SKU with its letters stripped out
 *   DS-D6055UN-D/S held "6931850000000" a GS1 prefix zero-padded to 13
 *   three Hikvision products shared ONE such value
 *   "AVer 60V2B10000AL Accessory"       a name that identifies nothing
 *   "Yealink — nan 4k conference camera" an unresolved token in the title
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT ALARMS AND WHAT DOES NOT
 *
 * Barcode faults FAIL the run. A wrong GTIN is a Google Shopping disapproval
 * and a duplicated one invites scrutiny of the whole feed — money out of the
 * door either way. Names, images and suspect costs are REPORTED and do not
 * fail: they are a quality backlog, and an alarm that fires every morning for
 * a known backlog is one people stop reading, so the real one gets missed too.
 * Same split, same reasoning, as 260825-n5v.
 *
 * SUSPECT COST is delegated to the exact CeilingBlockClassifier + RuleResolver
 * path pricing:health-check uses. Reimplementing it would let two commands
 * disagree about the same product, which is worse than not checking twice.
 *
 *   php artisan products:identity-health-check
 *   php artisan products:identity-health-check --include-unpublished
 *   php artisan products:identity-health-check --section=barcode
 */
final class CatalogueIdentityHealthCheckCommand extends BaseCommand
{
    /** Hard ceiling on printed rows per section, whatever --limit says. */
    private const REPORT_CAP = 40;

    /**
     * Standalone tokens that mean "this field was never filled in". Matched
     * whole-word and case-insensitively, so "Nano", "Financial" and the Sony
     * "NAV" range are untouched.
     *
     * @var list<string>
     */
    private const UNRESOLVED_TOKENS = ['nan', 'n/a', 'na', 'null', 'nil', 'undefined', 'unknown', 'tbc', 'tbd', 'xxx'];

    /**
     * Nouns that describe a CATEGORY rather than a product. A name built from
     * nothing but brand + part number + one of these tells a customer nothing
     * and tells us nobody ever established what the item is.
     *
     * @var list<string>
     */
    private const GENERIC_NOUNS = [
        'accessory', 'accessories', 'part', 'parts', 'spare', 'spares',
        'replacement part', 'module', 'kit', 'bundle', 'item', 'product',
        'solution', 'av solution', 'professional av solution', 'component',
        'unit', 'device', 'misc', 'miscellaneous', 'other',
    ];

    protected $signature = 'products:identity-health-check
        {--include-unpublished : Check every product, not just what is on the storefront}
        {--section= : Only run one section: barcode, name, image or cost}
        {--limit=20 : Rows to print per section}';

    protected $description = 'READ-ONLY health check of product IDENTITY — barcodes, names and images — against the live catalogue (260828-plk).';

    public function __construct(private readonly RuleResolver $resolver)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $publishedOnly = ! (bool) $this->option('include-unpublished');
        $limit = max(1, (int) $this->option('limit'));
        $section = strtolower(trim((string) $this->option('section')));
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $classifier = CeilingBlockClassifier::fromConfig();

        $this->info(sprintf(
            'Catalogue identity — %s products%s.',
            $publishedOnly ? 'PUBLISHED' : 'ALL',
            $section === '' ? '' : ', section='.$section,
        ));

        $find = [
            'sku_derived' => [], 'placeholder_gtin' => [], 'invalid_gtin' => [], 'duplicate_gtin' => [],
            'placeholder_name' => [], 'unresolved_token' => [], 'no_image' => [], 'single_image' => [],
            'suspect_cost' => [],
        ];
        $gtinOwners = [];
        $checked = 0;

        $query = Product::query();
        if ($publishedOnly) {
            $query->where('status', 'publish');
        }

        $query->orderBy('id')->chunkById(500, function ($products) use (
            $section, $vatBps, $classifier, &$find, &$gtinOwners, &$checked
        ): void {
            foreach ($products as $product) {
                $checked++;
                $sku = trim((string) $product->sku);
                $name = trim((string) $product->name);
                $gtin = trim((string) $product->ean);

                if ($this->wants($section, 'barcode') && $gtin !== '') {
                    $row = ['sku' => $sku, 'value' => $gtin, 'name' => $name];
                    $gtinOwners[ltrim($gtin, '0')][] = $sku;

                    if ($this->isSkuDerived($gtin, $sku)) {
                        $find['sku_derived'][] = $row;
                    } elseif ($this->isPlaceholderGtin($gtin)) {
                        $find['placeholder_gtin'][] = $row;
                    } elseif (! self::gtinIsValid($gtin)) {
                        $find['invalid_gtin'][] = $row;
                    }
                }

                if ($this->wants($section, 'name')) {
                    if ($token = $this->unresolvedTokenIn($name)) {
                        $find['unresolved_token'][] = ['sku' => $sku, 'value' => $token, 'name' => $name];
                    } elseif ($this->isPlaceholderName($name, $sku)) {
                        $find['placeholder_name'][] = ['sku' => $sku, 'value' => '', 'name' => $name];
                    }
                }

                if ($this->wants($section, 'image')) {
                    $images = is_array($product->gallery_image_urls) ? count($product->gallery_image_urls) : 0;
                    if ($images === 0) {
                        $find['no_image'][] = ['sku' => $sku, 'value' => '0', 'name' => $name];
                    } elseif ($images === 1) {
                        $find['single_image'][] = ['sku' => $sku, 'value' => '1', 'name' => $name];
                    }
                }

                if ($this->wants($section, 'cost') && $this->hasUsablePrices($product)) {
                    $buy = (int) round(((float) $product->buy_price) * 100);
                    $sell = (int) round(((float) $product->sell_price) * 100);
                    $currentBps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);
                    if ($classifier->classify(0, 0, $currentBps, $this->ruleMarginFor($product), $buy) === CeilingBlockClassifier::COST_FAULT) {
                        $find['suspect_cost'][] = [
                            'sku' => $sku,
                            'value' => number_format($currentBps / 100, 1).'%',
                            'name' => 'cost '.number_format($buy / 100, 2).' → price '.number_format($sell / 100, 2),
                        ];
                    }
                }
            }
        });

        foreach ($gtinOwners as $normalised => $owners) {
            $unique = array_values(array_unique($owners));
            if (count($unique) > 1) {
                $find['duplicate_gtin'][] = [
                    'sku' => implode(', ', array_slice($unique, 0, 4)).(count($unique) > 4 ? ' …' : ''),
                    'value' => (string) $normalised,
                    'name' => count($unique).' products share this barcode',
                ];
            }
        }

        $this->renderSection('SKU-DERIVED BARCODE (the part number, not a barcode)', $find['sku_derived'], $limit);
        $this->renderSection('PLACEHOLDER BARCODE (company prefix padded with zeros)', $find['placeholder_gtin'], $limit);
        $this->renderSection('DUPLICATE BARCODE (one GTIN, several products)', $find['duplicate_gtin'], $limit);
        $this->renderSection('INVALID BARCODE (check digit or length fails)', $find['invalid_gtin'], $limit);
        $this->renderSection('PLACEHOLDER NAME (identifies nothing)', $find['placeholder_name'], $limit);
        $this->renderSection('UNRESOLVED TOKEN IN TITLE', $find['unresolved_token'], $limit);
        $this->renderSection('NO IMAGE', $find['no_image'], $limit);
        $this->renderSection('SINGLE IMAGE', $find['single_image'], $limit);
        $this->renderSection('SUSPECT COST (margin implausible vs its rule)', $find['suspect_cost'], $limit);

        $barcodeFaults = count($find['sku_derived']) + count($find['placeholder_gtin'])
            + count($find['duplicate_gtin']) + count($find['invalid_gtin']);

        $this->newLine();
        $this->line(sprintf('Checked %d product(s).', $checked));
        $this->line(sprintf(
            '  barcode: %d sku-derived, %d placeholder, %d duplicate, %d invalid',
            count($find['sku_derived']), count($find['placeholder_gtin']),
            count($find['duplicate_gtin']), count($find['invalid_gtin']),
        ));
        $this->line(sprintf(
            '  identity: %d placeholder name, %d unresolved token, %d no image, %d single image, %d suspect cost',
            count($find['placeholder_name']), count($find['unresolved_token']),
            count($find['no_image']), count($find['single_image']), count($find['suspect_cost']),
        ));

        Log::info('catalogue.identity_health_check', array_merge(
            ['checked' => $checked, 'published_only' => $publishedOnly],
            array_map(static fn (array $rows): int => count($rows), $find),
        ));

        $this->newLine();
        if ($barcodeFaults > 0) {
            $this->error(sprintf('FAIL — %d product(s) carry a barcode that is not a real GTIN.', $barcodeFaults));

            return SymfonyCommand::FAILURE;
        }

        $this->info('PASS — every published barcode is a well-formed, unique GTIN.');

        return SymfonyCommand::SUCCESS;
    }

    private function wants(string $section, string $name): bool
    {
        return $section === '' || $section === $name;
    }

    private function hasUsablePrices(Product $product): bool
    {
        return $product->buy_price !== null && (float) $product->buy_price > 0
            && $product->sell_price !== null && (float) $product->sell_price > 0;
    }

    /**
     * The signature fault. `61U3010000AC` holding `613010000` is the SKU with
     * its letters and punctuation removed — a part number wearing a barcode's
     * clothes. Compared digits-only so `960-001699` → `960001699` is caught.
     *
     * A real GTIN whose digits happen to equal the SKU's is vanishingly
     * unlikely, but it would have to ALSO pass its check digit, so require the
     * value to be malformed before calling it derived.
     */
    private function isSkuDerived(string $gtin, string $sku): bool
    {
        $skuDigits = preg_replace('/\D+/', '', $sku) ?? '';
        if ($skuDigits === '' || $gtin === '') {
            return false;
        }

        if (ltrim($gtin, '0') !== ltrim($skuDigits, '0')) {
            return false;
        }

        return ! self::gtinIsValid($gtin);
    }

    /**
     * `6931850000000` is Hikvision's GS1 company prefix with the product code
     * left as zeros. Requires ≥6 trailing zeros so genuine barcodes ending in a
     * round number are not swept up.
     */
    private function isPlaceholderGtin(string $gtin): bool
    {
        return (bool) preg_match('/^\d{4,8}0{6,}$/', $gtin);
    }

    /**
     * GS1 mod-10 over GTIN-8/12/13/14. Weights run 3,1,3,1… from the digit
     * immediately left of the check digit, which is length-agnostic.
     */
    public static function gtinIsValid(string $gtin): bool
    {
        if (! ctype_digit($gtin) || ! in_array(strlen($gtin), [8, 12, 13, 14], true)) {
            return false;
        }

        $digits = str_split($gtin);
        $check = (int) array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) {
            $sum += ((int) $digit) * ($i % 2 === 0 ? 3 : 1);
        }

        return ((10 - $sum % 10) % 10) === $check;
    }

    /**
     * Whole-word match so "Nano", "Financial" and Sony's "NAV" range survive.
     */
    private function unresolvedTokenIn(string $name): ?string
    {
        foreach (self::UNRESOLVED_TOKENS as $token) {
            if (preg_match('/(?<![\w\-])'.preg_quote($token, '/').'(?![\w\-])/i', $name) === 1) {
                return $token;
            }
        }

        return null;
    }

    /**
     * A placeholder name is one that survives removing the brand, the SKU and
     * every generic noun with nothing meaningful left — "AVer 60V2B10000AL
     * Accessory" collapses to nothing, while "Vision VFM-DSXP Desktop LCD
     * Display Stand" keeps "desktop lcd display stand".
     *
     * Deliberately conservative: a name only flags when NOTHING specific
     * remains, so a terse-but-real name is safe.
     */
    private function isPlaceholderName(string $name, string $sku): bool
    {
        if ($name === '') {
            return true;
        }

        $residue = strtolower($name);
        $residue = str_replace(strtolower($sku), ' ', $residue);

        foreach (self::GENERIC_NOUNS as $noun) {
            $residue = preg_replace('/(?<![\w\-])'.preg_quote($noun, '/').'(?![\w\-])/i', ' ', $residue) ?? $residue;
        }

        // Anything left that is a word of 2+ characters and not a bare number
        // counts as describing the product. The FIRST such word is usually the
        // brand, so require a second.
        preg_match_all('/[a-z]{2,}/', (string) $residue, $matches);

        return count($matches[0] ?? []) < 2;
    }

    private function ruleMarginFor(Product $product): ?int
    {
        try {
            return (int) $this->resolver->resolve($product)->marginBasisPoints;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function renderSection(string $title, array $rows, int $limit): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf('%s — %d', $title, count($rows)));

        $shown = min($limit, self::REPORT_CAP);
        $this->table(
            ['SKU', 'Value', 'Product'],
            array_map(static fn (array $r): array => [
                mb_strimwidth($r['sku'], 0, 34, '…'),
                $r['value'],
                mb_strimwidth($r['name'], 0, 52, '…'),
            ], array_slice($rows, 0, $shown)),
        );

        $more = count($rows) - $shown;
        if ($more > 0) {
            $this->line("  … and {$more} more.");
        }
    }
}
