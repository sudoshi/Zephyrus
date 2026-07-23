# Zephyrus–Hummingbird Functional Parity and Inpatient Experience Plan

> **Status:** Current execution plan and capability audit
> **Original audit date:** 2026-07-19; **implementation evidence verified through:** 2026-07-23
> **Scope:** Zephyrus web, Hummingbird staff iOS, Hummingbird staff Android, the Laravel mobile BFF, and a new patient-facing Hummingbird product
> **Supersedes for execution status:** the current-state assumptions and unfinished sequence in `PLATFORM-RECONCILIATION-TODO.md`; that document remains historical evidence
> **Decision rule:** functional parity means an intentional, verified disposition for every Zephyrus capability—not a literal phone-sized copy of every web page
> **Clinical/product boundary:** this is an engineering and product plan. Privacy, clinical-safety, accessibility, proxy-access, record-release, and communication policies require formal organizational approval before production use.

## 1. Executive conclusion

Hummingbird is no longer a prototype shell. It has a meaningful staff-mobile foundation:

- a three-tab role-adaptive shell on both platforms;
- 14 aligned staff personas;
- token-gated `/api/mobile/v1` endpoints with `mobile:read` and `mobile:act` separation;
- role homes, For You, Activity, Altitude, a 48-hour Flow surface, transport, EVS, RTDC, OR, staffing, improvement, operational approvals, Eddy, widgets, iOS Live Activities, and foreground realtime;
- contract, fixture, role-catalog, authorization, idempotency, redaction, and Flow Window tests.

It is **not yet functionally complete relative to Zephyrus**. The most important gaps are:

1. Zephyrus has materially broader operational coverage than the mobile BFF: virtual rounds, huddles, ancillary services, ED, most perioperative functions, radiology, laboratory, pharmacy, Home Hospital, deep analytics, prediction, administration, integration control, and several operational writes do not yet have complete mobile dispositions.
2. iOS and Android share vocabulary and much of the visible product grammar, but still duplicate DTOs, client behavior, caching, authorization interpretation, and business rules. The planned KMP layer does not exist.
3. iOS has a substantially more complete Eddy and push implementation. Android has Eddy context but not the staff chat/conversation/approval experience, and it has notification channels but no complete FCM registration/delivery path.
4. Staff session rotation is now cross-platform and server-governed: both clients use a process-wide single-flight coordinator, and Laravel maintains stable refresh-token families with one-time generations, reuse-theft detection, family-scoped revocation, and absolute expiry. User-facing device/session management, background timeout policy, and high-risk reauthentication remain incomplete.
5. Offline support is concentrated in Flow. Role queues and write workflows do not yet have a durable read cache, idempotent outbox, conflict UX, or replay policy.
6. Automated backend safety coverage is strong for the current slice. Conventional iOS unit/UI targets and Android unit/instrumentation foundations now cover the patient product and the staff patient-communications workflow, including large-text and privacy cases; the remaining staff workflows still need the same depth before parity can be claimed.
7. The current `A2P` patient context is a **staff operational lens**. It is deliberately PHI-minimized, tokenized, role-gated, and expressed in operational language. It is not a patient portal and must not be exposed to patients.

The recommended target is therefore two related but security-separated products:

| Product                 | Primary user                           | Primary question                                                             | API and identity realm                                                                  |
| ----------------------- | -------------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| **Hummingbird Staff**   | Hospital workforce                     | “What needs me now, and can I act safely?”                                   | Existing staff identity and `/api/mobile/v1`                                            |
| **Hummingbird Patient** | Inpatient or authorized representative | “What is happening in my care, what comes next, and how do I reach my team?” | New patient identity, relationship, consent, projection, and `/api/patient/v1` boundary |

**Do not add `patient` to `MobilePersonaCatalog`.** Share design tokens, generated contract tooling, and carefully selected UI primitives. Do not share staff authorization, PHI caches, push topics, app groups, deep links, or operational payloads with the patient application.

---

## 2. Outcomes and non-negotiable principles

### 2.1 Required outcomes

- [ ] Every current Zephyrus capability has an owner-approved mobile disposition: `NATIVE`, `GLANCE`, `NOTIFY`, `DEEPLINK`, `DESKTOP_ONLY`, `PATIENT`, or `RETIRED`.
- [ ] Every `NATIVE`, `GLANCE`, and `NOTIFY` disposition has a versioned BFF contract, authorization rule, data-provenance rule, iOS behavior, Android behavior, tests, telemetry, and release gate.
- [ ] Staff iOS and Android reach semantic parity: the same authorized roles can see the same meaning and perform the same supported action even when platform-native interaction differs.
- [ ] Hummingbird Patient provides a patient-readable pathway, care-team directory, rounds participation, secure messaging, education/teach-back, and discharge preparation without exposing staff-only operational data.
- [ ] Staff and patient applications degrade safely under stale, missing, conflicting, delayed, or revoked data.
- [ ] All mobile mutations are idempotent, version-aware, auditable, and attributable to a human or explicitly governed system actor.
- [ ] No release depends on a debug persona switcher, seeded password, hidden web completion step, or unverified production credential.

### 2.2 Product principles

1. **Parity is classified, not copied.** Dense analysis and administration can stay on web if the mobile product provides the correct glance, action, notification, or safe deep link.
2. **Patient-facing does not mean staff data with friendlier labels.** Patient content is a governed projection with its own release, sensitivity, plain-language, freshness, and provenance rules.
3. **The source system stays authoritative.** EHR/FHIR, Zephyrus operational domains, rounds, and ancillary systems remain systems of record. Hummingbird stores projections and patient interaction state, not a shadow clinical record.
4. **No inferred clinical certainty.** The UI distinguishes confirmed, planned, requested, waiting, delayed, completed, cancelled, and uncertain states.
5. **Communication is routed and accountable.** Patients message responsibility pools and care contexts; the product does not promise that any named individual is continuously available.
6. **Urgent care is never a chat SLA.** Every messaging surface visibly directs emergencies and immediate bedside needs to the locally approved urgent path.
7. **Least privilege is calculated on the server.** Native clients may hide controls for usability, but the API independently enforces every relationship, scope, capability, encounter, consent, and state transition.
8. **Offline is explicit.** Users always know whether content is current, cached, pending sync, rejected, or no longer accessible.
9. **Accessibility and language are release gates.** WCAG 2.2 AA, Dynamic Type/font scaling, screen-reader semantics, non-color status, reduced motion, plain language, and meaningful language access are testable requirements.
10. **AI explains; people decide.** Eddy may summarize approved content and help route a question. It must not diagnose, prescribe, fabricate certainty, autonomously alter care, or impersonate the care team.
11. **The environment should feel calm, warm, and distinctly Hummingbird.** Patient screens use the repository's reviewed Hummingbird photography as a quiet visual background system, never as a substitute for meaning and never at the expense of contrast, focus, reduced-motion settings, privacy, or clinical clarity.

---

## 3. Audit method and repository evidence

This audit reconciles runtime routes, server services, contracts, both native clients, tests, navigation, and prior planning. Counts are snapshots, not measures of quality or coverage.

### 3.1 Evidence reviewed

| Layer                 | Primary evidence                                                                                                                                          |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Zephyrus product map  | `resources/js/config/navigationConfig.ts`, `routes/web.php`, `routes/api.php`, domain controllers, models, and feature tests                              |
| Mobile BFF            | `routes/api.php` mobile group; `app/Http/Controllers/Api/Mobile/*`; `app/Services/Mobile/*`                                                               |
| Contract              | `docs/hummingbird/api-contract/hummingbird-bff.v1.yaml`, route inventory, shared fixtures, role catalog                                                   |
| Staff iOS             | `hummingbird/iosApp/Hummingbird/App`, `Features`, `Networking`, `Realtime`, push, widget, and Live Activity code                                          |
| Staff Android         | `hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird`, tests, manifest, notification channels, and widget code                              |
| Existing plans        | Hummingbird README, implementation, Altitude/persona, reconciliation, Flow, native 4D, design, Eddy, and security references                              |
| Virtual rounds        | routes, `rounds` schema/models/services/tests, virtual-rounds implementation plan, TODO, and development log                                              |
| Patient-adjacent data | `MobilePatientContextService`, `prod.encounters`, `flow_core.patient_identities`, ancillary milestones, `CareJourneyMilestone`, FHIR integration services |

### 3.2 Current implementation snapshot

- The staff catalog has **17 roles** in backend, iOS, and Android.
- The capability ledger currently inventories **58 staff mobile/auth operations** with a single owner, including all nine accountable patient-communication operations.
- Both native apps use a role home + For You + Activity production shell and one process-wide staff-session coordinator that proactively rotates the complete protected access/refresh pair.
- Both implement Flow snapshot/delta logic and a local Flow cache.
- Backend tests cover current route/OpenAPI parity, envelope shape, role vocabulary, shared DTO fixtures, authorization, idempotency, redaction, and high-value workflows.
- A separate patient API realm, patient principal/grant/session foundation, governed read-only projection kernel, independent iOS/Android patient apps, and disabled-by-default secure-messaging persistence/API kernel now exist. The accountable staff bridge has content-free outbox consumption, shared governed pool/responder resolution, inbox/detail/candidates, claim, patient-visible reply, close, release, reassign, cross-pool reroute, immutable staff/routing/receipt facts, scheduler heartbeat, overdue-response escalation, and scheduled encounter-lifecycle routing reconciliation (discharge close, **unit**-transfer reroute, shift release/coverage reroute, and pool-downtime reroute). Capability-gated Zephyrus and iOS/Android staff surfaces exercise all nine operations; patient-communication cards also project into each native For You queue through an intentionally PHI-minimized contract. Representative/delegation, production source projection, pathway interaction, attachments, push delivery, authoritative service ownership, production messaging-policy approval, and live deployed end-to-end validation remain incomplete.

#### 3.2.1 Verified through 2026-07-22 execution checkpoint

This checkpoint records repository implementation, not production approval or deployment. Patient and patient-communication features remain disabled by default.

| Slice                              | Implemented repository state                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Verification state                                                                                                                                                                                                                                                                                                                                                                                                                                                | Remaining release boundary                                                                                                                                                                                             |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Patient backend                    | Separate identity, enrollment/token/session realm; governed projections; eight patient messaging operations, including append-only correction/withdrawal and a source-bound released-education clarification request; active-session inventory and family revocation; feature-gated, released patient rounds-summary projection; default-off, patient-owned notification-device registration/revocation with a dedicated encryption keyring, keyed token lookup digest, opaque device UUID, installation binding, and content-free audit                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Static route/OpenAPI, capability-ledger, and immutable-operation-baseline checks pass; patient contract owns **25 operations**. Focused iOS and Android endpoint-boundary tests pass. The focused PHP feature suite is currently blocked before execution because its local PostgreSQL maintenance service is unavailable.                                                                                                                                        | Production identity proofing, approved source projections, native token acquisition, provider integration/feedback, governance, operational runbooks, and live-like E2E                                                |
| Staff communication BFF            | Nine capability-and-membership-gated operations: inbox, detail, route candidates, claim, patient-visible reply, close, release, reassign, and reroute; content-free handoff, immutable facts, exact replay, escalation, and lifecycle reconciliation                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | The lifecycle-reconciliation suite passes **10 tests / 148 assertions**; the full Patient suite passes; staff contract verifier owns all **nine operations**                                                                                                                                                                                                                                                                                                      | Authoritative service-ownership feed, push, production pool configuration, runbooks, and deployed E2E                                                                                                                  |
| Zephyrus staff web                 | Capability-gated workspace with content-free bootstrap, explicit no-cache detail load, all nine communication operations, exact replay, authorization-loss purging, retained-row transition reconciliation, and unit/team filters                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Four focused communication Vitest files pass **24 tests**; the complete frontend suite passes **636 tests in 144 files**; production Vite build passes                                                                                                                                                                                                                                                                                                            | Facility/service-line filtering, push, pilot telemetry, and deployed E2E                                                                                                                                               |
| Staff native communication         | iOS and Android implement capability-gated inbox/detail plus all six mutations, exact explicit replay, authorization/omission privacy purging, retained-row transition reconciliation, restricted For You cards/deep links, content-free possible-reroute recovery, and process-wide proactive/single-flight staff-token rotation. A 401 is automatically retried once only for a GET; mutations may refresh before first transmission but are never replayed after a 401. Laravel binds every issued generation to a stable server-side family and revokes that family on predecessor reuse.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | iOS passes **71/71** full unit and **16/16** full simulator UI tests plus an optimized Release build; Android passes **101/101** Debug and **98/98** Release JVM tests, lint/Debug+Release assembly, and **18/18** full API 35 instrumentation tests. The native suites include terminal server reuse handling without diagnostic leakage; the Android emulator suite also includes direct Keystore-backed complete-pair persistence and legacy-migration checks. | No push, authoritative service-ownership integration, user-facing staff session management, or live deployed staff-login E2E; wider legacy staff workflow depth remains incomplete                                     |
| Patient native session management  | Both patient apps expose a scenic, PHI-free Manage Devices surface backed by GET/DELETE session APIs; current-session revocation signs out and clears patient state                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | iOS passes **52/52** unit and **6/6** UI tests plus Debug/Release, deterministic-project, boundary/log/persistence scans; Android passes **82/82** Debug and **78/78** Release JVM tests plus the full **13/13** API 35 suite                                                                                                                                                                                                                                     | Recovery, federation, MFA/passkeys, production device policy, device registration, and push                                                                                                                            |
| Patient pathway, discharge, rounds | Both patient apps consume governed, read-only `pathway`, `pathway_events`, `discharge_readiness`, and feature-gated `rounds_summary` projections; their dynamic Messages composer additionally exposes an independently gated, non-promissory care-team-rounds question topic plus safe “possible review” and post-review statuses. They also provide one append-only, retained-history correction or withdrawal action for an eligible patient-authored message. My Path additionally lets a patient ask for an explanation of an item in the current released education projection, through a default-off, source-bound route that creates an encrypted, accountable care-team message plus content-free association fact; it records no comprehension, completion, consent, or clinician assessment. In the staff Virtual Rounds workspace, an independently gated, exact-authorized discovery panel renders only eligible patient questions and requires an explicit “possible review” confirmation before promotion. A content-free, one-to-one outcome fact lets terminal staff resolution append one generic, encrypted patient status without exposing internal round details. My Path distinguishes released stages, milestones, goals, education, explicitly categorized test/procedure/transport timeline moments, non-promissory discharge preparation, and released care-conversation topics. | Focused iOS Simulator and Android JVM endpoint-boundary tests pass for the education clarification route; broader iOS Simulator and Android API 35 emulator journeys exercise status, messaging, and categorized-pathway flows. The Rounds and Patient feature suites pass locally with staff discovery, promotion, withdrawal, outcome, correction/withdrawal, and authorization tests.                                                                          | Approved production sources, deployment governance, operational equipment/transport breadth, education assignment/review workflow, and clinical/patient-advisor validation remain.                                     |
| Patient visual system              | Both patient binaries use app-local reviewed Hummingbird imagery with deterministic scrims/fallbacks, decorative semantics, privacy covers, and saved nonclinical reading preferences. iOS never contracts the system Dynamic Type setting, removes scenery/strengthens cards for saved high contrast, and suppresses optional motion; Android applies the maximum of system/account text scale, uses a strict high-contrast Material scheme, and removes scenery for saved high contrast.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Focused iPhone 17 Pro unit/UI tests pass and its Extra Large/high-contrast Today screenshot was visually reviewed; focused Android API 35 unit/instrumentation checks pass the equivalent preference journey. **2026-07-22 reconfirmation:** the iPhone 17 Pro state-vocabulary/reference journey and Android API 35 reference journey both pass after the versioned state-vocabulary and accessibility-matrix work.                                              | Image source/license/attribution approval is a production **HOLD**; patient/family usability review, formal contrast evidence, comprehensive system-setting matrix, and full large-text/state capture remain required. |
| Contract and CI controls           | Capability ledger, generated report, staff OpenAPI verifier, patient OpenAPI verifier, disclosure-matrix verifier, patient product-boundary scanners, and independent patient iOS/Android CI jobs                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Verifiers own **51 capabilities / 100 navigation routes / 58 staff mobile-auth operations / 25 patient operations**, all **nine** staff communication operations, and **11/14/3** disclosure controls; targeted Pint passes; CI has no deployment step                                                                                                                                                                                                            | Normalize path casing and observe clean Linux CI; CI has not run remotely for this uncommitted work; governance review cannot be replaced by CI                                                                        |

#### 3.2.2 Safety and routing tranche completed on 2026-07-20

- [x] Seed and safely read back the canonical synthetic inpatient. `hummingbird:seed-reference-patient` owns exactly one active row for encounter `10040` in unit `85`; the content-minimized verification returned `count=1`, `active_count=1`, `owned_count=1`, and `encounter_ids=[10040]`. No patient principal or enrollment secret was provisioned because the required deployment HMAC boundary is unavailable and patient features remain disabled.
- [x] Enforce the active canonical encounter on every messaging disclosure and fresh mutation. Missing, deleted, discharged, or inactive encounters now produce generic audited denial; fresh work fails closed without adding content.
- [x] Separate exact replay from fresh-write readiness. Exact create/send/close replay still requires the current grant and disclosure authority but can confirm the original committed fact during a stale consumer heartbeat, write-path outage, or topic retirement; changed payloads still conflict and cannot create a second fact.
- [x] Make handoff readiness respect every unresolved outbox state. Future-backoff, terminal, unexpired unresolved, and concurrent failure states keep readiness degraded; due selection honors `next_attempt_at`, rechecks under lock, and suppresses duplicate immediate failure facts.
- [x] Centralize governed responder and responsibility-pool decisions. Eligibility requires effective membership, `can_reply`, an active user, and `RespondPatientCommunications`; automatic routing uses exact policy/topic/digest/current active encounter scope with unit → matching facility → enterprise precedence; same-tier ambiguity and facility mismatch fail closed; established work never silently substitutes another pool.
- [x] Bind staff routing to one canonical thread/work-item/grant identity. Route discovery and locked mutations reject a drifted `thread.access_grant_id`/`work_item.access_grant_id`, while manual destination validation rechecks an eligible responder under lock and preserves source authorization through effective membership plus `can_reroute`.
- [x] Implement privacy-preserving exact mutation recovery on web, iOS, and Android. Claim/reply/close retain an immutable in-memory tuple only after an uncertain result and expose explicit same-request replay; fresh controls remain locked. A reroute that may have committed immediately removes the source inbox/detail/draft/candidate projection, quarantines the opaque work-item UUID against late polls, and retains only its content-free exact tuple. Confirmed exact reroute replay returns only the immutable event receipt and generic destination-team copy.
- [x] Automate the accountable-routing lifecycle against canonical encounter facts. The feature-gated `PatientCommunicationLifecycleReconciliationService`—scheduled every minute as a bounded, single-server, non-overlapping `hummingbird:reconcile-patient-communications --once` run—closes discharged encounters, reroutes **unit** transfers in place, releases an ended assignee to an eligible pool backup, escalates a shift-coverage gap to the staffed facility/enterprise fallback, and reroutes pool downtime to an unambiguous fallback. It matches the staff-mutation lock order, preserves thread/work/grant identity, appends each content-free staff/routing/receipt fact exactly once (idempotent on re-runs), and degrades to `unresolved`—never a silent pool substitution or thread close—when no eligible destination exists. Ten reconciliation tests (148 assertions) pass; service-change routing is intentionally deferred pending an authoritative source, features remain off, and deployed E2E remains.

#### 3.2.3 Controlled production release and reference-identity evidence — 2026-07-22

- [x] Publish the consolidated, clean `main` checkout through the canonical migration-enabled `./deploy.sh` procedure. The deployed release marker is `6121df89d7363e054bd9d7f21063f7e93f97997b`; Apache and the supervised queue worker were active after deployment, and all patient foundation, messaging, pathway, notification-device, education-clarification, care-preference, and pathway-history migrations were reported `Ran`.
- [x] Run the idempotent reference-encounter dry run and commit against only command-owned encounter `10040` in active unit `85` (`5 East — Medical/Surgical`). The committed result remains an active, synthetic, command-owned source encounter.
- [x] Run the reference-identity dry run and commit on the deployed runtime without `--show-secrets`. It created one pending patient principal, one verified encrypted identity link, one pending access grant, and one redacted one-time enrollment challenge for each native platform. No challenge value, verification code, application key, or HMAC value was emitted to the terminal, checklist, or source tree.
- [x] Restore the safety boundary immediately after the controlled exercise. `HUMMINGBIRD_PATIENT_ENABLED`, `HUMMINGBIRD_PATIENT_MESSAGING_ENABLED`, and `HUMMINGBIRD_PATIENT_REFERENCE_PROVISIONING_ENABLED` are all `false`; an unauthenticated deployed `GET /api/patient/v1/me` returns generic `404`.
- [ ] Deliver the separately held iOS and Android one-time enrollment challenges through the approved secure enrollment workflow; do not use terminal output, chat, or source control. Do not activate a patient account, enable a patient feature, or begin a clinical pilot from this reference exercise.

#### 3.2.4 Staff contract-governance and emulator revalidation — 2026-07-23

