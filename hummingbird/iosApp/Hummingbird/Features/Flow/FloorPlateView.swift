import SwiftUI

/// A same-floor transport route in plan feet, styled by the trip's state (P1 lens).
struct FlowPlanRoute: Identifiable {
    let id: String
    let from: CGPoint
    let to: CGPoint
    let ghost: Bool
    let opacity: Double
    let warning: Bool
}

/// A turn marker on a bed plate (P2 lens): a solid tick for a past evs_status, a dashed
/// ghost outline + due-time chip for an upcoming evs_due.
struct FlowTurnMark: Identifiable {
    let id: String
    let bedId: Int
    let ghost: Bool
    let opacity: Double
    let timeText: String?
    let isolation: Bool
    let warning: Bool
    let selection: FlowSelection
}

/// One floor's 2.5D plate map (D4): unit/zone plates filled with a neutral occupancy heat
/// (Z.primary at 0.10–0.45 opacity — occupancy is capacity information, never urgency, so
/// no red/coral here), rooms/bays as subtle outlines, beds as small outline rects, and
/// projected ghosts as dashed confidence-mapped outlines on their bed's rect.
struct FloorPlateView: View {
    let floor: FlowFloor
    /// unitId → live rollup (heat + counts). From window.spaces.floors.
    let unitRollups: [Int: FlowUnitRollup]
    /// Ghosts accumulated up to the scrub time (already filtered by the store).
    let ghosts: [FlowProjection]
    let selection: FlowSelection?
    /// bed_id → current state (turn-map tint). Now-only data: callers fade it via
    /// `bedStateOpacity` when the scrub time leaves now.
    var bedStates: [Int: FlowBedStatus] = [:]
    var bedStateOpacity: Double = 1
    var turnMarks: [FlowTurnMark] = []
    var routes: [FlowPlanRoute] = []
    let onSelect: (FlowSelection) -> Void

