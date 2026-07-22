# DRG Care Pathways, Zephyrus, Eddy, and Hummingbird — Implementation TODO

**Plan:** [2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-integration-plan.md](./2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-integration-plan.md)

**Started:** 2026-07-21

**Current release:** `drg-care-pathways-verification-package-v43.1-20260721`

**Current product state:** Source and verification data are preserved in `raw`; canonical release `2` is deployed and inactive; an off-by-default, staff-only governance read API exists in the repository; no pathway is clinically approved, encounter-assigned, patient-released, Hummingbird-served, Rounds-served, 4D-served, or Eddy-served.

**Convention:** Check items off only when the implementation and named evidence exist. Human, vendor, deployment, and clinical decisions remain unchecked and are marked `deferred`. Do not infer clinical approval from database import, citation resolution, or passing automated tests.

Legend: `[x]` done · `[~]` partially done, with the gap stated inline · `[ ]` open · `(deferred)` requires authority/evidence outside the repository

---

## Non-negotiable safety boundary

- [x] Keep raw source data, automated evidence verification, institutional clinical approval, activation, encounter assignment, staff projection, patient release, and Eddy retrieval as separate states.
- [x] Treat MS-DRGs as coverage, candidate, and retrospective-reconciliation signals; never as sufficient admission-time pathway assignment.
- [x] Preserve all 250 clinical signoffs as incomplete on adoption.
- [x] Default the catalog and every downstream integration flag to off/inactive.
- [x] Keep raw CSV/workbook prose out of `prod`, `rounds`, `patient_experience`, and `eddy` serving paths.
- [x] Preserve 770 unique codebook entries separately from 802 pathway-to-DRG associations and the 32 overlapping DRG codes.
- [x] Model 10,123 claims independently from source registry rows and section-level citations.
- [ ] Require exact-version, independent clinical approval before a version can activate.
- [ ] Require a separate approved patient-language artifact and release policy before patient projection.
- [ ] Require local-only, freshly authorized context and a clinical draft-review boundary before Eddy sees any patient instance.
- [ ] Keep all EHR actions/orders read-only until a separately governed writeback phase.

---

## Authoritative source controls

These values are release gates, not descriptive estimates.

- [x] CSV: 49 columns, 250 rows, 250 unique ranks/conditions, SHA-256 `2e3ac28238cdb8d7e1002117de6ad824d71882dae54df77fe4abd214b268a6ae`.
- [x] Verification workbook: 8 worksheets, SHA-256 `42cadf84dce297c5a839784148ebd2c5375320350394c0d143411008ed5bd171`.
- [x] Workbook-declared baseline SHA-256: `6819c1e111985da1fc62f38cdd85dd2a34b69308f4cf9be9f8941fbce62bf8fd`.
- [x] 250 verified pathways and 250 verification-ledger rows.
- [x] 770 unique CMS v43.1 MS-DRG codebook entries.
- [x] 802 pathway-version-to-DRG associations.
- [x] 32 DRG codes intentionally map to more than one candidate pathway.
- [x] 10,123 independently inventoried evidence claims.
- [x] 811 unique source-index PMIDs.
- [x] 324 source-release changes.
- [x] 96 evidence-verified rows and 154 rows verified with limitations.
- [x] 96 institutional-signoff candidates, 148 specialist-review candidates, and 6 redesign/non-protocol candidates.
- [x] 0 clinically approved rows.
- [x] Approximate volume control `32,967,000`; final planning coverage `99.0%`.
- [x] PMID `38349295` bibliographic enrichment preserved separately from raw cells.
- [x] Fifteen no-personal-author rows carry explicit not-listed semantics instead of invented people.
- [x] Zero residual unclassified required-field absences.
- [x] Rank 89 source value `87.9%` is preserved with the independently calculated `88.0%` rounding difference recorded as a control.
- [x] CMS v43.1 list-page count `770` versus design-PDF statement `772` is retained as an accepted discrepancy, not silently corrected.

---

## Delivery sequence and hard dependencies

```text
raw release reproducibility
  -> inactive canonical catalog and governance
    -> 3-5 institutionally approved pilot definitions
      -> explainable candidate assignment and pinned encounter instances
        -> Virtual Rounds and staff web
          -> Hummingbird Staff
            -> privacy-bounded 4D projection
              -> Hummingbird Patient released projections
                -> Eddy approved reference retrieval
                  -> Eddy local-only patient-instance drafts
                    -> external FHIR/EHR integration
```

- [x] Implement the first slice without touching already-modified authorization, route, patient, rounds, Hummingbird, or Eddy files.
- [ ] Do not start a downstream phase until its upstream exit gate is evidenced.
- [ ] Keep unit, department, service-line, facility, and IDN scopes distinct throughout assignment, awareness, and reporting.
- [ ] Split implementation into coherent pull requests by release boundary; do not combine catalog, patient, mobile, rounds, and Eddy changes in one review.

---

## Phase 0 — Preserve, reconcile, and reproduce the source release

### 0.1 Existing live raw release

- [x] Import the 49-column CSV into `raw.drg_care_pathways_v43_1_20260721`.
- [x] Record the source import in `raw.drg_care_pathway_imports`.
- [x] Import the verification manifest, verified pathways, ledger, claims, sources, changes, codebook, methodology, and QA snapshots.
- [x] Create source enrichment, complete-source, and completeness-audit relations without replacing raw cells.
- [x] Recompute row, hash, volume, status, DRG, claim/source, and cross-sheet controls.
- [x] Confirm no data was inserted into serving schemas during source import.

### 0.2 Repository reproducibility

