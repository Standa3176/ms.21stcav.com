<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources;

use App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages;
use App\Foundation\Integration\Models\IntegrationEvent;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 4 Plan 04 — CrmPushLogResource (CRM-11).
 *
 * READ-ONLY filtered view over `integration_events` WHERE channel='bitrix'.
 * No create/edit/delete actions — the underlying table is append-only via
 * IntegrationLogger.
 *
 * RBAC: admin + sales both see (CrmPushLogPolicy::viewAny); mutations denied
 * for all. The CrmPushLogPolicy is registered against IntegrationEvent via
 * Gate::policy in AppServiceProvider — this is scoped to this Resource
 * because it's the ONLY Filament surface that exposes IntegrationEvent.
 *
 * Filters:
 *   - correlation_id exact
 *   - operation (endpoint) multi-select
 *   - http_status multi-select (200/400/429/500/503/0)
 *   - created_at date range
 *
 * Row action: View Details (modal with full request_body + response_body JSON).
 */
class CrmPushLogResource extends Resource
{
    protected static ?string $model = IntegrationEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'CRM Push Log';

    protected static ?string $modelLabel = 'CRM Push Log Entry';

    protected static ?string $pluralModelLabel = 'CRM Push Log';

    /**
     * Scope to Bitrix channel only. Sales role sees ONLY these rows — not
     * the full integration_events table — per D-02 role split.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('channel', 'bitrix');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('When'),

                TextColumn::make('operation')
                    ->label('Endpoint')
                    ->fontFamily('mono')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('direction')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'outbound' => 'primary',
                        'inbound' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('http_status')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state === null || $state === 0 => 'gray',
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 && $state < 500 => 'danger',
                        $state >= 500 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('latency_ms')
                    ->label('ms')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'retrying' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(12)
                    ->tooltip(fn (IntegrationEvent $r) => $r->correlation_id)
                    ->label('CID'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('operation')
                    ->label('Endpoint')
                    ->multiple()
                    ->options([
                        'crm.deal.add' => 'crm.deal.add',
                        'crm.deal.update' => 'crm.deal.update',
                        'crm.deal.get' => 'crm.deal.get',
                        'crm.deal.list' => 'crm.deal.list',
                        'crm.contact.add' => 'crm.contact.add',
                        'crm.contact.update' => 'crm.contact.update',
                        'crm.company.add' => 'crm.company.add',
                        'crm.company.update' => 'crm.company.update',
                        'crm.duplicate.findbycomm' => 'crm.duplicate.findbycomm',
                        'crm.deduper.contact' => 'crm.deduper.contact (decision)',
                        'crm.deduper.company' => 'crm.deduper.company (decision)',
                    ]),

                SelectFilter::make('http_status')
                    ->label('HTTP status')
                    ->multiple()
                    ->options([
                        '0' => '0 (shadow/internal)',
                        '200' => '200 OK',
                        '400' => '400 Bad Request',
                        '401' => '401 Unauthorized',
                        '403' => '403 Forbidden',
                        '404' => '404 Not Found',
                        '429' => '429 Rate Limited',
                        '500' => '500 Server Error',
                        '503' => '503 Transient',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'retrying' => 'Retrying',
                    ]),

                SelectFilter::make('direction')
                    ->options([
                        'outbound' => 'Outbound',
                        'inbound' => 'Inbound',
                    ]),

                Filter::make('correlation_id')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('correlation_id')
                            ->label('Correlation ID')
                            ->placeholder('Exact UUID match')
                            ->maxLength(36),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        $cid = $data['correlation_id'] ?? null;
                        if (is_string($cid) && $cid !== '') {
                            return $q->where('correlation_id', $cid);
                        }

                        return $q;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrmPushLogs::route('/'),
            'view' => Pages\ViewCrmPushLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // integration_events is append-only by design — IntegrationLogger owns writes.
        return false;
    }
}
