#!/usr/bin/env bash
#
# testflight.sh — one pathway to TestFlight for both iOS apps.
#
#   ./testflight.sh ship hummingbird        archive → sign → upload
#   ./testflight.sh ship nightingale
#   ./testflight.sh ship all                both, sequentially
#
#   ./testflight.sh doctor                  preflight: credentials, tools, signing
#   ./testflight.sh apps                    app records on the team
#   ./testflight.sh builds hummingbird      recent TestFlight builds
#   ./testflight.sh status hummingbird [n]  processing state (default: last shipped)
#   ./testflight.sh wait   hummingbird [n]  poll until processing finishes
#   ./testflight.sh groups nightingale      beta tester groups
#   ./testflight.sh distribute nightingale <build> [group]
#
# Flags for `ship`:
#   --build <n>     explicit build number (default: UTC timestamp yyyymmddHHMM)
#   --no-upload     stop at the signed .ipa
#   --wait          block until App Store Connect finishes processing
#   --to <group>    after processing, hand the build to a beta tester group
#                   (implies --wait)
#
# Credentials and the app registry live in .appledeploy (gitignored).
# Copy .appledeploy.example if you don't have one yet.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${APPLEDEPLOY_FILE:-$REPO_ROOT/.appledeploy}"
ASC_API="$REPO_ROOT/scripts/appledeploy/asc_api.py"

# ── output ─────────────────────────────────────────────────────────────────────
if [ -t 1 ]; then
  BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
  BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
