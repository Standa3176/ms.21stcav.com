<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Phase 7 Plan 03 — signed download route for queued CSV exports (D-06).
// QueuedCsvExportJob generates a URL::temporarySignedRoute to this name with
// 7-day expiry. 'signed' middleware + 'auth' together enforce T-07-03-05.
Route::middleware(['auth', 'signed'])
    ->get('/exports/download', [\App\Http\Controllers\Dashboard\ExportDownloadController::class, 'download'])
    ->name('exports.download');
