# DEVLOG — UMLS 2026AA Care-Pathway Terminology Enrichment and Review Enablement

- **Date:** 2026-07-22
- **Repository:** Zephyrus
- **Delivery type:** Local terminology-enrichment release, validation package, and credentialed-review work queue
- **Terminology authority:** UMLS Metathesaurus `2026AA`, released 2026-05-04
- **Supplemental relationship source:** `parthenon.vocab`, revalidated against UMLS before retention
- **Source pathway rows:** 250
- **Source pathway fields:** 49
- **Expanded pathway fields:** 82
- **Clinical/coding approval state:** Not completed
- **Operational state:** Not activated; not suitable for billing, ordering, patient-level coding, or direct clinical serving
- **Related governed-catalog devlog:** [Governed DRG Care Pathways](./DEVLOG-governed-drg-care-pathways-2026-07-22.md)
- **Related UMLS import contract:** [UMLS PostgreSQL import kit](../db/umls/README.md)
- **Related UMLS implementation checklist:** [UMLS 2026AA PostgreSQL import TODO](./superpowers/plans/2026-07-22-umls-2026aa-postgresql-import-TODO.md)

## Executive summary

This work rebuilt the terminology enrichment for the 250-pathway DRG care-pathway package using current UMLS 2026AA source records and a deliberately conservative, evidence-preserving mapping policy.

The original request was to add corresponding LOINC, CPT, SNOMED CT US, and ICD-10 codes to every pathway without hallucinating codes. The key engineering decision was that “corresponding” could not safely mean “force at least one code from every system onto every row.” The source data contains narrative pathway titles and laboratory text, not the complete clinical, procedural, encounter, laterality, device, approach, method, or payer context required to select many exact codes. The implementation therefore distinguishes:

- a code system being applicable to the pathway;
- a current source record being a plausible candidate;
- a pathway-level candidate being sufficiently evidenced for automated acceptance;
- a candidate requiring credentialed review;
- no conservative match being available; and
- the system being inapplicable to that pathway.

That distinction prevented source-valid terminology records from being overclaimed as clinically or financially correct coding decisions.

The completed work produced four principal outcomes:

1. A current-source terminology inventory and reproducible source manifest for LOINC 2.82, CPT 2026, SNOMED CT US 2026-03-01, ICD-10-CM 2026, and ICD-10-PCS 2026.
2. A conservative mapping rebuild that retained 1,479 pathway-level accepted records and quarantined 1,504 current-source-valid candidates for credentialed review.
3. An expanded 250-row CSV and a 16-sheet verification workbook with normalized crosswalk, evidence, applicability, exception, methodology, source-release, and formula-backed QA authorities.
4. An 18-sheet reviewer-ready workbook with a 1,504-row, 32-column decision queue, live status formulas, controlled decisions, pathway grouping, provenance, blocking questions, and explicit safety guidance.

All automatable validation gates passed:

- zero unsupported codes;
- zero source-description mismatches;
- zero duplicate pathway/system/code candidates;
- zero row-summary reconciliation failures;
- all 250 source rows and 49 source fields preserved;
- source row order preserved;
- valid XLSX package structure;
- successful independent workbook-reader validation;
- zero formula-error matches;
- visual review of every sheet and representative top, middle, bottom, and provenance ranges; and
- an independent final release audit with no automatable release blocker.

The work did **not** complete credentialed clinical or coding adjudication. It did not activate a pathway, change the production `care_pathways` catalog, establish patient-level medical necessity, select payer-valid billing codes, authorize an order, or release content to Hummingbird, Eddy, Virtual Rounds, the 4D view, or patients.

## Why the rebuild was required

### The prior enrichment was structurally valid but semantically unsafe

The earlier terminology-expanded artifact contained valid-looking code structures and many records that existed in a terminology source. That was not sufficient evidence that the records belonged to a particular pathway.

The audit found three broad overmapping mechanisms:

1. **ICD descendant fan-out.** A category or short prefix match was used as permission to emit large descendant sets. Of 2,340 prior ICD records, 2,171, or 92.8%, came from category fan-out. A single `S06` family match produced 812 records. Existence under a category does not establish pathway applicability, encounter specificity, laterality, episode, sequela status, or coding sequence.
2. **Inherited SNOMED fan-out.** Of 648 prior SNOMED records, 540, or 83.3%, inherited the ICD fan-out rather than matching a pathway-defining current SNOMED concept. That created broad concept sets with weak pathway evidence.
3. **Unconstrained LOINC lexical matching.** The prior package contained 7,285 LOINC records. Many were driven by token overlap without preserving the six LOINC axes. This produced analyte, specimen, property, method, panel, or unit mismatches. One concrete regression involved medication text being incorrectly associated with LOINC `65818-7`.

The earlier CPT set also depended on an OPPS-oriented subset and showed contradictions between the pathway title, procedure family, and available context. A CPT code can exist and still be wrong for a professional service, component, approach, modifier, bundling state, or encounter.

### Source validity is necessary but not sufficient

The rebuild treats the following claims as separate:

```text
code exists in a current source
  != source relationship asserts a map
  != pathway text supports the concept
  != institution approves the pathway mapping
  != encounter documentation supports the code
  != code is billable or payable
  != code is appropriate for a specific patient
```

Every retained code had to pass the first claim. Accepted pathway mappings also had to pass a documented evidence rule. The remaining claims require separate institutional, clinical, coding, payer, and encounter authorities.

## Scope and non-scope

### In scope

- Profile and fingerprint the supplied 250-row CSV and verification workbook.
- Verify the renewed `umls_2026aa` database and the `parthenon.vocab` schema.
- Identify current UMLS source releases for the five target code systems.
- Extract current, unsuppressed terminology records with explicit SAB, version, TTY, language, and suppression filters.
- Use source-asserted relationships only within documented safety constraints.
- Rebuild pathway-level terminology candidates using deterministic and auditable evidence classes.
- Separate accepted mappings from review-required candidates.
- Record applicability, no-match, and review states instead of forcing codes into every row.
- Preserve original CSV values, workbook sheets, tables, merges, formulas, and order.
- Build normalized evidence, crosswalk, exception, applicability, source, and QA sheets.
- Create a credentialed-review queue without promoting candidates.
- Perform structural, semantic, formula, package, independent-reader, and visual verification.

