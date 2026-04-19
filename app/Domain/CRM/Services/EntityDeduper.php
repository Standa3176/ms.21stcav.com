<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\BitrixEntityMap;
use App\Foundation\Audit\Services\Auditor;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * CRM-04 + CRM-05 — find-or-create cascade for Contact / Company / Deal.
 *
 * Contact cascade (4 steps):
 *   1. BitrixEntityMap lookup on (entity_type='contact', woo_id)
 *   2. crm.duplicate.findbycomm by PHONE (when payload has one)
 *   3. crm.duplicate.findbycomm by EMAIL (when payload has one)
 *   4. crm.contact.add + record in BitrixEntityMap
 *
 * Company dedup: woo_id=0 sentinel + sha256(title+postcode) as last_payload_hash.
 *
 * Deal find-only (never creates): BitrixEntityMap map → crm.deal.list filter on
 * UF_CRM_WOO_ORDER_ID. Multi-match logs a duplicate_detected audit row + adopts
 * the lowest Bitrix ID (oldest deal in Bitrix wins).
 *
 * Every decision step writes one integration_events row with endpoint shaped
 * `crm.deduper.{contact|company|deal}` for Plan 04-04 admin-log visibility.
 */
final class EntityDeduper
{
    public function __construct(
        private readonly BitrixClient $client,
        private readonly IntegrationLogger $logger,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════════
    // Contact
    // ══════════════════════════════════════════════════════════════════════

    public function findOrCreateContact(int $wooCustomerId, array $payload, ?string $correlationId = null): string
    {
        $correlationId ??= (string) Context::get('correlation_id');

        // 1. Map lookup
        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_CONTACT)
            ->where('woo_id', $wooCustomerId)
            ->first();
        if ($map !== null) {
            $this->client->contactUpdate($map->bitrix_id, $payload, $correlationId);
            $map->update([
                'last_pushed_at' => now(),
                'last_payload_hash' => hash('sha256', (string) json_encode($payload)),
                'last_correlation_id' => $correlationId,
            ]);
            $this->logDecision('contact', 'map_hit', [
                'bitrix_id' => $map->bitrix_id,
                'woo_id' => $wooCustomerId,
            ], $correlationId);

            return $map->bitrix_id;
        }

        // 2. Phone dedup
        $phone = $this->normalisePhone($this->extractPhone($payload));
        if ($phone !== null) {
            $found = $this->client->duplicateFindByComm('PHONE', 'CONTACT', [$phone], $correlationId);
            $adopted = $this->adoptContactIfFound($found, $wooCustomerId, $payload, $correlationId);
            if ($adopted !== null) {
                $this->logDecision('contact', 'phone_dupe_hit', [
                    'bitrix_id' => $adopted,
                    'woo_id' => $wooCustomerId,
                ], $correlationId);

                return $adopted;
            }
        } else {
            $this->logDecision('contact', 'phone_skipped', [
                'reason' => 'empty or non-E164',
            ], $correlationId);
        }

        // 3. Email dedup
        $email = $this->extractEmailNormalised($payload);
        if ($email !== '') {
            $found = $this->client->duplicateFindByComm('EMAIL', 'CONTACT', [$email], $correlationId);
            $adopted = $this->adoptContactIfFound($found, $wooCustomerId, $payload, $correlationId);
            if ($adopted !== null) {
                $this->logDecision('contact', 'email_dupe_hit', [
                    'bitrix_id' => $adopted,
                    'woo_id' => $wooCustomerId,
                    'email_hash' => hash('sha256', $email),
                ], $correlationId);

                return $adopted;
            }
        }

        // 4. Create + record
        $bitrixId = $this->client->contactAdd($payload, $correlationId);
        BitrixEntityMap::create([
            'entity_type' => BitrixEntityMap::ENTITY_CONTACT,
            'woo_id' => $wooCustomerId,
            'bitrix_id' => $bitrixId,
            'email_hash' => $email !== '' ? hash('sha256', $email) : null,
            'last_payload_hash' => hash('sha256', (string) json_encode($payload)),
            'last_correlation_id' => $correlationId,
            'created_via' => BitrixEntityMap::VIA_PUSH,
        ]);

        $this->logDecision('contact', 'created', [
            'bitrix_id' => $bitrixId,
            'woo_id' => $wooCustomerId,
        ], $correlationId);

        return $bitrixId;
    }

