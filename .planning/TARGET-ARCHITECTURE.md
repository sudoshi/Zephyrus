# Zephyrus — Target (North-Star) Architecture

**Date:** 2026-06-20
**Status:** Proposed north star. Derived from `.planning/research/00-RESEARCH-DOSSIER.md`.
**Scope:** The end-state system, including Hummingbird mobile. Not everything ships at once — see `PROGRAM-ROADMAP.md` for sequencing.

---

## 1. Architectural principles

1. **Event-driven core.** A canonical operational event stream is the heartbeat. The live census, the forecasts, the optimizer, the dashboards, and the mobile app are all readers/writers of that stream — not a request-response CRUD app.
2. **Anti-corruption at the edge.** HL7v2/FHIR/vendor vocabulary dies in the ingestion sidecar. The Laravel domain and everything above it speaks only the **canonical operational model**.
3. **Polyglot by responsibility, not by fashion.** Laravel owns the domain, API, auth, and web rendering. Python owns ML and combinatorial optimization (where its ecosystem is decisive). Node/Python owns protocol adapters. No Python where PHP suffices; no PHP where a solver lives.
4. **Safety is the feasible region.** Optimization and predictions operate inside hard safety constraints. Nothing unsafe is ever surfaced as a recommendation.
5. **Advice, not autopilot.** Every prescriptive output is an explainable suggestion with a runner-up and an override path that feeds learning.
6. **One contract, two clients.** Web and mobile share validated TypeScript contracts (`packages/core`); they share no UI.
7. **Sim == prod path.** The synthetic stream simulator is a first-class `EventSource`. Demo, CI, and live traffic flow through identical code.
8. **Preserve the frontend asset.** The existing React/Inertia UI is migrated and rewired, not rewritten.

---

## 2. System context (C4 level 1)

```
        ┌────────────────────────────────────────────────────────────────┐
        │                         EXTERNAL                                 │
        │  EHR (Epic/Oracle/Meditech)    Synthetic Simulator   Vendor ML   │
        │   • HL7v2 ADT/SIU/ORM           (Synthea-seeded)      (EDI/eCART) │
        │   • FHIR R4 ($export + Sub)                                       │
        └───────────────┬───────────────────────┬──────────────┬──────────┘
                        │ (same EventSource interface)          │ scores
                        ▼                                       ▼
        ┌────────────────────────────────────────────────────────────────┐
        │                    ZEPHYRUS PLATFORM                             │
        │                                                                  │
        │  Ingestion Sidecar ──► Event Bus ──► Domain (Laravel) ──► Web UI │
        │  (Python/Node)        (Redis Str.)   + Census Projection  (React)│
        │       │                   │              │      │                │
        │       │                   ▼              ▼      ▼                │
        │       │            Predictive Svc   Optimizer  Reverb (WS) ──────┼──► Hummingbird
        │       │              (Python ML)     (Python)         broadcast  │    (Expo/RN)
        │       └───────────────────────────────────────────────► Audit   │
        └────────────────────────────────────────────────────────────────┘
```

**Actors:** charge nurse, nursing supervisor / bed manager / flow coordinator, hospitalist/attending, case manager, EVS, transport, perioperative scheduler, ED charge, administrator/analyst.

---

## 3. Components (C4 level 2)

### 3.1 Ingestion Sidecar — `zephyrus-ingest` (Node/TypeScript, new — ports Medgnosis)
- Hosts the **`EventSource`** abstraction. Concrete sources:
  - **HL7v2 ADT listener** (MLLP/TLS, A01/A02/A03/A08/A11/A13, SIU, ORM/ORU) — priority source, **net-new** (Medgnosis has no HL7v2).
  - **FHIR adapter** — Bulk `$export` NDJSON backfill + rest-hook Subscription (R5 Backport) live tail. **Ported from Medgnosis** (`fhirClient.ts`, `vendorAdapters/`, `bulkData.ts`, SMART launch).
  - **Flat-file/CSV adapter** — batch import for low-tech sites.
  - **Synthetic stream simulator** — Synthea-seeded patients + Poisson/diurnal/LOS/surge timing.
- Responsibilities: parse → map to **canonical operational event** → enforce idempotency (`event_id` ledger / `content_hash` crosswalk, Medgnosis pattern) → publish to the event bus → ACK source. PHI minimized/pseudonymized here.
- **EHR onboarding** reuses Medgnosis's tenant registry + EMPI (three-tier identity resolution, never auto-merge on demographics) + staging→hydration→crosswalk pipeline. See `research/09-medgnosis-ehr-reuse.md`.
- **Language decision:** Node/TypeScript (not Python) — Medgnosis's proven stack is TS, and TS lets ingestion share `packages/core` contracts with web + mobile. Python is reserved for `predict` + `optimize` where its ML/OR ecosystem is decisive.
- Why separate from Laravel: long-lived raw TCP/MLLP sockets, TLS, and protocol libraries are a poor fit for PHP-FPM request lifecycles.

