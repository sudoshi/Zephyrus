package net.acumenus.hummingbird.patient.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientVisualAssetPolicyTest {
    @Test
    fun eachPatientMomentUsesAnExplicitStaticHummingbirdScene() {
        assertTrue(PatientScene.entries.all { it.drawable != 0 })
        assertEquals(PatientScene.WELCOME.drawable, PatientScene.TODAY.drawable)
        assertNotEquals(PatientScene.TODAY.drawable, PatientScene.PATHWAY.drawable)
        assertNotEquals(PatientScene.PATHWAY.drawable, PatientScene.CARE_TEAM.drawable)
        assertEquals(PatientScene.CARE_TEAM.drawable, PatientScene.MESSAGES.drawable)
        assertNotEquals(PatientScene.CARE_TEAM.drawable, PatientScene.LOADING_OR_EMPTY.drawable)
    }

    @Test
    fun scenicBackgroundBecomesNearlyOpaqueForLargeText() {
        val defaultPolicy = patientSceneAccessibilityPolicy(
            fontScale = 1f,
        )
        val largeTextPolicy = patientSceneAccessibilityPolicy(
            fontScale = 1.3f,
        )

        assertEquals(0.46f, defaultPolicy.imageAlpha)
        assertEquals(listOf(0.68f, 0.84f, 0.96f), defaultPolicy.scrimAlphas)
        assertEquals(0.16f, largeTextPolicy.imageAlpha)
        assertEquals(listOf(0.88f, 0.94f, 0.99f), largeTextPolicy.scrimAlphas)
    }

    @Test
    fun highContrastRemovesTheDecorativeImageBeforeItCanCompeteWithCareContent() {
        val highContrastPolicy = patientSceneAccessibilityPolicy(
            fontScale = 1f,
            highContrast = true,
        )

        assertEquals(0f, highContrastPolicy.imageAlpha)
        assertEquals(listOf(1f, 1f, 1f), highContrastPolicy.scrimAlphas)
    }
}
