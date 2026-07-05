import SwiftUI

/// P6 — the capacity lead's home: the house strain header + the operational **approvals inbox**
/// (cross-domain actions awaiting a human decision), each with an inline Approve. Backed by
/// GET /command/house + /ops/inbox; the decision posts to /ops/approvals/{uuid}/decision.
@MainActor
final class CapacityDemandViewModel: ObservableObject {
    @Published var strain: ExecStrain?
    @Published var approvals: [OpsApproval] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var working: Set<String> = []
    @Published var needsReauth = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        isLoading = true
        defer { isLoading = false }
        if let env = try? await api.commandHouse(bearer: bearer) { strain = env.data.strain }
        do {
            approvals = try await api.opsInbox(bearer: bearer).data
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch { errorMessage = error.localizedDescription }
    }

    func decide(_ a: OpsApproval, decision: String, bearer: String) async {
        working.insert(a.id)
        defer { working.remove(a.id) }
        do { try await api.opsDecide(uuid: a.approvalUuid, decision: decision, bearer: bearer); await load(bearer: bearer) }
        catch let e as APIError { errorMessage = e.message }
        catch { errorMessage = error.localizedDescription }
    }

    func approve(_ a: OpsApproval, bearer: String) async {
        await decide(a, decision: "approved", bearer: bearer)
    }

    func reject(_ a: OpsApproval, bearer: String) async {
        await decide(a, decision: "rejected", bearer: bearer)
    }
}

struct CapacityDemandView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm = CapacityDemandViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var showProfile = false
    @State private var viewMode: FlowHomeMode = .list

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window — "strain over time" (P6): curve-first (occupancy vs
                    // staffed with the forecast band), the house map one fold below.
                    FlowMapView(persona: "capacity_lead", scope: .house)
                } else {
                    listBody
                }
            }
            .background(Z.bg)
            .navigationTitle("Capacity & Demand")
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
                    Button { showProfile = true } label: { Image(systemName: "person.crop.circle").foregroundStyle(Z.ink) }.accessibilityLabel("Profile and settings")
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
                AltitudeContextCard(domain: "ops")
                if let s = vm.strain { strainHeader(s) }
                if vm.approvals.isEmpty && vm.isLoading {
                    SkeletonRows()
                } else if vm.approvals.isEmpty && vm.errorMessage != nil {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load approvals",
                                     message: vm.errorMessage ?? "", tone: .warning) { Task { await vm.load(bearer: auth.accessToken ?? "") } }
                } else if vm.approvals.isEmpty {
                    RetryableMessage(symbol: "checkmark.circle", title: "Inbox clear",
                                     message: "No operational actions awaiting your approval.", tone: .success)
                } else {
                    sectionLabel("APPROVALS (\(vm.approvals.count))")
                    ForEach(vm.approvals) { approvalRow($0) }
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

    private func strainHeader(_ s: ExecStrain) -> some View {
        Panel {
            HStack(spacing: Z.s3) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("HOUSE STRAIN").font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted)
                    Text("\(s.label) · \(s.level)/4").monospacedDigit().font(.system(size: 17, weight: .semibold)).foregroundStyle(Z.ink)
                }
                Spacer()
                StatusChip(status: s.capacity)
            }
        }
    }

    private func approvalRow(_ a: OpsApproval) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(spacing: Z.s2) {
                    riskChip(a)
                    Spacer()
                    if let owner = a.owner { Text(owner).font(.system(size: 11)).foregroundStyle(Z.inkMuted) }
                }
                Text(a.title).font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                if let r = a.rationale { Text(r).font(.system(size: 12)).foregroundStyle(Z.inkMuted).lineLimit(2) }
                NavigationLink {
                    DrillDetailView(itemUuid: "ops-approval-\(a.approvalUuid)")
                } label: {
                    Label("Why this approval?", systemImage: "info.circle")
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundStyle(Z.primary)
                }
                .buttonStyle(.plain)
                EddyContextButton(scopeRef: a.approvalUuid)
                HStack(spacing: Z.s2) {
                    Button {
                        Task { await vm.reject(a, bearer: auth.accessToken ?? "") }
                    } label: {
                        Text("Reject").font(.system(size: 15, weight: .semibold))
                            .frame(maxWidth: .infinity).padding(.vertical, Z.s2)
                            .foregroundStyle(Z.status(.warning))
                            .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.status(.warning).opacity(0.6), lineWidth: 1))
                    }
                    .disabled(vm.working.contains(a.id))

                    Button {
                        Task { await vm.approve(a, bearer: auth.accessToken ?? "") }
                    } label: {
                        HStack(spacing: Z.s2) {
                            if vm.working.contains(a.id) { ProgressView().controlSize(.small).tint(.white) }
                            Text("Approve").font(.system(size: 15, weight: .semibold))
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, Z.s2)
                        .foregroundStyle(.white)
                        .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                    }
                    .disabled(vm.working.contains(a.id))
                }
                .padding(.top, 2)
            }
        }
    }

    private func riskChip(_ a: OpsApproval) -> some View {
        HStack(spacing: Z.s1) {
            Image(systemName: a.capacity.symbol).font(.system(size: 11, weight: .semibold))
            Text((a.risk ?? "review").uppercased()).font(.system(size: 11, weight: .semibold)).tracking(0.4)
        }
        .foregroundStyle(Z.status(a.capacity))
        .padding(.horizontal, Z.s2).padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(a.capacity).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(a.capacity).opacity(0.35), lineWidth: 1))
    }

    private func sectionLabel(_ t: String) -> some View {
        Text(t).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
    }
}
