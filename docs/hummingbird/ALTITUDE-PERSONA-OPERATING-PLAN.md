# Hummingbird 2.0 - Altitude Persona Operating Plan

**Status:** Directional execution plan
**Date:** 2026-07-01
**Scope:** Hummingbird mobile companion aligned to `docs/product/ZEPHYRUS-2.0-PLAN.md`

## 1. Executive Decision

Hummingbird should become the mobile operating surface for the Zephyrus 2.0 Cockpit direction.
The current mobile app already has role-specific homes, a Sanctum-protected mobile BFF, and
live endpoints for RTDC, transport, EVS, OR, command, ops, staffing, improvement, and Eddy.
The new work is to make those role screens obey the same Altitude model as Zephyrus 2.0 and
to add a patient/encounter drill leaf so any authorized worker can descend from house status
to the exact patient, bed, case, barrier, trip, turn, or approval that needs attention.

The governing rule:

> One truth, many altitudes, one patient-centered action trail. Every persona sees the same
> operational state at the right distance, every action updates the other personas that depend
> on it, and Eddy receives the complete operational decision trail while remaining an
> advice-and-draft system, never an autonomous actor.

This is not a second cockpit and not a reduced desktop port. It is the mobile cockpit lens:
the same snapshot, status vocabulary, thresholds, action lifecycle, and Eddy governance loop,
reshaped around each worker's shift context.

## 2. Repo-grounded Findings

The Zephyrus 2.0 plan establishes the canonical Altitude model:

| Altitude | Zephyrus 2.0 meaning | Hummingbird adaptation |
|---|---|---|
| A0 - Glance | Is the house OK right now? | Role home: the persona's one most important truth in 3 seconds. |
| A1 - Workspace | Show me everything in this domain, live. | Persona workspace: job queue, unit board, house capacity, OR board, staffing, PI, brief. |
| A2 - Drill | Why is this tile red? | Mobile drill: the exact alert, dependency, queue item, patient, bed, case, trip, turn, action, or KPI driver. |
| A3 - Study | What pattern over weeks should we change? | Mobile summary plus "Open in Zephyrus" deep link for analytical work. |

The patient drill is not a fifth altitude. It is the deepest A2 leaf on mobile: `A2P -
Patient/Encounter Lens`. Hummingbird must make this leaf reachable from any relevant A0/A1/A2
signal, subject to role authorization and PHI minimization.

Current Hummingbird assets already support the direction:

- `routes/api.php` exposes `/api/mobile/v1/*` behind Sanctum, `mobile:read`, and
  `mobile:act` for governed writes.
- `Role.swift` defines 14 operational role ids: charge nurse, bedside nurse, bed manager,
  house supervisor, hospitalist, intensivist, EVS, transport, OR nurse, capacity lead,
  perioperative manager, staffing coordinator, PI lead, and executive.
- `RoleExperience.swift` maps roles to home types: census, transport jobs, EVS turns,
  house capacity, OR board, capacity demand, house brief, staffing, and improvement.
- `ForYouController` currently aggregates placements, barriers, capacity, transport, and
  EVS into a single queue, but the item shape is too thin to drive cross-persona relay,
  patient drill, dependency tracking, or Eddy's full awareness.
- `EddyController` already provides mobile chat, streaming, conversation history, approval
  inbox, approval preview, and human approval/rejection through the existing lifecycle.

The missing product layer is therefore clear:

1. A formal mobile Altitude contract shared by all personas.
2. A patient/encounter operational context endpoint and screen.
3. A universal activity and dependency ledger for every recommendation, decision, action,
   and progress update.
4. Role-filtered fan-out so each persona sees the events that affect their scope without
   turning Hummingbird into a noisy pager.
5. Eddy ingestion of the complete operational action trail so it can reason over the
   house care team's decisions and progress.

## 3. Core Product Model

### 3.1 The Mobile Altitude Ladder

Every Hummingbird persona uses the same descent:

1. **A0 - Persona Glance**
   The first tab answers the persona's single top question: my trips, my turns, my unit,
   house capacity, OR today, staffing gaps, improvement work, or executive strain.

2. **A1 - Live Workspace**
   The worker sees the live queue or board for their domain, scoped by assignment and role.
   This is where they claim, assign, approve, resolve, start, complete, hand off, or
   acknowledge.

3. **A2 - Alert/Queue Drill**
   A red or amber item opens an explanation: why it matters, what changed, who is already
   working on it, what depends on it, what Eddy recommends, and what action is allowed.

4. **A2P - Patient/Encounter Lens**
   If the item is linked to a patient, case, visit, bed request, barrier, transport, EVS
   turn, OR case, ED visit, or staffing dependency, the drill can open a patient-centered
   operational view. This view is role-authorized, PHI-minimized in lists, and full-PHI only
   after explicit authorized entry.

