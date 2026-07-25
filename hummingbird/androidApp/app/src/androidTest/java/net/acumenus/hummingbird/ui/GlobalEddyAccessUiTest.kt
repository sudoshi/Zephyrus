package net.acumenus.hummingbird.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class GlobalEddyAccessUiTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun globalEntryIsVisibleAndDelegatesOnlyToTheBoundedHouseLens() {
        var launches = 0

        compose.setContent {
            HummingbirdTheme {
                GlobalEddyAccessButton(onOpenEddy = { launches += 1 })
            }
        }

        compose.onNodeWithTag("global-eddy-access").assertIsDisplayed().performClick()
        compose.runOnIdle { assertEquals("house", GLOBAL_EDDY_SCOPE_REF) }
        compose.runOnIdle { assertEquals(1, launches) }
    }
}
