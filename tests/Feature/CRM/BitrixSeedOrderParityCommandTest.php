<?php

declare(strict_types=1);

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Phase 12.1 Plan 02 Task 3 — bitrix:seed-order-parity (D-08)
|--------------------------------------------------------------------------
*/

/** Write a map fixture to a temp file and return its path. */
function parityMapFile(array $overrides = []): string
{
    $map = array_replace_recursive(committedParityMap(), $overrides['merge'] ?? []);
    foreach ($overrides['set'] ?? [] as $path => $value) {
        data_set($map, $path, $value);
    }
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parity-'.uniqid().'.php';
    file_put_contents($file, '<?php return '.var_export($map, true).';');

    return $file;
}

function committedParityMap(): array
{
    $map = require database_path('seeders/Phase12_1/order_parity_map.php');
    $map['pipeline']['deal_cost_field'] = 'UF_CRM_COST';
    $map['pipeline']['deal_predicted_margin_field'] = 'UF_CRM_PM';

    return $map;
}

function snapshotParityTables(): array
{
    return [
        CrmPipelineSetting::query()->get()->toArray(),
        CrmStatusMapping::query()->orderBy('id')->get()->toArray(),
        CrmFieldMapping::query()->orderBy('id')->get()->toArray(),
    ];
}

it('dry-run prints a per-section diff and writes nothing', function (): void {
    $before = snapshotParityTables();
    $file = parityMapFile();

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file])
        ->expectsOutputToContain('== pipeline ==')
        ->expectsOutputToContain('== status_stages ==')
        ->expectsOutputToContain('== deal_field_mappings ==')
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect(snapshotParityTables())->toBe($before);
    @unlink($file);
});

it('--apply writes pipeline, stages and deal mappings and a second apply is a no-op', function (): void {
    CrmStatusMapping::create(['woo_status' => 'processing', 'bitrix_stage_id' => null, 'bitrix_stage_label' => 'PREPAYMENT_INVOICE', 'is_terminal' => false]);
    CrmStatusMapping::create(['woo_status' => 'completed', 'bitrix_stage_id' => 'WON', 'bitrix_stage_label' => 'WON', 'is_terminal' => true]);
    $file = parityMapFile();

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])->assertExitCode(0);

    $p = CrmPipelineSetting::current();
    expect($p->bitrix_pipeline_id)->toBe('0')
        ->and($p->assigned_user_id)->toBe('6428')
        ->and($p->deal_title_template)->toBe('BS - #{order_number} - ({billing_company})')
        ->and($p->deal_static_fields)->toBe(['TYPE_ID' => 'GOODS', 'OPENED' => 'Y', 'SOURCE_ID' => 'RC_GENERATOR'])
        ->and($p->deal_cost_field)->toBe('UF_CRM_COST')
        ->and($p->deal_predicted_margin_field)->toBe('UF_CRM_PM');

    $processing = CrmStatusMapping::query()->where('woo_status', 'processing')->first();
    expect($processing->bitrix_stage_id)->toBe('2')
        ->and($processing->bitrix_stage_label)->toBe('PREPAYMENT_INVOICE');   // label preserved
    expect(CrmStatusMapping::stageIdForStatus('on-hold'))->toBe('2')
        ->and(CrmStatusMapping::stageIdForStatus('ywraq-new'))->toBe('NEW')
        ->and(CrmStatusMapping::stageIdForStatus('ywraq-pending'))->toBe('NEW');

    // 'completed' listed null => cleared (plugin "no stage change"); terminal flag preserved.
    $completed = CrmStatusMapping::query()->where('woo_status', 'completed')->first();
    expect($completed->bitrix_stage_id)->toBeNull()->and($completed->is_terminal)->toBeTrue();

    expect(CrmFieldMapping::query()->where('entity_type', 'deal')->count())->toBe(15);
    expect(CrmFieldMapping::query()->where('entity_type', 'deal')->where('woo_field', 'shipping.postcode')->value('bitrix_field'))->toBe('UF_CRM_1624008544289');

    $after = snapshotParityTables();
    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])
        ->expectsOutputToContain('(no change)')
        ->assertExitCode(0);

    $second = snapshotParityTables();
    // updated_at may not move because nothing changed; compare the semantic columns.
    $strip = fn (array $rows): array => array_map(fn ($r) => array_diff_key($r, ['updated_at' => 1]), $rows);
    expect($strip($second[1]))->toBe($strip($after[1]))
        ->and($strip($second[2]))->toBe($strip($after[2]))
        ->and(CrmFieldMapping::query()->count())->toBe(15);
    @unlink($file);
});

it('--apply prunes unlisted deal mappings but never touches contact or company mappings', function (): void {
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'stale.field', 'bitrix_field' => 'UF_CRM_STALE', 'is_custom' => true, 'sort_order' => 99]);
    CrmFieldMapping::create(['entity_type' => 'contact', 'woo_field' => 'billing.phone', 'bitrix_field' => 'PHONE', 'is_custom' => false, 'sort_order' => 1]);
    CrmFieldMapping::create(['entity_type' => 'company', 'woo_field' => 'billing.company', 'bitrix_field' => 'TITLE', 'is_custom' => false, 'sort_order' => 1]);
    $file = parityMapFile();

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])->assertExitCode(0);

    expect(CrmFieldMapping::query()->where('woo_field', 'stale.field')->exists())->toBeFalse()
        ->and(CrmFieldMapping::query()->where('entity_type', 'contact')->count())->toBe(1)
        ->and(CrmFieldMapping::query()->where('entity_type', 'company')->count())->toBe(1);
    @unlink($file);
});

