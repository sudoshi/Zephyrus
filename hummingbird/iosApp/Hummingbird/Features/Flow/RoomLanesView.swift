import SwiftUI

/// The OR lens primary layer — P4 "My room's day" / P7 "All rooms, the cascade": one lane
/// per room aligned to the Chronobar's 48h span. Case bars run scheduled start → end,
/// solid once started, dashed ghosts at confidence opacity ahead of now. When a case's end
/// overlaps the next case's scheduled start, the next bar slides to the predecessor's end
/// and carries a "+Xm" drift chip (the cascade). Milestone ticks mark in-room / procedure /
/// PACU transitions; PACU collects into a bottom lane. The payload carries no patient
/// identity for these lenses — rooms and procedures only.
struct RoomLanesView: View {
    @ObservedObject var store: FlowWindowStore
    let persona: String
    let onSelect: (FlowSelection) -> Void

    @State private var focusedRoom: String?

    private let laneHeight: CGFloat = 34
    private let labelWidth: CGFloat = 52

    var body: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            if persona == "or_nurse", rooms.count > 1 {
                roomPicker
            }
            if rooms.isEmpty && pacuMilestones.isEmpty {
                Text("No cases on the schedule in this window.")
                    .font(.system(size: 13))
                    .foregroundStyle(Z.inkMuted)
            } else {
                ForEach(rooms, id: \.self) { room in
                    laneRow(label: room, bars: bars(for: room), ticks: milestones(in: room))
                        .opacity(rowOpacity(room))
                }
                if !pacuMilestones.isEmpty {
                    laneRow(label: "PACU", bars: [], ticks: pacuMilestones)
                }
            }
        }
    }

    // MARK: Rooms

    /// Rooms come from the data, not a roster: scheduled cases' `room` plus rooms named by
    /// milestones. PACU is a stage, not a room — it gets its own bottom lane.
    private var rooms: [String] {
        var names = Set<String>()
        for projection in store.window?.projections ?? [] where projection.kind == "scheduled_or_case" {
            if let room = projection.room { names.insert(room) }
        }
        for event in store.window?.events ?? [] where event.kind == "or_milestone" {
            if let room = event.toSpace, room != "PACU" { names.insert(room) }
        }
        return names.sorted { $0.localizedStandardCompare($1) == .orderedAscending }
    }

    private func rowOpacity(_ room: String) -> Double {
        guard persona == "or_nurse", let focusedRoom, rooms.count > 1 else { return 1 }
        return room == focusedRoom ? 1 : 0.4
    }

    private var roomPicker: some View {
        Menu {
            ForEach(rooms, id: \.self) { room in
                Button(room) { focusedRoom = room }
            }
        } label: {
            HStack(spacing: Z.s1) {
                Text("Room · \(focusedRoom ?? rooms.first ?? "—")")
                    .font(.system(size: 13, weight: .medium))
                Image(systemName: "chevron.up.chevron.down")
                    .font(.system(size: 10, weight: .semibold))
            }
            .foregroundStyle(Z.primary)
            .frame(minHeight: 44, alignment: .leading)
        }
        .onAppear { if focusedRoom == nil { focusedRoom = rooms.first } }
        .accessibilityLabel("Choose your room")
    }

    // MARK: Case cascade

    private struct CaseBar: Identifiable {
        let projection: FlowProjection
        let start: Date
        let end: Date
        let scheduledStart: Date

        var id: String { projection.id }
        var driftMinutes: Int { max(0, Int(start.timeIntervalSince(scheduledStart) / 60)) }
    }

    /// Cascade drift: within a room, a case whose predecessor runs past its scheduled
    /// start slides to the predecessor's end, keeping its duration.
    private func bars(for room: String) -> [CaseBar] {
        let cases = (store.window?.projections ?? [])
            .filter { $0.kind == "scheduled_or_case" && $0.room == room }
            .compactMap { projection -> (FlowProjection, Date, Date)? in
                guard let start = projection.time else { return nil }
                let end = FlowTime.parse(projection.endsAt) ?? start.addingTimeInterval(3600)
                return (projection, start, max(end, start))
            }
            .sorted { $0.1 < $1.1 }

        var result: [CaseBar] = []
        var cursor = Date.distantPast
        for (projection, scheduledStart, scheduledEnd) in cases {
            let start = max(scheduledStart, cursor)
            let end = start.addingTimeInterval(scheduledEnd.timeIntervalSince(scheduledStart))
            result.append(CaseBar(projection: projection, start: start, end: end, scheduledStart: scheduledStart))
            cursor = end
        }
        return result
    }

    private func milestones(in room: String) -> [FlowTimelineEvent] {
        (store.window?.events ?? []).filter { $0.kind == "or_milestone" && $0.toSpace == room }
    }

    private var pacuMilestones: [FlowTimelineEvent] {
        (store.window?.events ?? []).filter { $0.kind == "or_milestone" && $0.toSpace == "PACU" }
    }

    // MARK: Lane rendering

    private func laneRow(label: String, bars: [CaseBar], ticks: [FlowTimelineEvent]) -> some View {
        HStack(spacing: Z.s2) {
            Text(label)
                .font(.system(size: 11, weight: .medium))
                .foregroundStyle(Z.inkMuted)
                .lineLimit(2)
                .frame(width: labelWidth, alignment: .leading)
            GeometryReader { geo in
                laneTrack(bars: bars, ticks: ticks, width: geo.size.width, height: geo.size.height)
            }
            .frame(height: laneHeight)
        }
    }

    private func laneTrack(bars: [CaseBar], ticks: [FlowTimelineEvent],
                           width: CGFloat, height: CGFloat) -> some View {
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

            ForEach(bars) { bar in
                caseBarView(bar, width: width, midY: midY)
            }

            ForEach(ticks) { event in
                tickButton(event, width: width, midY: midY)
            }
        }
    }

    @ViewBuilder
    private func caseBarView(_ bar: CaseBar, width: CGFloat, midY: CGFloat) -> some View {
        let startX = x(for: bar.start, width: width)
        let endX = x(for: bar.end, width: width)
        let barWidth = max(endX - startX, 8)
        let started = bar.start <= store.nowDate
        let opacity = bar.projection.confidenceLevel.ghostOpacity

        Button {
            onSelect(.projection(bar.projection))
        } label: {
            ZStack(alignment: .topLeading) {
                Group {
                    if started {
                        RoundedRectangle(cornerRadius: 4)
                            .fill(Z.primary.opacity(0.35))
                            .overlay(RoundedRectangle(cornerRadius: 4).strokeBorder(Z.primary, lineWidth: 1))
                    } else {
                        // Ghost grammar: dashed outline at confidence opacity, never a solid fill.
                        RoundedRectangle(cornerRadius: 4)
                            .strokeBorder(Z.ink.opacity(opacity), style: StrokeStyle(lineWidth: 1.5, dash: [4, 3]))
                    }
                }
                .frame(width: barWidth, height: 14)

                if bar.driftMinutes > 0 {
                    Text("+\(OperationalDuration.minutes(Double(bar.driftMinutes), compact: true))")
                        .font(.system(size: 9, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.status(.warning))
                        .offset(y: -12)
                }
            }
            .frame(width: barWidth, height: 44) // full-height touch target around the 14pt bar
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .position(x: startX + barWidth / 2, y: midY)
        .accessibilityLabel(caseAccessibilityText(bar))
    }

    private func tickButton(_ event: FlowTimelineEvent, width: CGFloat, midY: CGFloat) -> some View {
        Button {
            onSelect(.event(event))
        } label: {
            Rectangle()
                .fill(Z.primary)
                .frame(width: 2, height: 12)
                .frame(width: 44, height: 44)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .position(x: x(for: event.time, width: width), y: midY)
        .accessibilityLabel(event.label ?? event.kind)
    }

    private func caseAccessibilityText(_ bar: CaseBar) -> String {
        var parts = [bar.projection.label ?? "Scheduled case"]
        if bar.driftMinutes > 0 {
            parts.append("running \(OperationalDuration.minutes(Double(bar.driftMinutes))) behind schedule")
        }
        return parts.joined(separator: ", ")
    }

    private func x(for date: Date?, width: CGFloat) -> CGFloat {
        guard let date else { return 0 }
        let span = max(store.toDate.timeIntervalSince(store.fromDate), 1)
        let fraction = min(max(date.timeIntervalSince(store.fromDate) / span, 0), 1)
        return CGFloat(fraction) * width
    }
}
