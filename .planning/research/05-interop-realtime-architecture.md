# 05 — Interoperability & Real-Time Data Architecture

**Dossier section for Zephyrus** — the real-time hospital demand/capacity platform.
Scope: the *ingestion backbone* that feeds Zephyrus's census/bed/flow models. We have
committed to **vendor-agnostic pluggable adapters** (HL7v2 + FHIR + flat file) behind a
clean `EventSource` interface, plus a **synthetic real-time stream simulator** for demo and
test. This section establishes the standards, the streaming architecture, and the concrete
design for a Laravel 11 + PostgreSQL core with a Python/Node ingestion sidecar and Laravel
Reverb for live UI push.

---

## 1. HL7v2 ADT — the backbone of bed/census tracking

**ADT (Admit/Discharge/Transfer)** is the single most important real-time feed for a
demand/capacity platform. Nearly every patient movement that changes a hospital's census or
bed state is broadcast as an ADT message keyed by a *trigger event* in `MSH-9` (e.g.
`ADT^A01`). The events that matter for Zephyrus:

| Event | Meaning | Census effect |
|------|---------|---------------|
| **A01** | Admit / visit notification — patient assigned a bed, stay begins | +1 census, bed occupied |
| **A02** | Transfer — patient changed physical location | bed→bed move |
| **A03** | Discharge / end visit | −1 census, bed freed |
| **A04** | Register (e.g. ED/outpatient) | arrival, pre-bed |
| **A05** | Pre-admit | pending arrival |
| **A06 / A07** | Change outpatient↔inpatient / inpatient↔outpatient | class change |
| **A08** | Update patient info (any field changed, no other event) | attribute refresh |
| **A11** | **Cancel** admit (A01 entered in error / no admit) | reverse +1 |
| **A12** | Cancel transfer | reverse move |
| **A13** | **Cancel** discharge (A03 reversed) | reverse −1 |
| **A40** | Merge patient identifiers | identity reconciliation |

An A01 "is sent as a result of a patient undergoing the admission process which assigns the
patient to a bed and signals the beginning of a patient's stay"; A02 is issued "as a result
of the patient changing his or her assigned physical location," with the new location in
`PV1-3` and the prior in `PV1-6`; A03 "signals the end of a patient's stay"; A11/A13 are the
cancellation events for erroneous A01/A03 ([Caristix Trigger Events][c1];
[InterSystems ADT][c2]; [Rhapsody ADT][c3]).

**Why ADT is *the* backbone:** the **PV1 (Patient Visit)** segment carries the live
operational truth. `PV1-2` is patient class (Inpatient/Outpatient/Emergency), `PV1-3` is the
**assigned patient location** — point of care / room / bed / facility — and `PV1-6` is the
prior location. A correct, ordered ADT stream is sufficient to reconstruct a near-real-time
census, bed map, and length-of-stay clock without ever touching the EHR database. This is
exactly the signal Zephyrus needs.

**MLLP transport.** HL7v2 over TCP is framed with **MLLP (Minimal Lower Layer Protocol)**: a
start block `<VT>` = `0x0B`, the message body, then end block `<FS><CR>` = `0x1C 0x0D`. The
receiver returns an ACK (wrapped in its own MLLP frame) whose **MSA** segment reports
`AA` (accept), `AE` (error), or `AR` (reject) against the original `MSH-10` message control
ID. MLLP is connection-oriented over a long-lived TCP socket ([Wikipedia MLLP][m1];
[HL7 MLLP Transport Spec PDF][m2]; [Google Cloud MLLP adapter][m3]). **Raw MLLP is cleartext**
and must be wrapped in TLS for PHI (see §8).

**Ordering & duplicates.** `MSH-13` (sequence number) and `MSH-10` (message control ID)
support ordering and idempotent de-duplication; resends after a missing ACK are common, so
consumers must treat `MSH-10` as an idempotency key. ADT feeds are typically delivered by an
integration engine (§5), not the EHR directly.

**Adjacent feeds we should accept but not require for v1:**
- **SIU (Scheduling)** — `S12` new appointment, `S13` reschedule, `S14` modify, `S15` cancel.
  These let Zephyrus see *expected demand* (planned admissions, surgical bookings) ahead of
  the A01 ([Rhapsody SIU][s1]; [Caristix][c1]).
- **ORM^O01 / ORU^R01** — order and result messages. For perioperative flow, OR case orders
  and PACU/lab results help predict step-down/ICU demand.

