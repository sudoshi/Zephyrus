package net.acumenus.hummingbird.patient

import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import net.acumenus.hummingbird.patient.data.FakePatientApiGateway
import net.acumenus.hummingbird.patient.data.CURRENT_SESSION_UUID
import net.acumenus.hummingbird.patient.data.MemoryPatientCredentialStore
import net.acumenus.hummingbird.patient.data.PatientDeviceDescriptor
import net.acumenus.hummingbird.patient.data.PatientMessageAmendmentAction
import net.acumenus.hummingbird.patient.data.PatientSessionCoordinator
import net.acumenus.hummingbird.patient.data.patientEncounter
import net.acumenus.hummingbird.patient.data.patientDeviceSession
import net.acumenus.hummingbird.patient.data.patientMessageThread
import net.acumenus.hummingbird.patient.data.patientThreadMessage
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class PatientAppViewModelTest {
    @Test
    fun startsSignedOutInInvitationMode() {
        val viewModel = PatientAppViewModel(apiEnabled = false)
        val session = viewModel.state.session as PatientSessionState.SignedOut
        assertEquals(PatientAuthMode.ENROLL, session.authMode)
        assertTrue(session.status is PatientAuthStatus.Idle)
    }

    @Test
    fun authModeSwitchClearsPriorStatus() {
        val viewModel = PatientAppViewModel(apiEnabled = false)
        viewModel.submitEnrollment(
            PatientEnrollmentForm("", "", "", "", "", "", ""),
        )
        viewModel.selectAuthMode(PatientAuthMode.SIGN_IN)

        val session = viewModel.state.session as PatientSessionState.SignedOut
        assertEquals(PatientAuthMode.SIGN_IN, session.authMode)
        assertTrue(session.status is PatientAuthStatus.Idle)
    }

    @Test
    fun disabledNetworkFailsClosedWithPlainLanguageStatus() {
        val viewModel = PatientAppViewModel(apiEnabled = false)
        viewModel.submitSignIn("patient@example.test", "test-only")

        val status = (viewModel.state.session as PatientSessionState.SignedOut).status
        assertTrue(status is PatientAuthStatus.Unavailable)
        assertTrue((status as PatientAuthStatus.Unavailable).message.contains("not enabled"))
    }

    @Test
    fun navigationIsIgnoredWhileSignedOut() {
        val signedOut = PatientAppViewModel(apiEnabled = false)
        signedOut.selectDestination(PatientDestination.CARE_TEAM)
        assertEquals(PatientDestination.TODAY, signedOut.state.destination)
    }

    @Test
    fun enabledCredentialSignInTransitionsThroughLoadingToReleasedPatientData() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(FakePatientApiGateway()),
            scope = this,
            workDispatcher = dispatcher,
        )

        viewModel.selectAuthMode(PatientAuthMode.SIGN_IN)
        viewModel.submitSignIn("patient@example.test", "test-password")
        assertTrue(viewModel.state.session is PatientSessionState.Loading)

        advanceUntilIdle()

        val ready = viewModel.state.session as PatientSessionState.Ready
        assertEquals("Sample Patient", ready.snapshot.patientDisplayName)
        assertTrue(!ready.synthetic)
        assertTrue(viewModel.state.messaging is PatientMessagingState.Unavailable)
    }

    @Test
    fun rejectedCredentialSignInReturnsPatientSafeFailure() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(FakePatientApiGateway(passwordFailureStatus = 401)),
            scope = this,
            workDispatcher = dispatcher,
        )

        viewModel.submitSignIn("patient@example.test", "wrong-password")
        advanceUntilIdle()

        val signedOut = viewModel.state.session as PatientSessionState.SignedOut
        val failure = signedOut.status as PatientAuthStatus.Failure
        assertTrue(failure.message.contains("could not be verified"))
        assertTrue(!failure.message.contains("wrong-password"))
    }

    @Test
    fun enabledRestoreFailsClosedWhenProtectedStorageCouldNotInitialize() {
        val viewModel = PatientAppViewModel(apiEnabled = true, coordinator = null)

        viewModel.restoreSession()

        val status = (viewModel.state.session as PatientSessionState.SignedOut).status
        assertTrue(status is PatientAuthStatus.Unavailable)
        assertTrue((status as PatientAuthStatus.Unavailable).message.contains("storage is unavailable"))
    }

    @Test
    fun messagingLoadsServerGuidanceAndSendsANewThreadWithoutAnOfflineQueue() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(
            encounters = listOf(
                patientEncounter().copy(
                    scopes = patientEncounter().scopes + listOf("messaging:read", "messaging:write"),
                ),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )

        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()

        val loaded = viewModel.state.messaging as PatientMessagingState.Ready
        assertEquals("test-guidance-v1", loaded.immediateHelp.version)
        assertTrue(loaded.immediateHelp.text.contains("call button"))
        viewModel.createMessageThread("care_question", "Please explain today's plan.")
        assertTrue(
            (viewModel.state.messaging as PatientMessagingState.Ready).operation is
                PatientMessagingOperation.Working,
        )
        advanceUntilIdle()

        val finished = viewModel.state.messaging as PatientMessagingState.Ready
        assertTrue(finished.operation is PatientMessagingOperation.Notice)
        assertEquals(1, api.createThreadCalls)
        assertEquals("test-guidance-v1", api.createThreadRequests.single().urgentGuidanceVersion)
        assertEquals("Please explain today's plan.", api.createThreadRequests.single().message)
    }

    @Test
    fun staleThreadVersionRefetchesBeforeAllowingAnotherReplyAndHidesDiagnostics() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(
            encounters = listOf(
                patientEncounter().copy(
                    scopes = patientEncounter().scopes + listOf("messaging:read", "messaging:write"),
                ),
            ),
            sendFailureCode = "stale_thread_version",
            refetchedThreadVersion = 5,
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.selectMessageThread(patientMessageThread().threadUuid)
        advanceUntilIdle()

        assertEquals(
            1,
            (viewModel.state.messaging as PatientMessagingState.Ready).selectedThread?.version,
        )
        viewModel.sendMessage("Could you clarify that?")
        advanceUntilIdle()

        val messaging = viewModel.state.messaging as PatientMessagingState.Ready
        val failure = messaging.operation as PatientMessagingOperation.Failure
        assertEquals(5, messaging.selectedThread?.version)
        assertTrue(failure.message.contains("changed while you were viewing"))
        assertTrue(!failure.message.contains("Internal test detail"))
        assertEquals(2, api.messageThreadCalls)
        assertEquals(1, api.sendMessageCalls)
    }

    @Test
    fun correctionAppendsAnImmutableFactAndPreventsAnotherAmendmentOfTheSameSource() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(
            encounters = listOf(
                patientEncounter().copy(
                    scopes = patientEncounter().scopes + listOf("messaging:read", "messaging:write"),
                ),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.selectMessageThread(patientMessageThread().threadUuid)
        advanceUntilIdle()

        val sourceUuid = patientThreadMessage().messageUuid
        viewModel.amendMessage(
            messageUuid = sourceUuid,
            action = PatientMessageAmendmentAction.Correction,
            message = "Correction: please explain the timing first.",
        )
        advanceUntilIdle()

        val messaging = viewModel.state.messaging as PatientMessagingState.Ready
        assertEquals(1, api.amendMessageCalls)
        assertEquals(PatientMessageAmendmentAction.Correction, api.amendMessageRequests.single().action)
        assertEquals("Correction: please explain the timing first.", api.amendMessageRequests.single().message)
        assertEquals("correction", messaging.selectedThread?.messages?.last()?.messageKind)
        assertEquals(sourceUuid, messaging.selectedThread?.messages?.last()?.relatesToMessageUuid)

        viewModel.amendMessage(
            messageUuid = sourceUuid,
            action = PatientMessageAmendmentAction.Retraction,
        )
        advanceUntilIdle()
        assertEquals(1, api.amendMessageCalls)
        assertTrue((viewModel.state.messaging as PatientMessagingState.Ready).operation is PatientMessagingOperation.Failure)
    }

    @Test
    fun invalidMessageNeverCallsTheNetwork() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(
            encounters = listOf(
                patientEncounter().copy(
                    scopes = patientEncounter().scopes + listOf("messaging:read", "messaging:write"),
                ),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()

        viewModel.createMessageThread("care_question", "   ")

        assertEquals(0, api.createThreadCalls)
        val operation = (viewModel.state.messaging as PatientMessagingState.Ready).operation
        assertTrue(operation is PatientMessagingOperation.Failure)
    }

    @Test
    fun deviceSessionsLoadOnlyAfterExplicitManageDevicesOpen() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(
            deviceSessions = listOf(patientDeviceSession(current = true)),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )

        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        assertEquals(0, api.patientSessionsCalls)

        viewModel.openDeviceSessions()
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Loading)
        advanceUntilIdle()

        val devices = viewModel.state.deviceSessions as PatientDeviceSessionsState.Ready
        assertEquals(1, devices.sessions.size)
        assertEquals(1, api.patientSessionsCalls)
    }

    @Test
    fun otherDeviceDeleteIsExplicitAndReconcilesWithFreshServerList() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val otherUuid = "019f4d7a-3200-7000-8000-000000000131"
        val api = FakePatientApiGateway(
            deviceSessions = listOf(
                patientDeviceSession(sessionUuid = CURRENT_SESSION_UUID, current = true),
                patientDeviceSession(sessionUuid = otherUuid, name = "Family tablet"),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.openDeviceSessions()
        advanceUntilIdle()

        viewModel.selectDeviceSessionForRevocation(otherUuid)
        assertEquals(0, api.revokePatientSessionCalls)
        viewModel.confirmDeviceSessionRevocation()
        advanceUntilIdle()

        val devices = viewModel.state.deviceSessions as PatientDeviceSessionsState.Ready
        assertEquals(listOf(CURRENT_SESSION_UUID), devices.sessions.map { it.sessionUuid })
        assertTrue(devices.operation is PatientDeviceSessionOperation.Notice)
        assertEquals(1, api.revokePatientSessionCalls)
        assertEquals(2, api.patientSessionsCalls)
    }

    @Test
    fun currentDeviceDeleteClearsCredentialsAndAllPatientState() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val store = MemoryPatientCredentialStore()
        val api = FakePatientApiGateway(
            deviceSessions = listOf(
                patientDeviceSession(sessionUuid = CURRENT_SESSION_UUID, current = true),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api, store),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.openDeviceSessions()
        advanceUntilIdle()
        viewModel.selectDeviceSessionForRevocation(CURRENT_SESSION_UUID)
        viewModel.confirmDeviceSessionRevocation()
        advanceUntilIdle()

        assertTrue(viewModel.state.session is PatientSessionState.SignedOut)
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
        assertTrue(viewModel.state.messaging is PatientMessagingState.Hidden)
        assertEquals(null, store.current)
        assertEquals(1, api.revokePatientSessionCalls)
    }

    @Test
    fun featureDisabledSessionManagementLeavesCorePatientExperienceReady() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(sessionListFailureStatus = 404)
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()

        viewModel.openDeviceSessions()
        advanceUntilIdle()

        assertTrue(viewModel.state.session is PatientSessionState.Ready)
        val unavailable = viewModel.state.deviceSessions as PatientDeviceSessionsState.Unavailable
        assertTrue(unavailable.message.contains("not available right now"))
        assertTrue(!unavailable.canRetry)
        viewModel.dismissDeviceSessions()
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
        assertTrue(viewModel.state.session is PatientSessionState.Ready)
    }

    @Test
    fun backgroundClearsDeviceRowsErrorsAndSelectionWithoutAnotherRequest() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val api = FakePatientApiGateway(
            deviceSessions = listOf(patientDeviceSession()),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.openDeviceSessions()
        advanceUntilIdle()
        viewModel.selectDeviceSessionForRevocation(
            "019f4d7a-3200-7000-8000-000000000131",
        )

        viewModel.onAppBackgrounded()

        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
        assertEquals(1, api.patientSessionsCalls)
    }

    @Test
    fun backgroundAfterConfirmedCurrentDeleteStillClearsCredentialsAndPatientState() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val store = MemoryPatientCredentialStore()
        val api = FakePatientApiGateway(
            deviceSessions = listOf(
                patientDeviceSession(sessionUuid = CURRENT_SESSION_UUID, current = true),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api, store),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.openDeviceSessions()
        advanceUntilIdle()
        viewModel.selectDeviceSessionForRevocation(CURRENT_SESSION_UUID)

        viewModel.confirmDeviceSessionRevocation()
        viewModel.onAppBackgrounded()
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
        advanceUntilIdle()

        assertEquals(1, api.revokePatientSessionCalls)
        assertEquals(null, store.current)
        assertTrue(viewModel.state.session is PatientSessionState.SignedOut)
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
    }

    @Test
    fun backgroundAfterConfirmedOtherDeleteSkipsBackgroundListRefresh() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val otherUuid = "019f4d7a-3200-7000-8000-000000000131"
        val api = FakePatientApiGateway(
            deviceSessions = listOf(
                patientDeviceSession(sessionUuid = CURRENT_SESSION_UUID, current = true),
                patientDeviceSession(sessionUuid = otherUuid),
            ),
        )
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()
        viewModel.openDeviceSessions()
        advanceUntilIdle()
        viewModel.selectDeviceSessionForRevocation(otherUuid)

        viewModel.confirmDeviceSessionRevocation()
        viewModel.onAppBackgrounded()
        advanceUntilIdle()

        assertEquals(1, api.revokePatientSessionCalls)
        assertEquals(1, api.patientSessionsCalls)
        assertTrue(viewModel.state.session is PatientSessionState.Ready)
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
    }

    @Test
    fun unauthorizedSessionManagementClearsTokensAndReturnsSignedOut() = runTest {
        val dispatcher = StandardTestDispatcher(testScheduler)
        val store = MemoryPatientCredentialStore()
        val api = FakePatientApiGateway(sessionListFailureStatus = 401)
        val viewModel = PatientAppViewModel(
            apiEnabled = true,
            coordinator = coordinator(api, store),
            scope = this,
            workDispatcher = dispatcher,
        )
        viewModel.submitSignIn("patient@example.test", "test-password")
        advanceUntilIdle()

        viewModel.openDeviceSessions()
        advanceUntilIdle()

        assertTrue(viewModel.state.session is PatientSessionState.SignedOut)
        assertEquals(null, store.current)
        assertTrue(viewModel.state.deviceSessions is PatientDeviceSessionsState.Hidden)
    }

    private fun coordinator(
        api: FakePatientApiGateway,
        store: MemoryPatientCredentialStore = MemoryPatientCredentialStore(),
    ): PatientSessionCoordinator =
        PatientSessionCoordinator(
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