### Explicitly out of scope

- Institutional clinical approval.
- Credentialed coding adjudication.
- Patient-level diagnosis or procedure coding.
- Medical necessity, payer coverage, reimbursement, or sequencing decisions.
- CPT bundling, modifier, global-period, or professional/facility claim decisions.
- ICD-10-PCS selection without complete seven-axis procedure documentation.
- Local order-catalog, laboratory-catalog, chargemaster, or EHR binding.
- Activation of any `care_pathways` catalog release or pathway version.
- Encounter assignment from an MS-DRG or terminology code alone.
- Direct release to care teams, Hummingbird, patients, Eddy, Virtual Rounds, or the 4D view.
- Redistribution of licensed UMLS or CPT content through Git.

## Release identity and lineage

### Source files used by this enrichment

| Artifact                     |                Shape | SHA-256                                                            |
| ---------------------------- | -------------------: | ------------------------------------------------------------------ |
| Source pathway CSV           | 250 rows × 49 fields | `2e3ac28238cdb8d7e1002117de6ad824d71882dae54df77fe4abd214b268a6ae` |
| Source verification workbook |             8 sheets | `6617bda522a55bfa3e6971b00bb3d1862d6f6567a1119f07f2682e468e5c293e` |

The CSV digest is the same source digest previously imported for the governed DRG catalog. The workbook digest is **not** the digest pinned to canonical production catalog release 2.

### Production catalog identity boundary

The existing governed-catalog devlog records production release 2 with workbook digest:

```text
42cadf84dce297c5a839784148ebd2c5375320350394c0d143411008ed5bd171
```

This enrichment used the shared-checkout workbook digest:

```text
6617bda522a55bfa3e6971b00bb3d1862d6f6567a1119f07f2682e468e5c293e
```

No binary or semantic equivalence assertion was made between those workbooks. Therefore:

- this terminology package is a new local candidate release;
- it must not mutate production catalog release 2;
- any future catalog adoption must create a new immutable release identity;
- release adoption must reconcile both source content and terminology content;
- the candidate release must remain inactive until its own institutional review and approval gates pass; and
- references to “the workbook” must always include a digest or immutable release identifier.

### Generated local-only release artifacts

The generated files remain outside Git because they contain licensed or locally controlled terminology content.

| Local artifact                                                                     | Purpose                                   | SHA-256                                                            |
| ---------------------------------------------------------------------------------- | ----------------------------------------- | ------------------------------------------------------------------ |
| `DRG_Care_Pathways_250_PATHWAYS_99PCT_v43_1_UMLS_2026AA_Expanded.csv`              | Expanded 82-field pathway release         | `9de26fcdc4319744c08a20f7347d54d8865debec2020a66fc685bc462e09f16e` |
| `DRG_Care_Pathways_250_Verification_Package_v43_1_UMLS_2026AA_Expanded.xlsx`       | 16-sheet terminology verification package | `8599391a002eaa224b0e23f8489dc34bd5fbced9d3dab12bbbb582093c8d6339` |
| `DRG_Care_Pathways_250_Verification_Package_v43_1_UMLS_2026AA_Reviewer_Ready.xlsx` | 18-sheet credentialed-review package      | `72237774ea7286c4fab5c498ccac4964d32cb78d9d08361b258b4dd485ce4758` |

The normalized reviewer-queue CSV was also fingerprinted as:

```text
3f11b76d87f3c8fb4fe501c6842e9a77a6168df1d69c5376ddd404d009553463
```

The underlying 2,983-record candidate extract was fingerprinted as:

```text
da375c6c190e967c2ff4637de91088469150ebb75107118a3838b1308a5dd1bb
```

These hashes are lineage controls, not distribution permissions.

## Authoritative terminology sources

### UMLS 2026AA release controls

The UMLS database reported release `2026AA`, base release for Spring 2026, released 2026-05-04. All 56 loaded UMLS release files had already passed their import validations.

The terminology rebuild used UMLS as the final existence and descriptor authority. The essential currentness filter was `SUPPRESS='N'`; CVF and ISPREF were not treated as universal currentness filters.

| System       | UMLS SAB/version         | Canonical TTY | Filter                                         | Current records |
| ------------ | ------------------------ | ------------- | ---------------------------------------------- | --------------: |
| LOINC        | `LNC282`                 | `LN`          | `SAB=LNC; LAT=ENG; TTY=LN; SUPPRESS=N`         |         104,334 |
| CPT          | `CPT2026`                | `PT`          | `SAB=CPT; LAT=ENG; TTY=PT; SUPPRESS=N`         |          11,525 |
| SNOMED CT US | `SNOMEDCT_US_2026_03_01` | `FN`          | `SAB=SNOMEDCT_US; LAT=ENG; TTY=FN; SUPPRESS=N` |         386,110 |
| ICD-10-CM    | `ICD10CM_2026`           | `PT`          | `SAB=ICD10CM; LAT=ENG; TTY=PT; SUPPRESS=N`     |          74,719 |
| ICD-10-PCS   | `ICD10PCS_2026`          | `PT`          | `SAB=ICD10PCS; LAT=ENG; TTY=PT; SUPPRESS=N`    |          79,115 |

The extraction contract kept CODE as the emitted identifier. CUI, AUI, SCUI, SAUI, source record, map identifiers, and map rules were retained as provenance rather than substituted for the source code.

### Official SNOMED CT US to ICD-10-CM map

The full active current map extract contained 285,108 rows. A deliberately narrow context-free subset retained only rows satisfying all of these controls:

- `MAPSETCUI=C6067230`;
- `MAPSETSAB=SNOMEDCT_US`;
- `REL=RO`;
- `RELA=mapped_to`;
- `MAPATN=ACTIVE`;
- `MAPTYPE=447637006`;
- `MAPRANK=1`;
- `MAPRULE=TRUE`;
- non-null target; and
- no unresolved `?` target.

That safe subset contained:

