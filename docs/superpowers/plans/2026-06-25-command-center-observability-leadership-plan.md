# Zephyrus Command Center Observability Leadership Plan

**Date:** 2026-06-25
**Status:** Active plan
**Scope:** Command Center dashboard, 90-day drill-down backend, patient safety and quality opportunity engine

## Executive Intent

Zephyrus should make the main Command Center feel like the operating system for hospital operations: a real-time command wall, a retrospective learning system, and a predictive decision engine in one surface. The current dashboard already has the right skeleton: House Status, Capacity, Flow, Outcomes, Forecast, unit heat strip, forecast curve, role switching, and OKR view. The next leap is to make every panel interactive, every metric drillable, every trend explainable, and every operational defect convertible into an accountable safety or quality improvement action.

The product claim should not be "another dashboard." The product claim should be:

Zephyrus turns hospital operations telemetry into safer care, faster flow, clearer accountability, and measurable improvement.

## Grounding References

This plan is anchored in current hospital operations and safety guidance:

- IHI frames hospital-wide patient flow as a system-wide problem requiring demand shaping, capacity-demand matching, redesign, advanced analytics, and a hospital-wide learning system: https://www.ihi.org/library/white-papers/achieving-hospital-wide-patient-flow
- The Joint Commission's 2026 National Performance Goals emphasize measurable, actionable safety and quality goals for hospitals and critical access hospitals: https://www.jointcommission.org/en-us/standards/national-performance-goals
- AHRQ's National Healthcare Safety Dashboard tracks patient and workforce safety measures including Patient Safety Indicators, Medicare adverse events, CMS safety measures, and safety culture: https://datatools.ahrq.gov/action-alliance/
- AHRQ PSNet describes situational awareness as perception, understanding, and prediction of events, and ties it directly to visible hospital hazards such as medication errors, infection risk, hand hygiene, restraints, and catheter harm: https://psnet.ahrq.gov/web-mm/situational-awareness-and-patient-safety
- AHRQ Patient Safety Learning Laboratories support systems engineering approaches to improve safety and quality: https://www.ahrq.gov/patient-safety/resources/learning-lab/index.html
- CMS public reporting and Overall Hospital Quality Star Rating updates increasingly emphasize patient safety performance and Safety of Care: https://www.cms.gov/medicare/quality/initiatives/hospital-quality-initiative/hospital-compare

## Current Command Center Assessment

### Code Surface Reviewed

- Page composition: `resources/js/Components/CommandCenter/CommandCenterView.tsx`
- Hero and role behavior: `HeroWall.tsx`, `StrainIndex.tsx`, `OkrScoreboard.tsx`, `RoleSwitcher.tsx`
- Panel and KPI components: `Band.tsx`, `KpiTile.tsx`, `UnitHeatStrip.tsx`, `ForecastCurve.tsx`
- Backend payload: `app/Services/CommandCenterDataService.php`
- Controller and routes: `app/Http/Controllers/CommandCenterController.php`, `routes/web.php`, `routes/api.php`
- Existing contracts and tests: `resources/js/types/commandCenter.ts`, `tests/Feature/CommandCenterDataServiceTest.php`, `tests/Feature/CommandCenterControllerTest.php`, `tests/js/commandCenter/contract.test.tsx`

### What Works

- The dashboard is already organized around a defensible structure: Capacity, Flow, Outcomes, Forecast.
- The backend computes live-derived headline metrics and returns 90-point trajectories for all KPIs.
- KPI tiles include targets, trajectories, status, definitions, and route-level drill links.
- Flow is usefully split into ED, inpatient, and OR subgroups.
- Forecast detail already has an occupancy curve and unit net-bed projection.
- Executive mode turns the hero section into an OKR scoreboard.

### Main Gaps

- Interactivity is still mostly "click tile to navigate." The Command Center should let users inspect, compare, simulate, assign, and learn in place.
- The backend had 90-point trajectories but lacked a separate drill-down detail contract with dates, event rows, unit histories, opportunities, and recommended actions.
- Unit heat strip cards are not yet interactive despite being one of the highest-value surfaces.
- Forecast curve tooltips show values but do not expose drivers, uncertainty, intervention levers, or backtesting.
- Outcome metrics are not yet tied to precursor patterns in capacity and flow.
- Patient safety and quality opportunities are not yet first-class objects.

## Backend Step Completed In This Pass

The backend now has a synthetic 90-day minimum drill-down surface:

- New service: `app/Services/CommandCenterDrilldownService.php`
- New API: `GET /api/command-center/drilldown`
- Supported query params:
  - `focus=panel:capacity|flow|outcomes|forecast`
  - `focus=metric:<metric_key>`
  - `focus=unit:<unit_id>`
  - `days=<1..180>` with a hard minimum of 90 returned days
