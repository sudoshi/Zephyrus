# Zephyrus Hospital OCEL Model Landscape Implementation Plan

**Status:** Executable reference-model tranche implemented; semantic OCEL expansion remains planned
**Date:** 2026-07-10
**Source:** `ACUM-OPS-OCEL-001`, *OCEL Models for Process Improvement in Modern Hospital Operations*, v1.0, 2026-07-09
**Scope:** Zephyrus OCEL 2.0 projection, Patient-Flow Arena, all hospital operations domains, and the governed improvement loop
**Authority:** This plan specializes `docs/ZEPHYRUS-2.0-PART-X.md`. The beta PRD still governs beta scope and feature promotion.

## 1. Executive decision

Zephyrus should implement the report as one governed object-centric event fabric that publishes many bounded process views. It should not create one whole-hospital process graph, one extraction per process, or 93 disconnected mock diagrams.

The first implementation tranche now provides a database-backed reference registry and a React Flow map for every catalog row. It intentionally separates three different truths:

1. **Reference design:** what the process should mean and which facts/objects it requires.
2. **Projected evidence:** what the current `ocel.*` log actually contains.
3. **Discovered model:** what the Arena sidecar mines from that evidence.

The next phase must close the semantic foundation before claiming hospital-wide OCEL readiness. Current Zephyrus is a credible OCEL/Arena substrate, but no catalog model is yet a production-validated bounded model. Twenty models have a partial projection, 24 have a real source seam that is not projected end to end, and 49 remain reference-only.

## 2. Catalog-count reconciliation

The implementation request referred to 88 processes. The PDF's section 6 actually contains **93 unique candidate models**:

| Domain | IDs | Count |
|---|---:|---:|
| A — Access and demand | A1–A10 | 10 |
| B — Capacity, placement, inpatient flow, and discharge | B1–B12 | 12 |
| C — Perioperative and procedural operations | C1–C12 | 12 |
| D — Diagnostics, consults, therapies, and medication | D1–D14 | 14 |
| E — Logistics, workforce, assets, and facilities | E1–E12 | 12 |
| F — Quality, safety, reliability, and clinical pathways | F1–F13 | 13 |
| G — Administrative, financial, enterprise, and network | G1–G12 | 12 |
| H — Improvement and operating-system governance | H1–H8 | 8 |
| **Total** | **A1–H8** | **93** |

The executable catalog covers all 93. Automated tests construct the expected ID sequence and fail on an omission, duplicate, or unexpected row.

## 3. Design findings that govern implementation

### 3.1 One fabric, bounded views

The shared store should preserve reusable events, objects, qualified relationships, time-varying attributes, provenance, and corrections. A published process view should normally select two to five primary object types plus the minimum supporting objects needed for its improvement question.

### 3.2 Temporal relationships require association objects

Changing relationships must not be represented as timeless object-to-object edges. `Bed Stay`, `Staff Assignment`, `Transport Assignment`, `Barrier Ownership`, `PACU Stay`, and similar relationships need stable identities, begin/end facts, and interval semantics.

### 3.3 Physical and documentary events are different facts

The model must distinguish at least:

- bed assignment, bed readiness, origin departure, destination arrival, and physical occupancy;
- discharge order, encounter completion, physical departure, bed vacancy, dirty, cleaning, inspected, and ready;
- transport acceptance, assignment, dispatch, arrival at origin, pickup, arrival at destination, handoff, and completion;
- OR wheels-out, PACU arrival, recovery completion, PACU-ready, destination-ready, and PACU departure.

If a physical timestamp is unavailable, Zephyrus must expose an explicitly named proxy. It must never rename dispatch as pickup or assignment as occupancy.

### 3.4 Authored and discovered maps must remain visibly distinct

The 93 seeded maps are governed reference designs. They are not observed event counts, conformance results, or evidence of local workflow. The UI and API therefore carry `data_basis=seeded_reference_model` and `observed_claim=false`. The current discovered OC-DFG remains a separate surface.

### 3.5 Analytical claims must match the algorithm

