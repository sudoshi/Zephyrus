# Zephyrus — Research Dossier (Synthesis)

**Date:** 2026-06-20
**Purpose:** Evidence base for re-architecting Zephyrus into the best real-time hospital demand/capacity optimization platform in the world, with a synced mobile companion ("Hummingbird").
**Status:** Foundation document. Synthesizes eight cited pillar dossiers in this directory (`01`–`08`). ~29k words of primary research, ~350 cited sources.

> Read this first. Each claim links to a pillar file where the evidence and source URLs live.

---

## 1. The thesis: where "best in the world" actually lives

The capacity/patient-flow software market is **crowded but structurally stale** (see `06-competitive-landscape.md`). It splits four ways: workflow incumbents (TeleTracking — deep bed management, thin AI, RTLS-hardware dependency), optimization specialists (LeanTaaS iQueue + Hospital IQ — strong math, fragmented point products), AI-native challengers (Qventus — agentic, EHR-embedded, narrow), and the EHR gorillas (Epic Grand Central, Oracle CareAware) now eating the standalone command-center market from inside the record.

Two facts define the opening:

1. **Epic is absorbing the command center from inside the EHR.** Johns Hopkins — the GE/Hopkins command center being the canonical case study — migrated capacity dashboards into Epic. A standalone product that is *just* dashboards loses to the EHR.
2. **The best controlled study of an AI command center found no patient-safety effect — the control site improved comparably** (PMC9884873). The active ingredient in flow improvement is **human + process change**, not software alone. Most vendor ROI claims are large, consistent, and **un-peer-reviewed**.

No incumbent is simultaneously: **vendor-agnostic / open-standards, nursing-acuity-centric, explainable-prescriptive, mobile-first for the frontline, safety-tied, affordable, and evidence-first.** That seven-way intersection is Zephyrus's credible, defensible path to "best in the world." Everything below serves that intersection.

### The seven differentiators (north star)

| # | Differentiator | Why it wins | Pillar |
|---|----------------|-------------|--------|
| D1 | **Vendor-agnostic open-standards ingestion** (HL7v2 + FHIR + flat file behind one interface) | Runs on Epic, Oracle, Meditech, or mixed regional networks; the only affordable agnostic option | 05, 06 |
| D2 | **Nursing-acuity-centric optimization** (optimize against workload/acuity, not bed counts) | No incumbent makes acuity the organizing principle; ties directly to the mortality evidence | 02, 04 |
| D3 | **Explainable prescriptive recommendations** (every next-best action shows its reasoning + runner-up) | Directly answers the Epic-Sepsis-Model trust crisis; auditable/governable | 03, 04 |
| D4 | **Mobile-first frontline companion (Hummingbird)** | The gap no flow vendor and no comms vendor (TigerConnect/Voalte) fills | 08, 06 |
| D5 | **Flow tied to clinical safety/quality** (safety as a first-class optimizer constraint) | Counters the category's dollars-not-safety blind spot | 07, 01 |
| D6 | **Affordability + open core** for the ~4,000 community/critical-access hospitals priced out | Untapped market; no-hardware deployment (no RTLS) | 06 |
| D7 | **Evidence-first credibility** (prospective, controlled, peer-reviewed validation + built-in SPC) | The one vendor whose outcomes are provable in a category built on case studies | 01, 07 |

---

## 2. The core methodology: IHI RTDC is the literal product (`01`)

The platform's name and center is **Real-Time Demand Capacity Management** — an actual, named IHI methodology, not a generic phrase. It is a daily four-step cycle run through a **two-tier huddle** structure (unit-level huddles → hospital-wide bed meeting):

1. **Predict capacity** — how many beds will each unit free today (predicted discharges).
2. **Predict demand** — how many admissions will each unit receive (from ED, OR, transfers, direct).
3. **Develop a plan** — reconcile the gap; act on the signed **bed-need integer** (demand − capacity) per unit.
4. **Evaluate yesterday's plan** — reconcile prediction vs. actual; this is a *learning loop*, and per-unit discharge-prediction reliability becomes a headline KPI.

**This is the canonical data model of Zephyrus.** Per unit, per day: a confidence-weighted predicted-discharge count (definite/probable/possible tiers, two horizons), a source-segmented predicted-demand count, and a single signed bed-need integer. Everything else — predictive ML, the optimizer, the mobile app — feeds or consumes this triple.

**Supporting science** (strong on mechanism, weaker on intervention): Little's Law and Erlang B/C for bed sizing; the ~85% occupancy danger threshold (~92.5% critical) should be **computed per unit** from a chosen access standard, not hardcoded; Litvak's natural-vs-artificial variability and **surgical smoothing** (separate scheduled from unscheduled flow everywhere); ED **boarding** as the central safety metric (CMS ECAT 60/240/480-min thresholds); NHS bundle constructs (**SAFER**, **Red2Green**, **EDD + Clinical Criteria for Discharge**, **Discharge to Assess** pathways, stranded/super-stranded review).

---

## 3. Safety is a constraint, not a report (`07`)

