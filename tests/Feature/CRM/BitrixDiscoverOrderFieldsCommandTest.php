<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| Phase 12.1 Plan 02 Task 1 — bitrix:discover-order-fields (D-07, D-09)
|--------------------------------------------------------------------------
| Read-only. Every write method on BitrixClient must be unreachable.
*/

beforeEach(function (): void {
    config(['services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/']);
});

function discoverMock(array $fields, array $userfields = []): MockInterface
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->andReturn($fields);
    $client->shouldReceive('dealUserfieldList')->andReturn($userfields);
    $client->shouldReceive('dealCategoryList')->andReturn([['ID' => '3', 'NAME' => 'Quotes', 'SORT' => 1, 'IS_LOCKED' => false]]);
    $client->shouldReceive('dealStageList')->andReturn([
        ['STATUS_ID' => 'NEW', 'NAME' => 'New', 'SORT' => 10, 'SEMANTICS' => ''],
        ['STATUS_ID' => '2', 'NAME' => 'Two', 'SORT' => 20, 'SEMANTICS' => ''],
    ]);
    foreach (['dealAdd', 'dealUpdate', 'dealProductRowsSet', 'contactAdd', 'contactUpdate', 'companyAdd', 'companyUpdate', 'dealUserfieldAdd'] as $write) {
        $client->shouldNotReceive($write);
    }
    app()->instance(BitrixClient::class, $client);

    return $client;
}

function baseFields(): array
{
    return [
        'UF_CRM_A' => ['type' => 'money', 'isRequired' => false, 'formLabel' => 'Cost'],
        'UF_CRM_B' => ['type' => 'double', 'isRequired' => false, 'listLabel' => 'Predicted Margin'],
        'UF_CRM_1610623973323' => ['type' => 'double', 'isRequired' => false, 'formLabel' => 'Actual Margin'],
        'UF_CRM_X' => ['type' => 'double', 'isRequired' => false, 'formLabel' => 'Actual Margin'],
    ];
}

it('resolves Cost and Predicted Margin by label and flags Actual Margin DO-NOT-WRITE', function (): void {
    discoverMock(baseFields());

    $this->artisan('bitrix:discover-order-fields')
        ->expectsOutputToContain('Cost => UF_CRM_A')
        ->expectsOutputToContain('Predicted Margin => UF_CRM_B')
        ->expectsOutputToContain('UF_CRM_1610623973323  DO-NOT-WRITE')
        ->expectsOutputToContain('UF_CRM_X')
        ->assertExitCode(0);
});

it('fails with AMBIGUOUS when two fields share a label', function (): void {
    $fields = baseFields();
    $fields['UF_CRM_A2'] = ['type' => 'money', 'isRequired' => false, 'formLabel' => ' cost '];
    discoverMock($fields);

    $this->artisan('bitrix:discover-order-fields')
        ->expectsOutputToContain('AMBIGUOUS: 2 deal fields are labelled "Cost": UF_CRM_A, UF_CRM_A2')
        ->assertExitCode(1);
});

it('fails with NOT FOUND when a label does not exist', function (): void {
    $fields = baseFields();
    unset($fields['UF_CRM_B']);
    discoverMock($fields);

    $this->artisan('bitrix:discover-order-fields')
        ->expectsOutputToContain('NOT FOUND')
        ->assertExitCode(1);
});

it('fails when a requested label resolves to the Actual Margin field', function (): void {
    discoverMock([
        'UF_CRM_1610623973323' => ['type' => 'double', 'isRequired' => false, 'formLabel' => 'Cost'],
        'UF_CRM_B' => ['type' => 'double', 'isRequired' => false, 'formLabel' => 'Predicted Margin'],
    ]);

    $this->artisan('bitrix:discover-order-fields')
        ->expectsOutputToContain('FORBIDDEN')
        ->assertExitCode(1);
});

it('marks MANDATORY=Y userfields REQUIRED, prints the D-09 conflict block and writes cost.required in --json', function (): void {
    discoverMock(baseFields(), [
        ['FIELD_NAME' => 'UF_CRM_A', 'MANDATORY' => 'Y', 'USER_TYPE_ID' => 'money', 'EDIT_FORM_LABEL' => ['en' => 'Cost']],
        ['FIELD_NAME' => 'UF_CRM_B', 'MANDATORY' => 'N', 'USER_TYPE_ID' => 'double', 'EDIT_FORM_LABEL' => 'Predicted Margin'],
    ]);
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'discover-'.uniqid().'.json';

    $this->artisan('bitrix:discover-order-fields', ['--json' => $path])
        ->expectsOutputToContain('required=YES')
        ->expectsOutputToContain('D-09 CONFLICT')
        ->assertExitCode(0);

    $report = json_decode((string) file_get_contents($path), true);
    @unlink($path);

    expect($report['cost']['code'])->toBe('UF_CRM_A')
        ->and($report['cost']['required'])->toBeTrue()
        ->and($report['predicted_margin']['required'])->toBeFalse()
        ->and($report)->toHaveKeys(['actual_margin_fields', 'required_fields', 'categories', 'stages', 'generated_at'])
        ->and(array_column($report['actual_margin_fields'], 'code'))->toContain('UF_CRM_1610623973323');
});

it('flags codes outside the plugin mapped-but-blank list', function (): void {
    discoverMock(baseFields());

    $this->artisan('bitrix:discover-order-fields')
        ->expectsOutputToContain('NOT in plugin mapped-but-blank list')
        ->assertExitCode(0);
});

it('fails fast when BITRIX_WEBHOOK_URL is empty', function (): void {
    config(['services.bitrix.webhook_url' => null]);

    $this->artisan('bitrix:discover-order-fields')->assertExitCode(1);
});