The current Arena provides an object-tagged union of per-type DFGs, adjacent lifecycle-gap heuristics, and batch pathway rule checks. Those are useful. They are not formal OCPN discovery, OPerA replay, object-centric alignment, or streaming prefix conformance. Product copy, metric metadata, and audit exports must retain that distinction until formal engines and validation gates exist.

## 4. Verified current-state snapshot

The following was verified from the 2026-07-10 worktree, local PostgreSQL state, Laravel scheduler, and host services.

| Capability | Current evidence | Readiness conclusion |
|---|---|---|
| OCEL relational store | `ocel.object_types`, `activities`, `events`, `objects`, E2O, O2O, and `object_changes` exist | Foundation present |
| Event projection | 4,193 events, 1,156 objects, 11,165 E2O links, 842 O2O links, 1,983 object changes | Real but narrow evidence corpus |
| Source coverage | 1,569 flow events; 1,645 perioperative milestones; 940 case-timing events; 39 transport phases | Four source streams only |
| Object ontology | 12 types declared; seven emitted; report target is 52 | 23.1% declared and 13.5% emitted |
| Emitted types | Patient, Encounter, Bed, Unit, OR Case, OR Suite, Transport Job | Flow/periop/transport core only |
| Activity ontology | 48 activities, with mixed kebab case, snake case, title case, and generic verbs | Requires versioned normalization |
| Refresh cadence | `RefreshOcelLog` every 15 minutes; 90-day reconcile daily at 02:30 | Code and host scheduler plumbing present |
| Host scheduler | `www-data` cron runs `/var/www/Zephyrus/php artisan schedule:run` each minute | Production schedule runner present |
| Arena sidecar | `zephyrus-arena.service` active; pm4py 2.7.23.1 available | Runtime engine present |
| Arena delivery | Laravel proxy/cache, React Flow OC-DFG, performance, conformance, and governed copilot routes exist | Feature substrate present; semantics still bounded |
| Reference landscape | 93 models, 558 nodes, 465 edges seeded idempotently | Complete visual reference coverage |
| Object-change idempotency | Unique `(object_id, attr, changed_at)` constraint exists | PDF gap partially remediated |

### 4.1 Readiness distribution

| Readiness tier | Count | Meaning |
|---|---:|---|
| Partial projection | 20 | Some relevant events/objects exist in `ocel.*`, but the bounded model is semantically incomplete and unvalidated |
| Source present, not projected | 24 | A real Zephyrus operational table or deterministic seed seam exists, but no governed end-to-end OCEL view exists |
| Reference only | 49 | The reference map exists; source contract, canonical facts, and emission remain open |

No tier means “production-ready.” Production readiness requires the per-model charter, coverage thresholds, semantic validation, reconciliation, owner approval, privacy review, and algorithm-specific acceptance gates defined below.

## 5. Material gaps and required corrections

| Gap | Current behavior | Required correction | Gate |
|---|---|---|---|
| Temporal occupancy | Encounter `occupies` Bed is a timeless O2O edge | Emit `Bed Stay`/`Placement` objects with begin/end facts; keep stable Bed-in-Unit relationships separate | Foundation |
| Assignment equals occupancy | `assign-bed` sets Bed status to occupied | Split assigned, ready, departed-origin, arrived-destination, and physical-occupancy-started | Foundation |
| Discharge equals vacancy | Generic discharge vacates a bed | Source physical departure and bed-vacated facts independently | Foundation |
| Transport pickup proxy | Dispatch or assignment is emitted as `transport-pickup` | Preserve accepted, assigned, dispatched, arrived-origin, pickup, and proxy basis | Foundation/Wave 1 |
| PACU semantics | Non-procedure timing can leave OR Suite “occupied”; PACU is absent | Add PACU Stay/Bay and close OR occupancy at actual wheels-out | Wave 2 |
| Object-change identity | Natural uniqueness now exists, but no source/version identity | Add deterministic change ID, source reference, mapping version, and supersession semantics | Foundation |
| Empty attribute schemas | Catalog rows write `{}` schemas | Publish versioned object/event schemas and compatibility rules | Foundation |
| Time provenance | One event time without documented/ingested/corrected/time-basis fields | Add source time, documented time, ingested time, correction time, precision, and proxy basis | Foundation |
| Mixed activities | Several naming conventions and generic verbs coexist | Ratify completed past-tense canonical activities; version source mappings and deprecations | Foundation |
| Performance claims | Adjacent deltas and heuristic synchronization | Label as heuristic; add OCPN replay and validated OPerA measures before formal claims | Wave 1/2 |
| Conformance claims | Batch, one-case-object rule engine | Keep explicit rule-engine labeling; add model/version/exception support and formal alignment where justified | Wave 2/3 |
| Identity privacy | Truncated unsalted SHA-256 is deterministic and linkable | Use tenant-scoped HMAC/token service; document re-identification controls and formal privacy assessment | Foundation |
| Tenant/facility scope | Not carried on every OCEL fact/export | Add facility/tenant scope, row access, retention, and export policy metadata | Foundation |
| Correction/replay | Projection upserts current facts without a complete correction lifecycle | Emit correction/supersession facts; link DQ finding, mapping change, replay, and reconciliation | H7 Foundation |
| Model governance | Reference registry exists without approval/evidence-window lifecycle | Add owner, reviewer, approval, effective/expiry dates, extraction parameters, fitness/precision, and publication state | Wave 1/2 |

