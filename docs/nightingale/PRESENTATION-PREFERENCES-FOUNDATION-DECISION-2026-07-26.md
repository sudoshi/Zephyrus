# Nightingale presentation-preferences foundation decision

**Status:** implemented foundation subset; locally verified; independent human review and
distribution release remain held

**Decision date:** 2026-07-26

**Applies to:** Nightingale iOS and Android application roots only

**Does not authorize:** patient data access, account preferences, networking, identity,
clinical content, production fixtures, pilot enrollment, deployment, or release

## 1. Decision

Nightingale now owns two device-local display-comfort choices:

1. **Reduce motion in Nightingale**
2. **Hide decorative imagery**

These choices are deliberately separate from every future care-account, communication,
locale, consent, notification, and clinical preference. They modify presentation only.
They cannot alter care information, consent, routing, release state, clinical meaning, or
the availability of essential text and controls.

The application follows the stronger of the patient’s Nightingale choice and the relevant
system accessibility setting. A patient can ask Nightingale to reduce motion or hide
imagery even when the operating system has not requested it. Nightingale cannot use its
local controls to weaken a stronger operating-system accessibility condition.

This is a bounded foundation decision. It closes the previously identified semantically
inert Android reduced-motion control gap and establishes equivalent policy behavior on
iOS. It does not establish app-wide WCAG 2.2 AA conformance or accessibility approval.

## 2. In-scope behavior

| Concern                      | iOS behavior                                                                    | Android behavior                                       | Patient-safety rule                          |
| ---------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------ | -------------------------------------------- |
| Local storage                | `UserDefaults` under two full Nightingale keys                                  | private `SharedPreferences` file with two keys         | no care-account or clinical store            |
| System reduced motion        | SwiftUI `accessibilityReduceMotion`                                             | animator-duration scale equal to zero                  | system request cannot be overridden          |
| Patient reduced motion       | Nightingale-local Boolean                                                       | Nightingale-local Boolean                              | removes governed decorative transitions      |
| System contrast/transparency | increased contrast or reduced transparency hides imagery and makes cards opaque | Android 14+ positive system contrast hides imagery     | stronger system condition wins               |
| Accessibility text size      | decorative art attenuated                                                       | decorative art attenuated at font scale 1.3 or greater | text remains foreground content              |
| Patient imagery choice       | hides foreground and scenic Nightingale art                                     | hides foreground and scenic Nightingale art            | essential text and controls remain           |
| Persistence                  | survives app relaunch                                                           | survives activity recreation and cold relaunch         | device-local presentation state only         |
| Network/account sync         | none                                                                            | none                                                   | no API, account, or cloud preference binding |

No patient record, identity, encounter, grant, account, clinical projection, message, or
server preference is read or written by this behavior.

## 3. Namespace and storage boundary

### 3.1 iOS

The iOS application persists only:

- `net.acumenus.nightingale.presentation.v1.reduce-motion`
- `net.acumenus.nightingale.presentation.v1.hide-decorative-imagery`

The application uses `UserDefaults.standard`; it does not use
`NSUbiquitousKeyValueStore`, an account model, protected clinical storage, or a network
client for these settings. The UI-test reset environment variable is enclosed in
`#if DEBUG`, and the Release simulator binary scan proves the reset variable is not
present in that artifact.

This implementation does not intentionally roam settings through Nightingale or a care
account. iOS operating-system backup or device-restore treatment of application defaults
has not been independently proven or excluded. Distribution evidence must therefore not
claim that an Apple-managed device restore can never carry these values.

### 3.2 Android

Android persists only these keys in the private
`net.acumenus.nightingale.presentation.v1` preferences file:

- `reduce-motion`
- `hide-decorative-imagery`

Each write uses a synchronous commit. The in-memory presentation snapshot changes only
after the private-store commit reports success. The application manifest sets
`android:allowBackup="false"`, and both the legacy full-backup rules and Android data
extraction rules exclude all shared preferences from cloud backup and device transfer.

