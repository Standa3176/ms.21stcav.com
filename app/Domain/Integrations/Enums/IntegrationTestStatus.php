<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Enums;

/**
 * Phase 09.1 Plan 01 — IntegrationTestStatus.
 *
 * Stored on integration_credentials.last_test_status. Set by the per-row
 * Test connection action via TestIntegrationAction::dispatch.
 */
enum IntegrationTestStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
