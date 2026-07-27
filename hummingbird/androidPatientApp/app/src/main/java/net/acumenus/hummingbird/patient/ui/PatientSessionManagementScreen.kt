package net.acumenus.hummingbird.patient.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.DevicesOther
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
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.patient.PatientDeviceSessionOperation
import net.acumenus.hummingbird.patient.PatientDeviceSessionsState
import net.acumenus.hummingbird.patient.data.PatientDeviceSession
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PatientSessionManagementScreen(
    state: PatientDeviceSessionsState,
    onDismiss: () -> Unit,
    onRetry: () -> Unit,
    onSelectForRevocation: (String) -> Unit,
    onCancelRevocation: () -> Unit,
    onConfirmRevocation: () -> Unit,
) {
    BackHandler(onBack = onDismiss)
    PatientScenicBackground(scene = PatientScene.LOADING_OR_EMPTY) {
        Scaffold(
            containerColor = androidx.compose.ui.graphics.Color.Transparent,
            topBar = {
                TopAppBar(
                    title = { Text("Manage devices") },
                    navigationIcon = {
                        IconButton(onClick = onDismiss) {
                            Icon(
                                Icons.AutoMirrored.Outlined.ArrowBack,
                                contentDescription = "Back to Hummingbird",
                            )
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = MaterialTheme.colorScheme.surface.copy(alpha = 0.94f),
                    ),
                )
            },
        ) { contentPadding ->
            LazyColumn(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(contentPadding)
                    .testTag("device-sessions-list"),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(
                            text = "Signed-in devices",
                            style = MaterialTheme.typography.headlineSmall,
                            fontWeight = FontWeight.Bold,
                            modifier = Modifier.semantics { heading() },
                        )
                        Text(
                            text = "Review devices that can currently open your Hummingbird Patient account. Device details stay on this screen only.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }

                when (state) {
                    PatientDeviceSessionsState.Hidden -> Unit
                    is PatientDeviceSessionsState.Loading -> item {
                        CalmStatusCard(message = state.message, loading = true)
                    }
                    is PatientDeviceSessionsState.Unavailable -> item {
                        CalmStatusCard(
                            message = state.message,
                            actionLabel = if (state.canRetry) "Try again" else null,
                            onAction = onRetry,
                        )
                    }
                    is PatientDeviceSessionsState.Ready -> {
                        when (val operation = state.operation) {
                            PatientDeviceSessionOperation.Idle -> Unit
                            is PatientDeviceSessionOperation.Working -> item {
                                CalmStatusCard(
                                    message = "Signing out the selected device securely",
                                    loading = true,
                                )
                            }
                            is PatientDeviceSessionOperation.Notice -> item {
                                CalmStatusCard(message = operation.message)
                            }
                            is PatientDeviceSessionOperation.Failure -> item {
                                CalmStatusCard(
                                    message = operation.message,
                                    actionLabel = "Refresh device list",
                                    onAction = onRetry,
                                    warning = true,
                                )
                            }
                        }

                        if (state.sessions.isEmpty()) {
                            item {
                                CalmStatusCard(
                                    message = "No active device sessions are available to show right now.",
                                )
                            }
                        } else {
                            items(state.sessions, key = PatientDeviceSession::sessionUuid) { session ->
                                PatientDeviceSessionCard(
                                    session = session,
                                    working = state.operation is PatientDeviceSessionOperation.Working,
                                    onSelectForRevocation = onSelectForRevocation,
                                )
                            }
                        }
                    }
                }
            }
        }
    }

    val selected = (state as? PatientDeviceSessionsState.Ready)?.selectedForRevocation
    if (selected != null) {
        PatientDeviceSessionConfirmation(
            session = selected,
            onDismiss = onCancelRevocation,
            onConfirm = onConfirmRevocation,
        )
    }
}

@Composable
private fun PatientDeviceSessionCard(
    session: PatientDeviceSession,
    working: Boolean,
    onSelectForRevocation: (String) -> Unit,
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .testTag("device-session-${session.sessionUuid}"),
        colors = CardDefaults.cardColors(
            containerColor = if (session.current) {
                MaterialTheme.colorScheme.secondaryContainer
            } else {
                MaterialTheme.colorScheme.surface
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
                Icon(Icons.Outlined.DevicesOther, contentDescription = null)
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = session.deviceLabel(),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                    Text(
                        text = if (session.current) "Current device" else "Other signed-in device",
                        style = MaterialTheme.typography.labelLarge,
                        color = if (session.current) {
                            MaterialTheme.colorScheme.primary
                        } else {
                            MaterialTheme.colorScheme.onSurfaceVariant
                        },
                    )
                }
                Surface(
                    color = MaterialTheme.colorScheme.primaryContainer,
                    shape = MaterialTheme.shapes.small,
                ) {
                    Text(
                        text = "Active",
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                        style = MaterialTheme.typography.labelMedium,
                    )
                }
            }
            Text(session.deviceDetails(), style = MaterialTheme.typography.bodyMedium)
            Text(
                "Authentication: ${session.authMethod.patientAuthLabel()}",
                style = MaterialTheme.typography.bodySmall,
            )
            Text(
                "Last seen: ${formatDeviceTime(session.lastSeenAt)}",
                style = MaterialTheme.typography.bodySmall,
            )
            Text(
                "Expires: ${formatDeviceTime(session.expiresAt)}",
                style = MaterialTheme.typography.bodySmall,
            )
            Text(
                "Signed in: ${formatDeviceTime(session.createdAt)}",
                style = MaterialTheme.typography.bodySmall,
            )
            OutlinedButton(
                onClick = { onSelectForRevocation(session.sessionUuid) },
                enabled = !working,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(if (session.current) "Sign out this device" else "Sign out device")
            }
        }
    }
}

