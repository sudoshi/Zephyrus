package net.acumenus.nightingale

import android.view.WindowManager
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
    fun decorativeImageIsWithheldForHighContrastAndReducedForLargeText() {
        val highContrast = nightingaleSceneAccessibilityPolicy(fontScale = 1f, highContrast = true)
        val largeText = nightingaleSceneAccessibilityPolicy(fontScale = 1.3f)
        val standard = nightingaleSceneAccessibilityPolicy(fontScale = 1f)

        assertEquals(0f, highContrast.imageAlpha)
        assertEquals(0.08f, largeText.imageAlpha)
        assertEquals(0.16f, standard.imageAlpha)
    }

    @Test
    fun protectedStateNamespaceIsNightingaleOnlyAndCredentialAgnostic() {
        val combined = listOf(
            NightingaleProtectedStateNamespace.KEYSTORE_ALIAS,
            NightingaleProtectedStateNamespace.PREFERENCES_FILE,
            NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING,
        ).joinToString("|").lowercase()

        assertTrue(combined.contains("nightingale"))
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
}
