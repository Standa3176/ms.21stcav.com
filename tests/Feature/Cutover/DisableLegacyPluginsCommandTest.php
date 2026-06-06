<?php

declare(strict_types=1);

use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 05 Task 2 — cutover:disable-legacy-plugins (CUT-03 + CUT-07)
|--------------------------------------------------------------------------
|
| Behaviour tests DL1..DL4:
|   DL1 — dry-run emits WP-CLI commands to stdout
|   DL2 — --live without CUTOVER_DISABLE_LIVE_ALLOWED fails fast
|   DL3 — --live with env+--no-interaction fails (confirm() auto-declines)
|   DL4 — LegacyPluginDisabler.php contains ZERO `DB::` facade calls
|         (Deptrac WpDirectDb ban regression guard — SYNC-04 preserved)
*/

beforeEach(function (): void {
    config([
        'cutover.legacy_cron_hooks' => [
            'stock_updater_daily_sync',
            'itgalaxy_bitrix24_send',
            'itgalaxy_bitrix24_status',
        ],
        'cutover.legacy_plugins' => [
            'stock-updater',
            'woocommerce-bitrix24-integration',
        ],
        'cutover.disable_live_allowed_env_var' => 'CUTOVER_DISABLE_LIVE_ALLOWED',
        // Gate closed by default — individual tests open it via config().
        // We override the config value directly (not via putenv) because the
        // command reads config('cutover.disable_live_allowed'), and Laravel's
        // config is loaded once at boot — putenv() after boot does NOT
        // propagate to config in test runs. (Pattern enforced by
        // tests/Architecture/EnvUsageTest.php as of 260606-c4o.)
        'cutover.disable_live_allowed' => null,
    ]);

    // Stub WooClient so --live tests never touch the network.
    app()->instance(WooClient::class, new class extends WooClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = []): array
        {
            throw new \RuntimeException('WP-CLI endpoint 404 (expected in test env)');
        }
    });
});

it('registers cutover:disable-legacy-plugins in the artisan registry', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cutover:disable-legacy-plugins');
});

it('dry-run emits all WP-CLI commands to stdout (DL1)', function (): void {
    $exit = Artisan::call('cutover:disable-legacy-plugins');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('DRY-RUN');
    expect($output)->toContain('wp cron event delete');
    expect($output)->toContain('stock_updater_daily_sync');
    expect($output)->toContain('itgalaxy_bitrix24_send');
    expect($output)->toContain('itgalaxy_bitrix24_status');
    expect($output)->toContain('wp plugin deactivate');
    expect($output)->toContain('stock-updater');
    expect($output)->toContain('woocommerce-bitrix24-integration');
});

it('--live without env gate fails fast (DL2)', function (): void {
    // beforeEach already sets cutover.disable_live_allowed = null; nothing more to do.
    $exit = Artisan::call('cutover:disable-legacy-plugins', ['--live' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('not true in this environment');
});

it('--live with env but --no-interaction auto-declines confirmation (DL3)', function (): void {
    // Override the gate config directly — equivalent to the operator setting
    // CUTOVER_DISABLE_LIVE_ALLOWED=true in .env, but survives Laravel's
    // config load order in tests.
    config(['cutover.disable_live_allowed' => 'true']);

    // Pass `--no-interaction` explicitly — Pest's Artisan::call does NOT
    // automatically pass this flag, and on TTY-attached shells (Herd CLI on
    // Windows) Laravel's $this->confirm() blocks on STDIN read instead of
    // returning the default. With --no-interaction the helper returns the
    // provided default (false) immediately, so the command aborts.
    $exit = Artisan::call('cutover:disable-legacy-plugins', [
        '--live' => true,
        '--no-interaction' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('Aborted');
});

it('LegacyPluginDisabler.php contains ZERO DB facade calls (DL4 — WpDirectDb ban regression guard)', function (): void {
    $path = base_path('app/Domain/Cutover/Services/LegacyPluginDisabler.php');
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->not->toContain('DB::');
    expect($contents)->not->toContain('Illuminate\\Support\\Facades\\DB');
});
