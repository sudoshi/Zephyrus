import Foundation

@MainActor
final class PatientAppViewModel: ObservableObject {
    @Published private(set) var snapshot: PatientExperienceSnapshot?
    @Published private(set) var isBusy = false
    @Published var errorMessage: String?
    @Published private(set) var messagingState: PatientMessagingState = .notGranted
    @Published private(set) var selectedMessageThread: PatientMessageThreadDetailResult?
    @Published private(set) var isMessagingBusy = false
    @Published private(set) var messagingMessage: String?
    @Published private(set) var sessionManagementState: PatientSessionManagementState = .idle
    @Published private(set) var patientSessions: [PatientSessionSummary] = []
    @Published private(set) var selectedSessionForRevocation: PatientSessionSummary?
    @Published private(set) var sessionManagementMessage: String?
    @Published private(set) var patientPreferences = PatientPreferences()
    @Published private(set) var isSavingPreferences = false
    @Published private(set) var preferencesMessage: String?

    let configuration: PatientAppConfiguration
    private let api: (any PatientAPIService)?
    private let tokenStore: any PatientTokenStoring
    private var sessionManagementGeneration = 0

    init(
        configuration: PatientAppConfiguration,
        api: (any PatientAPIService)?,
        tokenStore: any PatientTokenStoring
    ) {
        self.configuration = configuration
        self.api = api
        self.tokenStore = tokenStore
    }

    var liveAccessAvailable: Bool {
        configuration.patientAPIEnabled && api != nil
    }

    func bootstrap() async {
        guard snapshot == nil else { return }

        #if DEBUG
        if configuration.syntheticReferenceRequested {
            activateSyntheticReference()
            return
        }
        #endif

        guard liveAccessAvailable,
              tokenStore.accessToken != nil || tokenStore.refreshToken != nil
        else { return }
        await loadPatientExperience()
    }

    func retry() async {
        guard liveAccessAvailable else { return }
        await loadPatientExperience()
    }

    func signIn(email: String, password: String) async {
        guard let api, liveAccessAvailable else {
            errorMessage = "Patient API access is not configured for this build."
            return
        }

        await performAuthentication(api: api) {
            try await api.signIn(
                email: email.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
                password: password,
                device: .current
            )
        }
    }

    func enroll(_ input: PatientEnrollmentInput) async {
        guard let api, liveAccessAvailable else {
            errorMessage = "Enrollment is not configured for this build."
            return
        }

        await performAuthentication(api: api) {
            try await api.enroll(input, device: .current)
        }
    }

    func signOut() async {
        let revokeToken = tokenStore.refreshToken ?? tokenStore.accessToken
        tokenStore.clear()
        snapshot = nil
        errorMessage = nil
        resetMessaging()
        resetSessionManagement()
        resetPreferences()

        guard let api, liveAccessAvailable, let revokeToken else { return }
        try? await api.revoke(token: revokeToken)
    }

    #if DEBUG
    func activateSyntheticReference() {
        tokenStore.clear()
        errorMessage = nil
        resetSessionManagement()
        let syntheticSnapshot = PatientExperienceSnapshot.syntheticReference(now: Date())
        snapshot = syntheticSnapshot
        patientPreferences = syntheticSnapshot.preferences
        messagingState = .ready(.syntheticReference)
    }
    #endif

