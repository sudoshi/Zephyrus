#!/usr/bin/env bash

set -euo pipefail

repo_root="${1:-.}"
cd "$repo_root"

fail() {
    echo "Mobile brand surface violation: $1" >&2
    exit 1
}

require_file() {
    [[ -f "$1" ]] || fail "missing required file $1"
}

require_directory() {
    [[ -d "$1" ]] || fail "missing required directory $1"
}

require_match() {
    local pattern="$1"
    local file="$2"
    rg -q -- "$pattern" "$file" || fail "$file does not contain required pattern: $pattern"
}

forbid_match() {
    local pattern="$1"
    shift
    if rg -n -i --glob '!**/build/**' --glob '!**/.gradle/**' -- "$pattern" "$@" >/dev/null
    then
        fail "forbidden pattern '$pattern' found under $*"
    fi
}

hummingbird_ios_project="hummingbird/iosApp/project.yml"
hummingbird_ios_plist="hummingbird/iosApp/Hummingbird/Info.plist"
hummingbird_widget_root="hummingbird/iosApp/HummingbirdWidgets"
hummingbird_widget_plist="$hummingbird_widget_root/Info.plist"
hummingbird_widget_entitlements="$hummingbird_widget_root/HummingbirdWidgets.entitlements"
hummingbird_android_gradle="hummingbird/androidApp/app/build.gradle.kts"
hummingbird_android_manifest="hummingbird/androidApp/app/src/main/AndroidManifest.xml"

nightingale_ios_project="nightingale/iosApp/project.yml"
nightingale_ios_plist="nightingale/iosApp/Nightingale/Info.plist"
nightingale_android_gradle="nightingale/androidApp/app/build.gradle.kts"
nightingale_android_manifest="nightingale/androidApp/app/src/main/AndroidManifest.xml"
nightingale_android_strings="nightingale/androidApp/app/src/main/res/values/strings.xml"

for file in \
    "$hummingbird_ios_project" \
    "$hummingbird_ios_plist" \
    "$hummingbird_widget_plist" \
    "$hummingbird_widget_entitlements" \
    "$hummingbird_android_gradle" \
    "$hummingbird_android_manifest" \
    "$nightingale_ios_project" \
    "$nightingale_ios_plist" \
    "$nightingale_android_gradle" \
    "$nightingale_android_manifest" \
    "$nightingale_android_strings"
do
    require_file "$file"
done
require_directory "$hummingbird_widget_root"

# Stable application identities are what make the Hummingbird icon replacement an
# in-place staff-app update and Nightingale an independent product. The former patient
# reference identifiers stay distinct migration evidence.
require_match 'PRODUCT_BUNDLE_IDENTIFIER: net\.acumenus\.hummingbird$' "$hummingbird_ios_project"
require_match 'applicationId = "net\.acumenus\.hummingbird"' "$hummingbird_android_gradle"
require_match '<string>Hummingbird</string>' "$hummingbird_ios_plist"
require_match 'android:label="Hummingbird"' "$hummingbird_android_manifest"

require_match 'PRODUCT_BUNDLE_IDENTIFIER: net\.acumenus\.nightingale$' "$nightingale_ios_project"
require_match 'applicationId = "net\.acumenus\.nightingale"' "$nightingale_android_gradle"
require_match '<string>Nightingale</string>' "$nightingale_ios_plist"
require_match '<string name="app_name">Nightingale</string>' "$nightingale_android_strings"
require_match 'android:label="@string/app_name"' "$nightingale_android_manifest"

require_match 'PRODUCT_BUNDLE_IDENTIFIER: net\.acumenus\.hummingbird\.patient$' \
    hummingbird/iosPatientApp/project.yml
require_match 'applicationId = "net\.acumenus\.hummingbird\.patient"' \
    hummingbird/androidPatientApp/app/build.gradle.kts

# Both host apps must use only their own verified launcher resources.
require_match 'ASSETCATALOG_COMPILER_APPICON_NAME: AppIcon' "$hummingbird_ios_project"
require_match 'ASSETCATALOG_COMPILER_APPICON_NAME: AppIcon' "$nightingale_ios_project"
for manifest in "$hummingbird_android_manifest" "$nightingale_android_manifest"
do
    require_match 'android:icon="@mipmap/ic_launcher"' "$manifest"
    require_match 'android:roundIcon="@mipmap/ic_launcher_round"' "$manifest"
done

hummingbird_icon_sets="$(
    find hummingbird/iosApp/Hummingbird/Assets.xcassets -type d -name '*.appiconset' | wc -l | tr -d ' '
)"
nightingale_icon_sets="$(
    find nightingale/iosApp/Nightingale/Assets.xcassets -type d -name '*.appiconset' | wc -l | tr -d ' '
)"
[[ "$hummingbird_icon_sets" == "1" ]] || fail "Hummingbird must have exactly one AppIcon set"
[[ "$nightingale_icon_sets" == "1" ]] || fail "Nightingale must have exactly one AppIcon set"

# Hummingbird owns the current staff widgets and live activity. Their extension identity,
# display name, app group, source namespace, and user-facing empty state remain staff-only.
require_match 'HummingbirdWidgets:' "$hummingbird_ios_project"
require_match 'PRODUCT_BUNDLE_IDENTIFIER: net\.acumenus\.hummingbird\.HummingbirdWidgets' \
    "$hummingbird_ios_project"
require_match '<string>Hummingbird</string>' "$hummingbird_widget_plist"
require_match '<string>com\.apple\.widgetkit-extension</string>' "$hummingbird_widget_plist"
require_match '<string>group\.net\.acumenus\.hummingbird</string>' \
    "$hummingbird_widget_entitlements"
