# Zephyrus Competitor Leapfrog Execution Plan

Date: 2026-06-26
Status: Executable strategy and implementation plan
Supersedes: `docs/superpowers/plans/2026-06-25-zephyrus-flow-os-market-leapfrog-plan.md`
Related plans:
- `docs/superpowers/plans/2026-06-25-real-time-healthcare-data-acquisition.md`
- `docs/superpowers/plans/2026-06-25-analytics-engine-reimagine.md`
- `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md`
- `docs/superpowers/plans/2026-06-25-hospital-blueprint-ingestion-digital-twin.md`

## 1. Executive Thesis

The competitor bar is higher than "build a command center" or "add AI." The current market already includes:

- EHR-native patient-flow suites with transport, EVS, predictive analytics, and transfer center workflows.
- Patient-flow platforms with command centers, access, throughput, analytics, AI-powered workflows, and large installed bases.
- AI capacity and perioperative automation vendors positioned as teammates or prescriptive optimizers.
- Command-center vendors that now publicly claim AI-enabled tiles and digital-twin simulation.
- Broad AI/data platforms positioning themselves as hospital operating systems.
- Ambient smart-hospital platforms that see physical room, patient, and staff activity that EHR-only systems miss.

Zephyrus should exceed them by becoming the evidence-grade hospital operations execution layer:

> Zephyrus Flow OS turns fragmented EHR, ADT, bed, transport, perioperative, staffing, facility, payer, ambient, and external-network signals into a live operational graph, simulates safe action plans, routes approved work to accountable owners, and proves impact with source-level lineage and intervention attribution.

The winning claim is not "we have dashboards," "we have AI," or "we have a digital twin." Competitors already say those things. The winning claim must be:

> Every signal, recommendation, action, approval, and measured outcome in Zephyrus is traceable, testable, replayable, and accountable.

## 2. Current Zephyrus Assessment

This plan is grounded in the current checkout, not only the README. The worktree was clean during this review, and the Laravel route surface was verified with `php artisan route:list`.

### 2.1 Existing Product Surface

Zephyrus already has the shape of a hospital operations platform:

- Command Center: `/dashboard` plus `CommandCenterDataService`, drilldown API, dashboard tests, and frontend contract tests.
- RTDC: 33 RTDC routes covering huddles, unit predictions, census, barriers, bed requests, and bed-placement recommendations.
- Analytics: 32 analytics-related routes across the top-level Operations Intelligence shell, ED, RTDC, transport, and surgical deep dives.
- Transport: 21 transport routes covering requests, dispatch, inpatient, transfers, discharge, EMS, resources, analytics, vendors, and handoff events.
- Patient Flow 4D: authenticated APIs for summary, locations, events, tracks, state, FHIR bundle, HL7 v2 ingest, and ADT stream.
- Facility model: hospital blueprint/CAD/BIM ingestion schemas and `/api/facility/model/summary`.
- Integration foundation: `integration`, `raw`, and `fhir` tables for source registry, source endpoints, credentials references, ingest runs, inbound messages, canonical events, watermarks, dead letters, resource versions, resource links, identity links, and provenance.
- Ops graph: `ops.nodes`, `ops.edges`, `ops.state_snapshots`, `ops.constraints`, `ops.recommendations`, `ops.actions`, and `ops.approvals`, with `OperationsGraphProjector` projecting locations, services, rooms, units, beds, encounters, ED visits, OR cases, bed requests, transport requests, and barriers.
- RTDC optimizer: transparent weighted bed-assignment recommendations with hard-constraint pruning and an interface designed to swap in a stronger optimizer later.
- Tests: targeted backend and frontend coverage for command center, analytics, RTDC, transport, patient flow, facility model, integrations, and ops graph.

### 2.2 Important Repo Seams

Use these as the implementation backbone:

- Integration contracts: `app/Integrations/Healthcare/Contracts/HealthcareConnector.php`
- Canonical event persistence: `app/Integrations/Healthcare/Services/CanonicalEventWriter.php`
- Ops graph projection: `app/Services/Ops/OperationsGraphProjector.php`
- Command Center payload: `app/Services/CommandCenterDataService.php`
- Transport workflow events: `app/Services/Transport/TransportOperationsService.php`
- Patient-flow normalization: `app/Services/PatientFlow/FlowEventNormalizer.php`
- Bed placement optimizer: `app/Rtdc/Optimizer/HeuristicBedAssignmentOptimizer.php`
- Navigation IA: `resources/js/config/navigationConfig.ts`
- Web/API routes: `routes/web.php`, `routes/api.php`
- Schema migrations:
  - `database/migrations/2026_06_25_000020_create_ops_graph_tables.php`
  - `database/migrations/2026_06_25_000030_create_healthcare_integration_foundation_tables.php`
  - `database/migrations/2026_06_25_000040_create_patient_flow_navigator_tables.php`

