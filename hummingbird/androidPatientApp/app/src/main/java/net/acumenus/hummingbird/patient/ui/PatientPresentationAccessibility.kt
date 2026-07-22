package net.acumenus.hummingbird.patient.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.remember
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Density
import kotlin.math.max
import net.acumenus.hummingbird.patient.data.PatientPreferences

/**
 * Rendering-only account choices. They never alter clinical data, urgency
 * guidance, routing, or the system accessibility choices already active on a
 * patient's device.
 */
internal data class PatientPresentationAccessibility(
    val effectiveFontScale: Float = 1f,
    val textSizePreference: String = "standard",
    val highContrast: Boolean = false,
    val reducedMotion: Boolean = false,
) {
    val accessibilityTag: String
        get() = "patient-presentation-" +
            if (highContrast) "high-contrast" else "standard-contrast"
}

internal val LocalPatientPresentationAccessibility = staticCompositionLocalOf {
    PatientPresentationAccessibility()
}

internal fun patientPreferredFontScale(textSize: String?): Float = when (textSize) {
    "large" -> 1.15f
    "extra_large" -> 1.3f
    else -> 1f
}

@Composable
internal fun PatientPresentationAccessibilityProvider(
    preferences: PatientPreferences?,
    content: @Composable () -> Unit,
) {
    val systemDensity = LocalDensity.current
    val effectiveFontScale = max(
        systemDensity.fontScale,
        patientPreferredFontScale(preferences?.textSize),
    )
    val presentation = remember(
        effectiveFontScale,
        preferences?.highContrast,
        preferences?.reducedMotion,
    ) {
        PatientPresentationAccessibility(
            effectiveFontScale = effectiveFontScale,
            textSizePreference = preferences?.textSize ?: "standard",
            highContrast = preferences?.highContrast == true,
            reducedMotion = preferences?.reducedMotion == true,
        )
    }

    CompositionLocalProvider(
        LocalDensity provides Density(systemDensity.density, fontScale = effectiveFontScale),
        LocalPatientPresentationAccessibility provides presentation,
        content = content,
    )
}
