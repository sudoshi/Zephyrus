package net.acumenus.hummingbird.ui.communications

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.ChatBubble
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.PatientCommunicationAttention
import net.acumenus.hummingbird.data.PatientCommunicationCloseReason
import net.acumenus.hummingbird.data.PatientCommunicationMessage
import net.acumenus.hummingbird.data.PatientCommunicationPresentation
import net.acumenus.hummingbird.data.PatientCommunicationReassignCandidate
import net.acumenus.hummingbird.data.PatientCommunicationRerouteCandidate
import net.acumenus.hummingbird.data.PatientCommunicationRouteCandidates
import net.acumenus.hummingbird.data.PatientCommunicationRouteReason
import net.acumenus.hummingbird.data.PatientCommunicationRoutingAction
import net.acumenus.hummingbird.data.PatientCommunicationRoutingPolicy
import net.acumenus.hummingbird.data.PatientCommunicationWorkItem
import net.acumenus.hummingbird.data.PatientCommunicationsViewModel
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PatientCommunicationsInboxScreen(
    auth: AuthViewModel,
    viewModel: PatientCommunicationsViewModel,
    forceError: Boolean = false,
    active: Boolean,
    refreshEpoch: Int,
    onOpenProfile: () -> Unit,
    onOpenThread: (String) -> Unit,
) {
    val bearer = auth.accessToken.orEmpty()

    LaunchedEffect(bearer, forceError, active, refreshEpoch) {
        if (!forceError && active) viewModel.loadInbox(bearer)
    }
    LaunchedEffect(viewModel.needsReauth) {
        if (viewModel.needsReauth) auth.logout()
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Patient Communications", fontWeight = FontWeight.SemiBold) },
                actions = {
                    IconButton(onClick = onOpenProfile) {
                        Icon(Icons.Filled.Person, contentDescription = "Profile")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
            )
        },
    ) { inner ->
        PatientCommunicationsInboxContent(
            items = viewModel.inbox,
            loading = viewModel.inboxLoading,
            error = if (forceError) "Can't load Patient Communications. Check your connection and try again." else viewModel.inboxError,
            unavailable = viewModel.unavailable,
            onRefresh = { viewModel.loadInbox(bearer) },
            onOpenThread = onOpenThread,
            modifier = Modifier.padding(inner),
        )
    }
}

