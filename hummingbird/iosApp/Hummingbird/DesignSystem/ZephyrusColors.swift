// Generated token palette — mirrors docs/hummingbird/design-tokens/samples/ZephyrusColors.swift
// (the style-dictionary `iosSwift` output from tokens.json). Do not hand-edit values;
// edit tokens.json and regenerate. Dark is the default theme.

import SwiftUI

/// Raw token palette. Semantic theme mapping lives in `Z` (Theme.swift).
public enum ZephyrusColors {
    // System A — operational (blue/slate). Governs working surfaces + interaction.
    public static let operationalPrimaryDark = Color(red: 0.231, green: 0.510, blue: 0.965)      // #3B82F6
    public static let operationalPrimaryLight = Color(red: 0.149, green: 0.388, blue: 0.922)     // #2563EB
    public static let operationalInkDark = Color(red: 0.973, green: 0.980, blue: 0.988)          // #F8FAFC
    public static let operationalInkLight = Color(red: 0.118, green: 0.161, blue: 0.231)         // #1E293B
    public static let operationalInkMutedDark = Color(red: 0.580, green: 0.639, blue: 0.722)     // #94A3B8
    public static let operationalInkMutedLight = Color(red: 0.278, green: 0.333, blue: 0.412)    // #475569
    public static let operationalSurfaceBaseDark = Color(red: 0.059, green: 0.090, blue: 0.165)  // #0F172A
    public static let operationalSurfaceBaseLight = Color(red: 0.973, green: 0.980, blue: 0.988) // #F8FAFC
    public static let operationalSurfaceRaisedDark = Color(red: 0.118, green: 0.161, blue: 0.231)// #1E293B
    public static let operationalSurfaceRaisedLight = Color(red: 1.0, green: 1.0, blue: 1.0)     // #FFFFFF
    public static let operationalBorderDark = Color(red: 0.200, green: 0.255, blue: 0.333)       // #334155
    public static let operationalBorderLight = Color(red: 0.886, green: 0.910, blue: 0.941)      // #E2E8F0

    // System B — brand/focus ONLY (the Two-System Rule). Never an operational primary.
    public static let brandCrimsonDark = Color(red: 0.608, green: 0.106, blue: 0.188)            // #9B1B30
    public static let brandGoldDark = Color(red: 0.788, green: 0.635, blue: 0.153)               // #C9A227 (focus ring)

    // Rationed status vocabulary. ALWAYS pair with icon/arrow/label. Coral = real breach.
    public static let statusSuccessDark = Color(red: 0.176, green: 0.831, blue: 0.749)           // #2DD4BF
    public static let statusWarningDark = Color(red: 0.898, green: 0.659, blue: 0.294)           // #E5A84B
    public static let statusCriticalDark = Color(red: 0.910, green: 0.353, blue: 0.420)          // #E85A6B
    public static let statusInfoDark = Color(red: 0.376, green: 0.647, blue: 0.980)              // #60A5FA

    public static let onFill = Color.white // white only on a solid colored fill
}
