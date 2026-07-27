:::meta
title: An Object-Centric Event Log Architecture for Governed Modeling and Measurement of Inpatient Care Pathways: Design, Reference Implementation, and Empirical Evaluation
short_title: Object-Centric Event Logs for Care Pathways
authors: Sanjay M. Udoshi, MD
affiliations: Acumenus, Inc., Philadelphia, PA, USA
correspondence: Sanjay M. Udoshi, MD, Acumenus, Inc. (smudoshi@acumenus.net)
article_type: Research and Applications
keywords: process mining; object-centric event logs; clinical pathways; conformance checking; quality measurement; patient safety; electronic health records; data quality
word_count_note: Extended manuscript; structured abstract 300 words
date: July 24, 2026 · Version 2.0
:::

:::abstract
**Objective:** Inpatient care pathways involve many interacting entities — patients, encounters, orders, specimens, medications, operating-room cases, beds, transport jobs, and clinical teams — yet conventional event logs force every event into exactly one case, distorting the record before measurement begins. We designed, implemented, and empirically evaluated an architecture that measures individual inpatient care against a governed catalog of 250 diagnosis-related-group (DRG)–anchored care-pathway definitions over a single shared Object-Centric Event Log (OCEL 2.0) observation layer, with measurement claims constrained to remain proportional to evidence.

**Materials and Methods:** The architecture separates five components: one enterprise OCEL 2.0 observation layer; 250 independently versioned computable pathway-definition packages; a patient–pathway assignment layer with immutable version pinning; a deterministic, append-only measurement ledger; and a governed analysis-and-review layer. A reference implementation within a hospital-operations platform (Zephyrus) was audited at the schema, code, and test levels, and its production database — populated by a governed synthetic demonstration environment — was evaluated with a formal flattening experiment quantifying event loss (deficiency) and duplication (convergence) under case-centric projections, together with source-connectivity and identity audits.

**Results:** The observation layer held 19,439 events, 10,912 objects of 29 types, and 56,026 qualified event-object relationships (100% qualifier coverage) spanning 75 days from 9 source classes. Flattening to a patient case notion rendered 75.9% of events unrepresentable; an encounter case notion lost 18.1% and duplicated 2.2% of attributable events into 2–4 cases; retention proportions were stable across independent same-day snapshots. Attempted graph-based recovery of the largest lost class exposed a measurable cross-source granularity mismatch with no governed identity crosswalk (0 of 602 unit objects shared between source classes; 0 identity links), converting silent misattribution into an explicit data-readiness gate. The 250-pathway catalog decomposed into 28,164 candidate clauses, 11,663 timing expressions, and 10,588 source-verified terminology candidates awaiting institutional review across three readiness lanes (96 signoff, 148 specialist review, 6 redesign).

**Discussion and Conclusion:** Object-centric storage removes a structural distortion that single-case event logs impose on multi-object inpatient care, but measurement validity is earned by the surrounding governance: six separated truth domains, a 17-state step-measurement taxonomy distinguishing care findings from data findings, append-only supersession, and eleven activation gates. The architecture is implementable and auditable on commodity infrastructure; clinical activation is deliberately gated on identity proof, binding validation, and chart-reviewed gold-set performance.
:::

## BACKGROUND AND SIGNIFICANCE

### The measurement problem in inpatient quality and safety

Twenty-five years after *To Err Is Human* and *Crossing the Quality Chasm*, the dominant instruments for measuring the quality and safety of inpatient care remain retrospective, aggregate, and slow [1,2]. Donabedian's structure–process–outcome triad established that process measurement is the actionable core of quality assessment — structure changes slowly and outcomes arrive late and confounded — yet process measurement at the level of the individual patient remains the exception rather than the rule [3,4]. Care pathways, defined by the European Pathway Association tradition as complex interventions for the mutual decision-making and organization of care for a well-defined patient group over a well-defined period, are the principal vehicle by which hospitals convert clinical evidence into expected sequences of inpatient care [5,6]. The 2010 Cochrane review found clinical pathways associated with reduced in-hospital complications and improved documentation without adverse cost or length-of-stay effects, findings revisited in a 2025 update [7,8].

The clinical stakes of pathway timing are not abstract. In sepsis, each additional hour to completion of the 3-hour bundle and to antibiotic administration was associated with higher risk-adjusted in-hospital mortality across 49,331 patients in New York State's mandated-care cohort (odds ratio 1.04 per hour for both, 95% CI 1.02–1.05 and 1.03–1.06, respectively) — while time to completion of the fluid bolus showed no such association (odds ratio 1.01 per hour, 95% CI 0.99–1.02), a caution that pathway elements are not interchangeable evidence [9]; bundle timing anchors both the Surviving Sepsis Campaign guidelines and the hour-1 bundle [10,11]. In acute ischemic stroke, the Target: Stroke initiative shortened door-to-needle times with an accompanying fall in in-hospital mortality [12], and shorter door-to-needle times were associated with lower 1-year mortality and readmission in Medicare beneficiaries [13]. In hip fracture, observational data associate surgical delay beyond 24 hours with higher 30-day mortality [15], while the HIP ATTACK randomized trial found accelerated surgery did not reduce the composite primary outcome but reduced delirium, urinary tract infection, and time to mobilization [14]. Enhanced Recovery After Surgery programs and the WHO Surgical Safety Checklist show analogous process–outcome coupling in perioperative care [16,17]. If hospitals could measure, per patient and per step, whether expected care happened on time — and could distinguish care that did not happen from care that was not observable — pathway programs could close the loop between evidence and delivery at operational tempo.

Yet the measurement instruments themselves have become cautionary tales. The Centers for Medicare & Medicaid Services SEP-1 measure — an all-or-none sepsis bundle — drew a formal Infectious Diseases Society of America position paper recommending against its pass/fail construction, citing sepsis overdiagnosis, antibiotic overuse, and the collapsing of heterogeneous clinical situations into a single dichotomy [18]; a 2024 six-society position paper reiterated these concerns as the measure entered pay-for-performance [19]. United States physician practices were estimated to spend $15.4 billion annually reporting quality measures [66], and Campbell's law — the more a quantitative indicator is used for decision-making, the more it will distort the process it monitors — applies with full force to pathway compliance scores [67]. A measurement architecture for care pathways must therefore be engineered not only to compute, but to *resist over-claiming*: to keep "missing" separate from "unobservable," "deviation" separate from "error," and "correlation" separate from "cause."

### Process mining in healthcare and the single-case-notion problem

Process mining derives process knowledge from event logs — discovery of process models, conformance checking of observed behavior against normative models, and performance analysis [20-22]. Its application to healthcare is well surveyed: foundational and updated literature reviews document hundreds of studies [23-26], a scoping systematic review examines conformance checking against clinical guidelines specifically [27], and a systematic review of data-driven care-pathway mapping catalogs clinical and operational insights obtained from routinely collected data [28]. The healthcare process-mining community's consensus paper identifies the characteristics that make healthcare distinctive — high variability, case heterogeneity, multiple interacting perspectives, and data-quality fragility [24].

The dominant log format underlying this literature, the IEEE 1849 XES standard, requires every event to belong to exactly one case [21]. van der Aalst formalized the two distortions this forces on multi-object processes: **convergence** (one event related to multiple case objects is duplicated into each case) and **divergence** (unrelated instances of the same activity are interleaved within a case, fabricating spurious orderings); events related to *no* object of the chosen case notion are silently dropped (**deficiency**) [29]. Object-centric process mining (OCPM) removes the single-case constraint: events relate to any number of typed objects through qualified relationships, and object-centric event logs (OCEL 1.0, then OCEL 2.0 with qualified relationships, object-to-object relationships, and dynamic object attributes) provide a standard interchange format [30-32]. Analytical machinery has followed: object-centric directly-follows graphs and Petri nets [30], case and variant definitions for object-centric data [34], object-centric process discovery and analysis tooling [35,70], object-centric alignments [36], and synchronization-aware conformance alignments [37], alongside the broader conformance-checking foundations [38] and declarative constraint models suited to flexible clinical work [39].

Healthcare-specific OCPM is nascent. Park, Lee, and Cho published what they describe as the inaugural application, transforming OMOP common-data-model data into OCEL and validating on MIMIC-IV [40,55]; extraction work in enterprise systems ties convergence explicitly to unintended event duplication [41]; and tutorial treatments quantify the distortions on worked examples [71]. To our knowledge, no published work has (a) quantified flattening distortion on a deployed hospital operational event store, (b) specified an end-to-end governance architecture connecting OCEL observation to versioned care-pathway definitions and a patient-level measurement ledger, or (c) grounded that architecture in the measurement-science requirements of clinical quality programs [49,50]. That is the gap this paper addresses.

### Computable guidelines, FHIR, and why neither suffices alone

Computable clinical guidelines have a three-decade literature — Arden Syntax, GLIF, PROforma, and successors are comprehensively reviewed by Peleg [42] — and the HL7 Clinical Practice Guidelines on FHIR implementation guide, FHIR PlanDefinition, the Quality Measure implementation guide, QI-Core profiles, and Clinical Quality Language provide contemporary interoperable representations of *what should happen* and *how to compute a measure* [43-48]. The FHIR ecosystem, SMART on FHIR applications, and bulk data access solve *exchange* of clinical resources [44,45]. The OHDSI/OMOP community solves *analytic standardization* of observational records [54]. None of these, however, is a temporal record of *what actually happened, to which interacting objects, in which order and under which resource constraints* — the behavioral substrate that process measurement requires. Conversely, an event log without governed definitions, terminology bindings, assignment logic, and measure semantics cannot say what *should* have happened. The architecture evaluated here treats these as complementary: FHIR resources and operational systems feed a single OCEL observation layer; versioned pathway-definition packages (informed by CPG-on-FHIR patterns) are evaluated *over* that layer; and quality-measure discipline (CMS Measures Management System testing criteria; data-quality assessment frameworks) governs what may be claimed [49,51-53].

## OBJECTIVE