@Composable
fun PatientCommunicationsInboxContent(
    items: List<PatientCommunicationWorkItem>,
    loading: Boolean,
    error: String?,
    unavailable: Boolean,
    onRefresh: () -> Unit,
    onOpenThread: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    HbRefreshable(
        refreshing = loading,
        onRefresh = onRefresh,
        modifier = modifier,
    ) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(Z.s4),
            verticalArrangement = Arrangement.spacedBy(Z.s3),
        ) {
            item { StaffUrgencyGuidance() }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(Z.s1)) {
                    Text("Care-team inbox", color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                    Text(
                        "${items.size} open conversation${if (items.size == 1) "" else "s"} in your active responsibility pools",
                        color = Z.inkMuted,
                        fontSize = 13.sp,
                    )
                }
            }

            when {
                items.isEmpty() && loading -> item {
                    RetryableMessage(
                        title = "Loading patient messages",
                        message = "Checking only the responsibility pools you currently belong to.",
                        tone = CapacityStatus.INFO,
                        loading = true,
                    )
                }

                items.isEmpty() && error != null -> item {
                    RetryableMessage(
                        title = if (unavailable) "Not available" else "Can't load patient messages",
                        message = error,
                        tone = CapacityStatus.WARNING,
                        retryLabel = if (unavailable) null else "Try again",
                        onRetry = if (unavailable) null else onRefresh,
                    )
                }

                items.isEmpty() -> item {
                    EmptyInbox()
                }

                else -> {
                    if (error != null) {
                        item {
                            InlineNotice(
                                text = error,
                                tone = NoticeTone.Warning,
                                actionLabel = "Refresh",
                                onAction = onRefresh,
                            )
                        }
                    }
                    items(items, key = { it.workItemUuid }) { item ->
                        PatientCommunicationInboxRow(item = item, onOpen = { onOpenThread(item.workItemUuid) })
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PatientCommunicationDetailScreen(
    auth: AuthViewModel,
    viewModel: PatientCommunicationsViewModel,
    workItemUuid: String,
    canRespond: Boolean,
    active: Boolean,
    refreshEpoch: Int,
    onBack: () -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    val bearer = auth.accessToken.orEmpty()

    LaunchedEffect(bearer, workItemUuid, active, refreshEpoch) {
        if (active) viewModel.loadThread(bearer, workItemUuid)
    }
    LaunchedEffect(viewModel.needsReauth) {
        if (viewModel.needsReauth) auth.logout()
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Patient conversation", fontWeight = FontWeight.SemiBold) },
                navigationIcon = {
                    IconButton(
                        onClick = {
                            viewModel.closeThread()
                            onBack()
                        },
                    ) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    navigationIconContentColor = Z.ink,
                ),
            )
        },
    ) { inner ->
        val item = viewModel.detail?.takeIf { it.workItemUuid == workItemUuid }
        if (item == null && viewModel.hasPendingMutation) {
            DetachedMutationRecoveryBoundary(
                message = viewModel.mutationError
                    ?: "The responsibility change could not be confirmed.",
                canRetry = viewModel.canRetryMutation,
                mutating = viewModel.mutating,
                onRetry = { viewModel.retryPendingMutation(bearer) },
                onDiscardAndRefresh = {
                    viewModel.discardPendingMutation()
                    viewModel.loadThread(bearer, workItemUuid)
                },
                modifier = Modifier.padding(inner),
            )
        } else PatientCommunicationDetailAccessBoundary(
                item = item,
                loading = viewModel.detailLoading,
                error = viewModel.detailError,
                onRefresh = { viewModel.loadThread(bearer, workItemUuid) },
                modifier = Modifier.padding(inner),
            ) { displayedItem ->
            PatientCommunicationDetailContent(
                item = displayedItem,
                canRespond = canRespond,
                replyDraft = viewModel.replyDraft,
                mutating = viewModel.mutating,
                error = viewModel.detailError,
                mutationError = viewModel.mutationError,
                notice = viewModel.notice,
                conflictNotice = viewModel.conflictNotice,
                canRetryMutation = viewModel.canRetryMutation,
                routingOpen = viewModel.routingOpen,
                routeCandidates = viewModel.routeCandidates,
                routeCandidatesLoading = viewModel.routeCandidatesLoading,
                routeCandidatesError = viewModel.routeCandidatesError,
                selectedRoutingAction = viewModel.selectedRoutingAction,
                selectedRoutingReasonCode = viewModel.selectedRoutingReasonCode,
                selectedRoutingTargetUuid = viewModel.selectedRoutingTargetUuid,
                canReviewRouting = viewModel.canReviewRouting,
                onReplyDraftChange = viewModel::updateReplyDraft,
                onClaim = { viewModel.claim(bearer) },
                onReply = { viewModel.reply(bearer) },
                onClose = { viewModel.close(bearer, it) },
                onRetryMutation = { viewModel.retryPendingMutation(bearer) },
                onDiscardAndRefresh = {
                    viewModel.discardPendingMutation()
                    viewModel.loadThread(bearer, workItemUuid)
                },
                onRefresh = { viewModel.loadThread(bearer, workItemUuid) },
                onOpenRouting = { viewModel.openRouting(bearer, canRespond) },
                onDismissRouting = viewModel::dismissRouting,
                onSelectRoutingAction = viewModel::selectRoutingAction,
                onSelectRoutingReason = viewModel::selectRoutingReason,
                onSelectRoutingTarget = viewModel::selectRoutingTarget,
                onConfirmRouting = { viewModel.confirmRouting(bearer, canRespond) },
                onOpenPatient = onOpenPatient,
                modifier = Modifier.padding(inner),
            )
        }
    }
}

@Composable
private fun DetachedMutationRecoveryBoundary(
    message: String,
    canRetry: Boolean,
    mutating: Boolean,
    onRetry: () -> Unit,
    onDiscardAndRefresh: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            modifier = Modifier.padding(Z.s4).widthIn(max = 560.dp),
            verticalArrangement = Arrangement.spacedBy(Z.s3),
        ) {
            Text("Responsibility change not confirmed", color = Z.ink, fontWeight = FontWeight.SemiBold)
            Text(
                "Patient details were removed from this device while the result is uncertain. Nothing will be resent automatically.",
                color = Z.inkMuted,
            )
            Text(message, color = Z.inkMuted)
            if (mutating) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Z.s2)) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                    Text("Confirming the same action…", color = Z.inkMuted)
                }
            } else if (canRetry) {
                Button(onClick = onRetry, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.Filled.Refresh, contentDescription = null)
                    Spacer(Modifier.size(Z.s2))
                    Text("Retry the same action")
                }
                OutlinedButton(
                    onClick = onDiscardAndRefresh,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                ) {
                    Text("Discard action and refresh")
                }
            }
        }
    }
}

