import Foundation

enum PatientCommunicationMutationAction: String, Equatable {
    case claim
    case reply
    case close

    var label: String {
        switch self {
        case .claim: return "claim"
        case .reply: return "reply"
        case .close: return "closure"
        }
    }
}

private struct PatientCommunicationResponseVerificationError: Error {
    let message: String
}

@MainActor
final class PatientCommunicationsViewModel: ObservableObject {
    @Published private(set) var inbox: [PatientCommunicationWorkItem] = []
    @Published private(set) var thread: PatientCommunicationWorkItem?
    @Published private(set) var routeCandidates: PatientCommunicationRouteCandidatesData?
    @Published private(set) var isLoadingInbox = false
    @Published private(set) var isLoadingThread = false
    @Published private(set) var isLoadingRouting = false
    @Published private(set) var isWorking = false
    @Published private(set) var inboxUnavailable = false
    @Published private(set) var threadUnavailable = false
    @Published private(set) var routingUnavailable = false
    @Published private(set) var pendingMutationRetryAction: PatientCommunicationMutationAction?
    @Published private(set) var pendingRoutingRetryAction: PatientCommunicationRoutingAction?
    @Published private(set) var needsReauth = false
    @Published private(set) var sensitiveContentPurgeGeneration = 0
    @Published var inboxErrorMessage: String?
    @Published var routingErrorMessage: String?
    @Published private(set) var routingConfirmationMessage: String?
    @Published var actionMessage: String?

    private let repository: PatientCommunicationsRepository
    private let makeUUID: () -> UUID

    /// Attempts live only in process memory. They preserve UUID identity for an
    /// explicit user retry after an ambiguous network failure, but are erased on
    /// conflict, success, backgrounding, or any payload edit. Nothing is queued.
    private var claimAttempt: ClaimAttempt?
    private var replyAttempt: ReplyAttempt?
    private var closeAttempt: CloseAttempt?
    private var routingAttempt: RoutingAttempt?
    /// Detail reads normally remain single-flight. A newer authoritative inbox
    /// projection may supersede one already in flight; the request sequence
    /// prevents that older response from restoring stale PHI or action state.
    private var threadRequestSequence = 0
    private var activeThreadRequestSequence = 0
    /// Opaque UUIDs whose source projection must not be republished after a
    /// possible or confirmed cross-pool reroute during this foreground session.
    private var quarantinedRerouteWorkItemUUIDs: Set<String> = []

    #if DEBUG
    var hasPendingReplyAttempt: Bool {
        replyAttempt != nil || pendingMutationRetryAction == .reply
    }
    #endif

    init(
        repository: PatientCommunicationsRepository,
        makeUUID: @escaping () -> UUID = UUID.init
    ) {
        self.repository = repository
        self.makeUUID = makeUUID
    }

