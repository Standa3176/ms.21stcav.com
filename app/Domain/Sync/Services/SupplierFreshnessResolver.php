<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260608-g8x — single source of truth for supplier fresh/amber/stale.
 *
 * Per the 260607-pys drift-prevention principle: flipping the freshness
 * policy or moving the threshold must be a ONE-FILE edit. Three downstream
 * consumers (AdCandidateScanner, CompetitorPositionScanner, SupplierDbSyncCommand)
 * opt in via constructor flag defaulting TRUE; they ALL ask this resolver.
 *
 * Classification rules:
 *   fresh   — MAX(recorded_at) >= today() - threshold_days (default 7)
 *   amber   — days_since >= floor(threshold * amber_warning_ratio)
 *             AND days_since < threshold  (default ratio 0.7 → {4,5,6} for 7d)
 *   stale   — days_since >= threshold
 *   unknown — supplier has zero snapshots ever
 *
 * Per-supplier threshold override lives on `suppliers.stale_after_days`. NULL
 * falls back to `config('supplier.default_stale_after_days', 7)`.
 *
 * Per-request memoisation: the classification map is built ONCE per resolve
 * (lazy on first method call) and reused for the lifetime of the resolver
 * instance. Singleton-bound at the framework level. This prevents the
 * "3 scanners each querying supplier_offer_snapshots" anti-pattern — Pest
 * case F pins it with DB::enableQueryLog().
 *
 * Driver-aware days-since SQL:
 *   MySQL  — DATEDIFF(CURDATE(), MAX(recorded_at))
 *   SQLite — CAST(julianday('now','start of day') - julianday(MAX(recorded_at)) AS INTEGER)
 * resolved via the SupplierOfferSnapshot model connection's getDriverName()
 * (facade-free — SYNC-04 bans the Illuminate DB facade from the Sync layer).
 */
final class SupplierFreshnessResolver
{
    /**
     * Per-request cache. NULL = not loaded yet (lazy on first read).
     * Shape: supplier_id (string) => [
     *   'status' => 'fresh'|'amber'|'stale'|'unknown',
     *   'threshold_days' => int,
     *   'latest_recorded_at' => ?Carbon,
     *   'days_since' => ?int,
     *   'name' => ?string,
     * ]
     *
     * @var array<string, array{status:string,threshold_days:int,latest_recorded_at:?Carbon,days_since:?int,name:?string}>|null
     */
    private ?array $cache = null;

    /**
     * Fresh supplier_ids (status='fresh'). Strings — supplier_id is VARCHAR(16).
     *
     * @return Collection<int, string>
     */
    public function freshSupplierIds(): Collection
    {
        $this->loadCache();

        return collect($this->cache)
            ->filter(static fn (array $row): bool => $row['status'] === 'fresh')
            ->keys()
            ->values();
    }

    /**
     * Stale supplier_ids (status='stale').
     *
     * @return Collection<int, string>
     */
    public function staleSupplierIds(): Collection
    {
        $this->loadCache();

        return collect($this->cache)
            ->filter(static fn (array $row): bool => $row['status'] === 'stale')
            ->keys()
            ->values();
    }

    /**
     * Amber supplier_ids (status='amber').
     *
     * @return Collection<int, string>
     */
    public function amberSupplierIds(): Collection
    {
        $this->loadCache();

        return collect($this->cache)
            ->filter(static fn (array $row): bool => $row['status'] === 'amber')
            ->keys()
            ->values();
    }

    /**
     * Returns 'fresh'|'amber'|'stale'|'unknown'. Supplier with zero snapshots
     * → 'unknown' (also covers supplier_id we have never observed).
     */
    public function classify(string $supplierId): string
    {
        $this->loadCache();

        return $this->cache[$supplierId]['status'] ?? 'unknown';
    }

    /**
     * Per-supplier threshold (override OR config default). Cached.
     */
    public function thresholdDaysFor(string $supplierId): int
    {
        $this->loadCache();

        return $this->cache[$supplierId]['threshold_days']
            ?? (int) config('supplier.default_stale_after_days', 7);
    }

    /**
     * MAX(recorded_at) for the supplier or NULL when no snapshots.
     */
    public function latestRecordedAtFor(string $supplierId): ?Carbon
    {
        $this->loadCache();

        return $this->cache[$supplierId]['latest_recorded_at'] ?? null;
    }

    /**
     * Days since most-recent snapshot, NULL when no snapshots.
     */
    public function daysSinceFor(string $supplierId): ?int
    {
        $this->loadCache();

        return $this->cache[$supplierId]['days_since'] ?? null;
    }

    /**
     * Display name (from supplier_offer_snapshots OR suppliers table).
     */
    public function nameFor(string $supplierId): ?string
    {
        $this->loadCache();

        return $this->cache[$supplierId]['name'] ?? null;
    }