@Composable
internal fun PatientCommunicationDetailAccessBoundary(
    item: PatientCommunicationWorkItem?,
    loading: Boolean,
    error: String?,
    onRefresh: () -> Unit,
    modifier: Modifier = Modifier,
    content: @Composable (PatientCommunicationWorkItem) -> Unit,
) {
    when {
        item == null && loading -> Box(
            modifier.fillMaxSize(),
            contentAlignment = Alignment.Center,
        ) {
            CircularProgressIndicator(color = Z.primary)
        }

        item == null -> LazyColumn(
            modifier = modifier.fillMaxSize(),
            contentPadding = PaddingValues(Z.s4),
        ) {
            item { StaffUrgencyGuidance() }
            item {
                RetryableMessage(
                    title = "Conversation unavailable",
                    message = error ?: "This conversation may have moved or your access may have changed.",
                    tone = CapacityStatus.WARNING,
                    retryLabel = "Refresh",
                    onRetry = onRefresh,
                )
            }
        }

        else -> content(item)
    }
}

@Composable
fun PatientCommunicationDetailContent(
    item: PatientCommunicationWorkItem,
    canRespond: Boolean,
    replyDraft: String,
    mutating: Boolean,
    error: String?,
    mutationError: String?,
    notice: String?,
    conflictNotice: String?,
    canRetryMutation: Boolean,
    routingOpen: Boolean = false,
    routeCandidates: PatientCommunicationRouteCandidates? = null,
    routeCandidatesLoading: Boolean = false,
    routeCandidatesError: String? = null,
    selectedRoutingAction: PatientCommunicationRoutingAction? = null,
    selectedRoutingReasonCode: String? = null,
    selectedRoutingTargetUuid: String? = null,
    canReviewRouting: Boolean = false,
    onReplyDraftChange: (String) -> Unit,
    onClaim: () -> Unit,
    onReply: () -> Unit,
    onClose: (PatientCommunicationCloseReason) -> Unit,
    onRetryMutation: () -> Unit,
    onDiscardAndRefresh: () -> Unit,
    onRefresh: () -> Unit,
    onOpenRouting: () -> Unit = {},
    onDismissRouting: () -> Unit = {},
    onSelectRoutingAction: (PatientCommunicationRoutingAction) -> Unit = {},
    onSelectRoutingReason: (String) -> Unit = {},
    onSelectRoutingTarget: (String) -> Unit = {},
    onConfirmRouting: () -> Unit = {},
    onOpenPatient: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    var showCloseDialog by remember { mutableStateOf(false) }
    val freshActionsEnabled = !mutating && !canRetryMutation
    val canReply = canRespond && item.status == "open" && item.assignedToMe
    val canClaim = canRespond && PatientCommunicationPresentation.isClaimable(item)
    val canClose = canReply && item.ownershipState == "responded"
    val canManageRouting = PatientCommunicationRoutingPolicy.canOpen(canRespond, item)

    HbRefreshable(
        refreshing = false,
        onRefresh = onRefresh,
        modifier = modifier,
    ) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(Z.s4),
            verticalArrangement = Arrangement.spacedBy(Z.s3),
        ) {
            item { StaffUrgencyGuidance() }
            item { ConversationSummary(item, onOpenPatient) }
            item { ResponseTargets(item) }

            if (conflictNotice != null) {
                item { InlineNotice(conflictNotice, NoticeTone.Warning) }
            }
            if (notice != null) {
                item { InlineNotice(notice, NoticeTone.Success) }
            }
            if (error != null) {
                item { InlineNotice(error, NoticeTone.Warning, "Refresh", onRefresh) }
            }
            if (mutationError != null) {
                item {
                    MutationFailurePanel(
                        message = mutationError,
                        canRetry = canRetryMutation,
                        onRetry = onRetryMutation,
                        onDiscardAndRefresh = onDiscardAndRefresh,
                    )
                }
            }

            if (item.hasEarlierMessages) {
                item {
                    Text(
                        "Earlier messages are not shown in this bounded mobile view.",
                        color = Z.inkMuted,
                        fontSize = 12.sp,
                    )
                }
            }
            if (item.messages.isEmpty()) {
                item { InlineNotice("No message content is available in this view.", NoticeTone.Info) }
            } else {
                items(item.messages, key = { it.messageUuid }) { message ->
                    PatientCommunicationMessageBubble(message)
                }
            }

            if (item.status == "open") {
                if (canManageRouting) {
                    item {
                        OutlinedButton(
                            onClick = onOpenRouting,
                            enabled = freshActionsEnabled,
                            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                        ) {
                            Icon(Icons.Filled.Person, contentDescription = null)
                            Spacer(Modifier.size(Z.s2))
                            Text("Manage responsibility")
                        }
                    }
                }

                item {
                    when {
                        !canRespond -> InlineNotice(
                            "You have read-only access to this conversation. A current responsibility-pool responder must take action.",
                            NoticeTone.Info,
                            "Refresh",
                            onRefresh,
                        )

                        canClaim -> Button(
                            onClick = onClaim,
                            enabled = freshActionsEnabled,
                            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                        ) {
                            if (mutating) {
                                CircularProgressIndicator(
                                    modifier = Modifier.size(18.dp),
                                    strokeWidth = 2.dp,
                                    color = Color.White,
                                )
                                Spacer(Modifier.size(Z.s2))
                            }
                            Text("Claim and acknowledge")
                        }

                        !item.assignedToMe -> InlineNotice(
                            "This conversation is owned by another team member. Refresh if responsibility changes.",
                            NoticeTone.Info,
                            "Refresh",
                            onRefresh,
                        )

                        else -> ReplyComposer(
                            value = replyDraft,
                            enabled = freshActionsEnabled,
                            onValueChange = onReplyDraftChange,
                            onSend = onReply,
                        )
                    }
                }

                if (item.assignedToMe) {
                    item {
                        OutlinedButton(
                            onClick = { showCloseDialog = true },
                            enabled = canClose && freshActionsEnabled,
                            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                        ) {
                            Icon(Icons.Filled.CheckCircle, contentDescription = null)
                            Spacer(Modifier.size(Z.s2))
                            Text("Close conversation")
                        }
                        if (!canClose) {
                            Text(
                                "Send a patient-visible care-team response before closing.",
                                color = Z.inkMuted,
                                fontSize = 12.sp,
                                modifier = Modifier.padding(top = Z.s2),
                            )
                        }
                    }
                }
            }
        }
    }

    if (showCloseDialog) {
        CloseConversationDialog(
            onDismiss = { showCloseDialog = false },
            onConfirm = {
                showCloseDialog = false
                onClose(it)
            },
        )
    }

    if (routingOpen) {
        RoutingFlowDialog(
            candidates = routeCandidates,
            loading = routeCandidatesLoading,
            error = routeCandidatesError,
            selectedAction = selectedRoutingAction,
            selectedReasonCode = selectedRoutingReasonCode,
            selectedTargetUuid = selectedRoutingTargetUuid,
            canReview = canReviewRouting,
            mutating = mutating,
            onDismiss = onDismissRouting,
            onSelectAction = onSelectRoutingAction,
            onSelectReason = onSelectRoutingReason,
            onSelectTarget = onSelectRoutingTarget,
            onConfirm = onConfirmRouting,
        )
    }
}

