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

if [ "$fail" -eq 0 ]; then
  echo "✅ UI canon check passed (no faux-bold, no arbitrary font sizes outside Design)."
fi
exit "$fail"
