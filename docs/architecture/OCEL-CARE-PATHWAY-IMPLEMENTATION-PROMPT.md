# Claude Code Implementation Prompt — OCEL Care-Pathway Modeling & Measurement Program

**Target repo:** `Zephyrus`
**Companion specification:** `docs/architecture/OCEL_Care_Pathway_Program_Specification.docx` (ACUM-ENG-OCEL-002)
**Source research:** `docs/architecture/OCEL-INDIVIDUAL-CARE-PATHWAY-MODELING-AND-MEASUREMENT-RESEARCH-2026-07-24.md`
**Source worklist:** `docs/architecture/OCEL-CARE-PATHWAY-250-MODELING-WORKLIST-2026-07-24.md`
**Scope:** Phases 0–6, complete program
**Written:** 2026-07-24

> **How to use this file.** Paste §0–§4 as the opening prompt to Claude Code, then work
> one phase at a time by pasting the relevant `W#` section. Do not paste the whole
> document as a single instruction — each phase has its own exit gate, and the gates
> exist precisely so that the work cannot run ahead of its evidence.

---

## 0. Mission

You are implementing the platform that lets Zephyrus measure 250 governed care pathways
against what actually happened to individual patients.

You are **not** importing 250 rows, and you are **not** writing 250 rule functions.

You are building five things:

1. **One** enterprise OCEL 2.0 observation layer (hardened, not new).
2. **250** independently versioned, computable pathway definition packages.
3. A **patient–pathway assignment layer** with confirmation states and version pinning.
4. A **deterministic measurement ledger** that is reproducible and append-only.
5. A **governed analysis and review layer** that keeps hypotheses separate from conclusions.

### The six separations that govern every design decision

| Claim you might be tempted to make | What the system is allowed to say |
| --- | --- |
| "This care was missed." | A required, applicable step had no matching evidence **and** source coverage was sufficient to conclude that. |
| "This was an undue delay." | Signed variance against an approved anchor, clock, and window — with coverage and review status attached. |
| "Resources were insufficient." | A labelled resource *candidate* backed by co-occurring capacity evidence, or `insufficient_evidence`. |
| "This was an error." | A safety **screen** that has entered governed review; only an adjudicator may conclude preventability. |
| "X caused Y." | Contributing-factor evidence attached to a review case. Never an automated causal assertion. |
| "This code proves this step." | An **approved binding member**, not a candidate crosswalk record. |

If a change you are about to make would let the system skip the right-hand column, stop
and raise it instead of implementing it.

---

## 1. Required reading before writing any code

Read these in order. Do not skim; several of them contradict assumptions you would
otherwise make.

### 1.1 Repository conventions

| File | Why |
| --- | --- |
| `AGENTS.md` | Build/deploy conventions. **Note: it says "Laravel 11" and is stale — `composer.json` pins `laravel/framework ^12.61.1`.** |
| `CLAUDE.md` | Design context and the Token Canon. Any UI you add is bound by it. |
| `.claude/rules/auth-system.md` | Auth is production-deployed and **additive-only**. Do not touch it. |
| `composer.json`, `phpunit.xml` | **PHPUnit 11. Pest is NOT installed** despite `tests/Pest.php` existing. Four Pest-syntax files are excluded in `phpunit.xml` — do not add more. |
| `config/database.php` + `AGENTS.md` | `config/database.php` sets only `'search_path' => 'prod,public'` and `'schema' => 'prod'`. The `raw → stg → prod → star` flow is documented in `AGENTS.md`. Schemas actually created by migrations: `arena, audit, care_pathways, eddy, fhir, flow_core, flow_realtime, governance, hosp_ingest, hosp_org, hosp_ref, hosp_space, integration, ocel, ops, patient_communications, patient_experience, prod, raw, regional, rounds`. Note `stg` and `star` are **not** created by any Laravel migration, and `flow_core` — the largest OCEL source — is easy to overlook. |
| `app/Traits/SafeMigration.php` | `safeDropIfExists()`, `safeDropSchema()`, `constraintExists()`, `indexExists()`; destructive operations are gated on `app()->environment('local')`. Every new migration uses this trait. |
| `scripts/test-suite.sh` | Suite aliases: `unit`, `contract`, `integration`, `admin`, `migration`, `conformance`, `browser`, `full`. `migration` = every `tests/Feature/**/*SchemaTest.php` plus `tests/Feature/Security/ProductionWebBoundaryTest.php`. `conformance` = 4 PHPUnit files **plus** `cd arena && python -m pytest tests -q`. |
| `scripts/check-ui-canon.sh` | Hard-fails on `font-bold`/`font-extrabold`, `text-[Npx]` outside `Components/cockpit/`, `oklch(`, and any NEW `backdrop-blur` file. |
| `scripts/check-clean-room.sh` | The `arena/` sidecar is clean-room. Respect `arena/CLEAN-ROOM.md`. |
| `.github/workflows/ci.yml` | Eleven jobs — `backend-quality`, `backend-tests`, `frontend`, `security`, `arena`, `browser`, `dast`, plus four Hummingbird mobile jobs. The gate steps (migrate, PHPUnit shards, `tsc`, vitest, vite build, UI canon, security suite, arena pytest, DAST) are wrapped in `scripts/capture-release-evidence.sh`. |

### 1.2 The domain as it exists today

| File / area | Read for |
| --- | --- |
| `database/migrations/2026_07_21_000900_create_care_pathway_catalog.php` | The `care_pathways` schema, its append-only triggers, and its content-protection PL/pgSQL functions. |
| `database/migrations/2026_07_22_001400_create_patient_pathway_instance_history.php` | `care_pathways.stage_definitions` and the `patient_experience.pathway_*` instance tables. |
| `database/migrations/2026_07_04_000200_create_ocel_schema.php` | The OCEL core. **No cross-table FKs by design** — integrity comes from deterministic IDs plus unique constraints. |
| `app/Domain/Ocel/OcelProjector.php` | `project()`, `projectAncillary()`, `reconcile()`, `ensureCatalog()`, the seven source collectors, and `flush()`. |
| `app/Domain/Ocel/EmissionMap.php` | Per-source transformers and `hashRef()` — a **12-hex-char truncated SHA-256** producing `patient-<hash>` / `enc-<hash>` IDs. |
| `app/Domain/Ocel/OcelCatalog.php` | `VERSION = 1`, **38** declared object types (5 declared-but-never-emitted: `Order`, `EVS Task`, `Staff Assignment`, `Alert`, `PDSA / Intervention`), **56** activities. |
| `app/Domain/Arena/FlowReviewService.php` | `sourceSignature()` — **this is the weak signature the program must replace** (see §W1.1). |
| `arena/app/pathways.py` | The three hard-coded pathway rule functions (`sepsis`, `surgical_safety`, `home_hospital`). W3 deprecates them behind a parity test; W5 removes them when the Arena conformance route goes definition-driven. |
| `app/Services/CarePathways/CatalogImportService.php` | How a release is imported today. |
| `app/Services/CarePathways/ApprovedPathwayCatalogReadService.php` | The governance read boundary. **Only its own test calls it today** — it must become the sole downstream route. |
| `config/care-pathways.php` | Ten serving flags, all defaulting `false` (nine top-level plus the nested `demo.enabled`); the pinned `source_release` block; `expected_controls`; the 12 `raw_tables` entries (11 with the `raw.drg_cp_` prefix plus `raw.drg_care_pathway_verification_imports`); the 28 `source_section_fields`. |
| `database/migrations/2026_06_25_000030_create_healthcare_integration_foundation_tables.php` | `integration.sources`, `integration.terminology_maps` (**currently zero rows**), and the canonical-event plumbing. |

