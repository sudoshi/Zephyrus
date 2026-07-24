# OCEL Models for 250 Individual Care Pathways

**Detailed research, target architecture, measurement design, validation plan, and implementation backlog**
**Date:** 2026-07-24
**Scope:** Zephyrus care-pathway catalog, patient-experience measurement, OCEL 2.0, operational flow, resource constraints, delay, safety screening, and governed root-cause review
**Companion worklist:** [OCEL Care-Pathway 250 Modeling Worklist](./OCEL-CARE-PATHWAY-250-MODELING-WORKLIST-2026-07-24.md)
**Prior foundation revalidated by this report:** [OCEL Care-Pathway Measurement and Root-Cause Architecture](./OCEL-DRG-CARE-PATHWAY-MEASUREMENT-ROOT-CAUSE-ARCHITECTURE-2026-07-21.md)

> **Clinical and coding boundary:** The supplied pathway material and terminology
> crosswalks are research and planning inputs. They are not clinically approved
> order sets, coding directives, patient-specific recommendations, or evidence that
> a listed terminology code is applicable to a particular patient. No pathway may
> influence live clinical care until its local definition, bindings, measures,
> exceptions, tests, and presentation have completed institutional governance.

## Executive answer

Zephyrus should not create 250 independent event logs and should not turn the
workbook's narrative cells directly into OCEL events.

The correct architecture has five major components:

1. **One governed enterprise OCEL 2.0 observation layer** records what actually
   happened across patients, encounters, orders, results, procedures, medications,
   tasks, locations, resources, barriers, and safety events.
2. **Two hundred fifty independent, versioned computable pathway definitions**
   describe what was expected for an applicable patient, including stages, atomic
   steps, branches, temporal constraints, exceptions, resource requirements, and
   measures.
3. **A patient-pathway assignment layer** identifies candidate pathways, requires
   the appropriate clinical confirmation, and pins every patient instance to one
   immutable definition version.
4. **A deterministic measurement ledger** matches observed events to expected
   steps and records timing, order, completeness, exceptions, source coverage,
   confidence, and all supporting evidence. More expensive object-centric
   alignments are an explanatory or offline second layer, not the safety-critical
   first evaluator.
5. **A governed analysis and review layer** turns measurements into deficiency,
   bottleneck, resource, delay, outcome, and safety signals. It records hypotheses
   and contributing evidence without asserting causality or medical error until
   an authorized review supports that conclusion.

The supplied data is a strong research catalog, but it is not an executable
pathway library. All 250 pathways still require institutional clinical signoff.
Production currently contains the catalog structure and an OCEL foundation, but
it does not contain the stage, milestone, activity, goal, approval, review, or
patient-pathway instance population needed to evaluate the 250 pathways.

The scale of the work is therefore not “import 250 rows.” It is:

- normalize approximately 28,164 candidate narrative clauses;
- define and approve branches, exclusions, timing anchors, and exceptions;
- bind each expected observation to local data with explicit timestamp semantics;
- validate candidate terminology rather than assuming code equivalence;
- prove data completeness and correction behavior;
- construct a patient-level, reproducible measurement ledger;
- validate each measure against chart-reviewed cases; and
- activate by readiness lane, not all at once.

## 1. Evidence and verification performed

### 1.1 Supplied release

The research used:

- `DRG_Care_Pathways_250_PATHWAYS_99PCT_v43_1_Terminology_Expanded.csv`
- `DRG_Care_Pathways_250_Verification_Package_v43_1_Terminology_Expanded.xlsx`

The current file identities are:

| Artifact                      | SHA-256                                                            | Shape                    |
| ----------------------------- | ------------------------------------------------------------------ | ------------------------ |
| Terminology-expanded CSV      | `7ac306c44737c3a7af6ec4a499adec2a222c62d0568c72c65ea773fc00d081b1` | 250 pathways, 59 columns |
| Terminology-expanded workbook | `77399527d3b22f5d3ee7bf41066e46d1f9a34c5febe6d400a8f7fcd9769530b7` | 11 worksheets            |

The workbook contains:

| Worksheet                 | Data role                                       |        Size |
| ------------------------- | ----------------------------------------------- | ----------: |
| `QA_Summary`              | Release-level verification facts                |      22 × 2 |
| `Verification_Ledger`     | One governed review record per pathway          |    251 × 23 |
| `Verified_Pathways`       | Pathway content plus provenance and terminology |    251 × 69 |
| `Claim_Audit`             | Claim-by-claim supporting evidence              | 10,124 × 11 |
| `Source_Index`            | Resolved source inventory                       |    812 × 11 |
| `Change_Log`              | Append-only change evidence                     |     325 × 8 |
| `MS_DRG_Codebook`         | DRG reference data                              |     771 × 7 |
| `Methodology`             | Core verification method                        |      16 × 2 |
| `Terminology_Crosswalk`   | Candidate code-to-pathway records               | 10,589 × 12 |
| `Terminology_QA`          | Terminology quality summary                     |      22 × 7 |
| `Terminology_Methodology` | Terminology construction limits                 |      18 × 6 |

A second structural pass confirmed that the XLSX ZIP package is valid, all 11
used-range dimensions reproduce, and the workbook contains zero formula cells
and zero spreadsheet error tokens. The source CSV and workbook were not modified
during analysis.

### 1.2 Lossless expansion finding

The terminology-expanded release adds ten fields:

- `loinc_codes`
- `loinc_codes_count`
- `cpt_codes`
- `cpt_codes_count`
- `snomed_ct_codes`
- `snomed_ct_codes_count`
- `icd10cm_codes`
- `icd10cm_codes_count`
- `terminology_mapping_status`
- `terminology_mapping_notes`

Two exact comparisons were performed:

1. all original 49 CSV fields for all 250 rows were compared with the production
   raw baseline; and
2. all original 59 `Verified_Pathways` fields for all 250 rows were compared with
   the production imported verification package.

Both comparisons returned zero field differences. Each expanded artifact is
therefore a lossless derivative of its own prior baseline.

That conclusion must not be read as saying that the expanded CSV and expanded
workbook are exact copies of one another. A direct comparison of the 59
same-named CSV fields with `Verified_Pathways` found 1,972 exact cell
differences:

- `verification_status`, `verification_confidence`, `verification_notes`,
  `source_access_date`, `citation_audit_status`,
  `clinical_verification_basis`, and `data_quality_notes` differ for all 250
  pathways; and
- `guideline_source`, `key_citations`, and `clinical_source_urls` also differ for
  74 pathways.

This reflects the two-layer release design: the CSV retains the pathway-build/raw
research representation, while the workbook contains the later verified and
governed representation. Some differences are representational, such as date
format, while others are substantive evidence/provenance enrichment. The
workbook `Verified_Pathways` sheet—not the CSV—is the source used for the
governance lanes, source-specificity labels, terminology review state, and the
250-pathway modeling worklist.

This does **not** make the binary releases interchangeable. Their checksums differ
because the new release adds content. The expanded artifacts need a new source
release manifest, lineage record, import identity, and acceptance decision. A
filename resemblance or semantic-core equality must never silently bypass release
controls.

### 1.3 Read-only production snapshot

A production database inspection was executed in an explicitly read-only
transaction with statement and lock timeouts. It was rolled back and made no
changes.

The relevant snapshot was:

| Area                                | Current evidence              |
| ----------------------------------- | ----------------------------- |
| Catalog definitions                 | 250                           |
| Catalog versions                    | 250                           |
| Narrative sections                  | 7,000, exactly 28 per pathway |
| DRG mappings                        | 802                           |
| Stage definitions                   | 0                             |
| Milestone definitions               | 0                             |
| Activity definitions                | 0                             |
| Goal definitions                    | 0                             |
| Clinical approvals                  | 0                             |
| Clinical reviews                    | 0                             |
| Patient pathway instances           | 0                             |
| Patient stage instances             | 0                             |
| Patient milestone instances         | 0                             |
| OCEL events                         | 19,322                        |
| OCEL objects                        | 10,825                        |
| OCEL event-object links             | 55,730                        |
| Distinct OCEL activities observed   | 91                            |
| Distinct OCEL object types observed | 29                            |
| OCEL source classes                 | 9                             |
| OCEL observation window             | 2026-05-11 through 2026-07-24 |

