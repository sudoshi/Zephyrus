# Nursing Operations, Workload/Acuity, and Staffing Optimization — Research Dossier (Zephyrus §02)

> **Scope.** Best-practice evidence on nurse staffing models, acuity/workload measurement, assignment optimization, missed care, handoffs/huddles, rostering, nurse experience, and real-time nursing operations — with concrete design implications for Zephyrus, a real-time demand/capacity optimization platform whose dual mandate is operational efficiency *and* safe, evidence-based, equitable patient care.
>
> **North star.** Every feature below must make the *charge nurse and bedside nurse safer and less burdened*, not surveilled or squeezed. Optimization objectives that ignore the staffing-outcomes evidence base are not efficiency — they are risk transfer onto patients and nurses.

---

## 1. Nurse Staffing Models

**The staffing–outcomes evidence base is the single most important fact for this platform.** The landmark Aiken et al. (2002, JAMA) study established that each additional patient added to a hospital nurse's average workload is associated with a **7% increase in the odds of patient death within 30 days** and a 7% increase in failure-to-rescue. Replications have found effects of equal or larger magnitude — recent Pennsylvania work reports ~8% per additional patient, and shift-level studies report up to a 16% increase in 30-day mortality per additional patient in the average workload. This is the causal logic Zephyrus must encode: **workload is not a cost line — it is a clinical risk variable.** [Aiken/NYSNA; Penn study; ScienceDirect shift-level study]

**California Title 22 / AB 394** is the only U.S. enforced numeric-ratio regime. AB 394 (1999), implemented from 2004–2008, sets *minimum, at-all-times, licensed-nurse-to-patient ratios by unit type* — these are floors that may never be breached, including during breaks:
- ICU/critical care & NICU: **1:2** (or 1:1 for unstable patients)
- Step-down: **1:3** (post-2008)
- Med/surg: **1:5**
- Specialty care (e.g., oncology, telemetry): **1:4** (post-2008); telemetry **1:4**
- L&D: **1:2**; postpartum **1:4**; pediatrics **1:4**; ED **1:4** (1:2/1:1 for critical)
- Psych: **1:6**

Critically, Title 22 requires that **additional nurses be added above the minimum based on a documented patient classification system** that accounts for severity of illness, complexity of clinical judgment, and need for specialized technology. The ratio is a floor; **acuity drives the real number.** [NNU ratios law; CHCF; LegalClarity]

**ANA "Safe Staffing" model** rejects rigid national ratios in favor of *unit-level staffing committees* (≥55% direct-care nurses per the proposed federal Nurse Staffing Standards for Hospital Patient Safety and Quality Care Act, H.R.3415/119th Congress) that set *adjustable* minimum ratios reflecting acuity, skill mix, the practice environment, and patient flow. The 2019 ANA *Principles for Nurse Staffing* frame staffing as a dynamic match between patient needs and nurse competencies, not a static grid. [ANA Nurse Staffing; Congress.gov H.R.3415]

**Operational vocabulary Zephyrus must model:**
- **Budgeted vs. actual** — units are funded to a *budgeted HPPD/skill mix*; the daily reality (actual census × actual acuity × actual staff present) diverges constantly. The gap is where short-staffing risk lives.
- **Float pools / resource teams** — internal flexible staff deployed to fill gaps; require competency-to-unit matching.
- **Skill mix** — RN vs. LPN vs. CNA/PCT proportions; RN-rich mixes are independently protective.

> **Design implications for Zephyrus.**
> - Encode **ratio floors as hard constraints** (per-unit, configurable by jurisdiction; California Title 22 as a shipped ruleset). Never propose an assignment or staffing plan that breaches a floor — surface it as a *violation*, not a suggestion.
> - Treat **acuity-adjusted required staff = max(ratio floor, acuity-derived demand)**. The optimizer's target is the *higher* of the two.
> - Surface **budgeted-vs-actual-vs-acuity-required** as a first-class real-time dashboard tile per unit/shift, with variance and a projected mortality/failure-to-rescue *risk signal* (framed as evidence-based context, not a clinical prediction).
> - Model **float-pool competency matrices** so fill suggestions never place a nurse on a unit they're not credentialed for.

---

## 2. Acuity & Patient Classification Systems (PCS)

