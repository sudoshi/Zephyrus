# Part X — Object-Centric Process Intelligence & the Patient-Flow Arena

> **Promoted into version control 2026-07-04.** This is the tracked companion
> volume to [ZEPHYRUS-2.0-PLAN.md](./ZEPHYRUS-2.0-PLAN.md) (Parts I–IX). Its
> prose master lives in the PDF publishing pipeline (`zephyrus_build/partx_doc.md`,
> git-ignored); this file is the canonical, reviewable source of record. Figure
> embeds are rendered here as captions — the illustration assets remain in the
> publishing pipeline. Where this document and the master plan conflict, the
> master plan's Part II precedence rule still governs.

## Execution Status (living)

| Phase | Scope | Status | Evidence |
|---|---|---|---|
| **X0** | OCEL 2.0 foundation — `ocel.*` schema + `OcelProjector` + emission map + `RefreshOcelLog` + validated OCEL-JSON export | **SHIPPED (dev), 2026-07-04** | commit `ef6c238`; conformance seed `b83f812`. Reconciliation 1:1 (flow_events 1569→1569, milestones 1645→1645, transport 22→39 phases); 3253 events / 1156 objects (7 types) / 9285 E2O / 842 O2O; idempotent; OCEL-JSON export structurally valid + PHI-safe; `EmissionMapTest` 8/43 green; Pint clean |
| **X1** | Arena MVP — Python OCPM sidecar (`pm4py`/`ocpa`); auto-discovered OC-DFG maps in the Study altitude | PENDING | needs sidecar packaging decision (§X.4.2 open question); depends on P5 |
| **X2** | OPerA object-centric bottleneck analytics → object-centric cockpit tiles | PENDING | depends on P2 |
| **X3** | Conformance + safety guardrails (sepsis + surgical-safety pathways; online prefix-alignment) → cockpit → Eddy | PENDING (data ready) | the X0 log already carries the sepsis/stroke/WHO-safety corpus from `b83f812`; depends on P6 |
| **X4** | AI copilot — governed map authoring, narratives, NL query, PDSA drafts | PENDING (ships disabled) | depends on P6 |

**What X0 supersedes.** The app today ships a *mock* object-centric surface —
`/improvement/process` ("Process Analysis (OCEL)") reads static
`sample-pages/OCEL/*.json` via `ProcessAnalysisController`, and
`DashboardService::getRootCauses()` is a curated array (see the completeness
audit + `docs/architecture/RootCauseImprovementWorkflow.md`). Part X §X.4.3 retires these in
favour of the discovered, live equivalents. X0 is the real projection
foundation that makes that possible: the `ocel.*` log is now generated from
assets already on `main`, not authored by hand.

**Provenance note.** The clinical-pathway conformance data the Arena mines
(sepsis bundle, acute-stroke pathway, WHO surgical-safety checklist) is
synthesised by `database/seeders/ClinicalPathwaySeeder.php` (commit `b83f812`),
with clinically-correct LOINC / RxNorm / ICD-10 codes and a deliberate
conformant/deviant mix so conformance checking (X3) has real deviations to catch.

---

# Executive Summary

> **Object-centric, AI-assisted, and governed.** Part X extends the Zephyrus 2.0 cohesion thesis from *rendering* the state of the house to *understanding the processes that produce it*. It embeds the current best practice in process science — **OCEL 2.0** object-centric event logs and **object-centric process mining (OCPM)** — behind a single AI-assisted workbench, the **Patient-Flow Arena**, that automatically generates process maps and analytics to maximize patient safety, quality, and flow. It is additive, flag-gated, PHI-safe, and human-governed — the same discipline as the rest of the plan.

Zephyrus 2.0 gives the house *one truth, computed once*: a single `StatusEngine`, one `cockpit_snapshots` row, one Eddy loop. Part X asks the next question. A cockpit tile can tell a charge nurse that boarding is `crit` — but it cannot, today, tell her **why the process is failing, where the hand-off broke, or which change would fix it.** The Process-Improvement module that should answer this is the thinnest layer in the app: process maps load from static JSON, root causes are a hardcoded PHP array, and PDSA stages are synthesized from a modulo of the cycle ID (Part I, §3.4). The one genuinely-live signal, `DashboardService::getBottleneckStats()`, is buried behind `/improvement/bottlenecks` and never reaches the overview.

Part X replaces that scaffolding with a **living, discovered model of how the hospital actually runs**, mined directly from the event data Zephyrus already produces. The method is object-centric on purpose. A hospital is not one process with one "case"; it is many objects — patients, encounters, beds, OR cases, transport jobs, EVS tasks, orders, staff assignments — whose lifecycles collide at exactly the hand-offs where flow breaks. Forcing that reality into a single case notion (the universal habit of classic process mining) **systematically distorts the very bottlenecks we most need to see**. Object-Centric Process Mining, formalized over the last five years and now standardized as **OCEL 2.0** (2024), keeps every object in place and measures the interactions between them. The first published application of OCPM to healthcare appeared in 2024; Part X operationalizes it on a live, deployed platform.

> **Figure.** From a single forced case notion to object-centric reality: one event touches many objects, and flattening to one "case" distorts counts, loops, and dropped events. OCEL keeps every object in place.

**The Patient-Flow Arena** is the delivery vehicle. It is a new surface at the **Study (A3) altitude** where, on a schedule and on demand, the platform (1) projects an **OCEL 2.0 log** from the RTDC event store and the `prod.*` fact tables, (2) **auto-discovers** object-centric process maps (OC-DFG and object-centric Petri nets), (3) runs **OPerA** object-centric performance analytics to localize bottlenecks at object intersections, (4) **conformance-checks** live behavior against safety-critical clinical pathways using online prefix-alignment, and (5) lets an **AI copilot** author the maps, write plain-language narratives, and draft PDSA hypotheses — every one of which a human approves through the existing Eddy governance gate. Nothing is auto-enacted; the copilot holds `ops:draft` and never `ops:approve`, exactly as Eddy does, and the whole subsystem ships behind `ARENA_ENABLED` (default off).

The payoff is a closed improvement loop that finally matches the cockpit's closed action loop: **observe → discover → diagnose → check → propose → approve → intervene → re-measure.** The cockpit tells you the house is sick; the Arena tells you which process is failing, proves it with the data, proposes the fix, and measures whether the fix worked — all in the canon design language, on the same snapshot cadence, through the same approval spine.

| What Part X adds | Built on (already in Zephyrus) | Net-new |
|---|---|---|
| OCEL 2.0 event log of the whole hospital | RTDC event sourcing · `prod.*` fact tables · `flow_core.*` | `ocel.*` schema + `OcelProjector` |
| Object-centric discovery & maps | Process-Improvement module · Study (A3) altitude | Python OCPM sidecar (pm4py / ocpa) |
| Object-centric performance & bottlenecks | `getBottleneckStats()` · `StatusEngine` · cockpit tiles | OPerA metrics → live bottleneck signals |
| Patient-safety conformance guardrails | `StatusEngine` · AlertEngine · Eddy loop | Reference pathways + online prefix-alignment |
| AI-assisted map & PDSA generation | Eddy `ops.agent_*` control plane · `PdsaCycle` bridge | Governed copilot (ProMoAI-style) |

---

# X.1 — The Case for Object-Centric Process Intelligence

**Decision.** Adopt **object-centric** process mining (OCEL 2.0 / OCPM) as the foundation for all process analysis in Zephyrus, rather than classic single-case process mining. Every map, every performance number, and every conformance check the Arena produces is computed object-centrically; flattening to a single case notion is permitted only as a deliberate, labeled *view*, never as the source of truth.

