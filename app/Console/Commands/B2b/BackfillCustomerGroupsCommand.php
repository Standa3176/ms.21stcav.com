<?php

declare(strict_types=1);

namespace App\Console\Commands\B2b;

use App\Console\Commands\BaseCommand;
use App\Domain\TradePricing\Services\RoleToGroupMapper;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 9 Plan 06 Task 1 — RESEARCH §Open Q2 backfill mechanism (TRDE-04 cold-start path).
 *
 * Walks every User with an email and tries to resolve their customer_group
 * from the most recent customer.created/customer.updated WebhookReceipt
 * payload role field. UPDATE-ONLY (mirrors listener B-04 contract — never
 * creates User rows from webhook payloads). Idempotent — only writes when
 * the resolved group differs from the user's current customer_group_id.
 *
 * Dry-run by default (v1 convention). Operator runs `--live` after rehearsal.
 *
 * W-03 — webhook_receipts.raw_body is LONGTEXT (not JSON column type).
 * MySQL 8 still allows whereJsonContains against LONGTEXT but it relies on
 * runtime JSON parsing, no functional index. Primary path uses
 * whereJsonContains; if that returns 0 matches we fall back to LIKE against
 * the raw text body and emit an explicit WARN log line so ops know the
 * fallback was exercised.
 *
 * Cold-start scope: this command walks ALREADY-EXISTING User rows. It does
 * NOT create User rows for emails seen only in webhook payloads — that's
 * out of scope for Phase 9. If ops needs that, run a separate one-off
 * script or use Filament User CRUD. Keeps the security contract simple:
 * webhooks NEVER create User rows automatically; only operator-administered
 * surfaces (Filament, manual ops scripts) do.
 */
class BackfillCustomerGroupsCommand extends BaseCommand
{
    protected $signature = 'b2b:backfill-customer-groups {--live : Commit writes (default is dry-run)}';

    protected $description = 'Backfill users.customer_group_id from existing Woo customer webhooks (dry-run default).';

    public function __construct(
        private readonly RoleToGroupMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $mode = $live ? 'LIVE' : 'DRY-RUN';
        $this->info("b2b:backfill-customer-groups [{$mode}]");

        $updated = 0;
        $skippedNoWebhook = 0;
        $unchanged = 0;
        $likeFallbackUsed = 0;

        User::query()
            ->whereNotNull('email')
            ->chunkById(1000, function ($users) use (&$updated, &$skippedNoWebhook, &$unchanged, &$likeFallbackUsed, $live) {
                foreach ($users as $user) {
                    $receipt = $this->findReceiptForEmail($user->email, $likeFallbackUsed);

                    if ($receipt === null) {
                        $skippedNoWebhook++;
                        continue;
                    }

                    $body = $receipt->raw_body;
                    if (is_string($body)) {
                        $body = (array) json_decode($body, true);
                    } else {
                        $body = (array) $body;
                    }

                    $role = (string) ($body['role'] ?? 'customer');
                    $newGroupId = $this->mapper->mapToGroupId($role);

                    // Compare-and-swap (Pitfall 4) — if already at desired
                    // state, skip. Re-running --live should produce zero
                    // saves on a stable role landscape.
                    if ($user->customer_group_id === $newGroupId) {
                        $unchanged++;
                        continue;
                    }

                    if ($live) {
                        $oldGroupId = $user->customer_group_id;
                        // forceFill — customer_group_id is intentionally
                        // omitted from User::$fillable (B-02 hardening).
                        // The listener and this backfill command are the
                        // ONLY legitimate writers of this column.
                        $user->forceFill(['customer_group_id' => $newGroupId])->save();
                        Log::info('b2b:backfill: user customer_group updated', [
                            'user_id' => $user->id,
                            'old_group_id' => $oldGroupId,
                            'new_group_id' => $newGroupId,
                            'role' => $role,
                        ]);
                    }
                    $updated++;
                }
            });

        $this->info(sprintf(
            '[%s] would_update=%d unchanged=%d skipped_no_webhook=%d like_fallback=%d',
            $mode,
            $updated,
            $unchanged,
            $skippedNoWebhook,
            $likeFallbackUsed,
        ));

        if ($likeFallbackUsed > 0) {
            $this->warn("WARN: webhook receipts may not have email indexed — fallback LIKE used for {$likeFallbackUsed} lookups");
            Log::warning('b2b:backfill: W-03 LIKE fallback exercised', [
                'like_fallback_count' => $likeFallbackUsed,
            ]);
        }

        if (! $live) {
            $this->warn('Dry-run only. Re-run with --live to commit.');
        }

        return self::SUCCESS;
    }

    /**
     * W-03 — try whereJsonContains first; fall back to LIKE on LONGTEXT raw_body.
     *
     * whereJsonContains works against LONGTEXT in MySQL 8 (relies on JSON_CONTAINS
     * runtime parsing — no functional index, slow but correct). If the parse
     * misses (e.g. the receipt payload was double-encoded or the column type
     * was migrated and indices are stale) the LIKE fallback finds it via
     * substring match against the literal `"email":"<email>"` form Woo emits.
     */
    private function findReceiptForEmail(string $email, int &$likeFallbackUsed): ?WebhookReceipt
    {
        // Primary path — whereJsonContains works against LONGTEXT in MySQL 8.
        $receipt = WebhookReceipt::query()
            ->whereIn('topic', ['customer.created', 'customer.updated'])
            ->whereJsonContains('raw_body->email', $email)
            ->latest('id')
            ->first();

        if ($receipt !== null) {
            return $receipt;
        }

        // W-03 fallback — LIKE against the LONGTEXT body. Slow but correct.
        // addslashes escapes embedded quotes so an attacker-controlled email
        // (e.g. a"b@c.test) cannot break out of the LIKE pattern. Eloquent
        // parameter binding still applies on top of this on the value side.
        $receipt = WebhookReceipt::query()
            ->whereIn('topic', ['customer.created', 'customer.updated'])
            ->where('raw_body', 'LIKE', '%"email":"'.addslashes($email).'"%')
            ->latest('id')
            ->first();

        if ($receipt !== null) {
            $likeFallbackUsed++;
        }

        return $receipt;
    }
}