- 93,243 map rows;
- 82,292 distinct source codes; and
- 14,110 distinct ICD-10-CM targets.

Even those rows were retained as pathway-review candidates unless the pathway evidence independently supported acceptance. A source-asserted map is not automatically an encounter-level coding decision.

### Parthenon vocabulary role

The renewed `parthenon.vocab` schema was useful for supplemental OMOP relationships and domain context. It was not used as the final code-existence authority.

The Parthenon metadata remained older than UMLS 2026AA for important sources, including CPT4 2025, LOINC 2.80, and a 2025 SNOMED release. The implementation therefore applied these rules:

- Parthenon relationships could generate candidates.
- Relationship direction was preserved.
- A `Maps to` relationship was never silently inverted into an equivalence.
- Every target retained from Parthenon was revalidated against the current UMLS source release.
- Stale Parthenon metadata could not override a current UMLS descriptor or suppression state.

The in-scope Parthenon candidate export contained 130,627 active cross-vocabulary OMOP `Maps to` rows.

### Why UMLS 2026AA was not deficient for this task

UMLS 2026AA contained the required current source inventories for all five target systems. The principal limitation was not missing code inventory; it was missing pathway and encounter specificity.

UMLS can establish that a source code and source term exist in a particular release. It can also preserve source relationships. It does not, by itself, decide:

- which local test method is ordered;
- which CPT component or bundled service applies;
- which PCS approach, device, or qualifier is documented;
- which ICD-10-CM encounter, laterality, or sequencing rule applies;
- whether a source relationship is suitable for a particular institutional use;
- whether payer policy permits a claim;
- whether a code is medically necessary for a patient; or
- whether local governance has approved the mapping.

The unresolved candidates are therefore evidence of missing contextual authority, not evidence that UMLS lacks the underlying terminology.

## Mapping safety model

### Contextual applicability

The implementation created one applicability record for each pathway and each of the five code systems: 1,250 records total.

Applicability is independent of candidate acceptance. It answers whether the code system is reasonably in scope for the pathway-level source text.

The states are:

- `APPLICABLE`: the source contains a relevant domain of text;
- `NOT_APPLICABLE`: the target system is outside the pathway-defining source scope;
- `APPLICABILITY_REVIEW`: the pathway classification itself needs a human decision;
- `MAPPED`: at least one accepted record exists;
- `REVIEW_REQUIRED`: source-valid candidates exist but lack sufficient evidence for automated acceptance; and
- `NO_CONSERVATIVE_MATCH`: the system is applicable, but no candidate met the conservative matching rules.

A blank code list is never interpreted without the corresponding state.

### Evidence tiers

The rebuild replaced unrestricted fuzzy matching with explicit evidence classes.

| Tier   | Evidence class                                                                                   | Permitted use                                                                  |
| ------ | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| Tier 1 | Direct source-explicit identifier or equivalently strong record                                  | Eligible for acceptance after current-source validation and scope check        |
| Tier 2 | Exact current preferred/synonym phrase, constrained abbreviation, or curated LOINC six-axis rule | Eligible for acceptance only when the pathway text resolves required ambiguity |
| Tier 3 | Source-asserted map or procedure-descriptor family candidate                                     | Review candidate; not automatically accepted                                   |
| Tier 4 | Shared UMLS CUI or other non-authoritative co-membership                                         | Candidate generation only; never accepted without stronger external evidence   |

The strongest evidence per pathway/system/code was retained. Deduplication did not discard provenance.

### Per-system acceptance policy

#### LOINC

LOINC was mapped only from explicit laboratory or measurement text. The matching rules preserved component, specimen/system, property, timing, scale, method, panel, and unit distinctions where the source text supplied them.

Examples of deliberate holds included:

- CBC method or differential not explicit;
- troponin I versus T unresolved;
- high-sensitivity status unresolved;
- urinalysis panel/method incomplete;
- fibrinogen method unresolved;
- D-dimer FEU/DDU and unit method unresolved;
- beta-hydroxybutyrate property/units unresolved; and
- arterial pH scale or method unresolved.

No LOINC record was accepted merely because a test name shared a token with a pathway paragraph.

#### SNOMED CT US

SNOMED CT US matching used current preferred or synonym phrases plus the current fully specified name and compatible semantic tag. The implementation excluded or held:

- generic fragments;
- body structures when a disorder or procedure was required;
- administrative qualifiers;
- negated or exclusionary phrases;
- broad descendant expansion;
- broader subphrases superseded by a more specific accepted phrase; and
- abbreviation matches with unresolved meaning.

#### ICD-10-CM

ICD-10-CM was split from ICD-10-PCS. It was derived only from pathway-defining condition evidence, exact current terms, or the narrow official SNOMED CT US map subset.

The rebuild did not treat a category match as authority to emit every child. It also held candidates when billable specificity, laterality, encounter status, status/history semantics, sequencing, or competing choices remained unresolved.

#### CPT

CPT candidate generation was limited to pathway-defining professional procedure families supported by current CPT 2026 source records.

No CPT candidate was automatically accepted because the pathway titles generally lacked enough information to resolve:

- exact service or component;
- professional versus facility context;
- technique or approach;
- bundling and add-on status;
- modifiers;
- global or postpartum scope;
- prior-procedure status; and
- encounter context.

HCPCS or NHSN category membership was not substituted for CPT.

#### ICD-10-PCS

ICD-10-PCS candidates were limited to explicit inpatient procedure and body-part families. No seven-character PCS code was manufactured from a partial procedure name.

Every candidate remained review-required unless all seven axes could be established:

1. section;
2. body system;
3. root operation;
4. body part;
5. approach;
6. device; and
7. qualifier.

The pathway title alone did not supply those axes reliably, so zero PCS records were automatically accepted.

## Implementation tranches

### Tranche 1 — Baseline and source authority

Completed controls included:

- hashing and profiling both source files;
- confirming 250 rows and 49 fields;
- auditing prior mapping volume and fan-out mechanisms;
- confirming UMLS release identity and current source versions;
- auditing Parthenon vocabulary versions and relationship coverage;
- establishing UMLS as the final existence authority;
- documenting Parthenon as supplemental only; and
- creating a reproducible source manifest with counts and hashes.