## 6. Target architecture

```text
EHR / ADT / RTDC / OR / EVS / transport / staffing / ancillary / ERP sources
                                │
                                ▼
        Immutable source envelopes + versions + correction lineage
                                │
                                ▼
      Canonical operational facts + identity resolution + time basis
                                │
                                ▼
       Governed OCEL projection: events, objects, E2O, O2O, changes
                    ┌───────────┴───────────┐
                    ▼                       ▼
       Bounded domain views        Reference-model registry
                    │                       │
                    ├───────────┬───────────┤
                    ▼           ▼           ▼
               Discovery    Performance  Conformance
                    └───────────┬───────────┘
                                ▼
       Published evidence + metric registry + provenance + review
                                │
                                ▼
     Signal → recommendation → approval → action → PDSA → outcome
```

### 6.1 Required canonical layers

1. **Raw immutable envelope:** source payload/reference, source timestamps, ingestion timestamp, facility, interface version, correction/supersession.
2. **Canonical operational fact:** governed activity, resolved identities, time basis, source fidelity, mapping version.
3. **OCEL projection:** typed events/objects, qualified E2O/O2O, association objects, time-varying attributes, schema versions.
4. **Bounded view registry:** process ID, scope, objects, source filters, evidence window, extraction version, owner, approval, expiration.
5. **Model registry:** authored/discovered kind, algorithm/library version, fitness, precision, reviewer, publication state.
6. **Metric registry:** definition, denominator, time semantics, thresholds, source coverage, balancing measures.
7. **Governed action plane:** recommendation, approval, assignment, execution, override, outcome review, PDSA disposition.

## 7. Delivered reference-model tranche

### 7.1 Persistent model registry

`ocel.process_models`, `ocel.process_model_nodes`, and `ocel.process_model_edges` store all 93 models. `OcelProcessLandscapeSeeder` owns only these reference tables and converges on repeated runs. It does not write observed OCEL events or mined Arena evidence.

Each model carries:

- PDF ID, domain, name, question, evidence grade, and priority;
- core object collision and reusable interaction pattern;
- implementation wave and current readiness tier;
- a process-specific six-event target sequence using completed past-tense facts;
- object participation at each node;
- explicit `reference_model=true` and `observed_claim=false` metadata.

No synthetic frequency, duration, throughput, or conformance score is fabricated. An observed node count is displayed only when the live OCEL log has an exact matching canonical activity.

### 7.2 Serving contract

- `GET /api/arena/models` returns document metadata, all 93 summaries, domain/priority/readiness/wave counts, and a current projection snapshot.
- `GET /api/arena/models/{processId}` returns one reference graph with nodes and edges.
- Both routes require authentication, the Arena feature flag, and API throttling inherited from the Arena route group.
- Zod schemas keep browser data `unknown` until validated.

### 7.3 React Flow experience

The Patient-Flow Arena now provides:

