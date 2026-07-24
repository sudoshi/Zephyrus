package net.acumenus.hummingbird.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.DevicesOther
import androidx.compose.material.icons.outlined.Shield
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.StaffSession
import net.acumenus.hummingbird.data.StaffSessionRevocation
import net.acumenus.hummingbird.ui.theme.Z
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

internal sealed interface StaffSessionsState {
    data object Loading : StaffSessionsState
    data class Ready(val sessions: List<StaffSession>) : StaffSessionsState
    data class Failure(val message: String) : StaffSessionsState
}

internal fun shouldClearLocalStaffSession(
    result: StaffSessionRevocation? = null,
    statusCode: Int? = null,
): Boolean = result?.current == true || statusCode in setOf(401, 403)

internal fun shouldRefetchAfterStaffSessionMutation(statusCode: Int?): Boolean =
    statusCode == 401

@Composable
internal fun StaffSessionsScreen(
    auth: AuthViewModel,
    onBack: () -> Unit,
) {
    val api = remember { ApiClient() }
    val scope = rememberCoroutineScope()
    var state by remember { mutableStateOf<StaffSessionsState>(StaffSessionsState.Loading) }
    var pendingRevocation by remember { mutableStateOf<StaffSession?>(null) }
    var workingSessionUuid by remember { mutableStateOf<String?>(null) }
    var reloadEpoch by remember { mutableIntStateOf(0) }
    val bearer = auth.accessToken

    fun clearTerminalSession() {
        pendingRevocation = null
        workingSessionUuid = null
        auth.completeCurrentSessionRevocation()
    }

    LaunchedEffect(bearer, reloadEpoch) {
        if (bearer.isNullOrBlank()) {
            clearTerminalSession()
            return@LaunchedEffect
        }

        state = StaffSessionsState.Loading
        state = try {
            StaffSessionsState.Ready(api.staffSessions(bearer))
        } catch (error: ApiException) {
            if (shouldClearLocalStaffSession(statusCode = error.statusCode)) {
                clearTerminalSession()
                return@LaunchedEffect
            }
            StaffSessionsState.Failure(error.message ?: "Signed-in devices are unavailable.")
        } catch (error: Exception) {
            StaffSessionsState.Failure(error.message ?: "Signed-in devices are unavailable.")
        }
    }

    StaffSessionsContent(
        state = state,
        pendingRevocation = pendingRevocation,
        workingSessionUuid = workingSessionUuid,
        onBack = onBack,
        onRetry = { reloadEpoch += 1 },
        onSelectForRevocation = { pendingRevocation = it },
        onCancelRevocation = { pendingRevocation = null },
        onConfirmRevocation = {
            val selected = pendingRevocation ?: return@StaffSessionsContent
            pendingRevocation = null
            workingSessionUuid = selected.sessionUuid
            scope.launch {
                val activeBearer = auth.accessToken
                if (activeBearer.isNullOrBlank()) {
                    clearTerminalSession()
                    return@launch
                }
                try {
                    val result = api.revokeStaffSession(activeBearer, selected.sessionUuid)
                    if (shouldClearLocalStaffSession(result = result)) {
                        clearTerminalSession()
                    } else {
                        workingSessionUuid = null
                        reloadEpoch += 1
                    }
                } catch (error: ApiException) {
                    when {
                        shouldRefetchAfterStaffSessionMutation(error.statusCode) -> {
                            // Do not replay DELETE. The safe GET may rotate once;
                            // the user must then review and confirm explicitly.
                            workingSessionUuid = null
                            reloadEpoch += 1
                        }
                        error.statusCode == 403 ->
                            clearTerminalSession()
                        error.statusCode == 404 -> {
                            workingSessionUuid = null
                            reloadEpoch += 1
                        }
                        else -> {
                            workingSessionUuid = null
                            state = StaffSessionsState.Failure(
                                error.message ?: "The selected session could not be revoked.",
                            )
                        }
                    }
                } catch (error: Exception) {
                    workingSessionUuid = null
                    state = StaffSessionsState.Failure(
                        error.message ?: "The selected session could not be revoked.",
                    )
                }
            }
        },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun StaffSessionsContent(
    state: StaffSessionsState,
    pendingRevocation: StaffSession?,
    workingSessionUuid: String?,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onSelectForRevocation: (StaffSession) -> Unit,
    onCancelRevocation: () -> Unit,
    onConfirmRevocation: () -> Unit,
) {
    BackHandler(onBack = onBack)
    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Signed-in devices", fontWeight = FontWeight.SemiBold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
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
        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(inner)
                .testTag("staff-sessions-list"),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Z.surface),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Row(
                            horizontalArrangement = Arrangement.spacedBy(10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Outlined.Shield, contentDescription = null, tint = Z.primary)
                            Text(
                                "Your Hummingbird sessions",
                                color = Z.ink,
                                fontWeight = FontWeight.SemiBold,
                                fontSize = 17.sp,
                                modifier = Modifier.semantics { heading() },
                            )
                        }
                        Text(
                            "Review devices that can use your staff account. Revoke anything you do not recognize.",
                            color = Z.inkMuted,
                            fontSize = 13.sp,
                        )
                        Text(
                            "For privacy, this screen never shows network addresses, credentials, or clinical information.",
                            color = Z.inkMuted,
                            fontSize = 12.sp,
                        )
                    }
                }
            }

            when (state) {
                StaffSessionsState.Loading -> item {
                    StaffSessionStatusCard(
                        message = "Checking signed-in devices…",
                        loading = true,
                    )
                }

                is StaffSessionsState.Failure -> item {
                    StaffSessionStatusCard(
                        message = state.message,
                        actionLabel = "Try again",
                        onAction = onRetry,
                    )
                }

                is StaffSessionsState.Ready -> {
                    if (state.sessions.isEmpty()) {
                        item {
                            StaffSessionStatusCard(
                                message = "No active Hummingbird sessions were found.",
                            )
                        }
                    } else {
                        items(state.sessions, key = StaffSession::sessionUuid) { session ->
                            StaffSessionCard(
                                session = session,
                                working = workingSessionUuid != null,
                                onSelectForRevocation = onSelectForRevocation,
                            )
                        }
                    }
                }
            }
        }
    }

    if (pendingRevocation != null) {
        AlertDialog(
            onDismissRequest = onCancelRevocation,
            title = {
                Text(
                    if (pendingRevocation.current) {
                        "Sign out this device?"
                    } else {
                        "Revoke this session?"
                    },
                )
            },
            text = {
                Text(
                    if (pendingRevocation.current) {
                        "Hummingbird will erase this device's protected credentials and cached operational data."
                    } else {
                        "That device will need to sign in again. Other devices stay signed in."
                    },
                )
            },
            confirmButton = {
                Button(
                    onClick = onConfirmRevocation,
                    modifier = Modifier.testTag("staff-session-confirm-revocation"),
                ) {
                    Text(if (pendingRevocation.current) "Sign out this device" else "Revoke session")
                }
            },
            dismissButton = {
                TextButton(onClick = onCancelRevocation) { Text("Cancel") }
            },
        )
    }
}

