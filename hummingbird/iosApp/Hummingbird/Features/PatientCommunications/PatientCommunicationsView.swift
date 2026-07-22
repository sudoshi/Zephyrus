import SwiftUI

struct PatientCommunicationsView: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.scenePhase) private var scenePhase
    @StateObject private var viewModel: PatientCommunicationsViewModel
    @State private var path = NavigationPath()

    init(repository: PatientCommunicationsRepository? = nil) {
        let resolved: PatientCommunicationsRepository
        if let repository {
            resolved = repository
        } else {
            #if DEBUG
            if StaffCommunicationsUITestMode.isEnabled {
                resolved = PatientCommunicationsUITestRepository()
            } else {
                resolved = APIClient.patientCommunications(baseURL: URL(string: AppConfig.baseURL)!)
            }
            #else
            resolved = APIClient.patientCommunications(baseURL: URL(string: AppConfig.baseURL)!)
            #endif
        }
        _viewModel = StateObject(wrappedValue: PatientCommunicationsViewModel(repository: resolved))
    }

    var body: some View {
        NavigationStack(path: $path) {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: Z.s3) {
                    guidance

                    if viewModel.inbox.isEmpty && viewModel.isLoadingInbox {
                        SkeletonRows(count: 4)
                    } else if viewModel.inboxUnavailable {
                        unavailableState
                    } else if let error = viewModel.inboxErrorMessage, viewModel.inbox.isEmpty {
                        RetryableMessage(
                            symbol: "lock.shield",
                            title: "Can't load communications",
                            message: error,
                            tone: .warning
                        ) {
                            Task { await loadInbox() }
                        }
                    } else if viewModel.inbox.isEmpty {
                        emptyState
                    } else {
                        inboxHeader
                        ForEach(viewModel.inbox) { item in
                            NavigationLink(value: item.workItemUuid) {
                                PatientCommunicationInboxRow(item: item)
                            }
                            .buttonStyle(.plain)
                            .accessibilityIdentifier("patientCommunications.row.\(item.workItemUuid)")
                        }
                    }
                }
                .padding(Z.s4)
            }
            .accessibilityIdentifier("patientCommunications.inbox")
            .background { HummingbirdBackdrop(dim: 0.52) }
            .navigationTitle("Patient messages")
            .navigationBarTitleDisplayMode(.inline)
            .navigationDestination(for: String.self) { workItemUUID in
                PatientCommunicationDetailView(
                    viewModel: viewModel,
                    workItemUUID: workItemUUID,
                    canRespond: auth.me?.can.respondPatientCommunications == true
                )
            }
            .refreshable { await loadInbox() }
        }
        .task {
            // Keep authorization-aware inbox polling attached to the navigation
            // container so it remains active while a conversation is open.
            // A later inbox 401 must purge that detail projection and its draft,
            // not wait until the user navigates back to the inbox screen.
            while !Task.isCancelled {
                await loadInbox()
                try? await Task.sleep(for: inboxPollInterval)
            }
        }
        .tint(Z.primary)
        .patientCommunicationPrivacySensitive()
        .overlay {
            if scenePhase != .active {
                PatientCommunicationPrivacyShield()
            }
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active {
                Task { await loadInbox() }
            } else {
                path = NavigationPath()
                viewModel.suspend()
            }
        }
        .onChange(of: viewModel.needsReauth) { _, required in
            guard required else { return }
            path = NavigationPath()
            Task { await auth.logout() }
        }
    }

    private var guidance: some View {
        PatientCommunicationCard {
            HStack(alignment: .top, spacing: Z.s3) {
                Image(systemName: "cross.case.fill")
                    .font(.title2)
                    .foregroundStyle(Z.status(.warning))
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: Z.s1) {
                    Text("Non-emergency communication")
                        .font(.headline)
                        .foregroundStyle(Z.ink)
                    Text("Use established clinical escalation channels for urgent changes. Patient message threads are not an emergency-response path.")
                        .font(.subheadline)
                        .foregroundStyle(Z.inkMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }

    private var inboxHeader: some View {
        HStack(alignment: .firstTextBaseline) {
            Text("Open conversations")
                .font(.title3.weight(.semibold))
                .foregroundStyle(Z.ink)
            Spacer()
            Text("\(viewModel.inbox.count)")
                .font(.subheadline.monospacedDigit().weight(.semibold))
                .foregroundStyle(Z.inkMuted)
                .accessibilityLabel("\(viewModel.inbox.count) open conversations")
        }
        .padding(.top, Z.s1)
    }

    private var unavailableState: some View {
        RetryableMessage(
            symbol: "person.crop.circle.badge.questionmark",
            title: "Communications unavailable",
            message: "Patient communications are not available for your current assignment. Your care-team responsibilities may have changed.",
            tone: .info
        ) {
            Task { await loadInbox() }
        }
        .accessibilityIdentifier("patientCommunications.unavailable")
    }

    private var emptyState: some View {
        RetryableMessage(
            symbol: "checkmark.message.fill",
            title: "No open patient messages",
            message: "New conversations routed to one of your active care teams will appear here.",
            tone: .success
        )
    }

    private func loadInbox() async {
        await viewModel.loadInbox(bearer: auth.accessToken ?? "")
    }

    private var inboxPollInterval: Duration {
        #if DEBUG
        if [
            "inbox_401_detail",
            "inbox_403_detail",
            "inbox_404_detail",
            "inbox_200_empty_detail",
        ].contains(StaffCommunicationsUITestMode.scenario) {
            return .milliseconds(700)
        }
        #endif
        return .seconds(20)
    }
}

private struct PatientCommunicationInboxRow: View {
    let item: PatientCommunicationWorkItem

    var body: some View {
        PatientCommunicationCard {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(alignment: .top, spacing: Z.s2) {
                    Image(systemName: statusSymbol)
                        .font(.title3)
                        .foregroundStyle(statusColor)
                        .accessibilityHidden(true)
                    VStack(alignment: .leading, spacing: Z.s1) {
                        Text(item.topic.label)
                            .font(.headline)
                            .foregroundStyle(Z.ink)
                            .fixedSize(horizontal: false, vertical: true)
                        Text(contextLine)
                            .font(.subheadline)
                            .foregroundStyle(Z.inkMuted)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: Z.s1)
                    Image(systemName: "chevron.right")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(Z.inkMuted)
                        .accessibilityHidden(true)
                }

                Divider().overlay(Z.border)

                ViewThatFits(in: .horizontal) {
                    HStack(spacing: Z.s2) { statusLabels }
                    VStack(alignment: .leading, spacing: Z.s1) { statusLabels }
                }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilitySummary)
        .accessibilityHint("Opens the patient conversation")
    }

    @ViewBuilder private var statusLabels: some View {
        PatientCommunicationStateLabel(
            symbol: ownershipSymbol,
            label: ownershipLabel,
            tone: ownershipTone
        )
        PatientCommunicationStateLabel(
            symbol: statusSymbol,
            label: deadlineLabel,
            tone: deadlineTone
        )
        Spacer(minLength: 0)
        Text(PatientCommunicationDates.relative(item.lastMessageAt))
            .font(.caption)
            .foregroundStyle(Z.inkMuted)
    }

    private var contextLine: String {
        [item.unit?.label, item.pool.label]
            .compactMap { $0 }
            .joined(separator: " · ")
    }

    private var ownershipLabel: String {
        if item.assignedToMe { return "Owned by you" }
        switch item.ownershipState {
        case "pool_owned", "rerouted", "escalated": return "Team queue"
        case "responded": return "Response sent"
        case "closed": return "Closed"
        default: return "Team member assigned"
        }
    }

    private var ownershipSymbol: String {
        item.assignedToMe ? "person.crop.circle.badge.checkmark" : "person.2.fill"
    }

    private var ownershipTone: CapacityStatus {
        item.assignedToMe ? .success : .info
    }

    private var statusSymbol: String {
        if item.isEscalationDue { return "exclamationmark.triangle.fill" }
        if item.isResponseDue { return "clock.badge.exclamationmark.fill" }
        return "clock.fill"
    }

    private var statusColor: Color { Z.status(deadlineTone) }

    private var deadlineTone: CapacityStatus {
        if item.isEscalationDue { return .critical }
        if item.isResponseDue { return .warning }
        return .info
    }

    private var deadlineLabel: String {
        if item.isEscalationDue { return "Escalation due" }
        if item.isResponseDue { return "Response due" }
        return "Due \(PatientCommunicationDates.relative(item.dueAt))"
    }

    private var accessibilitySummary: String {
        "\(item.topic.label), \(contextLine), \(ownershipLabel), \(deadlineLabel), last message \(PatientCommunicationDates.relative(item.lastMessageAt))"
    }
}

struct PatientCommunicationCard<Content: View>: View {
    @Environment(\.colorSchemeContrast) private var contrast
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency
    @ViewBuilder let content: Content

    var body: some View {
        content
            .padding(Z.s4)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background {
                RoundedRectangle(cornerRadius: Z.radius, style: .continuous)
                    .fill(reduceTransparency ? Z.surface : Z.surface.opacity(0.72))
                    .background {
                        if !reduceTransparency {
                            RoundedRectangle(cornerRadius: Z.radius, style: .continuous)
                                .fill(.regularMaterial)
                        }
                    }
            }
            .overlay {
                RoundedRectangle(cornerRadius: Z.radius, style: .continuous)
                    .strokeBorder(
                        contrast == .increased ? Z.ink.opacity(0.72) : Z.border,
                        lineWidth: contrast == .increased ? 2 : 1
                    )
            }
    }
}

struct PatientCommunicationStateLabel: View {
    let symbol: String
    let label: String
    let tone: CapacityStatus

    var body: some View {
        Label(label, systemImage: symbol)
            .font(.caption.weight(.semibold))
            .foregroundStyle(Z.status(tone))
            .padding(.horizontal, Z.s2)
            .padding(.vertical, Z.s1)
            .background(Capsule().fill(Z.status(tone).opacity(0.16)))
            .overlay(Capsule().strokeBorder(Z.status(tone).opacity(0.42), lineWidth: 1))
    }
}

enum PatientCommunicationDates {
    static func relative(_ source: String?) -> String {
        guard let source, let date = parse(source) else { return "time unavailable" }
        return relativeFormatter.localizedString(for: date, relativeTo: Date())
    }

    static func absolute(_ source: String?) -> String {
        guard let source, let date = parse(source) else { return "Time unavailable" }
        return absoluteFormatter.string(from: date)
    }

    private static func parse(_ source: String) -> Date? {
        ISO8601DateFormatter().date(from: source) ?? ISO8601DateFormatter.flexible.date(from: source)
    }

    private static let relativeFormatter: RelativeDateTimeFormatter = {
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .full
        return formatter
    }()

    private static let absoluteFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateStyle = .medium
        formatter.timeStyle = .short
        return formatter
    }()
}

private struct PatientCommunicationPrivacyShield: View {
    var body: some View {
        ZStack {
            Z.bg.ignoresSafeArea()
            VStack(spacing: Z.s3) {
                Image(systemName: "lock.shield.fill")
                    .font(.largeTitle)
                    .foregroundStyle(Z.primary)
                Text("Patient communications hidden")
                    .font(.headline)
                    .foregroundStyle(Z.ink)
            }
        }
        .accessibilityElement(children: .combine)
    }
}

extension View {
    /// Production communications remain privacy-sensitive during capture and
    /// app switching. The dedicated Debug UI-test process uses synthetic data
    /// only, so it must opt out to keep XCUITest's automatic screen recording
    /// from redacting the view tree that the test needs to exercise.
    @ViewBuilder
    func patientCommunicationPrivacySensitive() -> some View {
        #if DEBUG
        if StaffCommunicationsUITestMode.isEnabled {
            self
        } else {
            privacySensitive()
        }
        #else
        privacySensitive()
        #endif
    }
}