### 2.3 Gaps That Matter Strategically

These gaps are what prevent Zephyrus from credibly beating the market today:

- Command Center metrics do not yet carry source freshness, metric lineage, confidence, or owner accountability in the payload.
- Ops graph exists, but graph lineage, graph timeline, graph constraints, and graph-backed recommendations are still early.
- Recommendation/action/approval tables exist, but there is no complete review, assignment, execution, override, and outcome loop.
- Simulation is not yet implemented as a first-class service.
- Forecasting exists in lightweight form, but not with model versions, backtesting, drift monitoring, and reliability grades.
- Agent runtime, agent traces, agent evals, PHI guardrails, and provider-neutral model adapters are not yet implemented.
- EVS is a major competitor capability, but Zephyrus does not yet have a first-class EVS workflow.
- Staffing is represented in metrics and planning concepts, but not as a real operational domain with constraints and actions.
- 4D patient flow and facility digital twin data are present, but not yet fully fused into the Command Center, ops graph, and action fabric.
- Outcome attribution is not yet rigorous enough to prove ROI against command-center skepticism.

## 3. Competitor Landscape

### 3.1 Epic Grand Central

Public positioning: EHR-native hospital patient flow, capacity command center, discharge planning, predictive analytics, organization-wide visibility, transfer center, bed planning, transport automation, and EVS automation.

Strength to respect:

- Epic owns the clinical source of truth in many hospitals.
- The product is embedded in EHR workflows and Rover mobile documentation.
- Epic can make transport and EVS feel native because source context, orders, encounters, bed state, and staff workflow live nearby.

Zephyrus wedge:

- Treat Epic as an important source system, not the operating boundary.
- Win in multi-EHR, cross-facility, staffing, payer, RTLS, ambient, external transfer, post-acute, and independent simulation/governance scenarios.
- Provide evidence and action lineage that can span Epic plus non-Epic systems.

### 3.2 TeleTracking Operations IQ

Public positioning: Operations IQ provides visibility across owned, affiliated, and unaffiliated healthcare entities, integrated care coordination, automated flow, access, throughput, ambulatory, and analytics. TeleTracking publicly claims broad installed-base numbers and a large command-center footprint. In June 2025, TeleTracking and Palantir announced a partnership to combine Operations IQ with Foundry/AIP for AI-powered operational decision-making.

Strength to respect:

- Deep patient-flow credibility and implementation history.
- Clear access, throughput, and command-center specialization.
- Large installed base and market recognition.
- New Palantir partnership raises the AI/data-platform bar.

Zephyrus wedge:

- Build the same operations breadth, but make source contracts, graph topology, action traces, intervention measurement, and replay visible by design.
- Compete on transparency, modularity, local deployability, and speed of iteration.

### 3.3 Qventus

Public positioning: AI teammates for hospital operations, perioperative care coordination, surgical growth, inpatient capacity, and malnutrition care automation. Qventus emphasizes below-license administrative work, EHR embedding, end-to-end workflow optimization, and measurable outcomes.

Strength to respect:

- Strong AI teammate narrative.
- Commercially clear ROI and workload-reduction story.
- Perioperative and inpatient capacity automation are direct overlaps with Zephyrus.

Zephyrus wedge:

- Match the teammate story but require every teammate to expose tools, inputs, constraints, approvals, run traces, evaluations, and measured outcomes.
- Make teammates operate across the full operational graph, not just solution lines.

### 3.4 LeanTaaS iQueue

Public positioning: predictive and prescriptive AI for inpatient flow, ORs, infusion, and capacity management. The inpatient flow product emphasizes patient surges, bed availability, staffing needs, discharge barriers, LOS prediction, alerts, and operational ROI.

Strength to respect:

- Strong optimization/predictive positioning.
- Strong capacity and perioperative credibility.
- Clear operational improvement and ROI framing.

