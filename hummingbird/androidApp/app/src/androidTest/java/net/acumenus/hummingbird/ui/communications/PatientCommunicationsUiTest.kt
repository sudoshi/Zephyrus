package net.acumenus.hummingbird.ui.communications

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.unit.Density
import net.acumenus.hummingbird.data.PatientCommunicationMessage
import net.acumenus.hummingbird.data.PatientCommunicationPool
import net.acumenus.hummingbird.data.PatientCommunicationReassignCandidate
import net.acumenus.hummingbird.data.PatientCommunicationRerouteCandidate
import net.acumenus.hummingbird.data.PatientCommunicationRouteActions
import net.acumenus.hummingbird.data.PatientCommunicationRouteCandidates
import net.acumenus.hummingbird.data.PatientCommunicationRouteReason
import net.acumenus.hummingbird.data.PatientCommunicationRouteReasonOptions
import net.acumenus.hummingbird.data.PatientCommunicationRoutingAction
import net.acumenus.hummingbird.data.PatientCommunicationRoutingPolicy
import net.acumenus.hummingbird.data.PatientCommunicationTopic
import net.acumenus.hummingbird.data.PatientCommunicationUnit
import net.acumenus.hummingbird.data.PatientCommunicationWorkItem
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import net.acumenus.hummingbird.ui.theme.Z
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.channels.Channel
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import java.util.concurrent.atomic.AtomicInteger

class PatientCommunicationsUiTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun inboxShowsEmergencyBoundaryEscalationStateAndOpensEligibleWorkItem() {
        var opened: String? = null
        var observedBackground: Color? = null
        val item = sampleItem(isEscalationDue = true)

        compose.setContent {
            HummingbirdTheme {
                val background = MaterialTheme.colorScheme.background
                SideEffect { observedBackground = background }
                PatientCommunicationsInboxContent(
                    items = listOf(item),
                    loading = false,
                    error = null,
                    unavailable = false,
                    onRefresh = {},
                    onOpenThread = { opened = it },
                )
            }
        }