- Payload includes:
  - `window`: start, end, days, grain, minimumDrillDays, synthetic flag
  - `panels`: panel daily rows, metric histories, recommended interactions
  - `timeline`: 90 daily house-level snapshots with drivers
  - `units`: 90 daily rows per unit, or synthetic house fallback when no units exist
  - `events`: at least one synthetic operations/safety event per day
  - `opportunities`: safety and quality improvement opportunity cards
  - `playbooks`: huddle and recovery playbooks
  - `dataQuality`: synthetic lineage and clinical-use notice

This preserves the existing Inertia dashboard payload while creating a richer API contract for the next UI layer.

## Product North Star

Zephyrus should operate as a closed-loop hospital observability platform:

1. Sense: ingest real-time signals from ED, inpatient, OR, transport, EVS, staffing, ADT, EHR, bed management, scheduling, labs, pharmacy, and patient-safety systems.
2. Understand: normalize signals into a hospital operations graph: patients, beds, units, rooms, services, staff, barriers, predictions, actions, and outcomes.
3. Predict: forecast demand, capacity, discharges, boarding risk, diversion risk, OR overrun risk, and safety exposure.
4. Explain: show why a metric is moving, what changed, which unit or process step is responsible, and what uncertainty remains.
5. Act: convert an insight into a huddle item, assigned owner, escalation, PDSA cycle, or operational playbook.
6. Learn: compare predicted vs actual, action vs outcome, and process change vs patient safety result over 90 days and longer.

## Panel-by-Panel Interactivity Plan

### 1. House Status / Command Wall

Current state:

- `StrainIndex` shows a 0-4 surge level, trend, and three drivers.
- Hero KPI tiles show occupancy, net beds, ED boarding, and discharges ready.

Upgrade the interaction model:

- Click surge gauge to open a "House Strain Trace" drawer with 90 daily levels, hourly intra-day trace, and the drivers that contributed to each level.
- Add driver chips for occupancy, boarding, pending admits, blocked beds, staffing, discharge readiness, and forecast reliability.
- Add a "why now" explainer: last 4 hours, last 24 hours, last 7 days, and 90-day percentile.
- Add an escalation timeline: who was notified, which huddles opened, which actions remain unresolved.
- Add "compare similar days" that finds prior days with the same strain pattern and shows what recovered the system fastest.
- Add a "safety exposure" counter: boarded patients, time at risk, high-acuity waiting-room exposure, isolation bed mismatch, fall-risk boarding, and medication-timing risk.
- Add a command-mode action tray:
  - Open capacity huddle
  - Escalate blocked beds
  - Pull forward discharge work queue
  - Review boarded ED patients
  - Run 4-hour net-bed forecast

Backend data needed:

- 90 days of strain history, drivers, event rows, huddle actions, and outcomes.
- Hourly snapshots for real-time mode when available.
- Synthetic support exists now through `/api/command-center/drilldown`.

### 2. Capacity Panel

Current state:

- Capacity band shows available beds, blocked beds, acuity-adjusted occupancy.
- Unit heat strip shows unit occupancy, open beds, blocked beds.

Upgrade the interaction model:

- Make every unit card clickable with a side panel:
  - 90-day occupancy
  - acuity-adjusted load
  - available beds
  - blocked beds by reason
  - staffing-adjusted capacity
  - isolation and specialty bed constraints
  - top recurring barriers
  - recovery actions that worked previously
- Add filters:
  - Adult/peds
  - ICU/step-down/med-surg/ED/observation
  - bed class
  - isolation requirement
  - staffing state
  - physical vs staffed vs operational capacity
- Add a bed-readiness waterfall:
  - dirty
  - cleaning
  - ready
  - assigned
  - occupied
  - blocked
  - staffing unavailable
- Add "capacity debt" by unit:
  - blocked bed-hours
  - staffing gap bed-hours
  - discharge-ready wait hours
  - placement wait hours
- Add unit-to-unit pressure map showing where downstream blocks are creating upstream ED boarding.
- Add one-click barrier conversion:
  - create barrier
  - assign owner
  - expected recovery time
  - escalation level
  - close-loop verification

Quality and safety opportunity layer:

- Flag infection-prevention mismatches when isolation need exceeds available isolation beds.
- Flag high fall-risk or delirium-risk patients placed in inappropriate capacity states.
- Flag unsafe boarding exposure when ED boarding rises while inpatient capacity appears available but blocked.
- Flag workforce safety risk when occupancy remains above 90 percent for repeated days.

