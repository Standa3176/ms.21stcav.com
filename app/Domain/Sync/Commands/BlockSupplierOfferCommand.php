<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Sync\Models\ProductSupplierBlock;
use App\Domain\Sync\Models\Supplier;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 261027-duz — supplier:block-offer {sku} {supplier}.
 *
 * Operator CLI for per-(match key, supplier) offer blocks. A block makes that
 * supplier's offer for that key invisible to cost AND stock in supplier:db-sync
 * and products:hydrate-stock-from-offers. Use for supplier SKU collisions
 * (incident 2026-10-27: Midwich's code 1301222 is an unrelated £7.20 item; local
 * 1301222 is a £88.20 Yealink T74U from Nimans).
 *
 * Blocks are per MATCH KEY: if a product matches via an alternative SKU alias,
 * block the alias key too.
 *
 *   php artisan supplier:block-offer 1301222 Midwich --reason="unrelated £7.20 item"
 *   php artisan supplier:block-offer 1301222 Midwich --remove
 */
final class BlockSupplierOfferCommand extends BaseCommand
{
    protected $signature = 'supplier:block-offer
        {sku : Match key (local SKU or alias)}
        {supplier : suppliers.supplier_id or supplier name}
        {--reason= : Why this offer is wrong (required when adding)}
        {--remove : Delete the block instead}';

    protected $description = 'Block (or unblock) one supplier\'s offer for one SKU match key — cost + stock (261027-duz).';

    protected function perform(): int
    {
        $sku = strtolower(trim((string) $this->argument('sku')));
        if ($sku === '') {
            $this->error('SKU is required.');

            return SymfonyCommand::FAILURE;
        }

        $supplierId = $this->resolveSupplierId(trim((string) $this->argument('supplier')));
        if ($supplierId === '') {
            $this->error('Supplier is required.');

            return SymfonyCommand::FAILURE;
        }

        if ((bool) $this->option('remove')) {
            $deleted = ProductSupplierBlock::query()
                ->where('sku', $sku)
                ->where('supplier_id', $supplierId)
                ->delete();

            if ($deleted === 0) {
                $this->warn("No block found for sku={$sku} supplier={$supplierId} — nothing removed.");
            } else {
                $this->info("Removed block sku={$sku} supplier={$supplierId}.");
                $this->line("Next: php artisan supplier:explain-cost {$sku}  then  php artisan supplier:db-sync");
            }

            return SymfonyCommand::SUCCESS;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required when adding a block (why is this offer wrong?).');

            return SymfonyCommand::FAILURE;
        }

        $block = ProductSupplierBlock::updateOrCreate(
            ['sku' => $sku, 'supplier_id' => $supplierId],
            ['reason' => $reason],
        );

        $this->info(sprintf(
            'Blocked: id=%d sku=%s supplier=%s product_id=%s reason="%s"',
            $block->id,
            $block->sku,
            $block->supplier_id,
            $block->product_id ?? 'null',
            $block->reason,
        ));
        $this->line("Next: php artisan supplier:explain-cost {$sku}  then  php artisan supplier:db-sync");

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Exact supplier_id first, then a case-insensitive suppliers.name match,
     * else the raw value (feed supplier ids may be absent from `suppliers`).
     */
    private function resolveSupplierId(string $arg): string
    {
        if ($arg === '') {
            return '';
        }

        if (Supplier::query()->where('supplier_id', $arg)->exists()) {
            return $arg;
        }

        $byName = Supplier::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($arg)])
            ->value('supplier_id');

        if ($byName !== null) {
            $this->line("Resolved supplier '{$arg}' → supplier_id {$byName}.");

            return (string) $byName;
        }

        $this->warn("'{$arg}' is not a known supplier id or name — storing it as a raw supplier_id.");

        return $arg;
    }
}
