import SwiftUI

/// The 48h census/occupancy curve (§8 P6/P9/P10), sharing the Chronobar's time domain
/// [now−24h, now+24h]: a solid past line summed from the hourly per-unit snapshots, a
/// dashed `predicted_census` line ahead with a soft band ribbon (band.lower/upper), a
/// `now` tick, a scrub-time tick, a staffed-capacity reference line, and optional
/// shift-boundary detents. Persona overlays: staffing-gap step markers (P10) and a
/// surge-probability marker (P6/P9) — both tappable into the standard provenance detail.
struct FlowCurveView: View {
    @ObservedObject var store: FlowWindowStore
    /// Client-side spatial filter: nil = whole scope (sum every unit); else sum these units
    /// only. Snapshots and predicted_census are per-unit, so no re-fetch is needed.
    var unitIds: Set<Int>? = nil
    var showShiftDetents = true
    /// `staffing_shift_gap` projections drawn as step markers at their `t` (P10).
    var gapSteps: [FlowProjection] = []
    /// `surge_probability` drawn as a marker at its `t` (P6/P9).
    var surgeMarker: FlowProjection? = nil
    /// The detent to emphasize (P10: the next shift boundary — "tonight's exposure").
    var emphasizedDetent: Date? = nil
    var onSelect: ((FlowSelection) -> Void)? = nil

    private let chartHeight: CGFloat = 148
    private let topPad: CGFloat = 14

    var body: some View {
        let past = pastPoints
        let future = futurePoints
        if past.count <= 1 && future.count <= 1 {
            // No series either side — say so instead of drawing an empty instrument.
            Text("No census series in this window yet.")
                .font(.system(size: 12))
                .foregroundStyle(Z.inkMuted)
                .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
        } else {
            VStack(alignment: .leading, spacing: Z.s2) {
                GeometryReader { geo in
                    ZStack(alignment: .topLeading) {
                        chartCanvas(past: past, future: future, size: geo.size)
                        markerOverlay(size: geo.size)
                    }
                }
                .frame(height: chartHeight)
                legend(future: future)
            }
            .accessibilityElement(children: .contain)
            .accessibilityLabel(accessibilityText(past: past, future: future))
        }
    }

    // MARK: Series

    private struct Point {
        let t: Date
        let v: Double
    }

    private struct BandPoint {
        let t: Date
        let v: Double
        let lower: Double?
        let upper: Double?
    }

    private func included(_ unitId: Int?) -> Bool {
        guard let unitIds else { return true }
        guard let unitId else { return false }
        return unitIds.contains(unitId)
    }

    /// Past half: per-unit hourly checkpoints summed per timestamp; anchored at now with the
    /// live rollup so the line always meets the present. Prefers per-unit rows; falls back to
    /// scope-total rows (unit_id null) so both server shapes sum once, never twice.
    private var pastPoints: [Point] {
        let snapshots = store.window?.snapshots ?? []
        let unitRows = snapshots.filter { $0.unitId != nil }
        let source = unitRows.isEmpty ? snapshots : unitRows
        var byTime: [Date: Double] = [:]
        for snap in source {
            guard let t = snap.time, t <= store.nowDate, included(snap.unitId) else { continue }
            byTime[t, default: 0] += Double(snap.occupied ?? 0)
        }
        var points = byTime.map { Point(t: $0.key, v: $0.value) }.sorted { $0.t < $1.t }
        if let live = liveOccupied { points.append(Point(t: store.nowDate, v: Double(live))) }
        return points
    }