### 3. Flow Panel

Current state:

- Flow band has ED, inpatient, and OR subgroups.
- ED: door-to-provider, LWBS, ED LOS.
- Inpatient: admit-to-bed, discharge by noon.
- OR: first-case on-time starts, block utilization, turnover, cancellations.

Upgrade the interaction model:

- Add swimlane tabs inside the Flow panel:
  - ED arrival to disposition
  - admitted ED patient to bed
  - inpatient discharge readiness
  - OR first case and turnover
  - transfer and transport dependencies
- Add a 90-day process replay:
  - choose a day
  - replay hourly arrivals, bed requests, clean-room readiness, discharges, OR starts, and bottlenecks
  - show process steps as a time-aware journey map
- Add cohort filtering:
  - acuity
  - admit/discharge/transfer
  - service line
  - unit
  - hour of day
  - day of week
  - provider group
  - surgical service
- Add "delay reason decomposition" for each KPI:
  - waiting for provider
  - waiting for bed
  - waiting for orders
  - waiting for transport
  - waiting for EVS
  - waiting for consult
  - waiting for test result
  - waiting for OR readiness
- Add bottleneck ranking:
  - minutes lost
  - patients affected
  - safety exposure
  - avoidable bed-days
  - recurrence
  - owner
  - solvability
- Add metric-to-action buttons:
  - open ED surge pod
  - reassign provider-in-triage
  - release bed hold
  - escalate discharge barrier
  - open OR recovery huddle
  - create PDSA opportunity

Quality and safety opportunity layer:

- LWBS and door-to-provider become early warning signals for diagnostic delay and equity risk.
- Admit-to-bed and ED boarding become safety-exposure signals for medication timing, falls, infection prevention, and handoff reliability.
- OR delays become access, handoff, and procedural reliability signals.
- Discharge by noon becomes a patient-flow and transition-of-care reliability signal, not a vanity throughput metric.

### 4. Outcomes Panel

Current state:

- Outcomes shows readmission, LOS/GMLOS, excess bed-days, diversion hours, active PDSA.

Upgrade the interaction model:

- Click any outcome to open a causal trace:
  - the 90-day outcome trend
  - precursor capacity signals
  - precursor flow signals
  - process defects
  - affected units/services
  - active improvement work
  - projected improvement opportunity
- Add an "outcome bridge" that explains movement:
  - baseline
  - volume mix
  - acuity mix
  - operational delay
  - discharge delay
  - readmission cohort
  - intervention effect
- Add safety and quality opportunity cohorts:
  - preventable readmission risk
  - excess LOS outliers
  - discharge transition failures
  - avoidable bed-day clusters
  - diversion-adjacent access failures
  - repeated unit-level process defects
- Add PDSA conversion:
  - turn a recurring outcome defect into a PDSA cycle
  - assign owner
  - attach metric baseline and target
  - define intervention
  - monitor daily control chart
  - close with measured effect
- Add reliability/control views:
  - run chart
  - control chart
  - pre/post intervention comparison
  - outlier days
  - special-cause signals

Quality and safety opportunity layer:

- Align opportunities to Joint Commission 2026 National Performance Goals where applicable.
- Map event families to AHRQ-style safety domains: timely care, infection prevention, medication safety, safe transitions, workforce safety, culture of safety.
- Tie operational failure modes to patient harm prevention, not only financial throughput.

### 5. Forecast Panel

Current state:

- Forecast band shows predicted discharges, ED arrivals, projected net beds, and surge probability.
- Forecast curve shows 24-hour occupancy range.

Upgrade the interaction model:

- Make the forecast curve scrub-able:
  - hover hour to show predicted admissions, discharges, occupancy, confidence band, net bed position, and likely bottleneck.
  - click an hour to list the units driving projected deficit.
- Add what-if controls:
  - add 5 discharges before noon
  - recover 3 blocked beds
  - add staffing capacity
  - reduce OR add-ons
  - surge ED arrivals by 10/20/30 percent
  - accelerate EVS turnaround
  - hold transfers
- Add forecast backtesting:
  - predicted vs actual by day
  - model bias
  - mean absolute error
  - reliability by unit
  - calibration by horizon
- Add intervention recommendations:
  - "To avoid negative net beds at 16:00, recover 4 beds by 13:00 or complete 6 additional discharges by noon."
  - "Unit 5 East is the limiting constraint; discharge reliability and blocked-bed recovery have the largest marginal effect."
- Add scenario comparison:
  - baseline
  - discharge acceleration
  - blocked-bed recovery
  - staffing recovery
  - ED surge
  - OR overrun
- Add forecast provenance:
  - data freshness
  - missing feeds
  - uncertainty drivers
  - last model backtest result

