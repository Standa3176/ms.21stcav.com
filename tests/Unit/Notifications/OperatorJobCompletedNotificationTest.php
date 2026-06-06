<?php

declare(strict_types=1);

use App\Notifications\OperatorJobCompletedNotification;

/**
 * Quick task 260606-p4q — Pest unit coverage for the generic operator
 * job-completed notification. Covers via() channel selection, toDatabase()
 * payload shape (5 keys), level→icon mapping (4 levels + default), and
 * the null-url constructor edge case.
 *
 * Reusable: future ops commands (PruneOrphanSuggestionsCommand,
 * products:resync-to-woo, etc.) wire $user->notify(new
 * OperatorJobCompletedNotification(...)) without subclassing.
 */
it('via() returns database channel for any notifiable', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 'Test',
        body: 'Body',
    );

    expect($notification->via(new stdClass))->toBe(['database']);
});

it('toDatabase() returns exactly the 5-key payload', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 'Pipeline complete',
        body: '10/12 SKUs processed',
        level: 'success',
        url: '/admin/products',
    );

    $payload = $notification->toDatabase(new stdClass);

    expect(array_keys($payload))->toEqualCanonicalizing(['title', 'body', 'level', 'url', 'icon']);
    expect($payload['title'])->toBe('Pipeline complete');
    expect($payload['body'])->toBe('10/12 SKUs processed');
    expect($payload['level'])->toBe('success');
    expect($payload['url'])->toBe('/admin/products');
});

it('maps level success to heroicon-o-check-circle', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 't', body: 'b', level: 'success',
    );

    expect($notification->toDatabase(new stdClass)['icon'])
        ->toBe('heroicon-o-check-circle');
});

it('maps level danger to heroicon-o-x-circle', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 't', body: 'b', level: 'danger',
    );

    expect($notification->toDatabase(new stdClass)['icon'])
        ->toBe('heroicon-o-x-circle');
});

it('maps level warning to heroicon-o-exclamation-triangle', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 't', body: 'b', level: 'warning',
    );

    expect($notification->toDatabase(new stdClass)['icon'])
        ->toBe('heroicon-o-exclamation-triangle');
});

it('maps level info to heroicon-o-information-circle', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 't', body: 'b', level: 'info',
    );

    expect($notification->toDatabase(new stdClass)['icon'])
        ->toBe('heroicon-o-information-circle');
});

it('produces null url when constructor omits it', function (): void {
    $notification = new OperatorJobCompletedNotification(
        title: 't', body: 'b',
    );

    $payload = $notification->toDatabase(new stdClass);

    expect($payload)->toHaveKey('url');
    expect($payload['url'])->toBeNull();
});
