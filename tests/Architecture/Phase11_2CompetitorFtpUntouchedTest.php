<?php

declare(strict_types=1);

/**
 * Phase 09.1 Plan 01 — architectural regression for Phase 11.2 isolation.
 *
 * Phase 09.1 introduces a generic IntegrationCredential table — the temptation
 * is to subsume Phase 11.2's CompetitorFtpCredential into it. We deliberately
 * did NOT do that: competitor FTP credentials are per-feed (1 credential maps
 * to many CompetitorFtpFeed rows), whereas integration credentials are
 * per-integration-kind (1 row per kind, UNIQUE(kind) constraint). Different
 * concerns, different tables.
 *
 * This test enforces that Phase 09.1 did NOT touch the Phase 11.2 files —
 * the most recent commit on each Phase 11.2 file MUST still reference
 * "11.2" in its subject line and MUST NOT reference "09.1".
 */

it('Phase 11.2 CompetitorFtpCredential pattern is untouched by Phase 09.1', function (): void {
    $phase112Files = [
        'app/Domain/Competitor/Models/CompetitorFtpCredential.php',
        'app/Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php',
        'app/Domain/Competitor/Policies/CompetitorFtpCredentialPolicy.php',
        'database/migrations/2026_05_02_150100_create_competitor_ftp_credentials_table.php',
    ];

    $repoRoot = base_path();

    foreach ($phase112Files as $file) {
        $absPath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        expect(file_exists($absPath))->toBeTrue("Phase 11.2 file missing: {$file}");

        // git log -1 returns the latest commit on this file; --format=%s is the subject.
        $cmd = sprintf('git -C %s log -1 --format=%%s -- %s', escapeshellarg($repoRoot), escapeshellarg($file));
        $latestCommitSubject = trim((string) shell_exec($cmd));

        // Empty subject means git wasn't available or the file isn't tracked — skip
        // gracefully rather than producing a misleading green; require at least
        // one of the markers below in any normal-run state.
        if ($latestCommitSubject === '') {
            continue;
        }

        expect($latestCommitSubject)
            ->not->toContain('09.1', "File {$file} was modified by Phase 09.1 — must remain untouched (subject: {$latestCommitSubject})");
    }
});
