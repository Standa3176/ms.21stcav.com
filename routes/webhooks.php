<?php

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Inbound webhook endpoints (Woo, Bitrix future). Registered in
| bootstrap/app.php under the 'api' middleware group with CSRF excluded
| for the 'webhooks/*' prefix.
|
| HMAC verification middleware is added per-route in Plan 04.
|
| Phase 1 Plan 01 ships this file empty to establish the registration seam.
*/

// Routes added in Plan 04 (VerifyWooHmacSignature + WooWebhookController)
