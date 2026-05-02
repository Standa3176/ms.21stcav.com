<?php

declare(strict_types=1);

use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.1 Plan 01 — FtpSourceConnector adapter selection tests.
|--------------------------------------------------------------------------
|
| Verifies the connector picks the right Flysystem adapter for each protocol
| (D-01 + D-02 — dynamic per-source disk; never persisted in
| config/filesystems.php). NO actual FTP connections are made — we only
| introspect the built Filesystem's internal `adapter` property via
| ReflectionProperty.
*/

it('builds an FtpAdapter for protocol=ftp', function (): void {
    $source = CompetitorFtpSource::factory()->ftp()->create();

    $fs = (new FtpSourceConnector())->connect($source);
    expect($fs)->toBeInstanceOf(Filesystem::class);

    $adapterProp = (new ReflectionClass($fs))->getProperty('adapter');
    $adapterProp->setAccessible(true);
    expect($adapterProp->getValue($fs))->toBeInstanceOf(FtpAdapter::class);
});

it('builds an FtpAdapter for protocol=ftps', function (): void {
    $source = CompetitorFtpSource::factory()->ftps()->create();

    $fs = (new FtpSourceConnector())->connect($source);

    $adapterProp = (new ReflectionClass($fs))->getProperty('adapter');
    $adapterProp->setAccessible(true);
    expect($adapterProp->getValue($fs))->toBeInstanceOf(FtpAdapter::class);
});

it('builds an SftpAdapter for protocol=sftp', function (): void {
    $source = CompetitorFtpSource::factory()->create(); // sftp is the factory default

    $fs = (new FtpSourceConnector())->connect($source);

    $adapterProp = (new ReflectionClass($fs))->getProperty('adapter');
    $adapterProp->setAccessible(true);
    expect($adapterProp->getValue($fs))->toBeInstanceOf(SftpAdapter::class);
});

it('throws RuntimeException for unknown protocol', function (): void {
    $source = CompetitorFtpSource::factory()->create();
    // Bypass model fillable + casts to set an out-of-band value.
    DB::table('competitor_ftp_sources')->where('id', $source->id)->update(['protocol' => 'gopher']);

    $reloaded = CompetitorFtpSource::find($source->id);

    expect(fn () => (new FtpSourceConnector())->connect($reloaded))
        ->toThrow(RuntimeException::class, "Unknown FTP protocol 'gopher'");
});