**Patient acuity** = "the intensity of nursing care a patient requires" — the time and resources needed. PCS group patients into care-intensity categories and sum them to compute total unit demand. The major instruments Zephyrus should understand:

- **NHPPD (Nursing Hours Per Patient Day)** — available nursing hours ÷ patient-days over 24h. A "bottom-up" method classifying each ward into categories by complexity, intervention level, high-dependency beds, emergency/elective mix, and turnover. Validated against patient outcomes; superior to crude average-daily-census because it accounts for acuity, geography, and skill mix. [PMC NHPPD outcomes; ScienceDirect FTE/NHPPD]
- **RAFAELA (Finland)** — the most rigorous research-grade system: **OPCq** instrument (six care areas, scored to nursing-intensity points) + daily nurse-resource registration + **PAONCIL** professional assessment to derive each ward's *optimal nursing intensity per nurse* via linear regression. Daily workload is benchmarked against the optimal zone (−3 to +3, zero = optimal). In a 53-ward Finnish study, optimal intensity averaged ~25 points/nurse and was *exceeded 48% of days* — i.e., overload is the norm, not the exception. RAFAELA's key idea: **there is an empirically derivable optimal load per unit, and you can measure deviation from it daily.** [PubMed RAFAELA; IOS Press]
- **Perroca** — Brazilian PCS with validated construct validity and good inter-rater agreement; scores patients across nursing-care domains to standardize care and prioritize. Linking Perroca to shift assignment raised nurse satisfaction. [PubMed Perroca; Matching Assignment study]
- **GRASP, NAS (Nursing Activities Score), SNCT (Safer Nursing Care Tool)** — other workload/dependency instruments. In ICU comparisons, *direct* instruments (NAS) outperform *indirect* ones (Perroca) for capturing true workload.
- **Predictive acuity from routine EHR data** — emerging models estimate nurse workload from routine hospital data (vitals, orders, devices, ADLs) rather than manual scoring, removing the documentation burden that plagues manual PCS. [JMIR Medinform 2025]

The recurring failure mode: PCS that require *manual nurse charting* add to workload, get gamed or skipped, and decay. The recurring success factor: acuity that **drives the assignment**, not just a retrospective justification number.

> **Design implications for Zephyrus.**
> - Adopt a **pluggable acuity engine**: support manual PCS entry (Perroca/NHPPD-style categories) *and* an **EHR-derived predictive acuity model** (vitals instability, drips/devices, isolation, fall/Braden risk, telemetry, total-care ADLs, frequency of interventions) so units without a deployed PCS still get acuity-aware optimization.
> - Compute a **per-patient acuity score** and a **per-unit total demand** in real time; express unit load as **deviation from optimal (RAFAELA-style ±band)**, not just a raw number — the optimal-zone framing is more actionable for charge nurses.
> - **Minimize charting burden**: prefer passive EHR-derived signals; when manual input is needed, make it one tap at the bedside, not a separate form.
> - Acuity must be **the input that drives assignment and float decisions**, surfaced at the moment of decision — not a number entered to satisfy compliance.

---

## 3. Nurse Assignment Optimization

The charge nurse's twice-or-more-daily task is to map *N patients → M nurses* balancing competing objectives. The science (multi-objective heuristics and ML assignment models) identifies the trade-offs Zephyrus must optimize:

- **Acuity balance** — equalize total acuity across nurses so no one is overloaded.
- **Continuity of care** — keeping a nurse with the same patients across shifts reduces errors, readmissions, and raises satisfaction. Evidence-backed heuristics **prioritize higher-acuity patients for continuity**, while distributing lower-acuity patients to minimize workload imbalance.
- **Geography** — clustering rooms minimizes nurse travel/walking; geographic dispersion silently inflates workload.
- **Competency congruence** — match nurse expertise to patient needs (e.g., new grad vs. complex drip titration).
- **Equity & nurse voice** — assignments are fairest when they reflect both patient need *and* nurse skill/experience, and when **bedside nurses have input** into the process.

**ADT (admission/discharge/transfer) is the dominant hidden workload driver.** Admissions and discharges are the most labor-intensive events in a shift — each admission/discharge can consume 60–90+ minutes of RN time (assessment, orders, education, documentation, coordination) yet is invisible to midnight-census staffing. A unit "at census" with 4 admissions and 3 discharges pending is dramatically more loaded than a static unit at the same census. **Churn, not census, is the workload.**

