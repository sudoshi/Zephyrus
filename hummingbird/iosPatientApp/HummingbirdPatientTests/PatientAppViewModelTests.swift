import XCTest
@testable import HummingbirdPatient

@MainActor
final class PatientAppViewModelTests: XCTestCase {
    func testSyntheticReferenceIsClearlyLabeledAndContainsUncertaintyAndProvenance() {
        let snapshot = PatientExperienceSnapshot.syntheticReference(now: Date(timeIntervalSince1970: 1_700_000_000))

        XCTAssertTrue(snapshot.isSynthetic)
        XCTAssertTrue(snapshot.sourceDescription.lowercased().contains("synthetic"))
        XCTAssertTrue(snapshot.todayItems.contains(where: { $0.certainty == .beingClarified }))
        XCTAssertTrue(snapshot.todayItems.allSatisfy { !$0.provenance.isEmpty })
        XCTAssertTrue(snapshot.pathwayStages.contains(where: { $0.state == .notScheduled }))
        XCTAssertTrue(snapshot.pathwayStages.allSatisfy { !$0.provenance.isEmpty })
        XCTAssertEqual(snapshot.pathwayMilestones.first?.title, "Review medicines before your next setting")
        XCTAssertEqual(snapshot.pathwayGoals.map(\.authorType), ["care_team", "patient"])
        XCTAssertEqual(snapshot.pathwayEducation.first?.title, "Preparing for the next setting")
        XCTAssertEqual(snapshot.pathwayEvents?.events?.first?.title, "Admitted to the hospital")
        XCTAssertEqual(snapshot.pathwayEvents?.events?.first?.category, .other)
        XCTAssertEqual(snapshot.roundsSummary?.topics?.first?.title, "How you are feeling and responding to care")
        XCTAssertEqual(snapshot.pathwayRevisionNotice?.kind, .correction)
        XCTAssertTrue(snapshot.careTeam.allSatisfy { !$0.provenance.isEmpty })
    }

    func testSyntheticReferencePreferencesStayNonClinicalAndNeverWriteAnAccount() async {
        let viewModel = PatientAppViewModel(
            configuration: .disabled,
            api: nil,
            tokenStore: InMemoryPatientTokenStore()
        )
        viewModel.activateSyntheticReference()

        let saved = await viewModel.savePreferences(
            PatientPreferencesInput(
                textSize: .extraLarge,
                reducedMotion: true,
                highContrast: true,
                notificationPreview: .generic,
                preferredChannel: .email
            )
        )

        XCTAssertTrue(saved)
        XCTAssertEqual(viewModel.patientPreferences.textSize, .extraLarge)
        XCTAssertEqual(viewModel.patientPreferences.preferredChannel, .email)
        XCTAssertTrue(viewModel.preferencesMessage?.contains("No patient account was changed") == true)
    }

    func testLiveSnapshotDoesNotInventClinicalDetailsFromEncounterHandles() {
        let snapshot = PatientExperienceSnapshot.live(
            profile: PatientFixtures.profile,
            encounters: PatientFixtures.encounters
        )

        XCTAssertFalse(snapshot.isSynthetic)
        XCTAssertFalse(snapshot.hasTodayProjection)
        XCTAssertFalse(snapshot.hasPathwayProjection)
        XCTAssertFalse(snapshot.hasCareTeamProjection)
        XCTAssertTrue(snapshot.todayItems.isEmpty)
        XCTAssertTrue(snapshot.pathwayStages.isEmpty)
        XCTAssertTrue(snapshot.careTeam.isEmpty)
        XCTAssertTrue(snapshot.todaySummary.contains("No patient-facing plan"))
        XCTAssertTrue(snapshot.pathwaySummary.contains("No patient-facing pathway"))
        XCTAssertTrue(snapshot.careTeamSummary.contains("No patient-facing care-team"))
        XCTAssertTrue(snapshot.sourceLimitation.contains("Only information released"))
    }

    func testDefaultViewModelCannotAuthenticateWithoutExplicitAPIConfiguration() async {
        let store = InMemoryPatientTokenStore()
        let viewModel = PatientAppViewModel(
            configuration: .disabled,
            api: nil,
            tokenStore: store
        )

        await viewModel.signIn(email: "sample@example.test", password: "not-a-real-secret")

        XCTAssertNil(viewModel.snapshot)
        XCTAssertEqual(viewModel.errorMessage, "Patient API access is not configured for this build.")
        XCTAssertNil(store.accessToken)
    }

    func testBootstrapRefreshesUnauthorizedAccessTokenAndLoadsAllReleasedProjections() async {
        let api = MockPatientAPI(profileUnauthorizedOnce: true)
        let store = InMemoryPatientTokenStore(accessToken: "expired-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        XCTAssertEqual(store.accessToken, "rotated-access")
        XCTAssertEqual(store.refreshToken, "rotated-refresh")
        XCTAssertEqual(viewModel.snapshot?.patientName, "Sam Example")
        XCTAssertEqual(viewModel.snapshot?.todayItems.first?.title, "Care team rounds")
        XCTAssertEqual(viewModel.snapshot?.pathwayStages.first?.title, "Getting stronger")
        XCTAssertEqual(viewModel.snapshot?.pathwayMilestones.first?.title, "Review medicines before discharge")
        XCTAssertEqual(viewModel.snapshot?.pathwayGoals.first?.authorType, "care_team")
        XCTAssertEqual(viewModel.snapshot?.pathwayEducation.first?.title, "Preparing for home")
        XCTAssertEqual(viewModel.snapshot?.pathwayEvents?.events?.first?.title, "Admitted to the hospital")
        XCTAssertEqual(viewModel.snapshot?.dischargeReadiness?.headline, "Getting ready to leave")
        XCTAssertEqual(viewModel.snapshot?.roundsSummary?.headline, "Your care-team conversation")
        XCTAssertEqual(viewModel.snapshot?.pathwayRevisionNotice?.kind, .correction)
        XCTAssertEqual(
            viewModel.snapshot?.pathwayRevisionNotice?.message,
            "Your care team updated this information. Please use the details shown here."
        )
        XCTAssertEqual(
            viewModel.snapshot?.dischargeReadiness?.criteria?.first?.label,
            "Moving safely with the support you need"
        )
        XCTAssertEqual(viewModel.snapshot?.careTeam.first?.name, "Jordan Lee, RN")
        XCTAssertTrue(viewModel.snapshot?.hasTodayProjection == true)
        XCTAssertTrue(viewModel.snapshot?.hasPathwayProjection == true)
        XCTAssertTrue(viewModel.snapshot?.hasCareTeamProjection == true)
        XCTAssertNil(viewModel.errorMessage)

        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.refreshTokens, ["valid-refresh"])
        XCTAssertEqual(calls.profileTokens, ["expired-access", "rotated-access"])
        XCTAssertEqual(calls.todayTokens, ["rotated-access"])
        XCTAssertEqual(calls.pathwayTokens, ["rotated-access"])
        XCTAssertEqual(calls.pathwayEventsTokens, ["rotated-access"])
        XCTAssertEqual(calls.dischargeReadinessTokens, ["rotated-access"])
        XCTAssertEqual(calls.roundsSummaryTokens, ["rotated-access"])
        XCTAssertEqual(calls.careTeamTokens, ["rotated-access"])
    }

