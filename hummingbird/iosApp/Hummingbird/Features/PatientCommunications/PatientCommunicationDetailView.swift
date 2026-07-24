import SwiftUI

struct PatientCommunicationDetailView: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.scenePhase) private var scenePhase
    @ObservedObject var viewModel: PatientCommunicationsViewModel

    let workItemUUID: String
    let canRespond: Bool

    @State private var draft = ""
    @State private var showCloseReasons = false
    @State private var routingAction: PatientCommunicationRoutingAction?
    @State private var showMutationRetryConfirmation = false
    @State private var showRoutingRetryConfirmation = false
    @State private var mutationTask: Task<Void, Never>?
    @FocusState private var composerFocused: Bool

    init(
        viewModel: PatientCommunicationsViewModel,
        workItemUUID: String,
        canRespond: Bool
    ) {
        self.viewModel = viewModel
        self.workItemUUID = workItemUUID
        self.canRespond = canRespond

        #if DEBUG
        // Authorization-loss UI tests must begin with a draft already present.
        // Injecting text through XCUITest can outlive the deliberately short
        // polling interval on a loaded runner, allowing the secure purge to
        // remove the editor while the automation framework is still typing.
        // This hook is compiled only into Debug and remains gated by the
        // explicit in-memory UI-test launch mode.
        if StaffCommunicationsUITestMode.isEnabled {
            _draft = State(
                initialValue: ProcessInfo.processInfo.environment[
                    "HB_STAFF_COMM_UI_SEEDED_DRAFT"
                ] ?? ""
            )
        }
        #endif
    }

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: Z.s3) {
                urgentGuidance

                if let retryAction = viewModel.pendingMutationRetryAction {
                    exactMutationRetryCard(retryAction)
                }

                if let retryAction = viewModel.pendingRoutingRetryAction {
                    exactRoutingRetryCard(retryAction)
                }

                if let confirmation = viewModel.routingConfirmationMessage {
                    minimizedRerouteConfirmation(confirmation)
                }

                if let item = viewModel.thread {
                    ownershipCard(item)

                    if item.hasEarlierMessages == true {
                        PatientCommunicationCard {
                            Label(
                                "Earlier messages are available in the canonical record. This mobile view shows the newest 250.",
                                systemImage: "clock.arrow.circlepath"
                            )
                            .font(.subheadline)
                            .foregroundStyle(Z.inkMuted)
                        }
                    }

                    messages(item)

                    if item.isOpen {
                        actionArea(item)
                        routingArea(item)
                    } else {
                        closedState(item)
                    }
                } else if viewModel.threadUnavailable {
                    unavailableState
                } else if viewModel.isLoadingThread {
                    SkeletonRows(count: 3)
                } else {
                    RetryableMessage(
                        symbol: "lock.shield",
                        title: "Can't load conversation",
                        message: "This patient conversation could not be loaded over the secure connection.",
                        tone: .warning
                    ) {
                        Task { await loadThread() }
                    }
                }
            }
            .padding(Z.s4)
        }
        .accessibilityIdentifier("patientCommunications.thread")
        .background { HummingbirdBackdrop(dim: 0.57) }
        .navigationTitle("Patient conversation")
        .navigationBarTitleDisplayMode(.inline)
        .patientCommunicationPrivacySensitive()
        .overlay {
            if scenePhase != .active {
                PatientCommunicationPrivacyCover()
            }
        }
        .task {
            await loadThread()
            #if DEBUG
            await runAuthorizationRefreshUITestIfNeeded()
            #endif
        }
        .refreshable { await loadThread() }
        .onDisappear {
            clearSensitiveUIState()
            viewModel.clearThread()
        }
        .onChange(of: draft) { _, _ in
            viewModel.discardReplyAttempt()
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active {
                Task { await loadThread() }
            } else {
                clearSensitiveUIState()
                viewModel.suspend()
            }
        }
        .onChange(of: viewModel.sensitiveContentPurgeGeneration) { _, _ in
            clearSensitiveUIState()
        }
        .onChange(of: canRespond) { _, allowed in
            guard !allowed else {
                if let item = viewModel.thread {
                    Task { await viewModel.loadRouteCandidates(
                        for: item,
                        canRespond: true,
                        bearer: auth.accessToken ?? ""
                    ) }
                }
                return
            }
            clearSensitiveUIState()
            viewModel.revokeResponseCapability()
            routingAction = nil
        }
        .onChange(of: viewModel.routingConfirmationMessage) { _, confirmation in
            guard confirmation != nil else { return }
            draft = ""
            composerFocused = false
            routingAction = nil
            showCloseReasons = false
        }
        .confirmationDialog(
            "Close this conversation?",
            isPresented: $showCloseReasons,
            titleVisibility: .visible
        ) {
            ForEach(PatientCommunicationCloseReason.allCases) { reason in
                Button(reason.label) { beginClose(reason) }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("The reason is kept in the staff record. The patient sees only that their question was answered.")
        }
        .confirmationDialog(
            "Retry the exact unconfirmed request?",
            isPresented: $showMutationRetryConfirmation,
            titleVisibility: .visible
        ) {
            Button("Retry exact request") { beginExactMutationRetry() }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text(exactMutationRetryConfirmationMessage)
        }
        .confirmationDialog(
            "Retry the exact ownership request?",
            isPresented: $showRoutingRetryConfirmation,
            titleVisibility: .visible
        ) {
            Button("Retry exact request") { beginExactRoutingRetry() }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This resends only the same UUID, versions, target, reason, and replay key. No new ownership request will be created.")
        }
        .sheet(item: $routingAction) { action in
            if let candidates = viewModel.routeCandidates,
               candidates.actions.allows(action),
               let item = viewModel.thread,
               candidates.matches(item) {
                PatientCommunicationRoutingSheet(
                    action: action,
                    candidates: candidates,
                    isWorking: viewModel.isWorking
                ) { targetUUID, reason in
                    beginRouting(
                        action,
                        item: item,
                        targetUUID: targetUUID,
                        reason: reason
                    )
                }
            } else {
                PatientCommunicationRoutingUnavailableSheet()
            }
        }
    }

    private var urgentGuidance: some View {
        PatientCommunicationCard {
            HStack(alignment: .top, spacing: Z.s3) {
                Image(systemName: "exclamationmark.shield.fill")
                    .font(.title2)
                    .foregroundStyle(Z.status(.warning))
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: Z.s1) {
                    Text("Keep urgent care on clinical channels")
                        .font(.headline)
                        .foregroundStyle(Z.ink)
                    Text("For urgent or life-threatening needs, use your hospital's immediate escalation and emergency procedures. Do not rely on this thread.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }

    private func ownershipCard(_ item: PatientCommunicationWorkItem) -> some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack(alignment: .top, spacing: Z.s2) {
                    VStack(alignment: .leading, spacing: Z.s1) {
                        Text(item.topic.label)
                            .font(.title3.weight(.semibold))
                            .foregroundStyle(Z.ink)
                            .fixedSize(horizontal: false, vertical: true)
                        Text([item.unit?.label, item.pool.label].compactMap { $0 }.joined(separator: " · "))
                            .font(.subheadline)
                            .foregroundStyle(Z.inkMuted)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: Z.s2)
                    PatientCommunicationStateLabel(
                        symbol: ownershipSymbol(item),
                        label: ownershipLabel(item),
                        tone: ownershipTone(item)
                    )
                }

                Divider().overlay(Z.border)

                ViewThatFits(in: .horizontal) {
                    HStack(spacing: Z.s3) { deadlineSummary(item) }
                    VStack(alignment: .leading, spacing: Z.s2) { deadlineSummary(item) }
                }

                if item.patientContextRef != nil {
                    Label("Authorized patient context is available in Zephyrus", systemImage: "person.text.rectangle")
                        .font(.caption)
                        .foregroundStyle(Z.inkMuted)
                }
            }
        }
    }

    @ViewBuilder private func deadlineSummary(_ item: PatientCommunicationWorkItem) -> some View {
        Label(
            item.isResponseDue ? "Response due" : "Response target \(PatientCommunicationDates.relative(item.dueAt))",
            systemImage: item.isResponseDue ? "clock.badge.exclamationmark.fill" : "clock.fill"
        )
        .font(.caption.weight(.semibold))
        .foregroundStyle(Z.status(item.isResponseDue ? .warning : .info))

        Label(
            item.isEscalationDue ? "Escalation due" : "Escalates \(PatientCommunicationDates.relative(item.escalateAt))",
            systemImage: item.isEscalationDue ? "exclamationmark.triangle.fill" : "arrow.up.right.circle"
        )
        .font(.caption.weight(.semibold))
        .foregroundStyle(Z.status(item.isEscalationDue ? .critical : .info))
    }

    private func messages(_ item: PatientCommunicationWorkItem) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            Text("Conversation")
                .font(.title3.weight(.semibold))
                .foregroundStyle(Z.ink)
                .padding(.top, Z.s1)

            if let messages = item.messages, !messages.isEmpty {
                ForEach(messages) { message in
                    PatientCommunicationMessageBubble(message: message)
                }
            } else {
                PatientCommunicationCard {
                    Text("No message content is available.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                }
            }
        }
    }

    @ViewBuilder private func actionArea(_ item: PatientCommunicationWorkItem) -> some View {
        if !canRespond {
            PatientCommunicationCard {
                Label(
                    "Responding is not available for your current responsibilities.",
                    systemImage: "eye.fill"
                )
                .font(.subheadline)
                .foregroundStyle(Z.inkMuted)
            }
        } else if item.canClaim {
            PatientCommunicationCard {
                VStack(alignment: .leading, spacing: Z.s3) {
                    Text("Claim before responding")
                        .font(.headline)
                        .foregroundStyle(Z.ink)
                    Text("Claiming records you as the accountable owner. Other eligible team members will see that ownership changed.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                        .fixedSize(horizontal: false, vertical: true)
                    Button {
                        beginClaim(item)
                    } label: {
                        actionButtonLabel("Claim conversation", symbol: "person.crop.circle.badge.checkmark")
                    }
                    .buttonStyle(.plain)
                    .disabled(viewModel.isWorking || hasPendingExactRetry)
                    .accessibilityIdentifier("patientCommunications.claimButton")
                }
            }
        } else if item.canReply {
            composer(item)
        } else {
            PatientCommunicationCard {
                Label(
                    "Another eligible care-team member owns this conversation.",
                    systemImage: "person.crop.circle.badge.checkmark"
                )
                .font(.subheadline)
                .foregroundStyle(Z.inkMuted)
            }
        }

        if let actionMessage = viewModel.actionMessage {
            PatientCommunicationCard {
                Label(actionMessage, systemImage: actionMessageSymbol)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(actionMessageColor)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .accessibilityIdentifier("patientCommunications.actionMessage")
        }
    }

    @ViewBuilder private func routingArea(_ item: PatientCommunicationWorkItem) -> some View {
        if canRespond && !hasPendingExactRetry {
            if viewModel.isLoadingRouting {
                PatientCommunicationCard {
                    HStack(spacing: Z.s2) {
                        ProgressView()
                        Text("Loading ownership options…")
                            .font(.subheadline)
                            .foregroundStyle(Z.inkMuted)
                    }
                }
                .accessibilityIdentifier("patientCommunications.routing.loading")
            } else if let candidates = viewModel.routeCandidates, candidates.matches(item) {
                PatientCommunicationRoutingCard(
                    candidates: candidates,
                    isWorking: viewModel.isWorking,
                    onSelect: { routingAction = $0 }
                )
            } else if viewModel.routingUnavailable {
                PatientCommunicationCard {
                    Label(
                        "Ownership controls are unavailable for this conversation or your current assignment.",
                        systemImage: "lock.fill"
                    )
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                }
                .accessibilityIdentifier("patientCommunications.routing.unavailable")
            } else if let routingError = viewModel.routingErrorMessage {
                RetryableMessage(
                    symbol: "arrow.triangle.branch",
                    title: "Can't load ownership options",
                    message: routingError,
                    tone: .warning
                ) {
                    Task {
                        await viewModel.loadRouteCandidates(
                            for: item,
                            canRespond: canRespond,
                            bearer: auth.accessToken ?? ""
                        )
                    }
                }
                .accessibilityIdentifier("patientCommunications.routing.error")
            }
        }
    }

    private func exactMutationRetryCard(_ action: PatientCommunicationMutationAction) -> some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s3) {
                Label("\(action.label.capitalized) outcome unconfirmed", systemImage: "questionmark.diamond.fill")
                    .font(.headline)
                    .foregroundStyle(Z.status(.warning))
                Text(exactMutationRetryExplanation(action))
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                    .fixedSize(horizontal: false, vertical: true)
                Button {
                    showMutationRetryConfirmation = true
                } label: {
                    actionButtonLabel("Retry exact request", symbol: "arrow.clockwise.circle.fill")
                }
                .buttonStyle(.plain)
                .disabled(viewModel.isWorking || !canRespond)
                .accessibilityLabel("Retry exact \(action.label) request")
                .accessibilityHint("Requires confirmation and reuses the prior request without changes")
                .accessibilityIdentifier("patientCommunications.mutation.retryExactButton")
            }
        }
    }

    private func exactRoutingRetryCard(_ action: PatientCommunicationRoutingAction) -> some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s3) {
                Label("Ownership outcome unconfirmed", systemImage: "questionmark.diamond.fill")
                    .font(.headline)
                    .foregroundStyle(Z.status(.warning))
                Text("The secure response was lost, so Hummingbird did not resend the \(action.rawValue) request. You may explicitly retry the identical request even if the conversation has already moved out of this queue.")
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                    .fixedSize(horizontal: false, vertical: true)
                Button {
                    showRoutingRetryConfirmation = true
                } label: {
                    actionButtonLabel("Retry exact request", symbol: "arrow.clockwise.circle.fill")
                }
                .buttonStyle(.plain)
                .disabled(viewModel.isWorking || !canRespond)
                .accessibilityLabel("Retry exact \(action.rawValue) request")
                .accessibilityHint("Requires confirmation and reuses the prior request without changes")
                .accessibilityIdentifier("patientCommunications.routing.retryExactButton")
            }
        }
    }

    private func minimizedRerouteConfirmation(_ message: String) -> some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s2) {
                Label("Reroute confirmed", systemImage: "checkmark.shield.fill")
                    .font(.headline)
                    .foregroundStyle(Z.status(.success))
                Text(message)
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                    .fixedSize(horizontal: false, vertical: true)
                Text("Destination details are intentionally hidden because this conversation is no longer in your accountable queue.")
                    .font(.caption)
                    .foregroundStyle(Z.inkMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .accessibilityIdentifier("patientCommunications.routing.minimizedReplayConfirmation")
    }

    private func composer(_ item: PatientCommunicationWorkItem) -> some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s1) {
                    Label("Patient-visible reply", systemImage: "eye.fill")
                        .font(.headline)
                        .foregroundStyle(Z.ink)
                    Text("Everything sent from this composer is visible to the patient and becomes part of the communication record. Keep internal routing notes out of this reply.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }

                ZStack(alignment: .topLeading) {
                    if draft.isEmpty {
                        Text("Write a clear, patient-friendly response…")
                            .font(.body)
                            .foregroundStyle(Z.inkMuted)
                            .padding(.horizontal, 5)
                            .padding(.vertical, 9)
                            .allowsHitTesting(false)
                    }
                    TextEditor(text: $draft)
                        .font(.body)
                        .foregroundStyle(Z.ink)
                        .scrollContentBackground(.hidden)
                        .frame(minHeight: 132)
                        .padding(Z.s1)
                        .background(
                            RoundedRectangle(cornerRadius: 10, style: .continuous)
                                .fill(Z.bg.opacity(0.84))
                        )
                        .overlay(
                            RoundedRectangle(cornerRadius: 10, style: .continuous)
                                .strokeBorder(Z.border, lineWidth: 1)
                        )
                        .focused($composerFocused)
                        .autocorrectionDisabled(true)
                        .textInputAutocapitalization(.sentences)
                        .textContentType(.none)
                        .accessibilityLabel("Patient-visible reply")
                        .accessibilityHint("Draft is held only while this screen is open")
                        .accessibilityIdentifier("patientCommunications.replyEditor")
                        .disabled(viewModel.isWorking || hasPendingExactRetry)
                }

                HStack(alignment: .firstTextBaseline) {
                    Label("Not saved on this device", systemImage: "lock.fill")
                        .font(.caption)
                        .foregroundStyle(Z.inkMuted)
                    Spacer()
                    Text("\(draft.count) / 4,000")
                        .font(.caption.monospacedDigit())
                        .foregroundStyle(draft.count > 4_000 ? Z.status(.critical) : Z.inkMuted)
                        .accessibilityLabel("\(draft.count) of 4,000 characters")
                }

                Button {
                    beginReply(item)
                } label: {
                    actionButtonLabel("Send reply", symbol: "paperplane.fill")
                }
                .buttonStyle(.plain)
                .disabled(!canSend || viewModel.isWorking || hasPendingExactRetry)
                .accessibilityIdentifier("patientCommunications.sendButton")

                Divider().overlay(Z.border)

                if item.canClose {
                    VStack(alignment: .leading, spacing: Z.s2) {
                        Text("Closing tells the patient their question was answered. The selected staff reason remains internal.")
                            .font(.caption)
                            .foregroundStyle(Z.inkMuted)
                            .fixedSize(horizontal: false, vertical: true)
                        Button {
                            showCloseReasons = true
                        } label: {
                            Label("Close conversation", systemImage: "checkmark.message.fill")
                                .font(.body.weight(.semibold))
                                .foregroundStyle(Z.status(.success))
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, Z.s3)
                                .background(
                                    RoundedRectangle(cornerRadius: 10, style: .continuous)
                                        .strokeBorder(Z.status(.success).opacity(0.65), lineWidth: 1)
                                )
                        }
                        .buttonStyle(.plain)
                        .disabled(viewModel.isWorking || hasPendingExactRetry)
                        .accessibilityIdentifier("patientCommunications.closeButton")
                    }
                } else {
                    Label("Send a patient-visible response before closing.", systemImage: "info.circle.fill")
                        .font(.caption)
                        .foregroundStyle(Z.inkMuted)
                }
            }
        }
    }

    private func closedState(_ item: PatientCommunicationWorkItem) -> some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s2) {
                Label("Conversation closed", systemImage: "checkmark.message.fill")
                    .font(.headline)
                    .foregroundStyle(Z.status(.success))
                if let closedAt = item.closedAt {
                    Text("Closed \(PatientCommunicationDates.absolute(closedAt)). The patient sees that their question was answered.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                }
            }
        }
        .accessibilityIdentifier("patientCommunications.closed")
    }

    private var unavailableState: some View {
        RetryableMessage(
            symbol: "person.crop.circle.badge.questionmark",
            title: "Conversation unavailable",
            message: "This conversation is not available for your current assignment. It may have been completed or reassigned.",
            tone: .info
        )
        .accessibilityIdentifier("patientCommunications.threadUnavailable")
    }

    private var canSend: Bool {
        let body = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        return !body.isEmpty && body.count <= 4_000
    }

    private var hasPendingExactRetry: Bool {
        viewModel.pendingMutationRetryAction != nil || viewModel.pendingRoutingRetryAction != nil
    }

    private var exactMutationRetryConfirmationMessage: String {
        guard let action = viewModel.pendingMutationRetryAction else {
            return "The pending request is no longer available."
        }
        switch action {
        case .reply:
            return "This resends only the same patient-visible reply, client message UUID, versions, and replay key. No new reply will be created."
        case .claim, .close:
            return "This resends only the same request, versions, and replay key. No new \(action.label) request will be created."
        }
    }

    private func exactMutationRetryExplanation(_ action: PatientCommunicationMutationAction) -> String {
        switch action {
        case .reply:
            return "The secure response was lost, so Hummingbird did not resend the patient-visible reply. You may explicitly retry only the identical in-memory reply."
        case .claim, .close:
            return "The secure response was lost, so Hummingbird did not resend the \(action.label) request. You may explicitly retry only the identical in-memory request."
        }
    }

    private var actionMessageSymbol: String {
        guard let message = viewModel.actionMessage else { return "info.circle.fill" }
        if message.contains("delivered")
            || message.contains("confirmed")
            || message.contains("own")
            || message.contains("closed")
            || message.contains("released")
            || message.contains("reassigned")
            || message.contains("rerouted") {
            return "checkmark.circle.fill"
        }
        return "exclamationmark.circle.fill"
    }

    private var actionMessageColor: Color {
        actionMessageSymbol == "checkmark.circle.fill" ? Z.status(.success) : Z.status(.warning)
    }

    private func ownershipLabel(_ item: PatientCommunicationWorkItem) -> String {
        if !item.isOpen { return "Closed" }
        if item.assignedToMe {
            return item.ownershipState == "responded" ? "Response sent by you" : "Owned by you"
        }
        switch item.ownershipState {
        case "pool_owned", "rerouted", "escalated": return "Team queue"
        default: return "Team member assigned"
        }
    }

    private func ownershipSymbol(_ item: PatientCommunicationWorkItem) -> String {
        if !item.isOpen { return "checkmark.message.fill" }
        return item.assignedToMe ? "person.crop.circle.badge.checkmark" : "person.2.fill"
    }

    private func ownershipTone(_ item: PatientCommunicationWorkItem) -> CapacityStatus {
        if !item.isOpen || item.assignedToMe { return .success }
        if item.isEscalationDue { return .critical }
        return .info
    }

    private func actionButtonLabel(_ title: String, symbol: String) -> some View {
        HStack(spacing: Z.s2) {
            if viewModel.isWorking {
                ProgressView().tint(.white)
            } else {
                Image(systemName: symbol)
            }
            Text(viewModel.isWorking ? "Working…" : title)
        }
        .font(.body.weight(.semibold))
        .foregroundStyle(.white)
        .frame(maxWidth: .infinity)
        .padding(.vertical, Z.s3)
        .background(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .fill(viewModel.isWorking ? Z.primary.opacity(0.55) : Z.primary)
        )
    }

    private func beginClaim(_ item: PatientCommunicationWorkItem) {
        guard canRespond else { return }
        mutationTask?.cancel()
        mutationTask = Task {
            await viewModel.claim(item, canRespond: canRespond, bearer: auth.accessToken ?? "")
        }
    }

    private func beginReply(_ item: PatientCommunicationWorkItem) {
        guard canRespond else { return }
        let submittedDraft = draft
        composerFocused = false
        mutationTask?.cancel()
        mutationTask = Task {
            if await viewModel.reply(
                item,
                message: submittedDraft,
                canRespond: canRespond,
                bearer: auth.accessToken ?? ""
            ) {
                draft = ""
            }
        }
    }

    private func beginClose(_ reason: PatientCommunicationCloseReason) {
        guard canRespond else { return }
        guard let item = viewModel.thread else { return }
        mutationTask?.cancel()
        mutationTask = Task {
            await viewModel.close(
                item,
                reason: reason,
                canRespond: canRespond,
                bearer: auth.accessToken ?? ""
            )
        }
    }

    private func beginRouting(
        _ action: PatientCommunicationRoutingAction,
        item: PatientCommunicationWorkItem,
        targetUUID: String?,
        reason: PatientCommunicationRoutingReasonOption
    ) {
        guard canRespond else { return }
        mutationTask?.cancel()
        mutationTask = Task {
            _ = await viewModel.route(
                action,
                item: item,
                targetUUID: targetUUID,
                reason: reason,
                canRespond: canRespond,
                bearer: auth.accessToken ?? ""
            )
        }
    }

    private func beginExactRoutingRetry() {
        guard canRespond else { return }
        mutationTask?.cancel()
        mutationTask = Task {
            if await viewModel.retryPendingRouting(
                canRespond: canRespond,
                bearer: auth.accessToken ?? ""
            ) {
                draft = ""
                composerFocused = false
                routingAction = nil
            }
        }
    }

    private func beginExactMutationRetry() {
        guard canRespond, viewModel.pendingMutationRetryAction != nil else { return }
        mutationTask?.cancel()
        mutationTask = Task {
            if await viewModel.retryPendingMutation(
                canRespond: canRespond,
                bearer: auth.accessToken ?? ""
            ) {
                draft = ""
                composerFocused = false
                routingAction = nil
                showCloseReasons = false
            }
        }
    }

    private func clearSensitiveUIState() {
        mutationTask?.cancel()
        mutationTask = nil
        draft = ""
        composerFocused = false
        showCloseReasons = false
        routingAction = nil
        showMutationRetryConfirmation = false
        showRoutingRetryConfirmation = false
    }

    #if DEBUG
    private func runAuthorizationRefreshUITestIfNeeded() async {
        let scenario = StaffCommunicationsUITestMode.scenario
        let threadScenarios = ["thread_403_refresh", "thread_404_refresh"]
        let candidateScenarios = ["candidate_401_refresh", "candidate_404_refresh"]
        guard threadScenarios.contains(scenario) || candidateScenarios.contains(scenario) else { return }
        try? await Task.sleep(for: .seconds(7))
        guard !Task.isCancelled else { return }
        if threadScenarios.contains(scenario) {
            await viewModel.loadThread(
                workItemUUID: workItemUUID,
                bearer: auth.accessToken ?? ""
            )
        } else if let item = viewModel.thread {
            await viewModel.loadRouteCandidates(
                for: item,
                canRespond: canRespond,
                bearer: auth.accessToken ?? ""
            )
        }
    }
    #endif

    private func loadThread() async {
        await viewModel.loadThread(workItemUUID: workItemUUID, bearer: auth.accessToken ?? "")
        guard let item = viewModel.thread else {
            viewModel.handleUnavailableThreadRefresh()
            return
        }
        await viewModel.loadRouteCandidates(
            for: item,
            canRespond: canRespond,
            bearer: auth.accessToken ?? ""
        )
    }
}