> **Design implications for Zephyrus.**
> - Ship an **assignment optimizer** with a **weighted multi-objective function**: minimize acuity variance across nurses (fairness), maximize continuity for high-acuity patients, minimize geographic spread, enforce competency matches — all subject to ratio-floor hard constraints.
> - Make objective **weights configurable per unit/charge nurse** and always present the optimizer output as a **draft the charge nurse edits**, never an imposed assignment. Capture overrides as feedback to tune weights.
> - Add an **ADT-pressure term**: count pending/expected admissions, discharges, and transfers as *acuity-equivalent workload* and reserve capacity for incoming churn. An incoming admission should temporarily raise the receiving nurse's effective load in the optimizer.
> - Provide a **fairness ledger**: track cumulative heavy-assignment burden per nurse across shifts so the optimizer can equalize *over time*, not just within one shift.

---

## 4. Missed / Rationed Nursing Care & Nurse-Sensitive Outcomes

**Missed care (a.k.a. implicit rationing / care left undone)** is the causal mechanism linking workload to harm. Kalisch & Williams' **MISSCARE Survey (2009)** operationalized it: nine commonly missed care elements (ambulation, turning, timely feedings, patient teaching, discharge planning, emotional support, hygiene, I&O documentation, **surveillance**) and seven reasons — **chiefly too few staff, poor skill mix, time pressure, and poor teamwork.** Meta-analysis finds **~80% of nurses report missing ≥1 care activity on their last shift.** Missed care correlates directly with medication errors, falls, infections, and pressure injuries. [PMC Kalisch; ScienceDirect MISSCARE prevalence]

**Nurse-sensitive indicators (NSIs) — NDNQI** (National Database of Nursing Quality Indicators, ANA-affiliated, now Press Ganey, >2,000 hospitals) are the outcomes Zephyrus should treat as its safety scoreboard:
- **Outcome indicators:** patient falls (total + falls-with-injury per 1,000 patient-days); **hospital-acquired pressure injuries** (Stage 2+); **CLABSI**; **CAUTI**; restraint prevalence; failure to rescue.
- **Structure indicators:** RN HPPD, total nursing HPPD, RN skill-mix %, RN education/certification.
- **Process indicators:** fall-risk and Braden assessment completion.

NSI performance drives **Magnet designation, CMS HAC Reduction Program penalties (falls-with-injury and HAPIs are HACs), Value-Based Purchasing, and Joint Commission accreditation** — so these are financial as well as clinical signals. [Vizient NSI; Wolters Kluwer NDNQI; Press Ganey]

> **Design implications for Zephyrus.**
> - Treat the **MISSCARE care elements as the "care that must happen" checklist** the platform protects. When a unit/nurse is projected to be overloaded, surface *which surveillance and prevention tasks are at risk* (turning schedules, ambulation, rounding) — converting an abstract "understaffed" alert into specific, defensible care-at-risk items.
> - **Embed nurse-sensitive risk** into acuity: a fall-risk + Braden-high + CAUTI-device patient is *high surveillance acuity*, which should raise their assignment weight.
> - Provide a **missed-care early-warning signal** tying real-time workload/overload to elevated NSI risk, and feed unit-shift staffing context into retrospective NDNQI/HAC analytics so leaders can correlate staffing decisions with outcomes.
> - Use NSI/missed-care as the **safety guardrail on the optimizer**: efficiency gains that raise projected missed-care risk must be flagged, never silently accepted.

---

## 5. Shift Handoffs, Huddles & Interdisciplinary Rounds

Communication failures are a leading root cause of sentinel events; structured handoffs and huddles are the countermeasures, and they are the **moments where flow decisions get made.**

