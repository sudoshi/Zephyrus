import SwiftUI

/// Floor number → slab bounds, published so overlay layers (transport route arcs) can
/// anchor endpoints to the exact slab rows without duplicating stack layout math.
struct FloorSlabAnchorKey: PreferenceKey {
    static var defaultValue: [Int: Anchor<CGRect>] = [:]
    static func reduce(value: inout [Int: Anchor<CGRect>], nextValue: () -> [Int: Anchor<CGRect>]) {
        value.merge(nextValue()) { $1 }
    }
}

/// House scope: an exploded axonometric stack of floor slabs (D4). Each floor is a
/// sheared parallelogram filled with the same neutral occupancy heat the plate view uses,
/// labeled with its occupied/staffed count. Tapping a floor descends into FloorPlateView.
struct HouseStackView: View {
    /// Census rollups from the window payload (heat + counts).
    let rollups: [FlowFloorRollup]
    /// The plates asset — floors with geometry that can be descended into.
    let floorsDocument: FlowFloorsDocument?
    /// Floor → worst staffing-gap headcount (P10 lens): tints the slab amber and adds a
    /// "short N" word so the gap is never color alone. Empty for every other lens.
    var gapByFloor: [Int: Int] = [:]
    let onDescend: (Int) -> Void

    private let shear: CGFloat = 26
    private let slabHeight: CGFloat = 34

    /// Union of operational floors (rollups) and geometry floors, drawn bottom-up
    /// (highest floor at the top of the stack).
    private var floorNumbers: [Int] {
        let fromRollups = rollups.map(\.floor)
        let fromGeometry = floorsDocument?.floors.map(\.floor) ?? []
        return Array(Set(fromRollups).union(fromGeometry)).sorted(by: >)
    }

    var body: some View {
        VStack(spacing: Z.s2) {
            ForEach(floorNumbers, id: \.self) { number in
                slabRow(number)
            }
        }
    }

    private func slabRow(_ number: Int) -> some View {
        let rollup = rollups.first { $0.floor == number }
        let gap = gapByFloor[number] ?? 0
        return Button {
            onDescend(number)
        } label: {
            ZStack {
                SlabShape(shear: shear)
                    .fill(gap > 0
                          ? Z.status(.warning).opacity(gapOpacity(gap))
                          : Z.primary.opacity(heatOpacity(rollup)))
                SlabShape(shear: shear)
                    .stroke(Z.border, lineWidth: 1)
                HStack(spacing: Z.s2) {
                    Text(rollup?.label ?? floorLabel(number))
                        .font(.system(size: 13, weight: .medium))
                        .foregroundStyle(Z.ink)
                    Spacer()
                    if gap > 0 {
                        Text("short \(gap)")
                            .font(.system(size: 11, weight: .semibold))
                            .monospacedDigit()
                            .foregroundStyle(Z.status(.warning))
                    }
                    if let rollup {
                        Text("\(rollup.occupied)/\(rollup.staffed)")
                            .font(.system(size: 13, weight: .semibold))
                            .monospacedDigit()
                            .foregroundStyle(Z.ink)
                        Text("occupied")
                            .font(.system(size: 11))
                            .foregroundStyle(Z.inkMuted)
                    } else {
                        Text("no census")
                            .font(.system(size: 11))
                            .foregroundStyle(Z.inkMuted)
                    }
                    Image(systemName: "chevron.right")
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(Z.inkMuted)
                }
                .padding(.leading, shear + Z.s3)
                .padding(.trailing, Z.s3)
            }
            .frame(height: slabHeight)
            .contentShape(SlabShape(shear: shear))
        }
        .buttonStyle(.plain)
        .anchorPreference(key: FloorSlabAnchorKey.self, value: .bounds) { [number: $0] }
        .accessibilityLabel(accessibilityText(number, rollup, gap: gap))
    }

    /// Same neutral heat ramp as FloorPlateView: 0% → 0.10, 100% → 0.45. Never coral.
    private func heatOpacity(_ rollup: FlowFloorRollup?) -> Double {
        let fraction = min(max((rollup?.occupancyPct ?? 0) / 100, 0), 1)
        return 0.10 + 0.35 * fraction
    }

    /// Coverage-gap tint (P10): amber scaled by the worst shortfall, capped well below an
    /// alarm read — the gap is a planning exposure, not a live breach.
    private func gapOpacity(_ gap: Int) -> Double {
        min(0.12 + 0.07 * Double(gap), 0.38)
    }

    private func floorLabel(_ number: Int) -> String {
        floorsDocument?.floors.first { $0.floor == number }?.label ?? "Floor \(number)"
    }

    private func accessibilityText(_ number: Int, _ rollup: FlowFloorRollup?, gap: Int) -> String {
        var text: String
        if let rollup {
            text = "\(rollup.label), \(rollup.occupied) of \(rollup.staffed) beds occupied"
        } else {
            text = "\(floorLabel(number)), no census"
        }
        if gap > 0 { text += ", short \(gap) staff at the worst shift" }
        return text
    }
}

/// A parallelogram slab — the axonometric top face of one floor plate.
struct SlabShape: Shape {
    let shear: CGFloat

    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + shear, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - shear, y: rect.maxY))
        p.addLine(to: CGPoint(x: rect.minX, y: rect.maxY))
        p.closeSubpath()
        return p
    }
}
