# B6 - Hummingbird Demo Parity

Goal: finish Hummingbird as the mobile coordination surface for the same Zephyrus 2.0 operating model, not a parallel or watered-down mobile demo.

Primary source: PRD section `B6 - Hummingbird Demo Parity`.

Exit principle: every mobile beta capability that overlaps Zephyrus web must use the same backend contracts, source/freshness semantics, redaction rules, action lifecycle, and audit ledger.

## Current Evidence

- Mobile BFF routes exist under `/api/mobile/v1/*`.
- Mobile BFF tests cover route drift, envelopes, `mobile:act`, redaction, activity ledger, and vocabulary parity.
- iOS and Android apps have role-aware Home, For You, and Activity shells.
- APNs sender exists when configured.
- Log push fallback exists.
- Android/FCM is not proven.
- 2026-07-09 local implementation hardens Android backup, data extraction, and cleartext behavior.
- 2026-07-09 local implementation adds deterministic idempotency keys for Android and iOS mobile POSTs and server-side ledger replay protection.
- Android debug build and unit tests pass locally with Java 17.
- iOS build proof remains blocked on this Linux host because `swift`, `xcodebuild`, and `xcodegen` are unavailable.

## Deliverables

- [ ] Role package completion for beta personas.
- [ ] Mobile/web/backend contract parity for cards, actions, patient context, and Eddy.
- [x] Activity ledger exactly-once behavior for repeated mobile write idempotency keys.
- [ ] Offline/unsafe-write semantics.
- [ ] Push or fetch-on-open decision implemented and tested.
- [ ] iOS and Android build evidence.
- [ ] Persona screenshot matrix.
- [x] Android mobile security hardening for backup, transfer, and cleartext posture.

## Phase Entry Gate

B6 may start only after:

- [ ] B2 has handed off snapshot/status/trust semantics for mobile aggregate cards.
- [ ] B4 has handed off Patient Flow parity contracts if mobile Patient Flow is in scope.
- [ ] B5 has handed off action catalog/approval policy if mobile approval/actions are in scope.
- [ ] D4, D9, D10, D12, and D16 are resolved or time-boxed.
- [ ] iOS and Android local toolchain availability is recorded.
- [ ] Requirement IDs `PRD-HB-001` through `PRD-HB-008` are assigned.

Preflight commands:

```bash
git status --short --branch
php artisan route:list --path=mobile
php artisan test --filter=MobileBffTest
php artisan test --filter=MobileBackendSafetyTest
php artisan test --filter=MobileRoleCatalogParityTest
php artisan test --filter=MobileUiVocabularyParityTest
```

Native preflight:

```bash
cd hummingbird/androidApp
./gradlew test
./gradlew assembleDebug
```

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

If `xcodegen` or Xcode is unavailable, B6 may not mark iOS build complete; record the blocker and assign validation to a machine with the required toolchain.

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Persona role matrix | Mobile Agent | Product/QA Agent | B8 owner | role package table and screenshots |
| BFF contract parity | Backend Agent | Mobile Agent | iOS/Android owners | API samples and BFF tests |
| Activity/action ledger | Backend + Mobile Agents | Security Agent | B5/B8 owners | exactly-once tests and DB/API proof |
| Patient context/redaction | Security Agent | Mobile Agent | B8 owner | PHI review and role tests |
| Offline/unsafe-write | Mobile Agent | QA Agent | B8 owner | native tests/screenshots |
| Push/fetch-on-open | Mobile + Ops Agents | Security Agent | B8 owner | D10 decision, push/fetch proof |
| Native builds/screenshots | Mobile Agent | QA Agent | B8 owner | Android/iOS logs and screenshot matrix |

## Agent Execution Contract

Owned write scope:

- `app/Http/Controllers/Api/Mobile/*`.
- `app/Services/Mobile/*`.
- `docs/hummingbird/api-contract/*`.
- `hummingbird/androidApp/*`.
- `hummingbird/iosApp/*`.
- mobile BFF, safety, fixture, and native tests.

Read-only first:

