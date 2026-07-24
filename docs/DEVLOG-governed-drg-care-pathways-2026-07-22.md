# DEVLOG — Governed DRG Care Pathways, Verification Closure, and Journey Demo

**Date:** 2026-07-22  
**Repository:** Zephyrus  
**Branch:** `main`  
**Catalog commit:** `7dd1f4b345dcd51d365683802bd62011ac8f8e61` (`Add governed care pathway catalog`)  
**Demo commit:** `930ced8b24c283a73cc7a662e57692f26e5155fb` (`Add governed care pathway journey demo`)  
**Production commit marker (as of the 2026-07-22 deployment):** `930ced8b24c283a73cc7a662e57692f26e5155fb` — production has since been redeployed with later `main` commits; the deployment evidence below is a point-in-time snapshot of that feature deployment, not a claim about the current production tip.  
**Canonical release:** `drg-care-pathways-verification-package-v43.1-20260721`  
**Canonical release ID:** `2`  
**Production catalog state:** `inactive`  
**Production demo state:** enabled, synthetic, read-only, and non-clinical  
**Production application:** <https://zephyrus.acumenus.net>

## Executive summary

This delivery turns a deeply researched 250-pathway DRG package into a governed Zephyrus knowledge asset without pretending that a research import is the same thing as an approved clinical protocol.

The work delivered four related outcomes:

1. The original 49-column CSV and the eight-sheet verification package were preserved and reconciled in PostgreSQL as immutable source evidence.
2. A new `care_pathways` schema was added for normalized catalog, evidence, provenance, review, approval, and activation state.
3. Every classified source-data absence and two important provenance defects were resolved without overwriting the imported evidence.
4. A production-deployed synthetic journey now demonstrates how the same pathway could eventually appear to a care team, Virtual Rounds and the 4D hospital view, Hummingbird Staff, Hummingbird Patient, Eddy, and governance reviewers.

The feature intentionally does **not** activate any of the 250 pathways. All 250 canonical versions remain `not_reviewed` and `inactive`; there are no clinical approvals, encounter assignments, patient releases, or live Eddy pathway retrievals. The demo is an in-memory presentation overlay over real catalog metadata. It does not write to PostgreSQL, create a diagnosis or order, or relax any clinical-serving gate.

That separation is the central design decision:

```text
source evidence
  -> automated verification
    -> canonical inactive catalog
      -> institutional review and approval
        -> explicit activation
          -> encounter candidate and clinician confirmation
            -> staff projection
              -> separately released patient projection
                -> bounded Eddy reference or draft assistance
```

The result is a strong data and governance foundation plus a realistic product demonstration. It is not yet a production clinical pathway program.

## Why this work was necessary

The source package is much richer than a simple list of diagnoses. It combines pathway narrative, MS-DRG coverage, evidence claims, citations, verification results, limitations, change history, and coverage controls. Loading that material directly into a serving table would have created several unsafe ambiguities:

- evidence verification could be mistaken for local clinical approval;
- an MS-DRG could be treated as an admission-time assignment rule;
- source prose could leak into patient or Eddy experiences without audience-specific review;
- overlapping DRGs could silently select the wrong pathway;
- corrections to source status could erase the imported history;
- a missing source field could be filled with invented data;
- a research-tail template could be represented as a condition-specific executable protocol; and
- a bulk activation could expose all 250 pathways before local eligibility, exclusion, ownership, escalation, language, and safety policies exist.

The implementation therefore treats the package as an evidence-backed candidate catalog. It creates the authorities needed for later clinical authoring and activation while keeping every serving path fail-closed.

## Scope delivered

### Catalog and governance foundation

Commit `7dd1f4b3` added 55 files and 9,184 lines. Its principal deliverables are:

- checksum-pinned release configuration;
- source reconciliation and canonical adoption services;
- an accountable Artisan adoption command;
- eight additive PostgreSQL migrations;
- 25 Eloquent models for catalog and provenance authorities;
- an approved-content read boundary;
- a staff-only governance read service and ten GET endpoints;
- eight separate care-pathway capabilities and dedicated role profiles;
- schema, adoption, authorization, API, model-contract, and read-boundary tests; and
- a detailed integration strategy and execution checklist.

### Journey demonstration

Commit `930ced8b` added 16 files and 1,676 lines. Its principal deliverables are:

- a synthetic heart-failure journey scenario service;
- a six-step Inertia/React experience;
- care-team, Virtual Rounds, Hummingbird Staff, Hummingbird Patient, Eddy, and governance views;
- an authenticated, feature-gated page and read-only API;
- conditional navigation through the existing `navigationConfig.ts` authority;
- PHP and Vitest coverage for release boundaries and API-envelope handling; and
- a concise operator/demo script.

## Authoritative source package

### Imported release identity

The production canonical release records the following immutable identity:

| Control                            | Production value                                                   |
| ---------------------------------- | ------------------------------------------------------------------ |
| Dataset key                        | `drg-care-pathways-verification-package-v43.1-20260721`            |
| Source CSV SHA-256                 | `2e3ac28238cdb8d7e1002117de6ad824d71882dae54df77fe4abd214b268a6ae` |
| Verification workbook SHA-256      | `42cadf84dce297c5a839784148ebd2c5375320350394c0d143411008ed5bd171` |
| Workbook-declared baseline SHA-256 | `6819c1e111985da1fc62f38cdd85dd2a34b69308f4cf9be9f8941fbce62bf8fd` |
| Grouper                            | CMS MS-DRG v43.1                                                   |
| Effective period                   | 2026-04-01 through 2026-09-30                                      |
| Semantic version                   | `43.1-source.1`                                                    |

The verification workbook contains these eight sheets:

1. `QA_Summary`
2. `Verification_Ledger`
3. `Verified_Pathways`
4. `Claim_Audit`
5. `Source_Index`
6. `Change_Log`
7. `MS_DRG_Codebook`
8. `Methodology`

