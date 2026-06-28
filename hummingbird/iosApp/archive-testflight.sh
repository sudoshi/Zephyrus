#!/usr/bin/env bash
#
# archive-testflight.sh — archive Hummingbird (Release), export a signed App Store .ipa,
# and optionally upload it to TestFlight. Pure xcrun/xcodebuild.
#
# ── BEFORE THE FIRST REAL UPLOAD (see docs/hummingbird/TESTFLIGHT.md) ───────────────
#   1. Set AppConfig.publicHost in Networking/APIClient.swift to your real HTTPS BFF host
#      (Release builds point there; testers can't reach your Mac's LAN).
#   2. Create the app record in App Store Connect for bundle id net.acumenus.hummingbird.
#   3. For headless upload, create an App Store Connect API key (Users & Access → Integrations
#      → Keys) and export its coordinates before running:
#        export ASC_KEY_ID=XXXXXXXXXX
#        export ASC_ISSUER_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
#        export ASC_KEY_PATH=/absolute/path/AuthKey_XXXXXXXXXX.p8
#      Without these the script stops at the .ipa and prints upload instructions.
#
# Usage:  ./archive-testflight.sh [BUILD_NUMBER]
#   BUILD_NUMBER defaults to a UTC timestamp (App Store Connect requires it to increase
#   for each upload of the same marketing version).
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_NUMBER="${1:-$(date -u +%Y%m%d%H%M)}"
ARCHIVE="$SCRIPT_DIR/.build-archive/Hummingbird.xcarchive"
EXPORT_DIR="$SCRIPT_DIR/.build-archive/export"

echo "▸ Archiving (Release, build $BUILD_NUMBER) ..."
xcodebuild \
  -project "$SCRIPT_DIR/Hummingbird.xcodeproj" \
  -scheme Hummingbird \
  -configuration Release \
  -destination 'generic/platform=iOS' \
  -archivePath "$ARCHIVE" \
  -allowProvisioningUpdates \
  CURRENT_PROJECT_VERSION="$BUILD_NUMBER" \
  archive

echo "▸ Exporting signed App Store .ipa ..."
rm -rf "$EXPORT_DIR"
xcodebuild -exportArchive \
  -archivePath "$ARCHIVE" \
  -exportPath "$EXPORT_DIR" \
  -exportOptionsPlist "$SCRIPT_DIR/ExportOptions.plist" \
  -allowProvisioningUpdates

IPA="$(ls "$EXPORT_DIR"/*.ipa 2>/dev/null | head -1 || true)"
[ -n "$IPA" ] || { echo "✗ No .ipa produced — check the export log above."; exit 1; }
echo "✅ Exported: $IPA"

if [[ -n "${ASC_KEY_ID:-}" && -n "${ASC_ISSUER_ID:-}" && -n "${ASC_KEY_PATH:-}" ]]; then
  echo "▸ Uploading to TestFlight ..."
  # altool finds the key by id in ~/.appstoreconnect/private_keys; stage it there.
  mkdir -p "$HOME/.appstoreconnect/private_keys"
  cp -f "$ASC_KEY_PATH" "$HOME/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8"
  xcrun altool --upload-app -f "$IPA" -t ios \
    --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
  echo "✅ Uploaded — appears in App Store Connect → TestFlight after processing (a few min)."
else
  echo
  echo "ℹ️  ASC_* API key vars not set — skipped upload. Upload the .ipa with either:"
  echo "    • Xcode → Window → Organizer → Distribute App → TestFlight, or"
  echo "    • xcrun altool --upload-app -f \"$IPA\" -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>"
fi