@Composable
private fun RoutingFlowDialog(
    candidates: PatientCommunicationRouteCandidates?,
    loading: Boolean,
    error: String?,
    selectedAction: PatientCommunicationRoutingAction?,
    selectedReasonCode: String?,
    selectedTargetUuid: String?,
    canReview: Boolean,
    mutating: Boolean,
    onDismiss: () -> Unit,
    onSelectAction: (PatientCommunicationRoutingAction) -> Unit,
    onSelectReason: (String) -> Unit,
    onSelectTarget: (String) -> Unit,
    onConfirm: () -> Unit,
) {
    var reviewing by remember(
        candidates?.workItemUuid,
        selectedAction,
        selectedReasonCode,
        selectedTargetUuid,
    ) { mutableStateOf(false) }

    if (reviewing && candidates != null && selectedAction != null && selectedReasonCode != null) {
        RoutingConfirmationDialog(
            candidates = candidates,
            action = selectedAction,
            reasonCode = selectedReasonCode,
            targetUuid = selectedTargetUuid,
            mutating = mutating,
            onBack = { reviewing = false },
            onConfirm = onConfirm,
        )
        return
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Manage responsibility") },
        text = {
            LazyColumn(
                modifier = Modifier
                    .fillMaxWidth()
                    .heightIn(max = 520.dp)
                    .testTag("responsibility-routing-options"),
                verticalArrangement = Arrangement.spacedBy(Z.s3),
            ) {
                item {
                    Text(
                        "Only server-authorized responsibility options are shown. Patient message content is not included in this routing step.",
                    )
                }
                when {
                    loading -> item {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(Z.s2),
                        ) {
                            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                            Text("Loading current options")
                        }
                    }

                    error != null -> item { Text(error, color = Z.statusWarning) }

                    candidates == null -> item { Text("No responsibility options are available.") }

                    else -> {
                        val actions = PatientCommunicationRoutingAction.entries.filter {
                            PatientCommunicationRoutingPolicy.isAllowed(it, candidates.actions)
                        }
                        if (actions.isEmpty()) {
                            item { Text("No responsibility changes are available for this conversation.") }
                        } else {
                            item { RoutingSectionLabel("1. Choose an action") }
                            items(actions, key = { it.name }) { action ->
                                RoutingRadioRow(
                                    selected = action == selectedAction,
                                    label = action.label,
                                    supporting = routingActionDescription(action),
                                    onClick = { onSelectAction(action) },
                                )
                            }

                            selectedAction?.let { action ->
                                val reasons = PatientCommunicationRoutingPolicy.reasons(
                                    action,
                                    candidates.reasonOptions,
                                )
                                item { RoutingSectionLabel("2. Choose a reason") }
                                items(reasons, key = { "reason-${it.code}" }) { reason ->
                                    RoutingRadioRow(
                                        selected = reason.code == selectedReasonCode,
                                        label = reason.label,
                                        onClick = { onSelectReason(reason.code) },
                                    )
                                }

                                when (action) {
                                    PatientCommunicationRoutingAction.Release -> item {
                                        Text(
                                            "The conversation will return to its current responsibility team for another eligible responder.",
                                            color = Z.inkMuted,
                                            fontSize = 12.sp,
                                        )
                                    }

                                    PatientCommunicationRoutingAction.Reassign -> {
                                        item { RoutingSectionLabel("3. Choose a responder") }
                                        items(
                                            candidates.reassignCandidates,
                                            key = PatientCommunicationReassignCandidate::membershipUuid,
                                        ) { candidate ->
                                            RoutingRadioRow(
                                                selected = candidate.membershipUuid == selectedTargetUuid,
                                                label = candidate.label,
                                                supporting = membershipRoleCopy(candidate.membershipRole),
                                                onClick = { onSelectTarget(candidate.membershipUuid) },
                                            )
                                        }
                                    }

                                    PatientCommunicationRoutingAction.Reroute -> {
                                        item { RoutingSectionLabel("3. Choose a responsibility team") }
                                        items(
                                            candidates.rerouteCandidates,
                                            key = PatientCommunicationRerouteCandidate::poolUuid,
                                        ) { candidate ->
                                            RoutingRadioRow(
                                                selected = candidate.poolUuid == selectedTargetUuid,
                                                label = candidate.label,
                                                supporting = responsibilityScopeCopy(candidate),
                                                onClick = { onSelectTarget(candidate.poolUuid) },
                                            )
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        },
        confirmButton = {
            Button(
                onClick = { reviewing = true },
                enabled = canReview && !mutating,
                modifier = Modifier.heightIn(min = 48.dp),
            ) {
                Text("Review change")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) {
                Text("Cancel")
            }
        },
    )
}

@Composable
private fun RoutingConfirmationDialog(
    candidates: PatientCommunicationRouteCandidates,
    action: PatientCommunicationRoutingAction,
    reasonCode: String,
    targetUuid: String?,
    mutating: Boolean,
    onBack: () -> Unit,
    onConfirm: () -> Unit,
) {
    val reason = PatientCommunicationRoutingPolicy.reasons(action, candidates.reasonOptions)
        .firstOrNull { it.code == reasonCode }
    val target = when (action) {
        PatientCommunicationRoutingAction.Release -> "Current responsibility team"
        PatientCommunicationRoutingAction.Reassign -> candidates.reassignCandidates
            .firstOrNull { it.membershipUuid == targetUuid }
            ?.label
        PatientCommunicationRoutingAction.Reroute -> candidates.rerouteCandidates
            .firstOrNull { it.poolUuid == targetUuid }
            ?.label
    }
    val valid = reason != null && target != null && PatientCommunicationRoutingPolicy.canSubmit(
        candidates = candidates,
        action = action,
        reasonCode = reasonCode,
        targetUuid = targetUuid,
    )

    AlertDialog(
        onDismissRequest = onBack,
        title = { Text("Confirm responsibility change") },
        text = {
            LazyColumn(
                modifier = Modifier.fillMaxWidth().heightIn(max = 420.dp),
                verticalArrangement = Arrangement.spacedBy(Z.s2),
            ) {
                item { ConfirmationLine("Action", action.label) }
                item { ConfirmationLine("Reason", reason?.label ?: "Option unavailable") }
                item { ConfirmationLine("Destination", target ?: "Option unavailable") }
                item {
                    Text(
                        "Confirm only if this is the intended accountable handoff. The app will send this action once; an uncertain result is never resent automatically.",
                        color = Z.inkMuted,
                        fontSize = 13.sp,
                    )
                }
            }
        },
        confirmButton = {
            Button(
                onClick = onConfirm,
                enabled = valid && !mutating,
                modifier = Modifier.heightIn(min = 48.dp),
            ) {
                Text(routingConfirmationLabel(action))
            }
        },
        dismissButton = {
            TextButton(onClick = onBack, enabled = !mutating, modifier = Modifier.heightIn(min = 48.dp)) {
                Text("Back")
            }
        },
    )
}

@Composable
private fun RoutingRadioRow(
    selected: Boolean,
    label: String,
    supporting: String? = null,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .heightIn(min = 48.dp)
            .selectable(selected = selected, onClick = onClick, role = Role.RadioButton)
            .padding(vertical = Z.s1),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        RadioButton(selected = selected, onClick = null)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(label, color = Z.ink)
            supporting?.let { Text(it, color = Z.inkMuted, fontSize = 12.sp) }
        }
    }
}

@Composable
private fun RoutingSectionLabel(label: String) {
    Text(label, color = Z.ink, fontWeight = FontWeight.SemiBold)
}

@Composable
private fun ConfirmationLine(label: String, value: String) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, color = Z.inkMuted, fontSize = 12.sp)
        Text(value, color = Z.ink, fontWeight = FontWeight.SemiBold)
    }
}

private fun routingActionDescription(action: PatientCommunicationRoutingAction): String = when (action) {
    PatientCommunicationRoutingAction.Release -> "Return it to the current team queue."
    PatientCommunicationRoutingAction.Reassign -> "Hand it to another authorized responder."
    PatientCommunicationRoutingAction.Reroute -> "Move it to another authorized responsibility team."
}

private fun routingConfirmationLabel(action: PatientCommunicationRoutingAction): String = when (action) {
    PatientCommunicationRoutingAction.Release -> "Release to team"
    PatientCommunicationRoutingAction.Reassign -> "Confirm reassignment"
    PatientCommunicationRoutingAction.Reroute -> "Confirm reroute"
}

private fun membershipRoleCopy(role: String): String = when (role) {
    "responder" -> "Responder"
    "triage" -> "Triage responder"
    "supervisor" -> "Supervisor"
    else -> "Unavailable role"
}

private fun responsibilityScopeCopy(candidate: PatientCommunicationRerouteCandidate): String =
    when (candidate.scopeType) {
        "unit" -> candidate.unit?.label?.let { "Unit: $it" } ?: "Unit team"
        "facility" -> "Facility-wide team"
        "enterprise" -> "Enterprise-wide team"
        else -> "Unavailable scope"
    }

@Composable
private fun StaffUrgencyGuidance() {
    Column(
        modifier = Modifier.fillMaxWidth().panel().padding(Z.s4),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Z.s2),
        ) {
            Icon(Icons.Filled.Warning, contentDescription = null, tint = Z.statusWarning)
            Text("Not an emergency channel", color = Z.ink, fontWeight = FontWeight.SemiBold)
        }
        Text(
            "If a message suggests immediate danger or urgent bedside need, use your hospital's emergency, rapid-response, or bedside escalation process now. Do not rely on a reply here.",
            color = Z.inkMuted,
            fontSize = 13.sp,
        )
    }
}