Zephyrus wedge:

- Move beyond prediction and prioritization into simulation-backed execution.
- Show each recommendation's alternatives, constraints, expected downstream effects, approval state, and actual outcome.

### 3.5 GE HealthCare Command Center

Public positioning: source-system integration, actionable real-time insights, care orchestration, capacity management, staffing, scheduling, AI-enabled tiles, predictive census/staffing modules, and digital-twin simulation. GE now publicly positions Digital Twin simulation as a way to test what-if scenarios.

Strength to respect:

- Mature command-center product and playbook.
- Publicly visible digital twin and AI-enabled tile story.
- Change-management credibility and enterprise scale.

Zephyrus wedge:

- Do not claim "digital twin" alone as differentiation.
- Win by making each tile executable, auditable, source-linked, simulation-backed, and tied to intervention attribution.

### 3.6 Oracle Health Clinical/System Operations

Public positioning: near-real-time clinical operations suite covering location awareness, clinical operations whiteboard, command center dashboard, digital room signage, patient flow, transfer center, workforce resource planning, EVS/transport automation, staffing alignment, and predictive analytics.

Strength to respect:

- Broad suite breadth.
- EHR and cloud footprint.
- Patient flow, transfer, staffing, location awareness, and signage coverage.

Zephyrus wedge:

- Offer lighter modular adoption and EHR-neutral operations.
- Preserve independent event fabric and action governance even when Oracle is the customer EHR.

### 3.7 ABOUT Healthcare

Public positioning: transfer, system capacity, admit prioritization, discharge, provider network, post-acute workflows, and insights across patients moving into, through, and out of a health system.

Strength to respect:

- Strong transfer/access-center mental model.
- Clear patient acquisition/progression/discharge framing.

Zephyrus wedge:

- Make transfer acceptance a constrained optimization problem that uses live capacity, staffing, OR/PACU, transport, payer, and opportunity-cost evidence.

### 3.8 Care Logistics

Public positioning: operational command centers, analytics, real-time and predictive awareness, workflow management, throughput, bed capacity, OR efficiency, LOS, wait time, cost, ED diversion/boarding, bed request turnaround, implementation partnership, and operational model.

Strength to respect:

- Good operational model and implementation story.
- Recognizes that technology alone is not enough.

Zephyrus wedge:

- Productize operational model artifacts: huddle scripts, escalation policies, KPI packs, owner maps, control charts, implementation coach, and outcome attribution.

### 3.9 Palantir for Hospitals

Public positioning: AI-powered operating system for hospitals, AI-driven workflow automations, hospital digital twin imagery, Foundry/AIP/Ontology, and customer examples spanning Hospital at Home, denial automation, patient/staffing allocation, EVS staffing, dynamic scheduling, capacity management, and operating room resource use.

Strength to respect:

- Powerful general-purpose data/AI platform.
- Forward-deployed implementation model.
- Strong executive AI narrative.

Zephyrus wedge:

- Be the healthcare-native, minimum-necessary, operations-specific alternative.
- Avoid generic platform sprawl by shipping a focused hospital operations graph, safety policy, source lineage, and action-control plane out of the box.

### 3.10 Artisight and care.ai

Public positioning: smart hospital, ambient intelligence, computer vision, voice recognition, two-way audio/video, virtual nursing, edge AI, patient rooms, smart ED/OR, sensors, patient monitoring, EHR integration, local inferencing, and command-center concepts.

Strength to respect:

- Ambient systems see reality before the EHR is updated.
- They can improve timestamp accuracy, safety monitoring, room activity detection, virtual nursing, OR activity, and discharge readiness.

Zephyrus wedge:

- Stay sensor-agnostic.
- Add an Ambient Signal Adapter contract and use ambient events as high-fidelity signals in the same graph, simulation, and action engine.

## 4. Market Gap

The market has converged around five categories:

1. EHR-native patient flow: Epic and Oracle.
2. Flow and transfer platforms: TeleTracking, ABOUT, Care Logistics.
3. AI capacity optimizers: Qventus and LeanTaaS.
4. Command centers and data platforms: GE HealthCare and Palantir.
5. Smart-hospital ambient platforms: Artisight and care.ai.

The unclaimed product category is:

> Evidence-grade hospital operations execution: an EHR-neutral operational graph that can explain, simulate, approve, execute, and measure operational action across every source system.

