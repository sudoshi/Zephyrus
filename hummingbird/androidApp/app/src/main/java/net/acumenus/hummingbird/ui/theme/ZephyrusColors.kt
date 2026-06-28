package net.acumenus.hummingbird.ui.theme

import androidx.compose.ui.graphics.Color

// Generated token palette — mirrors docs/hummingbird/design-tokens/samples/ZephyrusColor.kt
// (the style-dictionary `composeColor` output). Dark is the default theme.
object Z {
    // Operational surfaces & ink (System A — blue/slate)
    val bg = Color(0xFF0F172A)
    val surface = Color(0xFF1E293B)
    val border = Color(0xFF334155)
    val ink = Color(0xFFF8FAFC)
    val inkMuted = Color(0xFF94A3B8)
    val primary = Color(0xFF3B82F6)

    // Brand/focus ONLY (System B) — never an operational primary
    val gold = Color(0xFFC9A227)

    // Rationed status ramp (always paired with icon + label)
    val statusSuccess = Color(0xFF2DD4BF)
    val statusWarning = Color(0xFFE5A84B)
    val statusCritical = Color(0xFFE85A6B)
    val statusInfo = Color(0xFF60A5FA)
}
