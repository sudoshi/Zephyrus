# Nightingale identity, recovery, and protected-state decisions

**Status:** Engineering foundation decision; implementation may protect synthetic
verification canaries only. This document does not approve an identity provider, patient
authentication, enrollment, recovery workflow, credential format, production patient,
representative access, API client, pilot, or release.

**Decision date:** 2026-07-26

**Applies to:** `nightingale/iosApp` and `nightingale/androidApp`

**Reference implementations:** `hummingbird/iosPatientApp` and
`hummingbird/androidPatientApp` are evidence inputs only. Their access-token, refresh-token,
device-identity, and storage choices are not inherited.

**Companion design evidence:** The
[identity/session/recovery and inpatient-source held candidate](./IDENTITY-SESSION-RECOVERY-AND-SOURCE-CANDIDATE-DECISION-2026-07-26.md)
now defines exhaustive synthetic failure states and adoption gates. It does not satisfy the
provider, credential, representative, source, review, integration, pilot, or release
decisions that remain open below.

## 1. Why this decision precedes authentication code

A patient-facing app can create durable disclosure and account-takeover risk before it
shows clinical content. Token persistence, device migration, logout, recovery, retained
drafts, and deletion failure determine whether a former user, a restored device, an
interrupted workflow, or a compromised process can regain access. Nightingale therefore
defines the lifecycle and failure semantics before selecting an identity provider or
adding an authentication screen.

The current native foundation remains no-network and no-data. The protected-state
implementation introduced under this decision is deliberately dormant in production code;
platform tests exercise it with conspicuously synthetic canary bytes and delete them in
teardown.

## 2. Authoritative security inputs

