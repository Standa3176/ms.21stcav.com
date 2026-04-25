<?php

declare(strict_types=1);

namespace App\Domain\Agents\Filament\Resources\AgentRunResource\Pages;

use App\Domain\Agents\Filament\Resources\AgentRunResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 8 Plan 04 — read-only list page (AGNT-13).
 *
 * No header actions because AgentRuns are framework-produced; admins cannot
 * create them via Filament (canCreate returns false on the Resource and the
 * AgentRunPolicy denies create() unconditionally).
 */
class ListAgentRuns extends ListRecords
{
    protected static string $resource = AgentRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
