# Hummingbird ASAP Pilot-Completion Devlog — 2026-07-24

**Plan:** [Hummingbird ASAP Pilot-Completion Plan](../plans/hummingbird-asap-pilot-completion-2026-07-24.md)
**Status:** active execution reset; bounded staff increments accepted locally;
no patient feature enabled by this entry.

## Baseline

- The governing Hummingbird plan has 186 checked and 285 unchecked checklist items
  (471 total). This is an unweighted work-item count, not a clinical-readiness
  percentage.
- The program is reset to a controlled inpatient-pilot cutline: approved
  Today/My Path/Care Team/discharge projections plus accountable secure messaging
  for one facility and two units.
- Patient Eddy, proxy access, attachments, patient push delivery, offline message
  queues, post-discharge handoff, broad staff parity, and general availability are
  deliberately outside that pilot cutline.
- The worktree is clean after two bounded staff increments: native Eddy streaming
  (`2c60052b`) and server-governed patient-communication action affordances
  (`7764bde1`). Neither authorizes deployment or patient feature enablement.

## Daily control board

| Release slice                            | Owner                               | Status                    | Decision/dependency                                                                   | Evidence                                                                                                                                                                                               | Flag state                            | Next action                                                     |
| ---------------------------------------- | ----------------------------------- | ------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------- | --------------------------------------------------------------- |
| Native staff Eddy SSE completion         | Engineering                         | Ready for review          | No external decision                                                                  | Laravel BFF 9 tests/66 assertions; stream service 3/13; Android 122 Debug JVM + 26 API 35 AVD; iPhone 17 Pro 110 tests; contract/ledger verifiers pass                                                 | Staff-only; no patient flag           | Include with scoped commit; no deployment                       |
| Staff communication action affordances   | Engineering                         | Accepted locally          | No external decision                                                                  | PHP 19 tests/446 assertions; web 14 tests + production build; Android API 35 12 tests; iOS 93 unit + 4 UI tests; all client controls fail closed                                                       | Staff-only; no patient flag           | Hold for review; do not expand while pilot gates block          |
| Staff and patient DTO fixture provenance | Engineering                         | Accepted locally          | No external decision                                                                  | 8 factory-seeded staff BFF captures; 6 test-only patient BFF captures plus 1 labelled derived forward-compatibility probe; PHP, iOS, Android decoders; iPhone 17 Pro and Android API 35 journeys green | Contract-only; no patient flag        | Add generated artifacts; keep approved-source fixtures separate |
| Reference-patient dry-run integrity      | Engineering                         | Accepted locally          | No external decision                                                                  | Local regression proves preview does not create an encounter or opaque-resolver row; remote read-only preview finds sole active encounter `10040` / unit `85`                                          | All patient flags remain off          | Keep identity, draft, and release gates unchanged               |
| Pilot scope and governance               | Product / clinical / privacy        | Blocked                   | Facility, units, cohort, disclosure policy, identity, language, SLA, escalation owner | None yet                                                                                                                                                                                               | All patient flags remain off          | Convene decision meeting within one business day                |
| Approved patient source/release adapter  | Integration / clinical content      | Blocked                   | Named source system, source contract, review/release owner                            | Draft/reconciliation kernel exists; no approved production adapter or release authority                                                                                                                | All patient exposure flags remain off | Select source and write source contract                         |
| Patient native vertical slice            | Native / accessibility              | Contract ratified locally | Released pilot projection contract                                                    | Six test-only BFF fixtures decode through both production model layers; iPhone 17 Pro UI and Android API 35 journey are green                                                                          | All patient flags remain off          | Bind the same harness to approved-source release fixtures       |
| Accountable communication pilot          | Nursing ops / support / engineering | Blocked                   | Responsibility pools, shifts, topics, SLA, support desk                               | Local workflow foundations exist; pilot configuration and deployed E2E do not                                                                                                                          | Messaging remains off                 | Configure two pilot unit pools and tabletop                     |
| Integrated pilot rehearsal               | Release / independent reviewers     | Not started               | Waves 1–4 exit evidence                                                               | None yet                                                                                                                                                                                               | All patient flags remain off          | Schedule after source, governance, and workflow gates           |

## Evidence convention

Each subsequent entry must cite the exact commit SHA, command output/test count,
simulator or emulator target, feature-flag state, decision record, and unresolved
blocker. Narrative progress without those artifacts is not an accepted update.

## 2026-07-24 — Native staff Eddy no-store SSE increment

