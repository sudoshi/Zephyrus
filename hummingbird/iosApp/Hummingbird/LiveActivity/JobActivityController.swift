import ActivityKit
import Foundation

/// Starts, updates, and ends the Live Activity for a claim-and-run job. Locally driven:
/// the worker's own lifecycle taps are the source of truth, so the island updates the
/// instant they act — no push round-trip, no APNs dependency. One activity per job;
/// terminal statuses end it (lingering briefly so "complete" is visible on the island).
@MainActor
enum JobActivityController {
    private static let terminal: Set<String> = ["completed", "cancelled", "unable_to_complete"]

    static func sync(kind: String, id: Int, title: String, detail: String,
                     isStat: Bool, statusRaw: String, statusLabel: String) {
        guard ActivityAuthorizationInfo().areActivitiesEnabled else { return }

        let state = HBJobActivityAttributes.ContentState(
            statusRaw: statusRaw, statusLabel: statusLabel, updatedAt: Date())
        let content = ActivityContent(state: state, staleDate: nil)
        let existing = Activity<HBJobActivityAttributes>.activities
            .first { $0.attributes.kind == kind && $0.attributes.jobId == id }

        if terminal.contains(statusRaw) {
            guard let existing else { return }
            Task { await existing.end(content, dismissalPolicy: .after(.now + 120)) }
            return
        }

        if let existing {
            Task { await existing.update(content) }
        } else {
            let attributes = HBJobActivityAttributes(
                kind: kind, jobId: id, title: title, detail: detail, isStat: isStat)
            _ = try? Activity.request(attributes: attributes, content: content)
        }
    }

    /// Ends every job activity (sign-out — a signed-out device must not keep showing
    /// operational state on the lock screen).
    static func endAll() {
        for activity in Activity<HBJobActivityAttributes>.activities {
            Task { await activity.end(activity.content, dismissalPolicy: .immediate) }
        }
    }

    #if DEBUG
    /// Test hook (matches HB_AUTOLOGIN/HB_FOCUS): HB_DEMO_LIVE_ACTIVITY=1 starts a demo
    /// trip activity on launch so the island/lock-screen UI can be screenshot-verified.
    static func startDemoIfRequested() {
        guard ProcessInfo.processInfo.environment["HB_DEMO_LIVE_ACTIVITY"] == "1" else { return }
        sync(kind: "transport", id: 999_999, title: "STAT transport",
             detail: "ED Room 4 → CT Imaging", isStat: true,
             statusRaw: "en_route", statusLabel: "En route")
    }
    #endif
}