    /// Future half: predicted_census (2h steps) summed per timestamp, band summed the same
    /// way; anchored at now on the live occupancy so the dashed line continues the solid one.
    private var futurePoints: [BandPoint] {
        let all = (store.window?.projections ?? []).filter { $0.kind == "predicted_census" }
        let unitRows = all.filter { $0.unitId != nil }
        let source = unitRows.isEmpty ? all : unitRows
        struct Acc { var v = 0.0; var lower: Double?; var upper: Double? }
        var byTime: [Date: Acc] = [:]
        for projection in source {
            guard let t = projection.time, t >= store.nowDate, included(projection.unitId) else { continue }
            var acc = byTime[t] ?? Acc()
            acc.v += projection.value ?? 0
            if let lo = projection.band?.lower { acc.lower = (acc.lower ?? 0) + lo }
            if let up = projection.band?.upper { acc.upper = (acc.upper ?? 0) + up }
            byTime[t] = acc
        }
        var points = byTime
            .map { BandPoint(t: $0.key, v: $0.value.v, lower: $0.value.lower, upper: $0.value.upper) }
            .sorted { $0.t < $1.t }
        if !points.isEmpty, let live = liveOccupied {
            points.insert(BandPoint(t: store.nowDate, v: Double(live), lower: nil, upper: nil), at: 0)
        }
        return points
    }

    /// Units in the current filter, from the live spaces rollup.
    private var filteredUnits: [FlowUnitRollup] {
        (store.window?.spaces?.floors ?? [])
            .flatMap(\.units)
            .filter { included($0.unitId) }
    }

    private var liveOccupied: Int? {
        let units = filteredUnits
        guard !units.isEmpty else { return nil }
        return units.reduce(0) { $0 + $1.occupied }
    }

    /// The staffed-capacity reference line (sum of staffed beds in the filter).
    private var staffedCapacity: Int? {
        let units = filteredUnits
        guard !units.isEmpty else { return nil }
        return units.reduce(0) { $0 + $1.staffed }
    }

    // MARK: Chart

    private func chartCanvas(past: [Point], future: [BandPoint], size: CGSize) -> some View {
        let staffed = staffedCapacity
        let maxValue = max(
            Double(staffed ?? 0),
            past.map(\.v).max() ?? 0,
            future.map { $0.upper ?? $0.v }.max() ?? 0,
            1)

        return Canvas { context, size in
            let plotHeight = size.height - topPad
            func x(_ date: Date) -> CGFloat { xPosition(for: date, width: size.width) }
            func y(_ value: Double) -> CGFloat {
                topPad + plotHeight * CGFloat(1 - min(max(value / (maxValue * 1.1), 0), 1))
            }

            // Shift detents behind everything.
            if showShiftDetents {
                for boundary in store.shiftBoundaries {
                    let emphasized = emphasizedDetent.map { abs($0.timeIntervalSince(boundary)) < 60 } ?? false
                    var line = Path()
                    line.move(to: CGPoint(x: x(boundary), y: topPad))
                    line.addLine(to: CGPoint(x: x(boundary), y: size.height))
                    context.stroke(line, with: .color(Z.inkMuted.opacity(emphasized ? 0.7 : 0.25)),
                                   lineWidth: emphasized ? 1.5 : 1)
                    if emphasized {
                        context.draw(
                            Text(Self.hourText(boundary))
                                .font(.system(size: 9, weight: .semibold)).monospacedDigit()
                                .foregroundStyle(Z.inkMuted),
                            at: CGPoint(x: x(boundary), y: 5), anchor: .center)
                    }
                }
            }

            // Forecast band — a soft ribbon, never a solid alarm fill.
            let banded = future.filter { $0.lower != nil && $0.upper != nil }
            if banded.count > 1 {
                var ribbon = Path()
                ribbon.move(to: CGPoint(x: x(banded[0].t), y: y(banded[0].upper ?? banded[0].v)))
                for point in banded.dropFirst() {
                    ribbon.addLine(to: CGPoint(x: x(point.t), y: y(point.upper ?? point.v)))
                }
                for point in banded.reversed() {
                    ribbon.addLine(to: CGPoint(x: x(point.t), y: y(point.lower ?? point.v)))
                }
                ribbon.closeSubpath()
                context.fill(ribbon, with: .color(Z.primary.opacity(0.12)))
            }

            // Staffed-capacity reference line.
            if let staffed {
                var capacity = Path()
                capacity.move(to: CGPoint(x: 0, y: y(Double(staffed))))
                capacity.addLine(to: CGPoint(x: size.width, y: y(Double(staffed))))
                context.stroke(capacity, with: .color(Z.inkMuted.opacity(0.6)),
                               style: StrokeStyle(lineWidth: 1, dash: [2, 3]))
                context.draw(
                    Text("staffed \(staffed)")
                        .font(.system(size: 9, weight: .medium)).monospacedDigit()
                        .foregroundStyle(Z.inkMuted),
                    at: CGPoint(x: size.width - 4, y: y(Double(staffed)) - 7), anchor: .trailing)
            }

            // Past line — solid.
            if past.count > 1 {
                var line = Path()
                line.move(to: CGPoint(x: x(past[0].t), y: y(past[0].v)))
                for point in past.dropFirst() {
                    line.addLine(to: CGPoint(x: x(point.t), y: y(point.v)))
                }
                context.stroke(line, with: .color(Z.primary), lineWidth: 2)
            }

            // Future line — dashed ghost.
            if future.count > 1 {
                var line = Path()
                line.move(to: CGPoint(x: x(future[0].t), y: y(future[0].v)))
                for point in future.dropFirst() {
                    line.addLine(to: CGPoint(x: x(point.t), y: y(point.v)))
                }
                context.stroke(line, with: .color(Z.primary.opacity(0.7)),
                               style: StrokeStyle(lineWidth: 1.5, dash: [4, 3]))
            }

            // `now` tick and the scrub-time tick (matches the Chronobar/lanes indicators).
            var nowTick = Path()
            nowTick.move(to: CGPoint(x: x(store.nowDate), y: topPad))
            nowTick.addLine(to: CGPoint(x: x(store.nowDate), y: size.height))
            context.stroke(nowTick, with: .color(Z.ink.opacity(0.8)), lineWidth: 1.5)

            var scrubTick = Path()
            scrubTick.move(to: CGPoint(x: x(store.t), y: topPad))
            scrubTick.addLine(to: CGPoint(x: x(store.t), y: size.height))
            context.stroke(scrubTick, with: .color(Z.inkMuted.opacity(0.6)), lineWidth: 1)
        }
    }

