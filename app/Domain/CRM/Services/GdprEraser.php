<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Models\GdprErasureLogEntry;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Context;

/**
 * Phase 4 Plan 05 Task 2 — GDPR right-to-erasure service (CRM-13).
 *
 * Scrub-in-place semantics (NOT hard delete):
 *   - Contact: 18 PII fields redacted with REDACTED-{hash12} tokens OR
 *     emptied arrays (PHONE / EMAIL / WEB / IM) OR nulled (BIRTHDATE /
 *     PHOTO). ADDRESS_COUNTRY is deliberately preserved per UK GDPR Art.
 *     4 (country alone is not PII).
 *   - Deal: 4 PII-bearing fields (TITLE, COMMENTS, SOURCE_DESCRIPTION,
 *     ADDITIONAL_INFO). Financial fields PRESERVED (OPPORTUNITY,
 *     UF_CRM_WOO_ORDER_ID, STAGE_ID, CATEGORY_ID, BEGINDATE, CLOSEDATE,
 *     CURRENCY_ID, COMPANY_ID, CONTACT_ID) per HMRC retention.
 *   - Company: NEVER scrubbed (legal entity, not personal data per UK GDPR).
 *
 * Every erasure writes ONE row to gdpr_erasure_log (indefinite retention —
 * never pruned) AND ONE row to activity_log via Auditor::record('gdpr_erasure').
 * subject_email is stored PLAINTEXT in activity_log because UK ICO queries
 * typically reference customers by email (the audit is admin-only; the
 * broader PII surface is already scrubbed).
 */
final class GdprEraser
{
    /**
     * 18 Contact PII fields — 'REDACTED' string values are post-substituted
     * with REDACTED-{hash12} tokens at call time so ops can cross-reference
     * without storing the email. ADDRESS_COUNTRY deliberately preserved.
     */
    private const CONTACT_SCRUB_FIELDS = [
        'NAME' => 'REDACTED',
        'LAST_NAME' => 'REDACTED',
        'SECOND_NAME' => 'REDACTED',
        'PHONE' => [],
        'EMAIL' => [],
        'WEB' => [],
        'IM' => [],
        'ADDRESS' => 'REDACTED',
        'ADDRESS_2' => 'REDACTED',
        'ADDRESS_CITY' => 'REDACTED',
        'ADDRESS_POSTAL_CODE' => 'REDACTED',
        'ADDRESS_REGION' => 'REDACTED',
        'ADDRESS_PROVINCE' => 'REDACTED',
        'POST' => 'REDACTED',
        'BIRTHDATE' => null,
        'COMMENTS' => 'REDACTED',
        'SOURCE_DESCRIPTION' => 'REDACTED',
        'PHOTO' => null,
    ];

    /**
     * Deal PII fields. TITLE's placeholder {UF_CRM_WOO_ORDER_ID} is replaced
     * at call time with the per-deal order number.
     *
     * PRESERVED (NOT in this list):
     *   OPPORTUNITY, UF_CRM_WOO_ORDER_ID, STAGE_ID, CATEGORY_ID,
     *   BEGINDATE, CLOSEDATE, CURRENCY_ID, COMPANY_ID, CONTACT_ID,
     *   UF_CRM_WOO_UTM_* (UTM attribution — not PII per GDPR guidance).
     */
    private const DEAL_SCRUB_FIELDS_TEMPLATE = [
        'TITLE' => 'Order #{UF_CRM_WOO_ORDER_ID}',
        'COMMENTS' => 'REDACTED — order notes removed per GDPR erasure',
        'SOURCE_DESCRIPTION' => 'REDACTED',
        'ADDITIONAL_INFO' => 'REDACTED',
    ];

    public function __construct(
        private readonly BitrixClient $client,
        private readonly Auditor $auditor,
    ) {
    }

    /**
     * @return array{contact_id: ?string, deal_ids: array<int, string>, fields_scrubbed_count: int}
     */
    public function eraseByEmail(string $email, ?int $actorId = null, ?string $correlationId = null): array
    {
        $correlationId ??= (string) Context::get('correlation_id');
        $normalised = mb_strtolower(trim($email));
        $hash = hash('sha256', $normalised);
        $token = 'REDACTED-'.substr($hash, 0, 12);

        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_CONTACT)
            ->where('email_hash', $hash)
            ->first();