5. **A3 - Study / Retrospective**
   The mobile app summarizes trends and links to Zephyrus for charts, process mining, PDSA
   study, utilization analysis, scenario modeling, and command-center review. Mobile does
   not rebuild desktop-grade analytics.

### 3.2 The Patient/Encounter Lens

`A2P` must be a single reusable screen, not a separate patient detail implementation in every
feature. It should show operational intelligence only:

- Header: patient display allowed by role, patient_ref token, current location, target
  location, service, isolation/precaution flag when operationally relevant, responsible
  team, and as-of time.
- Status spine: current state across ED, inpatient unit, OR, transport, EVS, staffing,
  barriers, bed request, and approvals.
- Timeline: ordered operational events with actor, role, source, timestamp, status before,
  status after, and affected dependency.
- Dependencies: what this patient is waiting on and who owns it, such as EVS turn,
  transport pickup, bed placement, consult/discharge barrier, OR milestone, staffing
  constraint, approval, or huddle action.
- Recommendations: active Eddy recommendations, rule-based recommendations, and human
  overrides, each with rationale and runner-up where available.
- Actions: only the role-authorized actions for the signed-in worker.
- Relay: who will be notified if the user acts.
- Web handoff: deep link to the corresponding Zephyrus workspace or study surface.

This screen is the connective tissue between "house status" and "the patient in front of
the team." It is not an EHR chart and must not become one.

### 3.3 The Cross-persona Relay Contract

Every mobile-visible action must write an operational event. That event is the single source
for fan-out, audit, For You refresh, push, and Eddy awareness.

Minimum event shape:

```json
{
  "event_uuid": "uuid",
  "event_type": "bed_request.placed",
  "occurred_at": "2026-07-01T14:32:00Z",
  "actor_user_id": 123,
  "actor_role": "bed_manager",
  "source_surface": "hummingbird",
  "domain": "rtdc",
  "scope": {
    "facility_id": "summit",
    "unit_id": 12,
    "patient_ref": "ptok_...",
    "encounter_ref": "enc_...",
    "bed_id": 44,
    "action_uuid": "uuid",
    "approval_uuid": "uuid"
  },
  "status": {
    "previous": "pending",
    "current": "placed",
    "severity": "warning"
  },
  "recommendation": {
    "source": "eddy",
    "recommendation_uuid": "uuid",
    "runner_up": "6 West 12",
    "human_decision": "approved"
  },
  "relay": {
    "affected_roles": ["charge_nurse", "evs", "transport", "capacity_lead"],
    "push_tier": "warning",
    "notify_now": ["charge_nurse"],
    "activity_only": ["executive", "pi_lead"]
  },
  "phi_policy": {
    "list_safe": true,
    "push_safe": true,
    "requires_detail_auth": true
  }
}
```

Relay does not mean every worker gets paged for every event. It means every persona can see
the complete role-relevant trail, and only the workers who must act are interrupted.

### 3.4 Eddy Awareness Contract

Eddy must be aware of every recommendation, decision, action, and progress update by the
house care team. That awareness should be implemented as event ingestion, not by scraping
screens.

Rules:

- Eddy receives all operational events in PHI-minimized form by default.
- Eddy can join events to patient/encounter context only when the asking user is authorized
  and the surface is allowed to carry that context.
- Eddy never receives `ops:approve`; human approval stays in `OperationalActionLifecycleService`.
- Eddy recommendations carry `recommendation_uuid`, source metric, status, affected scope,
  action type, rationale, runner-up, stale-data check, and safety flags.
- Human decisions, rejections, overrides, assignments, starts, completions, and handoffs are
  written back to the same event stream so Eddy learns what the team actually sanctioned.
- Eddy answers "what changed, who acted, what is still blocked, who needs to know, and what
  should happen next?" from this event stream plus the cockpit snapshot.

## 4. Data and API Additions

### 4.1 BFF Extensions

Keep the existing BFF shape `{ data, meta, links }`, but extend mobile payloads with:

- `altitude`: `A0`, `A1`, `A2`, `A2P`, or `A3`.
- `persona`: role id and assignment scope.
- `status`: canonical StatusEngine value, glyph, label, and generated_at.
- `provenance`: source service, metric key, query/snapshot version, stale flag.
- `recommended_actions`: rule or Eddy proposals the user may inspect.
- `dependencies`: role/persona/unit/patient dependencies.
- `activity`: latest relevant operational events.
- `subscriptions`: who else is watching or affected.
- `patient_context_ref`: opaque reference when a patient lens is available.
- `web`: deep link to the corresponding Zephyrus altitude.

