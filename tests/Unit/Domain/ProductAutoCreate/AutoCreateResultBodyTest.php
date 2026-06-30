<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Jobs\RunAutoCreatePipelineJob;

/*
|--------------------------------------------------------------------------
| Quick task 260630-ry6 — formatAutoCreateResultBody() coverage
|--------------------------------------------------------------------------
|
| Pure unit coverage for the notification body/level builder used by
| RunAutoCreatePipelineJob to surface the command's per-SKU run summary
| back into the operator's bell notification. No DB, no Filament — call
| the static helper directly.
|
| Cases:
|   - null summary (older command / cache miss) → generic fallback + 'info'
|   - all created (auto-publish) → counts + brands + published line + 'success'
|   - partial (some created, some skipped) → skip line + 'warning'
|   - zero created → skip line + 'danger'
|   - >10 skipped → first 10 shown + "(+N more)"
|
*/

it('falls back to a generic info line when the summary is null', function (): void {
    [$body, $level] = RunAutoCreatePipelineJob::formatAutoCreateResultBody(null, 5, false);

    expect($level)->toBe('info');
    expect($body)->toContain('5 selected SKU(s)');
    expect($body)->toContain('/admin/auto-create-reviews');
});

it('reports created counts, brands and the Woo-publish line when all created', function (): void {
    $summary = [
        'created' => 2,
        'created_skus' => ['SKU-A', 'SKU-B'],
        'by_brand' => ['BrightSign' => 1, 'Lindy' => 1],
        'skipped' => [
            'not_sourceable' => [],
            'no_manufacturer' => [],
            'brand_not_on_woo' => [],
        ],
        'auto_publish' => ['published' => 2, 'shadowed' => 0, 'failed' => 0],
    ];

    [$body, $level] = RunAutoCreatePipelineJob::formatAutoCreateResultBody($summary, 2, true);

    expect($level)->toBe('success');
    expect($body)->toContain('Created/updated 2 (BrightSign, Lindy)');
    expect($body)->toContain('Published to Woo: 2');
});

it('flags a partial run as warning with the brand-not-on-Woo skip line', function (): void {
    $summary = [
        'created' => 1,
        'created_skus' => ['KEEP-1'],
        'by_brand' => ['Yealink' => 1],
        'skipped' => [
            'not_sourceable' => [],
            'no_manufacturer' => [],
            'brand_not_on_woo' => ['Trantec (S4.04-B-EB-GD5)'],
        ],
        'auto_publish' => null,
    ];

    [$body, $level] = RunAutoCreatePipelineJob::formatAutoCreateResultBody($summary, 2, false);

    expect($level)->toBe('warning');
    expect($body)->toContain('brand not on Woo');
    expect($body)->toContain('Trantec (S4.04-B-EB-GD5)');
});

it('flags a zero-created run as danger and lists the skip reason', function (): void {
    $summary = [
        'created' => 0,
        'created_skus' => [],
        'by_brand' => [],
        'skipped' => [
            'not_sourceable' => ['A75DM66D', '65DM66D'],
            'no_manufacturer' => [],
            'brand_not_on_woo' => [],
        ],
        'auto_publish' => null,
    ];

    [$body, $level] = RunAutoCreatePipelineJob::formatAutoCreateResultBody($summary, 2, false);

    expect($level)->toBe('danger');
    expect($body)->toContain('Created/updated 0');
    expect($body)->toContain('not sourceable');
    expect($body)->toContain('A75DM66D, 65DM66D');
});

it('truncates a long skip list to the first 10 with a "+N more" suffix', function (): void {
    $skus = array_map(static fn (int $i): string => "SKU-{$i}", range(1, 13));
    $summary = [
        'created' => 0,
        'created_skus' => [],
        'by_brand' => [],
        'skipped' => [
            'not_sourceable' => $skus,
            'no_manufacturer' => [],
            'brand_not_on_woo' => [],
        ],
        'auto_publish' => null,
    ];

    [$body, $level] = RunAutoCreatePipelineJob::formatAutoCreateResultBody($summary, 13, false);

    expect($level)->toBe('danger');
    expect($body)->toContain('SKU-1, SKU-2, SKU-3, SKU-4, SKU-5, SKU-6, SKU-7, SKU-8, SKU-9, SKU-10');
    expect($body)->toContain('(+3 more)');
    expect($body)->not->toContain('SKU-11');
});
