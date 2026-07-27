# Nightingale foundation privacy and release-control evidence

**Status:** Implemented engineering evidence for the offline foundation only. This is not
a privacy approval, App Store or Play declaration, mobile penetration test, patient-data
authorization, release authorization, or claim that a future networked product collects no
data.

**Decision date:** 2026-07-26

**Applies to:** the exact Nightingale iOS and Android offline foundation source and Release
artifacts on `codex/nightingale-patient-product`.

## 1. Outcome

The bounded privacy controls inherited as design evidence from Hummingbird Patient have
now been independently reissued, tightened, and rerun under Nightingale product
identities and assets:

- the iOS application carries one exact `PrivacyInfo.xcprivacy` file;
- that manifest declares no tracking, no collected-data types in the current offline
  foundation, and only the app-local `UserDefaults` required-reason API use;
- Android continues to request no `INTERNET` permission and now also has explicit
  cleartext denial, system-only trust anchors, and no debug trust override;
- Android backup and device transfer remain disabled and excluded;
- iOS lifecycle covering and Android lifecycle covering plus `FLAG_SECURE` remain
  independently tested;
- dormant protected storage and volatile-input deletion remain product-namespaced and
  fail closed;
- runtime sources contain no logging, analytics, crash-reporting, clipboard, pasteboard,
  or network-client implementation; and
- both Release artifact verifiers inspect the installed declarations, not only the source
  files.

These controls close the plan item to carry forward and rerun the existing automated
privacy controls **as candidate evidence**. They do not approve patient access, identity,
networking, telemetry, messaging, notification, clinical content, production data, or
distribution.

## 2. Primary platform requirements used

The implementation was checked against current primary platform documentation:

