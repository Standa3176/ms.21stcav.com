<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\WooDbSnapshotter;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-9c6 — WooDbSnapshotter command builder (H-2 remediation)
|--------------------------------------------------------------------------
|
| SECURITY-REVIEW.md (260606-q7h) H-2: WooDbSnapshotter leaked the WooDB
| password via mysqldump -p<pwd> argv (visible in /proc/PID/cmdline for up
| to 1h while the dump ran). Refactor uses --defaults-extra-file with a
| chmod-0600 temp .cnf file, unlinked in finally on both success + failure.
|
| Test strategy: an anonymous subclass overrides runDumpCommand() to capture
| the assembled command string + tempfile state instead of invoking Process.
| We never actually run mysqldump — the override is the seam.
|
| Sentinel password: SECRET_SHOULD_NOT_LEAK_123!@# is loud + grep-friendly.
| Any future regression that puts the password back on argv fails CI noisily.
*/

const SECRET_SENTINEL = 'SECRET_SHOULD_NOT_LEAK_123!@#';

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir().'/cutover-snapshot-builder-test-'.uniqid();
    mkdir($this->tmp, 0o755, true);
    config([
        'cutover.backup_path' => $this->tmp,
        'cutover.woo_db.host' => 'wooprod.internal',
        'cutover.woo_db.username' => 'msops',
        'cutover.woo_db.password' => SECRET_SENTINEL,
        'cutover.woo_db.database' => 'meetingstore_woo',
    ]);
});

afterEach(function (): void {
    if (isset($this->tmp) && is_dir($this->tmp)) {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        @rmdir($this->tmp);
    }
});

it('builds a command that uses --defaults-extra-file', function (): void {
    $snap = makeRecordingSnapshotter();
    $snap->snapshot('builder-test');
    expect($snap->capture['cmd'])->toContain('--defaults-extra-file=');
});

it('does NOT include the literal password in the assembled command', function (): void {
    $snap = makeRecordingSnapshotter();
    $snap->snapshot('no-leak');
    expect($snap->capture['cmd'])->not->toContain(SECRET_SENTINEL);
});

it('does NOT use the legacy -p<pwd> short-flag', function (): void {
    $snap = makeRecordingSnapshotter();
    $snap->snapshot('no-p-flag');
    // -p followed by anything other than whitespace/EOL is the dangerous form
    expect(preg_match('/\s-p\S/', (string) $snap->capture['cmd']))->toBe(0);
});

it('writes a cnf with [client] block + user/password/host', function (): void {
    $snap = makeRecordingSnapshotter();
    $snap->snapshot('cnf-content');
    expect($snap->capture['cnf_content'])->toContain('[client]');
    expect($snap->capture['cnf_content'])->toContain('user=msops');
    expect($snap->capture['cnf_content'])->toContain('password='.SECRET_SENTINEL);
    expect($snap->capture['cnf_content'])->toContain('host=wooprod.internal');
});

it('unlinks the cnf temp file after a successful run', function (): void {
    $snap = makeRecordingSnapshotter();
    $snap->snapshot('unlink-success');
    expect($snap->capture['cnf_path'])->not->toBeNull();
    expect(file_exists($snap->capture['cnf_path']))->toBeFalse();
});

it('unlinks the cnf temp file even when the dump throws (finally branch)', function (): void {
    $snap = makeRecordingSnapshotter(new \RuntimeException('simulated mysqldump failure'));
    try {
        $snap->snapshot('unlink-on-failure');
    } catch (\Throwable) {
        // expected — runDumpCommand throws
    }
    expect($snap->capture['cnf_path'])->not->toBeNull();
    expect(file_exists($snap->capture['cnf_path']))->toBeFalse();
});

// ── helpers ──

function makeRecordingSnapshotter(?\Throwable $throw = null): WooDbSnapshotter
{
    return new class(app(Auditor::class), $throw) extends WooDbSnapshotter {
        /** @var array<string,mixed> */
        public array $capture = ['cmd' => null, 'cnf_content' => null, 'cnf_path' => null];

        public function __construct(Auditor $a, public ?\Throwable $throw)
        {
            parent::__construct($a);
        }

        protected function runDumpCommand(string $cmd): void
        {
            $this->capture['cmd'] = $cmd;
            // Extract --defaults-extra-file=<arg> path. escapeshellarg uses
            // single quotes on POSIX and double quotes on Windows — broaden
            // the regex to accept both so the test passes on both runtimes.
            if (preg_match("/--defaults-extra-file=(?:'([^']+)'|\"([^\"]+)\")/", $cmd, $m)) {
                $path = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                $this->capture['cnf_path'] = $path;
                $this->capture['cnf_content'] = $path !== '' && file_exists($path)
                    ? (string) file_get_contents($path)
                    : null;
            }
            if ($this->throw) {
                throw $this->throw;
            }
        }
    };
}
