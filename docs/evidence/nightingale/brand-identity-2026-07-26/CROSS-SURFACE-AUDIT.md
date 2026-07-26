# Hummingbird and Nightingale Cross-Surface Brand and Upgrade Audit

**Audit date:** 2026-07-26

**Evidence class:** Non-PHI repository, build-artifact, and emulator engineering evidence

**Products:** Hummingbird Staff and Nightingale

**Predecessor source:** `84b5f8305a694128423ae489fa4527e4b542927f`

**Current source under test:** `06441141edd38ffd79803a5aed8b8906f6a32a20` plus
the uncommitted verifier/documentation slice described in this record

**Disposition:** Repository-level brand isolation and source-predecessor install
compatibility pass. Distribution, released-artifact upgrade, store-console, signing, and
future notification-rendering approval remain held.

## 1. Question and conclusion

This audit answers five narrow engineering questions:

1. Do the active Hummingbird and Nightingale applications retain independent, correctly
   named application identities?
2. Can Hummingbird's existing notification, widget, and Live Activity surfaces accidentally
   acquire the Nightingale mark or namespace from the repository?
3. Does Nightingale currently expose any unapproved notification, widget, extension, shared
   container, shortcut, or push surface?
4. Does the repository contain a store-listing package or screenshot asset that could be
   mistaken for approved distribution material?
5. Can the current Hummingbird build replace a clean build from the current `origin/main`
   predecessor without changing app identity or losing synthetic private app data?

The repository-level answer is **yes, the products are mechanically isolated**, and the
source-predecessor Hummingbird replacement test passes on iOS Simulator and Android API 35.
The answer is deliberately **not** “release upgrade approved”:

- the predecessor is a build from repository source, not a retained App Store or Play Store
  binary;
- simulator/debug signing is not distribution signing;
- predecessor and current artifacts both use development version/build values rather than a
  store-valid increment;
- no Apple or Google console record was inspected or changed;
- no distribution-rights, product-design, accessibility, privacy, or release approval was
  inferred; and
- Hummingbird Android does not yet post notifications, while Nightingale has no approved
  push or notification implementation to render.

## 2. Audit boundary and method

### 2.1 Included

- application IDs, bundle IDs, display names, extension IDs, app-group names, and launcher
  resource references;
- iOS APNs entitlement namespaces, push-routing source, WidgetKit configuration, and Live
  Activity ownership;
- Android widget receiver configuration and notification-channel registration;
- positive inventory of Hummingbird notification/widget surfaces;
- negative inventory of Nightingale notification/widget/push/shortcut surfaces;
- repository-owned store-listing directories and store screenshot assets below the four
  native product roots;
- cross-product icon, in-app brand-mark, adaptive, round, and monochrome asset fingerprints;
- clean in-place installation of predecessor then current Hummingbird artifacts on an iPhone
  17 Pro Simulator and the `hb` Android API 35 emulator; and
- preservation of a clearly synthetic canary written to each application's private data
  area before replacement.

### 2.2 Excluded

- production or patient data;
- Apple Developer, App Store Connect, Google Play Console, Firebase, APNs provider, or
  distribution-signing access;
- a previously distributed Hummingbird IPA, App Store receipt, AAB, or Play-delivered APK;
- push delivery, notification-center rendering, notification permission journeys, and
  notification attachment rendering;
- widget gallery screenshots, widget upgrade persistence, and Live Activity push updates;
- store screenshots, metadata, localization, ratings, privacy declarations, support URLs,
  artwork rights, and release notes; and
- Nightingale notification, widget, shared-container, shortcut, or deep-link design, because
  none is approved or implemented.

### 2.3 Evidence controls

- The predecessor worktree was detached at the exact source SHA above.
- Both predecessor and current Hummingbird apps were built locally from their own checked-out
  source.
- Each emulator was cleaned of the Hummingbird package before predecessor installation.
- The canary contained only a product label and source-SHA fragment; it was not derived from
  a person, encounter, credential, token, or production system.
- Current artifacts were installed over the predecessor without uninstalling between builds.
- The test then read the canary through the application-private storage boundary.
- Repository scans ignore generated Android `build/` and `.gradle/` trees so compiled
  duplicates cannot mask source ownership.