@Composable
private fun EmptyInbox() {
    Column(
        modifier = Modifier.fillMaxWidth().padding(top = Z.s6),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Z.statusSuccess, modifier = Modifier.size(40.dp))
        Text("No open patient messages", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
        Text(
            "There are no open conversations in your active responsibility pools.",
            color = Z.inkMuted,
            fontSize = 13.sp,
        )
    }
}

@Composable
private fun PatientCommunicationInboxRow(
    item: PatientCommunicationWorkItem,
    onOpen: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxWidth().panel().clickable(onClick = onOpen).padding(Z.s4),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.Top,
            horizontalArrangement = Arrangement.spacedBy(Z.s3),
        ) {
            Icon(Icons.Filled.ChatBubble, contentDescription = null, tint = Z.primary, modifier = Modifier.size(22.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(Z.s1)) {
                Text(
                    item.topic.label,
                    color = Z.ink,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    listOfNotNull(item.unit?.label, item.pool.label).joinToString(" • "),
                    color = Z.inkMuted,
                    fontSize = 13.sp,
                )
            }
            AttentionPill(item)
        }
        Text(ownershipLabel(item), color = Z.inkMuted, fontSize = 12.sp)
        Text(
            "Last message ${formatTimestamp(item.lastMessageAt)}",
            color = Z.inkMuted,
            fontSize = 12.sp,
        )
    }
}

