<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\LogBatch;

/**
 * Every artisan command that performs cross-module work extends this.
 *
 * Ensures correlation_id threads through the command's entire execution path
 * (audit_log, integration_events, dispatched jobs) — same seam HTTP requests
 * get via AttachCorrelationId middleware.
 *
 * Subclasses implement execute() instead of handle().
 */
abstract class BaseCommand extends Command
{
    final public function handle(): int
    {
        $correlationId = (string) Str::uuid();
        Context::add('correlation_id', $correlationId);

        LogBatch::startBatch();
        LogBatch::setBatch($correlationId);

        $this->info("Correlation: {$correlationId}");

        try {
            return $this->perform();
        } finally {
            LogBatch::endBatch();
        }
    }

    /**
     * Command body — implement per-command logic here, not in handle().
     *
     * Named perform() to avoid collision with Symfony's concrete Command::execute()
     * and Laravel's Command::run() / call() helpers — both cannot be redeclared abstract.
     */
    abstract protected function perform(): int;
}