Recommended new endpoints:

| Endpoint | Purpose |
|---|---|
| `GET /api/mobile/v1/altitude/home` | Persona A0 composition from cockpit snapshot plus role assignment. |
| `GET /api/mobile/v1/altitude/workspace/{domain}` | A1 live board/queue with common metadata and status provenance. |
| `GET /api/mobile/v1/drills/{item_uuid}` | A2 explanation for a For You item, alert, KPI, approval, or queue row. |
| `GET /api/mobile/v1/patients/{patient_ref}/operational-context` | A2P patient/encounter lens, role-authorized. |
| `GET /api/mobile/v1/activity` | Role-filtered cross-persona event feed, cursor-based. |
| `POST /api/mobile/v1/activity/{event_uuid}/ack` | Acknowledge that an event was seen, without mutating the operational object. |
| `GET /api/mobile/v1/eddy/context/{scope_ref}` | Eddy-ready context packet for a drill or patient lens. |

Existing domain endpoints should keep working, but their response bodies should gradually
conform to the new altitude and event metadata.

### 4.2 Backend Services

Add or formalize these services:

- `MobileAltitudeService`: builds A0/A1/A2/A2P/A3 packets per persona.
- `MobilePatientContextService`: constructs the operational patient lens from RTDC, ED,
  OR, transport, EVS, staffing, barriers, and ops approvals.
- `OperationalActivityLedger`: records every recommendation, decision, action, status
  transition, handoff, and progress update.
- `PersonaRelayPolicy`: maps events to affected roles, push tier, activity-only recipients,
  and Eddy context availability.
- `MobileForYouService`: replaces controller-local queue assembly with a reusable ranking
  service backed by activity, dependency, assignment, and StatusEngine output.
- `EddyOperationalAwarenessService`: subscribes to the ledger and maintains Eddy-safe
  context packets.

### 4.3 Database / Event Storage

Additive tables:

- `ops.operational_events`
- `ops.operational_event_targets`
- `ops.operational_event_entities`
- `ops.operational_event_acknowledgements`
- `ops.patient_operational_context_cache`
- `ops.eddy_context_packets`

The cache table is an optimization, not a source of truth. The event ledger and existing
domain tables remain authoritative.

## 5. Persona-by-persona Operating Plan

Each persona below must have A0, A1, A2, A2P, A3, relay, and Eddy behavior. The goal is not
equal screen density. The goal is equal situational intelligence at the correct altitude.

### 5.1 Charge Nurse (`charge_nurse`)

**A0 - Glance:** "Is my unit safe to receive and discharge?" Show pinned unit capacity,
acuity-safe headroom, open barriers, expected discharges, inbound bed requests, staffing
gap, dirty/blocked beds, active transports, and huddle status.

**A1 - Workspace:** Unit board with patients grouped by operational state: ready for
discharge, barrier open, waiting for transport, waiting for EVS, pending bed request,
incoming admit, staffing-sensitive, and huddle action due.

**A2 - Drill:** Drill any amber/coral tile into the exact driver: which barrier aged, which
bed request is pending, which EVS turn blocks an admit, which staffing gap prevents safe
admission, which transport is late.

**A2P - Patient Lens:** For each patient on the unit or inbound to the unit, show current
unit, bed, service, discharge barrier, target disposition, transport/EVS dependencies,
handoff notes, huddle actions, and placement status.

**A3 - Study:** Unit flow trends, recurring barriers, discharge-before-noon performance,
staffing-sensitive throughput, and huddle reliability open in Zephyrus.

**Actions:** Resolve/reassign barrier, request bed, acknowledge inbound patient, create
huddle action, mark unit readiness, escalate staffing risk, message capacity lead through
an action thread.

**Relay:** Bed managers see readiness changes and barriers. EVS sees dirty-bed dependency.
Transport sees pickup readiness. Staffing coordinator sees staffing escalation. Capacity
lead sees huddle/action implications. Hospitalists see discharge barrier progress.

**Eddy:** Eddy knows the unit's current blockers, what the charge nurse has resolved, what
was rejected or deferred, and who owns the next dependency.

### 5.2 Bedside / Duty Nurse (`bedside_nurse`)

**A0 - Glance:** "Which of my patients need operational action?" Show assigned patients
with only action-driving statuses: discharge barrier, pending transport, isolation/EVS
dependency, bed move, OR/pre-op milestone, consult/disposition delay, safety note, and
huddle item.

**A1 - Workspace:** My-patients list sorted by action urgency rather than room number.
Each row shows one operational next step and whether someone else already owns it.

**A2 - Drill:** Open the exact task: barrier reason, transport status, pending bed move,
EVS status, OR milestone, or action request.

