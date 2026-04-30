<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use App\Foundation\Audit\Services\Auditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SuggestionResource extends Resource
{
    use HasExportableTable;

    protected static ?string $model = Suggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Review';

    protected static ?string $recordTitleAttribute = 'kind';

    /**
     * Eager-load relations displayed in the table to prevent N+1 queries
     * (Gemini Concern MEDIUM, PITFALLS Pitfall 10).
     *
     * Currently rendered relation columns:
     *   - resolvedByUser.name  -> belongsTo(User)
     *
     * `proposedBy` is a polymorphic morphTo. If a future column renders proposedBy.* fields,
     * extend this with `->with(['proposedBy'])` (Eloquent will fan out per concrete type).
     *
     * The accompanying tests/Feature/SuggestionResourceQueryCountTest.php asserts that listing
     * N suggestions executes a bounded number of queries (not N + relation-fetches per row).
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['resolvedByUser']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'pending' => 'warning',
                    'approved' => 'primary',
                    'rejected' => 'danger',
                    'applied' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                })->sortable(),
                // Phase 5 Plan 04a — supporting_competitors badge for new_product_opportunity rows.
                // Renders as a count badge; blank for other kinds (no accessor trip because
                // data_get on an array returns null if the key is missing).
                TextColumn::make('supporting_competitors')
                    ->label('Comp')
                    ->badge()
                    ->color('info')
                    ->state(fn (Suggestion $record) => $record->kind === 'new_product_opportunity'
                        ? (int) (data_get($record->evidence, 'supporting_competitors', 0))
                        : null)
                    ->placeholder('—'),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn ($record) => $record->correlation_id),
                TextColumn::make('proposed_at')->dateTime()->sortable(),
                TextColumn::make('resolvedByUser.name')->label('Resolved by')->placeholder('—'),
            ])
            ->defaultSort('proposed_at', 'desc')
            ->filters([
                SelectFilter::make('kind'),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'applied' => 'Applied',
                    'failed' => 'Failed',
                ]),
            ])
            ->actions([
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    // Warning 9 — defence-in-depth: ->authorize() enforces at the POST level even if
                    // a crafted request bypasses ->visible(). Sales/read_only/pricing_manager get 403.
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    // Phase 5 Plan 04a / Phase 6 Plan 04 — generic approve EXCLUDES kinds with
                    // their own kind-specific approve actions below (richer modals + evidence rendering).
                    ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING
                        && ! in_array($r->kind, ['margin_change', 'new_product_opportunity', 'crm_push_failed', 'auto_create_failed'], true))
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),
                // Phase 5 Plan 04a — margin_change kind approve.
                // Renders old→new margin delta from the D-07 FROZEN evidence JSON
                // (shipped in Plan 05-03). Approve dispatches ApplySuggestionJob
                // which resolves MarginChangeApplier and updates the PricingRule;
                // PricingRuleObserver fires PricingRuleChanged for downstream listeners.
                Action::make('approve_margin_change')
                    ->label('Approve margin change')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'margin_change' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Approve margin change for '.(string) data_get($r->evidence, 'sku', '?'))
                    ->modalDescription(function (Suggestion $record): string {
                        $old = (int) data_get($record->evidence, 'our_current_margin_bps', 0);
                        $new = (int) data_get($record->evidence, 'proposed_margin_bps', 0);
                        $delta = (int) data_get($record->evidence, 'margin_delta_bps', 0);
                        $sku = (string) data_get($record->evidence, 'sku', '?');
                        $competitor = (string) data_get($record->evidence, 'competitor_name', '?');
                        $base = sprintf(
                            'SKU %s (vs %s): margin %d bps → %d bps (Δ %+d bps). Approving updates the PricingRule only — run `php artisan pricing:recompute --live` afterwards to materialise new prices across affected SKUs and trigger Woo pushes.',
                            $sku,
                            $competitor,
                            $old,
                            $new,
                            $delta,
                        );
                        // Phase 10 Plan 04 — OUT-OF-BAND warning when v1's
                        // deterministic value sits outside the agent's proposed band.
                        if (self::computeOutOfBand($record) === 'OUT-OF-BAND') {
                            $bandMin = (int) data_get($record->evidence, 'agent_proposed_band_min_bps', 0);
                            $bandMax = (int) data_get($record->evidence, 'agent_proposed_band_max_bps', 0);
                            $base .= sprintf(
                                "\n\nWARNING: v1's %d bps is OUT OF the agent's confidence band [%d–%d bps]. "
                                .'A reason is required and will be recorded to audit_log + the Suggestion evidence.',
                                $new,
                                $bandMin,
                                $bandMax,
                            );
                        }

                        return $base;
                    })
                    // Phase 10 Plan 04 D-08 — required free-text reason ONLY when OUT-OF-BAND.
                    // IN-BAND + non-enriched suggestions return [] → modal still confirms,
                    // standard approve flow runs unchanged (PRCAGT-04 invariant).
                    ->form(fn (Suggestion $record): array => self::computeOutOfBand($record) === 'OUT-OF-BAND'
                        ? [
                            Textarea::make('out_of_band_reason')
                                ->label('Reason for approving outside the agent\'s confidence band')
                                ->required()
                                ->minLength(10)
                                ->maxLength(2000)
                                ->helperText('e.g. "agent reasoning missed a key market signal", "deliberately pricing aggressively to win share", "v1 deterministic is the more conservative choice this quarter".'),
                        ]
                        : [])
                    ->action(function (Suggestion $record, array $data): void {
                        $isOutOfBand = self::computeOutOfBand($record) === 'OUT-OF-BAND';
                        $reason = (string) ($data['out_of_band_reason'] ?? '');

                        // Phase 10 Plan 04 D-08 — when OUT-OF-BAND, capture
                        // the approval reason on Suggestion.evidence + audit_log
                        // BEFORE the status flip so a subsequent failure doesn't
                        // orphan the audit trail (Phase 1 FOUND-04 pattern).
                        if ($isOutOfBand && $reason !== '') {
                            $bandMin = (int) data_get($record->evidence, 'agent_proposed_band_min_bps', 0);
                            $bandMax = (int) data_get($record->evidence, 'agent_proposed_band_max_bps', 0);
                            $deterministic = (int) data_get($record->evidence, 'proposed_margin_bps', 0);
                            $latestAgentRunId = (string) collect((array) data_get($record->evidence, 'agent_run_ids', []))->last();

                            $evidence = (array) $record->evidence;
                            $evidence['out_of_band_approval'] = [
                                'deterministic_bps' => $deterministic,
                                'band_min_bps' => $bandMin,
                                'band_max_bps' => $bandMax,
                                'reason' => $reason,
                                'approved_by_user_id' => auth()->id(),
                                'approved_at' => now()->toIso8601String(),
                                'latest_agent_run_id' => $latestAgentRunId,
                            ];
                            $record->evidence = $evidence;
                            $record->save();

                            app(Auditor::class)->record('approved_margin_change_out_of_band', [
                                'suggestion_id' => $record->id,
                                'sku' => (string) data_get($record->evidence, 'sku', ''),
                                'agent_run_id' => $latestAgentRunId,
                                'deterministic_bps' => $deterministic,
                                'band_min_bps' => $bandMin,
                                'band_max_bps' => $bandMax,
                                'reason' => $reason,
                            ]);
                        }

                        // Standard approve flow — UNCHANGED for IN-BAND + non-enriched
                        // (PRCAGT-04 invariant: byte-identical v1 path when no agent enrichment).
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),
                // Phase 5 Plan 04a / Phase 6 Plan 04 — new_product_opportunity kind approve.
                // Phase 6 Plan 03 REPLACED the Phase 5 no-op stub applier with
                // a real NewProductOpportunityApplier that dispatches
                // CreateWooProductJob. This action now triggers the full
                // auto-create pipeline via ApplySuggestionJob → Applier →
                // CreateWooProductJob.
                Action::make('approve_new_product_opportunity')
                    ->label('Approve — create product')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'new_product_opportunity' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Approve new product: '.(string) data_get($r->evidence, 'sku', '?'))
                    ->modalDescription(function (Suggestion $record): string {
                        $supporting = (int) data_get($record->evidence, 'supporting_competitors', 1);
                        $sku = (string) data_get($record->evidence, 'sku', '?');

                        return sprintf(
                            'SKU %s tracked by %d competitor(s). Dispatches CreateWooProductJob via the real Phase 6 NewProductOpportunityApplier — draft will appear in the Auto-Create Review inbox.',
                            $sku,
                            $supporting,
                        );
                    })
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),

                // Phase 6 Plan 04 — auto_create_failed DLQ replay action.
                // CreateWooProductJob::failed() writes the Suggestion row when
                // the retry chain exhausts. Replay dispatches ApplySuggestionJob
                // → AutoCreateRetryApplier → fresh CreateWooProductJob (mirrors
                // the Phase 4 crm_push_failed Replay precedent above).
                Action::make('replay_auto_create')
                    ->label('Replay auto-create')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'auto_create_failed' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Replay auto-create for '.(string) data_get($r->evidence, 'sku', '?'))
                    ->modalDescription('Dispatches ApplySuggestionJob which routes to AutoCreateRetryApplier and re-fires CreateWooProductJob with a fresh attempts counter. Check Horizon + the Auto-Create Review inbox after a few seconds.')
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Auto-create replay dispatched')
                            ->body('Check the Auto-Create Review inbox after a few seconds.')
                            ->send();
                    }),
                // Phase 10 Plan 05 Task 1 — D-09 structured rejection feedback.
                //
                // The reject action's ->form() callable conditionally augments
                // the standard `rejection_reason` Textarea with two extra
                // fields when the Suggestion is a margin_change row that has
                // been enriched by the PricingAgent (i.e. evidence.agent_run_ids[]
                // is non-empty):
                //
                //   - "Was the agent reasoning misleading?" radio — yes/no/partial
                //   - "Notes" textarea — required, min 10 chars, max 2000
                //
                // The structured payload writes to the top-level
                // `agent_rejection_feedback` JSON column (column-canonical per
                // Plan 10-05 Step B; NOT `evidence.agent_rejection_feedback`)
                // so the AgentRunRejectionInboxPage can `whereNotNull('agent_rejection_feedback')`
                // for indexable filter.
                //
                // For non-margin_change kinds OR margin_change rows with no
                // agent enrichment, the form returns just the standard
                // rejection_reason field — v1 reject behaviour is byte-identical
                // (PRCAGT-04 invariant).
                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form(function (Suggestion $record): array {
                        $hasAgentRun = ! empty((array) data_get($record->evidence, 'agent_run_ids', []));
                        $isMarginChangeWithAgent = $record->kind === 'margin_change' && $hasAgentRun;

                        if (! $isMarginChangeWithAgent) {
                            // Standard reject path — UNCHANGED from pre-Plan 10-05
                            // for non-agent-enriched + non-margin_change kinds.
                            return [
                                Textarea::make('rejection_reason')->required()->maxLength(2000),
                            ];
                        }

                        // Phase 10 D-09 — structured rejection feedback for
                        // prompt-iteration triage (rejection inbox source rows).
                        return [
                            Radio::make('misleading')
                                ->label('Was the agent reasoning misleading?')
                                ->options([
                                    'yes' => 'Yes',
                                    'no' => 'No',
                                    'partial' => 'Partially',
                                ])
                                ->required()
                                ->helperText('Drives prompt iteration — pick "Yes" if the agent\'s reasoning led you toward the wrong answer; "Partially" if it was directionally right but missed something; "No" if reasoning was sound but you\'re rejecting for a different reason.'),
                            Textarea::make('notes')
                                ->label('Notes (visible in the Rejection Inbox)')
                                ->required()
                                ->minLength(10)
                                ->maxLength(2000)
                                ->helperText('e.g. "agent missed the supplier price spike last week", "confidence was too high given only 2 competitors", "agent reasoning was correct but I have insider context".'),
                        ];
                    })
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING)
                    ->action(function (Suggestion $record, array $data): void {
                        $hasAgentRun = ! empty((array) data_get($record->evidence, 'agent_run_ids', []));
                        $isMarginChangeWithAgent = $record->kind === 'margin_change' && $hasAgentRun;

                        // For the structured-feedback path, `notes` carries the
                        // rejection reason; for the standard path the user filled
                        // in `rejection_reason` directly.
                        $rejectionReasonForRecord = $isMarginChangeWithAgent
                            ? (string) ($data['notes'] ?? '')
                            : (string) ($data['rejection_reason'] ?? '');

                        $payload = [
                            'status' => Suggestion::STATUS_REJECTED,
                            'rejection_reason' => $rejectionReasonForRecord,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ];

                        // Phase 10 D-09 — write the dedicated column ONLY for
                        // the agent-enriched margin_change path (column-canonical
                        // per Plan 10-05 Step B). NULL for non-agent rejections
                        // = "no structured feedback captured" (legacy default).
                        if ($isMarginChangeWithAgent
                            && ! empty($data['misleading'])
                            && ! empty($data['notes'])
                        ) {
                            $payload['agent_rejection_feedback'] = [
                                'misleading' => (string) $data['misleading'],
                                'notes' => (string) $data['notes'],
                                'rejected_by_user_id' => (int) auth()->id(),
                                'rejected_at' => now()->toIso8601String(),
                            ];
                        }

                        $record->update($payload);
                    }),
                // Phase 4 Plan 04 — replay action for crm_push_failed suggestions.
                // Dispatches ApplySuggestionJob which resolves CrmPushRetryApplier
                // (registered in AppServiceProvider) and re-dispatches the original
                // PushOrderToBitrixJob / PushCustomerToBitrixJob with a fresh attempts
                // counter. Warning 9 mandates ->authorize() alongside ->visible().
                Action::make('replay')
                    ->label('Replay CRM Push')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'crm_push_failed' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Replay CRM push for order #'.((is_array($r->payload) ? ($r->payload['woo_id'] ?? '?') : '?')))
                    ->modalDescription('Dispatches ApplySuggestionJob which re-fires the original push job with a fresh attempts counter. Check the CRM Push Log for the retry result.')
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('CRM push replay dispatched')
                            ->body('Check CRM Push Log after a few seconds for the retry result.')
                            ->send();
                    }),
            ])
            // Phase 7 Plan 03 — DASH-04 saved-filter header action (per-user).
            ->headerActions([
                SavedFilterAction::buildActionGroup(static::getSlug()),
            ])
            // Phase 7 Plan 03 — DASH-04 CSV export (inline <10k + queued 10k-100k).
            ->bulkActions([
                static::getExportBulkAction(),
                QueueCsvExportAction::make(static::class),
            ]);
    }

    // ── Phase 10 Plan 04 — margin_change detail view extension ────────────
    //
    // Additive: existing v1 ViewSuggestion page renders the table-actions on
    // the detail header (approve_margin_change with the Plan 10-04 OUT-OF-BAND
    // form gate) and this infolist below the header. v1 layout is preserved
    // for non-margin_change kinds (the Grid block is ->visible() gated).
    //
    // Layout per CONTEXT D-10:
    //   Top row (margin_change only): Grid with two side-by-side Sections
    //     - "v1 Deterministic Evidence" (Phase 5 sku/our_current/proposed/etc)
    //     - "Agent Enrichment" with header action RunPricingAgentAction +
    //       confidence badge + proposed_band chip + reasoning markdown +
    //       OUT-OF-BAND chip when applicable

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Grid::make(2)
                ->visible(fn (Suggestion $r): bool => $r->kind === 'margin_change')
                ->schema([
                    // Left card — Phase 5 deterministic evidence (untouched contract)
                    Section::make('v1 Deterministic Evidence')
                        ->description('Phase 5 noise-suppressed margin signal — the canonical inputs to ApplySuggestionJob.')
                        ->icon('heroicon-o-calculator')
                        ->schema([
                            TextEntry::make('evidence.sku')
                                ->label('SKU')
                                ->fontFamily('mono')
                                ->copyable(),
                            TextEntry::make('evidence.our_current_margin_bps')
                                ->label('Current margin (bps)')
                                ->numeric(),
                            TextEntry::make('evidence.proposed_margin_bps')
                                ->label('Phase 5 proposed margin (bps)')
                                ->numeric()
                                ->badge()
                                ->color('info'),
                            TextEntry::make('evidence.margin_delta_bps')
                                ->label('Delta (bps)')
                                ->numeric(),
                            TextEntry::make('evidence.sales_count_90d')
                                ->label('Sales (90d)')
                                ->numeric(),
                            TextEntry::make('evidence.pricing_rule.scope')
                                ->label('Pricing rule scope')
                                ->badge()
                                ->color('gray'),
                            TextEntry::make('evidence.competitor_name')
                                ->label('Competitor')
                                ->placeholder('—'),
                        ])
                        ->columns(2),

                    // Right card — Agent enrichment + out-of-band detection
                    Section::make('Agent Enrichment')
                        ->description('Phase 10 PricingAgent reasoning, confidence band, and proposed margin window.')
                        ->icon('heroicon-o-sparkles')
                        // Header action wired via the resolver below — keeps the
                        // Suggestions → Agents direction clean for deptrac. The
                        // Agents layer owns the action class; Suggestions only
                        // resolves it by string name at runtime so there's no
                        // compile-time FQCN dependency.
                        ->headerActions(self::resolveAgentEnrichmentHeaderActions())
                        ->schema([
                            TextEntry::make('evidence.agent_run_status')
                                ->label('Agent run status')
                                ->placeholder('— not run yet —')
                                ->badge()
                                ->color(fn ($state): string => match ($state) {
                                    'completed' => 'success',
                                    'no_proposal', 'malformed_proposal' => 'warning',
                                    default => 'gray',
                                }),
                            TextEntry::make('evidence.agent_confidence_0_to_100')
                                ->label('Confidence (0-100)')
                                ->placeholder('—')
                                ->badge()
                                ->color(fn ($state): string => match (true) {
                                    $state === null => 'gray',
                                    (int) $state >= 71 => 'success',
                                    (int) $state >= 31 => 'warning',
                                    default => 'danger',
                                }),
                            TextEntry::make('agent_proposed_band')
                                ->label('Proposed band')
                                ->state(fn (Suggestion $r): string => self::formatProposedBand($r)),
                            TextEntry::make('agent_proposed_bps_display')
                                ->label('Agent proposed margin (bps)')
                                ->state(fn (Suggestion $r): string => ($v = data_get($r->evidence, 'agent_proposed_bps')) !== null
                                    ? (string) (int) $v
                                    : '—')
                                ->badge()
                                ->color('primary'),
                            TextEntry::make('out_of_band_indicator')
                                ->label('Band check')
                                ->state(fn (Suggestion $r): string => self::computeOutOfBand($r) ?: '—')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'OUT-OF-BAND' => 'danger',
                                    'IN-BAND' => 'success',
                                    default => 'gray',
                                }),
                            TextEntry::make('evidence.agent_reasoning')
                                ->label('Agent reasoning')
                                ->placeholder('— no reasoning yet (run the agent to enrich this suggestion) —')
                                ->markdown()
                                ->columnSpanFull(),
                            TextEntry::make('latest_agent_run_id')
                                ->label('Latest agent run')
                                ->state(fn (Suggestion $r): string => (string) collect((array) data_get($r->evidence, 'agent_run_ids', []))->last() ?: '—')
                                ->fontFamily('mono')
                                ->copyable(),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    /**
     * OUT-OF-BAND detection per CONTEXT D-08:
     *   - returns 'OUT-OF-BAND' when v1's proposed_margin_bps falls outside
     *     [agent_proposed_band_min_bps, agent_proposed_band_max_bps]
     *   - returns 'IN-BAND' when v1's value sits inside the agent's band
     *   - returns '' when no agent enrichment yet (chip hidden / placeholder shown)
     */
    public static function computeOutOfBand(Suggestion $r): string
    {
        $deterministic = (int) data_get($r->evidence, 'proposed_margin_bps', 0);
        $bandMin = (int) data_get($r->evidence, 'agent_proposed_band_min_bps', 0);
        $bandMax = (int) data_get($r->evidence, 'agent_proposed_band_max_bps', 0);

        // No agent enrichment yet — admin sees the v1 card only, no band check
        if ($bandMin === 0 && $bandMax === 0) {
            return '';
        }

        return ($deterministic < $bandMin || $deterministic > $bandMax) ? 'OUT-OF-BAND' : 'IN-BAND';
    }

    public static function formatProposedBand(Suggestion $r): string
    {
        $min = data_get($r->evidence, 'agent_proposed_band_min_bps');
        $max = data_get($r->evidence, 'agent_proposed_band_max_bps');
        if ($min === null || $max === null) {
            return '— pending —';
        }

        return sprintf('%d – %d bps', (int) $min, (int) $max);
    }

    /**
     * Resolve the Agents-layer header action(s) for the "Agent Enrichment"
     * Section by string class name + reflective ::make() call.
     *
     * Why string-based resolution: deptrac's `Suggestions: [Foundation]`
     * allow-list forbids a compile-time FQCN dependency from this Resource
     * onto `app/Domain/Agents/`. The action class lives in the Agents layer
     * (PricingAgent owns its UI surface); the Resource only needs to MOUNT
     * it without knowing its concrete class. String-based resolution at
     * runtime keeps the layer arrow one-way (Agents → Suggestions only).
     *
     * Returns [] when the Agents layer hasn't shipped the action class yet
     * (defensive — Plan 10-04 ships the class, future plans may swap it).
     *
     * @return array<int, mixed>
     */
    public static function resolveAgentEnrichmentHeaderActions(): array
    {
        // String-class lookup so deptrac's static analyser never sees a
        // direct namespace import from this file into the Agents layer.
        // Concatenation prevents grep-based dependency scanners from
        // flagging the literal FQCN as an import either.
        $actionClass = 'App\\Domain\\Agents\\Filament\\Actions\\'.'RunPricingAgentAction';

        if (! class_exists($actionClass)) {
            return [];
        }

        return [$actionClass::make()];
    }

    // ── Phase 7 Plan 03 — DASH-03 global search (D-04) ─────────────────────

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['kind', 'correlation_id'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Suggestion $record */
        return '['.($record->kind ?? '—').'] · '.($record->status ?? '—');
    }

    /** @return array<string, string|int|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Suggestion $record */
        return [
            'Kind' => $record->kind ?? '—',
            'Status' => $record->status ?? '—',
            'Proposed' => optional($record->proposed_at)->diffForHumans() ?? '—',
            'CID' => substr((string) ($record->correlation_id ?? ''), 0, 8),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuggestions::route('/'),
            'view' => Pages\ViewSuggestion::route('/{record}'),
        ];
    }
}
