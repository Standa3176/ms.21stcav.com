<?php

declare(strict_types=1);

use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Context;
use Spatie\Activitylog\Models\Activity;

it('records a system-level activity with correlation_id in properties', function () {
    Context::add('correlation_id', 'audit-cid-1');

    app(Auditor::class)->record('test.action', ['foo' => 'bar']);

    $activity = Activity::where('log_name', 'system')
        ->where('description', 'test.action')
        ->latest()
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties['correlation_id'])->toBe('audit-cid-1');
    expect($activity->properties['foo'])->toBe('bar');
    expect($activity->properties)->toHaveKey('occurred_at');
});

it('falls back gracefully when Context has no correlation_id', function () {
    Context::forget('correlation_id');

    app(Auditor::class)->record('orphan.action', []);

    $activity = Activity::where('description', 'orphan.action')->latest()->first();
    expect($activity)->not->toBeNull();
    // correlation_id key present but value is null.
    expect($activity->properties)->toHaveKey('correlation_id');
    expect($activity->properties['correlation_id'])->toBeNull();
});

it('merges custom context over defaults but preserves correlation_id + occurred_at keys', function () {
    Context::add('correlation_id', 'audit-cid-2');

    app(Auditor::class)->record('merge.test', [
        'extra_field' => 'hello',
        'nested' => ['key' => 'value'],
    ]);

    $activity = Activity::where('description', 'merge.test')->latest()->first();

    expect($activity->properties)->toHaveKey('correlation_id', 'audit-cid-2');
    expect($activity->properties)->toHaveKey('occurred_at');
    expect($activity->properties)->toHaveKey('extra_field', 'hello');
    expect($activity->properties['nested'])->toBe(['key' => 'value']);
});