To specify, implement, and empirically evaluate an architecture by which a hospital can measure individual inpatient care against a governed catalog of 250 DRG-anchored care-pathway definitions using one shared OCEL 2.0 observation layer — quantifying (1) what a deployed object-centric store contains and how completely its sources connect patients, encounters, and operational objects; (2) what is destroyed when the same store is flattened to conventional single-case logs; (3) what the 250-pathway modeling program requires, measured at clause, terminology-candidate, and governance-gate granularity; and (4) which structural safeguards keep the resulting measurements honest — with all claims bounded by the evidence a synthetic-data deployment can support.

## MATERIALS AND METHODS

### Setting and design

This is a design-science study comprising architecture specification, reference implementation, and empirical evaluation. The setting is Zephyrus (Acumenus, Inc.), a hospital-operations command-center platform (Laravel/PostgreSQL backend, React front end, Python process-mining sidecar) whose production instance is populated by a governed synthetic demonstration environment: a coordinated data-plausibility service regenerates emergency-department, inpatient-flow, perioperative, transport, barrier, ancillary, and home-hospital activity on a 15-minute cadence, and the OCEL projection runs on the same clock. No human-subjects data are involved; all patient, encounter, and clinical entities are synthetic (Ethics, below). The evaluation snapshot was taken July 24, 2026. Reporting follows the STARE-HI statement for evaluation studies in health informatics where its items apply to a design-science evaluation [73]. Two protections against self-confirmation apply: the demonstration generator and the OCEL projector predate this analysis and were built for command-center realism, not to exhibit flattening effects; and every quantitative claim derives from scripted queries (Supplementary Appendix) cross-checked for arithmetic identity.

The architectural specification under evaluation (Acumenus technical program specification ACUM-ENG-OCEL-002, July 24, 2026) consolidates a research report, a 250-pathway modeling worklist, and a prior measurement-and-root-cause architecture into a single governed program document; the source care-pathway catalog is a terminology-expanded release comprising a 250-row, 59-column pathway CSV and an 11-worksheet verification workbook (10,124-row claim audit; 10,589-row terminology crosswalk; SHA-256 release digests), imported into production under release controls with zero silent overwrites.

### Component 1 — the enterprise OCEL 2.0 observation layer

**Storage model.** The observation layer is a 12-table PostgreSQL schema implementing the OCEL 2.0 metamodel relationally [32]: `events` (identifier, activity, timestamp, attributes, source system, source reference), `objects` (identifier, type, attributes), qualified event-to-object (E2O) and object-to-object (O2O) relationship tables with uniqueness over (source, target, qualifier), an object attribute-change table keyed by (object, attribute, change time), object-quantity and quantity-operation tables implementing the quantity extension, and activity and object-type catalogs. Cross-table foreign keys are deliberately omitted — integrity is carried by deterministic identifiers plus eight uniqueness constraints — so that an interrupted partial re-projection can never wedge on write ordering; a JSON exporter serializes the store to standard OCEL 2.0 interchange format and validates that every event touches at least one object and every reference resolves.

**Projection.** A deterministic projector transforms seven operational source families (patient-flow events, care-journey milestones, perioperative case timings, transport requests, barriers, ancillary milestones, and home-hospital episodes/visits/escalations) into events, objects, and qualified relationships through pure per-source emission mappings. Event identifiers are deterministic functions of source rows (e.g., one transport request emits distinct request/pickup/dropoff events); all writes are idempotent upserts in a single transaction, so re-projection of any window is safe by construction. Patient and encounter identifiers are de-identified at emission by truncated SHA-256 hashing (48 bits) of source references; operational locations (units, beds, operating-room suites) remain human-readable slugs as non-PHI operational identifiers. A defect-and-repair history in the migration record (attribute-change deduplication requiring an added uniqueness key) documents the idempotency guarantee being enforced, not assumed.

**Scheduling and serving.** The OCEL refresh job runs every 15 minutes over a 2-day trailing window with a nightly 90-day full re-projection and reconciliation; conformance and performance analytics refresh every 30 minutes; and a process-mining sidecar (FastAPI + pm4py) computes object-centric directly-follows graphs (the union of per-object-type projections), OPerA-style per-type performance metrics, object-centric Petri nets, token-based replay fitness, and quantity-based occupancy from the exported log [30,70]. Discovery caches are keyed by a source signature; the audit deliberately records that the current signature (row count concatenated with maximum event time) cannot detect in-place updates — a weakness the program specification independently orders replaced before any pathway measurement (Results).

### Component 2 — computable pathway-definition packages

Each of the 250 catalog rows becomes a versioned, immutable *pathway package*, never a single record: identity and governance metadata (four named owner roles; approval status, body, and evidence; effective period; site/service-line/population scope); executable applicability logic (inclusion, exclusion, precedence among concurrent pathways, lookback/washout, and mandatory-confirmation policy); a stage and atomic-activity graph in which each atomic expected activity must answer twelve questions (stable identifier, clinical meaning, permitted performer, target object, required evidence state, branch applicability, timing anchor and bounds, acceptable substitution, acceptable exception, source binding, resource requirement, and provenance to a source claim and reviewer); explicit branch semantics (eleven constructs: sequence, parallel, inclusive-OR, exclusive-OR, optional, conditional, repeat-until, bounded loop, interrupt, substitution, terminal); temporal constraints carrying seventeen attributes across identity, anchoring, bounds, clock (elapsed/calendar/business/staffed-time, with pause conditions and time-zone policy), interpretation, operations, and governance; exception and contraindication models in which an exception is an evidence-bearing object with actor, timestamp, and documented authority — never a free-text status that erases an obligation; resource requirements paired against observable capacity evidence; a measure bundle (process completion, timeliness, branch appropriateness, outcome, complication, balancing, utilization, experience, readmission, safety screening, and data-quality coverage), each measure with numerator, denominator, exclusions, exceptions, stratifiers, missing-data policy, minimum cohort rule, and test cases [46,49]; and terminology binding sets versioned independently of clinical content.

**Terminology discipline.** Candidate codes from the source crosswalk (LOINC, SNOMED CT, ICD-10-CM, CPT) [61-64] are stored structurally apart from approved binding members; promotion requires four independent reviews (coding correctness, clinical applicability, local source mapping, binding test against fixtures). DRG is admitted only as a candidate-detection and retrospective-reconciliation signal — the database schema constrains DRG-mapping roles to {candidate, supporting, retrospective_reconciliation, excluded}, and the implementation comments record the rationale: a final coded DRG arrives after care and would make denominators depend on billing latency.

### Component 3 — patient–pathway assignment

Assignment is a nine-state machine (candidate, pending_confirmation, confirmed, rejected, superseded, completed, canceled, entered_in_error, unable_to_determine), with denominator membership defined per state and every transition carrying actor, reason, timestamp, and evidence. Candidate detection is deliberately permissive and never establishes the denominator; confirmation pins the patient instance to one immutable definition version by content digest. In the reference implementation, pinning is enforced in the database: patient-side instance tables reference the pinned catalog version, and a trigger rejects any stage or milestone instance whose definition belongs to a different version.

### Component 4 — the deterministic measurement ledger

Each evaluation is a *measurement run* under an immutable reproducibility key (pathway instance; definition version and digest; evaluator version; binding-set version; source cutoff; source-manifest digest; identity-rules version; run purpose). Two runs with identical keys must produce identical output. Step evaluation proceeds by applicability resolution (recording every predicate result and preserving *unknown* rather than coercing it to failure), lifecycle-aware event matching under nine explicit selection rules (first valid, last valid, closest to anchor, all, minimum/maximum result, final/corrected result, specific specimen, reviewer-selected, other approved deterministic policy) with every considered-and-rejected candidate retained, and timing calculation persisting the full tuple (anchor event and role, target event, clock, bounds, signed variance, coverage, exception evidence). Step outcomes take one of seventeen states (Table 6) that separate care findings (e.g., met_late, missing, out_of_sequence) from data findings (data_unavailable, source_late, source_corrected, canceled_or_entered_in_error, ambiguous) and neutral states (not_applicable, exception_approved, optional_not_observed). Late-arriving or corrected source data trigger a superseding run linked by provenance; prior conclusions remain queryable as evidence of what the system reported at the time. A single unqualified "pathway compliance percentage" is prohibited by design.

The deterministic evaluator is the primary and safety-critical evaluator; object-centric alignments [36,37] — whose synchronization-aware variants carry substantial computational complexity — are restricted to offline, explanatory, sampled use (disputed cases, model validation, retrospective explanation), and process discovery is used to learn how care actually flows, never promoted to a clinical standard without governed review.

### Component 5 — governed analysis and review

Measurement outputs feed problem-type-specific analysis with explicit evidentiary preconditions: deficiency rates computed only over steps with sufficient source coverage; bottleneck signals requiring a stable queue boundary, reliable lifecycle timestamps, volume, persistence, and baseline comparison; resource-insufficiency candidates requiring co-occurring demand/capacity/unavailability evidence (with *insufficient_evidence* as a legitimate output); delay decomposition into twelve classes whose twelfth — documentation/ingestion delay — attributes measurement-system latency to the measurement system rather than to clinicians; and safety screening whose eleven allowed review conclusions include four non-events or data findings, feeding governed adjudication aligned with AHRQ Common Formats and root-cause/systems-approach practice [57-59]. Structural misuse controls prohibit individual clinician ranking from unvalidated deviations, unit comparisons across materially different source coverage, small-cohort disclosure, silent retroactive rescoring, and causal labels without adjudication [65-67]. Eleven approval gates separate source acceptance, normalization, terminology, binding, clinical definition, measures, data quality, shadow-mode validation, staff-facing activation, prospective alerting, and patient-facing content (Table 7); retrospective measurement is the required first production mode.

### Evaluation methods

**(1) Implementation audit.** We audited the repository (migrations, domain services, scheduled jobs, tests) and enumerated database-enforced invariants: append-only triggers, row-immutability triggers, SHA-256 digest columns with format checks, count-partition constraints, and activation gates. Scale was measured in tables, triggers, lines of code, and automated tests.

