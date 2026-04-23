<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource;
use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages\ListAutoCreateReview;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 04 — AutoCreateReviewResource tests
|--------------------------------------------------------------------------
| Exercises: scope filter, completeness-tier filter, approve (with + without
| override modal), reject + reason/notes, bulk-approve silent-skip semantics.
| RBAC: admin + pricing_manager can edit; sales + read_only denied.
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->pricingManager = User::factory()->create();
    $this->pricingManager->assignRole('pricing_manager');

    $this->sales = User::factory()->create();
    $this->sales->assignRole('sales');
});

it('scopes the table to auto-create review statuses only (excludes manual)', function (): void {
    Product::factory()->create(['auto_create_status' => 'draft', 'sku' => 'DRAFT-1', 'completeness_score' => 70]);
    Product::factory()->create(['auto_create_status' => 'pending_review', 'sku' => 'PR-1', 'completeness_score' => 90]);
    Product::factory()->create(['auto_create_status' => 'needs_brand_or_category_assignment', 'sku' => 'NEEDS-1', 'completeness_score' => 60]);
    Product::factory()->create(['auto_create_status' => 'manual', 'sku' => 'MANUAL-1']);
    Product::factory()->create(['auto_create_status' => 'published', 'sku' => 'PUB-1']);
    Product::factory()->create(['auto_create_status' => 'rejected', 'sku' => 'REJ-1']);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->assertCanSeeTableRecords(Product::whereIn('sku', ['DRAFT-1', 'PR-1', 'NEEDS-1'])->get())
        ->assertCanNotSeeTableRecords(Product::whereIn('sku', ['MANUAL-1', 'PUB-1', 'REJ-1'])->get());
});

it('sorts by completeness_score DESC by default', function (): void {
    Product::factory()->create(['auto_create_status' => 'draft', 'sku' => 'LOW', 'completeness_score' => 40]);
    Product::factory()->create(['auto_create_status' => 'draft', 'sku' => 'HIGH', 'completeness_score' => 90]);
    Product::factory()->create(['auto_create_status' => 'draft', 'sku' => 'MID', 'completeness_score' => 70]);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->assertCanSeeTableRecords(
            Product::where('auto_create_status', 'draft')->orderByDesc('completeness_score')->get(),
            inOrder: true,
        );
});

it('approve action dispatches PublishProductJob when score >= threshold (no override modal)', function (): void {
    Queue::fake();

    $p = Product::factory()->create([
        'auto_create_status' => 'draft',
        'sku' => 'HIGH-SCORE',
        'completeness_score' => 95,
    ]);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->callTableAction('approve', $p, data: [])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(PublishProductJob::class);
});

it('approve action requires override_reason when score below threshold + logs activity', function (): void {
    Queue::fake();

    $p = Product::factory()->create([
        'auto_create_status' => 'draft',
        'sku' => 'LOW-SCORE',
        'completeness_score' => 40,
    ]);

    $this->actingAs($this->admin);

    // Without reason — fails validation.
    livewire(ListAutoCreateReview::class)
        ->callTableAction('approve', $p, data: [])
        ->assertHasTableActionErrors(['override_reason']);

    Queue::assertNotPushed(PublishProductJob::class);

    // With reason — succeeds, job dispatched, activity_log written.
    livewire(ListAutoCreateReview::class)
        ->callTableAction('approve', $p, data: ['override_reason' => 'Needed for promo launch'])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(PublishProductJob::class);
    expect(
        \Spatie\Activitylog\Models\Activity::query()
            ->where('description', 'auto_create.publish.low_completeness_override')
            ->exists()
    )->toBeTrue();
});

it('reject action writes AutoCreateRejection row + sets status rejected', function (): void {
    $p = Product::factory()->create([
        'auto_create_status' => 'draft',
        'sku' => 'TO-REJECT',
    ]);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->callTableAction('reject', $p, data: [
            'reason' => AutoCreateRejection::REASON_SPARE_PART_OR_ACCESSORY,
            'notes' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect(AutoCreateRejection::where('product_id', $p->id)->where('reason', 'spare_part_or_accessory')->exists())->toBeTrue();
    expect($p->fresh()->auto_create_status)->toBe('rejected');
});

it('reject action requires notes when reason=other', function (): void {
    $p = Product::factory()->create([
        'auto_create_status' => 'draft',
        'sku' => 'OTHER-REJ',
    ]);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->callTableAction('reject', $p, data: [
            'reason' => AutoCreateRejection::REASON_OTHER,
            'notes' => null,
        ])
        ->assertHasTableActionErrors(['notes']);
});

it('bulk approve silently skips rows below threshold', function (): void {
    Queue::fake();

    $low = Product::factory()->create(['auto_create_status' => 'draft', 'completeness_score' => 40]);
    $high1 = Product::factory()->create(['auto_create_status' => 'draft', 'completeness_score' => 90]);
    $high2 = Product::factory()->create(['auto_create_status' => 'draft', 'completeness_score' => 95]);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->callTableBulkAction('approve_selected', [$low->id, $high1->id, $high2->id]);

    Queue::assertPushed(PublishProductJob::class, 2);
});

it('non-admin sales user cannot approve (authorize returns false)', function (): void {
    Queue::fake();

    $p = Product::factory()->create([
        'auto_create_status' => 'draft',
        'completeness_score' => 95,
    ]);

    $this->actingAs($this->sales);

    $component = livewire(ListAutoCreateReview::class);
    // The action should be hidden / unauthorized for sales; call should fail silently.
    $component->callTableAction('approve', $p, data: []);

    Queue::assertNotPushed(PublishProductJob::class);
});

it('quick-edit action updates name + descriptions', function (): void {
    $p = Product::factory()->create([
        'auto_create_status' => 'draft',
        'name' => 'Original Name',
        'short_description' => 'old short',
    ]);

    $this->actingAs($this->admin);

    livewire(ListAutoCreateReview::class)
        ->callTableAction('quick_edit', $p, data: [
            'name' => 'New Name',
            'short_description' => 'new short',
            'long_description' => 'new long',
            'meta_description' => 'new meta',
        ])
        ->assertHasNoTableActionErrors();

    $p->refresh();
    expect($p->name)->toBe('New Name');
    expect($p->short_description)->toBe('new short');
});