**Parsing libraries (no need to hand-roll a parser):** **HAPI** (Java, the reference v2/FHIR
library), **python-hl7** and **hl7apy** (Python), **simple-hl7 / node-hl7-client** (Node), and
the JavaScript transformers built into **Mirth** (§5).

### Design implications for Zephyrus
- The **HL7v2 ADT adapter** is the highest-value source. Build it as an MLLP/TLS listener in
  the **Node or Python sidecar** (Laravel is a poor fit for long-lived raw TCP sockets).
- Use **hl7apy/python-hl7** (or `node-hl7-client`) to parse, then map `PV1-2/3/6` + `MSH-9`
  trigger into the canonical event model (§4/§6).
- Treat `MSH-10` as the idempotency key and `MSH-13`/timestamps for ordering; ACK with an MSA
  `AA` only after the event is durably enqueued.
- Accept SIU/ORM/ORU on the *same seam* but gate them behind feature flags for v1.

---

## 2. FHIR R4/R5 — the modern operational API surface

Where a site exposes a FHIR API, Zephyrus should prefer it for backfill and for sites with no
ADT feed. The operations-relevant resources:

- **Encounter** — `status` (planned | in-progress | finished | cancelled), `class`
  (inpatient/emergency/ambulatory), `subject`, `participant`, `period`, and `location[]`
  (a back-reference to the bed/room). The Encounter status lifecycle is effectively the FHIR
  analogue of the ADT event stream ([FHIR R4 Encounter][f1]).
- **Location** — the physical hierarchy. `physicalType` distinguishes **bed / room / ward /
  level / site**; `operationalStatus` is "typically only for a bed/room" and covers
  housekeeping/contaminated/occupied states; `partOf` builds the bed→room→ward→building tree;
  `status` is active/suspended/inactive ([FHIR R4 Location][f2]; [FHIR R6 ballot Location][f2b]).
- **Patient, Practitioner, ServiceRequest, Appointment, Condition, Observation** round out
  demand context (who, what's ordered, what's scheduled, acuity signals).

**US Core** profiles constrain these for the US realm — `us-core-encounter`,
`us-core-location`, `us-core-patient` — defining the *must-support* elements, vocabularies,
and search parameters a server is required to expose ([US Core Encounter][f3];
[US Core Location][f4]).

**FHIR Subscriptions = real-time push.** The R5 **topic-based Subscriptions** framework, and
its **Subscriptions R5 Backport IG** for R4/R4B servers, let a server actively notify Zephyrus
when data changes — exactly what we want instead of polling. A `SubscriptionTopic` defines the
trigger (e.g. *Encounter complete / status change*); a `Subscription` binds a topic to a
channel. The **rest-hook** channel POSTs a notification **Bundle** to our endpoint; payload
content can be **empty** (ping), **id-only**, or **full-resource**. There is a published
backport `SubscriptionTopic` for **R4 Encounter** changes ([Subscriptions Backport IG][f5];
[Backport components][f6]; [R4 Encounter topic][f7]; [Topology: subscriptions across versions][f8]).

**Bulk Data Access (`$export`) for backfill.** The **SMART Bulk Data IG** defines
**system-, group-, and patient-level** `$export` returning **NDJSON** (one resource per line),
async via a kickoff + status-poll (`Content-Location`) pattern, authorized by **SMART Backend
Services** (OAuth2 client-credentials with a signed JWT). This is the right tool for the
initial cold-load / nightly reconciliation of all open encounters and the Location tree
([FHIR Bulk Data export][f9]; [Bulk Data IG export][f10]; [SMART bulk-data-server][f11]).

**SMART App Launch.** If Zephyrus is ever embedded in the EHR, **SMART App Launch** (EHR
launch vs standalone, `.well-known/smart-configuration` discovery, OAuth2 scopes like
`patient/Encounter.rs`, launch context) is the standard front door ([SMART App Launch][f12];
[SMART scopes & launch context][f13]).

### Design implications for Zephyrus
- Build a **FHIR adapter** that (a) cold-loads via `$export` NDJSON, then (b) stays live via a
  **rest-hook Subscription** on Encounter/Location changes. The rest-hook endpoint is a
  Laravel route (or sidecar HTTP route) that normalizes the notification Bundle into canonical
  events on the same queue as ADT.