@Composable
private fun ConversationSummary(
    item: PatientCommunicationWorkItem,
    onOpenPatient: (String) -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxWidth().panel().padding(Z.s4),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.Top,
        ) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(Z.s1)) {
                Text(item.topic.label, color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                Text(
                    listOfNotNull(item.unit?.label, item.pool.label).joinToString(" • "),
                    color = Z.inkMuted,
                    fontSize = 13.sp,
                )
            }
            AttentionPill(item)
        }
        Text(ownershipLabel(item), color = Z.inkMuted, fontSize = 13.sp)
        item.patientContextRef?.let { ref ->
            OutlinedButton(
                onClick = { onOpenPatient(ref) },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) {
                Icon(Icons.Filled.Person, contentDescription = null)
                Spacer(Modifier.size(Z.s2))
                Text("Open operational patient context")
            }
        }
    }
}

@Composable
private fun ResponseTargets(item: PatientCommunicationWorkItem) {
    Column(
        modifier = Modifier.fillMaxWidth().panel().padding(Z.s4),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Z.s2)) {
            Icon(Icons.Filled.Schedule, contentDescription = null, tint = attentionColor(item))
            Text("Response and escalation", color = Z.ink, fontWeight = FontWeight.SemiBold)
        }
        Text("Response target: ${formatTimestamp(item.dueAt)}", color = Z.ink, fontSize = 13.sp)
        Text("Escalate if unanswered: ${formatTimestamp(item.escalateAt)}", color = Z.ink, fontSize = 13.sp)
        Text(
            "These targets support accountable follow-up; urgent clinical needs still use immediate hospital escalation channels.",
            color = Z.inkMuted,
            fontSize = 12.sp,
        )
    }
}

