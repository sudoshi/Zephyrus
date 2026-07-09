# B4 - Patient Flow 4D And Eddy Barrier Intelligence

Goal: complete Patient Flow 4D as a beta-grade operational timeline with scenario selection, persisted history, barrier intelligence, source lineage, persona redaction, and Eddy-ready context.

Primary source: PRD section `B4 - Patient Flow 4D And Eddy Barrier Intelligence`.

Exit principle: the navigator must support a credible demo and operating-room-style explanation of who is blocked, why, where the constraint lives, what data supports it, and which governed action can move it.

## Current Evidence

- Patient Flow 4D web/API foundation exists.
- Occupancy context and projections exist.
- Barrier taxonomy exists.
- Eddy context exists.
- Redaction/persona concepts exist.
- One demo overlay exists: `rtdc_barriers`.
- 2026-07-09 local implementation added `GET /api/patient-flow/occupancy/history`.
- 2026-07-09 local implementation added `GET /api/patient-flow/demo-scenarios`.
- 2026-07-09 local implementation populates live snapshot detail/count/projection fields for `flow:snapshot`.
- Remaining proof gaps are visual canvas evidence, archived API samples, and native/mobile screenshot parity.

## Deliverables

- [x] Occupancy history endpoint from persisted snapshots.
- [x] Demo scenario registry endpoint.
- [x] PRD demo scenarios represented as selectable, labeled, source-aware scenarios.
- [x] Persisted snapshot detail/count/projection/lineage fields for live snapshot writes.
- [ ] Barrier intelligence panel with selected/all barrier modes.
- [ ] Persona redaction proof for web and mobile.
- [ ] Eddy context parity for selected barriers and occupancy scope.
- [ ] Canvas/timeline visual tests and screenshot evidence.

## Phase Entry Gate

B4 may start only after:

- [ ] B1 has handed off scenario keys, seed/import/rebase expectations, and source labels.
- [ ] B2 has handed off status/trust/snapshot field semantics.
- [ ] B3 has handed off patient display/redaction policy or B4 records a temporary Patient Flow-specific redaction rule.
- [ ] D2, D5, D6, D7, and D16 are resolved or time-boxed.
- [ ] Requirement IDs `PRD-PF-001` through `PRD-PF-007` are assigned in `TRACEABILITY-MATRIX.md`.
- [ ] Existing Patient Flow web/API/mobile tests are inventoried.

Preflight commands:

