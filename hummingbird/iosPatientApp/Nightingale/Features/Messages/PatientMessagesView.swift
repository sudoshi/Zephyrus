import SwiftUI

struct PatientMessagesView: View {
    let snapshot: PatientExperienceSnapshot
    @ObservedObject var viewModel: PatientAppViewModel
    @State private var selectedTopicCode = ""
    @State private var newMessage = ""

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                PatientScreenHeader(
                    eyebrow: "Nonurgent care questions",
                    title: "Messages",
                    subtitle: "Ask a question about your care and follow the conversation with your care team. Messages are not live chat."
                )

                #if DEBUG
                if snapshot.isSynthetic { SyntheticReferenceBanner() }
                #endif

                messagingContent
            }
            .padding(20)
        }
        .background {
            PatientPhotoBackground(scene: .messages)
                .ignoresSafeArea()
        }
        .navigationTitle("Messages")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable {
            await viewModel.refreshMessaging()
        }
        .task {
            if snapshot.canReadMessaging,
               case .notGranted = viewModel.messagingState {
                await viewModel.refreshMessaging()
            }
        }
    }

    @ViewBuilder
    private var messagingContent: some View {
        switch viewModel.messagingState {
        case .notGranted:
            PatientPhotoStateCard(
                scene: .empty,
                icon: "message.slash.fill",
                title: "Messages are not available",
                message: "This care connection has not released messaging access. Use your bedside call button or speak with a staff member when you need help."
            )
            .accessibilityIdentifier("messages-not-granted-state")

        case .disabled:
            PatientPhotoStateCard(
                scene: .empty,
                icon: "message.slash.fill",
                title: "Messages are not available right now",
                message: "Messaging may not be enabled for this hospital or care connection. No message was sent or saved for later."
            )
            .accessibilityIdentifier("messages-disabled-state")

        case .loading:
            PatientCard {
                HStack(spacing: 12) {
                    ProgressView()
                    Text("Opening your conversations…")
                        .font(.body.weight(.semibold))
                }
            }
            .accessibilityElement(children: .combine)
            .accessibilityLabel("Opening your conversations")
            .accessibilityIdentifier("messages-loading-state")

        case .failed:
            PatientPhotoStateCard(
                scene: .error,
                icon: "wifi.exclamationmark",
                title: "Messages could not be opened",
                message: "Nothing was sent or queued. Check your connection and try again.",
                actionTitle: "Try again"
            ) {
                Task { await viewModel.refreshMessaging() }
            }
            .accessibilityIdentifier("messages-error-state")

        case .ready(let overview):
            PatientImmediateHelpCard(guidance: overview.immediateHelp)

            if let messagingMessage = viewModel.messagingMessage {
                PatientMessagingFeedbackCard(message: messagingMessage)
            }

            PatientNoOfflineQueueCard()

            Text("Your conversations")
                .font(.title2.bold())
                .foregroundStyle(PatientPalette.ink)

            if overview.threads.isEmpty {
                PatientPhotoStateCard(
                    scene: .empty,
                    icon: "message.fill",
                    title: "No conversations yet",
                    message: snapshot.canWriteMessaging
                        ? "Use the new-message section below for a nonurgent care question."
                        : "No patient-visible conversations have been released for this care connection."
                )
                .accessibilityIdentifier("messages-empty-state")
            } else {
                ForEach(overview.threads) { thread in
                    NavigationLink {
                        PatientMessageThreadView(
                            snapshot: snapshot,
                            threadUUID: thread.threadUUID,
                            viewModel: viewModel
                        )
                    } label: {
                        PatientMessageThreadSummaryCard(thread: thread)
                    }
                    .buttonStyle(.plain)
                    .accessibilityIdentifier("message-thread-\(thread.threadUUID)")
                }
            }

            if snapshot.canWriteMessaging {
                PatientNewMessageComposer(
                    topics: overview.topics,
                    selectedTopicCode: $selectedTopicCode,
                    message: $newMessage,
                    isBusy: viewModel.isMessagingBusy
                ) {
                    let sent = await viewModel.createMessageThread(
                        topicCode: selectedTopicCode,
                        message: newMessage
                    )
                    if sent { newMessage = "" }
                }
                .onAppear {
                    if selectedTopicCode.isEmpty {
                        selectedTopicCode = overview.topics.first?.code ?? ""
                    }
                }
            } else {
                PatientCard {
                    VStack(alignment: .leading, spacing: 8) {
                        Label("Viewing only", systemImage: "eye.fill")
                            .font(.headline)
                            .foregroundStyle(PatientPalette.blue)
                        Text(snapshot.isSynthetic
                            ? "This synthetic preview shows how a conversation looks. It cannot send or close messages."
                            : "This care connection allows you to view conversations but not send or close them.")
                            .font(.body)
                    }
                }
                .accessibilityIdentifier("messages-read-only-state")
            }
        }
    }
}

