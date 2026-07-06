import SwiftUI

@MainActor
final class DrillDetailViewModel: ObservableObject {
    @Published var drill: MobileAltitudeDrill?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var actionMessage: String?
    @Published var workingAction: String?

    let itemUuid: String
    let api: APIClient

    init(itemUuid: String, api: APIClient) {
        self.itemUuid = itemUuid
        self.api = api
    }

    func load(persona: String?, bearer: String) async {
        guard !bearer.isEmpty else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            drill = try await api.altitudeDrill(itemUuid: itemUuid, persona: persona, bearer: bearer).data
            errorMessage = nil
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func perform(_ action: MobileAction, persona: String?, bearer: String) async {
        guard !bearer.isEmpty else { return }
        workingAction = action.id
        defer { workingAction = nil }
        do {
            if action.kind == "acknowledge" {
                _ = try await api.acknowledgeActivity(eventUuid: itemUuid, persona: persona, bearer: bearer)
            } else if action.kind == "resolve", let endpoint = action.endpoint {
                try await api.performMobileAction(endpoint: endpoint, bearer: bearer)
            } else {
                actionMessage = "Use the native lifecycle controls on the related workspace."
                return
            }
            actionMessage = "\(action.label ?? altitudeTitle(action.kind)) complete."
            await load(persona: persona, bearer: bearer)
        } catch let error as APIError {
            actionMessage = error.message
        } catch {
            actionMessage = error.localizedDescription
        }
    }
}

struct DrillDetailView: View {
    let itemUuid: String

    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @Environment(\.openURL) private var openURL
    @StateObject private var vm: DrillDetailViewModel

    init(itemUuid: String) {
        self.itemUuid = itemUuid
        _vm = StateObject(wrappedValue: DrillDetailViewModel(
            itemUuid: itemUuid,
            api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)
        ))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                if vm.drill == nil && vm.isLoading {
                    ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                } else if vm.drill == nil, let error = vm.errorMessage {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load drill",
                                     message: error, tone: .warning) {
                        Task { await vm.load(persona: profile.roleId, bearer: auth.accessToken ?? "") }
                    }
                } else if let drill = vm.drill {
                    content(drill)
                }
            }
            .padding(Z.s4)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
        .navigationTitle("Details")
        .navigationBarTitleDisplayMode(.inline)
        .task(id: "\(itemUuid)|\(profile.roleId ?? "")") {
            await vm.load(persona: profile.roleId, bearer: auth.accessToken ?? "")
        }
        .tint(Z.primary)
    }

    private func content(_ drill: MobileAltitudeDrill) -> some View {
        VStack(alignment: .leading, spacing: Z.s4) {
            Panel {
                VStack(alignment: .leading, spacing: Z.s3) {
                    HStack(alignment: .top) {
                        Spacer()
                        if let status = drill.status {
                            StatusChip(status: status.capacity)
                        }
                    }

                    Text(drill.explanation ?? "Operational context is available for this item.")
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(Z.ink)

                    Text(metaLine(drill))
                        .font(.system(size: 12))
                        .foregroundStyle(Z.inkMuted)
                }
            }

            if let ref = drill.patientContextRef {
                PatientContextLink(contextRef: ref)
            }

            sectionLabel("WHY IT MATTERS")
            Panel { DependencyListView(dependencies: drill.dependencies ?? []) }

            if let actions = drill.actions, !actions.isEmpty {
                sectionLabel("ACTIONS")
                actionsCard(actions)
            }

            if let message = vm.actionMessage {
                Text(message)
                    .font(.system(size: 12))
                    .foregroundStyle(Z.inkMuted)
                    .padding(.horizontal, Z.s1)
            }

            sectionLabel("ACTIVITY AND RELAY")
            Panel { ActivityListView(events: drill.activity ?? [], allowPatientLinks: true) }

            if let href = drill.web?.href, let url = URL(string: href) {
                Button { openURL(url) } label: {
                    HStack {
                        Label(drill.web?.label ?? "Open in Zephyrus", systemImage: "arrow.up.forward.square")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(Z.primary)
                        Spacer()
                    }
                    .padding(Z.s3)
                    .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
                }
                .buttonStyle(.plain)
            }
        }
    }

    private func actionsCard(_ actions: [MobileAction]) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                ForEach(actions) { action in
                    if canPerform(action) {
                        Button {
                            Task { await vm.perform(action, persona: profile.roleId, bearer: auth.accessToken ?? "") }
                        } label: {
                            HStack(spacing: Z.s2) {
                                if vm.workingAction == action.id {
                                    ProgressView().controlSize(.small).tint(.white)
                                }
                                Text(action.label ?? altitudeTitle(action.kind))
                                    .font(.system(size: 15, weight: .semibold))
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, Z.s2)
                            .foregroundStyle(.white)
                            .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                        }
                        .disabled(vm.workingAction != nil)
                    } else {
                        HStack(spacing: Z.s2) {
                            Image(systemName: "arrow.right.circle")
                                .foregroundStyle(Z.primary)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(action.label ?? altitudeTitle(action.kind))
                                    .font(.system(size: 14, weight: .semibold))
                                    .foregroundStyle(Z.ink)
                                Text("Handled in the related workspace controls")
                                    .font(.system(size: 11))
                                    .foregroundStyle(Z.inkMuted)
                            }
                            Spacer()
                        }
                        .padding(.vertical, Z.s1)
                    }
                }
            }
        }
    }

    private func canPerform(_ action: MobileAction) -> Bool {
        action.kind == "acknowledge" || (action.kind == "resolve" && action.endpoint != nil)
    }

    private func metaLine(_ drill: MobileAltitudeDrill) -> String {
        var parts = [drill.domain?.uppercased(), drill.generatedAt.flatMap(altitudeRelativeTime)]
            .compactMap { $0 }
        if let role = drill.persona?.title { parts.insert(role, at: 0) }
        return parts.isEmpty ? itemUuid : parts.joined(separator: " · ")
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold))
            .tracking(0.5)
            .foregroundStyle(Z.inkMuted)
            .padding(.top, Z.s2)
    }
}