- full-text search across ID, name, objects, and improvement question;
- domain, priority, and readiness filters;
- a selector and previous/next navigation covering every catalog row;
- a dedicated React Flow canvas for each process;
- trigger, decision, exception, event, and outcome node treatments;
- object-participation chips and typed transition labels;
- readiness explanation, core-object collision, evidence grade, and delivery wave;
- a hard visual boundary between seeded reference designs and observed/discovered evidence.

## 8. Implementation waves

Effort ranges are planning estimates, not release commitments. Wave exit criteria govern promotion more strongly than elapsed time.

### Foundation — H7 and semantic trust (4–6 weeks)

**Models:** H7 plus shared foundations for all 93.

**Work:**

1. Ratify the 52-type ontology and completed past-tense activity vocabulary.
2. Add deterministic source/version identity for events, objects, and object changes.
3. Add `documented_at`, `ingested_at`, `corrected_at`, `time_basis`, `precision`, tenant, and facility fields.
4. Implement association-object primitives and migrate encounter/bed logic to `Bed Stay`.
5. Implement correction, supersession, quarantine, replay, and DQ-finding lineage.
6. Replace raw hashes with a governed tenant-scoped token/HMAC service.
7. Publish JSON schemas and compatibility/deprecation rules.
8. Rename or explicitly label every proxy and heuristic.

**Exit criteria:**

- repeat projection is byte/count stable for the same source versions;
- source-to-OCEL reconciliation is at least 99.5% or every excluded row has a coded reason;
- zero timeless edges for relationships designated temporal;
- every event exposes source, mapping version, time basis, facility, and tenant;
- every correction is reproducible through replay;
- privacy/security review approves the token and export model;
- H7 can trace finding → owner → correction → replay → reconciliation → closure.

### Wave 1 — first production portfolio (6–8 weeks after Foundation)

**Models:** A8, B1, B2, B3, B7, E1, H1, H2, H3, H4.

**Work:**

- implement `flow.ed_boarding_v1`, `flow.bed_turn_v1`, `flow.discharge_barriers_v1`, `logistics.transport_v1`, and `improvement.action_v1`;
- project Placement Request, Bed Stay, EVS Task/Assignment, Barrier/Ownership, Transport Assignment, Signal, Alert, Recommendation, Action, and PDSA objects;
- validate physical timestamps with operational owners;
- add interval-aware barrier exposure and explicit proxy coverage;
- publish first reviewed reference-vs-observed overlays in the Arena.

**Exit criteria:**

- 95% or better lifecycle coverage for in-scope encounters/jobs/tasks, with exclusions coded;
- physical occupancy, vacancy, cleaning, pickup, and departure semantics approved by source owners;
- p50/p90/tail metrics reconcile to source-system samples;
- reference overlays disclose missing/extra paths and evidence window;
- no worker-ranking view; assignment data remains pseudonymous and role-governed;
- all actions remain draft/pending until human approval.

### Wave 2 — shared services, network, and perioperative synchronization (8–12 weeks)

**Models:** remaining P1 portfolio, led by A9, B10, B11, C1, C3, C7, C8, C12, D1, D5, D8, E4, G8, F13, G11, H5, H6, and H8.

**Work:**

- implement PACU Stay/Bay and close OR occupancy at actual physical departure;
- project lab Specimen/Test/Analyzer/Result, Imaging Study/Scanner/Report, Consult/Assignment/Recommendation;
- join forecast, huddle, action, actual, and reconciliation lifecycles;
- promote regional-transfer and staffing sources into governed OCEL emissions;
- establish model review/publication and measure-evidence lineage;
- introduce formal OCPN/OPerA only for models whose log/model fitness passes validation.

**Exit criteria:**

- actual PACU location and hold semantics approved;
- lab/imaging/consult timestamps reconcile to authoritative systems;
- every published model records evidence window, extraction version, owner, reviewer, fitness, precision, and expiry;
- formal analytical labels appear only when the formal algorithm produced them.

### Wave 3 — P2 expansion (12–18 weeks, sequenced by funded pain)

**Models:** 38 P2 processes covering access, diagnostics, pharmacy, logistics, safety, workforce, utilization review, downtime, and network continuity.

