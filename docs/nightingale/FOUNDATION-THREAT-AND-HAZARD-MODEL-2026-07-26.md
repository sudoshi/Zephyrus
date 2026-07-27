# Nightingale foundation threat and clinical-hazard model

| Field                       | Value                                                                                                                                                                             |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Document status             | **Draft engineering model; not an approved safety case or release authorization**                                                                                                 |
| Assessment date             | 2026-07-26                                                                                                                                                                        |
| Executable baseline         | `80c29e9ff5a745e50e7efdca4c2ac9c3a3df8091`                                                                                                                                        |
| Product                     | Nightingale, the independent inpatient-facing product                                                                                                                             |
| Current exposure            | Offline native foundation; no live patient access, network client, identity, patient route, source query, disclosure, mutation, messaging, notification, or production activation |
| Intended future audience    | Inpatients and separately approved representatives                                                                                                                                |
| Engineering owner           | Patient Experience Platform                                                                                                                                                       |
| Required independent owners | Clinical safety, privacy, security, identity, data governance, content, accessibility, language, support/operations, and release                                                  |
| Named approvals recorded    | **None**                                                                                                                                                                          |
| Next mandatory review       | Before any route, network client, identity provider, source adapter, patient data, notification channel, messaging operation, non-production integration, or pilot is enabled     |

This model is intentionally strict. A threat or hazard marked **blocked by non-activation**
has not been made safe for live use; it is prevented only because the relevant capability
does not exist or cannot run. Removing that barrier without satisfying the named gates is a
release-blocking defect.

## 1. Executive disposition

### 1.1 Current decision

The current Nightingale foundation is acceptable only as an offline, no-patient-data
application shell and governance substrate. It is **not acceptable for patient access,
clinical projection, care-team communication, notifications, representatives, or pilot
release**.

Current executable barriers are unusually strong because:

- the Android manifest requests no `INTERNET` permission;
- Android also explicitly denies cleartext traffic, trusts system certificate authorities
  only, and contains no debug trust override;
- the iOS application contains no network client;
- the iOS Release bundle carries an exact offline-foundation privacy manifest with no
  tracking or collected-data declaration and only app-local `UserDefaults` reason
  `CA92.1`;
- the Nightingale OpenAPI foundation has zero paths and a non-routable `.invalid` server;
- Laravel registers no Nightingale route and binds no identity or inpatient-source port;
- every configuration and contract activation field is false;
- native product-boundary constants deny live patient and staff-endpoint access;
- local storage contains only two nonclinical presentation preferences;
- protected-state implementations are dormant and tested only with synthetic canaries; and
- no production database, patient, principal, encounter, message, or clinical projection
  was used to prepare or verify this model.

These barriers reduce current exposure; they do not close the inherent high and critical
risks of a future inpatient application.

### 1.2 Safety conclusion

The most consequential future hazards are:

1. disclosure to the wrong patient or representative;
2. display of the wrong encounter or stale, unreleased, corrected, or retracted clinical
   information;
3. false assurance that a patient message was delivered, monitored, or escalated;
4. privacy loss on a shared, lost, captured, backed-up, or compromised device;
5. inaccessible or misunderstood information causing delayed questions or care;
6. cross-product leakage from Hummingbird staff capabilities into Nightingale; and
7. activation without an auditable kill switch, incident process, rollback, and named
   clinical/privacy/security approval.

All seven remain activation-blocking.

## 2. Method and authoritative inputs

This document combines:

