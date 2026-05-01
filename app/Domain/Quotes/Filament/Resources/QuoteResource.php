<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Resources;

use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Filament\Actions\ApproveQuoteAction;
use App\Domain\Quotes\Filament\Actions\MarkAcceptedAction;
use App\Domain\Quotes\Filament\Actions\MarkRejectedAction;
use App\Domain\Quotes\Filament\Actions\RevertQuoteAction;
use App\Domain\Quotes\Filament\Resources\QuoteResource\Pages;
use App\Domain\Quotes\Filament\Resources\QuoteResource\RelationManagers\QuoteLinesRelationManager;
use App\Domain\Quotes\Models\Quote;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 11 Plan 03 — QuoteResource (QUOT-03 + QUOT-05 + D-01..D-13).
 *
 * Operator UI for the v2 quote-flow. "Sales" navigation group is new this
 * plan (first member; future v1.x can add CustomerResource + InvoiceResource
 * here per CONTEXT.md Claude's Discretion).
 *
 * Form (D-03 toggle):
 *   - Toggle "Existing customer?" reveals user picker (Select::searchable
 *     against users.email) which auto-fills customer_email/customer_name/
 *     customer_group_id from the selected User.
 *   - Toggle off reveals free-text customer_email + customer_name +
 *     billing_address Repeater + manual customer_group_id Select.
 *   - customer_group_name_at_quote denormalised string is set at save time
 *     from CustomerGroup::find($customer_group_id)?->name (D-02 + Pitfall 6).
 *
 * RelationManagers: QuoteLinesRelationManager — search-and-add SKU picker
 * (D-10 PRIMARY input path) + manual SKU TextInput fallback. All writes
 * defer to QuoteLineWriter (Plan 11-02 sole creation path) so the immutability
 * observer chain runs through the same code path on every line write.
 *
 * Row actions (state-machine — D-04..D-08):
 *   - ApproveQuoteAction (draft → sent)
 *   - RevertQuoteAction (sent → draft, admin-only, 5-min window)
 *   - MarkAcceptedAction (sent → accepted)
 *   - MarkRejectedAction (sent → rejected with D-08 reason capture)
 *
 * Pitfall 7 — every Filament Action calls ->authorize() server-side;
 * visible() is UI-only, authorize() is the actual gate.
 *
 * RBAC (RolePermissionSeeder Plan 11-03 extension):
 *   - admin           — all 9 quote_* perms
 *   - pricing_manager — all EXCEPT delete_quote
 *   - sales           — viewAny/view/create/update/markAccepted/markRejected
 *                       (NOT approve/revert/delete — D-04 separation-of-duties)
 *   - read_only       — viewAny/view only
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Quote';

    protected static ?string $pluralModelLabel = 'Quotes';

    protected static ?string $recordTitleAttribute = 'customer_email';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Customer')
                ->description('Pick an existing user, or capture free-text details for an anonymous lead.')
                ->schema([
                    Toggle::make('existing_customer')
                        ->label('Existing customer?')
                        ->live()
                        ->dehydrated(false)
                        ->default(false)
                        ->helperText('On — search registered users. Off — free-text contact details.'),

                    // ── Toggle ON: existing-user picker (D-03 toggle ON path) ──
                    Select::make('user_id')
                        ->label('User')
                        ->visible(fn (Get $get): bool => (bool) $get('existing_customer'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->limit(25)
                            ->pluck('email', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->email)
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if ($state === null) {
                                return;
                            }
                            $user = User::find($state);
                            if ($user === null) {
                                return;
                            }
                            $set('customer_email', $user->email);
                            $set('customer_name', $user->name);
                            $set('customer_group_id', $user->customer_group_id);
                        })
                        ->live(),

                    // ── Toggle OFF: free-text fields (D-03 toggle OFF path) ──
                    TextInput::make('customer_email')
                        ->label('Customer email')
                        ->email()
                        ->required()
                        ->maxLength(255),

                    TextInput::make('customer_name')
                        ->label('Customer name')
                        ->maxLength(255),

                    Select::make('customer_group_id')
                        ->label('Customer group')
                        ->options(fn (): array => CustomerGroup::query()
                            ->where('is_active', true)
                            ->orderBy('display_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->placeholder('— Retail (no group) —')
                        ->searchable()
                        ->helperText('D-02 — drives TradeRuleResolver pricing on every line.'),

                    Repeater::make('billing_address')
                        ->label('Billing address')
                        ->visible(fn (Get $get): bool => ! (bool) $get('existing_customer'))
                        ->schema([
                            TextInput::make('line1')->label('Line 1')->maxLength(255),
                            TextInput::make('line2')->label('Line 2')->maxLength(255),
                            TextInput::make('city')->label('City')->maxLength(128),
                            TextInput::make('postcode')->label('Postcode')->maxLength(16),
                            TextInput::make('country')->label('Country')->default('UK')->maxLength(64),
                        ])
                        ->maxItems(1)
                        ->defaultItems(0),
                ]),

            Section::make('Quote details')
                ->schema([
                    Select::make('status')
                        ->options(collect(QuoteStatus::cases())
                            ->reject(fn (QuoteStatus $c): bool => QuoteStatus::isReserved($c))
                            ->mapWithKeys(fn (QuoteStatus $c): array => [
                                $c->value => ucfirst(str_replace('_', ' ', $c->value)),
                            ])
                            ->all())
                        ->default(Quote::STATUS_DRAFT)
                        ->disabled()
                        ->dehydrated(true)
                        ->helperText('Status transitions via dedicated row actions (Approve / Revert / Mark Accepted / Mark Rejected).'),

                    TextInput::make('expires_at')
                        ->label('Expires at')
                        ->type('datetime-local')
                        ->default(fn (): string => now()->addDays((int) config('quote.default_expiry_days', 14))->format('Y-m-d\TH:i')),

                    Textarea::make('notes_internal')
                        ->label('Internal notes')
                        ->visible(false)  // placeholder slot for future v1.x — keeps form-state stable
                        ->dehydrated(false),
                ]),
        ]);
    }

    /**
     * Customer-group denormalised name persistence (D-02 + Pitfall 6).
     *
     * Called by CreateQuote::mutateFormDataBeforeCreate + EditQuote::
     * mutateFormDataBeforeSave — looks up CustomerGroup by id and stores
     * the literal name string. Survives subsequent CustomerGroup rename via
     * the denormalised column.
     */
    public static function denormaliseCustomerGroupName(array $data): array
    {
        if (! empty($data['customer_group_id'])) {
            $data['customer_group_name_at_quote'] = CustomerGroup::find($data['customer_group_id'])?->name;
        } else {
            $data['customer_group_name_at_quote'] = null;
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Quote')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => '#'.substr($state, 0, 8))
                    ->copyable()
                    ->copyableState(fn (string $state): string => $state)
                    ->tooltip(fn (Quote $record): string => $record->id),

                TextColumn::make('customer_email')
                    ->label('Customer')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'draft' => 'gray',
                        'sent' => 'info',
                        'accepted' => 'success',
                        'rejected' => 'danger',
                        'expired' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('customer_group_name_at_quote')
                    ->label('Group')
                    ->placeholder('Retail')
                    ->sortable(),

                TextColumn::make('total_pence_at_quote')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => '£'.number_format(((int) $state) / 100, 2))
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(QuoteStatus::cases())
                        ->reject(fn (QuoteStatus $c): bool => QuoteStatus::isReserved($c))
                        ->mapWithKeys(fn (QuoteStatus $c): array => [
                            $c->value => ucfirst(str_replace('_', ' ', $c->value)),
                        ])
                        ->all()),

                SelectFilter::make('customer_group_id')
                    ->label('Customer group')
                    ->options(fn (): array => CustomerGroup::query()
                        ->orderBy('display_order')
                        ->pluck('name', 'id')
                        ->all()),

                Filter::make('expires_at_range')
                    ->form([
                        TextInput::make('expires_from')->type('date')->label('Expires from'),
                        TextInput::make('expires_to')->type('date')->label('Expires to'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        if (! empty($data['expires_from'])) {
                            $q->whereDate('expires_at', '>=', $data['expires_from']);
                        }
                        if (! empty($data['expires_to'])) {
                            $q->whereDate('expires_at', '<=', $data['expires_to']);
                        }

                        return $q;
                    }),
            ])
            ->actions([
                ViewAction::make()
                    ->authorize(fn (Quote $record): bool => auth()->user()?->can('view', $record) ?? false),
                EditAction::make()
                    ->authorize(fn (Quote $record): bool => auth()->user()?->can('update', $record) ?? false),
                ApproveQuoteAction::make(),
                RevertQuoteAction::make(),
                MarkAcceptedAction::make(),
                MarkRejectedAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            QuoteLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'view' => Pages\ViewQuote::route('/{record}'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
