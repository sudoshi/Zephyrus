# Nightingale background asset governance and native integration

**Status:** implemented and locally verified for the offline native foundation; external
distribution rights, named human review, signed-store artifacts, and exact-SHA CI for the
current change remain open

**Decision date:** 2026-07-26

**Applies to:** `nightingale/backgrounds`, the Nightingale iOS application, and the
Nightingale Android application

**Does not authorize:** patient data, identity, networking, clinical content, production
access, patient creation, pilot enrollment, marketing use, store distribution, deployment,
or release

## 1. Product direction and decision

The seven images supplied under `nightingale/backgrounds` are the Nightingale product
background collection. Both native products consume one governed derivative set. A
platform-specific photo set, legacy Hummingbird Patient scene taxonomy, downloaded runtime
media, clinical-state mapping, or species-label experience is prohibited.

The background is atmosphere only. It never communicates diagnosis, progress, acuity,
urgency, care-team action, pathway stage, expected outcome, or whether information is
current. Every patient-visible state and action must remain complete when all decorative
imagery is removed.

## 2. Immutable source and derivative lineage

The machine-readable source of record is
[`nightingale/backgrounds/backgrounds.v1.json`](../../nightingale/backgrounds/backgrounds.v1.json).
It binds each supplied source by filename, SHA-256, byte count, and dimensions, then binds
the exact app derivative by repository path, SHA-256, byte count, and dimensions.

| ID  | Source dimensions | Derivative dimensions | Derivative bytes | Derivative SHA-256                                                 |
| --- | ----------------: | --------------------: | ---------------: | ------------------------------------------------------------------ |
| 01  |         1400×1980 |             1400×1980 |          285,047 | `4a741d9d3add77eac8aad8071bf3c9945bbd2ce4aa0d93b0daa79efe166b30b4` |
| 02  |          800×1169 |              800×1169 |          149,373 | `6f2888ae489e2ca2f268065fc7f3f029a051921b4290200e740e455c17cc3510` |
| 03  |         2667×4000 |             1600×2400 |          591,858 | `760c01a4ee1a6830d8a975b5626dd4e452702639a562be875dd2e7838d5334d4` |
| 04  |         2667×4000 |             1600×2400 |          788,373 | `16addb872da986ee99aa3de4db4715f4ff8dc945170934a33eb1192fe3bfb2a3` |
| 05  |         2400×3600 |             1600×2400 |          830,081 | `339ab8239cab04fa6cac373a00dc6993e30a6b3786d35e065b3e810ce6f52e8d` |
| 06  |         3456×5184 |             1600×2400 |        1,110,350 | `e6d9d06cf85ef9360186762e9c0ce0c2297cd6ec445f8409eaa5df49b99086d9` |
| 07  |         5304×6630 |             1920×2400 |          684,892 | `024349a43146b2682af268f6ae4abe14be7725b04456eaf478cda00869086f2c` |

The exact seven derivatives total 4,439,974 bytes. Sources at or below a 2400-pixel long
edge retain their dimensions; larger sources are downsampled without upscaling. The
committed JPEGs are quality-82, optimized, progressive, and stripped with
`jpegtran -copy none`. EXIF, XMP, Photoshop/IPTC, comment, and GPS-capable application
markers are prohibited.

The approximately 33 MB source binaries are not duplicated in this development stream.
Their exact fingerprints remain in the manifest. Before external distribution, the release
owner must identify a durable source archive and attach the applicable license or
attribution evidence to the release decision.

## 3. Cross-platform runtime contract

### 3.1 Exact catalog

Both apps contain only these seven logical resource names:

1. `nightingale_background_01`
2. `nightingale_background_02`
3. `nightingale_background_03`
4. `nightingale_background_04`
5. `nightingale_background_05`
6. `nightingale_background_06`
7. `nightingale_background_07`

Android compiles the shared `optimized/drawable-nodpi` directory through a dedicated
source-set resource root. iOS packages the same files as loose bundle resources from the
shared directory through the generated Xcode project. Neither target contains a copied or
independently recompressed platform variant.

### 3.2 Stable local-day selection

Both platforms derive the catalog index as:

```text
floorMod(local Gregorian epoch day since 1970-01-01, 7)
```

The selected photo therefore remains stable throughout the patient’s local day and maps to
the same catalog entry on iOS and Android. A negative epoch day also uses mathematical
floor modulo, so pre-1970 dates cannot produce an invalid index.

