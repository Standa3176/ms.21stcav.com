<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Database\Seeders\CompetitorDemoSeeder;

/**
 * Phase 5 Plan 04b Task 2 — CompetitorDemoSeeder.
 *
 * Fixture contract: 3 competitors (fresh/stale/missing), 20+ CompetitorPrice
 * rows for a demo SKU across 30 days, 2 Suggestions (margin_change +
 * new_product_opportunity), 1 ambiguous_mapping CsvParseError + matching CSV
 * file under storage/app/competitors/quarantine/.
 *
 * Idempotent: running twice must NOT duplicate rows.
 */
it('seeds 3 demo competitors (fresh / stale / missing)', function (): void {
    $this->seed(CompetitorDemoSeeder::class);

    expect(Competitor::where('slug', 'demo-fresh')->exists())->toBeTrue();
    expect(Competitor::where('slug', 'demo-stale')->exists())->toBeTrue();
    expect(Competitor::where('slug', 'demo-missing')->exists())->toBeTrue();

    $fresh = Competitor::where('slug', 'demo-fresh')->first();
    $stale = Competitor::where('slug', 'demo-stale')->first();
    $missing = Competitor::where('slug', 'demo-missing')->first();

    $threshold = (int) config('competitor.stale_feed_hours', 48);

    expect($fresh->last_ingest_at)->not->toBeNull();
    expect($fresh->last_ingest_at->diffInHours(now()))->toBeLessThan($threshold);
    expect($stale->last_ingest_at)->not->toBeNull();
    expect($stale->last_ingest_at->diffInHours(now()))->toBeGreaterThanOrEqual($threshold);
    expect($missing->last_ingest_at)->toBeNull();
});

it('seeds a demo product + at least 20 CompetitorPrice rows across 30 days', function (): void {
    $this->seed(CompetitorDemoSeeder::class);

    $product = Product::where('sku', 'DEMO-SKU-001')->first();
    expect($product)->not->toBeNull();
    expect((float) $product->sell_price)->toBeGreaterThan(0);

    $priceCount = CompetitorPrice::where('sku', 'DEMO-SKU-001')->count();
    expect($priceCount)->toBeGreaterThanOrEqual(20);
});

it('seeds a pending margin_change Suggestion with D-07 evidence shape', function (): void {
    $this->seed(CompetitorDemoSeeder::class);

    $s = Suggestion::where('kind', 'margin_change')->first();
    expect($s)->not->toBeNull();
    expect($s->status)->toBe(Suggestion::STATUS_PENDING);
    expect($s->evidence)->toHaveKey('sku');
    expect($s->evidence)->toHaveKey('competitor_name');
    expect($s->evidence)->toHaveKey('our_current_margin_bps');
    expect($s->evidence)->toHaveKey('proposed_margin_bps');
    expect($s->evidence)->toHaveKey('margin_delta_bps');
});

it('seeds a new_product_opportunity Suggestion with supporting_competitors=2', function (): void {
    $this->seed(CompetitorDemoSeeder::class);

    $s = Suggestion::where('kind', 'new_product_opportunity')->first();
    expect($s)->not->toBeNull();
    expect((int) ($s->evidence['supporting_competitors'] ?? 0))->toBe(2);
});

it('seeds an ambiguous_mapping parse error + writes the matching CSV to quarantine/', function (): void {
    $this->seed(CompetitorDemoSeeder::class);

    $err = CsvParseError::where('issue_type', CsvParseError::TYPE_AMBIGUOUS_MAPPING)->first();
    expect($err)->not->toBeNull();
    expect($err->filename)->toContain('demo');

    $path = storage_path('app/competitors/quarantine/'.$err->filename);
    expect(is_file($path))->toBeTrue();
});

it('is idempotent — running twice does NOT duplicate rows', function (): void {
    $this->seed(CompetitorDemoSeeder::class);

    $firstCompetitors = Competitor::count();
    $firstPrices = CompetitorPrice::count();
    $firstSuggestions = Suggestion::count();
    $firstParseErrors = CsvParseError::count();

    $this->seed(CompetitorDemoSeeder::class);

    expect(Competitor::count())->toBe($firstCompetitors);
    expect(CompetitorPrice::count())->toBe($firstPrices);
    expect(Suggestion::count())->toBe($firstSuggestions);
    expect(CsvParseError::count())->toBe($firstParseErrors);
});