Command centers can fail to improve outcomes when they stop at visualization or when data quality, operational governance, and process accountability are weak. Zephyrus should bake those countermeasures into the product.

## 5. Product Principles Required To Win

### 5.1 Every Metric Must Be Explainable

Every Command Center and Analytics metric must expose:

- Definition.
- Owner.
- Target.
- Numerator and denominator.
- Source tables/events/resources.
- Source freshness.
- Completeness and plausibility checks.
- Last calculation time.
- Drilldown path.
- Known exclusions.
- PHI display class.

### 5.2 Every Recommendation Must Be Testable

Every recommendation must include:

- Triggering signal.
- Evidence facts.
- Constraints checked.
- Alternatives considered.
- Expected impact.
- Confidence.
- Risk level.
- Required approval.
- Owner.
- Expiration time.
- Outcome measurement plan.

### 5.3 Every Action Must Be Governed

Zephyrus should start with read-only and draft-only operations, then graduate to approval-gated execution. No autonomous clinical decisions.

Operational actions can include:

- Create huddle item.
- Assign barrier owner.
- Recommend bed placement.
- Create transport request.
- Request EVS clean.
- Draft secure message.
- Draft FHIR `Task` or `ServiceRequest`.
- Escalate transfer or payer blocker.
- Create staffing request.

### 5.4 Every Improvement Must Be Attributed

Zephyrus must prove impact better than competitors by linking:

`signal -> recommendation -> approval -> action -> completion -> measured outcome -> balancing measures`

Use intervention windows, matched comparisons, interrupted time-series options, and confidence statements. Do not overclaim ROI from before/after charts alone.

### 5.5 Every AI Agent Must Be Auditable

Agents are operational actors, not chat panels. Each agent must have:

- Role.
- Scope.
- Tool permissions.
- Safety policy.
- Required approvals.
- Context pack.
- Output schema.
- Trace.
- Eval suite.
- Cost budget.
- PHI policy.

## 6. Target Architecture

### Layer 1: Acquisition Fabric

Use the existing `integration`, `raw`, and `fhir` foundation. Extend it with real adapters and operational source-health scoring.

Priority connectors:

- FHIR R4 and Bulk Data.
- HL7 v2 ADT/SIU/ORM/ORU/DFT/MDM.
- Epic, Oracle Health, MEDITECH, and interface-engine endpoints.
- Transport vendors.
- EVS/facilities systems.
- Staffing/scheduling.
- RTLS and ambient platforms.
- Payer/prior authorization and post-acute network sources.

### Layer 2: Canonical Event Ledger

Use `integration.canonical_events` plus `CanonicalEventWriter`. Add:

- Projection offsets.
- Projection error queues.
- Replay jobs.
- Source freshness materialization.
- Event-to-metric lineage.
- Event-to-action lineage.

### Layer 3: Operations Graph

Use the existing `ops` schema and `OperationsGraphProjector`. Extend it into the primary operations reasoning model:

- Add graph paths and node timelines to the UI.
- Add source lineage to nodes and edges.
- Add graph-derived constraints.
- Add EVS, staffing, payer, post-acute, external facility, equipment, and ambient-signal nodes.
- Add graph snapshots for scenario baselines.

### Layer 4: Simulation And Optimization

Create a service boundary so Laravel can call a Python or TypeScript optimizer service without contaminating core app code.

Initial simulations:

- No action.
- Pull forward discharges.
- Accelerate EVS.
- Reassign transport.
- Open/flex beds.
- Shift staffing.
- Reroute transfers.
- Protect PACU/OR flow.
- Combined action plan.

Initial optimization:

- Bed placement.
- Transport dispatch.
- EVS prioritization.
- Transfer destination/acceptance.
- Discharge barrier prioritization.
- Staffing gap mitigation.

### Layer 5: Agent Control Plane

Use provider-neutral contracts. Model provider choice is an implementation detail behind:

- Agent definition.
- Tool registry.
- Approval policy.
- Output schema.
- Trace pointer.
- Eval suite.
- PHI policy.

Start with two safe agents:

- Capacity Commander: read/draft only.
- Data Quality Agent: read-only with issue creation.

Then add:

- Discharge Flow Agent.
- Bed Placement Agent.
- Transport/EVS Dispatcher Agent.
- Transfer Center Agent.
- OR Throughput Agent.
- Executive Briefing Agent.

