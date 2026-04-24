<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 7 Plan 03 — signed download endpoint for queued CSV exports (D-06).
 *
 * QueuedCsvExportJob writes to storage/app/exports/{filename} and emails the
 * operator a URL::temporarySignedRoute pointing at this controller (7-day
 * expiry per Threat T-07-03-05 mitigation).
 *
 * Auth stack:
 *   - Route-level `auth` middleware (registered in routes/web.php)
 *   - Signed-URL middleware (signature invalidates after 7 days)
 *   - Filename whitelist (strict basename match — no path traversal)
 *
 * The filename is the opaque CsvExportWriter::filename format — it does NOT
 * contain SKU/PII in the URL — so leakage of the URL (e.g. from a browser
 * history dump) is bounded to the 7-day expiry window.
 */
final class ExportDownloadController extends Controller
{
    public function download(Request $request): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Signed URL is invalid or has expired.');

        $file = (string) $request->query('file', '');
        // Reject any path traversal. basename() strips / and \ components but
        // we also defence-in-depth reject empty + suspicious strings.
        $file = basename($file);
        abort_if($file === '' || $file === '.' || $file === '..', 400, 'Invalid filename.');

        $path = storage_path('app/exports/'.$file);
        abort_unless(File::exists($path), 404, 'Export file not found (may have been pruned).');

        return response()->download($path, $file, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
