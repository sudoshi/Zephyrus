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

R-5 superseded the initial P0-8 phase-zero demo totals. The later R-13/R-15 expansion now preserves 26 orders and emits 140 milestones: 97 Radiology, 20 Laboratory, and 23 Pharmacy milestones.

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

## 2026-07-12 — R-11 Imaging Readiness Across Radiology, ED, and RTDC

### Outcome

Added `AncillaryReadinessService` as the single batched imaging-readiness calculation for encounter, ED-visit, and department-order scopes. Each axis carries pending count, oldest age, governed state/status, explicit discharge-blocking state, source freshness, selected top-order UUID, and a bounded `/radiology/worklist` drill. Top-order selection is deterministic: explicit discharge gates first, then oldest order and stable order ID.

Only open Radiology orders can contribute. Discharge blocking requires either the governed `discharge` priority or an explicit `metadata.discharge_blocking` tag; routine imaging is visible as pending but is not silently promoted to a discharge gate. Terminal orders are excluded. Missing or stale source evidence produces `unknown`, including the zero-order case, so an empty stale cohort cannot become earned green.

`DischargePrioritiesService` now batches readiness for its existing active inpatient cohort and adds only the `imaging` field; all prior patient and filter fields and the existing tier algorithm remain unchanged. The RTDC card renders the shared compact `ReadinessVector` and routes its accessible drill to the exact UUID-filtered Radiology view.

`TreatmentService` similarly batches by `ed_visit_id` through the governed Radiology exam context rather than issuing per-row queries. ED rows retain the existing deterministic pending-order list and add an imaging chip with icon/text state, pending count, oldest age, and an allowlisted `source=ed` drill. Stale facts are announced as unknown. `RadiologyWorklistService` consumes the same per-order axis for its readiness detail and downstream discharge-blocking flag, removing the previous local duplicate formula.

The shared readiness browser contract remains additive: legacy `status` and `drillTarget` fields stay available while the exact R-11 `state`, `topOrderUuid`, and `drillHref` fields are now validated by the strict Zod schema. `ReadinessVector` prefers the new drill field and falls back to the legacy target for compatibility.

### Automated and rendered evidence

```text
ImagingReadinessIntegrationTest: 2 tests, PASS
Focused R-11 + Radiology worklist + DTO backend: 14 tests, 142 assertions, PASS
Full Ancillary feature regression: 103 tests, 1,025 assertions, PASS
Ancillary + Radiology Vitest: 7 files, 22 tests, PASS
Focused readiness/Radiology Vitest: 4 files, 16 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint for touched R-11 PHP/tests: PASS
git diff --check: PASS
Chromium smoke: RTDC discharge, ED treatment, and Radiology worklist PASS; zero console/page errors
```

The fixed-time reconciliation fixture creates one pseudonymous patient context with an active inpatient encounter, active ED visit, and one open discharge-blocking CT. Radiology, ED, and RTDC independently resolve the same order UUID, 47-minute age, blocked state, and current freshness. The same test proves a routine unrelated outpatient order is non-blocking, a completed discharge-tagged order is excluded, completion transitions the encounter to ready, and stale source health demotes both open and empty axes to unknown.

Browser evidence used an isolated `APP_ENV=testing` database populated by the canonical seed and demo-refresh paths. All three changed routes rendered built assets and their readiness controls without console/page errors. The test database was then reset with fresh migrations and its normal deployment registry/staff-role baseline restored.

### Activation and limitations

- No connector, credential, scheduler, migration, deployment, production data, Lab/Pathology axis, or Pharmacy axis was activated.
- ED scope depends on the governed `rad_exams.metadata.ed_visit_id` context emitted by the existing projector/demo path; ambiguous or absent visit context is never guessed from patient identity.
- R-11 intentionally does not alter discharge prioritization tiers or the existing ED synthetic pending-order enrichment. It adds observed imaging readiness beside those contracts.
- L-11 is now unblocked to add the Lab axis through this service without copying readiness logic or replacing the Imaging axis.

## 2026-07-12 — R-12 Radiology TAT Study

### Outcome

Implemented `/analytics/radiology-tat` and `GET /api/radiology/tat` through one `RadiologyTatAnalyticsService` contract shared by the Inertia render and authenticated API refresh. Validated filters cover a bounded 90-day inclusive date window, governed priority/modality/patient-class/shift dimensions, and a result limit capped at 2,000. The service first selects the indexed Radiology cohort and then hydrates current assertions in batches; large-fixture evidence proves query count is constant between limits of 2 and 100.

Added six reference-only study clocks for order-to-exam-start, exam duration, acquisition-to-PACS, images-to-preliminary, images-to-final, and order-to-final. Each interval row retains its SLA-definition UUID/metric plus the selected start and stop assertion UUID, source, rank, and competing-assertion count. The shared ancillary contract now serializes definition scope so the Study can declare segment order and primary-trend ownership without inventing client-side clock semantics.

The aggregate contract reports median and P90 as the primary statistics, with mean only as a secondary value. It provides the six-segment waterfall, daily order-to-final trend, priority/modality/patient-class/shift distributions, facility-time night/weekend comparison, persisted breach Pareto, benchmark/policy registry, data-coverage ledger, freshness, and a bounded pseudonymous lineage audit. Corrected/addendum exams, missing assertion pairs, negative intervals, invalid timestamps, and conflicting assertions are counted visibly rather than silently folded into the denominator.

Benchmark labeling is deliberately honest. A study clock with no persisted numeric policy exposes its definition source and `no governed numeric benchmark`; only persisted local warning/breach/target values become reference lines, and those lines remain labeled as local policy rather than external benchmarks. Every chart exposes the exact clock definition, cohort count, source cutoff, and benchmark-source label in both its accessible caption and summary table. No patient identifiers or report narrative are selected or rendered.

The Analytics navigation now owns `/analytics/radiology-tat` exactly once under `Ancillary Performance`. Explicit ownership tests prove the route is absent from all other workflow sections. The page supplies bounded filters, freshness and exclusion messaging, 11 accessible chart figures in the canonical demo, coverage and benchmark tables, and an expandable lineage table linking operational evidence back to its selected assertions.

Added `ancillary_orders_department_ordered_idx` on `(department, ordered_at, ancillary_order_id)` for the bounded aggregate cohort. PostgreSQL `EXPLAIN` evidence proves the Study query uses that index. The existing worklist planner test now also accepts this valid governed path because PostgreSQL may select the new department/date index for its bounded Radiology scan.

### Automated and rendered evidence

```text
RadiologyTatAnalyticsTest + AncillaryReferenceSeederTest: 11 tests, 671 assertions, PASS
Full Ancillary feature regression: 106 tests, 1,347 assertions, PASS
Radiology + Ancillary + navigation Vitest: 9 files, 46 tests, PASS
Final focused Radiology TAT/navigation Vitest: 3 files, 28 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint for touched R-12 PHP/tests: PASS
Chromium built-asset smoke: 11 figures, 6 segment rows, 64 lineage rows, zero console/page errors
```

The first full Ancillary regression exposed disposable R-11 browser-demo rows left in non-default PostgreSQL schemas because `migrate:fresh` resets only the configured/default schema. A database-name guard confirmed `zephyrus_test` before truncating only the disposable `integration`, `ops`, `ocel`, and related test schemas. The clean rerun passed all 106 tests, and the test baseline was restored again afterward with zero ancillary source/event rows plus the normal 29-service-line and 87-role registries. No production database was touched.

### Activation and limitations

- No connector, credential, scheduler, deployment, production data, external benchmark, or policy activation was introduced.
- The six new clocks are inactive reference definitions for Study attribution. Existing operational SLA enforcement remains unchanged.
- Numeric lines reflect persisted local policy only; absent governance remains explicitly absent rather than replaced with literature-derived or synthetic targets.
- R-13 is the next dependency-ordered Radiology tranche and must reuse the shared operational interval calculator for IR utilization.

