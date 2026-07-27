# Nightingale foundation accessibility and language-readiness evidence

**Date:** 2026-07-27

**Product:** Nightingale

**Scope:** bounded offline native shell only

**Evidence class:** non-PHI engineering evidence; not accessibility conformance, translation
approval, clinical approval, distribution approval, or live-use authorization

## 1. Decision

The current Nightingale offline shell now has a mechanically governed source-language
contract and native language-readiness exercises on iOS and Android. The completed subset
is deliberately narrow:

- 15 exact, nonclinical English strings are identical across both native products;
- every visible foundation string is loaded through the native localization resource
  system;
- the current shell exposes three ordered headings;
- two preference status changes use restrained screen-reader notification behavior;
- iOS exercises rendered text doubling and a Debug-only forced right-to-left layout;
- Android exercises the platform `en-XA` text-expansion and `ar-XB` bidirectional
  pseudolocales;
- every current control remains reachable and operable during the exercised states; and
- Release artifacts contain the governed English source only and exclude Debug
  pseudolocales or layout test hooks.

This does **not** approve a non-English locale. It does not establish WCAG 2.2 AA
conformance, VoiceOver or TalkBack human usability, translation quality, interpreter
workflow, patient comprehension, or accessibility of future patient-data screens.

## 2. Triggering audit

The previous accessibility/layout matrix closed maximum text, contrast, semantic order,
landscape, and target-size behavior for the offline shell, but retained these gaps:

1. the privacy title on Android had no heading semantics;
2. changing display-comfort status had no explicit restrained announcement policy;
3. most visible shell copy was hardcoded in Swift and Kotlin;
4. iOS and Android had no exact cross-platform copy reconciliation;
5. no native journey exercised rendered text expansion;
6. no native journey exercised right-to-left layout or bidirectional markers; and
7. Release verifiers did not reject accidentally shipped pseudolocales.

The live Today route, clinical projection, identity, and communication work remains held.
Closing language readiness on the existing nonclinical shell is independent of those
unapproved capabilities.

## 3. Authoritative platform inputs

The implementation uses platform behavior described by:

- Apple,
  [Preparing your interface for localization](https://developer.apple.com/documentation/xcode/preparing-your-interface-for-localization);
- Apple,
  [Testing your internationalized app](https://developer.apple.com/library/archive/documentation/MacOSX/Conceptual/BPInternational/TestingYourInternationalApp/TestingYourInternationalApp.html);
- Apple,
  [Localization](https://developer.apple.com/localization/);
- Android,
  [Test your app with pseudolocales](https://developer.android.com/guide/topics/resources/pseudolocales);
  and
- Android,
  [Semantics in Compose](https://developer.android.com/develop/ui/compose/accessibility/semantics).

These references inform engineering test design. They are not evidence that Nightingale
has completed a platform accessibility certification or human review.

## 4. Exact governed copy contract

The iOS source language is `en` in `Localizable.xcstrings`. Android carries the same
records in `res/values/strings.xml`. Neither source contains another approved
localization.

| Stable key                          | Exact English source value                                                                                        |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `app_name`                          | Nightingale                                                                                                       |
| `foundation_mission`                | A calm place to understand, prepare, and connect with your care team.                                             |
| `privacy_heading`                   | Your privacy comes first                                                                                          |
| `foundation_unavailable`            | Live patient access is not available in this foundation build. Please ask your care team for current information. |
| `foundation_no_patient_data`        | No patient information is stored or requested by this build.                                                      |
| `display_comfort_heading`           | Display comfort                                                                                                   |
| `display_comfort_scope`             | These settings are stored by Nightingale, not your care account. They never change your care information.         |
| `reduce_motion_label`               | Reduce motion in Nightingale                                                                                      |
| `motion_reduced_status`             | Motion is reduced. Nightingale changes views without decorative movement.                                         |
| `motion_standard_status`            | Gentle transitions are enabled. Nightingale also follows your system Reduce Motion setting.                       |
| `hide_imagery_label`                | Hide decorative imagery                                                                                           |
| `imagery_shown_status`              | A calming Nightingale background is shown softly behind the page.                                                 |
| `imagery_hidden_status`             | Decorative imagery is hidden. Essential text and controls remain available.                                       |
| `privacy_cover_accessibility_label` | Privacy cover. Your care information is hidden while Nightingale is not active.                                   |
| `privacy_cover_message`             | Your care information is covered while the app is not active.                                                     |

`verify-nightingale-foundation-copy.mjs` parses both source formats and compares exact
key/value records. It also rejects:

- a missing or extra key;
- copy drift between platforms;
- an iOS locale other than `en`;
- Android Release pseudolocales;
- a return to hardcoded runtime copy;
- missing heading semantics;
- assertive Android status announcements;
- a missing iOS double-length journey; and
- a missing Android `ar-XB` journey.

The verifier currently has nine negative mutations. It is invoked by the existing
Nightingale product-boundary gate, so CI cannot pass by running only the native compilers.

## 5. Semantic and announcement policy

### 5.1 Headings

The current shell has exactly three ordered headings on each platform:

1. Nightingale;
2. Your privacy comes first; and
3. Display comfort.

iOS applies the header accessibility trait. Android applies Compose heading semantics,
including the privacy heading that was previously plain text.

### 5.2 Status changes

The reduced-motion and decorative-imagery status values change only after a user operates
the corresponding switch.

- Android exposes exactly two `LiveRegionMode.Polite` semantics and prohibits
  `LiveRegionMode.Assertive`.
- iOS posts one localized announcement per operated preference through
  `UIAccessibility`, with `UIAccessibilityPriority.low`. The low priority queues the
  announcement rather than overriding more important speech.

No initial page-load announcement, repeating timer, decorative-image announcement, or
assertive notification was added.

## 6. iOS implementation and test design

### 6.1 Source and Release boundary

- `NightingaleCopy.swift` owns stable localization keys and the low-priority announcement
  adapter.
- `Localizable.xcstrings` owns the exact English source.
- SwiftUI views consume `LocalizedStringKey` values rather than patient-visible literals.
- `#if DEBUG` contains the accessibility text-size and right-to-left layout test adapters.
- the Release bundle must contain exactly `en.lproj`;
- the Release bundle's compiled `Localizable.strings` must parse to the exact 15 values;
  and
- the Release binary scan continues to prohibit test environment hooks.

UIKit is now a declared Apple system-module import for the low-priority accessibility
announcement. The governed dependency inventory was regenerated and records five Apple
system modules and zero third-party iOS packages.

### 6.2 Double-length journey

The UI test launches with `-NSDoubleLocalizedStrings YES`, accessibility text size, and a
landscape viewport. It requires:

- the rendered mission label to be longer than the English source;
- both current switches to exist;
- each switch to retain a minimum 44-point frame; and
- each switch to change state after an accessibility-driven tap.

The retained screenshot visibly doubles all localized strings. The exercise is a
pseudolanguage stress state, not approved patient copy.

### 6.3 Right-to-left journey

An Arabic locale and Apple right-to-left launch arguments alone did not mirror this
English-only SwiftUI bundle. The accepted test therefore also uses a compile-time
Debug-only `layoutDirection` adapter. The test compares the same heading in left-to-right
and right-to-left launches and requires:

- the heading's leading edge to move by more than 40 points;
- all five required semantic landmarks and controls to retain their logical order; and
- the mirrored decorative-imagery switch to change state.

The retained screenshot shows right-aligned English fallback copy. English fallback is
intentional because no Arabic translation is approved. It proves current layout
adaptability, not Arabic usability or translation quality.

## 7. Android implementation and test design

### 7.1 Build-type boundary

Gradle enables pseudolocales only for Debug:

- Debug: `isPseudoLocalesEnabled = true`;
- Release: `isPseudoLocalesEnabled = false`.

The generated Debug APK contains `en-rXA` and `ar-rXB`. The Release verifier rejects those
configurations and the BCP-47 variants. Release contains all 15 English compiled resource
keys.

Android's `ar-XB` resource strings inserted bidirectional markers, but the
instrumentation-owned Compose root did not reliably inherit a mirrored direction.
Nightingale therefore has two build-type implementations of one language-readiness
policy:

- Debug maps only `ar-XB` to `LayoutDirection.Rtl`;
- Release always returns the platform-selected direction and contains no `ar-XB` literal.

The actual tagged content shell is inside the composition-local direction provider. This
keeps the pseudolocale correction out of Release while requiring the Debug journey to
exercise mirrored layout.

### 7.2 `en-XA` exercise

The API 35 instrumentation journey selects the app-specific `en-XA` locale, recreates the
activity, and requires:

- the mission resource to differ from English;
- the mission to expand beyond the English source length;
- the expanded mission to remain displayed; and
- the decorative-imagery switch to remain reachable and off.

The retained hierarchy contains accented, bracketed, expanded copy and filler words. It
also shows reflow into a taller initial viewport without truncating the safety message.

### 7.3 `ar-XB` exercise

The same journey selects `ar-XB`, recreates the activity, and requires:

- the tagged shell's Compose layout direction to be RTL;
- all five semantic landmarks and controls to preserve logical order;
- bidirectional pseudolocale strings to remain rendered;
- the decorative-imagery switch to remain scroll-reachable; and
- the switch to change from off to on.

The retained hierarchy shows the Nightingale heading at bounds
`[470,473][953,589]` in `ar-XB`, compared with `[127,473][953,705]` in the expanded
left-to-right hierarchy. The exact fonts and heights differ because the pseudolocales
intentionally transform text, so the native semantic assertion—not a pixel subtraction—is
the acceptance gate.

Android `FLAG_SECURE` remains enabled. The external screenshot is entirely black. The
hierarchies are retained as the inspectable visual/semantic evidence; capture protection
was not weakened for audit convenience.

## 8. Accepted verification results

| Layer                                     | Accepted result                                                     |
| ----------------------------------------- | ------------------------------------------------------------------- |
| Copy contract and negative self-tests     | 15/15 exact keys; 9/9 negative mutations rejected                   |
| iOS signed Debug unit tests               | 11/11 passed                                                        |
| iOS signed Debug UI tests                 | 6/6 passed; includes double-length and RTL journeys                 |
| iOS unsigned Release build/verifier       | passed; exactly one English localization directory and no test hook |
| Android Debug unit tests                  | 8/8 passed                                                          |
| Android Release unit tests                | 8/8 passed                                                          |
| Android API 35 instrumentation            | 10/10 passed; includes exact semantics and both pseudolocales       |
| Android Debug/Release lint and assembly   | passed                                                              |
| Android unsigned Release APK verifier     | passed; exact English keys and no `en-XA`/`ar-XB` configuration     |
| Product-boundary and Xcode project checks | passed                                                              |

Accepted environments:

- Xcode 26.3, iPhone 16e simulator, iOS 26.3.1;
- Android `hb` AVD, Android 15/API 35; and
- local Debug and unsigned Release artifacts only.

## 9. Exact artifact and retained-evidence binding

| Artifact or evidence                                                                                                                            | SHA-256                                                            |
| ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| iOS Debug simulator executable                                                                                                                  | `7128de99c084d8b3416aadb6c6cd716ea8ed82727e4d8d012ce4c2d5054c5d1c` |
| iOS Release simulator executable                                                                                                                | `3667f3c743ec7b5ddce915beef2f0c96ead628d2d166d563de2f7e6bf3a1b698` |
| iOS Release compiled English strings                                                                                                            | `8baee2535f701a01a0e5f972c3d531f057fa6fe85ea535e10694c9a40a2f947c` |
| Android Debug APK                                                                                                                               | `6511676a6ce092b2a868b1bc6f28027f7a60dd13f27da1e994a74473fdab2b47` |
| Android unsigned Release APK                                                                                                                    | `28b055b8873f8db9cf98a24edacdbf74349fbb6c6fd013175df12bca4e7f673e` |
| [iOS double-length screenshot](../evidence/nightingale/accessibility-language-readiness-2026-07-27/screenshots/ios-debug-double-length.png)     | `05023f1954ec978e478bd79c6a9d5c0a1c0dc7fa8cd2838de7994011d6dafbdd` |
| [iOS RTL screenshot](../evidence/nightingale/accessibility-language-readiness-2026-07-27/screenshots/ios-debug-rtl.png)                         | `e7c0c62341732910930f5030587a2a2f7f7e99158764c15f0e2edd5b91387ba7` |
| [Android secure RTL screenshot](../evidence/nightingale/accessibility-language-readiness-2026-07-27/screenshots/android-debug-ar-xb-secure.png) | `c35bacdb98b522206335afa5b9baffd2e4e3352a40749bb747e469cd403af514` |
| [Android `en-XA` hierarchy](../evidence/nightingale/accessibility-language-readiness-2026-07-27/hierarchies/android-debug-en-xa.xml)            | `3bbf3178a99659de488df1d7184cfb3e552ca418159ff5d8cd0301a2e770794a` |
| [Android `ar-XB` hierarchy](../evidence/nightingale/accessibility-language-readiness-2026-07-27/hierarchies/android-debug-ar-xb.xml)            | `720d2c8d9b31e3f850dbee46def8e1e139f797a30b242e396d58d8bc749f22fc` |

These are local engineering artifacts, not App Store or Play Store binaries, distribution
signatures, physical-device results, or pilot artifacts.

## 10. Corrected preliminary failures

The following failures shaped the accepted implementation and are not counted as passing
evidence:

1. Android instrumentation initially used an unavailable Compose
   `SemanticsConfiguration.getOrNull` helper. It was replaced with
   `getOrElseNullable`, and all variants were recompiled.
2. SwiftUI in the current SDK did not expose the attempted
   `accessibilityLiveRegion` modifier. iOS now uses low-priority localized
   `UIAccessibility` announcements after user operations.
3. Apple language and text-direction launch arguments alone did not mirror the
   English-only SwiftUI bundle. A compile-time Debug-only layout-direction adapter was
   added and the journey now proves changed geometry.
4. The first iOS RTL switch assertion assumed an initial persisted state. The accepted
   assertion records the state and proves it changes, regardless of prior nonclinical
   presentation preference.
5. Android's first locale helper timed out when restoring an empty app-locale selection,
   because system fallback is nonempty. The accepted helper separately checks app locale
   selection and resource locale behavior.
6. Android's `ar-XB` resource markers did not change the Compose tagged shell direction
   under instrumentation. Build-type-specific policy now forces only the Debug
   pseudolocale, while Release remains platform-directed and contains no `ar-XB`.
7. The first Android direction assertion inspected the test framework's outer semantic
   root, which is outside Nightingale's direction provider. The accepted assertion
   inspects `nightingale-safe-shell`, the actual product content.
8. One intermediate Compose edit omitted an outer brace and failed both Debug and Release
   compilation. The source was corrected and both variants plus all tests were rebuilt.

## 11. Remaining gates

The following remain open and cannot be inferred from this evidence:

- human VoiceOver and TalkBack traversal, speech quality, focus recovery, and verbosity;
- Switch Control, Voice Control, keyboard, external input, magnification, and cognitive
  accessibility review;
- a complete WCAG 2.2 AA audit across future screens and states;
- approved Arabic or any other translation;
- translation memory, linguistic QA, plain-language review, reading-level target,
  cultural review, interpreter escalation, and locale fallback policy;
- bidirectional behavior for clinical values, dates, times, units, names, identifiers,
  mixed scripts, charts, tables, messages, notifications, and error states;
- physical-device, supported-OS, backup/restore, upgrade, signing, store, and distribution
  evidence;
- named patient-advisor, accessibility, language/interpreter, clinical, privacy/security,
  legal/HIM, support, and release approvals; and
- every live identity, source, Today projection, messaging, notification, or patient-data
  capability.

The broad Stream E accessibility-conformance and human-validation checklist items remain
unchecked.

## 12. Safety and non-authorization statement

No clinical or patient-data UI was added. No route, provider, network client, source
adapter, database query, patient disclosure, mutation, message, notification, enrollment,
migration, deployment, pilot, or production activation was added or enabled.

Pseudolocales are test transformations. They must never be described to a patient as a
translation. English fallback in an RTL layout is test evidence only and must not be used
as an Arabic release strategy.
