# MeetingStore UTM Capture — WordPress deployment

Deliverable for Phase 7 cutover. The Laravel side (Phase 4 Plan 03 `UtmExtractor`)
already parses `meta_data[] → _ms_utm_*` keys off the `order.created` webhook —
this directory contains the two WP-side artefacts that feed those keys.

## Files

- `ms-utm-capture.js` — ~30-line JS snippet. Reads `utm_*` query params + the
  `_ga` cookie on every page, writes to `ms_utm_first_touch` cookie
  (TTL 30 days, first-touch attribution per D-02), injects hidden input
  fields (`ms_utm_{key}`) into the WooCommerce checkout form.
- `ms-utm-persist.php` — WordPress mu-plugin hook on
  `woocommerce_checkout_create_order`. Reads the hidden inputs from the
  POST body and persists them as `_ms_utm_*` order meta keys.

## Deployment options (operator picks ONE)

### Option 1 — mu-plugin (recommended)

1. Upload `ms-utm-capture.js` to `wp-content/plugins/ms-utm/assets/`.
2. Upload `ms-utm-persist.php` to `wp-content/mu-plugins/`.
3. Add a small loader alongside `ms-utm-persist.php` that enqueues the JS
   via `wp_enqueue_script('ms-utm', plugins_url('assets/ms-utm-capture.js',
   __FILE__), [], '1.0.0', true);` — hook on `wp_enqueue_scripts`.

### Option 2 — active theme footer hook

1. Paste the JS contents into the theme's `footer.php` between
   `<script type="text/javascript">` tags, OR enqueue via
   `functions.php` per Option 1 step 3.
2. Copy `ms-utm-persist.php` to `wp-content/mu-plugins/` (required —
   the hook MUST run as a mu-plugin, not inside a theme, to survive
   theme switches).

### Option 3 — Google Tag Manager

1. Create a custom HTML tag in GTM containing the JS from
   `ms-utm-capture.js`; fire on All Pages.
2. Copy `ms-utm-persist.php` to `wp-content/mu-plugins/`.

## Contract

The JavaScript snippet injects hidden form fields named
`ms_utm_{source|medium|campaign|term|content|_ga}`. The PHP hook
saves those POST values to Woo order meta keys
`_ms_utm_{source|medium|campaign|term|content|_ga}`. Laravel's
Phase 4 `UtmExtractor` service reads exactly those meta keys from
the `order.created` webhook payload.

Cookie: `ms_utm_first_touch` — JSON-encoded object with keys
`utm_source, utm_medium, utm_campaign, utm_term, utm_content, _ga`.
TTL 30 days. First-touch only (D-02).

## Bitrix-side custom fields (must exist before deploy)

Run `php artisan bitrix:bootstrap` on the Laravel side BEFORE
enabling this JS. The bootstrap creates the 6 UTM custom fields plus
the GA CID field on BOTH Deal and Contact entities:

| Bitrix field             | Woo meta key        |
| ------------------------ | ------------------- |
| `UF_CRM_WOO_UTM_SOURCE`   | `_ms_utm_source`   |
| `UF_CRM_WOO_UTM_MEDIUM`   | `_ms_utm_medium`   |
| `UF_CRM_WOO_UTM_CAMPAIGN` | `_ms_utm_campaign` |
| `UF_CRM_WOO_UTM_TERM`     | `_ms_utm_term`     |
| `UF_CRM_WOO_UTM_CONTENT`  | `_ms_utm_content`  |
| `UF_CRM_WOO_GA_CID`       | `_ms_utm__ga`      |

## Deferred (Phase 8+)

`gclid` / `fbclid` offline-conversion capture is explicitly OUT of scope
(D-03). When offline-conversion upload lands in a future phase, extend
`ms-utm-capture.js` with two more URL params + extend this README.

## Testing checklist after deploy

1. Visit `meetingstore.co.uk/?utm_source=test&utm_medium=cpc&utm_campaign=qa`
   in an incognito window. Open DevTools → Application → Cookies; confirm
   `ms_utm_first_touch` exists with the JSON payload.
2. Navigate to `/checkout`. Open DevTools → Elements; search the form for
   `input[name^="ms_utm_"]`; confirm 6 hidden inputs are present.
3. Place a test order. In the Woo admin, open the order's detail page and
   scroll to Order Meta — confirm `_ms_utm_source`, `_ms_utm_medium`, etc.
   are populated.
4. On the Laravel side (admin panel), open `/admin/crm-push-logs` → filter
   by correlation_id matching the test order's webhook; confirm the
   `UF_CRM_WOO_UTM_*` fields appear in the logged request_body.
