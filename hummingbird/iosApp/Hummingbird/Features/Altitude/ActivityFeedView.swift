import SwiftUI

@MainActor
final class ActivityFeedViewModel: ObservableObject {
    @Published var events: [ActivityEvent] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var working: Set<String> = []

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(persona: String?, bearer: String) async {
        guard !bearer.isEmpty else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            events = try await api.activity(persona: persona, bearer: bearer).data
            errorMessage = nil
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func acknowledge(_ event: ActivityEvent, persona: String?, bearer: String) async {
        guard !bearer.isEmpty else { return }
        working.insert(event.id)
        defer { working.remove(event.id) }
        do {
            _ = try await api.acknowledgeActivity(eventUuid: event.eventUuid, persona: persona, bearer: bearer)
            await load(persona: persona, bearer: bearer)
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

struct ActivityFeedView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @StateObject private var vm = ActivityFeedViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var showProfile = false

    private let refreshInterval: Duration = .seconds(25)

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    header

                    if vm.events.isEmpty && vm.isLoading {
                        SkeletonRows()
                    } else if vm.events.isEmpty, let error = vm.errorMessage {
                        RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load activity",
                                         message: error, tone: .warning) {
                            Task { await vm.load(persona: profile.roleId, bearer: auth.accessToken ?? "") }
                        }
                    } else if vm.events.isEmpty {
                        RetryableMessage(symbol: "tray", title: "No team activity yet",
                                         message: "Updates relevant to your role appear here as the team acts.", tone: .info)
                    } else {
                        ForEach(vm.events) { event in
                            eventCard(event)
                        }
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("Activity")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showProfile = true } label: {
                        Image(systemName: "person.crop.circle").foregroundStyle(Z.ink)
                    }
                    .accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .refreshable { await vm.load(persona: profile.roleId, bearer: auth.accessToken ?? "") }
            .task(id: profile.roleId ?? "") {
                let token = auth.accessToken ?? ""
                while !Task.isCancelled {
                    await vm.load(persona: profile.roleId, bearer: token)
                    try? await Task.sleep(for: refreshInterval)
                }
            }
        }
        .tint(Z.primary)
    }

    private var header: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                Text("Team activity")
                    .font(.system(size: 20, weight: .semibold))
                    .foregroundStyle(Z.ink)
                Text("Patient identifiers stay out of the feed. Rows carry a context token only when detail entry is authorized.")
                    .font(.system(size: 12))
                    .foregroundStyle(Z.inkMuted)
            }
        }
    }

    private func eventCard(_ event: ActivityEvent) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            ActivityEventRow(event: event, allowPatientLinks: true)
            HStack(spacing: Z.s2) {
                if let roles = relayRoles(event), !roles.isEmpty {
                    Label(roles.map(altitudeTitle).joined(separator: ", "), systemImage: "dot.radiowaves.left.and.right")
                        .font(.system(size: 11))
                        .foregroundStyle(Z.inkMuted)
                        .lineLimit(1)
                }
                Spacer()
                Button {
                    Task { await vm.acknowledge(event, persona: profile.roleId, bearer: auth.accessToken ?? "") }
                } label: {
                    HStack(spacing: 4) {
                        if vm.working.contains(event.id) {
                            ProgressView().controlSize(.small).tint(Z.primary)
                        } else {
                            Image(systemName: "checkmark.circle")
                        }
                        Text("Acknowledge")
                    }
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(Z.primary)
                    .padding(.horizontal, Z.s2)
                    .padding(.vertical, Z.s1)
                    .background(Capsule().strokeBorder(Z.primary.opacity(0.5), lineWidth: 1))
                }
                .buttonStyle(.plain)
                .disabled(vm.working.contains(event.id))
            }
            .padding(.horizontal, Z.s2)
        }
    }

    private func relayRoles(_ event: ActivityEvent) -> [String]? {
        guard let value = event.relay?["affected_roles"], case let .array(values) = value else { return nil }
        return values.compactMap { $0.stringValue }
    }
}
