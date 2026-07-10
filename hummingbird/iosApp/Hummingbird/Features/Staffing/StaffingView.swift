import SwiftUI

/// P10 — the staffing coordinator's home: coverage metrics, units below minimum-safe, and the
/// open-request queue with an inline Fill (assign from the float pool). Backed by
/// GET /staffing/overview; fill posts to /staffing/requests/{id}/fill.
@MainActor
final class StaffingViewModel: ObservableObject {
    @Published var overview: StaffingOverview?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var working: Set<Int> = []
    @Published var needsReauth = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        isLoading = true
        defer { isLoading = false }
        do {
            overview = try await api.staffingOverview(bearer: bearer).data
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch { errorMessage = error.localizedDescription }
    }

    func fill(_ r: StaffingReq, bearer: String) async {
        working.insert(r.id)
        defer { working.remove(r.id) }
        do { try await api.staffingFill(id: r.id, source: "Float Pool", bearer: bearer); await load(bearer: bearer) }
        catch let e as APIError { errorMessage = e.message }
        catch { errorMessage = error.localizedDescription }
    }
}

struct StaffingView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm = StaffingViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var showProfile = false
    @State private var viewMode: FlowHomeMode = .list

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window — "coverage vs the curve" (P10): predicted census
                    // against staffing-gap steps at shift boundaries; floors tinted by gap.
                    FlowMapView(persona: "staffing_coordinator", scope: .house)
                } else {
                    listBody
                }
            }
            .background { HummingbirdBackdrop(dim: 0.4) }
            .navigationTitle("Staffing")
            .eddyContext("staffing", title: "Staffing", summary: "coverage vs census", scopeRef: "house")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .principal) {
                    Picker("View", selection: $viewMode) {
                        Text("List").tag(FlowHomeMode.list)
                        Text("Map").tag(FlowHomeMode.map)
                    }
                    .pickerStyle(.segmented)
                    .frame(width: 160)
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showProfile = true } label: { EmptyView() }.accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .onChange(of: vm.needsReauth) { _, n in if n { Task { await auth.logout() } } }
        }
        .tint(Z.primary)
    }

    private var listBody: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                AltitudeContextCard(domain: "staffing")
                if vm.overview == nil && vm.isLoading {
                    SkeletonRows()
                } else if vm.overview == nil, let e = vm.errorMessage {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load staffing",
                                     message: e, tone: .warning) { Task { await vm.load(bearer: auth.accessToken ?? "") } }
                } else if let o = vm.overview {
                    metrics(o.metrics)
                    if !o.unitsAtRisk.isEmpty {
                        sectionLabel("BELOW MINIMUM-SAFE (\(o.unitsAtRisk.count))")
                        ForEach(o.unitsAtRisk) { unitRow($0) }
                    }
                    if !o.queue.isEmpty {
                        sectionLabel("OPEN REQUESTS (\(o.queue.count))")
                        ForEach(o.queue) { requestRow($0) }
                    }
                }
            }
            .padding(Z.s4)
        }
        .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
        .task {
            let token = auth.accessToken ?? ""
            while !Task.isCancelled { await vm.load(bearer: token); try? await Task.sleep(for: .seconds(20)) }
        }
    }

    private func metrics(_ m: StaffingMetrics) -> some View {
        Panel {
            HStack(spacing: 0) {
                cell("\(m.coveragePct)%", "Coverage", tone: m.coveragePct < 95 ? .warning : .success)
                bar
                cell("\(m.criticalGaps)", "Critical", tone: m.criticalGaps > 0 ? .critical : nil)
                bar
                cell("\(m.openRequests)", "Requests", tone: m.openRequests > 0 ? .warning : nil)
                bar
                cell("\(m.totalGapHeadcount)", "Gap FTE", tone: m.totalGapHeadcount > 0 ? .warning : nil)
            }
        }
    }

    private func cell(_ v: String, _ l: String, tone: CapacityStatus?) -> some View {
        VStack(spacing: 2) {
            Text(v).font(.system(size: 24, weight: .semibold)).monospacedDigit().foregroundStyle(tone.map { Z.status($0) } ?? Z.ink)
            Text(l).font(.system(size: 11)).foregroundStyle(Z.inkMuted)
        }.frame(maxWidth: .infinity)
    }

    private var bar: some View { Rectangle().fill(Z.border).frame(width: 1, height: 26) }

    private func unitRow(_ u: UnitAtRisk) -> some View {
        Panel(padding: Z.s3) {
            HStack(spacing: 0) {
                Rectangle().fill(Z.status(u.capacity)).frame(width: 4).cornerRadius(2)
                VStack(alignment: .leading, spacing: 2) {
                    Text(u.unitLabel).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink).lineLimit(1)
                    Text("\(u.worstRoleLabel) · short \(u.gapHeadcount)").monospacedDigit().font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                }.padding(.leading, Z.s3)
                Spacer()
                StatusChip(status: u.capacity)
            }
        }
    }

    private func requestRow(_ r: StaffingReq) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(spacing: Z.s2) {
                    priorityChip(r)
                    Spacer()
                    Text(r.sla.label).font(.system(size: 12, weight: .medium)).monospacedDigit()
                        .foregroundStyle(r.sla.atRisk ? Z.status(.critical) : Z.inkMuted)
                }
                Text("\(r.roleLabel ?? "Staff") · \(r.unitLabel ?? "—")").font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                NavigationLink {
                    DrillDetailView(itemUuid: "staffing-\(r.id)")
                } label: {
                    Label("Why this gap?", systemImage: "info.circle")
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundStyle(Z.primary)
                }
                .buttonStyle(.plain)
                Button {
                    Task { await vm.fill(r, bearer: auth.accessToken ?? "") }
                } label: {
                    HStack(spacing: Z.s2) {
                        if vm.working.contains(r.id) { ProgressView().controlSize(.small).tint(.white) }
                        Text("Fill from float pool").font(.system(size: 15, weight: .semibold))
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, Z.s2)
                    .foregroundStyle(.white)
                    .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                }
                .disabled(vm.working.contains(r.id))
            }
        }
    }

    private func priorityChip(_ r: StaffingReq) -> some View {
        HStack(spacing: Z.s1) {
            Image(systemName: r.capacity.symbol).font(.system(size: 11, weight: .semibold))
            Text(r.priority.uppercased()).font(.system(size: 11, weight: .semibold)).tracking(0.4)
        }
        .foregroundStyle(Z.status(r.capacity))
        .padding(.horizontal, Z.s2).padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(r.capacity).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(r.capacity).opacity(0.35), lineWidth: 1))
    }

    private func sectionLabel(_ t: String) -> some View {
        Text(t).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
    }
}
