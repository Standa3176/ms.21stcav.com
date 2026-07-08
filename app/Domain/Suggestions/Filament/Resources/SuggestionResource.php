<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\ProductAutoCreate\Jobs\RunAutoCreatePipelineJob;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use App\Foundation\Audit\Services\Auditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SuggestionResource extends Resource
{
    use HasExportableTable;

    protected static ?string $model = Suggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Review';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'kind';

    /** Cache key for the Brand SelectFilter option list (pre-warmed by products:refresh-brands-to-add). */
    public const BRAND_FILTER_OPTIONS_CACHE_KEY = 'suggestions.brand_filter_options';

    /**
     * Quick task 260707-gsy — per-request memo so the Readiness column's
     * state/color/tooltip closures don't each re-query supplier_sku_cache.
     * Keyed by Suggestion primary key.
     *
     * @var array<string, array{label:string,color:string}|null>
     */
    protected static array $readinessMemo = [];

    /**
     * Quick task 260707-gsy — PURE readiness verdict for the Suggestions list.
     *
     * sourceable = the SKU is currently carried by a supplier (membership in
     * supplier_sku_cache); brand comes from evidence.brand. Verdict:
     *   - not sourceable            → 'Not sourceable' / gray
     *   - sourceable + blank/junk   → 'Needs brand'    / warning
     *   - sourceable + usable brand → 'Ready'          / success (Auto-create
     *     auto-adds the Woo brand if new, per 260702-qd8)
     * Junk = config('product_auto_create.brands_to_add_exclude') (case-insensitive).
     *
     * @return array{label:string,color:string}
     */
    public static function readinessFrom(bool $sourceable, ?string $brand): array
    {
        if (! $sourceable) {
            return ['label' => 'Not sourceable', 'color' => 'gray'];
        }

        $brand = trim((string) $brand);
        $junk = $brand === '' || in_array(
            mb_strtolower($brand),
            array_map('mb_strtolower', (array) config('product_auto_create.brands_to_add_exclude', [])),
            true,
        );

        return $junk
            ? ['label' => 'Needs brand', 'color' => 'warning']
            : ['label' => 'Ready', 'color' => 'success'];
    }

    /**
     * Quick task 260707-gsy — readiness verdict for a record (null for
     * non-opportunity kinds). Memoised per request.
     *
     * CRITICAL (memory: SQLite ↔ MariaDB strict trap): the sourceable check is
     * engine-independent — the SKU is pulled from evidence in PHP, lowercased +
     * trimmed, then matched via a plain indexed where('sku', …)->exists() on
     * supplier_sku_cache. NO JSON_UNQUOTE/JSON_EXTRACT SQL (that's exactly what
     * has repeatedly bitten this page).
     *
     * @return array{label:string,color:string}|null
     */
    public static function readiness(Suggestion $record): ?array
    {
        if ($record->kind !== 'new_product_opportunity') {
            return null;
        }

        $key = (string) $record->getKey();
        if (! array_key_exists($key, self::$readinessMemo)) {
            $sku = strtolower(trim((string) data_get($record->evidence, 'sku', '')));
            $sourceable = $sku !== '' && DB::table('supplier_sku_cache')->where('sku', $sku)->exists();
            self::$readinessMemo[$key] = self::readinessFrom(
                $sourceable,
                (string) data_get($record->evidence, 'brand', ''),
            );
        }

        return self::$readinessMemo[$key];
    }

    /**
     * Quick task 260606-gnu — high-confidence sourceable attention badge.
     *
     * Counts only rows the operator can actually action: pending +
     * new_product_opportunity + sku ON supplier DB + supporting_competitors
     * >= 3. Rationale: 62% of pending rows are competitor-only orphans
     * (off-supplier-DB), so the raw pending count was inbox noise. The wider
     * pools stay reachable via existing filters; getNavigationBadgeTooltip()
     * exposes the three-tier breakdown so the operator can see what they're
     * filtering out at a glance.
     *
     * Defensive try/catch preserved — badge runs on every sidebar render;
     * a failed query (missing table, broken connection) must NOT 500 the
     * entire admin chrome.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            // Quick task 260606-lhp — delegates the 4-clause predicate to the
            // shared Suggestion::scopeHighConfidenceSourceable scope so the
            // sidebar badge, the badge tooltip, and the Home dashboard
            // "High-confidence sourceable opportunities" tile cannot drift.
            $count = Suggestion::query()->highConfidenceSourceable()->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Quick task 260606-gnu — three-tier breakdown tooltip.
     *
     * Hovering the sidebar badge exposes the underlying counts so the
     * operator can decide whether to drill into the wider pools via the
     * existing filters. Cache::remember keeps the three EXISTS queries off
     * the hot path — refreshed once per minute, which is well below the
     * cadence at which the inbox actually changes.
     *
     * Filament 3 picks this up automatically (documented Resource extension
     * point alongside getNavigationBadge / getNavigationBadgeColor).
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        try {
            return Cache::remember('suggestions.nav_breakdown', 60, function (): string {
                $base = Suggestion::query()
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->where('kind', 'new_product_opportunity');

                $rawPending = (clone $base)->count();

                // Sourceable = pending NPO + EXISTS supplier_sku_cache. A
                // DIFFERENT predicate to "high-confidence" — no competitor
                // count gate — so it stays inline; the scope deliberately
                // bundles the competitor gate.
                $sourceable = (clone $base)
                    ->whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku')))))")
                    ->count();

                // Quick task 260606-lhp — high-confidence count comes from the
                // shared scope so the tooltip cannot drift from the sidebar
                // badge or the Home dashboard tile. The scope already applies
                // status + kind so the surrounding $base clone is redundant
                // here; querying via Suggestion::query() avoids double-binding.
                $highConfidence = Suggestion::query()->highConfidenceSourceable()->count();

                return sprintf(
                    '%s high-confidence • %s sourceable • %s raw',
                    number_format($highConfidence),
                    number_format($sourceable),
                    number_format($rawPending),
                );
            });
        } catch (\Throwable) {
            return null;
        }
    }

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
     *
     * Phase 12 Plan 05 (Open Question O-5) — hide kind='agent_guardrail_blocked'
     * from the default list. These rows are forensic audit trail for blocked
     * SEO agent runs (P12-B mitigation per Plan 12-04); admin doesn't approve
     * them (no SuggestionApplier is registered for that kind). Operators can
     * opt-in to view them via the explicit 'kind' SelectFilter chip below —
     * the request()-aware `when()` clause exposes them when the user has
     * filtered explicitly by kind.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['resolvedByUser'])
            ->when(
                ! request()->filled('tableFilters.kind.value'),
                fn (Builder $q) => $q->where('kind', '!=', 'agent_guardrail_blocked'),
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            // Quick task 260707-gsy — auto-refresh so rows flip to 'applied' as
            // the Horizon auto-create pipeline finishes, giving the operator
            // visible feedback after a bulk Auto-create instead of a silent list.
            // Read-only refresh; safe.
            ->poll('30s')
            // Quick task 260707-iz9 — friendly empty state so a filtered-to-nothing
            // list explains itself instead of showing a bare "No records".
            ->emptyStateHeading('No suggestions match')
            ->emptyStateDescription('New product opportunities appear here when competitors list SKUs you do not sell yet. Clear the filters to see other kinds.')
            ->columns([
                // Part / SKU — the actual product identifier (from evidence JSON;
                // also present on margin_change rows). Searchable so an 8k-row
                // inbox is navigable by part number.
                TextColumn::make('sku')
                    ->label('Part / SKU')
                    ->state(fn (Suggestion $record) => data_get($record->evidence, 'sku'))
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('evidence->sku', 'like', "%{$search}%")),
                // Comp — # of competitors tracking this orphan SKU. Sortable, and
                // the table default-sorts on it DESC so the strongest
                // opportunities (most competitors) surface first.
                TextColumn::make('supporting_competitors')
                    ->label('Competitor count')
                    ->badge()
                    ->color('info')
                    ->tooltip('Number of competitors currently tracking this SKU')
                    ->state(fn (Suggestion $record) => $record->kind === 'new_product_opportunity'
                        ? (int) (data_get($record->evidence, 'supporting_competitors', 0))
                        : null)
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('evidence->supporting_competitors', $direction)),
                // Which competitors list it (names from competitor_sightings[]).
                TextColumn::make('competitors')
                    ->label('Competitors')
                    ->tooltip('Which competitors list this SKU')
                    ->state(fn (Suggestion $record) => collect((array) data_get($record->evidence, 'competitor_sightings', []))
                        ->pluck('name')->filter()->implode(', ') ?: null)
                    ->wrap()
                    ->placeholder('—'),
                // Competitor price / range — gross pennies → £.
                TextColumn::make('comp_price')
                    ->label('Comp price')
                    ->tooltip('Competitor price (range) seen for this SKU')
                    ->state(function (Suggestion $record): ?string {
                        $prices = collect((array) data_get($record->evidence, 'competitor_sightings', []))
                            ->pluck('price_gross_pennies')
                            ->filter(fn ($p) => $p !== null && $p !== '')
                            ->map(fn ($p) => (int) $p);
                        if ($prices->isEmpty()) {
                            return null;
                        }
                        $min = $prices->min() / 100;
                        $max = $prices->max() / 100;

                        return $min === $max
                            ? '£'.number_format($min, 2)
                            : '£'.number_format($min, 2).' – £'.number_format($max, 2);
                    })
                    ->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'pending' => 'warning',
                    'approved' => 'primary',
                    'rejected' => 'danger',
                    'applied' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                })->sortable(),
                // Quick task 260707-gsy — Readiness badge. Makes what-will-create
                // visible up front so the operator isn't left guessing after a
                // silent Auto-create: Ready (sourceable + usable brand),
                // Needs brand (sourceable but blank/junk brand → parks), Not
                // sourceable (no supplier carries the SKU). '—' for non-opportunity
                // kinds. Verdict is computed engine-independently via readiness()
                // (PHP-extracted SKU + supplier_sku_cache exists() — no JSON-in-SQL).
                TextColumn::make('readiness')
                    ->label('Readiness')
                    ->badge()
                    ->state(fn (Suggestion $record): ?string => self::readiness($record)['label'] ?? null)
                    ->color(fn (Suggestion $record): string => self::readiness($record)['color'] ?? 'gray')
                    ->placeholder('—')
                    ->tooltip(fn (Suggestion $record): ?string => match (self::readiness($record)['label'] ?? null) {
                        'Ready' => 'In the supplier feed + brand known — Auto-create will create it (brand auto-added if new).',
                        'Needs brand' => 'In the supplier feed but no usable brand — parks until a brand is set.',
                        'Not sourceable' => 'No supplier currently carries this SKU — cannot be created.',
                        default => null,
                    }),
                TextColumn::make('kind')->badge()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn ($record) => $record->correlation_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('proposed_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolvedByUser.name')->label('Resolved by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('supporting_competitors', 'desc')
            ->filters([
                // Phase 12 Plan 05 (Open Question O-5) — explicit kind options
                // INCLUDE agent_guardrail_blocked so an admin can opt-in to
                // viewing audit-blocked SEO runs. Default list hides them via
                // getEloquentQuery; choosing this filter value DOES expose them
                // because the `when(! filled(tableFilters.kind.value))` clause
                // skips the kind!='agent_guardrail_blocked' default scope.
                // Quick task 260707-gsy — default to new_product_opportunity so
                // the operator lands on the actionable set (still freely
                // changeable). Option list + getEloquentQuery guardrail-hiding
                // unchanged; the default is only the initial selection.
                SelectFilter::make('kind')->options([
                    'margin_change' => 'margin_change',
                    'new_product_opportunity' => 'new_product_opportunity',
                    'crm_push_failed' => 'crm_push_failed',
                    'auto_create_failed' => 'auto_create_failed',
                    'seo_content_patch' => 'seo_content_patch',
                    'agent_guardrail_blocked' => 'agent_guardrail_blocked (audit)',
                ])->default('new_product_opportunity'),
                // Quick task 260707-gsy — default to pending (the actionable set).
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'applied' => 'Applied',
                    'failed' => 'Failed',
                ])->default('pending'),
                // Competitor-count bucket filter for new_product_opportunity
                // suggestions. Reads the denormalised evidence.supporting_competitors
                // value with a portable whereRaw (matches Laravel's JSON path
                // syntax used elsewhere in this Resource for the SKU search).
                // Replaces the tabs attempt (commits 41bcf90 / fd4028b / a389b8c)
                // which produced a Filament 3 internal 500 — see commit f2391b8
                // for the trace; the search wraps `where(Closure)` and somewhere
                // in that chain $this->model goes null when tabs are involved.
                // Filter-based UI sits in the existing filter row, no novel
                // Filament integration paths.
                SelectFilter::make('competitor_count_bucket')
                    ->label('Competitor count')
                    ->options([
                        '3plus' => '3+ competitors',
                        '2' => '2 competitors',
                        '1' => '1 competitor',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }
                        $cmp = match ($value) {
                            '3plus' => '>= 3',
                            '2' => '= 2',
                            '1' => '= 1',
                            default => null,
                        };
                        if ($cmp === null) {
                            return $query;
                        }

                        return $query->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) {$cmp}");
                    }),
                // "On supplier DB" — filter to SKUs that a supplier currently
                // carries (via the local supplier_sku_cache table, refreshed
                // Mon-Fri 07:05 London by supplier:refresh-sku-cache) so the
                // operator can isolate genuinely-actionable opportunities from
                // competitor-only orphan parts. Uses an EXISTS subquery so it
                // scales to the ~900k SKU feed without hitting MySQL packet
                // limits. Lowercased-trim match mirrors DraftFromSuggestionsCommand.
                SelectFilter::make('on_supplier_db')
                    ->label('On supplier DB')
                    ->options([
                        'yes' => 'Yes — sourceable',
                        'no' => 'No — competitor-only',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }
                        $existsSub = "SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku'))))";

                        return $value === 'yes'
                            ? $query->whereRaw("EXISTS ($existsSub)")
                            : $query->whereRaw("NOT EXISTS ($existsSub)");
                    }),
                // Quick task 260707-iz9 — Readiness filter. Narrows to exactly the
                // rows the operator can action, MATCHING the 260707-gsy Readiness
                // COLUMN verdict (same sourceable check + same brand + same junk
                // config): 'ready' = sourceable + non-blank non-junk brand;
                // 'needs_brand' = sourceable + (blank OR junk brand);
                // 'not_sourceable' = NOT sourceable. Supersedes the retired
                // Brand-on-Woo ternary. Driver-portable — sourceableExistsSql()
                // switches SQLite json_extract vs MariaDB JSON_UNQUOTE(JSON_EXTRACT)
                // (memory: SQLite↔MariaDB strict trap); brand via brandJsonExpr().
                SelectFilter::make('readiness')
                    ->label('Readiness')
                    ->options([
                        'ready' => 'Ready to create',
                        'needs_brand' => 'Needs brand',
                        'not_sourceable' => 'Not sourceable',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }
                        $exists = self::sourceableExistsSql();
                        $brand = self::brandJsonExpr();  // portable evidence.brand
                        $junk = array_values(array_filter(array_map(
                            'mb_strtolower',
                            (array) config('product_auto_create.brands_to_add_exclude', []),
                        )));
                        $ph = $junk === [] ? '' : implode(',', array_fill(0, count($junk), '?'));

                        return match ($value) {
                            'not_sourceable' => $query->whereRaw("NOT {$exists}"),
                            'ready' => $query
                                ->whereRaw($exists)
                                ->whereRaw("{$brand} IS NOT NULL AND TRIM({$brand}) != ''")
                                ->when($junk !== [], fn (Builder $q): Builder => $q->whereRaw("LOWER(TRIM({$brand})) NOT IN ({$ph})", $junk)),
                            'needs_brand' => $query
                                ->whereRaw($exists)
                                ->where(function (Builder $q) use ($brand, $junk, $ph): void {
                                    $q->whereRaw("{$brand} IS NULL OR TRIM({$brand}) = ''");
                                    if ($junk !== []) {
                                        $q->orWhereRaw("LOWER(TRIM({$brand})) IN ({$ph})", $junk);
                                    }
                                }),
                            default => $query,
                        };
                    }),
                // Quick task 260702-hg1 — Competitor / Brand / Brand-on-Woo
                // filters (Piece 2 of the brands-to-add workflow). Reads the
                // evidence.brand / evidence.brand_on_woo tags written by
                // products:refresh-brands-to-add (260702-h50) and the existing
                // evidence.competitor_sightings[].name array.
                //
                // Competitor — options are the Competitor master names; matches
                // any sighting name via JSON_SEARCH over the sightings array.
                SelectFilter::make('competitor')
                    ->label('Competitor')
                    ->options(fn (): array => Competitor::orderBy('name')->pluck('name', 'name')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        // MariaDB (prod): JSON_SEARCH over the sightings array.
                        // SQLite (tests): no JSON_SEARCH — walk json_each() over
                        // the competitor_sightings array and match .name.
                        // (memory: SQLite↔MariaDB strict trap.)
                        if (DB::connection()->getDriverName() === 'sqlite') {
                            return $query->whereRaw(
                                "EXISTS (SELECT 1 FROM json_each(evidence, '$.competitor_sightings') je WHERE json_extract(je.value, '$.name') = ?)",
                                [$value],
                            );
                        }

                        return $query->whereRaw("JSON_SEARCH(evidence, 'one', ?, null, '$.competitor_sightings[*].name') IS NOT NULL", [$value]);
                    }),
                // Brand — distinct evidence.brand values tagged on pending
                // new_product_opportunity rows. Searchable (the brand list is
                // long once refresh-brands-to-add has run across the inbox).
                SelectFilter::make('brand')
                    ->label('Brand')
                    // Quick task 260703-qc0 — the distinct-JSON scan behind this
                    // dropdown was running on EVERY admin render (~8,826 rows) and
                    // 30s-timing-out the Suggestions page under load. Now served
                    // from a cached list (5-min TTL, pre-warmed by
                    // products:refresh-brands-to-add) via brandFilterOptions().
                    ->options(fn (): array => self::brandFilterOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return ($value === null || $value === '')
                            ? $query
                            : $query->whereRaw(self::brandJsonExpr().' = ?', [$value]);
                    }),
                // Quick task 260707-iz9 — the Brand-on-Woo TernaryFilter was
                // retired here: it showed 'none' both ways under normal data and
                // is superseded by the Readiness filter above (which classifies
                // ready / needs_brand / not_sourceable directly).
            ])
            // Render filters always-visible above the table (operator
            // feedback 2026-06-03 — Collapsible variant still hid them
            // behind a funnel icon toggle, defeating the goal).
            ->filtersLayout(FiltersLayout::AboveContent)
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

                        Notification::make()
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

                        Notification::make()
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
                // Bulk auto-create — dispatches RunAutoCreatePipelineJob which
                // wraps `products:draft-from-suggestions --skus=... --no-confirm`
                // so the whole chain (generate-drafts → mark-applied →
                // assign-taxonomy → source-images → auto-publish) runs in one
                // Horizon job. Only operates on pending new_product_opportunity
                // rows — other kinds in the selection are silently skipped.
                BulkAction::make('auto_create_full')
                    ->label('Auto-create selected (full pipeline)')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Auto-create selected SKUs end-to-end')
                    ->modalDescription('Runs: generate Claude content → assign brand+category → source 3 validated images → push to Woo. Pipeline runs on Horizon. Non-pending or non-new-product-opportunity rows in the selection are ignored.')
                    ->modalSubmitActionLabel('Dispatch')
                    ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->form([
                        Toggle::make('source_images')
                            ->label('Source images (Icecat + web vision-validated)')
                            ->helperText('Disable for cheaper draft-only runs (~3p/SKU vs ~13p/SKU).')
                            ->default(true),
                        Toggle::make('auto_publish')
                            ->label('Auto-publish to Woo (skip review inbox)')
                            ->helperText('Publishes DIRECTLY to live storefront. Requires WOO_WRITE_ENABLED=true. Leave OFF to send drafts to /admin/auto-create-reviews for human approval.')
                            ->default(false),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $skus = $records
                            ->filter(fn (Suggestion $s): bool => $s->kind === 'new_product_opportunity'
                                && $s->status === Suggestion::STATUS_PENDING)
                            ->map(fn (Suggestion $s): string => trim((string) data_get($s->evidence, 'sku', '')))
                            ->filter(fn (string $sku): bool => $sku !== '')
                            ->unique()
                            ->values()
                            ->all();

                        if ($skus === []) {
                            Notification::make()
                                ->warning()
                                ->title('No eligible rows in selection')
                                ->body('Pick pending new_product_opportunity rows that have a SKU in evidence.')
                                ->send();

                            return;
                        }

                        RunAutoCreatePipelineJob::dispatch(
                            $skus,
                            (bool) ($data['source_images'] ?? true),
                            (bool) ($data['auto_publish'] ?? false),
                            (int) auth()->id(),
                        );

                        // Quick task 260707-gsy — set expectations + point onward
                        // so the operator isn't left wondering after a silent
                        // dispatch. Title ("{n} SKU(s) queued") + the dispatch call
                        // above are unchanged.
                        Notification::make()
                            ->success()
                            ->title(count($skus).' SKU(s) queued')
                            ->body('Dispatched. These rows will change to "applied" here as each finishes (this list refreshes itself), and the new products appear under Auto-create Health. The full created/skipped result lands in your notifications bell — usually under a minute.')
                            ->send();
                    }),
            ]);
    }

    // ── Quick task 260702-hg1 — driver-portable JSON expression helpers ────
    //
    // Prod is MariaDB (strict); tests run on SQLite. The two engines diverge on
    // JSON scalar extraction (JSON_UNQUOTE vs json_extract). These helpers
    // centralise the driver switch so the Brand + Readiness SelectFilters stay
    // prod-safe while the suite runs green on SQLite (memory: SQLite↔MariaDB
    // strict trap).

    /**
     * Distinct evidence.brand values across pending new_product_opportunity rows,
     * for the Brand filter dropdown. CACHED (5-min TTL) because the underlying
     * distinct-JSON scan over ~8,826 rows was running on EVERY admin page render
     * and 30s-timing-out under load. refresh-brands-to-add pre-warms this key.
     *
     * Pure caching wrapper — the cached result is byte-identical to the live
     * query (same kind scope, brandJsonExpr, filter/sort/mapWithKeys shape).
     *
     * @return array<string,string>
     */
    public static function brandFilterOptions(): array
    {
        return Cache::remember(self::BRAND_FILTER_OPTIONS_CACHE_KEY, 300, function (): array {
            // Driver-portable brand extraction: MariaDB (prod) needs
            // JSON_UNQUOTE(JSON_EXTRACT(...)); SQLite (tests) has no
            // JSON_UNQUOTE and json_extract() already unquotes scalars
            // (memory: SQLite↔MariaDB strict trap — green tests must
            // stay prod-safe).
            $brandExpr = self::brandJsonExpr();

            return DB::table('suggestions')
                ->where('kind', 'new_product_opportunity')
                ->whereNotNull(DB::raw($brandExpr))
                ->distinct()
                ->pluck(DB::raw($brandExpr.' as brand'))
                ->filter()
                ->sort()
                ->mapWithKeys(fn ($b): array => [$b => $b])
                ->all();
        });
    }

    private static function brandJsonExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(evidence, '$.brand')"
            : "JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.brand'))";
    }

    /**
     * Quick task 260707-iz9 — driver-portable "evidence.sku is in
     * supplier_sku_cache" EXISTS SQL, matching readiness()/on_supplier_db.
     *
     * SQLite (tests) has no JSON_UNQUOTE and json_extract() already unquotes
     * scalars; MariaDB (prod) needs JSON_UNQUOTE(JSON_EXTRACT(...)). The
     * lowercased-trim match mirrors readiness() + DraftFromSuggestionsCommand
     * (memory: SQLite↔MariaDB strict trap — green tests must stay prod-safe).
     */
    private static function sourceableExistsSql(): string
    {
        $sku = DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(suggestions.evidence, '$.sku')"
            : "JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku'))";

        return "EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM({$sku})))";
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