## 2026-07-12 — R-13 IR Suite Study

### Outcome

Implemented `/analytics/ir-utilization` and authenticated `GET /api/radiology/ir-utilization` through one `IrSuiteAnalyticsService` contract. The route is a Study surface under Analytics; Radiology retains ownership of the live IR worklist. The page cross-links to the source-scoped IR worklist, OR room status, and the Perioperative OR-utilization Study while stating this ownership split directly in the browser contract.

Extracted `SuiteMetricCalculator` as the single arithmetic authority for first-case on-time starts, same-room turnover, declared-window utilization, rooms-running overlap, and planned-versus-unplanned downtime classification. Existing `PerioperativeMetricsService` now delegates FCOTS decisions to that calculator. Existing OR-utilization and room-running services consume its denominator/profile constants. `ModalityUtilizationService` and the new IR service share the downtime classifier, while utilization continues through the existing `OperationalIntervalCalculator`. Identical procedure-suite interval fixtures now produce byte-equivalent Perioperative-context and IR-context results because domain identity is not a calculation input.

Extracted `OperatingWindowResolver` from the R-8 modality path. Both modality and IR services now resolve the same deployment-owned `staffed_operating_hours` weekly contract, including overnight windows, date/time clipping, timezone conversion, interval union, and no inferred 24-hour default. IR resources enter the cohort only when the scanner is active, has modality `IR`, and carries explicit `metadata.ir_suite_declared=true`; `rad_exams.is_ir=true` and a declared-room link are independently required for every case.

The bounded service validates a maximum 31-day inclusive date range, declared room UUID, governed patient class, and at most 1,000 analyzed rows. It uses the partial `rad_exams_ir_scheduled_idx` path for `(scheduled_start_at, rad_scanner_id, rad_exam_id)` when `is_ir=true`, then hydrates selected assertions in one batch. Query count is identical at limits 1 and 100 and remains below the test ceiling.

Suite metrics are denominator-explicit. Each room returns timezone, exact operating windows, capacity, completed-case coverage, occupied/planned-downtime/unplanned-downtime/idle minutes, utilization, reconciliation delta, FCOTS numerator/denominator, median/P90/mean turnover, and mutually exclusive timeline segments. Aggregate utilization is withheld if any in-scope room lacks a declared window or valid selected MPPS start/end evidence. Negative or missing intervals, missing windows, truncation, missing gate pairs, and invalid gate intervals remain visible in the coverage ledger.

Preparation (`RAD_ORDERED` to `RAD_PREP_COMPLETE`), transport (`RAD_TRANSPORT_REQUESTED` to `RAD_TRANSPORT_COMPLETE`), and read (`RAD_IMAGES_AVAILABLE` to `RAD_FINAL`) distributions are additive imaging gates. They use the selected assertion view and expose comparable count, median, P90, secondary mean, missing count, invalid count, source cutoff, and exact clock definition without changing the shared suite formulas. The bounded lineage table retains operational exam/room UUIDs plus selected MPPS assertion UUID/source/rank/count and excludes patient identifiers and report narrative.

The React page uses strict Zod validation, bounded GET filters, source freshness, four summary cards, three accessible chart figures and companion tables, explicit denominator and coverage language, five shared-definition rows, operational/Study cross-links, and expandable assertion lineage. `IR Suite Utilization` is registered exactly once under Analytics → Ancillary Performance; the ownership test proves no other navigation domain claims it.

The canonical Radiology demo now gives its existing IR case explicit scheduled bounds, declares exactly one generated IR scanner, and preserves the original 16-order/16-exam cohort. Demo idempotency, ownership isolation, invariants, the existing modality service, and the new IR Study contract all pass together.

### Automated and rendered evidence

```text
IrSuiteAnalyticsTest + SuiteMetricCalculatorTest: 4 tests, 99 assertions, PASS
SuiteMetricReuseTest against seeded Perioperative dashboard/OR utilization: 1 test, 6 assertions, PASS
Full Ancillary + shared-calculator regression: 120 tests, 1,472 assertions, PASS
RadiologyDemoGeneratorTest: 1 test, 51 assertions, PASS
Radiology + Ancillary + navigation Vitest: 11 files, 51 tests, PASS
Final focused IR/navigation Vitest: 3 files, 28 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint for touched R-13 PHP/tests: PASS
git diff --check: PASS
Chromium built-asset smoke: HTTP 200; 3 chart figures, 1 room row, 3 gate rows, 5 shared-definition rows, 1 lineage row, zero console/page errors
```

Browser evidence used only `zephyrus_test`, the canonical database seed, and `zephyrus:demo-refresh --force`. The refresh passed 35 invariants with zero critical failures and published the Cockpit. After smoke, a database-name guard verified `zephyrus_test`, disposable multi-schema rows were truncated, fresh migrations were restored, and the normal 29-service-line/87-role registry baseline was reseeded. Production was never accessed or mutated.

### Activation and limitations

- No connector, credential, scheduler, deployment, production data, benchmark target, or live IR policy was activated.
- IR room declaration and staffed operating hours remain deployment-owned metadata. An undeclared scanner or missing schedule yields no denominator rather than a generic OR-day assumption.
- The existing single-case demo proves FCOTS, utilization, gate, provenance, and ownership behavior; multi-case turnover distributions are proven by fixed backend fixtures rather than fabricated demo cases.
- R-14 is the next dependency-ordered Radiology tranche and will consolidate the complete Radiology route, API, navigation, policy, mobile-drawer, mega-menu, command-palette, and RTDC drill ownership evidence.

## 2026-07-12 — R-14 Radiology Route, Navigation, Policy, and Handoff Ownership

### Outcome

Registered Radiology as a first-class operational workspace without moving or duplicating any established bookmark. The four page routes now share the stable `radiology.*` namespace and remain `/radiology`, `/radiology/worklist`, `/radiology/modality`, and `/radiology/reads`; the route group still precedes RTDC. The six read endpoints and Zephyrus-owned barrier mutation now have explicit `api.radiology.*` names while preserving their existing `web`, `auth`, and throttle middleware.

`navigationConfig.ts` remains the sole navigation authority. It now declares one Radiology workspace with Imaging Flow Board as its dashboard plus Worklist, Modality Utilization, and Reads & Results operational leaves. Radiology TAT and IR Suite Utilization remain owned exactly once under Analytics. The shared section-menu renderer now suppresses any dashboard/first-leaf duplicate generically, so `/radiology` appears once without introducing Radiology-only rendering logic. Structural and rendered tests prove the same four operational leaves reach the desktop section menu, mobile drawer, and command palette.

Added `viewAncillaryPatientDetail` as a distinct, minimum-necessary operational capability alongside the existing `manageAncillaryBarriers` gate. This registration is additive and does not alter the protected authentication files or authentication flow. Both Inertia and API controllers pass the capability into the Flow Board, Worklist, and Reads services. Those services retain their strict contracts but deterministically replace patient context with `Patient context restricted` and declare patient context absent when the user lacks permission. Barrier mutation remains independently authorized and audited; neither capability implies the other.

The RTDC Ancillary Services contract now emits a server-owned drill only for real Imaging service rows. Its target is the existing scoped worklist bookmark with the governed unit identifier and `source=ancillary_services`. Non-Imaging rows and client fallback data receive no fabricated destination. The matrix tile, expanded service table, and unit modal expose accessible Imaging handoffs, and the destination retains the exact scope query through the browser navigation.