The adoption process does not trust a workbook summary merely because it exists. Counts, identities, mappings, and cross-sheet relationships are recomputed from the imported raw relations and compared with the configured release controls.

### Post-deployment workspace digest caveat

Neither the workbook nor the CSV currently present in the shared worktree is byte-identical to the artifacts production release `2` adopted. Both files are now git-tracked (committed in `c90a4aba`, "Preserve care pathway and planning artifacts") and both were regenerated after adoption — most likely by the UMLS 2026AA terminology-expansion pass, which also produced the separate `*_Terminology_Expanded` variants.

The workbook now present at `DRG_Care_Pathways_250_Verification_Package_v43_1.xlsx` hashes to:

```text
6617bda522a55bfa3e6971b00bb3d1862d6f6567a1119f07f2682e468e5c293e
```

The CSV now present at `DRG_Care_Pathways_250_PATHWAYS_99PCT_v43_1.csv` hashes to:

```text
fcd6986ce3a4a0ee69e3120f18353a2d371d456ad397304f7e0eea387a80f5f0
```

Neither matches the immutable digest recorded by production release `2` (`42cad...` for the workbook, `2e3ac282...` for the CSV). A different XLSX byte digest can result from either substantive workbook changes or ZIP/package reserialization; the CSV retains the original 49-column, 250-row shape but its bytes have changed. Semantic equivalence has not been established for either file. Consequently:

- this devlog describes the package actually adopted by production, whose digests are `42cad...` (workbook) and `2e3ac282...` (CSV);
- the current shared-worktree copies must not be represented as byte-identical to that release;
- the originally adopted CSV (`2e3ac282...`) is not recoverable from this machine — it does not appear in the working tree, anywhere in git history, or in the production deployment tree at `/var/www/Zephyrus` — so any CSV reconciliation must be semantic rather than a byte diff;
- no reimport was performed as part of this documentation-only task; and
- because **both** artifacts have diverged, any future adoption of the current files must first run full reconciliation and use a new immutable release identity rather than mutating release `2`.

> **Correction (2026-07-24):** An earlier revision of this section described the workbook as "untracked" and stated that "the local CSV still matches the production-pinned CSV digest." Both statements were inaccurate: the files are tracked, and the CSV had already diverged to `fcd6986c...` at the time of writing. Corrected after a verification audit that searched the working tree, full git history, and the production deployment tree for the pinned `2e3ac282...` CSV and found no match.

## Reconciled inventory

The imported and canonicalized release contains:

| Authority                     |  Count | Meaning                                              |
| ----------------------------- | -----: | ---------------------------------------------------- |
| Pathway definitions           |    250 | Stable condition/pathway identities                  |
| Pathway versions              |    250 | Immutable source-content versions                    |
| Source sections               |  7,000 | 28 source-only, staff-reference sections per version |
| CMS v43.1 DRG codes           |    770 | Unique codebook authority                            |
| Pathway-to-DRG mappings       |    802 | Many-to-many candidate associations                  |
| Overlapping DRG codes         |     32 | DRGs with more than one candidate pathway            |
| Evidence claims               | 10,123 | Independently modeled claim segments                 |
| Claim-to-source links         | 53,994 | Claim-level citation relationships                   |
| Indexed sources               |    811 | Unique versioned PMID-based sources                  |
| Source release changes        |    324 | Imported source/version change history               |
| Current enrichment facts      |     20 | Normalized, field-level absence resolutions          |
| Current completeness controls |      7 | Classified completeness checks                       |
| Current residual unknowns     |      0 | No unclassified required-field absences              |
| Service-line mappings         |     26 | 3 mapped and 23 deliberately pending                 |
| Clinical reviews              |      0 | No institutional review decision recorded            |
| Clinical approvals            |      0 | No institutional approval recorded                   |

The 250 pathways represent approximately `32,967,000` cases and a stated planning coverage of `99.0%`.

## Evidence state is not approval state

The verification package divides the catalog into two evidence groups:

| Evidence status                                      | Pathways | Interpretation                                            |
| ---------------------------------------------------- | -------: | --------------------------------------------------------- |
| Automated independent evidence verification complete |       96 | May enter institutional signoff; not clinically approved  |
| Verification complete with documented limitations    |      154 | Requires specialist adjudication; not clinically approved |

It also creates three release-work queues:

| Disposition                              | Pathways | Required next action                                                             |
| ---------------------------------------- | -------: | -------------------------------------------------------------------------------- |
| Institutional signoff candidate          |       96 | Full local multidisciplinary review                                              |
| Specialist review                        |      148 | Resolve documented specificity, currency, heterogeneity, or template limitations |
| Redesign or explicit non-protocol status |        6 | Do not convert directly into an executable pathway                               |

Every pathway still has zero clinical signoff. Automated verification established traceability and consistency; it did not establish institutional applicability, approved eligibility/exclusion logic, order authority, patient copy, or local role ownership.

The canonical model preserves these as distinct fields:

- evidence status;
- verification confidence;
- source specificity;
- unresolved evidence flags;
- release disposition;
- clinical signoff state;
- institutional approval state; and
- activation state.

That is why a green evidence indicator cannot accidentally activate a clinical workflow.

## Absence and provenance remediation

“Find and correct absent data” was implemented as a provenance exercise, not a fill-blank exercise. The operating rule was: classify and resolve what can be evidenced, preserve the source cell, and never invent a person, citation, mapping, or clinical definition.

### Bibliographic absences

The final source audit produced 16 source-resolution records and 20 normalized field facts:

- PMID `38349295` received five separately evidenced bibliographic metadata facts; and
- 15 sources without a named personal author received explicit `not listed` semantics rather than fabricated author names.

These facts live in append-only enrichment authorities. The raw workbook cells remain unchanged.

### Completeness controls

Seven normalized completeness controls retain:

