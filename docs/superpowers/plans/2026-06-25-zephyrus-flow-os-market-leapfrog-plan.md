# Zephyrus Flow OS Market Leapfrog Plan

Date: 2026-06-25
Status: Proposed product and implementation strategy
Related plan: `docs/superpowers/plans/2026-06-25-real-time-healthcare-data-acquisition.md`

## 1. Executive Thesis

Epic Grand Central is the benchmark to beat, but not by trying to become another EHR module. Epic wins because it is embedded in the source of truth. TeleTracking, ABOUT, Oracle, GE HealthCare, LeanTaaS, Qventus, Care Logistics, Palantir, Artisight, and care.ai each win a different slice of hospital operations: patient flow, transfer center, command wall, AI throughput, smart rooms, or enterprise data fusion.

Zephyrus can leapfrog them by becoming the vendor-neutral hospital operations operating system:

1. It ingests all operational truth in real time.
2. It turns hospital state into a canonical operational graph and digital twin.
3. It predicts bottlenecks before they become delays.
4. It simulates the effect of actions before recommending them.
5. It converts recommendations into governed tasks, handoffs, escalations, and writebacks.
6. It gives every operations role an audited AI teammate, not just a dashboard.
7. It proves impact with source-level lineage, intervention attribution, and ROI.

The core product position:

> Zephyrus Flow OS is the AI-native, EHR-neutral command and control layer for hospital operations. It sits above Epic, Oracle Health, MEDITECH, TeleTracking, Qventus, LeanTaaS, transport vendors, staffing systems, RTLS, EVS, payer systems, and HIEs; creates a live digital twin of the hospital; and safely orchestrates the next best operational action.

The wedge:

- For Epic hospitals: Zephyrus is the cross-system orchestration and AI execution layer that sees beyond Epic Grand Central into staffing, transport, payer, HIE, RTLS, EVS, periop, external transfers, and multi-EHR network operations.
- For non-Epic hospitals: Zephyrus is the fast path to Grand-Central-class capability without locking into a monolithic EHR.
- For multi-hospital systems: Zephyrus is the regional flow layer that load-balances patients, teams, beds, cases, transport, and discharges across facilities.
- For executives: Zephyrus is the measurable capacity-growth system that creates "virtual beds" without construction.

## 2. Competitive Baseline

### 2.1 Epic Grand Central and Hospital Patient Flow

Officially visible capabilities:

- Capacity command center with real-time system capacity, goals, staff productivity, and open-bed opportunities.
- Discharge planning and length-of-stay tools that start when the patient is admitted.
- Predictive analytics for predicted length of stay, expected ED admissions, projected census, and likely post-surgical destination.
- Hospital-wide visibility without a separate system for Epic users.
- Transfer center workflows for interfacility and external-provider requests.
- Bed planning for outstanding requests and bottlenecks.
- Transport automation, including assignment by proximity and priority, Rover mobile documentation, and real-time patient-location updates.
- Environmental services automation based on isolation information, staff skill, and priority, with Rover documentation.

Why Epic is strong:

- Native EHR context and workflow embedding.
- Patient, encounter, order, bed, transport, EVS, and staff context can be tightly aligned inside one ecosystem.
- Existing customer trust, governance, install base, and implementation machinery.
- Embedded mobile workflow through Rover.
- UserWeb, roadmap, training, and operational playbooks for Epic customers.

Likely limitations and wedge opportunities:

- Epic-native value is strongest when the whole operational surface lives in Epic.
- Multi-EHR, regional transfer, payer, transport vendor, staffing, RTLS, HIE, post-acute, and ambient-sensor data can remain fragmented.
- Predictive analytics exist, but the public posture is still module/workflow oriented, not a transparent digital twin plus governed multi-agent execution fabric.
- Roadmap and deep docs live behind Epic customer access, limiting open extensibility.
- KLAS comments show customer sentiment is mixed enough that "integrated" is not the same as "fully sufficient."

Zephyrus leapfrog response:

- Treat Epic as one of many transactional systems, not the universe.
- Ingest Epic Grand Central state where available, then enrich it with non-Epic constraints and opportunity costs.
- Build a cross-facility operational graph that can answer questions Epic modules do not own: "What is the systemwide best bed, transfer decision, transport mode, staffing action, payer action, and discharge barrier action right now?"
- Use AI agents that propose actions with lineage, confidence, safety constraints, and human approval.

### 2.2 TeleTracking Operations IQ and Capacity IQ

Publicly visible posture:

- Operations platform for hospitals and health systems.
- AI-powered insights, workflow automation, and enterprise-wide visibility.
- Capacity IQ is positioned around bed capacity, patient access, discharge, ED boarding, and bed turnover.
- TeleTracking was named 2024 Best in KLAS for Patient Flow.

Why TeleTracking is strong:

- Deep patient-flow specialization.
- Strong operational credibility outside the EHR.
- Historic focus on command centers, access centers, transfer centers, bed management, and throughput.
- Strong market recognition in patient flow.

Likely limitations and wedge opportunities:

- Purpose-built operations platform, but not a developer-first open operational graph and agent fabric.
- AI positioning is visible, but public materials emphasize insights and workflow automation more than auditable multi-agent orchestration.
- Zephyrus can beat by making every recommendation explainable, simulated, and attributable to outcome.

Zephyrus leapfrog response:

- Build TeleTracking-class flow operations but make the data model open, replayable, and multi-source.
- Add a digital twin and simulation layer as a first-class product, not an analytics add-on.
- Make agentic execution and measurable intervention attribution the differentiator.

### 2.3 Qventus

Publicly visible posture:

- "AI teammates" for automating hospital operations.
- Solutions across perioperative care coordination, surgical growth, inpatient capacity, and malnutrition care automation.
- Claims include reducing surgery cancellations by up to 40%, adding strategic cases per OR per month, reducing excess days by 15-30%, and increasing staff productivity.
- Emphasizes below-license administrative work and EHR-embedded operational assistants.

Why Qventus is strong:

- Clear AI teammate narrative.
- Strong automation posture in perioperative and inpatient capacity.
- Commercially compelling ROI language.
- Focus on administrative task relief and care-team adoption.

Likely limitations and wedge opportunities:

