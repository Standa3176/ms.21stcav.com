<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Services;

use App\Domain\Competitor\Models\CompetitorFtpCredential;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

/**
 * Phase 11.2 Plan 01 — D-12 dynamic Flysystem disk per credential.
 *
 * Builds a `\League\Flysystem\Filesystem` instance from a CompetitorFtpCredential
 * row at runtime. Each credential has its own Flysystem instance — the disk is
 * NEVER persisted in `config/filesystems.php` so credentials live in the DB
 * (encrypted via the model's 'encrypted' Eloquent cast — D-03) and ops can
 * add/remove credentials via Filament without redeploys.
 *
 * Phase 11.2 refactor: connector accepts CompetitorFtpCredential (was
 * CompetitorFtpSource). Same body — only the model class changed.
 *
 * Adapter selection by `$credential->protocol`:
 *   - 'ftp'  → FtpAdapter (plain FTP — discouraged but supported for legacy feeds)
 *   - 'ftps' → FtpAdapter with ssl=true (FTP over TLS)
 *   - 'sftp' → SftpAdapter (SSH FTP via league/flysystem-sftp-v3 → phpseclib)
 *
 * Connection timeout falls through to `config('competitor.ftp.connection_timeout_seconds')`
 * which defaults to 30s. Per-credential override is a future plan (deferred).
 *
 * NOTE: NOT marked `final` so test suites can extend with a stub `connect()`
 * method (Phase 11.1 lesson — anonymous-class extension is the cleaner pattern
 * than Mockery's class-mock magic).
 */
class FtpSourceConnector
{
    public function connect(CompetitorFtpCredential $credential): Filesystem
    {
        $adapter = match ($credential->protocol) {
            CompetitorFtpCredential::PROTOCOL_FTP, CompetitorFtpCredential::PROTOCOL_FTPS => $this->buildFtpAdapter($credential),
            CompetitorFtpCredential::PROTOCOL_SFTP => $this->buildSftpAdapter($credential),
            default => throw new RuntimeException(
                "Unknown FTP protocol '{$credential->protocol}' for credential {$credential->id} ({$credential->name}). "
                .'Allowed: ftp / sftp / ftps.'
            ),
        };

        return new Filesystem($adapter);
    }

    private function buildFtpAdapter(CompetitorFtpCredential $credential): FtpAdapter
    {
        $isFtps = $credential->protocol === CompetitorFtpCredential::PROTOCOL_FTPS;

        if ($isFtps && $credential->verify_ssl === false) {
            // Pitfall: ops self-signed cert exemption — audit-trail the
            // weakened security posture so it surfaces in incident reviews.
            Log::warning('competitor.ftp.ssl_verification_disabled', [
                'credential_id' => $credential->id,
                'host' => $credential->host,
                'protocol' => $credential->protocol,
            ]);
        }

        return new FtpAdapter(
            FtpConnectionOptions::fromArray([
                'host' => $credential->host,
                'root' => $credential->base_path ?: '/',
                'username' => $credential->username,
                'password' => (string) ($credential->password_encrypted ?? ''),
                'port' => (int) $credential->port,
                'ssl' => $isFtps,
                'timeout' => (int) config('competitor.ftp.connection_timeout_seconds', 30),
                'utf8' => true,
                'passive' => true,
                // FTP_BINARY (=2) lives in ext-ftp; reference the literal so dev
                // boxes without ext-ftp enabled can still load this class for
                // unit tests + Filament resource introspection.
                'transferMode' => defined('FTP_BINARY') ? FTP_BINARY : 2,
                'systemType' => null,
                'ignorePassiveAddress' => $isFtps && $credential->verify_ssl === false,
                'timestampsOnUnixListingsEnabled' => false,
                'recurseManually' => true,
            ])
        );
    }

    private function buildSftpAdapter(CompetitorFtpCredential $credential): SftpAdapter
    {
        $provider = new SftpConnectionProvider(
            host: $credential->host,
            username: $credential->username,
            password: $credential->password_encrypted ?: null,
            privateKey: $credential->private_key_encrypted ?: null,
            passphrase: $credential->passphrase_encrypted ?: null,
            port: (int) $credential->port,
            useAgent: false,
            timeout: (int) config('competitor.ftp.connection_timeout_seconds', 30),
            maxTries: 1,
            hostFingerprint: null,
            connectivityChecker: null,
        );

        return new SftpAdapter($provider, $credential->base_path ?: '/');
    }
}
