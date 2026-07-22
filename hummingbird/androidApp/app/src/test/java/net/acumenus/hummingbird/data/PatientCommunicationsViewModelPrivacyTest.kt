package net.acumenus.hummingbird.data

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withContext
import kotlinx.coroutines.yield
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientCommunicationsViewModelPrivacyTest {
    @Test
    fun `normal reroute success revokes local access and purges decrypted state`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        prepareReroute(viewModel, gateway)
        gateway.rerouteResults.add(
            Result.success(
                PatientCommunicationMutationResult(
                    workItem = sentinelItem().copy(
                        pool = PatientCommunicationPool(NEW_POOL_UUID, "New team"),
                        ownershipState = "rerouted",
                        workItemVersion = 8,
                        threadVersion = 12,
                    ),
                    message = null,
                    eventUuid = EVENT_UUID,
                    replayed = false,
                ),
            ),
        )

        viewModel.confirmRouting(TOKEN, canRespond = true)

        assertRevokedAndPurged(viewModel)
        assertEquals(1, gateway.rerouteIdempotencyKeys.size)
    }

    @Test
    fun `exact reroute retry accepts committed nil replay then purges decrypted state`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        prepareReroute(viewModel, gateway)
        gateway.rerouteResults.add(Result.failure(ApiException("connection lost", null)))
        gateway.rerouteResults.add(
            Result.success(
                PatientCommunicationMutationResult(
                    workItem = null,
                    message = null,
                    eventUuid = EVENT_UUID,
                    replayed = true,
                ),
            ),
        )

        viewModel.confirmRouting(TOKEN, canRespond = true)
        assertTrue(viewModel.canRetryMutation)
        assertNull(viewModel.detail)
        assertEquals("", viewModel.replyDraft)
        assertFalse(viewModel.inbox.any { item ->
            item.workItemUuid == WORK_ITEM_UUID || item.messages.any { it.body == SENTINEL_MESSAGE }
        })
        assertTrue(viewModel.unavailable)

        viewModel.retryPendingMutation(TOKEN)

        assertRevokedAndPurged(viewModel)
        assertEquals(2, gateway.rerouteIdempotencyKeys.size)
        assertEquals(gateway.rerouteIdempotencyKeys.first(), gateway.rerouteIdempotencyKeys.last())
    }

    @Test
    fun `malformed reroute decode purges content before exposing exact retry`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        prepareReroute(viewModel, gateway)
        gateway.rerouteResults.add(Result.failure(IllegalArgumentException("malformed 2xx")))

        viewModel.confirmRouting(TOKEN, canRespond = true)

        assertNull(viewModel.detail)
        assertEquals("", viewModel.replyDraft)
        assertFalse(viewModel.inbox.any { item ->
            item.workItemUuid == WORK_ITEM_UUID || item.messages.any { it.body == SENTINEL_MESSAGE }
        })
        assertTrue(viewModel.canRetryMutation)
    }

    @Test
    fun `poll cannot republish source content while a reroute receipt is unresolved`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        prepareReroute(viewModel, gateway)
        gateway.rerouteResults.add(Result.failure(ApiException("response lost", 503)))
        viewModel.confirmRouting(TOKEN, canRespond = true)
        assertTrue(viewModel.canRetryMutation)

        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(listOf(sentinelItem()), 1)))
        viewModel.loadInbox(TOKEN)

        assertTrue(viewModel.inbox.isEmpty())
        assertNull(viewModel.detail)
        assertEquals("", viewModel.replyDraft)
        assertTrue(viewModel.canRetryMutation)
    }

    @Test
    fun `successful inbox omission purges open detail draft routing and uncertain non reroute command`() = runBlocking {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModelWithPendingState(gateway)
        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(emptyList(), 0)))

        viewModel.refreshInboxForPolling(TOKEN, WORK_ITEM_UUID)

        assertAccessDeniedAndPurged(viewModel)
        assertTrue(viewModel.inbox.isEmpty())
        assertFalse(viewModel.canRetryMutation)
    }

    @Test
    fun `retained row version drift purges stale ui and refetches detail exactly once`() = runBlocking {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        gateway.routeCandidateResults.add(Result.success(routeCandidates()))
        viewModel.openRouting(TOKEN, canRespond = true)
        val transitioned = sentinelItem().copy(
            unit = PatientCommunicationUnit(86, "Destination unit"),
            pool = PatientCommunicationPool(NEW_POOL_UUID, "Destination care team"),
            ownershipState = "rerouted",
            assignedToMe = false,
            workItemVersion = 8,
            threadVersion = 12,
            messages = emptyList(),
        )
        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(listOf(transitioned), 1)))
        gateway.threadResults.add(Result.success(transitioned.copy(messages = listOf(sentinelMessage()))))
        val callsBeforePoll = gateway.threadCalls

        viewModel.refreshInboxForPolling(TOKEN, WORK_ITEM_UUID)

        assertEquals(callsBeforePoll + 1, gateway.threadCalls)
        assertEquals(8, viewModel.detail?.workItemVersion)
        assertEquals(NEW_POOL_UUID, viewModel.detail?.pool?.poolUuid)
        assertEquals("", viewModel.replyDraft)
        assertFalse(viewModel.routingOpen)
        assertNull(viewModel.routeCandidates)
        assertTrue(viewModel.conflictNotice.orEmpty().contains("Responsibility changed"))
    }

    @Test
    fun `retained row fail safe ownership drift refreshes even without version advance`() = runBlocking {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        val released = sentinelItem().copy(
            ownershipState = "pool_owned",
            assignedToMe = false,
            messages = emptyList(),
        )
        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(listOf(released), 1)))
        gateway.threadResults.add(Result.success(released.copy(messages = listOf(sentinelMessage()))))

        viewModel.refreshInboxForPolling(TOKEN, WORK_ITEM_UUID)

        assertEquals(2, gateway.threadCalls)
        assertEquals("pool_owned", viewModel.detail?.ownershipState)
        assertFalse(viewModel.detail?.assignedToMe ?: true)
        assertEquals("", viewModel.replyDraft)
    }

    @Test
    fun `late pre transition detail success cannot overwrite reconciled detail`() = runBlocking {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        val staleReadStarted = CompletableDeferred<Unit>()
        val releaseStaleRead = CompletableDeferred<Unit>()
        gateway.threadResponders.add {
            staleReadStarted.complete(Unit)
            try {
                releaseStaleRead.await()
            } catch (_: CancellationException) {
                withContext(NonCancellable) { releaseStaleRead.await() }
            }
            sentinelItem().copy(messages = listOf(sentinelMessage().copy(body = "STALE PHI")))
        }
        val transitioned = sentinelItem().copy(
            ownershipState = "pool_owned",
            assignedToMe = false,
            workItemVersion = 8,
            threadVersion = 12,
            messages = emptyList(),
        )
        gateway.threadResponders.add {
            transitioned.copy(messages = listOf(sentinelMessage().copy(body = "CURRENT PHI")))
        }
        viewModel.loadThread(TOKEN, WORK_ITEM_UUID)
        staleReadStarted.await()
        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(listOf(transitioned), 1)))

        viewModel.refreshInboxForPolling(TOKEN, WORK_ITEM_UUID)
        releaseStaleRead.complete(Unit)
        yield()

        assertEquals(3, gateway.threadCalls)
        assertEquals(8, viewModel.detail?.workItemVersion)
        assertEquals("CURRENT PHI", viewModel.detail?.messages?.single()?.body)
    }

    @Test
    fun `server transition preserves immutable uncertain reply without enabling a fresh write`() = runBlocking {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        gateway.replyResults.add(Result.failure(ApiException("response lost", 503)))
        viewModel.reply(TOKEN)
        val exactAttempt = gateway.replyAttempts.single()
        val transitioned = sentinelItem().copy(
            ownershipState = "pool_owned",
            assignedToMe = false,
            workItemVersion = 8,
            threadVersion = 12,
            messages = emptyList(),
        )
        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(listOf(transitioned), 1)))
        gateway.threadResults.add(Result.success(transitioned.copy(messages = listOf(sentinelMessage()))))

        viewModel.refreshInboxForPolling(TOKEN, WORK_ITEM_UUID)
        viewModel.updateReplyDraft("A different reply must not replace the exact tuple")
        viewModel.reply(TOKEN)
        viewModel.close(TOKEN, PatientCommunicationCloseReason.QuestionAnswered)

        assertTrue(viewModel.canRetryMutation)
        assertEquals("", viewModel.replyDraft)
        assertEquals(1, gateway.replyAttempts.size)
        assertTrue(gateway.closeAttempts.isEmpty())
        gateway.replyResults.add(Result.failure(ApiException("still unknown", 503)))
        viewModel.retryPendingMutation(TOKEN)
        assertEquals(listOf(exactAttempt, exactAttempt), gateway.replyAttempts)
    }

    @Test
    fun `concurrent poll request is skipped while the first inbox read is in flight`() = runBlocking {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        val firstReadStarted = CompletableDeferred<Unit>()
        val releaseFirstRead = CompletableDeferred<Unit>()
        gateway.inboxResponders.add {
            firstReadStarted.complete(Unit)
            releaseFirstRead.await()
            PatientCommunicationInbox(listOf(sentinelItem().copy(messages = emptyList())), 1)
        }

        val first = launch { viewModel.refreshInboxForPolling(TOKEN, null) }
        firstReadStarted.await()
        viewModel.refreshInboxForPolling(TOKEN, null)
        assertEquals(2, gateway.inboxCalls)
        assertEquals(1, gateway.maximumConcurrentInboxCalls)
        releaseFirstRead.complete(Unit)
        first.join()
    }

    @Test
    fun `route candidate access denial purges the authoritative conversation boundary`() {
        listOf(403, 404).forEach { statusCode ->
            val gateway = FakePatientCommunicationsGateway()
            val viewModel = seededViewModel(gateway)
            gateway.routeCandidateResults.add(Result.failure(ApiException("denied", statusCode)))

            viewModel.openRouting(TOKEN, canRespond = true)

            assertAccessDeniedAndPurged(viewModel)
        }
    }

    @Test
    fun `401 purges every patient communication state before reauthentication`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModelWithPendingState(gateway)
        gateway.threadResults.add(Result.failure(ApiException("expired", 401)))

        viewModel.loadThread(TOKEN, WORK_ITEM_UUID)

        assertAllSensitiveStatePurged(viewModel)
        assertTrue(viewModel.needsReauth)
        assertTrue(viewModel.inbox.isEmpty())
    }

    @Test
    fun `403 detail denial purges sentinel PHI routing draft and pending command`() {
        assertDetailDenialPurges(statusCode = 403)
    }

    @Test
    fun `404 detail denial purges sentinel PHI routing draft and pending command`() {
        assertDetailDenialPurges(statusCode = 404)
    }

    @Test
    fun `409 followed by refresh 404 purges stale detail without resending`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        gateway.routeCandidateResults.add(Result.success(routeCandidates()))
        viewModel.openRouting(TOKEN, canRespond = true)
        gateway.replyResults.add(Result.failure(ApiException("version conflict", 409)))
        gateway.threadResults.add(Result.failure(ApiException("no longer authorized", 404)))
        viewModel.updateReplyDraft(SENTINEL_DRAFT)

        viewModel.reply(TOKEN)

        assertAccessDeniedAndPurged(viewModel)
        assertEquals(1, gateway.replyCalls)
        assertEquals(2, gateway.threadCalls)
    }

    @Test
    fun `ambiguous reply survives advanced refresh and only exact tuple can be retried`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        gateway.replyResults.add(Result.failure(ApiException("response lost", 503)))

        viewModel.reply(TOKEN)

        assertTrue(viewModel.canRetryMutation)
        val original = gateway.replyAttempts.single()

        gateway.threadResults.add(
            Result.success(
                sentinelItem().copy(
                    workItemVersion = 9,
                    threadVersion = 13,
                    ownershipState = "responded",
                ),
            ),
        )
        viewModel.loadThread(TOKEN, WORK_ITEM_UUID)

        // Fresh actions and draft replacement cannot supersede an unresolved write.
        viewModel.updateReplyDraft("A different patient-visible reply")
        viewModel.reply(TOKEN)
        viewModel.close(TOKEN, PatientCommunicationCloseReason.QuestionAnswered)
        assertEquals(SENTINEL_DRAFT, viewModel.replyDraft)
        assertEquals(1, gateway.replyAttempts.size)
        assertTrue(gateway.closeAttempts.isEmpty())

        gateway.replyResults.add(
            Result.success(
                PatientCommunicationMutationResult(
                    workItem = sentinelItem().copy(
                        workItemVersion = 9,
                        threadVersion = 13,
                        ownershipState = "responded",
                    ),
                    message = sentinelMessage().copy(
                        senderDisplayRole = "Care team",
                        body = original.message,
                    ),
                    eventUuid = EVENT_UUID,
                    replayed = true,
                ),
            ),
        )
        viewModel.retryPendingMutation(TOKEN)

        assertEquals(2, gateway.replyAttempts.size)
        assertEquals(original, gateway.replyAttempts.last())
        assertFalse(viewModel.canRetryMutation)
        assertEquals("", viewModel.replyDraft)
    }

    @Test
    fun `nil work item on any non reroute mutation fails closed and removes stale detail`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        gateway.replyResults.add(
            Result.success(
                PatientCommunicationMutationResult(
                    workItem = null,
                    message = null,
                    eventUuid = EVENT_UUID,
                    replayed = true,
                ),
            ),
        )
        viewModel.updateReplyDraft(SENTINEL_DRAFT)

        viewModel.reply(TOKEN)

        assertAccessDeniedAndPurged(viewModel)
        assertFalse(viewModel.canRetryMutation)
    }

    @Test
    fun `unverified reroute response purges content and retains only exact retry`() {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModel(gateway)
        prepareReroute(viewModel, gateway)
        gateway.rerouteResults.add(
            Result.success(
                PatientCommunicationMutationResult(
                    workItem = null,
                    message = null,
                    eventUuid = EVENT_UUID,
                    replayed = false,
                ),
            ),
        )

        viewModel.confirmRouting(TOKEN, canRespond = true)

        assertNull(viewModel.detail)
        assertEquals("", viewModel.replyDraft)
        assertNull(viewModel.routeCandidates)
        assertFalse(viewModel.routingOpen)
        assertFalse(viewModel.inbox.any { item ->
            item.workItemUuid == WORK_ITEM_UUID || item.messages.any { it.body == SENTINEL_MESSAGE }
        })
        assertTrue(viewModel.unavailable)
        assertTrue(viewModel.canRetryMutation)
    }

    private fun assertDetailDenialPurges(statusCode: Int) {
        val gateway = FakePatientCommunicationsGateway()
        val viewModel = seededViewModelWithPendingState(gateway)
        gateway.threadResults.add(Result.failure(ApiException("denied", statusCode)))

        viewModel.loadThread(TOKEN, WORK_ITEM_UUID)

        assertAccessDeniedAndPurged(viewModel)
        assertFalse(viewModel.needsReauth)
    }

    private fun seededViewModel(gateway: FakePatientCommunicationsGateway): PatientCommunicationsViewModel {
        gateway.inboxResults.add(Result.success(PatientCommunicationInbox(listOf(sentinelItem()), 1)))
        gateway.threadResults.add(Result.success(sentinelItem()))
        val viewModel = PatientCommunicationsViewModel(
            api = gateway,
            externalScope = CoroutineScope(SupervisorJob() + Dispatchers.Unconfined),
        )
        viewModel.loadInbox(TOKEN)
        viewModel.loadThread(TOKEN, WORK_ITEM_UUID)
        viewModel.updateReplyDraft(SENTINEL_DRAFT)
        assertEquals(SENTINEL_MESSAGE, viewModel.detail?.messages?.single()?.body)
        assertEquals(SENTINEL_DRAFT, viewModel.replyDraft)
        return viewModel
    }

    private fun seededViewModelWithPendingState(
        gateway: FakePatientCommunicationsGateway,
    ): PatientCommunicationsViewModel {
        val viewModel = seededViewModel(gateway)
        gateway.routeCandidateResults.add(Result.success(routeCandidates()))
        viewModel.openRouting(TOKEN, canRespond = true)
        gateway.replyResults.add(Result.failure(ApiException("unconfirmed", null)))
        viewModel.reply(TOKEN)
        assertTrue(viewModel.routingOpen)
        assertTrue(viewModel.canRetryMutation)
        return viewModel
    }

    private fun prepareReroute(
        viewModel: PatientCommunicationsViewModel,
        gateway: FakePatientCommunicationsGateway,
    ) {
        gateway.routeCandidateResults.add(Result.success(routeCandidates()))
        viewModel.openRouting(TOKEN, canRespond = true)
        viewModel.selectRoutingAction(PatientCommunicationRoutingAction.Reroute)
        viewModel.selectRoutingReason("wrong_team")
        viewModel.selectRoutingTarget(NEW_POOL_UUID)
        assertTrue(viewModel.canReviewRouting)
    }

    private fun assertRevokedAndPurged(viewModel: PatientCommunicationsViewModel) {
        assertAllSensitiveStatePurged(viewModel)
        assertTrue(viewModel.inbox.none { it.workItemUuid == WORK_ITEM_UUID })
        assertTrue(viewModel.unavailable)
        assertTrue(viewModel.detailError.orEmpty().contains("Responsibility changed"))
    }

    private fun assertAccessDeniedAndPurged(viewModel: PatientCommunicationsViewModel) {
        assertAllSensitiveStatePurged(viewModel)
        assertTrue(viewModel.inbox.none { it.workItemUuid == WORK_ITEM_UUID })
        assertTrue(viewModel.unavailable)
        assertTrue(viewModel.detailError.orEmpty().contains("no longer available"))
    }

    private fun assertAllSensitiveStatePurged(viewModel: PatientCommunicationsViewModel) {
        assertNull(viewModel.detail)
        assertEquals("", viewModel.replyDraft)
        assertNull(viewModel.routeCandidates)
        assertNull(viewModel.selectedRoutingAction)
        assertNull(viewModel.selectedRoutingReasonCode)
        assertNull(viewModel.selectedRoutingTargetUuid)
        assertFalse(viewModel.routingOpen)
        assertFalse(viewModel.canRetryMutation)
        assertFalse(viewModel.mutating)
        assertFalse(viewModel.inbox.any { item ->
            item.messages.any { it.body == SENTINEL_MESSAGE }
        })
    }

    private fun sentinelItem() = PatientCommunicationWorkItem(
        workItemUuid = WORK_ITEM_UUID,
        threadUuid = THREAD_UUID,
        patientContextRef = "sentinel-patient-context",
        topic = PatientCommunicationTopic("sentinel_topic", "Sentinel private topic"),
        unit = PatientCommunicationUnit(85, "Sentinel private unit"),
        pool = PatientCommunicationPool(POOL_UUID, "Sentinel private care team"),
        status = "open",
        ownershipState = "acknowledged",
        assignedToMe = true,
        workItemVersion = 7,
        threadVersion = 11,
        lastMessageAt = "2026-07-20T01:00:00-04:00",
        dueAt = "2026-07-20T01:30:00-04:00",
        escalateAt = "2026-07-20T02:00:00-04:00",
        isResponseDue = false,
        isEscalationDue = false,
        closedAt = null,
        messages = listOf(sentinelMessage()),
    )

    private fun sentinelMessage() = PatientCommunicationMessage(
        messageUuid = MESSAGE_UUID,
        senderDisplayRole = "Patient",
        visibility = "patient_visible",
        messageKind = "message",
        body = SENTINEL_MESSAGE,
        deliveryState = "acknowledged",
        sentAt = "2026-07-20T01:00:00-04:00",
    )

    private fun routeCandidates() = PatientCommunicationRouteCandidates(
        workItemUuid = WORK_ITEM_UUID,
        workItemVersion = 7,
        threadVersion = 11,
        actions = PatientCommunicationRouteActions(
            canRelease = false,
            canReassign = false,
            canReroute = true,
        ),
        reasonOptions = PatientCommunicationRouteReasonOptions(
            release = emptyList(),
            reassign = emptyList(),
            reroute = listOf(PatientCommunicationRouteReason("wrong_team", "Wrong team")),
        ),
        reassignCandidates = emptyList(),
        rerouteCandidates = listOf(
            PatientCommunicationRerouteCandidate(
                poolUuid = NEW_POOL_UUID,
                label = "New team",
                scopeType = "facility",
                unit = null,
            ),
        ),
    )

    private companion object {
        const val TOKEN = "test-token"
        const val SENTINEL_MESSAGE = "SENTINEL PHI: private patient message"
        const val SENTINEL_DRAFT = "SENTINEL PHI: private staff draft"
        const val WORK_ITEM_UUID = "019f7cb6-4d44-73e1-b28c-82bea62c4192"
        const val THREAD_UUID = "019f7cb6-4d44-73e1-b28c-82bea62c4191"
        const val POOL_UUID = "019f7cb6-4d44-73e1-b28c-82bea62c4190"
        const val NEW_POOL_UUID = "019f7cb6-4d44-73e1-b28c-82bea62c4300"
        const val MESSAGE_UUID = "019f7cb6-4d44-73e1-b28c-82bea62c4189"
        const val EVENT_UUID = "019f7cb6-4d44-73e1-b28c-82bea62c4194"
    }
}

