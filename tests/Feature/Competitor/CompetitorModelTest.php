<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Policies\CompetitorCsvMappingPolicy;
use App\Domain\Competitor\Policies\CompetitorPolicy;
use App\Domain\Competitor\Policies\CompetitorPricePolicy;
use App\Domain\Competitor\Policies\CompetitorIngestRunPolicy;
use App\Domain\Competitor\Policies\CsvParseErrorPolicy;
use App\Domain\Competitor\Models\CsvParseError;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 01 Task 2 — Competitor model + policy contract guarantees
|--------------------------------------------------------------------------
|
| Schema (Task 1 shipped migrations) + Eloquent (Task 2 ships models + factories
| + policies) + Gate::policy bindings registered in AppServiceProvider::boot.
*/

function phase5RoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('creates the competitors table with every expected column', function (): void {
    expect(Schema::hasTable('competitors'))->toBeTrue();

    foreach ([
        'id', 'slug', 'name', 'website_url', 'map_policy_notes',
        'status', 'is_active', 'last_ingest_at',
        'created_at', 'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('competitors', $col))->toBeTrue("competitors missing column: {$col}");
    }
});

it('factory persists a Competitor + enforces slug unique index', function (): void {
    $c = Competitor::factory()->create(['slug' => 'acme', 'status' => Competitor::STATUS_ACTIVE]);
    expect($c->fresh())->not->toBeNull();
    expect($c->slug)->toBe('acme');

    $this->expectException(QueryException::class);
    Competitor::factory()->create(['slug' => 'acme']);
});

it('exposes STATUS_* constants + isActive() helper', function (): void {
    expect(Competitor::STATUS_PENDING)->toBe('pending');
    expect(Competitor::STATUS_ACTIVE)->toBe('active');
    expect(Competitor::STATUS_INACTIVE)->toBe('inactive');

    $active = Competitor::factory()->create(['status' => Competitor::STATUS_ACTIVE, 'is_active' => true]);
    $inactive = Competitor::factory()->create(['status' => Competitor::STATUS_ACTIVE, 'is_active' => false]);
    $pending = Competitor::factory()->create(['status' => Competitor::STATUS_PENDING, 'is_active' => true]);

    expect($active->isActive())->toBeTrue();
    expect($inactive->isActive())->toBeFalse();
    expect($pending->isActive())->toBeFalse();
});

it('exposes prices() hasMany + csvMapping() hasOne + ingestRuns() hasMany relationships', function (): void {
    $c = Competitor::factory()->create();
    CompetitorPrice::factory()->create(['competitor_id' => $c->id]);
    CompetitorPrice::factory()->create(['competitor_id' => $c->id]);
    CompetitorCsvMapping::factory()->create(['competitor_id' => $c->id]);
    CompetitorIngestRun::factory()->create(['competitor_id' => $c->id]);

    expect($c->prices)->toHaveCount(2);
    expect($c->prices->first())->toBeInstanceOf(CompetitorPrice::class);
    expect($c->csvMapping)->toBeInstanceOf(CompetitorCsvMapping::class);
    expect($c->ingestRuns)->toHaveCount(1);
    expect($c->ingestRuns->first())->toBeInstanceOf(CompetitorIngestRun::class);
});

it('CompetitorPolicy: viewAny/view admin+pricing_manager; create/update/delete admin only', function (): void {
    $admin = phase5RoleUser('admin');
    $pm = phase5RoleUser('pricing_manager');
    $sales = phase5RoleUser('sales');
    $readOnly = phase5RoleUser('read_only');

    expect(Gate::forUser($admin)->allows('viewAny', Competitor::class))->toBeTrue();
    expect(Gate::forUser($pm)->allows('viewAny', Competitor::class))->toBeTrue();
    expect(Gate::forUser($sales)->allows('viewAny', Competitor::class))->toBeFalse();
    expect(Gate::forUser($readOnly)->allows('viewAny', Competitor::class))->toBeFalse();

    expect(Gate::forUser($admin)->allows('create', Competitor::class))->toBeTrue();
    expect(Gate::forUser($pm)->allows('create', Competitor::class))->toBeFalse();
    expect(Gate::forUser($readOnly)->allows('create', Competitor::class))->toBeFalse();

    $c = Competitor::factory()->create();
    expect(Gate::forUser($admin)->allows('delete', $c))->toBeTrue();
    expect(Gate::forUser($pm)->allows('delete', $c))->toBeFalse();
});

it('CompetitorCsvMappingPolicy: pricing_manager CAN update (D-04 quarantine resolution)', function (): void {
    $admin = phase5RoleUser('admin');
    $pm = phase5RoleUser('pricing_manager');
    $sales = phase5RoleUser('sales');

    $mapping = CompetitorCsvMapping::factory()->create();

    expect(Gate::forUser($admin)->allows('update', $mapping))->toBeTrue();
    expect(Gate::forUser($pm)->allows('update', $mapping))->toBeTrue();     // D-04
    expect(Gate::forUser($sales)->allows('update', $mapping))->toBeFalse();

    expect(Gate::forUser($admin)->allows('create', $mapping))->toBeFalse(); // auto-created by ingest
});

it('CompetitorPricePolicy: admin+pricing_manager+sales view; nobody writes', function (): void {
    $admin = phase5RoleUser('admin');
    $pm = phase5RoleUser('pricing_manager');
    $sales = phase5RoleUser('sales');

    expect(Gate::forUser($admin)->allows('viewAny', CompetitorPrice::class))->toBeTrue();
    expect(Gate::forUser($pm)->allows('viewAny', CompetitorPrice::class))->toBeTrue();
    expect(Gate::forUser($sales)->allows('viewAny', CompetitorPrice::class))->toBeTrue();

    expect(Gate::forUser($admin)->allows('create', CompetitorPrice::class))->toBeFalse();
    expect(Gate::forUser($admin)->allows('delete', CompetitorPrice::factory()->create()))->toBeFalse();
});

it('Gate::policy bindings resolve to Competitor Policies (not null, not stubs)', function (): void {
    $pairs = [
        Competitor::class => CompetitorPolicy::class,
        CompetitorPrice::class => CompetitorPricePolicy::class,
        CompetitorCsvMapping::class => CompetitorCsvMappingPolicy::class,
        CompetitorIngestRun::class => CompetitorIngestRunPolicy::class,
        CsvParseError::class => CsvParseErrorPolicy::class,
    ];

    foreach ($pairs as $model => $expectedPolicy) {
        $resolved = Gate::getPolicyFor(new $model);
        expect($resolved)->toBeInstanceOf($expectedPolicy);
    }
});