Quality and safety opportunity layer:

- Forecast risk should translate into patient safety exposure:
  - likely ED boarding hours
  - number of high-acuity boarders
  - transfer delay risk
  - staffing load risk
  - isolation placement risk

### 6. OKR / Executive Mode

Current state:

- Executive role shows objectives and key results.

Upgrade the interaction model:

- Make each KR clickable into:
  - current value
  - baseline
  - target
  - 90-day trend
  - expected target date
  - main blockers
  - active interventions
  - leader accountable
  - confidence of success
- Add "board packet" export:
  - one-page executive summary
  - trend charts
  - notable risks
  - improvement wins
  - active decisions needed
- Add "strategy to operations trace":
  - objective
  - key result
  - operational metric
  - unit/service driver
  - action owner
  - patient-safety impact
- Add "investment simulator":
  - additional nurse staffing
  - EVS capacity
  - discharge lounge
  - provider-in-triage
  - OR block redesign
  - transport resource
  - compare estimated effect and confidence

## Data Architecture Roadmap

### Current Backend Contract

The current page payload remains:

- `generatedAtIso`
- `strain`
- `heroMetrics`
- `capacity`
- `flow`
- `outcomes`
- `forecast`
- `forecastDetail`
- `unitCensus`
- `objectives`

The new drill-down API adds a detail layer without breaking the page:

- `GET /api/command-center/drilldown`
- 90-day minimum returned even when `days` is lower.
- Synthetic detail clearly labeled.

### Next Data Objects

Create first-class backend objects for:

- `operations_snapshots`: dated and hourly house/unit metric states.
- `operations_events`: operational defect, bottleneck, action, escalation, and resolution events.
- `safety_opportunities`: quality/safety opportunity objects with domain, cohort, severity, action, owner, and status.
- `forecast_runs`: model inputs, outputs, uncertainty, and predicted-vs-actual.
- `huddle_actions`: decision, owner, due time, escalation, completion, measured effect.
- `process_variants`: discovered flow variants and bottlenecks from process mining.
- `unit_capacity_states`: physical, staffed, operational, blocked, isolation, and specialty capacity.
- `metric_lineage`: source table/feed, freshness, transformation, confidence, and known caveats.

### Synthetic-to-Real Migration

Phase the synthetic backend into live detail:

1. Keep the current synthetic endpoint as a contract stabilizer.
2. Persist daily `operations_snapshots` from live aggregates.
3. Backfill 90 days from existing prod tables where possible.
4. Mark each field with lineage: live, inferred, synthetic, unavailable.
5. Replace synthetic events with real operational events, barriers, bed requests, OR cases, ED visits, transport requests, and PDSA actions.
6. Keep synthetic fallback for demo tenants and tests.

## Patient Safety and Quality Opportunity Engine

### Opportunity Object

Each opportunity should include:

- title
- patient safety domain
- operational lever
- affected cohort
- current signal
- target
- severity
- confidence
- estimated impact
- first recommended actions
- owner
- due time
- evidence trace
- associated NPG/CMS/AHRQ measure when applicable
- status
- measured result

### Opportunity Families

- ED boarding safety exposure
- waiting-room diagnostic delay
- LWBS equity risk
- high-acuity boarding
- medication timing risk during boarding
- infection/isolation mismatch
- fall-risk placement mismatch
- discharge transition failure
- avoidable readmission cluster
- excess LOS and delayed progression
- blocked-bed recurrence
- OR cancellation and access loss
- first-case delay reliability
- turnover delay and handoff risk
- diversion risk and community access
- workforce overload and burnout risk

### Innovative Differentiators

- Safety Exposure Minutes: convert operational delay into patient-safety exposure time.
- Harm-Precursor Graph: link capacity and flow signals to downstream safety outcomes.
- Forecast-to-Action Bridge: translate predicted deficits into exact bed, discharge, staffing, and barrier actions.
- Reliability Backtesting: show whether predictions and interventions actually worked.
- Improvement Opportunity Autopilot: convert recurring defects into PDSA cycles with baseline, owner, target, and monitoring.
- Unit Pressure Propagation: show how a downstream unit constraint creates ED, OR, transfer, and discharge consequences.
- Digital Twin Replay: replay a hospital day and compare alternate interventions.
- Safety Equity Lens: stratify delays and outcomes by unit, service, acuity, language, payer proxy, arrival mode, and social-risk flags when available and governed.
- Command Huddle Memory: every huddle action becomes structured data that can be learned from.

## UI Build Sequence

### Phase 1: Drill-In Drawers

