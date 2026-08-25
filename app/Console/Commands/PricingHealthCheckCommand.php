<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Pricing\Notifications\PricingHealthNotification;
use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Quick task 260825-n5v — is the LIVE catalogue priced correctly right now?
 *
 * READ-ONLY. No writes, no events, no Woo calls.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS ALONGSIDE pricing:audit-movements
 *
 * That command audits products whose price MOVED between two daily snapshots.
 * It cannot see a product that is priced wrongly and simply sitting there —
 * which is the worse failure, because a moving price gets another chance
 * tomorrow while a stuck one stays wrong indefinitely. SKU CP4 sat at £1,517.99
 * for weeks against a £24.96 cost and never moved, so no movement audit would
 * ever have found it.
 *
 * This asks the static question over EVERY published product with a cost:
 *
 *   below cost    sell ex-VAT < buy — selling at a loss
 *   below floor   under cost + competitor.min_margin_floor_bps (6%) — a margin
 *                 the business never agreed to
 *   cost fault    current margin implausible vs the resolved rule — indicts the
 *                 COST, which then poisons the floor, the rules and every report
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY IT NOTIFIES
 *
 * Every pricing fault found in August 2026 was found by a human deciding to go
 * looking: the 5,319 lost pushes surfaced from reading failed_jobs by hand, the
 * CP4 homonym had been live for weeks, the projection-screen families turned up
 * while chasing an unrelated question. Tools that only run when someone
 * remembers them are not monitoring.
 *
 * Alarms are deliberately narrow — below-cost and below-floor are money going
 * out of the door, and cost faults corrupt everything downstream. Everything
 * else is reported and not alarmed, because an alarm that cries wolf gets muted
 * and then the real one is missed too.
 *
 *   php artisan pricing:health-check
 *   php artisan pricing:health-check --notify        (scheduled use)
 *   php artisan pricing:health-check --include-unpublished
 */
final class PricingHealthCheckCommand extends BaseCommand
{
    private const REPORT_CAP = 20;

    protected $signature = 'pricing:health-check
        {--notify : Email alert recipients when a hard fault is found (scheduled use)}
        {--include-unpublished : Check every product, not just what is on the storefront}
        {--limit=20 : Rows to print per section}';

    protected $description = 'READ-ONLY health check of CURRENT live prices against cost, the margin floor and the pricing rules (260825-n5v).';

    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $floorBps = (int) config('competitor.min_margin_floor_bps', 600);
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $publishedOnly = ! (bool) $this->option('include-unpublished');
        $limit = max(1, (int) $this->option('limit'));
        $classifier = CeilingBlockClassifier::fromConfig();

        $this->info(sprintf(
            'Pricing health — %s products, floor %.2f%%, VAT %.2f%%.',
            $publishedOnly ? 'PUBLISHED' : 'ALL',
            $floorBps / 100,
            $vatBps / 100,
        ));

        $belowCost = [];
        $belowFloor = [];
        $costFaults = [];
        $checked = 0;

        $query = Product::query()
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->whereNotNull('sell_price')
            ->where('sell_price', '>', 0);

        if ($publishedOnly) {
            $query->where('status', 'publish');
        }

