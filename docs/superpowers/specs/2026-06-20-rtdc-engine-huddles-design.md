# Design Spec — S2: RTDC Four-Step Engine + Huddles (with minimal real-time substrate)

**Date:** 2026-06-20
**Subsystem:** S2 (first build) — see `.planning/PROGRAM-ROADMAP.md`
**Grounded in:** `.planning/research/00-RESEARCH-DOSSIER.md` (§2 RTDC methodology), `.planning/TARGET-ARCHITECTURE.md`
**Status:** Proposed. Awaiting founder review before `writing-plans`.

---

## 1. Goal

Turn Zephyrus's most built-out UI area (RTDC huddles) from a mock-data mockup into a **working IHI Real-Time Demand Capacity Management engine**: a daily four-step cycle (predict capacity → predict demand → develop plan → evaluate) run through a two-tier huddle workflow (unit → hospital bed meeting), standing on a **genuinely live (simulated) census** that updates web clients in real time.

This is the first product a clinician could sit in front of and run a real RTDC huddle with. It deliberately uses **clinician-entered predictions** (authentic to the IHI method, which starts with clinical judgment); ML-assisted prediction is S3, the optimizer is S4.

## 2. Scope

### In scope
1. **Minimal real-time substrate** — a synthetic census simulator emitting **canonical operational events** through an in-process dispatcher into a Postgres census projection, broadcast via Laravel Reverb. (Designed so S1 later swaps the in-process dispatcher for Redis Streams without touching producers or consumers.)
2. **Canonical operational model** (subset): `units`, `beds`/`locations`, `encounters` (minimal), `census_snapshots`, plus a basic manual **acuity** input.
3. **The RTDC triple** — `rtdc_predictions` per unit × service_date × horizon: predicted discharges (definite/probable/possible tiers + weighted expected), predicted demand (by source: ED/OR/transfer/direct), acuity-adjusted `capacity_now`, and the signed **bed-need** integer.
4. **Two-tier huddle workflow** — unit huddles (predictions + barriers per unit) feeding a hospital-wide bed meeting (aggregated bed-need + system plan), live and multi-user.
5. **Barrier tracking** — 4-category (medical / logistical / placement / social), coded reason, owner, status, age.
6. **Step-4 reconciliation** — a daily job comparing predicted vs. actual (from the census), computing **per-unit discharge-prediction reliability** as a headline KPI.
7. **Frontend rewiring + TS migration** of the existing RTDC huddle pages (`ServiceHuddle`, `GlobalHuddle`, `UnitHuddle`) to the live engine via TanStack Query + Reverb.

### Explicitly out of scope (deferred, with the seam left for them)
- HL7v2 ADT/MLLP ingestion, FHIR adapters, EHR connectivity → **S1/S8** (port Medgnosis).
- Redis Streams / full event-sourcing + replay → **S1** (S2 uses an in-process dispatcher behind the same event contract).
- ML forecasts (discharge/census/ED demand) → **S3** (S2 predictions are clinician-entered).
- Prescriptive optimizer / bed assignment → **S4**.
- Nurse staffing/assignment optimization → **S5**.
- Bundle-compliance/equity safety fabric → **S6** (S2 surfaces bed-need + balancing context but not the full safety-constraint engine).
- Hummingbird mobile → **S7** (S2 ships the Reverb broadcast it will later consume).

## 3. The minimal substrate — how S2 is "live" without an EHR

```
SyntheticSimulator ──emit──► CanonicalEvent ──► InProcessDispatcher ──► CensusProjector ──► Postgres (census_snapshots, bed_states)
   (tick loop)                (EncounterStarted,        │                                         │
                               Transferred,             └────────────► ReverbBroadcaster ────────┴──► Web clients (live)
                               Discharged, …)
```

