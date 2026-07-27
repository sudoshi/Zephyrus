package net.acumenus.hummingbird.patient.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ChatBubbleOutline
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.patient.PatientMessagingOperation
import net.acumenus.hummingbird.patient.PatientMessagingState
import net.acumenus.hummingbird.patient.data.PatientMessageAmendmentAction
import net.acumenus.hummingbird.patient.data.PatientMessageThread
import net.acumenus.hummingbird.patient.data.PatientMessageTopic

@Composable
internal fun PatientMessagingPanel(
    state: PatientMessagingState,
    onRefresh: () -> Unit,
    onThreadSelected: (String) -> Unit,
    onLeaveThread: () -> Unit,
    onCreateThread: (topicCode: String, message: String) -> Unit,
    onSendMessage: (String) -> Unit,
    onAmendMessage: (String, PatientMessageAmendmentAction, String?) -> Unit,
    onCloseThread: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surface.copy(alpha = 0.97f),
        ),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Outlined.ChatBubbleOutline, contentDescription = null)
                Text(
                    text = "Message your care team",
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.semantics { heading() },
                )
            }
            Text(
                text = "Messages go to the responsible care-team pool, not to one continuously available person.",
                style = MaterialTheme.typography.bodyMedium,
            )

            when (state) {
                PatientMessagingState.Hidden -> Text(
                    text = "Secure care-team messaging is not available for this stay.",
                    style = MaterialTheme.typography.bodyMedium,
                )

                is PatientMessagingState.Loading -> StatusText(state.message)

                is PatientMessagingState.Unavailable -> {
                    StatusText(state.message)
                    OutlinedButton(onClick = onRefresh) { Text("Try again") }
                }

                is PatientMessagingState.Ready -> {
                    ImmediateHelpCard(state.immediateHelp.text)
                    MessagingOperationStatus(state.operation)
                    if (state.selectedThread == null) {
                        ConversationList(
                            state = state,
                            onThreadSelected = onThreadSelected,
                            onCreateThread = onCreateThread,
                        )
                    } else {
                        ThreadConversation(
                            thread = state.selectedThread,
                            canWrite = state.canWrite,
                            busy = state.operation is PatientMessagingOperation.Working,
                            operation = state.operation,
                            onLeaveThread = onLeaveThread,
                            onSendMessage = onSendMessage,
                            onAmendMessage = onAmendMessage,
                            onCloseThread = onCloseThread,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ImmediateHelpCard(text: String) {
    Card(
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.errorContainer,
        ),
    ) {
        Row(
            modifier = Modifier.padding(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(
                Icons.Outlined.WarningAmber,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onErrorContainer,
            )
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    text = "For immediate or urgent help",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                )
                Text(
                    text = text,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                )
            }
        }
    }
}

@Composable
private fun ConversationList(
    state: PatientMessagingState.Ready,
    onThreadSelected: (String) -> Unit,
    onCreateThread: (topicCode: String, message: String) -> Unit,
) {
    // Message drafts deliberately remain in volatile composition memory; they
    // are never put into SavedState, preferences, a database, or an outbox.
    var composing by remember { mutableStateOf(false) }
    var selectedTopicCode by remember { mutableStateOf<String?>(null) }
    var draft by remember { mutableStateOf("") }
    val busy = state.operation is PatientMessagingOperation.Working

    LaunchedEffect(state.operation) {
        if (state.operation is PatientMessagingOperation.Notice) {
            draft = ""
            composing = false
            selectedTopicCode = null
        }
    }

    if (state.canWrite && !composing) {
        Button(
            onClick = { composing = true },
            enabled = !busy && state.topics.isNotEmpty(),
        ) {
            Text("Start a non-urgent message")
        }
    }

    if (composing) {
        NewThreadComposer(
            topics = state.topics,
            selectedTopicCode = selectedTopicCode,
            draft = draft,
            busy = busy,
            onTopicSelected = { selectedTopicCode = it },
            onDraftChanged = { draft = it.take(MAX_MESSAGE_LENGTH) },
            onCancel = {
                draft = ""
                selectedTopicCode = null
                composing = false
            },
            onSend = {
                selectedTopicCode?.let { topic -> onCreateThread(topic, draft) }
            },
        )
    }

    HorizontalDivider()
    Text(
        text = "Your conversations",
        style = MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.Bold,
        modifier = Modifier.semantics { heading() },
    )
    if (state.threads.isEmpty()) {
        Text(
            text = "You do not have any care-team conversations for this stay yet.",
            style = MaterialTheme.typography.bodyMedium,
        )
    }
    state.threads.forEach { thread ->
        OutlinedButton(
            onClick = { onThreadSelected(thread.threadUuid) },
            enabled = !busy,
            modifier = Modifier
                .fillMaxWidth()
                .testTag("message-thread-${thread.threadUuid}"),
        ) {
            Column(
                modifier = Modifier.fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(
                    text = thread.topic.label,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    text = thread.patientVisibleState(),
                    style = MaterialTheme.typography.bodySmall,
                )
                Text(
                    text = thread.expectedResponseWindow,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
        }
    }
}

@Composable
private fun NewThreadComposer(
    topics: List<PatientMessageTopic>,
    selectedTopicCode: String?,
    draft: String,
    busy: Boolean,
    onTopicSelected: (String) -> Unit,
    onDraftChanged: (String) -> Unit,
    onCancel: () -> Unit,
    onSend: () -> Unit,
) {
    Text(
        text = "Choose what you need help with",
        style = MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.Bold,
        modifier = Modifier.semantics { heading() },
    )
    topics.forEach { topic ->
        val selected = selectedTopicCode == topic.code
        OutlinedButton(
            onClick = { onTopicSelected(topic.code) },
            enabled = !busy,
            modifier = Modifier
                .fillMaxWidth()
                .testTag("message-topic-${topic.code}"),
        ) {
            Column(
                modifier = Modifier.fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(
                    text = if (selected) "Selected: ${topic.label}" else topic.label,
                    fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium,
                )
                Text(topic.description, style = MaterialTheme.typography.bodySmall)
                Text(
                    text = "Expected response: ${topic.expectedResponseWindow}",
                    style = MaterialTheme.typography.bodySmall,
                )
            }
        }
    }
    OutlinedTextField(
        value = draft,
        onValueChange = onDraftChanged,
        modifier = Modifier
            .fillMaxWidth()
            .testTag("new-message-input"),
        label = { Text("Non-urgent message") },
        supportingText = { Text("${draft.length} of $MAX_MESSAGE_LENGTH characters") },
        minLines = 4,
        enabled = !busy,
    )
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(
            onClick = onSend,
            enabled = !busy && selectedTopicCode != null && draft.isNotBlank(),
        ) {
            Text("Send message")
        }
        TextButton(onClick = onCancel, enabled = !busy) { Text("Cancel") }
    }
}

@Composable
private fun ThreadConversation(
    thread: PatientMessageThread,
    canWrite: Boolean,
    busy: Boolean,
    operation: PatientMessagingOperation,
    onLeaveThread: () -> Unit,
    onSendMessage: (String) -> Unit,
    onAmendMessage: (String, PatientMessageAmendmentAction, String?) -> Unit,
    onCloseThread: () -> Unit,
) {
    var reply by remember(thread.threadUuid) { mutableStateOf("") }
    var confirmingClose by remember(thread.threadUuid) { mutableStateOf(false) }
    var correctionTarget by remember(thread.threadUuid) { mutableStateOf<net.acumenus.hummingbird.patient.data.PatientThreadMessage?>(null) }
    var correctionDraft by remember(thread.threadUuid) { mutableStateOf("") }
    var withdrawalTarget by remember(thread.threadUuid) { mutableStateOf<net.acumenus.hummingbird.patient.data.PatientThreadMessage?>(null) }
    val isUnsharedRoundsQuestion = thread.topic.code == "rounds_question" &&
        thread.messages.none { it.messageKind == "system_status" }

    LaunchedEffect(operation) {
        if (operation is PatientMessagingOperation.Notice) {
            reply = ""
            correctionTarget = null
            correctionDraft = ""
            withdrawalTarget = null
        }
    }

    TextButton(onClick = onLeaveThread, enabled = !busy) { Text("Back to conversations") }
    Text(
        text = thread.topic.label,
        style = MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.Bold,
        modifier = Modifier.semantics { heading() },
    )
    Text(thread.patientVisibleState(), style = MaterialTheme.typography.bodyMedium)
    Text(
        text = "Expected response: ${thread.expectedResponseWindow}",
        style = MaterialTheme.typography.bodySmall,
    )
    HorizontalDivider()

    if (thread.messages.isEmpty()) {
        Text("No patient-visible messages are available in this conversation.")
    }
    thread.messages.forEach { message ->
        val canAmend = canWrite && thread.status == "open" && !busy &&
            message.senderDisplayRole == "You" && message.messageKind == "message" &&
            thread.messages.none {
                it.relatesToMessageUuid == message.messageUuid &&
                    it.messageKind in setOf("correction", "retraction")
            }
        Card(
            colors = CardDefaults.cardColors(
                containerColor = if (message.senderDisplayRole == "You") {
                    MaterialTheme.colorScheme.primaryContainer
                } else {
                    MaterialTheme.colorScheme.secondaryContainer
                },
            ),
        ) {
            Column(
                modifier = Modifier.padding(12.dp),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(message.senderDisplayRole, fontWeight = FontWeight.Bold)
                Text(
                    message.body ?: if (message.messageKind == "retraction") {
                        "This message was withdrawn. The earlier message remains in this conversation."
                    } else {
                        "No patient-visible text is available."
                    },
                )
                Text(
                    text = message.deliveryState.patientVisibleDeliveryState(),
                    style = MaterialTheme.typography.bodySmall,
                )
                if (canAmend) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        TextButton(
                            onClick = {
                                correctionTarget = message
                                correctionDraft = message.body.orEmpty()
                            },
                            modifier = Modifier.testTag("correct-message-${message.messageUuid}"),
                        ) { Text("Correct message") }
                        TextButton(
                            onClick = { withdrawalTarget = message },
                            modifier = Modifier.testTag("withdraw-message-${message.messageUuid}"),
                        ) { Text("Withdraw message") }
                    }
                    Text(
                        "A correction or withdrawal adds a new record. It does not erase this message from the conversation history.",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        }
    }

    correctionTarget?.let { target ->
        Text("Correct your message", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
        Text(
            "Your correction is sent as a new message. The earlier message stays in the conversation history.",
            style = MaterialTheme.typography.bodySmall,
        )
        OutlinedTextField(
            value = correctionDraft,
            onValueChange = { correctionDraft = it.take(MAX_MESSAGE_LENGTH) },
            modifier = Modifier
                .fillMaxWidth()
                .testTag("message-correction-input-${target.messageUuid}"),
            label = { Text("Your corrected non-urgent message") },
            supportingText = { Text("${correctionDraft.length} of $MAX_MESSAGE_LENGTH characters") },
            minLines = 3,
            enabled = !busy,
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(
                onClick = {
                    onAmendMessage(
                        target.messageUuid,
                        PatientMessageAmendmentAction.Correction,
                        correctionDraft,
                    )
                },
                enabled = !busy && correctionDraft.isNotBlank(),
                modifier = Modifier.testTag("message-correction-send-${target.messageUuid}"),
            ) { Text("Send correction") }
            TextButton(onClick = {
                correctionTarget = null
                correctionDraft = ""
            }, enabled = !busy) { Text("Cancel") }
        }
    }

    withdrawalTarget?.let { target ->
        Text(
            "Withdraw this message? This sends a withdrawal to your care team. It does not erase the earlier message from the conversation history.",
            style = MaterialTheme.typography.bodyMedium,
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(
                onClick = {
                    onAmendMessage(target.messageUuid, PatientMessageAmendmentAction.Retraction, null)
                },
                enabled = !busy,
                modifier = Modifier.testTag("message-withdraw-confirm-${target.messageUuid}"),
            ) { Text("Confirm withdrawal") }
            TextButton(onClick = { withdrawalTarget = null }, enabled = !busy) { Text("Keep message") }
        }
    }

    if (thread.status == "open" && canWrite) {
        OutlinedTextField(
            value = reply,
            onValueChange = { reply = it.take(MAX_MESSAGE_LENGTH) },
            modifier = Modifier
                .fillMaxWidth()
                .testTag("message-reply-input"),
            label = { Text("Non-urgent reply") },
            supportingText = { Text("${reply.length} of $MAX_MESSAGE_LENGTH characters") },
            minLines = 3,
            enabled = !busy,
        )
        Button(
            onClick = { onSendMessage(reply) },
            enabled = !busy && reply.isNotBlank(),
        ) {
            Text("Send reply")
        }
        if (confirmingClose) {
            Text(
                text = if (isUnsharedRoundsQuestion) {
                    "Withdraw this question? It will not be shared for a care-team round if it has not already been shared. The conversation history is kept."
                } else {
                    "Close this conversation? You will not be able to add another reply."
                },
                style = MaterialTheme.typography.bodyMedium,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = onCloseThread, enabled = !busy) {
                    Text(if (isUnsharedRoundsQuestion) "Confirm withdrawal" else "Confirm close")
                }
                TextButton(
                    onClick = { confirmingClose = false },
                    enabled = !busy,
                ) {
                    Text("Keep open")
                }
            }
        } else {
            TextButton(
                onClick = { confirmingClose = true },
                enabled = !busy,
            ) {
                Text(if (isUnsharedRoundsQuestion) "Withdraw this question" else "Close conversation")
            }
        }
    }
}

@Composable
private fun MessagingOperationStatus(operation: PatientMessagingOperation) {
    when (operation) {
        PatientMessagingOperation.Idle -> Unit
        is PatientMessagingOperation.Working -> StatusText(operation.message)
        is PatientMessagingOperation.Notice -> StatusText(operation.message)
        is PatientMessagingOperation.Failure -> StatusText(operation.message, error = true)
    }
}

@Composable
private fun StatusText(message: String, error: Boolean = false) {
    Text(
        text = message,
        style = MaterialTheme.typography.bodyMedium,
        color = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
        modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite },
    )
}

private fun PatientMessageThread.patientVisibleState(): String = when (status) {
    "closed" -> "Closed"
    else -> when (ownershipState) {
        "awaiting_team" -> "Sent to your care team"
        "team_acknowledged" -> "Your care team acknowledged this conversation"
        "assigned" -> "Your care team is reviewing this conversation"
        else -> "Open with your care team"
    }
}

private fun String.patientVisibleDeliveryState(): String = when (this) {
    "sent", "server_accepted" -> "Sent"
    "delivered" -> "Delivered"
    "acknowledged", "read" -> "Acknowledged by care team"
    else -> "Status available in this conversation"
}

private const val MAX_MESSAGE_LENGTH = 2_000
