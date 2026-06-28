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
                        houseRollup
                        censusHeader
                        ForEach(vm.units) { unit in
                            NavigationLink(value: unit.unitId) { KpiTile(unit: unit) }
                                .buttonStyle(.plain)
                        }
                    }
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("House Status")
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
        if let role = profile.role {
            if let unit = profile.unitName { return "\(role.title) · \(unit)" }
            return role.title
        }
        if let wf = auth.me?.workflowPreference { return "\(wf.capitalized) workflow" }
        return ""
    }

    private var houseRollup: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack {
                    Text("HOUSE CAPACITY")
                        .font(.system(size: 11, weight: .semibold)).tracking(0.5)
                        .foregroundStyle(Z.inkMuted)
                    Spacer()
                    StatusChip(status: vm.houseStatus)
                }
                HStack(alignment: .firstTextBaseline, spacing: Z.s2) {
                    Text("\(vm.totalOccupied)")
                        .font(.system(size: 40, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.ink)
                    Text("/ \(vm.totalSafe) safe beds")
                        .font(.system(size: 15)).foregroundStyle(Z.inkMuted)
                    Spacer()
                    Text("\(vm.occupancyPercent)%")
                        .font(.system(size: 22, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.status(vm.houseStatus))
                }
                Divider().overlay(Z.border)
                HStack(spacing: Z.s2) {
                    Image(systemName: vm.pressuredUnitCount > 0 ? "exclamationmark.circle.fill" : "checkmark.circle.fill")
                        .foregroundStyle(vm.pressuredUnitCount > 0 ? Z.status(.warning) : Z.status(.success))
                    Text(vm.pressuredUnitCount > 0
                         ? "\(vm.pressuredUnitCount) of \(vm.units.count) units near or at capacity"
                         : "All units within safe capacity")
                        .font(.system(size: 13)).foregroundStyle(Z.ink)
                }
            }
        }
    }

    private var censusHeader: some View {
        HStack(spacing: Z.s2) {
            Text("Unit census")
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

    private var firstName: String {
        (auth.me?.name.split(separator: " ").first).map(String.init) ?? "there"
    }
}
