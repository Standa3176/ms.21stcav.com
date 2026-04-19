<?php

declare(strict_types=1);

namespace Database\Seeders\Phase4;

use App\Domain\CRM\Models\CrmFieldMapping;
use Illuminate\Database\Seeder;

/**
 * Phase 4 Plan 03 — CRM-06 default Woo↔Bitrix field mappings.
 *
 * Ported from the legacy itgalaxy plugin's `$setFields` arrays in
 * includes/CrmFields.php (Deal + Contact + Company). 40 rows total:
 *   - 19 Deal mappings (D-03 UTMs + ORDER_ID + OPPORTUNITY + standard UF_CRM_WOO_* fields)
 *   - 15 Contact mappings (name + address + D-04 UTMs + CUSTOMER_ID)
 *   - 6 Company mappings (title + address + VAT)
 *
 * Idempotent via firstOrCreate on (entity_type, woo_field) — safe on every deploy.
 * Admin edits in the Filament UI (Plan 04-04) persist; the seeder only ever
 * creates new rows, never overwrites existing ones.
 */
final class CrmFieldMappingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_merge(
            $this->dealRows(),
            $this->contactRows(),
            $this->companyRows(),
        );

        foreach ($rows as $sortOrder => $row) {
            CrmFieldMapping::firstOrCreate(
                [
                    'entity_type' => $row['entity_type'],
                    'woo_field' => $row['woo_field'],
                ],
                array_merge($row, ['sort_order' => $sortOrder]),
            );
        }
    }

    /**
     * @return array<int, array{entity_type:string, woo_field:string, bitrix_field:string, is_custom:bool, transformer:?string}>
     */
    private function dealRows(): array
    {
        $e = CrmFieldMapping::ENTITY_DEAL;

        return [
            ['entity_type' => $e, 'woo_field' => 'id',                  'bitrix_field' => 'UF_CRM_WOO_ORDER_ID',             'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'number',              'bitrix_field' => 'UF_CRM_WOO_ORDER_NUMBER',         'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'total',               'bitrix_field' => 'OPPORTUNITY',                     'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'currency',            'bitrix_field' => 'CURRENCY_ID',                     'is_custom' => false, 'transformer' => 'uppercase'],
            ['entity_type' => $e, 'woo_field' => 'date_created',        'bitrix_field' => 'BEGINDATE',                       'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'customer_note',       'bitrix_field' => 'COMMENTS',                        'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.first_name',  'bitrix_field' => 'UF_CRM_WOO_BILLING_FIRST_NAME',   'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.last_name',   'bitrix_field' => 'UF_CRM_WOO_BILLING_LAST_NAME',    'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.company',     'bitrix_field' => 'UF_CRM_WOO_BILLING_COMPANY',      'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.email',       'bitrix_field' => 'UF_CRM_WOO_BILLING_EMAIL',        'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.phone',       'bitrix_field' => 'UF_CRM_WOO_BILLING_PHONE',        'is_custom' => true,  'transformer' => 'phone_e164'],
            ['entity_type' => $e, 'woo_field' => 'line_items',          'bitrix_field' => 'UF_CRM_WOO_LINE_ITEMS_SUMMARY',   'is_custom' => true,  'transformer' => 'join_line_items'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_source',      'bitrix_field' => 'UF_CRM_WOO_UTM_SOURCE',           'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_medium',      'bitrix_field' => 'UF_CRM_WOO_UTM_MEDIUM',           'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_campaign',    'bitrix_field' => 'UF_CRM_WOO_UTM_CAMPAIGN',         'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_term',        'bitrix_field' => 'UF_CRM_WOO_UTM_TERM',             'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_content',     'bitrix_field' => 'UF_CRM_WOO_UTM_CONTENT',          'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_ga_cid',      'bitrix_field' => 'UF_CRM_WOO_GA_CID',               'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'payment_method_title','bitrix_field' => 'UF_CRM_WOO_PAYMENT_METHOD',       'is_custom' => true,  'transformer' => 'none'],
        ];
    }

    /**
     * @return array<int, array{entity_type:string, woo_field:string, bitrix_field:string, is_custom:bool, transformer:?string}>
     */
    private function contactRows(): array
    {
        $e = CrmFieldMapping::ENTITY_CONTACT;

        return [
            ['entity_type' => $e, 'woo_field' => 'id',                  'bitrix_field' => 'UF_CRM_WOO_CUSTOMER_ID',   'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'first_name',          'bitrix_field' => 'NAME',                     'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'last_name',           'bitrix_field' => 'LAST_NAME',                'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'email',               'bitrix_field' => 'EMAIL',                    'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.phone',       'bitrix_field' => 'PHONE',                    'is_custom' => false, 'transformer' => 'phone_e164'],
            ['entity_type' => $e, 'woo_field' => 'billing.address_1',   'bitrix_field' => 'ADDRESS',                  'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.address_2',   'bitrix_field' => 'ADDRESS_2',                'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.city',        'bitrix_field' => 'ADDRESS_CITY',             'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.postcode',    'bitrix_field' => 'ADDRESS_POSTAL_CODE',      'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.country',     'bitrix_field' => 'ADDRESS_COUNTRY',          'is_custom' => false, 'transformer' => 'uppercase'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_source',      'bitrix_field' => 'UF_CRM_WOO_UTM_SOURCE',    'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_medium',      'bitrix_field' => 'UF_CRM_WOO_UTM_MEDIUM',    'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_campaign',    'bitrix_field' => 'UF_CRM_WOO_UTM_CAMPAIGN',  'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_content',     'bitrix_field' => 'UF_CRM_WOO_UTM_CONTENT',   'is_custom' => true,  'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => '_ms_utm_ga_cid',      'bitrix_field' => 'UF_CRM_WOO_GA_CID',        'is_custom' => true,  'transformer' => 'none'],
        ];
    }

    /**
     * @return array<int, array{entity_type:string, woo_field:string, bitrix_field:string, is_custom:bool, transformer:?string}>
     */
    private function companyRows(): array
    {
        $e = CrmFieldMapping::ENTITY_COMPANY;

        return [
            ['entity_type' => $e, 'woo_field' => 'billing.company',     'bitrix_field' => 'TITLE',                 'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.address_1',   'bitrix_field' => 'ADDRESS',               'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.city',        'bitrix_field' => 'ADDRESS_CITY',          'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.postcode',    'bitrix_field' => 'ADDRESS_POSTAL_CODE',   'is_custom' => false, 'transformer' => 'none'],
            ['entity_type' => $e, 'woo_field' => 'billing.country',     'bitrix_field' => 'ADDRESS_COUNTRY',       'is_custom' => false, 'transformer' => 'uppercase'],
            ['entity_type' => $e, 'woo_field' => 'billing.vat',         'bitrix_field' => 'UF_CRM_COMPANY_VAT',    'is_custom' => true,  'transformer' => 'uppercase'],
        ];
    }
}
