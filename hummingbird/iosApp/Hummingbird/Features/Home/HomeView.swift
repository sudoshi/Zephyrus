import SwiftUI

struct HomeView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @StateObject private var vm: HomeViewModel
    @State private var pulse = false
    @State private var path = NavigationPath()
    @State private var showProfile = false

    /// Poll cadence — now just the fallback safety net; live updates arrive over the Reverb
    /// websocket (architecture: push-first / WS-when-foregrounded / poll-fallback).
    private let refreshInterval: Duration = .seconds(15)

    init() {
        // The APIClient is value-type and cheap; mirror the app config.
        _vm = StateObject(wrappedValue: HomeViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)))
    }

    // The confirmed role drives what this surface emphasizes and which units it lists.
    private var role: RoleExperience { RoleExperience.of(profile.roleId) }
    private var pinned: CensusUnit? { role.pinnedUnit(vm.units, myUnitId: profile.unitId) }
    private var listUnits: [CensusUnit] { role.censusList(vm.units, myUnitId: profile.unitId) }

    var body: some View {
        NavigationStack(path: $path) {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    greeting
                    if vm.units.isEmpty && vm.isLoading {
                        ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                    } else if vm.units.isEmpty && vm.errorMessage != nil {
                        // Hard failure with nothing cached — don't render a misleading 0/0 rollup.
                        RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load the census",
                                         message: vm.errorMessage ?? "", tone: .warning) {
                            Task { await vm.load(bearer: auth.accessToken ?? "") }
                        }
                    } else if vm.units.isEmpty {
                        RetryableMessage(symbol: "building.2", title: "No units reporting",
                                         message: "No units are reporting a census right now.")
                    } else {
                        roleContent
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle(role.homeTitle)
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
            .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
            .task {
                // Open the live websocket; keep the poll loop as a fallback. Both auto-stop
                // when the view goes away.
                let token = auth.accessToken ?? ""
                vm.startLive(bearer: token)
                defer { vm.stopLive() }
                var first = true
                while !Task.isCancelled {
                    await vm.load(bearer: token)
                    if first {
                        first = false
                        // Deep-link test affordance: SIMCTL_CHILD_HB_OPEN_UNIT=<id> opens a unit.
                        if let s = ProcessInfo.processInfo.environment["HB_OPEN_UNIT"], let id = Int(s) {
                            path.append(id)
                        }
                        // Test affordance: SIMCTL_CHILD_HB_PROFILE=1 opens the profile sheet.
                        if ProcessInfo.processInfo.environment["HB_PROFILE"] == "1" {
                            showProfile = true
                        }
                    }
                    try? await Task.sleep(for: refreshInterval)
                }
            }
            .onChange(of: vm.needsReauth) { _, needs in
                if needs { Task { await auth.logout() } }
            }
            .navigationDestination(for: Int.self) { unitId in
                // Look the unit up from the live list so the detail stays fresh as the
                // census auto-refreshes underneath it.
                if let unit = vm.units.first(where: { $0.unitId == unitId }) {
                    UnitDetailView(unit: unit, webLink: vm.webLink)
                }
            }
        }
        .tint(Z.primary)
    }

    // MARK: Role-scoped content

    @ViewBuilder
    private var roleContent: some View {
        if let pinned {
            sectionLabel("YOUR UNIT")
            NavigationLink(value: pinned.unitId) { KpiTile(unit: pinned) }
                .buttonStyle(.plain)
        }

        if role.censusScope == .turns {
            turnsCard(listUnits)
        } else {
            rollupCard(rollupLabel, CensusRollup(listUnits))
        }

        if listUnits.isEmpty {
            Text(emptyCensusMessage)
                .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                .frame(maxWidth: .infinity).padding(.top, Z.s4)
        } else {
            censusHeader(censusTitle)
            ForEach(listUnits) { unit in
                NavigationLink(value: unit.unitId) { KpiTile(unit: unit) }
                    .buttonStyle(.plain)
            }
        }
    }

    // MARK: Sections

    private var greeting: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("Good shift, \(firstName)")
                .font(.system(size: 22, weight: .semibold))
                .foregroundStyle(Z.ink)
            Text(roleLine)
                .font(.system(size: 13))
                .foregroundStyle(Z.inkMuted)
        }
    }

    private var roleLine: String {
        if let r = profile.role {
            if let unit = profile.unitName { return "\(r.title) · \(unit)" }
            return r.title
        }
        if let wf = auth.me?.workflowPreference { return "\(wf.capitalized) workflow" }
        return ""
    }

    private func rollupCard(_ label: String, _ r: CensusRollup) -> some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack {
                    Text(label)
                        .font(.system(size: 11, weight: .semibold)).tracking(0.5)
                        .foregroundStyle(Z.inkMuted)
                    Spacer()
                    StatusChip(status: r.status)
                }
                HStack(alignment: .firstTextBaseline, spacing: Z.s2) {
                    Text("\(r.occupied)")
                        .font(.system(size: 40, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.ink)
                    Text("/ \(r.safe) safe beds")
                        .font(.system(size: 15)).foregroundStyle(Z.inkMuted)
                    Spacer()
                    Text("\(r.percent)%")
                        .font(.system(size: 22, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.status(r.status))
                }
                Divider().overlay(Z.border)
                HStack(spacing: Z.s2) {
                    Image(systemName: r.pressured > 0 ? "exclamationmark.circle.fill" : "checkmark.circle.fill")
                        .foregroundStyle(r.pressured > 0 ? Z.status(.warning) : Z.status(.success))
                    Text(r.pressured > 0
                         ? "\(r.pressured) of \(r.total) units near or at capacity"
                         : "All units within safe capacity")
                        .font(.system(size: 13)).foregroundStyle(Z.ink)
                }
            }
        }
    }

    /// EVS leads with cleaning pressure, not occupancy.
    private func turnsCard(_ units: [CensusUnit]) -> some View {
        let dirty = units.reduce(0) { $0 + $1.blocked }
        let toTurn = units.filter { $0.blocked > 0 }.count
        return Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                Text("BEDS TO TURN")
                    .font(.system(size: 11, weight: .semibold)).tracking(0.5)
                    .foregroundStyle(Z.inkMuted)
                HStack(alignment: .firstTextBaseline, spacing: Z.s2) {
                    Text("\(dirty)")
                        .font(.system(size: 40, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.ink)
                    Text("blocked / dirty beds")
                        .font(.system(size: 15)).foregroundStyle(Z.inkMuted)
                }
                Divider().overlay(Z.border)
                HStack(spacing: Z.s2) {
                    Image(systemName: toTurn > 0 ? "sparkles" : "checkmark.circle.fill")
                        .foregroundStyle(toTurn > 0 ? Z.status(.warning) : Z.status(.success))
                    Text(toTurn > 0 ? "\(toTurn) of \(units.count) units need a turn"
                                    : "No beds waiting on a turn")
                        .font(.system(size: 13)).foregroundStyle(Z.ink)
                }
            }
        }
    }

    private func censusHeader(_ title: String) -> some View {
        HStack(spacing: Z.s2) {
            Text(title)
                .font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
            if vm.live {
                HStack(spacing: 4) {
                    Circle()
                        .fill(Z.status(.success))
                        .frame(width: 7, height: 7)
                        .opacity(pulse ? 0.25 : 1)
                        .animation(.easeInOut(duration: 1).repeatForever(autoreverses: true), value: pulse)
                    Text("LIVE")
                        .font(.system(size: 10, weight: .semibold)).tracking(0.5)
                        .foregroundStyle(Z.status(.success))
                }
            } else if vm.stale {
                Label("Stale", systemImage: "wifi.exclamationmark")
                    .font(.system(size: 11, weight: .medium))
                    .foregroundStyle(Z.status(.warning))
            }
            Spacer()
            Text("as of \(vm.asOfDisplay)")
                .font(.system(size: 11)).foregroundStyle(Z.inkMuted)
        }
        .padding(.top, Z.s2)
        .onAppear { pulse = true }
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
            .foregroundStyle(Z.inkMuted)
    }

    // MARK: Role copy

    private var rollupLabel: String {
        switch role.censusScope {
        case .unitFocused: return "REST OF HOUSE"
        case .criticalCare: return "CRITICAL CARE"
        case .turns, .house: return "HOUSE CAPACITY"
        }
    }

    private var censusTitle: String {
        switch role.censusScope {
        case .unitFocused: return "Rest of house"
        case .criticalCare: return "Critical care units"
        case .turns: return "Units to turn"
        case .house: return "Unit census"
        }
    }

    private var emptyCensusMessage: String {
        switch role.censusScope {
        case .criticalCare: return "No critical-care units are reporting right now."
        default: return "No other units are reporting right now."
        }
    }

    private var firstName: String {
        (auth.me?.name.split(separator: " ").first).map(String.init) ?? "there"
    }
}