    var body: some View {
        GeometryReader { geo in
            Canvas { context, size in
                let transform = PlateTransform(bounds: floor.bounds, size: size)
                draw(in: &context, transform: transform)
            }
            .contentShape(Rectangle())
            .onTapGesture(coordinateSpace: .local) { location in
                handleTap(at: location, size: geo.size)
            }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(floor.label) floor map, \(floor.spaces.count) spaces")
    }

    // MARK: Drawing

    private func draw(in context: inout GraphicsContext, transform: PlateTransform) {
        // Back-to-front: unit/zone heat → corridors → rooms/bays → beds → ghosts → selection.
        for plate in plates(of: ["unit", "zone"]) {
            let rect = transform.rect(plate.rect)
            let path = Path(roundedRect: rect, cornerRadius: 4)
            context.fill(path, with: .color(Z.primary.opacity(heatOpacity(for: plate))))
            context.stroke(path, with: .color(Z.border), lineWidth: 1)
            if let title = plate.unitId.flatMap({ unitRollups[$0]?.abbr }) ?? plate.label {
                context.draw(
                    Text(title)
                        .font(.system(size: 10, weight: .medium))
                        .foregroundStyle(Z.inkMuted),
                    at: CGPoint(x: rect.minX + 6, y: rect.minY + 8),
                    anchor: .leading)
            }
        }

        for plate in plates(of: ["corridor", "vertical_transport"]) {
            let path = Path(roundedRect: transform.rect(plate.rect), cornerRadius: 2)
            context.fill(path, with: .color(Z.inkMuted.opacity(0.10)))
        }

        for plate in plates(of: ["room", "bay"]) {
            let path = Path(roundedRect: transform.rect(plate.rect), cornerRadius: 2)
            context.stroke(path, with: .color(Z.border.opacity(0.9)), lineWidth: 1)
        }

        for plate in plates(of: ["bed"]) {
            let path = Path(roundedRect: transform.rect(plate.rect), cornerRadius: 1.5)
            context.stroke(path, with: .color(Z.border), lineWidth: 1)
        }

        drawBedStates(in: &context, transform: transform)
        drawRoutes(in: &context, transform: transform)

        // Ghost overlays: dashed 1.5pt rounded-rect outlines on the bed's rect, opacity by
        // confidence, ink-colored — never a solid fill, never a status color.
        for (projection, plate) in ghostPlates() {
            let rect = ghostRect(transform.rect(plate.rect))
            let path = Path(roundedRect: rect, cornerRadius: 3)
            context.stroke(
                path,
                with: .color(Z.ink.opacity(projection.confidenceLevel.ghostOpacity)),
                style: StrokeStyle(lineWidth: 1.5, dash: [4, 3]))
        }

        drawTurnMarks(in: &context, transform: transform)

        // Gold selection ring (focus layer, not a status color).
        if let selectedRect = selectedRect(transform: transform) {
            let path = Path(roundedRect: selectedRect.insetBy(dx: -2, dy: -2), cornerRadius: 4)
            context.stroke(path, with: .color(Z.gold), lineWidth: 2)
        }
    }

    private func plates(of categories: Set<String>) -> [FlowPlate] {
        floor.spaces.filter { categories.contains($0.category) }
    }

    /// Turn-map tints (now-only bed state): dirty = warning fill, blocked = muted outline,
    /// occupied = neutral fill, available = faint success. Tap detail carries the status
    /// word, so state is never color alone.
    private func drawBedStates(in context: inout GraphicsContext, transform: PlateTransform) {
        guard !bedStates.isEmpty, bedStateOpacity > 0 else { return }
        for plate in plates(of: ["bed"]) {
            guard let bedId = plate.bedId, let state = bedStates[bedId] else { continue }
            let rect = ghostRect(transform.rect(plate.rect)).insetBy(dx: 2, dy: 2)
            let path = Path(roundedRect: rect, cornerRadius: 2)
            switch state.status {
            case "dirty":
                context.fill(path, with: .color(Z.status(.warning).opacity(0.30 * bedStateOpacity)))
                context.stroke(path, with: .color(Z.status(.warning).opacity(0.6 * bedStateOpacity)), lineWidth: 1)
            case "blocked":
                context.stroke(path, with: .color(Z.inkMuted.opacity(0.7 * bedStateOpacity)), lineWidth: 1.5)
            case "occupied":
                context.fill(path, with: .color(Z.primary.opacity(0.20 * bedStateOpacity)))
            case "available":
                context.fill(path, with: .color(Z.status(.success).opacity(0.12 * bedStateOpacity)))
            default:
                break
            }
        }
    }

    /// Same-floor transport arcs: quadratic curves between plate centroids, solid for real
    /// trips (warning tint for STAT), dashed at confidence opacity for ghosts.
    private func drawRoutes(in context: inout GraphicsContext, transform: PlateTransform) {
        for route in routes {
            let start = transform.point(route.from)
            let end = transform.point(route.to)
            let color = route.warning ? Z.status(.warning) : (route.ghost ? Z.ink : Z.primary)
            let shading = GraphicsContext.Shading.color(color.opacity(route.opacity))
            var path = Path()
            path.move(to: start)
            path.addQuadCurve(to: end, control: CGPoint(x: (start.x + end.x) / 2,
                                                        y: min(start.y, end.y) - 24))
            context.stroke(path, with: shading, style: route.ghost
                ? StrokeStyle(lineWidth: 1.5, dash: [4, 3])
                : StrokeStyle(lineWidth: 1.5))
            let dot = CGRect(x: end.x - 3, y: end.y - 3, width: 6, height: 6)
            if route.ghost {
                context.stroke(Path(ellipseIn: dot), with: shading, lineWidth: 1.5)
            } else {
                context.fill(Path(ellipseIn: dot), with: shading)
            }
        }
    }

    /// Turn markers: solid tick on the bed for past turn events; dashed outline + due-time
    /// chip for upcoming turns; "ISO" chip when the turn is an isolation clean.
    private func drawTurnMarks(in context: inout GraphicsContext, transform: PlateTransform) {
        let beds = plates(of: ["bed"])
        for mark in turnMarks {
            guard let plate = beds.first(where: { $0.bedId == mark.bedId }) else { continue }
            let rect = ghostRect(transform.rect(plate.rect))
            let color = mark.warning || mark.isolation ? Z.status(.warning) : (mark.ghost ? Z.ink : Z.primary)
            if mark.ghost {
                context.stroke(Path(roundedRect: rect, cornerRadius: 3),
                               with: .color(color.opacity(mark.opacity)),
                               style: StrokeStyle(lineWidth: 1.5, dash: [4, 3]))
                if let timeText = mark.timeText {
                    context.draw(
                        Text(timeText)
                            .font(.system(size: 9, weight: .medium)).monospacedDigit()
                            .foregroundStyle(Z.inkMuted),
                        at: CGPoint(x: rect.midX, y: rect.minY - 7), anchor: .center)
                }
            } else {
                // Solid tick — a small filled notch on the bed's corner.
                let tick = CGRect(x: rect.maxX - 5, y: rect.minY - 1, width: 6, height: 6)
                context.fill(Path(ellipseIn: tick), with: .color(color.opacity(mark.opacity)))
            }
            if mark.isolation {
                context.draw(
                    Text("ISO")
                        .font(.system(size: 8, weight: .semibold))
                        .foregroundStyle(Z.status(.warning)),
                    at: CGPoint(x: rect.minX - 2, y: rect.midY), anchor: .trailing)
            }
        }
    }

    /// Neutral occupancy heat: 0% → 0.10, 100% → 0.45.
    private func heatOpacity(for plate: FlowPlate) -> Double {
        let pct = plate.unitId.flatMap { unitRollups[$0]?.occupancyPct } ?? 0
        let fraction = min(max(pct / 100, 0), 1)
        return 0.10 + 0.35 * fraction
    }

    /// Ghosts joined to this floor's bed plates.
    private func ghostPlates() -> [(FlowProjection, FlowPlate)] {
        let beds = plates(of: ["bed"])
        return ghosts.compactMap { projection in
            guard let bedId = projection.bedId,
                  let plate = beds.first(where: { $0.bedId == bedId }) else { return nil }
            return (projection, plate)
        }
    }

    /// Beds are tiny in plan feet; give the ghost outline a legible minimum size.
    private func ghostRect(_ rect: CGRect) -> CGRect {
        let minSide: CGFloat = 12
        let w = max(rect.width + 6, minSide)
        let h = max(rect.height + 6, minSide)
        return CGRect(x: rect.midX - w / 2, y: rect.midY - h / 2, width: w, height: h)
    }

    private func selectedRect(transform: PlateTransform) -> CGRect? {
        switch selection {
        case .plate(let plate) where floor.spaces.contains(plate):
            return transform.rect(plate.rect)
        case .projection(let projection):
            guard let bedId = projection.bedId,
                  let plate = floor.spaces.first(where: { $0.bedId == bedId }) else { return nil }
            return ghostRect(transform.rect(plate.rect))
        default:
            return nil
        }
    }

    // MARK: Hit testing

    /// Taps resolve to the nearest plate so the effective target is always ≥ 44pt even for
    /// tiny bed rects. A turn mark or ghost on the tapped bed wins over the bare plate.
    private func handleTap(at location: CGPoint, size: CGSize) {
        let transform = PlateTransform(bounds: floor.bounds, size: size)
        guard let plate = hitPlate(at: location, transform: transform) else { return }
        if let bedId = plate.bedId, let mark = turnMarks.first(where: { $0.bedId == bedId }) {
            onSelect(mark.selection)
        } else if let bedId = plate.bedId,
                  let ghost = ghosts.first(where: { $0.bedId == bedId }) {
            onSelect(.projection(ghost))
        } else {
            onSelect(.plate(plate))
        }
    }

    private func hitPlate(at location: CGPoint, transform: PlateTransform) -> FlowPlate? {
        // Containing plates first, smallest area wins (bed over room over unit).
        let containing = floor.spaces
            .filter { transform.rect($0.rect).insetBy(dx: -4, dy: -4).contains(location) }
            .sorted { area($0, transform) < area($1, transform) }
        // A tiny plate (bed) near the tap beats a large containing plate — keeps beds tappable.
        let nearestSmall = floor.spaces
            .filter { area($0, transform) < 44 * 44 }
            .map { (plate: $0, d: distance(from: location, to: transform.rect($0.rect))) }
            .filter { $0.d <= 22 }
            .min { $0.d < $1.d }?
            .plate
        return nearestSmall ?? containing.first
    }

    private func area(_ plate: FlowPlate, _ transform: PlateTransform) -> CGFloat {
        let r = transform.rect(plate.rect)
        return r.width * r.height
    }

    private func distance(from point: CGPoint, to rect: CGRect) -> CGFloat {
        let dx = max(rect.minX - point.x, 0, point.x - rect.maxX)
        let dy = max(rect.minY - point.y, 0, point.y - rect.maxY)
        return sqrt(dx * dx + dy * dy)
    }
}

/// Maps plan-view feet (top-left-origin rects) into view points, fitted and centered.
struct PlateTransform {
    let scale: CGFloat
    let offset: CGPoint
    let origin: CGPoint