- Every one of the **58** registered staff mobile/auth operations now carries governed authorization, request/response/storage data classification, idempotency/retry, and error-envelope/status behavior in `hummingbird-bff.v1.yaml`. The verifier compares the complete OpenAPI operation inventory with Laravel, requires `mobile:read` on all 54 mobile BFF operations, and proves `mobile:act` declarations match route middleware exactly. Refresh-token authentication is now explicitly bearer-token protected in the contract.
- The CI gate includes a negative self-test that independently removes each required extension and proves the verifier rejects authorization, data-classification, idempotency, and error-behavior omissions. Normal staff, patient, capability-ledger, route-inventory, and immutable contract-baseline verification all pass locally.
- Device revalidation is green: staff iOS passed **60/60 unit** and **16/16 simulator UI** tests; staff Android passed **16/16 connected instrumentation** tests; the focused patient iOS reference journey passed **4/4 simulator UI** tests; and the patient Android suite passed **15/15 connected instrumentation** tests. There were no failures or skips. These are simulator/emulator and synthetic-contract results, not a live patient pilot.
- A read-only, content-minimized production check reconfirmed the command-owned synthetic encounter `10040` in active unit `85`, one pending patient principal, one verified identity link, one pending access grant, two issued platform enrollment challenges, and **zero** released patient projections. No challenge or credential value was emitted. The patient product and reference-provisioning flags remain disabled, so this evidence does not activate an account, approve content, or authorize patient use.

#### 3.2.5 Staff token-rotation and stable-family parity — 2026-07-23

- [x] Both staff clients now store one complete access/refresh generation with its access expiry in device-only protected storage: one Keychain blob on iOS and one committed `EncryptedSharedPreferences` value on Android. Legacy split keys migrate into the complete-pair form with an intentionally expired access timestamp, forcing rotation before use; an incomplete pair or protected-persistence failure clears the local session rather than falling back to plaintext. A transient bootstrap `/me` failure withholds authenticated UI but retains the protected generation for a later verification attempt; only terminal auth rejection erases it.
- [x] Every staff `/api/mobile/v1` request now passes through one process-wide coordinator. At or within **120 seconds** of access expiry, concurrent callers share one refresh operation. A successful response must rotate both credentials and be durably protected before the new access token is used. A terminal `401`/`403` from the refresh endpoint clears the protected session; a transient proactive-refresh failure may use the old access token only while it is still valid.
- [x] Automatic replay is deliberately narrow: one `GET` may refresh and replay once after `401`; a mutation may refresh before its first transmission but is never automatically resent after a `401`. If the one GET replay rejects the freshly rotated access token, that exact generation is cleared rather than entering a rotation loop; a concurrently installed newer generation is preserved. The iOS audit also brought Eddy chat—previously a direct `URLRequest` path—through this same gate while retaining its 60-second timeout and decodable `503` unavailable envelope.
- [x] Laravel now creates a durable `prod.mobile_token_sessions` lifecycle row for each staff login. Separate session and token-family UUIDs are server-generated; access and refresh token names are bound to the stable family UUID; only the current refresh-token row ID is retained as a generation pointer; and bearer material remains exclusively as Sanctum's one-way hash. Access expiry is capped by the family's non-sliding absolute expiry. A legacy `mobile-refresh` credential is transactionally adopted once, with unmatched legacy access tokens retired.
- [x] Rotation locks the exact presented Sanctum row and its family row in a fixed order. The predecessor loses every ability but its one-way hash remains as a tombstone until absolute expiry, the prior access generation is deleted, and one successor pair becomes current atomically. Presenting any non-current predecessor records `refresh_token_reuse_detected`, returns only the generic public `invalid_refresh_token` envelope, deletes every access/refresh row in that family, and marks the lifecycle row revoked. Other device families remain active. Logout, account deactivation, password change, identity/capability revocation, and other account-wide credential retirement reconcile lifecycle rows with token deletion.
- [x] Tombstones cannot use the staff BFF or password-change surface: `mobile:read` still gates the BFF, and password change now requires one of `password:change`, `mobile:read`, or `token:refresh`. Refresh intentionally remains controller-enforced so an ability-less predecessor can reach theft detection; revoke intentionally accepts any authenticated family credential for safe cleanup. Expired Sanctum hashes have a daily, single-server, non-overlapping prune schedule with 24-hour retention.
- [x] Backend evidence is green on an isolated PostgreSQL 16/pgvector database: the focused auth and lifecycle matrix passes **38 tests / 266 assertions**, including one-way bearer hashing, stable-family issuance, one-time rotation, tombstone confinement, generic reuse response plus specific server audit, compromised-family isolation, absolute expiry, legacy adoption, logout, account deactivation, password change, rollback, and adjacent access-governance behavior. Static contract, immutable-baseline, capability-ledger, route, lint, and formatting checks are separate release gates.
- [x] Device and build evidence is green with no skips: iOS **71/71 unit**, **16/16 UI** on an iPhone 17 Pro simulator, and optimized Release build; Android **101/101 Debug JVM**, **98/98 Release JVM**, lint, Debug/Release assembly, and **18/18** instrumentation tests on an API 35 emulator. Focused cross-platform tests cover near-expiry writes, one-time GET replay, rejected-replay invalidation, mutation non-replay, eight-caller single flight, transient fallback and bootstrap retention, terminal refresh/reuse rejection without server diagnostic leakage, rotated-pair persistence, protected-store failure, cleared-generation non-resurrection, and Android Keystore-backed migration.

### 3.3 Parity status vocabulary

| Code             | Meaning                                                                                        |
| ---------------- | ---------------------------------------------------------------------------------------------- |
| `COMPLETE`       | Behavior, contract, authorization, both clients, tests, and operational readiness are verified |
| `PARTIAL`        | Valuable implementation exists, but one or more required layers or cases are incomplete        |
| `IOS_LEAD`       | iOS supports behavior not semantically matched on Android                                      |
| `ANDROID_LEAD`   | Android supports behavior not semantically matched on iOS                                      |
| `BFF_ONLY`       | Server contract exists without complete client behavior                                        |
| `WEB_ONLY`       | Zephyrus has the capability; Hummingbird has no approved mobile treatment                      |
| `PLANNED`        | Design or plan exists without production implementation                                        |
| `NOT_APPLICABLE` | Capability is intentionally excluded, with documented rationale and safe deep link if useful   |

### 3.4 Disposition vocabulary

| Disposition    | Definition                                | Required artifact                                    |
| -------------- | ----------------------------------------- | ---------------------------------------------------- |
| `NATIVE`       | Complete mobile read/action workflow      | BFF + iOS + Android + tests + telemetry              |
| `GLANCE`       | Condensed status, no complex editing      | Summary contract + stale-state behavior              |
| `NOTIFY`       | Event-driven alert with safe route/action | Event contract + routing + suppression/escalation    |
| `DEEPLINK`     | Open authenticated web context            | Stable route + mobile handoff + access test          |
| `DESKTOP_ONLY` | Explicitly retained on web                | Rationale, owner, annual review                      |
| `PATIENT`      | Patient product projection or interaction | Patient contract + disclosure policy + safety review |
| `RETIRED`      | Capability intentionally removed          | Deprecation and migration record                     |

---

## 4. Current Hummingbird staff architecture and deltas

### 4.1 What is genuinely implemented

| Capability             | Backend                                                                                   | iOS                                                                         | Android                                                                     | Status                                                                                                                                                                              |
| ---------------------- | ----------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- | --------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Staff token login      | Sanctum issue/revoke/refresh/change-password plus stable family lifecycle/reuse detection | Login, Keychain, biometric lock, single-flight rotation                     | Login, encrypted preferences, biometric lock, single-flight rotation        | `PARTIAL`: core rotation/reuse parity is verified; self-service session management, background timeout, and high-risk reauthentication remain                                       |
| Role alignment         | Server catalog and role resolver                                                          | 14 role experiences                                                         | 14 role experiences                                                         | `PARTIAL`: static parity, still manually duplicated                                                                                                                                 |
| Production shell       | Role home, For You, Activity data                                                         | Three tabs                                                                  | Three tabs                                                                  | `PARTIAL`: semantics aligned; native QA incomplete                                                                                                                                  |
| Patient communications | Content-free inbox, governed candidates, all six mutations, and For You projection        | Native queue, detail, all six mutations, exact recovery, and safe deep link | Native queue, detail, all six mutations, exact recovery, and safe deep link | `PARTIAL`: manual routing is cross-platform; automated handoff/transfer/discharge/downtime reconciliation is implemented and test-covered; push, telemetry, and deployed E2E remain |
| Altitude A0/A1/A2/A2P  | Home/workspace/drill/patient context                                                      | Integrated context and drills                                               | Integrated screens and drills                                               | `PARTIAL`: staff access and patient-unit calculation need hardening                                                                                                                 |
| Flow 48-hour surface   | Floors, spaces3d, scenarios, history, window/deltas                                       | Native map/3D-oriented experience and cache                                 | Native 2.5D map and cache                                                   | `PARTIAL`: visual differences valid; accessibility/performance/offline gates incomplete                                                                                             |
| Transport              | Queue, status, structured handoff                                                         | Claim/run/status/handoff                                                    | Claim/run/status/handoff                                                    | `PARTIAL`: broader Zephyrus transport lifecycle is not covered                                                                                                                      |
| EVS                    | Queue and status                                                                          | Claim/start/complete                                                        | Claim/start/complete                                                        | `PARTIAL`: exceptions, cancellation, reassignment, resource view absent                                                                                                             |
| RTDC                   | Census, house, placements, recommendations, placement decision, barrier resolve           | Role views and actions                                                      | Role views and actions                                                      | `PARTIAL`: request creation, huddles, escalation, broader barriers absent                                                                                                           |
| OR                     | Board                                                                                     | Read-only role view                                                         | Read-only role view                                                         | `PARTIAL`: case status, delay, safety notes, blocks, forecasts absent                                                                                                               |
| Ops approvals          | Inbox and governed decision                                                               | Supported                                                                   | Supported                                                                   | `PARTIAL`: action assign/start/complete/override absent                                                                                                                             |
| Staffing               | Overview, candidates, fill                                                                | Supported                                                                   | Supported                                                                   | `PARTIAL`: create/assign/status/cancel and fulfillment lifecycle absent                                                                                                             |
| Improvement            | PDSA and opportunities                                                                    | Read-only                                                                   | Read-only                                                                   | `PARTIAL`: workflow mutations and richer process intelligence absent                                                                                                                |
| Eddy staff             | Chat, stream, conversations, approvals, context                                           | Chat and context experience                                                 | Context screen only                                                         | `IOS_LEAD`                                                                                                                                                                          |
| Foreground realtime    | Reverb config                                                                             | Reverb client + poll fallback                                               | Reverb client + poll fallback                                               | `PARTIAL`: reconnect, subscription governance, background behavior need hardening                                                                                                   |
| Push                   | Device registry and APNs seam                                                             | APNs registration/delivery path                                             | Channels only                                                               | `IOS_LEAD`: end-to-end FCM absent                                                                                                                                                   |
| Widgets                | Summary-compatible data                                                                   | House/For You widgets and Live Activities                                   | House glance widget                                                         | `PARTIAL`: platform differences acceptable; refresh/privacy QA required                                                                                                             |
| Offline                | Flow contracts and idempotent writes                                                      | Flow cache                                                                  | Flow cache                                                                  | `PARTIAL`: no general queue cache or mutation outbox                                                                                                                                |

### 4.2 Cross-platform defects and architectural debt

- [ ] Generate or share DTOs instead of maintaining independent PHP/OpenAPI/Swift/Kotlin interpretations.
- [x] Decide explicitly whether the promised KMP domain/data layer remains the target. If yes, create it and migrate incrementally; if no, replace all KMP claims in documentation with generated-client and contract-test controls. _(2026-07-19: generated native clients/rules accepted; README and control-plane links reconciled; KMP runtime deferred behind ADR revisit criteria.)_
- [ ] Replace role-catalog duplication with one generated source or a build-time generated artifact per platform.
- [x] Make token refresh proactive and single-flight on both clients; retry only idempotent reads automatically. _(2026-07-23: both staff clients share one process-wide coordinator, refresh at a 120-second lead, store a complete protected token generation, replay one GET at most once after 401, and never replay a mutation after 401. Full native and emulator evidence is recorded in §3.2.5.)_
- [x] Implement forced-password change natively on Android and verify the challenge token cannot access mobile data. _(2026-07-19: the challenge is held only in memory, routes to a native current/new/confirm flow, cannot satisfy `mobile:read`, and successful completion atomically revokes old credentials before issuing a verified pair. Debug/Release builds and 49 JVM tests passed; an Android emulator traversed challenge → native form → authenticated shell, while the local database verified `must_change_password=false`, session generation `1`, and exactly two replacement tokens. A forced revocation failure test proves password mutation rollback.)_
- [x] Remove Android's ordinary-preferences fallback for secrets. Fail closed and surface a supported-device error if protected storage is unavailable. _(2026-07-19: encrypted-store construction is terminal, sign-in is disabled with a supported-device error, failed secure persistence revokes the issued token, and focused policy tests pass.)_
- [ ] Add environment-aware certificate trust policy, transport security tests, and production endpoint pinning decision.
- [x] Define one error taxonomy: unauthenticated, unauthorized, forbidden-by-relationship, stale version, invalid transition, rate limited, offline, server unavailable, and contract mismatch. _(2026-07-20: `app/Http/Errors/ErrorCategory.php` is the nine-case backed enum (exactly these classes, each with a typical HTTP status and a `retryableRead()` policy); `app/Http/Errors/ErrorCatalog.php` classifies every patient + staff-communication wire leaf-code into one category; documented in `docs/hummingbird/error-taxonomy.md`; `ErrorTaxonomyTest` (3 tests/81 assertions) pins the nine categories and that every mature-surface wire code is classified. Broader staff-mobile leaf-code adoption is incremental.)_
- [ ] Define a client-generated `request_id`/idempotency key for every mutation and persist it through retry.
- [ ] Add an encrypted read cache and outbox for the approved mobile workflows; partition by user, facility, persona, environment, and product.
- [ ] On logout/revoke/role change, cryptographically erase tokens and all user-scoped cached data.
- [ ] Make realtime events hints that trigger authorized refetches; never trust an event payload as the final authorization decision.
- [ ] Verify every deep link after authentication and role resolution; reject stale or unauthorized resource identifiers without data leakage.
- [ ] Create normal XCTest and Android instrumentation targets with deterministic fixtures, accessibility checks, and screenshot baselines.
- [ ] Consolidate the case-colliding frontend directory families (`Components`/`components`, `Hooks`/`hooks`, and related imports) into one Git-canonical spelling so `tsc --noEmit` is reproducible on case-insensitive macOS worktrees as well as Linux CI.
- [x] Replace demo credentials and debug launch affordances in release builds with compile-time excluded test configuration. _(2026-07-19: blank login defaults; iOS `#if DEBUG`; Android debug/release source sets; both release builds exclude test keys and credential literals; Android release emulator ignored injected hooks.)_
- [x] Update `docs/hummingbird/README.md`, which still describes planning-era gaps and KMP as already selected architecture. _(2026-07-19: current implementation state, generated-native decision, patient boundary, ledger, disclosure draft, and ADR front doors reconciled.)_
- [x] Regenerate `mobile-route-contract-inventory.md`; its July 2 snapshot no longer reflects all Flow and Eddy routes. _(2026-07-20: replaced the hand-maintained July-2 snapshot with `scripts/generate-hummingbird-route-inventory.php`, which renders the inventory deterministically from the live `/api/mobile/v1/*` route table (now 54 routes, including the 3 Flow and 9 patient-communications routes the snapshot missed) with no volatile date/counts. A `--check` freshness gate runs in CI alongside the capability-report check; the authoritative route↔OpenAPI parity guard remains `MobileBffTest`.)_

### 4.3 Server-side issues requiring early remediation

- [x] Expand unit-scoped patient-context authorization to derive active patient unit from `prod.encounters` and other authoritative current-location sources, not only ED visits and EVS requests. _(2026-07-19, refactored 2026-07-22: `PatientOperationalContextLookup::activeUnitIds()` supplies active, non-deleted encounter, nondeparted ED, and nonterminal EVS units to the named `UnitPatientOperationalContextPolicy`; the historical-source denial regression remains part of the pending named-policy CI gate below.)_
- [x] Add tests for assigned-unit access when a patient exists only in the active inpatient encounter spine. _(2026-07-19: all four unit-scoped personas pass assigned access; other-unit, raw-reference, discharged, and deleted cases are denied; full 22-test safety suite passes.)_
- [x] Eliminate direct raw `patient_ref` acceptance at controller boundaries. Require opaque context references outside trusted internal services. _(2026-07-19: mobile/web patient routes accept only exact `ptok_` handles; resolver rejects raw identifiers; Eddy rejects and does not echo unsafe scope input; safety suite passes 22 tests/317 assertions.)_
- [x] Review the candidate enumeration used to resolve `ptok_` references; replace bounded database scans with a persisted, indexed, revocable mapping or a signed encrypted identifier. _(2026-07-19: all issuers share a dedicated-key HMAC handle store backed by the unique `patient_context_ref` index; resolution is one expiring lookup; explicit revocation and reissue tests pass.)_
- [x] Define expiry/revocation behavior for staff patient context references. _(2026-07-20, refactored 2026-07-22: `MobilePatientContextReferenceStore` issues a deterministic HMAC handle stamped with a configurable TTL (`hummingbird.patient_context.ttl_minutes`, default 15, clamped 1–1440); `resolve()` returns null for any expired or revoked handle; `revoke()` sets immediate expiry without deleting the row; reissue refreshes the same handle to a fresh window; and downstream `MobilePatientContextAuthorizationService` independently re-evaluates encounter/role/task authorization on every request regardless of handle validity. `MobileBackendSafetyTest::test_patient_context_reference_honors_configured_ttl_and_expiry` (6 assertions) proves the TTL stamp, time-based expiry denying resolution, and live-handle reissue, alongside the existing indexed/expiring/revocable-mapping test.)_
- [x] Split broad and unit/task-level patient access policies into named policy classes with audit reason codes. _(2026-07-22: `MobilePatientContextAuthorizationService` evaluates separately named broad-persona, house-operations, active-transport, active-EVS, and shared-active-unit policies on every staff disclosure; it rejects inactive accounts and stale personas before source-context lookup, requires a still-current authoritative workflow source, writes content-free opaque-handle audit decisions with machine reason codes, and atomically commits the populated context cache with the success audit so an audit outage fails closed without an unaudited cached payload. Focused `MobileBackendSafetyTest` cases prove allowed/denied scope, historical-unit and terminal-workflow exclusion, current-location projection, no false success event after a build failure, and audit-outage rollback/non-disclosure. CI run `29973300332` on mainline superset `affe3684` (containing implementation/test head `ba030686`) passed **17/17 jobs**; feature shard 6 passed **237 tests / 3,067 assertions**, including all five focused policy regressions, and the iOS/Android patient emulator jobs passed.)_
- [x] Turn `PersonaRelayPolicy::NOT_EMITTED_YET_EVENT_TYPES` into a tracked implementation backlog; do not present implied Activity completeness until sources emit events. _(2026-07-20: the not-emitted set is now a code-adjacent tracked backlog—each pending event type carries the reason it is silent, with disposition/owner/target-phase anchored to the plan §5 delta matrix. `PersonaRelayPolicy::activityTaxonomy()` exposes the emitted-vs-pending split (plus `emittedEventTypes()`/`pendingEventTypes()`/`pendingBacklog()`) so surfaces report truthful completeness. `PersonaRelayPolicyTest` (2 tests/202 assertions) proves emitted and pending partition the taxonomy with no overlap or omission, the backlog is non-empty with a reason per entry, and every event claimed emitted has a real relay path.)_
- [x] Add authorization tests for cross-facility, cross-unit, inactive assignment, expired task ownership, revoked capability, and changed role. _(2026-07-20: covered for the mobile patient-context authz surface in `MobileBackendSafetyTest` (25 tests/334 assertions). **cross-unit** — assigned-unit personas reach the context, other-unit personas are denied; **expired task ownership** — new test proves transport/EVS personas reach a patient only while their task is `active()` and lose access once it is completed/cancelled or absent; **revoked capability** — indexed/expiring/revocable `ptok_` handle revocation denies resolution, and `mobile:act` is required for writes; **changed role** — new test denies roles without broad access, an active task, or a shared unit, and roles are re-evaluated every request so a changed role loses access on the next call. **cross-facility** collapses into cross-unit here (this path routes on unit membership, not a separate facility dimension) and **inactive assignment** is not a modeled state on the `user_unit` pivot. The broader platform-wide authorization matrix (§13.1) remains open.)_
- [ ] Add optimistic concurrency to every workflow that can be changed concurrently by web and mobile.
- [ ] Ensure every mobile write produces exactly one canonical operational event and never a second event on replay.

---

## 5. Zephyrus-to-Hummingbird functional delta matrix

The “target” column is the recommended product disposition. It must be ratified by product, clinical operations, security, and the named domain owner before implementation.

### 5.1 Cockpit, command, and Eddy

