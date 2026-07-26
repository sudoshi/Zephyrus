package net.acumenus.hummingbird.ui.altitude

import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class EddyChatComposerUiTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun composerShowsHumanDecisionBoundaryAndSendsOnlyAnExplicitNonblankPrompt() {
        val draft = mutableStateOf("")
        var sent: String? = null

        compose.setContent {
            HummingbirdTheme {
                EddyChatComposer(
                    draft = draft.value,
                    sending = false,
                    error = null,
                    onDraftChange = { draft.value = it },
                    onSend = { sent = it },
                )
            }
        }

        compose.onNodeWithText("Use the authorized context; do not add unnecessary patient details. Eddy suggests; people decide.")
            .assertIsDisplayed()
        compose.onNodeWithText("Ask Eddy").assertIsNotEnabled()
        compose.onNode(hasSetTextAction()).performTextInput("  What needs attention this shift?  ")
        compose.onNodeWithText("Ask Eddy").assertIsEnabled().performClick()
        compose.runOnIdle { assertEquals("What needs attention this shift?", sent) }
    }
}