### Tranche 2 — Current terminology extracts

The read-only extraction generated:

- canonical current LOINC records;
- canonical current CPT records;
- canonical current SNOMED CT US fully specified names;
- canonical current ICD-10-CM preferred terms;
- canonical current ICD-10-PCS preferred terms;
- selected aliases and synonyms required for deterministic exact matching;
- the full source-asserted SNOMED-to-ICD map extract;
- the safe context-free map subset; and
- the in-scope Parthenon cross-vocabulary relationship set.

All database work was read-only. Credentials were supplied only at runtime and were not written to scripts, logs, workbooks, Markdown, Git, or memory.

### Tranche 3 — Conservative mapping rebuild

The new engine:

- preserved the source row order;
- preserved all original parsed values;
- assigned pathway/system applicability;
- used normalized exact phrases and constrained abbreviations;
- respected negation and administrative qualifier removal;
- used the current SNOMED fully specified name as semantic evidence;
- applied curated LOINC rules with six-axis awareness;
- held CPT and PCS procedure-family candidates for review;
- preserved map identifiers and rules;
- treated CUI co-membership as non-authoritative;
- deduplicated on pathway/system/code;
- imposed cardinality caps and ambiguity holds; and
- emitted a documented reason for every accepted candidate, review candidate, and intentional gap.

### Tranche 4 — Expanded CSV and verification workbook

The expanded CSV added 33 terminology fields to the 49 original fields, producing 82 fields total.

For each system, the row includes:

- accepted code list;
- accepted count;
- review-candidate list;
- review-candidate count;
- applicability state; and
- mapping status.

The row also includes an overall terminology status, safety notes, and source-release identity.

The 16-sheet workbook contains the eight original sheets plus:

1. `Terminology_Crosswalk`
2. `Terminology_Candidates`
3. `Terminology_Evidence`
4. `Applicability_Ledger`
5. `Terminology_Exceptions`
6. `Terminology_QA`
7. `Source_Releases`
8. `Terminology_Methodology`

The workbook preserves one code per row in the normalized sheets so reviewers can trace each candidate to:

- pathway rank and condition;
- code system and code;
- current authoritative description;
- decision and reason;
- evidence tier and mapping method;
- source pathway field and matched text;
- source release and UMLS identifiers;
- relationship/map provenance; and
- current-source revalidation status.

### Tranche 5 — Release verification

Verification was layered rather than relying on a successful workbook export.

The mapping validator checked:

- current-source existence;
- exact source-description agreement;
- code format;
- duplicate keys;
- accepted cardinality caps;
- crosswalk-to-row-summary reconciliation;
- evidence-text presence;
- source hashes;
- original-value preservation; and
- targeted negative regressions.

The workbook validator checked:

- XLSX ZIP integrity;
- second-reader opening;
- sheet inventory;
- table, merge, and freeze-pane preservation;
- original cell values and formulas;
- formula count and formula-error matches;
- Excel row, column, sheet, and cell-size constraints; and
- rendered visual quality.

An independent release audit then rechecked provenance, accepted-versus-review separation, applicability disclosure, source preservation, formulas, and visual evidence.

### Tranche 6 — Credentialed-review enablement

The final phase converted all 1,504 review-required candidates into a decision-ready queue without changing mapping status.

The reviewer-ready workbook added:

- `Review_Guidance`
- `Reviewer_Queue`

Every queue row contains:

- unique review ID;
- priority;
- workstream;
- pathway rank and condition;
- code system, code, and current descriptor;
- live review status;
- controlled reviewer decision;
- reviewer rationale, name, and date;
- pathway/system review-group ID;
- candidate ordinal and group count;
- conservative suggested disposition;
- blocking decision;
- original decision reason;
- evidence tier and method;
- matched text and complete source text;
- UMLS release and source vocabulary version;
- source record and map provenance; and
- source URL.

Reviewer decision cells accept only:

- `ACCEPT`
- `REJECT`
- `NEEDS_MORE_CONTEXT`
- `DUPLICATE_REDUNDANT`
- `ESCALATE`

An `ACCEPT` reviewer decision does not itself promote the mapping release. Promotion requires a separately validated regeneration and release gate.

## Results

### Record-level disposition

| Code system  | Accepted records | Review-required records | Total retained records |
| ------------ | ---------------: | ----------------------: | ---------------------: |
| LOINC        |            1,365 |                     549 |                  1,914 |
| CPT          |                0 |                     541 |                    541 |
| SNOMED CT US |              108 |                      19 |                    127 |
| ICD-10-CM    |                6 |                      75 |                     81 |
| ICD-10-PCS   |                0 |                     320 |                    320 |
| **Total**    |        **1,479** |               **1,504** |              **2,983** |

The zero accepted counts for CPT and ICD-10-PCS are deliberate safety outcomes, not missing-source failures.

### Pathway-level applicability and accepted-map gaps

| Code system  | Applicable pathways | Pathways with accepted mapping | Applicable pathways without accepted mapping | Other pathways                  |
| ------------ | ------------------: | -----------------------------: | -------------------------------------------: | ------------------------------- |
| LOINC        |                 250 |                            209 |                                           41 | None                            |
| CPT          |                 121 |                              0 |                                          121 | 129 not applicable              |
| SNOMED CT US |                 250 |                             90 |                                          160 | None                            |
| ICD-10-CM    |                 153 |                              6 |                                          147 | 97 require applicability review |
| ICD-10-PCS   |                 121 |                              0 |                                          121 | 129 not applicable              |

For applicable pathways without an accepted map, a reviewer must distinguish between an existing candidate, missing specificity, and no conservative candidate.

### Review-candidate routing

| Priority | Count | Meaning                                                                     | Default routing                                |
| -------- | ----: | --------------------------------------------------------------------------- | ---------------------------------------------- |
| P1       |   569 | Bounded terminology/coding decision with direct or source-asserted evidence | Review first                                   |
| P2       |   861 | CPT or PCS candidate requiring multi-axis technique/context adjudication    | Specialty coder                                |
| P3       |    74 | Redundant/broader candidate or non-authoritative CUI co-membership          | Exclude unless stronger evidence is documented |

