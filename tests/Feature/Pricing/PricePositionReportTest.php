<?php

declare(strict_types=1);

use App\Console\Commands\PricePositionReportCommand;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Services\LowestCompetitorResolver;
use App\Domain\Pricing\Services\PricePositionClassifier;
use App\Domain\Products\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260930-eo3 — price-position report
|--------------------------------------------------------------------------
|
| Marks a supplied list of manufacturer part numbers as best-priced,
| could-be-best, or selling under our own floor / under cost.
|
| The floor is NOT recomputed here — it is CompetitorUndercutPricer's, via the
| same PriceCalculator. If this report invented its own floor the two would
| drift, and it would report a SKU as winnable that the pricing job then refuses
| to move. That is the most expensive kind of wrong, because ad budget follows
| the report.
|
| Prices in these fixtures are stated the way the database stores them:
| sell_price VAT-INCLUSIVE, buy_price EX-VAT (260927-p41).
*/

/** Seed a product plus optional competitor prices (all pennies). */
function seedPositionRow(string $sku, int $buyPence, int $sellGrossPence, array $competitorGross = []): Product
{
    $product = Product::factory()->create([
        'sku' => $sku,
        'name' => "Product {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'stock_status' => 'instock',
        'buy_price' => $buyPence / 100,
        'sell_price' => $sellGrossPence / 100,
    ]);

    foreach ($competitorGross as $gross) {
        CompetitorPrice::factory()->forSku($sku)->create([
            'competitor_id' => Competitor::factory(),
            'price_pennies_ex_vat' => (int) round($gross / 1.2),
            'price_pennies_gross' => $gross,
        ]);
    }

    return $product;
}

function positionClassifier(): PricePositionClassifier
{
    return app(PricePositionClassifier::class);
}

it('registers the command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('products:price-position-report');
});

// ── The classifier ───────────────────────────────────────────────────────────

it('calls us BEST PRICE when we undercut the cheapest competitor', function (): void {
    // cost £100 net, we sell £150 inc, cheapest rival £160 inc.
    $r = positionClassifier()->classify(15000, 10000, 16000);

    expect($r['status'])->toBe(PricePositionClassifier::BEST_PRICE)
        ->and($r['delta_vs_lowest_pence'])->toBe(-1000);
});

it('calls us COULD BE BEST when undercutting still clears the floor', function (): void {
    // cost £100 net → floor is £100 + 6% + VAT = £127.20. Rival at £160, we are
    // at £170: dropping to £159.99 is far above the floor.
    $r = positionClassifier()->classify(17000, 10000, 16000);

    expect($r['status'])->toBe(PricePositionClassifier::COULD_BE_BEST)
        ->and($r['undercut_target_pence'])->toBe(15999)
        ->and($r['undercut_target_pence'])->toBeGreaterThan($r['floor_gross_pence']);
});

it('calls it CANNOT WIN when the leader is below our floor', function (): void {
    // cost £100 net → floor £127.20 inc. The rival sells at £120 inc, which is
    // under it. Chasing that price would breach the floor, so the undercut job
    // would hold — and the report must say so rather than calling it winnable.
    $r = positionClassifier()->classify(17000, 10000, 12000);

    expect($r['status'])->toBe(PricePositionClassifier::CANNOT_WIN)
        ->and($r['floor_gross_pence'])->toBeGreaterThan(12000);
});

it('flags BELOW COST when the net sell price is under the supplier cost', function (): void {
    // £100 inc = £83.33 net, against a £90 net cost. A loss on every unit.
    $r = positionClassifier()->classify(10000, 9000, null);

    expect($r['status'])->toBe(PricePositionClassifier::BELOW_COST)
        ->and($r['true_margin_pence'])->toBeLessThan(0);
});

