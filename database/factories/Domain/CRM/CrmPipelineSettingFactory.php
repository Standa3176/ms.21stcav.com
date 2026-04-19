<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CRM;

use App\Domain\CRM\Models\CrmPipelineSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmPipelineSetting>
 */
class CrmPipelineSettingFactory extends Factory
{
    protected $model = CrmPipelineSetting::class;

    public function definition(): array
    {
        return [
            'bitrix_pipeline_id' => '0',
            'landing_stage_id' => 'NEW',
            'assigned_user_id' => null,
            'deal_title_template' => 'Woo Order #{order_number}',
        ];
    }
}
