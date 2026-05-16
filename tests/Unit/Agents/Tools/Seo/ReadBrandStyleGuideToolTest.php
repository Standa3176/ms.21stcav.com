<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Seo\ReadBrandStyleGuideTool;

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 02 Task 1 — ReadBrandStyleGuideTool real-impl unit tests
|--------------------------------------------------------------------------
|
| Validates SEOAGT-02 read_brand_style_guide tool body:
|   - Per-brand markdown returned when {slug}.md exists
|   - Falls back to _global.md when per-brand file absent (CONTEXT D-01/D-02)
|   - 3072-byte content cap via mb_substr (T-12-02-03 DoS mitigation)
|   - source field reports 'per-brand' or 'global'
|   - Empty / 'global' brand argument → global content
|   - SECURITY (P12-H / T-12-01-02): file is read raw, never through Blade
|
| No DB needed — pure file_get_contents + json_encode.
*/

function invokeReadBrandStyleGuide(string $brand): string
{
    return app(ReadBrandStyleGuideTool::class)->asPrismTool()->handle($brand);
}

it('returns per-brand markdown when {slug}.md exists (logitech happy path)', function () {
    $payload = json_decode(invokeReadBrandStyleGuide('logitech'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand'])->toBe('logitech');
    expect($payload['source'])->toBe('per-brand');
    expect($payload['content'])->toContain('Logitech voice supplement');
    expect($payload['_bytes'])->toBeGreaterThan(0);
});

it('falls back to _global.md when per-brand file does not exist', function () {
    $payload = json_decode(invokeReadBrandStyleGuide('unknown-brand-xyz'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand'])->toBe('unknown-brand-xyz');
    expect($payload['source'])->toBe('global');
    expect($payload['content'])->toContain('MeetingStore brand voice');
    expect($payload['_bytes'])->toBeGreaterThan(0);
});

it('treats empty brand string as global', function () {
    $payload = json_decode(invokeReadBrandStyleGuide(''), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand'])->toBe('global');
    expect($payload['source'])->toBe('global');
    expect($payload['content'])->toContain('MeetingStore brand voice');
});

it('treats brand="global" as global (case-normalised)', function () {
    $payload = json_decode(invokeReadBrandStyleGuide('global'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand'])->toBe('global');
    expect($payload['source'])->toBe('global');
});

it('normalises case of brand slug (Logitech → logitech)', function () {
    $payload = json_decode(invokeReadBrandStyleGuide('LOGITECH'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand'])->toBe('logitech');
    expect($payload['source'])->toBe('per-brand');
});

it('caps content at 3072 chars via mb_substr', function () {
    // Use a brand file we control — write a temp brand file with > 3072 chars.
    $tempSlug = 'caps-test-'.uniqid();
    $tempPath = resource_path("agents/brand-voice/{$tempSlug}.md");
    $bigContent = str_repeat('x', 5_000);
    file_put_contents($tempPath, $bigContent);

    try {
        $payload = json_decode(invokeReadBrandStyleGuide($tempSlug), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['source'])->toBe('per-brand');
        expect(mb_strlen($payload['content']))->toBe(3072);
        expect($payload['_bytes'])->toBe(5_000); // total bytes BEFORE cap
    } finally {
        @unlink($tempPath);
    }
});