### 3.2 Event Bus — Redis Streams (existing Redis)
- Topics partitioned/ordered by `encounter_ref`. At-least-once delivery; idempotent consumers; dead-letter stream on parse/validation failure. **Seam kept Kafka-ready** (one interface) for scale.

### 3.3 Domain + Census Projection — Laravel 11 (existing, re-architected)
- **Canonical operational model** (Postgres `prod` schema, new): `encounters`, `locations` (bed/room/ward graph via `partOf`), `bed_states`, `census_snapshots`, `rtdc_predictions` (the triple), `huddles`, `barriers`, `acuity_scores`, `assignments`, `recommendations`, `overrides`, `safety_events`, `audit_log`.
- **CQRS read side:** event consumers materialize read tables from the append-only canonical ledger; the live census is **rebuildable by replay**.
- **Domain services** replace today's thin/mock services: `CensusService`, `RtdcService` (the four-step engine), `AcuityService`, `HuddleService`, `CapacityService`, plus thin clients to the Predictive and Optimizer sidecars.
- Auth/RBAC unchanged (Sanctum + Spatie; the protected auth flow in `.claude/rules/auth-system.md` is preserved verbatim).

### 3.4 Predictive Service — `zephyrus-predict` (Python FastAPI, new)
- Uniform model interface: `score()` / `explain()` (SHAP) / `health()`, each carrying **HTI-1 31-attribute model-card metadata**.
- v1 models: **daily discharge prediction** (XGBoost) → expected-discharge counts; **census via flow decomposition**; **ED demand** (hourly arrivals + admit-probability). Vendor deterioration scores (EDI/eCART/Rothman) ingested, not rebuilt; NEWS2/MEWS computed as rules.
- Feature store on FHIR-derived features; offline/online parity enforced; drift + calibration (Brier) monitoring from day one. **Non-device by design.**

### 3.5 Optimization Service — `zephyrus-optimize` (Python FastAPI, new)
- Stateless: JSON snapshot in → ranked, explainable recommendations + score breakdown out. Strict solve-time budget (~500 ms–2 s).
- **OR-Tools CP-SAT** for bed-assignment (MVP) and nurse-to-patient assignment; **Timefold** for multi-week rostering (explainable `ScoreAnalysis`).
- **Safety constraints are first-class**: bundle windows, NEWS2 stability, discharge-readiness gates, ratio floors, equity thresholds are hard constraints / penalties. Unsafe moves are pruned before output.
- Every output: hard-violation blockers (red), soft advisories (amber), the "why", the runner-up, and an override hook that persists rationale for weight retuning.

### 3.6 Real-time fan-out — Laravel Reverb (new)
- One broadcast feeds **both** web and Hummingbird. Channels per unit/role. Presence for huddle participation. Snapshot-on-reconnect is mandatory on clients.

### 3.7 Web App — React/Inertia (existing, migrated)
- Full TypeScript migration (today ~12%). Rewire from mock data to TanStack Query against the live API + Reverb. Preserve the Acumenus Clinical design system, RTDC pages, analytics, huddle UIs.

### 3.8 Hummingbird — Expo / React Native (new)
- Consumes the same API + Reverb bus; shares `packages/core` (Zod schemas, API client, TanStack hooks).
- Role-based home screens (charge nurse, bed manager, EVS/transport first), tiered push (Expo + APNs/FCM), closed-loop acknowledgment with an SLO, SQLite offline outbox, HIPAA-grade device posture.

### 3.9 Shared contracts — `packages/core` (TypeScript, new monorepo package)
- Zod schemas + generated API client + TanStack Query hooks. The single validation truth web and mobile both import. pnpm/Turborepo workspace.

---

## 4. The canonical data spine: the RTDC triple

Everything orbits one structure, written daily per unit and reconciled the next day:

```
RtdcPrediction {
  unit_id, service_date, horizon (by_2pm | by_midnight),
  predicted_discharges:  { definite, probable, possible, weighted_expected },
  predicted_demand:      { from_ed, from_or, from_transfer, from_direct, expected },
  capacity_now:          { staffed_beds, occupied, available, acuity_adjusted_capacity },
  bed_need:              signed_integer,   // demand − capacity, the headline number
  plan:                  [actions...],
  actuals:               { discharges, admissions },   // filled next day (Step 4)
  reliability:           computed per-unit prediction accuracy   // headline KPI
}
```

