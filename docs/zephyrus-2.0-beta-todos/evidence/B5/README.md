# Evidence - B5

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: post-review hardening working tree; final commit recorded by git history
Environment: local development checkout
Database target: local Laravel test database
Seed command: test-managed `RefreshDatabase`
Seed timestamp: per test run
Scenario keys: Patient Flow scenario keys available to context callers
Operator: Codex
Reviewer: pending human review
Related requirement IDs: PRD-EDDY-001, PRD-EDDY-004, PRD-HB-003
Known limitations touched: full governed execute-to-outcome loop remains B8 work
Deployment status: prior tranche deployed; post-review hardening deploy pending final clean-branch deploy
Rollback status: not applicable to local-only changes

## Implemented Evidence

- Hardened Eddy action approval so token callers are draft-only even if a token is misissued with `ops:approve`.
- Expanded the action catalog with minimum role, scope, write tier, PHI policy, dry-run, adapter, rollback, audit, mobile availability, and human-approval metadata.
- Preserved human propose-and-approve behavior while preventing agent-token approval.
- Added web route gates for Eddy action proposal/token routes and service-level minimum-role enforcement.
- Added Hummingbird Eddy approval minimum-role enforcement so mobile callers cannot approve with ability-only authorization.
- Added/extended feature tests for the misissued-token case and catalog metadata completeness.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan test --filter=EddyActionTest` | Pass in targeted runs |
| `php artisan test --filter=MobileBackendSafetyTest` | Pass in targeted runs |
| `php artisan test --filter='MobileBackendSafetyTest|FlowWindowTest|PatientFlowApiTest|EddyActionTest|ApiAuthorizationTest|ApiRouteSmokeTest' --compact` | Pass: 70 tests, 1563 assertions |

## Remaining B5/B8 Work

- Prove every action as executable, dry-run-only, or disabled with visible reason.
- Add durable agent run/tool-call runtime evidence if the beta keeps the local Eddy loop enabled.
- Archive a full cockpit to Eddy to Hummingbird to ledger to web outcome rehearsal.