The founder's non-negotiable — speed must never buy harm — is achievable only architecturally. The evidence is unambiguous: crowding/high occupancy raises in-hospital and 30-day mortality; ED boarding drives medication errors, delayed sepsis care, and excess mortality, worst for frail/pediatric/psychiatric patients; crowding is the strongest predictor of LWBS. But relieving crowding via premature discharge/transfer just trades one harm for another.

**Resolution: safety is the feasible region the optimizer searches**, not a downstream dashboard.

- Encode bundle windows (sepsis/SEP-1, VTE, CLABSI/CAUTI, VAP, HAPI, falls), NEWS2 stability, discharge-readiness gates, and equity thresholds as **hard constraints / penalty terms** in the optimizer — unsafe moves are pruned before they surface.
- Every recommendation renders with **inline safety-check chips**; no green light until they clear.
- **Paired driver/balancing dashboards**: never show a throughput KPI without its safety balancing measure beside it (LOS↓ next to readmissions; bed-turn next to HAI/HAPI/falls).
- **Capacity pressure may raise the *priority* of evaluating a ready patient; it may never lower the readiness *threshold*.**
- Continuous **equity audits**; never rank by cost/charges/prior utilization (the Obermeyer 2019 bias).

---

## 4. Nursing operations is the differentiator, not the afterthought (`02`)

Nurse staffing is a clinical-risk variable, not a cost line: each additional patient per nurse raises 30-day mortality odds ~7–16% (Aiken). Design rules:

- **Ratio floors are hard constraints; acuity sets the real target**: `required = max(ratio_floor, acuity_demand)`. Ship California Title 22 as a configurable ruleset; never propose a floor-breaching plan.
- **Acuity-first, census-never.** Every capacity/workload number is acuity-adjusted and **ADT-aware** (each admit/discharge = 60–90+ min hidden RN load). Deprecate raw midnight census as an input.
- **Pluggable acuity engine** — support manual PCS *and* passive EHR-signal acuity (drips, devices, isolation, fall/Braden, total-care ADLs) to minimize charting burden.
- **Multi-objective nurse-assignment optimizer as an editable draft** — minimize acuity variance (fairness), maximize continuity for high-acuity patients, minimize geographic spread, match competency; charge nurse always overrides with captured rationale.
- **"Capacity to admit" is gated on nurse-safety-to-accept, not bed availability alone.** This single rule keeps the platform on the right side of the staffing-outcomes evidence.
- Missed-care (MISSCARE) is the mechanism translating overload into nurse-sensitive harm; the tool must be **the nurse's advocate against overload** (fairness ledger, fatigue limits, explainability), or it accelerates burnout-driven turnover.

---

## 5. The three optimization layers

### 5a. Descriptive — live state (foundation)
A real-time, event-sourced census/bed board, broadcast to web and mobile. This is table stakes but must be *genuinely real-time* (not request-response, which Zephyrus is today).

### 5b. Predictive — pluggable model service (`03`)
- **Build daily discharge-prediction first** (per-patient "discharge in 24/48h", XGBoost; ~36% F1 over baseline, ~19% excess-day reduction in deployment). It is the single highest-leverage signal and drives the census forecast.
- **Decompose census into flow** (current + predicted admits − predicted discharges) rather than forecasting net level directly.
- **Split ED demand** into hourly arrival volume + per-patient admit probability.
- **Don't rebuild clinical deterioration models** — compute open NEWS2/MEWS as rules; ingest vendor scores (EDI/eCART/Rothman) as ICU step-up/step-down demand inputs; keep clinical alerting in the cleared EHR tool.
- **Stay non-device by design**: ship only operational, HCP-reviewable forecasts with SHAP explainability → stays inside the 21st Century Cures CDS exemption. HTI-1/DSI 31-attribute model-card metadata on every model.
- **Pluggable model service** (`score()/explain()/health()`): internal, vendor, or rule-based models swap without touching the optimizer.

### 5c. Prescriptive — the optimizer (`04`)
- **Bed-assignment optimizer is the MVP** — most bounded (live queue × eligible beds, sub-second solve), central to throughput, naturally drives discharge prioritization. **Formulation:** weighted bipartite assignment with side constraints — hard = isolation/gender/capability; soft (penalized) = distance, transfer avoidance, cohorting, preference, and a *flexibility* term penalizing fragmentation of open beds.
- **Nurse-to-patient assignment is the fast-follow**; solve jointly later.
- **Tech:** stateless Python **FastAPI** microservice; **OR-Tools CP-SAT** default solver (free, fast, logic-heavy fit); **Timefold** for multi-week rostering (native domain + explainable `ScoreAnalysis`); strict solve-time budget (~500 ms–2 s). Snapshot all inputs; never call remote services inside score calc.
- **Advice, not autopilot.** Hard violations = red blockers, soft = amber advisories; always show the "why" + the runner-up; capture every override + reason to retune weights (closed loop).

---

## 6. The data backbone: pluggable adapters + event-sourced census (`05`)

