# 01 — Feature Parity Matrix

This document maps **every data element and function of the Zephyrus web application** to a
Hummingbird treatment. It is the contract that satisfies the requirement *"map to all the
data elements and functionality of the core web application."* Nothing is silently dropped:
each capability is either **delivered on mobile** (with a treatment) or **explicitly
deferred to web** (with a reason).

### Legend

- **Treatment:** `GLANCE` (read at a glance) · `ACT` (do it on mobile) · `NOTIFY`
  (push-worthy) · `WEB` (deep-link to web, not rebuilt). Multiple flags allowed.
- **Backend:** `LIVE` (real endpoint/data today) · `MOCK` (UI exists, data is mock/stub) ·
  `NEW` (net-new backend required) · `WS` (has Reverb websocket today).
- **Phase:** target delivery phase (see [07-roadmap-phasing.md](07-roadmap-phasing.md)).
  `P0` platform foundation · `P1`–`P5` feature waves.
- **Source:** the authoritative web model(s)/endpoint(s); full detail in [research/](../research/).

> **Coverage summary:** 4 core workflows + 6 supporting domains, ~95 models, ~106 API
> endpoints audited. Of the operationally meaningful capabilities, **~60% are GLANCE/ACT on
> mobile**, **~25% are NOTIFY**, and **~30% are WEB-deferred** (analytics/mining/admin),
> with overlap. The ED and PI domains carry the most `NEW` backend because their web UIs are
> largely mock today.

---

## A. RTDC — Real-Time Demand & Capacity  *(the flagship mobile domain)*

Source: [research/02-rtdc.md](../research/02-rtdc.md). Live event-sourced engine + Reverb WS on
public PHI-free channels `unit.{id}`, `hospital.beds`.

### A.1 Data elements

| Data element | Key fields | Treatment | Backend | Phase | Source |
|---|---|---|---|---|---|
| **Unit census** | unit, occupied/total, available, acuity-adjusted *safe capacity*, pending admits/discharges | GLANCE · NOTIFY | LIVE · WS | P1 | `CensusSnapshot`, `Unit`, `/api/rtdc/census` |
| **Hospital bed-need roll-up** | net need/deficit per unit + house total | GLANCE · NOTIFY | LIVE · WS | P1 | `BedMeetingUpdated`, `hospital.beds` |
| **Bed** | bed id, unit, status (available/occupied/dirty/blocked), isolation, gender | GLANCE | LIVE | P1 | `Bed` |
| **Bed request** | patient ref, from/to unit, priority, status, requested_at | GLANCE · ACT · NOTIFY | LIVE | P1 | `BedRequest`, `/api/rtdc/bed-requests` |
| **Bed placement decision** | candidate beds, transparent score, rationale, accept/edit/reject | ACT | LIVE | P1 | `BedPlacementDecision` |
| **Barrier to discharge** | patient ref, type, owner*, status, logged_at, resolved_at | GLANCE · ACT · NOTIFY | LIVE | P1 | `Barrier`, `/api/rtdc/barriers` |
| **Huddle** | unit/service/global, participants, steps, state | GLANCE · ACT | LIVE · WS | P2 | `Huddle`, `/api/rtdc/huddles` |
| **Huddle action item** | text, owner, due_at, status | ACT · NOTIFY | **NEW** (no API/UI today) | P2 | `RtdcPlan` (table only) |
| **Discharge prediction** | patient ref, predicted discharge, confidence/reliability | GLANCE | LIVE | P2 | `RtdcPrediction`, `/api/rtdc/predictions` |
| **Care-journey milestone** | milestone, status, timestamp | GLANCE | LIVE | P2 | `CareJourneyMilestone` |
| **Reconciliation** | predicted vs actual, reliability tile | GLANCE | LIVE | P2 | `RtdcReconciliation`, `/api/rtdc/reconciliation` |
| **Diversion event** | type, start/stop, reason | GLANCE · NOTIFY | **NEW** (model only, no endpoint/broadcast) | P3 | `DiversionEvent` |
| **GMLOS reference** | DRG, geometric mean LOS | GLANCE (context) | LIVE (data-only) | P2 | `GmlosReference` |
| **Discharge readiness / priorities** | scored readiness, priority list | GLANCE · ACT | **NEW** (mock-only) | P3 | mock pages |