**Work:** source-by-source contracts, exception ontologies, clinical-governance review, formal or declarative conformance where appropriate, and balancing measures.

**Exit criteria:** each model independently meets its charter. No whole-wave “ready” flag should mask a weak domain.

### Wave 4 — P3/specialized portfolio (later)

**Models:** 11 P3 processes covering pre-registration, nutrition, reverse logistics, selected safety prevention, revenue cycle, credentialing, onboarding, and procurement.

Start a Wave 4 model early only when a named executive owner, funded pain, and authoritative data source justify it.

## 9. Workstream backlog

### WS1 — Semantic contracts

- approve object, event, qualifier, association-object, and proxy vocabularies;
- publish schema versions, deprecations, and replacement rules;
- create a source-to-canonical mapping registry with effective dates.

### WS2 — Identity, time, and provenance

- deterministic IDs with source/version lineage;
- tenant-scoped tokens/HMAC;
- source/documented/ingested/corrected timestamps and precision;
- correction, supersession, and replay semantics.

### WS3 — Domain source adapters

- ADT/flow and placement;
- EVS and bed status;
- discharge barriers/pharmacy/transport;
- perioperative/PACU;
- lab, imaging, consults, therapies, medication;
- staffing, facilities, assets, supply;
- transfer center/network;
- governance, DQ, model, metric, and action lifecycles.

### WS4 — Projection and bounded views

- add association-object emitters;
- materialize versioned bounded views;
- add per-source watermarks, late-arrival handling, and replay;
- publish view charters and coverage reports.

### WS5 — Analysis engines

- retain OC-DFG for exploration;
- add formal OCPN discovery/replay only where appropriate;
- implement validated OPerA measures;
- add reference-model versioning, exceptions, and formal/declarative conformance;
- keep comparison, prediction, simulation, and causal evaluation distinct.

### WS6 — Arena product

- reference/observed overlay;
- missing and unexpected event/object paths;
- evidence-window and source-coverage display;
- object-lifecycle flattening as a labeled readability projection;
- model approval/publication history;
- drill-through to aggregate evidence without exposing PHI.

### WS7 — Governance, privacy, and workforce safety

- minimum-necessary data profiles;
- row-level tenant/facility access;
- retention and export policies;
- small-cell suppression and equity review;
- prohibition on individual worker ranking without labor/legal/privacy/fairness approval;
- human approval for every operational intervention.

### WS8 — Validation and observability

- source-count reconciliation and coded exclusions;
- uniqueness, referential, lifecycle, clock, ordering, and orphan checks;
- late-arrival and correction replay tests;
- reference-model fitness/precision/simplicity/generalization;
- operational dashboards for projection lag, DQ findings, sidecar health, cache age, and model expiry.

### WS9 — Release and operations

- migrations are additive and reversible;
- seed the reference registry explicitly after migration;
- deploy only through `./deploy.sh`;
- run `php artisan migrate --force` separately because `deploy.sh` does not run migrations;
- run `php artisan db:seed --class=Database\\Seeders\\OcelProcessLandscapeSeeder --force` for the registry;
- verify routes, registry counts, scheduler, queue worker, sidecar, feature flags, and the Zephyrus vhost.

## 10. Per-model charter and promotion gate

Before any reference model becomes a published observed model, it needs a versioned charter containing:

- process ID, title, purpose, improvement question, and named owner;
- included/excluded facilities, services, cohorts, and time windows;
- required object/event types, qualifiers, attributes, and source authorities;
- explicit case/execution construction rule when a comparison needs one;
- time semantics and allowed proxies;
- expected exceptions and how they are approved;
- coverage, reconciliation, DQ, fitness, and precision thresholds;
- privacy/workforce classification and access policy;
- metrics, balancing measures, action path, and evaluation design;
- review, approval, effective, expiry, and revalidation dates.

Promotion states should be: `reference → source-mapped → projecting → validation → approved → published → expired/deprecated`. “Seeded” is never a production-readiness state.

## 11. Testing strategy

### 11.1 Catalog and seed tests

