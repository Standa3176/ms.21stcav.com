# Deferred items — 260607-pys

Out-of-scope discoveries logged during this quick task. **Not fixed here**
(scope boundary: only auto-fix issues DIRECTLY caused by 260607-pys
changes).

## Pre-existing test failures inherited from 260607-hxa

Surfaced during the Task 6 full-Pest run. The 260607-hxa SUMMARY counted
its baseline as 1,896 / 219 / 3 but did NOT account for the 3
IntegrationHealthWidgetTest cases broken by its own `IntegrationCredentialKind::EanSearch`
enum addition (commit ddb2311). The true post-260607-hxa state is
**1,896 / 222 / 3**, not 219.

| Failing test | Cause | Fix scope |
|---|---|---|
| `IntegrationHealthWidgetTest::it SnapshotAggregator::computeIntegrationHealth returns all 5 kinds (Test 3.8)` | Hardcoded `->toHaveCount(5)`; enum now has 6 cases after EanSearch addition (ddb2311) | Future quick — update `->toHaveCount(6)` |
| `IntegrationHealthWidgetTest::it IntegrationHealthWidget reads dashboard_snapshots metric_key=integration_health (Test 3.7)` | Same root cause — widget rendering count assertion | Future quick |
| `IntegrationHealthWidgetTest::it SnapshotAggregator::computeAll includes integration_health metric_key (D-15 wiring)` | Same — hardcoded count of 5 on `expect($all['integration_health'])->toHaveCount(5)` | Future quick |
| `HomeDashboardPageTest::it renders 9 widget class names somewhere in the /admin HTML` | Pre-existing assertSee miss on the redesigned section view — explicitly logged in 260606-lhp SUMMARY as "pre-existing assertSee miss on rebuilt section view. Unrelated." | Future quick — likely needs the test rewritten against the new section layout |

These are NOT caused by 260607-pys. The 260607-pys task introduced
exactly **one** test failure (`HomeDashboardPageTest::it exposes 12
widgets`) which was auto-fixed in commit 659e38e (Rule 1 deviation) by
updating the assertion from 12 to 13 + adding `AdCandidatesReadyWidget`
to the expected ordered widget array.
