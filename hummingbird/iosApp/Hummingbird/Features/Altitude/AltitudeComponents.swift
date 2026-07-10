import SwiftUI

// The Altitude model (A0 glance → A1 workspace → A2 drill → A2P patient → A3 study) is
// the internal information-architecture discipline: it decides how much context each
// surface carries. It is deliberately INVISIBLE to end users — screens speak the
// worker's language (trips, turns, placements, "why this?"), never the model's
// coordinates. The A0–A3 vocabulary lives only in code, docs, and debug tooling.

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

/// Persistent top-left access to Eddy — the copilot is one tap away from any signed-in
/// screen (overlaid on the shell in RootView). Tapping opens a full chat with Eddy, who
/// can assess any part of hospital operations and assist whatever persona is signed in.
/// The circle shares Z.topAvatar with the profile circle so the two chrome circles match.
struct EddyAccessButton: View {
    @State private var showing = false

    var body: some View {
        Button { showing = true } label: {
            Image("Eddy")
                .resizable()
                .scaledToFill()
                .frame(width: Z.topAvatar, height: Z.topAvatar)
                .clipShape(Circle())
                .overlay(Circle().strokeBorder(Z.gold, lineWidth: 1.5))
                .shadow(color: .black.opacity(0.35), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .padding(.leading, Z.s3)
        .padding(.top, Z.s1)
        .accessibilityLabel("Ask Eddy")
        .accessibilityHint("Chat with Eddy about hospital operations")
        .sheet(isPresented: $showing) { EddyChatView() }
    }
}

/// Persistent top-right access to profile & settings — mirrors EddyAccessButton exactly
/// (same Z.topAvatar size and .top padding, at the opposite corner) so the two chrome
/// avatars align top-and-bottom and neither is clipped by a nav bar. Opens ProfileView.
struct ProfileAccessButton: View {
    @State private var showing = false

    var body: some View {
        Button { showing = true } label: {
            Image(systemName: "person.crop.circle.fill")
                .resizable()
                .scaledToFit()
                .frame(width: Z.topAvatar, height: Z.topAvatar)
                .foregroundStyle(Z.ink, Z.surface)
                .clipShape(Circle())
                .overlay(Circle().strokeBorder(Z.border, lineWidth: 1.5))
                .shadow(color: .black.opacity(0.35), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .padding(.trailing, Z.s3)
        .padding(.top, Z.s1)
        .accessibilityLabel("Profile and settings")
        .sheet(isPresented: $showing) { ProfileView() }
    }
}

/// Live screen context for Eddy — the currently-visible screen writes what the user is
/// looking at here (a stable screen key + human summary + a few key metrics + scope), so
/// every Eddy turn is grounded in *this* screen for *this* persona, never generic. Injected
/// app-wide in HummingbirdApp; screens set it with `.eddyContext(…)`; EddyChatView reads it.
final class EddyContextStore: ObservableObject {
    @Published var screenKey = "home"          // page_context (stable id, e.g. "house_capacity")
    @Published var screenTitle = "Hummingbird" // human label for the header + prompts
    @Published var summary: String?            // page_component (one-line state, e.g. "61% occupancy")
    @Published var data: [String: String] = [:] // page_data (key metrics on screen)
    @Published var scopeRef: String?           // house / floor:N / unit:N, when the screen is scoped

    func set(key: String, title: String, summary: String? = nil,
             data: [String: String] = [:], scopeRef: String? = nil) {
        screenKey = key
        screenTitle = title
        self.summary = summary
        self.data = data
        self.scopeRef = scopeRef
    }
}

private struct EddyContextSetter: ViewModifier {
    @EnvironmentObject private var eddyContext: EddyContextStore
    let key: String
    let title: String
    let summary: String?
    let data: [String: String]
    let scopeRef: String?

    func body(content: Content) -> some View {
        content.onAppear {
            eddyContext.set(key: key, title: title, summary: summary, data: data, scopeRef: scopeRef)
        }
    }
}

extension View {
    /// Register what this screen is showing so Eddy is context-aware here — grounds Eddy in
    /// the current screen (and, via the signed-in persona, the current lens). See EddyContextStore.
    func eddyContext(_ key: String, title: String, summary: String? = nil,
                     data: [String: String] = [:], scopeRef: String? = nil) -> some View {
        modifier(EddyContextSetter(key: key, title: title, summary: summary, data: data, scopeRef: scopeRef))
    }
}

/// One turn in the Eddy chat.
private struct EddyBubble: Identifiable, Equatable {
    enum Role { case user, assistant }
    let id = UUID()
    let role: Role
    var text: String
    var provider: String?
    var pending: Bool = false
}

/// A full conversational surface for Eddy — the copilot every persona can ask about any
/// part of operations (census, flow, staffing, transport, EVS). Persona-aware and grounded
/// server-side in live data + the RAG knowledge base (EddyChatService). Multi-turn via the
/// returned conversation id.
struct EddyChatView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @EnvironmentObject var eddyContext: EddyContextStore
    @Environment(\.dismiss) private var dismiss

    @State private var bubbles: [EddyBubble] = []
    @State private var input = ""
    @State private var conversationId: String?
    @State private var isSending = false
    @FocusState private var inputFocused: Bool

    private let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)

    private var personaTitle: String {
        Role.by(id: profile.roleId ?? "")?.title ?? "Operations"
    }

    /// Prompts tuned to the screen the user opened Eddy from — the first one is about
    /// exactly what's on screen, so Eddy is never generic.
    private var suggestions: [String] {
        var list = ["Explain what I'm seeing on \(eddyContext.screenTitle)"]
        if let summary = eddyContext.summary { list.append("Why is it \(summary)? What should I do?") }
        list.append(contentsOf: [
            "Where are the flow bottlenecks right now?",
            "What needs my attention this shift?",
        ])
        return list
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ScrollViewReader { proxy in
                    ScrollView {
                        VStack(alignment: .leading, spacing: Z.s3) {
                            if bubbles.isEmpty { intro }
                            ForEach(bubbles) { bubble in
                                bubbleRow(bubble).id(bubble.id)
                            }
                        }
                        .padding(Z.s4)
                    }
                    .onChange(of: bubbles.count) { _, _ in
                        guard let last = bubbles.last else { return }
                        withAnimation(.easeOut(duration: 0.25)) { proxy.scrollTo(last.id, anchor: .bottom) }
                    }
                }
                composer
            }
            .background { HummingbirdBackdrop(dim: 0.4) }
            .navigationTitle("Eddy")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .principal) {
                    HStack(spacing: 6) {
                        Image("Eddy").resizable().scaledToFill().frame(width: 26, height: 26).clipShape(Circle())
                        VStack(spacing: 0) {
                            Text("Eddy · \(personaTitle)").font(.system(size: 14, weight: .semibold)).foregroundStyle(Z.ink)
                            Text("viewing \(eddyContext.screenTitle)").font(.system(size: 10)).foregroundStyle(Z.inkMuted)
                        }
                    }
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }.tint(Z.primary)
                }
            }
        }
        .tint(Z.primary)
    }

    private var intro: some View {
        VStack(alignment: .leading, spacing: Z.s3) {
            HStack(alignment: .top, spacing: Z.s3) {
                Image("Eddy").resizable().scaledToFill().frame(width: Z.topAvatar, height: Z.topAvatar)
                    .clipShape(Circle()).overlay(Circle().strokeBorder(Z.gold, lineWidth: 1.5))
                VStack(alignment: .leading, spacing: 4) {
                    Text("Ask Eddy").font(.system(size: 18, weight: .semibold)).foregroundStyle(Z.ink)
                    Text("You're on **\(eddyContext.screenTitle)** as \(personaTitle)\(eddyContext.summary.map { " — \($0)" } ?? ""). Ask about what you see here, or assess any part of operations — census, flow, staffing, transport, EVS.")
                        .font(.system(size: 13)).foregroundStyle(Z.inkMuted).fixedSize(horizontal: false, vertical: true)
                }
            }
            Text("TRY").font(.system(size: 11, weight: .semibold)).tracking(0.5)
                .foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
            ForEach(suggestions, id: \.self) { suggestion in
                Button { send(suggestion) } label: {
                    HStack {
                        Text(suggestion).font(.system(size: 14)).foregroundStyle(Z.ink)
                            .multilineTextAlignment(.leading)
                        Spacer()
                        Image(systemName: "arrow.up.forward").font(.system(size: 11, weight: .semibold))
                            .foregroundStyle(Z.inkMuted)
                    }
                    .padding(Z.s3)
                    .background(RoundedRectangle(cornerRadius: 12).fill(Z.surface))
                    .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(Z.border, lineWidth: 1))
                }
                .buttonStyle(.plain)
            }
        }
    }

    @ViewBuilder
    private func bubbleRow(_ bubble: EddyBubble) -> some View {
        HStack {
            if bubble.role == .user { Spacer(minLength: Z.s6) }
            VStack(alignment: bubble.role == .user ? .trailing : .leading, spacing: 4) {
                if bubble.pending {
                    HStack(spacing: 6) {
                        ProgressView().controlSize(.small).tint(Z.inkMuted)
                        Text("Assessing…").font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                    }
                    .padding(.horizontal, Z.s3).padding(.vertical, Z.s2)
                    .background(RoundedRectangle(cornerRadius: 14).fill(Z.surface))
                    .overlay(RoundedRectangle(cornerRadius: 14).strokeBorder(Z.border, lineWidth: 1))
                } else {
                    Text(bubble.text)
                        .font(.system(size: 15))
                        .foregroundStyle(bubble.role == .user ? .white : Z.ink)
                        .textSelection(.enabled)
                        .padding(.horizontal, Z.s3).padding(.vertical, Z.s2)
                        .background(RoundedRectangle(cornerRadius: 14)
                            .fill(bubble.role == .user ? Z.primary : Z.surface))
                        .overlay {
                            if bubble.role == .assistant {
                                RoundedRectangle(cornerRadius: 14).strokeBorder(Z.border, lineWidth: 1)
                            }
                        }
                    if bubble.role == .assistant, let provider = bubble.provider {
                        Text("via \(provider)").font(.system(size: 10)).foregroundStyle(Z.inkMuted)
                    }
                }
            }
            if bubble.role == .assistant { Spacer(minLength: Z.s6) }
        }
    }

    private var composer: some View {
        VStack(spacing: 0) {
            Divider().overlay(Z.border)
            HStack(spacing: Z.s2) {
                TextField("Ask Eddy about operations…", text: $input, axis: .vertical)
                    .lineLimit(1...4)
                    .font(.system(size: 15))
                    .foregroundStyle(Z.ink)
                    .focused($inputFocused)
                    .padding(.horizontal, Z.s3).padding(.vertical, Z.s2)
                    .background(RoundedRectangle(cornerRadius: 12).fill(Z.surface))
                    .overlay(RoundedRectangle(cornerRadius: 12)
                        .strokeBorder(inputFocused ? Z.gold : Z.border, lineWidth: inputFocused ? 1.5 : 1))
                    .onSubmit { send(input) }
                Button { send(input) } label: {
                    Image(systemName: "arrow.up.circle.fill")
                        .font(.system(size: 30))
                        .foregroundStyle(canSend ? Z.primary : Z.inkMuted)
                }
                .disabled(!canSend)
                .accessibilityLabel("Send")
            }
            .padding(Z.s3)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
    }

    private var canSend: Bool {
        !input.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !isSending
    }

    private func send(_ text: String) {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty, !isSending else { return }
        input = ""
        inputFocused = false
        bubbles.append(EddyBubble(role: .user, text: trimmed))
        bubbles.append(EddyBubble(role: .assistant, text: "", pending: true))
        isSending = true
        // Ground this turn in the current screen + persona (never generic).
        var pageData = eddyContext.data
        pageData["screen"] = eddyContext.screenTitle
        pageData["persona"] = profile.roleId ?? personaTitle
        if let scopeRef = eddyContext.scopeRef { pageData["scope_ref"] = scopeRef }
        let pageContext = eddyContext.screenKey
        let pageComponent = eddyContext.summary ?? eddyContext.screenTitle
        Task {
            do {
                let reply = try await api.eddyChat(message: trimmed, conversationId: conversationId,
                                                   persona: profile.roleId,
                                                   pageContext: pageContext, pageComponent: pageComponent,
                                                   pageData: pageData, bearer: auth.accessToken ?? "").data
                conversationId = reply.conversationId ?? conversationId
                resolvePending(text: reply.message.content, provider: reply.message.provider)
            } catch let error as APIError {
                resolvePending(text: error.message, provider: nil)
            } catch {
                resolvePending(text: "Eddy is unavailable right now. Please try again shortly.", provider: nil)
            }
            isSending = false
        }
    }

    private func resolvePending(text: String, provider: String?) {
        guard let index = bubbles.lastIndex(where: { $0.pending }) else {
            bubbles.append(EddyBubble(role: .assistant, text: text, provider: provider))
            return
        }
        bubbles[index].text = text.isEmpty ? "…" : text
        bubbles[index].provider = provider
        bubbles[index].pending = false
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
    return OperationalDuration.age(since: date)
}
