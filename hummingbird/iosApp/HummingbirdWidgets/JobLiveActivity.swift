import ActivityKit
import SwiftUI
import WidgetKit

/// Lock-screen banner + Dynamic Island for an in-flight trip or bed turn. The content
/// mirrors the in-app detail: worker-language title, route/location, current lifecycle
/// step. STAT earns coral; everything else stays interaction-blue (earned urgency).
struct JobLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: HBJobActivityAttributes.self) { context in
            LockScreenJobView(context: context)
                .activityBackgroundTint(HBActivityPalette.bg.opacity(0.92))
                .activitySystemActionForegroundColor(HBActivityPalette.ink)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    Image(systemName: HBActivityPalette.kindIcon(context.attributes.kind))
                        .font(.system(size: 22, weight: .semibold))
                        .foregroundStyle(tint(context))
                        .padding(.leading, 4)
                }
                DynamicIslandExpandedRegion(.center) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(context.attributes.title)
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(HBActivityPalette.ink)
                            .lineLimit(1)
                        Text(context.attributes.detail)
                            .font(.system(size: 12))
                            .foregroundStyle(HBActivityPalette.inkMuted)
                            .lineLimit(1)
                    }
                }
                DynamicIslandExpandedRegion(.trailing) {
                    Text(context.state.statusLabel)
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundStyle(tint(context))
                        .lineLimit(1)
                        .padding(.trailing, 4)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    JobProgressTrack(context: context)
                        .padding(.horizontal, 4)
                }
            } compactLeading: {
                Image(systemName: HBActivityPalette.kindIcon(context.attributes.kind))
                    .foregroundStyle(tint(context))
            } compactTrailing: {
                Text(context.state.statusLabel)
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(tint(context))
                    .lineLimit(1)
                    .frame(maxWidth: 72)
            } minimal: {
                Image(systemName: HBActivityPalette.kindIcon(context.attributes.kind))
                    .foregroundStyle(tint(context))
            }
            .keylineTint(tint(context))
        }
    }

    private func tint(_ context: ActivityViewContext<HBJobActivityAttributes>) -> Color {
        context.attributes.isStat ? HBActivityPalette.critical : HBActivityPalette.primary
    }
}

private struct LockScreenJobView: View {
    let context: ActivityViewContext<HBJobActivityAttributes>

    private var tint: Color {
        context.attributes.isStat ? HBActivityPalette.critical : HBActivityPalette.primary
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top, spacing: 10) {
                Image(systemName: HBActivityPalette.kindIcon(context.attributes.kind))
                    .font(.system(size: 20, weight: .semibold))
                    .foregroundStyle(tint)
                VStack(alignment: .leading, spacing: 2) {
                    Text(context.attributes.title)
                        .font(.system(size: 15, weight: .semibold))
                        .foregroundStyle(HBActivityPalette.ink)
                        .lineLimit(1)
                    Text(context.attributes.detail)
                        .font(.system(size: 13))
                        .foregroundStyle(HBActivityPalette.inkMuted)
                        .lineLimit(1)
                }
                Spacer(minLength: 8)
                Text(context.state.statusLabel)
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(tint)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 5)
                    .background(Capsule().fill(tint.opacity(0.16)))
                    .lineLimit(1)
            }
            JobProgressTrack(context: context)
        }
        .padding(14)
    }
}

/// Thin determinate track through the lifecycle; falls back to a static bar when the
/// status is unknown (never an empty or spinning state on the lock screen).
private struct JobProgressTrack: View {
    let context: ActivityViewContext<HBJobActivityAttributes>

    var body: some View {
        let tint = context.attributes.isStat ? HBActivityPalette.critical : HBActivityPalette.primary
        let done = context.state.statusRaw == "completed"
        ProgressView(value: HBJobSteps.progress(kind: context.attributes.kind,
                                                statusRaw: context.state.statusRaw) ?? 0.1)
            .progressViewStyle(.linear)
            .tint(done ? HBActivityPalette.success : tint)
            .scaleEffect(x: 1, y: 0.8, anchor: .center)
    }
}
