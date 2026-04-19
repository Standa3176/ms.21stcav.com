<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 5 Plan 04a — role × Competitor Resource policy matrix.
 *
 * Verifies:
 *   - admin gets viewAny + view + update + delete on everything
 *   - pricing_manager gets viewAny + view on all 3; update on csv_parse_error
 *     (D-04 quarantine resolution); NO delete
 *   - sales gets viewAny + view on competitor_price + competitor_ingest_run ONLY;
 *     csv_parse_error 403
 *   - read_only gets ZERO Competitor resource access
 *
 * Seeded via RolePermissionSeeder (idempotent) on every test (uses=TestCase +
 * RefreshDatabase in tests/Pest.php).
 */
beforeEach(function (): void {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function mkUserWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('admin can viewAny + view + update + delete on all 3 Competitor Resources', function (): void {
    $admin = mkUserWithRole('admin');

    $competitor = Competitor::factory()->create();
    $price = CompetitorPrice::factory()->create(['competitor_id' => $competitor->id]);
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);
    $error = CsvParseError::factory()->create(['competitor_id' => $competitor->id]);

    expect(Gate::forUser($admin)->allows('viewAny', CompetitorPrice::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('viewAny', CompetitorIngestRun::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('viewAny', CsvParseError::class))->toBeTrue();

    expect(Gate::forUser($admin)->allows('view', $price))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $run))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $error))->toBeTrue();

    // Admin can update + delete parse errors (D-04 + admin delete exclusive).
    expect(Gate::forUser($admin)->allows('update', $error))->toBeTrue();
    expect(Gate::forUser($admin)->allows('delete', $error))->toBeTrue();
});

it('pricing_manager gets view all 3 + update csv_parse_error (D-04); no delete', function (): void {
    $pm = mkUserWithRole('pricing_manager');

    $competitor = Competitor::factory()->create();
    $price = CompetitorPrice::factory()->create(['competitor_id' => $competitor->id]);
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);
    $error = CsvParseError::factory()->create(['competitor_id' => $competitor->id]);

    // View access on all 3.
    expect(Gate::forUser($pm)->allows('viewAny', CompetitorPrice::class))->toBeTrue();
    expect(Gate::forUser($pm)->allows('viewAny', CompetitorIngestRun::class))->toBeTrue();
    expect(Gate::forUser($pm)->allows('viewAny', CsvParseError::class))->toBeTrue();

    // D-04 — pricing_manager resolves parse errors.
    expect(Gate::forUser($pm)->allows('update', $error))->toBeTrue();

    // No delete on parse errors — admin-only operational action.
    expect(Gate::forUser($pm)->allows('delete', $error))->toBeFalse();

    // Prices are immutable (COMP-07).
    expect(Gate::forUser($pm)->allows('update', $price))->toBeFalse();
    expect(Gate::forUser($pm)->allows('delete', $price))->toBeFalse();

    // Ingest runs are producer-owned.
    expect(Gate::forUser($pm)->allows('update', $run))->toBeFalse();
});

it('sales gets view_any on competitor_price + competitor_ingest_run ONLY; csv_parse_error denied', function (): void {
    $sales = mkUserWithRole('sales');

    $competitor = Competitor::factory()->create();
    $price = CompetitorPrice::factory()->create(['competitor_id' => $competitor->id]);
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);
    $error = CsvParseError::factory()->create(['competitor_id' => $competitor->id]);

    // Sales sees competitor prices (COMP-10 trend visibility for quote-building).
    expect(Gate::forUser($sales)->allows('viewAny', CompetitorPrice::class))->toBeTrue();
    expect(Gate::forUser($sales)->allows('view', $price))->toBeTrue();

    // Sales sees ingest runs ("is today's data in?" operational insight).
    expect(Gate::forUser($sales)->allows('viewAny', CompetitorIngestRun::class))->toBeTrue();
    expect(Gate::forUser($sales)->allows('view', $run))->toBeTrue();

    // Sales CANNOT see parse errors — that's pricing_manager + admin triage.
    expect(Gate::forUser($sales)->allows('viewAny', CsvParseError::class))->toBeFalse();
    expect(Gate::forUser($sales)->allows('view', $error))->toBeFalse();
    expect(Gate::forUser($sales)->allows('update', $error))->toBeFalse();
});

it('read_only gets ZERO Competitor resource access', function (): void {
    $readOnly = mkUserWithRole('read_only');

    $competitor = Competitor::factory()->create();
    $price = CompetitorPrice::factory()->create(['competitor_id' => $competitor->id]);
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);
    $error = CsvParseError::factory()->create(['competitor_id' => $competitor->id]);

    // T-05-04a-02 mitigation — read_only explicitly excluded from Competitor surface.
    expect(Gate::forUser($readOnly)->allows('viewAny', CompetitorPrice::class))->toBeFalse();
    expect(Gate::forUser($readOnly)->allows('viewAny', CompetitorIngestRun::class))->toBeFalse();
    expect(Gate::forUser($readOnly)->allows('viewAny', CsvParseError::class))->toBeFalse();

    expect(Gate::forUser($readOnly)->allows('view', $price))->toBeFalse();
    expect(Gate::forUser($readOnly)->allows('view', $run))->toBeFalse();
    expect(Gate::forUser($readOnly)->allows('view', $error))->toBeFalse();
});

it('RolePermissionSeeder is idempotent — re-running does not duplicate attachments', function (): void {
    $pm = \Spatie\Permission\Models\Role::findByName('pricing_manager');
    $initialCount = $pm->permissions()->count();

    // Re-run the seeder.
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $afterCount = $pm->fresh()->permissions()->count();
    expect($afterCount)->toBe($initialCount);
});
