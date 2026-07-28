# FLOW-4D Phase D3 — Decision Memo: CPG-on-FHIR vs bespoke reference spec

**Date:** 2026-07-27  **Author:** [C]  **Status:** Decided (memo, not a dependency)
**Resolves:** [FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN §6.5, Open Question OQ-5](./FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN-2026-07-26.md)

## The question

To bridge the governed DRG care-pathway catalog to conformance, should we compile
a pathway version into:

- **(A)** a **bespoke reference spec** — the shape `arena/app/pathways.py::PATHWAYS`
  already uses (what Phase D1's `ReferenceModelCompiler` emits), or
- **(B)** **CPG-on-FHIR** `PlanDefinition` + **CQL** as the canonical, standards-based
  intermediate (per the global standards-first rule: FHIR R4 / CPG-on-FHIR)?

This was time-boxed to a spike. The outcome is this memo; no code depends on it.

## What the D1/D4 build established

1. **The operative part of a sidecar reference model is a Python callable, not
   data.** Each enabled safety pathway (`evaluate_sepsis`, `evaluate_surgical_safety`,
   `evaluate_home_hospital`) encodes imperative clinical timing — the SEP-3 3-hour
   antibiotic window, the AHCAH two-visits-per-full-day floor, the 30-minute
   escalation response. That logic is **not serializable and not derivable from the
   catalog**. What *is* data — ordered activities, timing targets, deviation labels —
   is exactly what D1's compiler emits. The compiler deliberately stops there
   (`test_never_emits_an_evaluate_callable`).

2. **The governed catalog stores declarative truth, not executable logic.**
   `milestone_definitions.sequence` + `expected_range` (day offsets) + evidence claims
   are a *description* of the pathway. There is no executable rule in the schema, and
   the executable layer is not even persisted today (`milestone_definitions` is empty;
   drafts are derived on demand from source prose).

3. **So the real gap is turning a declarative milestone into an executable
   conformance rule** — "was this milestone met, and was it late?" — over the OCEL
   event stream. Options A and B differ only in *how that rule is expressed and run*.

## Options

| | (A) Bespoke spec | (B) CPG-on-FHIR PlanDefinition + CQL |
|---|---|---|
| Representation | `PATHWAYS`-shaped dict (D1 output) | `PlanDefinition.action[*]` (+ `.timing`), `Library`/CQL expressions, `DetectedIssue` semantics for deviations |
| Executable rule | Hand-authored Python evaluator per pathway | CQL expression evaluated by a CQL engine over a FHIR data layer |
| Standards fit | None (internal shape) | Strong — the HL7 CPG-on-FHIR IG is the canonical way to represent a computable pathway |
| Dependencies | None beyond the running pm4py sidecar | A CQL execution engine (`cql-execution` JS, `cqframework` Java, or a Python CQL lib) **not in the stack**, plus an OMOP/OCEL → FHIR projection CQL can query |
| Scale to 250 DRGs | Poor — per-pathway human authoring | Good — data-driven once the engine + mapping exist |
| Time-to-serve today | Immediate (matches the live sidecar) | Weeks (new engine + FHIR data layer + validation) |

## Decision

**Adopt CPG-on-FHIR `PlanDefinition` as the canonical *representation*; defer CQL as
the *execution* layer; ship the bespoke declarative compiler now.** Concretely:

1. **Now — ship D1's bespoke declarative compiler.** It already emits the shape the
   running sidecar consumes and is the pragmatic intermediate. Nothing serves in
   Phase D (dark behind `assignment_enabled`, G-9), so there is no executable
   requirement to satisfy yet.

2. **Commit to a `PlanDefinition` projection.** When serving is greenlit, add a
   `PlanDefinition` output to the compiler alongside the bespoke spec — a mechanical
   projection of the declarative fields the compiler already holds: milestone →
   `action`, `expected_range` → `action.timingRange` (or `timingTiming`), predecessor
   keys → `action.relatedAction`, deviation labels → `DetectedIssue.code` vocabulary.
   The catalog's governed schema remains the source of truth; `PlanDefinition` is a
   standards-conformant *view* of it, not a second store.

3. **Defer CQL execution until there is an executable requirement.** Introduce a CQL
   engine only when per-patient conformance *from the DRG catalog* is actually enabled
   ([SU] / clinical sign-off, G-9). At that point evaluate `cql-execution` (JS,
   colocatable with the app) vs a Python CQL lib inside the arena sidecar, and the
   OMOP/OCEL → FHIR (QI-Core) projection CQL would query. Until then the three
   *enabled* safety pathways keep their hand-authored Python evaluators — a bounded,
   reviewed set, not 250.

## Why not adopt CQL now

Standards-first is honored at the **representation** layer (`PlanDefinition`) without
prematurely importing a CQL **runtime**. Doing (B) fully today would mean standing up
a CQL engine and a FHIR data layer to serve *nothing* (Phase D is dark) — cost with no
recipient. The honest sequencing is: represent in the standard, execute in the
standard when there is something to execute.

## Consequences / follow-ups

- D1's compiler is the intermediate of record for now; `PlanDefinition` projection is a
  tracked follow-up gated with serving (not scheduled).
- D2's `care-pathways:ocel-coverage` remains the gate report either way — CQL cannot
  evaluate a milestone whose activity does not map to an OCEL/FHIR event, so the
  milestone↔activity mapping is prerequisite to *both* options.
- No dependency is added by this memo.
