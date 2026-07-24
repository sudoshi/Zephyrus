# B2 - Cockpit Foundation

Goal: prove the canonical status, metric, and snapshot layer end to end across web, API, mobile, and Eddy.

Primary source: PRD section `B2 - Cockpit Foundation`.

Exit principle: the cockpit is not complete because it renders; it is complete when the same trusted operational snapshot drives web, mobile, Eddy, history, stale states, and drill contexts with tests and runtime evidence.

## Current Evidence

- Cockpit API routes exist.
- `SnapshotBuilder`, `StatusEngine`, and `MetricValue` exist.
- `CommandCenterController` serves `/dashboard` from the current snapshot.
- Cockpit snapshot tests exist.
- Metric trust and serving tables exist.
- Realtime/polling paths exist in the frontend, but runtime Reverb/queue/scheduler proof is not archived.

## Deliverables

- [ ] Canonical metric schema completeness tests.
- [ ] Snapshot build/refresh/cache/ETag/stale/fallback tests.
- [ ] Web, mobile, and Eddy snapshot timestamp parity tests.
- [ ] Metric history and trend proof.
- [ ] Realtime/SSE/poll fallback proof.
- [ ] Admin threshold and damping tests.
- [ ] Cockpit visual and PHI evidence.

## Phase Entry Gate

B2 may start only after:

- [ ] B1 has produced a deterministic seed/provenance handoff or B2 records why existing fixtures are enough.
- [ ] D1, D2, and D18 are resolved or time-boxed.
- [ ] The snapshot contract owner has reviewed `TRACEABILITY-MATRIX.md` rows `PRD-Z1-001` through `PRD-Z1-004`.
- [ ] Existing tests in `tests/Feature/Cockpit/*`, `tests/Unit/Cockpit/*`, `tests/js/cockpit/*`, and `tests/js/commandCenter/*` are inventoried.
- [ ] B2 evidence directory exists.

Preflight commands:

```bash
git status --short --branch
php artisan route:list --path=cockpit
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=CockpitMetricValuesTest
php artisan test --filter=StatusParityTest
npm run test -- tests/js/cockpit
npm run test -- tests/js/commandCenter
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Snapshot JSON schema | Backend Agent | Frontend Agent | B3/B5/B6 owners | versioned PHP/TS/API schema contract |
| Status parity | Backend Agent | QA Agent | all UI/mobile owners | status tests and client mirror proof |
| Trust/freshness fields | Data/Integration Agent | Security Agent | B3/B5/B8 owners | API samples with source/as-of/freshness/confidence |
| Web/mobile/Eddy parity | Backend Agent | Mobile Agent | B5/B6 owners | same-snapshot timestamp proof |
| Realtime/fallback | Frontend Agent | Ops Agent | B8 owner | Reverb/SSE/poll fallback test or runtime proof |
| Visual/stale evidence | Frontend Agent | QA Agent | B8 owner | normal/stale/degraded screenshots |

## Agent Execution Contract

Owned write scope:

- `app/Support/Cockpit/MetricValue.php`.
- `resources/js/types/cockpit.ts`.
- `app/Services/Cockpit/SnapshotBuilder.php`.
- `app/Services/Eddy/EddyContextService.php` only for shared snapshot/trust fields.
- mobile BFF mapping only for aggregate snapshot parity.
- cockpit/mobile/Eddy tests proving the contract.

Read-only first:

- `tests/Feature/Cockpit/CockpitSnapshotApiTest.php`.
- `tests/Feature/Cockpit/CockpitMetricValuesTest.php`.
- `tests/Feature/Cockpit/StatusParityTest.php`.
- `tests/Feature/Eddy/EddyContextTest.php`.
- `tests/Feature/MobileBffTest.php`.
- `resources/js/features/cockpit/useCockpitStream.ts`.
- `bootstrap/app.php`.

Do not touch:

- Patient Flow scenario registry/history owned by B4.
- Eddy execution adapter semantics owned by B5.
- domain-specific route cleanup owned by B7 unless needed for snapshot contract tests.

## Versioned Snapshot Contract

B2 must publish a schema section in evidence and, if appropriate, source code comments/fixtures with:

| Field | Required Semantics |
| --- | --- |
| `schema_version` | explicit version for snapshot payload or fixture; bump on breaking field changes |
| `generated_at` / `as_of` | ISO timestamp from the shared snapshot source |
| `facility_key` | `HOSP1`/manifest facility key, not mixed with CAD join code |
| `scope` | house/unit/service scope where applicable |
| `metrics[].key` | stable metric key used by web/mobile/Eddy |
| `metrics[].status` | canonical status vocabulary from `StatusEngine` |
| `metrics[].source` | source system/table/registry key |
| `metrics[].source_mode` | `live`, `synthetic`, `demo`, `fallback`, or approved enum |
| `metrics[].refresh_cadence` | expected refresh cadence or null with reason |
| `metrics[].freshness_status` | `fresh`, `stale`, `degraded`, `unknown`, or approved enum |
| `metrics[].confidence` | numeric or enum confidence with documented scale |
| `metrics[].lineage` | source table/event IDs/counts sufficient for audit without PHI |
| `metrics[].fallback_state` | none/fallback/error state and reason |
| `metrics[].synthetic` | boolean or explicit source mode equivalent |
| `metrics[].actions` | only authorized, lifecycle-governed actions |

Allowed `as_of` skew:

- [ ] Web cockpit, mobile aggregate, and Eddy shared context must use identical snapshot `generated_at` where they read the cached cockpit snapshot.
- [ ] If a surface necessarily wraps the cached snapshot with its own envelope timestamp, the envelope may differ, but the nested source snapshot timestamp must match exactly.
- [ ] If a metric is domain-live and not part of the cached cockpit snapshot, allowed skew must be explicitly documented in that metric's lineage and must not exceed the refresh cadence.

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| `CockpitSnapshotApiTest` | Existing | cache/ETag/cold-start/shared snapshot assertions |
| `CockpitMetricValuesTest` | Existing | metric history/write/prune assertions |
| `StatusParityTest` | Existing | status vocabulary parity |
| `MetricValueTest` | Existing | PHP DTO behavior |
| `resources/js/types/cockpit.ts` schema tests | Existing JS ecosystem; extend as needed | Zod schema accepts required trust fields |
| web/mobile/Eddy as-of parity | Partially existing | exact shared timestamp proof |
| degraded/stale feed test | To create or extend | stale/degraded field and visible UI proof |
| Reverb/poll fallback proof | To create or runtime evidence | Reverb disabled/enabled behavior, no freeze |

## Criticality Double Check

B2 must pass or defer:

- Requirements traceability.
- Architecture fit.
- API contract.
- Data trust.
- Observability.
- Performance.
- Realtime/fallback.
- Testing.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B2/README.md`.
- [ ] `api/cockpit-snapshot-schema-v*.json`.
- [ ] `api/mobile-aggregate-snapshot-parity.json`.
- [ ] `api/eddy-context-snapshot-parity.json`.
- [ ] `commands/cockpit-tests.txt`.
- [ ] `commands/js-cockpit-tests.txt`.
- [ ] `screenshots/desktop-cockpit-normal.png`.
- [ ] `screenshots/desktop-cockpit-stale.png`.
- [ ] `screenshots/desktop-cockpit-degraded.png`.
- [ ] `reviews/snapshot-contract-review.md`.