- [~] Add a repository adoption command: `care-pathways:adopt-raw-release` now reconciles the deployed raw relations and adopts them canonically; a fresh-database raw CSV/XLSX loader is still open.
- [x] Add checksum-pinned release identity and table mapping in `config/care-pathways.php`.
- [x] Recompute controls from raw tables instead of trusting workbook `QA_Summary` values.
- [x] Fail if a required raw relation is absent.
- [x] Fail if manifest identity or a checksum differs.
- [x] Fail if any exact control count or status distribution differs.
- [x] Fail if verified-pathway and verification-ledger rank/condition/DRG identity differs.
- [x] Fail if the pathway and codebook DRG sets differ.
- [x] Fail if a claim references an unknown pathway or PMID.
- [x] Fail if a complete-source row retains an unclassified title or URL absence.
- [x] Fail if residual required-field absence is nonzero.
- [x] Support `--dry-run` with zero canonical writes.
- [x] Require an accountable `--actor` value.
- [x] Use a transaction-level advisory lock for one dataset key.
- [x] Return the existing release on an exact rerun.
- [x] Reject the same dataset key with different immutable hashes.
- [ ] Create a migration that reproduces the deployed raw manifest/table/view topology on a fresh database without changing existing deployed tables.
- [ ] Choose and document the durable XLSX ingestion approach: supported library or checksum-pinned normalized release bundle; do not ship an incomplete workbook parser.
- [ ] Add tracked, minimized, non-PHI source fixtures so CI does not rely on absolute `/Users/...` paths.
- [ ] Add a deployed-raw-shape compatibility fixture proving adoption does not update/delete source rows.
- [ ] Add raw mutation-rejection triggers through a forward-only migration after comparing the exact live table shape.
- [ ] Add backup/restore and raw-release verification runbook.

**Phase 0 exit gate:** The full release can be reproduced in an isolated PostgreSQL database, existing raw rows remain immutable, all controls pass, and no application read path serves raw data.

---

## Phase 1 — Canonical catalog and governance

### 1.1 Schema foundation

- [x] Add additive migration `2026_07_21_000900_create_care_pathway_catalog.php` using `SafeMigration`.
- [x] Add forward-only provenance migration `2026_07_21_001000_extend_care_pathway_provenance.php`.
- [x] Add current normalized provenance views through `2026_07_21_001100_create_care_pathway_current_provenance_views.php`.
- [x] Create dedicated `care_pathways` schema.
- [x] Use bigint surrogate primary keys and UUID business keys.
- [x] Create `catalog_releases` with source hashes, grouper effective period, exact control totals, state, and explicit signoff state.
- [x] Create append-only `catalog_release_controls`.
- [x] Create stable `definitions` and immutable-content `versions`.
- [x] Store evidence status, confidence, source specificity, unresolved flags, release disposition, clinical signoff, institutional approval, and activation in separate fields.
- [x] Create `drg_codebook_entries` for the 770-code authority.
- [x] Create many-to-many `drg_mappings` for the 802 associations.
- [x] Create typed `sections` with source text separate from approved text.
- [x] Create draft `milestone_definitions`, `activity_definitions`, `goal_definitions`, and `education_definitions` authorities.
- [x] Ensure executable activity definitions require approved review state.
- [x] Ensure approved/released sections require approved text and digest.
- [x] Ensure approved education requires reviewed content, teach-back prompt, and digest.
- [x] Create versionable `sources` with exact content digest and retraction/supersession state.
- [x] Add append-only `source_status_events` and `current_source_statuses` so later currency/retraction facts never mutate imported sources.
- [x] Correct all 811 negated retraction indicators through append-only status facts; verify 811 current and 0 residual non-current.
- [x] Create `catalog_release_sources` so all 811 indexed sources belong explicitly to release `2`, independent of the 795 sources currently cited by claims.
- [x] Create `evidence_claims` and `claim_sources` for claim-level provenance.
- [x] Keep separate `section_sources` for approved section-level citation selection.
- [x] Make section-source selections append-only and enforce same-release source membership for both claim and section citations.
- [x] Enforce exact immutable source digest on every release-source membership row.
- [x] Create `source_changes`, `source_enrichments`, and `completeness_resolutions`.
- [x] Preserve all 16 source resolution records as 20 queryable field facts: 5 metadata fields for PMID `38349295` and 15 explicit no-personal-author facts.
- [x] Preserve all 7 completeness controls with source blank count, classification, corrective action, evidence, residual unknown count, raw record, and digest.
- [x] Provide current read views that exclude historical generic adoption facts without deleting append-only history.
- [x] Create `service_line_mappings` with mapped/pending/rejected states and nullable canonical FK.
- [x] Create append-only `reviews`, `approvals`, and PHI-safe `events`.
- [x] Protect immutable release controls and version content with PostgreSQL triggers.
- [x] Protect reviews, approvals, controls, claims, claim-source links, and events from update/delete.
- [x] Prevent a release from activating while any version is unapproved or inactive.
- [x] Keep catalog FKs out of `raw`, `prod`, `rounds`, `patient_experience`, and `eddy`.
- [x] Add append-only triggers for codebook rows, DRG mappings, canonical source versions, source changes/enrichments/completeness rows, and immutable source-section fields.
- [x] Require the active-version count to equal the release pathway count, including the zero-version edge case.
- [ ] Add non-overlapping effective-period enforcement for active versions of one definition.
- [ ] Add independent-approver and required-discipline activation gates.
- [ ] Add explicit withdrawal/retraction transitions and emergency correction path.
- [ ] Add partition/retention planning for evidence and audit growth.

### 1.2 Canonical adoption service

