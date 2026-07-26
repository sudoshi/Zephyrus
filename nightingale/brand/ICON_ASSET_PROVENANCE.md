# Nightingale icon asset provenance

| Field                      | Value                                                               |
| -------------------------- | ------------------------------------------------------------------- |
| Product                    | Nightingale                                                         |
| Source file                | `source/Nightingale.png`                                            |
| SHA-256                    | `e97191b7d1eccc32c6a1aa95f0ba2329e1cfb4c1ac1c9b3d2d540872b3327c76`  |
| Source dimensions          | 1254 x 1254 RGBA PNG                                                |
| Supplied for               | The Nightingale product/app icon by the project owner on 2026-07-26 |
| Derivation tool            | `scripts/brand/render-app-icon.swift`                               |
| Opaque launcher background | `#17120E`                                                           |
| Android adaptive inset     | 8% per edge; source negative space remains inside the round mask    |
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
| iOS 1024 px AppIcon master            | `23d72f038c23c5b2863129ef8b13ea25345053df45403da7b376739b8af447af` |
| Android xxxhdpi legacy launcher       | `5abe29f7a435248910ad479040b0d253d0dd5a1a6f564b929f12441de3e806ec` |
| Android xxxhdpi adaptive foreground   | `088d7eda867589ac639ece4f53f770994844e8543479812697505bf0344e75d1` |
| Android xxxhdpi monochrome foreground | `9bb09d319d86883a9d8ff96fc7faa363b4c8865d5684ef82c35aa96bc1806819` |

Generation recipe:

```bash
scripts/brand/render-app-icon.swift nightingale/brand/source/Nightingale.png \
  <opaque-output.png> <pixels> opaque 0 '#17120E'
scripts/brand/render-app-icon.swift nightingale/brand/source/Nightingale.png \
  <adaptive-output.png> <pixels> transparent 0.08
scripts/brand/render-app-icon.swift nightingale/brand/source/Nightingale.png \
  <monochrome-output.png> <pixels> monochrome 0.08
```

Release approval must confirm the organization holds the necessary distribution rights
for the supplied artwork and that the resulting icons meet the platform requirements
current at the time of submission. Engineering visual review is not that approval.