- No production database, route, feature flag, migration, deployment, or store record was
  accessed or changed.

## 3. Canonical identity results

| Surface                      | Hummingbird Staff                                 | Nightingale                | Result                           |
| ---------------------------- | ------------------------------------------------- | -------------------------- | -------------------------------- |
| Apple application bundle     | `net.acumenus.hummingbird`                        | `net.acumenus.nightingale` | Distinct                         |
| Android application ID       | `net.acumenus.hummingbird`                        | `net.acumenus.nightingale` | Distinct                         |
| Display name                 | `Hummingbird`                                     | `Nightingale`              | Distinct                         |
| Apple widget extension       | `net.acumenus.hummingbird.HummingbirdWidgets`     | None                       | Staff-only                       |
| Apple app group              | `group.net.acumenus.hummingbird`                  | None                       | Staff-only                       |
| APNs entitlement             | Hummingbird development/production entitlements   | None                       | Staff-only                       |
| Android widget receiver      | `.widget.HouseGlanceReceiver`                     | None                       | Staff-only                       |
| Android notification surface | Four registered Hummingbird urgency channels only | None                       | No rendered notification claimed |
| Legacy patient-reference ID  | `net.acumenus.hummingbird.patient`                | Not reused                 | Preserved as lineage only        |
| Runtime API boundary         | Staff `/api/mobile/v1`                            | None in foundation app     | Independent                      |

The Hummingbird identity files are byte-for-byte unchanged between the source predecessor
and the current branch for the compared project, manifest, and build configuration paths.
The Hummingbird artwork change therefore remains an update to the existing engineering
application identity; it does not create Nightingale, a patient mode, or a second staff
listing.

## 4. Hummingbird iOS notification audit

### 4.1 Implemented surface

`Hummingbird/Networking/PushManager.swift` imports `UserNotifications`, requests alert,
badge, and sound authorization, registers with APNs when authorized, accepts an APNs device
token, presents foreground banners, and maps a tapped notification's `tab` value to a staff
tab. The source states that notification payloads are generic and PHI-free, but this brand
audit does not independently prove server payload contents.

The Hummingbird app declares:

- sandbox APNs entitlement value `development` for Debug;
- production APNs entitlement value `production` for Release;
- background mode `remote-notification`; and
- the staff app group `group.net.acumenus.hummingbird` in both app entitlement files.

### 4.2 Brand behavior

There is no `UNNotificationServiceExtension`, `UNNotificationContentExtension`, or
`UNNotificationAttachment` source in the Hummingbird iOS root. The repository therefore
does not own a custom notification image or alternate notification-content UI. System
notification presentation inherits the installed Hummingbird application identity and its
AppIcon.

Mechanical controls reject:

- `Nightingale` or `net.acumenus.nightingale` in the Hummingbird project, application
  plist, widget root, Android build identity, or Android manifest;
- `nightingale` in Hummingbird entitlement and push-manager sources; and
- a custom notification extension or attachment entering the audited Hummingbird iOS root
  without an explicit audit update.

### 4.3 Residual holds

- No APNs delivery was triggered.
- No notification-center or lock-screen screenshot was captured.
- No payload, category, action, interruption level, localization, redaction, or deep-link
  allowlist was approved.
- The installed AppIcon is mechanically and visually verified elsewhere in this evidence
  package, but distribution-signing and released-binary behavior remain unverified.

## 5. Hummingbird Android notification audit

### 5.1 Implemented surface

`UrgencyChannels.kt` creates four staff urgency channels:

| Tier | Channel ID      | Importance | Sound    |
| ---- | --------------- | ---------- | -------- |
| T1   | `hb_urgency_t1` | High       | Enabled  |
| T2   | `hb_urgency_t2` | Default    | Disabled |
| T3   | `hb_urgency_t3` | Low        | Disabled |
| T4   | `hb_urgency_t4` | Minimum    | Disabled |

