import SwiftUI

enum AltitudeLevel: String, CaseIterable {
    case a0 = "A0"
    case a1 = "A1"
    case a2 = "A2"
    case a2p = "A2P"
    case a3 = "A3"

    var label: String {
        switch self {
        case .a0: return "Glance"
        case .a1: return "Workspace"
        case .a2: return "Drill"
        case .a2p: return "Patient"
        case .a3: return "Study"
        }
    }

    var rank: Int {
        Self.allCases.firstIndex(of: self) ?? 0
    }
}

struct AltitudeBreadcrumbView: View {
    let current: AltitudeLevel
    var includesPatient: Bool = false

    private var levels: [AltitudeLevel] {
        includesPatient ? [.a0, .a1, .a2, .a2p, .a3] : [.a0, .a1, .a2, .a3]
    }

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Z.s2) {
                ForEach(levels, id: \.rawValue) { level in
                    levelChip(level)
                    if level != levels.last {
                        Image(systemName: "chevron.right")
                            .font(.system(size: 9, weight: .semibold))
                            .foregroundStyle(Z.inkMuted)
                    }
                }
            }
        }
        .accessibilityLabel("Altitude \(current.rawValue), \(current.label)")
    }

    private func levelChip(_ level: AltitudeLevel) -> some View {
        let active = level == current
        let reached = level.rank < current.rank
        return HStack(spacing: 4) {
            Text(level.rawValue)
                .font(.system(size: 10, weight: .semibold))
            Text(level.label)
                .font(.system(size: 10, weight: .medium))
        }
        .foregroundStyle(active ? .white : (reached ? Z.primary : Z.inkMuted))
        .padding(.horizontal, Z.s2)
        .padding(.vertical, Z.s1)
        .background(Capsule().fill(active ? Z.primary : Z.surface))
        .overlay(Capsule().strokeBorder(active ? Z.primary : Z.border, lineWidth: 1))
    }
}

struct DependencyListView: View {
    let dependencies: [OperationalDependency]

    var body: some View {
        if dependencies.isEmpty {
            RetryableMessage(symbol: "checkmark.circle", title: "No open dependencies",
                             message: "No downstream owner is blocking this item right now.", tone: .success)
        } else {
            VStack(alignment: .leading, spacing: Z.s2) {
                ForEach(dependencies) { dependency in
                    HStack(alignment: .top, spacing: Z.s2) {
                        Image(systemName: dependencyIcon(dependency.displayType))
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(Z.status(status(for: dependency)))
                            .frame(width: 18, alignment: .leading)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(dependency.label ?? altitudeTitle(dependency.displayType))
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundStyle(Z.ink)
                            Text(dependencyMeta(dependency))
                                .font(.system(size: 12))
                                .foregroundStyle(Z.inkMuted)
                        }
                        Spacer()
                    }
                }
            }
        }
    }

    private func status(for dependency: OperationalDependency) -> CapacityStatus {
        switch dependency.status {
        case "blocked", "pending", "overdue": return .warning
        case "stat", "critical": return .critical
        case "resolved", "completed": return .success
        default: return .info
        }
    }

    private func dependencyMeta(_ dependency: OperationalDependency) -> String {
        var parts: [String] = []
        if let owner = dependency.ownerRole { parts.append("owner " + altitudeTitle(owner)) }
        if let status = dependency.status { parts.append(altitudeStatusLabel(status)) }
        return parts.isEmpty ? "Operational dependency" : parts.joined(separator: " · ")
    }

    private func dependencyIcon(_ raw: String) -> String {
        switch raw {
        case "bed_request": return "bed.double.fill"
        case "transport": return "figure.walk"
        case "evs": return "sparkles"
        case "staffing": return "person.3.fill"
        case "barrier": return "exclamationmark.octagon.fill"
        default: return "link"
        }
    }
}

struct ActivityListView: View {
    let events: [ActivityEvent]
    var limit: Int? = nil
    var allowPatientLinks = false

    private var visibleEvents: [ActivityEvent] {
        let capped = limit.map { Array(events.prefix($0)) }
        return capped ?? events
    }

    var body: some View {
        if visibleEvents.isEmpty {
            RetryableMessage(symbol: "tray", title: "No activity yet",
                             message: "Relevant relay events will appear here.", tone: .info)
        } else {
            VStack(alignment: .leading, spacing: Z.s2) {
                ForEach(visibleEvents) { event in
                    ActivityEventRow(event: event, allowPatientLinks: allowPatientLinks)
                }
            }
        }
    }
}

struct ActivityEventRow: View {
    let event: ActivityEvent
    var allowPatientLinks = false

    var body: some View {
        HStack(alignment: .top, spacing: Z.s3) {
            Circle()
                .fill(Z.status(event.severity))
                .frame(width: 9, height: 9)
                .padding(.top, 5)
            VStack(alignment: .leading, spacing: 3) {
                Text(altitudeTitle(event.eventType))
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundStyle(Z.ink)
                Text(metaLine)
                    .font(.system(size: 11))
                    .foregroundStyle(Z.inkMuted)
                if allowPatientLinks, let ref = event.patientContextRef {
                    NavigationLink {
                        PatientOperationalContextView(contextRef: ref)
                    } label: {
                        Label("Open operational patient context", systemImage: "person.text.rectangle")
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundStyle(Z.primary)
                    }
                    .buttonStyle(.plain)
                }
            }
            Spacer()
        }
        .padding(Z.s3)
        .background(RoundedRectangle(cornerRadius: 10, style: .continuous).fill(Z.bg))
        .overlay(RoundedRectangle(cornerRadius: 10, style: .continuous).strokeBorder(Z.border, lineWidth: 1))
    }

