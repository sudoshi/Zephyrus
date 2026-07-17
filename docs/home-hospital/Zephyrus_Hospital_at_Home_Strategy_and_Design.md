# Extending Zephyrus to Hospital at Home

**Acute Care at Home, Remote Patient Monitoring & Transitions of Care — Strategy and Module Design**

`ACUM-PRD-HAH-001` · Sanjay M. Udoshi, MD — Founder & Chief Medical Information Officer, Acumenus, Inc. · July 17, 2026
<br>**CONFIDENTIAL** · © 2026 Acumenus, Inc. All rights reserved.

> _Faithful Markdown conversion of `Zephyrus_Hospital_at_Home_Strategy_and_Design.docx`. This is the strategy source-of-truth behind the engineering brief in [`HOME-HOSPITAL-BUILD-PROMPT.md`](./HOME-HOSPITAL-BUILD-PROMPT.md). Tables and callout boxes were reconstructed from the DOCX; wording is unchanged._

---

## Contents

1. Executive Summary
2. The Strategic Opportunity: Hospital at Home in 2026
3. Why Zephyrus Is Uniquely Positioned
4. Module Design: The Home Hospital Workspace
5. Data and Integration Architecture
6. Intelligence Layer
7. Transitions of Care
8. Compliance, Safety, and Privacy
9. Implementation Roadmap
10. Business Case and Positioning
11. Risks and Open Questions
12. References

# 1. Executive Summary

Hospital at Home (HaH) — delivering acute, hospital-level care in a patient’s residence — has crossed the line from pilot to durable service line. In February 2026 Congress extended the CMS Acute Hospital Care at Home (AHCAH) waiver for roughly five years, through September 30, 2030, giving health systems the payment certainty they need to invest in permanent home-hospital operations (AMA 2026; 42 U.S.C. §1395cc-7). At the same time, CMS relaxed the billing floors on remote monitoring for calendar year 2026, making shorter, lower-acuity monitoring reimbursable for the first time (CY2026 PFS Final Rule). The clinical evidence is now strong enough to defend the model in a Monday review: randomized and national claims-based studies show comparable or lower mortality, fewer ICU escalations, and lower total cost of care versus traditional inpatient admission (Levine et al. 2020; Levine et al. 2024; Vakkalanka et al. 2026).

This document proposes a **Home Hospital** module for Zephyrus. The thesis is simple and specific to our platform: every competitor orchestrates the home care itself, but none unify it with live house-wide demand and capacity. Zephyrus already computes one server-side operations snapshot across the Emergency Department, Real-Time Demand & Capacity (RTDC), Perioperative, and Process Improvement. A virtual ward is, structurally, one more unit whose beds are program slots — so census, huddles, cockpit tiles, alerting, and the governed Eddy action loop apply to home patients with minimal new machinery. The differentiated product is not a monitoring app; it is the only command center that can say, in one glance, “we have 14 ED boarders, 9 of them home-eligible, 6 home slots free tonight — enroll these and boarding hours fall.”

> **The opportunity in one line**
>
> A five-year waiver extension turns Hospital at Home into a fundable service line; Zephyrus is uniquely positioned to run it as a **capacity-decompression instrument**, not just another care-at-home tool.

The recommendation is a four-phase build that reuses Zephyrus’s integration control plane for device and FHIR ingestion, adds a compact set of home-specific data tables alongside (not inside) the existing census spine, and surfaces a virtual-ward command surface, an eligibility-and-referral funnel, a field-logistics board, and a transitions-of-care board. It pulls the BUSINESS_PLAN’s Year-3 “telehealth operations” item forward into the current window while the waiver tailwind is strongest, and positions a new à-la-carte module in the $85–95K range between the ED and Perioperative tiers.

# 2. The Strategic Opportunity: Hospital at Home in 2026

## 2.1 What Hospital at Home is

Hospital at Home substitutes home-based care for a traditional inpatient admission for a defined set of acute conditions. Under the CMS AHCAH waiver the patient is formally an inpatient — admitted from an Emergency Department or an inpatient bed — but receives their care at home. The waiver sets a firm operating floor: a **daily physician evaluation** (which may be virtual), a **minimum of two in-person clinician visits per day** by a registered nurse or community paramedic, and the ability to deliver **in-person emergency clinical services at the home within 30 minutes** (MedPAC 2024). Around that floor, programs wrap remote patient monitoring (RPM), tele-visits, and home delivery of medications, labs, imaging, durable medical equipment, and often meals.

