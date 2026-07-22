package net.acumenus.hummingbird.patient.ui

import org.junit.Assert.assertEquals
import org.junit.Test

class PatientPresentationAccessibilityTest {
    @Test
    fun accountTextPreferenceRaisesButNeverDefinesTheSystemFontScale() {
        assertEquals(1f, patientPreferredFontScale(null))
        assertEquals(1f, patientPreferredFontScale("standard"))
        assertEquals(1.15f, patientPreferredFontScale("large"))
        assertEquals(1.3f, patientPreferredFontScale("extra_large"))
        assertEquals(1f, patientPreferredFontScale("unexpected"))
    }
}
