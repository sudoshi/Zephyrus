package net.acumenus.hummingbird.ui.altitude

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import net.acumenus.hummingbird.data.EddyChatRole
import net.acumenus.hummingbird.data.EddyApprovalParameter
import net.acumenus.hummingbird.data.EddyApprovalPreview
import net.acumenus.hummingbird.data.EddyApprovalSummary
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

    @Test
    fun approvalsRequireOpeningTheSelectedLivePreviewBeforeAnyDecision() {
        val id = "f2de3b42-5f41-4a34-9a91-c6292465bba1"
        var opened: String? = null

        compose.setContent {
            HummingbirdTheme {
                EddyApprovalsContent(
                    approvals = listOf(
                        EddyApprovalSummary(
                            approvalUuid = id,
                            actionUuid = "dca4d2b5-0dca-49d6-a2e4-431aaf1bcb91",
                            actionType = "flag_barrier",
                            title = "Flag a discharge barrier",
                            surface = "rtdc",
                            tier = "T1",
                            risk = "medium",
                            requestedAt = "2026-07-24T16:00:00Z",
                        ),
                    ),
                    loading = false,
                    error = null,
                    onOpenApproval = { opened = it },
                )
            }
        }

        compose.onNodeWithText("actions are never queued offline.", substring = true).assertIsDisplayed()
        compose.onNodeWithText("Open live preview before deciding").assertIsDisplayed()
        compose.onNodeWithText("Flag a discharge barrier").performClick()
        compose.runOnIdle { assertEquals(id, opened) }
    }

    @Test
    fun approvalPreviewKeepsTheHumanAndOnlineDecisionBoundaryVisible() {
        compose.setContent {
            HummingbirdTheme {
                EddyApprovalDetailContent(
                    preview = EddyApprovalPreview(
                        summary = EddyApprovalSummary(
                            approvalUuid = "f2de3b42-5f41-4a34-9a91-c6292465bba1",
                            actionUuid = null,
                            actionType = "flag_barrier",
                            title = "Flag a discharge barrier",
                            surface = "rtdc",
                            tier = "T1",
                            risk = "medium",
                            requestedAt = null,
                        ),
                        rationale = null,
                        runnerUp = null,
                        preview = "Would flag a throughput/discharge barrier.",
                        params = emptyList(),
                    ),
                    outcome = null,
                    loading = false,
                    error = null,
                )
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(hasTestTag("eddy-approval-live-decision-boundary"))
        compose.onNodeWithText("Live server preview · not retained offline").assertIsDisplayed()
        compose.onNodeWithTag("eddy-approval-live-decision-boundary").assertIsDisplayed()
    }
}