- [x] Create `CatalogReconciliationService` for source controls and cross-relation checks.
- [x] Create `CatalogImportService` for atomic inactive adoption.
- [x] Auto-discover the Artisan command without editing dirty `routes/console.php`.
- [x] Insert all adopted definitions as `candidate`.
- [x] Insert all versions as `institutional_approval_status=not_reviewed` and `activation_status=inactive`.
- [x] Insert source sections as staff-reference-only, `approved_text=NULL`, and `review_state=source_candidate`.
- [x] Preserve exact raw snapshots and source/content digests on versions.
- [x] Insert service-line mappings only when the canonical registry row actually exists; otherwise queue pending instead of guessing.
- [x] Record the CMS 770/772 discrepancy in `catalog_release_controls`.
- [x] Record adoption in the append-only event stream with no patient identifiers or clinical prose.
- [x] Redact local paths and credential-like manifest fields from canonical release metadata.
- [x] Run the repository command against the full live raw release after read-only dry-run validation.
- [x] Verify live canonical controls: 250/770/802/10,123/811/324 and all status partitions.
- [x] Verify all 32 overlapping DRG codes retain multiple pathway mappings.
- [x] Verify 16 source resolution records, 20 normalized field facts, 7 completeness controls, PMID `38349295`, and zero residual unknowns live.
- [x] Verify complete release-source membership: 811 indexed, 795 claim-cited, 16 uncited, and no source omitted.
- [x] Repair the retraction-negation parser and record 811 immutable corrective status events plus superseding release controls.
- [x] Batch 7,000 sections, 10,123 claims, and 53,994 claim-source links; the optimized live adoption completed in under five seconds.
- [~] Measure deployment behavior: atomic rollback and optimized runtime are verified; formal WAL/backup-window measurements remain open.
- [x] Verify an exact live rerun returns canonical release `2` and creates no duplicate release/event/content rows.
- [ ] Adjudicate the 23 composite or locally absent service-line labels now recorded as an accepted pending crosswalk control `(deferred — institutional taxonomy owner)`.

### 1.3 Models and read boundary

- [x] Add catalog release/control, definition/version, section, DRG codebook/mapping, source, evidence-claim, and claim-source Eloquent models.
- [x] Add remaining models for authoring definitions, reviews, approvals, changes, enrichments, completeness, service-line mapping, and events.
- [x] Create one `ApprovedPathwayCatalogReadService`; downstream products must never query raw tables or unrestricted models.
- [x] Make approved-version reads require current effective period, approved institutional state, active state, non-withdrawn sources, and intended audience.
- [x] Exclude raw snapshots and source prose; return only approved staff sections and evidence digests.
- [x] Return no version/candidate when the requested staff audience has no approved sections.
- [x] Exclude a version when either claim-level or approved-section citations reference a non-current source.
- [x] Preserve overlapping DRGs as multiple candidates that require clinician confirmation rather than selecting a pathway silently.
- [x] Reject patient audiences at the catalog boundary; patient-facing material must come from governed `patient_experience` projections.
- [x] Add exact-version and source-cutoff fields to the approved staff/version and governance read DTOs.
- [x] Add pagination/search filters for condition, DRG candidate, MDC, service line, evidence state, source age, and review queue.
- [ ] Add source retraction/supersession monitor and next-review scheduling.

### 1.4 Authorization and feature flags

- [x] Add off-by-default flags for catalog, assignment, rounds, staff mobile, patient, Eddy reference, Eddy instance, and writeback.
- [x] Add a separate off-by-default governance-read flag; both middleware and the read service fail closed when it is disabled.
- [x] Add distinct capabilities: catalog view, source adoption, content authoring, evidence review, clinical approval, activation, encounter-pathway view, and instance management.
- [x] Keep data-steward adoption separate from clinical approval and activation; clinical approver and release manager are also separate roles.
- [ ] Require step-up authentication and independent actor for activation/withdrawal.
- [ ] Add organization/facility scope to local variants and approvals.
- [~] Add authorization tests: governance flag 404, unauthenticated 401, missing-capability 403, role separation, Gate adapter, and inactive-account denial are covered; wrong-facility and revoked-assignment cases remain for encounter-scoped Phase 3 APIs.
- [ ] Update Hummingbird capability ledger only when mobile endpoints are introduced.

### 1.5 Governance console

- [~] Add staff web API: ten GET-only `/api/care-pathways/v1` governance endpoints now cover release summary, pathway/version/section detail, evidence claims, complete source index/detail, controls, reviews, approvals, and events; standalone diffs plus all review/approval/activation/withdrawal mutations remain open.
- [ ] Add FormRequests for every mutation with expected version and idempotency key.
- [ ] Add catalog and review pages under `resources/js/Pages/CarePathways/`.
- [ ] Add typed feature API/Zod boundary under `resources/js/features/carePathways/`.
- [ ] Add one navigation entry through `navigationConfig.ts` only.
- [ ] Show source text beside draft/approved staff and patient content without conflating them.
- [ ] Show evidence status, limitations, source age, exact claims, local approval, and intended use.
- [ ] Add review queue views for 96 signoff, 148 specialist-review, and 6 redesign rows.
- [ ] Add source/version diff with changed claims and citations.
- [ ] Add accessible status labels/icons; never rely on color alone.
- [~] Emit “Automated evidence verification is not institutional clinical approval.” in every governance response; the visible console banner remains open with the UI.

### 1.6 Phase 1 tests

- [x] Add `CarePathwayCatalogSchemaTest` so the migration lane discovers the schema test.
- [x] Assert all canonical authorities exist.
- [x] Assert state dimensions remain separate.
- [x] Assert required activation/immutability/append-only triggers exist.
- [x] Assert no canonical FK enters raw or application-serving schemas.
- [x] Add fixture-backed dry-run, inactive adoption, idempotency, conflicting-hash, missing-source, append-only, and premature-activation tests.
- [x] Focused evidence: 20 tests, 140 assertions passed on isolated PostgreSQL.
- [x] Pint passes for all first-slice PHP files.
- [x] Add focused model-contract, capability-separation, governance-gating, exact-version, pagination/filter, DTO-redaction, source-index, and ledger API tests.
- [~] Run the new focused tests: current execution is blocked before PHPUnit starts because the repository requires an isolated loopback PostgreSQL server and `localhost:5432` is unavailable; no test was redirected to the live database. Syntax, Pint, route inventory, and live read-only service smoke validation pass.
- [ ] Add full-source fixture control tests without checking local absolute paths into CI.
- [ ] Add DB tests for malformed hash, JSON shape, date period, duplicate rank, duplicate source digest, and invalid DRG.
- [ ] Add raw immutability/deployed-shape adoption tests.
- [ ] Run migration lane, contract lane, and full backend suite.
- [ ] Capture release evidence with the repository evidence script.

**Phase 1 exit gate:** Every source row is traceable and searchable in an inactive canonical catalog, all control totals reconcile, permissions are distinct, governance review is usable, and no unapproved content can be selected by a serving read service.

---

## Phase 2 — Pilot knowledge engineering and institutional signoff