        if ($map === null) {
            GdprErasureLogEntry::create([
                'email_hash' => $hash,
                'status' => GdprErasureLogEntry::STATUS_NO_MATCH,
                'actor_id' => $actorId,
                'correlation_id' => $correlationId,
                'fields_scrubbed_count' => 0,
                'notes' => 'No BitrixEntityMap row found for email_hash',
            ]);

            $this->auditor->record('gdpr_erasure', [
                'actor_id' => $actorId,
                'subject_email' => $normalised,
                'contact_id' => null,
                'deal_ids' => [],
                'entity_map_rows_updated' => 0,
                'correlation_id' => $correlationId,
                'status' => 'no_match',
                'retention_note' => 'No Bitrix contact found; no scrub performed. UK GDPR compliance — nothing to erase.',
            ]);

            return ['contact_id' => null, 'deal_ids' => [], 'fields_scrubbed_count' => 0];
        }

        $contactId = (string) $map->bitrix_id;

        // Build + send Contact scrub payload. Replace 'REDACTED' string markers
        // with the per-subject token; empty arrays + nulls pass through
        // verbatim so Bitrix clears multi-value comm fields / nulls photo.
        $contactPayload = [];
        foreach (self::CONTACT_SCRUB_FIELDS as $field => $value) {
            $contactPayload[$field] = $value === 'REDACTED' ? $token : $value;
        }
        $this->client->contactUpdate($contactId, $contactPayload, $correlationId);

        // Resolve linked Deals via Bitrix side (CONTACT_ID filter) — covers
        // the case where a Deal was created through adopt-legacy or manual
        // attach but no BitrixEntityMap row exists for it.
        $dealsFromBitrix = $this->client->dealList(
            ['CONTACT_ID' => $contactId],
            ['ID', 'UF_CRM_WOO_ORDER_ID'],
            0,
            $correlationId,
        );

        $dealIds = [];
        foreach ($dealsFromBitrix as $deal) {
            $dealId = (string) ($deal['ID'] ?? '');
            if ($dealId === '') {
                continue;
            }
            $wooOrderId = (string) ($deal['UF_CRM_WOO_ORDER_ID'] ?? '?');

            $dealScrub = self::DEAL_SCRUB_FIELDS_TEMPLATE;
            $dealScrub['TITLE'] = str_replace('{UF_CRM_WOO_ORDER_ID}', $wooOrderId, (string) $dealScrub['TITLE']);
            $this->client->dealUpdate($dealId, $dealScrub, $correlationId);

            $dealIds[] = $dealId;
        }

        $fieldsScrubbed = count(self::CONTACT_SCRUB_FIELDS)
            + count($dealIds) * count(self::DEAL_SCRUB_FIELDS_TEMPLATE);

        GdprErasureLogEntry::create([
            'email_hash' => $hash,
            'contact_bitrix_id' => $contactId,
            'deal_bitrix_ids' => $dealIds,
            'actor_id' => $actorId,
            'correlation_id' => $correlationId,
            'fields_scrubbed_count' => $fieldsScrubbed,
            'status' => GdprErasureLogEntry::STATUS_APPLIED,
        ]);

        $this->auditor->record('gdpr_erasure', [
            'actor_id' => $actorId,
            'subject_email' => $normalised,                 // PLAINTEXT — permitted per RESEARCH decision
            'contact_id' => $contactId,
            'deal_ids' => $dealIds,
            'entity_map_rows_updated' => 1,
            'correlation_id' => $correlationId,
            'status' => 'applied',
            'retention_note' => 'Financial records preserved per HMRC retention; PII scrubbed per UK GDPR Article 17(3)(b).',
        ]);

        return [
            'contact_id' => $contactId,
            'deal_ids' => $dealIds,
            'fields_scrubbed_count' => $fieldsScrubbed,
        ];
    }
}