- **Descriptive layer** populates `capacity_now` from the live census projection.
- **Predictive layer** populates `predicted_discharges` / `predicted_demand`.
- **Prescriptive layer** populates `plan` (recommended actions) under safety constraints.
- **Step 4** reconciles `actuals` and updates `reliability` → the learning loop.

---

## 5. Data flow: an admission, end to end

1. EHR emits HL7v2 **A01** (admit) → ingest sidecar parses → canonical `EncounterStarted` event → Redis Streams.
2. Laravel consumer updates `encounters` + `bed_states`; census projection recomputes; Reverb broadcasts the delta → web board + Hummingbird update live.
3. Predictive service scores the new encounter's expected LOS / discharge horizon → feeds `predicted_discharges` for its unit.
4. A bed request for the next ED admit → optimizer snapshots queue × eligible beds, applies safety + acuity + cohorting constraints → returns ranked, explained bed recommendations.
5. Bed manager (web or Hummingbird) accepts/edits/overrides → override + rationale persisted → Reverb broadcasts assignment → EVS gets a sequenced clean task on their phone.
6. Overnight: Step 4 reconciliation scores yesterday's predictions; reliability KPIs and recurring barriers surface as improvement items.

---

## 6. Cross-cutting concerns

| Concern | Approach |
|---------|----------|
| **Security/PHI** | TLS everywhere; pseudonymous `patient_ref`; PHI minimized at adapter; Safe-Harbor de-id gate for demos; append-only audit log; existing protected auth flow preserved. |
| **Explainability** | SHAP for predictions; CP-SAT score breakdowns + runner-up for recommendations; HTI-1 model cards. |
| **Regulatory** | Non-device posture (operational, HCP-reviewable, explainable). Clinical alerting stays in the EHR. LD.04.03.11 / HAC / HRRP / SEP-1 / Leapfrog traceability. |
| **Equity** | Continuous subgroup audits; never cost-as-need; equity dashboard peer to efficiency view. |
| **Observability** | SPC charts + balancing measures in-product; model drift/calibration; event-bus DLQ metrics; recommendation acceptance/override rates. |
| **Resilience** | Event sourcing → replay/rebuild; snapshot-on-reconnect on clients; optimizer is stateless + time-bounded. |
| **Testing** | Simulator drives deterministic end-to-end tests; Pest (PHP), Vitest (TS), Pytest (Python), Playwright (E2E) — the test scaffolding already started in recent commits. |

---

## 7. What changes vs. today

| Area | Today | Target |
|------|-------|--------|
| Data | ~100% mock/seeded | Live, event-sourced canonical census from pluggable adapters (or simulator) |
| Real-time | None (request-response) | Redis Streams + Reverb broadcast to web + mobile |
| Backend logic | 4 thin/mostly-mock services | Domain services + Python predictive + Python optimizer sidecars |
| Prediction | None | Pluggable model service (discharge/census/ED demand first) |
| Optimization | None ("optimization" was a dashboard) | CP-SAT bed-assignment + nurse-assignment, safety-constrained, explainable |
| Frontend types | ~12% TS | Full TS, rewired to live API via TanStack Query |
| Mobile | None | Hummingbird (Expo/RN), lockstep via `packages/core` + Reverb |
| Safety | Implicit | First-class optimizer constraint + paired balancing dashboards |

---

## 8. Technology summary

- **Web/domain:** Laravel 11, PHP 8.4, Inertia, React 19 + TypeScript, Vite, TailwindCSS, TanStack Query, Zustand. PostgreSQL 16/17 (`prod` schema). Redis 7. Laravel Reverb.
- **Sidecars:** `zephyrus-ingest` = **Node/TypeScript** (ports Medgnosis FHIR/SMART/Bulk stack + EMPI; new HL7v2 MLLP listener; BullMQ workers). `zephyrus-predict` + `zephyrus-optimize` = **Python 3.12 / FastAPI / Pydantic v2** (XGBoost/scikit/SHAP; OR-Tools CP-SAT, Timefold).
- **Mobile:** Expo / React Native + TypeScript; EAS Build/Update; Expo push. `packages/core` shared (pnpm/Turborepo).
- **Infra:** Docker Compose (existing), CI already scaffolded (Pest/Vitest/Playwright + main.yml).

> This is the destination. The path is in `PROGRAM-ROADMAP.md`.
