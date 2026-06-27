# Hummingbird Design Tokens

**One design system, three platforms, zero drift.** This directory is the *source of truth*
for Hummingbird's visual language. The Zephyrus web app keeps its existing Tailwind setup;
these tokens generate the **Compose (Android)** and **SwiftUI (iOS)** equivalents and are
**diffed against the web** in CI so they cannot silently diverge.

## Files

| File | Purpose |
|------|---------|
| [`tokens.json`](tokens.json) | The **DTCG** token source — colors (operational / brand / status, dark + light), Figtree typography (400/500/600), 4px spacing, radius, elevation, motion, component tokens. The only file you edit. |
| [`style-dictionary.config.js`](style-dictionary.config.js) | Build config: `tokens.json` → Compose + SwiftUI + a CSS verify file. |
| [`samples/ZephyrusColor.kt`](samples/ZephyrusColor.kt) | **Sample** generated Compose output (illustrative). |
| [`samples/ZephyrusColors.swift`](samples/ZephyrusColors.swift) | **Sample** generated SwiftUI output (illustrative). |

## Build

```bash
cd docs/hummingbird/design-tokens
npm i -D style-dictionary       # v4+
npx style-dictionary build --config style-dictionary.config.js
# → build/android/ZephyrusColor.kt, build/ios/ZephyrusColors.swift, build/web/tokens.verify.css
```

In the mobile repo this runs as a Gradle/SwiftPM pre-build step (via a build-logic
convention plugin) so generated token files are never hand-edited.

## The canon these tokens encode (do not regress)

- **The Two-System Rule.** `color.operational.*` (blue/slate) governs every working surface
  and interaction. `color.brand.*` (crimson + gold) is Acumenus heritage + **focus only** —
  never an operational primary. The token namespaces enforce the seam.
- **The rationed status ramp.** `color.status.{success,warning,critical,info}` =
  teal/amber/coral/sky. Coral (`critical`) is the most expensive ink — real breaches only.
  **Status is never color alone** — the platform `StatusChip`/`KpiTile` components always add
  an icon, arrow, or label.
- **Dark-default.** Every color has `.dark` (default) and `.light`. The theme layer
  (`ZephyrusTheme`) picks the set; dark is the seed.
- **Figtree, weights 400/500/600 only.** No 700/800 (not loaded → faux-bold). No serif/mono;
  metrics use `tabular-nums`, expressed per platform (`TextStyle(fontFeatureSettings="tnum")`
  / `.monospacedDigit()`).
- **Quiet-Lift elevation.** Resting calm; lift on interaction. Dark prefers the
  `surface-base → surface-raised` tonal step over a shadow.
- **Gold focus ring** on every interactive element (`component.focusRing`).

## Parity / anti-drift check (CI)

A CI job resolves `tokens.json` and asserts the values match the canonical web sources:

- status + operational colors vs **`tailwind.config.js`** (`healthcare-*`,
  `critical/warning/success/info` dark/light)
- the full palette + type scale vs **`.impeccable/design.json`**

Any mismatch **fails the build**. To change a color/size, edit `tokens.json` *and* the web
config in the same PR — the check guarantees they move together. This is how "the impeccable
design hook" guardrail extends from web to mobile.

## Mapping to platform components

The generated raw palette feeds a thin **semantic theme** per platform that resolves
dark/light and exposes role names (`ZephyrusTheme.colors.surface`, `.statusCritical`,
`.focusRing`, …). The signature components are then built **once per platform** to match the
web's:

| Component | Web signature | Compose | SwiftUI |
|-----------|---------------|---------|---------|
| **Panel** | `rounded-lg`, hairline border, sheen, quiet-lift | `ZephyrusPanel` | `ZephyrusPanel` |
| **KpiTile** | Panel + **3px status stripe** + uppercase label + tabular value + arrow | `KpiTile` | `KpiTile` |
| **StatusChip** | dot + worded label (never color alone) | `StatusChip` | `StatusChip` |
| **PrimaryButton** | interactive blue, 36px, gold focus | `ZephyrusButton` | `ZephyrusButton` |

> These component specs live with each platform's `core-ui` / `DesignSystem` module; the
> tokens here are what keep them identical in value to the bridge.
