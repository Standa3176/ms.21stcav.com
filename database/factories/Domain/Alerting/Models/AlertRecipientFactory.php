<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Alerting\Models;

use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 260708-gy0 — factory for AlertRecipient (was missing; AlertRecipient uses the
 * default HasFactory resolution which looks for THIS namespace/path, so
 * AlertRecipient::factory() 500'd with "class not found").
 *
 * @extends Factory<AlertRecipient>
 */
class AlertRecipientFactory extends Factory
{
    protected $model = AlertRecipient::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'is_active' => true,
            'notes' => null,
            'receives_sync_reports' => true,
            'receives_crm_alerts' => false,
            'receives_competitor_alerts' => false,
            'receives_auto_create_alerts' => false,
            'receives_weekly_digest' => true,
            'receives_agent_alerts' => false,
            'receives_quote_alerts' => false,
            'receives_competitor_ftp_alerts' => false,
        ];
    }
}
