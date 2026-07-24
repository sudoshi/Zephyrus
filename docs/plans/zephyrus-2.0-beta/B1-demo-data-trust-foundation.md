# B1 - Demo Data And Trust Foundation

Goal: make HOSP1/Summit demo data repeatable, credible, and visibly labeled with source/provenance/freshness across cockpit, domain pages, Hummingbird, and Eddy.

Primary source: PRD section `B1 - Demo Data And Trust Foundation`.

Exit principle: every visible beta signal must be explainable: where it came from, when it refreshed, whether it is live or synthetic, and what confidence/fallback state applies.

## Current Evidence

- `app/Console/Commands/DemoSeedCommand.php` exists.
- Patient Flow synthetic import/rebase commands exist.
- The multi-schema integration foundation exists.
- Cockpit metric trust and serving tables exist.
- Synthetic connector tests exist.
- Some legacy frontend paths still return mock/fallback data without a beta-grade provenance surface.

## Deliverables

- [ ] Repeatable HOSP1/Summit reset runbook.
- [ ] Seed/import/rebase commands wired into a single beta rehearsal flow.
- [ ] Source registry and provenance records for every visible hero metric.
- [ ] Visible synthetic/live/fallback labels across web, mobile, and Eddy.
- [ ] Stale-feed and degraded-feed demo state.
- [ ] Integration Health page/control surface that can show watermarks, dead letters, replay, provenance, and writeback drafts.

## Phase Entry Gate

B1 may start only after:

- [ ] B0 has settled or time-boxed D1, D2, D6, D7, and D13.
- [ ] The target database is explicitly named: local, test, staging, or production-like beta.
- [ ] The operator has permission to run data-writing commands in that environment.
- [ ] A pre-seed backup, disposable database, or rollback decision is recorded.
- [ ] `VALIDATION-INVENTORY.md` has been checked for command signatures.

Preflight commands:

```bash
git status --short --branch
php artisan config:show app.env
php artisan config:show database.default
php artisan config:show hospital.default_facility
php artisan config:show facility_models.zep_500
php artisan list --raw | rg "zephyrus:demo-seed|patient-flow:import-synthetic|patient-flow:rebase-synthetic|rtdc:demo-reset|flow:snapshot"
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Facility identity reconciliation | Data/Integration Agent | QA Agent | B2/B3/B4 owners | HOSP1/ZEPHYRUS-500 proof queries and UI label notes |
| Demo reset runbook | Data/Integration Agent | Ops Agent | B8 owner | seed/rebase/import command log and idempotency log |
| Source/provenance contract | Backend Agent | Security Agent | B2/B5/B6 owners | API metadata examples and source registry rows |
| Synthetic/live labeling | Frontend Agent | Security Agent | B3/B8 owners | screenshots/API samples proving labels |
| Degraded feed scenario | Data/Integration Agent | Eddy/Backend Agent | B2/B5/B8 owners | stale/degraded source state, cockpit screenshot, Eddy sample |
| Integration Health readiness | Backend/Frontend Agent | Security Agent | B7/B8 owners | admin-only route proof and screenshot |

## Agent Execution Contract

Owned write scope:

- Demo seed commands and seeders only when needed to make the runbook deterministic.
- Source registry/provenance services and tests.
- Demo-data docs/evidence.
- UI/API labels for synthetic/demo/live state when B1 owns the label source.

Read-only first:

- `app/Console/Commands/DemoSeedCommand.php`.
- `app/Console/Commands/PatientFlowImportSyntheticCommand.php`.
- `app/Console/Commands/PatientFlowRebaseSyntheticCommand.php`.
- `config/hospital.php`.
- `config/hospital/hospital-1.php`.
- `config/facility_models.php`.
- `config/patient_flow.php`.
- `database/seeders/*`.
- `app/Integrations/Healthcare/*`.
- `routes/api.php`.

Do not touch:

- Patient Flow history/scenario implementation details owned by B4, except for seed inputs and scenario keys.
- Eddy tool execution semantics owned by B5.
- Production deployment behavior owned by B8.

## Required Environment And Config Contract

Record actual values before and after the reset:

| Key | Expected Beta Meaning | Source |
| --- | --- | --- |
| `APP_ENV` | controls local/demo defaults and must not hide production behavior | `config/app.php` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | target database; never assume local if command writes | `config/database.php` |
| `HOSPITAL_DEFAULT_FACILITY` | default facility manifest key | default `SUMMIT_REGIONAL` |
| `ZEPHYRUS_500_FACILITY_CODE` | immutable CAD/RTDC join key | default `ZEPHYRUS-500` |
| `ZEPHYRUS_500_FACILITY_NAME` | display name | default `Summit Regional Medical Center` |
| `ZEPHYRUS_500_FACILITY_SHORT_NAME` | short display name | default `Summit Regional` |
| `ZEPHYRUS_500_MODEL_URL` | facility GLB path | default `/vendor/zephyrus-facility-models/zep-500/hospital_model.glb` |
| `ZEPHYRUS_500_TILESET_URL` | facility tileset path | default `/vendor/zephyrus-facility-models/zep-500/tileset.json` |
| `PATIENT_FLOW_DEMO_BARRIERS` | enables demo barrier overlay outside production/testing by default | `config/patient_flow.php` |
| `PATIENT_FLOW_DEMO_BARRIERS_REPLACE_REPLAY` | lets demo overlay replace sparse replay outside production/testing by default | `config/patient_flow.php` |

## Executable Demo Reset Contract

Preferred reset command:

```bash
php artisan zephyrus:demo-seed
```

Optional migration-inclusive reset for disposable environments only:

```bash
php artisan zephyrus:demo-seed --migrate
```

Patient Flow import is not a no-argument command. If bypassing `zephyrus:demo-seed`, use:

```bash
php artisan patient-flow:import-synthetic patient-flow-4d-navigator/data/hl7_messages.ndjson --source-key=synthetic-flow-ehr --facility-code=ZEPHYRUS-500
php artisan patient-flow:rebase-synthetic --anchor=now
php artisan flow:snapshot
```

Required scenario keys must be recorded in B1 even if B4 implements the scenario registry:

| Scenario Key | PRD Scenario | Data Owner | B4/B7/B8 Consumer |
| --- | --- | --- | --- |
| `house_glance` | House executive glance | B1/B2 | B8 |
| `ed_boarder_to_bed` | ED boarder to inpatient bed | B1/B4/B7 | B5/B6/B8 |
| `icu_downgrade_capacity` | ICU downgrade unlocks capacity | B1/B4 | B8 |
| `or_delay_pacu_hold` | OR delay/PACU hold pressure | B1/B7 | B8 |
| `discharge_barrier_resolution` | Discharge barrier resolution | B1/B4 | B5/B6/B8 |
| `staffing_gap_safe_capacity` | Staffing gap and safe capacity | B1/B7 | B6/B8 |
| `regional_transfer_pressure` | Regional transfer/external demand | B1/B7 | B8 |
| `improvement_study_handoff` | Improvement and Study handoff | B1/B7 | B8 |
| `trust_degraded_feed` | Trust, provenance, degraded feed | B1/B2 | B5/B8 |

## Proof Queries And Checks

Capture DB/API output proving:

- [ ] configured facility display key is `SUMMIT_REGIONAL`/`HOSP1` where appropriate.
- [ ] immutable CAD facility code remains `ZEPHYRUS-500`.
- [ ] facility spaces and operational unit/bed mappings exist after seed/import.
- [ ] `flow_core.flow_events` has rows and a current rebased `max(occurred_at)`.
- [ ] `flow_core.occupancy_snapshots` has rows after `flow:snapshot`.
- [ ] integration source rows identify `synthetic-flow-ehr` as synthetic/demo.
- [ ] source watermarks or freshness records show an as-of timestamp.
- [ ] repeat run does not duplicate rows beyond expected upsert/update behavior.

Suggested API proof:

```bash
php artisan route:list --path=api
curl -fsS -H "Cookie: <redacted-beta-session>" http://127.0.0.1:8001/api/cockpit/snapshot
curl -fsS -H "Cookie: <redacted-beta-session>" "http://127.0.0.1:8001/api/patient-flow/occupancy?include=eddy_context"
```

Adjust host/port/auth to the environment and redact payloads before committing evidence.

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| `zephyrus:demo-seed` run | Existing | command output with exit code |
| `patient-flow:import-synthetic <path>` | Existing, path required | output or proof that `zephyrus:demo-seed` invoked it |
| `patient-flow:rebase-synthetic` | Existing | anchor timestamp and output |
| `flow:snapshot` | Existing | snapshot row/API proof |
| seed idempotency test | To create or manual proof | two run logs and stable counts/upsert evidence |
| source label assertions | To create or extend tests | API/DB assertions that synthetic/demo/live state is explicit |
| degraded feed scenario | To create | stale source state, cockpit/UI proof, Eddy caveat input for B5 |

## Criticality Double Check

B1 must pass or defer these criticalities:

- Data trust.
- Data integrity.
- Requirements traceability.
- API contract.
- PHI/privacy.
- Observability.
- Testing.
- Rollback.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B1/README.md`.
- [ ] `commands/zephyrus-demo-seed.txt`.
- [ ] `commands/patient-flow-rebase-synthetic.txt` if used.
- [ ] `commands/flow-snapshot.txt`.
- [ ] `api/cockpit-snapshot-source-labels.json`.
- [ ] `api/patient-flow-occupancy-source-labels.json`.
- [ ] `reviews/data-idempotency-review.md`.
- [ ] `reviews/synthetic-live-label-review.md`.
- [ ] degraded feed evidence for B2/B5/B8.

## Workstream 1.1: HOSP1/Summit Facility Identity

- [ ] Confirm the facility naming rule:
  - [ ] Internal code remains `HOSP1`.
  - [ ] Demo-facing label is `Summit`.
  - [ ] No screenshot or demo copy mixes conflicting facility names unless explaining lineage.
- [ ] Verify `config/hospital/hospital-1.php` contains the intended public-facing labels.
- [ ] Audit the cockpit, Patient Flow, mobile, and reports for hardcoded facility labels.
- [ ] Add a test or snapshot fixture that ensures the cockpit facility label matches the config source.
- [ ] Add a demo operator note explaining what is synthetic about the facility and what is product behavior.

Verification:

```bash
rg -n "HOSP1|Summit|Hospital 1|hospital-1" config app resources docs hummingbird
php artisan test --filter=Hospital
```

## Workstream 1.2: Repeatable Demo Reset Runbook

- [ ] Create a committed runbook for a fresh beta demo reset.
- [ ] Include exact preconditions:
  - [ ] Branch name and commit hash.
  - [ ] Clean worktree expectation.
  - [ ] Database target.
  - [ ] Queue/scheduler/Reverb expectations.
  - [ ] Required `.env` values.
- [ ] Include exact reset commands:
  - [ ] `php artisan migrate`.
  - [ ] `php artisan zephyrus:demo-seed` with selected flags.
  - [ ] Patient Flow synthetic import.
  - [ ] Patient Flow synthetic rebase.
  - [ ] Cockpit materialized view refresh.
  - [ ] Cockpit snapshot refresh.
  - [ ] Optional mobile token/device fixture setup.
- [ ] Include post-reset proof queries:
  - [ ] Facility row exists.
  - [ ] Source registry rows exist.
  - [ ] Cockpit snapshot row exists.
  - [ ] Metric history rows exist.
  - [ ] Patient Flow occupancy snapshots exist.
  - [ ] Mobile BFF `/me` returns expected roles.
  - [ ] Eddy action catalog returns expected enabled/disabled tools.
- [ ] Include a one-command dry run if supported, or add a wrapper command if it does not exist.

Suggested commands:

```bash
php artisan zephyrus:demo-seed
php artisan patient-flow:rebase-synthetic --anchor=now
php artisan flow:snapshot
timeout 120s php artisan schedule:run -vvv
php artisan route:list | rg "cockpit|patient-flow|mobile|eddy"
```

Only run `php artisan migrate` or `php artisan zephyrus:demo-seed --migrate` in a disposable/local environment or when the release plan explicitly authorizes schema changes.

Implementation note:

- Current repo evidence shows cockpit refresh is scheduled through `App\Jobs\RefreshCockpitSnapshot`, not exposed as a standalone artisan command. Use `timeout 120s php artisan schedule:run -vvv` in an approved writing environment for the verified path, or add a dedicated one-off refresh command before documenting one.

## Workstream 1.3: Source Registry And Provenance Completeness

- [ ] Inventory all beta hero metrics:
  - [ ] Cockpit house status.
  - [ ] RTDC capacity.
  - [ ] ED strain and NEDOCS.
  - [ ] Periop utilization and delays.
  - [ ] Transport/EVS waits and turnaround.
  - [ ] Staffing risk and fill rate.
  - [ ] Improvement/process bottlenecks.
  - [ ] Service-line, financial, and quality scorecards.
  - [ ] Patient Flow barriers and projections.
  - [ ] Hummingbird For You cards.
  - [ ] Eddy context summaries.
- [ ] For each metric, require:
  - [ ] `metric_key`.
  - [ ] Source system or source registry key.
  - [ ] Source mode: live, synthetic, seeded, derived, fallback.
  - [ ] `as_of`.
  - [ ] Refresh cadence.
  - [ ] Freshness status.
  - [ ] Confidence score or confidence bucket.
  - [ ] Lineage summary.
  - [ ] Fallback state.
  - [ ] Last successful refresh.
  - [ ] Next expected refresh or stale threshold.
- [ ] Add a test that fails if a cockpit/mobile/Eddy hero metric omits these fields.
- [ ] Add UI rendering for source/freshness/synthetic state in compact form.
- [ ] Add mobile rendering for source/freshness/synthetic state where a card drives action.
- [ ] Add Eddy context text that can cite source/freshness without exposing PHI.

Suggested tests:

```bash
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=CockpitMetricValuesTest
php artisan test --filter=MobileBffTest
php artisan test --filter=Eddy
```

## Workstream 1.4: Synthetic And Fallback Labeling

- [ ] Create a single label vocabulary:
  - [ ] Live.
  - [ ] Synthetic.
  - [ ] Seeded.
  - [ ] Derived.
  - [ ] Fallback.
  - [ ] Stale.
  - [ ] Degraded.
- [ ] Apply the vocabulary to API payloads.
- [ ] Apply the vocabulary to cockpit cards and drill surfaces.
- [ ] Apply the vocabulary to Patient Flow inspector and scenario controls.
- [ ] Apply the vocabulary to Hummingbird cards and detail screens.
- [ ] Apply the vocabulary to Eddy citations, proposals, and action previews.
- [ ] Add screenshot evidence for at least one live label, one synthetic label, one fallback label, and one stale label.
- [ ] Add a release-note section naming every intentionally synthetic beta metric.

Do not allow:

- [ ] Unlabelled mock data in beta cockpit.
- [ ] Unlabelled seeded data in mobile action recommendations.
- [ ] Eddy proposals that do not cite source/freshness for operational claims.

## Workstream 1.5: Degraded Feed And Stale Feed Scenario

- [ ] Add or document a demo control that simulates feed degradation without corrupting live demo data.
- [ ] Prove cockpit stale banner behavior.
- [ ] Prove mobile stale/fetch-on-open behavior.
- [ ] Prove Eddy refuses or downgrades high-risk recommendations when source freshness is stale.
- [ ] Prove Integration Health shows stale watermarks.
- [ ] Prove Patient Flow projections mark stale inputs.
- [ ] Add tests for freshness threshold behavior.

Suggested tests:

```bash
php artisan test --filter=Stale
php artisan test --filter=Freshness
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=PatientFlowApiTest
```

## Workstream 1.6: Integration Health Console

- [ ] Expand admin integration health from count/status into an operator console.
- [ ] Surface:
  - [ ] Source registry records.
  - [ ] Active/planned/disabled status.
  - [ ] BAA/PHI status.
  - [ ] Last poll time.
  - [ ] Last webhook time.
  - [ ] Watermark.
  - [ ] Dead-letter count.
  - [ ] Latest dead-letter reason.
  - [ ] Replay jobs.
  - [ ] Projection offset.
  - [ ] Projection errors.
  - [ ] Provenance record counts.
  - [ ] Writeback drafts.
  - [ ] Credential status without exposing secrets.
- [ ] Add admin-only authorization tests.
- [ ] Add a replay dry-run path for demo operators.
- [ ] Add a "real integration not connected" label where beta uses synthetic source state.

Suggested files:

- `app/Http/Controllers/Api/Admin/IntegrationHealthController.php`.
- `app/Integrations/Healthcare/Services/SourceRegistryService.php`.
- `app/Integrations/Healthcare/Services/EnterpriseConnectorControlService.php`.
- Admin frontend page under `resources/js`.

## Workstream 1.7: Data Integrity And Idempotency

- [ ] Ensure demo seed can run twice without duplicate hero data.
- [ ] Ensure Patient Flow synthetic import can run twice without duplicated active tracks.
- [ ] Ensure rebase preserves relative demo timing.
- [ ] Ensure source registry and provenance identifiers remain stable.
- [ ] Ensure cockpit snapshot rebuild after seed produces current `as_of` values.
- [ ] Add tests for idempotency and duplicate prevention.
- [ ] Add a cleanup step for stale synthetic artifacts from earlier local runs.

Verification:

```bash
php artisan zephyrus:demo-seed
php artisan zephyrus:demo-seed
php artisan patient-flow:import-synthetic patient-flow-4d-navigator/data/hl7_messages.ndjson --source-key=synthetic-flow-ehr --facility-code=ZEPHYRUS-500 --dry-run
php artisan patient-flow:rebase-synthetic --anchor=now --dry-run
php artisan flow:snapshot
php artisan test --filter=SyntheticHealthcareConnectorTest
```

## Phase Exit Gate

This phase is complete only when:

- [ ] A fresh developer can reset demo data using one documented runbook.
- [ ] Every beta hero metric exposes source/freshness/synthetic/fallback state.
- [ ] Synthetic values are never visually indistinguishable from live values.
- [ ] Stale/degraded feed behavior is visible and tested.
- [ ] Integration Health can support an operator conversation about source state, watermarks, dead letters, replay, and provenance.
- [ ] Seed/import/rebase commands are idempotent or document safe cleanup.
- [ ] B1 tests and smoke checks pass or exact blockers are logged in the known-limitations register.