### 1.3 Facts you must not re-derive incorrectly

- `config/care-pathways.php` pins the **previous** release:
  `source_csv_sha256 = 2e3ac282…a6ae`, `verification_workbook_sha256 = 42cadf84…5bd171`.
  The terminology-expanded release has **different** digests
  (`7ac306c4…081b1` CSV, `77399527…530b7` workbook). Phase 0 exists because of this.
- `OcelProjector::reconcile()` compares **windowed source row counts** against
  **unwindowed total projected counts**. That is a real defect, not a misreading.
- There is **no** linkage anywhere in `app/` between `care_pathways.*` and `ocel.*`.
  Grep both directions before assuming otherwise.
- `ocel.*` has **no Eloquent models**; it is accessed via `DB::table(...)`.
- `care_pathways.{stage,milestone,activity,goal}_definitions` **exist but are empty**.
- `ocel:export` is **not** scheduled, but `ocel:project` **is**: `bootstrap/app.php:201`
  runs `ocel:project --days=90 --reconcile` `dailyAt('02:30')`, and `App\Jobs\RefreshOcelLog`
  runs `everyFifteenMinutes()->withoutOverlapping()`. **W1 therefore changes a live nightly
  job — plan the rollout accordingly.**

---

## 2. Ground rules

### 2.1 Safety and governance

1. **Nothing you build may activate a pathway.** Every serving flag in
   `config/care-pathways.php` stays `false` by default, and new flags follow the same
   pattern (`filter_var(env(...), FILTER_VALIDATE_BOOL)` defaulting to `false`).
2. **Candidate terminology and approved bindings live in different tables.** There must
   be no code path — not a backfill, not a seeder, not an admin command — that copies a
   candidate crosswalk record into a binding member without a persisted review record.
3. **Measurement is append-only.** Never `UPDATE` a completed run or step measurement.
   Corrections create a new run with `supersedes`.
4. **`data_unavailable` is never `missing`.** Any aggregation that collapses them is a bug.
5. **No new patient-facing or assistant-facing surface** may read `care_pathways.*` or
   `pathway_measurement.*` directly. Everything routes through the approved-catalog read
   service and the governed projection layer.
6. **PHI discipline:** OCEL object IDs stay hashed via `EmissionMap::hashRef()` semantics.
   Do not introduce raw MRNs, names, or free-text clinical narrative into `ocel.*`.

### 2.2 Engineering

1. Laravel 12 / PHP 8.4. Format with `./vendor/bin/pint`. CI runs `pint --test`.
2. New migrations `use App\Traits\SafeMigration;`. Follow the local idiom: newer domain
   schemas (`care_pathways`, `patient_experience`) use `DB::unprepared(<<<'SQL' … SQL)`
   raw DDL; `ocel`, `arena`, `integration` use `Schema::create('schema.table', …)`.
   **Match the schema you are extending.**
3. Models are schema-qualified: `protected $table = 'pathway_measurement.runs';`, explicit
   `$primaryKey`, `protected $guarded = []`, `getRouteKeyName()` returning the UUID column.
4. Tests are PHPUnit 11 classes under `tests/Feature/**` and `tests/Unit/**`. Every new
   schema gets a `*SchemaTest.php` so it joins the `migration` suite automatically.
5. Deployment is manual and path-scoped:
   `./deploy.sh --migrate --path database/migrations/<file>.php`. Write migrations that
   are individually deployable and idempotent.
6. Register new API routes in a dedicated route file wired through
   `app/Providers/RouteServiceProvider.php` (that is where `routes/care-pathways.php` and
   `routes/patient.php` are registered — **not** `bootstrap/app.php`).
7. Any React you add obeys the Token Canon in `CLAUDE.md`: Figtree via `font-sans`,
   weights 400/500/600 only, `healthcare-*` tokens with `dark:` pairs, `Surface`/`Card`/
   `Panel` primitives, `shadow-sm` at rest, `tabular-nums` for metrics.

### 2.3 How to work

- Work **one workstream at a time**. Each `W#` below has an exit gate; do not start the
  next workstream until the gate's tests are green.
- Before each workstream, run `git log --oneline -10` and re-read the files listed in its
  "Read first" block. The repo moves.
- When a workstream requires an institutional decision (see §5), **stop and ask**. Do not
  invent a clinical default. The twenty decisions in §5 are owned by humans.
- Prefer additive migrations. If you must change an existing table, add columns and
  backfill; do not rewrite.
- Every deliverable is done when it has: the code, a test that would fail without it, and
  a line in the phase's evidence summary.

---

## 3. Architecture contract

```
governed source records  (raw.*, integration.canonical_events, FHIR)
        │
        ▼
canonical identity + event lifecycle
        │
        ▼
ONE enterprise OCEL observation layer   (ocel.*)
        │
        ├── definition vA (care_pathways.*) + instance + run (pathway_measurement.*)
        ├── definition vB                   + instance + run
        └── … 250
```

### 3.1 Six kinds of truth — never collapsed into one flag

| Truth | Lives in |
| --- | --- |
| Source / evidence | `raw.*`, `integration.*`, `care_pathways.catalog_releases` |
| Definition | `care_pathways.*` definition-side tables |
| Observation | `ocel.*` |
| Assignment | `pathway_measurement.instances` + `instance_assignments` |
| Measurement | `pathway_measurement.runs` + `step_measurements` + `constraint_measurements` |
| Review / action | `pathway_measurement.review_cases` + `review_decisions` + `corrective_actions` |

### 3.2 Hard prohibitions

- No 250 per-pathway event logs.
- No single unqualified "pathway compliance %" anywhere in the codebase, API, or UI.
- No DRG-driven automatic activation of a pathway instance.
- No object-centric alignment in an online or alerting code path.
- No prospective alerting until Gate 10 (§4, W6) is explicitly authorised.
- No individual-clinician aggregation of deviations.