@Composable
private fun AttentionPill(item: PatientCommunicationWorkItem) {
    val color = attentionColor(item)
    Row(
        modifier = Modifier.background(color.copy(alpha = 0.15f), RoundedCornerShape(50)).padding(horizontal = 8.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Icon(attentionIcon(item), contentDescription = null, tint = color, modifier = Modifier.size(14.dp))
        Text(
            PatientCommunicationPresentation.attentionLabel(item),
            color = color,
            fontSize = 11.sp,
            fontWeight = FontWeight.SemiBold,
            maxLines = 2,
        )
    }
}

@Composable
private fun PatientCommunicationMessageBubble(message: PatientCommunicationMessage) {
    val careTeam = message.senderDisplayRole.startsWith("Care team")
    val internal = message.visibility == "staff_internal"
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = if (careTeam) Arrangement.End else Arrangement.Start,
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = 560.dp)
                .background(
                    when {
                        internal -> Z.statusWarning.copy(alpha = 0.12f)
                        careTeam -> Z.primary.copy(alpha = 0.18f)
                        else -> Z.surface
                    },
                    RoundedCornerShape(14.dp),
                )
                .padding(Z.s3),
            verticalArrangement = Arrangement.spacedBy(Z.s1),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Z.s1)) {
                if (internal) Icon(Icons.Filled.Lock, contentDescription = null, tint = Z.statusWarning, modifier = Modifier.size(14.dp))
                Text(
                    if (internal) "${message.senderDisplayRole} • Internal" else message.senderDisplayRole,
                    color = if (internal) Z.statusWarning else Z.inkMuted,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            Text(message.body ?: "Message content unavailable", color = Z.ink, fontSize = 15.sp)
            Text(
                "${humanize(message.deliveryState)} • ${formatTimestamp(message.sentAt)}",
                color = Z.inkMuted,
                fontSize = 11.sp,
            )
        }
    }
}

@Composable
private fun ReplyComposer(
    value: String,
    enabled: Boolean,
    onValueChange: (String) -> Unit,
    onSend: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxWidth().panel().padding(Z.s4),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Text("Reply to the patient", color = Z.ink, fontWeight = FontWeight.SemiBold)
        Text(
            "The patient and any authorized representative can see this reply. Do not include internal routing notes.",
            color = Z.inkMuted,
            fontSize = 12.sp,
        )
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            enabled = enabled,
            modifier = Modifier.fillMaxWidth().heightIn(min = 132.dp),
            label = { Text("Patient-visible reply") },
            supportingText = { Text("${value.length} / 4000 characters") },
            minLines = 4,
            maxLines = 10,
        )
        Button(
            onClick = onSend,
            enabled = enabled && value.trim().isNotEmpty(),
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
        ) {
            Icon(Icons.AutoMirrored.Filled.Send, contentDescription = null)
            Spacer(Modifier.size(Z.s2))
            Text("Send patient-visible reply")
        }
    }
}

