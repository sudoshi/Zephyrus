import SwiftUI

// The transporter's route layer (P1 lens): completed/active trips as solid arcs between
// origin and destination, upcoming trips as dashed ghost arcs with confidence-mapped
// opacity, and an "Off-map" gutter for endpoints that name no mappable space.

// MARK: Trips

/// One trip drawn on the map: the latest transport_status at/before the scrub time
/// (solid), or a transport_due projection still ahead of it (ghost).
struct FlowTrip: Identifiable {
    let id: String
    let fromLabel: String?
    let toLabel: String?
    let ghost: Bool
    let opacity: Double
    /// STAT tier renders in the warning tint — never coral (earned urgency).
    let warning: Bool
    let unitId: Int?
    let bedId: Int?
    let time: Date?
    let selection: FlowSelection
}

enum FlowTripBuilder {
    /// Trips visible at scrub time `t`: per transport request, the latest status event at
    /// or before t (solid); plus every transport_due still due at or after t (ghost).
    static func trips(window: FlowWindowData?, at t: Date) -> [FlowTrip] {
        guard let window else { return [] }

        var latestByRef: [String: FlowTimelineEvent] = [:]
        for event in window.events where event.kind == "transport_status" {
            guard let time = event.time, time <= t else { continue }
            let key = event.entity?.ref ?? event.id
            if let current = latestByRef[key], (current.time ?? .distantPast) >= time { continue }
            latestByRef[key] = event
        }

        var trips: [FlowTrip] = latestByRef.values
            .sorted { ($0.time ?? .distantPast) < ($1.time ?? .distantPast) }
            .map { event in
                FlowTrip(id: "event|\(event.id)",
                         fromLabel: event.fromSpace, toLabel: event.toSpace,
                         ghost: false, opacity: 0.85,
                         warning: event.capacity == .warning || event.capacity == .critical,
                         unitId: event.unitId, bedId: nil, time: event.time,
                         selection: .event(event))
            }

        for projection in window.projections where projection.kind == "transport_due" {
            guard let time = projection.time, time >= t else { continue }
            let route = parseRoute(label: projection.label)
            trips.append(FlowTrip(id: "projection|\(projection.id)",
                                  fromLabel: route?.from, toLabel: route?.to,
                                  ghost: true, opacity: projection.confidenceLevel.ghostOpacity,
                                  warning: false,
                                  unitId: projection.unitId, bedId: projection.bedId, time: time,
                                  selection: .projection(projection)))
        }
        return trips
    }

    /// transport_due carries its route only in the label ("Transport due · ED → MICU-01");
    /// derived ghosts ("Likely discharge transport") have no route text at all.
    static func parseRoute(label: String?) -> (from: String, to: String)? {
        guard let tail = label?.components(separatedBy: " · ").last else { return nil }
        let parts = tail.components(separatedBy: " → ")
        guard parts.count == 2 else { return nil }
        let from = parts[0].trimmingCharacters(in: .whitespaces)
        let to = parts[1].trimmingCharacters(in: .whitespaces)
        guard !from.isEmpty, !to.isEmpty else { return nil }
        return (from, to)
    }
}

// MARK: Space resolution

/// Resolves the data plane's space vocabulary — unit abbreviation ("ED"), full unit name
/// ("3 West — Medical ICU (MICU)"), or bed label ("MICU-01") — to a floor + plate centroid.
/// Free text ("Main Lobby Discharge") stays unresolved and lands in the off-map gutter.
struct FlowSpaceResolver {
    struct Endpoint {
        let floor: Int
        /// Plate centroid in plan feet; nil when the unit has census but no geometry.
        let plan: CGPoint?
    }

    private var unitIdByAbbr: [String: Int] = [:]
    private var unitIdByName: [String: Int] = [:]
    private var floorByUnitId: [Int: Int] = [:]
    private var planByUnitId: [Int: (floor: Int, point: CGPoint)] = [:]