### 2.1 Governance decisions `(deferred)`

- [ ] Name clinical executive sponsor, governance chair, physician owners, nursing, pharmacy, quality/safety, informatics, patient advisor, privacy/security, accessibility/language, and AI governance owners.
- [ ] Choose first facility, units, and 3-5 pilots from the 96-row signoff queue using local value and readiness—not national rank alone.
- [ ] Decide whether multiple pathway families may coexist on one encounter.
- [ ] Define minimum evidence/review tier separately for staff reference, staff workflow, and patient release.
- [ ] Define disposition of the 6 redesign/non-protocol candidates.
- [ ] Approve local service-line crosswalk ownership.
- [ ] Define acknowledgement, teach-back, consent, and correction semantics.
- [ ] Define retention for versions, AI traces, instances, and awareness facts.

### 2.2 Structured authoring

- [ ] Split over-broad source-to-claim linkages before authoring executable logic.
- [ ] Author discrete eligibility and exclusion predicates with units, code systems, negation, and uncertainty.
- [ ] Author milestone definitions with stable keys, relationships, expected ranges, and evidence requirements.
- [ ] Author activities with performer roles, timing, preconditions, contraindications, and executable=false by default.
- [ ] Author pathway and patient goals separately.
- [ ] Author patient/caregiver education independently from raw clinical prose.
- [ ] Include plain-language uncertainty, “what can change,” warning signs, urgent-help route, and teach-back prompts.
- [ ] Create English pilot copy and selected language translations through governed human review.
- [ ] Link every computable predicate/action to exact claims and human review.
- [ ] Add local variants as explicit diffs; do not mutate source versions.
- [ ] Generate FHIR PlanDefinition, ActivityDefinition, Questionnaire, Library, and Measure projections with canonical versions.

### 2.3 Review and approval

- [ ] Complete physician review.
- [ ] Complete nursing review.
- [ ] Complete pharmacy review when medication content exists.
- [ ] Complete coding/utilization-management review.
- [ ] Complete quality/patient-safety review.
- [ ] Complete patient/family and health-literacy review for patient content.
- [ ] Complete accessibility and language-access review.
- [ ] Approve staff-reference, staff-workflow, and patient content separately.
- [ ] Record conditions, effective period, next review, and local owner.
- [ ] Test withdrawal and emergency correction before activation.

### 2.4 Phase 2 tests

- [ ] Deidentified inclusion/exclusion boundary fixtures.
- [ ] Missing, conflicting, stale, pediatric/adult, pregnancy/newborn, and contraindication fixtures.
- [ ] No executable rule from unapproved/generalized content.
- [ ] Every rule resolves to claims and approvals.
- [ ] Superseded/retracted source behavior.
- [ ] Local variant inheritance/diff behavior.
- [ ] FHIR artifact validation and canonical version resolution.
- [ ] Patient copy reading level, translation, screen-reader, contrast, and teach-back usability evidence.

**Phase 2 exit gate:** Three to five pilot versions have complete multidisciplinary approval matrices, deidentified rule evidence, separately approved patient content, no unresolved high-severity hazard, and tested correction/withdrawal behavior.

---

## Phase 3 — Candidate assignment and encounter pathway instances

### 3.1 Encounter schema

- [ ] Add `assignment_candidates`, `assignments`, `instances`, `instance_milestones`, `instance_goals`, `deviations`, `instance_tasks`, `education_assignments`, `awareness_events`, `projection_runs`, and `projection_failures`.
- [ ] Use stable UUID business keys and optimistic version fields.
- [ ] Allow one active confirmed assignment per encounter/pathway family unless governed coexistence policy permits more.
- [ ] Pin every instance to one exact approved version.
- [ ] Keep submitted awareness and released projection facts append-only.
- [ ] Link tasks by UUID to `rounds.tasks`, `ops.actions`, and future FHIR Task without duplicating lifecycle authority.

### 3.2 Candidate matcher

- [ ] Define source adapters for diagnoses, procedures, problems, service, location, orders, age, pregnancy, and other approved signals.
- [ ] Generate an explainable candidate set with rule/model version and source cutoff.
- [ ] Return multiple candidates for overlapping DRGs.
- [ ] Treat unavailable admission-time DRG as normal.
- [ ] Never auto-confirm from DRG alone.
- [ ] Require clinician confirm/reject/reassign with reason.
- [ ] Preserve late coding reconciliation as a quality signal rather than rewriting history.
- [ ] Abstain on missing exclusion data or contradictory signals.
- [ ] Reauthorize and reconcile after transfer/service change.

### 3.3 Instance service and projections

- [ ] Add command services for assignment and instance FSM transitions.
- [ ] Add expected-version and idempotency handling to every mutation.
- [ ] Add milestone evidence, due ranges, owner roles, exception reasons, and explicit not-applicable state.
- [ ] Add care-team and patient goals without overwriting one another.
- [ ] Add deviations with reason, owner, severity, and resolution.
- [ ] Add education offer/view/response/teach-back workflow.
- [ ] Add audience awareness ledger: offered, viewed, acknowledged, questioned, taught-back, corrected.
- [ ] Build one authorization-aware `PathwayInstanceProjectionService` with audience-specific DTOs.
- [ ] Carry exact instance/version, source cutoff, generated time, freshness, and correction state in every projection.

### 3.4 Staff web workspace

- [ ] Add candidate confirmation queue.
- [ ] Add encounter Pathway Workspace: why matched, exact version, current stage, next milestone, deviations, owners, questions, education, source age, and changes.
- [ ] Distinguish observed EHR fact, pathway guidance, human task, patient report, and Eddy draft visually and semantically.
- [ ] Add role-shaped views for physician, nursing, pharmacy, case management, therapy, quality, and coding.
- [ ] Add shift/transfer handoff and freshness warnings.

### 3.5 Phase 3 tests

