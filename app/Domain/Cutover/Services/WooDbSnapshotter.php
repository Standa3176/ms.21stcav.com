<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Phase 7 Plan 05 Task 2 — CUT-04 Woo-DB snapshotter.
 *
 * Spawns `mysqldump` piped through `gzip` against the WOO_DB_* env credentials
 * (which the VPS operator maintains as a READ-ONLY connection to the
 * meetingstore.co.uk WordPress database). The output filename encodes an
 * ISO-ish UTC timestamp + an ops-supplied label so ops can distinguish
 * pre-cutover / post-cutover / mid-drill backups at a glance.
 *
 * Filename shape:
 *   woo-db-backup-{YYYY-MM-DD-HHMMSS}-{safe-label}.sql.gz
 *
 * label is re-escaped to [a-zA-Z0-9_-] before use so an operator typo
 * (spaces, path separators) can't escape the backup directory.
 *
 * Every successful snapshot writes an audit_log row with action
 * 'cutover.woo_db_snapshotted' containing {filename, path, label, size_bytes}
 * so an operator can reconcile the file on disk against the audit trail
 * post-cutover.
 *
 * Binary dependencies:
 *   - mysqldump (mysql-client apt package)
 *   - gzip       (base system)
 *
 * Failures throw RuntimeException; the command wrapper translates this to
 * a non-zero exit code + an error log line (no uncaught crash).
 */
class WooDbSnapshotter
{
    public function __construct(protected Auditor $auditor) {}

    /**
     * Snapshot the configured Woo DB to {backup_path}/{filename}.
     *
     * @param  string  $label  Ops-supplied reason for the snapshot ('pre-cutover', 'drill-run-2', etc.)
     * @return array{filename:string, path:string, size_bytes:int}
     *
     * @throws \RuntimeException on mysqldump failure
     */
    public function snapshot(string $label): array
    {
        $backupDir = (string) config('cutover.backup_path');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0o755, true);
        }

        $stamp = now()->format('Y-m-d-His');
        $safeLabel = preg_replace('/[^a-zA-Z0-9_-]/', '-', $label);
        if ($safeLabel === '' || $safeLabel === null) {
            $safeLabel = 'unlabelled';
        }
        $filename = sprintf('woo-db-backup-%s-%s.sql.gz', $stamp, $safeLabel);
        $path = $backupDir.DIRECTORY_SEPARATOR.$filename;

        $host = (string) env('WOO_DB_HOST', '127.0.0.1');
        $user = (string) env('WOO_DB_USERNAME', 'root');
        $pass = (string) env('WOO_DB_PASSWORD', '');
        $db = (string) env('WOO_DB_DATABASE', 'wordpress');

        // Command assembled with escapeshellarg on every interpolated value
        // (T-07-05-03 mitigation — prevents WOO_DB_PASSWORD leaking via shell
        // injection; note the password still briefly appears in `ps` — operator
        // runs this on a private VPS per the threat register).
        $cmd = sprintf(
            'mysqldump --single-transaction --skip-lock-tables -h %s -u %s -p%s %s | gzip > %s',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($db),
            escapeshellarg($path),
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(3600); // 1h for a large Woo DB

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            Log::error('WooDbSnapshotter: mysqldump failed', [
                'exception' => $e->getMessage(),
                'stderr' => $process->getErrorOutput(),
            ]);
            throw new \RuntimeException(
                'mysqldump failed: '.$e->getMessage(),
                0,
                $e,
            );
        }

        $size = file_exists($path) ? (int) filesize($path) : 0;

        $this->auditor->record('cutover.woo_db_snapshotted', [
            'filename' => $filename,
            'path' => $path,
            'label' => $label,
            'size_bytes' => $size,
        ]);

        return [
            'filename' => $filename,
            'path' => $path,
            'size_bytes' => $size,
        ];
    }
}
