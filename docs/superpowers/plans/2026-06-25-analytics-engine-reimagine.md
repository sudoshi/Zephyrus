# Analytics Engine Reimagine Plan

Date: 2026-06-25

## Purpose

Rebuild Analytics from a perioperative reporting dropdown into the hospital operations intelligence engine for Zephyrus. The section should combine real-time situational awareness, retrospective performance review, predictive planning, process mining, and improvement governance so analytic findings become owned operational action.

The first implementation slice replaces the top-level Analytics navigation and overview shell while preserving existing surgical deep-dive routes. Later phases should turn the static shell into live services and governed data products.

## Current-State Findings

- `/analytics` was an OR-focused tab page with service analytics, provider analytics, historical trends, and block utilization.
- Top-level Analytics duplicated the same perioperative links already exposed under the Perioperative dropdown.
- RTDC, ED, Transport, Improvement, and Command Center already contain stronger real-time and process-improvement material, but Analytics did not yet unify them.
- The live Command Center payload already provides a useful cross-domain pattern: capacity, flow, outcomes, forecast, unit census, objectives, generated timestamp, and drill links.
- The analytics API is narrow: service performance, provider performance, and historical trends over `prod.or_cases`, `prod.case_metrics`, `prod.services`, `prod.providers`, and `prod.block_utilization`.
- The repo already has event/log foundations that should become the canonical analytics spine: `operational_events`, `rtdc_predictions`, `rtdc_reconciliations`, `bed_requests`, `bed_placement_decisions`, `ed_visits`, `transport_requests`, `transport_events`, `pdsa_cycles`, and surgical timing/utilization tables.

## Best-Practice Synthesis

Research themes used for this plan:

- Whole-hospital flow should be treated as an interdependent system, not a set of departmental dashboards. IHI RTDC work emphasizes real-time demand/capacity processes integrated into bed management and hospital-wide flow.
- Command centers are most useful when people, process, and technology come together around shared real-time visibility, predictive signals, and coordinated decision-making.
- Centralized management works best when it provides global situational awareness, automated real-time data collection, predictive insight, and a workflow for acting on signals.
- Process mining should operate from event logs so teams can discover variants, waiting states, rework, bottlenecks, and downstream cascade effects rather than relying only on aggregate KPIs.
- Predictive analytics needs a complete pipeline: source integration, transformations, modeling, visualization, back-testing, governance, and leader/clinician participation.
- Forecast alerts should be thresholded and action-oriented; routine daily emails create fatigue and weaken operational trust.
- Model governance should follow a risk-management lifecycle: define intended use, map risks, measure performance, monitor drift, manage mitigations, and preserve accountability.
- Improvement analytics should close the loop through PDSA or equivalent test-of-change workflows, tying process changes to observed outcomes and balancing measures.
- Interoperability should remain standards-oriented. FHIR is a widely used API-focused standard for exchanging health information, and operational analytics should map internal events to resources such as Encounter, Location, ServiceRequest, Task, Observation, and DocumentReference where appropriate.

## Target Product Model

Analytics becomes "Operations Intelligence" with these route-backed sections:

- `/analytics` - Intelligence Hub: hospital-wide signal fusion, action queue, source map, and cross-domain status.
- `/analytics/live` - Live Signals: current capacity, demand, flow, OR, ED, transport, barriers, and huddle exceptions.
- `/analytics/retrospective` - Retrospective Review: trend, control, cohort, variance, service-line, and operator review.
- `/analytics/predictive` - Predictive Planning: forecasts, confidence bands, early-warning thresholds, and back-tested reliability.
- `/analytics/process-intelligence` - Process Intelligence: event-log process mining, variants, bottlenecks, cascade analysis, and root-cause evidence.
- `/analytics/opportunities` - Opportunity Portfolio: prioritized improvement opportunities linked to owners, PDSA cycles, playbooks, and impact.
- `/analytics/workbench` - Scenario Workbench: what-if planning for staffing, beds, discharge acceleration, elective volume, transport, and surge response.
- `/analytics/data-quality` - Data Quality: freshness, completeness, lineage, reconciliation, PHI boundaries, RBAC posture, and model governance.

Existing surgical pages remain as "Surgical Deep Dives":

- `/analytics/block-utilization`
- `/analytics/or-utilization`
- `/analytics/primetime-utilization`
- `/analytics/room-running`
- `/analytics/turnover-times`

## Feature Catalog

### 1. Intelligence Hub

- House strain score with drivers across capacity, flow, outcomes, forecast, and data trust.
- Cross-domain action queue ranked by bed-hours, delay minutes, safety risk, financial value, confidence, and owner.
- Drill-through map into Command Center, RTDC huddles, ED analytics, surgical analytics, transport, and PDSA.
- Executive, house supervisor, service-line, and improvement-team role presets.
- Metric dictionary with definitions, targets, source freshness, and lineage.

### 2. Live Signals

