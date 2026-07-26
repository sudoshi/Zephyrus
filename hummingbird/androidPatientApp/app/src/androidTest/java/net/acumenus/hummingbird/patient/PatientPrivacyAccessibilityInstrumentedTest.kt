package net.acumenus.hummingbird.patient

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import net.acumenus.hummingbird.patient.ui.HummingbirdPatientTheme
import net.acumenus.hummingbird.patient.ui.PatientApp
import net.acumenus.hummingbird.patient.ui.PatientPresentationAccessibilityProvider
import org.junit.Rule
import org.junit.Test

class PatientPrivacyAccessibilityInstrumentedTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun privacyCoverRemovesCareContentFromAccessibilityUntilItLifts() {
        val viewModel = PatientAppViewModel(
            apiEnabled = false,
            launchState = PatientLaunchState(syntheticReferenceRequested = true),
        )
        var privacyCovered by mutableStateOf(false)

        compose.setContent {
            HummingbirdPatientTheme {
                PatientPresentationAccessibilityProvider(preferences = null) {
                    PatientApp(
                        viewModel = viewModel,
                        privacyCovered = privacyCovered,
                    )
                }
            }
        }

        compose.onNodeWithTag("patient-content").assertIsDisplayed()

        compose.runOnUiThread {
            privacyCovered = true
        }

        compose.onNodeWithContentDescription("Hummingbird Patient is hidden for privacy")
            .assertIsDisplayed()
        compose.onAllNodesWithTag("patient-content").assertCountEquals(0)

        compose.runOnUiThread {
            privacyCovered = false
        }

        compose.onNodeWithTag("patient-content").assertIsDisplayed()
    }
}
