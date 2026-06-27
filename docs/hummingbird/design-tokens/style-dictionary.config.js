/**
 * Style Dictionary build — Hummingbird design tokens.
 *
 * One source (tokens.json, DTCG) → three platforms:
 *   - Android: Jetpack Compose Color/Dp/TextStyle objects
 *   - iOS:     SwiftUI Color/CGFloat/Font constants
 *   - Web:     CSS custom properties (used only to DIFF against the existing
 *              tailwind.config.js / .impeccable/design.json in CI — the web app
 *              keeps its current Tailwind setup; this guarantees no drift)
 *
 * Dark is the default theme. We emit BOTH .dark and .light leaf tokens; each
 * platform's theme layer selects the set (default = dark).
 *
 * Run:  npx style-dictionary build --config style-dictionary.config.js
 * (Style Dictionary v4+, ESM.)
 */

export default {
  source: ['tokens.json'],
  platforms: {
    compose: {
      transformGroup: 'compose',
      buildPath: 'build/android/',
      files: [
        {
          destination: 'ZephyrusColor.kt',
          format: 'compose/object',
          filter: { $type: 'color' },
          options: { className: 'ZephyrusColor', packageName: 'net.acumenus.hummingbird.designsystem' },
        },
        {
          destination: 'ZephyrusDimens.kt',
          format: 'compose/object',
          filter: (t) => ['dimension'].includes(t.$type),
          options: { className: 'ZephyrusDimens', packageName: 'net.acumenus.hummingbird.designsystem' },
        },
      ],
    },

    iosSwift: {
      transformGroup: 'ios-swift-separate',
      buildPath: 'build/ios/',
      files: [
        {
          destination: 'ZephyrusColors.swift',
          format: 'ios-swift/class.swift',
          filter: { $type: 'color' },
          options: { className: 'ZephyrusColors', imports: ['SwiftUI'], objectType: 'enum', accessControl: 'public' },
        },
        {
          destination: 'ZephyrusMetrics.swift',
          format: 'ios-swift/class.swift',
          filter: (t) => ['dimension'].includes(t.$type),
          options: { className: 'ZephyrusMetrics', imports: ['CoreGraphics'], objectType: 'enum', accessControl: 'public' },
        },
      ],
    },

    cssVerify: {
      transformGroup: 'css',
      buildPath: 'build/web/',
      files: [
        {
          destination: 'tokens.verify.css',
          format: 'css/variables',
          options: { outputReferences: true },
          // CI step diffs the resolved values here against the canonical
          // healthcare-* / status colors in tailwind.config.js + design.json.
          // A mismatch fails the build → the three platforms cannot drift.
        },
      ],
    },
  },

  // Custom formats for `typography` and `motion.easing` (composite DTCG types)
  // are registered in build-logic and emit Compose TextStyle / SwiftUI Font and
  // cubic-bezier easing curves. Kept out of this config sketch for brevity.
};