- Capacity: staffed beds, occupancy, blocked beds, available beds, unit-level acuity-adjusted pressure.
- Demand: ED admits, transfer requests, direct admits, OR demand, pending bed requests.
- Flow: door-to-provider, admit-to-bed, boarding, discharge readiness, discharge-before-noon, discharge order-to-exit.
- Surgical flow: first-case on-time starts, PACU holds, turnover, room running, cancellations, cases at risk.
- Transport: active requests, assignment latency, pickup latency, handoff completion, SLA breach risk.
- Ancillary operations: lab, imaging, pharmacy, environmental services, consult, and discharge support queues.
- Alert rules with escalation owner, threshold rationale, snooze rules, and recent alert history.

### 3. Retrospective Review

- Run charts and statistical process control views for core flow metrics.
- Cohort and stratified analysis by unit, location, service, shift, weekday, provider, source, acuity, patient class, and discharge disposition.
- Before/after and interrupted-time-series views for improvement interventions.
- Distributional analytics: medians, percentiles, outliers, tail risk, and variation over averages.
- Variance decomposition: demand mix, staffing, occupancy, service line, room/location, upstream queue, and data quality effects.
- Exportable executive scorecards and evidence packets.

### 4. Predictive Planning

- Forecasts for admissions, discharges, bed need, occupancy, staffed capacity, transport load, ED arrivals, OR demand, and ancillary workload.
- Multi-horizon views: next 4 hours, shift, 24 hours, 7 days, and elective planning windows.
- Confidence intervals, reliability grades, back-test history, drift checks, and model versions.
- Alert thresholds based on operational actionability, not just statistical anomaly.
- Recommendation layer: open beds, flex staff, discharge lounge activation, transport pooling, elective-volume deferral, block release, and escalation huddle.
- Forecast comparison between baseline, current plan, and proposed scenario.

### 5. Process Intelligence

- Canonical event log for ED-to-inpatient, bed placement, discharge, perioperative flow, transport, and ancillary turnaround.
- Process maps with happy path, variants, loops, rework, waits, handoffs, and bottleneck nodes.
- Cascade analysis: how one delay propagates to ED boarding, OR starts, PACU holds, transport queue, or diversion risk.
- Root-cause candidates ranked by impact, prevalence, controllability, and confidence.
- Conformance checks against standard work and playbooks.
- Event-level drill-through with de-identified aggregate defaults and role-gated PHI access.

### 6. Opportunity Portfolio

- Opportunity intake from live alerts, retrospective variance, predictive warnings, process-mining findings, and user-submitted problems.
- Scoring model: patient impact, avoidable bed-hours, wait-time effect, financial value, safety risk, feasibility, equity, confidence, and effort.
- Owner, due date, affected workflows, linked metrics, baseline, target, countermeasure, and balancing measures.
- PDSA integration: Plan, Do, Study, Act phases with analytic evidence attached.
- Sustainment tracking after successful interventions.
- Library of proven playbooks for discharge acceleration, bed placement reliability, OR starts, turnover, transport SLA, and ancillary delays.

### 7. Scenario Workbench

- What-if controls for staffed beds, blocked beds, discharges by time, transport resources, staffing, elective cases, and surge inputs.
- Constraint-aware simulation outputs: projected net beds, boarding, occupancy, delay minutes, staff load, and confidence.
- Assumption ledger and approval history.
- Save, compare, and revisit scenarios against actual outcomes.
- Planning modes for bed meeting, daily huddle, weekly surgical governance, staffing office, and quarterly service-line planning.

### 8. Data Quality And Governance

- Freshness checks per source and metric.
- Completeness and plausibility checks for required timestamps and dimensions.
- Reconciliation between event streams and current-state projections.
- Metric registry with definition, numerator, denominator, exclusions, owner, target, and source SQL/service.
- PHI minimization and role-based access rules.
- Model cards for predictive assets: intended use, non-use, features, training window, validation, drift, bias/equity review, owner, version, rollback path.
- Audit trail for analytic output used in operational decisions.

## Canonical Data Spine

Recommended core projections:

- `prod.analytics_metric_definitions`: metric key, label, domain, definition, target, direction, owner, source query/service.
- `prod.analytics_metric_snapshots`: metric key, period, grain, dimension payload, value, target, status, computed_at, source_hash.
- `prod.analytics_events`: canonical event view or materialized table normalized from operational, RTDC, transport, OR, ED, and discharge events.
- `prod.analytics_alerts`: signal, threshold, status, severity, owner, opened_at, acknowledged_at, resolved_at, linked entity.
- `prod.analytics_opportunities`: opportunity score, source signal, owner, status, expected impact, linked PDSA cycle.
- `prod.analytics_model_runs`: model key, version, run time, features hash, horizon, predictions, confidence, reliability, drift, status.
- `prod.analytics_data_quality_checks`: source, check key, status, observed value, threshold, checked_at, remediation owner.