    private function adoptContactIfFound(array $dupeResult, int $wooCustomerId, array $payload, ?string $correlationId): ?string
    {
        $ids = $dupeResult['CONTACT'] ?? $dupeResult['result']['CONTACT'] ?? [];
        if (empty($ids)) {
            return null;
        }

        $bitrixId = (string) $ids[0];           // lowest ID = oldest Bitrix contact wins
        $this->client->contactUpdate($bitrixId, $payload, $correlationId);

        $email = $this->extractEmailNormalised($payload);
        BitrixEntityMap::updateOrCreate(
            [
                'entity_type' => BitrixEntityMap::ENTITY_CONTACT,
                'woo_id' => $wooCustomerId,
            ],
            [
                'bitrix_id' => $bitrixId,
                'email_hash' => $email !== '' ? hash('sha256', $email) : null,
                'last_payload_hash' => hash('sha256', (string) json_encode($payload)),
                'last_correlation_id' => $correlationId,
                'created_via' => BitrixEntityMap::VIA_PUSH,
            ],
        );

        return $bitrixId;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Company
    // ══════════════════════════════════════════════════════════════════════

    public function findOrCreateCompany(string $title, ?string $postcode, array $payload, ?string $correlationId = null): string
    {
        $correlationId ??= (string) Context::get('correlation_id');
        $dedupKey = $this->companyDedupKey($title, $postcode);

        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_COMPANY)
            ->where('last_payload_hash', $dedupKey)
            ->first();
        if ($map !== null) {
            $this->client->companyUpdate($map->bitrix_id, $payload, $correlationId);
            $map->update([
                'last_pushed_at' => now(),
                'last_correlation_id' => $correlationId,
            ]);
            $this->logDecision('company', 'map_hit', [
                'bitrix_id' => $map->bitrix_id,
            ], $correlationId);

            return $map->bitrix_id;
        }

        $bitrixId = $this->client->companyAdd($payload, $correlationId);
        BitrixEntityMap::create([
            'entity_type' => BitrixEntityMap::ENTITY_COMPANY,
            'woo_id' => 0,                        // sentinel — companies lack a Woo primary key
            'bitrix_id' => $bitrixId,
            'last_payload_hash' => $dedupKey,
            'last_correlation_id' => $correlationId,
            'created_via' => BitrixEntityMap::VIA_PUSH,
        ]);

        $this->logDecision('company', 'created', [
            'bitrix_id' => $bitrixId,
            'title' => $title,
            'postcode' => $postcode,
        ], $correlationId);

        return $bitrixId;
    }

    private function companyDedupKey(string $title, ?string $postcode): string
    {
        return hash('sha256', (string) json_encode([
            'title' => mb_strtolower(trim($title)),
            'postcode' => mb_strtolower(trim((string) $postcode)),
        ]));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Deal (find-only)
    // ══════════════════════════════════════════════════════════════════════

    public function findDealByWooOrderId(int $wooOrderId, ?string $correlationId = null): ?string
    {
        $correlationId ??= (string) Context::get('correlation_id');

        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
            ->where('woo_id', $wooOrderId)
            ->first();
        if ($map !== null) {
            $this->logDecision('deal', 'map_hit', [
                'bitrix_id' => $map->bitrix_id,
                'woo_id' => $wooOrderId,
            ], $correlationId);

            return $map->bitrix_id;
        }

        $found = $this->client->dealList(
            ['UF_CRM_WOO_ORDER_ID' => $wooOrderId],
            ['ID'],
            0,
            $correlationId,
        );

        if (empty($found)) {
            $this->logDecision('deal', 'not_found', [
                'woo_id' => $wooOrderId,
            ], $correlationId);

            return null;
        }

        if (count($found) > 1) {
            try {
                app(Auditor::class)->record('bitrix.deal.duplicate_detected', [
                    'woo_order_id' => $wooOrderId,
                    'bitrix_deal_ids' => array_map(static fn ($r) => (string) ($r['ID'] ?? null), $found),
                    'correlation_id' => $correlationId,
                ]);
            } catch (Throwable) {
                // Audit writes never block the dedup decision.
            }
        }

        usort($found, static fn ($a, $b) => (int) ($a['ID'] ?? 0) <=> (int) ($b['ID'] ?? 0));
        $bitrixId = (string) $found[0]['ID'];

        BitrixEntityMap::updateOrCreate(
            [
                'entity_type' => BitrixEntityMap::ENTITY_DEAL,
                'woo_id' => $wooOrderId,
            ],
            [
                'bitrix_id' => $bitrixId,
                'last_correlation_id' => $correlationId,
                'created_via' => BitrixEntityMap::VIA_PUSH,
            ],
        );

        $this->logDecision('deal', 'adopted_from_uf_filter', [
            'bitrix_id' => $bitrixId,
            'woo_id' => $wooOrderId,
            'multi_match_count' => count($found),
        ], $correlationId);

        return $bitrixId;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    /** Bitrix multi-value COMM fields arrive as [['VALUE' => '...'], ...] — pull the first. */
    private function extractPhone(array $payload): ?string
    {
        if (isset($payload['PHONE'])) {
            if (is_array($payload['PHONE'])) {
                $first = $payload['PHONE'][0] ?? null;
                if (is_array($first)) {
                    return $first['VALUE'] ?? null;
                }
                if (is_string($first)) {
                    return $first;
                }
            } elseif (is_string($payload['PHONE'])) {
                return $payload['PHONE'];
            }
        }

        return null;
    }

    private function extractEmailNormalised(array $payload): string
    {
        $raw = null;
        if (isset($payload['EMAIL'])) {
            if (is_array($payload['EMAIL'])) {
                $first = $payload['EMAIL'][0] ?? null;
                if (is_array($first)) {
                    $raw = $first['VALUE'] ?? null;
                } elseif (is_string($first)) {
                    $raw = $first;
                }
            } elseif (is_string($payload['EMAIL'])) {
                $raw = $payload['EMAIL'];
            }
        }

        return mb_strtolower(trim((string) ($raw ?? '')));
    }

    private function normalisePhone(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if ($digits === '' || ! preg_match('/^\+?[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        return str_starts_with($digits, '+') ? $digits : '+'.$digits;
    }

    private function logDecision(string $entity, string $step, array $details, ?string $correlationId): void
    {
        $this->logger->log([
            'channel' => 'bitrix',
            'direction' => 'internal',
            'method' => 'DEDUP',
            'operation' => "crm.deduper.{$entity}",
            'endpoint' => "crm.deduper.{$entity}",
            'request_body' => [],
            'response_body' => array_merge(['step' => $step], $details),
            'http_status' => 200,
            'correlation_id' => $correlationId,
            'status' => 'success',
        ]);
    }
}
