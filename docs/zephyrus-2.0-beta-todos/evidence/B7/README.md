# Evidence - B7

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: `3093c355efdc6d2bbdf3310f30d3ba663105629f` plus uncommitted local changes
Environment: local development checkout
Database target: local Laravel test database
Seed command: test-managed `RefreshDatabase`
Seed timestamp: per test run
Scenario keys: not applicable
Operator: Codex
Reviewer: pending human review
Related requirement IDs: PRD-Z5-003, PRD-TRUST-001, PRD-SEC-002
Known limitations touched: full domain completion matrix and Study handoff remain open
Deployment status: not deployed
Rollback status: not applicable to local-only changes

## Implemented Evidence

- Corrected perioperative API references away from stale table names and toward current `prod.or_cases` and `prod.or_logs` usage.
- Removed raw exception trace/message leakage from mutable/read API JSON failures in `ORCaseController`.
- Corrected OR case creation mapping from API request fields to current schema fields.
- Added route smoke coverage for public, session-auth, privileged admin, mobile, and Patient Flow route families.
- Hardened integration admin route authorization and kept synthetic connector tests passing under the new gates.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan test --filter=ApiRouteSmokeTest` | Pass: 4 tests, 94 assertions |
| `php artisan test --filter=ApiAuthorizationTest` | Pass: 5 tests, 59 assertions |
| `php artisan test --filter=SyntheticHealthcareConnectorTest` | Pass: 9 tests, 74 assertions |
| `npm run build` | Pass; Vite reported existing large-chunk warnings |

## Remaining B7/B8 Work

- Complete the live/synthetic/post-beta matrix for every visible domain.
- Prove Study/A3 handoff from operational events with aggregate, redacted payloads.
- Add or archive domain-specific screenshots for ED, RTDC, periop, transport, EVS, staffing, and improvement.
