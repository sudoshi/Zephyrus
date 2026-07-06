# Patient Flow 4D Barrier And Eddy Enablement Implementation Plan

Date: 2026-07-05
Status: Detailed execution plan
Target repo: `/Users/sudoshi/Github/Zephyrus`
Primary surface: `/rtdc/patient-flow-navigator`
Primary API: `/api/patient-flow/occupancy`
Related docs:

- `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md`
- `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-todo.md`
- `docs/hummingbird/FLOW-WINDOW-PLAN.md`
- `docs/EDDY-AI-AGENT-PLAN.md`

## 1. Objective

Advance the Patient Flow 4D viewer from a compelling visual demo into an
RTDC operating instrument where every bed/position occupancy disk can explain:

- how long the position has been occupied,
- where the patient came from,
- where the patient is expected to move next,
- which timer is driving action,
- whether arrival, movement, EVS readiness, discharge readiness, staffing, or
  another dependency is delayed,
- which service line and persona are affected,
- why the barrier exists,
- who owns the next step,
- what RTDC demand-capacity metric is being affected,
- what Eddy can safely summarize or recommend for human review.

The implementation is complete when the 4D viewer, backend contract, snapshot
storage, persona lenses, and Eddy context all share the same barrier semantics.

## 2. Current Baseline

The current working implementation already provides the first demonstration
slice:

- `GET /api/patient-flow/occupancy` returns disk-ready occupancy details.
- `flow_core.occupancy_snapshots` has JSONB places for occupancy details,
  timer rollups, service-line rollups, persona rollups, blockers, and projection
  windows.
- Occupancy disks render above patient positions in `NavigatorScene.ts`.
- Disk radius reflects duration of stay.
- Disk color reflects `ok`, `watch`, or `delayed`.
- Timer pips show the first few active timers.
- Clicking a disk exposes a flat inspector payload with timers and barrier
  metadata.
- The toolbar includes RTDC occupancy/timer rollups.
- A `Barriers` switch finds/focuses all barriers and delays.
- A local demo scenario can show six focused RTDC barriers instead of noisy
  stale replay data.
- Eddy can be opened with a prefilled prompt containing occupancy, service-line
  compounding, and barrier reasons.

This plan assumes that baseline exists and focuses on hardening it into a
production-grade capability.

## 3. Guiding Principles

- One barrier vocabulary must serve backend storage, 4D disks, persona rollups,
  mobile lenses, and Eddy context.
- The viewer must show source lineage and confidence for every timer or barrier.
- Patient identity must remain persona-lensed. Aggregate personas can see
  pressure and reasons without patient detail.
- Demo data must be deterministic, explainable, and switchable. It must not
  pollute production behavior.
- Eddy should receive structured context, not only prose. Text prompts are a
  useful UI affordance, not the system of record.
- UI controls should help operators act: find, rank, filter, focus, inspect,
  and hand off.
- Every visual claim must map back to a backend field that can be tested.

## 4. Scope

### In Scope

- Barrier taxonomy and metadata model.
- Occupancy detail contract expansion.
- Snapshot persistence and rollup jobs.
- 4D viewer finder controls, inspector grouping, legends, and camera focus.
- Named demo scenarios.
- Eddy structured context endpoint or payload.
- Persona-specific lens handling for barrier visibility and actions.
- Backend, frontend, and browser smoke tests.
- Documentation and operator demo scripts.

### Out Of Scope For This Plan

- Replacing the full RTDC planning engine.
- Building autonomous action execution for Eddy.
- Production integrations with real transport, EVS, staffing, or discharge
  systems beyond existing source hooks.
- Native mobile implementation beyond contract alignment notes.
- Reworking the Three.js renderer into React Three Fiber.

## 5. Target Architecture

### 5.1 Data Flow

```text
source systems / demo scenario
  -> normalized flow events and projections
  -> barrier/timer enrichment
  -> occupancy insight projector
  -> occupancy API response
  -> snapshot persistence
  -> 4D disks, toolbar rollups, inspector, Eddy context
```

### 5.2 Backend Components

- `PatientFlowController::occupancy`
    - request validation,
    - persona lens resolution,
    - replay/projection loading,
    - demo scenario routing,
    - response assembly.

