import SwiftUI
import WidgetKit

/// Home-screen glance: occupancy + pending placements from the last in-app sync.
/// Deliberately calm — one number, one status word, one queue count. Tapping opens
/// the app (default deep link); the widget never shows PHI.
struct HouseGlanceWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: HouseGlanceCache.widgetKind, provider: HouseGlanceProvider()) { entry in
            HouseGlanceView(entry: entry)
                .containerBackground(HBActivityPalette.bg, for: .widget)
        }
        .configurationDisplayName("House at a glance")
        .description("Occupancy and pending placements from your last sync.")
        .supportedFamilies([.systemSmall])
    }
}

struct HouseGlanceEntry: TimelineEntry {
    let date: Date
    let snapshot: HouseGlanceSnapshot?
}

struct HouseGlanceProvider: TimelineProvider {
    func placeholder(in context: Context) -> HouseGlanceEntry {
        HouseGlanceEntry(date: .now, snapshot: HouseGlanceSnapshot(
            occupancyPercent: 84, occupied: 201, staffed: 240,
            pendingPlacements: 6, statusRaw: "warning", updatedAt: .now))
    }

    func getSnapshot(in context: Context, completion: @escaping (HouseGlanceEntry) -> Void) {
        completion(HouseGlanceEntry(date: .now, snapshot: HouseGlanceCache.load()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<HouseGlanceEntry>) -> Void) {
        let entry = HouseGlanceEntry(date: .now, snapshot: HouseGlanceCache.load())
        // The app reloads this timeline on every fresh rollup; the 30-minute horizon
        // only ages the "updated" line when the app hasn't synced.
        completion(Timeline(entries: [entry], policy: .after(.now + 30 * 60)))
    }
}

private struct HouseGlanceView: View {
    let entry: HouseGlanceEntry

    var body: some View {
        if let snapshot = entry.snapshot {
            VStack(alignment: .leading, spacing: 4) {
                Text("HOUSE")
                    .font(.system(size: 10, weight: .semibold))
                    .tracking(0.6)
                    .foregroundStyle(HBActivityPalette.inkMuted)
                Text("\(snapshot.occupancyPercent)%")
                    .font(.system(size: 34, weight: .semibold))
                    .monospacedDigit()
                    .foregroundStyle(HBActivityPalette.ink)
                    .minimumScaleFactor(0.7)
                HStack(spacing: 5) {
                    Circle().fill(statusColor(snapshot.statusRaw)).frame(width: 7, height: 7)
                    Text(statusWord(snapshot.statusRaw))
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(statusColor(snapshot.statusRaw))
                }
                Spacer(minLength: 0)
                Text("\(snapshot.pendingPlacements) placements pending")
                    .font(.system(size: 11))
                    .monospacedDigit()
                    .foregroundStyle(HBActivityPalette.inkMuted)
                    .lineLimit(1)
                Text(snapshot.updatedAt, style: .relative)
                    .font(.system(size: 10))
                    .foregroundStyle(HBActivityPalette.inkMuted.opacity(0.8))
                    .lineLimit(1)
            }
        } else {
            VStack(alignment: .leading, spacing: 6) {
                Text("HOUSE")
                    .font(.system(size: 10, weight: .semibold))
                    .tracking(0.6)
                    .foregroundStyle(HBActivityPalette.inkMuted)
                Text("Open Hummingbird to sync")
                    .font(.system(size: 12))
                    .foregroundStyle(HBActivityPalette.ink)
                Spacer(minLength: 0)
            }
        }
    }

    private func statusColor(_ raw: String) -> Color {
        switch raw {
        case "critical": return HBActivityPalette.critical
        case "warning": return Color(red: 0.898, green: 0.659, blue: 0.294) // #E5A84B
        case "success": return HBActivityPalette.success
        default: return HBActivityPalette.primary
        }
    }

    private func statusWord(_ raw: String) -> String {
        switch raw {
        case "critical": return "At capacity"
        case "warning": return "Tight"
        case "success": return "Stable"
        default: return "Normal"
        }
    }
}
