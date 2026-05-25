<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\PricingOpsReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the Pricing Operations dashboard tiles/panels.
 *
 * GET /pricing-operations/export/{bucket} (auth-gated). Streams the FULL rows
 * for one bucket — same data + ordering as the on-page modal. Read-only;
 * never touches Woo. RBAC mirrors the page (CompetitorPrice viewAny: admin +
 * pricing_manager + sales).
 */
final class PricingOpsExportController extends Controller
{
    public function __invoke(Request $request, string $bucket, PricingOpsReport $report): StreamedResponse
    {
        abort_unless(in_array($bucket, PricingOpsReport::BUCKETS, true), 404);
        abort_unless($request->user()?->can('viewAny', CompetitorPrice::class) ?? false, 403);

        $csv = $report->csv($bucket);

        return response()->streamDownload(function () use ($csv): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $csv['header']);
            foreach ($csv['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $csv['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
