# Zephyrus Competitor Capability Scorecard

Date: 2026-06-26
Status: Phase 0 deliverable for `docs/superpowers/plans/2026-06-26-competitor-leapfrog-execution-plan.md`
Purpose: Let product, engineering, and sales explain — in one page — why Zephyrus is not "just another command center."

## Positioning Statement

> Zephyrus Flow OS turns fragmented EHR, ADT, bed, transport, perioperative, staffing, facility, payer, ambient, and external-network signals into a live operational graph, simulates safe action plans, routes approved work to accountable owners, and proves impact with source-level lineage and intervention attribution.

The category we claim is **evidence-grade hospital operations execution**: an EHR-neutral operational graph that can explain, simulate, approve, execute, and measure operational action across every source system.

The defensible claim:

> Every signal, recommendation, action, approval, and measured outcome in Zephyrus is traceable, testable, replayable, and accountable.

## Legend

- `●` first-class / shipping
- `◐` partial / adjacent / via partner
- `○` not a focus / not public

## Capability Matrix

Vendors: EP=Epic Grand Central, TT=TeleTracking, QV=Qventus, LT=LeanTaaS, GE=GE Command Center, OR=Oracle Health, AB=ABOUT, CL=Care Logistics, PA=Palantir, AM=Artisight/care.ai, **ZE=Zephyrus**.

| Capability | EP | TT | QV | LT | GE | OR | AB | CL | PA | AM | ZE |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Command center / capacity visibility | ● | ● | ◐ | ◐ | ● | ● | ◐ | ● | ◐ | ◐ | ● |
| Predictive analytics (census/LOS/surge) | ● | ● | ● | ● | ● | ● | ◐ | ● | ● | ○ | ● |
| Prescriptive optimization | ◐ | ◐ | ● | ● | ◐ | ◐ | ◐ | ◐ | ● | ○ | ● |
| Simulation / digital twin | ○ | ◐ | ◐ | ◐ | ● | ○ | ○ | ◐ | ● | ○ | ● |
| Transport workflow | ● | ● | ○ | ○ | ◐ | ● | ◐ | ◐ | ○ | ○ | ● |
| EVS workflow | ● | ● | ○ | ○ | ◐ | ● | ○ | ◐ | ◐ | ◐ | ● |
| Transfer / regional acceptance | ● | ● | ○ | ○ | ◐ | ● | ● | ◐ | ◐ | ○ | ● |
| Staffing operations (constraints + actions) | ◐ | ◐ | ◐ | ● | ● | ● | ◐ | ◐ | ● | ○ | ● |
| Governed AI agents (traces + evals + approvals) | ◐ | ◐ | ● | ◐ | ◐ | ◐ | ○ | ○ | ● | ◐ | ● |
| Source-level metric lineage / trust | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ◐ | ○ | ● |
| Intervention attribution / ROI proof | ○ | ◐ | ● | ● | ◐ | ○ | ○ | ◐ | ◐ | ○ | ● |
| Replay / provenance of signals | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ◐ | ◐ | ● |
| EHR-neutral operational graph | ○ | ◐ | ◐ | ◐ | ◐ | ○ | ◐ | ◐ | ● | ◐ | ● |
| Ambient-signal adapter | ○ | ○ | ○ | ○ | ○ | ◐ | ○ | ○ | ○ | ● | ● |
| Approval-gated action governance | ◐ | ◐ | ◐ | ◐ | ◐ | ◐ | ◐ | ○ | ● | ○ | ● |

The columns where every incumbent is `○`/`◐` and Zephyrus is `●` are the wedge: **source-level metric lineage, replay/provenance, intervention attribution as a first-class workflow, and approval-gated governance applied uniformly across every domain.**

## Per-Vendor Summary

| Vendor | Core strength to respect | Zephyrus wedge |
|---|---|---|
| Epic Grand Central | EHR-native flow, transport, EVS, transfer; owns source of truth | Treat Epic as a source, not the boundary; win multi-EHR, cross-facility, staffing, payer, ambient, governance |
| TeleTracking Operations IQ | Deep flow credibility, large install base, Palantir AI partnership | Same breadth but with visible source contracts, graph topology, action traces, replay, attribution |
| Qventus | Strong AI-teammate ROI narrative, EHR-embedded | Require every teammate to expose tools, inputs, constraints, approvals, traces, evals, measured outcomes |
| LeanTaaS iQueue | Predictive/prescriptive capacity + perioperative ROI | Move past prediction into simulation-backed execution with alternatives, constraints, and actual outcomes |
| GE Command Center | Mature command center, public digital-twin + AI tiles | Don't claim digital twin alone; make every tile executable, auditable, source-linked, attribution-tied |
| Oracle Health | Broad ops suite, location awareness, signage | Lighter modular adoption, EHR-neutral fabric, independent action governance even when Oracle is the EHR |
| ABOUT Healthcare | Strong transfer/access-center model | Make transfer acceptance a constrained optimization over live capacity, staffing, OR/PACU, transport, payer, opportunity cost |
| Care Logistics | Operational model + implementation discipline | Productize the operational model: huddle scripts, escalation, KPI packs, owner maps, control charts, attribution |
| Palantir for Hospitals | General-purpose AI/data platform, forward-deployed | Be the healthcare-native, minimum-necessary, operations-specific alternative with safety policy out of the box |
| Artisight / care.ai | Ambient sees reality before the EHR | Stay sensor-agnostic; consume ambient via an adapter contract into the same graph, simulation, and action engine |

## 90-Day Provable Claims (without external PHI feeds)

These are claims Zephyrus can demonstrate today against current seeded/live tables — see `2026-06-26-90day-demo-walkthrough.md`:

1. Every Command Center hero metric answers "where did this come from and can I trust it?" (metric lineage + source freshness).
2. At least 10 graph-backed operational recommendations can be reviewed, approved, assigned, completed, and audited.
3. A user can compare ≥5 capacity scenarios (including EVS, discharge, transport, flex-bed, staffing relief, and combined) and promote one into an approval-gated action plan.
4. "Action taken → effect measured" exists for RTDC, transport, and a perioperative flow, with balancing measures and confidence language.
5. Two-plus governed agents (Capacity Commander, Data Quality, Executive Briefing) run read/draft-only with visible tool traces, golden-case evals, prompt-injection blocking, and PHI minimization.
6. Staffing is a real operational domain — coverage gaps, units at risk, and governed gap-mitigation across float/overtime/agency/on-call.
7. An executive brief is generated with situation, governed plan, measured impact, source lineage, and an explicit confidence statement.

## What We Will Not Overclaim

- We do not claim "digital twin" or "AI" as differentiation — incumbents already say those words.
- We do not claim causal ROI from before/after charts alone; attribution uses intervention windows, comparison options, balancing measures, and confidence language.
- We do not make autonomous clinical decisions — agents are read-only then draft-only then approval-gated.