private class FakePatientCommunicationsGateway : PatientCommunicationsGateway {
    val inboxResults = ArrayDeque<Result<PatientCommunicationInbox>>()
    val threadResults = ArrayDeque<Result<PatientCommunicationWorkItem>>()
    val inboxResponders = ArrayDeque<suspend () -> PatientCommunicationInbox>()
    val threadResponders = ArrayDeque<suspend () -> PatientCommunicationWorkItem>()
    val routeCandidateResults = ArrayDeque<Result<PatientCommunicationRouteCandidates>>()
    val claimResults = ArrayDeque<Result<PatientCommunicationMutationResult>>()
    val replyResults = ArrayDeque<Result<PatientCommunicationMutationResult>>()
    val closeResults = ArrayDeque<Result<PatientCommunicationMutationResult>>()
    val releaseResults = ArrayDeque<Result<PatientCommunicationMutationResult>>()
    val reassignResults = ArrayDeque<Result<PatientCommunicationMutationResult>>()
    val rerouteResults = ArrayDeque<Result<PatientCommunicationMutationResult>>()
    val rerouteIdempotencyKeys = mutableListOf<String>()
    val replyAttempts = mutableListOf<ReplyAttempt>()
    val closeAttempts = mutableListOf<String>()
    var threadCalls = 0
    var replyCalls = 0
    var inboxCalls = 0
    var maximumConcurrentInboxCalls = 0
    private var concurrentInboxCalls = 0

