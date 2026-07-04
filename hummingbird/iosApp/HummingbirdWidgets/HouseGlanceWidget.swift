import SwiftUI
import WidgetKit

/// Home-screen glance: occupancy, net bed need, For You count, and the "next 4h" ghost count
/// — all from the last in-app sync (App-Group cache; the widget has no network or token and
/// never shows PHI). Deliberately calm: numbers and one status word, coral reserved for a real
/// at-capacity breach. Tapping opens the app.
struct HouseGlanceWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: HouseGlanceCache.widgetKind, provider: HouseGlanceProvider()) { entry in
            HouseGlanceView(entry: entry)
                .containerBackground(HBActivityPalette.bg, for: .widget)
        }
        .configurationDisplayName("House at a glance")
        .description("Occupancy, bed need, and what's coming in the next 4 hours.")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}

struct HouseGlanceEntry: TimelineEntry {
    let date: Date
    let snapshot: HouseGlanceSnapshot?
    let forYou: ForYouGlanceSnapshot?
}

struct HouseGlanceProvider: TimelineProvider {
    func placeholder(in context: Context) -> HouseGlanceEntry {
        HouseGlanceEntry(date: .now, snapshot: HouseGlanceSnapshot(
            occupancyPercent: 84, occupied: 201, staffed: 240,
            pendingPlacements: 6, statusRaw: "warning", updatedAt: .now,
            netBedNeed: 4, next4hGhostCount: 7),
            forYou: ForYouGlanceSnapshot(pending: 5, critical: 1, updatedAt: .now))
    }

    func getSnapshot(in context: Context, completion: @escaping (HouseGlanceEntry) -> Void) {
        completion(HouseGlanceEntry(date: .now, snapshot: HouseGlanceCache.load(), forYou: ForYouGlanceCache.load()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<HouseGlanceEntry>) -> Void) {
        let entry = HouseGlanceEntry(date: .now, snapshot: HouseGlanceCache.load(), forYou: ForYouGlanceCache.load())
        // The app reloads this timeline on every fresh rollup / flow-window load; the 30-minute
        // horizon only ages the "updated" line when the app hasn't synced.
        completion(Timeline(entries: [entry], policy: .after(.now + 30 * 60)))
    }
}

private struct HouseGlanceView: View {
    @Environment(\.widgetFamily) private var family
    let entry: HouseGlanceEntry

    var body: some View {
        if let snapshot = entry.snapshot {
            if family == .systemMedium {
                mediumBody(snapshot)
            } else {
                smallBody(snapshot)
            }
        } else {
            emptyBody
        }
    }

    // MARK: Small

    private func smallBody(_ s: HouseGlanceSnapshot) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            header
            Text("\(s.occupancyPercent)%")
                .font(.system(size: 32, weight: .semibold))
                .monospacedDigit()
                .foregroundStyle(HBActivityPalette.ink)
                .minimumScaleFactor(0.7)
            statusRow(s)
            Spacer(minLength: 0)
            comingLine(s)
            Text(s.updatedAt, style: .relative)
                .font(.system(size: 10))
                .foregroundStyle(HBActivityPalette.inkMuted.opacity(0.8))
                .lineLimit(1)
        }
    }

    /// The "what's coming / what's needed" line — degrades gracefully when a writer hasn't run
    /// yet (net bed need from RTDC, next-4h from the flow window, For You from its queue).
    private func comingLine(_ s: HouseGlanceSnapshot) -> some View {
        var parts: [String] = []
        if let need = s.netBedNeed { parts.append("net \(signed(need)) beds") }
        else { parts.append("\(s.pendingPlacements) pending") }
        if let ghosts = s.next4hGhostCount { parts.append("\(ghosts) in 4h") }
        if let pending = entry.forYou?.pending { parts.append("\(pending) for you") }
        return Text(parts.joined(separator: " · "))
            .font(.system(size: 11))
            .monospacedDigit()
            .foregroundStyle(HBActivityPalette.inkMuted)
            .lineLimit(2)
    }

    // MARK: Medium

    private func mediumBody(_ s: HouseGlanceSnapshot) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .firstTextBaseline) {
                header
                Spacer()
                statusRow(s)
            }
            HStack(alignment: .top, spacing: 12) {
                statTile("\(s.occupancyPercent)%", "Occupancy")
                statTile(s.netBedNeed.map { signed($0) } ?? "\(s.pendingPlacements)",
                         s.netBedNeed != nil ? "Net bed need" : "Pending")
                statTile(entry.forYou.map { "\($0.pending)" } ?? "—", "For you")
                statTile(s.next4hGhostCount.map { "\($0)" } ?? "—", "Next 4h")
            }
            Spacer(minLength: 0)
            Text(s.updatedAt, style: .relative)
                .font(.system(size: 10))
                .foregroundStyle(HBActivityPalette.inkMuted.opacity(0.8))
                .lineLimit(1)
        }
    }

    private func statTile(_ value: String, _ label: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(value)
                .font(.system(size: 22, weight: .semibold))
                .monospacedDigit()
                .foregroundStyle(HBActivityPalette.ink)
                .minimumScaleFactor(0.6)
                .lineLimit(1)
            Text(label)
                .font(.system(size: 10, weight: .medium))
                .foregroundStyle(HBActivityPalette.inkMuted)
                .lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    // MARK: Shared

    private var header: some View {
        Text("HOUSE")
            .font(.system(size: 10, weight: .semibold))
            .tracking(0.6)
            .foregroundStyle(HBActivityPalette.inkMuted)
    }

    private func statusRow(_ s: HouseGlanceSnapshot) -> some View {
        HStack(spacing: 5) {
            Circle().fill(statusColor(s.statusRaw)).frame(width: 7, height: 7)
            Text(statusWord(s.statusRaw))
                .font(.system(size: 11, weight: .semibold))
                .foregroundStyle(statusColor(s.statusRaw))
        }
    }

    private var emptyBody: some View {
        VStack(alignment: .leading, spacing: 6) {
            header
            Text("Open Hummingbird to sync")
                .font(.system(size: 12))
                .foregroundStyle(HBActivityPalette.ink)
            Spacer(minLength: 0)
        }
    }

    private func signed(_ n: Int) -> String { n > 0 ? "+\(n)" : "\(n)" }

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