private struct PatientCommunicationRoutingUnavailableSheet: View {
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            RetryableMessage(
                symbol: "arrow.triangle.branch",
                title: "Ownership options changed",
                message: "Dismiss this view and refresh the conversation before selecting an ownership action.",
                tone: .warning
            ) { dismiss() }
            .padding(Z.s4)
            .background { HummingbirdBackdrop(dim: 0.64) }
            .navigationTitle("Ownership and routing")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
        .patientCommunicationPrivacySensitive()
    }
}

private struct PatientCommunicationMessageBubble: View {
    let message: PatientCommunicationMessage

    var body: some View {
        HStack {
            if !message.isFromPatient { Spacer(minLength: Z.s6) }
            VStack(alignment: .leading, spacing: Z.s1) {
                HStack(spacing: Z.s1) {
                    Text(message.senderDisplayRole)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(message.isFromPatient ? Z.status(.info) : Z.status(.success))
                    if !message.isPatientVisible {
                        Label("Internal", systemImage: "eye.slash.fill")
                            .font(.caption2.weight(.semibold))
                            .foregroundStyle(Z.status(.warning))
                    }
                }

                Text(message.body ?? "Status update")
                    .font(.body)
                    .foregroundStyle(Z.ink)
                    .fixedSize(horizontal: false, vertical: true)

                HStack(spacing: Z.s1) {
                    Text(PatientCommunicationDates.absolute(message.sentAt))
                    if !message.isFromPatient, message.isPatientVisible {
                        Text("·")
                        Text(deliveryLabel)
                    }
                }
                .font(.caption2)
                .foregroundStyle(Z.inkMuted)
            }
            .padding(Z.s3)
            .background(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(message.isFromPatient ? Z.status(.info).opacity(0.14) : Z.status(.success).opacity(0.12))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .strokeBorder(message.isFromPatient ? Z.status(.info).opacity(0.38) : Z.status(.success).opacity(0.34), lineWidth: 1)
            )
            if message.isFromPatient { Spacer(minLength: Z.s6) }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilitySummary)
    }

    private var deliveryLabel: String {
        switch message.deliveryState {
        case "responded": return "Patient responded"
        case "closed": return "Conversation closed"
        case "delivered": return "Delivered to patient"
        case "acknowledged": return "Acknowledged"
        default: return message.deliveryState.replacingOccurrences(of: "_", with: " ").capitalized
        }
    }

    private var accessibilitySummary: String {
        let visibility = message.isPatientVisible ? "patient visible" : "internal"
        return "\(message.senderDisplayRole), \(visibility), \(message.body ?? "status update"), \(PatientCommunicationDates.absolute(message.sentAt))"
    }
}

private struct PatientCommunicationPrivacyCover: View {
    var body: some View {
        ZStack {
            Z.bg.ignoresSafeArea()
            VStack(spacing: Z.s3) {
                Image(systemName: "lock.shield.fill")
                    .font(.largeTitle)
                    .foregroundStyle(Z.primary)
                Text("Conversation hidden")
                    .font(.headline)
                    .foregroundStyle(Z.ink)
            }
        }
        .accessibilityElement(children: .combine)
    }
}
