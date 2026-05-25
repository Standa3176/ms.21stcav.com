<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\ExportDownloadController;
use App\Http\Controllers\PricingOpsExportController;
use App\Http\Controllers\ProductPreviewController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Phase 7 Plan 03 — signed download route for queued CSV exports (D-06).
// QueuedCsvExportJob generates a URL::temporarySignedRoute to this name with
// 7-day expiry. 'signed' middleware + 'auth' together enforce T-07-03-05.
Route::middleware(['auth', 'signed'])
    ->get('/exports/download', [ExportDownloadController::class, 'download'])
    ->name('exports.download');

// Draft product preview — renders a local draft as a customer-facing product
// page for sign-off before any Woo push. Auth-gated (admin panel session).
Route::middleware('auth')
    ->get('/preview/product/{product}', ProductPreviewController::class)
    ->name('preview.product');

// Pricing Operations dashboard — CSV export per tile/panel bucket. Auth-gated;
// the controller authorises against CompetitorPrice viewAny + validates bucket.
Route::middleware('auth')
    ->get('/pricing-operations/export/{bucket}', PricingOpsExportController::class)
    ->name('pricing-ops.export');