| Zephyrus capability                               | Current Hummingbird delta                                                               | Target                        | Priority / acceptance                                                                 |
| ------------------------------------------------- | --------------------------------------------------------------------------------------- | ----------------------------- | ------------------------------------------------------------------------------------- |
| Enterprise dashboard / house cockpit              | Executive house brief and Flow rollups exist; not all cockpit signals/scopes are mobile | `GLANCE` + `DEEPLINK`         | P0: agreed KPI subset, freshness, source, thresholds, and web handoff                 |
| Live signals / alert acknowledgement              | Activity exists; alert types and acknowledgement coverage are incomplete                | `NOTIFY` + `NATIVE`           | P0: all eligible signals mapped to urgency, role, route, ack, suppression, escalation |
| Executive brief                                   | House brief exists                                                                      | `NATIVE`                      | P1: variance explanation, timestamp, facility scope, offline summary, no PHI          |
| Agent inbox                                       | Operational approvals are a subset                                                      | `NATIVE`                      | P1: approval + action lifecycle with audit, concurrency, and explicit AI provenance   |
| Operational action assign/start/complete/override | Web API exists; mobile BFF does not expose full lifecycle                               | `NATIVE` for assigned actions | P1: authorized transitions and rollback-safe event emission                           |
| Eddy context                                      | Server and both platforms have context                                                  | `NATIVE`                      | P1: identical scope semantics and data classification                                 |
| Eddy chat/conversations                           | BFF and iOS exist; Android lacks full experience                                        | `NATIVE`                      | P1: Android parity, streaming fallback, conversation retention/deletion policy        |
| Eddy approval inbox                               | BFF exists; client completeness must be verified role by role                           | `NATIVE`                      | P1: human decision only, no autonomous approval                                       |
| Agent definitions/runs/admin knowledge/usage      | Web administration only                                                                 | `DESKTOP_ONLY`                | P0 disposition record; no mobile admin secrets or prompt controls                     |
| Simulation promotion                              | Web governed flow only                                                                  | `DEEPLINK`                    | P2: mobile may notify/inspect; final promotion stays web until safety case exists     |

### 5.2 RTDC, capacity, bed flow, and virtual rounds

| Zephyrus capability                              | Current Hummingbird delta                                    | Target                                                               | Priority / acceptance                                                                     |
| ------------------------------------------------ | ------------------------------------------------------------ | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Census and house status                          | Implemented in BFF and role homes                            | `NATIVE`                                                             | P0 hardening: scope, freshness, stale behavior, accessible statuses                       |
| Bed requests queue                               | Read and decision supported                                  | `NATIVE`                                                             | P0: filters, paging, refresh, concurrency, all valid lifecycle states                     |
| Bed request creation                             | Absent                                                       | `NATIVE` for authorized roles                                        | P1: minimal create flow, required clinical/operational fields, draft protection           |
| Placement recommendations                        | Read supported                                               | `NATIVE`                                                             | P0: recommendation provenance, constraints, no silent auto-selection                      |
| Placement decision                               | Supported                                                    | `NATIVE`                                                             | P0: unsafe-placement guard, version conflict, audit, undo/escalation policy               |
| Discharge barriers                               | Resolve supported; create/assign/escalate incomplete         | `NATIVE`                                                             | P1: create, owner, due time, escalation, resolution evidence                              |
| Bed tracking                                     | Role census and Flow provide slices                          | `GLANCE` + `NATIVE` drill                                            | P1: bed state legend, unit/floor scopes, exception workflow                               |
| Patient Flow 4D                                  | Native Flow is meaningful but not feature-identical          | `NATIVE` mobile lens                                                 | P1: retain platform-native 2.5D/3D; semantic and accessibility parity required            |
| Flow history and scenarios                       | Routes exist; product reach varies                           | `GLANCE` + `DEEPLINK`                                                | P2: no misleading forecast certainty; scenario actions remain web                         |
| Ancillary service milestones                     | Staff A2P may infer some context; no dedicated mobile queues | `NATIVE` for task owners, `GLANCE` for care team                     | P2: radiology/lab/pharmacy milestones and delay ownership                                 |
| Global/unit/service huddles                      | Zephyrus models/APIs exist; Hummingbird has no full workflow | `NATIVE`                                                             | P1: agenda, attendance, barriers, actions, owner, due time, close, event feed             |
| Virtual rounds board                             | Backend/web phases 1–2 exist; no mobile rounds package       | `NATIVE` staff patient-scoped subset                                 | P1: queue, participant, contribution, question, task, summary; no whole-board PHI in push |
| Family/interpreter participation                 | Planned but explicitly blocked                               | `PATIENT` after consent and vendor policy                            | P3: separate token/identity path; never reuse staff guest access assumptions              |
| Demand, resource, discharge, risk predictions    | Mostly web-only                                              | `GLANCE`/`NOTIFY` for actionable exceptions; `DEEPLINK` for analysis | P2: model version, horizon, confidence, driver, actionability, governance                 |
| Utilization/performance/resource/trend analytics | Web-only                                                     | `GLANCE` + `DEEPLINK`                                                | P3: curated KPI cards only; dense exploration remains web                                 |

### 5.3 Emergency Department

| Zephyrus capability               | Current Hummingbird delta                                                       | Target                          | Priority / acceptance                                                                  |
| --------------------------------- | ------------------------------------------------------------------------------- | ------------------------------- | -------------------------------------------------------------------------------------- |
| ED triage                         | No dedicated mobile workflow; current web surface includes placeholder behavior | `DEEPLINK` initially            | P3: do not mobile-port mock workflows; define real source and owner first              |
| ED treatment board                | No dedicated role/package                                                       | `GLANCE` for charge/house roles | P3: boarding, holds, disposition readiness, aging, assignment                          |
| ED resource operations            | Indirect capacity signals only                                                  | `GLANCE` + `NOTIFY`             | P3: actionable thresholds and escalation owners                                        |
| ED patient flow                   | Some Flow/A2P overlap                                                           | `NATIVE` lens                   | P2: ED-specific lens and authorized drill, not a duplicate engine                      |
| Arrival/acuity/resource forecasts | Web prediction surfaces                                                         | `GLANCE` + `NOTIFY`             | P3: governed forecast cards with confidence/freshness                                  |
| Wait/resource analytics           | Web-only                                                                        | `DEEPLINK`                      | P3: no dense native rebuild unless user research proves need                           |
| ED patient-facing status          | Does not exist                                                                  | `PATIENT`                       | Patient P2: explain stage, care team, expected next step, uncertainty—never queue rank |

### 5.4 Perioperative

| Zephyrus capability                            | Current Hummingbird delta                         | Target                                   | Priority / acceptance                                                                 |
| ---------------------------------------------- | ------------------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------- |
| Room status board                              | Read-only OR board exists                         | `NATIVE`                                 | P1: complete room/case states, delayed-data signal, safe PHI depth                    |
| Case management                                | No complete mobile mutation flow                  | `NATIVE` for assigned transition actions | P2: capability/state gates, safety notes, concurrency, audit                          |
| Block schedule                                 | No mobile package                                 | `GLANCE` + `DEEPLINK`                    | P2: today's changes/releases; editing remains web initially                           |
| Utilization forecast/demand/resource planning  | Web-only                                          | `GLANCE` + `DEEPLINK`                    | P3: exception-oriented summaries                                                      |
| Block/OR/prime-time/room/turnover/IR analytics | Web-only                                          | `GLANCE` + `DEEPLINK`                    | P3: curated daily operational measures only                                           |
| Delay and status advancement                   | Listed as not emitted/implemented in relay policy | `NATIVE`                                 | P1: event type, transition contract, reason codes, downstream Activity                |
| Patient perioperative pathway                  | No patient surface                                | `PATIENT`                                | Patient P2: preparation, stage, expected next milestone, recovery/discharge education |

### 5.5 Radiology, laboratory, and pharmacy

| Zephyrus capability                                                              | Current Hummingbird delta                                                                                 | Target                                                   | Priority / acceptance                                                                                                                |
| -------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Radiology flow board/worklist/modality/reads/results/TAT                         | No dedicated staff package                                                                                | `GLANCE` + `NATIVE` exception actions for relevant roles | P2: modality delay, patient readiness, transport dependency, result-release boundary                                                 |
| Laboratory flow/specimens/pending decisions/blood bank/AP/TAT                    | No dedicated staff package                                                                                | `GLANCE` + `NATIVE` exception actions                    | P2: specimen state, recollect, critical escalation, no unauthorized result content                                                   |
| Pharmacy flow/discharge meds/IV room/dispense/delivery/controlled substances/TAT | No dedicated staff package                                                                                | `GLANCE` + `NATIVE` exception actions                    | P2: discharge-med readiness, delivery dependency, controlled-substance restrictions                                                  |
| Patient test/procedure progress                                                  | Governed, released test/procedure/transport pathway-event categories now render in both patient timelines | `PATIENT`                                                | Patient P2: approved source adapters; scheduled/in progress/completed/result-pending/released semantics; no premature interpretation |
| Patient medication education                                                     | No patient surface                                                                                        | `PATIENT`                                                | Patient P3: released medication list, purpose, teach-back, questions; source and pharmacist review                                   |

### 5.6 Transport, EVS, staffing, and support operations

| Zephyrus capability                                                   | Current Hummingbird delta                          | Target                              | Priority / acceptance                                                                               |
| --------------------------------------------------------------------- | -------------------------------------------------- | ----------------------------------- | --------------------------------------------------------------------------------------------------- |
| Transport queue/status/handoff                                        | Strong current slice                               | `NATIVE`                            | P0: lifecycle exhaustiveness, pagination, reassignment, cancellation, exception handling            |
| Transport dispatch/inpatient/transfers/discharge/EMS/care transitions | Current queue does not cover full product taxonomy | `NATIVE` or `GLANCE` by worker role | P1: one canonical lifecycle and subtype-specific fields                                             |
| Transport resources/analytics                                         | Absent                                             | `GLANCE` + `DEEPLINK`               | P2: worker availability and exception summary; analysis stays web                                   |
| EVS claim/start/complete                                              | Implemented                                        | `NATIVE`                            | P0: validate allowed states and concurrency                                                         |
| EVS assign/cancel/resources/exceptions                                | Not complete in BFF                                | `NATIVE`                            | P1: supervisor and technician flows separated by capability                                         |
| Staffing overview/candidates/fill                                     | Implemented                                        | `NATIVE`                            | P0 hardening                                                                                        |
| Staffing request create/assign/status/cancel                          | Absent from mobile                                 | `NATIVE` for coordinators           | P1: canonical fulfillment service only; never parallel state machines                               |
| Staffing source/import/rules/admin                                    | Web administration                                 | `DESKTOP_ONLY`                      | P0: explicit exclusion; alerts can deep-link to web                                                 |
| Patient transport visibility                                          | No patient surface                                 | `PATIENT`                           | Patient P2: “transport requested/on the way/arrived” without worker tracking or internal queue rank |

### 5.7 Analytics, Arena, improvement, and predictions

| Zephyrus capability                                        | Current Hummingbird delta           | Target                                     | Priority / acceptance                                                  |
| ---------------------------------------------------------- | ----------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------- |
| Live operational analytics                                 | Executive/Flow slices exist         | `GLANCE`                                   | P2: persona-curated measures with freshness and definitions            |
| Retrospective analytics                                    | Absent                              | `DEEPLINK`                                 | P3: authenticated context handoff                                      |
| Process intelligence / Arena maps, conformance, Petri nets | Absent                              | `DESKTOP_ONLY` + exception `NOTIFY`        | P3: mobile shows findings/actions, not authoring canvas                |
| Arena review / copilot drafts                              | Absent                              | `NOTIFY` + `DEEPLINK`                      | P3: mobile approval only after governed action contract exists         |
| Opportunity workbench                                      | Improvement opportunities read-only | `GLANCE` + `DEEPLINK`                      | P2: ownership and status summary                                       |
| PDSA                                                       | Read-only list                      | `NATIVE` lightweight updates               | P2: update check-in, measure, note, evidence; full authoring stays web |
| Root cause/process/bottleneck analysis                     | Absent                              | `DEEPLINK`                                 | P3                                                                     |
| Predictive workbench                                       | Absent                              | `DESKTOP_ONLY`                             | P3: model governance and authoring remain web                          |
| Data quality                                               | Absent                              | `NOTIFY` + `GLANCE` for accountable owners | P2: issue severity, impacted domain, owner, remediation link           |

### 5.8 Home Hospital, administration, deployment, and integrations

| Zephyrus capability                                          | Current Hummingbird delta | Target                                             | Priority / acceptance                                               |
| ------------------------------------------------------------ | ------------------------- | -------------------------------------------------- | ------------------------------------------------------------------- |
| Home Hospital command/census/referrals/transitions/logistics | No mobile package         | `NATIVE` for field workflows; `GLANCE` for command | P3: separate role/scopes and offline requirements                   |
| User/access/role/identity-provider administration            | No mobile package         | `DESKTOP_ONLY`                                     | P0 explicit exclusion                                               |
| System health                                                | No mobile package         | `NOTIFY` + `GLANCE` for authorized technical roles | P3: no secrets/configuration values                                 |
| Data protection/governance                                   | No mobile package         | `DESKTOP_ONLY`; selected approval `NOTIFY`         | P3: governed changes remain web until purpose-built flow exists     |
| Enterprise/deployment setup                                  | No mobile package         | `DESKTOP_ONLY`                                     | P0 explicit exclusion                                               |
| Integration control plane and credentials                    | No mobile package         | `DESKTOP_ONLY`; health incident `NOTIFY`           | P3: never expose credentials or network configuration in native app |

---

## 6. Detailed staff parity backlog

### 6.1 Parity control plane

- [x] Create `docs/hummingbird/capability-ledger.v1.yaml` with stable capability IDs, domain, web route/API, user roles, data classification, disposition, mobile endpoint, iOS route, Android route, notification event, offline class, owner, and verification evidence. _(2026-07-22: 51 capability records, including the feature-gated synthetic care-pathway journey demo.)_
- [x] Seed the ledger from `navigationConfig.ts`, `routes/web.php`, and `routes/api.php`; review manually for non-navigation capabilities and background jobs. _(2026-07-22: validator proves single ownership for 100 navigation routes and 58 staff mobile/auth operations; non-navigation foundations and all nine staff communication operations are included.)_
- [x] Add CI that fails when a new navigation capability or staff mobile/auth API operation lacks a ledger record. _(2026-07-19: `scripts/verify-hummingbird-capability-ledger.php` runs in backend quality CI.)_
- [ ] Extend the same ownership gate to non-mobile named API domains and background-job capabilities.
- [x] Add CI that fails when a `NATIVE` capability has only one native platform route. _(2026-07-19: planned capabilities may have neither platform; one-platform drift fails.)_
- [x] Add CI that fails when an OpenAPI operation is missing an authorization, data-classification, idempotency, and error-behavior extension. _(2026-07-23: all 58 registered staff mobile/auth operations now carry the four governed extensions. `verify-hummingbird-staff-contract.php` checks every operation, validates extension structure and documented error coverage, compares the complete contract inventory with Laravel, and reconciles `mobile:read`/`mobile:act` metadata with actual middleware. CI also runs a negative self-test that removes each extension in turn and proves the gate fails.)_
- [x] Add a generated Markdown report that shows domain coverage and unresolved dispositions without treating route count as parity percentage. _(2026-07-19: `generated/capability-coverage.md`, with a deterministic CI freshness check.)_
- [ ] Assign one accountable product owner and one engineering owner per capability.
- [x] Require a deprecation record before deleting or remapping a capability ID. _(2026-07-20: added the append-only `docs/hummingbird/capability-registry.lock` (every capability id ever created) and `docs/hummingbird/capability-deprecations.v1.yaml` (retirement records: id, retired_on, reason, replaced_by, migration_note). `verify-hummingbird-capability-ledger.php` now fails CI when an active id is missing from the lock, when a deprecation record is malformed or points at an unknown successor, when an id is both active and deprecated, or—critically—when a registered id is neither active nor deprecated, i.e. a capability was deleted or remapped without a deprecation record. Verified: the extended gate passes green (50 ids, 0 deprecations) and a simulated silent deletion fails with the exact remediation message.)_

### 6.2 Contract and client generation

- [ ] Make `hummingbird-bff.v1.yaml` the published interface contract and Laravel route tests the executable enforcement boundary.
- [ ] Add schemas for standard success/error envelopes, pagination, cursor expiry, stale-version conflict, idempotency replay, authorization denial reason class, and data freshness.
- [ ] Add `ETag`/`If-None-Match` or version cursor behavior to high-volume read surfaces.
- [x] Define one ISO-8601/time-zone rule and test DST boundaries. _(2026-07-20: the single rule—every wire timestamp is UTC ISO-8601/RFC 3339 with a literal `Z`, app runs `APP_TIMEZONE=UTC`, serialize via Carbon `->toISOString()`—is documented in `docs/hummingbird/api-contract/README.md` and guarded by `tests/Feature/Patient/PatientTimestampContractTest.php` (3 tests/12 assertions): envelope timestamps match the strict UTC-`Z` pattern with no offset, US/Eastern fall-back and spring-forward wall clocks each resolve to one unambiguous UTC instant, and a clock frozen on a DST boundary still emits the unshifted UTC value.)_
- [ ] Generate Swift `Codable` and Kotlin serialization models, or generate a shared KMP model artifact.
- [ ] Keep view models and platform navigation native; share/generate contracts, validation, status rules, and sync policy.
- [x] Add a breaking-change checker against the last released contract. _(2026-07-20: `scripts/verify-hummingbird-contract-baseline.php` (CI-wired) diffs both OpenAPI specs against the append-only `docs/hummingbird/api-contract/contract-operations.lock` (77 seeded operations). A baselined operation may only disappear with a record in `contract-breaking-changes.v1.yaml`; a new operation must be appended to the lock. Verified: green at 77 ops, and a simulated removal fails with the exact remediation message.)_
- [ ] Regenerate shared JSON fixtures from server factories and decode them in PHP, Swift, and Kotlin CI.
- [ ] Include nullability, unknown-enum, additive-field, precision, and large-payload fixtures.

### 6.3 Authentication, authorization, and session lifecycle

- [x] Implement single-flight refresh on 401/near-expiry in iOS and Android. _(2026-07-23: process-wide iOS actor and Android synchronized coordinator; proactive 120-second lead; one GET replay; mutation non-replay; protected complete-pair persistence; terminal invalidation; focused concurrency plus full simulator/emulator evidence in §3.2.5.)_
- [x] Rotate refresh tokens on use; revoke the predecessor; detect reuse; revoke the token family on suspected theft. _(2026-07-23: stable server-side family rows, absolute expiry, fixed-order transactional token/family locking, ability-less predecessor hash tombstones, generic public failure with specific server audit, compromised-family-only revocation, legacy-token adoption, bounded hash pruning, account-lifecycle reconciliation, isolated PostgreSQL feature coverage, and iOS/Android terminal-reuse behavior are verified; see §3.2.5.)_
- [x] Implement native Android password-change completion. _(2026-07-19: native UI, in-memory challenge handling, fail-safe token persistence, server-side transactionality, backend feature coverage, and Android emulator completion are verified.)_
- [x] Enforce protected storage without plaintext fallback. _(2026-07-19: iOS remains device-only Keychain; Android plaintext fallback removed and secure-store failure is fail-closed.)_
- [ ] Bind device registration to current user, app, platform, environment, installation ID, token fingerprint, and last-seen time.
- [ ] Revoke device tokens on logout, account disablement, role removal, facility removal, and app uninstall feedback.
- [ ] Re-evaluate capabilities and unit/facility assignments on every request; do not rely only on token claims.
- [ ] Add a session-management screen for the user to view and revoke their own devices.
- [ ] Define background timeout, biometric grace period, reauthentication for high-risk actions, and screenshot/privacy-cover behavior.

### 6.4 Notifications and realtime

- [ ] Complete FCM SDK configuration, permission UX, token registration/rotation, delivery sender, and receipt telemetry.
- [ ] Verify APNs token environments, payload size, collapse identifiers, interruption levels, and production certificates/keys.
- [x] Define T1–T4 urgency in one server-owned registry with allowed event types, personas, copy class, sound/haptic policy, acknowledgement, expiry, collapse, quiet hours, and escalation. _(2026-07-20: `config/hummingbird-notifications.php` is the single server-owned registry — exactly T1–T4, each with iOS interruption level, Android importance, sound/haptic, requires-ack + ack timeout, escalation, expiry, collapse strategy, quiet-hours exemption, and a PHI-free copy class — plus an event→tier map (personas resolve via `PersonaRelayPolicy`) and facility quiet hours. `NotificationUrgencyRegistry` reads/validates it; `NotificationUrgencyRegistryTest` (3 tests/41 assertions) proves the tiers, escalation targets, PHI-free copy, and that every mapped event is a recognized relay type. Backend-only; native rendering is the client half.)_
- [ ] Use generic lock-screen copy by default; fetch authorized details after unlock.
- [ ] Add notification preference and mandatory-safety-event policy.
- [ ] Add deep-link contract tests for cold start, warm start, expired auth, changed role, deleted item, and unauthorized item.
- [ ] Add delivery, open, action, acknowledgement, suppression, escalation, duplication, and stale-notification metrics.
- [ ] Make Reverb reconnect bounded with jitter, token/role change resubscription, foreground-only lifecycle, and poll fallback.

### 6.5 Offline and conflict behavior

