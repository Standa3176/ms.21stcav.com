<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ProposeMarginBandTool;
use App\Domain\Agents\Tools\Pricing\TruncatingTool;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 10 Plan 02 — PricingTools soft-cap invariant (P10-B)
|--------------------------------------------------------------------------
|
| Per RESEARCH §P10-B: every concrete tool under
| app/Domain/Agents/Tools/Pricing/ MUST extend TruncatingTool (which
| inherits the 3 KB soft-cap helper + _truncated/_total_available hint
| contract per CONTEXT D-05).
|
| Sole exemption: ProposeMarginBandTool — it's a structured-contract
| no-op writer (CONTEXT D-06) with no payload to cap. Its tool_calls
| arguments are extracted by Plan 10-04's PricingAgentResultMapper.
|
| This gate catches future tool authors who forget to apply the cap.
| Without it, a 50KB JSON output multiplied through 6 tool steps could
| add ~300KB extra prompt tokens and blow past the £200/month cap on
| a few runs.
*/

it('every read_* tool in app/Domain/Agents/Tools/Pricing extends TruncatingTool', function (): void {
    $finder = Finder::create()
        ->files()
        ->in(app_path('Domain/Agents/Tools/Pricing'))
        ->name('*.php')
        ->notName('TruncatingTool.php')
        ->notName('ProposeMarginBandTool.php');

    $offenders = [];
    foreach ($finder as $file) {
        $class = 'App\\Domain\\Agents\\Tools\\Pricing\\'.$file->getFilenameWithoutExtension();
        if (! class_exists($class)) {
            continue;
        }
        $rc = new ReflectionClass($class);
        if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
            continue;
        }
        if (! is_subclass_of($class, TruncatingTool::class)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe(
        [],
        'PricingTools soft-cap invariant violated (RESEARCH P10-B). The following classes '
        .'must extend TruncatingTool (or be added to the exemption list with justification): '
        .implode(' | ', $offenders)
    );
});

it('ProposeMarginBandTool is the sole TruncatingTool exemption (no-op writer per D-06)', function (): void {
    // Sanity check — the architectural test has the right exclusion list.
    // If ProposeMarginBandTool is ever refactored to extend TruncatingTool,
    // this test will fail and the maintainer must consciously delete it
    // (and remove the notName() exclusion above).
    expect(is_subclass_of(ProposeMarginBandTool::class, TruncatingTool::class))->toBeFalse();
});
