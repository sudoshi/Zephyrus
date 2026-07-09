# Evidence - B7

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: post-review hardening working tree; final commit recorded by git history
Environment: local development checkout
Database target: local Laravel test database
Seed command: test-managed `RefreshDatabase`
Seed timestamp: per test run
Scenario keys: not applicable
Operator: Codex
Reviewer: pending human review
Related requirement IDs: PRD-Z5-003, PRD-TRUST-001, PRD-SEC-002
Known limitations touched: full domain completion matrix, stale schema-init artifact, and Study handoff remain open
Deployment status: prior tranche deployed; post-review OR/API hardening pending final clean-branch deploy
Rollback status: app rollback only for post-review hardening

## Implemented Evidence

- Corrected perioperative API references away from stale table names and toward current `prod.or_cases` and `prod.or_logs` usage.
- Removed raw exception trace/message leakage from mutable/read API JSON failures in `ORCaseController`.
- Corrected OR case creation mapping from API request fields to current schema fields.
- Added `writeOrCases` authorization to OR case write routes and made OR case writes transaction-backed.
- Removed arbitrary first-row reference fallback from OR case creation/update mapping.
- Verified live production `prod.or_cases.scheduled_start_time` is `timestamp without time zone`; `db/schemas/init/004-case-tables.sql` remains stale and must be reconciled separately.
- Added route smoke coverage for public, session-auth, privileged admin, mobile, and Patient Flow route families.
- Hardened integration admin route authorization and kept synthetic connector tests passing under the new gates.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan test --filter=ApiRouteSmokeTest` | Pass: 4 tests, 94 assertions |
| `php artisan test --filter=ApiAuthorizationTest` | Pass: 5 tests, 59 assertions |
| `php artisan test --filter=SyntheticHealthcareConnectorTest` | Pass: 9 tests, 74 assertions |
| `php artisan test --filter='MobileBackendSafetyTest|FlowWindowTest|PatientFlowApiTest|EddyActionTest|ApiAuthorizationTest|ApiRouteSmokeTest' --compact` | Pass: 70 tests, 1563 assertions |
| `npm run build` | Pass; Vite reported existing large-chunk warnings |

## Remaining B7/B8 Work

- Complete the live/synthetic/post-beta matrix for every visible domain.
- Prove Study/A3 handoff from operational events with aggregate, redacted payloads.
- Add or archive domain-specific screenshots for ED, RTDC, periop, transport, EVS, staffing, and improvement.
- Reconcile `db/schemas/init/004-case-tables.sql` with current migrations/live schema.
