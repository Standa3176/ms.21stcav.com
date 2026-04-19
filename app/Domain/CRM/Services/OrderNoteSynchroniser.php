<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\BitrixEntityMap;
use App\Foundation\Integration\Services\IntegrationLogger;

/**
 * Phase 4 Plan 03 — D-09 narrow-patch order-note append.
 *
 * On `order.updated`, this service only appends NEW Woo notes to the Deal's
 * COMMENTS field. "New" = note id whose sha256(id|body) hash isn't already in
 * bitrix_entity_map.notes_hash_set. Existing comments (including manually-added
 * Bitrix comments from sales) are preserved — we concat onto the existing
 * string, never overwrite.
 *
 * Re-delivery safety: the same webhook fired twice writes nothing the second
 * time because the hash set already contains every note's signature.
 */
final class OrderNoteSynchroniser
{
    public function __construct(
        private readonly BitrixClient $client,
        private readonly IntegrationLogger $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function appendNewNotes(
        string $dealId,
        array $order,
        BitrixEntityMap $map,
        ?string $correlationId = null,
    ): void {
        $wooNotes = $order['notes'] ?? [];
        if (! is_array($wooNotes) || empty($wooNotes)) {
            return;
        }

        $pastHashes = (array) ($map->notes_hash_set ?? []);
        $newNotes = [];
        $newHashes = [];

        foreach ($wooNotes as $note) {
            if (! is_array($note)) {
                continue;
            }
            $body = (string) ($note['note'] ?? '');
            $noteId = (string) ($note['id'] ?? '');
            if ($body === '') {
                continue;
            }
            $hash = hash('sha256', $noteId.'|'.$body);
            if (in_array($hash, $pastHashes, true)) {
                continue;
            }
            $newNotes[] = $body;
            $newHashes[] = $hash;
        }

        if (empty($newNotes)) {
            return;
        }

        // Read-before-write to preserve existing comments (legacy parity).
        $current = (string) ($this->client->dealGet($dealId, $correlationId)['COMMENTS'] ?? '');
        $appendBlock = implode("\n\n", $newNotes);
        $concatenated = $current === ''
            ? $appendBlock
            : rtrim($current)."\n\n".$appendBlock;

        $this->client->dealUpdate($dealId, ['COMMENTS' => $concatenated], $correlationId);

        $map->update([
            'notes_hash_set' => array_values(array_unique(array_merge($pastHashes, $newHashes))),
            'last_pushed_at' => now(),
            'last_correlation_id' => $correlationId ?? $map->last_correlation_id,
        ]);

        $this->logger->log([
            'channel' => 'bitrix',
            'direction' => 'internal',
            'method' => 'NOTES',
            'operation' => 'crm.deal.notes.sync',
            'endpoint' => 'crm.deal.notes.sync',
            'request_body' => ['deal_id' => $dealId, 'new_count' => count($newNotes)],
            'response_body' => ['appended' => count($newNotes)],
            'http_status' => 200,
            'correlation_id' => $correlationId,
            'status' => 'success',
        ]);
    }
}
