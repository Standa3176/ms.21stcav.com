<?php

use App\Domain\Webhooks\Http\Controllers\WooWebhookController;
use App\Domain\Webhooks\Http\Middleware\VerifyWooHmacSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under 'api' middleware group (no session, no CSRF).
| VerifyWooHmacSignature is registered FIRST on the /webhooks/woo/* group so that
| the raw body is available when hash_hmac computes the expected signature
| (Pitfall A — middleware ordering is load-bearing).
*/

Route::prefix('webhooks/woo')
    ->middleware([VerifyWooHmacSignature::class])
    ->group(function () {
        Route::post('order', [WooWebhookController::class, 'order'])->name('webhooks.woo.order');
        Route::post('customer', [WooWebhookController::class, 'customer'])->name('webhooks.woo.customer');
        // Phase 4 adds more topics (product.updated, etc.)
    });
