<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\EraseBitrixContactJob;
use App\Domain\CRM\Models\BitrixEntityMap;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 05 Task 2 — gdpr:erase-bitrix-customer CLI tests (CRM-13).
|--------------------------------------------------------------------------
|
| --email required, --dry-run suppresses job dispatch, --no-confirm skips
| the ERASE prompt, mistyped ERASE aborts cleanly without side effects.
*/

function seedContactMapForCommand(string $email, string $bitrixId = 'C777'): BitrixEntityMap
{
    return BitrixEntityMap::create([
        'entity_type' => BitrixEntityMap::ENTITY_CONTACT,
        'woo_id' => 800,
        'bitrix_id' => $bitrixId,
        'email_hash' => hash('sha256', mb_strtolower(trim($email))),
        'last_pushed_at' => now()->subDay(),
        'created_via' => BitrixEntityMap::VIA_PUSH,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — --email required
// ══════════════════════════════════════════════════════════════════════════════

it('requires --email flag', function (): void {
    $this->artisan('gdpr:erase-bitrix-customer')
        ->expectsOutputToContain('--email is required')
        ->assertExitCode(1);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — --dry-run does NOT dispatch the job
// ══════════════════════════════════════════════════════════════════════════════

it('dry-run does not dispatch EraseBitrixContactJob', function (): void {
    Queue::fake();
    seedContactMapForCommand('dry@test.com');

    $this->artisan('gdpr:erase-bitrix-customer', ['--email' => 'dry@test.com', '--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    Queue::assertNotPushed(EraseBitrixContactJob::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — --no-confirm skips the ERASE prompt and dispatches the job
// ══════════════════════════════════════════════════════════════════════════════

it('--no-confirm skips the ERASE prompt and dispatches job', function (): void {
    Queue::fake();
    seedContactMapForCommand('noconfirm@test.com');

    $this->artisan('gdpr:erase-bitrix-customer', [
        '--email' => 'noconfirm@test.com',
        '--no-confirm' => true,
    ])->assertExitCode(0);

    Queue::assertPushed(EraseBitrixContactJob::class, function (EraseBitrixContactJob $job): bool {
        return $job->email === 'noconfirm@test.com'
            && $job->queue === 'default';
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — mistyped ERASE aborts without dispatching
// ══════════════════════════════════════════════════════════════════════════════

it('prompt-based confirm rejects non-ERASE input', function (): void {
    Queue::fake();
    seedContactMapForCommand('prompt@test.com');

    $this->artisan('gdpr:erase-bitrix-customer', ['--email' => 'prompt@test.com'])
        ->expectsQuestion('Type "ERASE" to confirm irreversible PII scrub on this Contact + linked Deals', 'no thanks')
        ->expectsOutputToContain('did not match')
        ->assertExitCode(1);

    Queue::assertNotPushed(EraseBitrixContactJob::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — no-match email exits 0 with info message, no dispatch
// ══════════════════════════════════════════════════════════════════════════════

it('exits 0 with info when no Bitrix contact exists for email', function (): void {
    Queue::fake();

    $this->artisan('gdpr:erase-bitrix-customer', [
        '--email' => 'ghost@nowhere.com',
        '--no-confirm' => true,
    ])
        ->expectsOutputToContain('No Bitrix Contact found')
        ->assertExitCode(0);

    Queue::assertNotPushed(EraseBitrixContactJob::class);
});
