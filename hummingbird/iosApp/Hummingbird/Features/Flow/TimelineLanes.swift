import SwiftUI

/// Persona-filtered horizontal lanes under the map, aligned to the Chronobar's 48h range.
/// Solid dots are past events; dashed-ring dots are future projections at confidence-mapped
/// opacity. Status color appears only when an event itself carries a warning/critical tier.
struct TimelineLanes: View {
    @ObservedObject var store: FlowWindowStore
    let onSelect: (FlowSelection) -> Void

    private let laneHeight: CGFloat = 26
    private let labelWidth: CGFloat = 88

    /// One lane = a labeled group of event kinds + projection kinds.
    struct Lane: Identifiable {
        let id: String
        let label: String
        let eventKinds: Set<String>
        let projectionKinds: Set<String>
    }

    /// Lanes derive from the lens's allowed kinds — a lane only shows kinds the server's
    /// lens granted, and disappears entirely when the lens grants none of its kinds.
    private var lanes: [Lane] {
        guard let lens = store.window?.lens else { return [] }
        let allowedEvents = Set(lens.eventKinds)
        let allowedProjections = Set(lens.projectionKinds)

        var candidates: [Lane] = [
            Lane(id: "moves", label: "Admits & Transfers",
                 eventKinds: ["admit", "transfer", "ed_arrival", "ed_admit_decision"],
                 projectionKinds: ["predicted_arrivals"]),
            Lane(id: "discharges", label: "Discharges",
                 eventKinds: ["discharge"],
                 projectionKinds: ["expected_discharge"]),
            Lane(id: "barriers", label: "Barriers",
                 eventKinds: ["barrier_opened", "barrier_resolved"],
                 projectionKinds: []),
            Lane(id: "turnsTrips", label: "Turns & Trips",
                 eventKinds: ["evs_status", "transport_status"],
                 projectionKinds: ["evs_due", "transport_due"]),
        ]
        if lens.roleId == "bed_manager" || lens.roleId == "house_supervisor" {
            candidates.append(Lane(id: "placements", label: "Placements",
                                   eventKinds: ["bed_request", "placement"],
                                   projectionKinds: []))
        }
        return candidates.compactMap { lane in
            let events = lane.eventKinds.intersection(allowedEvents)
            let projections = lane.projectionKinds.intersection(allowedProjections)
            guard !events.isEmpty || !projections.isEmpty else { return nil }
            return Lane(id: lane.id, label: lane.label, eventKinds: events, projectionKinds: projections)
        }
    }

    var body: some View {
        VStack(spacing: Z.s1) {
            ForEach(lanes) { lane in
                laneRow(lane)
            }
        }
    }

    private func laneRow(_ lane: Lane) -> some View {
        HStack(spacing: Z.s2) {
            Text(lane.label)
                .font(.system(size: 11, weight: .medium))
                .foregroundStyle(Z.inkMuted)
                .lineLimit(2)
                .frame(width: labelWidth, alignment: .leading)
            GeometryReader { geo in
                laneTrack(lane, width: geo.size.width, height: geo.size.height)
            }
            .frame(height: laneHeight)
        }
    }

    private func laneTrack(_ lane: Lane, width: CGFloat, height: CGFloat) -> some View {
        let events = (store.window?.events ?? []).filter { lane.eventKinds.contains($0.kind) }
        let projections = (store.window?.projections ?? []).filter { lane.projectionKinds.contains($0.kind) }
        let midY = height / 2

        return ZStack(alignment: .leading) {
            Rectangle()
                .fill(Z.border.opacity(0.5))
                .frame(height: 1)
                .position(x: width / 2, y: midY)

            // Scrub-time indicator, aligned with the Chronobar thumb.
            Rectangle()
                .fill(Z.inkMuted.opacity(0.6))
                .frame(width: 1, height: height)
                .position(x: x(for: store.t, width: width), y: midY)

            ForEach(events) { event in
                dotButton(at: CGPoint(x: x(for: event.time, width: width), y: midY)) {
                    onSelect(.event(event))
                } label: {
                    Circle()
                        .fill(eventColor(event))
                        .frame(width: 7, height: 7)
                }
                .accessibilityLabel(event.label ?? event.kind)
            }

            ForEach(projections) { projection in
                dotButton(at: CGPoint(x: x(for: projection.time, width: width), y: midY)) {
                    onSelect(.projection(projection))
                } label: {
                    Circle()
                        .stroke(Z.ink.opacity(projection.confidenceLevel.ghostOpacity),
                                style: StrokeStyle(lineWidth: 1.5, dash: [2, 2]))
                        .frame(width: 9, height: 9)
                }
                .accessibilityLabel("\(projection.label ?? projection.kind), \(projection.confidence)")
            }
        }
    }

    /// A dot with a ≥44pt hit area centered on its timeline position.
    private func dotButton(at point: CGPoint,
                           action: @escaping () -> Void,
                           @ViewBuilder label: () -> some View) -> some View {
        Button(action: action) {
            label()
                .frame(width: 44, height: 44)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .position(point)
    }

    /// Earned urgency: events stay in the operational blue unless the payload itself says
    /// warning (amber) or a real breach (coral for critical only).
    private func eventColor(_ event: FlowTimelineEvent) -> Color {
        switch event.tier {
        case "warning": return Z.status(.warning)
        case "critical": return Z.status(.critical)
        default: return Z.primary
        }
    }

    private func x(for date: Date?, width: CGFloat) -> CGFloat {
        guard let date else { return 0 }
        let span = max(store.toDate.timeIntervalSince(store.fromDate), 1)
        let fraction = min(max(date.timeIntervalSince(store.fromDate) / span, 0), 1)
        return CGFloat(fraction) * width
    }
}