- Persist the FHIR `Location.partOf` tree as Zephyrus's authoritative **bed/room/ward graph**.
- Prefer **id-only** payloads + a follow-up read for large resources; full-resource only when
  the server is trusted and volume is modest.

---

## 3. CDS Hooks — surfacing prescriptive guidance into EHR workflow

**CDS Hooks** (an HL7 spec from the CDS Workgroup) lets an external service inject near-real-
time guidance into the clinician's EHR workflow when a *hook* fires. A CDS Client (EHR)
publishes a **discovery** endpoint (`/cds-services`) listing services, the **hooks** they
support, and the **prefetch** data they need; on a triggering activity the EHR POSTs a hook
request (with `context` + prefetched FHIR) and the service responds with **cards** —
information cards, **suggestion** cards (proposed actions), and **app-link** cards (which can
launch a SMART app) ([CDS Hooks home][h1]; [CDS Hooks 1.0 spec][h2]; [Quick Start][h3]).

Hooks relevant to Zephyrus's prescriptive layer: **`encounter-start`** ("invoked when the user
is initiating a new encounter… in an inpatient setting, the time of admission"),
**`encounter-discharge`**, **`patient-view`**, **`order-select`/`order-sign`**,
**`appointment-book`** ([encounter-start hook][h4]).

### Design implications for Zephyrus
- CDS Hooks is the **outbound** complement to our inbound ADT/FHIR ingestion: it is how
  Zephyrus's *prescriptive recommendations* (e.g. "this admission will breach ICU capacity at
  14:00 — consider step-down placement") get surfaced **inside the EHR** at admit/discharge.
- Stand up a `/cds-services` discovery endpoint and an `encounter-discharge` /
  `encounter-start` service that returns suggestion + app-link cards pointing back at the
  Zephyrus capacity view. This is a **Phase 2** capability — design the seam now, build later.

---

## 4. Event-driven / streaming architecture

The core architectural move: **decouple the source format from the internal model.** ADT,
FHIR, and flat files all collapse into one **canonical operational event stream** that the
rest of Zephyrus consumes. This is the classic **Canonical Data Model** integration pattern
fronted by an **Anti-Corruption Layer** (DDD) that keeps HL7/FHIR vocabulary from leaking into
our domain.

**Transport options:**
- **Apache Kafka** — durable, partitioned log. **Ordering is guaranteed only within a
  partition**, so partition by `patient_id` (or `encounter_id`) to keep a patient's events
  ordered. Consumer groups give horizontal scale; offsets in `__consumer_offsets` enable
  **replay** from any point; **log compaction** retains the latest value per key (ideal for a
  "current bed state per encounter" topic) ([Confluent log compaction][k1];
  [Confluent compaction course][k2]; [Kafka partition best practices][k3]).
- **Redis Streams** — lighter weight, already in our stack (Redis 7 + Horizon). `XADD` /
  `XREADGROUP` with **consumer groups** deliver each entry to exactly one consumer; native
  delivery is **at-least-once**, so we add **idempotency** keyed on the stream entry ID / our
  `event_id` ([Redis Streams docs][r1]; [Exactly-once with Redis Streams][r2]).

**CQRS + event sourcing fit.** A real-time census is a textbook **event-sourcing** read model:
the canonical event log is the source of truth; a **projection** consumes it and materializes
query-optimized **read models** (current census per unit, bed occupancy grid, LOS clock). CQRS
separates the write path (append events) from the read path (serve projections), letting them
scale independently and letting us **rebuild any projection by replaying the log** — invaluable
when we add a new metric or fix a bug ([Azure Event Sourcing][q1]; [Azure CQRS][q2];
[Fowler/Marten event sourcing][q3]).

**Delivery semantics:** assume **at-least-once** end to end and make every consumer
**idempotent** (`event_id` dedupe table / unique index). Route un-processable messages to a
**dead-letter** stream/topic for inspection and replay.

### Design implications for Zephyrus
- **Start with Redis Streams** (already in the stack, lower ops burden); keep the
  `EventSource` → stream boundary clean so we can **swap to Kafka** if volume/replay needs grow.
- Model the census as an **event-sourced projection**: append canonical events → project into
  Postgres read tables (`encounters`, `bed_states`, `census_snapshots`).
- Partition/order by `encounter_id`; dedupe on `event_id`; dead-letter on parse/validation
  failure with full original payload retained for replay.

---

## 5. The integration-engine landscape