Route registration tests lock page and API names, controller actions, authentication middleware, bookmark compatibility, and Radiology-before-RTDC ordering. Authorization tests reject anonymous access to all Radiology read APIs. Role-matrix and service tests prove patient-detail and barrier permissions remain independent and that redaction is identical across first render and API refresh. Navigation ownership tests cover the single source, generic dashboard deduplication, desktop, mobile, and command palette. RTDC backend, React, and Chromium tests prove the Imaging-only handoff and scoped destination.

The combined regression exposed one pre-existing sequence-sensitive API test that assumed the next OR-case primary key would be `1`. The test now reads the actual case ID created by the request before checking the log record, preserving the contract while removing dependence on PostgreSQL sequence reset behavior.

### Automated and rendered evidence

```text
Ancillary + Radiology + Cockpit + demo + API/route regression: 135 tests, 2,014 assertions, PASS
Final RadiologyRouteRegistrationTest with source-order guard: 3 tests, 74 assertions, PASS
Focused Radiology navigation, pages, and RTDC Vitest: 8 files, 54 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
RouteSmoke: 97 GET routes checked, 0 failures
Laravel Pint for touched R-14 PHP/tests: PASS
git diff --check: PASS
Chromium R-14 smoke: 4 tests PASS; desktop, mobile, command palette, and scoped RTDC Imaging handoff
```

Browser evidence used built assets, an ephemeral application key, and only `zephyrus_test`. One temporary inpatient unit was inserted solely because the governed RTDC endpoint validates that a requested unit exists; all four browser paths then passed together. The temporary server was stopped and the fixture was deleted, with a final count of zero. No production database, connector, credential, scheduler, deployment, or external system was accessed or mutated.

### Activation and limitations

- No deployment, production data, connector, credential, scheduler, feature activation, or authentication-flow change was introduced.
- Navigation visibility continues to follow the repository's existing authenticated navigation contract; R-14 establishes exact ownership and server-side capabilities but does not invent a new client entitlement system.
- Client fallback RTDC units intentionally have no Radiology drill because their identifiers are not server-governed and may fail the worklist's unit validation.
- R-15 is the next dependency-ordered Radiology tranche and must run the complete phase verification/release gate, including the full PHP suite, migration rehearsal, canonical demo validation, OCEL projection, and dark/light responsive screenshot evidence.

## 2026-07-12 — R-15 Radiology Phase Verification and Release Gate

### Outcome

Closed the complete Radiology phase with a green release matrix and a durable evidence bundle at [`docs/evidence/ancillary/radiology-r15-2026-07-12/`](evidence/ancillary/radiology-r15-2026-07-12/README.md). The gate covers all four Radiology operational pages, both Radiology-owned Study implementations, the shared ancillary spine, cross-module readiness, Cockpit aggregation, OCEL projection, migration rollback/forward repair, demo invariants, responsive behavior, privacy, and activation boundaries.

The initial repository-wide run exposed multi-schema test pollution: PostgreSQL `migrate:fresh` clears the configured `prod,public` search path but does not clear mutable facts in `ops`, `integration`, `hosp_org`, `ocel`, and other schemas. That allowed rows from earlier PHPUnit processes to distort later demo, staffing, recommendation, and Radiology fixtures. `tests/TestCase.php` now performs one guarded baseline reset per RefreshDatabase process after rolling back the framework's wrapper transaction. It refuses to run unless `APP_ENV=testing` and the database name is exactly `zephyrus_test`, truncates only mutable non-system schemas and explicitly enumerated seeder-owned `hosp_ref` catalogs, preserves migration-owned reference catalogs, then restores the wrapper transaction. Formerly failing fixtures also seed their exact staffing prerequisite rather than depending on prior process state. The final full suite is hermetic from a dirty multi-schema starting point.

The strict demo gate found four release defects and R-15 fixed each rather than accepting a warning:

- `expected_discharge_date` is a calendar date, but repair and validation compared it with a timestamp. Both now compare with `admitted_at::date`, and a focused regression proves a same-day target is valid while a prior-day target fails.
- Command Center's synthetic census row used a fixed noon timestamp, becoming decision-critically stale during evening refreshes. The seeder now reuses the matching governed row for the day while advancing `captured_at` to the current minute, keeping history bounded and freshness honest.
- The operational transport scenario generated a 50/25/25 routine/urgent/STAT mix and too many overdue requests. It now deterministically produces 60/30/10 with exactly four of twenty active requests overdue.
- `DemoTuningSeeder` randomized those scenario-owned transport deadlines after creation. The obsolete rewrite is removed; EVS deadline tuning remains, while transport lifecycle/SLA ownership stays in `OperationalDemoDataService`.

After those repairs, `zephyrus:demo-refresh` and independent `zephyrus:demo-validate --strict` passed all 35 invariants with zero critical and zero warning failures. The refresh published the gated Cockpit snapshot. The owned cohort reconciled to 26 orders and 140 milestones overall: Radiology 16/97, Laboratory 5/20, and Pharmacy 5/23. Radiology additionally produced 16 exams, 29 reads, six scanners, one downtime record, one critical-result loop, and two linked barriers.

The rendered audit found two additional browser-boundary defects. Empty PHP SLA scopes were valid object-shaped JSONB but were serialized as JSON arrays, violating the strict Zod record contract and preventing the Flow Board from rendering. `AncillaryContractSerializer` now casts scope to an object at the HTTP boundary, and unit/feature/browser coverage proves service, Inertia, API, and TypeScript parity. Separately, Tailwind's screen-reader-only class applied directly to an accessible HTML table did not constrain the table formatting context and widened the Reads page to 780 px in a 768 px viewport. Both Radiology accessible chart tables now sit inside screen-reader-only wrappers, preserving assistive data while eliminating document overflow.

### Automated, data, and rendered evidence

```text
Focused temporal invariant: 1 test, 4 assertions, PASS
Operational demo scenario: 3 tests, 66 assertions, PASS
Focused SLA serializer + Flow Board contract: PASS
Full PHP suite: 1 skipped, 1,031 passed, 16,664 assertions, 198.22 s
RouteSmoke: 97 GET routes, 0 failures
Route inventory: 413 routes, 13 Radiology-related named routes
Vitest: 101 files, 421 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS (existing Browserslist/chunk warnings only)
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint --dirty: PASS
git diff --check: PASS
Canonical Playwright matrix: 15 passed, 1 stale-fixture-only skip
Isolated stale-state Playwright capture: 1 passed
```

The Playwright phase-gate specification visits `/radiology`, `/radiology/worklist`, `/radiology/modality`, `/radiology/reads`, `/analytics/radiology-tat`, and `/analytics/ir-utilization` in dark desktop and representative light tablet/mobile viewports. Every surface asserts its semantic heading and main region, selected theme, zero document-level horizontal overflow, zero console errors, and zero uncaught page errors. Additional checks prove a positive governed breach count, the degraded reads explanation, an honest bounded empty Study cohort, a registered stale-source cutoff, keyboard-openable worklist detail, visible focus, and keyboard theme switching.

Fourteen full-page screenshots were saved and manually inspected. Together they cover normal, breach, degraded, stale, and empty states, both themes, and 390/768/1024/1440 px widths. The audit reviewed hierarchy, contrast, status/freshness placement, filter affordances, breakpoint stacking, chart/table correspondence, horizontal containment, cross-link ownership, and synthetic/privacy boundaries. Only pseudonymous deterministic identifiers appear; no report narrative, direct identifier, credential, raw source message, or DICOM payload reaches the browser evidence.

Command-level OCEL reconciliation projected all 140 ancillary milestone rows to 140 distinct source references. The full 90-day log contained 717 events, 530 objects, 2,295 E2O links, 360 O2O links, and 142 object changes. Two consecutive bounded ancillary projections returned identical 140-event, 122-object, 566-E2O, 158-O2O, and 140-change results. Direct joins separately proved 97 Radiology OCEL events, one live discharge blocker, ten real ED-context exams, one real IR/OR link, and Radiology content in the gated Cockpit snapshot.