**A2P - Patient Lens:** Full authorized operational context for the patient, including
timeline, care-team actors, current dependencies, and allowed actions.

**A3 - Study:** Bedside nurses do not need mobile study views by default. Link to unit
review or patient-flow study when authorized.

**Actions:** Acknowledge readiness, add/resolve operational barrier, confirm patient-ready
for transport, acknowledge incoming transfer, add handoff note, escalate through charge
nurse.

**Relay:** Charge nurse receives every bedside action. Transport receives patient-ready
events. EVS receives room-ready/dirty events when relevant. Hospitalist receives discharge
barrier updates. Eddy receives all status changes and handoffs.

### 5.3 Bed Manager / Flow (`bed_manager`)

**A0 - Glance:** "Can the house absorb demand?" Show house occupancy, net bed need,
pending placements, ED boarding, critical units, available beds by type, dirty beds, and
blocked capacity.

**A1 - Workspace:** Placement board with pending bed requests, candidate beds, ranked
recommendations, unit readiness, EVS/transport dependencies, and ED/inpatient demand.

**A2 - Drill:** Explain why a placement is amber/coral: isolation match, acuity mismatch,
staffing gap, bed not clean, transport delay, boarding timer, competing request, or stale
data.

**A2P - Patient Lens:** Bed-request patient context: source, target level of care, service,
isolation requirement, current boarding/waiting state, placement candidates, chosen bed,
runner-up, and who must prepare.

**A3 - Study:** Boarding trends, placement latency, bed turnover, avoidable delays,
placement overrides, and source-of-delay review in Zephyrus.

**Actions:** Accept/edit/reject placement, create bed request, assign EVS/transport
dependency, escalate capacity alert, request surge huddle, annotate override rationale.

**Relay:** Charge nurse gets inbound placement and readiness request. EVS gets bed-turn
priority. Transport gets move timing. Capacity lead gets capacity decision trail.
Executive sees crit-level house strain. Eddy sees recommendation, selected bed, runner-up,
override, and downstream dependencies.

### 5.4 House Supervisor (`house_supervisor`)

**A0 - Glance:** "What is threatening the house right now?" Show house status, critical
alerts, units crossing thresholds, approvals pending, blocked discharges, staffing gaps,
and active incident/surge state.

**A1 - Workspace:** House escalation workspace: active alerts, huddle queue, pending
actions, unresolved cross-domain dependencies, and event stream.

**A2 - Drill:** Open any escalation to show contributing units, patients/encounters,
timers, responsible roles, prior decisions, and next allowable actions.

**A2P - Patient Lens:** Only for patients involved in house-level escalations or transfers,
with operational context and limited PHI based on authorization.

**A3 - Study:** House operations review, repeated escalation causes, staffing-flow impact,
and action effectiveness.

**Actions:** Convene huddle, assign action owner, approve/deny low-risk operational
actions if authorized, escalate to capacity lead/executive, acknowledge or suppress an
alert with reason.

**Relay:** All affected domain leads see supervisor decisions. Executives see crit-level
escalations and resolutions. Eddy receives the full huddle/action trail.

### 5.5 Hospitalist (`hospitalist`)

**A0 - Glance:** "Which of my patients are blocking flow or need a discharge decision?"
Show assigned service census, discharge-ready candidates, consult/barrier age, pending
disposition, bed requests needing medical input, and readmission/LOS risk where available.

**A1 - Workspace:** Service discharge and barrier worklist sorted by operational impact:
ED boarder awaiting admit, discharge barrier, SNF/home-health delay, consult pending,
medical clearance pending, patient move awaiting handoff.

**A2 - Drill:** Explain the specific barrier and operational consequence: blocked bed,
pending transport, staffing dependency, ED boarder impact, or huddle action.

**A2P - Patient Lens:** Authorized operational patient detail with service, unit, barriers,
disposition, consults, transport, bed status, action history, and responsible roles.

**A3 - Study:** Service-level discharge timing, barrier recurrence, LOS impact, and
throughput opportunity in Zephyrus.

**Actions:** Mark medical clearance status, resolve/reassign barrier, add disposition note,
acknowledge bed request, respond to huddle action, escalate to charge/case management.

**Relay:** Charge nurse sees clearance and barrier changes. Bed manager sees beds becoming
available. EVS/transport see downstream readiness. PI lead sees recurring barrier patterns.
Eddy sees decisions and can recommend next operational step.

### 5.6 Intensivist (`intensivist`)

**A0 - Glance:** "Which critical-care decisions affect capacity and safety?" Show ICU and
step-down occupancy, acuity-safe headroom, pending ICU requests, downgrade candidates,
blocked ICU beds, critical transport dependencies, and staffing constraints.

