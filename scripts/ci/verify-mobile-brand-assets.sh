#!/usr/bin/env bash

set -euo pipefail

repo_root="${1:-.}"
cd "$repo_root"

expected_hummingbird_source="5ecc70c2a85d9d6471aabb76cbc49b42a976f6b66ba22c84af065a625fe6e8ad"
expected_nightingale_source="e97191b7d1eccc32c6a1aa95f0ba2329e1cfb4c1ac1c9b3d2d540872b3327c76"

actual_hummingbird_source="$(
    shasum -a 256 hummingbird/brand/source/Hummingbird.png | awk '{print $1}'
)"
actual_nightingale_source="$(
    shasum -a 256 nightingale/brand/source/Nightingale.png | awk '{print $1}'
)"

[[ "$actual_hummingbird_source" == "$expected_hummingbird_source" ]]
[[ "$actual_nightingale_source" == "$expected_nightingale_source" ]]

/usr/bin/swift scripts/brand/verify-app-icon.swift \
    hummingbird/iosApp/Hummingbird/Assets.xcassets/AppIcon.appiconset/icon-1024.png \
    1024 opaque
/usr/bin/swift scripts/brand/verify-app-icon.swift \
    nightingale/iosApp/Nightingale/Assets.xcassets/AppIcon.appiconset/icon-1024.png \
    1024 opaque

for product in hummingbird nightingale
do
    for density_and_sizes in mdpi:48:108 hdpi:72:162 xhdpi:96:216 xxhdpi:144:324 xxxhdpi:192:432
    do
        density="${density_and_sizes%%:*}"
        remaining_sizes="${density_and_sizes#*:}"
        launcher_pixels="${remaining_sizes%%:*}"
        foreground_pixels="${remaining_sizes##*:}"
        resource_root="$product/androidApp/app/src/main/res/mipmap-$density"

        /usr/bin/swift scripts/brand/verify-app-icon.swift \
            "$resource_root/ic_launcher.png" "$launcher_pixels" opaque
        /usr/bin/swift scripts/brand/verify-app-icon.swift \
            "$resource_root/ic_launcher_round.png" "$launcher_pixels" opaque
        /usr/bin/swift scripts/brand/verify-app-icon.swift \
            "$resource_root/ic_launcher_foreground.png" "$foreground_pixels" transparent
        /usr/bin/swift scripts/brand/verify-app-icon.swift \
            "$resource_root/ic_launcher_monochrome.png" "$foreground_pixels" monochrome
    done

    for adaptive_icon in \
        "$product/androidApp/app/src/main/res/mipmap-anydpi-v33/ic_launcher.xml" \
        "$product/androidApp/app/src/main/res/mipmap-anydpi-v33/ic_launcher_round.xml"
    do
        grep -Eq '<monochrome android:drawable="@mipmap/ic_launcher_monochrome" />' \
            "$adaptive_icon"
    done
done

if cmp -s \
    hummingbird/iosApp/Hummingbird/Assets.xcassets/AppIcon.appiconset/icon-1024.png \
    nightingale/iosApp/Nightingale/Assets.xcassets/AppIcon.appiconset/icon-1024.png
then
    echo "error: Hummingbird and Nightingale iOS icons must remain distinct." >&2
    exit 1
fi

if cmp -s \
    hummingbird/iosApp/Hummingbird/Assets.xcassets/BrandMark.imageset/icon-1024.png \
    nightingale/iosApp/Nightingale/Assets.xcassets/BrandMark.imageset/icon-1024.png
then
    echo "error: Hummingbird and Nightingale in-app brand marks must remain distinct." >&2
    exit 1
fi

for density in mdpi hdpi xhdpi xxhdpi xxxhdpi
do
    for asset in ic_launcher.png ic_launcher_round.png ic_launcher_foreground.png ic_launcher_monochrome.png
    do
        if cmp -s \
            "hummingbird/androidApp/app/src/main/res/mipmap-$density/$asset" \
            "nightingale/androidApp/app/src/main/res/mipmap-$density/$asset"
        then
            echo "error: Hummingbird and Nightingale Android $density/$asset must remain distinct." >&2
            exit 1
        fi
    done
done

echo "Mobile brand asset verification passed."