- `OccupancyInsightProjector`
    - patient/current-position reconstruction,
    - stay duration,
    - origin and next move,
    - timer status,
    - barrier reason extraction,
    - service-line and persona rollups.

- `PatientFlowDemoBarrierScenario`
    - deterministic demo barriers,
    - real facility location selection,
    - scenario metadata.

- New `BarrierTaxonomyService`
    - canonical barrier codes,
    - SLA thresholds,
    - owner roles,
    - RTDC metric mapping,
    - Eddy-safe summaries.

- New `OccupancySnapshotWriter` or extension of `TimelineSnapshotService`
    - periodic persistence of detail and rollup JSON,
    - retention and replay-friendly projection windows.

### 5.3 Frontend Components

- `PatientFlowNavigator.tsx`
    - fetch orchestration,
    - barrier finder state,
    - Eddy prompt/context handoff,
    - camera focus behavior.

- `NavigatorToolbar.tsx`
    - barrier finder toggle,
    - ranked barrier list,
    - persona/service-line rollups,
    - legend affordance.

- `NavigatorScene.ts`
    - disk/pip rendering,
    - disk metadata,
    - finder highlighting,
    - click/focus behavior.

- `NavigatorInspector`
    - grouped detail layout,
    - timer and barrier cards,
    - source lineage,
    - persona-safe fields.

- `features/patientFlowNavigator/*`
    - API mappers,
    - fallback occupancy derivation,
    - typed barrier/timer contracts.

## 6. Data Contract

### 6.1 Occupancy Insight Shape

Each disk should have the following canonical fields:

```json
{
    "key": "patient-or-context:location",
    "location": "MS4A-B001",
    "location_name": "4 - Med Surg bed 001",
    "unit_code": "MS4A",
    "service_line": "adult_med_surg",
    "position_m": { "x": 0, "y": 0, "z": 0 },
    "stay_minutes": 2110,
    "arrived_at": "2026-07-05T00:50:00Z",
    "came_from": "MS4A-HALL",
    "next_move": "Discharge",
    "next_move_at": "2026-07-05T11:05:00Z",
    "primary_status": "delayed",
    "timers": [],
    "blockers": [],
    "barrier_reasons": [],
    "owner_roles": [],
    "delay_impacts": [],
    "source_lineage": [],
    "patient_id": "only when lens allows",
    "patient_display_id": "only when lens allows",
    "encounter_id": "only when lens allows"
}
```

### 6.2 Timer Shape

Every timer should include:

```json
{
    "kind": "arrival_transport | next_transport | evs | readiness | stay",
    "label": "Receiving bed EVS turn",
    "due_at": "2026-07-05T11:32:00Z",
    "minutes_remaining": -28,
    "status": "ok | watch | delayed",
    "source": "EVS bed board",
    "barrier_code": "evs_isolation_clean_delayed",
    "reason": "Isolation clean exceeded target after discharge transport arrived late.",
    "owner_role": "evs",
    "blocks": "PACU discharge and next OR recovery slot",
    "impact": "PACU hold risks OR room recovery delay.",
    "confidence": "definite | probable | possible",
    "source_lineage": []
}
```

### 6.3 Summary Shape

The summary should include:

- active occupancy count,
- delayed count,
- watch count,
- ready-to-move count,
- transport delay count,
- EVS delay count,
- average stay minutes,
- service-line rollups,
- persona rollups,
- timer status counts,
- top barriers,
- top impacted units,
- top impacted service lines,
- current scenario metadata when demo mode is on.

### 6.4 Persona Visibility Rules

- `patient_dots=full`
    - can see patient identifiers,
    - can see disk-level timer and barrier detail,
    - can open patient context links.

- `patient_dots=unit`
    - can see patient identifiers only within shared unit scope,
    - can see aggregate barriers outside that scope.

- `patient_dots=task`
    - can see patient identifiers only for task-linked transport/EVS duties,
    - can see non-PHI task details.

- `patient_dots=none`
    - cannot see patient identifiers,
    - can see aggregate disk heat, barrier counts, service-line pressure, and
      source-safe reasons.

## 7. Barrier Taxonomy

### 7.1 Canonical Barrier Codes

Create a canonical taxonomy, preferably in config first and DB-backed later:

