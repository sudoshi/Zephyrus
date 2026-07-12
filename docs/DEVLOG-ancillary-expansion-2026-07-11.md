# Ancillary Expansion Implementation Devlog

This devlog is the evidence companion to `ANCILLARY-EXPANSION-RADIOLOGY-PATHOLOGY-PHARMACY-PLAN-2026-07-11.md`. A task is marked complete in the plan only after its work and acceptance checklists have direct evidence here or in a linked artifact.

## 2026-07-11 — P0-1 shared schema and database invariants

### Outcome

Implemented the PHI-minimized shared ancillary spine in `database/migrations/2026_07_11_000400_create_ancillary_spine_tables.php`:

- `prod.ancillary_orders`
- `prod.ancillary_milestones`
- `prod.ancillary_sla_definitions`
- `prod.ancillary_breaches`
- `hosp_ref.ancillary_milestone_types`
- `hosp_ref.ancillary_barrier_reasons`
- `prod.ancillary_current_assertions`

The migration includes explicit vocabularies and cross-field checks, source-scoped natural keys, partial worklist/open-breach indexes, governed effective-date overlap rejection, exact repository foreign keys, table/column comments, and safe delete behavior. Milestones reject direct update/delete. Only a transaction-local `zephyrus.allow_ancillary_demo_reset=on` setting combined with deletion of an order carrying a non-null `demo_owner` permits a nested cascade. Non-demo orders remain protected even when the setting is present.

The current-assertion view retains every source assertion in the ledger and chooses the lowest `source_rank`, then newest `received_at`, while exposing assertion count and timestamp disagreement for the later configured conflict policy.

### Automated evidence

Command:

```bash
./vendor/bin/pint database/migrations/2026_07_11_000400_create_ancillary_spine_tables.php tests/Feature/Ancillary/AncillarySpineMigrationTest.php
php artisan test tests/Feature/Ancillary/AncillarySpineMigrationTest.php
```

Result: PASS, 12 tests and 41 assertions.

The focused suite proves:

- table, view, constraint, partial-index, and foreign-key presence;
- selection precedence without source-history loss;
- database rejection of milestone update and direct delete;
- guarded owned-demo cascade and rejection of non-demo reset;
- duplicate source-order and assertion-key rejection;
- overlapping SLA range rejection and adjacent-version acceptance;
- one-open-breach uniqueness; and
- empty local down/up rehearsal.

### Disposable production-shaped migration rehearsal

The rehearsal cloned the fully migrated 53 MB `zephyrus_test` schema into the disposable `zephyrus_ancillary_rehearsal` database, removed only the ancillary migration objects/ledger entry, ran the exact migration by path, inspected the resulting catalog, and dropped the disposable database.

```text
Laravel DDL duration: 31.46 ms
End-to-end command duration: 0.13 s
Peak RSS: 71,656 KB
Tables created: 6
Ancillary catalog constraints observed: 124
Ancillary indexes observed: 29
Ancillary orders before/after: 0 / 0
Ancillary milestones before/after: 0 / 0
```

The migration is additive and does not rewrite an existing clinical table. The empty-schema down/up path is automated. Once milestone rows exist, `down()` refuses destructive rollback; application rollback retains facts, and correction proceeds through a forward-repair migration or verified backup restoration as described in section 19 of the plan.

### Activation and limitations

- No production source, route, screen, scheduler, or connector was activated.
- Reference catalogs and initial SLA rows are intentionally deferred to P0-3.
- Application models/DTOs are intentionally deferred to P0-2.
- Commit/PR and deployed-runtime evidence remain program-level release evidence; this task currently exists in the working tree.

## 2026-07-11 — R-5 coherent Radiology demo cohort

### Outcome

Expanded the rolling ancillary scenario from the original five illustrative Radiology orders to a deterministic 16-order operational cohort. Nine ED CT orders carry fixed order-to-exam-end intervals of 35, 45, 57, 80, 108, 140, 182, 210, and 240 minutes, producing an exact continuous median of 108 minutes and IQR of 57–182. The remaining branches cover an inpatient discharge-blocking chest CT with transport, portable inpatient XR without transport, outpatient ultrasound, night/weekend nuclear medicine with final-only degraded evidence, IR linked to a real OR case, a competing-source CT with a corrected report, and a cancelled MRI.

The cohort spans CT, IR, MRI, NM, US, and XR plus emergency, inpatient, and outpatient classes. Every event continues through the governed raw/canonical/projection path. Projection now propagates exact demo ownership into exams, reads, critical-result loops, and scanners. The Radiology refresh additionally creates one owned CT scanner-downtime interval and two owned barriers linked to live encounter orders and any corresponding open breaches.

Clinical context selection is explicit. ED scenarios resolve directly to non-deleted `prod.ed_visits`, carrying the exact pseudonymous patient and `ed_visit_id`; they do not fabricate an inpatient encounter for an ED-only patient. The discharge blocker selects an encounter with a current expected discharge date and remains non-terminal. The IR scenario carries an actual `prod.or_cases.case_id` when procedural context is available. Those context keys are retained in operational metadata and Radiology exam metadata for auditable invariant checks.

Added critical invariants for exact-owner Radiology satellite linkage, ED-visit/patient resolution, current discharge-candidate resolution, and IR case resolution. The warning-level distribution invariant calculates the ED CT continuous quartiles directly from selected milestone assertions and requires the fixed 9-order cohort, 108-minute median, and 57–182-minute IQR. The ancillary invariant surface now contains 13 named checks.

The focused generator test proves categorical coverage, portable and IR branches, scanner/downtime/barrier counts, corrected read lineage, acknowledged critical-result state, exact distribution anchors, same-anchor idempotency, advanced-anchor natural-key rotation, invariant success, and preservation of a foreign non-demo exam. The lightweight shared demo fixture now includes a real ED visit so its critical context invariant is meaningful rather than vacuously skipped.

R-5 supersedes the initial P0-8 phase-zero demo totals: the complete shared ancillary preview is now 26 orders and 126 milestones, of which 16 orders are Radiology.

### Automated evidence

```text
RadiologyDemoGeneratorTest: 1 test, 30 assertions, PASS
R-5-relevant Demo + Ancillary feature/unit regression: 114 tests, 4,913 assertions, PASS
Laravel Pint for R-5 implementation/tests: PASS
git diff --check: PASS
```

The broader demo/ancillary command also exposed an unrelated pre-existing isolation failure in `OperationalDemoDataTest::test_roll_forward_command_requires_explicit_synthetic_guard_and_mode`: its empty-registry branch runs after an operational scenario that leaves 29 service lines, 87 roles, and 4,529 workforce rows visible in the test database, so the command correctly exits success instead of the stale expected failure. The R-5-relevant suite excludes that unrelated test and is green; R-5 did not alter the roll-forward command, staffing seeders, or operational test.

### Activation and limitations

- No RIS, PACS, MPPS, reporting, transport, critical-result, route, scheduler, credential, connector, deployment, or production source was activated.
- The cohort is deterministic operational evidence, not a claim that its 16 samples reproduce a production case mix. Only the PDF-anchored ED CT median/IQR is fixed.
- ED visits are deliberately modeled as ED visit context with no fabricated inpatient encounter. Encounter linkage remains present for inpatient/discharge scenarios.
- The working tree remains unpublished; commit, PR, migration rehearsal on a production-shaped copy, and deployment evidence belong to the later Radiology phase gate.

## 2026-07-11 — P0-3 governed reference and metric catalogs

### Outcome

Added `AncillaryReferenceSeeder` to the normal database seed chain. It owns:

- 60 milestone definitions across Radiology (15), clinical Lab (14), Pathology (9), Blood Bank (6), and Pharmacy (16);
- 17 department-specific barrier reasons mapped into the existing four allowed `prod.barriers` categories;
- 16 effective-dated initial SLA/reference definitions; and
- explicit default source precedence plus executable OCEL process/event mappings for every milestone.

