<?php

declare(strict_types=1);

use App\Foundation\Html\RichTextSanitiser;

/*
|--------------------------------------------------------------------------
| Quick task 260906-hbl — rich-text sanitiser
|--------------------------------------------------------------------------
|
| The product preview rendered LLM-written description HTML raw into an
| authenticated admin's browser. The markup has to survive (it is the shop
| description); the script must not.
*/

beforeEach(function (): void {
    $this->s = new RichTextSanitiser;
});

it('keeps the markup a product description is actually made of', function (): void {
    $html = '<p>A <strong>4K</strong> camera.</p><ul><li>USB-C</li><li>PTZ</li></ul><h3>Specs</h3>';

    $out = $this->s->sanitise($html);

    expect($out)->toContain('<strong>4K</strong>')
        ->and($out)->toContain('<li>USB-C</li>')
        ->and($out)->toContain('<h3>Specs</h3>');
});

it('removes script tags AND their body', function (): void {
    $out = $this->s->sanitise('<p>Hi</p><script>fetch("/admin/users").then(r=>r.text())</script>');

    expect($out)->toContain('<p>Hi</p>')
        ->and($out)->not->toContain('script')
        ->and($out)->not->toContain('fetch');
});

it('strips event-handler and style attributes while keeping the tag', function (): void {
    $out = $this->s->sanitise('<p onclick="steal()" style="position:fixed" class="x">Text</p>');

    expect($out)->toBe('<p>Text</p>');
});

it('neutralises the classic img onerror payload', function (): void {
    $out = $this->s->sanitise('<img src=x onerror="alert(document.cookie)">');

    expect($out)->not->toContain('onerror')
        ->and($out)->not->toContain('alert');
});

it('drops javascript: hrefs but keeps http, https and mailto', function (): void {
    expect($this->s->sanitise('<a href="javascript:alert(1)">x</a>'))->not->toContain('javascript')
        ->and($this->s->sanitise('<a href="https://example.com">x</a>'))->toContain('href="https://example.com"')
        ->and($this->s->sanitise('<a href="mailto:s@example.com">x</a>'))->toContain('mailto:s@example.com')
        ->and($this->s->sanitise('<a href="/local/page">x</a>'))->toContain('href="/local/page"');
});

it('removes iframes, forms, svg and comments entirely', function (string $html): void {
    $out = $this->s->sanitise($html);

    expect($out)->not->toContain('iframe')
        ->and($out)->not->toContain('<form')
        ->and($out)->not->toContain('<svg')
        ->and($out)->not->toContain('onload');
})->with([
    '<iframe src="https://evil.test"></iframe>',
    '<form action="/x"><input name="a"></form>',
    '<svg onload="alert(1)"></svg>',
    '<!-- [if IE]><script>alert(1)</script><![endif] -->',
]);

it('returns an empty string for empty and null input', function (): void {
    expect($this->s->sanitise(null))->toBe('')
        ->and($this->s->sanitise(''))->toBe('')
        ->and($this->s->sanitise('   '))->toBe('');
});

it('preserves non-ascii characters rather than mangling them', function (): void {
    expect($this->s->sanitise('<p>Ø 60mm — café “quoted”</p>'))
        ->toContain('Ø')
        ->toContain('café');
});
