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
 *
 * SECURITY (2026-06-07, quick task 260607-9c6 — H-2 remediation):
 *   Originally the password was interpolated as `mysqldump -p<pwd>` argv,
 *   which is shell-injection-safe (escapeshellarg covers metacharacters)
 *   but VISIBLE in /proc/PID/cmdline for the entire 1h dump window. Any
 *   local user could read the password while the dump ran. Now we write
 *   the credentials to a chmod-0600 temp .cnf file (mode set BEFORE
 *   write so it's never world-readable on disk), pass it via
 *   `--defaults-extra-file=...` (which mysqldump strips from argv), and
 *   unlink the temp file in finally{} on both success AND failure. The
 *   T-07-05-03 shell-injection mitigation is still in place via
 *   escapeshellarg on every interpolated value.
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

        // Read via config() (not env()) — env() outside config/*.php returns
        // the .env default at config:cache build time, not the live value
        // (see d7d0e39 + 2026-05-31 cutover incident). Bindings live in
        // config('cutover.woo_db.*').
        $host = (string) config('cutover.woo_db.host', '127.0.0.1');
        $user = (string) config('cutover.woo_db.username', 'root');
        $pass = (string) config('cutover.woo_db.password', '');
        $db = (string) config('cutover.woo_db.database', 'wordpress');

        // H-2 remediation (260607-9c6): assemble via --defaults-extra-file
        // with a chmod-0600 temp .cnf so the password never lands on argv.
        // T-07-05-03 shell-injection mitigation preserved via escapeshellarg
        // on every interpolated value below.
        $this->buildAndRunDump($host, $user, $pass, $db, $path);

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

    /**
     * H-2 remediation (260607-9c6, SECURITY-REVIEW.md 260606-q7h):
     * Write credentials to a chmod-0600 temp .cnf file and pass it via
     * --defaults-extra-file so the password never appears on the mysqldump
     * argv (which would be visible in /proc/PID/cmdline for the full dump).
     *
     * chmod runs BEFORE file_put_contents so the file is mode-0600 from the
     * instant it exists on disk — other-user reads are blocked even mid-write
     * (per T-9c6-03 in the quick-task threat model).
     *
     * The temp file is unlinked in finally{} so it disappears on BOTH success
     * AND failure paths (per T-9c6-04). Suppress-error on unlink because the
     * dump-success path is the read-side of interest, not unlink failures.
     */
    protected function buildAndRunDump(string $host, string $user, string $pass, string $db, string $path): void
    {
        $cnfPath = tempnam(sys_get_temp_dir(), 'msdmp_');
        if ($cnfPath === false) {
            throw new \RuntimeException('mysqldump: failed to allocate temp cnf file');
        }
        // chmod BEFORE writing — file is mode-0600 from the moment it exists.
        @chmod($cnfPath, 0o600);
        file_put_contents(
            $cnfPath,
            "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\n"
        );

        try {
            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --skip-lock-tables %s | gzip > %s',
                escapeshellarg($cnfPath),
                escapeshellarg($db),
                escapeshellarg($path),
            );
            $this->runDumpCommand($cmd);
        } finally {
            @unlink($cnfPath);
        }
    }

    /**
     * Extracted so tests can override the Process invocation without invoking
     * a real mysqldump binary (see WooDbSnapshotterCommandBuilderTest).
     */
    protected function runDumpCommand(string $cmd): void
    {
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
    }
}
