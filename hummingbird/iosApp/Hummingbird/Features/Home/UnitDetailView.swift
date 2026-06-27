import SwiftUI

/// Per-unit detail: a prominent occupancy gauge + full bed breakdown + a deep link back to
/// the web bed-tracking surface (deep analysis stays on the web). Reads a live CensusUnit, so
/// it updates in place as the home census auto-refreshes.
struct UnitDetailView: View {
    let unit: CensusUnit
    let webLink: String?
    @Environment(\.openURL) private var openURL

    private var status: CapacityStatus { unit.capacity }
    private var fraction: Double {
        unit.safeCapacity > 0 ? Double(unit.occupied) / Double(unit.safeCapacity) : 0
    }
    private var pct: Int { Int((fraction * 100).rounded()) }

    var body: some View {
        ScrollView {
            VStack(spacing: Z.s5) {
                gauge
                StatusChip(status: status)

                Panel {
                    VStack(spacing: Z.s3) {
                        row("Occupied", "\(unit.occupied)")
                        divider
                        row("Available", "\(unit.available)")
                        divider
                        row("Blocked / dirty", "\(unit.blocked)")
                        divider
                        row("Safe capacity", "\(unit.safeCapacity)")
                        divider
                        row("Staffed beds", "\(unit.staffedBedCount)")
                        if unit.bedNeed > 0 {
                            divider
                            row("Over safe capacity", "\(unit.bedNeed)", emphasize: true)
                        }
                    }
                }

                if let webLink, let url = URL(string: webLink) {
                    Button { openURL(url) } label: {
                        HStack(spacing: Z.s2) {
                            Image(systemName: "safari")
                            Text("View bed tracking on web").font(.system(size: 15, weight: .semibold))
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, Z.s3)
                        .foregroundStyle(Z.primary)
                        .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
                    }
                }

                Text("Safe capacity is acuity-adjusted. Bed need = occupied − safe capacity.")
                    .font(.system(size: 11))
                    .foregroundStyle(Z.inkMuted)
                    .multilineTextAlignment(.center)
            }
            .padding(Z.s4)
        }
        .background(Z.bg)
        .navigationTitle(unit.name)
        .navigationBarTitleDisplayMode(.inline)
    }

    private var gauge: some View {
        ZStack {
            Circle().stroke(Z.border, lineWidth: 14)
            Circle()
                .trim(from: 0, to: min(1.0, fraction))
                .stroke(Z.status(status), style: StrokeStyle(lineWidth: 14, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .animation(.easeInOut(duration: 0.5), value: fraction)
            VStack(spacing: 2) {
                Text("\(pct)%")
                    .font(.system(size: 44, weight: .semibold)).monospacedDigit()
                    .foregroundStyle(Z.ink)
                Text("\(unit.occupied) / \(unit.safeCapacity) safe")
                    .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
            }
        }
        .frame(width: 190, height: 190)
        .padding(.top, Z.s2)
    }

    private var divider: some View { Divider().overlay(Z.border) }

    private func row(_ label: String, _ value: String, emphasize: Bool = false) -> some View {
        HStack {
            Text(label).font(.system(size: 14)).foregroundStyle(Z.inkMuted)
            Spacer()
            Text(value).font(.system(size: 16, weight: .semibold)).monospacedDigit()
                .foregroundStyle(emphasize ? Z.status(.critical) : Z.ink)
        }
    }
}
