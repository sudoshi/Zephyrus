# Hummingbird → TestFlight

Everything here is driven from `hummingbird/iosApp/` via `xcrun`/`xcodebuild`. Two scripts:

| Script | What it does |
|---|---|
| `./deploy-device.sh` | Debug build → install → launch on the paired iPhone (LAN backend). |
| `./archive-testflight.sh [build#]` | Release archive → signed App Store `.ipa` → optional TestFlight upload. |

## What's already wired
- **Signing**: automatic, team `TKXPY255A2` (`project.yml`).
- **APNs per config**: Debug → `Hummingbird.entitlements` (`development`); Release → `Hummingbird.production.entitlements` (`production`). TestFlight needs production.
- **Export compliance**: `ITSAppUsesNonExemptEncryption = false` (app uses only standard HTTPS) — no per-upload prompt.
- **Release backend**: `AppConfig` (Release branch) uses `https://<publicHost>` + `wss`.
- `ExportOptions.plist`: App Store distribution, automatic signing, local `.ipa` export.

## Before the first real upload — 3 things only you can do
1. **Public BFF host.** Set `publicHost` in `Networking/APIClient.swift` (Release branch) to your deployed **HTTPS** BFF domain. Testers can't reach your Mac's LAN, so this must be a real TLS host.
2. **App Store Connect app record.** Create an app for bundle id `net.acumenus.hummingbird` (App Store Connect → Apps → +). Required before any upload is accepted.
3. **Upload auth (for headless `xcrun` upload).** App Store Connect → Users & Access → Integrations → Keys → generate a key (App Manager role). Then:
   ```bash
   export ASC_KEY_ID=XXXXXXXXXX
   export ASC_ISSUER_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
   export ASC_KEY_PATH=/absolute/path/AuthKey_XXXXXXXXXX.p8
   ```
   (Or skip these and upload the `.ipa` via Xcode → Organizer → Distribute App → TestFlight.)

## Run it
```bash
cd hummingbird/iosApp
./archive-testflight.sh            # build number defaults to a UTC timestamp
```
After upload, the build shows in **App Store Connect → TestFlight** once processing finishes (a few minutes). Add internal testers (immediate) or external testers (one-time Beta App Review).

## Notes
- Each upload needs a **higher build number** for the same `MARKETING_VERSION` (`0.1.0`). The script's timestamp default handles this; pass an explicit number as `$1` if you prefer.
- Build artifacts land in `.build-archive/` (gitignored).