require_match 'struct HummingbirdWidgetsBundle: WidgetBundle' \
    "$hummingbird_widget_root/HummingbirdWidgetsBundle.swift"
require_match 'Open Hummingbird to sync' \
    "$hummingbird_widget_root/HouseGlanceWidget.swift"
forbid_match 'nightingale' "$hummingbird_widget_root"

widget_raster="$(
    find "$hummingbird_widget_root" -type f \
        \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.pdf' -o -iname '*.svg' \) \
        -print -quit
)"
[[ -z "$widget_raster" ]] || fail "Hummingbird widget contains an unreviewed raster/vector asset: $widget_raster"

# The Android staff widget has a Hummingbird receiver, staff package, and no custom preview
# artwork that could drift to the wrong product.
require_match 'android:name="\.widget\.HouseGlanceReceiver"' "$hummingbird_android_manifest"
require_match 'android:exported="false"' "$hummingbird_android_manifest"
require_match 'android\.appwidget\.action\.APPWIDGET_UPDATE' "$hummingbird_android_manifest"
require_match 'package net\.acumenus\.hummingbird\.widget' \
    hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/widget/HouseGlanceReceiver.kt
require_match 'android:initialLayout="@layout/glance_default_loading_layout"' \
    hummingbird/androidApp/app/src/main/res/xml/house_glance_widget_info.xml
forbid_match 'nightingale' \
    hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/widget \
    hummingbird/androidApp/app/src/main/res/xml/house_glance_widget_info.xml

# Nightingale has no approved widget, extension, Live Activity, app group, shared container,
# shortcut, or Android receiver. Adding one must first update the governed audit.
forbid_match \
    'WidgetKit|ActivityKit|WidgetBundle|com\.apple\.widgetkit-extension|NSExtension|application-groups|AppShortcut' \
    nightingale/iosApp
forbid_match \
    'appwidget|GlanceAppWidget|<receiver|android\.appwidget|shortcut' \
    nightingale/androidApp/app/src/main

# Hummingbird iOS notifications use the system-owned app identity. There is no custom
# notification-service/content extension or notification attachment artwork. APNs
# entitlements and the shared widget group remain under the staff namespace.
require_match '<string>development</string>' \
    hummingbird/iosApp/Hummingbird/Hummingbird.entitlements
require_match '<string>production</string>' \
    hummingbird/iosApp/Hummingbird/Hummingbird.production.entitlements
require_match '<string>group\.net\.acumenus\.hummingbird</string>' \
    hummingbird/iosApp/Hummingbird/Hummingbird.entitlements
require_match '<string>group\.net\.acumenus\.hummingbird</string>' \
    hummingbird/iosApp/Hummingbird/Hummingbird.production.entitlements
forbid_match 'nightingale' \
    hummingbird/iosApp/Hummingbird/Hummingbird.entitlements \
    hummingbird/iosApp/Hummingbird/Hummingbird.production.entitlements \
    hummingbird/iosApp/Hummingbird/Networking/PushManager.swift
forbid_match \
    'UNNotificationServiceExtension|UNNotificationContentExtension|UNNotificationAttachment' \
    hummingbird/iosApp

# Android staff code currently registers channels but does not post notifications or own
# FCM credentials. Therefore no Android small-icon visual is claimed by this audit.
require_match 'package net\.acumenus\.hummingbird\.notifications' \
    hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/notifications/UrgencyChannels.kt
forbid_match \
    'FirebaseMessagingService|NotificationCompat|setSmallIcon|POST_NOTIFICATIONS' \
    hummingbird/androidApp/app/src/main \
    hummingbird/androidApp/app/build.gradle.kts

# Nightingale remains notification- and push-free on both platforms.
forbid_match \
    'UserNotifications|UNNotification|registerForRemoteNotifications|remote-notification|aps-environment' \
    nightingale/iosApp
forbid_match \
    'FirebaseMessaging|NotificationCompat|NotificationChannel|POST_NOTIFICATIONS' \
    nightingale/androidApp/app/src/main \
    nightingale/androidApp/app/build.gradle.kts

# No repository-owned App Store/Play Store listing package exists. When one is added it must
# be reviewed as a new governed surface instead of silently entering a release bundle.
store_directory="$(
    find hummingbird/iosApp hummingbird/androidApp nightingale/iosApp nightingale/androidApp \
        -type d \
        \( -iname fastlane -o -iname store-listing -o -iname store_metadata -o -iname store-metadata \) \
        -print -quit
)"
[[ -z "$store_directory" ]] || fail "unreviewed store-listing directory found: $store_directory"

store_asset="$(
    find hummingbird/iosApp hummingbird/androidApp nightingale/iosApp nightingale/androidApp \
        -type f \
        \( -iname '*store*screenshot*' -o -iname '*appstore*png' -o -iname '*playstore*png' \) \
        -print -quit
)"
[[ -z "$store_asset" ]] || fail "unreviewed store-listing asset found: $store_asset"

# Cross-product names and historical patient identifiers cannot enter the active
# application/extension manifests or build identities.
forbid_match \
    'net\.acumenus\.nightingale|>Nightingale<|"Nightingale"' \
    "$hummingbird_ios_project" \
    "$hummingbird_ios_plist" \
    "$hummingbird_widget_root" \
    "$hummingbird_android_gradle" \
    "$hummingbird_android_manifest"
forbid_match \
    'net\.acumenus\.hummingbird|>Hummingbird(?: Patient)?<|"Hummingbird(?: Patient)?"' \
    "$nightingale_ios_project" \
    "$nightingale_ios_plist" \
    "$nightingale_android_manifest" \
    "$nightingale_android_strings"

echo "Mobile notification/widget/upgrade/store brand surfaces verified."
