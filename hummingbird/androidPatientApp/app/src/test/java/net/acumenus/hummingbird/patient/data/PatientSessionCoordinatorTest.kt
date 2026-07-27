package net.acumenus.hummingbird.patient.data

import net.acumenus.hummingbird.patient.PatientDestination
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientSessionCoordinatorTest {
    @Test
    fun credentialSignInPersistsRotatingPairAndBuildsReleasedExperience() {
        val api = FakePatientApiGateway()
        val store = MemoryPatientCredentialStore()
        val coordinator = coordinator(api, store)

        val outcome = coordinator.signIn("patient@example.test", "test-password".toCharArray())

        val ready = outcome as PatientSessionOutcome.Ready
        assertEquals("new-access", store.current?.accessToken)
        assertEquals("new-refresh", store.current?.refreshToken)
        assertEquals("Sample Patient", ready.snapshot.patientDisplayName)
        assertEquals("Care team rounds", ready.snapshot.todayItems.single().title)
        assertEquals("Monitoring and treatment", ready.snapshot.pathway.single().title)
        assertEquals("Care Coordinator", ready.snapshot.careTeam.single().name)
        assertEquals("Released summary.", ready.snapshot.todaySummary)
        assertEquals(listOf("Ask questions during rounds."), ready.snapshot.todayNextSteps)
        assertEquals("Monitoring and treatment", ready.snapshot.pathwayCurrentStage)
        assertEquals("Safe next step", ready.snapshot.pathwayMilestones.single().title)
        assertEquals("Care-team goal", ready.snapshot.pathwayGoals.single().authorLabel)
        assertEquals("Preparing for home", ready.snapshot.pathwayEducation.single().title)
        assertEquals("Admitted to the hospital", ready.snapshot.pathwayEvents?.events?.single()?.title)
        assertEquals("Test", ready.snapshot.pathwayEvents?.events?.single()?.category)
        assertEquals("Getting ready to leave", ready.snapshot.dischargeReadiness?.headline)
        assertEquals("Moving safely with the support you need", ready.snapshot.dischargeReadiness?.criteria?.single()?.label)
        assertEquals("Your care-team conversation", ready.snapshot.roundsSummary?.headline)
        assertEquals("How you are doing", ready.snapshot.roundsSummary?.topics?.single()?.title)
        assertEquals(
            "Your care team updated this information. Please use the details shown here.",
            ready.snapshot.contexts[PatientDestination.PATH]?.revisionNotice,
        )
        assertEquals(
            listOf(
                "Speak with your bedside nurse or another staff member.",
                "Use your bedside call button for urgent help.",
            ),
            ready.snapshot.careTeamCommunicationOptions,
        )
        assertEquals(
            "Ask your bedside staff to connect you.",
            ready.snapshot.careTeam.single().contactGuidance,
        )
        assertTrue(ready.snapshot.contexts.values.all { it.sourceLabel.startsWith("Source:") })
        assertEquals(1, api.passwordExchangeCalls)
    }

    @Test
    fun enrollmentUsesPatientChallengeAndPersistsSession() {
        val api = FakePatientApiGateway()
        val store = MemoryPatientCredentialStore()
        val outcome = coordinator(api, store).enroll(
            PatientEnrollmentRequest(
                challengeUuid = ENCOUNTER_UUID,
                challengeToken = "test-challenge-token-value".toCharArray(),
                verificationCode = "123456".toCharArray(),
                displayName = "Sample Patient",
                email = "patient@example.test",
                password = "test-password".toCharArray(),
            ),
        )

        assertTrue(outcome is PatientSessionOutcome.Ready)
        assertEquals(1, api.enrollmentCalls)
        assertEquals(CURRENT_SESSION_UUID, store.current?.sessionUuid)
    }

    @Test
    fun signInRevokesNewSessionAndClearsPartialStateWhenProtectedWriteFails() {
        val api = FakePatientApiGateway()
        val store = MemoryPatientCredentialStore(failWrites = true)

        val failure = runCatching {
            coordinator(api, store).signIn("patient@example.test", "test-password".toCharArray())
        }.exceptionOrNull()

        assertTrue(failure is IllegalStateException)
        assertEquals("Protected write failed", failure?.message)
        assertEquals(1, api.revokeCalls)
        assertEquals(1, store.clearCalls)
        assertNull(store.current)
        assertEquals(0, api.profileCalls)
    }

    @Test
    fun enrollmentRevokesNewSessionAndClearsPartialStateWhenProtectedWriteFails() {
        val api = FakePatientApiGateway()
        val store = MemoryPatientCredentialStore(failWrites = true)

        val failure = runCatching {
            coordinator(api, store).enroll(
                PatientEnrollmentRequest(
                    challengeUuid = ENCOUNTER_UUID,
                    challengeToken = "test-challenge-token-value".toCharArray(),
                    verificationCode = "123456".toCharArray(),
                    displayName = "Sample Patient",
                    email = "patient@example.test",
                    password = "test-password".toCharArray(),
                ),
            )
        }.exceptionOrNull()

        assertTrue(failure is IllegalStateException)
        assertEquals(1, api.revokeCalls)
        assertEquals(1, store.clearCalls)
        assertNull(store.current)
        assertEquals(0, api.profileCalls)
    }

    @Test
    fun restoreRefreshesOnceOnUnauthorizedAndPersistsRotatedTokens() {
        val api = FakePatientApiGateway()
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("expired-access", "stored-refresh", "old-session"),
        )

        val outcome = coordinator(api, store).restore()

        assertTrue(outcome is PatientSessionOutcome.Ready)
        assertEquals(1, api.refreshCalls)
        assertEquals("new-access", store.current?.accessToken)
        assertEquals("new-refresh", store.current?.refreshToken)
        assertEquals(2, api.profileCalls)
    }

    @Test
    fun rotatedRefreshSessionIsRevokedIfProtectedPersistenceFails() {
        val api = FakePatientApiGateway()
        val store = MemoryPatientCredentialStore(
            initial = PatientStoredCredentials("expired-access", "stored-refresh", "old-session"),
            failWrites = true,
        )

        val failure = runCatching { coordinator(api, store).restore() }.exceptionOrNull()

        assertTrue(failure is IllegalStateException)
        assertEquals(1, api.refreshCalls)
        assertEquals(1, api.revokeCalls)
        assertEquals(1, store.clearCalls)
        assertNull(store.current)
        assertEquals(1, api.profileCalls)
    }

    @Test
    fun invalidRefreshClearsExpiredCredentials() {
        val api = FakePatientApiGateway(refreshFailureStatus = 401)
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("expired-access", "invalid-refresh", "old-session"),
        )

        val failure = runCatching { coordinator(api, store).restore() }.exceptionOrNull()

        assertTrue(failure is PatientApiException)
        assertEquals(401, (failure as PatientApiException).statusCode)
        assertNull(store.current)
        assertEquals(1, store.clearCalls)
    }

    @Test
    fun transientRefreshFailurePreservesCredentialsForAConnectedRetry() {
        val api = FakePatientApiGateway(refreshFailureStatus = 503)
        val original = PatientStoredCredentials("expired-access", "stored-refresh", "old-session")
        val store = MemoryPatientCredentialStore(original)

        val failure = runCatching { coordinator(api, store).restore() }.exceptionOrNull()

        assertTrue(failure is PatientApiException)
        assertEquals(503, (failure as PatientApiException).statusCode)
        assertEquals(original, store.current)
        assertEquals(0, store.clearCalls)
    }

    @Test
    fun signOutClearsLocalCredentialsEvenWhenServerRevokeFails() {
        val api = FakePatientApiGateway(revokeFailure = true)
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("new-access", "new-refresh", CURRENT_SESSION_UUID),
        )

        val result = coordinator(api, store).signOut()

        assertFalse(result.serverRevoked)
        assertNull(store.current)
        assertEquals(1, store.clearCalls)
        assertEquals(1, api.revokeCalls)
    }

    @Test
    fun noEncounterReturnsExplicitEmptyState() {
        val api = FakePatientApiGateway(encounters = emptyList())
        val outcome = coordinator(api, MemoryPatientCredentialStore())
            .signIn("patient@example.test", "test-password".toCharArray())

        val empty = outcome as PatientSessionOutcome.Empty
        assertEquals("Sample Patient", empty.displayName)
        assertTrue(empty.message.contains("No active hospital stay"))
        assertEquals(0, api.todayCalls)
    }

    @Test
    fun unavailableReleasedSurfaceDoesNotCrossOrBreakOtherPatientSurfaces() {
        val api = FakePatientApiGateway(todayUnavailable = true)
        val ready = coordinator(api, MemoryPatientCredentialStore())
            .signIn("patient@example.test", "test-password".toCharArray()) as PatientSessionOutcome.Ready

        assertTrue(ready.snapshot.todayItems.isEmpty())
        assertEquals(1, ready.snapshot.pathway.size)
        assertEquals(1, ready.snapshot.careTeam.size)
    }

    @Test
    fun messagingOverviewUsesOnlyTheCurrentEncounterAndServerGuidance() {
        val api = FakePatientApiGateway()
        val coordinator = coordinator(
            api,
            MemoryPatientCredentialStore(
                PatientStoredCredentials("new-access", "new-refresh", CURRENT_SESSION_UUID),
            ),
        )

        val overview = coordinator.messagingOverview(ENCOUNTER_UUID)

        assertEquals("care_question", overview.topics.single().code)
        assertEquals("test-guidance-v1", overview.immediateHelp.version)
        assertEquals(1, overview.threads.size)
        assertEquals(1, api.messageTopicsCalls)
        assertEquals(1, api.messageThreadsCalls)
    }

    @Test
    fun messagingMutationsCreateDistinctUuidKeysAndCarryGuidanceAndThreadVersion() {
        val api = FakePatientApiGateway()
        val coordinator = coordinator(
            api,
            MemoryPatientCredentialStore(
                PatientStoredCredentials("new-access", "new-refresh", CURRENT_SESSION_UUID),
            ),
        )

        coordinator.createMessageThread(
            encounterUuid = ENCOUNTER_UUID,
            topicCode = "care_question",
            message = "Can someone explain today's plan?",
            urgentGuidanceVersion = "approved-guidance-v3",
        )
        coordinator.sendMessage(
            threadUuid = patientMessageThread().threadUuid,
            threadVersion = 7,
            message = "One more question.",
            urgentGuidanceVersion = "approved-guidance-v3",
        )
        coordinator.amendMessage(
            threadUuid = patientMessageThread().threadUuid,
            messageUuid = patientThreadMessage().messageUuid,
            threadVersion = 8,
            action = PatientMessageAmendmentAction.Correction,
            message = "Correction: please explain the timing first.",
            urgentGuidanceVersion = "approved-guidance-v3",
        )
        coordinator.closeMessageThread(patientMessageThread().threadUuid, 8)

        val create = api.createThreadRequests.single()
        val send = api.sendMessageRequests.single()
        val amend = api.amendMessageRequests.single()
        val close = api.closeThreadRequests.single()
        listOf(
            create.clientMessageUuid,
            create.idempotencyKey,
            send.clientMessageUuid,
            send.idempotencyKey,
            amend.clientMessageUuid,
            amend.idempotencyKey,
            close.idempotencyKey,
        ).forEach { value -> assertTrue(UUID_PATTERN.matches(value)) }
        assertNotEquals(create.clientMessageUuid, create.idempotencyKey)
        assertNotEquals(send.clientMessageUuid, send.idempotencyKey)
        assertEquals("approved-guidance-v3", create.urgentGuidanceVersion)
        assertEquals("approved-guidance-v3", send.urgentGuidanceVersion)
        assertEquals(7, send.threadVersion)
        assertEquals(PatientMessageAmendmentAction.Correction, amend.action)
        assertEquals("Correction: please explain the timing first.", amend.message)
        assertEquals(8, amend.threadVersion)
        assertEquals("approved-guidance-v3", amend.urgentGuidanceVersion)
        assertEquals(8, close.threadVersion)
        assertEquals("no_longer_needed", close.closeReason)
    }

    @Test
    fun rejectedMessageAccessRotatesOnceAndRetriesTheSameIdempotentMutation() {
        val api = FakePatientApiGateway(messageUnauthorizedOnce = true)
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("old-access", "stored-refresh", "old-session"),
        )

        val result = coordinator(api, store).sendMessage(
            threadUuid = patientMessageThread().threadUuid,
            threadVersion = 3,
            message = "Please explain the next step.",
            urgentGuidanceVersion = "test-guidance-v1",
        )

        assertEquals("Please explain the next step.", result.message.body)
        assertEquals(2, api.sendMessageCalls)
        assertEquals(api.sendMessageRequests[0], api.sendMessageRequests[1])
        assertEquals(1, api.refreshCalls)
        assertEquals("new-access", store.current?.accessToken)
        assertEquals("new-refresh", store.current?.refreshToken)
    }

    @Test
    fun deviceSessionListUsesExistingSingleRefreshBoundary() {
        val expected = patientDeviceSession()
        val api = FakePatientApiGateway(
            deviceSessions = listOf(expected),
            sessionListUnauthorizedOnce = true,
        )
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("expired-access", "stored-refresh", CURRENT_SESSION_UUID),
        )

        val result = coordinator(api, store).patientSessions()

        assertEquals(listOf(expected), result)
        assertEquals(2, api.patientSessionsCalls)
        assertEquals(1, api.refreshCalls)
        assertEquals("new-access", store.current?.accessToken)
    }

    @Test
    fun confirmedOtherDeviceDeleteRunsOnceAndKeepsCurrentCredentials() {
        val otherUuid = "019f4d7a-3200-7000-8000-000000000131"
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("new-access", "new-refresh", CURRENT_SESSION_UUID),
        )
        val api = FakePatientApiGateway()

        val outcome = coordinator(api, store).revokePatientSession(otherUuid)

        assertFalse(outcome.currentSessionRevoked)
        assertEquals(otherUuid, outcome.result.sessionUuid)
        assertEquals(listOf(otherUuid), api.revokedSessionUuids)
        assertEquals(1, api.revokePatientSessionCalls)
        assertEquals("new-access", store.current?.accessToken)
    }

    @Test
    fun confirmedCurrentDeviceDeleteClearsCredentialsBeforeReturning() {
        val currentUuid = "019f4d7a-3200-7000-8000-000000000131"
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("new-access", "new-refresh", currentUuid),
        )

        val outcome = coordinator(FakePatientApiGateway(), store)
            .revokePatientSession(currentUuid)

        assertTrue(outcome.currentSessionRevoked)
        assertNull(store.current)
        assertEquals(1, store.clearCalls)
    }

    @Test
    fun sessionManagementUnauthorizedClearsCredentials() {
        val sessionUuid = "019f4d7a-3200-7000-8000-000000000131"
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("new-access", "new-refresh", CURRENT_SESSION_UUID),
        )
        val api = FakePatientApiGateway(sessionRevokeFailureStatus = 401)

        val failure = runCatching {
            coordinator(api, store).revokePatientSession(sessionUuid)
        }.exceptionOrNull()

        assertTrue(failure is PatientApiException)
        assertNull(store.current)
        assertEquals(2, api.revokePatientSessionCalls)
        assertEquals(1, api.refreshCalls)
    }

    @Test
    fun transportOrServerDeleteFailureIsNeverBlindlyRetried() {
        val sessionUuid = "019f4d7a-3200-7000-8000-000000000131"
        val store = MemoryPatientCredentialStore(
            PatientStoredCredentials("new-access", "new-refresh", CURRENT_SESSION_UUID),
        )
        val api = FakePatientApiGateway(sessionRevokeFailureStatus = 503)

        val failure = runCatching {
            coordinator(api, store).revokePatientSession(sessionUuid)
        }.exceptionOrNull()

        assertTrue(failure is PatientApiException)
        assertEquals(1, api.revokePatientSessionCalls)
        assertEquals("new-access", store.current?.accessToken)
        assertEquals(0, api.refreshCalls)
    }

    private fun coordinator(
        api: PatientApiGateway,
        store: PatientCredentialStore,
    ) = PatientSessionCoordinator(
        api = api,
        credentials = store,
        device = PatientDeviceDescriptor(
            uuid = "019f4d7a-3200-7000-8000-000000000099",
            name = "Test device",
            appVersion = "test",
            osVersion = "15",
        ),
    )
}

