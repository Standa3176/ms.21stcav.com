<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Marketing;

use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Integrations\Models\GaChannelMetric;
use Prism\Prism\Facades\Tool as PrismToolFacade;
use Prism\Prism\Tool;

/**
 * Phase 15 Plan 15b-01 — read_ga4_channel_performance (advice-only AdOptimisationAgent).
 *
 * Aggregates the GA4 channel/campaign snapshot (15a — ga_channel_metrics_daily)
 * over the configured lookback window (config
 * `agents.ad_optimisation.data_lookback_days`, env
 * `AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS`, default 30 — the SAME knob the
 * command/dashboard "is there data to review" guard uses) by
 * channel_group + campaign: SUM(sessions),
 * SUM(key_events), SUM(transactions), SUM(revenue) with revenue mapped
 * pennies → £. Ordered revenue-first so the highest-value channels lead the
 * (capped) list.
 *
 * READ-ONLY. This tool never writes to GA4/Google Ads (the app never does —
 * 15c owns any closed-loop). Per-tool 3 KB soft cap with `_truncated:true` +
 * `_total_available:N` hints (mirrors the Phase 10 Pricing read tools). When
 * the snapshot table is empty (no recent pull) the tool returns an empty
 * channels array — never throws (the command's no-op guard means the agent
 * only runs when data exists, but the tool stays defensive regardless).
 *
 * Schema returned:
 * {
 *   "window_days": 30,
 *   "channels": [
 *     {
 *       "channel_group": "Paid Search",
 *       "campaign": "Brand UK",
 *       "sessions": 1200,
 *       "key_events": 40,
 *       "transactions": 12,
 *       "revenue_gbp": 5400.0
 *     }
 *   ],
 *   "_truncated": false,
 *   "_total_available": 8
 * }
 */
final class ReadGa4ChannelPerformanceTool extends TruncatingTool
{
    /**
     * Fallback window (days) used only if the config key is entirely absent.
     * The operative value comes from
     * `config('agents.ad_optimisation.data_lookback_days')`, unifying this
     * read window with the command/dashboard "is there data to review" guard
     * so a single env var (AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS) controls both.
     */
    private const DEFAULT_WINDOW_DAYS = 30;

    public function name(): string
    {
        return 'read_ga4_channel_performance';
    }

    public function description(): string
    {
        $days = $this->windowDays();

        return "Read the last {$days} days of GA4 channel/campaign performance, aggregated by channel group + campaign: sessions, key events, transactions and revenue (£). Highest-revenue rows first. Use to spot which paid/organic channels convert and which underperform.";
    }

    private function windowDays(): int
    {
        return (int) config('agents.ad_optimisation.data_lookback_days', self::DEFAULT_WINDOW_DAYS);
    }

    public function asPrismTool(): Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->using(fn (): string => $this->execute());
    }

    private function execute(): string
    {
        $windowDays = $this->windowDays();
        $since = now()->subDays($windowDays)->toDateString();

        $rows = GaChannelMetric::query()
            ->where('date', '>=', $since)
            ->selectRaw('channel_group')
            ->selectRaw('campaign')
            ->selectRaw('SUM(sessions) as sessions')
            ->selectRaw('SUM(key_events) as key_events')
            ->selectRaw('SUM(transactions) as transactions')
            ->selectRaw('SUM(purchase_revenue_pennies) as revenue_pennies')
            ->groupBy('channel_group', 'campaign')
            ->orderByRaw('SUM(purchase_revenue_pennies) DESC')
            ->get();

        $channels = $rows->map(fn ($r): array => [
            'channel_group' => (string) $r->channel_group,
            'campaign' => $r->campaign !== null ? (string) $r->campaign : null,
            'sessions' => (int) $r->sessions,
            'key_events' => (int) $r->key_events,
            'transactions' => (int) $r->transactions,
            'revenue_gbp' => round(((int) $r->revenue_pennies) / 100, 2),
        ])->values()->all();

        $total = count($channels);

        $payload = [
            'window_days' => $windowDays,
            'channels' => $channels,
            '_truncated' => false,
            '_total_available' => $total,
        ];

        return $this->capJson($payload, $total);
    }

    /**
     * Trim the channels array (already revenue-sorted) to fit under the soft
     * cap. Halves the entry count each invocation, preserving the top rows.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        if (! isset($payload['channels']) || ! is_array($payload['channels'])) {
            return $payload;
        }
        $count = count($payload['channels']);
        if ($count <= 1) {
            return $payload;
        }
        $newCount = max(1, (int) floor($count / 2));
        $payload['channels'] = array_slice($payload['channels'], 0, $newCount);

        return $payload;
    }
}
