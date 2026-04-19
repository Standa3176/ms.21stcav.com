<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmPushLogResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewCrmPushLog extends ViewRecord
{
    protected static string $resource = CrmPushLogResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Event')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('operation')->fontFamily('mono'),
                    TextEntry::make('direction')->badge(),
                    TextEntry::make('http_status'),
                    TextEntry::make('latency_ms'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('correlation_id')->fontFamily('mono')->copyable(),
                    TextEntry::make('error_message')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Request body')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('request_body')
                        ->keyLabel('Key')
                        ->valueLabel('Value')
                        ->placeholder('(no request body)'),
                ]),

            Section::make('Response body')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('response_body')
                        ->keyLabel('Key')
                        ->valueLabel('Value')
                        ->placeholder('(no response body)'),
                ]),
        ]);
    }
}
