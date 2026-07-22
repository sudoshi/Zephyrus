# DRG Care Pathways: Zephyrus, Eddy, and Hummingbird Integration Plan

**Date:** 2026-07-21

**Status:** Source and verification package imported; canonical release adopted inactive; evidence review reconciled; no clinical pathway approved or activated

**Sources:** `DRG_Care_Pathways_250_PATHWAYS_99PCT_v43_1.csv` and `DRG_Care_Pathways_250_Verification_Package_v43_1.xlsx`

**Scope:** Preserve and govern the 250-pathway catalog, convert approved content into versioned computable pathway definitions, assign the right pathway to an encounter without treating a retrospective DRG as an admission diagnosis, and keep patients, representatives, and care teams continuously aware through Zephyrus, Virtual Rounds, Hummingbird Staff, Hummingbird Patient, and governed Eddy assistance.

---

## 1. Executive Decision

Importing the CSV and its evidence-verification package is complete. Product activation is not.

Zephyrus should treat the file as a **source knowledge release**, not as an order set, diagnosis engine, or patient-facing content store. The target product has four distinct layers:

1. **Source catalog:** the immutable CSV snapshot, workbook worksheets, claim audit, source index, change log, completeness resolutions, and import lineage in `raw`.
2. **Evidence-reviewed candidates:** the workbook's automated verification conclusions and explicit limitations, still inactive and not institutionally approved.
3. **Approved pathway definitions:** versioned, structured, locally reviewed clinical knowledge in a new `care_pathways` domain.
4. **Encounter pathway instances:** a clinician-confirmed plan for one encounter, with milestones, goals, deviations, education, tasks, acknowledgements, and version history.
5. **Audience projections:** separate staff and patient releases derived from the same approved instance, never from raw CSV/workbook content at request time.

The core safety rules are:

- A DRG is a coverage and retrospective reconciliation signal. It is not sufficient by itself to decide why a patient was admitted or which pathway applies.
- A pathway version is not clinically usable merely because its citations resolve or its DRG taxonomy is correct.
- Automated evidence review is now complete for all 250 rows, but it produces two materially different cohorts: 96 are evidence-verified and ready to enter institutional signoff, while 154 retain documented limitations and require specialist review; 6 of those 154 require redesign or an explicit non-protocol designation.
- All 250 rows explicitly remain `Not clinically approved — institutional SME signoff required`. Automated source resolution and adversarial rule checks do not satisfy physician, nursing, pharmacy, coding, utilization-management, patient-safety, or local policy approval.
- Patient-facing wording is independently authored, health-literacy reviewed, translated, approved, and released. Raw clinical prose is never exposed directly.
- Every active encounter is pinned to an exact pathway version. A new catalog release never silently changes an in-progress patient's plan.
- Care teams see applicability, evidence, source age, verification tier, local approval, deviations, owners, and uncertainty—not a false “standard of care” badge.
- Eddy retrieves only approved pathway content. Patient-specific Eddy context is authorization-scoped, local-only, ephemeral, and never stored in the global knowledge corpus.
- Eddy may explain, compare structured evidence, identify missing inputs, and draft. It may not diagnose, order, sign a clinical contribution, attest comprehension, or activate a pathway.
- Hummingbird Patient continues to consume only governed `patient_experience.encounter_projections` under the separate patient identity and release-policy boundary.

This structure follows the HL7 Clinical Practice Guidelines implementation approach: keep the patient's state (**Case**), the clinical guidance (**Plan**), and the delivery process (**Workflow**) distinct, then connect them with governed projections. [HL7 CPG-on-FHIR 2.0.0](https://hl7.org/fhir/uv/cpg/) describes computable guideline artifacts and an iterative, multi-stakeholder knowledge lifecycle; its approach explicitly separates case, plan, and workflow concerns. [HL7 CPG approach](https://www.hl7.org/fhir/uv/cpg/approach.html)

---

## 2. Work Completed on 2026-07-21

### 2.1 Source file controls

| Control             | Verified result                                                    |
| ------------------- | ------------------------------------------------------------------ |
| File                | `DRG_Care_Pathways_250_PATHWAYS_99PCT_v43_1.csv`                   |
| Encoding            | UTF-8 CSV                                                          |
| File size           | 4,038,979 bytes                                                    |
| SHA-256             | `2e3ac28238cdb8d7e1002117de6ad824d71882dae54df77fe4abd214b268a6ae` |
| Header columns      | 49                                                                 |
| Data rows           | 250                                                                |
| Row widths          | 250 of 250 rows contain exactly 49 fields                          |
| Rank integrity      | Unique, sequential `1..250`                                        |
| Condition integrity | 250 distinct nonblank condition labels                             |
| Full-row duplicates | 0                                                                  |

### 2.2 Verification workbook controls

| Control                   | Verified result                                                    |
| ------------------------- | ------------------------------------------------------------------ |
| Workbook                  | `DRG_Care_Pathways_250_Verification_Package_v43_1.xlsx`            |
| File size                 | 2,053,601 bytes                                                    |
| SHA-256                   | `42cadf84dce297c5a839784148ebd2c5375320350394c0d143411008ed5bd171` |
| Worksheets                | 8                                                                  |
| Pathway and ledger rows   | 250 each                                                           |
| Claim-audit rows          | 10,123                                                             |
| Source-index rows         | 811 unique PMIDs                                                   |
| Change-log rows           | 324                                                                |
| CMS codebook rows         | 770                                                                |
| Formulas/errors           | 0 formulas; no stored spreadsheet error cells                      |
| Workbook baseline SHA-256 | `6819c1e111985da1fc62f38cdd85dd2a34b69308f4cf9be9f8941fbce62bf8fd` |

The workbook's declared baseline file exists at its recorded build path and matches that SHA exactly. The repository CSV already imported into PostgreSQL uses a different byte representation, but semantic reconciliation found only 250 date-display changes (`2026-07-21` versus `7/21/26`) and 21 whole-number percentage renderings (`9.0` versus `9`). Ranks, conditions, DRGs, volumes, and every clinical-operational field match.

The workbook contains no formula layer; its QA results are static release assertions. Zephyrus therefore recomputes the critical counts and cross-sheet relationships during import rather than trusting the `QA_Summary` cells.

### 2.3 Live PostgreSQL imports

The source is now present in the Zephyrus database at `pgsql.acumenus.net`, database `zephyrus`, in an isolated raw snapshot:

- Data table: `raw.drg_care_pathways_v43_1_20260721`
- Import manifest: `raw.drg_care_pathway_imports`
- Manifest dataset key: `drg-care-pathways-250-v43.1-20260721`
- Manifest import id: `1`
- Imported at: `2026-07-21 18:04:54.536343-04`
- Imported by: `smudoshi`
- Imported rows: `250`
- Source columns: `49`
- Imported volume control total: `32,967,000`
- Final coverage control: `99.0%`

The raw table includes source file name, source SHA-256, generated source row number, importer, and import time. Numeric fields are typed; all narrative fields are retained as text. A post-import round trip compared every source field on every row. All 12,250 field values matched semantically; PostgreSQL only normalized 21 whole-number percentage strings such as `9` to `9.0`.

The table comment states that the snapshot is not approved for direct clinical activation. No data was inserted into `prod`, `patient_experience`, `rounds`, `eddy`, or an application-serving pathway table.

The verification package is also present as a separate, immutable raw release:

- Verification manifest: `raw.drg_care_pathway_verification_imports`
- Verification release id: `1`
- Dataset key: `drg-care-pathways-verification-package-v43.1-20260721`
- Imported at: `2026-07-21 18:47:20.451068-04`
- Verified pathways: `raw.drg_cp_verified_pathways_v43_1_20260721`
- Verification ledger: `raw.drg_cp_verification_ledger_v43_1_20260721`
- Claim audit: `raw.drg_cp_claim_audit_v43_1_20260721`
- Source index: `raw.drg_cp_source_index_v43_1_20260721`
- Source enrichment: `raw.drg_cp_source_enrichment_v43_1_20260721`
- Complete source view: `raw.drg_cp_source_index_complete_v43_1_20260721`
- Change log: `raw.drg_cp_change_log_v43_1_20260721`
- MS-DRG codebook: `raw.drg_cp_ms_drg_codebook_v43_1_20260721`
- Methodology and QA snapshots: `raw.drg_cp_methodology_v43_1_20260721` and `raw.drg_cp_qa_summary_v43_1_20260721`
- Completeness audit: `raw.drg_cp_completeness_audit_v43_1_20260721`
- Activation status: `raw_verification_package_only`
- Clinical signoff complete: `false`

The import transaction independently enforced worksheet counts, verification/disposition totals, volume controls, pathway/ledger identity, claim-to-pathway identity, claim-PMID/source-index coverage, exact pathway/codebook DRG-set equality, and equality of all unchanged clinical-operational fields against the first raw import.

### 2.4 Live application-state snapshot

The production database snapshot observed during this work is important to sequencing:

- `rounds` is deployed and populated: 2 templates, 145 runs, and 2,474 round-patient rows.
- `patient_experience` identity and projection-kernel migrations through `2026_07_19_000200` are applied.
- `patient_experience.release_policy_versions` contains 0 rows.
- `patient_experience.encounter_projections` contains 0 rows.
- Later patient messaging, pathway-event, discharge-readiness, and rounds-summary migrations exist in the working tree but are not recorded as applied in the live migration ledger.
- There was no pre-existing database table with `pathway`, `care_plan`, or `drg` in its name before this import.

The plan therefore separates **schema implementation**, **projection-pipeline implementation**, and **clinical activation**. A successful migration is not a release-policy approval.

### 2.5 Repository implementation status

The first non-serving vertical slice now exists in the repository:

- `database/migrations/2026_07_21_000900_create_care_pathway_catalog.php` creates the additive canonical catalog/governance schema;
- `config/care-pathways.php` pins this source release and defaults every serving flag off;
- `CatalogReconciliationService` recomputes release, status, DRG, claim/source, volume, and completeness controls from the deployed raw relations;
- `CatalogImportService` adopts a reconciled release atomically as inactive/unapproved and records complete release-to-source membership independently from claim citation;
- an append-only source-status ledger corrects and tracks later retractions/supersessions without overwriting imported bibliography rows;
- `ApprovedPathwayCatalogReadService` exposes only active, effective, institutionally approved staff content, preserves DRG ambiguity, blocks versions linked to any non-current source, and never returns raw snapshots or source prose;
- `care-pathways:adopt-raw-release` provides dry-run, accountable-actor, idempotent adoption, and conflicting-hash rejection;
- a complete model set now covers every canonical catalog, authoring, evidence, provenance, crosswalk, review, approval, and event authority;
- `CatalogGovernanceReadService`, its controller, dedicated route file, and feature-gate middleware expose a staff-only governance read surface without opening the approved serving boundary;
- eight explicit capabilities separate catalog view, source adoption, content authoring, evidence review, clinical approval, release activation, encounter-pathway view, and instance management; and
- isolated PostgreSQL schema/adoption tests cover the inactive default, exact control model, append-only claims, version immutability, premature activation, idempotency, and rollback-before-write behavior.

