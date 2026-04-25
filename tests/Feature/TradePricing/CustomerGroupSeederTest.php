<?php

declare(strict_types=1);

use App\Domain\TradePricing\Models\CustomerGroup;
use Database\Seeders\Phase9\CustomerGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 01 Task 2 — CustomerGroupSeeder feature test (TRDE-01)
|--------------------------------------------------------------------------
|
| Locks the seeded baseline: 4 groups (trade/reseller/education/nhs) with
| display_order 10/20/30/40 and is_active=true. Re-running the seeder is
| idempotent (firstOrCreate keyed on slug). DatabaseSeeder picks up the
| Phase9 entry so `migrate:fresh --seed` produces the baseline on first boot.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 — see CustomerGroupTest
| header for rationale.
*/

function skipIfMySqlOfflineSeeder(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

it('seeds exactly 4 customer_groups rows on first run', function (): void {
    skipIfMySqlOfflineSeeder();

    expect(CustomerGroup::count())->toBe(0);

    Artisan::call('db:seed', [
        '--class' => CustomerGroupSeeder::class,
        '--no-interaction' => true,
    ]);

    expect(CustomerGroup::count())->toBe(4);
    expect(CustomerGroup::pluck('slug')->sort()->values()->all())
        ->toEqual(['education', 'nhs', 'reseller', 'trade']);
});

it('is idempotent — re-running yields exactly 4 rows (firstOrCreate on slug)', function (): void {
    skipIfMySqlOfflineSeeder();

    Artisan::call('db:seed', [
        '--class' => CustomerGroupSeeder::class,
        '--no-interaction' => true,
    ]);
    expect(CustomerGroup::count())->toBe(4);

    Artisan::call('db:seed', [
        '--class' => CustomerGroupSeeder::class,
        '--no-interaction' => true,
    ]);
    expect(CustomerGroup::count())->toBe(4);

    Artisan::call('db:seed', [
        '--class' => CustomerGroupSeeder::class,
        '--no-interaction' => true,
    ]);
    expect(CustomerGroup::count())->toBe(4);
});

it('all seeded rows have is_active=true', function (): void {
    skipIfMySqlOfflineSeeder();

    Artisan::call('db:seed', [
        '--class' => CustomerGroupSeeder::class,
        '--no-interaction' => true,
    ]);

    expect(CustomerGroup::where('is_active', false)->count())->toBe(0);
    expect(CustomerGroup::where('is_active', true)->count())->toBe(4);
});

it('seeded display_order matches D-01 spec (trade=10, reseller=20, education=30, nhs=40)', function (): void {
    skipIfMySqlOfflineSeeder();

    Artisan::call('db:seed', [
        '--class' => CustomerGroupSeeder::class,
        '--no-interaction' => true,
    ]);

    expect(CustomerGroup::where('slug', 'trade')->value('display_order'))->toBe(10);
    expect(CustomerGroup::where('slug', 'reseller')->value('display_order'))->toBe(20);
    expect(CustomerGroup::where('slug', 'education')->value('display_order'))->toBe(30);
    expect(CustomerGroup::where('slug', 'nhs')->value('display_order'))->toBe(40);
});

it('DatabaseSeeder->run() includes the Phase9 CustomerGroupSeeder so migrate:fresh --seed picks it up', function (): void {
    // Source-level assertion — independent of MySQL connection.
    $databaseSeederPath = base_path('database/seeders/DatabaseSeeder.php');
    $contents = file_get_contents($databaseSeederPath);

    expect($contents)->toContain('Phase9\\CustomerGroupSeeder');
});