```text
transport_arrival_delay
transport_next_move_delay
transport_equipment_constraint
evs_turn_delay
evs_isolation_clean_delayed
receiving_unit_staffing
receiving_unit_handoff
discharge_authorization
post_acute_packet_pending
home_dme_pending
clinical_clearance_pending
or_pacu_hold
procedure_pickup_watch
stepdown_capacity_delay
staffing_variance
bed_assignment_pending
```

### 7.2 Barrier Metadata

Each barrier code should define:

- display name,
- owner role,
- affected personas,
- default timer kind,
- status thresholds,
- source system,
- source confidence,
- RTDC metric impact,
- escalation path,
- Eddy-safe summary,
- allowed actions,
- whether patient identity may be shown by lens.

### 7.3 Suggested Config Shape

```php
return [
    'evs_isolation_clean_delayed' => [
        'display_name' => 'Isolation clean delayed',
        'owner_role' => 'evs',
        'timer_kind' => 'evs',
        'watch_minutes' => 15,
        'delayed_minutes' => 0,
        'rtdc_metrics' => ['dirty_bed_minutes', 'bed_release_delay'],
        'affected_personas' => ['evs', 'bed_manager', 'charge_nurse'],
        'eddy_summary' => 'EVS clean is delaying bed release.',
    ],
];
```

## 8. Implementation Phases

## Phase 0: Baseline Audit And Guardrails

### Goals

- Confirm the current barrier demo implementation is the baseline.
- Document all existing route, API, and frontend touch points.
- Add a lightweight implementation note if behavior differs from this plan.

### Tasks

- [ ] Run `git status --short` and record unrelated dirty files.
- [ ] Re-read `routes/api.php` patient-flow group.
- [ ] Re-read `routes/web.php` demo session auth changes.
- [ ] Re-read `PatientFlowController::occupancy`.
- [ ] Re-read `OccupancyInsightProjector`.
- [ ] Re-read `PatientFlowDemoBarrierScenario`.
- [ ] Re-read `PatientFlowNavigator.tsx`.
- [ ] Re-read `NavigatorScene.ts`.
- [ ] Re-read `NavigatorToolbar.tsx`.
- [ ] Confirm the current browser route smoke:

```bash
curl -sS -L http://127.0.0.1:8001/rtdc/patient-flow-navigator
```

- [ ] Confirm the focused demo API smoke:

```bash
curl -sS \
  'http://127.0.0.1:8001/api/patient-flow/occupancy?demo=barriers&persona=bed_manager&asOf=2026-07-05T12:00:00Z'
```

### Acceptance Criteria

- [ ] The route renders `RTDC/PatientFlowNavigator`.
- [ ] Occupancy API returns active demo barriers.
- [ ] API includes `demo_scenario`.
- [ ] API includes `top_barriers`.
- [ ] API includes disk-level barrier reasons.

## Phase 1: Canonical Barrier Taxonomy

### Goals

- Move from free-form barrier text to governed barrier codes.
- Keep display text and source-safe Eddy language derived from backend config.

### Backend Tasks

- [ ] Add `config/patient_flow_barriers.php`.
- [ ] Add canonical barrier codes and metadata.
- [ ] Add `BarrierTaxonomyService`.
- [ ] Add methods:
    - [ ] `definition(string $code): array`.
    - [ ] `statusFor(string $code, ?int $minutesRemaining): string`.
    - [ ] `ownerFor(string $code): string`.
    - [ ] `eddySummaryFor(string $code): string`.
    - [ ] `rtdcMetricsFor(string $code): array`.
- [ ] Update `PatientFlowDemoBarrierScenario` to emit `barrier_code`.
- [ ] Update `OccupancyInsightProjector` to resolve barrier metadata.
- [ ] Keep `reason` as narrative detail, but require `barrier_code` for
      normalized analytics.
- [ ] Add tests for unknown barrier codes.
- [ ] Add tests for status thresholds.

### Frontend Tasks

- [ ] Add `barrierCode` to `OccupancyTimer`.
- [ ] Add `rtdcMetrics` to timer or disk detail types.
- [ ] Display human labels from backend response, not local enums.
- [ ] Show barrier code in debug-only inspector rows if useful.

### Acceptance Criteria

- [ ] Top-barrier rollups group by `barrier_code`, not just label/reason.
- [ ] Demo scenario returns at least five distinct barrier codes.
- [ ] Backend tests prove taxonomy fields are present.
- [ ] Frontend build passes.

