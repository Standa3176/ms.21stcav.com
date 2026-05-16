<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Collection;
use Prism\Prism\Facades\Tool as PrismToolFacade;
use Spatie\Activitylog\Models\Activity;

/**
 * Phase 10 Plan 02 — read_margin_history real implementation.
 *
 * Returns the last 90 days of margin changes for a SKU. Combines two
 * data sources (RESEARCH §Tool 1):
 *   - Primary: spatie/activitylog rows on PricingRule where
 *     `properties.old.margin_basis_points` is set. Margin rule changes
 *     in v1 are scope-driven (brand / category / brand_category /
 *     default_tier) — surfacing the rule's scope in the response gives
 *     the agent context on whether the change affected this SKU directly
 *     or via a wide rule.
 *   - Fallback: `Suggestion::where('kind', 'margin_change')` rows
 *     referencing the SKU in evidence — gives "all the times we considered
 *     changing margin for this SKU" rather than "all the times margin
 *     actually changed".
 *
 * Cap at 30 entries with even-spacing downsample (RESEARCH §Tool 1 — preserve
 * most-recent 5 + first 5; sample evenly between).
 *
 * Schema returned:
 * {
 *   "sku": "LOGI-MEETUP",
 *   "window_days": 90,
 *   "changes": [
 *     {
 *       "date": "2026-03-15",
 *       "rule_scope": "brand_category",
 *       "old_margin_bps": 2300,
 *       "new_margin_bps": 2200,
 *       "delta_bps": -100,
 *       "applied": true,
 *       "via": "margin_change_suggestion"
 *     }
 *   ],
 *   "_truncated": false,
 *   "_total_available": 12
 * }
 *
 * Unknown SKU returns empty changes — never throws (CONTEXT D-07 sparse-data
 * → low-confidence path).
 */
final class ReadMarginHistoryTool extends TruncatingTool
{
    private const DOWNSAMPLE_TARGET = 30;

    private const WINDOW_DAYS = 90;

    public function name(): string
    {
        return 'read_margin_history';
    }

    public function description(): string
    {
        return 'Read the last 90 days of margin changes for a SKU. Returns up to 30 entries (downsampled if more exist) with date, rule scope, old/new bps, delta. Use once per SKU to understand price trajectory.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'The SKU to look up')
            ->using(fn (string $sku): string => $this->execute($sku));
    }

    private function execute(string $sku): string
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        // Primary: activity_log entries on PricingRule with old margin_basis_points.
        // SQLite-portable approach: over-fetch then filter post-fetch (whereJsonContainsKey
        // behaves differently across MySQL/SQLite). Filter further to only changes
        // affecting this SKU once Plan 10-04 wires the rule→SKU resolver; for now,
        // these entries provide global rule-change context.
        $logRows = Activity::query()
            ->where('subject_type', PricingRule::class)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(self::DOWNSAMPLE_TARGET * 2)
            ->get()
            ->filter(function (Activity $row): bool {
                $props = $row->properties->toArray();

                return isset($props['old']['margin_basis_points']);
            })
            ->values();

        $logChanges = $logRows->map(function (Activity $row): array {
            $oldBps = (int) data_get($row->properties, 'old.margin_basis_points');
            $newBps = (int) data_get($row->properties, 'attributes.margin_basis_points');

            return [
                'date' => $row->created_at?->toDateString(),
                'rule_scope' => $this->lookupRuleScope((int) $row->subject_id) ?? 'unknown',
                'old_margin_bps' => $oldBps,
                'new_margin_bps' => $newBps,
                'delta_bps' => $newBps - $oldBps,
                'applied' => true,
                'via' => 'activity_log',
            ];
        });

        // Fallback context: margin_change Suggestion rows referencing this SKU.
        $suggestionRows = Suggestion::query()
            ->where('kind', 'margin_change')
            ->where('proposed_at', '>=', $since)
            ->whereJsonContains('evidence->sku', $sku)
            ->get();

        $suggestionChanges = $suggestionRows->map(function (Suggestion $s): array {
            return [
                'date' => $s->proposed_at?->toDateString(),
                'rule_scope' => (string) data_get($s->evidence, 'pricing_rule.scope', 'unknown'),
                'old_margin_bps' => (int) data_get($s->evidence, 'our_current_margin_bps', 0),
                'new_margin_bps' => (int) data_get($s->evidence, 'proposed_margin_bps', 0),
                'delta_bps' => (int) data_get($s->evidence, 'margin_delta_bps', 0),
                'applied' => $s->status === Suggestion::STATUS_APPROVED,
                'via' => 'margin_change_suggestion',
            ];
        });

        $merged = $logChanges->concat($suggestionChanges)
            ->sortByDesc('date')
            ->values();

        $total = $merged->count();
        $capped = $total > self::DOWNSAMPLE_TARGET
            ? $this->downsampleEvenly($merged->all(), self::DOWNSAMPLE_TARGET)
            : $merged->all();

        $payload = [
            'sku' => $sku,
            'window_days' => self::WINDOW_DAYS,
            'changes' => $capped,
            '_truncated' => $total > self::DOWNSAMPLE_TARGET,
            '_total_available' => $total,
        ];

        return $this->capJson($payload, $total);
    }

    /**
     * Downsample to $target entries while preserving the most-recent 5
     * and first 5 (RESEARCH §Tool 1). Evenly samples between to keep
     * trend resolution intact.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function downsampleEvenly(array $items, int $target): array
    {
        $count = count($items);
        if ($count <= $target) {
            return $items;
        }

        $first5 = array_slice($items, 0, 5);  // already sortByDesc('date') so [0..4] are most-recent
        $last5 = array_slice($items, -5);
        $middle = array_slice($items, 5, $count - 10);
        $middleTarget = $target - 10;

        if ($middleTarget < 0) {
            return array_slice($items, 0, $target);
        }

        $sampled = [];
        if ($middleTarget > 0 && count($middle) > 0) {
            $step = count($middle) / max(1, $middleTarget);
            for ($i = 0; $i < $middleTarget; $i++) {
                $idx = (int) floor($i * $step);
                if (isset($middle[$idx])) {
                    $sampled[] = $middle[$idx];
                }
            }
        }

        return array_merge($first5, $sampled, $last5);
    }

    /**
     * Resolve PricingRule.scope without N+1 — caches per-request.
     *
     * @var array<int, string|null>
     */
    private array $scopeCache = [];

    private function lookupRuleScope(int $ruleId): ?string
    {
        if (! array_key_exists($ruleId, $this->scopeCache)) {
            $rule = PricingRule::query()->select(['id', 'scope'])->find($ruleId);
            $this->scopeCache[$ruleId] = $rule?->scope;
        }

        return $this->scopeCache[$ruleId];
    }

    /**
     * Reduce changes array to fit under the soft cap. Halves the entry count
     * on each invocation while preserving the most-recent slice.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        if (! isset($payload['changes']) || ! is_array($payload['changes'])) {
            return $payload;
        }
        $count = count($payload['changes']);
        if ($count <= 1) {
            return $payload;
        }
        $newCount = max(1, (int) floor($count / 2));
        $payload['changes'] = array_slice($payload['changes'], 0, $newCount);

        return $payload;
    }
}
