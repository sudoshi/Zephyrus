# Zephyrus Ancillary Services Expansion: Implementation Plan and TODO

| Field | Value |
| --- | --- |
| Document ID | ACUM-ENG-ANC-001-IMPL |
| Date | 2026-07-11 |
| Status | Implementation in progress; shared P0, Radiology R-1 through R-15, and Laboratory L-1 through L-6 complete; production connector activation remains governance-gated |
| Source brief | docs/Zephyrus_Ancillary_Expansion_Plan.pdf, 37 pages |
| Scope | Shared ancillary milestone spine, Radiology, Pathology and Laboratory, Inpatient Pharmacy, cross-module readiness, Cockpit, Study analytics, process intelligence, demo data, integration, validation, and release |
| Backlog size | 60 dependency-ordered implementation tasks: 10 shared, 15 Radiology, 14 Lab, 14 Pharmacy, 7 predictive and polish |
| Progress | 31 of 60 tasks complete; 29 remain |
| Primary outcome | **Where is the order stuck, whose patient is it blocking, and what barrier clears it?** |

---

## 1. Executive implementation decision

Build the expansion as one reusable, provenance-preserving ancillary work-item spine with three department satellites. Zephyrus remains a read-mostly operational intelligence layer. It does not replace or write back to the RIS, PACS, LIS, AP-LIS, blood-bank system, pharmacy information system, ADC, IV workflow system, BCMA, or EHR. The only user-authored mutations in the initial scope are Zephyrus-owned barrier annotations and governed SLA/reference configuration.

The implementation has four inseparable parts:

1. **Canonical facts:** raw source messages become canonical integration events, then append-only ancillary milestones and department satellites.
2. **Operational clocks:** effective-dated, data-driven SLA definitions calculate warning state, breaches, clear transitions, queue aging, and downstream impact.
3. **Cross-domain joins:** every resolvable ancillary work item links to the existing encounter, ED state, discharge priorities, OR schedule, barriers, Cockpit, and OCEL process landscape.
4. **Demo and live parity:** every screen ships against coherent, invariant-checked demo data. A real feed upgrades provenance and milestone fidelity without changing UI contracts.

The PDF’s proposed five phases are retained. Its 60 task IDs are also retained so the PDF, this plan, issues, commits, tests, and release evidence can share one vocabulary.

### 1.1 Definition of done for the whole program

- Radiology, Lab, and Pharmacy workspaces render useful, coherent data with no external interfaces configured.
- The same pages render connector-backed data without a separate frontend path.
- Every displayed clock names its start milestone, stop milestone, population, percentile, freshness, and source.
- Conflicting source assertions remain available for audit; the selected assertion follows an explicit source-precedence policy.
- ED, RTDC discharge, Perioperative, the ancillary summary, Cockpit, and Study surfaces reconcile to the same ancillary projection.
- Warning and breach treatments come from persisted definitions, not JSX constants.
- No raw HL7, DICOM metadata containing direct identifiers, NDC transaction payload, warehouse clinical extract, or source credential reaches the browser or logs.
- The demo refresh remains idempotent, owner-scoped, one-clock, ledgered, and invariant-gated.
- OCEL receives ancillary object/event projections and the existing Arena jobs can produce performance and conformance evidence for the registered process families.
- All release gates in section 18 pass, the schema change is rehearsed on a production-shaped database, and deployment follows only deploy.sh plus explicit migrations.

---

## 2. Source-document findings translated into requirements

The PDF establishes these product truths:

| Finding | Implementation consequence |
| --- | --- |
| Imaging, lab, and medication readiness are hidden drivers of ED LOS, discharge delay, and OR delay. | Encounter and OR-case joinery is mandatory in the first department release, not a later analytics enhancement. |
| All three workflows are ordered things moving through timestamps. | One shared work-item and milestone model precedes department tables and pages. |
| A TAT value is meaningless without its clock definition. | Every metric references a persisted SLA definition and exposes the definition in the UI. |
| The differentiator is downstream impact, not department queue depth alone. | Worklists require discharge-blocking, ED-decision, and OR-gate lenses. |
| Optional feeds vary widely. | Every page and metric has a declared minimum milestone set and degraded mode. |
| Status-dense screens create alarm-fatigue risk. | Cockpit shows aggregates and oldest age only; per-item breach styling stays inside authorized workspaces. |
| Demo-first is a product and CI requirement. | Generators and invariants are phase prerequisites, not post-build fixtures. |
| Administration timestamps may be nightly. | Administration-derived metrics are always labeled with source cutoff and never called real-time. |
| Diversion analytics is regulated-adjacent. | Pharmacy exposes unit/station operational outliers only; no individual risk score or accusation is permitted. |
| Cross-module readiness is the platform moat. | The discharge readiness vector accretes imaging, lab, then medication axes in the same order as the PDF. |

### 2.1 Scope included

- Imaging order, preparation, transport, exam, PACS, read, critical-result, and follow-up milestones.
- Clinical lab order, collection, transport, accession, analysis, result, verification, critical callback, rejection, and recollect flows.
- Anatomic pathology stage aging, frozen-section timing, microbiology progression, and blood-bank readiness.
- Medication order, verification queue, preparation branch, dispense, delivery, administration freshness, ADC events, discharge medications, and discrepancy aging.
- Operational and Study workspaces, Cockpit measures, RTDC/ED/Periop joins, barrier integration, benchmark registry, and predictive sort/planning aids.
- HL7 v2, forwarded MPPS events, optional vendor events, FHIR/backfill contracts, and warehouse batch contracts.

### 2.2 Explicitly excluded from this program

- Clinical order entry, result acknowledgment writeback, label reprint, scanner control, pharmacy verification, dispense authorization, MAR writeback, or any other source-system command.
- Diagnostic interpretation, clinical decision support, or medication recommendations.
- Individual diversion-risk scoring or workforce performance scoring.
- Replacing PACS/RIS/LIS/pharmacy middleware or becoming an MLLP routing engine.
- Outpatient imaging access/template optimization in Phase 1; it is a separately approved follow-on unless decision DEC-3 changes.
- Respiratory therapy, dialysis, rehabilitation, dietary, and other fourth-wave ancillary modules; the spine must support them, but their satellites and screens are not in these 60 tasks.

---

## 3. Current repository baseline and reconciliation

The plan is grounded in the repository state inspected on 2026-07-11.

### 3.1 Existing seams to reuse

| Need | Current source of truth | Implementation decision |
| --- | --- | --- |
| Ancillary summary | app/Services/Rtdc/AncillaryServicesService.php and resources/js/Pages/RTDC/AncillaryServices.jsx | Replace deterministic wait synthesis incrementally with spine aggregates; preserve the current route as the cross-department consumer view. |
| Discharge candidates | app/Services/Rtdc/DischargePrioritiesService.php | Add a readiness object to each returned patient; do not create a second discharge cohort. |
| Navigation | resources/js/config/navigationConfig.ts | Add Radiology, Lab, and Pharmacy as Workspaces domains and Study leaves under Analytics. There is no current DashboardContext mirror to update. |
| Authenticated web routes | routes/web.php under SessionAuthMiddleware | Add all Inertia page routes inside the existing authenticated group. Do not touch the protected authentication flow. |
| Browser APIs | routes/api.php groups using web, auth, and throttle middleware | Add read endpoints for polling and filtered worklists; barrier writes use an explicit policy and existing BarrierService pattern. |
| Raw/canonical integration | raw.inbound_messages, integration.canonical_events, provenance_records, watermarks, dead_letters, replay jobs | Extend the current ledger. Do not create a parallel raw or canonical store. |
| Connector contracts | SourceMessageNormalizer, CanonicalEventMapper, ProjectionHandler, CanonicalEventWriter | Implement ancillary normalizers/mappers/projector through these contracts. |
| Existing HL7 utility | app/Services/PatientFlow/Hl7V2Message.php | Extract or extend shared field/repetition/timestamp helpers; do not maintain two unrelated HL7 parsers. |
| Demo clock and gate | DemoClock, DistributionSampler, DemoInvariantService, DemoRefreshCoordinator | Add an ancillary refresh step and ancillary invariant family to the canonical coordinator. |
| Cockpit | SnapshotBuilder, FlowMetrics, CockpitKpiDefinitionSeeder, DomainGrid, DrillBuilder | Add ancillary metrics to the existing Flow domain and drill. Do not create standalone client-computed RadiologyTile/LabTile/PharmacyTile components. |
| Barriers and improvement | prod.barriers, Barrier model/service, PDSA surfaces | Store domain detail as reason codes while preserving the existing four high-level categories. |
| OCEL and Arena | ocel schema, OcelProjector, HospitalProcessCatalog, Arena jobs | Project ancillary objects/events into the existing OCEL tables and align with D1, D5, D6, D7, D11, and D12. |
| UI system | PRODUCT.md, DESIGN.md, Surface/Card/Panel, PageContentLayout, scripts/check-ui-canon.sh | Build all new pages in the existing blue/slate, earned-urgency, dual-theme system. |

### 3.2 Corrections to the PDF implementation appendix

These are deliberate repository-fit corrections, not product-scope reductions:

1. The actual canonical table is **integration.canonical_events** and the model is CanonicalEventRecord. There is no integration.canonical_event_records table.
2. The actual provenance primary key is **provenance_record_id**, not provenance_id.
3. ReplayPendingIntegrationEvents is currently bound to RtdcProjectionHandler. It must become a projection dispatcher/registry before ancillary event replay can be correct.
4. OperationalDemoDataService currently owns staffing and transport scenario data, while DemoRefreshCoordinator orchestrates the full rolling scenario. Ancillary generation must be an explicit coordinator step with ownership tags; merely adding methods to OperationalDemoDataService would leave scheduled refresh incomplete.
5. The current Cockpit is server-snapshot driven. New metrics belong in a metrics provider, KPI definitions, Flow drill table, and tests. Client-computed standalone tiles would violate the single-snapshot discipline.
6. Route ownership is tested from navigationConfig.ts. The plan must update those tests and the fixed workspace-domain expectations.
7. The existing Barrier category constraint permits only medical, logistical, placement, and social. Ancillary detail therefore uses governed reason codes mapped to one of those categories instead of adding arbitrary categories.
8. Production append-only ledgers make destructive rollback unsafe after data exists. Migration testing must cover fresh up/down locally, while production rollback is forward repair plus backup restoration, never casual down migration.
9. EpicSmartFhirClient and the scheduled FHIR poller currently allow only Encounter and Location. Ancillary FHIR resources and SMART scopes must be added through a reviewed allowlist/capability extension; the PDF’s FHIR/Bulk posture is not already implemented.

### 3.3 No-change boundaries

- Existing unrelated changes in Cockpit files and tests are outside this planning artifact.
- Protected auth files listed in .claude/rules/auth-system.md are outside scope.
- Existing routes and API contracts remain additive and backward compatible.
- The current Ancillary Services URL, RTDC route names, and Discharge Priorities payload fields remain valid while new fields and drill links are added.

---

## 4. Planning defaults and owner decisions

These defaults permit Phase 0 and demo implementation to proceed. A decision may change a later bounded slice but must not fork the spine.

| ID | Decision | Recommended default | Must be final by |
| --- | --- | --- | --- |
| DEC-1 | Design-partner EHR and ancillary stack | Epic-first message shapes, standards-first contracts, vendor-neutral canonical events | Before live connector configuration |
| DEC-2 | IR ownership | Radiology workspace owns operational IR queue; Study reuses Perioperative calculation components and definitions | Before R-13 |
| DEC-3 | Outpatient imaging access | Defer no-show/template/access optimization; retain patient_class support in schema | Before R-8 final scope |
| DEC-4 | Demo story | Stage department releases, while Phase 0 seeds a minimal coherent spine for all three | Before Phase 1 screenshots |
| DEC-5 | Real-time transport source | Reuse existing Zephyrus transport milestones when encounter/order linkage is resolvable; accept vendor milestones as additional assertions | Before R-4/R-7 |
| DEC-6 | SLA governance owner | Department operations leader proposes, platform administrator approves and activates; all edits audited | Before editable configuration |
| DEC-7 | Production identifiers | Persist only source-scoped order/accession/medication identifiers needed for reconciliation; direct patient identifiers remain in governed source ledgers and are never exposed | Before first production feed |

Unresolved decisions are recorded in an implementation decision register. They do not justify hard-coded vendor behavior.

---

## 5. Target architecture

~~~text
RIS / PACS / LIS / AP-LIS / Blood Bank / Pharmacy / ADC / IVWMS / Warehouse
                                |
                    MLLP boundary / HTTPS / files
                                |
             raw.inbound_messages + raw.dead_letters
                                |
        SourceMessageNormalizer -> CanonicalEventMapper
                                |
                 integration.canonical_events
                    + provenance + watermarks
                                |
             AncillaryProjectionHandler / dispatcher
                                |
     prod.ancillary_orders ---- prod.ancillary_milestones
             |                         |
             |                  SLA evaluation / breach lifecycle
             |                         |
      department satellites     prod.ancillary_breaches
             |                         |
             +------ encounter / barrier / OR joins ------+
                                                           |
       Workspaces / RTDC / ED / Periop / Cockpit / Study / OCEL
~~~

### 5.1 Command and query boundaries

- Integration ingestion commands own raw receipt, normalization, canonical event creation, projection, provenance, and dead-letter state.
- Milestone projection is idempotent and transactionally updates the append-only milestone ledger plus current order projection.
- SLA evaluation is deterministic from work item, selected assertions, effective definition, and evaluation time.
- Page query services own filters, aggregates, freshness, percentile math, and redaction. React never recomputes authoritative status.
- Barrier annotation uses a policy-checked Zephyrus command and links to an ancillary breach/order; it never commands a source system.
- Realtime messages contain only invalidation keys, aggregate counts, or versions. Clients refetch authorized payloads.

### 5.2 Canonical event naming

Use a stable dotted vocabulary:

- ancillary.radiology.order_placed, ancillary.radiology.exam_started, ancillary.radiology.report_finalized
- ancillary.lab.specimen_collected, ancillary.lab.result_verified, ancillary.lab.critical_notified
- ancillary.pathology.case_grossed, ancillary.pathology.report_signed_out
- ancillary.blood_bank.crossmatch_ready, ancillary.blood_bank.unit_issued
- ancillary.pharmacy.queue_entered, ancillary.pharmacy.verified, ancillary.pharmacy.dispensed

The canonical event payload contains a department code, milestone code, source-scoped work-item identity, encounter identity candidates, occurrence timestamp, local codes, and non-PHI operational attributes. The payload does not duplicate raw messages.

---

## 6. Shared data model

### 6.1 prod.ancillary_orders

One row represents one operational work item: an imaging order, lab order/AP case, blood-bank request, or medication order. Specimens, results, reads, and dispenses are satellites, not competing definitions of the same row.

Required fields:

| Field | Purpose |
| --- | --- |
| ancillary_order_id, order_uuid | Internal numeric key and stable public/audit UUID |
| department | rad, lab, pathology, blood_bank, or rx; grouped to the three product modules |
| work_item_type | imaging_order, lab_order, ap_case, blood_bank_request, medication_order |
| source_id | Owning integration source |
| source_order_key | Source-scoped placer/filler/order identity; hashed or pseudonymous when required |
| encounter_id | Nullable FK to prod.encounters.encounter_id |
| encounter_ref | Optional stable pseudonymous bridge when the prod encounter has not resolved yet |
| patient_ref | Pseudonymous local patient reference for reconciliation, never an MRN |
| patient_class | emergency, inpatient, outpatient, observation, perioperative, unknown |
| priority | stat, urgent, routine, timed, first_dose, sepsis, discharge, unknown |
| ordered_at, terminal_at | Core work-item interval |
| current_state, current_milestone_code | Rebuildable read projection |
| current_milestone_at | The selected head timestamp; prevents repeated max scans |
| unit_id | Nullable current operational unit snapshot |
| source_cutoff_at | Freshness boundary for this projection |
| demo_owner | Nullable exact ownership marker for safe refresh |
| metadata | Site variance and non-indexed operational context |

Constraints and indexes:

- Unique source_id + department + source_order_key.
- Check constraints for department, work_item_type, patient_class, and priority.
- Index department/current_state/current_milestone_at for live worklists.
- Index encounter_id/department/current_state for readiness joins.
- Index unit_id/department/current_state for unit lens.
- Partial index terminal_at IS NULL.
- No cascading delete from encounters or sources; retain audit facts and null the link when permitted.

### 6.2 prod.ancillary_milestones

Append-only source assertions:

| Field | Purpose |
| --- | --- |
| ancillary_milestone_id, milestone_uuid | Ledger identity |
| ancillary_order_id | Parent work item |
| milestone_code | Catalog code |
| occurred_at | Source asserted clinical/operational time |
| received_at | Zephyrus receipt time |
| source_id | Asserting source |
| canonical_event_id | FK to integration.canonical_events |
| provenance_record_id | Nullable FK to integration.provenance_records |
| assertion_key | Stable idempotency key |
| source_rank | Resolved precedence snapshot |
| selected_for_clock | Whether this assertion currently drives clocks |
| metadata | Non-PHI local detail, correction/addendum markers |

Rules:

- Unique assertion_key.
- UPDATE and DELETE rejected by a PostgreSQL trigger outside an explicitly documented synthetic reset transaction.
- Conflicting values from distinct sources are retained.
- Corrections append a new assertion and identify the superseded assertion in metadata; they do not mutate history.
- selected_for_clock is a read projection. Because the row is append-only, selection changes belong in a separate current-assertion projection or are computed. The implementation must not violate append-only behavior by flipping this field. Preferred design: omit persisted selected_for_clock and materialize a view keyed by order + code.

### 6.3 prod.ancillary_sla_definitions

Effective-dated policy data:

- ancillary_sla_definition_id, definition_uuid, department, metric_key, label.
- start_milestone_code and stop_milestone_code.
- priority and patient_class nullable scope.
- optional scope jsonb for modality, test code, unit, route, preparation branch, and site.
- statistic: item_clock, compliance_rate, median, p90, count, oldest_age.
- warning_minutes, breach_minutes, target_value, direction, unit.
- effective_from, effective_to, version, active, approved_by_user_id, approved_at.
- definition_text and source_reference_id.

Never overwrite an active definition in place. Close the prior effective range and create a new version. Open orders retain the definition version selected when a breach opens; current dashboards may also show the currently active policy.

