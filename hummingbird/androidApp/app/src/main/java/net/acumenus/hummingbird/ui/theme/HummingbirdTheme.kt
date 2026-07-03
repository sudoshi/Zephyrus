package net.acumenus.hummingbird.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// The real MaterialTheme wrapper: maps the Zephyrus `Z` tokens into an M3 ColorScheme,
// a typography scale, and shape tokens so every Material component (NavigationBar,
// buttons, text fields, sheets) inherits the operations-bridge look instead of the
// baseline purple defaults. Dark-only by design for v1 — same as iOS.

private val HummingbirdDarkColors = darkColorScheme(
    primary = Z.primary,
    onPrimary = Color.White,
    secondary = Z.statusInfo,
    onSecondary = Color.White,
    tertiary = Z.gold,
    onTertiary = Color(0xFF1E293B),
    background = Z.bg,
    onBackground = Z.ink,
    surface = Z.surface,
    onSurface = Z.ink,
    surfaceVariant = Z.surface,
    onSurfaceVariant = Z.inkMuted,
    surfaceContainer = Z.surface,
    surfaceContainerLow = Z.bg,
    surfaceContainerLowest = Z.bg,
    surfaceContainerHigh = Z.surface,
    surfaceContainerHighest = Z.surface,
    outline = Z.border,
    outlineVariant = Z.border,
    error = Z.statusCritical,
    onError = Color.White,
    scrim = Color.Black,
)

/**
 * Typography mirrors the iOS hierarchy (display 40 / headline 22 / title 16 / body 14 /
 * label 11 uppercase-tracked), SemiBold-capped — no faux-bold weights.
 */
private val HummingbirdTypography = Typography(
    displaySmall = TextStyle(fontSize = 40.sp, fontWeight = FontWeight.SemiBold, lineHeight = 46.sp),
    headlineMedium = TextStyle(fontSize = 28.sp, fontWeight = FontWeight.SemiBold, lineHeight = 34.sp),
    headlineSmall = TextStyle(fontSize = 22.sp, fontWeight = FontWeight.SemiBold, lineHeight = 28.sp),
    titleLarge = TextStyle(fontSize = 20.sp, fontWeight = FontWeight.SemiBold, lineHeight = 26.sp),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold, lineHeight = 22.sp),
    titleSmall = TextStyle(fontSize = 15.sp, fontWeight = FontWeight.Medium, lineHeight = 20.sp),
    bodyLarge = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.Normal, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Normal, lineHeight = 20.sp),
    bodySmall = TextStyle(fontSize = 13.sp, fontWeight = FontWeight.Normal, lineHeight = 18.sp),
    labelLarge = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium, lineHeight = 20.sp),
    labelMedium = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium, lineHeight = 16.sp),
    labelSmall = TextStyle(fontSize = 11.sp, fontWeight = FontWeight.SemiBold, lineHeight = 16.sp, letterSpacing = 0.5.sp),
)

/** Shape tokens: 10dp controls, 14dp panels (the shared panel radius), 28dp hero cards. */
private val HummingbirdShapes = Shapes(
    extraSmall = RoundedCornerShape(6.dp),
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(20.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

@Composable
fun HummingbirdTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = HummingbirdDarkColors,
        typography = HummingbirdTypography,
        shapes = HummingbirdShapes,
        content = content,
    )
}
