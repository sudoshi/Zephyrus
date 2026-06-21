# Zephyrus — Program Roadmap

**Date:** 2026-06-20
**Status:** Proposed sequencing to GA. Derived from `TARGET-ARCHITECTURE.md` + `research/00-RESEARCH-DOSSIER.md`.

This is a **program**, not a project: a real-time data backbone, a forecasting layer, an optimization engine, the web modernization, the safety/quality fabric, and Hummingbird. Each subsystem ships behind a seam, gets its own spec → plan → implementation cycle, and is independently demoable on the synthetic simulator.

---

## ⚑ Sequencing decision (2026-06-20)

The founder elected to lead with **S2 (the RTDC four-step engine + huddles)** rather than S1 — it is the most visible "product." To avoid building on sand, the **minimal real-time substrate S2 requires** (the synthetic stream simulator + a canonical census read-model) is folded into the S2 spec. The heavier backbone — HL7v2 ADT/MLLP listener, full event-sourcing/replay, Kafka-ready bus — is deferred to **S1 proper** (later). EHR connectivity (S1/S8) will **port Medgnosis's production FHIR/SMART/Bulk/EMPI stack** (Node/TypeScript) rather than green-field it; see `research/09-medgnosis-ehr-reuse.md`. The net-new ingestion work concentrates on the HL7v2 ADT listener and canonical-event mapping, which Medgnosis never needed (it is population-health, not real-time ops).

**First spec target: S2.**

## Guiding sequencing logic

- **Backbone before brains.** No forecast or optimizer matters until there is a live, event-sourced canonical census to feed it. The simulator lets us build the whole stack with zero EHR dependency.
- **Descriptive → predictive → prescriptive**, in that order — each layer consumes the one below.
- **Safety fabric is woven in from S1**, not bolted on at the end.
- **Web rewiring tracks each subsystem** (rewire the pages that consume what just went live), rather than one big-bang migration.
- **Hummingbird starts once the event bus + a real domain exist** (it rides the same Reverb broadcast), targeting the highest-value frontline roles first.

---

## Subsystems & sequence

### S0 — Foundation & monorepo hygiene  *(enabler)*
**Goal:** A clean base to build on.
- Establish pnpm/Turborepo workspace with `packages/core` (Zod schemas + API client + TanStack hooks) skeleton.
- Finish the TypeScript scaffolding already begun; lock CI (Pest/Vitest/Playwright/Pint/tsc) as merge gates.
- Stand up the Docker Compose topology for the new sidecars (`ingest`, `predict`, `optimize`) as empty health-checked services.
- **Exit gate:** green CI; all services boot; `packages/core` importable by web; no behavior change.

### S1 — Real-time data backbone + canonical census  *(THE keystone — spec this first)*
**Goal:** A genuinely live census/bed board driven by events, demoable on the simulator.
- `EventSource` interface + **synthetic stream simulator** + **HL7v2 ADT adapter** in `zephyrus-ingest`.
- Canonical operational event model; Redis Streams bus; idempotency ledger; DLQ.
- Event-sourced census projection in Postgres (`encounters`, `locations`, `bed_states`, `census_snapshots`); replay/rebuild.
- Laravel Reverb broadcast; rewire the RTDC bed-board / capacity-overview pages from mock to live.
- **Exit gate:** simulator drives a live, multi-unit census that updates web in <2 s end-to-end; census rebuildable by replay; ADT adapter parses a recorded ADT stream into identical state.

### S2 — RTDC engine (the four-step methodology) + huddle workflow
**Goal:** The IHI RTDC triple as a working daily cycle.
- `rtdc_predictions` model + the triple (predicted discharges w/ confidence tiers, predicted demand by source, signed bed-need); two-tier huddle workflow (unit → hospital bed meeting); barrier tracking (4-category); Step-4 reconciliation + per-unit reliability KPI.
- Acuity service (manual PCS + passive EHR-signal acuity); acuity-adjusted capacity.
- Rewire the existing huddle pages (ServiceHuddle/GlobalHuddle/UnitHuddle) to the live engine.
- **Exit gate:** a full RTDC day runs on the simulator — predict, plan, reconcile — with reliability scoring; huddles editable live across clients.

### S3 — Predictive layer (`zephyrus-predict`)
**Goal:** The forecasts that make capacity prediction trustworthy.
- Pluggable model service (`score/explain/health` + HTI-1 model cards); daily **discharge prediction** first; **census via flow decomposition**; **ED demand** (arrivals + admit probability).
- Vendor-deterioration ingestion (EDI/eCART/Rothman) + rule-based NEWS2/MEWS as ICU-demand inputs.
- Drift/calibration monitoring + feature store; fairness/subgroup audit harness.
- **Exit gate:** discharge & census forecasts beat naive baselines on backtest; SHAP explanations render in UI; non-device posture documented; fairness audit passes.