- AI appears organized around solution lines rather than an open, hospitalwide, source-linked operations twin.
- Zephyrus can differentiate by making agents operate across all domains: bed flow, ED, OR, transport, staffing, EVS, payer, HIE, ambient sensors, and disaster response.
- Zephyrus can expose agent traces, guardrails, approvals, simulations, and source lineage as product features.

Zephyrus leapfrog response:

- Match the AI teammate language, then go beyond it: every teammate should have tools, permissions, a run trace, an approval policy, a domain-specific safety envelope, and measurable impact.
- Offer provider-choice: OpenAI Agents SDK, Claude Agent SDK, or a provider-neutral runtime behind the same Zephyrus tool contracts.

### 2.4 LeanTaaS iQueue

Publicly visible posture:

- AI/ML-powered healthcare capacity suite.
- iQueue for Inpatient Flow uses predictive analytics and AI to dynamically manage capacity and align workforce to demand.
- Public claims include 100+ hospitals, 30+ health systems, 28k inpatient beds, and ROI per bed.
- Focuses on daily bed meetings, hourly administrative management, discharge planning, and capacity protocol standardization.

Why LeanTaaS is strong:

- Strong optimization and predictive positioning.
- Strong operating-room, infusion, and inpatient-flow credibility.
- Clear health-system operations ROI story.

Likely limitations and wedge opportunities:

- Strong capacity optimization, but less publicly framed as a universal real-time command execution and agent system.
- Zephyrus can beat by connecting predictions to governed operational actions and cross-domain consequences.

Zephyrus leapfrog response:

- Build prediction and optimization, but require every recommendation to include simulation output, alternative actions, constraints, confidence, and expected downstream effects.
- Turn bed meetings into live, agent-assisted execution sessions rather than static daily reviews.

### 2.5 GE HealthCare Command Center

Publicly visible posture:

- Command Center Software supports orchestration of patient care and balancing competing demands across hospital service points.
- GE materials say the software was first deployed at Johns Hopkins in 2015 and has evolved into a platform used by about 250 hospitals in four countries for applications including daily care orchestration, regional capacity management, deterioration/sepsis quality, predictive staffing, and outpatient schedule optimization.

Why GE is strong:

- Mature command-center implementation pattern.
- Credible enterprise visualization and operations packaging.
- Strong narrative around physical command centers and "tiles."

Likely limitations and wedge opportunities:

- Command-center software can remain dashboard and tile heavy.
- Zephyrus can beat by being action-native and agent-native: the command center does not just see the problem; it drafts the plan, checks constraints, assigns work, and measures impact.

Zephyrus leapfrog response:

- Build tile-level visibility, but make each tile executable: root cause, next-best action, responsible owner, simulation, approval, task creation, and outcome feedback.

### 2.6 Oracle Health Clinical Operations and Systems Operations

Publicly visible posture:

- Near real-time suite for situational awareness, throughput management, resource tracking, and monitoring.
- Patient Flow, Transfer Center, Command Center Dashboard, Clinical Operations Whiteboard, Digital Room Signage, Workload Management, and Patient Observer.
- Features include bed management, patient movement, transfer workflows, EVS/transport automation, staffing alignment, disaster/mass-transfer workflows, and predictive analytics.

Why Oracle is strong:

- Broad suite across EHR, command center, patient flow, transfer, signage, workload, and emergency response.
- Strong non-Epic EHR footprint.
- Ability to combine Oracle Health with Oracle Cloud, HCM, and ERP.

Likely limitations and wedge opportunities:

- Suite breadth can create implementation complexity and Oracle-stack dependency.
- Zephyrus can beat through lighter modularity, EHR neutrality, open APIs, transparent AI, and faster iteration.

Zephyrus leapfrog response:

- Integrate with Oracle Health where present, but preserve independent event fabric and agent logic.
- Build "small first, expand fast" modules that can prove value without a massive suite rollout.

### 2.7 ABOUT Healthcare

Publicly visible posture:

- Care orchestration across patients moving into, through, and out of health systems.
- Transfer, inpatient stay logistics, discharge to post-acute care, and operational insights.
- Emphasis on access centers, transfer centers, patient acquisition, progression, and discharge.

Why ABOUT is strong:

- Transfer-center specialization.
- Strong "into, through, out" mental model.
- Cross-organization patient movement focus.

Likely limitations and wedge opportunities:

- Strong transfer/care orchestration, but less visible AI-native digital twin and multi-agent execution.
- Zephyrus can beat by unifying transfer decisions with live capacity, staffing, OR, payer, transport, and post-acute constraints.

Zephyrus leapfrog response:

- Make transfer acceptance and destination recommendation a constrained optimization problem with traceable rationale and real-time opportunity cost.

### 2.8 Care Logistics

Publicly visible posture:

- Operational command centers to improve coordination, analytic intelligence, visibility, and aggregation of information from multiple sources.
- Longstanding healthcare command-center positioning.

Why Care Logistics is strong:

- Operational transformation and command-center methodology.
- Workflow and governance emphasis, not just software.

Likely limitations and wedge opportunities:

- Zephyrus can productize implementation science and operations governance into reusable playbooks, agents, and workflow templates.

Zephyrus leapfrog response:

- Ship a "command center in a box" implementation kit with role charters, huddle scripts, escalation policies, KPI definitions, go-live scorecards, and AI-assisted improvement cycles.

### 2.9 Palantir for Hospitals

Publicly visible posture:

- Build custom workflows that harmonize patient-flow data into a connected capacity-management source of truth.
- Strong data-platform and workflow-building story.
- Visible adoption in hospitals, including Cleveland Clinic and Tampa General examples in public reporting.

Why Palantir is strong:

- Enterprise data fusion.
- Custom workflows.
- Powerful ontology and application-building capabilities.
- Strong AI and operational command narrative.

Risks and wedge opportunities:

- Data-sovereignty, transparency, procurement, and public-trust concerns are material, especially in public health systems.
- Broad platform power can feel heavy, expensive, and opaque.
- Zephyrus can beat with healthcare-specific trust: open interoperability, auditable lineage, minimal PHI movement, local deployment options, explainable actions, and governance designed for clinical operations.

Zephyrus leapfrog response:

- Become the trusted, healthcare-native alternative to generic data-platform command centers.
- Make every agent action inspectable and every data use bounded by source, role, purpose, and retention.

### 2.10 Artisight and care.ai

Publicly visible posture:

- Smart hospital, ambient intelligence, virtual nursing, patient monitoring, safety, workflow relief, and care coordination.
- Sensor, camera, microphone, room device, and EHR integration patterns.

