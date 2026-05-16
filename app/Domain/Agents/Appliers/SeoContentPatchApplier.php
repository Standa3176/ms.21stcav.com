<?php

declare(strict_types=1);

namespace App\Domain\Agents\Appliers;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12 Plan 04 — per-field write-through applier for kind='seo_content_patch'.
 *
 * Architectural exemption: this is the SIXTH sanctioned writer in app/Domain/Agents/.
 * It writes to Product.{column} + ProductOverride.pin_{field}, plus flips the
 * parent Suggestion's status. Tests/Architecture/AgentsWriteOnlyViaSuggestionsTest
 * exempts this file via Finder->notPath().
 *
 * CRITICAL P12 GOTCHA — title→name column mapping:
 *   SEOAGT-01 names the field 'title' (the user-facing semantic). The Product
 *   model has NO 'title' column — the canonical Eloquent column is 'name'.
 *   FIELD_TO_PRODUCT_COLUMN translates 'title' → 'name' at write time.
 *   SeoContentPatchApplierTitleToNameTest fences this with 3 assertions:
 *     (a) Product.name updated after applying field='title'
 *     (b) Product fillable does NOT list 'title'
 *     (c) This source file literally contains the string "'title' => 'name'"
 *
 * Workflow (D-04 from CONTEXT.md):
 *   1. Decode payload.patches[]
 *   2. For each patch with applied_at !== null (admin selected it):
 *      a. Translate field → column via FIELD_TO_PRODUCT_COLUMN
 *      b. Translate field → pin_column via FIELD_TO_PIN_COLUMN
 *      c. $product->{column} = $patch['after']
 *      d. Mark $overrideUpdates[$pinColumn] = true
 *      e. Auditor::record('seo.content_patch_applied', { field, before_hash,
 *         after_hash, product_id, suggestion_id, agent_run_id })
 *      f. Audit records the USER-FACING field name ('title'), NOT the column
 *         name ('name') — preserves admin's mental model.
 *   3. $product->save() (single Eloquent save coalesces multi-field updates)
 *   4. ProductOverride::updateOrCreate — upsert pin flags WITHOUT clobbering
 *      pin_image / pin_slug / margin_basis_points etc.
 *   5. Flip Suggestion.status: APPLIED if all patches applied_at != null,
 *      PENDING if subset (so admin can return later to approve more)
 *
 * Transactional integrity: all 4 write steps wrap in DB::transaction so a
 * failure mid-flight rolls back consistently. The audit Auditor::record uses
 * Spatie's activitylog which has its own internal transaction discipline.
 *
 * Idempotency: a second apply() call against a fully-applied Suggestion finds
 * status=STATUS_APPLIED already and the loop sees no patches with applied_at
 * (depending on how the form action wires) — re-running is safe. Treat as
 * best-effort idempotency: the architecture invariant is "approve → applier
 * runs once" through ApplySuggestionJob; double-clicks are gated upstream.
 */
final class SeoContentPatchApplier implements SuggestionApplier
{
    /** P12 CRITICAL — title → name column mapping (Product has no 'title' column). */
    private const FIELD_TO_PRODUCT_COLUMN = [
        'title' => 'name',
        'short_description' => 'short_description',
        'long_description' => 'long_description',
        'meta_description' => 'meta_description',
    ];

    /** Field → ProductOverride pin column (verified at ProductOverride.php:42-50 fillable). */
    private const FIELD_TO_PIN_COLUMN = [
        'title' => 'pin_title',
        'short_description' => 'pin_short_description',
        'long_description' => 'pin_long_description',
        'meta_description' => 'pin_meta_description',
    ];

    public function __construct(private readonly Auditor $auditor) {}

    /** @return array<int, string> */
    public function supports(): array
    {
        return ['seo_content_patch'];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(Suggestion $suggestion): array
    {
        $payload = (array) $suggestion->payload;
        $productId = (int) ($payload['product_id'] ?? 0);
        $patches = (array) ($payload['patches'] ?? []);

        if ($productId === 0 || $patches === []) {
            throw new \RuntimeException(
                "SeoContentPatchApplier: malformed payload (suggestion_id={$suggestion->id})"
            );
        }

        $product = Product::findOrFail($productId);
        $agentRunId = (string) ($payload['agent_run_id'] ?? '');

        $appliedFields = [];
        $overrideUpdates = [];

        DB::transaction(function () use ($product, $patches, $suggestion, $agentRunId, &$appliedFields, &$overrideUpdates): void {
            foreach ($patches as $patch) {
                if (! is_array($patch)) {
                    continue;
                }
                // applied_at===null means admin did NOT select this field
                if (($patch['applied_at'] ?? null) === null) {
                    continue;
                }

                $field = (string) ($patch['field'] ?? '');
                if (! isset(self::FIELD_TO_PRODUCT_COLUMN[$field])) {
                    continue;
                }

                $column = self::FIELD_TO_PRODUCT_COLUMN[$field];
                $pinColumn = self::FIELD_TO_PIN_COLUMN[$field];
                $before = (string) ($patch['before'] ?? '');
                $after = (string) ($patch['after'] ?? '');

                $product->{$column} = $after;
                $overrideUpdates[$pinColumn] = true;
                $appliedFields[] = $field;

                // Audit row records user-facing 'field' value (NOT the column
                // name) — preserves admin's mental model. Verbatim before/after
                // stays on Suggestion.payload (own retention); we only persist
                // sha256 hashes here so the audit table stays lean.
                $this->auditor->record('seo.content_patch_applied', [
                    'product_id' => (int) $product->id,
                    'field' => $field,
                    'agent_run_id' => $agentRunId,
                    'suggestion_id' => (string) $suggestion->id,
                    'before_hash' => hash('sha256', $before),
                    'after_hash' => hash('sha256', $after),
                ]);
            }

            if ($appliedFields !== []) {
                $product->save();

                // ProductOverride.margin_basis_points is NOT NULL with no DB
                // default (Phase 3 D-08 — column was designed as a margin %
                // override). When we're upserting an override row solely for
                // SEO pin flags, supply 0 as the margin override (semantically:
                // "no margin override; only pin flags are meaningful here"). An
                // existing row's margin_basis_points is preserved because the
                // 0 default lives in the 2nd-arg "values to insert" array and
                // updateOrCreate's 1st-arg "match" array also drives the
                // UPDATE path's SET list — only $overrideUpdates flags are SET.
                $existing = ProductOverride::where('product_id', (int) $product->id)->first();
                if ($existing !== null) {
                    $existing->fill($overrideUpdates)->save();
                } else {
                    ProductOverride::create(array_merge(
                        ['product_id' => (int) $product->id, 'margin_basis_points' => 0],
                        $overrideUpdates,
                    ));
                }
            }

            // Flip Suggestion status — APPLIED only when EVERY patch has applied_at.
            $totalPatches = count(array_filter($patches, fn ($p) => is_array($p) && isset($p['field'])));
            $appliedCount = count($appliedFields);
            $allApplied = $appliedCount > 0 && $appliedCount === $totalPatches;

            $suggestion->update([
                'status' => $allApplied
                    ? Suggestion::STATUS_APPLIED
                    : Suggestion::STATUS_PENDING,
                'applied_at' => $appliedCount > 0 ? now() : null,
            ]);
        });

        return [
            'applied' => $appliedFields !== [],
            'applied_fields' => $appliedFields,
            'product_id' => (int) $product->id,
            'suggestion_id' => (string) $suggestion->id,
        ];
    }
}