### Layer 6: Action Fabric

Build a first-class action lifecycle:

- Draft.
- Review.
- Approve.
- Assign.
- Execute.
- Complete.
- Override.
- Expire.
- Measure.

Use existing `ops.actions` and `ops.approvals`; add intervention and outcome tables.

### Layer 7: Experience Layer

Keep dense, role-specific operational surfaces:

- Command Center V2.
- Agent Inbox.
- House Supervisor workbench.
- Bed Manager workbench.
- Transfer Center workbench.
- Transport/EVS dispatcher.
- OR board runner.
- Staffing office.
- Executive brief.
- Improvement portfolio.
- 4D patient-flow digital twin.

## 7. Implementation Roadmap

### Phase 0: Competitive Baseline And Product Claims

Duration: 1 week.

- [ ] Create a capability scorecard for Epic, TeleTracking, Qventus, LeanTaaS, GE, Oracle, ABOUT, Care Logistics, Palantir, Artisight, and care.ai.
- [ ] Define claims Zephyrus can prove in 90 days without external PHI feeds.
- [ ] Create a demo script that starts with ED boarding and ends with approved actions plus measured impact.
- [ ] Add a public/internal positioning statement: "evidence-grade operations execution."

Exit criteria:

- Product, engineering, and sales can explain why Zephyrus is not just another command center.

### Phase 1: Metric Lineage And Source Trust

Duration: 2 to 4 weeks.

- [x] Add `ops.metric_definitions`, `ops.metric_values`, `ops.metric_lineage`, `ops.source_freshness`, and `ops.data_quality_findings`.
- [x] Extend `CommandCenterDataService` payload with source freshness and metric lineage.
- [x] Add `/api/analytics/metrics/{metricKey}/lineage`.
- [x] Add Data Quality Agent rules without LLM dependency.
- [x] Surface stale-source and low-confidence states in Command Center and Analytics.
- [x] Add exhaustive tests for every metric definition and empty-source behavior.

Implementation progress:

- 2026-06-26: Added the ops metric trust schema, metric/source lineage catalog, analytics lineage endpoint, Analytics source-trust enrichment, Command Center metric trust payload fields, KPI trust badge, and focused backend/frontend tests.
- 2026-06-26: Added the rules-only Data Quality Agent, durable source-freshness issue creation, Analytics agent panel, metric catalog completeness tests, empty-source trust tests, and no-source ad hoc metric behavior tests.

Exit criteria:

- Every Command Center hero metric can answer "where did this come from and can I trust it?"

### Phase 2: Graph-Backed Action Recommendations

Duration: 4 to 6 weeks.

- [x] Add graph-backed recommendation rules for ED boarding, bed pressure, blocked beds, discharge barriers, transport SLA risk, OR/PACU pressure, and stale feeds.
- [x] Add recommendation evidence rows or payloads with graph paths and source facts.
- [x] Build Agent Inbox shell without LLM dependency.
- [x] Implement approval queue UI using `ops.approvals`.
- [x] Extend `ops.actions` with owner, due time, completion payload, override reason, and expiration.
- [x] Add EVS workflow tables and API endpoints.

Implementation progress:

- 2026-06-26: Added `OperationsRecommendationService`, `/api/ops/recommendations`, persisted draft recommendations/actions/pending approvals, graph evidence payloads, Analytics Opportunities recommendation payloads, and an Opportunities page recommendation panel. Initial rules cover ED boarding, bed pressure, transport SLA risk, open barriers, and stale source feeds.
- 2026-06-26: Added ops action lifecycle extensions, `/api/ops/agent-inbox`, approval decision endpoints, assign/start/complete/override/expire endpoints, duplicate-safe recommendation regeneration for active work, and approval/assignment/execute controls in the Analytics Opportunities panel.
- 2026-06-26: Added EVS workflow tables, EVS request/event models, `/api/evs` workflow endpoints, EVS ops-graph projection, blocked-bed recommendations, and OR/PACU pressure recommendations with graph evidence and approval-gated draft actions.

Exit criteria:

- At least 10 operational recommendations can be reviewed, approved, assigned, completed, and audited.

### Phase 3: Simulation Workbench

Duration: 6 to 8 weeks.

