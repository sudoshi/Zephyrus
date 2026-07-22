import Foundation

/// Last-known house snapshot, cached to the App Group so the glance widget renders
/// without network or auth (widgets get no bearer token; the app is the only writer).
/// New fields are optional so a blob written by an older build still decodes.
struct HouseGlanceSnapshot: Codable {
    var occupancyPercent: Int
    var occupied: Int
    var staffed: Int
    var pendingPlacements: Int
    /// success | warning | critical | info — the widget pairs it with a label,
    /// never color alone.
    var statusRaw: String
    var updatedAt: Date
    /// Net bed need (by-2pm sum) from the RTDC house rollup.
    var netBedNeed: Int? = nil
    /// Count of projected "ghost" items landing in the next 4h — written from the Flow
    /// Window load so the glance shows what is coming, not just what is.
    var next4hGhostCount: Int? = nil
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

    /// Update only the fields a given writer owns (RTDC owns occupancy/net-bed-need; the Flow
    /// Window owns next4hGhostCount) without clobbering the others. Seeds a calm zero snapshot
    /// when nothing is cached yet.
    static func merge(_ mutate: (inout HouseGlanceSnapshot) -> Void) {
        var snapshot = load() ?? HouseGlanceSnapshot(
            occupancyPercent: 0, occupied: 0, staffed: 0, pendingPlacements: 0,
            statusRaw: "info", updatedAt: Date())
        mutate(&snapshot)
        snapshot.updatedAt = Date()
        save(snapshot)
    }

    static func clear() {
        UserDefaults(suiteName: appGroupId)?.removeObject(forKey: key)
    }
}

/// Last-known operational For You queue shape. Restricted patient-communication
/// items are excluded even from these counts before this snapshot is created.
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

    static func clear() {
        UserDefaults(suiteName: HouseGlanceCache.appGroupId)?.removeObject(forKey: key)
    }
}