@Composable
private fun MutationFailurePanel(
    message: String,
    canRetry: Boolean,
    onRetry: () -> Unit,
    onDiscardAndRefresh: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxWidth().panel().padding(Z.s4),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Z.s2)) {
            Icon(Icons.Filled.Error, contentDescription = null, tint = Z.statusWarning)
            Text("Action not confirmed", color = Z.ink, fontWeight = FontWeight.SemiBold)
        }
        Text(message, color = Z.inkMuted, fontSize = 13.sp)
        if (canRetry) {
            Button(onClick = onRetry, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Icon(Icons.Filled.Refresh, contentDescription = null)
                Spacer(Modifier.size(Z.s2))
                Text("Retry the same action")
            }
        }
        OutlinedButton(onClick = onDiscardAndRefresh, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text("Discard action and refresh")
        }
    }
}

@Composable
private fun CloseConversationDialog(
    onDismiss: () -> Unit,
    onConfirm: (PatientCommunicationCloseReason) -> Unit,
) {
    var selected by remember { mutableStateOf(PatientCommunicationCloseReason.QuestionAnswered) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Close this conversation?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Z.s2)) {
                Text("Choose the internal closure reason. The patient sees only that their question was answered.")
                PatientCommunicationCloseReason.entries.forEach { reason ->
                    Row(
                        modifier = Modifier.fillMaxWidth().clickable { selected = reason }.padding(vertical = Z.s1),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        RadioButton(selected = selected == reason, onClick = { selected = reason })
                        Text(reason.label, modifier = Modifier.weight(1f))
                    }
                }
            }
        },
        confirmButton = {
            Button(onClick = { onConfirm(selected) }) { Text("Close conversation") }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("Cancel") }
        },
    )
}

private enum class NoticeTone { Info, Warning, Success }

@Composable
private fun InlineNotice(
    text: String,
    tone: NoticeTone,
    actionLabel: String? = null,
    onAction: (() -> Unit)? = null,
) {
    val color = when (tone) {
        NoticeTone.Info -> Z.statusInfo
        NoticeTone.Warning -> Z.statusWarning
        NoticeTone.Success -> Z.statusSuccess
    }
    Column(
        modifier = Modifier.fillMaxWidth().background(color.copy(alpha = 0.12f), RoundedCornerShape(12.dp)).padding(Z.s3),
        verticalArrangement = Arrangement.spacedBy(Z.s2),
    ) {
        Text(text, color = Z.ink, fontSize = 13.sp)
        if (actionLabel != null && onAction != null) {
            TextButton(onClick = onAction) { Text(actionLabel, color = color) }
        }
    }
}

private fun ownershipLabel(item: PatientCommunicationWorkItem): String = when {
    item.status == "closed" -> "Conversation closed"
    item.assignedToMe -> "Assigned to you"
    PatientCommunicationPresentation.isClaimable(item) -> "Available to claim"
    else -> "Assigned within ${item.pool.label}"
}

private fun attentionColor(item: PatientCommunicationWorkItem): Color = when (PatientCommunicationPresentation.attention(item)) {
    PatientCommunicationAttention.EscalationDue -> Z.statusCritical
    PatientCommunicationAttention.ResponseDue -> Z.statusWarning
    PatientCommunicationAttention.AwaitingResponse -> Z.statusInfo
    PatientCommunicationAttention.Responded,
    PatientCommunicationAttention.Closed,
    -> Z.statusSuccess
}

private fun attentionIcon(item: PatientCommunicationWorkItem) = when (PatientCommunicationPresentation.attention(item)) {
    PatientCommunicationAttention.EscalationDue -> Icons.Filled.Error
    PatientCommunicationAttention.ResponseDue -> Icons.Filled.Warning
    PatientCommunicationAttention.AwaitingResponse -> Icons.Filled.Schedule
    PatientCommunicationAttention.Responded,
    PatientCommunicationAttention.Closed,
    -> Icons.Filled.CheckCircle
}

internal fun formatTimestamp(value: String?): String {
    if (value.isNullOrBlank()) return "not available"
    return runCatching {
        OffsetDateTime.parse(value)
            .atZoneSameInstant(ZoneId.systemDefault())
            .format(DateTimeFormatter.ofPattern("MMM d, h:mm a", Locale.getDefault()))
    }.getOrElse { "time unavailable" }
}

private fun humanize(value: String): String = value
    .replace('_', ' ')
    .replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() }