- source blank count;
- absence classification;
- corrective action;
- evidence;
- residual unknown count;
- original raw control record; and
- a digest of the normalized fact.

The current provenance view reports zero residual unclassified required-field absences.

### Retraction-negation correction

The audit found that all 811 explicit negative phrases equivalent to “No PubMed retraction indicator detected” had initially been parsed as if they indicated a retraction. The source rows were not rewritten. Instead, the implementation added:

- an append-only `source_status_events` ledger;
- a `current_source_statuses` view;
- 811 corrective current-state events; and
- superseding release controls describing the repair.

The current view reports 811 current sources and zero residual non-current sources for this release.

This is a useful example of why imported facts and current interpreted state are separate authorities: a parser correction should not erase the original import or its audit history.

### Complete source membership

Another audit found that 16 indexed sources were not cited by any current evidence claim. They were present in the source index but lacked an explicit canonical relationship to the release.

The `catalog_release_sources` authority now records all 811 indexed sources:

- 795 are cited by one or more evidence claims; and
- 16 are uncited but still explicitly belong to the release.

Release membership is therefore complete and independent of current citation use.

### Citation integrity

The final relational hardening added these guarantees:

- claim citations must reference sources in the same catalog release;
- approved section citations must reference sources in the same catalog release;
- release-source membership carries the exact immutable source digest;
- claim-source and section-source relationships are append-only;
- a version is suppressed if a claim or approved-section citation resolves to a non-current source; and
- an audience with no approved content receives no pathway content.

### Deliberately unresolved service-line mappings

The production catalog contains 26 service-line mapping records:

- 3 map to an existing canonical service-line authority; and
- 23 remain `pending` because the source labels are composite or do not exist in the local registry.

These 23 items are not residual unclassified data absences. They are a governed institutional crosswalk queue. Guessing them would collapse distinct clinical and organizational scopes and would be less correct than retaining an explicit pending state.

### Accepted discrepancies

Production contains 23 passed controls and three accepted discrepancies. The accepted discrepancies are visible and justified:

1. **CMS code-count discrepancy:** the adopted CSV matches the CMS v43.1 list-page authority with 770 distinct codes, while a CMS design document states 772 total MS-DRGs.
2. **Rank 89 rounding:** the source value `87.9%` is preserved while the independent calculation rounds to `88.0%`.
3. **Service-line crosswalk:** 23 source labels remain pending instead of being mapped by guesswork.

There are zero failed controls.

## Raw-to-canonical data architecture

### Layer 1: immutable raw evidence

The source CSV, workbook sheets, import manifest, complete-source view, enrichment facts, and completeness audit are preserved in `raw`. The raw layer is the reproducible evidence record; it is not a runtime serving API.

The imported relations cover:

- original pathway CSV rows;
- import and verification manifests;
- verified pathway rows;
- verification ledger rows;
- claim audit rows;
- raw and completed source indexes;
- source enrichment records;
- source release changes;
- MS-DRG codebook rows;
- completeness controls;
- QA summary; and
- methodology.

No raw import inserts patient, rounds, Eddy, or application-serving content.

### Layer 2: governed canonical catalog

The additive `care_pathways` schema separates authorities by responsibility.

| Concern                        | Principal authorities                                                                        |
| ------------------------------ | -------------------------------------------------------------------------------------------- |
| Release identity and controls  | `catalog_releases`, `catalog_release_controls`, `catalog_release_sources`                    |
| Stable pathway identity        | `definitions`                                                                                |
| Immutable content versions     | `versions`                                                                                   |
| DRG authority                  | `drg_codebook_entries`, `drg_mappings`                                                       |
| Narrative and approved content | `sections`, `section_sources`                                                                |
| Future workflow authoring      | `stage_definitions`, `milestone_definitions`, `activity_definitions`, `goal_definitions`, `education_definitions` |
| Evidence                       | `sources`, `source_status_events`, `evidence_claims`, `claim_sources`, `source_changes`      |
| Data remediation               | `source_enrichments`, `completeness_resolutions` and their current views                     |
| Institutional taxonomy         | `service_line_mappings`                                                                      |
| Governance decisions           | `reviews`, `approvals`, `events`                                                             |

Bigint surrogate keys are used internally; UUID business keys are exposed across application boundaries. The schema deliberately has no foreign keys into `raw`, `prod`, `rounds`, `patient_experience`, or `eddy`.

### Layer 3: approved-content read boundary

`ApprovedPathwayCatalogReadService` is the only intended source for later staff-facing clinical serving. It fails closed unless all of the following are true:

- the catalog feature is enabled;
- the catalog release is active;
- release clinical signoff is complete;
- the pathway definition is active;
- the exact pathway version is institutionally approved and active;
- the version is within its effective period;
- claim and approved-section sources are current;
- the requested audience is a permitted staff audience;
- a section is explicitly approved or released;
- approved text and its digest are present; and
- at least one eligible section remains after filtering.

The service excludes raw snapshots and source prose. Patient audiences are rejected at this boundary; they must use separately governed `patient_experience` projections.

The DRG lookup returns candidate versions, not an assignment. All 32 overlapping DRGs remain multiple candidates and require a separate matcher plus clinician confirmation.

### Layer 4: audience projections

The integration plan uses the approved catalog as an upstream knowledge authority and creates audience-specific projections for:

- Zephyrus care-team workflow;
- Virtual Rounds;
- privacy-bounded 4D status;
- Hummingbird Staff;
- Hummingbird Patient;
- Eddy approved reference retrieval; and
- Eddy local-only patient-instance drafts.

These are not interchangeable payloads. A source section, staff instruction, patient explanation, mobile notification, 4D marker, and Eddy chunk each have different disclosure, freshness, provenance, and approval rules.

## Database protections

The database is an active safety boundary, not just persistence.

### Immutable and append-only records

PostgreSQL triggers reject update or delete operations on:

