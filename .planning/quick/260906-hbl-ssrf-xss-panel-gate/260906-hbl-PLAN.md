---
id: 260906-hbl
slug: ssrf-xss-panel-gate
date: 2026-09-06
status: complete
---

# Security review — three fixes at the untrusted-data boundaries

Operator asked for a deep security check of the app and fixes for anything found.

## Method

Reviewed the attack surface rather than grepping for keywords: route files and their
middleware, the webhook signature path, panel authentication and policy coverage,
raw-SQL construction, command execution, deserialization, secrets at rest, output
escaping, and every place the app fetches a URL or renders third-party content.

**What was already correct** (recorded so a later reader doesn't re-audit it):

- `VerifyWooHmacSignature` — raw body, `hash_equals`, fails closed when the secret
  is unset. Correct.
- Raw SQL — every user-influenced value is bound. The one interpolated fragment
  (`SuggestionResource:426`) comes from a closed `match` with a null default.
- No `shell_exec`/`eval`/`unserialize` on any request path; the only `exec()` calls
  are in a developer CLI tool and use `escapeshellarg`.
- Credentials at rest — `IntegrationCredential` and `CompetitorFtpCredential` use
  native `encrypted` casts and deliberately exclude the ciphertext from activity
  logging.
- `ExportDownloadController` — signed + auth + `basename()`, no traversal.
- Horizon is gated to `admin`; Pulse carries its `Authorize` middleware.
- Policies are hand-written with explicit role checks; no `Gate::before` bypass.

## Fix 1 — SSRF via supplier-controlled image URLs (the significant one)

`ProductImageFetcher` issued a server-side HEAD+GET against a URL taken from
`$supplierData['image_url']` (`CreateWooProductJob:281`) — a value out of a supplier
feed delivered by FTP/CSV — or from `WebImageSearchClient`, whose results are steered
by a prompt built from that same supplier text.

There was **no validation of any kind**: no scheme check, no host check, no
private-range check, and redirects were followed. A feed row carrying
`http://169.254.169.254/…` or `http://127.0.0.1:6379/` made the queue worker issue
that request from inside the network. Worse, `logAttempt()` wrote the resulting HTTP
status into `integration_logs`, turning a blind SSRF into a readable oracle.

**New `OutboundUrlGuard`** rejects, failing closed:

- any scheme but http/https (`file://`, `gopher://`, `data:`)
- credentials in the authority (`http://trusted-cdn.com@127.0.0.1/` reads as the CDN
  to a human and resolves to loopback for cURL)
- literal or resolved addresses in loopback, private, link-local, unique-local,
  multicast or reserved ranges, IPv4 and IPv6
- a host answering with a mix of public and private addresses

Wired into `ProductImageFetcher` in two places: before the first request leaves the
host, and on **every redirect hop** via Guzzle's `on_redirect` — a 3-hop budget is
not a guard, since a public URL is free to 302 to `127.0.0.1`.

**What this does not claim.** A hostile DNS server can answer public to the guard and
private to cURL moments later (rebinding/TOCTOU). Closing that needs pinned resolution
plus a manual Host header, which breaks SNI on the CDNs this pipeline depends on. The
per-hop check narrows the window; egress filtering is the real answer and belongs in
infrastructure. This is stated in the class docblock rather than left implied.

## Fix 2 — stored XSS in the product preview

`preview/product.blade.php` rendered `{!! $product->short_description !!}` and
`{!! $product->long_description !!}` raw. The controller docblock justified it as
"our own AI-generated HTML" — that premise is wrong: the model writes those columns
from a prompt built out of supplier feed text, so the content originates with a third
party and lands in an authenticated admin's browser inside a Filament session.

Escaping is not available — the columns are HTML by design, they become the Woo
description. **New `RichTextSanitiser`** parses with DOMDocument and allow-lists:
known-good tags survive, unknown tags are unwrapped (content kept), dangerous tags are
removed with their subtree, and **all** attributes are dropped except a scheme-checked
`href` on `<a>`. Dropping attributes wholesale kills `onclick`/`onerror`/`style` as a
class rather than one name at a time, and deny-by-default means it needs no update
when a new dangerous element ships in browsers.

Applied at render. The stored value and the Woo publish payload are unchanged —
altering what customers see is a pipeline behaviour change and was not in scope.
**Open question for the operator**, deliberately not actioned: the same unsanitised
HTML is also PUT to WooCommerce, where whether `wp_kses` strips it depends on the API
user's `unfiltered_html` capability. Worth verifying on the WordPress side.

## Fix 3 — any authenticated user could reach the admin panel

`User::canAccessPanel()` returned a bare `true`, so per-resource policies were the only
thing between a role-less account and the data — one Resource shipped without a policy
and it was exposed to everyone. Now requires at least one assigned role. Registration
is closed (the panel calls `->login()` and never `->registration()`), so every
legitimate user is created deliberately and given a role at that point.

## Verification

- `tests/Unit/Security/OutboundUrlGuardTest.php` — 19 cases. DNS is stubbed through a
  constructor seam, because a test that passes because a lookup happened to fail is
  worse than no test.
- `tests/Unit/Security/RichTextSanitiserTest.php` — 11 cases, including that ordinary
  description markup survives intact; a sanitiser that eats `<ul>` would be reverted
  in a week.
- `tests/Feature/Security/PanelAccessTest.php` — 6 cases.
- `ProductImageFetcherTest` gains the integration half: a blocked URL must produce
  **no HTTP request at all** (`Http::assertNothingSent()`), not a request that merely
  fails — a failing request still leaks reachability through timing.

Two existing test files needed a stub resolver bound in `beforeEach`: they use
`Http::fake()` so nothing is really contacted, but the guard resolves DNS for real and
correctly rejected `supplier.cdn.com` as unresolvable.

## Not done

`composer audit` for dependency CVEs — composer is not installed on this machine. It
needs running on the server; the command is in the handover.
