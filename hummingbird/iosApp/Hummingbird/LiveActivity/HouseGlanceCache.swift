import Foundation

/// Last-known house snapshot, cached to the App Group so the glance widget renders
/// without network or auth (widgets get no bearer token; the app is the only writer).
struct HouseGlanceSnapshot: Codable {
    var occupancyPercent: Int
    var occupied: Int
    var staffed: Int
    var pendingPlacements: Int
    /// success | warning | critical | info — the widget pairs it with a label,
    /// never color alone.
    var statusRaw: String
    var updatedAt: Date
}

enum HouseGlanceCache {
    static let appGroupId = "group.net.acumenus.hummingbird"
    static let widgetKind = "HouseGlanceWidget"
    private static let key = "hb.house.glance.v1"

    static func save(_ snapshot: HouseGlanceSnapshot) {
        guard let defaults = UserDefaults(suiteName: appGroupId),
              let data = try? JSONEncoder().encode(snapshot) else { return }
        defaults.set(data, forKey: key)
    }

    static func load() -> HouseGlanceSnapshot? {
        guard let defaults = UserDefaults(suiteName: appGroupId),
              let data = defaults.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(HouseGlanceSnapshot.self, from: data)
    }

    static func clear() {
        UserDefaults(suiteName: appGroupId)?.removeObject(forKey: key)
    }
}

/// Last-known For You queue shape (counts only — the widget never shows item content,
/// so nothing PHI-adjacent leaves the app).
struct ForYouGlanceSnapshot: Codable {
    var pending: Int
    var critical: Int
    var updatedAt: Date
}

enum ForYouGlanceCache {
    static let widgetKind = "ForYouWidget"
    private static let key = "hb.foryou.glance.v1"

    static func save(_ snapshot: ForYouGlanceSnapshot) {
        guard let defaults = UserDefaults(suiteName: HouseGlanceCache.appGroupId),
              let data = try? JSONEncoder().encode(snapshot) else { return }
        defaults.set(data, forKey: key)
    }

    static func load() -> ForYouGlanceSnapshot? {
        guard let defaults = UserDefaults(suiteName: HouseGlanceCache.appGroupId),
              let data = defaults.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(ForYouGlanceSnapshot.self, from: data)
    }
}
