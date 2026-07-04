#!/bin/bash
# check-ui-canon.sh — Zephyrus UI consistency guardrail.
#
# Fails (exit 1) on unambiguous token-canon violations in resources/js
# (the Design swatch gallery is exempt). Wire into CI or a pre-commit hook.
# The interactive impeccable design hook remains the broad-judgment layer;
# this script is the unambiguous floor that survives worktree/CI commits
# where the interactive hook does not run.
#
# Established by the 2026-06-26 UI consistency remediation.
# Hardened for Zephyrus 2.0 (plan Part II.1#3, P0): oklch + backdrop-blur
# hard-fail, raw-palette ratchet, sanctioned cockpit wall-mode text-[11px].
set -u
cd "$(dirname "$0")/.." || exit 2

fail=0

# 1) Faux-bold: Figtree ships 400/500/600 only — 700/800 synthesize faux-bold.
bold=$(grep -rnE "font-bold|font-extrabold" resources/js --include=*.jsx --include=*.tsx 2>/dev/null | grep -v "/Design/")
if [ -n "$bold" ]; then
  echo "❌ font-bold/font-extrabold found (use font-semibold — Figtree has no 700):"
  echo "$bold"
  fail=1
fi

# 2) Arbitrary font sizes: use the Tailwind scale (text-xs … text-4xl).
#    ONE sanctioned exception (Zephyrus 2.0 wall mode): text-[11px] inside
#    Components/cockpit/ — dense wall-display micro-captions only.
sizes=$(grep -rnE "text-\[[0-9.]+(px|rem)\]" resources/js --include=*.jsx --include=*.tsx 2>/dev/null \
  | grep -v "/Design/" \
  | grep -vE "Components/cockpit/[^:]+:[0-9]+:.*text-\[11px\]")
if [ -n "$sizes" ]; then
  echo "❌ arbitrary text-[Npx]/[Nrem] found (snap to the scale: text-xs/sm/base/lg/xl/2xl):"
  echo "$sizes"
  fail=1
fi

# 3) Arbitrary line-heights (WARNING): snap to the leading-* scale, not
#    leading-[Npx] or the text-size/[Npx] shorthand. Reported but NOT fatal yet —
#    there is a known backlog (concentrated in Transport/Ops/Staffing/Navigation)
#    to clear before this is promoted to a hard failure like rules 1 & 2.
lh=$(grep -rnE "leading-\[[0-9.]+(px|rem)\]|text-(xs|sm|base|lg|[0-9]?xl)/\[[0-9.]+(px|rem)\]" resources/js --include=*.jsx --include=*.tsx 2>/dev/null | grep -v "/Design/")
if [ -n "$lh" ]; then
  count=$(echo "$lh" | wc -l | tr -d ' ')
  echo "⚠️  arbitrary line-heights found — ${count} occurrence(s) [warning, not fatal]."
  echo "    Use leading-none/tight/snug/normal/relaxed; first 10:"
  echo "$lh" | head -10
fi

# 4) OKLCH: the cockpit reference prototype ships oklch() literals — none may
#    enter the codebase. Status color flows through STATUS_VAR / CSS vars only.
oklch=$(grep -rn "oklch(" resources/js --include=*.jsx --include=*.tsx --include=*.ts --include=*.css 2>/dev/null | grep -v "/Design/")
if [ -n "$oklch" ]; then
  echo "❌ oklch() found (reference-prototype token — use healthcare-* / STATUS_VAR):"
  echo "$oklch"
  fail=1
fi

# 5) Glassmorphism: backdrop-blur is banned outside the sanctioned Auth surfaces.
#    Grandfathered legacy files below are pinned to their 2.0 fix phases
#    (P5: analytics/process; the P3 modal entries were fixed in P3) — REMOVE
#    each entry when fixed, never add.
BLUR_GRANDFATHERED=(
  "resources/js/Components/Analytics/PrimetimeUtilization/Views/DayOfWeekView.jsx" # P5
  "resources/js/Components/Process/Intelligence/ResourceAnalysis/ResourceStressAnalysis.jsx" # P5
)
blur=$(grep -rln "backdrop-blur" resources/js --include=*.jsx --include=*.tsx 2>/dev/null \
  | grep -v "/Design/" \
  | grep -v "Components/Auth/" \
  | grep -v "Components/ChangePasswordModal.jsx")
if [ -n "$blur" ]; then
  new_blur=""
  while IFS= read -r f; do
    grandfathered=0
    for g in "${BLUR_GRANDFATHERED[@]}"; do
      [ "$f" = "$g" ] && grandfathered=1 && break
    done
    [ "$grandfathered" -eq 0 ] && new_blur="${new_blur}${f}"$'\n'
  done <<< "$blur"
  if [ -n "$new_blur" ]; then
    echo "❌ backdrop-blur (glassmorphism) in non-sanctioned, non-grandfathered file(s):"
    printf '%s' "$new_blur"
    echo "   Use the solid .modal-backdrop/.modal-surface scrim instead."
    fail=1
  fi
fi

# 6) Raw Tailwind palette RATCHET: bg/text/border-(gray|red|blue|green|amber|
#    indigo|slate)-N outside Design/Auth. The 2026-07-04 baseline is 134
#    (P3 fixed Components/Modal.jsx's bg-gray-500/75 scrim); the count may only
#    go DOWN (2.0 success metric C4). Lower the baseline as violations are
#    fixed; a rising count fails the gate.
RAW_PALETTE_BASELINE=134
raw_count=$(grep -rnE '\b(bg|text|border)-(gray|red|blue|green|amber|indigo|slate)-[0-9]' resources/js --include=*.jsx --include=*.tsx 2>/dev/null \
  | grep -v "/Design/" \
  | grep -v "/Auth/" \
  | wc -l | tr -d ' ')
if [ "$raw_count" -gt "$RAW_PALETTE_BASELINE" ]; then
  echo "❌ raw Tailwind palette count ROSE: ${raw_count} > baseline ${RAW_PALETTE_BASELINE}."
  echo "   New raw bg/text/border-{gray,red,blue,green,amber,indigo,slate}-N introduced;"
  echo "   use healthcare-* tokens with a dark: pair. (Ratchet: fix yours, or lower"
  echo "   the baseline if you cleaned others.)"
  fail=1
elif [ "$raw_count" -lt "$RAW_PALETTE_BASELINE" ]; then
  echo "ℹ️  raw-palette count ${raw_count} < baseline ${RAW_PALETTE_BASELINE} — nice; ratchet the baseline down in scripts/check-ui-canon.sh."
fi

if [ "$fail" -eq 0 ]; then
  echo "✅ UI canon check passed (no faux-bold, no arbitrary sizes, no oklch, no new glassmorphism, raw-palette ≤ ${RAW_PALETTE_BASELINE})."
fi
exit "$fail"
