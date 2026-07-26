# Hummingbird icon asset provenance

| Field                      | Value                                                               |
| -------------------------- | ------------------------------------------------------------------- |
| Product                    | Hummingbird Staff                                                   |
| Source file                | `source/Hummingbird.png`                                            |
| SHA-256                    | `5ecc70c2a85d9d6471aabb76cbc49b42a976f6b66ba22c84af065a625fe6e8ad`  |
| Source dimensions          | 1254 x 1254 RGBA PNG                                                |
| Supplied for               | The Hummingbird product/app icon by the project owner on 2026-07-26 |
| Derivation tool            | `scripts/brand/render-app-icon.swift`                               |
| Opaque launcher background | `#050B12`                                                           |
| Android adaptive inset     | 20% per edge; corrected after round-mask inspection                 |
| Android monochrome rule    | White alpha silhouette; launcher supplies foreground/background     |
| Engineering visual review  | Complete on iOS 26.3 Simulator and Android API 35 emulator          |
| Distribution-rights review | Pending; no store or pilot distribution is authorized               |

The source artwork is preserved as the canonical input. Generated density variants must
be regenerated from this file rather than hand-edited. The iOS master and legacy Android
launcher PNGs are RGB files without alpha. Android adaptive foregrounds retain source
transparency, while Android 13+ monochrome resources retain only the antialiased alpha
silhouette.

Canonical generated-output fingerprints:

| Output                                | SHA-256                                                            |
| ------------------------------------- | ------------------------------------------------------------------ |
| iOS 1024 px AppIcon master            | `4be69fb470a6d651a493fe3997fcf0e0aff8ec4470053d5f551e22abe528769e` |
| Android xxxhdpi legacy launcher       | `eeffe7ec147dbfa88fc39ef01abf587c50abbcdd8d166bef03b09f34fc3e8307` |
| Android xxxhdpi adaptive foreground   | `8effad3b73da2ad2bb42507f35b47a1502373310a90c700773fd8211448c1234` |
| Android xxxhdpi monochrome foreground | `eca383c3b1c0be28c645c50a81d1814416281b9e10c67e194ac13ee0368f5991` |

Generation recipe:

```bash
scripts/brand/render-app-icon.swift hummingbird/brand/source/Hummingbird.png \
  <opaque-output.png> <pixels> opaque 0 '#050B12'
scripts/brand/render-app-icon.swift hummingbird/brand/source/Hummingbird.png \
  <adaptive-output.png> <pixels> transparent 0.20
scripts/brand/render-app-icon.swift hummingbird/brand/source/Hummingbird.png \
  <monochrome-output.png> <pixels> monochrome 0.20
```

Release approval must confirm the organization holds the necessary distribution rights
for the supplied artwork and that the resulting icons meet the platform requirements
current at the time of submission. Engineering visual review is not that approval.