- **`EventSource` contract** is defined now; `SyntheticSimulator` is its first implementation. The HL7v2/FHIR sources (later) implement the same contract.
- **`CanonicalEvent`** is the only shape the domain consumes — anti-corruption boundary established from day one.
- **`InProcessDispatcher`** is a synchronous in-process publisher in S2. S1 replaces it with a Redis Streams publisher/consumer; producers (simulator) and consumers (projector) don't change. This is the explicit seam that prevents rework.
- **`CensusProjector`** applies events to materialized read tables. In S2 it can apply directly; the projection is still **rebuildable from a recorded event log** (we persist emitted canonical events to an append-only `operational_events` table even in S2, so replay works and S1 is a smaller step).

Simulator realism (S2 baseline): per-unit bed inventory, diurnal admit/discharge curves, LOS distributions, configurable surge; Synthea-seeded patient demographics optional. Deterministic seed for tests.

## 4. Domain model (new `prod` tables)

| Table | Purpose | Key fields |
|-------|---------|-----------|
| `units` | Nursing units / departments | id, name, type, staffed_bed_count, target_occupancy (or access standard), ratio_floor |
| `beds` | Bed inventory (subset of `locations`) | id, unit_id, label, status (available/occupied/blocked/dirty), bed_type, isolation_capable |
| `encounters` | Minimal admission record | id, patient_ref (pseudonymous), unit_id, bed_id, admitted_at, expected_discharge_date, acuity_tier, status |
| `census_snapshots` | Materialized live census | unit_id, captured_at, staffed_beds, occupied, available, blocked, acuity_adjusted_capacity |
| `operational_events` | Append-only canonical event log | id, event_id (idempotency), type, encounter_ref, payload (jsonb), occurred_at |
| `rtdc_predictions` | **The triple** | unit_id, service_date, horizon, predicted_discharges_{definite,probable,possible,weighted}, demand_{ed,or,transfer,direct,expected}, capacity_now, bed_need, status, created_by |
| `rtdc_plans` | Plan actions from develop-plan step | id, rtdc_prediction_id or bed_meeting_id, action_text, owner, due, status |
| `huddles` | Huddle sessions | id, type (unit/hospital), unit_id (nullable), service_date, status (open/closed), facilitator_id |
| `barriers` | Discharge/flow barriers | id, encounter_id or unit_id, category (medical/logistical/placement/social), reason_code, description, owner, status, opened_at, resolved_at |
| `rtdc_reconciliations` | Step-4 results | unit_id, service_date, predicted vs actual discharges/admits, reliability_score |

`acuity` in S2 is a manual tier (1–4) on the encounter; `acuity_adjusted_capacity` = function of staffed beds, ratio floor, and aggregate unit acuity (per research §4: `required = max(ratio_floor, acuity_demand)`). The richer passive-signal acuity engine is a later enhancement.

## 5. The four-step engine (`RtdcService`)

1. **Predict capacity** — for each unit, clinicians mark expected discharges with confidence tiers (definite/probable/possible) at two horizons (`by_2pm`, `by_midnight`); the engine computes a weighted expected discharge count.
2. **Predict demand** — clinicians/bed manager enter expected admissions by source (ED/OR/transfer/direct); engine sums to expected demand.
3. **Develop plan** — engine computes `bed_need = demand − (available + weighted_discharges)` per unit; where bed-need is positive, the unit huddle records plan actions (owner + due); the hospital bed meeting aggregates and resolves cross-unit moves.
4. **Evaluate** — next-day reconciliation compares predictions to census actuals and updates the per-unit **reliability** KPI; recurring barrier reasons surface as candidate improvement items.

All four steps are pure domain logic over the tables above; the engine never reads mock data.

## 6. Huddle workflow (two-tier, live)

- **Unit huddle**: facilitator opens a huddle for a unit + service_date; participants (presence via Reverb) enter the triple inputs and barriers; bed-need renders live.
- **Hospital bed meeting**: aggregates all unit bed-needs into a system view; records system-level plan actions and cross-unit decisions; closes the cycle.
- Concurrency: optimistic updates with last-writer-wins per field + Reverb broadcast of deltas; presence shows who's in the huddle. (No CRDT complexity in S2.)

## 7. API & real-time