- [x] Add `ops.simulation_runs`, `ops.simulation_scenarios`, and `ops.simulation_results`.
- [x] Create a simulation service boundary.
- [x] Implement first deterministic simulations using current seeded/live tables.
- [x] Add scenario comparison UI under `/analytics/workbench`.
- [x] Add graph snapshot capture before each simulation.
- [x] Add replayable fixtures for simulation tests.

Implementation progress:

- 2026-06-26: Added persisted ops simulation runs/scenarios/results, deterministic capacity scenario modeling, graph snapshot capture before each run, Analytics Workbench scenario comparison, `/api/ops/simulation-scenarios/{scenario}/promote`, and approval-gated promotion of a scenario into a draft action plan.

Exit criteria:

- A user can compare at least five capacity scenarios and promote one scenario into an action plan.

### Phase 4: Intervention Attribution

Duration: 4 to 6 weeks.

- [x] Add `ops.interventions`, `ops.intervention_metrics`, and `ops.outcome_attribution`.
- [x] Link recommendations, actions, and PDSA cycles to intervention records.
- [x] Add before/after windows with matched or stratified comparison options.
- [x] Add balancing measures for safety and unintended consequences.
- [x] Add executive impact cards with confidence language.

Implementation progress:

- 2026-06-26: Added persisted intervention attribution tables, action/PDSA linkage, deterministic before/after metric capture, balancing measures, confidence language, comparison options, and Analytics Workbench impact cards backed by focused attribution tests.

Exit criteria:

- Zephyrus can show "action taken" and "effect measured" for RTDC, transport, and at least one perioperative flow use case.

### Phase 5: Agent Control Plane

Duration: 6 to 8 weeks.

- [x] Add `ops.agent_definitions`, `ops.agent_runs`, `ops.agent_tool_calls`, `ops.agent_approvals`, `ops.agent_evaluations`, and `ops.agent_safety_events`.
- [x] Implement a Zephyrus tool registry that wraps Laravel APIs with role-aware authorization.
- [x] Implement provider-neutral agent runner contracts.
- [x] Implement read-only Capacity Commander.
- [x] Implement read-only Data Quality Agent.
- [x] Add golden-case evals and prompt-injection tests.
- [x] Add PHI minimization and stale-data guardrails.

Implementation progress:

- 2026-06-26: Added the ops agent control-plane schema, read-only tool registry, provider-neutral rules-only runner contract, Capacity Commander run endpoint, Data Quality Agent run endpoint, tool-call traces, safety events, golden-case evaluations, prompt-injection blocking, PHI key minimization, and stale census guardrails.

Exit criteria:

- Two agents can run in production-like read/draft mode with visible traces, eval results, and approval gates.

### Phase 6: 4D Flow And Ambient Readiness

Duration: 6 to 10 weeks.

- [x] Integrate Patient Flow 4D into authenticated React/Inertia pages.
- [x] Overlay ops graph nodes on facility spaces.
- [x] Add live ADT/SSE stream controls and replay.
- [x] Define Ambient Signal Adapter contract.
- [x] Add adapter fixtures for RTLS, room sensor, nurse call, and OR milestone events.
- [x] Add source confidence scoring for ambient-derived events.

Implementation progress:

- 2026-06-26: Extended the authenticated Patient Flow 4D navigator with ambient readiness, added `flow_realtime` ambient adapter/event tables, fixture adapters for RTLS, room sensor, nurse call, and OR milestones, confidence scoring, `/api/patient-flow/ambient`, and navigator UI indicators for ambient signal count and trust.

Exit criteria:

- Zephyrus can replay patient movement, connect it to current capacity signals, and consume at least one ambient-style event source in test mode.

### Phase 7: Enterprise Connectors And Writeback

Duration: customer and vendor dependent.

- [x] Add production-grade HL7 v2 ingestion behind an interface-engine boundary.
- [x] Add FHIR client capability discovery and polling/backfill.
- [x] Add SMART/backend-services credential lifecycle.
- [x] Add Epic/Oracle/MEDITECH connector playbooks.
- [x] Add TeleTracking/Qventus/LeanTaaS coexistence adapters where customers already own those systems.
- [x] Add approval-gated writeback drafts for FHIR `Task`, `ServiceRequest`, transport, EVS, and secure messaging.

Implementation progress:

- 2026-06-26: Added enterprise connector control tables, interface-engine boundary metadata, FHIR capability discovery/backfill metadata, SMART backend credential lifecycle records, Epic/Oracle/MEDITECH playbooks, TeleTracking/Qventus/LeanTaaS coexistence adapters, and approval-gated writeback drafts for external-system resources.

