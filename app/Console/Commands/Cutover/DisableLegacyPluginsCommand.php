<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Cutover\Services\LegacyPluginDisabler;

/**
 * Phase 7 Plan 05 Task 2 — CUT-03 + CUT-07 legacy-plugin disabler artisan entry.
 *
 * Dry-run (default) — print exact WP-CLI commands ops will run:
 *   php artisan cutover:disable-legacy-plugins
 *
 * Live — requires TWO safety gates (D-18):
 *   1. CUTOVER_DISABLE_LIVE_ALLOWED=true in .env
 *   2. interactive confirmation prompt (`--no-interaction` auto-declines)
 *
 *   CUTOVER_DISABLE_LIVE_ALLOWED=true php artisan cutover:disable-legacy-plugins --live
 *
 * LIVE mode attempts WP-CLI over REST; when the endpoint is absent (common —
 * the wp-cli plugin is rarely installed), each command falls back to
 * 'manual_required' and ops runs it over SSH.
 */
class DisableLegacyPluginsCommand extends BaseCommand
{
    protected $signature = 'cutover:disable-legacy-plugins
        {--live : Execute disable (requires CUTOVER_DISABLE_LIVE_ALLOWED=true + interactive confirmation)}';

    protected $description = 'Deregister Stock Updater + itgalaxy Bitrix24 crons + deactivate plugins (CUT-03 + CUT-07)';

    public function perform(): int
    {
        $disabler = app(LegacyPluginDisabler::class);
        $live = (bool) $this->option('live');

        if ($live) {
            $envVarName = (string) config('cutover.disable_live_allowed_env_var', 'CUTOVER_DISABLE_LIVE_ALLOWED');
            $envValue = env($envVarName);
            if ($envValue !== true && $envValue !== 'true' && $envValue !== '1' && $envValue !== 1) {
                $this->error(sprintf(
                    '%s is not true in this environment. Refusing --live.',
                    $envVarName,
                ));

                return self::FAILURE;
            }

            if (! $this->confirm(
                'This will disable legacy plugins on the configured Woo site. Continue?',
                false,
            )) {
                $this->warn('Aborted by operator.');

                return self::FAILURE;
            }
        }

        $result = $disabler->run($live);

        $this->info('Mode: '.$result['mode']);
        $this->line('Commands:');
        foreach ($result['commands'] as $cmd) {
            $this->line('  '.$cmd);
        }

        if ($result['executed']) {
            $this->line('');
            $this->line('Per-command results:');
            foreach ($result['results'] as $row) {
                $this->line(sprintf('  [%s] %s', $row['status'], $row['command']));
            }
        }

        return self::SUCCESS;
    }
}