The Android production source contains no test-only clear or persisted-key inspection API.
Instrumentation inspects the private preference file through the activity only in the test
source set.

### 3.3 Roaming decision

Nightingale does not implement account, API, cloud-key-value, or cross-device synchronization
for these accessibility choices. Android backup and device transfer are explicitly
excluded. Apple-managed backup/restore behavior is an external release-evidence question,
not a Nightingale synchronization feature; that question remains open.

Future product work must not silently move either local choice into an account preference.
Any proposed roaming behavior requires a new privacy/accessibility decision covering shared
devices, representatives, stale settings, conflict resolution, and patient control.

## 4. Precedence truth table

### 4.1 Reduced motion

| System requests reduced motion | Patient requests reduced motion | Effective result                     |
| ------------------------------ | ------------------------------- | ------------------------------------ |
| no                             | no                              | governed gentle transitions may run  |
| no                             | yes                             | governed transition duration is zero |
| yes                            | no                              | governed transition duration is zero |
| yes                            | yes                             | governed transition duration is zero |

### 4.2 Decorative imagery

| Patient hides imagery | Stronger system visual condition | Effective result                             |
| --------------------- | -------------------------------- | -------------------------------------------- |
| no                    | no                               | decorative Nightingale imagery may be shown  |
| yes                   | no                               | all decorative Nightingale imagery is hidden |
| no                    | yes                              | all decorative Nightingale imagery is hidden |
| yes                   | yes                              | all decorative Nightingale imagery is hidden |

On iOS, a stronger visual condition means increased contrast or reduced transparency. On
Android 14 and later, it means positive system contrast. At accessibility text sizes,
imagery is retained only when otherwise permitted and is attenuated behind content.

## 5. Governed motion-site inventory

The foundation application has no route transitions, clinical refresh animation, animated
charts, progress choreography, parallax, video, or patient-content motion. The governed
motion sites are therefore intentionally small and enumerable.

| Motion site                          | iOS                                   | Android                                 | Reduced-motion result                                                              |
| ------------------------------------ | ------------------------------------- | --------------------------------------- | ---------------------------------------------------------------------------------- |
| privacy-cover appearance/removal     | opacity transition and root animation | lifecycle cover changes synchronously   | iOS uses identity/no implicit animation; Android has no animated cover transition  |
| scenic-image alpha change            | SwiftUI presentation-policy animation | `animateFloatAsState` with 180 ms tween | iOS disables the preference animation; Android uses `snap()` and zero milliseconds |
| local preference presentation update | 180 ms ease-in/out when permitted     | image-alpha tween when permitted        | transition duration becomes zero                                                   |

Every future Nightingale animation must be added to this inventory or its successor and
must consume the same effective policy or an independently approved equivalent. A stored
or announced preference with no rendering effect is prohibited.

## 6. Decorative imagery behavior

The foreground brand mark and full-page scenic Nightingale artwork are decorative. They
have no accessibility label and never carry care information.

When imagery is hidden:

- the foreground bird mark is removed;
- the background bird image is fully transparent or not rendered;
- essential headings, privacy copy, status copy, toggles, and explanatory text remain;
- the base surface remains present; and
- the UI explicitly states that decorative imagery is hidden and essential content remains
  available.

When imagery is permitted at an accessibility text size, its opacity is reduced. When iOS
increased contrast or reduced transparency is active, imagery is withheld and card
backgrounds become opaque. Android’s high-contrast policy similarly withholds imagery and
uses opaque scrims.

This foundation test does not prove every future Nightingale journey at the largest text
size, in landscape, or with language expansion. Those broader requirements remain open.

## 7. Patient-facing semantics

Both platforms present the section heading **Display comfort** and the same two controls.
The explanatory boundary states:

> These settings are stored by Nightingale, not your care account. They never change your
> care information.

Each control has a Nightingale-specific accessibility identifier/test tag, a patient-readable
label, a checkable state, and effect copy:

- motion permitted: “Gentle transitions are enabled. Nightingale also follows your system
  Reduce Motion setting.”
