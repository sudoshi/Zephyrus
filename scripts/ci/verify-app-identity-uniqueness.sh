#!/usr/bin/env bash
#
# verify-app-identity-uniqueness.sh — one application identifier, one app.
#
# On 2026-07-27 two independent efforts each declared net.acumenus.nightingale:
# one renamed the existing patient app in place, the other built a new
# foundation tree. Both were correct in isolation, nothing compared them, and
# the merge produced no conflict — git cannot see that two project.yml files
# claim the same App Store record. The collision was found by reading diffs.
#
# The rule enforced here is per-platform uniqueness, not global uniqueness: one
# product legitimately declares the same identifier once for iOS and once for
# Android. What must never happen is the same identifier appearing twice within
# a platform, because that is two applications competing for one store listing.
#
# Usage: verify-app-identity-uniqueness.sh [repository-root]

set -euo pipefail

ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$ROOT"

errors=0

report_duplicates() {
    local platform="$1"
    local listing="$2"

    # listing lines are "<identifier>\t<file>"
    local duplicated
    duplicated="$(cut -f1 <<<"$listing" | sort | uniq -d || true)"
    [ -n "$duplicated" ] || return 0

    while IFS= read -r identifier; do
        [ -n "$identifier" ] || continue
        echo "✗ ${platform}: '${identifier}' is declared by more than one application:" >&2
        grep -F "$identifier"$'\t' <<<"$listing" | cut -f2 | sed 's/^/    /' >&2
        errors=$((errors + 1))
    done <<<"$duplicated"
}

# ── iOS: PRODUCT_BUNDLE_IDENTIFIER in every XcodeGen spec ─────────────────────
ios_listing=""
while IFS= read -r spec; do
    while IFS= read -r identifier; do
        [ -n "$identifier" ] || continue
        ios_listing+="${identifier}"$'\t'"${spec}"$'\n'
    done < <(sed -nE 's/.*PRODUCT_BUNDLE_IDENTIFIER:[[:space:]]*"?([A-Za-z0-9._-]+)"?.*/\1/p' "$spec")
done < <(find . -name project.yml -not -path './node_modules/*' -not -path './vendor/*' -not -path '*/build/*' | sort)

# ── Android: applicationId in every Gradle module ─────────────────────────────
android_listing=""
while IFS= read -r gradle; do
    while IFS= read -r identifier; do
        [ -n "$identifier" ] || continue
        android_listing+="${identifier}"$'\t'"${gradle}"$'\n'
    done < <(sed -nE 's/.*applicationId[[:space:]]*=[[:space:]]*"([A-Za-z0-9._-]+)".*/\1/p' "$gradle")
done < <(find . -name build.gradle.kts -not -path './node_modules/*' -not -path '*/build/*' | sort)

report_duplicates "iOS bundle identifier" "$(printf '%s' "$ios_listing")"
report_duplicates "Android applicationId" "$(printf '%s' "$android_listing")"

ios_count="$(printf '%s' "$ios_listing" | grep -c . || true)"
android_count="$(printf '%s' "$android_listing" | grep -c . || true)"

if [ "$errors" -gt 0 ]; then
    echo "" >&2
    echo "Two applications cannot share one store listing. Either the new target" >&2
    echo "needs its own identifier, or it is a duplicate of an app that already" >&2
    echo "ships and should not exist alongside it." >&2
    exit 1
fi

echo "Application identity verified: ${ios_count} iOS bundle identifier(s) and ${android_count} Android applicationId(s), each declared once per platform."