All 250 versions are inactive and not reviewed. The release is therefore behaving
correctly as an unapproved catalog rather than a live clinical standard.

The existing relational schema is a useful foundation: it already distinguishes
definitions, versions, sections, reviews, approvals, and append-only patient
history. It is missing the computable graph content and durable measurement
ledger described later in this report.

### 1.4 Current OCEL is operationally useful but not pathway-complete

The production OCEL contains substantial operational evidence, but source
connectivity is fragmented. The table below reports the percentage of events in
each source class that have at least one linked Patient, Encounter, or
non-patient/encounter operational object. For this audit, the operational-object
column excludes Patient, Encounter, Unit, and Bed; it includes process objects
such as Transport Job, Barrier, Ancillary Order, OR Case, and Home Episode.

| Source class         | Events | Patient link | Encounter link | Operational-object link | Main limitation                                                |
| -------------------- | -----: | -----------: | -------------: | ----------------------: | -------------------------------------------------------------- |
| Transport            | 11,308 |           0% |          99.3% |                    100% | No patient relationship                                        |
| Flow core            |  4,430 |         100% |           100% |                      0% | No linked workflow/resource object                             |
| Barriers             |  2,623 |           0% |             0% |                    100% | Barrier object only; no patient/encounter; no resolution event |
| Ancillary milestones |    320 |           0% |          64.7% |                    100% | Generic milestones; no clinical terminology binding            |
| Care journey         |    259 |         100% |             0% |                    100% | No encounter relationship                                      |
| Case timing          |    192 |           0% |             0% |                    100% | No patient or encounter relationship                           |
| Home visits          |    176 |           0% |             0% |                    100% | No patient or encounter relationship                           |
| Home episodes        |     10 |           0% |             0% |                    100% | Very limited volume; no patient/encounter link                 |
| Escalations          |      4 |           0% |             0% |                    100% | Too sparse for inference; no patient/encounter link            |

The OCEL reference catalogs currently contain 114 activity rows and 38
object-type rows, but only 91 activities and 29 object types are represented by
the current events/objects. Catalog presence must not be confused with observed
coverage.

Only a subset of flow events carry clinical-code-shaped attributes:

- 1,585 diagnosis-bearing events;
- 960 order-bearing events;
- 1,408 observation-bearing events; and
- 435 medication-bearing events.

Ancillary events currently contain operational attributes such as department,
milestone code, phase, priority, and source rank, but not a validated LOINC,
SNOMED CT, CPT, or local terminology binding that could prove a specific pathway
step.

The current hard-coded conformance examples cover sepsis, surgical safety, and
home hospital. They are demonstrations, not proof that production can measure
all 250 pathway definitions. The current aggregate signals for sepsis and
surgical safety must not be presented as clinically validated pathway
performance.

### 1.5 Integration readiness

Production contains 17 registered sources, all in sandbox-oriented states. One
source has a recent passed FHIR conformance record and declares a useful set of
resources, including Patient, Encounter, Condition, ServiceRequest, Observation,
DiagnosticReport, MedicationRequest, MedicationAdministration, Procedure, Task,
CarePlan, Goal, Provenance, Location, PractitionerRole, and CareTeam.

However:

- the conformance record does not prove complete historical or incremental data
  coverage;
- Bulk Data and Subscriptions are not currently established as available;
- `integration.terminology_maps` contains no rows;
- canonical events are dominated by flow and ancillary signals; and
- no current feed has been proven sufficient for all expected observations in
  the 250 pathways.

The first production milestone is therefore data observability and identity
proof, not activation of pathway scoring.

## 2. What the terminology expansion proves—and does not prove

### 2.1 Candidate counts

The crosswalk contains:

| System    | Candidate records | Unique codes | Pathways with candidates | Pathways without candidates |
| --------- | ----------------: | -----------: | -----------------------: | --------------------------: |
| LOINC     |             7,285 |          871 |                      250 |                           0 |
| CPT       |               315 |          195 |                       41 |                         209 |
| SNOMED CT |               648 |          584 |                      107 |                         143 |
| ICD-10-CM |             2,340 |        2,082 |                       67 |                         183 |

An independent row-by-row reconciliation confirmed that every pathway's
semicolon-delimited candidate list, declared count, and `Terminology_Crosswalk`
records contain the same code set for all four systems. There were zero list,
count, or crosswalk mismatches and no blanks in the crosswalk's required rank,
condition, code-system, code, description, mapping-basis, or validation-status
fields.

Every record has the status that the terminology source was verified but pathway
applicability still requires coding and clinical review.

### 2.2 Safe interpretation

The expansion is useful for:

- locating candidate codes during normalization;
- tracing descriptors back to terminology sources;
- estimating the review surface;
- generating local-code mapping tasks;
- identifying pathways with no conservative candidate match; and
- constructing test fixtures once a binding is approved.

It is not evidence that:

- the code belongs in the pathway;
- the code is sufficient to recognize the expected clinical action;
- a diagnosis code establishes patient applicability;
- every descendant of a category is clinically equivalent;
- an inherited or “maps to” SNOMED relationship is equivalent in this context;
- a CPT descriptor represents what happened inside the institution;
- a LOINC code alone determines the specimen, method, timing, or interpretation;
- a blank list means no relevant code exists; or
- ICD-10-PCS procedure bindings have been established.

### 2.3 Binding review requirements by system

#### LOINC

For each candidate, reviewers must confirm:

- component;
- property;
- timing;
- system or specimen;
- scale;
- method where relevant;
- order-versus-result observation semantics;
- local test code and panel membership;
- corrected/final/canceled result status;
- whether multiple acceptable codes form a value set; and
- whether the pathway expects an order, specimen collection, preliminary result,
  final result, result acknowledgment, threshold, or trend.

“Lactate obtained” cannot be implemented by testing only for a LOINC code. It
needs an event state, timestamp role, acceptable specimen/method set, and
relationship to the patient encounter and specimen.

#### SNOMED CT

For each candidate, reviewers must confirm:

- whether it represents a condition, finding, procedure, observable entity, or
  other semantic tag;
- whether the concept is active in the selected edition/version;
- whether descendants are permitted and, if so, by an explicit expression
  constraint;
- whether post-coordination or local concepts are required;
- whether a map target changes meaning or merely supports interoperability;
- whether the concept is used for applicability, observation matching,
  contraindication, exception, or outcome; and
- the expected clinical status and verification status.

#### ICD-10-CM

ICD-10-CM is primarily a diagnosis classification and reconciliation signal in
this design. Reviewers must:

- reject automatic category-to-all-descendants equivalence;
- define the permitted code set and version;
- distinguish admission diagnosis, principal diagnosis, problem list, and
  discharge coding;
- account for laterality, encounter qualifiers, manifestations, and exclusions;
- specify whether the code is only a candidate assignment signal; and
- avoid using the final coded DRG as a silent real-time assignment key.

#### CPT and procedure vocabularies

CPT candidates require:

- licensed descriptor governance;
- explicit local procedure-code mapping;
- setting and status interpretation;
- ordered, scheduled, started, completed, aborted, and billed state separation;
- modifier and laterality context where relevant;
- payer/billing latency awareness; and
- clinical confirmation that the code represents the intended procedural step.

The workbook did not infer ICD-10-PCS. Surgical pathways without an approved
procedure binding need a local procedure terminology and, where required, a
reviewed PCS value set. This gap must remain visible.

## 3. The central modeling decision

### 3.1 OCEL describes observation, not the guideline