**A1 - Workspace:** Critical-care board: ICU/step-down patients, pending transfers,
downgrade/discharge barriers, isolation constraints, staffing risk, and bed requests.

**A2 - Drill:** Show the reason ICU capacity is constrained: no staffed bed, no clean bed,
high acuity, delayed downgrade, pending transport, pending approval, or isolation mismatch.

**A2P - Patient Lens:** Authorized critical-care operational context with current unit,
target unit, level-of-care need, bed request, downgrade barrier, transport, EVS, staffing,
and action timeline.

**A3 - Study:** ICU capacity trends, downgrade delay, avoidable ICU boarding, critical-care
staffing impact, and transfer patterns.

**Actions:** Acknowledge/approve level-of-care movement if authorized, flag downgrade
barrier, update readiness, respond to ICU bed request, create huddle action.

**Relay:** Bed manager and capacity lead see ICU decisions immediately. Charge nurses see
incoming/outgoing patient movement. Transport/EVS see dependencies. Eddy receives all
critical-care flow decisions.

### 5.7 Transporter (`transport`)

**A0 - Glance:** "What trip needs me now?" Show active trip, next STAT/urgent job, pickup
readiness, SLA timer, origin, destination, required mode, and handoff requirement.

**A1 - Workspace:** My trips plus available jobs, sorted by priority, due time, assignment,
and proximity when available.

**A2 - Drill:** Job detail with lifecycle, timer, origin/destination, readiness state,
equipment/mode, handoff fields, and downstream dependency.

**A2P - Patient Lens:** Transport-safe patient context: patient token or authorized display,
pickup location, destination, isolation/precaution operational flag, readiness, handoff
summary, outstanding risk labels, and status timeline. No clinical chart data.

**A3 - Study:** Transport SLA, delay causes, handoff reliability, and demand patterns in
Zephyrus.

**Actions:** Claim, arrived, picked up, en route, arrived, handoff, complete, patient not
ready, request assistance.

**Relay:** Charge nurse sees patient movement and not-ready reasons. Bed manager sees when
placement movement starts/completes. OR nurse sees transport-to-OR state. EVS can prepare
vacated bed. Eddy sees progress and delay causes.

### 5.8 EVS (`evs`)

**A0 - Glance:** "Which bed turn unlocks care next?" Show next dirty bed, overdue turns,
isolation turns, bed-turn SLA, assigned/in-progress work, and beds blocking placements.

**A1 - Workspace:** Bed-turn queue sorted by care impact: placement-blocking, isolation,
overdue, terminal, routine.

**A2 - Drill:** Turn detail with bed/location, turn type, SLA, isolation/PPE SOP, prior
patient movement state, and downstream patient/placement dependency.

**A2P - Patient Lens:** Usually not full patient PHI. Show the operational dependency: bed
needed for pending patient token, source unit/ED/OR if authorized, target unit, and expected
placement consequence.

**A3 - Study:** Turnaround trends, overdue patterns, isolation load, staffing impact, and
blocked-bed contribution.

**Actions:** Claim, start, complete, blocked/unable, request supplies, flag isolation issue.

**Relay:** Bed manager sees bed placeability. Charge nurse sees bed ready/blocked. Transport
sees destination readiness. Capacity lead sees capacity unlock. Eddy records blocked/ready
state and can revise recommendations.

### 5.9 OR Nurse (`or_nurse`)

**A0 - Glance:** "What case or room needs action now?" Show assigned room/case, current
phase, safety-note SLA, pre-op milestones, transport-to-OR readiness, turnover state, next
case timer.

**A1 - Workspace:** Live OR room board scoped to assigned rooms/cases, with phase, delay,
milestone, turnover, safety note, and transport dependency.

**A2 - Drill:** Case/room detail explaining delay or breach: missing consent, labs,
transport, room turnover, surgeon/anesthesia delay, equipment, safety note, or downstream
bed dependency.

**A2P - Patient Lens:** Authorized surgical encounter context: procedure label, room, case
phase, safety milestones, transport state, destination bed dependency, and operational
timeline.

**A3 - Study:** Turnover outliers, first-case starts, safety-note timeliness, cancellation
patterns, and block utilization in Zephyrus.

**Actions:** Acknowledge milestone, update case phase, add safety note, mark transport
ready, flag delay cause, request turnover/transport dependency.

**Relay:** Periop manager sees delays and milestone status. Transport sees OR pickup
readiness. EVS sees turnover/cleaning dependency. Bed manager sees postop bed demand.
Eddy sees surgical flow decisions and delay causes.

### 5.10 Capacity Lead (`capacity_lead`)

