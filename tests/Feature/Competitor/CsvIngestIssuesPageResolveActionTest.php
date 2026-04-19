<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Pages\CsvIngestIssuesPage;
use App\Domain\Competitor\Jobs\IngestCompetitorCsvJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CsvParseError;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Phase 5 Plan 04b Task 1 — end-to-end Resolve action on the Quarantine tab.
 *
 * Asserts T-05-04b-01 / T-05-04b-04 mitigations: basename() strips any path
 * traversal; submitted column indexes are cast to (int) before persistence;
 * file is moved back to incoming/ and the ingest job is re-dispatched.
 */
beforeEach(function (): void {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    Queue::fake();

    // Clean + ensure quarantine + incoming directories exist.
    $quarantine = storage_path('app/competitors/quarantine');
    $incoming = storage_path('app/competitors/incoming');
    if (! is_dir($quarantine)) {
        @mkdir($quarantine, 0o775, true);
    }
    if (! is_dir($incoming)) {
        @mkdir($incoming, 0o775, true);
    }
});

afterEach(function (): void {
    $path = storage_path('app/competitors/incoming/pest-resolve.csv');
    if (is_file($path)) {
        @unlink($path);
    }
    $path2 = storage_path('app/competitors/quarantine/pest-resolve.csv');
    if (is_file($path2)) {
        @unlink($path2);
    }
});

it('pricing_manager can resolve a quarantined CSV — creates mapping + moves file + re-queues ingest + marks resolved', function (): void {
    $pm = User::factory()->create();
    $pm->assignRole('pricing_manager');

    $competitor = Competitor::factory()->create(['slug' => 'pest-resolver']);

    $csvPath = storage_path('app/competitors/quarantine/pest-resolve.csv');
    file_put_contents($csvPath, "sku,price,name\nABC-1,19.99,Widget A\nXYZ-9,29.50,Widget B\n");

    $err = CsvParseError::create([
        'competitor_id' => $competitor->id,
        'filename' => 'pest-resolve.csv',
        'issue_type' => CsvParseError::TYPE_AMBIGUOUS_MAPPING,
        'context' => ['detail' => 'Column auto-detection returned no candidates'],
    ]);

    Livewire::actingAs($pm)
        ->test(CsvIngestIssuesPage::class)
        ->set('activeTab', 'quarantine')
        ->callTableAction('resolve', $err, data: [
            'sku_column_index' => '0',
            'price_column_index' => '1',
            'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
        ])
        ->assertHasNoTableActionErrors();

    // Mapping persisted.
    $mapping = CompetitorCsvMapping::where('competitor_id', $competitor->id)->first();
    expect($mapping)->not->toBeNull();
    expect((int) $mapping->sku_column_index)->toBe(0);
    expect((int) $mapping->price_column_index)->toBe(1);
    expect($mapping->decimal_format)->toBe(CompetitorCsvMapping::FORMAT_DOT);

    // File moved to incoming/.
    expect(is_file(storage_path('app/competitors/incoming/pest-resolve.csv')))->toBeTrue();
    expect(is_file(storage_path('app/competitors/quarantine/pest-resolve.csv')))->toBeFalse();

    // Ingest job re-dispatched on competitor-csv queue.
    Queue::assertPushedOn('competitor-csv', IngestCompetitorCsvJob::class);

    // Parse-error row marked resolved.
    expect($err->fresh()->resolved_at)->not->toBeNull();
});

it('resolve action is hidden from users without update permission on CompetitorCsvMapping (sales role)', function (): void {
    $sales = User::factory()->create();
    $sales->assignRole('sales');

    // Sales role doesn't have viewAny on CsvParseError (per Plan 05-04a access
    // matrix) → canAccess returns false → the whole page is inaccessible.
    expect(CsvIngestIssuesPage::canAccess())->toBeFalse();
});

it('read_only role is denied access to the page entirely', function (): void {
    $ro = User::factory()->create();
    $ro->assignRole('read_only');

    auth()->login($ro);
    expect(CsvIngestIssuesPage::canAccess())->toBeFalse();
});