### Completed implementation

- The shared Laravel stream proxy now parses incremental upstream frames rather
  than forwarding the upstream terminal `complete` frame verbatim. It relays only
  token/error frames, persists the assistant result, emits a server-persisted clean
  `complete` reply, then emits the separately sanitized persisted proposal and
  `[DONE]`. Its response is `Cache-Control: no-store, no-cache, max-age=0` plus
  `Pragma: no-cache`.
- iOS (`URLSession.AsyncBytes`) and Android (`HttpURLConnection` Flow) consume the
  BFF stream through dedicated non-idempotent transports. They use no-store headers,
  a 45-second inactivity limit, cancellation when the visible scope disappears,
  no local transcript/cache, no automatic replay, and no generated idempotency key.
- Both chat surfaces update one pending assistant bubble with provisional token text,
  suppress a partial `<propose_action>` marker in defense in depth, and finalize only
  with the server-persisted clean reply. A stream cannot open, approve, or execute an
  action; approval remains a separate fetch-on-open, explicit-human flow.
- The OpenAPI summary, mobile reference, capability ledger, and governing checklist
  now distinguish completed native streaming from still-open history deletion,
  deployed role evidence, and autonomous action.

### Verification

| Boundary               | Command / target                                                             | Result                                                                                                        |
| ---------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Laravel mobile BFF     | `php artisan test tests/Feature/Mobile/Eddy/EddyMobileBffTest.php --compact` | 9 passed / 66 assertions                                                                                      |
| Laravel stream service | `php artisan test tests/Feature/Eddy/EddyStreamTest.php --compact`           | 3 passed / 13 assertions                                                                                      |
| Laravel formatting     | `./vendor/bin/pint` on the three changed PHP files                           | passed (Pint's bundled dependency emitted a non-source deprecation notice)                                    |
| Contract/ledger        | four Hummingbird verifier scripts                                            | 85 contract operations, 60 staff operations, 52 capabilities / 101 routes, and 25 patient operations verified |
| Android JVM            | `testDebugUnitTest --rerun-tasks`                                            | 122 tests, 0 failures/errors/skips                                                                            |
| Android emulator       | `connectedDebugAndroidTest --rerun-tasks` on `emulator-5554` / API 35        | 26 tests, 0 failures                                                                                          |
| iOS simulator          | `xcodebuild test` on iPhone 17 Pro / iOS 26.3.1                              | 110 tests, 0 failures/skips                                                                                   |

### Remaining boundary

This entry is a staff-only local change. It does not deploy, enable a patient flag,
activate the pending reference patient, or change patient release/governance status.

## 2026-07-24 — Server-governed staff communication action affordances

### Completed implementation

- The staff communication BFF now supplies a per-current-user `actions` object with
  `can_claim`, `can_reply`, and `can_close`. It derives each value from the current
  ability, role capability, effective pool membership, ownership, assignment, and
  open state; it does not trust a client-side interpretation of thread status.
- The web, iOS, and Android clients render direct actions only when the exact server
  boolean is true. A missing or malformed action object fails closed. The server still
  authorizes and locks every mutation independently, so the UI hint cannot grant an
  action.
- New native action-forbidden journeys prove that a thread which visually resembles an
  actionable escalation does not offer claim, reply, send, or close when the server
  denies them. The Hummingbird-background patient presentation remains visible and
  readable in the inspected iOS state.

### Verification

| Boundary                  | Command / target                                                                        | Result                                                                                             |
| ------------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Laravel communication API | `php artisan test tests/Feature/Patient/StaffPatientCommunicationApiTest.php --compact` | 19 passed / 446 assertions                                                                         |
| Staff contract            | `php scripts/verify-hummingbird-staff-contract.php`                                     | 60 operations; all 9 communication operations match Laravel and the bounded schemas/offline policy |
| Web                       | focused Vitest communications suite and `npm run build`                                 | 14 passed; production build passed                                                                 |
| Android                   | focused JVM suite plus API 35 emulator `connectedDebugAndroidTest`                      | 12 communication UI tests, 0 failures                                                              |
| iOS                       | iPhone 17 Pro Simulator                                                                 | 93 unit tests and 4 communications UI tests, 0 failures; forbidden-action screenshot inspected     |

### Remaining boundary

This does not configure a pilot responsibility pool, establish an authoritative
service-ownership feed, add patient push, or prove deployed end-to-end delivery. It
is therefore staff safety evidence, not patient-pilot enablement evidence.

## 2026-07-25 — Factory-backed shared DTO fixture provenance

### Completed implementation

- All eight shared staff DTO fixtures now have exact source endpoint, regeneration
  test, and factory-seeded BFF provenance in
  `docs/hummingbird/api-contract/fixtures/fixture-provenance.v1.json`.
- `SharedDtoFixtureRegenerationTest` captures Altitude home, For You, Activity,
  staff patient operational context, and Transport queue through real Laravel BFF
  routes. The existing Flow capture test now accepts the same unified opt-in command.
  Native fixture assertions validate stable semantics and grammar rather than a
  previous run's opaque handles, database sequence values, or content hash.
- The default Flow/shared capture tests now compare all eight checked-in staff
  fixtures with deterministic BFF semantics and fail stale artifacts without
  writing. They canonicalize only grammar-checked database-sequence identifiers
  and catalog hashes; every user-visible, authorization, status, relationship, and
  envelope value remains exact. Only `HUMMINGBIRD_FIXTURE_DUMP=1` rewrites a
  fixture, so CI cannot silently replace a reviewed contract artifact.
- The new capture found that the staff patient-context BFF leaked source vocabulary
  (`"none"`) for `header.isolation_required`, despite the published native Boolean
  contract. `MobilePatientContextService` now normalizes false-like source values
  and preserves a true value for an actual requirement; a non-dump feature test
  proves both paths.

### Verification

| Boundary                  | Command / target                                            | Result                                                                                    |
| ------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Laravel fixture freshness | Flow/shared capture tests plus `MobileSharedDtoFixtureTest` | 7 passed / 170 assertions; all 8 factory-seeded BFF artifacts match without writing       |
| Explicit Laravel capture  | unified fixture command, including Flow                     | 3 passed / 32 assertions; only explicit flag rewrites the 5 non-Flow and 3 Flow artifacts |
| Swift contract decoder    | `decode-shared-fixtures.swift`                              | decoded all 8 fixtures                                                                    |
| Kotlin contract decoder   | `SharedDtoFixtureDecodeTest`                                | 15 tests, 0 failures                                                                      |
| iOS Simulator             | iPhone 17 Pro / `HummingbirdTests`                          | 93 tests, 0 failures                                                                      |
| Android emulator          | API 35 `hb(AVD)` / communications UI suite                  | 12 tests, 0 failures, including 200% text and unavailable-action states                   |

### Remaining boundary

Fixture provenance eliminates a contract-ratification blind spot; it does not generate
the Swift/Kotlin models, provide patient-production source data, activate a patient flag,
or replace source/governance/pilot approval requirements.

## 2026-07-25 — Patient-care projection fixture ratification

### Completed implementation

- `PatientProjectionFixtureRegenerationTest` captures six DTO fixtures from the real
  patient BFF response boundary: Today, My Path, pathway events, discharge readiness,
  rounds summary, and Care Team. It freezes time and uses a deterministic request ID
  only in the `testing` runtime.
- The source is explicitly `SyntheticPatientProjectionProvisioner`, which is a
  testing-only projection provisioner. No fixture contains a real patient, remote
  production database data, clinical source payload, release approval, credential, or
  enabled feature flag. `fixtures/patient/fixture-provenance.v1.json` records this
  distinction so a synthetic contract fixture cannot be mistaken for release evidence.
- The writer preserves the raw API JSON before formatting. That retains an empty
  `links` object as `{}` rather than silently changing its type to `[]`, a defect a
  PHP array conversion would otherwise conceal.
- The default test path now compares all six checked-in fixtures with deterministic
  BFF responses and fails without writing if any artifact is stale. The explicit
  `HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1` path is the only writer, so a CI run cannot
  silently alter the reviewed fixture set.
- PHP validates every envelope, no-store contract, opaque handles, freshness,
  forbidden raw identity/source keys, provenance, and both native decoder harnesses.
  The production apps decode the same six fixtures through their existing patient
  models, not a duplicate test-only DTO.

### Verification

| Boundary                   | Command / target                                                                                                                   | Result                                                                                             |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Laravel fixture freshness  | `php artisan test tests/Feature/Patient/PatientProjectionFixtureRegenerationTest.php --compact`                                    | 1 passed / 72 assertions, no writes                                                                |
| Laravel fixture capture    | `HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1 php artisan test tests/Feature/Patient/PatientProjectionFixtureRegenerationTest.php --compact` | 1 passed / 66 assertions, explicit writer only                                                     |
| Laravel fixture validation | combined freshness and `PatientSharedDtoFixtureTest` command                                                                       | 4 passed / 200 assertions                                                                          |
| Patient contract guards    | contract, baseline, accessibility, and disclosure verifier scripts                                                                 | 25 governed routes; 85 baseline operations; accessibility/disclosure remain draft, not pilot-ready |
| iOS model decode           | iPhone 17 Pro / iOS 26.3.1, selected `PatientAPIModelTests` XCTest                                                                 | 1 passed / 0 failures                                                                              |
| iOS UI journey             | iPhone 17 Pro / iOS 26.3.1, `PatientReferenceJourneyUITests`                                                                       | passed / 0 failures                                                                                |
| Android model decode       | `PatientProjectionFixtureDecodeTest` JVM suite                                                                                     | 6 passed / 0 failures/errors/skips                                                                 |
| Android UI journey         | API 35 `hb(AVD)` / `PatientPrimaryJourneyInstrumentedTest`                                                                         | 7 passed / 0 failures/errors/skips                                                                 |

### Remaining boundary

This establishes local, deterministic contract evidence and native emulator/simulator
evidence. It does not generate the native DTOs, replace the selected approved clinical
source adapter, create a clinical release, alter the existing remote reference encounter,
enable a patient flag, or satisfy pilot governance and independent-review gates.

## 2026-07-25 — Patient forward-compatibility fixture ratification

### Completed implementation

- `patient-pathway-events-forward-compatible.json` is a deterministic **test-only**
  derivation of the testing-runtime pathway-events BFF capture. Its provenance names it
  as derived, rather than presenting it as an additional source capture or release
  fixture.
- The fixture proves explicit `null` handling, an unknown `future_navigation` category,
  additive nested fields in `data`, `meta`, and `links`, exact integer preservation for
  `9007199254740993`, a precision-preserving decimal string, and a 256-item notice list.
- The iOS decoder maps an unknown pathway event category to generic `Care update`; the
  Android presentation vocabulary maps it to generic `Status being confirmed`. Neither
  app renders the unrecognized server value to a patient.

### Verification

| Boundary                            | Command / target                                                                                          | Result                             |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Laravel patient projection boundary | `PatientProjectionFixtureRegenerationTest`, `PatientSharedDtoFixtureTest`, and `PatientProjectionApiTest` | 18 passed / 779 assertions         |
| iOS native decoder                  | iPhone 17 Pro / iOS 26.3.1, `testForwardCompatiblePathwayEventsFixtureFallsBackToPatientSafeVocabulary`   | 1 passed / 0 failures              |
| Android native decoder              | `PatientProjectionFixtureDecodeTest` JVM suite                                                            | 7 passed / 0 failures/errors/skips |
| Android patient journey             | API 35 `hb` emulator / `PatientPrimaryJourneyInstrumentedTest`                                            | 7 passed / 0 failures/errors/skips |

### Remaining boundary

This is deterministic compatibility evidence for manually maintained client models. It
does not create generated DTOs, prove behavior against an approved source/release,
change any patient feature flag, or satisfy clinical, accessibility, privacy, or pilot
acceptance gates.

## 2026-07-25 — Staff Flow high-volume synchronization ratification

### Completed implementation

- The server already serves the high-volume staff Flow Window through a constrained
  `since` cursor: only append-only events and census snapshots are narrowed; current
  projections, geometry, bed status, and duties are renewed in full. Malformed or
  out-of-window cursors fail with `422 invalid_since` instead of silently becoming a
  potentially misleading full refresh.
- Static, non-live Flow geometry (`/flow/floors` and `/flow/spaces3d`) has a strong
  `ETag`/`If-None-Match` 304 path. The iOS and Android runtime paths use the Flow
  Window cursor; iOS now has direct native coverage for request construction, cursor
  echo decoding, duplicate-safe historical merge, and full current-state replacement.
- This control is intentionally confined to the staff Flow BFF. It does not change the
  private no-store treatment of patient projections or authorize a patient offline cache.

### Verification

| Boundary                      | Command / target                                                     | Result                              |
| ----------------------------- | -------------------------------------------------------------------- | ----------------------------------- |
| Laravel Flow contract         | `php artisan test tests/Feature/Mobile/FlowWindowTest.php --compact` | 22 passed / 865 assertions          |
| iOS native delta contract     | iPhone 17 Pro / iOS 26.3.1, `FlowWindowDeltaTests`                   | 3 passed / 0 failures               |
| Android native delta contract | `FlowDeltaTest` Debug and Release; `FlowCacheLogicTest` Debug        | 8/8 + 8/8 + 5/5 passed / 0 failures |
| Android staff regression      | API 35 `hb` emulator / full Debug instrumentation                    | 27 passed / 0 failures/errors/skips |

### Remaining boundary

This ratifies the bounded staff high-volume-read strategy. It does not establish
generated clients, make every mobile read cacheable, or apply caching to patient data.

## 2026-07-25 — Reference-patient dry-run made no-write

### Completed implementation

- `MobilePatientContextReferenceStore` now distinguishes deterministic opaque-handle
  derivation from issuance. Only issuance creates or refreshes the resolver row.
- The synthetic-reference provisioner uses derivation for preview and returns the
  explicit `patient_context_ref_issued=false` marker. An explicit commit retains the
  existing issuance behavior.
- The reference-provisioner regression now proves that the default command creates
  neither a `prod.encounters` row nor an `ops.patient_operational_context_cache` row.

### Verification

| Boundary                  | Command / target                                                                                 | Result                                                                                             |
| ------------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| Local regression          | `php artisan test tests/Feature/Mobile/HummingbirdReferencePatientProvisionerTest.php --compact` | 2 passed / 14 assertions                                                                           |
| Canonical database safety | `php artisan zephyrus:database-safety --json`                                                    | local runtime transaction and default are read-only                                                |
| Remote reconciliation     | `php artisan hummingbird:seed-reference-patient --unit-id=85 --json`                             | dry run found the existing active command-owned encounter `10040`; no context reference was issued |

### Remaining boundary

This verifies and preserves the synthetic reference exercise. It does not activate the
pending identity/grant, issue or reveal enrollment material, approve a clinical
projection, release any draft, enable a patient flag, or authorize a pilot.

## 2026-07-25 — Pre-round question and clarification journey ratification

### Completed implementation

- The patient `rounds_question` topic is an approved-policy, default-off composition
  path with a fixed **nonurgent** class. Its iOS and Android composers now state that
  a care team may review the question before a care conversation, that review does not
  promise discussion in a particular round, and that immediate needs use the existing
  urgent-help route.
- The server bridge remains explicit and fail-closed: eligible patient content appears
  in the matching authorized staff rounds workspace only after a capability-bearing
  staff member promotes it. Promotion produces a `rounds.questions` workflow record
  with bridge provenance and audit/event facts, not an order, care-plan mutation, or
  patient access to the staff workspace. Withdrawal, correction, repeat promotion,
  grant revocation, and bridge disablement preserve the same boundary.
- A staff resolution creates one deliberately generic patient-visible outcome without
  copying staff-rounds content into the patient message ledger. The post-round summary
  read surface remains separately governed and patient-readable.
- The education “teach-back” surface is intentionally a request for clarification,
  not an attestation. The API, durable association, iOS composer, and Android dialog
  contain no consent, comprehension, completion, clinical assessment, order, or
  care-plan field; unreleased education cannot create a message or fact.

### Verification

| Boundary                                        | Command / target                                                                                                                                                                             | Result                                                                                                                            |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Laravel rounds + messaging + patient projection | `php artisan test tests/Feature/Rounds/PatientRoundQuestionPromotionTest.php tests/Feature/Patient/PatientMessagingApiTest.php tests/Feature/Patient/PatientProjectionApiTest.php --compact` | 49 passed / 1,145 assertions                                                                                                      |
| Laravel clarification boundary                  | `php artisan test tests/Feature/Patient/PatientEducationClarificationApiTest.php --compact`                                                                                                  | 3 passed / 35 assertions                                                                                                          |
| iOS native                                      | iPhone 17 Pro / iOS 26.3.1: `PatientRoundsQuestionTopicTests` and `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage`                           | 3 unit tests and 1 UI journey passed / 0 failures                                                                                 |
| Android native                                  | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                                                                                             | 15 tests passed / 0 failures, errors, or skips; the journey selects the rounds topic and checks the nonurgent/no-guarantee notice |

### Remaining boundary

The staff lifecycle still lacks a governed patient-visible acknowledge, defer, and
route state machine; only explicit promotion and terminal generic resolution are
ratified here. The released rounds-summary reader does not establish an approved
production source, clinical-release owner, pilot responsibility pool, feature enablement,
or a patient production deployment. All patient flags remain off.
