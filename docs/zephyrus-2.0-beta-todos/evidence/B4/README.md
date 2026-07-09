# Evidence - B4

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: `3093c355efdc6d2bbdf3310f30d3ba663105629f` plus uncommitted local changes
Environment: local development checkout
Database target: local Laravel test database
Seed command: test-managed `RefreshDatabase`
Seed timestamp: per test run
Scenario keys: `rtdc_barriers`, `ed_boarding_surge`, `critical_care_outflow`, `or_pacu_hold`, `evs_backlog`, `weekend_staffing_gap`, `post_acute_discharge_gridlock`
Operator: Codex
Reviewer: pending human review
Related requirement IDs: PRD-PF-001, PRD-PF-002, PRD-PF-003, PRD-PF-005, PRD-PF-007
Known limitations touched: visual canvas proof and full web/mobile screenshot parity remain B8 work
Deployment status: not deployed
Rollback status: not applicable to local-only changes

## Implemented Evidence

- Added `GET /api/patient-flow/demo-scenarios` backed by `PatientFlowScenarioRegistry`.
- Added `GET /api/patient-flow/occupancy/history` backed by persisted `flow_core.occupancy_snapshots`.
- Extended demo barrier overlays from the single `rtdc_barriers` mode to named PRD demo scenarios.
- Populated live snapshot `occupancy_details`, timer counts, service-line counts, persona counts, and projection-window metadata in `TimelineSnapshotService`.
- Verified history redacts patient and encounter identifiers for aggregate lenses while preserving detail for patient-capable roles.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan route:list --path=api/patient-flow` | Pass: route list includes `demo-scenarios` and `occupancy/history` |
| `php artisan test --filter=PatientFlowApiTest` | Pass: 3 tests, 167 assertions |
| `php artisan test --filter=FlowWindowTest` | Pass: 22 tests, 811 assertions |

## Remaining B4/B8 Work

- Archive API samples for occupancy, history, scenarios, barrier context, and Eddy context.
- Run Playwright or manual screenshot checks for Patient Flow canvas framing, nonblank rendering, interactions, and responsive behavior.
- Prove mobile Patient Flow parity with native screenshots and BFF response samples.
