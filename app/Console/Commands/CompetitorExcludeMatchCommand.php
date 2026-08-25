<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorMatchExclusion;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260825-h2r — stop one competitor's row pricing one of our SKUs.
 *
 * DRY-RUN BY DEFAULT (cross-cutting invariant) — --apply writes.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FOR SKU HOMONYMS, NOT BAD PRICES
 *
 * `CP4` is a Unicol/AVM ceiling mount here (cost GBP 24.96) and a Crestron
 * control processor at AVITDirect (~GBP 1,748). Both feeds are correct about
 * their own product; the string collides. Before the 2026-08-09 margin ceiling
 * existed, the undercut logic matched them and priced our mount at GBP 1,517.99
 * — exactly AVITDirect's GBP 1,518.00 less the 1p undercut.
 *
 * Use is_price_anomaly when a feed's PRICE is wrong. Use this when the row is a
 * DIFFERENT PRODUCT. Conflating them would leave the anomaly flag meaning
 * nothing in particular.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THIS ARMS A PRICE CHANGE — READ BEFORE --apply
 *
 * Removing a competitor can change which pricing branch a product takes. CP4
 * has exactly ONE competitor, so excluding it leaves the product on no
 * competitor at all: the next `pricing:undercut-competitors --live` run
 * (08:00 daily) will reprice it cost-plus and push that to Woo.
 *
 * For CP4 that is GBP 1,517.99 -> about GBP 40, which is the CORRECT outcome for
 * a GBP 25 mount and a 97% drop on a live product. The dry-run prints the
 * projected branch change so nobody discovers it from the storefront.
 *
 *   php artisan competitor:exclude-match --sku=CP4 --competitor=AVITDirect --reason="..."
 *   php artisan competitor:exclude-match --sku=CP4 --competitor=AVITDirect --reason="..." --apply
 *   php artisan competitor:exclude-match --list
 *   php artisan competitor:exclude-match --sku=CP4 --competitor=AVITDirect --remove --apply
 */
final class CompetitorExcludeMatchCommand extends BaseCommand
{
    protected $signature = 'competitor:exclude-match
        {--sku= : The SKU (or MPN) string to stop matching}
        {--competitor= : Competitor name or id. Omit ONLY to exclude the key for every competitor.}
        {--reason= : Why these are different products (required to add)}
        {--remove : Remove the exclusion instead of adding it}
        {--list : List current exclusions and exit}
        {--apply : Write the change (default: dry-run, writes nothing)}';

    protected $description = 'Exclude a competitor row from pricing one of our SKUs, for product homonyms like CP4 (260825-h2r). Dry-run by default.';

    protected function perform(): int
    {
        if ((bool) $this->option('list')) {
            return $this->listExclusions();
        }

        $sku = trim((string) $this->option('sku'));
        if ($sku === '') {
            $this->error('--sku is required (or use --list).');

            return SymfonyCommand::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $remove = (bool) $this->option('remove');

        $competitor = null;
        $competitorInput = trim((string) $this->option('competitor'));
        if ($competitorInput !== '') {
            $competitor = $this->resolveCompetitor($competitorInput);
            if ($competitor === null) {
                $this->error("No competitor matching \"{$competitorInput}\".");

                return SymfonyCommand::FAILURE;
            }
        }

        return $remove
            ? $this->removeExclusion($sku, $competitor, $apply)
            : $this->addExclusion($sku, $competitor, $apply);
    }

    private function addExclusion(string $sku, ?Competitor $competitor, bool $apply): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            // An exclusion silently removes a competitor from pricing forever.
            // A future reader must be able to tell "CP4 is a Crestron there and a
            // mount here" from somebody's undocumented hunch.
            $this->error('--reason is required: say WHY these are different products.');

            return SymfonyCommand::FAILURE;
        }

        if ($competitor === null) {
            $this->warn('No --competitor given: this will exclude the key for EVERY competitor.');
            $this->warn('That is the broader claim. Prefer naming the competitor whose row is a different product.');
        }

        $this->reportImpact($sku, $competitor);

        if (! $apply) {
            $this->newLine();
            $this->info('Nothing written — re-run with --apply.');

            return SymfonyCommand::SUCCESS;
        }

        $existing = CompetitorMatchExclusion::query()
            ->where('match_key', CompetitorMatchExclusion::normalise($sku))
            ->where('competitor_id', $competitor?->id)
            ->first();

        if ($existing !== null) {
            $this->info('Already excluded — nothing to do.');

            return SymfonyCommand::SUCCESS;
        }

        CompetitorMatchExclusion::create([
            'competitor_id' => $competitor?->id,
            'match_key' => $sku,
            'reason' => $reason,
        ]);