There is no timer-driven rotation, carousel, video, parallax, pan/zoom, care-event
transition, or automatic cross-fade cycle. A patient-triggered imagery preference change
may use the existing short presentation transition unless reduced motion is effective.

### 3.3 Accessibility and comprehension

- The photo is hidden from the accessibility tree and does not receive input.
- No filename, species, visual description, or inferred state is announced.
- “Hide decorative imagery” removes every background photo and decorative product mark
  while preserving all text, headings, controls, focus order, and actions.
- iOS also withholds photos when Reduce Transparency or Increased Contrast is active.
- Android withholds photos when the supported high-contrast signal is active.
- Reduced motion disables the current preference transition on both platforms.
- Accessible text size increases the iOS scrim strength; text remains on governed content
  surfaces rather than directly over raw pixels.

### 3.4 Legibility and visual restraint

The image fills the viewport with a deterministic centered crop. A system-surface gradient
separates photography from the content layer. Patient-readable cards use opaque or
near-opaque system surfaces; stronger system contrast/transparency settings force full
opacity. The background can establish a calm visual character but cannot reduce the
prominence of privacy, urgent-help, stale/withheld, error, or action language when those
future states are approved.

## 4. Mechanical enforcement

`scripts/ci/verify-nightingale-background-assets.mjs` fails closed on:

- a missing, extra, reordered, renamed, malformed, non-progressive, or non-portrait file;
- any source or derivative lineage field that is missing or malformed;
- derivative byte, dimension, or SHA-256 drift;
- upscaling or a derivative long edge above 2400 pixels;
- duplicate IDs, paths, source hashes, or derivative hashes;
- EXIF/XMP/IPTC/Photoshop/comment-capable metadata markers;
- a change from decorative-only, no-clinical-semantics, no-species-label, no-autonomous-
  motion behavior;
- removal of imagery suppression, accessibility hiding, or readable-surface requirements;
- weakening the foundation-only rights status; or
- changing the shared local-Gregorian-epoch-day selection rule.

The verifier includes negative self-tests that deliberately mutate the catalog and require
rejection. The native product-boundary verifier invokes this asset verifier so a
background-only drift cannot bypass the Nightingale safety gate.

Release artifact verifiers independently prove that the built iOS app and Android APK
contain the exact seven derivative hashes and no ungoverned background file. Native unit
tests prove catalog cardinality, resource readability, selection stability, next-day
rotation, wraparound, negative-day handling, and presentation suppression policy.

## 5. Native verification disposition

Local verification includes:

- iOS Debug build-for-testing, nine XCTest unit tests, four XCUITest journeys, and unsigned
  Release simulator build;
- Android Debug/Release unit suites, Debug/Release assemblies, Release artifact inspection,
  and seven API 35 instrumentation journeys;
- an iOS Simulator visual inspection showing a governed photo behind strong scrims and
  readable content surfaces;
- Android API 35 hierarchy inspection confirming the image contributes no accessibility
  label or species token; and
- a black Android ADB capture confirming `FLAG_SECURE` remains active. Capture protection
  was not weakened to obtain a background screenshot.

These are local engineering checks. Exact-SHA CI, physical-device review, assistive-
technology review, patient-advisor review, language/RTL review, signed distribution
artifacts, and store presentation remain open.

## 6. Distribution and change-control gates

No catalog asset is approved for external distribution until all of the following are
recorded:

1. durable source archive location;
2. license, permission, and attribution obligation for each source;
3. release-owner approval of the exact manifest version and hashes;
4. named patient-advisor and accessibility review of comfort, legibility, crop, cultural
   interpretation, and images-hidden behavior;
5. supported physical-device and orientation inspection;
6. signed iOS and Android distribution-artifact verification;
7. store-listing and privacy-declaration review where applicable; and
8. exact-SHA CI plus the ordinary protected release gates.

A future addition, replacement, recompression, crop, or deletion increments the catalog
version and requires the complete lineage, native parity, artifact, accessibility,
rights, and human-review sequence. Silent file replacement is prohibited.

## 7. Safety statement

No production database or source was accessed. No patient, principal, encounter, grant,
session, identity link, clinical projection, message, preference, feature flag, migration,
deployment, pilot, or release state was created, read, or changed.