- **JSON API** (Laravel, `/api/rtdc/*`): units + live census; CRUD for predictions, huddles, barriers, plans; reconciliation read. Validated with FormRequests; responses typed and mirrored into `packages/core` Zod schemas (web consumes those via TanStack Query).
- **Reverb channels**: `unit.{id}` (census + huddle deltas), `hospital.beds` (bed-meeting aggregate), presence channels per huddle. One broadcast shape, reused by Hummingbird in S7.
- **Snapshot-on-reconnect** required on the web client (research §7 reliability rule) even though S2 is web-only — establishes the pattern.

## 8. Frontend (rewire + migrate)

- Migrate `ServiceHuddle.jsx`, `GlobalHuddle.jsx`, `UnitHuddle.jsx` and their RTDC components to TypeScript; replace mock-data imports with TanStack Query hooks from `packages/core` + Reverb subscriptions.
- Preserve the Acumenus Clinical design system and existing component structure (CapacityTimeline, DemandCapacityModel, etc.) — this is rewiring, not redesign.
- New small UI: confidence-tier discharge entry, demand-by-source entry, bed-need readout, barrier board, reliability KPI tile.

## 9. Architecture & isolation

- **Units of responsibility:** `EventSource` (sim) | `CanonicalEvent` contract | `InProcessDispatcher` (seam) | `CensusProjector` | `ReverbBroadcaster` | `RtdcService` (four-step) | `HuddleService` | `AcuityService` | `BarrierService` | `ReconciliationService` (scheduled). Each is independently testable; each has one job.
- **The seam that matters:** producers and consumers depend on the `CanonicalEvent` + dispatcher interface, never on the simulator or Redis directly — so S1 is a swap, not a rewrite.
- Files kept focused (per coding-style: 200–400 lines typical). Domain services replace today's thin/mock `RTDCService` (14 lines) and `DashboardService` mock methods.

## 10. Error handling, safety, testing

- **Error handling:** events validated at the dispatcher (canonical schema); invalid events dead-lettered to a table (not dropped) even in S2; API boundary validates via FormRequests + Zod; fail-loud in dev, structured errors in prod.
- **Safety (S2 slice):** bed-need never recommends a discharge — it surfaces predicted capacity vs demand for human huddle decisions. The full safety-constraint engine is S6; S2 must not imply any auto-action. Reliability + barriers are shown alongside bed-need so speed is never the only number on screen.
- **Testing:** deterministic simulator seed drives end-to-end tests; Pest (domain + API), Vitest (`packages/core` + hooks), Playwright (a full simulated huddle cycle). Reconciliation tested by running a sim day forward and asserting reliability math. Target ≥80% on new domain code.

## 11. Acceptance criteria (S2 exit gate)

1. Simulator drives a live multi-unit census that updates the web huddle UI in **< 2 s** end-to-end.
2. A facilitator can run a complete RTDC cycle for a unit: enter tiered discharges + demand-by-source, see computed bed-need, log barriers and plan actions, close the unit huddle, and roll it up into a hospital bed meeting.
3. Step-4 reconciliation runs for a simulated prior day and produces a per-unit reliability score.
4. `census_snapshots` is **rebuildable by replaying `operational_events`** (proves the S1 seam).
5. Existing RTDC huddle pages run on live data with **zero mock-data imports remaining** in those pages; migrated to TypeScript; CI green (Pint/tsc/Pest/Vitest/Playwright).
6. The Reverb broadcast contract is documented and shaped for reuse by Hummingbird.

## 12. Open questions for founder

1. **Unit set for the demo:** model a specific unit mix (e.g., ED, 3× med/surg, 1× ICU, 1× step-down for a ~300-bed hospital), or make it config-driven from the start? *(Recommend: config-driven, seeded with that default mix.)*
2. **Horizons:** the IHI default pairs a "by 2pm / by midnight" pair. Keep those two, or your preferred horizon labels?
3. **Acuity in S2:** manual 1–4 tier per patient is the minimum. Acceptable for now, with the passive-signal acuity engine deferred?