it('does not prune when the map disables prune_unlisted_deal_mappings', function (): void {
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'stale.field', 'bitrix_field' => 'UF_CRM_STALE', 'is_custom' => true, 'sort_order' => 99]);
    $file = parityMapFile(['set' => ['prune_unlisted_deal_mappings' => false]]);

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])->assertExitCode(0);

    expect(CrmFieldMapping::query()->where('woo_field', 'stale.field')->exists())->toBeTrue();
    @unlink($file);
});

it('refuses with UNRESOLVED when Cost or Predicted Margin is null and writes nothing', function (): void {
    $before = snapshotParityTables();
    $file = parityMapFile(['set' => ['pipeline.deal_cost_field' => null]]);

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])
        ->expectsOutputToContain('UNRESOLVED: pipeline.deal_cost_field')
        ->assertExitCode(1);

    expect(snapshotParityTables())->toBe($before);
    @unlink($file);
});

it('exits 1 when a deal-creating status stage is null', function (string $status): void {
    $file = parityMapFile(['set' => ['status_stages.'.$status => null]]);

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])->assertExitCode(1);

    expect(CrmFieldMapping::query()->count())->toBe(0);
    @unlink($file);
})->with(['processing', 'on-hold', 'ywraq-new', 'ywraq-pending']);

it('exits 1 when a mapping targets Actual Margin', function (): void {
    $map = committedParityMap();
    $map['deal_field_mappings'][] = ['woo_field' => 'meta:x', 'bitrix_field' => 'UF_CRM_1610623973323', 'transformer' => 'none', 'is_custom' => true];
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parity-'.uniqid().'.php';
    file_put_contents($file, '<?php return '.var_export($map, true).';');

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])
        ->expectsOutputToContain('FORBIDDEN')
        ->assertExitCode(1);
    @unlink($file);
});

it('exits 1 when a static field targets Actual Margin', function (): void {
    $file = parityMapFile(['merge' => ['pipeline' => ['deal_static_fields' => ['UF_CRM_1610623973323' => '1']]]]);

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file])
        ->expectsOutputToContain('FORBIDDEN')
        ->assertExitCode(1);
    @unlink($file);
});

it('exits 1 when the Cost code also appears as a mapping target', function (): void {
    $map = committedParityMap();
    $map['deal_field_mappings'][] = ['woo_field' => 'meta:cost', 'bitrix_field' => 'UF_CRM_COST', 'transformer' => 'none', 'is_custom' => true];
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parity-'.uniqid().'.php';
    file_put_contents($file, '<?php return '.var_export($map, true).';');

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])
        ->expectsOutputToContain('must not be a mapping target')
        ->assertExitCode(1);
    @unlink($file);
});

it('exits 1 on a duplicate woo_field', function (): void {
    $map = committedParityMap();
    $map['deal_field_mappings'][] = $map['deal_field_mappings'][0];
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parity-'.uniqid().'.php';
    file_put_contents($file, '<?php return '.var_export($map, true).';');

    $this->artisan('bitrix:seed-order-parity', ['--map' => $file, '--apply' => true])
        ->expectsOutputToContain('Duplicate woo_field')
        ->assertExitCode(1);
    @unlink($file);
});

it('the committed data file fails validation only on the two unresolved Cost / Predicted Margin codes', function (): void {
    $before = snapshotParityTables();

    $this->artisan('bitrix:seed-order-parity', ['--apply' => true])
        ->expectsOutputToContain('UNRESOLVED: pipeline.deal_cost_field')
        ->expectsOutputToContain('UNRESOLVED: pipeline.deal_predicted_margin_field')
        ->expectsOutputToContain('Validation failed')
        ->assertExitCode(1);

    expect(snapshotParityTables())->toBe($before);

    $map = require database_path('seeders/Phase12_1/order_parity_map.php');
    expect($map['pipeline']['deal_cost_field'])->toBeNull()
        ->and($map['pipeline']['deal_predicted_margin_field'])->toBeNull()
        ->and($map['pipeline']['bitrix_pipeline_id'])->toBe('0')
        ->and($map['pipeline']['assigned_user_id'])->toBe('6428')
        ->and($map['pipeline']['deal_title_template'])->toBe('BS - #{order_number} - ({billing_company})')
        ->and($map['status_stages']['processing'])->toBe('2')
        ->and($map['status_stages']['ywraq-new'])->toBe('NEW');
});

it('the committed data file reports no validation errors other than the two unresolved codes', function (): void {
    $exit = Artisan::call('bitrix:seed-order-parity');
    $output = Artisan::output();

    preg_match_all('/^(UNRESOLVED|FORBIDDEN|Duplicate|pipeline\.|status_stages\.|Cost\/Predicted|Mapping rows).*$/m', $output, $m);

    expect($exit)->toBe(1)
        ->and($m[0])->toHaveCount(2)
        ->and($output)->toContain('deal_cost_field')->toContain('deal_predicted_margin_field');
});
