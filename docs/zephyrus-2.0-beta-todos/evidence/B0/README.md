# Evidence - B0

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
Related requirement IDs: PRD-SEC-002, PRD-GATE-AUTO-002, PRD-GATE-AUTO-003
Known limitations touched: production auth posture, visual evidence, and runtime browser smoke still require B8
Deployment status: prior guardrail tranche deployed; post-review hardening pending final clean-branch deploy
Rollback status: app rollback only for post-review hardening

## Implemented Guardrails

- Added `tests/Feature/ApiRouteSmokeTest.php` for API route families that were previously skipped by the legacy route smoke test.
- Added `tests/Feature/ApiAuthorizationTest.php` for explicit public, session-auth, admin, Sanctum, and Eddy agent-token route behavior.
- Hardened admin integration routes so read endpoints require deployment-console access and write/discovery endpoints require deployment-config management.
- Added explicit Eddy and OR case route gates during adversarial hardening.
- Time-boxed or resolved the decision-register rows closed by this tranche, including scenario storage, native 4D scope, no-self-approval policy, patient display policy, mutable API exposure, push posture, and rollback triggers.
- Preserved the deploy rule that production release must use `./deploy.sh`; no automated deploy path was introduced.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan test --filter=ApiAuthorizationTest` | Pass: 5 tests, 59 assertions |
| `php artisan test --filter=ApiRouteSmokeTest` | Pass: 4 tests, 94 assertions |
| `php artisan test --filter='MobileBackendSafetyTest|FlowWindowTest|PatientFlowApiTest|EddyActionTest|ApiAuthorizationTest|ApiRouteSmokeTest' --compact` | Pass: 70 tests, 1566 assertions |
| `git diff --check` | Pass |
| `git diff --name-only -- '*.php' | xargs -r ./vendor/bin/pint --test` | Pass: 22 files |

## Remaining B0/B8 Work

- Finish unresolved decision-register rows that remain outside this hardening tranche.
- Add final production evidence from a clean release deploy before marking all route/auth guardrails complete.
