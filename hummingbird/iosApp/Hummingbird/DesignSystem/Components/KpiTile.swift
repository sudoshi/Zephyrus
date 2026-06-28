import SwiftUI

/// A per-unit census tile: a left status stripe, the unit name + status chip, the big
/// occupied/safe metric (tabular figures), and a supporting available/blocked/bed-need line.
/// Mirrors the web KpiTile signature (status stripe + arrow/label, metrics in tabular-nums).
struct KpiTile: View {
    let unit: CensusUnit

    private var status: CapacityStatus { unit.capacity }

    var body: some View {
        HStack(spacing: 0) {
            // 4pt status stripe
            Rectangle()
                .fill(Z.status(status))
                .frame(width: 4)

            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(unit.name)
                            .font(.system(size: 16, weight: .semibold))
                            .foregroundStyle(Z.ink)
                        Text(unit.type.replacingOccurrences(of: "_", with: " ").uppercased())
                            .font(.system(size: 10, weight: .medium))
                            .tracking(0.5)
                            .foregroundStyle(Z.inkMuted)
                    }
                    Spacer()
                    StatusChip(status: status)
                }

                HStack(alignment: .firstTextBaseline, spacing: Z.s2) {
                    Text("\(unit.occupied)")
                        .font(.system(size: 34, weight: .semibold))
                        .monospacedDigit()
                        .foregroundStyle(Z.ink)
                    Text("/ \(unit.safeCapacity) safe beds")
                        .font(.system(size: 13, weight: .regular))
                        .foregroundStyle(Z.inkMuted)
                    Spacer()
                    // Only show "over" when there's an actual safe-capacity baseline to be
                    // over; no-data units (safeCapacity == 0) report occupancy without one.
                    if unit.bedNeed > 0 && unit.safeCapacity > 0 {
                        Label("\(unit.bedNeed) over", systemImage: "arrow.up")
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundStyle(Z.status(.critical))
                            .labelStyle(.titleAndIcon)
                    }
                }

                // Occupancy bar
                GeometryReader { geo in
                    let ratio = unit.safeCapacity > 0 ? min(1.2, Double(unit.occupied) / Double(unit.safeCapacity)) : 0
                    ZStack(alignment: .leading) {
                        Capsule().fill(Z.border)
                        Capsule()
                            .fill(Z.status(status))
                            .frame(width: max(2, geo.size.width * min(1.0, ratio)))
                    }
                }
                .frame(height: 6)

                HStack(spacing: Z.s3) {
                    metric("\(unit.available)", "available")
                    metric("\(unit.blocked)", "blocked/dirty")
                    Spacer()
                }
            }
            .padding(Z.s4)
        }
        .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.surface))
        .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.border, lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: Z.radius, style: .continuous))
        .shadow(color: .black.opacity(0.25), radius: 8, x: 0, y: 2)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(unit.name), \(status.label), \(unit.occupied) of \(unit.safeCapacity) safe beds occupied")
    }

    private func metric(_ value: String, _ label: String) -> some View {
        HStack(spacing: Z.s1) {
            Text(value).font(.system(size: 14, weight: .semibold)).monospacedDigit().foregroundStyle(Z.ink)
            Text(label).font(.system(size: 12)).foregroundStyle(Z.inkMuted)
        }
    }
}
