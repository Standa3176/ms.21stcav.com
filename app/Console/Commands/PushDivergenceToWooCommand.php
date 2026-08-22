<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cutover\Services\DivergenceScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooProductWriter;
use App\Domain\Sync\Support\WooWriteMetrics;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260611-g4q — products:push-divergence-to-woo.
 *
 * Consumes sync_diffs emitted by `cutover:divergence-scan` (260610-qc4) and
 * pushes MS-side truth back to Woo for the 3 deterministically-pushable fields
 * (stock_quantity / buy_price / category_id) — 3,078 of 4,235 cutover-parity
 * gaps surfaced on prod's first 13-field scan (2026-06-11).
 *
 * 260822-rmo added a 4th pushable field, sell_price. The comparator ALREADY
 * emitted sell_price diffs (local sell_price vs Woo `price`, 0.005 tolerance)
 * — there was simply no way to push them, so every price the event-driven
 * PushPriceChangeToWoo listener lost to the write throttle stayed diverged
 * forever. sell_price maps to Woo `regular_price` (VAT-inclusive unless
 * services.woo.push_prices_ex_vat), and WooProductWriter REFUSES to push it
 * for a product with an active Woo sale — see its SALE SAFETY note.
 *
 * Drift-prevention contract: this command supports a FIXED set of 4 fields
 * (SUPPORTED_FIELDS). If WooFieldComparator adds a 14th comparable field
 * (e.g. tags, ean), the next dev must (a) decide whether the new field is
 * pushable MS→Woo here, (b) if yes — add it to SUPPORTED_FIELDS AND extend
 * WooProductWriter's payload builder. The DivergenceComparatorCoverageTest
 * (260610-qc4) will fail when the comparator changes; this command bails on
 * unknown `--field=` values until extended. Pre-GET is non-negotiable for
 * buy_price diffs — Algoritmika WC COG meta lives in meta_data[] alongside
 * Yoast/EAN/brand entries; a blind PUT wipes them all (260611-s2d — the
 * pre-GET + meta-merge logic now lives in WooProductWriter).
 *
 *   php artisan products:push-divergence-to-woo --dry-run
 *   php artisan products:push-divergence-to-woo --field=stock_quantity --no-confirm
 *   php artisan products:push-divergence-to-woo --correlation-id=<uuid>
 *   php artisan products:push-divergence-to-woo --no-confirm
 */
// Not `final` so the Pest feature test can swap WooProductWriter through the
// container without subclassing the command itself (mirrors PushVisibilityToWooCommand).
class PushDivergenceToWooCommand extends BaseCommand
{
    /**
     * Pushable subset of WooFieldComparator's comparable fields.
     *
     * Grep-discoverable. If a new field becomes pushable (e.g. brand_id once
     * the pa_brand→id resolver lands), extend this list AND
     * WooProductWriter::putProductFields(). Unknown `--field=` values bail
     * with a clear error.
     */
    private const SUPPORTED_FIELDS = ['stock_quantity', 'buy_price', 'category_id', 'sell_price'];

    protected $signature = 'products:push-divergence-to-woo
        {--field=stock_quantity,buy_price,category_id : Comma-separated subset of pushable fields (must be subset of SUPPORTED_FIELDS). sell_price is supported but NOT in this default — pass it explicitly, or use cutover:auto-sync whose default includes it.}
        {--limit=0 : Cap PRODUCT count this run (0=unbounded). Limits products, not sync_diff rows.}
        {--chunk=50 : sync_diffs read chunk size for cursor streaming. NOT a Woo concurrency cap.}
        {--dry-run : Print plan without writing to Woo or sync_diffs}
        {--correlation-id= : Override divergence-scan correlation_id; default = latest}
        {--no-confirm : Skip the live-confirmation prompt (for cron-style invocation)}';

    protected $description = 'Push MS-side truth to Woo for stock_quantity / buy_price / category_id divergences surfaced by cutover:divergence-scan (260611-g4q).';

    public function __construct(private readonly WooProductWriter $writer)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Parse + validate --field ──────────────────────────────────────
        $fieldsRaw = (string) $this->option('field');
        $fields = array_values(array_filter(
            array_map('trim', explode(',', $fieldsRaw)),
            static fn (string $s): bool => $s !== '',
        ));

        if ($fields === []) {
            $this->error('No fields specified. --field must be a non-empty comma-separated subset of: '.implode(',', self::SUPPORTED_FIELDS));

            return SymfonyCommand::FAILURE;
        }