The class explicitly describes this as registration only. The active Android source has no
`FirebaseMessagingService`, `NotificationCompat`, `setSmallIcon`, or
`POST_NOTIFICATIONS` declaration. Accordingly, there is no actual Android notification
small-icon or rendered-notification surface to approve.

### 5.2 Brand behavior and hold

The channel implementation remains under `net.acumenus.hummingbird.notifications`. The
cross-surface verifier rejects Nightingale vocabulary in the active staff identities and
rejects the appearance of an unreviewed notification-posting implementation.

This is a **bounded pass**, not notification parity: if notification posting, FCM, a small
icon, runtime permission handling, or custom content is introduced, this audit must reopen
before release.

## 6. Hummingbird widget and Live Activity audit

### 6.1 Apple extension inventory

The `HummingbirdWidgets` extension owns:

- `HouseGlanceWidget`;
- `ForYouWidget`;
- `JobLiveActivity`;
- the `HummingbirdWidgetsBundle`;
- Hummingbird-only extension entitlements; and
- shared, staff-scoped Live Activity and glance-cache contracts.

Its exact extension bundle ID is
`net.acumenus.hummingbird.HummingbirdWidgets`, its display name is `Hummingbird`, its
extension point is `com.apple.widgetkit-extension`, and its app group is
`group.net.acumenus.hummingbird`.

The widget source contains no Nightingale string or namespace. It contains no PNG, JPEG,
PDF, or SVG artwork. Its empty state says “Open Hummingbird to sync.” The repository
therefore cannot place the supplied Nightingale mark in the widget from an extension-owned
asset. Widget rendering remains a staff data-glance experience and does not become a
patient-facing surface.

### 6.2 Android widget inventory

The active manifest declares the non-exported
`.widget.HouseGlanceReceiver` for `APPWIDGET_UPDATE`. Its source namespace is
`net.acumenus.hummingbird.widget`, and its provider metadata uses the platform loading
layout rather than a product raster preview image. The receiver and provider metadata
contain no Nightingale reference.

### 6.3 Residual holds

- Widget gallery/picker presentation was not captured in this slice.
- An already-installed widget was not migrated across a distributed app upgrade.
- Live Activity state migration and push-to-start behavior were not exercised.
- Product-design and accessibility review of widget content remain separate.
- Adding any explicit widget artwork, preview image, or Nightingale widget reopens this
  audit.

## 7. Nightingale negative-surface audit

The Nightingale foundation applications intentionally have:

- no iOS WidgetKit or ActivityKit import;
- no WidgetBundle, app extension, extension plist, app group, or shared container;
- no iOS UserNotifications import, APNs registration, background remote-notification mode,
  APNs entitlement, notification service, notification content extension, or attachment;
- no Android AppWidget/Glance receiver or shortcut;
- no Android Firebase Messaging service, notification channel, `NotificationCompat`,
  runtime notification permission, or notification-posting code; and
- no API or native network client.

The verifier fails closed if any of these surface classes enter Nightingale application
source before the governed audit is updated. Absence is the approved foundation behavior;
it is not a promise that Nightingale will never have these capabilities.

## 8. Store-listing and screenshot audit

No `fastlane`, `store-listing`, `store_metadata`, or `store-metadata` directory exists
below the Hummingbird or Nightingale iOS/Android product roots. No file matching the audited
App Store/Play Store screenshot naming patterns exists there.

The launcher and splash captures in this evidence directory are engineering evidence, not
store assets. They must not be copied into a listing without the separate checks in the
product identity and support naming checklist.

This negative repository result does not establish:

- that an external Apple or Google listing does not exist;
- that an external listing has the correct icon, name, screenshots, or audience;
- ownership of either supplied mark;
- approved localization, support, privacy, rating, or marketing metadata; or
- authorization to create or modify a store record.

## 9. Cross-product generated-asset controls

`scripts/ci/verify-mobile-brand-assets.sh` now checks:

- the pinned SHA-256 of each supplied source image;
- iOS 1024 px dimensions and opaque RGB encoding;
- Android density-specific launcher, round, adaptive-foreground, and monochrome dimensions;
- transparency policy for adaptive and monochrome resources;
- white-only monochrome silhouette pixels;
- Android 13+ monochrome resource wiring;
- non-identity of the two iOS AppIcon masters;
- non-identity of the two in-app brand marks; and
- non-identity of every Hummingbird/Nightingale Android launcher, round, foreground, and
  monochrome resource at every audited density.