@Composable
private fun PatientDeviceSessionConfirmation(
    session: PatientDeviceSession,
    onDismiss: () -> Unit,
    onConfirm: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Text(if (session.current) "Sign out this device?" else "Sign out other device?")
        },
        text = {
            Text(
                if (session.current) {
                    "You will return to the Hummingbird Patient sign-in screen on this device. Other signed-in devices are not changed."
                } else {
                    "${session.deviceLabel()} will need to sign in again. This current device will stay signed in."
                },
            )
        },
        confirmButton = {
            Button(onClick = onConfirm) {
                Text(if (session.current) "Sign out this device" else "Sign out other device")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("Keep device signed in") }
        },
    )
}

@Composable
private fun CalmStatusCard(
    message: String,
    loading: Boolean = false,
    actionLabel: String? = null,
    onAction: () -> Unit = {},
    warning: Boolean = false,
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .semantics { liveRegion = LiveRegionMode.Polite },
        colors = CardDefaults.cardColors(
            containerColor = if (warning) {
                MaterialTheme.colorScheme.errorContainer
            } else {
                MaterialTheme.colorScheme.surfaceVariant
            },
        ),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (loading) CircularProgressIndicator()
            Text(message, style = MaterialTheme.typography.bodyLarge)
            if (actionLabel != null) {
                OutlinedButton(onClick = onAction) { Text(actionLabel) }
            }
        }
    }
}

private fun PatientDeviceSession.deviceLabel(): String =
    device.name.safeBoundedLabel(MAX_DEVICE_NAME_LENGTH)
        ?: if (current) {
            "This device"
        } else {
            device.platform.patientPlatformLabel() ?: "Unknown device"
        }

private fun PatientDeviceSession.deviceDetails(): String {
    val details = listOfNotNull(
        device.platform.patientPlatformLabel(),
        device.appVersion.safeBoundedLabel(MAX_VERSION_LENGTH)?.let { "App $it" },
        device.osVersion.safeBoundedLabel(MAX_VERSION_LENGTH)?.let { "OS $it" },
    )
    return details.joinToString(" • ").ifBlank { "Device details are not available." }
}

private fun String?.patientAuthLabel(): String = when (this) {
    "password" -> "Password"
    "enrollment" -> "Invitation verification"
    "federated" -> "Organization sign-in"
    "passkey" -> "Passkey"
    "recovery" -> "Account recovery"
    else -> "Not available"
}

private fun String?.patientPlatformLabel(): String? = when (this) {
    "android" -> "Android"
    "ios" -> "iPhone or iPad"
    "web" -> "Web browser"
    else -> null
}

private fun String?.safeBoundedLabel(maxLength: Int): String? =
    this?.trim()
        ?.takeIf { value ->
            value.isNotEmpty() &&
                value.length <= maxLength &&
                value.none(Char::isISOControl)
        }

private fun formatDeviceTime(value: String?): String {
    if (value.isNullOrBlank()) return "Not available"
    return runCatching { OffsetDateTime.parse(value).format(DEVICE_TIME_FORMAT) }
        .getOrDefault("Not available")
}

private val DEVICE_TIME_FORMAT: DateTimeFormatter =
    DateTimeFormatter.ofPattern("MMM d, h:mm a", Locale.US)

private const val MAX_DEVICE_NAME_LENGTH = 190
private const val MAX_VERSION_LENGTH = 80