- **SBAR** (Situation, Background, Assessment, Recommendation) — the dominant nurse handoff/escalation framework; improves communication quality, reduces handover errors, strengthens teamwork. [SBAR scoping review; PMC frameworks]
- **I-PASS** (Illness severity, Patient summary, Action list, Situation awareness/contingencies, Synthesis-by-receiver) — stronger evidence base (large multicenter trials show reduced medical errors); preferred where detailed action lists and contingency planning matter. **The certainty of evidence for I-PASS exceeds SBAR.** [I-PASS Institute; AHRQ Making Healthcare Safer IV; PMC structured-handoff systematic review]
- **Safety huddles / charge-nurse huddles** — brief stand-up syncs surfacing safety risks, high-acuity patients, anticipated admissions/discharges, and staffing gaps. They are the natural cadence for *capacity awareness*.
- **Interdisciplinary bedside rounds (IDR/SIBR)** — at-bedside, *include the primary nurse*, address all care + discharge. Structured IDR **reduces length of stay, adverse events, and infections** vs. conference-room rounds, by enabling real-time discharge-readiness assessment. Discharge-focused rounds directly improve patient flow. [Wikipedia IDR; PubMed SIBR LOS; PMC discharge rounds flow]

The flow connection: handoffs/huddles/rounds are where **anticipated discharges and admissions become known** — the exact data a demand/capacity engine needs and the exact moments to inject it.

> **Design implications for Zephyrus.**
> - Auto-generate **SBAR/I-PASS-structured handoff summaries** per patient from EHR + acuity data (illness severity = acuity tier; action list = pending tasks/missed-care risks; contingencies = deterioration risk), pre-filled for the nurse to verify — reducing handoff prep time, a top burnout source.
> - Build a **digital safety-huddle board**: high-acuity patients, pending ADT, staffing gaps, and at-risk care tasks in one view, framed for a 5-minute stand-up.
> - During **IDR/discharge rounds**, surface *anticipated discharge date/discharge-readiness* and feed confirmed discharges straight into the bed-capacity forecast — closing the loop between rounds and real-time flow.

---

## 6. Nurse Scheduling / Rostering (NSP)

The **Nurse Scheduling Problem (NSP / nurse rostering)** is a classic OR/constraint-optimization problem: assign nurses to shifts subject to **hard constraints** (coverage minimums, max hours, mandatory rest, certifications, labor law) and **soft constraints** (preferences, fairness, weekend equity, shift-pattern continuity) whose satisfaction defines solution quality. Solved via ILP, constraint programming, metaheuristics, and increasingly ML/Bayesian/evolutionary methods. [Wikipedia NSP; arXiv Bayesian/squeaky-wheel/scheduling]

Operational practices Zephyrus should support:
- **Self-scheduling** — nurses pick shifts within constraints; raises autonomy/satisfaction and cuts admin overhead. The most retention-positive scheduling practice.
- **Predictive staffing** — forecast demand from historical census, seasonality (flu), day-of-week/hour-of-day admission spikes, and acuity trends to *pre-position* staff and float pools rather than react.
- **Sick-call / short-staffing mitigation** — float-pool management, per-diem pipelines, shift-swap marketplaces, and automated gap alerts; AI scheduling can flag burnout risk and distribute shifts by past workload. [Teambridge; IntelyCare; Ceipal]

> **Design implications for Zephyrus.**
> - Implement the roster as a **constraint solver**: hard constraints = coverage floors (incl. Title 22), max-hours, rest, certifications; soft constraints = preferences, weekend/holiday equity, **cumulative-fairness**, shift-pattern continuity.
> - Drive rosters from a **predictive demand forecast** (census × acuity × seasonality × ADT patterns), not a flat grid — staff to *predicted* load.
> - Provide **self-scheduling + a shift-swap marketplace** with auto-validation against constraints, and a **real-time sick-call mitigation flow**: when a call-out hits, instantly rank qualified float/per-diem/voluntary-OT fills by competency, fairness ledger, and fatigue — present as ranked options to the charge nurse/supervisor.

---

## 7. Nurse Experience & Retention — Designing *For* Nurses

The literature is unambiguous: **higher workload → higher burnout → higher turnover intention, quiet quitting, and intent-to-leave.** Mandatory overtime is independently the strongest driver of intent-to-leave. Work-environment factors — nurse-manager leadership, staffing/resource adequacy, nurse-physician relations — mediate turnover through burnout. Turnover is enormously costly and itself degrades staffing, creating a doom loop. [PMC turnover/overtime cross-sectional; Greece quiet-quitting study; Frontiers IJPH]