    func loadInbox(bearer: String) async {
        guard !needsReauth, !bearer.isEmpty, !isLoadingInbox else { return }
        let requestGeneration = sensitiveContentPurgeGeneration
        isLoadingInbox = true
        defer { isLoadingInbox = false }

        do {
            let result = try await repository.patientCommunicationsInbox(bearer: bearer)
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return }
            let activeWorkItemUUID = activeRestrictedWorkItemUUID
            let visibleItems = result.items.filter {
                !quarantinedRerouteWorkItemUUIDs.contains($0.workItemUuid)
            }
            inbox = visibleItems
            inboxUnavailable = false
            inboxErrorMessage = nil
            if let activeWorkItemUUID {
                guard let visibleItem = visibleItems.first(where: {
                    $0.workItemUuid == activeWorkItemUUID
                }) else {
                    if !quarantinedRerouteWorkItemUUIDs.contains(activeWorkItemUUID) {
                        purgeAfterInboxOmission(workItemUUID: activeWorkItemUUID)
                    }
                    return
                }

                if let currentThread = thread,
                   currentThread.workItemUuid == activeWorkItemUUID,
                   Self.restrictedProjectionDrifted(from: currentThread, to: visibleItem) {
                    purgeForRetainedInboxDrift(workItemUUID: activeWorkItemUUID)
                    await loadThread(
                        workItemUUID: activeWorkItemUUID,
                        bearer: bearer,
                        supersedingExistingRequest: true
                    )
                }
            }
        } catch {
            guard !Task.isCancelled else { return }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeForInboxAuthorizationDenial()
                return
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return }
            inbox = []
            if Self.isServiceUnavailable(error) {
                inboxUnavailable = true
                inboxErrorMessage = nil
            } else {
                inboxUnavailable = false
                inboxErrorMessage = Self.safeLoadMessage(error)
            }
        }
    }

    func loadThread(workItemUUID: String, bearer: String) async {
        await loadThread(
            workItemUUID: workItemUUID,
            bearer: bearer,
            supersedingExistingRequest: false
        )
    }

    private func loadThread(
        workItemUUID: String,
        bearer: String,
        supersedingExistingRequest: Bool
    ) async {
        guard !needsReauth, !bearer.isEmpty else { return }
        guard supersedingExistingRequest || !isLoadingThread else { return }

        threadRequestSequence &+= 1
        let requestSequence = threadRequestSequence
        activeThreadRequestSequence = requestSequence
        let requestGeneration = sensitiveContentPurgeGeneration
        isLoadingThread = true
        defer {
            if activeThreadRequestSequence == requestSequence {
                isLoadingThread = false
            }
        }

        do {
            let result = try await repository.patientCommunicationThread(
                workItemUUID: workItemUUID,
                bearer: bearer
            )
            guard !Task.isCancelled,
                  activeThreadRequestSequence == requestSequence,
                  requestGeneration == sensitiveContentPurgeGeneration else { return }
            thread = result
            threadUnavailable = false
        } catch {
            guard !Task.isCancelled,
                  activeThreadRequestSequence == requestSequence,
                  requestGeneration == sensitiveContentPurgeGeneration else { return }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: workItemUUID)
                return
            }
            signalSensitiveContentPurge()
            thread = nil
            routeCandidates = nil
            threadUnavailable = Self.isServiceUnavailable(error)
            routingUnavailable = Self.isServiceUnavailable(error)
            if !threadUnavailable {
                actionMessage = Self.safeLoadMessage(error)
            }
        }
    }

    func loadRouteCandidates(
        for item: PatientCommunicationWorkItem,
        canRespond: Bool,
        bearer: String
    ) async {
        guard !needsReauth, canRespond, item.isOpen, !bearer.isEmpty, !isLoadingRouting else {
            if !canRespond || !item.isOpen { clearRoutingState() }
            return
        }
        let requestGeneration = sensitiveContentPurgeGeneration
        isLoadingRouting = true
        defer { isLoadingRouting = false }

        do {
            let result = try await repository.patientCommunicationRouteCandidates(
                workItemUUID: item.workItemUuid,
                bearer: bearer
            )
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return }
            guard result.matches(item) else {
                routeCandidates = nil
                routingUnavailable = false
                if pendingMutationRetryAction == nil && pendingRoutingRetryAction == nil {
                    routingErrorMessage = "Ownership options changed with the conversation. Refresh before taking a routing action."
                    routingAttempt = nil
                }
                return
            }
            if pendingMutationRetryAction != nil || pendingRoutingRetryAction != nil {
                // An ambiguous request must be resolved by an explicit replay
                // before newly discovered actions can replace its exact tuple.
                routeCandidates = nil
                routingUnavailable = false
                routingErrorMessage = nil
                return
            } else if let attempt = routingAttempt, !attempt.matches(result) {
                routingAttempt = nil
            }
            routeCandidates = result
            routingUnavailable = false
            routingErrorMessage = nil
        } catch {
            guard !Task.isCancelled else { return }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: item.workItemUuid)
                return
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return }
            routeCandidates = nil
            if Self.isServiceUnavailable(error) {
                // A read-only 503 is unavailable, not an authorization loss.
                // Preserve an already-ambiguous exact mutation identity, if any.
                routingUnavailable = true
                routingErrorMessage = nil
            } else {
                // A candidate refresh can fail after an ambiguous write. Keep
                // that exact attempt identity in memory until a later verified
                // candidate response proves it stale or the user backgrounds.
                routingUnavailable = false
                routingErrorMessage = Self.safeRoutingLoadMessage(error)
            }
        }
    }

    func claim(_ item: PatientCommunicationWorkItem, canRespond: Bool, bearer: String) async {
        guard !needsReauth,
              canRespond,
              item.canClaim,
              !isWorking,
              !bearer.isEmpty,
              pendingMutationRetryAction == nil,
              pendingRoutingRetryAction == nil else { return }
        let requestGeneration = sensitiveContentPurgeGeneration
        actionMessage = nil
        isWorking = true
        defer { isWorking = false }

        let attempt: ClaimAttempt
        if let existing = claimAttempt, existing.matches(item) {
            attempt = existing
        } else {
            attempt = ClaimAttempt(item: item, idempotencyKey: makeUUID())
            claimAttempt = attempt
        }

        do {
            let result = try await repository.claimPatientCommunication(
                workItemUUID: attempt.workItemUUID,
                workItemVersion: attempt.workItemVersion,
                threadVersion: attempt.threadVersion,
                idempotencyKey: attempt.idempotencyKey,
                bearer: bearer
            )
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return }
            guard let workItem = result.workItem, workItem.workItemUuid == attempt.workItemUUID else {
                throw PatientCommunicationResponseVerificationError(
                    message: "The claim response could not be verified."
                )
            }
            clearAllMutationIdentities()
            thread = workItem
            actionMessage = result.replayed ? "Your earlier claim was confirmed." : "You now own this conversation."
            await reconcile(workItemUUID: item.workItemUuid, bearer: bearer)
        } catch {
            guard !Task.isCancelled else { return }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: item.workItemUuid)
                return
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return }
            await handleMutationFailure(
                error,
                action: .claim,
                workItemUUID: item.workItemUuid,
                bearer: bearer
            )
        }
    }

    /// Returns true only when the patient-visible reply is confirmed by the API.
    func reply(
        _ item: PatientCommunicationWorkItem,
        message: String,
        canRespond: Bool,
        bearer: String
    ) async -> Bool {
        let body = message.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !needsReauth,
              canRespond,
              item.canReply,
              !isWorking,
              !bearer.isEmpty,
              !body.isEmpty,
              body.count <= 4_000,
              pendingMutationRetryAction == nil,
              pendingRoutingRetryAction == nil else {
            return false
        }
        let requestGeneration = sensitiveContentPurgeGeneration
        actionMessage = nil
        isWorking = true
        defer { isWorking = false }

        let attempt: ReplyAttempt
        if let existing = replyAttempt, existing.matches(item, body: body) {
            attempt = existing
        } else {
            attempt = ReplyAttempt(
                item: item,
                body: body,
                clientMessageUUID: makeUUID(),
                idempotencyKey: makeUUID()
            )
            replyAttempt = attempt
        }

        do {
            let result = try await repository.replyToPatientCommunication(
                workItemUUID: attempt.workItemUUID,
                workItemVersion: attempt.workItemVersion,
                threadVersion: attempt.threadVersion,
                message: attempt.body,
                clientMessageUUID: attempt.clientMessageUUID,
                idempotencyKey: attempt.idempotencyKey,
                bearer: bearer
            )
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return false }
            guard let workItem = result.workItem, workItem.workItemUuid == attempt.workItemUUID else {
                throw PatientCommunicationResponseVerificationError(
                    message: "The reply response could not be verified."
                )
            }
            clearAllMutationIdentities()
            thread = workItem
            actionMessage = result.replayed ? "Your earlier reply was confirmed." : "Reply delivered to the patient."
            await reconcile(workItemUUID: item.workItemUuid, bearer: bearer)
            return true
        } catch {
            guard !Task.isCancelled else { return false }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return false
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: item.workItemUuid)
                return false
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return false }
            await handleMutationFailure(
                error,
                action: .reply,
                workItemUUID: item.workItemUuid,
                bearer: bearer
            )
            return false
        }
    }

    func close(
        _ item: PatientCommunicationWorkItem,
        reason: PatientCommunicationCloseReason,
        canRespond: Bool,
        bearer: String
    ) async {
        guard !needsReauth,
              canRespond,
              item.canClose,
              !isWorking,
              !bearer.isEmpty,
              pendingMutationRetryAction == nil,
              pendingRoutingRetryAction == nil else { return }
        let requestGeneration = sensitiveContentPurgeGeneration
        actionMessage = nil
        isWorking = true
        defer { isWorking = false }

        let attempt: CloseAttempt
        if let existing = closeAttempt, existing.matches(item, reason: reason) {
            attempt = existing
        } else {
            attempt = CloseAttempt(item: item, reason: reason, idempotencyKey: makeUUID())
            closeAttempt = attempt
        }

        do {
            let result = try await repository.closePatientCommunication(
                workItemUUID: attempt.workItemUUID,
                workItemVersion: attempt.workItemVersion,
                threadVersion: attempt.threadVersion,
                reasonCode: attempt.reason,
                idempotencyKey: attempt.idempotencyKey,
                bearer: bearer
            )
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return }
            guard let workItem = result.workItem, workItem.workItemUuid == attempt.workItemUUID else {
                throw PatientCommunicationResponseVerificationError(
                    message: "The closure response could not be verified."
                )
            }
            clearAllMutationIdentities()
            thread = workItem
            actionMessage = result.replayed ? "Your earlier closure was confirmed." : "Conversation closed."
            await reconcile(workItemUUID: item.workItemUuid, bearer: bearer)
        } catch {
            guard !Task.isCancelled else { return }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: item.workItemUuid)
                return
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return }
            await handleMutationFailure(
                error,
                action: .close,
                workItemUUID: item.workItemUuid,
                bearer: bearer
            )
        }
    }

    /// Replays only the exact in-memory claim/reply/close tuple whose outcome
    /// was ambiguous. The refreshed projection is deliberately not an input:
    /// it may have advanced because the first request committed before its
    /// response was lost. This method is called only after a separate explicit
    /// UI confirmation and never automatically.
    @discardableResult
    func retryPendingMutation(canRespond: Bool, bearer: String) async -> Bool {
        guard !needsReauth,
              canRespond,
              !isWorking,
              !bearer.isEmpty,
              pendingRoutingRetryAction == nil,
              let action = pendingMutationRetryAction else {
            return false
        }

        let workItemUUID: String
        switch action {
        case .claim:
            guard let attempt = claimAttempt else { return false }
            workItemUUID = attempt.workItemUUID
        case .reply:
            guard let attempt = replyAttempt else { return false }
            workItemUUID = attempt.workItemUUID
        case .close:
            guard let attempt = closeAttempt else { return false }
            workItemUUID = attempt.workItemUUID
        }

        let requestGeneration = sensitiveContentPurgeGeneration
        actionMessage = nil
        isWorking = true
        defer { isWorking = false }

        do {
            let result: PatientCommunicationMutationData
            switch action {
            case .claim:
                guard let attempt = claimAttempt else { return false }
                result = try await repository.claimPatientCommunication(
                    workItemUUID: attempt.workItemUUID,
                    workItemVersion: attempt.workItemVersion,
                    threadVersion: attempt.threadVersion,
                    idempotencyKey: attempt.idempotencyKey,
                    bearer: bearer
                )
                try Self.verifyMutationReplay(result, claim: attempt)
            case .reply:
                guard let attempt = replyAttempt else { return false }
                result = try await repository.replyToPatientCommunication(
                    workItemUUID: attempt.workItemUUID,
                    workItemVersion: attempt.workItemVersion,
                    threadVersion: attempt.threadVersion,
                    message: attempt.body,
                    clientMessageUUID: attempt.clientMessageUUID,
                    idempotencyKey: attempt.idempotencyKey,
                    bearer: bearer
                )
                try Self.verifyMutationReplay(result, reply: attempt)
            case .close:
                guard let attempt = closeAttempt else { return false }
                result = try await repository.closePatientCommunication(
                    workItemUUID: attempt.workItemUUID,
                    workItemVersion: attempt.workItemVersion,
                    threadVersion: attempt.threadVersion,
                    reasonCode: attempt.reason,
                    idempotencyKey: attempt.idempotencyKey,
                    bearer: bearer
                )
                try Self.verifyMutationReplay(result, close: attempt)
            }

            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration,
                  let workItem = result.workItem else { return false }
            clearAllMutationIdentities()
            routeCandidates = nil
            routingUnavailable = false
            routingErrorMessage = nil
            threadUnavailable = false
            thread = workItem
            actionMessage = Self.mutationSuccessMessage(action, replayed: result.replayed)
            await reconcile(workItemUUID: workItemUUID, bearer: bearer)
            return true
        } catch {
            guard !Task.isCancelled else { return false }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return false
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: workItemUUID)
                return false
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return false }
            await handleMutationFailure(
                error,
                action: action,
                workItemUUID: workItemUUID,
                bearer: bearer
            )
            return false
        }
    }

    /// Applies one explicitly confirmed ownership-routing action. The selected
    /// target must come from the current bounded candidate response, and the
    /// response versions must exactly match the visible work item.
    @discardableResult
    func route(
        _ action: PatientCommunicationRoutingAction,
        item: PatientCommunicationWorkItem,
        targetUUID: String?,
        reason: PatientCommunicationRoutingReasonOption,
        canRespond: Bool,
        bearer: String
    ) async -> Bool {
        guard !needsReauth,
              canRespond,
              item.isOpen,
              !isWorking,
              !bearer.isEmpty,
              pendingMutationRetryAction == nil,
              pendingRoutingRetryAction == nil,
              reason.action == action,
              action.allows(reasonCode: reason.code),
              let candidates = routeCandidates,
              candidates.matches(item),
              candidates.actions.allows(action),
              candidates.reasons(for: action).contains(where: { $0.code == reason.code }),
              candidates.containsTarget(targetUUID, for: action) else {
            return false
        }

        let requestGeneration = sensitiveContentPurgeGeneration
        actionMessage = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        isWorking = true
        defer { isWorking = false }

        let attempt: RoutingAttempt
        if let existing = routingAttempt,
           existing.matches(item, action: action, targetUUID: targetUUID, reasonCode: reason.code) {
            attempt = existing
        } else {
            attempt = RoutingAttempt(
                item: item,
                action: action,
                targetUUID: targetUUID,
                reasonCode: reason.code,
                idempotencyKey: makeUUID()
            )
            routingAttempt = attempt
        }

        do {
            let result = try await performRoutingAttempt(attempt, bearer: bearer)
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return false }
            let workItem = try Self.verifyFreshRoutingResult(result, for: attempt)
            clearAllMutationIdentities()
            routeCandidates = nil
            routingUnavailable = false
            routingErrorMessage = nil
            if action == .reroute {
                // A cross-pool reroute immediately ends the source actor's
                // accountable read. The verified mutation projection proves
                // the write committed, but must never become a destination
                // projection in this client. A separately authorized GET is
                // required before any destination state may be rendered.
                purgeAfterConfirmedReroute(
                    workItemUUID: attempt.workItemUUID,
                    replayed: result.replayed
                )
            } else {
                threadUnavailable = false
                thread = workItem
                routingConfirmationMessage = nil
                actionMessage = Self.routingSuccessMessage(action, replayed: result.replayed)
                await loadInbox(bearer: bearer)
            }
            return true
        } catch {
            guard !Task.isCancelled else { return false }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return false
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: item.workItemUuid)
                return false
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return false }
            await handleRoutingFailure(error, workItemUUID: item.workItemUuid, bearer: bearer)
            return false
        }
    }

    /// Replays only the exact in-memory UUID/version/target/reason/idempotency
    /// tuple whose prior outcome was ambiguous. It intentionally does not need
    /// a fresh detail or candidate read, because a committed transfer may have
    /// already revoked those reads. This is called only from an explicit,
    /// separately confirmed UI action and never automatically.
    @discardableResult
    func retryPendingRouting(canRespond: Bool, bearer: String) async -> Bool {
        guard !needsReauth,
              canRespond,
              !isWorking,
              !bearer.isEmpty,
              pendingMutationRetryAction == nil,
              let attempt = routingAttempt,
              pendingRoutingRetryAction == attempt.action else {
            return false
        }

        let requestGeneration = sensitiveContentPurgeGeneration
        actionMessage = nil
        isWorking = true
        defer { isWorking = false }

        do {
            let result = try await performRoutingAttempt(attempt, bearer: bearer)
            guard !Task.isCancelled,
                  requestGeneration == sensitiveContentPurgeGeneration else { return false }
            let verifiedWorkItem = try Self.verifyRoutingRetryResult(result, for: attempt)
            clearAllMutationIdentities()
            routeCandidates = nil
            routingUnavailable = false
            routingErrorMessage = nil
            if attempt.action == .reroute {
                // This covers both an exact replay receipt and an explicit retry
                // that performed the mutation for the first time. Neither path
                // may retain or display the destination projection.
                purgeAfterConfirmedReroute(
                    workItemUUID: attempt.workItemUUID,
                    replayed: result.replayed
                )
            } else if let verifiedWorkItem {
                threadUnavailable = false
                thread = verifiedWorkItem
                routingConfirmationMessage = nil
                actionMessage = Self.routingSuccessMessage(attempt.action, replayed: result.replayed)
                await loadInbox(bearer: bearer)
            } else {
                throw PatientCommunicationResponseVerificationError(
                    message: "The ownership replay could not be verified."
                )
            }
            return true
        } catch {
            guard !Task.isCancelled else { return false }
            if Self.isAuthenticationLoss(error) {
                purgeForReauthentication()
                return false
            }
            if Self.isKnownAuthorizationDenial(error) {
                purgeKnownAuthorizationDenial(workItemUUID: attempt.workItemUUID)
                return false
            }
            guard requestGeneration == sensitiveContentPurgeGeneration else { return false }
            await handleRoutingFailure(error, workItemUUID: attempt.workItemUUID, bearer: bearer)
            return false
        }
    }

    /// Clear every PHI-bearing object and every retry identity as soon as this
    /// surface leaves the foreground. A return to foreground performs fresh GETs.
    func suspend() {
        // Fence every request that began while the sensitive projection was
        // visible. A late success must not repopulate state after backgrounding.
        signalSensitiveContentPurge()
        inbox = []
        thread = nil
        routeCandidates = nil
        inboxErrorMessage = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        inboxUnavailable = false
        threadUnavailable = false
        routingUnavailable = false
        quarantinedRerouteWorkItemUUIDs.removeAll()
        clearAllMutationIdentities()
    }

    func clearThread() {
        thread = nil
        routeCandidates = nil
        threadUnavailable = false
        routingUnavailable = false
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        quarantinedRerouteWorkItemUUIDs.removeAll()
        clearAllMutationIdentities()
    }

    func discardReplyAttempt() {
        // The reply tuple becomes immutable before the repository call begins.
        // A draft notification that races the in-flight POST must never erase
        // the only exact identity available if the response is later lost.
        guard !isWorking, pendingMutationRetryAction != .reply else { return }
        replyAttempt = nil
    }

    /// Capability loss invalidates every command identity immediately while
    /// leaving the separately authorized read projection available.
    func revokeResponseCapability() {
        signalSensitiveContentPurge()
        routeCandidates = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        clearAllMutationIdentities()
    }

    func clearRoutingState() {
        routeCandidates = nil
        routingUnavailable = false
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        pendingRoutingRetryAction = nil
        routingAttempt = nil
    }

    /// A committed transfer can make the next detail GET return 404 after its
    /// mutation response was lost. Preserve only the already-pending exact
    /// replay tuple in that case; an ordinary unavailable read clears routing.
    func handleUnavailableThreadRefresh() {
        routeCandidates = nil
        routingErrorMessage = nil
        if pendingRoutingRetryAction != nil {
            routingUnavailable = true
        } else {
            clearRoutingState()
        }
    }

    private func reconcile(workItemUUID: String, bearer: String) async {
        let requestGeneration = sensitiveContentPurgeGeneration
        await loadThread(workItemUUID: workItemUUID, bearer: bearer)
        guard !needsReauth,
              requestGeneration == sensitiveContentPurgeGeneration else { return }
        await loadInbox(bearer: bearer)
    }

    private func handleMutationFailure(
        _ error: Error,
        action: PatientCommunicationMutationAction,
        workItemUUID: String,
        bearer: String
    ) async {
        if Self.isAuthenticationLoss(error) {
            purgeForReauthentication()
            return
        } else if Self.isKnownAuthorizationDenial(error) {
            purgeKnownAuthorizationDenial(workItemUUID: workItemUUID)
            return
        } else if let apiError = error as? APIError, apiError.statusCode == 409 {
            clearAllMutationIdentities()
            actionMessage = "This conversation changed since you opened it. It has been refreshed; review it before trying again."
        } else if let apiError = error as? APIError, apiError.statusCode == 422 {
            clearAllMutationIdentities()
            actionMessage = "The request could not be accepted. Review the message and try again."
        } else {
            // Network failures and every 5xx are ambiguous without a structured
            // feature-denial code. Preserve exactly one immutable attempt tuple;
            // refreshed versions never replace it and no write is sent here.
            preservePendingMutation(action)
            routeCandidates = nil
            routingErrorMessage = nil
            routingConfirmationMessage = nil
            actionMessage = "The \(action.label) outcome could not be confirmed. The conversation was refreshed. Review it before choosing whether to retry the exact request."
        }

        // A read-only reconciliation is safe. This is intentionally never a
        // recursive or automatic write retry, including after HTTP 409.
        let reconciliationGeneration = sensitiveContentPurgeGeneration
        await loadThread(workItemUUID: workItemUUID, bearer: bearer)
        guard !needsReauth,
              reconciliationGeneration == sensitiveContentPurgeGeneration else { return }

        // A closed, exactly-one-version-advanced detail projection is itself an
        // authoritative read confirmation that this actor's close committed.
        // Resolve it before the complete open-inbox projection omits the now
        // closed item; other mutation types still require exact replay because
        // a refreshed open projection cannot identify their event uniquely.
        if action == .close,
           pendingMutationRetryAction == .close,
           let attempt = closeAttempt,
           let refreshed = thread,
           Self.isVerifiedMutationProjection(
               refreshed,
               workItemUUID: attempt.workItemUUID,
               workItemVersion: attempt.workItemVersion,
               threadVersion: attempt.threadVersion
           ),
           !refreshed.isOpen,
           refreshed.status == "closed" {
            clearAllMutationIdentities()
            actionMessage = "Your earlier closure was confirmed by the refreshed conversation."
        }
        await loadInbox(bearer: bearer)
    }

    private func preservePendingMutation(_ action: PatientCommunicationMutationAction) {
        pendingRoutingRetryAction = nil
        routingAttempt = nil
        switch action {
        case .claim:
            guard claimAttempt != nil else {
                clearAllMutationIdentities()
                return
            }
            replyAttempt = nil
            closeAttempt = nil
        case .reply:
            guard replyAttempt != nil else {
                clearAllMutationIdentities()
                return
            }
            claimAttempt = nil
            closeAttempt = nil
        case .close:
            guard closeAttempt != nil else {
                clearAllMutationIdentities()
                return
            }
            claimAttempt = nil
            replyAttempt = nil
        }
        pendingMutationRetryAction = action
    }

    private func handleRoutingFailure(_ error: Error, workItemUUID: String, bearer: String) async {
        // Never leave a selector projection usable after any write attempt; its
        // versions may already have advanced even when the response was lost.
        routeCandidates = nil
        routingConfirmationMessage = nil
        if Self.isAuthenticationLoss(error) {
            purgeForReauthentication()
            return
        } else if Self.isKnownAuthorizationDenial(error) {
            purgeKnownAuthorizationDenial(workItemUUID: workItemUUID)
            return
        } else if let apiError = error as? APIError, apiError.statusCode == 409 {
            quarantinedRerouteWorkItemUUIDs.remove(workItemUUID)
            routingAttempt = nil
            pendingRoutingRetryAction = nil
            actionMessage = "This conversation changed since you opened the routing controls. It has been refreshed; review it before trying again."
        } else if let apiError = error as? APIError, apiError.statusCode == 422 {
            quarantinedRerouteWorkItemUUIDs.remove(workItemUUID)
            routingAttempt = nil
            pendingRoutingRetryAction = nil
            actionMessage = "The ownership change could not be accepted. Refresh the options and review your selection."
        } else {
            // Network failures and every 5xx are ambiguous without a structured
            // feature-denial code. Preserve this exact in-memory request
            // identity for a deliberate user retry, but never resend it.
            actionMessage = "The ownership change could not be confirmed. The conversation was refreshed. Review it before choosing whether to retry."
            pendingRoutingRetryAction = routingAttempt?.action
            if pendingRoutingRetryAction == .reroute {
                purgeForPossibleReroute(workItemUUID: workItemUUID)
                return
            }
        }

        let reconciliationGeneration = sensitiveContentPurgeGeneration
        await loadThread(workItemUUID: workItemUUID, bearer: bearer)
        guard !needsReauth,
              reconciliationGeneration == sensitiveContentPurgeGeneration else { return }
        await loadInbox(bearer: bearer)
        guard !needsReauth,
              reconciliationGeneration == sensitiveContentPurgeGeneration else { return }
        if let refreshed = thread, refreshed.isOpen, !routingUnavailable {
            await loadRouteCandidates(for: refreshed, canRespond: true, bearer: bearer)
        }
    }

    private func purgeForPossibleReroute(workItemUUID: String) {
        // A reroute may have committed before its response was lost or failed
        // local verification. Fence every older read and retain only the opaque
        // exact request tuple needed for an explicit replay.
        signalSensitiveContentPurge()
        quarantinedRerouteWorkItemUUIDs.insert(workItemUUID)
        inbox.removeAll { $0.workItemUuid == workItemUUID }
        thread = nil
        routeCandidates = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        threadUnavailable = true
        routingUnavailable = true
        pendingMutationRetryAction = nil
        claimAttempt = nil
        replyAttempt = nil
        closeAttempt = nil
        pendingRoutingRetryAction = .reroute
        actionMessage = "The reroute outcome could not be confirmed. Patient content was hidden; review before choosing whether to retry the exact request."
    }

    private func performRoutingAttempt(
        _ attempt: RoutingAttempt,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let result: PatientCommunicationMutationData
        switch attempt.action {
        case .release:
            result = try await repository.releasePatientCommunication(
                workItemUUID: attempt.workItemUUID,
                workItemVersion: attempt.workItemVersion,
                threadVersion: attempt.threadVersion,
                reasonCode: attempt.reasonCode,
                idempotencyKey: attempt.idempotencyKey,
                bearer: bearer
            )
        case .reassign:
            guard let targetUUID = attempt.targetUUID else {
                throw APIError(message: "A routing target is required.", statusCode: 422)
            }
            result = try await repository.reassignPatientCommunication(
                workItemUUID: attempt.workItemUUID,
                workItemVersion: attempt.workItemVersion,
                threadVersion: attempt.threadVersion,
                targetMembershipUUID: targetUUID,
                reasonCode: attempt.reasonCode,
                idempotencyKey: attempt.idempotencyKey,
                bearer: bearer
            )
        case .reroute:
            guard let targetUUID = attempt.targetUUID else {
                throw APIError(message: "A routing target is required.", statusCode: 422)
            }
            result = try await repository.reroutePatientCommunication(
                workItemUUID: attempt.workItemUUID,
                workItemVersion: attempt.workItemVersion,
                threadVersion: attempt.threadVersion,
                targetPoolUUID: targetUUID,
                reasonCode: attempt.reasonCode,
                idempotencyKey: attempt.idempotencyKey,
                bearer: bearer
            )
        }
        return result
    }

    private static func isCanonicalUUID(_ raw: String?) -> Bool {
        guard let raw else { return false }
        let pattern = "^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
        return raw.range(of: pattern, options: .regularExpression) != nil
            && UUID(uuidString: raw)?.uuidString.lowercased() == raw
    }

    private static func isVerifiedRoutingProjection(
        _ workItem: PatientCommunicationWorkItem,
        for attempt: RoutingAttempt,
        replayed: Bool
    ) -> Bool {
        guard attempt.workItemVersion < Int.max,
              attempt.threadVersion < Int.max else {
            return false
        }
        guard workItem.workItemUuid == attempt.workItemUUID else { return false }
        if replayed {
            return workItem.workItemVersion > attempt.workItemVersion
                && workItem.threadVersion > attempt.threadVersion
        }
        return workItem.workItemVersion == attempt.workItemVersion + 1
            && workItem.threadVersion == attempt.threadVersion + 1
    }

    private static func hasExpectedImmediateRoutingState(
        _ workItem: PatientCommunicationWorkItem,
        for attempt: RoutingAttempt
    ) -> Bool {
        guard workItem.isOpen, !workItem.assignedToMe else { return false }
        switch attempt.action {
        case .release: return workItem.ownershipState == "pool_owned"
        case .reassign: return workItem.ownershipState == "assigned"
        case .reroute:
            return workItem.ownershipState == "rerouted"
                && workItem.pool.poolUuid == attempt.targetUUID
        }
    }

    private static func verifyFreshRoutingResult(
        _ result: PatientCommunicationMutationData,
        for attempt: RoutingAttempt
    ) throws -> PatientCommunicationWorkItem {
        guard !result.replayed,
              let workItem = result.workItem,
              isVerifiedRoutingProjection(workItem, for: attempt, replayed: false),
              hasExpectedImmediateRoutingState(workItem, for: attempt),
              result.message == nil,
              isCanonicalUUID(result.eventUuid) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The ownership response could not be verified."
            )
        }
        return workItem
    }

    private static func verifyRoutingRetryResult(
        _ result: PatientCommunicationMutationData,
        for attempt: RoutingAttempt
    ) throws -> PatientCommunicationWorkItem? {
        guard result.message == nil, isCanonicalUUID(result.eventUuid) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The ownership replay could not be verified."
            )
        }

        if attempt.action == .reroute, result.replayed {
            guard result.workItem == nil else {
                throw PatientCommunicationResponseVerificationError(
                    message: "The ownership replay could not be verified."
                )
            }
            return nil
        }

        guard let workItem = result.workItem,
              isVerifiedRoutingProjection(
                  workItem,
                  for: attempt,
                  replayed: result.replayed
              ) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The ownership replay could not be verified."
            )
        }

        if result.replayed {
            // Generic release/reassign replay returns the current authorized
            // work item, which may have advanced through later mutations.
            guard attempt.action != .reroute else {
                throw PatientCommunicationResponseVerificationError(
                    message: "The ownership replay could not be verified."
                )
            }
        } else if !hasExpectedImmediateRoutingState(workItem, for: attempt) {
            throw PatientCommunicationResponseVerificationError(
                message: "The ownership replay could not be verified."
            )
        }
        return workItem
    }

    private static func isVerifiedMutationProjection(
        _ workItem: PatientCommunicationWorkItem,
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        replayed: Bool = false
    ) -> Bool {
        guard workItemVersion < Int.max, threadVersion < Int.max else { return false }
        guard workItem.workItemUuid == workItemUUID else { return false }
        if replayed {
            // Generic replay returns the actor's current authorized projection,
            // not an immutable historical snapshot. Intervening writes may have
            // advanced it beyond the original mutation's +1 versions.
            return workItem.workItemVersion > workItemVersion
                && workItem.threadVersion > threadVersion
        }
        return workItem.workItemVersion == workItemVersion + 1
            && workItem.threadVersion == threadVersion + 1
    }

    private static func verifyMutationReplay(
        _ result: PatientCommunicationMutationData,
        claim attempt: ClaimAttempt
    ) throws {
        guard let workItem = result.workItem,
              isVerifiedMutationProjection(
                  workItem,
                  workItemUUID: attempt.workItemUUID,
                  workItemVersion: attempt.workItemVersion,
                  threadVersion: attempt.threadVersion,
                  replayed: result.replayed
              ),
              result.message == nil,
              isCanonicalUUID(result.eventUuid) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The claim replay could not be verified."
            )
        }
        guard result.replayed || (workItem.isOpen && workItem.assignedToMe) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The claim replay could not be verified."
            )
        }
    }

    private static func verifyMutationReplay(
        _ result: PatientCommunicationMutationData,
        reply attempt: ReplyAttempt
    ) throws {
        guard let workItem = result.workItem,
              isVerifiedMutationProjection(
                  workItem,
                  workItemUUID: attempt.workItemUUID,
                  workItemVersion: attempt.workItemVersion,
                  threadVersion: attempt.threadVersion,
                  replayed: result.replayed
              ),
              let message = result.message,
              message.isPatientVisible,
              message.messageKind == "message",
              message.body == attempt.body,
              isCanonicalUUID(message.messageUuid),
              isCanonicalUUID(result.eventUuid) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The reply replay could not be verified."
            )
        }
        guard result.replayed || (workItem.isOpen && workItem.assignedToMe) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The reply replay could not be verified."
            )
        }
    }

    private static func verifyMutationReplay(
        _ result: PatientCommunicationMutationData,
        close attempt: CloseAttempt
    ) throws {
        guard let workItem = result.workItem,
              isVerifiedMutationProjection(
                  workItem,
                  workItemUUID: attempt.workItemUUID,
                  workItemVersion: attempt.workItemVersion,
                  threadVersion: attempt.threadVersion,
                  replayed: result.replayed
              ),
              result.message == nil,
              isCanonicalUUID(result.eventUuid) else {
            throw PatientCommunicationResponseVerificationError(
                message: "The closure replay could not be verified."
            )
        }
        guard result.replayed || (!workItem.isOpen && workItem.status == "closed") else {
            throw PatientCommunicationResponseVerificationError(
                message: "The closure replay could not be verified."
            )
        }
    }

    private func purgeAfterConfirmedReroute(workItemUUID: String, replayed: Bool) {
        // Invalidate any detail/candidate/inbox read that began before the
        // cross-pool transfer was confirmed. Its late projection is stale and
        // no longer authorized for the source accountable queue.
        signalSensitiveContentPurge()
        quarantinedRerouteWorkItemUUIDs.insert(workItemUUID)
        thread = nil
        threadUnavailable = true
        routeCandidates = nil
        inbox.removeAll { $0.workItemUuid == workItemUUID }
        actionMessage = nil
        clearAllMutationIdentities()
        routingConfirmationMessage = replayed
            ? "Your earlier reroute was confirmed. The conversation is now with the destination care team."
            : "The reroute was confirmed. The conversation is now with the destination care team."
    }

    private func signalSensitiveContentPurge() {
        sensitiveContentPurgeGeneration &+= 1
    }

    private var activeRestrictedWorkItemUUID: String? {
        if let thread, thread.isOpen {
            return thread.workItemUuid
        }
        return routingAttempt?.workItemUUID
            ?? claimAttempt?.workItemUUID
            ?? replyAttempt?.workItemUUID
            ?? closeAttempt?.workItemUUID
    }

    private func clearAllMutationIdentities() {
        pendingMutationRetryAction = nil
        pendingRoutingRetryAction = nil
        claimAttempt = nil
        replyAttempt = nil
        closeAttempt = nil
        routingAttempt = nil
    }

    private func purgeForReauthentication() {
        // Publish the purge signal before replacing the projection so SwiftUI
        // clears local draft/focus/dialog state in the same update transaction.
        signalSensitiveContentPurge()
        inbox = []
        thread = nil
        routeCandidates = nil
        inboxErrorMessage = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        inboxUnavailable = false
        threadUnavailable = false
        routingUnavailable = false
        clearAllMutationIdentities()
        quarantinedRerouteWorkItemUUIDs.removeAll()
        needsReauth = true
    }

    private func purgeForInboxAuthorizationDenial() {
        // An inbox-level 403/404 is authoritative for the entire restricted
        // communications surface. Polling remains active while a detail is
        // open, so purge that detail and every command identity as well.
        signalSensitiveContentPurge()
        inbox = []
        thread = nil
        routeCandidates = nil
        inboxErrorMessage = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        inboxUnavailable = true
        threadUnavailable = true
        routingUnavailable = true
        clearAllMutationIdentities()
        quarantinedRerouteWorkItemUUIDs.removeAll()
    }

    private func purgeAfterInboxOmission(workItemUUID: String) {
        // The accountable inbox is a complete authorization projection. If a
        // still-open detail (or its ambiguous command) disappears from a 200
        // response, retain other inbox rows but revoke this stale projection.
        // The sole exception is an already-ambiguous exact reroute: omission
        // can mean that write committed, and the source endpoint deliberately
        // permits its same-key minimized receipt replay.
        let preserveExactRerouteReplay = routingAttempt?.action == .reroute
            && routingAttempt?.workItemUUID == workItemUUID
        signalSensitiveContentPurge()
        inbox.removeAll { $0.workItemUuid == workItemUUID }
        thread = nil
        routeCandidates = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        threadUnavailable = true
        routingUnavailable = true
        pendingMutationRetryAction = nil
        claimAttempt = nil
        replyAttempt = nil
        closeAttempt = nil
        if preserveExactRerouteReplay {
            quarantinedRerouteWorkItemUUIDs.insert(workItemUUID)
            pendingRoutingRetryAction = .reroute
        } else {
            routingAttempt = nil
            pendingRoutingRetryAction = nil
        }
    }

    private func purgeForRetainedInboxDrift(workItemUUID: String) {
        // A dual-pool responder can remain authorized to the same opaque work
        // item after an automated transfer or shift handoff. The inbox row is
        // therefore retained, but its advanced routing projection invalidates
        // decrypted detail, draft, confirmation, and route-candidate state.
        // An already-ambiguous exact write tuple remains immutable in memory;
        // only an explicit user replay may resolve it.
        signalSensitiveContentPurge()
        thread = nil
        routeCandidates = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        threadUnavailable = false
        routingUnavailable = false
        quarantinedRerouteWorkItemUUIDs.remove(workItemUUID)
    }

    private static func restrictedProjectionDrifted(
        from current: PatientCommunicationWorkItem,
        to visible: PatientCommunicationWorkItem
    ) -> Bool {
        current.workItemVersion != visible.workItemVersion
            || current.threadVersion != visible.threadVersion
            || current.threadUuid != visible.threadUuid
            || current.pool.poolUuid != visible.pool.poolUuid
            || current.pool.label != visible.pool.label
            || current.unit?.id != visible.unit?.id
            || current.status != visible.status
            || current.ownershipState != visible.ownershipState
            || current.assignedToMe != visible.assignedToMe
            || current.dueAt != visible.dueAt
            || current.escalateAt != visible.escalateAt
    }

    private func purgeKnownAuthorizationDenial(workItemUUID: String) {
        // A 403/404 is authoritative, not an ambiguous transport outcome. An
        // old projection or idempotency tuple must not outlive revoked access.
        signalSensitiveContentPurge()
        inbox.removeAll { $0.workItemUuid == workItemUUID }
        thread = nil
        routeCandidates = nil
        routingErrorMessage = nil
        routingConfirmationMessage = nil
        actionMessage = nil
        threadUnavailable = true
        routingUnavailable = true
        clearAllMutationIdentities()
        quarantinedRerouteWorkItemUUIDs.remove(workItemUUID)
    }

    private static func isAuthenticationLoss(_ error: Error) -> Bool {
        guard let apiError = error as? APIError else { return false }
        return apiError.statusCode == 401
    }

    private static func isKnownAuthorizationDenial(_ error: Error) -> Bool {
        guard let apiError = error as? APIError else { return false }
        return [403, 404].contains(apiError.statusCode)
    }

    private static func isServiceUnavailable(_ error: Error) -> Bool {
        guard let apiError = error as? APIError else { return false }
        return apiError.statusCode == 503
    }

    private static func safeLoadMessage(_ error: Error) -> String {
        if let apiError = error as? APIError, apiError.statusCode == 401 {
            return "Your session expired. Sign in again to view patient communications."
        }
        return "Patient communications could not be loaded over the secure connection. Try again when you are online."
    }

    private static func safeRoutingLoadMessage(_ error: Error) -> String {
        if let apiError = error as? APIError, apiError.statusCode == 401 {
            return "Your session expired. Sign in again to view ownership options."
        }
        return "Ownership options could not be loaded over the secure connection. Refresh when you are online."
    }

    private static func routingSuccessMessage(
        _ action: PatientCommunicationRoutingAction,
        replayed: Bool
    ) -> String {
        if replayed {
            switch action {
            case .release: return "Your earlier release was confirmed."
            case .reassign: return "Your earlier reassignment was confirmed."
            case .reroute: return "Your earlier reroute was confirmed."
            }
        }
        switch action {
        case .release: return "Conversation released to the team."
        case .reassign: return "Conversation reassigned."
        case .reroute: return "Conversation rerouted."
        }
    }

    private static func mutationSuccessMessage(
        _ action: PatientCommunicationMutationAction,
        replayed: Bool
    ) -> String {
        switch action {
        case .claim:
            return replayed ? "Your earlier claim was confirmed." : "You now own this conversation."
        case .reply:
            return replayed ? "Your earlier reply was confirmed." : "Reply delivered to the patient."
        case .close:
            return replayed ? "Your earlier closure was confirmed." : "Conversation closed."
        }
    }
}