## Phase 2: Backend Snapshot Persistence

### Goals

- Persist the full occupancy/timer/barrier picture over time.
- Enable RTDC trend analysis instead of current-state-only rendering.

### Backend Tasks

- [ ] Decide whether to extend `TimelineSnapshotService` or add
      `OccupancySnapshotWriter`.
- [ ] Ensure `flow_core.occupancy_snapshots` stores:
    - [ ] `occupancy_details`,
    - [ ] `timer_status_counts`,
    - [ ] `service_line_timer_counts`,
    - [ ] `persona_timer_counts`,
    - [ ] `active_blocker_counts`,
    - [ ] `projection_window`.
- [ ] Add `barrier_code_counts` if not covered by existing JSON fields.
- [ ] Add `source_lineage_counts` if source transparency is needed in RTDC
      dashboards.
- [ ] Add scheduled write path from current snapshot job.
- [ ] Add idempotent upsert keyed by facility, snapshot time, and scenario/live
      mode.
- [ ] Add pruning/retention rules.
- [ ] Add fixture tests with demo scenario and real replay fallback.

### API Tasks

- [ ] Add `GET /api/patient-flow/occupancy/history`.
- [ ] Support query params:
    - [ ] `from`,
    - [ ] `to`,
    - [ ] `interval`,
    - [ ] `persona`,
    - [ ] `service_line`,
    - [ ] `floor`,
    - [ ] `barrier_code`.
- [ ] Return time-series rollups for:
    - [ ] delayed occupancy,
    - [ ] watch occupancy,
    - [ ] transport blockers,
    - [ ] EVS blockers,
    - [ ] discharge blockers,
    - [ ] service-line pressure.

### Acceptance Criteria

- [ ] Snapshot job writes detail JSON.
- [ ] History endpoint returns at least a 24-hour trend.
- [ ] Tests verify snapshot persistence and filtering.
- [ ] Existing current occupancy endpoint remains backward compatible.

## Phase 3: Barrier Finder Panel

### Goals

- Expand the `Barriers` toggle into an operational finder.
- Let users find all barriers and delays without hunting through the 3D scene.

### Frontend Tasks

- [ ] Add a ranked barrier finder panel under the toolbar rollup.
- [ ] Include rows with:
    - [ ] barrier label,
    - [ ] location,
    - [ ] service line,
    - [ ] owner role,
    - [ ] elapsed delay,
    - [ ] next expected move,
    - [ ] impact summary.
- [ ] Add filter chips or segmented controls:
    - [ ] all,
    - [ ] transport,
    - [ ] EVS,
    - [ ] discharge/readiness,
    - [ ] staffing,
    - [ ] long stay.
- [ ] Add sort modes:
    - [ ] severity,
    - [ ] oldest delay,
    - [ ] service-line impact,
    - [ ] owner role,
    - [ ] next due.
- [ ] Clicking a row should:
    - [ ] turn on barrier finder mode,
    - [ ] focus camera on the disk,
    - [ ] open the inspector,
    - [ ] optionally pulse the disk for one second.
- [ ] Add empty states for aggregate personas and no-barrier filters.

### Scene Tasks

- [ ] Add stable scene object keys by occupancy `key`.
- [ ] Add `focusOccupancy(key: string)` to `NavigatorScene`.
- [ ] Add barrier/delay highlight material or outline.
- [ ] Keep non-barrier disks dimmed, not hidden, if the user needs spatial
      context.
- [ ] Add keyboard-accessible focus path from panel row to scene.

### Acceptance Criteria

- [ ] Operator can toggle `Barriers`.
- [ ] Operator can filter to EVS or transport delays.
- [ ] Operator can click a barrier row and land on the correct disk.
- [ ] Inspector opens with barrier fields.
- [ ] Browser smoke verifies the toggle and at least one row click.

## Phase 4: Grouped Disk Inspector

### Goals

- Replace flat key-value rows with an operator-readable detail layout.
- Make the click path a clear answer to "what is blocked and why?"

### Frontend Tasks

- [ ] Add typed selection model:
    - [ ] `patient-token`,
    - [ ] `occupancy-marker`,
    - [ ] `occupancy-timer`,
    - [ ] `projection-ghost`,
    - [ ] `facility-space`.
