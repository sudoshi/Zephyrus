package net.acumenus.hummingbird.patient

import net.acumenus.hummingbird.patient.data.PatientPreferencesUpdate
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SyntheticReferencePatientScenarioTest {
    @Test
    fun debugSyntheticPreferencesRemainAccountOnlyAndDoNotRequireNetwork() {
        val viewModel = PatientAppViewModel(
            apiEnabled = false,
            launchState = PatientLaunchState(syntheticReferenceRequested = true),
        )

        viewModel.openPreferences()
        assertTrue(viewModel.state.preferences is PatientPreferencesState.Ready)
        viewModel.savePreferences(
            PatientPreferencesUpdate(
                textSize = "extra_large",
                reducedMotion = true,
                highContrast = true,
                notificationPreview = "generic",
                preferredChannel = "email",
            ),
        )

        val ready = viewModel.state.preferences as PatientPreferencesState.Ready
        assertEquals("extra_large", ready.preferences.textSize)
        assertEquals("email", ready.preferences.preferredChannel)
        assertTrue(ready.message?.contains("No patient account was changed") == true)

        viewModel.dismissPreferences()
        viewModel.openPreferences()
        val reopened = viewModel.state.preferences as PatientPreferencesState.Ready
        assertEquals("extra_large", reopened.preferences.textSize)
        assertEquals("email", reopened.preferences.preferredChannel)
    }

    @Test
    fun debugSyntheticScenarioStartsOnRequestedDestination() {
        val viewModel = PatientAppViewModel(
            apiEnabled = false,
            launchState = PatientLaunchState(
                syntheticReferenceRequested = true,
                initialDestination = PatientDestination.PATH,
            ),
        )

        val ready = viewModel.state.session as PatientSessionState.Ready
        assertTrue(ready.synthetic)
        assertEquals(PatientDestination.PATH, viewModel.state.destination)
        assertEquals("Sample inpatient", ready.snapshot.patientDisplayName)
        assertTrue(ready.snapshot.uncertaintyNotice.contains("estimates"))
        assertTrue(ready.snapshot.todayItems.all { it.provenance.startsWith("Source:") })
        assertEquals(4, ready.snapshot.contexts.size)
        assertTrue(ready.snapshot.contexts.values.none { it.stale })
        assertTrue(ready.snapshot.contexts.values.all { it.sourceLabel.startsWith("Source:") })
        assertEquals(
            "Your care team updated this information. Please use the details shown here.",
            ready.snapshot.contexts[PatientDestination.PATH]?.revisionNotice,
        )
        val messaging = viewModel.state.messaging as PatientMessagingState.Ready
        assertTrue(messaging.immediateHelp.text.contains("call button"))
        assertTrue(messaging.topics.any { it.code == "care_question" })
        assertTrue(messaging.topics.any { it.code == "rounds_question" && it.description.contains("does not promise") })
        val roundsThread = messaging.threads.first { it.topic.code == "rounds_question" }
        assertEquals(3, roundsThread.messages.size)
        assertEquals(2, roundsThread.messages.count { it.messageKind == "system_status" })
        assertTrue(
            roundsThread.messages.first { it.messageKind == "system_status" }
                .body
                ?.contains("may not be discussed") == true,
        )
        assertTrue(
            roundsThread.messages.last { it.messageKind == "system_status" }
                .body
                ?.contains("completed their review") == true,
        )
    }

    @Test
    fun debugSyntheticNavigationAndExitAreStateBounded() {
        val viewModel = PatientAppViewModel(
            apiEnabled = false,
            launchState = PatientLaunchState(syntheticReferenceRequested = true),
        )
        viewModel.selectDestination(PatientDestination.MESSAGES)
        assertEquals(PatientDestination.MESSAGES, viewModel.state.destination)

        viewModel.signOut()
        assertTrue(viewModel.state.session is PatientSessionState.SignedOut)
        assertFalse(viewModel.state.session is PatientSessionState.Ready)
    }
}
