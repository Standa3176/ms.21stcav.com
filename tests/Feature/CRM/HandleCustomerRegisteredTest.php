<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\PushCustomerToBitrixJob;
use App\Domain\CRM\Listeners\HandleCustomerRegistered;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 1 — HandleCustomerRegistered listener
|--------------------------------------------------------------------------
|
| Same shape as HandleOrderReceived but topic = customer.created|updated and
| dispatches PushCustomerToBitrixJob.
*/

function makeCustomerReceipt(string $topic, array $body = []): WebhookReceipt
{
    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => $topic,
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => ['x-wc-webhook-topic' => [$topic]],
        'raw_body' => json_encode(array_merge(['id' => 7, 'email' => 'c@example.com'], $body)),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'accepted',
    ]);
}

it('dispatches PushCustomerToBitrixJob with topic=customer.created', function (): void {
    Queue::fake();
    $receipt = makeCustomerReceipt('customer.created');

    (new HandleCustomerRegistered())->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    Queue::assertPushedOn('crm-bitrix', PushCustomerToBitrixJob::class, function (PushCustomerToBitrixJob $job) use ($receipt) {
        return $job->webhookReceiptId === $receipt->id
            && $job->topic === 'customer.created';
    });
});

it('dispatches PushCustomerToBitrixJob with topic=customer.updated', function (): void {
    Queue::fake();
    $receipt = makeCustomerReceipt('customer.updated');

    (new HandleCustomerRegistered())->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    Queue::assertPushed(PushCustomerToBitrixJob::class, fn (PushCustomerToBitrixJob $job) => $job->topic === 'customer.updated');
});

it('logs warning and skips dispatch on unsupported topic (customer.deleted)', function (): void {
    Queue::fake();
    Log::spy();
    $receipt = makeCustomerReceipt('customer.deleted');

    (new HandleCustomerRegistered())->handle(new CustomerRegistered($receipt->id, $receipt->delivery_id));

    Queue::assertNotPushed(PushCustomerToBitrixJob::class);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});