The workstream distribution is:

| Workstream             | Count |
| ---------------------- | ----: |
| CPT coding             |   541 |
| ICD-10-PCS coding      |   320 |
| Laboratory terminology |   491 |
| Diagnosis coding       |    75 |
| Redundancy/exclusion   |    66 |
| Clinical terminology   |    11 |

The queue begins with zero reviewer decisions and zero candidate promotions.

### Exceptions and evidence

The package contains:

- 1,250 applicability records;
- 2,983 candidate records;
- 3,062 evidence records; and
- 687 explicit exception/gap records.

Exceptions are not silently converted into blanks. They retain the pathway, system, applicability, mapping state, candidate count, and reason.

## Verification evidence

### Mapping release

The final mapping validation reported:

- status `PASS`;
- 250 source rows;
- 49 source fields;
- 250 expanded rows;
- 82 expanded fields;
- zero unsupported codes;
- zero description mismatches;
- zero duplicate candidates;
- zero summary reconciliation failures;
- all applicability gaps disclosed;
- negative tests passed;
- original values preserved; and
- source hashes matched the build.

### Expanded workbook

The expanded workbook validation reported:

- status `PASS`;
- 16 sheets;
- 149,083 original cells/formulas compared;
- original values and formulas preserved;
- original merges, tables, and freeze-pane states preserved;
- valid ZIP package;
- successful `openpyxl` second-reader load;
- 25 formulas;
- zero formula errors;
- zero oversized cells; and
- 22 rendered previews with visual QA status `PASS`.

### Reviewer-ready workbook

The reviewer-ready workbook validation reported:

- status `PASS`;
- valid ZIP package with 57 entries;
- all 16 previous sheets preserved;
- 401,172 existing cells/formulas independently compared;
- two new review sheets;
- 1,504 queue rows;
- 32 queue columns;
- 1,504 live review-status formulas;
- zero initialized reviewer decisions;
- zero candidate promotions;
- all candidates current-source revalidated; and
- 21 nonempty visual previews covering all sheets plus queue-bottom and provenance checks.

The first visual pass found a real layout defect in the guidance sheet: a narrative column had been treated as a spacer, causing extreme vertical wrapping. The column was widened, affected row heights were constrained, the workbook was rebuilt, and every preview was regenerated before release.

### Known workbook-engine limitation

The artifact engine accepted freeze-pane calls for the two new review sheets but did not serialize those panes during the imported-workbook round trip. This limitation does not alter data, formulas, tables, or original sheet state.

The mitigation is:

- table filters remain enabled;
- all original freeze-pane states remain preserved;
- the limitation is disclosed in validation evidence; and
- a future implementation should revalidate the artifact-engine version or use a supported post-export control only if the repository’s spreadsheet-authoring policy permits it.

## Security, privacy, and licensing controls

### Database safety

The live terminology databases were queried read-only. The extraction contract set `default_transaction_read_only=on` and did not create, alter, truncate, insert, update, or delete database objects or records.

The work retained only the host/user access context needed for future operations. The database password was not persisted in:

- source code;
- shell scripts;
- SQL files;
- Markdown;
- workbook cells;
- logs;
- Git history; or
- durable assistant memory.

### UMLS and CPT licensing

UMLS and CPT content is licensed. The generated extracts, candidate CSVs, expanded CSV, verification workbooks, review workbooks, and preview files are local authorized-review artifacts and are intentionally excluded from Git.

This devlog records only aggregate counts, release identifiers, controls, and hashes. It does not reproduce the licensed terminology corpus or CPT descriptor set.

Any next implementation must preserve these boundaries:

- no licensed RRF rows in Git;
- no generated terminology extracts in Git;
- no CPT descriptor redistribution without applicable rights;
- no public CI job that exposes licensed fixtures;
- no production deployment artifact that bundles the full terminology corpus; and
- no log or error payload that unintentionally exports restricted source text.

## Relationship to the governed care-pathway catalog

The terminology package and the governed `care_pathways` catalog solve different problems.

The existing catalog models:

- immutable pathway source releases;
- pathway definitions and versions;
- evidence claims and sources;
- review and approval state;
- activation state;
- approved staff-content boundaries; and
- audience-specific downstream projections.

This terminology work adds candidate semantic links from a pathway version to external code-system releases. Those links must not bypass the catalog’s governance.

The required future flow is:

```text
immutable pathway source release
  + immutable terminology source release
  + deterministic candidate-generation version
    -> pathway terminology candidate set
      -> credentialed review decisions
        -> institution-approved terminology projection release
          -> separately activated pathway version
            -> clinician-confirmed encounter instance
              -> bounded staff/patient/Eddy projections
```

The existing `ApprovedPathwayCatalogReadService` should remain the staff-serving boundary. Raw candidates, reviewer notes, UMLS rows, and unrestricted source prose must never be returned through that service.

MS-DRG, ICD, SNOMED, CPT, LOINC, and PCS codes remain indexing and evidence signals unless a separate workflow authority assigns them. They must not silently select a pathway or diagnose a patient.

## What remains incomplete

The automation is complete; the clinical program is not.

### Credentialed review

All 1,504 queue rows require one of the controlled decisions. A complete review requires more than filling the decision column.

For an `ACCEPT` decision, the reviewer must record:

- reviewer identity and credential/role;
- review date;
- rationale tied to the blocking question;
- institutional scope;
- source pathway version;
- terminology release;
- any local catalog or policy dependency; and
- whether a second reviewer or approval is required.

`NEEDS_MORE_CONTEXT` and `ESCALATE` remain unresolved states and cannot be promoted.

### Local clinical and operational binding

The package does not yet know:

- the institution’s laboratory test catalog and methods;
- the EHR order IDs bound to LOINC;
- local CPT/HCPCS chargemaster conventions;
- professional versus facility billing responsibilities;
- coder specialty ownership;
- payer-specific policy constraints;
- local SNOMED extension concepts;
- ICD coding guideline interpretations adopted by the institution;
- procedure documentation sufficient for PCS axes;
- pathway eligibility/exclusion logic; or
- which pathway version is approved for which facility/service line.

