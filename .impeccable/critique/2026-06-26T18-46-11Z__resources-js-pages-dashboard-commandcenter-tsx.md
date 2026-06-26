---
target: the Command Center dashboard
total_score: 30
p0_count: 1
p1_count: 1
timestamp: 2026-06-26T18-46-11Z
slug: resources-js-pages-dashboard-commandcenter-tsx
---
# Critique — Operations Command Center (/dashboard)

Target: resources/js/Pages/Dashboard/CommandCenter.tsx + 13 Components/CommandCenter/*

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | "Updated just now → moments ago" never advances; 45s auto-refresh has no live-region + no in-flight indicator |
| 2 | Match System / Real World | 4 | Surge level, census %, net bed position, Donabedian bands speak fluent ops |
| 3 | User Control & Freedom | 3 | Role is global Zustand, not URL-persisted; shared wall view resets to "command"; can't pause auto-refresh |
| 4 | Consistency & Standards | 3 | STATUS_VAR CSS-vars vs Tailwind healthcare-* status tokens = two near-but-unequal reds/ambers in one tile |
| 5 | Error Prevention | 2 | Hard z.parse with no boundary on this layout; one bad field white-screens |
| 6 | Recognition over Recall | 4 | Definitions, targets, trust, trend all on-tile — low memory load |
| 7 | Flexibility & Efficiency | 3 | No keyboard role-switch/refresh, role not bookmarkable, no Cmd-K hook |
| 8 | Aesthetic & Minimalist | 4 | Genuinely restrained, calm-at-rest, status rationed |
| 9 | Error Recovery | 1 | No empty/zero/stale/refresh-fail UI anywhere; failures are silent |
| 10 | Help & Documentation | 3 | Excellent inline per-metric docs; no status-vocabulary or surge-scale legend |
| Total | | 30/40 | Good — but happy-path-only |

Strong on the perception axis (2/6/8), weak on the failure axis (5/9). Well-composed dashboard that has never been shown a bad day.

## Anti-Patterns Verdict — does this look AI-generated?

No. Unusually disciplined for a dense dashboard.

- LLM assessment: Actively passes the bans. No gradient text. No glassmorphism on the dashboard (Panel = solid surface + faint sheen). No marketing hero-metric card. Four Bands use identical tile-grids but are differentiated by icon/title/summary and a real Donabedian IA (capacity→flow→outcomes→forecast).
- Deterministic scan: detect.mjs exit 2, 1 finding — `side-tab` at KpiTile.tsx:143 (the 3px left status stripe). Agreed FALSE POSITIVE: it's the one DESIGN.md-sanctioned colored side-accent, redundantly encoded (status also drives value color, ▲▼▬ arrow, uppercase label, ⓘ definition, Trust badge). Detector otherwise clears the surface of slop tells.
- Visual overlays: Not available — no browser-automation tool; assessment is source + detector only.

## Overall Impression

The most on-brand decision in the product lives here: the inline Trust/provenance badge on every KPI tile — it operationalizes "defensible at a glance." The biggest opportunity is the mirror image: the surface is built entirely for the good day. When a feed goes stale or the payload fails to parse, it white-screens or keeps showing confident green numbers under "Updated moments ago." Silently-frozen-but-pretty is the most dangerous failure mode for a clinical staffing surface.

## Priority Issues

[P0] No error / empty / stale states — the surface fails unsafely.
- Why: parseCommandCenterData is a hard z.parse (commandCenter.ts:135) with no boundary in DashboardLayout → one bad field white-screens the whole command center. A failed router.reload (CommandCenter.tsx:18) is swallowed silently while the freshness label keeps lying. Empty unitCensus/metrics render blank grids.
- Fix: Wrap the view in the already-existing ErrorBoundary (used by AnalyticsLayout, not here) with a defensible fallback; track router.on('error') for a "Stale — last good data N min ago" banner; render explicit empty states.
- Command: /impeccable harden

[P1] Freshness signal is dishonest and the refresh is unannounced.
- Why: Label only shows "just now" → "moments ago" then stops (CommandCenter.tsx:25-29); never reflects real staleness or a failed refresh; 45s reload has no aria-live.
- Fix: Derive the label from generatedAtIso (real "2m ago", amber past threshold); add aria-live="polite" announcing update/failure; show an in-flight spinner on Refresh.
- Command: /impeccable clarify

[P2] Role switcher doesn't actually re-prioritize — and isn't sticky.
- Why: HeroWall.tsx:16 only swaps the hero region (KPI grid ↔ OKR scoreboard) for executive; the four Bands/heat strip/forecast render identically for command and executive. "Service-line" is non-functional but still selectable. Role is global Zustand with no URL persistence.
- Fix: Have role reorder/threshold-tune the Bands; disabled/aria-disabled the service-line tab until it works; persist role in the URL query.
- Command: /impeccable adapt

[P3] Two divergent status-color sources inside one tile.
- Why: Status fills use STATUS_VAR CSS-vars (coral #E85A6B), but KpiTile detail rows + Trust badge use Tailwind healthcare-critical/warning whose hex differs (#EF4444/#F59E0B).
- Fix: Route detailStatusClass + Trust badge through STATUS_VAR, or align the Tailwind healthcare-* status tokens to the DESIGN.md CSS-var hex.
- Command: /impeccable colorize

[P3] Percent KPI tile is over-instrumented for the glance audience.
- Why: One percent tile carries up to 8 simultaneous encodings — past the ≤4 glanceable budget. Same tile serves the 3-second nurse glance and the CMO review with no simplification.
- Fix: In command/glance mode collapse detail breakdown + sparkline behind drill-down (progressive disclosure); reveal full detail in executive/review mode or on expand.
- Command: /impeccable distill

## Persona Red Flags

- Alex (power user): No keyboard role-switch (role="tab" RoleSwitcher has no roving tabindex), no force-refresh shortcut, role not bookmarkable, 45s reload re-animates every gauge mid-read with no pause.
- Sam (screen reader / low vision): 45s auto-refresh has no live region — content changes silently. ⓘ definition, segment-bar labels, heat-tile occupancy are title=-only (KpiTile.tsx, UnitHeatStrip.tsx:15) — not keyboard/SR reachable. Muted captions (~#94A3B8 at 10–11px, ⓘ at /50 opacity) borderline for AA.
- Charge nurse glancing: Synchronized house-wide 45s animation reads as ambient agitation; "am I in trouble?" buried under 8 encodings; no "what changed" cue, no most-urgent-first ordering within a band.
- CMO in review: Mostly excellent (trust/targets/definitions are the peak) — but a frozen feed shows confident "Updated moments ago" with no staleness flag; "Executive" doesn't re-level the operational bands.

## What's Working

1. Redundant status encoding is real, not performative (KpiTile.tsx:120-153 + StrainIndex role="status").
2. Inline provenance/Trust badge (KpiTile.tsx:132-140) — the most on-brand decision in the product.
3. Disciplined two-system color — operational status via STATUS_VAR; crimson/gold absent from the working surface; gold reserved for :focus-visible.

## Minor Observations

- StrainIndex marks the trend delta aria-hidden, so SR users lose the "▲ from L2" direction.
- MAX_SURGE_LEVEL = 4 is hard-coded while levels come from data — an L5 would silently clamp.
- No surface-level legend for the 4-color status vocabulary or "surge level."
- ForecastCurve hard-codes panelFill = '#1E293B' — correct today, brittle if the surface token changes.
- Recharts forecast tooltip is hover-only — no keyboard equivalent.

## Questions to Consider

1. If a feed goes stale at 2am during a surge, should staleness be the loudest thing on the wall?
2. Is the role switcher adaptive emphasis, or an OKR widget bolted onto one fixed dashboard?
3. Every gauge re-animates 700ms each 45s whether its value changed or not — alive, or ambient agitation the Earned-Urgency principle forbids?
