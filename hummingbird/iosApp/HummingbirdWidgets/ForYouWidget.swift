import SwiftUI
import WidgetKit

/// For You queue count for the lock screen (accessory) and home screen. Counts only —
/// item content never leaves the app. Coral is earned: it appears only when the queue
/// holds critical items.
struct ForYouWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: ForYouGlanceCache.widgetKind, provider: ForYouProvider()) { entry in
            ForYouWidgetView(entry: entry)
                .containerBackground(HBActivityPalette.bg, for: .widget)
        }
        .configurationDisplayName("For You queue")
        .description("How many items are waiting for your action.")
        .supportedFamilies([.accessoryCircular, .accessoryRectangular, .systemSmall])
    }
}

struct ForYouEntry: TimelineEntry {
    let date: Date
    let snapshot: ForYouGlanceSnapshot?
}

struct ForYouProvider: TimelineProvider {
    func placeholder(in context: Context) -> ForYouEntry {
        ForYouEntry(date: .now, snapshot: ForYouGlanceSnapshot(pending: 7, critical: 2, updatedAt: .now))
    }

    func getSnapshot(in context: Context, completion: @escaping (ForYouEntry) -> Void) {
        completion(ForYouEntry(date: .now, snapshot: ForYouGlanceCache.load()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<ForYouEntry>) -> Void) {
        let entry = ForYouEntry(date: .now, snapshot: ForYouGlanceCache.load())
        completion(Timeline(entries: [entry], policy: .after(.now + 30 * 60)))
    }
}

private struct ForYouWidgetView: View {
    @Environment(\.widgetFamily) private var family
    let entry: ForYouEntry

    private var pending: Int { entry.snapshot?.pending ?? 0 }
    private var critical: Int { entry.snapshot?.critical ?? 0 }
    private var tint: Color { critical > 0 ? HBActivityPalette.critical : HBActivityPalette.primary }

    var body: some View {
        switch family {
        case .accessoryCircular:
            // Lock screens tint accessories themselves; shape carries the meaning.
            VStack(spacing: 0) {
                Text("\(pending)")
                    .font(.system(size: 22, weight: .semibold))
                    .monospacedDigit()
                    .minimumScaleFactor(0.6)
                Text("for you")
                    .font(.system(size: 9, weight: .medium))
            }
        case .accessoryRectangular:
            VStack(alignment: .leading, spacing: 1) {
                Text("FOR YOU")
                    .font(.system(size: 10, weight: .semibold))
                    .tracking(0.5)
                Text("\(pending) to action")
                    .font(.system(size: 14, weight: .semibold))
                    .monospacedDigit()
                if critical > 0 {
                    Text("\(critical) critical")
                        .font(.system(size: 11, weight: .medium))
                        .monospacedDigit()
                }
            }
        default:
            VStack(alignment: .leading, spacing: 4) {
                Text("FOR YOU")
                    .font(.system(size: 10, weight: .semibold))
                    .tracking(0.6)
                    .foregroundStyle(HBActivityPalette.inkMuted)
                Text("\(pending)")
                    .font(.system(size: 34, weight: .semibold))
                    .monospacedDigit()
                    .foregroundStyle(HBActivityPalette.ink)
                Text("to action")
                    .font(.system(size: 11))
                    .foregroundStyle(HBActivityPalette.inkMuted)
                Spacer(minLength: 0)
                if critical > 0 {
                    HStack(spacing: 5) {
                        Circle().fill(tint).frame(width: 7, height: 7)
                        Text("\(critical) critical")
                            .font(.system(size: 11, weight: .semibold))
                            .monospacedDigit()
                            .foregroundStyle(tint)
                    }
                } else {
                    Text("No critical items")
                        .font(.system(size: 11))
                        .foregroundStyle(HBActivityPalette.inkMuted)
                }
                if let updated = entry.snapshot?.updatedAt {
                    Text(updated, style: .relative)
                        .font(.system(size: 10))
                        .foregroundStyle(HBActivityPalette.inkMuted.opacity(0.8))
                        .lineLimit(1)
                }
            }
        }
    }
}