- [ ] DRG overlap returns multiple candidates.
- [ ] No DRG at admission does not prevent safe matching.
- [ ] Late coding change cannot silently reassign.
- [ ] Contradictory diagnosis/procedure/service signals abstain or require adjudication.
- [ ] Missing exclusion data blocks confirmation where required.
- [ ] Pediatric/adult and pregnancy/newborn boundaries.
- [ ] Unit/service transfer revokes/reroutes access and ownership.
- [ ] Multiple-pathway coexistence policy.
- [ ] Clinician reject/reassign/supersede.
- [ ] Stale source cutoff and withdrawn version behavior.
- [ ] Wrong-patient/IDOR and cross-facility denial.
- [ ] No source version changes an active instance silently.

**Phase 3 exit gate:** A pilot service can safely confirm, maintain, correct, and close a pinned pathway instance using explainable evidence; assignment is not DRG-only and all audience projections share the same canonical state.

---

## Phase 4 — Virtual Rounds, Zephyrus staff awareness, and Hummingbird Staff

### 4.1 Virtual Rounds backend

- [ ] Inject authorized pathway projection through `RoundProjectionService`; do not query pathway tables directly from controllers.
- [ ] Add bounded pathway summary to board and patient detail: version, stage, next milestone, deviations count, education/teach-back needs, freshness.
- [ ] Preserve board `queue_version` and 409 recovery behavior.
- [ ] Add pathway-specific contribution schemas to `config/rounds.php` only after approval.
- [ ] Promote patient pathway questions into accountable rounds questions/work queues through the existing bridge.
- [ ] Link follow-up through `rounds.tasks.external_task_ref`.
- [ ] Do not require pathway completion to close a round unless an approved template policy says so.
- [ ] Keep aggregate/unit/service-line scopes and authorizations distinct.

### 4.2 Virtual Rounds frontend

- [ ] Extend Zod schemas before rendering new pathway fields.
- [ ] Show current stage, next step, deviations, source freshness, patient questions, education/teach-back, and owner.
- [ ] Show exact pathway/version and why it applies.
- [ ] Keep guidance distinct from orders and observed facts.
- [ ] Preserve keyboard navigation, zoom, contrast, screen-reader order, and color-independent statuses.

### 4.3 Hummingbird Staff BFF

- [ ] Extract/reuse opaque `ptok_` context resolution and current role/unit access policy.
- [ ] Add strict `GET /api/mobile/v1/patients/{contextRef}/care-pathway` contract.
- [ ] Reject raw encounter, patient, and MRN identifiers.
- [ ] Add pathway-specific read capability; `mobile:read` alone is insufficient.
- [ ] Add accountable pathway work only to `MobileForYouService`.
- [ ] Define dedupe, urgency, shift transfer, completion, withdrawal, and disappearance behavior.
- [ ] Use generic PHI-free push doorbells.

### 4.4 Hummingbird Staff clients/contracts

- [ ] Update Laravel routes/controllers, staff OpenAPI, operation locks, capability ledger, and route inventory together.
- [ ] Add exact iOS DTO/client/view state and tests.
- [ ] Add exact Android DTO/client/view state and tests.
- [ ] Preserve role-specific altitude rather than exposing one generic clinical blob.
- [ ] Show offline/stale state and purge revoked cached context.
- [ ] Keep review/approval/activation unavailable to native clients.

### 4.5 Phase 4 tests

- [ ] Rounds authorization intersects encounter-pathway authorization.
- [ ] Aggregate viewers receive no patient pathway detail.
- [ ] Round board/detail version and freshness parity.
- [ ] Contribution/task/question lifecycle and source attribution.
- [ ] Raw identifiers rejected by mobile BFF.
- [ ] Transfer/assignment removal revokes mobile detail and For You items.
- [ ] Unaccountable roles receive no pathway card.
- [ ] Staff OpenAPI/route/capability/operation-lock parity.
- [ ] iOS and Android model, client, role-view, stale/offline, and revocation parity.

**Phase 4 exit gate:** Every accountable pilot role sees and closes the correct pathway work across Zephyrus, Virtual Rounds, and both Hummingbird Staff clients; transfers and shift changes revoke/reroute correctly.

---

## Phase 4C — 4D Patient Flow projection

- [ ] Implement only after board semantics and privacy rules stabilize.
- [ ] Reuse the existing Rounds layer and lens authorization.
- [ ] In detail lenses, expose only stage/status enums and bounded counts needed for navigation.
- [ ] Omit narrative, diagnosis, citations, patient identifiers, and source text from scene payloads.
- [ ] Create a separate privacy-thresholded aggregate endpoint for service-line/facility heatmaps.
- [ ] Suppress small cells and carry denominator/freshness.
- [ ] Add pathway state to scene Zod, vocabulary, legend, keyboard/list parity, and inspector.
- [ ] Verify color/shape do not collide with identity, acuity, round state, or barriers.
- [ ] Keep board/list fallback fully functional without WebGL.
- [ ] Test scene/board version parity and PHI-free payloads.

**4D exit gate:** The authorized board and scene project the same bounded pathway state, aggregate privacy thresholds pass, and no clinical narrative or patient identity enters the scene contract.

---

## Phase 5 — Hummingbird Patient production projection

### 5.1 Deployment and release boundary

- [ ] Review pending patient migrations `000300` through `000800` in exact order.
- [ ] Deploy with every patient pathway feature flag off.
- [ ] Create an approved `PatientReleasePolicyVersion` `(deferred — governance approval)`.
- [ ] Implement production source cursor/failure handling.
- [ ] Build the production pathway adapter from approved pinned instances only.
- [ ] Keep `patient_experience.encounter_projections` as the sole patient-facing boundary.
- [ ] Never add a direct raw/canonical pathway patient API.

### 5.2 Patient projection content

- [ ] Populate `today` with approved immediate priorities and ownership.
- [ ] Populate `pathway` with plain-language stage, next steps, uncertainty, goals, and contacts.
- [ ] Populate append-only `pathway_events` for meaningful progress/corrections.
- [ ] Populate `discharge_readiness` with criteria, owner, uncertainty, and can-change wording.
- [ ] Populate approved `rounds_summary`.
- [ ] Populate relationship-filtered `care_team`.
- [ ] Add education response, patient questions, goals, acknowledgement, and correction report workflows.
- [ ] Treat acknowledgement as “seen,” never consent or understanding.
- [ ] Record teach-back only with observed response and reviewing clinician.
- [ ] Route urgent concerns to bedside/emergency instructions, never async messaging or Eddy.
- [ ] Retract/correct released content immediately when source instance changes.

