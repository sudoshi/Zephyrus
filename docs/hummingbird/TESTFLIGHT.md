# TestFlight — Zephyrus-Hummingbird & Zephyrus-Nightingale

One script ships both iOS apps: **`./testflight.sh`** at the repo root.

| App Store Connect record | Bundle id | App id | Source |
|---|---|---|---|
| **Zephyrus-Hummingbird** | `net.acumenus.hummingbird` | 6785290384 | `hummingbird/iosApp` (scheme `Hummingbird`) |
| **Zephyrus-Nightingale** | `net.acumenus.nightingale` | 6794950746 | `hummingbird/iosPatientApp` (scheme `HummingbirdPatient`) |

Nightingale is the patient-facing app. Only what Apple sees is branded Nightingale —
the target, scheme, and directory keep the `HummingbirdPatient` name, and the backend
middleware / Android package / CI guards that share that name are untouched.

## Quick start

```bash
./testflight.sh doctor              # verify credentials, toolchain, signing, ASC reachability
./testflight.sh ship hummingbird    # archive → sign → upload
./testflight.sh ship nightingale
./testflight.sh ship all            # both, sequentially
```

Then watch it land:

```bash
./testflight.sh status nightingale        # defaults to the build you just shipped
./testflight.sh wait   nightingale        # poll until processing finishes (5-20 min)
./testflight.sh builds hummingbird        # recent upload history
```

## Flags for `ship`

| Flag | Effect |
|---|---|
| `--build <n>` | Explicit build number. Default is a UTC timestamp (`yyyymmddHHMM`). |
| `--no-upload` | Stop at the signed `.ipa` — useful for verifying signing without shipping. |
| `--wait` | Block until App Store Connect finishes processing. |
| `--to <group>` | After processing, hand the build to a beta tester group. Implies `--wait`. |

```bash
./testflight.sh ship nightingale --wait --to "Internal"
```

## Credentials — `.appledeploy`

Everything lives in `.appledeploy` at the repo root. It is **gitignored**;
`.appledeploy.example` is the committed template.

```bash
cp .appledeploy.example .appledeploy   # then fill in and run ./testflight.sh doctor
```

| Key | Where it comes from |
|---|---|
| `APPLE_TEAM_ID` | Apple Developer → Membership details |
| `ASC_KEY_ID` | App Store Connect → Users & Access → Integrations → App Store Connect API |
| `ASC_ISSUER_ID` | Top of that same page (one per team) |
| `ASC_KEY_PATH` | Where you saved `AuthKey_<KEY_ID>.p8` |
| `<APP>_APP_ID` | Numeric app id — `./testflight.sh apps` prints them |
| `TESTFLIGHT_GROUP` | Optional default tester group for `--to` / `distribute` |

The **only real secret is the `.p8` private key**, which lives outside the repo
(`~/.appstoreconnect/private_keys/`). It can be downloaded **exactly once** when you
generate it — keep a backup. Losing it means revoking the key and generating a new one.
The API key needs the **App Manager** role to upload.

## Adding a third app

Append the slug to `APPS` in `.appledeploy` and add a matching uppercase block:

```sh
APPS="hummingbird nightingale eddy"

EDDY_NAME="Zephyrus-Eddy"
EDDY_APP_ID=6800000000
EDDY_BUNDLE_ID=net.acumenus.eddy
EDDY_DIR=hummingbird/iosEddyApp
EDDY_XCODEPROJ=Eddy.xcodeproj
EDDY_SCHEME=Eddy
```

The app directory needs an `ExportOptions.plist` (copy either existing one). No script
changes required.

## What's already wired

- **Signing**: automatic, team `TKXPY255A2`. `-allowProvisioningUpdates` creates
  profiles on demand — Nightingale's was minted on its first archive.
- **Project generation**: `ship` runs `xcodegen generate` first, so a stale
  `.xcodeproj` can never ship the wrong bundle id. Nightingale's `.xcodeproj` is
  tracked in git and CI diffs it against `project.yml`
  (`scripts/ci/verify-hummingbird-patient-xcode-project.sh`) — commit it after any
  `project.yml` change.
- **APNs per config** (Hummingbird only): Debug → `Hummingbird.entitlements`
  (`development`); Release → `Hummingbird.production.entitlements` (`production`).
  TestFlight requires production. Nightingale has no push entitlement yet.
- **Export compliance**: `ITSAppUsesNonExemptEncryption = false` on both (standard
  HTTPS only) — no per-upload prompt.
- **Release backend**: Hummingbird points at `https://zephyrus.acumenus.net`
  (`TransportSecurityPolicy.productionHost`).

## Gotchas

- **Build numbers must increase** within a marketing version. Both apps ship
  `CFBundleShortVersionString = 1.0` (hardcoded in `Info.plist`, which overrides
  `MARKETING_VERSION` in `project.yml`). The timestamp default keeps climbing, so this
  resolves itself — but if you pass `--build` manually, go higher than the last one
  `./testflight.sh builds <app>` reports.
- **Nightingale's patient API is off by default.** `HBPPatientAPIEnabled = false` and
  `HBPPatientAPIBaseURL = ""` in `Info.plist`, so a TestFlight build runs without a
  live backend until those are set. Flip them in
  `hummingbird/iosPatientApp/project.yml` before a tester-facing build that needs real
  data.
- **Processing lag.** A build is not visible to the API for a minute or two after
  upload; `status` reports `not visible yet` in that window, which is normal.
- **`altool` can exit 0 on a failed upload.** Its own `--help` claims "0 success,
  1 failure", but on 2026-07-26 App Store Connect's `/v1/apps` endpoint returned
  500s and altool logged `ERROR: [altool] Failed to determine the Apple ID from
  Bundle ID ...`, uploaded nothing, and still exited 0. `ship` therefore does not
  trust the exit code: it scans altool's output for `ERROR:` **and** confirms the
  build is actually visible in App Store Connect before reporting success. If you
  ever script around this file, do the same — exit code alone is not evidence.
- **Why `--upload-package`, not `--upload-app`.** `--upload-app` first resolves the
  bundle id to an Apple ID via `/v1/apps` — the exact endpoint that outage took
  down. Since `.appledeploy` already records each Apple ID, `ship` passes it
  directly via `--upload-package` and skips the lookup. That path kept working
  throughout the outage. The `--upload-app` fallback only triggers if `APP_ID` is
  missing from the config.
- **Apple-side outages** are worth ruling out before debugging your own setup:
  <https://developer.apple.com/system-status/>. `/v1/builds` stayed healthy while
  `/v1/apps` was down, so partial failure is possible.
- **External testers** need a one-time Beta App Review. Internal testers (up to 100
  App Store Connect users) get builds immediately.