## Workstream 2.1: Metric Contract Completeness

- [ ] Define one required JSON contract for every emitted cockpit metric:
  - [ ] `key`.
  - [ ] `label`.
  - [ ] `domain`.
  - [ ] `value`.
  - [ ] `unit`.
  - [ ] `status`.
  - [ ] `trend`.
  - [ ] `as_of`.
  - [ ] `source`.
  - [ ] `source_mode`.
  - [ ] `refresh_cadence`.
  - [ ] `freshness_status`.
  - [ ] `confidence`.
  - [ ] `lineage`.
  - [ ] `fallback_state`.
  - [ ] `synthetic`.
  - [ ] `drill_url` or drill target.
  - [ ] `actions` when action suggestions exist.
- [ ] Add an assertion helper for `MetricValue` payloads.
- [ ] Use the helper in cockpit snapshot tests.
- [ ] Use the helper in mobile BFF tests for cards that mirror cockpit metrics.
- [ ] Use the helper in Eddy context tests.
- [ ] Add negative fixtures for missing source, stale `as_of`, missing status, missing synthetic flag, and missing lineage.

Suggested files:

- `app/Support/Cockpit/MetricValue.php`.
- `tests/Feature/Cockpit/CockpitSnapshotApiTest.php`.
- `tests/Feature/Cockpit/CockpitMetricValuesTest.php`.
- `tests/Feature/MobileBffTest.php`.
- `tests/Feature/Eddy/*`.

Verification:

```bash
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=CockpitMetricValuesTest
php artisan test --filter=MobileBffTest
```

## Workstream 2.2: Snapshot Builder Behavior

- [ ] Prove a fresh snapshot is served from cache when valid.
- [ ] Prove stale snapshots are marked stale rather than silently treated as current.
- [ ] Prove inline refresh fallback when cache is cold.
- [ ] Prove persisted snapshot row is updated after refresh.
- [ ] Prove metric history rows are written.
- [ ] Prove ETag behavior with unchanged snapshot.
- [ ] Prove `force=true` or equivalent refresh behavior for admin/operator paths.
- [ ] Prove no PHI is emitted in broadcast reload pings.
- [ ] Add tests for failure behavior when one domain metric fails:
  - [ ] Other domains still render.
  - [ ] Failed metric has fallback/error state.
  - [ ] Error is logged with enough operator context.
  - [ ] No raw exception leaks to mobile/web users.

Suggested files:

- `app/Services/Cockpit/SnapshotBuilder.php`.
- `app/Jobs/RefreshCockpitSnapshot.php`.
- `database/migrations/*cockpit*`.
- `tests/Feature/Cockpit/CockpitSnapshotApiTest.php`.