---

## 4. Workstreams

Each workstream states: **Objective → Read first → Deliverables → Schema → Tests → Exit gate → Guardrails.**

---

### W0 — Accept the terminology-expanded source release

> Maps to Phase 0. Estimated shape: 1 migration, 1 service extension, 2 commands, 3 test classes.

**Objective.** Land the terminology-expanded release as a *separately manifested, lossless
derivative* of the accepted baseline, with its terminology preserved strictly as candidate
evidence and with zero silent overwrite of the prior release.

**Read first**

- `app/Services/CarePathways/CatalogImportService.php`
- `app/Services/CarePathways/CatalogReconciliationService.php`
- `app/Console/Commands/CarePathwaysAdoptReleaseCommand.php` (`care-pathways:adopt-raw-release`)
- `config/care-pathways.php` — the `source_release`, `expected_controls`, `raw_tables` blocks
- `tests/Support/CarePathwayRawFixture.php`
- `tests/Feature/CarePathways/CarePathwayCatalogAdoptionTest.php`

**Deliverables**

1. **A second release manifest.** Extend `config/care-pathways.php` with a
   `source_releases` array keyed by `dataset_key`, retaining the existing pinned block as
   the first entry and adding:

   ```php
   'drg-care-pathways-verification-package-v43.1-terminology-expanded-20260724' => [
       'source_csv_sha256'            => '7ac306c44737c3a7af6ec4a499adec2a222c62d0568c72c65ea773fc00d081b1',
       'verification_workbook_sha256' => '77399527d3b22f5d3ee7bf41066e46d1f9a34c5febe6d400a8f7fcd9769530b7',
       'derives_from'                 => 'drg-care-pathways-verification-package-v43.1-20260721',
       'semantic_version'             => '43.1-source.2',
       'expansion_fields'             => [ /* the 10 added fields */ ],
   ],
   ```

   Keep the singular `source_release` key working (read it from the array) so nothing
   currently referencing it breaks.

2. **Immutable raw import** of the expanded CSV and workbook into new
   `raw.drg_cp_terminology_*` tables, following the existing `raw.drg_cp_*` idiom.

3. **Candidate terminology tables** — new, and *structurally incapable* of being confused
   with approved bindings:

   ```
   care_pathways.terminology_candidates
     terminology_candidate_id BIGSERIAL PK
     candidate_uuid           UUID UNIQUE
     catalog_release_id       BIGINT  -- FK care_pathways.catalog_releases
     pathway_version_id       BIGINT  -- FK care_pathways.versions
     code_system              VARCHAR(40)  CHECK IN ('LOINC','CPT','SNOMED CT','ICD-10-CM')
                                           -- the workbook emits 'SNOMED CT' with a space;
                                           -- either match it or normalise on import
     code                     VARCHAR(64)
     display                  TEXT
     rank                     INT
     condition_context        TEXT
     mapping_basis            TEXT
     validation_status        TEXT     -- reproduced verbatim from the workbook
     candidate_digest         CHAR(64) -- sha256 over the normalised record
     UNIQUE (catalog_release_id, pathway_version_id, code_system, code)
   ```

   Append-only trigger, same pattern as `care_pathways.evidence_claims`.

4. **A reconciliation report command** — `care-pathways:reconcile-expanded-release` —
   emitting, as machine-readable evidence:
   - 250/250 row reconciliation of the 49 original CSV fields against the raw baseline;
   - 250/250 row reconciliation of the 59 original `Verified_Pathways` fields against the
     imported verification package;
   - the **1,972 expected CSV↔workbook cell differences**, broken down by field, with the
     seven all-250 fields and the three 74-pathway fields enumerated separately;
   - crosswalk totals: LOINC 7,285 / CPT 315 / SNOMED CT 648 / ICD-10-CM 2,340; unique
     codes 871 / 195 / 584 / 2,082; pathways-with-candidates 250 / 41 / 107 / 67.

5. **A disposition record** in `care_pathways.catalog_release_controls` for each control,
   plus an explicit control asserting `clinical_activation_occurred = false`.

**Tests**

- `tests/Feature/CarePathways/ExpandedReleaseSchemaTest.php` — schema + trigger shape.
- `tests/Feature/CarePathways/ExpandedReleaseAdoptionTest.php` — reproducible import;
  re-running the command is idempotent; the prior release row is untouched.
- `tests/Feature/CarePathways/TerminologyCandidateIsolationTest.php` — assert that **no**
  application code path can write a `terminology_binding_members` row from a
  `terminology_candidates` row without a review record. Implement as a static-analysis
  style test over the service layer plus a DB-level trigger test.

**Exit gate (Gate 1)**

- Import is reproducible and byte-stable.
- All 250 rows reconciled; all **10,588** crosswalk rows traceable to a candidate row
  (7,285 + 2,340 + 648 + 315 = 10,588; the `10,589 × 12` figure in the research report is
  the header-inclusive sheet extent).
- Zero mutations to the earlier release's rows (assert via `content_digest` equality).
- A named governance owner is recorded as accepting the derivative identity.

**Guardrails.** Do not update the existing `source_release` digests in place. Do not
delete or rewrite `raw.drg_cp_*`. Do not mark any version `approved` or `active`.

---

### W1 — Harden identity and the OCEL projection

> Maps to Phase 1. This is the highest-leverage workstream; everything downstream depends on it.

**Objective.** Make the OCEL projection provably reproducible, correction-aware, and
coverage-measurable.

**Read first**

- `app/Domain/Ocel/OcelProjector.php` (all 509 lines — especially `flush()` and `reconcile()`)
- `app/Domain/Ocel/EmissionMap.php`
- `app/Domain/Ocel/OcelCatalog.php`
- `app/Domain/Arena/FlowReviewService.php::sourceSignature()`
- `app/Jobs/RefreshOcelLog.php`
- `tests/Feature/Ocel/OcelBarrierProjectionTest.php`, `tests/Feature/Ocel/OcelAncillaryProjectionTest.php`
- `tests/Unit/Ocel/EmissionMapTest.php`

**Deliverables**