- [ ] Build `OccupancyInspectorPanel`.
- [ ] Group fields into:
    - [ ] Bed/position,
    - [ ] Patient/encounter context,
    - [ ] Duration and origin,
    - [ ] Next move,
    - [ ] Active timers,
    - [ ] Barrier reason,
    - [ ] Owner/persona,
    - [ ] RTDC impact,
    - [ ] Source lineage,
    - [ ] Eddy handoff.
- [ ] Add timer cards with status color and due/overdue text.
- [ ] Add source chips:
    - [ ] ADT,
    - [ ] transport queue,
    - [ ] EVS board,
    - [ ] discharge tracker,
    - [ ] OR schedule,
    - [ ] staffing grid,
    - [ ] demo scenario.
- [ ] Add persona redaction labels when patient identity is hidden.

### Backend Tasks

- [ ] Ensure inspector payload does not require parsing display strings.
- [ ] Include `source_lineage` arrays for timers.
- [ ] Include `rtdc_metrics` and `metric_impact`.
- [ ] Include `actions_allowed` by persona if action buttons are shown.

### Acceptance Criteria

- [ ] Clicked occupancy disk shows grouped details.
- [ ] Timer pips show timer-specific details.
- [ ] Aggregate personas do not see patient identifiers.
- [ ] No inspector row contains raw PHI from source payloads.

## Phase 5: Visual Legend And Semantics

### Goals

- Make the disk grammar immediately legible.

### Frontend Tasks

- [ ] Add compact legend component.
- [ ] Explain with visual samples:
    - [ ] disk size = duration,
    - [ ] green = on track,
    - [ ] amber = watch,
    - [ ] red = delayed,
    - [ ] pips = active timers,
    - [ ] ghost = future projection.
- [ ] Add hover tooltips for icon-only controls.
- [ ] Add a `What am I seeing?` disclosure if needed, but avoid heavy in-app
      instructional copy.
- [ ] Confirm mobile viewport does not overlap toolbar, status bar, feed, and
      inspector.

### Acceptance Criteria

- [ ] New users can decode disk size/color/pips from the legend.
- [ ] Legend does not cover important scene content.
- [ ] Build and browser smoke pass at desktop and mobile widths.

## Phase 6: Named Demo Scenarios

### Goals

- Make demos repeatable and credible across service lines and personas.

### Scenario Catalog

- [ ] `rtdc_barriers`
    - ED boarding, PACU hold, discharge barrier, ICU stepdown, cath pickup, rehab
      acceptance.
- [ ] `ed_boarding_surge`
    - ED admits waiting on ICU/med-surg beds and transport.
- [ ] `evs_backlog`
    - Dirty beds, isolation cleans, discharge transport slippage.
- [ ] `or_pacu_hold`
    - OR recovery capacity constrained by inpatient bed turns.
- [ ] `weekend_staffing_gap`
    - receiving unit staffing variance delays moves.
- [ ] `post_acute_discharge_gridlock`
    - authorizations, DME, packet completion, transport timing.
- [ ] `critical_care_outflow`
    - ICU stepdown blockers and high-acuity arrivals.

### Backend Tasks

- [ ] Add scenario parameter:

```text
GET /api/patient-flow/occupancy?demo=or_pacu_hold
```

- [ ] Add scenario registry:
    - [ ] key,
    - [ ] label,
    - [ ] description,
    - [ ] affected service lines,
    - [ ] affected personas,
    - [ ] default as-of time,
    - [ ] expected top barriers.
- [ ] Add `GET /api/patient-flow/demo-scenarios`.
- [ ] Support `demo=0` to disable demo replacement.
- [ ] Keep production default off unless explicitly enabled.

### Frontend Tasks

- [ ] Add scenario picker in dev/demo mode.
- [ ] Show current scenario label in toolbar.
- [ ] Trigger occupancy refetch on scenario change.
- [ ] Keep scenario picker hidden or disabled in production unless configured.

### Acceptance Criteria

- [ ] Each named scenario returns deterministic counts.
- [ ] Browser smoke can switch scenarios.
- [ ] Tests assert expected top barriers for at least three scenarios.

## Phase 7: Eddy Structured Context

### Goals

- Give Eddy structured, governed context for barrier reasoning and action
  drafting.
