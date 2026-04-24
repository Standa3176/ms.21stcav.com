<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| DEPTRAC WpDirectDb BAN — REGRESSION GUARD
|--------------------------------------------------------------------------
| Phase 2 Plan 05 / SYNC-04 forbids any direct database-facade use when
| targeting the WordPress database. This file MUST NOT import or call the
| Laravel DB facade. Plan 07-05 Task 2 ships a static-scan regression test
| (DL4 in DisableLegacyPluginsCommandTest) that greps this file for literal
| facade references and fails the build if any appear.
|
| All legacy-plugin deactivation happens via:
|   - WP-CLI over REST (when the WP site exposes a wp-cli-command endpoint),
|     or
|   - documented ops one-liners that the operator runs manually on the VPS.
|
| Never add a direct database-facade call here.
|--------------------------------------------------------------------------
*/

/**
 * Phase 7 Plan 05 Task 2 — CUT-03 + CUT-07 legacy-plugin disabler.
 *
 * Produces the exact WP-CLI commands required to:
 *   1. Unschedule the 3 legacy cron hooks (config cutover.legacy_cron_hooks):
 *      - stock_updater_daily_sync
 *      - itgalaxy_bitrix24_send
 *      - itgalaxy_bitrix24_status
 *   2. Deactivate the 2 legacy plugins (config cutover.legacy_plugins):
 *      - stock-updater
 *      - woocommerce-bitrix24-integration
 *
 * Dry-run ($live=false) returns the commands as a stdout dump for ops review.
 * --live ($live=true) attempts to execute each via WooClient::get with an
 * assumed wp-cli-command REST endpoint; if the endpoint 404s (which is the
 * common case — the wp-cli plugin is rarely installed), the result is
 * flagged 'manual_required' and ops must run the command over SSH.
 *
 * --live is gated TWICE by the command wrapper:
 *   - CUTOVER_DISABLE_LIVE_ALLOWED=true in .env
 *   - interactive $this->confirm() prompt
 *
 * Every --live invocation records an audit_log entry with the command list
 * and per-command status so ops can reconcile "did the wp-cli endpoint
 * actually fire?" against the VPS SSH log.
 */
class LegacyPluginDisabler
{
    public function __construct(
        protected WooClient $woo,
        protected Auditor $auditor,
    ) {}

    /**
     * @return array{mode:string, commands:array<int,string>, executed:bool, results:array<int,array<string,mixed>>}
     */
    public function run(bool $live = false): array
    {
        $cronHooks = (array) config('cutover.legacy_cron_hooks', []);
        $plugins = (array) config('cutover.legacy_plugins', []);

        $commands = [];
        foreach ($cronHooks as $hook) {
            $commands[] = sprintf('wp cron event delete %s', escapeshellarg((string) $hook));
        }
        foreach ($plugins as $plugin) {
            $commands[] = sprintf('wp plugin deactivate %s', escapeshellarg((string) $plugin));
        }

        if (! $live) {
            return [
                'mode' => 'DRY-RUN',
                'commands' => $commands,
                'executed' => false,
                'results' => [],
            ];
        }

        // ── LIVE — attempt WP-CLI over REST; fall back to 'manual_required' ───
        // The commonly-installed wp-cli REST endpoint is POST /wp-json/wp-cli-command.
        // Most production WP sites do NOT expose this — the fallback is the
        // expected case, and ops run the commands manually over SSH.
        $results = [];
        foreach ($commands as $cmd) {
            try {
                $response = $this->woo->get('wp-cli-command', ['command' => $cmd]);
                $results[] = [
                    'command' => $cmd,
                    'status' => 'executed',
                    'response' => $response,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'command' => $cmd,
                    'status' => 'manual_required',
                    'reason' => $e->getMessage(),
                ];
                Log::warning(
                    'LegacyPluginDisabler: WP-CLI over REST failed; ops must run manually',
                    ['command' => $cmd, 'reason' => $e->getMessage()],
                );
            }
        }

        $this->auditor->record('cutover.legacy_plugins_disabled', [
            'mode' => 'LIVE',
            'commands' => $commands,
            'results' => $results,
        ]);

        return [
            'mode' => 'LIVE',
            'commands' => $commands,
            'executed' => true,
            'results' => $results,
        ];
    }
}