Why they are strong:

- They see physical reality, not just EHR state.
- They can detect in-room and perioperative events that staff may document late.
- They address nursing shortages and virtual-care workflows.

Likely limitations and wedge opportunities:

- Hardware and ambient networks are powerful but costly and implementation heavy.
- Zephyrus can start as sensor-agnostic and ingest ambient signals from these platforms rather than building hardware first.

Zephyrus leapfrog response:

- Define an Ambient Signal Adapter contract.
- Treat smart-room events, RTLS, nurse call, device, and computer-vision milestones as optional high-fidelity inputs into the same operational graph.

## 3. Market Gap Map

The leading products cluster around five patterns:

1. EHR-native patient flow: Epic Grand Central and Oracle Health.
2. Flow and transfer operations: TeleTracking, ABOUT, Care Logistics.
3. AI throughput optimization: Qventus and LeanTaaS.
4. Enterprise command centers and data platforms: GE HealthCare and Palantir.
5. Smart hospital and ambient care: Artisight and care.ai.

The unclaimed category is:

> A vendor-neutral, AI-native hospital operations operating system with a live digital twin, source-level lineage, simulation, governed multi-agent execution, and measurable intervention attribution.

This is where Zephyrus should go.

## 4. Leapfrog Product Principles

### 4.1 From Visibility to Execution

Do not stop at dashboards. Every signal should answer:

- What is happening?
- Why is it happening?
- What will happen next?
- What actions are possible?
- Which action is safest and highest value?
- Who must approve it?
- Who owns execution?
- Did the action work?

### 4.2 From Modules to an Operational Graph

Instead of building disconnected pages for ED, RTDC, periop, transport, and improvement, build one graph:

- Patients
- Encounters
- Beds
- Units
- Rooms
- Staff
- Services
- Orders
- Procedures
- Tasks
- Barriers
- Transport requests
- EVS work
- Equipment
- Payers
- Documents
- External facilities
- Forecasts
- Constraints
- Policies
- Actions
- Outcomes

Each page becomes a projection of the graph.

### 4.3 From Prediction to Simulation

Predictions say what may happen. Simulation says what to do about it.

For every major recommendation, Zephyrus should simulate at least:

- Do nothing.
- Accelerate discharge barriers.
- Pull forward EVS.
- Reassign transport.
- Open flex beds.
- Shift staffing.
- Redirect transfers.
- Resequence OR/PACU flow.
- Board in alternate unit.
- Escalate payer authorization.

### 4.4 From AI Chat to Audited Agents

Agents should not be generic chatbots. They should be operational actors with:

- Role
- Scope
- Tools
- Permissions
- Required approvals
- Safety constraints
- Source context
- Output schema
- Trace
- Cost accounting
- Evaluation score
- Post-action outcome measurement

### 4.5 From Vendor Lock-In to Connector Leverage

Zephyrus should integrate with incumbents rather than only compete head-on:

- Epic Grand Central can be a source.
- Oracle Patient Flow can be a source.
- TeleTracking can be a source.
- Qventus/LeanTaaS can be a source or coexist as specialty optimizers.
- Artisight/care.ai can be ambient-signal sources.
- Palantir can be a source or downstream data platform if a customer already uses it.

The strategic stance:

- Take data in.
- Add cross-system intelligence.
- Orchestrate actions.
- Write back only through governed, approved channels.

## 5. Zephyrus Flow OS Product Architecture

### 5.1 Core Product Layers

Layer 1: Acquisition Fabric

- FHIR, HL7 v2, DICOMweb, X12, NCPDP, Direct/C-CDA, TEFCA/HIE, vendor REST, webhooks, SFTP, RTLS, EVS, staffing, transport, ambient.
- Implemented by the real-time acquisition plan.

Layer 2: Canonical Event Ledger

- Immutable source event history.
- Idempotency keys.
- Source lineage.
- Replay.
- Dead-letter repair.
- Projection offsets.

Layer 3: Operations Graph

- A current-state graph of operational resources and dependencies.
- Built from canonical events and resource mirrors.
- Enables traversal, constraints, ranking, and simulation.

Layer 4: Digital Twin and Forecasting

- Current state plus future state.
- 4-hour, 12-hour, 24-hour, 48-hour, and 7-day horizons.
- Forecasts census, ED arrivals, admissions, discharges, OR/PACU demand, transport load, EVS work, staffing gaps, and payer/post-acute barriers.

Layer 5: Optimization and Simulation

- Bed assignment.
- Transfer acceptance.
- Discharge acceleration.
- EVS/transport dispatch.
- Staffing adjustments.
- OR sequencing and PACU protection.
- Surge/disaster response.

Layer 6: Agentic Operations Control Plane

- Domain agents.
- Tool registry.
- Approval queues.
- Traces.
- Evals.
- Cost controls.
- Safety policies.

Layer 7: Action Fabric

- Converts recommendations into tasks, escalations, messages, huddle plan items, FHIR `Task`/`ServiceRequest`, transport requests, EVS requests, and writebacks.

Layer 8: Command Center Experience

- Executive command wall.
- Role-specific workbenches.
- Huddles.
- Transfer center.
- ED flow.
- OR flow.
- Discharge control.
- Staffing control.
- Quality/safety control.
- Agent inbox.

Layer 9: Outcome Attribution

- Measures accepted, rejected, overridden, delayed, and completed recommendations.
- Links interventions to ED boarding, LOS/GMLOS, opportunity days, transfers, OR starts, cancellations, EVS turnaround, transport SLA, and staffing variance.

### 5.2 Product Name and Messaging

Use "Zephyrus Flow OS" internally and in product strategy. Public naming can evolve.

Message pillars:

- See everything.
- Predict pressure.
- Simulate choices.
- Act safely.
- Prove impact.

One-line pitch:

> Zephyrus Flow OS gives hospitals a live digital twin and AI operations teammates that turn fragmented EHR, bed, staffing, transport, and payer signals into safe, measurable action.

## 6. Data Model Plan

Build on the acquisition plan with a new `ops` or `flow` schema. Recommended name: `ops`.

### 6.1 Core Tables

- `ops.nodes`
  - `node_id`, `node_type`, `canonical_key`, `display_name`, `source_priority`, `current_state`, `created_at`, `updated_at`, `is_active`