- assert the exact 93-ID sequence and domain counts;
- assert unique models, nodes, ordinals, edges, and valid endpoints;
- assert every graph has a trigger, outcome, and connected path;
- run the seeder twice and assert 93 models, 558 nodes, and 465 edges both times.

### 11.2 Projection contract tests

- fixture-level source row → canonical fact → OCEL event/object assertions;
- association-object interval tests;
- proxy-label tests;
- source/version correction and replay tests;
- facility/tenant isolation tests;
- PHI/free-text exclusion tests.

### 11.3 Bounded-view acceptance tests

- lifecycle coverage and exclusions;
- source reconciliation;
- event-order and clock-skew tolerances;
- interval overlaps/gaps;
- expected exception handling;
- metric reconciliation against independently calculated samples.

### 11.4 Analysis tests

- OC-DFG deterministic fixtures;
- OCPN fitness/precision and replay fixtures;
- OPerA benchmark calculations;
- conformance with approved exceptions and model versions;
- prevent heuristic outputs from being labeled formal.

### 11.5 UI and accessibility tests

- schema parsing at API boundaries;
- all 93 selectors/filter combinations;
- React Flow graph connectivity and fit behavior;
- keyboard selection, readable labels, dark/light mode, and reduced motion;
- persistent “reference, not observed” disclosure;
- no invented counts when evidence is absent.

## 12. Deployment checklist for this tranche

1. Confirm the intended worktree allowlist; leave unrelated dirty files untouched.
2. Run file-scoped Pint and PHP lint.
3. Run catalog unit, API feature, frontend contract/layout, and production build checks.
4. Commit and push only the OCEL landscape tranche if publication is requested.
5. Deploy through `./deploy.sh` only.
6. Run the new migration under `/var/www/Zephyrus` with `php artisan migrate --force`.
7. Seed the registry explicitly with `php artisan db:seed --class=Database\\Seeders\\OcelProcessLandscapeSeeder --force`.
8. Verify 93 models, 558 nodes, and 465 edges in production.
9. Verify authenticated `/api/arena/models` and `/api/arena/models/A8` through the Zephyrus vhost.
10. Verify the Arena page, scheduler, queue, and sidecar; keep reference and observed disclosures visible.

## 13. Risks and non-goals

| Risk | Guardrail |
|---|---|
| A polished reference flow is mistaken for local evidence | Persistent data-basis labels, separate stores/surfaces, no fabricated metrics |
| The 93 maps become a giant spaghetti graph | One selector, one bounded model at a time |
| Wrong physical semantics produce confident false waits | Foundation gates and operational-owner validation |
| Source gaps get hidden by synthetic seeds | Readiness tier and coverage report on every model |
| Formal terms overstate heuristic math | Algorithm/version metadata and label tests |
| Worker analytics become surveillance | Assignment-level pseudonyms and explicit governance prohibition |
| Clinical pathway variability creates false deviations | Exceptions, declarative constraints, clinical review, balancing measures |
| Simulation/AI outruns evidence quality | Foundation and validation gates before prediction/simulation/autonomous narration |

Non-goals for the current tranche are formal OCPN/OPerA implementation, hospital-wide source integration, causal root-cause claims, worker ranking, autonomous intervention, clinical decision support, and production deployment.

## 14. Definition of done

The landscape program is complete only when:

1. all 93 reference models remain versioned, reviewable, and renderable;
2. the 52-type ontology and canonical activity/time/proxy semantics are approved;
3. every published bounded model reconciles to authoritative sources and meets its charter;
4. reference, projected, discovered, conformance, prediction, and intervention claims are never conflated;
5. formal OPerA/conformance labels are backed by formal algorithms and validation;
6. source corrections replay reproducibly through H7;
7. every improvement signal can trace recommendation → approval → action → PDSA → outcome without autonomous enactment;
8. tenant, privacy, retention, workforce, and small-cell controls pass review;
9. production refresh, queue, sidecar, cache, DQ, model expiry, and action-plane health are observable;
10. the hospital can identify which object waited, why it waited, what action was taken, and whether the process shifted—without claiming causality from a map alone.

## Appendix A — Complete 93-model readiness and delivery matrix

“Partial projection” means incomplete current evidence, not validation. “Source present” means an operational seam exists but no complete bounded OCEL model is published. “Reference only” means the source contract and emission remain open.