`scripts/ci/verify-mobile-brand-surfaces.sh` adds identity, extension, notification, widget,
shortcut, store-package, and cross-product-name checks. Both scripts run in the independent
macOS mobile-brand CI job.

These controls are source-level drift detectors. They do not replace human inspection of a
compiled binary or external store surface.

The brand scripts passed locally after their CI wiring. The final regression also passed
all three Nightingale contract/candidate verifiers with their negative self-tests, the
dependency-free backend verifier with its negative self-tests, the native no-network
product-boundary verifier, targeted formatting, relative-link validation, Git whitespace
validation, and the changed-slice production-connection-token scan.

## 10. Source-predecessor installed-upgrade evidence

### 10.1 Common protocol

1. Build predecessor Hummingbird from detached source
   `84b5f8305a694128423ae489fa4527e4b542927f`.
2. Build current Hummingbird from
   `06441141edd38ffd79803a5aed8b8906f6a32a20`.
3. Remove any prior Hummingbird install from the engineering emulator.
4. Install the predecessor artifact.
5. Write `hummingbird-upgrade-canary-84b5f830` into application-private storage.
6. Install the current artifact over the predecessor without uninstalling.
7. Read and compare the canary.
8. Verify application and extension identities and, on Android, signer equality.
9. Uninstall the test application and return the iOS simulator to Shutdown. The Android
   emulator may remain booted only for the subsequent native regression suite.

### 10.2 iOS Simulator result

| Assertion           | Predecessor                                   | Current                    | Result                                  |
| ------------------- | --------------------------------------------- | -------------------------- | --------------------------------------- |
| App bundle ID       | `net.acumenus.hummingbird`                    | `net.acumenus.hummingbird` | Pass                                    |
| Widget bundle ID    | `net.acumenus.hummingbird.HummingbirdWidgets` | Same                       | Pass                                    |
| Display name        | `Hummingbird`                                 | `Hummingbird`              | Pass                                    |
| Built short version | `1.0`                                         | `1.0`                      | Equal, release increment still required |
| Built number        | `1`                                           | `1`                        | Equal, release increment still required |
| Private-data canary | Written before replacement                    | Read after replacement     | Pass                                    |

The iOS simulator reassigned the raw data-container filesystem path while preserving its
contents. Equality of a simulator-internal path is not an application contract; survival of
the private canary under the same bundle identity is the relevant continuity result.

Both built `Info.plist` files had SHA-256
`922b1c73aaaa76dc3b956dfac146978f1e38bc76421445b3a9dc20f3aa4efd03`.
The result proves no identity/plist drift in these local artifacts. It does not prove
distribution-signing continuity, App Store receipt continuity, Keychain migration, widget
installation persistence, or compatibility with a previously released IPA.

### 10.3 Android API 35 result

| Assertion           | Predecessor                                                        | Current                    | Result                                      |
| ------------------- | ------------------------------------------------------------------ | -------------------------- | ------------------------------------------- |
| Package ID          | `net.acumenus.hummingbird`                                         | `net.acumenus.hummingbird` | Pass                                        |
| Version name        | `0.1.0`                                                            | `0.1.0`                    | Equal, release increment still required     |
| Version code        | `1`                                                                | `1`                        | Equal, Play upload increment still required |
| V2 signer SHA-256   | `663f448870c7cf490608d8816a6d0d93cc5f441c0ff8190f53f02155f19df281` | Same                       | Pass                                        |
| Private-data canary | Written before `install -r`                                        | Read after `install -r`    | Pass                                        |

Artifact SHA-256 values were:

- predecessor APK:
  `0b04a4b30d74242c69691e6534126ad02b59b9c9f8ae142e7db170729bda71e5`;
- current APK:
  `4fa3ef48278f209674261a953e664ac68ab9633f10aa6292a5c541e00eabd61a`.