    // MARK: Markers (44pt tap targets, SwiftUI-overlaid on the canvas)

    @ViewBuilder
    private func markerOverlay(size: CGSize) -> some View {
        // Gap steps grouped per timestamp: the marker shows the summed exposure at that
        // boundary; tapping selects the worst single projection (its provenance chip).
        ForEach(groupedGapSteps, id: \.t) { group in
            gapMarker(group, size: size)
        }
        if let surge = surgeMarker, let t = surge.time {
            surgeMarkerView(surge, at: t, size: size)
        }
    }

    private struct GapGroup {
        let t: Date
        let total: Int
        let worst: FlowProjection
    }

    private var groupedGapSteps: [GapGroup] {
        let dated = gapSteps.compactMap { projection -> (Date, FlowProjection)? in
            guard let t = projection.time else { return nil }
            return (t, projection)
        }
        return Dictionary(grouping: dated, by: \.0).compactMap { t, rows -> GapGroup? in
            let projections = rows.map(\.1)
            let total = projections.reduce(0) { $0 + ($1.gapHeadcount ?? 0) }
            guard let worst = projections.max(by: { ($0.gapHeadcount ?? 0) < ($1.gapHeadcount ?? 0) }) else { return nil }
            return GapGroup(t: t, total: total, worst: worst)
        }
        .sorted { $0.t < $1.t }
    }