Populated query-plan evidence used `enable_seqscan=off` only to expose intended index selection on the small demo. The open worklist used `ancillary_orders_open_idx`; the bounded TAT cohort used `ancillary_orders_department_ordered_idx`; and the IR cohort used `rad_exams_ir_scheduled_idx`. All three were index-only paths except the expected incremental sort for the final IR tie-breaker. These are planner-path proofs, not production latency claims.

### Production-shaped migration rehearsal and rollback posture

Cloned the clean 74,143,411-byte, 95-migration test schema into disposable `zephyrus_r15_rehearsal`. The exact Radiology tail migrations `2026_07_12_000700`, `2026_07_12_000600`, and `2026_07_11_000500` rolled down in 36.09, 41.12, and 29.77 ms. Inspection proved the Radiology tables/TAT/IR indexes were absent while the shared ancillary order ledger remained. Forward migration completed in 26.05, 7.94, and 10.66 ms (0.20 s wall time; 71,308 KB maximum RSS).

After reference seeding, the disposable database contained seven Radiology tables, 49 constraints with zero unvalidated constraints, seven required indexes, six modalities, nine subspecialties, all 95 migration rows, and no mutation of the zero-row shared ledger. The database was dropped and confirmed absent.

Empty tail rollback is therefore rehearsed. Once production facts exist, the safe rollback is application-level route/feature withdrawal while preserving schema and facts, followed by a forward repair or verified restore; destructive populated rollback is not an approved mechanism. Existing migration tests enforce the shared append-only ledger guard and empty down/up behavior.

### Activation and limitations

- No production database, deployment, migration, connector, credential, source endpoint, feature flag, queue, scheduler, or external interface was accessed or activated.
- The only accepted command warnings are the pre-existing 104 UI-canon line-height findings, stale Browserslist database, existing large Vite chunk, and Playwright color-environment warning. No functional, invariant, privacy, or release failure is waived.
- The test database was reset after verification; final counts are zero ancillary orders, zero milestones, zero Radiology exams, and zero scenario-owned transport requests.
- R-15 completes Radiology. L-1 is now the next dependency-ordered implementation task and must build Lab, Pathology, and Blood Bank satellites on the completed shared spine without copying Radiology-specific behavior.

## 2026-07-12 — L-1 Laboratory, Pathology, and Blood Bank Satellites

### Outcome

Completed the first Laboratory-phase dependency by adding six governed PostgreSQL contracts: `hosp_ref.lab_test_catalog`, `prod.lab_specimens`, `prod.lab_results`, `prod.lab_critical_values`, `prod.ap_cases`, and `prod.bb_readiness`. The implementation stays on the shared ancillary order/source/encounter spine, uses the repository's real `prod.or_cases.case_id`, and does not add a parallel order ledger or an invented `or_case_id`.

The governed test catalog contains nine deterministic entries spanning chemistry, hematology, coagulation, microbiology, anatomic pathology, frozen section, type-and-screen, and crossmatch. It preserves stable UUIDv5 identities across replay and constrains decision classes to `ed_disposition`, `discharge_gate`, `or_gate`, and `none`. The Laboratory result satellite stores operational state, source version, local/LOINC identity, analyzer reference, flags, and timing only. It intentionally has no result-value, narrative, report-text, or clinical-payload column.

Specimen lifecycle supports pending collection, transit, receipt, rejection, and an explicit parent-to-child recollect chain. Results support preliminary/final/corrected flows and microbiology preliminary, organism-identification, susceptibility, and final stages. Critical values provide a timestamped notification/acknowledgement/escalation/closure loop. AP cases provide stage aging and frozen-section readiness against an actual OR case. Blood Bank readiness represents type-and-screen, crossmatch, allocation/issue, and active/closed MTP state with internally consistent unit counts.

Department and lineage triggers reject cross-department satellite attachment, encounter mismatch, a result tied to another order's specimen or correction parent, and clinical-result use of Pathology/Blood Bank catalog rows. Natural source identities are unique. Partial/composite indexes cover pending collection/receipt, pending decisions, open critical results, recollect lineage, AP stage/frozen OR readiness, blood-bank schedule readiness, and active MTP work.

### Models, factories, and rollback posture

Added six Eloquent models plus inverse relationships on `AncillaryOrder` and `ORCase`. Immutable timestamps and object-safe JSON casts preserve the database contract. Factories cover clinical-lab, microbiology, AP/frozen, critical-callback, T&S/crossmatch/issue, and MTP scenarios, including correction and recollect lineage.

The migration allows destructive down only in local/testing and only while every new fact table is empty. An empty down/up rehearsal removes and restores the satellite tail while retaining `prod.ancillary_orders`; a populated specimen makes down fail closed. Constraint installation is idempotent when PHPUnit rebuilds the `prod` search path while retaining the seeder-owned `hosp_ref` catalog. The guarded test baseline now explicitly truncates that catalog between PHPUnit processes.

### Verification

```text
Laravel Pint over L-1 implementation/tests: PASS
PHP syntax checks over all L-1 models/factories/tests: PASS
Focused L-1 plus ancillary reference contracts: 20 tests, 421 assertions, PASS
Complete ancillary feature regression: 126 tests, 1,582 assertions, PASS
Empty local/testing satellite down/up rehearsal: PASS
Populated satellite destructive-down refusal: PASS
Information-schema result-value/narrative exclusion check: PASS
```

No production database, connector, credential, scheduler, queue, route, UI, deployment, or external system was accessed or activated. L-2 is the next direct Laboratory dependency and will normalize Laboratory order and collection messages into these contracts.

## 2026-07-12 — L-2 Laboratory Order and Collection Ingestion

### Outcome

Completed the governed Laboratory order/collection path for HL7 v2 OML/ORM plus collection-bearing ORU messages and FHIR ServiceRequest/Specimen resources. The new normalizers are registered ahead of the generic ancillary fallbacks only for approved LIS, Laboratory middleware, Laboratory, EHR, and CPOE source classes whose profile explicitly authorizes the message family and Laboratory department.

The HL7 path now preserves ORC order control/status, placer and filler identities, OBR local/LOINC test coding, priority, PV1 patient class, SPM specimen/accession/type/container/method, explicit collector role, and collection timing. It parses true ORC/OBR blocks and their following SPM/OBX segments, so multiple tests and multiple specimens remain isolated. One message can emit one order event per OBR and a separate `LAB_COLLECTED` event for every explicitly collected specimen. OBX clinical values remain only in the governed raw inbound envelope and never enter canonical or Laboratory operational facts.

Collection semantics are assertion-driven. SPM-17 takes precedence and OBR-7 is the declared fallback. If both are absent, the projector creates a pending specimen but emits no `LAB_COLLECTED` milestone and leaves `collected_at` null. A later ORU with collection evidence updates the same source-scoped specimen and adds one collection milestone; exact replay returns the existing receipt without duplicating orders, facts, milestones, or provenance.

The FHIR path normalizes ServiceRequest identifiers, status, authored time, priority, subject/encounter, local/LOINC coding, and referenced specimens. Specimen normalizes relative or absolute ServiceRequest references, business identity, collection time, type, container, method, and explicitly coded nurse/lab collection role. ServiceRequest-first and Specimen-first arrival both converge. When Specimen arrives first, its collection time is explicitly labeled as an order-time fallback; a later ServiceRequest enriches priority, patient class, test coding, and authoritative ordered time without changing source identity or regressing collected state.

`LabOrderProjector` idempotently creates and enriches `prod.lab_specimens`, preserves advanced lifecycle states, retains the first asserted collection timestamp, records a conflicting later timestamp instead of overwriting it, and cancels only still-pending uncollected specimens. Every specimen projection records canonical/inbound provenance. Raw patient, encounter, and collector references are pseudonymized before canonical persistence, and unasserted collector roles remain null.

