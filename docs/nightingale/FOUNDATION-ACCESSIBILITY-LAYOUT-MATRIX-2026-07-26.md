# Nightingale foundation accessibility and layout matrix

**Status:** implemented and locally verified for the bounded offline foundation; full
product accessibility conformance and independent human approval remain open

**Decision date:** 2026-07-26

**Applies to:** the current Nightingale iOS and Android safe shell, privacy status card,
and device-local Display comfort card

**Companion decision:**
[Presentation-preferences foundation decision](./PRESENTATION-PREFERENCES-FOUNDATION-DECISION-2026-07-26.md)

**Does not authorize:** patient data, identity, networking, clinical content, account
preferences, production access, patient creation, pilot enrollment, deployment, or release

## 1. Purpose

The initial presentation-preferences slice proved reduced-motion and decorative-imagery
behavior, but it deliberately left a compound accessibility item open. This follow-up
tests and hardens the only currently executable patient surface at:

- the largest iOS accessibility text category;
- Android font scale `2.0`;
- portrait and landscape;
- light and dark appearance;
- iOS Increased Contrast;
- ordered accessibility semantics;
- minimum interactive-target size; and
- measurable text-color contrast.

This is a foundation-only matrix. It does not extrapolate from one offline screen to
future Today, My Path, Care Team, education, discharge, messaging, identity, recovery, or
representative journeys.

## 2. Triggering finding

A Release-screen audit on the iPhone 16e Simulator used:

- dark appearance;
- Increased Contrast enabled; and
- `accessibility-extra-extra-extra-large`.

The original iOS forest accent used fixed sRGB components `(0.20, 0.38, 0.29)` in both
appearances. Its contrast was:

| Pairing                 | Ratio   | Text threshold | Result |
| ----------------------- | ------- | -------------- | ------ |
| original forest / white | 7.129:1 | 4.5:1          | pass   |
| original forest / black | 2.946:1 | 4.5:1          | fail   |

The patient-visible “Your privacy comes first” label was therefore too dark on the dark
system background. The defect was visible in the inspected simulator output and was not
closed by merely documenting an intended palette.

The same audit found that:

- iOS content did reflow and remain vertically scrollable at the largest text size;
- the status card remained reachable;
- Android had only a light Material color scheme and therefore did not explicitly honor
  system dark appearance; and
- Android exposed the switch control, but the complete labeled preference row did not yet
  have an explicit, testable 48 dp minimum.

## 3. Bounded acceptance contract

The current foundation is accepted only when all of the following are true:

| Requirement    | iOS acceptance                                                                             | Android acceptance                                                          |
| -------------- | ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------- |
| Text growth    | accessibility XXXL content exists and remains scroll-reachable                             | font scale `2.0` content exists and remains scroll-reachable                |
| Landscape      | both Display comfort controls can be reached and operated                                  | both Display comfort rows can be reached and operated                       |
| Semantic order | product heading → privacy heading → Display comfort heading → reduce motion → hide imagery | same ordered test-tag traversal                                             |
| Target size    | each identified toggle has a frame height of at least 44 points                            | each complete labeled row has a height of at least 48 dp                    |
| Appearance     | system light/dark resolution is explicit                                                   | `isSystemInDarkTheme()` selects an explicit light/dark scheme               |
| Contrast       | patient accent/system-background pair is at least 4.5:1 in light and dark                  | every defined patient text/container pair is at least 4.5:1 in both schemes |
| Images         | decorative imagery stays absent from accessibility meaning and can be hidden               | same                                                                        |
| Privacy        | lifecycle cover remains operative                                                          | lifecycle cover and `FLAG_SECURE` remain operative                          |
| State cleanup  | simulator settings are restored after evidence collection                                  | font scale/orientation/night mode are restored after evidence collection    |

The 4.5:1 ratio is used as the bounded normal-text gate. Passing these palette pairs is not
a claim that every future component, state, overlay, image crop, disabled state, chart, or
clinical status meets WCAG contrast requirements.

## 4. Implementation decisions

### 4.1 iOS appearance-aware accent

`NightingalePalette.forest` now wraps a dynamic `UIColor`:

- light appearance: `(0.20, 0.38, 0.29)`;
- dark appearance: `(0.55, 0.80, 0.66)`.

