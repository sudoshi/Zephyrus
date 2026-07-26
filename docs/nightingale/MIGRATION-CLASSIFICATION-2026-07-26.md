# Nightingale migration classification — foundation slice

**Status:** Partial source-level classification. This does not approve the remaining legacy
patient application for migration or production use.

**Reference roots:** `hummingbird/iosPatientApp` and `hummingbird/androidPatientApp`

**Destination roots:** `nightingale/iosApp` and `nightingale/androidApp`

## Classification rules

| Class                    | Meaning                                                                                                    | Migration rule                                                                                   |
| ------------------------ | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Safety primitive         | Platform control that can reduce disclosure risk without carrying patient data or legacy identity          | Reimplement under the Nightingale namespace with independent tests                               |
| Product behavior         | Patient-facing behavior that may be useful but depends on approved contracts, content, identity, or policy | Hold until its source, authorization, failure behavior, and patient-language review are approved |
| Test/fixture only        | Synthetic scenario, preview hook, screenshot harness, or reference fixture                                 | Never ship; retain only as evidence or recreate under an explicit non-production test namespace  |
| Rejected legacy behavior | Staff coupling, old branding/identifier, ungoverned endpoint/storage, or behavior that cannot fail closed  | Do not migrate                                                                                   |

## Classified foundation sources

| Reference source                                                                                                 | Classification                                                                       | Nightingale disposition                                                                                                                                                                                                    | Evidence                                                                      |
| ---------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `hummingbird/iosPatientApp/HummingbirdPatient/App/HummingbirdPatientApp.swift`                                   | Mixed: lifecycle safety primitive plus legacy patient bootstrapping                  | Reimplemented only the scene-phase privacy cover; rejected legacy configuration, API client, token store, and patient view-model bootstrapping for this slice                                                              | `NightingaleApp.swift`; iOS UI privacy-cover test                             |
| `hummingbird/iosPatientApp/HummingbirdPatient/Privacy/PatientPrivacyCoverView.swift`                             | Safety primitive with legacy branding                                                | Reissued as a Nightingale-only cover with new copy, identifiers, palette, and brand artwork                                                                                                                                | `NightingaleVisualFoundation.swift`; `nightingale-privacy-cover` UI assertion |
| `hummingbird/iosPatientApp/HummingbirdPatient/DesignSystem/PatientPhotoBackground.swift`                         | Mixed: accessibility-aware presentation primitive plus legacy scene/content taxonomy | Reimplemented only static decorative-background, transparency, contrast, and reduced-motion behavior; rejected legacy scene names and Hummingbird content strings                                                          | iOS unit/UI build and simulator screenshots; no clinical content present      |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/PatientPrivacyPolicy.kt`       | Safety primitive                                                                     | Reissued as `NightingalePrivacyPolicy`; mandatory `FLAG_SECURE` verified by unit and API 35 instrumentation tests                                                                                                          | Black system screenshot plus secure-window assertion                          |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/MainActivity.kt`               | Mixed: lifecycle safety primitive plus legacy patient API/session bootstrapping      | Reimplemented only `onPause`/`onResume` privacy-cover state and secure-window application; rejected API, credentials, device/session, and patient-state construction                                                       | Three Android instrumentation tests pass                                      |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientScenicBackground.kt` | Mixed: accessibility-aware presentation primitive plus legacy assets/scenes          | Reimplemented a Nightingale-only, no-data background using the supplied Nightingale mark and large-text image attenuation; retained a tested high-contrast policy seam pending an approved Android presentation preference | JVM accessibility-policy tests and API 35 semantics inspection                |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientApp.kt`              | Product behavior plus lifecycle cover                                                | Reimplemented only the privacy overlay; held all authentication, patient session, pathway, messaging, preferences, device-session, and synthetic-scenario branches                                                         | Nightingale foundation remains no-network and no-data                         |

## Still unclassified or held

All reference sources not listed above remain unclassified for migration and must not be
copied. The next classification slice should cover protected credential storage and volatile
input handling only after the Nightingale identity, recovery, storage namespace, device
compromise, logout/deletion, and threat-model decisions are documented. API clients,
authentication, clinical projections, care-team communication, preferences persistence,
notifications, and synthetic patient scenarios remain held.

No production database, patient, grant, session, release, feature flag, or deployment state
was read or changed during this classification.