- [x] Classify each capability as `NO_CACHE`, `ENCRYPTED_READ_CACHE`, or `READ_CACHE_AND_OUTBOX`. _(2026-07-20: every capability in `capability-ledger.v1.yaml` carries an `offline_class` field constrained to exactly these three values by `verify-hummingbird-capability-ledger.php` (currently 27 NO_CACHE / 16 ENCRYPTED_READ_CACHE / 7 READ_CACHE_AND_OUTBOX). Client cache/outbox implementation is the native half.)_
- [x] Default all patient context and sensitive notes to `NO_CACHE` until threat-model approval. _(2026-07-20: `verify-hummingbird-capability-ledger.php` now fails CI if any patient-facing capability (PATIENT disposition or any `patient_api_operations`) has an `offline_class` other than `NO_CACHE`; `patient.experience` (19 patient ops) is `NO_CACHE` and the invariant is now enforced.)_
- [ ] Display `last_updated_at`, source freshness, and offline status on every cached clinical/operational screen.
- [ ] Persist mutation intent, payload hash, base version, idempotency key, actor, target, creation time, and expiry.
- [ ] Revalidate authorization and target state at replay; never assume pre-offline permission still applies.
- [ ] Define conflict UX: refresh and review, safely merge, withdraw local intent, or escalate—never silent last-write-wins.
- [ ] Expire unsafe pending actions after a domain-specific TTL.
- [ ] Encrypt caches with per-install keys and wipe on logout, user switch, environment switch, app-integrity failure, or remote revocation.

### 6.6 Native validation

- [ ] Create an iOS XCTest target for models, auth, cache, routing, role homes, and mutation state machines.
- [ ] Add iOS XCUITest journeys for all 17 roles using deterministic server fixtures.
- [ ] Expand Android unit tests to DTOs, auth refresh, role routing, every action transition, cache/outbox, and error mapping.
- [ ] Add Android instrumentation/Compose tests for all 17 roles.
- [ ] Add accessibility identifiers/test tags from the capability ledger.
- [ ] Test screen reader order, focus, actions, content descriptions, Dynamic Type/font scale to 200%, display zoom, contrast, reduced motion, and non-color status.
- [ ] Test minimum supported OS versions, low-memory termination, process restore, clock skew, DST, background/foreground, airplane mode, slow network, packet loss, duplicate response, and server schema additive change.
- [ ] Maintain screenshot baselines for dark/light, smallest supported phone, largest font, and representative localization expansion.

---

## 7. Hummingbird Patient product definition

### 7.1 Product promise

An inpatient or authorized representative can answer, at any time:

1. Where am I in my care pathway?
2. What has happened, what is expected next, and what is uncertain?
3. Who is on my care team, what does each person or service do, and who is currently responsible?
4. What are today's goals, tests, treatments, mobility/nutrition plans, and discharge criteria in language I can understand?
5. What questions have I asked, who is responding, and when should I use a more urgent channel?
6. What do I need to understand or do before leaving the hospital?
7. Which family member, caregiver, interpreter, or representative can participate, and what may they see or do?

### 7.2 Recommended information architecture

| Tab           | Patient purpose        | Core content                                                                                                            |
| ------------- | ---------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| **Today**     | Immediate orientation  | Current stage/location, care team on duty, today's goals, next expected milestones, new updates, unanswered questions   |
| **My Path**   | Understand the journey | Admission-to-discharge stages, completed/current/planned milestones, delays/uncertainty, education, discharge readiness |
| **Care Team** | Know who is helping    | Team/service directory, roles, responsibility, availability language, interpreter/care-manager links                    |
| **Messages**  | Communicate safely     | Threads by encounter/topic, question routing, acknowledgements, responses, attachments, urgent-use guidance             |
| **More**      | Preferences and access | Language/accessibility, proxy access, consent, notification preferences, documents, privacy, help, sign out             |

Do not put an enterprise dashboard, operational Flow map, staff priority score, staffing strain, raw forecast, other-patient information, or staff-only Eddy recommendation in this product.

### 7.3 Calming visual system and Hummingbird imagery

The patient product should feel reassuring without becoming juvenile, visually noisy, or falsely therapeutic. Use the existing reviewed photography in `public/images/auth/hummingbirds/` as app-bundled, screen-specific atmosphere. Do not download backgrounds at runtime, rotate them unexpectedly, or make clinical content depend on the image.