fi
step() { printf '%s▸ %s%s\n' "$BOLD" "$*" "$RESET"; }
ok()   { printf '%s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '%s!%s %s\n' "$YELLOW" "$RESET" "$*"; }
note() { printf '%s  %s%s\n' "$DIM" "$*" "$RESET"; }
die()  { printf '%s✗%s %s\n' "$RED" "$RESET" "$*" >&2; exit 1; }

usage() { sed -n '3,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

# ── config ─────────────────────────────────────────────────────────────────────
load_config() {
  [ -f "$CONFIG_FILE" ] || die "no $CONFIG_FILE — copy .appledeploy.example to .appledeploy and fill it in"
  # shellcheck disable=SC1090
  set -a; source "$CONFIG_FILE"; set +a
  : "${APPS:?APPS is not set in .appledeploy}"
  ASC_KEY_PATH="${ASC_KEY_PATH/#\~/$HOME}"
}

# Resolve <slug> into APP_* variables. Registry keys are the uppercased slug.
resolve_app() {
  local slug="$1" prefix
  prefix="$(printf '%s' "$slug" | tr '[:lower:]-' '[:upper:]_')"

  local known=" $APPS "
  [[ "$known" == *" $slug "* ]] || die "unknown app '$slug' — .appledeploy defines: $APPS"

  local name_var="${prefix}_NAME" id_var="${prefix}_APP_ID" bundle_var="${prefix}_BUNDLE_ID"
  local dir_var="${prefix}_DIR" proj_var="${prefix}_XCODEPROJ" scheme_var="${prefix}_SCHEME"

  APP_SLUG="$slug"
  APP_NAME="${!name_var:-$slug}"
  APP_ID="${!id_var:-}"
  APP_BUNDLE="${!bundle_var:-}"
  APP_DIR="$REPO_ROOT/${!dir_var:?${dir_var} missing in .appledeploy}"
  APP_PROJ="${!proj_var:?${proj_var} missing in .appledeploy}"
  APP_SCHEME="${!scheme_var:?${scheme_var} missing in .appledeploy}"

  [ -d "$APP_DIR" ] || die "$APP_SLUG: project directory not found: $APP_DIR"
}

# Expand "all" to the full registry; validate everything else.
expand_targets() {
  if [ "$1" = "all" ]; then printf '%s\n' $APPS; else printf '%s\n' "$1"; fi
}

# ── doctor ─────────────────────────────────────────────────────────────────────
cmd_doctor() {
  local failed=0
  step "Credentials"
  if [ -f "$CONFIG_FILE" ]; then ok ".appledeploy found"; else die "no $CONFIG_FILE"; fi
  for var in APPLE_TEAM_ID ASC_KEY_ID ASC_ISSUER_ID ASC_KEY_PATH; do
    if [ -n "${!var:-}" ]; then ok "$var = ${!var}"; else warn "$var is empty"; failed=1; fi
  done
  if [ -f "$ASC_KEY_PATH" ]; then
    ok "API key present at $ASC_KEY_PATH"
  else
    warn "API key MISSING at $ASC_KEY_PATH"
    note "App Store Connect → Users & Access → Integrations → App Store Connect API → generate"
    note "the .p8 downloads exactly once; save it to that path"
    failed=1
  fi

  step "Toolchain"
  for tool in xcodebuild xcrun xcodegen python3 openssl; do
    if command -v "$tool" >/dev/null 2>&1; then ok "$tool"; else warn "$tool not on PATH"; failed=1; fi
  done

  step "Signing identity (team $APPLE_TEAM_ID)"
  if security find-identity -v -p codesigning 2>/dev/null | grep -q .; then
    security find-identity -v -p codesigning 2>/dev/null | grep '"' | sed 's/^/  /'
  else
    warn "no codesigning identities in the keychain"; failed=1
  fi

  step "Apps"
  local slug
  for slug in $APPS; do
    resolve_app "$slug"
    if [ -d "$APP_DIR/$APP_PROJ" ]; then
      ok "$APP_SLUG → $APP_NAME ($APP_BUNDLE, app id ${APP_ID:-?})"
      note "$APP_DIR/$APP_PROJ  scheme=$APP_SCHEME"
    else
      warn "$APP_SLUG: $APP_PROJ missing in $APP_DIR (run xcodegen generate)"; failed=1
    fi
  done

  step "App Store Connect reachability"
  if python3 "$ASC_API" apps >/dev/null 2>&1; then
    ok "API key authenticates"
    python3 "$ASC_API" apps | sed 's/^/  /'
  else
    warn "could not list apps — the key may be revoked or lack App Manager role"; failed=1
  fi

  echo
  if [ "$failed" -eq 0 ]; then ok "ready to ship"; else warn "fix the items above before shipping"; fi
  return "$failed"
}

# ── ship ───────────────────────────────────────────────────────────────────────
ship_one() {
  local slug="$1"
  resolve_app "$slug"

  local archive="$APP_DIR/.build-archive/$APP_SCHEME.xcarchive"
  local export_dir="$APP_DIR/.build-archive/export"

  step "$APP_NAME — build $BUILD_NUMBER ($APP_BUNDLE)"

  # Keep the generated project in step with project.yml. CI diffs these, so a
  # stale .xcodeproj would ship the wrong bundle id and fail the guard later.
  if [ -f "$APP_DIR/project.yml" ] && command -v xcodegen >/dev/null 2>&1; then
    ( cd "$APP_DIR" && xcodegen generate --spec project.yml --quiet )
    note "xcodegen: project regenerated from project.yml"
  fi

  step "Archiving (Release)"
  xcodebuild \
    -project "$APP_DIR/$APP_PROJ" \
    -scheme "$APP_SCHEME" \
    -configuration Release \
    -destination 'generic/platform=iOS' \
    -archivePath "$archive" \
    -allowProvisioningUpdates \
    CURRENT_PROJECT_VERSION="$BUILD_NUMBER" \
    archive

  step "Exporting signed App Store .ipa"
  local export_opts="$APP_DIR/ExportOptions.plist"
  [ -f "$export_opts" ] || die "$APP_SLUG: missing $export_opts"
  rm -rf "$export_dir"
  xcodebuild -exportArchive \
    -archivePath "$archive" \
    -exportPath "$export_dir" \
    -exportOptionsPlist "$export_opts" \
    -allowProvisioningUpdates

  local ipa
  ipa="$(find "$export_dir" -maxdepth 1 -name '*.ipa' | head -1)"
  [ -n "$ipa" ] || die "$APP_SLUG: no .ipa produced — check the export log above"
  ok "exported $ipa"

  if [ "$DO_UPLOAD" -eq 0 ]; then
    warn "--no-upload: stopping at the .ipa"
    return 0
  fi

  step "Uploading to TestFlight"
  # altool resolves the key by id under ~/.appstoreconnect/private_keys.
  mkdir -p "$HOME/.appstoreconnect/private_keys"
  if [ "$ASC_KEY_PATH" != "$HOME/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8" ]; then
    cp -f "$ASC_KEY_PATH" "$HOME/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8"
  fi
  # altool cannot be trusted to signal failure by exit code: when App Store
  # Connect returns 500s it logs a fatal "ERROR: [altool] ..." line, uploads
  # nothing, and still exits 0. Scan its output, then confirm against the API.
  #
  # Prefer --upload-package over --upload-app. --upload-app resolves the bundle
  # id to an Apple ID through App Store Connect's /v1/apps endpoint first; when
  # that endpoint 500s (observed 2026-07-26) every upload dies on a lookup we
  # don't need, because .appledeploy already records the Apple ID.
  # --upload-package takes it directly and skips the lookup entirely.
  local -a upload_cmd
  local short_version app_plist
  app_plist="$(find "$archive/Products/Applications" -maxdepth 2 -name Info.plist 2>/dev/null | head -1)"
  short_version="$([ -n "$app_plist" ] && /usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$app_plist" 2>/dev/null || true)"

  if [ -n "$APP_ID" ] && [ -n "$short_version" ]; then
    note "upload-package (apple-id $APP_ID, version $short_version)"
    upload_cmd=(xcrun altool --upload-package "$ipa" -t ios
      --apple-id "$APP_ID" --bundle-id "$APP_BUNDLE"
      --bundle-version "$BUILD_NUMBER" --bundle-short-version-string "$short_version")
  else
    warn "no APP_ID or short version — falling back to --upload-app (needs ASC's /v1/apps lookup)"
    upload_cmd=(xcrun altool --upload-app -f "$ipa" -t ios)
  fi

  local upload_log rc
  upload_log="$(mktemp -t testflight-upload)"
  set +e
  "${upload_cmd[@]}" --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID" 2>&1 | tee "$upload_log"
  rc=${PIPESTATUS[0]}
  set -e

  if [ "$rc" -ne 0 ] || grep -qE '(^|[[:space:]])ERROR:' "$upload_log"; then
    local reason
    reason="$(grep -E '(^|[[:space:]])ERROR: \[altool' "$upload_log" | tail -1 | sed 's/.*ERROR: //')"
    rm -f "$upload_log"
    warn "altool exit=$rc"
    [ -n "$reason" ] && note "$reason"
    die "$APP_NAME: upload FAILED — nothing reached App Store Connect. Re-run when the error above clears (Apple 500s are transient; check https://developer.apple.com/system-status/)."
  fi
  rm -f "$upload_log"
  ok "altool accepted the upload"

  # Ground truth: the binary is only really shipped once ASC can see it.
  step "Confirming the build reached App Store Connect"
  python3 "$ASC_API" confirm "$APP_ID" "$BUILD_NUMBER" \
    || die "$APP_NAME: build $BUILD_NUMBER never appeared — treat this upload as failed"
  ok "uploaded — $APP_NAME build $BUILD_NUMBER"

  # Record what was shipped so `status`/`wait` can default to it.
  mkdir -p "$REPO_ROOT/.build-testflight"
  printf '%s\n' "$BUILD_NUMBER" > "$REPO_ROOT/.build-testflight/$APP_SLUG.last"

  # A build can only join a tester group once it is VALID, so distributing
  # always implies waiting — including when TESTFLIGHT_GROUP came from config
  # rather than an explicit --to.
  if [ "$DO_WAIT" -eq 1 ] || [ -n "$TO_GROUP" ]; then
    step "Waiting for App Store Connect processing"
    note "typically 5-20 minutes; safe to Ctrl-C — the upload is already accepted"
    python3 "$ASC_API" wait "$APP_ID" "$BUILD_NUMBER"
  fi

  if [ -n "$TO_GROUP" ]; then
    step "Distributing to tester group '$TO_GROUP'"
    python3 "$ASC_API" distribute "$APP_ID" "$BUILD_NUMBER" "$TO_GROUP"
  fi
}

cmd_ship() {
  local target="${1:-}"; shift || true
  [ -n "$target" ] || die "usage: ./testflight.sh ship <hummingbird|nightingale|all> [flags]"

  BUILD_NUMBER=""; DO_UPLOAD=1; DO_WAIT=0; TO_GROUP="${TESTFLIGHT_GROUP:-}"
  while [ $# -gt 0 ]; do
    case "$1" in
      --build)     BUILD_NUMBER="${2:?--build needs a value}"; shift 2 ;;
      --no-upload) DO_UPLOAD=0; shift ;;
      --wait)      DO_WAIT=1; shift ;;
      --to)        TO_GROUP="${2:?--to needs a group name}"; DO_WAIT=1; shift 2 ;;
      *)           die "unknown flag: $1" ;;
    esac
  done

  # App Store Connect rejects a build number it has already seen for the same
  # marketing version, so default to something monotonic.
  BUILD_NUMBER="${BUILD_NUMBER:-$(date -u +%Y%m%d%H%M)}"

  local slug
  for slug in $(expand_targets "$target"); do
    ship_one "$slug"
    echo
  done
  ok "done — builds appear in App Store Connect → TestFlight after processing"
}