private struct ClaimAttempt {
    let workItemUUID: String
    let workItemVersion: Int
    let threadVersion: Int
    let idempotencyKey: UUID

    init(item: PatientCommunicationWorkItem, idempotencyKey: UUID) {
        workItemUUID = item.workItemUuid
        workItemVersion = item.workItemVersion
        threadVersion = item.threadVersion
        self.idempotencyKey = idempotencyKey
    }

    func matches(_ item: PatientCommunicationWorkItem) -> Bool {
        workItemUUID == item.workItemUuid
            && workItemVersion == item.workItemVersion
            && threadVersion == item.threadVersion
    }
}

private struct ReplyAttempt {
    let workItemUUID: String
    let workItemVersion: Int
    let threadVersion: Int
    let body: String
    let clientMessageUUID: UUID
    let idempotencyKey: UUID

    init(
        item: PatientCommunicationWorkItem,
        body: String,
        clientMessageUUID: UUID,
        idempotencyKey: UUID
    ) {
        workItemUUID = item.workItemUuid
        workItemVersion = item.workItemVersion
        threadVersion = item.threadVersion
        self.body = body
        self.clientMessageUUID = clientMessageUUID
        self.idempotencyKey = idempotencyKey
    }

    func matches(_ item: PatientCommunicationWorkItem, body: String) -> Bool {
        workItemUUID == item.workItemUuid
            && workItemVersion == item.workItemVersion
            && threadVersion == item.threadVersion
            && self.body == body
    }
}

