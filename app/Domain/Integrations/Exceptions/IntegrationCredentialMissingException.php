<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use RuntimeException;

/**
 * Phase 09.1 Plan 01 — thrown by IntegrationCredentialResolver when both
 * the DB row and env fallback are empty for a given kind (D-06 case 3).
 */
class IntegrationCredentialMissingException extends RuntimeException
{
    public static function for(IntegrationCredentialKind $kind): self
    {
        return new self(sprintf(
            'No credential available for integration kind "%s". '
            .'Either create an active row in admin/integration-credentials, '
            .'or set the env fallback (see config/services.php and config/agents.php).',
            $kind->value,
        ));
    }
}