- release controls;
- codebook entries;
- DRG mappings;
- evidence claims;
- claim-source relationships;
- section-source relationships;
- sources;
- source changes;
- source enrichments;
- completeness resolutions;
- source status events;
- reviews;
- approvals; and
- audit events.

Imported version content and source-section content are immutable. Corrections require a superseding version or an append-only fact.

### Activation gates

The database rejects catalog activation when:

- clinical signoff is incomplete;
- any version lacks institutional approval;
- any version is inactive; or
- the active-version count does not equal the release pathway count.

The zero-version edge case is also guarded. Application code cannot bypass these constraints by issuing a direct update.

### Content-state constraints

The schema also enforces that:

- executable activities require approved review state;
- approved or released sections require approved text and a digest;
- approved education requires reviewed content, a teach-back prompt, and a digest; and
- source-only sections cannot be silently treated as approved content.

## Reconciliation and adoption workflow

The operator entry point is:

```bash
php artisan care-pathways:adopt-raw-release 1 \
  --actor=<stable-data-steward-or-service-actor> \
  --dry-run \
  --json
```

After a successful dry run, the same command without `--dry-run` performs the atomic canonical adoption.

The command and services:

1. require an accountable actor;
2. resolve the configured raw relations;
3. take a transaction-level PostgreSQL advisory lock for the dataset key;
4. validate release identity and immutable checksums;
5. recompute controls from the raw relations;
6. reconcile pathway and ledger rank, condition, and DRG identity;
7. reconcile the pathway DRG set with the codebook;
8. reject orphan claims and unknown PMIDs;
9. reject unclassified title or URL absence;
10. reject any residual required-field absence;
11. write the canonical release in one transaction; and
12. return the existing release without duplicates on an exact rerun.

The same dataset key with different immutable hashes is rejected. A dry run performs zero canonical writes.

For the full production release, the optimized adoption batched:

- 7,000 sections;
- 10,123 evidence claims; and
- 53,994 claim-source links.

It completed in under five seconds. An exact production rerun returned release `2` and created no duplicate release, event, or content rows.

One reproducibility gap remains: the repository can adopt the deployed raw topology, but it does not yet contain a complete fresh-database XLSX/CSV ingestion pipeline. A durable workbook parser or checksum-pinned normalized release bundle still needs to be selected and tested.

## Governance API and authorization

### Read API

The staff governance API is mounted at `/api/care-pathways/v1` and provides ten authenticated GET endpoints:

| Endpoint                         | Purpose                                                             |
| -------------------------------- | ------------------------------------------------------------------- |
| `/summary`                       | Release identity, status, counts, warnings, and caller capabilities |
| `/pathways`                      | Paginated and filtered pathway queue                                |
| `/versions/{versionUuid}`        | Exact version, sections, mappings, and governance state             |
| `/versions/{versionUuid}/claims` | Claim-level evidence inventory                                      |
| `/sources`                       | Complete source index with citation and status filters              |
| `/sources/{sourceUuid}`          | Exact source and release membership detail                          |
| `/controls`                      | Passed, accepted, failed, or not-applicable controls                |
| `/reviews`                       | Append-only review decisions                                        |
| `/approvals`                     | Append-only approval decisions                                      |
| `/events`                        | PHI-safe governance event history                                   |

All routes require:

- the governance feature gate;
- an authenticated web session;
- `viewCarePathwayCatalog` authorization; and
- request throttling.

Governance DTOs exclude raw payloads, credential-like manifest data, and local filesystem paths. Responses explicitly warn that automated evidence verification is not institutional clinical approval.

The current slice is read-only. Review, approval, activation, withdrawal, and correction mutations remain future work and will require form validation, idempotency, expected-version checks, step-up authentication, and independent-actor controls.

### Capability separation

Eight dedicated capabilities prevent one broad administrative permission from becoming clinical authority:

- `viewCarePathwayCatalog`
- `adoptCarePathwaySource`
- `authorCarePathwayContent`
- `reviewCarePathwayEvidence`
- `approveCarePathwayClinical`
- `activateCarePathwayCatalog`
- `viewEncounterCarePathway`
- `manageCarePathwayInstances`

Role profiles distinguish data steward, pathway author, evidence reviewer, clinical approver, release manager, and instance manager. Source adoption is not approval, and clinical approval is not release activation.

## Why all real pathways remain inactive

The inactive state is correct and expected. It is not evidence of a broken import.

Production currently has:

- 250 definitions in candidate state;
- 250 versions with `institutional_approval_status=not_reviewed`;
- 250 versions with `activation_status=inactive`;
- 7,000 `source_candidate` sections for `staff_reference`;
- zero approved staff sections;
- zero stage definitions;
- zero milestone definitions;
- zero activity definitions;
- zero goal definitions;
- zero education definitions;
- zero reviews; and
- zero approvals.

The five workflow-authoring tables are intentionally empty. The source contains researched prose and evidence; it does not yet contain institutionally approved, executable milestone, activity, goal, or patient-education definitions. Creating those rows by parsing narrative heuristically would convert research language into clinical behavior without review.

Before any real version can activate, a local institution must establish at least:

- intended use and pilot scope;
- eligibility and exclusion rules;
- multidisciplinary clinical review;
- required approving disciplines and independent approval policy;
- exact milestones, activities, goals, and owners;
- local policy and order-set relationships;
- source and guideline currency review;
- patient-language content and release policy;
- accessibility, language-access, and patient-advisor review;
- escalation, correction, withdrawal, and downtime behavior;
- assignment and wrong-pathway safeguards;
- monitored rollback and kill switches; and
- explicit release-manager activation.

The platform now has a place to record and enforce these decisions. It does not manufacture the decisions.

## Synthetic journey demo

### Purpose

The demo answers the product question “What will this feel like once a pathway is approved?” without turning unapproved research content into clinical guidance.

It uses a fictional adult inpatient, **Jordan Lee**, and a heart-failure scenario. Heart Failure is an evidence-verified candidate, but the demonstration does not approve or activate it. The scenario combines real catalog counts and state with purpose-authored synthetic workflow content.