Do not duplicate clinical source-of-truth records. Analytics tables should be projections, snapshots, evidence, and governance records.

## API Roadmap

Phase 1 shell routes are Inertia routes. Future JSON endpoints:

- `GET /api/analytics/overview`
- `GET /api/analytics/live`
- `GET /api/analytics/retrospective`
- `GET /api/analytics/predictive`
- `GET /api/analytics/process-intelligence`
- `GET /api/analytics/opportunities`
- `GET /api/analytics/workbench/scenarios`
- `POST /api/analytics/workbench/scenarios`
- `GET /api/analytics/data-quality`
- `GET /api/analytics/metrics/{metricKey}/lineage`
- `GET /api/analytics/models/{modelKey}/backtests`
- `POST /api/analytics/alerts/{alertId}/acknowledge`
- `POST /api/analytics/opportunities`
- `POST /api/analytics/opportunities/{opportunityId}/link-pdsa`

## Frontend Principles

- Keep the first screen operational, not promotional.
- Prefer dense panels, status strips, queues, tables, segmented controls, drill links, and icon-led action buttons.
- Separate live operational action from retrospective review and predictive planning so users know which evidence is current-state, historical, or forecasted.
- Every insight should show owner, horizon, confidence/trust, and next action.
- Keep surgical analytics available but scoped as a domain deep dive, not the whole Analytics section.
- Make data-quality state visible near the insight, not hidden in admin tooling.

## Phased Delivery

### Phase 1: Navigation And Shell

Delivered in this slice:

- Route-backed Analytics sections.
- Reimagined top-level Analytics dropdown.
- New `/analytics` Operations Intelligence shell.
- Preserved existing surgical deep-dive pages.
- Durable plan artifact.

### Phase 2: Unified Metrics Service

- Build `AnalyticsMetricService`.
- Add metric registry and snapshot tables.
- Compute hub/live metrics from current DB tables.
- Add Zod contracts for analytics payloads.
- Add PHPUnit feature tests for every metric definition and empty-window behavior.

### Phase 3: Data Quality And Lineage

- Add freshness/completeness/plausibility checks.
- Surface source health in `/analytics/data-quality`.
- Add metric lineage drill-through.
- Add role-aware PHI/de-identified display checks.

### Phase 4: Process Mining

- Normalize canonical event logs.
- Add process maps for ED-to-inpatient, discharge, bed placement, transport, and surgical flow.
- Add variant, bottleneck, wait-state, rework, conformance, and cascade views.
- Link findings to opportunities and PDSA cycles.

### Phase 5: Predictive Engine

- Add forecast service and model-run ledger.
- Start with heuristic and statistical baselines before trained models.
- Add back-testing, confidence, drift, and alert thresholds.
- Introduce scenario workbench using the same forecast contracts.

### Phase 6: Opportunity Governance

- Add opportunity intake/scoring.
- Link live alerts, retrospective signals, forecasts, and process-mining findings to PDSA.
- Track expected impact, actual impact, balancing measures, and sustainment.
- Add executive and service-line portfolio views.

## Acceptance Criteria

- Analytics dropdown no longer reads as a duplicate perioperative menu.
- `/analytics` presents a hospital-wide operations intelligence hub.
- Existing surgical analytics URLs remain valid.
- Every new Analytics section has a route and visible entry point.
- Plan explains how real-time, retrospective, predictive, process-mining, and improvement workflows connect.
- Future metric/model work has clear data, API, governance, and test requirements.

## Research Sources

- PubMed: Using real-time demand capacity management to improve hospitalwide patient flow: https://pubmed.ncbi.nlm.nih.gov/21618898/
- IHI Hospital Flow Professional Development Program agenda: https://forms.ihi.org/hubfs/IHI%20Hospital%20Flow%20Professional%20Development%20Example%20Agenda_April%202022.pdf
- Johns Hopkins Medicine command center article: https://www.hopkinsmedicine.org/news/articles/2016/03/command-center-to-improve-patient-flow
- Centralized hospital management review: https://pmc.ncbi.nlm.nih.gov/articles/PMC10637563/
- Real-time healthcare predictive analytics deployment: https://pmc.ncbi.nlm.nih.gov/articles/PMC10120788/
- Patient-flow optimization review: https://pmc.ncbi.nlm.nih.gov/articles/PMC10910643/
- Health Catalyst patient-flow ML implementation practices: https://www.healthcatalyst.com/learn/insights/improve-hospital-patient-flow-with-machine-learning
- NIST AI Risk Management Framework: https://www.nist.gov/itl/ai-risk-management-framework
- AHRQ/NCBI quality improvement and PDSA overview: https://www.ncbi.nlm.nih.gov/books/NBK2682/
- ONC HL7 FHIR interoperability overview: https://healthit.gov/interoperability/investments/fhir/
- HL7 FHIR Encounter resource: https://build.fhir.org/encounter.html