```bash
git status --short --branch
php artisan route:list --path=patient-flow
php artisan test --filter=PatientFlowApiTest
php artisan test --filter=FlowWindowTest
npm run test -- tests/js
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Patient Flow route/API contract | Backend Agent | QA Agent | Frontend/Mobile/B8 owners | route list, API contract samples |
| Occupancy history endpoint | Backend Agent | Data Agent | Frontend/Mobile owners | bounded/paginated history tests |
| Snapshot detail persistence | Data Agent | Backend Agent | B1/B8 owners | DB assertions for details/counts/projections/lineage |
| Scenario registry | Backend Agent | Product/QA Agent | B5/B6/B8 owners | selected scenario set and scenario API samples |
| Barrier panel and canvas proof | Frontend Agent | QA Agent | B8 owner | screenshots and interaction/canvas checks |
| Eddy barrier context | Backend/Eddy Agent | Security Agent | B5 owner | selected/all-barrier context samples |
| Mobile parity | Mobile Agent | Backend Agent | B6 owner | BFF/native parity samples |

## Agent Execution Contract

Owned write scope:

- `routes/api.php` Patient Flow route definitions.
- `app/Http/Controllers/Api/PatientFlow/PatientFlowController.php`.
- `app/Services/PatientFlow/PatientFlowOccupancyContextService.php`.
- `app/Services/Flow/TimelineSnapshotService.php`.
- `resources/js/features/patientFlowNavigator/api.ts`.
- `resources/js/Components/PatientFlowNavigator/*`.
- Patient Flow feature/unit/JS tests.
- mobile flow mapping only for Patient Flow parity.

Read-only first:

- `config/patient_flow.php`.
- `app/Console/Commands/FlowSnapshotCommand.php`.
- `app/Services/PatientFlow/*`.
- `tests/Feature/PatientFlow/PatientFlowApiTest.php`.
- `tests/Feature/Mobile/FlowWindowTest.php`.
- `tests/Feature/Mobile/MobileBackendSafetyTest.php`.
- `tests/Unit/PatientFlow/*`.
- `hummingbird/*/Flow*` files.

Do not touch:

- Eddy approval/execution policy owned by B5, except to provide context payloads.
- Broad Hummingbird role package implementation owned by B6.
- Domain completion outside Patient Flow owned by B7.

## Scenario Scope Decision

B4 must close the seven-versus-nine scenario ambiguity before implementation:

| Scenario Key | Patient Flow-Owned? | If Not Patient Flow, Owning Phase | Evidence Required |
| --- | --- | --- | --- |
| `house_glance` | partial | B2/B8 | cockpit scenario proof |
| `ed_boarder_to_bed` | yes | B4/B7 | PF scenario and RTDC/ED handoff |
| `icu_downgrade_capacity` | yes | B4 | PF scenario |
| `or_delay_pacu_hold` | partial | B7 | periop pressure handoff |
| `discharge_barrier_resolution` | yes | B4/B5/B6 | barrier/action/mobile proof |
| `staffing_gap_safe_capacity` | partial | B7/B6 | staffing state and safe-capacity proof |
| `regional_transfer_pressure` | no by default | B7 | graph-backed transfer proof or limitation |
| `improvement_study_handoff` | no by default | B7 | Study handoff proof |
| `trust_degraded_feed` | partial | B1/B2/B5 | degraded/stale source proof |

If beta implements seven Patient Flow scenarios instead of all nine PRD demo scenarios, B4 must update `DECISION-REGISTER.md` D6 and `docs/beta-known-limitations.md` with the exact two scenarios handled outside Patient Flow.

## API Contract Details

`GET /api/patient-flow/occupancy/history` must specify:

- [ ] default time window.
- [ ] maximum time window.
- [ ] interval/bucketing behavior.
- [ ] pagination or capped sample count.
- [ ] role/persona redaction mode.
- [ ] scenario key filter behavior.
- [ ] empty-state response.
- [ ] stale/degraded source fields.
- [ ] lineage count fields.

`GET /api/patient-flow/demo-scenarios` must specify:

- [ ] scenario key.
- [ ] label.
- [ ] description.
- [ ] PRD scenario ID.
- [ ] source mode.
- [ ] enabled/disabled state.
- [ ] disabled reason.
- [ ] owning phase if scenario is outside Patient Flow.
- [ ] default route/query params.
- [ ] expected actor/persona.

## Snapshot Detail Persistence Contract

`flow:snapshot` and related services must populate or explicitly defer:

- [ ] per-unit occupancy details.
- [ ] per-space occupancy details.
- [ ] service-line counts.
- [ ] blocker/barrier counts.
- [ ] projection windows.
- [ ] lineage source tables/counts.
- [ ] scenario/live flag.
- [ ] source key and freshness timestamp.
- [ ] retention/pruning behavior.

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| `PatientFlowApiTest` | Existing | extend for history/scenarios/redaction/source labels |
| `FlowWindowTest` | Existing mobile flow coverage | update for history/scenario parity if BFF changes |
| `MobileBackendSafetyTest` | Existing | privileged versus aggregate role proof |
| `ApiRouteSmokeTest` | Missing until B0/B7 creates it | do not mark green unless file exists |
| snapshot detail persistence test | To create | `TimelineSnapshotService` writes non-empty detail/count/projection/lineage fields |
| bounded history test | To create | max window/pagination/interval enforced |
| scenario registry test | To create | all selected scenario keys enumerated and labeled |
| canvas nonblank/framing check | To create or capture with Playwright | nonblank pixels, correct framing, interaction proof |

## Criticality Double Check

B4 must pass or defer:

- Requirements traceability.
- API contract.
- Authorization.
- PHI/privacy.
- Data trust.
- Data integrity.
- Frontend quality.
- Mobile parity.
- Eddy governance context handoff.
- Performance.
- Testing.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B4/README.md`.
- [ ] `api/patient-flow-occupancy-history-aggregate.json`.
- [ ] `api/patient-flow-occupancy-history-privileged.json`.
- [ ] `api/patient-flow-demo-scenarios.json`.
- [ ] `api/patient-flow-eddy-context-selected-barrier.json`.
- [ ] `commands/patient-flow-tests.txt`.
- [ ] `screenshots/desktop-patient-flow-default.png`.
- [ ] `screenshots/desktop-patient-flow-scenario.png`.
- [ ] `screenshots/desktop-patient-flow-selected-barrier.png`.
- [ ] `reviews/scenario-scope-decision.md`.
- [ ] `reviews/snapshot-detail-persistence.md`.

## Workstream 4.1: API Contract Inventory And Drift Lock