**(2) Observation-layer census and connectivity audit.** Read-only SQL against the production database enumerated events, objects, relationship cardinalities, qualifier coverage, activity and object-type coverage versus catalogs, and — per source class — the percentage of events with at least one linked Patient, Encounter, and operational object (excluding Patient/Encounter/Unit/Bed), reproducing and extending the program specification's snapshot.

**(3) Flattening experiment.** Following the convergence/divergence formalization [29,71], let E be the event set and, for each object type T, let R_T(e) be the distinct set of T-objects related to event e. We computed the *retained set* E_T = {e ∈ E : R_T(e) ≠ ∅}; **event loss** ('deficiency' in the object-centric process-mining literature) = 1 − |E_T|/|E|, the fraction of events unrepresentable in a T-cased log; the flattened log L_T = {(e,o) : o ∈ R_T(e)} with |L_T| = Σ_e |R_T(e)|; and the **duplication factor** |L_T|/|E_T|. We evaluated T = Patient and T = Encounter under direct E2O relationships and under a one-hop O2O closure that models the bridge-table joins analysts actually write (an event attributed to encounter c if any of its objects has an O2O relationship with c). O2O traversal is direction-agnostic and attribution sets are distinct-counted. Because this projector emits at most one object of each type per event, within-event fan-out cannot exceed one, and measured duplication is a lower bound relative to general OCEL 2.0 logs, which permit multiple same-type relationships per event [32]. We additionally attempted graph-based recovery of the largest deficient class (barrier events) into encounter cases via the shared-unit path (Barrier→Unit; Encounter→Bed→Unit), and audited identifier-namespace overlap between the unit objects reached by each path.

**(4) Program quantification.** From the governed release and its production import we quantified the modeling workload (candidate clauses, timing terms, conditional terms; per-pathway medians), terminology candidates by system, readiness lanes, complexity distribution, and the catalog's current governance state (approvals, signoff, definition-graph population).

**Reproducibility and statistics.** All census and experiment values are exact database aggregations (no sampling); percentages are reported to one decimal. Snapshot stability was assessed two ways: by deriving comparison values from the specification's independently published morning snapshot (per-class link rates applied to class volumes) and by re-executing the full query set later the same evening. The complete audit SQL is provided in the Supplementary Appendix. Because the demonstration data are synthetic, we attach no inferential statistics to clinical content and interpret magnitudes as properties of this deployment's log structure — the analysis method, not the specific percentages, is the transferable artifact. Queries are reproducible against a snapshot restored from the release-controlled import.

**Ethics.** The evaluated instance contains synthetic data only, generated by the platform's demonstration environment; no human-subjects data were accessed, and institutional review was not required. The architecture's privacy posture (minimum-necessary access, de-identification at projection, cohort-size suppression) follows 45 CFR §164.502(b) [69].


## RESULTS

### The architecture as implemented

[[FIG:fig1_architecture|Figure 1. The five-component architecture (left) and the six separated truth domains (right). Fan-out to 250 pathway definitions occurs at evaluation time, never at storage time; no table, API, or interface may collapse the six truths into one status flag. Counts are the July 24, 2026 census of the reference deployment.]]

Table 1 summarizes the five components and the question each answers; Figure 1 shows their composition and the six truth domains they are forbidden to conflate. The central design decision is that the observation layer is *one and shared*: a creatinine result is simultaneously relevant to acute kidney injury, sepsis, medication safety, and perioperative management, and duplicating it into pathway-scoped logs would create inconsistent corrections, conflicting identities, duplicate lineage, and no defensible answer to which copy is authoritative (Figure 2). The definition layer fans out to 250 independently versioned packages evaluated *over* the shared observations.

| # | Component | Question it answers | Cardinality |
|---|---|---|---|
| 1 | Enterprise OCEL 2.0 observation layer | What actually happened, to which objects, when, from which source record and version | One, shared |
| 2 | Computable pathway-definition packages | What was expected for an applicable patient — stages, atomic steps, branches, temporal constraints, exceptions, resources, measures | 250, independently versioned |
| 3 | Patient–pathway assignment | Why this pathway applied to this patient, who confirmed it, and which immutable definition version is pinned | One per patient instance |
| 4 | Deterministic measurement ledger | How observation compared with definition — timing, order, completeness, exceptions, coverage, confidence, evidence | One run per evaluation, append-only |
| 5 | Governed analysis and review | What a deficiency, bottleneck, resource, delay, outcome, or safety signal means — and what an authorized reviewer concluded | One review workflow |

*Table 1. The five components. Component 4 is deterministic and safety-critical; object-centric alignment is an explanatory second layer, never the first evaluator.*

[[FIG:fig2_object_graph|Figure 2. One clinical event, one stored copy. A finalized creatinine result relates through qualified event-to-object relationships to the patient, encounter, order, specimen, and result object, and serves four concurrent pathway instances through measurement-layer evidence links — without duplicating the event. Flattening this graph to any single case notion destroys at least one perspective.]]

[[FIG:fig3_lifecycles|Figure 3. Operational and clinical event lifecycles the observation layer must represent. Each transition is a distinct timestamp role and anchor candidate; a snapshot event ("test ordered") cannot prove specimen collection, result availability, correction, or acknowledgment. result_corrected triggers a superseding measurement run rather than an in-place rewrite.]]

[[FIG:fig8_measurement|Figure 4. (A) The patient–pathway measurement lifecycle: permissive candidate detection; confirmation with immutable version pinning; measurement runs under a full reproducibility key; a step-and-constraint ledger with seventeen states; governed review. (B) Append-only supersession: a corrected lab collection time produces run 2 superseding run 1; both remain queryable, and every report states which run it used.]]

### Reference-implementation census

The implementation is substantial and its governance invariants are database-enforced (Table 2). Notably, the care-pathway catalog schema enforces append-only semantics on fifteen tables through a shared rejection trigger, protects release and version content with row-immutability triggers, carries SHA-256 content digests (with hexadecimal format checks) on more than twelve columns, and gates catalog activation behind a trigger requiring complete clinical signoff with per-version approval — while all ten downstream serving flags default to *off* and clinical signoff stands at 0 of 250. The system is, by construction, running as an unapproved catalog: governance is enforced by the schema, not by policy documents.

| Subsystem | Scale in the audited repository |
|---|---|
| OCEL observation schema | 12 tables, 4 migrations; 0 foreign keys (deterministic identifiers + 8 uniqueness constraints); OCEL 2.0 JSON exporter with structural validation |
| OCEL projector | 7 source families; deterministic idempotent upserts; 48-bit truncated SHA-256 de-identification of patient/encounter references; 509-line orchestrator + 808-line pure emission map |
| Process-mining sidecar | Python/FastAPI + pm4py; OC-DFG, OPerA-style performance, OC Petri nets, token-replay fitness, quantity occupancy; 1,756 LOC + 985 test LOC; clean-room boundary against AGPL tooling, CI-enforced |
| Care-pathway catalog schema | 25 tables + 3 views; 15 append-only triggers; 4 row-immutability triggers; activation-gate trigger; SHA-256 digest columns with format checks; count-partition check constraints |
| Patient-side instance schema | Version pinning enforced by trigger (instance definitions must belong to the pinned version); all 5 instance/status tables append-only; digest-keyed status events |
| Catalog import (production) | 250 definitions; 250 versions; 7,000 narrative sections (28/pathway); 802 DRG mappings; 10,123 evidence claims; 0 approvals; 0 reviews; all versions inactive |
| Conformance demonstrations | Exactly 3 hard-coded rule sets (sepsis/SEP-3 180-minute antibiotic target; WHO surgical-safety checklist phases; home-hospital activation/cadence/response floors); 11 deviation codes; first-occurrence timelines |
| Scheduling | OCEL refresh every 15 min (2-day window); nightly 90-day re-projection + reconciliation; conformance/performance every 30 min |
| Automated tests | 187 (129 PHP methods, 4,552 LOC; 35 Python; 23 TypeScript/JavaScript) |

*Table 2. Reference-implementation census (repository audit, July 24, 2026). LOC = lines of code.*

Two audit findings are reported as limitations the specification itself already orders fixed, providing convergent validity between specification and code: (a) the discovery-cache source signature — SHA-1 over (row count, maximum event time) — cannot detect in-place updates to already-projected rows, exactly the weakness item 1 of the specification's ordered change list replaces with bounded content digests; and (b) the conformance demonstrations build per-case timelines from the *first* occurrence of each activity, exactly the first-timestamp logic item 7 replaces with lifecycle-aware event matching. Neither defect can corrupt the append-only stores; both bound what today's demonstrations may claim.

### Observation-layer census and source connectivity

At the evaluation snapshot the observation layer contained **19,439 events**, **10,912 objects** across 29 observed types (38 cataloged), **56,026 event-object links** — a mean of 2.88 objects per event, with **100% of links carrying an explicit qualifier** — a measured property, not a schema constraint (the qualifier column is nullable) — across 14 qualifier kinds (subject on every event; patient 28.5%; target 20.3%; location 12.0%; resource 2.8%; plus work-item, context, episode, specimen, result, dose, report, device, communication) — **9,649 object-object relationships**, and **20,878 object attribute changes**, spanning 75 days (May 11 – July 24, 2026) from 9 source classes. Ninety-one of 114 cataloged activities and 29 of 38 cataloged object types are represented by actual data: catalog presence must not be confused with observed coverage. Relative to the specification's same-morning snapshot (19,322 events), counts drift upward continuously under the 15-minute refresh; all structural proportions are stable.

Source connectivity is fragmented in exactly the way that matters for pathway measurement (Table 3; Figure 5). The two largest classes are individually incomplete in complementary ways: transport events (11,370) link encounters (99.3%) and transport-job work objects (100%) but no patients; patient-flow events (4,430) link patients and encounters (100% each) but no workflow or resource objects. Barrier events (2,670) link only barrier objects — no patient, no encounter, and no resolution event in the observed window. Only a minority of flow events carry clinical-code-shaped attributes (1,585 diagnosis-bearing; 1,408 observation-bearing; 960 order-bearing; 435 medication-bearing), and ancillary events carry operational attributes without validated terminology bindings. The measured consequence: no current source family is sufficient to prove the expected observations of any of the 250 pathways; the first production milestone is therefore data observability and identity proof, not activation of pathway scoring.