Exit criteria:

- Zephyrus can coexist with incumbent systems while still providing independent graph intelligence and governed action.

### Phase 8: Regional Transfer And Multi-Hospital Operations

Duration: 8 to 12 weeks.

- [x] Add regional facility and capability tables for multi-hospital transfer operations.
- [x] Seed an initial regional network catalog for demo and test mode.
- [x] Score transfer destination options using capacity, ICU availability, clinical capabilities, transport time, and opportunity cost.
- [x] Add regional transfer decision audit records linked to canonical transport requests.
- [x] Surface regional transfer recommendations on the existing Transfers worklist.
- [x] Add organization/campus/building scoping to facility imports, spaces, and operational maps.
- [x] Support multiple current approved facility model versions.
- [x] Add regional comparison dashboards across campuses and external facilities.
- [x] Add health-system-wide capacity and route simulation.
- [x] Add a transfer-center agent that can draft acceptance, redirect, or defer recommendations.

Implementation progress:

- 2026-06-26: Added `regional` transfer operations tables, seeded regional facility/capability catalog, deterministic candidate scoring, `/api/transport/regional-summary`, `/api/transport/requests/{transportRequestId}/regional-decision`, decision audit events, and the Transfers page regional optimization panel.
- 2026-06-26: Completed the remaining Phase 8 slices with organization/campus/building/service-area scoped facilities, an external partner facility, approved regional model versions, regional comparison dashboard payloads, persisted route simulation runs, `/api/transport/regional-simulation`, `/api/transport/requests/{transportRequestId}/regional-agent-draft`, and draft-only transfer-center agent recommendations with audit events.

Exit criteria:

- Zephyrus can recommend, simulate, compare, draft, and audit regional transfer destination/acceptance decisions with capacity, clinical capability, interfacility transport, model-version, and opportunity-cost evidence.

## 8. First 10 Engineering Tickets

1. Add metric lineage schema and service.
2. Add source freshness materialization from `integration.connector_watermarks`, `raw.ingest_runs`, and source registry state.
3. Extend Command Center payload types and UI to show metric trust and stale-source states.
4. Add graph node timeline endpoint to complete the already planned `/api/ops/graph/node/{id}/timeline` behavior.
5. Build rules-based recommendation generator with graph evidence.
6. Build Agent Inbox shell backed by `ops.recommendations`, `ops.actions`, and `ops.approvals`.
7. Add EVS domain model, routes, and transport/bed-flow integration.
8. Add simulation schema and a deterministic simulation service with fixture tests.
9. Add intervention attribution schema linked to actions and PDSA cycles.
10. Integrate the Patient Flow 4D navigator into the authenticated app and connect it to ops graph snapshots.

## 9. 90-Day Demo Target

Scenario:

- ED is boarding patients.
- PACU holds are delaying OR flow.
- EVS turnaround is behind.
- Transport queue is overloaded.
- Several discharges are likely but blocked.
- Staffing is tight on two units.
- One source feed is stale.

Zephyrus must:

1. Show the live operational graph and 4D facility context.
2. Flag source freshness and confidence.
3. Explain root causes across ED, inpatient, OR, EVS, transport, staffing, and discharge barriers.
4. Compare no-action, EVS acceleration, discharge acceleration, transport reassignment, flex-bed, staffing, and combined scenarios.
5. Recommend a combined plan with alternatives and constraints.
6. Request human approval.
7. Create huddle items, tasks, and owner assignments.
8. Track completion.
9. Measure outcome and balancing metrics.
10. Generate an executive brief with source lineage and confidence.

Acceptance:

- The demo proves Zephyrus is an execution system, not a passive dashboard.
- The demo proves AI is governed, observable, and accountable.
- The demo proves impact measurement is part of the workflow, not a retrospective consulting exercise.

## 10. Twelve-Month Winning Position

By 12 months, Zephyrus should credibly claim:

- EHR-neutral hospital operations graph.
- Real-time acquisition fabric with replay and provenance.
- Command Center V2 with source-level metric lineage.
- Rules and agent recommendations with evidence and approvals.
- Simulation-backed action planning.
- 4D patient-flow digital twin integrated into operations.
- Transport, EVS, bed placement, discharge, transfer, perioperative, staffing, and data-quality workflows.
- Ambient-signal adapter layer.
- Intervention attribution and ROI proof.
- Implementation kit for huddles, escalation, governance, and improvement sustainment.

