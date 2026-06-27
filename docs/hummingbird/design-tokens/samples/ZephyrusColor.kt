// SAMPLE generated output — Jetpack Compose (Android).
// Illustrates what `style-dictionary build` emits from tokens.json for the
// `compose` platform. Do not hand-edit the real file; edit tokens.json instead.
// Dark is the default theme; ZephyrusTheme selects .Dark vs .Light at runtime.

package net.acumenus.hummingbird.designsystem

import androidx.compose.ui.graphics.Color

/** Raw token palette. Semantic theme mapping lives in [ZephyrusTheme]. */
public object ZephyrusColor {
    // System A — operational (blue/slate). Governs working surfaces + interaction.
    public val OperationalPrimaryDark: Color = Color(0xFF3B82F6)
    public val OperationalPrimaryLight: Color = Color(0xFF2563EB)
    public val OperationalPrimaryHoverDark: Color = Color(0xFF2563EB)
    public val OperationalPrimaryHoverLight: Color = Color(0xFF1D4ED8)
    public val OperationalInkDark: Color = Color(0xFFF8FAFC)
    public val OperationalInkLight: Color = Color(0xFF1E293B)
    public val OperationalInkMutedDark: Color = Color(0xFF94A3B8)
    public val OperationalInkMutedLight: Color = Color(0xFF475569)
    public val OperationalSurfaceBaseDark: Color = Color(0xFF0F172A)
    public val OperationalSurfaceBaseLight: Color = Color(0xFFF8FAFC)
    public val OperationalSurfaceRaisedDark: Color = Color(0xFF1E293B)
    public val OperationalSurfaceRaisedLight: Color = Color(0xFFFFFFFF)
    public val OperationalSurfaceHoverDark: Color = Color(0xFF334155)
    public val OperationalSurfaceHoverLight: Color = Color(0xFFF1F5F9)
    public val OperationalBorderDark: Color = Color(0xFF334155)
    public val OperationalBorderLight: Color = Color(0xFFE2E8F0)

    // System B — brand/focus ONLY (the Two-System Rule). Never an operational primary.
    public val BrandCrimsonDark: Color = Color(0xFF9B1B30)
    public val BrandCrimsonLight: Color = Color(0xFFB82D42)
    public val BrandGoldDark: Color = Color(0xFFC9A227) // every focus ring is gold
    public val BrandGoldLight: Color = Color(0xFFA6791A)

    // Rationed status vocabulary. ALWAYS pair with icon/arrow/label. Coral = real breach.
    public val StatusSuccessDark: Color = Color(0xFF2DD4BF)
    public val StatusSuccessLight: Color = Color(0xFF059669)
    public val StatusWarningDark: Color = Color(0xFFE5A84B)
    public val StatusWarningLight: Color = Color(0xFFD97706)
    public val StatusCriticalDark: Color = Color(0xFFE85A6B)
    public val StatusCriticalLight: Color = Color(0xFFDC2626)
    public val StatusInfoDark: Color = Color(0xFF60A5FA)
    public val StatusInfoLight: Color = Color(0xFF0284C7)

    public val OnFill: Color = Color(0xFFFFFFFF) // white only on a solid colored fill
}