*Rationale.* Patient flow is irreducibly multi-object, and the literature is now unambiguous that flattening such data corrupts the analysis. This is not a stylistic preference; it is the difference between a bottleneck chart a CMO can act on and one that quietly lies.

### X.1.1 Why a hospital has no single "case"

Classic process mining requires you to pick one **case notion** — one identifier that every event belongs to — and then builds a model of how that one thing flows. Pick "the patient" and you can draw a patient journey. But the patient journey is not where flow breaks. Flow breaks at the **hand-offs between objects**: a patient who is ready to move but has no clean bed; an OR case finished but no PACU bay; a discharge order written but no transport; a bed vacated but no EVS turn. Each of those failures is an *interaction between two object lifecycles*, and a single-case model cannot represent an interaction — by construction it only sees one object at a time.

When you force multi-object data into one case, three well-documented pathologies appear (van der Aalst, 2019):

- **Convergence** — an event that legitimately touches several objects (one cleaning event that frees a bed used by two encounters across a day) is **duplicated**, once per case, inflating frequencies and making rare events look common.
- **Divergence** — repeated sub-activities (multiple orders, multiple transports within one encounter) **tangle into false loops** in the model, manufacturing rework that did not occur.
- **Deficiency** — events that have no value for the chosen case id (a bed-turnaround event, a staffing change) are **silently dropped**, so the analysis is blind to exactly the logistics that govern flow.

A boarding analysis flattened to "patient" will under-count bed-turn delays (deficiency), double-count shared cleanings (convergence), and invent transport loops (divergence). The chart looks authoritative and is wrong in the direction that matters most.

### X.1.2 The object-centric answer

OCPM removes the case notion entirely. Events reference **as many objects as they truly touch**, through typed and qualified relationships, and the model is discovered over all of them at once (Berti & van der Aalst, *OC-PM*). The result is a model that shows each object type's lifecycle *and the synchronizations between them* — which is precisely the layer where a hospital's flow problems live. The 2024 transformation of the OMOP Common Data Model into OCELs (Park, Lee & Cho, *J. Biomed. Inform.*) — the first application of OCPM to healthcare — demonstrated the approach end-to-end on MIMIC-IV, identifying object types, clinical activities, and their relationships, and concluded that the object-centric view "transcends the constraints of conventional patient-centric process mining."

### X.1.3 Why Zephyrus is unusually ready for this

Most organizations cannot adopt OCPM cheaply because they have no clean event data; they must reverse-engineer events from database deltas. Zephyrus does not have that problem. **RTDC already runs production-grade event sourcing** (Part I, §1), the `prod.*` fact tables are timestamped operational truth, and `flow_core.*` already projects state transitions. The events that an OCEL needs *already exist*; Part X projects them into the OCEL shape rather than instrumenting anything new. This mirrors the master plan's central finding — 2.0 is a *consolidation and connection* effort — and extends it: **the object-centric event log is a projection of assets already on `main`, not a new data-collection program.**

### X.1.4 The cohesion connection

Part X is the natural completion of Principle 7 ("One Truth, Computed Once"). The cockpit computes the *state* once; the Arena discovers the *process* once. Both read the same underlying events; both feed the same Eddy loop; both render in the same canon. Where the master plan unified six dashboards into one cockpit, Part X unifies the scattered, mostly-mock Process-Improvement surfaces into one discovered model — the seventh-and-a-half unification.

## Risks

- Object-centric modeling has a steeper conceptual learning curve than swim-lane diagrams; clinicians and analysts must be onboarded with the *flattened views* as an on-ramp before the full object-centric map. Mitigation: the Arena always offers a per-object-type "flatten for readability" view, clearly labeled as a projection.
- The object and activity catalog (X.2) is a modeling commitment; a wrong object-type choice propagates into every downstream map and metric. Mitigation: ratify the catalog with clinical and operations stakeholders before X1, and version it.

## Open Questions

- Which clinical object granularity is canonical for "the patient over time" — `Patient` (person-level, longitudinal) vs `Encounter` (visit-level)? Part X models both with an O2O link, but the default lens for flow analytics needs a house decision.
- Do we ever need a true person-level longitudinal view (readmissions, repeat surgical patients) at A3, or is encounter-level sufficient for v1 of the Arena?

---

# X.2 — The OCEL 2.0 Foundation

**Decision.** Standardize on **OCEL 2.0** as the event-data format for all process intelligence, using its **relational (SQLite/Postgres)** representation as the system of record inside an additive `ocel.*` schema, with JSON/XML export for interoperability with `pm4py` and `ocpa`. Model the hospital with an explicit, versioned **object-type and activity catalog**.

*Rationale.* OCEL 2.0 (2024) is the current community standard for object-centric event data and is fully supported by the mature open-source tooling (`pm4py`, `ocpa`). Adopting the standard — rather than a bespoke event shape — buys Zephyrus the entire OCPM toolchain for free and keeps the data portable and auditable.

### X.2.1 The OCEL 2.0 metamodel

OCEL 2.0 (Berti, Koren, Park, et al.; `ocel-standard.org`) is built from six elements. Relative to OCEL 1.0 it adds object-to-object relationships, qualifiers on relationships, and time-varying object attributes — the three features a hospital model genuinely needs.

| Element | Meaning | Zephyrus example |
|---|---|---|
| **Event** | An execution of an activity at a timestamp, with attribute values | "Place patient in bed" @ 14:32, by RN_07 |
| **Object** | A uniquely identified instance of an object type, with time-varying attributes | `bed-5West-12`, status changing over time |
| **Object type** | The class an object belongs to, with an attribute schema | `Bed`, `Encounter`, `OR Case` |
| **Activity** | The type of an event | "Admit", "Bring to OR", "Turn bed" |
| **E2O relationship** | Links an event to an object it acted on/with — **qualified** | event → `RN_07` qualified *actor*; → `patient-9` qualified *subject* |
| **O2O relationship** | Links two objects without a shared event — **qualified** | `Encounter` *occupies* `Bed`; `Bed` *in* `Unit` |

Two OCEL 2.0 features are load-bearing for healthcare. **Qualified relationships** let one event distinguish the nurse (actor), the patient (subject), and the bed (resource) — the role disambiguation a clinical model demands. **Time-varying object attributes** capture a bed moving through `dirty → cleaning → ready → occupied`, which is the raw material for bed-turnaround analytics. OCEL 2.0 offers three interchange formats — relational (SQLite), XML, and JSON; Zephyrus uses the relational form natively and exports JSON for the Python tooling.

> **Figure.** The Zephyrus OCEL object model: object types (nodes) and the qualified object-to-object relationships (edges) that bind them. Events connect to objects through qualified E2O links.

### X.2.2 The Zephyrus object-type catalog

The following object types are the v1 catalog. Each maps to data Zephyrus already holds, so projection (X.3) requires no new instrumentation. The catalog is versioned in `ocel.object_types` and ratified with stakeholders.

| Object type | Lens | Source of truth (existing) | Key time-varying attributes |
|---|---|---|---|
| `Patient` | clinical (person) | RTDC / `prod.*` identity (de-identified) | acuity band |
| `Encounter` | clinical (visit) | `prod.ed_visits`, admissions | LOS, status, service line |
| `Bed` | space | RTDC `BedTrackingService` | status (dirty/cleaning/ready/occupied) |
| `Unit` | space | `HospitalManifest` (25-unit SSOT) | census, staffed beds |
| `OR Case` | surgical | `prod.or_logs`, `RoomStatusService` | phase, delay_min |
| `OR Suite` | surgical | `RoomStatusService` (18 suites) | status, running/turnover |
| `Transport Job` | logistics | `prod.transport_requests` | state (requested→pickup→done) |
| `EVS Task` | logistics | `EvsOperationsService` | started_at / completed_at |
| `Order` | clinical | orders facts | status |
| `Staff Assignment` | resource | `StaffingOperationsService` | role, ratio |
| `Alert` | governance | `cockpit_alerts` (Part VI) | status, opened/cleared |
| `PDSA / Intervention` | governance | `PdsaCycle` → `Ops::Intervention` bridge | stage, outcome |