- `ops.edges`
  - `edge_id`, `from_node_id`, `to_node_id`, `edge_type`, `weight`, `valid_from`, `valid_to`, `metadata`
- `ops.state_snapshots`
  - `snapshot_id`, `scope_type`, `scope_id`, `captured_at`, `state_hash`, `state_payload`
- `ops.constraints`
  - `constraint_id`, `constraint_type`, `scope_type`, `scope_id`, `hard_or_soft`, `expression`, `severity`, `active_status`
- `ops.actions`
  - `action_id`, `action_type`, `subject_node_id`, `target_node_id`, `status`, `recommended_by`, `approved_by`, `executed_by`, `created_at`, `completed_at`, `payload`
- `ops.action_options`
  - alternatives considered by the optimizer or agent.
- `ops.recommendations`
  - `recommendation_id`, `scope_type`, `scope_id`, `agent_run_id`, `title`, `rationale`, `confidence`, `expected_impact`, `risk_level`, `status`
- `ops.recommendation_evidence`
  - source facts, graph paths, forecasts, and constraints used.
- `ops.simulation_runs`
  - `simulation_run_id`, `scenario_name`, `baseline_snapshot_id`, `horizon`, `started_at`, `completed_at`, `status`
- `ops.simulation_results`
  - predicted metrics by scenario.
- `ops.interventions`
  - accepted actions with pre/post measurement windows.
- `ops.outcome_attribution`
  - linked KPI movement, counterfactual, and confidence.

### 6.2 Agent Tables

- `ops.agent_definitions`
  - name, role, model provider, model, instructions version, active tools, approval policy.
- `ops.agent_runs`
  - run ID, agent, trigger, user, scope, status, token/cost, started/completed, trace pointer.
- `ops.agent_tool_calls`
  - tool name, input hash, output hash, PHI class, status, latency.
- `ops.agent_approvals`
  - pending approval, approver, decision, reason, expiration.
- `ops.agent_evaluations`
  - scenario, expected answer/action, actual output, grader result.
- `ops.agent_safety_events`
  - blocked actions, unsupported claims, PHI guardrail hits, policy failures.

### 6.3 Operational Metric Tables

- `ops.metric_definitions`
- `ops.metric_values`
- `ops.metric_lineage`
- `ops.metric_targets`
- `ops.metric_alerts`

These make every Command Center tile source-backed and explainable.

## 7. Agentic Product Design

### 7.1 Agent Roster

Capacity Commander Agent

- Watches house-wide capacity, ED boarding, pending admits, ICU pressure, staffed beds, and blocked beds.
- Proposes systemwide actions.
- Runs surge simulations.
- Drafts huddle plans.

Discharge Flow Agent

- Finds likely discharge candidates and active barriers.
- Prioritizes barriers by bed need and avoidable-day impact.
- Drafts task bundles for case management, pharmacy, transport, post-acute, payer, and attending review.

Bed Placement Agent

- Ranks bed assignments.
- Checks hard constraints: service, isolation, level of care, sex/gender placement policy, bed status, staffing, special equipment, clinical exclusions.
- Explains tradeoffs and opportunity cost.

Transfer Center Agent

- Scores incoming transfer requests.
- Recommends accepting facility, level of care, required service, expected bed timing, transport mode, and escalation path.
- Simulates impact on local ED, ICU, OR, and med-surg pressure.

ED Flow Agent

- Detects waiting-room pressure, triage bottlenecks, provider delay, boarding, LWBS risk, and downstream bed blockers.
- Recommends operational moves: provider-in-triage, fast track, direct bedding, inpatient pull, transport escalation, or diversion review.

OR Throughput Agent

- Watches first-case starts, turnover, PACU holds, block utilization, add-ons, cancellations, and staffing.
- Recommends room rebalancing, add-on timing, PACU protection, and case readiness escalations.

EVS and Transport Dispatcher Agent

- Coordinates bed cleaning, transport requests, patient readiness, priority, proximity, and SLA risk.
- Recommends dispatch changes but writes only to Zephyrus transport/EVS tasks until source writeback is approved.

Staffing Balancer Agent

- Compares demand, acuity, census, staffing supply, call schedules, and float pool.
- Recommends staffing moves and escalation, with labor rules and fairness constraints.

Payer and Authorization Agent

- Detects prior-auth, eligibility, and documentation blockers.
- Drafts follow-up tasks and supporting packet checklists.
- Never submits payer transactions autonomously without approval.

Post-Acute and Care Transition Agent

- Tracks referral status, acceptance, packet readiness, transport, and discharge barriers.
- Recommends facility outreach and escalation order.

Data Quality Agent

- Watches stale feeds, mapping failures, conflicting sources, identity merges, terminology misses, and impossible states.
- Opens integration tasks and blocks suspect recommendations.

Executive Briefing Agent

- Produces daily, shift, incident, and board-level operational briefings with metrics, root causes, decisions, and outcomes.

Implementation Coach Agent

- Guides customers through command-center setup, role design, huddles, escalation policies, and KPI adoption.

### 7.2 Tool Categories

Read tools:

- `getCurrentCensus`
- `getBedState`
- `getEdFlowState`
- `getOrState`
- `getTransportState`
- `getStaffingState`
- `getBarrierState`
- `getTransferRequests`
- `getMetricLineage`
- `getSourceFreshness`
- `getPatientTimeline`

Analysis tools:

- `rankBedAssignments`
- `forecastCensus`
- `forecastEdArrivals`
- `forecastDischarges`
- `detectBottlenecks`
- `findRootCausePaths`
- `calculateOpportunityCost`
- `runFlowSimulation`
- `compareScenarios`

Action tools:

- `draftHuddlePlan`
- `createOperationalTask`
- `escalateBarrier`
- `recommendBedAssignment`
- `createTransportRequest`
- `requestEvsClean`
- `sendSecureMessageDraft`
- `createPayerFollowupTask`
- `writeFhirTaskDraft`

Approval tools:

- `requestHumanApproval`
- `approveAction`
- `rejectAction`
- `recordOverrideReason`
- `commitApprovedAction`

Governance tools:

- `checkPolicy`
- `checkPhiScope`
- `checkActionSafety`
- `logAgentDecision`
- `openDataQualityIssue`

### 7.3 Agent Safety Rules

Hard rules:

