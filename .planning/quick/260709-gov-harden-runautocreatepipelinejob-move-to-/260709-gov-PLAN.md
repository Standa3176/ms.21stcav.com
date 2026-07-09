---
phase: 260709-gov-harden-runautocreatepipelinejob-move-to-
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
  - tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php
must_haves:
  truths:
    - "RunAutoCreatePipelineJob now runs on the 'sync-bulk' Horizon queue (512MB, 1800s worker timeout, single worker) instead of 'default' (256MB, 120s worker timeout). The default supervisor's 120s timeout was silently killing any auto-create batch that ran longer than 2 minutes — sync-bulk gives it the memory + time an hour-scale Claude+Woo batch actually needs, and serializes it (one worker) so it can't compete with other bulk work."
    - "The job keeps ShouldBeUnique (prevents a concurrent duplicate run of the same SKU set — protects Claude/Woo spend) but now sets a bounded uniqueFor so a crashed/OOM'd/SIGKILL'd worker cannot hold its lock forever. Today, with no uniqueFor, a stalled job's lock is never released → re-dispatching the identical SKU set is silently swallowed indefinitely (the reported bug). With uniqueFor the lock auto-expires, so re-dispatch works again."
    - "uniqueFor is set to 1800 (matching the sync-bulk worker timeout) so the lock lifetime tracks the job's real maximum runtime: a legitimately-running batch holds the lock for its duration; a worker that is killed at the 1800s ceiling has its lock expire at ~the same point. The job's own timeout is aligned to 1800 to match the supervisor (was an inconsistent 3600 that the 120s default worker never honoured anyway). tries stays 1 (deliberate — never re-spend Claude money; per-SKU idempotency lives in the child commands)."
    - "uniqueId() and handle() are UNCHANGED (still dispatches products:draft-from-suggestions for the batch; skip-reason feedback via the existing toast + 30s poll + bell notification is untouched — that was already delivered by 260707-gsy). This is a queue/uniqueness reliability fix only, mirroring the codebase's own RecomputePriceJob (ShouldBeUnique + uniqueFor=300 + onQueue('sync-bulk'))."
  artifacts:
    - path: "app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php"
      provides: "sync-bulk queue + bounded uniqueFor"
      contains: "uniqueFor"
    - path: "tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php"
      provides: "asserts queue=sync-bulk + uniqueFor=1800 + uniqueId stable"
      contains: "sync-bulk"
  key_links:
    - from: "RunAutoCreatePipelineJob"
      to: "sync-bulk queue + auto-expiring unique lock"
      via: "onQueue('sync-bulk') + public int uniqueFor"
      pattern: "uniqueFor"
---

<objective>
Reliability fix for the auto-create-from-Suggestions job: move it off the wrong queue (default/120s/256MB — which
silently kills long batches) onto sync-bulk (1800s/512MB/1 worker), and bound its ShouldBeUnique lock with a
uniqueFor so a crashed job stops permanently blocking re-dispatch of the same SKUs. Mirror the codebase's own
RecomputePriceJob. NOTE: skip-reason UI feedback is already done (260707-gsy) — NOT part of this task.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260709-gov-harden-runautocreatepipelinejob-move-to-/
@CLAUDE.md
@app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
@app/Domain/Pricing/Jobs/RecomputePriceJob.php
@tests/Feature/Pricing/RecomputePriceJobTest.php
@config/horizon.php
---
Verified:
- RunAutoCreatePipelineJob: `final class … implements ShouldBeUnique, ShouldQueue`; `public int $tries = 1;`
  `public int $timeout = 3600;`; constructor calls `$this->onQueue('default')`; `uniqueId()` = 'auto-create:'.md5(csv of skus);
  NO uniqueFor. handle() runs the whole batch via Artisan::call('products:draft-from-suggestions', …) synchronously,
  then pulls the run summary from cache + sends the OperatorJobCompletedNotification (skip breakdown — keep as-is).
- config/horizon.php production supervisors: default = maxProcesses 3, tries 3, timeout 120, memory 256.
  sync-bulk = maxProcesses 1, tries 2, timeout 1800, memory 512. (So on 'default' the worker kills the job at 120s.)
- RecomputePriceJob is the pattern: `implements ShouldBeUnique, ShouldQueue`, `public int $uniqueFor = 300;`,
  `$this->onQueue('sync-bulk')` in ctor, uniqueId() keyed on stable identity. RecomputePriceJobTest asserts
  `$job->queue === 'sync-bulk'` and `$job->uniqueFor === 300`.
