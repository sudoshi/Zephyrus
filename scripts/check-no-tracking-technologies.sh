#!/bin/bash
# check-no-tracking-technologies.sh — Hummingbird privacy guardrail (plan §10.1).
#
# Fails (exit 1) if a known advertising or analytics tracking SDK signature
# appears in an authenticated or patient-facing surface: the staff web app and
# its login/enrollment pages (resources/js, resources/views), and the patient
# native apps (hummingbird/{ios,android}PatientApp) plus their dependency
# manifests. Authenticated health-app and login/enrollment telemetry requires a
# HIPAA vendor/disclosure analysis (HHS online-tracking guidance); this script
# is the unambiguous floor that keeps ad/tracking pixels out until then.
set -u
cd "$(dirname "$0")/.." || exit 2

# High-confidence advertising/tracking SDK signatures. Patterns are specific
# enough to avoid English-word false positives (e.g. wave "amplitude",
# React "onDoubleClick", the "Analytics" feature-page names).
PATTERN='google-analytics\.com|googletagmanager|googlesyndication|doubleclick\.net|\bgtag\s*\(|\bfbq\s*\(|connect\.facebook\.net|cdn\.segment\.com|api\.segment\.io|mixpanel|amplitude\.com|@amplitude|com\.amplitude|hotjar|fullstory|heapanalytics|heap\.io|appsflyer|branch\.io|com\.google\.firebase:firebase-analytics|FirebaseAnalytics|firebase/analytics|react-ga|react-gtm'

ROOTS=(
  resources/js
  resources/views
  hummingbird/androidPatientApp/app/src
  hummingbird/iosPatientApp/HummingbirdPatient
  package.json
  hummingbird/androidPatientApp
  hummingbird/iosPatientApp/project.yml
)

fail=0
hits=$(grep -rnEi "$PATTERN" "${ROOTS[@]}" \
  --include=*.js --include=*.jsx --include=*.ts --include=*.tsx \
  --include=*.blade.php --include=*.kt --include=*.kts --include=*.swift \
  --include=*.json --include=*.yml --include=*.yaml \
  2>/dev/null)

if [ -n "$hits" ]; then
  echo "❌ Advertising/analytics tracking signature found in an authenticated/patient surface:"
  echo "$hits"
  echo ""
  echo "Authenticated and patient-facing telemetry must be privacy-preserving, first-party,"
  echo "and free of PHM/message text (plan §10.1). Remove the tracker or route it through an"
  echo "approved, governed telemetry path before adding it here."
  fail=1
fi

if [ "$fail" -eq 0 ]; then
  echo "✅ No advertising/tracking SDK signatures in authenticated or patient surfaces."
fi
exit "$fail"