- Add a `CommandCenterDrillDrawer`.
- Click KPI tile opens metric history from `/api/command-center/drilldown?focus=metric:<key>`.
- Click panel header opens panel daily history.
- Click unit heat card opens unit detail.
- Add loading, error, empty, and synthetic-data states.
- Keep existing route links as secondary actions inside drawers.

### Phase 2: Interactive Unit Heat Strip

- Unit cards become buttons.
- Add unit detail side panel with 90-day history.
- Add capacity waterfall.
- Add top barriers and safety opportunities.
- Add compare unit option.

### Phase 3: Flow Process Replay

- Add Flow panel tabs.
- Add 90-day date picker.
- Add event timeline using synthetic events first.
- Add cohort filters.
- Add bottleneck ranking and owner/action conversion.

### Phase 4: Forecast What-If

- Add forecast scrubber.
- Add scenario controls.
- Add net-bed-by-unit table.
- Add prediction confidence and backtesting.
- Add recommended action generator.

### Phase 5: Outcomes and Improvement Loop

- Add outcome causal traces.
- Add opportunity-to-PDSA conversion.
- Add control charts and intervention annotations.
- Add board-ready exports.

### Phase 6: Real-Time Operations Mode

- Add polling or websocket transport.
- Add event freshness indicators.
- Add command huddle board.
- Add unresolved action counter.
- Add alert acknowledgement and escalation.

## Acceptance Criteria

Backend:

- `/api/command-center/drilldown` always returns at least 90 daily timeline rows.
- Each panel has 90 daily rows.
- Each metric has 90 history rows.
- Event list has at least 90 synthetic events.
- Payload includes synthetic lineage and clinical-use notice.
- Focus resolution works for metric, panel, and unit keys.
- Existing dashboard payload remains unchanged.

Frontend:

- Every Command Center panel has a meaningful click target.
- Every KPI tile opens an in-place drawer before routing away.
- Unit heat strip supports unit-level drill-down.
- Forecast curve supports hover and click inspection.
- Outcome metrics trace to precursor capacity/flow signals.
- Role switcher changes emphasis without losing drill capability.

Product:

- Every insight can become an action.
- Every action has an owner, due time, and measured result.
- Every metric has lineage, target, trend, and explanation.
- Every safety opportunity can be tied to a patient safety domain.
- Every forecast can be backtested.

## Validation Plan

- PHP:
  - `php artisan test --filter=CommandCenterDataServiceTest`
  - `php artisan test --filter=CommandCenterControllerTest`
  - `vendor/bin/pint app/Services/CommandCenterDrilldownService.php app/Http/Controllers/CommandCenterController.php routes/api.php tests/Feature/CommandCenterDataServiceTest.php tests/Feature/CommandCenterControllerTest.php`
- JS:
  - `npx vitest run tests/js/commandCenter/contract.test.tsx`
  - `npm run build`
- Manual:
  - call `/api/command-center/drilldown?focus=panel:flow&days=30`
  - verify `window.days = 90`
  - verify panel/metric histories
  - verify events and opportunities are synthetic and non-PHI

## Risks and Controls

- Risk: synthetic detail may be mistaken for clinical truth.
  - Control: explicit `synthetic` flags, data-quality lineage, and clinical-use notice in payload and UI.
- Risk: too much interactivity overwhelms users.
  - Control: progressive disclosure: tile, drawer, full route, action.
- Risk: forecasts imply precision they do not have.
  - Control: confidence bands, backtesting, and model lineage.
- Risk: safety opportunity engine becomes alert fatigue.
  - Control: rank by severity, recurrence, solvability, and patient-safety exposure.
- Risk: dashboard becomes a passive reporting layer.
  - Control: every insight must have an action path and closure tracking.

## Strategic Demonstration Story

The demonstration should tell one coherent hospital day:

1. Morning: Command Center detects rising occupancy and negative projected net beds.
2. Capacity: Unit heat strip shows one unit is the constraint, with blocked beds and staffing pressure.
3. Flow: ED boarding and admit-to-bed delay are increasing, with high-acuity boarding exposure.
4. Forecast: what-if shows that recovering 3 blocked beds and completing 5 discharges by noon prevents the afternoon deficit.
5. Action: Zephyrus opens a capacity huddle, assigns blocked-bed and discharge actions, and tracks owner closure.
6. Outcomes: the system shows how similar days produced excess bed-days and diversion risk.
7. Learning: next day, predicted vs actual and action vs result are visible; a recurring defect becomes a PDSA cycle.

That is the product leap: Zephyrus does not only show that the hospital is strained. It explains why, predicts what will happen, tells leaders what to do, tracks whether they did it, and learns whether it made patients safer.
