import SwiftUI

@MainActor
final class AltitudeContextViewModel: ObservableObject {
    @Published var home: MobileAltitudeHome?
    @Published var workspace: MobileAltitudeWorkspace?
    @Published var isLoading = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(domain: String, persona: String?, bearer: String) async {
        guard !bearer.isEmpty else { return }
        isLoading = true
        defer { isLoading = false }

        if let homeEnvelope = try? await api.altitudeHome(persona: persona, bearer: bearer) {
            home = homeEnvelope.data
        }
        if let workspaceEnvelope = try? await api.altitudeWorkspace(domain: domain, persona: persona, bearer: bearer) {
            workspace = workspaceEnvelope.data
        }
    }
}

/// Compact A0/A1 context that can sit above existing persona homes without replacing their
/// purpose-built queue or board UI.
struct AltitudeContextCard: View {
    let domain: String

    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @StateObject private var vm = AltitudeContextViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))

    var body: some View {
        if vm.home != nil || vm.workspace != nil {
            Panel {
                VStack(alignment: .leading, spacing: Z.s3) {
                    // The worker's question leads; the model's coordinates stay backstage.
                    HStack(alignment: .top, spacing: Z.s2) {
                        if let question = vm.home?.glanceQuestion {
                            Text(question)
                                .font(.system(size: 15, weight: .semibold))
                                .foregroundStyle(Z.ink)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        Spacer(minLength: Z.s2)
                        if let status = vm.home?.status {
                            StatusChip(status: status.capacity)
                        }
                    }

                    if let summary = vm.workspace?.workspace?.summary {
                        HStack(spacing: Z.s2) {
                            Image(systemName: "rectangle.stack.fill")
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundStyle(Z.primary)
                            Text(workspaceLine(summary))
                                .font(.system(size: 13))
                                .foregroundStyle(Z.inkMuted)
                            Spacer()
                        }
                    }

                    if let tiles = vm.home?.tiles, !tiles.isEmpty {
                        HStack(spacing: Z.s2) {
                            ForEach(tiles.prefix(3)) { tile in
                                tileChip(tile)
                            }
                        }
                    }

                    if let event = vm.workspace?.activity?.first ?? vm.home?.activity?.first {
                        Divider().overlay(Z.border)
                        ActivityEventRow(event: event)
                    }
                }
            }
            .task(id: "\(domain)|\(profile.roleId ?? "")|\(auth.accessToken ?? "")") {
                await vm.load(domain: domain, persona: profile.roleId, bearer: auth.accessToken ?? "")
            }
        } else {
            EmptyView()
                .task(id: "\(domain)|\(profile.roleId ?? "")|\(auth.accessToken ?? "")") {
                    await vm.load(domain: domain, persona: profile.roleId, bearer: auth.accessToken ?? "")
                }
        }
    }

    private func workspaceLine(_ summary: WorkspaceSummary) -> String {
        let label = summary.label ?? altitudeTitle(domain)
        if let count = summary.count {
            return "\(label) · \(count) active"
        }
        return label
    }

    private func tileChip(_ tile: AltitudeTile) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(tile.label.uppercased())
                .font(.system(size: 9, weight: .semibold))
                .tracking(0.4)
                .foregroundStyle(Z.inkMuted)
                .lineLimit(1)
            Text(tile.value)
                .font(.system(size: 16, weight: .semibold))
                .monospacedDigit()
                .foregroundStyle(Z.status(tile.capacity))
        }
        .padding(.horizontal, Z.s2)
        .padding(.vertical, Z.s2)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 10).fill(Z.bg))
        .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
    }
}