    override suspend fun inbox(bearer: String): PatientCommunicationInbox {
        inboxCalls += 1
        concurrentInboxCalls += 1
        maximumConcurrentInboxCalls = maxOf(maximumConcurrentInboxCalls, concurrentInboxCalls)
        return try {
            if (inboxResponders.isNotEmpty()) {
                inboxResponders.removeFirst().invoke()
            } else {
                inboxResults.take("inbox")
            }
        } finally {
            concurrentInboxCalls -= 1
        }
    }

    override suspend fun thread(bearer: String, workItemUuid: String): PatientCommunicationWorkItem {
        threadCalls += 1
        return if (threadResponders.isNotEmpty()) {
            threadResponders.removeFirst().invoke()
        } else {
            threadResults.take("thread")
        }
    }

    override suspend fun routeCandidates(bearer: String, workItemUuid: String) =
        routeCandidateResults.take("route candidates")

    override suspend fun claim(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: String,
    ) = claimResults.take("claim")

    override suspend fun reply(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUuid: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult {
        replyCalls += 1
        replyAttempts += ReplyAttempt(
            workItemUuid = workItemUuid,
            workItemVersion = workItemVersion,
            threadVersion = threadVersion,
            message = message,
            clientMessageUuid = clientMessageUuid,
            idempotencyKey = idempotencyKey,
        )
        return replyResults.take("reply")
    }

    override suspend fun close(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reason: PatientCommunicationCloseReason,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult {
        closeAttempts += idempotencyKey
        return closeResults.take("close")
    }

    override suspend fun release(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: String,
    ) = releaseResults.take("release")

    override suspend fun reassign(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ) = reassignResults.take("reassign")

    override suspend fun reroute(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult {
        rerouteIdempotencyKeys += idempotencyKey
        return rerouteResults.take("reroute")
    }

    private fun <T> ArrayDeque<Result<T>>.take(name: String): T {
        check(isNotEmpty()) { "No fake $name result was queued." }
        return removeFirst().getOrThrow()
    }
}

private data class ReplyAttempt(
    val workItemUuid: String,
    val workItemVersion: Int,
    val threadVersion: Int,
    val message: String,
    val clientMessageUuid: String,
    val idempotencyKey: String,
)
