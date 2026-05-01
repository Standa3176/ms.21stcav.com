<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Services;

use App\Domain\Quotes\Models\Quote;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 Plan 05 Task 1 — Phase 14 forward-compat quote-creation surface.
 *
 * Service-only entry point — NOT exposed as an artisan command in v1. Phase 14
 * chatbot's `propose_quote(customer_email, line_items)` agent tool will wrap
 * this action; Phase 13 WhatsApp inbound `quote_request` intent can
 * also reuse this surface to create draft Quotes for anonymous leads (D-01
 * dual-mode customer — user_id may be null).
 *
 * Composes:
 *   1. Customer group resolution (D-02 priority — user_id wins)
 *   2. Customer group name denormalisation (A9 + Pitfall 6 — survives FK rename)
 *   3. Quote::create() with status=draft + expires_at = now() + default_expiry_days
 *   4. QuoteLineWriter::add() per line item (Plan 11-02 sole creation path)
 *
 * Why service vs job: synchronous quote creation is fast enough (no AI calls,
 * no network IO inside the action — TradeRuleResolver is in-process). Phase 14
 * chatbot streams the agent response while this action runs; queueing would
 * add coupling for no latency benefit.
 *
 * Returns: the persisted Quote with `lines` relation eager-loaded so the
 * caller (chatbot, WhatsApp listener) can immediately render the quote ULID
 * + total_pence_at_quote (recomputed by QuoteTotalRecomputeObserver during
 * the line writer chain).
 */
final class ImportQuoteAction
{
    public function __construct(
        private readonly QuoteLineWriter $writer,
    ) {}

    /**
     * Create a draft Quote with snapshotted line prices.
     *
     * @param array{
     *     customer_email: string,
     *     customer_name?: ?string,
     *     user_id?: ?int,
     *     customer_group_id?: ?int,
     *     billing_address?: ?array,
     *     line_items: array<int, array{sku: string, quantity_int: int}>,
     *     notes?: ?string,
     * } $input
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when any line item's SKU does not match a Product (propagates from PriceSnapshotter)
     */
    public function execute(array $input): Quote
    {
        return DB::transaction(function () use ($input) {
            $customerGroupId = $this->resolveCustomerGroupId($input);
            $customerGroupName = $customerGroupId !== null
                ? CustomerGroup::query()->whereKey($customerGroupId)->value('name')
                : null;

            $expiryDays = (int) config('quote.default_expiry_days', 14);

            /** @var Quote $quote */
            $quote = Quote::create([
                'user_id' => $input['user_id'] ?? null,
                'customer_email' => $input['customer_email'],
                'customer_name' => $input['customer_name'] ?? null,
                'customer_group_id' => $customerGroupId,
                'customer_group_name_at_quote' => $customerGroupName,
                'billing_address' => $input['billing_address'] ?? null,
                'status' => Quote::STATUS_DRAFT,
                'total_pence_at_quote' => 0,
                'expires_at' => now()->addDays($expiryDays),
            ]);

            foreach ($input['line_items'] as $line) {
                $this->writer->add(
                    $quote,
                    (string) $line['sku'],
                    (int) $line['quantity_int'],
                );
            }

            // Reload with lines relation so callers see the recomputed
            // total_pence_at_quote (QuoteTotalRecomputeObserver fires on each line save).
            $fresh = $quote->fresh(['lines']);
            assert($fresh instanceof Quote);

            return $fresh;
        });
    }

    /**
     * D-02 customer_group resolution priority:
     *   1. user_id set → users.customer_group_id (snapshotted at quote moment)
     *   2. user_id null → input.customer_group_id (anonymous-lead manual select)
     *   3. neither → null (retail pricing)
     */
    private function resolveCustomerGroupId(array $input): ?int
    {
        $userId = $input['user_id'] ?? null;
        if ($userId !== null && $userId !== 0) {
            $user = User::query()->whereKey($userId)->first();
            if ($user !== null && property_exists($user, 'customer_group_id')) {
                return $user->customer_group_id !== null ? (int) $user->customer_group_id : null;
            }
            // User model may surface customer_group_id via attribute; fall through to input.
            $cg = $user?->getAttribute('customer_group_id');

            return $cg !== null ? (int) $cg : ($input['customer_group_id'] ?? null);
        }

        $explicit = $input['customer_group_id'] ?? null;

        return $explicit !== null ? (int) $explicit : null;
    }
}