- Apple requires every executable using a required-reason API to include that API and an
  approved reason in its privacy manifest:
  [Describing use of required reason API](https://developer.apple.com/documentation/bundleresources/describing-use-of-required-reason-api).
- Apple's app-local `UserDefaults` reason is `CA92.1`:
  [NSPrivacyAccessedAPITypeReasons](https://developer.apple.com/documentation/bundleresources/app-privacy-configuration/nsprivacyaccessedapitypes/nsprivacyaccessedapitypereasons).
- Android's Network Security Configuration supports declarative cleartext opt-out and
  trust-anchor policy:
  [Network security configuration](https://developer.android.com/privacy-and-security/security-config).
- Android documents `android:usesCleartextTraffic` as a defense honored by many platform
  network components while noting that it is not a universal socket-level control:
  [application manifest element](https://developer.android.com/guide/topics/manifest/application-element).

The repositories and generated binaries remain the authoritative evidence for what this
foundation actually implements.

## 3. iOS privacy manifest

The application source contains exactly one privacy manifest at
`nightingale/iosApp/Nightingale/PrivacyInfo.xcprivacy`.

| Manifest key                       | Exact foundation value   | Reason and boundary                                                                                                      |
| ---------------------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| `NSPrivacyTracking`                | `false`                  | The offline foundation contains no tracking implementation. This is not a promise about an unreviewed future SDK/client. |
| `NSPrivacyTrackingDomains`         | Absent                   | No tracking domain is permitted or needed.                                                                               |
| `NSPrivacyCollectedDataTypes`      | Empty array              | The current application has no patient identity, network, telemetry, or data-collection path.                            |
| `NSPrivacyAccessedAPITypes`        | One `UserDefaults` entry | The app persists only two local display-comfort choices.                                                                 |
| `NSPrivacyAccessedAPITypeReasons`  | `CA92.1`                 | Those preferences are accessible only to Nightingale itself; no app group or cross-app access exists.                    |
| Additional accessed-API categories | None                     | No other required-reason API use was identified in the bounded application source.                                       |

The unit suite loads the manifest from `Bundle.main`, proving that the generated test host
actually packages it. The Release verifier recursively inventories `.xcprivacy` files,
requires only `PrivacyInfo.xcprivacy`, parses it as a property list, and compares the
complete dictionary to the approved offline-foundation declaration.

This is local manifest evidence only. App Store Connect privacy answers, nutrition labels,
third-party SDK declarations, signed archive validation, data-retention policy, and a
privacy notice remain release gates.

## 4. Android transport and backup defense in depth

The Android application manifest keeps these independent controls:

| Control                                  | Exact implementation                                                                                                         | Verification                                                                              |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| No network capability                    | No `android.permission.INTERNET`                                                                                             | Gradle boundary task, installed instrumentation, and APK permission inventory             |
| Explicit cleartext denial                | `android:usesCleartextTraffic="false"`                                                                                       | Source verifier, installed `NetworkSecurityPolicy`, and compiled manifest inspection      |
| Declarative network policy               | `android:networkSecurityConfig="@xml/network_security_config"`                                                               | Source verifier, Gradle boundary task, and compiled resource inventory                    |
| Trust anchors                            | System certificate authorities only                                                                                          | Exact XML check; no user-added CA and no debug override                                   |
| Application backup                       | `android:allowBackup="false"`                                                                                                | Source, installed `ApplicationInfo.FLAG_ALLOW_BACKUP`, and APK manifest                   |
| Cloud backup and device transfer         | Full exclusions in `backup_rules.xml` and `data_extraction_rules.xml`                                                        | Gradle boundary task and compiled manifest/resource checks                                |
| Ordinary screenshot/recording protection | `WindowManager.LayoutParams.FLAG_SECURE`                                                                                     | Unit constant check, installed-window instrumentation, and black `adb screencap` evidence |
| Background/task-switcher content cover   | `MainActivity.onPause` applies the privacy cover and clears volatile input; `onResume` removes the cover only for active use | Lifecycle instrumentation                                                                 |

The app still has no network stack. The network-security configuration is defense in depth
against an accidental future platform client; it is not permission to add a host, API
route, certificate pin, `INTERNET` permission, or transport implementation.

## 5. Cross-platform privacy-control reconciliation

| Reference concern               | Nightingale iOS evidence                                                                   | Nightingale Android evidence                                                                                    | Current limitation                                                                                      |
| ------------------------------- | ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Product isolation               | `net.acumenus.nightingale`; Nightingale-only Keychain and preference keys                  | `net.acumenus.nightingale`; Nightingale-only Keystore and preference keys                                       | No independent live identity realm/provider exists                                                      |
| Network absence                 | No client imports/tokens in source or Release executable                                   | No `INTERNET`; no client; explicit cleartext denial and system-only trust                                       | Future TLS, endpoint, certificate, and outage policy remains unapproved                                 |
| Required privacy declaration    | Exact packaged privacy manifest                                                            | Manifest/resource declarations inspected in APK                                                                 | Store-console declarations and signed distribution remain absent                                        |
| Background privacy              | Cover whenever scene is inactive; covered content removed from accessibility/hit testing   | Cover on pause and `FLAG_SECURE`; volatile input clears                                                         | iOS active-screen capture remains an explicit open decision before PHI                                  |
| Protected local state           | Non-synchronizable, data-protection Keychain, `WhenUnlockedThisDeviceOnly`                 | AES-256-GCM Android Keystore primitive with tamper failure and verified deletion                                | Dormant synthetic binding only; no real session format or user-authentication policy                    |
| Backup/transfer                 | Protected binding is this-device-only; preferences are nonclinical                         | App backup false plus complete cloud/transfer exclusions                                                        | Signed-artifact backup/restore/upgrade exercises remain open                                            |
| Volatile patient-entered text   | In-memory only and cleared at six sensitive boundaries                                     | In-memory only and cleared at the same six boundaries                                                           | Immutable Swift/Kotlin strings cannot be reliably zeroized; no approved input/keyboard/clipboard policy |
| Logging, analytics, crash, copy | Mechanically absent from bounded runtime source                                            | Mechanically absent from bounded runtime source                                                                 | A future observability design requires a privacy-minimized event model and vendor review                |
| Release-only test escape        | Release executable rejects Debug hook tokens and unapproved embedded frameworks/extensions | Release APK rejects Debug/test state, endpoints, extra permissions/components, and unapproved library inventory | Distribution signing, notarized/store processing, and tamper testing remain open                        |

The older Hummingbird Patient implementation remains evidence only. No legacy source file
was copied to claim parity, and no legacy identifier, endpoint, token, or production
configuration was introduced.

## 6. Mechanical enforcement

The following checks fail closed:

1. `scripts/ci/verify-nightingale-product-boundary.sh`
    - requires and exactly parses the iOS privacy manifest;
    - requires Android cleartext denial, network-security resource, system trust anchors,
      and no debug override;
    - rejects any Android `INTERNET` permission;
    - rejects native network-client types;
    - rejects runtime logging, analytics, crash-reporting, clipboard, or pasteboard APIs;
    - retains namespace, accessibility, background, backup, and Release-hook controls.
2. `nightingale/androidApp/app/build.gradle.kts`
    - repeats the Android source/resource policy inside the Gradle `check` lifecycle.
3. `NightingaleProductBoundaryTests.swift`
    - reads `PrivacyInfo.xcprivacy` from the built app and checks the complete exact
      dictionary.
4. `NightingaleLaunchInstrumentedTest.kt`
    - checks the installed package identity;
    - proves `INTERNET` is denied;
    - proves platform cleartext policy is false; and
    - proves the installed application does not carry `FLAG_ALLOW_BACKUP`.
5. Release artifact verifiers
    - iOS requires the exact packaged privacy manifest and continues executable/linkage/
      resource scans;
    - Android requires the compiled transport declarations and continues permission,
      component, DEX, library, and resource scans.

## 7. Native verification protocol

The accepted local evidence for this slice must include:

### iOS

1. regenerate the Xcode project and prove no project drift;
2. build a signed Debug test host for the selected iPhone Simulator;
3. run the complete XCTest suite, including packaged-manifest validation;
4. run all XCUITest shell/privacy/accessibility journeys;
5. build the unsigned Release application;
6. verify the exact Release application bundle; and
7. inspect the inactive privacy cover and active safe shell on the simulator.

### Android

1. run Debug and Release unit tests;
2. run Debug and Release lint;
3. assemble Debug and unsigned Release APKs;
4. verify the exact Release APK;
5. run the complete instrumentation suite on API 35, including installed privacy-policy
   checks;
6. confirm a shell-level `adb screencap` of the active secure window remains black; and
7. inspect the UI hierarchy to confirm no sensitive, legacy, source, or background-file
   semantics appear.

Exact commands, simulator/emulator identifiers, test counts, hashes, and exact-SHA CI are
recorded in the companion execution log after the run completes.

## 8. Deliberate holds and residual risk

This evidence does not authorize:

- a patient, principal, encounter, grant, session, sample patient, or production query;
- a network permission, client, endpoint, route, identity provider, or certificate pin;
- clinical content, communication, push, telemetry, crash reporting, or support tooling;
- App Store/Play privacy declarations or an assertion that a future product collects no
  data;
- active-screen capture behavior on iOS once PHI exists;
- patient input, clipboard, keyboard, autofill, background refresh, notification, widget,
  extension, or sharing behavior;
- distribution signing, store processing, penetration testing, or pilot activation; or
- privacy, security, legal, clinical, accessibility, or release approval.

The current control is safe because the application is offline, contains no patient data,
and mechanically withholds every live capability. Any future operation changes the data
flow and invalidates the no-collected-data assumption until the manifest, store
declarations, contracts, threat model, tests, and named approvals are reviewed together.