### 5.3 Patient native clients

- [ ] Reuse iOS `PatientPathView` and existing snapshot models before adding navigation.
- [ ] Reuse Android `PatientExperienceScreen` and existing models/coordinator.
- [ ] Add language fallback that fails closed for untranslated clinical content.
- [ ] Add proxy/representative relationship filtering.
- [ ] Add large text, VoiceOver/TalkBack, high contrast, and 200% zoom validation.
- [ ] Purge inaccessible/stale cached content after revocation, discharge, or relationship change.

### 5.4 Phase 5 tests

- [ ] Active principal, grant, encounter, scope, relationship, feature flag, release policy, and released projection all required.
- [ ] Generic denial for unknown, cross-principal, revoked, expired, and wrong-relationship resources.
- [ ] No raw IDs, MRN, internal notes, risk/priority scores, or unreleased source content.
- [ ] Correction/retraction removes superseded content and starts a new awareness cycle.
- [ ] Acknowledged never renders as understood.
- [ ] Urgent-help wording and uncertainty wording present.
- [ ] English plus selected pilot languages pass human review.
- [ ] iOS/Android accessibility, offline, cache purge, and contract parity.

**Phase 5 exit gate:** Patients/representatives see only current, approved, comprehensible, relationship-appropriate projections and can ask questions, state goals, acknowledge viewing, and participate in governed education/teach-back.

---

## Phase 6 — Eddy approved reference and staff assistance

### 6.1 Correct existing Eddy safety gaps before pathway context

- [ ] Do not give care-pathway/rounds surfaces the entire operational action catalog.
- [ ] Create a separate clinical proposal/draft FSM; do not reuse ops approval as clinical authority.
- [ ] Make provider routing request-context-aware, not surface-only.
- [ ] Force local-only routing for both non-streaming and streaming patient-instance turns.
- [ ] Scan the complete envelope, including live context and retrieved knowledge, as defense in depth.
- [ ] Resolve patient context server-side from opaque references; never trust caller-supplied `page_data`.
- [ ] Remove/review the generic “NON-DEVICE” assertion for clinical intended uses.

### 6.2 Approved reference plane

- [ ] Add canonical knowledge chunks keyed by pathway version, section, audience, effective period, local approval, source claim, and withdrawal state.
- [ ] Do not bulk-copy raw CSV/workbook text into `eddy_knowledge`.
- [ ] Retrieve only active, approved, intended-audience content.
- [ ] Return exact pathway/version and claim/source citations.
- [ ] Add reference Q&A contract with structured answer, applicability, limitations, citations, and abstention.
- [ ] Exclude withdrawn, stale, unapproved, generalized-out-of-scope, and patient-instance content.
- [ ] Add provider/model/prompt/policy/version provenance.

### 6.3 Patient-instance staff plane

- [ ] Reauthorize opaque encounter context on every request and stream.
- [ ] Provide one bounded patient instance at a time.
- [ ] Minimize context to structured stage, milestone evidence, deviations, questions, and freshness.
- [ ] Prevent cross-patient carryover, global corpus insertion, auto-curation, and preference learning.
- [ ] Add rounds preparation and gap-scan modes that produce drafts only.
- [ ] Require evidence refs for every assertion; otherwise abstain.
- [ ] Require human accept/edit/reject for every proposal.
- [ ] Auto-pause on access revocation, discharge, stale cutoff, withdrawal, tool error, or validator failure.

### 6.4 Eddy evaluation

- [ ] Citation correctness and exact version.
- [ ] Applicability/exclusion handling.
- [ ] Unsupported condition-specific claims from generalized tail content.
- [ ] Stale/withdrawn/unapproved exclusion.
- [ ] Missing/ambiguous pathway abstention.
- [ ] Prompt injection through source text, citations, patient questions, and EHR fields.
- [ ] PHI leakage, memorization, and cross-patient isolation.
- [ ] Cloud egress hard block for both `/eddy/chat` and `/eddy/chat/stream` when patient context exists.
- [ ] No order/action language beyond draft contract.
- [ ] Reviewer acceptance/edit/rejection, overrides, near misses, and subgroup performance.
- [ ] Independent clinical/AI red-team approval `(deferred)`.

**Phase 6 exit gate:** Eddy retrieves exact approved versions with claim citations, abstains outside scope, keeps patient context local and ephemeral across both stream modes, and cannot execute or sign clinical action.

---

## Phase 7 — Standards, external integration, and scale

- [ ] Publish approved definitions as versioned FHIR R4 CPG artifacts.
- [ ] Implement SMART-on-FHIR launch with exact encounter/facility scope.
- [ ] Add informational CDS Hooks patient-view card after safety review.
- [ ] Synchronize approved CarePlan/Task/CareTeam/Communication projections.
- [ ] Use transactional outbox, idempotency, reconciliation, replay, and conflict handling.
- [ ] Keep writeback as governed draft until EHR sandbox/change control passes `(deferred)`.
- [ ] Add kill switch for outbound integration.
- [ ] Add new CMS/guideline release diff and reconciliation workflow.
- [ ] Add source-age/retraction monitoring.
- [ ] Add materialized aggregates only after observed load.
- [ ] Add retention partitioning only after evidence-backed sizing.
- [ ] Expand pathway cohort by local value and review capacity, not by bulk activation.

**Phase 7 exit gate:** Connector conformance, provenance, replay, conflict, correction, withdrawal, and rollback pass in the deployed environment; writeback remains separately governed.

---

## Phase 8 — Optional patient Eddy study `(deferred)`

- [ ] Create separate intended-use statement and safety case.
- [ ] Complete regulatory, privacy, clinical, accessibility, language, and patient-advisor review.
- [ ] Restrict retrieval to released patient projections only.
- [ ] Implement explanation-only behavior, urgent-help routing, and abstention.
- [ ] Run dedicated patient comprehension, bias, injection, PHI, and unsafe-reassurance studies.
- [ ] Pilot only in a separately approved, monitored cohort.