    init(bounds: [Double], size: CGSize, padding: CGFloat = 12) {
        let bx = CGFloat(bounds.count > 0 ? bounds[0] : 0)
        let by = CGFloat(bounds.count > 1 ? bounds[1] : 0)
        let bw = CGFloat(bounds.count > 2 ? bounds[2] : 1)
        let bh = CGFloat(bounds.count > 3 ? bounds[3] : 1)
        let fitW = max(size.width - padding * 2, 1)
        let fitH = max(size.height - padding * 2, 1)
        scale = min(fitW / max(bw, 1), fitH / max(bh, 1))
        origin = CGPoint(x: bx, y: by)
        offset = CGPoint(
            x: (size.width - bw * scale) / 2,
            y: (size.height - bh * scale) / 2)
    }

    func rect(_ raw: [Double]) -> CGRect {
        guard raw.count >= 4 else { return .zero }
        return CGRect(
            x: (CGFloat(raw[0]) - origin.x) * scale + offset.x,
            y: (CGFloat(raw[1]) - origin.y) * scale + offset.y,
            width: CGFloat(raw[2]) * scale,
            height: CGFloat(raw[3]) * scale)
    }

    func point(_ plan: CGPoint) -> CGPoint {
        CGPoint(x: (plan.x - origin.x) * scale + offset.x,
                y: (plan.y - origin.y) * scale + offset.y)
    }
}
