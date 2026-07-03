import SwiftUI

/// P8 — the PI / quality lead's home: active PDSA cycles (stage + owner) and the improvement
/// opportunity portfolio ranked by impact. Read-mostly on mobile; deep work is on the web.
/// Backed by GET /improvement/pdsa + /improvement/opportunities.
@MainActor
final class ImprovementViewModel: ObservableObject {
    @Published var cycles: [PdsaCycle] = []
    @Published var opportunities: [Opportunity] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var needsReauth = false
    @Published var loaded = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        isLoading = true
        defer { isLoading = false }
        do {
            cycles = try await api.improvementPdsa(bearer: bearer)
            opportunities = try await api.improvementOpportunities(bearer: bearer)
            errorMessage = nil
            loaded = true
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch { errorMessage = error.localizedDescription }
    }

    var activeCycles: [PdsaCycle] { cycles.filter { $0.status == "active" } }
}

struct ImprovementView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm = ImprovementViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var showProfile = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    AltitudeContextCard(domain: "ops")
                    if !vm.loaded && vm.isLoading {
                        ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                    } else if !vm.loaded, let e = vm.errorMessage {
                        RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load improvement",
                                         message: e, tone: .warning) { Task { await vm.load(bearer: auth.accessToken ?? "") } }
                    } else {
                        summary
                        if !vm.activeCycles.isEmpty {
                            sectionLabel("ACTIVE PDSA CYCLES (\(vm.activeCycles.count))")
                            ForEach(vm.activeCycles) { cycleRow($0) }
                        }
                        if !vm.opportunities.isEmpty {
                            sectionLabel("OPPORTUNITIES (by impact)")
                            ForEach(vm.opportunities) { oppRow($0) }
                        }
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("Improvement")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .topBarTrailing) {
                Button { showProfile = true } label: { Image(systemName: "person.crop.circle").foregroundStyle(Z.ink) }
            } }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
            .task {
                let token = auth.accessToken ?? ""
                while !Task.isCancelled { await vm.load(bearer: token); try? await Task.sleep(for: .seconds(30)) }
            }
            .onChange(of: vm.needsReauth) { _, n in if n { Task { await auth.logout() } } }
        }
        .tint(Z.primary)
    }

    private var summary: some View {
        Panel {
            HStack(spacing: 0) {
                cell("\(vm.activeCycles.count)", "Active cycles")
                Rectangle().fill(Z.border).frame(width: 1, height: 26)
                cell("\(vm.opportunities.count)", "Opportunities")
            }
        }
    }

    private func cell(_ v: String, _ l: String) -> some View {
        VStack(spacing: 2) {
            Text(v).font(.system(size: 24, weight: .semibold)).monospacedDigit().foregroundStyle(Z.ink)
            Text(l).font(.system(size: 11)).foregroundStyle(Z.inkMuted)
        }.frame(maxWidth: .infinity)
    }

    private func cycleRow(_ c: PdsaCycle) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: Z.s2) {
                    Text(c.status.uppercased()).font(.system(size: 10, weight: .semibold)).tracking(0.4)
                        .foregroundStyle(Z.status(.info))
                        .padding(.horizontal, Z.s2).padding(.vertical, 2)
                        .background(Capsule().fill(Z.status(.info).opacity(0.15)))
                    Spacer()
                    if let u = c.unit { Text(u).font(.system(size: 11)).foregroundStyle(Z.inkMuted).lineLimit(1) }
                }
                Text(c.title).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                if let o = c.objective { Text(o).font(.system(size: 12)).foregroundStyle(Z.inkMuted).lineLimit(2) }
            }
        }
    }

    private func oppRow(_ o: Opportunity) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(spacing: Z.s3) {
                    VStack(spacing: 1) {
                        Text("\(o.impact ?? 0)").font(.system(size: 22, weight: .semibold)).monospacedDigit().foregroundStyle(Z.status(o.priorityTier))
                        Text("impact").font(.system(size: 9)).foregroundStyle(Z.inkMuted)
                    }
                    .frame(width: 44)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(o.title).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                        Text("\(o.department ?? "—") · \(o.priority) · \(o.status)").font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                    }
                    Spacer()
                }
                NavigationLink {
                    DrillDetailView(itemUuid: "improvement-\(o.id)")
                } label: {
                    Label("Explain improvement signal", systemImage: "info.circle")
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundStyle(Z.primary)
                }
                .buttonStyle(.plain)
            }
        }
    }

    private func sectionLabel(_ t: String) -> some View {
        Text(t).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
    }
}