### Six-step story

| Step | Label                | What becomes visible                                                                           |
| ---: | -------------------- | ---------------------------------------------------------------------------------------------- |
|    0 | Evidence review      | Provenance, catalog controls, and activation blockers                                          |
|    1 | Candidate match      | Matched signals, conflicts, unavailable final DRG, and confirmation requirement                |
|    2 | Clinician confirm    | Sandbox-only assignment, milestone ownership, and explicit no-order/no-diagnosis boundary      |
|    3 | Coordinate rounds    | Virtual Rounds, 4D badge, Hummingbird Staff, patient question, and Eddy draft                  |
|    4 | Patient awareness    | Separate plain-language Hummingbird Patient projection                                         |
|    5 | Supported transition | Resolved transportation barrier, answered question, observed teach-back, and synthetic closure |

The step is a bounded query parameter from `0` through `5`. The service clamps internal callers to the same range. Advancing or resetting the demo changes only the returned in-memory projection.

### Care Team surface

The care-team panel demonstrates:

- candidate, confirmed, active, and completed demo states;
- exact pathway/version display;
- matched signals and unresolved conflicts;
- explicit confirmation rather than automatic DRG assignment;
- current stage;
- four synthetic milestones with accountable role owners;
- next decisions;
- transportation barrier ownership;
- patient-awareness state; and
- a clear statement that the clinician confirmation is simulation-only.

### Virtual Rounds and 4D surface

The Virtual Rounds panel appears at step 3 and demonstrates:

- the `Coordinated recovery` stage;
- a transportation variance;
- one open patient medication question;
- role-shaped Nursing, Pharmacy, and Care Management inputs; and
- a minimal 4D payload.

The 4D projection contains an opaque `ptok_` context reference, stage code, and status code. It explicitly contains no narrative or raw patient identifier. The 4D hospital model is treated as a projection surface, not the source of workflow state.

### Hummingbird Staff surface

The staff-mobile projection demonstrates:

- an `action_due` For You item;
- the current pathway stage and next owner;
- milestone progress;
- an opaque context reference;
- a deep link into the demo; and
- a generic PHI-free push notification that acts only as a doorbell.

The notification text does not carry patient details. Authorization and current context would be resolved after the user opens the app.

### Hummingbird Patient surface

The patient projection remains locked until step 4. It demonstrates:

- a separate release boundary from staff content;
- purpose-authored plain language;
- “why you are here” and “your plan for today” framing;
- patient-authored and care-team-authored goals;
- a patient medication question routed to the care team;
- an `answered_in_person` terminal state;
- urgent-help wording; and
- an observed teach-back record that does not claim comprehension.

The patient text is never generated from raw CSV prose at request time.

### Eddy surface

The Eddy panel demonstrates a future `rounds_preparation` mode with:

- a concise, draft-only gap scan;
- the medication reconciliation and transportation issues;
- the patient’s medication question;
- the 2022 AHA/ACC/HFSA Heart Failure Guideline, PMID `35363499`;
- the verification package and exact pathway rank; and
- explicit limitations and local-approval status.

The demo guardrails state that Eddy:

- may not diagnose;
- may not order;
- may not activate a pathway;
- may not send patient context to a cloud provider;
- may not write patient context to global memory; and
- produces a draft for accountable human review.

This is a product contract demonstration, not a live Eddy pathway retrieval implementation.

### Governance surface

The governance panel deliberately displays both truths at once:

- real catalog state: `inactive`; and
- demo overlay state: `simulation_only`.

It also shows the 250/96/154 catalog distribution, zero clinical signoffs, zero failed controls, and zero residual unknowns. Reviewers can see why strong data quality does not equal clinical activation.

## Demo implementation

### Routes and gates

The demo uses:

- page: `GET /care-pathways/demo`
- API: `GET /api/care-pathways/v1/demo/scenario?step={0..5}`

Both routes require an authenticated staff session. The API is throttled. When `CARE_PATHWAYS_DEMO_ENABLED=false`, middleware returns 404 and the navigation item disappears.

The demo flag is independent of every clinical serving flag:

```dotenv
CARE_PATHWAYS_DEMO_ENABLED=true
CARE_PATHWAYS_GOVERNANCE_ENABLED=false
CARE_PATHWAYS_CATALOG_ENABLED=false
CARE_PATHWAYS_ASSIGNMENT_ENABLED=false
CARE_PATHWAYS_ROUNDS_ENABLED=false
CARE_PATHWAYS_STAFF_MOBILE_ENABLED=false
CARE_PATHWAYS_PATIENT_ENABLED=false
CARE_PATHWAYS_EDDY_REFERENCE_ENABLED=false
CARE_PATHWAYS_EDDY_INSTANCE_ENABLED=false
CARE_PATHWAYS_WRITEBACK_ENABLED=false
```

Production explicitly enables the demo. That does not alter the canonical release or enable clinical serving.

### Scenario contract

`CarePathwayDemoScenarioService` returns:

- metadata marking the response synthetic, read-only, and non-clinical;
- the six-step progression;
- a live catalog snapshot when available;
- fictional subject context;
- six integration-surface projections; and
- an in-memory event timeline.

If the catalog is unavailable in an isolated frontend environment, the service falls back to configured verified-release controls and labels their source accordingly. It does not invent a live-database observation.

### Frontend behavior

`resources/js/Pages/CarePathways/Demo.tsx` provides:

- step controls;
- integration-surface tabs;
- locked-state explanations before a release boundary;
- light/dark styling through the existing application shell;
- status pills using text in addition to color;
- privacy and clinical-use warnings; and
- reset and forward/back navigation.

The Care Pathways navigation domain was added only to `navigationConfig.ts`, preserving the repository’s single navigation authority.

### API-envelope defect found during browser QA

