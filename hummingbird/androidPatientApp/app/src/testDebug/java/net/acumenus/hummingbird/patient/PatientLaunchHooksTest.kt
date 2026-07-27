package net.acumenus.hummingbird.patient

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientLaunchHooksTest {
    @Test
    fun debugAcceptsOnlyTheSyntheticScenarioAndNavigationControls() {
        val extras = mapOf(
            "HB_PATIENT_SCENARIO" to "reference-inpatient",
            "HB_PATIENT_DESTINATION" to "messages",
            "HB_PATIENT_STATE" to "empty",
        )
        val state = PatientLaunchHooks.fromExtras(extras::get)

        assertTrue(state.syntheticReferenceRequested)
        assertEquals(PatientDestination.MESSAGES, state.initialDestination)
        assertEquals(PatientLaunchPreview.EMPTY, state.preview)
    }

    @Test
    fun debugExposesOnlyNamedNonPhiPreviewStates() {
        val supported = mapOf(
            "loading" to PatientLaunchPreview.LOADING,
            "empty" to PatientLaunchPreview.EMPTY,
            "unavailable" to PatientLaunchPreview.UNAVAILABLE,
            "recoverable-error" to PatientLaunchPreview.RECOVERABLE_ERROR,
        )

        supported.forEach { (input, expected) ->
            val state = PatientLaunchHooks.fromExtras { key ->
                if (key == "HB_PATIENT_STATE") input else null
            }
            assertEquals(expected, state.preview)
        }
        val unknown = PatientLaunchHooks.fromExtras { "not-supported" }
        assertEquals(PatientLaunchPreview.NONE, unknown.preview)
    }

    @Test
    fun unknownScenarioDoesNotUnlockSyntheticData() {
        val state = PatientLaunchHooks.fromExtras { key ->
            if (key == "HB_PATIENT_SCENARIO") "unknown" else null
        }
        assertFalse(state.syntheticReferenceRequested)
    }
}