    private var metaLine: String {
        var parts: [String] = []
        if let domain = event.domain { parts.append(domain.uppercased()) }
        if let role = event.actorRole { parts.append(altitudeTitle(role)) }
        if let status = event.currentStatus { parts.append(altitudeStatusLabel(status)) }
        if let time = altitudeRelativeTime(event.occurredAt) { parts.append(time) }
        return parts.joined(separator: " · ")
    }
}

struct PatientContextLink: View {
    let contextRef: String
    var title = "Open operational patient context"

    var body: some View {
        NavigationLink {
            PatientOperationalContextView(contextRef: contextRef)
        } label: {
            HStack {
                Label(title, systemImage: "person.text.rectangle")
                    .font(.system(size: 14, weight: .semibold))
                    .foregroundStyle(Z.primary)
                Spacer()
                Text("Authorized context")
                    .font(.system(size: 11, weight: .medium))
                    .foregroundStyle(Z.inkMuted)
                    .lineLimit(1)
            }
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

struct EddyContextButton: View {
    let scopeRef: String

    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @State private var packet: EddyContextPacket?
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var showing = false

    private let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)

    var body: some View {
        Button {
            showing = true
            Task { await load() }
        } label: {
            HStack {
                Label("Eddy context", systemImage: "sparkles")
                    .font(.system(size: 14, weight: .semibold))
                    .foregroundStyle(Z.primary)
                Spacer()
                Image(systemName: "chevron.up.forward")
                    .font(.system(size: 11, weight: .semibold))
                    .foregroundStyle(Z.inkMuted)
            }
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
        }
        .buttonStyle(.plain)
        .sheet(isPresented: $showing) {
            EddyContextSheet(packet: packet, isLoading: isLoading, errorMessage: errorMessage)
        }
    }

    private func load() async {
        guard !isLoading else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            packet = try await api.eddyContext(scopeRef: scopeRef, persona: profile.roleId, bearer: auth.accessToken ?? "").data
            errorMessage = nil
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct EddyContextSheet: View {
    let packet: EddyContextPacket?
    let isLoading: Bool
    let errorMessage: String?

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    if isLoading {
                        ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                    } else if let errorMessage {
                        RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load Eddy context",
                                         message: errorMessage, tone: .warning)
                    } else if let packet {
                        content(packet)
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("Eddy Context")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }.tint(Z.primary)
                }
            }
        }
        .tint(Z.primary)
    }

    private func content(_ packet: EddyContextPacket) -> some View {
        VStack(alignment: .leading, spacing: Z.s4) {
            Panel {
                VStack(alignment: .leading, spacing: Z.s2) {
                    Text(packet.scopeType.map(altitudeTitle) ?? "Context packet")
                        .font(.system(size: 18, weight: .semibold))
                        .foregroundStyle(Z.ink)
                    Text(packet.scopeRef)
                        .font(.system(size: 11))
                        .foregroundStyle(Z.inkMuted)
                        .lineLimit(1)
                        .truncationMode(.middle)
                    if let generated = altitudeRelativeTime(packet.generatedAt) {
                        Text("generated \(generated)")
                            .font(.system(size: 11))
                            .foregroundStyle(Z.inkMuted)
                    }
                }
            }

            if let policy = packet.phiPolicy, !policy.isEmpty {
                sectionLabel("PHI AND GOVERNANCE")
                Panel(padding: Z.s3) {
                    VStack(alignment: .leading, spacing: Z.s2) {
                        ForEach(policy.keys.sorted(), id: \.self) { key in
                            HStack {
                                Text(altitudeTitle(key))
                                    .font(.system(size: 13))
                                    .foregroundStyle(Z.inkMuted)
                                Spacer()
                                Text(policy[key]?.displayString ?? "—")
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundStyle(Z.ink)
                            }
                        }
                    }
                }
            }

            if let questions = packet.questionsSupported, !questions.isEmpty {
                sectionLabel("SUPPORTED QUESTIONS")
                Panel(padding: Z.s3) {
                    VStack(alignment: .leading, spacing: Z.s2) {
                        ForEach(questions, id: \.self) { question in
                            Label(altitudeTitle(question), systemImage: "questionmark.circle")
                                .font(.system(size: 13))
                                .foregroundStyle(Z.ink)
                        }
                    }
                }
            }
        }
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold))
            .tracking(0.5)
            .foregroundStyle(Z.inkMuted)
            .padding(.top, Z.s2)
    }
}

func altitudeTitle(_ raw: String) -> String {
    raw.replacingOccurrences(of: "_", with: " ")
        .replacingOccurrences(of: ".", with: " ")
        .split(separator: " ")
        .map { $0.prefix(1).uppercased() + $0.dropFirst() }
        .joined(separator: " ")
}

func altitudeStatusLabel(_ raw: String) -> String {
    altitudeTitle(raw)
}

func altitudeRelativeTime(_ raw: String?) -> String? {
    guard let raw else { return nil }
    let date = ISO8601DateFormatter().date(from: raw) ?? ISO8601DateFormatter.flexible.date(from: raw)
    guard let date else { return nil }
    if abs(Date().timeIntervalSince(date)) < 60 { return "now" }
    let formatter = RelativeDateTimeFormatter()
    formatter.unitsStyle = .abbreviated
    return formatter.localizedString(for: date, relativeTo: Date())
}