The Pharmacy catalog includes `RX_DISCREPANCY_OPEN` and `RX_DISCREPANCY_RESOLVED`, closing the source plan's prior mismatch between its discrepancy-aging SLA and its milestone list. This correction is now reflected in section 7.4 of the canonical plan.

Reference-only or site-policy-required clock rows remain inactive. Demo local-policy rows are active and explicitly labeled as such. Benchmark/reference labels remain separate from Cockpit/SLA policy thresholds.

Extended `CockpitKpiDefinitionSeeder` with ten Flow-domain aggregate metrics: imaging breaches/oldest unread/scanners down, lab STAT compliance/oldest decision-pending/critical callbacks, and pharmacy verification queue/oldest STAT/sepsis at-risk/stockouts. Ancillary reseeding refreshes descriptive metadata but deliberately preserves tuned target/warning/critical edges and stable metric UUIDs.

During verification, PostgreSQL catalog inspection proved Laravel had not emitted foreign keys for fluent string-code references. P0-1 was repaired with explicit idempotent named foreign keys for order current milestone, milestone assertion type, and both SLA endpoints; the migration test now verifies them.

### Automated evidence

Command:

```bash
./vendor/bin/pint database/factories/Ancillary database/seeders/AncillaryReferenceSeeder.php database/seeders/CockpitKpiDefinitionSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Ancillary
php artisan test tests/Feature/Ancillary/AncillarySpineMigrationTest.php tests/Feature/Ancillary/AncillaryModelsAndContractsTest.php tests/Feature/Ancillary/AncillaryReferenceSeederTest.php
```

Result: PASS, 27 tests and 399 assertions.

Evidence includes exact department counts, same-department SLA endpoint checks, barrier category checks, executable HospitalProcessCatalog resolution for every OCEL process ID, stable UUID/tuned-edge reseeding, FK rejection of an SLA-referenced catalog deletion, and demo-scenario failure when a non-SLA rework code is removed.

### Activation and limitations

- No production feed or Cockpit value provider is activated; this task seeds definitions only.
- Concrete parser-to-code maps land with P0-6 and must join the same catalog-integrity suite.
- Department leaders still must approve site-specific policies before production source activation.

## 2026-07-11 — P0-6 governed integration runtime extension

### Outcome

Completed the ancillary message-family extension on the existing healthcare integration runtime. The implementation deliberately adds no browser or public machine-ingress route and activates no source. Governed callers can use the internal pipeline only after a source is active, PHI-approved, explicitly enabled for ancillary ingress, and bound to named message families, departments, and optional milestone overrides.

### Completed sub-elements

- Generalized the single RTDC replay dependency into `ProjectionDispatcher`, with unique handler keys, exclusive event ownership, dynamic replay allowlists, sanitized unsupported-event errors, and the actual projector key in projection-error records.
- Preserved the existing synthetic connector and RTDC projector through the dispatcher. Existing synthetic integration and enterprise replay/FHIR tests remain green.
- Expanded the shared `Hl7V2Message` utility with MLLP stripping, negotiated delimiters, repetitions, occurrence/component/subcomponent access, escaped delimiter/hex decoding, multiple OBR/OBX group retention, precision/timezone-aware timestamps, structural validation, and payload-safe errors.
- Added 13 vendor-neutral ancillary connector playbooks for Radiology, Lab/AP/Blood Bank, Pharmacy/ADC/warehouse, and shared ADT linkage. Every template is read-only, upstream-MLLP/HTTPS-boundary based, machine-ability scoped, and inactive/non-PHI/not-started by default.
- Added `FhirResourcePolicy`. Encounter and Location remain enabled; ServiceRequest, ImagingStudy, DiagnosticReport, Specimen, Observation, MedicationRequest, and MedicationDispense exist in the configuration allowlist but remain disabled unless `INTEGRATION_ENABLE_ANCILLARY_FHIR` is explicitly approved. Polling also requires the exact resource SMART scope in the credential.
- Added `AncillarySourceProfile`, a terminal `UnsupportedAncillaryMessageNormalizer`, and an ordered normalizer registry. Unsupported input can only throw a safe reason code and enter the dead-letter path; it cannot report success.
- Added a shared HL7 v2 ancillary normalizer for governed ORM, OMI, OML, ORU, SIU, RDE, and RDS families. It validates structure, family/source binding, control ID, source order identity, and offset-aware source timestamps; derives only approved operational fields; and pseudonymizes patient/encounter identifiers before canonical storage.
- Added a structured normalizer for MPPS, analyzer/autoverification, barcode/workflow, pharmacy queue/ADC, warehouse batch, and approved FHIR-derived events. Structured sources require an explicit family-to-milestone binding and cannot select an arbitrary canonical event.
- Added `AncillaryCanonicalEventMapper` and `AncillaryMessageIngestPipeline`. Messages now traverse the existing raw record, normalized payload, canonical writer, projection dispatcher, append-only milestone, projection-specific provenance, and safe dead-letter controls. Receipts contain opaque UUIDs and status only. Normalized/canonical payloads discard raw vendor-only fields.
- Added `BulkBackfillAdapter`, `AncillaryBulkBackfillAdapter`, and the typed `BulkBackfillResult` contract. Backfill batches are bounded, every record traverses the same raw/canonical/projection controls, cursors are opaque, durable cursor comparison prevents gaps, and the watermark advances only when the entire batch succeeds.

The concrete default HL7 maps are catalog-checked: RIS ORM/OMI/ORU/SIU, LIS ORM/OML/ORU, and pharmacy RDE/RDS all resolve to currently seeded milestone codes. Structured sources are stricter and must carry an explicit governed family-to-code mapping.

### Automated evidence

```text
Integration runtime compatibility: 16 tests, 140 assertions, PASS
FHIR policy plus operational runtime: 10 tests, 72 assertions, PASS
Dispatcher and HL7 utility: 8 tests, 31 assertions, PASS
Patient Flow HL7 lineage/idempotency/rejected-raw checks: 3 tests, 40 assertions, PASS
Connector template idempotency/truthfulness: 1 test, 7 assertions, PASS
```

Final P0-6 acceptance matrix:

```text
AncillaryIntegrationRuntimeTest: 10 tests, 93 assertions, PASS
Consolidated ancillary + integration + Patient Flow + relay regression:
123 tests, 1,462 assertions, PASS
```

The acceptance matrix proves:

- governed HL7 and structured messages traverse raw receipt, sanitized normalization, canonical write, ancillary projection, milestone, provenance, and current status;
- duplicate delivery does not duplicate raw facts, canonical events, orders, assertions, or provenance;
- invalid family, missing control identity, missing order identity, malformed timestamp, source mismatch, and oversized input write sanitized dead letters without canonical projection;
- rejected raw messages remain available only in the raw store while receipts, normalized payloads, result DTOs, and exception/dead-letter messages exclude the raw payload and vendor-only secret fields;
- inactive or non-PHI-approved sources fail before raw storage;
- bounded bulk success advances one opaque checkpoint, a mixed-failure batch does not advance it, stale cursors fail closed, and configured record limits are enforced;
- all concrete default parser maps resolve to the governed milestone catalog; and
- the complete existing Patient Flow feature/unit suite remains green, including ADT machine-ingress authorization, raw rejection, canonical lineage, idempotency, redaction, and Encounter/Location behavior.

### Activation and limitations

- No endpoint, source, credential, scope, or connector was activated.
- The application pipeline is ready for a separately authorized machine boundary; authentication ability and endpoint binding remain deployment governance evidence, not an implicit route added by this task.
- The bulk adapter consumes already retrieved bounded records. Remote Bulk Data kickoff, polling, file transport, encryption, and vendor credentials remain connector-specific deployment work and cannot bypass this adapter.

## 2026-07-11 — P0-4 projection, precedence, replay, and rebuild

