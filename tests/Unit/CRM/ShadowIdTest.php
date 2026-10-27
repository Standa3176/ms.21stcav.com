<?php

declare(strict_types=1);

use App\Domain\CRM\Support\ShadowId;

it('detects shadow sentinel ids', function (): void {
    expect(ShadowId::is('SHADOW-0b4e1c2a-aaaa'))->toBeTrue()
        ->and(ShadowId::is('34582'))->toBeFalse()
        ->and(ShadowId::is(''))->toBeFalse()
        ->and(ShadowId::is(null))->toBeFalse()
        ->and(ShadowId::is('shadow-x'))->toBeFalse();
});