- `config/hummingbird.php`.
- `app/Console/Commands/HummingbirdTestPush.php`.
- `hummingbird/androidApp/app/src/main/AndroidManifest.xml`.
- `hummingbird/iosApp/project.yml`.
- `docs/hummingbird/reference/06-security-hipaa.md`.
- `docs/hummingbird/PUSH.md`.
- `tests/Feature/Mobile*`.
- `tests/Feature/Mobile/*`.

Do not touch:

- Web shell convergence owned by B3, except for parity labels documented through shared contracts.
- Eddy execution adapters owned by B5, except mobile approval/action presentation.
- domain completion outside mobile parity owned by B7.

## Push And Fetch-On-Open Decision Contract

D10 must decide one of:

| Option | Implementation Requirement | Evidence |
| --- | --- | --- |
| APNs and FCM real push | real device/emulator token registration, PHI-free push payloads, delivery proof | `hummingbird:test-push <username>` output plus platform screenshots/logs |
| APNs real, Android fetch-on-open | APNs proof, Android visible fetch-on-open/manual refresh limitation | APNs evidence plus limitations entry |
| No native push for beta | fetch-on-open/manual refresh implemented and disclosed everywhere | limitations entry, mobile UX proof |

Push smoke command:

```bash
php artisan hummingbird:test-push demo --title="Hummingbird" --body="You have an item that needs attention." --tab=foryou
```

Use a known beta username instead of `demo` if the seed uses a different account.

## Mobile Security Contract

B6 must review and either harden or document beta posture for:

- [ ] Android `allowBackup`.
- [ ] Android `usesCleartextTraffic`.
- [ ] Android exported components.
- [ ] Android network security config if used.
- [ ] iOS entitlements.
- [ ] iOS Keychain use.
- [ ] token storage and refresh.
- [ ] push payload PHI.
- [ ] offline cache PHI.
- [ ] logs/crash output PHI.

Known starting point:

- `hummingbird/androidApp/app/src/main/AndroidManifest.xml` currently requires review for source-level backup and cleartext defaults before non-dev beta signoff.

## Offline And Unsafe-Write Policy

Every mobile write must declare:

- [ ] allowed online only or queued offline.
- [ ] idempotency key behavior.
- [ ] stale data rejection behavior.
- [ ] approval-required behavior.
- [ ] retry behavior.
- [ ] visible user state while pending.
- [ ] exactly one activity/audit row per accepted action.
- [ ] no write replay after logout/token revocation.

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| `MobileBffTest` | Existing | response envelope/source/action parity |
| `MobileBackendSafetyTest` | Existing | role redaction and safety |
| `MobileRoleCatalogParityTest` | Existing | role catalog parity |
| `MobileUiVocabularyParityTest` | Existing | vocabulary/status parity |
| Android tests/build | Existing Gradle wrapper | `./gradlew test` and `./gradlew assembleDebug` output |
| iOS project generation/build | Requires `xcodegen` + Xcode | `xcodegen generate` and `xcodebuild` output |
| `hummingbird:test-push <username>` | Existing command | PHI-free push/fetch proof |
| offline/unsafe-write tests | To create/extend | native tests and screenshots |
| persona screenshot matrix | To capture | iOS/Android role screenshots |

## Criticality Double Check

B6 must pass or defer:

- Mobile parity.
- Authorization.
- PHI/privacy.
- API contract.
- Data trust.
- Security headers/config for mobile transport and push.
- Frontend/mobile quality.
- Testing.
- Deployment.
- Rollback.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B6/README.md`.
- [ ] `api/mobile-bff-role-samples/`.
- [ ] `commands/mobile-bff-tests.txt`.
- [ ] `mobile/android/gradle-test.txt`.
- [ ] `mobile/android/assemble-debug.txt`.
- [ ] `mobile/ios/xcodegen.txt`.
- [ ] `mobile/ios/xcodebuild-debug.txt`.
- [ ] `mobile/push-or-fetch-proof.md`.
- [ ] `screenshots/mobile/<platform>-<role>-<surface>.png`.
- [ ] `reviews/mobile-security-review.md`.
- [ ] `reviews/offline-unsafe-write-review.md`.

## Workstream 6.1: Persona And Role Package Matrix

- [ ] Confirm beta persona catalog from the PRD.
- [ ] For each role, document mobile surfaces:
  - [ ] Home cards.
  - [ ] For You cards.
  - [ ] Activity feed.
  - [ ] Patient context access.
  - [ ] Eddy access.
  - [ ] Actions visible.
  - [ ] Actions allowed.
  - [ ] Redaction mode.
  - [ ] Push eligibility.
- [ ] Validate at minimum:
  - [ ] House supervisor.
  - [ ] ED charge.
  - [ ] Bed manager.
  - [ ] Unit charge.
  - [ ] EVS lead.
  - [ ] Transport lead.
  - [ ] OR charge.
  - [ ] Staffing coordinator.
  - [ ] Quality/process owner.
  - [ ] Executive.
  - [ ] Mobile admin.
- [ ] If the PRD requires fourteen roles, list all fourteen and mark any intentionally post-beta.
- [ ] Add role catalog parity tests if missing.

Verification:

```bash
php artisan test --filter=MobileRoleCatalogParityTest
php artisan test --filter=MobileBffTest
```

## Workstream 6.2: Mobile BFF Contract Completion

- [ ] Audit `/api/mobile/v1/*` response envelopes for:
  - [ ] `data`.
  - [ ] `meta`.
  - [ ] `as_of`.
  - [ ] `source`.
  - [ ] `freshness`.
  - [ ] `redaction`.
  - [ ] `actions`.
  - [ ] `activity_ref`.
  - [ ] `stale` or `degraded` state.
- [ ] Add missing source/freshness fields to action-driving cards.
- [ ] Add contract tests for:
  - [ ] Altitude/home.
  - [ ] For You.
  - [ ] Activity.
  - [ ] Patient context.
  - [ ] RTDC.
  - [ ] Patient Flow.
  - [ ] Transport.
  - [ ] EVS.
  - [ ] Command.
  - [ ] OR.
  - [ ] Ops.
  - [ ] Staffing.
  - [ ] Improvement.
  - [ ] Eddy.
- [ ] Ensure mobile response vocabulary matches web labels and status states.
- [ ] Keep OpenAPI/route drift tests required.

Verification:

```bash
php artisan test --filter=MobileBffTest
php artisan test --filter=MobileUiVocabularyParityTest
```

## Workstream 6.3: Action And Activity Ledger Semantics

- [ ] Define exactly-once mobile action logging:
  - [ ] Action requested.
  - [ ] Action accepted/rejected.
  - [ ] Approval required.
  - [ ] Execution started.
  - [ ] Execution completed/failed.
  - [ ] Activity feed item created.
- [ ] Add idempotency keys for mobile writes where missing.
- [ ] Ensure every mobile action maps to the same backend lifecycle as web.
- [ ] Ensure mobile cannot bypass Eddy/action approval policy.
- [ ] Add tests for duplicate taps, retry after network failure, stale action, and unauthorized role.
- [ ] Ensure activity feed is updated after web-created actions and mobile-completed actions.

Verification:

```bash
php artisan test --filter=MobileBackendSafetyTest
php artisan test --filter=EddyActionTest
```

## Workstream 6.4: Patient Context And Redaction

- [ ] Align patient context policy with B3.
- [ ] Validate mobile patient context for:
  - [ ] Privileged patient-care role.
  - [ ] Operational aggregate role.
  - [ ] Executive role.
  - [ ] Eddy context.
  - [ ] Offline cached state.
- [ ] Ensure no mobile role receives patient names/MRNs unless web would show them to the same role.
- [ ] Ensure screenshots avoid PHI unless using seeded demo-safe names.
- [ ] Add tests for patient-context redaction parity.

Suggested tests:

```bash
php artisan test --filter=MobileBackendSafetyTest
# Create or extend a focused mobile redaction test before using it as a gate.
```

## Workstream 6.5: Offline And Unsafe-Write Behavior

- [ ] Define mobile offline states:
  - [ ] Read-only cached card.
  - [ ] Stale but visible.
  - [ ] Requires refresh.
  - [ ] Action blocked offline.
  - [ ] Action queued only if safe.
- [ ] Decide whether any writes may be queued offline for beta.
- [ ] For unsafe writes, require online revalidation before submit.
- [ ] Return clear `409` or equivalent stale-state conflict when source has changed.
- [ ] Add mobile UI for stale/blocked states.
- [ ] Add tests for offline rejection and stale conflict.

Verification:

```bash
php artisan test --filter=MobileBackendSafetyTest
# Create or extend focused offline/conflict tests before using them as a gate.
```

## Workstream 6.6: Push, Realtime, And Fetch-On-Open

- [ ] Decide B0 push posture:
  - [ ] APNs and FCM required.
  - [ ] APNs required, Android fetch-on-open accepted.
  - [ ] No native push required; fetch-on-open accepted.
- [ ] If APNs required:
  - [ ] Document required env vars.
  - [ ] Add sandbox/prod mode check.
  - [ ] Add test command with safe payload.
- [ ] If FCM required:
  - [ ] Add Android sender implementation.
  - [ ] Add credentials handling.
  - [ ] Add token registration and invalid-token cleanup.
- [ ] If fetch-on-open is accepted:
  - [ ] Implement visible refresh state.
  - [ ] Ensure action cards refresh on resume.
  - [ ] Document limitation in known-limitations register.
- [ ] Add quiet-hours and role routing behavior if PRD requires it.

Suggested commands:

```bash
php artisan hummingbird:test-push demo --title="Hummingbird" --body="You have an item that needs attention." --tab=foryou
# Create or extend focused push/fetch-on-open tests before using them as a gate.
```

Use the seeded beta username instead of `demo` if the account differs.

## Workstream 6.7: Native iOS Completion

- [ ] Verify iOS Home, For You, Activity, patient context, and Eddy screens against backend contracts.
- [ ] Remove or gate debug-only screens.
- [ ] Add source/freshness/synthetic labels to action-driving cards.
- [ ] Add approval/dry-run preview for relevant actions.
- [ ] Add stale/offline states.
- [ ] Add role screenshot matrix.
- [ ] Run iOS build and archive result.

Suggested command pattern:

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

This checkout uses `project.yml`; generate the Xcode project before building unless a reviewed future commit checks in the project.

## Workstream 6.8: Native Android Completion

- [ ] Verify Android Home, For You, Activity, patient context, and Eddy screens against backend contracts.
- [ ] Remove or gate debug-only screens.
- [ ] Add source/freshness/synthetic labels to action-driving cards.
- [ ] Add approval/dry-run preview for relevant actions.
- [ ] Add stale/offline states.
- [ ] Harden `AndroidManifest.xml`:
  - [ ] Disable backup outside dev if required.
  - [ ] Disable cleartext traffic outside dev if required.
  - [ ] Verify exported activities/services.
  - [ ] Verify network security config.
- [ ] Add role screenshot matrix.
- [ ] Run Android build and archive result.

Suggested command pattern:

```bash
cd hummingbird/androidApp
./gradlew test
./gradlew assembleDebug
```

Use `JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64` if local Gradle requires Java 17.

## Workstream 6.9: Screenshot Matrix

- [ ] Capture iOS screenshots for each beta persona.
- [ ] Capture Android screenshots for each beta persona.
- [ ] Include states:
  - [ ] Normal.
  - [ ] Stale.
  - [ ] Redacted.
  - [ ] Privileged patient context.
  - [ ] Action requires approval.
  - [ ] Action completed.
  - [ ] Offline/blocked.
  - [ ] Eddy context.
- [ ] Archive screenshots with device, role, environment, commit, and data seed timestamp.
- [ ] Add screenshots to B8 release evidence.

## Phase Exit Gate

This phase is complete only when:

- [ ] Role package matrix is complete or explicit post-beta exceptions are documented.
- [ ] Mobile BFF contracts pass parity tests.
- [ ] Mobile actions use the same lifecycle and audit semantics as web.
- [ ] Push/fetch-on-open decision is implemented and documented.
- [ ] Offline/unsafe-write behavior is tested.
- [ ] iOS build evidence is archived.
- [ ] Android build evidence is archived.
- [ ] Persona screenshot matrix is archived.