### 6.4 prod.ancillary_breaches

- ancillary_breach_id, breach_uuid, ancillary_order_id, ancillary_sla_definition_id.
- status: open or cleared.
- warning_at, breached_at, cleared_at.
- start_assertion_id and stop_assertion_id for defensible clock reconstruction.
- elapsed_minutes_at_open and elapsed_minutes_at_clear.
- barrier_id nullable FK to prod.barriers.
- opened_event_uuid and cleared_event_uuid nullable references for operational activity.
- last_evaluated_at, metadata.

Enforce one open breach per order + SLA definition with a partial unique index. Clearing is a controlled lifecycle update on this materialized state table; historical milestone assertions remain immutable.

### 6.5 hosp_ref.ancillary_milestone_types

- department, code, label, phase, ordinal, terminal flag, optional flag.
- expected source class and source-precedence array.
- minimum-feed-set membership.
- OCEL event type and process IDs.
- display metadata limited to labels/icons, never status thresholds.

### 6.6 Supporting reference data

Add or seed:

- hosp_ref.ancillary_barrier_reasons with department, reason code, high-level Barrier category, label, active flag.
- hosp_ref.benchmark_references in Phase 4 with metric key, population, value/range, unit, source label, citation, publication year, evidence label, and established/current classification.
- Site-specific source precedence in integration source metadata or a dedicated governed mapping table. The global milestone catalog supplies defaults only.

### 6.7 Department satellites

| Module | Tables |
| --- | --- |
| Radiology | prod.rad_exams, rad_scanners, rad_scanner_downtimes, rad_reads, rad_critical_results, later rad_followup_recommendations; hosp_ref.rad_modalities and rad_subspecialties |
| Lab and Pathology | prod.lab_specimens, lab_results, lab_critical_values, ap_cases, bb_readiness; hosp_ref.lab_test_catalog |
| Pharmacy | prod.rx_orders, rx_verifications, rx_preps, rx_dispenses, rx_administrations, adc_stations, adc_transactions, rx_discharge_queue; hosp_ref.rx_formulary |

All satellites reference ancillary_order_id where applicable and use their own natural-key/idempotency constraints.

---

## 7. Milestone catalogs and source-of-truth policy

### 7.1 Radiology catalog

| Code | Label | Default source | Required for minimum mode |
| --- | --- | --- | --- |
| RAD_ORDERED | Order placed | EHR CPOE via ORM/OMI | Yes |
| RAD_PROTOCOLLED | Protocol assigned | RIS/EHR protocol activity | No |
| RAD_SCHEDULED | Scheduled or queued | RIS/SIU | No |
| RAD_PREP_COMPLETE | Prep/contrast/safety complete | RIS/EHR workflow | No |
| RAD_TRANSPORT_REQUESTED | Transport requested | Zephyrus/vendor transport | No |
| RAD_TRANSPORT_COMPLETE | Patient arrived | Zephyrus/vendor transport | No |
| RAD_EXAM_START | Exam started | MPPS; RIS fallback | No |
| RAD_EXAM_END | Exam completed | MPPS; RIS fallback | Yes for acquisition TAT |
| RAD_IMAGES_AVAILABLE | Images in PACS | Storage commitment/ImagingStudy | No |
| RAD_PRELIM | Preliminary report | Reporting system ORU | No |
| RAD_FINAL | Final report | Reporting system ORU | Yes |
| RAD_CRITICAL_NOTIFIED | Critical result communicated | CTRM | No |
| RAD_CRITICAL_ACKED | Critical result acknowledged | CTRM | No |
| RAD_FOLLOWUP_TRACKED | Follow-up recommendation tracked | Follow-up system | Phase 4 only |
| RAD_CANCELLED | Order cancelled/discontinued | EHR/RIS | Terminal |

Minimum degraded mode is RAD_ORDERED plus RAD_FINAL, with RAD_EXAM_END when available. The UI must not draw fabricated intermediate nodes.

### 7.2 Clinical lab catalog

| Code | Label | Default source | Notes |
| --- | --- | --- | --- |
| LAB_ORDERED | Ordered | EHR OML/ORM | Required |
| LAB_COLLECTED | Collected | Bedside barcode/OBR-7 | Optional until asserted |
| LAB_IN_TRANSIT | In transit | Tube/courier | Optional |
| LAB_RECEIVED | Received/accessioned | LIS OBR-14 | Required for analytic split |
| LAB_ANALYSIS_STARTED | In analysis | Middleware | Optional |
| LAB_PRELIM | Preliminary result | LIS ORU | Optional |
| LAB_RESULTED | Resulted | LIS ORU | Required |
| LAB_VERIFIED | Verified/released | LIS ORU/middleware | Preferred stop |
| LAB_CRITICAL_NOTIFIED | Critical callback initiated | LIS callback workflow | Conditional |
| LAB_CRITICAL_ACKED | Critical value read-back complete | LIS callback workflow | Conditional |
| LAB_REJECTED | Specimen rejected | LIS | Exception |
| LAB_RECOLLECT_ORDERED | Recollect requested | EHR/LIS | Rework loop |
| LAB_CORRECTED | Corrected result | LIS ORU | Append, never regress prior fact |
| LAB_CANCELLED | Cancelled | EHR/LIS | Terminal |

### 7.3 Anatomic pathology and blood bank

| Code | Label |
| --- | --- |
| AP_SPECIMEN_OUT | Specimen out of OR/procedure |
| AP_RECEIVED | Specimen received/accessioned |
| AP_GROSSED | Gross examination complete |
| AP_PROCESSING_BATCH | Histology processing/batch entered |
| AP_SLIDES_READY | Slides ready |
| AP_DIAGNOSED | Diagnosis recorded |
| AP_SIGNED_OUT | Final report signed out |
| AP_FROZEN_STARTED | Frozen section timer started |
| AP_FROZEN_RESULTED | Frozen section result communicated |
| BB_ORDERED | Blood-bank request ordered |
| BB_TNS_READY | Type and screen ready |
| BB_CROSSMATCH_READY | Crossmatch ready |
| BB_UNIT_ISSUED | Unit issued |
| BB_MTP_ACTIVATED | Massive transfusion protocol activated |
| BB_MTP_CLOSED | Massive transfusion protocol closed |

### 7.4 Pharmacy catalog

| Code | Label | Default source |
| --- | --- | --- |
| RX_ORDERED | CPOE order | RDE/MedicationRequest |
| RX_QUEUE_IN | Verification queue entered | EHR pharmacy queue event |
| RX_VERIFIED | Pharmacist verified | Queue removal/RDE ack |
| RX_PREP_STARTED | Preparation started | IVWMS/central automation |
| RX_PREP_COMPLETE | Preparation complete | IVWMS/central automation |
| RX_CHECKED | Final preparation check | IVWMS |
| RX_DISPENSED | Dispensed/vended | ADC/RDS/robot |
| RX_DELIVERED | Delivered to care area | Tube/dose tracking |
| RX_ADMINISTERED | Administered | BCMA/eMAR warehouse or RAS |
| RX_MISSING_DOSE | Missing dose/re-request | EHR/pharmacy workflow |
| RX_RETURNED | Returned | ADC |
| RX_WASTED | Wasted | ADC |
| RX_OVERRIDE | Override transaction | ADC; station/unit analytics only |
| RX_DISCREPANCY_OPEN | Controlled discrepancy opened | ADC; station/unit analytics only |
| RX_DISCREPANCY_RESOLVED | Controlled discrepancy resolved | ADC; station/unit analytics only |
| RX_DISCONTINUED | Discontinued | EHR |

### 7.5 Assertion-selection algorithm

For each order and milestone code:

1. Load all assertions.
2. Rank by deployment-specific source precedence; fall back to milestone catalog precedence.
3. Prefer a non-null, parse-valid source timestamp.
4. For equal rank, use the newest received assertion while retaining all originals.
5. Record the selected assertion in a rebuildable view/projection.
6. If sources disagree beyond a configured tolerance, expose a data-quality flag and do not silently average.
7. Rebuild current order state from selected assertions ordered by workflow ordinal and occurred_at; late events may fill history without regressing a terminal state.

---

## 8. SLA and benchmark seed matrix

Seed values are defaults for demo and initial configuration, not universal clinical policy.

| Metric key | Population | Start to stop | Default warning / breach or target |
| --- | --- | --- | --- |
| rad.stat_order_final | STAT CT/MR | RAD_ORDERED to RAD_FINAL | 90 / 120 min |
| rad.ed_image_final | ED imaging | RAD_IMAGES_AVAILABLE to RAD_FINAL | 30 / 60 min |
| rad.ed_ct_order_complete | ED CT | RAD_ORDERED to RAD_EXAM_END | Reference median 108 min, IQR 57–182; policy locally configured |
| rad.transport | Inpatient | RAD_TRANSPORT_REQUESTED to RAD_TRANSPORT_COMPLETE | Site configured |
| rad.critical_ack | Critical results | RAD_CRITICAL_NOTIFIED to RAD_CRITICAL_ACKED | Site policy; target at least 99% on time |
| lab.troponin_order_result | ED troponin | LAB_ORDERED to LAB_VERIFIED/RESULTED | 45 / 60 min |
| lab.stat_tat | STAT lab | LAB_ORDERED to LAB_VERIFIED | 45 / 60 min; compliance target 90% |
| lab.collect_receive | All specimens | LAB_COLLECTED to LAB_RECEIVED | Reference median 28 min |
| lab.critical_notify | Critical result | LAB_VERIFIED to LAB_CRITICAL_ACKED | Reference median 7 min; local breach policy |
| lab.ap_routine | Routine biopsy | AP_RECEIVED to AP_SIGNED_OUT | Target at least 90% within 2 days; complex policy configurable |
| lab.frozen | Frozen section | AP_FROZEN_STARTED to AP_FROZEN_RESULTED | Warning 15 / breach 20 min |
| rx.stat_dispense | STAT medication | RX_ORDERED to RX_DISPENSED | 10 / 15 min |
| rx.first_dose_admin | First dose | RX_ORDERED to RX_ADMINISTERED | 45 / 60 min, freshness-qualified |
| rx.sepsis_abx | Sepsis antibiotic | RX_ORDERED to RX_ADMINISTERED | 150 / 180 min, freshness-qualified |
| rx.discharge_ready | Discharge medication | RX_ORDERED or queue start to ready/delivered | Target derived from planned discharge time |
| rx.discrepancy_age | Controlled discrepancy | open to resolved | Shift-end policy, not an individual risk score |

Rules:

- Definitions expose both policy and benchmark. A benchmark never silently becomes an SLA.
- Median and P90 are required together on Study pages; mean alone is prohibited.
- Metrics with stale stop events become unknown/freshness-qualified, not falsely breached or compliant.
- Warehouse-fed administration metrics show the last source cutoff in the value label and tooltip.

---

## 9. API, route, and navigation contract

### 9.1 Inertia pages

| Workspace | Routes |
| --- | --- |
| Radiology | /radiology, /radiology/worklist, /radiology/modality, /radiology/reads |
| Lab | /lab, /lab/specimens, /lab/pending-decisions, /lab/blood-bank, /lab/anatomic-path |
| Pharmacy | /pharmacy, /pharmacy/discharge-meds, /pharmacy/iv-room, /pharmacy/dispense, /pharmacy/controlled |
| Study | /analytics/radiology-tat, /analytics/ir-utilization, /analytics/lab-tat, /analytics/pharmacy-tat |

### 9.2 Read APIs

Add authenticated, throttled GET endpoints under /api/ancillary:

- /summary and /readiness/{encounterId}
- /radiology/flow, /worklist, /modality, /reads, /tat
- /lab/flow, /specimens, /pending-decisions, /blood-bank, /anatomic-path, /tat
- /pharmacy/flow, /discharge-meds, /iv-room, /dispense, /controlled, /tat

Every response includes:

- data, filters, generatedAt, sourceCutoffAt, freshnessStatus, degradedMode.
- applied SLA definition IDs/versions.
- no direct identifier beyond already-authorized pseudonymous display/context references.
- stable pagination and server-side sort/filter for worklists.

### 9.3 Mutations

Initial mutation surface:

- POST /api/ancillary/orders/{orderUuid}/barriers
- POST /api/ancillary/breaches/{breachUuid}/link-barrier
- POST /api/ancillary/barriers/{barrierId}/resolve through the existing governed barrier service

Requirements:

- web session auth, policy checks, CSRF, validation request, idempotency key, audit/event record.
- no source-system writeback.
- no arbitrary category/reason strings; use active reference codes.
- optimistic conflict handling where a breach clears while an annotation is submitted.

### 9.4 Navigation ownership