private struct PatientImmediateHelpCard: View {
    let guidance: PatientImmediateHelpGuidance

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 9) {
                Label("Need help now?", systemImage: "cross.case.fill")
                    .font(.headline)
                    .foregroundStyle(PatientPalette.rose)
                Text(guidance.text)
                    .font(.body)
                Text("Do not wait for a message response when you need immediate help.")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("message-immediate-help")
    }
}

private struct PatientNoOfflineQueueCard: View {
    var body: some View {
        PatientCard {
            Label(
                "Messages send only while you are connected. Hummingbird does not queue an unsent message or create an offline outbox.",
                systemImage: "wifi"
            )
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(.secondary)
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("messages-no-offline-queue")
    }
}

private struct PatientMessagingFeedbackCard: View {
    let message: String

    var body: some View {
        PatientCard {
            Label(message, systemImage: "info.circle.fill")
                .font(.body.weight(.semibold))
                .foregroundStyle(PatientPalette.blue)
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("messages-feedback")
    }
}

private struct PatientMessageThreadSummaryCard: View {
    let thread: PatientMessageThreadSummary

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .firstTextBaseline) {
                    Text(thread.topic.label)
                        .font(.headline)
                        .foregroundStyle(PatientPalette.ink)
                    Spacer(minLength: 8)
                    Image(systemName: "chevron.right")
                        .font(.caption.bold())
                        .foregroundStyle(.secondary)
                        .accessibilityHidden(true)
                }
                Label(thread.ownershipState.patientLabel, systemImage: thread.status == .open ? "message.badge.fill" : "checkmark.circle.fill")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(thread.status == .open ? PatientPalette.blue : PatientPalette.teal)
                Text(thread.expectedResponseWindow)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                Text("Last update \(PatientMessageDateFormatting.display(thread.lastMessageAt))")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Conversation: \(thread.topic.label). \(thread.ownershipState.patientLabel). \(thread.expectedResponseWindow)")
    }
}

private struct PatientNewMessageComposer: View {
    let topics: [PatientMessageTopic]
    @Binding var selectedTopicCode: String
    @Binding var message: String
    let isBusy: Bool
    let send: () async -> Void

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 13) {
                Label("Start a new conversation", systemImage: "square.and.pencil")
                    .font(.title3.bold())
                    .foregroundStyle(PatientPalette.blue)

                if topics.isEmpty {
                    Text("No approved message topics are available right now.")
                        .font(.body)
                        .foregroundStyle(.secondary)
                } else {
                    Picker("Question topic", selection: $selectedTopicCode) {
                        ForEach(topics) { topic in
                            Text(topic.label).tag(topic.code)
                        }
                    }
                    .pickerStyle(.menu)
                    .accessibilityIdentifier("new-message-topic")

                    if let topic = topics.first(where: { $0.code == selectedTopicCode }) {
                        Text(topic.description)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                        Text(topic.expectedResponseWindow)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(PatientPalette.blue)
                    }

                    PatientMessageEditor(
                        title: "Your nonurgent question",
                        text: $message
                    )

                    Button {
                        Task { await send() }
                    } label: {
                        if isBusy {
                            ProgressView()
                                .frame(maxWidth: .infinity)
                        } else {
                            Label("Send to care team", systemImage: "paperplane.fill")
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(
                        isBusy
                            || selectedTopicCode.isEmpty
                            || message.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                    )
                    .accessibilityIdentifier("new-message-send")
                }
            }
        }
        .accessibilityIdentifier("new-message-composer")
    }
}

