<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\PricingOpsReport;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / XLSX export for the Pricing Operations dashboard tiles/panels.
 *
 * GET /pricing-operations/export/{bucket}?format=csv|xlsx (auth-gated). Returns
 * the FULL rows for one bucket — same data + ordering as the on-page modal.
 * Read-only; never touches Woo. RBAC mirrors the page (CompetitorPrice viewAny:
 * admin + pricing_manager + sales).
 */
final class PricingOpsExportController extends Controller
{
    public function __invoke(Request $request, string $bucket, PricingOpsReport $report): StreamedResponse|BinaryFileResponse
    {
        abort_unless(in_array($bucket, PricingOpsReport::BUCKETS, true), 404);
        abort_unless($request->user()?->can('viewAny', CompetitorPrice::class) ?? false, 403);

        $data = $report->csv($bucket);
        $format = strtolower((string) $request->query('format', 'csv'));
        $base = (string) preg_replace('/\.csv$/', '', $data['filename']);

        if ($format === 'xlsx') {
            return $this->xlsx($data, $base);
        }

        // Default: stream CSV.
        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $data['header']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $base.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Build a real .xlsx via spatie/simple-excel into a temp file and stream it
     * (deleted after send). First row's keys become the header.
     *
     * @param  array{header:array<int,string>, rows:array<int,array<int,string>>}  $data
     */
    private function xlsx(array $data, string $base): BinaryFileResponse
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pricing-ops-'.uniqid('', true).'.xlsx';

        $writer = SimpleExcelWriter::create($path);
        foreach ($data['rows'] as $row) {
            $writer->addRow(array_combine($data['header'], $row));
        }
        $writer->close();

        return response()->download($path, $base.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