- [ ] Document all current Patient Flow routes:
  - [ ] Summary.
  - [ ] Locations.
  - [ ] Ambient.
  - [ ] Events.
  - [ ] Tracks.
  - [ ] State.
  - [ ] FHIR bundle.
  - [ ] ADT stream.
  - [ ] Projections.
  - [ ] Occupancy.
  - [ ] HL7v2 ingest.
- [ ] Add missing PRD routes:
  - [ ] `GET /api/patient-flow/occupancy/history`.
  - [ ] `GET /api/patient-flow/demo-scenarios`.
- [ ] Add request/response contracts for:
  - [ ] Scope.
  - [ ] Persona.
  - [ ] Time window.
  - [ ] Scenario key.
  - [ ] Include flags.
  - [ ] Redaction mode.
  - [ ] Source/freshness metadata.
- [ ] Add OpenAPI or contract tests if the repo's API contract pattern supports it.
- [ ] Add route smoke tests for all Patient Flow routes.

Suggested files:

- `routes/api.php`.
- `app/Http/Controllers/Api/PatientFlow/PatientFlowController.php`.
- `tests/Feature/PatientFlow/PatientFlowApiTest.php`.

Verification:

```bash
php artisan route:list | rg "patient-flow"
php artisan test --filter=PatientFlowApiTest
# Run only after B0/B7 creates it:
php artisan test --filter=ApiRouteSmokeTest
```

## Workstream 4.2: Occupancy History Endpoint

- [ ] Design response shape for `/api/patient-flow/occupancy/history`:
  - [ ] `scope_ref`.
  - [ ] `from`.
  - [ ] `to`.
  - [ ] `interval_minutes`.
  - [ ] `snapshots`.
  - [ ] `source`.
  - [ ] `freshness`.
  - [ ] `lineage`.
  - [ ] `scenario`.
  - [ ] `redaction`.
- [ ] Read from persisted occupancy snapshots rather than recomputing only current state.
- [ ] Support filters:
  - [ ] Unit.
  - [ ] Service line.
  - [ ] Acuity.
  - [ ] Barrier type.
  - [ ] Patient class.
  - [ ] Scenario key.
- [ ] Enforce retention boundaries.
- [ ] Add pagination or interval bucketing to prevent large payloads.
- [ ] Add tests for empty history, normal history, stale history, and scenario history.
- [ ] Add tests for unauthorized and redacted roles.

Acceptance example:

- [ ] A demo operator can open Patient Flow 4D, select a unit, and see a previous 24-hour occupancy trend without reseeding data.

## Workstream 4.3: Persist Snapshot Details

- [ ] Populate snapshot fields that are currently empty or incomplete:
  - [ ] `occupancy_details`.
  - [ ] Service-line counts.
  - [ ] Blocker counts.
  - [ ] Projection windows.
  - [ ] Source lineage.
  - [ ] Scenario key.
  - [ ] Demo/live flag.
  - [ ] Confidence/freshness state.
- [ ] Ensure `flow:snapshot` writes enough data for the history endpoint.
- [ ] Ensure scheduled snapshots and manual snapshots use the same write path.
- [ ] Add data migration or compatibility handling for older snapshot rows.
- [ ] Add pruning/retention command tests.
- [ ] Add metric for last successful snapshot time.

Suggested files:

- `app/Services/Flow/TimelineSnapshotService.php`.
- `app/Console/Commands/FlowSnapshotCommand.php`.
- `database/migrations/*occupancy_snapshots*`.

Verification:

```bash
php artisan flow:snapshot
php artisan test --filter=PatientFlowApiTest
php artisan test --filter=FlowSnapshot
```

## Workstream 4.4: Demo Scenario Registry

- [ ] Create a scenario registry service for PRD demo scenarios.
- [ ] Add `GET /api/patient-flow/demo-scenarios`.
- [ ] Include for each scenario:
  - [ ] Key.
  - [ ] Label.
  - [ ] Short description.
  - [ ] Required seed state.
  - [ ] Default scope.
  - [ ] Time window.
  - [ ] Expected visible barriers.
  - [ ] Expected Eddy action opportunities.
  - [ ] Source mode.
  - [ ] Enabled/disabled state.
  - [ ] Reason if disabled.
- [ ] Support at minimum these PRD-aligned scenarios:
  - [ ] House executive glance.
  - [ ] ED boarder to inpatient bed.
  - [ ] ICU downgrade unlocks capacity.
  - [ ] OR delay creates bed and staffing pressure.
  - [ ] Discharge barrier resolution.
  - [ ] Staffing gap and safe capacity.
  - [ ] Regional transfer/external demand.
  - [ ] Improvement and study handoff.
  - [ ] Trust/provenance/degraded feed.
