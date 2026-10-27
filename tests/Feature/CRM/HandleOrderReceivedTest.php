<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\CRM\Listeners\HandleOrderReceived;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 1 — HandleOrderReceived listener
|--------------------------------------------------------------------------
|
| D-08: the listener's sole responsibility is to (a) load the receipt,
| (b) route on x-wc-webhook-topic header, (c) dispatch the push job on the
| crm-bitrix queue. Unsupported topics log a warning and short-circuit.
*/

function makeOrderReceipt(string $topic, array $orderBody = []): WebhookReceipt
{
    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => $topic,
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => ['x-wc-webhook-topic' => [$topic]],
        'raw_body' => json_encode(array_merge(['id' => 42], $orderBody)),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'accepted',
    ]);
}

it('dispatches PushOrderToBitrixJob with topic=order.created', function (): void {
    Queue::fake();
    $receipt = makeOrderReceipt('order.created');

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertPushedOn('crm-bitrix', PushOrderToBitrixJob::class, function (PushOrderToBitrixJob $job) use ($receipt) {
        return $job->webhookReceiptId === $receipt->id
            && $job->topic === 'order.created'
            && $job->updateMissRetries === 0;
    });
});

it('dispatches PushOrderToBitrixJob with topic=order.updated', function (): void {
    Queue::fake();
    $receipt = makeOrderReceipt('order.updated');

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertPushed(PushOrderToBitrixJob::class, function (PushOrderToBitrixJob $job) {
        return $job->topic === 'order.updated';
    });
});

it('logs warning and skips dispatch on unsupported topic (order.deleted)', function (): void {
    Queue::fake();
    Log::spy();
    $receipt = makeOrderReceipt('order.deleted');

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertNotPushed(PushOrderToBitrixJob::class);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

function makeSourcedReceipt(?string $source): WebhookReceipt
{
    $headers = ['x-wc-webhook-topic' => ['order.created']];
    if ($source !== null) {
        $headers['x-wc-webhook-source'] = [$source];
    }

    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'order.created',
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => $headers,
        'raw_body' => json_encode(['id' => 42]),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'accepted',
    ]);
}

it('dispatches when the webhook source host is allowed (www. normalised)', function (string $source): void {
    config(['services.woo.allowed_webhook_sources' => ['https://meetingstore.co.uk']]);
    Queue::fake();
    $receipt = makeSourcedReceipt($source);

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertPushed(PushOrderToBitrixJob::class);
})->with(['https://meetingstore.co.uk/', 'https://www.meetingstore.co.uk/']);

it('drops a foreign webhook source with a warning and an integration event', function (): void {
    config(['services.woo.allowed_webhook_sources' => ['https://meetingstore.co.uk']]);
    Queue::fake();
    Log::spy();
    $receipt = makeSourcedReceipt('https://keep.meetingstore.co.uk/');

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertNotPushed(PushOrderToBitrixJob::class);
    Log::shouldHaveReceived('warning')->atLeast()->once();
    $event = \App\Foundation\Integration\Models\IntegrationEvent::where('operation', 'webhook.order.foreign_source')->first();
    expect($event)->not->toBeNull();
    expect($event->request_body['source_host'])->toBe('keep.meetingstore.co.uk');
});

it('dispatches receipts that carry no source header (backfill)', function (): void {
    config(['services.woo.allowed_webhook_sources' => ['https://meetingstore.co.uk']]);
    Queue::fake();
    $receipt = makeSourcedReceipt(null);

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertPushed(PushOrderToBitrixJob::class);
});

it('dispatches but warns when no allowed sources are configured (guard disabled)', function (): void {
    config(['services.woo.allowed_webhook_sources' => []]);
    Queue::fake();
    Log::spy();
    $receipt = makeSourcedReceipt('https://keep.meetingstore.co.uk/');

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertPushed(PushOrderToBitrixJob::class);
    Log::shouldHaveReceived('warning')->once();
});

it('falls back to receipt.topic column when header missing', function (): void {
    Queue::fake();
    $receipt = WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'order.created',
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => [],
        'raw_body' => json_encode(['id' => 99]),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'accepted',
    ]);

    (new HandleOrderReceived())->handle(new OrderReceived($receipt->id, $receipt->delivery_id));

    Queue::assertPushed(PushOrderToBitrixJob::class);
});