- Avoid making Eddy infer facts from display strings.

### Backend Tasks

- [ ] Add `GET /api/patient-flow/eddy-context`.
- [ ] Or add `eddy_context` object to occupancy response when requested:

```text
GET /api/patient-flow/occupancy?include=eddy_context
```

- [ ] Include:
    - [ ] role/persona,
    - [ ] scope,
    - [ ] as-of time,
    - [ ] current metrics,
    - [ ] top barriers,
    - [ ] barrier-to-owner map,
    - [ ] affected service lines,
    - [ ] recommended focus areas,
    - [ ] source lineage,
    - [ ] redaction state,
    - [ ] action allowlist.
- [ ] Add policy filters so Eddy never receives patient identifiers for
      aggregate personas.
- [ ] Add tests for bed manager vs executive context.

### Frontend Tasks

- [ ] Replace prose-only prefill with structured context plus concise prompt.
- [ ] Keep a readable prompt for the user to inspect.
- [ ] Add "Ask Eddy about selected barrier" from inspector.
- [ ] Add "Ask Eddy about all barriers" from toolbar.
- [ ] Add "Draft huddle summary" action for bed manager/house supervisor.

### Eddy Behavior Requirements

- [ ] Rank barriers by RTDC impact.
- [ ] Separate facts from recommendations.
- [ ] Call out uncertainty and source lineage.
- [ ] Respect persona scope.
- [ ] Recommend human-reviewed actions only.
- [ ] Avoid clinical advice and avoid autonomous execution.

### Acceptance Criteria

- [ ] Eddy can summarize the barrier picture.
- [ ] Eddy can explain why each top barrier matters.
- [ ] Eddy output differs appropriately by persona.
- [ ] Tests verify structured context redaction.

## Phase 8: Persona Comparison Mode

### Goals

- Demonstrate how the same operational picture compounds across service lines
  and personas.

### Frontend Tasks

- [ ] Add a persona lens control for broad-access users:
    - [ ] Bed Manager,
    - [ ] Transport,
    - [ ] EVS,
    - [ ] Charge Nurse,
    - [ ] Hospitalist,
    - [ ] Capacity Lead,
    - [ ] Executive,
    - [ ] PI Lead.
- [ ] Display lens summary:
    - [ ] what this persona can see,
    - [ ] what this persona owns,
    - [ ] what this persona can act on.
- [ ] Update disk labels/inspector by lens.
- [ ] Add comparison panel:
    - [ ] total barriers,
    - [ ] owned by current persona,
    - [ ] visible but not owned,
    - [ ] hidden due to policy.

### Backend Tasks

- [ ] Ensure `persona` query param remains server-authorized.
- [ ] Add `persona_timer_counts` by taxonomy code.
- [ ] Add `owned_by_current_persona` booleans per barrier/timer.
- [ ] Add `visible_detail_level` per disk.

### Acceptance Criteria

- [ ] Bed manager sees patient-level operational detail.
- [ ] Executive sees aggregate pressure and no patient identity.
- [ ] Transport sees transport-owned work.
- [ ] EVS sees EVS-owned work.
- [ ] Browser smoke checks at least bed manager and executive.

## Phase 9: Service-Line Compounding Analytics

### Goals

- Show how a barrier in one domain cascades across service lines.

### Backend Tasks

- [ ] Add service-line dependency mapping:
    - [ ] ED -> inpatient bed assignment,
    - [ ] OR/PACU -> inpatient bed release,
    - [ ] ICU -> stepdown capacity,
    - [ ] med-surg -> discharge/post-acute,
    - [ ] cardiology -> procedure schedule,
    - [ ] rehab -> post-acute placement.
- [ ] Add `compound_pressure_score` per service line.
- [ ] Add `downstream_impacts` to timer/barrier payloads.
- [ ] Add rollups:
    - [ ] delayed by service line,
    - [ ] watch by service line,
    - [ ] owner-role blockers by service line,
    - [ ] net bed capacity impact by service line.

### Frontend Tasks

- [ ] Add service-line compounding strip or panel.
- [ ] Allow clicking a service line to filter/focus disks.
- [ ] Show cross-service-line barrier relationships in inspector.

### Acceptance Criteria

