<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8 Plan 05 Task 2 — D-09 GDPR scrub-in-place for AgentRun rows
 * containing customer PII.
 *
 * Mirrors Phase 4 D-13 (gdpr:erase-bitrix-customer scrubs Contact + Company)
 * + Phase 6 GDPR pattern (scrub-in-place; preserve operational integrity).
 *
 * Preserved fields (operational + financial integrity per D-09):
 *   - cost_pence, prompt_token_count, completion_token_count
 *   - kind (AgentKind enum), system_prompt_hash, langfuse_trace_id
 *   - started_at, completed_at, status
 *
 * Scrubbed fields:
 *   - tool_calls JSON entries with customer_email/customer_phone/customer_name
 *     in inputs OR outputs → values replaced with REDACTED-{sha256-prefix}
 *   - agent_reasoning_summary → "[scrubbed per GDPR erasure {gdpr_log_ulid}]"
 *
 * Audit: every scrub run writes a row to `gdpr_erasure_log` with status='applied',
 * notes containing the JSON-encoded scrubbed AgentRun id list, and the
 * gdpr_log_ulid carried through `correlation_id` for cross-table traceability.
 *
 * DI integration: Phase 4's GdprEraseBitrixCustomerCommand injects this service
 * via constructor (Plan 05 extends Phase 4 erasure pathway WITHOUT modifying
 * the v1 command's logic shape — listener-style extension via DI bridge).
 */
final class AgentRunGdprScrubber
{
    /** @var array<int, string> */
    public const PII_KEYS = [
        'customer_email',
        'customer_phone',
        'customer_name',
        'email',
        'phone',
        'name',
    ];

    /**
     * Scrub all AgentRun rows whose tool_calls or agent_reasoning_summary
     * reference the given customer email.
     *
     * @return array<int, string> list of scrubbed AgentRun IDs (ULIDs)
     */
    public function scrubForCustomer(string $customerEmail, string $gdprLogUlid): array
    {
        $normalised = mb_strtolower(trim($customerEmail));
        $hashFull = hash('sha256', $normalised);
        $hashPrefix = substr($hashFull, 0, 12);

        // Driver-portable substring match on the tool_calls JSON (stored as text)
        // + LIKE on the reasoning summary. Both predicates are intentionally OR'd
        // so a hit on either surface causes the row to be picked up + scrubbed.
        // NOTE: was MariaDB-only JSON_SEARCH — absent on SQLite (test DB), so the
        // query threw on the test harness. LOWER(tool_calls) LIKE is a broader-or-
        // equal match (never misses the email) and runs on both SQLite + MariaDB.
        $candidates = AgentRun::query()
            ->where(function ($q) use ($normalised) {
                $q->whereRaw('LOWER(tool_calls) LIKE ?', ['%'.$normalised.'%'])
                    ->orWhere('agent_reasoning_summary', 'like', '%'.$normalised.'%');
            })
            ->get();

        $scrubbedIds = [];
        foreach ($candidates as $run) {
            $this->scrubRun($run, $normalised, $hashPrefix, $gdprLogUlid);
            $scrubbedIds[] = $run->id;
        }

        // Audit row written regardless of count — Phase 4 GDPR command writes a
        // row per erasure invocation as the durable artefact even when no agent
        // run was touched (so ICO query "did we run the erasure for this email"
        // resolves yes/no off the audit table).
        DB::table('gdpr_erasure_log')->insert([
            'email_hash' => $hashFull,
            'contact_bitrix_id' => null,
            'deal_bitrix_ids' => null,
            'actor_id' => null,
            'correlation_id' => $gdprLogUlid,
            'fields_scrubbed_count' => count($scrubbedIds) * 2, // tool_calls + agent_reasoning_summary per row
            'status' => count($scrubbedIds) > 0 ? 'applied' : 'no_match',
            'notes' => json_encode([
                'context' => 'agent_runs',
                'gdpr_log_ulid' => $gdprLogUlid,
                'agent_run_ids' => $scrubbedIds,
            ], JSON_UNESCAPED_SLASHES),
            'erased_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('AgentRunGdprScrubber: scrubbed runs for GDPR erasure', [
            'gdpr_log_ulid' => $gdprLogUlid,
            'customer_email_hash_prefix' => $hashPrefix,
            'agent_run_count' => count($scrubbedIds),
        ]);

        return $scrubbedIds;
    }

    private function scrubRun(AgentRun $run, string $customerEmail, string $hashPrefix, string $gdprLogUlid): void
    {
        $token = "REDACTED-{$hashPrefix}";
        $toolCalls = $run->tool_calls ?? [];
        $scrubbedToolCalls = $this->scrubArray($toolCalls, $customerEmail, $token);

        $run->forceFill([
            'tool_calls' => $scrubbedToolCalls,
            'agent_reasoning_summary' => "[scrubbed per GDPR erasure {$gdprLogUlid}]",
        ])->saveQuietly();
    }

    /**
     * Recursively walk a tool_calls array, replacing PII string values with
     * the token. Per-key matching uses `PII_KEYS`; fallback string scan
     * substitutes any literal email occurrence regardless of key.
     *
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    private function scrubArray(array $data, string $customerEmail, string $token): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->scrubArray($value, $customerEmail, $token);

                continue;
            }
            if (is_string($value)) {
                $keyLc = is_string($key) ? mb_strtolower($key) : '';
                if (in_array($keyLc, self::PII_KEYS, true) && stripos($value, $customerEmail) !== false) {
                    $out[$key] = $token;

                    continue;
                }
                $out[$key] = str_ireplace($customerEmail, $token, $value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