We are **not** rebuilding an integration engine — but we must decide where one sits relative
to our adapter layer.

- **Mirth Connect / NextGen Connect** — the de-facto open-source engine: channels +
  JavaScript transformers, 11 connectors incl. **TCP/MLLP**, HTTP, file, JDBC, DICOM, SFTP.
  Note the licensing inflection: **4.5.2 (Sep 2024) is the last open-source release; 4.6+ is
  commercial-only** ([Mirth guide][e1]; [Mirth vs Rhapsody vs Iguana][e2]).
- **Rhapsody (Lyniate) / Corepoint** — commercial, enterprise-scale, "Best in KLAS" for
  integration; Rhapsody for developers, Corepoint for analysts ([same][e2]).
- **Iguana (iNTERFACEWARE)** — commercial; its Lua **Translator** gives the best live
  transform-authoring experience; favored for self-sufficient teams ([same][e2]).
- **Cloud managed services:**
  - **Google Cloud Healthcare API** — **native HL7v2 *and* FHIR *and* DICOM stores**, with
    **Pub/Sub notifications** on create/update/delete and the earliest R5 / topic-based
    Subscription support. Strongest fit if HL7v2 is the primary feed
    ([Google Cloud Healthcare API][e3]; [GCP vs Azure vs AWS][e4]).
  - **AWS HealthLake** — FHIR R4 + Comprehend Medical NLP; **no native HL7v2 ingest, no FHIR
    Subscriptions** (use S3/CloudWatch events) ([same][e4]).
  - **Azure Health Data Services** — FHIR + DICOM + MedTech (IoT); notifications via **Event
    Grid** ([same][e4]).

### Design implications for Zephyrus
- A site that already runs **Mirth/Rhapsody/Iguana** is an *asset*: let them deliver a
  normalized ADT feed to our MLLP listener; our adapter is thinner there.
- **Greenfield/cloud** sites: **Google Cloud Healthcare API** is the best upstream because it
  can *terminate* HL7v2 and emit **Pub/Sub** change events — our adapter then becomes a Pub/Sub
  consumer instead of an MLLP listener (proof the `EventSource` abstraction earns its keep).
- We do **not** bundle an engine; we interoperate with whatever the site has and provide our
  own lightweight MLLP/FHIR adapters for sites that have none.

---

## 6. Adapter interface design — the pluggable `EventSource` seam

Everything above funnels through one interface so HL7v2, FHIR-subscription, flat-file, cloud
Pub/Sub, and the synthetic simulator are **interchangeable**.

```
interface EventSource:
    start(checkpoint) -> stream of RawMessage          # long-running or batch
    stop()
    ack(message_id)                                    # source-specific durable ack (MLLP MSA, FHIR 200, offset commit)
    nack(message_id, reason)                            # → dead-letter

# Each adapter MAPS RawMessage -> CanonicalEvent, never leaking source vocabulary upstream.

CanonicalEvent {
    event_id        # idempotency key (MSH-10 / FHIR resource version / file row hash)
    event_type      # ENCOUNTER_ADMIT | TRANSFER | DISCHARGE | CANCEL_ADMIT | CANCEL_DISCHARGE
                    #   | LOCATION_UPDATE | APPOINTMENT_BOOKED | ...
    occurred_at     # source event time (for ordering)
    received_at
    source          # adapter id + site
    encounter_ref   # stable encounter key  (ordering/partition key)
    patient_ref     # de-identifiable pseudonym key
    location        { bed, room, ward, facility, physical_type, operational_status }
    patient_class   # INPATIENT | EMERGENCY | OUTPATIENT
    raw_payload     # retained for audit/replay/dead-letter
    sequence        # MSH-13 / FHIR version / file offset
}
```

**Cross-cutting guarantees the seam must enforce:**
- **Idempotency** — unique index on `event_id`; re-delivered messages are no-ops.
- **Ordering** — order by `(encounter_ref, sequence/occurred_at)`; out-of-order cancels (A11
  arriving after A01 already projected) are handled by the projection, not the adapter.
- **Replay** — every source exposes a checkpoint (MLLP: persisted last-acked control ID; FHIR:
  last version/`_since`; file: byte offset; stream: consumer-group offset). Replays re-emit
  canonical events that are idempotently absorbed.
- **Dead-letter** — `nack` writes the raw payload + reason to a `dead_letters` table/stream for
  inspection and manual/automated replay.