### Golden fixtures and verification

Seven HL7 fixtures cover ED STAT troponin, AM BMP, add-on CBC, cancelled order, missing collection time, ORU collection backfill, and a multi-OBR panel containing three specimens and deliberately ignored OBX values. FHIR coverage exercises both arrival orders, absolute request references, missing collection failure, local/LOINC coding, privacy, and replay.

```text
Focused L-2 pipeline: 8 tests, 122 assertions, PASS
Complete ancillary feature regression: 134 tests, 1,704 assertions, PASS
Existing shared multi-source reconciliation coverage: PASS
Existing Radiology order/result ingestion coverage: PASS
FHIR Specimen without collection assertion: fail closed and dead-lettered, PASS
Canonical direct-identifier and OBX-value exclusion checks: PASS
```

No production database, connector, credential, source endpoint, scheduler, queue, route, migration, deployment, or external system was accessed or activated. L-3 is the next dependency-ordered task and will add result/version/correction, rejection/recollect, microbiology progression, and critical-flag projection on top of these stable order/specimen identities.

## 2026-07-12 — L-3 Laboratory Results, Rework, Microbiology, and Critical Callbacks

### Outcome

Completed governed Laboratory result ingestion for HL7 v2 ORU and FHIR Observation/DiagnosticReport resources. The result normalizers sit ahead of the L-2 ORU fallback only when a message explicitly carries result status, OBR-14 receipt/status, SPM rejection, or SPM parent/recollect evidence. A collection-only ORU remains on L-2, preserving its exact replay and no-fabrication contract.

Each ORU OBR group now expands independently into collection, receipt, rejection/recollect, and one or more OBX result assertions. P/I/S emits preliminary; R emits resulted without claiming verification; F emits separate resulted and verified assertions; C appends a corrected result version; and D/X represents cancellation. OBR-7/SPM-17, OBR-14, OBX-14, OBX-19, OBR-22, and MSH fallback clocks retain their explicit source. The projector resolves the active governed local/LOINC catalog, links the projected specimen, and stores only operational status, stage, abnormal/critical flags, analyzer/middleware, auto-verification evidence, and timestamps.

Corrected results are append-only. A corrected key/version must find an earlier result with the same source key, and the new row links it through `parent_lab_result_id`. The original final row and timestamps remain unchanged. An orphan correction fails closed and is dead-lettered. Exact inbound replay does not add facts, milestones, or provenance.

Hemolysis uses SPM-21 to reject the original specimen, retain its collection/receipt history, and emit `LAB_REJECTED`. A child SPM with the original parent identity emits `LAB_RECOLLECT_ORDERED`, advances the rejected parent to recollect-requested, and creates a distinct pending child. The child does not inherit the original OBR-7 collection clock. Microbiology uses one stable source-result key with four appended versions for preliminary, organism identification, susceptibility, and final rather than flattening a multi-day process into one mutable status.

Critical flags and communication are deliberately separate. HH/LL/AA/critical interpretation creates a pending `lab_critical_values` row with `notification_asserted=false`; it does not emit callback milestones. Explicit governed structured notification and acknowledgement events carry source-result/version identity and advance the callback fact with chronological guards. Existing milestone-only demo events remain valid and bypass the satellite projector unless result detail is present.

FHIR Observation and DiagnosticReport map basedOn ServiceRequest, Specimen, local/LOINC code, effective/issued time, status/version, interpretation, analyzer device, and explicit auto-verification/microbiology extensions. Final resources emit resulted plus verified assertions; corrected resources append parented versions. Observation values, DiagnosticReport conclusion text, and HL7 OBX-5 values are never copied into canonical events or satellite facts.

### Verification

```text
Focused L-3 ingestion: 7 tests, 109 assertions, PASS
Complete ancillary feature regression: 141 tests, 1,813 assertions, PASS
Hand-calculated STAT TAT: 25m order-verify, 8m collect-receive, 15m receive-result, PASS
Explicit critical callback: 2m notify-ack with flag-only separation, PASS
Corrected HL7/FHIR parent lineage and orphan-correction refusal: PASS
Four-stage microbiology version history: PASS
Multi-OBX local/LOINC catalog isolation and replay: PASS
Clinical value/conclusion exclusion checks: PASS
Existing milestone-only critical demo compatibility: PASS
```

No production database, connector, credential, source endpoint, scheduler, queue, route, migration, deployment, or external system was accessed or activated. L-4 is the next dependency-ordered task and will generate coherent Laboratory, AP, microbiology, and Blood Bank demo facts on these now-proven contracts.

## 2026-07-12 — L-4 Coherent Laboratory, Pathology, Microbiology, and Blood Bank Demo

### Outcome

Replaced the five-order Laboratory placeholder with a deterministic, cross-domain demo cohort that exercises the L-1 through L-3 contracts as real operational facts. The ancillary refresh now produces 47 shared orders and 246 milestones across five governed departments: Radiology 16/97, Laboratory 14/84, Pathology 6/29, Blood Bank 6/13, and Pharmacy 5/23. Laboratory adds sixteen specimens, fourteen results, and two critical-value loops; Pathology adds six AP cases; Blood Bank adds six readiness requests.

The Laboratory cohort contains six AM draws, four ED orders, one live discharge gate, one live perioperative coagulation gate, two different rejection/recollect paths, and one historical blood-culture sequence. The AM wave spans pending collection through verification with five completed results and a fixed 40-minute median receipt-to-result interval. Two results are explicitly auto-verified. One chemistry result records a bounded analyzer downtime and reroute without introducing an ungoverned analyzer table.

`LabOrderProjector` now makes `LAB_IN_TRANSIT` a real specimen transition. It requires prior collection evidence, rejects a transit clock before collection, records `in_transit_at`, and advances eligible specimens to `in_transit`. This closed a genuine projection gap found while building the demo rather than encoding transport only in the milestone ledger.

Two specimen-quality branches preserve full lineage. The completed AM clot branch rejects the original, requests a new child, transports/receives the child, and reaches a verified result. The current hemolysis branch retains a pending recollect child. Owner-scoped cleanup deletes callbacks, results, recollect children, and parents in dependency order before replay, while foreign facts survive.

The critical cohort deliberately separates flag and communication evidence. One critical ED troponin remains preliminary with a pending-notification callback and blocks ED disposition. A second reaches final verification, notification, and read-back acknowledgement with monotonic timestamps. No result value or narrative enters the operational contract.

Microbiology uses one stable source-result key with four versions for preliminary, organism identification, susceptibility, and final. Every version ends before the live 24-hour window and carries `historical_study_only`, so Study can exercise multi-day progression without making the operational board claim that an old culture is current work.

Pathology and Blood Bank are now first-class shared-spine departments rather than mislabeled Laboratory orders. The AP generator creates four overnight stages plus one live and one resulted frozen section. The Blood Bank generator creates ordered, testing, type-and-screen-ready, crossmatch-ready, issued, and active-MTP states. Every AP/Blood Bank row links an existing non-deleted OR case. Blood Bank `needed_by` derives from that case's scheduled start. Every non-ready Blood Bank row and the active frozen section name the exact OR case blocked and explain the gate.

Clinical-lab decision metadata likewise resolves exactly three live pending decisions: a real ED visit for disposition, a real expected-discharge encounter, and a real OR case. These are asserted against their source tables, not accepted as opaque JSON identifiers.

### Invariants and verification

Added seven ancillary invariant gates:

- exact-owner Laboratory specimen/result/callback parentage;
- complete same-order recollect lineage;
- critical callback/result and timestamp consistency;
- exactly three valid clinical-lab downstream decision links;
- valid AP/Blood Bank shared-order and OR-case links with explanations on every pending gate;
- four-stage, entirely historical microbiology progression;
- fixed AM distribution plus exactly one analyzer downtime reroute.

