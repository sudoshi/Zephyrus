package net.acumenus.hummingbird.patient

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test

class PatientLaunchHooksReleaseTest {
    @Test
    fun releaseIgnoresSyntheticScenarioAndDestinationExtras() {
        val state = PatientLaunchHooks.fromExtras {
            when (it) {
                "HB_PATIENT_SCENARIO" -> "reference-inpatient"
                "HB_PATIENT_DESTINATION" -> "care-team"
                "HB_PATIENT_STATE" -> "empty"
                else -> "ignored"
            }
        }

        assertFalse(state.syntheticReferenceRequested)
        assertEquals(PatientDestination.TODAY, state.initialDestination)
        assertEquals(PatientLaunchPreview.NONE, state.preview)
        assertNull(SyntheticReferencePatientScenario.snapshotOrNull())
        assertNull(SyntheticReferencePatientScenario.messagingOrNull())

        val forcedState = PatientAppViewModel(
            apiEnabled = false,
            launchState = PatientLaunchState(syntheticReferenceRequested = true),
        ).state
        assertFalse(forcedState.session is PatientSessionState.Ready)
    }
}
