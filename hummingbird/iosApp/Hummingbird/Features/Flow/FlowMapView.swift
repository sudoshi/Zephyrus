import SwiftUI

/// The list ⇄ map segment a persona home offers in its toolbar (the map is a
/// presentation mode of the home, never a separate tab).
enum FlowHomeMode: String, CaseIterable {
    case list, map
}

/// The Flow Window map surface — a presentation mode of the persona's home (list ⇄ map),
/// not a new tab. Composes the scope header, the spatial layer (HouseStack for house scope,
/// FloorPlate once descended / for unit scope), the Chronobar, the persona's timeline lanes,
/// and the selection/provenance detail strip.
///
/// Start-of-shift moment (charge nurse): on first appearance the scrubber rewinds to the
/// last shift boundary (07:00/19:00) and auto-replays the unit's story up to now —
/// DESIGN-ELEVATION Wave 3 rendered spatially. Skipped under Reduce Motion.
struct FlowMapView: View {
    @EnvironmentObject var auth: AuthStore
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @StateObject private var store: FlowWindowStore

    let persona: String
    let scope: FlowScopeRequest

    @State private var didStartShiftReplay = false

    init(persona: String, scope: FlowScopeRequest) {
        self.persona = persona
        self.scope = scope
        _store = StateObject(wrappedValue: FlowWindowStore(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                if store.window == nil && store.isLoading {
                    SkeletonRows()
                } else if store.window == nil, let message = store.errorMessage {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load the flow window",
                                     message: message, tone: .warning) {
                        Task { await store.load(bearer: auth.accessToken ?? "", persona: persona, scope: scope) }
                    }
                } else if store.window != nil {
                    header
                    mapLayer
                    ChronobarView(store: store)
                    TimelineLanes(store: store) { store.selection = $0 }
                    detailStrip
                }
            }
            .padding(Z.s4)
        }
        .background(Z.bg)
        .task {
            let token = auth.accessToken ?? ""
            store.startLive(bearer: token)
            defer { store.stopLive() }
            await store.load(bearer: token, persona: persona, scope: scope)
            runStartOfShiftReplayIfNeeded()
            // Park until cancelled so the websocket stays open while the map is visible;
            // `hospital.beds` events re-snapshot the window (no 15s poll needed here).
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(60))
            }
        }
        .onChange(of: store.needsReauth) { _, needs in
            if needs { Task { await auth.logout() } }
        }
    }

    // MARK: Header

    private var header: some View {
        HStack(alignment: .firstTextBaseline, spacing: Z.s2) {
            VStack(alignment: .leading, spacing: 2) {
                Text(store.window?.scope.label ?? "House")
                    .font(.system(size: 16, weight: .semibold))
                    .foregroundStyle(Z.ink)
                Text(personaTitle + " lens")
                    .font(.system(size: 12))
                    .foregroundStyle(Z.inkMuted)
            }
            Spacer()
            if store.live {
                HStack(spacing: 4) {
                    Circle().fill(Z.status(.success)).frame(width: 7, height: 7)
                    Text("LIVE")
                        .font(.system(size: 10, weight: .semibold)).tracking(0.5)
                        .foregroundStyle(Z.status(.success))
                }
            }
        }
    }

    private var personaTitle: String {
        Role.by(id: persona)?.title ?? persona.replacingOccurrences(of: "_", with: " ").capitalized
    }

    // MARK: Spatial layer

    @ViewBuilder
    private var mapLayer: some View {
        let isHouse = (scope == .house)
        if isHouse, store.selectedFloor == nil {
            Panel(padding: Z.s3) {
                HouseStackView(rollups: store.window?.spaces?.floors ?? [],
                               floorsDocument: store.floors) { floor in
                    store.selectedFloor = floor
                    store.selection = nil
                }
            }
        } else {
            floorPanel(floorNumber: store.selectedFloor ?? unitFloorNumber)
        }
    }

    @ViewBuilder
    private func floorPanel(floorNumber: Int?) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            if scope == .house {
                Button {
                    store.selectedFloor = nil
                    store.selection = nil
                } label: {
                    Label("House", systemImage: "chevron.left")
                        .font(.system(size: 13, weight: .medium))
                        .foregroundStyle(Z.primary)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Back to the house stack")
            }
            if let floor = geometryFloor(floorNumber) {
                Panel(padding: Z.s2) {
                    FloorPlateView(floor: floor,
                                   unitRollups: unitRollups,
                                   ghosts: store.ghostsUpTo(store.t),
                                   selection: store.selection) { store.selection = $0 }
                        .frame(height: 240)
                }
            } else {
                Panel {
                    VStack(alignment: .leading, spacing: Z.s2) {
                        Text(floorNumber.map { "Floor \($0)" } ?? "This floor")
                            .font(.system(size: 15, weight: .semibold))
                            .foregroundStyle(Z.ink)
                        Text("No floor plates exported for this floor yet — occupancy heat is on the house stack.")
                            .font(.system(size: 13))
                            .foregroundStyle(Z.inkMuted)
                    }
                }
            }
        }
    }

    /// For unit scope: the floor the payload names, else the floor whose plates carry the unit.
    private var unitFloorNumber: Int? {
        if let floor = store.window?.scope.floor { return floor }
        guard case .unit(let unitId) = scope else { return store.floors?.floors.first?.floor }
        if let floor = store.floors?.floors.first(where: { f in f.spaces.contains { $0.unitId == unitId } }) {
            return floor.floor
        }
        if let floor = store.window?.spaces?.floors.first(where: { f in f.units.contains { $0.unitId == unitId } }) {
            return floor.floor
        }
        return store.floors?.floors.first?.floor
    }

    private func geometryFloor(_ number: Int?) -> FlowFloor? {
        guard let number else { return nil }
        return store.floors?.floors.first { $0.floor == number }
    }

    private var unitRollups: [Int: FlowUnitRollup] {
        var byId: [Int: FlowUnitRollup] = [:]
        for floor in store.window?.spaces?.floors ?? [] {
            for unit in floor.units { byId[unit.unitId] = unit }
        }
        return byId
    }

    // MARK: Detail strip

    @ViewBuilder
    private var detailStrip: some View {
        switch store.selection {
        case .none:
            Text("Tap a floor, plate, ghost, or timeline dot for detail.")
                .font(.system(size: 12))
                .foregroundStyle(Z.inkMuted)
        case .plate(let plate):
            Panel(padding: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s1) {
                    Text(plate.label ?? plate.code ?? plate.category)
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundStyle(Z.ink)
                    if let unitId = plate.unitId, let rollup = unitRollups[unitId] {
                        Text("\(rollup.occupied)/\(rollup.staffed) occupied · \(Int(rollup.occupancyPct))%")
                            .font(.system(size: 12)).monospacedDigit()
                            .foregroundStyle(Z.inkMuted)
                    } else {
                        Text(plate.category.replacingOccurrences(of: "_", with: " "))
                            .font(.system(size: 12))
                            .foregroundStyle(Z.inkMuted)
                    }
                }
            }
        case .event(let event):
            Panel(padding: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s1) {
                    HStack(spacing: Z.s2) {
                        Text(event.label ?? event.kind)
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(Z.ink)
                        if event.capacity == .warning || event.capacity == .critical {
                            StatusChip(status: event.capacity)
                        }
                    }
                    Text([timeText(event.time), event.toSpace].compactMap { $0 }.joined(separator: " · "))
                        .font(.system(size: 12)).monospacedDigit()
                        .foregroundStyle(Z.inkMuted)
                    if let source = event.provenance?.source {
                        provenanceChip("Source: \(source)")
                    }
                }
            }
        case .projection(let projection):
            Panel(padding: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s1) {
                    Text(projection.label ?? projection.kind)
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundStyle(Z.ink)
                    Text([projection.confidence, timeText(projection.time)].compactMap { $0 }.joined(separator: " · "))
                        .font(.system(size: 12)).monospacedDigit()
                        .foregroundStyle(Z.inkMuted)
                    if let provenance = projection.provenance {
                        provenanceChip(provenance.chipText)
                    }
                }
            }
        }
    }

    private func provenanceChip(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .medium)).monospacedDigit()
            .foregroundStyle(Z.inkMuted)
            .padding(.horizontal, Z.s2)
            .padding(.vertical, Z.s1)
            .background(Capsule().fill(Z.bg))
            .overlay(Capsule().strokeBorder(Z.border, lineWidth: 1))
    }

    private func timeText(_ date: Date?) -> String? {
        guard let date else { return nil }
        let f = DateFormatter()
        f.dateFormat = "HH:mm"
        return f.string(from: date)
    }

    // MARK: Start-of-shift replay

    private func runStartOfShiftReplayIfNeeded() {
        guard persona == "charge_nurse", !didStartShiftReplay, store.window != nil else { return }
        didStartShiftReplay = true
        let boundary = FlowWindowStore.lastShiftBoundary(before: store.nowDate)
        store.t = store.clamp(boundary)
        if !reduceMotion {
            store.play()
        }
    }
}
