package net.acumenus.nightingale

import android.view.WindowManager
import androidx.compose.material3.ColorScheme
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class NightingaleProductBoundaryTest {
    @Test
    fun foundationHasNoLivePatientOrStaffAccess() {
        assertEquals("Nightingale", NightingaleProductBoundary.productName)
        assertFalse(NightingaleProductBoundary.livePatientAccessEnabled)
        assertFalse(NightingaleProductBoundary.staffEndpointsPermitted)
    }

    @Test
    fun secureWindowFlagIsMandatory() {
        assertEquals(
            WindowManager.LayoutParams.FLAG_SECURE,
            NightingalePrivacyPolicy.SECURE_WINDOW_FLAG,
        )
    }

    @Test
    fun presentationPolicyCombinesPatientAndSystemAccessibilityDecisions() {
        val defaultPreferences = NightingalePresentationPreferenceSnapshot(
            reduceMotionRequested = false,
            hideDecorativeImageryRequested = false,
        )
        val patientPreferences = NightingalePresentationPreferenceSnapshot(
            reduceMotionRequested = true,
            hideDecorativeImageryRequested = true,
        )
        val highContrast = nightingaleSceneAccessibilityPolicy(
            preferences = defaultPreferences,
            fontScale = 1f,
            highContrast = true,
            systemReduceMotion = true,
        )
        val largeText = nightingaleSceneAccessibilityPolicy(
            preferences = defaultPreferences,
            fontScale = 1.3f,
            highContrast = false,
            systemReduceMotion = false,
        )
        val standard = nightingaleSceneAccessibilityPolicy(
            preferences = defaultPreferences,
            fontScale = 1f,
            highContrast = false,
            systemReduceMotion = false,
        )
        val patientReduced = nightingaleSceneAccessibilityPolicy(
            preferences = patientPreferences,
            fontScale = 1f,
            highContrast = false,
            systemReduceMotion = false,
        )

        assertEquals(0f, highContrast.imageAlpha)
        assertTrue(highContrast.reduceMotion)
        assertFalse(highContrast.showDecorativeImagery)
        assertEquals(0, highContrast.transitionDurationMillis)

        assertEquals(1f, largeText.imageAlpha)
        assertEquals(listOf(0.72f, 0.88f, 0.97f), largeText.scrimAlphas)
        assertFalse(largeText.reduceMotion)
        assertTrue(largeText.showDecorativeImagery)

        assertEquals(1f, standard.imageAlpha)
        assertEquals(listOf(0.46f, 0.70f, 0.88f), standard.scrimAlphas)
        assertFalse(standard.reduceMotion)
        assertTrue(standard.showDecorativeImagery)
        assertEquals(180, standard.transitionDurationMillis)

        assertEquals(0f, patientReduced.imageAlpha)
        assertEquals(listOf(1f, 1f, 1f), patientReduced.scrimAlphas)
        assertTrue(patientReduced.reduceMotion)
        assertFalse(patientReduced.showDecorativeImagery)
        assertEquals(0, patientReduced.transitionDurationMillis)
    }

    @Test
    fun backgroundCatalogIsExactStableForLocalDayAndWrapsDeterministically() {
        assertEquals(7, NightingaleBackgroundCatalog.resourceIds.size)
        assertEquals(7, NightingaleBackgroundCatalog.resourceIds.toSet().size)
        assertEquals(0, NightingaleBackgroundCatalog.indexForEpochDay(0))
        assertEquals(1, NightingaleBackgroundCatalog.indexForEpochDay(1))
        assertEquals(6, NightingaleBackgroundCatalog.indexForEpochDay(6))
        assertEquals(0, NightingaleBackgroundCatalog.indexForEpochDay(7))
        assertEquals(6, NightingaleBackgroundCatalog.indexForEpochDay(-1))

        for (epochDay in -14L..14L) {
            val index = NightingaleBackgroundCatalog.indexForEpochDay(epochDay)
            assertTrue(index in NightingaleBackgroundCatalog.resourceIds.indices)
            assertEquals(
                NightingaleBackgroundCatalog.resourceIds[index],
                NightingaleBackgroundCatalog.resourceIdForEpochDay(epochDay),
            )
        }
    }

    @Test
    fun presentationPreferenceNamespaceIsNightingaleOnlyAndAccountAgnostic() {
        val identifiers = listOf(
            NightingalePresentationPreferenceNamespace.PREFERENCES_FILE,
            *NightingalePresentationPreferenceNamespace.allKeys.toTypedArray(),
        )
        val combined = identifiers.joinToString("|").lowercase()

        assertTrue(combined.contains("nightingale"))
        assertTrue(
            identifiers.all { it.startsWith("net.acumenus.nightingale.") },
        )
        assertFalse(combined.contains("hummingbird"))
        assertFalse(combined.contains("patient"))
        assertFalse(combined.contains("account"))
        assertFalse(combined.contains("token"))
    }

    @Test
    fun patientTextColorsMeetContrastInLightAndDarkSchemes() {
        assertEquals(NightingaleLightColorScheme, nightingaleColorScheme(darkTheme = false))
        assertEquals(NightingaleDarkColorScheme, nightingaleColorScheme(darkTheme = true))
        assertPatientTextContrast(NightingaleLightColorScheme)
        assertPatientTextContrast(NightingaleDarkColorScheme)
    }

    @Test
    fun protectedStateNamespaceIsNightingaleOnlyAndCredentialAgnostic() {
        val identifiers = listOf(
            NightingaleProtectedStateNamespace.KEYSTORE_ALIAS,
            NightingaleProtectedStateNamespace.PREFERENCES_FILE,
            NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING,
        )
        val combined = identifiers.joinToString("|").lowercase()

        assertTrue(combined.contains("nightingale"))
        assertTrue(
            identifiers.all { it.startsWith("net.acumenus.nightingale.") },
        )
        assertFalse(combined.contains("hummingbird"))
        assertFalse(combined.contains("access_token"))
        assertFalse(combined.contains("refresh_token"))
        assertFalse(combined.contains("device_uuid"))
    }

    @Test
    fun volatileInputClearsAtEverySensitiveBoundary() {
        val state = NightingaleVolatileInputState()

        NightingaleVolatileInputClearReason.entries.forEach { reason ->
            state.replaceDraftForComposition("synthetic draft that must remain volatile")
            assertTrue(state.hasDraft)
            state.clear(reason)
            assertFalse(state.hasDraft)
            assertEquals(reason, state.lastClearReason)
        }
    }

    private fun assertPatientTextContrast(colorScheme: ColorScheme) {
        assertTrue(contrastRatio(colorScheme.primary, colorScheme.surfaceVariant) >= 4.5f)
        assertTrue(contrastRatio(colorScheme.onSurface, colorScheme.surface) >= 4.5f)
        assertTrue(
            contrastRatio(
                colorScheme.onSurfaceVariant,
                colorScheme.surfaceVariant,
            ) >= 4.5f,
        )
        assertTrue(contrastRatio(colorScheme.onPrimary, colorScheme.primary) >= 4.5f)
    }

    private fun contrastRatio(foreground: Color, background: Color): Float {
        val foregroundLuminance = foreground.luminance()
        val backgroundLuminance = background.luminance()
        val lighter = maxOf(foregroundLuminance, backgroundLuminance)
        val darker = minOf(foregroundLuminance, backgroundLuminance)
        return (lighter + 0.05f) / (darker + 0.05f)
    }
}
