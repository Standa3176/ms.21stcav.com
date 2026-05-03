<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource;
use App\Domain\Competitor\Models\Competitor;
use Filament\Notifications\Notification;
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

    /**
     * After "Create" clicked → land on the feed list (default Filament behaviour
     * with both Create + Edit pages registered would be to go to Edit; that's
     * the wrong target for ops who want to confirm + move on to the next feed).
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Explicit success toast so "Create" and "Create and create another" both
     * surface a clear "Saved" confirmation. Filament fires a default notification
     * when this returns a non-null value; making it explicit avoids any panel
     * config silencing it.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('FTP feed saved')
            ->body('Local file: '.($this->record->local_filename ?? '—'))
            ->success();
    }
}