private struct PatientMessageThreadView: View {
    let snapshot: PatientExperienceSnapshot
    let threadUUID: String
    @ObservedObject var viewModel: PatientAppViewModel
    @State private var reply = ""
    @State private var showCloseReasons = false
    @State private var correctionTarget: PatientVisibleMessage?
    @State private var correctionDraft = ""
    @State private var retractionTarget: PatientVisibleMessage?

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                if let result = viewModel.selectedMessageThread,
                   result.thread.threadUUID == threadUUID {
                    PatientImmediateHelpCard(guidance: result.immediateHelp)

                    if let messagingMessage = viewModel.messagingMessage {
                        PatientMessagingFeedbackCard(message: messagingMessage)
                    }

                    PatientNoOfflineQueueCard()
                    conversationHeader(result.thread)

                    Text("Conversation")
                        .font(.title2.bold())

                    ForEach(result.thread.messages) { message in
                        PatientVisibleMessageCard(
                            message: message,
                            canAmend: canAmend(message, in: result.thread),
                            onCorrect: {
                                correctionTarget = message
                                correctionDraft = message.body ?? ""
                            },
                            onWithdraw: {
                                retractionTarget = message
                            }
                        )
                    }

                    if let correctionTarget {
                        PatientCorrectionComposer(
                            originalMessage: correctionTarget,
                            correction: $correctionDraft,
                            isBusy: viewModel.isMessagingBusy,
                            onCancel: {
                                self.correctionTarget = nil
                                correctionDraft = ""
                            },
                            onSend: {
                                let amended = await viewModel.amendMessage(
                                    threadUUID: threadUUID,
                                    messageUUID: correctionTarget.messageUUID,
                                    action: .correction,
                                    message: correctionDraft
                                )
                                if amended {
                                    self.correctionTarget = nil
                                    correctionDraft = ""
                                }
                            }
                        )
                    }

                    if result.thread.messages.isEmpty {
                        PatientPhotoStateCard(
                            scene: .empty,
                            icon: "message.fill",
                            title: "No visible messages",
                            message: "This conversation does not contain a patient-visible message yet."
                        )
                    }

                    if snapshot.canWriteMessaging, result.thread.status == .open {
                        PatientReplyComposer(
                            reply: $reply,
                            isBusy: viewModel.isMessagingBusy
                        ) {
                            let sent = await viewModel.sendMessage(
                                threadUUID: threadUUID,
                                message: reply
                            )
                            if sent { reply = "" }
                        }

                        Button(role: .destructive) {
                            showCloseReasons = true
                        } label: {
                            Label(closeActionLabel(for: result.thread), systemImage: "xmark.circle")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                        .disabled(viewModel.isMessagingBusy)
                        .accessibilityIdentifier("close-message-thread")
                    } else if result.thread.status == .closed {
                        PatientCard {
                            Label("This conversation is closed. New messages cannot be added.", systemImage: "checkmark.circle.fill")
                                .font(.body.weight(.semibold))
                                .foregroundStyle(PatientPalette.teal)
                        }
                    }
                } else if viewModel.isMessagingBusy {
                    PatientCard {
                        HStack(spacing: 12) {
                            ProgressView()
                            Text("Opening this conversation…")
                        }
                    }
                } else {
                    PatientPhotoStateCard(
                        scene: .error,
                        icon: "message.badge.waveform.fill",
                        title: "Conversation unavailable",
                        message: "Nothing was sent or queued. Return to Messages and try again when you are online."
                    )
                }
            }
            .padding(20)
        }
        .background {
            PatientPhotoBackground(scene: .messages)
                .ignoresSafeArea()
        }
        .navigationTitle("Conversation")
        .navigationBarTitleDisplayMode(.inline)
        .task(id: threadUUID) {
            await viewModel.openMessageThread(threadUUID: threadUUID)
        }
        .confirmationDialog(
            closeDialogTitle,
            isPresented: $showCloseReasons,
            titleVisibility: .visible
        ) {
            ForEach(PatientMessageThreadCloseReason.allCases) { reason in
                Button(reason.patientLabel) {
                    Task {
                        _ = await viewModel.closeMessageThread(
                            threadUUID: threadUUID,
                            reason: reason
                        )
                    }
                }
            }
            Button("Keep conversation open", role: .cancel) {}
        } message: {
            Text(closeDialogMessage)
        }
        .confirmationDialog(
            "Withdraw this message?",
            isPresented: Binding(
                get: { retractionTarget != nil },
                set: { if !$0 { retractionTarget = nil } }
            ),
            titleVisibility: .visible
        ) {
            Button("Withdraw message", role: .destructive) {
                guard let retractionTarget else { return }
                Task {
                    let amended = await viewModel.amendMessage(
                        threadUUID: threadUUID,
                        messageUUID: retractionTarget.messageUUID,
                        action: .retraction
                    )
                    if amended { self.retractionTarget = nil }
                }
            }
            Button("Keep message", role: .cancel) { retractionTarget = nil }
        } message: {
            Text("This sends a withdrawal to your care team. It does not erase the earlier message from the conversation history.")
        }
    }

    private var selectedThread: PatientMessageThreadDetail? {
        guard let result = viewModel.selectedMessageThread,
              result.thread.threadUUID == threadUUID else {
            return nil
        }
        return result.thread
    }

    private var isUnsharedRoundsQuestion: Bool {
        guard let thread = selectedThread, thread.topic.code == "rounds_question" else {
            return false
        }
        return !thread.messages.contains(where: { $0.messageKind == .systemStatus })
    }

    private var closeDialogTitle: String {
        isUnsharedRoundsQuestion ? "Why are you withdrawing this question?" : "Why are you closing this conversation?"
    }

    private var closeDialogMessage: String {
        if isUnsharedRoundsQuestion {
            return "Withdrawing stops this question from being shared for a care-team round if it has not already been shared. It does not erase the conversation history."
        }
        return "Closing stops new messages in this conversation. You can still read its history."
    }

    private func closeActionLabel(for thread: PatientMessageThreadDetail) -> String {
        guard thread.topic.code == "rounds_question",
              !thread.messages.contains(where: { $0.messageKind == .systemStatus }) else {
            return "Close this conversation"
        }
        return "Withdraw this question"
    }

    private func canAmend(
        _ message: PatientVisibleMessage,
        in thread: PatientMessageThreadDetail
    ) -> Bool {
        snapshot.canWriteMessaging
            && thread.status == .open
            && !viewModel.isMessagingBusy
            && message.senderDisplayRole == .patient
            && message.messageKind == .message
            && !thread.messages.contains(where: {
                $0.relatesToMessageUUID == message.messageUUID
                    && ($0.messageKind == .correction || $0.messageKind == .retraction)
            })
    }

    private func conversationHeader(_ thread: PatientMessageThreadDetail) -> some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 8) {
                Text(thread.topic.label)
                    .font(.title2.bold())
                Text(thread.topic.description)
                    .font(.body)
                    .foregroundStyle(.secondary)
                Label(thread.ownershipState.patientLabel, systemImage: "message.badge.fill")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(PatientPalette.blue)
                Text(thread.expectedResponseWindow)
                    .font(.subheadline)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("message-thread-header")
    }
}

