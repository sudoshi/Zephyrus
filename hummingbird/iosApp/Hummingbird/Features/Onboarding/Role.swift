import Foundation

/// The clinical/operational role a worker confirms at the start of a shift. The role of
/// record is assigned in Zephyrus (server) and surfaced via `/me`; this catalog is the
/// confirmation/selection vocabulary the app understands. When the server role/assignment
/// endpoints land, map server roles → these ids (see `matching(serverRoles:)`).
struct Role: Identifiable, Hashable {
    let id: String
    let title: String
    let subtitle: String
    let symbol: String
    /// Whether this role is unit-bound (picks a floor/unit) vs. house-wide.
    let unitBound: Bool

    static let catalog: [Role] = [
        Role(id: "charge_nurse", title: "Charge Nurse", subtitle: "Run a unit — placements, barriers, staffing", symbol: "person.2.fill", unitBound: true),
        Role(id: "bedside_nurse", title: "Bedside / Duty Nurse", subtitle: "Your patients on your unit", symbol: "cross.case.fill", unitBound: true),
        Role(id: "bed_manager", title: "Bed Manager / Flow", subtitle: "House-wide capacity & placement", symbol: "bed.double.fill", unitBound: false),
        Role(id: "house_supervisor", title: "House Supervisor", subtitle: "House status & escalations", symbol: "building.2.fill", unitBound: false),
        Role(id: "hospitalist", title: "Hospitalist", subtitle: "Your service & discharges", symbol: "stethoscope", unitBound: true),
        Role(id: "intensivist", title: "Intensivist", subtitle: "Critical care units", symbol: "waveform.path.ecg", unitBound: true),
        Role(id: "evs", title: "EVS", subtitle: "Bed turns & cleaning", symbol: "sparkles", unitBound: false),
        Role(id: "transport", title: "Transport", subtitle: "Patient moves & trips", symbol: "figure.walk", unitBound: false),
    ]

    static func by(id: String?) -> Role? {
        guard let id else { return nil }
        return catalog.first { $0.id == id }
    }

    /// Best-effort match of Zephyrus-assigned role names to this catalog (for pre-selection).
    static func matching(serverRoles: [String]) -> Role? {
        let norm = serverRoles.map { $0.lowercased().replacingOccurrences(of: " ", with: "_") }
        return catalog.first { role in
            norm.contains(role.id) || norm.contains { $0.contains(role.id) || role.id.contains($0) }
        }
    }
}