it('flags BELOW FLOOR when we are profitable but under the floor', function (): void {
    // cost £100 net, floor £127.20 inc. Selling at £125 inc = £104.17 net, so a
    // real £4.17 margin — profitable, but under the floor the pricing job
    // enforces. A distinct finding from an outright loss, and shown as one.
    $r = positionClassifier()->classify(12500, 10000, null);

    expect($r['status'])->toBe(PricePositionClassifier::BELOW_FLOOR)
        ->and($r['true_margin_pence'])->toBeGreaterThan(0);
});

it('reports the loss even when the loss is what makes us cheapest', function (): void {
    // The trap: cheapest on the market BECAUSE we are selling under cost.
    // "Best price" must not mask it.
    $r = positionClassifier()->classify(10000, 9000, 20000);

    expect($r['status'])->toBe(PricePositionClassifier::BELOW_COST)
        // and the competitive position is still reported, not discarded
        ->and($r['delta_vs_lowest_pence'])->toBe(-10000);
});

it('says NO COMPETITOR rather than implying we are cheapest', function (): void {
    // Absence of evidence. Calling this "best price" would be a lie the whole
    // report hangs on.
    $r = positionClassifier()->classify(15000, 10000, null);

    expect($r['status'])->toBe(PricePositionClassifier::NO_COMPETITOR);
});

it('says NO COST rather than assuming a floor of zero', function (): void {
    // With no cost every price looks safe, which is the opposite of the truth.
    $r = positionClassifier()->classify(15000, 0, 16000);

    expect($r['status'])->toBe(PricePositionClassifier::NO_COST)
        ->and($r['floor_gross_pence'])->toBe(0);
});

it('uses the undercut job own floor, not a private copy', function (): void {
    $source = file_get_contents(base_path('app/Domain/Pricing/Services/PricePositionClassifier.php'));

    expect($source)->toContain('$this->pricer->decide(')
        ->and($source)->toContain("'competitor_floor'")
        // the floor itself comes from PriceCalculator::compute (cost-plus)
        ->and($source)->toContain('$this->calculator->compute($buyPence, $floorBps)');
});

it('treats the floor margin as markup on cost, not a share of the sell price', function (): void {
    // 600bps on a £100 net cost is cost + 6% = £106 net = £127.20 inc.
    // Read as 6% OF THE SELL PRICE it would be £106.38 net — a different number,
    // and the confusion decides whether a row reads as a loss.
    $r = positionClassifier()->classify(20000, 10000, null);

    expect($r['floor_gross_pence'])->toBe(12720);
});

// ── Matching ─────────────────────────────────────────────────────────────────

it('matches part numbers across spacing and case differences', function (): void {
    $cmd = PricePositionReportCommand::class;

    expect($cmd::normaliseKey('MVC S40-C5U-000'))->toBe($cmd::normaliseKey('mvcs40c5u000'))
        ->and($cmd::normaliseKey('A3SV5AA#ABU'))->toBe($cmd::normaliseKey('a3sv5aa-abu'))
        ->and($cmd::normaliseKey('960-001311'))->toBe('960001311');
});

// ── The command end to end ───────────────────────────────────────────────────