```text
Focused L-4 feature gate: 1 test, 38 assertions, PASS
Radiology generator regression with the expanded registry: 1 test, 51 assertions, PASS
Complete ancillary demo scenario regression: 7 tests, 37 assertions, PASS
Combined demo generator/scenario regression: 9 tests, 126 assertions, PASS
Complete ancillary feature regression: 141 tests, 1,813 assertions, PASS
Same-anchor semantic replay and summary equality: PASS
Foreign Laboratory/AP/Blood Bank row survival: PASS
All 20 ancillary invariants: zero failed critical findings, PASS
Laravel Pint over the L-4 implementation/tests: PASS
git diff --check: PASS
```

No production database, connector, credential, source endpoint, scheduler, queue, route, migration, deployment, or external system was accessed or activated. L-5 is the next dependency-ordered task and can now build the Lab Flow Board against a coherent current-operation cohort rather than mocked queue counts.

## 2026-07-12 — L-5 Laboratory Flow Board

### Outcome

Implemented the first Laboratory operational surface at `/lab` on the shared ancillary spine and L-4 demo cohort. `LabFlowBoardService` owns the complete browser contract: current-window selection, stage distribution, open work, STAT compliance, pending downstream decisions, callback state, pre-analytic quality, feed coverage, TAT distributions, filters, freshness, degraded/error state, bounded drill rows, barrier Pareto, governed SLA definitions, and privacy projection.

The live board intentionally excludes the four-stage `historical_study_only` microbiology history and all orders outside the 24-hour operational window. Against the fixed L-4 cohort, it reports 13 current Laboratory orders, eight still active at the selected projection, three STAT orders, one verified within the 60-minute governed breach threshold (33.3% compliance), three exact downstream decision blockers, and one open critical callback. All ratios and statuses arrive precomputed from the server.

Collection-to-receipt and receipt-to-result are separate count/median/p90 contracts. Transport and middleware evidence are detected independently. When either optional feed is absent, the board retains the coarser observable interval, labels its granularity `coarse`, explains the missing segment, and leaves unavailable values null. It never converts missing transit or analysis evidence to a zero-minute duration.

The pre-analytic strip computes specimen rejection, hemolysis, and contamination from current specimen facts. Each measure carries an explicit reference kind. Rejection and hemolysis are labeled as external benchmarks; contamination is labeled as local policy. Because no governed target value is configured yet, the visible labels say `External benchmark not configured` and `Site policy not configured`, and the assistive source explanation states that the observed rate is not judged against an invented threshold.

Filters cover ED, inpatient, discharge gate, OR gate, degraded feed, priority, test family, unit, and shift. The oldest-active drill exposes current stage, age, pseudonymous context, location, existing barrier count, and the exact decision explanation from the L-4 result metadata. Patient context is replaced with `Patient context restricted` unless the established capability permits detail.

Laboratory barrier annotation reuses the established ancillary policy and audit model. `/api/lab/barriers` accepts only active Laboratory reason codes, requires an encounter-linked Laboratory order, opens the shared barrier, links an open ancillary breach when present, and records `ancillary.barrier.opened` in `audit.user_events`. Unauthorized writes fail before mutation.

The new `/lab`, `/api/lab/flow-board`, and `/api/lab/barriers` routes are authenticated and named. A Laboratory workspace domain now lives in `navigationConfig.ts`, making it the one source for desktop/mobile navigation, command palette projection, active-route ownership, and domain-local menus. The page uses the canonical dashboard/page layout, strict Zod parsing, source-freshness badge, responsive filters, dual-theme healthcare tokens, accessible announcements and reference labels, and a keyboard-operable Radix barrier drawer.

### Verification

```text
Focused Lab Flow Board backend: 4 tests, 57 assertions, PASS
Complete ancillary feature regression: 145 tests, 1,870 assertions, PASS
Focused Lab Flow Board + navigation Vitest: 2 files, 29 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS (existing Browserslist and large-chunk warnings only)
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint over L-5 PHP implementation/tests/routes: PASS
Mobile dark browser smoke, 390x844: HTTP 200, semantic h1/main, no overflow, no console/page errors
Desktop light discharge-gate smoke, 1440x1000: HTTP 200, selected lens, visible benchmark labels, no overflow, no console/page errors
```

No production deployment, production database, connector, credential, source endpoint, scheduler, queue, migration, or external system was accessed or activated. L-6 is the next dependency-ordered task and will add the per-specimen tracker on the same filters, timing semantics, and privacy boundary.

## 2026-07-12 — L-6 Laboratory Specimen Tracker

### Outcome

Implemented the authenticated Laboratory Specimen Tracker at `/lab/specimens` with a matching private `/api/lab/specimens` contract. `LabSpecimenService` owns current-window selection, server filters, deterministic cursor pagination, freshness and degraded states, accession identity, event timelines, operational result state, arbitrary-depth recollect lineage, downstream-decision deduplication, and the browser privacy projection.

The tracker selects Laboratory orders from the trailing 24-hour operational window and excludes `historical_study_only` microbiology progression without deleting those Study-ready facts. Each specimen remains a distinct row even when an order has multiple specimens. Its stable ordering pair is the evidenced collection timestamp, falling back to order time, plus the numeric specimen key. Opaque next/previous cursors preserve that order and reject unexpected cursor shapes.

Every row exposes source-scoped accession/specimen identities and an ordered timeline for order, collection, optional transport, receipt, rejection, recollect request, result, and verification. The transport segment is genuinely optional. When no current specimen has transit evidence, the service marks transport coverage missing, the page removes the transit stage and responsive grid column, and a written degraded-mode notice says that no zero-minute segment was inferred.

Result projection is deliberately operational rather than clinical. The browser receives catalog label, result status and stage, abnormal classification, auto-verification and critical flags, result/verify/correction timestamps, and version count. It does not receive numeric or textual result values, interpretations, narratives, or raw source payloads. Direct patient identifiers are always absent. Authorized patient context uses the existing source-scoped pseudonym; roles without ancillary patient-detail capability receive a redacted label.

Recollect reconciliation batch-loads all sibling specimens for the selected orders, resolves each chain's root, depth, position, parent, children, and deepest/current representative, and supports chains longer than the demo's parent/child pairs. A pending ED, discharge, or OR decision is moved to the chain representative and rendered there exactly once. Other members identify the representative without duplicating the decision. A synthetic three-node test proves that a decision originating on the rejected root follows the chain to the active leaf.

Server filters cover specimen status, test family, unit, priority, rejection/recollect state, and defined age bands. Filter options and page rows are populated with bounded set queries. Results, catalog context, all sibling specimens, and pending decisions are batch-loaded; query count remains constant between five-row and fifteen-row pages. PostgreSQL `EXPLAIN` confirms an existing `lab_specimens_*_idx` access path for a filtered tracker query.

The React page uses the canonical dashboard layout, freshness badge, responsive healthcare-theme tokens, accessible filter and pagination labels, explicit normal/degraded/no-data/stale/source-error messages, long-identity wrapping, strict Zod parsing, and a 30-second query refresh. `navigationConfig.ts` remains the sole navigation authority and now projects both Laboratory Flow Board and Specimen Tracker across desktop, mobile, command palette, active ownership, and local navigation.

### Verification

```text
Focused Laboratory Specimen Tracker backend: 4 tests, 58 assertions, PASS
Complete ancillary feature regression: 149 tests, 1,928 assertions, PASS
Specimen Tracker + Flow Board + navigation Vitest: 3 files, 32 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS (existing Browserslist and large-chunk warnings only)
scripts/check-ui-canon.sh: PASS (pre-existing arbitrary-line-height warnings only)
Laravel Pint over the L-6 PHP implementation/tests/routes: PASS
Mobile dark browser smoke, 390x844: HTTP 200, semantic h1/main, no overflow, no console/page errors
Desktop light browser smoke, 1440x1000: HTTP 200, semantic h1/main, no overflow, no console/page errors
git diff --check: PASS
```

