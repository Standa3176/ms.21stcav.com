<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;

it('generates a UUIDv4 correlation_id when no inbound header is present', function () {
    $response = $this->get('/up');

    $response->assertOk();
    $cid = $response->headers->get('X-Correlation-Id');

    expect($cid)->not->toBeNull()
        ->and($cid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('honours a well-formed inbound X-Correlation-Id header', function () {
    $fixed = 'fixed-value-abc123-xyz';

    $response = $this->withHeaders(['X-Correlation-Id' => $fixed])->get('/up');

    expect($response->headers->get('X-Correlation-Id'))->toBe($fixed);
});

it('falls back to X-Request-Id when X-Correlation-Id absent', function () {
    $fixed = 'req-id-7890-abc';

    $response = $this->withHeaders(['X-Request-Id' => $fixed])->get('/up');

    expect($response->headers->get('X-Correlation-Id'))->toBe($fixed);
});

it('rejects malformed inbound correlation_id and regenerates (T-03-02)', function () {
    // Newline-containing header would be stripped by HTTP layer, so use characters the regex rejects.
    $malformed = 'has spaces and !!! invalid chars';

    $response = $this->withHeaders(['X-Correlation-Id' => $malformed])->get('/up');

    expect($response->headers->get('X-Correlation-Id'))
        ->not->toBe($malformed)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}/i');
});

it('rejects inbound correlation_id that is too long (>64 chars) and regenerates', function () {
    $tooLong = str_repeat('a', 100);

    $response = $this->withHeaders(['X-Correlation-Id' => $tooLong])->get('/up');

    expect($response->headers->get('X-Correlation-Id'))
        ->not->toBe($tooLong)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}/i');
});

it('rejects inbound correlation_id that is too short (<8 chars) and regenerates', function () {
    $tooShort = 'abc';

    $response = $this->withHeaders(['X-Correlation-Id' => $tooShort])->get('/up');

    expect($response->headers->get('X-Correlation-Id'))
        ->not->toBe($tooShort)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}/i');
});

it('DomainEvent subclass reads correlation_id from Context, not a fresh UUID', function () {
    Context::add('correlation_id', 'test-cid-from-context');

    $event = new class extends \App\Foundation\Events\DomainEvent {};

    expect($event->correlationId)->toBe('test-cid-from-context');
});

it('DomainEvent generates UUIDv4 when Context has no correlation_id', function () {
    Context::forget('correlation_id');

    $event = new class extends \App\Foundation\Events\DomainEvent {};

    expect($event->correlationId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}/i');
});

it('DomainEvent sets occurredAt to ISO-8601 timestamp', function () {
    $event = new class extends \App\Foundation\Events\DomainEvent {};

    expect($event->occurredAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});