Crucially for a tool like Zephyrus: **scheduling/optimization technology can help *or harm*.** Nurses respond positively to AI scheduling **only when it is fair, transparent, accommodates individual circumstances, and supports work-life balance**; opaque or punitive optimization breeds distrust and accelerates exit. Systems that flag over-scheduling and distribute shifts by past workload reduce burnout. [PMC nurse perspectives on AI scheduling; Ceipal]

> **Design implications for Zephyrus.**
> - **Transparency by default**: every assignment/schedule recommendation must show *why* (acuity, continuity, fairness ledger, constraints). No black-box assignments.
> - **Fairness as a measured, visible objective**: cumulative heavy-load, weekend/holiday, and undesirable-shift burden tracked per nurse and *equalized over time* — and shown to nurses.
> - **Accommodate the human**: honor preferences, predictability (no last-minute thrash), adequate rest, and life constraints as real soft-constraint weights, not afterthoughts.
> - **Burnout/fatigue guardrails**: model consecutive shifts, hours, and cumulative acuity; flag and resist assignments/rosters that push nurses into fatigue zones. Frame the tool as the nurse's advocate against overload, not management's productivity whip.
> - **Co-design and nurse override** everywhere — the charge nurse and bedside nurse always have the final say and a one-tap override with captured rationale.

---

## 8. Real-Time Nursing Operations — What Actually Happens Minute-to-Minute

The **charge nurse** is the unit's real-time air-traffic controller. Minute-to-minute they: redistribute assignments as acuity shifts; manage call-offs, float coordination, and overtime; coordinate admissions, discharges, and transfers with bed management and the house supervisor; assess discharge readiness; triage incoming patients by acuity; staff to breaks/lunches without breaching ratios; respond to deterioration/rapid-response events; and absorb the constant churn of ADT. In the ED specifically, charge-nurse **throughput** — clearing admitted/discharged/transferred patients to open beds — is the core job. [Nurse.com charge duties; HRCloud; Nurse Sophie]

The **house/nursing supervisor (a.k.a. bed admission coordinator)** owns *house-wide* bed placement, matching incoming patients (ED, OR, direct admits, transfers) to the right unit/bed, and balancing load across units.

**The data they lack** (and Zephyrus can provide):
- A **real-time, acuity-aware picture of every unit's true load** (not midnight census) and where slack exists.
- **Predicted near-future demand** — incoming admissions, expected discharges, OR/ED pull — *before* it lands.
- **Discharge-readiness and anticipated-discharge** signals to forecast bed availability.
- **Who is actually safe to take the next admission** given current acuity + ADT churn, not just who has an open bed.
- **Cross-unit / float visibility** to rebalance staff to demand.
Today these decisions are made from whiteboards, phone calls, and intuition.

> **Design implications for Zephyrus.**
> - Build the **charge-nurse real-time cockpit**: live acuity-adjusted unit load, current assignments + fairness ledger, pending ADT, break-coverage gaps, ratio-floor status, and at-risk care tasks — one screen, refresh in seconds.
> - Build the **house-supervisor capacity command view**: all units' acuity-adjusted load and *true* admit-capacity (open bed *and* a nurse safe to take it), predicted demand inflow (ED boarding, OR schedule, expected transfers), and predicted discharge-driven bed releases — with a **best-placement recommendation** for each incoming patient.
> - **Anticipate, don't just report**: forecast the next 2–8 hours of admissions/discharges and proactively recommend pre-positioning float staff and sequencing discharges to open the right beds. Make the implicit decisions of the charge nurse and supervisor *explicit, data-backed, and faster* — while leaving final judgment with them.
> - **Right answer = safe answer**: "capacity to admit" must always be gated on *nurse safety to accept*, never bed availability alone. This is the design rule that keeps Zephyrus on the right side of the staffing-outcomes evidence.

---

## Cross-Cutting Design Principles for Zephyrus

1. **Acuity-first, census-never.** Every capacity/workload computation is acuity-adjusted and ADT-aware; raw census is a deprecated input.
2. **Ratio floors are hard constraints; acuity sets the real target** (`required = max(floor, acuity-demand)`).
3. **Optimize a multi-objective, not a single cost.** Fairness, continuity, geography, competency, and missed-care risk are co-equal with efficiency — and safety guardrails dominate.
4. **The nurse is the user, not the resource.** Transparency, fairness ledgers, override, fatigue guardrails, and reduced charting/handoff burden are non-negotiable retention features.
5. **Anticipate the next shift, not just describe this one.** Predictive demand/discharge forecasting is the core differentiator.
6. **Close the loop with NSIs.** Tie staffing/assignment decisions to nurse-sensitive outcome risk (falls, HAPI, CLABSI, CAUTI, failure-to-rescue) so efficiency is never bought with patient harm.