The accent resolves at runtime from the active `UITraitCollection`. The light value
preserves the existing calm forest identity. The dark value is a lighter mint-green that
retains that identity without becoming low-contrast against a black system surface.

The status label and privacy-cover icon continue to consume this single semantic accent.
No clinical meaning is encoded by the color.

### 4.2 Android light and dark schemes

Android now defines two Nightingale-owned Material 3 schemes:

| Token            | Light     | Dark      |
| ---------------- | --------- | --------- |
| primary          | `#365F49` | `#A8D5B8` |
| onPrimary        | `#FFFFFF` | `#123323` |
| surface          | `#FFFCF5` | `#17130F` |
| onSurface        | `#24201B` | `#F7F0E7` |
| surfaceVariant   | `#F1E9DC` | `#2B2723` |
| onSurfaceVariant | `#514A42` | `#D8CEC3` |

`NightingaleFoundationScreen` defaults `darkTheme` from
`isSystemInDarkTheme()`. A pure `nightingaleColorScheme(darkTheme)` selector makes the
branch independently testable without platform screenshots.

The privacy-status heading uses `primary`; body and supporting copy continue to use the
appropriate surface content tokens. Color remains decorative emphasis, not the sole
status cue.

### 4.3 Full-row interaction targets

iOS applies `frame(minHeight: 44)` and a rectangular content shape to both native toggles.
The native switch remains a switch; the change expands its row target without replacing
platform semantics.

Android replaces separate label-plus-switch interaction with one `Row` that:

- fills the card width;
- has `heightIn(min = 48.dp)`;
- uses `toggleable` with `Role.Switch`;
- leaves the visual `Switch` non-interactive so there is only one state-changing target;
- keeps the patient-readable label as a descendant of the checkable row; and
- wraps the label at large text rather than truncating it.

The final UI Automator hierarchy exposes each full row as one enabled, focusable,
checkable, clickable element with its label beneath that semantic parent.

### 4.4 Reading order

Stable identifiers/test tags now bind the five required landmarks:

1. `nightingale-product-heading`
2. `nightingale-privacy-status-heading`
3. `nightingale-display-comfort-heading`
4. `nightingale-reduce-motion-toggle`
5. `nightingale-hide-imagery-toggle`

On iOS, the privacy label is combined into one accessibility element and the unnecessary
status-card containment boundary was removed. This corrected an observed XCUITest tree in
which the Display comfort card had been traversed before the privacy card even though the
visual source order was the reverse.

On Android, the instrumentation suite recursively traverses the unmerged Compose semantics
tree and compares the ordered Nightingale tags. This avoids using clipped viewport
coordinates as a proxy for semantic order.

### 4.5 Scroll and width behavior

iOS retains a vertical `ScrollView` with a 520-point maximum content width.

Android now:

- aligns the scroll column at `TopCenter`;
- constrains it to a 520 dp maximum width;
- fills the available height;
- uses top rather than center vertical arrangement; and
- retains vertical scrolling for text growth and landscape height reduction.

Top alignment prevents tall large-text content from depending on centering behavior as it
exceeds the viewport.

## 5. Contrast proof

### 5.1 Method

Both native unit suites calculate relative luminance from sRGB components using the WCAG
piecewise linearization formula:

```text
if c <= 0.04045: c_linear = c / 12.92
else:            c_linear = ((c + 0.055) / 1.055) ^ 2.4

L = 0.2126 R_linear + 0.7152 G_linear + 0.0722 B_linear
contrast = (L_lighter + 0.05) / (L_darker + 0.05)
```

iOS resolves the dynamic color against explicit light and dark trait collections before
calculating the ratio. Android tests both complete `ColorScheme` values through the
Compose `luminance()` implementation.

### 5.2 Results

