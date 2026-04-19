<?php

declare(strict_types=1);

use App\Domain\CRM\Filament\Resources\CrmStatusMappingResource\Pages\EditCrmStatusMapping;
use App\Domain\CRM\Filament\Resources\CrmStatusMappingResource\Pages\ListCrmStatusMappings;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Services\BitrixClient;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }
    $this->seed(\Database\Seeders\Phase4\CrmStatusMappingSeeder::class);

    // Configure the singleton pipeline = '0' (default pipeline).
    CrmPipelineSetting::query()->update(['bitrix_pipeline_id' => '0', 'landing_stage_id' => 'NEW']);

    // Shield permissions required by Filament's implicit policy gate.
    foreach (['view_any_crm::status::mapping', 'view_crm::status::mapping', 'update_crm::status::mapping', 'create_crm::status::mapping', 'delete_crm::status::mapping'] as $perm) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }
    Role::findByName('admin')->givePermissionTo([
        'view_any_crm::status::mapping', 'view_crm::status::mapping',
        'update_crm::status::mapping', 'create_crm::status::mapping', 'delete_crm::status::mapping',
    ]);
});

function bindDealFieldsWithStages(array $stages): void
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn([
        'STAGE_ID' => ['items' => $stages],
    ]);
    $client->shouldReceive('contactFieldsGet')->zeroOrMoreTimes()->andReturn([]);
    $client->shouldReceive('companyFieldsGet')->zeroOrMoreTimes()->andReturn([]);

    app()->instance(BitrixClient::class, $client);
    app()->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);
}

it('admin can list the 7 seeded status mappings', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCrmStatusMappings::class)->assertSuccessful();

    expect(CrmStatusMapping::count())->toBe(7);
});

it('pipelineStageOptions returns stages filtered by the configured pipeline (default=0 = unprefixed)', function (): void {
    bindDealFieldsWithStages([
        ['ID' => 'NEW', 'VALUE' => 'Brand new'],
        ['ID' => 'PREPAYMENT_INVOICE', 'VALUE' => 'Invoiced'],
        ['ID' => 'WON', 'VALUE' => 'Won'],
        ['ID' => 'C5:OTHER_PIPE', 'VALUE' => 'Other pipeline stage'],  // must be filtered out
    ]);

    // Make sure the singleton pipeline is explicitly on default '0' for this test.
    $p = CrmPipelineSetting::query()->first();
    $p->update(['bitrix_pipeline_id' => '0']);

    $method = new ReflectionMethod(\App\Domain\CRM\Filament\Resources\CrmStatusMappingResource::class, 'pipelineStageOptions');
    $method->setAccessible(true);
    $options = $method->invoke(null);

    expect($options)->toHaveKey('PREPAYMENT_INVOICE');
    expect($options)->toHaveKey('NEW');
    expect($options)->toHaveKey('WON');
    expect($options)->not->toHaveKey('C5:OTHER_PIPE');
});

it('pipelineStageOptions filters to the configured non-default pipeline ID', function (): void {
    bindDealFieldsWithStages([
        ['ID' => 'NEW', 'VALUE' => 'Default'],
        ['ID' => 'C5:PREPAYMENT_INVOICE', 'VALUE' => 'Invoiced (pipeline 5)'],
        ['ID' => 'C5:WON', 'VALUE' => 'Won (pipeline 5)'],
        ['ID' => 'C9:OTHER', 'VALUE' => 'Pipeline 9'],
    ]);
    CrmPipelineSetting::query()->update(['bitrix_pipeline_id' => '5']);

    $method = new ReflectionMethod(\App\Domain\CRM\Filament\Resources\CrmStatusMappingResource::class, 'pipelineStageOptions');
    $method->setAccessible(true);
    $options = $method->invoke(null);

    expect($options)->toHaveKey('C5:PREPAYMENT_INVOICE');
    expect($options)->toHaveKey('C5:WON');
    expect($options)->not->toHaveKey('C9:OTHER');
    expect($options)->not->toHaveKey('NEW');
});

it('save rejects stage_id not in the pipeline stage list', function (): void {
    bindDealFieldsWithStages([
        ['ID' => 'NEW', 'VALUE' => 'Brand new'],
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $row = CrmStatusMapping::where('woo_status', 'processing')->firstOrFail();

    Livewire::test(EditCrmStatusMapping::class, ['record' => $row->getKey()])
        ->fillForm([
            'woo_status' => 'processing',
            'bitrix_stage_id' => 'NONEXISTENT_STAGE',
            'bitrix_stage_label' => 'x',
            'is_terminal' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['bitrix_stage_id']);
});

it('shows the configure-pipeline sentinel when pipeline_id is null', function (): void {
    bindDealFieldsWithStages([['ID' => 'NEW', 'VALUE' => 'New']]);

    // Null the pipeline setting so the Resource's stage dropdown falls back to the sentinel.
    CrmPipelineSetting::query()->update(['bitrix_pipeline_id' => null]);

    $method = new ReflectionMethod(\App\Domain\CRM\Filament\Resources\CrmStatusMappingResource::class, 'pipelineStageOptions');
    $method->setAccessible(true);
    $options = $method->invoke(null);

    expect($options)->toHaveKey('__not_configured__');
});
