# Virtual Rounds, 4D Viewer, and Eddy Implementation Plan

**Date:** 2026-07-11

**Status:** Proposed implementation plan

**Scope:** Add asynchronous and hybrid multidisciplinary rounds to Zephyrus at patient, unit, department, and service-line scope; embed the workflow in the Patient Flow 4D Navigator; and let Eddy conduct a governed, resumable virtual walkthrough of authorized patients.

**Primary product reference:** [Q-Rounds features](https://www.q-rounds.com/product/features), [integrations](https://www.q-rounds.com/product/integrations), and [technology](https://www.q-rounds.com/product/technology).
**Zephyrus companions:** [Patient Flow 4D integration](./2026-06-25-patient-flow-4d-navigator-integration.md), [service-line/location deployment](./2026-07-04-service-line-location-deployment-implementation.md), and [Patient Flow devlog](../../devlog/DEVLOG-patient-flow-4d-navigator-2026-06-25.md).

---

## 1. Executive Decision

Build **Virtual Rounds** as an additive clinical-coordination domain, not as state embedded in the Three.js scene and not as an extension of Eddy chat history.

The product has three coordinated surfaces:

1. **Rounds Board:** the operational queue and asynchronous contribution workspace for one unit, department, or service line.
2. **4D Rounds Overlay:** patient and location status projected into the existing Patient Flow 4D Navigator, with a guided itinerary and drill-in workspace.
3. **Eddy Rounds Tour:** a human-started, scope-limited batch evaluation that visits eligible patients, summarizes current evidence, detects missing round inputs, and drafts recommendations for human review.

The core rules are:

- A round is a durable, versioned workflow. It can span minutes or shifts and does not require simultaneous attendance.
- Every clinical statement retains author, role, source, timestamp, and supersession history.
- Unit, department, and service line are distinct scopes. Zephyrus must never treat department and service line as synonyms.
- Queue priority and completion estimates are explainable, overridable, and auditable.
- Eddy can read, summarize, compare, and draft. It cannot sign a clinician's contribution, mark a patient rounded, contact a family, call an interpreter, invite a consultant, or write back to the EHR without the applicable human approval or policy gate.
- Patient access is enforced server-side through the existing persona lens and assignment model. The 4D scene receives only the permitted projection.
- External notifications are PHI-free doorbells. The recipient opens an authenticated, authorized experience to see detail.

This design preserves Q-Rounds' strongest idea, a shared and continuously updated queue, while extending it into a multi-author asynchronous workflow and a hospital-scale digital-twin experience.

---

## 2. Q-Rounds Product Findings

### 2.1 What the current product does

Q-Rounds organizes its workflow into three stages:

| Stage           | Verified capability                                                                                                                       | Zephyrus implication                                                                                       |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Build the queue | Pull the patient list from the EHR; mark high-priority and discharge-ready patients; estimate completion time                             | Create a source-aware census projection, explainable prioritization, queue versions, and ETA windows       |
| Share the queue | Notify families and nurses; collect family RSVPs; publish queue/schedule updates; coordinate interpreter needs                            | Add participant/invitation state, recipient consent, channel adapters, retries, and delivery audit         |
| Arrive together | Notify the next nurse; support in-person or virtual family attendance; connect interpreters; invite consultants; mark activity in the EHR | Support hybrid encounters and remote joins without making simultaneous presence mandatory for async rounds |

The product's published integration surface includes Epic and Cerner EHRs; Ascom, Vocera, and TigerConnect nurse communication platforms; in-person, virtual, and call-center interpreter workflows; and secure text, conference call, MyChart, hospital-app, in-room module, and survey channels. Its technology framing combines clinical decision support, patient engagement, and telehealth.

### 2.2 What should be adopted

- A single shared queue with real-time changes.
- Explicit high-priority and discharge-ready signals.
- Estimated completion time and patient/family time transparency.
- Nurse, family, interpreter, and consultant coordination from the same workflow.
- EHR-derived patient, room, care-team, language, and contact context.
- EHR writeback of round status where the deployment supports it.
- Remote attendance as a first-class option.

### 2.3 What Zephyrus must add

The public Q-Rounds material describes a coordinated queue centered on the rounding team's arrival. Zephyrus' requested workflow is broader:

- independent contributions by any authorized care-team member;
- round continuity across shifts and time zones;
- completion policies that can require or waive role-specific inputs;
- department/service-line rounds spanning multiple units and floors;
- a longitudinal audit of changed assessments and unresolved questions;
- a 4D facility overlay and guided traversal;
- Eddy-assisted evaluation of a patient cohort under human governance;
- operational task and barrier closure after the round.

The implementation should therefore treat Q-Rounds as a product benchmark, not a data model to copy.

### 2.4 Claims and validation boundary

Q-Rounds publishes benefits such as greater nurse presence and better family coordination. These are vendor claims until Zephyrus validates them in its own deployments. The Zephyrus rollout must define baseline and post-launch measures, avoid importing marketing claims into acceptance criteria, and obtain clinical, privacy, and integration sign-off before production activation.

---

## 3. Product Definition

### 3.1 Round modes

| Mode            | Definition                                                                                         | Typical use                                                            |
| --------------- | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `async`         | Participants submit role-specific inputs within a time window; no simultaneous meeting is required | overnight preparation, distributed specialty input, cross-shift review |
| `live`          | A leader advances a shared queue and participants join in person or remotely                       | traditional bedside rounds with time transparency                      |
| `hybrid`        | Async preparation precedes a short synchronous review; missing roles can respond later             | daily multidisciplinary rounds                                         |
| `eddy_assisted` | A human starts a governed Eddy tour over an eligible cohort; outputs are drafts                    | house-wide gap scan or service-line review                             |

### 3.2 Scope hierarchy

The supported scope grammar should become:

```text
facility:{facility_key}
floor:{floor_number}
unit:{unit_id|abbreviation}
department:{department_key}
service_line:{service_line_code}
patient:{opaque_patient_context_ref}
```

MVP supports `unit`, `service_line`, and `patient`. `department` becomes generally available only after a governed department master and department-to-unit/service-line crosswalk exist.

Rules:

- A unit is a physical/operational assignment.
- A department is an organizational ownership structure sourced from HR/EHR master data.
- A service line is a clinical portfolio that can cross departments, units, facilities, and care settings.
- A patient may appear in multiple authorized scope views, but a round contribution belongs to one canonical patient-round record and is projected into each view to prevent duplicate documentation.

### 3.3 Personas

- Attending/hospitalist: establishes plan, disposition, and clinical priorities.
- Bedside nurse: submits overnight events, safety concerns, education needs, and family availability.
- Charge nurse/unit leader: owns queue readiness, missing inputs, escalation, and completion policy.
- Pharmacist: reconciles medications and discharge medication barriers.
- Case manager/social worker: disposition, authorization, placement, transport, and social barriers.
- Therapist/dietitian/other allied health: discipline-specific readiness and tasks.
- Consultant: responds to a bounded question without gaining broader cohort access.
- Interpreter coordinator/interpreter: accepts language-service requests and joins a scheduled segment.
- Patient/family/representative: RSVPs, provides preferences/questions, joins remotely, and receives approved summaries.
- Department/service-line leader: views aggregate progress and authorized patient detail.
- House supervisor/bed manager: views cross-unit completion, discharge opportunity, and operational barriers.
- Eddy: non-human assistant with a separate service identity, explicit scope, read tools, and draft-only action abilities.

### 3.4 Core user journeys

#### Unit async round

1. A charge nurse starts today's run from the active unit census.
2. Zephyrus snapshots the eligible encounters, required contributor roles, priority reasons, and source freshness.
3. Each care-team member sees their incomplete patients and submits structured contributions.
4. The board shows missing inputs, conflicts, stale information, and actionable barriers.
5. A round leader reviews the synthesized patient summary, resolves or assigns tasks, and marks the patient rounded.
6. The run closes when its configured completion policy is met or an authorized leader records an exception.

#### Service-line async round

1. A service-line leader starts a run across mapped units and spaces.
2. The cohort is deduplicated by encounter and grouped by facility, unit, and phase of care.
3. Local unit contributions remain authoritative; the service-line run adds specialty questions, decisions, and cross-unit tasks.
4. Leaders see aggregate readiness and may drill into only the patients allowed by their role and assignment.

#### Hybrid family-centered round

1. Family consent, channel, language, attendance mode, and time constraints are confirmed.
2. The queue publishes a time window, not a false exact promise.
3. PHI-free doorbells notify the nurse/family/interpreter as the window changes.
4. The live segment records attendance, start/end time, questions, and approved follow-up.
5. External writeback occurs through the deployment connector and is auditable.

#### Eddy tour

1. An authorized user selects unit/service-line scope and presses **Start Eddy tour**.
2. Zephyrus freezes an eligibility manifest and records the actor, lens, data cutoff, policy, and maximum patient count.
3. The 4D camera follows the itinerary while the backend evaluates one patient at a time.
4. Eddy emits structured evidence, uncertainty, missing inputs, and draft recommendations.
5. Human reviewers accept, edit, reject, or defer each result. No recommendation silently changes the round.
6. The tour can pause, resume from a checkpoint, or stop when data becomes stale or access changes.

---

## 4. Current Zephyrus Baseline

### 4.1 Reusable capabilities already in the repo

| Need                  | Existing substrate                                                                           | Reuse decision                                                                            |
| --------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| 4D hospital model     | `resources/js/Components/PatientFlowNavigator/**`, `NavigatorScene.ts`, Three.js GLB runtime | Add a rounds layer and guided camera API; do not fork the renderer                        |
| Patient flow identity | `flow_core.patient_identities`, `flow_core.encounters`, `flow_core.flow_events`              | Reference opaque patient/encounter keys; do not duplicate MRNs or raw clinical payloads   |
| Location mapping      | `hosp_space.facility_spaces`, operational maps, units/beds, service-line bridge              | Resolve every round target to canonical facility space, unit, and service line            |
| Service-line taxonomy | `hosp_ref.service_lines`, `config/hospital/service-lines.php`                                | Use canonical codes and display metadata                                                  |
| Staff membership      | `prod.user_unit`, `hosp_org.staff_members`, effective-dated `hosp_org.staff_assignments`     | Drive eligibility, role requirements, and notification routing                            |
| Patient authorization | `FlowLensService`, `MobilePatientContextService`, `EnforceFlowLens`                          | Extend scope and rounds policies server-side; never depend on client hiding               |
| Push                  | `PushNotifier`, APNs implementation, mobile device registry                                  | Reuse for authenticated staff doorbells; add FCM and external-channel adapters separately |
| Eddy                  | global dock, context envelope, action catalog, recommendation/action/approval FSM            | Add rounds tools and evaluations; retain draft-only human approval                        |
| Realtime              | patient-flow SSE, Echo/Pusher dependencies, cockpit broadcast patterns                       | Publish invalidation events and refetch projections; avoid PHI in broadcast payloads      |
| API/client validation | Laravel feature/unit tests, Vitest, Playwright                                               | Add contract, state-machine, authorization, concurrency, and visual tests                 |

### 4.2 Gaps to close

- No rounds schema, models, state machine, templates, completion policies, contributions, attendance, or delivery ledger.
- `FlowLensService` supports house/floor/unit/patient, not department or service-line scope.
- The current patient-flow aggregate endpoints are broadly authenticated; Virtual Rounds requires stricter patient/cohort authorization.
- No generic SMS/family messaging, nurse-platform, interpreter, consultant-invite, or telehealth connector interface exists.
- Existing push is staff-oriented and iOS-first. It cannot be treated as the family communication channel.
- Eddy chat accepts page context but has no resumable cohort job, per-patient isolation, evaluation ledger, or tour checkpoint.
- The operations graph contains facility containment and action governance, but not a walking-route graph. Initial tours must use stable location ordering/centroids and must not claim shortest-path routing.
- A normalized service-line registry exists, but a general department master is not yet present.
- EHR writeback is deployment-specific; the plan must not imply that Epic/Cerner integration exists merely because Q-Rounds advertises it.

---

## 5. Target Architecture

```text
EHR / ADT / FHIR / staffing / communication vendors
                    |
          raw + integration schemas
                    |
      canonical encounter, care team, location,
      language, contact-consent, and readiness adapters
                    |
             rounds domain service
       +------------+-------------+
       |            |             |
  queue engine  contribution  notification/outbox
       |         state machine         |
       +------------+------------------+
                    |
          rounds read projection/cache
          /          |              \
  Rounds Board   4D overlay      Eddy tour runner
                       |              |
                 NavigatorScene   draft evaluations
                                      |
                          Eddy governance/approval FSM
```

### 5.1 Ownership boundaries

- `flow_core` remains the deidentified encounter and movement projection.
- `hosp_ref`, `hosp_space`, and `hosp_org` remain taxonomy, place, and staffing truth.
- New `rounds` schema owns workflow state and its audit history.
- `integration` owns source mappings, inbound normalization, and outbound delivery/outbox concerns.
- `ops` owns approved operational actions and lifecycle state after a round creates or links a task.
- The frontend owns display and interaction state only. It is never the source of completion, ranking, authorization, or communication truth.

### 5.2 Command/query split

Use transactional command services for all mutations and a denormalized read projection for boards and 4D overlays.

- Commands use row locking, expected version, idempotency key, and audit metadata.
- Queries return a lens-clamped scope projection with one `generated_at`, `source_cutoff_at`, and `version` envelope.
- Realtime events contain only scope identifiers and versions. Clients refetch authorized data.
- Long-running fan-out and Eddy tours run on queues and persist checkpoints.

---

## 6. Domain Model

Create additive migrations using `SafeMigration`. Use PostgreSQL constraints and indexes; destructive rollback remains local-only.

### 6.1 Tables

| Table                                                  | Purpose                                                     | Key fields                                                                                                                                                                                                     |
| ------------------------------------------------------ | ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `rounds.templates`                                     | Versioned round policy by scope and service                 | `template_uuid`, `name`, `scope_types`, `mode`, `required_roles`, `completion_policy`, `priority_policy`, `eta_policy`, `version`, `active`                                                                    |
| `rounds.runs`                                          | One execution of a template                                 | `run_uuid`, `template_uuid`, `facility_key`, `scope_type`, `scope_key`, `mode`, `status`, `planned_start_at`, `window_end_at`, `started_at`, `completed_at`, `queue_version`, `source_cutoff_at`, `created_by` |
| `rounds.patients`                                      | Canonical encounter participation in a run                  | `round_patient_uuid`, `run_uuid`, `encounter_ref`, `patient_ref`, snapshot unit/space/service line, `status`, priority fields, ETA window, `version`, `rounded_by`, `rounded_at`                               |
| `rounds.participants`                                  | Human/service participants and requirements                 | `participant_uuid`, `run_uuid`, optional `round_patient_uuid`, `user_id` or external actor ref, `role_code`, `required`, `status`, `invited_at`, `responded_at`, `joined_at`                                   |
| `rounds.contributions`                                 | Versioned role-specific clinical/operational input          | `contribution_uuid`, `round_patient_uuid`, `author_user_id`, `author_role`, `section_code`, `status`, `structured_data`, `summary`, `source_refs`, `authored_at`, `supersedes_uuid`, `version`                 |
| `rounds.questions`                                     | Questions for patient/family or a discipline                | target actor/role, text, status, response contribution, due time, provenance                                                                                                                                   |
| `rounds.tasks`                                         | Round-local follow-up and bridge to governed ops/EHR tasks  | owner, category, due time, status, `ops_action_uuid`, external task ref, provenance                                                                                                                            |
| `rounds.attendance`                                    | Live/hybrid attendance segments                             | actor/role/mode, invite, join/leave times, connection state; no media payload                                                                                                                                  |
| `rounds.contact_preferences`                           | Consent snapshot and tokenized external recipient reference | related-person ref, relationship, permitted channels, language, timezone, consent source/version, expiry; no plaintext contact in app logs                                                                     |
| `rounds.notifications`                                 | Intent and delivery audit                                   | event type, recipient token/ref, channel, template/version, PHI classification, state, attempt count, vendor message id, sent/delivered/failed timestamps                                                      |
| `rounds.interpreter_requests`                          | Language-service coordination                               | language, mode, urgency, requested window, vendor ref, status, accepted/joined/completed timestamps                                                                                                            |
| `rounds.consult_requests`                              | Bounded specialty invitation                                | specialty/service line, question, invited actor/team, minimum access grant, due time, status, response contribution                                                                                            |
| `rounds.evaluations`                                   | Eddy output for one patient and cutoff                      | `evaluation_uuid`, `tour_uuid`, `round_patient_uuid`, evidence refs, data cutoff, model/policy versions, structured result, uncertainty, status, reviewer decision                                             |
| `rounds.tours`                                         | Resumable Eddy cohort job                                   | actor, scope, lens snapshot, eligibility manifest hash, status, cursor, counts, cutoff, policy/model versions, stop reason                                                                                     |
| `rounds.events`                                        | Append-only domain audit/event stream                       | `event_uuid`, aggregate type/id/version, actor, event type, PHI-safe metadata, `occurred_at`, correlation/idempotency keys                                                                                     |
| `integration.outbox_messages` or round-specific outbox | Reliable external writeback                                 | destination, message type, aggregate/version, payload encryption ref, idempotency key, state, attempts, next attempt                                                                                           |

### 6.2 Data rules

- `encounter_ref` is the round target. A patient can have multiple encounters and must not be merged across active episodes by display name.
- Patient display identifiers are returned only after lens authorization. Broadcast and notification payloads use opaque IDs.
- Snapshot `unit_id`, `facility_space_id`, and `service_line_code` on enrollment for historical truth, while also retaining current-location resolution for live display.
- Contributions are immutable after submission. Corrections create a superseding row.
- Structured clinical data must use an allowlisted schema per `section_code`; arbitrary blobs do not become a shadow medical record.
- A run cannot be completed with patients in disallowed states unless an authorized exception with reason is recorded.
- External contacts are tokenized or connector-owned. Plain phone numbers must not be copied into round events, queue payloads, logs, or push data.
- Every outbound write uses an outbox record in the same transaction as the domain change.
- Use partial unique indexes for one active patient record per encounter/run, one active primary contribution per author/role/section, and one active Eddy evaluation per patient/cutoff/policy.

### 6.3 State machines

**Run**

```text
draft -> scheduled -> active <-> paused -> closing -> completed
   |         |          |          |          |
   +---------+----------+----------+--------> cancelled
```

**Round patient**

```text
queued -> in_progress -> awaiting_input -> ready_for_review -> rounded
   |          |               |                  |
   +----------+---------------+---------------> deferred
   +------------------------------------------> skipped
rounded -> reopened -> in_progress
```

**Contribution**

```text
draft -> submitted -> superseded
   |          |
   +-------> withdrawn
```

**Notification/invite**

```text
pending -> queued -> sent -> delivered -> acknowledged
                 \-> failed -> retrying -> dead_letter
```

Transitions belong in PHP services with policy checks and transaction tests, not in controllers.

### 6.4 Completion policies

Templates define:

- required roles or role groups;
- whether one contribution can satisfy multiple roles;
- required sections per role;
- hard versus soft missing-input rules;
- freshness windows;
- required leader attestation;
- family/interpreter invitation requirements when applicable;
- whether open tasks/barriers block completion;
- exception roles and mandatory exception reasons.

The UI must distinguish **contribution complete**, **patient ready for review**, **patient rounded**, and **run complete**. A progress percentage must never obscure which requirement is missing.

---

## 7. Queue, Priority, and Completion-Time Engine

### 7.1 Queue construction

`RoundCohortBuilder` resolves eligible active encounters from the selected scope at one source cutoff. It must:

1. authorize the initiating actor and requested scope;
2. resolve units/spaces using `hosp_space` and service lines using the canonical registry/bridge;
3. deduplicate by encounter;
4. record inclusion/exclusion reasons;
5. snapshot source freshness and data-quality warnings;
6. add required role slots from the template and care-team/staff assignments;
7. create the initial ordered queue in one transaction.

Admissions, transfers, and discharges after creation produce explicit cohort suggestions. They do not silently rewrite a clinical run already in progress.

### 7.2 Explainable priority

Phase 1 uses deterministic rules. Each score component emits a reason code, source, value, weight, freshness, and human-readable explanation.

Recommended ordering bands:

1. Human-pinned urgent patients.
2. Time-critical clinical or operational signals explicitly approved for use.
3. Discharge-ready patients with unresolved blockers.
4. Coordination constraints: interpreter/family/consultant availability windows.
5. Missing required input or overdue response.
6. Routine patients, optimized by location cluster and expected duration.

Guardrails:

- Never infer discharge readiness solely from bed pressure or length of stay.
- Never describe a ranking as a diagnosis or treatment recommendation.
- Display every priority reason and the data timestamp.
- Require a reason for manual pin/unpin and preserve the prior order in events.
- Fall back to stable room/order sorting when inputs are stale or rules fail.
- A later learned ranker runs in shadow mode first and can never remove manual control.

### 7.3 ETA model

Start with transparent duration estimates:

```text
patient duration = template default
                 + complexity adjustment
                 + interpreter adjustment
                 + family/consultant adjustment
                 + unresolved-input adjustment

patient window = cumulative preceding durations + uncertainty buffer
run completion = start + sum(patient durations) + planned breaks
```

After sufficient local observations, replace static adjustments with per-unit/template exponentially weighted estimates. Report ranges such as `10:20-10:35`, not false precision. Recalculate on queue changes, but damp notifications so small shifts do not generate noise.

Version every queue. Clients submit `expected_queue_version`; conflicts return `409` with the current projection.

---

## 8. 4D Viewer Experience

### 8.1 New layers

Extend `NavigatorScene` with a separate `RoundsLayer` that receives a prepared projection:

- patient token ring for round state;
- location/unit completion heat;
- high-priority and discharge-ready glyphs;
- missing-input marker;
- family/interpreter/consultant coordination status;
- Eddy evaluation state and review-needed marker;
- current guided-tour stop and remaining itinerary;
- optional path/trail between ordered locations.

Do not overload existing clinical/status colors. Use shape plus color, honor reduced motion, and retain existing patient-depth redaction.

### 8.2 Interaction model

- Add a segmented view control: `Flow | Rounds`.
- Add scope and run selectors in the rounds mode.
- Selecting a patient opens a right-side round workspace, not a nested card over the canvas.
- The workspace shows queue reasons, ETA window, completion requirements, contributions, unresolved questions, tasks, attendance, and Eddy draft.
- Unit/service-line progress appears as full-width operational strips and scene overlays.
- Queue reordering can occur in a dense board/list view; the 3D model remains a spatial situational-awareness tool, not the only way to manage work.
- The current camera, floor, and filters persist when the rounds layer updates.
- Deep links include `run`, `scope`, `round_patient`, and time context, but opaque identifiers only.

### 8.3 Guided tour API

Add methods to `NavigatorScene`:

```ts
focusRoundPatient(roundPatientId: string): Promise<void>
flyToFacilitySpace(spaceId: number, options: TourCameraOptions): Promise<void>
setRoundsProjection(projection: RoundsSceneProjection): void
setTourStop(stop: TourStop | null): void
pauseTourAnimation(): void
resumeTourAnimation(): void
```

Phase 1 camera routing uses stable floor/unit/location ordering and known centroids. A true walkable path requires a navigable graph derived from CAD/IFC corridors, stairs, and elevators and is a later workstream. Label the initial feature **guided tour**, not shortest route or indoor navigation.

### 8.4 Mobile and accessibility

- Provide the complete workflow in board/list form when WebGL is unavailable.
- Do not require camera animation to understand progress.
- Announce tour stop changes in an accessible region only when the user starts the tour.
- Keep keyboard navigation, focus restoration, pause controls, and reduced-motion behavior.
- Test desktop, tablet, and phone layouts; the canvas and workspace must never overlap incoherently.

---

## 9. Eddy Virtual Care Assistant

### 9.1 Operating contract

Eddy's rounds identity receives:

- the initiating user's authorized scope and effective patient depth;
- an immutable eligibility manifest;
- one patient context at a time;
- a data cutoff and freshness envelope;
- an allowlisted evaluation schema;
- read tools plus draft-only proposal tools;
- a patient and run limit;
- a cancellation token and checkpoint.

It does not receive an unrestricted house dump or retain patient A's clinical context while evaluating patient B.

### 9.2 Tour pipeline

```text
authorize -> freeze cohort -> build itinerary -> checkpoint
    -> for each patient:
       reauthorize -> fetch fresh bounded context -> evaluate
       -> validate structured output -> persist draft -> emit progress
    -> reconcile coverage -> human review -> close/stop
```

`EddyRoundTourJob` must be idempotent. A retry resumes after the last committed cursor and never duplicates an evaluation.

### 9.3 Structured evaluation output

Each evaluation contains:

- patient/run references;
- data cutoff, source freshness, and missing sources;
- completion requirements satisfied/missing;
- concise current-state synthesis with evidence references;
- discharge-readiness evidence and blockers, without declaring a discharge order;
- care-team questions and unresolved conflicts;
- family/language/interpreter coordination gaps;
- proposed round tasks or barrier flags;
- uncertainty and reasons the assistant abstained;
- model, prompt/policy, tool, and knowledge versions;
- safety flags and reviewer state.

Every factual statement that can affect work must point to source refs available to the reviewer. Unsupported output is rejected or marked as ungrounded before display.

### 9.4 Human governance

- Starting a tour is a privileged action with a visible scope and patient count.
- Results are `draft`; a clinician or operational leader reviews each item.
- Accepted operational proposals use the existing `Recommendation -> OperationalAction -> Approval` lifecycle.
- Extend `EddyActionService::CATALOG` with narrow actions such as `propose_round_task`, `request_round_input`, and `flag_round_conflict`; keep external communication actions outside auto-proposal until policy exists.
- Eddy never supplies another person's required contribution.
- Eddy never changes priority without showing the proposed score delta and receiving a human decision.
- A reviewer can bulk reject, but bulk accept is limited to low-risk administrative gaps and requires policy approval.
- Access revocation, encounter discharge, stale cutoff, tool failure, or safety validation failure pauses the tour.

### 9.5 Scene synchronization

Backend progress is authoritative. The browser listens for PHI-free `{tour_uuid, version, cursor, status}` updates, refetches the authorized stop, animates to it, and opens the corresponding workspace. Closing the browser does not stop the job; an explicit stop command does.

---

## 10. API and Event Contracts

### 10.1 Web API

Add session-authenticated, policy-protected routes under `/api/rounds`:

```text
GET    /templates
GET    /scopes
GET    /runs?scope=&status=&date=
POST   /runs
GET    /runs/{run}
POST   /runs/{run}/start
POST   /runs/{run}/pause
POST   /runs/{run}/resume
POST   /runs/{run}/complete
POST   /runs/{run}/cancel
GET    /runs/{run}/board
GET    /runs/{run}/scene
POST   /runs/{run}/cohort/reconcile
PATCH  /runs/{run}/queue

GET    /patients/{roundPatient}
POST   /patients/{roundPatient}/contributions
POST   /patients/{roundPatient}/questions
POST   /patients/{roundPatient}/tasks
POST   /patients/{roundPatient}/mark-ready
POST   /patients/{roundPatient}/complete
POST   /patients/{roundPatient}/reopen

POST   /patients/{roundPatient}/family-invites
POST   /patients/{roundPatient}/interpreter-requests
POST   /patients/{roundPatient}/consult-requests
POST   /invites/{invite}/respond

POST   /runs/{run}/eddy-tours
GET    /eddy-tours/{tour}
POST   /eddy-tours/{tour}/pause
POST   /eddy-tours/{tour}/resume
POST   /eddy-tours/{tour}/stop
POST   /evaluations/{evaluation}/decision
```

Mutations require `Idempotency-Key` and `If-Match`/expected version. Responses use a consistent envelope:

```json
{
    "data": {},
    "meta": {
        "version": 12,
        "generated_at": "2026-07-11T14:00:00Z",
        "source_cutoff_at": "2026-07-11T13:59:30Z",
        "scope": "unit:42",
        "lens": "charge_nurse"
    }
}
```

### 10.2 Public recipient endpoints

Family/representative links must use a separate minimal surface:

```text
GET  /rounds/guest/{single_use_token}
POST /rounds/guest/{single_use_token}/rsvp
POST /rounds/guest/{single_use_token}/questions
```

Controls: opaque single-use or rotating token, short expiry, consent check, rate limit, device/session binding where practical, generic error responses, minimum disclosed data, revocation, and complete access audit. No guest endpoint exposes the unit board or other patients.

### 10.3 Realtime events

Use reload-ping events:

- `RoundRunUpdated`
- `RoundPatientUpdated`
- `RoundQueueUpdated`
- `RoundNotificationUpdated`
- `EddyRoundTourProgressed`

Channel authorization mirrors scope policy. Payloads contain opaque IDs, version, event category, and timestamps only.

---

## 11. Integration Architecture

### 11.1 Connector interfaces

Create deployment adapters behind stable contracts:

```php
interface RoundCensusSource {}
interface RoundCareTeamSource {}
interface RoundReadinessSource {}
interface RoundEhrWriteback {}
interface RoundNotificationChannel {}
interface InterpreterServiceConnector {}
interface TelehealthConnector {}
interface RelatedPersonConsentSource {}
```

Each connector declares capabilities, supported event types, PHI policy, idempotency behavior, health state, and last successful synchronization. The UI must expose unavailable/degraded integrations rather than pretending a button worked.

### 11.2 EHR mapping

Use the deployment's supported FHIR/HL7 surface; do not hard-code a single vendor workflow.

| Zephyrus concept          | Preferred standard mapping                                                      | Notes                                                                   |
| ------------------------- | ------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Active patient/location   | `Encounter`, `Patient`, `Location` plus ADT events                              | Existing flow normalization remains the location spine                  |
| Care team/consultants     | `CareTeam`, `Practitioner`, `PractitionerRole`                                  | Reconcile with `hosp_org.staff_assignments`; retain source precedence   |
| Family/representative     | `RelatedPerson`, patient contact, consent source                                | Store connector refs and consent snapshot, not uncontrolled copies      |
| Language/interpreter need | Patient/RelatedPerson communication/language extensions and deployment mappings | Family-requested need can supplement but not silently overwrite the EHR |
| Round work item           | `Task` or vendor workflow object                                                | Status and owner mapping must be deployment-tested                      |
| Invitation/message audit  | `CommunicationRequest`/`Communication`                                          | Record intent and outcome; PHI handling follows connector policy        |
| Scheduled live segment    | `Appointment`/`AppointmentResponse` when appropriate                            | Pure async contributions are not appointments                           |
| Round note/writeback      | deployment-approved document/note/flowsheet mapping                             | Requires clinical documentation governance and author/signature rules   |

FHIR R4 describes `CareTeam` for planned participants, `Task` for tracked work, `Communication` for recorded information transfer, and `Appointment` for planned meetings that may be in-person or remote. Use those semantics rather than forcing all round activity into one resource: [CareTeam](https://www.hl7.org/fhir/R4/careteam.html), [Task](https://www.hl7.org/fhir/R4/task.html), [Communication](https://www.hl7.org/fhir/R4/communication.html), and [Appointment](https://hl7.org/fhir/R4/appointment.html).

### 11.3 Nurse communication platforms

- Route recipients from active unit assignments and care-team data, not free-text nurse names.
- Support platform capability levels: push/doorbell, secure text, in-app deep link, voice call.
- Introduce vendor adapters for Ascom, Vocera, and TigerConnect only when credentials and tested APIs are available.
- Deduplicate notifications by event/recipient/channel/queue version.
- Add quiet periods, escalation, acknowledgment, and channel preference.
- Reuse `PushNotifier` for Hummingbird staff devices but add Android FCM before claiming cross-platform mobile coverage.

### 11.4 Interpreter services

- Resolve documented language need and family-requested language separately.
- Support in-person, virtual, and call-center requests.
- Send requested window, modality, location token, and join instructions through a connector.
- Track accepted, en route/ready, joined, completed, cancelled, and failed states.
- Never treat machine translation as a substitute for a qualified interpreter in clinical rounds.

### 11.5 Patient/family communication

- Require verified relationship, consent, channel preference, language, and expiry.
- SMS contains generic timing language and a secure link, not diagnosis, room, or clinical detail.
- Secure experience supports RSVP, attendance mode, language request, questions, and approved follow-up.
- Telehealth join links are short-lived and connector-owned; secrets are encrypted and never logged.
- Queue changes use notification thresholds and time windows to prevent message fatigue.
- Surveys are a later, separate consented workflow and must not block round completion.

### 11.6 Reliability

- Transactional outbox for all external side effects.
- Idempotency key per vendor operation.
- Exponential backoff, circuit breaker, dead-letter state, and operator replay.
- Connector dashboards for health, lag, errors, delivery rates, and credential expiry.
- Reconciliation jobs compare Zephyrus intent with vendor/EHR status.

FHIR R4 Subscription supports push-style notifications but warns that transmitted clinical data requires appropriate security. Zephyrus should use PHI-free invalidation where possible and fetch authorized detail after receipt: [FHIR R4 Subscription](https://hl7.org/fhir/R4/subscription.html).

---

## 12. Authorization, Privacy, and Clinical Safety

### 12.1 Authorization model

Add abilities such as:

```text
rounds:view-aggregate
rounds:view-patient
rounds:contribute
rounds:lead
rounds:invite-family
rounds:request-interpreter
rounds:invite-consultant
rounds:manage-template
rounds:start-eddy-tour
rounds:review-eddy
rounds:writeback
```

Authorization combines auth role, current unit/service-line staff assignment, care-team relationship, scope policy, encounter status, and explicit consult grant. An admin role does not automatically justify clinical patient access in production policy.

Extend the flow lens with service-line and department resolution. Unit-depth users receive patient detail only in shared units; service-line leaders receive aggregate data by default and patient detail only when policy and assignment permit it.

### 12.2 Minimum necessary controls

- PHI-free broadcast and push payloads.
- No patient names or clinical summaries in logs, queue job names, metrics labels, URLs, or exception messages.
- Field-level response schemas by lens.
- Encryption in transit and at rest; external secrets in the secret manager.
- Audit guest access, exports, writeback, invitation, contribution, completion, overrides, and Eddy tool calls.
- Configurable retention by data class; legal hold support.
- Break-glass access requires reason, short duration, prominent banner, and review audit.
- Export/download disabled by default for guest and broad aggregate roles.

### 12.3 Clinical safety controls

- Clinical content is source-attributed and timestamped.
- Stale or conflicting data is explicit; the system does not silently choose a winner.
- Only authorized humans attest or sign clinical contributions.
- Discharge readiness is advisory until the responsible clinician completes the applicable order/workflow.
- Eddy outputs visibly identify uncertainty and missing evidence.
- Templates and CDS rules are versioned, reviewed, testable, and deployable behind flags.
- Safety events, inappropriate access, unsupported output, notification misrouting, and writeback mismatch have dedicated reporting and kill switches.

### 12.4 Feature flags

```text
VIRTUAL_ROUNDS_ENABLED=false
VIRTUAL_ROUNDS_FAMILY_ENABLED=false
VIRTUAL_ROUNDS_WRITEBACK_ENABLED=false
VIRTUAL_ROUNDS_EDDY_ENABLED=false
VIRTUAL_ROUNDS_EXTERNAL_NOTIFICATIONS_ENABLED=false
```

Flags gate routes, UI, jobs, and outbound connectors. Disabling a flag stops new work without deleting existing audit records.

---

## 13. Backend Implementation Map

### 13.1 New areas

```text
app/Models/Rounds/*
app/Services/Rounds/RoundAuthorizationService.php
app/Services/Rounds/RoundCohortBuilder.php
app/Services/Rounds/RoundCommandService.php
app/Services/Rounds/RoundContributionService.php
app/Services/Rounds/RoundCompletionService.php
app/Services/Rounds/RoundQueueService.php
app/Services/Rounds/RoundEtaService.php
app/Services/Rounds/RoundProjectionService.php
app/Services/Rounds/RoundNotificationService.php
app/Services/Rounds/RoundIntegrationService.php
app/Services/Rounds/EddyRoundTourService.php
app/Jobs/Rounds/*
app/Events/Rounds/*
app/Policies/Rounds/*
app/Http/Controllers/Api/Rounds/*
app/Http/Requests/Rounds/*
config/rounds.php
database/migrations/*_create_virtual_rounds_tables.php
database/seeders/RoundTemplateSeeder.php
```

### 13.2 Existing areas to extend

- `routes/api.php`: authenticated rounds route group and strict middleware.
- `routes/web.php`: rounds board route and optional guest route group.
- `FlowLensService`: department/service-line scopes and rounds-specific depth.
- `PatientFlowController`/projection services: round overlay is a separate authorized endpoint, not added to public aggregate summary.
- `NavigatorScene.ts`: layer lifecycle and camera tour methods.
- `EddyActionService`: narrow round proposal types.
- `EddyContextService`/tool registry: bounded round snapshot tools.
- `PushNotifier`: reuse for staff; do not generalize it into an unsafe arbitrary-recipient API.
- `bootstrap/app.php`: scheduled reconciliation/expiry jobs with `withoutOverlapping`.

### 13.3 Service boundaries

Controllers validate and delegate. They do not compute priority, resolve access, send messages, or implement transitions. Jobs call idempotent application services. Vendor clients live behind connector contracts. Every service returns typed arrays/DTOs with explicit versions and source cutoffs.

---

## 14. Frontend Implementation Map

```text
resources/js/Pages/RTDC/VirtualRounds.tsx
resources/js/features/virtualRounds/api.ts
resources/js/features/virtualRounds/schemas.ts
resources/js/features/virtualRounds/types.ts
resources/js/features/virtualRounds/hooks/*
resources/js/Components/VirtualRounds/RoundsBoard.tsx
resources/js/Components/VirtualRounds/RoundsCommandBar.tsx
resources/js/Components/VirtualRounds/RoundQueue.tsx
resources/js/Components/VirtualRounds/RoundPatientWorkspace.tsx
resources/js/Components/VirtualRounds/ContributionComposer.tsx
resources/js/Components/VirtualRounds/ParticipantRail.tsx
resources/js/Components/VirtualRounds/CoordinationPanel.tsx
resources/js/Components/VirtualRounds/EddyTourControls.tsx
resources/js/Components/VirtualRounds/EddyEvaluationReview.tsx
resources/js/Components/PatientFlowNavigator/RoundsLayer.ts
```

Use TanStack Query for server state, Zod for response validation, and Zustand only for transient tour/camera/UI state. Optimistic updates are limited to low-risk UI actions and must roll back on version conflicts. Contribution submission, completion, invitations, queue reorder, and review decisions wait for authoritative success.

The first screen is the usable rounds board, not a marketing page. It should be dense, quiet, and optimized for repeated scanning. The 4D view is a peer mode and drill surface, not a decorative hero.

---

## 15. Delivery Plan

Estimates are engineering ranges for one focused cross-functional team and exclude vendor contracting, enterprise security review, and EHR change-control lead time.

### Phase 0 - Discovery, safety, and contracts (2-3 weeks)

**Build**

- Name clinical owner, operational owner, privacy/security owner, integration owner, and pilot-unit owner.
- Observe current rounds on at least two distinct units and one service-line review.
- Define required role sections, exception policy, discharge-ready definition, priority reason codes, and communication consent rules.
- Inventory EHR/FHIR/HL7 capabilities, nurse platforms, interpreter vendor, telehealth, family channels, and sandboxes.
- Approve canonical FHIR/vendor mappings and writeback boundary.
- Produce threat model, data-flow diagram, downtime workflow, and clinical safety case.
- Record baseline metrics.

**Gate**

- Signed workflow and data contract.
- No unresolved question about who may view, contribute, complete, invite, or write back.
- Pilot can run read-only with synthetic/deidentified data.

### Phase 1 - Rounds kernel and unit board (4-6 weeks)

**Build**

- Migrations, models, template seeding, state machines, events, policies, and audit.
- Unit cohort builder from active flow encounters.
- Explainable static priority and ETA range.
- Versioned contributions, questions, tasks, completion requirements, and leader exceptions.
- Unit board with filters, queue, patient workspace, and source freshness.
- PHI-free reload-ping events.
- Synthetic round generator tied to Summit demo data.

**Gate**

- Two users can contribute concurrently without lost updates.
- Unauthorized unit access is `403` and leaks no patient identifiers.
- Every priority reason and completion status is reproducible from recorded inputs.
- A run survives browser close, queue restart, and worker retry.

### Phase 2 - 4D rounds overlay (3-4 weeks)

**Build**

- `Flow | Rounds` mode, run/scope selection, rounds scene projection, status legend, and patient workspace handoff.
- Unit/service-line progress overlays and stable camera focus.
- Guided itinerary using location centroids.
- WebGL-unavailable board fallback and accessibility controls.

**Gate**

- Scene projection matches the board for the same version.
- Camera and filters remain stable during updates.
- Canvas pixel checks are nonblank at desktop/mobile viewports.
- No restricted patient token appears for an aggregate-only lens.

### Phase 3 - Async multidisciplinary workflow (4-6 weeks)

**Build**

- Role-specific contribution schemas and templates.
- Required-role resolution from care team plus staffing assignments.
- Consultant question/invite workflow with bounded access.
- Cross-shift handoff, reopen/supersede, stale-input warning, and conflict display.
- Department master/crosswalk design; enable department scope only when its source is governed.
- Service-line cohort and aggregate progress across units.

**Gate**

- One encounter is not documented twice when visible in unit and service-line runs.
- Required roles and waivers are explicit and audited.
- A consultant sees only the invited patient/question and can submit a bounded response.

### Phase 4 - Staff notifications and hybrid timing (3-5 weeks)

**Build**

- Queue-sharing, next/up-next/window-change policies.
- Hummingbird APNs routing through active assignments; Android FCM implementation.
- Delivery ledger, acknowledgment, retry/dead letter, quiet periods, and notification damping.
- Live/hybrid attendance and timer state.
- Connector capability/health admin view.

**Gate**

- Notifications are PHI-free and deduplicated under repeated queue updates.
- Revoked devices and inactive assignments receive nothing.
- ETA shifts below threshold do not notify.

### Phase 5 - Family, interpreter, and telehealth pilot (5-8 weeks plus vendor lead time)

**Build**

- Consent/relationship verification and tokenized recipient references.
- Secure guest RSVP/questions experience.
- Interpreter request lifecycle and one pilot connector.
- Telehealth join-link lifecycle and one pilot connector.
- Family timing notifications and approved follow-up.
- Accessibility and language testing with interpreters and patient-family advisors.

**Gate**

- SMS contains no PHI and expired/revoked tokens reveal nothing.
- Interpreter/family requests cannot expose another patient or the unit queue.
- Failed vendor delivery is visible and has an operational fallback.
- Consent withdrawal stops future messages.

### Phase 6 - Eddy rounds tour, shadow mode (4-6 weeks)

**Build**

- Tour/evaluation tables, eligibility manifest, job checkpoints, cancellation, and progress events.
- Per-patient bounded context tool and structured output validator.
- Guided 4D camera synchronization.
- Human evaluation-review queue and low-risk proposal bridge to Eddy governance.
- Evaluation harness with synthetic cases, missing data, conflicting data, stale data, and access changes.

**Gate**

- Eddy cannot cross patient/scope boundaries in adversarial tests.
- A retry resumes without duplicate evaluations.
- All displayed assertions have evidence refs or are clearly abstentions.
- No Eddy output changes round, task, priority, message, or EHR state without a human action.

### Phase 7 - EHR writeback and production pilot (6-10 weeks plus EHR lead time)

**Build**

- One deployment-specific writeback adapter for agreed Task/Communication/note fields.
- Transactional outbox, reconciliation, idempotency, replay, and mismatch dashboard.
- Read-only shadow period, then limited writeback by template/unit.
- Downtime and rollback procedures.

**Gate**

- Sandbox and integrated test environment pass duplicate, timeout, partial-failure, and replay cases.
- Clinical documentation/signature ownership is approved.
- Zephyrus and EHR states reconcile or raise an actionable exception.
- Kill switch stops new outbound operations immediately.

### Phase 8 - Scale and optimization (ongoing)

- Learned duration model after adequate local data; shadow evaluation first.
- Learned priority suggestions only after fairness/safety review and explicit human override.
- CAD-derived walkable routing if operationally valuable.
- Additional units, service lines, facilities, nurse platforms, and interpreter vendors.
- Materialized aggregate projections and retention partitioning at scale.
- Formal outcomes evaluation and template governance council.

---

## 16. Test Strategy

### 16.1 Unit tests

- Every state transition, invalid transition, and exception role.
- Cohort inclusion/exclusion/deduplication.
- Scope resolution for unit, service line, department, and patient.
- Priority components, tie-breaking, stale fallback, manual override, and reason serialization.
- ETA range and notification threshold logic.
- Completion policies, freshness, waivers, supersession, and conflicts.
- Guest token expiry/revocation and consent state.
- Connector idempotency and retry classification.
- Eddy output schema, evidence validation, cutoff, and abstention.

### 16.2 Feature/contract tests

- Auth required on all staff endpoints.
- Cross-unit, aggregate-only, consultant, family, and Eddy access matrices.
- `409` behavior for stale versions and duplicate idempotency keys.
- Concurrent contributions and queue reorder under row locks.
- Outbox atomicity with domain changes.
- Delivery replay and dead-letter recovery.
- Tour pause/resume/cancel/access-revocation.
- FHIR/vendor fixtures and contract drift.
- Feature flags return disabled behavior without data loss.

### 16.3 Frontend tests

- Zod rejects malformed projections.
- Board status and missing requirements.
- Conflict/retry behavior.
- Contribution drafts and supersession confirmation.
- Notification/invite state and degraded connectors.
- Eddy review decisions and no implicit acceptance.
- Keyboard, focus, reduced motion, and WebGL fallback.

### 16.4 End-to-end scenarios

1. Unit async round across a shift change.
2. High-priority manual pin plus discharge-ready patient and ETA recalculation.
3. Family RSVP in another language with interpreter request and remote join.
4. Consultant invitation with bounded patient access.
5. Patient transfer during an active unit round.
6. Service-line run spanning multiple units with aggregate-only viewer.
7. Eddy tour pause, worker retry, data staleness, review, and rejected proposal.
8. EHR writeback timeout followed by idempotent reconciliation.

### 16.5 Validation commands

```bash
php artisan test --filter=VirtualRounds
php artisan test --testsuite=Unit
./vendor/bin/pint --test
npm run test
npm run build
npm run test:e2e -- virtual-rounds
git diff --check
npx prettier --check docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md
```

---

## 17. Observability and Outcomes

### 17.1 Operational telemetry

- active runs and patients by state;
- projection freshness and query latency;
- queue version conflicts;
- contribution latency by role, without patient identifiers in metric labels;
- missing/waived requirements;
- outbox depth, connector latency/error, retries, dead letters, and reconciliation mismatch;
- notification sent/delivered/acknowledged rates and damping counts;
- tour throughput, pause/stop reasons, validation failures, abstentions, and reviewer decisions;
- unauthorized/denied access and guest token failures.

### 17.2 Product/outcome measures

Establish a baseline before pilot and stratify by unit/template/shift where sample size permits:

- percentage of rounds with bedside nurse participation;
- family invitation, RSVP, and attendance rates;
- interpreter need identified and fulfilled;
- time from round start to completion;
- percentage completed within published window;
- discharge-ready identification to order/discharge milestones;
- unresolved barrier age;
- consultant response time;
- staff notification burden and acknowledgment;
- percentage of Eddy findings accepted, edited, rejected, or ungrounded;
- user-reported coordination burden and patient/family experience.

Do not claim causal clinical improvement from pre/post operational measures alone. Define the evaluation design with clinical analytics.

### 17.3 Service objectives

Initial pilot targets:

- board projection p95 under 2 seconds;
- command p95 under 1 second excluding vendor delivery;
- realtime invalidation visible within 5 seconds;
- no lost acknowledged contribution;
- notification intent to adapter queue under 30 seconds;
- resumable Eddy tour with no duplicate committed evaluation;
- complete audit coverage for sensitive commands.

---

## 18. Risks and Mitigations

| Risk                                             | Consequence                  | Mitigation                                                                                   |
| ------------------------------------------------ | ---------------------------- | -------------------------------------------------------------------------------------------- |
| Treating department and service line as synonyms | Wrong cohorts and access     | Separate scope types and require governed crosswalk                                          |
| Shadow medical record                            | Conflicting clinical truth   | Allowlisted sections, source refs, EHR writeback governance, retention policy                |
| Broad house-scale patient access                 | Privacy breach               | Lens-clamped server projections, assignment/care-team checks, audit and adversarial tests    |
| Overconfident discharge/CDS ranking              | Unsafe prioritization        | Deterministic explainable rules, human override, staleness fallback, shadow learned models   |
| Notification fatigue                             | Staff/family disengagement   | Windows, damping, preferences, quiet periods, escalation thresholds                          |
| Family contact/consent drift                     | Misrouted PHI                | Connector-owned contact, consent snapshot, generic SMS, revocation/reverification            |
| Eddy context leakage between patients            | Serious privacy/safety event | One-patient context, isolated tool calls, manifest/lens checks before every step             |
| Eddy implied autonomy                            | Unsafe action                | Draft-only evaluation, visible reviewer state, existing approval FSM, kill switch            |
| Duplicate external writes                        | EHR/vendor inconsistency     | Transactional outbox, idempotency, reconciliation, replay tooling                            |
| 3D novelty reduces efficiency                    | Slow workflow                | Board is primary operational surface; 3D is optional peer view                               |
| No walkable facility graph                       | Misleading route claims      | Stable guided itinerary first; label accurately; derive navigation graph later               |
| Vendor API/contract delay                        | Blocks launch                | Adapter interfaces, simulators, phased read-only pilot, degraded-state UI                    |
| Stale active census during a run                 | Wrong cohort                 | Source cutoff, explicit reconcile suggestions, transfer/discharge events, no silent mutation |

---

## 19. Decisions Required Before Phase 1

1. Which unit and service line are the first pilots?
2. What exact contribution sections and roles are required for each pilot template?
3. Who can mark a patient rounded, waive a requirement, reopen a round, or change priority?
4. What is the clinical definition and source hierarchy for `high priority` and `ready for discharge`?
5. Is the first release async-only, or must it include a live queue timer?
6. Which EHR and interface capabilities are actually available in the pilot environment?
7. Which system is authoritative for care-team membership, family relationship/consent, language, and contact channel?
8. Which nurse communication, interpreter, and telehealth vendors have supported APIs and sandboxes?
9. What, if anything, may Zephyrus write to the EHR in the pilot?
10. Which roles may start an Eddy tour, over what maximum cohort, and who reviews the results?
11. What is the retention policy for contributions, guest questions, notifications, and Eddy evaluations?
12. Is department scope needed in the pilot, and if so, what is its governed master source?

These decisions should become versioned configuration or ADRs, not undocumented controller behavior.

---

## 20. Release Checklist

### Product and clinical

- [ ] Pilot workflows observed and signed off.
- [ ] Required roles, sections, completion policy, and exception reasons approved.
- [ ] Priority/readiness rules reviewed by clinical leadership.
- [ ] Downtime workflow and escalation owner documented.
- [ ] Family/interpreter copy reviewed for clarity, accessibility, and translation.

### Data and integration

- [ ] Source-of-truth matrix and field-level lineage complete.
- [ ] Department/service-line/unit crosswalk validated.
- [ ] Connector capability and degradation behavior tested.
- [ ] Outbox, idempotency, replay, reconciliation, and kill switches proven.
- [ ] Synthetic and non-production fixtures contain no real PHI.

### Security and privacy

- [ ] Threat model and privacy review complete.
- [ ] Authorization matrix passes server-side tests.
- [ ] Guest token, consent, revocation, expiry, and rate limiting verified.
- [ ] Logs, URLs, pushes, broadcasts, and metrics scanned for PHI.
- [ ] Audit export and incident-review process tested.

### Eddy safety

- [ ] Per-patient context isolation tested.
- [ ] Output schema/evidence validator blocks unsupported content.
- [ ] Draft-only and approval boundaries verified.
- [ ] Model/prompt/tool/policy versions recorded.
- [ ] Pause, cancel, stale-data, access-revocation, and kill-switch paths tested.

### UX and quality

- [ ] Unit board is usable without the 3D view.
- [ ] Desktop/mobile Playwright screenshots and canvas pixel checks pass.
- [ ] Keyboard, screen-reader, focus, reduced-motion, and contrast checks pass.
- [ ] Concurrent edits and stale-version recovery are understandable.
- [ ] Build, tests, Pint, Prettier, and `git diff --check` pass.

### Pilot exit

- [ ] Baseline and target measures defined before activation.
- [ ] Read-only/shadow period completed.
- [ ] No open severity-1 privacy, clinical safety, or data-integrity issue.
- [ ] Support, monitoring, rollback, and on-call ownership are active.
- [ ] Clinical and operational owners approve expansion beyond the pilot.

---

## 21. Definition of Done

Virtual Rounds is complete for the first production pilot only when:

- any authorized pilot care-team member can submit a durable, role-attributed asynchronous contribution;
- unit and service-line views show the same canonical patient-round state without duplicate documentation;
- the queue explains priority and shows a defensible completion window;
- nurse/family/interpreter/consultant coordination uses consented, audited, reliable channels;
- the 4D viewer accurately projects the authorized round state and offers a nonessential guided tour;
- Eddy can evaluate an authorized cohort with per-patient isolation, checkpointed execution, evidence-linked drafts, and mandatory human review;
- no external communication, clinical attestation, priority mutation, or EHR writeback occurs outside the approved policy gate;
- authorization, concurrency, retries, reconciliation, accessibility, and safety tests pass;
- pilot outcomes are measured against a pre-launch baseline and reviewed before scale-up.