- [x] Use a stable Hummingbird background treatment on welcome/enrollment, sign-in, Today, My Path, Care Team, Messages, account preferences, loading, empty, unavailable, and recoverable-error states on both native platforms. _(2026-07-22: iOS maps nine named scenes to four bundled assets; account preferences deliberately reuse its Calm Green account/session scene. Android maps welcome, Today, My Path, Care Team, Messages, and loading/empty/unavailable/error states to four bundled assets; account preferences deliberately reuse the low-intensity Calm Green treatment. Android's dedicated Messages destination intentionally reuses the calm Care Connection image rather than introducing a visually noisy fifth treatment.)_
- [x] Select calmer, naturally lit images for primary patient screens; reserve high-contrast or visually intense images for contexts where readability and emotional-safety review explicitly approve them. _(2026-07-21: the reviewed `hummingbird-01`, `-06`, `-07`, and `-12` derivatives have explicit scene assignments and no rotating/remote selection.)_
- [x] Place content on an adaptive opaque or translucent surface with a deterministic gradient/scrim; verify text, controls, focus indicators, and status chips against the actual brightest and darkest crops. _(2026-07-22: iOS uses system-background scrims at 68%→92%, increasing to 93%→98% for system increased contrast and to an opaque canvas for the patient-selected high-contrast mode; cards gain a two-point border. Android uses a 46%-alpha image with a 68%→84%→96% Material-surface gradient at default scale, 16%/88%→94%→99% at 130%+ text, and a 0%-alpha image with an opaque high-contrast surface. The focused iPhone 17 Pro screenshot visually confirms the Extra Large/high-contrast Today view.)_
- [x] Keep all imagery decorative for assistive technology unless a specific image has approved informational alt text; navigation, state, and safety instructions must remain complete with images hidden or disabled. _(2026-07-21: SwiftUI backgrounds are `accessibilityHidden`; Android uses `contentDescription = null`; native journey tests exercise the semantic content.)_
- [ ] Respect Reduce Motion, Reduce Transparency, increased contrast, dark appearance, screen dimming, Dynamic Type/font scale, display zoom, and platform accessibility settings without removing information or actions. _(2026-07-22 partial: both native account menus expose the governed `text_size`, `reduced_motion`, `high_contrast`, notification-preview, and preferred-delivery choices through patient-readable Hummingbird-background screens. The synthetic iOS and Android paths confirm the controls never write an account; live calls use only `PUT /api/patient/v1/me/preferences`, and the UI states that the choices cannot change clinical care or urgent-help guidance. Saved choices now affect the patient care view: iOS establishes a Dynamic Type minimum of `.large`, `.xLarge`, or `.accessibility1` while preserving any stronger system size through `.accessibility5`, removes scenery and strengthens card borders for patient-selected high contrast, and suppresses optional privacy/loading motion when either system Reduce Motion or the saved choice is active. Android supplies the maximum of the system font scale and account floor (1.0/1.15/1.30) to the full Compose tree; patient-selected high contrast selects a black/white Material scheme and removes the decorative image, while the experience remains intentionally static rather than introducing optional scenic motion. Both patient apps show an in-care-view “Your reading preferences” notice that labels the active display choices and repeats their nonclinical boundary. Focused iPhone 17 Pro unit/UI tests and an API 35 Android emulator journey pass; the iOS Extra Large/high-contrast screenshot was visually reviewed. Full system-setting combinations, dark/display-zoom matrix, TalkBack/VoiceOver order, localization, and formal contrast evidence remain before this cross-platform requirement can close.)_
- [x] Avoid auto-advancing carousels, parallax, continuous wing animation, flashing, or motion that may cause fatigue. Any optional transition must stop under reduced motion. _(2026-07-21: all imagery is static; the iOS loading transition switches to opacity-only under Reduce Motion.)_
- [x] Ensure every loading/empty/error treatment distinguishes decorative warmth from actual freshness, uncertainty, offline, unavailable, or safety status. _(2026-07-21: the iOS UI suite covers safe unavailable/error/privacy states; Android's API 35 test covers explicit loading, empty, unavailable, and recoverable-error copy.)_
- [ ] Bundle optimized app-local derivatives with no network dependency; document source, crop, asset checksum, licensing/attribution review, and release ownership. _(Optimized local derivatives, checksums, source/crop records, and rendering rules are in the platform provenance documents; licensing/attribution approval and release ownership remain open.)_
- [ ] Capture and review iOS Simulator and Android Emulator evidence for each primary state at default and large text, in light/dark or approved fixed appearance, with accessibility semantics and contrast checks. _(2026-07-22 partial: iOS default-size UI attachments for Today, My Path, Care Team, Messages, privacy cover, and safe error states were reviewed; a focused iPhone 17 Pro simulator journey now captures and visually confirms the patient-selected Extra Large/high-contrast Today state. The focused Android API 35 preference journey also saves Extra Large/high contrast, closes the preference surface, and asserts the active in-care preference notice. Complete light/dark, reduced-transparency/motion, screen-reader, and every-state evidence package remains required.)_

### 7.4 Primary patient journeys

#### Journey A: enrollment after admission

- [ ] Staff offers enrollment without making care contingent on adoption.
- [ ] Patient scans/enters a short-lived enrollment challenge tied to an active encounter.
- [ ] Identity proofing follows approved assurance level and uses an existing patient identity source where available.
- [ ] Patient reviews privacy, communication limitations, proxy choices, language, accessibility, and notification preferences.
- [ ] Server creates a patient principal and encounter access grant; no raw MRN is stored in the app.
- [ ] The app displays patient name/encounter confirmation sufficient to prevent wrong-patient enrollment without exposing unnecessary data.
- [ ] A revoked, discharged, transferred, merged, or corrected encounter is handled explicitly.

#### Journey B: morning orientation and rounds

- [ ] Today shows the date, care stage, care team, goals, expected rounds window if approved, tests/procedures, mobility/nutrition plan, and discharge focus.
- [ ] Patient can add a question before rounds and mark its topic/urgency class.
- [ ] The question appears in the authorized staff rounds workflow without becoming a clinical order.
- [ ] Staff may acknowledge, answer, defer, or route it; every state and timestamp is patient-visible.
- [ ] After rounds, the patient sees an approved plain-language summary and updated goals/tasks.
- [ ] Teach-back prompts record understanding or a request for clarification, not clinical consent unless a separate approved consent flow is used.

#### Journey C: test, procedure, or transport

- [ ] Patient sees preparation instructions only after source and release policy permit display.
- [ ] Status distinguishes scheduled, requested, waiting, transport requested, in progress, completed, result pending, and result released.
- [ ] Delays use plain language and avoid exposing staffing/capacity details or an unreliable exact ETA.
- [ ] A patient can ask a question routed to the responsible service.
- [ ] Results appear only according to the organization's legal and clinical release policy; the app does not generate independent interpretations.

#### Journey D: contacting the care team

- [ ] Patient chooses a topic or team pool, not necessarily an individual clinician.
- [ ] UI explains typical response expectations and the urgent bedside/emergency alternative.
- [ ] Message is scanned, persisted, audited, and routed based on encounter, unit, topic, availability, and handoff rules.
- [ ] Recipient pool acknowledges ownership; unowned messages escalate.
- [ ] Patient sees sent, delivered, assigned/acknowledged, responded, closed, and rerouted states.
- [ ] Shift change transfers pool responsibility without losing the thread.

#### Journey E: discharge preparation

- [ ] My Path shows discharge criteria, estimated date/range with uncertainty, unresolved needs, medications/education, equipment, transport, follow-up, warning signs, and who to contact.
- [ ] Patient/family goals and preferences are visible to the care team.
- [ ] Teach-back identifies areas needing clarification.
- [ ] Required clinical documents link to the designated source rather than being silently copied into a separate record.
- [ ] After discharge, encounter access transitions to the approved retention/portal mode and messaging rules change visibly.

#### Journey F: representative, caregiver, and interpreter

- [ ] Patient may invite or approve a representative only under approved identity, consent, relationship, and scope rules.
- [ ] Representative sees exactly which encounters/data/actions are granted and when access expires.
- [ ] Revocation is immediate and audited.
- [ ] Legal personal representatives and patient-designated delegates are modeled separately.
- [ ] Minor/adolescent, incapacitated adult, guardianship, sensitive-service, and state-law cases fail closed to a reviewed workflow.
- [ ] Interpreter access is task- and encounter-scoped; interpreters do not inherit family/proxy privileges.

---

## 8. Patient data disclosure and translation rules

### 8.1 Projection policy

Every patient-facing field must have:

- canonical source system and source identifier;
- patient-readable label and explanation;
- release rule and sensitivity class;
- permitted viewer relationship;
- freshness expectation and observed timestamp;
- state vocabulary and uncertainty behavior;
- correction/retraction behavior;
- provenance available to support and audit staff;
- translation ownership;
- offline/cache policy;
- notification eligibility.

### 8.2 Initial disclosure matrix

The machine-readable draft is
[`patient-disclosure-matrix.v1.yaml`](./patient-disclosure-matrix.v1.yaml). CI requires
every disclosure class to declare its source, release, relationship, freshness,
uncertainty, correction/retraction, translation, cache, notification, allowed-field, and
prohibited-field rules. Its governance status and every class remain explicitly pending;
technical completeness is not multidisciplinary approval.

| Information                    | Patient treatment                                               | Explicit exclusions / safeguards                                                                |
| ------------------------------ | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| Current encounter and location | Show patient-readable facility/unit/room if policy allows       | No bed-management priority, isolation detail without policy, or other-patient context           |
| Care stage                     | Show approved stage label and explanation                       | Do not derive diagnosis or prognosis from operational state                                     |
| Care team                      | Show names/roles/services/responsibility and safe contact route | No personal numbers, schedules, private comments, or credential detail not approved for display |
| Today's goals                  | Show patient-approved goals and care-plan goals                 | Separate clinical team goals from patient-authored preferences; show provenance                 |
| Rounds                         | Show expected window, submitted questions, and approved summary | No staff-only queue rank, raw contributions, disagreement notes, or other patients              |
| Tests/procedures               | Show preparation and operational milestone                      | Results and interpretation follow release policy; no fabricated ETA                             |
| Medications                    | Show only from approved medication/reconciliation source        | No operational pharmacy queue or unverified inferred indication                                 |
| Transport                      | Show patient-relevant state                                     | No worker location, internal dispatch rank, or other requests                                   |
| Discharge                      | Show criteria, readiness topics, EDD/range with uncertainty     | No raw barrier coding or blame-oriented ownership                                               |
| Messages                       | Show thread content intended for patient/representative         | Staff internal notes, routing metadata, and safety-review content remain separate               |
| Eddy explanation               | Later, only from released/approved content                      | No diagnosis, triage, medication change, clinical order, or autonomous action                   |

### 8.3 Language rules

- Use “planned” rather than “will happen” when scheduling is not confirmed.
- Use a time range or “timing may change” rather than an unsupported exact ETA.
- Explain acronyms and staff roles on first use.
- State why a milestone matters and what the patient can do now.
- Never infer causality from temporal sequence.
- Never turn operational risk scores into patient prognosis.
- Present delays without assigning blame.
- Preserve the clinical source text for staff audit while displaying an approved patient-facing rendering.
- Mark machine-translated or AI-simplified content and provide the approved human-language-assistance path.

---

## 9. Patient architecture

### 9.1 Product and deployment boundary

- New iOS application target/bundle identifier: Hummingbird Patient.
- New Android application/application ID or strictly separated product flavor with independent signing and storage.
- New URL/deep-link namespace and universal/app links.
- New push topic, device registry purpose, entitlements, app groups, Keychain service, encrypted database, analytics property, crash-reporting project, and feature flags.
- New `/api/patient/v1` route group, authentication guard, rate limits, policies, envelopes, OpenAPI contract, and audit channel.
- Shared design tokens and selected non-clinical primitives only. Staff/patient binaries must not accidentally link privileged staff routes or credentials.

### 9.2 Identity and access model

Create a patient-specific realm rather than reusing `prod.users` staff roles.

Proposed concepts:

| Concept                                | Purpose                                                                                     |
| -------------------------------------- | ------------------------------------------------------------------------------------------- |
| `patient_principals`                   | Authentication subject; no role in the staff persona catalog                                |
| `patient_identity_links`               | Governed mapping to enterprise patient identity with source/provenance and merge history    |
| `patient_encounter_grants`             | Principal may access a specific encounter for a defined purpose/window                      |
| `patient_representative_relationships` | Patient-designated, legal, guardian, caregiver, interpreter, or other approved relationship |
| `patient_delegation_scopes`            | Data/action/topic scope, effective/expiry dates, grantor, verification, revocation          |
| `patient_consents`                     | Versioned notice/consent/directive evidence; not a generic boolean                          |
| `patient_devices`                      | Patient-app installation and push registration, separate from staff devices                 |
| `patient_access_events`                | Authentication, enrollment, grant, revoke, disclosure, export, and sensitive-access audit   |

Required controls:

- [ ] Select NIST-aligned identity proofing, authentication, recovery, and federation assurance based on risk assessment.
- [ ] Prefer health-system portal/IdP federation when trustworthy and available; otherwise use short-lived encounter enrollment plus approved proofing.
- [ ] Support phishing-resistant MFA/passkeys where platform and IdP policy permit.
- [ ] Prevent account recovery from becoming a weaker identity-proofing bypass.
- [ ] Detect duplicate/merged patient identities and pause access during unresolved ambiguity.
- [ ] Require step-up authentication for proxy changes, exports, sensitive content, and device/session management.
- [ ] Evaluate relationships and grants on every request.
- [ ] Produce patient-visible access history where policy requires or product chooses.

### 9.3 Proposed data model

Use a new `patient_experience` schema (final name decided by ADR). Suggested tables:

#### Identity and governance

- `principals`
- `identity_links`
- `enrollment_challenges`
- `encounter_access_grants`
- `representative_relationships`
- `delegation_scopes`
- `consent_records`
- `preference_profiles`
- `device_registrations`
- `access_audit_events`

#### Patient pathway

- `pathway_definitions`
- `pathway_definition_versions`
- `pathway_instances`
- `pathway_stage_instances`
- `pathway_milestones`
- `pathway_status_events`
- `pathway_explanations`
- `patient_goals`
- `care_preferences`
- `education_assignments`
- `teach_back_responses`
- `discharge_readiness_items`

#### Care team and communication

- `care_team_projections`
- `care_team_members`
- `responsibility_pools`
- `responsibility_assignments`
- `message_threads`
- `thread_participants`
- `messages`
- `message_attachments`
- `message_delivery_receipts`
- `message_routing_events`
- `message_escalations`
- `patient_questions`
- `question_resolutions`

#### Projection and reliability

- `source_projection_cursors`
- `source_projection_failures`
- `release_policy_versions`
- `encounter_projections`
- `content_actions`
- `notification_outbox`

Data-model rules:

- [x] Use UUIDs externally; never expose MRN or internal sequence IDs. _(2026-07-19: principals, grants, encounters, challenges, sessions, audit events, and outbox records have external UUIDs; patient serializers expose only UUID handles, with negative leak assertions for source/internal fields.)_
- [x] Keep source identity links encrypted and tightly scoped. _(2026-07-19: source subjects and encounter references use encrypted model casts, keyed lookup digests, hidden attributes, separate patient tables, and patient-safe projection serializers.)_
- [x] Make messages append-only with correction/retraction events rather than destructive edits. _(2026-07-19: dedicated-key encrypted message content, delivery receipts, and routing events are model- and PostgreSQL-trigger-guarded immutable facts; a database relationship guard prevents correction/retraction links from crossing thread boundaries, and patient serialization excludes staff-internal visibility.)_
- [x] Version pathway definitions; never reinterpret historical events after a definition change. _(2026-07-22: immutable catalog `care_pathways.versions` now has a version-bound `stage_definitions` layer, while encounter-scoped `patient_experience.pathway_instances` pin an access grant to one exact approved, active catalog version. Stage and milestone instances must belong to that pinned version by database trigger; every status transition is a separate append-only, HMAC-digested observed fact, with current-status views derived from—not substituted for—the immutable history. The internal-only writer has no patient route or care-plan/order/consent mutation path and rejects ineffective grants, unapproved/inactive assignment versions, unapproved definitions, invalid state, and cross-version attachment. A future definition revision therefore changes no historical instance or event.)_
- [x] Keep patient-authored goals/preferences distinct from clinician-authored care-plan content. _(2026-07-22: default-off `care_preference` and `patient_goal` message topics can each atomically create one separate, append-only, content-free fact linked to the patient, active encounter grant, encrypted source message, policy version, and HMAC-protected replay/payload digests. Neither table stores patient text or writes to pathway, care-plan, goal, order, consent, assessment, or source-system tables; their direct-topic response exposes neither opaque fact UUID nor clinical/control fields. Exact replay returns the original message/fact, every other topic is excluded, and disabling either persistent-fact flag preserves the established encrypted-message workflow. `PatientCarePreferenceApiTest` and `PatientAuthoredGoalApiTest` exercise these boundaries.)_
- [x] Store provenance and release-policy version on every projection. _(2026-07-19: every immutable encounter projection requires a release-policy FK, source version, typed provenance, observed/generated/released timestamps, freshness, uncertainty, scope, relationship allowlist, schema version, and content digest; unsafe nested content is rejected before storage.)_
- [ ] Use transactional outbox for projection events, messages, push, and staff-inbox handoff. _(2026-07-22 partial: thread creation, patient follow-up, and closure atomically append a content-free `staff_inbox` outbox fact with the message/routing/audit transaction. Consumption is implemented with locked exact retry eligibility, `next_attempt_at` backoff, aggregate degraded heartbeat, and duplicate-failure suppression. A PostgreSQL `AFTER INSERT` trigger now atomically adds one deterministic, content-free `projection` outbox fact for every released patient projection; it contains only the projection UUID, grant principal, projection kind, schema version, and release time—never projection content, source identifiers, policy/routing decisions, or encryption material. The partial unique index prevents a duplicate release event, and a draft creates none. Projection/push workers, durable in-flight claim/lease, terminal/dead-letter recovery, and production runbooks remain.)_
- [x] Atomically enqueue each released patient projection. _(2026-07-22: `patient_released_projection_outbox` runs inside the projection insert transaction and derives a single content-free publication event from the already-stored projection UUID. It fails the projection insert closed if the grant has no principal, never sends a notification, and cannot release or serialize content by itself. `PatientProjectionOutboxTest` covers the six-projection reference journey, exact reprovisioning, and draft suppression.)_
- [ ] Define retention, legal hold, export, amendment, and deletion behavior by data class.

### 9.4 FHIR and source-system alignment

Map where appropriate, without forcing all operational workflow into FHIR:

| Patient feature               | FHIR R4 alignment                                                            | Zephyrus source                                           |
| ----------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------- |
| Care pathway                  | `CarePlan`, `Goal`, `Task`, `Provenance`                                     | Encounter, rounds, operational event projections          |
| Care team                     | `CareTeam`, `PractitionerRole`, `Organization`                               | Staff assignments, rounds participants, service ownership |
| Patient questions/messages    | `Communication`; optionally `QuestionnaireResponse` for structured check-ins | Patient experience messaging and rounds bridge            |
| Consent/proxy                 | `Consent`, `RelatedPerson` where supported                                   | Patient identity/relationship governance                  |
| Tests/procedures/appointments | `ServiceRequest`, `Procedure`, `DiagnosticReport`, `Appointment` as approved | Ancillary/perioperative sources and FHIR connector        |
| Education/teach-back          | `Task`, `QuestionnaireResponse`, `DocumentReference` where appropriate       | Patient experience education state                        |

- [x] Write an ADR for which FHIR resources are consumed, projected, or produced. _(2026-07-19: accepted resource-by-resource consumption/projection dispositions, raw-FHIR prohibition, and a no-write-back boundary.)_
- [x] Do not write back to an EHR until a governed integration, reconciliation, and human-review path exists. _(2026-07-19: the FHIR boundary ADR prohibits write-back; the patient API exposes no source-system mutation route and operates only on the isolated patient-experience realm.)_
- [ ] Use existing integration control-plane credential and conformance governance; do not embed EHR credentials in the app.
- [x] Preserve source resource version and provenance for correction/retraction. _(2026-07-19: projection rows are append-only and retain source version/provenance; correction/retraction is a unique immutable content action tied to the governing release-policy version and, for correction, a separately released superseding projection.)_

### 9.5 Initial patient API contract

All endpoints require patient authentication, active relationship/grant checks, purpose-of-use enforcement, rate limiting, audit, and a patient-specific OpenAPI contract.

#### Authentication and enrollment

- `POST /api/patient/v1/auth/enroll/challenge/verify`
- `POST /api/patient/v1/auth/token`
- `POST /api/patient/v1/auth/token/refresh`
- `POST /api/patient/v1/auth/token/revoke`
- `POST /api/patient/v1/auth/recovery/start`
- `POST /api/patient/v1/auth/recovery/complete`
- `GET /api/patient/v1/me`
- `PUT /api/patient/v1/me/preferences`
- `GET /api/patient/v1/me/sessions`
- `DELETE /api/patient/v1/me/sessions/{sessionUuid}`

#### Encounter and pathway

- `GET /api/patient/v1/encounters`
- `GET /api/patient/v1/encounters/{encounterUuid}/today`
- `GET /api/patient/v1/encounters/{encounterUuid}/pathway`
- `GET /api/patient/v1/encounters/{encounterUuid}/pathway/events`
- `GET /api/patient/v1/encounters/{encounterUuid}/goals`
- `POST /api/patient/v1/encounters/{encounterUuid}/goals`
- `PUT /api/patient/v1/encounters/{encounterUuid}/goals/{goalUuid}`
- `GET /api/patient/v1/encounters/{encounterUuid}/education`
- `POST /api/patient/v1/encounters/{encounterUuid}/education/{assignmentUuid}/teach-back`
- `GET /api/patient/v1/encounters/{encounterUuid}/discharge-readiness`

#### Care team and rounds

- `GET /api/patient/v1/encounters/{encounterUuid}/care-team`
- `GET /api/patient/v1/encounters/{encounterUuid}/rounds`
- `POST /api/patient/v1/encounters/{encounterUuid}/rounds/questions`
- `GET /api/patient/v1/encounters/{encounterUuid}/rounds/questions`
- `GET /api/patient/v1/encounters/{encounterUuid}/rounds/summaries`

#### Messaging

- [x] `GET /api/patient/v1/encounters/{encounterUuid}/message-topics`
- [x] `GET /api/patient/v1/encounters/{encounterUuid}/threads`
- [x] `POST /api/patient/v1/encounters/{encounterUuid}/threads`
- [x] `GET /api/patient/v1/threads/{threadUuid}`
- [x] `POST /api/patient/v1/threads/{threadUuid}/messages`
- [ ] `POST /api/patient/v1/threads/{threadUuid}/attachments`
- [x] `POST /api/patient/v1/threads/{threadUuid}/close`

The six checked operations are implemented behind a disabled feature flag and a second fail-closed local-policy gate. Fresh writes additionally require a healthy accountable handoff consumer and an encounter/topic-specific staffed-pool check under the locked grant. Exact create/send/close replay is evaluated before fresh-write readiness, while still requiring current grant and disclosure authority, so a committed result can be confirmed without creating a second fact during a stale heartbeat or transient write-path outage. Their existence is not approval to enable messaging: urgent-help language, response expectations, routing ownership, encryption keys, pilot units, responsibility pools, active responders, and a fresh worker heartbeat must all be explicitly governed before new content is accepted. Both patient apps consume all six operations; attachments remain intentionally absent.

#### Representatives and consent

- `GET /api/patient/v1/representatives`
- `POST /api/patient/v1/representatives/invitations`
- `GET /api/patient/v1/representatives/invitations/{token}/preview`
- `POST /api/patient/v1/representatives/invitations/{token}/accept`
- `PUT /api/patient/v1/representatives/{relationshipUuid}/scopes`
- `DELETE /api/patient/v1/representatives/{relationshipUuid}`
- `GET /api/patient/v1/consents`
- `POST /api/patient/v1/consents/{consentType}/decision`

#### Devices and notifications

- `POST /api/patient/v1/devices`
- `DELETE /api/patient/v1/devices/{deviceUuid}`
- `GET /api/patient/v1/notifications/preferences`
- `PUT /api/patient/v1/notifications/preferences`

Contract requirements:

- [x] Every response has `data`, `meta.request_id`, `meta.generated_at`, `meta.source_freshness`, and `meta.policy_version` where applicable. _(2026-07-19: the patient response decorator covers successes, feature-gate/auth/validation failures, and unhandled errors; seven route-boundary and lifecycle suites verify the stable patient-safe envelope.)_
- [ ] Every patient-facing state has a stable code and approved plain-language representation. _(2026-07-22 partial: `config/hummingbird-patient-content.php` now contains a versioned, default-English state registry, and `PatientProjectionContentGuard` rejects unknown schedule, pathway, milestone, event, goal, timing-confidence, discharge-criterion, rounds-topic, location, and contact-route codes before release. Both native apps map the currently accepted patient-visible state codes to matching context-specific English rather than title-casing an internal identifier; an unknown code falls back to “Status being confirmed.” Pathway-event category and governed urgent-contact wording now match across platforms, including the contract-only `call_button_for_urgent_help` value. Every patient envelope now has an additive `meta.state_vocabulary_version`, whose OpenAPI shape is checked in CI. An absent version remains compatible with an older server, but each native app withholds a projection that explicitly declares an incompatible version rather than render unapproved wording. The four-test, 12-assertion no-database registry/guard/metadata suite, iPhone 17 Pro unit/reference journey, and Android JVM/API 35 reference journey pass. This is a versioned English state-vocabulary foundation, not translated content, multidisciplinary content approval, or a complete patient-content registry.)_
- [ ] Every mutation accepts an idempotency key and returns replay metadata. _(Messaging create/send/close now require UUID replay keys, client message UUIDs where applicable, payload digests, transaction-scoped concurrency serialization, exact replay metadata, and conflict on key reuse with changed content; existing auth/profile mutations still require separate disposition.)_
- [x] Relationship denial returns a generic response that does not reveal encounter existence. _(2026-07-19: cross-principal, unknown encounter, missing scope, disallowed relationship, inactive grant, unavailable policy, unreleased content, and retracted content collapse to the same audited patient-safe `404`; focused IDOR tests pass.)_
- [x] Paging cursors are opaque, scoped, short-lived, and non-transferable. _(2026-07-20: the patient API exposes no pagination today (encounter-scoped, bounded lists). `verify-hummingbird-patient-contract.php` now fails CI if any patient operation declares an `offset`/`page`/`limit`/`per_page` query parameter, and permits a `cursor` parameter only when marked `x-zephyrus-cursor: opaque_scoped_short_lived` — so any future paging must be an opaque, scoped, short-lived cursor rather than offset/page.)_

### 9.6 Secure care-team communication

#### Routing model

- Topic examples: nursing need, medication question, test/procedure question, discharge planning, therapy/mobility, nutrition, interpreter, care coordination, technical help.
- Each topic resolves to an encounter-scoped responsibility pool using facility/unit/service rules.
- The routing engine considers current shift assignment, service ownership, away/handoff state, escalation timer, language need, and patient/representative scope.
- Individuals can be displayed when appropriate, but assignment stays to a durable pool until an accountable recipient accepts it.

#### Staff experience required

- [ ] Add a Zephyrus web patient-communications inbox with facility/unit/service filters. _(2026-07-20 partial: the capability-gated web workspace now provides content-free bootstrap, explicit no-cache detail retrieval, all nine inbox/detail/candidate/mutation operations, exact replay, authorization-loss and ambiguous-reroute privacy clearing, and unit/team filters. Facility and service-line filters, push, pilot telemetry, and deployed E2E remain.)_
- [ ] Add Hummingbird Staff For You items for new, aging, escalated, and reassigned patient messages. _(2026-07-20 partial: both native apps accept only the exact restricted card type/domain/canonical-ID tuple, replace server copy with fixed non-PHI text, expose bounded governed unit context, navigate only to the dedicated authorized detail route, and reconcile manual release/reassign/reroute access changes. New/unassigned, assigned-to-me, aging, and escalated states are represented; event-driven push and pilot telemetry remain.)_
- [x] Add mobile thread read/reply/route/close actions for authorized staff. _(2026-07-21: the nine-operation BFF and both native clients implement inbox/detail/route-candidates/claim/reply/close/release/reassign/reroute with capability, membership, destination-responder, current-scope, no-store, exact-replay, optimistic-version, omission/access-loss/retained-transition purge, foreground lifecycle/lock polling gates, and no-blind-retry controls. Automated shift/unit/discharge/pool-downtime reconciliation is local-only behind feature gates; authoritative service-change routing and deployed E2E remain separate backlog.)_
- [x] Keep staff internal notes separate from patient-visible replies. _(2026-07-19: canonical message visibility is enforced in storage; patient serialization excludes `staff_internal`; staff replies created by the response API are explicitly `patient_visible`; routing/outbox/audit projections remain content-free.)_
- [x] Require a close reason and optionally a patient-facing resolution summary. _(2026-07-19: staff close requires an allowlisted internal reason and a patient-visible staff response newer than the latest patient message; the patient receives the safe `question_answered` resolution state. A separately authored resolution-summary field remains optional and unimplemented.)_
- [ ] Preserve the thread across handoffs; log every routing decision. _(The current aggregate remains stable through pool projection, claim, patient follow-up, reply, escalation, close, release, reassign, and cross-pool reroute, and each implemented transition appends immutable routing/audit facts. Automated shift handoff, unit transfer, discharge, and pool-downtime transitions are implemented locally behind feature gates; an authoritative service-ownership feed, service-change routing, and deployed end-to-end evidence remain.)_
- [ ] Add canned responses only if approved, labeled, editable, and audited.

#### Implemented disabled foundation

- [x] Persist encounter-scoped threads with opaque UUIDs, patient-visible messages encrypted by a dedicated versioned key ring independent of `APP_KEY`, explicit topic/response-window snapshots, optimistic thread versions, and patient close reasons.
- [x] Persist immutable delivery receipts and immutable routing decisions while keeping the mutable thread row limited to a versioned current-state projection.
- [x] Require active encounter ownership plus `messaging:read`/`messaging:write` scope on every request; return the same audited `404` for cross-principal, revoked, expired, missing-scope, and unknown resources.
- [x] Make thread create, message send, and close exact-replay-safe using required UUID keys, encounter-bound/client-message-bound keyed payload digests, PostgreSQL transaction advisory locks, locked thread/grant rows, unique constraints, and optimistic version conflicts.
- [x] Commit each accepted patient mutation with a content-free transactional handoff event and patient-realm audit; include only opaque aggregate/routing-policy/responsibility-pool handles, and roll back message, receipt, route, handoff, and audit together if the handoff fact cannot commit.
- [x] Require a separately approved local communication policy and a configured ready handoff consumer for fresh writes even when the feature flag is enabled; missing urgent-help copy/version, response window, dedicated encryption key/version, routing topic, policy version, consumer contract, or consumer readiness returns a patient-safe `503` and stores nothing. Exact create/send/close replay bypasses fresh-write readiness only after current grant/disclosure authorization and an immutable event-digest match prove the original commit.
- [x] Build the responsibility-pool resolver, accountable assignment/acknowledgement, response, latest-response-aware closure, and SLA escalation operations consumed by staff workflows. _(2026-07-20: the shared resolver binds exact policy/topic/digest/current active encounter scope, uses unit → matching facility → enterprise precedence, rejects ambiguity and facility mismatch, requires an effective reply-enabled active capable responder, and never substitutes an established pool. The bridge projects content-free work, resets response timers on patient follow-up, and appends patient-visible acknowledgement/response/escalation facts.)_
- [x] Complete manual release, supervisor reassign, and cross-pool reroute across the BFF, Zephyrus web, iOS, and Android with immutable events, exact replay, source/destination authorization, current-grant scope, and possible-reroute privacy revocation.
- [x] Complete automated shift handoff, unit transfer, discharge, and downtime-recovery operations. _(2026-07-21: the feature-gated `PatientCommunicationLifecycleReconciliationService`, run by the every-minute `onOneServer`/`withoutOverlapping` `hummingbird:reconcile-patient-communications --once` schedule, reconciles open work against canonical encounter facts under the staff-mutation lock order: discharge closes both projections (`encounter_discharged`), unit transfer reroutes in place (`encounter_unit_transferred`), an ended assignee with an eligible pool backup releases to the pool (`assigned_responder_unavailable`), a shift-coverage gap escalates to the staffed facility/enterprise fallback (`responder_coverage_changed`), and pool downtime reroutes to an unambiguous fallback (`responsibility_pool_unavailable`). Every transition preserves thread/work/grant identity, appends the content-free immutable staff/routing/receipt facts exactly once, and never silently substitutes a pool or closes a thread when no eligible destination exists—it degrades to `unresolved`. Ten reconciliation tests (148 assertions) pass locally; service change remains deliberately unimplemented without an authoritative service-ownership input, features remain off, migrations local-only, and deployed E2E remains.)_
- [ ] Add approved attachment upload/scanning and retention controls.

#### Safety and abuse controls

- [x] Display the locally approved immediate-help instruction above compose and in every pending thread. _(2026-07-19: both patient apps render versioned server guidance above the list and compose/reply surfaces, distinguish nonurgent messaging from live chat, direct bedside needs to the call button/staff, and carry the displayed guidance version on create/send.)_
- [ ] Detect—not clinically diagnose—terms requiring urgent routing; show escalation UI and route to an approved human queue.
- [ ] Do not claim that keyword detection guarantees safety.
- [ ] Rate-limit spam while preserving access for distressed patients.
- [ ] Support abuse reporting, staff boundary policy, message blocking only through governed workflow, and security investigation.
- [ ] Allow only approved attachment types/sizes; malware scan, content-type verify, encrypt, and strip unsafe metadata.
- [ ] Never include message text or clinical details in lock-screen push by default.

### 9.7 Patient Eddy, if later approved

Patient Eddy is **not an MVP dependency**.

- [ ] Create a separate policy, prompt, retrieval index, telemetry stream, model configuration, and name/disclosure from staff Eddy.
- [ ] Retrieve only content released to the current patient/representative for the current encounter.
- [ ] Answer “what does this mean?” and “who should I ask?” using approved content and plain language.
- [ ] Cite the source screen/document and freshness in the answer.
- [ ] Refuse diagnosis, prognosis, triage, dose changes, treatment recommendations, or care-plan changes.
- [ ] Route unresolved or potentially urgent concerns to a human workflow.
- [ ] Never send a message, acknowledge education, alter a goal, or accept consent without explicit confirmation and allowed deterministic action.
- [ ] Run red-team tests for cross-patient retrieval, hidden staff notes, prompt injection in EHR text, sensitive-service leakage, hallucinated timing, and overconfident medical advice.

---

## 10. Security, privacy, accessibility, and safety requirements

### 10.1 Privacy and security

- [ ] Complete separate threat models for Staff and Patient, including lost device, shared device, malicious proxy, wrong-patient enrollment, account takeover, screenshot, notification disclosure, offline cache theft, rooted/jailbroken device, API scraping, attachment malware, and cross-product deep links.
- [ ] Complete data-flow diagrams and inventory every processor/SDK.
- [x] Do not include advertising pixels or unapproved tracking technologies in authenticated pages, login/enrollment, patient messaging, or patient content. _(2026-07-20: the invariant held in fact and is now codified — `scripts/check-no-tracking-technologies.sh` (CI-wired) fails if a known advertising/analytics SDK signature (Google Analytics/Tag Manager, DoubleClick, Meta Pixel, Segment, Mixpanel, Amplitude, Hotjar, FullStory, Heap, AppsFlyer, Branch, Firebase Analytics, react-ga/gtm) appears in `resources/js`, `resources/views`, either patient native app, or their dependency manifests. Current scan: zero matches.)_
- [ ] Use privacy-preserving first-party telemetry with no raw PHI, message text, patient identifiers, or free-text screen capture.
- [ ] Encrypt in transit and at rest; protect keys with platform hardware-backed stores where available.
- [ ] Add app attestation/integrity signals as risk input, not the sole access decision.
- [ ] Redact logs, crash breadcrumbs, analytics, push, widgets, backups, clipboard, recents/app switcher, and screenshots according to data class.
- [ ] Complete penetration test, mobile application security review, API authorization test, dependency/SBOM review, secrets scan, and third-party risk review before pilot.
- [ ] Rotate and invalidate every credential that was ever published in repository history, move replacement secrets to approved protected runtime storage, verify history/CI/artifact scanning, and retain only credential-free operating instructions. _(2026-07-19: known plaintext launch/database recipes were removed from the current devlog, but editing the current file does not revoke the exposed credential or erase repository history.)_
- [ ] Define breach/incident response, remote token revocation, forced upgrade, kill switch, and patient/support communication.

### 10.2 Accessibility and language access

- [ ] Adopt WCAG 2.2 AA as the cross-platform acceptance baseline, plus native iOS/Android guidance. _(2026-07-22 partial: `patient-accessibility-acceptance-matrix.v1.yaml` now turns the baseline into 12 explicit iOS/Android patient-product criteria, ties current source/test evidence to the controls that can be checked in CI, requires named human validation for each criterion, and keeps the matrix visibly draft and **not pilot-ready**. The matrix intentionally leaves screen-reader/focus, target-size, cognitive-readability, accessible-media, and language/interpreter validation open rather than treating static checks as an accessibility certification.)_
- [ ] Support screen readers, logical focus, headings, landmarks, rotor/navigation semantics, descriptive actions, and live-region restraint.
- [ ] Support text resizing without truncating meaning or hiding actions.
- [ ] Meet target-size and spacing expectations; do not depend on precision gestures.
- [ ] Provide non-color, non-motion status indicators and reduced-motion alternatives.
- [ ] Make authentication accessible without a cognitive-function test.
- [ ] Provide captions/transcripts and accessible alternatives for audio/video education.
- [ ] Establish professional translation, review, versioning, fallback, and interpreter workflows for each supported language.
- [ ] Do not use machine translation as the only path for critical clinical or discharge content.
- [ ] Test with patients with visual, hearing, motor, cognitive, literacy, and limited-English-proficiency needs.

### 10.3 Clinical and communication safety case

- [ ] Create a hazard log with severity, likelihood, detectability, control, verification, owner, and residual risk.
- [ ] Include wrong patient, wrong encounter, stale pathway, missed message, wrong team routing, premature result, misleading ETA, proxy oversharing, inaccessible urgent instruction, and AI fabrication hazards.
- [ ] Define patient-visible downtime and stale-data language.
- [ ] Add data-reconciliation jobs and alert on projection lag, orphaned messages, unowned threads, expired escalation, and release-policy mismatch.
- [ ] Run tabletop exercises for patient deterioration communicated through chat, patient transfer mid-thread, identity merge, proxy revocation, EHR outage, push outage, and incorrect published content.
- [ ] Require clinical safety, privacy, security, accessibility, legal, patient-experience, nursing, medical staff, pharmacy, interpreter services, health information management, and support sign-off.

---

## 11. Phased implementation plan

Phases are dependency gates, not calendar promises. A phase exits only when its evidence is complete.

### Phase 0 — Ratify scope, parity ledger, and safety boundaries

**Goal:** convert “parity” into an owned, testable contract and freeze unsafe assumptions.

- [ ] Approve the disposition vocabulary and this initial matrix.
- [x] Build `capability-ledger.v1.yaml` and its generated human-readable coverage report. _(2026-07-22: the checked ledger owns 51 capabilities, 100 navigation routes, 58 staff mobile/auth operations, all nine staff communication operations, and all 25 patient operations, including the two default-off notification-device operations, the source-bound education-clarification operation, and the feature-gated synthetic care-pathway journey demo.)_
- [ ] Obtain domain-owner and governance review of every ledger disposition, status, and named ownership assignment.
- [ ] Name domain owners and patient-product governance group.
- [x] Decide KMP vs generated native clients and record an ADR. _(2026-07-19: generated Swift/Kotlin contracts selected; KMP runtime deferred behind explicit revisit criteria.)_
- [x] Record the separate Patient product/API/identity boundary ADR. _(2026-07-19: separate binary, identity, API, storage, audit, push, cache, and release realms.)_
- [x] Record source-of-truth and patient projection ADR. _(2026-07-19: versioned released projection with provenance, freshness, uncertainty, correction, and retraction.)_
- [ ] Approve initial patient disclosure matrix and prohibited-data list.
- [x] Encode the initial patient disclosure matrix and prohibited-data list as a CI-validated draft. _(2026-07-19: 11 disclosure classes, 14 global prohibitions, and 3 relationship classes; governance approval remains pending.)_
- [ ] Approve urgent-message language and escalation policy.
- [ ] Decide portal/IdP federation and identity proofing approach.
- [ ] Define pilot facility, units, languages, patient cohorts, support hours, and exclusions.
- [x] Correct stale Hummingbird README/status documentation. _(2026-07-19: current implementation status, platform reality, execution-plan front door, ledger, and generated report are now explicit.)_

**Exit evidence**

- [ ] Zero unowned capabilities.
- [ ] Zero capabilities without a disposition.
- [ ] Governance sign-off on patient boundary and pilot constraints.
- [ ] Threat-model and hazard-log workshops scheduled with named owners.

### Phase 1 — Staff foundation and critical parity closure

**Goal:** make the existing staff product reliable before expanding scope.

- [x] Implement single-flight token refresh parity on both staff clients. _(2026-07-23: proactive near-expiry rotation, one-time GET replay after 401, mutation non-replay, protected complete-pair persistence/migration, terminal clearing, concurrency tests, full native regression, and iOS/Android emulator evidence are complete; see §3.2.5.)_
- [x] Complete server-side refresh-family rotation and theft response. _(2026-07-23: stable family identity, absolute lifetime, one-time predecessor tombstones, reuse detection with family-wide revocation, legacy upgrade, lifecycle reconciliation, generic client failure, specific audit evidence, and bounded hash pruning are implemented and verified; see §3.2.5.)_
- [x] Complete native password-change parity. _(2026-07-19: iOS behavior was retained; Android now completes the scoped challenge natively, verifies `/me` after replacement-token persistence, and has end-to-end emulator plus rollback evidence.)_
- [x] Remove insecure storage fallback. _(2026-07-19: Android encrypted preferences now fail closed; iOS uses device-only Keychain; Android debug/release tests and builds pass.)_
- [ ] Complete FCM end-to-end and harden APNs.
- [ ] Establish generated/shared contracts and three-language fixture CI.
- [x] Remediate staff A2P context-reference and active-encounter unit access. _(2026-07-19: exact opaque route grammar, raw-reference rejection, indexed/expiring/revocable mapping, active encounter unit derivation, and assigned-unit authorization verified in 23 tests/323 assertions.)_
- [ ] Complete Android Eddy chat/conversations/approvals.
- [ ] Add general encrypted read cache and safe mutation outbox framework.
- [ ] Complete core transport, EVS, staffing, RTDC, OR, and ops action lifecycle gaps.
- [x] Add iOS XCTest/UI-test and Android unit/instrumentation foundations. _(2026-07-19: both patient apps and the staff patient-communications slice now have deterministic contract, policy, navigation, privacy, and accessibility coverage; equivalent depth across older staff workflows remains backlog.)_
- [x] Add parity-ledger CI. _(2026-07-19: route/API ownership, native one-platform drift, referenced evidence paths, and generated-report freshness.)_

**Exit evidence**

- [ ] All existing mobile routes have iOS/Android semantic parity or approved platform exception.
- [ ] All current mobile writes pass replay, conflict, authorization-change, and audit tests.
- [ ] Push works end to end in production-like APNs and FCM environments.
- [x] No release build contains demo credentials or debug persona bypasses. _(2026-07-19: Android release DEX and installed-release hook injection plus iOS Release bundle scan/build verify exclusion; server-authorized admin/superuser navigation remains intentional product behavior.)_

### Phase 2 — Staff mobile capability expansion

**Goal:** close the highest-value Zephyrus operational gaps.

- [ ] Virtual rounds staff package.
- [ ] Global/unit/service huddle package.
- [ ] Ancillary milestone and exception cards.
- [ ] Full transport subtype/lifecycle disposition.
- [ ] OR status/delay/safety-action support.
- [ ] Staffing request and fulfillment lifecycle.
- [ ] Prediction cards with provenance/confidence/actionability.
- [ ] Data-quality and system-health exception notifications for authorized roles.
- [ ] Deep-link framework for dense analytics and administration.

**Exit evidence**

- [ ] Every P0/P1 ledger capability is verified or explicitly deferred with owner/risk.
- [ ] No mobile prediction omits model version, horizon, freshness, uncertainty, and escalation route.
- [ ] Rounds/huddles maintain authorization and do not leak whole-board PHI into notifications.

### Phase 3 — Patient platform foundation

**Goal:** establish identity, projection, policy, and a non-communicating read pilot.

- [x] Create patient schema, models, migrations, policies, audit, and transactional outbox. _(2026-07-19: the additive `patient_experience` identity foundation isolates principals, encrypted identity links, encounter grants, enrollment challenges, sessions, append-only access audit, and append-only notification outbox/delivery attempts; PostgreSQL triggers and model tests enforce its invariants; canonical remote migration batch 38 is verified.)_
- [x] Add a dry-run-first, idempotent synthetic reference-inpatient provisioner and verify it against the canonical backend. _(2026-07-20: `hummingbird:seed-reference-patient` owns exactly one active canonical row for encounter `10040`, unit `85`; the content-minimized readback returned `count=1`, `active_count=1`, `owned_count=1`, and `encounter_ids=[10040]`. No principal or enrollment secret was provisioned while the deployment HMAC boundary is unavailable.)_
- [x] Link a pending patient principal, verified encrypted identity link, pending encounter grant, and separate iOS/Android enrollment challenges to the synthetic encounter. _(2026-07-22: after the canonical migration-enabled production release, the deployed guarded command dry-ran and then committed exactly one command-owned pending principal, verified identity link, pending grant, and two redacted platform-specific challenges for encounter `10040` in unit `85`. It was executed without `--show-secrets`, and the reference-provisioning flag was restored to false immediately afterward. This establishes a controlled synthetic identity only; it does not activate access or enable a patient feature.)_
- [ ] Create and approve a patient projection for the synthetic encounter. _(No Today, My Path, Care Team, or other projection was created or released during the controlled identity exercise.)_

**Controlled deployment procedure — reference-patient identity (executed; enrollment/pilot remains pending):**

1. [x] Publish the reviewed, merged release through `./deploy.sh` from the canonical `/home/smudoshi/Github/Zephyrus` checkout only. The script refused the initial dirty checkout, then deployed the clean consolidated SHA `6121df89d7363e054bd9d7f21063f7e93f97997b`; no direct production SQL or substitute deployment mechanism was used.
2. [x] On the deployed runtime, verify the required migrations and guarded runtime prerequisites through the commands' fail-closed success paths. Do not copy `APP_KEY`, HMAC keys, or encryption material to a local checkout, shell history, ticket, or chat.
3. [x] Run the identity provisioner without `--commit`. It resolved only encounter `10040` and planned the one pending principal, verified identity link, pending access grant, and platform-specific challenges.
4. [x] Run the same command with `--commit` and without `--show-secrets`. Its result was redacted; the resulting source/link/grant relationship is command-owned and pending.
5. [ ] Deliver the two one-time enrollment challenges through the approved secure enrollment workflow, not terminal logs or chat. Verify activation, revocation, and redacted audit behavior through the deployed patient API before any clinical pilot.
6. [x] Disable the reference-provisioning flag immediately after the controlled exercise and retain only the required operational evidence. Patient product and messaging flags remain false.

- [x] Implement patient identity, enrollment, password exchange, rotating refresh/reuse detection, revoke, and durable session-family tracking behind disabled flags. _(2026-07-21: the separate patient realm uses exact token abilities, a required active backing session, protected challenge hashes, generic failures, atomic verification/consumption, session-family theft response, and audit; the complete Patient feature suite, including the later messaging and routing safety tranche, passes.)_
- [x] Add patient self-service session listing and revocation API. _(2026-07-19: fail-closed routes expose only the authenticated principal's active, PHI-free device/session metadata; owned-session revocation is idempotent and revokes the whole token family; cross-principal and unknown UUIDs are indistinguishable; current-session revocation is verified.)_
- [x] Add patient self-service session-management UI on iOS and Android. _(2026-07-19, reconfirmed 2026-07-21: both patient apps provide a scenic Manage Devices surface with bounded PHI-free inventory, explicit current/other-session confirmations, idempotent family revocation, current-session sign-out and full in-memory state clearing, no blind retry, disabled-route tolerance, lifecycle clearing, and native unit/UI/instrumentation coverage. iOS passes 52/52 unit and 6/6 UI tests plus Debug/Release and boundary/persistence scans; Android passes 82/82 Debug and 78/78 Release JVM tests, lint/assembly, boundary scans, and 13/13 API 35 tests.)_
- [ ] Add account recovery, portal/IdP federation, MFA/passkeys, and complete production device-management policy.
- [x] Implement encounter access grants and per-request relationship/scope/effective-window policies behind disabled flags. _(2026-07-19: patient-only grants use opaque encounter UUIDs, encrypted source links, active-window and revocation evaluation, exact read scopes, generic denials, and disclosure audit.)_
- [ ] Implement the representative/delegation relationship, consent, invitation, recovery, and sensitive-service review model.
- [x] Create `/api/patient/v1` OpenAPI and contract tests. _(2026-07-21: OpenAPI 3.1 owns all 25 patient operations exactly once, including eight disabled messaging operations; governed pathway-events, discharge-readiness, and feature-gated rounds-summary reads; two default-off notification-device operations; and the default-off, source-bound education-clarification route. The clarification request only permits an item in the patient's current released education projection and transmits no completion, comprehension, consent, or assessment field. The device `PUT`/`DELETE` routes require a patient access token and patient-owned opaque UUID, return no provider-token material, are no-store/idempotent/content-free audited, and have no native caller or provider delivery path yet. Route/spec, realm, ability, feature-gate, envelope, classification, audit, idempotency declaration, identifier, projection-schema, and error-behavior drift fail CI. The contract, ledger, and baseline scripts pass; the focused database feature suite is blocked locally before execution because the PHPUnit PostgreSQL maintenance service is unavailable.)_
- [x] Create the governed, versioned projection kernel and read-only Today, My Path, and Care Team endpoints. _(2026-07-19: append-only release policies, cursors, PHI-minimized failures, projections, unique correction/retraction actions, strict nested content schemas, generic IDOR behavior, transactional disclosure/audit locking, and stale/correction tests are green; canonical remote migration batch 39 is verified.)_
- [ ] Create Today, My Path, and Care Team projections from approved sources. _(2026-07-22 partial: `PatientPathwayHistoryDraftService` transforms only an effective-grant, version-pinned pathway instance, its active clinically signed-off catalog version, approved stage/milestone definitions, and latest append-only status observations into one content-guarded **draft** `pathway` projection. It HMAC-digests the protected cursor/trace, derives freshness from the latest observation, uses a current active patient release-policy version, and exact-replays unchanged history without duplicating the cursor or draft. The bounded `hummingbird:draft-patient-pathway-history --once` command dry-runs by default; its explicit `--commit` worker is scheduled every five minutes only after the independent `HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_DRAFTS_ENABLED`, patient product/pathway, and care-pathway patient gates are all enabled. A separate default-off `PatientPathwaySourceReconciliationService` now gives an approved connector a private, transactional handoff to that immutable history: its source allowlist is empty in code by default, it requires both patient and care-pathway assignment gates, accepts transient source references only to HMAC-digest them, pins the exact approved version/definition, exact-replays events, and treats an absent event as no change. `PatientPathwayProjectionReviewReleaseService` then requires an independent `ApproveCarePathwayClinical` reviewer and `ActivateCarePathwayCatalog` release manager before it appends one patient-visible release and the existing content-free publication outbox fact. It never exposes a route, makes a clinical assignment, or writes back to a source; source-contract approval, deployed connector wiring, review/release operation, and Today/Care Team producers remain.)_
- [x] Build separate iOS and Android patient application targets. _(2026-07-19: independent `net.acumenus.hummingbird.patient` applications now live in `hummingbird/iosPatientApp` and `hummingbird/androidPatientApp`; both default live API access off, use patient-only protected-storage namespaces, compile release variants, and pass native tests plus iOS Simulator and Android API 35 AVD journeys.)_
- [x] Apply the governed, calming Hummingbird-image background system across both patient apps. _(2026-07-22: the four reviewed repository images are bundled locally with deterministic scrims/fallbacks; iOS maps nine named scenes and Android maps its patient states without runtime download or motion. Saved patient reading preferences now preserve system-or-larger text, make high contrast an opaque content-first mode, and state the active nonclinical choice in the care view. Focused iPhone 17 Pro unit/UI tests plus visual screenshot review and the Android API 35 preference journey pass. The detailed remaining accessibility evidence is tracked in §7.3; asset source/license/attribution approval remains a production HOLD.)_
- [ ] Implement patient device registration, protected storage, privacy cover, and generic push. _(2026-07-22 partial: protected storage, volatile messaging drafts, app-switcher/privacy covers, iOS screenshot handling, and Android `FLAG_SECURE` are verified. The patient-realm `notification_devices` table and the default-off `PUT`/`DELETE /me/notification-devices/{deviceUuid}` contract now accept/revoke only the authenticated principal's opaque-device registration. The registry encrypts provider-token material with its own versioned keyring, derives a keyed lookup digest, serializes device/token and installation replacement under transaction advisory locks, records only content-free audit metadata, returns no token/ciphertext/digest, and makes missing/foreign device UUIDs generic. It remains separate from `prod.mobile_devices` and authentication sessions, has no clinical payload column, does not call a notification provider, and has no native token acquisition/wiring; registration must not be construed as delivery. The feature gate defaults false. Static contract/ledger/baseline checks and Pint pass; the focused PHP feature suite is blocked before execution by the unavailable local PostgreSQL maintenance service. Both native account menus expose the already-governed profile preference fields for text size, motion, contrast, notification preview, and preferred delivery channel; these fields are an account preference only, never promise notification delivery, and never alter urgent guidance. The focused iOS Simulator unit/UI test and Android API 35 emulator journey prove the synthetic path does not write a patient account. Provider enablement, invalid-token feedback, revocation propagation, generic delivery, collapse/expiry, native push-token wiring, and live delivery/device coverage remain.)_
- [x] Establish the disabled secure-messaging persistence/API kernel without enabling a communicating pilot. _(2026-07-21: the patient kernel enforces dedicated-key encrypted append-only messages, receipts/routing facts, active-encounter and grant authorization, exact replay before fresh-write readiness, content-free transactional handoff, backoff-aware consumer health, and generic non-disclosure. The accountable staff bridge exposes all nine capability-gated operations, uses one shared governed responder/pool resolver, binds thread/work-item/grant identity, and records immutable assignment/routing/audit/escalation facts. The lifecycle-reconciliation suite passes 10 tests/148 assertions and the complete Patient feature suite passes. Features remain off and migrations `000300`/`000400` remain local-only.)_
- [ ] Add accessibility/localization foundations and patient-readable content registry. _(2026-07-22 partial: both native patient apps provide a patient-visible account-preferences surface with plain-language clinical-safety boundaries and deterministic accessibility identifiers/test tags. Saved text-size/high-contrast choices now have a bounded, patient-visible rendering effect across the native care experience while preserving stronger device font settings; the focused iPhone 17 Pro and Android API 35 emulator paths confirm that effect. A versioned, default-English backend state registry now blocks unknown released state codes, matching native maps never title-case or expose an unknown internal code, and the additive envelope version makes clients withhold explicitly incompatible state vocabulary. This is not localization, translated/clinically approved content, a complete display-zoom/system-setting matrix, or a completed high-contrast/screen-reader acceptance review.)_
- [x] Implement immutable correction/retraction behavior at the projection and disclosure boundary. _(2026-07-19: effective terminal actions hide the target without falling back to an older release; corrections require a separately released, same-grant/same-kind superseding projection; model and database race guards are tested.)_
- [ ] Implement production source reconciliation, projection workers, lag measurement/alerting, replay, and dead-letter recovery. _(2026-07-22 partial: `PatientPathwaySourceReconciliationService` provides a default-off, no-route, configuration-allowlisted ingestion boundary with all-or-nothing append/replay behavior; `PatientPathwaySourceReconciliationTest` covers approved admission, duplicate replay, absence-without-cancellation, unapproved-source/gate rejection, and rollback. The separate `PatientPathwayProjectionReviewReleaseService` now persists two-person clinical approval/release execution and atomically publishes a reviewed release through the existing outbox. No source is allowlisted in shipped configuration, and no deployed connector, worker, lag metric, dead-letter policy, operational runbook, or live review workflow exists yet.)_
- [ ] Conduct usability tests with patient/family advisors before clinical pilot.

**Exit evidence**

- [ ] Read-only pilot passes wrong-patient, relationship, stale data, revocation, accessibility, and language gates.
- [ ] Every displayed field has source, release, freshness, uncertainty, and correction rules.
- [x] No patient binary can call or decode staff endpoints. _(2026-07-19: patient clients use explicit `/api/patient/v1` endpoint allowlists; iOS project/source and Release-binary scans plus Android source/Gradle/Release-bytecode boundary gates reject staff API prefixes and staff-module linkage.)_

### Phase 4 — Patient pathway, rounds, education, and discharge

**Goal:** deliver deep care-pathway understanding and patient participation.

- [ ] Implement pathway definitions/versions/instances/stages/milestones. _(2026-07-22 partial: structured, status-bearing pathway **stages** and **milestones** (planned/current/completed/delayed/canceled), plus patient- vs care-team-authored **goals** and **education**, now render through the governed "My Path" projection. `milestones` was upgraded from a flat string list to a validated object list in both the `PatientProjectionContentGuard` and the patient OpenAPI (new `PatientPathwayMilestone` schema), and the deterministic reference projection was built into a full admission→discharge journey (four stages, three milestones, two goals, one education item). `PatientProjectionApiTest` asserts the journey end to end; `PatientProjectionKernelTest` guards valid and rejects invalid structured milestones and timeline events (plain strings, bad status, leaked staff note). A **pathway-events timeline** operation `GET /encounters/{uuid}/pathway/events` now renders through the governed `pathway_events` projection kind in both native My Path views, preserving released status, approximate time, detail, notice, and provenance without presenting a complete clinical record. This tranche closes native presentation parity: iOS decodes `PatientReleasedPathwayMilestone`, `PatientReleasedGoal`, `PatientReleasedEducation`, and `PatientReleasedPathwayEvent`; Android decodes the equivalent structured objects; both show distinct milestones, source-labeled goals, released education, and key timeline moments in My Path. Both also consume governed, read-only `discharge_readiness` and `rounds_summary` projections. The latter presents only a released plain-language care-conversation summary—never the staff virtual-rounds workspace, its contributions, assignments, or deliberations—under `pathway:read`, a dedicated default-off feature gate, no-store responses, patient-realm audit, and 404-on-unavailable behavior. Route↔OpenAPI↔ledger now stay exact at 25 patient operations; the previously passing full Patient suite must be rerun once the local PostgreSQL maintenance service is restored; iOS Simulator passes **52/52** unit and **6/6** UI tests; Android passes **82/82** Debug and **78/78** Release JVM tests plus **13/13** API 35 instrumentation tests. Remaining: versioned pathway definition/version/instance authoring tables and approved production source adapters/reconciliation.)_
- [x] Add the immutable, version-pinned pathway-history foundation. _(2026-07-22: `care_pathways.stage_definitions` is immutable source content within one catalog version. An encounter-scoped `patient_experience.pathway_instance` can be created only by an internal source adapter against a currently active, institutionally approved version in an active clinically signed-off release; its source reference is HMAC-digested. Stage/milestone instance membership is enforced against the exact pinned version, and planned/current/completed/delayed/canceled status changes are separate append-only observations with deterministic current-status views. `PatientPathwayInstanceHistoryTest` covers replay, historic status retention, cross-version rejection, definition immutability, and destructive-history rejection. This is deliberately not yet a production source adapter, a patient release, a clinical care-plan/order workflow, or patient-visible authoring.)_
- [x] Produce a draft-only My Path projection from approved version-pinned history. _(2026-07-22: `PatientPathwayHistoryDraftService` admits only a current effective `pathway:read` grant, a version with active institutional approval and active clinical-signoff release, and approved stage/milestone definitions with a current append-only observation. It creates one `patient-pathway.v1` draft guarded by the existing allowlist, stores no source assignment/event identifier in content, never sets `released_at`, and leaves correction and patient disclosure under the existing governed boundary. The exact same historical input reuses the draft instead of adding a cursor/projection. `PatientPathwayInstanceHistoryTest` covers bounded current-stage/milestone rendering, draft-only state, identifier exclusion, replay, and fail-closed feature gates. Production assignment ingestion, source reconciliation, and patient-visible release remain intentionally separate.)_
- [x] Add the default-off source-reconciliation boundary for version-pinned history. _(2026-07-22: a connector can call `PatientPathwaySourceReconciliationService` only after the patient product, pathway, `CARE_PATHWAYS_ASSIGNMENT_ENABLED`, `CARE_PATHWAYS_PATIENT_ENABLED`, and dedicated reconciliation gates are all on and the exact versioned source key is code-allowlisted. It resolves only approved definitions in one exact active version, serializes the source assignment with an HMAC advisory lock, and atomically appends replay-safe HMAC-digested assignment/stage/milestone facts. No raw source reference, snapshot, or event ID is stored or returned; an empty subsequent snapshot does not infer a cancellation. `PatientPathwaySourceReconciliationTest` covers the transaction and fail-closed boundaries. This is a connector seam, not a source-system integration, clinical assignment, patient release, route, worker, or writeback.)_
- [x] Add two-person clinical review and release for a pathway-history draft. _(2026-07-22: `PatientPathwayProjectionReviewReleaseService` is default-off and no-route. A staff actor with `ApproveCarePathwayClinical` can append exactly one content-free approved/withheld decision for an eligible current/aging draft; a different actor with `ActivateCarePathwayCatalog` can then append one matching released projection and immutable execution fact in the same transaction. The database independently verifies draft/policy/grant/content-digest matching and defers a release-producer guard until commit, so the release cannot persist without its execution fact. The normal released-projection outbox trigger publishes only the content-free release event. `PatientPathwayProjectionReviewReleaseTest` covers approval/replay, withholding, two-person authorization, deferred database guard, outbox publication, governed patient disclosure selection, and fail-closed gating. Static verification passes; the focused PostgreSQL test is staged but cannot start while the local PostgreSQL maintenance service remains unavailable. This is not a deployed staff workflow, source connector, automatic worker release, correction/retraction workflow, or patient-facing authoring path.)_
- [ ] Implement today's goals and patient-authored preferences. _(2026-07-22 partial: each native My Path view clearly distinguishes released patient-authored goals from care-team goals. Patients can submit \"What matters to you\" as a non-urgent, encrypted, idempotent, accountable care-team message; the server-driven topic and both native My Path views explicitly state that this is not a care-plan change or clinical order. The separate default-off `HUMMINGBIRD_PATIENT_CARE_PREFERENCES_ENABLED` and `HUMMINGBIRD_PATIENT_GOALS_ENABLED` flags now persist exactly one append-only, content-free association for their respective direct topics, `care_preference` and `patient_goal`, inside the existing encrypted message-create transaction. Both facts store no text, clinical interpretation, care-plan content, order, consent, or assessment; staff continue to review/respond through the accountable message workflow. A patient-goal/care-plan mutation path remains intentionally absent. Care-team acknowledgement projection, approved source reconciliation, and governance workflows remain unimplemented.)_
- [x] Persist a patient-authored care preference without creating a shadow care plan. _(2026-07-21: the default-off preference flag preserves existing messaging behavior until explicitly enabled. When enabled for the approved direct `care_preference` topic, the locked, idempotent message-create transaction atomically adds one immutable, content-free association to the principal, active encounter grant, message thread, encrypted source message, policy version, and HMAC-protected replay/payload digests. The ordinary thread response exposes no preference UUID or clinical/control field; separate patient audit events contain only the opaque preference UUID and replay metadata. A replay returns the original thread/fact, other message topics cannot create a preference fact, and disabling the fact flag retains the established encrypted-message flow. The PHP feature suite is staged but remains blocked before database boot by the unavailable local PostgreSQL maintenance service; PHP syntax, route inventory, OpenAPI baseline, capability ledger, disclosure matrix, and native patient boundary controls pass.)_
- [x] Persist a patient-authored personal goal without creating a clinical goal or shadow care plan. _(2026-07-22: the separate default-off `HUMMINGBIRD_PATIENT_GOALS_ENABLED` flag preserves existing encrypted-message behavior until explicitly enabled. For the approved direct `patient_goal` topic, the locked, idempotent thread-create transaction adds exactly one immutable, content-free `patient_experience.patient_authored_goals` association to the principal, active encounter grant, message thread, encrypted source message, policy version, and HMAC-protected replay/payload digests. The database enforces one source message/thread per fact and rejects updates/deletes. The ordinary response exposes no goal UUID or clinical/control field; separate audit facts contain only the opaque goal UUID and replay metadata. Exact replay returns the original thread/fact, other topics cannot create a goal fact, and feature disablement keeps the established message-only workflow. The server contract and both native synthetic selectors now distinguish a preference from the dedicated personal-goal topic while retaining the same care-plan/order safety boundary; iOS focused unit/UI tests and the Android API 35 instrumentation journey pass. The PHP feature test is staged but cannot boot while the local PostgreSQL maintenance service remains unavailable; static PHP, contract, ledger, disclosure, and native patient-boundary checks are required before enabling it.)_
- [x] Surface patient-safe promotion status without exposing Virtual Rounds. _(2026-07-21: a successful staff promotion atomically appends one encrypted, patient-visible `system_status` message—"shared ... for possible review" and explicitly not a promise of discussion—to the patient-owned thread, advances the thread/work-item versions, and links it through a unique opaque `patient_status_message_id`. Exact idempotency replay returns the original promotion/status without a duplicate. The backend test decrypts the status through the normal patient thread serializer and proves it contains neither the patient question nor rounds identifiers; the iOS and Android synthetic journeys visibly render the same bounded wording.)_
- [x] Publish one patient-safe post-review status for a promoted question. _(2026-07-21: when the linked staff `RoundQuestion` reaches `answered` or `dismissed`, the same staff-resolution transaction creates at most one content-free `patient_communications.round_question_promotion_outcomes` fact and one encrypted patient `system_status`: “Your care team has completed their review …”. The patient message intentionally omits the staff resolution label, staff response, round identity, question text, and any promise of a particular discussion or reply. The outcome requires the bridge and messaging approvals to remain enabled, a current approved matching policy, active effective grant, and active source encounter; otherwise it safely withholds the disclosure without blocking ordinary staff resolution. The focused backend test proves atomic creation, exact repeat suppression, patient serialization, version advancement, audit/event metadata without question text, and feature-disable suppression; both native synthetic journeys render the bounded status.)_
- [x] Bridge patient questions into virtual rounds without widening the patient realm. _(2026-07-22: do **not** let the patient realm call `POST /api/rounds/patients/{roundPatientUuid}/questions` or reuse `rounds.questions` directly. A patient-owned, encrypted `rounds_question` message thread is the composition queue; the iOS and Android dynamic topic picker renders its approved, explicitly non-promissory wording. `HUMMINGBIRD_PATIENT_ROUNDS_QUESTIONS_ENABLED` independently controls composition, while `VIRTUAL_ROUNDS_PATIENT_QUESTION_BRIDGE_ENABLED` independently controls staff discovery and promotion; both default false, and the bridge also requires patient product/messaging/staff-messaging approval. The staff-only `GET /api/rounds/patients/{roundPatientUuid}/patient-question-threads` discovery projection decrypts question text only after the same current `User`, `RespondPatientCommunications`, effective accountable-pool membership, `RoundAuthorizationService::assertCanContribute`, active matching source encounter, exact policy/topic, and non-terminal-round checks as promotion. The Virtual Rounds workspace uses that projection only in the authorized staff context, labels it non-urgent, and requires an explicit “possible review” confirmation. `POST /api/rounds/patients/{roundPatientUuid}/patient-question-threads/{threadUuid}/promote` also requires the expected thread version and exact replay key. The content-free, one-to-one `patient_communications.round_question_promotions` fact points to the encrypted source message, patient-safe status message, and newly created staff `RoundQuestion`; it stores no patient text, audits promotion, rejects cross-encounter, disabled, revoked, and retracted content, and exact-replays the same idempotency key without creating a duplicate. The Rounds feature suite, complete web frontend suite, native iOS Simulator, and Android API 35 verification passed before the later registration-only additions; the patient OpenAPI is exact at 25 operations.)_
- [x] Enable patient withdrawal of an unshared rounds question without destructive deletion. _(2026-07-21: reuse the existing patient `POST /threads/{threadUuid}/close` exact-replay/optimistic-version operation with the `created_in_error` reason rather than adding an unsafe delete or staff-rounds call. A closed `rounds_question` thread is excluded from the staff discovery projection, and promotion independently rejects non-open threads; the focused backend test proves both outcomes. iOS and Android now call this action “Withdraw this question” only while no patient-safe promotion `system_status` is present, explain that it prevents sharing only if not already shared, and retain ordinary close wording once it has been shared. The iOS Simulator and Android API 35 journeys exercise the visible withdrawal explanation.)_
- [x] Add patient message-level correction/retraction for a question already shared. _(2026-07-21: the patient-only `POST /threads/{threadUuid}/messages/{messageUuid}/amend` operation accepts exactly one correction or withdrawal for an eligible patient-authored `message`, never rewrites or deletes the source, and requires the current open thread, active grant/encounter, approved policy/topic, expected version, fresh idempotency key, and content/routing readiness. Corrections are separately encrypted immutable facts; withdrawals are bodyless immutable facts. Both advance the thread version, create a receipt and content-free routing/outbox/audit facts, and are protected by model/policy checks plus a PostgreSQL one-amendment partial unique index. Exact replay returns the original result; a changed request or second amendment cannot create another fact. The staff rounds bridge excludes a retracted source, presents only a superseding correction, and rejects a direct attempt to promote the original once an amendment exists. Both patient apps expose clear Correct/Withdraw controls only while eligible, describe retained history, and never promise that a prior share can be erased. Backend messaging tests now pass **28 tests / 496 assertions**, including care-preference routing; the focused rounds bridge suite passes **8 tests / 101 assertions**; the contract, capability ledger, iOS Simulator tests, Android JVM tests, and Android API 35 primary journey are current.)_
- [x] Implement the separate, read-only patient rounds-summary kernel, contract, and native presentation. _(2026-07-21: `GET /api/patient/v1/encounters/{encounterUuid}/rounds/summary` resolves only the latest `rounds_summary` projection explicitly released by the patient projection policy. The schema accepts a plain-language headline, summary, approximate round window, status-labelled topics, next steps, questions, and notices; it rejects unknown nested fields and internal-note/routing/source identifiers. A forward-only migration widens only the governed projection-kind constraints. iOS and Android treat a feature-disabled/missing/retracted release as unavailable, never cache or mutate it, show provenance/uncertainty, and state that the view is not the complete conversation. Backend contract/kernel/feature tests, iOS Simulator UI tests, and Android API 35 instrumentation tests pass.)_
- [ ] Publish only approved rounds summaries to patient projection. _(2026-07-21 partial: the technical release boundary and default-off feature gate exist, but no production source adapter, review workflow, approval record, reconciliation worker, or deployment authorization has been added. The synthetic fixture is test-only and cannot run outside the testing environment.)_
- [x] Add test/procedure/transport milestones with release policy. _(2026-07-21: the existing append-only, patient-realm `pathway_events` release policy now admits only the explicit optional `category` enum `test`, `procedure`, `transport`, or `other`; unknown categories are rejected by `PatientProjectionContentGuard` before release. The patient OpenAPI documents the category, while the deterministic reference journey includes a completed test, a current procedure-preparation milestone, and a planned transport milestone. iOS and Android decode and visibly label the category beside the released status, preserving plain-language timing, provenance, uncertainty, and no-store behavior. `PatientProjectionApiTest` and `PatientProjectionKernelTest` pass **20 tests / 615 assertions**; focused iOS Simulator model/UI tests, Android decoder/session JVM tests, and the Android API 35 six-test primary journey pass. This is a release-gated presentation capability only: it exposes no raw test result, clinical interpretation, order, exact schedule, or production source adapter.)_
- [ ] Add education assignments and governed teach-back review. _(2026-07-21 partial: released education has its own My Path section on iOS and Android. Assignment, clinical review, comprehension assessment, education completion, consent, and outcome workflows remain unimplemented.)_
- [x] Allow a source-bound education clarification request without asserting comprehension. _(2026-07-21: a patient may use the default-off `POST /encounters/{encounterUuid}/education/{educationItemUuid}/clarifications` endpoint only when messaging and education-clarification flags are enabled and the item UUID is contained in the patient's current released pathway projection. The server creates the existing encrypted, accountable care-team message under a reserved policy topic and a separate append-only, content-free association fact; generic messaging cannot enumerate or compose the reserved topic. The request is idempotent, no-store, audit-recorded, and returns only the ordinary message thread. It never accepts or stores a completion, comprehension, consent, clinician-assessment, care-plan, or order field. iOS and Android render it as “Ask for an explanation,” keep it out of synthetic/offline mode, and send only the message, client UUID, urgent-guidance version, and normal idempotency key. Focused iOS Simulator and Android JVM endpoint-boundary tests pass; the PHP feature test is present but cannot boot locally while the PostgreSQL maintenance service is unavailable.)_
- [ ] Add discharge-readiness, medication, equipment, transport, follow-up, warning-sign, and contact projections. _(2026-07-21 partial: both patient apps now consume the existing feature-gated, patient-realm `discharge_readiness` read-only projection under `pathway:read`, expose its released estimated range/confidence, criteria, unresolved needs, medicines, follow-up, warning signs, contacts, questions/notices, freshness/provenance, and an explicit "Your team confirms the details" safety callout. The clients treat 404 as unavailable, do not cache or mutate the content, and never infer a discharge commitment. Approved source adapters, equipment and transport expansion, release governance, reconciliation, and clinical/patient-advisor validation remain.)_
- [x] Add patient-visible history/provenance and correction notices. _(2026-07-22: governed projections already expose patient-readable release/freshness/provenance context; an effective immutable `correction` action now adds an optional, bounded `revision_notice` only to its separately released and already authorized replacement. The notice is fixed patient language—“Your care team updated this information. Please use the details shown here.”—and deliberately contains no withdrawn projection UUID, correction reason, actor, source reference, or correction timestamp. A corrected target and a retracted release remain indistinguishable generic 404s; a superseding row without an effective correction action does not claim a correction. The OpenAPI schema documents this optional source-free field without adding an operation (the patient contract now contains 25 operations). iOS and Android decode it as an optional correction-only type and place it with the relevant Today, My Path, or Care Team context; My Path safely summarizes notices from its pathway, event, discharge-readiness, and rounds sections. Android rejects an unexpected notice kind rather than rendering it. `PatientProjectionApiTest` passed **13 tests / 548 assertions** before the registration-only additions; contract, disclosure-matrix, baseline, ledger, and source-boundary checks pass; focused iOS API/view-model/reference-journey tests, Android decoder/session tests, and the Android API 35 reference journey pass.)_
- [ ] Validate content with nursing, physicians, pharmacy, care management, health literacy, accessibility, and patient advisors.

**Exit evidence**

- [ ] Admission-to-discharge reference journeys pass source/release/freshness tests.
- [ ] Patient questions cannot create orders or silently modify clinical data.
- [x] Education clarification requests are clearly distinct from legal consent and clinician assessment. _(2026-07-21: the route, schema, model, immutable fact, policy topic, UI copy, and native request bodies are deliberately limited to a patient request for an explanation. They do not create a teach-back/comprehension state.)_

### Phase 5 — Secure communication and staff response operations

**Goal:** allow patients to reach the appropriate team with accountable routing.

- [ ] Implement threads, messages, receipts, attachments, routing, assignment, handoff, escalation, and closure. _(Implemented through 2026-07-21: patient topics/list/create/detail/send/close; shared governed pool/responder resolution; content-free scheduled handoff; all nine staff inbox/detail/route-candidate/mutation operations; exact replay and optimistic concurrency; latest-patient-message response requirement; SLA reset after follow-up; manual release/reassign/reroute; scheduled immutable escalation; and scheduled encounter-lifecycle reconciliation (discharge close, **unit**-transfer reroute, shift release/coverage reroute, and pool-downtime reroute). The reconciler and clients fail closed on a missing/ambiguous destination; every automated transition preserves thread/work/grant identity and appends content-free staff/routing/receipt facts. Remaining: attachments, patient push, retention/legal-hold/export operations, production runbooks, and authoritative service-ownership input—there is no approved canonical service signal to automate a service-change handoff.)_
- [ ] Build Zephyrus web communications inbox. _(2026-07-20 partial: the capability-gated workspace implements all nine operations, exact replay, ambiguous-outcome privacy clearing, and unit/team filters. Facility/service-line filters, push, pilot telemetry, and deployed E2E remain.)_
- [x] Add staff Hummingbird For You items and native reply/route/close workflow. _(2026-07-21: PHI-minimized For You cards plus native inbox/detail/route candidates/claim/reply/close/release/reassign/reroute are implemented on iOS and Android with immutable exact retry, access-loss/omission purge, pending-action locking, ambiguous-reroute source revocation, and foreground-only server-transition polling. Retained-row version/ownership/pool/unit drift clears stale detail, draft, and routing state before exactly one current-detail read; iOS fences an older response and Android synchronously gates a timer tick after lifecycle stop or lock. Patient push, authoritative service ownership, and deployed E2E remain separate backlog.)_
- [ ] Integrate an approved canonical service-ownership/lifecycle feed before claiming service-change routing. _(The current authoritative inputs are encounter unit, discharge state, pool status, and effective pool membership only; the scheduler intentionally reports `unresolved` rather than guessing a service handoff.)_
- [ ] Configure responsibility pools and shift handoff integration for pilot units.
- [ ] Implement urgent guidance, safety-term escalation, unowned-thread alerting, and SLA dashboards.
- [ ] Add proxy-scoped participation.
- [ ] Complete abuse, attachment, retention, eDiscovery/legal hold, export, and amendment procedures.
- [ ] Staff pilot units and support desk; train on response expectations.

**Exit evidence**

- [ ] No test thread remains unowned beyond policy threshold.
- [ ] Shift change, patient transfer, service change, and discharge route correctly. _(Local lifecycle evidence covers ended-assignee release, coverage-gap reroute, unit transfer, discharge close, and pool downtime. An authoritative service-ownership source and deployed end-to-end evidence remain required.)_
- [ ] Urgent-message tabletop and downtime drills pass.
- [ ] Patient-visible delivery/acknowledgement states match actual routing state.

### Phase 6 — Proxy, language expansion, home transition, and patient Eddy evaluation

**Goal:** broaden access safely after the core workflow is stable.

- [ ] Enable representative invitations/delegations for approved adult use cases.
- [ ] Add reviewed flows for guardianship, incapacity, adolescents, and sensitive data where legally approved.
- [ ] Expand languages with professional translation and interpreter integration.
- [ ] Define post-discharge access, message closure, and transition to the long-term portal.
- [ ] Evaluate patient Eddy in a non-actioning, explanation-only study.
- [ ] Add Home Hospital transition only after identity, messaging, offline, and escalation differences are modeled.

**Exit evidence**

- [ ] Proxy revocation and sensitive-data test matrices pass.
- [ ] Language-access validation passes with native speakers and interpreter services.
- [ ] Patient Eddy, if pursued, has a separately approved safety case and red-team evidence.

### Phase 7 — Scale and general availability

**Goal:** prove multi-facility operability without weakening local policy controls.

- [ ] Parameterize facility content, release rules, responsibility pools, escalation, languages, and support.
- [ ] Add facility-readiness assessment and deployment manifest.
- [ ] Run load, failover, queue-backlog, push-outage, EHR-outage, and recovery tests.
- [ ] Complete independent security and accessibility audits.
- [ ] Measure pilot safety, adoption, comprehension, messaging workload, response, equity, and discharge outcomes.
- [ ] Define go/no-go thresholds and rollback triggers.
- [ ] Publish support, incident, on-call, content governance, and version-deprecation runbooks.

---

## 12. Proposed implementation slices and pull-request sequence

Each PR should be independently reviewable, preserve existing web behavior, update the ledger/contract/tests together, and avoid mixed staff/patient authorization changes.

1. **Parity ledger and CI.** Add capability schema, seeded inventory, report generator, ownership fields, and contract checks.
2. **Staff authentication parity.** Refresh rotation/client coordinators, Android password change, secure-store fail-closed, session tests.
3. **Push parity.** FCM integration, APNs hardening, device lifecycle, generic payload contract, delivery telemetry.
4. **Staff A2P hardening.** Opaque indexed context mapping, active encounter unit authorization, revocation/expiry, negative tests.
5. **Shared/generated client seam.** Contract schemas, generated DTOs, unknown-enum/error behavior, fixture CI.
6. **Android Eddy parity.** Chat, stream fallback, conversations, approval view/decision, accessibility and UI tests.
7. **Offline/outbox kernel.** Encrypted cache, pending intent, idempotency, conflict, expiry, logout wipe.
8. **Staff workflow completion.** RTDC creation/barriers, ops actions, OR transitions, staffing lifecycle, full Activity emission.
9. **Rounds and huddles mobile.** BFF endpoints, role policies, both clients, notifications, event ledger.
10. **Patient architecture ADRs and contract skeleton.** Separate guard/schema/OpenAPI/apps, no patient data yet.
11. **Patient identity and enrollment.** Principal, identity link, encounter grant, sessions, audit, proofing integration.
12. **Patient projection kernel.** Source registry, release policy, cursor, provenance, correction/retraction, lag monitoring.
13. **Patient Today/My Path/Care Team read-only.** Both apps, accessibility/localization, deterministic fixtures.
14. **Patient rounds/questions and teach-back.** Staff bridge, approved summary, patient state, no order writeback.
15. **Patient discharge pathway.** Readiness, education, released medication/follow-up content, uncertainty language.
16. **Communication persistence and routing.** Threads, pools, messages, receipts, escalation, staff web inbox.
17. **Staff mobile communication.** For You integration, reply/route/close, push, handoff, offline-safe drafts.
18. **Proxy/delegation.** Invitations, verification, scopes, revocation, sensitive-case fail-closed workflow.
19. **Pilot hardening.** Threat model controls, performance, accessibility, security testing, runbooks, dashboards.
20. **Patient Eddy experiment, optional.** Retrieval-only evaluation behind a disabled-by-default research flag.

---

## 13. Test and verification matrix

### 13.1 Backend and contract

- [x] Route/OpenAPI exact parity for staff and patient groups. _(2026-07-21: staff inventory is exact across 58 authenticated operations and 58 OpenAPI operations, including all nine staff communication operations; patient inventory is exact across all 25 operations, including the default-off notification-device registration/revocation pair and the source-bound education-clarification operation; 130 local staff-contract references resolve.)_
- [x] Standard envelope and error-schema tests. _(2026-07-19: `MobileBffTest` passes nine focused cases including every documented GET envelope and the server-derived patient-communications capability map; patient contract validation enforces the patient envelope, generic non-disclosing errors, classifications, and mutation metadata. A richer semantic response-schema validator for the legacy staff contract remains an improvement item.)_
- [ ] Capability/relationship/encounter/facility/unit/state authorization matrix. _(Patient communications now cover current encounter/grant/scope, effective membership/capability, facility/unit pool precedence, same-tier ambiguity, destination eligibility, and canonical thread/work-item/grant identity; the broader platform matrix remains.)_
- [ ] IDOR and resource-existence non-disclosure tests. _(Patient and staff communication routes cover unknown, cross-principal, revoked, expired, missing-scope, access-loss, and omitted-resource cases with generic denial and local projection purge; broader domains remain.)_
- [ ] Token issue/refresh/reuse/revoke/recovery/device tests.
- [ ] Idempotency and exactly-one-event tests for every mutation. _(All six patient communication mutations and the staff manual assignment/routing mutations cover exact replay, changed-payload conflict, and exactly-one immutable event behavior; auth/profile and older operational writes remain.)_
- [ ] Optimistic concurrency and cross-channel web/mobile conflict tests. _(Communication versions, stale conflicts, ambiguous outcomes, immutable same-request recovery, and no-blind-retry behavior are covered across web, iOS, and Android; broader write families remain.)_
- [ ] Projection provenance, release, sensitivity, correction, retraction, and lag tests.
- [ ] Patient/proxy/representative/minor/sensitive-service boundary tests.
- [ ] Messaging assignment/handoff/escalation/discharge/transfer/downtime tests. _(Manual claim, release, supervisor reassign, cross-pool reroute, responder eligibility, handoff-consumer backoff/readiness, follow-up timer reset, and escalation are covered. Automated workforce shift-release and coverage-gap reroute, **unit** transfer, discharge close, and pool-downtime reroute are covered by the ten-test lifecycle-reconciliation suite, including the no-eligible-destination degraded-`unresolved` invariant. Authoritative service-change and deployed E2E coverage remain.)_
- [ ] Push redaction, collapse, expiry, preference, escalation, and revoked-device tests.
- [ ] FHIR/source version reconciliation and duplicate/late/out-of-order event tests.
- [ ] Database row-level/query-scope and audit completeness tests.

### 13.2 Native functional matrix

For both iOS and Android, test:

- [ ] fresh install, upgrade, logout/login as another user, environment change;
- [ ] access-token expiry during read and write;
- [ ] role/facility/unit/encounter/proxy grant change while app is foregrounded and backgrounded;
- [ ] deep link and notification after item closure/deletion/access revocation;
- [ ] cold/warm launch, process death, low memory, clock skew, time-zone/DST change;
- [ ] no network, slow network, intermittent network, duplicate response, stale cache, schema-additive response;
- [ ] mutation queued, replayed, conflicted, expired, revoked, and manually withdrawn;
- [ ] biometric unavailable, lockout, device passcode removed, secure store failure;
- [ ] screenshot/app switcher/clipboard/widget/notification privacy;
- [ ] Hummingbird background asset availability, deterministic crop/scrim, contrast over brightest/darkest regions, images-disabled fallback, reduced transparency/motion, and large-text readability;
- [ ] screen reader, 200% text, landscape where supported, reduced motion, high contrast, and language expansion.
- [x] Staff communications foreground polling, omission/authorization purge, retained-row transition reconciliation, stale-response fencing, immutable exact retry, and lifecycle/lock pause. _(2026-07-21: iOS passes 60/60 unit and 16/16 UI tests on iPhone 17 Pro (iOS 26.3.1); Android passes 92/92 Debug and 89/89 Release JVM tests plus 16/16 API 35 AVD instrumentation tests. The Android device run originally exposed one lifecycle-stop timer race; the synchronous post-wait gate was added and the focused test plus full suite were rerun green.)_

### 13.3 Patient reference scenarios

- [ ] uncomplicated adult admission;
- [ ] intra-hospital unit transfer;
- [ ] surgery during admission;
- [ ] ancillary test delay and result release;
- [ ] patient asks pre-rounds question and receives approved summary;
- [ ] patient message routed through shift handoff;
- [ ] estimated discharge date changes;
- [ ] patient discharged while a thread is open;
- [ ] patient identity merge/correction;
- [ ] proxy invited, scoped, expired, and revoked;
- [ ] limited-English-proficiency patient with interpreter;
- [ ] visual, hearing, motor, cognitive, and low-literacy accommodations;
- [ ] sensitive-data case that must fail closed;
- [ ] source outage and stale projection;
- [ ] incorrect patient-facing content retracted and corrected.

### 13.4 Repository verification commands

Run the narrowest relevant set per PR, then the full gates before phase exit:

```bash
# Contract/control plane: generate first when the ledger intentionally changed,
# then require a clean freshness check.
php scripts/generate-hummingbird-capability-report.php
php scripts/verify-hummingbird-capability-ledger.php
php scripts/generate-hummingbird-capability-report.php --check
php scripts/verify-hummingbird-staff-contract.php
php scripts/verify-hummingbird-patient-disclosure-matrix.php
php scripts/verify-hummingbird-patient-contract.php

# Backend slices used by this checkpoint.
php artisan test tests/Feature/Patient
php artisan test \
  tests/Feature/MobileBffTest.php \
  tests/Feature/Mobile/MobileAltitudeContractTest.php \
  tests/Feature/Mobile/ForYouTest.php \
  tests/Feature/Mobile/PersonaDefaultTest.php

# Zephyrus web communication workspace and production bundle.
npx tsc --noEmit
npx vitest run \
  tests/js/pages/PatientCommunications.test.tsx \
  tests/js/pages/PatientCommunicationMutationSafety.test.ts \
  tests/js/pages/PatientCommunicationRoutingPolicy.test.ts \
  tests/js/config/navigationConfig.test.ts
npm run build

cd hummingbird/androidApp
./gradlew testDebugUnitTest testReleaseUnitTest lintDebug lintRelease assembleDebug assembleRelease
./gradlew connectedDebugAndroidTest

cd hummingbird/iosApp
xcodebuild test -project Hummingbird.xcodeproj -scheme Hummingbird -destination 'platform=iOS Simulator,name=iPhone 17 Pro,OS=26.3.1'
xcodebuild build -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Release -destination 'generic/platform=iOS Simulator' CODE_SIGNING_ALLOWED=NO

cd ../androidPatientApp
bash ../../scripts/ci/verify-hummingbird-patient-boundary.sh source app/src
./gradlew verifyPatientProductBoundary testDebugUnitTest testReleaseUnitTest lintDebug lintRelease assembleDebug assembleRelease
./gradlew connectedDebugAndroidTest

cd ../iosPatientApp
bash ../../scripts/ci/verify-hummingbird-patient-xcode-project.sh .
bash ../../scripts/ci/verify-hummingbird-patient-boundary.sh source .
xcodebuild test -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,name=iPhone 16 Pro'

cd ../..
npx prettier --check \
  docs/hummingbird/ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md \
  docs/hummingbird/README.md \
  docs/hummingbird/capability-ledger.v1.yaml
./vendor/bin/pint --test
git diff --check
```

The iOS schemes now include test targets. Local developers may substitute any supported installed simulator; CI must continue to resolve and pin an available simulator/device rather than relying on a developer-local default. Connected Android and iOS UI commands require a booted emulator/simulator and are independent of live backend E2E.

At this checkpoint, repo-wide `tsc --noEmit` is the only locally red focused gate: on the case-insensitive macOS checkout it reports `TS1261`/`TS1149` for pre-existing Git directory families that differ only by case (`Components`/`components`, `Hooks`/`hooks`, and `Contexts`/`contexts` imports). The four-file communication gate passes **24/24**, the complete frontend suite passes **636/636 across 144 files**, and the production Vite bundle passes. None of the reported TypeScript paths is part of the patient-communications implementation; nevertheless, do not call frontend CI green until the casing debt is normalized or a clean Linux CI run proves the committed tree. Before a phase exit, also run the broader legacy mobile, rounds, security, and full Laravel CI shards rather than treating the focused checkpoint commands as complete regression coverage.

---

## 14. Observability, service levels, and product measures

### 14.1 Technical and safety indicators

- API success/error/latency by operation and app version, without PHI dimensions.
- Projection lag by source/domain/facility and count of stale patient views.
- Reconciliation mismatch and retraction/correction counts.
- Push accepted/delivered/opened/actioned/expired/suppressed/duplicated rates.
- Realtime reconnect/fallback and stale-screen rates.
- Offline outbox age, replay success, conflict, expiry, and authorization-revoked counts.
- Wrong-patient/wrong-relationship prevention signals and near misses.
- Message unowned time, acknowledgement time, response time, reroute, escalation, reopen, and discharge-with-open-thread counts.
- Accessibility defect escape rate and language fallback frequency.
- Crash-free sessions, app start, screen render, memory, energy, and data-transfer budgets.

### 14.2 Patient/product measures

- Enrollment offer, acceptance, activation, and sustained use, stratified for equity review.
- Patient comprehension of today's goals, next step, care-team roles, and discharge plan.
- Questions captured before rounds and answered/closed.
- Teach-back clarification requests and resolution.
- Message workload per occupied bed day and response distribution, not only averages.
- Patient-reported confidence about what comes next and how to reach the team.
- Staff-reported interruption burden, duplicate work, and routing accuracy.
- Discharge instruction comprehension, follow-up completion signals, and support contacts where measurement is approved.
- Adoption and outcomes by language, disability/accessibility need, age, digital-access proxy, and representative use to detect inequity.

### 14.3 Initial service-level objectives to ratify

Do not hard-code clinical expectations until operations approves them. Establish separate SLOs for:

- authentication and enrollment availability;
- patient projection freshness by data class;
- message delivery and ownership acknowledgement by topic/urgency;
- push dispatch latency by tier;
- revocation propagation;
- correction/retraction propagation;
- audit-event completeness;
- support response and incident communication.

---

## 15. Feature flags, kill switches, and rollout controls

- `hummingbird.staff.<capability>` per facility and role.
- `hummingbird.patient.enabled` global and facility flag.
- `hummingbird.patient.enrollment.enabled` per unit/cohort.
- `hummingbird.patient.pathway.enabled` per pathway definition version.
- `hummingbird.patient.rounds_questions.enabled`.
- `hummingbird.patient.messaging.enabled` plus topic-level flags.
- `hummingbird.patient.proxy.enabled` plus relationship-type flags.
- `hummingbird.patient.results.enabled` per release policy.
- `hummingbird.patient.eddy.enabled`, default `false`.
- Server kill switches for patient writes, attachments, push, realtime, proxy invitations, and AI.
- Minimum app version and forced-upgrade control with accessible downtime copy.
- Read-only degraded mode when writes or source systems are unavailable.

Every flag needs an owner, default, dependency, exposure audit, removal date, and rollback procedure. A disabled UI flag must be backed by a disabled/denied server capability.

---

## 16. Required decisions and owners

| Decision                                                 | Required owners                                                  | Blocking phase |
| -------------------------------------------------------- | ---------------------------------------------------------------- | -------------- |
| KMP vs generated native clients                          | Mobile architecture, backend, platform leads                     | Staff P1       |
| Patient app binary/flavor separation                     | Security, mobile architecture, product                           | Patient P3     |
| Patient identity proofing and federation                 | IAM, security, privacy, HIM, product                             | Patient P3     |
| Patient identity source/merge policy                     | HIM, integration, data governance                                | Patient P3     |
| Release policy by content type                           | Legal, privacy, clinical leadership, HIM                         | Patient P3/P4  |
| Proxy/guardian/minor/sensitive-service rules             | Legal, privacy, HIM, pediatrics/behavioral health as applicable  | Patient P6     |
| Messaging topics, responsibility pools, SLA, urgent path | Nursing, medical staff, operations, risk, support                | Patient P5     |
| Results and medication display                           | Clinical leadership, lab/radiology/pharmacy, HIM                 | Patient P4     |
| Translation and interpreter operating model              | Language access, accessibility, clinical content                 | Patient P3/P6  |
| Patient Eddy scope                                       | AI governance, clinical safety, legal, privacy, patient advisors | Optional P6    |
| Post-discharge retention and portal handoff              | Product, HIM, legal, portal team                                 | Patient P4/P6  |

---

## 17. Definition of done

### 17.1 Hummingbird Staff parity

- [ ] Every Zephyrus capability is present in the ledger with ratified disposition and owner.
- [ ] Every mobile capability has matching server, iOS, Android, contract, authorization, test, telemetry, and runbook evidence.
- [ ] All 14 personas pass the agreed journey matrix on both platforms.
- [ ] Platform-native differences do not change meaning, authorization, supported actions, or safety behavior.
- [ ] Auth refresh, protected storage, password change, APNs, FCM, realtime fallback, cache, outbox, and wipe behavior pass production-like tests.
- [ ] The release contains no demo-only access path or debug explorer.
- [ ] All unresolved `PersonaRelayPolicy` events are implemented or removed from claimed scope.
- [ ] Security, accessibility, performance, offline, incident, and support gates pass.

### 17.2 Hummingbird Patient

- [x] Patient is a separate identity and authorization realm, not a staff persona. _(2026-07-19: separate database schema/model/provider, exact-ability Sanctum tokens, route namespace, audit ledger, feature flags, native binaries, bundle/application IDs, and protected-storage namespaces; cross-realm tests reject staff-to-patient and patient-to-staff credentials.)_
- [ ] Every displayed data field has approved source, release, relationship, freshness, uncertainty, correction, translation, and offline rules.
- [ ] Patient and representative access is verified per encounter and revokes promptly.
- [ ] Today, My Path, Care Team, rounds participation, education/teach-back, discharge preparation, and secure messaging pass end-to-end reference scenarios.
- [ ] No staff-only operational context, internal note, other-patient data, raw priority/risk score, or unreleased content is exposed.
- [ ] Messages are durably owned, handed off, escalated, audited, and accurately represented to the patient.
- [ ] Urgent-use instructions are accessible, localized, and validated in tabletop exercises.
- [ ] WCAG 2.2 AA and native accessibility requirements pass independent review.
- [ ] Privacy/security testing, threat controls, clinical hazard controls, release-policy review, patient-advisor usability, and pilot go/no-go gates pass.
- [ ] Patient Eddy remains disabled unless its separate safety case is approved.

---

## 18. External standards and implementation references

Use these as design inputs and verify organizational/legal interpretation before release:

- [HHS HIPAA guidance on online tracking technologies](https://www.hhs.gov/hipaa/for-professionals/privacy/guidance/hipaa-online-tracking/index.html) — authenticated health-app and login/enrollment telemetry requires particularly careful vendor, disclosure, and HIPAA analysis.
- [HHS guidance on an individual's right of access](https://www.hhs.gov/hipaa/for-professionals/privacy/guidance/access/index.html) and [2025 access FAQ](https://www.hhs.gov/hipaa/for-professionals/faq/2042/what-personal-health-information-do-individuals/index.html) — portal display policy must not be confused with the full legal right-of-access workflow; personal representatives and applicable state law also matter.
- [NIST SP 800-63-4 Digital Identity Guidelines](https://www.nist.gov/publications/nist-sp-800-63-4-digital-identity-guidelines) — identity proofing, authentication, federation, recovery, and assurance design input.
- [W3C WCAG 2.2](https://www.w3.org/WAI/standards-guidelines/wcag/) — cross-platform accessibility baseline.
- [HHS Section 1557 limited-English-proficiency guidance](https://www.hhs.gov/civil-rights/for-individuals/section-1557/fs-limited-english-proficiency/index.html) and [HHS telehealth accessibility guidance](https://www.hhs.gov/civil-rights/for-individuals/disability/guidance-on-nondiscrimination-in-telehealth/index.html) — meaningful language access and effective communication are product requirements, not optional localization polish.
- [AHRQ IDEAL Discharge Planning](https://www.ahrq.gov/patient-safety/patients-families/engagingfamilies/strategy4/index.html) and [AHRQ patient/family engagement](https://www.ahrq.gov/hai/cusp/modules/patient-family-engagement/notes.html) — include patients/families as care-team partners; cover medicines, warning signs, results, follow-up, goals, preferences, plain language, and teach-back.
- [ASTP/ONC USCDI goals and preferences](https://isp.healthit.gov/uscdi-data-class/goals-and-preferences) — patient goals, care-experience preferences, and treatment preferences should remain explicit data concepts.
- HL7 FHIR R4: [CareTeam](https://hl7.org/fhir/R4/careteam.html), [Goal](https://hl7.org/fhir/r4/goal.html), [Task](https://hl7.org/fhir/R4/task.html), [Communication](https://www.hl7.org/fhir/R4/communication.html), [Consent](https://hl7.org/fhir/R4/consent.html), [QuestionnaireResponse](https://hl7.org/fhir/R4/questionnaireresponse.html), and [Appointment](https://hl7.org/fhir/R4/appointment.html).

---

## 19. Immediate next actions

1. Ratify the ledger dispositions and named owners; approve the patient disclosure matrix, urgent-help language, messaging policy, pilot boundaries, and Hummingbird-image source/license/attribution. None of these engineering foundations constitutes governance approval.
2. Review the local-only patient messaging and staff-bridge migrations (`000300` and `000400`) as a controlled release unit. Prepare backup, rollback, scheduler, queue, encryption-key, audit, and missing-schema runbooks before any environment change; keep every patient and staff-messaging feature flag off during deployment validation.
3. The automated accountable-routing lifecycle—workforce shift release and coverage-gap reroute, **unit** transfer, discharge close, pool-downtime reroute, and their content-free immutable audit/event facts—is implemented behind feature gates as the scheduled `hummingbird:reconcile-patient-communications` run and covered by passing local tests, including the no-eligible-destination degraded-`unresolved` invariant. Web, iOS, and Android reconcile a server-driven omission or retained-row transition by purging stale sensitive state; Android also synchronously rechecks lifecycle/lock eligibility at the timer boundary. Service-change routing remains explicitly unimplemented until an approved authoritative service-ownership input exists. Production enablement, patient push, and deployed E2E remain.
4. Complete generic patient/staff device registration and push delivery: add native token acquisition only after provider review, provider invalid-token feedback, revocation propagation, redaction, collapse/expiry, preference, and deep-link authorization tests. The patient device API contract exists but is default-off and has no delivery provider or native caller. No notification may contain message text or patient context by default.
5. Replace deterministic/reference projections with approved production source adapters, reconciliation workers, projection lag/error telemetry, replay, correction/retraction, and dead-letter recovery. Complete patient identity proofing/federation, recovery, MFA/passkeys, and representative/delegation policy separately.
6. Select one pilot facility and two inpatient units; configure responsibility pools and current-shift membership from an authoritative workforce source; staff the response desk; and tabletop urgent, unowned, handoff, downtime, transfer, discharge, and open-thread scenarios.
7. Run live-like end-to-end tests through the deployed API boundary for enrollment, Today/My Path/Care Team, session revocation, patient compose, outbox consumption, staff For You/inbox, route candidates, claim, reply, close, release, reassign, reroute, escalation, and patient-visible receipt state. Contract fixtures and local database tests do not replace this gate.
8. Complete independent privacy/security, clinical-safety, accessibility, language-access, and patient/family usability reviews. Measure comprehension and calmness over the approved Hummingbird backgrounds at large text, high contrast, reduced transparency/motion, and screen-reader settings.
9. Enable only the smallest approved pilot cohort behind server-side kill switches after go/no-go evidence is signed. Treat patient Eddy, attachments, proxy access, offline messaging, and broader-scale rollout as separately governed later releases.
