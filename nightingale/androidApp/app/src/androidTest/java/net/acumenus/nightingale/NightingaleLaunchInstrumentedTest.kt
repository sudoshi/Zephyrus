package net.acumenus.nightingale

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NightingaleLaunchInstrumentedTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun launchShowsTheSafePatientFoundationMessage() {
        composeRule.onNodeWithTag("nightingale-safe-shell").assertIsDisplayed()
        composeRule.onNodeWithText("Live patient access is not available in this foundation build. Please ask your care team for current information.")
            .assertIsDisplayed()
    }
}