        $query->orderBy('id')->chunkById(500, function ($products) use (
            $floorBps, $vatBps, $classifier, &$belowCost, &$belowFloor, &$costFaults, &$checked
        ): void {
            foreach ($products as $product) {
                $checked++;

                $buy = (int) round(((float) $product->buy_price) * 100);
                $sell = (int) round(((float) $product->sell_price) * 100);
                $exVat = $this->calculator->stripVat($sell, $vatBps);
                $floor = $this->calculator->compute($buy, $floorBps, $vatBps);
                $currentBps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);

                $row = [
                    'sku' => (string) $product->sku,
                    'buy' => $buy,
                    'sell' => $sell,
                    'floor' => $floor,
                    'margin_bps' => $currentBps,
                ];

                if ($exVat < $buy) {
                    $belowCost[] = $row;

                    continue;
                }

                if ($sell < $floor) {
                    $belowFloor[] = $row;

                    continue;
                }

                // A cost fault is judged against the product's own rule so a
                // deliberately-pinned line (a 260824-w9k override resolves to its
                // own margin) cannot be reported as broken.
                $ruleBps = $this->ruleMarginFor($product);
                if ($classifier->classify(0, 0, $currentBps, $ruleBps) === CeilingBlockClassifier::COST_FAULT) {
                    $row['rule_bps'] = $ruleBps;
                    $costFaults[] = $row;
                }
            }
        });

        $this->renderSection('SELLING BELOW COST', $belowCost, $limit);
        $this->renderSection('BELOW THE MINIMUM-MARGIN FLOOR', $belowFloor, $limit);
        $this->renderSection('SUSPECT COST (margin implausible vs its rule)', $costFaults, $limit);

        $hardFaults = count($belowCost) + count($belowFloor);

        $this->newLine();
        $this->line(sprintf('Checked %d product(s).', $checked));
        $this->line(sprintf(
            '  %d below cost, %d below floor, %d suspect cost.',
            count($belowCost),
            count($belowFloor),
            count($costFaults),
        ));

        Log::info('pricing.health_check', [
            'checked' => $checked,
            'below_cost' => count($belowCost),
            'below_floor' => count($belowFloor),
            'cost_faults' => count($costFaults),
            'published_only' => $publishedOnly,
        ]);

        if ((bool) $this->option('notify')) {
            $this->notifyIfNeeded($belowCost, $belowFloor, $costFaults);
        }

        $this->newLine();
        if ($hardFaults > 0) {
            $this->error(sprintf('FAIL — %d product(s) priced below cost or below the agreed floor.', $hardFaults));

            return SymfonyCommand::FAILURE;
        }

        $this->info('PASS — every checked product covers its cost and the minimum margin.');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Hard faults alarm. Suspect costs are reported but do NOT alarm on their
     * own: they are a data-quality queue, and an alarm that fires every day for
     * a known backlog is one people stop reading.
     *
     * @param  array<int, array<string, mixed>>  $belowCost
     * @param  array<int, array<string, mixed>>  $belowFloor
     * @param  array<int, array<string, mixed>>  $costFaults
     */
    private function notifyIfNeeded(array $belowCost, array $belowFloor, array $costFaults): void
    {
        if ($belowCost === [] && $belowFloor === []) {
            return;
        }

        try {
            $recipients = AlertRecipient::query()
                ->where('is_active', true)
                ->where('receives_pricing_alerts', true)
                ->get();

            if ($recipients->isEmpty()) {
                // The run still FAILS on its exit code — the alarm is a
                // courtesy, the non-zero exit is the durable signal.
                $this->warn('No active recipient subscribed to pricing alerts; nothing emailed.');

                return;
            }

            Notification::send($recipients, new PricingHealthNotification(
                belowCost: $belowCost,
                belowFloor: $belowFloor,
                costFaultCount: count($costFaults),
            ));

            $this->line(sprintf('  Alerted %d recipient(s).', $recipients->count()));
        } catch (Throwable $e) {
            // Notification failure must never mask the finding itself.
            Log::warning('pricing.health_alert_failed', ['error' => $e->getMessage()]);
        }
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
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderSection(string $title, array $rows, int $limit): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf('%s — %d', $title, count($rows)));

        usort($rows, static fn (array $a, array $b): int => ($a['margin_bps'] ?? 0) <=> ($b['margin_bps'] ?? 0));

        $this->table(
            ['SKU', 'Cost', 'Price', 'Floor price', 'Margin'],
            array_map(static fn (array $r): array => [
                $r['sku'],
                number_format($r['buy'] / 100, 2),
                number_format($r['sell'] / 100, 2),
                number_format($r['floor'] / 100, 2),
                $r['margin_bps'] === null ? '-' : number_format($r['margin_bps'] / 100, 1).'%',
            ], array_slice($rows, 0, min($limit, self::REPORT_CAP))),
        );

        $more = count($rows) - min($limit, self::REPORT_CAP);
        if ($more > 0) {
            $this->line("  … and {$more} more.");
        }
    }
}