**A0 - Glance:** "What decisions will change the next four hours?" Show capacity vs demand,
strain/surge index, forecast, pending approvals, high-impact barriers, stale feeds, and
unowned actions.

**A1 - Workspace:** Capacity command workspace: forecast, approvals, huddle queue, alert
ticker, active operational actions, assignment progress, and cross-domain dependencies.

**A2 - Drill:** Action or alert detail with metric provenance, contributing units/patients,
Eddy recommendation, runner-up, downstream impact, stale-data guardrail, and approval path.

**A2P - Patient Lens:** For patient-linked actions, show operational patient context and
the expected system impact of the decision.

**A3 - Study:** Forecast accuracy, action effectiveness, alert precision, huddle completion,
and capacity opportunity portfolio.

**Actions:** Approve/reject/override Eddy or rules recommendations, assign action owner,
start surge plan, facilitate huddle, suppress/acknowledge alert with reason.

**Relay:** Every affected persona receives role-filtered activity. Executives see material
house escalation and resolution. Eddy records the human decision and override rationale.

### 5.11 Perioperative Manager (`periop_manager`)

**A0 - Glance:** "Is the OR day drifting?" Show rooms running, turnover, first-case starts,
delays, cancellations, staffing gaps, PACU/bed constraints, and safety-note breaches.

**A1 - Workspace:** OR day board across rooms with delayed cases, turnover outliers,
cancellations, staffing/resource issues, transport dependencies, and bed downstream risk.

**A2 - Drill:** Delay/cancellation/turnover detail with cause, responsible team, patient
encounter, room, ETA, downstream capacity impact, and recommended action.

**A2P - Patient Lens:** Surgical encounter operational context, postop destination,
transport/EVS/bed dependency, safety notes, and timeline.

**A3 - Study:** Utilization, turnover, late starts, cancellation causes, service-line
patterns, and staffing impact in Zephyrus.

**Actions:** Acknowledge delay, assign owner, request transport/EVS escalation, flag
postop bed risk, open Zephyrus deep dive, approve periop-related operational action.

**Relay:** OR nurses receive manager decisions. Transport/EVS receive dependencies.
Bed manager sees postop bed demand. Capacity lead sees periop contribution to strain.
Eddy receives delay cause and action progress.

### 5.12 Staffing Coordinator (`staffing_coordinator`)

**A0 - Glance:** "Where are we below safe coverage?" Show open requests, units below
minimum-safe, critical gaps, role gaps, stat requests, total gap headcount, and coverage
percent.

**A1 - Workspace:** Staffing queue with units at risk, requests, candidate sources,
priority, due time, required role, and dependency to admissions/OR/ED/ICU demand.

**A2 - Drill:** Staffing gap detail with unit, role, minimum-safe threshold, current plan,
demand driver, affected patients/units, and recommended fill options.

**A2P - Patient Lens:** Only when staffing directly blocks a patient movement or assignment.
Show the operational dependency without exposing unnecessary clinical detail.

**A3 - Study:** Staffing-demand mismatch, overtime/agency patterns, fill latency, and
capacity impact.

**Actions:** Create request, fill request, assign source, escalate critical gap,
acknowledge no-fill with reason.

**Relay:** Charge nurse and house supervisor see fill status. Bed manager sees safe-admit
headroom changes. Capacity lead sees risk to forecast/action plans. Eddy receives staffing
state and can adjust placement/surge recommendations.

### 5.13 PI / Quality Lead (`pi_lead`)

**A0 - Glance:** "Which improvement work is tied to today's operational pain?" Show active
PDSA cycles, due stages, high-priority opportunities, recurring barriers, action outcomes,
and process signals from the current shift.

**A1 - Workspace:** Improvement workspace with PDSA cycles, opportunities, recurring
barriers, linked interventions, outcome signals, and active operational patterns worth
capturing.

**A2 - Drill:** Opportunity detail with evidence, affected units/personas, event examples,
current owner, expected impact, and next PDSA step.

**A2P - Patient Lens:** Mostly de-identified or tokenized examples. Full patient context
only if explicitly authorized and necessary for operational quality review.

**A3 - Study:** Process mining, RCA, PDSA detail, opportunity portfolio, and intervention
outcome analysis in Zephyrus.

**Actions:** Advance PDSA stage, assign owner, acknowledge recurring barrier pattern,
create improvement opportunity from a resolved operational event, deep-link to analysis.

**Relay:** Operational teams see when their resolved issues become improvement work.
Executives see aggregate opportunity impact. Eddy learns from resolved barriers and human
outcomes to improve future recommendations.

### 5.14 Executive (`executive`)

**A0 - Glance:** "Is the hospital OK?" Show strain index, four to six hero KPIs, material
breach, confidence, freshness, and morning/evening brief status.