- Add three Workspaces domains after RTDC and before Emergency unless product review explicitly selects another order.
- Radiology owns /radiology, Lab owns /lab, Pharmacy owns /pharmacy.
- Analytics owns all /analytics/* Study routes.
- Keep RTDC Ancillary Services in RTDC Operations at its current established location.
- Update NAVIGATION ordering tests, workspace header expectations, active-domain tests, uniqueness tests, mobile drawer, mega-menu, and command-palette projections.

---

## 10. Frontend contract and design rules

### 10.1 Shared components

- AncillaryOrderTimeline.tsx: milestone nodes, selected source, elapsed time, missing optional nodes, and SLA state.
- AgingHeatmap.tsx: rows by operational category, columns by aging bucket, accessible table fallback.
- SlaComplianceTile.tsx: value, target, median/P90, definition hover, freshness.
- BarrierChip.tsx and BarrierDrawer.tsx: reason, owner, age, linked breach, policy-checked mutation.
- QueueDepthSparkline.tsx: compact trend with accessible summary and no independent status calculation.
- ReadinessVector.tsx: imaging/lab/medication axes with label/icon/text plus color.
- SourceFreshnessBadge.tsx: real-time, delayed, warehouse as-of, degraded, unknown.

### 10.2 UI invariants

- Use TypeScript for new shared components and page contracts.
- Use PageContentLayout as the only page gutter owner.
- Use Card/Panel/Surface primitives and healthcare tokens with dark pairs.
- Use tabular-nums for all times, counts, rates, and percentages.
- No raw Tailwind status colors, faux bold, arbitrary font sizes, glass effects, or decorative red.
- Warning and breach classes receive a server state and never compare raw minutes in JSX.
- Status always includes text/icon/shape; color never stands alone.
- Honor reduced motion. Timers update text without layout shift or aggressive animation.
- Dense micro-captions are permitted only in resources/js/Components/cockpit.
- Worklists support keyboard navigation, visible focus, screen-reader column labels, and non-color filter state.

### 10.3 Live refresh

- Inertia supplies first render and authorization context.
- TanStack Query calls the same service-backed read endpoints for refetch.
- Default cadence: 30–60 seconds for operational queues, 5 minutes for Study aggregates, source-specific for warehouse data.
- Optional Echo events invalidate by module/version and never carry PHI.
- Paused/background tabs do not run one timer per row; one page clock derives display ages from server timestamps.

---

## 11. Demo-data architecture

Ancillary demo data is part of the canonical rolling scenario.

### 11.1 Generator contract

Create an AncillaryDemoGenerator interface with preview(DemoClock) and refresh(DemoClock, owner) results. Department generators:

- RadiologyDemoGenerator
- LabDemoGenerator
- PharmacyDemoGenerator

DemoRefreshCoordinator invokes one ancillary step after operational/tuning data establishes encounters, OR cases, discharge candidates, transport, and ED state, and before source freshness, invariants, OCEL/Arena refresh, and Cockpit publish.

### 11.2 Ownership and idempotency

- Use exact owner operations-demo:summit-500-current-operations-v1:ancillary:v1.
- Natural keys derive from scenario + anchor date + encounter/order ordinal.
- A refresh may replace only rows with the exact ancillary demo owner.
- Append-only milestone reset requires a transaction-local PostgreSQL setting accepted only by the trigger and only for exact demo-owned parent orders.
- Connector-backed and user-authored rows are never selected by date or missing metadata.

### 11.3 Distribution requirements

Radiology:

- ED/IP/OP mix, modality mix, portable XR branch, IR branch.
- ED CT order-to-complete distribution anchored around median 108 and IQR 57–182.
- night/weekend degradation, scanner downtime, transport bottlenecks, critical-result loops.

Lab:

- 03:00–06:00 AM draw wave, STAT/routine mix, ED troponin gates.
- hemolysis/rejection/recollect loops, auto-verification, critical callbacks.
- AP overnight batch, frozen sections linked to real demo OR cases, blood-bank readiness.

Pharmacy:

- 09:00–11:00 verify queue surge and shift-boundary dips.
- ADC, IV room, and central pharmacy branches.
- STAT, first-dose, sepsis, missing-dose, shortage, discharge medication, and discrepancy scenarios.
- Administration rows carry explicit warehouse cutoff.

### 11.4 New invariants

Critical:

- no orphan order, milestone, specimen, result, read, dispense, breach, or readiness row.
- assertion keys unique and milestone codes valid for the order department.
- no terminal milestone before order time unless the source correction is explicitly flagged.
- one open breach per order/SLA and every open breach is mathematically valid at the frozen anchor.
- cleared breaches have a valid stop assertion and nonnegative elapsed interval.
- every discharge blocker references a current discharge candidate and live ancillary order.
- every frozen section/blood-bank gate references a valid OR case.
- every administered metric carries source cutoff; no stale warehouse metric reports real-time.
- demo rows all carry the exact owner; no non-owned row is changed during refresh.

Warning/plausibility:

- generated medians/IQRs and categorical shares stay within configured tolerance.
- breach rate, recollect rate, callback time, queue surge, and stockout rates remain plausible.
- source precedence conflicts are represented in at least one deterministic scenario.

---

## 12. OCEL and process-intelligence mapping

Do not create a second process-mining schema. Extend the existing OcelProjector and HospitalProcessCatalog.

| Process | Ancillary objects | Events |
| --- | --- | --- |
| D1 Lab order-to-result | ancillary_order, specimen, test, analyzer, result, encounter | ordered, collected, received, analysis, verified, notified |
| D5 Imaging order-to-final | ancillary_order, imaging_study, scanner, read, encounter | ordered, protocolled, scheduled, acquired, prelim, final |
| D6 Critical imaging communication | imaging_study, critical_result, communication task, responsible team | finding, notified, acknowledged, closed |
| D7 Pathology specimen-to-diagnosis | specimen, AP case, block/slide, pathologist assignment, report | received, grossed, processed, slides ready, diagnosed, signed out |
| D11 Medication order-to-administration | medication order, pharmacy work, dose, ADC/prep resource, encounter | ordered, verified, prepared, dispensed, delivered, administered |
| D12 Discharge medication readiness | medication order, pharmacy work, encounter, education/handoff | reconciled/ordered, verified, prepared, ready, delivered |

Acceptance requires object-object and event-object relationships, not merely registering labels in process_models. Demo projections must produce rows that the existing Arena performance and conformance jobs can consume.

---

## 13. Security, privacy, governance, and safety

- Raw messages remain ePHI by default and stay server-side in governed storage.
- Logs contain receipt IDs, source keys, event types, hashes, and reason codes; never raw payloads or direct identifiers.
- Source activation retains contract, BAA, PHI approval, environment/live state, exact machine identity, and credential-reference gates.
- Extend the existing machine-ingress model with exact abilities by feed family; do not reuse a broad browser session or the Patient Flow ADT ability.
- Enforce message size, content type, source binding, rate limit, idempotency, and rejection/dead-letter behavior.
- Validate timestamps, identifiers, code formats, segment cardinality, and allowed message families before projection.
- Browser payloads use authorized patient context references and minimum necessary data.
- Barrier writes are authenticated, authorized, audited, and limited to Zephyrus.
- SLA edits require admin capability and immutable audit history.
- Predictive scores are sort/planning aids with model version, calibration date, feature freshness, and explanation. They are not alarms or clinical recommendations.
- Pharmacy controlled-substance screens prohibit individual scoring in service, API, UI, tests, exports, and documentation.
- Accessibility target is WCAG 2.2 AA in both themes.

---

## 14. Dependency graph and delivery order

~~~text
DEC defaults
    |
P0-1 -> P0-2 -> P0-3
  |       |       |
  +----> P0-4 -> P0-5
  |               |
  +----> P0-6 ----+
  |               |
  +----> P0-7     |
  +----> P0-8     |
  +----> P0-9 -> P0-10
                  |
      +-----------+-----------+
      |                       |
   Radiology                Lab and Pharmacy schema/demo prep
 R-1..R-5                    L-1..L-4 / X-1..X-5
      |                       |
 R-6..R-14 -> R-15 -> L-11 -> X-7
                  |       |
                L-14     X-14
                  +---+---+
                      |
                    P4-1..P4-7
~~~

Hard dependencies:

- All P0 tasks block department production code.
- Within each department, migration/model/parser/demo tasks block UI and joins.
- R-11 blocks L-11; L-11 blocks X-7 so the readiness vector accretes deliberately.
- R-15, L-14, and X-14 all block Phase 4.
- Live connector activation is separately blocked by DEC-1 and governance evidence, not by demo delivery.

---

## 15. Detailed 60-task implementation backlog

Each task below includes scope, concrete seams, dependencies, and acceptance. A checked box means code, tests, documentation, and evidence all exist.

### Phase 0 — Shared ancillary spine

#### [x] P0-1 — Create the shared schema and database invariants

**Depends on:** planning defaults only
**Primary files:** new dated migrations; app/Traits/SafeMigration.php patterns; integration and transport governance migrations as examples

**Work:**

- [x] Add the five core tables and the supporting barrier-reason catalog from section 6.
- [x] Use repository primary-key conventions such as ancillary_order_id and provenance_record_id.
- [x] Add explicit PostgreSQL checks, partial indexes, natural-key uniqueness, FK delete behavior, and effective-date constraints.
- [x] Implement milestone append-only enforcement with a named trigger and a narrowly scoped demo reset setting.
- [x] Add a current-assertion view or materialized projection; do not update append-only milestone rows to switch source selection.
- [x] Add comments describing pseudonymous identifiers, source ownership, timestamp semantics, and rollback constraints.
- [x] Make migration up idempotent where existing repository style requires it. Down is supported only for empty/local schemas; if governed rows exist, fail with a forward-repair explanation.

**Acceptance:**

- [x] Fresh PostgreSQL migration succeeds and all new constraints/indexes exist.
- [x] Local empty-schema down/up rehearsal succeeds.
- [x] UPDATE and DELETE of a milestone fail; the exact owned-demo reset path succeeds only inside its guarded transaction.
- [x] Duplicate source order keys, duplicate assertion keys, overlapping effective SLA versions, and duplicate open breaches are rejected.
- [x] Foreign keys reference prod.encounters.encounter_id, prod.barriers.barrier_id, integration.sources.source_id, integration.canonical_events.canonical_event_id, and integration.provenance_records.provenance_record_id correctly.
- [x] A production-shaped migration rehearsal records duration, row counts, constraint validation, and rollback/forward-repair notes.

#### [x] P0-2 — Add models, factories, relationships, scopes, and DTO contracts

**Depends on:** P0-1
**Primary files:** app/Models/Ancillary/**; database/factories/**; app/Data or app/DTO ancillary contracts

**Work:**

- [x] Create AncillaryOrder, AncillaryMilestone, AncillarySlaDefinition, and AncillaryBreach models using explicit table and primary-key names.
- [x] Add relationships to source, encounter, barrier, canonical event, provenance, and current reference records; department satellites add their inverse relations with R-1, L-1, and X-1 when those tables exist.
- [x] Add query scopes for open, department, unit, encounter, priority, breached, discharge-blocking, source freshness, and demo owner.
- [x] Make milestone models non-updatable at the application layer in addition to the DB trigger.
- [x] Add immutable typed DTOs for milestone assertion, selected clock, readiness axis, freshness envelope, and page filters.
- [x] Create factories that generate valid alternative/degraded sequences, not only a happy linear path.

**Acceptance:**

- [x] Relationship and cast tests cover every currently materialized FK and timestamp/JSON field.
- [x] Factories generate full, degraded, conflicting-source, out-of-order, terminal, and rework-loop cases.
- [x] A model cannot silently update or delete milestones; query-level mutation remains rejected by the P0-1 database trigger.
- [x] Readiness and page DTOs serialize to stable camelCase browser contracts.

#### [x] P0-3 — Seed milestone, barrier, source-precedence, SLA, and metric definitions

**Depends on:** P0-1, P0-2
**Primary files:** database/seeders/AncillaryReferenceSeeder.php; CockpitKpiDefinitionSeeder.php; HospitalProcessCatalog.php

**Work:**

- [x] Seed every milestone in section 7 with department, phase, ordinal, optionality, terminal state, default source class, and OCEL mapping; add the two discrepancy lifecycle codes required by the section 8 clock.
- [x] Seed department barrier reasons and map each to medical, logistical, placement, or social.
- [x] Seed the initial SLA definitions in section 8 with explicit evidence labels and effective versions.
- [x] Add Flow-domain Cockpit definitions for imaging open breaches/oldest unread/scanners down, lab STAT compliance/oldest decision-pending/critical callbacks, and pharmacy queue/oldest STAT/sepsis at-risk/shortage stockouts.
- [x] Keep benchmark values separate from policy thresholds; reference-only rows remain inactive and carry an explicit evidence label.
- [x] Use update-or-create behavior that does not churn stable identifiers. Do not overwrite admin-tuned active definitions without a deliberate versioned reset command.

**Acceptance:**

- [x] Seeder is idempotent and preserves stable UUIDs and administrator-tuned ancillary thresholds.
- [x] Every SLA start/stop code exists for the same department.
- [x] Every barrier reason maps to an allowed prod.barriers category.
- [x] Every Cockpit metric key has direction, unit, refresh cadence, definition, and earned-urgency edges.
- [x] Tests fail if a catalog code is removed while referenced by current SLA/demo contracts, and all current OCEL mappings resolve; P0-6 adds the same integrity assertion around concrete parser maps as they land.

#### [x] P0-4 — Implement ancillary projection, assertion selection, and state rebuild

**Depends on:** P0-2, P0-3, P0-6
**Primary files:** app/Integrations/Healthcare/Services/AncillaryProjectionHandler.php; projection registry/dispatcher; app/Services/Ancillary/**

**Work:**

- [x] Implement ProjectionHandler for ancillary canonical event types.
- [x] Resolve or create the source-scoped work item, append the milestone, persist provenance, and update the rebuildable current-order projection in one transaction.
- [x] Use the canonical event idempotency key plus milestone code/source identity to form assertion keys.
- [x] Add assertion selection using section 7.5 and a data-quality conflict flag.
- [x] Handle out-of-order events, late encounter linkage, cancellations, corrected results, terminal state, and competing source assertions.
- [x] Add a repair/rebuild command that reconstructs current state from the milestone ledger without changing history.
- [x] Generalize ReplayPendingIntegrationEvents from its current single RtdcProjectionHandler dependency to a bounded projection dispatcher that selects the supporting handler and records the real projector key.

**Acceptance:**

- [x] Duplicate replay produces one assertion and one projection result.
- [x] Out-of-order history is retained and current state remains correct.
- [x] MPPS/RIS or LIS/middleware conflicts retain both assertions and select the configured source.
- [x] A late identity link attaches the order to the correct encounter without duplicating it.
- [x] Replay supports both existing RTDC and new ancillary event families; unsupported types remain safely rejected and failed projections use their real handler key.
- [x] Rebuild produces byte-equivalent current projections on fixed fixtures.

#### [x] P0-5 — Implement the SLA evaluator and governed breach lifecycle

**Depends on:** P0-3, P0-4
**Primary files:** app/Services/Ancillary/SlaEvaluator.php; app/Console/Commands/AncillaryEvaluateSlas.php; bootstrap/app.php scheduler

**Work:**

- [x] Select applicable effective SLA definitions by department, priority, patient class, and scoped attributes.
- [x] Resolve selected start/stop assertions and calculate elapsed time in a single explicit timezone.
- [x] Open one breach after the breach threshold, clear it when the stop assertion arrives, and retain the SLA definition version and assertion IDs.
- [x] Expose warning state without necessarily persisting a breach row.
- [x] Record idempotent open/clear activity through the existing ops OperationalActivityLedger with domain ancillary and PHI-minimized entity references.
- [x] Add event-driven evaluation after projection plus a per-minute scheduled catch-up command.
- [x] Make staleness and missing-source behavior explicit: unknown is not compliant, and warehouse metrics are cutoff-qualified.

**Acceptance:**

- [x] Carbon time-travel tests cover before warning, warning, breach, stop before breach, stop after breach, correction, stale source, and DST boundary.
- [x] Repeated scheduler/event evaluation creates no duplicate breach or activity event.
- [x] Clearing references the exact selected stop assertion and preserves elapsed time.
- [x] Scheduler is registered and visible in php artisan schedule:list.
- [x] A failed one-order evaluation is isolated and reported without aborting the entire batch.

#### [x] P0-6 — Extend the existing integration runtime for ancillary message families

**Depends on:** P0-1
**Primary files:** app/Integrations/Healthcare/Contracts/**; new normalizers/mappers; app/Services/PatientFlow/Hl7V2Message.php; IntegrationConnectorTemplateSeeder.php

**Work:**

- [x] Reuse SourceMessageNormalizer, CanonicalEventMapper, ProjectionHandler, CanonicalEventWriter, raw inbound records, provenance, dead letters, watermarks, and replay jobs through the governed normalizer registry, shared projection dispatcher, and bounded backfill checkpoint.
- [x] Extract a shared HL7 message utility from the Patient Flow implementation or extend it with repetitions, components, escaped delimiters, multiple OBR/OBX groups, timezone offsets, validation, and safe field access.
- [x] Register governed source templates for radiology ORM/OMI, ORU, MPPS relay, SIU; lab OML/ORM, ORU, middleware, blood bank/AP; pharmacy RDE, RDS, verification queue, ADC, warehouse administration; and shared ADT linkage.
- [x] Extend the FHIR poller through configuration/capability allowlists for ServiceRequest, ImagingStudy, DiagnosticReport, Specimen, Observation, MedicationRequest, and MedicationDispense. Add only the SMART system scopes approved for the deployment; ancillary resources remain disabled by default.
- [x] Define a bounded Bulk Data/backfill adapter and checkpoint contract separately from real-time polling. Bulk files still land through raw, canonical, projection, provenance, and dead-letter controls.
- [x] Define exact machine abilities and endpoint/source binding for each feed family. Keep production inactive until governance passes.
- [x] Add a Null/unsupported handler only as an explicit dead-letter path, never a success.
- [x] Document MLLP termination as an upstream interface-engine/dedicated receiver responsibility; Zephyrus accepts the governed HTTPS boundary unless a separately approved listener is built.

**Acceptance:**

- [x] One synthetic source message traverses raw receipt, normalization, canonical write, ancillary projection, milestone, provenance, and projected status.
- [x] Invalid family, oversized message, missing control/order identity, malformed timestamp, and source mismatch reach a sanitized dead letter.
- [x] Existing Patient Flow ADT tests remain green; full Patient Flow feature/unit plus targeted lineage, idempotency, and rejected-raw scenarios pass.
- [x] No raw message appears in logs, API receipts, exception messages, or browser payloads.
- [x] Source templates remain inactive/non-PHI by default and require the existing governance fields for activation.
- [x] Existing Encounter/Location polling remains green, and unapproved ancillary resource/scopes are rejected.

#### [x] P0-7 — Project ancillary facts into the existing OCEL landscape

**Depends on:** P0-3, P0-4
**Primary files:** app/Domain/Ocel/OcelProjector.php; HospitalProcessCatalog.php; OCEL tests

**Work:**

- [x] Register the object and event types in section 12.
- [x] Project event-object and object-object relations for encounter, order, specimen/study/dose, resource, result/read, communication, and report.
- [x] Align event names to existing D1/D5/D6/D7/D11/D12 reference nodes or document an explicit mapping.
- [x] Make projection idempotent on canonical event/milestone identity.
- [x] Add a backfill command scope for ancillary order IDs/date window.
- [x] Preserve PHI-free object keys in OCEL.

**Acceptance:**

- [x] Demo ancillary milestones create expected OCEL events, objects, and relations.
- [x] Reprojection is idempotent.
- [x] Existing Arena map/performance/conformance queries return ancillary rows for at least D1 and D5 in Phase 0 fixtures.
- [x] Process landscape readiness notes update from reference-only/partial only when evidence really exists.

#### [x] P0-8 — Integrate ancillary generation into the rolling demo pipeline

**Depends on:** P0-1 through P0-5
**Primary files:** app/Services/Demo/Ancillary/**; DemoRefreshCoordinator.php; DemoInvariantService.php; config/demo.php

**Work:**

- [x] Implement the generator and ownership design from section 11.
- [x] Add a preview result with order/milestone/breach counts and collision detection.
- [x] Add the ancillary coordinator step after encounter/OR/discharge seeding and before freshness/invariants/Cockpit publish.
- [x] Extend source freshness with ancillary tables and expected lag.
- [x] Add deterministic minimal scenarios for all three departments in Phase 0, including one degraded feed and one source conflict.
- [x] Extend demo validation output with ancillary findings and critical/warning counts.

**Acceptance:**

- [x] Two fixed-anchor refreshes produce identical natural keys and counts.
- [x] Advancing the anchor moves active queues without changing non-demo rows.
- [x] A forced invariant failure prevents Cockpit publish and preserves the last-known-good snapshot.
- [x] Collision detection refuses to replace a non-owned natural key.
- [x] zephyrus:demo-refresh --validate and zephyrus:demo-validate --json include ancillary results.

#### [x] P0-9 — Build the shared order timeline and readiness vector contracts

**Depends on:** P0-2, P0-3
**Primary files:** resources/js/Components/Ancillary/AncillaryOrderTimeline.tsx and ReadinessVector.tsx; types and tests

**Work:**

- [x] Render done, selected current, pending required, missing optional, terminal, and exception/rework milestones.
- [x] Show the selected source, degraded-mode explanation, source cutoff, elapsed and remaining clocks, and definition access.
- [x] Render readiness axes with pending count, oldest age, blocking state, freshness, and drill target.
- [x] Use one page clock to update visible elapsed labels.
- [x] Add compact and expanded variants for ED/RTDC/Periop joins and department worklists.

**Acceptance:**

- [x] Vitest covers full, degraded, breached, conflicting-source, recollect, cancelled, stale, and warehouse-as-of states.
- [x] Keyboard and screen-reader checks identify every state without color.
- [x] Timers use tabular numerals and do not shift layout.
- [x] scripts/check-ui-canon.sh and TypeScript pass.

#### [x] P0-10 — Build the shared ancillary visual/query kit and example contract

**Depends on:** P0-9
**Primary files:** resources/js/Components/Ancillary/**; reusable backend query helpers; existing design example route

**Work:**

- [x] Build AgingHeatmap, SlaComplianceTile, BarrierChip/Drawer, QueueDepthSparkline, SourceFreshnessBadge, FilterSummary, and percentile/clock-definition helpers.
- [x] Provide accessible tabular fallbacks for heatmaps and charts.
- [x] Add one existing Examples/Design surface showcasing canonical states; do not introduce Storybook solely for this program.
- [x] Add backend percentile, interval, freshness, and SLA-definition serializers so pages share calculations.
- [x] Freeze Zod/TypeScript contracts for worklist rows, metric tiles, readiness axes, and freshness envelope.

**Acceptance:**

- [x] Example states cover normal, warning, breach, stale, no-data, degraded, and loading.
- [x] Backend unit tests cover median/P90, empty cohorts, negative/invalid intervals, and timezones.
- [x] Frontend tests reject malformed contracts and render intentional unavailable states.
- [x] UI canon, TypeScript, and focused Vitest suites pass.

### Phase 1 — Radiology

#### [x] R-1 — Create Radiology satellites, models, catalogs, and indexes

**Depends on:** P0 complete
**Primary files:** Radiology migration; app/Models/Radiology/**; factories; AncillaryReferenceSeeder

**Work:**

- [x] Create rad_exams with modality, body region, subspecialty, protocol, contrast, portable, scanner, scheduled slot, IR flag, preparation attributes, and ancillary_order_id.
- [x] Create rad_scanners and rad_scanner_downtimes with facility/unit/location identity, capacity/status, source, effective interval, and demo ownership.
- [x] Create rad_reads with status, radiologist pseudonymous reference where permitted, subspecialty, teleradiology flag, preliminary/final/corrected timestamps, and addendum lineage.
- [x] Create rad_critical_results with finding class, notification/acknowledgment/escalation timestamps and policy state.
- [x] Seed modalities/subspecialties and indexes for open worklists, scanner day views, unread backlog, and critical loops.

**Acceptance:**

- [x] Migrations and factories cover XR, CT, MRI, US, NM, IR, portable, contrast, and cancelled exams.
- [x] Natural keys prevent duplicate exam/read/critical-result projection.
- [x] Scanner downtime intervals cannot end before they start.
- [x] Factory data joins valid ancillary orders and encounters.

#### [x] R-2 — Parse and map Radiology ORM/OMI order/status messages

**Depends on:** R-1, P0-6
**Primary files:** Radiology HL7 normalizer/mapper; tests/Fixtures/hl7/radiology/**

**Work:**

- [x] Parse MSH, PID/PV1 encounter candidates, ORC order control/status, OBR procedure/modality/priority/times, and relevant scheduling/prep fields.
- [x] Map new, status, cancel, discontinue, and modify messages to canonical events and RAD milestones.
- [x] Map SIU and FHIR Appointment/ServiceRequest backfill into the same scheduled/order vocabulary without creating duplicate work items.
- [x] Preserve placer and filler identities under source scope.
- [x] Support multiple ordered procedures/message groups without cross-contaminating fields.
- [x] Sanitize all validation failures and dead-letter unparseable messages.

**Acceptance:**

- [x] Golden fixtures cover STAT ED CT, routine inpatient MRI, portable XR, scheduled outpatient, order modification, cancellation, and multi-OBR message.
- [x] Same message replay is idempotent; a changed message under the same idempotency key returns conflict.
- [x] Missing optional protocol/schedule data produces degraded output, not fabricated milestones.

#### [x] R-3 — Parse and map Radiology ORU preliminary/final/corrected reports

**Depends on:** R-1, P0-6
**Primary files:** Radiology ORU mapper; read projector; golden fixtures

**Work:**

- [x] Map preliminary, final, corrected, and addendum report states using OBR-25/OBX status and the correct report clock.
- [x] Map FHIR ImagingStudy and DiagnosticReport backfill to image-available/report milestones with resource version provenance.
- [x] Populate rad_reads and RAD_PRELIM/RAD_FINAL assertions.
- [x] Final-only feeds are valid; corrected reports append new evidence without erasing the original final.
- [x] Separate report text/clinical content from operational metadata; the browser needs status and timing, not full report narrative.

**Acceptance:**

- [x] Fixtures cover prelim-to-final, final-only, corrected-after-final, duplicate final, multiple result groups, and timezone offsets.
- [x] Hand-computed order-to-final and prelim-to-final intervals match service calculations.
- [x] No report narrative or direct identifier appears in a list API or log.

#### [x] R-4 — Ingest forwarded MPPS, PACS, transport, and critical-result events

**Depends on:** R-1, P0-6
**Primary files:** vendor-event normalizers/mappers; source templates; fixtures

**Work:**

- [x] Define a governed JSON envelope for an upstream MPPS relay: SOP instance/source study key, status, performed procedure step start/end, modality/scanner, event time, and source signature metadata.
- [x] Map IN PROGRESS, COMPLETED, and DISCONTINUED to exam milestones/terminal state.
- [x] Accept optional storage commitment/ImagingStudy image-available events.
- [x] Reuse or map existing Zephyrus transport request lifecycle events to RAD_TRANSPORT_REQUESTED/COMPLETE.
- [x] Accept critical result notified/acknowledged events from CTRM adapters.
- [x] Apply source precedence while retaining RIS assertions.

**Acceptance:**

- [x] MPPS beats RIS for exam start/end when configured, with both assertions visible.
- [x] Invalid status, missing source identity, or impossible interval dead-letters safely.
- [x] Portable exams render without transport.
- [x] No direct DICOM network listener is implied; relay contract and deployment responsibility are documented.

#### [x] R-5 — Generate coherent Radiology demo data

**Depends on:** R-1 through R-4, P0-8
**Primary files:** RadiologyDemoGenerator; demo profiles; invariants/tests

**Work:**

- [x] Generate modality and patient-class mixes, preparation/transport branches, scanner allocation/downtime, read queues, critical loops, IR cases, and barriers.
- [x] Tie ED imaging to real demo ED visits, discharge blockers to current discharge candidates, and IR cases to OR/procedural context where applicable.
- [x] Include night/weekend degradation, portable bypass, source conflict, corrected report, and cancellation scenarios.
- [x] Generate a fixed-seed distribution approximating the PDF anchor without overfitting every sample.

**Acceptance:**

- [x] Fixed-seed median/IQR and categorical tolerance tests pass.
- [x] Every linked discharge/ED/IR scenario resolves.
- [x] Demo invariants pass across two refreshes and an advanced anchor.
- [x] No non-demo row changes.

#### [x] R-6 — Implement Imaging Flow Board at /radiology

**Depends on:** R-1 through R-5, P0-10
**Primary files:** RadiologyFlowBoardService; controller/API; Pages/Radiology/FlowBoard.tsx

**Work:**

- [x] Build aging heatmap by modality and bucket, open-breach summary, oldest items, source freshness, barrier Pareto preview, and current scanner state.
- [x] Add lenses for all, ED, inpatient, discharge blocking, priority, modality, unit, and degraded feed.
- [x] Provide a policy-checked barrier drawer linked to the order/breach.
- [x] Return server-derived status and thresholds plus generated/source cutoff timestamps.
- [x] Add a bounded oldest-order preview and filtered worklist drill rather than rendering every order in the heatmap page.

**Acceptance:**

- [x] Inertia first render and API refetch use the same service contract.
- [x] A pending discharge CT is visible and filterable as discharge blocking.
- [x] Barrier annotation persists, is audited, and appears in existing Improvement views.
- [x] Empty, stale, degraded, and source-error states are intentional and tested.

#### [x] R-7 — Implement Radiology Order Worklist at /radiology/worklist

**Depends on:** R-6
**Primary files:** RadiologyWorklistService; API/controller; Worklist.tsx

**Work:**

- [x] Server-side filter, sort, cursor pagination, and bounded search by permitted operational identifier.
- [x] Expand rows into the shared timeline with source assertions, SLA clock, downstream impact, barrier, and freshness.
- [x] Include transport segment only when real milestones exist.
- [x] Add breach-risk sort seam but do not enable predictive scoring until P4-1.
- [x] Support filtered deep links from Ancillary Services, ED, RTDC, Periop, and Cockpit.

**Acceptance:**

- [x] Filters and sort are deterministic and covered by query tests.
- [x] Full and degraded timelines render correctly.
- [x] Deep-link filter parameters are allowlisted and malformed values are rejected/defaulted.
- [x] Large-fixture query plan uses intended indexes and avoids N+1 queries.

#### [x] R-8 — Implement Modality Utilization at /radiology/modality

**Depends on:** R-1, R-5, P0-10
**Primary files:** ModalityUtilizationService; page/API; chart components

**Work:**

- [x] Calculate scanner available interval, MPPS-covered exam blocks, planned/unplanned downtime, idle gaps, ED/IP/OP mix, utilization, and data coverage with one server contract.
- [x] Treat explicit scanner `staffed_operating_hours` windows as denominators; clip weekly/overnight windows to the selected date/time and never infer a 24-hour denominator.
- [x] Union overlapping intervals, apply unplanned downtime then planned downtime before exam activity, and emit mutually exclusive timeline segments that reconcile to the staffed window.
- [x] Require an observed/healthy governed MPPS source mapped to the scanner plus per-exam MPPS start/end evidence before returning machine utilization; convert uncovered remainder to unknown rather than idle.
- [x] Show validated date, time, and modality filters, stacked downtime/exam/idle/unknown chart layers, scanner timelines, definition hover/expanded definitions, and a derived portfolio-average reference line explicitly labeled as non-benchmark.
- [x] Keep outpatient output limited to the required ED/IP/OP cohort mix; DEC-3 remains at its default and no access, template, or no-show analytics were added.

**Acceptance:**

- [x] Interval-union and overlap tests prevent double counting.
- [x] Idle + exam + downtime reconciles to the declared available window within rounding tolerance.
- [x] A missing MPPS feed produces a coverage warning and does not claim machine utilization.
- [x] Charts have accessible summaries and canon-clean colors.

#### [x] R-9 — Implement Reads and Results at /radiology/reads

**Depends on:** R-3, R-4, R-5, P0-10
**Primary files:** RadiologyReadsService; page/API

**Work:**

- [x] Show unread depth and oldest age by priority/subspecialty, complete-hour backlog growth, preliminary-to-first-final aging, critical-result notification/acknowledgment state, and governed reporting-source freshness.
- [x] Keep item-level warning/breach/stale/unconfigured states inside the workspace and expose one exact aggregate `cockpitHealth()` seam for future R-10 consumption.
- [x] Separate no-report, preliminary, final, corrected, stale-feed, missing-feed, source-error, degraded-timestamp, and no-data states without inferring current health from unavailable evidence.
- [x] Add allowlisted state/priority/subspecialty/modality/window filters and source-scoped originating-order drills into the existing Radiology worklist.
- [x] Preserve the original final clock when corrected reports or addenda arrive, count corrections independently, and exclude all clinical report narrative from service and browser contracts.
- [x] Extend deterministic Radiology demo data with preliminary-to-final lineage while retaining deliberate no-report, corrected, critical-loop, and missing-timestamp examples.

**Acceptance:**

- [x] Closed-loop pending/notified/acknowledged/closed transitions and corrected-report lineage behavior are tested.
- [x] Full 60-minute buckets exclude the current partial hour; first-final timing and missing/negative interval handling are documented and tested.
- [x] The strict frontend schema, service privacy flag, payload inspection, and rendered test prove clinical report text is neither required nor exposed.
- [x] Page/API contracts match after JSON normalization and workspace health aggregates reconcile exactly with the future R-10 Cockpit summary seam.
- [x] The page supplies accessible filters, chart summary table, queue table, critical-loop drills, healthcare-token chart colors, and explicit freshness/degraded-state messaging.

#### [x] R-10 — Add Radiology health metrics to the server-computed Cockpit

**Depends on:** R-6, R-9
**Primary files:** FlowMetrics.php; CockpitKpiDefinitionSeeder.php; DrillBuilder.php; Cockpit tests

**Work:**

- [x] Emit imaging open breaches, oldest unread age with priority breakdown, and distinct active scanners down as Flow-domain `MetricValue` instances from one aggregate-only Radiology contract.
- [x] Compose the existing Flow Board `cockpitHealth()` and unchanged Reads `cockpitHealth()` seams so Cockpit cannot invent a second workspace calculation.
- [x] Add a cached-tile ancillary health table to the Flow drill with active Radiology values and explicitly reserved Laboratory and Pharmacy rows that remain neutral/not-available until their providers ship.
- [x] Use the seeded `flow.ancillary_rad_*` definitions and `StatusEngine`; keep all status calculation server-side and add no standalone/client-computed Radiology tile.
- [x] Carry live provenance, workspace route, source label/state/cutoff, current/degraded/unknown data state, unread priority detail, and last-known labeling in metric metadata/subtext.
- [x] Count active scanner downtime with a distinct aggregate so overlapping downtime records cannot double-count a scanner; exclude retired inventory and do not classify limited-capacity scanners as down.

**Acceptance:**

- [x] Snapshot, metric-history linkage/metadata, alert derivation, Flow drill, and definition-driven status-parity tests pass.
- [x] Scanner-down, unread, and imaging-breach values remain absent from the alert ticker because their governed definitions have no alert template, even when their tiles are warning/critical.
- [x] Stale/error/degraded last-known values are demoted server-side to neutral while retaining the numeric fact and cutoff; missing report evidence omits the unsupported unread metric and can never render success.
- [x] The full Cockpit plus Radiology regression and Cockpit frontend contract suite pass without changing the existing eight-domain snapshot or cell grammar.
- [x] Chromium browser smoke opens `/dashboard?drill=flow`, renders the semantic ancillary table and all three service rows/source-cutoff header, and reports no browser console errors.

#### [x] R-11 — Add imaging to ED and the RTDC discharge readiness vector

**Depends on:** R-6, R-7; blocks L-11
**Primary files:** DischargePrioritiesService.php/page; ED board service/page; shared ReadinessVector

**Work:**

- [x] Add imaging = pendingCount, oldestAgeMinutes, state, blocking, freshness, topOrderUuid, drillHref to each discharge patient.
- [x] Define blocking as an open imaging order explicitly tagged/derived as a discharge gate, not every routine imaging order.
- [x] Add authorized pending-imaging chip to ED patient rows with count and oldest age.
- [x] Keep existing discharge payload fields unchanged.
- [x] Use one readiness aggregation service shared by RTDC, ED, and department worklists.

**Acceptance:**

- [x] Fixed-time feature evidence proves the same demo CT appears on Radiology, ED, and RTDC at 47 minutes with the same blocked state and order UUID.
- [x] An unrelated routine outpatient order is non-blocking, and a completed discharge-tagged order is excluded from pending/blocking readiness.
- [x] A stale registered source produces an unknown axis for both populated and empty scopes, never ready.
- [x] The ED chip and RTDC vector expose text/icon state, count, oldest age, accessible drill labels, and allowlisted UUID-filtered Radiology links.
- [x] Chromium smoke renders `/rtdc/predictions/discharge`, `/ed/operations/treatment`, and `/radiology/worklist`, including their readiness controls, with zero browser console/page errors.

#### [x] R-12 — Implement Radiology TAT Study at /analytics/radiology-tat

**Depends on:** R-6 through R-9
**Primary files:** RadiologyTatAnalyticsService; Analytics controller/page/API; navigation

**Work:**

- [x] Provide median and P90 segment waterfall, trends by priority/modality/class/shift, night/weekend comparison, breach Pareto, data coverage, and benchmark lines.
- [x] Attribute each interval to explicit SLA definition and selected assertions.
- [x] Exclude invalid/negative/corrected intervals with a visible data-quality count rather than silently cleaning.
- [x] Support date range and filter limits suitable for indexed queries.

**Acceptance:**

- [x] Percentile math matches fixed fixtures and PostgreSQL reference calculations.
- [x] Every chart exposes clock definition, cohort size, cutoff, and benchmark source label.
- [x] Mean is never the only statistic.
- [x] Study route is owned only by Analytics in navigation tests.

#### [x] R-13 — Implement IR Suite Study at /analytics/ir-utilization

**Depends on:** R-1, R-8, DEC-2
**Primary files:** IR analytics service/page; reusable Perioperative calculation extraction

**Work:**

- [x] Reuse or extract FCOTS, turnover, utilization, and room-running definitions from existing Perioperative services/components.
- [x] Scope to rad_exams.is_ir and declared IR rooms/resources.
- [x] Add imaging-specific preparation/transport/read gates without altering core Perioperative definitions.
- [x] Document ownership and cross-link to Radiology and Perioperative operational surfaces.

**Acceptance:**

- [x] Identical interval fixtures produce identical Perioperative and IR calculations.
- [x] IR room denominators and operating windows are explicit.
- [x] No copied/divergent formula exists when a shared service can own it.

#### [x] R-14 — Register Radiology routes, APIs, navigation, policies, and ownership tests

**Depends on:** R-6 through R-13
**Primary files:** routes/web.php; routes/api.php; navigationConfig.ts; controllers; route/nav tests

**Work:**

- [x] Group the four existing Radiology operational bookmarks under the stable `radiology.*` route namespace without changing their URLs, controller actions, authentication middleware, or their position before the RTDC route group.
- [x] Name every authenticated Radiology read API and the Zephyrus-owned barrier action under `api.radiology.*` while retaining the existing `web`, `auth`, and throttling middleware contract.
- [x] Register one `RADIOLOGY` workspace domain in `navigationConfig.ts` with `/radiology` as its dashboard and exactly four operational leaves: Imaging Flow Board, Order Worklist, Modality Utilization, and Reads & Results.
- [x] Keep Radiology TAT and IR Suite Utilization as Analytics-owned Study leaves only; do not duplicate either Study inside the Radiology workspace.
- [x] Generalize section-menu dashboard deduplication so a domain dashboard that is also its first leaf renders once for Radiology and remains correct for every existing workspace.
- [x] Define a minimum-necessary `viewAncillaryPatientDetail` capability separately from `manageAncillaryBarriers`, using additive gate registration outside the protected authentication files.
- [x] Pass patient-detail authorization through both Inertia and API controllers and redact pseudonymous patient context in Flow Board, Worklist, and Reads contracts when the capability is absent.
- [x] Preserve barrier mutation authorization as an independent capability so a user may have operational patient context without automatically gaining annotation rights, and vice versa.
- [x] Emit server-owned, unit-scoped `/radiology/worklist?unitId=...&source=ancillary_services` drill targets for real Imaging services only; never fabricate a drill for fallback rows or non-Imaging services.
- [x] Surface the Imaging drill consistently in the RTDC ancillary matrix, expanded table, and unit modal with accessible link text/icons and preserve the destination query string.
- [x] Add page/API route-registration tests covering canonical names, controller ownership, middleware, stable bookmarks, and Radiology-before-RTDC ordering.
- [x] Add anonymous API rejection, authorized API smoke, global GET route smoke, patient-redaction, independent-capability, and server-drill contract coverage.
- [x] Add navigation single-source tests for exact ownership, dashboard deduplication, desktop section menu, mobile drawer, and command-palette parity.
- [x] Add Chromium smoke for desktop workspace ownership, mobile parity, command-palette navigation, and the RTDC Imaging handoff into the scoped Radiology worklist.

**Acceptance:**

- [x] Every Radiology page and API route returns its expected authentication behavior, controller action, and Inertia component/API contract.
- [x] Every Radiology operational and Study route has exactly one navigation owner; `/radiology` is not duplicated when the dashboard and first operational leaf are identical.
- [x] Desktop section menu, mobile drawer, and command palette derive the same four authorized Radiology operational leaves from `navigationConfig.ts`.
- [x] `/radiology`, `/radiology/worklist`, `/radiology/modality`, `/radiology/reads`, `/analytics/radiology-tat`, and `/analytics/ir-utilization` bookmarks remain unchanged, and Radiology routes remain registered before RTDC routes.
- [x] Patient context is included only for the minimum-necessary role set and is deterministically redacted for a role without the capability; barrier mutation remains independently policy checked.
- [x] RTDC Imaging handoff is server-owned, unit-scoped, browser-proven, and absent for non-Imaging or fallback-only rows.
- [x] Final R-14 verification passes: 135 combined backend tests with 2,014 assertions plus the final 3-test/74-assertion route-registration guard, 54 focused frontend tests, TypeScript, production build, UI canon, route smoke over 97 GET routes, four Chromium navigation/handoff tests, Pint, and `git diff --check`.

#### [x] R-15 — Pass the Radiology phase verification and release gate

**Depends on:** R-1 through R-14
**Primary evidence:** tests, screenshots, query plans, migration rehearsal, devlog

**Work:**

- [x] Run file-scoped and dirty Laravel Pint over every changed PHP implementation/test file and leave `git diff --check` clean.
- [x] Run focused temporal-invariant, deterministic operational-demo, serializer, Flow Board, Radiology/demo, migration, and formerly sequence-sensitive regression coverage.
- [x] Run the complete PHP suite and prove route smoke across every registered GET page with no server error.
- [x] Run the complete Vitest suite, TypeScript compiler, production Vite build, and UI-canon scanner after the final browser-driven fixes.
- [x] Inventory the complete route and scheduler surfaces, retaining all six canonical Radiology bookmarks, 13 named Radiology page/API routes, existing ancillary/OCEL cadence, and no new activation.
- [x] Rebuild the deterministic demo on migration-only `zephyrus_test` plus exact prerequisite catalogs; run refresh and independent strict validation at a frozen anchor.
- [x] Repair strict-gate defects in expected-discharge date semantics, evening census freshness, deterministic transport priority/overdue distribution, and final tuning ownership instead of accepting failed invariants.
- [x] Run full and ancillary-specific OCEL projections, reconciliation, and a second identical bounded projection to prove command-level idempotency.
- [x] Reconcile demo counts directly across shared orders/milestones, Radiology satellites, ED, discharge, IR/OR context, Cockpit publication, and OCEL source references.
- [x] Capture populated PostgreSQL query plans for the open worklist, bounded TAT Study, and bounded IR Study and prove each intended partial/composite index path.
- [x] Add a dedicated Playwright phase-gate specification covering all four operational pages and both Study pages in dark desktop plus representative light tablet/mobile viewports.
- [x] Assert semantic headings/main regions, theme state, document-level overflow, zero console/page errors, keyboard details/focus/theme behavior, and real breach/degraded/empty/stale states.
- [x] Repair the empty-SLA-scope JSON object contract and the screen-reader-only accessible-table overflow found by the rendered audit, with backend/unit/browser regression coverage.
- [x] Save and manually inspect 14 full-page screenshots spanning normal, breach, degraded, stale, and empty evidence with a synthetic-data/PHI review.
- [x] Clone the production-shaped migrated schema into a disposable database, roll the exact three-migration Radiology tail down and forward, seed governed references, inspect tables/constraints/indexes/catalogs/migration history, and drop the rehearsal database.
- [x] Reset the shared test database after verification and confirm zero ancillary orders, milestones, Radiology exams, and owned transport fixtures remain.
- [x] Publish the durable evidence index under `docs/evidence/ancillary/radiology-r15-2026-07-12/` and update this plan plus the ancillary devlog.

**Acceptance:**

- [x] Final PHP result is 1,031 passed tests and 16,664 assertions with one intentional skip; route smoke checks 97 GET routes with zero failures.
- [x] Final frontend result is 101 Vitest files and 421 tests, clean TypeScript, successful production build, successful UI canon, and clean diff whitespace.
- [x] Strict demo validation passes all 35 invariants with zero critical and zero warning failures and publishes exactly one gated Cockpit snapshot carrying Radiology evidence.
- [x] The canonical cohort reconciles to 26 orders/140 milestones overall and 16 orders/97 milestones/16 exams/29 reads/six scanners for Radiology, with real ED, discharge, and IR/OR joins.
- [x] Full OCEL reconciliation consumes all 140 ancillary source milestones; two ancillary-only runs return identical 140-event/122-object/566-E2O/158-O2O/140-change results.
- [x] Worklist, TAT, and IR plans use `ancillary_orders_open_idx`, `ancillary_orders_department_ordered_idx`, and `rad_exams_ir_scheduled_idx` respectively on populated demo facts.
- [x] Browser verification passes 15 canonical checks plus the isolated stale-state check across all six pages, with zero console/page errors and no document-level overflow.
- [x] The screenshot bundle explicitly contains normal, breach, degraded, stale, and empty states in both themes and representative desktop/tablet/mobile widths; manual review finds no direct identifier or report narrative.
- [x] The 74 MB disposable clone rolls the empty Radiology tail down and forward in milliseconds, retains the shared ancillary ledger, restores seven tables/49 validated constraints/seven indexes/six modalities/nine subspecialties, and is deleted afterward.
- [x] Rollback posture is evidence-backed: empty tail rollback is rehearsed; populated facts remain protected and require application rollback plus forward repair or verified restoration rather than destructive down migration.
- [x] No production interface, connector, credential, scheduler, queue, endpoint, feature flag, migration, deployment, or external system is activated by R-15.
- [x] The only accepted command warnings are the pre-existing 104 UI-canon line-height findings, stale Browserslist database, large existing Vite chunk, and Playwright color-environment warning; no functional, privacy, invariant, or release check is waived.

### Phase 2 — Pathology and Laboratory

#### [x] L-1 — Create clinical lab, pathology, and blood-bank satellites

**Depends on:** P0 complete
**Primary files:** Lab migration; app/Models/Lab/**; factories; reference seeder

**Work:**

- [x] Add `hosp_ref.lab_test_catalog` with deterministic UUID/key identity, local code, optional LOINC, governed department/test-family, expected TAT class, exact decision class, specimen type, active/effective interval, and object-shaped metadata.
- [x] Seed nine governed clinical-lab, microbiology, anatomic-pathology, frozen-section, type-and-screen, and crossmatch catalog entries with stable UUIDv5 identifiers.
- [x] Keep reference seeding idempotent and preserve catalog identity on replay.
- [x] Constrain `decision_class` to `ed_disposition`, `discharge_gate`, `or_gate`, or `none`; constrain department, TAT class, effective interval, and metadata shape.
- [x] Add `prod.lab_specimens` with stable UUID, source-scoped specimen identity, accession reference, shared ancillary order/source/encounter lineage, specimen/container/collector/method descriptors, lifecycle state, rejection/recollect evidence, demo ownership, and object metadata.
- [x] Add a self-referencing `parent_specimen_id` lineage so a rejected parent and pending child recollect remain distinct operational facts.
- [x] Enforce collection, transit, receipt, rejection, recollect, and cancellation timestamp/state evidence without fabricating absent collection times.
- [x] Add `prod.lab_results` with source key/version idempotency, specimen/order/catalog/source lineage, correction-parent lineage, local/LOINC snapshots, status/stage, abnormal/critical/auto-verification flags, analyzer reference, and operational timestamps.
- [x] Deliberately exclude result values, narratives, report text, and clinical payloads from the operational contract.
- [x] Represent preliminary, final, corrected, and cancelled results plus microbiology preliminary, organism-identification, susceptibility, and final stages.
- [x] Enforce result/correction timestamp order, auto-verification evidence, correction-parent evidence, source-version uniqueness, and object-shaped metadata.
- [x] Add `prod.lab_critical_values` with source identity, severity, callback state, identification/notification/acknowledgement/escalation/closure timestamps, recipient role, demo ownership, and object metadata.
- [x] Enforce closed-loop callback state evidence and monotonic notification/acknowledgement timing.
- [x] Add `prod.ap_cases` with a one-to-one shared pathology order, source/accession/specimen/procedure context, real `case_id`, encounter, AP type/stage timeline, frozen-section branch, pathologist reference, cancellation, demo ownership, and object metadata.
- [x] Enforce AP stage vocabulary, sequential processing timestamps, signed-out evidence, frozen-section state/timestamp evidence, and source identity.
- [x] Add `prod.bb_readiness` with a one-to-one shared blood-bank order, source request identity, real `case_id`, encounter, product class, readiness/type-screen/crossmatch states, unit counts, needed-by/expiry/readiness/issue timestamps, MTP branch, cancellation, demo ownership, and object metadata.
- [x] Enforce blood-product/readiness vocabularies, positive and internally consistent unit counts, lifecycle evidence, timestamp ordering, and MTP activation/closure order.
- [x] Add projection guards that require Laboratory, Pathology, and Blood Bank satellites to attach only to shared orders from their own department and matching encounter.
- [x] Require each lab result specimen and correction parent to belong to the same shared order, and reject Pathology/Blood Bank catalog entries from the clinical-result table.
- [x] Add source-natural-key uniqueness boundaries for specimens, versioned results, critical callbacks, AP cases, and blood-bank readiness requests.
- [x] Add pending-collection, pending-receipt, recollect-lineage, pending-decision, open-critical, AP stage-aging/frozen-OR, blood-bank OR-readiness, and active-MTP indexes.
- [x] Add Eloquent models with immutable time casts, object-safe JSON casts, source/order/encounter/catalog/ORCase relationships, correction/recollect inverse relationships, and operational query scopes.
- [x] Add inverse relationships on `AncillaryOrder` and `ORCase` using the repository's real `case_id` contract.
- [x] Add factories for pending/in-transit/rejected/recollect specimens; preliminary/auto-verified/critical/corrected/microbiology results; callback states; AP/frozen/signed-out cases; and T&S/crossmatch/issued/MTP blood-bank readiness.
- [x] Extend the guarded multi-schema PHPUnit baseline reset to include the new seeder-owned catalog while preserving migration-owned schemas.
- [x] Make catalog constraint installation idempotent when `prod` is rebuilt while `hosp_ref` persists between PostgreSQL test processes.
- [x] Add focused migration, guard, constraint, catalog, model, relationship, factory, scope, privacy-boundary, and rollback tests.
- [x] Rehearse empty satellite down/up while proving the shared ancillary order ledger survives, and prove populated satellites refuse destructive rollback.

**Acceptance:**

- [x] Recollect chains, micro prelim/final sequences, AP cases, frozen sections, T&S/crossmatch, MTP readiness, and critical callbacks are represented by valid factory-backed facts.
- [x] `ap_cases` and `bb_readiness` reference the real ORCase key `case_id`; schema tests prove neither table invents `or_case_id`.
- [x] Decision classes are database-constrained to `ed_disposition`, `discharge_gate`, `or_gate`, and `none`, and all four are covered by the governed catalog.
- [x] Factories create valid clinical-lab, AP, microbiology, and blood-bank scenarios with working shared-order, source, encounter, catalog, correction/recollect, and ORCase relationships.
- [x] Information-schema inspection proves `lab_results` exposes no result-value or narrative column.
- [x] Focused verification passes 20 tests and 421 assertions across L-1 migration/model/factory coverage and the expanded ancillary reference-seeder contract.
- [x] No production connector, credential, scheduler, endpoint, migration, deployment, or external system is activated by L-1.

#### [x] L-2 — Parse Lab OML/ORM order and collection messages

**Depends on:** L-1, P0-6
**Primary files:** Lab order normalizer/mapper; HL7 fixtures

**Work:**

- [x] Add a dedicated Laboratory HL7 v2 normalizer ahead of the generic ancillary fallback for governed LIS, lab-middleware, Laboratory, EHR, and CPOE sources.
- [x] Accept `OML`, `ORM`, and collection-bearing `ORU` families only when the source profile explicitly authorizes the family and Laboratory department.
- [x] Validate the HL7 envelope, message control identity, OBR presence, order identity, specimen identity, and source timestamp before canonical mapping.
- [x] Parse each ORC/OBR block with its own following SPM/OBX group rather than reading every repeated segment from the first occurrence.
- [x] Map ORC order control/status into `LAB_ORDERED` or `LAB_CANCELLED`, including CA/DC cancellation behavior.
- [x] Preserve both placer and filler order identities while using the filler/accession identity as the stable test-level source order key when present.
- [x] Retain the governed cross-source `reconciliation_key` behavior so multiple approved assertions can converge on one shared work item without weakening source-scoped natural keys.
- [x] Map OBR primary and alternate coding into local test code/label plus LOINC when the coding system is LN/LOINC.
- [x] Map ORC/OBR timing priority into `stat`, `urgent`, or `routine` and map PV1 class into emergency, inpatient, outpatient, perioperative, or unknown.
- [x] Pseudonymize patient, encounter, and collector references before canonical persistence; retain no raw direct identifier in canonical payloads or Laboratory facts.
- [x] Map SPM specimen identity, accession, type, container, collection method, and explicitly coded nurse-collect/lab-collect role.
- [x] Leave collector role null when the message does not assert an approved role rather than inferring one from a person name or identifier.
- [x] Prefer SPM-17 collection time and use OBR-7 only as the declared fallback; record the exact source field in specimen metadata.
- [x] Do not emit `LAB_COLLECTED`, set `collected_at`, or advance specimen status when neither SPM-17 nor OBR-7 asserts collection.
- [x] Expand one order message into one order event per OBR plus one collection event per explicitly collected specimen.
- [x] Support multiple SPM specimens within one OBR and multiple OBR tests within one message without collapsing their order, specimen, milestone, or provenance identities.
- [x] Ignore OBX clinical values during L-2 order/collection normalization while preserving the raw governed inbound envelope for later authorized processing.
- [x] Mark OBR-11 add-on orders explicitly in shared order metadata without inventing a distinct unsupported lifecycle milestone.
- [x] Add a dedicated FHIR Laboratory normalizer for enabled `ServiceRequest` and `Specimen` resources under the same governed source-family/department boundary.
- [x] Map ServiceRequest identifier, status, priority, authored time, patient/encounter context, local/LOINC code, and referenced specimens into the shared order/pending-specimen contract.
- [x] Normalize relative or absolute Specimen request references to a canonical `ServiceRequest/{id}` reconciliation identity.
- [x] Map FHIR Specimen collection time, type, container, method, collector role/reference, and business identifier into the same specimen fact created from ServiceRequest references.
- [x] Support both ServiceRequest-first and Specimen-first arrival; later authoritative order context enriches priority, class, test coding, and ordered time without changing source identity or regressing collected state.
- [x] Fail closed and dead-letter a FHIR Specimen that lacks either its ServiceRequest identity or an explicit collection timestamp.
- [x] Add `LabOrderProjector` to idempotently create/update source-scoped specimen facts, preserve advanced states, retain the first collection timestamp, and record conflicting later collection times rather than silently overwriting them.
- [x] Cancel only still-pending, uncollected specimens when a Laboratory cancellation arrives; never erase a collection already asserted.
- [x] Record canonical-to-`lab_specimens` provenance for every projected specimen and inbound message.
- [x] Extend late-link enrichment so a specimen-first order can safely acquire missing patient context, priority, test metadata, and authoritative ordered time from a later ServiceRequest.
- [x] Add seven golden HL7 fixtures covering ED STAT troponin, AM BMP, add-on CBC, cancellation, missing collection, ORU collection backfill, and a multi-test/multi-specimen panel with ignored OBX values.
- [x] Add focused pipeline tests for normalized event counts, milestones, state transitions, coding, pseudonymization, provenance, replay, cross-source reconciliation, reverse-order FHIR arrival, and dead-letter behavior.

**Acceptance:**

- [x] Fixtures cover ED STAT troponin, AM BMP, add-on test, cancelled order, multi-test/multi-specimen panels, and missing collection time.
- [x] Missing OBR-7/SPM-17 creates a pending specimen with no `LAB_COLLECTED`; a later ORU adds collection exactly once and replay does not duplicate the order, specimen, or milestone.
- [x] Source-scoped order/specimen identities remain stable under replay, while two governed sources can still reconcile one shared order and retain distinct source-scoped specimen facts.
- [x] FHIR ServiceRequest/Specimen resources converge in either arrival order, including absolute request references, without state regression or fabricated timestamps.
- [x] Canonical payload tests prove patient/collector identifiers and OBX values do not cross the operational boundary.
- [x] Focused L-2 verification passes 8 tests and 122 assertions; the complete ancillary feature regression passes 134 tests and 1,704 assertions.
- [x] No production connector, credential, source endpoint, scheduler, queue, route, migration, deployment, or external system is activated by L-2.

#### [x] L-3 — Parse Lab ORU results, rework loops, microbiology, and critical flags

**Depends on:** L-1, P0-6
**Primary files:** Lab ORU mapper/projector; fixtures

**Work:**

- [x] Add a dedicated Laboratory ORU normalizer ahead of the L-2 collection-only fallback for governed LIS, Laboratory, and middleware sources.
- [x] Claim an ORU only when it explicitly contains OBX result status, OBR-14 receipt/status, SPM rejection, or SPM parent/recollect evidence; preserve collection-only ORUs on the proven L-2 path.
- [x] Validate the HL7 envelope, source family/department, message control ID, OBR order/accession identity, OBX local/LOINC identity, result status, and required clocks.
- [x] Expand each OBR group independently and preserve every OBX result rather than collapsing a panel to its first observation.
- [x] Map OBR-7/SPM-17 collection, OBR-14 receipt, OBX-14 observation, OBX-19 analysis/result, OBR-22 result, and MSH-7 fallback with explicit timestamp-source metadata.
- [x] Emit `LAB_COLLECTED` only from collection evidence and `LAB_RECEIVED` only from OBR-14; withhold specimen receipt-state projection when collection is absent instead of fabricating a prerequisite.
- [x] Map OBX/OBR result status into preliminary, resulted-only, final/released, corrected, and cancelled result contracts.
- [x] Emit `LAB_PRELIM` for P/I/S, `LAB_RESULTED` for R, distinct `LAB_RESULTED` plus `LAB_VERIFIED` for F, `LAB_CORRECTED` for C, and `LAB_CANCELLED` for D/X.
- [x] Preserve source result key and explicit version when supplied; otherwise derive a stable operational key and timestamp-qualified version without using a result value.
- [x] Map primary/alternate local and LOINC codes, abnormal/critical flags, OBX equipment analyzer, OBX producer/middleware, and explicitly coded `AUTO_VERIFY` evidence.
- [x] Constrain abnormal flags to normal, abnormal, critical, or unknown and treat HH/LL/AA/critical codes as critical without reading the clinical value.
- [x] Resolve every result against the active governed Laboratory test catalog and reject Pathology/Blood Bank or unknown test identities.
- [x] Resolve the result specimen within the reconciled shared order, preferring the same governed source while rejecting ambiguous cross-source identity.
- [x] Project one `lab_results` row per source result key/version with catalog, specimen, analyzer, stage, timing, status, auto-verification, abnormal, and critical metadata.
- [x] Append corrected result rows with `parent_lab_result_id` lineage; require an existing prior version and never mutate or erase the original result timestamps.
- [x] Enrich a result-version row with a later verification assertion without duplicating it; exact inbound replay returns the existing canonical receipt.
- [x] Persist explicit `value_storage=excluded`/operational-only metadata and never copy OBX-5, Observation values, DiagnosticReport conclusion, or other clinical result payloads into canonical events or satellites.
- [x] Create one pending `lab_critical_values` fact for each critical result version with identification time and `notification_asserted=false`.
- [x] Do not emit `LAB_CRITICAL_NOTIFIED` or `LAB_CRITICAL_ACKED` from an abnormal/critical result flag alone.
- [x] Extend governed structured workflow normalization with source-result/version, callback timestamp, critical identity, and recipient-role fields.
- [x] Project explicit result-linked notification and acknowledgement milestones into the critical callback state machine with chronological guards and no state regression.
- [x] Preserve existing milestone-only demo/legacy critical workflows by requiring source-result detail before invoking the satellite callback projector.
- [x] Record canonical/inbound provenance for every Laboratory result, critical-value creation, and callback transition.
- [x] Map SPM-21 rejection reason into `LAB_REJECTED` with rejection time and retained collection/receipt history.
- [x] Map an SPM parent identity into `LAB_RECOLLECT_ORDERED`, require a previously rejected parent, advance the parent to recollect-requested, and create a distinct pending child linked by `parent_specimen_id`.
- [x] Prevent the child recollect from inheriting the original OBR-7 collection timestamp when the child does not assert its own SPM-17 time.
- [x] Preserve microbiology preliminary, organism-identification, susceptibility, and final stages as distinct versioned result facts under one stable result key.
- [x] Add a dedicated FHIR Laboratory result normalizer for enabled Observation and DiagnosticReport resources under the same governed source boundary.
- [x] Map FHIR basedOn ServiceRequest, Specimen, subject/encounter, local/LOINC coding, effective/issued clocks, status/version, interpretation, analyzer device, auto-verification extension, and microbiology-stage extension.
- [x] Support Observation and DiagnosticReport specimen reference shapes, relative/absolute ServiceRequest references, final/verified expansion, corrected parent versions, and cancellation.
- [x] Add nine golden ORU fixtures covering critical STAT/auto-verification, original and corrected BMP, hemolysis/recollect, four microbiology stages, and a multi-OBX result group.
- [x] Add FHIR Observation/DiagnosticReport final/corrected/version fixtures inline with deliberately forbidden values/conclusion text.
- [x] Add focused tests for exact TAT segments, correction lineage, rejection/recollect state, microbiology progression, multi-OBX catalog isolation, FHIR versions, privacy, replay, callback separation/transitions, provenance, and fail-closed orphan correction.

**Acceptance:**

- [x] Fixtures cover critical STAT, hemolyzed/recollect, auto-verification, corrected result, microbiology preliminary-to-final, and multi-OBX groups.
- [x] Corrected HL7 and FHIR results append parented versions, preserve the original row/timestamps, and reject an orphan correction.
- [x] Critical flags create a pending callback fact but no communication milestone; separately governed notification and acknowledgement events advance the callback loop exactly.
- [x] The STAT fixture reconciles to 25 minutes order-to-verify, 8 minutes collect-to-receive, 15 minutes receive-to-result, and 2 minutes notify-to-ack.
- [x] Canonical payload and satellite assertions prove exclusion of all fixture OBX values, FHIR values, and DiagnosticReport conclusion text.
- [x] Focused L-3 verification passes 7 tests and 109 assertions; the complete ancillary feature regression passes 141 tests and 1,813 assertions.
- [x] No production connector, credential, source endpoint, scheduler, queue, route, migration, deployment, or external system is activated by L-3.

#### [x] L-4 — Generate coherent Lab, AP, microbiology, and blood-bank demo data

**Depends on:** L-1 through L-3, P0-8
**Primary files:** LabDemoGenerator; profiles; invariants/tests

**Work:**

- [x] Expand Laboratory demo generation from five milestone-only orders to fourteen coherent shared orders with sixteen specimen facts, fourteen versioned result facts, and two critical callback facts.
- [x] Generate a six-order AM-draw wave spanning pending collection, collection, tube/courier transit, receipt, analysis, final result, manual verification, and explicit auto-verification branches.
- [x] Generate a four-order ED mix with STAT and routine work, one transport-pending specimen, one live critical troponin decision, one acknowledged critical callback, and one auto-verified chemistry result.
- [x] Project `LAB_IN_TRANSIT` into actual specimen state/timing with fail-closed collection-before-transit chronology instead of leaving transport as a milestone-only assertion.
- [x] Generate two independent rejection/recollect chains with same-order parent lineage, explicit clot/hemolysis reasons, recollect request timestamps, and distinct pending/complete child paths.
- [x] Represent one chemistry analyzer degradation as a bounded downtime window plus an explicit reroute on the affected final result; do not invent a parallel analyzer ledger before its governed contract exists.
- [x] Generate one pending and one fully acknowledged critical callback loop while preserving monotonic identify/notify/acknowledge clocks.
- [x] Add a dedicated Pathology generator with six shared `pathology` work items, four overnight-batch stages, one live frozen section, and one resulted frozen section.
- [x] Link every AP case and both frozen branches to existing non-deleted `prod.or_cases` rows while retaining current-versus-historical cohort labeling.
- [x] Generate four distinct microbiology result versions under one stable source result identity: preliminary, organism identification, susceptibility, and final.
- [x] Keep every microbiology result older than the current operational window and label it `historical_study_only`, making the multi-day cohort available to Study without contaminating live worklists.
- [x] Add a dedicated Blood Bank generator with six shared `blood_bank` requests spanning ordered, testing, type-and-screen ready, crossmatch ready, issued, and active-MTP states.
- [x] Derive each Blood Bank `needed_by` clock from the linked current OR schedule and preserve internally consistent requested/allocated/issued unit counts.
- [x] Attach exact decision contexts to the three live clinical-lab blockers: one real ED visit, one real discharge-candidate encounter, and one real OR case.
- [x] Attach exact OR-case decision contexts and explanations to every non-ready Blood Bank request and the live frozen-section gate.
- [x] Preserve owner-scoped deletion order for callback/result/recollect satellites, stable date-scoped natural keys, deterministic UUIDv5 AP/Blood Bank identities, collision refusal, and foreign-row survival.
- [x] Extend the canonical ancillary demo registry to report Laboratory, Pathology, and Blood Bank as distinct departments on the one shared spine.
- [x] Add seven invariant checks covering exact-owner satellite links, recollect lineage, callback chronology, downstream decision links, AP/Blood Bank OR links, microbiology window honesty, and the fixed AM distribution/analyzer branch.
- [x] Add a fixed-anchor feature gate proving cohort counts, 40-minute median AM receipt-to-result time, decision explanations, AP/frozen/Blood Bank joins, semantic replay identity, foreign-row survival, and zero failed critical invariants.

**Acceptance:**

- [x] The fixed-anchor cohort contains five completed AM results with a 40-minute median receipt-to-result interval, one bounded analyzer reroute, two valid recollect chains, and two chronologically valid critical loops.
- [x] All six AP rows and all six Blood Bank readiness rows link to valid non-deleted OR cases; the two frozen-section rows preserve in-progress/resulted timing evidence.
- [x] Exactly three current decision-pending clinical-lab rows resolve to and explain a real ED disposition, discharge gate, or OR gate; all other pending frozen/Blood Bank gates do the same.
- [x] Same-anchor refresh produces the same 47-order/246-milestone summary and semantic satellite snapshot, while non-owned Laboratory/AP/Blood Bank rows survive unchanged.
- [x] Focused L-4 verification passes 1 test and 38 assertions; the complete demo generator/scenario regression passes 9 tests and 126 assertions.
- [x] The complete ancillary feature regression remains green at 141 tests and 1,813 assertions after the shared `LAB_IN_TRANSIT` projection change.
- [x] No production connector, credential, source endpoint, scheduler, queue, route, migration, deployment, or external system is activated by L-4.

#### [x] L-5 — Implement Lab Flow Board at /lab

**Depends on:** L-1 through L-4, P0-10
**Primary files:** LabFlowBoardService; controller/API; Pages/Lab/FlowBoard.tsx

**Work:**

- [x] Add `LabFlowBoardService` as the sole owner of current-window cohorts, ratios, statuses, clocks, coverage, quality measures, callback state, filter options, and drill rows.
- [x] Exclude `historical_study_only` microbiology rows and orders older than 24 hours from the live Flow Board without removing them from Study-ready facts.
- [x] Render current stage distribution from the selected shared-order projection and retain an explicit empty contract.
- [x] Calculate STAT compliance on the server from order-to-selected-verify evidence against the governed 60-minute breach threshold; do not recompute ratios in React.
- [x] Render open/current order counts, exact pending ED/discharge/OR decisions, open critical callback count, and degraded-order coverage from server facts.
- [x] Calculate collection-to-receipt and receipt-to-result count/median/p90 distributions from specimen/result timestamps.
- [x] Detect transport and middleware evidence independently and downgrade missing feeds to named coarse clocks with null unavailable values rather than fabricated zero-duration segments.
- [x] Render critical callback total/open/oldest and state distribution from `prod.lab_critical_values`.
- [x] Render rejection, hemolysis, and contamination numerator/denominator/rate measures with accessible, explicit `benchmark` versus `local_policy` labels.
- [x] Label absent benchmark/policy configuration honestly as `External benchmark not configured` or `Site policy not configured`; do not invent a target.
- [x] Add ED, inpatient, discharge-gate, OR-gate, and degraded lenses plus validated priority, test-family, unit, and shift filters.
- [x] Add a bounded oldest-active drill with pseudonymous patient context, exact downstream decision explanation, current stage, age, unit, and existing barrier count.
- [x] Redact item patient context for roles without `viewAncillaryPatientDetail` while preserving aggregate operational measures.
- [x] Add governed Laboratory barrier annotation using the existing capability, barrier service, audit recorder, reason catalog, and open-breach linkage pattern.
- [x] Add authenticated `/lab`, `/api/lab/flow-board`, and `/api/lab/barriers` routes with stable names, request validation, private no-cache reads, and unauthorized API coverage.
- [x] Add a `Laboratory` workspace domain to `navigationConfig.ts`; the desktop menu, mobile drawer, command palette, active ownership, and local navigation all derive from that single entry.
- [x] Add strict Zod response validation, 30-second query refresh, responsive dual-theme page composition, accessible filter labels, status/freshness announcements, benchmark labels, and audited drawer semantics.
- [x] Replace per-row decision/barrier queries with correlated subselects so the bounded drill retains constant query shape.
- [x] Add focused service/Inertia/API/privacy/filter/barrier/route tests plus rendered Vitest coverage for operational, degraded, empty, benchmark, decision, and drawer states.

**Acceptance:**

- [x] The server service owns every displayed count, rate, interval, coverage state, and urgency state; TypeScript only validates and renders the contract.
- [x] Missing transport or middleware evidence produces `missing`/`coarse` coverage, a written explanation, and null unavailable intervals; tests prove the UI never reports zero.
- [x] Benchmark/local-policy kinds and their unconfigured labels are visible text, with source explanation retained for assistive technology.
- [x] Normal/current, degraded, empty, stale, source-error, privacy-redacted, forbidden-write, successful-write, validation, and unauthenticated states are tested.
- [x] Focused backend verification passes 4 tests and 57 assertions; focused frontend/navigation verification passes 29 tests.
- [x] The complete ancillary feature regression passes 145 tests and 1,870 assertions.
- [x] TypeScript, production build, UI canon, mobile dark and desktop light browser smoke, zero overflow, and zero console/page errors pass.
- [x] No production deployment, connector, credential, scheduler, queue, migration, or external source is activated by L-5.

#### [x] L-6 — Implement Specimen Tracker at /lab/specimens

**Depends on:** L-3 through L-5
**Primary files:** LabSpecimenService; page/API

**Work:**

- [x] Add an authenticated `/lab/specimens` Inertia page and private `/api/lab/specimens` JSON endpoint, both backed by one `LabSpecimenService` contract and one validated `LabSpecimenRequest` filter boundary.
- [x] Restrict the operational tracker to current Laboratory work: orders selected in the trailing 24-hour window with `historical_study_only` microbiology progression excluded from the live queue while its source facts remain intact for Study.
- [x] Use stable source-scoped accession and specimen identities plus the Zephyrus UUID for chain reconciliation; never use a direct patient identifier as the row identity.
- [x] Render a per-specimen event timeline containing order, collection, optional in-transit, receipt, rejection, recollect request, result, and verification stages in chronological workflow order.
- [x] Derive age from the evidenced specimen collection time with order time as the defined fallback, and retain the same `(sort_at, lab_specimen_id)` pair as the deterministic cursor ordering contract.
- [x] Project only operational result state: catalog label, status/stage, abnormal classification, auto-verification flag, critical flag, timestamps, and version count; exclude numeric/text result values, narratives, and raw source payloads.
- [x] Batch-load every specimen belonging to the page's selected orders so an order with multiple specimens is represented as distinct rows without losing sibling context.
- [x] Resolve recollect lineage to its root, arbitrary depth, position, parent, children, and active representative; guard traversal against malformed cycles.
- [x] Move a pending downstream decision to the deepest/current representative of its recollect chain and expose the representative UUID on the other chain members so the same ED, discharge, or OR decision is rendered exactly once.
- [x] Batch-load result assertions, test-catalog context, and pending decisions for all selected orders/specimens rather than issuing per-row queries.
- [x] Detect current transport timestamp coverage independently from specimen state and expose an explicit `available` or `missing` transport contract.
- [x] Remove the optional in-transit stage and its responsive grid column when transport evidence is absent; show a degraded-mode explanation stating that the tracker does not infer a zero-minute segment.
- [x] Calculate a selected-source freshness envelope and distinguish normal, degraded, no-data, stale, and source-error page states with written operator messages.
- [x] Add server-side filters for specimen status, catalog test family, operational unit, order priority, rejection/recollect state, and defined age bands.
- [x] Use opaque cursor pagination with a configurable bounded page size (maximum 50), stable next/previous cursor links, cursor-shape validation, and filter preservation between pages.
- [x] Add strict Zod validation for the complete page/API contract and a 30-second React Query refresh using the same request parameters as the server-rendered page.
- [x] Add Specimen Tracker beneath Laboratory Flow Board in the canonical `navigationConfig.ts` Laboratory workspace so desktop, mobile, command-palette, active-route, and local-navigation projections stay aligned.
- [x] Build the page with the canonical dashboard/page layout, freshness badge, accessible filter and pagination labels, dual-theme healthcare tokens, responsive timeline grids, explicit empty state, and narrow-screen wrapping for long source identities.
- [x] Declare the privacy boundary in the response: direct patient identifiers and result content are always absent; patient context is pseudonymous when permitted and redacted when the established capability is absent.
- [x] Add focused backend and frontend coverage for timeline construction, multiple specimens per order, length-two and length-three recollect chains, single decision representation, privacy redaction, transport degradation, filters, pagination, index use, bounded query shape, route parity, validation, authentication, populated rendering, and empty rendering.

**Acceptance:**

- [x] The fixed demo cohort returns 15 current specimens, including four rows in length-two recollect chains, three downstream decisions represented exactly once, and two orders with multiple distinct specimens.
- [x] A synthetic three-node recollect chain resolves one root, increasing depths/positions, correct parent/child links, one active representative, and one downstream decision rendered only on that representative.
- [x] Status, test-family, unit, priority, rejection/recollect, and age filters return the expected populations; next/previous cursors preserve deterministic ordering and malformed cursor shapes fail validation.
- [x] PostgreSQL `EXPLAIN` uses a `lab_specimens_*_idx` access path for the filtered tracker query.
- [x] Query count remains constant between five-row and fifteen-row page sizes and stays within the focused test budget, proving results, lineages, and decisions do not create N+1 query growth.
- [x] Multiple specimens attached to one order remain separately addressable and retain the correct common order identity.
- [x] Result objects contain only the approved operational keys, and service, Inertia, API, and rendered-page tests assert that clinical result values/narratives and direct patient identifiers are absent.
- [x] Missing transport evidence hides the transit stage/column, produces the degraded explanation, and never substitutes a zero duration.
- [x] Focused backend verification passes 4 tests and 58 assertions; the combined Specimen Tracker, Flow Board, and navigation frontend verification passes 32 tests across 3 files.
- [x] The complete ancillary feature regression passes 149 tests and 1,928 assertions.
- [x] TypeScript, production build, UI canon, mobile dark and desktop light browser smoke, zero horizontal overflow, and zero console/page errors pass.
- [x] No production deployment, production database, connector, credential, source endpoint, scheduler, queue, migration, or external system is activated by L-6.

#### [ ] L-7 — Implement Decision-Pending Results at /lab/pending-decisions

**Depends on:** L-1 through L-6
**Primary files:** LabDecisionPendingService; page/API; cross-domain aggregation

**Work:**

- Join pending tests to ED disposition, current discharge cohort, and OR gates using lab_test_catalog.decision_class plus encounter/case linkage.
- Rank by explicit downstream impact: live OR gate, discharge bed impact, ED disposition, then age/priority; expose the ranking reasons.
- Show what is pending, current stage, age, SLA, downstream decision, unit/case, freshness, and drill.
- Avoid inferring a gate from test name alone when the catalog/encounter context does not support it.

**Acceptance:**

- Feature tests cover all three decision classes, no-gate, stale, and completed cases.
- The same pending result appears consistently in the department queue and destination surface.
- Sort order is deterministic and explainable.
- This surface remains read-only except Zephyrus barrier annotation.

#### [ ] L-8 — Implement Blood Bank Readiness at /lab/blood-bank and Periop gates

**Depends on:** L-1, L-4, L-7
**Primary files:** BloodBankReadinessService; page/API; existing Periop timeline component/service

**Work:**

- Build today’s OR case matrix with requested products, T&S state, crossmatch state, issue state, source cutoff, and time to planned start.
- Add a compact blood-bank gate to the existing Perioperative case timeline.
- Show MTP activity as an operational state, not a clinical command.
- Define readiness based on case requirement plus current blood-bank state; no requirement means not-applicable, not ready.

**Acceptance:**

- A case missing required readiness is gated on both surfaces.
- A case without a blood product requirement is not falsely flagged.
- Time-to-start and source freshness are tested across date boundaries.
- No blood product allocation/writeback action exists.

#### [ ] L-9 — Implement AP Case Aging at /lab/anatomic-path

**Depends on:** L-1, L-4
**Primary files:** AnatomicPathologyService; page/API; Periop frozen-timer integration

**Work:**

- Render case aging by received, grossed, processing, slides-ready, diagnosed, and signed-out stages.
- Separate routine, complex, consult/send-out, and frozen-section cohorts.
- Show CAP-established benchmark lines with evidence label, not as universal policy.
- Add live frozen-section timer to the linked OR case during the active procedural window.

**Acceptance:**

- Aging bucket transitions remain correct as DemoClock advances.
- Frozen timer appears only for a valid active case and clears when result arrives.
- Overnight batch is represented as a structural stage, not unexplained idle time.
- Page supports degraded AP-LIS/backfill detail honestly.

#### [ ] L-10 — Add Lab critical-value and health metrics to Cockpit

**Depends on:** L-5, L-7
**Primary files:** FlowMetrics.php; KPI seeder; DrillBuilder ancillary table; tests

**Work:**

- Emit current STAT compliance, oldest decision-pending result, and open/at-risk critical callback state.
- Add Lab row/metrics to the existing Flow-domain ancillary health drill.
- Use server threshold definitions and freshness behavior.
- Keep individual critical results out of the house wall.

**Acceptance:**

- Snapshot, history, status parity, and drill tests pass.
- Flow Cockpit values reconcile to Lab Flow/Decision-Pending services.
- Stale data is unknown, not successful.

#### [ ] L-11 — Add the Lab axis to discharge readiness and ED chips

**Depends on:** R-11, L-7; blocks X-7
**Primary files:** shared AncillaryReadinessService; DischargePrioritiesService/page; ED board

**Work:**

- Extend each discharge readiness vector with the Lab axis and each authorized ED row with pending-lab state.
- Count only explicit decision-class gates for the encounter.
- Choose the top drill target deterministically and include pending count/oldest age/freshness.
- Preserve the imaging axis and the existing patient payload.

**Acceptance:**

- Imaging and Lab axes render together and reconcile with department queues.
- A routine/non-gating result does not block discharge.
- Completed/corrected results transition readiness correctly.
- Feature tests cover multiple pending labs and stale feed.

#### [ ] L-12 — Implement Lab TAT Study at /analytics/lab-tat

**Depends on:** L-5 through L-10
**Primary files:** LabTatAnalyticsService; Analytics page/API/navigation

**Work:**

- Provide median/P90 by test, priority, patient class, and shift.
- Render collect/transport/analytic/post-analytic waterfall, AM readiness curve, auto-verification trend, rejection/recollect rate, critical callback performance, and barrier Pareto.
- Separate clinical lab, microbiology, AP, and blood bank rather than mixing incomparable clocks.
- Include benchmark registry references, data coverage, and invalid interval counts.

**Acceptance:**

- Percentile, AM-by-hour readiness, and segment calculations pass fixed-fixture tests.
- Every chart names its clock and population.
- Historical microbiology/AP data is labeled outside the live operational window.
- Analytics owns the route uniquely.

#### [ ] L-13 — Register Lab routes, APIs, navigation, policies, and ownership tests

**Depends on:** L-5 through L-12
**Primary files:** routes; navigationConfig; controllers; route/nav tests

**Work:**

- Add the Lab Workspace domain, five page routes, read APIs, authorized barrier mutations, and Lab Study leaf.
- Add filtered drill links from RTDC Ancillary Services, ED, RTDC discharge, Periop case timeline, and Cockpit.
- Update navigation projections and active owner tests.
- Add API authentication, redaction, pagination, and validation tests.

**Acceptance:**

- All Lab routes render and are uniquely owned.
- Desktop/mobile/palette navigation is consistent.
- Patient/case detail is server-authorized.
- Existing Radiology and RTDC navigation remains intact.

#### [ ] L-14 — Pass the Lab phase verification and release gate

**Depends on:** L-1 through L-13
**Primary evidence:** tests, screenshots, query plans, migration rehearsal, devlog

**Work:**

- Run the full validation stack from R-15 plus lab parser fixtures, decision joins, AP/frozen, blood-bank, critical callback, and OCEL D1/D7 tests.
- Audit six operational/Study surfaces plus ED/RTDC/Periop compact joins.
- Save normal, rework, breach, degraded, stale, and empty screenshots.
- Rehearse additive migration and seeded backfill on production-shaped data.
- Record release and known limitations.

**Acceptance:**

- All gates pass and the full readiness vector remains backward compatible with imaging-only clients/tests.
- No live LIS/AP/blood-bank feed is activated without governance evidence.
- Phase evidence proves all three decision classes and cross-surface reconciliation.

### Phase 3 — Inpatient Pharmacy

#### [ ] X-1 — Create Pharmacy, ADC, administration, and discharge satellites

**Depends on:** P0 complete
**Primary files:** Pharmacy migration; app/Models/Pharmacy/**; factories; reference seeder

**Work:**

- Create rx_orders with RxNorm, NDC, priority/clock class, preparation branch, controlled/hazardous/shortage flags, and ancillary_order_id.
- Create rx_verifications, rx_preps, rx_dispenses, rx_administrations, adc_stations, adc_transactions, and rx_discharge_queue.
- Store administration source cutoff/as-of and import batch identity.
- Model discharge status transitions as a governed status field/history or milestones; do not rely on display text.
- Add source-scoped natural keys, order linkage indexes, station/unit rollup indexes, and discharge candidate indexes.

**Acceptance:**

- STAT, first dose, sepsis, ADC, IV batch, chemo, TPN, discharge, controlled, shortage, and discontinued scenarios are representable.
- RxNorm and NDC may both be null only under an explicit unmapped/local-code state.
- Administration rows cannot omit source cutoff/import identity.
- Factories never imply an individual diversion score.

#### [ ] X-2 — Parse RDE/RDS and verification-queue events

**Depends on:** X-1, P0-6
**Primary files:** Pharmacy HL7 and queue-event normalizers/mappers; fixtures

**Work:**

- Parse medication order identity, priority/timing, route, dosage form, local/NDC/RxNorm mapping candidates, encounter, and order status.
- Map FHIR MedicationRequest and MedicationDispense backfill into the same order/dispense identities; FHIR does not substitute for missing administration events.
- Map RDE orders, verification queue add/remove, verified state, RDS dispense, modification, and discontinuation.
- Define a versioned vendor-neutral verification-queue JSON envelope; Epic-specific mapping lives at the adapter edge.
- Preserve order changes as events and current projection without rewriting history.

**Acceptance:**

- Fixtures cover STAT sepsis antibiotic, routine first dose, IV order, discharge prescription, modify, discontinue, and duplicate queue event.
- Replay is idempotent and terminal state remains correct under late events.
- Missing terminology mapping produces an explicit unmapped flag, not a failed order.

#### [ ] X-3 — Ingest ADC transactions and station-level operational signals

**Depends on:** X-1, P0-6
**Primary files:** ADC normalizer/mapper; vendor mapping documentation; fixtures

**Work:**

- Define a canonical ADC transaction envelope for vend, refill, return, waste, override, discrepancy, and stockout with source/station/unit/time and optional order link.
- Map linked vends to RX_DISPENSED and persist all supported transactions for station/unit rollups.
- Keep unlinked overrides at station/unit operational level.
- Add terminology and station mapping with dead-letter/data-quality handling.
- Never generate user-level risk features, scores, ranks, or labels.

**Acceptance:**

- Linked vend creates an order milestone; unlinked override creates only an operational station transaction.
- Stockout, override, refill, waste, and discrepancy rollups are tested.
- API schemas and tests prove no individual risk score exists.
- Duplicate vendor transaction identity is rejected/idempotent.

#### [ ] X-4 — Implement warehouse/BCMA administration batch ingestion and freshness

**Depends on:** X-1, P0-6
**Primary files:** PharmacyAdministrationImport contract/command; importer; fixtures

**Work:**

- Define a vendor-neutral batch contract modeled on permitted warehouse fields, with source cutoff, extract ID, row identity, order linkage, and administration timestamp.
- Import idempotently into raw/canonical/projection/provenance rather than direct untracked inserts.
- Map administration to RX_ADMINISTERED only when order linkage is defensible; unmatched rows enter data-quality reconciliation.
- Propagate as-of cutoff to every administration-dependent service and metric.
- Support RAS as a future real-time source without making it the baseline assumption.

**Acceptance:**

- Reimport is idempotent and a corrected warehouse row appends/version-controls evidence.
- Unmatched administration does not attach to the wrong order.
- Every admin-derived response displays as-of freshness.
- Stale cutoff prevents real-time/compliant claims.

#### [ ] X-5 — Generate coherent Pharmacy and ADC demo data

**Depends on:** X-1 through X-4, P0-8
**Primary files:** PharmacyDemoGenerator; profiles; invariants/tests

**Work:**

- Generate queue surge, shift dips, priority clock classes, preparation branches, batch cutoffs/BUD, dispense/delivery, ADC transactions, missing-dose loops, shortages, discrepancies, and discharge-med statuses.
- Tie sepsis clocks to real demo ED encounters and discharge medication rows to the current discharge cohort.
- Generate administration through a warehouse cutoff separate from current dispense events.
- Include IVWMS-absent degraded scenarios.

**Acceptance:**

- Fixed-seed distribution and coherence tests pass.
- Discharge candidates, ED boarders, units/stations, and medication orders all resolve.
- Admin freshness and degraded prep branches are exercised.
- Refresh is idempotent and owner-safe.

#### [ ] X-6 — Implement Medication Flow Board at /pharmacy

**Depends on:** X-1 through X-5, P0-10
**Primary files:** PharmacyFlowBoardService; controller/API; Pages/Pharmacy/FlowBoard.tsx

**Work:**

- Render verification queue depth/age, STAT flags, first-dose clocks, sepsis timer segments, breach summary, preparation branch, source freshness, and barrier actions.
- Show real-time order-to-dispense separately from warehouse-qualified dispense-to-admin tail.
- Add lenses for priority, unit, branch, shortage, discharge, sepsis, status, and degraded feed.
- Keep clinical content/dose detail to minimum necessary operational fields.

**Acceptance:**

- Queue metrics and clock segments pass fixed-fixture tests.
- Sepsis display cannot imply a current administration when warehouse data is stale.
- Empty/stale/degraded/error and barrier states are tested.
- No pharmacist/user performance scoring appears.

#### [ ] X-7 — Implement Discharge Medication Readiness and complete the RTDC vector

**Depends on:** L-11, X-5, X-6
**Primary files:** PharmacyDischargeReadinessService; page/API; shared readiness; DischargePrioritiesService

**Work:**

- Build today’s planned discharges by status: not started, prior authorization pending, verification, filling/preparing, ready, delivered, unknown.
- Calculate target-relative age and ready-by-target percentage with explicit cohort definition.
- Add medication axis to RTDC readiness, making imaging + lab + medication complete.
- Deep-link both directions between discharge candidates and filtered pharmacy work.
- Treat PA pending as a workflow state/barrier, not a payer writeback.

**Acceptance:**

- Pipeline transitions and target calculations are tested.
- All three readiness axes render and reconcile to department services.
- Candidate with no discharge medications is not-applicable rather than falsely ready/blocked.
- Stale source is unknown/degraded.

#### [ ] X-8 — Implement IV Room and Batches at /pharmacy/iv-room

**Depends on:** X-1, X-5, X-6
**Primary files:** PharmacyIvRoomService; page/API

**Work:**

- Render current/next batches, BUD windows, TPN cutoff, chemo preparation timeline, active work, and waste measures.
- Separate policy/configuration from measured timestamps.
- Provide degraded verify-to-dispense view when IVWMS is absent.
- Do not expose clinical compounding recipes or actionable preparation instructions.

**Acceptance:**

- DemoClock cutoff/BUD calculations pass across day boundaries.
- Missing IVWMS removes unsupported stages and shows coverage.
- Waste/batch metrics declare denominator and time range.
- Accessibility and UI canon pass.

#### [ ] X-9 — Implement Dispense and Delivery at /pharmacy/dispense

**Depends on:** X-3, X-5
**Primary files:** PharmacyDispenseService; page/API

**Work:**

- Show station/unit stockout and override rates, shortage-flag context, vend-to-refill duration, missing-dose/re-request chains, and optional delivery segments.
- Separate measured operational reference lines from local policy.
- Add drill from station rollup to order-linked events only where authorization allows.
- Avoid names or user identifiers in outlier views.

**Acceptance:**

- Rollup math, missing-dose chains, and shortage filters are tested at demo scale.
- Queries use intended indexes.
- A station with no denominator shows no data, not zero percent.
- No individual-level output exists.

#### [ ] X-10 — Implement Controlled Substances operational view at /pharmacy/controlled

**Depends on:** X-3, X-5
**Primary files:** ControlledSubstanceOperationsService; page/API; safety tests

**Work:**

- Show open discrepancy count, age against shift-end policy, and override/discrepancy patterns by unit and station.
- Include an explicit page/API statement that diversion investigation and individual scoring are out of scope.
- Restrict access through an appropriate capability/policy if required by deployment governance.
- Provide aggregate export only if separately authorized and audited.

**Acceptance:**

- Service, DTO, API, page, tests, and exports contain no individual risk score or ranked staff list.
- Aging clocks and resolution transitions are tested.
- Unauthorized users receive the correct denial without leaking existence/detail.
- Status remains operational and non-accusatory.

#### [ ] X-11 — Add Pharmacy health metrics to Cockpit and an ED boarder medication lens

**Depends on:** X-6, X-9
**Primary files:** FlowMetrics.php; KPI seeder; DrillBuilder; ED service/page; tests

**Work:**

- Emit queue depth versus hour norm, oldest STAT unverified, sepsis clocks at risk, and shortage-drug stockouts as Flow-domain metrics.
- Complete the ancillary health table in the Flow drill with all three departments.
- Add a medication-delay lens to authorized boarded ED rows for home medications/antibiotics.
- Use current/warehouse split and source freshness.

**Acceptance:**

- Cockpit and workspace values reconcile.
- Stale administration cannot create a false sepsis success/failure.
- ED lens joins the correct boarded encounter.
- No standalone client-computed PharmacyTile is introduced.

#### [ ] X-12 — Implement Pharmacy TAT Study at /analytics/pharmacy-tat

**Depends on:** X-6 through X-11
**Primary files:** PharmacyTatAnalyticsService; Analytics page/API/navigation

**Work:**

- Provide verification/prepare/dispense/deliver/admin waterfall, median/P90 by priority/shift/unit/branch, queue depth heatmap, missing-dose Pareto, discharge readiness trend, and shortage impact.
- Separate real-time segments from warehouse-fed administration segments.
- Expose denominator, cohort, source cutoff, mapping coverage, and benchmark/reference classification.
- Avoid causal claims about shortages or staffing without designed analysis.

**Acceptance:**

- Heatmap, percentile, and segment calculations are unit-tested.
- Every admin-dependent chart has as-of labeling.
- Missing or unmapped data is quantified.
- Analytics owns the route uniquely.

#### [ ] X-13 — Register Pharmacy routes, APIs, navigation, policies, and ownership tests

**Depends on:** X-6 through X-12
**Primary files:** routes; navigationConfig; controllers; policies; route/nav tests

**Work:**

- Add Pharmacy Workspace domain, five page routes, read APIs, barrier mutations, and Study leaf.
- Add appropriate access control for controlled-substance aggregate view.
- Add drill links from RTDC Ancillary Services, discharge vector, ED boarder lens, and Cockpit.
- Update all navigation projection and route ownership tests.

**Acceptance:**

- Routes render, authorize, redact, paginate, and validate correctly.
- Desktop/mobile/palette navigation remains coherent with eight Workspace domains.
- Controlled page denial is tested.
- Existing Radiology/Lab/RTDC routes remain intact.

#### [ ] X-14 — Pass the Pharmacy phase verification and release gate

**Depends on:** X-1 through X-13
**Primary evidence:** tests, screenshots, query plans, migration rehearsal, devlog

**Work:**

- Run the full stack from prior phase gates plus RDE/RDS/queue/ADC/warehouse fixtures, freshness, discharge vector, controlled safety, and OCEL D11/D12 tests.
- Audit five workspace pages, one Study page, Cockpit drill, ED lens, and RTDC full vector.
- Save normal, surge, shortage, discharge-blocked, degraded, stale, and empty screenshots.
- Rehearse migrations/import on production-shaped data and document batch recovery.
- Record release and limitations.

**Acceptance:**

- All gates pass, including a test that searches code/contracts for forbidden individual risk fields.
- No live pharmacy/ADC/warehouse feed is activated without governance.
- Evidence proves the complete readiness vector and honest freshness split.

### Phase 4 — Predictive layer, benchmark pack, and final coherence

#### [ ] P4-1 — Add calibrated Radiology breach-risk sorting

**Depends on:** R-15, L-14, X-14
**Primary files:** RadiologyBreachRiskService; model artifact/config; worklist/flow services

**Work:**

- Define the prediction target and cutoff before training: open order breaches its applicable SLA within a declared horizon.
- Use only available-at-prediction features such as hour/day, modality, priority, patient class, queue depth, current stage age, scanner/downtime and staffing proxy freshness.
- Train/backtest on demo history only as a pipeline proof; label demo calibration as synthetic.
- Persist model version, feature schema, training window, calibration, and evaluation.
- Surface risk only as an optional sort/planning column with top contributing operational factors; never as a page alarm.

**Acceptance:**

- Time-based split prevents target leakage.
- Calibration, discrimination, coverage, and naive baseline comparison are reported.
- Missing/stale features produce unavailable or low-confidence, not a fabricated score.
- No clinical feature or protected attribute is introduced without review.

#### [ ] P4-2 — Add Lab AM-readiness forecasting

**Depends on:** L-14
**Primary files:** LabMorningReadinessService; Decision-Pending/RTDC huddle integration

**Work:**

- Predict the probability that explicit decision-class lab work will be verified before the configured rounds cutoff.
- Use current stage, collection/receipt status, test family, queue conditions, shift, analyzer context, and source freshness.
- Surface in Decision-Pending Results and the existing RTDC morning huddle as a planning aid with cutoff/model version.
- Keep the actual readiness vector based on observed state, not prediction.

**Acceptance:**

- Calibration and naive baseline comparison pass on fixed demo history.
- Observed and predicted readiness are visually/semantically distinct.
- No forecast converts an observed blocked axis to ready.

#### [ ] P4-3 — Add Pharmacy queue and stockout forecasts

**Depends on:** X-14
**Primary files:** PharmacyForecastService; Flow/Dispense pages

**Work:**

- Forecast queue depth by hour/day from time series and known scheduled demand.
- Forecast station-level stockout risk from dispense velocity, par/on-hand when available, refill cadence, and shortage flag.
- Avoid claiming stockout prediction when inventory/on-hand is absent; provide velocity pressure instead.
- Surface as planning series/sort, not alarms.

**Acceptance:**

- Forecast beats a declared seasonal/last-value baseline on demo backtest.
- Coverage and missing inventory limitations are explicit.
- No individual staff features are used.

#### [ ] P4-4 — Add Radiology follow-up recommendation tracking

**Depends on:** R-15
**Primary files:** follow-up migration/model/ingestion stub; Study widget; demo generator

**Work:**

- Add rad_followup_recommendations with recommendation identity, originating exam/read, recommended interval/category, tracking status, due/completed timestamps, source, and provenance.
- Keep recommendation text/content to minimum necessary structured fields.
- Add demo end-to-end progression and Study completion-rate widget.
- Treat published 28–77% range as an established reference, not a target.
- Keep outreach/writeback out of scope.

**Acceptance:**

- Projection and demo flow are idempotent.
- Due/completed/cancelled/unknown states are distinct.
- Completion metric defines denominator, cutoff, and follow-up window.

#### [ ] P4-5 — Implement the benchmark reference registry and chart integration

**Depends on:** all department Study pages
**Primary files:** benchmark migration/model/seeder/service; shared chart definitions

**Work:**

- Create the effective, cited benchmark registry described in section 6.6.
- Seed every benchmark used by a new Study chart from the PDF’s evidence set, with publication year and established/current label.
- Require chart services to return reference metadata; UI renders label/citation hover and never embeds numeric references.
- Add governance for updates and source retirement.

**Acceptance:**

- Every ancillary Study chart either has a cited reference or explicitly states no national benchmark.
- Older sources display established benchmark.
- Tests reject orphan metric keys, missing units, or invalid effective ranges.

#### [ ] P4-6 — Complete cross-module coherence and the RTDC ancillary summary transition

**Depends on:** P4-1 through P4-5
**Primary files:** AncillaryServicesService/page; shared readiness; Cockpit; ED/RTDC/Periop; analytics

**Work:**

- Replace synthesized Radiology/Lab/Pharmacy waits on the existing RTDC Ancillary Services page with spine aggregates while retaining other unsupported ancillary categories as clearly labeled deterministic demo/future data.
- Add department drill-through links with unit/service filters.
- Run one reconciliation service/test across Ancillary summary, department workspaces, readiness vector, ED chips, Periop gates, Cockpit Flow metrics, and Study counts.
- Standardize freshness, no-data, degraded, and clock-definition wording.
- Remove any temporary client-side status thresholds introduced during phased work.

**Acceptance:**

- Every summary tile lands on the correct filtered department view.
- Cross-surface counts and oldest-age metrics reconcile at one frozen anchor.
- Unsupported future services are not misrepresented as spine-backed.
- Existing RTDC route and consumer contract remain valid.

#### [ ] P4-7 — Pass final program verification, accessibility, release, and handoff

**Depends on:** P4-1 through P4-6
**Primary evidence:** complete test/release pack; runbook; devlog; decision register

**Work:**

- Run all validation in section 18, WCAG 2.2 AA keyboard/contrast/focus review, dark/light responsive audit, and performance/query plan review.
- Run end-to-end demo refresh, Cockpit publish, OCEL projection, Arena refresh, and cross-module reconciliation.
- Rehearse production migration/backfill and restore/forward-repair on a disposable copy.
- Create an integrations runtime addendum for each ancillary family: activation, health, replay, dead letters, source precedence, freshness, incident response, and rollback.
- Update this file’s status/task evidence and add docs/DEVLOG-ancillary-expansion.md.
- Release in bounded phase commits/PRs; deploy only merged, clean, current main through deploy.sh, then run explicit migrations.

**Acceptance:**

- All 60 tasks have linked evidence or a documented, approved deferral outside the program.
- No unresolved critical security, privacy, data-integrity, accessibility, or migration finding remains.
- Production activation checklists remain closed until source-specific governance and test-message evidence exist.
- The plan, code, tests, runtime, and devlog tell the same current-state story.

---

## 16. Task count and phase exit criteria

| Phase | IDs | Count | Exit criterion |
| --- | --- | ---: | --- |
| Shared spine | P0-1 through P0-10 | 10 | Canonical facts, clocks, integration, demo, OCEL, and shared UI work end to end |
| Radiology | R-1 through R-15 | 15 | Imaging module, joins, Cockpit, Study, navigation, and evidence complete |
| Lab/Pathology | L-1 through L-14 | 14 | Clinical lab/AP/blood bank, decision joins, second readiness axis, and evidence complete |
| Pharmacy | X-1 through X-14 | 14 | Medication/ADC/admin/discharge, full vector, safety boundary, and evidence complete |
| Predictive/polish | P4-1 through P4-7 | 7 | Forecasts, benchmarks, coherence, accessibility, runbooks, and final evidence complete |
| **Total** |  | **60** |  |

Phase exits are evidence gates, not calendar milestones. A phase may ship demo-only while live activation stays closed.

---

## 17. Suggested sprint packaging

The PDF estimates nine to twelve sprints. Use bounded vertical slices:

| Sprint | Primary package | Demonstrable outcome |
| --- | --- | --- |
| 1 | P0 schema/models/catalog/integration dispatcher | Raw synthetic ancillary event projects to an auditable milestone and clock |
| 2 | P0 SLA/demo/OCEL/shared UI | Coherent three-department demo spine, breach lifecycle, timeline, OCEL evidence |
| 3 | R ingestion/demo/Flow/Worklist | Imaging order can be followed from order to final with downstream impact |
| 4 | R modality/reads/joins/Cockpit | Scanner/read bottlenecks and imaging discharge axis are operational |
| 5 | R Study/IR/navigation/gate | Radiology phase shippable |
| 6 | L schema/parsers/demo/Flow/Specimens | Lab pre-analytic and result flow visible |
| 7 | L decision/blood bank/AP/joins/Cockpit | ED/discharge/OR decisions and second readiness axis work |
| 8 | L Study/navigation/gate | Lab phase shippable |
| 9 | X schema/parsers/ADC/warehouse/demo/Flow | Verification-to-administration flow visible with honest freshness |
| 10 | X discharge/IV/dispense/controlled/joins | Full readiness vector and pharmacy operational workspaces work |
| 11 | X Study/navigation/gate | Pharmacy phase shippable |
| 12 | P4 predictions/benchmarks/coherence/final gate | Complete program, audited and handed off |

If fewer sprints are required, combine only after dependency and review capacity are verified. Do not combine all three schemas/parsers into one high-risk migration release.

---

## 18. Verification matrix

### 18.1 Automated commands

Run proportionately on every task and fully at phase gates:

~~~bash
./vendor/bin/pint --dirty
php artisan test
npx tsc --noEmit
npm run test
npm run build
./scripts/check-ui-canon.sh
php artisan route:list
php artisan schedule:list
php artisan zephyrus:demo-refresh --anchor=<fixed-iso> --validate --json
php artisan zephyrus:demo-validate --anchor=<same-fixed-iso> --strict --json
php artisan ocel:project
~~~

Use actual package script names if npm run test differs; do not invent a command in CI. Parser and high-risk migration suites run before the full suite.

### 18.2 Test inventory required

| Layer | Required coverage |
| --- | --- |
| Migration | fresh up/down, production-shaped additive run, constraints, indexes, append-only guard, demo reset guard |
| Models | relationships, casts, scopes, natural keys, non-updatable milestones |
| Parsers | golden messages, multi-group, optional fields, timezone, malformed/dead-letter, replay/idempotency, source mismatch |
| Projection | out-of-order, duplicate, conflict, correction, cancellation, late encounter link, rebuild |
| SLA | warning/breach/clear, definition versions, staleness, missing milestones, DST, duplicate suppression |
| Demo | fixed seed, distributions, ownership, collisions, two-refresh idempotency, advanced anchor, invariants |
| Services | filters, pagination, percentiles, interval union, readiness joins, freshness, no N+1 |
| APIs | auth, policy, throttling, validation, redaction, idempotent mutations, 409 conflicts |
| Frontend | contract validation, every status/freshness/degraded state, accessibility, keyboard, reduced motion |
| Navigation | unique owners, workspace order, desktop/mobile/palette, route smoke |
| Cockpit | definition/status parity, snapshot history, Flow drill reconciliation, stale behavior |
| OCEL/Arena | object/event relations, idempotency, D1/D5/D7/D11/D12 evidence |
| Safety | no raw payload leakage, no individual diversion scoring, no source writeback |

### 18.3 Manual evidence

- Screenshots in dark and light for desktop, tablet, and representative wall view.
- Keyboard walkthrough of worklists, drawers, filters, tooltips, and drill links.
- Contrast/focus review and screen-reader spot check.
- Explain-one-number exercise: reconstruct one Radiology, Lab, and Pharmacy metric from source receipt through selected assertions and SLA definition.
- Degraded-mode exercise with each optional feed absent.
- Dead-letter/replay exercise for one message family per department.
- Demo refresh failure exercise proving last-known-good Cockpit preservation.
- Query plan capture at expected demo and projected production volumes.

### 18.4 Performance targets

Initial targets, measured and revised with real volume:

- Operational page aggregate API P95 under 750 ms at demo scale and under 1.5 s at projected single-facility peak.
- Paginated worklist P95 under 750 ms for indexed common filters.
- Cockpit metric refresh adds no more than 20% to current snapshot duration and isolates ancillary failures.
- SLA per-minute batch finishes within 30 seconds for the projected open-order population; use chunking and SKIP LOCKED/coordination if needed.
- Demo refresh ancillary step finishes within its scheduler window and reports counts/duration.
- Browser initial route bundle does not import all three modules eagerly; preserve Inertia lazy page loading.

---

## 19. Migration, backfill, deployment, and rollback

### 19.1 Release sequence per schema-bearing phase

1. Merge tested code with additive migration and feature flags/default-hidden navigation if the schema must land first.
2. Capture the required PostgreSQL backup before production schema change.
3. Deploy the exact merged main commit through ./deploy.sh.
4. Run sudo -u www-data php artisan migrate --force explicitly; deploy.sh does not run migrations.
5. Run reference seeders with the exact class and record their output.
6. Run demo refresh only on demo-enabled hosts.
7. Verify route/auth boundary, queue worker, scheduler, schema/indexes, source health, Cockpit snapshot, and representative pages.
8. Enable navigation/feature flag only after the read path and demo/live source state are healthy.

### 19.2 Backfill

- Backfill canonical source messages before projecting milestones when source evidence exists.
- Use bounded date/source/order scopes, dry-run counts, checkpoint/watermark, chunking, and resumability.
- Never synthesize missing historical milestones during live backfill.
- Rebuild current projections and SLA state after backfill; keep historical breaches distinguishable from live operational breaches if policy requires.
- OCEL projection follows milestone backfill and uses idempotent identities.

### 19.3 Rollback posture

- Before production data exists, an empty-schema down migration may be used in rehearsal.
- After append-only facts exist, application rollback hides routes/features while retaining schema and facts.
- Correct schema/data forward with a compatibility migration and rebuild command.
- Restore from the verified backup only for a declared incident requiring data rollback.
- Never disable append-only enforcement broadly or delete source evidence to make a rollback easier.

---

## 20. Risks and mitigations

| Risk | Detection | Mitigation / owner |
| --- | --- | --- |
| Site feed lacks optional milestones | Source capability/freshness and coverage metrics | Minimum-feed contracts and declared degraded pages; Integrations |
| Source timestamps conflict | Data-quality conflict flags and tolerance metrics | Retain assertions, governed precedence, explain selection; Data governance |
| Incorrect encounter/order linkage | Unmatched/reconciliation queue and invariant failures | Source-scoped identities, late link workflow, never guess across ambiguous candidates; Integration |
| SLA policy treated as universal clinical rule | Definition/evidence review | Effective versioning, local approval, benchmark separation; Department owner |
| Alarm fatigue | UI audit and alert-template inventory | Cockpit aggregate-only, earned urgency, no per-item wall alarms; Product/Design |
| Large queue query regression | Query-plan tests and runtime telemetry | Composite/partial indexes, cursor pagination, aggregate materialization; Engineering |
| Demo overwrites real data | Ownership collision checks | Exact owner, guarded reset, DEMO_MODE, invariant gate; Engineering/Ops |
| Admin freshness misrepresented | Contract tests and freshness invariant | Mandatory cutoff, unknown/stale state, separate real-time segment; Pharmacy/Data |
| Diversion-adjacent liability | Static/schema/API test | Unit/station aggregation only and restricted access; Pharmacy/Legal |
| Parser site variance | Golden fixtures and dead letters | Adapter profile/config, raw retention, replay, no silent coercion; Integrations |
| OCEL mapping overclaims readiness | Landscape readiness tests | Update readiness only with projected evidence; Process Intelligence |
| Cross-module count drift | Frozen-anchor reconciliation suite | Shared aggregation services and one source cutoff; Platform |
| Production rollback damages ledger | Migration rehearsal | Additive schema, feature rollback, forward repair, backup; Release owner |

---

## 21. Open questions and required evidence before live activation

### Design partner inventory

- EHR, RIS/PACS/reporting/CTRM, LIS/AP/blood bank/middleware, pharmacy/ADC/IVWMS, warehouse, transport/tube systems.
- Exact message families, versions, trigger events, profiles, field mappings, and delivery modes.
- Source timestamps, timezone behavior, resend/replay capability, sequencing, and outage behavior.
- Contracts, BAA, PHI approval, source identity, network allowlist, credentials, and non-production test channel.

### Operational policy inventory

- Department-approved clock definitions by patient class/priority/modality/test/med class.
- Critical notification/acknowledgment policy.
- Discharge-gate test/order catalog and ownership.
- OR blood-bank/frozen gate rules.
- Pharmacy queue/STAT/first-dose/discharge/shift-end definitions.
- Barrier reason ownership and escalation policy.

### Acceptance evidence

- At least one governed test message per supported family traverses raw, canonical, projection, provenance, query service, and UI.
- Duplicate/replay, malformed/dead-letter, source outage/staleness, corrected result, and clock conflict are exercised.
- Department leaders sign off on displayed clocks and degraded modes.
- Privacy/security sign off on identifiers, browser payloads, retention, access, and logs.

---

## 22. Implementation evidence ledger template

For every completed task, add or link:

| Field | Required content |
| --- | --- |
| Task ID | P0-1, R-6, and so on |
| Commit/PR | Exact SHA and PR |
| Files | Main implementation and migration paths |
| Tests | Exact focused and full commands/results |
| Data evidence | Fixture/demo/live source and source cutoff |
| UI evidence | Screenshot paths and audit notes |
| Migration | Rehearsal duration, row counts, constraints, backup/rollback |
| Runtime | Route, scheduler/worker, health, freshness, representative response |
| Limitations | Missing feed/degraded behavior, activation gate, deferred work |
| Plan update | Checkbox/status and any approved decision change |

---

## 23. Final handoff checklist

- [ ] All 60 tasks have an evidence row.
- [ ] DEC-1 through DEC-7 are final or clearly retain their defaults.
- [ ] No unresolved schema, replay, SLA, source precedence, or cross-module ambiguity remains.
- [ ] The source-to-screen lineage is demonstrable for one case in each department.
- [ ] Demo mode remains coherent with zero external feeds.
- [ ] Production connector mode is closed by default and separately governed.
- [ ] Cockpit uses server snapshot metrics; no client-computed shadow source exists.
- [ ] RTDC Ancillary Services is spine-backed for Radiology/Lab/Pharmacy and honest for remaining services.
- [ ] Readiness axes reconcile across RTDC, ED, Periop, and department queues.
- [ ] Study metrics show clock, population, percentile, cutoff, and benchmark classification.
- [ ] OCEL/Arena readiness claims match actual projected evidence.
- [ ] Accessibility, UI canon, performance, security, and privacy gates pass.
- [ ] Runbooks cover activation, health, dead letters, replay, precedence, staleness, backfill, incident response, and rollback.
- [ ] Plan, devlog, routes, tests, deployed runtime, and release evidence agree.

---

## 24. Plan authority and maintenance

This Markdown file is the engineering-facing implementation plan of record for the PDF. The PDF remains the strategic/product brief and evidence narrative. When implementation reveals a repository constraint, update this plan with the decision and evidence; do not silently diverge in code. When a task ships, check it only after its acceptance and phase evidence exist.

Related repository authorities:

- docs/Zephyrus_Ancillary_Expansion_Plan.pdf — source brief.
- docs/ZEPHYRUS-2.0-BETA-PRD.md — beta/product planning authority.
- docs/ZEPHYRUS-2.0-PLAN.md and docs/ZEPHYRUS-2.0-PART-X.md — altitude/Cockpit/process-intelligence context.
- docs/DEMO-DATA-COHERENCE-AND-REMEDIATION-PLAN-2026-07-10.md — one-clock demo and invariant contract.
- docs/superpowers/plans/2026-06-25-real-time-healthcare-data-acquisition.md — integration architecture.
- docs/superpowers/plans/2026-07-10-ocel-hospital-model-landscape-implementation-plan.md — OCEL process portfolio.
- docs/operations/INTEGRATIONS-RUNTIME-RUNBOOK.md — current connector operations boundary.
- CLAUDE.md, PRODUCT.md, DESIGN.md, and AGENTS.md — code, product, design, navigation, and deployment constraints.