### Outcome

Implemented `AncillaryProjectionHandler`, `AncillaryProjectionRebuilder`, the `ancillary:rebuild-projections` repair command, and a 60-code `AncillaryEventVocabulary` shared by canonical mapping, handler ownership, replay allowlists, and catalog integrity.

Projection resolves the governed source and canonical record, validates event/code/department/work-item agreement, resolves or creates the source-owned order, appends an idempotent milestone assertion, writes milestone-specific provenance, and rebuilds the current order inside one transaction. Optional pseudonymous `reconciliation_key` joinery allows a secondary MPPS/LIS/middleware source to assert against an existing source-owned order without guessing from patient identity; ambiguous matches fail closed. A partial expression index supports this reconciliation path.

Source precedence comes from the source's governed metadata when present, then the milestone catalog. The rebuildable view retains all assertions and selects lowest source rank then newest receipt. The order projection exposes conflicts only when timestamp disagreement exceeds the configured tolerance.

The rebuild command supports order IDs, date bounds, dry-run counts, and chunked repair. It derives current milestone/state, terminal state, source cutoff, and conflict projection exclusively from the append-only selected-assertion ledger.

### Evidence

`AncillaryProjectionTest`: PASS, 9 tests and 48 assertions.

Covered source-to-screen lineage prerequisites include:

- raw message to canonical event to order/milestone/provenance/projected status;
- duplicate ingestion and forced replay idempotency;
- out-of-order history without current-state regression;
- late encounter/unit linkage without order duplication;
- correction append behavior and cancellation terminal protection;
- two-source reconciliation with MPPS precedence over RIS, both assertions retained, and conflict metadata;
- sanitized mismatch dead letter;
- dry-run and byte-equivalent repair rebuild; and
- queued replay through the multi-family dispatcher.

No external connector or route was activated.

### Consolidated checkpoint regression

The complete focused tranche was rerun after all schema, catalog, dispatcher, FHIR-policy, HL7, template, and projection edits:

```text
Laravel Pint: 49 files, PASS
PHPUnit: 71 tests, 780 assertions, PASS
Suites: all Ancillary feature tests; Integration unit tests; SyntheticHealthcareConnectorTest;
        IntegrationOperationalRuntimeTest; IntegrationConsoleTest
```

## 2026-07-11 — P0-5 governed SLA evaluator and breach lifecycle

### Outcome

Implemented `SlaEvaluator`, the typed `AncillarySlaEvaluation` result contract, the `ancillary:evaluate-slas` catch-up command, per-minute scheduler registration, and post-projection event-driven evaluation.

The evaluator selects the effective definition for each metric from department, priority, patient class, and JSON-scoped population attributes. The most specific eligible definition wins while an already-open breach remains pinned to its original definition version and exact start assertion. Start and stop milestones come from the governed current-assertion view; elapsed time is calculated from Unix instants and presented in the explicitly configured clock timezone.

The lifecycle now has distinct `not_started`, `running`, `warning`, `breached`, `complete`, and `unknown` states. Warning state exposes its computed threshold without writing a breach. Crossing a breach threshold writes exactly one open row; the selected stop assertion clears that same row and preserves exact start/stop assertion IDs, open/clear elapsed values, definition version, and lifecycle timestamps. Historical data arriving with a completed interval can materialize the chronologically correct open and clear activity once without pretending the breach was first observed at replay time.

Open and clear transitions write idempotent `ancillary.sla_breached` and `ancillary.sla_cleared` activity through `OperationalActivityLedger`. The events use the `ancillary` domain, UUID-only order/breach/definition entity references, no patient or encounter references, and the existing relay-policy machinery. Event recording is part of the breach transaction, so failure rolls back the partial lifecycle mutation.

Fresh real-time clocks age to the evaluation instant. Stale real-time evidence is `unknown`, never silently compliant, and cannot open a new breach. A terminal selected stop remains a definitive completed interval even when the feed later becomes stale. Warehouse clocks are explicitly `batch` and age only to their source cutoff. Missing starts and negative intervals are also explicit unknown states.

`AncillaryProjectionHandler` invokes safe evaluation after its source-fact transaction commits. Evaluation failure therefore cannot discard a valid raw/canonical/milestone projection. The scheduled command independently catches up open orders and orders with open breaches in bounded chunks, continues after a one-order failure, emits a sanitized machine-readable summary, and returns a failure exit code when any item failed.

### Automated evidence

Final focused verification:

```text
Laravel Pint: P0-5 implementation and test files, PASS
AncillarySlaEvaluatorTest: 13 tests, 71 assertions, PASS
php artisan schedule:list: ancillary:evaluate-slas --json registered every minute
git diff --check: PASS
```

The focused suite covers before-warning, warning, breach, stop-before-breach, historical stop-after-breach, repeated open/clear evaluation, corrected selected stop, stale source, missing start, warehouse cutoff, a spring DST boundary, most-specific effective policy, event-driven projection evaluation, batch failure isolation, and scheduler discovery.

Regression evidence around the changed integration and activity seams:

```text
Ancillary + integration runtime + relay policy: 85 tests, 976 assertions, PASS
Mobile BFF + backend safety + role parity + relay policy: 54 tests, 1,773 assertions, PASS
```

### Activation and limitations

- No external connector, API route, browser surface, or production source was activated.
- The scheduler is registered in code but this working-tree implementation has not been deployed.
- Initial definition rows remain demo/local-policy or inactive reference rows until department governance approves production policy and feed activation.

## 2026-07-11 — P0-7 ancillary OCEL and Arena projection

### Outcome

Extended the existing `OcelProjector`, `EmissionMap`, and `OcelCatalog`; no second process-mining schema or parallel event store was introduced. The normal recurring OCEL refresh now consumes `prod.ancillary_milestones` alongside Flow, perioperative, transport, and barrier facts.

Every milestone emits one deterministic `anc-mil-{ancillary_milestone_id}` event using the governed `ocel_event_type` already stored in `hosp_ref.ancillary_milestone_types`. The projector dynamically registers those governed activities and resolves cross-department activity names without duplicate upsert keys. Reprojection uses the milestone identity and existing qualified-relation unique constraints, so repeated full or scoped runs converge.

The projection declares and emits the shared `Ancillary Order` plus department-specific objects needed by the current evidence:

- Radiology: Imaging Study, Scanner when a governed resource reference exists, Imaging Read, Diagnostic Report, Critical Result, and Communication Task.
- Laboratory: Laboratory Test, Laboratory Specimen, Analyzer when available, and Laboratory Result.
- Pathology: AP Case, Pathology Specimen, Pathology Slide / Block, optional Pathologist Assignment, and Diagnostic Report.
- Blood bank: Blood Bank Request, Laboratory Specimen where asserted, and Blood Product Unit where issued.
- Pharmacy: Medication Order, Pharmacy Work, Medication Dose, and governed Medication Resource when available.

All events have qualified E2O links. O2O links express fulfillment, encounter context, location, specimen/test support, result-of, read/report-of, communication-of, case derivation, pharmacy processing, and dose-of relationships. Direct source order keys, accessions, patient references, encounter references, scanner/analyzer identifiers, and medication identifiers never become OCEL keys. Order UUIDs are safe internal identities; clinical/resource references use the existing one-way OCEL hash convention.

Added `ocel:project-ancillary` for bounded repair/backfill by inclusive date window and optional ancillary order IDs. It calls the same projector/flush path as recurring refresh and supports machine-readable output.

The executable process landscape now advances D1, D5, D6, D7, D11, and D12 to honest `partial_projection` notes. Each note names what shared objects now emit and what satellite fidelity remains absent; none claims a validated end-to-end model before those later tasks land.

### Automated evidence