No production deployment, production database, connector, credential, source endpoint, scheduler, queue, migration, or external system was accessed or activated. L-7 is the next dependency-ordered task and can now join pending result work to the same specimen lineage and downstream-decision contract.

## 2026-07-12 — L-7 Laboratory Decision-Pending Results

### Outcome

Implemented the authenticated Laboratory Decision-Pending Results surface at `/lab/pending-decisions` with a matching private `/api/lab/pending-decisions` contract. `LabDecisionPendingService` is now the single server authority for the pending cohort, effective catalog selection, live destination validation, explicit impact ranking, SLA selection, freshness qualification, cross-domain aggregate, privacy projection, filters, drills, and exclusion diagnostics.

The cohort is intentionally stricter than a query for unverified result rows. Each candidate must be in the current 24-hour Laboratory operational window, resolve an active effective-dated test-catalog entry, carry a catalog decision class other than `none`, and have matching source-projected gate evidence. The service accepts a result `decision_context` only when its decision class, object type, and positive object ID match the catalog class. Before a result exists, it can use the corresponding order linkage (`ed_visit_id`, encounter ID, or `or_case_id`). It never infers impact from the test label or catalog class alone.

Latest-version semantics keep correction and completion behavior coherent. A lateral query selects the newest result for the order/catalog pair. A verified or cancelled latest assertion is excluded; a later unverified correction can remain pending. Result and specimen UUIDs are nullable so an explicitly gated order may enter before the first result assertion without fabricating an object. Historical microbiology remains outside the live queue.

Downstream validation is class-specific. ED disposition requires an active non-deleted visit without departure. Discharge impact requires the exact active non-deleted encounter, an expected discharge date, and no completed discharge. OR impact requires the exact non-deleted source-linked case. Malformed, mismatched, absent, inactive, completed, or deleted destinations are withheld from the ranked queue and counted as unresolved. The page explains degraded state and says no gate was inferred.

The fixed demo produces exactly three visible gates. The current OR coagulation result ranks first. The discharge metabolic panel ranks second and carries an explicit one-bed impact. The ED critical troponin ranks third. Within an impact class, descending age wins, then governed priority, then stable ancillary-order identity. Every row returns its numeric impact and priority ranks, stable sort key, displayed position, and written rationale.

SLA state also remains server-owned. The service selects the most specific active `item_clock` definition ending at `LAB_VERIFIED`, using priority, patient class, and definition scope. The OR result selects the generic `lab.stat_tat` policy and is warning at 55 minutes. The discharge result has no matching configured item clock and says `unconfigured`; it does not borrow an unrelated threshold. The 85-minute ED troponin selects the more-specific `lab.troponin_order_result` policy and is breached. A stale source demotes every item urgency to stale, while a registered source error makes the page source-error.

`destinationAggregates` establishes the exact cross-domain consumer contract needed by L-11 without prematurely editing the ED/discharge UI in this tranche. Each destination aggregate carries class and destination ID, pending count, oldest age, top order UUID, destination drill, and exact result UUID membership. Focused tests prove every visible department-queue result reconciles to one aggregate. L-11 can build readiness axes from these facts without re-querying or recreating queue rules.

Each row shows only operational state: test/catalog identity, pseudonymous patient context, unit, priority, selected milestone, result status/stage/critical and abnormal classifications, order age, effective SLA, destination, decision explanation, source cutoff, and barrier count. Clinical result values, interpretation, narrative, raw payload, direct patient identifiers, and credentials are absent. Roles without ancillary detail capability receive `Patient context restricted`.

Decision-class, priority, unit, SLA-state, and bounded-limit filters are server validated. Exclusion diagnostics separately count non-gating catalog work, completed/cancelled gates, and invalid destinations. An exact `orderUuid` filter was added to the L-6 Specimen Tracker so each decision's specimen drill returns only that order and its recollect lineage. Destination drills point to the established ED Treatment, RTDC Discharge Priorities, or Operations Case Management route.

The pending surface registers no mutation. POST is method-not-allowed. Authorized encounter-linked rows reuse the already governed and audited Laboratory barrier drawer and `/api/lab/barriers`; verification, acknowledgment, order changes, destination changes, and source-system commands remain impossible. `navigationConfig.ts` now projects Flow Board, Specimen Tracker, and Decision-Pending Results from the single Laboratory workspace definition.

### Verification

```text
Focused Laboratory Decision-Pending backend: 4 tests, 83 assertions, PASS
Complete ancillary feature regression: 153 tests, 2,011 assertions, PASS
Decision-Pending + Specimen Tracker + Flow Board + navigation Vitest: 4 files, 35 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS (existing Browserslist and large-chunk warnings only)
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint over the L-7 PHP implementation/tests/routes and exact specimen drill extension: PASS
Mobile dark browser smoke, 390x844: HTTP 200, semantic h1/main, empty contract, no overflow, no console/page errors
Desktop light browser smoke, 1440x1000: HTTP 200, semantic h1/main, empty contract, no overflow, no console/page errors
Populated, degraded, and empty rendered contracts: PASS in Vitest
git diff --check: PASS
```

No production deployment, production database, connector, credential, source endpoint, scheduler, queue, migration, or external system was accessed or activated. L-8 is the next dependency-ordered task and can build Blood Bank readiness against the now-proven OR gate/destination pattern.

## 2026-07-12 — L-8 Blood Bank Readiness and Perioperative Gates

### Outcome

Implemented the authenticated Blood Bank Readiness workspace at `/lab/blood-bank` with a matching private `/api/lab/blood-bank` read contract and case-specific drill. `BloodBankReadinessService` is the single authority for operating-day selection, request aggregation, compatibility/readiness state, schedule arithmetic, freshness, coverage, filters, privacy projection, and the compact gate consumed by Perioperative Case Management.

The operating cohort now follows the actual OR schedule rather than wall-clock coincidence. For an exact `caseId`, the service uses that case's surgery date. Otherwise it selects the latest non-deleted operating date at or before the current clock, with the earliest future operating date as a bounded fallback. The Blood Bank demo generator uses the same rule and selects six cases from one operating day, fixing the earlier fallback that could attach requests to the oldest cases while Case Management displayed the latest scheduled day.

The matrix starts from every non-deleted case on the operating date, not only cases with `prod.bb_readiness` rows. Each gate carries the case and schedule context, room, service, location, planned duration, signed minutes-to-start, upcoming or past-due state, source cutoff, freshness, coverage, and exact drill. Active requests add product class, requested/allocated/issued units, T&S, crossmatch, issue and readiness state, ordered and needed-by timestamps, compatibility milestone timestamps, expiry, MTP lifecycle, source identity, and stable request/order UUIDs. Clinical details and direct patient identifiers remain absent.

Readiness is derived from requirement plus evidence. Cancelled requests do not create a requirement. A complete request is ready; otherwise T&S and crossmatch must be ready or explicitly not required, with an allocation/issue-ready lifecycle state. Any unresolved active requirement is blocked. A case with no active request is `not_applicable`, not required, not ready, and non-blocking; absence is never presented as successful readiness. Stale evidence demotes a case to non-blocking unknown. A needed-by timestamp that does not align with the selected case start produces degraded coverage rather than silently trusting inconsistent schedule evidence.

Active massive-transfusion work is represented as `mtp_active`: a distinct blocking operational condition with request evidence and an explicit explanation. It is deliberately not an activation, allocation, issue, closure, or clinical-command surface. When the MTP closes, the special state clears while any still-unresolved product requirement remains blocked. Cancelling the only active request removes the requirement and produces not-applicable state.

