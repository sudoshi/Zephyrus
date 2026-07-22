package net.acumenus.hummingbird.ui.communications

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive

internal const val PATIENT_COMMUNICATIONS_POLL_INTERVAL_MILLIS = 20_000L

internal fun shouldPollPatientCommunications(
    canView: Boolean,
    foreground: Boolean,
    locked: Boolean,
    surfaceVisible: Boolean,
): Boolean = canView && foreground && !locked && surfaceVisible

/**
 * Owns the single foreground polling loop shared by the inbox and conversation detail.
 * The loop awaits every read before starting its interval, so a slow request can never
 * overlap the next poll. The synchronous gate is checked at the read boundary as well
 * as through the Compose effect key: an ON_STOP or lock event must not allow a timer
 * tick already waiting in the channel to start one last background read. Updated state
 * lets navigation switch the observed work item without tearing down an in-flight
 * authorization read.
 */
@Composable
internal fun PatientCommunicationsPollingEffect(
    active: Boolean,
    bearer: String,
    workItemUuid: String?,
    foregroundEpoch: Int,
    pollIntervalMillis: Long = PATIENT_COMMUNICATIONS_POLL_INTERVAL_MILLIS,
    onPoll: suspend (bearer: String, workItemUuid: String?) -> Unit,
    awaitNextPoll: suspend (Long) -> Unit = { delay(it) },
    isPollingStillAllowed: () -> Boolean = { active },
) {
    val currentWorkItemUuid by rememberUpdatedState(workItemUuid)
    val currentPoll by rememberUpdatedState(onPoll)
    val currentAwaitNextPoll by rememberUpdatedState(awaitNextPoll)
    val currentIsPollingStillAllowed by rememberUpdatedState(isPollingStillAllowed)

    LaunchedEffect(active, bearer, foregroundEpoch, pollIntervalMillis) {
        if (!active || bearer.isBlank() || !currentIsPollingStillAllowed()) return@LaunchedEffect

        while (currentCoroutineContext().isActive) {
            if (!currentIsPollingStillAllowed()) break
            currentPoll(bearer, currentWorkItemUuid)
            if (!currentCoroutineContext().isActive) break
            currentAwaitNextPoll(pollIntervalMillis)
            if (!currentIsPollingStillAllowed()) break
        }
    }
}