1. **Bounded content digests, replacing `sha1(count | max(event_time))`.**

   Today `FlowReviewService::sourceSignature()` is:

   ```php
   $row = DB::table('ocel.events')->selectRaw('count(*) as c, max(event_time) as m')->first();
   return sha1((string)((int)($row->c ?? 0)).'|'.(string)($row->m ?? ''));
   ```

   Two materially different source states produce the same signature whenever a row is
   updated in place. Replace with a windowed, ordered, content-bearing digest:

   ```
   digest = sha256( concat_ordered_by(event_id) [
       event_id, activity, event_time(µs, UTC), source_system, source_ref,
       source_record_version, canonical_json(attrs),
       sorted(event_object: object_id|qualifier)
   ] )
   ```

   Persist as `ocel.projection_runs`:

   ```
   ocel.projection_runs
     projection_run_id   BIGSERIAL PK
     run_uuid            UUID UNIQUE
     window_start        TIMESTAMPTZ NOT NULL
     window_end          TIMESTAMPTZ NOT NULL
     source_system       VARCHAR(120) NULL   -- NULL = all sources
     projector_version   VARCHAR(40)  NOT NULL
     projector_config    JSONB        NOT NULL
     source_row_count    BIGINT
     projected_event_count BIGINT
     content_digest      CHAR(64)     NOT NULL
     started_at, completed_at TIMESTAMPTZ
     status              TEXT CHECK IN ('running','succeeded','failed','superseded')
     supersedes_run_id   BIGINT NULL
     warnings            JSONB
   ```

2. **Fix `reconcile()`.** It must compare like with like: windowed source rows against
   **windowed** projected events for the same `source_system`, using the same
   `[since, until)` boundary semantics. Add the three missing sources —
   `prod.home_episodes`, `prod.home_visits`, and `prod.home_escalations` — to the
   reconciled list (currently six of the nine emitted `source_system` values are reconciled).

3. **Timestamp roles.** Add to `ocel.events`:

   ```
   occurred_at            TIMESTAMPTZ  -- clinical/occurrence time (rename semantics of event_time)
   recorded_at            TIMESTAMPTZ
   ingested_at            TIMESTAMPTZ
   source_updated_at      TIMESTAMPTZ
   source_timezone        VARCHAR(64)
   timestamp_role         VARCHAR(40)  -- e.g. order_authored, specimen_collected, result_final
   timestamp_precision    VARCHAR(16)  -- second|minute|hour|day
   source_record_version  VARCHAR(64)
   lifecycle_status       VARCHAR(24)  -- active|corrected|canceled|entered_in_error
   projection_run_id      BIGINT
   ```

   Keep `event_time` populated for backward compatibility with `arena/` and
   `OcelJsonExporter`; make it a generated/mirrored column of `occurred_at` rather than a
   second source of truth.

4. **Correction and retraction reconciliation.** A source update must produce an updated
   projection *and* a superseding `projection_run`, never a silent overwrite of attributes
   that a later narrow window did not observe. `absorb()` already exists
   (`OcelProjector.php:373`) as the in-memory accumulator that merges object attributes —
   **harden the existing `absorb()`/`flush()` upsert path** so a narrow window cannot regress
   attribute state that a wider earlier window established.

5. **Qualified relationship completion.** Backfill the qualifiers the model requires and
   the current projection lacks — in particular a patient relationship for the Transport
   class (11,308 events, 0% patient link) and a workflow/resource object for Flow core
   (4,430 events, 0% operational-object link). Where the source genuinely cannot supply
   the link, emit an explicit `coverage_gap` record rather than an unqualified event.

6. **Barrier lifecycle.** `barrier_resolved` is already declared (`OcelCatalog.php:147`) and
   emitted by `EmissionMap.php` whenever `resolved_at` is non-null — but none of the 2,623
   projected barrier events carry it, so blocked duration is not computable. Add
   `barrier_canceled`, and make `barrier_resolved` reliably populated at the source.

7. **Orphan quarantine.** New table `ocel.quarantined_events` with the rejection reason;
   an event that fails its source class's required-relationship contract goes here, not
   into `ocel.events`.

8. **Coverage and freshness service** — `app/Domain/Ocel/OcelCoverageService.php`:

   ```php
   public function coverageFor(string $evidenceFamily, CarbonInterface $from, CarbonInterface $to, ?string $scope = null): CoverageResult;
   ```

   Evidence families at minimum: `lab_order`, `specimen`, `lab_result`, `imaging_order`,
   `imaging_result`, `medication_request`, `medication_administration`, `procedure_case`,
   `consult_task`, `transport_job`, `barrier`, `location_movement`, `staffing`.

9. **Replay idempotency.** `ocel:project` run twice over the same window must produce an
   identical `content_digest` and zero row churn. The command's established options are
   `--since/--until/--days/--reconcile/--quantities-only`; extend that idiom rather than
   inventing `--window`/`--replay`.

**Tests**