Verification:

```bash
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=CockpitMetricValuesTest
php artisan test --filter=CockpitBroadcastTest
```

## Workstream 2.3: Status Engine And Threshold Governance

- [ ] Inventory every status threshold source:
  - [ ] Code constants.
  - [ ] Database metric definitions.
  - [ ] Config files.
  - [ ] Admin UI overrides.
- [ ] Ensure `StatusEngine` resolves all PRD states consistently:
  - [ ] Good.
  - [ ] Watch.
  - [ ] Warning.
  - [ ] Critical.
  - [ ] Unknown.
- [ ] Add tests around boundary values for every hero metric.
- [ ] Add tests for missing data, stale data, and fallback data.
- [ ] Ensure status wording and color tokens are consistent in web and mobile.
- [ ] Add admin-facing threshold review if beta requires runtime tuning.
- [ ] Add alert damping tests so threshold flapping does not create noisy action recommendations.

Suggested tests:

```bash
php artisan test --filter=StatusEngine
php artisan test --filter=Cockpit
```

## Workstream 2.4: Web, Mobile, And Eddy Snapshot Parity

- [ ] Add a test that fetches:
  - [ ] `/api/cockpit/snapshot`.
  - [ ] Mobile For You or altitude snapshot route.
  - [ ] Eddy context route.
- [ ] Assert all three cite the same snapshot `as_of` or a documented bounded skew.
- [ ] Assert all three expose freshness/source state.
- [ ] Assert all three use the same domain status for the same scope.
- [ ] Assert mobile/Eddy redaction never removes required freshness/source metadata.
- [ ] Add a regression fixture for stale snapshot parity.

Suggested route families:

- `/api/cockpit/snapshot`.
- `/api/mobile/v1/*`.
- `/api/mobile/v1/eddy/context/{scopeRef}`.
- `/api/eddy/*`.

Verification:

```bash
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=MobileBffTest
php artisan test --filter=Eddy
```

## Workstream 2.5: Realtime, SSE, And Polling Fallback

- [ ] Document the intended realtime stack for beta:
  - [ ] Reverb enabled.
  - [ ] SSE fallback.
  - [ ] Poll fallback.
  - [ ] Mobile fetch-on-open.
- [ ] Prove frontend handles each state:
  - [ ] Realtime connected.
  - [ ] Realtime disconnected.
  - [ ] Polling active.
  - [ ] Snapshot stale.
  - [ ] Manual refresh.
- [ ] Restrict Reverb origins for beta if enabled.
- [ ] Enable Reverb rate limiting or document why it is not used.
- [ ] Add an operations check for Reverb config after deploy.
- [ ] Add Playwright or component tests for cockpit stale and reconnect banners.

Verification:

```bash
php artisan route:list | rg "cockpit|stream|broadcast"
php artisan test --filter=Cockpit
npm run test
```

## Workstream 2.6: Cockpit Drill And Action Context

- [ ] For every hero metric, define a drill target:
  - [ ] Route.
  - [ ] Scope parameters.
  - [ ] Patient or unit redaction mode.
  - [ ] Required role.
  - [ ] Back link to cockpit.
- [ ] Ensure drill payload includes the metric source and snapshot timestamp.
- [ ] Ensure action recommendations cite the metric or event that created them.
- [ ] Ensure action inbox counts match backend actions.
- [ ] Add tests for at least one drill per domain:
  - [ ] RTDC.
  - [ ] ED.
  - [ ] Periop.
  - [ ] Transport.
  - [ ] Staffing.
  - [ ] Improvement.
  - [ ] Service-line/quality/financial.
- [ ] Add screenshot evidence for each drill.

## Workstream 2.7: Cockpit Visual Evidence

- [ ] Capture screenshots at:
  - [ ] Desktop standard viewport.
  - [ ] Wide wall display.
  - [ ] Tablet landscape.
  - [ ] Mobile web viewport.
- [ ] Capture states:
  - [ ] Normal.
  - [ ] Stale.
  - [ ] Degraded feed.
  - [ ] Synthetic source visible.
  - [ ] Action recommendation visible.
  - [ ] Patient lens visible with redaction.
- [ ] Verify no overlapping UI elements.
- [ ] Verify source/freshness labels remain readable.
- [ ] Verify dark/light mode if both are supported.
- [ ] Archive screenshots under a stable docs or release-evidence path.

Suggested command pattern:

```bash
npm run build
php artisan serve --port=8001
npm run test
```

Add exact Playwright commands once the repo's test harness is confirmed.

## Phase Exit Gate

This phase is complete only when:

- [ ] Every cockpit metric passes contract assertions.
- [ ] Snapshot cache, persistence, history, ETag, stale, and fallback behavior is tested.
- [ ] Web, mobile, and Eddy cite the same snapshot timing or documented bounded skew.
- [ ] Realtime and fallback behavior are proven.
- [ ] Drill targets and action context exist for every hero metric.
- [ ] Cockpit screenshots are archived.
- [ ] No unresolved B2 failures remain outside the known-limitations register.
