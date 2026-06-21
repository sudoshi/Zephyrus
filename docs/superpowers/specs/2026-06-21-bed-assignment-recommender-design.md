# Design Spec — S4: Prescriptive Bed-Assignment Recommender

**Date:** 2026-06-21
**Subsystem:** S4 (prescriptive layer) — see `.planning/PROGRAM-ROADMAP.md`
**Grounded in:** `.planning/research/04-prescriptive-optimization.md` (bed-assignment MVP, weighted bipartite + side constraints, advice-not-autopilot, explainability) and `.planning/research/07-quality-safety-integration.md` (safety as a first-class constraint, inline safety verdicts).
**Builds on:** S2 (live census: `units`, `beds`, `encounters`, `AcuityService`, the `auth`/`web` API group, Reverb).
**Status:** Proposed. Awaiting founder review before `writing-plans`.

---

## 1. Goal

When a patient needs an inpatient bed (an admit from the ED, an inter-unit transfer, or a direct/OR admit), recommend the **best available bed(s)** as an **explainable, ranked list** computed inside a **hard safety/clinical feasible region** — never an automated assignment. This is the differentiator the research identifies: explainable prescriptive recommendations, "advice not autopilot," that elevate Zephyrus from live dashboards to decision support.

## 2. The core principle (non-negotiable)

**Safety is the feasible region the recommender searches, not a downstream report** (research §07). A bed that would breach a hard constraint — isolation incompatibility, gender policy, capability mismatch, **or nurse-safety-to-accept (acuity-adjusted capacity exhausted / ratio floor breached)** — is *pruned before ranking* and can never appear as a recommendation. Every recommendation renders with inline **safety chips**. Capacity pressure may raise the *priority* of placing a patient; it may never relax a hard safety constraint.

## 3. Scope