**Phase 8 exit gate:** Separate governance approval exists. This phase is not required for the core staff/patient pathway product.

---

## Cross-cutting security, privacy, and clinical-safety checklist

- [ ] Threat model catalog, assignment, patient projection, mobile, rounds, 4D, Eddy, and external connectors.
- [ ] Data-flow diagram for staff, patient/proxy, service-line/facility, and AI boundaries.
- [ ] Minimum-necessary field matrix by audience and lens.
- [ ] PHI-free broadcast and push contract.
- [ ] Generic denial and audit-on-disclosure for patient resources.
- [ ] Step-up and independent approval for activation/withdrawal.
- [ ] Idempotency and expected-version checks for every mutation.
- [ ] Append-only correction and awareness facts.
- [ ] Downtime, stale-source, stale-projection, and connector-outage behavior.
- [ ] Emergency correction/retraction runbook.
- [ ] Wrong-pathway/near-miss response runbook.
- [ ] Transfer/shift/service reconciliation runbook.
- [ ] Source retraction/grouper update runbook.
- [ ] Eddy provider/model/prompt rollback runbook.
- [ ] Privacy incident and access-revocation runbook.
- [ ] Backup/restore and append-only audit verification.

---

## Human factors, accessibility, and language validation

- [ ] Patient can identify current stage, usual next step, uncertainty, warning signs, and contact route.
- [ ] Care-team roles can identify exact version, applicability, current milestones, deviations, owners, patient questions, and changes.
- [ ] Users distinguish guidance, order, observed fact, patient report, and Eddy draft.
- [ ] Status uses text/icon/shape, not color alone.
- [ ] Critical tasks remain visible at 200% zoom and large native text.
- [ ] Screen-reader order and accessible names are correct.
- [ ] High contrast passes.
- [ ] Shift-change, transfer, correction, bad-news, representative, interpreter, and downtime scenarios pass.
- [ ] Alert burden, interruption cost, workarounds, and time-on-task are measured.
- [ ] Patient acknowledgement is not interpreted as comprehension.
- [ ] Teach-back state reflects an observed interaction and reviewer.

---

## Metrics and operational readiness

### Knowledge quality

- [ ] Counts by evidence, confidence, specificity, disposition, signoff, approval, activation, audience, and facility.
- [ ] Claim adjudication and source-to-claim specificity.
- [ ] Source age, retraction, last review, and next review.
- [ ] Completeness/enrichment freshness and unresolved discrepancy count.
- [ ] Rule/fixture coverage and patient-copy language/reading-level coverage.

### Assignment quality

- [ ] Candidate acceptance/rejection/reassignment and time to confirmation.
- [ ] Ambiguity, missing data, and wrong-pathway near misses.
- [ ] Final DRG reconciliation as a quality signal, not a truth label.
- [ ] Coverage by unit and service line without flattening the scopes.

### Shared awareness

- [ ] Patient release/view/question/acknowledgement/teach-back/correction rates.
- [ ] Role acknowledgement and overdue ownership.
- [ ] Time from material change to explanation.
- [ ] Unanswered patient questions.
- [ ] Shift/transfer continuity and language/interpreter parity.

### Care delivery and balancing measures

- [ ] Milestone delay, deviation closure, LOS, readmission, complications, experience, and functional outcomes with valid denominators.
- [ ] Alert burden, overrides, workarounds, time-on-task, disagreement, and non-applicability.
- [ ] Equity/fairness and adverse-event/near-miss measures.
- [ ] Predefine baseline, cohort, exclusions, risk adjustment, comparison, and balancing measures before pilot; do not claim causality from a pre/post dashboard alone.

### Eddy

- [ ] Retrieval/citation precision, abstention, unsafe proposal, reviewer edits/rejections, cloud-block events, latency, stale context, red-team drift, subgroup quality, and harms.

---

## Release and validation commands

Run exact-path checks so unrelated worktree changes remain untouched.

```bash
php artisan test --compact \
  tests/Feature/CarePathways/CarePathwayCatalogSchemaTest.php \
  tests/Feature/CarePathways/CarePathwayCatalogAdoptionTest.php \
  tests/Feature/CarePathways/ApprovedPathwayCatalogReadServiceTest.php \
  tests/Feature/CarePathways/CarePathwayAuthorizationTest.php \
  tests/Feature/CarePathways/CarePathwayGovernanceApiTest.php \
  tests/Unit/CarePathways/CarePathwayModelContractTest.php

./vendor/bin/pint --test \
  database/migrations/2026_07_21_000900_create_care_pathway_catalog.php \
  database/migrations/2026_07_21_001000_extend_care_pathway_provenance.php \
  database/migrations/2026_07_21_001100_create_care_pathway_current_provenance_views.php \
  database/migrations/2026_07_21_001200_create_care_pathway_source_status_ledger.php \
  database/migrations/2026_07_21_001300_repair_care_pathway_source_retraction_negation.php \
  database/migrations/2026_07_21_001400_create_care_pathway_release_source_membership.php \
  database/migrations/2026_07_21_001500_protect_care_pathway_section_sources.php \
  database/migrations/2026_07_21_001600_enforce_care_pathway_source_membership.php \
  app/Console/Commands/CarePathwaysAdoptReleaseCommand.php \
  app/Authorization/Capability.php \
  app/Http/Controllers/Api/CarePathways \
  app/Http/Middleware/EnsureCarePathwayGovernanceEnabled.php \
  app/Models/CarePathways \
  app/Providers/AuthServiceProvider.php \
  app/Providers/RouteServiceProvider.php \
  app/Services/CarePathways \
  config/care-pathways.php \
  config/authorization.php \
  routes/care-pathways.php \
  tests/Support/CarePathwayRawFixture.php \
  tests/Feature/CarePathways \
  tests/Unit/CarePathways

bash scripts/test-suite.sh migration
bash scripts/test-suite.sh contract

npx --no-install prettier --check \
  docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-integration-plan.md \
  docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-TODO.md

git diff --check -- \
  database/migrations/2026_07_21_000900_create_care_pathway_catalog.php \
  app/Console/Commands/CarePathwaysAdoptReleaseCommand.php \
  app/Models/CarePathways \
  app/Services/CarePathways \
  config/care-pathways.php \
  tests/Support/CarePathwayRawFixture.php \
  tests/Feature/CarePathways \
  docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-integration-plan.md \
  docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-TODO.md
```