    init(window: FlowWindowData?, floors: FlowFloorsDocument?) {
        for floor in window?.spaces?.floors ?? [] {
            for unit in floor.units {
                floorByUnitId[unit.unitId] = floor.floor
                if let abbr = unit.abbr { unitIdByAbbr[abbr.lowercased()] = unit.unitId }
                if let name = unit.name { unitIdByName[name.lowercased()] = unit.unitId }
            }
        }
        for floor in floors?.floors ?? [] {
            for plate in floor.spaces where plate.category == "unit" {
                guard let unitId = plate.unitId, plate.rect.count >= 4 else { continue }
                planByUnitId[unitId] = (floor.floor, CGPoint(x: plate.rect[0] + plate.rect[2] / 2,
                                                            y: plate.rect[1] + plate.rect[3] / 2))
            }
        }
    }

    func endpoint(space raw: String?) -> Endpoint? {
        guard let space = raw?.trimmingCharacters(in: .whitespaces), !space.isEmpty else { return nil }
        let key = space.lowercased()
        if let unitId = unitIdByAbbr[key] ?? unitIdByName[key] {
            return endpoint(unitId: unitId)
        }
        // Bed label {ABBR}-{NN}: resolve the bed's unit, use the unit's plate centroid.
        let parts = space.split(separator: "-")
        if parts.count >= 2, Int(parts.last ?? "") != nil,
           let unitId = unitIdByAbbr[parts.dropLast().joined(separator: "-").lowercased()] {
            return endpoint(unitId: unitId)
        }
        return nil
    }

    func endpoint(unitId: Int?) -> Endpoint? {
        guard let unitId else { return nil }
        if let plan = planByUnitId[unitId] { return Endpoint(floor: plan.floor, plan: plan.point) }
        if let floor = floorByUnitId[unitId] { return Endpoint(floor: floor, plan: nil) }
        return nil
    }
}

/// A trip joined to its resolved endpoints. Origins of derived discharge ghosts (no route
/// text) fall back to the projection's bed/unit; a discharge's destination is off-map by
/// nature and simply doesn't draw.
struct ResolvedTrip: Identifiable {
    let trip: FlowTrip
    let from: FlowSpaceResolver.Endpoint?
    let to: FlowSpaceResolver.Endpoint?

    var id: String { trip.id }

    init(trip: FlowTrip, resolver: FlowSpaceResolver) {
        self.trip = trip
        from = trip.fromLabel != nil
            ? resolver.endpoint(space: trip.fromLabel)
            : resolver.endpoint(unitId: trip.unitId)
        to = resolver.endpoint(space: trip.toLabel)
    }

    /// Chip copy for the off-map gutter — only trips that NAME a space we can't map.
    var offMapText: String? {
        let fromLost = trip.fromLabel != nil && from == nil
        let toLost = trip.toLabel != nil && to == nil
        switch (fromLost, toLost) {
        case (true, true): return "\(trip.fromLabel ?? "") → \(trip.toLabel ?? "")"
        case (false, true): return "→ \(trip.toLabel ?? "")"
        case (true, false): return "\(trip.fromLabel ?? "") →"
        case (false, false): return nil
        }
    }
}

// MARK: House arcs

/// Route arcs over the house stack: solid past trips, dashed ghosts, warning tint for
/// STAT. Cross-floor trips bow between slabs; same-floor trips hump over their slab.
/// Ghosts with only an origin (derived discharge trips) draw a dashed departure ring.
struct TransportHouseArcs: View {
    let trips: [ResolvedTrip]
    let anchors: [Int: Anchor<CGRect>]
    /// Plan bounds per floor ([x, y, w, h]) for positioning endpoints along a slab.
    let boundsByFloor: [Int: [Double]]

    var body: some View {
        GeometryReader { proxy in
            let slabRects = anchors.mapValues { proxy[$0] }
            Canvas { context, _ in
                for (index, resolved) in trips.enumerated() {
                    draw(resolved, index: index, slabs: slabRects, in: &context)
                }
            }
        }
        .allowsHitTesting(false) // arcs annotate; slabs stay tappable underneath
    }

