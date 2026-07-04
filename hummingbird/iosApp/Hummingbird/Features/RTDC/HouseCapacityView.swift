import SwiftUI
import WidgetKit

/// P5 — the bed manager's "House Capacity" home: the house roll-up (occupancy, net bed-need,
/// pending placements, ED boarding), the pending-placement worklist, and the pressured units.
/// Each placement opens a decision screen with the transparent recommendation. Backed by
/// GET /api/mobile/v1/rtdc/house + /rtdc/bed-requests.
@MainActor
final class HouseCapacityViewModel: ObservableObject {
    @Published var house: HouseRollup?
    @Published var placements: [Placement] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var stale = false
    @Published var webLink: String?
    @Published var needsReauth = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            errorMessage = "Can't reach the server. Check your connection and try again."
            stale = true
            return
        }
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.rtdcHouse(bearer: bearer)
            house = env.data
            webLink = env.links?["web"]
            stale = env.meta?.stale ?? false
            errorMessage = nil
            cacheGlance(env.data)
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
            stale = true
        } catch {
            errorMessage = error.localizedDescription
            stale = true
        }
        if let p = try? await api.placements(bearer: bearer) { placements = p.data }
    }

    /// Feed the home-screen glance widget from every fresh rollup (App-Group cache; the
    /// widget itself has no network or token).
    private func cacheGlance(_ h: HouseRollup) {
        let status = h.occupancy.percent >= 100 ? "critical" : (h.occupancy.percent >= 90 ? "warning" : "success")
        HouseGlanceCache.save(HouseGlanceSnapshot(
            occupancyPercent: h.occupancy.percent,
            occupied: h.occupancy.occupied,
            staffed: h.occupancy.staffed,
            pendingPlacements: h.pendingPlacements,
            statusRaw: status,
            updatedAt: Date()))
        WidgetCenter.shared.reloadTimelines(ofKind: HouseGlanceCache.widgetKind)
    }
}

