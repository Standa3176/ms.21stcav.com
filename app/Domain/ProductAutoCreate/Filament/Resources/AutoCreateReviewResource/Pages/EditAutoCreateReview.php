<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages;

use App\Domain\Agents\Appliers\SeoContentPatchApplier;
use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 12 Plan 04 — Auto-Create Review Edit page extended with SEO sidebar
 * Section (SEOAGT-03).
 *
 * P12-F invariant (RESEARCH §Pattern 4 footnote — ADDITIVE EXTENSION ONLY):
 *   - This page DOES NOT override the parent EditRecord's `form()` or
 *     `infolist()` methods. The Phase 6 admin edit form schema lives on
 *     AutoCreateReviewResource::form() and stays byte-identical.
 *   - The new SEO sidebar mounts via a DIFFERENTLY-NAMED method
 *     `seoPatchesInfolist()`. Filament resolves any public method matching
 *     the *Infolist signature on an HasInfolists page — naming it after the
 *     entry's name keeps the parent default `infolist()` untouched.
 *   - AutoCreateEditFormUnchangedTest is the fence: it asserts the form
 *     fields list is unchanged AND that this class declares neither form()
 *     nor infolist() locally.
 *
 * Sidebar UX (CONTEXT D-03 + RESEARCH §Pattern 4 — Approve-selected variant):
 *   - Section header "SEO content patches (N proposed)" + agent-run-id prefix
 *     description (when a pending Suggestion exists).
 *   - RepeatableEntry over payload.patches[] with 5 columns:
 *       field (badge) / before (mono, 200ch limit, full-text tooltip) /
 *       after (mono, 200ch limit, tooltip) / reasoning (markdown) / applied_at
 *   - Section is `visible(false)` when NO pending seo_content_patch
 *     Suggestion exists for the product — admin sees nothing rather than an
 *     empty panel.
 *   - Footer Action::make('approve_selected_patches') opens a modal with a
 *     CheckboxList over the 4 valid SEO fields. On submit, the action loads
 *     the latest pending Suggestion, sets each selected patch's applied_at to
 *     now(), then invokes SeoContentPatchApplier directly. Notification on
 *     success.
 *
 * Filament 3.3 RepeatableEntry constraint (RESEARCH §Pattern 4 fallback A5):
 *   Per-row Actions inside RepeatableEntry are not natively supported. Plan
 *   12-04 ships the simpler "Approve selected via header action with
 *   CheckboxList" variant — RESEARCH explicitly recommends this as the
 *   primary ship. v2.1 may retrofit per-row actions if admin feedback
 *   justifies the complexity.
 */
class EditAutoCreateReview extends EditRecord implements HasInfolists
{
    use InteractsWithInfolists;

    protected static string $resource = AutoCreateReviewResource::class;

    /**
     * Header actions on the Edit page — adds the "Approve selected SEO patches"
     * button which opens a modal driving SeoContentPatchApplier.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve_selected_patches')
                ->label('Approve selected SEO patches')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->latestSeoSuggestion($this->getRecord()) !== null)
                ->modalHeading('Approve SEO patches')
                ->modalDescription('Tick each field whose proposed patch you want to apply. Approved patches write through to the product and pin the field from supplier sync.')
                ->form([
                    CheckboxList::make('approved_fields')
                        ->label('Fields to approve')
                        ->options(fn (): array => $this->seoPatchOptionsForApproval($this->getRecord()))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Product $product */
                    $product = $this->getRecord();
                    $suggestion = $this->latestSeoSuggestion($product);
                    if ($suggestion === null) {
                        Notification::make()
                            ->warning()
                            ->title('No pending SEO suggestion')
                            ->send();

                        return;
                    }

                    $approvedFields = (array) ($data['approved_fields'] ?? []);
                    if ($approvedFields === []) {
                        return;
                    }

                    // Mark each selected patch's applied_at to now() — the
                    // applier reads this flag to decide which patches to
                    // write through. Unselected patches stay applied_at=null
                    // and are left untouched on the Suggestion payload.
                    $payload = (array) $suggestion->payload;
                    $patches = (array) ($payload['patches'] ?? []);
                    $now = now()->toIso8601String();
                    foreach ($patches as $i => $patch) {
                        if (! is_array($patch)) {
                            continue;
                        }
                        $field = (string) ($patch['field'] ?? '');
                        if (in_array($field, $approvedFields, true)) {
                            $patches[$i]['applied_at'] = $now;
                        }
                    }
                    $payload['patches'] = $patches;
                    $suggestion->payload = $payload;
                    $suggestion->save();

