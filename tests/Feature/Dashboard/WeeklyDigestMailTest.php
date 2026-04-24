<?php

declare(strict_types=1);

use App\Domain\Dashboard\Services\WeeklyDigestComposer;
use App\Mail\WeeklyDigestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 04 Task 2 — WeeklyDigestMail + Composer
|--------------------------------------------------------------------------
|
| Covers (plan <behavior> C1..C6 + M1..M3):
|   - Composer returns 5-section payload keyed [sync, margin, crm, auto_create, competitor]
|   - Mail renders both HTML + plain-text views
|   - Subject includes YYYY-MM-DD date
*/

function makeDigestPayload(): array
{
    return (new WeeklyDigestComposer())->compose();
}

it('composes a 5-section payload keyed by domain', function (): void {
    $payload = makeDigestPayload();

    expect($payload)->toHaveKeys(['window_start', 'window_end', 'sync', 'margin', 'crm', 'auto_create', 'competitor']);
});

it('sync section exposes run counts + updated + failed', function (): void {
    $payload = makeDigestPayload();
    $sync = $payload['sync'];

    expect($sync)->toHaveKeys(['runs_completed', 'updated_skus', 'failed_skus']);
    expect($sync['runs_completed'])->toBeInt();
    expect($sync['updated_skus'])->toBeInt();
    expect($sync['failed_skus'])->toBeInt();
});

it('margin section exposes created + approved + largest delta', function (): void {
    $payload = makeDigestPayload();
    $margin = $payload['margin'];

    expect($margin)->toHaveKeys(['created_count', 'approved_count', 'largest_delta_bps']);
});

it('crm section exposes pushed + retries + dlq counts', function (): void {
    $payload = makeDigestPayload();
    $crm = $payload['crm'];

    expect($crm)->toHaveKeys(['deals_pushed', 'retries', 'failed_to_suggestions']);
});

it('auto-create section groups rejections by reason', function (): void {
    $payload = makeDigestPayload();
    $ac = $payload['auto_create'];

    expect($ac)->toHaveKeys(['drafts_created', 'approved_count', 'rejected_count', 'rejections_by_reason']);
    expect($ac['rejections_by_reason'])->toBeArray();
});

it('competitor section exposes ingest + parse + top-movers', function (): void {
    $payload = makeDigestPayload();
    $comp = $payload['competitor'];

    expect($comp)->toHaveKeys(['ingested_runs', 'parse_errors', 'top_3_movers']);
});

it('mail subject includes the current date', function (): void {
    $mail = new WeeklyDigestMail(makeDigestPayload());
    $envelope = $mail->envelope();

    expect($envelope->subject)->toMatch('/MeetingStore Ops Weekly Digest — \d{4}-\d{2}-\d{2}/');
});

it('mail renders the HTML view with all 5 section headings', function (): void {
    $mail = new WeeklyDigestMail(makeDigestPayload());
    $rendered = $mail->render();

    expect($rendered)->toContain('<html')
        ->toContain('Supplier Sync')
        ->toContain('Margin Analysis')
        ->toContain('CRM Pushes')
        ->toContain('Product Auto-Create')
        ->toContain('Competitor Analysis');
});

it('mail renders a plain-text fallback view', function (): void {
    $mail = new WeeklyDigestMail(makeDigestPayload());

    // Render text version via Laravel's Mailable::render()/textView flow —
    // force text view render by looking at the configured content.
    $content = $mail->content();
    expect($content->text)->toBe('emails.weekly-digest-text');

    // Compile the text view with the payload to verify it doesn't blow up.
    $text = view($content->text, ['payload' => makeDigestPayload()])->render();
    expect($text)
        ->toContain('MEETINGSTORE OPS')
        ->toContain('Supplier Sync')
        ->toContain('Margin Analysis')
        ->toContain('CRM Pushes')
        ->toContain('Product Auto-Create')
        ->toContain('Competitor Analysis');
});