Interactive testing uncovered a failure mode where malformed or contaminated API output could replace the active scenario and crash the UI. The frontend now routes updates through `scenarioFromApiEnvelope`, which verifies that:

- the response is an object;
- it contains a `data` object;
- `data.meta.current_step` exists; and
- `data.steps` is an array.

Malformed payloads raise a bounded error and do not silently become scenario state. Vitest coverage includes undefined data, string contamination, and incomplete envelopes.

## Integration strategy beyond the demo

The demo establishes desired user behavior. The following sections describe the real integration still to be built after institutional approval.

### Zephyrus encounter pathway workspace

The real care-team flow should:

1. collect candidate signals from diagnosis, setting, service, and other locally approved inputs;
2. treat DRG as a candidate or retrospective reconciliation signal, never the sole assignment rule;
3. display matches, conflicts, missing inputs, exclusions, and exact pathway version;
4. require a clinician to confirm, reject, or defer the candidate with a reason;
5. pin the encounter instance to an exact approved version;
6. create milestone instances with role and named-owner accountability;
7. record evidence, deviation, correction, transfer, and closure as append-only events; and
8. keep orders and EHR writeback outside the initial deployment.

The assignment domain should be additive. It should not put patient-instance state into the catalog tables.

### Virtual Rounds

Virtual Rounds should consume a projection of the encounter instance containing:

- exact version and current stage;
- milestone evidence and freshness;
- unresolved deviations and barriers;
- accountable role and owner;
- released patient questions;
- recent material changes; and
- correction or withdrawal state.

Role inputs must remain distinct. Nursing, Pharmacy, Care Management, Rehabilitation, Medicine, and other disciplines should not be collapsed into a single generic “care team complete” flag.

### Patient Flow 4D

The 4D viewer should receive only a privacy-bounded projection:

- opaque context reference;
- location already authorized for the viewer;
- pathway stage code;
- attention/completion state;
- barrier or question count; and
- freshness timestamp.

It should not receive source narrative, diagnosis detail, patient-authored text, citation prose, or a new workflow authority. Selecting the 4D marker should deep-link into the authorized workflow that owns the state.

### Hummingbird Staff

Hummingbird Staff should receive BFF-shaped projections for:

- For You work items;
- current stage and next owner;
- milestone progress;
- patient questions assigned to the user’s role;
- material-change awareness; and
- deep links using opaque context references.

Push notifications should remain generic and PHI-free. The mobile client must reauthorize and retrieve current state after opening. Offline storage, access revocation, cache purge, and iOS/Android contract parity require explicit testing.

### Hummingbird Patient

Hummingbird Patient must not consume staff sections or raw research prose. It requires a separate projection and release workflow with:

- locally authored plain-language content;
- language and accessibility review;
- patient/family-advisor review;
- relationship-aware patient/proxy authorization;
- “what is happening,” “what is next,” and “who can help” framing;
- uncertainty and urgent-help language;
- attributable patient and care-team goals;
- question routing and accountable response;
- acknowledgement distinct from understanding; and
- observed teach-back distinct from self-attestation.

A correction or pathway withdrawal must revoke or supersede the patient projection without erasing what was previously shown.

### Eddy approved reference plane

The first real Eddy integration should be staff reference retrieval only. Knowledge chunks should be keyed by:

- exact pathway version;
- section;
- intended audience;
- effective period;
- institutional approval;
- evidence claim and source;
- source current/withdrawn state; and
- provider, model, prompt, and policy version.

Eddy must retrieve only active, approved content and return exact citations, limitations, applicability, and abstention. Raw CSV/workbook text must not be bulk-copied into the existing generic Eddy knowledge table.

### Eddy patient-instance staff plane

Patient-instance assistance is a later, separately gated capability. It should:

- resolve an opaque encounter reference server-side on every request and stream;
- reauthorize every turn;
- expose one bounded patient instance at a time;
- minimize context to stage, milestone evidence, deviations, questions, and freshness;
- force local-only provider routing;
- create drafts only;
- require evidence for assertions or abstain;
- require human accept, edit, or reject;
- prohibit orders, activation, and clinical signature;
- prohibit global memory, auto-curation, and cross-patient carryover; and
- pause on revocation, discharge, stale context, source withdrawal, or validation failure.

The current demo expresses these requirements but does not wire live patient context into Eddy.

### Standards and external integration

FHIR R4 CPG artifacts, SMART-on-FHIR launch, informational CDS Hooks, CarePlan/Task/CareTeam/Communication projections, and EHR synchronization belong to a later phase. Any connector will require transactional outbox, idempotency, reconciliation, replay, conflict handling, provenance, and an outbound kill switch. Writeback remains a separately governed program.

## Privacy and clinical-safety invariants

The implementation and plan preserve these non-negotiable boundaries:

- no patient identifiers or clinical prose in catalog audit metadata;
- no raw source text in patient, rounds, 4D, Hummingbird, or Eddy serving paths;
- no patient audience at the approved staff catalog boundary;
- no automatic pathway assignment from one DRG;
- no conflation of evidence verification, clinical approval, activation, and assignment;
- no pathway activation from the demo;
- no patient-record write from demo navigation;
- no PHI in broadcast or push payloads;
- no Eddy diagnosis, order, activation, or signature authority;
- no cloud egress for patient-instance Eddy context;
- no assertion of patient comprehension from a view or acknowledgement; and
- no silent rewriting of source evidence when a correction is learned.

## Testing and validation

### Catalog and database evidence

The first catalog slice passed 20 focused PostgreSQL tests with 140 assertions. Coverage includes:

- schema authorities;
- separate state dimensions;
- activation and immutability triggers;
- no foreign keys into serving or raw schemas;
- dry run;
- inactive adoption;
- exact-rerun idempotency;
- conflicting hash rejection;
- missing-source rejection;
- append-only protections;
- premature activation rejection;
- approved-read gating;
- source-currentness suppression;
- patient-audience rejection;
- overlapping-DRG preservation;
- authorization separation; and
- governance DTO redaction and filters.

