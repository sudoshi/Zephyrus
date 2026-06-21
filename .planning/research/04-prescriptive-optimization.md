# Prescriptive Optimization & Operations Research for Hospital Resource Allocation

**Dossier section — Zephyrus prescriptive (optimizer) layer**
Author: Operations-Research / Healthcare Resource Optimization
Scope: how to turn Zephyrus from a real-time demand/capacity *display* into a system that *recommends actions* — bed placement, nurse assignment, OR scheduling, and discharge sequencing — with formulations, solver stack, and trust/explainability design.

---

## 0. Framing: prescriptive ≠ predictive

A demand/capacity platform has three analytic layers: **descriptive** (what is happening — census, occupancy, boarding), **predictive** (what will happen — admission forecast, length-of-stay/LOS estimate, discharge-by date), and **prescriptive** (what should we *do* — assign this patient to this bed, sequence these discharges first). Leading vendors are explicit that operational impact requires the prescriptive layer on top of prediction: LeanTaaS states that successful inpatient-flow management "requires prescriptive and predictive AI," with iQueue providing "real-time insights and recommendations" rather than dashboards alone ([LeanTaaS iQueue Inpatient Flow](https://leantaas.com/products/inpatient-flow/)). Zephyrus's differentiator must be this layer, and it must be built as **advice, not autopilot** (Section 7).

The four prescriptive workloads below are all variants of **constrained combinatorial optimization** — assignment, rostering, sequencing, packing. They share a methods toolbox (Section 5) and a solver/tech stack (Section 6).

---

## 1. Bed Assignment / Patient Placement Optimization

### Problem
Assign each incoming patient (ED admit, OR recovery, direct admit, transfer) to a specific bed/unit so as to maximize **clinical appropriateness** (right service line, telemetry/ICU capability), satisfy **cohorting and isolation** constraints, respect **gender** rules, minimize **transfers** and **walking distance**, and preserve **future flexibility** (don't fragment a unit so the next surge can't be cohorted). This is the operational, rolling-horizon cousin of the academic **Patient Admission Scheduling (PAS)** problem, which assigns patients to beds over a planning horizon to maximize treatment efficiency, patient comfort, and utilization while honoring medical constraints ([PAS via constraint aggregation, EJOR 2024](https://www.sciencedirect.com/science/article/pii/S0377221724001012)).

### Formulation (assignment / MILP)
Core is a **0–1 assignment** model. Decision variable `x[p,b,t] = 1` if patient *p* occupies bed *b* in period *t*.

- **Hard constraints (HC):** one patient per bed per period; isolation/quarantine patients in single rooms or cohorted with same-organism patients; room gender policy; department must offer the required specialism. In the PAS literature isolation (quarantine) and gender schema are modeled as hard constraints ([Harmony Search for PAS](https://www.degruyterbrill.com/document/doi/10.1515/jisys-2018-0094/html)).
- **Soft constraints (SC), penalized in the objective:** preferred room properties (SC2), department/room specialism match (SC3/SC4), age fit (SC5), **minimize inter-room transfers** (SC6), patient room preference (SC7) — the canonical PAS soft-constraint set ([PAS soft constraints, Liu/Wang/Hao 2024 PDF](https://leria-info.univ-angers.fr/~jinkao.hao/papers/LiuWangHaoEJOR2024.pdf)).
- **Objective:** minimize weighted sum of soft-constraint penalties + travel/distance + a *flexibility* penalty (e.g., reward keeping contiguous open beds in a unit). A real-time MILP variant minimizes total patient travel distance subject to capacity ([data-driven inpatient bed assignment, arXiv](https://arxiv.org/pdf/2111.08269)).

### Practical realities
Exact MILP (e.g., Gurobi) is tractable for a single admission decision or a short horizon, but PAS at scale is NP-hard, so the literature leans on **MIP-based heuristics / matheuristics** ([MIP heuristics for PAS, COR](https://www.sciencedirect.com/science/article/abs/pii/S0305054816302805)) and **ML+optimization hybrids** that predict LOS to feed the optimizer ([ML + optimization for patient-bed assignment](https://www.researchgate.net/publication/367831309)). Uncertain LOS is handled with stochastic/robust models and matheuristics ([PAS with uncertain LOS, ITOR 2024](https://onlinelibrary.wiley.com/doi/10.1111/itor.13272)).

### Design implications for Zephyrus
- **Build this first as the MVP optimizer** (recommended; see Section 9). At any moment there is a small queue of patients-to-place and a set of eligible beds — a *bounded* assignment instance that solves in well under a second.
- Model it as a **weighted bipartite assignment with side constraints**: hard = isolation/gender/specialism/capability; soft = distance, transfer avoidance, cohorting, flexibility, preserving "swing" capacity.
- Output a **ranked top-3 bed suggestions per patient with the score breakdown** ("Bed 4B: +cohort match, +telemetry, −2 transfers; Bed 7A available but breaks gender on the bay"). Never auto-place.

---

## 2. Nurse Scheduling & Real-Time Assignment Optimization

Two distinct problems share machinery:

### (a) Nurse Rostering Problem (NRP) — weeks ahead
Assign nurses with varying skills to shifts over a horizon to meet demand while honoring legal hours, qualifications, and preferences. NRP is a classic combinatorial optimization problem ([Optimizing Nurse Rostering with IP, Healthcare/MDPI 2024](https://www.mdpi.com/2227-9032/12/24/2545)). A common, robust pattern is **two-phase**: first decide workload distribution per nurse/day, then assign specific shifts via integer programming ([same MDPI study](https://pmc.ncbi.nlm.nih.gov/articles/PMC11675476/)). This is the OptaPlanner/Timefold "employee rostering" sweet spot.

### (b) Real-time Nurse-to-Patient Assignment (NPA) — this shift
Distribute the current patient census across on-duty nurses to **balance acuity/workload**, preserve **continuity** (same nurse keeps the patient), enforce **ratios and skills**, and keep it **fair**. NPA models drive workload from patient-acuity classification scores, nurse-specific workload scores, or both ([Balancing Nursing Workload by CP, Pesant et al.](https://share.polymtl.ca/alfresco/service/api/path/content;cm:content/workspace/SpacesStore/Company%20Home/Sites/labo-qosseca-web/documentLibrary/Publications/npa.pdf)). On NICU NPA, **constraint programming outperformed competing optimization methods** ([CP for nursing workload](https://www.researchgate.net/publication/302973410)). Recent OR work even **integrates patient-to-room and nurse-to-patient assignment** jointly ([integrated PRA+NPA, OR Spectrum 2024](https://link.springer.com/article/10.1007/s00291-024-00800-z)) — directly relevant to a unified Zephyrus optimizer.

### Formulation (NPA)
`x[n,p] = 1` if nurse *n* cares for patient *p*.
- **Hard:** ratio caps (e.g., ≤4 med-surg / ≤2 ICU per nurse); required skills/competencies (chemo, vent, charge); break coverage.
- **Objective:** minimize **variance/range of per-nurse workload** (acuity-weighted) → an equity/min-max term; subtract a **continuity reward** for keeping prior assignments; add **geographic clustering** so a nurse's patients are physically near each other. Minimizing workload variance via mixed-integer-quadratic or CP is established ([CP workload balancing](https://www.researchgate.net/publication/302973410)).

### Design implications for Zephyrus
- NPA is the **strong alternative MVP** — high daily frequency, intense pain (acuity imbalance burns out nurses), and it's the natural complement to bed placement (a placed patient must be assigned a nurse).
- Use a **min-max / variance objective** for fairness; expose the *workload bar per nurse* so charge nurses see the balancing rationale.
- **Continuity as a soft reward, not a hard rule** — let charge nurses see the cost of breaking it.

---

## 3. OR / Surgical Scheduling Optimization (perioperative module)

Three tiers, longest to shortest horizon:

1. **Block scheduling / Master Surgical Schedule** — allocate recurring OR blocks to services/surgeons. **Open** policies (mixed cases per block) outperform pure **block** policies because they absorb delays and raise utilization ([OR planning & case scheduling review](https://www.researchgate.net/publication/326240467)).
2. **Smoothing** — flatten elective volume across days and **separate elective from urgent/emergent** flow. A redesign that smoothed the elective schedule and split elective/urgent achieved a **99% reduction in postponed/cancelled cases** ([Redesigning the Surgical Schedule](https://www.heraldopenaccess.us/openaccess/redesigning-the-surgical-schedule-to-enhance-productivity-in-the-operating-room)). Smoothing OR discharges is also the lever that de-peaks downstream inpatient demand — directly coupling the perioperative module to bed capacity.
3. **Case sequencing within a day** — order cases to minimize **blocking** between intra-op and post-op (PACU) stages given downstream occupancy; a blocking-minimization model cut blockings by **94%** ([OR scheduling to reduce blocking, ScienceDirect](https://www.sciencedirect.com/science/article/pii/S2351978917302020)). Surgery-duration uncertainty drives stochastic/chance-constrained models and simulation-optimization ([sim-opt for elective+urgent surgery](https://www.sciencedirect.com/science/article/abs/pii/S2211692322000273); [comprehensive OR scheduling review, 2024](https://link.springer.com/article/10.1007/s12351-024-00884-z)). Real-time reactive re-sequencing handles day-of disruptions ([reactive case sequencing, arXiv](https://arxiv.org/pdf/1808.10133)).

### Design implications for Zephyrus
- Don't build a full block-allocation engine first — it's strategic, low-frequency, and politically loaded.
- **Highest-value perioperative prescriptive feature: discharge-smoothing intelligence** that predicts each surgical case's downstream bed/PACU demand and **flags scheduling decisions that will create a bed crunch 24–48h out**, plus **day-of case re-sequencing** suggestions to relieve PACU blocking. This reuses the bed-capacity model rather than standing up a separate OR optimizer.

---

## 4. Discharge Sequencing & Flow Optimization

### Problem
Discharge is the dominant throughput bottleneck: when discharge-eligible patients wait, ED/ICU back up and the hospital gridlocks ([IHI, Achieving Hospital-Wide Patient Flow](https://www.ihi.org/sites/default/files/IHIAchievingHospitalWidePatientFlowWhitePaper.pdf)). A recurring pattern is **afternoon discharge clustering** — ~72% of discharges between 11am–5pm, colliding with ED admits and step-downs ([pediatric throughput study, PMC](https://pmc.ncbi.nlm.nih.gov/articles/PMC11838157/)). The prescriptive question: given today's likely-discharge list, **which discharges/transfers to expedite, and in what order**, to free the right beds *before* the demand wave, and how to **sequence transport + EVS turnaround**.

### Formulation
A **priority-scoring + sequencing** problem more than a pure MILP. Score each candidate discharge by: downstream bed demand it unblocks (does an ED boarder/ICU step-down need *this* bed type?), readiness (orders, meds, ride), and barrier risk. Then sequence EVS/transport as a **scheduling problem with resource (crew) constraints** to minimize "order-written → bed-ready" turnaround — which shared visibility compresses from hours to minutes ([Artisight bed-capacity/throughput](https://artisight.com/bed-capacity-management-in-hospitals-strategies-to-improve-patient-throughput/)). Outflow-barrier analysis is the proven process lens ([reduced time-to-admit via outflow barrier analysis, PMC](https://pmc.ncbi.nlm.nih.gov/articles/PMC11418872/)).

### What vendors do here
Qventus **predicts each patient's discharge date and the barriers**, then **orchestrates resolution** embedded in Epic/Cerner workflows — reducing excess days 20–35% and LOS by up to a full day ([Qventus discharge planning](https://www.qventus.com/solutions/discharge-planning/)); OhioHealth reported **$1.7M saved in months** ([FierceHealthcare on Qventus](https://www.fiercehealthcare.com/health-tech/qventus-ai-discharge-planning-solution-saved-ohio-health-17-mil-matter-months)).

### Design implications for Zephyrus
- Implement as a **transparent priority score + sequenced worklist**, not a hidden MILP. Clinicians trust "expedite Bed 12 first — it unblocks an ED boarder needing telemetry, and the ride is already booked" far more than an opaque ordering.
- Couple discharge priority directly to the **bed-assignment optimizer's unmet demand** (close the loop: placement pressure drives discharge prioritization).

---

## 5. Methods Toolbox (and the optimality/explainability/latency trade-off)

| Method | Best for | Watch-outs |
|---|---|---|
| **MILP / integer programming** (branch-and-cut) | Small/medium *exact* instances with linear structure + continuous vars (cost, overtime hours) | NP-hard at scale; PAS needs heuristics ([MIP heuristics for PAS](https://www.sciencedirect.com/science/article/abs/pii/S0305054816302805)) |
| **Constraint Programming / CP-SAT** | Heavily *logical/combinatorial* constraints, scheduling, no continuous vars | CP-SAT has no true continuous variables; use MIP for those ([CP-SAT Primer](https://d-krupke.github.io/cpsat-primer/)). CP beat MIP on NICU NPA and on parallel-machine/GPU scheduling |
| **Metaheuristics** (genetic, simulated annealing, tabu, late-acceptance hill-climbing) | Large rostering/assignment where "very good, fast" beats "optimal, slow" | No optimality proof; this is OptaPlanner/Timefold's engine room |
| **Constraint Programming (CP solvers)** | Quarantine/gender/specialism logic, redundant modeling | Modeling effort |
| **Discrete-event simulation** | *What-if* (Monte-Carlo over LOS/surgery duration), not real-time advice | Slow; pair with optimization (sim-opt) for OR/surgery ([sim-opt OR planning](https://www.sciencedirect.com/science/article/abs/pii/S2211692322000273)) |
| **Simple transparent heuristics / scoring** | Discharge priority, bed top-3, anything clinicians must trust on day one | Sub-optimal — but *adopted* |

**The governing trade-off:** optimality ↔ explainability ↔ latency. In a hospital, an *explainable, fast, slightly-suboptimal* recommendation is adopted; a *provably-optimal, slow, opaque* one is ignored. **CP-SAT vs MIP is problem-dependent** — Gurobi edged CP-SAT on some periodic scheduling, but CP-SAT solved more parallel-machine and GPU-scheduling instances to optimality in less time ([CP vs MIP differences, OR-Tools discuss](https://groups.google.com/g/or-tools-discuss/c/NoGdc_MiXT0); [Hexaly RCPSP benchmark](https://www.hexaly.com/benchmarks/hexaly-vs-or-tools-on-the-resource-constrained-project-scheduling-problem-rcpsp)). For Zephyrus's bounded, logic-heavy assignment problems, **CP-SAT (OR-Tools) and Timefold's metaheuristics are the right defaults; reserve MIP for models with real continuous variables** (e.g., overtime-hour cost minimization).

---

## 6. Solver / Tech Stack

**Open source (recommended core):**
- **Google OR-Tools / CP-SAT** — state-of-the-art free CP-SAT solver, strong on industrial scheduling, Python-native, no license ([CP-SAT Primer](https://d-krupke.github.io/cpsat-primer/)). Ideal for bed assignment and NPA.
- **Timefold Solver** (the OptaPlanner fork by its original Red Hat team) — purpose-built for employee rostering, task assignment, bed scheduling; **Constraint Streams** API gives incremental scoring and, crucially, **explainable score via `ScoreAnalysis` (JSON-friendly, justifications + indictments)** that serializes cleanly backend→frontend ([Timefold score explanation](https://timefold.ai/blog/timefold-solver-1-4-brings-explainable-score); [Timefold ScoreAnalysis docs](https://docs.timefold.ai/timefold-solver/latest/constraints-and-score/understanding-the-score)). Has a **Python package** (`timefold-solver`), though Python is slower than the JVM core ([timefold-solver on PyPI](https://pypi.org/project/timefold-solver/)).
- **Pyomo + CBC/HiGHS** — open MIP modeling + solvers for the continuous-variable models (cost/overtime).

**Commercial (optional, for hard exact instances):** **Gurobi** / **CPLEX** — fastest MIP; many bed-assignment papers use Gurobi ([P-model bed assignment](https://arxiv.org/pdf/2111.08269)). Add only if a specific model proves intractable on open solvers. (Note the user's stack: Python FastAPI is already the AI-service convention.)

**Architecture — optimization as a service:** Run the optimizer as a **stateless Python FastAPI microservice** ("optimizer service") separate from the Laravel app and the real-time data layer. It receives a problem snapshot (eligible patients, beds, nurses, constraints, weights) as JSON, solves under a wall-clock budget, and returns **ranked recommendations + a JSON score breakdown**. Timefold's JSON-friendly `ScoreAnalysis` and OR-Tools' solution callbacks both fit this. Key rule: **never call remote services inside score calculation** — cache inputs in the snapshot ([Timefold performance guidance](https://docs.timefold.ai/timefold-solver/latest/constraints-and-score/performance)). Enforce a strict solve-time budget (e.g., 500ms–2s for real-time placement; minutes for overnight rostering).

---

## 7. Human-in-the-Loop & Trust ("advice, not autopilot")

Black-box optimization fails in hospitals for the same reason black-box AI does: when a system recommends but can't explain *why*, clinicians must either trust an opaque output or override on experience — and they override ([Black Box problem in healthcare AI](https://medium.com/@healthai_official/the-black-box-problem-in-healthcare-ai-building-trust-and-transparency-4b8004b4a08b)). Human-in-the-loop design lets clinicians **override or fine-tune** and validate before acting; the clinician bears ultimate responsibility ([HITL AI in healthcare, ScienceDirect](https://www.sciencedirect.com/science/article/pii/S1386505626001024)). And explanations are not magic: clinician trust/reliance varies, and explanations sometimes *don't* move trust and can even hurt — so design for **interrogability** (let users probe the recommendation), not just a static rationale ([clinician variability in trust/reliance, npj Digital Medicine](https://www.nature.com/articles/s41746-025-02023-0)).

Even the vendor pushing furthest toward automation frames it as assistive: LeanTaaS's "iQueue Autopilot" is generative-AI *for operations decisions*, layered on a recommendation product, not unattended control ([iQueue Autopilot announcement](https://leantaas.com/press-releases/leantaas-announces-iqueue-autopilot-first-ever-generative-ai-hospital-operations-solution/)). The iQueue inpatient product scored **95/100 in KLAS** by delivering *recommendations to frontline staff* ([KLAS 95 score](https://leantaas.com/press-releases/klas-research-reveals-outstanding-95-out-of-100-overall-satisfaction-score-for-the-leantaas-iqueue-for-inpatient-flow-solution/)).

**Zephyrus trust contract:**
1. **Recommend, the human commits.** Every optimizer output is a suggestion accepted/edited/rejected by a charge nurse or bed manager.
2. **Always show the "why."** Per-recommendation score breakdown (which constraints were satisfied/violated and by how much) — Timefold `ScoreAnalysis` gives this for free.
3. **Show the runner-up.** Top-3 with deltas so the human sees the trade-off they're overriding.
4. **Capture the override + reason** → feedback loop to retune constraint weights (and an audit trail).
5. **Surface hard-constraint violations as blockers, soft ones as advisories.**

---

## 8. What Leading Products Actually Do

- **LeanTaaS iQueue (+ acquired Hospital IQ)** — predictive *and prescriptive* AI; continuously monitors operational health and pushes **real-time insights and recommendations** to leaders and frontline staff; covers inpatient flow, OR, infusion. The Hospital IQ acquisition created a >$1B entity spanning **180+ health systems** ([LeanTaaS acquires Hospital IQ](https://www.healthcareittoday.com/2023/01/13/leantaas-acquires-hospital-iq-to-create-ai-innovator-for-hospital-operations-optimization/); [iQueue Inpatient Flow](https://leantaas.com/products/inpatient-flow/)). Their thesis: lean + AI integration prevents capacity constraints from *multiplying* demand ([lean-AI integration, ScienceDirect](https://www.sciencedirect.com/science/article/pii/S3050837126000019)).
- **Qventus** — **EHR-embedded** (Epic/Cerner) discharge-planning automation: ML predicts discharge date + barriers, then **orchestrates resolution** and accountability; 20–35% excess-day reduction, up to 1 day LOS, **$1.7M saved** at OhioHealth; 3rd-gen solution automates care planning inside the EHR ([Qventus discharge planning](https://www.qventus.com/solutions/discharge-planning/); [Qventus 3rd-gen launch](https://www.qventus.com/company/newsroom/qventus-launches-its-3rd-generation-inpatient-solution-to-optimize-hospital-discharge-planning-and-automate-care-planning-fully-embedded-into-ehr-workflows/)).
- **Hospital IQ** — intelligent-automation for hospital ops (now under LeanTaaS).

**Common pattern:** *recommendation engines, not full automation*. They predict (LOS, discharge date, surge), prescribe (who/what/when to act), embed in existing workflow (EHR/dashboard), and keep humans in the decision seat. Zephyrus should match this posture and compete on **transparency + real-time responsiveness + open standards (OMOP/FHIR)**, not on opaque automation.

---

## 9. Concrete Design Implications for Zephyrus

### Build first: **Bed-Assignment Optimizer** (MVP)
Recommended over nurse-assignment as #1 because (a) it's the most bounded problem (small live queue × eligible beds → sub-second solve), (b) it sits at the center of the throughput crisis and naturally drives discharge prioritization, and (c) it produces an obviously useful artifact (ranked bed suggestions) that wins clinician trust fast. **Nurse-to-Patient Assignment is the immediate fast-follow** (it consumes the placement output and is the highest daily-frequency pain), and the two can later be solved **jointly** ([integrated patient-room + nurse-patient, OR Spectrum 2024](https://link.springer.com/article/10.1007/s00291-024-00800-z)).

### MVP formulation (bed assignment)
- **Variables:** `x[p,b] ∈ {0,1}` for each queued patient *p* and eligible bed *b*.
- **Hard constraints:** ≤1 patient/bed; isolation/quarantine → eligible (single/cohort-compatible) rooms only; gender policy per bay; unit must provide required specialism + capability (ICU/telemetry).
- **Soft constraints (weighted penalties):** specialism/room-property fit, minimize transfers, cohorting bonus, walking distance, **flexibility** (penalize fragmenting open beds), age fit, patient preference.
- **Objective:** `min Σ (weighted soft-constraint penalties)`; weights tunable via the override feedback loop.

### Recommended tech
- **Optimizer service:** Python **FastAPI** microservice (matches existing AI-service convention), stateless, JSON in/out, strict solve-time budget.
- **Solver:** **OR-Tools CP-SAT** for bed assignment + NPA (free, fast, logic-heavy fit). Adopt **Timefold** when modeling the nurse *roster* (multi-week employee rostering — its native domain) and to inherit **explainable `ScoreAnalysis`**. Keep **Pyomo + HiGHS** for any continuous-cost model. Defer Gurobi unless a model proves intractable.
- **No remote calls inside scoring;** snapshot all inputs.

### Explainable recommendation UI
- Per patient, show **top-3 beds** with a **score-breakdown chip set** (e.g., `+cohort ✓ | +telemetry ✓ | −2 transfers | gender ✓`).
- **Hard violations = red blockers; soft = amber advisories.** Show the **delta to the runner-up** so the human sees the trade-off of overriding.
- **One-click accept / edit / reject + reason capture** → retune weights, write an audit trail.
- Frame everything as **advice**: the bed manager always commits the placement.

---

## Sources

1. LeanTaaS — iQueue for Inpatient Flow. https://leantaas.com/products/inpatient-flow/
2. LeanTaaS acquires Hospital IQ (Healthcare IT Today). https://www.healthcareittoday.com/2023/01/13/leantaas-acquires-hospital-iq-to-create-ai-innovator-for-hospital-operations-optimization/
3. LeanTaaS — iQueue Autopilot (generative AI for hospital ops). https://leantaas.com/press-releases/leantaas-announces-iqueue-autopilot-first-ever-generative-ai-hospital-operations-solution/
4. LeanTaaS — KLAS 95/100 satisfaction for iQueue Inpatient Flow. https://leantaas.com/press-releases/klas-research-reveals-outstanding-95-out-of-100-overall-satisfaction-score-for-the-leantaas-iqueue-for-inpatient-flow-solution/
5. Lean-AI integration prevents capacity constraints multiplying demand (ScienceDirect). https://www.sciencedirect.com/science/article/pii/S3050837126000019
6. Qventus — Inpatient/Discharge Planning solution. https://www.qventus.com/solutions/discharge-planning/
7. Qventus — 3rd-gen inpatient solution, EHR-embedded. https://www.qventus.com/company/newsroom/qventus-launches-its-3rd-generation-inpatient-solution-to-optimize-hospital-discharge-planning-and-automate-care-planning-fully-embedded-into-ehr-workflows/
8. Qventus saved OhioHealth $1.7M (FierceHealthcare). https://www.fiercehealthcare.com/health-tech/qventus-ai-discharge-planning-solution-saved-ohio-health-17-mil-matter-months
9. Patient Admission Scheduling via constraint aggregation (EJOR 2024). https://www.sciencedirect.com/science/article/pii/S0377221724001012
10. PAS soft/hard constraint set (Liu, Wang, Hao 2024, PDF). https://leria-info.univ-angers.fr/~jinkao.hao/papers/LiuWangHaoEJOR2024.pdf
11. Harmony Search Algorithm for PAS (isolation/gender as hard constraints). https://www.degruyterbrill.com/document/doi/10.1515/jisys-2018-0094/html
12. MIP-based heuristics for PAS (Computers & OR). https://www.sciencedirect.com/science/article/abs/pii/S0305054816302805
13. PAS with uncertain LOS — matheuristic (ITOR 2024). https://onlinelibrary.wiley.com/doi/10.1111/itor.13272
14. Combining ML and optimization for patient-bed assignment. https://www.researchgate.net/publication/367831309
15. Data-driven inpatient bed assignment (P-model, arXiv). https://arxiv.org/pdf/2111.08269
16. Optimizing Nurse Rostering with Integer Programming (Healthcare/MDPI 2024). https://www.mdpi.com/2227-9032/12/24/2545 (PMC: https://pmc.ncbi.nlm.nih.gov/articles/PMC11675476/)
17. Balancing Nursing Workload by Constraint Programming (Pesant et al., PDF). https://share.polymtl.ca/alfresco/service/api/path/content;cm:content/workspace/SpacesStore/Company%20Home/Sites/labo-qosseca-web/documentLibrary/Publications/npa.pdf
18. CP for nursing workload balancing (ResearchGate). https://www.researchgate.net/publication/302973410
19. Integrated patient-to-room and nurse-to-patient assignment (OR Spectrum 2024). https://link.springer.com/article/10.1007/s00291-024-00800-z
20. Redesigning the Surgical Schedule (smoothing, 99% fewer cancellations). https://www.heraldopenaccess.us/openaccess/redesigning-the-surgical-schedule-to-enhance-productivity-in-the-operating-room
21. OR scheduling to reduce blocking across perioperative process (94% blocking reduction). https://www.sciencedirect.com/science/article/pii/S2351978917302020
22. OR planning & surgical case scheduling: literature review (open vs block). https://www.researchgate.net/publication/326240467
23. Comprehensive review on OR scheduling and optimization (Springer 2024). https://link.springer.com/article/10.1007/s12351-024-00884-z
24. Simulation-optimization for elective + urgent surgery planning. https://www.sciencedirect.com/science/article/abs/pii/S2211692322000273
25. Real-time reactive surgical case-sequencing framework (arXiv). https://arxiv.org/pdf/1808.10133
26. IHI — Achieving Hospital-Wide Patient Flow (white paper). https://www.ihi.org/sites/default/files/IHIAchievingHospitalWidePatientFlowWhitePaper.pdf
27. Multidisciplinary discharge facilitation, afternoon clustering (PMC). https://pmc.ncbi.nlm.nih.gov/articles/PMC11838157/
28. Reduced time-to-admit via outflow barrier analysis (PMC). https://pmc.ncbi.nlm.nih.gov/articles/PMC11418872/
29. Bed capacity management / throughput strategies (Artisight). https://artisight.com/bed-capacity-management-in-hospitals-strategies-to-improve-patient-throughput/
30. The CP-SAT Primer — OR-Tools CP-SAT. https://d-krupke.github.io/cpsat-primer/
31. MIP vs CP solver differences (OR-Tools discuss). https://groups.google.com/g/or-tools-discuss/c/NoGdc_MiXT0
32. Hexaly vs OR-Tools on RCPSP (solver benchmark). https://www.hexaly.com/benchmarks/hexaly-vs-or-tools-on-the-resource-constrained-project-scheduling-problem-rcpsp
33. Timefold — explainable score (ScoreAnalysis, justifications/indictments). https://timefold.ai/blog/timefold-solver-1-4-brings-explainable-score
34. Timefold — Understanding the score / ScoreAnalysis docs. https://docs.timefold.ai/timefold-solver/latest/constraints-and-score/understanding-the-score
35. Timefold — performance (no remote calls in scoring). https://docs.timefold.ai/timefold-solver/latest/constraints-and-score/performance
36. timefold-solver — Python package (PyPI). https://pypi.org/project/timefold-solver/
37. Timefold vs OptaPlanner (fork by original team). https://goo.by/blog/timefold-vs-optaplanner
38. Black Box problem in healthcare AI (Medium). https://medium.com/@healthai_official/the-black-box-problem-in-healthcare-ai-building-trust-and-transparency-4b8004b4a08b
39. Human-in-the-loop AI in healthcare (ScienceDirect). https://www.sciencedirect.com/science/article/pii/S1386505626001024
40. Clinician variability in trust, reliance, performance (npj Digital Medicine). https://www.nature.com/articles/s41746-025-02023-0
