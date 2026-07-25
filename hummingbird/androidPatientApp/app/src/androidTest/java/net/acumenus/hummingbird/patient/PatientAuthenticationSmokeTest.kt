package net.acumenus.hummingbird.patient

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import org.junit.Rule
import org.junit.Test

class PatientAuthenticationSmokeTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun signedOutShellExplainsTheSeparatePatientBoundary() {
        composeRule.onNodeWithText("Hummingbird Patient").assertIsDisplayed()
        composeRule.onNodeWithText("A separate patient account")
            .performScrollTo()
            .assertIsDisplayed()
        composeRule.onNodeWithText("Use invitation").performScrollTo().assertIsDisplayed()
        composeRule.onNodeWithText("Invitation ID").performScrollTo().assertIsDisplayed()
        composeRule.onNodeWithText("Continue securely").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun invitationSubmitRequiresACompletePlausibleInvitationBeforeItCanBeUsed() {
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
    }
}