The condition mix is well characterized. In the first national claims analysis of the waiver, the most common diagnoses were heart failure, respiratory infection (including COVID-19), sepsis, kidney/urinary-tract infection, and cellulitis; the population is medically complex (mean HCC score 3.15, case-mix index 1.31; 42.5% with heart failure, 43.3% with COPD) (Levine et al. 2024). A HaH module must be built for exactly this comorbid, escalation-prone population — not the worried well.

## 2.2 Regulatory and payment status

### 2.2.1 The AHCAH waiver — extended through 2030

The AHCAH waiver flexibilities, first created in November 2020, were extended by the **Consolidated Appropriations Act, 2026** (Public Law 119-75), signed in early February 2026, which amended Section 1866G of the Social Security Act (42 U.S.C. §1395cc-7) to move the expiration from January 30, 2026 to **September 30, 2030** (AMA 2026; U.S. Code). The standalone vehicle — H.R. 4313, the Hospital Inpatient Services Modernization Act, which passed the House on December 1, 2025 — carried the same date and its study requirements were folded into the enacted package (Congress.gov 2025).

> **New program requirement to design for**
>
> The extension directs HHS to deliver a study and report by **September 30, 2028** comparing home versus brick-and-mortar inpatient care on readmissions, mortality, infections, staffing, escalations/transfers, patient experience, conditions/DRGs, cost, and equity — explicitly addressing selection bias (Congress.gov 2025). A Zephyrus module that captures these variables as first-class operational data turns a compliance burden into a reporting asset.

CMS is also opening the data: a second public AHCAH data release began March 17, 2026 (covering April 2023–September 2025), giving the field nearly five years of program data to benchmark against (CMS 2026).

### 2.2.2 Remote monitoring reimbursement — the 2026 changes

The CY2026 Medicare Physician Fee Schedule final rule (published in the Federal Register on November 5, 2025, effective January 1, 2026) materially widened remote-monitoring payment. It retained the legacy RPM codes (99453, 99454, 99457, 99458, 99091) and RTM codes (98975–98981), and **added shorter-duration codes**: new RPM **99445** for device supply covering just 2–15 days of data in a 30-day period (paid at parity with 99454), and **99470** for 10–19 minutes of monitoring management per month; parallel new RTM codes (98979, 98984, 98985) mirror the change (Nixon Law Group 2026; Federal Register 2025). The prior 16-day and 20-minute billing floors no longer gate all reimbursement, so lower-acuity and shorter home-monitoring episodes — exactly the post-discharge and chronic cohorts described later in this document — become billable.

> **Payment caveat — Medicaid and commercial vary**
>
> Only about half of state Medicaid programs reimburse RPM, most with restrictions (CCHP 2026), and commercial coverage is uneven. The module’s enrollment and eligibility logic must be **payer-aware** from day one, not bolted on later.

## 2.3 The clinical evidence base

The evidence is what lets a CMO defend the program. Three anchors matter:

- **The founding RCT.** In the first U.S. randomized trial of substitutive hospital-level care at home (Brigham and Women’s / Faulkner), the adjusted acute-episode cost was **38% lower** than inpatient care, with a **30-day readmission rate of 7% versus 23%**, and markedly lower utilization of labs, imaging, and consults (Levine et al. 2020).
- **National claims outcomes (the KPI benchmarks).** Across 5,132 Medicare fee-for-service patients in the waiver’s first year, mean length of stay was **6.3 days**, escalation back to the hospital was **6.2%**, in-episode mortality **0.5%**, and 30-day readmission **15.6%** (Levine et al. 2024). These are the numbers a virtual-ward dashboard should track against.
- **Large propensity-matched safety data.** In a 15,871-patient matched study, HaH was associated with lower in-hospital mortality (**0.4% vs 3.6%**), fewer ICU escalations (**3.5% vs 7.9%**), and modestly lower total 30-day cost, with 30-day readmissions statistically unchanged (Vakkalanka et al. 2026).

