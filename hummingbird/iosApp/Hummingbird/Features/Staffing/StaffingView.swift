import SwiftUI

/// P10 — the staffing coordinator's home: coverage metrics, units below minimum-safe, and the
/// open-request queue with governed person-level fulfillment. Backed by
/// GET /staffing/overview and the canonical candidate/fill endpoints.
@MainActor
final class StaffingViewModel: ObservableObject {
    @Published var overview: StaffingOverview?
    @Published var isLoading = false
    @Published var errorMessage: String?
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
}

@MainActor
final class StaffingFulfillmentViewModel: ObservableObject {
    @Published var candidates: [StaffingCandidate] = []
    @Published var selectedStaffMemberId: Int?
    @Published var source = "float_pool"
    @Published var isLoading = false
    @Published var isWorking = false
    @Published var errorMessage: String?
    @Published var needsReauth = false

    private let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(request: StaffingReq, bearer: String) async {
        isLoading = true
        defer { isLoading = false }
        do {
            candidates = try await api.staffingCandidates(id: request.id, bearer: bearer).data
            selectedStaffMemberId = candidates.first(where: \.eligible)?.staffMemberId
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func fill(request: StaffingReq, bearer: String) async -> Bool {
        guard let selectedStaffMemberId else {
            errorMessage = "Select a qualified, available staff member."
            return false
        }
        isWorking = true
        defer { isWorking = false }
        do {
            try await api.staffingFill(
                id: request.id,
                staffMemberId: selectedStaffMemberId,
                source: source,
                bearer: bearer
            )
            errorMessage = nil
            return true
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
        return false
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
                NavigationLink {
                    StaffingFulfillmentView(request: r) {
                        Task { await vm.load(bearer: auth.accessToken ?? "") }
                    }
                } label: {
                    HStack(spacing: Z.s2) {
                        Image(systemName: "person.badge.plus")
                        Text("Choose qualified staff").font(.system(size: 15, weight: .semibold))
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, Z.s2)
                    .foregroundStyle(.white)
                    .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                }
                .buttonStyle(.plain)
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

private struct StaffingFulfillmentView: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    @StateObject private var vm = StaffingFulfillmentViewModel(
        api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)
    )

    let request: StaffingReq
    let onFilled: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                Panel {
                    VStack(alignment: .leading, spacing: Z.s2) {
                        Text("\(request.roleLabel ?? "Staff") · \(request.unitLabel ?? "—")")
                            .font(.system(size: 18, weight: .semibold))
                            .foregroundStyle(Z.ink)
                        Text("Choose a person who passes role, qualification, availability, and overlap checks.")
                            .font(.system(size: 13))
                            .foregroundStyle(Z.inkMuted)
                    }
                }

                if vm.isLoading {
                    SkeletonRows()
                } else if let error = vm.errorMessage {
                    RetryableMessage(symbol: "exclamationmark.shield", title: "Staffing action unavailable",
                                     message: error, tone: .warning) {
                        Task { await vm.load(request: request, bearer: auth.accessToken ?? "") }
                    }
                }

                if !vm.candidates.isEmpty {
                    let blocked = vm.candidates.filter { !$0.eligible }
                    if !blocked.isEmpty {
                        Text("\(blocked.count) candidate\(blocked.count == 1 ? " is" : "s are") blocked by current safety checks.")
                            .font(.system(size: 12))
                            .foregroundStyle(Z.inkMuted)
                    }
                    ForEach(vm.candidates) { candidate in
                        Button {
                            if candidate.eligible { vm.selectedStaffMemberId = candidate.staffMemberId }
                        } label: {
                            HStack(spacing: Z.s3) {
                                Image(systemName: vm.selectedStaffMemberId == candidate.staffMemberId ? "checkmark.circle.fill" : "circle")
                                    .foregroundStyle(candidate.eligible ? Z.primary : Z.inkMuted)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(candidate.displayName).font(.system(size: 15, weight: .semibold))
                                    Text(candidate.eligible
                                         ? candidate.roleLabel
                                         : candidate.reasonCodes.map(humanize).joined(separator: " · "))
                                        .font(.system(size: 12))
                                        .foregroundStyle(Z.inkMuted)
                                }
                                Spacer()
                                if !candidate.eligible {
                                    Text(candidate.eligibilityState).font(.system(size: 11, weight: .semibold))
                                        .foregroundStyle(Z.status(.warning))
                                }
                            }
                            .padding(Z.s3)
                            .background(RoundedRectangle(cornerRadius: 12).fill(Z.surface))
                            .overlay(RoundedRectangle(cornerRadius: 12).stroke(candidate.eligible ? Z.border : Z.status(.warning).opacity(0.4)))
                        }
                        .buttonStyle(.plain)
                        .disabled(!candidate.eligible)
                    }

                    Picker("Source", selection: $vm.source) {
                        Text("Float pool").tag("float_pool")
                        Text("Overtime").tag("overtime")
                        Text("Agency").tag("agency")
                        Text("On call").tag("on_call")
                    }
                    .pickerStyle(.menu)

                    Button {
                        Task {
                            if await vm.fill(request: request, bearer: auth.accessToken ?? "") {
                                onFilled()
                                dismiss()
                            }
                        }
                    } label: {
                        HStack {
                            if vm.isWorking { ProgressView().controlSize(.small).tint(.white) }
                            Text(vm.isWorking ? "Filling shift" : "Fill with selected staff")
                                .font(.system(size: 15, weight: .semibold))
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, Z.s3)
                        .foregroundStyle(.white)
                        .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                    }
                    .disabled(vm.isWorking || vm.selectedStaffMemberId == nil)
                } else if !vm.isLoading && vm.errorMessage == nil {
                    RetryableMessage(symbol: "person.crop.circle.badge.xmark", title: "No eligible staff",
                                     message: "No candidate currently passes qualification, availability, unit, and overlap checks.", tone: .warning) {
                        Task { await vm.load(request: request, bearer: auth.accessToken ?? "") }
                    }
                }
            }
            .padding(Z.s4)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
        .navigationTitle("Fulfill shift")
        .navigationBarTitleDisplayMode(.inline)
        .task { await vm.load(request: request, bearer: auth.accessToken ?? "") }
        .onChange(of: vm.needsReauth) { _, needsReauth in
            if needsReauth { Task { await auth.logout() } }
        }
    }

    private func humanize(_ value: String) -> String {
        value.replacingOccurrences(of: "_", with: " ")
    }
}
