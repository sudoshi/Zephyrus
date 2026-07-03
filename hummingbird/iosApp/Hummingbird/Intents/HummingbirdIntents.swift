import AppIntents

/// "Open For You" — jump straight to the action queue (Siri, Shortcuts, Action Button).
/// Routes through the same deeplink seam a tapped push uses.
struct OpenForYouIntent: AppIntent {
    static let title: LocalizedStringResource = "Open For You"
    static let description = IntentDescription("Open your Hummingbird action queue.")
    static let openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        PushManager.shared.deeplinkTab = "foryou"
        return .result()
    }
}

/// "House status" — speak/show the last-synced house snapshot without opening the app.
/// Reads the same App-Group cache the glance widget renders; no network, no PHI.
struct HouseStatusIntent: AppIntent {
    static let title: LocalizedStringResource = "House Status"
    static let description = IntentDescription("Occupancy and pending placements from your last sync.")

    func perform() async throws -> some IntentResult & ProvidesDialog {
        guard let s = HouseGlanceCache.load() else {
            return .result(dialog: "No house snapshot yet — open Hummingbird to sync.")
        }
        return .result(dialog: IntentDialog(
            "House is at \(s.occupancyPercent) percent — \(s.occupied) of \(s.staffed) staffed beds occupied, \(s.pendingPlacements) placements pending."))
    }
}

struct HummingbirdShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(intent: OpenForYouIntent(),
                    phrases: ["Open \(.applicationName) For You", "Show my \(.applicationName) queue"],
                    shortTitle: "For You",
                    systemImageName: "tray.full.fill")
        AppShortcut(intent: HouseStatusIntent(),
                    phrases: ["\(.applicationName) house status", "What's the \(.applicationName) house status"],
                    shortTitle: "House status",
                    systemImageName: "building.2.fill")
    }
}