A 2026 meta-analysis of 11 randomized trials in older adults reinforces the direction of travel — shorter stays, lower readmission risk, no mortality penalty, and lower cost — while cautioning that heterogeneity is high, so programs should trust their own instrumented outcomes over pooled averages (He et al. 2026). That caution is itself an argument for Zephyrus: a program that measures its own escalation and readmission rates in real time is defensible in a way that one relying on literature is not.

## 2.4 Operating models and the competitive landscape

Leading programs share a common shape: a 24/7 virtual command center with physician and nurse coverage, in-home visits by nurses or mobile-integrated-health paramedics at least twice daily, a nurse practitioner every other day in some models, and a technology kit for continuous or intermittent vitals. Kaiser Permanente’s Advanced Care at Home (built with Medically Home) grew its average daily census from roughly 7 to 13 patients as it matured; Mount Sinai at Home — one of the earliest programs, seeded by a 2014 CMS Innovation grant — runs about 30 admissions per month and has planned to scale toward 50–60, coordinated from a 24/7 virtual command center with a telemedicine kit that includes a remote stethoscope (Kaiser/AJMC; Mount Sinai 2023).

The vendor market, by contrast, is consolidating and unsettled — which is the opening. DispatchHealth and Medically Home merged in mid-2025 into a single national platform; Best Buy divested Current Health barely four years after paying nearly $400M for it; and CoPilotIQ and Biofourmis combined in late 2024 (DispatchHealth 2025; Healthcare Dive 2025; HCI 2024). Every one of these players orchestrates the home care itself. **None of them unifies home-hospital operations with the hospital’s live inpatient demand and capacity, its OR and ED pressure, its staffing, or its process-mining and governance layer.** That whitespace is precisely where an operations-intelligence platform wins.

# 3. Why Zephyrus Is Uniquely Positioned

## 3.1 The assets the module inherits

Zephyrus 2.0 was built around exactly the primitives a home-hospital program needs. The module reuses them rather than rebuilding:

- **A census spine.** prod.encounters, prod.units, prod.beds, and prod.census_snapshots drive house-wide RTDC, with event-sourced occupancy (prod.operational_events projected by CensusProjector). A virtual ward is one more unit whose beds are program slots.
- **One server-computed snapshot.** SnapshotBuilder composes nine domain metric providers; each emits values resolved by a single StatusEngine against admin-editable band edges in ops.metric_definitions. Alerts derive from templates, are flap-damped by AlertEngine, and feed Eddy’s approval-gated Action Inbox. A new domain provider inherits all of this.
- **An integration control plane.** integration.sources → raw.inbound_messages → integration.canonical_events → ProjectionHandler → prod.*, with per-feed freshness SLAs, dead-letter/replay, FHIR R4 client scaffolding, and a working HL7v2 ingest exemplar. Device and FHIR observation feeds ride these same rails.
- **A transitions substrate.** prod.transport_requests.request_type already includes care_transition and discharge; the regional schema models external facilities, capabilities, and transfer decisions with opportunity-cost scoring; prod.discharge_facts is a separate outcomes/billing ledger.
- **Ambient telemetry hooks and governed intelligence.** flow_realtime.ambient_signal_* anticipates sensor/wearable feeds; Eddy provides a draft-only, human-approved action catalog; Arena projects operations into an OCEL 2.0 log for conformance checking; and a deterministic Predictions layer already forecasts census and demand.
- **A disciplined design system.** The ISA-101 “earned urgency” canon — status never by color alone, coral reserved for real breaches — is exactly the discipline clinical-grade home monitoring needs to avoid alarm fatigue.

> **Gap analysis**
>
> There is **no existing HaH, RPM, or telehealth surface** in Zephyrus today. The BUSINESS_PLAN parks “telehealth operations” in Year 3 (2028); the June-2025 market-leapfrog plan already specifies a “Post-Acute and Care Transition Agent” and names wearable and post-acute data as territory to own. This module makes that intent concrete and pulls it forward.

## 3.2 The differentiated thesis: capacity unification

