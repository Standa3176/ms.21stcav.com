<?php

declare(strict_types=1);

use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-9c6 — webhooks:prune-receipts (H-1 remediation)
|--------------------------------------------------------------------------
|
| SECURITY-REVIEW.md (260606-q7h) H-1: webhook_receipts.raw_body holds raw
| Woo customer/order PII indefinitely (GDPR Art. 5(1)(e) violation). This
| command prunes per-topic retention windows nightly at 03:25 London.
|
| Defaults (CLI-overridable):
|   - order=30d (Woo order topic — line items + tokenised payment metadata)
|   - customer=7d (tightest GDPR window — email + billing/shipping/phone PII)
|   - other=90d (catch-all for future topic strings)
|
| Live mode hard-deletes rows; --dry-run reports per-topic candidate counts.
*/

it('registers webhooks:prune-receipts as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('webhooks:prune-receipts');
});

it('deletes only the 3 stale rows in a 2x3 topic-x-age matrix', function (): void {
    // 3 fresh (1 day old) — MUST stay
    $freshOrder = makeReceipt('order', ageDays: 1);
    $freshCustomer = makeReceipt('customer', ageDays: 1);
    $freshOther = makeReceipt('subscription', ageDays: 1);

    // 3 stale — MUST be deleted
    $staleOrder = makeReceipt('order', ageDays: 35);       // > 30d default
    $staleCustomer = makeReceipt('customer', ageDays: 10); // > 7d default
    $staleOther = makeReceipt('subscription', ageDays: 100); // > 90d default

    Artisan::call('webhooks:prune-receipts');

    // Fresh rows survive
    expect(WebhookReceipt::find($freshOrder->id))->not->toBeNull();
    expect(WebhookReceipt::find($freshCustomer->id))->not->toBeNull();
    expect(WebhookReceipt::find($freshOther->id))->not->toBeNull();

    // Stale rows gone
    expect(WebhookReceipt::find($staleOrder->id))->toBeNull();
    expect(WebhookReceipt::find($staleCustomer->id))->toBeNull();
    expect(WebhookReceipt::find($staleOther->id))->toBeNull();
});

it('--dry-run makes no DB changes and reports per-topic counts', function (): void {
    $staleOrder = makeReceipt('order', ageDays: 35);
    $staleCustomer = makeReceipt('customer', ageDays: 10);
    $staleOther = makeReceipt('subscription', ageDays: 100);

    $exit = Artisan::call('webhooks:prune-receipts', ['--dry-run' => true]);

    expect($exit)->toBe(0);

    // No deletes happened
    expect(WebhookReceipt::find($staleOrder->id))->not->toBeNull();
    expect(WebhookReceipt::find($staleCustomer->id))->not->toBeNull();
    expect(WebhookReceipt::find($staleOther->id))->not->toBeNull();

    $output = Artisan::output();
    expect(strtolower($output))->toContain('dry-run');
    expect($output)->toContain('order=');
    expect($output)->toContain('customer=');
    expect($output)->toContain('other=');
});

it('--customer-days=1 makes a 5-day-old customer receipt stale', function (): void {
    // 5 days old — under the 7-day default (would survive), but over the 1-day override
    $customer = makeReceipt('customer', ageDays: 5);

    Artisan::call('webhooks:prune-receipts', ['--customer-days' => 1]);

    expect(WebhookReceipt::find($customer->id))->toBeNull();
});

it('returns exit code 0 on success', function (): void {
    expect(Artisan::call('webhooks:prune-receipts'))->toBe(0);
});

it('live output includes per-topic deletion counts', function (): void {
    makeReceipt('order', ageDays: 35);
    makeReceipt('customer', ageDays: 10);
    makeReceipt('subscription', ageDays: 100);

    Artisan::call('webhooks:prune-receipts');

    $output = Artisan::output();
    expect($output)->toContain('order=1');
    expect($output)->toContain('customer=1');
    expect($output)->toContain('other=1');
});

// ── helpers ──

function makeReceipt(string $topic, int $ageDays): WebhookReceipt
{
    return WebhookReceipt::forceCreate([
        'source' => 'woo',
        'topic' => $topic,
        'delivery_id' => (string) Str::uuid(),
        'headers' => [],
        'raw_body' => '{}',
        'correlation_id' => 'test-'.uniqid(),
        'received_at' => now()->subDays($ageDays),
    ]);
}
