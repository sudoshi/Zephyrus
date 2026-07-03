import ActivityKit
import SwiftUI

/// Live Activity contract for a claim-and-run job (transport trip or EVS bed turn).
/// Shared between the app target (which starts/updates activities locally — no APNs
/// dependency; the worker's own lifecycle taps drive the island) and the
/// HummingbirdWidgets extension (which renders them).
struct HBJobActivityAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable {
        /// BFF lifecycle status key, e.g. "en_route" — drives step progress.
        var statusRaw: String
        /// Worker-language label, e.g. "En route".
        var statusLabel: String
        var updatedAt: Date
    }

    /// "transport" | "evs"
    var kind: String
    var jobId: Int
    /// e.g. "STAT transport" / "Isolation bed-turn"
    var title: String
    /// Route or location, e.g. "ED Room 4 → CT" / "5W-22"
    var detail: String
    var isStat: Bool
}

/// Ordered lifecycle steps per kind, for the progress track. Kept in worker order;
/// unknown statuses render as indeterminate (no crash, no empty bar).
enum HBJobSteps {
    static let transport = ["assigned", "dispatched", "arrived_pickup", "picked_up",
                            "en_route", "arrived_destination", "handoff_started",
                            "handoff_complete", "completed"]
    static let evs = ["assigned", "in_progress", "completed"]

    static func progress(kind: String, statusRaw: String) -> Double? {
        let steps = kind == "evs" ? evs : transport
        guard let index = steps.firstIndex(of: statusRaw) else { return nil }
        return Double(index + 1) / Double(steps.count)
    }
}

/// Z dark tokens, self-contained for the widget extension (the app's DesignSystem drags
/// model dependencies; keep these in sync with ZephyrusColors).
enum HBActivityPalette {
    static let bg = Color(red: 0.059, green: 0.090, blue: 0.165)        // #0F172A
    static let surface = Color(red: 0.118, green: 0.161, blue: 0.231)   // #1E293B
    static let ink = Color(red: 0.973, green: 0.980, blue: 0.988)       // #F8FAFC
    static let inkMuted = Color(red: 0.580, green: 0.639, blue: 0.722)  // #94A3B8
    static let primary = Color(red: 0.231, green: 0.510, blue: 0.965)   // #3B82F6
    static let critical = Color(red: 0.910, green: 0.353, blue: 0.420)  // #E85A6B
    static let success = Color(red: 0.176, green: 0.831, blue: 0.749)   // #2DD4BF

    static func kindIcon(_ kind: String) -> String {
        kind == "evs" ? "sparkles" : "figure.walk"
    }
}
