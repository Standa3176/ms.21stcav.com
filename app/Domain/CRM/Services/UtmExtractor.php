<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use Illuminate\Support\Collection;

/**
 * Phase 4 Plan 03 — D-03 + D-04 UTM capture.
 *
 * Parses the 6 `_ms_utm_*` meta_data entries Woo persists on orders (via the
 * WP-side hidden checkout inputs, D-01) and customer profiles into the 6
 * UF_CRM_WOO_* Bitrix custom fields.
 *
 * Missing keys default to `''` (never null) — Bitrix rejects null for
 * string user_type fields with a 400 "bad field" error.
 *
 * T-04-03-01 mitigation: the map is hardcoded. A malicious checkout payload
 * cannot inject arbitrary UF_CRM_* fields because only the 6 known keys
 * traverse to the output array.
 */
final class UtmExtractor
{
    /** @var array<string, string>  Woo meta key => Bitrix custom field name */
    private const META_KEYS = [
        '_ms_utm_source'   => 'UF_CRM_WOO_UTM_SOURCE',
        '_ms_utm_medium'   => 'UF_CRM_WOO_UTM_MEDIUM',
        '_ms_utm_campaign' => 'UF_CRM_WOO_UTM_CAMPAIGN',
        '_ms_utm_term'     => 'UF_CRM_WOO_UTM_TERM',
        '_ms_utm_content'  => 'UF_CRM_WOO_UTM_CONTENT',
        '_ms_utm_ga_cid'   => 'UF_CRM_WOO_GA_CID',
    ];

    /**
     * @param  array<string, mixed>  $payload  Woo order JSON (with meta_data[])
     * @return array<string, string>  UF_CRM_WOO_* => value (empty string if absent)
     */
    public function fromOrderPayload(array $payload): array
    {
        return $this->emit($this->indexMetaByKey($payload['meta_data'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $payload  Woo customer JSON (with meta_data[])
     * @return array<string, string>  UF_CRM_WOO_* => value
     */
    public function fromCustomerPayload(array $payload): array
    {
        return $this->emit($this->indexMetaByKey($payload['meta_data'] ?? []));
    }

    /**
     * @param  array<int, array<string, mixed>>|mixed  $metaData
     */
    private function indexMetaByKey(mixed $metaData): Collection
    {
        if (! is_array($metaData)) {
            return collect();
        }

        return collect($metaData)
            ->filter(fn ($m) => is_array($m))
            ->keyBy(fn ($m) => (string) ($m['key'] ?? ''))
            ->map(fn ($m) => (string) ($m['value'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private function emit(Collection $metaByKey): array
    {
        $out = [];
        foreach (self::META_KEYS as $wooKey => $bitrixField) {
            $out[$bitrixField] = (string) ($metaByKey->get($wooKey) ?? '');
        }

        return $out;
    }
}
