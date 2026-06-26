#!/bin/bash
# check-ui-canon.sh — Zephyrus UI consistency guardrail.
#
# Fails (exit 1) if the two UNAMBIGUOUS token-canon violations reappear in
# resources/js (the Design swatch gallery is exempt). Wire into CI or a
# pre-commit hook. The broader color/surface canon (no raw bg-gray/red/blue,
# no bg-white surfaces, no glassmorphism) is enforced interactively by the
# impeccable design hook on every edit and documented in CLAUDE.md.
#
# Established by the 2026-06-26 UI consistency remediation.
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
sizes=$(grep -rnE "text-\[[0-9.]+(px|rem)\]" resources/js --include=*.jsx --include=*.tsx 2>/dev/null | grep -v "/Design/")
if [ -n "$sizes" ]; then
  echo "❌ arbitrary text-[Npx]/[Nrem] found (snap to the scale: text-xs/sm/base/lg/xl/2xl):"
  echo "$sizes"
  fail=1
fi

if [ "$fail" -eq 0 ]; then
  echo "✅ UI canon check passed (no faux-bold, no arbitrary font sizes outside Design)."
fi
exit "$fail"