@Composable
private fun StaffSessionCard(
    session: StaffSession,
    working: Boolean,
    onSelectForRevocation: (StaffSession) -> Unit,
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .testTag("staff-session-${session.sessionUuid}"),
        colors = CardDefaults.cardColors(
            containerColor = if (session.current) {
                Z.primary.copy(alpha = 0.12f)
            } else {
                Z.surface
            },
        ),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Outlined.DevicesOther, contentDescription = null, tint = Z.primary)
                Column(Modifier.weight(1f)) {
                    Text(
                        session.device.name?.takeIf(String::isNotBlank)
                            ?: session.platformDisplayName(),
                        color = Z.ink,
                        fontWeight = FontWeight.SemiBold,
                        fontSize = 16.sp,
                    )
                    Text(
                        if (session.current) "This device" else session.platformDisplayName(),
                        color = if (session.current) Z.statusSuccess else Z.inkMuted,
                        fontSize = 12.sp,
                        fontWeight = if (session.current) FontWeight.SemiBold else FontWeight.Normal,
                    )
                }
            }
            StaffSessionDetail("Last used", formatStaffSessionTime(session.lastSeenAt))
            StaffSessionDetail("Signed in", formatStaffSessionTime(session.createdAt))
            StaffSessionDetail("Session expires", formatStaffSessionTime(session.expiresAt))
            session.device.appVersion?.takeIf(String::isNotBlank)?.let {
                StaffSessionDetail("App version", it)
            }
            session.device.osVersion?.takeIf(String::isNotBlank)?.let {
                StaffSessionDetail("System", it)
            }
            OutlinedButton(
                onClick = { onSelectForRevocation(session) },
                enabled = !working,
                modifier = Modifier
                    .fillMaxWidth()
                    .heightIn(min = 48.dp)
                    .testTag("staff-session-revoke-${session.sessionUuid}"),
            ) {
                Text(if (session.current) "Sign out this device" else "Revoke session")
            }
        }
    }
}

@Composable
private fun StaffSessionDetail(label: String, value: String) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Text(label, color = Z.inkMuted, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(
            value,
            color = Z.ink,
            fontSize = 13.sp,
            fontWeight = FontWeight.Medium,
            textAlign = TextAlign.End,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun StaffSessionStatusCard(
    message: String,
    loading: Boolean = false,
    actionLabel: String? = null,
    onAction: () -> Unit = {},
) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Z.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            horizontalAlignment = Alignment.Start,
        ) {
            if (loading) {
                CircularProgressIndicator(color = Z.primary)
            }
            Text(message, color = Z.inkMuted, fontSize = 13.sp)
            if (actionLabel != null) {
                OutlinedButton(onClick = onAction) { Text(actionLabel) }
            }
        }
    }
}

private fun StaffSession.platformDisplayName(): String = when (device.platform) {
    "ios" -> "Apple device"
    "android" -> "Android device"
    else -> "Hummingbird device"
}

private fun formatStaffSessionTime(raw: String): String = runCatching {
    OffsetDateTime.parse(raw)
        .format(DateTimeFormatter.ofPattern("MMM d, yyyy 'at' h:mm a", Locale.getDefault()))
}.getOrDefault("Time unavailable")