The last two object types are deliberate: by making **alerts and interventions first-class objects in the same log**, the Arena can mine *the improvement process itself* — answering "when we flag a barrier, what actually happens next, and does the metric move?" — which is exactly the attribution loop the `PdsaCycle → Ops::Intervention → InterventionMetric → OutcomeAttribution` chain was built for but never surfaced (Part I, §3.4).

### X.2.3 The activity catalog

Activities are the verbs. v1 covers the flow-critical hand-offs; the catalog grows additively.

| Domain | Representative activities | Objects touched (E2O) |
|---|---|---|
| ED | triage, bed-request, provider-seen, admit-decision, board, depart | Encounter, Patient, Bed |
| RTDC / placement | request-bed, assign-bed, place, transfer | Encounter, Bed, Unit |
| Perioperative | schedule, wheels-in, procedure, wheels-out, PACU-in, PACU-out | OR Case, OR Suite, Encounter, Bed |
| Transport | request, accept, pickup, dropoff | Transport Job, Encounter, Bed |
| EVS | dirty, start-clean, ready | EVS Task, Bed |
| Discharge | discharge-order, lounge, depart, bed-vacated | Encounter, Bed, Transport Job |
| Governance | alert-open, propose-action, approve, intervene, alert-clear | Alert, PDSA/Intervention, Encounter |

## Risks

- Over-modeling: too many object types or qualifiers makes maps unreadable and projection expensive. Mitigation: ship the v1 catalog above and add types only when a concrete analytical question demands one.
- Standard drift: OCEL 2.0 is young; tooling details still move. Mitigation: pin `pm4py`/`ocpa` versions, treat the relational `ocel.*` store as the contract, and treat JSON/XML as disposable export.

## Open Questions

- Do we store object attribute history in OCEL `object_changes` for *every* attribute, or only the flow-critical ones (bed status, OR phase)? Full history is faithful but heavier.
- Is `Order` worth modeling in v1, or deferred — it is high-volume and risks divergence if its granularity is wrong.

---

# X.3 — Automatic OCEL Generation: The Emission Layer

**Decision.** Generate the OCEL log **automatically** by projecting it from existing event sources — never by hand-instrumenting new logging. Add an additive `ocel.*` schema (OCEL 2.0 relational layout) and an `OcelProjector` that consumes the **RTDC event store**, the `prod.*` fact tables, and `flow_core.*`, writing OCEL events, objects, and qualified relationships. De-identify object identities at projection time so the log is PHI-safe by construction.

*Rationale.* "Automatically generate OCEL process mapping" is the heart of the request, and the only sustainable way to do it is **projection from a single source of operational truth**. Zephyrus already event-sources RTDC and already lands timestamped facts in `prod.*`; the projector is a read-side consumer of those, exactly like `RefreshCockpitSnapshot` is for the cockpit. This keeps the OCEL log consistent with the cockpit (same events, same clock) and means the map is never staler than the data.

### X.3.1 The `ocel.*` schema (additive, OCEL 2.0 relational)

