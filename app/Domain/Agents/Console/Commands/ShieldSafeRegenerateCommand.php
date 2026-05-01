<?php

declare(strict_types=1);

namespace App\Domain\Agents\Console\Commands;

use App\Console\Commands\BaseCommand;

/**
 * Phase 8 Plan 05 Task 1 — AGNT-11 Shield wrapper with automatic P5-F restoration.
 *
 * Workflow:
 *   1. Refuse if git working tree is dirty (unless --force)
 *   2. Capture current policy paths via `git ls-files app/Domain/{star}/Policies/{star}.php`
 *   3. Run `shield:generate --all --force`
 *   4. For each captured path NOT in --allow-new, `git checkout -- {path}`
 *   5. Run PolicyTemplateIntegrityTest as smoke test; exit 1 if it fails
 *
 * --allow-new flag: lets a brand-new policy (e.g. AgentRunPolicy on first run)
 * survive Shield's auto-generation. Subsequent runs commit the hand-written
 * body to git, then --allow-new is no longer needed.
 *
 * --restore=false escape hatch — runs Shield without restoration (operator
 * override; rare). Default true.
 *
 * --force overrides the dirty-tree refusal (rare; for CI / scripted contexts).
 *
 * Documented in docs/ops/shield-regeneration.md (runbook).
 */
final class ShieldSafeRegenerateCommand extends BaseCommand
{
    protected $signature = 'shield:safe-regenerate
                            {--allow-new=* : Policy class names allowed to be newly created without restore}
                            {--restore=true : Restore hand-written policies after regen (default true)}
                            {--force : Proceed even if git working tree is dirty}';

    protected $description = 'AGNT-11 — Shield wrapper with automatic P5-F policy restoration';

    protected function perform(): int
    {
        // ── 1. Dirty-tree guard ──
        $force = (bool) $this->option('force');
        if (! $force && $this->isGitTreeDirty()) {
            $this->error('git working tree is dirty — commit or stash before running shield:safe-regenerate. Pass --force to override.');

            return 1;
        }

        // ── 2. Capture policy paths from git ──
        $this->info('Step 1: Capturing current policy state from git...');
        $policies = $this->capturePoliciesFromGit();
        $this->info('  Captured '.count($policies).' policy file(s)');

        // ── 3. Run shield:generate --all --panel=admin --option=policies_and_permissions ──
        //      filament-shield ^3.x has no `--force`; both `--panel` AND
        //      `--option` are mandatory in non-interactive mode (the command
        //      uses Laravel\Prompts\Select() with no fallback value otherwise
        //      — TypeError "null returned"). Plan 11-03 deviation — previous
        //      wrapper used `--force` which doesn't exist + omitted `--panel`
        //      which interactively prompts. Hand-written policy preservation
        //      happens via the post-step `git checkout --` restoration loop
        //      below (P5-F discipline).
        $this->info('Step 2: Running shield:generate --all --panel=admin --option=policies_and_permissions...');
        $exitCode = $this->call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--option' => 'policies_and_permissions',
        ]);
        if ($exitCode !== 0) {
            $this->error("shield:generate failed with exit code {$exitCode}");

            return $exitCode;
        }

        // ── 4. Restore policies (unless --restore=false) ──
        $restoreOption = $this->option('restore');
        $restore = filter_var($restoreOption, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $restore = $restore !== null ? $restore : true;
        if ($restore) {
            /** @var array<int, string> $allowNew */
            $allowNew = (array) $this->option('allow-new');
            $allowNewLabel = $allowNew !== [] ? ' (skipping '.implode(', ', $allowNew).')' : '';
            $this->info('Step 3: Restoring hand-written policies (P5-F)'.$allowNewLabel);
            foreach ($policies as $absolutePath) {
                $className = pathinfo($absolutePath, PATHINFO_FILENAME);
                if (in_array($className, $allowNew, true)) {
                    $this->info("  Skipping {$className} (--allow-new)");

                    continue;
                }
                $rc = 0;
                $output = [];
                exec('git checkout -- '.escapeshellarg($absolutePath).' 2>&1', $output, $rc);
                if ($rc !== 0) {
                    $this->warn("  Could not restore {$className}: ".implode(' ', $output));
                }
            }
        } else {
            $this->info('Step 3: Skipping restoration (--restore=false)');
        }

        // ── 5. PolicyTemplateIntegrityTest gate (Plan 11-03 deviation:
        //      use exec() to spawn a clean child artisan process so the
        //      `--allow-new` / `--force` flags from THIS command don't
        //      leak into the test command's input parser via Laravel's
        //      Command::call() option-inheritance behaviour). ──
        $this->info('Step 4: Running PolicyTemplateIntegrityTest...');
        $output = [];
        $testExit = 0;
        // PHP_BINARY + base_path() so the wrapper works on Windows-Herd + Linux CI alike.
        $cmd = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(base_path('artisan'))
            .' test --filter=PolicyTemplateIntegrityTest 2>&1';
        exec($cmd, $output, $testExit);
        if ($testExit !== 0) {
            $this->error('PolicyTemplateIntegrityTest FAILED — Shield {{ Placeholder }} leak detected.');
            $this->line(implode(PHP_EOL, $output));

            return 1;
        }

        $this->info('shield:safe-regenerate complete.');

        return 0;
    }

    private function isGitTreeDirty(): bool
    {
        $output = [];
        $rc = 0;
        exec('git status --porcelain 2>&1', $output, $rc);

        return $rc === 0 && count(array_filter($output, fn ($l) => trim((string) $l) !== '')) > 0;
    }

    /**
     * @return array<int, string> absolute paths to tracked policy files
     *
     * Captures TWO policy roots (Plan 11-03 deviation — original capture only
     * scanned `app/Domain/{slash}{star}{slash}Policies/{star}.php` and missed
     * `app/Policies/RolePolicy.php` which Shield re-generates on every run;
     * the leak surfaced when PolicyTemplateIntegrityTest tripped post-regen
     * on a Shield `{{ Placeholder }}` literal in app/Policies/RolePolicy.php):
     *   1. `app/Policies/{star}.php` — Phase 1 root policies (RolePolicy)
     *   2. `app/Domain/{star}/Policies/{star}.php` — Phase 2+ domain policies
     */
    private function capturePoliciesFromGit(): array
    {
        $output = [];
        $rc = 0;
        exec('git ls-files app/Policies/*.php app/Domain/*/Policies/*.php 2>&1', $output, $rc);
        if ($rc !== 0) {
            return [];
        }
        $tracked = array_filter($output, fn ($l) => trim((string) $l) !== '');

        return array_map(fn ($p) => base_path(trim((string) $p)), $tracked);
    }
}