- No agent makes diagnoses.
- No agent discharges a patient.
- No agent changes level of care without human approval.
- No agent writes directly to an EHR without a configured, auditable writeback path.
- No agent sends external messages containing PHI unless the message channel and recipient are approved.
- No agent uses raw source payloads when a minimized projection is sufficient.
- No agent hides stale data; stale data must be visible in the answer and UI.
- No agent can override a hard clinical, staffing, isolation, or policy constraint.

Soft rules:

- Prefer operational tasks over clinical recommendations.
- Prefer drafts and approvals for high-impact actions.
- Prefer source facts and graph evidence over narrative.
- Prefer bounded language when forecasts are uncertain.

## 8. OpenAI SDK Option

### 8.1 Recommended Use

Use OpenAI Agents SDK as the primary productized orchestration runtime for Zephyrus agents when the application owns:

- Tool execution.
- State.
- Approvals.
- Traces.
- Handoffs.
- Guardrails.
- Persistent conversations.
- Server-side workflows.

Why:

- Official OpenAI docs position the Agents SDK for code-first agent apps where the application owns orchestration, tool execution, approvals, and state.
- The SDK supports TypeScript and Python.
- Built-in tracing captures model calls, tool calls, handoffs, guardrails, and custom spans.
- It supports handoffs for specialist agents and tool use for APIs and external systems.
- It fits Zephyrus because Laravel can call an internal TypeScript or Python agent service while keeping PHI, permissions, and writeback controls inside Zephyrus.

### 8.2 OpenAI Architecture Option A: TypeScript Agent Service

Create:

- `services/agents-openai/package.json`
- `services/agents-openai/src/server.ts`
- `services/agents-openai/src/agents/*`
- `services/agents-openai/src/tools/zephyrusTools.ts`
- `services/agents-openai/src/guardrails/*`
- `services/agents-openai/src/evals/*`

Dependencies:

- `@openai/agents`
- `zod`
- Internal Zephyrus SDK generated from Laravel API/OpenAPI routes.

Why TypeScript:

- Matches frontend type discipline and Zod contracts.
- Good for API-first agent tools and UI streaming.
- Easier to share types with React Command Center components.

Use for:

- Capacity Commander.
- Executive Briefing.
- Huddle copilot.
- Agent inbox.
- Tool-driven operational actions.
- Voice/realtime extensions later.

### 8.3 OpenAI Architecture Option B: Python Agent Service

Create:

- `services/agents-openai-python/`
- FastAPI service.
- `openai-agents`.
- OR-Tools/scikit-learn/statsmodels integration.

Why Python:

- Better optimization and forecasting ecosystem.
- Good for simulation, model evaluation, and data-science heavy agents.

Use for:

- Simulation-heavy recommendations.
- Bed assignment optimization.
- Staffing optimization.
- Forecast model analysis.
- Agent eval harness.

### 8.4 OpenAI Responses API Option

Use the Responses API directly when:

- One model call plus tools is enough.
- Zephyrus should own the orchestration loop.
- The workflow is low-risk, low-latency, and not multi-agent.

Examples:

- Summarize a huddle.
- Draft an executive briefing.
- Explain why a metric changed.
- Convert a bed meeting transcript into structured action items.

### 8.5 OpenAI Realtime and Voice Option

Use OpenAI Realtime or Voice Agents for:

- Bed huddle voice copilot.
- Transfer-center call summarization.
- Command-center spoken queries.
- Hands-free transport/EVS updates.
- Incident room coordination.

Guardrail:

- Voice agents should draft and summarize first; operational commits require explicit confirmation and audit.

## 9. Claude Agent SDK Option

### 9.1 Recommended Use

Use Claude Agent SDK when Zephyrus needs long-horizon autonomous agent behavior that can:

- Read and reason over large operational playbooks.
- Work with files and generated artifacts.
- Use command-like tools in a sandbox.
- Search, analyze, and synthesize complex documentation.
- Execute multi-step investigation workflows.
- Use custom tools through MCP.

Official Anthropic docs describe the Claude Agent SDK as giving applications the tools, agent loop, and context management that power Claude Code, programmable in Python and TypeScript. It supports agents that read files, run commands, search the web, edit code, and use custom tools through an in-process MCP server.

### 9.2 Claude Architecture Option A: Offline Operations Analyst

Use Claude Agent SDK for:

- Implementation Coach Agent.
- Data Quality Agent in sandbox mode.
- Market and standards research.
- Source-system onboarding analysis.
- Interface specification review.
- Customer playbook generation.
- Root-cause narrative generation from de-identified exports.

Safety:

- No direct production database access.
- No unrestricted shell in PHI environments.
- Use de-identified or minimized datasets.
- Route all Zephyrus actions through MCP tools with permission checks.

### 9.3 Claude Architecture Option B: Embedded Operational Agent

Use Claude Agent SDK behind a Zephyrus internal service when:

- Customer has approved Anthropic processing.
- PHI controls and BAAs are in place.
- Tools are constrained through an MCP server.
- Agent output is reviewed or approval-gated.

Good fits:

- Complex discharge-barrier plans.
- Multi-document transfer packet review.
- SOP-aware incident response.
- Long narrative explanation of why a capacity plan is recommended.

### 9.4 Claude Agent Skills

Create Claude Agent SDK skills for:

- `rtdc-huddle-facilitator`
- `transfer-center-triage`
- `discharge-barrier-resolver`
- `or-throughput-analyst`
- `healthcare-integration-auditor`
- `patient-flow-roi-analyst`

Each skill should include:

- Domain instructions.
- Safety policy.
- Allowed tools.
- Required output schema.
- Example good/bad recommendations.
- Escalation triggers.

## 10. Provider-Neutral Agent Strategy

Do not hard-code Zephyrus to one model provider.

Build an agent control plane where OpenAI and Claude are runtime options behind the same Zephyrus contracts:

- Agent definition.
- Context pack.
- Tool registry.
- Approval policy.
- Output schema.
- Trace pointer.
- Eval suite.
- Cost budget.

Provider selection matrix:

- OpenAI Agents SDK: primary for productized, traced, code-first, tool-heavy live operations.
- OpenAI Responses API: lightweight one-shot summaries and explanations.
- OpenAI Realtime/Voice: voice command center and transfer-center workflows.
- Claude Agent SDK: long-horizon analysis, document-heavy workflows, source onboarding, playbooks, and sandboxed agentic work.
- Claude API/Managed Agents: evaluate for stateful sessions if Anthropic hosted agent infrastructure becomes desirable.
- Local/open models: future option for low-risk summarization, de-identification, or on-prem deployments where data policy demands it.