- **NIST Cybersecurity Framework 2.0** as a high-level Govern, Identify, Protect, Detect,
  Respond, and Recover outcome taxonomy:
  [NIST CSF 2.0](https://www.nist.gov/publications/nist-cybersecurity-framework-csf-20);
- **OWASP Mobile Application Security Verification Standard** as a mobile control-domain
  checklist for storage, cryptography, authentication, network, platform, code,
  resilience, and privacy:
  [OWASP MASVS](https://mas.owasp.org/MASVS/);
- **HHS Office for Civil Rights risk-analysis guidance** for confidentiality, integrity,
  and availability of electronic protected health information:
  [HHS Final Guidance on Risk Analysis](https://www.hhs.gov/hipaa/for-professionals/security/guidance/final-guidance-risk-analysis/index.html);
- **HHS mobile-device privacy guidance** as a reminder that health data on personal devices
  creates privacy exposure beyond the application's direct control:
  [HHS mobile privacy guidance](https://www.hhs.gov/hipaa/for-professionals/privacy/guidance/cell-phone-hipaa/index.html);
- **NHS DCB0129/DCB0160 lifecycle concepts** as a clinical-risk reference for
  proportionate processes, maintained safety documentation, supplier/deployer
  responsibilities, and named clinical safety ownership:
  [NHS England 2026 supporting information](https://www.england.nhs.uk/long-read/national-review-of-clinical-risk-management-standardsdcb0129-and-dcb0160-supporting-information/);
- STRIDE categories for security threat enumeration; and
- repository-specific misuse cases, clinical failure modes, platform behavior, and
  activation controls.

These sources are methodological inputs. This model does **not** claim NIST, MASVS, HIPAA,
DCB0129, DCB0160, medical-device, privacy-law, or other regulatory compliance. Applicability
and conformity require independent legal, privacy, security, and clinical review.

## 3. Assessment rules

### 3.1 Severity

| Level           | Security/privacy consequence                                                                      | Patient-safety consequence                                                                 |
| --------------- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| S1 — negligible | No sensitive data affected; quickly reversible inconvenience                                      | No plausible injury; transient inconvenience                                               |
| S2 — minor      | Limited nonclinical exposure or short-lived loss of service                                       | Temporary confusion or delay with an obvious workaround                                    |
| S3 — moderate   | Limited patient-data exposure, integrity loss, or material service interruption                   | Temporary harm, delayed care, or additional intervention may occur                         |
| S4 — major      | Broad/sensitive disclosure, persistent account compromise, or serious integrity/availability loss | Serious temporary harm, preventable deterioration, or prolonged intervention               |
| S5 — severe     | Catastrophic confidentiality/integrity failure or systemic compromise                             | Death, permanent harm, severe deterioration, or failure to obtain urgent care is plausible |

### 3.2 Likelihood if activated without the stated gates

| Level         | Meaning                                                                    |
| ------------- | -------------------------------------------------------------------------- |
| L1 — rare     | Requires exceptional conditions and multiple independent failures          |
| L2 — unlikely | Credible but not expected in ordinary operation                            |
| L3 — possible | Can occur through a realistic defect, outage, misuse, or workflow mismatch |
| L4 — likely   | Expected repeatedly without a dedicated control                            |
| L5 — frequent | Inherent or routine when the capability is used                            |

### 3.3 Activation-risk score

`Activation risk = severity × likelihood`

| Score | Rating   | Required disposition                                                                                                            |
| ----- | -------- | ------------------------------------------------------------------------------------------------------------------------------- |
| 20–25 | Critical | Capability remains disabled; named executive, clinical, privacy, security, and release authorities must review closure evidence |
| 12–19 | High     | Capability remains disabled until verified controls, owner, residual-risk decision, and rollback exist                          |
| 6–11  | Moderate | Control and test evidence required before release; any acceptance is time-bound and named                                       |
| 1–5   | Low      | Track and verify; low never means exempt from privacy or clinical review                                                        |

The score estimates risk **if the relevant capability were activated without its gates**.
Current exposure is reported separately:

- `blocked`: executable non-activation prevents the scenario today;
- `present-foundation`: the exposure exists in the current offline shell;
- `future-design`: the component is only a candidate and has no runtime path; or
- `external`: the exposure is partly controlled by the device, operating system,
  institution, or patient environment.

No numerical “residual risk” is accepted by this document.

## 4. System and data-flow model

### 4.1 Current executable flow

```text
Patient or tester
      |
      v
Nightingale iOS / Android offline foundation
      |                         |
      |                         +--> OS accessibility and lifecycle signals
      |
      +--> two nonclinical, device-local presentation preferences
      |
      +--> dormant protected-state primitive
             (synthetic test canary only; no production caller)

NO EDGE --> network, Zephyrus API, identity provider, production database,
            inpatient source, care team, message router, push provider, or analytics

Laravel repository foundation
      |
      +--> unbound identity and inpatient-source interfaces
      +--> fail-closed precondition types
      +--> zero registered Nightingale routes and zero OpenAPI operations
```

### 4.2 Future conceptual flow under review

The following is a threat-model boundary, not an implementation design:

```text
Personal/shared device
  -> platform app and local protected state
  -> network edge/API gateway
  -> independent Nightingale identity/session service
  -> principal-to-patient and representative authority
  -> encounter-access and current-inpatient gates
  -> released patient projection service
  -> authoritative clinical/operational sources
  -> governed care-team routing and messaging
  -> notification provider with minimized payloads
  -> append-only audit, detection, support, incident, and rollback services
```

Every arrow introduces a trust boundary that is currently absent and must receive its own
contract, authorization tests, abuse cases, telemetry policy, failure behavior, and
rollback.

### 4.3 Repository evidence

- [Empty/default-off OpenAPI foundation](./api-contract/nightingale-foundation.v0.json)
- [Route, compatibility, identity, and source ADR](./ROUTE-COMPATIBILITY-IDENTITY-SOURCE-ADR-2026-07-26.md)
- [Contract ownership and authorization matrix](./CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md)
- [Identity/session/recovery/source held candidate](./IDENTITY-SESSION-RECOVERY-AND-SOURCE-CANDIDATE-DECISION-2026-07-26.md)
- [Encounter-access held candidate](./ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md)
- [Today projection held candidate](./TODAY-PROJECTION-CANDIDATE-DECISION-2026-07-26.md)
- [Presentation-preferences foundation decision](./PRESENTATION-PREFERENCES-FOUNDATION-DECISION-2026-07-26.md)
- [Accessibility/layout matrix](./FOUNDATION-ACCESSIBILITY-LAYOUT-MATRIX-2026-07-26.md)
- [Laravel default-deny configuration](../../config/nightingale.php)
- [iOS application root](../../nightingale/iosApp/Nightingale/NightingaleApp.swift)
- [Android application activity](../../nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/MainActivity.kt)

## 5. Protected assets

| Asset ID | Asset                                    | Required property                                                               | Current state                                           |
| -------- | ---------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------- |
| AST-01   | Independent Nightingale product identity | No Hummingbird/staff realm, endpoint, storage, bundle, or role confusion        | Implemented and mechanically checked                    |
| AST-02   | Patient principal and proofing result    | Authentic, self-only by default, purpose-bound, revocable                       | Not implemented                                         |
| AST-03   | Representative authority                 | Legally/operationally valid, scoped, visible, revocable, non-inferential        | Not implemented; prohibited                             |
| AST-04   | Session binding and token family         | Confidential, short-lived, rotated, replay-resistant, revocable                 | Dormant storage descriptor only                         |
| AST-05   | Principal-to-patient link                | Correct, current, conflict-detected, auditable                                  | Not implemented                                         |
| AST-06   | Current inpatient context                | Authoritative, encounter-specific, fresh, contradiction-aware                   | Held candidate; no adapter/query                        |
| AST-07   | Encounter-access grant                   | Self-only initial cardinality, scope/purpose/effective-window enforced          | Held candidate; no operation                            |
| AST-08   | Patient-visible clinical projection      | Released, field-governed, fresh, understandable, correctable/retractable        | Held Today candidate; no operation                      |
| AST-09   | Care-team directory and routing          | Current, accountable, service-window and escalation aware                       | Not implemented                                         |
| AST-10   | Patient messages and attachments         | Confidential, ordered, idempotent, deliverable, amendable, auditable            | Not implemented                                         |
| AST-11   | Notification metadata/payload            | Minimized, non-revealing, revocable, device-aware                               | Not implemented                                         |
| AST-12   | Audit and security telemetry             | Complete, tamper-evident, access-controlled, privacy-minimized                  | Not implemented                                         |
| AST-13   | Local presentation preferences           | Nonclinical, Nightingale-only, non-roaming                                      | Implemented                                             |
| AST-14   | Future volatile composition              | Process-lifetime only, cleared at sensitive transitions                         | Primitive implemented; no live input UI                 |
| AST-15   | Protected local state                    | Device-bound, corrupt-state fail-closed, deletable                              | Dormant primitives implemented                          |
| AST-16   | Signing/build/release/media artifacts    | Provenance, exact SHA, no Debug hooks, licensed assets, authorized distribution | Local code/media lineage only; no distribution approval |
| AST-17   | Support and recovery evidence            | Authentic, least-privilege, non-disclosing, auditable                           | Not implemented                                         |
| AST-18   | Clinical safety and content approvals    | Named, versioned, scoped, expiring, reviewable                                  | None recorded                                           |

## 6. Actors and attacker assumptions

### 6.1 Legitimate actors

- inpatient using a personal, shared, borrowed, accessibility-managed, or institution-owned
  device;
- separately approved representative;
- bedside nurse, physician, pharmacist, care manager, interpreter, and other care-team
  roles;
- support, privacy, security, clinical-safety, content, data-governance, and release staff;
- identity, EHR, ADT, FHIR, messaging, push, audit, and app-store/platform providers; and
- automated build, test, signing, and deployment systems.

### 6.2 Threat actors and failure sources

- another person with physical or logical access to the patient's device;
- an unauthorized or formerly authorized representative;
- a remote attacker using credential stuffing, phishing, replay, session theft, API
  enumeration, or denial of service;
- malware, overlay, instrumentation, rooted/jailbroken devices, or compromised operating
  systems;
- malicious or vulnerable third-party dependencies, SDKs, build runners, signing systems,
  or distribution accounts;
- an insider exceeding legitimate purpose or scope;
- accidental staff misrouting, wrong-patient linkage, source-data error, release error, or
  support disclosure;
- network, identity, source, notification, messaging, or time-synchronization failure; and
- product defects, ambiguous language, accessibility barriers, retry races, partial
  responses, or stale offline state.

### 6.3 Assumptions not permitted

Nightingale must not assume that:

- possession of a device proves patient identity;
- a platform biometric proves the correct patient relationship;
- one source row proves the current encounter;
- a successful HTTP response proves content release, freshness, or clinical correctness;
- a submitted message is delivered, read, monitored, or appropriate for urgent care;
- a representative remains authorized;
- the device is private, uncompromised, online, correctly timed, or backup-free;
- an absent section means that nothing is planned;
- patients understand internal clinical/operational vocabulary;
- staff and patient product identifiers are interchangeable; or
- a green automated test constitutes clinical, privacy, accessibility, or release approval.

## 7. Trust boundaries

| Boundary ID | Boundary                                            | Primary threats                                                      | Current status                          |
| ----------- | --------------------------------------------------- | -------------------------------------------------------------------- | --------------------------------------- |
| TB-01       | Patient/other person to unlocked device             | shoulder surfing, shared-device disclosure, coercion, mistaken user  | Present-foundation                      |
| TB-02       | App UI to local preference storage                  | tampering, backup/sync, clinical-state confusion                     | Present-foundation; nonclinical only    |
| TB-03       | App to Keychain/Keystore/private ciphertext         | theft, corruption, replay, deletion failure, key loss                | Dormant synthetic use only              |
| TB-04       | App lifecycle to task switcher/screenshot/recording | captured PHI, stale visible UI                                       | Present-foundation; platform-asymmetric |
| TB-05       | App package to OS/runtime                           | tampering, hooks, overlays, debug artifacts, vulnerable dependencies | Present-foundation                      |
| TB-06       | Device to network edge                              | interception, downgrade, traffic analysis, outage                    | Absent; future-design                   |
| TB-07       | Edge to identity/session service                    | spoofing, replay, token substitution, confused realm                 | Absent; future-design                   |
| TB-08       | Identity to patient/representative linkage          | wrong patient, stale authority, privilege escalation                 | Absent; future-design                   |
| TB-09       | Access service to current-inpatient source          | wrong encounter, stale/contradictory status, source outage           | Absent; future-design                   |
| TB-10       | Source to released projection                       | unreleased/internal/stale/corrected content                          | Absent; future-design                   |
| TB-11       | Patient message to care-team router                 | wrong team, duplication, loss, urgent-message misuse                 | Absent; future-design                   |
| TB-12       | Backend to push provider/lock screen                | PHI disclosure, token reuse, delayed/duplicate notification          | Absent; future-design                   |
| TB-13       | Runtime to audit/telemetry/support                  | missing events, sensitive logging, insider access, repudiation       | Absent; future-design                   |
| TB-14       | Source/build to signed store artifact               | dependency compromise, secret injection, wrong signing identity      | Local CI only; distribution absent      |

## 8. Implemented foundation-control catalog

| Control ID | Implemented control                                                                                                                                | Evidence                                                         | Claim boundary                                                                            |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| CTRL-001   | Independent `net.acumenus.nightingale` native identity and namespaces                                                                              | Native projects, protected/preference namespaces                 | Does not create an independent identity realm or provider                                 |
| CTRL-002   | Zero OpenAPI paths, empty components/security, `.invalid` server                                                                                   | `nightingale-foundation.v0.json` and verifier                    | Does not prove a future operation is safe                                                 |
| CTRL-003   | All backend/network/identity/source/disclosure/mutation/production flags false                                                                     | `config/nightingale.php` and backend verifier                    | Flags are not a future runtime kill switch                                                |
| CTRL-004   | Unconfigured identity and inpatient-source implementations return unavailable                                                                      | `app/Nightingale/**` and unit tests                              | No container binding or HTTP caller exists                                                |
| CTRL-005   | Precondition gate only permits later evaluation; it never grants access                                                                            | `NightingaleEncounterAccessPreconditionGate.php`                 | Later grant, purpose, resource, release, and audit gates are absent                       |
| CTRL-006   | Native compile-time live-access and staff-endpoint constants are false                                                                             | iOS/Android product boundaries                                   | Constants are defense-in-depth, not server authorization                                  |
| CTRL-007   | Android requests no `INTERNET` permission                                                                                                          | Android manifest, Gradle verifier, APK inspection                | iOS has no equivalent manifest permission model                                           |
| CTRL-008   | Android `FLAG_SECURE` blocks ordinary screenshots/recordings                                                                                       | `NightingalePrivacyPolicy.kt`, instrumentation evidence          | Does not defeat cameras, compromised OS, overlays, or every capture path                  |
| CTRL-009   | iOS covers content whenever the scene is inactive                                                                                                  | `NightingalePrivacyProtectedRoot` and XCUITest                   | Does not block active-screen screenshots or physical observation                          |
| CTRL-010   | Android covers content on pause and clears volatile input                                                                                          | `MainActivity.onPause`                                           | Lifecycle callbacks are not a compromised-runtime defense                                 |
| CTRL-011   | iOS Keychain uses data-protection Keychain, non-synchronizable, `WhenUnlockedThisDeviceOnly`                                                       | `NightingaleProtectedState.swift`                                | Dormant; no real session format, biometric binding, or threat approval                    |
| CTRL-012   | Android Keystore uses AES-256-GCM, randomized 12-byte IV, 128-bit tag, AAD, private ciphertext                                                     | `NightingaleProtectedState.kt`                                   | Key does not currently require user authentication                                        |
| CTRL-013   | Android write failure removes prior key/ciphertext; deletion verifies both                                                                         | Protected-state tests                                            | Does not prove behavior on all OEM, backup, restore, or device-compromise states          |
| CTRL-014   | Empty protected values are rejected and corrupt/unavailable storage fails closed                                                                   | Native stores and tests                                          | No recovery/support UX exists                                                             |
| CTRL-015   | Android backup and device transfer are disabled/excluded                                                                                           | Manifest plus backup/data-extraction rules                       | External device/OS/vendor behavior still requires release verification                    |
| CTRL-016   | Presentation preferences are nonclinical, product-local, and non-roaming                                                                           | Native preference code and decision record                       | They are not consent, account, clinical, or accessibility-profile records                 |
| CTRL-017   | Volatile drafts clear on inactive/logout/identity/recovery/revocation/removal boundaries                                                           | Native state primitives and tests                                | Immutable `String` memory is not reliably zeroized                                        |
| CTRL-018   | Debug test hooks are compile-time or build-boundary constrained and absent from Release artifacts                                                  | Native source, binary/APK scans                                  | Future diagnostic/logging code requires separate review                                   |
| CTRL-019   | Accessibility policy honors stronger system/patient reduced-motion and imagery conditions                                                          | Presentation policy tests                                        | Does not establish full WCAG or assistive-technology conformance                          |
| CTRL-020   | Minimum targets, semantic order, reflow, contrast, and true landscape are bounded and tested                                                       | Accessibility/layout matrix                                      | Covers only the current offline shell                                                     |
| CTRL-021   | Hummingbird/legacy identifiers and staff endpoints are mechanically prohibited in Nightingale source                                               | Product-boundary verifier                                        | Future shared libraries/adapters need explicit architectural review                       |
| CTRL-022   | Candidate fixtures fail closed on unknown, contradictory, stale, or unreleased states                                                              | Candidate documents and self-testing verifiers                   | Candidates are non-runnable and not clinically approved                                   |
| CTRL-023   | Exact-SHA CI and scoped publication evidence are retained                                                                                          | Draft PR and devlog                                              | No distribution signing, store, deployment, or pilot evidence exists                      |
| CTRL-024   | No production database work is authorized by this product stream                                                                                   | Plan, contract, and activation holds                             | A future source integration needs separate, path-scoped authorization                     |
| CTRL-025   | Exact seven-photo catalog is metadata-stripped, nonessential, cross-platform deterministic, and hidden under stronger contrast/transparency policy | Asset manifest/verifier, native UI/tests, Release artifact scans | Durable rights/attribution and human patient review remain open                           |
| CTRL-026   | Exact iOS offline privacy manifest plus Android cleartext denial, system-only trust, no network permission, and backup exclusion                   | Privacy-control record, native tests, Release artifact scans     | Store declarations, future data flows, signed artifacts, and privacy approval remain open |

## 9. Security and privacy threat register

| Threat ID   | STRIDE/domain          | Scenario                                                                                                     | Activation risk     | Current exposure/control                                                  | Required closure before exposure                                                                 |
| ----------- | ---------------------- | ------------------------------------------------------------------------------------------------------------ | ------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| THR-S-001   | Spoofing               | Attacker authenticates as an inpatient through weak enrollment, credential theft, replay, or realm confusion | S5×L4 = 20 Critical | Blocked: no identity/provider/session/network                             | GATE-03 through GATE-07; adversarial identity tests and penetration test                         |
| THR-S-002   | Spoofing               | Representative claims, retains, or expands authority without valid patient/legal basis                       | S5×L3 = 15 High     | Blocked; representatives prohibited                                       | GATE-05, relationship lifecycle, patient visibility, revocation, legal/privacy approval          |
| THR-S-003   | Spoofing               | Device possession or local biometric is treated as sufficient server identity                                | S5×L3 = 15 High     | Blocked; no local auth path                                               | Server-side proof, session binding, step-up policy, lost/shared-device cases                     |
| THR-T-001   | Tampering              | Protected local state is modified, replayed, rolled back, or paired with the wrong key/ciphertext            | S4×L3 = 12 High     | Dormant Keychain/Keystore controls; corruption fails closed               | Session format, anti-replay binding, restore/upgrade tests, mobile penetration test              |
| THR-T-002   | Tampering              | API response, cache, or source payload is modified or partially substituted                                  | S5×L3 = 15 High     | Blocked: no client/API/cache                                              | TLS, server authorization, schema integrity, complete-response validation, no partial trust      |
| THR-T-003   | Tampering              | Repackaged, hooked, debugged, or overlaid app changes patient-visible content or captures input              | S5×L3 = 15 High     | Platform defaults; Android secure flag; no attestation/resilience policy  | GATE-16, signed-distribution evidence, tamper posture, overlay/input review                      |
| THR-R-001   | Repudiation            | Patient, representative, staff, source, or system action cannot be reconstructed                             | S4×L3 = 12 High     | Blocked; no live actions and no audit service                             | Append-only event model, actor/purpose/correlation, retention/access policy, clock strategy      |
| THR-I-001   | Information disclosure | PHI appears in screenshot, recording, task switcher, shoulder view, camera, or shared device                 | S4×L4 = 16 High     | Present-foundation UI has no PHI; Android secure flag; lifecycle covers   | GATE-13/14/16, iOS capture decision, privacy UX, physical/shared-device testing                  |
| THR-I-002   | Information disclosure | PHI enters logs, crash reports, analytics, metrics, support tools, or diagnostics                            | S4×L4 = 16 High     | Blocked: no PHI/telemetry design                                          | Data-flow inventory, field allowlist, redaction tests, vendor contracts, access/retention policy |
| THR-I-003   | Information disclosure | Source identifiers, encounter IDs, staff internals, inferred risk, or unreleased notes reach the client      | S5×L3 = 15 High     | Candidate contracts prohibit source/cross-product identifiers             | Projection allowlist, serialization negative tests, code review, clinical/privacy approval       |
| THR-I-004   | Information disclosure | Push content or device token links a patient to diagnosis, location, or care                                 | S4×L4 = 16 High     | Blocked: no notifications/provider                                        | GATE-13, minimized generic payload, token lifecycle, lock-screen review, delivery tests          |
| THR-I-005   | Privacy                | Backup, cloud sync, device transfer, clipboard, keyboard, or OS service retains sensitive state              | S4×L3 = 12 High     | Android backup excluded; iOS protected state device-only; drafts not live | Platform release tests, input-class policy, clipboard/keyboard prohibition, deletion proof       |
| THR-I-006   | Privacy                | Third-party SDK or advertising/tracking identifier observes health-app behavior                              | S4×L3 = 12 High     | No application analytics/ad SDK identified; platform libraries only       | Dependency/SDK allowlist, privacy manifest/declarations, network observation, vendor review      |
| THR-D-001   | Denial of service      | Credential stuffing, enumeration, abusive polling, or message flooding exhausts service                      | S4×L4 = 16 High     | Blocked: no service                                                       | Rate limits by purpose/principal/device/network, abuse detection, safe errors, capacity tests    |
| THR-D-002   | Availability           | Identity, source, network, push, messaging, or audit outage produces blank or misleading UI                  | S5×L3 = 15 High     | Current UI explicitly says access unavailable                             | State-specific downtime/offline UX, cached-content policy, monitoring, support and rollback      |
| THR-E-001   | Elevation/IDOR         | Principal changes opaque handles or calls another patient's encounter/thread/resource                        | S5×L4 = 20 Critical | Blocked: zero operations                                                  | GATE-06/07/08, object-level authorization, cross-patient negatives, no existence oracle          |
| THR-E-002   | Elevation              | Client-side flags or local state are treated as authorization                                                | S5×L3 = 15 High     | No server operation; docs prohibit local authorization                    | Server-side lattice on every read/write, fail-closed defaults, mutation/IDOR tests               |
| THR-E-003   | Elevation              | Debug hook, synthetic API, hidden route, or test credential escapes into Release                             | S5×L2 = 10 Moderate | Release scans and zero-route verifiers                                    | Continue artifact scans; distribution artifact/SBOM inspection and signing provenance            |
| THR-CFG-001 | Misconfiguration       | Feature/configuration change activates route, query, disclosure, or client independently                     | S5×L3 = 15 High     | Code-owned false fields; no route/client/provider                         | Multi-party activation manifest, interlocked gates, environment-diff proof, kill switch          |
| THR-SC-001  | Supply chain           | Dependency, build runner, package registry, signing key, or store account is compromised                     | S5×L3 = 15 High     | Exact-SHA CI; generated source-hash-bound Release runtime inventory       | GATE-16, artifact verification/provenance, vulnerability policy, signing/access audit            |
| THR-OPS-001 | Social engineering     | Support/recovery staff disclose patient status or reset access for an attacker                               | S5×L3 = 15 High     | Blocked: no recovery/support flow                                         | GATE-04/15, scripts, proofing, dual control, non-disclosure, audit, red-team cases               |
| THR-INS-001 | Insider                | Authorized staff accesses, routes, exports, or alters patient data without purpose                           | S5×L3 = 15 High     | Blocked: no Nightingale patient operations                                | Least privilege, purpose-of-use, access review, anomaly detection, sanctions and audit           |

## 10. Clinical hazard log

| Hazard ID | Hazardous situation and initiating causes                                                                               | Plausible harm                                                                    | Activation risk     | Current barrier                            | Required control/evidence                                                                                       |
| --------- | ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ------------------- | ------------------------------------------ | --------------------------------------------------------------------------------------------------------------- |
| HZ-001    | Wrong principal is linked to a real patient through proofing, linkage, merge, or support error                          | Severe privacy breach; incorrect action based on another patient's care           | S5×L3 = 15 High     | No identity/linkage                        | Independent proofing/linkage design, conflict handling, cross-patient tests, named identity/privacy approval    |
| HZ-002    | Correct principal receives the wrong encounter because first-row, stale ADT, facility, class, or overlap logic is wrong | Misunderstanding care plan/location/discharge; delayed questions or care          | S5×L3 = 15 High     | No source adapter/query                    | Authoritative current-inpatient rule, contradiction state, cardinality policy, freshness, synthetic integration |
| HZ-003    | Released information is stale but presented as current                                                                  | Patient follows outdated schedule, location, medication, or discharge expectation | S5×L3 = 15 High     | No projection                              | Per-field source time, approved thresholds, explicit stale/withhold state, outage tests                         |
| HZ-004    | Corrected or retracted information remains visible or cached                                                            | Patient acts on withdrawn or erroneous information                                | S5×L3 = 15 High     | No projection/cache                        | Append-only correction lineage, retraction withhold, cache purge, notification and audit tests                  |
| HZ-005    | Draft, review, internal note, inferred risk, source identifier, or staff-only content reaches the patient               | Distress, privacy harm, misinterpretation, altered care relationship              | S5×L3 = 15 High     | Zero operations; candidate allowlists      | Released-projection service, deny-by-default serializer, field approvals, negative fixtures                     |
| HZ-006    | Translation, terminology, reading level, or uncertainty language changes clinical meaning                               | Misunderstanding, delayed escalation, inappropriate expectations                  | S5×L3 = 15 High     | No clinical content                        | Clinical + language approval per field, interpreter path, versioned copy, comprehension/usability evidence      |
| HZ-007    | Empty/unavailable/withheld section appears to mean “nothing is planned”                                                 | Patient fails to ask about missing care, tests, or discharge work                 | S4×L4 = 16 High     | Current shell says live access unavailable | Explicit state vocabulary, reason-safe explanation, contact path, comprehension tests                           |
| HZ-008    | Messaging UI implies urgent messages are monitored or substitutes for emergency help                                    | Delay in urgent assessment or emergency treatment                                 | S5×L4 = 20 Critical | No messaging operation                     | Urgent-use policy, prominent reviewed guidance, blocked urgent categories, service windows, escalation proof    |
| HZ-009    | Submitted/accepted message is described as delivered, seen, or actioned                                                 | Patient waits for a response that will not occur                                  | S5×L3 = 15 High     | No messaging                               | State vocabulary tied to end-to-end evidence, delivery/read semantics, timeout and fallback                     |
| HZ-010    | Ambiguous network retry duplicates a message, amendment, closure, or request                                            | Conflicting work, repeated interventions, confusion, privacy exposure             | S4×L3 = 12 High     | No mutation                                | Idempotency keys, stable command outcome, retry/reconciliation tests, append-only audit                         |
| HZ-011    | Message routes to the wrong team, inactive pool, or unaffiliated staff member                                           | Delay, non-response, disclosure, wrong advice                                     | S5×L3 = 15 High     | No routing/team directory                  | Accountable routing owner, membership/facility/encounter checks, service window and failover                    |
| HZ-012    | Discharged, transferred, deceased, merged, or closed context remains actionable                                         | Disclosure or advice disconnected from current care                               | S5×L3 = 15 High     | Source state cannot be current             | Real-time revocation/closure semantics, race tests, read/write reauthorization                                  |
| HZ-013    | Representative sees content outside approved relationship/scope or after revocation                                     | Privacy harm, coercion, unsafe family dynamics, loss of trust                     | S5×L3 = 15 High     | Representatives prohibited                 | Legal/privacy model, granular scope, sensitive-content exceptions, patient visibility/revoke                    |
| HZ-014    | Screen-reader order, focus, target size, contrast, language expansion, or motor access fails                            | Patient cannot understand or communicate, causing delay or exclusion              | S4×L4 = 16 High     | Bounded offline-shell tests only           | Full WCAG/platform matrix, human AT testing, language/RTL, physical-device and patient review                   |
| HZ-015    | PHI is visible on lock screen, task switcher, screenshot, shared/lost device, or nearby observer                        | Privacy breach, stigma, coercion, identity theft                                  | S4×L4 = 16 High     | No PHI; partial platform controls          | Minimized UI/push, reauthentication, capture posture, privacy mode, lost-device response                        |
| HZ-016    | Device compromise or repackaged app alters patient-visible content                                                      | Patient trusts malicious or false clinical/communication information              | S5×L2 = 10 Moderate | No live data; store distribution absent    | Signing/provenance, update/integrity posture, server trust, compromise response, user guidance                  |
| HZ-017    | Offline/outage state shows cached data without age/source or silently drops actions                                     | Patient believes stale information or communication is current                    | S5×L3 = 15 High     | No cache/network/action                    | Approved offline policy per field/action, timestamps, no offline mutation unless reconciled                     |
| HZ-018    | Partial/malformed response is rendered instead of withheld                                                              | Mixed-patient or internally inconsistent page appears authoritative               | S5×L3 = 15 High     | No client decoder                          | Atomic validation, unknown-enum withholding, schema negatives, no partially trusted object                      |
| HZ-019    | Clinical/operational source is unavailable or contradictory but adapter guesses current state                           | Wrong encounter/content disclosed                                                 | S5×L3 = 15 High     | Unavailable/inconsistent fail closed       | Reconciliation rules, authoritative ownership, contradiction alerting, no guessed thresholds                    |
| HZ-020    | Patient or staff over-relies on Nightingale as the complete legal/clinical record                                       | Missing context is treated as definitive                                          | S4×L3 = 12 High     | Foundation denies live access              | Product wording, scope disclosure, source/correction path, training, patient comprehension                      |
| HZ-021    | Recovery/identity transition leaves old protected state or draft bound to a new principal                               | Cross-account disclosure or action                                                | S5×L3 = 15 High     | No live identity; deletion primitives      | Transactional identity switch, verified deletion, new realm/session, failure-withhold tests                     |
| HZ-022    | Kill switch, rollback, or correction cannot propagate quickly during an incident                                        | Continued exposure or harmful guidance at scale                                   | S5×L3 = 15 High     | No live capability                         | GATE-15/17, tested remote disable/withhold, rollback SLO, incident tabletop                                     |

No hazard above is accepted. The current disposition for every high/critical live-data
hazard is **keep the capability disabled**.

## 11. Abuse and misuse cases

| Abuse ID  | Attempt                                                                                                    | Required safe result                                                                                               |
| --------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| ABUSE-001 | Change an encounter/thread/message handle to another value                                                 | Withhold without confirming resource existence; audit the denied attempt                                           |
| ABUSE-002 | Replay an expired, rotated, revoked, or already-used session binding                                       | Deny, delete unusable local state where appropriate, require safe recovery                                         |
| ABUSE-003 | Present a Hummingbird staff token or legacy patient token to Nightingale                                   | Reject realm/product mismatch; never alias, proxy, redirect, or silently migrate                                   |
| ABUSE-004 | Enroll using a room number, date of birth, shared email, or easily known fact                              | Fail proofing; rate-limit and avoid revealing whether a patient exists                                             |
| ABUSE-005 | Support caller claims to be a patient/representative and asks whether someone is admitted                  | Disclose neither identity nor admission; follow approved proofing/escalation                                       |
| ABUSE-006 | Revoked representative continues using cached credentials                                                  | Deny every read/write after revocation and purge/withhold cached content                                           |
| ABUSE-007 | Send urgent symptoms through a general message topic                                                       | Prevent false monitoring assurance; show approved urgent/emergency direction                                       |
| ABUSE-008 | Toggle airplane mode immediately after sending                                                             | Reconcile deterministic command state; never label ambiguous submission delivered                                  |
| ABUSE-009 | Rapidly retry send/close/amend commands                                                                    | Apply idempotency/concurrency rules; no duplicate or destructive lost update                                       |
| ABUSE-010 | Inject unknown enum, missing release field, mixed encounter handle, or malformed item                      | Reject/withhold the complete affected projection; log safe diagnostic metadata                                     |
| ABUSE-011 | Capture, mirror, overlay, or background the app                                                            | Apply approved platform privacy controls and reauthentication; never rely on one flag                              |
| ABUSE-012 | Restore app data to a different device or account                                                          | Device-bound protected state is unusable; app fails closed and supports safe recovery                              |
| ABUSE-013 | Root/jailbreak/hook the app and alter a care message                                                       | Apply approved compromised-device posture; server remains authoritative and auditable                              |
| ABUSE-014 | Dependency or build step inserts a network endpoint/debug credential                                       | CI/artifact/provenance checks fail; signing/release is blocked                                                     |
| ABUSE-015 | Source returns two plausible current encounters                                                            | Return inconsistent/withhold; do not choose first or latest without approved rules                                 |
| ABUSE-016 | Clinical item is corrected while patient is offline                                                        | Old item becomes visibly stale/withheld on reconnection; correction lineage is auditable                           |
| ABUSE-017 | Hide imagery, substitute a catalog file, inject metadata, or imply that a bird/photo represents care state | Exact asset/set/hash checks fail on substitution or metadata; essential meaning/action remain textual and semantic |
| ABUSE-018 | Switch identity or recover account while a draft is in memory                                              | Clear draft and protected binding before new identity can display or submit it                                     |

## 12. Platform deltas and unresolved asymmetries

| Area               | iOS foundation                                                                             | Android foundation                                              | Required convergence                                                                              |
| ------------------ | ------------------------------------------------------------------------------------------ | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Network absence    | No network client in source/binary scans; exact offline privacy manifest                   | No `INTERNET`; explicit cleartext denial and system-only trust  | Add approved network stacks together with TLS/endpoint tests; re-review privacy declarations      |
| Background privacy | SwiftUI privacy cover when scene inactive                                                  | Cover on pause plus `FLAG_SECURE`                               | Decide active-screen capture policy, physical observation, external display, and recents behavior |
| Protected state    | Data-protection Keychain, non-sync, when-unlocked-this-device-only                         | Keystore AES-GCM + private ciphertext, no user-auth requirement | Approve session threat model, authentication binding, restore/upgrade/delete behavior             |
| Backup/transfer    | Protected value is this-device-only; application-wide backup behavior not release-verified | Backup false plus complete cloud/transfer exclusions            | Distribution-artifact backup/restore tests on both platforms                                      |
| Volatile strings   | Reference cleared; immutable `String` not guaranteed zeroized                              | Reference cleared; immutable `String` not guaranteed zeroized   | Input-type/keyboard/clipboard policy and memory-sensitive design                                  |
| Capture testing    | Lifecycle cover XCUITest                                                                   | Secure-flag black capture and lifecycle instrumentation         | Approved physical-device test matrix without weakening production controls                        |
| Release hardening  | Release build and hook scan; no distribution artifact                                      | Release minification currently disabled                         | Security owner decides symbol/obfuscation/tamper posture and verifies signed store artifacts      |
| Compromised device | No jailbreak/attestation policy                                                            | No root/attestation/overlay policy beyond platform defaults     | Risk-based policy, support path, accessibility impact, false-positive handling                    |
| Accessibility      | True-landscape accessibility5 shell journey                                                | Font-scale-2.0 landscape shell journey                          | Human VoiceOver/TalkBack, keyboard/switch access, language/RTL, all future screens                |

## 13. Mandatory activation gates

| Gate ID | Gate                                    | Minimum evidence                                                                                              |
| ------- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| GATE-01 | Operation and owner                     | Versioned Nightingale-owned operation, accountable owner, compatibility/deprecation decision, no legacy alias |
| GATE-02 | Data minimization                       | Field inventory, purpose, provenance, release class, source-identifier prohibition, privacy approval          |
| GATE-03 | Identity realm and provider             | Independent realm/provider, proofing assurance, provider threat review, non-disclosure errors                 |
| GATE-04 | Enrollment, session, and recovery       | Invitation authority, rates, expiry/rotation/revocation, support scripts, lost/shared-device flow             |
| GATE-05 | Relationship and representative policy  | Self-only initial decision or approved legal/privacy representative model with lifecycle tests                |
| GATE-06 | Patient linkage                         | Conflict/merge/deceased/revoked handling, authoritative owner, cross-patient negative evidence                |
| GATE-07 | Encounter/current-inpatient source      | Approved adapter, cohort/status mapping, cardinality, contradiction, freshness, downtime, no guessed state    |
| GATE-08 | Authorization and IDOR                  | Scope/purpose/effective-window/resource ownership on every read/write; adversarial object tests               |
| GATE-09 | Clinical release/content                | Named field-level clinical/content/language approvals, understandable vocabulary, uncertainty rules           |
| GATE-10 | Freshness/correction/retraction/offline | Approved clocks/thresholds, visible state, cache/purge rules, complete synthetic cases                        |
| GATE-11 | Messaging/routing                       | Accountable team, topic/pool/member/service-window/escalation policy, end-to-end delivery states              |
| GATE-12 | Mutation integrity                      | Idempotency, concurrency, append-only history, retry/reconciliation and race tests                            |
| GATE-13 | Notification privacy                    | Generic/minimized payload, token lifecycle, lock-screen/device review, delayed/duplicate/revoked tests        |
| GATE-14 | Accessibility and patient comprehension | WCAG/platform matrix, patient advisors, VoiceOver/TalkBack, motor, language/RTL, physical devices             |
| GATE-15 | Audit, support, detection, incident     | Event model, privacy-minimized telemetry, monitoring, support, kill switch, breach/safety escalation          |
| GATE-16 | Mobile/application security             | MASVS-scoped assessment, dependency review, signed-artifact analysis, penetration test, residual risks        |
| GATE-17 | Rollback and recovery                   | Tested route/client/source/content disable, correction/retraction propagation, rollback SLO and owner         |
| GATE-18 | Non-production integration              | Synthetic/deidentified environment, no production credentials/data, bounded manifest, teardown proof          |
| GATE-19 | Pilot authorization                     | Named scope/expiry/users/devices/facilities/features, training/support, go/no-go and stop criteria            |
| GATE-20 | Production release                      | Protected-main exact SHA, CI, signed artifacts, store records, deployment checks, no implicit migration       |

Passing one gate never implies another. In particular: authenticated is not authorized;
authorized is not current inpatient; current inpatient is not content release; content
release is not messaging permission; deployment is not pilot approval.

## 14. Verification program

| Verification ID | Required verification                                                                                | Threats/hazards covered               | Status                         |
| --------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------- | ------------------------------ |
| VER-001         | Contract zero-path/default-off and negative mutation self-tests                                      | THR-CFG-001, THR-E-003                | Implemented                    |
| VER-002         | Backend unavailable/inconsistent truth tables and unbound-route proof                                | HZ-002, HZ-019                        | Implemented foundation only    |
| VER-003         | Native product/namespace/no-network/release-hook scans                                               | THR-I-004, THR-E-003                  | Implemented foundation only    |
| VER-004         | Protected-state read/write/corrupt/delete synthetic canaries                                         | THR-T-001, HZ-021                     | Implemented foundation only    |
| VER-005         | Accessibility contrast/order/reflow/target/landscape automation                                      | HZ-014                                | Implemented current shell only |
| VER-006         | Identity proofing, enrollment, replay, enumeration, session, step-up, recovery abuse suite           | THR-S-001/003, THR-D-001, THR-OPS-001 | Not implemented                |
| VER-007         | Representative grant/revoke/sensitive-content/cross-patient suite                                    | THR-S-002, HZ-013                     | Not implemented                |
| VER-008         | IDOR/resource ownership/scope/purpose/effective-window matrix                                        | THR-E-001/002                         | Not implemented                |
| VER-009         | Source reconciliation, overlap, merge, transfer, close, freshness, outage, race suite                | HZ-002/003/012/019                    | Candidate fixtures only        |
| VER-010         | Field release/language/freshness/correction/retraction/offline atomic-decoder suite                  | HZ-003–007/017/018                    | Today candidate fixtures only  |
| VER-011         | Messaging route/delivery/idempotency/concurrency/urgent/downtime end-to-end suite                    | HZ-008–012                            | Not implemented                |
| VER-012         | Push payload/token/lock-screen/delay/duplicate/revoke suite                                          | THR-I-004, HZ-015                     | Not implemented                |
| VER-013         | Logging/analytics/crash/support PHI canary and access/retention audit                                | THR-I-002/003/006                     | Not implemented                |
| VER-014         | Signed iOS/Android artifact MASVS assessment and penetration test                                    | THR-T-003, THR-SC-001                 | Not implemented                |
| VER-015         | Human patient comprehension, language, interpreter, VoiceOver/TalkBack, motor review                 | HZ-006/007/014/020                    | Not implemented                |
| VER-016         | Tabletop for wrong patient, stale content, message outage, push leak, lost device, source compromise | HZ-001–022                            | Not implemented                |
| VER-017         | Kill-switch, correction/retraction, rollback, cache purge, recovery-time exercise                    | HZ-004/017/022                        | Not implemented                |
| VER-018         | Deidentified bounded non-production pilot rehearsal and teardown                                     | Cross-cutting                         | Prohibited until approval      |

## 15. Detection, incident response, and recovery requirements

### 15.1 Detection signals

Before activation, the system needs privacy-minimized detection for:

- repeated proofing/enrollment/recovery failures and patient-existence probing;
- session replay, reuse, revocation, unusual device/risk events, and realm mismatch;
- cross-patient handle access, denied scopes, relationship changes, and IDOR patterns;
- source contradiction, unexpected cardinality, stale observation, and projection
  release/correction/retraction errors;
- message routing failures, delivery timeouts, duplicates, service-window violations, and
  urgent-content policy events;
- push-token churn, revoked-device sends, payload policy violations, and provider outage;
- decoder/schema failures, unknown enums, partial objects, and client-version incompatibility;
- abnormal exports, support lookups, audit access, and privileged configuration changes; and
- artifact/signing/dependency provenance drift.

Detection events must not themselves disclose clinical content or become an ungoverned
secondary patient record.

### 15.2 Incident classes

| Incident ID | Example                                    | Immediate containment                                                                                      |
| ----------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| INC-01      | Wrong-patient or representative disclosure | Disable affected access/projection, revoke sessions, preserve audit, privacy/clinical escalation           |
| INC-02      | Stale/wrong/retracted clinical content     | Withhold affected field/section globally, publish correction, purge cache, notify through approved channel |
| INC-03      | Message loss/misrouting/false delivery     | Disable mutation or affected topic, expose service outage, reconcile queues, activate safe fallback        |
| INC-04      | Push payload/token disclosure              | Stop provider sends, revoke tokens, rotate credentials, assess lock-screen/device exposure                 |
| INC-05      | Identity/recovery compromise               | Suspend enrollment/recovery, revoke families, require safe re-proofing, protect non-disclosure             |
| INC-06      | Source or API compromise                   | Disable adapter/route, fail closed, isolate credentials, validate integrity before restoration             |
| INC-07      | Mobile/supply-chain compromise             | Stop release/distribution, revoke signing/store access as applicable, block vulnerable versions            |
| INC-08      | Availability outage                        | Present explicit unavailable/offline state, prevent ambiguous mutations, activate support/downtime path    |

### 15.3 Recovery proof

Recovery is incomplete until:

1. the unsafe capability is demonstrably disabled;
2. protected state, sessions, push tokens, and queued actions are reconciled;
3. wrong/stale content is corrected or retracted from server, cache, and device;
4. audit evidence is preserved with controlled access;
5. affected patients and organizations receive legally/clinically approved communication;
6. regression, penetration, and hazard tests pass on the exact remediation artifact;
7. named clinical, privacy, security, operations, and release authorities approve
   restoration; and
8. the incident updates this model and its activation gates.

The current foundation has no remote kill switch or live rollback path because it has no
live capability. Those controls must exist and be exercised before activation.

## 16. Open residual risks and current disposition

| Risk ID  | Open risk                                                                                                                                          | Current disposition                                                                     |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| RISK-001 | iOS lifecycle cover does not prevent active-screen screenshot/recording                                                                            | Acceptable only because no PHI is shown; security/privacy decision required before data |
| RISK-002 | Android Keystore key does not require user authentication                                                                                          | Dormant synthetic use only; session threat model decides future binding                 |
| RISK-003 | iOS protected state has no app-level reauthentication/biometric policy                                                                             | Dormant synthetic use only                                                              |
| RISK-004 | Swift/Kotlin immutable strings cannot be reliably zeroized                                                                                         | No live composition UI; input/clipboard/keyboard design remains gated                   |
| RISK-005 | Android Release minification/obfuscation is disabled                                                                                               | No live data/network; signed-release security decision remains gated                    |
| RISK-006 | No root/jailbreak, attestation, overlay, hooking, or runtime-integrity policy                                                                      | No live capability; risk-based device posture required                                  |
| RISK-007 | No approved continuous dependency vulnerability/provenance response SLO                                                                            | Generated inventory reduces drift ambiguity only; supply-chain program still required   |
| RISK-008 | Identity, proofing, session, recovery, and representatives are undecided                                                                           | Keep identity/provider/network disabled                                                 |
| RISK-009 | Authoritative current-inpatient source, reconciliation, and freshness are undecided                                                                | Keep source adapter/query disabled                                                      |
| RISK-010 | Audit, telemetry, privacy redaction, detection, and retention are undecided                                                                        | No live operations or patient data                                                      |
| RISK-011 | Messaging, routing, urgent guidance, delivery semantics, and notifications are undecided                                                           | No message/push operation or provider                                                   |
| RISK-012 | No live kill switch, rollback propagation, incident SLO, or on-call model                                                                          | No live capability; required before integration/pilot                                   |
| RISK-013 | No named clinical safety officer or signed clinical-hazard review is recorded                                                                      | Draft model only; no clinical activation                                                |
| RISK-014 | No human patient, VoiceOver/TalkBack, physical-device, language/RTL, or interpreter review                                                         | Current automated shell evidence only                                                   |
| RISK-015 | No Nightingale penetration test, red-team exercise, or incident tabletop                                                                           | No network/data/pilot; GATE-16/15 remain closed                                         |
| RISK-016 | Personal-device environment can expose health-app presence and behavior outside app controls                                                       | Privacy/minimization/support review required; do not overstate confidentiality          |
| RISK-017 | Release/store signing, store privacy declarations, background rights/attribution, backup/restore, upgrade, and supported-version behavior unproven | Local manifest/code/media lineage and builds only; no distribution authorization        |

## 17. Ownership and approval record

| Discipline               | Required decision                                                           | Current record                           |
| ------------------------ | --------------------------------------------------------------------------- | ---------------------------------------- |
| Product/contract         | Scope, operation ownership, compatibility, support promise                  | Foundation owner only; no live operation |
| Identity/security        | Provider, proofing, session, recovery, device posture, threat closure       | Unassigned/not approved                  |
| Privacy/legal/HIM        | Data flow, relationship, consent/authority, disclosure, retention, incident | Unassigned/not approved                  |
| Clinical safety          | Hazard severity, controls, safety case, clinical incident and residual risk | Unassigned/not approved                  |
| Clinical/content         | Field release, source, vocabulary, uncertainty, correction/retraction       | Unassigned/not approved                  |
| Data governance/source   | Authoritative source, reconciliation, freshness, lineage, quality           | Unassigned/not approved                  |
| Nursing/medical/pharmacy | Workflow fit, routing, urgent use, patient meaning                          | Unassigned/not approved                  |
| Accessibility/language   | AT, motor, cognition, reading level, translation, interpreter               | Unassigned/not approved                  |
| Support/operations       | Lost/shared device, lockout, downtime, response, kill switch, on-call       | Unassigned/not approved                  |
| Release                  | Exact artifacts, signing, stores, pilot manifest, rollback, go/no-go        | Unassigned/not approved                  |

An approval entry must identify the person/authority, date, reviewed artifact version/SHA,
scope, conditions, expiry/re-review triggers, residual risks, and rollback relevance.

## 18. Change-control rules

This model must be reviewed when any of the following changes:

- route, OpenAPI operation, server, network client, permission, entitlement, or provider;
- identity, proofing, enrollment, session, recovery, representative, or support workflow;
- principal/patient/encounter handle, authorization predicate, scope, purpose, or cardinality;
- inpatient source, cohort, status mapping, reconciliation, freshness, or cache;
- patient-visible field, vocabulary, translation, uncertainty, correction, or retraction;
- message topic, router, staff pool, delivery state, attachment, urgent guidance, or push;
- local storage, Keychain/Keystore profile, backup, device transfer, logs, analytics, or crash
  reporting;
- third-party SDK, dependency, signing, store, CI, deployment, or supported OS baseline;
- privacy cover, screenshot behavior, accessibility semantics, device posture, or offline UX;
- incident, monitoring, kill-switch, rollback, retention, or support procedure; or
- material external regulation, standard, threat intelligence, incident, or patient feedback.

Each revision must:

1. update affected `AST`, `TB`, `CTRL`, `THR`, `HZ`, `ABUSE`, `GATE`, `VER`, `INC`, and
   `RISK` entries;
2. retain superseded findings and risk decisions in the execution log;
3. link exact tests/evidence and artifact SHA;
4. state what remains disabled;
5. identify named reviewers and missing approvals; and
6. never convert a candidate or automated check into an implied clinical approval.

## 19. Ordered implementation work

1. Keep every existing activation field false and add this model to CI as a required,
   self-tested governance artifact.
2. Obtain named clinical-safety and security owners to review severity, likelihood,
   omitted hazards, and the applicability of external standards.
3. Resolve the independent identity/proofing/session/recovery/shared-device model before
   building a provider adapter.
4. Resolve self-only linkage, authoritative current-inpatient source, overlap/cardinality,
   freshness, contradiction, outage, and rollback before any query.
5. Define privacy-minimized audit/detection and incident events before the first patient
   operation.
6. Complete the first read-only operation's authorization, release, vocabulary,
   correction/retraction, offline, accessibility, and cross-patient negative matrices.
7. Perform only an approved, synthetic/deidentified, default-off non-production integration
   after GATE-01 through GATE-18 evidence is versioned.
8. Defer messaging and notifications until read-only safety, urgent-use boundaries,
   routing accountability, deterministic delivery semantics, and support are proven.
9. Complete signed-artifact MASVS assessment, penetration testing, red-team abuse cases,
   human accessibility/language/patient review, and incident/rollback tabletops.
10. Require a separately signed, scope/expiry-bound pilot go/no-go record. Deployment,
    technical success, or database connectivity must never imply patient activation.

## 20. Non-authorization statement

This document:

- does not authorize production database access, a sample patient, production credentials,
  or patient-data inspection;
- does not approve a route, client, provider, adapter, source query, identity, disclosure,
  mutation, message, notification, integration, migration, deployment, or pilot;
- does not assert regulatory or standards compliance;
- does not accept any residual risk;
- does not replace clinical, privacy, security, legal, accessibility, language, support, or
  release review; and
- does not make the current foundation safe for live inpatient use.

Its purpose is to prevent the absence of implementation from being mistaken for the
presence of a complete safety/security design, and to make the evidence required for any
future activation explicit and testable.
