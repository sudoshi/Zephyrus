package net.acumenus.hummingbird.data

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex

internal interface PatientCommunicationsGateway {
    suspend fun inbox(bearer: String): PatientCommunicationInbox
    suspend fun thread(bearer: String, workItemUuid: String): PatientCommunicationWorkItem
    suspend fun routeCandidates(bearer: String, workItemUuid: String): PatientCommunicationRouteCandidates
    suspend fun claim(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult
    suspend fun reply(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUuid: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult
    suspend fun close(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reason: PatientCommunicationCloseReason,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult
    suspend fun release(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult
    suspend fun reassign(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult
    suspend fun reroute(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult
}

private class LivePatientCommunicationsGateway(
    private val api: ApiClient = ApiClient(),
) : PatientCommunicationsGateway {
    override suspend fun inbox(bearer: String) = api.patientCommunicationsInbox(bearer)
    override suspend fun thread(bearer: String, workItemUuid: String) =
        api.patientCommunicationThread(bearer, workItemUuid)
    override suspend fun routeCandidates(bearer: String, workItemUuid: String) =
        api.patientCommunicationRouteCandidates(bearer, workItemUuid)
    override suspend fun claim(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: String,
    ) = api.claimPatientCommunication(bearer, workItemUuid, workItemVersion, threadVersion, idempotencyKey)
    override suspend fun reply(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUuid: String,
        idempotencyKey: String,
    ) = api.replyToPatientCommunication(
        bearer,
        workItemUuid,
        workItemVersion,
        threadVersion,
        message,
        clientMessageUuid,
        idempotencyKey,
    )
    override suspend fun close(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reason: PatientCommunicationCloseReason,
        idempotencyKey: String,
    ) = api.closePatientCommunication(
        bearer,
        workItemUuid,
        workItemVersion,
        threadVersion,
        reason,
        idempotencyKey,
    )
    override suspend fun release(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: String,
    ) = api.releasePatientCommunication(
        bearer,
        workItemUuid,
        workItemVersion,
        threadVersion,
        reasonCode,
        idempotencyKey,
    )
    override suspend fun reassign(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ) = api.reassignPatientCommunication(
        bearer,
        workItemUuid,
        workItemVersion,
        threadVersion,
        targetMembershipUuid,
        reasonCode,
        idempotencyKey,
    )
    override suspend fun reroute(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ) = api.reroutePatientCommunication(
        bearer,
        workItemUuid,
        workItemVersion,
        threadVersion,
        targetPoolUuid,
        reasonCode,
        idempotencyKey,
    )
}

private sealed interface PendingPatientCommunicationMutation {
    val workItemUuid: String
    val workItemVersion: Int
    val threadVersion: Int
    val idempotencyKey: String

    data class Claim(
        override val workItemUuid: String,
        override val workItemVersion: Int,
        override val threadVersion: Int,
        override val idempotencyKey: String,
    ) : PendingPatientCommunicationMutation

    data class Reply(
        override val workItemUuid: String,
        override val workItemVersion: Int,
        override val threadVersion: Int,
        override val idempotencyKey: String,
        val clientMessageUuid: String,
        val body: String,
    ) : PendingPatientCommunicationMutation

    data class Close(
        override val workItemUuid: String,
        override val workItemVersion: Int,
        override val threadVersion: Int,
        override val idempotencyKey: String,
        val reason: PatientCommunicationCloseReason,
    ) : PendingPatientCommunicationMutation

    data class Release(
        override val workItemUuid: String,
        override val workItemVersion: Int,
        override val threadVersion: Int,
        override val idempotencyKey: String,
        val reasonCode: String,
    ) : PendingPatientCommunicationMutation

    data class Reassign(
        override val workItemUuid: String,
        override val workItemVersion: Int,
        override val threadVersion: Int,
        override val idempotencyKey: String,
        val targetMembershipUuid: String,
        val reasonCode: String,
    ) : PendingPatientCommunicationMutation

    data class Reroute(
        override val workItemUuid: String,
        override val workItemVersion: Int,
        override val threadVersion: Int,
        override val idempotencyKey: String,
        val targetPoolUuid: String,
        val reasonCode: String,
    ) : PendingPatientCommunicationMutation
}

/**
 * Memory-only state for the accountable staff messaging workflow. No message,
 * draft, command, or response is written to preferences, files, caches, or logs.
 */
class PatientCommunicationsViewModel internal constructor(
    private val api: PatientCommunicationsGateway,
    private val externalScope: CoroutineScope?,
) : ViewModel() {
    constructor() : this(LivePatientCommunicationsGateway(), null)

    private val stateScope: CoroutineScope get() = externalScope ?: viewModelScope
    private var identityId: Int? = null
    private var pendingMutation: PendingPatientCommunicationMutation? = null
    private var inboxJob: Job? = null
    private var detailJob: Job? = null
    private var routeCandidatesJob: Job? = null
    private var mutationJob: Job? = null
    private var inboxRequestGeneration = 0L
    private var detailRequestGeneration = 0L
    private val inboxRefreshMutex = Mutex()

    var inbox by mutableStateOf<List<PatientCommunicationWorkItem>>(emptyList()); private set
    var detail by mutableStateOf<PatientCommunicationWorkItem?>(null); private set
    var replyDraft by mutableStateOf(""); private set
    var routingOpen by mutableStateOf(false); private set
    var routeCandidates by mutableStateOf<PatientCommunicationRouteCandidates?>(null); private set
    var selectedRoutingAction by mutableStateOf<PatientCommunicationRoutingAction?>(null); private set
    var selectedRoutingReasonCode by mutableStateOf<String?>(null); private set
    var selectedRoutingTargetUuid by mutableStateOf<String?>(null); private set

    var inboxLoading by mutableStateOf(false); private set
    var detailLoading by mutableStateOf(false); private set
    var routeCandidatesLoading by mutableStateOf(false); private set
    var mutating by mutableStateOf(false); private set
    var inboxError by mutableStateOf<String?>(null); private set
    var detailError by mutableStateOf<String?>(null); private set
    var routeCandidatesError by mutableStateOf<String?>(null); private set
    var mutationError by mutableStateOf<String?>(null); private set
    var notice by mutableStateOf<String?>(null); private set
    var conflictNotice by mutableStateOf<String?>(null); private set
    var unavailable by mutableStateOf(false); private set
    var needsReauth by mutableStateOf(false); private set

    val canRetryMutation: Boolean get() = pendingMutation != null && !mutating
    val hasPendingMutation: Boolean get() = pendingMutation != null
    val canReviewRouting: Boolean
        get() = routeCandidates?.let { candidates ->
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates = candidates,
                action = selectedRoutingAction,
                reasonCode = selectedRoutingReasonCode,
                targetUuid = selectedRoutingTargetUuid,
            )
        } == true && !mutating

    fun resetForIdentity(userId: Int?) {
        if (identityId == userId) return
        identityId = userId
        clearSensitiveState()
    }

    fun clearSensitiveState() {
        inboxRequestGeneration += 1
        detailRequestGeneration += 1
        inboxJob?.cancel()
        detailJob?.cancel()
        routeCandidatesJob?.cancel()
        mutationJob?.cancel()
        inboxJob = null
        detailJob = null
        routeCandidatesJob = null
        mutationJob = null
        inbox = emptyList()
        detail = null
        replyDraft = ""
        clearRoutingPresentation()
        pendingMutation = null
        inboxLoading = false
        detailLoading = false
        routeCandidatesLoading = false
        mutating = false
        inboxError = null
        detailError = null
        mutationError = null
        notice = null
        conflictNotice = null
        unavailable = false
        needsReauth = false
    }

    fun loadInbox(bearer: String) {
        if (bearer.isBlank() || inboxLoading) return
        inboxJob = stateScope.launch {
            refreshInboxProjection(bearer, detail?.workItemUuid)
        }
    }

    /** One read-only cycle invoked by the lifecycle-owned foreground polling effect. */
    internal suspend fun refreshInboxForPolling(bearer: String, workItemUuid: String?) {
        refreshInboxProjection(bearer, workItemUuid)
    }

    private suspend fun refreshInboxProjection(
        bearer: String,
        selectedWorkItemUuid: String?,
    ) {
        if (bearer.isBlank() || needsReauth || inboxLoading) return
        if (!inboxRefreshMutex.tryLock()) return
        val requestGeneration = ++inboxRequestGeneration
        inboxLoading = true
        inboxError = null
        try {
            val response = api.inbox(bearer)
            currentCoroutineContext().ensureActive()
            if (requestGeneration != inboxRequestGeneration) return

            val pendingReroute = pendingMutation as? PendingPatientCommunicationMutation.Reroute
            val pendingRerouteUuid = pendingReroute?.workItemUuid
            val safeItems = response.items.filterNot { it.workItemUuid == pendingRerouteUuid }
            val displayedUuid = selectedWorkItemUuid ?: detail?.workItemUuid
            val matchingItem = displayedUuid?.let { uuid ->
                safeItems.firstOrNull { it.workItemUuid == uuid }
            }
            val displayedItemOmitted = displayedUuid != null && matchingItem == null

            inbox = safeItems
            if (displayedItemOmitted) {
                val possibleReroute = pendingReroute?.takeIf { it.workItemUuid == displayedUuid }
                if (possibleReroute != null) {
                    mutationJob?.cancel()
                    mutationJob = null
                    purgeAfterPossibleReroute(possibleReroute, cancelInboxJob = false)
                } else {
                    purgeDetailForAccessLoss(displayedUuid, cancelInboxJob = false)
                }
                return
            }

            if (pendingRerouteUuid == null) unavailable = false
            if (displayedUuid == null || matchingItem == null) return

            val displayedDetail = detail?.takeIf { it.workItemUuid == displayedUuid }
            val projectionDrifted = displayedDetail != null &&
                authoritativeInboxProjectionChanged(displayedDetail, matchingItem)
            if (projectionDrifted) {
                purgeDetailForServerTransition(displayedUuid)
            }

            // A selected row with no readable detail is also retried on the polling
            // cadence. This covers initial navigation races and transient read errors.
            if (projectionDrifted || detail == null) {
                refreshThreadProjection(bearer, displayedUuid)
            }
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (exception: ApiException) {
            if (requestGeneration == inboxRequestGeneration) {
                handleReadFailure(exception, inbox = true)
            }
        } catch (_: Exception) {
            if (requestGeneration == inboxRequestGeneration) inboxError = CONNECTION_ERROR
        } finally {
            if (requestGeneration == inboxRequestGeneration) inboxLoading = false
            inboxRefreshMutex.unlock()
        }
    }

    fun loadThread(bearer: String, workItemUuid: String) {
        if (bearer.isBlank() || detailLoading) return
        dismissRouting()
        detailError = null
        conflictNotice = null
        detailJob = stateScope.launch {
            refreshThreadProjection(bearer, workItemUuid)
        }
    }

    private suspend fun refreshThreadProjection(bearer: String, workItemUuid: String) {
        if (bearer.isBlank() || needsReauth || detailLoading) return
        val requestGeneration = ++detailRequestGeneration
        detailLoading = true
        detailError = null
        try {
            val response = api.thread(bearer, workItemUuid)
            currentCoroutineContext().ensureActive()
            if (requestGeneration != detailRequestGeneration) return
            detail = response
            unavailable = false
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (exception: ApiException) {
            if (requestGeneration == detailRequestGeneration) {
                handleReadFailure(exception, inbox = false, workItemUuid = workItemUuid)
            }
        } catch (_: Exception) {
            if (requestGeneration == detailRequestGeneration) detailError = CONNECTION_ERROR
        } finally {
            if (requestGeneration == detailRequestGeneration) detailLoading = false
        }
    }

    fun closeThread() {
        detailRequestGeneration += 1
        detailJob?.cancel()
        detailJob = null
        dismissRouting()
        detail = null
        detailLoading = false
        replyDraft = ""
        pendingMutation = null
        detailError = null
        mutationError = null
        notice = null
        conflictNotice = null
    }

    fun openRouting(bearer: String, canRespond: Boolean) {
        if (pendingMutation != null) return
        val item = detail ?: return
        if (
            bearer.isBlank() ||
            routeCandidatesLoading ||
            !PatientCommunicationRoutingPolicy.canOpen(canRespond, item)
        ) return
        dismissRouting()
        routingOpen = true
        routeCandidatesLoading = true
        routeCandidatesError = null
        routeCandidatesJob = stateScope.launch {
            try {
                val response = api.routeCandidates(bearer, item.workItemUuid)
                val displayed = detail
                if (
                    displayed == null ||
                    !PatientCommunicationRoutingPolicy.matchesDisplayedItem(response, displayed)
                ) {
                    dismissRouting()
                    conflictNotice = ROUTING_STALE_ERROR
                } else {
                    routeCandidates = response
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (exception: ApiException) {
                handleRouteCandidatesFailure(exception)
            } catch (_: Exception) {
                routeCandidatesError = ROUTING_UNAVAILABLE_ERROR
            }
            routeCandidatesLoading = false
        }
    }

    fun dismissRouting() {
        routeCandidatesJob?.cancel()
        routeCandidatesJob = null
        clearRoutingPresentation()
    }

    fun selectRoutingAction(action: PatientCommunicationRoutingAction) {
        val candidates = routeCandidates ?: return
        if (!PatientCommunicationRoutingPolicy.isAllowed(action, candidates.actions)) return
        selectedRoutingAction = action
        selectedRoutingReasonCode = null
        selectedRoutingTargetUuid = null
    }

    fun selectRoutingReason(reasonCode: String) {
        val candidates = routeCandidates ?: return
        val action = selectedRoutingAction ?: return
        if (PatientCommunicationRoutingPolicy.reasons(action, candidates.reasonOptions).none { it.code == reasonCode }) {
            return
        }
        selectedRoutingReasonCode = reasonCode
    }

    fun selectRoutingTarget(targetUuid: String) {
        val candidates = routeCandidates ?: return
        val action = selectedRoutingAction ?: return
        val exactTarget = runCatching { PatientCommunicationCommandIds.requireUuid(targetUuid) }.getOrNull() ?: return
        val valid = when (action) {
            PatientCommunicationRoutingAction.Release -> false
            PatientCommunicationRoutingAction.Reassign ->
                candidates.reassignCandidates.any { it.membershipUuid == exactTarget }
            PatientCommunicationRoutingAction.Reroute ->
                candidates.rerouteCandidates.any { it.poolUuid == exactTarget }
        }
        if (valid) selectedRoutingTargetUuid = exactTarget
    }

    fun confirmRouting(bearer: String, canRespond: Boolean) {
        if (pendingMutation != null) return
        val item = detail ?: return
        val candidates = routeCandidates ?: return
        val action = selectedRoutingAction ?: return
        val reasonCode = selectedRoutingReasonCode ?: return
        val targetUuid = selectedRoutingTargetUuid
        if (bearer.isBlank() || !PatientCommunicationRoutingPolicy.canOpen(canRespond, item)) {
            dismissRouting()
            return
        }
        if (!PatientCommunicationRoutingPolicy.matchesDisplayedItem(candidates, item)) {
            dismissRouting()
            conflictNotice = ROUTING_STALE_ERROR
            return
        }
        if (!PatientCommunicationRoutingPolicy.canSubmit(candidates, action, reasonCode, targetUuid)) {
            dismissRouting()
            return
        }
        val commonIdempotencyKey = PatientCommunicationCommandIds.next()
        val command = when (action) {
            PatientCommunicationRoutingAction.Release -> PendingPatientCommunicationMutation.Release(
                workItemUuid = candidates.workItemUuid,
                workItemVersion = candidates.workItemVersion,
                threadVersion = candidates.threadVersion,
                idempotencyKey = commonIdempotencyKey,
                reasonCode = reasonCode,
            )

            PatientCommunicationRoutingAction.Reassign -> PendingPatientCommunicationMutation.Reassign(
                workItemUuid = candidates.workItemUuid,
                workItemVersion = candidates.workItemVersion,
                threadVersion = candidates.threadVersion,
                idempotencyKey = commonIdempotencyKey,
                targetMembershipUuid = requireNotNull(targetUuid),
                reasonCode = reasonCode,
            )

            PatientCommunicationRoutingAction.Reroute -> PendingPatientCommunicationMutation.Reroute(
                workItemUuid = candidates.workItemUuid,
                workItemVersion = candidates.workItemVersion,
                threadVersion = candidates.threadVersion,
                idempotencyKey = commonIdempotencyKey,
                targetPoolUuid = requireNotNull(targetUuid),
                reasonCode = reasonCode,
            )
        }
        // Candidate labels and selectors are no longer needed after the exact opaque command exists.
        dismissRouting()
        submit(bearer, command)
    }

    fun updateReplyDraft(value: String) {
        // An uncertain write must be resolved or explicitly discarded before a
        // different patient-visible command can be composed. Silently replacing
        // the retained tuple could turn one tap into two committed replies.
        if (pendingMutation != null) return
        val next = value.take(MAX_MESSAGE_LENGTH)
        replyDraft = next
    }

    fun claim(bearer: String) {
        if (pendingMutation != null) return
        val item = detail ?: return
        submit(
            bearer,
            PendingPatientCommunicationMutation.Claim(
                workItemUuid = item.workItemUuid,
                workItemVersion = item.workItemVersion,
                threadVersion = item.threadVersion,
                idempotencyKey = PatientCommunicationCommandIds.next(),
            ),
        )
    }

    fun reply(bearer: String) {
        if (pendingMutation != null) return
        val item = detail ?: return
        val body = replyDraft.trim()
        if (body.isBlank() || body.length > MAX_MESSAGE_LENGTH) return
        submit(
            bearer,
            PendingPatientCommunicationMutation.Reply(
                workItemUuid = item.workItemUuid,
                workItemVersion = item.workItemVersion,
                threadVersion = item.threadVersion,
                idempotencyKey = PatientCommunicationCommandIds.next(),
                clientMessageUuid = PatientCommunicationCommandIds.next(),
                body = body,
            ),
        )
    }

    fun close(bearer: String, reason: PatientCommunicationCloseReason) {
        if (pendingMutation != null) return
        val item = detail ?: return
        submit(
            bearer,
            PendingPatientCommunicationMutation.Close(
                workItemUuid = item.workItemUuid,
                workItemVersion = item.workItemVersion,
                threadVersion = item.threadVersion,
                idempotencyKey = PatientCommunicationCommandIds.next(),
                reason = reason,
            ),
        )
    }

    /** An explicit foreground action; this is never invoked automatically. */
    fun retryPendingMutation(bearer: String) {
        pendingMutation?.let { submit(bearer, it) }
    }

    fun discardPendingMutation() {
        pendingMutation = null
        mutationError = null
    }

    fun clearNotice() {
        notice = null
        conflictNotice = null
    }

    private fun submit(bearer: String, command: PendingPatientCommunicationMutation) {
        val retained = pendingMutation
        if (retained != null && retained != command) return
        if (bearer.isBlank() || mutating) return
        pendingMutation = command
        mutating = true
        mutationError = null
        notice = null
        conflictNotice = null

        mutationJob = stateScope.launch {
            try {
                val result = when (command) {
                    is PendingPatientCommunicationMutation.Claim -> api.claim(
                        bearer = bearer,
                        workItemUuid = command.workItemUuid,
                        workItemVersion = command.workItemVersion,
                        threadVersion = command.threadVersion,
                        idempotencyKey = command.idempotencyKey,
                    )

                    is PendingPatientCommunicationMutation.Reply -> api.reply(
                        bearer = bearer,
                        workItemUuid = command.workItemUuid,
                        workItemVersion = command.workItemVersion,
                        threadVersion = command.threadVersion,
                        message = command.body,
                        clientMessageUuid = command.clientMessageUuid,
                        idempotencyKey = command.idempotencyKey,
                    )

                    is PendingPatientCommunicationMutation.Close -> api.close(
                        bearer = bearer,
                        workItemUuid = command.workItemUuid,
                        workItemVersion = command.workItemVersion,
                        threadVersion = command.threadVersion,
                        reason = command.reason,
                        idempotencyKey = command.idempotencyKey,
                    )

                    is PendingPatientCommunicationMutation.Release -> api.release(
                        bearer = bearer,
                        workItemUuid = command.workItemUuid,
                        workItemVersion = command.workItemVersion,
                        threadVersion = command.threadVersion,
                        reasonCode = command.reasonCode,
                        idempotencyKey = command.idempotencyKey,
                    )

                    is PendingPatientCommunicationMutation.Reassign -> api.reassign(
                        bearer = bearer,
                        workItemUuid = command.workItemUuid,
                        workItemVersion = command.workItemVersion,
                        threadVersion = command.threadVersion,
                        targetMembershipUuid = command.targetMembershipUuid,
                        reasonCode = command.reasonCode,
                        idempotencyKey = command.idempotencyKey,
                    )

                    is PendingPatientCommunicationMutation.Reroute -> api.reroute(
                        bearer = bearer,
                        workItemUuid = command.workItemUuid,
                        workItemVersion = command.workItemVersion,
                        threadVersion = command.threadVersion,
                        targetPoolUuid = command.targetPoolUuid,
                        reasonCode = command.reasonCode,
                        idempotencyKey = command.idempotencyKey,
                    )
                }
                applyMutationResult(command, result)
                pendingMutation = null
                mutationError = null
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (exception: ApiException) {
                handleMutationFailure(bearer, command, exception)
            } catch (_: Exception) {
                if (command is PendingPatientCommunicationMutation.Reroute) {
                    purgeAfterPossibleReroute(command)
                } else {
                    mutationError = UNCONFIRMED_MUTATION_ERROR
                    // Retain this exact command only in memory for an explicit retry.
                }
            }
            mutating = false
        }
    }

    private fun applyMutationResult(
        command: PendingPatientCommunicationMutation,
        result: PatientCommunicationMutationResult,
    ) {
        val canonicalEvent = result.eventUuid?.let { eventUuid ->
            runCatching { PatientCommunicationCommandIds.requireUuid(eventUuid) }.isSuccess
        } == true
        val workItem = result.workItem
        if (command is PendingPatientCommunicationMutation.Reroute) {
            val validResponse = if (workItem == null) {
                result.replayed && result.message == null && canonicalEvent
            } else {
                workItem.workItemUuid == command.workItemUuid &&
                    !result.replayed &&
                    result.message == null &&
                    canonicalEvent &&
                    workItem.workItemVersion == command.workItemVersion + 1 &&
                    workItem.threadVersion == command.threadVersion + 1 &&
                    workItem.pool.poolUuid == command.targetPoolUuid &&
                    workItem.ownershipState == "rerouted"
            }
            // A reroute response is an authorization boundary even when its
            // envelope drifts. Keep only the opaque command tuple when the
            // result cannot be verified, so an explicit retry cannot disclose
            // content or accidentally create a second command.
            if (!validResponse) {
                purgeAfterPossibleReroute(command)
                throw IllegalArgumentException("Reroute response did not match the submitted command.")
            }
            purgeAfterReroute(command.workItemUuid)
            return
        }
        if (workItem == null) {
            purgeDetailForAccessLoss(command.workItemUuid)
            throw IllegalArgumentException("Only an exact reroute replay may omit the work item.")
        }
        require(workItem.workItemUuid == command.workItemUuid) {
            "Mutation response did not match the submitted work item."
        }
        require(canonicalEvent) { "Mutation response omitted its canonical event receipt." }
        val validVersions = if (result.replayed) {
            workItem.workItemVersion >= command.workItemVersion + 1 &&
                workItem.threadVersion >= command.threadVersion + 1
        } else {
            workItem.workItemVersion == command.workItemVersion + 1 &&
                workItem.threadVersion == command.threadVersion + 1
        }
        require(validVersions) { "Mutation response did not advance the submitted versions." }
        val validMessage = if (command is PendingPatientCommunicationMutation.Reply) {
            result.message?.let { message ->
                message.body == command.body &&
                    message.visibility == "patient_visible" &&
                    message.messageKind == "message"
            } == true
        } else {
            result.message == null
        }
        require(validMessage) { "Mutation response did not match the submitted action." }
        if (!result.replayed) {
            val validActionProjection = when (command) {
                is PendingPatientCommunicationMutation.Claim ->
                    workItem.status == "open" &&
                        workItem.ownershipState == "acknowledged" &&
                        workItem.assignedToMe
                is PendingPatientCommunicationMutation.Reply ->
                    workItem.status == "open" && workItem.ownershipState == "responded"
                is PendingPatientCommunicationMutation.Close -> workItem.status == "closed"
                is PendingPatientCommunicationMutation.Release ->
                    workItem.status == "open" &&
                        workItem.ownershipState == "pool_owned" &&
                        !workItem.assignedToMe
                is PendingPatientCommunicationMutation.Reassign ->
                    workItem.status == "open" && workItem.ownershipState == "assigned"
                is PendingPatientCommunicationMutation.Reroute -> false
            }
            require(validActionProjection) { "Mutation response projection did not match the action." }
        }
        val previousMessages = detail?.messages.orEmpty()
        val messages = result.message?.let { message ->
            if (previousMessages.any { it.messageUuid == message.messageUuid }) {
                previousMessages
            } else {
                previousMessages + message
            }
        } ?: previousMessages

        detail = workItem.copy(
            messages = messages,
            hasEarlierMessages = detail?.hasEarlierMessages ?: false,
        )
        inbox = if (workItem.status == "closed") {
            inbox.filterNot { it.workItemUuid == workItem.workItemUuid }
        } else {
            inbox.map { item ->
                if (item.workItemUuid == workItem.workItemUuid) workItem else item
            }
        }
        if (command is PendingPatientCommunicationMutation.Reply) replyDraft = ""
        notice = when (command) {
            is PendingPatientCommunicationMutation.Claim -> "Conversation claimed. The patient can see that the care team acknowledged it."
            is PendingPatientCommunicationMutation.Reply -> "Patient-visible reply sent."
            is PendingPatientCommunicationMutation.Close -> "Conversation closed."
            is PendingPatientCommunicationMutation.Release -> "Conversation released to its responsibility team."
            is PendingPatientCommunicationMutation.Reassign -> "Conversation reassigned to the selected responder."
            is PendingPatientCommunicationMutation.Reroute -> error("Reroute returns at the purge boundary above.")
        }
        if (result.replayed) notice = "${notice.orEmpty()} The server confirmed the original action."
    }

    private suspend fun handleMutationFailure(
        bearer: String,
        command: PendingPatientCommunicationMutation,
        exception: ApiException,
    ) {
        when (PatientCommunicationMutationRecoveryPolicy.after(exception.statusCode)) {
            PatientCommunicationRecovery.Reauthenticate -> {
                purgeForReauthentication()
            }

            PatientCommunicationRecovery.RefetchWithoutResend -> {
                dismissRouting()
                pendingMutation = null
                mutationError = null
                conflictNotice =
                    "This conversation changed since you loaded it. The latest version was fetched; your action was not resent."
                try {
                    detail = api.thread(bearer, command.workItemUuid)
                } catch (cancelled: CancellationException) {
                    throw cancelled
                } catch (refreshFailure: ApiException) {
                    when (refreshFailure.statusCode) {
                        401 -> purgeForReauthentication()
                        403, 404 -> purgeDetailForAccessLoss(command.workItemUuid)
                        else -> detailError = "Refresh the conversation before taking another action."
                    }
                } catch (_: Exception) {
                    detailError = "Refresh the conversation before taking another action."
                }
            }

            PatientCommunicationRecovery.ExplicitExactRetryAvailable -> {
                if (command is PendingPatientCommunicationMutation.Reroute) {
                    purgeAfterPossibleReroute(command)
                } else {
                    mutationError = UNCONFIRMED_MUTATION_ERROR
                }
            }

            PatientCommunicationRecovery.DiscardCommand -> {
                if (exception.statusCode == 403 || exception.statusCode == 404) {
                    purgeDetailForAccessLoss(command.workItemUuid)
                } else {
                    pendingMutation = null
                    mutationError = safeMutationMessage(exception.statusCode)
                }
            }
        }
    }

    private fun handleReadFailure(
        exception: ApiException,
        inbox: Boolean,
        workItemUuid: String? = null,
    ) {
        if (exception.statusCode == 401) {
            purgeForReauthentication()
            return
        }
        if (exception.statusCode == 403 || exception.statusCode == 404) {
            if (inbox) {
                clearSensitiveState()
                unavailable = true
                inboxError = ACCESS_UNAVAILABLE_ERROR
            } else {
                purgeDetailForAccessLoss(workItemUuid ?: detail?.workItemUuid)
            }
            return
        }
        val message = when (exception.statusCode) {
            503 -> "Patient Communications is temporarily unavailable. Try again later."
            else -> CONNECTION_ERROR
        }
        if (inbox) inboxError = message else detailError = message
    }

    private fun authoritativeInboxProjectionChanged(
        displayed: PatientCommunicationWorkItem,
        fresh: PatientCommunicationWorkItem,
    ): Boolean = displayed.workItemVersion != fresh.workItemVersion ||
        displayed.threadVersion != fresh.threadVersion ||
        displayed.threadUuid != fresh.threadUuid ||
        displayed.status != fresh.status ||
        displayed.ownershipState != fresh.ownershipState ||
        displayed.assignedToMe != fresh.assignedToMe ||
        displayed.pool.poolUuid != fresh.pool.poolUuid ||
        displayed.unit?.id != fresh.unit?.id

    /**
     * Hide a still-authorized but stale decrypted projection before its fresh GET.
     * An already-immutable ambiguous command remains available only for explicit
     * exact replay; its identity is never replaced with versions from the poll.
     */
    private fun purgeDetailForServerTransition(workItemUuid: String) {
        if (detail?.workItemUuid != workItemUuid) return
        detailRequestGeneration += 1
        detailJob?.cancel()
        routeCandidatesJob?.cancel()
        detailJob = null
        routeCandidatesJob = null
        detail = null
        replyDraft = ""
        clearRoutingPresentation()
        detailLoading = false
        detailError = null
        notice = null
        conflictNotice = SERVER_TRANSITION_NOTICE
        unavailable = false
    }

    private fun handleRouteCandidatesFailure(exception: ApiException) {
        if (exception.statusCode == 401) {
            purgeForReauthentication()
            return
        }
        if (exception.statusCode == 403 || exception.statusCode == 404) {
            purgeDetailForAccessLoss(detail?.workItemUuid)
            return
        }
        routeCandidates = null
        selectedRoutingAction = null
        selectedRoutingReasonCode = null
        selectedRoutingTargetUuid = null
        routeCandidatesError = when (exception.statusCode) {
            503 -> "Responsibility changes are temporarily unavailable. Try again later."
            else -> ROUTING_UNAVAILABLE_ERROR
        }
    }

    private fun purgeForReauthentication() {
        clearSensitiveState()
        needsReauth = true
        inboxError = SESSION_ERROR
        detailError = SESSION_ERROR
        mutationError = SESSION_ERROR
    }

    private fun purgeDetailForAccessLoss(
        workItemUuid: String?,
        cancelInboxJob: Boolean = true,
    ) {
        if (cancelInboxJob) {
            inboxRequestGeneration += 1
            inboxJob?.cancel()
        }
        detailRequestGeneration += 1
        detailJob?.cancel()
        routeCandidatesJob?.cancel()
        mutationJob?.cancel()
        if (cancelInboxJob) {
            inboxJob = null
            inboxLoading = false
        }
        detailJob = null
        routeCandidatesJob = null
        mutationJob = null
        if (workItemUuid != null) inbox = inbox.filterNot { it.workItemUuid == workItemUuid }
        detail = null
        replyDraft = ""
        clearRoutingPresentation()
        pendingMutation = null
        detailLoading = false
        mutating = false
        detailError = ACCESS_UNAVAILABLE_ERROR
        mutationError = null
        notice = null
        conflictNotice = null
        unavailable = true
    }

    private fun purgeAfterReroute(workItemUuid: String) {
        inboxRequestGeneration += 1
        detailRequestGeneration += 1
        inboxJob?.cancel()
        detailJob?.cancel()
        routeCandidatesJob?.cancel()
        inboxJob = null
        detailJob = null
        routeCandidatesJob = null
        inbox = inbox.filterNot { it.workItemUuid == workItemUuid }
        detail = null
        replyDraft = ""
        clearRoutingPresentation()
        pendingMutation = null
        detailLoading = false
        inboxLoading = false
        mutating = false
        mutationError = null
        notice = null
        conflictNotice = null
        detailError = REROUTED_UNAVAILABLE_ERROR
        unavailable = true
    }

    private fun purgeAfterPossibleReroute(
        command: PendingPatientCommunicationMutation.Reroute,
        cancelInboxJob: Boolean = true,
    ) {
        if (cancelInboxJob) {
            inboxRequestGeneration += 1
            inboxJob?.cancel()
            inboxJob = null
            inboxLoading = false
        }
        detailRequestGeneration += 1
        detailJob?.cancel()
        routeCandidatesJob?.cancel()
        detailJob = null
        routeCandidatesJob = null
        inbox = inbox.filterNot { it.workItemUuid == command.workItemUuid }
        detail = null
        replyDraft = ""
        clearRoutingPresentation()
        pendingMutation = command
        detailLoading = false
        mutating = false
        mutationError = UNCONFIRMED_MUTATION_ERROR
        notice = null
        conflictNotice = null
        detailError = REROUTED_UNAVAILABLE_ERROR
        unavailable = true
    }

    private fun clearRoutingPresentation() {
        routingOpen = false
        routeCandidates = null
        selectedRoutingAction = null
        selectedRoutingReasonCode = null
        selectedRoutingTargetUuid = null
        routeCandidatesLoading = false
        routeCandidatesError = null
    }

    private fun safeMutationMessage(statusCode: Int?): String = when (statusCode) {
        403, 404 -> "This conversation is no longer available to you. Refresh your inbox."
        422 -> "Review the action and try again. No change was made."
        else -> "The action could not be completed. Refresh before trying again."
    }

    override fun onCleared() {
        clearSensitiveState()
        super.onCleared()
    }

    private companion object {
        const val MAX_MESSAGE_LENGTH = 4000
        const val CONNECTION_ERROR = "Can't load Patient Communications. Check your connection and try again."
        const val SESSION_ERROR = "Your session has expired. Please sign in again."
        const val UNCONFIRMED_MUTATION_ERROR =
            "We couldn't confirm the action. Nothing will be resent automatically; retry explicitly with the same command or refresh."
        const val ROUTING_UNAVAILABLE_ERROR =
            "Can't load responsibility options. Refresh the conversation before trying again."
        const val ROUTING_STALE_ERROR =
            "This conversation changed while responsibility options were open. Refresh before taking another action; nothing was sent."
        const val SERVER_TRANSITION_NOTICE =
            "Responsibility changed while this conversation was open. The latest version was loaded before another action."
        const val ACCESS_UNAVAILABLE_ERROR =
            "This conversation is no longer available to you. No patient information remains in this view."
        const val REROUTED_UNAVAILABLE_ERROR =
            "Responsibility changed. Refresh only if you are still authorized to view this conversation."
    }
}
