<?php

declare(strict_types=1);

use App\Domain\CRM\Models\CrmFieldMapping;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 1 — CrmFieldMappingSeeder (CRM-06 defaults)
|--------------------------------------------------------------------------
|
| 40 rows (19 deal + 15 contact + 6 company) ported from legacy CrmFields.php.
| Idempotent via firstOrCreate — running twice yields the same count.
*/

it('seeds at least 40 default field mappings', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    expect(CrmFieldMapping::count())->toBeGreaterThanOrEqual(40);
});

it('seeds 19 deal + 15 contact + 6 company mappings', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    expect(CrmFieldMapping::where('entity_type', 'deal')->count())->toBe(19);
    expect(CrmFieldMapping::where('entity_type', 'contact')->count())->toBe(15);
    expect(CrmFieldMapping::where('entity_type', 'company')->count())->toBe(6);
});

it('is idempotent — running twice produces no duplicates', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);
    $first = CrmFieldMapping::count();

    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);
    $second = CrmFieldMapping::count();

    expect($first)->toBe($second);
});

it('includes UF_CRM_WOO_ORDER_ID and 6 UTM keys on Deal', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    $dealFields = CrmFieldMapping::where('entity_type', 'deal')->pluck('bitrix_field')->all();
    foreach (['UF_CRM_WOO_ORDER_ID', 'UF_CRM_WOO_UTM_SOURCE', 'UF_CRM_WOO_UTM_MEDIUM', 'UF_CRM_WOO_UTM_CAMPAIGN', 'UF_CRM_WOO_UTM_TERM', 'UF_CRM_WOO_UTM_CONTENT', 'UF_CRM_WOO_GA_CID'] as $field) {
        expect($dealFields)->toContain($field);
    }
});

it('preserves admin edits on subsequent re-runs (firstOrCreate semantics)', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);
    $row = CrmFieldMapping::where('entity_type', 'deal')->where('woo_field', 'id')->firstOrFail();
    $row->update(['bitrix_field' => 'UF_CRM_WOO_ORDER_ID_CUSTOM', 'transformer' => 'uppercase']);

    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    $fresh = $row->fresh();
    expect($fresh->bitrix_field)->toBe('UF_CRM_WOO_ORDER_ID_CUSTOM');
    expect($fresh->transformer)->toBe('uppercase');
});
