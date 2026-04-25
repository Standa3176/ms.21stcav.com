<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 8 Plan 01 — Agent tool naming convention (AGNT-05)
|--------------------------------------------------------------------------
|
| Every concrete Tool subclass under app/Domain/Agents/Services/Tools/ MUST
| have a `name()` method returning a string starting with one of:
|   - propose_*  → tool produces a Suggestion (write through Suggestions seam)
|   - read_*     → tool reads v1 data (no side effects)
|   - search_*   → tool reads v1 data, possibly with FULLTEXT/scoring
|
| The naming convention is load-bearing because it makes intent visible at
| the AgentRun.tool_calls JSON layer (Plan 04 Filament AgentRunResource shows
| tool names directly). Plan 03's ToolBus depends on the prefix to route
| post-execution side effects (propose_ tools enqueue ApplySuggestionJob;
| read_ and search_ tools log only).
|
| Currently passes vacuously — Tools/ directory ships in Plan 03 (Tool base
| class) + Plan 04 (ReadHealthCheckTool, the EchoAgent's only tool).
*/

it('every agent tool name starts with propose_, read_, or search_', function (): void {
    $toolsDir = app_path('Domain/Agents/Services/Tools');
    if (! is_dir($toolsDir)) {
        test()->markTestSkipped('app/Domain/Agents/Services/Tools not present yet (Plan 04 ships first tool).');
    }

    $finder = (new Finder)->in($toolsDir)->name('*.php')->files();

    $violations = [];
    foreach ($finder as $file) {
        $class = 'App\\Domain\\Agents\\Services\\Tools\\'.$file->getFilenameWithoutExtension();
        if (! class_exists($class)) {
            continue;
        }
        $rc = new ReflectionClass($class);
        if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
            continue;
        }
        if (! $rc->hasMethod('name')) {
            $violations[] = $class.' — missing public name() method';

            continue;
        }
        try {
            $name = (new $class)->name();
        } catch (\Throwable $e) {
            $violations[] = $class.' — name() threw: '.$e->getMessage();

            continue;
        }
        if (! is_string($name) || ! preg_match('/^(propose_|read_|search_)/', $name)) {
            $violations[] = $class."->name() = '{$name}' — must start propose_/read_/search_";
        }
    }

    expect($violations)->toBe(
        [],
        'Agent tool naming convention violations (AGNT-05): '.implode(' | ', $violations)
    );
});