Different APK hashes are expected because the icon-bearing current artifact differs from
the predecessor. Matching package ID and development signer allowed the replacement, and
the private canary survived. The signer is an Android debug certificate and cannot be used
as evidence of Play App Signing or upload-key continuity.

### 10.4 Upgrade evidence classification

| Claim                                                                        | Status       | Reason                                               |
| ---------------------------------------------------------------------------- | ------------ | ---------------------------------------------------- |
| Current source can replace predecessor source on clean engineering emulators | Verified     | In-place install and canary checks passed            |
| Hummingbird application identity remained stable                             | Verified     | Exact bundle/package IDs match                       |
| Hummingbird iOS widget extension identity remained stable                    | Verified     | Exact extension ID matches                           |
| Android debug signer remained stable on this workstation                     | Verified     | Exact certificate digest matches                     |
| Released user data will migrate correctly                                    | Not verified | No released binary or production schema sampled      |
| Keychain/Keystore state will migrate correctly                               | Not verified | Canary used ordinary app-private files only          |
| Existing widget/Live Activity installation survives                          | Not verified | No installed extension state migrated                |
| App Store/Play Store accepts the new artifact                                | Not verified | Version/build not incremented; consoles not accessed |
| Distribution signing is continuous                                           | Not verified | Local simulator/debug signatures only                |
| Rollback preserves data                                                      | Not verified | Downgrade and store rollback were not exercised      |

## 11. Findings and required follow-up

### 11.1 Passed engineering controls

- Hummingbird and Nightingale retain distinct active product identities.
- Hummingbird's widget, app-group, APNs, and Android widget namespaces remain staff-only.
- Nightingale remains widget-, extension-, shortcut-, notification-, push-, and
  network-free.
- No repository-owned store-listing package exists under the audited native roots.
- Every active generated launcher/brand-mark asset compared across products is distinct.
- The current Hummingbird app replaces the repository predecessor in place on both
  emulators while preserving a synthetic private-data canary.

### 11.2 Open engineering/release findings

1. Hummingbird iOS builds report short version `1.0` even though the project build setting
   records marketing version `0.1.0`. Release engineering must choose and mechanically pin
   one canonical version source before archiving.
2. Predecessor/current iOS build numbers and Android version codes are both `1`. A real
   update must use approved monotonically increasing values.
3. Android notification channels exist without a posting implementation. No Android
   notification icon or visual behavior can be approved until posting exists.
4. No retained released artifact was available for binary-to-binary migration testing.
5. No store console or distribution-signing evidence exists.
6. Widget installation persistence, Keychain/Keystore persistence, deep links, analytics,
   crash reporting, and downgrade/rollback remain outside this test.

### 11.3 Release acceptance evidence still required

Before closing the external portion of Stream B:

- obtain artwork ownership and distribution-rights approval for both marks;
- identify retained, verifiably released Hummingbird iOS and Android predecessor artifacts;
- verify distribution application IDs, entitlements, signing chains, and store records;
- set and verify monotonic version/build values;
- run released-artifact-to-release-candidate upgrade tests for app-private data,
  Keychain/Keystore state, installed widgets, Live Activities, deep links, and any approved
  notification state;
- capture Hummingbird notification and widget surfaces only after the relevant capability
  exists and uses reviewed generic content;
- create and review store screenshots/metadata with no patient information, internal host,
  test hook, stale name, or cross-product asset;
- independently review product design and accessibility;
- attach the evidence to the product identity and support naming checklist; and
- obtain exact-SHA CI and protected-main release approval before any deployment workflow.

## 12. Reproduction commands

The stable source checks are:

```bash
bash -n scripts/ci/verify-mobile-brand-assets.sh
bash -n scripts/ci/verify-mobile-brand-surfaces.sh
bash scripts/ci/verify-mobile-brand-assets.sh .
bash scripts/ci/verify-mobile-brand-surfaces.sh .
```

The installed-upgrade protocol intentionally remains a manual engineering procedure until
the team provides retained released artifacts and approved distribution credentials.
Automating only the local-debug predecessor would risk making a source-level result look
like store-release evidence.