- [x] Focused care-pathway tests pass: 20 tests, 140 assertions.
- [x] First-slice PHP files pass Pint.
- [x] Governance-slice PHP files pass Pint and all ten GET-only routes expose the expected feature/auth/capability/throttle middleware chain.
- [~] Governance-slice PHPUnit execution is blocked by the unavailable loopback PostgreSQL test service; the failure occurs in isolated test-database provisioning before any test loads.
- [~] Migration lane: the repository wrapper is not macOS Bash 3 compatible (`mapfile: command not found`); its exact discovered-test command passed 39 tests/197 assertions.
- [~] Contract lane: 51 tests/1,550 assertions passed and 8 existing `MobileRoleCatalogParityTest` assertions failed against unrelated dirty iOS/Android worktree files; no care-pathway slice file appears in those failures.
- [ ] Full backend suite passes or unrelated baseline failures are reproduced and documented.
- [ ] Frontend unit/e2e/native tests pass when those phases begin.
- [ ] Full-source adoption evidence captured without credentials or clinical prose on command lines.

---

## Pilot go/no-go `(deferred)`

- [ ] Clinical executive sponsor signoff.
- [ ] Pathway physician owner signoff.
- [ ] Nursing signoff.
- [ ] Pharmacy/applicable discipline signoff.
- [ ] Clinical informatics signoff.
- [ ] Patient safety/quality signoff.
- [ ] Patient/family advisor signoff.
- [ ] Privacy/security/legal/regulatory signoff.
- [ ] Accessibility/language-access signoff.
- [ ] Hummingbird product owner signoff.
- [ ] Eddy AI governance signoff.
- [ ] Operations/SRE/integration signoff.
- [ ] Tested kill switches and rollback evidence.
- [ ] Baseline and balancing measures recorded.

---

## Session log

- 2026-07-21 — Source CSV and verification workbook deeply profiled and imported into isolated live `raw` relations. Verification package controls reconciled; bibliographic absences classified/enriched without overwriting raw cells. Integration plan completed with Zephyrus, Virtual Rounds/4D, Hummingbird Staff/Patient, Eddy, FHIR, safety, testing, and rollout boundaries.
- 2026-07-21 — Detailed execution checklist created. First vertical slice implemented: off-by-default config, canonical catalog/governance migration, normalized 770-codebook/802-mapping and claim/source model, reconciliation/adoption services, accountable Artisan command, Eloquent models, isolated raw fixture, schema/adoption tests, activation/immutability controls. Focused PostgreSQL evidence: 10 tests/87 assertions; Pint clean.
- 2026-07-21 — Migration/schema validation passed via the wrapper-equivalent command (38 tests/183 assertions). The wrapper itself is blocked locally by its pre-existing Bash 4 `mapfile` dependency. Contract validation completed with 51 passes and 8 unrelated mobile parity failures in already-modified Hummingbird iOS/Android files; those files were preserved.
- 2026-07-21 — Full live raw dry run passed. Catalog/provenance migrations `000900`, `001000`, and `001100` were applied explicitly without running unrelated pending patient migrations. Canonical release `2` was adopted inactive: 250 definitions/versions, 7,000 source-only sections, 770 codebook entries, 802 mappings, 10,123 claims, 53,994 claim-source links, 811 sources, 324 changes, 20 normalized enrichment facts, and 7 normalized completeness controls. Exact rerun was idempotent; zero versions are approved/active; 23 service-line labels remain deliberately pending instead of guessed.
- 2026-07-21 — Added and tested the fail-closed `ApprovedPathwayCatalogReadService`: feature-flag and active-release gating, effective/institutional approval checks, retracted-source exclusion, staff-audience-only projection, raw/source-prose exclusion, and ambiguity-preserving DRG lookup. Focused PostgreSQL evidence is now 15 tests/115 assertions; the migration-lane equivalent is 39 tests/187 assertions.
- 2026-07-21 — Final absence/currency audit found and corrected two provenance defects: all 811 explicit “No PubMed retraction indicator detected” values had been misparsed as retracted, and the 16 sources not cited by any claim lacked direct release membership. Added an append-only status ledger, 811 corrective current-state events, a complete 811/811 release-source authority (795 cited, 16 uncited), superseding passed controls, and fail-closed non-current-source reads. Release `2` remains inactive, all serving flags remain off, and zero source or clinical rows were overwritten. Focused evidence is 18 tests/132 assertions; migration-lane equivalent is 39 tests/193 assertions.
- 2026-07-21 — Hardened citation integrity after the final relational audit: section-source selections are append-only; claim and section citations must reference a source in the same catalog release; release-source digests must match the immutable source; non-current claim or section citations suppress serving; and audiences with no approved content return nothing. Live rows satisfied every new guard before deployment. Final focused evidence is 20 tests/140 assertions; migration-lane equivalent is 39 tests/197 assertions.
- 2026-07-21 — Implemented the next repository-only governance slice: all remaining canonical Eloquent models and relationships; eight distinct care-pathway capabilities with source-adoption, evidence-review, clinical-approval, activation, and encounter-instance duties separated; a dual-gated off-by-default governance read service; ten GET-only staff endpoints; exact release/version/source-cutoff metadata; searchable review/source/pathway queues; and raw-payload-redacted DTOs. Added focused model, authorization, and API tests. Route inventory and Pint pass. A read-only live smoke audit returned the expected 250/250 inactive catalog, 811 sources including 16 uncited, 26 controls, 0 reviews/approvals, and no raw/provenance fields; the local PHPUnit run remains blocked before test execution because no loopback PostgreSQL test server is running. No migration, clinical activation, patient/Hummingbird/Rounds/4D/Eddy serving, commit, push, or deployment occurred.