| Platform      | Foreground / background           | Ratio    | Gate | Result |
| ------------- | --------------------------------- | -------- | ---- | ------ |
| iOS light     | forest / system background        | 7.129:1  | 4.5  | pass   |
| iOS dark      | forest / system background        | 11.324:1 | 4.5  | pass   |
| Android light | primary / surfaceVariant          | 6.031:1  | 4.5  | pass   |
| Android light | onSurface / surface               | 15.796:1 | 4.5  | pass   |
| Android light | onSurfaceVariant / surfaceVariant | 7.238:1  | 4.5  | pass   |
| Android light | onPrimary / primary               | 7.267:1  | 4.5  | pass   |
| Android dark  | primary / surfaceVariant          | 9.085:1  | 4.5  | pass   |
| Android dark  | onSurface / surface               | 16.342:1 | 4.5  | pass   |
| Android dark  | onSurfaceVariant / surfaceVariant | 9.551:1  | 4.5  | pass   |
| Android dark  | onPrimary / primary               | 8.451:1  | 4.5  | pass   |

These ratios are deterministic unit-test assertions, not manually sampled screenshot
colors.

## 6. Native test matrix

### 6.1 iOS

The iOS Release contract now declares portrait, landscape-left, and landscape-right in
both `project.yml` and the generated application `Info.plist`. The new XCUITest:

1. requests SwiftUI `DynamicTypeSize.accessibility5` through a compile-time
   `#if DEBUG` launch-environment adapter;
2. rotates the simulator to landscape left;
3. launches with clean Nightingale presentation settings;
4. requires the application viewport width to exceed its height, proving the release
   orientation contract produced an actual landscape layout;
5. requires the scalable product heading to measure at least 60 points high, proving the
   requested accessibility category affected rendered geometry;
6. reads the accessibility-element sequence and compares all five required identifiers;
7. waits for the reduce-motion switch and asserts a frame height of at least 44 points;
8. operates the switch through XCTest's accessibility-aware auto-scroll and verifies its
   enabled state;
9. repeats the existence, size, auto-scroll, and state checks for the imagery switch; and
10. restores portrait orientation in test teardown, including after a framework-level
    interaction failure.

The first attempted test used the UIKit `-UIPreferredContentSizeCategoryName` launch
argument. On the iOS 26.3.1 simulator, the heading remained `40.67` points high, which
proved that the argument did not affect the SwiftUI hierarchy in this environment. That
test correctly failed its 60-point geometry gate. The accepted test uses the
`NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE` process environment key, which is read only
inside a compile-time `#if DEBUG` branch and applies `.dynamicTypeSize(.accessibility5)`
above the complete patient surface. Release compilation excludes the key and override.
The geometry assertion prevents a future no-op override from silently preserving a green
journey.

The accepted full normally signed suite began from restored device settings: light
appearance, normal contrast, and system-large text. This makes the seven-page hierarchy
in the largest-text journey attributable to the Debug-only accessibility5 adapter instead
of leaked simulator state. The prior Release visual inspection was separately performed
with dark appearance, `DarkenSystemColors=1`, and
`UICTContentSizeCategoryAccessibilityExtraExtraExtraLarge`; it remains appearance and
reflow evidence, not landscape evidence.

Results:

| Suite            | Tests | Failures | Skips |
| ---------------- | ----- | -------- | ----- |
| Nightingale unit | 8     | 0        | 0     |
| Nightingale UI   | 4     | 0        | 0     |
| Total            | 12    | 0        | 0     |

The unit total includes the dynamic light/dark contrast test and the existing product,
protected-state, volatile-input, presentation-policy, and preference-boundary tests.

### 6.2 Android

The new API 35 instrumentation journey:

1. sets system font scale to `2.0` through instrumentation shell authority;
2. requests landscape orientation;
3. waits until both configuration changes are active;
4. traverses the unmerged semantics tree and compares the five ordered tags;
5. scrolls each complete preference row into view;
6. converts 48 dp to device pixels using the current density;
7. asserts each row meets or exceeds the threshold;
8. operates each row and verifies its checked state; and
9. restores font scale `1.0` and portrait orientation in `finally`.

The final clean, no-build-cache run produced:

| Suite/gate                   | Result                                |
| ---------------------------- | ------------------------------------- |
| JVM unit tests               | 7 passed                              |
| API 35 instrumentation tests | 7 passed                              |
| product-boundary task        | passed                                |
| `lintDebug`                  | passed                                |
| `lintVitalRelease`           | passed                                |
| Debug assembly               | passed                                |
| unsigned Release assembly    | passed                                |
| Gradle actions               | 125 total; 124 executed; 1 up-to-date |