### Release promotion

Reviewer decisions have not been regenerated into a new mapping release. No approved terminology projection exists. No catalog release was created or activated from this enrichment.

### Application integration

No API, UI, Hummingbird, Eddy, Virtual Rounds, 4D, FHIR, or EHR integration was implemented as part of this task.

## Next evolution roadmap

The next evolution should be implemented as a governed product capability, not as a larger spreadsheet.

### Phase A — Repositoryize the pipeline

- **Priority:** P0
- **Goal:** Make the transformation reproducible from a clean checkout without committing licensed content.

Required work:

- Replace local absolute paths with explicit CLI arguments and environment variables.
- Add a checked-in orchestration command under `db/umls` or a dedicated care-pathway terminology package.
- Support commands such as `extract`, `build`, `validate`, `review-queue`, `render`, `status`, and `release`.
- Keep connection strings and passwords outside Git.
- Require read-only database sessions for extraction.
- Write generated artifacts only to an ignored workspace.
- Generate deterministic manifests containing release metadata, counts, filters, and hashes.
- Add a synthetic, license-safe fixture set for unit and CI tests.
- Run licensed-source integration tests only on an authorized private runner.
- Replace hard-coded inventory counts with versioned manifest assertions.
- Add semantic versioning for mapping-rule configuration.
- Fail closed if source digests, source versions, or expected TTY filters drift.
- Make a clean rerun produce identical normalized CSVs when all inputs are identical.

Exit gate:

- a clean checkout can reproduce the normalized candidate and validation manifests from authorized inputs;
- no licensed rows or secrets enter Git or public CI; and
- deterministic reruns match by hash.

### Phase B — Add an immutable terminology-governance model

- **Priority:** P0
- **Goal:** Move review state from mutable spreadsheets into append-only governed authorities.

Recommended authorities:

| Authority                         | Purpose                                                                            |
| --------------------------------- | ---------------------------------------------------------------------------------- |
| `terminology_releases`            | Immutable UMLS and component source identities, versions, filters, and digests     |
| `terminology_rule_sets`           | Versioned candidate-generation configuration and code digest                       |
| `pathway_terminology_batches`     | Exact pathway release + terminology release + rule-set identity                    |
| `pathway_terminology_candidates`  | One pathway-version/system/code candidate with disposition and provenance          |
| `pathway_terminology_evidence`    | Source field, matched text, relationship, map rule, and source record              |
| `terminology_review_batches`      | Assigned review scope, owner, specialty, due date, and status                      |
| `terminology_review_decisions`    | Append-only reviewer decision, rationale, identity, credential role, and timestamp |
| `terminology_review_events`       | Reopen, supersede, conflict, escalation, and audit events                          |
| `terminology_projection_releases` | Immutable, approved output set eligible for catalog binding                        |

Required controls:

- UUID business identifiers at API boundaries.
- Foreign keys to exact pathway-version and terminology-release authorities.
- Unique pathway-version/system/code identity within a batch.
- Append-only or superseding decision semantics.
- No overwrite of source evidence or prior review.
- Conflict handling for multiple reviewers.
- Release-wide count and digest controls.
- Explicit separation between candidate, reviewer-accepted, institution-approved, and operationally active.
- Row-level security or capability checks for licensed descriptors and reviewer data.

Exit gate:

- the database can reproduce the spreadsheet queue and its counts without using the spreadsheet as the system of record;
- prior decisions remain auditable after supersession; and
- no candidate can become operational through a direct status update.

### Phase C — Build the credentialed-review application

- **Priority:** P0
- **Goal:** Provide a safe, usable review surface for laboratory, clinical terminology, diagnosis, CPT, and PCS specialists.

Required capabilities:

- SSO-authenticated reviewer identity.
- Capability-scoped access by code system and specialty.
- Queue filters for priority, workstream, pathway, system, status, and assignee.
- Side-by-side comparison of candidates within the same review group.
- Visible pathway text, matched evidence, current descriptor, release, source record, map rule, and blocking question.
- Controlled decisions with required rationale.
- Structured missing-context fields instead of free-text-only escalation.
- Reviewer credential and institutional-role capture.
- Assignment, due date, escalation, and conflict workflows.
- Bulk rejection of exact redundant candidates only when the group logic is explicit and audited.
- No bulk acceptance of CPT or PCS candidates.
- Second-review or dual-signature support for high-risk or policy-defined mappings.
- Complete decision history and audit export.
- Accessibility and healthcare human-factors review.

Exit gate:

- every decision is attributable, evidence-linked, and version-pinned;
- unresolved states cannot be mistaken for rejection or acceptance; and
- the UI cannot modify source evidence.

### Phase D — Implement promotion and release gates

- **Priority:** P0
- **Goal:** Convert completed review decisions into an immutable terminology projection release.

Required gates:

- exact pathway source-release digest;
- exact terminology-release digest;
- exact mapping-rule-set digest;
- all required reviewers complete;
- no unresolved conflicts;
- no `NEEDS_MORE_CONTEXT` or `ESCALATE` record promoted;
- accepted codes still current and unsuppressed;
- descriptors still match the pinned release;
- code-system-specific validation passes;
- reviewer roles satisfy institutional policy;
- release counts reconcile to decisions;
- negative regression suite passes;
- patient/staff audience boundary review passes; and
- immutable output digest is recorded before activation.

Promotion must create a new release. It must never mutate the candidate batch or prior approved release.

Exit gate:

- the approved terminology release is reproducible from immutable inputs and append-only decisions;
- any changed source or rule creates a new release identity; and
- rollback means selecting a prior approved release, not rewriting history.

### Phase E — Bind to local clinical catalogs

- **Priority:** P1
- **Goal:** Replace narrative ambiguity with institution-specific structured authorities.

#### LOINC

- Import the local laboratory catalog.
- Bind local test/order/result IDs to LOINC release versions.
- Capture specimen, method, property, scale, and units.
- Distinguish order panels from result observations.
- Model local method changes and effective dates.
- Add UCUM unit validation where applicable.

#### CPT