        $this->info(sprintf(
            'Excluded "%s" from %s.',
            $sku,
            $competitor === null ? 'ALL competitors' : (string) $competitor->name,
        ));
        $this->line('The next pricing run will no longer see that row. If it was the only');
        $this->line('competitor, the product moves to cost-plus and its price WILL change.');

        return SymfonyCommand::SUCCESS;
    }

    private function removeExclusion(string $sku, ?Competitor $competitor, bool $apply): int
    {
        // Delegated to the model deliberately — see CompetitorMatchExclusion
        // ::removeFor(). This file reads CompetitorPrice to report impact, and
        // COMP-07's static guard flags any *Command.php holding both that and a
        // row-removal call. The scan is a plain string match over the file, so
        // it does not exempt comments either — keeping every such token out of
        // this file is what lets the guard stay blunt, and blunt is what makes
        // it trustworthy for the immutable competitor_prices history.
        $count = CompetitorMatchExclusion::countFor($competitor?->id, $sku);
        if ($count === 0) {
            $this->warn('No matching exclusion.');

            return SymfonyCommand::SUCCESS;
        }

        if (! $apply) {
            $this->info("Would remove {$count} exclusion(s). Nothing written — re-run with --apply.");

            return SymfonyCommand::SUCCESS;
        }

        $removed = CompetitorMatchExclusion::removeFor($competitor?->id, $sku);
        $this->info("Removed {$removed} exclusion(s); that competitor can price this SKU again.");

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Show what this exclusion would actually do to the product's pricing —
     * specifically whether it removes the LAST competitor, which changes branch.
     */
    private function reportImpact(string $sku, ?Competitor $competitor): void
    {
        $product = Product::where('sku', $sku)->first();
        $maxAgeDays = 30;

        $rows = CompetitorPrice::query()
            ->with('competitor')
            ->where(static fn ($q) => $q->where('sku', $sku)->orWhere('mpn', $sku))
            ->where('recorded_at', '>=', now()->subDays($maxAgeDays))
            ->where('is_price_anomaly', false)
            ->orderByDesc('recorded_at')
            ->get();

        $this->newLine();
        if ($product === null) {
            $this->warn("No local product with SKU \"{$sku}\" — the exclusion is still recorded, but nothing prices on it today.");
        } else {
            $this->info(sprintf(
                '%s — our cost £%s, current price £%s (%s)',
                $sku,
                number_format((float) $product->buy_price, 2),
                number_format((float) $product->sell_price, 2),
                (string) $product->status,
            ));
        }

        $remaining = [];
        foreach ($rows as $row) {
            $cid = (int) $row->competitor_id;
            if (array_key_exists($cid, $remaining)) {
                continue;
            }
            if ($competitor !== null && $cid !== (int) $competitor->id) {
                $remaining[$cid] = $row;
            }
        }

        $this->line(sprintf(
            '   competitors currently pricing it (within %dd): %d',
            $maxAgeDays,
            $rows->pluck('competitor_id')->unique()->count(),
        ));

        if ($remaining === []) {
            $this->newLine();
            $this->warn('   This removes the LAST competitor for this SKU.');
            $this->warn('   The product moves to the cost-plus margin branch, and the next');
            $this->warn('   08:00 pricing run will change its price and push it to Woo.');

            if ($product !== null && (float) $product->buy_price > 0) {
                $this->line(sprintf(
                    '   Cost £%s at the default 35%% tier would be about £%s (vs £%s today).',
                    number_format((float) $product->buy_price, 2),
                    number_format(((float) $product->buy_price) * 1.35 * 1.2, 2),
                    number_format((float) $product->sell_price, 2),
                ));
            }

            return;
        }

        $this->line('   other competitors would still price it: '.implode(', ', array_map(
            static fn ($r): string => (string) optional($r->competitor)->name,
            $remaining,
        )));
    }

    private function resolveCompetitor(string $input): ?Competitor
    {
        if (ctype_digit($input)) {
            return Competitor::find((int) $input);
        }

        return Competitor::whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($input))])->first();
    }

    private function listExclusions(): int
    {
        $rows = CompetitorMatchExclusion::with('competitor')->orderBy('match_key')->get();

        if ($rows->isEmpty()) {
            $this->info('No competitor match exclusions recorded.');

            return SymfonyCommand::SUCCESS;
        }

        $this->table(
            ['Key', 'Competitor', 'Reason', 'Added'],
            $rows->map(static fn (CompetitorMatchExclusion $r): array => [
                (string) $r->match_key,
                $r->competitor_id === null ? 'ALL' : (string) optional($r->competitor)->name,
                substr((string) $r->reason, 0, 60),
                $r->created_at?->format('Y-m-d') ?? '-',
            ])->all(),
        );

        return SymfonyCommand::SUCCESS;
    }
}