# ── read-only commands ─────────────────────────────────────────────────────────
last_build_for() {
  local f="$REPO_ROOT/.build-testflight/$1.last"
  [ -f "$f" ] && cat "$f"
}

cmd_passthrough() {
  local verb="$1" slug="${2:-}"
  [ -n "$slug" ] || die "usage: ./testflight.sh $verb <app>"
  resolve_app "$slug"
  [ -n "$APP_ID" ] || die "$slug: no APP_ID in .appledeploy"

  case "$verb" in
    builds|groups)
      python3 "$ASC_API" "$verb" "$APP_ID"
      ;;
    status|wait)
      local build="${3:-$(last_build_for "$slug")}"
      [ -n "$build" ] || die "no build number given and none recorded — pass one explicitly"
      python3 "$ASC_API" "$verb" "$APP_ID" "$build"
      ;;
    distribute)
      local build="${3:-$(last_build_for "$slug")}"
      local group="${4:-${TESTFLIGHT_GROUP:-}}"
      [ -n "$build" ] || die "usage: ./testflight.sh distribute <app> <build> [group]"
      [ -n "$group" ] || die "no group given and TESTFLIGHT_GROUP is unset in .appledeploy"
      python3 "$ASC_API" distribute "$APP_ID" "$build" "$group"
      ;;
  esac
}

# ── dispatch ───────────────────────────────────────────────────────────────────
main() {
  local cmd="${1:-help}"; shift || true
  case "$cmd" in
    help|-h|--help) usage; return 0 ;;
  esac

  load_config

  case "$cmd" in
    doctor)     cmd_doctor ;;
    ship)       cmd_ship "$@" ;;
    apps)       python3 "$ASC_API" apps ;;
    builds|groups|status|wait|distribute) cmd_passthrough "$cmd" "$@" ;;
    *)          die "unknown command '$cmd' — run ./testflight.sh help" ;;
  esac
}

main "$@"