    /**
     * All supplier_ids the resolver knows about (union of snapshots ∪ suppliers).
     *
     * @return Collection<int, string>
     */
    public function allKnownSupplierIds(): Collection
    {
        $this->loadCache();

        return collect(array_keys($this->cache))->values();
    }

    /**
     * Force-rebuild the per-request cache. Tests + the snapshot command call
     * this between data mutations + classification reads.
     */
    public function forget(): void
    {
        $this->cache = null;
    }

    /**
     * Build the classification map ONCE per resolve. Two queries total:
     *
     *   1. supplier_offer_snapshots grouped by supplier_id with MAX(recorded_at) + days_since
     *   2. suppliers (per-supplier overrides + names)
     *
     * Suppliers in `suppliers` table without snapshots → 'unknown'.
     * Suppliers in snapshots without `suppliers` row → classified with the default threshold
     *   (so freshness works on day 1 before the discovery upsert runs).
     */
    private function loadCache(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $defaultThreshold = (int) config('supplier.default_stale_after_days', 7);
        $amberRatio = (float) config('supplier.amber_warning_ratio', 0.7);

        // ─── Pull suppliers (per-supplier overrides) ────────────────────
        $overrides = []; // supplier_id (string) => [threshold_days:int, name:?string]
        if (Schema::hasTable('suppliers')) {
            foreach (
                Supplier::query()
                    ->select(['supplier_id', 'stale_after_days', 'name'])
                    ->get() as $sup
            ) {
                $sid = (string) $sup->supplier_id;
                if ($sid === '') {
                    continue;
                }
                $overrides[$sid] = [
                    'threshold_days' => $sup->stale_after_days !== null
                        ? (int) $sup->stale_after_days
                        : $defaultThreshold,
                    'name' => $sup->name,
                ];
            }
        }

        // ─── Pull observed (supplier_id, MAX(recorded_at), days_since, latest_name) ───
        // Driver-aware date math: MySQL DATEDIFF vs SQLite julianday.
        $observed = []; // supplier_id => [latest:?string, days_since:?int, name:?string]
        if (Schema::hasTable('supplier_offer_snapshots')) {
            $driver = SupplierOfferSnapshot::query()->getConnection()->getDriverName();
            $daysExpr = $driver === 'sqlite'
                ? "CAST(julianday('now','start of day') - julianday(MAX(recorded_at)) AS INTEGER)"
                : 'DATEDIFF(CURDATE(), MAX(recorded_at))';

            $rows = SupplierOfferSnapshot::query()
                ->selectRaw(
                    "supplier_id, MAX(recorded_at) AS latest_recorded_at, {$daysExpr} AS days_since, MAX(supplier_name) AS supplier_name"
                )
                ->whereNotNull('supplier_id')
                ->where('supplier_id', '!=', '')
                ->groupBy('supplier_id')
                ->get();

            foreach ($rows as $r) {
                $sid = (string) $r->supplier_id;
                $observed[$sid] = [
                    'latest' => $r->latest_recorded_at !== null
                        ? (string) $r->latest_recorded_at
                        : null,
                    'days_since' => $r->days_since !== null ? (int) $r->days_since : null,
                    'name' => $r->supplier_name ?? null,
                ];
            }
        }

        // ─── Classify ──────────────────────────────────────────────────
        $cache = [];

        // Suppliers we have observed (snapshots exist).
        foreach ($observed as $sid => $row) {
            $threshold = $overrides[$sid]['threshold_days'] ?? $defaultThreshold;
            $days = $row['days_since'];
            $amberBoundary = (int) floor($threshold * $amberRatio);

            if ($days === null) {
                $status = 'unknown';
            } elseif ($days >= $threshold) {
                $status = 'stale';
            } elseif ($days >= $amberBoundary) {
                $status = 'amber';
            } else {
                $status = 'fresh';
            }

            $cache[$sid] = [
                'status' => $status,
                'threshold_days' => $threshold,
                'latest_recorded_at' => $row['latest'] !== null
                    ? Carbon::parse($row['latest'])
                    : null,
                'days_since' => $days,
                'name' => $overrides[$sid]['name'] ?? $row['name'] ?? null,
            ];
        }

        // Suppliers in `suppliers` table but with NO snapshots → 'unknown'.
        foreach ($overrides as $sid => $meta) {
            if (isset($cache[$sid])) {
                continue;
            }
            $cache[$sid] = [
                'status' => 'unknown',
                'threshold_days' => $meta['threshold_days'],
                'latest_recorded_at' => null,
                'days_since' => null,
                'name' => $meta['name'],
            ];
        }

        $this->cache = $cache;
    }
}