### Design implications for Zephyrus
- Define `EventSource` as a **Python ABC / Node interface in the sidecar**; the four concrete
  adapters (HL7v2-MLLP, FHIR-subscription, flat-file, synthetic) implement it identically.
- The canonical event is the **only** contract Laravel/Postgres sees — source swaps never
  touch the domain or the UI.
- Ship `dead_letters` and an `ingested_events` (idempotency ledger) table from day one.

---

## 7. Synthetic stream simulator

For demo, load-test, and dev without PHI, we generate a **realistic synthetic ADT/census
stream** behind the *same* `EventSource` seam.

**Use Synthea for the patient substrate.** **Synthea** (MITRE, `synthetichealth/synthea`)
models full synthetic patient lives and exports **FHIR (R4), C-CDA, and CSV**; community work
adds **HL7 v2 ADT/ORU/VXU** output, so it can seed realistic patients, encounters, and
conditions — *not real, but statistically plausible* ([Synthea site][y1]; [Synthea FHIR
wiki][y2]; [Synthea HL7 ADT PR #862][y3]; [Synthea paper, PMC][y4]).

**Layer a real-time flow model on top.** Synthea gives the *who*; the simulator supplies the
*when/where*:
- **Arrivals** as a non-homogeneous **Poisson process** with a **diurnal curve** (ED peaks
  late morning / evening, troughs overnight) and weekday/weekend modulation.
- **Length-of-stay** drawn from a per-unit **log-normal/gamma** distribution; discharges
  scheduled accordingly (with a discharge-time-of-day bias).
- **Transfers** as a small Markov step (ED→floor→ICU→step-down) per patient class.
- **Surge mode** — a multiplier / injected spike (flu season, mass-casualty) to demo Zephyrus's
  capacity-stress behavior.
- Emit the resulting `CanonicalEvent`s on a wall-clock or *accelerated* clock (e.g. 1 sim-hour
  = 1 real-minute) for fast demos.

### Design implications for Zephyrus
- Build `SyntheticEventSource` as a first-class adapter implementing the **same** `EventSource`
  interface — the live UI cannot tell sim from real, which is exactly what makes the demo and
  the CI/load tests trustworthy.
- Seed patients from a pre-generated **Synthea** dataset (checked-in NDJSON/CSV, de-identified
  by construction); drive timing from the Poisson/diurnal/LOS model.
- Make the clock, arrival rate, LOS params, and surge toggles **config-driven** so demos are
  scriptable and reproducible.

---

## 8. Security & compliance

- **Encryption in transit.** Raw **MLLP is cleartext** — PHI HL7v2 feeds MUST run **MLLP over
  TLS** (TLS 1.2/1.3) or an encrypted VPN/dedicated VLAN; FHIR/Pub/Sub over HTTPS. This is the
  baseline for the HIPAA Security Rule transmission-security standard ([Saga MLLP][m4];
  [Accountable: HL7 HIPAA][sec1]).
- **Audit controls & access.** HIPAA technical safeguards require **audit logging** of access
  and activity, **access controls**, and the **minimum-necessary** principle — log every
  ingested event, every projection write, and every UI/API read of PHI.
- **PHI minimization.** Zephyrus needs *operational* data (bed, unit, class, timestamps,
  encounter key) far more than full clinical detail. Map only the fields the capacity model
  uses; drop the rest at the adapter so PHI never reaches the read model unnecessarily.
- **De-identification for demo.** Two HIPAA methods: **Safe Harbor** (remove all **18
  identifiers** — names, fine-grained geography, all date elements except year, MRNs, SSNs,
  device/biometric IDs, etc.) and **Expert Determination** (a qualified expert documents that
  re-identification risk is very small). The synthetic stream (§7) is **de-identified by
  construction**; any real data used for demo must pass Safe Harbor ([HHS de-identification
  guidance][sec2]; [HHS de-id index][sec3]).

### Design implications for Zephyrus
- Terminate **TLS at the MLLP listener** and the FHIR rest-hook/`$export` endpoints; reject
  plaintext.
- The **canonical event carries a pseudonymous `patient_ref`** (a salted hash / opaque token),
  not the MRN; the MRN↔token map (if needed at all) lives encrypted and access-controlled,
  separate from the operational read model.
- Persist an **append-only audit log** of ingest + access events.
- Default demos to the **synthetic source**; gate any real-data demo behind a Safe-Harbor
  de-identification step.

---

## Recommended Zephyrus ingestion architecture (synthesis)

```
            ┌─────────────────────── INGESTION SIDECAR (Python/Node) ──────────────────────┐
 HL7v2/MLLP │  [HL7v2-MLLP Adapter] ─┐                                                       │
 (TLS)  ───►│  [FHIR-Sub Adapter]   ─┤                                                       │
 FHIR rest- │  [Flat-File Adapter]  ─┤── map → CanonicalEvent ──► Redis Stream  ◄── dead-    │
 hook/$export  [Synthetic Adapter]  ─┘     (idempotent, ordered      `ops.events`   letter   │
 NDJSON ───►│        ▲ same EventSource interface          by encounter_ref)                 │
            └────────┼──────────────────────────────────────────────┬──────────────────────┘
                     │ checkpoint/replay                             │ XREADGROUP (consumer group)
                     │                                               ▼
                     │                        ┌──────────── LARAVEL 11 CORE ──────────────┐
   Synthea NDJSON ───┘                        │  Census Projector (event-sourced):        │
   (de-identified seed)                       │   absorb event (dedupe on event_id)        │
                                              │   → PostgreSQL read models:                │
                                              │      encounters / bed_states /             │
                                              │      census_snapshots / dead_letters       │
                                              │   → broadcast delta ──► Laravel Reverb ─────┼──► live React UI
                                              │  CDS Hooks /cds-services (outbound, P2)     │   (WebSocket)
                                              └────────────────────────────────────────────┘
```

**Concrete recommendation:**
1. **Adapters live in a Python (preferred) or Node sidecar** — Laravel cannot host long-lived
   raw-MLLP TCP sockets well. All four adapters implement one `EventSource` ABC.
2. **Canonical event model** (§6) is the single contract; source format never leaks past the
   adapter (anti-corruption layer).
3. **Redis Streams** as the internal bus (already in stack via Horizon/Redis 7), partition-
   ordered by `encounter_ref`, at-least-once + idempotency ledger; **keep the seam Kafka-ready.**
4. **Laravel + PostgreSQL** host the **event-sourced census projection** — append-only event
   ledger → materialized read tables; any projection rebuildable by replay.
5. **Laravel Reverb** broadcasts census/bed deltas over WebSocket to the React UI for live
   updates — the projection emits a broadcast event on each state change.
6. **The synthetic simulator plugs into the exact same `EventSource` seam**, so the live UI,
   the demo, and CI/load tests are indistinguishable from a real feed.
7. **Security:** TLS on every ingress, pseudonymous `patient_ref`, PHI minimization at the
   adapter, append-only audit log, Safe-Harbor gate for any real-data demo.

---

## Sources

HL7v2 / ADT / MLLP
- [c1] Caristix HL7 v2 Trigger Events — https://hl7-definition.caristix.com/v2/HL7v2.5/TriggerEvents
- [c2] InterSystems — Types of HL7 ADT message (A04 example) — https://community.intersystems.com/post/types-hl7-adt-message-and-example-adta04
- [c3] Rhapsody — HL7 ADT (Admit/Discharge/Transfer) — https://rhapsody.health/resources/hl7-adt/
- [m1] Wikipedia — Minimal Lower Layer Protocol — https://en.wikipedia.org/wiki/Minimal_Lower_Layer_Protocol
- [m2] HL7 — MLLP Transport Specification (PDF) — https://www.hl7.org/documentcenter/public/wg/inm/mllp_transport_specification.PDF
- [m3] Google Cloud — MLLP adapter — https://github.com/GoogleCloudPlatform/mllp/
- [m4] Saga IT — MLLP reference — https://saga-it.com/docs/hl7/reference/mllp
- [s1] Rhapsody — HL7 SIU message — https://rhapsody.health/resources/hl7-siu-message/

FHIR
- [f1] FHIR R4 Encounter — https://hl7.org/fhir/R4/encounter.html
- [f2] FHIR R4 Location — https://www.hl7.org/fhir/R4/location.html
- [f2b] FHIR R6 ballot Location — https://build.fhir.org/location.html
- [f3] US Core Encounter Profile — https://hl7.org/fhir/us/core/StructureDefinition-us-core-encounter.html
- [f4] US Core Location Profile — https://build.fhir.org/ig/HL7/US-Core/StructureDefinition-us-core-location.html
- [f5] Subscriptions R5 Backport IG (home) — https://build.fhir.org/ig/HL7/fhir-subscription-backport-ig/
- [f6] Backport — Topic-Based Subscription Components — https://build.fhir.org/ig/HL7/fhir-subscription-backport-ig/components.html
- [f7] Backport — R4 Encounter Complete SubscriptionTopic — https://build.fhir.org/ig/HL7/fhir-subscription-backport-ig/Basic-r4-encounter-complete.html
- [f8] Topology Health — FHIR Subscriptions across R4/R4B/R5 — https://blog.topology.health/articles/fhir-subscriptions
- [f9] FHIR Bulk Data Export — https://hl7.org/fhir/uv/bulkdata/export/index.html
- [f10] Bulk Data Access IG — Export — http://build.fhir.org/ig/HL7/bulk-data/en/export.html
- [f11] SMART bulk-data-server — https://github.com/smart-on-fhir/bulk-data-server
- [f12] SMART App Launch — Launch & Authorization — https://build.fhir.org/ig/HL7/smart-app-launch/app-launch.html
- [f13] SMART App Launch — Scopes & Launch Context — http://www.hl7.org/fhir/smart-app-launch/scopes-and-launch-context/

CDS Hooks
- [h1] CDS Hooks (home) — https://cds-hooks.org/
- [h2] CDS Hooks 1.0 spec (HL7) — https://cds-hooks.hl7.org/1.0/
- [h3] CDS Hooks Quick Start — https://cds-hooks.org/quickstart/
- [h4] CDS Hooks — encounter-start — https://cds-hooks.org/hooks/encounter-start/

Streaming / CQRS / Event Sourcing
- [k1] Confluent — Kafka Log Compaction — https://docs.confluent.io/kafka/design/log_compaction.html
- [k2] Confluent Developer — Key-based retention via compaction — https://developer.confluent.io/courses/architecture/compaction/
- [k3] Factor House — Kafka topic/partition best practices — https://factorhouse.io/articles/kafka-topic-partition-best-practices
- [r1] Redis Streams docs — https://redis.io/docs/latest/develop/data-types/streams/
- [r2] OneUptime — Exactly-once processing with Redis Streams — https://oneuptime.com/blog/post/2026-03-31-redis-exactly-once-processing-streams/view
- [q1] Azure Architecture Center — Event Sourcing pattern — https://learn.microsoft.com/en-us/azure/architecture/patterns/event-sourcing
- [q2] Azure Architecture Center — CQRS pattern — https://learn.microsoft.com/en-us/azure/architecture/patterns/cqrs
- [q3] CODE Magazine — Event Sourcing and CQRS with Marten — https://www.codemag.com/Article/2209071/Event-Sourcing-and-CQRS-with-Marten

Integration engines / cloud
- [e1] Mirth Connect guide (architecture, channels) — https://nirmitee.io/blog/mirth-connect-guide-architecture-channels-deployment-performance/
- [e2] Mirth Connect vs Rhapsody vs Iguana (2026) — https://nirmitee.io/blog/mirth-connect-vs-rhapsody-vs-iguana-comparison-2026/
- [e3] Google Cloud Healthcare API — https://cloud.google.com/healthcare-api
- [e4] GCP vs Azure vs AWS HealthLake FHIR comparison — https://www.mdatool.com/blog/google-cloud-healthcare-api-vs-azure-vs-aws-healthlake

Synthea
- [y1] Synthea (synthetichealth) — https://synthetichealth.github.io/synthea/
- [y2] Synthea HL7 FHIR wiki — https://github.com/synthetichealth/synthea/wiki/HL7-FHIR
- [y3] Synthea PR #862 — HL7 ADT/HL7v2 generation — https://github.com/synthetichealth/synthea/pull/862
- [y4] Synthea paper (PMC) — https://pmc.ncbi.nlm.nih.gov/articles/PMC7651916/

Security / HIPAA
- [sec1] Accountable — HL7 HIPAA compliance — https://www.accountablehq.com/post/hl7-hipaa-compliance-requirements-best-practices-and-security-checklist
- [sec2] HHS — De-identification guidance (Safe Harbor / Expert Determination) — https://www.hhs.gov/hipaa/for-professionals/special-topics/de-identification/index.html
- [sec3] HHS de-identification (index) — https://www.hhs.gov/hipaa/for-professionals/special-topics/de-identification/index.html
