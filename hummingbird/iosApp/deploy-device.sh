#!/usr/bin/env bash
#
# deploy-device.sh — build, install, and launch Hummingbird on the paired iPhone.
# Pure xcrun/xcodebuild/devicectl; no Xcode GUI needed.
#
# Prereqs (one-time): Apple ID added in Xcode → Settings → Accounts, and the iPhone
# paired for development (Xcode → Window → Devices and Simulators + Trust on phone).
#
# Override the device IDs via env if you swap phones:
#   HB_BUILD_UDID    — xcodebuild -destination id   (from: xcodebuild -showdestinations ...)
#   HB_DEVICECTL_ID  — devicectl --device id        (from: xcrun devicectl list devices)
#
set -euo pipefail

BUILD_UDID="${HB_BUILD_UDID:-00008130-000579443A79001C}"
DEVICECTL_ID="${HB_DEVICECTL_ID:-EC2696E6-CDB3-5F98-8C7A-106CCD45A9B1}"
BUNDLE_ID="net.acumenus.hummingbird"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DERIVED="$SCRIPT_DIR/.build-device"
APP="$DERIVED/Build/Products/Debug-iphoneos/Hummingbird.app"

echo "▸ Building + signing for device $BUILD_UDID ..."
xcodebuild \
  -project "$SCRIPT_DIR/Hummingbird.xcodeproj" \
  -scheme Hummingbird \
  -configuration Debug \
  -destination "id=$BUILD_UDID" \
  -derivedDataPath "$DERIVED" \
  -allowProvisioningUpdates \
  -allowProvisioningDeviceRegistration \
  build

echo "▸ Installing onto $DEVICECTL_ID ..."
xcrun devicectl device install app --device "$DEVICECTL_ID" "$APP"

echo "▸ Launching $BUNDLE_ID ..."
xcrun devicectl device process launch --device "$DEVICECTL_ID" "$BUNDLE_ID"

echo
echo "✅ Hummingbird is running on the iPhone."
echo "   For live data: phone + Mac on the same Wi-Fi, and run the BFF bound to the LAN:"
echo "     php artisan serve --host=0.0.0.0 --port=8001   (Reverb on 8080)"
echo "   If the Mac's IP isn't 192.168.1.35, set HB_HOST in the Xcode scheme's env vars."