        $unknown = array_diff($fields, self::SUPPORTED_FIELDS);
        if ($unknown !== []) {
            $bad = (string) array_values($unknown)[0];
            $this->error("Unsupported field: {$bad}. Supported: ".implode(',', self::SUPPORTED_FIELDS));

            return SymfonyCommand::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $noConfirm = (bool) $this->option('no-confirm');

        // ── 2. Resolve correlation_id ────────────────────────────────────────
        $correlationOverride = $this->option('correlation-id');
        $correlationId = is_string($correlationOverride) && $correlationOverride !== ''
            ? $correlationOverride
            : SyncDiff::query()
                ->where('provider', DivergenceScanner::PROVIDER)
                ->latest('created_at')
                ->value('correlation_id');

        if ($correlationId === null || $correlationId === '') {
            $this->warn('No divergence-scan rows found. Run `php artisan cutover:divergence-scan --live` first.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '[LIVE] ').'products:push-divergence-to-woo — correlation='.$correlationId);

        // ── 3. Stream pending diffs for this correlation_id matching --field ─
        // JSON predicate via whereJsonContains is portable across MySQL + SQLite
        // (Pest tests run on in-memory SQLite). Wrap an OR-block over the
        // requested fields so a single query handles 1-3 of the supported set.
        $pendingQuery = SyncDiff::query()
            ->where('provider', DivergenceScanner::PROVIDER)
            ->where('correlation_id', $correlationId)
            ->where('status', 'pending')
            ->where(function ($q) use ($fields): void {
                foreach ($fields as $f) {
                    $q->orWhereJsonContains('payload->field', $f);
                }
            });

        // Group pending diffs by product_id (read into PHP map; per-product
        // count is bounded — 3 fields max, so memory is O(products)).
        /** @var array<int, array<int, SyncDiff>> $grouped */
        $grouped = [];
        foreach ($pendingQuery->cursor() as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $productId = (int) ($payload['product_id'] ?? 0);
            if ($productId <= 0) {
                // Defensive: ignore rows missing product_id (shouldn't happen
                // post-260610-qc4 emit shape but don't crash on legacy data).
                continue;
            }
            $grouped[$productId][] = $row;
        }

        // ── 4. Apply --limit (caps PRODUCT count, not sync_diff rows) ────────
        if ($limit > 0 && count($grouped) > $limit) {
            $grouped = array_slice($grouped, 0, $limit, true);
        }

        $candidateCount = count($grouped);

        // ── 5. Live-confirmation gate ────────────────────────────────────────
        if (! $dryRun && ! $noConfirm) {
            if (! $this->confirm("About to PUT {$candidateCount} products to live Woo. Continue?")) {
                $this->warn('Aborted by operator.');

                return SymfonyCommand::SUCCESS;
            }
        }

        // ── 6. Initialise counters ───────────────────────────────────────────
        $scanned = 0;
        $pushed = 0;
        $partialSuccess = 0; // forward-compat — single-PUT design keeps this at 0
        $errors = 0;
        $noWooId = 0;
        $wooNotFound = 0;
        $alreadyApplied = 0; // informational — query filters out 'applied', stays 0
        $fieldTally = array_fill_keys(self::SUPPORTED_FIELDS, 0);
        $woulds = 0;
        // 260822-rmo — writer-level guard skips (e.g. sell_price on an on-sale
        // Woo product), tallied by reason so the operator sees WHY a
        // divergence was left alone rather than silently dropped.
        /** @var array<string, int> $skipTally  "field:reason" => count */
        $skipTally = [];
        // 260822-rmo — Woo write ceiling hit mid-run. Rows stay 'pending' so
        // the next run retries them; nothing is lost, nothing double-writes.
        $throttled = 0;
        // 260822-rmo — dry-run detail rows for price divergence:
        // [sku, internal, woo, difference]. Capped when rendered.
        /** @var array<int, array{0:string,1:string,2:string,3:string}> $priceDetail */
        $priceDetail = [];

        // ── 7. Per-product loop ──────────────────────────────────────────────
        foreach ($grouped as $productId => $diffRows) {
            $scanned++;
            $product = Product::find($productId);
            if ($product === null || $product->woo_product_id === null) {
                $noWooId++;
                $this->warn("  no_woo_product_id product_id={$productId} — annotating sync_diff + skipping");
                if (! $dryRun) {
                    $this->markAppliedAnnotated($diffRows, 'no_woo_product_id');
                }

                continue;
            }

            $wooId = (int) $product->woo_product_id;
            $sku = (string) ($product->sku ?? '');

            // Map field → list of related sync_diff rows so we know which rows
            // to flip after a successful PUT.
            /** @var array<string, array<int, SyncDiff>> $diffsByField */
            $diffsByField = [];
            foreach ($diffRows as $row) {
                $payload = is_array($row->payload) ? $row->payload : [];
                $field = (string) ($payload['field'] ?? '');
                if ($field === '' || ! in_array($field, $fields, true)) {
                    continue;
                }
                $diffsByField[$field][] = $row;
            }

            if ($diffsByField === []) {
                // No rows in scope after the --field filter — skip silently.
                continue;
            }

            $fieldsInScope = array_keys($diffsByField);

            // ── Dry-run branch ─────────────────────────────────────────────
            // Call writer with an EMPTY fields list to exercise pre-GET only:
            //   - status='woo_not_found' / 'error' bubbles through normally so
            //     dry-run plan accurately surfaces those outcomes.
            //   - empty $fields ⇒ writer builds empty payload ⇒ NO PUT call.
            // This preserves the 260611-g4q Case H invariant (dry-run = 1 GET,
            // 0 PUTs per product) without smuggling a probe API into the writer.
            if ($dryRun) {
                $probe = $this->writer->putProductFields($product, [], $correlationId);

                if ($probe['status'] === 'woo_not_found') {
                    $wooNotFound++;
                    $this->warn("  woo_not_found woo={$wooId} sku={$sku} — marking sync_diff status=woo_not_found");

                    continue;
                }

                if ($probe['status'] === 'error') {
                    $errors++;
                    $this->warn("  error (GET) woo={$wooId} sku={$sku}: {$probe['reason']}");

                    continue;
                }

                $woulds++;
                $this->line('  would_push woo='.$wooId.' sku='.$sku.' fields='.implode(',', $fieldsInScope));

                foreach ($fieldsInScope as $f) {
                    if (isset($fieldTally[$f])) {
                        $fieldTally[$f]++;
                    }
                }

                // 260822-rmo — capture SKU | internal | Woo | difference for
                // the price-divergence detail table. The sync_diff payload
                // already carries both sides (WooFieldComparator emits
                // laravel/live), so no extra Woo call is needed.
                foreach (($diffsByField['sell_price'] ?? []) as $priceRow) {
                    $payload = is_array($priceRow->payload) ? $priceRow->payload : [];
                    $internal = (float) ($payload['laravel'] ?? 0);
                    $live = (float) ($payload['live'] ?? 0);
                    $priceDetail[] = [
                        $sku,
                        number_format($internal, 2, '.', ''),
                        number_format($live, 2, '.', ''),
                        sprintf('%+.2f', $internal - $live),
                    ];
                }

                continue;
            }

            // ── Live: delegate to writer ─────────────────────────────────────
            //
            // 260822-rmo — the Woo write ceiling is back-pressure, not an
            // error. Wait out the window once and retry this product; if it is
            // still closed, leave the rows 'pending' and move on — the next
            // run picks them up. Previously WooProductWriter masked the
            // throttle as status='error', which counted it as a failure and
            // left no signal that the ceiling was the real cause.
            try {
                $result = $this->writer->putProductFields($product, $fieldsInScope, $correlationId);
            } catch (WooWriteThrottleException $e) {
                $wait = $e->retryAfterSeconds();
                $this->warn("  throttled woo={$wooId} sku={$sku} — waiting {$wait}s for the write window");
                WooWriteMetrics::increment(WooWriteMetrics::DEFERRED);
                $this->sleepSeconds($wait);

                try {
                    $result = $this->writer->putProductFields($product, $fieldsInScope, $correlationId);
                } catch (WooWriteThrottleException) {
                    $throttled++;
                    $this->warn("  throttled woo={$wooId} sku={$sku} — still closed; leaving rows pending for the next run");

                    continue;
                }
            }

            if ($result['status'] === 'woo_not_found') {
                $wooNotFound++;
                $this->warn("  woo_not_found woo={$wooId} sku={$sku} — marking sync_diff status=woo_not_found");
                $this->markStatus($diffRows, 'woo_not_found');

                continue;
            }

            if ($result['status'] === 'error') {
                $errors++;
                $this->warn("  error woo={$wooId} sku={$sku}: {$result['reason']}");

                continue;
            }

            // 260822-rmo — record writer-level guard skips before the
            // empty-push early-return, so an all-skipped product still
            // reports WHY (e.g. sell_price:on_sale) instead of vanishing.
            foreach (($result['fields_skipped'] ?? []) as $skippedField => $reason) {
                $key = $skippedField.':'.$reason;
                $skipTally[$key] = ($skipTally[$key] ?? 0) + 1;
                $this->line("  skipped woo={$wooId} sku={$sku} field={$skippedField} reason={$reason}");
            }

            $fieldsBeingPushed = $result['fields_pushed'];
            if ($fieldsBeingPushed === []) {
                // Nothing to push for this product (e.g. category_id-only diff
                // but Product has neither category_id nor category_ids set).
                continue;
            }

            $pushed++;
            $this->line('  pushed woo='.$wooId.' sku='.$sku.' fields='.implode(',', $fieldsBeingPushed));

            foreach ($fieldsBeingPushed as $f) {
                if (isset($fieldTally[$f])) {
                    $fieldTally[$f]++;
                }
            }

            // Flip ONLY the rows we actually pushed (respects --field subset
            // re-run safety: rows outside the subset stay 'pending').
            $rowsToApply = [];
            foreach ($fieldsBeingPushed as $f) {
                foreach (($diffsByField[$f] ?? []) as $r) {
                    $rowsToApply[] = $r;
                }
            }
            $this->markStatus($rowsToApply, 'applied');

            // Match pacing of 260607-v5g + 260609-nku + 260611-f1y — 200ms
            // between successful live PUTs. Skipped on errors / dry-run.
            usleep(200_000);
        }

        // ── 260822-rmo — price divergence detail (dry-run only) ──────────────
        // "SKU | internal | Woo | difference" so the operator can size the
        // problem before authorising any write. Capped so a 5,000-row
        // divergence doesn't scroll the terminal into uselessness.
        if ($dryRun && $priceDetail !== []) {
            $cap = 25;
            $shown = array_slice($priceDetail, 0, $cap);

            $this->newLine();
            $this->line('sell_price divergence detail (internal price is the source of truth):');
            $this->table(['SKU', 'Internal', 'Woo', 'Difference'], $shown);

            $more = count($priceDetail) - count($shown);
            if ($more > 0) {
                $this->line("  … and {$more} more sell_price divergence(s) not shown.");
            }
        }

        $rows = [
            ['scanned', $scanned],
            [$dryRun ? 'would_push' : 'pushed', $dryRun ? $woulds : $pushed],
            ['partial_success', $partialSuccess],
            ['errors', $errors],
            ['throttled', $throttled],
            ['no_woo_product_id', $noWooId],
            ['woo_not_found', $wooNotFound],
            ['already_applied', $alreadyApplied],
        ];

        foreach (self::SUPPORTED_FIELDS as $f) {
            $rows[] = ['field:'.$f, $fieldTally[$f]];
        }

        ksort($skipTally);
        foreach ($skipTally as $key => $count) {
            $rows[] = ['skipped:'.$key, $count];
        }

        // Today's Woo-write throttle counters — the fastest read on whether
        // the write path is healthy or jammed (260822-rmo observability).
        $rows[] = ['woo_writes_deferred_today', WooWriteMetrics::read(WooWriteMetrics::DEFERRED)];
        $rows[] = ['woo_writes_failed_today', WooWriteMetrics::read(WooWriteMetrics::FAILED)];

        $this->newLine();
        $this->table(['Outcome', 'Count'], $rows);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Seam so tests can exercise the throttle-wait branch without really
     * sleeping. Bounded — never wait longer than one limiter window plus a
     * small margin, however large a retry-after the client reports.
     */
    protected function sleepSeconds(int $seconds): void
    {
        sleep(max(0, min($seconds, 90)));
    }

    /**
     * Mark a batch of sync_diff rows as applied + applied_at=now().
     *
     * @param  array<int, SyncDiff>  $rows
     */
    private function markStatus(array $rows, string $status): void
    {
        foreach ($rows as $row) {
            $row->status = $status;
            if ($status === 'applied') {
                $row->applied_at = now();
            }
            $row->save();
        }
    }

    /**
     * Mark a batch of rows as applied with a payload annotation indicating
     * WHY they were applied without a real push (e.g. no_woo_product_id).
     * Keeps the row out of future re-run scope while preserving the audit
     * trail.
     *
     * @param  array<int, SyncDiff>  $rows
     */
    private function markAppliedAnnotated(array $rows, string $reason): void
    {
        foreach ($rows as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $payload['applied_with'] = $reason;
            $row->payload = $payload;
            $row->status = 'applied';
            $row->applied_at = now();
            $row->save();
        }
    }
}