```text
OcelAncillaryProjectionTest: 4 tests, 124 assertions, PASS
Complete existing OCEL + Arena feature/unit regression: 77 tests, 2,382 assertions, PASS
Laravel Pint for P0-7 implementation/tests: PASS
php artisan list: ocel:project-ancillary registered
git diff --check: PASS
```

The focused suite proves four-department phase-zero milestones emit the expected activities, object types, E2O/O2O relations, object changes, and a valid OCEL 2.0 export; no sensitive fixture reference survives in the OCEL store. It also proves scoped and repeated projection idempotency, reconciliation counts, command registration/execution, existing allow-listed Arena queries over Laboratory Test and Imaging Study, and inline D1/D5 facts on the existing map/performance/conformance sidecar request paths.

### Activation and limitations

- No sidecar, source, scheduler, or deployment state was changed.
- Phase-zero objects derived from the shared order are intentionally one-work-item abstractions. Distinct studies/specimens/reads/doses/resources replace fallback identities when department satellites land in R-1, L-1, and X-1.
- D1/D5/D6/D7/D11/D12 remain partial rather than validated until their department schemas, demo pipelines, and phase evidence gates are complete.

## 2026-07-11 — P0-8 rolling ancillary demo pipeline

### Outcome

Implemented the section 11 generator contract under `app/Services/Demo/Ancillary`. `RadiologyDemoGenerator`, `LabDemoGenerator`, and `PharmacyDemoGenerator` share the guarded `AncillaryDemoGenerator` contract and are composed by `AncillaryDemoScenarioService`.

The scenario owns exactly `operations-demo:summit-500-current-operations-v1:ancillary:v1`. Natural keys derive from anchor date, department, and stable ordinal. Preview is read-only and returns department plus total order, milestone, expected-breach, and collision counts. Refresh checks collisions before mutation, removes only exact-owner rows, uses the transaction-local append-only reset guard, removes only their lifecycle activity/provenance dependents, and then replays deterministic canonical events through the existing writer and projection dispatcher. Connector-backed, user-authored, and otherwise non-owned rows are never selected by date, null metadata, or source class.

The phase-zero scenario produces 15 orders and 60 milestone assertions across the three department families. It includes open SLA clocks, completed paths, a radiology minimum-feed/degraded path, MPPS-versus-RIS assertion competition, critical imaging notification/acknowledgment, laboratory rejection/recollect, critical callback, pharmacy first-dose and warehouse administration, discharge medication blocking, ADC override/discrepancy, and missing-dose recovery. Warehouse administration carries an explicit order cutoff and its source remains batch-classified.

`DemoRefreshCoordinator` now runs ancillary generation after operational/tuning/optional rounds data, then projects the ancillary OCEL window, refreshes source freshness, evaluates invariants, and only then publishes Cockpit. Domain step failures now fail the batch instead of being silently recorded while publication continues. Domain results retain the ancillary counts for the command/refresh ledger.

`ops.source_freshness` now has governed registrations for `prod.ancillary_orders.source_cutoff_at` and `prod.ancillary_milestones.received_at`. Both join the existing recomputation path and decision-source freshness gate.

`DemoInvariantService` adds eight ancillary findings: order/catalog linkage, terminal timing, mathematical open breaches, valid cleared stops, exact ownership, live discharge blockers, warehouse administration cutoff, and required source-conflict representation. The normal validation and refresh JSON outputs include these findings without a parallel command surface.

### Automated evidence

```text
AncillaryDemoScenarioTest: 7 tests, 36 assertions, PASS
Complete existing Demo feature/unit regression: 24 tests, 4,142 assertions, PASS
Laravel Pint for P0-8 implementation/tests: PASS
git diff --check: PASS
```

Acceptance evidence includes same-anchor count/key convergence, next-day natural-key movement, preservation of a non-demo row, transaction-wide refusal on a non-owned natural-key collision, retained conflict/rework/degraded/discharge/warehouse evidence, all critical ancillary invariants passing on the coherent scenario, and `zephyrus:demo-validate --json` exposing ancillary findings. A real isolated-test invocation of `zephyrus:demo-refresh --validate --json` includes the ancillary domain result. A deliberately non-owned row under a demo source forces the ownership invariant, leaves `published=false`, and proves `RefreshCockpitSnapshot` was not dispatched.

### Activation and limitations

- No production/demo host command was run and no deployed scheduler state changed; all refresh execution evidence used the isolated test database.
- Phase-zero scenarios intentionally exercise the shared spine. Full modality/scanner/IR, AP/blood-bank, pharmacy inventory/shortage, and department-scale distributions remain assigned to R-3, L-3/L-10, and X-3/X-4.
- Canonical demo events remain in the integration audit store across anchor dates; only exact-owner operational projections are rolled forward.

## 2026-07-11 — P0-9 shared timeline and readiness contracts

### Outcome

Added frozen TypeScript contracts and reusable components under `resources/js/Components/Ancillary`: `AncillaryOrderTimeline`, `ReadinessVector`, and one page-scoped `PageClockProvider`.

The timeline renders completed, selected-current, required-pending, optional-missing, terminal, and exception/rework facts with visible text plus distinct glyph semantics. It exposes selected source, retained assertion conflicts, source cutoff, real-time/stale/batch status, degraded-feed explanation, elapsed/remaining governed clock values, and keyboard-accessible definition access. Compact and expanded layouts share the same contract.

The readiness vector renders ready, pending, blocked, and unknown axes with pending count, oldest age, discharge-blocking status, freshness, warehouse-as-of labeling, and keyboard-accessible drill targets. Stale evidence is forced to unknown even when an upstream state says ready.

Elapsed display derives from one provider interval at page scope; rows do not create timers. Timer values use `tabular-nums` plus a fixed minimum character width, preventing digit changes from shifting layout.

### Automated evidence

```text
AncillaryTimeline Vitest: 5 tests, PASS
npx tsc --noEmit: PASS
scripts/check-ui-canon.sh: PASS (existing arbitrary-line-height backlog remains warning-only)
git diff --check: PASS
```

Fixtures cover full and degraded sequences, breached and unknown clocks, retained source conflict, rejection/recollect, cancellation, stale evidence, warehouse-as-of evidence, all readiness states, screen-reader labels, keyboard actions, stable numeric layout, and two timelines sharing one interval.

### Activation and limitations

- Components and contracts are reusable primitives; page/API adoption begins with the department and cross-module tasks.
- Runtime Zod parsing, common chart/heatmap primitives, backend statistical serializers, and the design example are deliberately assigned to P0-10.

## 2026-07-11 — P0-10 shared ancillary visual and query kit

### Outcome

Completed the shared query boundary with `AncillaryStatistics` and `AncillaryContractSerializer`. Continuous percentiles match PostgreSQL `percentile_cont`, empty cohorts return unavailable values rather than zero, non-finite and negative observations are excluded, and interval calculation parses explicit offsets or a supplied timezone before comparing instants. Negative, missing, and invalid intervals fail closed as unavailable. Freshness and governed SLA definitions serialize to the same camelCase vocabulary consumed by the browser.

Added strict Zod schemas for the freshness envelope, readiness axes, governed SLA definitions, metric tiles, and ancillary worklist rows. The schemas reject aliases, unknown fields, impossible readiness state, negative counts/ages, invalid UUIDs, freshness contradictions, and reversed warning/breach thresholds. The previous frontend-only freshness/readiness aliases were removed so the DTO and browser names are now identical (`asOf`, `status`, and `oldestAgeMinutes`).

Added `AgingHeatmap`, `SlaComplianceTile`, `BarrierChip` with an accessible Radix drawer, `QueueDepthSparkline`, `SourceFreshnessBadge`, and `FilterSummary`. Status is expressed through text and glyphs rather than color alone. Heatmaps and sparklines include expandable semantic tables. Stale, no-data, and loading states intentionally show unavailable values instead of fabricated zeroes; degraded state retains its partial-feed explanation.

