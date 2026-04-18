<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Database\Seeder;

/**
 * Pitfall M: seed the fallback recipient so failed-job alerts never silently
 * drop to an empty list. Operators override by adding real addresses via the
 * Filament AlertRecipientResource after first login; this row stays as the
 * safety net unless explicitly deactivated / removed.
 *
 * Idempotent via firstOrCreate — safe to run on every deploy.
 */
class AlertRecipientSeeder extends Seeder
{
    public function run(): void
    {
        AlertRecipient::firstOrCreate(
            ['email' => 'ops@meetingstore.co.uk'],
            [
                'name' => 'Ops Fallback',
                'is_active' => true,
                'notes' => 'Seeded fallback — Pitfall M mitigation. Replace with real ops addresses via /admin/alert-recipients.',
            ]
        );
    }
}