### S4 — Prescriptive optimizer (`zephyrus-optimize`) + safety constraints
**Goal:** Explainable next-best-action recommendations — the headline differentiator.
- **Bed-assignment optimizer** (CP-SAT, weighted bipartite + side constraints) as MVP; **safety as hard constraints** (bundle windows, NEWS2 stability, readiness gates, ratio floors, equity).
- Discharge-prioritization + sequenced EVS/transport worklist driven by unmet demand.
- Recommendation UI: red blockers / amber advisories / "why" + runner-up / override-with-rationale → weight retuning.
- **Exit gate:** optimizer returns ranked, explained bed recommendations <2 s on the simulator; no unsafe recommendation can be produced (constraint tests); overrides captured and replayable.

### S5 — Nurse staffing & assignment optimization
**Goal:** The nursing-acuity-centric core no incumbent has.
- Nurse-to-patient assignment (CP-SAT: acuity-variance fairness, continuity, geography, competency); ratio floors as hard constraints; "capacity-to-admit gated on nurse-safety-to-accept."
- Timefold multi-week rostering; fatigue/fairness ledger; missed-care/NSI risk guardrail.
- **Exit gate:** assignment drafts editable by charge nurse; fairness + safety guardrails enforced; rostering produces a compliant multi-week schedule on simulated demand.

### S6 — Quality & safety fabric (cross-cuts S1–S5, hardened here)
**Goal:** Make "speed never buys harm" visible and enforced.
- Bundle-compliance tiles (SEP-1, VTE, CLABSI/CAUTI, VAP, HAPI, falls) with degradation alarms.
- Paired driver/balancing dashboards everywhere; equity dashboard; LD.04.03.11/HAC/HRRP traceability; SPC charts for evidence-first measurement.
- **Exit gate:** every throughput KPI renders with its balancing measure; safety chips appear on every recommendation; equity audit dashboard live.

### S7 — Hummingbird mobile (Expo/RN)
**Goal:** The frontline companion, in lockstep with web.
- Monorepo `apps/mobile`; shares `packages/core`; rides Reverb with snapshot-on-reconnect.
- Role home screens: charge nurse → bed manager → EVS/transport; tiered push + closed-loop acknowledgment SLO; SQLite offline outbox; HIPAA device posture.
- **Exit gate:** live census/board/tasks on device, updating in lockstep with web; push delivery + ack instrumented to SLO; offline read + queued-ack works; TestFlight/Play internal build.

### S8 — Perioperative & ED depth, then real-EHR integration & GA hardening
**Goal:** Round out the value chain and go live for real.
- Extend the engine to perioperative (surgical smoothing, OR utilization) and ED (arrival forecasting, boarding instrumentation to ECAT thresholds) using the existing built-out UIs.
- First **site-specific real EHR integration** (FHIR `$export` + Subscription and/or live ADT) behind the same seam.
- GA hardening: load/soak on the bus, security review, audit completeness, model governance sign-off, docs, deploy runbooks.
- **Exit gate (GA):** runs against a real ADT/FHIR feed at one site; SLOs met under realistic load; security + model governance reviews pass; balancing measures show no safety regression.

---

## Dependency graph

```
S0 ─► S1 ─► S2 ─► S3 ─► S4 ─► S5
             │     │     │
             └─────┴─────┴──► S6 (safety fabric, cross-cutting)
        S1 ─────────────────► S7 (Hummingbird; deepens after S2/S4)
   S2,S3,S4,S5 ──────────────► S8 (periop/ED depth + real EHR + GA)
```

S1 is the keystone — almost everything depends on it. S6 is woven through S1–S5 and hardened as its own pass. S7 can begin in parallel once S1+S2 exist.

---

## GA readiness gates (program-level)

1. **Live data:** real ADT/FHIR feed ingested at ≥1 site through the production seam.
2. **Real-time SLO:** event→UI/mobile latency target met under realistic load; bus DLQ within tolerance.
3. **Predictive validity:** forecasts beat baselines on local backtest; drift/calibration monitored; fairness audits pass.
4. **Prescriptive safety:** zero unsafe recommendations provable by constraint tests; overrides captured + learned from.
5. **Safety/quality fabric:** balancing measures + bundle tiles + equity dashboard live; LD.04.03.11/HAC/HRRP traceable.
6. **Mobile lockstep:** Hummingbird in store-ready build; push/ack SLO instrumented; offline verified.
7. **Governance:** non-device regulatory posture documented; HTI-1 model cards complete; security review passed; audit log complete.
8. **Evidence:** SPC-based before/after measurement instrumented for a prospective, controlled evaluation.

---

## Recommended first spec

**S2 — RTDC four-step engine + huddles** (founder's choice), with a **minimal simulator + canonical census read-model folded in** as its data substrate. This delivers the most visible product first while standing on real (simulated) live data, not mocks. The S1 backbone proper (HL7v2 ADT, full event-sourcing/replay) and EHR connectivity (porting Medgnosis) follow. The first design spec (`docs/superpowers/specs/`) and implementation plan target **S2 (+ its minimal substrate)**.

> Each subsystem gets its own brainstorm → spec → `writing-plans` → implementation cycle. This roadmap is the map; the specs are the territory.
