<?php

declare(strict_types=1);

use App\Domain\CRM\Filament\Pages\CrmPipelineSettingsPage;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Services\BitrixClient;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }
});

function bindPipelineClient(): void
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn([
        'CATEGORY_ID' => [
            'items' => [
                ['ID' => '0', 'VALUE' => 'Default'],
                ['ID' => '5', 'VALUE' => 'B2B Sales'],
            ],
        ],
        'STAGE_ID' => [
            'items' => [
                ['ID' => 'NEW', 'VALUE' => 'Brand new'],
                ['ID' => 'PREPAYMENT_INVOICE', 'VALUE' => 'Invoiced'],
                ['ID' => 'C5:LEAD', 'VALUE' => 'B2B Lead'],
                ['ID' => 'C5:WON', 'VALUE' => 'B2B Won'],
            ],
        ],
    ]);

    app()->instance(BitrixClient::class, $client);
    app()->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);
}

it('admin can save pipeline_id + landing_stage_id + deal_title_template', function (): void {
    bindPipelineClient();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(CrmPipelineSettingsPage::class)
        ->fillForm([
            'bitrix_pipeline_id' => '5',
            'landing_stage_id' => 'C5:LEAD',
            'assigned_user_id' => '42',
            'deal_title_template' => 'Woo Order #{order_number} ({customer_email})',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = CrmPipelineSetting::current();
    expect($row->bitrix_pipeline_id)->toBe('5');
    expect($row->landing_stage_id)->toBe('C5:LEAD');
    expect($row->assigned_user_id)->toBe('42');
    expect($row->deal_title_template)->toBe('Woo Order #{order_number} ({customer_email})');
});

it('non-admin cannot access the page (canAccess returns false)', function (): void {
    foreach (['pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);
        $this->actingAs($user);

        expect(CrmPipelineSettingsPage::canAccess())
            ->toBeFalse("Role {$roleName} must NOT access CrmPipelineSettingsPage");
    }
});

it('admin canAccess returns true', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    expect(CrmPipelineSettingsPage::canAccess())->toBeTrue();
});

it('pipelineOptions returns the Bitrix crm.dealcategory.list items', function (): void {
    bindPipelineClient();

    $method = new ReflectionMethod(CrmPipelineSettingsPage::class, 'pipelineOptions');
    $method->setAccessible(true);
    $options = $method->invoke(null);

    expect($options)->toHaveKey('0');
    expect($options)->toHaveKey('5');
});

it('stageOptionsForPipeline returns stages scoped to the given pipeline ID', function (): void {
    bindPipelineClient();

    $method = new ReflectionMethod(CrmPipelineSettingsPage::class, 'stageOptionsForPipeline');
    $method->setAccessible(true);

    $pipeline5 = $method->invoke(null, '5');
    expect($pipeline5)->toHaveKey('C5:LEAD');
    expect($pipeline5)->toHaveKey('C5:WON');
    expect($pipeline5)->not->toHaveKey('NEW');

    $defaultPipeline = $method->invoke(null, '0');
    expect($defaultPipeline)->toHaveKey('NEW');
    expect($defaultPipeline)->toHaveKey('PREPAYMENT_INVOICE');
    expect($defaultPipeline)->not->toHaveKey('C5:LEAD');
});
