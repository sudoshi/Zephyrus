package net.acumenus.nightingale

import android.view.WindowManager
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
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
}