The initial import slice intentionally added no application API. The subsequent repository-only governance slice adds GET-only staff review endpoints behind `CARE_PATHWAYS_GOVERNANCE_ENABLED=false`, session authentication, `viewCarePathwayCatalog`, and a 30-request/minute limit. The service itself repeats the feature gate, so internal callers also fail closed. It exposes inactive source material only to authorized governance staff and never makes it approved or executable. There are still no patient, Hummingbird, Rounds, 4D, Eddy, assignment, instance, writeback, review-mutation, approval-mutation, activation, or withdrawal serving paths.

The governance DTO boundary returns exact release/version identity, content digests, grouper version, source cutoff, current-source status, and the warning “Automated evidence verification is not institutional clinical approval.” It omits version `raw_snapshot`, source/completeness raw records, and provenance blobs. The approved serving service now also returns `exact_version=true` and `source_cutoff_date`, while continuing to expose only approved staff text from an active, signed-off release.

The slice is now deployed to the live Zephyrus database as canonical catalog release `2`. PostgreSQL sequence `1` was consumed by a deliberately interrupted first transaction while the importer was being optimized; PostgreSQL sequences are non-transactional, but all release/content rows rolled back and live verification confirmed zero partial rows. The batched retry completed atomically and an exact rerun returned the same release without duplicates.

Live canonical controls after adoption:

| Control                              | Result                                                                 |
| ------------------------------------ | ---------------------------------------------------------------------- |
| Release state                        | `inactive`; clinical signoff false                                     |
| Definitions / versions               | 250 / 250; all versions inactive and institutionally not reviewed      |
| Source sections                      | 7,000; all source-candidate with `approved_text = NULL`                |
| DRG codebook / associations          | 770 / 802; all 32 overlapping DRG codes preserved                      |
| Evidence claims / claim-source links | 10,123 / 53,994                                                        |
| Sources / changes                    | 811 / 324                                                              |
| Release source membership            | 811 / 811; 795 claim-cited and 16 uncited but fully preserved          |
| Current source status                | 811 current; 0 residual non-current after append-only negation repair  |
| Source resolution records            | 16, normalized into 20 field-level facts                               |
| Completeness audit                   | 7 controls; residual unknown/unclassified count 0                      |
| PMID `38349295`                      | 5 field-level enrichment facts; title/author/journal/date/type present |
| No-personal-author records           | 15 explicit `not_listed_by_pubmed` facts                               |
| Service-line crosswalk               | 26 labels; 3 mapped and 23 intentionally pending institutional review  |
| Active / institutionally approved    | 0 / 0                                                                  |

Seven follow-on migrations add exact field-level enrichment/completeness columns, current read views, append-only source-status history, the retraction-negation repair, complete release-source membership, append-only section citations, and membership/digest enforcement. Historical generic facts from the initial canonical adoption remain append-only, but `current_source_enrichments` and `current_completeness_resolutions` expose only normalized, digest-addressed facts. All 811 imported source rows remain immutable: the original parser defect is retained as history while 811 corrective `current` events and a superseding 811/811 release control govern reads. No raw, source, or canonical clinical content was deleted or overwritten.

---

## 3. Deep Dataset Assessment

### 3.1 Coverage and ordering

| Measure                           |     Result | Interpretation                                                            |
| --------------------------------- | ---------: | ------------------------------------------------------------------------- |
| Pathways                          |        250 | Condition-level entries, not a one-row-per-DRG table                      |
| Approximate annual discharges     | 32,967,000 | 99.0% of the file's 33.3M planning denominator                            |
| Rank 1-130 volume                 | 30,748,000 | 92.3% of the planning denominator                                         |
| Rank 131-250 volume               |  2,219,000 | Smoothed tail allocation from 92.4% through 99.0%                         |
| Volume ordering violations        |          0 | Approximate discharge counts are non-increasing by rank                   |
| Cumulative percentage decreases   |          0 | Coverage is monotonic                                                     |
| Cumulative rounding discrepancies |          1 | Rank 89 stores 87.9%; arithmetic on the 33.3M denominator rounds to 88.0% |

`approx_annual_discharges` is a national planning/prioritization field, not a local census forecast. The last 120 rows explicitly state that their allocation is smoothed and not an observed all-payer code-level count. Local rollout priority must be recalculated from Zephyrus encounter/claims history and cannot simply copy the file's rank.

### 3.2 DRG taxonomy

| Measure                                             | Result |
| --------------------------------------------------- | -----: |
| DRG mentions across rows                            |    802 |
| Unique three-digit DRG codes                        |    770 |
| Minimum codes per pathway                           |      1 |
| Maximum codes per pathway                           |     16 |
| DRG codes reused by more than one condition pathway |     32 |
| Pathway rows containing at least one reused code    |     23 |