- motion reduced: “Motion is reduced. Nightingale changes views without decorative
  movement.”
- imagery shown: “The Nightingale artwork is shown softly behind the page.”
- imagery hidden: “Decorative imagery is hidden. Essential text and controls remain
  available.”

Decorative images are excluded from the accessibility tree. The safe-shell and privacy-cover
semantics remain Nightingale-only.

## 8. Implementation map

| Responsibility              | iOS                                           | Android                                        |
| --------------------------- | --------------------------------------------- | ---------------------------------------------- |
| keys, snapshot, persistence | `NightingalePresentationPreferences.swift`    | `NightingalePresentationPreferences.kt`        |
| system-signal collection    | `NightingaleApp.swift` environment values     | `MainActivity.kt` settings and `UiModeManager` |
| pure policy resolution      | `NightingaleSceneAccessibilityPolicy.resolve` | `nightingaleSceneAccessibilityPolicy`          |
| controls and effect copy    | `NightingaleApp.swift`                        | `NightingaleVisualFoundation.kt`               |
| scenic/cover consumption    | `NightingaleVisualFoundation.swift`           | `NightingaleVisualFoundation.kt`               |
| unit tests                  | `NightingaleProductBoundaryTests.swift`       | `NightingaleProductBoundaryTest.kt`            |
| device UI tests             | `NightingaleLaunchUITests.swift`              | `NightingaleLaunchInstrumentedTest.kt`         |
| compile-time boundary       | `verify-nightingale-product-boundary.sh`      | same repository verifier plus Gradle task      |

## 9. Automated proof

### 9.1 Policy and persistence tests

The iOS and Android unit suites independently prove:

- no request produces gentle motion when the system requests reduced motion;
- a patient’s local reduced-motion request produces a zero-duration policy;
- hiding imagery produces zero image opacity;
- a stronger system contrast/transparency condition hides imagery;
- accessibility text size attenuates imagery when it remains permitted;
- preference namespaces are Nightingale-only; and
- no key includes a patient, account, token, or Hummingbird namespace.

The device UI suites prove:

- both controls are rendered and discoverable;
- effect text changes when each control is enabled;
- state survives relaunch/activity recreation; and
- the existing lifecycle privacy controls still pass.

### 9.2 Product-boundary enforcement

The repository verifier now fails if:

- either presentation-policy source is missing;
- either iOS key or the Android namespace/key pair drifts;
- either implementation adopts an account/cloud-sync API;
- the iOS reset hook leaves a Debug conditional; or
- a production mobile source exposes test-only preference-clearing/inspection APIs.

Existing boundaries continue to reject networking, legacy patient namespace, Android
`INTERNET`, or Nightingale activation.

## 10. Native-device and build evidence

### 10.1 iOS

On iPhone 16e Simulator `3F568F29-BE58-49AD-8151-6C2303B4C4E3`, iOS 26.3.1:

- `xcodebuild test` passed 10 of 10 tests with zero failures or skips;
- the new UI journey enabled both settings, verified changed effect copy, terminated the
  application, relaunched it, and verified both switches remained enabled;
- the normally signed Release simulator build succeeded;
- visual inspection of the clean installed Release application confirmed the Nightingale
  mark, calm scenic treatment, legible privacy boundary, and reachable display-comfort
  controls; and
- the Release executable contained neither the Debug reset hook, the Hummingbird name, an
  app network URL, nor `NSUbiquitousKeyValueStore`. The standard Apple property-list DTD
  URL was identified and excluded from the application-endpoint assertion.

Exact local artifact hashes:

| Artifact                         | SHA-256                                                            |
| -------------------------------- | ------------------------------------------------------------------ |
| iOS Debug simulator executable   | `5f203f74c9b98f7700e00d52c05e0b676b8c458f07c385825cc59695709b3ca9` |
| iOS Release simulator executable | `663b1f9906e718dbd235512b1bc90cc9f9e47738a718bb71ac8cb686c6db0e81` |

### 10.2 Android