**A1 - Workspace:** Executive brief with situation, plan, impact, confidence, open major
decisions, current owners, and expected time to resolution.

**A2 - Drill:** One-tap drill into the material breach: contributing domains, patient/bed
counts, operational decisions already made, unresolved owners, and next decision point.

**A2P - Patient Lens:** Executives should generally see aggregate or tokenized patient-level
examples, not broad patient detail. Full context is exceptional and policy-gated.

**A3 - Study:** Monday review, trends, quality/throughput outcomes, capital/staffing impact,
and opportunity portfolio in Zephyrus.

**Actions:** Acknowledge brief, request escalation summary, endorse/sponsor surge plan if
policy allows, deep-link to Zephyrus study.

**Relay:** Executive acknowledgement is visible to capacity lead and house supervisor.
Executive requests create actions for owners rather than informal side channels. Eddy sees
acknowledgement, requested summary, and leadership decision.

## 6. Shared Situational Intelligence Model

Every persona home and drill should be assembled from the same intelligence primitives:

- **Status:** canonical status, shape, color token, label, threshold, target, trend, stale
  flag, and generated_at.
- **Scope:** facility, unit, bed, room, service, role, assignment, patient_ref, encounter_ref.
- **Timer:** age, due time, SLA, forecast horizon, and cooldown.
- **Dependency:** waiting_on, owner_role, owner_user, downstream_roles, downstream_patient_refs.
- **Recommendation:** source, action_type, rationale, runner-up, confidence, risk tier,
  stale-data check, and approval requirement.
- **Decision:** human decision, reason, override, approver, timestamp, and audit link.
- **Progress:** assigned, accepted, started, completed, blocked, handed off, resolved.
- **Relay:** affected personas, activity-only recipients, push recipients, and escalation.
- **Provenance:** source table/service/snapshot, query time, freshness, and web link.

## 7. Implementation Roadmap

### H0 - Plan and Contract Reconciliation

- Add the mobile Altitude contract to Hummingbird docs.
- Update OpenAPI with altitude metadata, activity feed, patient context, and drill endpoints.
- Mark old persona-screen plan as shipped-but-incomplete relative to Altitude 2.0.
- Add decision record: patient lens is an A2 leaf, not a fifth Zephyrus altitude.

Acceptance:

- Docs name the same role ids as `Role.swift`.
- OpenAPI has the new endpoint stubs and shared schemas.
- No behavior change yet.

### H1 - Mobile Snapshot and StatusEngine Parity

- Make Hummingbird A0 homes read from the same Cockpit snapshot/status output as Zephyrus
  2.0 where the metric exists.
- Replace controller-local status derivations with StatusEngine-backed values as the
  backend foundation lands.
- Add `status`, `provenance`, and `generated_at` to all persona home payloads.

Acceptance:

- A metric cannot be success on mobile and warning/critical on the cockpit for the same
  as-of time.
- Mobile shows stale/freshness on every aggregate.

### H2 - Patient/Encounter Lens

- Implement `MobilePatientContextService`.
- Add `/patients/{patient_ref}/operational-context`.
- Add iOS `PatientOperationalContextView` and Android equivalent.
- Extend all queue/drill items with `patient_context_ref` when available.

Acceptance:

- Charge nurse, bedside nurse, hospitalist, intensivist, bed manager, OR nurse, transporter,
  and EVS can drill from their relevant item to patient/encounter context.
- PHI is absent from lists and pushes.
- Full patient context requires authorized detail entry.

### H3 - Operational Activity Ledger and Relay

- Add `ops.operational_events` and target/entity/ack tables.
- Wrap all mobile writes so they emit events.
- Add `PersonaRelayPolicy`.
- Replace current For You controller assembly with `MobileForYouService` backed by events,
  dependencies, assignments, and status.
- Add `/activity` and acknowledgement endpoints.

Acceptance:

- Every mobile write records one event with actor, role, entity, patient/encounter ref when
  applicable, prior/current status, and affected personas.
- Role-filtered activity appears for every persona.
- Push is reserved for earned urgency; non-urgent updates stay activity-only.

### H4 - Eddy Full Operational Awareness

- Add `EddyOperationalAwarenessService`.
- Store Eddy-safe context packets keyed by alert, action, patient_context_ref, and event.
- Pre-seed mobile Eddy with current drill/patient context.
- Record all human approve/reject/override/complete decisions back into Eddy learning.

Acceptance:

- Eddy can answer what changed, who acted, what remains blocked, and who needs to act next.
- Eddy can cite the current snapshot and event trail.
- Eddy cannot self-approve or perform safety-critical writes.

### H5 - Persona Completion