## 11. MCP and Tooling Strategy

Expose Zephyrus operations as an internal MCP server so both OpenAI and Claude integrations can share tools.

Create:

- `services/mcp-zephyrus-ops/`
- Tools mapped to Laravel APIs.
- Role-aware authorization.
- PHI minimization.
- Tool-level audit.
- JSON-schema/Zod inputs and outputs.

MCP resources:

- Current census.
- Unit graph.
- Bed graph.
- Transfer queue.
- ED flow.
- OR board.
- Transport queue.
- EVS queue.
- Staffing snapshot.
- Barrier board.
- Forecasts.
- Simulation results.
- Metric lineage.
- Source freshness.

MCP tools:

- Read-only tools for most agent contexts.
- Draft-action tools for moderate-risk actions.
- Commit tools only after approval.

## 12. User Experience Leapfrog

### 12.1 Command Center V2

Every tile becomes actionable:

- Current value.
- Trend.
- Forecast.
- Source freshness.
- Root cause.
- Next best action.
- Expected impact.
- Confidence.
- Owner.
- Approval status.
- Drilldown.
- Replay/lineage.

### 12.2 Mission Control Views

Build role-specific views:

- House supervisor.
- Bed manager.
- Transfer center nurse.
- ED charge nurse.
- OR board runner.
- EVS/transport dispatcher.
- Case management.
- Staffing office.
- Executive.
- Incident commander.

### 12.3 Agent Inbox

Create a central work surface:

- Recommendations pending review.
- Actions pending approval.
- Failed or stale source feeds.
- Escalations by urgency.
- Overrides needing reason.
- Completed interventions and impact.

### 12.4 Huddle Copilot

For unit and hospital bed meetings:

- Generates pre-huddle briefing.
- Identifies changes since last huddle.
- Lists top constraints.
- Suggests agenda.
- Captures decisions.
- Converts decisions to tasks.
- Tracks completion.
- Produces shift handoff.

### 12.5 Simulation Panel

For major operational decisions:

- Baseline forecast.
- Scenario comparison.
- Capacity by unit.
- ED boarding impact.
- ICU pressure impact.
- Staffing impact.
- Transfer impact.
- OR/PACU impact.
- Financial/ROI estimate.
- Safety constraints.

### 12.6 Trust Panel

Every AI recommendation should include:

- Source facts.
- Missing facts.
- Stale feeds.
- Constraints checked.
- Alternatives considered.
- Why this action.
- Why not other actions.
- Required approvals.
- Expected benefit.
- Known risk.

## 13. Implementation Roadmap

### Phase 0: Strategic Benchmark and Product Definition

Duration: 1 to 2 weeks.

- [ ] Create market benchmark scorecard for Epic, TeleTracking, Qventus, LeanTaaS, GE, Oracle, ABOUT, Care Logistics, Palantir, Artisight, and care.ai.
- [ ] Map each competitor to Zephyrus capabilities: visibility, prediction, optimization, workflow, transfer, EVS, transport, staffing, ambient, AI, implementation, extensibility, trust.
- [ ] Define Zephyrus Flow OS product taxonomy.
- [ ] Define target ICPs: non-Epic community hospitals, multi-EHR systems, Epic systems with cross-enterprise gaps, transfer-heavy tertiary systems, capacity-constrained academic centers.
- [ ] Define product claims that can be proven in 90 days.
- [ ] Define demo narrative: "from ED boarding signal to simulated action to approved task to measured impact."

Exit criteria:

- Leadership can explain the leapfrog thesis in one minute.
- Product and engineering share one capability scorecard.

### Phase 1: Operations Graph Foundation

Duration: 4 to 6 weeks.

- [ ] Add `ops` schema migrations for nodes, edges, state snapshots, constraints, actions, recommendations, simulation runs, and outcome attribution.
- [ ] Build graph projection from existing `prod` RTDC, ED, OR, and transport tables.
- [ ] Add graph read service in Laravel.
- [ ] Add graph lineage from source tables and canonical events.
- [ ] Add initial hard constraints for bed assignment and transport dispatch.
- [ ] Add `GET /api/ops/graph/snapshot`.
- [ ] Add `GET /api/ops/graph/node/{id}/timeline`.
- [ ] Add tests for graph projection and replay.

Exit criteria:

- Zephyrus can render the current hospital state as a graph-backed snapshot.
- Bed, encounter, unit, ED visit, OR case, and transport request nodes are connected.

### Phase 2: Actionable Command Center

Duration: 4 to 6 weeks.

- [ ] Add source freshness and metric lineage to `CommandCenterDataService`.
- [ ] Convert each Command Center metric into a metric definition and metric lineage row.
- [ ] Add root-cause drilldown for capacity, ED boarding, OR flow, and discharge barriers.
- [ ] Add next-best-action cards for top operational bottlenecks.
- [ ] Add agent inbox shell with non-AI rules-based recommendations first.
- [ ] Add approval queue model and UI.
- [ ] Add "stale source" visual state.

Exit criteria:

- Command Center moves from observability to action: at least 10 rules-based recommendations can be reviewed, approved, assigned, and tracked.

### Phase 3: Forecasting and Simulation

Duration: 6 to 10 weeks.

- [ ] Add forecast service for ED arrivals, admissions, discharges, census, and OR/PACU pressure.
- [ ] Add OR-Tools or equivalent optimizer service for constrained assignment and scenario analysis.
- [ ] Add simulation runs for bed placement, discharge acceleration, EVS acceleration, transport reallocation, staffing moves, and transfer acceptance.
- [ ] Add scenario comparison UI.
- [ ] Add intervention outcome measurement.
- [ ] Add model versioning and forecast backtesting.

Exit criteria:

- A user can compare at least three capacity scenarios and approve one action plan.
- Forecast accuracy and recommendation acceptance are measured.

### Phase 4: Agent Control Plane

Duration: 6 to 8 weeks.

