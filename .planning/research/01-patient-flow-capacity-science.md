# The Science and Best Practices of Hospital Patient Flow & Real-Time Demand/Capacity Management

**Dossier section for Zephyrus — a real-time demand/capacity optimization platform**
*Prepared: June 2026. All claims cited to primary or peer-reviewed sources with URLs in the Sources section. Where evidence is institution- or vendor-reported rather than peer-reviewed, this is stated explicitly.*

---

## Orientation: why this matters and what is actually proven

Hospital patient flow is fundamentally a **queueing problem layered on a demand-prediction problem**. Patients arrive (scheduled and unscheduled), occupy a constrained set of beds for a variable length of stay (LOS), and depart through a discharge process riddled with non-clinical delays. When the inpatient hospital fills, admitted patients **board** in the Emergency Department (ED), ambulances are **diverted**, electives are cancelled, and measurable harm follows. The dominant operational insight — repeated across the IHI, NHS, queueing-theory, and command-center literatures — is that the binding constraint is usually **output block (discharge / inpatient-bed availability), not ED throughput** (Morley 2018). Zephyrus must therefore optimize the *whole* admission–census–discharge loop, with the discharge prediction as its engine.

A second, sobering theme runs through the evidence base: the *operational logic* is strong (Litvak's variability theory, Asplin's input–throughput–output model, Little's Law), and the *harm-association* evidence is strong (boarding and crowding are reproducibly linked to mortality), but the **intervention** evidence is mostly low-certainty before-after work, and the most rigorous controlled tests of the flashiest intervention (AI command centers) found *no consistent benefit* (Mebrahtu 2023; Johnson 2024). Zephyrus should lead with mechanism and prediction quality, instrument its own outcomes with controls, and avoid the vendor habit of over-claiming.

---

## 1. IHI Real-Time Demand Capacity Management (RTDC) — the product core

RTDC is the named IHI methodology that is the literal core of Zephyrus. It was developed by **Roger K. Resar, MD** (IHI Senior Fellow) with **Kevin Nolan**, grounded in **queueing theory and constraint/compression-wave theory**, first piloted at **UPMC Shadyside (526 beds, from 2007)** and held up by IHI as **Strategy S8** in the white paper *Achieving Hospital-wide Patient Flow* (2nd ed., 2020). The canonical paper is Resar, Nolan, Kaczynski & Jensen, *Jt Comm J Qual Patient Saf* 2011;37(5):217–227.

### The four-step daily process (verbatim from the IHI white paper)

> "RTDC comprises four steps that are undertaken in the hospital each day: 1) predict capacity at the unit level; 2) predict demand at the unit level; 3) develop a plan to match capacity and demand at the unit level; and 4) evaluate the results of the plans to identify barriers to patient flow that can be the focus of targeted improvement projects."

1. **Predict capacity** — how many beds the unit will *free* today, by identifying every patient likely to discharge or transfer out, ideally **by a target time** (so beds turn over before the afternoon admission wave).
2. **Predict demand** — how many patients will *need* one of the unit's beds today, summed across all inbound sources.
3. **Develop the plan** — compute the gap; if demand exceeds capacity, build a **specific, named, assigned** action plan to close it today (expedite a discharge, pull a transfer forward, escalate).
4. **Evaluate the plan** — review *yesterday's* prediction vs. what happened. Each miss is logged as a **barrier/constraint** feeding targeted improvement projects. This learning loop is what distinguishes RTDC from ordinary bed-board management. Resar lists **"unit-based reliability of discharge predictions"** as the *first* sustained measure.

RTDC is explicitly framed as *not* extra work: "Its four steps, integrated into current bed management processes, are not an add-on to the work needing to be accomplished every day."

### The math

**Predict capacity (beds free):**

> Predicted capacity = current empty beds + confidence-weighted predicted discharges + predicted transfers-out

Discharges are **not** binary. Mature RTDC sites tier them by confidence — *confirmed/definite* (order written or clinically certain) vs. *potential/possible* — and probability-weight the roll-up. The widely used operational pattern (from RTDC-implementation literature; treat the constants as illustrative, not an IHI-fixed law):

> Predicted discharges ≈ (0.80 × confirmed) + (0.50 × potential)

Target prediction horizons in the automating literature (Barnes et al., *JAMIA* 2016, Johns Hopkins) are **discharge-by-2 p.m.** and **discharge-by-midnight**; IHI's flow measures also use a "discharge by noon" style target and "% of units with ≥1 available bed at 7 a.m."

**Predict demand (beds needed):**

> Predicted demand = ED admissions + OR/PACU (post-surgical) admissions + direct admits + transfers in (external) + inter-unit transfers in (e.g., ICU step-down)

Crucially predicted **per service/unit, not just hospital-wide**. For each demand element, take the larger of historical-average vs. real-time observed counts (IHI S7).

**The gap / bed-need number** — the load-bearing output, a single signed integer per unit per day:

> Gap = Predicted capacity − Predicted demand

Gap ≥ 0 → surplus (relief valve for other units). Gap < 0 → **bed deficit**; |gap| = beds the unit must create today, triggering the Step-3 plan and, if unresolved, escalation.

### Two-tier huddle structure (both mid-morning)

- **Tier 1 — Unit bed huddle** (≤10 min, ~9:00–9:30 a.m.), led by the **charge nurse**, with clinical nurses/clinical nurse leader, case manager, and the attending/hospitalist; supported by pharmacy, radiology, lab, EVS. Walk the four steps for *this* unit; output the bed-need integer and an action plan. Unresolvable deficits escalate up.
- **Tier 2 — Hospital-wide capacity huddle ("the bed meeting")** — per the white paper, "include[s] administrative decision makers, bed managers, staffing coordinators, and representatives from the ED, OR, ICU, and all inpatient units." It consolidates each unit's signed gap, **matches surplus units to deficit units**, synchronizes the timing of admissions/discharges/transfers, and escalates recurring mismatches to improvement projects. Census is commonly coded **green/yellow/red** with surge protocols by level.

### Documented results (UPMC Shadyside, Resar 2011, single-site uncontrolled before-after)

Overnight PACU holds **eliminated two months after start**; LWBS **routinely < 0.5% by May 2008**; ED median LOS for admitted patients **routinely < 4 hours after March 2008**; aggregate LOS **maintained < 5.75 days**. RTDC has since been adopted in 20+ hospitals worldwide. *Caveat:* these are run-chart threshold observations on one hospital with no concurrent comparator.

### Design implications for Zephyrus

- **The core data model is, per unit per day:** a confidence-weighted predicted-discharge count, a source-segmented predicted-demand count, and a single signed **bed-need integer**. Build the entire app around producing and refining this triple.
- **Model discharge confidence as a first-class tier** (definite / probable / possible), expose the probability weighting, and let users tune the weights per unit. Predict against **two horizons (by-2 p.m. and by-midnight / by-noon)**.
- **Implement Step 4 as a learning system, not a footnote:** persist each day's prediction, reconcile against actuals, compute **per-unit discharge-prediction reliability** as a headline KPI, and auto-surface recurring barriers as improvement-project candidates.
- **Build both huddle tiers natively:** a unit huddle view (charge-nurse-facing, ≤10-min flow) whose signed gaps **roll up** to a hospital bed-meeting view that matches surplus to deficit and synchronizes timing. Provide green/yellow/red surge coding with configurable triggers.
- **ML-assist the capacity step** (per Barnes 2016): an LOS/discharge-likelihood model that augments — never replaces — clinician prediction, benchmarked against clinician accuracy.

---

## 2. NHS patient flow bundles

The NHS codifies flow best practice in operational bundles authored largely by the Emergency Care Improvement Programme (ECIP/ECIST), Dr Ian Sturgess prominent among them.

**The SAFER Patient Flow Bundle** (adult inpatient wards, excluding maternity) — implement all five together:
- **S — Senior Review.** "All patients will have a senior review before midday by a clinician able to make management and discharge decisions."
- **A — All patients** "will have an Expected Discharge Date (EDD) and Clinical Criteria for Discharge (CCD), set by assuming ideal recovery and assuming no unnecessary waiting" — set **within 14 hours of admission**.
- **F — Flow** commences early; wards "will ensure the first patient arrives on the ward by 10am."
- **E — Early discharge.** "33% of patients will be discharged from base inpatient wards before midday."
- **R — Review.** Systematic weekly MDT review of **stranded patients (LOS > 7 days)** with a "home first" mindset.

**Red2Green (Red and Green Bed Days)** — "a visual management system to assist in the identification of wasted time in a patient's journey." A **Red day** = "a patient receives little or no value adding acute care" (the care could be delivered in a non-acute setting *and* their physiological status would not require emergency admission). A **Green day** = "value adding acute care that … can only be in an acute hospital bed." Board rounds **start with every patient marked Red**; patients convert to Green only when senior review, EDD/CCD, the day's interventions, and next-day discharge meds are all in place. Red days are logged by cause to drive improvement.

**Discharge to Assess (D2A) / "Home First"** — discharge as soon as safe, then complete long-term assessment in the patient's *own home*, not the acute ward. Four pathways:
- **Pathway 0** — simple discharge home, no new support.
- **Pathway 1** — home with new health/social support, reablement (~4–6 weeks). *Pathways 0+1 are the default — NHS targets ~95% of over-65s.*
- **Pathway 2** — bedded intermediate care / rehab.
- **Pathway 3** — new long-term residential/nursing care.

**Criteria-Led Discharge (CLD)** — the senior decision-maker sets objective clinical criteria *in advance and in the notes*; a competent nurse/AHP/junior then discharges once criteria are met, removing the wait for senior sign-off (NHS England guidance B0928).

**Expected Date of Discharge (EDD)** — a professional judgement of when the patient can leave, set early ("within 14 hours"; the 2022 100-day challenge says "discharge within 48 hours of admission"), based on a **clinical end-point assuming no unnecessary waiting**, owned by the consultant + MDT, reviewed daily, and communicated to patient and family. Delays beyond EDD for non-clinical reasons are recorded as **EDD+1, EDD+2** to make them visible.

**The 100-Day Discharge Challenge (2022)** and the **stranded (>7 days) / super-stranded (>21 days)** metrics target deconditioning harm — "Today is a red day until we prove otherwise." Documented outcomes: University Hospitals Leicester reported bed occupancy −1.2% (to 94.6%) and +10.3 pp discharges before noon; Barking, Havering & Redbridge cut super-stranded patients 199 → 98; a peer-reviewed pre-post study (Brazil) on SAFER + Red2Green cut median LOS 19 → 14.2 days (p < 0.001) with neutral safety metrics.

### Design implications for Zephyrus

- **Make EDD + Clinical Criteria for Discharge first-class entities**, set at admission, owned by a named clinician, daily-reviewed, with **EDD+N variance tracking** when exceeded for non-clinical reasons.
- **Build a Red/Green daily board-round mode** that defaults every patient to Red and requires explicit value-justification to turn Green, logging **coded delay reasons** (internal vs. external; "fit-to-proceed-but-waiting" vs. "not fit").
- **Model the four D2A pathways** as a discharge-disposition taxonomy and track the **pathway-mix** (target the 0/1 default), since placement (Pathways 2/3) is the dominant long-stay driver.
- **Support Criteria-Led Discharge workflow:** capture prospective criteria, then let authorized non-consultant roles execute discharge when criteria are objectively met.
- **Surface stranded (>7d) and super-stranded (>21d) cohorts** as standing review queues with the SAFER "R" weekly MDT.
- **Encode the SAFER targets as configurable KPIs** (senior review before midday %, first-ward-patient-by-10am, 33%-before-noon, stranded review completion).

---

## 3. Capacity command centers

The reference implementation is the **Johns Hopkins Judy Reitz Capacity Command Center** (GE Healthcare, live Feb 2016): ~5,200 sq ft, ~38 workstations, a **"Wall of Analytics"** ingesting ~500 messages/minute from ~14–15 IT systems, **co-locating** four previously-siloed teams (physician referral/transfer line, critical-care transport, admissions, bed management) with no added headcount. The design pattern is the **"tile" model** — each tile is a discrete real-time analytics app for one flow problem, streamed to the wall *and* to staff phones/desktops, drill-down on click. Documented Hopkins tiles: **ED Status, Inbound Transfer (HAL), Bed Summary, Forecast Occupancy (48-hr projection)**, plus discharge/transport/OR-PACU tiles.

**Hopkins outcomes** (institution-reported; two vintages with different baselines): bed assignment after ED admit decision **~30–38% faster** (~3.5 h sooner); ED→unit transfer **26% faster**; ED boarding **~20% reduced**; OR/PACU transfer delays **70–83% reduced**; external transfer acceptance **46–60% improved**; critical-care dispatch **43–63 min sooner**; before-noon discharges **+21%**; **~16 beds of capacity created without building any**.

**Other centers:** Tampa General "CareComm" (Epic-fed, 20 AI tiles) — ~$40M saved, ~20,000 excess bed-days cut (≈30 beds), LOS −0.5 day, diversion −25%; later TGH C3 (Palantir) — 83% faster placement, 28% fewer PACU holds. Humber River (Canada, ~35 beds unlocked, hallway medicine eliminated). Bradford Royal Infirmary (Europe's first AI command centre, ~800 beds). OHSU statewide Mission Control. University of Michigan M2C2 (NEJM Catalyst: 33% shorter inpatient-bed wait, 37% shorter ED wait).

**Evidence caveat (critical):** the two most rigorous, *independent* evaluations are **null-to-negative**. Bradford's interrupted-time-series (Mebrahtu, *Int J Qual Health Care* 2023) found no significant LOS impact and *worsened* treatment-to-consultation time; the NIHR controlled mixed-methods study (Johnson 2024) found "no significant difference" and "no conclusive impact." A scoping review (Franklin, *J Patient Saf* 2022) found only 8 articles / 7 centers, "evidence in its earliest stages." The flashy percentage gains are institution/vendor-reported.

### Design implications for Zephyrus

- **Adopt the tile model as the UI primitive:** a configurable grid of single-purpose real-time tiles (ED status, inbound transfers, bed summary by unit, **forecast occupancy 48h**, discharge barriers, OR/PACU pipeline, transport/EVS), each drill-down, each streamable to wall *and* mobile.
- **Build a "single source of truth" aggregation layer** fusing ADT/EHR, bed management, transport, OR scheduling, lab/imaging — the integration breadth (~15 feeds) is the moat, not the dashboard.
- **Provide three analytics modes:** descriptive (current state) → predictive (48-hr occupancy, surge) → prescriptive (recommended, assignable actions / nudges).
- **Support co-location workflows** (transfer center, bed management, transport, EVS, case management in one operational view) — the value is coordinating siloed functions, not the screen.
- **Instrument your own outcomes with controls.** Given the null independent evidence, Zephyrus should ship built-in before/after + comparator measurement so customers can prove (or disprove) impact honestly.

---

## 4. Variability methodology (Eugene Litvak / IHO)

**Eugene Litvak, PhD** (Institute for Healthcare Optimization) applied operations-management science to patient flow, trademarking the **Variability Methodology®**. The conceptual heart is the distinction between:

- **Natural variability** — random, unscheduled, *uncontrollable*: ED arrival timing (driven by when people get sick), disease severity/acuity.
- **Artificial variability** — *man-made*, driven by scheduling decisions, especially elective surgery clustered into convenient days (heavy Tue/Wed, light Fri), manufacturing census peaks unrelated to when patients actually get sick.

Two counterintuitive, load-bearing claims: (1) artificial variability often **exceeds** the natural variability in ED flow; (2) artificial variability is **eliminable**, natural is not — so *first* smooth the artificial peaks, *then* size capacity to the irreducible natural demand.

**The solution (three phases):** (1) **separate the flows** — split scheduled (elective) from unscheduled (emergent) flow into separate ORs/beds/staffing; run elective at high utilization, keep slack for unscheduled; (2) **smooth the elective schedule** across the week and *by destination unit*; (3) **right-size** to the now-flat demand. A common tactic: dedicate one or more ORs to urgent/emergent cases and dismantle rigid surgeon block scheduling.

**Why it works (queueing logic):** downstream (ICU/step-down) demand is stochastic; a lumpy elective schedule superimposes artificial peaks that saturate the unit, block the OR, cancel cases, and force diversion. The landmark empirical finding (McManus, *Anesthesiology* 2003): ICU diversions correlated **more** with *scheduled* caseload (r = 0.542) than unscheduled (r = 0.255); ~70% of peak-period diversions tracked scheduled-surgery variability. Smoothing shrinks the *variance* of demand — and in a queueing system, required capacity is driven by variance, not just the mean — so the same beds suffice, or safe occupancy rises from ~75% toward ~90% without diversion.

**Outcomes (single-site before-after, no RCTs):** Cincinnati Children's — occupancy ~76% → ~91%, capacity equivalent to ~75 beds / a cancelled $100M tower (Ryckman, *Jt Comm J* 2009; per *Health Affairs* 2011, weekday emergent-case waits −28% as volume rose 24%). Boston Medical Center — elective cancellations 334 → 3 (−99.5%), +1,000 cases/yr, step-down admission variability −55% (RWJF-funded pilot). Mayo Clinic Florida (Smith, *J Am Coll Surg* 2013) — volume +4%, utilization +5%, overtime −27%, net operating income +38% (the best-quantified OR-smoothing study). St. John's Springfield — inpatient surgical capacity +59%.

### Design implications for Zephyrus

- **Separate scheduled from unscheduled flow in the data model and forecasts** — never blend elective and emergent demand; they have different statistics and require different capacity buffers.
- **Build a surgical-smoothing analytics module:** ingest the master surgery schedule, project **downstream (ICU/step-down) census by destination unit and day-of-week**, quantify artificial-variability peaks, and recommend smoothed schedules.
- **Quantify and report artificial vs. natural variability** explicitly (e.g., coefficient of variation of scheduled vs. unscheduled admissions per unit) — this reframes "we need more beds" as "we need to smooth."
- **Model the capacity-creation lever:** show, via queueing math, how much safe occupancy / bed-equivalent capacity smoothing would free — the executive-facing ROI story.

---

## 5. Operations / queueing theory foundations

**Little's Law** (`L = λW`): average census **L** = admission rate **λ** × LOS **W**. Distribution-free, holds in steady state. It is the basis for all flow dashboards and the quantitative argument that *cutting LOS decompresses the hospital* (to reduce census at fixed demand, you must cut LOS). Example: 400 census ÷ 100 admits/day = 4-day mean LOS.

**Occupancy–delay relationship:** in every queue, average delay rises **nonlinearly** with utilization ρ and **→ ∞ as ρ → 100%** (`W_q ∝ 1/(1−ρ)`). There is an "elbow" whose position depends on **size** and **variability (CV)**, not occupancy alone.

**The "85%" threshold — handle with care.** It originated as a U.S. Certificate-of-Need bed-*need trigger*, and was popularized as a "danger zone" by **Bagust, Place & Posnett, *BMJ* 1999**: "risks are discernible when average bed occupancy rates exceed about 85%, and an acute hospital can expect regular bed shortages … if average bed occupancy rises to 90% or more." But the OR consensus (**Proudlove 2019, "The 85% bed occupancy fallacy"**; **Green 2002/2006**) is that **there is no universal optimal occupancy**: occupancy is an *output* of arrival rate, LOS, bed count, and variability. The correct managerial move is to set a **required access/delay standard as the input** and let occupancy fall out — a safe occupancy for a 60-bed unit can be dangerous for a 10-bed unit.

**Erlang models:** offered load `a = λ × LOS` (in Erlangs). **Erlang B (loss / M/G/c/c)** models *no waiting room* — blocked patients are **diverted/lost** (full ICU on diversion); insensitive to the LOS *distribution* (mean only). **Erlang C (delay / M/M/s)** models an *infinite queue* — blocked patients **wait/board** (ED patients awaiting an inpatient bed) and always needs more servers than Erlang B. Invert either to size beds for a target blocking/delay probability (de Bruin 2010).

**Census forecasting:** `Census(t) = Census(t−1) + Admissions(t) − Discharges(t)`. Deployed models use ElasticNet regression on time-of-day, day-of-week, and lagged census/admit/discharge, separated medical vs. surgical (Ryu, *JAMIA* 2021, MAE ≈ 10 patients / ±3.4%). Admissions are well-modeled as a **Poisson process with day-of-week and seasonal rate variation**; notably **variable discharge rates matter more than variable admission rates** for producing overflows (Harrison 2005).

**Variability, pooling, economies of scale:** delay scales with the *square* of the service-time CV (M/G/1: `W_q = [λρ/(1−ρ)]·[(1+CV²)/2]`). Lower-variability and **larger, pooled** units safely run at higher occupancy (square-root staffing: `s ≈ a + β√a`) — formalizing why fragmenting beds into small specialty silos forces patients to wait *even when beds are free*. (Caveat: over-pooling can destroy beneficial specialization — Best 2015.)

### Design implications for Zephyrus

- **Embed Little's Law as a first-class calculator** linking census, admission rate, and LOS — and use it to translate LOS-reduction targets into census/bed-freeing projections.
- **Reject a hardcoded 85% target.** Instead let users set an **access/delay standard** (e.g., P(wait) ≤ 1%, or boarding ≤ 4h) and *compute* the implied safe occupancy per unit, accounting for **unit size and CV**. Display occupancy as an *output*, not a goal.
- **Implement Erlang B and Erlang C bed-sizing tools** — Erlang B for divert-on-full units (ICU), Erlang C for queue-on-full (ED→inpatient) — to answer "how many beds for target blocking/delay?"
- **Ship a census-forecasting engine** (ElasticNet baseline; Poisson admit model with day-of-week + seasonality; medical/surgical split), and weight **discharge-rate variability** heavily — it drives overflow more than admit variability.
- **Compute and expose CV** of arrivals and LOS per unit; flag high-CV units as smoothing/pooling candidates and model the occupancy headroom that variance-reduction or pooling would unlock.

---

## 6. Boarding, diversion, throughput metrics

**ED boarding** = an admitted (or observation) patient held in the ED after the admit decision, awaiting an inpatient bed. CMS measure **ED-2 (CMS111)** = median time from admit-decision to ED departure; **The Joint Commission flags boarding > 4 hours (240 min)** as a safety risk. Mean U.S. boarding rose **167 min (2021) → 190 min (2022)**, ~47% of admitted patients' ED time. >90% of EDs report routine crowding; a Nov 2022 letter from ACEP + 34 organizations called boarding "a national crisis."

**Harm evidence:** Singer et al. (*Acad Emerg Med* 2011, 41,256 admissions) — mortality **2.5% (boarded <2h) → 4.5% (≥12h)**, LOS 5.6 → 8.7 days; the strongest single dose-response (a 2020 PLOS One systematic review found the pooled signal mixed — present boarding-mortality as "associated/dose-dependent in large cohorts," not meta-analytically settled). Sun et al. (*Ann Emerg Med* 2013, ~995k admissions) — admission on a high-crowding day → **+5% odds of inpatient death**, ~300 excess deaths/yr in California. **Cost** (Canellas, *Ann Emerg Med* 2024, TDABC): a boarded med/surg day costs **$1,856 vs $993** inpatient.

**Ambulance diversion** = rerouting incoming ambulances because the ED is saturated; most dangerous for time-sensitive AMI/stroke. Shen & Hsia (*JAMA* 2011): AMI patients whose nearest ED had **≥12h diversion vs none** had **30-day mortality 19% vs 15%, 1-year 35% vs 29%**, with less catheterization/PCI; (*Health Affairs* 2015) ≥12h diversion → 9.8% higher 1-year mortality.

**Throughput KPIs:** LOS, **observed-to-expected LOS (target < 1.0)**, geometric-mean LOS (DRG basis), occupancy; **discharge-before-noon %** (commonly targeted ~30–50%, evidence contested — see §7); ED **door-to-doc / time-to-provider (T2P)**, ED LOS, **left-without-being-seen (LWBS, target < 2%)**, **boarding time (ED-2)**; **bed turnaround / EVS time** (STAT 45 / NEXT 60 / NORMAL 120 min). **CMS is replacing** legacy OP-18/OP-22/ED-1/ED-2 with the episode-based **ECAT eCQM**, whose four "must-monitor" gaps are **front-end wait > 60 min, leaves-without-evaluation, boarding > 240 min, ED LOS > 480 min** (voluntary 2027 → mandatory 2028 → payment 2030).

### Design implications for Zephyrus

- **Make boarding the central safety metric**, instrumented to **ECAT thresholds (60 / 240 / 480 min)** for future CMS alignment — design forward, not to the retiring measures.
- **Track the full KPI set** with definitions and configurable targets: LOS, O:E LOS, occupancy, DBN%, T2P, ED LOS, LWBS, boarding time, EVS turnaround — and tie boarding alerts to inpatient occupancy (boarding sharply worsens above ~85%).
- **Model diversion risk** and provide condition-specific diversion logic (e.g., never divert STEMI), reflecting the AMI/stroke harm evidence.
- **Quantify the cost of boarding** (≈2× daily cost for med/surg) in the executive ROI view to justify capacity actions.
- **Capture admit-decision timestamps** precisely — ED-2/ECAT boarding is decision-to-departure, the single most important event pair to log.

---

## 7. Discharge process optimization

**Barriers are predominantly non-clinical** and concentrate in a small tail of long-stayers: in one review, 3.5% of admissions consumed 27.2% of bed-days, and ~one in five inpatient bed-days is a delayed discharge. The taxonomy: **medical** (awaiting test/consult/procedure; amplified by the weekend effect), **logistical** (transport, pharmacy/TTOs, paperwork, late discharge orders — one study: 126 min order-to-departure), **placement** (no SNF/rehab bed, insurance authorization, infection refusal — the dominant long-stay driver), and **social** (no caregiver/home support, homelessness, capacity issues).

**Multidisciplinary rounds (IDR) / huddles:** evidence is altitude-dependent. Pannick (*JAMA Intern Med* 2015) found interdisciplinary teams *rarely* move LOS/mortality — don't over-claim IDR reduces LOS. But **case management** has the strongest LOS signal in high-risk cohorts (HF: −1.28 days; Siddique, *JAMA Netw Open* 2021), and bundled MDR-plus-huddle QI programs show real gains (Virtual MDR 2023: 3,813 excess days cut, ~$6.7M, sustained >1 yr).

**Discharge lounges** plausibly free morning bed-hours (a staffed space where discharge-ready patients wait, vacating the inpatient bed earlier) but the peer-reviewed base is thin and **utilization is the binding constraint** (Maguire 2020: 18% → 36% utilization with PDSA cycles; Franklin 2020 found only 3 implementation studies).

**Milestone/checklist approaches:** Project BOOST's **8 P's** readmission-risk screen, NHS SAFER milestones, Red/Green, and **conditional/criteria-led orders** (discharge once objective criteria are met, no fresh physician order needed).

**The "discharge by noon" (DBN) debate.** *Pro:* Wertheimer (*J Hosp Med* 2014) — a DBN bundle raised DBN 11% → 38%, dropped O:E LOS 1.06 → 0.96, and (2015) smoothed admission timing. *Con:* Rajkomar (*J Hosp Med* 2016, 38,365 stays) — DBN associated with **longer** LOS for *medical* patients (overnight-holding to qualify next morning); Kirubarajan (*J Hosp Med* 2021, 189,781 admissions) — **no association** with LOS, ED LOS, readmission, or mortality; Dunn & Lu (2024, "Things We Do for No Reason") — DBN alone doesn't improve crowding/LOS, can create a **perverse incentive** to keep patients longer, and is gameable. **EDD accuracy is poor early** (only 22.7% accurate within 24h; Anderson 2023). **Weekend effect** is real, but Thursday/Friday front-loading can substitute for costly 7-day coverage (Cureus 2025).

**Highest-certainty discharge evidence (Cochrane RCTs):** structured **discharge planning** yields a *small* benefit — **LOS −0.73 days** (95% CI −1.33 to −0.12) and **readmission RR 0.89**, with **no mortality difference** (Gonçalves-Bradley 2022). Admission-avoidance hospital-at-home reduces institutionalization (RR 0.53).

### Design implications for Zephyrus

- **Target the long-stay tail, not the mean:** auto-flag the small cohort of stranded/super-stranded patients consuming most bed-days, with coded delay reasons.
- **Build a structured discharge-barrier tracker** with the four-category taxonomy (medical / logistical / placement / social), since *categorizing* the barrier is what enables targeted resolution and improvement projects.
- **Treat DBN as a byproduct metric, never a quota.** If shown, **always pair it with LOS and readmission balancing measures** (Wertheimer's own design) and detect/flag overnight-holding gaming.
- **Prioritize proactive levers with real support:** EDD + criteria-led discharge + case management for high-risk/HF cohorts + Thursday/Friday weekend-prep, over a blunt morning-discharge target.
- **Instrument discharge-lounge utilization and turnaround** if a lounge module is built — utilization, not capacity, is the bottleneck.
- **Implement an 8 P's-style readmission-risk screen** and milestone/criteria-led discharge orders as structured, role-assignable workflow.

---

## 8. Evidence and outcomes — credibility-conscious summary

| Intervention | Best evidence | Effect / finding | Certainty |
|---|---|---|---|
| **Discharge planning** | Cochrane RCTs (33 trials, n=12,242) | LOS −0.73 d; readmission RR 0.89; no mortality benefit | **Moderate** (highest in field) |
| **ED throughput (triage liaison MD)** | RCT meta-analysis (Youssef 2024) | Time-to-disposition −28 min | Moderate |
| **Boarding → harm** | Singer 2011; Sun 2013 (large cohorts) | Mortality 2.5%→4.5%; +5% death odds on crowded days | Strong *association* (observational) |
| **Diversion → harm** | Shen & Hsia 2011/2015 | ≥12h diversion → +higher AMI mortality | Strong *association* (observational) |
| **Surgical smoothing** | Single-site before-after (Mayo FL, CCHMC, BMC) | Volume +4%, overtime −27%, cancellations −99.5%, capacity created | Promising, low-certainty (no RCT) |
| **RTDC** | Single-site before-after (Resar 2011) | PACU holds eliminated, ED LOS <4h, LWBS <0.5% | Promising, low-certainty |
| **SAFER / Red2Green** | Pre-post (Paiva 2024, Brazil) | LOS 19→14.2 d, safety neutral | Low-certainty (uncontrolled) |
| **AI command centers** | Controlled ITS + NIHR control-site study (Bradford) | **No significant LOS impact; no conclusive benefit** | Best designs are **null** |

**Honest cross-cutting caveats:** (1) outside discharge-planning Cochrane RCTs and a few throughput RCTs, intervention evidence is overwhelmingly **uncontrolled before-after / retrospective**, vulnerable to secular trends and **regression to the mean** (programs launched at a crowding peak "improve" anyway); (2) the most rigorous command-center studies are **null**, while the dramatic figures are institution/vendor-reported; (3) there is **no standardized definition** of crowding/boarding, defeating meta-analysis; (4) effect sizes are **modest even when real**; (5) the **causes–solutions mismatch** (Morley 2018) — the dominant driver is output block / inpatient capacity, yet most interventions target ED throughput.

### Design implications for Zephyrus

- **Build measurement-with-controls into the product.** Ship before/after + comparator-unit analysis and SPC run charts so customers can credibly attribute (or disprove) impact — the field's central weakness is the opportunity.
- **Lead with mechanism and prediction quality**, present intervention effects as promising-but-uncertain, and *separate* peer-reviewed effect sizes from vendor claims in any reporting Zephyrus generates.
- **Optimize the binding constraint (output block / discharge), not just the ED** — align the product's center of gravity with where the evidence says the problem actually is.
- **Adopt the modest, proven wins first:** structured discharge planning, case management for high-risk cohorts, EDD/CCD discipline — these have the only moderate-certainty evidence and should be the baseline Zephyrus guarantees.

---

## Sources

**1. IHI RTDC**
- IHI White Paper, *Achieving Hospital-wide Patient Flow* (2nd ed., 2020): https://www.ihi.org/sites/default/files/IHIAchievingHospitalWidePatientFlowWhitePaper.pdf
- Resar, Nolan, Kaczynski, Jensen. *Jt Comm J Qual Patient Saf* 2011;37(5):217–227 (PMID 21618898): https://pubmed.ncbi.nlm.nih.gov/21618898/ · DOI https://doi.org/10.1016/S1553-7250(11)37029-8
- Barnes et al., real-time LOS prediction for discharge prioritization, *JAMIA* 2016 (PMID 26253131): https://pmc.ncbi.nlm.nih.gov/articles/PMC4954620/
- IHI RTDC operational decks (browser-only): http://app.ihi.org/Events/Attachments/Event-2539/Document-3893/RTDC_Paper.pdf · https://app.ihi.org/Events/Attachments/Event-2539/Document-3842/Building_a_System_for_RTDC.pdf
- Radboudumc RTDC overview: https://www.radboudumc.nl/en/about-radboudumc/organization/adviesgroep-pvi/real-time-demand-and-capacity-management-rtdc/what-is-rtdc

**2. NHS bundles**
- SAFER Patient Flow Bundle (ECIP Rapid Improvement Guide): http://www.catmalvern.co.uk/nhs/References/the-safer-patient-flow-bundle.pdf · https://fabnhsstuff.net/fab-stuff/the-safer-patient-flow-bundle
- Red and Green Bed Days (ECIP RIG): https://www.england.nhs.uk/south/wp-content/uploads/sites/6/2016/12/rig-red-green-bed-days.pdf
- Reviewing 'stranded' patients (ECIP RIG): https://www.england.nhs.uk/south/wp-content/uploads/sites/6/2016/12/rig-reviewing-stranded-patients-hospital.pdf
- Discharge to Assess / "Home First" grab guide: https://www.england.nhs.uk/wp-content/uploads/2018/12/3-grab-guide-getting-people-home-first-v2.pdf
- Criteria-Led Discharge guidance (B0928): https://www.england.nhs.uk/wp-content/uploads/2021/10/B0928-criteria-led-discharge-guidance-v2.pdf
- 100-Day Discharge Challenge — ten initiatives (mirror): https://www.foureyesinsight.com/articles/nhs-england-launches-its-100-day-discharge-challenge/
- UHL outcomes (NHS Atlas): https://www.england.nhs.uk/atlas_case_study/reducing-hidden-waits-and-improving-patient-flow/
- SAFER+Red2Green pre-post study, *BMJ Open Quality* 2024 (PMID 38191217): https://pmc.ncbi.nlm.nih.gov/articles/PMC10806560/

**3. Command centers**
- Johns Hopkins 5-year retrospective: https://www.hopkinsmedicine.org/news/articles/2021/03/capacity-command-center-celebrates-5-years-of-improving-patient-safety-access
- Advisory Board (early Hopkins figures): https://www.advisory.com/daily-briefing/2018/06/11/command-center
- Use of Systems Engineering to Design a Hospital Command Center (tiles): https://www.researchgate.net/publication/330334261_Use_of_Systems_Engineering_to_Design_a_Hospital_Command_Center
- GE HealthCare Command Center product: https://www.gehealthcare.com/products/software/commandcenter
- Tampa General CareComm ($40M): https://investor.gehealthcare.com/news-releases/news-release-details/tampa-general-hospital-and-ge-healthcares-carecomm-saves-40
- University of Michigan M2C2, NEJM Catalyst: https://catalyst.nejm.org/doi/10.1056/CAT.25.0080
- Mebrahtu et al. (Bradford ITS), *Int J Qual Health Care* 2023 (PMID 37750687): https://pmc.ncbi.nlm.nih.gov/articles/PMC10566538/
- Johnson et al. (NIHR control-site study) 2024 (PMID 39523572): https://doi.org/10.3310/TATM3277
- Franklin et al., command-center scoping review, *J Patient Saf* 2022 (PMID 35435429): https://journals.lww.com/journalpatientsafety/abstract/2022/09000/use_of_hospital_capacity_command_centers_to.22.aspx

**4. Variability methodology (Litvak / IHO)**
- IHO — Reducing Artificial Variability: https://ihoptimize.org/why-iho/our-approach/artificial-variability/
- IHO — surgical-flow client success stories: https://ihoptimize.org/who-we-work-with/client-success-stories/surgery/
- McManus et al., *Anesthesiology* 2003 (PMID 12766663): https://pubmed.ncbi.nlm.nih.gov/12766663/
- McManus et al., queueing theory models ICU need, *Anesthesiology* 2004 (PMID 15114227): https://pubmed.ncbi.nlm.nih.gov/15114227/
- Ryckman et al., ICU variability management, *Jt Comm J* 2009 (PMID 19947329): https://doi.org/10.1016/s1553-7250(09)35073-4
- Smith et al., OR variability methodology, *J Am Coll Surg* 2013 (PMID 23521932): https://doi.org/10.1016/j.jamcollsurg.2012.12.046
- Litvak & Bisognano, *Health Affairs* 2011 (PMID 21209441): https://pubmed.ncbi.nlm.nih.gov/21209441/
- BMC case study (IHO): https://ihoptimize.org/wp-content/uploads/2025/03/BMC-case-study.pdf

**5. Queueing theory foundations**
- Linda Green, "Queueing Theory and Modeling" (Columbia): https://business.columbia.edu/sites/default/files-efs/pubfiles/5474/queueing%20theory%20and%20modeling.pdf
- Bagust, Place & Posnett, *BMJ* 1999 (PMID 10406748): https://pubmed.ncbi.nlm.nih.gov/10406748/ · DOI https://doi.org/10.1136/bmj.319.7203.155
- Proudlove, "The 85% bed occupancy fallacy," 2019 (PMID 31462072): https://pubmed.ncbi.nlm.nih.gov/31462072/
- de Bruin et al., dimensioning wards with Erlang loss, *Ann Oper Res* 2010: https://link.springer.com/article/10.1007/s10479-009-0647-8
- Ryu et al., 12-hour census prediction, *JAMIA* 2021: https://pmc.ncbi.nlm.nih.gov/articles/PMC8344501/
- Harrison, Shafer & Mackay, modelling bed occupancy, 2005 (PMID 16379415): https://pubmed.ncbi.nlm.nih.gov/16379415/
- Erlang (unit) reference: https://en.wikipedia.org/wiki/Erlang_(unit)

**6. Boarding / diversion / throughput**
- ACEP boarding/crowding hub: https://www.acep.org/administration/crowding--boarding
- Singer et al., boarding & mortality, *Acad Emerg Med* 2011 (PMID 22168198): https://pubmed.ncbi.nlm.nih.gov/22168198/
- Sun et al., crowding & mortality, *Ann Emerg Med* 2013: https://doi.org/10.1016/j.annemergmed.2012.10.026
- Shen & Hsia, diversion & AMI mortality, *JAMA* 2011: https://pmc.ncbi.nlm.nih.gov/articles/PMC4109302/
- Shen & Hsia, diversion, *Health Affairs* 2015: https://pmc.ncbi.nlm.nih.gov/articles/PMC4591852/
- Canellas et al., cost of boarding (TDABC), *Ann Emerg Med* 2024 (PMID 38795079): https://pubmed.ncbi.nlm.nih.gov/38795079/
- ED-2 / CMS111 spec (boarding measure): https://ecqi.healthit.gov/sites/default/files/ecqm/measures/CMS111v6.html
- ECAT eCQM (2026 OPPS) detail: https://www.d2ihc.com/ecat-ecqm-ed-metrics-opps-2026/
- Occupancy↔boarding (85% threshold): https://pmc.ncbi.nlm.nih.gov/articles/PMC9526134/

**7. Discharge optimization**
- Gonçalves-Bradley et al., Cochrane discharge planning 2022: https://doi.org/10.1002/14651858.CD000313.pub6
- Wertheimer et al., DBN achievable goal, *J Hosp Med* 2014: https://doi.org/10.1002/jhm.2154
- Rajkomar et al., DBN & LOS, *J Hosp Med* 2016 (PMID 26717556): https://pubmed.ncbi.nlm.nih.gov/26717556/
- Kirubarajan et al., morning discharges & LOS, *J Hosp Med* 2021 (PMID 34129483): https://pubmed.ncbi.nlm.nih.gov/34129483/
- Dunn & Lu, "Things We Do for No Reason: Discharge before noon," 2024: https://pmc.ncbi.nlm.nih.gov/articles/PMC11613578/
- Pannick et al., interdisciplinary care, *JAMA Intern Med* 2015 (PMID 26076428): https://pubmed.ncbi.nlm.nih.gov/26076428/
- Siddique et al., 8 LOS strategies, *JAMA Netw Open* 2021: https://pmc.ncbi.nlm.nih.gov/articles/PMC8453321/
- Franklin et al., discharge lounge synthesis, *Ann Emerg Med* 2020: https://doi.org/10.1016/j.annemergmed.2019.12.002
- Project BOOST 8 P's (AHRQ PSNet): https://psnet.ahrq.gov/innovation/project-boost-increases-patient-understanding-treatment-and-follow-care
- Delayed-discharge scoping review: https://pmc.ncbi.nlm.nih.gov/articles/PMC9278600/

**8. Evidence & outcomes**
- Morley et al., ED crowding causes/solutions mismatch, *PLoS One* 2018: https://doi.org/10.1371/journal.pone.0203316
- Clay-Williams et al., system-wide interventions review, *BMC Health Serv Res* 2014: https://doi.org/10.1186/s12913-014-0369-7
- Youssef et al., ED crowding RCT meta-analysis, *Acad Emerg Med* 2024: https://doi.org/10.1111/acem.14946
- Oredsson et al., GRADE-rated ED-flow review, 2011: https://doi.org/10.1186/1757-7241-19-43
- Edgar et al., admission-avoidance hospital-at-home, Cochrane 2024: https://doi.org/10.1002/14651858.CD007491.pub3
