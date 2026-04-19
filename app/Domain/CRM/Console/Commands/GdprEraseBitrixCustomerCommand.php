<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Jobs\EraseBitrixContactJob;
use App\Domain\CRM\Models\BitrixEntityMap;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Phase 4 Plan 05 Task 2 — GDPR right-to-erasure CLI (CRM-13).
 *
 * Dual-entry point: this CLI + the Filament `EraseCustomerAction` bulk action
 * on `CrmPushLogResource`. Both dispatch EraseBitrixContactJob.
 *
 * Confirmation: operator MUST type exactly `ERASE` (unless --no-confirm, which
 * exists for automated ops playbooks that already carry sign-off). Miswriting
 * the confirmation aborts the command without any side effects.
 *
 * --dry-run performs a read-only lookup (by email_hash on BitrixEntityMap)
 * and prints what WOULD be scrubbed. No job is dispatched, no audit row is
 * written.
 */
final class GdprEraseBitrixCustomerCommand extends BaseCommand
{
    protected $signature = 'gdpr:erase-bitrix-customer
        {--email= : Required email address to erase}
        {--no-confirm : Skip the "type ERASE" prompt (automated ops playbooks only)}
        {--dry-run : Show what would be erased without mutating}';

    protected $description = 'GDPR right-to-erasure for a Bitrix customer. Scrubs PII in place; preserves financial records (HMRC retention).';

    protected function perform(): int
    {
        $email = (string) ($this->option('email') ?? '');
        $email = trim($email);

        if ($email === '') {
            $this->error('Error: --email is required.');

            return SymfonyCommand::FAILURE;
        }

        $normalised = mb_strtolower($email);
        $hash = hash('sha256', $normalised);

        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_CONTACT)
            ->where('email_hash', $hash)
            ->first();

        if ($map === null) {
            $this->info("No Bitrix Contact found for email — nothing to erase. (email_hash={$hash})");

            return SymfonyCommand::SUCCESS;
        }

        $this->info("Bitrix Contact found: bitrix_id={$map->bitrix_id}, woo_id={$map->woo_id}");

        if ((bool) $this->option('dry-run')) {
            $this->line('DRY-RUN: would scrub 18 Contact PII fields + iterate linked Deals.');
            $this->line('        (no EraseBitrixContactJob dispatched)');

            return SymfonyCommand::SUCCESS;
        }

        if (! (bool) $this->option('no-confirm')) {
            $answer = (string) $this->ask('Type "ERASE" to confirm irreversible PII scrub on this Contact + linked Deals');
            if ($answer !== 'ERASE') {
                $this->warn('Confirmation phrase did not match; aborting. No mutations performed.');

                return SymfonyCommand::FAILURE;
            }
        }

        $actorId = auth()->id();
        $correlationId = (string) (Context::get('correlation_id') ?? '');

        EraseBitrixContactJob::dispatch(
            $normalised,
            $actorId,
            $correlationId !== '' ? $correlationId : null,
        );

        $this->info('GDPR erasure job dispatched. Check gdpr_erasure_log for confirmation.');
        $this->line('  email_hash     : '.$hash);
        $this->line('  correlation_id : '.($correlationId !== '' ? $correlationId : '(none)'));

        return SymfonyCommand::SUCCESS;
    }
}
