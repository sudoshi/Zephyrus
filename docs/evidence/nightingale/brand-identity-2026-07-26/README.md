# Hummingbird and Nightingale Brand Identity Evidence — 2026-07-26

**Evidence class:** Non-PHI engineering visual review. This is not distribution-rights,
App Store, Play Store, accessibility, privacy, clinical, or pilot approval.

## Scope

This record verifies the supplied Hummingbird and Nightingale product marks on:

- iOS 26.3 Simulator launcher in light and dark appearance;
- Android API 35 circular adaptive launcher rendering;
- Android API 35 themed icons in light and dark appearance; and
- Android 12+ system splash surfaces for both applications.

The isolated iOS review device contained only the two reviewed application products.
Android screenshots came from the repository’s `hb` API 35 engineering emulator and
contain no patient data.

## Findings and correction

The first Android round-mask review exposed clipping of the Hummingbird beak at the
original 8% adaptive inset. Engineering increased only the Hummingbird adaptive and
monochrome inset to 20%, rebuilt both Debug and Release, reinstalled the APK, and repeated
the review. The retained evidence shows the corrected full silhouette.

Both products now render distinct marks, readable silhouettes, complete round-mask
subjects, and stable themed-icon contrast. Nightingale’s smaller natural subject and
source negative space remain legible at the 8% inset. The iOS icons remain full-color in
both launcher appearances by design; no unreviewed alternate dark/tinted artwork is
claimed.

The icon generator was also corrected so every `opaque` output is an RGB PNG with no
alpha channel. `scripts/ci/verify-mobile-brand-assets.sh` now fails if an iOS or legacy
Android launcher output regains alpha, if themed icons are not white alpha silhouettes,
if dimensions drift, if source checksums change, or if the two iOS masters become
identical.

## Screenshots

| File                                                                           | Surface                                    | SHA-256                                                            |
| ------------------------------------------------------------------------------ | ------------------------------------------ | ------------------------------------------------------------------ |
| [ios-launcher-light.png](./screenshots/ios-launcher-light.png)                 | iOS launcher, light appearance             | `9d5f80e76e0d283588c490c317a38e9aeeb0cf70da30fce6acee9861c6965035` |
| [ios-launcher-dark.png](./screenshots/ios-launcher-dark.png)                   | iOS launcher, dark appearance              | `fe4b5bf1e006a1f9dfbdbd2f712aa42f39bbde565efb38e74e70d62c22be745d` |
| [android-round-adaptive.png](./screenshots/android-round-adaptive.png)         | Android app drawer, circular adaptive mask | `c5c9be9a1a4f2cad7d54a1cca52fdd28c223f51c2c31819c820871842d90cb3b` |
| [android-themed-light.png](./screenshots/android-themed-light.png)             | Android launcher, themed icons, light      | `078b458ababacdae58caf81c6bfc0ebbc4526b021d2ec4da2835fb72c3c4ffd9` |
| [android-themed-dark.png](./screenshots/android-themed-dark.png)               | Android launcher, themed icons, dark       | `6d4a8c28e7b77510704233707690ead407933a8ff16535758c612531d104aa7c` |
| [android-hummingbird-splash.png](./screenshots/android-hummingbird-splash.png) | Hummingbird Android system splash          | `f2d1dc47b4e24e0550d3690d88203ea33a732f497e1ecd40b55a2cd1880e4132` |
| [android-nightingale-splash.png](./screenshots/android-nightingale-splash.png) | Nightingale Android system splash          | `f974ca75dcaad5ed5c7f8c1d6f1218fcd92776fd92b5d1034e53dbc617f9ce62` |

## Open holds

- Supplied-artwork ownership and distribution rights remain unapproved.
- App Store and Play Store listings, screenshots, metadata, signing, and public support
  endpoints remain uncreated or unverified.
- Notification, widget, store-listing, and installed-upgrade regression review remains a
  separate release task.
- Engineering visual inspection does not substitute for independent accessibility or
  product-design review.