---

## Sources

1. National Nurses United — *Ratios: what does the California ratios law actually require?* https://www.nationalnursesunited.org/ratios-what-does-california-ratios-law-require
2. California HealthCare Foundation — *Minimum Nurse Staffing Ratios in California Acute Care Hospitals.* https://www.chcf.org/wp-content/uploads/2017/12/PDF-MinNurseStaffingRatios.pdf
3. LegalClarity — *Title 22 California Nursing Ratios: Rules and Penalties.* https://legalclarity.org/title-22-california-nursing-ratios-legal-requirements/
4. California Legislative Information — *Assembly Bill 394.* https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=199920000AB394
5. NYSNA — *Research Shows Safe Staffing Saves Lives* (Aiken et al., JAMA 2002). https://www.nysna.org/resources/research-shows-safe-staffing-saves-lives
6. Nurse.org — *Every Extra Patient Raises Death Risk by 8%: Penn Study.* https://nurse.org/news/penn-study-nurse-staffing-mortality/
7. ScienceDirect — *The association between nurse staffing and inpatient mortality: a shift-level retrospective longitudinal study.* https://www.sciencedirect.com/science/article/pii/S0020748921000936
8. The Lancet — *Effects of nurse-to-patient ratio legislation on nurse staffing and patient mortality, readmissions, and length of stay.* https://www.thelancet.com/journals/lancet/article/PIIS0140-6736(21)00768-6/abstract
9. ANA — *Safe Nurse Staffing and Patient Outcomes / Principles for Nurse Staffing.* https://www.nursingworld.org/practice-policy/nurse-staffing/
10. Congress.gov — *H.R.3415, Nurse Staffing Standards for Hospital Patient Safety and Quality Care Act of 2025.* https://www.congress.gov/bill/119th-congress/house-bill/3415/text
11. PMC — *The impact of the NHPPD staffing method on patient outcomes.* https://pubmed.ncbi.nlm.nih.gov/20696429/
12. PMC — *Calculating Optimal Patient to Nursing Capacity: Comparative Analysis of Traditional and New Methods.* https://pmc.ncbi.nlm.nih.gov/articles/PMC11612603/
13. PubMed — *Determining optimal nursing intensity: the RAFAELA method.* https://pubmed.ncbi.nlm.nih.gov/14756829/
14. PubMed — *Benchmarking by the RAFAELA Patient Classification System — optimal nursing intensity levels.* https://pubmed.ncbi.nlm.nih.gov/19592803/
15. PubMed — *Perroca's patient classification instrument: construct validity analysis.* https://pubmed.ncbi.nlm.nih.gov/15122409/
16. PubMed — *Classification of patients and nursing workload in intensive care: comparison between instruments (NAS vs Perroca).* https://pubmed.ncbi.nlm.nih.gov/28678901/
17. JMIR Medical Informatics (2025) — *Estimating Nurse Workload Using a Predictive Model From Routine Hospital Data.* https://medinform.jmir.org/2025/1/e71666
18. ScienceDirect — *Promoting continuity of care in nurse-patient assignment: A multiple objective heuristic algorithm.* https://www.sciencedirect.com/science/article/abs/pii/S0167923623000015
19. My American Nurse — *A new patient-acuity tool promotes equitable nurse-patient assignments.* https://www.myamericannurse.com/a-new-patient-acuity-tool-promotes-equitable-nurse-patient-assignments/
20. PMC — *Optimising Nurse–Patient Assignments: The Impact of Machine Learning Model on Care Dynamics.* https://pmc.ncbi.nlm.nih.gov/articles/PMC12018274/
21. PMC — *Development of a Nursing Assignment Tool Using Workload Acuity Scores.* https://pmc.ncbi.nlm.nih.gov/articles/PMC8402942/
22. PubMed — *Matching Nursing Assignment to Patients' Acuity Level: The Road to Nurses' Satisfaction.* https://pubmed.ncbi.nlm.nih.gov/31068499/
23. PMC — *Hospital Variation in Missed Nursing Care* (Kalisch). https://pmc.ncbi.nlm.nih.gov/articles/PMC3137698/
24. PubMed — *Development and psychometric testing of a tool to measure missed nursing care (MISSCARE).* https://pubmed.ncbi.nlm.nih.gov/19423986/
25. ScienceDirect — *Prevalence of missed nursing care and its association with work experience.* https://www.sciencedirect.com/science/article/pii/S2666142X24000237
26. Vizient — *Nurse-Sensitive Indicators (NSI): NDNQI Quality Metrics.* https://www.vizier.health/glossary/nurse-sensitive-indicators/
27. Wolters Kluwer — *Nursing quality indicators improve care safety and quality (NDNQI).* https://www.wolterskluwer.com/en/expert-insights/ndnqi-measures-aim-to-improve-healthcare-safety-and-quality
28. Press Ganey — *Comprehensive Guide to the NDNQI.* https://www.pressganey.com/resources/blog/your-comprehensive-guide-to-the-press-ganey-national-database-of-nursing-quality-indicators-ndnqi/
29. I-PASS Institute — *A Closer Look at SBAR vs. the I-PASS Handoff Method.* https://news.ipassinstitute.com/news/a-closer-look-at-sbar-vs.-the-i-pass-handoff-method
30. AHRQ — *Making Healthcare Safer IV: Use of Structured Handoff Protocols.* https://effectivehealthcare.ahrq.gov/sites/default/files/related_files/structured-handoff-rapid-research.pdf
31. PMC — *Use of structured handoff protocols for within-hospital unit transitions: a systematic review (Making Healthcare Safer IV).* https://pmc.ncbi.nlm.nih.gov/articles/PMC12232517/
32. PubMed — *Impact of structured interdisciplinary bedside rounding on patient outcomes.* https://pubmed.ncbi.nlm.nih.gov/31810994/
33. PubMed — *The Impact of Bedside Interdisciplinary Rounds on Length of Stay and Complications.* https://pubmed.ncbi.nlm.nih.gov/28272588/
34. IHI / MN Hospitals — *How-to Guide: Multidisciplinary Rounds.* https://www.mnhospitals.org/wp-content/uploads/Portals/Documents/patientsafety/Patient%20Family%20Engagement/IHIHowtoGuideMultidisciplinaryRounds.pdf
35. Wikipedia — *Nurse scheduling problem.* https://en.wikipedia.org/wiki/Nurse_scheduling_problem
36. arXiv — *Bayesian Optimisation Algorithm for Nurse Scheduling.* https://arxiv.org/pdf/0804.0524
37. Teambridge — *Innovative Strategies to Master Nursing Scheduling.* https://www.teambridge.com/blog/nursing-scheduling
38. IntelyCare — *Top 5 Nurse Scheduling Problems and How to Solve Them.* https://www.intelycare.com/facilities/resources/top-5-nurse-scheduling-problems-and-how-to-solve-them/
39. PMC — *Exploring nurse perspectives on AI-based shift scheduling for fairness, transparency, and work-life balance.* https://pmc.ncbi.nlm.nih.gov/articles/PMC12406402/
40. PMC — *Nurse Staffing, Work Hours, Mandatory Overtime, and Turnover Affect Job Satisfaction, Intent to Leave, and Burnout.* https://pmc.ncbi.nlm.nih.gov/articles/PMC11091319/
41. PMC — *Workload increases nurses' quiet quitting, turnover intention, and job burnout: evidence from Greece.* https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11999808/
42. America's Essential Hospitals — *Building Workforce Stability: Nursing Retention Strategies.* https://essentialhospitals.org/building-workforce-stability-nursing-retention-strategies-for-acute-care-hospitals/
43. Nurse.com — *Charge Nurse Duties: Role, Responsibilities, & Differences.* https://www.nurse.com/blog/charge-nurse-duties-leadership-at-the-bedside-and-more/
44. NCBI PMC — *Standardizing admission and discharge processes to improve patient flow.* https://www.ncbi.nlm.nih.gov/pmc/articles/PMC3407754/
45. PMC — *Impact of Discharge Rounds on Patient Flow and Hospital Outcomes.* https://www.ncbi.nlm.nih.gov/pmc/articles/PMC12433610/