### A.2 Functions / mutations

| Function | Treatment | Backend | Phase | Endpoint |
|---|---|---|---|---|
| View live unit/house census | GLANCE | LIVE · WS | P1 | `GET /api/rtdc/census` |
| Create a bed request | ACT | LIVE | P1 | `POST /api/rtdc/bed-requests` |
| Accept / edit / reject a placement decision | ACT | LIVE | P1 | `POST /api/rtdc/bed-requests/{id}/decision` |
| Log / resolve a discharge barrier | ACT · NOTIFY | LIVE | P1 | `POST /api/rtdc/barriers`, `…/{id}/resolve` |
| Run unit-huddle steps (enter discharges/demand, compute bed-need) | ACT | LIVE · WS | P2 | `POST /api/rtdc/huddles/{id}/…` |
| Assign / complete a huddle action item | ACT · NOTIFY | **NEW** | P2 | *(to add)* |
| Acknowledge a capacity/diversion alert | ACT | NEW (push) | P1/P3 | notification action |
| Deep utilization/trend analytics (RTDC Analytics/*) | WEB | MOCK | — | deep-link |

---

## B. Perioperative — OR / Surgical  *(ready data, greenfield mobile surface)*

Source: [research/03-perioperative.md](../research/03-perioperative.md). Rich data model + REST
contract; UIs largely mock; no realtime wired (local `setInterval` only).

### B.1 Data elements

| Data element | Key fields | Treatment | Backend | Phase | Source |
|---|---|---|---|---|---|
| **OR case** | case_id, patient_id (no name), service, surgeon/provider, room, scheduled time, status_id | GLANCE · ACT · NOTIFY | LIVE | P2 | `ORCase`, `/api/cases` |
| **OR log (wheels clock)** | 13 timestamps: periop arrival → preop in/out → **or_in** → anesth/proc start/close/end → **or_out** → PACU in/out | GLANCE | LIVE | P2 | `ORLog` |
| **Case status** | 1 Scheduled · 2 In-Progress · 3 Delayed · 4 Completed · 5 Cancelled | GLANCE · ACT · NOTIFY | LIVE | P2 | `Reference/CaseStatus` |
| **Room status (derived)** | Available / In-Progress / Turnover (from or_in/or_out) | GLANCE · NOTIFY | LIVE | P2 | `Room`, `/api/cases/room-status` |
| **Case timing** | per-phase progress %, variance vs expected | GLANCE · NOTIFY | LIVE | P2 | `CaseTiming` |
| **Case metrics** | turnover, late-start, prime-time flags | GLANCE | LIVE | P3 | `CaseMetrics` |
| **Case safety note** | text, severity, is_overdue (SLA: Crit 15m/High 30m/Med 60m/Low 120m) | GLANCE · ACT · NOTIFY | LIVE | P2 | `CaseSafetyNote` |
| **Care-journey milestone (pre-op)** | H&P, Consent, Labs — acknowledge | ACT · NOTIFY | LIVE | P2 | `CareJourneyMilestone` |
| **Case transport** | ready/overdue, mark complete | ACT · NOTIFY | LIVE | P2 | `CaseTransport` |
| **Case resource / measurement** | resources, intra-op measurements (PHI-gated) | GLANCE | LIVE | P3 | `CaseResource`, `CaseMeasurement` |
| **Block template / utilization** | block owner, allocated vs used, utilization % | GLANCE (tile) · WEB (deep) | LIVE | P3 | `BlockTemplate`, `BlockUtilization` |
| **Provider** | surgeon directory, specialty | GLANCE | LIVE | P2 | `Provider`, `/api/providers` |
| **Reference data** | ASA, CaseClass, CaseType, CancellationReason, PatientClass, Service, Specialty | (lookup) | LIVE | P2 | `Models/Reference/*` |

### B.2 Functions / mutations

| Function | Treatment | Backend | Phase | Endpoint |
|---|---|---|---|---|
| View today's OR board / my cases | GLANCE · NOTIFY | LIVE | P2 | `GET /api/cases/today` |
| View live room-status board | GLANCE · NOTIFY | LIVE | P2 | `GET /api/cases/room-status` |
| Track a single case (progress %, time remaining) | GLANCE · NOTIFY | LIVE | P2 | `GET /api/cases/{id}` |
| Advance case status (Scheduled→In-Progress→Delayed→Completed) | ACT · NOTIFY | LIVE *(fix store bug)* | P2 | `PUT /api/cases/{id}` |
| Create / acknowledge a safety note | ACT · NOTIFY | LIVE | P2 | `POST /api/cases/{id}/safety-notes` |
| Acknowledge a pre-op milestone | ACT · NOTIFY | LIVE | P2 | milestone endpoint |
| Mark case transport ready/complete | ACT · NOTIFY | LIVE | P2 | transport endpoint |
| Block / OR / primetime / turnover / provider / service analytics | WEB | LIVE/MOCK | — | deep-link |
| Utilization forecast / demand / resource planning | WEB | LIVE/MOCK | — | deep-link |

> **Backend fixes flagged by audit (must resolve before P2):** `ORCaseController@store`
> writes a string `status` instead of `status_id`; analytics SQL references
> `actual_start/end_time` columns that don't exist on `or_cases` (route via `orlog` +
> `case_metrics`); reference endpoints filter `is_active` but the column is `active_status`.

---

## C. Ops / AI Agent & Command Center  *(the "approvals on the go" greenfield)*

Source: [research/04-improvement-and-ops.md](../research/04-improvement-and-ops.md).
Production-grade `ops.*` schema; human-in-the-loop; **no mobile-shaped approval surface
exists — #1 opportunity, reuses endpoints verbatim.**

### C.1 Data elements

| Data element | Key fields | Treatment | Backend | Phase | Source |
|---|---|---|---|---|---|
| **Recommendation** | title, risk level, rationale, derived status | GLANCE · NOTIFY | LIVE | P2 | `Ops/Recommendation` |
| **Operational action** | status (draft→approved→assigned→executing→completed / rejected/overridden/expired), expires_at (+8h) | GLANCE · ACT · NOTIFY | LIVE | P2 | `Ops/OperationalAction` |
| **Approval** | "human approval required", decision, reason | ACT · NOTIFY | LIVE | P2 | `Ops/Approval` |
| **Intervention + metrics** | intervention, metric deltas, outcome attribution | GLANCE | LIVE | P3 | `Ops/Intervention`, `InterventionMetric`, `OutcomeAttribution` |
| **Metric definition / value / lineage** | metric trust, provenance, source freshness | GLANCE (trust badge) | LIVE | P3 | `Ops/MetricDefinition`, `MetricValue`, `MetricLineage`, `SourceFreshness` |
| **Agent run / tool call / safety event** | agent activity, safety guards | GLANCE · WEB | LIVE | P3 | `Ops/AgentRun`, `AgentToolCall`, `AgentSafetyEvent` |
| **Simulation scenario / run / result** | scenario, promote-to-recommendation | WEB (author) · GLANCE (result) | LIVE | P4 | `Ops/Simulation*` |
| **Operations graph (node/edge/state)** | operational dependency graph | WEB | LIVE | — | `Ops/OperationsNode/Edge`, `StateSnapshot` |
| **Data-quality finding** | feed stale / qualified metric | GLANCE · NOTIFY | LIVE | P3 | `Ops/DataQualityFinding` |
| **Command Center** | house strain/surge index (0–4), hero KPIs, 24h forecast, drilldown | GLANCE · NOTIFY | LIVE | P2 | `CommandCenterController`, `/api/command-center/*` |
| **Executive Brief** | server-composed situation/plan/impact/confidence | GLANCE · NOTIFY | LIVE | P2 | Command Center |

### C.2 Functions / mutations

| Function | Treatment | Backend | Phase | Endpoint |
|---|---|---|---|---|
| **Approve / reject** a recommendation's action | ACT · NOTIFY ★ | LIVE | P2 | `POST /api/ops/approvals/{id}/decision` |
| Assign / start / complete an action | ACT | LIVE | P2 | `POST /api/ops/actions/{id}/{assign,start,complete}` |
| Override / expire an action | ACT | LIVE | P3 | `…/{override,expire}` |
| Agent-inbox summary (pending/active/assigned/overdue) | GLANCE | LIVE | P2 | `GET /api/ops/…` |
| Run "Capacity Commander" (risk + huddle-ready next actions) | ACT | LIVE | P3 | ops endpoint |
| Read Executive Brief / house strain index / 24h forecast | GLANCE · NOTIFY | LIVE | P2 | `/api/command-center/*` |
| Promote a simulation scenario | WEB | LIVE | — | `…/simulation-scenarios/{id}/promote` |
| Process mining / OCEL root-cause / graph canvas | WEB | LIVE | — | deep-link |

---

## D. Emergency Department  *(glance now, act later — most net-new)*

Source: [research/01-emergency-department.md](../research/01-emergency-department.md). ED UI is
~90% placeholder; **0 ED endpoints, 0 ED mutations**; real signal via Command-Center /
Analytics / Patient-Flow.

| Data element / function | Treatment | Backend | Phase | Source |
|---|---|---|---|---|
| **ED boarding count** (target ≤ 6) | GLANCE · NOTIFY | LIVE (via Command Center) | P3 | `EdVisit` → `/api/command-center/*` |
| **Active ED diversion** | GLANCE · NOTIFY | LIVE | P3 | `DiversionEvent` |
| **High-acuity ESI 1–2 LWBS** (safety breach) | NOTIFY | LIVE | P3 | analytics |
| **LWBS rate %** | GLANCE | LIVE | P3 | analytics |
| **Door-to-provider / ED LOS medians** | GLANCE | LIVE | P3 | analytics |
| **Surge probability** | GLANCE · NOTIFY | LIVE | P3 | Command Center |
| **Boarder dwell > 4h since admit decision** (Joint Commission) | NOTIFY | **NEW** | P4 | needs eval job |
| **Live ED patient board / triage entry / disposition** | ACT | **NEW** (mock/placeholder; no write path) | P4+ | net-new backend |
| ED flow / wait-time / resource analytics | WEB | MOCK | — | deep-link |
| ED acuity / arrival / resource predictions | WEB | MOCK | — | deep-link |

> ED frontline-clinician *actions* (triage, disposition) require net-new backend and are the
> latest wave. ED *glanceable signals* are available now and ship in P3.

---

## E. Process Improvement  *(mostly stub today)*

Source: [research/04-improvement-and-ops.md](../research/04-improvement-and-ops.md).
`DashboardService` PI methods return empty stubs; PDSA write routes referenced by the React
components are **not present in `routes/web.php`**.

| Data element / function | Treatment | Backend | Phase | Source |
|---|---|---|---|---|
| **PDSA cycle** (plan/do/study/act, status, owner) | GLANCE · ACT · NOTIFY | **NEW** (model exists, no working API) | P4 | `PdsaCycle` |
| Advance a PDSA stage; assign owner | ACT · NOTIFY | **NEW** | P4 | net-new |
| **Operational event / barrier trends** | GLANCE | partial | P4 | `OperationalEvent`, `Barrier` |
| Bottleneck analysis / root-cause | WEB | MOCK | — | deep-link |
| Process mining / process layout (4D navigator) | WEB | MOCK | — | deep-link |

---

## F. Transport  *(textbook mobile worker)*

Source: [research/06-supporting-domains-and-api.md](../research/06-supporting-domains-and-api.md).
17-status lifecycle, append-only event log, structured handoff.

| Data element / function | Treatment | Backend | Phase | Endpoint |
|---|---|---|---|---|
| **Transport request** (patient/loc refs, priority, needed_at, status) | GLANCE · NOTIFY | LIVE | P1 | `TransportRequest`, `/api/transport/requests` |
| **Transport event log** (per-status transition) | GLANCE | LIVE | P1 | `TransportEvent` |
| **My queue / available jobs** | GLANCE · NOTIFY | LIVE | P1 | `…/overview` |
| **Claim / accept a job** | ACT · NOTIFY | LIVE | P1 | `POST …/requests/{id}/status` |
| **Progress status** (dispatched→arrived→picked_up→en_route→arrived→handoff→complete) | ACT | LIVE | P1 | `POST …/requests/{id}/status` |
| **Assign** (team/vendor) | ACT | LIVE | P1 | `POST …/requests/{id}/assign` |
| **Structured handoff** (`handoff_to`, summary, documents[], outstanding_risks[]) | ACT · NOTIFY | LIVE | P1 | `POST …/requests/{id}/handoff` |
| **Regional / inter-facility transfer** | GLANCE · ACT | LIVE | P3 | `RegionalTransferController` |
| SLA breach (`needed_at` passed) | NOTIFY | LIVE (derive) | P1 | event/eval |

Statuses (17): `requested → accepted → queued → assigned → dispatched → arrived_pickup →
patient_ready|patient_not_ready → picked_up → en_route → arrived_destination →
handoff_started → handoff_complete → completed` (+ `escalated/canceled/failed`).

---

## G. EVS — Environmental Services / Bed Turns  *(textbook mobile worker)*

Source: [research/06-supporting-domains-and-api.md](../research/06-supporting-domains-and-api.md).
5-status lifecycle; isolation/turn-type drives SOP/PPE.

| Data element / function | Treatment | Backend | Phase | Endpoint |
|---|---|---|---|---|
| **EVS / bed-turn request** (bed, turn_type terminal/isolation/spill, isolation_required, needed_at) | GLANCE · NOTIFY | LIVE | P1 | `EvsRequest`, `/api/evs/requests` |
| **EVS event log** | GLANCE | LIVE | P1 | `EvsEvent` |
| **Next dirty bed / my queue** | GLANCE · NOTIFY | LIVE | P1 | `…/overview` |
| **Claim a bed-turn** | ACT · NOTIFY | LIVE | P1 | `POST …/requests/{id}/status` (→ assigned) |
| **Start clean** (stamps started_at) | ACT | LIVE | P1 | `POST …/requests/{id}/status` (→ in_progress) |
| **Complete** (stamps completed_at, completion_payload) | ACT · NOTIFY | LIVE | P1 | `POST …/requests/{id}/status` (→ completed) |
| **Isolation SOP / PPE prompt** | GLANCE | LIVE (field-driven) | P1 | request fields |
| Bed-turn completed → unblocks placement | NOTIFY (to bed mgr) | LIVE | P1 | event |

Statuses (5 + exceptions): `requested → queued → assigned → in_progress → completed` (+
`escalated/canceled/failed`).

---

## H. Staffing

Source: [research/06-supporting-domains-and-api.md](../research/06-supporting-domains-and-api.md).
6-status lifecycle; demand from `StaffingPlan.gap()` / `belowMinimumSafe()`.

| Data element / function | Treatment | Backend | Phase | Endpoint |
|---|---|---|---|---|
| **Staffing plan** (unit, shift, min-safe, target, actual, gap) | GLANCE · NOTIFY | LIVE | P3 | `StaffingPlan` |
| **Staffing request** (role, count, needed_at, status) | GLANCE · ACT · NOTIFY | LIVE | P3 | `StaffingRequest`, `/api/staffing/*` |
| **Staffing event log** | GLANCE | LIVE | P3 | `StaffingEvent` |
| Below minimum-safe / critical-gap | NOTIFY | LIVE | P3 | derive |
| Create / source / assign / fill a request | ACT · NOTIFY | LIVE | P3 | `POST /api/staffing/requests/…` |
| Unfilled / escalated | NOTIFY | LIVE | P3 | event |

Statuses: `requested → open → sourcing → assigned → filled → completed` (+ `unfilled`).

---

## I. Patient Flow / FHIR / Integration  *(backbone — mostly invisible to mobile)*

Source: [research/06-supporting-domains-and-api.md](../research/06-supporting-domains-and-api.md).
Canonical patient-flow backbone; the only existing "stream" is a demo SSE replay.

| Data element / function | Treatment | Backend | Phase | Source |
|---|---|---|---|---|
| **Flow encounter / event / occupancy snapshot** | (feeds census/board) | LIVE | P1–P2 (indirect) | `PatientFlow/*` |
| **Patient identity** | (PHI — server-side join only) | LIVE | — | `PatientIdentity` |
| **Ambient signal event / adapter** | (feeds flow) | LIVE | — | `PatientFlow/AmbientSignal*` |
| **FHIR resource version / link / bundle cache** | (integration) | LIVE | — | `Fhir/*`, `FhirBundleCache` |
| **Integration source / health / connector** | GLANCE · NOTIFY (admin) | LIVE | P4 | `Integration/*`, `IntegrationHealthController` |
| Connector-down / dead-letter spike | NOTIFY (ops/admin) | LIVE | P4 | `Raw/DeadLetter` |
| PatientFlow SSE stream | (replace with push/WS) | LIVE (demo) | P1 | `PatientFlowStreamController` |
| Facility blueprint / space modeling | WEB | LIVE | — | `Facility/*` |

---

## J. Cross-cutting platform

Source: [research/05-platform-auth-realtime-design.md](../research/05-platform-auth-realtime-design.md).

| Capability | Treatment | Backend | Phase | Notes |
|---|---|---|---|---|
| **Authentication (login)** | ACT | **NEW token layer** | P0 | session-cookie today; add Sanctum/OIDC+PKCE — *additive* to locked auth rules |
| **Temp-password / must_change_password flow** | ACT | LIVE (do not modify) | P0 | honor verbatim; surface forced change on mobile |
| **Biometric unlock + auto-lock** | ACT | client | P0 | FaceID/TouchID/BiometricPrompt |
| **Role / workflow preference** | (drives home) | LIVE | P0 | `workflow_preference` (6 values) + `roles[]` |
| **Workflow switcher** | ACT | LIVE | P1 | any user may switch (preference, not gate) — mobile mirrors |
| **Profile & preferences** | ACT | LIVE + NEW (notif prefs) | P1 | theme, quiet hours, notification tiers |
| **Real-time transport** | (infra) | LIVE WS + **NEW push** | P0 | Reverb foreground; APNs/FCM net-new |
| **Design tokens** | (parity) | new pipeline | P0 | DTCG → Compose/SwiftUI/CSS |
| **Global search / directory** (units, beds, rooms, providers) | GLANCE | LIVE | P2 | ⌘K on web → search screen on mobile |
| **Admin (users, auth providers)** | WEB | LIVE | — | self-service profile only on mobile |

---

## K. Coverage assertion

Every model enumerated in the audit falls into one of the rows above or its domain's
"deep-link to web" bucket. The domains with the most **`NEW`** backend — and therefore the
latest phases — are **ED actions**, **PI/PDSA**, **RTDC huddle action-items**, and **RTDC
discharge-readiness**, all of which are *mock/stub on the web today* and so represent
net-new product, not a mobile gap. The **GLANCE/ACT** core that ships first (RTDC beds &
barriers, Ops approvals, Transport, EVS, the OR board, Command Center) is built entirely on
**existing live endpoints**, which is what makes an aggressive P1/P2 feasible.