private struct CloseAttempt {
    let workItemUUID: String
    let workItemVersion: Int
    let threadVersion: Int
    let reason: PatientCommunicationCloseReason
    let idempotencyKey: UUID

    init(
        item: PatientCommunicationWorkItem,
        reason: PatientCommunicationCloseReason,
        idempotencyKey: UUID
    ) {
        workItemUUID = item.workItemUuid
        workItemVersion = item.workItemVersion
        threadVersion = item.threadVersion
        self.reason = reason
        self.idempotencyKey = idempotencyKey
    }

    func matches(_ item: PatientCommunicationWorkItem, reason: PatientCommunicationCloseReason) -> Bool {
        workItemUUID == item.workItemUuid
            && workItemVersion == item.workItemVersion
            && threadVersion == item.threadVersion
            && self.reason == reason
    }
}

private struct RoutingAttempt {
    let workItemUUID: String
    let workItemVersion: Int
    let threadVersion: Int
    let action: PatientCommunicationRoutingAction
    let targetUUID: String?
    let reasonCode: String
    let idempotencyKey: UUID

    init(
        item: PatientCommunicationWorkItem,
        action: PatientCommunicationRoutingAction,
        targetUUID: String?,
        reasonCode: String,
        idempotencyKey: UUID
    ) {
        workItemUUID = item.workItemUuid
        workItemVersion = item.workItemVersion
        threadVersion = item.threadVersion
        self.action = action
        self.targetUUID = targetUUID
        self.reasonCode = reasonCode
        self.idempotencyKey = idempotencyKey
    }

    func matches(
        _ item: PatientCommunicationWorkItem,
        action: PatientCommunicationRoutingAction,
        targetUUID: String?,
        reasonCode: String
    ) -> Bool {
        workItemUUID == item.workItemUuid
            && workItemVersion == item.workItemVersion
            && threadVersion == item.threadVersion
            && self.action == action
            && self.targetUUID == targetUUID
            && self.reasonCode == reasonCode
    }

    func matches(_ candidates: PatientCommunicationRouteCandidatesData) -> Bool {
        workItemUUID == candidates.workItemUuid
            && workItemVersion == candidates.workItemVersion
            && threadVersion == candidates.threadVersion
            && candidates.actions.allows(action)
            && candidates.reasons(for: action).contains { $0.code == reasonCode }
            && candidates.containsTarget(targetUUID, for: action)
    }
}