The existing `/design/components` surface is the canonical example contract. It now shows normal, warning, breach, stale, no-data, degraded, and loading tiles plus readiness, source freshness, barrier, heatmap, queue-depth, and filter examples. No parallel navigation list, route, or Storybook was introduced.

### Automated evidence

```text
AncillaryQueryContractTest: 8 tests, 15 assertions, PASS
AncillaryTimeline + AncillaryVisualKit Vitest: 10 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
Laravel Pint for P0-10 PHP implementation/tests: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
git diff --check: PASS
```

Fixtures cover continuous median/P90 math, empty/invalid cohorts, UTC, explicit offsets, DST, missing and negative intervals, freshness/SLA serialization, malformed contract rejection, every canonical metric state, intentional unavailable output, semantic chart tables, and keyboard-managed barrier detail.

### Activation and limitations

- No source, route, scheduler, deployment, or production data was activated.
- The visual/query kit is intentionally domain-neutral. Radiology, Lab, and Pharmacy services adopt it in their phase-specific worklists and studies rather than forking calculations or status vocabulary.
- Production bundling retains the repository's existing Browserslist-age and large-chunk warnings; neither is introduced as a P0-10 correctness failure.

## 2026-07-11 — R-1 Radiology satellites, catalogs, and model graph

### Outcome

Created the Radiology persistence layer in `2026_07_11_000500_create_radiology_satellite_tables.php`. Governed `hosp_ref.rad_modalities` and `hosp_ref.rad_subspecialties` catalogs replace free-text dimensions. `prod.rad_scanners` and `prod.rad_scanner_downtimes` preserve source ownership, facility/unit/location identity, capacity, operational status, effective intervals, and exact demo ownership. PostgreSQL rejects zero capacity, unsupported states, and reversed downtime windows.

`prod.rad_exams` is a one-to-one satellite of a Radiology ancillary order and carries the source exam identity, encounter, modality, body region, subspecialty, procedure/protocol, contrast workflow, portable/IR branches, scanner, scheduled/performed/cancelled timestamps, preparation object, and status. A database trigger rejects non-Radiology order linkage and mismatched encounter linkage. Source-scoped natural identity and UUID uniqueness prevent duplicate projection.

`prod.rad_reads` appends preliminary, final, corrected, addendum, and cancelled operational report records with pseudonymous radiologist reference, subspecialty, teleradiology status, report-version identity, timestamps, and self-referential addendum lineage. `prod.rad_critical_results` carries finding class, policy state, identified/notified/acknowledged/escalated/closed timestamps, and recipient role without report narrative. Source-scoped keys prevent duplicate read and critical-loop projection.

Added typed Eloquent models and relationships from ancillary order through exam, scanner/downtime, read/addenda, and critical result, plus open, unread, operational, active-downtime, and open-loop query scopes. Scenario factories cover XR, CT, MRI, US, NM, IR, portable XR, contrast preparation, completed, cancelled, teleradiology, addendum, downtime, and critical acknowledgment states while creating valid pseudonymous encounters and source-owned ancillary orders.

The idempotent ancillary reference seeder now governs six modalities and nine subspecialties. Worklist, scanner-day, unread/read-queue, critical-loop, encounter, resource, and demo-owner indexes are present. The shared-spine down/up rehearsal now respects child-before-parent migration order and uses the canonical `RAD_FINAL` ordinal.

### Automated evidence

```text
php artisan migrate:fresh --env=testing --seed: PASS
RadiologyMigrationTest + RadiologyModelsAndFactoriesTest: 9 tests, 41 assertions, PASS
Complete Ancillary feature/unit regression: 77 tests, 731 assertions, PASS
Laravel Pint for R-1 implementation/tests: PASS
git diff --check: PASS
```

The focused suite verifies all seven tables, five critical constraints, five operational indexes, reversed-window rejection, three source-identity collision paths, Radiology-only order linkage, encounter consistency, idempotent catalog seeding, all required modalities and branches, immutable timestamp casts, and every model relationship.

### Activation and limitations

- No RIS, PACS, MPPS, reporting, critical-result, route, scheduler, deployment, or production data source was activated.
- Clinical report narrative and direct patient identifiers are deliberately absent from the Radiology satellites.
- Message parsing and projection into these satellites begin in R-2 through R-4; the R-1 factories are coherent local fixtures, not connector claims.

## 2026-07-11 — R-2 Radiology order, scheduling, and backfill ingestion

### Outcome

Added a governed Radiology HL7 v2 normalizer ahead of the generic ancillary handler for RIS `ORM`, `OMI`, and `SIU` families. It validates MSH/control identity, maps PV1 class codes to governed vocabulary, pseudonymizes PID/PV1 candidates, preserves source-scoped placer/filler/accession identities, maps ORC new/modify/cancel/discontinue/status controls, parses OBR procedure/modality/priority/event/schedule fields, and emits explicit degraded fields when optional modality/protocol/schedule evidence is missing.

The normalized contract can carry multiple independent OBR groups. `AncillaryCanonicalEventMapper` emits one canonical event per group with group-, event-, milestone-, and order-specific idempotency. Fields never bleed across OBR occurrences. The existing raw receipt, dead-letter, canonical writer, projection dispatcher, provenance, and replay boundary remains authoritative.

Added a feature-gated FHIR Radiology order normalizer for `ServiceRequest` and `Appointment`, with `Appointment` registered in the existing resource policy. Resource version identity, order identifier, explicitly offset authored/created/scheduled timestamps, procedure metadata, priority, pseudonymous subject/encounter references, cancellation status, and degraded evidence converge on the same canonical order/schedule vocabulary. Appointment creation time is the milestone event; future appointment start/end remain scheduled-slot attributes rather than falsely future-dating source freshness.

Added `RadiologyOrderProjector` inside the ancillary projection transaction. It resolves one `rad_exam` per authoritative ancillary order, enforces source exam identity, applies monotonic lifecycle status, records cancellation/discontinuation, preserves modifications, and never fabricates absent modality or protocol. The R-1 schema now permits a null exam modality only so degraded feeds can remain honest; scanner modality remains mandatory.

Seven golden HL7 fixtures cover STAT ED CT, routine inpatient MRI, portable XR, scheduled outpatient SIU, order modification, cancellation, and a two-OBR message. Focused tests also cover FHIR ServiceRequest/Appointment, duplicate replay, changed payload under an explicit idempotency key, isolated multi-procedure projection, and degraded optional fields.

### Automated evidence

```text
RadiologyOrderIngestTest: 5 tests, 17 assertions, PASS
Complete Ancillary feature/unit regression: 82 tests, 748 assertions, PASS
Laravel Pint for R-2 implementation/tests: PASS
git diff --check: PASS
```

### Activation and limitations

- No endpoint, credential, connector, route, scheduler, deployment, or production source was activated. FHIR ancillary resources remain disabled unless the existing environment governance flag enables them.
- R-2 carries operational order metadata only. Report narrative, DICOM listening, MPPS acquisition, PACS image availability, and critical-result adapter events remain R-3/R-4.
- Source payloads remain in the governed raw store; normalized contracts, dead letters, receipts, and browser-facing projections exclude direct identifiers and vendor-only secret fields.

## 2026-07-11 — R-3 Radiology result and report ingestion

### Outcome

Added a Radiology ORU normalizer that evaluates each OBR/OBX group independently. OBR-25 with OBX-11 fallback maps preliminary, final, corrected, and addendum states to `RAD_PRELIM` or `RAD_FINAL`. Report clocks prefer the group-specific OBR status-change timestamp, then OBX observation timestamp, OBR observation timestamp, and finally MSH time. Placer/filler/accession identity, procedure, modality, pseudonymous encounter/patient/radiologist references, and source report version are retained; OBX report content is intentionally never copied out of the governed raw message.

