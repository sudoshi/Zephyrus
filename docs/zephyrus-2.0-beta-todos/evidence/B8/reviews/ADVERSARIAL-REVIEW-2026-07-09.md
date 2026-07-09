# Adversarial Review - 2026-07-09

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Operator: Codex
Review mode: two-agent adversarial review plus orchestrator reconciliation

## Review Agents

- Mobile/native reviewer: Hummingbird Android/iOS parity, mobile BFF contracts, PHI leakage, release configuration.
- Backend/security reviewer: Laravel API authorization, mutable route gates, Patient Flow redaction, Eddy governance, OR case schema mapping, idempotency semantics.

## Findings Fixed

| Severity | Finding | Disposition |
| --- | --- | --- |
| Critical | Patient Flow occupancy history exposed raw patient/encounter identifiers in history details. | Fixed by centralized history redaction that removes raw patient and encounter refs and emits `ptok_` context refs where detail is permitted. Covered by `FlowWindowTest` and `PatientFlowApiTest`. |
| High | Mobile write endpoints used broad `mobile:act` ability without persona-specific authorization for several action families. | Fixed by `AuthorizesMobilePersonaActions` and per-controller persona allowlists for RTDC, transport, EVS, staffing, and Eddy approvals. Covered by `MobileBackendSafetyTest`. |
| High | Android release defaults still targeted cleartext emulator networking. | Fixed by production HTTPS/WSS defaults in release config and debug-only cleartext/emulator overrides. Covered by Android `testDebugUnitTest` and `assembleRelease`. |
| High | iOS query construction could leave `+` and other reserved timestamp characters ambiguous in query strings. | Fixed by RFC3986-style query encoding in `APIClient.swift`. |
| High | Eddy action proposal/token and OR case write routes lacked the explicit role gates expected by the beta authorization posture. | Fixed with `useEddyActions`, `writeOrCases`, service-level Eddy minimum-role enforcement, and route tests in `ApiAuthorizationTest`. |
| Medium | OR case API write mapping relied on stale schema assumptions and arbitrary reference fallbacks. | Fixed by transaction-backed mapping to current `prod.or_cases` plus `prod.or_logs.primary_procedure`, strict reference lookup, and tests. Live production column type for `prod.or_cases.scheduled_start_time` was verified as `timestamp without time zone`; `db/schemas/init/004-case-tables.sql` remains a stale schema artifact. |
| Medium | Login failed-auth UI did not render the `errors.general` validation message returned by `LoginRequest`. | Fixed in `resources/js/Pages/Auth/Login.jsx` and verified by Playwright auth smoke. |

## Remaining Follow-Up

- Concurrent duplicate idempotency submissions should be hardened with a database-level unique/upsert path; sequential duplicate replay and conflicting-payload rejection are tested.
- iOS build proof still requires macOS/Xcode/xcodegen.
- Manual screenshot PHI review remains open for web, Patient Flow, mobile personas, and Eddy prompt/output paths.
- Reverb/fallback and authenticated mobile/Eddy/Integration Health browser smokes remain post-deploy/runtime evidence gaps.
- `db/schemas/init/004-case-tables.sql` should be reconciled with the current live/migration schema to avoid future schema-drift confusion.

## Validation Evidence

| Command | Result |
| --- | --- |
| `php artisan test --filter='MobileBackendSafetyTest|FlowWindowTest|PatientFlowApiTest|EddyActionTest|ApiAuthorizationTest|ApiRouteSmokeTest' --compact` | Pass: 70 tests, 1563 assertions |
| `npx tsc --noEmit --pretty false` | Pass |
| `npm run build` | Pass with existing Browserslist and large-chunk warnings |
| `npx playwright test tests/e2e/auth.spec.ts tests/e2e/navigation.spec.ts tests/e2e/rtdc-huddle.spec.ts --reporter=line` | Pass: 17 passed, 2 skipped |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew testDebugUnitTest` | Pass |
| `cd hummingbird/androidApp && JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew assembleRelease` | Pass |
