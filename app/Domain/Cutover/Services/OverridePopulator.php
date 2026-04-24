<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Plan 05 Task 1 — CUT-02 ProductOverride populator (D-14, D-15).
 *
 * Reads the latest divergence scan's SyncDiff rows (provider='divergence-scan',
 * grouped by the most-recent correlation_id) and upserts ProductOverride rows
 * with the corresponding pin_* flags set true.
 *
 * ─ D-15 MERGE SEMANTICS (NEVER-CLEAR-PINS) ────────────────────────────────
 * If an operator has already manually pinned a field on a ProductOverride
 * (pin_title=true), this command NEVER flips it back to false. The merge is
 * strictly additive — we only ever flip pin_X from false→true when the
 * divergence scan detects a live-vs-Laravel mismatch.
 *
 * Concretely:
 *   - No override exists         → create a new override with the scanned pins
 *   - Override exists, pin false → flip to true if scan saw divergence
 *   - Override exists, pin true  → LEAVE TRUE regardless of scan result
 *
 * This preserves human judgement even if the divergence scan runs after an
 * ops-operator has restored a local copy to match Woo (in which case the
 * scan would NOT see a diff — but the pin remains, because someone went to
 * the trouble of setting it once).
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Reads correlation_id via `latest('created_at')` on sync_diffs rows where
 * provider='divergence-scan'. All rows sharing that correlation_id are
 * treated as one scan's output (correlates with DivergenceScanner::scan which
 * threads a single UUID through every diff row).
 */
class OverridePopulator
{
    public const ACTOR = 'cutover-populate-overrides-command';

    /**
     * Columns on ProductOverride that can be toggled by this populator.
     * Mirrors Phase 6 Plan 01's ProductOverride::$fillable pin_* list.
     */
    protected const PIN_COLUMNS = [
        'pin_title',
        'pin_slug',
        'pin_short_description',
        'pin_long_description',
        'pin_meta_description',
        'pin_image',
        'pin_brand',
        'pin_category',
    ];

    public function __construct(protected Auditor $auditor) {}

    /**
     * @param  bool  $persist  When false, compute stats without writing.
     * @return array{products_affected:int, overrides_created:int, overrides_merged:int, pins_set:int}
     */
    public function populateFromScan(bool $persist = false): array
    {
        $stats = [
            'products_affected' => 0,
            'overrides_created' => 0,
            'overrides_merged' => 0,
            'pins_set' => 0,
        ];

        $latestCorrelation = SyncDiff::query()
            ->where('provider', DivergenceScanner::PROVIDER)
            ->latest('created_at')
            ->value('correlation_id');

        if ($latestCorrelation === null) {
            return $stats;
        }

        $rows = SyncDiff::query()
            ->where('provider', DivergenceScanner::PROVIDER)
            ->where('correlation_id', $latestCorrelation)
            ->get();

        // Group by product_id extracted from the JSON payload.
        $byProduct = [];
        foreach ($rows as $row) {
            $payload = $row->payload ?? [];
            $productId = $payload['product_id'] ?? null;
            $pinColumn = $payload['pin_column'] ?? null;

            if ($productId === null || $pinColumn === null) {
                // Non-pinnable field (price) or malformed payload — skip.
                continue;
            }
            if (! in_array($pinColumn, self::PIN_COLUMNS, true)) {
                continue;
            }
            $byProduct[$productId][$pinColumn] = true;
        }

        foreach ($byProduct as $productId => $pinsToSet) {
            $stats['products_affected']++;

            if (! $persist) {
                continue;
            }

            DB::transaction(function () use ($productId, $pinsToSet, &$stats) {
                $override = ProductOverride::query()
                    ->where('product_id', $productId)
                    ->first();

                if ($override === null) {
                    // Create with pins set. margin_basis_points is nullable by
                    // design (Phase 3 Plan 01 — overrides without margin change
                    // are valid, they just carry pin semantics).
                    $attrs = array_merge(
                        ['product_id' => $productId],
                        array_fill_keys(array_keys($pinsToSet), true),
                    );
                    ProductOverride::create($attrs);
                    $stats['overrides_created']++;
                    $stats['pins_set'] += count($pinsToSet);

                    $this->auditor->record('cutover.override_created', [
                        'actor' => self::ACTOR,
                        'product_id' => $productId,
                        'pins_set' => array_keys($pinsToSet),
                    ]);
                } else {
                    // D-15 merge — NEVER clear an existing pin.
                    $changed = [];
                    foreach ($pinsToSet as $col => $_) {
                        if (! (bool) $override->{$col}) {
                            $override->{$col} = true;
                            $changed[] = $col;
                        }
                    }
                    if ($changed !== []) {
                        $override->save();
                        $stats['overrides_merged']++;
                        $stats['pins_set'] += count($changed);

                        $this->auditor->record('cutover.override_merged', [
                            'actor' => self::ACTOR,
                            'product_id' => $productId,
                            'pins_added' => $changed,
                        ]);
                    }
                }
            });
        }

        return $stats;
    }
}