The [OCEL 2.0 standard](https://www.ocel-standard.org/) describes events, objects,
qualified event-object relationships, object-object relationships, and changing
object attributes. It is the right representation for the many objects involved
in care delivery.

It is not, by itself, a complete language for:

- clinical applicability;
- desired order;
- optional and mutually exclusive branches;
- temporal obligations;
- contraindications;
- acceptable substitutions;
- guideline provenance;
- clinical approval;
- measure denominators; or
- safety adjudication.

Those concepts belong in a versioned pathway definition evaluated over the OCEL.

### 3.2 Do not create 250 isolated OCEL silos

A patient can be affected by several pathways at once, and one event can serve
several purposes. A creatinine result can be relevant to acute kidney injury,
sepsis, medication safety, and perioperative management. A bed move can affect a
clinical delay, transport workload, and capacity.

Duplicating the event into pathway-specific logs would create:

- inconsistent corrections;
- conflicting patient identities;
- duplicate lineage;
- brittle cross-pathway analysis;
- artificial case boundaries;
- expensive reprocessing; and
- difficulty proving which copy is authoritative.

Instead, the architecture should be:

```text
governed source records
        │
        ▼
canonical identity + event lifecycle
        │
        ▼
one enterprise OCEL observation layer
        │
        ├── pathway definition vA + patient instance + measurement run
        ├── pathway definition vB + patient instance + measurement run
        ├── ...
        └── pathway definition v250 + patient instance + measurement run
```

### 3.3 Six separate kinds of truth

Zephyrus must preserve these boundaries:

| Truth                 | Question                                         | Example                                                    |
| --------------------- | ------------------------------------------------ | ---------------------------------------------------------- |
| Source/evidence truth | What source material and raw data were received? | FHIR Observation version 4, source workbook row 3          |
| Definition truth      | What did the approved pathway version require?   | obtain lactate within the approved window                  |
| Observation truth     | What happened, to which objects, and when?       | specimen collected and result finalized                    |
| Assignment truth      | Why was this pathway applicable to this patient? | confirmed sepsis instance with explicit reviewer           |
| Measurement truth     | How did observation compare with definition?     | result late by 18 minutes, high coverage                   |
| Review/action truth   | What was concluded and what was done?            | delay confirmed; capacity factor rejected; action assigned |

No table, API, or interface should collapse these truths into one status flag.

## 4. What must be built for every individual pathway

Each of the 250 rows becomes a **pathway package**, not a single record. The
package is immutable after approval; later changes create a new version.

### 4.1 Package identity and governance

Required attributes:

- stable pathway definition ID;
- human-readable title;
- local institutional title;
- source release ID and digest;
- pathway version and semantic version;
- clinical owner;
- operational owner;
- knowledge-engineering owner;
- terminology reviewer;
- approval status;
- approval body and evidence;
- effective start and end;
- review-by date;
- superseded version;
- site, service line, population, and setting scope;
- provenance for every normalized rule; and
- audience-specific projection status.

### 4.2 Applicability model

Each pathway needs executable inclusion and exclusion logic covering:

- age range;
- sex-related or pregnancy context where clinically relevant;
- care setting and encounter class;
- presenting condition;
- confirmed diagnosis versus suspected diagnosis;
- procedure planned, performed, or completed;
- acuity/severity qualifiers;
- comorbidities;
- transfers and prior treatment;
- palliative, hospice, comfort-measures, or goals-of-care status;
- contraindications;
- mutually exclusive pathway precedence;
- episode start and end;
- lookback and washout windows; and
- required clinician confirmation.

DRG is a candidate and reconciliation signal. It is commonly assigned after care
has occurred and can group heterogeneous clinical situations. It must not silently
assign an active pathway.

Assignment states should include:

- `candidate`;
- `pending_confirmation`;
- `confirmed`;
- `rejected`;
- `superseded`;
- `completed`;
- `canceled`;
- `entered_in_error`; and
- `unable_to_determine`.

Every transition needs actor, reason, timestamp, and source evidence.

### 4.3 Stage and atomic activity graph

The workbook's sections must be decomposed into:

- stages;
- atomic expected activities;
- milestones;
- goals;
- decision points;
- branch groups;
- explicit edges;
- repeat rules;
- allowed substitutions;
- termination conditions; and
- escalation conditions.

An atomic expected activity must answer:

| Property       | Required question                                                        |
| -------------- | ------------------------------------------------------------------------ |
| Stable ID      | Can this rule be referenced across versions and tests?                   |
| Meaning        | What clinical or operational action is expected?                         |
| Performer      | Who or what may perform it?                                              |
| Target object  | Which patient, encounter, specimen, order, procedure, or task?           |
| Evidence state | Ordered, accepted, started, performed, resulted, verified, acknowledged? |
| Applicability  | Under what branch and conditions does it apply?                          |
| Timing         | Relative to which named anchor and with what bounds?                     |
| Substitution   | What alternative evidence is acceptable?                                 |
| Exception      | What makes omission clinically acceptable?                               |
| Binding        | Which source events and attributes prove it?                             |
| Resource need  | Which role, location, equipment, or capacity is required?                |
| Provenance     | Which source claim and reviewer support the rule?                        |

Narrative strings that contain “and,” “or,” “unless,” “if,” “repeat,” “consider,”
or more than one time expression must be decomposed before execution.

### 4.4 Branch semantics

At minimum, the definition language needs:

- **sequence:** B follows A;
- **parallel:** B and C can proceed independently after A;
- **inclusive OR:** one or more branches can apply;
- **exclusive OR:** exactly one branch applies;
- **optional:** absence is not a deficiency;
- **conditional:** the branch applies only when a predicate is true;
- **repeat-until:** repeat while a condition persists;
- **loop with bound:** repeat no more than the approved limit;
- **interrupt:** escalation or adverse event changes the expected path;
- **substitution:** one approved activity can satisfy another obligation; and
- **terminal:** discharge, transfer, death, comfort care, or other approved end.

Branches must be explicit. A narrative bullet list must never be interpreted as
all-required sequential care by default.

### 4.5 Temporal constraint model

Each timing rule needs:

- constraint ID;
- source step;
- target step;
- named anchor event;
- anchor selection rule when several candidates exist;
- lower bound;
- upper bound;
- unit;
- inclusive/exclusive boundary;
- elapsed, calendar, business, or staffed-time clock;
- pause conditions;
- timezone and daylight-saving policy;
- early/late interpretation;
- missing-anchor policy;
- active-encounter alert threshold;
- retrospective finalization policy; and
- approval provenance.

Examples:

```text
target = specimen_collected
anchor = pathway_recognition
window = [0 minutes, 60 minutes]
clock = elapsed
```

```text
target = specialist_consult_completed
anchor = consult_request_accepted
window = [0 hours, 24 staffed hours]
pause = service unavailable under approved transfer protocol
```

“Day 1,” “post-op day 1,” “within 24 hours,” and “next business day” are not
interchangeable. The clock and anchor must be locally defined and tested.

### 4.6 Exception and contraindication model

For every required step, specify:

- clinical contraindications;
- patient refusal;
- goals-of-care exceptions;
- prior performance elsewhere;
- transfer exceptions;
- test or medication unavailability;
- allergy or intolerance;
- alternative acceptable action;
- approved delay reason;
- documentation requirement;
- actor permitted to approve the exception;
- expiry; and
- whether the exception changes the denominator.

An exception must be an evidence-bearing object or event, not a free-text status
that erases the original obligation.

### 4.7 Resource requirement model

Resource analysis is impossible unless each relevant activity can declare:

- required role or competency;
- required count;
- acceptable substitutions;
- location or bed type;
- device or equipment class;
- consumable or medication availability;
- service calendar;
- preparation/changeover requirement;
- scheduled duration;
- urgency;
- queue discipline;
- escalation threshold; and
- whether the requirement is hard, preferred, or informational.

This definition is paired with observed:

- request time;
- acceptance time;
- ready time;
- queue entry;
- service start;
- pause/resume;
- completion;
- cancellation;
- assigned resource;
- staffed capacity;
- utilization;
- outage/unavailability; and
- competing demand.

### 4.8 Outcomes, quality, and safety measures

Each pathway needs a measure bundle, not one score:

- process completion;
- process timeliness;
- branch appropriateness;
- outcome;
- complication;
- balancing measure;
- utilization;
- patient experience;
- readmission/revisit;
- safety screening; and
- data-quality coverage.

Every measure definition requires:

- stable measure ID and version;
- measure intent;
- numerator;
- denominator;
- denominator exclusions;
- denominator exceptions;
- observation period;
- stratifiers;
- risk adjustment, if justified;
- data elements and value sets;
- missing-data policy;
- aggregation level;
- minimum cohort rule;
- interpretation;
- owner;
- approval;
- test cases; and
- retirement/supersession history.

The [FHIR Quality Measure Implementation Guide](https://hl7.org/fhir/uv/cqm/STU1/)
and FHIR `Measure`/`MeasureReport` artifacts provide useful interoperability
patterns. The internal measurement ledger must still retain event-level evidence
that a summary report cannot express.

## 5. Mapping the supplied pathway fields into a computable package

The workbook should be preserved as immutable source evidence. Normalization
creates new governed entities with field-level lineage.

| Source content family      | Target use                   | Required transformation                                     |
| -------------------------- | ---------------------------- | ----------------------------------------------------------- |
| Rank, pathway, DRG codes   | Identity and reconciliation  | stable IDs; explicit mapping purpose; versioned code sets   |
| Admission criteria         | Applicability                | inclusion/exclusion predicates and confirmation policy      |
| Risk stratification        | Branch/applicability         | individual variables, thresholds, missing-data policy       |
| Initial labs               | Expected activities          | separate order, collection, result, verification, threshold |
| Imaging                    | Expected activities          | order, acquisition, interpretation, acknowledgment          |
| Diagnostic interventions   | Expected activities/branches | split tests and conditional procedures                      |
| Medical interventions      | Activities and goals         | separate therapy, target, monitoring, contraindications     |
| Surgical interventions     | Procedure graph              | planned, scheduled, started, completed, aborted states      |
| Time-critical actions      | Temporal constraints         | named anchors and signed bounds                             |
| Consults                   | Task/resource lifecycle      | request, acceptance, queue, start, complete, recommendation |
| Day 1/2/3+ milestones      | Stage goals                  | replace narrative day labels with explicit anchors/windows  |
| Monitoring                 | Repeat rules                 | cadence, trigger, stop rule, missing-observation behavior   |
| Nutrition/VTE              | Conditional bundles          | risk, contraindication, alternative, documentation          |
| Discharge criteria         | Terminal conditions          | required state and evidence, not only an event name         |
| Discharge planning         | Task graph                   | ownership, dependencies, readiness, external coordination   |
| Quality metrics            | Measure candidates           | formal numerator/denominator/exclusions                     |
| Complications              | Safety/outcome screens       | event definitions, present-on-admission, adjudication       |
| Readmission drivers        | Risk hypotheses              | never treat prose as causal attribution                     |
| Source and evidence fields | Provenance                   | claim-level trace to definition component                   |
| Terminology fields         | Candidate bindings           | review queue, local map, version, semantic role             |

The dataset contains very large duplicate narrative groups: several day,
monitoring, discharge, quality, and readmission fields repeat across as many as
120 pathways. Reuse can support a **template library**, but identical source text
must not create one silently shared executable rule. Each pathway version needs
its own explicit adoption, overrides, clinical review, and provenance.

## 6. Enterprise OCEL object and event model

### 6.1 Minimum object types

The core should include:

| Object type                   | Purpose                                              |
| ----------------------------- | ---------------------------------------------------- |
| Patient                       | Longitudinal person identity                         |
| Encounter                     | Administrative/clinical encounter boundary           |
| EpisodeOfCare                 | Cross-encounter episode where appropriate            |
| PathwayInstance               | Patient-specific pinned pathway version              |
| PathwayDefinitionVersion      | Immutable normative reference                        |
| Condition                     | Suspected/confirmed/resolved applicability evidence  |
| Order/ServiceRequest          | Requested clinical service                           |
| Specimen                      | Collected material and lifecycle                     |
| Observation/Result            | Measurement and interpretation                       |
| DiagnosticReport              | Grouped/final diagnostic conclusion                  |
| ImagingStudy                  | Acquisition object                                   |
| MedicationRequest             | Medication intent                                    |
| MedicationAdministration/Dose | Observed administration                              |
| Procedure/ORCase              | Clinical procedure and case lifecycle                |
| Task/QueueWorkItem            | Work request and operational waiting                 |
| Appointment/ScheduleSlot      | Planned time and capacity                            |
| Location/Bed                  | Place and capacity                                   |
| PractitionerRole/CareTeam     | Responsible role/team                                |
| Resource/Device               | Equipment or constrained asset                       |
| Barrier                       | Blocking condition and resolution lifecycle          |
| SafetyEvent                   | Incident/screen/report/adjudication link             |
| Outcome                       | Disposition or measured result                       |
| SourceRecord                  | Optional lineage object for high-risk reconciliation |

Object IDs must be stable, source-scoped, and reconciled through governed identity
rules. A patient identifier from one source must not be assumed identical to an
identifier from another without a crosswalk or master identity decision.

### 6.2 Qualified relationships

Generic “related to” links are inadequate. Event-object qualifiers should include:

- `subject`;
- `encounter`;
- `episode`;
- `pathway_instance`;
- `requested_service`;
- `specimen`;
- `result`;
- `medication`;
- `procedure`;
- `task`;
- `performed_at`;
- `assigned_resource`;
- `performer`;
- `responsible_team`;
- `caused_queue_entry`;
- `blocked_by`;
- `satisfies`;
- `supports_exception`;
- `supports_assignment`; and
- `source_record`.

Object-object qualifiers should capture:

- encounter belongs to patient;
- pathway instance governs encounter/episode;
- result fulfills order;
- specimen supports result;
- medication administration fulfills request;
- task supports procedure;
- barrier blocks task;
- resource assigned to task;
- child encounter belongs to episode;
- newborn linked to maternal/delivery episode; and
- definition version governs pathway instance.

### 6.3 Event lifecycle

Events must represent state transitions that matter to process measurement, for
example:

```text
service_requested
service_accepted
service_scheduled
patient_ready
resource_ready
queue_entered
service_started
service_paused
service_resumed
service_completed
service_canceled
```

Clinical diagnostic lifecycles can require:

```text
test_ordered
specimen_collected
specimen_received
result_preliminary
result_final
result_corrected
result_acknowledged
```

A single generic `test-ordered` event cannot prove specimen collection or result
availability. A single “procedure” snapshot cannot distinguish planned,
completed, aborted, or billed states.

### 6.4 Timestamp semantics

Every event needs:

- occurrence/clinical time;
- recorded time;
- received/ingested time;
- source update/version time;
- source timezone;
- timestamp role;
- precision;
- source record/version;
- correction/cancellation status; and
- provenance.

Clinical order authored time, desired start, specimen collection, result
availability, verification, and acknowledgment are different anchors.

Late-arriving records must trigger a superseding measurement run. They must not
silently rewrite a previously reported result.

### 6.5 Projection integrity changes

The current OCEL projection should be hardened before pathway measurement:

- compute full bounded content digests, not a signature based only on row count
  and maximum event time;
- make source windows explicit and identical for projection and reconciliation;
- reconcile insertions, updates, cancellations, and retractions;
- prevent a narrow-window projection from erasing previously known object
  attributes;
- store immutable source record/version references;
- validate required object relationships by source class;
- quarantine orphan events;
- record projector version and configuration;
- expose completeness and freshness by event family; and
- test replay idempotency.

## 7. Patient-pathway measurement lifecycle

### 7.1 Candidate detection

Candidate detection may use:

- clinician selection;
- suspected/confirmed Condition;
- procedure order or schedule;
- care plan;
- service line and encounter type;
- validated phenotype;
- structured trigger;
- DRG reconciliation after coding; or
- an approved combination.

Candidate detection is deliberately permissive. It identifies cases for
confirmation; it does not establish the measurement denominator by itself.

### 7.2 Confirmation and version pinning

At confirmation:

1. select the institutional pathway;
2. evaluate inclusion and exclusion evidence;
3. record confirmation actor or approved automated rule;
4. choose episode/encounter boundaries;
5. pin the exact pathway definition digest;
6. store assignment evidence;
7. create the `PathwayInstance` object;
8. attach concurrent pathways and precedence; and
9. start or schedule measurement.

Changing the pathway definition later must not change the historical expectation
for an already pinned patient instance.

### 7.3 Measurement run identity

Each run needs an immutable reproducibility key containing at least:

```text
patient_pathway_instance_id
definition_version_id
definition_digest
evaluator_version
binding_set_version
source_cutoff
source_manifest_digest
identity_rules_version
run_purpose
```

Run purposes may include:

- prospective monitoring;
- retrospective final;
- correction replay;
- model validation;
- shadow-mode comparison;
- chart-review gold-set comparison; and
- cohort aggregation.

### 7.4 Applicability resolution

For each step:

1. evaluate branch predicates using data available at the appropriate time;
2. determine `required`, `optional`, `not_applicable`, or `unknown`;
3. record every predicate result and supporting event/object;
4. evaluate approved exception evidence;
5. identify the named anchor and window;
6. record source coverage for required evidence types; and
7. preserve uncertainty instead of converting it to failure.

### 7.5 Event matching

Candidate event matches are found using:

- approved source binding;
- object identity;
- event state;
- terminology/value set;
- pathway branch;
- temporal eligibility;
- encounter/episode boundary;
- status and correction state;
- allowed substitution; and
- provenance/freshness.

When several events can satisfy a step, the definition must declare the selection
rule:

- first valid;
- last valid;
- closest to anchor;
- all;
- minimum/maximum result;
- final/corrected result;
- specific specimen;
- reviewer selected; or
- other approved deterministic policy.

Every candidate and the selected match should remain auditable.

### 7.6 Required step states

Do not use only pass/fail. At minimum:

| State                          | Meaning                                                   |
| ------------------------------ | --------------------------------------------------------- |
| `met_on_time`                  | Valid evidence within the approved window                 |
| `met_early`                    | Valid evidence before the lower bound                     |
| `met_late`                     | Valid evidence after the upper bound                      |
| `missing`                      | Required and not observed with sufficient source coverage |
| `extra`                        | Observed but not expected in the active branch            |
| `out_of_sequence`              | Evidence occurred in a disallowed order                   |
| `repeated_or_rework`           | Activity repeated beyond the approved pattern             |
| `exception_approved`           | Required obligation was validly excepted                  |
| `contraindicated`              | Evidence supports an approved contraindication            |
| `not_applicable`               | Branch or step did not apply                              |
| `optional_observed`            | Optional action occurred                                  |
| `optional_not_observed`        | Optional action did not occur                             |
| `data_unavailable`             | Required source/field was not observable                  |
| `source_late`                  | Evidence arrived after the original cutoff                |
| `source_corrected`             | Later source version changed the interpretation           |
| `canceled_or_entered_in_error` | Source explicitly invalidated the event                   |
| `ambiguous`                    | Several interpretations remain unresolved                 |

### 7.7 Timing calculation

For a target event \(t\), anchor \(a\), lower bound \(L\), and upper bound \(U\):

```text
elapsed = clock(t, a)
signed_variance =
  elapsed - U        when elapsed > U
  elapsed - L        when elapsed < L
  0                  otherwise
```

Persist:

- anchor event and timestamp;
- target event and timestamp;
- clock implementation;
- lower and upper bounds;
- signed variance;
- state;
- source cutoff;
- event-match confidence;
- data coverage; and
- exception evidence.

The number “18 minutes late” is meaningless without the approved anchor, clock,
source state, and definition version.

### 7.8 Prospective versus retrospective evaluation

Prospective alerts require:

- sufficient source freshness;
- stable patient identity;
- an active confirmed pathway;
- an unambiguous anchor;
- known remaining time;
- suppression and acknowledgment controls;
- exception awareness;
- human-factors review; and
- proof that alert latency is safe.

Retrospective measurement can tolerate late-arriving data and is the required
first production mode. Prospective alerts should be a later, separately governed
capability.

## 8. Durable measurement ledger

The following is an illustrative relational design; exact naming should follow
repository conventions.

### 8.1 Definition-side additions

```text
care_pathways.definition_builds
care_pathways.applicability_rules
care_pathways.stage_definitions
care_pathways.activity_definitions
care_pathways.milestone_definitions
care_pathways.goal_definitions
care_pathways.definition_edges
care_pathways.branch_groups
care_pathways.temporal_constraints
care_pathways.exception_policies
care_pathways.resource_requirements
care_pathways.terminology_binding_sets
care_pathways.terminology_binding_members
care_pathways.observation_bindings
care_pathways.measure_definitions
care_pathways.definition_test_cases
care_pathways.definition_validation_results
```

Candidate terminology should be stored separately from approved binding members:

```text
candidate_crosswalk_record
  -> coding review
  -> clinical applicability review
  -> local source mapping
  -> binding test
  -> approved binding member
```

### 8.2 Measurement-side additions

```text
pathway_measurement.instances
pathway_measurement.instance_assignments
pathway_measurement.runs
pathway_measurement.step_measurements
pathway_measurement.constraint_measurements
pathway_measurement.event_match_candidates
pathway_measurement.selected_event_matches
pathway_measurement.coverage_results
pathway_measurement.deviations
pathway_measurement.wait_segments
pathway_measurement.resource_evidence
pathway_measurement.outcome_measurements
pathway_measurement.safety_screens
pathway_measurement.review_cases
pathway_measurement.review_decisions
pathway_measurement.factor_evidence
pathway_measurement.corrective_actions
```

### 8.3 Essential run fields

`runs` should contain:

- run ID;
- pathway instance;
- definition version/digest;
- evaluator version/digest;
- binding set version/digest;
- source cutoff;
- source manifest;
- status;
- purpose;
- started/completed time;
- supersedes/superseded-by;
- warnings;
- coverage summary; and
- failure/retry evidence.

### 8.4 Essential step measurement fields

`step_measurements` should contain:

- run ID;
- activity definition ID;
- applicability state;
- requirement state;
- expected anchor;
- expected window;
- selected event;
- selected timestamp role;
- actual time;
- signed variance;
- measurement state;
- exception;
- data coverage;
- match confidence;
- terminology binding used;
- source lineage;
- explanation code; and
- human override, if any.

### 8.5 Append-only correction policy

Measurement records are evidence. A later source correction, binding change, or
definition change creates a new run linked with `supersedes`. It does not mutate
the old conclusion out of existence.

## 9. Measurement framework by problem type

### 9.1 Deficiencies

A deficiency is a defined, applicable expectation that was:

- missing;
- late;
- too early where early action is unsafe;
- out of order;
- incomplete;
- performed with an unapproved substitution;
- repeated/reworked beyond the expected pattern; or
- unresolved at the end of the observation period.

Safe metric examples:

```text
completion_rate =
  required_applicable_steps_met
  / required_applicable_steps_with_sufficient_coverage
```

```text
on_time_rate =
  required_timed_steps_met_on_time
  / required_timed_steps_with_known_anchor_and_sufficient_coverage
```

The denominator excludes `not_applicable` and handles approved exceptions
according to the measure definition. Results must display coverage rather than
treat missing data as missed care.

Do not create one unqualified pathway compliance percentage. At minimum report:

- assignment certainty;
- definition version;
- step completion;
- timing;
- branch/sequence;
- exceptions;
- outcome;
- data coverage;
- data freshness; and
- unresolved ambiguity.

### 9.2 Bottlenecks

A bottleneck is a persistent constraint in a flow, not simply one long interval.

For every queue-capable activity, derive:

```text
queue_wait = service_start - queue_entry
processing_time = service_complete - service_start - paused_time
total_flow_time = service_complete - request_time
```

Measure by pathway, step, location, shift, weekday, acuity, and relevant case mix:

- median, p75, p90, p95, and p99 wait;
- queue length over time;
- work in process;
- arrival rate;
- service start/completion rate;
- abandonment/cancellation;
- blocked duration;
- rework;
- utilization;
- changeover;
- downstream starvation; and
- upstream blocking.

A bottleneck signal should require:

- a stable queue boundary;
- reliable entry/start/complete timestamps;
- sufficient volume;
- repeated high waits or queue growth;
- comparison with a relevant baseline; and
- sensitivity to source missingness.

Object-centric relationships matter because the patient, task, room, device, and
team may have different clocks and constraints.

### 9.3 Insufficient resources

Wait time alone does not prove insufficient resources. The system may observe
delay without observing staffing, capacity, downtime, scheduling, demand mix, or
clinical priority.

A resource-insufficiency candidate should require co-occurring evidence such as:

- demand or arrivals exceed effective service capacity;
- queue length grows across a sustained interval;
- required role, location, device, or supply is unavailable;
- staffed capacity is below the approved need;
- utilization is persistently high with poor recovery;
- work is blocked specifically on resource assignment;
- delay improves when capacity becomes available; and
- the pattern remains after reasonable case-mix/priority stratification.

Model effective capacity, not nominal capacity:

```text
effective_capacity =
  staffed_and_available_units
  × availability_fraction
  × competency_match
  × service_calendar_fraction
```

Resource findings should be labeled:

- `observed_unavailability`;
- `capacity_mismatch_candidate`;
- `scheduling_mismatch_candidate`;
- `skill_mix_candidate`;
- `equipment_or_location_candidate`;
- `supply_candidate`;
- `demand_surge_candidate`; or
- `insufficient_evidence`.

### 9.4 Undue delay

An observed delay becomes “undue” only relative to:

- an approved clinical or operational expectation;
- patient-specific applicability;
- a valid anchor and clock;
- approved exceptions;
- adequate data coverage; and
- relevant clinical context.

Report:

- target step;
- expected interval;
- actual interval;
- signed variance;
- patient harm/risk linkage, if reviewed;
- queue/process decomposition;
- resource and barrier evidence;
- source coverage;
- confidence; and
- review status.

Separate:

- recognition delay;
- order delay;
- acceptance delay;
- scheduling delay;
- readiness delay;
- queue wait;
- transport delay;
- processing delay;
- result verification delay;
- acknowledgment delay;
- disposition delay; and
- documentation/ingestion delay.

### 9.5 Medical error and safety screening

A pathway deviation is not automatically a medical error. Appropriate care may
deviate because of contraindication, patient preference, a competing condition,
transfer history, goals of care, or an incomplete data feed. Conversely, harm can
occur even when the pathway was followed.

Zephyrus should produce a **safety screen** when approved logic detects:

- potentially omitted required care;
- potentially unsafe timing/order;
- medication/allergy or dose concern;
- unacknowledged critical result;
- unplanned return to procedure;
- unexpected escalation;
- transfer to higher acuity;
- selected complications;
- mortality or readmission signals;
- conflicting or corrected documentation; or
- a safety report linked to the patient episode.

The screen then enters governed review using evidence such as:

- chart review;
- incident report or AHRQ Common Formats-aligned record;
- medication administration and pharmacy evidence;
- procedure/case log;
- diagnostic result history;
- staffing/resource history;
- patient/family report;
- harm classification;
- preventability review; and
- reviewer rationale.

Allowed conclusions should distinguish:

- no event;
- data artifact;
- expected variation;
- justified exception;
- process deviation without harm;
- near miss;
- adverse event;
- preventability indeterminate;
- potentially preventable;
- confirmed preventable; and
- needs formal review.

The [AHRQ systems approach](https://psnet.ahrq.gov/primer/systems-approach) and
[root-cause analysis guidance](https://psnet.ahrq.gov/primer/root-cause-analysis)
emphasize system contributors and corrective action. Analytics can organize
evidence and generate hypotheses; it must not auto-assign individual blame or
claim causality.

## 10. Conformance strategy

### 10.1 Deterministic constraint evaluator

Use a deterministic evaluator first for:

- applicability;
- required/optional state;
- branch selection;
- accepted substitutions;
- event matching;
- order;
- temporal windows;
- repeat counts;
- missing/extra evidence;
- exception handling; and
- coverage.

This evaluator is reproducible, testable, explainable, and suitable for
patient-level evidence ledgers.

### 10.2 Declarative constraints

Many pathways are not simple sequences. Declarative constraints are appropriate
for:

- response/existence;
- precedence;
- coexistence;
- mutual exclusion;
- alternate response;
- bounded repeat;
- absence within a window; and
- branch-specific obligations.

The health-process conformance literature supports using multiple representations
because clinical pathways include flexibility, temporal constraints, and
exception-rich care. See the
[systematic review of conformance checking in healthcare](https://pubmed.ncbi.nlm.nih.gov/35525401/).

### 10.3 Object-centric alignments

Object-centric alignments can explain interactions across encounters, orders,
specimens, medications, procedures, tasks, and resources without flattening to
one case ID. However, synchronization-aware alignment has significant
computational complexity.

Use alignments for:

- retrospective explanation;
- disputed cases;
- sampled quality review;
- model validation;
- discovery of alternative observed paths; and
- cohort research.

Do not put expensive alignment in the immediate alert path. Research on
[object-centric alignments](https://arxiv.org/abs/2305.05113) and
[synchronization](https://arxiv.org/abs/2312.08537) supports their value while
also showing why scalability must be controlled.

### 10.4 Process discovery

Discovery answers “what usually happened,” not “what should happen.”

Use it to:

- find common variants;
- identify unmodeled operational steps;
- find repeated work and loops;
- locate handoff and queue patterns;
- compare sites or shifts;
- propose definition refinements; and
- identify missing source bindings.

Never promote a discovered path to a clinical standard without evidence review,
clinical approval, and versioning.

## 11. Data quality and confidence

### 11.1 Three dimensions

The harmonized data-quality framework described by
[Kahn and colleagues](https://pmc.ncbi.nlm.nih.gov/articles/PMC5051581/) organizes
quality into conformance, completeness, and plausibility. Each pathway result
should carry all three:

- **conformance:** type, format, value set, relationship, and source-contract
  validity;
- **completeness:** expected source, object, event family, field, and time-window
  coverage; and
- **plausibility:** temporal, logical, and distributional reasonableness.

### 11.2 Coverage is measure-specific

There is no single global completeness flag. For example:

- lab timing requires orders/specimens/results and timestamp roles;
- medication administration requires MAR coverage, not only medication orders;
- consult waiting requires request/accept/start/complete lifecycle;
- transport attribution requires a patient/encounter/work relationship;
- staffing inference requires shift/role/capacity data; and
- medical-error review requires safety and chart evidence.

Persist coverage by required evidence family and by measurement run.

### 11.3 Confidence should not hide missingness

Confidence can summarize:

- assignment certainty;
- binding specificity;
- identity certainty;
- timestamp precision;
- source freshness;
- event-match ambiguity;
- coverage;
- correction stability; and
- reviewer confirmation.

The interface must show the reasons. A single opaque confidence score is not
adequate for clinical or operational review.

## 12. Clinical validation and measure testing

### 12.1 Static definition validation

Before clinical approval:

- all IDs and references resolve;
- graph cycles are explicitly bounded;
- every required step has a binding or declared observability gap;
- all timing constraints have anchors and clocks;
- all branch predicates are typed;
- all terminology systems and versions are present;
- candidate and approved codes are separated;
- every rule has provenance;
- no retired code is silently accepted;
- exceptions name an authority and evidence requirement;
- measures have denominators and exclusions; and
- audience projections are explicit.

### 12.2 Synthetic executable fixtures

Every pathway version needs fixtures for:

- ideal on-time path;
- each valid branch;
- each approved alternative;
- each exception/contraindication;
- early event;
- late event;
- missing event;
- duplicate event;
- out-of-order event;
- corrected/canceled event;
- late-arriving source;
- cross-encounter episode;
- transfer from another institution;
- concurrent pathway;
- missing source coverage;
- ambiguous terminology match; and
- daylight-saving/timezone boundary where relevant.

Expected ledger rows must be asserted, not only a final score.

### 12.3 Gold cases and inter-rater agreement

For every pilot pathway:

1. define sampling strata, including normal and likely-deviation cases;
2. create a structured abstraction form;
3. blind at least two qualified reviewers for an initial subset;
4. measure agreement for applicability, step state, timing, exception, and
   safety-screen disposition;
5. adjudicate disagreements;
6. refine rules and bindings;
7. freeze a gold set;
8. evaluate sensitivity, specificity, positive predictive value, negative
   predictive value, and calibration where applicable; and
9. repeat after material source, terminology, or definition changes.

### 12.4 Measure evaluation

CMS measure-testing guidance emphasizes importance, scientific acceptability,
feasibility, and usability/use. The
[CMS evaluation criteria overview](https://mmshub.cms.gov/measure-lifecycle/measure-testing/evaluation-criteria/overview)
and its guidance on
[reliability](https://mmshub.cms.gov/measure-lifecycle/measure-testing/evaluation-criteria/scientific-acceptability/reliability)
and
[validity](https://mmshub.cms.gov/measure-lifecycle/measure-testing/evaluation-criteria/scientific-acceptability/validity)
are appropriate discipline even for internal measures.

For each metric, test:

- face/content validity;
- data-element validity;
- construct or criterion validity where possible;
- reliability at the intended unit of analysis;
- exclusion/exception reproducibility;
- missing-data sensitivity;
- risk-adjustment performance where claimed;
- unintended incentives;
- feasibility and source burden;
- interpretability;
- fairness; and
- actionability.

### 12.5 Shadow mode

No live operational or clinical consequence should occur during initial
validation. Shadow mode should:

- compute results without alerts or scorecards;
- compare with chart review and existing operational records;
- record false positives and false negatives;
- measure data latency and corrections;
- assess workload and alert burden;
- test cohort stability;
- identify service-line differences; and
- require an explicit activation decision.

## 13. Governance, safety, privacy, and fairness

### 13.1 Approval gates

Separate gates are required for:

1. source release acceptance;
2. narrative normalization;
3. terminology/coding applicability;
4. local observation binding;
5. clinical definition;
6. measure;
7. data quality;
8. shadow-mode validation;
9. staff-facing activation;
10. prospective alert activation; and
11. patient-facing content.

Passing one gate does not imply another.

### 13.2 Audience projections

The same approved definition can have governed projections:

- clinical reviewer detail;
- operational flow detail;
- executive aggregate;
- Arena analytical view;
- Patient Flow 4D overlay;
- Eddy explanation;
- Hummingbird patient education/status.

Raw research prose, candidate terminology, unsupported hypotheses, and
unadjudicated error screens must never flow directly to patient-facing or
assistant surfaces.

### 13.3 Misuse controls

Prohibit:

- individual clinician ranking from unvalidated pathway deviations;
- punitive interpretation without data-quality review;
- causal labels from correlation;
- comparison of units with materially different source coverage;
- small-cohort disclosure;
- patient-facing “error” or “noncompliance” labels;
- silent retroactive rescoring;
- guideline activation based on DRG coverage; and
- replacing clinician judgment with a model state.

### 13.4 Privacy

Apply:

- minimum-necessary access;
- purpose-based authorization;
- audit logs;
- encrypted transport and storage;
- role-limited patient-level evidence;
- de-identification for research where appropriate;
- cohort-size suppression;
- controlled export;
- retention rules; and
- explicit governance for safety reports and reviewer notes.

## 14. Work sequencing across all 250 pathways

### 14.1 Readiness lanes

The governed release identifies:

- **96 pathways** ready to enter institutional clinician signoff and
  normalization;
- **148 pathways** needing specialist review with documented limitations before
  normalization; and
- **6 pathways** needing redesign or explicit non-protocol status.

The six redesign/non-protocol candidates are:

1. rank 92 — Aftercare / Convalescence / Health Status Factors;
2. rank 140 — Extensive O.R. Procedures Unrelated to Principal Diagnosis — With
   MCC;
3. rank 162 — Non-extensive O.R. Procedures Unrelated to Principal Diagnosis;
4. rank 166 — Extensive O.R. Procedures Unrelated to Principal Diagnosis —
   Without MCC;
5. rank 169 — Aftercare, Musculoskeletal System and Connective Tissue; and
6. rank 221 — O.R. Procedures with Diagnoses of Other Contact with Health
   Services.

These administrative/heterogeneous families should not be forced into a
condition-specific protocol shape.

### 14.2 Technical workload profile

A lexical inventory of the 250 rows found:

- approximately 28,164 candidate clauses;
- median 125 candidate clauses per pathway;
- approximately 11,663 timing terms;
- median 43 timing terms per pathway;
- approximately 4,238 conditional terms;
- median 15 conditional terms per pathway;
- 122 pathways in a `very_high` technical-workload heuristic;
- 107 in `high`; and
- 21 in `moderate`.

This is a workload heuristic only. It is not clinical severity, quality, risk, or
activation readiness.

The companion
[250-pathway worklist](./OCEL-CARE-PATHWAY-250-MODELING-WORKLIST-2026-07-24.md)
provides one row per pathway with:

- care type and service line;
- readiness lane;
- source specificity;
- technical normalization complexity;
- clause/timing/conditional inventory;
- terminology candidate counts;
- binding focus; and
- minimum object-centric scope.

### 14.3 Recommended factory workflow

For each pathway:

1. **Intake:** freeze source row, claims, citations, crosswalk, and release
   identity.
2. **Scope:** define institution, population, care setting, and owners.
3. **Evidence review:** confirm current primary guidance and local policy.
4. **Narrative decomposition:** split into atomic candidate rules.
5. **Clinical modeling:** define stages, branches, timing, alternatives, and
   exceptions.
6. **Terminology review:** approve semantic value sets and local maps.
7. **Observation design:** specify source fields, timestamp roles, objects, and
   correction behavior.
8. **Resource design:** define queue/resource states where analysis is intended.
9. **Measure design:** define process, outcome, balancing, safety, and coverage
   measures.
10. **Static validation:** run graph, schema, provenance, and binding checks.
11. **Synthetic testing:** execute branch, exception, timing, and source-failure
    fixtures.
12. **Clinical approval:** approve definition and measures separately.
13. **Gold-set validation:** compare with structured chart review.
14. **Shadow mode:** run retrospectively with no care consequence.
15. **Operational review:** assess usability, bias, workload, and false signals.
16. **Activation:** release only the approved audience projection.
17. **Monitoring:** track data drift, code changes, definition aging, and
    performance.

### 14.4 Minimum pathway team

Each pathway needs named participation from:

- specialty clinician owner;
- nursing representative;
- pharmacy representative where medication rules exist;
- laboratory/radiology/procedure representative as applicable;
- health information management/coding;
- clinical informatics;
- terminology specialist;
- process/operations owner;
- data engineer;
- process-mining/measurement analyst;
- quality and patient safety;
- privacy/security;
- patient/family representative where appropriate; and
- product/human-factors owner.

## 15. Implementation roadmap

### Phase 0 — Accept the expanded source release

Deliver:

- separate release manifest for the expanded files;
- hashes and semantic-core reconciliation evidence;
- immutable raw import;
- terminology candidate tables;
- candidate-versus-approved separation;
- release controls and disposition; and
- documentation that no clinical activation occurred.

Exit criteria:

- reproducible import;
- all 250 rows reconciled;
- all 10,588 crosswalk rows traceable;
- zero silent overwrites of the earlier release; and
- governance owner accepts the derivative identity.

### Phase 1 — Harden identity and OCEL projection

Deliver:

- source/record/version lineage;
- complete timestamp roles;
- qualified object links;
- bounded full-content digests;
- correction/retraction reconciliation;
- object attribute history protection;
- source coverage/freshness service;
- orphan-event quarantine; and
- replay/idempotency tests.

Exit criteria:

- correction and late-arrival fixtures pass;
- source-window reconciliation is exact;
- patient/encounter/work-object coverage is measurable;
- barrier lifecycle includes resolution/cancellation; and
- no pathway status depends on a source family with unknown coverage.

### Phase 2 — Build the pathway modeling workbench

Deliver:

- typed definition schema;
- applicability editor;
- graph/branch editor;
- temporal-constraint editor;
- exception/resource editor;
- terminology candidate review queue;
- local binding editor;
- provenance view;
- measure editor;
- validation engine;
- version/digest workflow; and
- approval gates.

Exit criteria:

- one pathway can be modeled end to end;
- every rule traces to evidence and reviewer;
- invalid graphs cannot be approved;
- candidate codes cannot be activated as approved bindings; and
- synthetic fixtures are executable.

### Phase 3 — Pilot a deliberately diverse set

Select three to five pilots based on observability and institutional priority,
not only DRG rank. Include:

- one high-specificity medical pathway with reliable lab/medication evidence;
- one surgical pathway with complete procedure/case/resource lifecycle;
- one pathway with meaningful consult/queue measurement;
- one obstetric or neonatal pathway to test dyad/linked-patient objects; and
- optionally sepsis for technical continuity, while treating existing seeded
  events as demonstration data rather than clinical validation.

Exit criteria:

- definition and measure approval;
- local binding completeness;
- gold-set performance targets met;
- object-centric evidence review usable;
- false signals understood; and
- no live alerting.

### Phase 4 — Build the measurement ledger and shadow operations

Deliver:

- patient assignment/confirmation;
- version pinning;
- deterministic evaluator;
- event-match evidence;
- step and constraint ledger;
- coverage/confidence;
- superseding runs;
- retrospective dashboards;
- reviewer workflow; and
- aggregate measures with cohort suppression.

Exit criteria:

- every summary drills to immutable evidence;
- replay is reproducible;
- late data creates a superseding run;
- data-unavailable is not counted as missed care;
- clinical reviewers agree results are interpretable; and
- no punitive use is permitted.

### Phase 5 — Bottleneck, resource, and safety evidence

Deliver:

- queue lifecycle events;
- resource/capacity objects;
- wait-segment decomposition;
- capacity-mismatch candidates;
- safety-screen workflow;
- Common Formats-aligned evidence where applicable;
- contributing-factor graph;
- corrective-action tracking; and
- offline object-centric alignment.

Exit criteria:

- wait and processing time can be separated;
- resource insufficiency requires capacity evidence;
- safety screen is distinct from error adjudication;
- cause remains a reviewed hypothesis until supported; and
- corrective actions have owners and effectiveness measures.

### Phase 6 — Scale by readiness

Scale:

1. approved pilots;
2. remaining pathways in the 96-path signoff lane;
3. pathways from the 148 specialist-review lane after limitations are resolved;
4. redesigned models, measures, registries, or non-protocol classifications for
   the six heterogeneous families.

Do not claim that “99% DRG coverage” means:

- 99% clinical-event coverage;
- 99% patient applicability;
- 99% clinically approved pathways;
- 99% terminology completeness;
- 99% source observability; or
- 99% measurement validity.

## 16. Priority repository changes

The current implementation should be evolved in this order:

1. replace weak source signatures with bounded content digests;
2. correct projection reconciliation and retraction behavior;
3. add source-coverage contracts and reports;
4. populate governed local terminology maps;
5. implement typed pathway definition entities;
6. replace hard-coded pathway rule functions with versioned definitions;
7. replace first-timestamp-per-activity logic with lifecycle-aware event matching;
8. add patient-pathway assignment and version pinning;
9. add measurement-run and step-ledger persistence;
10. implement correction/supersession;
11. expose evidence-first reviewer APIs;
12. add queue/resource models;
13. add safety-screen and review workflow; and
14. add offline object-centric alignments only after deterministic evaluation is
    proven.

The existing approved-catalog read service and append-only patient history are
good boundaries to retain. They should remain the only route by which governed
pathway projections reach downstream applications.

## 17. Decisions required before implementation

Institutional owners must decide:

1. Who owns clinical definition approval?
2. Who approves terminology bindings separately from clinical content?
3. What qualifies a patient for each pathway, and when is confirmation required?
4. How are concurrent pathways prioritized or combined?
5. What clock semantics apply to each class of timing rule?
6. Which exceptions remove an obligation from a denominator?
7. What local sources prove each expected observation?
8. What data-coverage threshold permits a “missing” conclusion?
9. What gold-set performance is required for staff-facing use?
10. Which measures are descriptive, operational, quality-improvement, or safety
    measures?
11. What evidence is required before calling a delay undue?
12. What evidence is required before suggesting resource insufficiency?
13. Who may adjudicate a safety screen and label preventability?
14. What cohort sizes and stratifiers are allowed?
15. Which outputs can reach Arena, 4D, Eddy, and Hummingbird?
16. What is the code/version update policy for LOINC, SNOMED CT, ICD-10-CM, CPT,
    and local terminologies?
17. What is the definition review/retirement cadence?
18. What are the performance and latency requirements?
19. What is the rollback/disable process for a faulty definition or binding?
20. How will corrective-action effectiveness be measured?

## 18. Definition of done for one pathway

A pathway is not ready because its row was imported. It is ready only when:

### Source and governance

- source release and row lineage are immutable;
- evidence claims are traceable;
- clinical and operational owners are named;
- scope and effective period are explicit;
- the version is approved; and
- audience projections are separately governed.

### Computable definition

- applicability and exclusions are executable;
- stages, steps, goals, and edges are typed;
- all branches and repeats are explicit;
- temporal anchors and clocks are defined;
- exceptions and substitutions are approved;
- resource requirements exist where resource conclusions will be made;
- measures are complete; and
- every rule has provenance.

### Terminology and observation

- candidate terminology has been reviewed;
- local codes are mapped;
- system/version and semantic role are explicit;
- source resource/field/status/time role is defined;
- identity and object relationships are proven;
- corrections/retractions are supported; and
- coverage/freshness thresholds are approved.

### Measurement

- patient assignment and confirmation are auditable;
- exact version is pinned;
- deterministic matching is reproducible;
- all step states are represented;
- signed timing variance is persisted;
- exceptions remain visible;
- insufficient data is distinct from failure;
- superseding runs preserve history; and
- summaries drill to event evidence.

### Validation

- static validation passes;
- all required synthetic fixtures pass;
- chart-reviewed gold cases meet approved performance;
- inter-rater reliability is acceptable;
- shadow-mode source latency and false signals are understood;
- fairness/missingness analysis is complete;
- human-factors review is complete; and
- activation is explicitly authorized.

### Analysis and safety

- bottlenecks use queue/process lifecycle evidence;
- resource conclusions use capacity evidence;
- deviation is not equated with error;
- safety screens enter governed review;
- causal language is controlled;
- corrective actions have owners and measures; and
- no individual punitive scoring is enabled.

## 19. Research standards and interoperability references

The design is grounded in:

- [OCEL 2.0 standard and specification](https://www.ocel-standard.org/)
- [HL7 Clinical Practice Guidelines Implementation Guide](https://hl7.org/fhir/uv/cpg/)
- [HL7 CPG methodology](https://www.hl7.org/fhir/uv/cpg/methodology.html)
- [FHIR PlanDefinition](https://hl7.org/fhir/plandefinition.html)
- [FHIR Quality Measure Implementation Guide](https://hl7.org/fhir/uv/cqm/STU1/)
- [QI-Core Implementation Guide](https://hl7.org/fhir/us/qicore/STU7.0.1/)
- [FHIR MeasureReport](https://www.hl7.org/fhir/R5/measurereport-definitions.html)
- [FHIR ServiceRequest](https://fhir.hl7.org/fhir/servicerequest.html)
- [FHIR Task](https://hl7.org/fhir/task.html)
- [FHIR Schedule](https://www.hl7.org/fhir/schedule.html)
- [FHIR Provenance](https://hl7.org/fhir/provenance.html)
- [FHIR Bulk Data Access](https://hl7.org/fhir/uv/bulkdata/)
- [CMS measure evaluation criteria](https://mmshub.cms.gov/measure-lifecycle/measure-testing/evaluation-criteria/overview)
- [AHRQ root-cause analysis](https://psnet.ahrq.gov/primer/root-cause-analysis)
- [AHRQ systems approach](https://psnet.ahrq.gov/primer/systems-approach)
- [AHRQ Common Formats and Network of Patient Safety Databases](https://www.ahrq.gov/npsd/how-does-npsd-work/index.html)
- [AHRQ Patient Safety Indicator technical specifications](https://qualityindicators.ahrq.gov/measures/PSI_TechSpec)
- [Harmonized data quality framework](https://pmc.ncbi.nlm.nih.gov/articles/PMC5051581/)
- [Systematic review of conformance checking in healthcare](https://pubmed.ncbi.nlm.nih.gov/35525401/)
- [Care-pathway process-mining review](https://pmc.ncbi.nlm.nih.gov/articles/PMC8851723/)
- [LOINC](https://loinc.org/)
- [SNOMED CT logical model](https://docs.snomed.org/snomed-ct-practical-guides/snomed-ct-starter-guide/5-snomed-ct-logical-model)
- [CDC ICD-10-CM](https://www.cdc.gov/nchs/icd/icd-10-cm/index.html)
- [CMS OPPS quarterly addenda](https://www.cms.gov/medicare/payment/prospective-payment-systems/hospital-outpatient-pps/quarterly-addenda-updates)

## Final recommendation

Proceed with a governed pathway-modeling and measurement program, not a bulk
conversion exercise.

Accept the terminology-expanded package as a separately manifested, lossless
derivative. Preserve its terminology as candidate evidence. Harden the observed
OCEL and local source bindings first. Build a typed, versioned definition package
for every pathway. Measure each confirmed patient instance with a reproducible
step-and-constraint ledger. Keep source coverage, exceptions, corrections, and
uncertainty visible. Use deterministic evaluation for the primary result,
object-centric alignment for controlled retrospective explanation, and process
discovery only to learn how care actually flows.

Most importantly, keep the claims proportional to the evidence:

- a missing event is not necessarily missed care;
- a late step is not necessarily an undue delay;
- a long wait is not necessarily insufficient resources;
- a deviation is not necessarily an error;
- a correlation is not a root cause; and
- a terminology match is not clinical approval.

That separation is what makes the resulting OCEL program useful for improvement
without turning incomplete data or research prose into unsafe clinical
conclusions.