- [ ] ED boarding barrier shows ICU/transport impact.
- [ ] PACU hold shows EVS/surgical floor impact.
- [ ] Discharge barrier shows med-surg/ED bed-release impact.
- [ ] Eddy prompt includes service-line compounding.

## Phase 10: Source Lineage And Confidence

### Goals

- Make each barrier defensible.

### Backend Tasks

- [ ] Add lineage fields to timers:
    - [ ] source system,
    - [ ] source table or service,
    - [ ] source record ID when safe,
    - [ ] generated at,
    - [ ] reliability/confidence,
    - [ ] derived vs direct.
- [ ] Add confidence mapping for demo and real sources.
- [ ] Add lineage tests.

### Frontend Tasks

- [ ] Show lineage chips in inspector.
- [ ] Show confidence in projection ghosts and timers.
- [ ] Avoid alarm styling for possible-confidence projections.

### Acceptance Criteria

- [ ] Every non-stay timer has source lineage.
- [ ] Demo lineage is clearly marked as demo.
- [ ] Real source lineage is shown when available.

## Phase 11: Testing And Verification

### Backend Tests

- [ ] `PatientFlowApiTest` covers:
    - [ ] occupancy contract,
    - [ ] barrier reasons,
    - [ ] taxonomy codes,
    - [ ] demo scenario metadata,
    - [ ] executive redaction,
    - [ ] bed manager identity visibility.
- [ ] Add `BarrierTaxonomyServiceTest`.
- [ ] Add `OccupancySnapshotWriterTest`.
- [ ] Add history endpoint tests.
- [ ] Add Eddy context redaction tests.

### Frontend Tests

- [ ] Add unit tests for occupancy API mapping.
- [ ] Add tests for local fallback barrier rollups.
- [ ] Add tests for barrier finder filtering and sorting.
- [ ] Add tests for grouped inspector rendering.

### Browser Smoke Tests

- [ ] Route loads 4D navigator.
- [ ] Canvas is nonblank.
- [ ] Toolbar metrics show expected demo counts.
- [ ] `Barriers` switch toggles on.
- [ ] Barrier panel rows render.
- [ ] Row click opens inspector.
- [ ] Eddy button prefill includes top barriers.
- [ ] Executive persona hides patient identifiers.

### Commands

```bash
php artisan test --filter=PatientFlowApiTest
php artisan test --filter=FlowWindowTest
./vendor/bin/pint
npm run build
```

## Phase 12: Rollout

### Local Demo Rollout

- [ ] Keep demo mode enabled by default in local.
- [ ] Keep demo replacement enabled by default in local.
- [ ] Provide demo script with exact route and expected counts.
- [ ] Store screenshots after smoke verification.

### Staging Rollout

- [ ] Set `PATIENT_FLOW_DEMO_BARRIERS=true` only if staging demos need it.
- [ ] Set `PATIENT_FLOW_DEMO_BARRIERS_REPLACE_REPLAY=false` if staging has
      real replay data.
- [ ] Verify persona redaction.
- [ ] Verify no raw PHI appears in inspector or Eddy context.

### Production Rollout

- [ ] Keep demo mode disabled by default.
- [ ] Enable current occupancy endpoint only after source lineage and redaction
      tests are green.
- [ ] Enable snapshot writes behind a feature flag.
- [ ] Enable Eddy context after governance review.
- [ ] Monitor API latency and snapshot job duration.

## 9. Detailed TODO Checklist

### Backend Contract

- [ ] Add barrier taxonomy config.
- [ ] Add taxonomy service.
- [ ] Add `barrier_code` to demo timers.
- [ ] Add `barrier_code` to occupancy timers.
- [ ] Add `rtdc_metrics` to occupancy timers.
- [ ] Add `source_lineage` to occupancy timers.
- [ ] Add `owned_by_current_persona` to timers.
- [ ] Add `visible_detail_level` to occupancy disks.
- [ ] Add top impacted units to summary.
- [ ] Add top impacted service lines to summary.
- [ ] Add source-lineage rollups to summary.

### Snapshot Storage

- [ ] Add `barrier_code_counts` JSONB if needed.
- [ ] Add `source_lineage_counts` JSONB if needed.
- [ ] Write periodic occupancy snapshots.
- [ ] Add snapshot history endpoint.
- [ ] Add retention/pruning logic.
- [ ] Add tests for snapshot write/read.

