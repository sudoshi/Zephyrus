package net.acumenus.hummingbird.patient.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientStateVocabularyTest {
    @Test
    fun everyReleasedStateCodeHasExplicitPatientLanguage() {
        assertEquals(
            mapOf(
                "requested" to "Requested",
                "planned" to "Planned",
                "confirmed" to "Confirmed",
                "in_progress" to "Happening now",
                "completed" to "Completed",
                "delayed" to "Delayed",
                "canceled" to "No longer planned",
            ),
            PatientStateVocabulary.labels(PatientStateDomain.SCHEDULE),
        )
        val pathwayLabels = mapOf(
            "planned" to "Planned",
            "current" to "Happening now",
            "completed" to "Completed",
            "delayed" to "Delayed",
            "canceled" to "No longer planned",
        )
        assertEquals(pathwayLabels, PatientStateVocabulary.labels(PatientStateDomain.PATHWAY))
        assertEquals(pathwayLabels, PatientStateVocabulary.labels(PatientStateDomain.MILESTONE))
        assertEquals(pathwayLabels, PatientStateVocabulary.labels(PatientStateDomain.PATHWAY_EVENT))
        assertEquals(
            mapOf(
                "proposed" to "Being considered",
                "planned" to "Planned",
                "in_progress" to "In progress",
                "completed" to "Completed",
                "paused" to "Paused",
                "canceled" to "No longer planned",
            ),
            PatientStateVocabulary.labels(PatientStateDomain.GOAL),
        )
        assertEquals(
            mapOf(
                "confirmed" to "Confirmed",
                "estimated" to "Estimated",
                "unknown" to "Not yet known",
            ),
            PatientStateVocabulary.labels(PatientStateDomain.TIMING_CONFIDENCE),
        )
        assertEquals(
            mapOf(
                "met" to "Met",
                "pending" to "Still needed",
                "at_risk" to "Needs attention",
            ),
            PatientStateVocabulary.labels(PatientStateDomain.DISCHARGE_CRITERION),
        )
        assertEquals(
            mapOf(
                "discussed" to "Discussed",
                "current" to "Being reviewed",
                "planned" to "Planned",
            ),
            PatientStateVocabulary.labels(PatientStateDomain.ROUNDS_TOPIC),
        )
        assertEquals(
            mapOf(
                "test" to "Test",
                "procedure" to "Procedure",
                "transport" to "Transportation",
                "other" to "Care update",
            ),
            PatientStateVocabulary.labels(PatientStateDomain.PATHWAY_EVENT_CATEGORY),
        )
    }

    @Test
    fun unknownStateNeverExposesItsInternalCode() {
        assertEquals(
            "Status being confirmed",
            PatientStateVocabulary.label("internal_triage_hold", PatientStateDomain.PATHWAY),
        )
    }

    @Test
    fun explicitServerVocabularyMismatchWithholdsTheProjection() {
        assertTrue(PatientStateVocabulary.isCompatible(null))
        assertTrue(PatientStateVocabulary.isCompatible(PatientStateVocabulary.VERSION))
        assertFalse(PatientStateVocabulary.isCompatible("patient-state-vocabulary.v2"))
    }
}
