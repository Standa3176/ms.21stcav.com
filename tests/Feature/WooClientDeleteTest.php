<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Quick task 260613-plo — WooClient::delete() POST-routing mirror
|--------------------------------------------------------------------------
|
| 7 cases + 1 DRIFT guard covering every documented routing outcome:
|
|   A — Default config (use_post_for_deletes=true, write_enabled=true):
|       delete('products/brands/42', ['force' => true])
|         → AutomatticClient::post('products/brands/42?_method=DELETE', ['force' => true])
|         → AutomatticClient::delete() NOT called.
|   B — Opt-out (use_post_for_deletes=false, write_enabled=true): same call
|         → AutomatticClient::delete('products/brands/42', ['force' => true])
|         → AutomatticClient::post() NOT called.
|   C — Endpoint with existing query string uses `&` separator
|       delete('products/123?force=true')
|         → AutomatticClient::post('products/123?force=true&_method=DELETE', [])
|   D — Empty payload default
|       delete('products/brands/42')
|         → AutomatticClient::post('products/brands/42?_method=DELETE', [])
|   E — Audit log row records method=POST + endpoint=…?_method=DELETE
|   F — Shadow-mode gate (write_enabled=false): SyncDiff row created with
|       method=POST + endpoint=…?_method=DELETE; AutomatticClient NEVER called.
|   G — Backward-compat regression: put() still routes through POST WITHOUT
|       the `_method` query-string tunnel (PUT precedent is 260530-clv: WP-REST
|       treats POST and PUT identically for EDITABLE endpoints; only DELETE
|       needs the explicit `_method` tunnel).
|   DRIFT — Architecture: the literal `_method=DELETE` appears in EXACTLY
|       ONE file under app/Domain/ (WooClient.php). Any second site means
|       someone forked the WAF workaround instead of consuming it.
|
| Boundary strategy: Mockery on AutomatticClient + WooResponse for status codes.
| Uses the 3-arg WooClient constructor (logger + resolver + inner) — the older
| 2-arg form used by WooClientGetTest.php is the source of that file's 4
| pre-existing failures (out of scope per 260613-plo brief).
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('Case A: default config — delete() routes through POST with ?_method=DELETE appended', function (): void {
    config()->set('services.woo.write_enabled', true);
    // use_post_for_deletes defaults to true; do not override.

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('post')
        ->once()
        ->with('products/brands/42?_method=DELETE', ['force' => true])
        ->andReturn([]);

    // Mockery default-deny on delete(): if it gets called, the test fails.
    $mockInner->shouldNotReceive('delete');

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    $client->delete('products/brands/42', ['force' => true]);
});

it('Case B: opt-out (use_post_for_deletes=false) — delete() routes through strict HTTP DELETE', function (): void {
    config()->set('services.woo.write_enabled', true);
    config()->set('services.woo.use_post_for_deletes', false);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('delete')
        ->once()
        ->with('products/brands/42', ['force' => true])
        ->andReturn([]);

    // Strict-DELETE branch: post() MUST NOT be called.
    $mockInner->shouldNotReceive('post');

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    $client->delete('products/brands/42', ['force' => true]);
});

it('Case C: endpoint with existing query string uses `&` separator (not `?`)', function (): void {
    config()->set('services.woo.write_enabled', true);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('post')
        ->once()
        ->with('products/123?force=true&_method=DELETE', [])
        ->andReturn([]);
    $mockInner->shouldNotReceive('delete');

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    $client->delete('products/123?force=true');
});

it('Case D: empty payload default — no crash, no spurious `force`', function (): void {
    config()->set('services.woo.write_enabled', true);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('post')
        ->once()
        ->with('products/brands/42?_method=DELETE', [])
        ->andReturn([]);
    $mockInner->shouldNotReceive('delete');

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    // No second arg — default array().
    $client->delete('products/brands/42');
});

it('Case E: audit log row records channel=woo + method=POST + endpoint=…?_method=DELETE + status=success', function (): void {
    config()->set('services.woo.write_enabled', true);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('post')->once()->andReturn([]);

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    $client->delete('products/brands/42', ['force' => true]);

    $events = IntegrationEvent::all();
    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->channel)->toBe('woo')
        ->and($event->method)->toBe('POST')
        ->and($event->endpoint)->toBe('products/brands/42?_method=DELETE')
        ->and($event->status)->toBe('success')
        ->and($event->http_status)->toBe(200)
        ->and($event->correlation_id)->not->toBeNull();
});

it('Case F: shadow-mode gate — SyncDiff row records method=POST + endpoint=…?_method=DELETE; SDK NEVER called', function (): void {
    config()->set('services.woo.write_enabled', false);
    // use_post_for_deletes defaults to true.

    $mockInner = Mockery::mock(AutomatticClient::class);
    // CRITICAL: no Mockery expectations on the SDK — shadow-mode short-circuits
    // before the writeLive path. If anything calls post()/delete() the strict
    // mock will throw.

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    $result = $client->delete('products/brands/42', ['force' => true]);

    expect($result)->toHaveKey('shadow_mode', true);

    $diff = SyncDiff::query()->first();
    expect($diff)->not->toBeNull()
        ->and($diff->channel)->toBe('woo')
        ->and($diff->method)->toBe('POST')
        ->and($diff->endpoint)->toBe('products/brands/42?_method=DELETE')
        ->and($diff->payload)->toBe(['force' => true]);
});

it('Case G: backward-compat regression — put() routes through POST WITHOUT a `_method` query suffix', function (): void {
    config()->set('services.woo.write_enabled', true);
    config()->set('services.woo.use_post_for_updates', true);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    // 260530-clv contract: PUT routes through POST WITHOUT touching the URL —
    // WP-REST handles POST as EDITABLE for /products/{id}. Only DELETE needs
    // the explicit _method query-string tunnel.
    $mockInner->shouldReceive('post')
        ->once()
        ->with('products/42', ['regular_price' => '99.00'])
        ->andReturn([]);
    $mockInner->shouldNotReceive('put');

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    );

    $client->put('products/42', ['regular_price' => '99.00']);
});

it('DRIFT: `_method=DELETE` literal appears in EXACTLY one file under app/Domain/, and that file is WooClient.php', function (): void {
    $finder = (new Finder())
        ->files()
        ->in(app_path('Domain'))
        ->name('*.php');

    $hits = [];
    foreach ($finder as $file) {
        $contents = file_get_contents($file->getRealPath());
        if ($contents !== false && str_contains($contents, '_method=DELETE')) {
            $hits[] = $file->getRealPath();
        }
    }

    expect($hits)->toHaveCount(
        1,
        sprintf(
            'Expected the WAF DELETE-tunnel literal `_method=DELETE` to appear in EXACTLY one file under app/Domain/. '
            ."Found %d hits:\n  - %s\n\n"
            .'If you legitimately need a second site, refactor WooClient::delete() so the tunnel logic remains '
            .'the single source of truth — do NOT duplicate the workaround.',
            count($hits),
            implode("\n  - ", $hits),
        ),
    );

    expect($hits[0])->toEndWith('WooClient.php');
});
