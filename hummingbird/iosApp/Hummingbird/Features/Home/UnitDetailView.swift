import SwiftUI

/// Per-unit detail: a prominent occupancy gauge + full bed breakdown + the unit's active
/// items (open barriers / inbound requests) + a deep link back to the web bed-tracking
/// surface. Reads a live CensusUnit, so it updates in place as the census auto-refreshes.
struct UnitDetailView: View {
    let unit: CensusUnit
    let webLink: String?
    @EnvironmentObject var auth: AuthStore
    @Environment(\.openURL) private var openURL
    @State private var activeItems: [ForYouItem] = []

    private let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)

    private var status: CapacityStatus { unit.capacity }
    /// Whether this unit has an acuity-adjusted safe-capacity baseline. Live data often
    /// doesn't (safeCapacity == 0) → we show occupancy without a misleading ratio.
    private var hasCapacity: Bool { unit.safeCapacity > 0 }
    private var fraction: Double { hasCapacity ? Double(unit.occupied) / Double(unit.safeCapacity) : 0 }
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
                        row("Safe capacity", hasCapacity ? "\(unit.safeCapacity)" : "—")
                        divider
                        row("Staffed beds", "\(unit.staffedBedCount)")
                        if hasCapacity && unit.bedNeed > 0 {
                            divider
                            row("Over safe capacity", "\(unit.bedNeed)", emphasize: true)
                        }
                    }
                }

                activeItemsSection

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

                Text(hasCapacity
                     ? "Safe capacity is acuity-adjusted. Bed need = occupied − safe capacity."
                     : "No acuity-adjusted safe capacity is set for this unit, so occupancy is shown without a ratio.")
                    .font(.system(size: 11))
                    .foregroundStyle(Z.inkMuted)
                    .multilineTextAlignment(.center)
            }
            .padding(Z.s4)
        }
        .background(Z.bg)
        .navigationTitle(unit.name)
        .navigationBarTitleDisplayMode(.inline)
        .task {
            // The unit's slice of the For You queue (open barriers / inbound requests tied to
            // this unit). Best-effort; the rest of the screen stands on its own if it fails.
            if let items = try? await api.forYou(bearer: auth.accessToken ?? "") {
                activeItems = items.filter { $0.unit == unit.name }
            }
        }
    }

    @ViewBuilder
    private var activeItemsSection: some View {
        if !activeItems.isEmpty {
            VStack(alignment: .leading, spacing: Z.s3) {
                Text("Active on this unit")
                    .font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
                ForEach(activeItems) { ForYouRow(item: $0, navigable: false) }
            }
        }
    }

    private var gauge: some View {
        ZStack {
            Circle().stroke(Z.border, lineWidth: 14)
            if hasCapacity {
                Circle()
                    .trim(from: 0, to: min(1.0, fraction))
                    .stroke(Z.status(status), style: StrokeStyle(lineWidth: 14, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                    .animation(.easeInOut(duration: 0.5), value: fraction)
            }
            if hasCapacity {
                VStack(spacing: 2) {
                    Text("\(pct)%")
                        .font(.system(size: 44, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.ink)
                    Text("\(unit.occupied) / \(unit.safeCapacity) safe")
                        .font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                }
            } else {
                VStack(spacing: 2) {
                    Text("\(unit.occupied)")
                        .font(.system(size: 44, weight: .semibold)).monospacedDigit()
                        .foregroundStyle(Z.ink)
                    Text("occupied").font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                    Text("no safe-capacity data")
                        .font(.system(size: 10)).foregroundStyle(Z.inkMuted)
                }
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