The home program should be presented not as a parallel service but as a **decompression valve on house-wide capacity**. Because Zephyrus already holds the live ED boarding count, floor census against staffed capacity, and expected discharges, it can do what no HaH vendor can: quantify, in real time, how many current ED and inpatient patients are home-eligible, how many home slots are free tonight and projected tomorrow, and what enrolling them would do to boarding hours and occupancy. Every active home episode is an avoided occupied bed-day, attributable against the very boarding pressure the cockpit already displays. That is the defensible, one-instrument story.

# 4. Module Design: The Home Hospital Workspace

## 4.1 Concept and placement

The module adds a nav domain — working name **Home Hospital** (HOME) — as a new Workspace in the Altitude model (Cockpit → Workspaces → Study), behind a feature flag (features.home_hospital) and an EnsureHomeHospitalEnabled route middleware, mirroring the virtual-rounds gating pattern already in the codebase. It treats home-based acute care as one more altitude of the same instrument: the virtual ward appears in the cockpit next to ED and RTDC, its patients are monitored with the same earned-urgency discipline, and its processes are mined by Arena and coached by Eddy.

Three capability rings ship progressively: (1) **acute Hospital-at-Home** at waiver grade — virtual-ward census and command, RPM observability, twice-daily visit logistics, escalation with response-time telemetry, and CMS reporting; (2) **extended observability** — post-discharge 30-day monitoring cohorts, ED-diversion/observation-at-home, and chronic RPM lines (heart failure, COPD) on the same pipes at lower acuity; and (3) **transitions of care** — admission pathways in and governed handoffs out.

## 4.2 Screens and surfaces

Six surfaces make up the workspace; each reuses an existing Zephyrus component pattern.

#### Virtual Ward Command · /home/command

The flagship surface: a grid of episode tiles, each showing pseudonymous identity, condition and program, day-of-stay versus expected length of stay, live vitals sparklines, a Home Early Warning Score chip, open alerts, the next required visit with a countdown, and device/connectivity status. Grey is the resting baseline; coral is reserved for true breaches — an unacknowledged critical vital, a blown response SLA, or a missed waiver-required visit. Tiles drill in place to the patient lens.

#### Virtual Bed Board · /home/census

An RTDC-pattern board of program slots (occupied / available / pending-setup / blocked), an enrollment pipeline column, and projected discharges at 24 and 48 hours. Because the program is modeled as a unit, this board and the house-wide huddle share one census engine.

#### Eligibility & Referral Funnel · /home/referrals

Two live worklists: ED candidates screened over the live ED census (qualifying conditions, service-zone address, payer class, clinical stability) and inpatient step-down candidates at or near expected LOS with home-eligible profiles. The funnel tracks referred → screened → eligible → consented → activated / declined, with decline reasons — important because real programs convert only a minority of consults (one program enrolled roughly 22% of consults, with a similar share declining out of preference for the hospital) (HaH Users Group).

#### Field Operations & Logistics · /home/logistics

Visit scheduling with a two-visits-per-day compliance rail, a route/assignment view for field nurses and community paramedics (reusing Transport dispatch patterns), kit inventory and lifecycle, and delivery tracking for medications, meals, DME, and labs.

#### Transitions of Care Board · /home/transitions

Inbound activation checklists (consent, home-safety check, kit delivery, first visit) and outbound handoffs — discharge readiness, handoff owner, receiving entity (PCP, home health, or SNF via regional.facilities), barrier tracking, and a 30-day post-discharge monitoring cohort with a step-down cadence.

#### Program Analytics · /home/analytics (Study)

Outcomes against matched inpatient comparators (LOS, escalation, mortality, 30-day readmission), program economics and avoided bed-days, funnel conversion, alert burden per patient-day, visit on-time compliance, and RPM adherence.

## 4.3 Cockpit integration

A new HomeMetrics provider (extending BaseMetrics) contributes a home domain to the single snapshot, with thresholds seeded into ops.metric_definitions so they remain admin-editable and audited. Alert templates are the “earned red” ration — only the metrics below carry them, so the cockpit never fires per-vital noise.

| **Metric key** | **Cockpit tile** | **Alerting** |
| --- | --- | --- |
| home.census_occupancy | Virtual ward occupancy vs. slots | — |
| home.unacked_critical_vitals | Unacked critical vitals (count · max age) | Critical → Eddy |
| home.escalation_response_p90 | Escalation response p90 (minutes) | Warn / Crit |
| home.visit_compliance_today | Waiver visit compliance % | Critical |
| home.device_offline_pct | Kits offline / transmission gaps | Warn |
| home.rpm_adherence | Patient monitoring adherence % | Watch |
| home.escalation_rate_7d | Escalations per 100 episode-days | Watch |
| home.referral_conversion_7d | Referral → enrollment conversion % | — |
| home.avoided_bed_days_mtd | Avoided bed-days MTD (executive) | — |