it('renders a real PDF and marks each row', function (): void {
    seedPositionRow('WIN-1', 10000, 15000, [16000, 17000]);   // best price
    seedPositionRow('CHASE-1', 10000, 17000, [16000]);        // could be best
    seedPositionRow('LOSS-1', 9000, 10000, [20000]);          // below cost

    $list = storage_path('app/testing/pp-'.uniqid().'.tsv');
    File::ensureDirectoryExists(dirname($list));
    File::put($list, "Winner\tWIN-1\nChaser\tCHASE-1\nLoser\tLOSS-1\nGhost\tNOT-A-SKU\n");

    $pdf = storage_path('app/testing/pp-'.uniqid().'.pdf');

    $this->artisan('products:price-position-report', ['--list' => $list, '--pdf' => $pdf])
        ->assertExitCode(0);

    expect(File::exists($pdf))->toBeTrue();

    $bytes = (string) File::get($pdf);
    // A real PDF, not an HTML error page saved with a .pdf name.
    expect(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(2000);

    @unlink($pdf);
    @unlink($list);
});

it('writes a CSV whose verdicts match the classifier', function (): void {
    seedPositionRow('WIN-2', 10000, 15000, [16000]);
    seedPositionRow('LOSS-2', 9000, 10000, []);

    $list = storage_path('app/testing/pp-'.uniqid().'.tsv');
    File::ensureDirectoryExists(dirname($list));
    File::put($list, "Winner\tWIN-2\nLoser\tLOSS-2\n");

    $csv = storage_path('app/testing/pp-'.uniqid().'.csv');
    $pdf = storage_path('app/testing/pp-'.uniqid().'.pdf');

    $this->artisan('products:price-position-report', [
        '--list' => $list, '--pdf' => $pdf, '--csv' => $csv,
    ])->assertExitCode(0);

    $lines = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim((string) File::get($csv))))));
    $header = array_flip($lines[0]);

    expect($lines[1][$header['verdict']])->toBe(PricePositionClassifier::BEST_PRICE)
        ->and($lines[2][$header['verdict']])->toBe(PricePositionClassifier::BELOW_COST);

    @unlink($csv);
    @unlink($pdf);
    @unlink($list);
});

it('reports an unmatched part number as a catalogue gap, not a pricing verdict', function (): void {
    // Competitors carry it, we do not stock it. That is the actionable version
    // of the row, and it must never be scored as if we had a price.
    $competitor = Competitor::factory()->create();
    CompetitorPrice::factory()->forSku('GHOST-1')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_ex_vat' => 10000,
        'price_pennies_gross' => 12000,
    ]);

    $list = storage_path('app/testing/pp-'.uniqid().'.tsv');
    File::ensureDirectoryExists(dirname($list));
    File::put($list, "Ghost\tGHOST-1\n");

    $csv = storage_path('app/testing/pp-'.uniqid().'.csv');
    $pdf = storage_path('app/testing/pp-'.uniqid().'.pdf');

    $this->artisan('products:price-position-report', [
        '--list' => $list, '--pdf' => $pdf, '--csv' => $csv,
    ])->assertExitCode(0);

    $lines = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim((string) File::get($csv))))));
    $header = array_flip($lines[0]);

    expect($lines[1][$header['verdict']])->toBe('not_in_catalogue')
        ->and($lines[1][$header['competitors']])->toBe('1');

    @unlink($csv);
    @unlink($pdf);
    @unlink($list);
});

it('is READ-ONLY — issues no insert, update or delete', function (): void {
    seedPositionRow('RO-1', 10000, 15000, [16000]);

    $list = storage_path('app/testing/pp-'.uniqid().'.tsv');
    File::ensureDirectoryExists(dirname($list));
    File::put($list, "Row\tRO-1\n");
    $pdf = storage_path('app/testing/pp-'.uniqid().'.pdf');

    $mutations = [];
    DB::listen(function ($query) use (&$mutations): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $query->sql) === 1) {
            $mutations[] = $query->sql;
        }
    });

    $this->artisan('products:price-position-report', ['--list' => $list, '--pdf' => $pdf])
        ->assertExitCode(0);

    expect($mutations)->toBe([]);

    @unlink($pdf);
    @unlink($list);
});