- No existing test dispatches RunAutoCreatePipelineJob or asserts its queue/uniqueness (grep-confirmed) — this adds
  new coverage, it doesn't fix a broken test. Do NOT change handle()/uniqueId()/the notification path.
</context>

<interfaces>
=== RunAutoCreatePipelineJob ===
- Add `public int $uniqueFor = 1800;` (alongside $tries/$timeout). Bounds the ShouldBeUnique lock so a
  crashed/killed worker's lock auto-expires; 1800 matches the sync-bulk worker timeout (the real max runtime).
- Change `public int $timeout = 3600;` → `public int $timeout = 1800;` (align to the sync-bulk worker timeout;
  the old 3600 was never honoured on the 120s default worker).
- Change the constructor `$this->onQueue('default')` → `$this->onQueue('sync-bulk')`.
- Keep: `implements ShouldBeUnique, ShouldQueue`, `public int $tries = 1;`, uniqueId(), handle(), failed(),
  the notification path — ALL unchanged.
- Update the class docblock line that claims "no new supervisor config required / default queue" to reflect the
  sync-bulk move + why (default's 120s worker timeout killed long batches; ShouldBeUnique needs uniqueFor).
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: move to sync-bulk + bounded uniqueFor</name>
  <files>
    app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php,
    tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php
  </files>
  <behavior>
    Apply the <interfaces> changes. New test (mirror tests/Feature/Pricing/RecomputePriceJobTest.php J2/J3):
      • constructing RunAutoCreatePipelineJob(['SKU-1'], sourceImages: false, autoPublish: false, actorId: 1)
        → $job->queue === 'sync-bulk'.
      • $job->uniqueFor === 1800.
      • $job->timeout === 1800; $job->tries === 1.
      • uniqueId() is stable + SKU-keyed: two jobs with the SAME skus share a uniqueId; different skus differ
        (assert uniqueId() equality/inequality — this proves the dedup key is unchanged).
    Match the RunAutoCreatePipelineJob constructor's ACTUAL signature (check arg order/names before writing the test).
    Do NOT run the pipeline or assert handle() behaviour — this is a config/property test only.
  </behavior>
  <action>
    Edit the job (uniqueFor + timeout + onQueue + docblock). Add the hardening test. Run it + pint. Confirm no
    other ProductAutoCreate tests regressed.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php 2>&1 | tail -12</automated>
    Expected: GREEN — queue sync-bulk, uniqueFor 1800, timeout 1800, tries 1, uniqueId stable/SKU-keyed.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/ProductAutoCreate 2>&1 | tail -6</automated>
    Expected: no NEW failures vs the known pre-existing baseline (this arc left ProductAutoCreate green — should stay 0 failed there).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Job on sync-bulk with uniqueFor=1800 + timeout=1800; ShouldBeUnique/tries=1/uniqueId/handle unchanged; hardening test green; ProductAutoCreate suite still green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest RunAutoCreatePipelineJobHardeningTest` → GREEN
2. `pest tests/Feature/ProductAutoCreate` → still 0 failed
3. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). Horizon picks
  up the new queue on restart (deploy already runs horizon:terminate).
- Effect: the Suggestions "Auto-create" batch now runs on sync-bulk (512MB, up to 1800s, one at a time) instead
  of default (256MB, killed at 120s). A crashed/stalled run no longer blocks re-dispatching the same SKUs forever —
  the unique lock now expires after 1800s. No behaviour change to what the pipeline does or the operator feedback.
- Skip-reason visibility was already delivered (260707-gsy: toast + 30s-poll + breakdown bell notification) — not
  changed here. A durable/browsable "recent auto-create runs" page + per-SKU fan-out remain OPTIONAL future work.
</verification>

<success_criteria>
- RunAutoCreatePipelineJob runs on sync-bulk with a bounded uniqueFor (1800) so crashed jobs stop swallowing re-dispatch; timeout aligned; ShouldBeUnique/tries/uniqueId/handle/notification unchanged; new hardening test green; ProductAutoCreate suite still green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260709-gov-harden-runautocreatepipelinejob-move-to-/260709-gov-SUMMARY.md` documenting the
queue move (why default's 120s was wrong), the bounded uniqueFor (the crash-lock bug it fixes), the timeout alignment,
the new test, and that skip-reason visibility was already done + fan-out/durable-surface are optional follow-ups.
</output>