### In scope
1. **`BedRequest`** — a pending placement: `patient_ref` (pseudonymous), source (ed/transfer/direct/or), `sex`, `service`, `acuity_tier` (1–4), `isolation_required` (none/contact/droplet/airborne), `required_unit_type` (med_surg/icu/step_down/any), status (pending/placed/cancelled).
2. **`BedAssignmentOptimizer` interface** + a **`HeuristicBedAssignmentOptimizer`** (transparent weighted scoring). This is the **seam**: a later `CpSatBedAssignmentOptimizer` (Python/OR-Tools service) drops in behind the same interface without touching callers.
3. **Hard constraints (prune):** isolation compatibility, gender/sex policy on the bed's room, unit-type/capability match, and **safety** — target unit's `AcuityService::adjustedCapacity > 0` and ratio floor not breached by accepting this acuity.
4. **Soft scoring (penalty/bonus, weighted, transparent):** service cohorting bonus, locality/distance, transfer-avoidance, **flexibility** (penalize fragmenting scarce bed types / isolation-capable beds), acuity balance across the unit.
5. **Explainable output:** for each ranked bed — total score, a per-term breakdown (which hard constraints passed, each soft term's signed contribution), the **runner-up delta**, and human-readable "why". Plus **safety chips** (acuity headroom, isolation match, ratio status).
6. **Decision capture:** persist accept / edit / reject + reason → `bed_placement_decisions` (audit + future weight-tuning). On accept, the placement creates the standard `EncounterStarted` canonical event (reusing S2's dispatcher → census updates live).
7. **API + focused UI:** request recommendations for a pending `BedRequest`; a "Bed Placement" panel showing the ranked, explained recommendations with safety chips and accept/override actions; live via the existing Reverb bus.

### Explicitly out of scope (deferred, seam left)
- **Python/OR-Tools CP-SAT optimizer** → later swap behind `BedAssignmentOptimizer` (research §04).
- **Nurse-to-patient assignment optimization** → S5.
- **Discharge sequencing / EVS-transport worklist** → S4b (separate spec).
- **Full bundle-compliance / equity dashboards** → S6 (S4 surfaces the acuity/ratio safety chips, not the full safety fabric).
- **ML-predicted demand feeding the optimizer** → S3.

## 4. Domain model (new `prod` tables)

| Table | Purpose | Key fields |
|-------|---------|-----------|
| `bed_requests` | A pending placement needing a bed | `bed_request_id`, `patient_ref`, `source`, `sex`, `service`, `acuity_tier`, `isolation_required`, `required_unit_type`, `status`, timestamps, audit |
| `bed_placement_decisions` | Audit of what was recommended vs chosen | `bed_placement_decision_id`, `bed_request_id`, `recommended_bed_id` (top pick), `chosen_bed_id`, `action` (accepted/edited/rejected), `reason`, `score_snapshot` (jsonb), `decided_by`, timestamps |

Bed attributes reused/extended on `beds` (S2): `isolation_capable` exists; add `sex_designation` (any/male/female/derived) and `service_affinity` (nullable) if needed for gender/cohorting — **decision: derive gender constraint from current room occupants** (simpler, no schema churn) and treat `service_affinity` as a soft cohorting signal computed from current occupants, so **no new columns on `beds`** for S4.

## 5. The optimizer (`HeuristicBedAssignmentOptimizer`)

```
recommend(BedRequest $req, Collection $availableBeds): RankedRecommendations
  1. FEASIBLE = beds that pass ALL hard constraints (prune):
       - isolation: bed isolation-capable iff req.isolation_required != none
       - gender: room has no opposite-sex occupant (derived) OR room empty/private
       - capability: bed.unit.type matches required_unit_type (or 'any')
       - SAFETY: AcuityService.adjustedCapacity(bed.unit_id) > 0
                 AND accepting req.acuity_tier keeps ratio_floor satisfied
  2. For each feasible bed, SCORE = Σ weighted soft terms:
       + cohorting   (same service already on unit)
       − distance    (cross-building / far unit)
       − transfer    (req.source == transfer & moving across units)
       − fragmentation (using a scarce isolation-capable bed for a non-isolation patient)
       + acuity_balance (placing reduces unit acuity variance)
  3. RANK feasible beds by score desc; attach per-term breakdown + runner-up delta.
  4. If FEASIBLE is empty → return an explained "no safe bed" result (never a forced pick).
```

Weights are named constants (tunable; later learned from `bed_placement_decisions` overrides). The whole thing is pure, synchronous PHP — sub-millisecond for realistic pools. The interface signature is stable so the CP-SAT service can replace the body.

## 6. Explainability & safety presentation

- Each recommendation card: **score** + **chips** — `Isolation ✓`, `Acuity headroom: N`, `Ratio OK`, and soft-term contributions as labeled +/− pills.
- The **runner-up** is always shown with its delta, so the human sees the trade-off they're overriding.
- **Hard-violation beds are not shown** (pruned); if the human wants to see why a specific bed was excluded, an "excluded beds" expander lists each with the failing hard constraint (red).
- **No green-light without clear safety chips.** Copy reinforces "Recommendation for placement decision — not an automated assignment."

## 7. API & real-time

- `POST /api/rtdc/bed-requests` — create a pending request (validated FormRequest).
- `GET /api/rtdc/bed-requests/{id}/recommendations` — ranked, explained recommendations.
- `POST /api/rtdc/bed-requests/{id}/decision` — accept/edit/reject + reason; on accept, dispatch `EncounterStarted` (S2 dispatcher) and mark request placed; persist `bed_placement_decisions`.
- All under the existing `['web','auth']` `rtdc` group (S2's C1 fix). Recommendations + placements broadcast on the affected `unit.{id}` channel (reuse `CensusUpdated`/a new `BedPlacementUpdated`).

## 8. Frontend (focused panel)

- New `resources/js/Pages/RTDC/BedPlacement.tsx` (TypeScript, TanStack Query + Zod) — a queue of pending `bed_requests` and, for the selected one, the ranked recommendation cards with chips, runner-up, and accept/override actions. Reuses the Acumenus Clinical tokens. Live via Reverb. Zod schemas extend `resources/js/schemas/rtdc.ts`.

## 9. Architecture & isolation

- **Units:** `BedRequest` model | `BedAssignmentOptimizer` interface | `HeuristicBedAssignmentOptimizer` | `BedPlacementService` (orchestrates request→recommend→decide→dispatch) | `BedRequestController` + FormRequests | the React panel. Each independently testable.
- **Seam:** callers depend on the `BedAssignmentOptimizer` interface and a `RankedRecommendations` DTO — never on the heuristic internals — so the Python CP-SAT swap is a binding change, not a rewrite.
- Reuses S2: `AcuityService` (safety), `EventDispatcher` (placement → census), Reverb, the `auth`/`web` API group.

## 10. Error handling, safety, testing

- **Safety tests are first-class:** a property-style test asserting **no recommendation ever violates a hard constraint** (isolation/gender/capability/acuity/ratio) across randomized pools — the core safety guarantee.
- **Explainability tests:** score breakdown sums to total; runner-up delta correct; excluded beds carry a failing-constraint reason.
- **Decision flow:** accept dispatches exactly one `EncounterStarted`, census reflects it, audit row written; reject/edit captured with reason.
- Empty-feasible-set returns an explained "no safe bed" (tested) — never a forced/unsafe pick.
- Pest-style not available (repo constraint) → PHPUnit classes; Vitest + Zod on the frontend; `vite build` gate.

## 11. Acceptance criteria (S4 exit gate)

1. Given a pending `BedRequest` and the live (simulated) census, `GET …/recommendations` returns a ranked list where **every** recommendation passes all hard constraints, each with a score breakdown + runner-up + safety chips.
2. A test proves **no hard-constraint-violating bed is ever recommended** across randomized inputs (the safety guarantee).
3. Accepting a recommendation creates an `EncounterStarted` event → the census updates live (Reverb) and an audit row is written; reject/edit captures a reason.
4. Empty feasible set yields an explained "no safe bed available" result, not a forced pick.
5. The `BedPlacement.tsx` panel shows the queue + ranked explained recommendations with safety chips and accept/override; `vite build` clean; no mock data.
6. The `BedAssignmentOptimizer` interface + `RankedRecommendations` DTO are documented as the swap seam for the future CP-SAT service.

## 12. Open questions for founder

1. **Gender policy:** derive from current room occupants (no schema change) — acceptable, or do you want explicit per-bed sex designation now?
2. **Scarce-bed flexibility term:** treat isolation-capable beds as the scarce resource to protect (penalize using them for non-isolation patients) — agree, or add other scarce types (e.g., telemetry)?
3. **Where the panel lives:** a new "Bed Placement" page under RTDC, or embedded into the existing bed board?
