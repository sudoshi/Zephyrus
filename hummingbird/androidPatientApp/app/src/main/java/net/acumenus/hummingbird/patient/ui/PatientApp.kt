package net.acumenus.hummingbird.patient.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.patient.PatientAppViewModel
import net.acumenus.hummingbird.patient.PatientDeviceSessionsState
import net.acumenus.hummingbird.patient.PatientPreferencesState
import net.acumenus.hummingbird.patient.PatientSessionState
import net.acumenus.hummingbird.patient.SyntheticReferencePatientScenario

@Composable
internal fun PatientApp(
    viewModel: PatientAppViewModel,
    privacyCovered: Boolean,
) {
    val state = viewModel.state
    val presentation = LocalPatientPresentationAccessibility.current

    LaunchedEffect(privacyCovered) {
        if (privacyCovered) viewModel.onAppBackgrounded()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .testTag(presentation.accessibilityTag),
    ) {
        when (val session = state.session) {
            is PatientSessionState.SignedOut -> PatientAuthenticationScreen(
                state = session,
                networkEnabled = viewModel.networkEnabled,
                onAuthModeSelected = viewModel::selectAuthMode,
                onSignIn = viewModel::submitSignIn,
                onEnroll = viewModel::submitEnrollment,
            )

            is PatientSessionState.Ready -> {
                if (state.preferences !is PatientPreferencesState.Hidden) {
                    PatientPreferencesScreen(
                        state = state.preferences,
                        onDismiss = viewModel::dismissPreferences,
                        onSave = viewModel::savePreferences,
                    )
                } else if (state.deviceSessions is PatientDeviceSessionsState.Hidden) {
                    PatientExperienceScreen(
                        snapshot = session.snapshot,
                        syntheticNotice = if (session.synthetic) {
                            SyntheticReferencePatientScenario.noticeOrNull()
                        } else {
                            null
                        },
                        selectedDestination = state.destination,
                        messagingState = state.messaging,
                        onDestinationSelected = viewModel::selectDestination,
                        onMessagesRefresh = viewModel::refreshMessages,
                        onMessageThreadSelected = viewModel::selectMessageThread,
                        onLeaveMessageThread = viewModel::leaveMessageThread,
                        onCreateMessageThread = viewModel::createMessageThread,
                        onRequestEducationClarification = viewModel::requestEducationClarification,
                        onSendMessage = viewModel::sendMessage,
                        onAmendMessage = viewModel::amendMessage,
                        onCloseMessageThread = viewModel::closeMessageThread,
                        onManagePreferences = viewModel::openPreferences,
                        onManageDevices = viewModel::openDeviceSessions,
                        onSignOut = viewModel::signOut,
                    )
                } else {
                    PatientSessionManagementScreen(
                        state = state.deviceSessions,
                        onDismiss = viewModel::dismissDeviceSessions,
                        onRetry = viewModel::openDeviceSessions,
                        onSelectForRevocation = viewModel::selectDeviceSessionForRevocation,
                        onCancelRevocation = viewModel::cancelDeviceSessionRevocation,
                        onConfirmRevocation = viewModel::confirmDeviceSessionRevocation,
                    )
                }
            }

            is PatientSessionState.Loading -> PatientLoadingScreen(session.message)

            is PatientSessionState.Empty -> PatientEmptyScreen(
                displayName = session.patientDisplayName,
                title = "No active hospital stay",
                message = session.message,
                retryLabel = "Check again",
                scene = PatientScene.EMPTY,
                onRetry = viewModel::retryCurrentCareAccess,
                onExit = viewModel::signOut,
            )

            is PatientSessionState.AccessVerificationUnavailable -> PatientEmptyScreen(
                displayName = session.patientDisplayName,
                title = "We can’t confirm your care access",
                message = session.message,
                retryLabel = "Try again",
                scene = PatientScene.ERROR,
                onRetry = viewModel::retryCurrentCareAccess,
                onExit = viewModel::signOut,
            )
        }

        if (privacyCovered) {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .background(MaterialTheme.colorScheme.surface)
                    .clearAndSetSemantics {
                        contentDescription = "Hummingbird Patient is hidden for privacy"
                    },
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    text = "Hummingbird Patient",
                    style = MaterialTheme.typography.titleLarge,
                )
            }
        }
    }
}

@Composable
private fun PatientLoadingScreen(message: String) {
    PatientScenicBackground(scene = PatientScene.LOADING) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(32.dp)
                .semantics { liveRegion = LiveRegionMode.Polite },
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            CircularProgressIndicator()
            Text(
                text = message,
                modifier = Modifier.padding(top = 20.dp),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "Your patient information stays hidden while this secure request completes.",
                modifier = Modifier.padding(top = 8.dp),
                style = MaterialTheme.typography.bodyMedium,
            )
        }
    }
}

@Composable
private fun PatientEmptyScreen(
    displayName: String,
    title: String,
    message: String,
    retryLabel: String,
    scene: PatientScene,
    onRetry: () -> Unit,
    onExit: () -> Unit,
) {
    PatientScenicBackground(scene = scene) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(32.dp),
            verticalArrangement = Arrangement.Center,
        ) {
            Text(
                text = "Hello, $displayName",
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.semantics { heading() },
            )
            Text(
                text = title,
                modifier = Modifier.padding(top = 8.dp),
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = message,
                modifier = Modifier.padding(top = 12.dp),
                style = MaterialTheme.typography.bodyLarge,
            )
            Text(
                text = "Need urgent help? Use your bedside call button or speak with staff in person.",
                modifier = Modifier.padding(top = 12.dp),
                style = MaterialTheme.typography.bodyMedium,
            )
            Button(
                onClick = onRetry,
                modifier = Modifier
                    .padding(top = 24.dp)
                    .patientMinimumInteractiveTarget()
                    .testTag("patient-care-access-retry"),
            ) {
                Text(retryLabel)
            }
            Button(
                onClick = onExit,
                modifier = Modifier
                    .padding(top = 12.dp)
                    .patientMinimumInteractiveTarget()
                    .testTag("patient-care-access-exit"),
            ) {
                Text("Exit securely")
            }
        }
    }
}