A ?drill=home modal exposes the census strip, alert list, funnel snapshot, and response-time trend; wall-display mode inherits automatically. Crucially, the RTDC global huddle gains a “home decant” line — home-eligible counts and free slots surfaced next to boarding metrics — operationalizing the capacity-unification thesis.

# 5. Data and Integration Architecture

## 5.1 Schema

New tables live in the prod schema and follow the established conventions exactly: a {table}_id primary key, a _uuid public/idempotency key, pseudonymous patient_ref (never an MRN), a jsonb metadata column, an is_deleted soft-delete flag, CHECK constraints via raw statements, and the SafeMigration trait. The RPM observation ledger is kept **separate** from prod.encounters, mirroring how prod.discharge_facts stays a distinct ledger.

| **Table** | **Purpose** |
| --- | --- |
| prod.home_programs | Program lines (AHCAH acute, observation-at-home, post-discharge RPM, chronic RPM, SNF-at-home) with slot capacity by service zone |
| prod.home_referrals | Funnel spine: source, status, decline reason, screening JSON (zone, payer, home-safety, connectivity) |
| prod.home_episodes | Episode spine linked to an encounter on the virtual unit: program, admission source, condition + DRG, acuity tier, target vs. actual LOS, disposition |
| prod.rpm_kits / rpm_devices | Kit and device inventory, lifecycle, battery and connectivity telemetry |
| prod.rpm_enrollments | Kit-to-episode assignment + per-patient monitoring plan (per-vital cadence, personalized thresholds, baseline window) |
| prod.rpm_observations | High-volume vitals ledger, monthly range-partitioned, LOINC-coded, with device and transmission provenance and a quality flag |
| prod.rpm_alerts | Patient-level clinical alerts (rule, severity, opened/acked/resolved, escalation link) |
| prod.home_visits | Scheduled/completed visits (RN, paramedic, MD/NP tele, labs, delivery), waiver-required flag, on-time telemetry |
| prod.home_escalations | Trigger, response mode, full timing chain (initiated → dispatched → arrived → resolved), outcome (managed at home / ED return / readmit) |
| prod.home_transitions | Inbound activation and outbound handoff milestones, receiving entity FK to regional.facilities, readiness checklist |

The virtual ward itself is modeled by seeding one or more prod.units rows with a virtual_home type and prod.beds rows as slots — so census, occupancy, huddles, and the entire cockpit machinery work unmodified. New operational event types (HomeReferralCreated, HomeEpisodeActivated, RpmObservationBreached, HomeEscalationOpened/Resolved, HomeVisitCompleted, HomeEpisodeDischarged, TransitionHandoffCompleted) extend the canonical event vocabulary and project into ocel.* via a new emission-map branch so Arena sees the home pathway.

## 5.2 Device and FHIR ingestion

RPM vendor feeds are new HealthcareConnector implementations (webhook and FHIR-poll styles) that land in raw.inbound_messages (idempotency key = transmission id), normalize to integration.canonical_events (ObservationRecorded, DeviceStatusChanged), and project via a new RpmProjectionHandler into prod.rpm_observations and device state. Observations are stored as HL7 **FHIR R4** Observation/Device/ServiceRequest resources in fhir.resource_versions with resource_links to internal rows, using **US Core** profiles, **IEEE 11073 PHD** semantics via vendor gateways, and **LOINC** vital-sign codes (heart rate 8867-4, SpO₂ 59408-5, systolic 8480-6 / diastolic 8462-4, respiratory rate 9279-1, body temperature 8310-5, weight 29463-7). The existing HL7v2 ADT ingest closes the escalation loop automatically: when an escalated home patient is registered in the ED, the open escalation resolves with an ed_return outcome. Per-feed freshness SLAs live at the source level; per-patient transmission-gap detection is layered on top.

