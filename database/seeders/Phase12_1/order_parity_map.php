<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12.1 — Bitrix order-push parity map (D-08)
|--------------------------------------------------------------------------
| Edited via PR only — this file IS the prod Bitrix order-push config (D-08).
| Applied with `php artisan bitrix:seed-order-parity` (dry-run) then `--apply`.
|
| Mapped-but-blank plugin fields (SOURCE_DESCRIPTION, PARENT_ID_187, ~30
| UF_CRM_*) are intentionally not reproduced: the plugin sends them empty.
|
| NEVER target UF_CRM_1610623973323 ("Actual Margin") — read by another system.
*/

return [
    'source' => [
        'evidence' => '12.1-PLUGIN-PARITY.md (live WP option wc-bitrix24-integration-settings, captured 2026-10-27)',
        'cost_and_predicted_margin' => 'pending 12.1-05 discovery (D-07): run bitrix:discover-order-fields',
        'd09_required_field_decision' => 'pending 12.1-05',
    ],

    'pipeline' => [
        // No CATEGORY_ID in the plugin map => default pipeline (category 0).
        'bitrix_pipeline_id' => '0',
        // Optional: the plugin sends no stage for statuses outside its map, so
        // Bitrix uses the pipeline's first stage.
        'landing_stage_id' => null,
        'assigned_user_id' => '6428',
        'deal_title_template' => 'BS - #{order_number} - ({billing_company})',
        'deal_static_fields' => [
            'TYPE_ID' => 'GOODS',
            'OPENED' => 'Y',
            'SOURCE_ID' => 'RC_GENERATOR',
        ],
        // Resolved in 12.1-05 via bitrix:discover-order-fields (D-07).
        'deal_cost_field' => null,
        'deal_predicted_margin_field' => null,
    ],

    // Woo status => Bitrix default-pipeline STAGE_ID (null = no stage change).
    // The plugin maps failed => NEW, but under D-01 a failed order never
    // creates a deal; an already-mapped deal whose order fails keeps its stage
    // (plugin parity for blank = no change).
    // ywraq-new / ywraq-pending => NEW are live plugin config: they are
    // deal-creating statuses per D-01a.
    'status_stages' => [
        'processing' => '2',
        'on-hold' => '2',
        'ywraq-new' => 'NEW',
        'ywraq-pending' => 'NEW',
        'failed' => null,
        'pending' => null,
        'completed' => null,
        'cancelled' => null,
        'refunded' => null,
    ],

    // The plugin's utm_* source is assumed to be order meta of the same key —
    // 12.1-06 shadow parity confirms; if wrong, change the woo_field here.
    'deal_field_mappings' => [
        ['woo_field' => 'meta:utm_source', 'bitrix_field' => 'UTM_SOURCE', 'transformer' => 'none', 'is_custom' => false],
        ['woo_field' => 'meta:utm_medium', 'bitrix_field' => 'UTM_MEDIUM', 'transformer' => 'none', 'is_custom' => false],
        ['woo_field' => 'meta:utm_campaign', 'bitrix_field' => 'UTM_CAMPAIGN', 'transformer' => 'none', 'is_custom' => false],
        ['woo_field' => 'meta:utm_content', 'bitrix_field' => 'UTM_CONTENT', 'transformer' => 'none', 'is_custom' => false],
        ['woo_field' => 'meta:utm_term', 'bitrix_field' => 'UTM_TERM', 'transformer' => 'none', 'is_custom' => false],
        ['woo_field' => 'meta:wciwtb_streaming', 'bitrix_field' => 'UF_CRM_1609669335792', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'payment_method_title', 'bitrix_field' => 'UF_CRM_1621415435354', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.first_name', 'bitrix_field' => 'UF_CRM_1624008413173', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.last_name', 'bitrix_field' => 'UF_CRM_1624008428473', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.company', 'bitrix_field' => 'UF_CRM_1624008462747', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.address_1', 'bitrix_field' => 'UF_CRM_1624008485495', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.address_2', 'bitrix_field' => 'UF_CRM_1624008500705', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.city', 'bitrix_field' => 'UF_CRM_1624008515079', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.state', 'bitrix_field' => 'UF_CRM_1624008533075', 'transformer' => 'none', 'is_custom' => true],
        ['woo_field' => 'shipping.postcode', 'bitrix_field' => 'UF_CRM_1624008544289', 'transformer' => 'none', 'is_custom' => true],
    ],

    // Prod has 0 deal mappings so this is a no-op there; it guarantees the map
    // is exactly the plugin's.
    'prune_unlisted_deal_mappings' => true,
];