- [ ] If the implementation chooses seven Patient Flow scenarios instead of all nine PRD demo scenarios, document that decision in B0 and show which two are handled outside Patient Flow.
- [ ] Add tests that scenario keys remain stable and each enabled scenario produces visible data.

Suggested files:

- `app/Services/PatientFlow/PatientFlowDemoBarrierScenario.php`.
- New `PatientFlowDemoScenarioRegistry` or equivalent.
- `tests/Feature/PatientFlow/PatientFlowScenarioTest.php`.

## Workstream 4.5: Barrier Intelligence Panel

- [ ] Define canonical barrier categories:
  - [ ] Bed unavailable.
  - [ ] EVS/room clean.
  - [ ] Transport.
  - [ ] Imaging/lab.
  - [ ] Consult.
  - [ ] Discharge order.
  - [ ] Medication.
  - [ ] Staffing.
  - [ ] External transfer.
  - [ ] Unknown.
- [ ] Add selected-barrier mode.
- [ ] Add all-barriers mode.
- [ ] Add barrier severity, age, source, confidence, and owner.
- [ ] Add associated action recommendations.
- [ ] Add "why this barrier" explanation with lineage.
- [ ] Add filtering by unit, service, acuity, and scenario.
- [ ] Add huddle-ready summary export/draft path if in beta scope.
- [ ] Add tests for barrier grouping and redaction.

Suggested frontend files:

- `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx`.
- `resources/js/features/patientFlowNavigator/api.ts`.

## Workstream 4.6: Eddy Context For Patient Flow

- [ ] Ensure Eddy can request context for:
  - [ ] Current scope.
  - [ ] Selected unit.
  - [ ] Selected barrier.
  - [ ] All barriers.
  - [ ] Selected patient only when role allows.
  - [ ] Scenario key.
- [ ] Ensure context includes:
  - [ ] Source/freshness.
  - [ ] Barrier taxonomy.
  - [ ] Confidence.
  - [ ] Redaction mode.
  - [ ] Recommended eligible actions.
  - [ ] Forbidden actions and reasons.
- [ ] Add tests for privileged and aggregate-redacted roles.
- [ ] Add tests that Eddy cannot see PHI through Patient Flow context when mobile/web would redact it.
- [ ] Add at least one Eddy proposal test based on Patient Flow barrier context.

Suggested tests:

```bash
php artisan test --filter=MobileBackendSafetyTest
php artisan test --filter=Eddy
php artisan test --filter=PatientFlow
```

## Workstream 4.7: Visual Timeline And Canvas Proof

- [ ] Add or update Playwright/component tests for Patient Flow 4D.
- [ ] Verify the timeline/canvas is nonblank after data load.
- [ ] Verify panning/zooming/time-window controls do not create blank states.
- [ ] Verify scenario switch updates visible data and labels.
- [ ] Verify occupancy history appears in the intended visual region.
- [ ] Verify tooltips do not leak PHI for redacted roles.
- [ ] Verify selected barrier panel updates without layout shift.
- [ ] Capture screenshots for:
  - [ ] Default current state.
  - [ ] Occupancy history.
  - [ ] Each enabled scenario.
  - [ ] Selected barrier.
  - [ ] Redacted persona.
  - [ ] Privileged persona.
  - [ ] Mobile viewport.

Verification:

```bash
npm run test
npm run build
./scripts/check-ui-canon.sh
```

## Workstream 4.8: Mobile Patient Flow Parity

- [ ] Ensure Hummingbird BFF exposes the same Patient Flow context needed by mobile.
- [ ] Ensure selected barrier and all-barriers modes have mobile-friendly cards.
- [ ] Ensure mobile redaction matches web.
- [ ] Ensure mobile action paths use the same Eddy/action lifecycle as web.
- [ ] Add mobile contract tests for scenario and barrier context.
- [ ] Capture iOS and Android screenshots for at least one Patient Flow barrier scenario.

## Phase Exit Gate

This phase is complete only when:

- [ ] `/api/patient-flow/occupancy/history` exists, is tested, and reads persisted snapshots.
- [ ] `/api/patient-flow/demo-scenarios` exists, is tested, and reports enabled/disabled scenario state.
- [ ] Snapshot details are populated enough to support history, lineage, and projections.
- [ ] Scenario selection works in API and UI.
- [ ] Barrier intelligence supports selected and all-barrier modes.
- [ ] Eddy context and mobile context match web redaction and source metadata.
- [ ] Visual/canvas/screenshot checks are archived.