### Demo Scenarios

- [ ] Add scenario registry.
- [ ] Add scenario list endpoint.
- [ ] Add `ed_boarding_surge`.
- [ ] Add `evs_backlog`.
- [ ] Add `or_pacu_hold`.
- [ ] Add `weekend_staffing_gap`.
- [ ] Add `post_acute_discharge_gridlock`.
- [ ] Add `critical_care_outflow`.
- [ ] Add deterministic count tests.

### Frontend Controls

- [ ] Convert `Barriers` toggle into a finder panel.
- [ ] Add owner-role filter.
- [ ] Add service-line filter from barrier panel.
- [ ] Add severity sort.
- [ ] Add oldest-delay sort.
- [ ] Add owner-role sort.
- [ ] Add click-to-focus disk.
- [ ] Add row-to-inspector path.
- [ ] Add selected barrier pulse.

### Inspector

- [ ] Add typed selection model.
- [ ] Build grouped occupancy inspector.
- [ ] Add timer cards.
- [ ] Add source chips.
- [ ] Add RTDC metric impact block.
- [ ] Add Eddy selected-barrier action.
- [ ] Add redaction state display.

### Eddy

- [ ] Add structured Eddy context payload.
- [ ] Add all-barriers Eddy action.
- [ ] Add selected-barrier Eddy action.
- [ ] Add huddle-summary Eddy action.
- [ ] Add persona redaction tests.
- [ ] Add source-lineage requirements to prompt.

### Visual Semantics

- [ ] Add disk legend.
- [ ] Add timer pip legend.
- [ ] Add projection ghost legend.
- [ ] Add confidence styling.
- [ ] Add accessible labels for icon controls.
- [ ] Verify desktop and mobile layout.

### Personas

- [ ] Bed manager lens smoke.
- [ ] Transport lens smoke.
- [ ] EVS lens smoke.
- [ ] Charge nurse lens smoke.
- [ ] Hospitalist lens smoke.
- [ ] Capacity lead lens smoke.
- [ ] Executive lens smoke.
- [ ] PI lead lens smoke.

### Quality Gates

- [ ] `php artisan test --filter=PatientFlowApiTest`.
- [ ] Add taxonomy tests.
- [ ] Add snapshot tests.
- [ ] Add Eddy context tests.
- [ ] Add frontend unit tests.
- [ ] Add browser smoke script.
- [ ] `npm run build`.
- [ ] `./vendor/bin/pint`.

## 10. Open Decisions

- [ ] Should barrier taxonomy start as config-only, DB-backed, or both?
- [ ] Should demo scenarios be API-only or also seed database rows?
- [ ] Should the barrier finder hide non-barrier disks or dim them?
- [ ] Should service-line compounding score be simple weighted count first, or
      tied to RTDC net bed need immediately?
- [ ] Should Eddy context be embedded in occupancy response or separate?
- [ ] Which roles should get action buttons in the first production slice?
- [ ] How long should occupancy detail snapshots be retained?
- [ ] Should production ever allow demo replacement mode, or only additive
      demo overlays?

## 11. Recommended Sequence

1. Barrier taxonomy.
2. Barrier finder panel with click-to-focus.
3. Grouped inspector.
4. Named demo scenarios.
5. Structured Eddy context.
6. Snapshot persistence and history.
7. Persona comparison mode.
8. Service-line compounding analytics.
9. Source lineage hardening.
10. Full browser/persona smoke suite.

This sequence keeps the next visible product improvement close to the current
demo while moving the underlying contract toward production use.

## 12. Definition Of Done

- [ ] The backend has a canonical, tested place for every timer, barrier, owner,
      reason, source, and RTDC impact.
- [ ] The 4D viewer can find, rank, focus, and inspect all barriers and delays.
- [ ] Each disk answers duration, origin, next move, active timers, delay reason,
      owner, and impact.
- [ ] Service-line and persona rollups show compounding pressure.
- [ ] Eddy receives structured governed context.
- [ ] Aggregate personas receive useful pressure context without patient identity.
- [ ] Demo scenarios are deterministic and repeatable.
- [ ] Snapshot persistence supports trend and history views.
- [ ] Automated tests cover contracts, redaction, taxonomy, snapshots, and build.