struct HouseCapacityView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @StateObject private var vm: HouseCapacityViewModel
    @State private var showProfile = false
    @State private var autoOpenPlacement = false
    @State private var autoPlacementIndex = 0
    @State private var didAutoOpen = false
    @State private var viewMode: FlowHomeMode = .list

    private let refreshInterval: Duration = .seconds(20)

    init() {
        _vm = StateObject(wrappedValue: HouseCapacityViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)))
    }

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window at house scope — the bed manager's whole board, in time.
                    FlowMapView(persona: profile.roleId ?? "bed_manager", scope: .house)
                } else {
                    listBody
                }
            }
            .background(Z.bg)
            .navigationTitle("House Capacity")
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
                    Button { showProfile = true } label: {
                        Image(systemName: "person.crop.circle").foregroundStyle(Z.ink)
                    }
                    .accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .onChange(of: vm.needsReauth) { _, needs in
                if needs { Task { await auth.logout() } }
            }
            // Test/demo affordance: SIMCTL_CHILD_HB_OPEN_PLACEMENT=<n> drills into the nth
            // (1-based) pending placement so screenshot runs can reach the decision screen.
            .navigationDestination(isPresented: $autoOpenPlacement) {
                if vm.placements.indices.contains(autoPlacementIndex) {
                    PlacementDetailView(placement: vm.placements[autoPlacementIndex],
                                        api: vm.api, bearer: auth.accessToken ?? "") {
                        await vm.load(bearer: auth.accessToken ?? "")
                    }
                }
            }
            .onChange(of: vm.placements.isEmpty) { _, empty in
                if !empty, !didAutoOpen,
                   let raw = ProcessInfo.processInfo.environment["HB_OPEN_PLACEMENT"],
                   let n = Int(raw), vm.placements.indices.contains(n - 1) {
                    didAutoOpen = true
                    autoPlacementIndex = n - 1
                    autoOpenPlacement = true
                }
            }
        }
        .tint(Z.primary)
    }

    private var listBody: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                AltitudeContextCard(domain: "rtdc")
                if vm.house == nil && vm.isLoading {
                    SkeletonRows()
                } else if vm.house == nil && vm.errorMessage != nil {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load capacity",
                                     message: vm.errorMessage ?? "", tone: .warning) {
                        Task { await vm.load(bearer: auth.accessToken ?? "") }
                    }
                } else if let h = vm.house {
                    content(h)
                }
            }
            .padding(Z.s4)
        }
        .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
        .task {
            let token = auth.accessToken ?? ""
            while !Task.isCancelled {
                await vm.load(bearer: token)
                try? await Task.sleep(for: refreshInterval)
            }
        }
    }

    @ViewBuilder
    private func content(_ h: HouseRollup) -> some View {
        rollupCard(h)

        if vm.placements.isEmpty {
            sectionLabel("PLACEMENTS")
            Text("No pending bed requests.").font(.system(size: 13)).foregroundStyle(Z.inkMuted)
        } else {
            sectionLabel("PENDING PLACEMENTS (\(vm.placements.count))")
            ForEach(vm.placements) { p in
                NavigationLink {
                    PlacementDetailView(placement: p, api: vm.api, bearer: auth.accessToken ?? "") {
                        await vm.load(bearer: auth.accessToken ?? "")
                    }
                } label: { placementRow(p) }
                .buttonStyle(.plain)
            }
        }

        let pressured = h.units.filter { $0.status == "warning" || $0.status == "critical" }
            .sorted { $0.capacity.severity > $1.capacity.severity }
        if !pressured.isEmpty {
            sectionLabel("UNITS UNDER PRESSURE (\(pressured.count))")
            ForEach(pressured) { unitRow($0) }
        }
    }

    // MARK: Roll-up

    private func rollupCard(_ h: HouseRollup) -> some View {
        let occStatus = h.occupancy.percent >= 100 ? CapacityStatus.critical : (h.occupancy.percent >= 90 ? .warning : .success)
        return Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack(alignment: .firstTextBaseline, spacing: Z.s3) {
                    Text("\(h.occupancy.percent)%")
                        .font(.system(size: 40, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.status(occStatus))
                    VStack(alignment: .leading, spacing: 2) {
                        Text("HOUSE OCCUPANCY").font(.system(size: 11, weight: .semibold)).tracking(0.4).foregroundStyle(Z.inkMuted)
                        Text("\(h.occupancy.occupied) / \(h.occupancy.staffed) staffed beds").monospacedDigit().font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                    }
                    Spacer()
                }
                Divider().overlay(Z.border)
                HStack(spacing: 0) {
                    miniStat("\(h.netBedNeed)", "net bed need", tone: h.netBedNeed > 0 ? .warning : .success)
                    divider
                    miniStat("\(h.pendingPlacements)", "placements", tone: h.pendingPlacements > 0 ? .warning : nil)
                    divider
                    miniStat("\(h.edBoarding)", "ED boarding", tone: h.edBoarding > 4 ? .warning : nil)
                }
            }
        }
    }

    private func miniStat(_ value: String, _ label: String, tone: CapacityStatus?) -> some View {
        VStack(spacing: 2) {
            Text(value).font(.system(size: 22, weight: .semibold)).monospacedDigit()
                .foregroundStyle(tone.map { Z.status($0) } ?? Z.ink)
            Text(label).font(.system(size: 11)).foregroundStyle(Z.inkMuted)
        }
        .frame(maxWidth: .infinity)
    }

    private var divider: some View { Rectangle().fill(Z.border).frame(width: 1, height: 26) }

    // MARK: Rows

    private func placementRow(_ p: Placement) -> some View {
        Panel(padding: Z.s3) {
            HStack(spacing: 0) {
                Rectangle().fill(Z.status(p.capacity)).frame(width: 4).cornerRadius(2)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: Z.s2) {
                        Text(p.service ?? "Unassigned").font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
                        if p.needsIsolation { IsolationBadge() }
                    }
                    Text(placementSubtitle(p)).font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                }
                .padding(.leading, Z.s3)
                Spacer()
                Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Z.inkMuted)
            }
        }
    }

    private func placementSubtitle(_ p: Placement) -> String {
        var parts: [String] = []
        if let s = p.source { parts.append(s) }
        if let t = p.acuityTier { parts.append("tier \(t)") }
        if let u = p.requiredUnitType { parts.append("needs " + u.replacingOccurrences(of: "_", with: " ")) }
        return parts.joined(separator: " · ")
    }

    private func unitRow(_ u: CensusUnit) -> some View {
        HStack(spacing: Z.s3) {
            Text(u.name).font(.system(size: 14, weight: .medium)).foregroundStyle(Z.ink)
                .lineLimit(2)
                .fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: Z.s2)
            Text("\(u.occupied)/\(u.staffedBedCount)").font(.system(size: 13)).monospacedDigit().foregroundStyle(Z.inkMuted)
                .layoutPriority(1)
            StatusChip(status: u.capacity)
                .layoutPriority(1)
        }
        .padding(.vertical, Z.s2).padding(.horizontal, Z.s3)
        .background(RoundedRectangle(cornerRadius: 10).fill(Z.surface))
        .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
            .foregroundStyle(Z.inkMuted)
            .padding(.top, Z.s2)
    }
}