Added a feature-gated FHIR result normalizer for `ImagingStudy` and `DiagnosticReport`. ImagingStudy emits `RAD_IMAGES_AVAILABLE`; DiagnosticReport preliminary/final/amended/corrected/appended states emit the report milestones. Resource ID plus version is the append identity, and provenance records retain that version without conclusion, presented form, narrative, or direct subject identifiers.

`RadiologyReadProjector` appends immutable operational read rows and links corrected/addendum rows to the latest prior final lineage without changing the original. Duplicate source-version delivery returns the existing read. Each read receives a dedicated `integration.provenance_records` target in addition to the shared milestone provenance. Final-only feeds create an honest degraded exam/order shell and remain valid.

Added `RadiologyReadContractSerializer` as the privacy-minimal list/read boundary. It exposes UUIDs, status, version, teleradiology state, timestamps, and parent UUID only. Golden fixtures cover preliminary, final, corrected, addendum, final-only, duplicate final delivery, two independent result groups, and explicit non-UTC offsets. FHIR fixtures cover image availability, final version 1, and corrected version 2.

The tranche also corrected canonical time persistence: parsed operational instants are normalized to UTC before Eloquent writes. This prevents wall-clock reinterpretation of HL7/FHIR offsets and preserves valid terminal/source-cutoff ordering. Hand-computed fixtures prove 90-minute order-to-final and 30-minute prelim-to-final clocks.

### Automated evidence

```text
RadiologyResultIngestTest: 5 tests, 28 assertions, PASS
Ancillary + Integration feature/unit regression: 127 tests, 1,131 assertions, PASS
Laravel Pint for R-3 implementation/tests: PASS
git diff --check: PASS
```

The privacy test verifies the secret report narrative and direct patient token are absent from normalized payloads, canonical events, read metadata, and the list contract. The integration read-only test was made environment-independent by comparing template counts before and after API reads rather than assuming an unseeded database; it still proves reads create or promote nothing.

### Activation and limitations

- No reporting, FHIR, PACS, DICOM, connector, endpoint, credential, route, scheduler, deployment, or production source was activated.
- Raw clinical report content remains only in the existing governed raw store. It is not a `rad_reads` column and cannot enter the shared browser contract.
- MPPS, storage commitment relay, transport, and critical-result adapter ingestion remain R-4.

## 2026-07-11 — R-4 forwarded MPPS, PACS, transport, and critical-result relays

### Outcome

Added a single governed `RadiologyOperationalEventNormalizer` for upstream `MPPS`, `PACS`, `RAD_TRANSPORT`, and `CTRM` JSON envelopes. All families remain inside the existing authorized source, raw receipt, canonical event, ancillary projection, provenance, and dead-letter boundary.

MPPS requires control, order, study, SOP-instance, explicit-offset event/start/end timestamps, modality/scanner where known, and verified source-signature metadata. Only algorithm, key ID, and verified state leave the raw envelope; the SOP UID is represented downstream only by a SHA-256 digest. IN PROGRESS, COMPLETED, and DISCONTINUED map to acquisition start, acquisition end, and terminal cancellation/discontinuation state. Unsupported status, missing study/SOP identity, unverified signature metadata, malformed timestamps, and reversed performed intervals fail closed.

PACS storage-committed/images-available events map to `RAD_IMAGES_AVAILABLE`. The R-3 ImagingStudy backfill remains the FHIR equivalent. `RAD_TRANSPORT` maps a real Zephyrus `transport_requests.external_id` through requested/completed milestones without copying transport workflow state. `CTRM` appends notification/acknowledgment milestones and creates or advances one source-owned `rad_critical_results` loop with its own canonical provenance.

`RadiologyOrderProjector` now persists UTC acquisition timestamps, source-owned scanners, scanner linkage, hashed SOP identity, relay signature evidence, and transport request reference. `RadiologyCriticalResultProjector` enforces notification-before-acknowledgment, links the latest operational read when present, and stores no finding narrative.

MPPS-versus-RIS precedence is governed by the existing milestone catalog and source metadata. The test sends disagreeing completion assertions from both sources, retains both, and proves the current assertion selects MPPS at rank 1. Portable imaging remains valid with no transport milestone.

### Automated evidence

```text
RadiologyOperationalEventIngestTest: 5 tests, 29 assertions, PASS
Radiology + Ancillary + Integration + Transport regression: 162 tests, 1,411 assertions, PASS
Laravel Pint for R-4 implementation/tests: PASS
git diff --check: PASS
```

### Deployment boundary

- This is a forwarded HTTPS/queue payload contract, not a DICOM listener. Zephyrus does not open an Association, receive C-STORE/N-CREATE, or terminate a modality/PACS connection.
- A separately governed upstream integration engine or vendor relay owns DICOM/MPPS network termination, signature creation, allowlisting, credentials, retry, and delivery to Zephyrus.
- No endpoint, connector, source, credential, route, scheduler, deployment, or production feed was activated by this tranche.

## 2026-07-11 — P0-2 models, factories, scopes, and typed contracts

### Outcome

Added the shared domain layer under `app/Models/Ancillary`, immutable browser-contract DTOs under `app/Data/Ancillary`, and Eloquent/scenario factories under `database/factories/Ancillary`.

The model graph now covers the shared-spine relationships to integration source, encounter, unit, current milestone type, milestone ledger, SLA definition, breach, barrier, canonical event, provenance, and approving user. Department satellite inverse relationships remain intentionally colocated with R-1, L-1, and X-1 because those tables and models do not exist yet.

Operational scopes cover open work, department, unit, encounter, priority, active breach, discharge impact, source freshness, and exact demo ownership. `AncillaryMilestone` rejects model updates and deletes before issuing SQL; the P0-1 trigger independently rejects query-builder/direct SQL mutations.

Added a reusable `JsonObject` Eloquent cast after focused tests exposed Laravel's empty-array encoding mismatch. It guarantees that object-shaped JSONB columns store `{}` rather than `[]` while returning associative arrays to application code.

Factories now cover individual order, milestone, SLA, and breach records plus coherent full, minimum-feed/degraded, conflicting-source, out-of-order, cancelled-terminal, and lab rejection/recollect scenarios.

Typed immutable DTOs now freeze the camelCase response shapes for milestone assertions, selected clocks, readiness axes, source freshness, and page filters. Constructors reject impossible freshness, clock, readiness, and pagination states.

### Automated evidence

Command:

```bash
./vendor/bin/pint app/Casts/JsonObject.php app/Models/Ancillary app/Data/Ancillary database/factories/Ancillary database/migrations/2026_07_11_000400_create_ancillary_spine_tables.php tests/Feature/Ancillary
php artisan test tests/Feature/Ancillary/AncillarySpineMigrationTest.php tests/Feature/Ancillary/AncillaryModelsAndContractsTest.php
```

Result: PASS, 20 tests and 107 assertions across P0-1 and P0-2.

### Activation and limitations

- No source connector, projector, route, scheduler, or production activation was added.
- Satellite relationships are blocked on the deliberately later department table tasks and are explicitly assigned to R-1, L-1, and X-1.
- The DTOs establish backend serialization contracts; Zod/TypeScript mirrors and visual components remain P0-9/P0-10.
- Commit/PR and deployed-runtime evidence remain program-level release evidence; this task currently exists in the working tree.

## 2026-07-11 — R-6 Imaging Flow Board

### Outcome

Implemented `/radiology` as a server-derived Imaging Flow Board. `RadiologyFlowBoardService` is the single query/serialization boundary for both the Inertia first render and `GET /api/radiology/flow-board`; the frontend hydrates React Query from that exact contract and performs 30-second no-cache refetches without a second calculation path.

The board returns generated and source-cutoff timestamps, the shared freshness envelope, persisted SLA definitions and selected warning/breach thresholds, open-order/open-breach/discharge/degraded summaries, modality aging cells, a bounded five-order oldest preview, filtered `/radiology/worklist` drill target, barrier Pareto preview, governed Radiology barrier reasons, and current scanner/downtime state. Lenses cover all, ED, inpatient, discharge, and degraded populations, with independent priority, modality, and unit filters validated on both web and API routes.