    func openSessionManagement() async {
        resetSessionManagement()
        guard snapshot != nil else { return }

        #if DEBUG
        if snapshot?.isSynthetic == true {
            patientSessions = .syntheticReference
            sessionManagementState = .ready
            return
        }
        #endif

        guard liveAccessAvailable else {
            sessionManagementState = .unavailable
            sessionManagementMessage = Self.sessionManagementUnavailableMessage
            return
        }

        sessionManagementState = .loading
        let generation = sessionManagementGeneration
        do {
            let sessions = try await requestPatientSessions()
            guard generation == sessionManagementGeneration, snapshot != nil else { return }
            patientSessions = sessions
            sessionManagementState = .ready
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch let error where isSessionManagementFeatureDisabled(error) {
            guard generation == sessionManagementGeneration else { return }
            sessionManagementState = .disabled
            sessionManagementMessage = Self.sessionManagementDisabledMessage
        } catch let error where isSessionManagementUnavailable(error) {
            guard generation == sessionManagementGeneration else { return }
            sessionManagementState = .unavailable
            sessionManagementMessage = Self.sessionManagementUnavailableMessage
        } catch {
            guard generation == sessionManagementGeneration else { return }
            sessionManagementState = .failed
            sessionManagementMessage = Self.sessionManagementUnavailableMessage
        }
    }

    func selectSessionForRevocation(_ session: PatientSessionSummary) {
        guard sessionManagementState == .ready,
              patientSessions.contains(where: { $0.sessionUUID == session.sessionUUID })
        else { return }
        selectedSessionForRevocation = session
        sessionManagementMessage = nil
    }

    func cancelSessionRevocation() {
        selectedSessionForRevocation = nil
    }

    @discardableResult
    func revokePatientSession(_ session: PatientSessionSummary) async -> Bool {
        guard sessionManagementState == .ready,
              patientSessions.contains(where: {
                  $0.sessionUUID == session.sessionUUID
              })
        else { return false }

        #if DEBUG
        if snapshot?.isSynthetic == true {
            if session.current {
                expireLocalSession(message: nil)
            } else {
                patientSessions.removeAll {
                    $0.sessionUUID == session.sessionUUID
                }
                self.selectedSessionForRevocation = nil
                sessionManagementMessage = "That device is now signed out."
            }
            return true
        }
        #endif

        sessionManagementState = .revoking
        sessionManagementMessage = nil
        let generation = sessionManagementGeneration

        do {
            let response = try await authorizedPatientRequest { api, accessToken in
                try await api.revokeSession(
                    sessionUUID: session.sessionUUID,
                    accessToken: accessToken
                )
            }

            if session.current {
                expireLocalSession(message: nil)
                return response.data.revoked
            }

            guard generation == sessionManagementGeneration, snapshot != nil else { return true }
            patientSessions.removeAll {
                $0.sessionUUID == session.sessionUUID
            }
            self.selectedSessionForRevocation = nil
            sessionManagementState = .ready
            sessionManagementMessage = response.data.alreadyRevoked
                ? "That device was already signed out."
                : "That device is now signed out."

            do {
                let reconciledSessions = try await requestPatientSessions()
                guard generation == sessionManagementGeneration, snapshot != nil else { return true }
                patientSessions = reconciledSessions
            } catch PatientSessionFailure.expired {
                expireLocalSession()
            } catch PatientAPIError.unauthorized {
                expireLocalSession()
            } catch {
                guard generation == sessionManagementGeneration else { return true }
                sessionManagementMessage = "That device is signed out. We could not refresh this list just now."
            }
            return true
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch let error where isSessionManagementFeatureDisabled(error) {
            guard generation == sessionManagementGeneration else { return false }
            sessionManagementState = .disabled
            sessionManagementMessage = Self.sessionManagementDisabledMessage
            self.selectedSessionForRevocation = nil
        } catch {
            guard generation == sessionManagementGeneration else { return false }
            sessionManagementState = .ready
            sessionManagementMessage = "We could not sign out that device. Nothing was retried. Check your connection and try again when you are ready."
            self.selectedSessionForRevocation = nil
        }
        return false
    }

    func dismissSessionManagement() {
        resetSessionManagement()
    }

    func protectPatientSessionRowsForBackground() {
        resetSessionManagement()
    }

    /// Preferences are account display/delivery choices only. They never alter care-plan data,
    /// urgency guidance, clinical orders, or the responsible care-team workflow.
    @discardableResult
    func savePreferences(_ input: PatientPreferencesInput) async -> Bool {
        guard snapshot != nil, !isSavingPreferences else { return false }

        #if DEBUG
        if snapshot?.isSynthetic == true {
            patientPreferences = PatientPreferences(
                textSize: input.textSize,
                reducedMotion: input.reducedMotion,
                highContrast: input.highContrast,
                notificationPreview: input.notificationPreview,
                preferredChannel: input.preferredChannel
            )
            preferencesMessage = "Reference settings updated on this device. No patient account was changed."
            return true
        }
        #endif

        guard liveAccessAvailable else {
            preferencesMessage = "Preferences are not available in this build. Your care view is unchanged."
            return false
        }

        isSavingPreferences = true
        preferencesMessage = nil
        defer { isSavingPreferences = false }

        do {
            let response = try await authorizedPatientRequest { api, accessToken in
                try await api.updatePreferences(input, accessToken: accessToken)
            }
            patientPreferences = response.data.preferences
            preferencesMessage = "Your preferences were saved. They do not change your care plan or urgent-help guidance."
            return true
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch {
            preferencesMessage = "We could not save your preferences. No change was queued. Check your connection and try again."
        }
        return false
    }

    private func performAuthentication(
        api: any PatientAPIService,
        _ operation: () async throws -> PatientTokenPair
    ) async {
        isBusy = true
        errorMessage = nil
        defer { isBusy = false }

        do {
            let pair = try await operation()
            try await persist(pair, api: api)
            try await installPatientExperience(accessToken: pair.accessToken)
        } catch {
            errorMessage = PatientFacingError.message(for: error)
        }
    }

    private func loadPatientExperience() async {
        guard let api else { return }
        isBusy = true
        errorMessage = nil
        defer { isBusy = false }

        do {
            let accessToken: String
            if let existingAccessToken = tokenStore.accessToken {
                accessToken = existingAccessToken
            } else if let refreshToken = tokenStore.refreshToken {
                accessToken = try await rotateTokens(using: refreshToken, api: api).accessToken
            } else {
                return
            }

            do {
                try await installPatientExperience(accessToken: accessToken)
            } catch PatientAPIError.unauthorized {
                guard let refreshToken = tokenStore.refreshToken else {
                    throw PatientSessionFailure.expired
                }
                let pair = try await rotateTokens(using: refreshToken, api: api)
                try await installPatientExperience(accessToken: pair.accessToken)
            }
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch {
            errorMessage = PatientFacingError.message(for: error)
        }
    }

    private func rotateTokens(
        using refreshToken: String,
        api: any PatientAPIService
    ) async throws -> PatientTokenPair {
        do {
            let pair = try await api.refresh(refreshToken: refreshToken)
            try await persist(pair, api: api)
            return pair
        } catch PatientAPIError.unauthorized {
            throw PatientSessionFailure.expired
        }
    }

    private func persist(
        _ pair: PatientTokenPair,
        api: any PatientAPIService
    ) async throws {
        do {
            try tokenStore.store(
                accessToken: pair.accessToken,
                refreshToken: pair.refreshToken
            )
        } catch {
            tokenStore.clear()
            try? await api.revoke(token: pair.refreshToken)
            throw PatientSessionFailure.secureStorageUnavailable
        }
    }

    private func installPatientExperience(accessToken: String) async throws {
        let loadedSnapshot = try await fetchPatientExperience(accessToken: accessToken)
        snapshot = loadedSnapshot
        patientPreferences = loadedSnapshot.preferences
        preferencesMessage = nil
        await loadMessagingOverview(for: loadedSnapshot, preferredAccessToken: accessToken)
    }

    private func fetchPatientExperience(accessToken: String) async throws -> PatientExperienceSnapshot {
        guard let api else { throw PatientAPIError.notConfigured }

        async let profileRequest = api.profile(accessToken: accessToken)
        async let encountersRequest = api.encounters(accessToken: accessToken)
        let (profile, encounters) = try await (profileRequest, encountersRequest)

        guard let selectedEncounter = encounters.data.encounters.first else {
            return .live(profile: profile, encounters: encounters)
        }

        async let todayRequest = fetchToday(
            encounter: selectedEncounter,
            accessToken: accessToken,
            api: api
        )
        async let pathwayRequest = fetchPathway(
            encounter: selectedEncounter,
            accessToken: accessToken,
            api: api
        )
        async let pathwayEventsRequest = fetchPathwayEvents(
            encounter: selectedEncounter,
            accessToken: accessToken,
            api: api
        )
        async let dischargeReadinessRequest = fetchDischargeReadiness(
            encounter: selectedEncounter,
            accessToken: accessToken,
            api: api
        )
        async let roundsSummaryRequest = fetchRoundsSummary(
            encounter: selectedEncounter,
            accessToken: accessToken,
            api: api
        )
        async let careTeamRequest = fetchCareTeam(
            encounter: selectedEncounter,
            accessToken: accessToken,
            api: api
        )
        let (today, pathway, pathwayEvents, dischargeReadiness, roundsSummary, careTeam) = try await (
            todayRequest,
            pathwayRequest,
            pathwayEventsRequest,
            dischargeReadinessRequest,
            roundsSummaryRequest,
            careTeamRequest
        )

        return .live(
            profile: profile,
            encounters: encounters,
            today: today,
            pathway: pathway,
            pathwayEvents: pathwayEvents,
            dischargeReadiness: dischargeReadiness,
            roundsSummary: roundsSummary,
            careTeam: careTeam
        )
    }

    private func fetchToday(
        encounter: PatientEncounterHandle,
        accessToken: String,
        api: any PatientAPIService
    ) async throws -> PatientTodayProjectionEnvelope? {
        guard encounter.scopes.contains("today:read") else { return nil }
        do {
            let response = try await api.today(encounterUUID: encounter.encounterUUID, accessToken: accessToken)
            return PatientStateVocabulary.isCompatible(serverVersion: response.meta.stateVocabularyVersion)
                ? response
                : nil
        } catch PatientAPIError.notFound {
            return nil
        }
    }

    private func fetchPathway(
        encounter: PatientEncounterHandle,
        accessToken: String,
        api: any PatientAPIService
    ) async throws -> PatientPathwayProjectionEnvelope? {
        guard encounter.scopes.contains("pathway:read") else { return nil }
        do {
            let response = try await api.pathway(encounterUUID: encounter.encounterUUID, accessToken: accessToken)
            return PatientStateVocabulary.isCompatible(serverVersion: response.meta.stateVocabularyVersion)
                ? response
                : nil
        } catch PatientAPIError.notFound {
            return nil
        }
    }

    private func fetchDischargeReadiness(
        encounter: PatientEncounterHandle,
        accessToken: String,
        api: any PatientAPIService
    ) async throws -> PatientDischargeReadinessProjectionEnvelope? {
        guard encounter.scopes.contains("pathway:read") else { return nil }
        do {
            let response = try await api.dischargeReadiness(
                encounterUUID: encounter.encounterUUID,
                accessToken: accessToken
            )
            return PatientStateVocabulary.isCompatible(serverVersion: response.meta.stateVocabularyVersion)
                ? response
                : nil
        } catch PatientAPIError.notFound {
            return nil
        }
    }

    private func fetchPathwayEvents(
        encounter: PatientEncounterHandle,
        accessToken: String,
        api: any PatientAPIService
    ) async throws -> PatientPathwayEventsProjectionEnvelope? {
        guard encounter.scopes.contains("pathway:read") else { return nil }
        do {
            let response = try await api.pathwayEvents(
                encounterUUID: encounter.encounterUUID,
                accessToken: accessToken
            )
            return PatientStateVocabulary.isCompatible(serverVersion: response.meta.stateVocabularyVersion)
                ? response
                : nil
        } catch PatientAPIError.notFound {
            return nil
        }
    }

    private func fetchRoundsSummary(
        encounter: PatientEncounterHandle,
        accessToken: String,
        api: any PatientAPIService
    ) async throws -> PatientRoundsSummaryProjectionEnvelope? {
        guard encounter.scopes.contains("pathway:read") else { return nil }
        do {
            let response = try await api.roundsSummary(
                encounterUUID: encounter.encounterUUID,
                accessToken: accessToken
            )
            return PatientStateVocabulary.isCompatible(serverVersion: response.meta.stateVocabularyVersion)
                ? response
                : nil
        } catch PatientAPIError.notFound {
            return nil
        }
    }

    private func fetchCareTeam(
        encounter: PatientEncounterHandle,
        accessToken: String,
        api: any PatientAPIService
    ) async throws -> PatientCareTeamProjectionEnvelope? {
        guard encounter.scopes.contains("care_team:read") else { return nil }
        do {
            let response = try await api.careTeam(encounterUUID: encounter.encounterUUID, accessToken: accessToken)
            return PatientStateVocabulary.isCompatible(serverVersion: response.meta.stateVocabularyVersion)
                ? response
                : nil
        } catch PatientAPIError.notFound {
            return nil
        }
    }

    func refreshMessaging() async {
        guard let snapshot else {
            resetMessaging()
            return
        }
        await loadMessagingOverview(for: snapshot)
    }

    func openMessageThread(threadUUID: String) async {
        guard let snapshot, snapshot.canReadMessaging,
              UUID(uuidString: threadUUID) != nil
        else {
            selectedMessageThread = nil
            messagingMessage = "This conversation is not available."
            return
        }

        #if DEBUG
        if snapshot.isSynthetic {
            selectedMessageThread = PatientMessageThreadDetail.syntheticReferenceThreads
                .first(where: { $0.threadUUID == threadUUID })
                .map { thread in
                    PatientMessageThreadDetailResult(
                        thread: thread,
                        immediateHelp: .syntheticReference
                    )
                }
            return
        }
        #endif

        guard !isMessagingBusy else { return }
        isMessagingBusy = true
        messagingMessage = nil
        defer { isMessagingBusy = false }

        do {
            selectedMessageThread = try await requestMessageThread(threadUUID: threadUUID)
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch {
            selectedMessageThread = nil
            messagingMessage = "We could not open this conversation. No message was sent or queued. Try again when you are online."
        }
    }

    func createMessageThread(topicCode: String, message: String) async -> Bool {
        guard let snapshot, snapshot.canWriteMessaging,
              case .ready(let overview) = messagingState,
              let encounterUUID = snapshot.encounterUUID,
              overview.topics.contains(where: { $0.code == topicCode })
        else {
            messagingMessage = "New messages are not available for this care connection."
            return false
        }

        let trimmedMessage = message.trimmingCharacters(in: .whitespacesAndNewlines)
        guard (1 ... 2_000).contains(trimmedMessage.count), !isMessagingBusy else {
            messagingMessage = "Enter a message between 1 and 2,000 characters."
            return false
        }

        let clientMessageUUID = UUID().uuidString.lowercased()
        let idempotencyKey = UUID().uuidString.lowercased()
        let input = PatientMessageThreadCreateInput(
            topicCode: topicCode,
            message: trimmedMessage,
            clientMessageUUID: clientMessageUUID,
            urgentGuidanceVersion: overview.immediateHelp.version
        )

        isMessagingBusy = true
        messagingMessage = nil
        defer { isMessagingBusy = false }

        do {
            let result = try await authorizedPatientRequest { api, accessToken in
                try await api.createMessageThread(
                    encounterUUID: encounterUUID,
                    input: input,
                    idempotencyKey: idempotencyKey,
                    accessToken: accessToken
                )
            }
            replaceThreadSummary(result.data.thread, prependWhenNew: true)
            messagingMessage = "Your message was sent to your care team."
            return true
        } catch {
            await handleMessagingMutationFailure(error, threadUUID: nil)
            return false
        }
    }

    /// Sends a request to explain an education item only when that item came
    /// from the released pathway snapshot. The API owns the source binding;
    /// this client never sends a completion, comprehension, consent, or
    /// clinician-assessment value.
    func requestEducationClarification(educationItemUUID: String, message: String) async -> Bool {
        guard let snapshot,
              snapshot.canWriteMessaging,
              let encounterUUID = snapshot.encounterUUID,
              UUID(uuidString: educationItemUUID) != nil,
              snapshot.pathwayEducation.contains(where: { $0.itemUUID == educationItemUUID })
        else {
            messagingMessage = "A secure request to explain this information is not available for this care connection. You can ask your bedside nurse for help."
            return false
        }

        let trimmedMessage = message.trimmingCharacters(in: .whitespacesAndNewlines)
        guard (1 ... 2_000).contains(trimmedMessage.count), !isMessagingBusy else {
            messagingMessage = "Enter a question between 1 and 2,000 characters."
            return false
        }

        isMessagingBusy = true
        messagingMessage = nil
        defer { isMessagingBusy = false }

        do {
            let overview: PatientMessagingOverview
            if case .ready(let available) = messagingState {
                overview = available
            } else {
                overview = try await requestMessagingOverview()
                messagingState = .ready(overview)
            }

            let input = PatientEducationClarificationInput(
                message: trimmedMessage,
                clientMessageUUID: UUID().uuidString.lowercased(),
                urgentGuidanceVersion: overview.immediateHelp.version
            )
            let result = try await authorizedPatientRequest { api, accessToken in
                try await api.requestEducationClarification(
                    encounterUUID: encounterUUID,
                    educationItemUUID: educationItemUUID,
                    input: input,
                    idempotencyKey: UUID().uuidString.lowercased(),
                    accessToken: accessToken
                )
            }
            replaceThreadSummary(result.data.thread, prependWhenNew: true)
            messagingMessage = "Your request for an explanation was sent to your care team. It does not record that you understand, complete, or agree to this information."
            return true
        } catch {
            await handleMessagingMutationFailure(error, threadUUID: nil)
            return false
        }
    }

    func sendMessage(threadUUID: String, message: String) async -> Bool {
        guard let snapshot, snapshot.canWriteMessaging,
              let detailResult = selectedMessageThread,
              detailResult.thread.threadUUID == threadUUID,
              detailResult.thread.status == .open
        else {
            messagingMessage = "This conversation cannot accept a new message."
            return false
        }

        let trimmedMessage = message.trimmingCharacters(in: .whitespacesAndNewlines)
        guard (1 ... 2_000).contains(trimmedMessage.count), !isMessagingBusy else {
            messagingMessage = "Enter a message between 1 and 2,000 characters."
            return false
        }

        let clientMessageUUID = UUID().uuidString.lowercased()
        let idempotencyKey = UUID().uuidString.lowercased()
        let input = PatientMessageCreateInput(
            message: trimmedMessage,
            clientMessageUUID: clientMessageUUID,
            threadVersion: detailResult.thread.version,
            urgentGuidanceVersion: detailResult.immediateHelp.version
        )

        isMessagingBusy = true
        messagingMessage = nil
        defer { isMessagingBusy = false }

        do {
            let result = try await authorizedPatientRequest { api, accessToken in
                try await api.sendMessage(
                    threadUUID: threadUUID,
                    input: input,
                    idempotencyKey: idempotencyKey,
                    accessToken: accessToken
                )
            }
            replaceThreadSummary(result.data.thread)
            selectedMessageThread = PatientMessageThreadDetailResult(
                thread: detailResult.thread.replacingSummary(
                    result.data.thread,
                    appending: result.data.message
                ),
                immediateHelp: detailResult.immediateHelp
            )
            messagingMessage = "Your message was sent to your care team."
            return true
        } catch {
            await handleMessagingMutationFailure(error, threadUUID: threadUUID)
            return false
        }
    }

    func amendMessage(
        threadUUID: String,
        messageUUID: String,
        action: PatientMessageAmendmentAction,
        message: String? = nil
    ) async -> Bool {
        guard let snapshot, snapshot.canWriteMessaging,
              let detailResult = selectedMessageThread,
              detailResult.thread.threadUUID == threadUUID,
              detailResult.thread.status == .open,
              let source = detailResult.thread.messages.first(where: { $0.messageUUID == messageUUID }),
              source.senderDisplayRole == .patient,
              source.messageKind == .message,
              !detailResult.thread.messages.contains(where: {
                  $0.relatesToMessageUUID == messageUUID
                      && ($0.messageKind == .correction || $0.messageKind == .retraction)
              }),
              !isMessagingBusy
        else {
            messagingMessage = "This message can no longer be corrected or withdrawn. Refresh the conversation to review it."
            return false
        }

        let trimmedMessage = message?.trimmingCharacters(in: .whitespacesAndNewlines)
        if action == .correction,
           !(1 ... 2_000).contains(trimmedMessage?.count ?? 0) {
            messagingMessage = "Enter a correction between 1 and 2,000 characters."
            return false
        }
        if action == .retraction, message != nil {
            messagingMessage = "A withdrawal does not include a replacement message."
            return false
        }

        let input = PatientMessageAmendmentInput(
            action: action,
            message: action == .correction ? trimmedMessage : nil,
            clientMessageUUID: UUID().uuidString.lowercased(),
            threadVersion: detailResult.thread.version,
            urgentGuidanceVersion: detailResult.immediateHelp.version
        )
        let idempotencyKey = UUID().uuidString.lowercased()

        isMessagingBusy = true
        messagingMessage = nil
        defer { isMessagingBusy = false }

        do {
            let result = try await authorizedPatientRequest { api, accessToken in
                try await api.amendMessage(
                    threadUUID: threadUUID,
                    messageUUID: messageUUID,
                    input: input,
                    idempotencyKey: idempotencyKey,
                    accessToken: accessToken
                )
            }
            replaceThreadSummary(result.data.thread)
            selectedMessageThread = PatientMessageThreadDetailResult(
                thread: detailResult.thread.replacingSummary(
                    result.data.thread,
                    appending: result.data.message
                ),
                immediateHelp: detailResult.immediateHelp
            )
            messagingMessage = action == .correction
                ? "Your correction was sent to your care team. The earlier message remains in the conversation history."
                : "Your withdrawal was sent to your care team. The earlier message remains in the conversation history."
            return true
        } catch {
            await handleMessagingMutationFailure(error, threadUUID: threadUUID)
            return false
        }
    }

    func closeMessageThread(
        threadUUID: String,
        reason: PatientMessageThreadCloseReason
    ) async -> Bool {
        guard let snapshot, snapshot.canWriteMessaging,
              let detailResult = selectedMessageThread,
              detailResult.thread.threadUUID == threadUUID,
              detailResult.thread.status == .open,
              !isMessagingBusy
        else {
            messagingMessage = "This conversation cannot be closed right now."
            return false
        }

        let input = PatientMessageThreadCloseInput(
            threadVersion: detailResult.thread.version,
            closeReason: reason
        )
        let idempotencyKey = UUID().uuidString.lowercased()

        isMessagingBusy = true
        messagingMessage = nil
        defer { isMessagingBusy = false }

        do {
            let result = try await authorizedPatientRequest { api, accessToken in
                try await api.closeMessageThread(
                    threadUUID: threadUUID,
                    input: input,
                    idempotencyKey: idempotencyKey,
                    accessToken: accessToken
                )
            }
            replaceThreadSummary(result.data.thread)
            selectedMessageThread = PatientMessageThreadDetailResult(
                thread: detailResult.thread.replacingSummary(result.data.thread),
                immediateHelp: detailResult.immediateHelp
            )
            messagingMessage = "This conversation is now closed."
            return true
        } catch {
            await handleMessagingMutationFailure(error, threadUUID: threadUUID)
            return false
        }
    }

    private func loadMessagingOverview(
        for snapshot: PatientExperienceSnapshot,
        preferredAccessToken: String? = nil
    ) async {
        selectedMessageThread = nil
        messagingMessage = nil

        guard snapshot.canReadMessaging, snapshot.encounterUUID != nil else {
            messagingState = .notGranted
            return
        }

        #if DEBUG
        if snapshot.isSynthetic {
            messagingState = .ready(.syntheticReference)
            return
        }
        #endif

        guard liveAccessAvailable else {
            messagingState = .disabled
            return
        }
        guard !isMessagingBusy else { return }

        messagingState = .loading
        isMessagingBusy = true
        defer { isMessagingBusy = false }

        do {
            messagingState = .ready(
                try await requestMessagingOverview(preferredAccessToken: preferredAccessToken)
            )
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch let error where isMessagingDisabled(error) {
            messagingState = .disabled
        } catch {
            messagingState = .failed
            messagingMessage = "Messaging is temporarily unavailable. No message was sent or queued. Try again when you are online."
        }
    }

    private func requestMessagingOverview(
        preferredAccessToken: String? = nil
    ) async throws -> PatientMessagingOverview {
        guard let encounterUUID = snapshot?.encounterUUID else {
            throw PatientAPIError.invalidBoundary
        }

        let responses = try await authorizedPatientRequest(
            preferredAccessToken: preferredAccessToken
        ) { api, accessToken in
            async let topics = api.messageTopics(
                encounterUUID: encounterUUID,
                accessToken: accessToken
            )
            async let threads = api.messageThreads(
                encounterUUID: encounterUUID,
                accessToken: accessToken
            )
            return try await (topics, threads)
        }

        guard responses.0.data.immediateHelp == responses.1.data.immediateHelp,
              !responses.0.data.immediateHelp.version.isEmpty,
              !responses.0.data.immediateHelp.text.isEmpty,
              responses.0.data.topics.count <= 25,
              responses.1.data.threads.count <= 50,
              responses.1.data.threads.allSatisfy({
                  UUID(uuidString: $0.threadUUID) != nil && $0.version >= 1
              })
        else { throw PatientAPIError.invalidResponse }

        return PatientMessagingOverview(
            topics: responses.0.data.topics,
            threads: responses.1.data.threads,
            immediateHelp: responses.0.data.immediateHelp
        )
    }

    private func requestMessageThread(threadUUID: String) async throws -> PatientMessageThreadDetailResult {
        guard UUID(uuidString: threadUUID) != nil else {
            throw PatientAPIError.invalidBoundary
        }
        let response = try await authorizedPatientRequest { api, accessToken in
            try await api.messageThread(threadUUID: threadUUID, accessToken: accessToken)
        }
        guard response.data.thread.threadUUID.caseInsensitiveCompare(threadUUID) == .orderedSame,
              response.data.thread.version >= 1,
              !response.data.immediateHelp.version.isEmpty,
              !response.data.immediateHelp.text.isEmpty,
              response.data.thread.messages.allSatisfy({ UUID(uuidString: $0.messageUUID) != nil })
        else { throw PatientAPIError.invalidResponse }
        return response.data
    }

    private func authorizedPatientRequest<Result>(
        preferredAccessToken: String? = nil,
        operation: (any PatientAPIService, String) async throws -> Result
    ) async throws -> Result {
        guard let api else { throw PatientAPIError.notConfigured }

        let accessToken: String
        if let preferredAccessToken {
            accessToken = preferredAccessToken
        } else if let storedAccessToken = tokenStore.accessToken {
            accessToken = storedAccessToken
        } else if let refreshToken = tokenStore.refreshToken {
            accessToken = try await rotateTokens(using: refreshToken, api: api).accessToken
        } else {
            throw PatientSessionFailure.expired
        }

        do {
            return try await operation(api, accessToken)
        } catch PatientAPIError.unauthorized {
            guard let refreshToken = tokenStore.refreshToken else {
                throw PatientSessionFailure.expired
            }
            let pair = try await rotateTokens(using: refreshToken, api: api)
            return try await operation(api, pair.accessToken)
        }
    }

    private func requestPatientSessions() async throws -> [PatientSessionSummary] {
        let response = try await authorizedPatientRequest { api, accessToken in
            try await api.sessions(accessToken: accessToken)
        }
        guard response.data.sessions.count <= 100,
              response.data.sessions.filter(\.current).count == 1
        else { throw PatientAPIError.invalidResponse }
        return response.data.sessions
    }

    private func isSessionManagementUnavailable(_ error: Error) -> Bool {
        guard let apiError = error as? PatientAPIError else { return false }
        switch apiError {
        case .notFound, .notConfigured, .transport, .invalidResponse:
            return true
        case .server(let statusCode, _, _):
            return statusCode >= 500 || statusCode == 429 || statusCode == 403
        case .invalidBoundary, .unauthorized:
            return false
        }
    }

    private func isSessionManagementFeatureDisabled(_ error: Error) -> Bool {
        guard let apiError = error as? PatientAPIError else { return false }
        if case .notFound = apiError { return true }
        if case .server(let statusCode, _, _) = apiError {
            return statusCode == 404
        }
        return false
    }

    private func replaceThreadSummary(
        _ summary: PatientMessageThreadSummary,
        prependWhenNew: Bool = false
    ) {
        guard case .ready(let overview) = messagingState else { return }
        var threads = overview.threads.filter { $0.threadUUID != summary.threadUUID }
        if prependWhenNew {
            threads.insert(summary, at: 0)
        } else if let originalIndex = overview.threads.firstIndex(where: { $0.threadUUID == summary.threadUUID }) {
            threads.insert(summary, at: min(originalIndex, threads.count))
        } else {
            threads.insert(summary, at: 0)
        }
        messagingState = .ready(
            PatientMessagingOverview(
                topics: overview.topics,
                threads: threads,
                immediateHelp: overview.immediateHelp
            )
        )
    }

    private func handleMessagingMutationFailure(
        _ error: Error,
        threadUUID: String?
    ) async {
        if isMessagingConflict(error) {
            await refreshAfterMessagingConflict(threadUUID: threadUUID)
            messagingMessage = "This conversation changed, so we refreshed it. Review the latest information before trying again. Nothing was sent or queued."
        } else if error is PatientSessionFailure {
            expireLocalSession()
        } else if let apiError = error as? PatientAPIError,
                  case .unauthorized = apiError {
            expireLocalSession()
        } else if isMessagingDisabled(error) {
            messagingState = .disabled
            selectedMessageThread = nil
            messagingMessage = "Messaging is not available for this care connection. Nothing was sent or queued."
        } else {
            messagingMessage = "We could not send that message. Nothing was queued for later. Check your connection and review your message before trying again."
        }
    }

    private func refreshAfterMessagingConflict(threadUUID: String?) async {
        do {
            messagingState = .ready(try await requestMessagingOverview())
            if let threadUUID {
                selectedMessageThread = try await requestMessageThread(threadUUID: threadUUID)
            }
        } catch PatientSessionFailure.expired {
            expireLocalSession()
        } catch PatientAPIError.unauthorized {
            expireLocalSession()
        } catch let error where isMessagingDisabled(error) {
            messagingState = .disabled
            selectedMessageThread = nil
        } catch {
            messagingState = .failed
            selectedMessageThread = nil
        }
    }

    private func isMessagingConflict(_ error: Error) -> Bool {
        guard let apiError = error as? PatientAPIError,
              case .server(_, let code, _) = apiError
        else { return false }
        return [
            "stale_thread_version",
            "urgent_guidance_changed",
            "thread_closed",
            "idempotency_conflict",
        ].contains(code)
    }

    private func isMessagingDisabled(_ error: Error) -> Bool {
        guard let apiError = error as? PatientAPIError else { return false }
        if case .notFound = apiError { return true }
        if case .server(let statusCode, _, _) = apiError {
            return statusCode == 404 || statusCode == 503
        }
        return false
    }

    private func resetMessaging() {
        messagingState = .notGranted
        selectedMessageThread = nil
        isMessagingBusy = false
        messagingMessage = nil
    }

    private func resetSessionManagement() {
        sessionManagementGeneration += 1
        sessionManagementState = .idle
        patientSessions = []
        selectedSessionForRevocation = nil
        sessionManagementMessage = nil
    }

    private func resetPreferences() {
        patientPreferences = PatientPreferences()
        isSavingPreferences = false
        preferencesMessage = nil
    }

    private func expireLocalSession(
        message: String? = "Your secure session ended. Sign in again to continue."
    ) {
        tokenStore.clear()
        snapshot = nil
        resetMessaging()
        resetSessionManagement()
        resetPreferences()
        errorMessage = message
    }

    private static let sessionManagementUnavailableMessage =
        "Device management is temporarily unavailable. Your care view is still available."
    private static let sessionManagementDisabledMessage =
        "Device management is not available for this account. Your care view is still available."
}

enum PatientSessionManagementState: Equatable {
    case idle
    case loading
    case ready
    case revoking
    case disabled
    case unavailable
    case failed
}

#if DEBUG
private extension Array where Element == PatientSessionSummary {
    static let syntheticReference = [
        PatientSessionSummary(
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
            lastSeenAt: "2026-07-19T02:22:31.000000Z",
            expiresAt: "2026-08-19T14:22:31.000000Z",
            createdAt: "2026-07-19T14:22:31.000000Z"
        ),
        PatientSessionSummary(
            sessionUUID: "019f0000-0000-7000-8000-000000000082",
            current: false,
            status: .active,
            device: PatientSessionDevice(
                uuid: "019f0000-0000-7000-8000-000000000092",
                platform: .android,
                name: "Home tablet",
                appVersion: "0.1.0",
                osVersion: "16"
            ),
            authMethod: .password,
            assuranceLevel: nil,
            lastSeenAt: "2026-07-18T18:10:00.000000Z",
            expiresAt: "2026-08-18T18:10:00.000000Z",
            createdAt: "2026-07-18T18:10:00.000000Z"
        ),
    ]
}
#endif

private extension PatientMessageThreadDetail {
    func replacingSummary(
        _ summary: PatientMessageThreadSummary,
        appending message: PatientVisibleMessage? = nil
    ) -> PatientMessageThreadDetail {
        PatientMessageThreadDetail(
            threadUUID: summary.threadUUID,
            topic: summary.topic,
            status: summary.status,
            ownershipState: summary.ownershipState,
            expectedResponseWindow: summary.expectedResponseWindow,
            version: summary.version,
            lastMessageAt: summary.lastMessageAt,
            createdAt: summary.createdAt,
            closedAt: summary.closedAt,
            closeReason: summary.closeReason,
            messages: messages + (message.map { [$0] } ?? [])
        )
    }
}

private enum PatientSessionFailure: Error, Equatable {
    case expired
    case secureStorageUnavailable
}

enum PatientFacingError {
    static func message(for error: Error) -> String {
        if let sessionFailure = error as? PatientSessionFailure,
           sessionFailure == .secureStorageUnavailable {
            return "We could not protect your secure session on this device. No session was kept. Please try again."
        }
        if let apiError = error as? PatientAPIError {
            return switch apiError {
            case .server(_, _, let message), .unauthorized(_, let message): message
            case .notFound:
                "The requested patient information is not available."
            case .notConfigured:
                "Patient API access is not configured for this build."
            case .invalidBoundary, .invalidResponse:
                "We could not safely open your care information. Please try again later."
            case .transport:
                "We could not reach the care service. Check your connection and try again."
            }
        } else {
            return "We could not open your care information. Please try again later."
        }
    }
}