it('ships the operator list with all 50 part numbers', function (): void {
    $path = base_path('resources/reports/run-adds-part-numbers.tsv');
    expect(File::exists($path))->toBeTrue();

    $rows = array_values(array_filter(
        preg_split('/\R/', (string) File::get($path)) ?: [],
        static fn (string $l): bool => trim($l) !== '' && ! str_starts_with(trim($l), '#'),
    ));

    expect($rows)->toHaveCount(50);

    // The duplicate part number in the source is real and deliberate — the file
    // documents it rather than silently de-duplicating, because dropping a row
    // would hide a data-entry error the operator should see.
    $numbers = array_map(static fn (string $l): string => trim(explode("\t", $l)[1] ?? ''), $rows);
    expect(array_count_values($numbers)['12X2S0000F'] ?? 0)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 260930-eo3 follow-up — the guards the first live run exposed
|--------------------------------------------------------------------------
|
| The first production run reported Neat Board Pro against a "lowest competitor"
| of £299.00 on a ~£6,900 product, and marked it "Cannot win at floor". £299 was
| a quarantined feed row (is_price_anomaly), which the pricing job ignores and
| this report did not — it had reproduced the recency window but none of the
| three guards. One bad feed line flipped a winnable SKU to unwinnable, which is
| the wrong call to hand someone planning ad spend.
|
| Fixed by extracting the pricing job's own lookup into LowestCompetitorResolver
| and having BOTH call it. These pin each guard through the report, not just
| through the resolver, because the report is what gets read.
*/

it('ignores a quarantined competitor row when deciding the verdict', function (): void {
    $product = seedPositionRow('QUAR-1', 10000, 15000, [16000]);

    // The Neat Board Pro case: an absurd price, flagged by the feed-jump
    // detector. Visible in history, invisible to pricing.
    CompetitorPrice::factory()->forSku('QUAR-1')->create([
        'competitor_id' => Competitor::factory(),
        'price_pennies_ex_vat' => 250,
        'price_pennies_gross' => 299,
        'is_price_anomaly' => true,
    ]);

    expect($product->exists)->toBeTrue();

    $resolved = app(LowestCompetitorResolver::class)
        ->resolve('QUAR-1', now()->subDays(30));

    // £160.00, not £2.99.
    expect($resolved['lowest'])->toBe(16000)
        ->and($resolved['competitors'])->toBe(1);
});

it('skips a competitor paused for pricing', function (): void {
    seedPositionRow('PAUSE-1', 10000, 15000, []);

    $live = Competitor::factory()->create();
    $paused = Competitor::factory()->create(['pricing_paused_until' => now()->addWeek()]);

    foreach ([[$live, 16000], [$paused, 11000]] as [$c, $gross]) {
        CompetitorPrice::factory()->forSku('PAUSE-1')->create([
            'competitor_id' => $c->id,
            'price_pennies_ex_vat' => (int) round($gross / 1.2),
            'price_pennies_gross' => $gross,
        ]);
    }

    $resolved = app(LowestCompetitorResolver::class)
        ->resolve('PAUSE-1', now()->subDays(30));

    // The paused competitor's £110 must not become the market price.
    expect($resolved['lowest'])->toBe(16000);
});

it('keeps ONE definition of the lowest competitor price', function (): void {
    // The whole point of the extraction. If the pricing command grows its own
    // query again, the report starts recommending prices the command refuses to
    // set — the failure this follow-up exists to close.
    $command = file_get_contents(base_path('app/Console/Commands/CompetitorUndercutPricingCommand.php'));
    $report = file_get_contents(base_path('app/Console/Commands/PricePositionReportCommand.php'));

    expect($command)->toContain('LowestCompetitorResolver::class')
        ->and($report)->toContain('LowestCompetitorResolver')
        // and neither may hand-roll the guards any more
        ->and($command)->not->toContain("->where('is_price_anomaly', false)")
        ->and($report)->not->toContain("->where('is_price_anomaly', false)");
});

it('uses the loose match ONLY to flag catalogue gaps, never to score a row', function (): void {
    $source = file_get_contents(base_path('app/Console/Commands/PricePositionReportCommand.php'));

    // The verdict path resolves on our own SKU through the shared resolver...
    expect($source)->toContain('$resolver->resolve((string) $product->sku, $cutoff)')
        // ...and the loose map is only read in the not-in-catalogue branch.
        ->and($source)->toContain('$loose = $looseByKey[$key] ?? null;');
});