The unit total includes pure light/dark scheme selection and all eight text-pair contrast
assertions.

## 7. Emulator and visual evidence

### 7.1 iOS Release inspection

The normally signed Release simulator application was installed cleanly on:

- device: iPhone 16e Simulator;
- device ID: `3F568F29-BE58-49AD-8151-6C2303B4C4E3`;
- runtime: iOS 26.3.1;
- appearance: dark;
- contrast: Increased Contrast;
- text: accessibility XXXL.

Visual inspection confirmed:

- text reflows rather than truncating;
- the page scrolls vertically;
- the status card begins after the introductory copy;
- the corrected accent is visibly distinct against the black card;
- card boundaries remain visible;
- decorative imagery is withheld by the stronger contrast policy; and
- no essential content depends on the bird image.

The local Release screenshot SHA-256 is
`f3ca5fb5eb5281424d08858e56fdd381eb06ed5fa47d607dfc1bfc480b08f153`.
The screenshot is local diagnostic evidence, not a distribution screenshot or an
independent patient review.

### 7.2 Android hierarchy inspection

The `hb` Android 15/API 35 AVD was booted without a saved-state dependency. A manual
hierarchy inspection used dark appearance and font scale `2.0`.

At 420 dpi, both final portrait preference rows measured 222 pixels high, approximately
84.6 dp, because their labels wrapped at the enlarged font scale. The instrumentation
journey independently proves each landscape row remains at least 48 dp.

The final UI Automator XML SHA-256 is
`6392d3ef5ebfbcd4ef85b568e94c067b90fb77725ef4197e85f7a82518d13fcf`.

Android continues to apply `FLAG_SECURE`. External screenshots therefore contain system
chrome and a black application surface. The final secure-capture SHA-256 is
`5d5c731e9047f2c26407fab4bfa66b40ae59788da3438089db5f479732b53ebc`.
That black capture is evidence that capture protection remains active; it is not accepted
as dark-theme visual evidence. Human dark-theme review on an approved physical or secure
review device remains open.

## 8. Exact local artifact binding

| Artifact                         | SHA-256                                                            |
| -------------------------------- | ------------------------------------------------------------------ |
| iOS Debug simulator executable   | `92c57ad6fbbe680bdc77d8252c6a144d0b4b90f4a225acadc86159891b34fd1e` |
| iOS Release simulator executable | `182ef77a6a020c4a26212482f09822901f94e3587433bbe490e5cb55be2c4827` |
| iOS Release application manifest | `cc49573008857a7a658978b871553c922bf928577a80a7cece3750e804f6ef0c` |
| Android Debug APK                | `4d866ec381399caabd1287fd204e0dc01d3794267aecd5900a69d87d0dd91164` |
| Android unsigned Release APK     | `bd3d2994c84fa7de97d2770c257b70b8eb48f0fc59a081f486ecf6ebe03dd4e3` |

The iOS manifest is the built Release `Nightingale.app/Info.plist`; it contains portrait,
landscape-left, and landscape-right. These are local simulator/emulator artifacts. They
are not App Store/Play artifacts, distribution signatures, production releases, or pilot
approval.

## 9. Mechanical enforcement

`verify-nightingale-product-boundary.sh` now fails if any of these bounded safeguards is
removed:

- the iOS accent stops resolving by appearance;
- the iOS 44-point row target disappears;
- the iOS light/dark contrast test disappears;
- the iOS largest-text landscape journey disappears;
- either iOS landscape orientation disappears from the project or application manifest;
- the iOS accessibility-size launch adapter escapes its Debug-only branch;
- the Android dark color scheme disappears;
- the Android screen stops following system dark appearance;
- the Android 48 dp row target disappears;
- the Android light/dark contrast test disappears; or
- the Android largest-text landscape journey disappears.

The existing verifier continues to enforce no networking, no Android `INTERNET`
permission, correct application identities, exact presentation namespaces, Debug-only
test reset, and no account/cloud preference store.

## 10. Failure history

Preliminary failures are retained because they changed the implementation:

1. The original iOS fixed accent failed the dark-background text gate at 2.946:1.
2. The first iOS semantic-order test exposed Display comfort before the privacy status
   because the status card created an unnecessary contained accessibility subtree.