The migration-lane equivalent passed 39 tests and 197 assertions. The repository’s Bash wrapper itself is not compatible with the macOS Bash 3 `mapfile` limitation, so its discovered command was run directly.

The wider contract lane recorded 51 passing tests and eight failures in pre-existing, unrelated dirty Hummingbird iOS/Android parity work. No care-pathway slice file appeared in those failures.

### Demo tests

The demo service tests assert:

- every step is synthetic, read-only, and non-clinical;
- the real catalog remains inactive;
- the demo overlay remains `simulation_only`;
- Eddy cannot diagnose, order, or activate;
- opaque context references match the expected shape;
- surfaces unlock only at their designed boundaries;
- patient awareness does not claim understanding;
- transition resolves the barrier and question;
- step input is bounded; and
- mobile and 4D contracts contain no raw patient identifiers.

The final targeted Vitest run passed 35 tests, including navigation behavior and malformed API-envelope regression coverage.

### Build and browser validation

The production build passed from the clean deployed commit and processed 7,945 modules. Browser QA exercised the complete six-step journey and all six surfaces.

Observed end-state checks included:

- care-team assignment `completed`;
- transportation barrier `resolved`;
- Hummingbird Patient plan items `done`;
- patient question `answered_in_person`;
- Virtual Rounds role cards and open-question progression;
- Hummingbird Staff generic notification and opaque context;
- Eddy citations and prohibited-action guardrails; and
- Governance counts of 250 pathways, 96 verified, 154 limitations, zero signoffs, zero failed controls, and zero residual unknowns.

A later local PHP test invocation could not provision its required loopback PostgreSQL test service at `localhost:5432`; it was not redirected to production. PHP syntax, targeted Pint checks, routes, builds, browser behavior, and live read-only catalog smoke checks were used for that final deployment pass.

## Production deployment

Production deployment followed the repository-mandated manual path:

1. the two scoped commits were pushed to `origin/main`;
2. the canonical server checkout was fast-forwarded;
3. `./deploy.sh` performed the application deployment;
4. the production environment enabled only `CARE_PATHWAYS_DEMO_ENABLED=true` for this feature;
5. Laravel caches were cleared;
6. queue and application services were restarted through the deployment workflow;
7. Apache and the Zephyrus virtual host were verified; and
8. live TLS, asset, route, and security checks passed.

No GitHub Actions deployment, direct production `git pull` deployment, alternate deployment script, or ad hoc application rsync path was used.

Production evidence after deployment:

- `/api/health` returned HTTP 200;
- the deployed commit marker matched `930ced8b24c283a73cc7a662e57692f26e5155fb`;
- the Vite manifest contained `resources/js/Pages/CarePathways/Demo.tsx`;
- 12 care-pathway routes were present: one page, one scenario API, and ten governance APIs;
- unauthenticated `/care-pathways/demo` redirected to `/login` as expected;
- all eight care-pathway migrations were recorded;
- the live catalog snapshot returned the production-pinned dataset key and inactive state; and
- the scenario reached step 5 with the patient question `answered_in_person`.

Only the care-pathway migrations were applied. Unrelated pending Hummingbird Patient migrations in the shared working tree were intentionally excluded from this deployment.

## What is real, what is simulated, and what is still planned

| Capability                          | Current status                                         | Important boundary                     |
| ----------------------------------- | ------------------------------------------------------ | -------------------------------------- |
| Raw source and verification import  | Live                                                   | Evidence record only                   |
| Canonical catalog release 2         | Live, inactive                                         | No clinical serving                    |
| Source/completeness remediation     | Live                                                   | Append-only; raw cells preserved       |
| Governance schema and read API      | Implemented, separately gated                          | Staff-only, GET-only                   |
| Approved catalog read service       | Implemented, returns nothing for this inactive release | Fail-closed by design                  |
| Journey demo                        | Live                                                   | Synthetic, read-only, non-clinical     |
| Care-team encounter assignment      | Simulated only                                         | No live patient instance               |
| Virtual Rounds pathway integration  | Simulated only                                         | No live rounds write/read path         |
| 4D pathway overlay                  | Simulated contract only                                | No narrative or identifier payload     |
| Hummingbird Staff integration       | Simulated only                                         | No production pathway BFF endpoint     |
| Hummingbird Patient pathway release | Simulated only                                         | No released clinical patient content   |
| Eddy pathway reference              | Simulated only                                         | No pathway chunks in live retrieval    |
| Eddy patient-instance assistance    | Simulated only                                         | No live patient context passed to Eddy |
| FHIR/EHR integration                | Planned                                                | No writeback                           |

This table is the safest short answer to “Why is it all inactive?” The data and governance foundation is operational; the clinically consequential workflow is deliberately not activated; the demo makes the intended experience reviewable before that authorization exists.

## Activation roadmap

### Immediate release-hygiene work

1. Reconcile both regenerated artifacts against the production-adopted release: the workbook’s `6617...` digest against `42cad...`, and the CSV’s `fcd6986c...` digest against `2e3ac282...`. The pinned CSV bytes are not recoverable locally, so treat this as semantic reconciliation, not a byte diff.
2. Determine for each artifact whether the difference is semantic (e.g., terminology expansion) or package-only reserialization.
3. If either is semantic, create a new dataset key, new immutable manifest, and new canonical release; do not mutate release `2`.
4. Finish a reproducible fresh-database raw loader or normalized release-bundle process.
5. Add minimized, non-PHI fixtures so CI does not depend on absolute local paths.

### Pilot knowledge engineering

1. Select three to five pilot pathways based on local value, evidence strength, source specificity, and available reviewers.
2. Resolve each pilot’s eligibility, exclusions, local policy conflicts, and overlapping DRGs.
3. Author exact staff sections rather than promoting source prose.
4. Populate reviewed milestone, activity, goal, and education definitions.
5. Select claim- and section-level citations from current release sources.
6. Complete required-discipline, independent institutional approval.
7. Set effective periods and withdrawal/review dates.