- Apple recommends selecting the most restrictive Keychain accessibility compatible with
  the app's use and documents that
  `kSecAttrAccessibleWhenUnlockedThisDeviceOnly` is available only while unlocked and does
  not migrate to another device:
  [Keychain accessibility](https://developer.apple.com/documentation/security/ksecattraccessiblewhenunlockedthisdeviceonly).
- Android documents that Android Keystore keys can remain non-exportable and can be
  restricted to specified algorithms, purposes, modes, and padding:
  [Android Keystore](https://developer.android.com/privacy-and-security/keystore).
- OWASP MASVS requires secure handling of intentionally stored sensitive data and
  recommends minimizing durable sensitive state:
  [MASVS-STORAGE-1](https://mas.owasp.org/MASVS/controls/MASVS-STORAGE-1/) and
  [iOS data storage](https://mas.owasp.org/MASTG/0x06d-Testing-Data-Storage/).

These sources establish implementation baselines, not Nightingale's complete threat model
or release approval.

## 3. Protected-state inventory and decisions

| State candidate                              | Foundation decision                                                                                                                                             | Durable location                                                  | Backup/migration                                         | Activation gate                                                                                              |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Access token                                 | **Do not persist.** If a future contract issues one, hold it in process memory only and make it short lived.                                                    | None                                                              | None                                                     | Approved Nightingale contract, identity threat model, expiry/rotation tests, and logout/revocation semantics |
| Refresh token                                | **No format or persistence approved.** The legacy clients' token keys are rejected.                                                                             | None                                                              | None                                                     | Identity-provider selection plus security/privacy review                                                     |
| Future session binding                       | Reserve exactly one Nightingale-only protected value descriptor so platform protection and deletion can be tested without defining a credential.                | iOS Keychain or Android Keystore-encrypted app-private ciphertext | Device only; Android application backup remains disabled | May store synthetic canaries now; real use requires identity approval                                        |
| Device identifier                            | **Do not create a durable app UUID.** Installation, device, patient, encounter, and session identity must not be conflated.                                     | None                                                              | None                                                     | Separate necessity, privacy, rotation, and support decision                                                  |
| Patient projection/cache                     | **Do not persist in this slice.**                                                                                                                               | None                                                              | None                                                     | Data-minimization, freshness, offline, correction, retraction, and deletion design                           |
| Message/question/teach-back draft            | Volatile composition memory only. Clear when the app loses active foreground state and at logout, account switch, recovery, revocation, or deletion boundaries. | None                                                              | None                                                     | Patient-advisor review is required before changing the safe-first loss-on-background behavior                |
| Analytics/crash context                      | No patient, credential, free text, source payload, or stable device identifier. No SDK is present.                                                              | None                                                              | None                                                     | Separate telemetry privacy decision and project isolation                                                    |
| Logs, screenshots, clipboard, keyboard cache | No sensitive values; use generic errors. Android keeps `FLAG_SECURE`; iOS uses the lifecycle cover. No clipboard behavior is added.                             | None                                                              | None                                                     | Platform-specific leakage test suite before patient inputs                                                   |

### 3.1 Namespace

The protected-state namespace is a product boundary:

- iOS Keychain service: `net.acumenus.nightingale.protected-state.v1`
- iOS account descriptor: `future-session-binding-v1`
- Android Keystore alias: `net.acumenus.nightingale.protected-state-key.v1`
- Android private preference file:
  `net.acumenus.nightingale.protected-state-ciphertext.v1`
- Android ciphertext record: `future-session-binding-v1`

No Hummingbird service, package, alias, preference file, access-token key, refresh-token
key, device UUID, or migration rule is accepted. There is no Keychain access group, Android
shared user, app group, cloud synchronization, or cross-product credential sharing.

Changing any identifier above is a versioned security migration, not a string cleanup. No
migration is defined by this decision; an old or unknown namespace remains unread.

## 4. Platform protection profile

### iOS

The foundation store uses generic-password Keychain items with:

- `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`;
- `kSecAttrSynchronizable = false`;
- the data-protection Keychain;
- the exact Nightingale service and account descriptor;
- explicit status handling for read, write, and deletion.

Foreground-only availability matches the current product boundary. A later requirement for
background refresh must not silently weaken the accessibility class. Passcode-set-only or
user-presence access remains an open usability/security decision for patient and
representative workflows; this foundation does not claim biometric protection.

### Android

The foundation store uses:

- a non-exportable 256-bit AES key generated in `AndroidKeyStore`;
- encrypt/decrypt purposes only;
- AES/GCM/NoPadding with a fresh platform-generated IV for every write;
- authenticated additional data binding ciphertext to the Nightingale application,
  schema, and descriptor;
- app-private `SharedPreferences` for versioned IV and ciphertext only;
- `android:allowBackup="false"` plus explicit all-domain cloud-backup, device-transfer, and
  legacy full-backup exclusion rules.

Hardware-backed key storage is device-dependent and is not claimed by this foundation.
StrongBox and per-use user authentication are not forced because support, accessibility,
representative access, and recovery requirements are undecided. This must be revisited
before any real binding is stored.

The legacy `EncryptedSharedPreferences` implementation and its persistent access token,
refresh token, session UUID, and device UUID are not migrated.

## 5. Read, write, and corruption semantics

1. Missing protected state is an unauthenticated state, never an implicit enrollment or
   recovery.
2. A platform access, Keychain, Keystore, decode, authentication-tag, or persistence error
   is surfaced as protected storage unavailable. The app must withhold access; it must not
   fall back to plaintext, another namespace, a legacy key, or a cached patient identity.
3. Writes must not log the value or include it in an error.
4. Empty values are rejected.
5. Android ciphertext is one versioned binary envelope. An unknown version, invalid IV,
   absent key with surviving ciphertext, or failed GCM authentication is unavailable state,
   not "no account."
6. A future caller must not infer remote session validity from successful local decryption.

## 6. Logout, revocation, deletion, and recovery state machine

Local protected-state deletion and server-side session revocation are separate facts:

| Event                                      | Required local action                                                                        | Required remote action                                                              | Patient-visible claim                                                                               |
| ------------------------------------------ | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| User logout while online                   | Immediately clear volatile inputs and make protected state cryptographically inaccessible    | Revoke the exact server session idempotently                                        | "Signed out" only after local access is removed; separately state if server confirmation is pending |
| User logout with ambiguous network outcome | Same local action                                                                            | Retry only an idempotent revocation operation; never replay another write           | Do not claim remote revocation succeeded                                                            |
| Server revocation/expiry                   | Clear volatile and protected state as soon as the response is authenticated                  | Server remains authoritative                                                        | Generic signed-out/re-authentication state with no existence disclosure                             |
| Account switch or representative change    | Clear volatile state before transition; never reuse another principal's protected state      | End or replace the scoped session under an approved contract                        | Never display the former principal while resolving the new one                                      |
| Local "remove from this device"            | Clear volatile state and protected state                                                     | No account deletion claim                                                           | State only what was removed locally                                                                 |
| Account/record deletion request            | Clear local state after the governed request reaches its defined state                       | Separate HIM/legal/retention workflow                                               | Never imply that legally retained records or audit events were erased                               |
| Recovery                                   | Start from no trusted local identity; clear old local binding before accepting a replacement | Perform independently proofed, rate-limited recovery and revoke superseded sessions | Never reveal whether an identifier belongs to a patient before proofing                             |

Deletion is idempotent. "Already absent" is success. Any platform error is failure and must
be observable without exposing a secret. On Android, deletion attempts both the Keystore
key and ciphertext. Removing the cryptographic key makes surviving ciphertext
unrecoverable, but cleanup failure must still be reported rather than silently called
complete.

## 7. Volatile input lifecycle

The initial policy intentionally favors disclosure prevention over draft retention:

```text
foreground composition
        |
        +-- app becomes inactive/backgrounded --> release in-memory draft reference
        +-- logout/revocation -----------------> release in-memory draft reference
        +-- account/representative transition --> release in-memory draft reference
        +-- recovery/deletion -----------------> release in-memory draft reference
```

The implementation can clear references but cannot prove that immutable language/runtime
strings have been immediately overwritten in process memory. It therefore makes no
zeroization claim. Future patient input controls must additionally address keyboard
learning, dictation, autofill, screenshots, accessibility announcements, logs, crash
reports, and process restoration. Draft persistence is prohibited until a separately
reviewed encrypted-offline and patient-experience design exists.

## 8. Device compromise posture

Root, jailbreak, debugger, hooking, sideload, integrity, and attestation signals can be
useful risk inputs but are not complete or perfectly reliable. Nightingale will not:

- claim that a device is safe merely because no compromise signal was detected;
- use a client-only signal as the sole authorization control;
- send patient details in a compromise error;
- fall back to weaker storage when hardware or policy is unavailable; or
- strand a patient without a documented bedside/support/recovery alternative.

Before pilot, the security review must decide which signals are collected, their privacy
and accessibility effects, server policy, false-positive handling, representative-device
handling, emergency support language, and whether the app withholds all content or only
sensitive capabilities. The current foundation only fails closed on protected-storage
errors.

## 9. Verification and evidence requirements

The foundation slice is complete only when all of the following are true:

- unit tests pin independent Nightingale namespaces and prove no access/refresh-token or
  durable-device-UUID keys exist;
- iOS Simulator tests round-trip and idempotently delete a synthetic Keychain canary;
- Android emulator tests round-trip a synthetic canary, prove the private preference record
  does not contain the plaintext canary, and delete both ciphertext and Keystore key;
- both platforms prove volatile drafts are cleared at their lifecycle boundary;
- Debug and Release builds pass and the existing no-network/product-boundary scans remain
  green;
- tests clean up canaries even after assertion failure;
- no production patient, credential, API, database, grant, session, or deployment is used.

The tests prove platform plumbing and defined failure behavior only. They do not prove
resistance to a compromised OS, identity correctness, secure server revocation, recovery
adequacy, clinical safety, privacy approval, or pilot readiness.

## 10. Required decisions before live identity work

- [ ] Select and contractually document the patient/representative identity provider.
- [ ] Define proofing assurance, enrollment authority, activation delivery, rate limits,
      lockout, non-disclosing errors, and help-desk verification.
- [ ] Define patient, guardian, proxy, interpreter, and representative relationship changes.
- [ ] Define session, rotation, expiry, device-limit, revocation, and risk-event contracts.
- [ ] Decide whether a refresh credential exists and, if so, its binding, accessibility,
      user-presence, recovery, and deletion requirements.
- [ ] Define remote logout ambiguity and idempotency behavior.
- [ ] Approve recovery and lost/stolen/shared-device procedures through security, privacy,
      legal/HIM, accessibility, patient-advisor, support, and clinical review.
- [ ] Complete mobile threat modeling, penetration testing, and device-compromise policy.
- [ ] Add a Nightingale-owned default-off API contract and authorization matrix.
- [ ] Obtain pilot and release approval separately.
