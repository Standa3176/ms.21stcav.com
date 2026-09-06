<?php

declare(strict_types=1);

namespace App\Foundation\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Quick task 260906-hbl — allow-list sanitiser for UNTRUSTED rich text.
 *
 * WHY THIS EXISTS
 *
 * `resources/views/preview/product.blade.php` rendered
 * `{!! $product->short_description !!}` and `{!! $product->long_description !!}`
 * raw. Those columns are written by the auto-create pipeline from Claude output
 * whose prompt is built out of supplier feed text — third-party data arriving by
 * FTP/CSV. A supplier row (or a prompt injection riding in one) could therefore
 * land `<script>` in the column, and it executed in the browser of whichever
 * admin opened the preview, inside an authenticated Filament session.
 *
 * The columns legitimately CONTAIN html — they become the Woo product
 * description — so escaping them is not an option; the markup has to survive
 * while the script does not.
 *
 * APPROACH
 *
 * Parse with DOMDocument, then walk the tree and keep only what is on the two
 * allow-lists below. Everything else is either unwrapped (children kept, tag
 * dropped) or removed outright. Attributes are dropped wholesale except `href`
 * on `<a>`, which is additionally scheme-checked — that kills `onclick`,
 * `onerror`, `style` and `srcset` as a class rather than one name at a time.
 *
 * Deny-by-default is the point: a tag nobody listed is not rendered, so this
 * does not need updating every time a new dangerous element ships in browsers.
 */
final class RichTextSanitiser
{
    /**
     * Tags that survive. Anything else is unwrapped or (see STRIP_WITH_CONTENT)
     * removed.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'span', 'div',
        'strong', 'b', 'em', 'i', 'u', 'sub', 'sup', 'small',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
        'a', 'blockquote', 'code', 'pre',
    ];

    /**
     * Tags removed WITH their subtree — keeping the children of a <script>
     * would paste the script body in as visible text.
     *
     * @var array<int, string>
     */
    private const STRIP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'svg', 'math', 'template', 'noscript', 'base', 'link', 'meta', 'title',
    ];

    /** @var array<int, string> */
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto'];

    public function sanitise(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;

        // The XML encoding hint is the portable way to stop DOMDocument
        // treating the bytes as ISO-8859-1 and mangling non-ASCII; the
        // mb_convert_encoding('HTML-ENTITIES') trick is deprecated in PHP 8.2+.
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            // Unparseable input is not rendered at all. Failing closed here
            // costs a preview; failing open costs a session.
            return '';
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMNode) {
            return '';
        }

        $this->clean($body);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= (string) $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Depth-first clean. Iterates a SNAPSHOT of childNodes because removing or
     * replacing nodes mutates the live DOMNodeList mid-loop.
     */
    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (in_array($tag, self::STRIP_WITH_CONTENT, true)) {
                    $child->parentNode?->removeChild($child);

                    continue;
                }

                // Clean descendants first, so an unwrapped element hands up an
                // already-clean subtree.
                $this->clean($child);

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->unwrap($child);

                    continue;
                }

                $this->stripAttributes($child, $tag);

                continue;
            }

            // Comments can carry conditional-comment payloads. Text nodes are
            // re-encoded on save, so they are safe and left alone.
            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /** Replace an element with its children, preserving readable content. */
    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            $parent->insertBefore($child, $el);
        }

        $parent->removeChild($el);
    }

    /** Drop every attribute; re-add only a scheme-checked href on <a>. */
    private function stripAttributes(DOMElement $el, string $tag): void
    {
        $href = $tag === 'a' ? (string) $el->getAttribute('href') : '';

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $el->removeAttribute($attr->nodeName);
        }

        if ($tag !== 'a' || $href === '') {
            return;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        // A relative link has no scheme and stays relative. "javascript:alert(1)"
        // also parses with a scheme, but obfuscated forms may not, so the
        // no-scheme branch additionally refuses any colon before the first slash.
        if ($scheme === '' && preg_match('/^[^\/?#]*:/', $href) !== 1) {
            $el->setAttribute('href', $href);

            return;
        }

        if (in_array($scheme, self::ALLOWED_LINK_SCHEMES, true)) {
            $el->setAttribute('href', $href);
            $el->setAttribute('rel', 'noopener nofollow');
        }
    }
}