                    app(SeoContentPatchApplier::class)->apply($suggestion->fresh());

                    Notification::make()
                        ->success()
                        ->title('SEO patches applied')
                        ->body(sprintf(
                            'Applied %d field(s); ProductOverride pins set so supplier sync respects the edits.',
                            count($approvedFields),
                        ))
                        ->send();
                }),
        ];
    }

    /**
     * SEO patches sidebar infolist — additive (does NOT override the parent
     * Edit page's form/infolist; P12-F invariant). Renders nothing when no
     * pending seo_content_patch Suggestion exists for the current Product.
     */
    public function seoPatchesInfolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getRecord())
            ->schema([
                Section::make('SEO content patches')
                    ->icon('heroicon-o-sparkles')
                    ->collapsible()
                    ->description(function (Product $record): string {
                        $suggestion = $this->latestSeoSuggestion($record);
                        if ($suggestion === null) {
                            return 'No SEO suggestions yet — agent runs nightly at 04:30 London for drafts with completeness < 85.';
                        }
                        $patchCount = count((array) data_get($suggestion->payload, 'patches', []));
                        $agentRunId = (string) data_get($suggestion->payload, 'agent_run_id', '');

                        return sprintf(
                            '%d patches proposed by agent run %s',
                            $patchCount,
                            substr($agentRunId, 0, 8),
                        );
                    })
                    ->visible(fn (Product $record): bool => $this->latestSeoSuggestion($record) !== null)
                    ->schema([
                        RepeatableEntry::make('seo_patches')
                            ->state(fn (Product $record): array => (array) data_get($this->latestSeoSuggestion($record)?->payload, 'patches', []))
                            ->schema([
                                TextEntry::make('field')->badge()->color('info'),
                                TextEntry::make('before')
                                    ->label('Current')
                                    ->limit(200)
                                    ->tooltip(fn ($state) => (string) $state)
                                    ->fontFamily('mono'),
                                TextEntry::make('after')
                                    ->label('Proposed')
                                    ->limit(200)
                                    ->tooltip(fn ($state) => (string) $state)
                                    ->fontFamily('mono'),
                                TextEntry::make('reasoning')->markdown(),
                                TextEntry::make('applied_at')
                                    ->placeholder('— pending —')
                                    ->dateTime(),
                            ])
                            ->columns(5),
                    ]),
            ]);
    }

    /**
     * Most-recent pending OR applied seo_content_patch Suggestion for the
     * given Product. Returns null when none exists (sidebar Section is then
     * hidden via the ->visible() callback above).
     */
    private function latestSeoSuggestion(Product $product): ?Suggestion
    {
        return Suggestion::query()
            ->where('kind', 'seo_content_patch')
            ->where('payload->product_id', $product->id)
            ->whereIn('status', [Suggestion::STATUS_PENDING, Suggestion::STATUS_APPLIED])
            ->latest('proposed_at')
            ->first();
    }

    /**
     * Build the CheckboxList options for the "Approve selected" action — one
     * entry per pending patch (i.e. applied_at===null) on the latest
     * Suggestion. The option label includes the field name + a truncated
     * "after" snippet so the admin can pick patches without leaving the modal.
     *
     * @return array<string, string>
     */
    private function seoPatchOptionsForApproval(Product $product): array
    {
        $suggestion = $this->latestSeoSuggestion($product);
        if ($suggestion === null) {
            return [];
        }

        $options = [];
        foreach ((array) data_get($suggestion->payload, 'patches', []) as $patch) {
            if (! is_array($patch)) {
                continue;
            }
            if (($patch['applied_at'] ?? null) !== null) {
                // Already applied — don't surface as a choice.
                continue;
            }
            $field = (string) ($patch['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $after = (string) ($patch['after'] ?? '');
            $options[$field] = $field . ' — ' . mb_substr($after, 0, 80);
        }

        return $options;
    }
}