- Bind to the institution’s licensed CPT/HCPCS and chargemaster authorities.
- Model professional versus facility responsibilities.
- Capture component, bundling, modifier, add-on, and global-period context.
- Require coding-owner approval before operational projection.
- Keep licensed descriptors behind appropriate access controls.

#### SNOMED CT US

- Pin the exact US edition and any local extension.
- Validate active concept state and semantic tag.
- Model replacement/inactivation associations.
- Decide whether post-coordination is allowed and how expressions are normalized.

#### ICD-10-CM

- Pin effective fiscal-year releases.
- Model laterality, encounter, status/history, and sequencing requirements.
- Preserve official map advice and rule evaluation.
- Do not use code assignment as a diagnosis inference.

#### ICD-10-PCS

- Capture all seven PCS axes from structured procedure documentation.
- Distinguish candidate root operations and body parts.
- Require approach, device, and qualifier evidence.
- Bind final selection to the coded inpatient encounter, not the pathway title.

Exit gate:

- accepted mappings reference local structured authorities where required;
- no narrative-only assumption supplies a mandatory coding axis; and
- effective dates and replacements are queryable.

### Phase F — Integrate with the governed care-pathway catalog

- **Priority:** P1
- **Goal:** Attach approved terminology projections to exact, inactive pathway versions without weakening existing serving gates.

Required work:

- Create a new immutable care-pathway catalog release for the `6617…` source workbook if it is semantically adopted.
- Reconcile the enriched source against the prior production release rather than assuming equivalence.
- Attach approved terminology projection release IDs to exact pathway versions.
- Keep all new pathway versions inactive until institutional clinical approval.
- Extend catalog QA to reconcile terminology counts and review state.
- Extend the approved read service to expose only explicitly approved staff-safe terminology fields.
- Exclude raw candidates, internal reviewer notes, licensed descriptors, and source-only UMLS identifiers from general application payloads.
- Preserve many-to-many DRG and terminology relationships.
- Require clinician confirmation before binding a pathway to an encounter.

Exit gate:

- catalog and terminology release identities are independently immutable and jointly auditable;
- no candidate is visible through the approved-content boundary; and
- activation still requires exact-version institutional approval.

### Phase G — Add audience-specific projections

- **Priority:** P1
- **Goal:** Use approved terminology safely across Zephyrus surfaces.

#### Care-team and Virtual Rounds

- Show approved pathway terminology as evidence and navigation context.
- Display version, source, review status, and institutional owner.
- Never present terminology as an automatically assigned diagnosis or billable service.
- Allow a clinician to confirm, reject, or defer a pathway candidate.

#### 4D hospital view

- Project only privacy-bounded aggregate or operational state.
- Avoid displaying patient diagnoses or code lists in spatial overlays without explicit authorization.
- Link visualization state back to the governed encounter/pathway instance.

#### Hummingbird Staff

- Provide concise approved staff instructions and provenance.
- Keep coding-review details out of ordinary mobile workflow unless the user has the relevant capability.

#### Hummingbird Patient

- Do not expose raw terminology codes as patient instructions.
- Use separately reviewed patient-language projections.
- Preserve consent, release, reading-level, localization, and safety review.

#### Eddy

- Retrieve only approved, exact-version content.
- Include citations and release identity.
- Keep patient-context reasoning local-only and draft-only.
- Do not activate pathways, assign codes, diagnose, order, or write global memory from terminology candidates.

Exit gate:

- every audience receives a separately governed projection;
- raw candidate and reviewer data remain inaccessible to unauthorized audiences; and
- end-to-end tests prove fail-closed behavior.

### Phase H — Support release upgrades and drift

- **Priority:** P1
- **Goal:** Make terminology currentness a managed lifecycle.

Required work:

- Detect new UMLS, LOINC, SNOMED CT US, CPT, ICD-10-CM, and ICD-10-PCS releases.
- Create new terminology-release records instead of updating prior rows.
- Diff active, inactive, replaced, changed-description, and new codes.
- Re-evaluate source maps and map advice.
- Re-run candidates only for affected pathways and rules.
- Preserve prior review decisions but mark them stale when their source record changes.
- Require targeted re-review for changed or replaced codes.
- Define effective-date overlap and future-dated release handling.
- Add a release-readiness dashboard and expiring-release alerts.

Exit gate:

- a new terminology release produces an attributable delta;
- unchanged decisions can be carried forward only under an explicit equivalence rule; and
- stale mappings cannot remain silently active.

### Phase I — Testing, observability, and audit

- **Priority:** P1
- **Goal:** Make safety properties continuously verifiable.

Required automated tests:

- source filter and currentness tests;
- deterministic normalization tests;
- negation and exclusion tests;
- LOINC six-axis ambiguity tests;
- SNOMED semantic-tag tests;
- ICD category fan-out regression tests;
- official-map rule/advice tests;
- CPT and PCS no-auto-accept tests;
- duplicate and cardinality-cap tests;
- source-description reconciliation tests;
- release-digest tests;
- row-summary and normalized-crosswalk reconciliation;
- reviewer-state transition tests;
- authorization and negative-access tests;
- append-only database tests;
- workbook round-trip tests where workbook export remains supported; and
- full approved-content boundary tests.

Required observability:

- candidate counts by release/system/evidence tier;
- acceptance, rejection, escalation, and unresolved rates;
- reviewer turnaround and conflict rates;
- stale or replaced code counts;
- source-version drift;
- currentness validation failures;
- attempts to serve unapproved content;
- attempts to access licensed descriptors without capability; and
- reproducibility/digest mismatches.

Exit gate:

- safety regressions fail CI or release promotion;
- audit records identify the exact source, rules, reviewer, and release; and
- operational alerts distinguish data drift from clinical-review backlog.

### Phase J — Licensing and deployment hardening

- **Priority:** P1
- **Goal:** Ensure the production architecture respects terminology licensing and least privilege.

Required work:

- Document authorized users and deployment environments for UMLS and CPT content.
- Keep the UMLS corpus in its dedicated database.
- Add a read-only application connection only after an approved use case exists.
- Prefer approved derived identifiers over broad descriptor replication.
- Restrict CPT descriptors by capability and environment.
- Redact licensed text from logs, telemetry, support bundles, and public CI artifacts.
- Define retention and deletion rules for local exports.
- Add license review to release checklists.
- Test `PUBLIC`, unauthenticated, patient, and ordinary staff negative access.

Exit gate:

- deployment has documented rights, least-privilege access, and negative-access evidence;
- no licensed corpus is embedded in Git or application images; and
- audit logs are useful without redistributing restricted content.

## Recommended data contract

The next implementation should preserve these state dimensions explicitly:

| Dimension              | Example values                                                                           |
| ---------------------- | ---------------------------------------------------------------------------------------- |
| Applicability          | applicable, not applicable, review required                                              |
| Candidate evidence     | exact term, constrained abbreviation, LOINC rule, source map, procedure family, CUI-only |
| Automated disposition  | accepted, review required, rejected by rule                                              |
| Reviewer decision      | accept, reject, needs context, duplicate/redundant, escalate                             |
| Review completion      | pending, unresolved, decided, conflicted, superseded                                     |
| Institutional approval | not submitted, under review, approved, rejected, expired                                 |
| Release state          | draft, validated, approved, active, retired                                              |
| Operational binding    | unbound, candidate, clinician confirmed, released                                        |

Collapsing these into one `status` column would recreate the ambiguity this rebuild removed.

## Next-version acceptance gates

No next version should be called complete until all applicable gates pass.

### Source and lineage

- [ ] Exact source CSV and workbook digests recorded.
- [ ] Terminology release and component versions recorded.
- [ ] Rule-set version and code digest recorded.
- [ ] Licensed source files remain outside Git.
- [ ] Database extraction is read-only and attributable.
- [ ] Generated extract counts and hashes reconcile.

### Mapping

- [ ] Every emitted code exists in the pinned current source.
- [ ] Every emitted description matches the source record.
- [ ] Every mapping has pathway evidence and evidence class.
- [ ] No uncontrolled descendant or category fan-out.
- [ ] No CUI co-membership accepted as equivalence.
- [ ] No CPT or PCS auto-accept without required context.
- [ ] All intentional blanks have explicit applicability/mapping states.
- [ ] Duplicate and cardinality controls pass.

### Review and approval

- [ ] Reviewer identity, role, rationale, and date captured.
- [ ] Required second reviews complete.
- [ ] No unresolved or conflicted decision promoted.
- [ ] Institutional approval references the exact release.
- [ ] Patient/staff/Eddy projection approvals remain separate.

### Release

- [ ] Normalized counts reconcile to summaries.
- [ ] Original source values remain unchanged.
- [ ] Structural and formula validation pass.
- [ ] Independent reader and package integrity pass.
- [ ] Visual QA passes.
- [ ] Negative regression suite passes.
- [ ] Output digest recorded before promotion.
- [ ] New release identity created; no prior release mutated.

### Application serving

- [ ] Only approved exact-version terminology crosses the catalog read boundary.
- [ ] Encounter assignment requires clinician confirmation.
- [ ] Patient language is separately reviewed and released.
- [ ] Eddy remains citation-backed, bounded, and non-authoritative.
- [ ] Unauthorized and unapproved-content access fails closed.

## Open product and governance decisions

The next evolution requires explicit institutional answers to these questions:

1. Which credentials and roles may review each code system?
2. Which mappings require a second reviewer or committee approval?
3. Is pathway-level terminology intended for search, clinical reference, analytics, coding preparation, billing, or all of these under separate projections?
4. Which facilities, service lines, and legal entities share a terminology decision?
5. What local laboratory, order, chargemaster, and coding authorities are canonical?
6. How are payer-specific decisions separated from source terminology mapping?
7. How are CPT licensing and descriptor access enforced in the UI and APIs?
8. What effective-date policy applies when pathway and terminology releases overlap?
9. How are reviewer conflicts resolved and superseded?
10. Which decisions may be carried forward across terminology releases?
11. What constitutes semantic equivalence for a changed workbook digest?
12. Who may promote a reviewed candidate batch into an approved terminology projection?
13. Which downstream audiences may see codes, descriptors, or only patient/staff language?
14. What evidence is required before an approved pathway can influence an encounter workflow?
15. What rollback, recall, and notification process applies after a code is inactivated or a mapping is found unsafe?

These decisions should be recorded as policy and enforced in data models and services rather than left as spreadsheet conventions.

## Operational handoff

The reviewer-ready workbook is the current handoff artifact for authorized credentialed review.

Recommended review order:

1. Review P1 laboratory, SNOMED, and ICD-10-CM candidates.
2. Resolve P3 redundant and CUI-only candidates, normally by exclusion unless stronger evidence exists.
3. Route P2 CPT candidates to appropriate professional/facility coding owners.
4. Route P2 PCS candidates to inpatient coding specialists with complete procedure documentation.
5. Keep all candidates in the same `review_group_id` together during comparison.
6. Complete reviewer rationale, name, and date for every decided row.
7. Leave `NEEDS_MORE_CONTEXT` and `ESCALATE` unresolved until the missing authority exists.
8. Do not use reviewer `ACCEPT` as a production promotion flag.
9. Regenerate a new immutable mapping release after review.
10. Rerun source, mapping, reconciliation, workbook, independent-reader, regression, and visual QA.
11. Create a new inactive governed catalog release if the `6617…` workbook is adopted.
12. Complete institutional pathway review and approval before any activation or downstream projection.

## Final state

The terminology-enrichment feature is mechanically complete and independently validated for authorized review.

It provides:

- current-source terminology provenance;
- conservative pathway-level acceptance;
- explicit review quarantine;
- contextual applicability;
- non-hallucination controls;
- normalized evidence and exception authorities;
- reproducible hashes and counts;
- a usable credentialed-review queue; and
- a clear path into the governed Zephyrus catalog.

It does not provide:

- clinical approval;
- encounter coding;
- billability;
- payer applicability;
- order authority;
- patient-specific appropriateness;
- catalog activation; or
- downstream serving authorization.

That boundary is intentional and must remain intact through every next evolution.
