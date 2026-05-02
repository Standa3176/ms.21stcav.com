<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Services;

use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

/**
 * Phase 11.1 Plan 01 — D-01 + D-02 dynamic Flysystem disk per source.
 *
 * Builds a `\League\Flysystem\Filesystem` instance from a CompetitorFtpSource
 * row at runtime. Each source has its own Flysystem instance — the disk is
 * NEVER persisted in `config/filesystems.php` so credentials live in the DB
 * (encrypted via the model's 'encrypted' Eloquent cast — D-04) and ops can
 * add/remove sources via Filament without redeploys.
 *
 * Adapter selection by `$source->protocol`:
 *   - 'ftp'  → FtpAdapter (plain FTP — discouraged but supported for legacy feeds)
 *   - 'ftps' → FtpAdapter with ssl=true (FTP over TLS)
 *   - 'sftp' → SftpAdapter (SSH FTP via league/flysystem-sftp-v3 → phpseclib)
 *
 * Both packages are already transitively pulled by Laravel 12 — verified via
 * composer.lock at plan time (no `composer require` needed; D-01).
 *
 * `verify_ssl` toggle on the source applies to FTPS only — when false the
 * adapter accepts self-signed certs and a Warning is emitted to the log so
 * ops can audit the security exemption later.
 *
 * Connection timeout falls through to `config('competitor.ftp.connection_timeout_seconds')`
 * which defaults to 30s. Per-source override is a future plan (deferred).
 *
 * NOTE: NOT marked `final` so test suites can extend with a stub `connect()`
 * method (Phase 11.1 feature tests bind the stub via $app->instance(...) to
 * exercise the command without a real FTP server).
 */
class FtpSourceConnector
{
    public function connect(CompetitorFtpSource $source): Filesystem
    {
        $adapter = match ($source->protocol) {
            CompetitorFtpSource::PROTOCOL_FTP, CompetitorFtpSource::PROTOCOL_FTPS => $this->buildFtpAdapter($source),
            CompetitorFtpSource::PROTOCOL_SFTP => $this->buildSftpAdapter($source),
            default => throw new RuntimeException(
                "Unknown FTP protocol '{$source->protocol}' for source {$source->id} ({$source->name}). "
                ."Allowed: ftp / sftp / ftps."
            ),
        };

        return new Filesystem($adapter);
    }

    private function buildFtpAdapter(CompetitorFtpSource $source): FtpAdapter
    {
        $isFtps = $source->protocol === CompetitorFtpSource::PROTOCOL_FTPS;

        if ($isFtps && $source->verify_ssl === false) {
            // Pitfall: ops self-signed cert exemption — audit-trail the
            // weakened security posture so it surfaces in incident reviews.
            Log::warning('competitor.ftp.ssl_verification_disabled', [
                'source_id' => $source->id,
                'host' => $source->host,
                'protocol' => $source->protocol,
            ]);
        }

        return new FtpAdapter(
            FtpConnectionOptions::fromArray([
                'host' => $source->host,
                'root' => $source->base_path ?: '/',
                'username' => $source->username,
                'password' => (string) ($source->password_encrypted ?? ''),
                'port' => (int) $source->port,
                'ssl' => $isFtps,
                'timeout' => (int) config('competitor.ftp.connection_timeout_seconds', 30),
                'utf8' => true,
                'passive' => true,
                // FTP_BINARY (=2) lives in ext-ftp; reference the literal so dev
                // boxes without ext-ftp enabled can still load this class for
                // unit tests + Filament resource introspection. Production VPS
                // ALWAYS has ext-ftp enabled (composer install --no-dev would
                // fail without it because league/flysystem-ftp requires ext-ftp).
                'transferMode' => defined('FTP_BINARY') ? FTP_BINARY : 2,
                'systemType' => null,
                'ignorePassiveAddress' => $isFtps && $source->verify_ssl === false,
                'timestampsOnUnixListingsEnabled' => false,
                'recurseManually' => true,
            ])
        );
    }

    private function buildSftpAdapter(CompetitorFtpSource $source): SftpAdapter
    {
        $provider = new SftpConnectionProvider(
            host: $source->host,
            username: $source->username,
            password: $source->password_encrypted ?: null,
            privateKey: $source->private_key_encrypted ?: null,
            passphrase: $source->passphrase_encrypted ?: null,
            port: (int) $source->port,
            useAgent: false,
            timeout: (int) config('competitor.ftp.connection_timeout_seconds', 30),
            maxTries: 1,
            hostFingerprint: null,
            connectivityChecker: null,
        );

        return new SftpAdapter($provider, $source->base_path ?: '/');
    }
}
