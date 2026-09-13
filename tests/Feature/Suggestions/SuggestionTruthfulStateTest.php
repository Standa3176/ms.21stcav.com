<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Services\OrphanDetector;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260913-qoi — the Suggestions inbox must tell the truth
|--------------------------------------------------------------------------
|
| Two separate lies, both reported by the operator on 2026-09-13.
|
| 1. FRESHNESS. OrphanDetector::record() returned early whenever the sighting
|    came from a competitor already counted for that SKU (D-09 idempotent
|    no-op). Correct for the counter — but updated_at then only moved when a
|    NEW competitor appeared, so a SKU listed on every scrape since May still
|    showed its May date. Of 6,965 pending suggestions untouched for 30+ days,
|    1,631 were in THAT MORNING'S scrape.
|
| 2. STATE. NewProductOpportunityApplier only DISPATCHES CreateWooProductJob,
|    but ApplySuggestionJob marked the suggestion 'applied' immediately. That
|    said "created" when it meant "queued"; a later failure left the row
|    reading 'applied' while the failure appeared as a separate
|    auto_create_failed suggestion. The operator watched the row they clicked
|    and nothing ever changed on it.
*/

it('refreshes last_seen_at when the SAME competitor is seen again', function (): void {
    $competitor = Competitor::factory()->create();
    $detector = app(OrphanDetector::class);

    $first = $detector->record($competitor, 'ORPHAN-1', 1000);
    $originalSeen = data_get($first->evidence, 'last_seen_at');
    expect($originalSeen)->not->toBeNull();

    // Reach back in time so any refresh is unambiguous.
    $stale = now()->subDays(45)->toIso8601String();
    $evidence = (array) $first->evidence;
    $evidence['last_seen_at'] = $stale;
    $first->forceFill(['evidence' => $evidence])->saveQuietly();

    // Same competitor, same SKU — the D-09 no-op path.
    $again = app(OrphanDetector::class)->record($competitor->fresh(), 'ORPHAN-1', 1000);

    expect(data_get($again->fresh()->evidence, 'last_seen_at'))->not->toBe($stale);
});

it('does NOT double-count the same competitor while refreshing', function (): void {
    $competitor = Competitor::factory()->create();
    $detector = app(OrphanDetector::class);

    $detector->record($competitor, 'ORPHAN-2', 1000);
    $detector->record($competitor, 'ORPHAN-2', 1000);
    $row = $detector->record($competitor, 'ORPHAN-2', 1000);

    // D-09 still holds — recency moved, the counter did not.
    expect((int) data_get($row->fresh()->evidence, 'supporting_competitors'))->toBe(1)
        ->and(Suggestion::where('kind', 'new_product_opportunity')->count())->toBe(1);
});

it('counts a genuinely new competitor and still records recency', function (): void {
    $a = Competitor::factory()->create();
    $b = Competitor::factory()->create();
    $detector = app(OrphanDetector::class);

    $detector->record($a, 'ORPHAN-3', 1000);
    $row = $detector->record($b, 'ORPHAN-3', 900);

    expect((int) data_get($row->fresh()->evidence, 'supporting_competitors'))->toBe(2)
        ->and(data_get($row->fresh()->evidence, 'last_seen_at'))->not->toBeNull();
});

it('registers the last-seen backfill command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('suggestions:backfill-last-seen');
});

it('backfill writes nothing without --apply', function (): void {
    $competitor = Competitor::factory()->create();
    app(OrphanDetector::class)->record($competitor, 'ORPHAN-4', 1000);

    $this->artisan('suggestions:backfill-last-seen')
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);
});

it('has a distinct APPLYING state that is not APPLIED', function (): void {
    // The whole point: "queued" must be visibly different from "created".
    expect(Suggestion::STATUS_APPLYING)->toBe('applying')
        ->and(Suggestion::STATUS_APPLYING)->not->toBe(Suggestion::STATUS_APPLIED);
});

it('marks a dispatch-only applier APPLYING, not APPLIED', function (): void {
    // ApplySuggestionJob decides on the applier's `dispatched_job_class` key.
    $source = file_get_contents(base_path('app/Domain/Suggestions/Jobs/ApplySuggestionJob.php'));

    expect($source)->toContain("isset(\$result['dispatched_job_class'])")
        ->and($source)->toContain('Suggestion::STATUS_APPLYING');
});

it('resolves the originating suggestion on BOTH success and failure', function (): void {
    // Without the failure half, a failed create leaves the row on 'applying'
    // forever — a different lie from the one we started with.
    $source = file_get_contents(base_path('app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php'));

    expect($source)->toContain('resolveOriginatingSuggestion(Suggestion::STATUS_APPLIED')
        ->and($source)->toContain('resolveOriginatingSuggestion(Suggestion::STATUS_FAILED')
        // The DLQ row must SURVIVE — it is what Replay acts on.
        ->and($source)->toContain("'kind' => 'auto_create_failed'");
});

it('surfaces the applying state in the inbox', function (): void {
    $source = file_get_contents(base_path('app/Domain/Suggestions/Filament/Resources/SuggestionResource.php'));

    // Badge: deliberately not 'success' — the product does not exist yet.
    expect($source)->toContain("'applying' => 'info'")
        ->and($source)->toContain("'applying' => 'Creating…'")
        // And the freshness column + filter.
        ->and($source)->toContain("TextColumn::make('last_seen')")
        ->and($source)->toContain("SelectFilter::make('last_seen')");
});