3. An unsigned iOS unit-test run produced Keychain status `-34018`; the normally signed
   full run passed the protected-state canary and is the accepted result.
4. The first Android compilation imported the receiver-scoped `weight` member directly;
   removing the invalid import allowed the `RowScope` call to compile.
5. The first Android order assertion compared clipped viewport coordinates. Offscreen
   nodes reported zero-valued clipped bounds, so the assertion was replaced with actual
   semantics-tree traversal.
6. The first attempted semantics helper used an unavailable `getOrNull` API. It was
   replaced with explicit configuration-key membership and indexed retrieval.
7. Android screenshots remained black because `FLAG_SECURE` is functioning. No bypass was
   added for audit convenience.
8. The first iOS largest-text journey used the UIKit preferred-content-size launch
   argument, but the iOS 26.3.1 SwiftUI hierarchy remained at a `40.67`-point product
   heading and correctly failed the 60-point geometry gate. The accepted Debug-only
   environment adapter produced the seven-page accessibility hierarchy and passed the
   complete journey.
9. A post-publication threat-surface inventory found that the test rotated the simulator
   but the Release `Info.plist` declared portrait only. The orientation contract was
   expanded to both landscape directions, and the journey now fails unless the application
   viewport itself is wider than it is tall. Earlier test success is not used as proof of
   distributed landscape support.
10. The first truly landscape run expanded to seven pages and exposed that probing
    `XCUIElement.isHittable` while a control was still fully offscreen can itself raise an
    invalid-activation-point failure. A center-in-viewport attempt admitted a partially
    clipped frame beginning at y = -4 points. A full-frame-in-viewport attempt then exposed
    XCTest's rotated-coordinate mismatch. The accepted proof checks existence and 44-point
    frame height, performs XCTest's accessibility-aware auto-scroll/tap, and requires the
    state change. Those failures also showed that a method-local `defer` did not reset
    orientation after a framework-level interaction failure, so portrait restoration now
    runs from XCTest teardown.

None of these preliminary failures is counted as passing evidence.

## 11. What is complete

The following bounded foundation subset is complete:

- light/dark patient text palette selection;
- automated contrast ratios for every currently declared semantic text pair;
- largest text/font-scale reflow and scroll reachability;
- landscape reachability of both current controls;
- ordered foundation landmarks and controls;
- 44-point iOS and 48 dp Android interaction targets;
- full-row Android switch interaction;
- iOS Increased Contrast Release inspection;
- Android dark configuration plus secure semantic hierarchy inspection; and
- exact local Debug/Release artifact binding.

## 12. What remains open

The following are intentionally not closed:

- WCAG 2.2 AA conformance for the product;
- VoiceOver and TalkBack manual traversal by an independent reviewer;
- focus recovery after errors, modals, navigation, live updates, and authentication;
- keyboard, Switch Control, Voice Control, external-input, and magnification journeys;
- right-to-left layout;
- pseudo-localization and language-expansion testing;
- non-English patient copy and interpreter workflows;
- captions, transcripts, audio, media controls, charts, tables, and clinical status
  semantics because none exists in the foundation;
- disabled, destructive, urgent, warning, stale, correction, and retraction states because
  none is approved for rendering;
- physical-device visual review;
- iOS backup/restore behavior for local presentation choices;
- every future screen and every future color/state pair;
- distribution-signing and store-artifact evidence; and
- named patient-advisor, accessibility, privacy/security, clinical, content, language,
  legal/HIM, nursing, medical-staff, pharmacy, support, and release approvals.

The compound Stream E conformance item therefore remains open.

## 13. Rollback

If this slice must be rolled back:

1. revert the dynamic iOS accent and Android dark scheme together with their tests;
2. revert only the foundation layout/semantic target changes;
3. retain the existing lifecycle privacy cover, `FLAG_SECURE`, no-network boundary,
   presentation preference storage, reduced-motion behavior, and imagery suppression; and
4. do not clear care-account, identity, protected clinical, or patient state.

No such patient state exists in this foundation.

## 14. Safety statement

No production database or source was accessed. No patient, principal, identity link,
grant, session, encounter, projection, preference, message, pathway, clinical release,
feature flag, migration, deployment, or pilot state was created, read, or changed.

The result remains an offline, no-patient-data Nightingale foundation.
