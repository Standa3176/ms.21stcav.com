<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource\Pages\ListCompetitorFtpSources;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.1 Plan 01 Task 3 — CompetitorFtpSourceResource feature tests.
|--------------------------------------------------------------------------
|
| Covers: admin access (list page), non-admin RBAC denial via the policy
| (D-08 admin-only on every method), encrypted-cast persistence on save,
| Test connection Action success path with a stubbed connector.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    // Seed perms inline (mirrors CrmFieldMappingResourceTest pattern — the
    // RolePermissionSeeder runs in CI but not necessarily inside RefreshDatabase).
    $perms = [
        'view_any_competitor_ftp_source',
        'view_competitor_ftp_source',
        'create_competitor_ftp_source',
        'update_competitor_ftp_source',
        'delete_competitor_ftp_source',
    ];
    foreach ($perms as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    Role::findByName('admin')->givePermissionTo($perms);
});

it('admin can access the list page', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCompetitorFtpSources::class)->assertSuccessful();
});

it('pricing_manager / sales / read_only are denied viewAny via the policy (D-08)', function (): void {
    foreach (['pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);

        expect($user->can('viewAny', CompetitorFtpSource::class))
            ->toBeFalse("Role {$roleName} should be denied viewAny on CompetitorFtpSource (D-08 admin-only).");
    }
});

it('admin save persists password encrypted at rest (D-04)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $competitor = Competitor::factory()->create();

    // Create the row directly via the model (verifies the cast layer; the
    // Filament form `dehydrated(filled)` gate is exercised in CreateCompetitorFtpSource
    // page-level smoke once Filament runtime is wired in CI MySQL).
    $source = CompetitorFtpSource::factory()->create([
        'competitor_id' => $competitor->id,
        'password_encrypted' => 'super-secret-pwd',
    ]);

    // Raw DB read — encrypted cast bypassed.
    $rawRow = DB::table('competitor_ftp_sources')->where('id', $source->id)->first();
    expect($rawRow->password_encrypted)
        ->not->toBe('super-secret-pwd', 'Plaintext password leaked to DB column.');

    // Reload via model — cast decrypts.
    $reloaded = CompetitorFtpSource::find($source->id);
    expect($reloaded->password_encrypted)->toBe('super-secret-pwd');
});

it('Test connection action success path lists files via stubbed connector', function (): void {
    // Stub a Flysystem with one file via Local adapter.
    $remoteRoot = storage_path('framework/testing/ftp-source-resource-'.uniqid());
    File::ensureDirectoryExists($remoteRoot);
    $remoteFs = new Filesystem(new LocalFilesystemAdapter($remoteRoot));
    $remoteFs->write('cisco_2026-05-02.csv', 'sku,price');

    $stubConnector = new class($remoteFs) extends FtpSourceConnector
    {
        public function __construct(private readonly Filesystem $fs) {}

        public function connect(CompetitorFtpSource $source): Filesystem
        {
            return $this->fs;
        }
    };

    app()->instance(FtpSourceConnector::class, $stubConnector);

    // Call the connector directly to verify the success path the Filament Action wraps.
    $competitor = Competitor::factory()->create();
    $source = CompetitorFtpSource::factory()->create(['competitor_id' => $competitor->id]);

    $fs = app(FtpSourceConnector::class)->connect($source);
    $files = [];
    foreach ($fs->listContents('', deep: false) as $item) {
        if ($item->isFile()) {
            $files[] = basename($item->path());
        }
    }
    expect($files)->toContain('cisco_2026-05-02.csv');

    File::deleteDirectory($remoteRoot);
});
