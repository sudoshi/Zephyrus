package net.acumenus.nightingale

import android.view.WindowManager
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.lifecycle.Lifecycle
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
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

    @Test
    fun launchProtectsTheWindowFromCapture() {
        composeRule.activityRule.scenario.onActivity { activity ->
            assertTrue(
                activity.window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE != 0,
            )
        }
    }

    @Test
    fun lifecycleActivatesAndRemovesThePrivacyCover() {
        val scenario = composeRule.activityRule.scenario

        scenario.onActivity { activity -> assertFalse(activity.isPrivacyCoverActive) }
        scenario.moveToState(Lifecycle.State.STARTED)
        scenario.onActivity { activity -> assertTrue(activity.isPrivacyCoverActive) }
        scenario.moveToState(Lifecycle.State.RESUMED)
        scenario.onActivity { activity -> assertFalse(activity.isPrivacyCoverActive) }
    }
}