> **Interoperability posture**
>
> Because ingestion rides the existing control plane, the module is **EHR-agnostic** — a hedge against Epic’s and Oracle Health’s embedded care-at-home offerings and against RPM-vendor lock-in. The connector abstraction is the strategic moat, not any single device kit.

## 5.3 Realtime and PHI handling

Consistent with Zephyrus’s PHI-free-wire rule, only aggregate pings travel on public Reverb channels (home.census, cockpit ping); patient-level vitals are fetched over authenticated APIs with TanStack cache invalidation on ping, exactly like the cockpit stream. If per-patient push is ever required, it must move to a PrivateChannel with an auth callback — a deliberate, documented contract change, not an incidental one.

# 6. Intelligence Layer

## 6.1 Home Early Warning Score and escalation risk

A Home Early Warning Score (HEWS) is computed deterministically first — matching the existing Predictions philosophy of transparent SQL/PHP over opaque ML. It blends a modified NEWS2 with patient-specific baselines calibrated over the first 24 hours, vital-sign trend slopes, and a monitoring-adherence signal; bands live in metric-definition-style config with an ML upgrade path via a sidecar later. A daily per-episode escalation-risk tier (condition, day-of-stay, HEWS trajectory, adherence, visit findings) drives visit intensity and the command-grid sort order.

> **Safety positioning — this is operational triage, not a medical device**
>
> HEWS is decision **support** for operations, not diagnosis. The module must ship with clear labeling and stay inside FDA clinical-decision-support boundaries; escalation authority always rests with the clinical team, exactly as Eddy actions always rest with a human approver.

## 6.2 Capacity forecasting and avoided bed-days

An enrollment-pipeline forecast (home-eligible ED census plus step-down candidates near expected LOS) and a per-episode discharge projection produce a free-slot forecast at 24/48 hours and 7 days, surfaced in the RTDC global huddle and written alongside physical capacity in prod.rtdc_predictions. Every active home episode-day rolls up as an avoided occupied bed-day into the ops materialized views and the executive brief — the ROI accounting that makes the program legible to a COO.

## 6.3 Eddy actions and Arena process mining

New draft-only entries join the Eddy action catalog — propose_hah_enrollment, propose_stepdown_cohort, propose_escalation_response, propose_visit_reschedule, flag_rpm_gap, propose_home_discharge, flag_transition_barrier — riding the existing Recommendation → Action → Approval governance so that the agent proposes and a human disposes. This is where the June-2025 plan’s Post-Acute and Care Transition Agent finally lands. Arena gains new object types (HomeEpisode, RpmKit, HomeVisit, Escalation) and conformance checks against the designed pathway (time-to-activation SLA, visit cadence, escalation-protocol adherence), and the 48-Hour Flow Review folds in the home program.

# 7. Transitions of Care

Transitions are where a home-hospital program creates — or destroys — value, and where Zephyrus’s existing substrate gives it an edge. Three admission pathways feed the ward: **ED diversion** (screen and enroll before an avoidable admission), **inpatient early-discharge / step-down** (decant an occupied bed once a patient is stable), and **direct or ambulatory admission**. The eligibility funnel in §4.2 instruments all three; the ED-diversion path is wired to the live ED census and the step-down path to encounters at or near expected LOS.

On the way out, the transitions board manages structured handoffs to the PCP, home health, or a skilled nursing facility, reusing prod.transport_requests with request_type = care_transition and the regional.facilities graph for destination selection with its opportunity-cost scoring. A 30-day post-discharge monitoring cohort — now billable under the relaxed 2026 RPM codes — extends observation past the acute episode with a step-down cadence, directly targeting the readmission measures on which both the program and the hospital are judged.

The KPIs that run a virtual ward are drawn straight from the evidence base, so the program measures itself against national benchmarks rather than aspiration:

| **KPI** | **National benchmark** | **Source** |
| --- | --- | --- |
| Escalation (return-to-hospital) rate | ≈ 6.2% | Levine et al. 2024 |
| In-episode mortality | ≈ 0.5% | Levine et al. 2024 |
| 30-day readmission | ≈ 15.6% | Levine et al. 2024 |
| Mean length of stay | ≈ 6.3 days | Levine et al. 2024 |
| Emergency in-person response | within 30 minutes | CMS waiver / MedPAC 2024 |
| In-person visit cadence | ≥ 2 per day + daily MD | CMS waiver / MedPAC 2024 |