        compose.onNodeWithText("Not an emergency channel").assertIsDisplayed()
        compose.onNodeWithText("Medication question").assertIsDisplayed().performClick()
        compose.onNodeWithText("Escalation due now").assertIsDisplayed()
        compose.runOnIdle {
            assertEquals(item.workItemUuid, opened)
            assertEquals(Z.bg, observedBackground)
        }
    }

    @Test
    fun detailRemainsScrollableAtTwoHundredPercentFontAndLabelsPatientVisibleReply() {
        var replyDraft = ""
        val item = sampleItem(
            assignedToMe = true,
            ownershipState = "acknowledged",
            messages = listOf(sampleMessage()),
        )

        compose.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                HummingbirdTheme {
                    PatientCommunicationDetailContent(
                        item = item,
                        canRespond = true,
                        replyDraft = replyDraft,
                        mutating = false,
                        error = null,
                        mutationError = null,
                        notice = null,
                        conflictNotice = null,
                        canRetryMutation = false,
                        onReplyDraftChange = { replyDraft = it },
                        onClaim = {},
                        onReply = {},
                        onClose = {},
                        onRetryMutation = {},
                        onDiscardAndRefresh = {},
                        onRefresh = {},
                        onOpenPatient = {},
                    )
                }
            }
        }

        compose.onNodeWithText("Not an emergency channel").assertIsDisplayed()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Response and escalation"))
        compose.onNodeWithText("Response and escalation").assertIsDisplayed()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Reply to the patient"))
        compose.onNodeWithText("Reply to the patient").assertIsDisplayed()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Send patient-visible reply"))
        compose.onNodeWithText("Send patient-visible reply").assertIsDisplayed()
        compose.onNode(hasScrollAction()).performScrollToNode(
            hasText("Send a patient-visible care-team response before closing."),
        )
        compose.onNodeWithText("Send a patient-visible care-team response before closing.")
            .assertIsDisplayed()
    }

    @Test
    fun respondedConversationRequiresExplicitCloseReasonConfirmation() {
        var closed = false
        val item = sampleItem(
            assignedToMe = true,
            ownershipState = "responded",
            messages = listOf(sampleMessage()),
        )

        compose.setContent {
            HummingbirdTheme {
                PatientCommunicationDetailContent(
                    item = item,
                    canRespond = true,
                    replyDraft = "",
                    mutating = false,
                    error = null,
                    mutationError = null,
                    notice = null,
                    conflictNotice = null,
                    canRetryMutation = false,
                    onReplyDraftChange = {},
                    onClaim = {},
                    onReply = {},
                    onClose = { closed = true },
                    onRetryMutation = {},
                    onDiscardAndRefresh = {},
                    onRefresh = {},
                    onOpenPatient = {},
                )
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Close conversation"))
        compose.onNodeWithText("Close conversation").performClick()
        compose.onNodeWithText("Close this conversation?").assertIsDisplayed()
        compose.onNodeWithText("Question answered").assertIsDisplayed()
        compose.onAllNodesWithText("Close conversation")[1].performClick()
        compose.runOnIdle { assertTrue(closed) }
    }

    @Test
    fun unresolvedExactCommandDisablesEveryFreshMutationControl() {
        compose.setContent {
            HummingbirdTheme {
                PatientCommunicationDetailContent(
                    item = sampleItem(
                        assignedToMe = true,
                        ownershipState = "responded",
                        messages = listOf(sampleMessage()),
                    ),
                    canRespond = true,
                    replyDraft = "A retained patient-visible reply",
                    mutating = false,
                    error = null,
                    mutationError = "The prior action was not confirmed.",
                    notice = null,
                    conflictNotice = null,
                    canRetryMutation = true,
                    onReplyDraftChange = {},
                    onClaim = {},
                    onReply = {},
                    onClose = {},
                    onRetryMutation = {},
                    onDiscardAndRefresh = {},
                    onRefresh = {},
                    onOpenPatient = {},
                )
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Retry the same action"))
        compose.onNodeWithText("Retry the same action").assertIsDisplayed()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Manage responsibility"))
        compose.onNodeWithText("Manage responsibility").assertIsNotEnabled()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Send patient-visible reply"))
        compose.onNodeWithText("Send patient-visible reply").assertIsNotEnabled()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Close conversation"))
        compose.onNodeWithText("Close conversation").assertIsNotEnabled()
    }

    @Test
    fun unresolvedClaimDisablesFreshClaimControl() {
        compose.setContent {
            HummingbirdTheme {
                PatientCommunicationDetailContent(
                    item = sampleItem(),
                    canRespond = true,
                    replyDraft = "",
                    mutating = false,
                    error = null,
                    mutationError = "The prior action was not confirmed.",
                    notice = null,
                    conflictNotice = null,
                    canRetryMutation = true,
                    onReplyDraftChange = {},
                    onClaim = {},
                    onReply = {},
                    onClose = {},
                    onRetryMutation = {},
                    onDiscardAndRefresh = {},
                    onRefresh = {},
                    onOpenPatient = {},
                )
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Claim and acknowledge"))
        compose.onNodeWithText("Claim and acknowledge").assertIsNotEnabled()
    }

    @Test
    fun responsibilityChangeUsesServerOptionsAndRequiresExplicitReviewConfirmation() {
        val candidates = sampleRouteCandidates()
        val selectedAction = mutableStateOf<PatientCommunicationRoutingAction?>(null)
        val selectedReason = mutableStateOf<String?>(null)
        val selectedTarget = mutableStateOf<String?>(null)
        var confirmations = 0

        compose.setContent {
            HummingbirdTheme {
                PatientCommunicationDetailContent(
                    item = sampleItem(assignedToMe = true, ownershipState = "acknowledged"),
                    canRespond = true,
                    replyDraft = "",
                    mutating = false,
                    error = null,
                    mutationError = null,
                    notice = null,
                    conflictNotice = null,
                    canRetryMutation = false,
                    routingOpen = true,
                    routeCandidates = candidates,
                    selectedRoutingAction = selectedAction.value,
                    selectedRoutingReasonCode = selectedReason.value,
                    selectedRoutingTargetUuid = selectedTarget.value,
                    canReviewRouting = PatientCommunicationRoutingPolicy.canSubmit(
                        candidates,
                        selectedAction.value,
                        selectedReason.value,
                        selectedTarget.value,
                    ),
                    onReplyDraftChange = {},
                    onClaim = {},
                    onReply = {},
                    onClose = {},
                    onRetryMutation = {},
                    onDiscardAndRefresh = {},
                    onRefresh = {},
                    onSelectRoutingAction = {
                        selectedAction.value = it
                        selectedReason.value = null
                        selectedTarget.value = null
                    },
                    onSelectRoutingReason = { selectedReason.value = it },
                    onSelectRoutingTarget = { selectedTarget.value = it },
                    onConfirmRouting = { confirmations += 1 },
                    onOpenPatient = {},
                )
            }
        }

        compose.onNodeWithTag("responsibility-routing-options")
            .performScrollToNode(hasText("Reassign responder"))
        compose.onNodeWithText("Reassign responder").performClick()
        compose.onNodeWithTag("responsibility-routing-options")
            .performScrollToNode(hasText("Supervisor assignment"))
        compose.onNodeWithText("Supervisor assignment").performClick()
        compose.onNodeWithTag("responsibility-routing-options")
            .performScrollToNode(hasText("Avery Morgan"))
        compose.onNodeWithText("Avery Morgan").performClick()
        compose.onNodeWithText("Review change").performClick()
        compose.onNodeWithText("Confirm responsibility change").assertIsDisplayed()
        compose.onNodeWithText("Avery Morgan").assertIsDisplayed()
        assertTrue(
            compose.onAllNodesWithText("019f7cb6-4d44-73e1-b28c-82bea62c4200")
                .fetchSemanticsNodes().isEmpty(),
        )
        compose.onNodeWithText("Confirm reassignment").performClick()
        compose.runOnIdle { assertEquals(1, confirmations) }
    }

    @Test
    fun routingDialogRemainsScrollableAndConfirmableAtTwoHundredPercentFont() {
        val candidates = sampleRouteCandidates()
        val selectedAction = mutableStateOf<PatientCommunicationRoutingAction?>(null)
        val selectedReason = mutableStateOf<String?>(null)
        val selectedTarget = mutableStateOf<String?>(null)
        var confirmed = false

        compose.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                HummingbirdTheme {
                    PatientCommunicationDetailContent(
                        item = sampleItem(assignedToMe = true, ownershipState = "acknowledged"),
                        canRespond = true,
                        replyDraft = "",
                        mutating = false,
                        error = null,
                        mutationError = null,
                        notice = null,
                        conflictNotice = null,
                        canRetryMutation = false,
                        routingOpen = true,
                        routeCandidates = candidates,
                        selectedRoutingAction = selectedAction.value,
                        selectedRoutingReasonCode = selectedReason.value,
                        selectedRoutingTargetUuid = selectedTarget.value,
                        canReviewRouting = PatientCommunicationRoutingPolicy.canSubmit(
                            candidates,
                            selectedAction.value,
                            selectedReason.value,
                            selectedTarget.value,
                        ),
                        onReplyDraftChange = {},
                        onClaim = {},
                        onReply = {},
                        onClose = {},
                        onRetryMutation = {},
                        onDiscardAndRefresh = {},
                        onRefresh = {},
                        onSelectRoutingAction = {
                            selectedAction.value = it
                            selectedReason.value = null
                            selectedTarget.value = null
                        },
                        onSelectRoutingReason = { selectedReason.value = it },
                        onSelectRoutingTarget = { selectedTarget.value = it },
                        onConfirmRouting = { confirmed = true },
                        onOpenPatient = {},
                    )
                }
            }
        }

        compose.onNodeWithTag("responsibility-routing-options")
            .performScrollToNode(hasText("Reroute to another team"))
        compose.onNodeWithText("Reroute to another team").performClick()
        compose.onNodeWithTag("responsibility-routing-options")
            .performScrollToNode(hasText("Wrong responsibility team"))
        compose.onNodeWithText("Wrong responsibility team").performClick()
        compose.onNodeWithTag("responsibility-routing-options")
            .performScrollToNode(hasText("6 West care team"))
        compose.onNodeWithText("6 West care team").performClick()
        compose.onNodeWithText("Review change").performClick()
        compose.onNodeWithText("Confirm responsibility change").assertIsDisplayed()
        compose.onNodeWithText("Confirm reroute").performClick()
        compose.runOnIdle { assertTrue(confirmed) }
    }

    @Test
    fun accessBoundaryRemovesSentinelPhiAndControlsBeforeUnavailableUi() {
        val sentinel = "SENTINEL PHI: decrypted patient message"
        val displayed = mutableStateOf<PatientCommunicationWorkItem?>(
            sampleItem(
                assignedToMe = true,
                ownershipState = "acknowledged",
                messages = listOf(sampleMessage().copy(body = sentinel)),
            ),
        )
        val error = mutableStateOf<String?>(null)

        compose.setContent {
            HummingbirdTheme {
                PatientCommunicationDetailAccessBoundary(
                    item = displayed.value,
                    loading = false,
                    error = error.value,
                    onRefresh = {},
                ) { item ->
                    PatientCommunicationDetailContent(
                        item = item,
                        canRespond = true,
                        replyDraft = "SENTINEL PHI: staff draft",
                        mutating = false,
                        error = null,
                        mutationError = null,
                        notice = null,
                        conflictNotice = null,
                        canRetryMutation = false,
                        onReplyDraftChange = {},
                        onClaim = {},
                        onReply = {},
                        onClose = {},
                        onRetryMutation = {},
                        onDiscardAndRefresh = {},
                        onRefresh = {},
                        onOpenPatient = {},
                    )
                }
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(hasText(sentinel))
        compose.onNodeWithText(sentinel).assertIsDisplayed()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Manage responsibility"))
        compose.onNodeWithText("Manage responsibility").assertIsDisplayed()

        compose.runOnIdle {
            displayed.value = null
            error.value = "This conversation is no longer available to you. No patient information remains in this view."
        }

        compose.onNodeWithText("Conversation unavailable").assertIsDisplayed()
        compose.onNodeWithText(
            "This conversation is no longer available to you. No patient information remains in this view.",
        ).assertIsDisplayed()
        assertTrue(compose.onAllNodesWithText(sentinel).fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Manage responsibility").fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Send patient-visible reply").fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun viewOnlyCapabilityNeverExposesMutationControls() {
        compose.setContent {
            HummingbirdTheme {
                PatientCommunicationDetailContent(
                    item = sampleItem(),
                    canRespond = false,
                    replyDraft = "",
                    mutating = false,
                    error = null,
                    mutationError = null,
                    notice = null,
                    conflictNotice = null,
                    canRetryMutation = false,
                    onReplyDraftChange = {},
                    onClaim = {},
                    onReply = {},
                    onClose = {},
                    onRetryMutation = {},
                    onDiscardAndRefresh = {},
                    onRefresh = {},
                    onOpenPatient = {},
                )
            }
        }

        compose.onNode(hasScrollAction()).performScrollToNode(
            hasText("You have read-only access to this conversation. A current responsibility-pool responder must take action."),
        )
        compose.onNodeWithText("You have read-only access to this conversation. A current responsibility-pool responder must take action.")
            .assertIsDisplayed()
        assertTrue(compose.onAllNodesWithText("Claim and acknowledge").fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Send patient-visible reply").fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Close conversation").fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Manage responsibility").fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun pollingPausesForLifecycleOrLockAndResumesWithAnImmediateRead() {
        assertEquals(20_000L, PATIENT_COMMUNICATIONS_POLL_INTERVAL_MILLIS)
        assertFalse(
            shouldPollPatientCommunications(
                canView = true,
                foreground = false,
                locked = false,
                surfaceVisible = true,
            ),
        )
        assertFalse(
            shouldPollPatientCommunications(
                canView = true,
                foreground = true,
                locked = true,
                surfaceVisible = true,
            ),
        )

        val active = mutableStateOf(true)
        val calls = AtomicInteger(0)
        val ticks = Channel<Unit>(capacity = Channel.UNLIMITED)
        compose.setContent {
            PatientCommunicationsPollingEffect(
                active = active.value,
                bearer = "test-token",
                workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192",
                foregroundEpoch = 1,
                onPoll = { _, _ -> calls.incrementAndGet() },
                awaitNextPoll = { ticks.receive() },
                isPollingStillAllowed = { active.value },
            )
        }

        compose.waitUntil(timeoutMillis = 5_000) { calls.get() == 1 }
        ticks.trySend(Unit)
        compose.waitUntil(timeoutMillis = 5_000) { calls.get() == 2 }
        compose.runOnIdle { active.value = false }
        ticks.trySend(Unit)
        compose.runOnIdle { assertEquals(2, calls.get()) }

        compose.runOnIdle { active.value = true }
        compose.waitUntil(timeoutMillis = 5_000) { calls.get() == 3 }
    }

    @Test
    fun pollingAwaitsTheCurrentReadBeforeStartingAnother() {
        val calls = AtomicInteger(0)
        val firstRead = CompletableDeferred<Unit>()
        val ticks = Channel<Unit>(capacity = Channel.UNLIMITED)
        compose.setContent {
            PatientCommunicationsPollingEffect(
                active = true,
                bearer = "test-token",
                workItemUuid = null,
                foregroundEpoch = 1,
                onPoll = { _, _ ->
                    if (calls.incrementAndGet() == 1) firstRead.await()
                },
                awaitNextPoll = { ticks.receive() },
            )
        }

        compose.waitUntil(timeoutMillis = 5_000) { calls.get() == 1 }
        ticks.trySend(Unit)
        compose.runOnIdle { assertEquals(1, calls.get()) }

        firstRead.complete(Unit)
        compose.waitUntil(timeoutMillis = 5_000) { calls.get() == 2 }
        assertEquals(2, calls.get())
    }

    private fun sampleItem(
        assignedToMe: Boolean = false,
        ownershipState: String = "pool_owned",
        isEscalationDue: Boolean = false,
        messages: List<PatientCommunicationMessage> = emptyList(),
    ) = PatientCommunicationWorkItem(
        workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192",
        threadUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4191",
        patientContextRef = "ptok_test_only",
        topic = PatientCommunicationTopic("medication_question", "Medication question"),
        unit = PatientCommunicationUnit(85, "5 East"),
        pool = PatientCommunicationPool("019f7cb6-4d44-73e1-b28c-82bea62c4190", "5 East care team"),
        status = "open",
        ownershipState = ownershipState,
        assignedToMe = assignedToMe,
        workItemVersion = 7,
        threadVersion = 11,
        lastMessageAt = "2026-07-19T14:00:00-04:00",
        dueAt = "2026-07-19T14:30:00-04:00",
        escalateAt = "2026-07-19T15:00:00-04:00",
        isResponseDue = isEscalationDue,
        isEscalationDue = isEscalationDue,
        closedAt = null,
        messages = messages,
    )

    private fun sampleMessage() = PatientCommunicationMessage(
        messageUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4189",
        senderDisplayRole = "Patient",
        visibility = "patient_visible",
        messageKind = "message",
        body = "I have a question about tonight's medicine.",
        deliveryState = "acknowledged",
        sentAt = "2026-07-19T14:00:00-04:00",
    )

    private fun sampleRouteCandidates() = PatientCommunicationRouteCandidates(
        workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192",
        workItemVersion = 7,
        threadVersion = 11,
        actions = PatientCommunicationRouteActions(
            canRelease = true,
            canReassign = true,
            canReroute = true,
        ),
        reasonOptions = PatientCommunicationRouteReasonOptions(
            release = listOf(PatientCommunicationRouteReason("return_to_team", "Return to team queue")),
            reassign = listOf(PatientCommunicationRouteReason("supervisor_assignment", "Supervisor assignment")),
            reroute = listOf(PatientCommunicationRouteReason("wrong_team", "Wrong responsibility team")),
        ),
        reassignCandidates = listOf(
            PatientCommunicationReassignCandidate(
                membershipUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4200",
                label = "Avery Morgan",
                membershipRole = "responder",
            ),
        ),
        rerouteCandidates = listOf(
            PatientCommunicationRerouteCandidate(
                poolUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4300",
                label = "6 West care team",
                scopeType = "unit",
                unit = PatientCommunicationUnit(86, "6 West"),
            ),
        ),
    )
}
