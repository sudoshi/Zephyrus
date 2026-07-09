# Evidence - B6

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: post-review hardening working tree; final commit recorded by git history
Environment: local development checkout
Database target: local Laravel test database plus Android local Gradle build
Seed command: test-managed `RefreshDatabase`
Seed timestamp: per test run
Scenario keys: Patient Flow and mobile action replay scenarios
Operator: Codex
Reviewer: pending human review
Related requirement IDs: PRD-HB-003, PRD-HB-006, PRD-HB-007, PRD-HB-008, PRD-GATE-AUTO-004
Known limitations touched: iOS build cannot be validated on this Linux host; push/fetch-on-open decision remains open
Deployment status: prior tranche deployed; post-review hardening deploy pending final clean-branch deploy
Rollback status: not applicable to local-only changes

## Implemented Evidence

- Added optional mobile idempotency-key ingestion for Hummingbird write endpoints.
- Made `OperationalActivityLedger` deterministic for replayed mobile writes with the same idempotency key and action scope.
- Added deterministic `Idempotency-Key` generation for Android and iOS mobile POSTs.
- Hardened Android release posture with backup disabled, cleartext disabled, production HTTPS/WSS defaults, and debug-only emulator cleartext overrides.
- Added Android and iOS client calls for mobile Patient Flow demo scenarios and occupancy history.
- Fixed iOS query encoding for ISO timestamps and other reserved characters.
- Added persona-specific authorization gates for mobile RTDC, transport, EVS, staffing, and Eddy approval actions.
- Updated Android and iOS shared fixture expectations for the current floor-plate version.
- Cleaned Android nullable warnings in touched client/test helpers.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan test --filter='MobileBackendSafetyTest|FlowWindowTest|PatientFlowApiTest|EddyActionTest|ApiAuthorizationTest|ApiRouteSmokeTest' --compact` | Pass: 70 tests, 1566 assertions |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew testDebugUnitTest` | Pass |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew assembleRelease` | Pass |
| `which swift; which xcodebuild; which xcodegen` | No tools found on this host |

## Remaining B6/B8 Work

- Run `xcodegen generate` and `xcodebuild` on a macOS/Xcode host.
- Capture iOS and Android persona screenshots.
- Decide and prove push versus fetch-on-open posture for the final beta package.
- Broaden offline/unsafe-write UX evidence beyond ledger replay idempotency.
- Consider an atomic unique/upsert path for concurrent duplicate idempotency submissions; sequential replay and conflict handling are tested.
