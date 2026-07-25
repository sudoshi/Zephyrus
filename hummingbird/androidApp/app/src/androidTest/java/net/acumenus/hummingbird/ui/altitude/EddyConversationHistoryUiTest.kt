package net.acumenus.hummingbird.ui.altitude

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import net.acumenus.hummingbird.data.EddyChatRole
import net.acumenus.hummingbird.data.EddyConversationDetail
import net.acumenus.hummingbird.data.EddyConversationMessage
import net.acumenus.hummingbird.data.EddyConversationSummary
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class EddyConversationHistoryUiTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun historyShowsNoCacheBoundaryAndOpensOnlyTheSelectedServerConversation() {
        val id = "e75d595c-7e67-49f8-b0a2-8189e1c8491d"
        var opened: String? = null

        compose.setContent {
            HummingbirdTheme {
                EddyConversationHistoryContent(
                    history = listOf(
                        EddyConversationSummary(id, "Discharge barriers", "hummingbird", "hummingbird", "2026-07-24T16:00:00Z"),
                    ),
                    loading = false,
                    error = null,
                    onOpenConversation = { opened = it },
                )
            }
        }

        compose.onNodeWithText("Your authorized Eddy history is read from the server and is not retained offline on this device.")
            .assertIsDisplayed()
        compose.onNodeWithText("Discharge barriers").assertIsDisplayed().performClick()
        compose.runOnIdle { assertEquals(id, opened) }
    }

    @Test
    fun detailKeepsDraftActionsReadOnlyAtLargeText() {
        compose.setContent {
            HummingbirdTheme {
                EddyConversationDetailContent(
                    conversation = EddyConversationDetail(
                        id = "e75d595c-7e67-49f8-b0a2-8189e1c8491d",
                        title = "Discharge barriers",
                        surface = "hummingbird",
                        messages = listOf(
                            EddyConversationMessage(
                                role = EddyChatRole.ASSISTANT,
                                content = "Two barriers need review.",
                                provider = "ollama",
                                createdAt = "2026-07-24T16:00:00Z",
                                hasProposedAction = true,
                            ),
                        ),
                    ),
                    loading = false,
                    error = null,
                )
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(hasText("A draft action remains subject to separate human review; this history view cannot approve it."))
        compose.onNodeWithText("A draft action remains subject to separate human review; this history view cannot approve it.")
            .assertIsDisplayed()
    }
}