The fixed demo discharge branch is now a real encounter-linked, discharge-blocking chest CT. R-6 proves it is the sole result under the discharge lens and preserves its CT modality, encounter linkage, server-derived clock, and downstream-impact classification.

Added a role-gated barrier annotation drawer for super/admin, operations, bed-management, and Radiology-management roles. The client can submit only an order UUID plus a governed active Radiology reason, bounded operational detail, and owner. The server derives the category from `hosp_ref.ancillary_barrier_reasons`, requires an encounter-linked Radiology order, inserts through the shared `BarrierService`, links any unlinked open ancillary breach, and writes `ancillary.barrier.opened` to the append-only `audit.user_events` ledger in the same transaction. The existing Improvement Active page now reads open `prod.barriers` and displays the Radiology annotation with its governed label, unit, owner, and opened time.

State handling is intentional. No matching orders returns `no_data` with an empty heatmap; missing optional modality/acquisition evidence returns `degraded`; measured/registered freshness returns `stale`; explicit registry failure returns `source_error` while preserving last-known cohorts with unavailable rather than fabricated cell counts. A warning registry default does not override an objectively in-tolerance selected source cutoff.

The React page reuses `AgingHeatmap`, `SourceFreshnessBadge`, DashboardLayout, PageContentLayout, Radix dialog focus management, healthcare theme tokens, semantic tables, and text/glyph status cues. It renders only the bounded preview; full pagination and timeline expansion are assigned to R-7.

### Automated evidence

```text
RadiologyFlowBoardTest: 5 tests, 48 assertions, PASS
R-6 combined Ancillary/Demo/Improvement/Audit regression: 115 tests, 1,020 assertions, PASS
Radiology + shared ancillary Vitest: 13 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
php artisan route:list --path=radiology: 3 routes present
Laravel Pint for R-6 PHP implementation/tests: PASS
git diff --check: PASS
```

### Activation and limitations

- No external source, credential, connector, scheduler, deployment, or production data was activated.
- `/radiology/worklist` is the explicit R-7 drill target; the R-6 board does not duplicate its pagination or timeline responsibility.
- Navigation registration and full route-ownership consolidation remain R-14. Direct authenticated route and API ownership are present for this vertical slice.
- Production bundle output retains the repository's existing Browserslist-age and large-chunk warnings; neither is an R-6 correctness regression.

## 2026-07-11 — R-7 Radiology Order Worklist

### Outcome

Implemented `/radiology/worklist` and `GET /api/radiology/worklist` through one `RadiologyWorklistService` contract. The service provides deterministic oldest, newest, governed-priority, and non-predictive breach-risk ordering with validated cursor pagination capped at 50 rows. Search is bounded to safe operational identifiers: source-order prefix, exact pseudonymous patient reference, or exact order UUID. Wildcards, SQL fragments, short searches, unknown deep-link sources, malformed cursors, unsupported filters, and overlarge pages are rejected.

Filters cover the R-6 lenses plus priority, modality, unit, operational state, sort, search, and an allowlisted source marker for `flow_board`, `ancillary_services`, `ed`, `rtdc`, `periop`, and `cockpit`. The source marker is relevance/audit context only and cannot broaden data access. The Inertia first render and API refetch serialize the same payload.

Each row carries downstream ED/discharge/OR impact, linked shared barriers, selected source cutoff/freshness, a governed clock, all retained source assertions with the selected assertion identified, and the shared `AncillaryOrderTimeline` contract. Minimum-feed gaps create an explicit degraded timeline. Transport milestones are removed from both the timeline and `transportSegment` unless a real transport assertion exists; the discharge CT shows its real request/arrival pair, while portable XR has no fabricated transport segment.

The breach-risk seam is intentionally descriptive. It orders current persisted open breaches before older non-breached work, advertises availability, and returns `enabled: false` with a P4-1 explanation. No model score, probability, or hidden predictive field exists.

The browser page uses a single page clock for every expanded timeline, displays downstream-impact chips and barriers, exposes a semantic retained-assertions table, and navigates next/previous encoded cursors without loading an unbounded order set. Empty results remain explicit.

Large-fixture evidence adds 150 open Radiology orders and proves query count is identical for 10- and 50-row pages and remains at or below 30 statements. Timeline, barrier, catalog, selected-assertion, and source-assertion hydration are batched by page rather than queried per row. PostgreSQL planner evidence verifies an intended ancillary open/live-worklist index path.

### Automated evidence

```text
RadiologyWorklistTest: 4 tests, 43 assertions, PASS
R-7 combined Ancillary + Demo regression: 109 tests, 962 assertions, PASS
Radiology + shared ancillary Vitest: 16 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
php artisan route:list --path=radiology: 5 routes present
Laravel Pint for R-7 PHP implementation/tests: PASS
git diff --check: PASS
```

### Activation and limitations

- No external source, credential, connector, scheduler, predictive model, deployment, or production data was activated.
- R-7 registers the direct worklist route needed by the R-6 drill. Full Radiology navigation ownership remains R-14.
- Search deliberately excludes report narrative, direct identifiers, and arbitrary contains queries.
- Production bundle output retains only the repository's existing Browserslist-age and large-chunk warnings.

## 2026-07-12 — R-8 Radiology Modality Utilization

### Outcome

Implemented `/radiology/modality` and `GET /api/radiology/modality` through one `ModalityUtilizationService` contract used by both the Inertia first render and React Query refetch. Validated filters cover date, bounded start/end time, and governed modality. The response declares generated/source-cutoff timestamps, filter options, source coverage, definitions, derived reference lines, portfolio totals, and scanner-level interval details.

Added a reusable `OperationalIntervalCalculator` for scanner and future IR operating-window calculations. It unions duplicate/overlapping intervals, clips activity to the declared staffed window, and partitions each instant with deterministic precedence: unplanned downtime, planned downtime, covered exam, then idle. The mutually exclusive output prevents double counting and reports an explicit reconciliation delta.

Scanner denominators now come from a versionable `staffed_operating_hours` metadata contract with timezone and weekly windows. The service clips same-day and overnight windows to the selected filter rather than dividing by 24 hours. Demo scanners receive explicit weekday/weekend staffing windows, and unused source-local demo scanner duplicates are removed after projection so the inventory and denominator are not inflated.

Machine utilization requires more than a configured source. A governed MPPS source must have observed milestone/watermark evidence or healthy protocol state and must map to the scanner by source identity, explicit `mpps_source_key`, or covered exam evidence. Each completed performed interval must retain authoritative MPPS start and end assertions. Missing, unrelated, or partial evidence returns null exam/idle/utilization values, a coverage warning, and unknown timeline segments. A globally healthy but unmapped feed cannot silently prove zero utilization for another scanner. Downtime remains visible because it is independently sourced.

The browser page renders staffed-window, machine-utilization, exam, and unplanned-downtime summaries; date/time/modality controls; a healthcare-token stacked Recharts view; a derived portfolio-average reference line labeled as non-benchmark; scanner timelines with downtime overlays; ED/IP/OP mix; reconciliation; native definition hover; expanded measure definitions; and an accessible chart summary table. DEC-3 remains unchanged: no outpatient access, template, or no-show analytics were introduced.

The large-fixture worklist planner assertion now accepts the existing `ancillary_orders_unit_worklist_idx` in addition to the open/live indexes. PostgreSQL selected that governed worklist index during the combined run; the query remains an index scan with constant bounded hydration.

### Automated and rendered evidence

```text
OperationalIntervalCalculatorTest + ModalityUtilizationTest: 6 tests, 54 assertions, PASS
R-8 combined Ancillary + Radiology demo + OCEL regression: 119 tests, 1,147 assertions, PASS
Radiology + shared ancillary Vitest: 18 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
php artisan route:list --path=radiology: 7 authenticated routes present
Laravel Pint for R-8 PHP implementation/tests: PASS
git diff --check: PASS
```