## 11. Risks And Countermeasures

Risk: Competitors already claim AI and digital twin.

- Countermeasure: differentiate on open lineage, governed action, replay, and attribution.

Risk: Hospitals distrust autonomous AI.

- Countermeasure: start read-only, then draft-only, then approval-gated; expose traces and safety events.

Risk: Integrations slow the roadmap.

- Countermeasure: build against current Zephyrus tables first, then replace synthetic/local feeds with adapters progressively.

Risk: Command center technology alone does not improve outcomes.

- Countermeasure: ship workflows, owners, escalation policies, huddle scripts, PDSA links, and measurement from day one.

Risk: Epic/Oracle customers ask why they need Zephyrus.

- Countermeasure: position Zephyrus as the cross-system evidence and execution layer that works above the EHR and around non-EHR constraints.

Risk: Palantir competes as a broad AI operating system.

- Countermeasure: be healthcare-specific, modular, transparent, minimum-necessary, and faster to deploy for hospital operations use cases.

## 12. Sources Reviewed

Repo evidence:

- `routes/web.php`
- `routes/api.php`
- `resources/js/config/navigationConfig.ts`
- `app/Services/CommandCenterDataService.php`
- `app/Services/Ops/OperationsGraphProjector.php`
- `app/Integrations/Healthcare/Contracts/HealthcareConnector.php`
- `app/Integrations/Healthcare/Services/CanonicalEventWriter.php`
- `app/Services/PatientFlow/FlowEventNormalizer.php`
- `app/Services/Transport/TransportOperationsService.php`
- `app/Rtdc/Optimizer/HeuristicBedAssignmentOptimizer.php`
- `database/migrations/2026_06_25_000020_create_ops_graph_tables.php`
- `database/migrations/2026_06_25_000030_create_healthcare_integration_foundation_tables.php`
- `database/migrations/2026_06_25_000040_create_patient_flow_navigator_tables.php`

Market and research sources:

- Epic Hospital Patient Flow: https://www.epic.com/software/hospital-patient-flow/
- TeleTracking Operations IQ: https://www.teletracking.com/healthcare-operations-iq-platform/
- TeleTracking and Palantir partnership, 2025-06-05: https://www.teletracking.com/news/teletracking-and-palantir-partner-to-transform-healthcare-operations-with-ai-powered-insights/
- Qventus: https://www.qventus.com/
- LeanTaaS iQueue for Inpatient Flow: https://leantaas.com/products/inpatient-flow/
- GE HealthCare Command Center: https://www.gehealthcare.com/en-us/products/software/command-center
- Oracle Health System Operations: https://www.oracle.com/health/clinical-operations/systems-operations/
- ABOUT Healthcare solutions: https://abouthealthcare.com/solutions/
- Care Logistics hospital command centers: https://www.carelogistics.com/hospital-command-centers
- Palantir for Hospitals: https://www.palantir.com/offerings/palantir-for-hospitals/
- Artisight: https://artisight.com/
- care.ai: https://www.care.ai/
- KLAS Patient Flow category: https://klasresearch.com/compare/patient-flow/84
- Centralized hospital management review: https://pmc.ncbi.nlm.nih.gov/articles/PMC10637563/
- Hospital command centre patient-flow impact study: https://pmc.ncbi.nlm.nih.gov/articles/PMC10566538/

Local research artifacts:

- `.firecrawl/2026-06-26-competitors-patient-flow.json`
- `.firecrawl/2026-06-26-epic-grand-central.json`
- `.firecrawl/2026-06-26-qventus-ai-teammates.json`
- `.firecrawl/2026-06-26-leantaas-iqueue.json`
- `.firecrawl/2026-06-26-teletracking.json`
- `.firecrawl/2026-06-26-ge-command-center.json`
- `.firecrawl/2026-06-26-oracle-health-ops.json`
- `.firecrawl/2026-06-26-about-healthcare.json`
- `.firecrawl/2026-06-26-care-logistics.json`
- `.firecrawl/2026-06-26-palantir-hospitals.json`
- `.firecrawl/2026-06-26-artisight.json`
- `.firecrawl/2026-06-26-care-ai.json`
