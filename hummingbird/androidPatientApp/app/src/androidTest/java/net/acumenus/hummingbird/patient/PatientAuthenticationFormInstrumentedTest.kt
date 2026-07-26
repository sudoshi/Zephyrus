package net.acumenus.hummingbird.patient

import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import net.acumenus.hummingbird.patient.ui.HummingbirdPatientTheme
import net.acumenus.hummingbird.patient.ui.PatientAuthenticationScreen
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class PatientAuthenticationFormInstrumentedTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun configuredBuildEnablesOnlyACompleteInvitationAndSubmitsTheExactForm() {
        var submitted: PatientEnrollmentForm? = null
        composeRule.setContent {
            HummingbirdPatientTheme(darkTheme = false) {
                PatientAuthenticationScreen(
                    state = PatientSessionState.SignedOut(),
                    networkEnabled = true,
                    onAuthModeSelected = {},
                    onSignIn = { _, _ -> },
                    onEnroll = { submitted = it },
                )
            }
        }

        composeRule.onNodeWithTag("patient-enrollment-submit")
            .performScrollTo()
            .assertIsNotEnabled()
        composeRule.onNodeWithTag("patient-enrollment-challenge-uuid")
            .performScrollTo()
            .performTextInput("019f0000-0000-7000-8000-000000000051")
        composeRule.onNodeWithTag("patient-enrollment-challenge-token")
            .performScrollTo()
            .performTextInput("0123456789abcdef0123456789abcdef")
        composeRule.onNodeWithTag("patient-enrollment-verification-code")
            .performScrollTo()
            .performTextInput("438201")
        composeRule.onNodeWithTag("patient-enrollment-display-name")
            .performScrollTo()
            .performTextInput("Sample Patient")
        composeRule.onNodeWithTag("patient-enrollment-email")
            .performScrollTo()
            .performTextInput("sample@example.test")
        composeRule.onNodeWithTag("patient-enrollment-password")
            .performScrollTo()
            .performTextInput("patient-password")
        composeRule.onNodeWithTag("patient-enrollment-password-confirmation")
            .performScrollTo()
            .performTextInput("patient-password")

        composeRule.onNodeWithTag("patient-enrollment-submit")
            .performScrollTo()
            .assertIsEnabled()
            .performClick()
        composeRule.runOnIdle {
            assertEquals("019f0000-0000-7000-8000-000000000051", submitted?.challengeUuid)
            assertEquals("Sample Patient", submitted?.displayName)
            assertEquals("sample@example.test", submitted?.email)
        }
    }
}