| Source class | Events | % with Patient | % with Encounter | % with operational object | Main limitation |
|---|---|---|---|---|---|
| Transport requests | 11,370 | 0 | 99.3 | 100 | No patient relationship |
| Patient-flow core | 4,430 | 100 | 100 | 0 | No workflow/resource object |
| Barriers | 2,670 | 0 | 0 | 100 | No patient/encounter; no resolution event |
| Ancillary milestones | 320 | 0 | 64.7 | 100 | No terminology binding |
| Care-journey milestones | 259 | 100 | 0 | 100 | No encounter relationship |
| Perioperative case timings | 192 | 0 | 0 | 100 | No patient/encounter relationship |
| Home-hospital visits | 184 | 0 | 0 | 100 | No patient/encounter relationship |
| Home-hospital episodes | 10 | 0 | 0 | 100 | Limited volume |
| Home-hospital escalations | 4 | 0 | 0 | 100 | Too sparse for inference |

*Table 3. Per-source connectivity of the observation layer (operational object excludes Patient, Encounter, Unit, Bed). The census reproduces the program specification's independent snapshot and quantifies why identity hardening precedes measurement.*

[[FIG:fig4_connectivity|Figure 5. (A) Event volume by source class (log scale). (B) Percentage of each class's events carrying at least one linked Patient, Encounter, or operational object. The two highest-volume classes are incomplete in complementary ways — transport lacks patients; flow lacks work objects.]]

### The flattening experiment: what single-case logs destroy

Within-event fan-out per type is exactly one in this deployment — the projector emits at most one object of each type per event — so the classical within-event convergence of order-management logs is structurally absent at emission. The distortions instead appear at the graph level, and they are large (Table 4; Figure 6).

**Event loss.** A patient-cased log retains 4,689 of 19,439 events (24.1%): 75.9% of the operational record — every transport movement, barrier, perioperative timing, and home-visit event — is unrepresentable. An encounter-cased log retains 15,929 events (81.9%); the 3,510 lost events comprise all barrier events (2,670), care-journey milestones (259), case timings (192), home-visit/episode events (194), ancillary milestones without encounter links (113), transport events without encounters (78), and escalations (4).

**Convergence.** Under the one-hop object-graph closure that models real bridge-table joins, encounter attribution duplicates 354 events (2.2% of attributable events) into multiple encounter cases — 264 into two, 86 into three, and 4 into four (duplication factor 1.028; the 16,377 flattened rows reconcile exactly as 15,575 single-case events + 264×2 + 86×3 + 4×4) — chiefly transport jobs serving multiple encounters, while recovering zero additional coverage (the 3,510 lost events have no one-hop path to any encounter). Duplication of this kind is exactly the mechanism by which flattened logs fabricate workload and distort performance statistics [29,41,71].

**Identity: a granularity mismatch, made measurable.** The natural recovery join for the largest lost class — attribute barrier events to encounters through the shared unit (Barrier→Unit joined against Encounter→Bed→Unit) — returns **zero rows**. The audit locates the cause precisely: barrier events reference 24 unit objects under unit-level identifiers (unit-picu, unit-4e, …), while bed-to-unit membership references 432 bed-granular location identifiers (unit-gyn8-b0x, …). This is a granularity mismatch — unit-level versus bed-level location coding — and the mismatch itself is not an error; the measured gap is that no governed crosswalk relates the two families (the identity-link table contains 0 rows), so the namespaces share 0 of the 602 unit objects and the join is unanswerable. In a conventional flattened pipeline it would silently return empty or wrong attribution; in the object-centric store the gap is itself a first-class, queryable finding — and it converts directly into the specification's gating requirement that cross-source identity be proven before any pathway status may depend on a source family.

| Representation | Events retained | % of 19,439 | Events lost | Flattened rows | Duplication factor | Multi-case events |
|---|---|---|---|---|---|---|
| Object-centric (reference) | 19,439 | 100.0 | 0 | — | — | — |
| Encounter-cased, direct links | 15,929 | 81.9 | 3,510 | 15,929 | 1.000 | 0 |
| Encounter-cased, one-hop O2O closure | 15,929 | 81.9 | 3,510 | 16,377 | 1.028 | 354 (2–4 cases each) |
| Patient-cased, direct links | 4,689 | 24.1 | 14,750 | 4,689 | 1.000 | 0 |
| Barrier→Unit→Bed→Encounter recovery join | 0 | 0.0 | 2,670 | 0 | — | — |

*Table 4. Flattening experiment results. Event loss ('deficiency') and duplication follow the convergence/divergence formalization of object-centric process mining [29]; within-type fan-out ≤1 by projector construction makes measured duplication a lower bound. The recovery-join row exposes a granularity mismatch with no governed crosswalk (0/602 shared unit identifiers; 0 identity links).*

**Snapshot stability and internal consistency.** The store refreshes continuously, so the reported proportions must not be artifacts of a single draw. Applying the specification's independently published morning per-class link rates to its morning volumes (19,322 events) yields patient-cased retention of 24.3% and encounter-cased retention of 82.1%, against 24.1% and 81.9% measured directly in the evening census (19,439 events); re-execution of the full query set later the same evening reproduced the evening values exactly. Arithmetic identities close: the nine source-class volumes sum to 19,439; the eight lost-class volumes sum to 3,510; and 56,026/19,439 reproduces the 2.88 mean objects per event.

[[FIG:fig5_flattening|Figure 6. (A) Event loss under flattening: percentage of the 19,439-event store representable under each case notion; hatched regions are unrepresentable events. (B) Composition of the 3,510 events invisible to an encounter-cased log. (C) The recovery join for barrier events fails measurably — a unit-level versus bed-level granularity mismatch with no governed crosswalk — rather than failing silently.]]

The shared-resource structure that motivates object-centricity is directly visible in participation profiles (Figure 7): the busiest unit object participates in 2,337 events, encounters reach 186 events, and operating-room suites average 28 events each — high-degree shared objects whose perspective (queueing, blocking, occupancy) has no home in a patient- or encounter-cased log.

[[FIG:fig6_shared_objects|Figure 7. Events per object by type (median to maximum, log scale). Shared resources — units, operating-room suites, encounters as containers — concentrate events; their perspective is destroyed by any single case notion.]]

### The 250-pathway program, quantified

The governed catalog import is verified end to end (release digests; 250/250 rows reconciled; two-layer CSV/workbook release design with 1,972 expected cell differences between layers; zero silent overwrites). Its content, however, is a *research catalog, not an executable pathway library* — and the program quantification measures the distance between the two (Table 5; Figure 8). Narrative decomposition must normalize approximately **28,164 candidate clauses** (median 125 per pathway), **11,663 timing expressions** (median 43), and **4,238 conditional expressions** (median 15) into typed, provenance-bearing rules. The terminology crosswalk contributes **10,588 source-verified candidate records** (the 10,589-row worksheet includes a header) (LOINC 7,285 across all 250 pathways; ICD-10-CM 2,340 across 67; SNOMED CT 648 across 107; CPT 315 across 41) — every record carrying the status that the terminology source was verified but pathway applicability requires coding and clinical review; 74 surgical pathways additionally require local procedure or ICD-10-PCS bindings that the release deliberately did not infer. Readiness lanes partition the catalog into 96 pathways ready for institutional signoff and normalization, 148 requiring specialist review of documented limitations first, and 6 administrative/heterogeneous DRG families (e.g., "O.R. procedures unrelated to principal diagnosis") explicitly routed to redesign, registry, or non-protocol status rather than being forced into condition-specific protocol shape. The definition-side graph tables (stages, milestones, activities, goals) exist and are entirely unpopulated (0 rows each), as are approvals (0), reviews (0), and patient pathway instances (0): measured evidence that the platform currently *withholds* pathway claims it cannot yet defend.

| Dimension | Value |
|---|---|
| Pathways / versions / narrative sections / DRG mappings | 250 / 250 / 7,000 / 802 |
| Evidence claims traced | 10,123 |
| Candidate clauses (median per pathway) | 28,164 (125) |
| Timing terms (median) | 11,663 (43) |
| Conditional terms (median) | 4,238 (15) |
| Terminology candidates: LOINC / ICD-10-CM / SNOMED CT / CPT | 7,285 / 2,340 / 648 / 315 (10,588 total; 0 approved for execution) |
| Pathways with ≥1 candidate: LOINC / SNOMED / ICD / CPT | 250 / 107 / 67 / 41 |
| Readiness lanes: signoff / specialist review / redesign | 96 / 148 / 6 |
| Complexity heuristic: very high / high / moderate | 122 / 107 / 21 |
| Care type: medical / surgical / obstetric / neonatal | 127 / 115 / 6 / 2 |
| Pathways requiring local procedure / ICD-10-PCS binding | 74 (release did not infer PCS) |
| Stage/milestone/activity/goal definitions populated | 0 / 0 / 0 / 0 |
| Clinical approvals / reviews / patient pathway instances | 0 / 0 / 0 |

*Table 5. Quantification of the 250-pathway modeling program (governed release v43.1, terminology-expanded; production import verified July 24, 2026).*

[[FIG:fig7_worklist|Figure 8. The 250-pathway program landscape: (A) readiness lanes; (B) technical-complexity heuristic (a workload measure, not clinical severity); (C) terminology-candidate coverage by system — source-verified candidates only, none approved for execution; (D) narrative-normalization workload.]]

### Current conformance demonstrations, honestly bounded

The deployed conformance capability comprises exactly three hard-coded rule sets — a sepsis bundle keyed to the Surviving Sepsis Campaign 180-minute antibiotic target [9-11], the WHO surgical-safety checklist phases [17], and a home-hospital pathway with activation, visit-cadence, and response-time floors — evaluated over a 60-episode seeded sepsis cohort and analogous synthetic populations, with eleven deviation codes and first-occurrence timelines. These are demonstrations of the serving path (they exercise export, evaluation, caching, and cockpit banding), and the platform treats them as such: only two are wired to cockpit metrics, and the specification prohibits presenting their aggregates as clinically validated pathway performance. They are reported here as evidence of pipeline feasibility, not of measurement validity — which the step-state taxonomy (Table 6) and the gate structure (Table 7) are designed to establish later, per pathway, against chart-reviewed gold cases.

### The seventeen step states and eleven gates

| State | Class | Meaning |
|---|---|---|
| met_on_time | Care | Valid evidence within the approved window |
| met_early | Care | Valid evidence before the lower bound |
| met_late | Care | Valid evidence after the upper bound |
| missing | Care | Required, not observed, with sufficient coverage to say so |
| extra | Care | Observed but not expected in the active branch |
| out_of_sequence | Care | Evidence in a disallowed order |
| repeated_or_rework | Care | Repetition beyond the approved pattern |
| exception_approved | Neutral | Obligation validly excepted, with evidence |
| contraindicated | Neutral | Approved contraindication supported by evidence |
| not_applicable | Neutral | Branch or step did not apply |
| optional_observed | Neutral | Optional action occurred |
| optional_not_observed | Neutral | Optional action did not occur |
| data_unavailable | Data | Required source or field not observable |
| source_late | Data | Evidence arrived after the original cutoff |
| source_corrected | Data | A later source version changed the interpretation |
| canceled_or_entered_in_error | Data | Source explicitly invalidated the event |
| ambiguous | Data | Multiple interpretations unresolved |

*Table 6. The seventeen step-measurement states. The care/data separation is the difference between a care finding and an integration finding; collapsing "unknown" into "not done" would systematically penalize the units with the weakest data feeds.*

| Gate | Approver | Proves |
|---|---|---|
| 1 Source release acceptance | Data governance | Release identity, lineage, reconciliation |
| 2 Narrative normalization | Knowledge engineering + clinical owner | Clauses became typed rules without meaning drift |
| 3 Terminology applicability | Terminology specialist + HIM/coding | Codes belong in this pathway |
| 4 Local observation binding | Clinical informatics + data engineering | Local data can prove the expectation |
| 5 Clinical definition | Specialty clinician owner | The expectation is clinically correct here |
| 6 Measure | Quality + measure owner | Numerator, denominator, exclusions sound |
| 7 Data quality | Data engineering | Coverage and freshness thresholds met |
| 8 Shadow-mode validation | Quality + clinical owner | Gold-case performance acceptable |
| 9 Staff-facing activation | Operational owner + human factors | Surface usable and not misleading |
| 10 Prospective alerting | Clinical governance body | Alert latency and burden safe |
| 11 Patient-facing content | Patient experience + clinical owner | Appropriate, plain-language, non-accusatory |

*Table 7. The eleven approval gates. Passing one gate never implies another; a clinically approved definition with an unapproved binding must not activate. Retrospective measurement (gates 1–9) is the required first production mode; prospective alerting is separately authorized.*


## DISCUSSION

### Principal findings

This study contributes four things. First, an architecture: five components that separate what happened (one shared OCEL 2.0 observation layer) from what was expected (250 versioned definition packages), from why a pathway applied (assignment with immutable pinning), from how observation compared with expectation (a deterministic, append-only measurement ledger), from what it means (governed review) — with six truth domains that no interface may collapse. Second, a reference implementation demonstrating that the architecture's governance is *enforceable in the database*: append-only triggers, content digests, version-pinning triggers, and activation gates, verified by 187 automated tests (129 PHP, 35 Python, 23 TypeScript), running on commodity open-source infrastructure at 15-minute refresh cadence. Third, an empirical audit method — and its first results on a deployed hospital-operations store — quantifying precisely what conventional single-case logs would destroy: 75.9% of events unrepresentable under a patient case notion, 18.1% under an encounter case notion with 2.2% duplication among the remainder, and a recovery join that fails not silently but *measurably*, on a granularity mismatch with no governed crosswalk (0 of 602 shared unit identifiers). Fourth, a quantified program design: 250 research-grade pathway narratives decomposed into a 28,164-clause, 10,588-candidate normalization and governance workload with lane-based sequencing, seventeen step-measurement states, and eleven activation gates that keep claims proportional to evidence throughout.

The flattening numbers deserve one emphasis. They are not an indictment of any particular BI pipeline; they are the *structural cost of the single-case assumption itself*, measured on real operational projections rather than constructed examples. The patient-perspective loss (75.9%) is dominated by exactly the event families — transport, barriers, perioperative timings — that carry the operational explanations (queueing, blocking, resource contention) for why clinical steps run late. A pathway program that flattens to patient cases can still compute "antibiotic late"; it structurally cannot see *why*, because the why lives on shared objects the case notion cannot hold (Figure 7). Object-centric alignment of clinical steps with operational context is the analytic dividend of the shared store [29,30,36,37].

### Relation to prior work

Healthcare process mining is a mature literature with an acknowledged data-quality and multi-perspective problem [23-28,68,72]; object-centric process mining is its proposed structural answer [29-32,34,35]; and healthcare OCPM has exactly one published end-to-end precedent identified in the PubMed, arXiv, and DBLP sweep (2022–2026) conducted during this paper's citation verification — OMOP-to-OCEL transformation validated on MIMIC-IV [40,55] — alongside ERP-extraction evidence that convergence duplicates events in practice [41] and tutorial quantifications [71]. Alternative multi-perspective substrates — event knowledge graphs over labeled property graphs [33] and database-grounded meta-models such as OpenSLEX [74] — address the same distortion; OCEL 2.0 was selected here for its standardized interchange format and available tooling, and nothing in the governance architecture depends on that choice. Our contribution is complementary and infrastructural: we show what an *operational, continuously projected* hospital OCEL contains, what flattening it would cost, and — the part absent from the OCPM literature — what surrounds the log when the goal is not analysis but *governed clinical measurement*: versioned definitions, terminology promotion pipelines, assignment states, reproducibility keys, supersession, and gates. Conversely, relative to the computable-guideline and eCQM traditions [42-49], our contribution is the observation side: CQL over FHIR resources computes measure populations but does not retain the qualified event-object graph, candidate-and-rejected match evidence, or operational context that make a patient-level result *explainable and auditable* at review. The architecture deliberately consumes both traditions rather than competing with them.

The claims-discipline stance — six separations, seventeen states, prohibition of the single compliance percentage — operationalizes lessons the quality-measurement community has learned expensively: all-or-none bundle measures invite miscount and clinical distortion [18,19]; alert systems without governance breed fatigue and overrides [65]; reporting burden is real money [66]; and indicators used for control distort what they measure [67]. The data-findings half of the state taxonomy implements the Kahn framework's conformance/completeness/plausibility categories at the level of individual step results [51-53], and the coverage-gated denominators respond directly to the equity failure mode in which poorly instrumented units appear clinically worse. Fairness, in this architecture, is first a missingness property: because *missing* and *data_unavailable* are distinguished per step, coverage can be stratified by unit, service line, shift, payer, language, and demographic group, and cross-unit comparison is suppressed when coverage differs beyond threshold.

### Adversarial examination

We subjected the design to the strongest objections we could construct; Table 8 summarizes, and the subsections answer.

| # | Challenge | Disposition |
|---|---|---|
| 1 | Why not 250 per-pathway logs? | Shared events would fork; corrections diverge; cross-pathway analysis breaks — one authoritative store, fan-out at evaluation |
| 2 | Is OCEL necessity or fashion? | Measured: 18.1–75.9% loss, 1.028 duplication, shared-object perspectives unrepresentable; flattening retained as a *view* |
| 3 | Synthetic data prove nothing clinical | Correct — and claimed nowhere; mechanisms are structural, magnitudes deployment-specific; method transfers; real-data precedent exists [40] |
| 4 | Why not FHIR/eCQM alone? | Exchange and measure computation ≠ behavioral record; complementary consumption, event-level evidence retained |
| 5 | Seventeen states is overengineering | The worked ledger shows pass/fail misclassifies 3 of 4 rows; the states are the smallest set separating care, data, and neutral findings |
| 6 | DRG anchoring is billing-shaped | DRG is constrained by schema to candidate/reconciliation roles; assignment requires clinical confirmation with pinned versions |
| 7 | 28,164 clauses is infeasible | Quantified, laned, factory-modeled; clinical-owner capacity is named as the binding constraint; scaling never outruns signoff |
| 8 | Alignments are the rigorous evaluator | Complexity and explainability argue the reverse for the safety-critical path; alignments serve offline explanation [36,37] |
| 9 | This becomes surveillance | Misuse controls are structural: no individual ranking, coverage-matched comparison only, suppression, no silent rescoring |
| 10 | 48-bit hashes; weak signatures; first-only matching | Acknowledged; quantified (collision bound); already ordered replaced by the specification's change list before clinical use |

*Table 8. Adversarial challenges and dispositions.*

**(1) "One log per pathway would be simpler."** Simpler to start; indefensible to operate. A creatinine result serving four pathways would exist as four copies with four correction histories; a late-arriving lab amendment would have to find and rewrite every copy; cross-pathway questions (how often does AKI complicate the sepsis pathway?) would require re-joining what duplication tore apart; and no copy would be authoritative. The single-store design makes the correction problem *one* problem, solved once by supersession (Figure 4B).

**(2) "Show that object-centricity is necessary, not fashionable."** Necessity here is empirical, not rhetorical: 24.1% patient-perspective retention means three-quarters of the operational record — precisely the delay-explaining classes — cannot exist in a patient-cased log at all (Table 4); duplication under encounter joins fabricates 2.8% extra rows concentrated in multi-encounter transport (the classic convergence artifact [29,41]); and shared-resource perspectives (units at 2,337 events; suites averaging 28) have no case-log home (Figure 7). Where a single-perspective question suffices, the architecture still answers it — flattening is retained as a *view* for compatibility, with the store as the single source of truth. The cost asymmetry matters: a view is cheap to produce from the graph; the graph is impossible to recover from the view.

**(3) "Your data are synthetic; your percentages are theater."** The percentages are properties of this deployment's log structure, and we claim exactly that — no clinical inference is attached to them anywhere in this paper. Three things survive the synthetic-data discount. The *mechanisms* (multi-object care, shared resources, cross-source identity fragmentation) are structural properties of hospital operations, not of the generator; published real-data OCPM work exhibits the same multi-object structure on MIMIC-IV [40,55]. The *audit method* — deficiency, duplication factor, connectivity, and identity-overlap queries — transfers unchanged to real feeds and is, we argue, a prerequisite audit any site should run before trusting pathway analytics. And the *governance findings* (0 approvals, 0 identity links, unpopulated definition graphs gating activation) are facts about the production system, synthetic content notwithstanding. What synthetic data cannot establish — measure validity against chart review, real-feed completeness, clinical usability — is exactly what gates 4, 7, and 8 require before activation, and Synthea-style synthetic populations remain appropriate for the executable fixtures those gates consume [56]. Two further protections deserve note: the generator and projector were built before and independently of this analysis, for command-center realism rather than to exhibit flattening effects; and we specify a replication protocol — apply the Supplementary Appendix queries to a MIMIC-IV-derived OCEL constructed per Park et al. [40,55] — under which every headline metric of Table 4 is recomputable on real data.

**(4) "FHIR + CQL already does this."** FHIR standardizes resources and exchange [44,45]; CPG-on-FHIR standardizes computable guideline content [42,43]; CQL/eCQM standardizes measure computation [46-49]. None is a behavioral record: a MeasureReport tells you the numerator; it does not retain which candidate events were considered and rejected, which anchor and clock produced "+18 minutes," what the source-coverage context was, or how the operational graph explains the delay. The architecture consumes FHIR resources as sources, borrows CPG-on-FHIR patterns for definition packaging, aligns measure metadata with the Quality Measure IG — and adds the layer those standards presuppose but do not provide: a qualified, multi-object, append-only observation record with run-scoped evidence. This mirrors the position of event knowledge graphs in the process-mining literature: the multi-dimensional behavioral substrate is its own artifact [33].

**(5) "Seventeen states is taxonomy for its own sake."** The specification's worked sepsis ledger is the rebuttal in miniature: of four step rows (lactate 18 minutes late; blood cultures validly excepted after referring-site antibiotics; repeat lactate unobservable for want of a result feed; vasopressors correctly not applicable), a pass/fail evaluator scores three of four as failures — three different kinds of wrong. The state set is the minimal partition separating care findings, data findings, and neutral states such that denominators can be coverage-gated [51], exceptions remain visible rather than obligation-erasing, and fairness analysis has the raw material it needs. All-or-none measurement's clinical track record is the cautionary precedent [18,19].

**(6) "DRG-anchored pathways inherit billing's flaws."** They would, if DRG assigned pathways. It does not: the schema restricts DRG-mapping roles to candidate and reconciliation purposes, six heterogeneous DRG families are explicitly exiled to non-protocol status rather than modeled, and assignment requires clinical confirmation with evidence and version pinning. DRG anchoring buys a complete, familiar organizing frame for the *catalog* (99.0% volume coverage as a worklist property) while the *denominator* remains clinically owned. The "99% coverage" figure is explicitly bounded in the specification: it describes the DRG catalog, and says nothing about clinical-event coverage, applicability, approval, or measurement validity.

**(7) "The normalization program is infeasible."** The program is large and now *known*: 28,164 clauses at a median of 125 per pathway, 60–120 executable fixtures per pathway, seventeen factory steps of which only two are automated; the fixture requirement alone implies 15,000–30,000 executable test cases across the catalog (60–120 per pathway). The design responds structurally: readiness lanes sequence work; template reuse is permitted without silent sharing (identical source text never creates one shared executable rule — each pathway version adopts, overrides, and reviews explicitly, because a single template edit must never silently rewrite expectations for 120 pathways); the six non-protocol families are not forced; and the specification names clinical-owner capacity — not engineering — as the binding constraint, with scaling forbidden to outrun signoff. Feasibility is a program-management claim, and the paper's contribution is to replace "import 250 rows" optimism with a measured backlog.

**(8) "Object-centric alignments are the principled evaluator; your deterministic engine is ad hoc."** Alignment-based conformance is the gold standard for *explaining* deviations against a model [36-38], and synchronization-aware object-centric alignments are exactly the right instrument for disputed-case explanation. But the safety-critical first evaluator must be reproducible under a pinned key, explainable to a clinical reviewer in terms of named anchors and windows, testable against fixtures, and cheap enough to run per patient per refresh. A deterministic constraint evaluator over declarative-style obligations [39] has those properties; alignment search does not, at clinical-review explainability or at unbounded-cohort cost. The architecture therefore stages them: deterministic first and always; alignments offline, sampled, explanatory — a division the alignment literature's own complexity results support [36,37].

**(9) "This will become clinician surveillance."** The misuse controls are structural, not aspirational: no individual clinician ranking from unvalidated deviations; no comparison across units with materially different coverage; minimum-cohort suppression; no patient-facing "error" language; no silent retroactive rescoring; adjudication required before causal or error labels; and audience projections firewalled so raw research prose and unadjudicated screens cannot reach patient or assistant surfaces. These constraints are the direct application of Campbell's law and the SEP-1 experience to system design [18,19,67] — and they are testable properties of the schema and serving path, not policy promises.

**(10) "Your own audit found defects."** Yes — three, and their disposition is the point. The 48-bit truncated hash is adequate for a synthetic demonstration but carries a birthday-bound collision probability of approximately n²/2⁴⁹ — 0.18% at 10⁶ identifiers, ≈16% at 10⁷ — unacceptable for production identity; the specification's identity-proof milestone and governed identity links (currently 0 rows, honestly) are the replacement path. The count-and-max-time cache signature cannot detect in-place updates; the ordered change list replaces it with bounded content digests as its *first* item. First-occurrence timelines cannot distinguish ordered from collected from resulted; lifecycle-aware matching (Figure 3) replaces them at item 7. A measurement platform whose specification and audit agree on its defects — and whose activation gates hold until they are fixed — is exhibiting the property this paper argues for: claims proportional to evidence.

### Generalizability

Three portability claims are defensible. The storage and projection pattern (OCEL 2.0 relational core; deterministic idempotent projection; qualifier discipline) uses only PostgreSQL and standard tooling, and the interchange format is standardized [32]. The audit method (connectivity, deficiency, duplication, identity overlap) is schema-generic SQL any OCEL-bearing site can run. The governance pattern (six truths; states; gates; supersession) is independent of the specific 250-pathway catalog — it would govern 20 pathways as well as 250. What does *not* generalize without local work is exactly what the architecture says cannot: terminology bindings, timestamp semantics, coverage thresholds, and gold-set performance are site properties, re-proven per service line (gate structure, Table 7). The magnitudes of flattening loss will differ by site and source mix; the existence of loss will not, wherever transport, barriers, and shared resources are logged.

### Limitations

First and foremost, all event content is synthetic: no clinical validity claim is made or available, magnitudes are illustrative of mechanism rather than estimates of any real site, and the demonstration cohort's volume ramp reflects generator cadence. Second, the definition layer is unpopulated by design — zero approved pathways, zero patient instances — so the measurement ledger (component 4) is specified and schema-anchored but not yet exercised end to end; the conformance results shown are pipeline demonstrations on three hard-coded rule sets with first-occurrence matching, explicitly not validated measurement. Third, single-setting evaluation: one platform, one synthetic deployment, one team's engineering conventions; the audit found real defects (hash truncation, weak cache signature, missing barrier-resolution events), and although each is acknowledged and ordered fixed in the specification, they bound today's capability. Fourth, the flattening experiment models analyst behavior with direct and one-hop joins; more sophisticated ETL could recover more context at proportionally greater fabrication risk — our zero-row recovery join shows the failure mode, not the ceiling of effort. Fifth, the program-workload figures (clauses, candidates) are lexical inventories that bound effort estimation but are not validated effort models. The audit itself was executed by a single investigator; the mitigation is that every claim is a scripted, re-runnable query (Supplementary Appendix) cross-checked against the specification's independent snapshot, but independent re-execution is the true remedy and the released artifacts enable it. Relatedly, the recovery-join analysis is structural, not temporal: even a time-scoped join cannot uniquely attribute a unit-level barrier during multi-encounter occupancy — which is why the object-centric store keeps such events once, at their true granularity, rather than forcing an attribution. Sixth, this is a single-author design-science study; the clinical, terminology, and governance review the architecture itself mandates (four named roles per pathway; eleven gates) has, by definition, not yet occurred for any pathway — the strongest limitation and the one the design converts into an explicit precondition rather than a silent assumption.

### Future work

The specification's phased roadmap is the future-work section made concrete: harden identity and projection integrity (bounded digests; correction reconciliation; orphan quarantine; barrier resolution events); build the definition workbench and normalize pilot pathways selected on observability (one high-specificity medical, one surgical with full case lifecycle, one consult/queue-heavy, one obstetric/neonatal dyad); stand up the measurement ledger with superseding runs; validate against chart-reviewed gold cases with inter-rater reliability, sensitivity/specificity/predictive values, and missing-data sensitivity per CMS measure-testing discipline [49,50]; run shadow mode with false-signal characterization; and only then activate, retrospective first, by readiness lane. On real feeds, the flattening and identity audits should be repeated as acceptance criteria; MIMIC-IV-style open datasets [55] offer an intermediate validation venue for the transformation and evaluator layers, and the OMOP-to-OCEL precedent [40] suggests a convergent path the two communities should standardize.

## CONCLUSION

Inpatient care is a multi-object process; storing its record as single-case logs destroys measurable, decision-relevant structure before analysis begins — 18.1% to 75.9% of events in this deployment, with duplication fabricating the remainder's context and identity fragmentation hiding silently inside recovery joins. A single shared OCEL 2.0 observation layer removes that distortion at acceptable engineering cost, and this paper demonstrates the removal is implementable, auditable, and governable on commodity infrastructure. But the log is the smaller half of the contribution. Care-pathway measurement earns clinical trust only through the discipline wrapped around observation: versioned computable definitions with provenance; clinically confirmed assignment with immutable pinning; deterministic, reproducible, append-only measurement that separates care findings from data findings; and gates that keep every claim proportional to its evidence — a missing event is not necessarily missed care; a late step is not necessarily an undue delay; a deviation is not necessarily an error; a terminology match is not clinical approval. The architecture evaluated here is one defensible way to hold both halves together, and its own unfinished state — 250 cataloged pathways, zero activated — is presented not as modesty but as the design working: a measurement system that refuses to claim what it cannot yet defend is the only kind that should ever reach a patient's chart.

## ACKNOWLEDGMENTS

The author thanks the Zephyrus engineering effort for the reference implementation and the governed release tooling that made the audit reproducible.

## AUTHOR CONTRIBUTIONS

S.M.U. conceived the architecture, performed the implementation audit and empirical analyses, and wrote the manuscript.

## FUNDING

This work received no external funding; it was supported internally by Acumenus, Inc.

## COMPETING INTERESTS

S.M.U. is the founder of Acumenus, Inc., which develops the Zephyrus platform evaluated in this work.

## DATA AVAILABILITY

The evaluated instance contains synthetic data only. The complete audit SQL (census, connectivity, event loss/duplication, identity overlap) is included in the Supplementary Appendix; the OCEL 2.0 export schema and figure-generation code are available from the author on reasonable request; the OCEL 2.0 standard artifacts are publicly available at ocel-standard.org [32].

## REFERENCES

1. Kohn LT, Corrigan JM, Donaldson MS, eds. To Err Is Human: Building a Safer Health System. Washington, DC: National Academies Press; 2000.
2. Institute of Medicine Committee on Quality of Health Care in America. Crossing the Quality Chasm: A New Health System for the 21st Century. Washington, DC: National Academies Press; 2001.
3. Donabedian A. Evaluating the quality of medical care. Milbank Mem Fund Q. 1966;44(3):Suppl:166-206.
4. Donabedian A. The quality of care: how can it be assessed? JAMA. 1988;260(12):1743-1748.
5. Vanhaecht K, Panella M, van Zelm R, Sermeus W. An overview on the history and concept of care pathways as complex interventions. Int J Care Pathw. 2010;14(3):117-123.
6. Kinsman L, Rotter T, James E, Snow P, Willis J. What is a clinical pathway? Development of a definition to inform the debate. BMC Med. 2010;8:31.
7. Rotter T, Kinsman L, James E, et al. Clinical pathways: effects on professional practice, patient outcomes, length of stay and hospital costs. Cochrane Database Syst Rev. 2010;(3):CD006632.
8. Rotter T, Kinsman LD, Alsius A, et al. Clinical pathways for secondary care and the effects on professional practice, patient outcomes, length of stay and hospital costs. Cochrane Database Syst Rev. 2025;(5):CD006632. doi:10.1002/14651858.CD006632.pub3.
9. Seymour CW, Gesten F, Prescott HC, et al. Time to treatment and mortality during mandated emergency care for sepsis. N Engl J Med. 2017;376(23):2235-2244.
10. Evans L, Rhodes A, Alhazzani W, et al. Surviving Sepsis Campaign: international guidelines for management of sepsis and septic shock 2021. Crit Care Med. 2021;49(11):e1063-e1143.
11. Levy MM, Evans LE, Rhodes A. The Surviving Sepsis Campaign bundle: 2018 update. Intensive Care Med. 2018;44(6):925-928.
12. Fonarow GC, Zhao X, Smith EE, et al. Door-to-needle times for tissue plasminogen activator administration and clinical outcomes in acute ischemic stroke before and after a quality improvement initiative. JAMA. 2014;311(16):1632-1640.
13. Man S, Xian Y, Holmes DN, et al. Association between thrombolytic door-to-needle time and 1-year mortality and readmission in patients with acute ischemic stroke. JAMA. 2020;323(21):2170-2184.
14. HIP ATTACK Investigators. Accelerated surgery versus standard care in hip fracture (HIP ATTACK): an international, randomised, controlled trial. Lancet. 2020;395(10225):698-708.
15. Pincus D, Ravi B, Wasserstein D, et al. Association between wait time and 30-day mortality in adults undergoing hip fracture surgery. JAMA. 2017;318(20):1994-2003.
16. Ljungqvist O, Scott M, Fearon KC. Enhanced Recovery After Surgery: a review. JAMA Surg. 2017;152(3):292-298.
17. Haynes AB, Weiser TG, Berry WR, et al. A surgical safety checklist to reduce morbidity and mortality in a global population. N Engl J Med. 2009;360(5):491-499.
18. Rhee C, Chiotos K, Cosgrove SE, et al. Infectious Diseases Society of America position paper: recommended revisions to the national severe sepsis and septic shock early management bundle (SEP-1) sepsis quality measure. Clin Infect Dis. 2021;72(4):541-552.
19. Rhee C, Strich JR, Chiotos K, et al. Improving sepsis outcomes in the era of pay-for-performance and electronic quality measures: a joint IDSA/ACEP/PIDS/SHEA/SHM/SIDP position paper. Clin Infect Dis. 2024;78(3):505-513.
20. van der Aalst WMP. Process Mining: Data Science in Action. 2nd ed. Berlin: Springer; 2016.
21. IEEE. IEEE Standard for eXtensible Event Stream (XES) for Achieving Interoperability in Event Logs and Event Streams. IEEE Std 1849-2016; 2016.
22. van der Aalst WMP, Adriansyah A, Alves de Medeiros AK, et al. Process mining manifesto. In: Business Process Management Workshops. LNBIP 99. Berlin: Springer; 2012:169-194.
23. Rojas E, Munoz-Gama J, Sepúlveda M, Capurro D. Process mining in healthcare: a literature review. J Biomed Inform. 2016;61:224-236.
24. Munoz-Gama J, Martin N, Fernandez-Llatas C, et al. Process mining for healthcare: characteristics and challenges. J Biomed Inform. 2022;127:103994.
25. De Roock E, Martin N. Process mining in healthcare — an updated perspective on the state of the art. J Biomed Inform. 2022;127:103995.
26. Mans RS, van der Aalst WMP, Vanwersch RJB. Process Mining in Healthcare: Evaluating and Exploiting Operational Healthcare Processes. Cham: Springer; 2015.
27. Oliart E, Rojas E, Capurro D. Are we ready for conformance checking in healthcare? Measuring adherence to clinical guidelines: a scoping systematic literature review. J Biomed Inform. 2022;130:104076.
28. Manktelow M, Iftikhar A, Bucholc M, McCann M, O'Kane M. Clinical and operational insights from data-driven care pathway mapping: a systematic review. BMC Med Inform Decis Mak. 2022;22:43.
29. van der Aalst WMP. Object-centric process mining: dealing with divergence and convergence in event data. In: Software Engineering and Formal Methods (SEFM 2019). LNCS 11724. Cham: Springer; 2019:3-25.
30. van der Aalst WMP, Berti A. Discovering object-centric Petri nets. Fundam Inform. 2020;175(1-4):1-40.
31. Ghahfarokhi AF, Park G, Berti A, van der Aalst WMP. OCEL: a standard for object-centric event logs. In: New Trends in Database and Information Systems (ADBIS 2021). CCIS 1450. Cham: Springer; 2021:169-175.
32. Berti A, Koren I, Adams JN, et al. OCEL (Object-Centric Event Log) 2.0 specification. arXiv:2403.01975; 2024. https://www.ocel-standard.org/.
33. Fahland D. Process mining over multiple behavioral dimensions with event knowledge graphs. In: van der Aalst WMP, Carmona J, eds. Process Mining Handbook. LNBIP 448. Cham: Springer; 2022:274-319.
34. Adams JN, Schuster D, Schmitz S, Schuh G, van der Aalst WMP. Defining cases and variants for object-centric event data. In: 2022 4th International Conference on Process Mining (ICPM). IEEE; 2022:128-135.
35. Berti A, van der Aalst WMP. OC-PM: analyzing object-centric event logs and process models. Int J Softw Tools Technol Transf. 2023;25:1-17.
36. Liss L, Adams JN, van der Aalst WMP. Object-centric alignments. In: Conceptual Modeling (ER 2023). LNCS 14320. Cham: Springer; 2023:201-219.
37. Gianola A, Montali M, Winkler S. Object-centric conformance alignments with synchronization. In: Advanced Information Systems Engineering (CAiSE 2024). LNCS 14663. Cham: Springer; 2024:3-19.
38. Carmona J, van Dongen B, Solti A, Weidlich M. Conformance Checking: Relating Processes and Models. Cham: Springer; 2018.
39. Pesic M, Schonenberg H, van der Aalst WMP. DECLARE: full support for loosely-structured processes. In: 11th IEEE International Enterprise Distributed Object Computing Conference (EDOC 2007). IEEE; 2007:287-300.
40. Park G, Lee Y, Cho M. Enhancing healthcare process analysis through object-centric process mining: transforming OMOP common data models into object-centric event logs. J Biomed Inform. 2024;156:104682.
41. Berti A, Park G, Rafiei M, van der Aalst WMP. A generic approach to extract object-centric event data from databases supporting SAP ERP. J Intell Inf Syst. 2023;61(3):835-857.
42. Peleg M. Computer-interpretable clinical guidelines: a methodological review. J Biomed Inform. 2013;46(4):744-763.
43. HL7 International. Clinical Practice Guidelines on FHIR (CPG-on-FHIR) Implementation Guide, STU2 v2.0.0; 2024. https://hl7.org/fhir/uv/cpg/.
44. HL7 International. HL7 FHIR Release 4 (R4) v4.0.1 (2019) and Release 5 (R5) v5.0.0 (2023). https://hl7.org/fhir/.
45. Mandel JC, Kreda DA, Mandl KD, Kohane IS, Ramoni RB. SMART on FHIR: a standards-based, interoperable apps platform for electronic health records. J Am Med Inform Assoc. 2016;23(5):899-908.
46. HL7 International. FHIR Quality Measure Implementation Guide (CQF Measures), STU5. https://hl7.org/fhir/us/cqfmeasures/.
47. HL7 International. QI-Core Implementation Guide, STU7. https://hl7.org/fhir/us/qicore/.
48. HL7 International. Clinical Quality Language (CQL), v1.5.3 (normative). https://cql.hl7.org/.
49. Centers for Medicare & Medicaid Services. Measures Management System (MMS) Hub: measure evaluation criteria (importance, scientific acceptability, feasibility, usability and use). https://mmshub.cms.gov/.
50. Battelle Partnership for Quality Measurement (CMS consensus-based entity since 2023). Endorsement and maintenance criteria. https://p4qm.org/.
51. Kahn MG, Callahan TJ, Barnard J, et al. A harmonized data quality assessment terminology and framework for the secondary use of electronic health record data. EGEMS (Wash DC). 2016;4(1):1244.
52. Weiskopf NG, Weng C. Methods and dimensions of electronic health record data quality assessment: enabling reuse for clinical research. J Am Med Inform Assoc. 2013;20(1):144-151.
53. Hersh WR, Weiner MG, Embi PJ, et al. Caveats for the use of operational electronic health record data in comparative effectiveness research. Med Care. 2013;51(8 Suppl 3):S30-S37.
54. Hripcsak G, Duke JD, Shah NH, et al. Observational Health Data Sciences and Informatics (OHDSI): opportunities for observational researchers. Stud Health Technol Inform. 2015;216:574-578.
55. Johnson AEW, Bulgarelli L, Shen L, et al. MIMIC-IV, a freely accessible electronic health record dataset. Sci Data. 2023;10:1.
56. Walonoski J, Kramer M, Nichols J, et al. Synthea: an approach, method, and software mechanism for generating synthetic patients and the synthetic electronic health care record. J Am Med Inform Assoc. 2018;25(3):230-238.
57. Agency for Healthcare Research and Quality. Root cause analysis. PSNet Patient Safety Primer. https://psnet.ahrq.gov/primer/root-cause-analysis.
58. Agency for Healthcare Research and Quality. Systems approach. PSNet Patient Safety Primer. https://psnet.ahrq.gov/primer/systems-approach.
59. Agency for Healthcare Research and Quality. Common Formats for Event Reporting — Hospital Version 2.0 (CFER-H V2.0). https://www.psoppc.org/.
60. Agency for Healthcare Research and Quality. Patient Safety Indicators technical specifications. https://qualityindicators.ahrq.gov/measures/PSI_TechSpec.
61. McDonald CJ, Huff SM, Suico JG, et al. LOINC, a universal standard for identifying laboratory observations: a 5-year update. Clin Chem. 2003;49(4):624-633.
62. Donnelly K. SNOMED-CT: the advanced terminology and coding system for eHealth. Stud Health Technol Inform. 2006;121:279-290.
63. National Center for Health Statistics, Centers for Disease Control and Prevention. International Classification of Diseases, Tenth Revision, Clinical Modification (ICD-10-CM). https://www.cdc.gov/nchs/icd/icd-10-cm/.
64. American Medical Association. Current Procedural Terminology (CPT). https://www.ama-assn.org/practice-management/cpt.
65. van der Sijs H, Aarts J, Vulto A, Berg M. Overriding of drug safety alerts in computerized physician order entry. J Am Med Inform Assoc. 2006;13(2):138-147.
66. Casalino LP, Gans D, Weber R, et al. US physician practices spend more than $15.4 billion annually to report quality measures. Health Aff (Millwood). 2016;35(3):401-406.
67. Campbell DT. Assessing the impact of planned social change. Eval Program Plann. 1979;2(1):67-90.
68. ter Hofstede AHM, Koschmider A, Marrella A, et al. Process-data quality: the true frontier of process mining. ACM J Data Inf Qual. 2023;15(3):29.
69. US Department of Health and Human Services. 45 CFR §164.502(b): minimum necessary standard. Code of Federal Regulations.
70. Berti A, van Zelst S, Schuster D. PM4Py: a process mining library for Python. Softw Impacts. 2023;17:100556.
71. van der Aalst WMP. Object-centric process mining: unraveling the fabric of real processes. Mathematics. 2023;11(12):2691.
72. Santos A, Leal GCL, Balancieri R. Process mining in healthcare: a tertiary study. BMC Med Inform Decis Mak. 2025;25:306.
73. Talmon J, Ammenwerth E, Brender J, de Keizer N, Nykänen P, Rigby M. STARE-HI — statement on reporting of evaluation studies in health informatics. Int J Med Inform. 2009;78(1):1-9.
74. González López de Murillas E, Reijers HA, van der Aalst WMP. Connecting databases with process mining: a meta model and toolset. Softw Syst Model. 2019;18(2):1209-1247.

## SUPPLEMENTARY APPENDIX — AUDIT QUERIES

The following read-only SQL reproduces every quantitative claim of the Results against the OCEL 2.0 relational schema (tables: ocel.events, ocel.objects, ocel.event_object, ocel.object_object). Query S1 produces the census; S2 the per-source connectivity; S3 event loss per case notion; S4 the one-hop closure with the duplication distribution; S5 the granularity/identity overlap. All are schema-generic for any OCEL 2.0 relational store up to table naming.

*S1 — Census.*

```
SELECT (SELECT count(*) FROM ocel.events)                        AS events,
       (SELECT count(*) FROM ocel.objects)                       AS objects,
       (SELECT count(*) FROM ocel.event_object)                  AS e2o_links,
       (SELECT count(*) FROM ocel.object_object)                 AS o2o_links,
       (SELECT count(*) FROM ocel.object_changes)                AS attr_changes,
       (SELECT count(DISTINCT activity) FROM ocel.events)        AS activities,
       (SELECT count(DISTINCT type) FROM ocel.objects)           AS object_types,
       (SELECT min(event_time) FROM ocel.events)                 AS window_start,
       (SELECT max(event_time) FROM ocel.events)                 AS window_end;
```

*S2 — Per-source connectivity (operational object excludes Patient, Encounter, Unit, Bed).*

```
WITH ev AS (
  SELECT e.id, e.source_system,
         bool_or(o.type = 'Patient')   AS has_patient,
         bool_or(o.type = 'Encounter') AS has_encounter,
         bool_or(o.type NOT IN ('Patient','Encounter','Unit','Bed')) AS has_op
  FROM ocel.events e
  LEFT JOIN ocel.event_object eo ON eo.event_id = e.id
  LEFT JOIN ocel.objects o       ON o.id = eo.object_id
  GROUP BY e.id, e.source_system)
SELECT source_system, count(*) AS events,
       round(100.0 * count(*) FILTER (WHERE has_patient)   / count(*), 1) AS pct_patient,
       round(100.0 * count(*) FILTER (WHERE has_encounter) / count(*), 1) AS pct_encounter,
       round(100.0 * count(*) FILTER (WHERE has_op)        / count(*), 1) AS pct_operational
FROM ev GROUP BY 1 ORDER BY events DESC;
```

*S3 — Event loss and duplication per case notion T (direct relationships).*

```
WITH per_event AS (
  SELECT eo.event_id, count(DISTINCT eo.object_id) AS k
  FROM ocel.event_object eo JOIN ocel.objects o ON o.id = eo.object_id
  WHERE o.type = :T GROUP BY 1)
SELECT (SELECT count(*) FROM ocel.events)                          AS total_events,
       count(*)                                                    AS events_retained,
       sum(k)                                                      AS flattened_rows,
       round(1.0 - 1.0*count(*)/(SELECT count(*) FROM ocel.events), 3) AS event_loss,
       round(1.0*sum(k)/count(*), 3)                               AS duplication_factor
FROM per_event;
```

*S4 — One-hop O2O closure for the Encounter case notion, with duplication distribution.*

```
WITH event_enc AS (
  SELECT DISTINCT e.id AS event_id, enc.enc_id
  FROM ocel.events e
  JOIN ocel.event_object eo ON eo.event_id = e.id
  JOIN ocel.objects o       ON o.id = eo.object_id
  LEFT JOIN LATERAL (
    SELECT o.id AS enc_id WHERE o.type = 'Encounter'
    UNION
    SELECT CASE WHEN fo.type = 'Encounter' THEN oo.from_id ELSE oo.to_id END
    FROM ocel.object_object oo
    JOIN ocel.objects fo ON fo.id = oo.from_id
    JOIN ocel.objects t2 ON t2.id = oo.to_id
    WHERE (oo.from_id = o.id AND t2.type = 'Encounter')
       OR (oo.to_id   = o.id AND fo.type = 'Encounter')) enc ON true
  WHERE enc.enc_id IS NOT NULL),
oh AS (SELECT event_id, count(*) AS n_enc FROM event_enc GROUP BY 1)
SELECT count(*) AS events_attributed, sum(n_enc) AS flattened_rows,
       count(*) FILTER (WHERE n_enc = 2) AS in_two_cases,
       count(*) FILTER (WHERE n_enc = 3) AS in_three_cases,
       count(*) FILTER (WHERE n_enc = 4) AS in_four_cases
FROM oh;
```

*S5 — Granularity/identity overlap between barrier-linked and bed-linked Unit namespaces.*

```
SELECT (SELECT count(DISTINCT u.to_id) FROM ocel.object_object u
         JOIN ocel.objects b ON b.id = u.from_id AND b.type = 'Barrier') AS barrier_units,
       (SELECT count(DISTINCT bu.to_id) FROM ocel.object_object bu
         JOIN ocel.objects bed ON bed.id = bu.from_id AND bed.type = 'Bed') AS bed_units,
       (SELECT count(*) FROM (
          SELECT DISTINCT u.to_id FROM ocel.object_object u
            JOIN ocel.objects b ON b.id = u.from_id AND b.type = 'Barrier'
          INTERSECT
          SELECT DISTINCT bu.to_id FROM ocel.object_object bu
            JOIN ocel.objects bed ON bed.id = bu.from_id AND bed.type = 'Bed') x) AS overlap,
       (SELECT count(*) FROM integration.identity_links) AS governed_identity_links;
```
