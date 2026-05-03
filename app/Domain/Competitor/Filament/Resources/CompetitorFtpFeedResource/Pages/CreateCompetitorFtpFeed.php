<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource;
use App\Domain\Competitor\Models\Competitor;
use Filament\Resources\Pages\CreateRecord;

class CreateCompetitorFtpFeed extends CreateRecord
{
    protected static string $resource = CompetitorFtpFeedResource::class;

    /**
     * Quick task 260503-uwk — derive local_filename from competitor slug.
     *
     * Form hides this field; we compute it here so the operator never has to
     * hand-craft the watcher's <slug>_YYYY-MM-DD.csv pattern. The fixed date
     * 2026-01-01 satisfies the watcher's regex without implying file freshness
     * (freshness is determined by file mtime, not the date in the filename).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['local_filename']) && ! empty($data['competitor_id'])) {
            $competitor = Competitor::find($data['competitor_id']);
            if ($competitor !== null) {
                $data['local_filename'] = sprintf('%s_2026-01-01.csv', $competitor->slug);
            }
        }

        return $data;
    }
}
