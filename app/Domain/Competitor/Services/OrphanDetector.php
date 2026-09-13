<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Context;

/**
 * Phase 5 Plan 02 Task 2 — Orphan-SKU producer (D-08 + D-09).
 *
 * When a CSV row's SKU matches no Product (after case-insensitive +
 * whitespace-normalised lookup), we fire ONE `new_product_opportunity`
 * suggestion per SKU — NEVER one-per-competitor. Subsequent competitors
 * tracking the same orphan SKU do an updateOrCreate keyed on
 * (kind, evidence->sku) and increment the supporting_competitors counter
 * on evidence JSON. This prevents the 5 competitors × 1000 orphans =
 * 5000-suggestion inbox flood that D-09 explicitly guards against.
 *
 * Idempotency: if the SAME competitor reports the SAME SKU again on a later
 * ingest, the detector no-ops — the existing competitor_id is already in
 * the competitor_sightings array so neither counter nor array grows.
 *
 * Phase 6 will ship the real applier that converts these to supplier-request
 * rows; Plan 05-02 ships NewProductOpportunityApplier as a no-op stub so
 * the kind is recognised by the Suggestions inbox.
 */
final class OrphanDetector
{
    public function record(Competitor $competitor, string $sku, int $priceGrossPennies): Suggestion
    {
        $existing = Suggestion::query()
            ->where('kind', 'new_product_opportunity')
            ->whereJsonContains('evidence->sku', $sku)
            ->first();

        $now = now()->toIso8601String();
        $sighting = [
            'competitor_id' => $competitor->id,
            'name' => $competitor->name,
            'price_gross_pennies' => $priceGrossPennies,
            'recorded_at' => $now,
        ];

        if ($existing === null) {
            return Suggestion::create([
                'kind' => 'new_product_opportunity',
                'status' => Suggestion::STATUS_PENDING,
                'correlation_id' => (string) (Context::get('correlation_id') ?? ''),
                'evidence' => [
                    'sku' => $sku,
                    'supporting_competitors' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'competitor_sightings' => [$sighting],
                ],
                'payload' => ['sku' => $sku],
                'proposed_at' => now(),
            ]);
        }

        $evidence = (array) $existing->evidence;
        $sightings = $evidence['competitor_sightings'] ?? [];

        // Has THIS competitor already been counted? Keyed on competitor_id.
        $alreadyCounted = false;
        foreach ($sightings as $existingSighting) {
            if ((int) ($existingSighting['competitor_id'] ?? 0) === $competitor->id) {
                $alreadyCounted = true;
                break;
            }
        }

        // 260913-qoi — an already-counted competitor still REFRESHES recency.
        //
        // This used to `return $existing` untouched (D-09 idempotent no-op).
        // That correctly stops supporting_competitors being incremented twice
        // for the same competitor — but it also meant `updated_at` only ever
        // moved when a NEW competitor appeared. A SKU that Screenmoove has
        // listed on every scrape since May still carried its original May
        // timestamp, so the Suggestions inbox showed live, currently-sourceable
        // products as a month stale.
        //
        // Measured 2026-09-13: of 6,965 pending suggestions untouched for 30+
        // days, 1,631 were in THAT MORNING'S scrape. The operator could not
        // tell a dead listing from a live one.
        //
        // D-09 still holds: the counter and the sightings array are untouched
        // on this path. Only last_seen_at and the row timestamp move.
        if ($alreadyCounted) {
            $evidence['last_seen_at'] = $now;
            $existing->evidence = $evidence;
            $existing->save();

            return $existing;
        }

        $sightings[] = $sighting;
        $evidence['competitor_sightings'] = $sightings;
        $evidence['supporting_competitors'] = count($sightings);
        $evidence['last_seen_at'] = $now;

        $existing->evidence = $evidence;
        $existing->save();

        return $existing;
    }
}