An isolated Playwright smoke against the built asset and `APP_ENV=testing` returned HTTP 200, the correct page title and heading, one filter form, one accessible chart, six scanner detail rows, complete MPPS coverage, and zero browser console/page errors. The no-data route was also rendered intentionally before demo setup and returned HTTP 200 with no browser errors.

The populated browser setup exercised the canonical demo coordinator only to create disposable test data. Its ancillary domain completed with 16 exams, six scanners, one downtime interval, and complete page coverage, but the broader coordinator correctly withheld Cockpit publication because unrelated global `temporal.admit_not_in_future` and `ancillary.open_breaches_mathematically_valid` invariants failed in that disposable setup. R-8 completion relies on the clean focused/combined tests and direct rendered-page smoke above, not on that failed global publish gate. The multi-schema test database was then explicitly restored and its canonical workforce/service-line reference fixtures reseeded before the final green regression.

### Activation and limitations

- No external MPPS/RIS/PACS source, connector, endpoint, credential, scheduler, deployment, production data, outpatient access analysis, or benchmark target was activated.
- `staffed_operating_hours` must be supplied by deployment-owned scanner configuration before a live scanner can claim utilization. Missing schedules remain explicit and degraded.
- The chart reference line is the selected covered-scanner portfolio average. External/local benchmark governance remains P4-5.
- The shared interval calculator is ready for R-13 IR reuse; R-13 must prove identical fixtures across Radiology and Perioperative consumers rather than copying formulas.
- Full Radiology navigation ownership remains assigned to R-14.

## 2026-07-12 — R-9 Radiology Reads and Results

### Outcome

Implemented `/radiology/reads` and `GET /api/radiology/reads` through one `RadiologyReadsService` contract. The workspace reports unread depth and oldest age by priority and subspecialty; distinct no-report, preliminary, final, and corrected populations; complete-hour backlog growth; preliminary-to-first-final aging; critical-result notification/acknowledgment health; and governed reporting-source freshness. State, priority, subspecialty, modality, time-window, and row-limit inputs are server validated.

Backlog points use full 60-minute clock buckets ending at the current hour and exclude the current partial hour. Open-at-end is reconstructed from acquisition completion and the first final report. Missing acquisition/final timestamps are counted explicitly and degrade the page rather than silently altering the denominator. Preliminary aging uses first preliminary to first final, excludes negative intervals, documents missing preliminary timestamps, and does not move the original final clock when corrections or addenda arrive.

Item-level SLA warnings and breaches remain in the Radiology workspace. Persisted breaches win; otherwise effective RAD_FINAL SLA definitions select the governed start milestone and server-side warning/breach threshold. Stale, missing, and failed reporting sources never claim current or normal item health. A bounded aggregate `cockpitHealth()` contract exposes unread and critical-loop counts/oldest ages plus source state for exact R-10 reuse without leaking item detail.

Critical-result state is separated into pending notification, notified, acknowledged, escalated, and closed populations. Timing distributions cover identified-to-notified and notified-to-acknowledged intervals. Open items link back to the originating order through the existing source-scoped Radiology worklist. The service selects no report narrative fields, declares `clinicalReportTextIncluded: false`, and returns only pseudonymous operational identifiers and UUIDs.

The React page validates the first-render and refetch payload with a strict Zod schema, supplies complete filters, freshness and degraded-state messaging, summary tiles, report-state counts, priority/subspecialty depth, an accessible Recharts backlog plot and table, critical-loop summaries/drills, and a bounded queue table. Demo Radiology scenarios now include preliminary-to-final lineage alongside deliberate no-report, corrected, critical-loop, and missing-timestamp examples.

### Automated evidence

```text
RadiologyReadsTest: 4 tests, 68 assertions, PASS
RadiologyDemoGeneratorTest + RadiologyReadsTest: 5 tests, 100 assertions, PASS
Radiology Reads + Modality Utilization Vitest: 3 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
Laravel Pint for touched R-9 PHP/tests: PASS
git diff --check: PASS
```

### Activation and limitations

- No RIS/PACS/reporting connector, endpoint, credential, scheduler, deployment, production data, report narrative, or Cockpit card was activated.
- R-10 must consume `cockpitHealth()` unchanged or prove any requested contract extension reconciles exactly with this workspace.
- Critical-result contents remain out of the operations payload; the loop exposes operational class/state/timing only.
- Full Radiology navigation ownership remains assigned to R-14.

## 2026-07-12 — R-10 Radiology Health in the Server-Computed Cockpit

### Outcome

Added aggregate Radiology health to the existing Flow domain without introducing a client-computed or standalone Radiology tile. `RadiologyFlowBoardService::cockpitHealth()` now exposes only open-breach count, distinct active scanners down/total, source state, and source cutoffs. `RadiologyCockpitHealthService` composes that contract with the unchanged `RadiologyReadsService::cockpitHealth()` seam from R-9, preserving exact workspace ownership of unread counts, oldest age, and priority breakdown.

`FlowMetrics` emits the three already-governed definitions: `flow.ancillary_rad_open_breaches`, `flow.ancillary_rad_oldest_unread`, and `flow.ancillary_rad_scanners_down`. Fresh values resolve solely through each current `ops.metric_definitions` row and `StatusEngine`. Each value carries live provenance, current/degraded/unknown data state, source label/state/cutoff, `/radiology` workspace route, and—on unread age—the exact priority breakdown. History therefore retains both the scalar and its source-quality context.

Scanner-down aggregation excludes retired inventory, treats explicit scanner downtime or a currently active/scheduled downtime interval as unavailable, retains limited scanners as available, and counts distinct scanners so overlapping downtime rows cannot inflate the result. The scanner configuration cutoff is carried separately from the operational-order source cutoff.

Stale, error, or degraded last-known values retain their numeric fact and cutoff but are explicitly demoted server-side to the neutral Cockpit state and labeled `Last known`. The reusable `MetricValue` data-quality override is deliberately restricted to `CockpitStatus::NORMAL`; callers cannot use it to manufacture warning, critical, or earned-green state. Missing report evidence omits the unsupported oldest-unread tile rather than writing a fabricated zero. This guarantees stale or missing evidence cannot render success.

The Flow drill now begins with an `Ancillary operational health` table sourced from the exact cached Flow tiles, preserving single-snapshot discipline. Its Radiology row reconciles with the wall values and shows source state/cutoff. Laboratory and Pharmacy rows are reserved as explicit neutral/not-available entries until their later providers emit the already-seeded keys; the React `DataTable` remains purely presentational.

Alert behavior remains definition-gated. All three Radiology definitions intentionally have null alert templates, so warning/critical wall values and metric-history rows do not enter the ticker. A later governed template change would activate the existing AlertEngine path without special Radiology code.

### Automated evidence

```text
RadiologyCockpitMetricsTest: 4 tests, 55 assertions, PASS
MetricValueTest + RadiologyCockpitMetricsTest: 11 tests, 75 assertions, PASS
Cockpit + Radiology backend regression: 143 tests, 925 assertions, PASS
Cockpit Vitest suite: 19 files, 85 tests, PASS
Cockpit ancillary Playwright smoke: 1 test, PASS; zero console errors
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint for touched R-10 PHP/tests: PASS
git diff --check: PASS
```

### Activation and limitations

- No connector, credential, scheduler, migration, deployment, production data, Cockpit alert template, Laboratory metric provider, or Pharmacy metric provider was activated.
- R-10 deliberately reuses the existing Flow domain and drill. Domain ordering, gauge ownership, census strip, and the frozen React cell grammar remain unchanged.
- R-11 remains the next dependency-ordered Radiology tranche: imaging in ED and the shared RTDC discharge-readiness vector.