- [ ] Add `ops.agent_definitions`, `ops.agent_runs`, `ops.agent_tool_calls`, `ops.agent_approvals`, `ops.agent_evaluations`, and `ops.agent_safety_events`.
- [ ] Implement Zephyrus tool registry.
- [ ] Implement internal MCP server.
- [ ] Implement OpenAI Agents SDK TypeScript prototype.
- [ ] Implement Claude Agent SDK prototype in sandbox mode.
- [ ] Add provider-neutral agent runner API.
- [ ] Add trace and audit viewer.
- [ ] Add evaluation harness with golden cases.
- [ ] Add PHI guardrails and prompt-injection tests.

Exit criteria:

- Capacity Commander and Discharge Flow Agent can run in read/draft mode with traces, approvals, and evals.

### Phase 5: Closed-Loop Workflow Execution

Duration: 8 to 12 weeks.

- [ ] Connect approved recommendations to `ops.actions`.
- [ ] Connect actions to Zephyrus transport, bed request, barrier, huddle, and task workflows.
- [ ] Add writeback adapters for FHIR `Task`/`ServiceRequest` where safe.
- [ ] Add secure message draft generation.
- [ ] Add override reasons and learning loop.
- [ ] Add shift handoff and daily executive briefing.
- [ ] Add outcome attribution dashboard.

Exit criteria:

- Zephyrus can show "recommendation -> approval -> task -> completion -> measured KPI impact" for at least one live domain.

### Phase 6: Transfer Center and Regional Flow

Duration: 8 to 12 weeks.

- [ ] Build transfer request graph.
- [ ] Ingest external facility and capability data.
- [ ] Score transfer acceptance and destination options.
- [ ] Simulate transfer acceptance impact on system capacity.
- [ ] Add transfer agent.
- [ ] Add interfacility transport constraints.
- [ ] Add regional executive dashboard.

Exit criteria:

- Zephyrus can recommend transfer destination/acceptance decisions with capacity, staffing, clinical service, transport, and opportunity-cost evidence.

### Phase 7: Ambient and Smart Hospital Signal Layer

Duration: customer dependent.

- [ ] Define Ambient Signal Adapter contract.
- [ ] Ingest RTLS events.
- [ ] Ingest nurse call/device events.
- [ ] Ingest smart room/virtual nursing events where available.
- [ ] Ingest OR/PACU ambient milestones where available.
- [ ] Add confidence scoring for ambient-derived events.
- [ ] Use ambient events to improve timestamps, transport, EVS, falls/safety, and OR flow.

Exit criteria:

- Zephyrus can improve event timeliness and data quality from at least one non-EHR real-world signal source.

## 14. First 30-Day Build Plan

Week 1:

- [ ] Add this market leapfrog plan to the repo.
- [ ] Build competitor scorecard markdown and capability matrix.
- [ ] Define `ops` schema migration draft.
- [ ] Define agent safety policy draft.
- [ ] Define Zephyrus tool registry draft.

Week 2:

- [ ] Implement `ops.nodes`, `ops.edges`, `ops.state_snapshots`, `ops.constraints`.
- [ ] Build projection from existing Command Center seed data.
- [ ] Add graph snapshot API.
- [ ] Add unit tests for graph projection.

Week 3:

- [ ] Implement recommendations, actions, approvals, and intervention tables.
- [ ] Add rules-based recommendations for ED boarding, bed pressure, blocked beds, OR cancellations, transport SLA risk.
- [ ] Add Agent Inbox UI shell.
- [ ] Add approval workflow.

Week 4:

- [ ] Implement OpenAI Agents SDK prototype with one read-only Capacity Commander.
- [ ] Implement Claude Agent SDK prototype with one sandboxed Implementation Coach.
- [ ] Add internal Zephyrus MCP tool server prototype.
- [ ] Add trace/audit records for agent runs.
- [ ] Create demo: "capacity crisis -> recommendation -> approval -> action plan -> simulated impact."

## 15. 90-Day Target Demo

The 90-day demo should make incumbents feel old.

Scenario:

- ED is boarding 12 patients.
- ICU has two pending transfers.
- OR has PACU holds.
- EVS turnaround is behind.
- Transport queue is overloaded.
- Case management has 14 discharge barriers.
- Staffing is tight on two med-surg units.

Zephyrus should:

1. Show live operational graph.
2. Identify root causes across ED, inpatient, OR, EVS, transport, staffing, and discharge.
3. Run simulations:
   - no action;
   - accelerate EVS;
   - pull forward five discharges;
   - reassign transport;
   - open flex beds;
   - redirect transfer;
   - combined plan.
4. Recommend the highest-value plan.
5. Explain constraints and missing data.
6. Request human approval.
7. Create tasks and huddle plan items.
8. Track completion.
9. Measure improvement.
10. Generate executive and shift handoff summaries.

Acceptance:

- The demo proves Zephyrus is not a passive dashboard.
- It proves a safe, auditable AI agent can orchestrate hospital operations under human control.

## 16. 180-Day Product Milestone

By 180 days, Zephyrus should have:

- EHR-neutral acquisition fabric for at least ADT, bed, ED, OR, and transport data.
- Operations graph.
- Command Center V2.
- Rules-based recommendations.
- Simulation engine.
- OpenAI and Claude agent prototypes behind a shared tool contract.
- Two production-grade agents in approval-gated mode.
- Intervention attribution dashboard.
- Customer implementation playbook.
- Sales demo that clearly beats static command centers.

## 17. Twelve-Month Product Ambition

By 12 months, Zephyrus should be able to claim:

- Vendor-neutral, real-time hospital operations digital twin.
- Multi-domain operational agents with approval and trace.
- Simulation-backed next-best-action recommendations.
- Cross-facility transfer and capacity optimization.
- Ambient signal ingestion.
- Payer/post-acute barrier orchestration.
- Source-level lineage for every metric and recommendation.
- Measurable ROI attribution.
- Deployment patterns for Epic, Oracle Health, MEDITECH, and mixed-EHR environments.

## 18. Build vs. Partner Strategy

Build:

- Canonical event ledger.
- Operations graph.
- Digital twin.
- Agent control plane.
- Recommendation and approval fabric.
- Simulation UX.
- Outcome attribution.
- Zephyrus MCP tools.

Partner or integrate:

- EHR source systems.
- Interface engines.
- RTLS.
- Ambient computer vision/smart rooms.
- Staffing systems.
- EVS/facilities systems.
- Transport vendors.
- HIE/TEFCA.
- Payer/clearinghouse.
- OR optimization specialists where customer already owns them.

Avoid:

