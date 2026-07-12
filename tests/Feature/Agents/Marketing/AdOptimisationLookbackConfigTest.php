<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 260712-adl — Unified AdOptimisation lookback knob
|--------------------------------------------------------------------------
|
| config('agents.ad_optimisation.data_lookback_days') is the SINGLE window
| controlling both the command/dashboard "is there data to review" guard and
| ReadGa4ChannelPerformanceTool's read window. Default 30 when the env var
| AGENTS_AD_OPTIMISATION_LOOKBACK_DAYS is unset (unifies the former 14d guard
| + 30d tool). The command/guard read this key so it can never disagree with
| the tool again.
*/

use App\Domain\Agents\Console\Commands\RunAdOptimisationCommand;
use App\Domain\Integrations\Filament\Pages\MarketingDashboardPage;

it('defaults data_lookback_days to 30 when the env var is unset', function () {
    expect(config('agents.ad_optimisation.data_lookback_days'))->toBe(30);
});

it('the command guard and dashboard both read the same lookback config key', function () {
    $key = 'config\(\s*[\'"]agents\.ad_optimisation\.data_lookback_days[\'"]';

    $command = file_get_contents((new ReflectionClass(RunAdOptimisationCommand::class))->getFileName());
    $dashboard = file_get_contents((new ReflectionClass(MarketingDashboardPage::class))->getFileName());

    expect($command)->toMatch("/{$key}/");
    expect($dashboard)->toMatch("/{$key}/");
});
