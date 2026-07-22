package net.acumenus.hummingbird.patient

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollTo
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
}