*Benchmark KPIs a Zephyrus virtual-ward dashboard should track against, with primary sources.*

# 8. Compliance, Safety, and Privacy

- **Waiver conditions as first-class telemetry.** Visit compliance, response times, and the CMS-required reporting are generated from the prod.home_* tables; the append-only user-audit ledger extends to home-episode access. Capturing the 2028 study variables (readmission, mortality, escalations, equity) as operational data turns the reporting mandate into a byproduct.
- **Pseudonymity preserved.** Operational and analytic paths use patient_ref and service zones, never street addresses; physical address is confined to a restricted logistics context. This matches the platform-wide convention and keeps PHI off public channels.
- **Clinical alarm governance.** Alert burden per patient-day is itself a tracked KPI; thresholds are personalized to each patient’s baseline; and clinical alerts are flap-damped exactly as AlertEngine damps operational tiles — the earned-urgency canon applied to patient safety.
- **Equity and selection-bias guardrails.** Connectivity screening (cellular-backhauled kits mitigate broadband gaps), language and caregiver requirements, and decline-reason analytics surface selection bias before it becomes a finding in the federal study.

# 9. Implementation Roadmap

The build is phased so that each phase is independently demonstrable and the highest-learning surfaces ship first. All phases reuse existing Zephyrus infrastructure; the estimates assume the module is developed against the synthetic Summit Regional demo hospital before any production integration.

| **Phase** | **Scope** | **Est.** |
| --- | --- | --- |
| **Phase 0** · Foundation | Schemas and migrations, virtual-unit seeding, feature flag and nav domain, a synthetic RPM connector and demo cohort in the demo seed, census board via RTDC reuse | 4–6 wks |
| **Phase 1** · Observability MVP | Vitals ingestion pipeline, HEWS and patient alerts with an acknowledgement workflow, Virtual Ward Command page, cockpit home tiles and ?drill=home, escalation workflow with response timers | 6–8 wks |
| **Phase 2** · Transitions | Referral funnel and eligibility worklists (ED + step-down), transitions board, care-transition and regional-facility handoffs, 30-day post-discharge cohort, logistics/visit board | 6–8 wks |
| **Phase 3** · Intelligence & compliance | Capacity/discharge forecasting into the RTDC huddle, Eddy catalog actions, OCEL projection and conformance, executive ROI tiles, CMS waiver reporting exports | 8+ wks |
| **Later** | Chronic RPM lines, SNF-at-home, multi-facility program operations, payer-facing reporting | — |

# 10. Business Case and Positioning

The market timing is the argument. The five-year waiver extension converts Hospital at Home from a reimbursement gamble into a fundable, durable service line, and hospitals are actively investing in the operational tooling to run it (AMA 2026; MedPAC 2024, which counted roughly 328 approved hospitals and about 23,000 discharges by April 2024). Zephyrus should meet that demand while the tailwind is strongest.

Commercially, the Home Hospital module fits cleanly into the existing à-la-carte structure — positioned in the **$85–95K per year** band between the ED ($65K) and Perioperative ($85K) modules, and included in the Enterprise Plus tier. This pulls the BUSINESS_PLAN’s Year-3 “telehealth operations” line into the current window. The differentiation against pure HaH vendors (Medically Home/DispatchHealth, Biofourmis, Inbound Health, Contessa) is not feature parity on home-care orchestration — it is the things only Zephyrus has: house-wide capacity unification (the decant valve), process mining and conformance (Arena), governed operations agents (Eddy), an EHR-agnostic control plane, and an anti-alarm-fatigue design system.

> **The one-instrument pitch**
>
> Sell Home Hospital as the module that makes the virtual ward a lever on the whole hospital’s throughput — the only command center that connects home capacity to ED boarding, floor occupancy, and discharge planning in a single, defensible view.

# 11. Risks and Open Questions