private struct PatientVisibleMessageCard: View {
    let message: PatientVisibleMessage
    let canAmend: Bool
    let onCorrect: () -> Void
    let onWithdraw: () -> Void

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .firstTextBaseline) {
                    Text(message.senderDisplayRole.rawValue)
                        .font(.headline)
                        .foregroundStyle(message.senderDisplayRole == .patient ? PatientPalette.blue : PatientPalette.teal)
                    Spacer(minLength: 8)
                    Text(PatientMessageDateFormatting.display(message.sentAt))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Text(visibleBody)
                    .font(.body)
                if message.senderDisplayRole == .patient {
                    Text(message.deliveryState.patientLabel)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.secondary)
                }
                if canAmend {
                    HStack(spacing: 8) {
                        Button("Correct message", action: onCorrect)
                            .buttonStyle(.bordered)
                            .accessibilityIdentifier("correct-message-\(message.messageUUID)")
                        Button("Withdraw message", role: .destructive, action: onWithdraw)
                            .buttonStyle(.bordered)
                            .accessibilityIdentifier("withdraw-message-\(message.messageUUID)")
                    }
                    Text("A correction or withdrawal adds a new record. It does not erase the message already in this conversation.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .accessibilityElement(children: .combine)
    }

    private var visibleBody: String {
        if let body = message.body?.trimmingCharacters(in: .whitespacesAndNewlines), !body.isEmpty {
            return body
        }
        return switch message.messageKind {
        case .message: "Message content is not available."
        case .correction: "A previous message was corrected."
        case .retraction: "A previous message was withdrawn. The earlier message remains in this conversation."
        case .systemStatus: "The conversation status changed."
        }
    }
}