private val UUID_PATTERN = Regex(
    "^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$",
)

internal class MemoryPatientCredentialStore(
    initial: PatientStoredCredentials? = null,
    private val failWrites: Boolean = false,
) : PatientCredentialStore {
    var current: PatientStoredCredentials? = initial
    var clearCalls = 0

    override fun read(): PatientStoredCredentials? = current

    override fun write(credentials: PatientStoredCredentials) {
        current = credentials
        if (failWrites) throw IllegalStateException("Protected write failed")
    }

    override fun clear() {
        clearCalls += 1
        current = null
    }

    override fun getOrCreateDeviceUuid(): String = "019f4d7a-3200-7000-8000-000000000098"
}

internal class FakePatientApiGateway(
    private val encounters: List<PatientEncounter> = listOf(patientEncounter()),
    private val todayUnavailable: Boolean = false,
    private val revokeFailure: Boolean = false,
    private val passwordFailureStatus: Int? = null,
    private val refreshFailureStatus: Int? = null,
    sendFailureCode: String? = null,
    private val messageUnauthorizedOnce: Boolean = false,
    private val refetchedThreadVersion: Int = 2,
    deviceSessions: List<PatientDeviceSession> = emptyList(),
    private val sessionListFailureStatus: Int? = null,
    private val sessionRevokeFailureStatus: Int? = null,
    sessionListUnauthorizedOnce: Boolean = false,
) : PatientApiGateway {
    var passwordExchangeCalls = 0
    var enrollmentCalls = 0
    var profileCalls = 0
    var refreshCalls = 0
    var revokeCalls = 0
    var todayCalls = 0
    var pathwayEventsCalls = 0
    var dischargeReadinessCalls = 0
    var roundsSummaryCalls = 0
    var messageTopicsCalls = 0
    var messageThreadsCalls = 0
    var messageThreadCalls = 0
    var createThreadCalls = 0
    var sendMessageCalls = 0
    var amendMessageCalls = 0
    var closeThreadCalls = 0
    var patientSessionsCalls = 0
    var revokePatientSessionCalls = 0
    val createThreadRequests = mutableListOf<PatientCreateThreadRequest>()
    val sendMessageRequests = mutableListOf<PatientSendMessageRequest>()
    val amendMessageRequests = mutableListOf<PatientAmendMessageRequest>()
    val closeThreadRequests = mutableListOf<PatientCloseThreadRequest>()
    val revokedSessionUuids = mutableListOf<String>()
    private var pendingSendFailureCode = sendFailureCode
    private var pendingMessageUnauthorized = messageUnauthorizedOnce
    private var pendingSessionListUnauthorized = sessionListUnauthorizedOnce
    private val activeDeviceSessions = deviceSessions.toMutableList()

    override fun exchangePassword(
        email: String,
        password: CharArray,
        device: PatientDeviceDescriptor,
    ): PatientEnvelope<PatientTokenPair> {
        passwordExchangeCalls += 1
        passwordFailureStatus?.let { status ->
            throw PatientApiException(status, "sign_in_failed", "Sign-in failed")
        }
        return tokenEnvelope()
    }

    override fun verifyEnrollment(
        request: PatientEnrollmentRequest,
        device: PatientDeviceDescriptor,
    ): PatientEnvelope<PatientTokenPair> {
        enrollmentCalls += 1
        return tokenEnvelope()
    }

    override fun profile(accessToken: CharArray): PatientEnvelope<PatientProfile> {
        profileCalls += 1
        if (accessToken.concatToString() == "expired-access") {
            throw PatientApiException(401, "invalid_access_token", "Unauthorized")
        }
        return patientEnvelope(
            PatientProfile(
                principalUuid = "019f4d7a-3200-7000-8000-000000000010",
                principalType = "patient",
                displayName = "Sample Patient",
                email = "patient@example.test",
                phoneE164 = null,
                emailVerified = true,
                phoneVerified = false,
                locale = "en-US",
                timezone = "America/New_York",
                preferences = PatientPreferences(
                    textSize = "standard",
                    reducedMotion = false,
                    highContrast = false,
                    notificationPreview = "hidden",
                    preferredChannel = "none",
                ),
            ),
        )
    }

    override fun updatePreferences(
        accessToken: CharArray,
        preferences: PatientPreferencesUpdate,
    ): PatientEnvelope<PatientProfile> = profile(accessToken)

    override fun patientSessions(
        accessToken: CharArray,
    ): PatientEnvelope<PatientDeviceSessionCollection> {
        patientSessionsCalls += 1
        if (pendingSessionListUnauthorized) {
            pendingSessionListUnauthorized = false
            throw PatientApiException(401, "invalid_access_token", "Unauthorized")
        }
        sessionListFailureStatus?.let { status ->
            throw PatientApiException(status, "sessions_unavailable", "Unavailable")
        }
        return patientEnvelope(PatientDeviceSessionCollection(activeDeviceSessions.toList()))
    }

    override fun revokePatientSession(
        accessToken: CharArray,
        sessionUuid: String,
    ): PatientEnvelope<PatientSessionRevocation> {
        revokePatientSessionCalls += 1
        revokedSessionUuids += sessionUuid
        sessionRevokeFailureStatus?.let { status ->
            throw PatientApiException(status, "session_revoke_failed", "Unavailable")
        }
        activeDeviceSessions.removeAll { it.sessionUuid == sessionUuid }
        return patientEnvelope(
            PatientSessionRevocation(
                sessionUuid = sessionUuid,
                revoked = true,
                alreadyRevoked = false,
            ),
        )
    }

    override fun encounters(accessToken: CharArray): PatientEnvelope<PatientEncounterCollection> =
        patientEnvelope(PatientEncounterCollection(encounters))

    override fun today(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientTodayContent>> {
        todayCalls += 1
        if (todayUnavailable) throw PatientApiException(404, "projection_not_available", "Not found")
        return patientEnvelope(
            projectionDocument(
                "today",
                PatientTodayContent(
                    headline = "Your plan for today",
                    summary = "Released summary.",
                    schedule = listOf(
                        PatientScheduleItem(
                            itemUuid = "019f4d7a-3200-7000-8000-000000000011",
                            label = "Care team rounds",
                            detail = "Review today’s care plan.",
                            status = "planned",
                            timeWindow = "This morning",
                            timingConfidence = "estimated",
                            preparation = null,
                            canChange = true,
                        ),
                    ),
                    nextSteps = listOf("Ask questions during rounds."),
                    notices = listOf("Use the call button for urgent help."),
                ),
            ),
        )
    }

    override fun pathway(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientPathwayContent>> = patientEnvelope(
        projectionDocument(
            "pathway",
            PatientPathwayContent(
                headline = "My Path",
                summary = "Released path.",
                currentStage = "Monitoring and treatment",
                stages = listOf(
                    PatientPathwayStage(
                        stageUuid = "019f4d7a-3200-7000-8000-000000000012",
                        title = "Monitoring and treatment",
                        status = "current",
                        summary = "Your team is checking your response.",
                        expectedRange = null,
                        timingConfidence = "estimated",
                        canChange = true,
                    ),
                ),
                milestones = listOf(
                    PatientPathwayMilestone(
                        milestoneUuid = "019f4d7a-3200-7000-8000-000000000021",
                        title = "Safe next step",
                        status = "planned",
                        detail = "Your team is preparing the next step.",
                        timing = null,
                        timingConfidence = "estimated",
                        canChange = true,
                    ),
                ),
                goals = listOf(
                    PatientPathwayGoal(
                        goalUuid = "019f4d7a-3200-7000-8000-000000000022",
                        authorType = "care_team",
                        label = "Get stronger safely",
                        explanation = "Review mobility needs with your team.",
                        status = "in_progress",
                        targetRange = null,
                    ),
                ),
                education = listOf(
                    PatientEducationItem(
                        itemUuid = "019f4d7a-3200-7000-8000-000000000023",
                        title = "Preparing for home",
                        summary = "Review the released next-step information.",
                    ),
                ),
                questions = emptyList(),
                notices = listOf("Timing can change."),
            ),
            revisionNotice = PatientProjectionRevisionNotice(
                kind = "correction",
                message = "Your care team updated this information. Please use the details shown here.",
            ),
        ),
    )

    override fun dischargeReadiness(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientDischargeReadinessContent>> {
        dischargeReadinessCalls += 1
        return patientEnvelope(
            projectionDocument(
                "discharge_readiness",
                PatientDischargeReadinessContent(
                    headline = "Getting ready to leave",
                    summary = "Your team will confirm the details before you leave.",
                    estimatedRange = "The next day or two",
                    estimatedConfidence = "estimated",
                    criteria = listOf(
                        PatientDischargeCriterion(
                            itemUuid = "019f4d7a-3200-7000-8000-000000000024",
                            label = "Moving safely with the support you need",
                            status = "pending",
                            detail = "Your team will review this with you each day.",
                        ),
                    ),
                    unresolvedNeeds = listOf("A ride home arranged for the day you leave."),
                    medications = listOf(
                        PatientDischargeMedication(
                            itemUuid = "019f4d7a-3200-7000-8000-000000000025",
                            name = "Your updated medicine list",
                            purpose = "Review each medicine with your care team.",
                        ),
                    ),
                    followUp = listOf(
                        PatientDischargeFollowUp(
                            itemUuid = "019f4d7a-3200-7000-8000-000000000026",
                            label = "Follow-up visit with your care team",
                            whenLabel = "Within a week or two of leaving",
                        ),
                    ),
                    warningSigns = listOf("Call your care team if symptoms get worse after you go home."),
                    contacts = listOf(
                        PatientDischargeContact(
                            itemUuid = "019f4d7a-3200-7000-8000-000000000027",
                            label = "Your care team",
                            route = "speak_with_bedside_staff",
                        ),
                    ),
                    questions = emptyList(),
                    notices = listOf("This is a summary; details can change."),
                ),
            ),
        )
    }

    override fun roundsSummary(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientRoundsSummaryContent>> {
        roundsSummaryCalls += 1
        return patientEnvelope(
            projectionDocument(
            "rounds_summary",
            PatientRoundsSummaryContent(
                headline = "Your care-team conversation",
                summary = "A released plain-language summary after your team reviewed your care.",
                roundWindow = "Earlier today",
                topics = listOf(
                    PatientRoundsTopic(
                        topicUuid = "019f4d7a-3200-7000-8000-000000000029",
                        title = "How you are doing",
                        summary = "Your team reviewed progress and next steps.",
                        status = "current",
                    ),
                ),
                nextSteps = listOf("Tell your bedside team what you would like explained."),
                questions = emptyList(),
                notices = listOf("This summary can change."),
            ),
            ),
        )
    }

    override fun pathwayEvents(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientPathwayEventsContent>> {
        pathwayEventsCalls += 1
        return patientEnvelope(
            projectionDocument(
                "pathway_events",
                PatientPathwayEventsContent(
                    headline = "What has happened so far",
                    summary = "A simple timeline of released key moments.",
                    events = listOf(
                        PatientPathwayEvent(
                            eventUuid = "019f4d7a-3200-7000-8000-000000000028",
                            title = "Admitted to the hospital",
                            whenLabel = "Two days ago",
                            status = "completed",
                            detail = "Your care team reviewed your history and started your plan.",
                            category = "test",
                        ),
                    ),
                    notices = listOf("This timeline is a summary and may not include every detail."),
                ),
            ),
        )
    }

    override fun careTeam(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientCareTeamContent>> = patientEnvelope(
        projectionDocument(
            "care_team",
            PatientCareTeamContent(
                headline = "Your care team",
                summary = "Released team.",
                members = listOf(
                    PatientCareTeamProjectionMember(
                        memberUuid = "019f4d7a-3200-7000-8000-000000000013",
                        displayName = "Care Coordinator",
                        role = "Care coordination",
                        service = "Hospital medicine",
                        responsibilities = listOf("Coordinates your care plan."),
                        contactRoute = "speak_with_bedside_staff",
                    ),
                ),
                communicationOptions = listOf(
                    "speak_with_bedside_staff",
                    "call_button_for_urgent_help",
                ),
                notices = listOf("No emergency messaging."),
            ),
        ),
    )

    override fun messageTopics(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientMessageTopics> {
        messageTopicsCalls += 1
        return patientEnvelope(
            PatientMessageTopics(
                topics = listOf(patientMessageTopic()),
                immediateHelp = patientImmediateHelp(),
            ),
        )
    }

    override fun messageThreads(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientMessageThreadCollection> {
        messageThreadsCalls += 1
        return patientEnvelope(
            PatientMessageThreadCollection(
                threads = listOf(patientMessageThread()),
                immediateHelp = patientImmediateHelp(),
            ),
        )
    }

    override fun createMessageThread(
        accessToken: CharArray,
        encounterUuid: String,
        request: PatientCreateThreadRequest,
    ): PatientEnvelope<PatientThreadResult> {
        createThreadCalls += 1
        createThreadRequests += request
        return patientEnvelope(PatientThreadResult(patientMessageThread()))
    }

    override fun messageThread(
        accessToken: CharArray,
        threadUuid: String,
    ): PatientEnvelope<PatientThreadResult> {
        messageThreadCalls += 1
        return patientEnvelope(
            PatientThreadResult(
                if (messageThreadCalls == 1) {
                    patientMessageThread()
                } else {
                    patientMessageThread().copy(version = refetchedThreadVersion)
                },
            ),
        )
    }

    override fun sendMessage(
        accessToken: CharArray,
        threadUuid: String,
        request: PatientSendMessageRequest,
    ): PatientEnvelope<PatientMessageResult> {
        sendMessageCalls += 1
        sendMessageRequests += request
        if (pendingMessageUnauthorized) {
            pendingMessageUnauthorized = false
            throw PatientApiException(401, "invalid_access_token", "Rejected test token")
        }
        pendingSendFailureCode?.let { code ->
            pendingSendFailureCode = null
            throw PatientApiException(409, code, "Internal test detail that must stay hidden")
        }
        return patientEnvelope(
            PatientMessageResult(
                thread = patientMessageThread().copy(version = request.threadVersion + 1),
                message = patientThreadMessage(body = request.message),
            ),
        )
    }

    override fun amendMessage(
        accessToken: CharArray,
        threadUuid: String,
        messageUuid: String,
        request: PatientAmendMessageRequest,
    ): PatientEnvelope<PatientMessageResult> {
        amendMessageCalls += 1
        amendMessageRequests += request
        return patientEnvelope(
            PatientMessageResult(
                thread = patientMessageThread().copy(version = request.threadVersion + 1),
                message = PatientThreadMessage(
                    messageUuid = "019f4d7a-3200-7000-8000-000000000042",
                    senderDisplayRole = "You",
                    messageKind = request.action.wireValue,
                    body = request.message,
                    relatesToMessageUuid = messageUuid,
                    deliveryState = "sent",
                    sentAt = "2026-07-19T12:01:00Z",
                ),
            ),
        )
    }

    override fun closeMessageThread(
        accessToken: CharArray,
        threadUuid: String,
        request: PatientCloseThreadRequest,
    ): PatientEnvelope<PatientThreadResult> {
        closeThreadCalls += 1
        closeThreadRequests += request
        return patientEnvelope(
            PatientThreadResult(
                patientMessageThread().copy(
                    status = "closed",
                    ownershipState = "closed",
                    version = request.threadVersion + 1,
                    closeReason = request.closeReason,
                ),
            ),
        )
    }

    override fun refresh(refreshToken: CharArray): PatientEnvelope<PatientTokenPair> {
        refreshCalls += 1
        refreshFailureStatus?.let { status ->
            throw PatientApiException(status, "refresh_failed", "Refresh failed")
        }
        return tokenEnvelope()
    }

    override fun revoke(accessOrRefreshToken: CharArray) {
        revokeCalls += 1
        if (revokeFailure) throw PatientApiException(503, "unavailable", "Unavailable")
    }
}

internal const val ENCOUNTER_UUID = "019f4d7a-3200-7000-8000-000000000020"
internal const val CURRENT_SESSION_UUID = "019f4d7a-3200-7000-8000-000000000130"

internal fun patientEncounter(): PatientEncounter = PatientEncounter(
    encounterUuid = ENCOUNTER_UUID,
    grantUuid = "019f4d7a-3200-7000-8000-000000000021",
    relationship = "self",
    scopes = listOf("today:read", "pathway:read", "care_team:read"),
    validFrom = "2026-07-19T11:00:00Z",
    expiresAt = null,
    version = 1,
)

internal fun patientDeviceSession(
    sessionUuid: String = "019f4d7a-3200-7000-8000-000000000131",
    current: Boolean = false,
    name: String? = "Test phone",
) = PatientDeviceSession(
    sessionUuid = sessionUuid,
    current = current,
    status = "active",
    device = PatientSessionDevice(
        uuid = "019f4d7a-3200-7000-8000-000000000132",
        platform = "android",
        name = name,
        appVersion = "0.1.0",
        osVersion = "15",
    ),
    authMethod = "password",
    assuranceLevel = "aal1",
    lastSeenAt = "2026-07-20T08:00:00Z",
    expiresAt = "2026-07-21T08:00:00Z",
    createdAt = "2026-07-19T08:00:00Z",
)

internal fun tokenEnvelope(): PatientEnvelope<PatientTokenPair> = patientEnvelope(
    PatientTokenPair(
        tokenType = "Bearer",
        accessToken = "new-access",
        refreshToken = "new-refresh",
        expiresInSeconds = 900,
        sessionUuid = CURRENT_SESSION_UUID,
        abilities = listOf("patient:access"),
    ),
)

internal fun <T> patientEnvelope(data: T): PatientEnvelope<T> = PatientEnvelope(
    data = data,
    meta = PatientEnvelopeMeta(
        asOf = "2026-07-19T12:00:00Z",
        stale = false,
        version = 1,
        sourceFreshness = PatientSourceFreshness("current", "2026-07-19T11:59:00Z"),
        policyVersion = "patient-disclosure-v1",
    ),
    links = emptyMap(),
)

internal fun patientImmediateHelp(): PatientImmediateHelp = PatientImmediateHelp(
    version = "test-guidance-v1",
    text = "Use your bedside call button or tell a staff member for immediate help.",
)

internal fun patientMessageTopic(): PatientMessageTopic = PatientMessageTopic(
    code = "care_question",
    label = "Question for my care team",
    description = "Ask a non-urgent question.",
    expectedResponseWindow = "During this shift",
)

internal fun patientMessageThread(): PatientMessageThread = PatientMessageThread(
    threadUuid = "019f4d7a-3200-7000-8000-000000000040",
    topic = PatientMessageThreadTopic(
        code = "care_question",
        label = "Question for my care team",
        description = "Ask a non-urgent question.",
    ),
    status = "open",
    ownershipState = "awaiting_team",
    expectedResponseWindow = "During this shift",
    version = 1,
    lastMessageAt = "2026-07-19T12:00:00Z",
    createdAt = "2026-07-19T12:00:00Z",
    closedAt = null,
    closeReason = null,
    messages = listOf(patientThreadMessage()),
)

internal fun patientThreadMessage(
    body: String = "Can you explain today's plan?",
): PatientThreadMessage = PatientThreadMessage(
    messageUuid = "019f4d7a-3200-7000-8000-000000000041",
    senderDisplayRole = "You",
    messageKind = "message",
    body = body,
    relatesToMessageUuid = null,
    deliveryState = "sent",
    sentAt = "2026-07-19T12:00:00Z",
)

internal fun <T> projectionDocument(
    kind: String,
    content: T,
    revisionNotice: PatientProjectionRevisionNotice? = null,
): PatientProjectionDocument<T> =
    PatientProjectionDocument(
        projectionUuid = "019f4d7a-3200-7000-8000-000000000030",
        encounterUuid = ENCOUNTER_UUID,
        kind = kind,
        content = content,
        uncertainty = PatientProjectionUncertainty(
            level = "medium",
            explanation = "Timing can change as your care needs change.",
            canChange = true,
            reviewedAt = "2026-07-19T11:58:00Z",
        ),
        provenance = PatientProjectionProvenance(
            projectionMethod = "governed_projection",
            sourceClass = "inpatient_care_plan",
            inputClasses = listOf("care_plan"),
            reviewState = "clinically_reviewed",
            producerVersion = "v1",
        ),
        revisionNotice = revisionNotice,
        observedAt = "2026-07-19T11:59:00Z",
        generatedAt = "2026-07-19T11:59:30Z",
        releasedAt = "2026-07-19T12:00:00Z",
    )
