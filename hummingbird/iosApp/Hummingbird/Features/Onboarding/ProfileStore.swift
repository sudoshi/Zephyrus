import Foundation

/// Persists the worker's confirmed shift profile (role + unit) locally, per user. This is the
/// on-device record of the onboarding confirmation; the authoritative role/assignment comes from
/// Zephyrus and will be reconciled here once the server endpoints land.
@MainActor
final class ProfileStore: ObservableObject {
    @Published private(set) var roleId: String?
    @Published private(set) var unitId: Int?
    @Published private(set) var unitName: String?

    private let defaults = UserDefaults.standard

    func isOnboarded(userId: Int) -> Bool {
        defaults.string(forKey: key("role", userId)) != nil
    }

    func load(userId: Int) {
        roleId = defaults.string(forKey: key("role", userId))
        let u = defaults.integer(forKey: key("unit", userId))
        unitId = (u == 0) ? nil : u
        unitName = defaults.string(forKey: key("unitName", userId))
    }

    func confirm(userId: Int, roleId: String, unitId: Int?, unitName: String?) {
        defaults.set(roleId, forKey: key("role", userId))
        if let unitId { defaults.set(unitId, forKey: key("unit", userId)) } else { defaults.removeObject(forKey: key("unit", userId)) }
        defaults.set(unitName, forKey: key("unitName", userId))
        self.roleId = roleId
        self.unitId = unitId
        self.unitName = unitName
    }

    func reset(userId: Int) {
        defaults.removeObject(forKey: key("role", userId))
        defaults.removeObject(forKey: key("unit", userId))
        defaults.removeObject(forKey: key("unitName", userId))
        roleId = nil
        unitId = nil
        unitName = nil
    }

    var role: Role? { Role.by(id: roleId) }

    private func key(_ k: String, _ userId: Int) -> String { "hb.\(k).\(userId)" }
}