`CaseManagementService` now batch-loads the same gate contract for every procedure on its selected operating day. `CaseTracker` renders the compact linked state in each row, and `CareJourneyCard` renders the same state in the case journey. The previous hard-coded `Labs / Action Required` journey item was removed, eliminating the false flag on cases without a Blood Bank requirement. Focused parity assertions compare every workspace gate with the exact object on its Perioperative procedure.

The React workspace uses the canonical dashboard layout, strict Zod contracts, freshness badge, summary counts, server filters for state/product/service/room/case, responsive request evidence, signed schedule clocks, explicit normal/degraded/no-data/stale/source-error messages, and a 30-second private API refresh. `navigationConfig.ts` remains the only navigation authority and now adds Blood Bank Readiness as the fourth Laboratory operations leaf.

The implementation is query-bounded. Cases and related labels are selected once, active requests and source cutoffs are loaded in batches, and `forCases()` takes the same query count for one case as for the full board. The focused budget remains at four queries, and PostgreSQL `EXPLAIN` confirms `bb_readiness_pending_or_idx` for pending-case readiness access.

### Verification

```text
Blood Bank demo generator/scenario regression: 8 tests, 75 assertions, PASS
Focused Blood Bank backend: 4 tests, 122 assertions, PASS
Complete ancillary feature regression: 157 tests, 2,133 assertions, PASS
Blood Bank + Decision-Pending + Specimen Tracker + Flow Board + navigation Vitest: 6 files, 42 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS (existing Browserslist and large-chunk warnings only)
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint over the L-8 PHP implementation/tests/routes: PASS
Mobile dark Blood Bank smoke, 390x844: HTTP 200, semantic h1/main, no overflow, no allocation controls, no console/page errors
Desktop light Blood Bank smoke, 1440x1000: HTTP 200, semantic h1/main, no overflow, no allocation controls, no console/page errors
Desktop light Perioperative Case Management smoke, 1440x1000: HTTP 200, semantic h1/main, no overflow, no allocation controls, no console/page errors
Midnight +30/-1 minute arithmetic, freshness demotion, needed-by degradation, MTP close, cancellation, and exact cross-surface parity: PASS
One-case versus full-board query-count parity and bb_readiness_pending_or_idx plan: PASS
```

No production deployment, production database, connector, credential, source endpoint, scheduler, queue, migration, allocation, writeback, or external system was accessed or activated. L-9 is the next dependency-ordered task and can add Anatomic Pathology aging and frozen-section timers on the shared Laboratory/OR foundation.

## 2026-07-12 — L-9 Anatomic Pathology Case Aging and Frozen-Section Timer

### Outcome

Implemented the authenticated Anatomic Pathology Case Aging workspace at `/lab/anatomic-path` with a matching private `/api/lab/anatomic-path` read contract and exact OR-case drill. `AnatomicPathologyService` is the single authority for the bounded operational cohort, stage evidence, age calculations, cohort classification, structural workflow stages, established benchmark references, AP-LIS/backfill coverage, freshness, filters, privacy projection, and the frozen-section timer consumed by Perioperative Case Management.

The operational board covers seven days so multi-day histology work and a recently signed-out case remain visible. Cancelled facts stay out. Exact `caseId` drills bypass the window without bypassing source validation. Each row carries source-scoped AP case/accession identity, OR linkage, procedure/cohort, current stage and timestamp, current-stage age, total age, deterministic age band, and a six-stage received→grossed→processing→slides-ready→diagnosed→signed-out timeline. Missing intermediate evidence remains not asserted or pending; it is never synthesized.

The deterministic AP demo now distinguishes two routine, one complex, one consult/send-out, and two frozen-section cases. The first four carry explicit processing models. Three expose the overnight histology batch and the consult case exposes its external handoff as a structural branch. This makes the overnight floor explainable instead of presenting it as idle delay. Procedure codes and labels likewise distinguish routine, complex, consult/send-out, and frozen work.

The demo's OR joinery was corrected alongside the surface. It now selects all six links from one operating day and chooses the active frozen case from the exact 30–75% Procedure band used by `CaseManagementService`, instead of the previous earliest-case fallback that placed it in Recovery. The resulted frozen case remains source-visible but has no timer.

Three reference lines reproduce the source brief's established CAP guidance: P90 routine AP final within two days, P90 complex AP final within three days, and P90 single-block frozen section within 20 minutes. Every line carries the source-brief section, applicability, and the statement that it is an established reference—not universal or local policy. Routine/complex working-day semantics must be governed locally before any scoring, and the service assigns no breach/success state from these references.

Coverage is explicit and independent. Selected rows must resolve to governed `ap_lis` sources. When a source declares bulk support, each source must have a successful `bulk_backfill` watermark before the page claims historical completeness. Backfill `not_configured` is neutral and written plainly; a missing watermark or AP-LIS classification is degraded. Stale/error source evidence leaves last-known stages visible but suppresses live timers.

`FrozenSectionTimer` advances from the server elapsed value, uses a stable-width tabular clock, links to the exact AP case, and explains that it does not replace pathology communication. `CaseManagementService` asks for timers only for procedure rows whose derived phase is `Procedure`; the service additionally requires a valid non-deleted OR link, in-progress status, start evidence, no result, and fresh source evidence. The same object renders in the case tracker and care journey. Unlinked, Pre-Op, Recovery, stale, cancelled, and resulted work receives no timer.

The React workspace uses the canonical dashboard layout, strict Zod contracts, freshness badge, stage/cohort/status/age filters, summary cards, established-reference cards, coverage messaging, responsive stage timelines, structural-stage callouts, and 30-second private API refresh. `navigationConfig.ts` remains the only navigation authority and now adds AP Case Aging as the fifth Laboratory operations leaf.

The privacy and action boundary remains read-only. The browser receives no direct patient identifiers, diagnosis, narrative, pathologist identity, raw payload, credentials, result/sign-out control, or source-system writeback. Source-scoped accession identity and the OR case reference are the only operational identifiers.

### Verification

```text
AP demo generator/scenario regression: 8 tests, 75 assertions, PASS
Focused Anatomic Pathology backend: 4 tests, 74 assertions, PASS
Complete ancillary feature regression: 161 tests, 2,207 assertions, PASS
AP + Blood Bank + Decision-Pending + Specimen Tracker + Flow Board + navigation Vitest: 7 files, 46 tests, PASS
npx tsc --noEmit: PASS
npm run build: PASS (existing Browserslist and large-chunk warnings only)
scripts/check-ui-canon.sh: PASS (104 pre-existing arbitrary-line-height warnings only)
Laravel Pint over the L-9 PHP implementation/tests/routes: PASS
Mobile dark AP smoke, 390x844: HTTP 200, semantic h1/main, evidence-labeled references, no overflow, no write controls, no console/page errors
Desktop light AP smoke, 1440x1000: HTTP 200, semantic h1/main, evidence-labeled references, no overflow, no write controls, no console/page errors
Desktop light Perioperative Case Management smoke, 1440x1000: HTTP 200, semantic h1/main, no overflow, no console/page errors
239→241 minute age-band transition, terminal completion, active/unlinked/resulted frozen lifecycle, stale/error suppression, and exact Perioperative parity: PASS
One-case versus fifty-case query-count parity, AP-LIS/backfill coverage lifecycle, and ap_cases_stage_aging_idx plan: PASS
```

No production deployment, production database, connector, credential, source endpoint, scheduler, queue, migration, diagnosis/sign-out action, writeback, or external system was accessed or activated. L-10 is the next dependency-ordered task and can add Laboratory critical-value and health metrics to the existing Cockpit Flow domain.