- Build missing patient-centered surfaces for bedside nurse, hospitalist, intensivist, house
  supervisor, and richer charge nurse workflows.
- Upgrade existing transport, EVS, bed manager, OR, capacity, staffing, improvement, and
  executive screens to show altitude breadcrumbs, drill explanations, activity, relay, and
  Eddy recommendations.

Acceptance:

- Every role in `Role.catalog` has A0/A1/A2/A2P/A3 behavior documented and implemented.
- Every role can see the relevant action trail created by every other role.

### H6 - Safety, Testing, and Rollout

- Add Feature tests for all new BFF reads and writes.
- Add PHI scrub tests for pushes, list payloads, and Eddy context packets.
- Add fan-out policy tests for each event type.
- Add UI tests for A0 -> A2 -> A2P descent per persona.
- Add stale-data and offline-write tests for safety-critical actions.

Acceptance:

- Mobile BFF conformance suite covers all new endpoints.
- No patient identifiers in notifications, logs, app-switcher snapshots, or activity rows
  where `push_safe=true`.
- App degrades cleanly when offline and blocks safety-critical writes.

## 8. Event Types to Standardize First

Start with the events that connect the whole house:

- `recommendation.created`
- `recommendation.approved`
- `recommendation.rejected`
- `recommendation.overridden`
- `action.assigned`
- `action.started`
- `action.completed`
- `action.blocked`
- `bed_request.created`
- `bed_request.placed`
- `barrier.created`
- `barrier.resolved`
- `transport.claimed`
- `transport.progressed`
- `transport.handoff_completed`
- `evs.claimed`
- `evs.started`
- `evs.completed`
- `staffing.request_created`
- `staffing.request_filled`
- `or.case_delayed`
- `or.case_advanced`
- `huddle.action_created`
- `huddle.action_completed`
- `patient.operational_state_changed`
- `alert.acknowledged`
- `alert.escalated`

Each event must answer: what changed, who changed it, who is affected, what patient/encounter
or unit is involved, what should happen next, and what Eddy should know.

## 9. Validation Scenarios

### Scenario 1 - ED Boarder to Inpatient Bed

1. Executive sees house strain rise at A0.
2. Capacity lead drills into ED boarding alert.
3. Bed manager opens placement request and patient lens.
4. Eddy recommends a bed placement with runner-up.
5. Bed manager approves chosen bed.
6. Charge nurse receives inbound placement readiness task.
7. EVS receives priority bed turn if needed.
8. Transport receives pickup timing.
9. Bedside nurse sees incoming patient context.
10. Eddy sees every action and updates the unresolved dependency list.

### Scenario 2 - ICU Downgrade Unlocks Capacity

1. Intensivist marks downgrade candidate readiness.
2. Bed manager receives candidate movement opportunity.
3. Charge nurse on receiving unit sees inbound dependency.
4. Transport receives move once bed ready.
5. Capacity lead sees ICU bed need improve.
6. Executive sees strain improve only if status crosses threshold.
7. PI lead later sees repeated downgrade delay pattern.

### Scenario 3 - OR Delay Creates Bed and Staffing Pressure

1. OR nurse flags delayed case and reason.
2. Periop manager sees delay drill.
3. Bed manager sees postop bed demand shift.
4. Staffing coordinator sees downstream unit gap risk if delay creates evening demand.
5. Capacity lead sees forecast impact.
6. Eddy revises recommendation and records the human decisions.

### Scenario 4 - Discharge Barrier Resolved

1. Hospitalist resolves medical barrier.
2. Charge nurse sees discharge progression.
3. Bed manager sees bed release forecast improve.
4. EVS is queued for turn after discharge.
5. Transport receives discharge transport when patient-ready.
6. PI lead receives aggregate resolved-barrier pattern, not a page.
7. Eddy learns the barrier resolution outcome.

## 10. Definition of Done

Hummingbird reflects Zephyrus 2.0 when:

- Every persona has the same Altitude descent: A0 -> A1 -> A2 -> A2P -> A3.
- Every persona can drill from top-level status to the authorized patient/encounter or
  operational entity causing the signal.
- Every recommendation, decision, action, status transition, handoff, and progress update
  writes one operational event.
- Every event is visible to the right other personas through activity, For You, push, or
  drill context.
- Eddy ingests the complete operational event trail and can reason over the current house
  state, decisions made, owners, remaining blockers, and recommended next actions.
- Eddy still drafts only; humans approve, reject, override, assign, and complete.
- Mobile and web read the same status and snapshot truth for the same as-of time.
- Patient detail remains role-authorized, PHI-minimized by default, and absent from pushes.
- Desktop-grade study stays in Zephyrus, reached through deep links.