    func testMissingReleasedProjectionBecomesAnExplicitEmptySection() async {
        let api = MockPatientAPI(missingToday: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        XCTAssertFalse(try XCTUnwrap(viewModel.snapshot).hasTodayProjection)
        XCTAssertTrue(viewModel.snapshot?.todayItems.isEmpty == true)
        XCTAssertTrue(viewModel.snapshot?.todaySummary.contains("No patient-facing plan") == true)
        XCTAssertTrue(viewModel.snapshot?.hasPathwayProjection == true)
        XCTAssertTrue(viewModel.snapshot?.hasCareTeamProjection == true)
        XCTAssertNil(viewModel.errorMessage)
    }

    func testLoginStoresPatientTokenPairAndLoadsThePatientExperience() async {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore()
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.signIn(email: "  SAMPLE@EXAMPLE.TEST ", password: "patient-password")

        XCTAssertEqual(store.accessToken, "login-access")
        XCTAssertEqual(store.refreshToken, "login-refresh")
        XCTAssertNotNil(viewModel.snapshot)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.signIns, ["sample@example.test"])
        XCTAssertEqual(calls.profileTokens, ["login-access"])
    }

    func testEnrollmentStoresPatientTokenPairAndLoadsThePatientExperience() async {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore()
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        let input = PatientEnrollmentInput(
            challengeUUID: "019f0000-0000-7000-8000-000000000051",
            challengeToken: "one-use-challenge",
            verificationCode: "438201",
            displayName: "Sam Example",
            email: "sample@example.test",
            password: "patient-password",
            passwordConfirmation: "patient-password"
        )

        await viewModel.enroll(input)

        XCTAssertEqual(store.accessToken, "enrollment-access")
        XCTAssertEqual(store.refreshToken, "enrollment-refresh")
        XCTAssertNotNil(viewModel.snapshot)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.enrollmentChallenges, [input.challengeUUID])
        XCTAssertEqual(calls.profileTokens, ["enrollment-access"])
    }

    func testSignOutClearsLocalStateBeforeRevokingTheRefreshToken() async {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore(accessToken: "access", refreshToken: "refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.signOut()

        XCTAssertNil(store.accessToken)
        XCTAssertNil(store.refreshToken)
        XCTAssertNil(viewModel.snapshot)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.revokedTokens, ["refresh"])
    }

    func testUnauthorizedRefreshExpiresAndClearsTheLocalSession() async {
        let api = MockPatientAPI(profileUnauthorizedOnce: true, refreshUnauthorized: true)
        let store = InMemoryPatientTokenStore(accessToken: "expired-access", refreshToken: "expired-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        XCTAssertNil(store.accessToken)
        XCTAssertNil(store.refreshToken)
        XCTAssertNil(viewModel.snapshot)
        XCTAssertEqual(viewModel.errorMessage, "Your secure session ended. Sign in again to continue.")
    }

    func testInitialAuthenticationRevokesIssuedSessionWhenSecureStorageFails() async {
        let api = MockPatientAPI()
        let store = FailingPatientTokenStore()
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.signIn(email: "sample@example.test", password: "patient-password")

        XCTAssertNil(store.accessToken)
        XCTAssertNil(store.refreshToken)
        XCTAssertGreaterThanOrEqual(store.clearCount, 1)
        XCTAssertNil(viewModel.snapshot)
        XCTAssertEqual(
            viewModel.errorMessage,
            "We could not protect your secure session on this device. No session was kept. Please try again."
        )
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.revokedTokens, ["login-refresh"])
        XCTAssertTrue(calls.profileTokens.isEmpty)
    }

    func testRefreshRevokesRotatedSessionAndClearsExistingTokensWhenSecureStorageFails() async {
        let api = MockPatientAPI(profileUnauthorizedOnce: true)
        let store = FailingPatientTokenStore(
            accessToken: "expired-access",
            refreshToken: "existing-refresh"
        )
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        XCTAssertNil(store.accessToken)
        XCTAssertNil(store.refreshToken)
        XCTAssertNil(viewModel.snapshot)
        XCTAssertEqual(
            viewModel.errorMessage,
            "We could not protect your secure session on this device. No session was kept. Please try again."
        )
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.refreshTokens, ["existing-refresh"])
        XCTAssertEqual(calls.revokedTokens, ["rotated-refresh"])
    }

    func testMessagingOverviewLoadsOnlyTheServerGuidanceTopicsAndPatientVisibleThreads() async {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        guard case .ready(let overview) = viewModel.messagingState else {
            XCTFail("Expected a ready messaging overview")
            return
        }
        XCTAssertEqual(overview.immediateHelp, PatientFixtures.immediateHelp)
        XCTAssertEqual(overview.topics.map(\.code), ["care_plan_question"])
        XCTAssertEqual(overview.threads.map(\.threadUUID), [PatientFixtures.messageThreadSummary.threadUUID])
        XCTAssertEqual(overview.threads.first?.ownershipState.patientLabel, "Seen by your care team")
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.messageTopicTokens, ["valid-access"])
        XCTAssertEqual(calls.messageThreadListTokens, ["valid-access"])
    }

    func testMessagingCreateSendAndCloseUseFreshGuidanceThreadVersionsAndDistinctUUIDs() async {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()

        let created = await viewModel.createMessageThread(
            topicCode: PatientFixtures.messageTopic.code,
            message: "  What should I expect before my walk?  "
        )
        XCTAssertTrue(created)
        await viewModel.openMessageThread(threadUUID: PatientFixtures.messageThreadSummary.threadUUID)
        let sent = await viewModel.sendMessage(
            threadUUID: PatientFixtures.messageThreadSummary.threadUUID,
            message: "  Is there anything I should bring?  "
        )
        XCTAssertTrue(sent)
        let corrected = await viewModel.amendMessage(
            threadUUID: PatientFixtures.messageThreadSummary.threadUUID,
            messageUUID: PatientFixtures.patientVisibleMessage.messageUUID,
            action: .correction,
            message: "  Correction: please explain the safe timing first.  "
        )
        XCTAssertTrue(corrected)
        let closed = await viewModel.closeMessageThread(
            threadUUID: PatientFixtures.messageThreadSummary.threadUUID,
            reason: .questionAnswered
        )
        XCTAssertTrue(closed)

        let calls = await api.recordedCalls()
        let createInput = try? XCTUnwrap(calls.createdMessageInputs.first)
        XCTAssertEqual(createInput?.message, "What should I expect before my walk?")
        XCTAssertEqual(createInput?.urgentGuidanceVersion, PatientFixtures.immediateHelp.version)
        let sendInput = try? XCTUnwrap(calls.sentMessageInputs.first)
        XCTAssertEqual(sendInput?.message, "Is there anything I should bring?")
        XCTAssertEqual(sendInput?.threadVersion, PatientFixtures.messageThreadSummary.version)
        XCTAssertEqual(sendInput?.urgentGuidanceVersion, PatientFixtures.immediateHelp.version)
        let amendInput = try? XCTUnwrap(calls.amendedMessageInputs.first)
        XCTAssertEqual(amendInput?.action, .correction)
        XCTAssertEqual(amendInput?.message, "Correction: please explain the safe timing first.")
        XCTAssertEqual(amendInput?.threadVersion, PatientFixtures.messageThreadSummary.version)
        XCTAssertEqual(amendInput?.urgentGuidanceVersion, PatientFixtures.immediateHelp.version)
        XCTAssertEqual(calls.closeMessageInputs.first?.threadVersion, PatientFixtures.messageThreadSummary.version)
        XCTAssertEqual(calls.closeMessageInputs.first?.closeReason, .questionAnswered)

        let clientUUIDs = [
            createInput?.clientMessageUUID,
            sendInput?.clientMessageUUID,
            amendInput?.clientMessageUUID,
        ].compactMap { $0 }
        let idempotencyUUIDs = calls.createIdempotencyKeys
            + calls.sendIdempotencyKeys
            + calls.amendIdempotencyKeys
            + calls.closeIdempotencyKeys
        XCTAssertTrue((clientUUIDs + idempotencyUUIDs).allSatisfy { UUID(uuidString: $0) != nil })
        XCTAssertTrue(Set(clientUUIDs).isDisjoint(with: Set(idempotencyUUIDs)))
        XCTAssertEqual(viewModel.selectedMessageThread?.thread.status, .closed)
    }

    func testStaleMessageSendRefetchesOverviewAndThreadWithoutAutomaticallyResending() async {
        let api = MockPatientAPI(sendConflictOnce: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()
        await viewModel.openMessageThread(threadUUID: PatientFixtures.messageThreadSummary.threadUUID)

        let sent = await viewModel.sendMessage(
            threadUUID: PatientFixtures.messageThreadSummary.threadUUID,
            message: "A nonurgent follow-up"
        )

        XCTAssertFalse(sent)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.sentMessageInputs.count, 1, "A stale write must never be resent automatically.")
        XCTAssertEqual(calls.messageThreadListTokens.count, 2)
        XCTAssertEqual(calls.messageThreadShowTokens.count, 2)
        XCTAssertEqual(viewModel.selectedMessageThread?.thread.version, PatientFixtures.messageThreadSummary.version)
        XCTAssertTrue(viewModel.messagingMessage?.contains("refreshed") == true)
        XCTAssertTrue(viewModel.messagingMessage?.contains("Nothing was sent or queued") == true)
        XCTAssertFalse(viewModel.messagingMessage?.contains("Internal conflict") == true)
    }

    func testMismatchedImmediateHelpVersionsFailClosedBeforeComposeCanBeShown() async {
        let api = MockPatientAPI(mismatchedMessagingGuidance: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        XCTAssertEqual(viewModel.messagingState, .failed)
        XCTAssertTrue(viewModel.messagingMessage?.contains("No message was sent or queued") == true)
    }

    func testServerDisabledMessagingRemainsUnavailableWithoutExposingFeatureOrPolicyDetails() async {
        let api = MockPatientAPI(messagingDisabled: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()

        XCTAssertEqual(viewModel.messagingState, .disabled)
        XCTAssertNil(viewModel.selectedMessageThread)
        XCTAssertNil(viewModel.messagingMessage)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.messageTopicTokens.count, 1)
        XCTAssertEqual(calls.messageThreadListTokens.count, 1)
    }

    func testTransportFailureDoesNotCreateAnOfflineOutboxOrRetryTheMessage() async {
        let api = MockPatientAPI(sendTransportFailure: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()
        await viewModel.openMessageThread(threadUUID: PatientFixtures.messageThreadSummary.threadUUID)

        let sent = await viewModel.sendMessage(
            threadUUID: PatientFixtures.messageThreadSummary.threadUUID,
            message: "A nonurgent question"
        )
        XCTAssertFalse(sent)

        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.sentMessageInputs.count, 1)
        XCTAssertTrue(viewModel.messagingMessage?.contains("Nothing was queued") == true)
        XCTAssertEqual(viewModel.selectedMessageThread?.thread.messages.count, 1)
    }

    func testDeviceSessionsLoadOnlyAfterExplicitOpenAndOtherDeviceRevocationReconciles() async throws {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )

        await viewModel.bootstrap()
        var calls = await api.recordedCalls()
        XCTAssertTrue(calls.sessionListTokens.isEmpty, "Bootstrap must not preload device-session metadata.")
        XCTAssertTrue(viewModel.patientSessions.isEmpty)

        await viewModel.openSessionManagement()
        XCTAssertEqual(viewModel.sessionManagementState, .ready)
        XCTAssertEqual(viewModel.patientSessions.count, 2)
        XCTAssertEqual(viewModel.patientSessions.filter(\.current).count, 1)

        let otherSession = try XCTUnwrap(viewModel.patientSessions.first(where: { !$0.current }))
        let revokedOther = await viewModel.revokePatientSession(otherSession)
        XCTAssertTrue(revokedOther)

        calls = await api.recordedCalls()
        XCTAssertEqual(calls.sessionListTokens, ["valid-access", "valid-access"])
        XCTAssertEqual(calls.revokedSessionUUIDs, [PatientFixtures.otherSession.sessionUUID])
        XCTAssertEqual(viewModel.patientSessions.map(\.sessionUUID), [PatientFixtures.currentSession.sessionUUID])
        XCTAssertEqual(viewModel.sessionManagementMessage, "That device is now signed out.")
    }

    func testCurrentDeviceRevocationClearsEveryPatientStateAndReturnsToWelcomeBoundary() async throws {
        let api = MockPatientAPI()
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()
        await viewModel.openSessionManagement()
        let current = try XCTUnwrap(viewModel.patientSessions.first(where: \.current))

        let revokedCurrent = await viewModel.revokePatientSession(current)
        XCTAssertTrue(revokedCurrent)

        XCTAssertNil(store.accessToken)
        XCTAssertNil(store.refreshToken)
        XCTAssertNil(viewModel.snapshot)
        XCTAssertEqual(viewModel.messagingState, .notGranted)
        XCTAssertNil(viewModel.selectedMessageThread)
        XCTAssertEqual(viewModel.sessionManagementState, .idle)
        XCTAssertTrue(viewModel.patientSessions.isEmpty)
        XCTAssertNil(viewModel.selectedSessionForRevocation)
        XCTAssertNil(viewModel.errorMessage)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.revokedSessionUUIDs, [current.sessionUUID])
        XCTAssertEqual(calls.sessionListTokens.count, 1, "Current-device revocation must not issue a list request afterward.")
    }

    func testSessionManagementTerminalUnauthorizedExpiresLocalSession() async {
        let api = MockPatientAPI(sessionUnauthorized: true)
        let store = InMemoryPatientTokenStore(accessToken: "expired-access", refreshToken: "expired-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()

        await viewModel.openSessionManagement()

        XCTAssertNil(store.accessToken)
        XCTAssertNil(store.refreshToken)
        XCTAssertNil(viewModel.snapshot)
        XCTAssertTrue(viewModel.patientSessions.isEmpty)
        XCTAssertEqual(viewModel.errorMessage, "Your secure session ended. Sign in again to continue.")
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.refreshTokens, ["expired-refresh"])
        XCTAssertEqual(calls.sessionListTokens, ["expired-access", "rotated-access"])
    }

    func testDisabledSessionManagementLeavesCorePatientExperienceAvailable() async {
        let api = MockPatientAPI(sessionManagementUnavailable: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()
        let snapshot = viewModel.snapshot

        await viewModel.openSessionManagement()

        XCTAssertEqual(viewModel.sessionManagementState, .disabled)
        XCTAssertTrue(viewModel.patientSessions.isEmpty)
        XCTAssertEqual(viewModel.snapshot, snapshot)
        XCTAssertNil(viewModel.errorMessage)
        XCTAssertEqual(
            viewModel.sessionManagementMessage,
            "Device management is not available for this account. Your care view is still available."
        )
    }

    func testTransportFailureDoesNotRetrySessionRevocationAndBackgroundClearsRowsAndSelection() async throws {
        let api = MockPatientAPI(sessionRevocationTransportFailure: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()
        await viewModel.openSessionManagement()
        let other = try XCTUnwrap(viewModel.patientSessions.first(where: { !$0.current }))
        viewModel.selectSessionForRevocation(other)

        let revokedOther = await viewModel.revokePatientSession(other)
        XCTAssertFalse(revokedOther)
        var calls = await api.recordedCalls()
        XCTAssertEqual(calls.revokedSessionUUIDs, [other.sessionUUID])
        XCTAssertTrue(viewModel.sessionManagementMessage?.contains("Nothing was retried") == true)

        viewModel.selectSessionForRevocation(other)
        viewModel.protectPatientSessionRowsForBackground()
        XCTAssertEqual(viewModel.sessionManagementState, .idle)
        XCTAssertTrue(viewModel.patientSessions.isEmpty)
        XCTAssertNil(viewModel.selectedSessionForRevocation)
        XCTAssertNil(viewModel.sessionManagementMessage)

        calls = await api.recordedCalls()
        XCTAssertEqual(calls.sessionListTokens.count, 1)
    }

    func testServerFailureDoesNotRetrySessionRevocationOrRemoveTheDevice() async throws {
        let api = MockPatientAPI(sessionRevocationServerFailure: true)
        let store = InMemoryPatientTokenStore(accessToken: "valid-access", refreshToken: "valid-refresh")
        let viewModel = PatientAppViewModel(
            configuration: .enabled,
            api: api,
            tokenStore: store
        )
        await viewModel.bootstrap()
        await viewModel.openSessionManagement()
        let other = try XCTUnwrap(viewModel.patientSessions.first(where: { !$0.current }))

        let revoked = await viewModel.revokePatientSession(other)

        XCTAssertFalse(revoked)
        let calls = await api.recordedCalls()
        XCTAssertEqual(calls.revokedSessionUUIDs, [other.sessionUUID])
        XCTAssertEqual(viewModel.patientSessions.count, 2)
        XCTAssertNotNil(viewModel.snapshot)
        XCTAssertTrue(viewModel.sessionManagementMessage?.contains("Nothing was retried") == true)
    }
}

private extension PatientAppConfiguration {
    static let disabled = PatientAppConfiguration(
        patientAPIEnabled: false,
        patientAPIBaseURL: nil,
        syntheticReferenceRequested: false
    )

    static let enabled = PatientAppConfiguration(
        patientAPIEnabled: true,
        patientAPIBaseURL: URL(string: "https://patient.example.test"),
        syntheticReferenceRequested: false
    )
}

private final class InMemoryPatientTokenStore: PatientTokenStoring {
    var accessToken: String?
    var refreshToken: String?

    init(accessToken: String? = nil, refreshToken: String? = nil) {
        self.accessToken = accessToken
        self.refreshToken = refreshToken
    }

    func store(accessToken: String, refreshToken: String) throws {
        self.accessToken = accessToken
        self.refreshToken = refreshToken
    }

    func clear() {
        accessToken = nil
        refreshToken = nil
    }
}

private final class FailingPatientTokenStore: PatientTokenStoring {
    enum StubError: Error { case unavailable }

    var accessToken: String?
    var refreshToken: String?
    private(set) var clearCount = 0

    init(accessToken: String? = nil, refreshToken: String? = nil) {
        self.accessToken = accessToken
        self.refreshToken = refreshToken
    }

    func store(accessToken: String, refreshToken: String) throws {
        self.accessToken = accessToken
        throw StubError.unavailable
    }

    func clear() {
        clearCount += 1
        accessToken = nil
        refreshToken = nil
    }
}

private actor MockPatientAPI: PatientAPIService {
    struct Calls {
        var signIns: [String] = []
        var enrollmentChallenges: [String] = []
        var refreshTokens: [String] = []
        var revokedTokens: [String] = []
        var profileTokens: [String] = []
        var sessionListTokens: [String] = []
        var revokedSessionUUIDs: [String] = []
        var encounterTokens: [String] = []
        var todayTokens: [String] = []
        var pathwayTokens: [String] = []
        var pathwayEventsTokens: [String] = []
        var dischargeReadinessTokens: [String] = []
        var roundsSummaryTokens: [String] = []
        var careTeamTokens: [String] = []
        var messageTopicTokens: [String] = []
        var messageThreadListTokens: [String] = []
        var messageThreadShowTokens: [String] = []
        var createdMessageInputs: [PatientMessageThreadCreateInput] = []
        var sentMessageInputs: [PatientMessageCreateInput] = []
        var amendedMessageInputs: [PatientMessageAmendmentInput] = []
        var closeMessageInputs: [PatientMessageThreadCloseInput] = []
        var createIdempotencyKeys: [String] = []
        var sendIdempotencyKeys: [String] = []
        var amendIdempotencyKeys: [String] = []
        var closeIdempotencyKeys: [String] = []
    }

    private var calls = Calls()
    private var shouldRejectNextProfile: Bool
    private let missingToday: Bool
    private let refreshUnauthorized: Bool
    private var shouldConflictNextSend: Bool
    private let mismatchedMessagingGuidance: Bool
    private let messagingDisabled: Bool
    private let sendTransportFailure: Bool
    private let sessionUnauthorized: Bool
    private let sessionManagementUnavailable: Bool
    private let sessionRevocationTransportFailure: Bool
    private let sessionRevocationServerFailure: Bool
    private var revokedPatientSessionUUIDs: Set<String> = []

    init(
        profileUnauthorizedOnce: Bool = false,
        missingToday: Bool = false,
        refreshUnauthorized: Bool = false,
        sendConflictOnce: Bool = false,
        mismatchedMessagingGuidance: Bool = false,
        messagingDisabled: Bool = false,
        sendTransportFailure: Bool = false,
        sessionUnauthorized: Bool = false,
        sessionManagementUnavailable: Bool = false,
        sessionRevocationTransportFailure: Bool = false,
        sessionRevocationServerFailure: Bool = false
    ) {
        shouldRejectNextProfile = profileUnauthorizedOnce
        self.missingToday = missingToday
        self.refreshUnauthorized = refreshUnauthorized
        shouldConflictNextSend = sendConflictOnce
        self.mismatchedMessagingGuidance = mismatchedMessagingGuidance
        self.messagingDisabled = messagingDisabled
        self.sendTransportFailure = sendTransportFailure
        self.sessionUnauthorized = sessionUnauthorized
        self.sessionManagementUnavailable = sessionManagementUnavailable
        self.sessionRevocationTransportFailure = sessionRevocationTransportFailure
        self.sessionRevocationServerFailure = sessionRevocationServerFailure
    }

    func recordedCalls() -> Calls { calls }

    func signIn(
        email: String,
        password: String,
        device: PatientDeviceDescriptor
    ) async throws -> PatientTokenPair {
        calls.signIns.append(email)
        return PatientFixtures.tokenPair(access: "login-access", refresh: "login-refresh")
    }

    func enroll(
        _ input: PatientEnrollmentInput,
        device: PatientDeviceDescriptor
    ) async throws -> PatientTokenPair {
        calls.enrollmentChallenges.append(input.challengeUUID)
        return PatientFixtures.tokenPair(access: "enrollment-access", refresh: "enrollment-refresh")
    }

    func refresh(refreshToken: String) async throws -> PatientTokenPair {
        calls.refreshTokens.append(refreshToken)
        if refreshUnauthorized {
            throw PatientAPIError.unauthorized(code: "session_expired", message: "Session expired.")
        }
        return PatientFixtures.tokenPair(access: "rotated-access", refresh: "rotated-refresh")
    }

    func revoke(token: String) async throws {
        calls.revokedTokens.append(token)
    }

    func profile(accessToken: String) async throws -> PatientEnvelope<PatientProfile> {
        calls.profileTokens.append(accessToken)
        if shouldRejectNextProfile {
            shouldRejectNextProfile = false
            throw PatientAPIError.unauthorized(code: "access_expired", message: "Access token expired.")
        }
        return PatientFixtures.profile
    }

    func updatePreferences(
        _ input: PatientPreferencesInput,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientProfile> {
        PatientFixtures.profile
    }

    func sessions(accessToken: String) async throws -> PatientEnvelope<PatientSessionCollection> {
        calls.sessionListTokens.append(accessToken)
        if sessionUnauthorized {
            throw PatientAPIError.unauthorized(code: "session_expired", message: "Session expired.")
        }
        if sessionManagementUnavailable {
            throw PatientAPIError.notFound
        }
        return PatientEnvelope(
            data: PatientSessionCollection(
                sessions: [PatientFixtures.currentSession, PatientFixtures.otherSession]
                    .filter { !revokedPatientSessionUUIDs.contains($0.sessionUUID) }
            ),
            meta: PatientFixtures.meta,
            links: [:]
        )
    }

    func revokeSession(
        sessionUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientSessionRevocationResult> {
        calls.revokedSessionUUIDs.append(sessionUUID)
        if sessionRevocationTransportFailure {
            throw PatientAPIError.transport
        }
        if sessionRevocationServerFailure {
            throw PatientAPIError.server(
                statusCode: 500,
                code: "patient_service_unavailable",
                message: "Internal failure detail"
            )
        }
        let alreadyRevoked = revokedPatientSessionUUIDs.contains(sessionUUID)
        revokedPatientSessionUUIDs.insert(sessionUUID)
        return PatientEnvelope(
            data: PatientSessionRevocationResult(
                sessionUUID: sessionUUID,
                revoked: true,
                alreadyRevoked: alreadyRevoked
            ),
            meta: PatientFixtures.meta,
            links: [:]
        )
    }

    func encounters(accessToken: String) async throws -> PatientEnvelope<PatientEncounterCollection> {
        calls.encounterTokens.append(accessToken)
        return PatientFixtures.encounters
    }

    func today(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientTodayProjectionEnvelope {
        calls.todayTokens.append(accessToken)
        if missingToday { throw PatientAPIError.notFound }
        return PatientFixtures.today
    }

    func pathway(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientPathwayProjectionEnvelope {
        calls.pathwayTokens.append(accessToken)
        return PatientFixtures.pathway
    }

    func pathwayEvents(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientPathwayEventsProjectionEnvelope {
        calls.pathwayEventsTokens.append(accessToken)
        return PatientFixtures.pathwayEvents
    }

    func dischargeReadiness(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientDischargeReadinessProjectionEnvelope {
        calls.dischargeReadinessTokens.append(accessToken)
        return PatientFixtures.dischargeReadiness
    }

    func roundsSummary(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientRoundsSummaryProjectionEnvelope {
        calls.roundsSummaryTokens.append(accessToken)
        return PatientFixtures.roundsSummary
    }

    func careTeam(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientCareTeamProjectionEnvelope {
        calls.careTeamTokens.append(accessToken)
        return PatientFixtures.careTeam
    }

    func messageTopics(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageTopicsResult> {
        calls.messageTopicTokens.append(accessToken)
        if messagingDisabled {
            throw PatientAPIError.server(
                statusCode: 503,
                code: "patient_messaging_disabled",
                message: "Internal feature state"
            )
        }
        return PatientFixtures.messageTopics
    }

    func messageThreads(
        encounterUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadListResult> {
        calls.messageThreadListTokens.append(accessToken)
        if mismatchedMessagingGuidance {
            return PatientEnvelope(
                data: PatientMessageThreadListResult(
                    threads: [PatientFixtures.messageThreadSummary],
                    immediateHelp: PatientImmediateHelpGuidance(
                        version: "changed-guidance-v2",
                        text: PatientFixtures.immediateHelp.text
                    )
                ),
                meta: PatientFixtures.meta,
                links: [:]
            )
        }
        return PatientFixtures.messageThreads
    }

    func createMessageThread(
        encounterUUID: String,
        input: PatientMessageThreadCreateInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult> {
        calls.createdMessageInputs.append(input)
        calls.createIdempotencyKeys.append(idempotencyKey)
        return PatientEnvelope(
            data: PatientMessageThreadMutationResult(thread: PatientFixtures.messageThreadSummary),
            meta: PatientFixtures.meta,
            links: [:]
        )
    }

    func messageThread(
        threadUUID: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadDetailResult> {
        calls.messageThreadShowTokens.append(accessToken)
        return PatientFixtures.messageThread
    }

    func sendMessage(
        threadUUID: String,
        input: PatientMessageCreateInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageMutationResult> {
        calls.sentMessageInputs.append(input)
        calls.sendIdempotencyKeys.append(idempotencyKey)
        if shouldConflictNextSend {
            shouldConflictNextSend = false
            throw PatientAPIError.server(
                statusCode: 409,
                code: "stale_thread_version",
                message: "Internal conflict detail"
            )
        }
        if sendTransportFailure {
            throw PatientAPIError.transport
        }
        return PatientEnvelope(
            data: PatientMessageMutationResult(
                thread: PatientFixtures.messageThreadSummary,
                message: PatientFixtures.sentVisibleMessage
            ),
            meta: PatientFixtures.meta,
            links: [:]
        )
    }

    func amendMessage(
        threadUUID: String,
        messageUUID: String,
        input: PatientMessageAmendmentInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageMutationResult> {
        calls.amendedMessageInputs.append(input)
        calls.amendIdempotencyKeys.append(idempotencyKey)
        let message = PatientVisibleMessage(
            messageUUID: "019f0000-0000-7000-8000-000000000065",
            senderDisplayRole: .patient,
            messageKind: input.action == .correction ? .correction : .retraction,
            body: input.message,
            relatesToMessageUUID: messageUUID,
            deliveryState: .sent,
            sentAt: "2026-07-19T15:21:00.000000Z"
        )
        return PatientEnvelope(
            data: PatientMessageMutationResult(
                thread: PatientFixtures.messageThreadSummary,
                message: message
            ),
            meta: PatientFixtures.meta,
            links: [:]
        )
    }

    func closeMessageThread(
        threadUUID: String,
        input: PatientMessageThreadCloseInput,
        idempotencyKey: String,
        accessToken: String
    ) async throws -> PatientEnvelope<PatientMessageThreadMutationResult> {
        calls.closeMessageInputs.append(input)
        calls.closeIdempotencyKeys.append(idempotencyKey)
        return PatientEnvelope(
            data: PatientMessageThreadMutationResult(thread: PatientFixtures.closedMessageThreadSummary),
            meta: PatientFixtures.meta,
            links: [:]
        )
    }
}

private enum PatientFixtures {
    static let encounterUUID = "019f0000-0000-7000-8000-000000000010"
    static let meta = PatientEnvelopeMeta(
        asOf: "2026-07-19T14:22:31.000000Z",
        stale: false,
        version: .integer(1),
        sourceFreshness: PatientSourceFreshness(status: "current", observedAt: "2026-07-19T14:20:00.000000Z"),
        policyVersion: "patient-disclosure-v1"
    )
    static let provenance = PatientProjectionProvenance(
        projectionMethod: "governed_projection",
        sourceClass: "clinical_operations",
        inputClasses: ["care_plan"],
        reviewState: "clinically_reviewed",
        producerVersion: "patient-projection-v1"
    )
    static let uncertainty = PatientProjectionUncertainty(
        level: "medium",
        explanation: "Plans can change after reassessment.",
        canChange: true,
        reviewedAt: "2026-07-19T14:20:00.000000Z"
    )

    static let profile = PatientEnvelope(
        data: PatientProfile(
            principalUUID: "019f0000-0000-7000-8000-000000000001",
            principalType: "patient",
            displayName: "Sam Example",
            email: nil,
            phoneE164: nil,
            emailVerified: false,
            phoneVerified: false,
            locale: "en-US",
            timezone: "America/New_York",
            preferences: PatientPreferences()
        ),
        meta: meta,
        links: [:]
    )

    static let encounters = PatientEnvelope(
        data: PatientEncounterCollection(encounters: [
            PatientEncounterHandle(
                encounterUUID: encounterUUID,
                grantUUID: "019f0000-0000-7000-8000-000000000011",
                relationship: "self",
                scopes: ["today:read", "pathway:read", "care_team:read", "messaging:read", "messaging:write"],
                validFrom: nil,
                expiresAt: nil,
                version: 1
            ),
        ]),
        meta: meta,
        links: [:]
    )

    static let currentSession = PatientSessionSummary(
        sessionUUID: "019f0000-0000-7000-8000-000000000081",
        current: true,
        status: .active,
        device: PatientSessionDevice(
            uuid: "019f0000-0000-7000-8000-000000000091",
            platform: .ios,
            name: "This iPhone",
            appVersion: "0.1.0",
            osVersion: "26.0"
        ),
        authMethod: .password,
        assuranceLevel: "aal1",
        lastSeenAt: "2026-07-20T14:22:31.000000Z",
        expiresAt: "2026-08-19T14:22:31.000000Z",
        createdAt: "2026-07-19T14:22:31.000000Z"
    )

    static let otherSession = PatientSessionSummary(
        sessionUUID: "019f0000-0000-7000-8000-000000000082",
        current: false,
        status: .active,
        device: PatientSessionDevice(
            uuid: nil,
            platform: .android,
            name: nil,
            appVersion: nil,
            osVersion: "16"
        ),
        authMethod: .enrollment,
        assuranceLevel: nil,
        lastSeenAt: "2026-07-19T18:10:00.000000Z",
        expiresAt: "2026-08-18T18:10:00.000000Z",
        createdAt: "2026-07-18T18:10:00.000000Z"
    )

    static let today = PatientTodayProjectionEnvelope(
        data: PatientProjectionData(
            projectionUUID: "019f0000-0000-7000-8000-000000000020",
            encounterUUID: encounterUUID,
            kind: "today",
            content: PatientTodayContent(
                headline: "Your plan for today",
                summary: "Your team released these steps.",
                schedule: [
                    PatientScheduleItem(
                        itemUUID: "019f0000-0000-7000-8000-000000000021",
                        label: "Care team rounds",
                        detail: "Bring your questions.",
                        status: "planned",
                        timeWindow: "This morning",
                        timingConfidence: "estimated",
                        preparation: nil,
                        canChange: true
                    ),
                ],
                nextSteps: ["Write down your questions."],
                careLocation: PatientCareLocation(
                    facilityDisplayName: "Example Hospital",
                    unitDisplayName: "5 East",
                    roomDisplayName: "Room 512",
                    status: "inpatient"
                ),
                dischargeOutlook: nil,
                questions: [],
                notices: ["Timing can change."]
            ),
            uncertainty: uncertainty,
            provenance: provenance,
            revisionNotice: nil,
            observedAt: "2026-07-19T14:18:00.000000Z",
            generatedAt: "2026-07-19T14:19:00.000000Z",
            releasedAt: "2026-07-19T14:20:00.000000Z"
        ),
        meta: meta,
        links: [:]
    )

    static let pathway = PatientPathwayProjectionEnvelope(
        data: PatientProjectionData(
            projectionUUID: "019f0000-0000-7000-8000-000000000030",
            encounterUUID: encounterUUID,
            kind: "pathway",
            content: PatientPathwayContent(
                headline: "My Path",
                summary: "What is happening now and what comes next.",
                currentStage: "Getting stronger",
                stages: [
                    PatientReleasedPathwayStage(
                        stageUUID: "019f0000-0000-7000-8000-000000000031",
                        title: "Getting stronger",
                        status: "current",
                        summary: "Your team is helping you regain strength.",
                        expectedRange: nil,
                        timingConfidence: "current",
                        canChange: true
                    ),
                ],
                milestones: [
                    PatientReleasedPathwayMilestone(
                        milestoneUUID: "019f0000-0000-7000-8000-000000000032",
                        title: "Review medicines before discharge",
                        status: "planned",
                        detail: "Your team will review medicine changes before you leave.",
                        timing: "Before your next setting",
                        timingConfidence: "estimated",
                        canChange: true
                    ),
                ],
                goals: [
                    PatientReleasedGoal(
                        goalUUID: "019f0000-0000-7000-8000-000000000033",
                        authorType: "care_team",
                        label: "Prepare for a safe next step",
                        explanation: "Review support needs with your care team.",
                        status: "in_progress",
                        targetRange: nil
                    ),
                ],
                education: [
                    PatientReleasedEducation(
                        itemUUID: "019f0000-0000-7000-8000-000000000034",
                        title: "Preparing for home",
                        summary: "Review released next-step information with your care team."
                    ),
                ],
                questions: [],
                notices: ["Your path may change."]
            ),
            uncertainty: uncertainty,
            provenance: provenance,
            revisionNotice: PatientProjectionRevisionNotice(
                kind: .correction,
                message: "Your care team updated this information. Please use the details shown here."
            ),
            observedAt: "2026-07-19T14:18:00.000000Z",
            generatedAt: "2026-07-19T14:19:00.000000Z",
            releasedAt: "2026-07-19T14:20:00.000000Z"
        ),
        meta: meta,
        links: [:]
    )

    static let pathwayEvents = PatientPathwayEventsProjectionEnvelope(
        data: PatientProjectionData(
            projectionUUID: "019f0000-0000-7000-8000-000000000035",
            encounterUUID: encounterUUID,
            kind: "pathway_events",
            content: PatientPathwayEventsContent(
                headline: "What has happened so far",
                summary: "A simple timeline of released key moments.",
                events: [
                    PatientReleasedPathwayEvent(
                        eventUUID: "019f0000-0000-7000-8000-000000000036",
                        title: "Admitted to the hospital",
                        when: "Two days ago",
                        status: "completed",
                        detail: "Your care team reviewed your history and started your plan.",
                        category: .other
                    ),
                ],
                notices: ["This timeline is a summary and may not include every detail."]
            ),
            uncertainty: uncertainty,
            provenance: provenance,
            revisionNotice: nil,
            observedAt: "2026-07-19T14:18:00.000000Z",
            generatedAt: "2026-07-19T14:19:00.000000Z",
            releasedAt: "2026-07-19T14:20:00.000000Z"
        ),
        meta: meta,
        links: [:]
    )

    static let dischargeReadiness = PatientDischargeReadinessProjectionEnvelope(
        data: PatientProjectionData(
            projectionUUID: "019f0000-0000-7000-8000-000000000035",
            encounterUUID: encounterUUID,
            kind: "discharge_readiness",
            content: PatientDischargeReadinessContent(
                headline: "Getting ready to leave",
                summary: "Your team will confirm the details before you leave.",
                estimatedRange: "The next day or two",
                estimatedConfidence: "estimated",
                criteria: [
                    PatientDischargeCriterion(
                        itemUUID: "019f0000-0000-7000-8000-000000000036",
                        label: "Moving safely with the support you need",
                        status: "pending",
                        detail: "Your team will review this with you each day."
                    ),
                ],
                unresolvedNeeds: ["A ride home arranged for the day you leave."],
                medications: [
                    PatientDischargeMedication(
                        itemUUID: "019f0000-0000-7000-8000-000000000037",
                        name: "Your updated medicine list",
                        purpose: "Review each medicine with your care team."
                    ),
                ],
                followUp: [
                    PatientDischargeFollowUp(
                        itemUUID: "019f0000-0000-7000-8000-000000000038",
                        label: "Follow-up visit with your care team",
                        when: "Within a week or two of leaving"
                    ),
                ],
                warningSigns: ["Call your care team if symptoms get worse after you go home."],
                contacts: [
                    PatientDischargeContact(
                        itemUUID: "019f0000-0000-7000-8000-000000000039",
                        label: "Your care team",
                        route: "speak_with_bedside_staff"
                    ),
                ],
                questions: [],
                notices: ["This is a summary; details can change."]
            ),
            uncertainty: uncertainty,
            provenance: provenance,
            revisionNotice: nil,
            observedAt: "2026-07-19T14:18:00.000000Z",
            generatedAt: "2026-07-19T14:19:00.000000Z",
            releasedAt: "2026-07-19T14:20:00.000000Z"
        ),
        meta: meta,
        links: [:]
    )

    static let roundsSummary = PatientRoundsSummaryProjectionEnvelope(
        data: PatientProjectionData(
            projectionUUID: "019f0000-0000-7000-8000-000000000042",
            encounterUUID: encounterUUID,
            kind: "rounds_summary",
            content: PatientRoundsSummaryContent(
                headline: "Your care-team conversation",
                summary: "A released plain-language summary after your team reviewed your care.",
                roundWindow: "Earlier today",
                topics: [
                    PatientReleasedRoundsTopic(
                        topicUUID: "019f0000-0000-7000-8000-000000000043",
                        title: "How you are doing",
                        summary: "Your team reviewed progress and next steps.",
                        status: "current"
                    ),
                ],
                nextSteps: ["Tell your bedside team what you would like explained."],
                questions: [],
                notices: ["This summary can change after your team reassesses you."]
            ),
            uncertainty: uncertainty,
            provenance: provenance,
            revisionNotice: nil,
            observedAt: "2026-07-19T14:18:00.000000Z",
            generatedAt: "2026-07-19T14:19:00.000000Z",
            releasedAt: "2026-07-19T14:20:00.000000Z"
        ),
        meta: meta,
        links: [:]
    )

    static let careTeam = PatientCareTeamProjectionEnvelope(
        data: PatientProjectionData(
            projectionUUID: "019f0000-0000-7000-8000-000000000040",
            encounterUUID: encounterUUID,
            kind: "care_team",
            content: PatientCareTeamContent(
                headline: "Your care team",
                summary: "People helping with your stay.",
                members: [
                    PatientReleasedCareTeamMember(
                        memberUUID: "019f0000-0000-7000-8000-000000000041",
                        displayName: "Jordan Lee, RN",
                        role: "Bedside nurse",
                        service: "5 East",
                        responsibilities: ["Coordinates your bedside care."],
                        contactRoute: "speak_with_bedside_staff"
                    ),
                ],
                communicationOptions: ["speak_with_bedside_staff", "call_button_for_urgent_help"],
                questions: [],
                notices: ["Use your call button for urgent help."]
            ),
            uncertainty: uncertainty,
            provenance: provenance,
            revisionNotice: nil,
            observedAt: "2026-07-19T14:18:00.000000Z",
            generatedAt: "2026-07-19T14:19:00.000000Z",
            releasedAt: "2026-07-19T14:20:00.000000Z"
        ),
        meta: meta,
        links: [:]
    )

    static let immediateHelp = PatientImmediateHelpGuidance(
        version: "patient-urgent-guidance-v1",
        text: "Messages are not monitored for emergencies. Use your bedside call button or speak with a staff member when you need help now."
    )

    static let messageTopic = PatientMessageTopic(
        code: "care_plan_question",
        label: "Question about my care plan",
        description: "Ask a nonurgent question about a released step in your care plan.",
        expectedResponseWindow: "Your care team usually responds during the current care shift."
    )

    static let messageThreadSummary = PatientMessageThreadSummary(
        threadUUID: "019f0000-0000-7000-8000-000000000061",
        topic: PatientMessageThreadTopic(
            code: messageTopic.code,
            label: messageTopic.label,
            description: messageTopic.description
        ),
        status: .open,
        ownershipState: .acknowledged,
        expectedResponseWindow: messageTopic.expectedResponseWindow,
        version: 2,
        lastMessageAt: "2026-07-19T15:12:00.000000Z",
        createdAt: "2026-07-19T14:45:00.000000Z",
        closedAt: nil,
        closeReason: nil
    )

    static let closedMessageThreadSummary = PatientMessageThreadSummary(
        threadUUID: messageThreadSummary.threadUUID,
        topic: messageThreadSummary.topic,
        status: .closed,
        ownershipState: .closed,
        expectedResponseWindow: messageThreadSummary.expectedResponseWindow,
        version: 3,
        lastMessageAt: messageThreadSummary.lastMessageAt,
        createdAt: messageThreadSummary.createdAt,
        closedAt: "2026-07-19T15:30:00.000000Z",
        closeReason: .questionAnswered
    )

    static let patientVisibleMessage = PatientVisibleMessage(
        messageUUID: "019f0000-0000-7000-8000-000000000062",
        senderDisplayRole: .patient,
        messageKind: .message,
        body: "Could someone explain what happens before my walk?",
        relatesToMessageUUID: nil,
        deliveryState: .acknowledged,
        sentAt: "2026-07-19T14:45:00.000000Z"
    )

    static let sentVisibleMessage = PatientVisibleMessage(
        messageUUID: "019f0000-0000-7000-8000-000000000064",
        senderDisplayRole: .patient,
        messageKind: .message,
        body: "Is there anything I should bring?",
        relatesToMessageUUID: nil,
        deliveryState: .sent,
        sentAt: "2026-07-19T15:20:00.000000Z"
    )

    static let messageTopics = PatientEnvelope(
        data: PatientMessageTopicsResult(
            topics: [messageTopic],
            immediateHelp: immediateHelp
        ),
        meta: meta,
        links: [:]
    )

    static let messageThreads = PatientEnvelope(
        data: PatientMessageThreadListResult(
            threads: [messageThreadSummary],
            immediateHelp: immediateHelp
        ),
        meta: meta,
        links: [:]
    )

    static let messageThread = PatientEnvelope(
        data: PatientMessageThreadDetailResult(
            thread: PatientMessageThreadDetail(
                threadUUID: messageThreadSummary.threadUUID,
                topic: messageThreadSummary.topic,
                status: messageThreadSummary.status,
                ownershipState: messageThreadSummary.ownershipState,
                expectedResponseWindow: messageThreadSummary.expectedResponseWindow,
                version: messageThreadSummary.version,
                lastMessageAt: messageThreadSummary.lastMessageAt,
                createdAt: messageThreadSummary.createdAt,
                closedAt: messageThreadSummary.closedAt,
                closeReason: messageThreadSummary.closeReason,
                messages: [patientVisibleMessage]
            ),
            immediateHelp: immediateHelp
        ),
        meta: meta,
        links: [:]
    )

    static func tokenPair(access: String, refresh: String) -> PatientTokenPair {
        PatientTokenPair(
            tokenType: "Bearer",
            accessToken: access,
            refreshToken: refresh,
            expiresIn: 900,
            sessionUUID: "019f0000-0000-7000-8000-000000000050",
            abilities: ["patient:access"]
        )
    }
}