- `tests/Feature/Ocel/OcelProjectionRunSchemaTest.php`
- `tests/Feature/Ocel/OcelProjectionDigestTest.php` — an in-place source row update
  **changes** the digest (this test fails against today's `sourceSignature`).
- `tests/Feature/Ocel/OcelReplayIdempotencyTest.php`
- `tests/Feature/Ocel/OcelCorrectionAndRetractionTest.php` — corrected, canceled, and
  late-arriving fixtures.
- `tests/Feature/Ocel/OcelNarrowWindowAttributeProtectionTest.php`
- `tests/Feature/Ocel/OcelOrphanQuarantineTest.php`
- `tests/Feature/Ocel/OcelCoverageServiceTest.php`
- `tests/Unit/Ocel/TimestampRoleTest.php`

**Exit gate (Gate 7 precondition)**

- Correction and late-arrival fixtures pass.
- Source-window reconciliation is exact for all nine source classes.
- Patient / encounter / work-object coverage is measurable and reported per source class.
- Barrier lifecycle includes resolution and cancellation.
- `ocel:project` replay is byte-identical.
- **No pathway status may depend on a source family whose coverage is unknown** — assert
  this as a test against the coverage service, not as a convention.

**Guardrails.** Do not break `arena/` — `arena/app/ocel_loader.py` and the sidecar tests
read the existing shape. Run `cd arena && python -m pytest tests -q` after every change.
Do not add FKs to `ocel.*` (the schema is deliberately FK-free and drop-safe).

---

### W2 — The typed pathway definition schema and modeling workbench

> Maps to Phase 2. Largest workstream by surface area.

**Objective.** Make a pathway *computable*: applicability, graph, timing, exceptions,
resources, bindings, and measures — all typed, versioned, digested, and provenance-bearing.

**Read first**

- `database/migrations/2026_07_21_000900_create_care_pathway_catalog.php` — especially
  `protect_version_content()` (line ~649)
- `database/migrations/2026_07_22_001400_create_patient_pathway_instance_history.php` —
  `protect_stage_definition_content()` (line ~59)
- `app/Models/CarePathways/*` (25 models; note the `review_state` + `executable` pattern on
  `ActivityDefinition`, enforced by `CHECK (NOT executable OR review_state = 'approved')`)
- `app/Services/CarePathways/CatalogGovernanceReadService.php`
- `routes/care-pathways.php`

**Deliverables — schema**

Add to the `care_pathways` schema, following the existing raw-DDL idiom and append-only
trigger pattern:

```
care_pathways.definition_builds          -- a normalisation pass over a version
care_pathways.applicability_rules        -- typed inclusion/exclusion predicates
care_pathways.definition_edges           -- explicit graph edges between stages/activities
care_pathways.branch_groups              -- sequence|parallel|inclusive_or|exclusive_or|
                                         --   optional|conditional|repeat_until|loop_bounded|
                                         --   interrupt|substitution|terminal
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

Required column detail for the three that carry the most measurement weight:

```
care_pathways.temporal_constraints
  temporal_constraint_id, constraint_uuid, pathway_version_id,
  stable_key, source_activity_key, target_activity_key,
  anchor_event_key, anchor_selection_rule
      CHECK IN ('first_valid','last_valid','closest_to_anchor','all',
                'min_result','max_result','final_or_corrected','specific_specimen',
                'reviewer_selected'),
  lower_bound NUMERIC, upper_bound NUMERIC, unit VARCHAR(16),
  lower_inclusive BOOL, upper_inclusive BOOL,
  clock_type CHECK IN ('elapsed','calendar','business','staffed'),
  pause_conditions JSONB, timezone_policy JSONB,
  early_interpretation CHECK IN ('acceptable','deficient','unsafe'),
  missing_anchor_policy CHECK IN ('not_applicable','data_unavailable','ambiguous'),
  active_alert_threshold NUMERIC NULL,
  retrospective_finalisation_policy JSONB,
  review_state, content_digest, provenance JSONB

care_pathways.observation_bindings
  observation_binding_id, binding_uuid, pathway_version_id, activity_definition_id,
  evidence_state CHECK IN ('ordered','accepted','started','performed',
                           'resulted_preliminary','resulted_final','verified','acknowledged'),
  ocel_activity VARCHAR(120),          -- must exist in ocel.activities
  ocel_object_type VARCHAR(80),        -- must exist in ocel.object_types
  timestamp_role VARCHAR(40) NOT NULL,
  terminology_binding_set_id BIGINT NULL,
  source_field_contract JSONB NOT NULL, -- FHIR resource/element or local column
  status_filter JSONB,                  -- accepted lifecycle_status values
  correction_behaviour CHECK IN ('supersede','ignore','flag'),
  required_coverage_families TEXT[],
  review_state, content_digest, provenance JSONB

care_pathways.measure_definitions
  measure_definition_id, measure_uuid, pathway_version_id,
  measure_key, semantic_version, intent,
  measure_type CHECK IN ('process_completion','process_timeliness','branch_appropriateness',
                         'outcome','complication','balancing','utilisation',
                         'patient_experience','readmission','safety_screen','data_coverage'),
  numerator JSONB, denominator JSONB,
  denominator_exclusions JSONB, denominator_exceptions JSONB,
  observation_period JSONB, stratifiers JSONB, risk_adjustment JSONB,
  data_elements JSONB, missing_data_policy JSONB,
  aggregation_level VARCHAR(32), minimum_cohort INT NOT NULL,
  interpretation TEXT, owner_ref TEXT,
  review_state, retired_at, supersedes_measure_id,
  content_digest, provenance JSONB
```

**Deliverables — engine**

1. `app/Domain/CarePathways/Definition/DefinitionValidator.php` implementing the twelve
   static checks (§15.1 of the specification). It must be callable from a command and from
   an approval-gate check, and must **block approval** on failure.
2. `app/Domain/CarePathways/Definition/DefinitionDigest.php` — canonical serialisation and
   SHA-256 over the whole package, so a version's digest is a function of its content.
3. `app/Domain/CarePathways/Terminology/BindingPromotionService.php` — the four-step
   pipeline: coding review → clinical applicability review → local source mapping →
   binding test → approved member. Each step writes an immutable review record. There is
   **no** method that skips a step.
4. `app/Services/CarePathways/DefinitionAuthoringService.php` — write-side for the
   workbench, enforcing that an approved version is immutable and that any change forks a
   new version.
5. Command `care-pathways:validate-definition {version}` and
   `care-pathways:digest-definition {version}`.

**Deliverables — workbench UI**

New Inertia page tree under `resources/js/Pages/CarePathways/` (there is currently only
`Demo.tsx`) plus `resources/js/features/carePathways/{api,hooks,schema}.ts`. Screens:
applicability editor; graph/branch editor; temporal-constraint editor; exception and
resource editor; terminology candidate review queue; local binding editor; provenance
view; measure editor; validation results; version/digest and approval workflow.

Gate the whole tree behind a new `care_pathways.workbench_enabled` flag, default `false`.
Obey the Token Canon; run `bash scripts/check-ui-canon.sh` before you commit.

**Tests**

- `tests/Feature/CarePathways/DefinitionSchemaTest.php`
- `tests/Feature/CarePathways/DefinitionValidatorTest.php` — one failing case per check.
- `tests/Feature/CarePathways/DefinitionImmutabilityTest.php` — approved version cannot be
  mutated; edit forks a version.
- `tests/Feature/CarePathways/BindingPromotionTest.php` — every skipped step throws.
- `tests/Unit/CarePathways/DefinitionDigestTest.php` — digest stability and sensitivity.
- `tests/js/carePathways/graphEditor.test.tsx`, `bindingQueue.test.tsx`

**Exit gate (Gates 2–4)**

- One pathway can be modelled end to end, from narrative section to executable fixtures.
- Every rule traces to a source claim and a named reviewer.
- Invalid graphs cannot be approved (test, not policy).
- Candidate codes cannot be activated as approved bindings (test, not policy).
- Synthetic fixtures are executable.

---

### W3 — Patient assignment, version pinning, and the deterministic evaluator

> Maps to Phase 3 + the first half of Phase 4.

**Objective.** Turn a confirmed patient instance plus a pinned definition into a
reproducible measurement run.

**Read first**

- `app/Services/Patient/Pathway/PatientPathwayInstanceService.php`
- `app/Services/Patient/Pathway/PatientPathwaySourceReconciliationService.php`
- `database/migrations/2026_07_22_001400_create_patient_pathway_instance_history.php` —
  especially `patient_experience.enforce_pathway_instance_definition_membership()`
- `arena/app/pathways.py` — the behaviour you are replacing

**Deliverables**

1. **New schema `pathway_measurement`** (new Postgres schema). Document it in `AGENTS.md`
   alongside the other schemas — **do not** change the literal
   `'search_path' => 'prod,public'` in `config/database.php`; reference the new tables
   schema-qualified, as `care_pathways` and `patient_experience` already do:

   ```
   pathway_measurement.instances
     instance_id, instance_uuid,
     patient_ref_hash, encounter_ref_hash, episode_ref_hash,
     pathway_definition_id, pathway_version_id, definition_digest CHAR(64) NOT NULL,
     assignment_state CHECK IN ('candidate','pending_confirmation','confirmed','rejected',
                                'superseded','completed','canceled','entered_in_error',
                                'unable_to_determine'),
     detected_at, confirmed_at, completed_at,
     concurrent_instance_ids BIGINT[], precedence_rank INT,
     ocel_object_id VARCHAR(160)      -- the PathwayInstance object in ocel.objects

   pathway_measurement.instance_assignments   -- append-only state transitions
     assignment_id, instance_id, from_state, to_state,
     actor_user_id NULL, actor_ref NULL, automated_rule_key NULL,  -- exactly one
     reason TEXT, evidence JSONB, occurred_at, event_digest

   pathway_measurement.runs
     run_id, run_uuid, instance_id,
     definition_version_id, definition_digest,
     evaluator_version, evaluator_digest,
     binding_set_version, binding_set_digest,
     source_cutoff TIMESTAMPTZ NOT NULL,
     source_manifest_digest CHAR(64) NOT NULL,
     identity_rules_version VARCHAR(40) NOT NULL,
     run_purpose CHECK IN ('prospective_monitoring','retrospective_final','correction_replay',
                           'model_validation','shadow_comparison','gold_set_comparison',
                           'cohort_aggregation'),
     status, started_at, completed_at,
     supersedes_run_id, superseded_by_run_id,
     warnings JSONB, coverage_summary JSONB, failure_evidence JSONB,
     UNIQUE (instance_id, definition_digest, evaluator_digest, binding_set_digest,
             source_cutoff, identity_rules_version, run_purpose)

   pathway_measurement.step_measurements
     step_measurement_id, run_id, activity_definition_id,
     applicability_state CHECK IN ('required','optional','not_applicable','unknown'),
     measurement_state CHECK IN ('met_on_time','met_early','met_late','missing','extra',
                                 'out_of_sequence','repeated_or_rework','exception_approved',
                                 'contraindicated','not_applicable','optional_observed',
                                 'optional_not_observed','data_unavailable','source_late',
                                 'source_corrected','canceled_or_entered_in_error','ambiguous'),
     expected_anchor_event_id, expected_window JSONB,
     selected_event_id VARCHAR(160), selected_timestamp_role VARCHAR(40),
     actual_time TIMESTAMPTZ, signed_variance_seconds BIGINT,
     exception_id BIGINT NULL, data_coverage JSONB, match_confidence NUMERIC,
     terminology_binding_set_id BIGINT NULL, source_lineage JSONB,
     explanation_code VARCHAR(64) NOT NULL,
     human_override JSONB NULL

   pathway_measurement.constraint_measurements
   pathway_measurement.event_match_candidates    -- every candidate considered
   pathway_measurement.selected_event_matches
   pathway_measurement.coverage_results
   pathway_measurement.deviations
   ```

   All of these are append-only. Add the `reject_append_only_mutation()` trigger pattern
   already used in `care_pathways`.

2. **`app/Domain/CarePathways/Evaluator/DeterministicEvaluator.php`.** Pure, injectable,
   version-stamped. Given `(instance, definition version, binding set, source cutoff)` it
   returns a complete run result. It must:
   - resolve applicability per step and persist every predicate result;
   - find **all** candidate matches and persist them before selecting;
   - apply the declared selection rule;
   - compute signed variance with the declared clock;
   - assign one of the 17 measurement states;
   - compute coverage per required evidence family via `OcelCoverageService`;
   - never emit `missing` when coverage is below the definition's threshold — emit
     `data_unavailable`.

3. **`EvaluatorVersion`** constant + digest, so a code change to the evaluator invalidates
   reproducibility keys rather than silently changing results.

4. **Assignment service** implementing the nine confirmation steps, including creation of
   the `PathwayInstance` OCEL object and the `supports_assignment` qualified links.

5. **Retire the hard-coded rules.** Once one pathway is fully definition-driven, add a
   parity test that runs `arena/app/pathways.py::evaluate_sepsis` and the deterministic
   evaluator over the same fixture and asserts they agree — then mark the Python function
   deprecated. Do **not** delete it until W5 replaces the Arena conformance route.

**Tests**

- `tests/Feature/PathwayMeasurement/PathwayMeasurementSchemaTest.php`
- `tests/Feature/PathwayMeasurement/VersionPinningTest.php` — changing the definition after
  pinning does not change a historical run.
- `tests/Feature/PathwayMeasurement/ReproducibilityKeyTest.php` — same key ⇒ identical output.
- `tests/Feature/PathwayMeasurement/StepStateMatrixTest.php` — a fixture per state, all 17.
- `tests/Feature/PathwayMeasurement/CoverageGatedMissingTest.php` — low coverage yields
  `data_unavailable`, never `missing`.
- `tests/Feature/PathwayMeasurement/EventSelectionRuleTest.php` — one case per rule in
  Table 10.1 of the specification.
- `tests/Unit/CarePathways/SignedVarianceTest.php` — early, late, on-time, boundary
  inclusivity, DST crossing, staffed-clock pause.
- `tests/Feature/Arena/SepsisEvaluatorParityTest.php`

**Exit gate (Gates 5–6)**

- Definition and measure approval recorded for at least the pilot set.
- Local binding completeness proven for the pilot set.
- Every summary drills to immutable evidence.
- Replay is reproducible.
- **No live alerting** — assert that no code path emits a notification from a run.

**Pilot selection.** Choose three to five pathways on *observability*, not DRG rank:
one high-specificity medical pathway with reliable lab/medication evidence; one surgical
pathway with a complete procedure/case/resource lifecycle; one with meaningful
consult/queue measurement; one obstetric or neonatal pathway to exercise dyad objects
(worklist ranks 1, 2, 5, 11, 27, 87, 96, 110 are the obstetric/neonatal candidates);
optionally sepsis (rank 3) for technical continuity — **treating the existing seeded
`ClinicalPathwaySeeder` events as demonstration data, not clinical validation.**

---

### W4 — Correction, supersession, and the reviewer surface

> Completes Phase 4.

**Objective.** Make late and corrected data safe, and make results reviewable.

**Deliverables**

1. **Supersession engine.** A late-arriving or corrected source record enqueues a
   `correction_replay` run that links `supersedes_run_id`. The prior run stays queryable
   and is marked `superseded`. Reports state which run they used.
2. **`pathway_measurement.review_cases` / `review_decisions` / `factor_evidence` /
   `corrective_actions`**, all append-only.
3. **Evidence-first reviewer API** — a new `routes/pathway-measurement.php` registered in
   `RouteServiceProvider`, prefix `api/pathway-measurement/v1`, name prefix
   `pathway-measurement.`, gated by a new `EnsurePathwayMeasurementEnabled` middleware and
   a `can:reviewPathwayMeasurement` ability. Every endpoint returns evidence, not verdicts:

   ```
   GET  /runs/{run}                       run header + coverage summary + supersession chain
   GET  /runs/{run}/steps                 step ledger with explanation codes
   GET  /runs/{run}/steps/{step}/candidates   every candidate event and why it lost
   GET  /instances/{instance}/assignments assignment state history with evidence
   GET  /measures/{measure}/results       aggregate with cohort suppression applied
   POST /review-cases                     open a review
   POST /review-cases/{case}/decisions    append an adjudication
   ```

4. **Reviewer UI** under `resources/js/Pages/CarePathways/Measurement/`. Every summary
   number must be drillable to the step ledger and then to the event evidence. Display
   coverage next to every rate. **Do not render a single compliance percentage.**

5. **Cohort suppression** as a service, not a view concern —
   `app/Domain/CarePathways/Measurement/CohortSuppressionService.php`, applied at the API
   boundary so no client can bypass it.

**Tests**

- `tests/Feature/PathwayMeasurement/SupersessionTest.php`
- `tests/Feature/PathwayMeasurement/LateArrivalReplayTest.php`
- `tests/Feature/PathwayMeasurement/ReviewerApiEvidenceTest.php` — every endpoint returns
  lineage.
- `tests/Feature/PathwayMeasurement/CohortSuppressionTest.php`
- `tests/Feature/Security/PathwayMeasurementBoundaryTest.php` — unauthenticated and
  under-privileged access is refused; flags off ⇒ 404.
- `tests/js/carePathways/measurementDrilldown.test.tsx`

**Exit gate (Gate 8)**

- Late data creates a superseding run; the original remains.
- `data_unavailable` is never counted as missed care in any aggregate.
- Clinical reviewers agree results are interpretable (record the review).
- No punitive use is possible: assert that no endpoint groups by individual clinician.

---

### W5 — Bottleneck, resource, and safety evidence

> Maps to Phase 5.

**Objective.** Support operational conclusions with operational evidence — and keep
safety screening separate from error adjudication.

**Deliverables**

1. **Queue lifecycle events** in the OCEL emission map: `service_requested`,
   `service_accepted`, `service_scheduled`, `patient_ready`, `resource_ready`,
   `queue_entered`, `service_started`, `service_paused`, `service_resumed`,
   `service_completed`, `service_canceled`. Extend `OcelCatalog::activities()` and bump
   `OcelCatalog::VERSION`.
2. **Resource and capacity objects** — `Resource/Device`, `PractitionerRole/CareTeam`,
   `Appointment/ScheduleSlot`, plus a capacity attribute history via `ocel.object_changes`.
3. **`pathway_measurement.wait_segments`** with the three derived intervals
   (`queue_wait`, `processing_time`, `total_flow_time`) and the twelve delay classes.
4. **`app/Domain/CarePathways/Analysis/BottleneckService.php`** enforcing the six
   preconditions (stable queue boundary, reliable timestamps, sufficient volume, repeated
   high waits, baseline comparison, missingness sensitivity). If a precondition fails it
   returns `insufficient_evidence`, not a bottleneck.
5. **`app/Domain/CarePathways/Analysis/ResourceEvidenceService.php`** computing
   `effective_capacity` and emitting one of the eight labels. `insufficient_evidence` must
   be a first-class, frequently-returned result.
6. **Safety screens** — `pathway_measurement.safety_screens` with the eleven detection
   categories, feeding `review_cases`. Allowed conclusions are exactly the eleven in
   Table 12.1 of the specification; enforce with a CHECK constraint.
7. **Offline object-centric alignment** in the `arena/` sidecar only, behind
   `EnsureArenaAiEnabled`-style gating, never in an online path. Respect
   `arena/CLEAN-ROOM.md` and `scripts/check-clean-room.sh`.
8. **Replace the Arena conformance route's data source** with definition-driven results,
   and remove the deprecated `arena/app/pathways.py` rule functions.

**Tests**

- `tests/Feature/PathwayMeasurement/WaitSegmentDecompositionTest.php` — wait vs processing
  separation.
- `tests/Feature/PathwayMeasurement/ResourceEvidenceGatingTest.php` — long waits alone
  yield `insufficient_evidence`.
- `tests/Feature/PathwayMeasurement/SafetyScreenAdjudicationTest.php` — a screen is never
  reported as an error without a decision record.
- `tests/Feature/Ocel/QueueLifecycleProjectionTest.php`
- `arena/tests/test_definition_driven_conformance.py`

**Exit gate**

- Wait and processing time are separable for every queue-capable activity.
- Resource insufficiency requires capacity evidence.
- Safety screen is structurally distinct from error adjudication.
- Cause remains a reviewed hypothesis until an adjudicator supports it.
- Corrective actions have owners and effectiveness measures.

---

### W6 — Scale by readiness, and (separately) prospective alerting

> Maps to Phase 6.

**Objective.** Industrialise the factory and, only if separately authorised, add
prospective alerting.

**Deliverables**

1. **Pathway factory tooling.** A command set that drives the 17-step workflow:
   `care-pathways:factory:intake`, `:decompose`, `:validate`, `:fixture`, `:shadow`,
   `:promote`. Each step writes an immutable record and refuses to run out of order.
2. **Lane-aware scaling controls.** The catalog carries `release_disposition` already —
   96 signoff, 148 specialist review, 6 redesign. Enforce that a version cannot enter the
   factory beyond decomposition unless its lane's precondition is recorded as resolved.
3. **Non-protocol classification** for the six redesign candidates (worklist ranks 92,
   140, 162, 166, 169, 221). These get a registry or measure-only definition type, not a
   condition-specific protocol shape. The enum value already exists in the
   `care_pathways.definitions.lifecycle_state` CHECK constraint — **wire it up end to end**
   (read service, governance API, factory, UI) rather than adding it.
4. **Monitoring.** Definition aging, terminology release drift, binding validity, gold-set
   performance decay, coverage drift by service line. Schedule via `bootstrap/app.php`
   alongside `RefreshOcelLog`.
5. **Prospective alerting — only behind its own gate.** New flag
   `care_pathways.prospective_alerts_enabled`, default `false`, with an explicit
   authorisation record required in addition to the flag. Requirements before it may be
   turned on anywhere: proven source freshness, stable real-time identity, an active
   confirmed pathway, an unambiguous anchor, known remaining time, suppression and
   acknowledgment controls, exception awareness, human-factors review, and a proof that
   alert latency is safe.

**Tests**

- `tests/Feature/CarePathways/FactoryStepOrderingTest.php`
- `tests/Feature/CarePathways/LaneGatingTest.php`
- `tests/Feature/CarePathways/NonProtocolClassificationTest.php`
- `tests/Feature/CarePathways/ProspectiveAlertGateTest.php` — flag alone is insufficient.

**Exit gate**

- Each lane transition is a governance decision backed by evidence.
- Scaling never outruns clinical-owner capacity (the factory refuses unowned versions).
- Coverage and gold-set performance are re-proven per service line, not assumed to transfer.

---

## 5. Decisions you must not make yourself

If a workstream needs any of these, stop and ask. Do not encode a default.

1. Who owns clinical definition approval?
2. Who approves terminology bindings, separately from clinical content?
3. What qualifies a patient for each pathway, and when is confirmation required?
4. How are concurrent pathways prioritised or combined?
5. What clock semantics apply to each class of timing rule?
6. Which exceptions remove an obligation from a denominator?
7. What local sources prove each expected observation?
8. What data-coverage threshold permits a "missing" conclusion?
9. What gold-set performance is required for staff-facing use?
10. Which measures are descriptive, operational, quality-improvement, or safety measures?
11. What evidence is required before calling a delay undue?
12. What evidence is required before suggesting resource insufficiency?
13. Who may adjudicate a safety screen and label preventability?
14. What cohort sizes and stratifiers are allowed?
15. Which outputs can reach Arena, Patient Flow 4D, Eddy, and Hummingbird?
16. What is the update policy for LOINC, SNOMED CT, ICD-10-CM, CPT, and local terminologies?
17. What is the definition review and retirement cadence?
18. What are the performance and latency requirements?
19. What is the rollback or disable process for a faulty definition or binding?
20. How will corrective-action effectiveness be measured?

---

## 6. Cross-cutting standards

### 6.1 Digests

Every digested entity uses SHA-256 over a **canonical JSON serialisation**: keys sorted,
no insignificant whitespace, timestamps in UTC ISO-8601 with microsecond precision,
numbers in shortest round-trip form, nulls omitted. Put this in one place —
`app/Support/CanonicalJson.php` — and use it everywhere. Do not hand-roll a second
serialisation.

### 6.2 Append-only

Reuse the existing `care_pathways.reject_append_only_mutation()` trigger pattern for every
new evidence table. Add a schema test asserting the trigger exists; a table that should be
append-only and is not will otherwise be discovered only after it has been corrupted.

### 6.3 Feature flags

All new flags go in `config/care-pathways.php` following the existing idiom and default
`false`:

```
workbench_enabled
measurement_enabled
measurement_api_enabled
reviewer_ui_enabled
bottleneck_analysis_enabled
resource_analysis_enabled
safety_screen_enabled
prospective_alerts_enabled
```

### 6.4 Explanation codes

One enum, one file: `app/Domain/CarePathways/Measurement/ExplanationCode.php`. Stable
string values. Never free text. Group them by family (`ANCHOR_*`, `BINDING_*`, `COVERAGE_*`,
`SOURCE_*`, `BRANCH_*`, `EXCEPTION_*`) so the reviewer UI can facet on prefix.

### 6.5 Testing

- Every new schema gets a `*SchemaTest.php` under `tests/Feature/**` so it lands in the
  `migration` suite.
- Evaluator behaviour gets unit tests under `tests/Unit/CarePathways/`.
- Anything touching `arena/` gets a matching `arena/tests/test_*.py`.
- Run before every commit:
  ```bash
  ./vendor/bin/pint --test
  php artisan test --compact
  bash scripts/check-ui-canon.sh
  bash scripts/check-clean-room.sh
  cd arena && python -m pytest tests -q
  ```

### 6.6 Documentation

Each workstream appends an evidence summary to
`docs/architecture/OCEL-CARE-PATHWAY-PROGRAM-EVIDENCE.md`: what was built, which tests
prove it, which exit-gate criteria are satisfied, and which remain open.

---

## 7. Anti-goals — do not do these

- Do not create a table, column, view, API field, or UI element named anything like
  `compliance_percent`, `pathway_score`, or `adherence_rate` without the qualifying
  dimensions attached.
- Do not add a `CarePathway` model to `ocel.*` or FKs between the schemas. The link is by
  deterministic ID and qualified relationship, by design.
- Do not "simplify" the 17 measurement states to pass/fail, or the 9 assignment states to
  a boolean.
- Do not let a template or shared narrative group create one executable rule shared by
  multiple pathway versions. Duplicate narrative text across up to 120 pathways is a known
  property of this dataset; each version adopts, overrides, reviews, and cites its own copy.
- Do not infer ICD-10-PCS. 74 worklist rows explicitly say "do not infer PCS."
- Do not use the coded DRG as a real-time assignment key.
- Do not put object-centric alignment in an online or alerting path.
- Do not modify anything listed in `.claude/rules/auth-system.md`.
- Do not recolour or gate the Patient Flow 4D Navigator — it is a dark-only, full-motion
  wall instrument by ruling.
- Do not promote the cockpit prototype's tokens (IBM Plex, OKLCH, cyan, a fifth green)
  into the codebase.

---

## 8. Definition of done for the program

The platform is done when a single pathway can travel the whole path and prove it:

1. Its source release row and claims are immutable and traceable.
2. Its definition is typed, versioned, digested, provenance-bearing, and approved.
3. Its terminology candidates have been reviewed and promoted through all four steps.
4. Its expected observations are bound to local data with explicit timestamp roles.
5. A patient instance can be confirmed, pinned, and evaluated.
6. The run is reproducible from its key alone.
7. Every step measurement carries state, variance, coverage, confidence, lineage, and an
   explanation code.
8. Late and corrected data produce a superseding run, and the original survives.
9. A reviewer can drill from any aggregate to the event evidence and see the rejected
   candidates.
10. Bottleneck, resource, and safety conclusions each require their own evidence, and
    return `insufficient_evidence` when they do not have it.

Then, and only then, repeat 249 times — by readiness lane, at the pace of clinical review.

---

## 9. Opening instruction to paste

> Read §0–§4 of `docs/architecture/OCEL-CARE-PATHWAY-IMPLEMENTATION-PROMPT.md`, then read
> every file listed in §1. Confirm the §1.3 facts against the current repo and report any
> that have changed. Then produce an implementation plan for **W0 only**, listing the exact
> files you will create or modify, the migration filenames, and the test classes you will
> add. Do not write code until I approve the W0 plan.