### Encounter workflow

1. Implement explainable candidate generation.
2. Add clinician confirm/reject/defer workflow.
3. Pin an instance to an exact version and facility scope.
4. Add append-only milestones, evidence, deviations, awareness, and correction events.
5. Test wrong-pathway, transfer, shift-change, stale-source, correction, and downtime scenarios.

### Awareness projections

1. Add care-team and Virtual Rounds projections.
2. Add the minimal 4D projection.
3. Add Hummingbird Staff contracts and PHI-free notification behavior.
4. Author and approve patient content independently.
5. Add Hummingbird Patient relationship-aware release, questions, goals, acknowledgement, and teach-back.
6. Validate accessibility, language access, offline behavior, and native parity.

### Eddy

1. Correct the identified general Eddy clinical-context gaps.
2. Add exact-version approved reference retrieval.
3. Evaluate citation correctness, applicability, currency, abstention, and injection resistance.
4. Add local-only patient-instance draft mode only after the encounter domain is stable.
5. Complete independent clinical and AI red-team approval.

### Pilot go/no-go

Do not enable a clinical serving flag until the pilot has documented signoff from clinical leadership, pathway owners, Nursing, Pharmacy or other applicable disciplines, Clinical Informatics, Patient Safety/Quality, patient/family advisors, Privacy/Security/Legal, Accessibility/Language Access, Hummingbird ownership, Eddy AI governance, and Operations/SRE/Integration. Kill switches, rollback evidence, baseline measures, and balancing measures must also exist.

## Rollback and operational controls

The demo can be removed from user access without altering the catalog:

```dotenv
CARE_PATHWAYS_DEMO_ENABLED=false
```

After changing the environment, clear Laravel configuration cache through the normal operational workflow. This removes the navigation and causes both demo routes to fail closed.

The canonical release remains inactive independently of the demo flag. Clinical-serving flags can remain off even while governance reviewers inspect the catalog or product teams rehearse the demo.

Because catalog records are immutable or append-only, a future data correction should be represented by a new release, superseding version, status event, or correction event. Rollback must not delete the evidence history.

## File map

### Core configuration and routes

- `config/care-pathways.php`
- `routes/care-pathways.php`
- `routes/web.php`
- `app/Providers/RouteServiceProvider.php`

### Reconciliation and serving services

- `app/Services/CarePathways/CatalogReconciliationService.php`
- `app/Services/CarePathways/CatalogImportService.php`
- `app/Services/CarePathways/CatalogGovernanceReadService.php`
- `app/Services/CarePathways/ApprovedPathwayCatalogReadService.php`
- `app/Services/CarePathways/CarePathwayDemoScenarioService.php`

### Operator and HTTP entry points

- `app/Console/Commands/CarePathwaysAdoptReleaseCommand.php`
- `app/Http/Controllers/Api/CarePathways/CatalogGovernanceController.php`
- `app/Http/Controllers/Api/CarePathways/CarePathwayDemoController.php`
- `app/Http/Controllers/CarePathwayDemoPageController.php`
- `app/Http/Middleware/EnsureCarePathwayGovernanceEnabled.php`
- `app/Http/Middleware/EnsureCarePathwayDemoEnabled.php`

### Database migrations

- `database/migrations/2026_07_21_000900_create_care_pathway_catalog.php`
- `database/migrations/2026_07_21_001000_extend_care_pathway_provenance.php`
- `database/migrations/2026_07_21_001100_create_care_pathway_current_provenance_views.php`
- `database/migrations/2026_07_21_001200_create_care_pathway_source_status_ledger.php`
- `database/migrations/2026_07_21_001300_repair_care_pathway_source_retraction_negation.php`
- `database/migrations/2026_07_21_001400_create_care_pathway_release_source_membership.php`
- `database/migrations/2026_07_21_001500_protect_care_pathway_section_sources.php`
- `database/migrations/2026_07_21_001600_enforce_care_pathway_source_membership.php`

### Demo frontend

- `resources/js/Pages/CarePathways/Demo.tsx`
- `resources/js/config/navigationConfig.ts`

### Tests

- `tests/Feature/CarePathways/CarePathwayCatalogSchemaTest.php`
- `tests/Feature/CarePathways/CarePathwayCatalogAdoptionTest.php`
- `tests/Feature/CarePathways/ApprovedPathwayCatalogReadServiceTest.php`
- `tests/Feature/CarePathways/CarePathwayAuthorizationTest.php`
- `tests/Feature/CarePathways/CarePathwayGovernanceApiTest.php`
- `tests/Unit/CarePathways/CarePathwayModelContractTest.php`
- `tests/Unit/CarePathways/CarePathwayDemoScenarioServiceTest.php`
- `tests/js/carePathways/demoScenario.test.ts`
- `tests/js/config/navigationConfig.test.ts`

### Design and operating documents

- `docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-integration-plan.md`
- `docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-TODO.md`
- `docs/CARE-PATHWAY-DEMO-SCENARIO.md`

## Final assessment

The release is successful as a governed knowledge foundation and product-demonstration milestone:

- the researched package is preserved and queryable;
- all expected control totals reconcile;
- required-field absences are classified with zero residual unknowns;
- the source-status and release-membership defects are corrected append-only;
- unapproved content cannot escape through the approved read boundary;
- activation is protected in both application configuration and PostgreSQL;
- the complete care-team-to-patient journey is reviewable in production; and
- the demo is visibly separated from clinical use.

The remaining work is substantive clinical product development, not another bulk-data toggle. The right next move is to reconcile the changed workbook artifact, choose a small pilot cohort, author local definitions, complete multidisciplinary approvals, and implement one audience projection at a time with the same fail-closed boundaries.
