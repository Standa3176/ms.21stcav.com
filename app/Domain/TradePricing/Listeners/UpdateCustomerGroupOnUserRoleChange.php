<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Listeners;

use App\Domain\TradePricing\Services\RoleToGroupMapper;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Phase 9 Plan 04 Task 3 — TRDE-04 customer -> group sync listener.
 *
 * Subscribes to App\Domain\Webhooks\Events\CustomerRegistered (Phase 4 v1
 * event fired on every Woo customer.created / customer.updated webhook).
 * Reads the receipt body, resolves the email -> existing User row, resolves
 * the Woo role -> CustomerGroup id via RoleToGroupMapper, then updates
 * users.customer_group_id with compare-and-swap idempotency.
 *
 * UPDATE-ONLY (B-04 hardening): when the webhook email does not match an
 * existing User row, the listener skips silently. Cold-start provisioning
 * (the legitimate "create User from Woo customer record" workflow) is the
 * job of `b2b:backfill-customer-groups` (Plan 09-06 Task 1) — this listener
 * is NOT a User-creation surface. Eliminates DoS (forged webhooks creating
 * spam User rows) and account-squat (forged email registering a User row
 * that the real owner later registers and finds preassigned with an
 * unwanted group affiliation).
 *
 * Compare-and-swap idempotency (Pitfall 4): re-firing the same payload
 * results in zero User->save() calls when the column is already at the
 * desired state. This keeps the activity_log audit trail clean — only
 * actual role changes produce updated_at drift + LogsActivity entries.
 *
 * Mass-assignment: writes go through ->forceFill([...])->save() because
 * customer_group_id is INTENTIONALLY OMITTED from User::$fillable (B-02
 * hardening). See app/Models/User.php docblock above the casts() method.
 */
final class UpdateCustomerGroupOnUserRoleChange implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly RoleToGroupMapper $mapper,
    ) {}

    public function handle(CustomerRegistered $event): void
    {
        $receipt = WebhookReceipt::findOrFail($event->webhookReceiptId);

        // raw_body is LONGTEXT — decode defensively. Some test setups may
        // construct the receipt with an already-decoded array in raw_body
        // (no cast on the column today); production webhooks always store
        // the raw bytes as received.
        $body = $receipt->raw_body;
        if (is_string($body)) {
            $body = (array) json_decode($body, true);
        } else {
            $body = (array) $body;
        }

        $email = (string) ($body['email'] ?? '');
        $role = (string) ($body['role'] ?? 'customer');

        if ($email === '') {
            Log::warning('UpdateCustomerGroupOnUserRoleChange: no email — skipping', [
                'webhook_receipt_id' => $receipt->id,
                'correlation_id' => $event->correlationId,
            ]);

            return;
        }

        // B-04 — UPDATE-ONLY. Skip silently when no local user exists.
        // Cold-start provisioning is `b2b:backfill-customer-groups`
        // (Plan 09-06 Task 1) which walks ALREADY-EXISTING users.
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            Log::info('UpdateCustomerGroupOnUserRoleChange: no local user — skipping (B-04 update-only)', [
                'webhook_receipt_id' => $receipt->id,
                'correlation_id' => $event->correlationId,
            ]);

            return;
        }

        $newGroupId = $this->mapper->mapToGroupId($role);    // null = retail

        if ($user->customer_group_id === $newGroupId) {
            // Compare-and-swap (Pitfall 4) — already at desired state.
            // No write, no audit drift, no updated_at flap.
            return;
        }

        $oldGroupId = $user->customer_group_id;

        // forceFill because customer_group_id is intentionally NOT in
        // User::$fillable (B-02 mass-assignment hardening). The listener
        // and the Plan 09-06 backfill command are the ONLY legitimate
        // writers of this column.
        $user->forceFill(['customer_group_id' => $newGroupId])->save();

        Log::info('TradePricing: user customer_group updated', [
            'user_id' => $user->id,
            'old_group_id' => $oldGroupId,
            'new_group_id' => $newGroupId,
            'role' => $role,
            'correlation_id' => $event->correlationId,
        ]);
    }
}
