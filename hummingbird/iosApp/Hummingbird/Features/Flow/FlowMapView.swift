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
/// Entry replays (skipped under Reduce Motion, re-runnable either way):
/// - Charge nurse: the scrubber rewinds to the last shift boundary (07:00/19:00) and
///   auto-replays the unit's story up to now (DESIGN-ELEVATION Wave 3, spatially).
/// - Executive (P9): a ~15s time-lapse of the last 24 hours — the floor heat re-renders
///   from snapshots as the Chronobar scrubs itself — then settles at now and reveals the
///   forward half (curve + forecast strip).
/// - PI lead (P8): playback runs at ~4h/s process-replay pace; "Clip window" shares the
///   replayed range as text + the web deep link (v1 clip-to-share; no PDSA write yet).
struct FlowMapView: View {
    @EnvironmentObject var auth: AuthStore
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.scenePhase) private var scenePhase
    @StateObject private var store: FlowWindowStore

    let persona: String
    let scope: FlowScopeRequest

    @State private var didStartShiftReplay = false
    @State private var showFloorPlate = false
    /// Executive: whether the forward half (curve + forecast strip) is revealed — false
    /// while the entry time-lapse is still walking the past 24h.
    @State private var revealForecast = false
    /// Capacity lead: the unit the curve is filtered to (client-side; snapshots and
    /// predicted_census are per-unit). Nil falls back to the descended floor, then house.
    @State private var curveUnitId: Int?
    /// Capacity lead: the map is the secondary surface — collapsed by default.
    @State private var showHouseMap = false

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
                        Task { await store.load(bearer: auth.accessToken ?? "", userId: auth.me?.id, persona: persona, scope: scope) }
                    }
                } else if store.window != nil {
                    header
                    if let stale = store.staleAsOf { stalenessCaption(stale) }
                    mapLayer
                    if isExecutiveLens { executiveReplayRow }
                    ChronobarView(store: store)
                    if isPILens { piControls }
                    if !isExecutiveLens {
                        TimelineLanes(store: store) { store.selection = $0 }
                    }
                    if isExecutiveLens { executiveForwardSection }
                    if isClinicianLens { DischargeLeverageLane(store: store) }
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
            await store.load(bearer: token, userId: auth.me?.id, persona: persona, scope: scope)
            runEntryReplayIfNeeded()
            // Park until cancelled so the websocket stays open while the map is visible;
            // `hospital.beds` events re-snapshot the window (no 15s poll needed here).
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(60))
            }
        }
        .onChange(of: store.needsReauth) { _, needs in
            if needs { Task { await auth.logout() } }
        }
        .onChange(of: scenePhase) { _, phase in
            // Foreground return delta-refreshes the head (keeps the user's scrub position).
            if phase == .active {
                Task { await store.refreshForeground(bearer: auth.accessToken ?? "") }
            }
        }
        .onChange(of: store.isPlaying) { _, playing in
            // Executive: the time-lapse settling (for any reason) reveals the forward half.
            if isExecutiveLens, !playing, didStartShiftReplay {
                withAnimation(reduceMotion ? nil : .easeOut(duration: 0.35)) {
                    revealForecast = true
                }
            }
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

    /// Shown when the surface is presenting the offline cache after a failed load. The past
    /// half still scrubs; this dates what you're looking at.
    private func stalenessCaption(_ asOf: Date) -> some View {
        HStack(spacing: 6) {
            Image(systemName: "wifi.slash")
                .font(.system(size: 11, weight: .semibold))
            Text("Showing data from \(asOf.formatted(date: .omitted, time: .shortened))")
                .font(.system(size: 12, weight: .medium))
                .monospacedDigit()
        }
        .foregroundStyle(Z.status(.warning))
        .padding(.horizontal, Z.s3)
        .padding(.vertical, Z.s2)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 8).fill(Z.status(.warning).opacity(0.12)))
    }

    // MARK: Persona lenses

    private var isTransportLens: Bool { persona == "transport" }
    private var isEVSLens: Bool { persona == "evs" }
    private var isORLens: Bool { persona == "or_nurse" || persona == "periop_manager" }
    private var isExecutiveLens: Bool { persona == "executive" }
    private var isCapacityLens: Bool { persona == "capacity_lead" }
    private var isStaffingLens: Bool { persona == "staffing_coordinator" }
    private var isPILens: Bool { persona == "pi_lead" }
    private var isClinicianLens: Bool { persona == "hospitalist" || persona == "intensivist" }
    /// Lenses whose floor heat time-travels with the scrubber (replay is the point).
    private var isReplayHeatLens: Bool { isExecutiveLens || isPILens }

    // MARK: Spatial layer

    @ViewBuilder
    private var mapLayer: some View {
        if isORLens {
            orLayer
        } else if isCapacityLens {
            capacityLayer
        } else if isStaffingLens {
            staffingLayer
        } else if is3DLens, let spaces = store.spaces3d {
            flow3DLayer(spaces: spaces)
        } else if scope == .house, store.selectedFloor == nil {
            houseLayer
            transportGutter
        } else {
            floorPanel(floorNumber: store.selectedFloor ?? scopedFloorNumber)
            transportGutter
        }
    }

    /// Floor rollups for the spatial layer: replay lenses re-read occupancy from the
    /// snapshot series at the scrub time; everyone else sees the live rollup.
    private var displayFloorRollups: [FlowFloorRollup] {
        isReplayHeatLens ? store.floorRollups(at: store.t) : (store.window?.spaces?.floors ?? [])
    }

    /// Phase B (NATIVE-4D-VIEWER): bed_manager / house_supervisor render the native SceneKit
    /// twin instead of the 2.5D stack. The 2.5D renderers are deleted in Phase D once every
    /// persona reaches 3D parity; other lenses keep the 2.5D layer until then.
    private var is3DLens: Bool { persona == "bed_manager" || persona == "house_supervisor" }

    private func flow3DLayer(spaces: FlowSpaces3dDocument) -> some View {
        Panel(padding: Z.s2) {
            Flow3DView(spaces: spaces,
                       bedStatuses: store.window?.bedStatuses ?? [],
                       selectedFloor: store.selectedFloor)
                .frame(height: 420)
                .cornerRadius(12)
                .accessibilityLabel("Native 3D hospital view")
        }
    }

    private var houseLayer: some View {
        Panel(padding: Z.s3) {
            HouseStackView(rollups: displayFloorRollups,
                           floorsDocument: store.floors,
                           gapByFloor: isStaffingLens ? worstGapByFloor : [:]) { floor in
                if isCapacityLens { curveUnitId = nil } // floor descent re-frames the curve
                store.descend(to: floor)
            }
            .overlayPreferenceValue(FloorSlabAnchorKey.self) { anchors in
                if isTransportLens {
                    TransportHouseArcs(trips: resolvedTrips, anchors: anchors,
                                       boundsByFloor: boundsByFloor)
                }
            }
        }
    }

    // MARK: Executive lens (P9) — time-lapse + forward half

    /// "Last 24 hours" caption + the replay affordance (always present: Reduce Motion users
    /// get the same story on demand instead of on entry).
    private var executiveReplayRow: some View {
        HStack(spacing: Z.s2) {
            sectionCaption("LAST 24 HOURS")
            Spacer()
            Button {
                revealForecast = false
                store.play(from: store.fromDate)
            } label: {
                Label("Replay", systemImage: "arrow.counterclockwise")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundStyle(Z.primary)
                    .frame(minHeight: 44)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Replay the last 24 hours")
        }
    }

    @ViewBuilder
    private var executiveForwardSection: some View {
        if revealForecast {
            VStack(alignment: .leading, spacing: Z.s3) {
                sectionCaption("NEXT 24 HOURS")
                Panel(padding: Z.s3) {
                    FlowCurveView(store: store, surgeMarker: surgeProjection) { store.selection = $0 }
                }
                FlowForecastStrip(arrivalsTotal: predictedArrivalsTotal,
                                  arrivalsSource: predictedArrivalsSource,
                                  surge: surgeProjection) { store.selection = $0 }
            }
            .transition(.opacity)
        }
    }

    private var surgeProjection: FlowProjection? {
        store.window?.projections.first { $0.kind == "surge_probability" }
    }

    /// Predicted ED arrivals summed across the next-24h hourly buckets.
    private var predictedArrivalsTotal: Int? {
        let arrivals = (store.window?.projections ?? []).filter { $0.kind == "predicted_arrivals" }
        guard !arrivals.isEmpty else { return nil }
        return Int(arrivals.reduce(0.0) { $0 + ($1.value ?? 0) })
    }

    private var predictedArrivalsSource: String? {
        (store.window?.projections ?? [])
            .first { $0.kind == "predicted_arrivals" }?
            .provenance?.service
    }

    // MARK: Capacity lead lens (P6) — curve first, map second

    private var capacityLayer: some View {
        VStack(alignment: .leading, spacing: Z.s3) {
            Panel(padding: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s2) {
                    HStack(spacing: Z.s2) {
                        sectionCaption("OCCUPANCY VS STAFFED")
                        Spacer()
                        if let label = curveFilterLabel {
                            FlowFilterChip(label: label) {
                                curveUnitId = nil
                                store.descend(to: nil)
                            }
                        }
                    }
                    FlowCurveView(store: store,
                                  unitIds: capacityCurveUnitIds,
                                  surgeMarker: surgeProjection) { store.selection = $0 }
                }
            }
            DisclosureGroup(isExpanded: $showHouseMap) {
                VStack(alignment: .leading, spacing: Z.s2) {
                    if store.selectedFloor == nil {
                        houseLayer
                    } else {
                        floorPanel(floorNumber: store.selectedFloor)
                    }
                    Text("Tap a floor, then a unit, to focus the curve.")
                        .font(.system(size: 11))
                        .foregroundStyle(Z.inkMuted)
                }
                .padding(.top, Z.s2)
            } label: {
                Text("House map")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundStyle(Z.ink)
                    .frame(minHeight: 44, alignment: .leading)
            }
            .tint(Z.inkMuted)
        }
    }

    /// The curve's unit filter: an explicitly tapped unit wins; otherwise the descended
    /// floor's units; otherwise the whole house. Client-side only — snapshots and
    /// predicted_census already arrive per-unit at house scope.
    private var capacityCurveUnitIds: Set<Int>? {
        if let unitId = curveUnitId { return [unitId] }
        if let floor = store.selectedFloor,
           let rollup = store.window?.spaces?.floors.first(where: { $0.floor == floor }) {
            return Set(rollup.units.map(\.unitId))
        }
        return nil
    }

    private var curveFilterLabel: String? {
        if let unitId = curveUnitId {
            return unitRollups[unitId]?.abbr ?? unitRollups[unitId]?.name ?? "Unit \(unitId)"
        }
        if let floor = store.selectedFloor {
            return store.window?.spaces?.floors.first { $0.floor == floor }?.label ?? "Floor \(floor)"
        }
        return nil
    }

    // MARK: Staffing lens (P10) — coverage vs the curve

    private var staffingLayer: some View {
        VStack(alignment: .leading, spacing: Z.s3) {
            Panel(padding: Z.s3) {
                VStack(alignment: .leading, spacing: Z.s2) {
                    sectionCaption("COVERAGE VS CENSUS")
                    FlowCurveView(store: store,
                                  gapSteps: staffingGapSteps,
                                  emphasizedDetent: nextShiftBoundary) { store.selection = $0 }
                }
            }
            if scope == .house, store.selectedFloor == nil {
                houseLayer
            } else {
                floorPanel(floorNumber: store.selectedFloor ?? scopedFloorNumber)
            }
        }
    }

    private var staffingGapSteps: [FlowProjection] {
        (store.window?.projections ?? []).filter { $0.kind == "staffing_shift_gap" }
    }

    /// The next shift boundary after now — P10's signature detent ("tonight's exposure").
    private var nextShiftBoundary: Date? {
        store.shiftBoundaries.first { $0 > store.nowDate }
    }

    /// Floor → worst staffing-gap headcount, joining staffing_shift_gap.unit_id to its
    /// manifest floor through the spaces rollup.
    private var worstGapByFloor: [Int: Int] {
        var floorByUnit: [Int: Int] = [:]
        for floor in store.window?.spaces?.floors ?? [] {
            for unit in floor.units { floorByUnit[unit.unitId] = floor.floor }
        }
        var worst: [Int: Int] = [:]
        for projection in staffingGapSteps {
            guard let unitId = projection.unitId, let floor = floorByUnit[unitId],
                  let gap = projection.gapHeadcount, gap > 0 else { continue }
            worst[floor] = max(worst[floor] ?? 0, gap)
        }
        return worst
    }

    // MARK: PI lens (P8) — process replay + clip-to-share

    private var piControls: some View {
        HStack(spacing: Z.s2) {
            Button {
                store.play(from: store.fromDate)
            } label: {
                Label("Replay 24h", systemImage: "arrow.counterclockwise")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundStyle(Z.primary)
                    .padding(.horizontal, Z.s3)
                    .frame(minHeight: 44)
                    .background(Capsule().strokeBorder(Z.border, lineWidth: 1))
                    .contentShape(Capsule())
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Replay the last 24 hours at process pace")
            Spacer()
            ShareLink(item: clipText) {
                Label("Clip window", systemImage: "scissors")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundStyle(Z.primary)
                    .padding(.horizontal, Z.s3)
                    .frame(minHeight: 44)
                    .background(Capsule().strokeBorder(Z.border, lineWidth: 1))
                    .contentShape(Capsule())
            }
            .accessibilityLabel("Clip the replayed window to share")
        }
    }

    /// The clip range: the replay head defines the end — a head parked at/near now clips
    /// the whole past half. Always within the past (the future is projection, not evidence).
    private var clipRange: (from: Date, to: Date) {
        let end = min(store.t, store.nowDate)
        if end <= store.fromDate.addingTimeInterval(300) || end >= store.nowDate.addingTimeInterval(-60) {
            return (store.fromDate, store.nowDate)
        }
        return (store.fromDate, end)
    }

    /// v1 clip-to-share (the PDSA attach endpoint doesn't exist yet): a plain-text summary
    /// — scope, range, occupancy delta from snapshots, event counts by kind — plus the web
    /// 4D-Navigator deep link with the clipped range appended.
    private var clipText: String {
        let range = clipRange
        let formatter = DateFormatter()
        formatter.dateFormat = "MMM d HH:mm"
        var lines = [
            "Flow window clip · \(store.window?.scope.label ?? "House")",
            "\(formatter.string(from: range.from)) – \(formatter.string(from: range.to))",
        ]
        if let delta = occupancyDelta(range) {
            let sign = delta.change >= 0 ? "+" : ""
            lines.append("Occupancy \(delta.start) → \(delta.end) (\(sign)\(delta.change))")
        }
        let counts = eventCountsText(range)
        if !counts.isEmpty { lines.append("Events: \(counts)") }
        if let url = clipURL(range) { lines.append(url.absoluteString) }
        return lines.joined(separator: "\n")
    }

    /// House occupancy at the clip edges from the snapshot series (nearest checkpoint ≤ t).
    private func occupancyDelta(_ range: (from: Date, to: Date)) -> (start: Int, end: Int, change: Int)? {
        let unitIds = (store.window?.spaces?.floors ?? []).flatMap { $0.units.map(\.unitId) }
        guard !unitIds.isEmpty, !(store.window?.snapshots.isEmpty ?? true) else { return nil }
        func occupancy(at t: Date) -> Int? {
            let values = unitIds.compactMap { store.censusAt(t, unitId: $0)?.occupied }
            return values.isEmpty ? nil : values.reduce(0, +)
        }
        guard let start = occupancy(at: range.from), let end = occupancy(at: range.to) else { return nil }
        return (start, end, end - start)
    }

    private func eventCountsText(_ range: (from: Date, to: Date)) -> String {
        let events = (store.window?.events ?? []).filter {
            guard let t = $0.time else { return false }
            return t >= range.from && t <= range.to
        }
        guard !events.isEmpty else { return "" }
        var countsByKind: [String: Int] = [:]
        for event in events { countsByKind[event.kind, default: 0] += 1 }
        let ordered = countsByKind.sorted { lhs, rhs in
            lhs.value == rhs.value ? lhs.key < rhs.key : lhs.value > rhs.value
        }
        let parts: [String] = ordered.map { entry in
            let kind = entry.key.replacingOccurrences(of: "_", with: " ")
            return "\(kind) ×\(entry.value)"
        }
        return parts.joined(separator: ", ")
    }

    /// links.web with the clipped range appended (`?from=&to=`), for the A3 handoff.
    private func clipURL(_ range: (from: Date, to: Date)) -> URL? {
        guard let link = store.webLink, var components = URLComponents(string: link) else { return nil }
        var items = components.queryItems ?? []
        items.append(URLQueryItem(name: "from", value: FlowTime.formatter.string(from: range.from)))
        items.append(URLQueryItem(name: "to", value: FlowTime.formatter.string(from: range.to)))
        components.queryItems = items
        return components.url
    }

    private func sectionCaption(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
            .foregroundStyle(Z.inkMuted)
    }

    /// The OR lens leads with the room lanes; the floor plate stays one fold away.
    private var orLayer: some View {
        VStack(alignment: .leading, spacing: Z.s3) {
            Panel(padding: Z.s3) {
                RoomLanesView(store: store, persona: persona) { store.selection = $0 }
            }
            DisclosureGroup(isExpanded: $showFloorPlate) {
                floorPanel(floorNumber: store.selectedFloor ?? scopedFloorNumber)
                    .padding(.top, Z.s2)
            } label: {
                Text("Floor plate")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundStyle(Z.ink)
                    .frame(minHeight: 44, alignment: .leading)
            }
            .tint(Z.inkMuted)
        }
    }

    @ViewBuilder
    private var transportGutter: some View {
        if isTransportLens {
            OffMapGutter(trips: resolvedTrips) { store.selection = $0 }
        }
    }

    @ViewBuilder
    private func floorPanel(floorNumber: Int?) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            if scope == .house {
                Button {
                    if isCapacityLens { curveUnitId = nil }
                    store.descend(to: nil)
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
                                   ghosts: plateGhosts,
                                   selection: store.selection,
                                   bedStates: isEVSLens ? store.bedStatesById : [:],
                                   bedStateOpacity: bedStateOpacity,
                                   turnMarks: isEVSLens ? turnMarks : [],
                                   routes: isTransportLens ? planRoutes(on: floor.floor) : []) { selected in
                        store.selection = selected
                        // Capacity lens: a tapped unit plate focuses the curve on that unit.
                        if isCapacityLens, case .plate(let plate) = selected, let unitId = plate.unitId {
                            curveUnitId = unitId
                        }
                    }
                        .frame(height: 240)
                }
                if isEVSLens, !store.bedStatesById.isEmpty, bedStateOpacity < 1 {
                    // Bed state is strictly current — say so instead of pretending to replay it.
                    Text("Bed states shown at now")
                        .font(.system(size: 11))
                        .foregroundStyle(Z.inkMuted)
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

    /// The floor to frame for floor/unit scope: the payload's floor first (server truth),
    /// then the request, then the first floor with geometry.
    private var scopedFloorNumber: Int? {
        if let floor = store.window?.scope.floor { return floor }
        switch scope {
        case .floor(let number):
            return number ?? store.floors?.floors.first?.floor ?? 1
        case .unit(let unitId):
            if let floor = store.floors?.floors.first(where: { f in f.spaces.contains { $0.unitId == unitId } }) {
                return floor.floor
            }
            if let floor = store.window?.spaces?.floors.first(where: { f in f.units.contains { $0.unitId == unitId } }) {
                return floor.floor
            }
            return store.floors?.floors.first?.floor
        case .house:
            return store.floors?.floors.first?.floor
        }
    }

    private func geometryFloor(_ number: Int?) -> FlowFloor? {
        guard let number else { return nil }
        return store.floors?.floors.first { $0.floor == number }
    }

    private var unitRollups: [Int: FlowUnitRollup] {
        var byId: [Int: FlowUnitRollup] = [:]
        for floor in displayFloorRollups {
            for unit in floor.units { byId[unit.unitId] = unit }
        }
        return byId
    }

    // MARK: Layer data (transport routes, EVS turn map)

    /// Generic bed ghosts, minus kinds a persona layer already renders its own way
    /// (trips as arcs, turns as marks) so nothing draws twice.
    private var plateGhosts: [FlowProjection] {
        var excluded: Set<String> = []
        if isTransportLens { excluded.insert("transport_due") }
        if isEVSLens { excluded.insert("evs_due") }
        return store.ghostsUpTo(store.t).filter { !excluded.contains($0.kind) }
    }

    private var resolvedTrips: [ResolvedTrip] {
        guard isTransportLens else { return [] }
        let resolver = FlowSpaceResolver(window: store.window, floors: store.floors)
        return FlowTripBuilder.trips(window: store.window, at: store.t)
            .map { ResolvedTrip(trip: $0, resolver: resolver) }
    }

    private var boundsByFloor: [Int: [Double]] {
        Dictionary((store.floors?.floors ?? []).map { ($0.floor, $0.bounds) },
                   uniquingKeysWith: { first, _ in first })
    }

    private func planRoutes(on floor: Int) -> [FlowPlanRoute] {
        resolvedTrips.compactMap { resolved in
            guard let from = resolved.from, let to = resolved.to,
                  from.floor == floor, to.floor == floor,
                  let fromPlan = from.plan, let toPlan = to.plan else { return nil }
            return FlowPlanRoute(id: resolved.id, from: fromPlan, to: toPlan,
                                 ghost: resolved.trip.ghost, opacity: resolved.trip.opacity,
                                 warning: resolved.trip.warning)
        }
    }

    /// Bed states are strictly current; fade the tint once the scrub time leaves now.
    private var bedStateOpacity: Double {
        abs(store.t.timeIntervalSince(store.nowDate)) <= 30 * 60 ? 1 : 0.25
    }

    /// Turn markers for the EVS floor plate: the latest past evs_status per bed as a solid
    /// tick, the next upcoming evs_due per bed as a dashed ghost with its due time.
    private var turnMarks: [FlowTurnMark] {
        guard isEVSLens, let window = store.window else { return [] }
        let bedIdByLabel = store.bedIdByLabel

        var latestByBed: [Int: FlowTimelineEvent] = [:]
        for event in window.events where event.kind == "evs_status" {
            guard let time = event.time, time <= store.t,
                  let bedId = event.toSpace.flatMap({ bedIdByLabel[$0] }) else { continue }
            if let current = latestByBed[bedId], (current.time ?? .distantPast) >= time { continue }
            latestByBed[bedId] = event
        }

        var nextDueByBed: [Int: FlowProjection] = [:]
        for projection in window.projections where projection.kind == "evs_due" {
            guard let time = projection.time, time >= store.t, let bedId = projection.bedId else { continue }
            if let current = nextDueByBed[bedId], (current.time ?? .distantFuture) <= time { continue }
            nextDueByBed[bedId] = projection
        }

        let past = latestByBed.map { bedId, event in
            FlowTurnMark(id: "event|\(event.id)", bedId: bedId, ghost: false, opacity: 0.9,
                         timeText: nil, isolation: isIsolationTurn(event.label),
                         warning: event.capacity == .warning || event.capacity == .critical,
                         selection: .event(event))
        }
        let upcoming = nextDueByBed.map { bedId, projection in
            FlowTurnMark(id: "projection|\(projection.id)", bedId: bedId, ghost: true,
                         opacity: projection.confidenceLevel.ghostOpacity,
                         timeText: timeText(projection.time),
                         isolation: isIsolationTurn(projection.label),
                         warning: false,
                         selection: .projection(projection))
        }
        return (past + upcoming).sorted { $0.id < $1.id }
    }

    // The payload has no isolation flag — the label is the only honest signal, so the ISO
    // chip appears exactly when the request says isolation.
    private func isIsolationTurn(_ label: String?) -> Bool {
        label?.localizedCaseInsensitiveContains("isolation") ?? false
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
                    } else if let bedId = plate.bedId, let state = store.bedStatesById[bedId] {
                        // The status word, so bed state is never communicated by tint alone.
                        Text(statusLabel(state.status))
                            .font(.system(size: 12, weight: .medium))
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
                    if projection.provenance != nil {
                        provenanceChip(projectionChipText(projection))
                    }
                }
            }
        }
    }

    /// Derived ghosts cite their chain explicitly ("Derived · expected discharge"), plus
    /// the reliability score when the source service reported one.
    private func projectionChipText(_ projection: FlowProjection) -> String {
        guard projection.derived == true else { return projection.provenance?.chipText ?? "" }
        let origin = (projection.provenance?.service ?? "")
            .replacingOccurrences(of: "derived · ", with: "")
            .replacingOccurrences(of: "_", with: " ")
        var parts = ["Derived · \(origin.isEmpty ? "projection" : origin)"]
        if let reliability = projection.provenance?.reliability {
            parts.append("reliability \(String(format: "%.2f", reliability))")
        }
        return parts.joined(separator: " · ")
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

    // MARK: Entry replays (charge nurse start-of-shift · executive time-lapse · PI pace)

    private func runEntryReplayIfNeeded() {
        guard !didStartShiftReplay, store.window != nil else { return }
        switch persona {
        case "charge_nurse":
            didStartShiftReplay = true
            let boundary = FlowWindowStore.lastShiftBoundary(before: store.nowDate)
            store.t = store.clamp(boundary)
            if !reduceMotion {
                store.play()
            }
        case "executive":
            didStartShiftReplay = true
            if reduceMotion {
                // No autoplay: land at now with the forward half revealed; the Replay
                // button tells the same 24h story on demand.
                store.t = store.nowDate
                revealForecast = true
            } else {
                revealForecast = false
                store.play(from: store.fromDate) // ~15s full past-half time-lapse
            }
        case "pi_lead":
            didStartShiftReplay = true
            store.playRate = 4 * 3600 // process-replay pace: ~4 sim-hours per second
        default:
            break
        }
    }
}
