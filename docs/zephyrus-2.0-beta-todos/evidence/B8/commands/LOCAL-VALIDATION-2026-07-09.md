# Local Validation - 2026-07-09

date: 2026-07-09
cwd: `/home/smudoshi/Github/Zephyrus`
branch: `feat/hummingbird-4d-service-line-eddy`
commit: `3093c355efdc6d2bbdf3310f30d3ba663105629f` plus uncommitted local changes
environment: local development checkout

## Passed Commands

| Command | Exit | Result |
| --- | --- | --- |
| `git diff --check` | 0 | No whitespace errors |
| `git diff --name-only -- '*.php' | xargs -r php -l` | 0 | Changed PHP files parse cleanly |
| `git diff --name-only -- '*.php' | xargs -r ./vendor/bin/pint --test` | 0 | 22 changed PHP files style-clean after scoped Pint fix |
| `php artisan route:list --path=api/patient-flow` | 0 | 13 Patient Flow routes, including `demo-scenarios` and `occupancy/history` |
| `php artisan test` | 0 | 1 skipped, 661 passed, 6810 assertions |
| `php artisan test --filter=ApiAuthorizationTest` | 0 | 5 tests, 59 assertions |
| `php artisan test --filter=ApiRouteSmokeTest` | 0 | 4 tests, 94 assertions |
| `php artisan test --filter=PatientFlowApiTest` | 0 | 3 tests, 167 assertions |
| `php artisan test --filter=FlowWindowTest` | 0 | 22 tests, 811 assertions |
| `php artisan test --filter=MobileBackendSafetyTest` | 0 | 17 tests, 244 assertions |
| `php artisan test --filter=EddyActionTest` | 0 | 10 tests, 109 assertions |
| `php artisan test --filter=SyntheticHealthcareConnectorTest` | 0 | 9 tests, 74 assertions |
| `npm run test` | 0 | 61 test files, 277 tests |
| `npm run test:e2e` | 0 | 2 skipped, 16 passed |
| `npx playwright test tests/e2e/navigation.spec.ts tests/e2e/rtdc-huddle.spec.ts --project=chromium` | 0 | 11/11 targeted Chromium E2E tests passed |
| `./scripts/check-ui-canon.sh` | 0 | Passed with existing arbitrary-line-height warnings only |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew test` | 0 | Android unit tests pass |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew assembleDebug` | 0 | Android debug APK build passes |
| `npm run build` | 0 | Production Vite build passes with existing large-chunk warnings |

## Corrected Validation Error

An attempted parallel run of `EddyActionTest` and `SyntheticHealthcareConnectorTest` failed with Laravel `RefreshDatabase` migration races and PostgreSQL deadlocks. Both suites passed when rerun sequentially. Do not run DB-refresh feature suites in parallel against the same test database.

## Corrected Harness Issues

- `resources/views/app.blade.php` no longer asks Vite for a page-specific `.jsx` asset beside the TypeScript Inertia entrypoint. This fixed local production-build page boot failures for TSX-backed pages.
- Playwright now runs serially with one worker because `php artisan serve`, SSE streams, and shared session/database state are not safe under high-concurrency E2E execution.
- E2E specs now use the actual login/demo route behavior, block long-lived cockpit streams where appropriate, and close the command palette with Escape.

## Not Run Or Known-Limited

- `xcodegen generate` and `xcodebuild`, because `swift`, `xcodebuild`, and `xcodegen` were unavailable on this Linux host.
- Production `./deploy.sh` was not run at the time this local validation artifact was first written because the checkout still contained uncommitted work. The user subsequently approved commit, push, and deployment from this branch; deployment evidence belongs under `evidence/B8/deploy/` after `./deploy.sh` runs from a clean tree.

## Requirements Covered

- `PRD-GATE-AUTO-001`
- `PRD-GATE-AUTO-002`
- `PRD-GATE-AUTO-003`
- `PRD-HB-006` for Android only
- `PRD-GATE-AUTO-004` for Android only