private struct PatientCorrectionComposer: View {
    let originalMessage: PatientVisibleMessage
    @Binding var correction: String
    let isBusy: Bool
    let onCancel: () -> Void
    let onSend: () async -> Void

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 13) {
                Label("Correct your message", systemImage: "pencil.line")
                    .font(.title3.bold())
                    .foregroundStyle(PatientPalette.blue)
                Text("Your correction is sent as a new message. The earlier message stays visible in the conversation history.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                PatientMessageEditor(title: "Your corrected nonurgent message", text: $correction)
                HStack(spacing: 10) {
                    Button {
                        Task { await onSend() }
                    } label: {
                        if isBusy {
                            ProgressView()
                                .frame(maxWidth: .infinity)
                        } else {
                            Label("Send correction", systemImage: "paperplane.fill")
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(isBusy || correction.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    .accessibilityIdentifier("message-correction-send-\(originalMessage.messageUUID)")

                    Button("Cancel", action: onCancel)
                        .buttonStyle(.bordered)
                        .disabled(isBusy)
                }
            }
        }
        .accessibilityIdentifier("message-correction-composer-\(originalMessage.messageUUID)")
    }
}

private struct PatientReplyComposer: View {
    @Binding var reply: String
    let isBusy: Bool
    let send: () async -> Void

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 13) {
                Label("Reply", systemImage: "arrowshape.turn.up.left.fill")
                    .font(.title3.bold())
                    .foregroundStyle(PatientPalette.blue)
                PatientMessageEditor(title: "Your nonurgent reply", text: $reply)
                Button {
                    Task { await send() }
                } label: {
                    if isBusy {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    } else {
                        Label("Send reply", systemImage: "paperplane.fill")
                            .frame(maxWidth: .infinity)
                    }
                }
                .buttonStyle(.borderedProminent)
                .disabled(isBusy || reply.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                .accessibilityIdentifier("message-reply-send")
            }
        }
        .accessibilityIdentifier("message-reply-composer")
    }
}

private struct PatientMessageEditor: View {
    let title: String
    @Binding var text: String

    var body: some View {
        VStack(alignment: .leading, spacing: 7) {
            Text(title)
                .font(.subheadline.weight(.semibold))
            TextEditor(text: $text)
                .frame(minHeight: 120)
                .padding(7)
                .background(Color(uiColor: .tertiarySystemBackground), in: RoundedRectangle(cornerRadius: 12))
                .overlay {
                    RoundedRectangle(cornerRadius: 12)
                        .stroke(Color.primary.opacity(0.12), lineWidth: 1)
                }
                .accessibilityLabel(title)
                .onChange(of: text) { _, newValue in
                    if newValue.count > 2_000 {
                        text = String(newValue.prefix(2_000))
                    }
                }
            Text("\(text.count) of 2,000 characters")
                .font(.caption)
                .foregroundStyle(.secondary)
                .frame(maxWidth: .infinity, alignment: .trailing)
        }
    }
}

private enum PatientMessageDateFormatting {
    static func display(_ value: String) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        guard let date = formatter.date(from: value) else { return "recently" }
        return date.formatted(date: .abbreviated, time: .shortened)
    }
}
