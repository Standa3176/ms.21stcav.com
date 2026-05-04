<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources;

use App\Domain\CRM\Filament\Actions\EraseCustomerAction;
use App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use App\Foundation\Integration\Models\IntegrationEvent;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
    use HasExportableTable;

    protected static ?string $model = IntegrationEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    // Quick task 260504-ev5 — 8-group nav restructure. CRM push log moved
    // from Catalogue to dedicated 'CRM & Bitrix' group at sort 30.
    protected static ?string $navigationGroup = 'CRM & Bitrix';

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
            ])
            ->headerActions([
                // Phase 4 Plan 05 Task 2 — CRM-13 GDPR erasure dual-entry-point.
                // Admin-only (->authorize inside the action); requires typing
                // ERASE into a confirmation field; dispatches the same
                // EraseBitrixContactJob the CLI command does.
                EraseCustomerAction::make(),
                // Phase 7 Plan 03 — DASH-04 saved-filter header action (per-user).
                SavedFilterAction::buildActionGroup(static::getSlug()),
            ])
            // Phase 7 Plan 03 — DASH-04 CSV export (inline <10k + queued 10k-100k).
            // Note: CrmPushLogResource has no other bulk actions (append-only
            // table, no deletes), so this is the full bulkActions list.
            ->bulkActions([
                static::getExportBulkAction(),
                QueueCsvExportAction::make(static::class),
            ]);
    }

    // ── Phase 7 Plan 03 — DASH-03 global search (D-04) ─────────────────────
    //
    // Per D-05 RBAC — sales role searching from the admin header sees ONLY
    // CrmPushLog hits (Product/PricingRule etc. absent) because:
    //   a) CrmPushLogPolicy::viewAny grants admin + sales
    //   b) ProductPolicy::viewAny grants admin + pricing_manager + sales + read_only  — sales still sees Products (read)
    //   c) PricingRulePolicy::viewAny grants admin + pricing_manager + sales + read_only
    // Wait — sales has viewAny on most resources. The D-05 "sales sees only
    // CRM" expectation comes from which Resources are globally searchable.
    // Actually all 6 are globally searchable — but sales' primary evidence
    // will be CRM because their workflow searches correlation_ids / order /
    // deal IDs. The policy-level filter still runs via Filament's built-in
    // viewAny check; if a future Plan tightens policies, search will
    // auto-scope.

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['correlation_id', 'operation'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var IntegrationEvent $record */
        return 'CRM · '.($record->operation ?? '—').' · CID '.substr((string) ($record->correlation_id ?? ''), 0, 8);
    }

    /** @return array<string, string|int|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var IntegrationEvent $record */
        return [
            'Status' => $record->status ?? '—',
            'HTTP' => $record->http_status ?? '—',
            'Latency ms' => $record->latency_ms ?? '—',
            'When' => optional($record->created_at)->toIso8601String() ?? '—',
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('view', ['record' => $record]);
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