    private func draw(_ resolved: ResolvedTrip, index: Int,
                      slabs: [Int: CGRect], in context: inout GraphicsContext) {
        let trip = resolved.trip
        let color = trip.warning ? Z.status(.warning) : (trip.ghost ? Z.ink : Z.primary)
        let shading = GraphicsContext.Shading.color(color.opacity(trip.opacity))
        let style = trip.ghost
            ? StrokeStyle(lineWidth: 1.5, dash: [4, 3])
            : StrokeStyle(lineWidth: 1.5)

        guard let from = resolved.from, let fromSlab = slabs[from.floor] else { return }
        let start = point(for: from, in: fromSlab, defaultFraction: 0.3)

        guard let to = resolved.to, let toSlab = slabs[to.floor] else {
            // Origin-only ghost (derived discharge trip): a dashed departure ring.
            if trip.ghost {
                let ring = CGRect(x: start.x - 4, y: start.y - 4, width: 8, height: 8)
                context.stroke(Path(ellipseIn: ring), with: shading, style: style)
            }
            return
        }
        let end = point(for: to, in: toSlab, defaultFraction: 0.7)

        var path = Path()
        path.move(to: start)
        if from.floor == to.floor {
            // Same-floor: hump above the slab.
            path.addQuadCurve(to: end, control: CGPoint(x: (start.x + end.x) / 2, y: start.y - 18))
        } else {
            // Cross-floor: bow sideways; stagger overlapping trips by index.
            let bow: CGFloat = 28 + CGFloat(index % 3) * 14
            path.addQuadCurve(to: end,
                              control: CGPoint(x: max(start.x, end.x) + bow, y: (start.y + end.y) / 2))
        }
        context.stroke(path, with: shading, style: style)

        // Destination marker: filled for real trips, open for ghosts.
        let dot = CGRect(x: end.x - 3, y: end.y - 3, width: 6, height: 6)
        if trip.ghost {
            context.stroke(Path(ellipseIn: dot), with: shading, lineWidth: 1.5)
        } else {
            context.fill(Path(ellipseIn: dot), with: shading)
        }
    }

    /// Endpoint x along the slab from its plan-x fraction when geometry exists;
    /// otherwise a fixed origin/destination station.
    private func point(for endpoint: FlowSpaceResolver.Endpoint,
                       in slab: CGRect, defaultFraction: CGFloat) -> CGPoint {
        var fraction = defaultFraction
        if let plan = endpoint.plan, let bounds = boundsByFloor[endpoint.floor],
           bounds.count >= 4, bounds[2] > 0 {
            fraction = min(max((plan.x - bounds[0]) / bounds[2], 0.1), 0.9)
        }
        return CGPoint(x: slab.minX + fraction * slab.width, y: slab.midY)
    }
}

// MARK: Off-map gutter

/// Trips whose named origin/destination isn't a mappable space (lobbies, SNFs, home
/// health) — listed as chips under the map instead of silently dropped.
struct OffMapGutter: View {
    let trips: [ResolvedTrip]
    let onSelect: (FlowSelection) -> Void

    private var items: [(id: String, text: String, trip: ResolvedTrip)] {
        trips.compactMap { resolved in
            resolved.offMapText.map { (resolved.id, $0, resolved) }
        }
    }

    var body: some View {
        if !items.isEmpty {
            VStack(alignment: .leading, spacing: Z.s2) {
                Text("OFF-MAP")
                    .font(.system(size: 11, weight: .semibold)).tracking(0.5)
                    .foregroundStyle(Z.inkMuted)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: Z.s2) {
                        ForEach(items, id: \.id) { item in
                            chip(item.text, trip: item.trip)
                        }
                    }
                }
            }
        }
    }

    private func chip(_ text: String, trip: ResolvedTrip) -> some View {
        Button {
            onSelect(trip.trip.selection)
        } label: {
            HStack(spacing: Z.s1) {
                Image(systemName: trip.trip.ghost ? "clock" : "figure.walk")
                    .font(.system(size: 10, weight: .medium))
                Text(text)
                    .font(.system(size: 12, weight: .medium))
                    .lineLimit(1)
            }
            .foregroundStyle(trip.trip.warning ? Z.status(.warning) : Z.inkMuted)
            .padding(.horizontal, Z.s3)
            .frame(height: 44) // full-height touch target even though the capsule reads small
            .contentShape(Capsule())
        }
        .buttonStyle(.plain)
        .background(
            Capsule().fill(Z.surface)
                .padding(.vertical, Z.s2)
        )
        .overlay(
            Capsule()
                .strokeBorder(Z.border, style: trip.trip.ghost
                    ? StrokeStyle(lineWidth: 1, dash: [4, 3])
                    : StrokeStyle(lineWidth: 1))
                .padding(.vertical, Z.s2)
        )
        .accessibilityLabel("Off-map trip, \(text)")
    }
}
