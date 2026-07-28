# Nightingale TestFlight and iOS distribution

**Status:** Tooling boundary established; no signing, upload, tester distribution, pilot,
patient access, or release is authorized by this record.

**Authoritative source root:** `nightingale/iosApp`

**Scheme:** `Nightingale`

**Bundle identifier:** `net.acumenus.nightingale`

## 1. Product boundary

Nightingale is an independent patient-facing application. The only repository iOS source
permitted to build its bundle identifier is:

```text
nightingale/iosApp/
├── Nightingale.xcodeproj
├── Nightingale/
├── NightingaleTests/
├── NightingaleUITests/
├── ExportOptions.plist
└── project.yml
```

The former `hummingbird/iosPatientApp` target remains Hummingbird Patient migration
evidence with bundle identifier `net.acumenus.hummingbird.patient`. It must not be renamed,
archived, exported, or uploaded as Nightingale. The non-secret `.appledeploy.example`
registry, native project verifier, and product-boundary CI enforce that separation.

The repository previously received a mainline change that pointed the legacy target at
the Nightingale bundle identifier. This development stream reverses that collision and
makes the independent source mapping mechanical. Repository comments about an earlier
archive or App Store record are not accepted as signed-artifact, upload, processing,
tester-distribution, or release evidence; this stream has not queried App Store Connect or
performed an upload.

## 2. Shared helper, independent registry entry

The repository-level `./testflight.sh` helper supports multiple independently registered
apps. For Nightingale, `.appledeploy.example` requires:

```sh
NIGHTINGALE_NAME="Zephyrus-Nightingale"
NIGHTINGALE_BUNDLE_ID=net.acumenus.nightingale
NIGHTINGALE_DIR=nightingale/iosApp
NIGHTINGALE_XCODEPROJ=Nightingale.xcodeproj
NIGHTINGALE_SCHEME=Nightingale
```

The real `.appledeploy` file is gitignored. App Store Connect API credentials and the
private `.p8` key remain outside the repository. No credential, patient information, or
tester contact belongs in source control.

The app-local `ExportOptions.plist` configures an App Store Connect export for the declared
Apple team. It deliberately disables Apple-managed build-number mutation so the reviewed
build number remains explicit. An export profile is configuration, not release approval.

## 3. Safe command progression

The helper exposes:

```bash
./testflight.sh help
./testflight.sh doctor
./testflight.sh apps
./testflight.sh builds nightingale
./testflight.sh status nightingale <build>
./testflight.sh groups nightingale
```

The following command creates a signed local archive and IPA but does not upload:

```bash
./testflight.sh ship nightingale --build <monotonic-build> --no-upload
```

Upload and tester distribution are state-changing external release actions:

```bash
./testflight.sh ship nightingale --build <monotonic-build> --wait
./testflight.sh distribute nightingale <build> <approved-group>
```

They require a separately authorized release decision after every gate below is closed.
Neither this document nor green CI grants that authorization.

## 4. Required pre-upload evidence

- [ ] Protected `main` contains the reviewed Nightingale change and is clean and
      synchronized.
- [ ] CI is green for the exact source SHA with no rerun masking an unexplained failure.
- [ ] The generated Xcode project matches `nightingale/iosApp/project.yml`.
- [ ] Debug unit and UI suites pass on the supported iOS Simulator matrix.
- [ ] A device Release archive builds from `nightingale/iosApp`, scheme `Nightingale`.
- [ ] The archive and exported IPA identify only `net.acumenus.nightingale`.
- [ ] The archive contains no legacy `hummingbird.patient` identity, Debug test hook,
      pseudolocale, synthetic patient, credential, or unapproved endpoint.
- [ ] The exact archive and IPA SHA-256 values are recorded.
- [ ] Marketing version and build number are greater than every accepted prior build for
      that marketing version.
- [ ] Code-signing certificate, provisioning profile, entitlements, team, and expiration
      are inspected from the exact archive.
- [ ] Privacy manifest, App Store privacy answers, export-compliance answer, support URL,
      privacy URL, screenshots, subtitle, description, age rating, and accessibility
      claims match the exact binary and approved product state.
- [ ] The seven-background catalog has durable rights/attribution evidence and named
      patient/advisor accessibility approval for external distribution.
- [ ] Identity, source, content, communication, accessibility, privacy/security, clinical
      safety, support, and release approvals are recorded by named owners.
- [ ] Pilot cohort, facility/unit scope, language, exclusions, support hours, expiry,
      rollback, kill switch, incident routing, and go/no-go authority are signed.

## 5. Required post-upload evidence

- [ ] App Store Connect reports the exact bundle identifier, marketing version, and build
      number.
- [ ] The processed build maps to the recorded source, archive, and IPA hashes.
- [ ] Processing completes without unexpected entitlement, privacy, symbol, or compliance
      changes.
- [ ] Only the approved internal or external tester group receives the build.
- [ ] External Beta App Review status is recorded when applicable.
- [ ] Installation and upgrade are tested on supported physical devices without erasing
      protected patient state or leaking the privacy cover.
- [ ] Launch, background/foreground, orientation, maximum text, VoiceOver, reduced motion,
      increased contrast, imagery-hidden behavior, offline behavior, and rollback are
      verified on the distributed build.
- [ ] Any connected capability is reauthenticated, reauthorized, source-freshness checked,
      correction/retraction aware, and generic on withheld or revoked access.
- [ ] Distribution, support, security, privacy, and clinical owners sign the final
      go/no-go record before a pilot is activated.

## 6. Current hold

Nightingale is currently an offline, default-deny foundation. It has no approved identity
provider, current-inpatient source adapter, patient API operation, clinical projection,
message capability, notification provider, or pilot cohort. A signed archive can be useful
engineering evidence, but it must not be distributed to patients or described as a
patient-ready product until the plan’s independent approvals and pilot gates are complete.