- Building hardware before software-market fit.
- Replacing EHR documentation.
- Autonomous clinical decisions.
- Locking to one LLM provider.
- Making AI recommendations without lineage.

## 19. Differentiation Scorecard

Zephyrus must win these categories:

- Multi-source coverage: better than Epic-native and Oracle-native modules.
- Time to value: faster than enterprise command-center programs.
- Actionability: stronger than dashboards.
- Trust: stronger than generic AI/data platforms.
- Agent governance: stronger than AI teammate marketing.
- Simulation: stronger than prediction-only products.
- Outcome attribution: stronger than anecdotal ROI.
- Developer ecosystem: stronger than closed modules.
- Implementation kit: stronger than software-only deployments.

## 20. Sales Narrative

Problem:

- Hospitals do not have a bed problem only. They have a coordination problem across beds, staff, transport, EVS, ORs, transfers, payer barriers, discharges, and external facilities.

Why incumbents fall short:

- EHR modules see what the EHR owns.
- Patient-flow vendors optimize slices.
- AI vendors automate tasks.
- Command centers visualize pressure.
- Data platforms integrate data but can lack healthcare-specific operational trust.

Zephyrus answer:

- Zephyrus sees the whole operational system, simulates decisions, orchestrates work, and proves impact.

Proof points to target:

- Reduce ED boarding.
- Reduce avoidable days.
- Improve discharges before noon.
- Improve OR first-case starts and reduce cancellations.
- Reduce EVS bed turnaround.
- Improve transport SLA.
- Improve transfer acceptance throughput.
- Improve staffing alignment.
- Reduce stale-feed operational blind spots.

## 21. Risks and Countermeasures

Risk: Epic customers ask why they need Zephyrus if they already own Grand Central.

- Counter: Zephyrus is not a replacement. It is the cross-system AI orchestration layer for everything Grand Central does not own, plus source lineage, simulation, agentic execution, and outcome attribution.

Risk: AI agents cause safety or trust concerns.

- Counter: start read-only, then draft-only, then approval-gated. Expose trace, evidence, constraints, and override controls.

Risk: Integration complexity slows the product.

- Counter: build from existing Zephyrus seeded/live tables first, then progressively replace feeds with acquisition fabric.

Risk: Competitors add agents.

- Counter: make Zephyrus differentiation the graph, digital twin, simulation, and governance, not just the model.

Risk: Model provider uncertainty.

- Counter: provider-neutral control plane with OpenAI and Claude adapters.

Risk: Command center adoption is operational, not only technical.

- Counter: ship huddle scripts, role charters, escalation templates, KPI packs, and implementation coach agent.

## 22. External Sources Used

- Epic Hospital Patient Flow / Grand Central: https://www.epic.com/software/hospital-patient-flow/
- EpicShare Ochsner centralized patient flow center: https://www.epicshare.org/share-and-learn/ochsner-patient-flow-center
- KLAS Patient Flow category: https://klasresearch.com/compare/patient-flow/84
- KLAS Epic Grand Central comments/review page: https://klasresearch.com/review/epic-grand-central-patient-flow-full-suite/216441
- TeleTracking homepage / Operations IQ: https://www.teletracking.com/
- TeleTracking Capacity IQ: https://www.teletracking.com/resources/capacity-iq/
- TeleTracking 2024 Best in KLAS announcement: https://www.teletracking.com/news/teletracking-technologies-named-2024-best-in-klas-for-patient-flow/
- Qventus: https://www.qventus.com/
- Qventus KLAS Emerging Solution Spotlight announcement: https://www.qventus.com/company/newsroom/qventus-receives-high-rating-klas-research-capacity-optimization-management-report/
- LeanTaaS iQueue for Inpatient Flow: https://leantaas.com/products/inpatient-flow/
- LeanTaaS product overview: https://leantaas.com/products/
- GE HealthCare Command Center announcement: https://investor.gehealthcare.com/news-releases/news-release-details/ge-healthcare-announces-major-academic-medical-center-first/
- GE HealthCare Command Center executive brief: https://events.dev.gehealthcare.com/wp-content/uploads/2024/02/Why-GE-Command-Center-Executive-Brief-USEN-JB22190XX.pdf
- Oracle Health Systems Operations: https://www.oracle.com/health/clinical-operations/systems-operations/
- Oracle Health Clinical Operations: https://www.oracle.com/health/clinical-operations/
- Oracle emergency operations capabilities: https://www.oracle.com/news/announcement/oracle-helps-health-systems-optimize-management-of-emergency-situations-2025-03-04/
- ABOUT Healthcare solutions: https://abouthealthcare.com/solutions/
- ABOUT Healthcare access centers and patient orchestration: https://abouthealthcare.com/articles/access-centers-and-patient-orchestration-what-health-systems-need-to-know/
- Care Logistics hospital command centers: https://www.carelogistics.com/hospital-command-centers
- Palantir for Hospitals: https://www.palantir.com/offerings/palantir-for-hospitals/
- Artisight Smart Hospital Platform: https://artisight.com/
- care.ai Smart Care Facility Platform: https://www.care.ai/
- Hospital command center patient-flow impact study: https://pmc.ncbi.nlm.nih.gov/articles/PMC10566538/
- Hospital Capacity Command Centers benchmarking survey: https://pure.johnshopkins.edu/en/publications/hospital-capacity-command-centers-a-benchmarking-survey-on-an-eme
- OpenAI Agents SDK guide: https://developers.openai.com/api/docs/guides/agents
- OpenAI Agents SDK TypeScript docs: https://openai.github.io/openai-agents-js/
- OpenAI Agents SDK Python docs: https://openai.github.io/openai-agents-python/agents/
- OpenAI Agents SDK tracing: https://developers.openai.com/api/docs/guides/agents/integrations-observability
- OpenAI SDKs and libraries: https://developers.openai.com/api/docs/libraries
- Claude Agent SDK overview: https://docs.claude.com/en/api/agent-sdk/overview
- Claude Agent SDK agent loop: https://code.claude.com/docs/en/agent-sdk/agent-loop
- Claude Agent SDK custom tools: https://code.claude.com/docs/en/agent-sdk/custom-tools
- Claude Agent SDK Python reference: https://docs.anthropic.com/en/docs/claude-code/sdk/sdk-python
- Claude Agent SDK TypeScript reference: https://docs.anthropic.com/en/docs/claude-code/sdk/sdk-typescript
