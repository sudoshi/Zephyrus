package net.acumenus.hummingbird.patient.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val PatientLightColors = lightColorScheme(
    primary = Color(0xFF006B62),
    onPrimary = Color.White,
    primaryContainer = Color(0xFF9EF2E6),
    onPrimaryContainer = Color(0xFF00201D),
    secondary = Color(0xFF3E6374),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFC1E8FA),
    onSecondaryContainer = Color(0xFF001F29),
    tertiary = Color(0xFF625B71),
    error = Color(0xFFB3261E),
    background = Color(0xFFF7FAFC),
    onBackground = Color(0xFF172126),
    surface = Color(0xFFF7FAFC),
    onSurface = Color(0xFF172126),
    surfaceVariant = Color(0xFFDCE5E8),
    onSurfaceVariant = Color(0xFF3F484B),
    outline = Color(0xFF6F797B),
)

private val PatientDarkColors = darkColorScheme(
    primary = Color(0xFF82D5CA),
    onPrimary = Color(0xFF003731),
    primaryContainer = Color(0xFF005047),
    onPrimaryContainer = Color(0xFF9EF2E6),
    secondary = Color(0xFFA5CCDE),
    onSecondary = Color(0xFF083543),
    secondaryContainer = Color(0xFF244C5B),
    onSecondaryContainer = Color(0xFFC1E8FA),
    background = Color(0xFF0F1417),
    onBackground = Color(0xFFDEE3E6),
    surface = Color(0xFF0F1417),
    onSurface = Color(0xFFDEE3E6),
    surfaceVariant = Color(0xFF3F484B),
    onSurfaceVariant = Color(0xFFBFC8CB),
)

private val PatientHighContrastLightColors = lightColorScheme(
    primary = Color.Black,
    onPrimary = Color.White,
    primaryContainer = Color.White,
    onPrimaryContainer = Color.Black,
    secondary = Color.Black,
    onSecondary = Color.White,
    secondaryContainer = Color.White,
    onSecondaryContainer = Color.Black,
    tertiary = Color.Black,
    onTertiary = Color.White,
    error = Color(0xFFB00020),
    onError = Color.White,
    errorContainer = Color.White,
    onErrorContainer = Color.Black,
    background = Color.White,
    onBackground = Color.Black,
    surface = Color.White,
    onSurface = Color.Black,
    surfaceVariant = Color.White,
    onSurfaceVariant = Color.Black,
    outline = Color.Black,
)

private val PatientHighContrastDarkColors = darkColorScheme(
    primary = Color.White,
    onPrimary = Color.Black,
    primaryContainer = Color.Black,
    onPrimaryContainer = Color.White,
    secondary = Color.White,
    onSecondary = Color.Black,
    secondaryContainer = Color.Black,
    onSecondaryContainer = Color.White,
    tertiary = Color.White,
    onTertiary = Color.Black,
    error = Color(0xFFFFB4AB),
    onError = Color.Black,
    errorContainer = Color.Black,
    onErrorContainer = Color.White,
    background = Color.Black,
    onBackground = Color.White,
    surface = Color.Black,
    onSurface = Color.White,
    surfaceVariant = Color.Black,
    onSurfaceVariant = Color.White,
    outline = Color.White,
)

@Composable
fun HummingbirdPatientTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    highContrast: Boolean = false,
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = when {
            highContrast && darkTheme -> PatientHighContrastDarkColors
            highContrast -> PatientHighContrastLightColors
            darkTheme -> PatientDarkColors
            else -> PatientLightColors
        },
        content = content,
    )
}
