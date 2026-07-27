package net.acumenus.nightingale

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import org.junit.Rule
import org.junit.Test

class PatientAuthenticationSmokeTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun signedOutShellExplainsTheSeparatePatientBoundary() {
        composeRule.onNodeWithText("Nightingale").assertIsDisplayed()
        composeRule.onNodeWithText("A separate patient account")
            .performScrollTo()
            .assertIsDisplayed()
        composeRule.onNodeWithText("Use invitation").performScrollTo().assertIsDisplayed()
        composeRule.onNodeWithText("Invitation ID").performScrollTo().assertIsDisplayed()
        composeRule.onNodeWithText("Continue securely").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun exactDemoAliasReachesTheExistingPatientSignInBoundary() {
        composeRule.onNodeWithText("Sign in").performScrollTo().performClick()
        composeRule.onNodeWithTag("patient-login-identifier")
            .performScrollTo()
            .performTextInput("demo1")
        composeRule.onNodeWithTag("patient-login-password")
            .performScrollTo()
            .performTextInput("synthetic-demo-emulator-test-password")
        composeRule.onNodeWithTag("patient-login-submit")
            .performScrollTo()
            .performClick()

        composeRule.onNodeWithText(
            "Patient sign-in is not enabled in this build. Ask your care team for current information.",
        ).performScrollTo().assertIsDisplayed()
    }
}