On the `hb` Android 15/API 35 AVD, cold-booted without a snapshot:

- six JVM tests passed;
- six instrumentation tests passed;
- `verifyNightingaleProductBoundary`, `lintVitalRelease`, `assembleDebug`, and
  `assembleRelease` passed;
- Gradle completed 117 actionable tasks, with 116 executed and one up-to-date;
- a manual accessibility-hierarchy interaction enabled both controls and a cold relaunch
  retained both checked states and both enabled-state explanations; and
- the Release DEX contained no legacy Hummingbird name, test-reset API, Zephyrus endpoint,
  API-path literal, or WebSocket endpoint, while the Release manifest declared no
  `INTERNET` permission. Generic AndroidX/Compose diagnostic and schema URLs are library
  strings and are not application endpoints.

Exact local artifact hashes:

| Artifact                     | SHA-256                                                            |
| ---------------------------- | ------------------------------------------------------------------ |
| Android Debug APK            | `ac851797682524dde8d739d9b6f4aa4eaaa0b77cc4e322b48e1ec21ec7149e1c` |
| Android unsigned Release APK | `9c23e30f1c7211f969ecb2cf68698591f74de409a3d689cf116c68e64fded3e1` |

Android applies `FLAG_SECURE`. External emulator screenshots are therefore black by design.
That black capture is not treated as visual-layout proof. The passing Compose instrumentation
suite and the live UI Automator hierarchy establish rendered semantics and state; independent
human visual review on a physical or approved review device remains required.

### 10.3 Evidence classification

These hashes bind the checks to exact local Debug/Release artifacts from this implementation
slice. They are not distribution signatures, store artifacts, production builds, or
governance approval. Exact commit and CI evidence must be appended to the execution log
after publication.

## 11. Failure history retained for reproducibility

Initial Android preflight attempts failed before application verification because the shell
had neither a selected Java runtime nor Android SDK environment. Using the Android Studio
JDK and the local Android SDK exposed two compile defects:

1. the proposed high-text-contrast API was not available at the selected compile surface;
2. the Compose delegated state required the `getValue` import.

The implementation replaced the unavailable read with the Android 14+
`UiModeManager.contrast` policy seam and added the required import. The complete clean task
set then passed. The earlier environment and compile failures are not counted as evidence.

An attempted Release URL scan also found platform/library URLs. The scan was narrowed to
application endpoint and forbidden-boundary tokens while retaining the independent no-
`INTERNET` manifest assertion. The broader library-string result is not represented as an
application network finding.

## 12. Residual risks and explicit holds

The following remain open:

- largest Dynamic Type/font-scale journeys on every future screen;
- complete reflow, focus-order, target-size, screen-reader, switch-control, contrast,
  landscape, language-expansion, and right-to-left testing;
- iOS operating-system backup/restore behavior for local presentation defaults;
- physical-device and independent patient/advisor accessibility review;
- distribution signing, store artifact, and exact protected-`main` release evidence;
- automated enforcement that every future motion site consumes a governed policy;
- named privacy/security, clinical, content, language/interpreter, accessibility,
  patient-advisor, support, and release approvals; and
- all identity, patient-data, Today, My Path, Care Team, communication, and production work.

No automated test in this slice substitutes for those reviews.

## 13. Rollback

Rollback is source-local:

1. remove the display-comfort card from each foundation screen;
2. remove the two platform preference stores and policy consumers;
3. remove only the two Nightingale presentation keys/preferences file during a separately
   reviewed migration if data cleanup is required; and
4. retain the lifecycle privacy cover, Android secure-window control, no-network boundary,
   and all other existing Nightingale safety primitives.

A rollback must never clear care-account or protected clinical state. None is touched by
this implementation.

## 14. Safety statement

No production database or source was accessed. No patient, principal, identity link, grant,
session, encounter, projection, preference, message, pathway, release, feature flag,
migration, deployment, or pilot state was created, read, or changed.

The implementation remains an offline, no-patient-data Nightingale foundation.
