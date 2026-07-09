# Evidence - B6

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: `3093c355efdc6d2bbdf3310f30d3ba663105629f` plus uncommitted local changes
Environment: local development checkout
Database target: local Laravel test database plus Android local Gradle build
Seed command: test-managed `RefreshDatabase`
Seed timestamp: per test run
Scenario keys: Patient Flow and mobile action replay scenarios
Operator: Codex
Reviewer: pending human review
Related requirement IDs: PRD-HB-003, PRD-HB-006, PRD-HB-007, PRD-HB-008, PRD-GATE-AUTO-004
Known limitations touched: iOS build cannot be validated on this Linux host
Deployment status: not deployed
Rollback status: not applicable to local-only changes

## Implemented Evidence

- Added optional mobile idempotency-key ingestion for Hummingbird write endpoints.
- Made `OperationalActivityLedger` deterministic for replayed mobile writes with the same idempotency key and action scope.
- Added deterministic `Idempotency-Key` generation for Android and iOS mobile POSTs.
- Hardened Android release posture with backup disabled, cleartext disabled, and data-extraction rules that exclude local data from backup/transfer.
- Updated Android and iOS shared fixture expectations for the current floor-plate version.
- Cleaned Android nullable warnings in touched client/test helpers.

## Local Validation

| Command | Result |
| --- | --- |
| `php artisan test --filter=MobileBackendSafetyTest` | Pass: 17 tests, 244 assertions |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew test` | Pass |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew assembleDebug` | Pass |
| `which swift; which xcodebuild; which xcodegen` | No tools found on this host |

## Remaining B6/B8 Work

- Run `xcodegen generate` and `xcodebuild` on a macOS/Xcode host.
- Capture iOS and Android persona screenshots.
- Decide and prove push versus fetch-on-open posture for the final beta package.
- Broaden offline/unsafe-write UX evidence beyond ledger replay idempotency.