| **Risk** | **Mitigation** |
| --- | --- |
| Clinical-safety scope creep — positioning Zephyrus as a medical device | Frame HEWS as operational triage support, not diagnosis; ship clear labeling; keep escalation authority with clinicians (mirrors Eddy governance) |
| Policy risk beyond the 2030 window; commercial-payer dependence | Payer-aware eligibility from day one; design for the extended-observability and chronic-RPM rings that stand on RPM billing independent of the waiver |
| RPM vendor lock-in | The HealthcareConnector abstraction; support multiple kit vendors behind one projection handler |
| PHI expansion (continuous home vitals) | Monthly partitioning and retention/rollup policy on rpm_observations; audit-ledger extension; PHI kept off public channels |
| Integration burden vs. EHR-embedded competitors (Epic Care at Home) | Lean on the existing coexistence-adapter strategy in the control plane; compete on capacity intelligence, not EHR proximity |

Open questions for product and clinical leadership: which conditions to support first (the evidence points to heart failure, COPD, pneumonia/respiratory infection, cellulitis, and UTI); whether to staff field visits with employed nurses or contracted community paramedics; the target daily census and service radius for the pilot; and which single RPM vendor to integrate first for the Phase 1 MVP.

# 12. References

*Primary and named secondary sources. Regulatory and payment facts were re-verified against source after research; the AHCAH extension vehicle was confirmed as the Consolidated Appropriations Act, 2026 (H.R. 4313 being the standalone House vehicle).*

1. American Medical Association. “Lawmakers extend CMS hospital-at-home waiver for five years.” 2026. ama-assn.org/public-health/population-health/lawmakers-extend-cms-hospital-home-waiver-five-years
2. 42 U.S.C. §1395cc-7 — Extension of Acute Hospital Care at Home waiver flexibilities. U.S. Code (current text). uscode.house.gov
3. H.R. 4313, Hospital Inpatient Services Modernization Act, 119th Congress (2025). congress.gov/bill/119th-congress/house-bill/4313/text
4. CMS. “Acute Hospital Care at Home Data Release Fact Sheet.” 2026. cms.gov/newsroom/fact-sheets/acute-hospital-care-home-data-release-fact-sheet-0
5. CMS. CY2026 Medicare Physician Fee Schedule Final Rule (CMS-1832-F). Federal Register, Nov 5, 2025. federalregister.gov/documents/2025/11/05/2025-19787
6. Nixon Law Group. “CMS Finalizes 2026 Remote Monitoring Reimbursement Updates — What Changed for RPM and RTM.” 2026. nixonlawgroup.com
7. Center for Connected Health Policy (CCHP). “Remote Patient Monitoring.” 2026. cchpca.org/topic/remote-patient-monitoring/
8. Levine DM, et al. “Hospital-Level Care at Home for Acutely Ill Adults: A Randomized Controlled Trial.” Annals of Internal Medicine, 2020. doi:10.7326/M19-0600
9. Levine DM, et al. National outcomes of the CMS Acute Hospital Care at Home waiver (Medicare FFS claims). Annals of Internal Medicine, 2024. doi:10.7326/M23-2264
10. Vakkalanka JP, et al. Hospital at Home vs. traditional inpatient care, propensity-matched Medicare study. JAMA Network Open, 2026;9(5):e2610810. jamanetwork.com/journals/jamanetworkopen/fullarticle/2848612
11. He, et al. “Efficacy and Safety of Hospital-at-Home versus Traditional Inpatient Care” (systematic review/meta-analysis, 11 RCTs). Gerontology, 2026. doi:10.1159/000551394
12. MedPAC. June 2024 Report to Congress, Ch. 6: Medicare’s Acute Hospital Care at Home Program. medpac.gov
13. Kaiser Permanente Advanced Care at Home at scale. American Journal of Managed Care. ajmc.com/view/advanced-care-at-home-at-scale-in-an-integrated-health-care-system
14. Mount Sinai at Home. Department of Medicine 2023 specialty report. reports.mountsinai.org
15. Hospital at Home Users Group. Technical Assistance Center — Patient Eligibility, Referral and Intake. hahusersgroup.org
16. DispatchHealth. “DispatchHealth and Medically Home Merger Closes.” 2025. dispatchhealth.com/press-room
17. Healthcare Dive / Fierce Healthcare. “Best Buy sells Current Health.” 2025. healthcaredive.com; fiercehealthcare.com
18. Healthcare Innovation. “CoPilotIQ and Biofourmis merge.” 2024. hcinnovationgroup.com