The 770 CSV codes exactly match the 770 codes on the CMS v43.1 “List of MS-DRGs” page, with no missing or extra code. [CMS MS-DRG v43.1 list](https://www.cms.gov/icd10m/FY2026-fr-v43.1-fullcode-cms/fullcode_cms/P0392.html)

CMS's v43.1 design PDF states an effective period of April 1 through September 30, 2026 and describes 772 total MS-DRGs, while the official list page exposes 770 distinct codes. [CMS Design and Development of the DRG](https://www.cms.gov/icd10m/FY2026-fr-v43.1-fullcode-cms/fullcode_cms/Design_and_Development_of_the_Diagnosis_Related_Group_%28DRGs%29.pdf) The CSV matches the official list, but this two-code difference between CMS artifacts must remain a documented release-control discrepancy and be rechecked against each future grouper release.

Examples of intentional overlaps include:

- `177-179`: COVID-19 and respiratory infections/inflammations.
- `637-639`: diabetes with complications and hyperglycemia/diabetes without complication.
- `391-392`: miscellaneous digestive disorders and gastroparesis/nausea/vomiting.
- `896-897`: substance use disorders and substance-induced disorders/withdrawal.
- `882-883`: depressive/mood disorders and childhood/adjustment/anxiety disorders.

Consequences:

- `pathway_drg_mappings` must be many-to-many.
- A DRG lookup returns candidate pathways, not one definitive pathway.
- The UI must display why a candidate matched and what additional clinical confirmation is required.
- Final DRG assignment may validate or challenge a pathway after coding, but must not rewrite the clinical history.

### 3.3 Automated evidence verification is complete; clinical approval is not

The verification package replaces the original 16/114/120 research-progress labels with a completed two-pass automated evidence review. It intentionally keeps evidence status, limitations, release disposition, and institutional signoff as separate dimensions.

| Evidence status                                                       | Rows | Approximate volume | Share of 33.3M denominator | Meaning                                                                                             |
| --------------------------------------------------------------------- | ---: | -----------------: | -------------------------: | --------------------------------------------------------------------------------------------------- |
| `Evidence verified — automated independent review complete`           |   96 |         24,951,000 |                      74.9% | May enter institutional signoff; still inactive and not clinically approved                         |
| `Verification complete with limitations — clinician signoff required` |  154 |          8,016,000 |                      24.1% | Requires specialist adjudication of the recorded evidence, heterogeneity, currency, or template gap |

| Release disposition                                     | Rows | Approximate volume | Share of 33.3M denominator | Required next action                                                |
| ------------------------------------------------------- | ---: | -----------------: | -------------------------: | ------------------------------------------------------------------- |
| Ready for institutional clinician signoff               |   96 |         24,951,000 |                      74.9% | Full local review matrix                                            |
| Ready for specialist review with documented limitations |  148 |          7,870,000 |                      23.6% | Resolve named limitations before multidisciplinary signoff          |
| Needs pathway redesign or explicit non-protocol status  |    6 |            146,000 |                       0.4% | Do not translate into an executable or patient-facing pathway as-is |

All 250 rows have `clinical_signoff_status = Not clinically approved — institutional SME signoff required`. The 10,123 claim-audit rows likewise have `clinical_adjudication = Pending institutional SME signoff`. This is the controlling release boundary even when evidence status is green.

The status transition from the original CSV is:

| Original status                                                | Automated evidence verified | Verified with limitations | Total |
| -------------------------------------------------------------- | --------------------------: | ------------------------: | ----: |
| `Verified`                                                     |                          14 |                         2 |    16 |
| `Not yet verified (verification pass pending)`                 |                          82 |                        32 |   114 |
| `Research complete; independent clinical verification pending` |                           0 |                       120 |   120 |
| **Total**                                                      |                          96 |                       154 |   250 |

The original 120-row tail remains a deliberately conservative MDC-level template:

- 120 identical risk-stratification blocks.
- 120 identical day-1, day-2, day-3+, monitoring, nutrition/mobility/VTE, discharge criteria, discharge planning, quality metric, readmission-driver, evidence-grade, and pathway-pearl blocks.
- 120 rows explicitly state that the cited guideline is not asserted as condition-specific.
- 120 rows explicitly require local validation of diagnosis-specific thresholds, medications, procedure timing, LOS, and metrics.
- 71 tail rows share one broad procedural admission template; 49 share one broad medical template.

The completed source review makes these rows better governed **coverage inventory and specialist work queues**. It does not convert them into 120 distinct clinical protocols.

### 3.4 Review queues and important reclassifications

The package inventories 10,123 high-stakes claim segments across all 250 pathways:

- 5,323 passed the rule-based consistency pass without a detected inconsistency.
- 4,800 have an explicit limitation requiring specialist adjudication.
- Claim categories include 4,200 high-stakes operational claims, 1,406 LOS claims, 1,405 risk-score/threshold claims, 969 quality-measure claims, 938 time targets, 723 medication/treatment claims, and 482 fluid/transfusion claims.
- All claims link to source PMIDs, and every referenced PMID exists in the imported source index.

The verification-confidence distribution is 129 medium and 121 low. Source specificity is 55 high, 156 medium, and 39 low. Evidence verification must therefore remain visible as structured metadata rather than collapsing into one green badge.

Two of the original 16 `Verified` rows were moved into the limitations queue:

- Rank 13, Diabetes with Complications, because its DRGs overlap rank 93.
- Rank 29, Intestinal Obstruction, because no guideline/source later than 2020 was selected and currency requires specialist confirmation.

The six pathways requiring redesign or an explicit non-protocol status are:

1. Rank 92 — Aftercare / Convalescence / Health Status Factors.
2. Rank 140 — Extensive O.R. Procedures Unrelated to Principal Diagnosis — With MCC.
3. Rank 162 — Non-extensive O.R. Procedures Unrelated to Principal Diagnosis.
4. Rank 166 — Extensive O.R. Procedures Unrelated to Principal Diagnosis — Without MCC.
5. Rank 169 — Aftercare, Musculoskeletal System and Connective Tissue.
6. Rank 221 — O.R. Procedures with Diagnoses of Other Contact with Health Services.

These six should default to `non_protocol_candidate` until a service-line owner demonstrates that a coherent, clinically actionable pathway can be defined. Their administrative or heterogeneous DRG families are not suitable labels for a patient journey.

### 3.5 Completeness, enrichment, and provenance

- All required pathway fields are populated across all 250 rows.
- `legacy_drg_codes` is blank in 115 rows. This field is optional: a blank means no retired predecessor code was recorded. Zephyrus preserves the raw null and adds `legacy_drg_codes_status = no_legacy_code_recorded_not_inferred`; it does not fabricate a crosswalk.
- The verification package expands the normalized source registry from the original prose's 704 unique PMID mentions to 811 source-index records.
- The change log contains 324 entries: 250 verification-status reclassifications and 74 source/citation enrichment bundles. No admission, risk, workup, intervention, management, milestone, monitoring, discharge, complication, or readmission field changed.
- Sixteen source-index rows had blank `first_author` values. NCBI XML supplies no personal AuthorList for 15, so they are explicitly classified `not_listed_by_pubmed` rather than assigned invented authors.
- PMID 38349295 lacked title, author, journal, publication date, and publication type in the workbook source index. Zephyrus preserves the raw row and adds a separate enrichment record: _Bacterial Keratitis Preferred Practice Pattern®_, first author Rhee MK, _Ophthalmology_, April 2024, Practice Guideline, DOI `10.1016/j.ophtha.2023.12.035`. The [PubMed record](https://pubmed.ncbi.nlm.nih.gov/38349295/) and [publisher article](https://www.aaojournal.org/article/S0161-6420%2824%2900007-1/fulltext) support that metadata.
- The completeness audit reports zero residual unclassified blanks. Raw source cells remain immutable; complete views apply enrichment or explicit null semantics.
- All source rows use verification date `2026-07-21`. Future monitors must distinguish last-checked date, publication date, local approval date, and next-review date.

URL presence, PMID resolution, and title/topic similarity are not evidence that a source supports every linked claim. Institutional reviewers must assess the exact claim, population, setting, intervention, currency, supersession, applicability, and local fit. Rank 127 illustrates the need: 41 heterogeneous eye-care claims share two PMIDs, so an ophthalmology evidence-map review remains necessary even after its missing bibliography is corrected.

### 3.6 Structure limitations

Most clinically important cells contain long narrative prose rather than computable elements. Median narrative length is commonly 400-800 characters, and some fields exceed 1,700 characters. The file does not directly encode:

- discrete eligibility predicates;
- typed clinical concepts and code systems;
- conditional branches;
- action relationships or sequencing;
- patient-specific exceptions;
- local ownership/performer roles;
- exact evidence-to-recommendation links;
- contraindications and exclusions as typed rules;
- translation and patient-reading-level content;
- teach-back prompts or comprehension state;
- release-policy decisions;
- change history across clinical versions.

Automated extraction may prepare review drafts, but no dose, threshold, timing rule, intervention, contraindication, or discharge criterion may become executable from regex or generative parsing alone.

---

## 4. Meaning of “Fully Aware”

Awareness is a closed loop, not a notification count.

For each active encounter and pathway version, Zephyrus should track separate awareness state for the patient/representative and relevant care-team roles:

```text
content approved
  -> content released to audience
  -> delivery offered
  -> viewed/opened
  -> acknowledged or question raised
  -> teach-back or clinician discussion when required
  -> misunderstanding corrected
  -> change explained and re-acknowledged when material
```

### 4.1 Patient awareness outcomes

The patient or authorized representative can answer, in plain language:

- What is the current stage of my hospital plan?
- What usually happens next, and what can change?
- What are today's important preparation steps?
- What goals did I set, and what goals did my care team set?
- What needs to happen before discharge?
- What warning signs and follow-up items should I understand?
- Who is responsible for each part of my care and how do I ask a non-urgent question?
- Which information is current, estimated, delayed, corrected, or not yet available?

### 4.2 Care-team awareness outcomes

Each accountable role can answer:

- Which pathway and exact version is active, and why?
- Has applicability been clinically confirmed?
- Which milestones are current, completed, delayed, skipped, or no longer applicable?
- Which time-sensitive items are orders/EHR facts versus pathway guidance?
- What deviations exist and who owns follow-up?
- What does the patient understand, prefer, or want discussed?
- Which education and discharge items require teach-back?
- What changed since the last shift or round?
- Which content is locally approved and which is only reference material?

### 4.3 Communication principles

AHRQ's IDEAL discharge framework calls for patients and families to be full partners, for education throughout the stay in plain language, for discussion of home life, medicines, warning signs, results, and follow-up, and for teach-back and patient/family goals and concerns. [AHRQ IDEAL Discharge Planning](https://www.ahrq.gov/patient-safety/patients-families/engagingfamilies/strategy4/index.html) The pathway experience should implement these as workflow state, not static copy.

- Notifications are PHI-free doorbells and never substitute for authenticated content.
- The app never says “you understand”; it records an observed teach-back or a patient response and the reviewing clinician.
- Acknowledgement means “I saw this,” not consent, agreement, comprehension, or clinical completion.
- Urgent concerns always route to bedside/emergency instructions, not asynchronous messaging or Eddy.
- Material corrections and pathway-version changes create a new release and awareness cycle.

---

## 5. Current Zephyrus Baseline to Reuse

| Need                    | Existing substrate                                                            | Integration decision                                                                             |
| ----------------------- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Raw source lineage      | `raw.drg_care_pathways_v43_1_20260721`, `raw.drg_care_pathway_imports`        | Preserve immutable; never serve directly                                                         |
| Patient identity/access | `patient_experience.principals`, identity links, encounter grants             | Keep patient realm separate from staff                                                           |
| Patient content release | `patient_experience.encounter_projections`, release policies, content actions | Use as the only patient-facing pathway boundary                                                  |
| Patient content guard   | `PatientProjectionContentGuard`                                               | Extend intentionally; do not bypass with raw JSON                                                |
| Patient disclosure      | `PatientProjectionDisclosureService`                                          | Continue exact scopes, generic denial, audit-on-disclosure                                       |
| Patient APIs            | `routes/patient.php` and `EncounterProjectionController`                      | Reuse `today`, `pathway`, `pathway_events`, `discharge_readiness`, `rounds_summary`, `care_team` |
| Patient native UI       | iOS `PatientPathView`; Android `PatientExperienceScreen`                      | Populate existing My Path grammar before adding more tabs                                        |
| Staff patient context   | `MobilePatientContextService`                                                 | Add a pathway projection using the same opaque `ptok_` authorization boundary                    |
| Staff attention         | `MobileForYouService`                                                         | Add pathway work items only when the user is an accountable role                                 |
| Virtual Rounds          | `rounds.*`, Rounds Board, tasks/questions/contributions, 4D overlay           | Make pathway status part of the round-patient projection                                         |
| Patient questions       | patient messaging plus round-question promotion                               | Route pathway questions into accountable rounds/work queues                                      |
| Eddy staff chat         | `EddyChatService`, provider policy, knowledge service                         | Add approved pathway reference and scoped patient-instance tools                                 |
| Eddy safety             | local/cloud policy, PHI block, draft-only action model                        | Create a clinical proposal boundary; do not reuse ops approval as an order signer                |
| Care-team/location      | `prod.encounters`, `prod.user_unit`, `hosp_org`, `hosp_space`, `hosp_ref`     | Drive assignment, role visibility, service line, and ownership                                   |
| EHR/FHIR integration    | integration control plane and FHIR registry                                   | Publish standards mappings and source adapters; no direct raw writeback                          |

The patient contract is ahead of the production projection pipeline: the native apps and allowlisted API shapes exist, but the live database has no release policy or released projection rows. This is the best seam for the pathway work, but it must be deployed in the correct migration order.

---

## 6. Target Architecture

```text
CSV + verification workbook / CMS / guideline sources / local policies
                    |
        immutable raw catalog snapshot
                    |
       knowledge ingestion + diff + review
                    |
     approved versioned pathway definitions
        /           |            \
 eligibility    patient copy    evidence/source
 and mapping     release        transparency
        \           |            /
       encounter candidate matcher
                    |
       clinician-confirmed pathway instance
                    |
       milestone / goal / task / deviation events
        /           |             |             \
 Zephyrus web   Virtual Rounds  Hummingbird Staff  patient release builder
                                                        |
                                           patient_experience projections
                                                        |
                                             Hummingbird Patient

 Approved pathway definition --------> Eddy reference retrieval
 Authorized instance snapshot --------> Eddy local-only scoped analysis
 Eddy result -------------------------> draft proposal / question / round input
 Human review ------------------------> accepted domain event or discarded draft
```

### 6.1 Ownership boundaries

- `raw` owns immutable CSV/workbook snapshots, claim/source/change evidence, completeness resolutions, and lineage. Its complete views support review tooling only; they are not application-serving clinical definitions.
- New `care_pathways` owns definitions, versions, mappings, reviews, approvals, encounter assignments, instances, milestones, awareness, and audit events.
- `flow_core` and `prod` remain encounter and operational truth; the pathway domain references, not copies, their facts.
- `rounds` owns multidisciplinary coordination and round-specific tasks/contributions.
- `patient_experience` owns released patient-safe projections and corrections.
- `eddy` owns approved retrieval indexes and AI interaction evidence, not pathway clinical truth.
- `integration`/`fhir` own source and outbound interoperability artifacts.

### 6.2 Case, plan, workflow separation

| Layer    | Zephyrus meaning                                       | Examples                                                                       |
| -------- | ------------------------------------------------------ | ------------------------------------------------------------------------------ |
| Case     | Current patient/encounter state                        | diagnoses, orders, observations, location, function, goals, discharge barriers |
| Plan     | Versioned pathway knowledge and one confirmed instance | pathway definition, eligibility, milestones, expected ranges, exceptions       |
| Workflow | Who does what and when                                 | rounds contributions, tasks, notifications, teach-back, approvals, follow-up   |

Do not place live patient state in a PlanDefinition-like record, do not make a pathway narrative the source of completed clinical facts, and do not treat workflow task completion as evidence that a clinical outcome occurred.

---

## 7. Proposed Domain Model

Create additive PostgreSQL migrations using `SafeMigration`. Keep definition/version records append-only after approval. Corrections supersede; they do not rewrite released history.

### 7.1 Catalog and governance tables

| Table                                    | Purpose                                      | Key fields                                                                                                                    |
| ---------------------------------------- | -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `care_pathways.catalog_releases`         | One imported/reconciled source release       | release UUID, source hash/import id, grouper version/effective dates, row/code counts, control totals, state                  |
| `care_pathways.catalog_release_controls` | Recomputed and accepted release assertions   | control key, observed/reference values, status, rationale, evidence                                                           |
| `care_pathways.definitions`              | Stable identity of a condition pathway       | pathway UUID/key, canonical name, MDC, medical/surgical class, service line, lifecycle state                                  |
| `care_pathways.versions`                 | Immutable content version                    | definition id, semantic version, source release, source rank, content/verification tier, effective dates, supersedes, digests |
| `care_pathways.drg_codebook_entries`     | Grouper-specific DRG authority               | release id, 770 unique DRG codes, title, MDC, type, source, verified date, digest                                             |
| `care_pathways.drg_mappings`             | Many-to-many DRG mapping                     | version id, codebook entry id, 802 associations, mapping role, ambiguity note, effective period                               |
| `care_pathways.sections`                 | Typed source and approved narrative sections | version id, section code, audience, source text, approved text, content mode, language, review state                          |
| `care_pathways.milestone_definitions`    | Computable stages/milestones                 | stable key, title, phase, sequence/relations, expected range, applicability expression ref, completion evidence ref           |
| `care_pathways.activity_definitions`     | Governed action guidance                     | type, performer role, timing, preconditions, evidence, executable flag, EHR/FHIR canonical ref                                |
| `care_pathways.goal_definitions`         | Pathway-level goal templates                 | goal code/text, author type, target range, patient-visible explanation                                                        |
| `care_pathways.education_definitions`    | Patient/caregiver education                  | audience, language, reading level, teach-back prompt, required reviewer, content digest                                       |
| `care_pathways.sources`                  | Citation/source registry                     | PMID/DOI/URL, title, organization, publication/access dates, source type, supersession state                                  |
| `care_pathways.evidence_claims`          | Independently adjudicated source claims      | version/section, field, claim type/excerpt, automated passes, clinical adjudication, digest                                   |
| `care_pathways.claim_sources`            | Claim-to-source evidence links               | claim id, source id, evidence grade, applicability note, provenance                                                           |
| `care_pathways.section_sources`          | Approved section citation selection          | section id, source id, claim summary, evidence grade, applicability note                                                      |
| `care_pathways.source_changes`           | Verification-release change provenance       | release/rank/field, old/new values, reason, source, date, digest                                                              |
| `care_pathways.source_enrichments`       | Non-destructive bibliographic enrichment     | source/field, original/enriched values, enrichment source, digest                                                             |
| `care_pathways.completeness_resolutions` | Explicit null/absence semantics              | source/rank/field, resolution type/value, rationale, evidence                                                                 |
| `care_pathways.service_line_mappings`    | Governed source-to-canonical crosswalk       | source label, normalized candidate, canonical code, mapped/pending/rejected state                                             |
| `care_pathways.reviews`                  | Append-only review facts                     | reviewer role/ref, scope, decision, reason, issues, timestamp                                                                 |
| `care_pathways.approvals`                | Release gate by discipline                   | version id, approval type, actor, decision, effective window, conditions                                                      |
| `care_pathways.events`                   | Append-only pathway audit                    | aggregate, event type, actor, version, PHI-safe metadata, correlation/idempotency keys                                        |

### 7.2 Encounter-instance tables

| Table                                 | Purpose                           | Key fields                                                                                               |
| ------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `care_pathways.assignment_candidates` | Explainable candidate set         | encounter ref, version id, match evidence, confidence, rule/model version, source cutoff, status         |
| `care_pathways.assignments`           | Clinician decision                | encounter ref, selected version, state, assigned/confirmed/rejected actor, reason, version               |
| `care_pathways.instances`             | One pinned encounter plan         | instance UUID, encounter ref, pathway version, state, started/closed dates, local variant, source cutoff |
| `care_pathways.instance_milestones`   | Current milestone state           | instance id, definition id, state, evidence refs, owner role/user, due/expected window, version          |
| `care_pathways.instance_goals`        | Patient and care-team goals       | author type/ref, label, state, target, provenance, visibility                                            |
| `care_pathways.deviations`            | Explicit variance                 | milestone/activity, type, reason, clinical owner, review state, resolved date                            |
| `care_pathways.instance_tasks`        | Pathway workflow tasks            | owner, status, due date, `rounds.task_uuid`, `ops.action_uuid`, FHIR Task ref                            |
| `care_pathways.education_assignments` | Education and teach-back          | education version, recipient relationship, state, assigned/reviewed actor, response/teach-back status    |
| `care_pathways.awareness_events`      | Audience awareness ledger         | audience type/ref, content/version, offered/viewed/acknowledged/questioned/taught-back/corrected event   |
| `care_pathways.projection_runs`       | Patient/staff projection pipeline | source cutoff, version/digest, output ids, status, counts, failure code                                  |
| `care_pathways.projection_failures`   | PHI-minimized pipeline failures   | run, stage, reason code, retry state, occurred time                                                      |

### 7.3 Required constraints

- Stable UUID business keys; surrogate bigint primary keys.
- One active confirmed pathway assignment per encounter and pathway family unless a governed multi-pathway policy allows coexistence.
- One exact pathway version per instance.
- No activation without all required approval types.
- No patient release without an active patient release policy and approved patient-language content.
- Submitted reviews, approved versions, released patient projections, and awareness events are append-only.
- `content_digest` and `source_digest` detect drift.
- Effective periods never overlap for the same definition/version state.
- Every computable rule links to one or more source claims and a human review.
- Every status transition uses expected-version concurrency and an idempotency key.
- Raw patient/encounter identifiers never enter broadcasts, generic push payloads, or Eddy's global knowledge table.

---

## 8. Source-to-Definition Translation

### 8.1 Field routing

| CSV/workbook fields                                                                                                                                          | Target use                                                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| `rank`, `approx_annual_discharges`, `cumulative_pct_admissions`, `scope_and_volume_notes`, `volume_*`                                                        | Catalog prioritization and transparency; never encounter-level clinical logic                                       |
| `condition`, `mdc`, `medical_or_surgical`, `service_line`                                                                                                    | Stable definition metadata, subject to local taxonomy crosswalk                                                     |
| `drg_codes`, `drg_code_titles_v43_1`, `official_mdc_codes_v43_1`, `official_drg_type_mix_v43_1`, `legacy_drg_codes`, `drg_grouper_version`, `drg_source_url` | Versioned DRG mapping and grouper reconciliation                                                                    |
| `admission_criteria`, `risk_stratification`                                                                                                                  | Eligibility-review drafts; must become typed, locally approved predicates before computation                        |
| `initial_workup_labs`, `initial_imaging_dx`, `time_critical_interventions`, `initial_management`                                                             | Staff reference sections and candidate activity definitions; no automatic order generation                          |
| `day1_milestones`, `day2_milestones`, `day3plus_milestones`                                                                                                  | Milestone-definition drafts with explicit relations and evidence semantics                                          |
| `consults_multidisciplinary`, `monitoring_level`, `nutrition_mobility_vte`                                                                                   | Role/monitoring/workflow drafts; local capability and policy review required                                        |
| `discharge_criteria`, `discharge_planning`, `expected_los`, `target_los`                                                                                     | Discharge-readiness criteria and estimated ranges; never a discharge promise                                        |
| `quality_metrics`, `common_complications`, `readmission_drivers`                                                                                             | Analytics definitions, safety monitoring, and education candidates; keep cohort metrics separate from patient facts |
| `guideline_source`, `key_citations`, `clinical_source_urls`, `evidence_grade`, `condition_specific_pmids`                                                    | Normalized source registry and claim-level evidence links                                                           |
| `severity_cc_mcc_notes`                                                                                                                                      | Coding/resource context; never a reason to intensify care or documentation                                          |
| `pathway_pearls`                                                                                                                                             | Staff-reference candidate content; not executable                                                                   |
| `verification_*`, `citation_audit_status`, `clinical_verification_basis`, `data_quality_notes`, `source_access_date`                                         | Automated evidence tier and warnings; never a substitute for institutional approval                                 |
| `coding_verification_status`, `source_specificity`, `volume_verification_status`, `automated_high_stakes_claims_reviewed`, `verification_method`             | Evidence QA facts and review-console filters                                                                        |
| `clinical_signoff_status`, `unresolved_flags`, `release_disposition`                                                                                         | Blocking local workflow state and activation gates                                                                  |
| `Claim_Audit` rows                                                                                                                                           | Claim inventory and source linkage for institutional adjudication; not executable rules                             |
| `Source_Index` plus enrichment/completeness records                                                                                                          | Versioned bibliography, retraction/currency monitoring, and explicit null semantics                                 |
| `Change_Log`                                                                                                                                                 | Source-release diff and provenance; input to local impact classification                                            |

### 8.2 Translation states

```text
raw_imported
  -> taxonomy_reconciled
  -> automated_evidence_reconciled
  -> institutional_claims_adjudicated
  -> local_clinical_content_approved
  -> computable_rules_reviewed
  -> patient_copy_reviewed
  -> multidisciplinary_release_approved
  -> pilot_enabled
  -> production_active
  -> superseded / withdrawn
```

No state is inferred from rank or national modeled volume. The 96 evidence-verified rows may enter institutional signoff; the 148 limitation rows enter named specialist review; the 6 redesign rows enter `non_protocol_candidate`. All begin at or before `automated_evidence_reconciled`, never `local_clinical_content_approved` or `production_active`.

### 8.3 Review matrix

Each version requires named decisions from:

- physician/service-line owner;
- nursing;
- pharmacy when medications, dosing, reconciliation, or monitoring are involved;
- case management/social work for transition content;
- rehabilitation/nutrition/other allied health when applicable;
- coding/CDI for DRG statements and grouper version;
- clinical informatics/knowledge engineering;
- patient education/health literacy;
- privacy/security;
- accessibility/language access;
- patient/family advisors;
- operational owner for local resources and accountability.

High-acuity/time-critical pathways require a formal clinical hazard analysis and simulation before pilot.

### 8.4 Version changes

On source or local-policy change:

1. Create a new draft version.
2. Diff every source section, structured rule, patient sentence, source citation, and FHIR artifact.
3. Classify each change as editorial, evidence-only, operational, patient-material, or clinical-material.
4. Re-run the required review matrix based on classification.
5. Decide whether active instances stay pinned, may opt in, or require urgent correction.
6. Generate correction/retraction projections for affected patients when necessary.
7. Preserve the prior version and its encounter history.

---

## 9. Encounter Assignment Strategy

### 9.1 Do not lead with DRG

The assignment engine should use the best available contemporaneous clinical and operational signals:

- encounter class, care setting, age band, service, and unit;
- admission reason and principal working problem;
- procedures performed or planned;
- problem list/condition codes and clinical documentation status;
- relevant structured observations and severity facts;
- local inclusion/exclusion rules;
- active specialty ownership;
- final DRG only when available.

The engine returns an explainable candidate set:

```json
{
    "candidate_pathway_version_uuid": "...",
    "confidence": "medium",
    "matched": ["working_problem", "service", "procedure_family"],
    "conflicts": ["drg_overlap", "missing_exclusion_data"],
    "source_cutoff_at": "...",
    "rule_version": "...",
    "requires_confirmation": true
}
```

### 9.2 Assignment lifecycle

```text
candidate -> proposed -> confirmed -> active -> completed
     |           |          |           |
     +--------> rejected     +-------> superseded
                                      +-------> withdrawn
```

- High-confidence candidates may be preselected in a review queue, never silently activated.
- The confirming clinician sees match evidence, exclusions, overlap warnings, source version, and local approval.
- A reassignment records why and preserves the original candidate/decision.
- Multiple pathways may coexist only under an explicit policy, such as a condition pathway plus a procedure pathway.
- Final DRG reconciliation produces a quality event; it does not backdate a pathway decision.

### 9.3 Initial pilot candidates

Recommended adult pilot sequence:

1. Heart Failure.
2. Simple Pneumonia.
3. COPD Exacerbation.
4. Major Joint Replacement.
5. Urinary Tract / Kidney Infection.

These five are in the workbook's 96-row evidence-verified cohort and are marked ready for institutional signoff. They are common, understandable to patients, and span medical and surgical workflows, but none is clinically approved yet. Final selection must use local volume, service ownership, existing order-set overlap, institutional claim adjudication, patient-advisor input, and unit readiness. Sepsis, stroke, respiratory failure, PCI, neonatal, and other high-acuity pathways follow after the governance and monitoring system is proven.

---

## 10. Zephyrus Care-Team Experience

### 10.1 Pathway Catalog and Governance Console

Add a new governed admin/clinical-informatics surface, not a general navigation item for all users.

Capabilities:

- filter by service line, MDC, rank, automated evidence status, confidence, source specificity, unresolved flag, release disposition, clinical signoff, local approval, source age, and implementation status;
- inspect and adjudicate the imported claim inventory, including source-to-claim coverage and limitation state;
- inspect source text beside approved staff and patient versions;
- view DRG overlaps and CMS reconciliation;
- diff versions and citations;
- record discipline reviews and blocking issues;
- simulate eligibility against deidentified fixtures;
- preview staff, rounds, Eddy, and patient projections;
- approve, supersede, withdraw, and scope a release by facility/unit/cohort;
- display source and projection digests.

### 10.2 Encounter Pathway Workspace

Add a pathway panel to the authorized patient drill/workspace with:

- exact pathway/version and applicability rationale;
- local approval and verification banner;
- current stage and next expected milestone;
- completed/current/delayed/not-applicable states;
- evidence-backed preparation and monitoring guidance;
- explicit “reference guidance—not an order” treatment;
- deviations and assigned owners;
- patient goals versus care-team goals;
- patient questions and understanding status;
- education and teach-back due;
- discharge criteria and remaining needs;
- source freshness and last reconciliation;
- one-click entry into the relevant round-patient workspace.

### 10.3 Role-specific views

- Physicians: applicability, decisions, exceptions, unresolved diagnostic/therapeutic questions.
- Nursing: current milestones, monitoring, preparation, education, mobility, safety, teach-back.
- Pharmacy: medication reconciliation, conversions/access, monitoring, patient education.
- Case management/social work: destination, caregiver, transport, equipment, access, follow-up.
- Allied health: discipline-specific goals, assessments, restrictions, and tasks.
- Unit/service-line leaders: aggregate adoption, deviations, overdue reviews, and outcome measures without flattening unit and service-line scope.

The UI must not display every source paragraph on every patient. Progressive disclosure begins with current stage, next decisions, owner, uncertainty, and changes.

---

## 11. Virtual Rounds and 4D Integration

### 11.1 Rounds data contract

Extend the round-patient projection with a bounded `pathway` block:

- pathway instance/version UUID;
- locally approved display name;
- current stage and state;
- next milestone window and confidence;
- overdue/at-risk milestone count;
- open deviation count;
- education/teach-back due count;
- patient question count;
- last reconciled timestamp;
- source freshness;
- opaque link to the pathway workspace.

Do not place raw narrative, citations, diagnoses, or patient identifiers in the 4D scene payload.

### 11.2 Round workflow

Add pathway-aware round sections:

- confirm/decline pathway applicability;
- review milestone progress;
- reconcile deviations and contraindications;
- review patient/representative questions;
- review patient goals and preferences;
- assign education/teach-back;
- assign or update discharge-preparation tasks;
- identify an owner and due time for every unresolved item;
- generate an approved patient rounds summary.

Existing `rounds.contributions`, `rounds.questions`, `rounds.tasks`, and `rounds.events` remain workflow truth. The pathway domain links to them with UUIDs; it does not duplicate their lifecycle.

### 11.3 4D projection

The Patient Flow 4D Navigator should show a pathway layer only to lenses permitted to see patient-level detail:

- stage ring or badge on authorized round stops;
- neutral visual grammar for current/completed/delayed states;
- no color that confuses identity, acuity, delay, and pathway status;
- inspector summary with version, stage, next milestone, deviation count, and deep link;
- service-line aggregate heatmap showing pathway adoption/completion only where cohort size and privacy policy permit;
- “Ask Eddy” uses the structured selected scope, never text scraped from the scene.

The 4D viewer remains a projection surface. It is not the source of pathway state.

---

## 12. Hummingbird Staff Integration

### 12.1 Mobile BFF

Extend `MobilePatientContextService` or add a dedicated service behind the same authorization policy:

```text
GET /api/mobile/v1/patients/{contextRef}/care-pathway
```

Return only staff-authorized, role-shaped fields. Keep `contextRef` opaque and reject raw patient references.

Suggested response sections:

- pathway header/version/approval;
- current stage;
- next milestones;
- role-owned tasks;
- deviations needing the role's review;
- patient questions and teach-back needs;
- latest rounds summary;
- source freshness and warnings;
- permitted actions.

### 12.2 For You

Add attention items only for accountable work:

- pathway confirmation required;
- milestone review due;
- patient question waiting;
- teach-back due;
- discharge criterion at risk;
- material pathway correction released;
- task reassigned after transfer/shift change.

Each item must have:

- server-derived urgency;
- accountable owner/role;
- due time and source freshness;
- opaque patient context ref;
- authorized deep link;
- deduplication/collapse key;
- lifecycle state so stale cards disappear.

### 12.3 Native client parity

Update the staff OpenAPI contract, generated types, Android, and iOS in the same change set. Do not let one client invent milestone labels or status mappings.

- iOS: role-adaptive patient context and For You detail.
- Android: same contract and status grammar; no return to a generic A0/A1 explorer.
- Both: biometric/session boundary, screen-capture policy, dynamic type, screen readers, high contrast, reduced motion, offline purge, transfer/discharge invalidation.
- Default offline behavior: do not persist pathway narrative. Cache only encrypted, minimum-needed metadata with a short server-governed TTL if an approved use case requires it.

---

## 13. Hummingbird Patient Integration

### 13.1 Reuse the existing patient release boundary

The current contract already models:

- `today`;
- `pathway`;
- `pathway_events`;
- `discharge_readiness`;
- `rounds_summary`;
- `care_team`.

Populate these from the approved encounter pathway instance. Do not create a second patient pathway API and do not let the native clients read `care_pathways` directly.

FHIR R4 `CarePlan` represents how practitioners intend to deliver care for a patient over time, and its activities can instantiate PlanDefinition or ActivityDefinition artifacts. [HL7 FHIR R4 CarePlan](https://hl7.org/fhir/R4/careplan.html) Zephyrus's internal pathway instance should map to that semantic boundary while continuing to use its own patient-safe projection contract.

### 13.2 Patient content model

`pathway`:

- headline and plain-language summary;
- current stage;
- planned/current/completed/delayed stages;
- milestones with estimated/confirmed/unknown timing;
- patient-authored and care-team-authored goals kept distinct;
- education topics;
- questions and safety notices.

`pathway_events`:

- only patient-approved key moments;
- no complete chart chronology;
- explicit correction notices.

`discharge_readiness`:

- estimated range and uncertainty;
- criteria and unresolved needs;
- medicines to review;
- equipment, transport, and home-support preparation;
- follow-up;
- warning signs;
- accountable contact routes.

`rounds_summary`:

- topics discussed;
- next steps;
- patient's questions and what remains unanswered;
- no staff disagreements or internal notes.

`care_team`:

- current members/roles/responsibilities;
- shift/service validity window;
- safe contact routes rather than personal contact details.

FHIR R4 `CareTeam` supports encounter-specific team members, roles, organizations, and participation periods; it is the correct interoperability concept for the underlying staff roster. [HL7 FHIR R4 CareTeam](https://hl7.org/fhir/R4/careteam.html)

### 13.3 Patient actions

Add actions deliberately and separately from the read projection:

- acknowledge that an update was viewed;
- add a question using approved topic routing;
- state a goal/preference;
- request a clinician explanation or interpreter;
- complete an education response;
- perform teach-back with a clinician reviewer;
- flag that content seems wrong;
- choose notification/language/accessibility preferences.

A FHIR `Questionnaire` can represent structured, ordered questions for patients, caregivers, and clinicians; use it as an interoperability model for education/teach-back instruments, with QuestionnaireResponse-like semantics for responses. [HL7 FHIR R4 Questionnaire](https://hl7.org/fhir/R4/questionnaire.html)

Patient Eddy remains outside the MVP. If later evaluated, it receives only the already released patient projection, operates in explanation-only mode, cannot take clinical action, is disabled by default, and requires its own safety case and patient-advisor study.

---

## 14. Eddy Integration

### 14.1 Two strictly separated retrieval planes

**Plane A: approved pathway knowledge**

- PHI-free.
- Versioned and linked to `care_pathways.versions` and exact source claims.
- Staff or patient audience explicitly declared.
- RAG-eligible only after local approval.
- Retrievable by service line, pathway, section, audience, facility, effective date, and verification tier.

**Plane B: authorized patient instance context**

- Contains minimum-needed patient-specific state.
- Retrieved at request time through the same staff authorization/lens used by the patient workspace.
- Local-only provider policy.
- Short-lived and non-cacheable outside the governed context packet.
- Never embedded into `eddy.eddy_knowledge`.
- Never included in preference-learning material or auto-curation.

### 14.2 Do not bulk-ingest the CSV into `eddy_knowledge`

The current Eddy knowledge service retrieves approved, PHI-free rows but lacks pathway-version, evidence-claim, audience, effective-date, and local-approval semantics. Add either:

- explicit pathway columns and FKs to `eddy.eddy_knowledge`; or
- an `eddy.knowledge_sources`/`eddy.knowledge_chunks` layer that points to the canonical pathway version and keeps `eddy_knowledge` for general operational doctrine.

Each chunk needs:

- canonical pathway/version/section ref;
- audience;
- approval and effective state;
- source/evidence refs;
- content digest;
- embedding model/version and embedding timestamp;
- withdrawal state;
- local applicability scope;
- citation label safe to show in answers.

### 14.3 Eddy modes

1. **Reference Q&A:** answer staff questions about an approved pathway with cited sections and applicability warnings.
2. **Patient-instance explanation for staff:** summarize where the authorized encounter is relative to its confirmed pathway.
3. **Gap scan:** compare structured milestone evidence to expected definitions and draft missing-data questions or deviations.
4. **Rounds preparation:** draft an evidence-linked round summary, missing role inputs, and patient questions.
5. **Patient-copy drafting:** propose plain-language content for human health-literacy and clinical review; never publish it.
6. **Patient explanation experiment:** future, retrieval-only over released patient content under a separate disabled flag.

### 14.4 Structured result contract

Every care-pathway result should contain:

- pathway/version;
- user/audience and authorization scope;
- data cutoff and freshness;
- retrieved section/source ids;
- applicability statement;
- findings and supporting structured evidence refs;
- missing information;
- uncertainty;
- proposed next question/task/deviation;
- prohibited-action reminder;
- provider/model/prompt/policy versions;
- trace digest.

### 14.5 Provider policy

Add `care_pathway` and `rounds` as explicit Eddy surfaces. Require `patient_context_local_only` whenever encounter context is present. A PHI detection result must hard-block cloud egress, as the current configuration intends.

CDS Hooks 2.0 is the current published release and supports workflow-triggered clinician decision support, patient-view context, source-labelled cards, and suggestions. [CDS Hooks 2.0](https://cds-hooks.hl7.org/2.0/) Use it only as an external EHR integration boundary after the internal pathway service is stable; Eddy itself should not masquerade as a CDS Hooks service without the required source, suggestion, override, and security semantics.

### 14.6 Human control

- Eddy cannot confirm a pathway assignment.
- Eddy cannot turn a narrative sentence into an order.
- Eddy cannot mark a milestone complete without defined source evidence or human attestation.
- Eddy cannot sign a round contribution.
- Eddy cannot attest patient comprehension.
- Eddy cannot publish patient content.
- Eddy cannot resolve a deviation or override a contraindication.
- Clinical proposals require a pathway-specific review FSM, not a shortcut through `EddyActionService`'s operational action catalog.

FDA's January 2026 CDS guidance distinguishes software functions and notes that patient/caregiver-facing functions may remain subject to device policy; applicability depends on intended use and design. [FDA Clinical Decision Support Software Guidance](https://www.fda.gov/regulatory-information/search-fda-guidance-documents/clinical-decision-support-software) Regulatory counsel must classify each Eddy mode before release. This plan does not assert non-device status.

For AI transparency and monitoring, record intended use, inputs, outputs, evidence, known limitations, validation population, performance, fairness review, update controls, and user feedback. These controls align with ONC's HTI-1 decision-support transparency concepts for certified health IT and NIST AI RMF practices, even where Zephyrus is not itself claiming a particular certification status. [ONC HTI-1](https://healthit.gov/regulations/hti-rules/hti-1-final-rule/) [NIST AI RMF](https://www.nist.gov/itl/ai-risk-management-framework)

---

## 15. Standards and Interoperability Mapping

### 15.1 FHIR R4/CPG-on-FHIR

| Zephyrus concept           | FHIR/CPG concept                          | Notes                                                                                             |
| -------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Pathway definition/version | CPG Pathway `PlanDefinition`              | Definition, goals, action relations, evidence, effective version                                  |
| Reusable activity          | `ActivityDefinition`                      | Definition only; applying it should initially create draft intent resources, not silently execute |
| Encounter pathway instance | `CarePlan`                                | Subject/encounter, intent/status, instantiates canonical pathway                                  |
| Patient/care-team goal     | `Goal`                                    | Preserve author and patient preference                                                            |
| Workflow task              | `Task`                                    | `basedOn` CarePlan; `instantiatesCanonical` ActivityDefinition where applicable                   |
| Ordered test/procedure     | `ServiceRequest`                          | Remains EHR clinical truth, not inferred from pathway prose                                       |
| Team                       | `CareTeam`                                | Roles, members, organizations, periods                                                            |
| Education/teach-back form  | `Questionnaire` / `QuestionnaireResponse` | Separate viewed, response, comprehension, and review states                                       |
| Patient/team communication | `CommunicationRequest` / `Communication`  | Intent versus actual communication                                                                |
| Source/review lineage      | `Provenance` and `RelatedArtifact`        | Link guideline evidence, local review, source digest, and transformations                         |

FHIR R4 `PlanDefinition` supports goals, action hierarchies, related evidence, libraries, and reusable ActivityDefinition references. [HL7 FHIR R4 PlanDefinition](https://hl7.org/fhir/R4/plandefinition.html) `Task.instantiatesCanonical` can link a task to an ActivityDefinition, and `Task.basedOn` can link it to a CarePlan or other request. [HL7 FHIR R4 Task](https://hl7.org/fhir/R4/task.html)

HL7's CPG implementation methods use PlanDefinition for recommendations, strategies, and pathways, but warn that array order alone does not imply execution sequence; related actions must express relationships. [HL7 CPG Methods of Implementation](https://www.hl7.org/fhir/uv/cpg/documentation-approach-09-methods-of-implementation.html)

### 15.2 FHIR artifacts are projections, not the only database model

Use relational tables for local governance, concurrency, role assignments, awareness, and analytics. Generate validated FHIR artifacts with canonical URLs and version identifiers. Store or mirror them in the existing FHIR integration layer. Do not bury all lifecycle state inside opaque JSONB resources.

### 15.3 EHR integration order

1. Read-only patient/encounter/problem/procedure/CareTeam/Task/CarePlan ingestion.
2. Encounter pathway candidate suggestion within Zephyrus.
3. SMART launch or link from the EHR to the Zephyrus pathway workspace.
4. CDS Hooks `patient-view` informational card for a confirmed active pathway.
5. Draft Task/CommunicationRequest writeback through governed integration outbox.
6. Broader CarePlan synchronization only after conflict, provenance, correction, and ownership rules pass deployed tests.

No production connector may write an order merely because a pathway definition contains an activity.

---

## 16. APIs, Contracts, and Events

### 16.1 Staff web API

Implemented governance reads, all under `/api/care-pathways/v1`, are:

```text
GET /summary
GET /pathways
GET /versions/{versionUuid}
GET /versions/{versionUuid}/claims
GET /sources
GET /sources/{sourceUuid}
GET /controls
GET /reviews
GET /approvals
GET /events
```

Every route is GET-only and uses the governance feature gate, web-session authentication, `viewCarePathwayCatalog`, and rate limiting. Search/filter support covers condition text, DRG candidate, MDC, service line, evidence state, release disposition/review queue, institutional approval, activation, source text/PMID/author/organization, source currency, cited/uncited status, verification date, control status, ledger decision, and event type.

Future encounter and governance mutations remain proposed:

```text
GET  /api/care-pathways/catalog
GET  /api/care-pathways/definitions/{pathwayUuid}/versions/{version}
GET  /api/care-pathways/encounters/{contextRef}/candidates
POST /api/care-pathways/encounters/{contextRef}/assignments
GET  /api/care-pathways/instances/{instanceUuid}
POST /api/care-pathways/instances/{instanceUuid}/milestones/{milestoneUuid}/transition
POST /api/care-pathways/instances/{instanceUuid}/deviations
POST /api/care-pathways/instances/{instanceUuid}/education
POST /api/care-pathways/instances/{instanceUuid}/reconcile
POST /api/care-pathways/versions/{versionUuid}/reviews
POST /api/care-pathways/versions/{versionUuid}/approvals
POST /api/care-pathways/versions/{versionUuid}/activate
POST /api/care-pathways/versions/{versionUuid}/withdraw
```

Catalog governance and encounter care require different capabilities and rate limits. Source adoption, authoring, evidence review, institutional clinical approval, release activation, encounter-pathway access, and instance management must continue to use their distinct capabilities; the catalog-view gate does not authorize any mutation.

### 16.2 Hummingbird Staff API

```text
GET /api/mobile/v1/patients/{contextRef}/care-pathway
GET /api/mobile/v1/care-pathway/work
POST /api/mobile/v1/care-pathway/work/{workUuid}/acknowledge
```

Mutations use idempotency keys and expected versions. Do not allow native clients to activate pathways or approve clinical versions.

### 16.3 Patient API

Continue the existing patient routes. Add only the narrowly defined interaction endpoints required by approved workflows:

```text
POST /api/patient/v1/encounters/{encounterUuid}/pathway/acknowledgements
POST /api/patient/v1/encounters/{encounterUuid}/pathway/questions
POST /api/patient/v1/encounters/{encounterUuid}/pathway/goals
POST /api/patient/v1/encounters/{encounterUuid}/education/{educationUuid}/responses
POST /api/patient/v1/encounters/{encounterUuid}/pathway/correction-reports
```

Keep generic non-disclosing 404/denial behavior, exact patient abilities, active encounter grants, and audit-on-disclosure.

### 16.4 Event vocabulary

```text
care_pathway.catalog_imported
care_pathway.taxonomy_reconciled
care_pathway.version_reviewed
care_pathway.version_approved
care_pathway.version_activated
care_pathway.version_withdrawn
care_pathway.assignment_candidate_created
care_pathway.assignment_confirmed
care_pathway.assignment_rejected
care_pathway.instance_started
care_pathway.milestone_transitioned
care_pathway.deviation_opened
care_pathway.deviation_resolved
care_pathway.education_assigned
care_pathway.awareness_recorded
care_pathway.patient_projection_released
care_pathway.patient_projection_corrected
care_pathway.patient_projection_retracted
care_pathway.eddy_draft_created
care_pathway.eddy_draft_decided
```

Broadcasts contain only aggregate UUID, version, event type, and refetch hint.

---

## 17. Security, Privacy, and Clinical Safety

### 17.1 Authorization

- Patient realm and staff realm remain separate.
- Staff detail access requires current role, capability, purpose, and patient/unit/service assignment.
- Service-line leaders do not automatically receive every patient in the service line.
- A patient's representative sees only relationships and content authorized by the encounter grant and release policy.
- Care-team membership is effective-dated and recalculated on transfer, shift change, service change, discharge, and access revocation.
- Patient lists, alerts, and 4D aggregates are filtered server-side.

### 17.2 Minimum necessary data

- Source pathway content is PHI-free but clinically sensitive and governed.
- Instance state is patient data and never enters the raw catalog or global knowledge index.
- Push, SSE, WebSocket, and mobile deep links use opaque references.
- Logs contain reason codes and trace digests, not pathway narrative plus patient context.
- Analytics use deidentified/aggregated event facts where possible.

### 17.3 Clinical hazards and controls

| Hazard                                                 | Control                                                                                |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| Wrong pathway assigned from DRG overlap                | Explainable candidate set plus clinician confirmation                                  |
| Retrospective DRG used as admission criterion          | Early clinical signals; final DRG only reconciles                                      |
| Generalized tail content treated as condition-specific | Verification-tier gate; no activation                                                  |
| Automated evidence-green status mistaken for approval  | Separate evidence, disposition, clinical-signoff, local-version, and activation states |
| One broad citation treated as support for every claim  | Claim-level institutional adjudication and evidence-map coverage review                |
| Old guideline/version remains active                   | Effective dates, source monitoring, supersession alerts, kill switch                   |
| Narrative parsed into incorrect executable rule        | Dual clinical/knowledge-engineering review and fixture validation                      |
| Patient interprets expected LOS as promise             | Range/confidence/can-change language and team confirmation                             |
| Alert fatigue                                          | Role ownership, dedupe, collapse, escalation, measurable burden                        |
| Patient acknowledgement mistaken for comprehension     | Separate view, acknowledgement, response, and reviewed teach-back states               |
| Eddy fabricates or overgeneralizes                     | Approved retrieval only, citation-required structured output, abstention, human review |
| Patient context sent to cloud model                    | Local-only surface policy and hard PHI egress block                                    |
| Care team changed after transfer                       | Effective-dated membership, reconciliation, stale projection purge                     |
| Incorrect patient content persists                     | Append-only correction/retraction action and generic notification                      |
| Pathway becomes shadow order set                       | Explicit non-executable default; EHR orders remain authoritative                       |

### 17.4 Kill switches

- global care-pathway feature;
- catalog/version activation;
- facility/unit/cohort assignment;
- patient pathway release;
- patient interactions;
- pathway notifications;
- staff Eddy pathway reference;
- Eddy patient-instance analysis;
- patient Eddy, permanently default false;
- EHR writeback;
- individual pathway version withdrawal.

---

## 18. Implementation Phases

### Phase 0 — Preserve and reconcile the source (completed for this snapshot)

- [x] Profile CSV structure and controls.
- [x] Compute source SHA-256.
- [x] Import into isolated raw table.
- [x] Record import manifest.
- [x] Reconcile rows, conditions, volume, coverage, verification classes, and every source field.
- [x] Cross-check the CSV's 770 DRGs against the CMS v43.1 list page.
- [x] Profile and visually inspect all eight verification-workbook sheets.
- [x] Verify the workbook's declared baseline hash and semantic equivalence to the imported CSV.
- [x] Import the verification ledger, verified pathways, 10,123 claim rows, 811-source index, 324 changes, 770-code codebook, methodology, and QA snapshot as a separate immutable release.
- [x] Recompute cross-sheet controls rather than trusting static workbook QA values.
- [x] Classify all source blanks, enrich PMID 38349295, and reduce residual unclassified absence to zero without overwriting raw cells.
- [ ] Add a repository migration/import command that reproduces the live raw schema and records the external import as an adopted release.
- [ ] Document the CMS 770-list/772-design-PDF discrepancy in a catalog-release control.

**Exit gate:** reproducible import, immutable lineage, no application read path from raw.

### Phase 1 — Canonical catalog and governance

- Create `care_pathways` schema and catalog/governance tables.
- Load all 250 rows as inactive source versions.
- Normalize 770 DRG mappings, the 811-record source index, 10,123 claim-evidence links, 324 source-release changes, and completeness/enrichment provenance.
- Map source service lines to `hosp_ref.service_lines`; queue unresolved mappings.
- Implement evidence status, confidence, source specificity, unresolved flags, release disposition, institutional signoff, and activation as separate state dimensions.
- Build catalog governance console, version diff, review, and approval workflow.
- Add source monitoring for superseded/retracted guidelines and future grouper versions.

**Exit gate:** every row is traceable, searchable, inactive, and correctly classified; no raw text is patient- or encounter-served.

### Phase 2 — Pilot pathway knowledge engineering and institutional signoff

- Select 3-5 locally high-value pathways from the 96-row institutional-signoff queue.
- Review the imported claim inventory and split over-broad source-to-claim linkages before creating typed eligibility/milestone/activity drafts.
- Perform multidisciplinary and patient-copy review.
- Create deidentified clinical fixtures and expected rule outcomes.
- Generate FHIR PlanDefinition/ActivityDefinition/Questionnaire artifacts and validate them.
- Approve staff content separately from patient content.

**Exit gate:** local approval matrix complete; no unresolved high-severity hazard; computable rules match fixtures and narrative intent.

### Phase 3 — Assignment and encounter instances

- Implement candidate matcher and confirmation queue.
- Add assignments, instances, milestones, goals, deviations, tasks, awareness, and audit events.
- Reconcile active instances with encounter, order, task, and care-team signals.
- Add encounter Pathway Workspace and role-shaped views.
- Keep orders and clinical facts read-only.

**Exit gate:** one pilot service can confirm and maintain a pathway without DRG-only assignment or silent version changes.

### Phase 4 — Virtual Rounds and Hummingbird Staff

- Add pathway summary to round-patient projection and Rounds Board.
- Add pathway-aware contributions, questions, tasks, and approved patient rounds summary.
- Add 4D pathway layer under existing lens policy.
- Add Mobile BFF pathway endpoint and For You items.
- Update staff OpenAPI, iOS, Android, tests, and route inventory together.

**Exit gate:** accountable roles receive and close the right pathway work across web and both staff clients; transfers/shift changes revoke or reroute correctly.

### Phase 5 — Hummingbird Patient production projection

- Deploy pending patient migrations in reviewed order with feature flags off.
- Add release policy and production source adapter.
- Generate patient `today`, `pathway`, `pathway_events`, `discharge_readiness`, `rounds_summary`, and `care_team` projections.
- Add acknowledgement, questions, goals, education response, and correction-report workflows.
- Implement generic push doorbells and correction notices.
- Validate English plus selected pilot language(s), accessibility, patient/family usability, and teach-back workflow.

**Exit gate:** patient sees only approved, current, comprehensible content; all reference scenarios and correction/retraction tests pass.

### Phase 6 — Eddy reference and staff assistance

- Add pathway/rounds surfaces and provider policies.
- Index only approved, versioned, claim-linked content.
- Add authorized local-only instance context tool.
- Add reference Q&A, rounds preparation, and structured gap-scan drafts.
- Add evaluation datasets for hallucination, attribution, applicability, omission, bias, and unsafe action.
- Add clinical proposal review FSM and monitoring.

**Exit gate:** Eddy answers with exact source/version citations, abstains outside approved scope, never executes clinical action, and passes independent red-team review.

### Phase 7 — External interoperability and scale

- SMART/EHR launch.
- CDS Hooks patient-view informational card.
- FHIR CarePlan/Task/CareTeam/Communication synchronization.
- Governed draft writeback and conflict handling.
- Expand approved pathway cohort by local value and review capacity.
- Reconcile new CMS grouper/guideline releases.

**Exit gate:** external connector conformance, replay, provenance, conflict, correction, and rollback evidence passes in a deployed environment.

### Phase 8 — Optional patient Eddy study

- Separate intended-use statement and regulatory/privacy review.
- Released-projection-only retrieval.
- Explanation-only contract with urgent-help routing and abstention.
- Patient-advisor study, accessibility/language testing, red-team, and monitored limited cohort.

**Exit gate:** separate safety case approved. This phase is not required for the core pathway product.

---

## 19. Repository Implementation Map

### Backend and database

- `database/migrations/2026_07_21_000900_create_care_pathway_catalog.php`
- `database/migrations/2026_07_21_001000_extend_care_pathway_provenance.php`
- `database/migrations/2026_07_21_001100_create_care_pathway_current_provenance_views.php`
- `database/migrations/2026_07_21_001200_create_care_pathway_source_status_ledger.php`
- `database/migrations/2026_07_21_001300_repair_care_pathway_source_retraction_negation.php`
- `database/migrations/2026_07_21_001400_create_care_pathway_release_source_membership.php`
- `database/migrations/2026_07_21_001500_protect_care_pathway_section_sources.php`
- `database/migrations/2026_07_21_001600_enforce_care_pathway_source_membership.php`
- future unique timestamp: `create_care_pathway_instances.php`
- future unique timestamp: `create_care_pathway_awareness.php`
- `app/Models/CarePathways/**`
- `app/Services/CarePathways/CatalogImportService.php`
- `app/Services/CarePathways/CatalogReconciliationService.php`
- `app/Services/CarePathways/ApprovedPathwayCatalogReadService.php`
- `app/Services/CarePathways/CatalogGovernanceReadService.php`
- `app/Http/Middleware/EnsureCarePathwayGovernanceEnabled.php`
- `app/Http/Controllers/Api/CarePathways/CatalogGovernanceController.php`
- `routes/care-pathways.php`
- `app/Services/CarePathways/PathwayReviewService.php`
- `app/Services/CarePathways/PathwayAssignmentService.php`
- `app/Services/CarePathways/PathwayInstanceService.php`
- `app/Services/CarePathways/PathwayReconciliationService.php`
- `app/Services/CarePathways/PathwayProjectionService.php`
- `app/Services/CarePathways/PathwayAwarenessService.php`
- `app/Services/CarePathways/FhirPathwayMapper.php`
- `app/Http/Controllers/Api/CarePathways/**`
- `app/Authorization/Capability.php`
- `app/Providers/AuthServiceProvider.php`
- `app/Providers/RouteServiceProvider.php`
- `config/authorization.php`
- `config/care-pathways.php`
- `routes/api.php`
- `routes/console.php`

### Patient projection

- `app/Services/Patient/Projection/PatientProjectionContentGuard.php`
- `app/Services/Patient/Projection/PatientProjectionDisclosureService.php`
- new production pathway projection adapter under `app/Services/Patient/Projection/`
- `app/Http/Controllers/Api/Patient/EncounterProjectionController.php`
- `routes/patient.php`
- `docs/hummingbird/patient-disclosure-matrix.v1.yaml`
- `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml`

### Virtual Rounds and staff web

- `app/Services/Rounds/RoundProjectionService.php`
- `app/Services/Rounds/RoundCompletionService.php`
- `resources/js/features/virtualRounds/{schemas,types,hooks,api}.ts`
- `resources/js/Components/VirtualRounds/RoundPatientWorkspace.tsx`
- `resources/js/Pages/RTDC/VirtualRounds.tsx`
- `resources/js/Pages/CarePathways/**`
- `resources/js/features/carePathways/**`
- `resources/js/config/navigationConfig.ts` as the only navigation source

### Hummingbird Staff

- `app/Services/Mobile/MobilePatientContextService.php`
- `app/Services/Mobile/MobileForYouService.php`
- `app/Services/Mobile/EddyOperationalAwarenessService.php`
- `docs/hummingbird/api-contract/hummingbird-bff.v1.yaml`
- iOS and Android patient-context/For You models and role views

### Hummingbird Patient

- `hummingbird/iosPatientApp/HummingbirdPatient/Features/Path/PatientPathView.swift`
- `hummingbird/iosPatientApp/HummingbirdPatient/Models/PatientExperienceSnapshot.swift`
- `hummingbird/iosPatientApp/HummingbirdPatient/Networking/**`
- `hummingbird/androidPatientApp/.../PatientExperienceModels.kt`
- `hummingbird/androidPatientApp/.../PatientExperienceScreen.kt`
- `hummingbird/androidPatientApp/.../data/**`

### Eddy

- `app/Services/Eddy/EddyProviderPolicyService.php`
- `app/Services/Eddy/EddyKnowledgeService.php`
- new `EddyPathwayContextService.php`
- new `EddyPathwayDraftService.php`
- `app/Services/Eddy/EddyChatService.php`
- `app/Services/Mobile/EddyOperationalAwarenessService.php`
- `config/eddy.php`
- Eddy service prompt/tool schemas and evaluation fixtures

Do not mix all of these into one pull request. Each phase should update schema, service, contract, client, and tests for one coherent release boundary.

---

## 20. Validation Strategy

### 20.1 Catalog/import tests

- exact 49-column schema;
- 250 rows and 250 unique ranks/conditions;
- source hash/size/row controls;
- verification-workbook hash, eight-sheet topology, and declared-baseline linkage;
- exact workbook controls: 250 pathway/ledger rows, 10,123 claims, 811 sources, 324 changes, and 770 codebook rows;
- 770 unique DRG codes and exact CMS-list reconciliation;
- DRG title/MDC/type consistency;
- 32 overlap codes preserved;
- 96 evidence-verified / 154 limitations distribution;
- 96 signoff / 148 specialist-review / 6 redesign disposition distribution;
- all 250 clinical-signoff values remain not approved;
- every claim PMID resolves to the source index;
- source enrichment resolves every required bibliographic blank or explicitly classifies not-applicable authorship;
- no unclassified required-field absence remains;
- 32,967,000 volume sum and 99.0% final coverage;
- rank 89 rounding discrepancy recorded rather than silently “fixed”;
- source and normalized content digests;
- rerun/idempotency and conflicting-source rejection.

### 20.2 Knowledge engineering tests

- every structured rule links to source claims and approvals;
- no executable rule from unapproved/generalized content;
- inclusion/exclusion boundary fixtures;
- units, ranges, dates, code systems, negation, and conditional branches;
- superseded guideline behavior;
- source retraction/withdrawal;
- local-variant diff and inheritance;
- FHIR validation and canonical version resolution.

### 20.3 Assignment tests

- DRG overlap produces multiple candidates;
- no DRG available at admission;
- late coding change;
- contradictory diagnosis/procedure/service signals;
- missing exclusion data;
- pediatric/adult and pregnancy/newborn separation;
- transfer between services/units;
- multiple pathway policy;
- clinician reject/reassign/supersede;
- stale source cutoff;
- wrong-patient/IDOR denial.

### 20.4 Patient safety and disclosure tests

- no raw source ids, MRN, internal notes, priority/risk score, other-patient data, or unreleased content;
- active grant, scope, relationship, policy, feature flag, and release state required;
- generic denial for unknown/cross-principal/revoked/expired resources;
- correction/retraction immediately removes superseded content;
- acknowledged is not rendered as understood;
- proxy and relationship filtering;
- language fallback and untranslated-content fail closed;
- urgent-help wording present;
- discharge timing always includes uncertainty/can-change language;
- native apps purge inaccessible/stale content.

### 20.5 Eddy evaluation

- citation correctness and exact pathway version;
- applicability and exclusion handling;
- no unsupported condition-specific claims from generalized tail content;
- no action/order language beyond contract;
- abstention on missing/ambiguous pathway;
- stale/withdrawn version exclusion;
- prompt injection in pathway text, citations, patient questions, and EHR fields;
- cloud egress hard block with patient context;
- PHI leakage and memorization tests;
- equal-quality review across age, sex, race/ethnicity, language, disability, payer, and facility cohorts where data and intended use permit;
- reviewer override, rejection, and near-miss monitoring;
- patient explanation red-team as a separate suite.

### 20.6 Human-factors validation

- patient can identify current stage, next step, uncertainty, warning signs, and contact route;
- clinicians distinguish guidance, order, observed fact, and Eddy draft;
- no status color collision with acuity/identity in 4D;
- critical tasks remain visible at 200% zoom and large native text;
- screen-reader order and accessible names;
- high contrast and color-independent status;
- shift-change and transfer handoff;
- alert burden and interruption cost;
- representative/interpreter workflows;
- correction and bad-news communication scenarios;
- downtime and stale-source behavior.

---

## 21. Metrics and Monitoring

### 21.1 Knowledge quality

- pathways by evidence status, confidence, source specificity, release disposition, institutional signoff, local approval, and activation tier;
- claim-audit adjudication coverage, source-to-claim specificity, and claims per supporting source;
- unresolved versus explicitly classified source absence and bibliographic enrichment freshness;
- age of source evidence and last review;
- unresolved source discrepancies;
- time from source change to review/withdrawal;
- rule/fixture coverage;
- patient-copy reading level and translation coverage.

### 21.2 Assignment quality

- candidate acceptance/rejection/reassignment rate;
- time to confirmation;
- final DRG reconciliation agreement, reported as a quality signal rather than a truth label;
- ambiguity and missing-data rate;
- wrong-pathway near misses;
- pathway coverage by unit/service line without flattening the scopes.

### 21.3 Shared awareness

- patient release/view/question/acknowledgement/teach-back rates;
- time from material change to patient explanation;
- unanswered patient questions;
- role acknowledgement and overdue ownership;
- shift/transfer continuity;
- correction/retraction delivery;
- interpreter use and language parity;
- comprehension/usability study outcomes.

### 21.4 Care delivery and balancing measures

- milestone delay and deviation closure;
- LOS, readmission, complications, patient experience, and functional outcomes where valid;
- alert burden, overrides, workarounds, and time-on-task;
- care-team disagreement and pathway non-applicability;
- inequity/fairness measures;
- adverse events and near misses.

Never claim causality from pre/post dashboard trends alone. Define baseline, denominator, risk adjustment, cohort exclusions, comparison strategy, and balancing measures before pilot.

### 21.5 Eddy

- retrieval/citation precision;
- abstention rate;
- unsafe proposal rate;
- reviewer acceptance/edit/rejection by mode;
- patient-context cloud-block events;
- latency and stale-context rate;
- outcome of red-team and drift suites;
- subgroup performance and observed harms.

---

## 22. Rollout and Operational Readiness

### 22.1 Deployment order

1. Adopt/reproduce raw import schema in version control.
2. Deploy catalog/governance tables with no app feature enabled.
3. Load inactive catalog and reconcile controls.
4. Deploy governance console to named reviewers only.
5. Approve pilot pathway versions.
6. Deploy assignment/instance services behind unit/cohort flags.
7. Pilot staff web and Virtual Rounds.
8. Add Hummingbird Staff.
9. Deploy pending patient foundation migrations with all patient features off.
10. Configure release policy and production projection adapter.
11. Pilot Hummingbird Patient.
12. Add Eddy reference Q&A, then local-only staff instance analysis.
13. Add external EHR integration.
14. Consider patient Eddy only under a separately approved study.

### 22.2 Runbooks

- source import and reconciliation;
- CMS/guideline release diff;
- pathway activation/withdrawal;
- emergency clinical correction;
- patient projection retraction;
- wrong-pathway assignment response;
- stale source/projection;
- transfer/shift/service reconciliation;
- notification outage;
- Eddy provider/model rollback;
- embedding rebuild/withdrawal;
- FHIR/CDS Hooks connector replay/conflict;
- privacy incident and access revocation;
- database backup/restore and append-only audit verification.

### 22.3 Pilot go/no-go board

Required sign-off:

- clinical executive sponsor;
- pathway physician owner;
- nursing;
- pharmacy and other applicable disciplines;
- clinical informatics;
- patient safety/quality;
- patient/family advisor;
- privacy/security/legal/regulatory;
- accessibility/language access;
- Hummingbird product owner;
- Eddy AI governance owner;
- operations/SRE/integration owner.

---

## 23. Decisions Required Before Build

1. Formally adopt or reject verification release `1` as the source package for institutional review; database import alone is not adoption.
2. Choose the first facility, units, and 3-5 pilot pathways from the 96-row signoff queue using local data.
3. Name the pathway governance chair and discipline approvers.
4. Define the adjudication standard for the 10,123 claim rows and the minimum source-to-claim specificity needed for each intended use.
5. Define whether more than one pathway family may be active per encounter.
6. Define the minimum evidence and review tier for staff reference, staff workflow, and patient release separately.
7. Decide whether the six redesign/non-protocol candidates remain discoverable catalog entries or are excluded from pathway authoring.
8. Select the canonical local service-line crosswalk and owners for unmapped values.
9. Choose the source EHR/FHIR signals used for candidate matching and milestone reconciliation.
10. Approve the patient-language style, reading-level target, supported pilot languages, and translation process.
11. Define acknowledgement versus teach-back versus consent semantics.
12. Approve notification channels, urgency policy, and quiet-hour/escalation rules.
13. Classify each Eddy intended use and decide whether cloud use is ever permitted for PHI-free reference content.
14. Decide the retention/withdrawal policy for AI traces, pathway instances, and awareness events.
15. Decide whether external CarePlan/Task writeback is in the pilot or deferred.

---

## 24. Definition of Done

The care-pathway integration is complete only when:

- the CSV, verification workbook, worksheet snapshots, completeness resolutions, and adopted source release are reproducible, immutable, and reconciled;
- every required source field is present or carries an explicit, evidence-backed not-applicable/not-listed state;
- automated evidence status, source limitations, clinical signoff, local approval, and activation cannot be confused in storage, APIs, UI, analytics, or Eddy prompts;
- no source row lacking completed institutional review and local approval can appear in clinical or patient workflow;
- pathway definitions, evidence, versions, and local approvals are queryable and auditable;
- assignment is explainable and clinician-confirmed, not DRG-only;
- encounter instances are pinned, versioned, and correctable;
- patients receive approved plain-language Today/My Path/Care Team/Rounds/Discharge projections;
- patients can ask questions, state goals, and complete governed education/teach-back workflows;
- care-team roles see the current stage, tasks, deviations, patient understanding, and changes in Zephyrus and Hummingbird;
- Virtual Rounds and the 4D viewer project the same authorized pathway state;
- Eddy retrieves exact approved versions, cites sources, uses local-only patient context, and produces drafts only;
- transfers, shift changes, discharge, revocation, correction, withdrawal, downtime, and stale-source scenarios pass end to end;
- accessibility, language access, privacy, security, clinical safety, patient-advisor, and AI red-team gates pass;
- pilot metrics include comprehension, equity, alert burden, workarounds, near misses, and balancing measures—not only utilization or LOS;
- named governance owners approve production activation and retain a tested kill switch.

Until these conditions are met, the correct state is **catalog and evidence package available for governed institutional review**, not **clinical pathway deployed**.