| ID | Candidate model | Priority | Delivery | Current readiness |
|---|---|---:|---|---|
| A1 | Referral-to-consult | P2 | wave 3 | Reference only |
| A2 | Elective waitlist and open-slot fill | P2 | wave 3 | Reference only |
| A3 | Pre-registration and eligibility | P3 | wave 4 | Reference only |
| A4 | Prior authorization / financial clearance | P2 | wave 3 | Reference only |
| A5 | Direct-admission intake | P1 | wave 2 | Source present |
| A6 | ED end-to-end journey | P1 | wave 2 | Partial projection |
| A7 | Ambulance offload | P2 | wave 3 | Reference only |
| A8 | ED admission and boarding | P1 | wave 1 | Partial projection |
| A9 | Transfer-center intake and acceptance | P1 | wave 2 | Source present |
| A10 | Scheduled arrival/no-show | P2 | wave 3 | Reference only |
| B1 | Bed request and capability matching | P1 | wave 1 | Partial projection |
| B2 | Assignment-to-physical occupancy | P1 | wave 1 | Partial projection |
| B3 | Bed vacation and turnover | P1 | wave 1 | Partial projection |
| B4 | Intra-hospital transfer | P1 | wave 2 | Partial projection |
| B5 | ICU downgrade / stepdown | P1 | wave 2 | Source present |
| B6 | Observation-to-inpatient/outpatient status | P2 | wave 3 | Reference only |
| B7 | Discharge readiness and barriers | P1 | wave 1 | Source present |
| B8 | Post-acute placement | P1 | wave 2 | Reference only |
| B9 | Discharge medication and departure | P1 | wave 2 | Source present |
| B10 | RTDC forecast-plan-reconcile | P1 | wave 2 | Source present |
| B11 | House huddle and escalation | P1 | wave 2 | Source present |
| B12 | Surge, diversion, and incident command | P2 | wave 3 | Source present |
| C1 | Surgical booking and readiness | P1 | wave 2 | Partial projection |
| C2 | Block allocation, release, and fill | P2 | wave 3 | Source present |
| C3 | First-case on-time start | P1 | wave 2 | Partial projection |
| C4 | Day-of-surgery flow | P1 | wave 2 | Partial projection |
| C5 | Intraoperative phase and handoff | P1 | wave 2 | Partial projection |
| C6 | Surgical-safety checklist conformance | P1 | wave 2 | Partial projection |
| C7 | OR room turnover | P1 | wave 2 | Partial projection |
| C8 | PACU and downstream capacity | P1 | wave 2 | Partial projection |
| C9 | Cancellation and rework | P1 | wave 2 | Source present |
| C10 | Sterile processing / instrument trays | P2 | wave 3 | Reference only |
| C11 | Cath/EP/IR/endoscopy flow | P2 | wave 3 | Reference only |
| C12 | Procedure-to-inpatient dependency | P1 | wave 2 | Partial projection |
| D1 | Lab order-to-result | P1 | wave 2 | Partial projection |
| D2 | Specimen split/merge/recollection | P2 | wave 3 | Reference only |
| D3 | Critical-result communication | P2 | wave 3 | Reference only |
| D4 | Blood product lifecycle | P2 | wave 3 | Reference only |
| D5 | Imaging order-to-final report | P1 | wave 2 | Partial projection |
| D6 | Imaging critical finding / result communication | P2 | wave 3 | Reference only |
| D7 | Pathology specimen-to-diagnosis | P2 | wave 3 | Reference only |
| D8 | Specialty consult | P1 | wave 2 | Reference only |
| D9 | PT/OT/SLP therapy clearance | P1 | wave 2 | Reference only |
| D10 | Respiratory therapy / device workflow | P2 | wave 3 | Reference only |
| D11 | Medication order-to-administration | P2 | wave 3 | Source present |
| D12 | Discharge medication / medication reconciliation | P1 | wave 2 | Source present |
| D13 | High-cost/specialty medication authorization | P2 | wave 3 | Reference only |
| D14 | Nutrition/dietary fulfillment | P3 | wave 4 | Reference only |
| E1 | Internal patient transport | P1 | wave 1 | Partial projection |
| E2 | External discharge/medical transport | P1 | wave 2 | Source present |
| E3 | EVS task dispatch and execution | P1 | wave 2 | Source present |
| E4 | Staffing plan-to-deployment | P1 | wave 2 | Source present |
| E5 | Workload and task distribution | P2 | wave 3 | Source present |
| E6 | Handoff/collaboration network | P2 | wave 3 | Reference only |
| E7 | Equipment/asset request and use | P2 | wave 3 | Reference only |
| E8 | Supply replenishment and stockout | P2 | wave 3 | Reference only |
| E9 | Pharmacy/material delivery logistics | P3 | wave 4 | Reference only |
| E10 | Facilities maintenance | P2 | wave 3 | Reference only |
| E11 | Environmental isolation/terminal cleaning | P2 | wave 3 | Reference only |
| E12 | Waste/linen/material reverse logistics | P3 | wave 4 | Reference only |
| F1 | Sepsis time-critical pathway | P2 | wave 3 | Partial projection |
| F2 | Stroke pathway | P2 | wave 3 | Partial projection |
| F3 | STEMI/trauma/other time-critical pathway | P2 | wave 3 | Reference only |
| F4 | Boarding safety and reassessment | P1 | wave 2 | Partial projection |
| F5 | Rapid response / escalation | P2 | wave 3 | Reference only |
| F6 | Infection prevention bundle | P2 | wave 3 | Reference only |
| F7 | Isolation placement and exposure | P2 | wave 3 | Reference only |
| F8 | Medication safety event | P2 | wave 3 | Reference only |
| F9 | Falls/pressure injury prevention process | P3 | wave 4 | Reference only |
| F10 | Incident-to-CAPA | P2 | wave 3 | Reference only |
| F11 | Readmission/cross-encounter transition | P2 | wave 3 | Reference only |
| F12 | Equity in access and flow | P2 | wave 3 | Reference only |
| F13 | Measure-evidence lineage | P1 | wave 2 | Source present |
| G1 | Utilization review / continued stay | P2 | wave 3 | Reference only |
| G2 | Charge capture and coding | P3 | wave 4 | Reference only |
| G3 | Claim submission and denial | P3 | wave 4 | Reference only |
| G4 | Patient financial assistance/counseling | P3 | wave 4 | Reference only |
| G5 | Credentialing and privileging | P3 | wave 4 | Reference only |
| G6 | Hiring/onboarding/education compliance | P3 | wave 4 | Reference only |
| G7 | Procurement-to-pay | P3 | wave 4 | Reference only |
| G8 | Multi-facility transfer/load balancing | P1 | wave 2 | Source present |
| G9 | Service-line longitudinal journey | P2 | wave 3 | Reference only |
| G10 | Ambulatory-inpatient transition | P2 | wave 3 | Reference only |
| G11 | Data-feed and application reliability | P1 | wave 2 | Source present |
| G12 | Downtime and recovery | P2 | wave 3 | Reference only |
| H1 | Signal-to-alert lifecycle | P1 | wave 1 | Source present |
| H2 | Recommendation-to-action | P1 | wave 1 | Source present |
| H3 | Huddle action closure | P1 | wave 1 | Source present |
| H4 | PDSA/intervention lifecycle | P1 | wave 1 | Source present |
| H5 | Reference-model governance | P1 | wave 2 | Reference only |
| H6 | Model-review and publication | P1 | wave 2 | Reference only |
| H7 | Data-quality issue-to-correction | P0 | foundation | Reference only |
| H8 | Forecast/recommendation reconciliation | P1 | wave 2 | Source present |

## Appendix B — Validation evidence for the delivered tranche

- `HospitalProcessCatalogTest`: 3 tests, 1,962 assertions.
- `OcelProcessLandscapeApiTest`: 3 tests, 22 assertions.
- `processLandscapeSchema.test.ts`: 2 tests passed.
- Production Vite build: passed, 7,827 modules transformed.
- Seeder idempotency: two consecutive runs converged on 93 models, 558 nodes, and 465 edges.