    private func gapMarker(_ group: GapGroup, size: CGSize) -> some View {
        // Positive gap = uncovered headcount → amber (a real staffing exposure, not coral).
        let short = group.total > 0
        let tint = short ? Z.status(.warning) : Z.inkMuted
        return Button {
            onSelect?(.projection(group.worst))
        } label: {
            VStack(spacing: 2) {
                Text(short ? "short \(group.total)" : "covered")
                    .font(.system(size: 9, weight: .semibold)).monospacedDigit()
                    .foregroundStyle(tint)
                Rectangle()
                    .fill(tint)
                    .frame(width: 2, height: 10)
            }
            .frame(width: 44, height: 44, alignment: .bottom)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .position(x: xPosition(for: group.t, width: size.width), y: size.height - 22)
        .accessibilityLabel("\(group.worst.label ?? "Staffing gap"), short \(group.total) at \(Self.hourText(group.t))")
    }

    private func surgeMarkerView(_ surge: FlowProjection, at t: Date, size: CGSize) -> some View {
        Button {
            onSelect?(.projection(surge))
        } label: {
            HStack(spacing: 3) {
                Image(systemName: "waveform.path.ecg")
                    .font(.system(size: 9, weight: .semibold))
                Text("surge \(Int(surge.value ?? 0))%")
                    .font(.system(size: 9, weight: .semibold)).monospacedDigit()
            }
            .foregroundStyle(Z.inkMuted)
            .padding(.horizontal, Z.s2)
            .padding(.vertical, 3)
            .background(Capsule().fill(Z.bg))
            .overlay(Capsule().strokeBorder(
                Z.inkMuted.opacity(surge.confidenceLevel.ghostOpacity),
                style: StrokeStyle(lineWidth: 1, dash: [3, 2])))
            .frame(minWidth: 44, minHeight: 44)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .position(x: min(max(xPosition(for: t, width: size.width), 40), size.width - 40), y: topPad + 8)
        .accessibilityLabel("\(surge.label ?? "Surge probability"), \(Int(surge.value ?? 0)) percent, \(surge.confidence)")
    }

    // MARK: Legend

    private func legend(future: [BandPoint]) -> some View {
        HStack(spacing: Z.s3) {
            HStack(spacing: 4) {
                Rectangle().fill(Z.primary).frame(width: 12, height: 2)
                Text("Last 24 hours").font(.system(size: 10)).foregroundStyle(Z.inkMuted)
            }
            if future.count > 1 {
                HStack(spacing: 4) {
                    Line(dash: [3, 2]).stroke(Z.primary.opacity(0.7), style: StrokeStyle(lineWidth: 1.5, dash: [3, 2]))
                        .frame(width: 12, height: 2)
                    Text("Next 24 hours").font(.system(size: 10)).foregroundStyle(Z.inkMuted)
                }
                if let source = forecastSource {
                    Spacer()
                    Text("Source: \(source)")
                        .font(.system(size: 9, weight: .medium)).monospacedDigit()
                        .foregroundStyle(Z.inkMuted)
                        .lineLimit(1)
                }
            }
        }
    }

    /// The forecast half's provenance (defensible-by-default: the ribbon cites its service).
    private var forecastSource: String? {
        (store.window?.projections ?? [])
            .first { $0.kind == "predicted_census" }?
            .provenance?.service
    }

    // MARK: Geometry & copy

    private func xPosition(for date: Date, width: CGFloat) -> CGFloat {
        let span = max(store.toDate.timeIntervalSince(store.fromDate), 1)
        let fraction = min(max(date.timeIntervalSince(store.fromDate) / span, 0), 1)
        return CGFloat(fraction) * width
    }

    private static func hourText(_ date: Date) -> String {
        let f = DateFormatter()
        f.dateFormat = "HH:mm"
        return f.string(from: date)
    }

    private func accessibilityText(past: [Point], future: [BandPoint]) -> String {
        var parts: [String] = ["48 hour occupancy curve"]
        if let now = liveOccupied { parts.append("now \(now) occupied") }
        if let staffed = staffedCapacity { parts.append("\(staffed) staffed") }
        if let peak = future.map(\.v).max() { parts.append("projected up to \(Int(peak))") }
        return parts.joined(separator: ", ")
    }
}

/// A short horizontal line segment for legends.
private struct Line: Shape {
    var dash: [CGFloat] = []

    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.minX, y: rect.midY))
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.midY))
        return p
    }
}