One additive migration using the `SafeMigration` trait with `Schema::hasColumn` guards (matching the master plan's data-protection discipline), in a dedicated `ocel` schema so it never touches `prod.*`:

```sql
-- OCEL 2.0 relational core (simplified)
ocel.events(            id, activity, timestamp, attrs jsonb )
ocel.objects(           id, type, attrs jsonb )
ocel.object_changes(    object_id, attr, value, timestamp )   -- time-varying
ocel.event_object(      event_id, object_id, qualifier )       -- E2O (qualified)
ocel.object_object(     from_id, to_id, qualifier )            -- O2O (qualified)
ocel.object_types(      type PK, lens, source_system, version )
ocel.activities(        activity PK, domain )
```

This is the canonical OCEL 2.0 relational shape; `pm4py` and `ocpa` read it directly, and it exports losslessly to OCEL-JSON/XML. The `attrs jsonb` columns hold activity/object attributes without a per-type table explosion; `object_changes` carries the time-varying values (bed status, OR phase) that OCEL 1.0 could not represent.

### X.3.2 The projector

`app/Domain/Ocel/OcelProjector.php` is a read-side projection — the OCPM analogue of `SnapshotBuilder`. It subscribes to the same domain events the cockpit already reacts to and appends OCEL rows; for facts that are not event-sourced, it tails `prod.*` by timestamp watermark.

```
OcelProjector::project(window):
  foreach domain_event in eventStore.since(watermark):
      e = ocel.events.insert(activity, ts, attrs)
      foreach (object, qualifier) in map(domain_event):   # catalog-driven
          ocel.objects.upsert(object); ocel.event_object.insert(e, object, qualifier)
      foreach (a,b,qualifier) in o2o(domain_event):
          ocel.object_object.upsert(a, b, qualifier)
      recordAttributeChanges(domain_event)                 # bed status, OR phase
  advance(watermark)
```

A declarative **emission map** (catalog-driven, one entry per domain event) keeps the projector thin and the modeling explicit:

| Domain event (existing) | OCEL activity | Objects + qualifiers |
|---|---|---|
| `CensusUpdated` (RTDC) | place / transfer | Encounter (subject), Bed (resource), Unit |
| ED `admit-decision` | admit-decision | Encounter (subject), Patient, Bed (target) |
| `or_logs` phase change | wheels-in / out | OR Case (subject), OR Suite (resource), Encounter |
| `transport_requests` row | request / pickup / dropoff | Transport Job (subject), Encounter, Bed |
| EVS `started/completed` | start-clean / ready | EVS Task (subject), Bed (resource) |
| `cockpit_alerts` open/clear | alert-open / clear | Alert (subject), Encounter |
| `PdsaCycle` stage change | propose / intervene | PDSA (subject), Encounter, Alert |

### X.3.3 Scheduling and freshness

`RefreshOcelLog` (`ShouldQueue`) runs on the existing `bootstrap/app.php` `withSchedule` hook beside `RefreshCockpitSnapshot` — every 5–15 minutes for the incremental projection, with a nightly full reconcile. Because the projector reads the **same** event store as the cockpit, the OCEL log and the cockpit snapshot are derived from one clock, so a bottleneck the Arena discovers at A3 is provably the same reality the cockpit shows at A0 — the live/analytic story stays singular (Principle 7).

### X.3.4 PHI safety by construction

The OCEL log is **aggregate-and-operational, not clinical-detail**. Object identities are de-identified at projection (`patient-<hash>`, `bed-<unit>-<n>`); no names, MRNs, diagnoses, or note text enter `ocel.*`. This matches the established PHI-free aggregate-only broadcast discipline (Part VI, §6) and HIPAA minimum-necessary: the Arena reasons about *timing and sequence of operational activities*, which need no PHI. Where a clinical attribute is genuinely required for a pathway check (e.g., "lactate ordered"), it is stored as a typed boolean/coded flag, never free text. The COPE OMOP CDM is a second, already-standardized source the same projector pattern can consume (per Park et al.'s OMOP→OCEL method) if longitudinal person-level analysis is ever needed.

## Risks

- Volume: at ~80 metrics' worth of activity across a 500-bed house, `ocel.*` grows quickly. Mitigation: partition `ocel.events` by month, retain a rolling window (e.g., 13 months) for trend depth, and archive older partitions; the `object_changes` table needs the heaviest retention discipline.
- Projection correctness: a wrong qualifier or a missed O2O link corrupts every downstream map. Mitigation: the emission map is unit-tested (PHPUnit class syntax, per the Pest constraint) against fixture events with asserted OCEL output; a nightly reconcile diffs projected counts against `prod.*` source counts.
- Watermark gaps: if the projector lags or a deploy interrupts it, the log has holes. Mitigation: idempotent upserts keyed by source event id, and a reconcile job that backfills.

## Open Questions

- Incremental cadence: is 5–15 min projection sufficient for the Arena's analytics, or do the online-conformance safety guardrails (X.7) need a tighter near-real-time tail off the event stream?
- Do we project directly from the RTDC event store, or interpose a durable outbox so the projector and the cockpit never contend on the same read model?

---

# X.4 — The Patient-Flow Arena

**Decision.** Build the **Patient-Flow Arena** as a new surface at the **Study (A3) altitude** (`/analytics/arena`) that orchestrates the full OCPM pipeline — generate OCEL → discover maps → analyze performance → check conformance → AI narrative → human review — and renders every result through the canon `cockpit/*` primitives. The heavy mining runs in an isolated **Python OCPM sidecar** (`pm4py` / `ocpa`); the PHP app orchestrates, the React Study UI presents, and the Eddy control plane governs. The whole subsystem is gated by `ARENA_ENABLED` (default off) and ships disabled, exactly like Eddy.

*Rationale.* The request is for "an arena where we can automatically generate AI-assisted OCEL process mapping and analytics." That is a *workbench*, not a single chart — a place where the maps are continuously regenerated, the analytics are always current, and a human can interrogate them and act. The mature OCPM toolchain (`pm4py`, `ocpa`, OPerA) is Python; PHP cannot run it natively. Rather than reimplement process mining, Part X isolates it in a sidecar service the way any platform isolates a specialist engine, and reuses everything else Zephyrus already has — the Study altitude, the snapshot cadence, the design canon, and the Eddy gate.

### X.4.1 What "the Arena" is

The Arena is a single A3 surface with four panes, all driven off one freshly-projected OCEL log:

1. **Map pane** — auto-discovered object-centric process maps (OC-DFG / object-centric Petri net), per object type and at intersections, with variant analysis and a Performance-Spectrum view.
2. **Performance pane** — OPerA object-centric metrics (flow / synchronization / pooling / lagging / waiting / service / sojourn times) localized to the hand-offs where flow breaks (X.6).
3. **Conformance pane** — live adherence of behavior to safety-critical reference pathways, with deviations highlighted and ranked (X.7).
4. **Copilot pane** — an AI assistant that authored the maps and narratives, answers natural-language questions over the log, and drafts PDSA hypotheses for human approval (X.8).

> **Figure.** The Patient-Flow Arena: an automatic, AI-assisted OCEL pipeline from existing event sources to discovered maps, performance, conformance, and governed AI narratives — reusing the Study altitude, the snapshot cadence, and the Eddy control plane.

### X.4.2 Architecture — the sidecar and the seam

The Arena is three cooperating pieces, each with a clean boundary:

| Tier | Runs | Responsibility |
|---|---|---|
| **Orchestrator** (PHP) | Laravel, existing app | Schedule refresh; call the sidecar; persist results to `arena.*`; expose `/api/arena/*`; enforce auth + the Eddy gate |
| **OCPM sidecar** (Python) | `FastAPI` + `pm4py`/`ocpa`, containerized | Read `ocel.*` (or OCEL-JSON); run discovery, OPerA, conformance; return canon-shaped JSON (nodes/edges/metrics) |
| **Study UI** (React) | Inertia, canon `cockpit/*` | Render maps as SVG via `STATUS_VAR`; tables via `DataTable`; drills via `DrillModal`; copilot chat |

The sidecar is **stateless and read-only** over a de-identified OCEL export — it never touches `prod.*` and holds no PHI, so its blast radius is contained. The orchestrator caches sidecar results in `arena.maps` / `arena.metrics` / `arena.deviations` keyed by OCEL window, so the Study UI reads a cache (mirroring the `/cockpit/snapshot` pattern), not a live mining run.

### X.4.3 How it lands in the Information Architecture

The Arena is the realization of the master plan's **Study altitude** for process intelligence (Part III). It absorbs the mostly-mock Process-Improvement surfaces: the static-JSON process maps, the hardcoded root-cause array, and the modulo-synthesized PDSA stages (Part I, §3.4) are **retired in favor of discovered, live equivalents**. The one live PI asset, `getBottleneckStats()`, is promoted: its five signals become object-centric and feed both the Arena and the cockpit Quality/Flow tiles (X.6). Navigation reuses the existing SSOT (`navigationConfig.ts`): the Arena is a STUDY entry, reachable from the Study section and from a "study the process →" affordance on the cockpit's bottleneck tiles.

### X.4.4 The automatic generation loop

"Automatic" means the maps and analytics regenerate without a human asking. The loop, on the existing scheduler:

```
every refresh:
  ocel   = OcelProjector.project(window)          # X.3
  maps   = sidecar.discover(ocel)                  # OC-DFG / OC-Petri-net
  perf   = sidecar.opera(ocel, maps)               # flow/sync/pool/lag
  devs   = sidecar.conformance(ocel, pathways)     # online prefix-align (X.7)
  story  = copilot.narrate(maps, perf, devs)       # ProMoAI-style (X.8, gated)
  persist(arena.*); signal cockpit tiles; queue Eddy drafts for human review
```

Discovery, performance, and conformance run **unattended**; the AI narrative and any proposed intervention are **drafted unattended but enacted only after human approval** through the Eddy FSM. This is the request's "automatically generate … to ensure maximal patient safety, quality and flow," realized within the plan's advice-not-autopilot contract.

## Risks

- A Python sidecar is a new operational dependency (a second supervised process / container) on an Apache + php-fpm box. Mitigation: ship it as a sibling container with health checks; the Arena degrades gracefully to "last good maps" if the sidecar is down, never white-screening (the `safeParse` discipline).
- Mining latency: object-centric discovery over a large OCEL can be slow. Mitigation: bound the OCEL window (e.g., trailing 30–90 days), pre-compute on the schedule, cache, and offer on-demand re-mine only for a scoped sub-log.

## Open Questions

- Sidecar packaging: a long-running `FastAPI` service, or an on-demand job container invoked per refresh? The former is lower-latency; the latter is simpler to supervise.
- Does the Arena need its own RBAC tier (process-analyst role) distinct from the cockpit personas, or does the existing `executive`/`command` persona model suffice at A3?

---

# X.5 — Object-Centric Discovery & Process Maps

**Decision.** Auto-discover and render **object-centric process maps** — the **OC-DFG** (object-centric directly-follows graph) as the default readable map and **object-centric Petri nets** for formal analysis — per object type and, crucially, at **object intersections**. Provide object-centric variant analysis and a Performance-Spectrum view. Render everything in the canon (Figtree, `tabular-nums`, four status colors + grey, SVG fills via `STATUS_VAR`).

*Rationale.* The OC-DFG is the established, widely-used representation for object-centric models (Berti & van der Aalst); it shows each activity once, with arcs colored/weighted per object type, so a reader sees the patient lifecycle, the bed lifecycle, and where they synchronize in one picture. This is exactly the "process mapping" the request asks to automate, and `pm4py`/`ocpa` discover it directly from the OCEL log.

### X.5.1 The maps the Arena ships

| Map | Object types | The flow question it answers |
|---|---|---|
| **ED journey** | Encounter, Patient, Bed | Where do arrivals stall — triage, provider, or the admit→bed hand-off? |
| **Perioperative** | OR Case, OR Suite, Encounter, Bed | Where is OR throughput lost — turnover, PACU pooling, or ward beds? |
| **Discharge-to-empty-bed** | Encounter, Bed, Transport Job, EVS Task | The full bed-recycle: order → depart → vacate → clean → ready → next |
| **Transport network** | Transport Job, Encounter, Bed | Dispatch and wait structure across the house |
| **Sepsis / safety pathway** | Encounter, Order | Did the bundle steps happen, in order, in time? (feeds X.7) |
| **Improvement loop** | Alert, PDSA/Intervention, Encounter | When we flag a barrier, what happens, and does the metric move? |

### X.5.2 Discovery technique

The sidecar uses `pm4py`'s object-centric discovery for the OC-DFG and object-centric Petri nets, and `ocpa` for analysis that needs the object graph. Maps carry **frequency and performance overlays** (arc width = event-pair frequency for the object type; arc color = a `StatusEngine`-derived band, so a slow hand-off renders amber/coral in the same vocabulary as the cockpit). Object-centric **variant analysis** answers "what are the common vs rare paths a bed takes?" without the divergence artifacts a flattened variant analysis would invent. A **Performance-Spectrum** view (per the current Celonis/academic best practice) shows the distribution of a hand-off's duration over time, exposing shift-level and seasonal patterns a single average hides.

### X.5.3 Rendering in the canon

Discovered maps are rendered as **SVG through `STATUS_VAR`** (the documented data-viz exemption), never raw hex — so the Arena's maps obey the same Earned-Color discipline as the cockpit: a calm process map reads near-monochrome, and saturated color marks a slow or non-conformant hand-off. Auto-layout uses an object-centric DFG layout pass; nodes are canon `Panel`/`Tile` primitives, detail tables are `DataTable`, and a node opens a `DrillModal` into the underlying object instances. The map is an A3 surface that descends to A1 reality: clicking the "admit→bed" hand-off drills to the live RTDC placement board.

## Risks

- OC-DFG readability degrades with too many object types on one canvas. Mitigation: default to two or three object types per map (the ones that actually synchronize there), with a control to add/remove a lifecycle.
- Auto-layout instability: small data changes can reshuffle the graph, eroding trust. Mitigation: stable layout seeds keyed by the map definition, so the same map looks the same week to week.

## Open Questions

- Do we expose object-centric Petri nets to end users at all, or keep them internal (for conformance) and show only OC-DFGs to clinicians?
- Is a third-party graph renderer acceptable for the SVG layout, or must layout also obey the no-external-token canon rule end to end?

---

# X.6 — OPerA: Object-Centric Performance & Bottleneck Analytics

**Decision.** Localize bottlenecks with **OPerA** object-centric performance analysis — computing **flow, synchronization, pooling, and lagging** times (alongside waiting, service, and sojourn) at the object intersections — and promote the resulting signals into the cockpit as the **live, object-centric successor to `getBottleneckStats()`**.

*Rationale.* Classic performance analysis assumes one case and therefore mis-measures any metric that depends on object interaction — most importantly *waiting time at a hand-off* (Park, Berti & van der Aalst, *OPerA*, 2022). In a hospital, the bottleneck is almost always an interaction: a patient waiting for a bed, a bed idling for a patient, OR cases pooling at a PACU. OPerA defines exactly the metrics that make those interactions measurable, and `ocpa` implements them.

### X.6.1 The object-centric performance metrics

| Metric | What it measures | Zephyrus bottleneck it exposes |
|---|---|---|
| **Flow time** | Total elapsed across an object's path through an activity | Admit-decision → placed (true boarding duration) |
| **Synchronization time** | How long the *earlier-ready* object waits for the *later* one to arrive at a shared event | Patient ready, waiting for a clean bed |
| **Pooling time** | How long objects of one type accumulate waiting for a shared downstream step | OR cases pooling for a PACU bay |
| **Lagging time** | How long an object sits idle because a *partner* object is late | Clean bed idle, waiting for the patient/transport |
| **Waiting / service / sojourn** | Classic measures, but computed correctly object-centrically | Per-activity dwell without convergence inflation |

The power is that these are **not derivable from a flattened log**. Flatten-by-patient and the bed's *lagging time* vanishes (the bed is not the case); flatten-by-bed and the patient's *synchronization wait* vanishes. OPerA computes both from the one object-centric log, so the Arena can finally answer "is boarding a patient-side or a bed-side problem?" — the question that determines whether the fix is staffing, EVS, or placement policy.

> **Figure.** Object-centric performance at the ED-admit ↔ bed-ready hand-off: synchronization time (patient waits for the bed), lagging time (bed idle, waiting for the patient), and flow time. A flattened view hides one side of every hand-off; OPerA measures both.

### X.6.2 From OPerA to the cockpit

The Arena's OPerA metrics close a gap the master plan flagged but could not fill: the cockpit's bottleneck tiles are today fed by `getBottleneckStats()`, the lone live PI signal, computed single-case. Part X **promotes them to object-centric**: the same five bottleneck signals are recomputed from the OCEL log with OPerA, registered as `kpi_definitions` (Part VI), resolved by the one `StatusEngine`, and surfaced on the cockpit Quality/Flow panels. A `crit` boarding tile at A0 now drills (A2 → A3) into the OPerA breakdown that *proves which side of the hand-off* is failing.

| OPerA signal | `kpi_definitions` key | Cockpit surface |
|---|---|---|
| ED admit→bed synchronization time | `flow.boarding_sync_min` | ED / Capacity panel |
| Bed turnaround lagging (EVS↔placement) | `flow.bed_turn_lag_min` | RTDC / Flow panel |
| PACU pooling time | `periop.pacu_pool_min` | Perioperative panel |
| Discharge→vacate flow time | `flow.dc_to_vacate_min` | Flow panel |
| Transport request→pickup wait | `flow.transport_wait_min` | Transport panel |

This is the bridge between A0 and A3 made literal: the cockpit shows the *number*; the Arena shows the *object-centric anatomy* of that number; both read the same engine, so they never disagree.

## Risks

- OPerA metrics are subtle; a synchronization time presented without its object context can mislead as easily as it informs. Mitigation: every OPerA tile drills to the intersection diagram (X.6.1 figure pattern) that shows *which* objects are waiting on which.
- Denominator drift versus the master plan's existing prime-time/utilization definitions (P5; Part I, §3.3). Mitigation: reconcile OPerA outputs against the post-P5 single-authority metrics before promoting any tile.

## Open Questions

- Which OPerA metric is the single best house-level "flow health" composite for the cockpit, if any — or do we keep them as distinct per-domain tiles?
- Should OPerA percentiles (p50/p90) drive the bands, given averages hide the surge tail that matters for safety?

---

# X.7 — Conformance & Patient-Safety Guardrails

**Decision.** Treat **patient safety as conformance**. Encode safety-critical care pathways (sepsis bundle, stroke, surgical safety checklist, discharge criteria) as **reference process models**, and continuously check live behavior against them with **online (streaming) prefix-alignment** conformance checking. A real, observed deviation becomes a `watch → warn` cockpit signal and, where appropriate, a drafted Eddy correction — through the human gate, never auto-enacted.

*Rationale.* Conformance checking is the established process-mining method for measuring guideline adherence; it has been applied to oncology pathways (rectal cancer; melanoma surveillance) and to COVID-19 care, precisely to surface where real practice deviates from the standard. For a *live* safety guardrail, the offline form is not enough — you must detect a missed or late safety step *while the patient is still in the pathway*. Online conformance checking via incremental prefix-alignments was developed for exactly this real-time setting and is well-suited to healthcare, where each running case must be understood as it unfolds.

### X.7.1 Safety pathways as reference models

| Safety pathway | Reference model (the standard) | Deviation the Arena catches |
|---|---|---|
| Sepsis bundle | lactate → cultures → antibiotics within target window | Antibiotic late or out of order |
| Stroke | door → CT → read → thrombolysis decision in time | CT or read delay past threshold |
| Surgical safety | sign-in → time-out → sign-out present | A checklist step skipped |
| Discharge readiness | criteria-met → order → ride → depart | Depart before a criterion documented |
| Boarding safety | admitted → reassessment cadence while boarding | Missed reassessment during a long board |

Reference models are authored once (with clinical owners), versioned, and stored as object-centric Petri nets the conformance engine can align against. They are *standards of care expressed as process*, which is exactly what conformance checking consumes.

### X.7.2 Online conformance → the cockpit → Eddy

The closed safety loop reuses the entire cockpit/Eddy spine:

> **Figure.** The closed patient-safety loop: online conformance checks the OCEL stream against a reference pathway; an observed deviation becomes an earned cockpit signal and a drafted Eddy correction that a human approves. Predictions surface as watch, never crit.

1. **Check.** The sidecar maintains a prefix-alignment for each running case against its pathway model, updated as new events stream in (decay-time pruning bounds the work, per the online-conformance literature).
2. **Deviate.** A missed, late, or out-of-order safety step yields a non-conformance with a cost/severity.
3. **Signal.** The deviation is registered as a `kpi_definitions`-backed signal and resolved by the one `StatusEngine` — surfacing as `watch` (eyes on it) escalating to `warn` (earned), in the same vocabulary and the same AlertTicker as every other cockpit signal, with **flap-damping** (Part VII) so a flickering pathway does not strobe.
4. **Prosecute.** Where a correction is actionable, the deviation seeds an Eddy proposal (a new CATALOG action, e.g. `flag_pathway_deviation` / `propose_pathway_correction`), which rides the existing `OperationalActionLifecycleService` FSM to a human approver. Eddy drafts; a human decides; the FSM audits.

### X.7.3 The patient-safety discipline

Two non-negotiables, inherited from the master plan and sharpened here. First, **predictions are `watch`, never `crit`** — a forecast that a case *will* breach a pathway is uncertain and must not manufacture false urgency; only an *observed* deviation earns amber/coral (Principle 10). Second, **the loop never auto-acts on a patient**: a conformance deviation can draft a huddle action or flag a barrier, but a human clinician approves before anything touches care. Conformance makes the *standard* visible and the *gap* measurable; humans close the gap. This is patient-safety automation that augments judgment rather than replacing it.

## Risks

- False-positive deviations (data lag, legitimate clinical exception) erode trust fast in a safety context. Mitigation: conformance signals ship `watch`-first with provenance, require flap-damping to escalate, and every reference model carries documented clinical exceptions; a deviation a clinician dismisses trains the model's exception set.
- Reference-model staleness: guidelines evolve. Mitigation: version every pathway model, attribute it to a clinical owner, and review on a cadence; the Arena shows which model version a deviation was judged against.
- Alarm fatigue (the master plan's R6) is amplified by safety alerts. Mitigation: tier-1 paging reserved for crit observed deviations only; warn surfaces in-app; the earned-color discipline is enforced at the engine, not the pixel.

## Open Questions

- Which two pathways are the v1 safety set (recommended: sepsis bundle + surgical safety checklist) — the highest-yield, best-instrumented standards?
- Do conformance deviations ever auto-open a `cockpit_alerts` row, or only after a human acknowledges, to keep the safety stream curated?
- Where is the line between a "pathway deviation" (Arena/conformance) and a clinical decision-support alert (out of scope, EHR's job)?

---

# X.8 — The AI Copilot (Governed Generation)

**Decision.** Add an **AI copilot** to the Arena that (a) auto-authors and labels process maps, (b) writes plain-language narratives of bottlenecks and deviations, (c) answers natural-language questions over the OCEL log, and (d) drafts PDSA hypotheses and Eddy actions — **all human-approved through the existing governance gate**. The copilot holds `ops:draft` and never `ops:approve`; it ships behind `ARENA_AI_ENABLED` (default off); and its generated models are validated by **conformance fitness before they are ever shown as fact**.

*Rationale.* The request is for *AI-assisted* OCEL process mapping and analytics, and the field has matured to the point where this is responsible to build. LLMs now generate process models reliably when constrained to produce validated intermediate representations (ProMoAI: LLM → constrained POWL code → BPMN/Petri net, with iterative error-handling), and they have been benchmarked specifically on process-mining tasks (PM-LLM-Benchmark), where Claude 3.5 Sonnet scored highest (0.93 average quality, with conformance as the quality measure). Conversational querying of process data is now a shipping product pattern (Celonis's LLM-to-PQL). The opportunity is real; the discipline is to wrap it in the same governance Eddy already enforces.

### X.8.1 What the copilot does — and how each output is gated

| Copilot capability | Input | Output | Governance gate |
|---|---|---|---|
| **Map authoring** | OCEL log | OC-DFG / BPMN of a chosen scope (ProMoAI-style) | Conformance-fitness threshold before publish; labeled "AI-generated" |
| **Narrative** | maps + OPerA + deviations | plain-language "what's slow and why" brief | Human reads; provenance to the underlying metrics shown |
| **NL query** | analyst question | a parameterized, allow-listed query over `ocel.*` (LLM-to-query, Celonis-style) | Read-only; no free-form SQL; results carry provenance |
| **PDSA hypothesis** | a bottleneck + history | a drafted Plan-Do-Study-Act cycle on the `PdsaCycle` bridge | Human approves via Eddy FSM before it becomes a real cycle |
| **Intervention** | a deviation/bottleneck | a drafted Eddy CATALOG action | `ops:draft`; human approval gate; FSM audit |

### X.8.2 Trust: conformance as the quality gate

The copilot's central safeguard is that **a generated map is not asserted until it conforms to the data**. When the copilot proposes a process model, the sidecar conformance-checks it against the OCEL log; only models above a fitness threshold are surfaced, and they are surfaced *with* their fitness/precision so the reader knows how well the picture matches reality. This is the same idea the PM-LLM-Benchmark used to score models, applied as a runtime guardrail: the AI may *propose*, but the *data adjudicates*. Narratives cite the specific metrics they summarize; NL queries are constrained to an allow-listed, parameterized query surface (never free-form SQL), so a hallucinated question cannot become a destructive or PHI-exposing query. The copilot reasons only over the **de-identified** OCEL log and aggregate metrics — never PHI.

### X.8.3 Reuse of the Eddy control plane

The copilot does not invent a governance system; it extends Eddy's. Its drafted PDSA cycles and corrections become `Recommendation(draft) → OperationalAction(draft) → Approval(pending)` records on the existing `ops.agent_*` plane and route through `OperationalActionLifecycleService::decideApproval()` exactly as Eddy's capacity proposals do. The CATALOG gains a small number of Arena-specific actions (`propose_pdsa_cycle`, `flag_pathway_deviation`, `propose_pathway_correction`), each tiered for the right approver. `ARENA_AI_ENABLED` gates the copilot independently of `ARENA_ENABLED`, so the Arena's deterministic discovery/analytics can run with the AI author switched entirely off — a conservative default for initial deployment.

### X.8.4 Evaluation

Because process-mining LLM quality is now measurable, the copilot is held to it. The **PM-LLM-Benchmark** task set (model discovery quality via conformance, narrative faithfulness, query correctness) becomes the Arena's copilot eval harness, run against fixture OCEL logs in CI. A copilot release that regresses model fitness or narrative faithfulness does not ship — the same "prove it didn't regress" discipline the master plan applies to the canon and the route smoke test.

## Risks

- Hallucinated insight is the signature failure of LLM analytics. Mitigation: conformance-fitness gate on every generated model; provenance on every narrative claim; allow-listed parameterized queries only; "AI-generated, human-unreviewed" labeling until approved.
- Automation bias: a polished AI narrative can over-persuade. Mitigation: surface fitness/precision and the raw OPerA numbers beside every narrative; require human approval before any action; never present a generated map as ground truth.
- Cost/latency of LLM calls on every refresh. Mitigation: generate narratives on demand and on material change only, not every 15-minute cycle; cache; the deterministic panes never depend on the AI being up.

## Open Questions

- Which model backs the copilot, and is conformance-fitness the right single quality gate, or do we add a precision floor and a narrative-faithfulness check?
- Do analysts get free-text NL query (allow-list-constrained) in v1, or only curated question templates until the guardrails are proven?
- Should the copilot's PDSA drafts require a second clinical reviewer (dual-control) given they propose changes to care processes?

---

# X.9 — The Arena Roadmap (X0–X4)

**What this is.** A five-phase workstream that runs **parallel to the master P-roadmap** (Part VIII), reusing its outputs. Every phase is additive, flag-gated, PHI-safe, canon-clean, and leaves `main` deployable. The Arena is *demonstrable* after **X1** (maps in the Study) and *actionable for safety* after **X3** (conformance into Eddy). The AI copilot (**X4**) ships disabled.

> **Figure.** The Arena roadmap (X0–X4): an additive, flag-gated workstream parallel to the master P-roadmap, with each phase depending on a specific P-phase output.

| Phase | Goal | Depends on | Ships behind |
|---|---|---|---|
| **X0** | OCEL foundation — `ocel.*` schema + `OcelProjector` + `RefreshOcelLog`; offline OCEL export validated in `pm4py`/`ocpa` | P1 (snapshot/serving, event cadence) | — (data only) |
| **X1** | Arena MVP — OCPM sidecar; auto-discovered OC-DFG maps in the Study altitude | P5 (Study consolidation) | `ARENA_ENABLED` |
| **X2** | OPerA analytics — object-centric bottlenecks; promote `getBottleneckStats()` to object-centric cockpit tiles | P2 (cockpit overview) | `ARENA_ENABLED` |
| **X3** | Conformance + safety guardrails — reference pathways; online prefix-alignment; deviations → cockpit → Eddy | P6 (Eddy loop + alerting) | `ARENA_ENABLED` |
| **X4** | AI copilot — governed map authoring, narratives, NL query, PDSA drafts; PM-LLM-Benchmark eval | P6 (Eddy plane) | `ARENA_AI_ENABLED` (off) |

### X.9.1 Phase detail

- **X0 — OCEL foundation (≈2.5 wk).** Additive `ocel.*` migration (`SafeMigration`); the `OcelProjector` + emission map for the live domains (RTDC, ED, Periop, Transport/EVS); `RefreshOcelLog` on the existing schedule; a validated OCEL-JSON export that `pm4py` ingests without error. *Acceptance:* projected counts reconcile to `prod.*` source counts within tolerance; a `pm4py` discovery runs on the export; PHI-free by construction (audited). *Depends on P1* for the serving/cadence pattern.
- **X1 — Arena MVP (≈2.5 wk).** The Python OCPM sidecar (`FastAPI` + `pm4py`/`ocpa`), the `/api/arena/*` orchestrator routes, and the Study UI Map pane rendering the first three maps (ED journey, discharge-to-empty-bed, perioperative) in the canon. *Acceptance:* maps auto-refresh on schedule; render canon-clean (SVG via `STATUS_VAR`, zero raw hex); a node drills via `DrillModal`. *Depends on P5* for the Study altitude.
- **X2 — OPerA analytics (≈2.5 wk).** OPerA metrics in the sidecar; the five object-centric bottleneck signals registered as `kpi_definitions` and resolved by `StatusEngine`; cockpit Quality/Flow tiles promoted from `getBottleneckStats()` to object-centric. *Acceptance:* a `crit` boarding tile drills A2→A3 to the OPerA sync/lag breakdown; metrics reconcile against post-P5 single-authority definitions. *Depends on P2* for the cockpit surface.
- **X3 — Conformance + safety (≈3 wk).** Reference pathway models (v1: sepsis bundle + surgical safety checklist); online prefix-alignment in the sidecar; deviations → `StatusEngine` `watch/warn` → flap-damped AlertTicker → Eddy `flag_pathway_deviation`. *Acceptance:* a seeded late-antibiotic case raises a `watch→warn` deviation and drafts a pending Eddy action; predictions render `watch` only; tier-1 paging crit-only. *Depends on P6* for the Eddy/alerting spine.
- **X4 — AI copilot (≈3 wk, ships disabled).** Governed map authoring (conformance-fitness gated), narratives with provenance, allow-listed NL query, PDSA drafts on the `PdsaCycle` bridge; the PM-LLM-Benchmark eval harness in CI. *Acceptance:* `ARENA_AI_ENABLED=false` fully suppresses the copilot; generated maps below the fitness threshold are withheld; every AI action lands pending behind the human gate. *Depends on P6* for the control plane.

### X.9.2 Effort & sequencing

**Total ≈ 11–14 weeks**, runnable in parallel with the back half of the master roadmap (X0 can begin once P1 lands; X3/X4 wait for P6). The Arena never blocks the master plan and the master plan never blocks the Arena beyond these named dependencies; both share the snapshot cadence, the Study altitude, the design canon, and the Eddy gate, so integration cost is low by construction.

## Risks

- Dependency coupling to the master roadmap (especially P5/P6) means Arena slips if those slip. Mitigation: X0/X1 depend only on P1/P5 and deliver standalone value (maps) even if the cockpit/Eddy phases lag; conformance (X3) is the only hard P6 dependency.
- Sidecar operationalization (container, supervision, scaling) is new ground for the team. Mitigation: treat X1 as including the ops runbook for the sidecar, not just the code.

## Open Questions

- Can X0 begin during the master P0–P1 window (backend-parallel), or must it wait for P1 to fully land?
- Is the v1 Arena single-facility (Summit Regional) like the cockpit (decision D1), with multi-facility OCEL deferred?

---

# X.10 — Risks, Privacy, Guardrails & Success Metrics

This chapter consolidates the cross-cutting fences for the whole Part X subsystem. The throughline is the master plan's: **additive, reuse-first, PHI-safe, human-governed.** Part X succeeds only if, at the end, the hospital understands its processes *better* and acts on them *faster*, with zero PHI exposed and zero care decision automated.

### X.10.1 Privacy & PHI (the hard rule)

- **De-identified by construction.** `ocel.*` and the sidecar hold operational timing and sequence only — de-identified object ids, coded clinical flags, no names/MRNs/notes. This satisfies HIPAA minimum-necessary and matches the established PHI-free aggregate-only discipline (Part VI, §6).
- **The sidecar is read-only and PHI-free.** It reads a de-identified OCEL export, never `prod.*`; it cannot mutate clinical data and holds no identifiers.
- **No PHI in AI prompts.** The copilot reasons over the de-identified log and aggregate metrics; clinical attributes used for conformance are coded flags, not free text.
- **Auditability.** Every Arena-drafted action is an `ops.agent_*` record with the full FSM audit trail; reference-model versions and copilot provenance are recorded.

### X.10.2 Architecture & canon guardrails

- **Additive + reversible.** `SafeMigration` + `Schema::hasColumn` on the `ocel.*`/`arena.*` migrations; no `prod.*` table altered destructively; the subsystem is removable by dropping two schemas and a flag.
- **Flag-gated, ships disabled.** `ARENA_ENABLED` and `ARENA_AI_ENABLED` both default off, honoring the `EDDY_ENABLED` precedent.
- **Canon never regresses.** Arena maps render via `STATUS_VAR` (the SVG data-viz exemption), the four-color+grey vocabulary, Figtree/`tabular-nums`; `scripts/check-ui-canon.sh` and the impeccable hook stay green.
- **One engine.** Arena bottleneck and conformance signals resolve through the *same* `StatusEngine` and `kpi_definitions` as the cockpit — never a parallel threshold system.
- **Advice, not autopilot.** The copilot holds `ops:draft`, never `ops:approve`; no Arena output touches care without a human approval through the Eddy FSM.
- **Testing under the Pest constraint.** All new backend logic (projector, emission map, OPerA wiring, conformance signals) is tested in **PHPUnit class syntax**, not Pest (Part IX, §3); the copilot is held to the PM-LLM-Benchmark eval in CI.

### X.10.3 Risk register (Part X)

| # | Risk | Mitigation |
|---|---|---|
| X-R1 | Object/qualifier mis-modeling corrupts every map | Versioned catalog ratified with stakeholders; emission-map unit tests; nightly reconcile to `prod.*` |
| X-R2 | OCEL volume / `object_changes` growth | Monthly partitions; rolling retention (≈13 mo); archive old partitions |
| X-R3 | Sidecar latency / availability | Bounded OCEL window; scheduled pre-compute + cache; graceful "last-good" degrade |
| X-R4 | Conformance false positives → alarm fatigue | `watch`-first + flap-damping; documented clinical exceptions; tier-1 paging crit-only |
| X-R5 | AI hallucination | Conformance-fitness gate; provenance; allow-listed queries; human approval |
| X-R6 | PHI leakage | De-identified projection; PHI-free sidecar; no PHI in prompts; audit |
| X-R7 | Scope creep (Arena becomes a second EHR/CDS) | Process-and-flow scope only; clinical decision support stays the EHR's job |

### X.10.4 Success metrics

Part X is "done" when the Arena measurably shortens the path from a flow problem to a proven fix. Outcome metrics are the master plan's flow/safety numbers; the Arena's own metrics measure whether it earns its place.

| # | Metric | Today (baseline) | Part X target | How measured |
|---|---|---|---|---|
| X-C1 | Process maps are *discovered*, not authored | static JSON + hardcoded root causes | maps auto-discovered from live OCEL | `getBottleneckStats` + mock maps retired; Arena maps refresh on schedule |
| X-C2 | Bottlenecks are *object-centric* | single-case `getBottleneckStats()` | OPerA sync/pool/lag on the cockpit | object-centric tiles live; A0→A3 drill proves the hand-off side |
| X-C3 | Safety pathway adherence is *measured live* | not measured | v1 pathways conformance-checked online | sepsis + surgical-safety deviations surface as earned signals |
| X-C4 | Improvement loop is *closed and attributed* | PDSA stages synthesized from a modulo | PDSA drafted from real bottlenecks, outcome-attributed | `PdsaCycle → Intervention → OutcomeAttribution` surfaced |
| X-C5 | Time-from-signal-to-proposed-fix | manual, ad hoc | minutes (drafted, pending human) | Eddy draft timestamp vs deviation open |
| X-C6 | PHI exposure in process analytics | n/a | zero | `ocel.*`/sidecar de-identification audit |
| X-C7 | Flow outcomes (shared with master plan) | boarding, bed-turn, DC-before-noon baselines | measured improvement | cockpit OPerA tiles before/after intervention |

### X.10.5 The one-sentence definition of done

**Part X is done when the hospital's processes are discovered — not drawn — from one object-centric event log; when boarding, turnover, and PACU bottlenecks are localized to the exact object hand-off that fails; when safety-critical pathways are conformance-checked live and their deviations earn a governed correction; and when an AI copilot can author the map, narrate the bottleneck, and draft the PDSA — every one of which a human approves through the same Eddy gate, with zero PHI exposed and the canon green.**

---

# Appendix X.A — References & Research Base

The following sources ground Part X. They are the current standard, the foundational object-centric techniques, the healthcare applications, and the AI-assisted-modeling state of the art (as of mid-2026).

**OCEL 2.0 standard & object-centric foundations**

- OCEL 2.0 — Object-Centric Event Log 2.0 (standard site, metamodel, formats). RWTH PADS. https://www.ocel-standard.org/
- Berti, A., Koren, I., Adams, J. N., Park, G., et al. *OCEL (Object-Centric Event Log) 2.0 Specification.* arXiv:2403.01975 (2024). https://arxiv.org/abs/2403.01975
- van der Aalst, W. M. P. *Object-Centric Process Mining: Dealing with Divergence and Convergence in Event Data.* SEFM 2019. (Flattening pathologies: convergence, divergence, deficiency.)
- Berti, A. & van der Aalst, W. M. P. *OC-PM: Analyzing Object-Centric Event Logs and Process Models.* (OC-DFG, object-centric discovery & conformance.) https://www.vdaalst.com/publications/p1355.pdf

**Object-centric performance**

- Park, G., Berti, A. & van der Aalst, W. M. P. *OPerA: Object-Centric Performance Analysis.* arXiv:2204.10662 / ER 2022. (Flow, synchronization, pooling, lagging times.) https://arxiv.org/abs/2204.10662
- Adams, J. N., et al. *ocpa: A Python Library for Object-Centric Process Analysis.* (Implements OPerA.) https://github.com/ocpm/ocpa

**Tooling**

- *pm4py* — process mining for Python; full OCEL 2.0 support (relational/JSON/XML), object-centric discovery & conformance. https://www.ocel-standard.org/tool-support/libraries/pm4py/

**Healthcare object-centric & conformance**

- Park, G., Lee, Y. & Cho, M. *Enhancing healthcare process analysis through object-centric process mining: Transforming OMOP common data models into object-centric event logs.* J. Biomed. Inform. 156:104682 (2024). DOI 10.1016/j.jbi.2024.104682. (First OCPM in healthcare; MIMIC-IV.) https://pubmed.ncbi.nlm.nih.gov/38944260/
- *Exploring Object-Centric Process Mining with MIMIC-IV.* (Springer, 2024.)
- *A process mining approach for clinical guidelines compliance: real-world application in rectal cancer.* Frontiers in Oncology (2023). https://www.frontiersin.org/journals/oncology/articles/10.3389/fonc.2023.1090076/full
- *Process Mining and Conformance Checking of Long-Running Processes in the Context of Melanoma Surveillance.* (Clinical conformance.)
- *Process Modeling and Conformance Checking in Healthcare: A COVID-19 Case Study.* Springer (2023).

**Online / streaming conformance**

- van Zelst, S. J., et al. *Online Conformance Checking: Relating Event Streams to Process Models using Prefix-Alignments.* Int. J. Data Science and Analytics (2019).
- *I Will Survive: An Online Conformance Checking Algorithm Using Decay Time.* arXiv:2211.16702.

**AI-assisted process mining & modeling**

- Kourani, H., Berti, A., et al. *ProMoAI: Process Modeling with Generative AI.* arXiv:2403.04327 (2024). (LLM → constrained POWL → BPMN/Petri net, iterative error-handling.) https://arxiv.org/abs/2403.04327
- Berti, A., et al. *PM-LLM-Benchmark: Evaluating Large Language Models on Process Mining Tasks.* arXiv:2407.13244 (2024). (Conformance-as-quality; Claude 3.5 Sonnet top at 0.93.) https://arxiv.org/abs/2407.13244
- Celonis. *Object-Centric Process Mining & the Process Intelligence Graph* (living digital twin; LLM-to-PQL for conversational querying). https://www.celonis.com/blog/what-is-object-centric-process-mining-ocpm/

*Note on currency: OCEL 2.0 and its tooling are evolving rapidly; pin library versions and treat the relational `ocel.*` store as the stable contract. Process-mining LLM benchmarks move quickly — re-baseline the copilot's eval against the current PM-LLM-Benchmark at each release.*