- **Adapters live in a Python/Node ingestion sidecar, not Laravel** (Laravel can't host long-lived raw-MLLP/TLS sockets). All sources implement one **`EventSource`** interface.
- **HL7v2 ADT is the priority source** — A01/A02/A03 + A11/A13 cancels + the PV1 assigned-location field reconstruct a real-time census. MLLP transport, TLS-wrapped; `MSH-10` = idempotency key.
- **FHIR adapter** = Bulk `$export` NDJSON backfill + rest-hook Subscription (R5 Backport) live tail; `Location.partOf` = authoritative bed/room/ward graph. **CDS Hooks** = outbound channel for surfacing recommendations into EHR workflow.
- **Canonical operational event** is the *only* contract the Laravel/Postgres domain sees (anti-corruption layer) → source swaps never touch domain or UI.
- **Event-sourced CQRS census projection**: append-only canonical event ledger → materialized Postgres read tables → broadcast live via **Laravel Reverb**.
- **Redis Streams now** (already in stack), seam kept **Kafka-ready**; idempotency via `event_id` ledger; dead-letter on parse failure.
- **The synthetic simulator implements the identical `EventSource` interface** (Synthea-seeded + Poisson/diurnal/LOS/surge timing) → the live UI cannot distinguish sim from real. This is how we demo "live" with no EHR.
- Security: TLS everywhere; pseudonymous `patient_ref` (not MRN); PHI minimized at the adapter; Safe-Harbor de-id gate for any real-data demo.

---

## 7. Hummingbird: the frontline companion (`08`)

- **Ride the existing Reverb event bus** (one broadcast feeds web + mobile) with **mandatory snapshot-on-reconnect** — Reverb/Pusher never replay missed messages and the OS suspends background WebSockets, so `invalidateQueries` on every `AppState→active` + Echo `connected` is the reliability backbone.
- **Three role-based home screens first:** charge nurse, bed manager/flow coordinator, EVS/transport — the densest real-time, low-data-entry, queue-and-acknowledge interactions.
- **Push delivery + closed-loop acknowledgment is a safety-grade feature with its own SLO** — the single failure mode that sinks every incumbent app's adoption. Three alert tiers; reserve iOS Critical Alerts strictly for life-critical events (alarm-fatigue / NPSG.06.01.01).
- **Share `packages/core` (Zod schemas + API client + TanStack hooks) across web and mobile; never share UI/routing.** Single validation truth = the literal mechanism of "in lockstep." pnpm/Turborepo monorepo.
- **Offline** = read census/board/tasks + queue acknowledge/mark-clean via SQLite outbox; shared-board live edits are online-only.
- HIPAA posture: PHI-free push payloads + fetch-on-open, AES-256 local store, biometric-gated short-lived tokens, 10–15 min auto-logoff, `FLAG_SECURE` + app-switcher blur. No "HIPAA certification" exists for Expo — only architecture + BAAs.
- Design for gloved, one-handed, glare conditions: ≥48 px targets in the thumb zone, redundant status encoding, haptics, a Live Activity for live census/surge.

---

## 8. Cross-cutting design principles (the rules that bind everything)

1. **The RTDC triple is the spine.** Predicted discharges, predicted demand, signed bed-need — per unit, per day — is the single source of truth every layer reads/writes.
2. **Safety is the feasible region, not a report.** No recommendation exists outside the safe set.
3. **Acuity, never raw census.** Every capacity number is acuity- and ADT-adjusted.
4. **Advice, not autopilot.** Humans decide; the system explains, ranks, and learns from overrides.
5. **One canonical event model.** Source formats die at the adapter; the domain is vendor-agnostic.
6. **Same seam for sim and real.** The simulator is a first-class `EventSource`, so demo == production path.
7. **Web and mobile share validated contracts, not UI.** `packages/core` is the lockstep mechanism.
8. **Measure with controls.** SPC charts + balancing measures built in; evidence-first is a differentiator, not a slogan.

---

## 9. Open assumptions to validate (defaults chosen; flag if wrong)

- **Target site profile:** a mid-size acute-care hospital (~250–500 beds) with ED, inpatient med/surg + ICU, and perioperative — the existing Zephyrus modules. Community/critical-access affordability is a strategic target, not the first design point.
- **No live EHR feed at first.** We build to the adapter seam and demo on the synthetic simulator; first real integration is a later, site-specific milestone.
- **Regulatory posture:** operational, HCP-reviewable, explainable forecasts only → non-device. We do **not** ship patient-level clinical alerting (stays in the EHR).
- **Stack continuity:** Laravel 11 + Inertia/React (web) stays; new Python optimization + ingestion sidecars; Expo/RN mobile; Postgres + Redis + Reverb. Frontend is the asset to preserve.

---

*Pillar files (evidence + sources): `01-patient-flow-capacity-science.md`, `02-nursing-operations-acuity.md`, `03-predictive-ml.md`, `04-prescriptive-optimization.md`, `05-interop-realtime-architecture.md`, `06-competitive-landscape.md`, `07-quality-safety-integration.md`, `08-mobile-hummingbird.md`.*
